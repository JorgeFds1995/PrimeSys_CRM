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

// --- FILTROS DE PESQUISA DO DEMONSTRATIVO ---
$dataIni = $_GET['data_ini'] ?? date('Y-m-01');
$dataFim = $_GET['data_fim'] ?? date('Y-m-t');
$regime = $_GET['regime'] ?? 'C'; // C = Caixa (Data de Baixa) | E = Emissão (Data de Emissão)

// Define a coluna de busca dinamicamente
$colunaData = ($regime === 'E') ? 'M.DATA_EMISSAO' : 'P.DATA_BAIXA';
$whereCond = "($colunaData BETWEEN '$dataIni' AND '$dataFim')";

if ($regime === 'C') {
    $whereCond .= " AND P.DATA_BAIXA IS NOT NULL";
}

// 1. TOTAL DE RECEITAS POR CATEGORIA
$sqlRecCat = "SELECT 
                COALESCE(CAT.DESCRICAO, 'Sem Categoria') AS CATEGORIA,
                SUM(" . ($regime === 'C' ? "P.VALOR_BAIXADO" : "P.VALOR_PARCELA") . ") AS TOTAL
              FROM MOVIMENTO_FINANCEIRO_PARCELAS P
              INNER JOIN MOVIMENTO_FINANCEIRO M ON P.ID_FIN_MOV = M.ID_FIN_MOV
              LEFT JOIN CATEGORIAS CAT ON M.ID_CATEGORIA = CAT.ID_CATEGORIA
              WHERE UPPER(M.PAGREC) = 'R' AND $whereCond
              GROUP BY CAT.DESCRICAO
              ORDER BY TOTAL DESC";

$receitasCategorias = $db->Query($sqlRecCat);
$totalReceitas = 0;
if (!empty($receitasCategorias)) {
    foreach ($receitasCategorias as $rc) {
        $totalReceitas += (float)$rc['TOTAL'];
    }
}

// 2. TOTAL DE DESPESAS POR CATEGORIA
$sqlDespCat = "SELECT 
                COALESCE(CAT.DESCRICAO, 'Sem Categoria') AS CATEGORIA,
                SUM(" . ($regime === 'C' ? "P.VALOR_BAIXADO" : "P.VALOR_PARCELA") . ") AS TOTAL
              FROM MOVIMENTO_FINANCEIRO_PARCELAS P
              INNER JOIN MOVIMENTO_FINANCEIRO M ON P.ID_FIN_MOV = M.ID_FIN_MOV
              LEFT JOIN CATEGORIAS CAT ON M.ID_CATEGORIA = CAT.ID_CATEGORIA
              WHERE UPPER(M.PAGREC) = 'P' AND $whereCond
              GROUP BY CAT.DESCRICAO
              ORDER BY TOTAL DESC";

$despesasCategorias = $db->Query($sqlDespCat);
$totalDespesas = 0;
if (!empty($despesasCategorias)) {
    foreach ($despesasCategorias as $dc) {
        $totalDespesas += (float)$dc['TOTAL'];
    }
}

// 3. APURAÇÃO DOS ENCARGOS (JUROS E DESCONTOS NO PERÍODO)
$sqlEncargos = "SELECT 
                    COALESCE(SUM(P.VALOR_DESCONTOS), 0) AS TOTAL_DESCONTOS,
                    COALESCE(SUM(P.VALOR_JUROS), 0) AS TOTAL_JUROS
                FROM MOVIMENTO_FINANCEIRO_PARCELAS P
                INNER JOIN MOVIMENTO_FINANCEIRO M ON P.ID_FIN_MOV = M.ID_FIN_MOV
                WHERE $whereCond";

$resEncargos = $db->Query($sqlEncargos);
$totalDescontos = (float)($resEncargos[0]['TOTAL_DESCONTOS'] ?? 0);
$totalJuros = (float)($resEncargos[0]['TOTAL_JUROS'] ?? 0);

// CÁLCULOS DOS LUCROS
$lucroBruto = $totalReceitas - $totalDespesas;
$lucroLiquido = $lucroBruto - $totalDescontos + $totalJuros;

// 4. SALDO TOTAL ATUAL DOS CAIXAS/BANCOS (ATÉ A DATA FIM)
$sqlBancos = "SELECT ID_BANCO, SALDO_INICIAL FROM BANCOS WHERE (INATIVO IS NULL OR INATIVO = 0) AND (DESCONSIDERAR_NO_BALANCO IS NULL OR DESCONSIDERAR_NO_BALANCO = 0)";
$bancos = $db->Query($sqlBancos);

