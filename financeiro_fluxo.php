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

$dataHoje = date('Y-m-d');
$dataHojeFormatada = date('d/m/Y');

// 1. SALDO ATUAL DOS BANCOS (CONSIDERADOS NO BALANÇO)
$sqlBancos = "SELECT ID_BANCO, DESCRICAO, SALDO_INICIAL FROM BANCOS WHERE (INATIVO IS NULL OR INATIVO = 0) AND (DESCONSIDERAR_NO_BALANCO IS NULL OR DESCONSIDERAR_NO_BALANCO = 0)";
$bancos = $db->Query($sqlBancos);

$totalSaldoBancos = 0;
$listaSaldosBancos = [];

if (!empty($bancos)) {
    foreach ($bancos as $b) {
        $idB = (int)$b['ID_BANCO'];
        $saldoIni = (float)($b['SALDO_INICIAL'] ?? 0);

        // Soma transferências recebidas (+)
        $resEntTransf = $db->Query("SELECT COALESCE(SUM(VALOR_TRANSFERENCIA), 0) AS TOTAL FROM TRANSFERENCIAS_BANCARIAS WHERE ID_BANCO_DESTINO = $idB AND DATA_TRANSFERENCIA <= '$dataHoje'");
        $entTransf = (float)($resEntTransf[0]['TOTAL'] ?? 0);

        // Soma transferências enviadas (-)
        $resSaiTransf = $db->Query("SELECT COALESCE(SUM(VALOR_TRANSFERENCIA), 0) AS TOTAL FROM TRANSFERENCIAS_BANCARIAS WHERE ID_BANCO_ORIGEM = $idB AND DATA_TRANSFERENCIA <= '$dataHoje'");
        $saiTransf = (float)($resSaiTransf[0]['TOTAL'] ?? 0);

        // Soma baixas de receitas (+)
        $resEntBaixas = $db->Query("SELECT COALESCE(SUM(P.VALOR_BAIXADO), 0) AS TOTAL FROM MOVIMENTO_FINANCEIRO_PARCELAS P INNER JOIN MOVIMENTO_FINANCEIRO M ON P.ID_FIN_MOV = M.ID_FIN_MOV WHERE P.ID_BANCO = $idB AND P.DATA_BAIXA IS NOT NULL AND P.DATA_BAIXA <= '$dataHoje' AND UPPER(M.PAGREC) = 'R'");
        $entBaixas = (float)($resEntBaixas[0]['TOTAL'] ?? 0);

        // Soma baixas de despesas (-)
        $resSaiBaixas = $db->Query("SELECT COALESCE(SUM(P.VALOR_BAIXADO), 0) AS TOTAL FROM MOVIMENTO_FINANCEIRO_PARCELAS P INNER JOIN MOVIMENTO_FINANCEIRO M ON P.ID_FIN_MOV = M.ID_FIN_MOV WHERE P.ID_BANCO = $idB AND P.DATA_BAIXA IS NOT NULL AND P.DATA_BAIXA <= '$dataHoje' AND UPPER(M.PAGREC) = 'P'");
        $saiBaixas = (float)($resSaiBaixas[0]['TOTAL'] ?? 0);

        // Cálculo do Saldo Atual do Caixa
        $saldoCaixa = $saldoIni + $entTransf - $saiTransf + $entBaixas - $saiBaixas;
        $saldoCaixa = round($saldoCaixa, 2);

        $totalSaldoBancos += $saldoCaixa;

        $listaSaldosBancos[] = [
            'descricao' => $b['DESCRICAO'],
            'saldo' => $saldoCaixa
        ];
    }
}

// 2. RECEITAS PENDENTES (Vencidas + Vencendo Hoje)
$sqlReceitasP = "SELECT P.*, M.DESCRICAO, CAT.DESCRICAO AS CATEGORIA, C.RAZAO_SOCIAL AS CLIENTE
                 FROM MOVIMENTO_FINANCEIRO_PARCELAS P
                 INNER JOIN MOVIMENTO_FINANCEIRO M ON P.ID_FIN_MOV = M.ID_FIN_MOV
                 LEFT JOIN CATEGORIAS CAT ON M.ID_CATEGORIA = CAT.ID_CATEGORIA
                 LEFT JOIN CLIENTES C ON M.ID_CLIENTE = C.ID_CLIENTE
                 WHERE UPPER(M.PAGREC) = 'R' 
                   AND P.DATA_BAIXA IS NULL 
                   AND P.DATA_VENCIMENTO <= '$dataHoje'
                 ORDER BY P.DATA_VENCIMENTO ASC";
