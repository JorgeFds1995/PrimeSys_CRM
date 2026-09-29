<?php
session_start();

if (!isset($_SESSION['id_usuario']) || !($_SESSION['master'] ?? false)) {
    header("Location: home_suporte.php?error=" . urlencode("Acesso negado. Área restrita ao financeiro master."));
    exit;
}

require_once __DIR__ . '/php/database.php';
require_once __DIR__ . '/php/Sidebar.php';

$isMaster = $_SESSION['master'] ?? false;
$nomeUsuario = $_SESSION['nome'] ?? 'Administrador';
$db = new database();

// --- PROCESSAMENTO DE FORMULÁRIOS (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';

    // 1. Lançar Nova Conta (Soma Automática de Meses a Partir de Um Único Vencimento)
    if ($acao === 'salvar_movimento') {
        $descricao = addslashes($_POST['descricao']);
        $idCliente = !empty($_POST['id_cliente']) ? (int)$_POST['id_cliente'] : "NULL";
        $pagrec = addslashes($_POST['pagrec']); // P ou R
        $dataEmissao = $_POST['data_emissao'];
        $dataVencimentoInicial = $_POST['data_vencimento'];
        $valorPorParcela = (float)str_replace(['.', ','], ['', '.'], $_POST['valor_documento']);
        $idCategoria = (int)$_POST['id_categoria'];
        $observacao = addslashes($_POST['observacao'] ?? '');
        $qtdParcelas = max(1, (int)($_POST['qtd_parcelas'] ?? 1));

        $valorTotalMovimento = $valorPorParcela * $qtdParcelas;

        // Inserção do Movimento Financeiro Principal
        $sqlMov = "INSERT INTO MOVIMENTO_FINANCEIRO (DESCRICAO, ID_CLIENTE, PAGREC, DATA_EMISSAO, VALOR_DOCUMENTO, ID_CATEGORIA, OBSERVACAO) 
                   VALUES ('$descricao', $idCliente, '$pagrec', '$dataEmissao', $valorTotalMovimento, $idCategoria, '$observacao')";
        $db->Query($sqlMov);

        $resMov = $db->Query("SELECT ID_FIN_MOV FROM MOVIMENTO_FINANCEIRO ORDER BY ID_FIN_MOV DESC LIMIT 1");
        $idFinMov = $resMov[0]['ID_FIN_MOV'];

        // Instancia a data inicial para somar os meses sequencialmente
        $dtVencObj = new DateTime($dataVencimentoInicial);

        for ($i = 1; $i <= $qtdParcelas; $i++) {
            $dtVencString = $dtVencObj->format('Y-m-d');

            $sqlParc = "INSERT INTO MOVIMENTO_FINANCEIRO_PARCELAS (ID_FIN_MOV, PARCELA, DATA_VENCIMENTO, VALOR_PARCELA) 
                        VALUES ($idFinMov, $i, '$dtVencString', $valorPorParcela)";
            $db->Query($sqlParc);

            // Soma +1 mês para a próxima parcela
            $dtVencObj->add(new DateInterval('P1M'));
        }

        header("Location: financeiro_contas.php?msg=sucesso");
        exit;
    }

    // 2. Baixar Parcela
    if ($acao === 'baixar_parcela') {
        $idParcela = (int)$_POST['id_parcela'];
        $dataBaixa = $_POST['data_baixa'];
        $valorBaixado = (float)str_replace(['.', ','], ['', '.'], $_POST['valor_baixado']);
        $valorDesconto = (float)str_replace(['.', ','], ['', '.'], $_POST['valor_descontos'] ?? '0');
        $valorJuros = (float)str_replace(['.', ','], ['', '.'], $_POST['valor_juros'] ?? '0');
        $idBanco = (int)$_POST['id_banco'];
        $obsBaixa = addslashes($_POST['observacao_baixa'] ?? '');

        $sql = "UPDATE MOVIMENTO_FINANCEIRO_PARCELAS SET 
                    DATA_BAIXA = '$dataBaixa', 
                    VALOR_BAIXADO = $valorBaixado, 
                    VALOR_DESCONTOS = $valorDesconto, 
                    VALOR_JUROS = $valorJuros, 
                    ID_BANCO = $idBanco, 
                    OBSERVACAO_BAIXA = '$obsBaixa' 
                WHERE ID_PARCELA = $idParcela";
        $db->Query($sql);
        header("Location: financeiro_contas.php?msg=baixado");
        exit;
    }

    // 3. Estornar Baixa
    if ($acao === 'estornar_parcela') {
        $idParcela = (int)$_POST['id_parcela'];
        $sql = "UPDATE MOVIMENTO_FINANCEIRO_PARCELAS SET 
                    DATA_BAIXA = NULL, 
                    VALOR_BAIXADO = NULL, 
                    VALOR_DESCONTOS = NULL, 
                    VALOR_JUROS = NULL, 
                    ID_BANCO = NULL, 
                    OBSERVACAO_BAIXA = NULL 
                WHERE ID_PARCELA = $idParcela";
        $db->Query($sql);
        header("Location: financeiro_contas.php?msg=estornado");
        exit;
    }

    // 4. Salvar / Editar Categoria
    if ($acao === 'salvar_categoria') {
        $idCategoria = (int)($_POST['id_categoria'] ?? 0);
        $descricaoCat = addslashes($_POST['descricao_categoria']);
        $despRec = addslashes($_POST['desp_rec']);
        $inativo = isset($_POST['inativo']) ? 1 : 0;

        if ($idCategoria > 0) {
            $sql = "UPDATE CATEGORIAS SET DESCRICAO = '$descricaoCat', DESP_REC = '$despRec', INATIVO = $inativo WHERE ID_CATEGORIA = $idCategoria";
        } else {
            $sql = "INSERT INTO CATEGORIAS (DESCRICAO, DESP_REC, INATIVO) VALUES ('$descricaoCat', '$despRec', $inativo)";
        }
        $db->Query($sql);
        header("Location: financeiro_contas.php?msg=cat_ok");
        exit;
    }
    
    // 5. Editar Parcela
    if ($acao === 'editar_parcela') {
        $idParcela = (int)$_POST['id_parcela'];
        $dataVencimento = $_POST['data_vencimento'];
        $valorParcela = (float)str_replace(['.', ','], ['', '.'], $_POST['valor_parcela']);
        $observacaoBaixa = addslashes($_POST['observacao_parcela'] ?? '');
    
        $sql = "UPDATE MOVIMENTO_FINANCEIRO_PARCELAS SET 
                    DATA_VENCIMENTO = '$dataVencimento', 
                    VALOR_PARCELA = $valorParcela, 
                    OBSERVACAO_BAIXA = '$observacaoBaixa' 
                WHERE ID_PARCELA = $idParcela";
                
        $db->Query($sql);
        header("Location: financeiro_contas.php?msg=editado");
        exit;
    }

    // 6. Excluir Parcela Aposta e Recalcular Valor do Movimento
    if ($acao === 'excluir_parcela') {
        $idParcela = (int)$_POST['id_parcela'];

        $resParc = $db->Query("SELECT ID_FIN_MOV, DATA_BAIXA FROM MOVIMENTO_FINANCEIRO_PARCELAS WHERE ID_PARCELA = $idParcela");
        if (!empty($resParc) && empty($resParc[0]['DATA_BAIXA'])) {
            $idFinMov = $resParc[0]['ID_FIN_MOV'];

            $db->Query("DELETE FROM MOVIMENTO_FINANCEIRO_PARCELAS WHERE ID_PARCELA = $idParcela");

            $resSoma = $db->Query("SELECT COALESCE(SUM(VALOR_PARCELA), 0) AS TOTAL FROM MOVIMENTO_FINANCEIRO_PARCELAS WHERE ID_FIN_MOV = $idFinMov");
            $novoTotal = (float)$resSoma[0]['TOTAL'];

            if ($novoTotal > 0) {
                $db->Query("UPDATE MOVIMENTO_FINANCEIRO SET VALOR_DOCUMENTO = $novoTotal WHERE ID_FIN_MOV = $idFinMov");
            } else {
                $db->Query("DELETE FROM MOVIMENTO_FINANCEIRO WHERE ID_FIN_MOV = $idFinMov");
            }
        }

        header("Location: financeiro_contas.php?msg=excluido");
        exit;
    }
}

