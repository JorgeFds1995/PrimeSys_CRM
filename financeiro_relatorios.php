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

$tipoRelatorio = $_GET['tipo_relatorio'] ?? 'caixas';
$dataPosicao = $_GET['data_posicao'] ?? date('Y-m-d');
$dataIni = $_GET['data_ini'] ?? date('Y-m-01');
$dataFim = $_GET['data_fim'] ?? date('Y-m-t');
$campoData = $_GET['campo_data'] ?? 'V'; // V (Vencimento), E (Emissão), B (Baixa)
$tipoMovimentacao = $_GET['tipo_mov'] ?? 'P'; // P (Pagar), R (Receber), T (Todas)

// --- 1. RELATÓRIO DE POSIÇÃO DE CAIXAS E BANCOS ---
$relatorioCaixas = [];
$totalGeralCaixas = 0;

if ($tipoRelatorio === 'caixas') {
    $sqlBancos = "SELECT ID_BANCO, DESCRICAO, SALDO_INICIAL FROM BANCOS WHERE INATIVO IS NULL OR INATIVO = 0 ORDER BY DESCRICAO ASC";
    $bancosAtivos = $db->Query($sqlBancos);

    if (!empty($bancosAtivos)) {
        foreach ($bancosAtivos as $b) {
            $idB = (int)$b['ID_BANCO'];
            $saldoIni = (float)($b['SALDO_INICIAL'] ?? 0);

            // Soma movimentações até a data informada pelo usuário
            $sqlMov = "SELECT COALESCE(SUM(VALOR_MOV), 0) AS TOTAL FROM (
                        SELECT VALOR_TRANSFERENCIA AS VALOR_MOV FROM TRANSFERENCIAS_BANCARIAS WHERE ID_BANCO_DESTINO = $idB AND DATA_TRANSFERENCIA <= '$dataPosicao'
                        UNION ALL
                        SELECT (VALOR_TRANSFERENCIA * -1) AS VALOR_MOV FROM TRANSFERENCIAS_BANCARIAS WHERE ID_BANCO_ORIGEM = $idB AND DATA_TRANSFERENCIA <= '$dataPosicao'
                        UNION ALL
                        SELECT CASE WHEN UPPER(M.PAGREC) = 'R' THEN P.VALOR_BAIXADO ELSE (P.VALOR_BAIXADO * -1) END AS VALOR_MOV
                        FROM MOVIMENTO_FINANCEIRO_PARCELAS P
                        INNER JOIN MOVIMENTO_FINANCEIRO M ON P.ID_FIN_MOV = M.ID_FIN_MOV
                        WHERE P.ID_BANCO = $idB AND P.DATA_BAIXA IS NOT NULL AND P.DATA_BAIXA <= '$dataPosicao'
                       ) AS MOV";

            $resMov = $db->Query($sqlMov);
            $saldoAtual = round($saldoIni + (float)($resMov[0]['TOTAL'] ?? 0), 2);
            $totalGeralCaixas += $saldoAtual;

            $relatorioCaixas[] = [
                'id' => $b['ID_BANCO'],
                'descricao' => $b['DESCRICAO'],
                'saldo' => $saldoAtual
            ];
        }
    }
}

// --- 2. RELATÓRIO DETALHADO DE PARCELAS / TÍTULOS ---
$relatorioDetalhado = [];
$totalValorParcelas = 0;
$totalValorBaixado = 0;
$totalDescontos = 0;
$totalJuros = 0;

if ($tipoRelatorio === 'detalhado') {
    $where = ["1=1"];

    if ($tipoMovimentacao !== 'T') {
        $where[] = "UPPER(M.PAGREC) = '$tipoMovimentacao'";
    }

    $colunaData = 'P.DATA_VENCIMENTO';
    if ($campoData === 'E') { $colunaData = 'M.DATA_EMISSAO'; }
    elseif ($campoData === 'B') { $colunaData = 'P.DATA_BAIXA'; $where[] = "P.DATA_BAIXA IS NOT NULL"; }

    $where[] = "($colunaData BETWEEN '$dataIni' AND '$dataFim')";
    $sqlWhere = " WHERE " . implode(" AND ", $where);

    $sqlDet = "SELECT P.*, M.DESCRICAO, M.PAGREC, M.DATA_EMISSAO, 
                      CAT.DESCRICAO AS CATEGORIA, 
                      B.DESCRICAO AS BANCO, 
                      CLI.RAZAO_SOCIAL AS CLIENTE
               FROM MOVIMENTO_FINANCEIRO_PARCELAS P
               INNER JOIN MOVIMENTO_FINANCEIRO M ON P.ID_FIN_MOV = M.ID_FIN_MOV
               LEFT JOIN CATEGORIAS CAT ON M.ID_CATEGORIA = CAT.ID_CATEGORIA
               LEFT JOIN BANCOS B ON P.ID_BANCO = B.ID_BANCO
               LEFT JOIN CLIENTES CLI ON M.ID_CLIENTE = CLI.ID_CLIENTE
               $sqlWhere
               ORDER BY $colunaData ASC";
    
    $relatorioDetalhado = $db->Query($sqlDet);

    if (!empty($relatorioDetalhado)) {
        foreach ($relatorioDetalhado as $rd) {
            $totalValorParcelas += (float)$rd['VALOR_PARCELA'];
            $totalValorBaixado += (float)($rd['VALOR_BAIXADO'] ?? 0);
            $totalDescontos += (float)($rd['VALOR_DESCONTOS'] ?? 0);
            $totalJuros += (float)($rd['VALOR_JUROS'] ?? 0);
        }
    }
}