$totalSaldoBancos = 0;
if (!empty($bancos)) {
    foreach ($bancos as $b) {
        $idB = (int)$b['ID_BANCO'];
        $saldoIni = (float)($b['SALDO_INICIAL'] ?? 0);

        $resEntTransf = $db->Query("SELECT COALESCE(SUM(VALOR_TRANSFERENCIA), 0) AS TOTAL FROM TRANSFERENCIAS_BANCARIAS WHERE ID_BANCO_DESTINO = $idB AND DATA_TRANSFERENCIA <= '$dataFim'");
        $resSaiTransf = $db->Query("SELECT COALESCE(SUM(VALOR_TRANSFERENCIA), 0) AS TOTAL FROM TRANSFERENCIAS_BANCARIAS WHERE ID_BANCO_ORIGEM = $idB AND DATA_TRANSFERENCIA <= '$dataFim'");
        $resEntBaixas = $db->Query("SELECT COALESCE(SUM(P.VALOR_BAIXADO), 0) AS TOTAL FROM MOVIMENTO_FINANCEIRO_PARCELAS P INNER JOIN MOVIMENTO_FINANCEIRO M ON P.ID_FIN_MOV = M.ID_FIN_MOV WHERE P.ID_BANCO = $idB AND P.DATA_BAIXA IS NOT NULL AND P.DATA_BAIXA <= '$dataFim' AND UPPER(M.PAGREC) = 'R'");
        $resSaiBaixas = $db->Query("SELECT COALESCE(SUM(P.VALOR_BAIXADO), 0) AS TOTAL FROM MOVIMENTO_FINANCEIRO_PARCELAS P INNER JOIN MOVIMENTO_FINANCEIRO M ON P.ID_FIN_MOV = M.ID_FIN_MOV WHERE P.ID_BANCO = $idB AND P.DATA_BAIXA IS NOT NULL AND P.DATA_BAIXA <= '$dataFim' AND UPPER(M.PAGREC) = 'P'");

        $saldoCaixa = $saldoIni + (float)$resEntTransf[0]['TOTAL'] - (float)$resSaiTransf[0]['TOTAL'] + (float)$resEntBaixas[0]['TOTAL'] - (float)$resSaiBaixas[0]['TOTAL'];
        $totalSaldoBancos += round($saldoCaixa, 2);
    }
}

