<?php
session_start();

// Trava de segurança: Atendentes ou Masters
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
$modalAbertoId = null; 

// --- BUSCA DADOS DA EMPRESA PARA O CABEÇALHO DO RELATÓRIO ---
$resEmpresa = $db->Query("SELECT RAZAO_SOCIAL FROM DADOS_EMPRESA LIMIT 1");
$nomeEmpresaSistema = (!empty($resEmpresa) && !empty($resEmpresa[0]['RAZAO_SOCIAL'])) ? $resEmpresa[0]['RAZAO_SOCIAL'] : 'PrimeSys';

// --- CAPTURA DOS FILTROS DA URL PARA PERSISTÊNCIA ---
$dataFiltro = $_GET['data'] ?? date('Y-m-d');
$filtroCliente = (int)($_GET['cliente'] ?? 0);
$filtroStatus = $_GET['status'] ?? '';
$filtroVisibilidade = $_GET['visibilidade'] ?? 'O'; // 'O' = Apenas Ocupados (padrão), 'L' = Apenas Livres, 'T' = Todos

// Monta string de query string para preservar os filtros nos redirecionamentos
$queryStringFiltros = http_build_query([
    'data' => $dataFiltro,
    'cliente' => $filtroCliente,
    'status' => $filtroStatus,
    'visibilidade' => $filtroVisibilidade
]);

// --- PROCESSAMENTO DE FORMULÁRIOS (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';

    // Recupera filtros enviados via campo oculto do POST para manter a persistência
    $dataFiltro = $_POST['filtro_data'] ?? $dataFiltro;
    $filtroCliente = (int)($_POST['filtro_cliente'] ?? $filtroCliente);
    $filtroStatus = $_POST['filtro_status'] ?? $filtroStatus;
    $filtroVisibilidade = $_POST['filtro_visibilidade'] ?? $filtroVisibilidade;
    
    $queryStringFiltros = http_build_query([
        'data' => $dataFiltro,
        'cliente' => $filtroCliente,
        'status' => $filtroStatus,
        'visibilidade' => $filtroVisibilidade
    ]);

    // 1. Salvar / Editar Agendamento
    if ($acao === 'salvar_agendamento') {
        $idAgendamento = (int)($_POST['id_agendamento'] ?? 0);
        $idCliente = (int)$_POST['id_cliente'];
        $dataAgendamento = $_POST['data_agendamento'];
        $horaAgendamento = $_POST['hora_agendamento'];
        $horaFimAgendamento = $_POST['hora_fim_agendamento'];
        $descricao = addslashes($_POST['descricao_agendamento']);

        $tsIniAg = strtotime($horaAgendamento);
        $tsFinAg = strtotime($horaFimAgendamento);

        if ($tsFinAg <= $tsIniAg) {
            $msgErro = "A hora final do agendamento deve ser estritamente maior que a hora inicial!";
        } else {
            if ($idAgendamento > 0) {
                $sql = "UPDATE AGENDAMENTOS SET 
                            ID_CLIENTE = $idCliente, 
                            DATA_AGENDAMENTO = '$dataAgendamento', 
                            HORA_AGENDAMENTO = '$horaAgendamento', 
                            HORA_FIM_AGENDAMENTO = '$horaFimAgendamento', 
                            DESCRICAO_AGENDAMENTO = '$descricao' 
                        WHERE ID_AGENDAMENTO = $idAgendamento";
                $db->Query($sql);
                $msgSucesso = "Agendamento atualizado com sucesso!";
            } else {
                $sql = "INSERT INTO AGENDAMENTOS (ID_CLIENTE, ID_USUARIO, DATA_AGENDAMENTO, HORA_AGENDAMENTO, HORA_FIM_AGENDAMENTO, DESCRICAO_AGENDAMENTO, FATURADO) 
                        VALUES ($idCliente, $idUsuarioLogado, '$dataAgendamento', '$horaAgendamento', '$horaFimAgendamento', '$descricao', 'N')";
                $db->Query($sql);
                $msgSucesso = "Agendamento criado com sucesso!";
            }
        }
    }

    // 2. Excluir Agendamento
    if ($acao === 'excluir_agendamento') {
        $idAgendamento = (int)$_POST['id_agendamento'];
        $db->Query("DELETE FROM APONTAMENTO_AGENDAMENTOS WHERE ID_AGENDAMENTO = $idAgendamento");
        $db->Query("DELETE FROM AGENDAMENTOS WHERE ID_AGENDAMENTO = $idAgendamento");
        $msgSucesso = "Agendamento excluído com sucesso!";
    }

    // 3. Salvar / Editar Apontamento
    if ($acao === 'salvar_apontamento') {
        $idApontamento = (int)($_POST['id_apontamento'] ?? 0);
        $idAgendamento = (int)$_POST['id_agendamento'];
        $dataApontamento = $_POST['data_apontamento'];
        $horaInicial = $_POST['hora_inicial'];
        $horaFinal = $_POST['hora_final'];
        $idServico = (int)($_POST['id_servico'] ?? 0);
        $idChamado = !empty($_POST['id_chamado']) ? (int)$_POST['id_chamado'] : 'NULL';
        $descricaoApontamento = addslashes(substr($_POST['descricao_apontamento'] ?? '', 0, 255));
        $modalAbertoId = $idAgendamento;

        $tsIni = strtotime($horaInicial);
        $tsFin = strtotime($horaFinal);
        if ($tsFin <= $tsIni) {
            $msgErro = "A hora final deve ser estritamente maior que a hora inicial!";
        } elseif ($idServico <= 0) {
            $msgErro = "Selecione o serviço referente ao apontamento!";
        } else {
            $diffSegundos = $tsFin - $tsIni;
            $horas = floor($diffSegundos / 3600);
            $minutos = floor(($diffSegundos / 60) % 60);
            $segundos = $diffSegundos % 60;
            $totalHoras = sprintf('%02d:%02d:%02d', $horas, $minutos, $segundos);

            $sqlConflitoAp = "SELECT ID_APONTAMENTO FROM APONTAMENTO_AGENDAMENTOS 
                              WHERE ID_AGENDAMENTO = $idAgendamento 
                              AND DATA_APONTAMENTO = '$dataApontamento' 
                              AND ('$horaInicial' < HORA_FINAL AND '$horaFinal' > HORA_INICIAL)";
            if ($idApontamento > 0) {
                $sqlConflitoAp .= " AND ID_APONTAMENTO != $idApontamento";
            }
            $conflitosAp = $db->Query($sqlConflitoAp);

            if (!empty($conflitosAp)) {
                $msgErro = "Conflito de horários! O intervalo informado se sobrepõe a outro apontamento existente neste agendamento.";
            } else {
                $valChamadoSql = ($idChamado === 'NULL') ? "NULL" : $idChamado;
                if ($idApontamento > 0) {
                    $sql = "UPDATE APONTAMENTO_AGENDAMENTOS SET 
                                DATA_APONTAMENTO = '$dataApontamento', 
                                HORA_INICIAL = '$horaInicial', 
                                HORA_FINAL = '$horaFinal', 
                                TOTAL_HORAS_APONTAMENTO = '$totalHoras',
                                ID_SERVICO = $idServico,
                                ID_CHAMADO = $valChamadoSql,
                                DESCRICAO_APONTAMENTO = '$descricaoApontamento'
                            WHERE ID_APONTAMENTO = $idApontamento";
                    $db->Query($sql);
                    $msgSucesso = "Apontamento atualizado com sucesso!";
                } else {
                    $sql = "INSERT INTO APONTAMENTO_AGENDAMENTOS (ID_AGENDAMENTO, DATA_APONTAMENTO, HORA_INICIAL, HORA_FINAL, TOTAL_HORAS_APONTAMENTO, ID_SERVICO, ID_CHAMADO, DESCRICAO_APONTAMENTO) 
                            VALUES ($idAgendamento, '$dataApontamento', '$horaInicial', '$horaFinal', '$totalHoras', $idServico, $valChamadoSql, '$descricaoApontamento')";
                    $db->Query($sql);
                    $msgSucesso = "Apontamento incluído com sucesso!";
                }
            }
        }
    }

    // 4. Excluir Apontamento
    if ($acao === 'excluir_apontamento') {
        $idApontamento = (int)$_POST['id_apontamento'];
        $modalAbertoId = (int)$_POST['id_agendamento'];
        $db->Query("DELETE FROM APONTAMENTO_AGENDAMENTOS WHERE ID_APONTAMENTO = $idApontamento");
        $msgSucesso = "Apontamento removido com sucesso!";
    }

    // 5. Faturar Agendamentos Selecionados
    if ($acao === 'faturar_agendamentos') {
        $idClienteFiltro = (int)($_POST['faturar_cliente'] ?? 0);
        $idsAgendamentos = $_POST['agendamentos_selecionados'] ?? [];
        $dataVencimento = $_POST['faturar_vencimento'] ?? '';
        $idCategoria = (int)($_POST['faturar_categoria'] ?? 0);

        if ($idClienteFiltro <= 0 || empty($idsAgendamentos)) {
            $msgErro = "Selecione o cliente e ao menos um agendamento para faturar.";
        } elseif (empty($dataVencimento) || $idCategoria <= 0) {
            $msgErro = "Informe a Data de Vencimento e selecione a Categoria de Receitas.";
        } else {
            $resCli = $db->Query("SELECT RAZAO_SOCIAL, VALOR_HORA FROM CLIENTES WHERE ID_CLIENTE = $idClienteFiltro");
            if (empty($resCli) || empty($resCli[0]['VALOR_HORA']) || (float)$resCli[0]['VALOR_HORA'] <= 0) {
                $msgErro = "O cliente selecionado não possui o campo 'Valor Hora' informado no cadastro.";
            } else {
                $razaoSocialCli = $resCli[0]['RAZAO_SOCIAL'];
                $valorHora = (float)$resCli[0]['VALOR_HORA'];

                $idsStr = implode(',', array_map('intval', $idsAgendamentos));
                $sqlAps = "SELECT TOTAL_HORAS_APONTAMENTO FROM APONTAMENTO_AGENDAMENTOS WHERE ID_AGENDAMENTO IN ($idsStr)";
                $resAps = $db->Query($sqlAps) ?: [];

                $totalSegundos = 0;
                foreach ($resAps as $ap) {
                    list($hh, $mm, $ss) = explode(':', $ap['TOTAL_HORAS_APONTAMENTO']);
                    $totalSegundos += ($hh * 3600) + ($mm * 60) + $ss;
                }

                $totalHorasDecimal = $totalSegundos / 3600;
                $valorTotalFaturamento = round($totalHorasDecimal * $valorHora, 2);

                if ($valorTotalFaturamento <= 0) {
                    $msgErro = "Os agendamentos selecionados não possuem horas apontadas válidas para faturamento.";
                } else {
                    $dataEmissaoHoje = date('Y-m-d');
                    $descricaoTitulo = addslashes("Faturamento de Agendamentos - " . $razaoSocialCli);
                    $observacaoTitulo = addslashes("Faturamento referente a " . count($idsAgendamentos) . " agendamento(s) realizado(s).");

                    $sqlMov = "INSERT INTO MOVIMENTO_FINANCEIRO (DESCRICAO, ID_CLIENTE, PAGREC, DATA_EMISSAO, VALOR_DOCUMENTO, ID_CATEGORIA, OBSERVACAO) 
                               VALUES ('$descricaoTitulo', $idClienteFiltro, 'R', '$dataEmissaoHoje', $valorTotalFaturamento, $idCategoria, '$observacaoTitulo')";
                    $db->Query($sqlMov);

                    $resMovId = $db->Query("SELECT ID_FIN_MOV FROM MOVIMENTO_FINANCEIRO ORDER BY ID_FIN_MOV DESC LIMIT 1");
                    $idFinMov = $resMovId[0]['ID_FIN_MOV'];

                    $sqlParc = "INSERT INTO MOVIMENTO_FINANCEIRO_PARCELAS (ID_FIN_MOV, PARCELA, DATA_VENCIMENTO, VALOR_PARCELA) 
                                VALUES ($idFinMov, 1, '$dataVencimento', $valorTotalFaturamento)";
                    $db->Query($sqlParc);

                    $db->Query("UPDATE AGENDAMENTOS SET FATURADO = 'S' WHERE ID_AGENDAMENTO IN ($idsStr)");

                    $msgSucesso = "Faturamento gerado com sucesso! Título financeiro #$idFinMov criado no valor de R$ " . number_format($valorTotalFaturamento, 2, ',', '.');
                }
            }
        }
    }
}