$receitasPendentes = $db->Query($sqlReceitasP);

$totalReceitasPendentes = 0;
$catReceitas = [];
if (!empty($receitasPendentes)) {
    foreach ($receitasPendentes as $r) {
        $val = (float)$r['VALOR_PARCELA'];
        $totalReceitasPendentes += $val;
        $catName = $r['CATEGORIA'] ?? 'Outros';
        $catReceitas[$catName] = ($catReceitas[$catName] ?? 0) + $val;
    }
}

// 3. DESPESAS PENDENTES (Vencidas + Vencendo Hoje)
$sqlDespesasP = "SELECT P.*, M.DESCRICAO, CAT.DESCRICAO AS CATEGORIA, C.RAZAO_SOCIAL AS CLIENTE
                 FROM MOVIMENTO_FINANCEIRO_PARCELAS P
                 INNER JOIN MOVIMENTO_FINANCEIRO M ON P.ID_FIN_MOV = M.ID_FIN_MOV
                 LEFT JOIN CATEGORIAS CAT ON M.ID_CATEGORIA = CAT.ID_CATEGORIA
                 LEFT JOIN CLIENTES C ON M.ID_CLIENTE = C.ID_CLIENTE
                 WHERE UPPER(M.PAGREC) = 'P' 
                   AND P.DATA_BAIXA IS NULL 
                   AND P.DATA_VENCIMENTO <= '$dataHoje'
                 ORDER BY P.DATA_VENCIMENTO ASC";
$despesasPendentes = $db->Query($sqlDespesasP);

$totalDespesasPendentes = 0;
$catDespesas = [];
if (!empty($despesasPendentes)) {
    foreach ($despesasPendentes as $d) {
        $val = (float)$d['VALOR_PARCELA'];
        $totalDespesasPendentes += $val;
        $catName = $d['CATEGORIA'] ?? 'Outros';
        $catDespesas[$catName] = ($catDespesas[$catName] ?? 0) + $val;
    }
}

// SUB-TOTAL OPERACIONAL DO DIA (Entradas a receber - Saídas a pagar)
$subtotalOperacionalDia = $totalReceitasPendentes - $totalDespesasPendentes;