// POSIÇÃO GERAL DA EMPRESA (Saldo Caixas + Lucro Líquido Apurado)
$posicaoGeralEmpresa = $totalSaldoBancos + $lucroLiquido;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Demonstrativo de Resultado (DRE) - PrimeSys</title>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/suporte.css">
    <link rel="stylesheet" href="css/financeiro.css">

    <style>
        .dre-container {
            background: #fff;
            padding: 25px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            margin-bottom: 25px;
        }
        .dre-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 15px;
            border-bottom: 1px solid #f1f5f9;
            font-size: 14px;
        }
        .dre-row.sub-item {
            padding-left: 35px;
            background: #fafafa;
            font-size: 13px;
        }
        .dre-row.highlight {
            background: #f8fafc;
            font-weight: bold;
            font-size: 15px;
            border-top: 1.5px solid #cbd5e1;
            border-bottom: 1.5px solid #cbd5e1;
        }
        .dre-row.total-final {
            background: #001517;
            color: #fff;
            font-weight: bold;
            font-size: 16px;
            border-radius: 6px;
            margin-top: 15px;
        }
        @media print {
            .top-header, .sidebar, .filters-card, .btn-print-hide { display: none !important; }
            .main-content { margin: 0 !important; width: 100% !important; padding: 0 !important; }
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
            $sidebar = new Sidebar('resultado_dre', $isMaster); 
            $sidebar->render(); 
        ?>

        <main class="main-content">
            <div class="btn-print-hide" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                <h1 class="page-title" style="margin-bottom: 0;">Demonstrativo do Resultado (DRE)</h1>
                <button onclick="window.print()" class="btn-3d btn-primary-3d">
                    <i class="fa-solid fa-print"></i> Imprimir DRE
                </button>
            </div>

            <!-- FILTRO DO PERÍODO E REGIME -->
            <div class="filters-card btn-print-hide">
                <form method="GET" action="financeiro_resultado.php" class="filters-grid">
                    <div class="form-group">
                        <label>Regime de Apuração:</label>
                        <select name="regime" onchange="this.form.submit()">
                            <option value="C" <?= $regime === 'C' ? 'selected' : '' ?>>Regime de Caixa (Baixados)</option>
                            <option value="E" <?= $regime === 'E' ? 'selected' : '' ?>>Regime de Competência (Emissão)</option>
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
                        <button type="submit" class="btn-3d btn-secondary-3d"><i class="fa-solid fa-magnifying-glass"></i> Calcular Resultado</button>
                    </div>
                </form>
            </div>

            <!-- ESTRUTURA DA DRE CONSOLIDADA -->
            <div class="dre-container">
                <div style="text-align: center; margin-bottom: 20px; border-bottom: 2px solid #001517; padding-bottom: 10px;">
                    <h2 style="color: #001517;">Demonstrativo de Resultado do Exercício</h2>
                    <p style="color: #666; font-size: 13px;">
                        Período de <strong><?= date('d/m/Y', strtotime($dataIni)) ?></strong> até <strong><?= date('d/m/Y', strtotime($dataFim)) ?></strong> 
                        (<?= $regime === 'C' ? 'Regime de Caixa' : 'Regime de Competência' ?>)
                    </p>
                </div>

                <!-- 1. RECEITAS -->
                <div class="dre-row highlight">
                    <span><i class="fa-solid fa-circle-plus" style="color: #16a34a;"></i> (+) TOTAL DE RECEITAS</span>
                    <span class="valor-entrada">R$ <?= number_format($totalReceitas, 2, ',', '.') ?></span>
                </div>
                <?php if (!empty($receitasCategorias)): ?>
                    <?php foreach ($receitasCategorias as $rc): ?>
                        <div class="dre-row sub-item">
                            <span><?= htmlspecialchars($rc['CATEGORIA']) ?></span>
                            <span>R$ <?= number_format($rc['TOTAL'], 2, ',', '.') ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <!-- 2. DESPESAS -->
                <div class="dre-row highlight" style="margin-top: 10px;">
                    <span><i class="fa-solid fa-circle-minus" style="color: #dc2626;"></i> (-) TOTAL DE DESPESAS</span>
                    <span class="valor-saida">R$ <?= number_format($totalDespesas, 2, ',', '.') ?></span>
                </div>
                <?php if (!empty($despesasCategorias)): ?>
                    <?php foreach ($despesasCategorias as $dc): ?>
                        <div class="dre-row sub-item">
                            <span><?= htmlspecialchars($dc['CATEGORIA']) ?></span>
                            <span>R$ <?= number_format($dc['TOTAL'], 2, ',', '.') ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <!-- 3. LUCRO BRUTO -->
                <div class="dre-row highlight" style="margin-top: 15px; background: #e2e8f0;">
                    <span>(=) LUCRO BRUTO OPERACIONAL</span>
                    <span class="<?= $lucroBruto >= 0 ? 'saldo-positivo' : 'saldo-negativo' ?>">
                        R$ <?= number_format($lucroBruto, 2, ',', '.') ?>
                    </span>
                </div>

                <!-- 4. ENCARGOS FINANCEIROS -->
                <div class="dre-row sub-item" style="margin-top: 10px;">
                    <span>(-) Total de Descontos Concedidos / Ajustes</span>
                    <span>R$ <?= number_format($totalDescontos, 2, ',', '.') ?></span>
                </div>
                <div class="dre-row sub-item">
                    <span>(+) Total de Juros e Multas Recebidas</span>
                    <span>R$ <?= number_format($totalJuros, 2, ',', '.') ?></span>
                </div>

                <!-- 5. LUCRO LÍQUIDO -->
                <div class="dre-row highlight" style="margin-top: 10px; background: #cbd5e1;">
                    <span>(=) LUCRO LÍQUIDO DO PERÍODO</span>
                    <span class="<?= $lucroLiquido >= 0 ? 'saldo-positivo' : 'saldo-negativo' ?>">
                        R$ <?= number_format($lucroLiquido, 2, ',', '.') ?>
                    </span>
                </div>

                <!-- 6. SALDO BANCOS -->
                <div class="dre-row sub-item" style="margin-top: 15px; font-weight: bold;">
                    <span>(+) Saldo Acumulado nos Caixas / Bancos (Até <?= date('d/m/Y', strtotime($dataFim)) ?>)</span>
                    <span class="<?= $totalSaldoBancos >= 0 ? 'saldo-positivo' : 'saldo-negativo' ?>">
                        R$ <?= number_format($totalSaldoBancos, 2, ',', '.') ?>
                    </span>
                </div>

                <!-- 7. POSIÇÃO DA EMPRESA -->
                <div class="dre-row total-final">
                    <span>(=) POSIÇÃO FINANCEIRA GERAL DA EMPRESA</span>
                    <span>R$ <?= number_format($posicaoGeralEmpresa, 2, ',', '.') ?></span>
                </div>
            </div>
        </main>
    </div>

</body>
</html>