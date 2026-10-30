<?php
session_start();

// Trava de segurança: Apenas Atendentes ou Masters
if (!isset($_SESSION['id_usuario']) || (!($_SESSION['atendente'] ?? false) && !($_SESSION['master'] ?? false))) {
    header("Location: index.php?error=" . urlencode("Acesso negado."));
    exit;
}

require_once __DIR__ . '/php/database.php';
require_once __DIR__ . '/php/Sidebar.php';

$isMaster = $_SESSION['master'] ?? false;
$idUsuarioLogado = $_SESSION['id_usuario'];
$nomeUsuario = $_SESSION['nome'] ?? 'Atendente';
$db = new database();

$msgErro = '';
$msgSucesso = '';

// 1. Nome da Empresa Sistema
$resEmpresa = $db->Query("SELECT RAZAO_SOCIAL FROM DADOS_EMPRESA LIMIT 1");
$nomeEmpresaSistema = (!empty($resEmpresa) && !empty($resEmpresa[0]['RAZAO_SOCIAL'])) ? $resEmpresa[0]['RAZAO_SOCIAL'] : 'PrimeSys';

// 2. Filtros de Pesquisa
$filtroParceiro = (int)($_GET['parceiro'] ?? 0);
$filtroDataIni = $_GET['data_ini'] ?? date('Y-m-01');
$filtroDataFim = $_GET['data_fim'] ?? date('Y-m-t');

$queryStringFiltros = http_build_query([
    'parceiro' => $filtroParceiro,
    'data_ini' => $filtroDataIni,
    'data_fim' => $filtroDataFim
]);

// Helper para converter HH:MM:SS em segundos
function horasParaSegundos($strHora) {
    if (empty($strHora)) return 0;
    list($h, $m, $s) = array_pad(explode(':', $strHora), 3, 0);
    return ((int)$h * 3600) + ((int)$m * 60) + (int)$s;
}

// Helper para converter segundos em HH:MM:SS
function segundosParaHoras($segundos) {
    $h = floor($segundos / 3600);
    $m = floor(($segundos / 60) % 60);
    $s = $segundos % 60;
    return sprintf('%02d:%02d:%02d', $h, $m, $s);
}