// POSIÇÃO FINANCEIRA PROJETADA FINAL DO DIA
$posicaoProjetadaDia = $totalSaldoBancos + $subtotalOperacionalDia;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fluxo de Caixa - PrimeSys</title>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/suporte.css">
    <link rel="stylesheet" href="css/financeiro.css">
    
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }
        .summary-card {
            background: #fff;
            padding: 18px;
            border-radius: 8px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.05);
            border-left: 5px solid #001517;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .bank-subtotal-list {
            margin-top: 10px;
            padding-top: 8px;
            border-top: 1px dashed #e2e8f0;
            max-height: 100px;
            overflow-y: auto;
            font-size: 11px;
        }
        .bank-subtotal-item {
            display: flex;
            justify-content: space-between;
            color: #475569;
            margin-bottom: 3px;
        }
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
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
            $sidebar = new Sidebar('fluxo_caixa', $isMaster); 
            $sidebar->render(); 
        ?>

        <main class="main-content">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                <h1 class="page-title" style="margin-bottom: 0;">Fluxo de Caixa Posicional</h1>
                <span class="badge-tag" style="font-size: 15px; padding: 8px 15px; background: #001517; color: #fff;">
                    <i class="fa-regular fa-calendar-days"></i> Data Atual: <strong><?= $dataHojeFormatada ?></strong>
                </span>
            </div>

            <!-- CARDS RESUMO DO DIA -->
            <div class="summary-grid">
                <!-- CARD 1: SALDO DOS BANCOS + SUBTOTAIS POR CAIXA -->
                <div class="summary-card" style="border-left-color: #020617;">
                    <div>
                        <span style="font-size: 12px; color: #666; font-weight: bold;">(+) Saldo Total nos Caixas</span>
                        <h2 class="<?= $totalSaldoBancos >= 0 ? 'saldo-positivo' : 'saldo-negativo' ?>" style="margin-top: 5px;">
                            R$ <?= number_format($totalSaldoBancos, 2, ',', '.') ?>
                        </h2>
                    </div>
                    
                    <div class="bank-subtotal-list">
                        <?php if (empty($listaSaldosBancos)): ?>
                            <div class="bank-subtotal-item"><span>Nenhum caixa ativo</span></div>
                        <?php else: ?>
                            <?php foreach ($listaSaldosBancos as $sb): ?>
                                <div class="bank-subtotal-item">
                                    <span><?= htmlspecialchars($sb['descricao']) ?>:</span>
                                    <strong>R$ <?= number_format($sb['saldo'], 2, ',', '.') ?></strong>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- CARD 2: RECEITAS A RECEBER -->
                <div class="summary-card" style="border-left-color: #14532d;">
                    <div>
                        <span style="font-size: 12px; color: #666; font-weight: bold;">(+) Receitas a Receber (Até Hoje)</span>
                        <h2 class="valor-entrada" style="margin-top: 5px;">R$ <?= number_format($totalReceitasPendentes, 2, ',', '.') ?></h2>
                    </div>
                    <small style="font-size: 11px; color: #666; margin-top: 10px; border-top: 1px dashed #e2e8f0; padding-top: 5px;">
                        Total de <?= count($receitasPendentes) ?> título(s) pendente(s)
                    </small>
                </div>

                <!-- CARD 3: DESPESAS A PAGAR -->
                <div class="summary-card" style="border-left-color: #dc2626;">
                    <div>
                        <span style="font-size: 12px; color: #666; font-weight: bold;">(-) Despesas a Pagar (Até Hoje)</span>
                        <h2 class="valor-saida" style="margin-top: 5px;">R$ <?= number_format($totalDespesasPendentes, 2, ',', '.') ?></h2>
                    </div>
                    <small style="font-size: 11px; color: #666; margin-top: 10px; border-top: 1px dashed #e2e8f0; padding-top: 5px;">
                        Total de <?= count($despesasPendentes) ?> título(s) pendente(s)
                    </small>
                </div>

                <!-- CARD 4: POSIÇÃO PROJETADA FINAL DO DIA -->
                <div class="summary-card" style="border-left-color: <?= $posicaoProjetadaDia >= 0 ? '#020617' : '#822412' ?>;">
                    <div>
                        <span style="font-size: 12px; color: #666; font-weight: bold;">(=) Posição Projetada Final</span>
                        <h2 class="<?= $posicaoProjetadaDia >= 0 ? 'saldo-positivo' : 'saldo-negativo' ?>" style="margin-top: 5px;">
                            R$ <?= number_format($posicaoProjetadaDia, 2, ',', '.') ?>
                        </h2>
                    </div>
                    <div style="font-size: 11px; color: #475569; margin-top: 10px; border-top: 1px dashed #e2e8f0; padding-top: 5px; display: flex; justify-content: space-between;">
                        <span>Subtotal Pendências:</span>
                        <strong class="<?= $subtotalOperacionalDia >= 0 ? 'valor-entrada' : 'valor-saida' ?>">
                            R$ <?= number_format($subtotalOperacionalDia, 2, ',', '.') ?>
                        </strong>
                    </div>
                </div>
            </div>

            <!-- GRÁFICOS PIZZA POR CATEGORIA -->
            <div class="charts-grid">
                <div class="dashboard-card">
                    <div class="card-header">
                        <h3><i class="fa-solid fa-chart-pie" style="color: #14532d;"></i> Receitas Pendentes por Categoria</h3>
                    </div>
                    <div class="chart-container">
                        <canvas id="receitasChart"></canvas>
                    </div>
                </div>

                <div class="dashboard-card">
                    <div class="card-header">
                        <h3><i class="fa-solid fa-chart-pie" style="color: #dc2626;"></i> Despesas Pendentes por Categoria</h3>
                    </div>
                    <div class="chart-container">
                        <canvas id="despesasChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- LISTAS DE PENDÊNCIAS (RECEITAS E DESPESAS VENCIDAS OU HOJE) -->
            <div class="dashboard-grid" style="grid-template-columns: repeat(auto-fit, minmax(450px, 1fr)); margin-top: 0;">
                
                <!-- Lista Receitas -->
                <div class="dashboard-card" style="border-top-color: #14532d;">
                    <div class="card-header">
                        <h3><i class="fa-solid fa-arrow-down-left" style="color: #14532d;"></i> Receitas Vencidas / Até Hoje</h3>
                        <span class="badge-tag badge-receber"><?= count($receitasPendentes) ?></span>
                    </div>
                    <div class="table-responsive" style="max-height: 300px; overflow-y: auto;">
                        <table class="custom-table" style="font-size: 12px;">
                            <thead>
                                <tr>
                                    <th>Vencimento</th>
                                    <th>Descrição / Cliente</th>
                                    <th>Valor</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($receitasPendentes)): ?>
                                    <tr><td colspan="3" style="text-align: center;">Nenhuma receita pendente até hoje.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($receitasPendentes as $rp): ?>
                                        <tr>
                                            <td style="color: #dc2626; font-weight: bold;"><?= date('d/m/Y', strtotime($rp['DATA_VENCIMENTO'])) ?></td>
                                            <td>
                                                <strong><?= htmlspecialchars($rp['DESCRICAO']) ?></strong>
                                                <?= !empty($rp['CLIENTE']) ? '<br><small>' . htmlspecialchars($rp['CLIENTE']) . '</small>' : '' ?>
                                            </td>
                                            <td class="valor-entrada">R$ <?= number_format($rp['VALOR_PARCELA'], 2, ',', '.') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Lista Despesas -->
                <div class="dashboard-card" style="border-top-color: #dc2626;">
                    <div class="card-header">
                        <h3><i class="fa-solid fa-arrow-up-right-dots" style="color: #dc2626;"></i> Despesas Vencidas / Até Hoje</h3>
                        <span class="badge-tag badge-pagar"><?= count($despesasPendentes) ?></span>
                    </div>
                    <div class="table-responsive" style="max-height: 300px; overflow-y: auto;">
                        <table class="custom-table" style="font-size: 12px;">
                            <thead>
                                <tr>
                                    <th>Vencimento</th>
                                    <th>Descrição / Fornecedor</th>
                                    <th>Valor</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($despesasPendentes)): ?>
                                    <tr><td colspan="3" style="text-align: center;">Nenhuma despesa pendente até hoje.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($despesasPendentes as $dp): ?>
                                        <tr>
                                            <td style="color: #dc2626; font-weight: bold;"><?= date('d/m/Y', strtotime($dp['DATA_VENCIMENTO'])) ?></td>
                                            <td>
                                                <strong><?= htmlspecialchars($dp['DESCRICAO']) ?></strong>
                                                <?= !empty($dp['CLIENTE']) ? '<br><small>' . htmlspecialchars($dp['CLIENTE']) . '</small>' : '' ?>
                                            </td>
                                            <td class="valor-saida">R$ <?= number_format($dp['VALOR_PARCELA'], 2, ',', '.') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
        </main>
    </div>

    <script>
        // Gráfico de Pizza Receitas
        new Chart(document.getElementById('receitasChart').getContext('2d'), {
            type: 'pie',
            data: {
                labels: <?= json_encode(array_keys($catReceitas)) ?>,
                datasets: [{
                    data: <?= json_encode(array_values($catReceitas)) ?>,
                    backgroundColor: ['#14532d', '#16a34a', '#22c55e', '#4ade80', '#86efac']
                }]
            },
            options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
        });

        // Gráfico de Pizza Despesas
        new Chart(document.getElementById('despesasChart').getContext('2d'), {
            type: 'pie',
            data: {
                labels: <?= json_encode(array_keys($catDespesas)) ?>,
                datasets: [{
                    data: <?= json_encode(array_values($catDespesas)) ?>,
                    backgroundColor: ['#822412', '#dc2626', '#ef4444', '#f87171', '#fca5a5']
                }]
            },
            options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
        });
    </script>
</body>
</html>