// --- CONSULTA COM OS FILTROS APLICADOS ---
$where = ["A.DATA_AGENDAMENTO = '$dataFiltro'"];
if ($filtroCliente > 0) {
    $where[] = "A.ID_CLIENTE = $filtroCliente";
}
$sqlWhere = " WHERE " . implode(" AND ", $where);

$sqlAgendamentos = "SELECT A.*, 
                           C.RAZAO_SOCIAL AS NOME_CLIENTE,
                           U.NOME AS NOME_USUARIO,
                           (SELECT COUNT(*) FROM APONTAMENTO_AGENDAMENTOS AP WHERE AP.ID_AGENDAMENTO = A.ID_AGENDAMENTO) AS QTD_APONTAMENTOS
                    FROM AGENDAMENTOS A
                    INNER JOIN CLIENTES C ON A.ID_CLIENTE = C.ID_CLIENTE
                    INNER JOIN USUARIOS U ON A.ID_USUARIO = U.ID_USUARIO
                    $sqlWhere
                    ORDER BY A.HORA_AGENDAMENTO ASC";

$agendamentosRaw = $db->Query($sqlAgendamentos) ?: [];

$agendamentos = [];
foreach ($agendamentosRaw as $ag) {
    $temApontamento = ($ag['QTD_APONTAMENTOS'] > 0);
    if ($filtroStatus === 'R' && !$temApontamento) continue;
    if ($filtroStatus === 'N' && $temApontamento) continue;
    $agendamentos[] = $ag;
}

// Mapeia agendamentos ocupando todas as horas compreendidas entre hora_agendamento e hora_fim_agendamento
$agendamentosPorHora = array_fill(0, 24, []);
foreach ($agendamentos as $ag) {
    $horaIniInt = (int)substr($ag['HORA_AGENDAMENTO'], 0, 2);
    $horaFimInt = !empty($ag['HORA_FIM_AGENDAMENTO']) ? (int)substr($ag['HORA_FIM_AGENDAMENTO'], 0, 2) : $horaIniInt;
    
    if ($horaFimInt < $horaIniInt) {
        $horaFimInt = $horaIniInt;
    }

    for ($h = $horaIniInt; $h <= $horaFimInt; $h++) {
        if ($h >= 0 && $h <= 23) {
            $jaAdicionado = false;
            foreach ($agendamentosPorHora[$h] as $cadastrado) {
                if ($cadastrado['ID_AGENDAMENTO'] == $ag['ID_AGENDAMENTO']) {
                    $jaAdicionado = true;
                    break;
                }
            }
            if (!$jaAdicionado) {
                $agendamentosPorHora[$h][] = $ag;
            }
        }
    }
}