// --- 3. RELATÓRIO CONSOLIDADO POR CATEGORIA (COM SUBTOTAL) ---
$relatorioCategorias = [];
$subtotaisCategorias = [];

if ($tipoRelatorio === 'categorias') {
    $whereCat = ["1=1"];
    if ($tipoMovimentacao !== 'T') {
        $whereCat[] = "UPPER(M.PAGREC) = '$tipoMovimentacao'";
    }
    
    $colunaData = ($campoData === 'E') ? 'M.DATA_EMISSAO' : (($campoData === 'B') ? 'P.DATA_BAIXA' : 'P.DATA_VENCIMENTO');
    if ($campoData === 'B') { $whereCat[] = "P.DATA_BAIXA IS NOT NULL"; }
    $whereCat[] = "($colunaData BETWEEN '$dataIni' AND '$dataFim')";

    $sqlCat = "SELECT COALESCE(CAT.DESCRICAO, 'Sem Categoria') AS CATEGORIA, 
                      M.PAGREC,
                      COUNT(P.ID_PARCELA) AS QTD_PARCELAS,
                      SUM(P.VALOR_PARCELA) AS TOTAL_PREVISTO,
                      SUM(COALESCE(P.VALOR_BAIXADO, 0)) AS TOTAL_BAIXADO
               FROM MOVIMENTO_FINANCEIRO_PARCELAS P
               INNER JOIN MOVIMENTO_FINANCEIRO M ON P.ID_FIN_MOV = M.ID_FIN_MOV
               LEFT JOIN CATEGORIAS CAT ON M.ID_CATEGORIA = CAT.ID_CATEGORIA
               WHERE " . implode(" AND ", $whereCat) . "
               GROUP BY CAT.DESCRICAO, M.PAGREC
               ORDER BY CAT.DESCRICAO ASC";

    $relatorioCategorias = $db->Query($sqlCat);
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Relatórios Financeiros - PrimeSys</title>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/suporte.css">
    <link rel="stylesheet" href="css/financeiro.css">

    <style>
        .report-header-print {
            display: none;
            text-align: center;
            border-bottom: 2px solid #001517;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }

        /* Estilização para Impressão e PDF Nativo */
        @media print {
            body { background: #fff !important; font-size: 11pt; }
            .top-header, .sidebar, .filters-card, .btn-print-hide { display: none !important; }
            .main-content { margin: 0 !important; width: 100% !important; padding: 0 !important; }
            .report-header-print { display: block !important; }
            .custom-table { width: 100% !important; border: 1px solid #ccc; }
            .custom-table th, .custom-table td { padding: 6px 8px !important; font-size: 10pt; }
        }
    </style>
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
            $sidebar = new Sidebar('relatorios_fin', $isMaster); 
            $sidebar->render(); 
        ?>

        <main class="main-content">
            <div class="btn-print-hide" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                <h1 class="page-title" style="margin-bottom: 0;">Central de Relatórios Financeiros</h1>
                <button onclick="window.print()" class="btn-3d btn-primary-3d">
                    <i class="fa-solid fa-print"></i> Imprimir / Salvar PDF
                </button>
            </div>

            <!-- FILTROS DOS RELATÓRIOS -->
            <div class="filters-card btn-print-hide">
                <form method="GET" action="financeiro_relatorios.php" class="filters-grid">
                    <div class="form-group">
                        <label>Tipo de Relatório:</label>
                        <select name="tipo_relatorio" onchange="this.form.submit()">
                            <option value="caixas" <?= $tipoRelatorio === 'caixas' ? 'selected' : '' ?>>Posição de Saldos dos Caixas</option>
                            <option value="detalhado" <?= $tipoRelatorio === 'detalhado' ? 'selected' : '' ?>>Extrato Detalhado de Títulos</option>
                            <option value="categorias" <?= $tipoRelatorio === 'categorias' ? 'selected' : '' ?>>Consolidado por Categoria</option>
                        </select>
                    </div>

                    <?php if ($tipoRelatorio === 'caixas'): ?>
                        <div class="form-group">
                            <label>Data de Posição do Saldo:</label>
                            <input type="date" name="data_posicao" value="<?= $dataPosicao ?>">
                        </div>
                    <?php else: ?>
                        <div class="form-group">
                            <label>Tipo de Lançamento:</label>
                            <select name="tipo_mov">
                                <option value="P" <?= $tipoMovimentacao === 'P' ? 'selected' : '' ?>>Despesas (A Pagar)</option>
                                <option value="R" <?= $tipoMovimentacao === 'R' ? 'selected' : '' ?>>Receitas (A Receber)</option>
                                <option value="T" <?= $tipoMovimentacao === 'T' ? 'selected' : '' ?>>Ambos (Despesas e Receitas)</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Filtrar Data Por:</label>
                            <select name="campo_data">
                                <option value="V" <?= $campoData === 'V' ? 'selected' : '' ?>>Vencimento</option>
                                <option value="E" <?= $campoData === 'E' ? 'selected' : '' ?>>Emissão</option>
                                <option value="B" <?= $campoData === 'B' ? 'selected' : '' ?>>Baixa</option>
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
                    <?php endif; ?>

                    <div class="filters-actions">
                        <button type="submit" class="btn-3d btn-secondary-3d"><i class="fa-solid fa-magnifying-glass"></i> Gerar</button>
                    </div>
                </form>
            </div>

            <!-- CABEÇALHO PARA IMPRESSÃO -->
            <div class="report-header-print">
                <h2>PrimeSys CRM - Relatório Gerencial Financeiro</h2>
                <p>
                    <?= $tipoRelatorio === 'caixas' ? "Posição de Saldos em " . date('d/m/Y', strtotime($dataPosicao)) : "Período: " . date('d/m/Y', strtotime($dataIni)) . " até " . date('d/m/Y', strtotime($dataFim)) ?>
                </p>
            </div>

            <!-- 1. EXIBIÇÃO: POSIÇÃO DOS CAIXAS -->
            <?php if ($tipoRelatorio === 'caixas'): ?>
                <div class="table-responsive">
                    <table class="custom-table">
                        <thead>
                            <tr>
                                <th>#ID</th>
                                <th>Descrição do Caixa / Banco</th>
                                <th style="text-align: right;">Saldo na Data (R$)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($relatorioCaixas)): ?>
                                <tr><td colspan="3" style="text-align: center;">Nenhum caixa ativo encontrado.</td></tr>
                            <?php else: ?>
                                <?php foreach ($relatorioCaixas as $c): ?>
                                    <tr>
                                        <td>#<?= $c['id'] ?></td>
                                        <td><strong><?= htmlspecialchars($c['descricao']) ?></strong></td>
                                        <td style="text-align: right;" class="<?= $c['saldo'] >= 0 ? 'saldo-positivo' : 'saldo-negativo' ?>">
                                            R$ <?= number_format($c['saldo'], 2, ',', '.') ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                        <tfoot>
                            <tr style="background: #f1f5f9; font-weight: bold;">
                                <td colspan="2" style="text-align: right;">TOTAL GERAL EM CAIXAS:</td>
                                <td style="text-align: right;" class="<?= $totalGeralCaixas >= 0 ? 'saldo-positivo' : 'saldo-negativo' ?>">
                                    R$ <?= number_format($totalGeralCaixas, 2, ',', '.') ?>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

            <!-- 2. EXIBIÇÃO: DETALHADO DE PARCELAS / TÍTULOS -->
            <?php elseif ($tipoRelatorio === 'detalhado'): ?>
                <div class="table-responsive">
                    <table class="custom-table" style="font-size: 12px;">
                        <thead>
                            <tr>
                                <th>Tipo</th>
                                <th>Vencimento</th>
                                <th>Emissão</th>
                                <th>Descrição / Favorecido</th>
                                <th>Categoria</th>
                                <th>Valor Parc.</th>
                                <th>Data Baixa</th>
                                <th>Banco Baixa</th>
                                <th>Desc.</th>
                                <th>Juros</th>
                                <th>Valor Baixado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($relatorioDetalhado)): ?>
                                <tr><td colspan="11" style="text-align: center;">Nenhum lançamento encontrado no período.</td></tr>
                            <?php else: ?>
                                <?php foreach ($relatorioDetalhado as $rd): ?>
                                    <tr>
                                        <td>
                                            <span class="badge-tag <?= strtoupper($rd['PAGREC']) === 'P' ? 'badge-pagar' : 'badge-receber' ?>">
                                                <?= strtoupper($rd['PAGREC']) === 'P' ? 'PAGAR' : 'RECEBER' ?>
                                            </span>
                                        </td>
                                        <td><?= date('d/m/Y', strtotime($rd['DATA_VENCIMENTO'])) ?></td>
                                        <td><?= date('d/m/Y', strtotime($rd['DATA_EMISSAO'])) ?></td>
                                        <td>
                                            <strong><?= htmlspecialchars($rd['DESCRICAO']) ?></strong> (Parc. #<?= $rd['PARCELA'] ?>)
                                            <?= !empty($rd['CLIENTE']) ? '<br><small>' . htmlspecialchars($rd['CLIENTE']) . '</small>' : '' ?>
                                        </td>
                                        <td><?= htmlspecialchars($rd['CATEGORIA'] ?? 'Sem Categoria') ?></td>
                                        <td>R$ <?= number_format($rd['VALOR_PARCELA'], 2, ',', '.') ?></td>
                                        <td><?= !empty($rd['DATA_BAIXA']) ? date('d/m/Y', strtotime($rd['DATA_BAIXA'])) : '-' ?></td>
                                        <td><?= htmlspecialchars($rd['BANCO'] ?? '-') ?></td>
                                        <td>R$ <?= number_format((float)($rd['VALOR_DESCONTOS'] ?? 0), 2, ',', '.') ?></td>
                                        <td>R$ <?= number_format((float)($rd['VALOR_JUROS'] ?? 0), 2, ',', '.') ?></td>
                                        <td class="valor-entrada">
                                            R$ <?= number_format((float)($rd['VALOR_BAIXADO'] ?? 0), 2, ',', '.') ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                        <tfoot>
                            <tr style="background: #f1f5f9; font-weight: bold;">
                                <td colspan="5" style="text-align: right;">TOTAIS:</td>
                                <td>R$ <?= number_format($totalValorParcelas, 2, ',', '.') ?></td>
                                <td colspan="2"></td>
                                <td>R$ <?= number_format($totalDescntos, 2, ',', '.') ?></td>
                                <td>R$ <?= number_format($totalJuros, 2, ',', '.') ?></td>
                                <td class="valor-entrada">R$ <?= number_format($totalValorBaixado, 2, ',', '.') ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

            <!-- 3. EXIBIÇÃO: CONSOLIDADO POR CATEGORIAS -->
            <?php elseif ($tipoRelatorio === 'categorias'): ?>
                <div class="table-responsive">
                    <table class="custom-table">
                        <thead>
                            <tr>
                                <th>Categoria</th>
                                <th>Tipo</th>
                                <th style="text-align: center;">Qtd. Títulos</th>
                                <th style="text-align: right;">Total Previsto (R$)</th>
                                <th style="text-align: right;">Total Baixado (R$)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($relatorioCategorias)): ?>
                                <tr><td colspan="5" style="text-align: center;">Nenhum dado por categoria no período.</td></tr>
                            <?php else: ?>
                                <?php 
                                    $subPrevisto = 0;
                                    $subBaixado = 0;
                                    foreach ($relatorioCategorias as $rc): 
                                        $subPrevisto += (float)$rc['TOTAL_PREVISTO'];
                                        $subBaixado += (float)$rc['TOTAL_BAIXADO'];
                                ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($rc['CATEGORIA']) ?></strong></td>
                                        <td>
                                            <span class="badge-tag <?= strtoupper($rc['PAGREC']) === 'P' ? 'badge-pagar' : 'badge-receber' ?>">
                                                <?= strtoupper($rc['PAGREC']) === 'P' ? 'DESPESA' : 'RECEITA' ?>
                                            </span>
                                        </td>
                                        <td style="text-align: center;"><?= $rc['QTD_PARCELAS'] ?></td>
                                        <td style="text-align: right;">R$ <?= number_format($rc['TOTAL_PREVISTO'], 2, ',', '.') ?></td>
                                        <td style="text-align: right;" class="valor-entrada">R$ <?= number_format($rc['TOTAL_BAIXADO'], 2, ',', '.') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                        <?php if (!empty($relatorioCategorias)): ?>
                            <tfoot>
                                <tr style="background: #f1f5f9; font-weight: bold;">
                                    <td colspan="3" style="text-align: right;">SUBTOTAL GERAL:</td>
                                    <td style="text-align: right;">R$ <?= number_format($subPrevisto, 2, ',', '.') ?></td>
                                    <td style="text-align: right;" class="valor-entrada">R$ <?= number_format($subBaixado, 2, ',', '.') ?></td>
                                </tr>
                            </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
            <?php endif; ?>

        </main>
    </div>

</body>
</html>