// --- FILTROS DE PESQUISA DE CONTAS ---
$tipoMovimentacao = addslashes($_GET['tipo_mov'] ?? ''); 
$statusBaixa = addslashes($_GET['status_baixa'] ?? 'A'); 
$campoData = addslashes($_GET['campo_data'] ?? 'V'); 
$dataIni = addslashes($_GET['data_ini'] ?? date('Y-m-01'));
$dataFim = addslashes($_GET['data_fim'] ?? date('Y-m-t'));

$where = ["1=1"];

if (!empty($tipoMovimentacao)) { 
    $where[] = "M.PAGREC = '$tipoMovimentacao'"; 
}

if ($statusBaixa === 'A') { 
    $where[] = "P.DATA_BAIXA IS NULL"; 
} elseif ($statusBaixa === 'B') { 
    $where[] = "P.DATA_BAIXA IS NOT NULL"; 
}

$colunaData = 'P.DATA_VENCIMENTO';
if ($campoData === 'E') {
    $colunaData = 'M.DATA_EMISSAO';
} elseif ($campoData === 'B') {
    $colunaData = 'P.DATA_BAIXA';
}

if (!empty($dataIni) && !empty($dataFim)) {
    $where[] = "($colunaData BETWEEN '$dataIni' AND '$dataFim')";
}