$clientesLista = $db->Query("SELECT ID_CLIENTE, RAZAO_SOCIAL, VALOR_HORA FROM CLIENTES ORDER BY RAZAO_SOCIAL ASC") ?: [];
$parceirosLista = $db->Query("SELECT ID_PARCEIRO, RAZAO_SOCIAL FROM PARCEIROS ORDER BY RAZAO_SOCIAL ASC") ?: [];
$categoriasReceita = $db->Query("SELECT ID_CATEGORIA, DESCRICAO FROM CATEGORIAS WHERE DESP_REC = 'RECEITA' AND (INATIVO IS NULL OR INATIVO = 0) ORDER BY DESCRICAO ASC") ?: [];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Agenda e Apontamentos - <?= htmlspecialchars($nomeEmpresaSistema) ?></title>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" />
    <link rel="stylesheet" href="css/suporte.css">
    <style>
        .timeline-container {
            display: flex;
            flex-direction: column;
            gap: 15px;
            margin-top: 20px;
        }
        .time-lane {
            background: #fff;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 2px 6px rgba(0,0,0,0.03);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        .time-lane-header {
            background: #f1f5f9;
            padding: 8px 16px;
            font-size: 13px;
            font-weight: 700;
            color: #0f172a;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .time-lane-body {
            padding: 12px;
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 15px;
            background: #fafafa;
        }
        .time-lane-empty {
            padding: 12px 16px;
            color: #94a3b8;
            font-size: 13px;
            font-style: italic;
        }

        .agenda-card {
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            border: 1px solid #cbd5e1;
            display: flex;
            flex-direction: column;
            position: relative;
            overflow: hidden;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .agenda-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .agenda-card-header {
            background: #001517;
            color: #fff;
            padding: 10px 14px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .agenda-date-badge {
            background: rgba(255,255,255,0.15);
            padding: 3px 6px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
        }
        .agenda-card-body {
            padding: 12px;
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .agenda-client {
            font-size: 15px;
            font-weight: 700;
            color: #0f172a;
        }
        .agenda-desc {
            font-size: 12px;
            color: #475569;
            background: #f8fafc;
            padding: 8px;
            border-radius: 6px;
            border-left: 3px solid #001517;
        }
        .agenda-meta {
            font-size: 11px;
            color: #64748b;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-top: 1px solid #f1f5f9;
            padding-top: 8px;
            margin-top: auto;
        }
        .agenda-card-footer {
            background: #f8fafc;
            padding: 10px 12px;
            border-top: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 6px;
        }
        .checked-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            color: #16a34a;
            font-weight: 600;
            font-size: 11px;
            background: #dcfce7;
            padding: 3px 8px;
            border-radius: 20px;
        }
        .unchecked-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            color: #d97706;
            font-weight: 600;
            font-size: 11px;
            background: #fef3c7;
            padding: 3px 8px;
            border-radius: 20px;
        }
        .faturado-badge {
            background: #0284c7;
            color: #fff;
            font-size: 10px;
            padding: 2px 5px;
            border-radius: 4px;
            font-weight: 600;
        }
        .btn-faturar-3d {
            background: linear-gradient(135deg, #059669 0%, #047857 100%);
            color: #fff;
            border: none;
            box-shadow: 0 4px 0 #065f46, 0 5px 10px rgba(0,0,0,0.15);
            transition: all 0.1s ease;
        }
        .btn-faturar-3d:hover {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            transform: translateY(-1px);
            box-shadow: 0 5px 0 #065f46, 0 7px 12px rgba(0,0,0,0.2);
        }
        .btn-faturar-3d:active {
            transform: translateY(2px);
            box-shadow: 0 2px 0 #065f46, 0 3px 6px rgba(0,0,0,0.15);
        }
        .btn-extrato-3d {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            color: #fff;
            border: none;
            box-shadow: 0 4px 0 #1e40af, 0 5px 10px rgba(0,0,0,0.15);
            transition: all 0.1s ease;
        }
        .btn-extrato-3d:hover {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            transform: translateY(-1px);
            box-shadow: 0 5px 0 #1e40af, 0 7px 12px rgba(0,0,0,0.2);
        }
        .btn-extrato-3d:active {
            transform: translateY(2px);
            box-shadow: 0 2px 0 #1e40af, 0 3px 6px rgba(0,0,0,0.15);
        }
        .alert-box {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .alert-success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }

        @media print {
            body * { visibility: hidden; }
            #extratoPrintArea, #extratoPrintArea * { visibility: visible; }
            #extratoPrintArea {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
                background: #fff;
                padding: 20px;
            }
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
            $sidebar = new Sidebar('agendamentos', $isMaster); 
            $sidebar->render(); 
        ?>

        <main class="main-content">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px;" class="no-print">
                <h1 class="page-title" style="margin-bottom: 0;"><i class="fa-solid fa-calendar-days"></i> Agenda e Apontamentos</h1>
                
                <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                    <button type="button" class="btn-3d btn-extrato-3d" onclick="openExtratoModal()">
                        <i class="fa-solid fa-file-pdf"></i> Extrato de Apontamentos
                    </button>
                    <button type="button" class="btn-3d btn-primary-3d" onclick="openAgendamentoModal()">
                        <i class="fa-solid fa-plus"></i> Novo Agendamento
                    </button>
                </div>
            </div>

            <?php if (!empty($msgSucesso)): ?>
                <div class="alert-box alert-success no-print"><i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($msgSucesso) ?></div>
            <?php endif; ?>
            <?php if (!empty($msgErro)): ?>
                <div class="alert-box alert-error no-print"><i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($msgErro) ?></div>
            <?php endif; ?>

            <!-- FILTROS DE PESQUISA -->
            <div class="filters-card no-print">
                <form method="GET" action="agendamentos.php" class="filters-grid">
                    <div class="form-group">
                        <label>Data:</label>
                        <input type="date" name="data" value="<?= $dataFiltro ?>" required>
                    </div>

                    <div class="form-group">
                        <label>Exibição dos Horários:</label>
                        <select name="visibilidade">
                            <option value="O" <?= $filtroVisibilidade === 'O' ? 'selected' : '' ?>>Apenas Horários Ocupados</option>
                            <option value="L" <?= $filtroVisibilidade === 'L' ? 'selected' : '' ?>>Apenas Horários Livres</option>
                            <option value="T" <?= $filtroVisibilidade === 'T' ? 'selected' : '' ?>>Todos os Horários (00:00 às 23:00)</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Cliente:</label>
                        <select name="cliente">
                            <option value="">Todos os Clientes</option>
                            <?php foreach ($clientesLista as $cli): ?>
                                <option value="<?= $cli['ID_CLIENTE'] ?>" <?= $filtroCliente == $cli['ID_CLIENTE'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cli['RAZAO_SOCIAL']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Status:</label>
                        <select name="status">
                            <option value="">Todos os Status</option>
                            <option value="R" <?= $filtroStatus === 'R' ? 'selected' : '' ?>>Realizados (Com Apontamento)</option>
                            <option value="N" <?= $filtroStatus === 'N' ? 'selected' : '' ?>>Não Realizados (Sem Apontamento)</option>
                        </select>
                    </div>

                    <div class="filters-actions">
                        <button type="submit" class="btn-3d btn-secondary-3d"><i class="fa-solid fa-magnifying-glass"></i> Filtrar</button>
                    </div>
                </form>
            </div>

            <!-- EXIBIÇÃO EM TIMELINE / RAIAS DE HORÁRIO -->
            <div class="timeline-container no-print">
                <?php 
                $contagemLanesExibidas = 0;

                for ($hora = 0; $hora <= 23; $hora++):
                    $horaFormatada = sprintf('%02d:00', $hora);
                    $listaAgendamentosHora = $agendamentosPorHora[$hora];
                    $temAgendamento = !empty($listaAgendamentosHora);

                    if ($filtroVisibilidade === 'O' && !$temAgendamento) continue;
                    if ($filtroVisibilidade === 'L' && $temAgendamento) continue;

                    $contagemLanesExibidas++;
                ?>
                    <div class="time-lane">
                        <div class="time-lane-header">
                            <span><i class="fa-regular fa-clock"></i> <?= $horaFormatada ?> às <?= sprintf('%02d:59', $hora) ?></span>
                            <span style="font-size: 11px; opacity: 0.8; font-weight: normal;">
                                <?= $temAgendamento ? count($listaAgendamentosHora) . ' agendamento(s)' : 'Horário Livre' ?>
                            </span>
                        </div>

                        <?php if ($temAgendamento): ?>
                            <div class="time-lane-body">
                                <?php foreach ($listaAgendamentosHora as $ag): 
                                    $realizado = ($ag['QTD_APONTAMENTOS'] > 0);
                                    $faturado = ($ag['FATURADO'] === 'S');
                                    $horarioExibicao = substr($ag['HORA_AGENDAMENTO'], 0, 5) . (!empty($ag['HORA_FIM_AGENDAMENTO']) ? ' às ' . substr($ag['HORA_FIM_AGENDAMENTO'], 0, 5) : '');
                                ?>
                                    <div class="agenda-card">
                                        <div class="agenda-card-header">
                                            <span style="font-size: 13px; font-weight: 600;"><i class="fa-regular fa-clock"></i> <?= $horarioExibicao ?>h</span>
                                            <div style="display: flex; gap: 6px; align-items: center;">
                                                <?php if ($faturado): ?>
                                                    <span class="faturado-badge">FATURADO</span>
                                                <?php endif; ?>
                                                <span class="agenda-date-badge"><i class="fa-regular fa-calendar"></i> <?= date('d/m/Y', strtotime($ag['DATA_AGENDAMENTO'])) ?></span>
                                            </div>
                                        </div>
                                        <div class="agenda-card-body">
                                            <div class="agenda-client"><?= htmlspecialchars($ag['NOME_CLIENTE']) ?></div>
                                            <div class="agenda-desc"><?= htmlspecialchars($ag['DESCRICAO_AGENDAMENTO']) ?></div>
                                            <div class="agenda-meta">
                                                <span><i class="fa-solid fa-user-pen"></i> <?= htmlspecialchars($ag['NOME_USUARIO']) ?></span>
                                                <?php if ($realizado): ?>
                                                    <span class="checked-badge"><i class="fa-solid fa-circle-check"></i> Realizado (<?= $ag['QTD_APONTAMENTOS'] ?>)</span>
                                                <?php else: ?>
                                                    <span class="unchecked-badge"><i class="fa-regular fa-clock"></i> Pendente</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="agenda-card-footer">
                                            <button type="button" class="btn-3d btn-secondary-3d btn-sm-3d" onclick='openApontamentosModal(<?= json_encode($ag) ?>)'>
                                                <i class="fa-solid fa-stopwatch"></i> Apontamentos
                                            </button>
                                            <div style="display: flex; gap: 6px;">
                                                <button type="button" class="btn-3d btn-secondary-3d btn-sm-3d" onclick='openAgendamentoModal(<?= json_encode($ag) ?>)'>
                                                    <i class="fa-solid fa-pen"></i>
                                                </button>
                                                <form method="POST" action="agendamentos.php?<?= $queryStringFiltros ?>" style="display:inline;" onsubmit="return confirm('Deseja excluir este agendamento e seus apontamentos?');">
                                                    <input type="hidden" name="acao" value="excluir_agendamento">
                                                    <input type="hidden" name="id_agendamento" value="<?= $ag['ID_AGENDAMENTO'] ?>">
                                                    <input type="hidden" name="filtro_data" value="<?= $dataFiltro ?>">
                                                    <input type="hidden" name="filtro_cliente" value="<?= $filtroCliente ?>">
                                                    <input type="hidden" name="filtro_status" value="<?= $filtroStatus ?>">
                                                    <input type="hidden" name="filtro_visibilidade" value="<?= $filtroVisibilidade ?>">
                                                    <button type="submit" class="btn-3d btn-danger-3d btn-sm-3d">
                                                        <i class="fa-solid fa-trash"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="time-lane-empty">Sem agendamentos vinculados a este horário.</div>
                        <?php endif; ?>
                    </div>
                <?php endfor; ?>

                <?php if ($contagemLanesExibidas === 0): ?>
                    <div style="text-align: center; padding: 40px; background: #fff; border-radius: 8px; border: 1px solid #e2e8f0; color: #64748b;">
                        <i class="fa-regular fa-calendar-xmark" style="font-size: 48px; margin-bottom: 10px; color: #cbd5e1;"></i>
                        <p>Nenhum horário correspondente aos filtros selecionados para o dia <?= date('d/m/Y', strtotime($dataFiltro)) ?>.</p>
                    </div>
                <?php endif; ?>
            </div>

        </main>
    </div>

    <!-- MODAL: CONFIGURAR EXTRATO DE APONTAMENTOS -->
    <div class="modal-overlay no-print" id="extratoModal">
        <div class="modal-card" style="max-width: 650px;">
            <div class="modal-header">
                <h3><i class="fa-solid fa-file-pdf"></i> Gerar Extrato de Apontamentos</h3>
                <i class="fa-solid fa-xmark" style="cursor: pointer;" onclick="closeExtratoModal()"></i>
            </div>
            <div class="modal-body">
                <form id="formExtratoConfig" onsubmit="gerarExtratoPdf(event)">
                    <div class="form-grid" style="grid-template-columns: 1fr; gap: 12px;">
                        <div class="form-group">
                            <label>Tipo de Relatório <span class="required-asterisk">*</span></label>
                            <select id="ext_tipo_filtro" required onchange="toggleTipoExtratoFiltro()" style="padding: 10px; border-radius:6px; border:1.5px solid #cbd5e1; width:100%;">
                                <option value="cliente">Por Cliente</option>
                                <option value="parceiro">Por Parceiro</option>
                            </select>
                        </div>

                        <div class="form-group" id="div_ext_cliente">
                            <label>Cliente <span class="required-asterisk">*</span></label>
                            <select id="ext_cliente" style="padding: 10px; border-radius:6px; border:1.5px solid #cbd5e1; width:100%;">
                                <option value="">-- Selecione o Cliente --</option>
                                <?php foreach ($clientesLista as $cli): ?>
                                    <option value="<?= $cli['ID_CLIENTE'] ?>" data-nome="<?= htmlspecialchars($cli['RAZAO_SOCIAL']) ?>" data-valorhora="<?= $cli['VALOR_HORA'] ?? 0 ?>">
                                        <?= htmlspecialchars($cli['RAZAO_SOCIAL']) ?> (R$ <?= number_format($cli['VALOR_HORA'] ?? 0, 2, ',', '.') ?>/h)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group" id="div_ext_parceiro" style="display: none;">
                            <label>Parceiro <span class="required-asterisk">*</span></label>
                            <select id="ext_parceiro" style="padding: 10px; border-radius:6px; border:1.5px solid #cbd5e1; width:100%;">
                                <option value="">-- Selecione o Parceiro --</option>
                                <?php foreach ($parceirosLista as $par): ?>
                                    <option value="<?= $par['ID_PARCEIRO'] ?>" data-nome="<?= htmlspecialchars($par['RAZAO_SOCIAL']) ?>">
                                        <?= htmlspecialchars($par['RAZAO_SOCIAL']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group" id="div_ext_ordenacao" style="display: none;">
                            <label>Ordenação do Relatório (Por Parceiro) <span class="required-asterisk">*</span></label>
                            <select id="ext_ordenacao" style="padding: 10px; border-radius:6px; border:1.5px solid #cbd5e1; width:100%;">
                                <option value="data">Por Data (Agrupado por Data)</option>
                                <option value="cliente">Por Cliente (Agrupado por Cliente)</option>
                            </select>
                        </div>

                        <div class="form-grid" style="grid-template-columns: 1fr 1fr; gap: 10px;">
                            <div class="form-group">
                                <label>Data Inicial <span class="required-asterisk">*</span></label>
                                <input type="date" id="ext_data_ini" value="<?= date('Y-m-01') ?>" required>
                            </div>
                            <div class="form-group">
                                <label>Data Final <span class="required-asterisk">*</span></label>
                                <input type="date" id="ext_data_fim" value="<?= date('Y-m-t') ?>" required>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Filtro de Faturamento <span class="required-asterisk">*</span></label>
                            <select id="ext_faturado" required style="padding: 10px; border-radius:6px; border:1.5px solid #cbd5e1; width:100%;">
                                <option value="T">Todos os Apontamentos</option>
                                <option value="N">Somente Apontamentos a Faturar (Não Faturados)</option>
                                <option value="S">Somente Apontamentos Faturados</option>
                            </select>
                        </div>
                    </div>

                    <div class="modal-footer" style="margin-top: 20px;">
                        <button type="button" class="btn-3d btn-danger-3d" onclick="closeExtratoModal()">Cancelar</button>
                        <button type="submit" class="btn-3d btn-extrato-3d"><i class="fa-solid fa-print"></i> Gerar Extrato PDF</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ÁREA DE IMPRESSÃO DO EXTRATO -->
    <div id="extratoPrintArea" style="display: none;"></div>

    <!-- MODAL: FATURAR AGENDAMENTOS -->
    <div class="modal-overlay no-print" id="faturamentoModal">
        <div class="modal-card" style="max-width: 850px;">
            <div class="modal-header">
                <h3><i class="fa-solid fa-sack-dollar"></i> Faturamento de Agendamentos</h3>
                <i class="fa-solid fa-xmark" style="cursor: pointer;" onclick="closeFaturamentoModal()"></i>
            </div>
            <form method="POST" action="agendamentos.php?<?= $queryStringFiltros ?>" onsubmit="return confirmarFaturamentoForm(this)">
                <input type="hidden" name="acao" value="faturar_agendamentos">
                <input type="hidden" name="faturar_cliente" id="fat_cli_hidden">
                <input type="hidden" name="filtro_data" value="<?= $dataFiltro ?>">
                <input type="hidden" name="filtro_cliente" value="<?= $filtroCliente ?>">
                <input type="hidden" name="filtro_status" value="<?= $filtroStatus ?>">
                <input type="hidden" name="filtro_visibilidade" value="<?= $filtroVisibilidade ?>">
                
                <div class="modal-body">
                    <div style="background: #f8fafc; padding: 12px; border-radius: 8px; border: 1px solid #e2e8f0; margin-bottom: 15px;">
                        <div class="form-grid" style="grid-template-columns: 2fr 1fr 1fr auto; gap: 10px; align-items: flex-end;">
                            <div class="form-group" style="margin-bottom: 0;">
                                <label>Selecione o Cliente <span class="required-asterisk">*</span></label>
                                <select id="fat_select_cliente" required style="padding: 9px; border-radius:6px; border:1.5px solid #cbd5e1;">
                                    <option value="">-- Escolha o Cliente --</option>
                                    <?php foreach ($clientesLista as $cli): ?>
                                        <option value="<?= $cli['ID_CLIENTE'] ?>" data-valorhora="<?= $cli['VALOR_HORA'] ?? 0 ?>">
                                            <?= htmlspecialchars($cli['RAZAO_SOCIAL']) ?> (R$ <?= number_format($cli['VALOR_HORA'] ?? 0, 2, ',', '.') ?>/h)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group" style="margin-bottom: 0;">
                                <label>Data Inicial:</label>
                                <input type="date" id="fat_data_ini" value="<?= date('Y-m-01') ?>" required style="padding: 8px;">
                            </div>
                            <div class="form-group" style="margin-bottom: 0;">
                                <label>Data Final:</label>
                                <input type="date" id="fat_data_fim" value="<?= date('Y-m-t') ?>" required style="padding: 8px;">
                            </div>
                            <div>
                                <button type="button" class="btn-3d btn-secondary-3d" onclick="carregarAgendamentosParaFaturar()" style="padding: 9px 15px;">
                                    <i class="fa-solid fa-magnifying-glass"></i> Filtrar
                                </button>
                            </div>
                        </div>
                    </div>

                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                        <span style="font-size: 13px; font-weight: 600; color: #334155;"><i class="fa-solid fa-list-check"></i> Agendamentos com Apontamentos (Não Faturados):</span>
                        <button type="button" class="btn-3d btn-secondary-3d btn-sm-3d" onclick="marcarTodosFaturar(true)">Selecionar Todos</button>
                    </div>

                    <div class="table-responsive" style="max-height: 220px; overflow-y: auto; border: 1px solid #e2e8f0; border-radius: 6px;">
                        <table class="custom-table" style="font-size: 12px;">
                            <thead>
                                <tr>
                                    <th style="width: 40px; text-align: center;"><input type="checkbox" id="chk_all_master" onclick="marcarTodosFaturar(this.checked)"></th>
                                    <th>#ID</th>
                                    <th>Data Agend.</th>
                                    <th>Horário</th>
                                    <th>Descrição</th>
                                    <th>Total Horas Apontadas</th>
                                </tr>
                            </thead>
                            <tbody id="fat_tbody_agendamentos">
                                <tr><td colspan="6" style="text-align: center; color: #888; padding: 15px;">Selecione o cliente e clique em Filtrar.</td></tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="form-grid" style="margin-top: 15px; grid-template-columns: 1fr 1fr; gap: 15px;">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label>Data de Vencimento do Título <span class="required-asterisk">*</span></label>
                            <input type="date" name="faturar_vencimento" value="<?= date('Y-m-d', strtotime('+30 days')) ?>" required>
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label>Categoria de Receitas <span class="required-asterisk">*</span></label>
                            <select name="faturar_categoria" required style="padding: 10px; border-radius:6px; border:1.5px solid #cbd5e1;">
                                <option value="">-- Selecione a Categoria --</option>
                                <?php foreach ($categoriasReceita as $cat): ?>
                                    <option value="<?= $cat['ID_CATEGORIA'] ?>"><?= htmlspecialchars($cat['DESCRICAO']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div style="background: #f1f5f9; padding: 12px 15px; border-radius: 6px; margin-top: 15px; display: flex; justify-content: space-between; align-items: center; font-size: 14px;">
                        <div>
                            <span>Total Horas Selecionadas: <strong id="fat_lbl_total_horas" style="color: #001517;">00:00:00</strong></span>
                        </div>
                        <div>
                            <span>Valor Total Faturamento: <strong id="fat_lbl_total_valor" style="color: #059669; font-size: 16px;">R$ 0,00</strong></span>
                        </div>
                    </div>

                    <div class="modal-footer" style="margin-top: 15px;">
                        <button type="button" class="btn-3d btn-danger-3d" onclick="closeFaturamentoModal()">Cancelar</button>
                        <button type="submit" class="btn-3d btn-success-3d"><i class="fa-solid fa-sack-dollar"></i> Efetivar Faturamento</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL: CADASTRAR / EDITAR AGENDAMENTO -->
    <div class="modal-overlay no-print" id="agendamentoModal">
        <div class="modal-card">
            <div class="modal-header">
                <h3 id="modalAgendamentoTitle"><i class="fa-solid fa-calendar-plus"></i> Novo Agendamento</h3>
                <i class="fa-solid fa-xmark" style="cursor: pointer;" onclick="closeAgendamentoModal()"></i>
            </div>
            <form method="POST" action="agendamentos.php?<?= $queryStringFiltros ?>">
                <input type="hidden" name="acao" value="salvar_agendamento">
                <input type="hidden" name="id_agendamento" id="modal_id_agendamento">
                <input type="hidden" name="filtro_data" value="<?= $dataFiltro ?>">
                <input type="hidden" name="filtro_cliente" value="<?= $filtroCliente ?>">
                <input type="hidden" name="filtro_status" value="<?= $filtroStatus ?>">
                <input type="hidden" name="filtro_visibilidade" value="<?= $filtroVisibilidade ?>">

                <div class="modal-body">
                    <div class="form-grid">
                        <div class="form-group full-width">
                            <label>Cliente <span class="required-asterisk">*</span></label>
                            <select name="id_cliente" id="modal_id_cliente" required style="padding: 10px; border-radius:6px; border:1.5px solid #cbd5e1;">
                                <option value="">-- Selecione o Cliente --</option>
                                <?php foreach ($clientesLista as $cli): ?>
                                    <option value="<?= $cli['ID_CLIENTE'] ?>"><?= htmlspecialchars($cli['RAZAO_SOCIAL']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Data do Agendamento <span class="required-asterisk">*</span></label>
                            <input type="date" name="data_agendamento" id="modal_data_agendamento" value="<?= $dataFiltro ?>" required>
                        </div>

                        <div class="form-grid" style="grid-template-columns: 1fr 1fr; gap: 10px; grid-column: span 2;">
                            <div class="form-group" style="margin-bottom: 0;">
                                <label>Hora Inicial <span class="required-asterisk">*</span></label>
                                <input type="time" name="hora_agendamento" id="modal_hora_agendamento" value="08:00" required>
                            </div>
                            <div class="form-group" style="margin-bottom: 0;">
                                <label>Hora Final <span class="required-asterisk">*</span></label>
                                <input type="time" name="hora_fim_agendamento" id="modal_hora_fim_agendamento" value="09:00" required>
                            </div>
                        </div>

                        <div class="form-group full-width" style="margin-top: 10px;">
                            <label>Descrição / Assunto <span class="required-asterisk">*</span></label>
                            <textarea name="descricao_agendamento" id="modal_descricao_agendamento" required rows="3" placeholder="Descreva o objetivo do agendamento..." style="padding: 10px; border-radius:6px; border:1.5px solid #cbd5e1; width:100%;"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer" style="margin-top: 20px;">
                        <button type="button" class="btn-3d btn-danger-3d" onclick="closeAgendamentoModal()">Cancelar</button>
                        <button type="submit" class="btn-3d btn-success-3d"><i class="fa-solid fa-floppy-disk"></i> Salvar Agendamento</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL: GERENCIAR APONTAMENTOS (Com scroll interno e limite de altura para não extrapolar a tela) -->
    <div class="modal-overlay no-print" id="apontamentosModal">
        <div class="modal-card" style="max-width: 900px; max-height: 90vh; display: flex; flex-direction: column;">
            <div class="modal-header" style="flex-shrink: 0;">
                <h3><i class="fa-solid fa-stopwatch"></i> Gerenciar Apontamentos (<span id="lbl_agendamento_id"></span>)</h3>
                <i class="fa-solid fa-xmark" style="cursor: pointer;" onclick="closeApontamentosModal()"></i>
            </div>
            <div class="modal-body" style="overflow-y: auto; flex-grow: 1; padding: 15px;">
                <div id="info_agendamento_card" style="background: #f8fafc; padding: 12px; border-radius: 6px; border: 1px solid #e2e8f0; margin-bottom: 15px; font-size: 13px;">
                </div>

                <form method="POST" action="agendamentos.php?<?= $queryStringFiltros ?>" id="formApontamento" style="background: #fff; padding: 15px; border-radius: 8px; border: 1px solid #cbd5e1; margin-bottom: 20px;">
                    <input type="hidden" name="acao" value="salvar_apontamento">
                    <input type="hidden" name="id_agendamento" id="apont_id_agendamento">
                    <input type="hidden" name="id_apontamento" id="apont_id_apontamento">
                    <input type="hidden" name="filtro_data" value="<?= $dataFiltro ?>">
                    <input type="hidden" name="filtro_cliente" value="<?= $filtroCliente ?>">
                    <input type="hidden" name="filtro_status" value="<?= $filtroStatus ?>">
                    <input type="hidden" name="filtro_visibilidade" value="<?= $filtroVisibilidade ?>">

                    <h4 style="margin-bottom: 10px; color: #001517; font-size: 14px;"><i class="fa-solid fa-plus-minus"></i> <span id="lbl_form_apont">Novo Apontamento de Hora</span></h4>

                    <div class="form-grid" style="grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 10px;">
                        <div class="form-group">
                            <label>Data Apontamento:</label>
                            <input type="date" name="data_apontamento" id="apont_data" required>
                        </div>
                        <div class="form-group">
                            <label>Hora Inicial:</label>
                            <input type="time" name="hora_inicial" id="apont_hora_ini" required onchange="calcularTotalHoras()">
                        </div>
                        <div class="form-group">
                            <label>Hora Final:</label>
                            <input type="time" name="hora_final" id="apont_hora_fim" required onchange="calcularTotalHoras()">
                        </div>
                        <div class="form-group">
                            <label>Total Calculado:</label>
                            <input type="text" id="apont_total_horas" readonly style="background: #f1f5f9; font-weight: bold; text-align: center;" placeholder="00:00:00">
                        </div>
                    </div>

                    <div class="form-grid" style="grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 10px;">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label>Serviço / Projeto <span class="required-asterisk">*</span></label>
                            <select name="id_servico" id="apont_id_servico" required style="padding: 9px; border-radius:6px; border:1.5px solid #cbd5e1; width:100%; font-size: 13px;">
                                <option value="">-- Selecione o Serviço --</option>
                            </select>
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label>Vincular ao Chamado (Opcional)</label>
                            <select name="id_chamado" id="apont_id_chamado" style="padding: 9px; border-radius:6px; border:1.5px solid #cbd5e1; width:100%; font-size: 13px;">
                                <option value="">-- Nenhum Chamado --</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group full-width" style="margin-top: 10px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                            <label style="margin-bottom: 0;">Descrição do Apontamento:</label>
                            <span id="apont_desc_counter" style="font-size: 11px; color: #64748b;">0 / 255 caracteres</span>
                        </div>
                        <textarea name="descricao_apontamento" id="apont_descricao" maxlength="255" rows="2" placeholder="Informe detalhes do serviço ou atividades realizadas..." oninput="atualizarContadorDescricao()" style="padding: 8px; border-radius:6px; border:1.5px solid #cbd5e1; width:100%; font-size: 13px;"></textarea>
                    </div>

                    <div style="display: flex; justify-content: flex-end; gap: 8px; margin-top: 12px;">
                        <button type="button" class="btn-3d btn-secondary-3d btn-sm-3d" id="btn_cancel_apont" onclick="resetFormApontamento(currentAgendamentoObj)" style="display: none;">Cancelar Edição</button>
                        <button type="submit" class="btn-3d btn-success-3d btn-sm-3d"><i class="fa-solid fa-floppy-disk"></i> Salvar Apontamento</button>
                    </div>
                </form>

                <h4 style="margin-bottom: 10px; color: #001517; font-size: 14px;">Apontamentos Realizados:</h4>
                <div class="table-responsive">
                    <table class="custom-table" style="font-size: 12px;">
                        <thead>
                            <tr>
                                <th>#ID</th>
                                <th>Data</th>
                                <th>Início</th>
                                <th>Término</th>
                                <th>Total</th>
                                <th>Serviço / Projeto</th>
                                <th>Chamado</th>
                                <th>Descrição</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody id="lista_apontamentos_tbody">
                        </tbody>
                    </table>
                </div>

                <div class="modal-footer" style="margin-top: 20px;">
                    <button type="button" class="btn-3d btn-secondary-3d" onclick="closeApontamentosModal()">Fechar</button>
                </div>
            </div>
        </div>
    </div>

    <?php
        $todosApontamentos = [];
        if (!empty($agendamentos)) {
            $idsArr = array_column($agendamentos, 'ID_AGENDAMENTO');
            if (!empty($idsArr)) {
                $idsStr = implode(',', $idsArr);
                $resAps = $db->Query("SELECT AP.*, S.DESCRICAO AS NOME_SERVICO, P.DESCRICAO AS NOME_PROJETO, C.TIPO_CHAMADO AS NOME_CHAMADO 
                                      FROM APONTAMENTO_AGENDAMENTOS AP
                                      LEFT JOIN SERVICOS S ON AP.ID_SERVICO = S.ID_SERVICO
                                      LEFT JOIN PROJETOS P ON S.ID_PROJETO = P.ID_PROJETO
                                      LEFT JOIN CHAMADOS C ON AP.ID_CHAMADO = C.ID_CHAMADO
                                      WHERE AP.ID_AGENDAMENTO IN ($idsStr) 
                                      ORDER BY AP.DATA_APONTAMENTO ASC, AP.HORA_INICIAL ASC");
                if ($resAps) {
                    foreach ($resAps as $ap) {
                        $todosApontamentos[$ap['ID_AGENDAMENTO']][] = $ap;
                    }
                }
            }
        }
    ?>

    <script>
        const apontamentosMap = <?= json_encode($todosApontamentos) ?>;
        let currentAgendamentoObj = null;

        function openExtratoModal() { document.getElementById('extratoModal').classList.add('active'); }
        function closeExtratoModal() { document.getElementById('extratoModal').classList.remove('active'); }

        function toggleTipoExtratoFiltro() {
            const tipo = document.getElementById('ext_tipo_filtro').value;
            if (tipo === 'cliente') {
                document.getElementById('div_ext_cliente').style.display = 'block';
                document.getElementById('ext_cliente').setAttribute('required', 'required');
                document.getElementById('div_ext_parceiro').style.display = 'none';
                document.getElementById('ext_parceiro').removeAttribute('required');
                document.getElementById('div_ext_ordenacao').style.display = 'none';
            } else {
                document.getElementById('div_ext_cliente').style.display = 'none';
                document.getElementById('ext_cliente').removeAttribute('required');
                document.getElementById('div_ext_parceiro').style.display = 'block';
                document.getElementById('ext_parceiro').setAttribute('required', 'required');
                document.getElementById('div_ext_ordenacao').style.display = 'block';
            }
        }

        function gerarExtratoPdf(event) {
            event.preventDefault();
            const tipoFiltro = document.getElementById('ext_tipo_filtro').value;
            const dataIni = document.getElementById('ext_data_ini').value;
            const dataFim = document.getElementById('ext_data_fim').value;
            const statusFaturado = document.getElementById('ext_faturado').value;

            let url = `get_extrato_apontamentos.php?tipo=${tipoFiltro}&data_ini=${dataIni}&data_fim=${dataFim}&faturado=${statusFaturado}`;
            let tituloRelatorio = '';

            if (tipoFiltro === 'cliente') {
                const selectCli = document.getElementById('ext_cliente');
                const opt = selectCli.options[selectCli.selectedIndex];
                const idCliente = selectCli.value;
                if (!idCliente) { alert('Selecione um cliente.'); return; }
                url += `&id_cliente=${idCliente}`;
                tituloRelatorio = `Cliente: ${opt.dataset.nome} (R$ ${parseFloat(opt.dataset.valorhora || 0).toLocaleString('pt-BR', {minimumFractionDigits: 2})}/h)`;
            } else {
                const selectPar = document.getElementById('ext_parceiro');
                const optPar = selectPar.options[selectPar.selectedIndex];
                const idParceiro = selectPar.value;
                const ordenacao = document.getElementById('ext_ordenacao').value;
                if (!idParceiro) { alert('Selecione um parceiro.'); return; }
                url += `&id_parceiro=${idParceiro}&ordenacao=${ordenacao}`;
                tituloRelatorio = `Parceiro: ${optPar.dataset.nome} [Ordenação: ${ordenacao === 'data' ? 'Por Data' : 'Por Cliente'}]`;
            }

            fetch(url)
                .then(res => res.json())
                .then(data => {
                    closeExtratoModal();
                    const printArea = document.getElementById('extratoPrintArea');
                    const statusTexto = statusFaturado === 'N' ? 'A Faturar (Não Faturados)' : (statusFaturado === 'S' ? 'Faturados' : 'Todos');
                    const periodoTexto = `${dataIni.split('-').reverse().join('/')} até ${dataFim.split('-').reverse().join('/')}`;
                    const dataEmissaoStr = new Date().toLocaleDateString('pt-BR') + ' ' + new Date().toLocaleTimeString('pt-BR');
                    const nomeEmpresa = data.empresa_nome || '<?= htmlspecialchars($nomeEmpresaSistema) ?>';

                    let html = `
                        <div style="font-family: 'Inter', Arial, sans-serif; color: #0f172a; padding: 20px;">
                            <div style="display: flex; justify-content: space-between; border-bottom: 2px solid #001517; padding-bottom: 15px; margin-bottom: 20px;">
                                <div>
                                    <h2 style="margin: 0; color: #001517; font-size: 20px;"><i class="fa-solid fa-headset"></i> ${nomeEmpresa} - Extrato de Apontamentos</h2>
                                    <p style="margin: 4px 0 0 0; font-size: 13px; color: #475569;">Relatório gerado em ${dataEmissaoStr}</p>
                                </div>
                                <div style="text-align: right;">
                                    <h3 style="margin: 0; color: #2563eb; font-size: 16px;">${tituloRelatorio}</h3>
                                </div>
                            </div>

                            <div style="background: #f8fafc; padding: 10px 15px; border-radius: 6px; border: 1px solid #cbd5e1; margin-bottom: 20px; font-size: 13px; display: flex; justify-content: space-between;">
                                <span>Período: <strong>${periodoTexto}</strong></span>
                                <span>Filtro: <strong>${statusTexto}</strong></span>
                            </div>
                    `;

                    if (!data.grupos || data.grupos.length === 0) {
                        html += `<p style="text-align: center; color: #64748b; padding: 30px;">Nenhum apontamento encontrado com os filtros selecionados.</p>`;
                    } else {
                        data.grupos.forEach(grupo => {
                            html += `
                                <div style="margin-bottom: 20px; border: 1px solid #cbd5e1; border-radius: 8px; overflow: hidden;">
                                    <div style="background: #001517; color: #fff; padding: 8px 12px; font-size: 13px; display: flex; justify-content: space-between; align-items: center;">
                                        <span><strong>${grupo.titulo}</strong></span>
                                        <span style="font-size: 11px; opacity: 0.9;">Total Qtd: ${grupo.total_apontamentos} | Tempo Total: ${grupo.total_horas_formatado}</span>
                                    </div>
                                    <table style="width: 100%; border-collapse: collapse; font-size: 12px;">
                                        <thead>
                                            <tr style="background: #e2e8f0; text-align: left;">
                                                <th style="padding: 6px 10px;">#Apont.</th>
                                                <th style="padding: 6px 10px;">Cliente</th>
                                                <th style="padding: 6px 10px;">Data</th>
                                                <th style="padding: 6px 10px;">Início</th>
                                                <th style="padding: 6px 10px;">Término</th>
                                                <th style="padding: 6px 10px;">Total Horas</th>
                                                <th style="padding: 6px 10px;">Serviço / Projeto</th>
                                                <th style="padding: 6px 10px;">Descrição</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                            `;

                            grupo.itens.forEach(ap => {
                                const servProjStr = (ap.NOME_SERVICO ? `Serviço: ${ap.NOME_SERVICO}` : '') + (ap.NOME_PROJETO ? ` | Projeto: ${ap.NOME_PROJETO}` : '');
                                html += `
                                    <tr style="border-bottom: 1px solid #f1f5f9;">
                                        <td style="padding: 6px 10px;">#${ap.ID_APONTAMENTO}</td>
                                        <td style="padding: 6px 10px;"><strong>${ap.NOME_CLIENTE}</strong></td>
                                        <td style="padding: 6px 10px;">${ap.DATA_APONTAMENTO.split('-').reverse().join('/')}</td>
                                        <td style="padding: 6px 10px;">${ap.HORA_INICIAL.substring(0,5)}</td>
                                        <td style="padding: 6px 10px;">${ap.HORA_FINAL.substring(0,5)}</td>
                                        <td style="padding: 6px 10px;"><strong>${ap.TOTAL_HORAS_APONTAMENTO}</strong></td>
                                        <td style="padding: 6px 10px;">${servProjStr || '-'}</td>
                                        <td style="padding: 6px 10px;">${ap.DESCRICAO_APONTAMENTO || '<em style="color:#94a3b8;">-</em>'}</td>
                                    </tr>
                                `;
                            });

                            html += `
                                        </tbody>
                                    </table>
                                    <div style="background: #f1f5f9; padding: 8px 12px; font-size: 12px; text-align: right; border-top: 1px solid #cbd5e1;">
                                        Subtotal do Grupo: <strong>${grupo.total_apontamentos} apontamento(s)</strong> | Tempo: <strong>${grupo.total_horas_formatado}</strong>
                                    </div>
                                </div>
                            `;
                        });

                        if (data.totais_gerais) {
                            html += `
                                <div style="background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; padding: 15px; margin-top: 25px;">
                                    <h4 style="margin: 0 0 10px 0; font-size: 14px; color: #001517;"><i class="fa-solid fa-chart-pie"></i> Resumo Geral por Cliente</h4>
                                    <table style="width: 100%; border-collapse: collapse; font-size: 12px; margin-bottom: 10px;">
                                        <thead>
                                            <tr style="background: #e2e8f0; text-align: left;">
                                                <th style="padding: 6px 10px;">Cliente</th>
                                                <th style="padding: 6px 10px; text-align: center;">Qtd Apontamentos</th>
                                                <th style="padding: 6px 10px; text-align: right;">Tempo Total</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                            `;
                            data.totais_gerais.clientes.forEach(c => {
                                html += `
                                    <tr style="border-bottom: 1px solid #e2e8f0;">
                                        <td style="padding: 6px 10px;">${c.cliente_nome}</td>
                                        <td style="padding: 6px 10px; text-align: center;">${c.qtd_apontamentos}</td>
                                        <td style="padding: 6px 10px; text-align: right;"><strong>${c.total_horas_formatado}</strong></td>
                                    </tr>
                                `;
                            });
                            html += `
                                        </tbody>
                                    </table>
                                    <div style="display: flex; justify-content: space-between; font-size: 13px; border-top: 2px solid #001517; padding-top: 8px;">
                                        <span>Total Geral de Apontamentos: <strong>${data.totais_gerais.qtd_geral}</strong></span>
                                        <span>Tempo Total Geral: <strong style="color: #059669; font-size: 15px;">${data.totais_gerais.tempo_geral}</strong></span>
                                    </div>
                                </div>
                            `;
                        }
                    }

                    html += `</div>`;
                    printArea.innerHTML = html;
                    printArea.style.display = 'block';
                    window.print();
                    setTimeout(() => { printArea.style.display = 'none'; }, 1000);
                });
        }

        function openFaturamentoModal() { document.getElementById('faturamentoModal').classList.add('active'); }
        function closeFaturamentoModal() { document.getElementById('faturamentoModal').classList.remove('active'); }

        function carregarAgendamentosParaFaturar() {
            const selectCli = document.getElementById('fat_select_cliente');
            const idCliente = selectCli.value;
            const dataIni = document.getElementById('fat_data_ini').value;
            const dataFim = document.getElementById('fat_data_fim').value;
            const tbody = document.getElementById('fat_tbody_agendamentos');

            document.getElementById('fat_cli_hidden').value = idCliente;
            if (!idCliente) { alert('Selecione um cliente primeiro.'); return; }

            tbody.innerHTML = '<tr><td colspan="6" style="text-align: center; padding: 15px;">Carregando agendamentos...</td></tr>';

            fetch(`get_agendamentos_faturar.php?id_cliente=${idCliente}&data_ini=${dataIni}&data_fim=${dataFim}`)
                .then(res => res.json())
                .then(data => {
                    tbody.innerHTML = '';
                    if (!data || data.length === 0) {
                        tbody.innerHTML = '<tr><td colspan="6" style="text-align: center; color: #888; padding: 15px;">Nenhum agendamento realizado e não faturado encontrado.</td></tr>';
                        atualizarTotaisFaturamento();
                        return;
                    }
                    data.forEach(ag => {
                        tbody.innerHTML += `
                            <tr>
                                <td style="text-align: center;"><input type="checkbox" name="agendamentos_selecionados[]" value="${ag.ID_AGENDAMENTO}" data-segundos="${ag.TOTAL_SEGUNDOS}" class="chk-faturar" onclick="atualizarTotaisFaturamento()"></td>
                                <td><strong>#${ag.ID_AGENDAMENTO}</strong></td>
                                <td>${ag.DATA_AGENDAMENTO.split('-').reverse().join('/')}</td>
                                <td>${ag.HORA_AGENDAMENTO.substring(0,5)}h</td>
                                <td>${ag.DESCRICAO_AGENDAMENTO}</td>
                                <td><strong>${ag.TOTAL_HORAS_FORMATADO}</strong></td>
                            </tr>
                        `;
                    });
                    atualizarTotaisFaturamento();
                });
        }

        function marcarTodosFaturar(marcar) {
            document.querySelectorAll('.chk-faturar').forEach(chk => chk.checked = marcar);
            atualizarTotaisFaturamento();
        }

        function atualizarTotaisFaturamento() {
            const selectCli = document.getElementById('fat_select_cliente');
            const opt = selectCli.options[selectCli.selectedIndex];
            const valorHora = opt && opt.dataset.valorhora ? parseFloat(opt.dataset.valorhora) : 0;
            let totalSegundos = 0;

            document.querySelectorAll('.chk-faturar:checked').forEach(chk => {
                totalSegundos += parseInt(chk.dataset.segundos || 0);
            });

            const valorTotal = (totalSegundos / 3600) * valorHora;
            const th = Math.floor(totalSegundos / 3600);
            const tm = Math.floor((totalSegundos / 60) % 60);
            const ts = totalSegundos % 60;

            document.getElementById('fat_lbl_total_horas').innerText = String(th).padStart(2, '0') + ':' + String(tm).padStart(2, '0') + ':' + String(ts).padStart(2, '0');
            document.getElementById('fat_lbl_total_valor').innerText = 'R$ ' + valorTotal.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        function confirmarFaturamentoForm(form) {
            const selectCli = document.getElementById('fat_select_cliente');
            const opt = selectCli.options[selectCli.selectedIndex];
            const valorHora = opt && opt.dataset.valorhora ? parseFloat(opt.dataset.valorhora) : 0;

            if (valorHora <= 0) {
                alert('Atenção: O cliente selecionado não possui "Valor Hora" informado no cadastro.');
                return false;
            }
            if (document.querySelectorAll('.chk-faturar:checked').length === 0) {
                alert('Selecione pelo menos um agendamento.');
                return false;
            }
            return confirm('Deseja realmente gerar o faturamento para os agendamentos selecionados?');
        }

        function openAgendamentoModal(data = null) {
            if (data) {
                document.getElementById('modalAgendamentoTitle').innerHTML = '<i class="fa-solid fa-pen-to-square"></i> Editar Agendamento #' + data.ID_AGENDAMENTO;
                document.getElementById('modal_id_agendamento').value = data.ID_AGENDAMENTO;
                document.getElementById('modal_id_cliente').value = data.ID_CLIENTE;
                document.getElementById('modal_data_agendamento').value = data.DATA_AGENDAMENTO;
                document.getElementById('modal_hora_agendamento').value = data.HORA_AGENDAMENTO ? data.HORA_AGENDAMENTO.substring(0, 5) : '08:00';
                document.getElementById('modal_hora_fim_agendamento').value = data.HORA_FIM_AGENDAMENTO ? data.HORA_FIM_AGENDAMENTO.substring(0, 5) : '09:00';
                document.getElementById('modal_descricao_agendamento').value = data.DESCRICAO_AGENDAMENTO;
            } else {
                document.getElementById('modalAgendamentoTitle').innerHTML = '<i class="fa-solid fa-calendar-plus"></i> Novo Agendamento';
                document.getElementById('modal_id_agendamento').value = '';
                document.getElementById('modal_id_cliente').value = '';
                document.getElementById('modal_data_agendamento').value = '<?= $dataFiltro ?>';
                document.getElementById('modal_hora_agendamento').value = '08:00';
                document.getElementById('modal_hora_fim_agendamento').value = '09:00';
                document.getElementById('modal_descricao_agendamento').value = '';
            }
            document.getElementById('agendamentoModal').classList.add('active');
        }
        function closeAgendamentoModal() { document.getElementById('agendamentoModal').classList.remove('active'); }

        function openApontamentosModal(ag) {
            currentAgendamentoObj = ag;
            document.getElementById('lbl_agendamento_id').innerText = 'Agendamento #' + ag.ID_AGENDAMENTO;
            document.getElementById('apont_id_agendamento').value = ag.ID_AGENDAMENTO;
            document.getElementById('apont_data').value = ag.DATA_AGENDAMENTO;
            
            const horarioFormatado = ag.HORA_AGENDAMENTO.substring(0,5) + (ag.HORA_FIM_AGENDAMENTO ? ' às ' + ag.HORA_FIM_AGENDAMENTO.substring(0,5) : '');
            document.getElementById('info_agendamento_card').innerHTML = `
                <strong>Cliente:</strong> ${ag.NOME_CLIENTE} <br>
                <strong>Data/Horário Agendamento:</strong> ${ag.DATA_AGENDAMENTO.split('-').reverse().join('/')} das ${horarioFormatado}h <br>
                <strong>Descrição:</strong> ${ag.DESCRICAO_AGENDAMENTO}
            `;

            resetFormApontamento(ag);
            carregarServicosCliente(ag.ID_CLIENTE, null);
            carregarChamadosCliente(ag.ID_CLIENTE, null);
            renderizarTabelaApontamentos(ag.ID_AGENDAMENTO, ag.ID_CLIENTE);
            document.getElementById('apontamentosModal').classList.add('active');
        }

        function closeApontamentosModal() {
            document.getElementById('apontamentosModal').classList.remove('active');
            window.location.href = 'agendamentos.php?<?= $queryStringFiltros ?>';
        }

        function carregarServicosCliente(idCliente, servicoSelecionadoId = null) {
            const selectServico = document.getElementById('apont_id_servico');
            selectServico.innerHTML = '<option value="">Carregando serviços...</option>';

            fetch(`get_servicos_cliente.php?id_cliente=${idCliente}`)
                .then(res => res.json())
                .then(data => {
                    selectServico.innerHTML = '<option value="">-- Selecione o Serviço vinculado a projeto em aberto --</option>';
                    if (data && data.length > 0) {
                        data.forEach(srv => {
                            const selected = (servicoSelecionadoId && srv.ID_SERVICO == servicoSelecionadoId) ? 'selected' : '';
                            selectServico.innerHTML += `
                                <option value="${srv.ID_SERVICO}" ${selected}>Serviço: ${srv.SERVICO_DESC} | Projeto: ${srv.PROJETO_DESC}</option>
                            `;
                        });
                    } else {
                        selectServico.innerHTML = '<option value="">Nenhum serviço disponível (projetos em aberto necessários)</option>';
                    }
                })
                .catch(err => {
                    selectServico.innerHTML = '<option value="">Erro ao carregar serviços</option>';
                });
        }

        function carregarChamadosCliente(idCliente, chamadoSelecionadoId = null) {
            const selectChamado = document.getElementById('apont_id_chamado');
            selectChamado.innerHTML = '<option value="">Carregando chamados...</option>';

            fetch(`get_chamados_cliente.php?id_cliente=${idCliente}`)
                .then(res => res.json())
                .then(data => {
                    selectChamado.innerHTML = '<option value="">-- Nenhum Chamado --</option>';
                    if (data && data.length > 0) {
                        data.forEach(ch => {
                            const selected = (chamadoSelecionadoId && ch.ID_CHAMADO == chamadoSelecionadoId) ? 'selected' : '';
                            selectChamado.innerHTML += `
                                <option value="${ch.ID_CHAMADO}" ${selected}>Chamado #${ch.ID_CHAMADO} - ${ch.TIPO_CHAMADO} [Etapa: ${ch.ETAPA_CHAMADO}]</option>
                            `;
                        });
                    }
                })
                .catch(err => {
                    selectChamado.innerHTML = '<option value="">Erro ao carregar chamados</option>';
                });
        }

        function atualizarContadorDescricao() {
            const txt = document.getElementById('apont_descricao');
            const lbl = document.getElementById('apont_desc_counter');
            const qtd = txt.value.length;
            lbl.innerText = `${qtd} / 255 caracteres`;
            if (qtd >= 255) {
                lbl.style.color = '#ef4444';
                lbl.style.fontWeight = 'bold';
            } else {
                lbl.style.color = '#64748b';
                lbl.style.fontWeight = 'normal';
            }
        }

        function resetFormApontamento(ag = null) {
            document.getElementById('apont_id_apontamento').value = '';
            
            let horaIniSugerida = '08:00';
            let horaFimSugerida = '09:00';

            if (ag) {
                const listaAps = apontamentosMap[ag.ID_AGENDAMENTO] || [];
                horaFimSugerida = ag.HORA_FIM_AGENDAMENTO ? ag.HORA_FIM_AGENDAMENTO.substring(0, 5) : '09:00';

                if (listaAps.length > 0) {
                    let ultimoAp = listaAps[listaAps.length - 1];
                    horaIniSugerida = ultimoAp.HORA_FINAL ? ultimoAp.HORA_FINAL.substring(0, 5) : ag.HORA_AGENDAMENTO.substring(0, 5);
                } else {
                    horaIniSugerida = ag.HORA_AGENDAMENTO ? ag.HORA_AGENDAMENTO.substring(0, 5) : '08:00';
                }
            }

            document.getElementById('apont_hora_ini').value = horaIniSugerida;
            document.getElementById('apont_hora_fim').value = horaFimSugerida;
            calcularTotalHoras();

            document.getElementById('apont_id_servico').value = '';
            document.getElementById('apont_id_chamado').value = '';
            document.getElementById('apont_descricao').value = '';
            atualizarContadorDescricao();
            document.getElementById('lbl_form_apont').innerText = 'Novo Apontamento de Hora';
            document.getElementById('btn_cancel_apont').style.display = 'none';
        }

        function calcularTotalHoras() {
            const hIni = document.getElementById('apont_hora_ini').value;
            const hFim = document.getElementById('apont_hora_fim').value;
            const txtTotal = document.getElementById('apont_total_horas');

            if (hIni && hFim) {
                const [h1, m1] = hIni.split(':').map(Number);
                const [h2, m2] = hFim.split(':').map(Number);
                const segundosIni = h1 * 3600 + m1 * 60;
                const segundosFim = h2 * 3600 + m2 * 60;

                if (segundosFim > segundosIni) {
                    const diff = segundosFim - segundosIni;
                    const th = Math.floor(diff / 3600);
                    const tm = Math.floor((diff / 60) % 60);
                    const ts = diff % 60;
                    txtTotal.value = String(th).padStart(2, '0') + ':' + String(tm).padStart(2, '0') + ':' + String(ts).padStart(2, '0');
                } else {
                    txtTotal.value = '00:00:00';
                }
            } else {
                txtTotal.value = '';
            }
        }

        function renderizarTabelaApontamentos(idAgendamento, idCliente) {
            const tbody = document.getElementById('lista_apontamentos_tbody');
            tbody.innerHTML = '';
            const lista = apontamentosMap[idAgendamento] || [];

            if (lista.length === 0) {
                tbody.innerHTML = '<tr><td colspan="9" style="text-align: center; color: #888; padding: 10px;">Nenhum apontamento vinculado.</td></tr>';
                return;
            }

            lista.forEach(ap => {
                const descTexto = ap.DESCRICAO_APONTAMENTO ? ap.DESCRICAO_APONTAMENTO : '<em style="color:#94a3b8;">Sem descrição</em>';
                const servProjStr = (ap.NOME_SERVICO ? `Serviço: ${ap.NOME_SERVICO}` : '') + (ap.NOME_PROJETO ? ` | Projeto: ${ap.NOME_PROJETO}` : '');
                const chamadoStr = ap.NOME_CHAMADO ? `#${ap.ID_CHAMADO} - ${ap.NOME_CHAMADO}` : '<em style="color:#94a3b8;">Não vinculado</em>';

                tbody.innerHTML += `
                    <tr>
                        <td><strong>#${ap.ID_APONTAMENTO}</strong></td>
                        <td>${ap.DATA_APONTAMENTO.split('-').reverse().join('/')}</td>
                        <td>${ap.HORA_INICIAL.substring(0,5)}</td>
                        <td>${ap.HORA_FINAL.substring(0,5)}</td>
                        <td><strong>${ap.TOTAL_HORAS_APONTAMENTO}</strong></td>
                        <td>${servProjStr || '-'}</td>
                        <td>${chamadoStr}</td>
                        <td style="max-width: 150px; word-wrap: break-word;">${descTexto}</td>
                        <td>
                            <button type="button" class="btn-3d btn-secondary-3d btn-sm-3d" onclick='editarApontamento(${JSON.stringify(ap)}, ${idCliente})'>
                                <i class="fa-solid fa-pen"></i>
                            </button>
                            <form method="POST" action="agendamentos.php?<?= $queryStringFiltros ?>" style="display:inline;" onsubmit="return confirm('Excluir este apontamento?');">
                                <input type="hidden" name="acao" value="excluir_apontamento">
                                <input type="hidden" name="id_apontamento" value="${ap.ID_APONTAMENTO}">
                                <input type="hidden" name="id_agendamento" value="${idAgendamento}">
                                <input type="hidden" name="filtro_data" value="<?= $dataFiltro ?>">
                                <input type="hidden" name="filtro_cliente" value="<?= $filtroCliente ?>">
                                <input type="hidden" name="filtro_status" value="<?= $filtroStatus ?>">
                                <input type="hidden" name="filtro_visibilidade" value="<?= $filtroVisibilidade ?>">
                                <button type="submit" class="btn-3d btn-danger-3d btn-sm-3d">
                                    <i class="fa-solid fa-trash"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                `;
            });
        }

        function editarApontamento(ap, idCliente) {
            document.getElementById('apont_id_apontamento').value = ap.ID_APONTAMENTO;
            document.getElementById('apont_data').value = ap.DATA_APONTAMENTO;
            document.getElementById('apont_hora_ini').value = ap.HORA_INICIAL.substring(0,5);
            document.getElementById('apont_hora_fim').value = ap.HORA_FINAL.substring(0,5);
            document.getElementById('apont_total_horas').value = ap.TOTAL_HORAS_APONTAMENTO;
            document.getElementById('apont_descricao').value = ap.DESCRICAO_APONTAMENTO || '';
            carregarServicosCliente(idCliente, ap.ID_SERVICO);
            carregarChamadosCliente(idCliente, ap.ID_CHAMADO);
            atualizarContadorDescricao();
            document.getElementById('lbl_form_apont').innerText = 'Editar Apontamento #' + ap.ID_APONTAMENTO;
            document.getElementById('btn_cancel_apont').style.display = 'inline-block';
        }

        <?php if ($modalAbertoId): ?>
            document.addEventListener("DOMContentLoaded", function() {
                const agObj = <?= json_encode(array_values(array_filter($agendamentos, fn($a) => $a['ID_AGENDAMENTO'] == $modalAbertoId))[0] ?? null) ?>;
                if (agObj) openApontamentosModal(agObj);
            });
        <?php endif; ?>
    </script>
</body>
</html>