// 3. Processamento de Ações (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';

    // --- AÇÃO: GERAR FECHAMENTO ---
    if ($acao === 'gerar_fechamento') {
        $idParceiro = (int)($_POST['id_parceiro'] ?? 0);
        $dataInicio = $_POST['data_inicio'] ?? '';
        $dataFim = $_POST['data_fim'] ?? '';

        if ($idParceiro <= 0 || empty($dataInicio) || empty($dataFim)) {
            $msgErro = "Selecione o parceiro e informe o período do fechamento.";
        } else {
            // Verifica clientes do parceiro
            $clientesParceiro = $db->Query("SELECT ID_CLIENTE, RAZAO_SOCIAL, VALOR_HORA FROM CLIENTES WHERE ID_PARCEIRO = $idParceiro") ?: [];
            if (empty($clientesParceiro)) {
                $msgErro = "O parceiro selecionado não possui nenhum cliente vinculado.";
            } else {
                $idsClientesArr = array_column($clientesParceiro, 'ID_CLIENTE');
                $idsClientesStr = implode(',', $idsClientesArr);

                // Busca todos os apontamentos do período vinculados aos clientes do parceiro
                $sqlAps = "SELECT AP.*, A.ID_CLIENTE, S.DESCRICAO AS DESCRICAO_SERVICO, S.FORMA_COBRANCA, S.VALOR AS VALOR_SERVICO, P.ID_PROJETO, P.NOME_PROJETO, C.VALOR_HORA AS CLIENTE_VALOR_HORA
                           FROM APONTAMENTO_AGENDAMENTOS AP
                           INNER JOIN AGENDAMENTOS A ON AP.ID_AGENDAMENTO = A.ID_AGENDAMENTO
                           INNER JOIN CLIENTES C ON A.ID_CLIENTE = C.ID_CLIENTE
                           LEFT JOIN SERVICOS S ON AP.ID_SERVICO = S.ID_SERVICO
                           LEFT JOIN PROJETOS P ON S.ID_PROJETO = P.ID_PROJETO
                           WHERE A.ID_CLIENTE IN ($idsClientesStr)
                             AND AP.DATA_APONTAMENTO BETWEEN '$dataInicio' AND '$dataFim'
                           ORDER BY A.ID_CLIENTE ASC, AP.DATA_APONTAMENTO ASC, AP.HORA_INICIAL ASC";
                $apontamentos = $db->Query($sqlAps) ?: [];

                // Serviços com cobrança mensal (Forma 2) precisam ser apurados mesmo que não haja apontamento
                $sqlMensais = "SELECT S.*, P.ID_CLIENTE, P.NOME_PROJETO 
                               FROM SERVICOS S
                               INNER JOIN PROJETOS P ON S.ID_PROJETO = P.ID_PROJETO
                               WHERE P.ID_CLIENTE IN ($idsClientesStr) AND S.FORMA_COBRANCA = 2";
                $servicosMensais = $db->Query($sqlMensais) ?: [];

                // Monta a estrutura do fechamento
                $itensParaInserir = [];
                $valorTotalFechamento = 0;
                $servicosMensaisProcessados = [];

                foreach ($apontamentos as $ap) {
                    $formaCobranca = (int)($ap['FORMA_COBRANCA'] ?? 1);
                    $valorServico = (float)($ap['VALOR_SERVICO'] ?? 0);
                    $valorHoraCliente = (float)($ap['CLIENTE_VALOR_HORA'] ?? 0);
                    $valUnit = 0;
                    $qtdServico = 1;
                    $valTotalItem = 0;

                    if ($formaCobranca == 1) { // Por Hora
                        $segundos = horasParaSegundos($ap['TOTAL_HORAS_APONTAMENTO']);
                        $qtdServico = $segundos / 3600; // Quantidade de horas decimal
                        $valUnit = ($valorServico > 0) ? $valorServico : $valorHoraCliente;
                        $valTotalItem = round($qtdServico * $valUnit, 2);
                    } elseif ($formaCobranca == 2) { // Mensal
                        $idServico = (int)$ap['ID_SERVICO'];
                        if (!in_array($idServico, $servicosMensaisProcessados)) {
                            $valUnit = $valorServico;
                            $qtdServico = 1;
                            $valTotalItem = $valorServico;
                            $servicosMensaisProcessados[] = $idServico;
                        } else {
                            $valUnit = 0;
                            $qtdServico = 0;
                            $valTotalItem = 0;
                        }
                    } elseif ($formaCobranca == 3) { // Por Execução
                        $valUnit = $valorServico;
                        $qtdServico = 1;
                        $valTotalItem = $valorServico;
                    }

                    $valorTotalFechamento += $valTotalItem;
                    $itensParaInserir[] = [
                        'id_servico' => (int)($ap['ID_SERVICO'] ?? 0),
                        'desc_servico' => addslashes($ap['DESCRICAO_SERVICO'] ?? 'Serviço Geral'),
                        'id_projeto' => (int)($ap['ID_PROJETO'] ?? 0),
                        'desc_projeto' => addslashes($ap['NOME_PROJETO'] ?? 'Projeto Geral'),
                        'valor_unit' => $valUnit,
                        'qtd_servico' => $qtdServico,
                        'valor_total' => $valTotalItem
                    ];
                }

                // Adiciona os serviços mensais sem nenhum apontamento registrado no período
                foreach ($servicosMensais as $sm) {
                    $idServicoM = (int)$sm['ID_SERVICO'];
                    if (!in_array($idServicoM, $servicosMensaisProcessados)) {
                        $valTotalM = (float)$sm['VALOR'];
                        $valorTotalFechamento += $valTotalM;
                        $itensParaInserir[] = [
                            'id_servico' => $idServicoM,
                            'desc_servico' => addslashes($sm['DESCRICAO']),
                            'id_projeto' => (int)$sm['ID_PROJETO'],
                            'desc_projeto' => addslashes($sm['NOME_PROJETO']),
                            'valor_unit' => $valTotalM,
                            'qtd_servico' => 1,
                            'valor_total' => $valTotalM
                        ];
                        $servicosMensaisProcessados[] = $idServicoM;
                    }
                }

                if (empty($itensParaInserir)) {
                    $msgErro = "Nenhum apontamento ou serviço cobrável encontrado para os clientes do parceiro no período informado.";
                } else {
                    // Insere Cabeçalho do Fechamento (salva o ID do Parceiro em ID_CLIENTE)
                    $db->Query("INSERT INTO FECHAMENTO (ID_CLIENTE, DATA_INICIO, DATA_FIM, VALOR_TOTAL, FATURADO) 
                                VALUES ($idParceiro, '$dataInicio', '$dataFim', $valorTotalFechamento, 0)");

                    $resId = $db->Query("SELECT ID_FECHAMENTO FROM FECHAMENTO ORDER BY ID_FECHAMENTO DESC LIMIT 1");
                    $idFechamento = $resId[0]['ID_FECHAMENTO'];

                    // Insere os Itens do Fechamento
                    foreach ($itensParaInserir as $item) {
                        $db->Query("INSERT INTO FECHAMENTO_ITENS (ID_FECHAMENTO, ID_SERVICO, DESCRICAO_SERVICO, ID_PROJETO, DESCRICAO_PROJETO, VALOR_UNIT, QTD_SERVICO, VALOR_TOTAL) 
                                    VALUES ($idFechamento, {$item['id_servico']}, '{$item['desc_servico']}', {$item['id_projeto']}, '{$item['desc_projeto']}', {$item['valor_unit']}, {$item['qtd_servico']}, {$item['valor_total']})");
                    }

                    $msgSucesso = "Fechamento #$idFechamento gerado com sucesso no valor total de R$ " . number_format($valorTotalFechamento, 2, ',', '.');
                }
            }
        }
    }

    // --- AÇÃO: EXCLUIR FECHAMENTO ---
    if ($acao === 'excluir_fechamento') {
        $idFechamento = (int)$_POST['id_fechamento'];
        $chk = $db->Query("SELECT FATURADO FROM FECHAMENTO WHERE ID_FECHAMENTO = $idFechamento");

        if (!empty($chk) && (int)$chk[0]['FATURADO'] === 1) {
            $msgErro = "Não é possível excluir um fechamento faturado. Desfature-o primeiro!";
        } else {
            $db->Query("DELETE FROM FECHAMENTO_ITENS WHERE ID_FECHAMENTO = $idFechamento");
            $db->Query("DELETE FROM FECHAMENTO WHERE ID_FECHAMENTO = $idFechamento");
            $msgSucesso = "Fechamento #$idFechamento excluído com sucesso!";
        }
    }

    // --- AÇÃO: FATURAR FECHAMENTO ---
    if ($acao === 'faturar_fechamento') {
        $idFechamento = (int)$_POST['id_fechamento'];
        $dataVencimento = $_POST['data_vencimento'] ?? date('Y-m-d', strtotime('+30 days'));
        $idCategoria = (int)($_POST['id_categoria'] ?? 0);

        if ($idCategoria <= 0) {
            $msgErro = "Selecione uma Categoria de Receitas para faturar.";
        } else {
            $resFec = $db->Query("SELECT F.*, P.RAZAO_SOCIAL AS PARCEIRO_NOME 
                                  FROM FECHAMENTO F 
                                  INNER JOIN PARCEIROS P ON F.ID_CLIENTE = P.ID_PARCEIRO 
                                  WHERE F.ID_FECHAMENTO = $idFechamento");
            if (!empty($resFec)) {
                $f = $resFec[0];
                $valTotal = (float)$f['VALOR_TOTAL'];
                $parceiroNome = addslashes($f['PARCEIRO_NOME']);
                $dataHoje = date('Y-m-d');
                $descFin = addslashes("Faturamento Fechamento #$idFechamento - Parceiro: $parceiroNome");
                $obsFin = addslashes("Ref. período " . date('d/m/Y', strtotime($f['DATA_INICIO'])) . " a " . date('d/m/Y', strtotime($f['DATA_FIM'])));

                // Insere no financeiro
                $db->Query("INSERT INTO MOVIMENTO_FINANCEIRO (DESCRICAO, ID_CLIENTE, PAGREC, DATA_EMISSAO, VALOR_DOCUMENTO, ID_CATEGORIA, OBSERVACAO) 
                            VALUES ('$descFin', {$f['ID_CLIENTE']}, 'R', '$dataHoje', $valTotal, $idCategoria, '$obsFin')");

                $resMov = $db->Query("SELECT ID_FIN_MOV FROM MOVIMENTO_FINANCEIRO ORDER BY ID_FIN_MOV DESC LIMIT 1");
                $idFinMov = $resMov[0]['ID_FIN_MOV'];

                // Insere Parcela
                $db->Query("INSERT INTO MOVIMENTO_FINANCEIRO_PARCELAS (ID_FIN_MOV, PARCELA, DATA_VENCIMENTO, VALOR_PARCELA) 
                            VALUES ($idFinMov, 1, '$dataVencimento', $valTotal)");

                // Marca Fechamento como Faturado
                $db->Query("UPDATE FECHAMENTO SET FATURADO = 1 WHERE ID_FECHAMENTO = $idFechamento");

                $msgSucesso = "Fechamento #$idFechamento faturado com sucesso! Título Financeiro #$idFinMov gerado.";
            }
        }
    }

    // --- AÇÃO: DESFATURAR FECHAMENTO ---
    if ($acao === 'desfaturar_fechamento') {
        $idFechamento = (int)$_POST['id_fechamento'];
        $resFec = $db->Query("SELECT ID_CLIENTE FROM FECHAMENTO WHERE ID_FECHAMENTO = $idFechamento");

        if (!empty($resFec)) {
            $idParceiro = $resFec[0]['ID_CLIENTE'];
            $buscaMov = $db->Query("SELECT ID_FIN_MOV FROM MOVIMENTO_FINANCEIRO 
                                    WHERE DESCRICAO LIKE 'Faturamento Fechamento #$idFechamento - %' 
                                      AND ID_CLIENTE = $idParceiro LIMIT 1");

            if (!empty($buscaMov)) {
                $idMov = $buscaMov[0]['ID_FIN_MOV'];
                $db->Query("DELETE FROM MOVIMENTO_FINANCEIRO_PARCELAS WHERE ID_FIN_MOV = $idMov");
                $db->Query("DELETE FROM MOVIMENTO_FINANCEIRO WHERE ID_FIN_MOV = $idMov");
            }

            $db->Query("UPDATE FECHAMENTO SET FATURADO = 0 WHERE ID_FECHAMENTO = $idFechamento");
            $msgSucesso = "Fechamento #$idFechamento desfaturado com sucesso!";
        }
    }
}

// 4. Consulta de Fechamentos para a Lista
$whereFilter = ["F.DATA_INICIO >= '$filtroDataIni'", "F.DATA_FIM <= '$filtroDataFim'"];
if ($filtroParceiro > 0) {
    $whereFilter[] = "F.ID_CLIENTE = $filtroParceiro";
}
$sqlWhereFilter = " WHERE " . implode(" AND ", $whereFilter);

$sqlListaFechamentos = "SELECT F.*, P.RAZAO_SOCIAL AS NOME_PARCEIRO 
                        FROM FECHAMENTO F 
                        INNER JOIN PARCEIROS P ON F.ID_CLIENTE = P.ID_PARCEIRO 
                        $sqlWhereFilter 
                        ORDER BY F.ID_FECHAMENTO DESC";
$listaFechamentos = $db->Query($sqlListaFechamentos) ?: [];

// Listas auxiliares para telas/combos
$parceirosLista = $db->Query("SELECT ID_PARCEIRO, RAZAO_SOCIAL FROM PARCEIROS ORDER BY RAZAO_SOCIAL ASC") ?: [];
$categoriasReceita = $db->Query("SELECT ID_CATEGORIA, DESCRICAO FROM CATEGORIAS WHERE DESP_REC = 'RECEITA' AND (INATIVO IS NULL OR INATIVO = 0) ORDER BY DESCRICAO ASC") ?: [];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fechamentos - <?= htmlspecialchars($nomeEmpresaSistema) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" />
    <link rel="stylesheet" href="css/suporte.css">
    <style>
        .faturado-sim { background: #dcfce7; color: #15803d; font-weight: 700; padding: 3px 8px; border-radius: 12px; font-size: 11px; }
        .faturado-nao { background: #fef3c7; color: #b45309; font-weight: 700; padding: 3px 8px; border-radius: 12px; font-size: 11px; }
        @media print {
            body * { visibility: hidden; }
            #imprimirRelatorioArea, #imprimirRelatorioArea * { visibility: visible; }
            #imprimirRelatorioArea { position: absolute; left: 0; top: 0; width: 100%; background: #fff; padding: 20px; }
            .no-print { display: none !important; }
        }
    </style>
</head>
<body>

    <header class="top-header no-print">
        <div class="brand"><i class="fa-solid fa-headset"></i> <?= htmlspecialchars($nomeEmpresaSistema) ?> Suporte</div>
        <div class="user-info">
            <span><i class="fa-solid fa-user"></i> <strong><?= htmlspecialchars($nomeUsuario) ?></strong></span>
            <a href="logout.php" class="btn-logout"><i class="fa-solid fa-right-from-bracket"></i> Sair</a>
        </div>
    </header>

    <div class="app-container">
        <?php 
            $sidebar = new Sidebar('fechamentos', $isMaster); 
            $sidebar->render(); 
        ?>

        <main class="main-content">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px;" class="no-print">
                <h1 class="page-title" style="margin-bottom: 0;"><i class="fa-solid fa-file-invoice-dollar"></i> Fechamentos de Parceiros</h1>
                <button type="button" class="btn-3d btn-primary-3d" onclick="openModalGerar()">
                    <i class="fa-solid fa-plus"></i> Novo Fechamento
                </button>
            </div>

            <?php if (!empty($msgSucesso)): ?>
                <div class="alert-box alert-success no-print" style="background:#dcfce7; color:#166534; padding:12px; border-radius:8px; margin-bottom:15px; border:1px solid #bbf7d0;">
                    <i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($msgSucesso) ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($msgErro)): ?>
                <div class="alert-box alert-error no-print" style="background:#fee2e2; color:#991b1b; padding:12px; border-radius:8px; margin-bottom:15px; border:1px solid #fecaca;">
                    <i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($msgErro) ?>
                </div>
            <?php endif; ?>

            <!-- FILTROS DE PESQUISA -->
            <div class="filters-card no-print">
                <form method="GET" action="fechamentos.php" class="filters-grid">
                    <div class="form-group">
                        <label>Data Inicial:</label>
                        <input type="date" name="data_ini" value="<?= $filtroDataIni ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Data Final:</label>
                        <input type="date" name="data_fim" value="<?= $filtroDataFim ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Parceiro:</label>
                        <select name="parceiro">
                            <option value="">Todos os Parceiros</option>
                            <?php foreach ($parceirosLista as $p): ?>
                                <option value="<?= $p['ID_PARCEIRO'] ?>" <?= $filtroParceiro == $p['ID_PARCEIRO'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($p['RAZAO_SOCIAL']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filters-actions">
                        <button type="submit" class="btn-3d btn-secondary-3d"><i class="fa-solid fa-magnifying-glass"></i> Filtrar</button>
                    </div>
                </form>
            </div>

            <!-- TABELA DE FECHAMENTOS -->
            <div class="table-responsive no-print" style="background:#fff; border-radius:8px; border:1px solid #e2e8f0; padding:15px;">
                <table class="custom-table" style="width:100%; font-size:13px;">
                    <thead>
                        <tr style="background:#f8fafc; text-align:left;">
                            <th style="padding:10px;">#ID</th>
                            <th style="padding:10px;">Parceiro</th>
                            <th style="padding:10px;">Período</th>
                            <th style="padding:10px; text-align:right;">Valor Total</th>
                            <th style="padding:10px; text-align:center;">Faturado</th>
                            <th style="padding:10px; text-align:center;">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($listaFechamentos)): ?>
                            <tr><td colspan="6" style="text-align:center; padding:20px; color:#64748b;">Nenhum fechamento encontrado no período.</td></tr>
                        <?php else: ?>
                            <?php foreach ($listaFechamentos as $f): 
                                $isFat = ((int)$f['FATURADO'] === 1);
                            ?>
                                <tr style="border-bottom:1px solid #f1f5f9;">
                                    <td style="padding:10px;"><strong>#<?= $f['ID_FECHAMENTO'] ?></strong></td>
                                    <td style="padding:10px; font-weight:600;"><?= htmlspecialchars($f['NOME_PARCEIRO']) ?></td>
                                    <td style="padding:10px;"><?= date('d/m/Y', strtotime($f['DATA_INICIO'])) ?> a <?= date('d/m/Y', strtotime($f['DATA_FIM'])) ?></td>
                                    <td style="padding:10px; text-align:right; font-weight:bold; color:#059669;">R$ <?= number_format($f['VALOR_TOTAL'], 2, ',', '.') ?></td>
                                    <td style="padding:10px; text-align:center;">
                                        <span class="<?= $isFat ? 'faturado-sim' : 'faturado-nao' ?>"><?= $isFat ? 'SIM' : 'NÃO' ?></span>
                                    </td>
                                    <td style="padding:10px; text-align:center;">
                                        <button type="button" class="btn-3d btn-secondary-3d btn-sm-3d" title="Imprimir/Visualizar" onclick="gerarRelatorioFechamento(<?= $f['ID_FECHAMENTO'] ?>)">
                                            <i class="fa-solid fa-print"></i>
                                        </button>

                                        <?php if (!$isFat): ?>
                                            <button type="button" class="btn-3d btn-success-3d btn-sm-3d" title="Faturar (Criar Título Receber)" onclick="openModalFaturar(<?= $f['ID_FECHAMENTO'] ?>, '<?= htmlspecialchars($f['NOME_PARCEIRO']) ?>', <?= $f['VALOR_TOTAL'] ?>)">
                                                <i class="fa-solid fa-sack-dollar"></i>
                                            </button>
                                            <form method="POST" action="fechamentos.php?<?= $queryStringFiltros ?>" style="display:inline;" onsubmit="return confirm('Deseja realmente excluir este fechamento?');">
                                                <input type="hidden" name="acao" value="excluir_fechamento">
                                                <input type="hidden" name="id_fechamento" value="<?= $f['ID_FECHAMENTO'] ?>">
                                                <button type="submit" class="btn-3d btn-danger-3d btn-sm-3d" title="Excluir"><i class="fa-solid fa-trash"></i></button>
                                            </form>
                                        <?php else: ?>
                                            <form method="POST" action="fechamentos.php?<?= $queryStringFiltros ?>" style="display:inline;" onsubmit="return confirm('Deseja realmente desfaturar este fechamento? O título financeiro será removido.');">
                                                <input type="hidden" name="acao" value="desfaturar_fechamento">
                                                <input type="hidden" name="id_fechamento" value="<?= $f['ID_FECHAMENTO'] ?>">
                                                <button type="submit" class="btn-3d btn-secondary-3d btn-sm-3d" style="background:#d97706;" title="Desfaturar"><i class="fa-solid fa-rotate-left"></i> Desfaturar</button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>

    <!-- MODAL: GERAR FECHAMENTO -->
    <div class="modal-overlay no-print" id="modalGerarFechamento">
        <div class="modal-card" style="max-width:550px;">
            <div class="modal-header">
                <h3><i class="fa-solid fa-file-circle-plus"></i> Gerar Novo Fechamento</h3>
                <i class="fa-solid fa-xmark" style="cursor:pointer;" onclick="closeModalGerar()"></i>
            </div>
            <form method="POST" action="fechamentos.php?<?= $queryStringFiltros ?>">
                <input type="hidden" name="acao" value="gerar_fechamento">
                <div class="modal-body">
                    <div class="form-group" style="margin-bottom:15px;">
                        <label>Parceiro <span class="required-asterisk">*</span></label>
                        <select name="id_parceiro" required style="padding:10px; border-radius:6px; border:1.5px solid #cbd5e1; width:100%;">
                            <option value="">-- Selecione o Parceiro --</option>
                            <?php foreach ($parceirosLista as $p): ?>
                                <option value="<?= $p['ID_PARCEIRO'] ?>"><?= htmlspecialchars($p['RAZAO_SOCIAL']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-grid" style="grid-template-columns:1fr 1fr; gap:12px;">
                        <div class="form-group">
                            <label>Data Inicial <span class="required-asterisk">*</span></label>
                            <input type="date" name="data_inicio" value="<?= date('Y-m-01') ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Data Final <span class="required-asterisk">*</span></label>
                            <input type="date" name="data_fim" value="<?= date('Y-m-t') ?>" required>
                        </div>
                    </div>
                    <div class="modal-footer" style="margin-top:20px;">
                        <button type="button" class="btn-3d btn-danger-3d" onclick="closeModalGerar()">Cancelar</button>
                        <button type="submit" class="btn-3d btn-success-3d"><i class="fa-solid fa-gears"></i> Processar Fechamento</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL: FATURAR FECHAMENTO -->
    <div class="modal-overlay no-print" id="modalFaturarFechamento">
        <div class="modal-card" style="max-width:550px;">
            <div class="modal-header">
                <h3><i class="fa-solid fa-sack-dollar"></i> Faturar Fechamento</h3>
                <i class="fa-solid fa-xmark" style="cursor:pointer;" onclick="closeModalFaturar()"></i>
            </div>
            <form method="POST" action="fechamentos.php?<?= $queryStringFiltros ?>">
                <input type="hidden" name="acao" value="faturar_fechamento">
                <input type="hidden" name="id_fechamento" id="fat_id_fechamento">
                <div class="modal-body">
                    <p style="font-size:13px; color:#475569; margin-bottom:15px;">
                        Você está faturando o fechamento do parceiro <strong id="lbl_fat_parceiro"></strong> no valor de <strong id="lbl_fat_valor" style="color:#059669;"></strong>.
                    </p>
                    <div class="form-group" style="margin-bottom:15px;">
                        <label>Data de Vencimento do Título <span class="required-asterisk">*</span></label>
                        <input type="date" name="data_vencimento" value="<?= date('Y-m-d', strtotime('+30 days')) ?>" required>
                    </div>
                    <div class="form-group" style="margin-bottom:15px;">
                        <label>Categoria de Receitas <span class="required-asterisk">*</span></label>
                        <select name="id_categoria" required style="padding:10px; border-radius:6px; border:1.5px solid #cbd5e1; width:100%;">
                            <option value="">-- Selecione a Categoria --</option>
                            <?php foreach ($categoriasReceita as $c): ?>
                                <option value="<?= $c['ID_CATEGORIA'] ?>"><?= htmlspecialchars($c['DESCRICAO']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="modal-footer" style="margin-top:20px;">
                        <button type="button" class="btn-3d btn-danger-3d" onclick="closeModalFaturar()">Cancelar</button>
                        <button type="submit" class="btn-3d btn-success-3d"><i class="fa-solid fa-check"></i> Efetivar Faturamento</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- ÁREA DE IMPRESSÃO / PDF DO RELATÓRIO DO FECHAMENTO -->
    <div id="imprimirRelatorioArea" style="display:none;"></div>

    <script>
        function openModalGerar() { document.getElementById('modalGerarFechamento').classList.add('active'); }
        function closeModalGerar() { document.getElementById('modalGerarFechamento').classList.remove('active'); }

        function openModalFaturar(id, parceiro, valor) {
            document.getElementById('fat_id_fechamento').value = id;
            document.getElementById('lbl_fat_parceiro').innerText = parceiro;
            document.getElementById('lbl_fat_valor').innerText = 'R$ ' + valor.toLocaleString('pt-BR', {minimumFractionDigits:2});
            document.getElementById('modalFaturarFechamento').classList.add('active');
        }
        function closeModalFaturar() { document.getElementById('modalFaturarFechamento').classList.remove('active'); }

        function gerarRelatorioFechamento(idFechamento) {
            fetch(`get_dados_fechamento.php?id_fechamento=${idFechamento}`)
                .then(res => res.json())
                .then(data => {
                    if (data.error) {
                        alert(data.error);
                        return;
                    }

                    const printArea = document.getElementById('imprimirRelatorioArea');
                    const empNome = data.empresa || '<?= htmlspecialchars($nomeEmpresaSistema) ?>';
                    const dtGeracao = new Date().toLocaleDateString('pt-BR') + ' ' + new Date().toLocaleTimeString('pt-BR');

                    let html = `
                        <div style="font-family: 'Inter', Arial, sans-serif; color: #0f172a; padding: 20px;">
                            <div style="display: flex; justify-content: space-between; border-bottom: 2px solid #001517; padding-bottom: 12px; margin-bottom: 15px;">
                                <div>
                                    <h2 style="margin:0; color:#001517; font-size:18px;"><i class="fa-solid fa-headset"></i> ${empNome} - Relatório de Fechamento #${data.fechamento.ID_FECHAMENTO}</h2>
                                    <p style="margin: 4px 0 0 0; font-size: 12px; color: #475569;">Emissão em: ${dtGeracao}</p>
                                </div>
                                <div style="text-align: right;">
                                    <h3 style="margin: 0; color: #2563eb; font-size: 15px;">Parceiro: ${data.parceiro}</h3>
                                    <span style="font-size: 12px; color: #64748b;">Período: <strong>${data.periodo}</strong></span>
                                </div>
                            </div>
                    `;

                    // Itera pelos clientes e seus apontamentos/serviços
                    data.clientes.forEach(cli => {
                        html += `
                            <div style="margin-bottom: 20px; border: 1px solid #cbd5e1; border-radius: 6px; overflow: hidden;">
                                <div style="background: #001517; color: #fff; padding: 6px 10px; font-size: 12px; font-weight: bold;">
                                    Cliente: ${cli.nome_cliente}
                                </div>
                                <table style="width: 100%; border-collapse: collapse; font-size: 11px;">
                                    <thead>
                                        <tr style="background: #f1f5f9; text-align: left;">
                                            <th style="padding: 6px 8px;">Data</th>
                                            <th style="padding: 6px 8px;">Início</th>
                                            <th style="padding: 6px 8px;">Fim</th>
                                            <th style="padding: 6px 8px;">Tempo</th>
                                            <th style="padding: 6px 8px;">Serviço / Projeto</th>
                                            <th style="padding: 6px 8px;">Modalidade</th>
                                            <th style="padding: 6px 8px;">Descrição</th>
                                            <th style="padding: 6px 8px; text-align: right;">Valor</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                        `;

                        cli.itens.forEach(it => {
                            html += `
                                <tr style="border-bottom: 1px solid #e2e8f0;">
                                    <td style="padding: 6px 8px;">${it.data}</td>
                                    <td style="padding: 6px 8px;">${it.hora_ini}</td>
                                    <td style="padding: 6px 8px;">${it.hora_fim}</td>
                                    <td style="padding: 6px 8px;"><strong>${it.tempo}</strong></td>
                                    <td style="padding: 6px 8px;">${it.servico_projeto}</td>
                                    <td style="padding: 6px 8px;">${it.modalidade}</td>
                                    <td style="padding: 6px 8px;">${it.descricao}</td>
                                    <td style="padding: 6px 8px; text-align: right; font-weight: bold;">R$ ${it.valor.toLocaleString('pt-BR', {minimumFractionDigits: 2})}</td>
                                </tr>
                            `;
                        });

                        html += `
                                    </tbody>
                                </table>
                                <div style="background: #f8fafc; padding: 6px 10px; font-size: 11px; text-align: right; border-top: 1px solid #cbd5e1;">
                                    Subtotal Cliente: <strong>${cli.total_apontamentos} apontamento(s)</strong> | Tempo: <strong>${cli.tempo_total}</strong> | Valor Total: <strong style="color:#059669;">R$ ${cli.valor_total.toLocaleString('pt-BR', {minimumFractionDigits: 2})}</strong>
                                </div>
                            </div>
                        `;
                    });

                    // Totalizador Geral do Relatório
                    html += `
                        <div style="background: #f8fafc; border: 1.5px solid #001517; border-radius: 6px; padding: 12px; margin-top: 20px;">
                            <h4 style="margin: 0 0 8px 0; font-size: 13px; color: #001517;"><i class="fa-solid fa-calculator"></i> Totalizador Geral por Cliente</h4>
                            <table style="width: 100%; border-collapse: collapse; font-size: 11px; margin-bottom: 8px;">
                                <thead>
                                    <tr style="background: #e2e8f0; text-align: left;">
                                        <th style="padding: 5px 8px;">Cliente</th>
                                        <th style="padding: 5px 8px; text-align: center;">Qtd Apontamentos</th>
                                        <th style="padding: 5px 8px; text-align: center;">Horas Totais</th>
                                        <th style="padding: 5px 8px; text-align: right;">Valor Cobrado</th>
                                    </tr>
                                </thead>
                                <tbody>
                    `;

                    data.totalizador.clientes.forEach(totCli => {
                        html += `
                            <tr style="border-bottom: 1px solid #e2e8f0;">
                                <td style="padding: 5px 8px;"><strong>${totCli.nome}</strong></td>
                                <td style="padding: 5px 8px; text-align: center;">${totCli.qtd_apontamentos}</td>
                                <td style="padding: 5px 8px; text-align: center;">${totCli.tempo_total}</td>
                                <td style="padding: 5px 8px; text-align: right;">R$ ${totCli.valor_total.toLocaleString('pt-BR', {minimumFractionDigits: 2})}</td>
                            </tr>
                        `;
                    });

                    html += `
                                </tbody>
                            </table>
                            <div style="display: flex; justify-content: space-between; font-size: 13px; font-weight: bold; border-top: 2px solid #001517; padding-top: 6px; margin-top: 4px;">
                                <span>Total de Apontamentos Geral: ${data.totalizador.qtd_geral}</span>
                                <span>Total de Horas Geral: ${data.totalizador.tempo_geral}</span>
                                <span>Valor Geral do Fechamento: <span style="color:#059669; font-size:15px;">R$ ${data.totalizador.valor_geral.toLocaleString('pt-BR', {minimumFractionDigits: 2})}</span></span>
                            </div>
                        </div>
                    </div>`;

                    printArea.innerHTML = html;
                    printArea.style.display = 'block';
                    window.print();
                    setTimeout(() => { printArea.style.display = 'none'; }, 1000);
                });
        }
    </script>
</body>
</html>