$sqlWhere = " WHERE " . implode(" AND ", $where);

$sqlContas = "SELECT 
                P.ID_PARCELA,
                P.ID_FIN_MOV,
                P.PARCELA,
                P.DATA_VENCIMENTO,
                P.VALOR_PARCELA,
                P.DATA_BAIXA,
                P.VALOR_BAIXADO,
                P.VALOR_DESCONTOS,
                P.VALOR_JUROS,
                P.OBSERVACAO_BAIXA,
                M.DESCRICAO,
                M.PAGREC,
                M.DATA_EMISSAO,
                M.ID_CLIENTE,
                COALESCE(C.RAZAO_SOCIAL, 'Sem Cliente') AS NOME_CLIENTE,
                COALESCE(CAT.DESCRICAO, 'Sem Categoria') AS NOME_CATEGORIA,
                B.DESCRICAO AS NOME_BANCO
              FROM MOVIMENTO_FINANCEIRO_PARCELAS P
              INNER JOIN MOVIMENTO_FINANCEIRO M ON P.ID_FIN_MOV = M.ID_FIN_MOV
              LEFT JOIN CLIENTES C ON M.ID_CLIENTE = C.ID_CLIENTE
              LEFT JOIN CATEGORIAS CAT ON M.ID_CATEGORIA = CAT.ID_CATEGORIA
              LEFT JOIN BANCOS B ON P.ID_BANCO = B.ID_BANCO
              $sqlWhere
              ORDER BY $colunaData ASC";

$contas = $db->Query($sqlContas) ?: [];

// Dados Auxiliares
$categorias = $db->Query("SELECT * FROM CATEGORIAS WHERE INATIVO IS NULL OR INATIVO = 0 ORDER BY DESCRICAO ASC") ?: [];
$todasCategorias = $db->Query("SELECT * FROM CATEGORIAS ORDER BY DESCRICAO ASC") ?: [];
$clientes = $db->Query("SELECT ID_CLIENTE, RAZAO_SOCIAL FROM CLIENTES ORDER BY RAZAO_SOCIAL ASC") ?: [];
$bancos = $db->Query("SELECT ID_BANCO, DESCRICAO FROM BANCOS WHERE INATIVO IS NULL OR INATIVO = 0 ORDER BY DESCRICAO ASC") ?: [];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contas a Pagar e Receber - PrimeSys</title>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/suporte.css">
    <link rel="stylesheet" href="css/financeiro.css">
</head>
<body>

    <header class="top-header">
        <div class="brand"><i class="fa-solid fa-headset"></i> PrimeSys Financeiro</div>
        <div class="user-info">
            <span><i class="fa-solid fa-user"></i> <strong><?= htmlspecialchars($nomeUsuario) ?></strong> <small>(Master)</small></span>
            <a href="logout.php" class="btn-logout"><i class="fa-solid fa-right-from-bracket"></i> Sair</a>
        </div>
    </header>

    <div class="app-container">
        <?php 
            $sidebar = new Sidebar('contas_pagar_receber', $isMaster); 
            $sidebar->render(); 
        ?>

        <main class="main-content">
            <h1 class="page-title">Gestão de Contas a Pagar e Receber</h1>

            <div class="actions-bar">
                <div></div>
                <div style="display: flex; gap: 10px;">
                    <button class="btn-3d btn-secondary-3d" onclick="openCategoriaModal()">
                        <i class="fa-solid fa-tags"></i> Categorias
                    </button>
                    <button class="btn-3d btn-primary-3d" onclick="openNovoLancamentoModal()">
                        <i class="fa-solid fa-plus"></i> Novo Lançamento
                    </button>
                </div>
            </div>

            <!-- FILTROS DE PESQUISA -->
            <div class="filters-card">
                <form method="GET" action="financeiro_contas.php" class="filters-grid">
                    <div class="form-group">
                        <label>Tipo:</label>
                        <select name="tipo_mov" onchange="this.form.submit()">
                            <option value="">A Pagar e Receber</option>
                            <option value="P" <?= $tipoMovimentacao === 'P' ? 'selected' : '' ?>>A Pagar (Despesas)</option>
                            <option value="R" <?= $tipoMovimentacao === 'R' ? 'selected' : '' ?>>A Receber (Receitas)</option>
                        </select>
                    </div>
                
                    <div class="form-group">
                        <label>Status:</label>
                        <select name="status_baixa" onchange="this.form.submit()">
                            <option value="A" <?= $statusBaixa === 'A' ? 'selected' : '' ?>>Em Aberto</option>
                            <option value="B" <?= $statusBaixa === 'B' ? 'selected' : '' ?>>Baixadas / Pagas</option>
                            <option value="T" <?= $statusBaixa === 'T' ? 'selected' : '' ?>>Todas</option>
                        </select>
                    </div>
                
                    <div class="form-group">
                        <label>Filtrar Data Por:</label>
                        <select name="campo_data" onchange="this.form.submit()">
                            <option value="V" <?= $campoData === 'V' ? 'selected' : '' ?>>Data Vencimento</option>
                            <option value="E" <?= $campoData === 'E' ? 'selected' : '' ?>>Data Emissão</option>
                            <option value="B" <?= $campoData === 'B' ? 'selected' : '' ?>>Data Baixa</option>
                        </select>
                    </div>
                
                    <div class="form-group">
                        <label>Data Início:</label>
                        <input type="date" name="data_ini" value="<?= $dataIni ?>">
                    </div>
                
                    <div class="form-group">
                        <label>Data Fim:</label>
                        <input type="date" name="data_fim" value="<?= $dataFim ?>">
                    </div>
                
                    <div class="filters-actions">
                        <button type="submit" class="btn-3d btn-primary-3d"><i class="fa-solid fa-magnifying-glass"></i> Filtrar</button>
                    </div>
                </form>
            </div>

            <!-- TABELA DE PARCELAS -->
            <div class="table-responsive">
                <table class="custom-table">
                    <thead>
                        <tr>
                            <th>Tipo</th>
                            <th>Descrição / Cliente</th>
                            <th>Categoria</th>
                            <th>Parc.</th>
                            <th>Vencimento</th>
                            <th>Valor Parc.</th>
                            <th>Status / Baixa</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($contas)): ?>
                            <tr><td colspan="8" style="text-align: center; padding: 15px;">Nenhuma conta encontrada com os filtros aplicados.</td></tr>
                        <?php else: ?>
                            <?php foreach ($contas as $cnt): ?>
                                <tr>
                                    <td>
                                        <span class="badge-tag <?= $cnt['PAGREC'] === 'P' ? 'badge-pagar' : 'badge-receber' ?>">
                                            <?= $cnt['PAGREC'] === 'P' ? 'PAGAR' : 'RECEBER' ?>
                                        </span>
                                    </td>
                                    <td>
                                        <strong><?= htmlspecialchars($cnt['DESCRICAO']) ?></strong>
                                        <?= !empty($cnt['NOME_CLIENTE']) ? '<br><small style="color:#666;">' . htmlspecialchars($cnt['NOME_CLIENTE']) . '</small>' : '' ?>
                                    </td>
                                    <td><?= htmlspecialchars($cnt['NOME_CATEGORIA']) ?></td>
                                    <td>#<?= $cnt['PARCELA'] ?></td>
                                    <td><?= date('d/m/Y', strtotime($cnt['DATA_VENCIMENTO'])) ?></td>
                                    <td><strong>R$ <?= number_format($cnt['VALOR_PARCELA'], 2, ',', '.') ?></strong></td>
                                    <td>
                                        <?php if (!empty($cnt['DATA_BAIXA'])): ?>
                                            <span class="badge-tag badge-baixado">Baixado <?= date('d/m/Y', strtotime($cnt['DATA_BAIXA'])) ?></span>
                                            <br><small class="valor-entrada">R$ <?= number_format($cnt['VALOR_BAIXADO'], 2, ',', '.') ?></small>
                                        <?php else: ?>
                                            <span class="badge-tag badge-aberto">Em Aberto</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (empty($cnt['DATA_BAIXA'])): ?>
                                            <button class="btn-3d btn-secondary-3d btn-sm-3d" onclick='openEditarParcelaModal(<?= json_encode($cnt) ?>)'>
                                                <i class="fa-solid fa-pen-to-square"></i> Editar
                                            </button>
                                            <button class="btn-3d btn-success-3d btn-sm-3d" onclick='openBaixarModal(<?= json_encode($cnt) ?>)'>
                                                <i class="fa-solid fa-check"></i> Baixar
                                            </button>
                                            <form method="POST" action="financeiro_contas.php" style="display:inline;" onsubmit="return confirm('Tem certeza que deseja excluir esta parcela? O valor total do título será ajustado.');">
                                                <input type="hidden" name="acao" value="excluir_parcela">
                                                <input type="hidden" name="id_parcela" value="<?= $cnt['ID_PARCELA'] ?>">
                                                <button type="submit" class="btn-3d btn-danger-3d btn-sm-3d">
                                                    <i class="fa-solid fa-trash"></i> Excluir
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <button class="btn-3d btn-secondary-3d btn-sm-3d" onclick='openEditarParcelaModal(<?= json_encode($cnt) ?>)'>
                                                <i class="fa-solid fa-pen-to-square"></i> Editar
                                            </button>
                                            <form method="POST" action="financeiro_contas.php" style="display:inline;" onsubmit="return confirm('Confirma o estorno desta baixa?');">
                                                <input type="hidden" name="acao" value="estornar_parcela">
                                                <input type="hidden" name="id_parcela" value="<?= $cnt['ID_PARCELA'] ?>">
                                                <button type="submit" class="btn-3d btn-danger-3d btn-sm-3d">
                                                    <i class="fa-solid fa-rotate-left"></i> Estornar
                                                </button>
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

    <!-- MODAL NOVO LANÇAMENTO -->
    <div class="modal-overlay" id="novoLancamentoModal">
        <div class="modal-card">
            <div class="modal-header">
                <h3><i class="fa-solid fa-file-invoice-dollar"></i> Novo Lançamento Financeiro</h3>
                <i class="fa-solid fa-xmark" style="cursor: pointer;" onclick="closeNovoLancamentoModal()"></i>
            </div>
            <form method="POST" action="financeiro_contas.php">
                <input type="hidden" name="acao" value="salvar_movimento">
                <div class="modal-body">
                    <div class="form-grid">
                        
                        <div class="form-group">
                            <label>Tipo de Conta <span class="required-asterisk">*</span></label>
                            <select name="pagrec" required style="padding: 10px; border-radius:6px; border:1.5px solid #cbd5e1;">
                                <option value="P">A Pagar (Despesa)</option>
                                <option value="R">A Receber (Receita)</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Data de Emissão <span class="required-asterisk">*</span></label>
                            <input type="date" name="data_emissao" value="<?= date('Y-m-d') ?>" required>
                        </div>

                        <div class="form-group full-width">
                            <label>Descrição do Título <span class="required-asterisk">*</span></label>
                            <input type="text" name="descricao" required placeholder="Ex: Fornecedor de Peças, Aluguel">
                        </div>

                        <div class="form-group">
                            <label>Cliente / Favorecido</label>
                            <select name="id_cliente" style="padding: 10px; border-radius:6px; border:1.5px solid #cbd5e1;">
                                <option value="">-- Nenhum / Consumidor --</option>
                                <?php foreach ($clientes as $cli): ?>
                                    <option value="<?= $cli['ID_CLIENTE'] ?>"><?= htmlspecialchars($cli['RAZAO_SOCIAL']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Categoria <span class="required-asterisk">*</span></label>
                            <select name="id_categoria" required style="padding: 10px; border-radius:6px; border:1.5px solid #cbd5e1;">
                                <option value="">-- Selecione --</option>
                                <?php foreach ($categorias as $cat): ?>
                                    <option value="<?= $cat['ID_CATEGORIA'] ?>"><?= htmlspecialchars($cat['DESCRICAO']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Valor de Cada Parcela (R$) <span class="required-asterisk">*</span></label>
                            <input type="text" name="valor_documento" required placeholder="0,00">
                        </div>

                        <div class="form-group">
                            <label>Qtd. de Parcelas <span class="required-asterisk">*</span></label>
                            <input type="number" name="qtd_parcelas" value="1" min="1" max="72" required>
                        </div>

                        <div class="form-group full-width">
                            <label>Vencimento da 1ª Parcela <span class="required-asterisk">*</span></label>
                            <input type="date" name="data_vencimento" value="<?= date('Y-m-d') ?>" required>
                        </div>

                        <div class="form-group full-width">
                            <label>Observação</label>
                            <input type="text" name="observacao" placeholder="Informações adicionais...">
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn-3d btn-danger-3d" onclick="closeNovoLancamentoModal()">Cancelar</button>
                        <button type="submit" class="btn-3d btn-success-3d"><i class="fa-solid fa-floppy-disk"></i> Gerar Contas</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL DE BAIXA DE PARCELA -->
    <div class="modal-overlay" id="baixarModal">
        <div class="modal-card">
            <div class="modal-header">
                <h3><i class="fa-solid fa-check-double"></i> Baixar Título / Parcela</h3>
                <i class="fa-solid fa-xmark" style="cursor: pointer;" onclick="closeBaixarModal()"></i>
            </div>
            <form method="POST" action="financeiro_contas.php">
                <input type="hidden" name="acao" value="baixar_parcela">
                <input type="hidden" name="id_parcela" id="baixa_id_parcela">
                
                <div class="modal-body">
                    <div class="form-grid">
                        
                        <div class="form-group">
                            <label>Data da Baixa <span class="required-asterisk">*</span></label>
                            <input type="date" name="data_baixa" value="<?= date('Y-m-d') ?>" required>
                        </div>

                        <div class="form-group">
                            <label>Caixa / Banco de Destino <span class="required-asterisk">*</span></label>
                            <select name="id_banco" required style="padding: 10px; border-radius:6px; border:1.5px solid #cbd5e1;">
                                <option value="">-- Selecione o Banco --</option>
                                <?php foreach ($bancos as $bnk): ?>
                                    <option value="<?= $bnk['ID_BANCO'] ?>"><?= htmlspecialchars($bnk['DESCRICAO']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Valor da Parcela (R$)</label>
                            <input type="text" id="baixa_valor_original" readonly style="background: #f1f5f9;">
                        </div>

                        <div class="form-group">
                            <label>Valor Efetivamente Baixado (R$) <span class="required-asterisk">*</span></label>
                            <input type="text" name="valor_baixado" id="baixa_valor_baixado" required>
                        </div>

                        <div class="form-group">
                            <label>Descontos (R$)</label>
                            <input type="text" name="valor_descontos" placeholder="0,00">
                        </div>

                        <div class="form-group">
                            <label>Juros / Acréscimos (R$)</label>
                            <input type="text" name="valor_juros" placeholder="0,00">
                        </div>

                        <div class="form-group full-width">
                            <label>Observação da Baixa</label>
                            <input type="text" name="observacao_baixa" placeholder="Ex: Pago via PIX">
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn-3d btn-danger-3d" onclick="closeBaixarModal()">Cancelar</button>
                        <button type="submit" class="btn-3d btn-success-3d"><i class="fa-solid fa-check"></i> Confirmar Baixa</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL GERENCIAR CATEGORIAS -->
    <div class="modal-overlay" id="categoriaModal">
        <div class="modal-card">
            <div class="modal-header">
                <h3><i class="fa-solid fa-tags"></i> Gerenciar Categorias</h3>
                <i class="fa-solid fa-xmark" style="cursor: pointer;" onclick="closeCategoriaModal()"></i>
            </div>
            <div class="modal-body">
                
                <form method="POST" action="financeiro_contas.php" style="margin-bottom: 20px; background: #f8f9fa; padding: 12px; border-radius: 6px;">
                    <input type="hidden" name="acao" value="salvar_categoria">
                    <input type="hidden" name="id_categoria" id="cat_id_categoria">
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Descrição <span class="required-asterisk">*</span></label>
                            <input type="text" name="descricao_categoria" id="cat_descricao" required>
                        </div>

                        <div class="form-group">
                            <label>Tipo <span class="required-asterisk">*</span></label>
                            <select name="desp_rec" id="cat_desp_rec" required style="padding: 10px; border-radius:6px; border:1.5px solid #cbd5e1;">
                                <option value="DESPESA">DESPESA</option>
                                <option value="RECEITA">RECEITA</option>
                            </select>
                        </div>
                    </div>

                    <div style="display: flex; justify-content: flex-end; margin-top: 10px; gap: 8px;">
                        <button type="submit" class="btn-3d btn-success-3d btn-sm-3d"><i class="fa-solid fa-floppy-disk"></i> Salvar Categoria</button>
                    </div>
                </form>

                <div class="table-responsive" style="max-height: 200px; overflow-y: auto;">
                    <table class="custom-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Descrição</th>
                                <th>Tipo</th>
                                <th>Ação</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($todasCategorias as $ct): ?>
                                <tr>
                                    <td>#<?= $ct['ID_CATEGORIA'] ?></td>
                                    <td><?= htmlspecialchars($ct['DESCRICAO']) ?></td>
                                    <td><?= $ct['DESP_REC'] ?></td>
                                    <td>
                                        <button class="btn-3d btn-secondary-3d btn-sm-3d" onclick='editCategoria(<?= json_encode($ct) ?>)'>Editar</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

            </div>
        </div>
    </div>

    <!-- MODAL DE EDIÇÃO DE PARCELA -->
    <div class="modal-overlay" id="editarParcelaModal">
        <div class="modal-card">
            <div class="modal-header">
                <h3><i class="fa-solid fa-pen-to-square"></i> Editar Parcela / Título</h3>
                <i class="fa-solid fa-xmark" style="cursor: pointer;" onclick="closeEditarParcelaModal()"></i>
            </div>
            <form method="POST" action="financeiro_contas.php">
                <input type="hidden" name="acao" value="editar_parcela">
                <input type="hidden" name="id_parcela" id="edit_id_parcela">
                
                <div class="modal-body">
                    <div class="form-grid">
                        
                        <div class="form-group full-width">
                            <label>Descrição do Título:</label>
                            <input type="text" id="edit_titulo_descricao" readonly style="background: #f1f5f9;">
                        </div>
    
                        <div class="form-group">
                            <label>Data de Vencimento <span class="required-asterisk">*</span></label>
                            <input type="date" name="data_vencimento" id="edit_data_vencimento" required>
                        </div>
    
                        <div class="form-group">
                            <label>Valor da Parcela (R$) <span class="required-asterisk">*</span></label>
                            <input type="text" name="valor_parcela" id="edit_valor_parcela" required>
                        </div>
    
                        <div class="form-group full-width">
                            <label>Observação / Anotação da Parcela</label>
                            <input type="text" name="observacao_parcela" id="edit_observacao_parcela" placeholder="Ex: Alterado vencimento a pedido do cliente">
                        </div>
                    </div>
    
                    <div class="modal-footer">
                        <button type="button" class="btn-3d btn-danger-3d" onclick="closeEditarParcelaModal()">Cancelar</button>
                        <button type="submit" class="btn-3d btn-success-3d"><i class="fa-solid fa-floppy-disk"></i> Salvar Alterações</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        function openNovoLancamentoModal() { document.getElementById('novoLancamentoModal').classList.add('active'); }
        function closeNovoLancamentoModal() { document.getElementById('novoLancamentoModal').classList.remove('active'); }

        function openBaixarModal(data) {
            document.getElementById('baixa_id_parcela').value = data.ID_PARCELA;
            const valFormated = data.VALOR_PARCELA ? data.VALOR_PARCELA.toString().replace('.', ',') : '0,00';
            document.getElementById('baixa_valor_original').value = valFormated;
            document.getElementById('baixa_valor_baixado').value = valFormated;
            document.getElementById('baixarModal').classList.add('active');
        }
        function closeBaixarModal() { document.getElementById('baixarModal').classList.remove('active'); }

        function openCategoriaModal() { document.getElementById('categoriaModal').classList.add('active'); }
        function closeCategoriaModal() { document.getElementById('categoriaModal').classList.remove('active'); }

        function editCategoria(data) {
            document.getElementById('cat_id_categoria').value = data.ID_CATEGORIA;
            document.getElementById('cat_descricao').value = data.DESCRICAO;
            document.getElementById('cat_desp_rec').value = data.DESP_REC;
        }

        function openEditarParcelaModal(data) {
            document.getElementById('edit_id_parcela').value = data.ID_PARCELA;
            document.getElementById('edit_titulo_descricao').value = data.DESCRICAO + ' (Parcela #' + data.PARCELA + ')';
            document.getElementById('edit_data_vencimento').value = data.DATA_VENCIMENTO;
            document.getElementById('edit_valor_parcela').value = data.VALOR_PARCELA ? data.VALOR_PARCELA.toString().replace('.', ',') : '0,00';
            document.getElementById('edit_observacao_parcela').value = data.OBSERVACAO_BAIXA || '';
            document.getElementById('editarParcelaModal').classList.add('active');
        }

        function closeEditarParcelaModal() {
            document.getElementById('editarParcelaModal').classList.remove('active');
        }
    </script>
</body>
</html>