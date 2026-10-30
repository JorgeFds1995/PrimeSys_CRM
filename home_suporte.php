<?php
session_start();

if (!isset($_SESSION['id_usuario']) || (!($_SESSION['atendente'] ?? false) && !($_SESSION['master'] ?? false))) {
    header("Location: index.php?error=" . urlencode("Acesso negado."));
    exit;
}

require_once __DIR__ . '/php/database.php';
require_once __DIR__ . '/php/Sidebar.php';

$nomeUsuario = $_SESSION['nome'] ?? 'Atendente';
$isMaster = $_SESSION['master'] ?? false;

$db = new database();

// 1. Chamados a vencer em até 3 dias (Previsão <= HOJE + 3 dias E sem data de conclusão)
$sqlVencendo = "SELECT ID_CHAMADO, TIPO_CHAMADO, DATA_PREVISAO 
                FROM CHAMADOS 
                WHERE DATA_CONCLUSAO IS NULL 
                  AND DATA_PREVISAO BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)
                ORDER BY DATA_PREVISAO ASC";
$chamadosVencendo = $db->Query($sqlVencendo);

// 2. Chamados sem movimentação nos últimos 2 dias
$sqlParados = "SELECT ID_CHAMADO, TIPO_CHAMADO, ULTIMA_MOV 
               FROM CHAMADOS 
               WHERE DATA_CONCLUSAO IS NULL 
                 AND ULTIMA_MOV <= DATE_SUB(CURDATE(), INTERVAL 2 DAY)
               ORDER BY ULTIMA_MOV ASC";
$chamadosParados = $db->Query($sqlParados);

// 3. Top 5 Clientes com mais chamados
$sqlTopClientes = "SELECT 
                       CONCAT(TRIM(CAST(a.ID_CLIENTE AS CHAR)), ' - ', b.RAZAO_SOCIAL) AS CLIENTE, 
                       COUNT(*) AS TOTAL 
                   FROM CHAMADOS a 
                   LEFT JOIN CLIENTES b ON a.ID_CLIENTE = b.ID_CLIENTE
                   GROUP BY a.ID_CLIENTE, b.RAZAO_SOCIAL 
                   ORDER BY TOTAL DESC 
                   LIMIT 5;";
$topClientes = $db->Query($sqlTopClientes);

// 4. Tipos de Chamados nos últimos 30 dias (Gráfico de Pizza)
$sqlTipos = "SELECT TIPO_CHAMADO, COUNT(*) AS TOTAL 
             FROM CHAMADOS 
             WHERE DATA_ABERTURA >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
             GROUP BY TIPO_CHAMADO";
$dadosTipos = $db->Query($sqlTipos);

// Formatação inicial dos tipos para o gráfico (Garante os tipos mesmo que zerados)
$tiposAgrupados = [
    'ATENDIMENTO' => 0,
    'ERRO' => 0,
    'AJUSTE' => 0,
    'PERSONALIZAÇÃO' => 0
];

if (!empty($dadosTipos)) {
    foreach ($dadosTipos as $row) {
        $key = mb_strtoupper($row['TIPO_CHAMADO'], 'UTF-8');
        if (array_key_exists($key, $tiposAgrupados)) {
            $tiposAgrupados[$key] = (int)$row['TOTAL'];
        }
    }
}

// 5. Lógica exclusiva para Master: Parceiros Pendentes de Fechamento e Fechamentos Não Faturados
$parceirosFechamentoPendentes = [];
$fechamentosNaoFaturados = [];

if ($isMaster) {
    // Busca todos os parceiros cadastrados
    $parceiros = $db->Query("SELECT * FROM PARCEIROS") ?: [];
    $anoMesAtual = date('Y-m');

    foreach ($parceiros as $par) {
        $idParceiro = $par['ID_PARCEIRO'];
        $diaIni = (int)date('d', strtotime($par['DATA_INI_PERIODO']));
        $diaFimOriginal = (int)date('d', strtotime($par['DATA_FIM_PERIODO']));
        $consideraUltimoDia = (bool)$par['CONSIDERA_ULTIMO_DIA_DO_MES'];

        // Define o dia fim considerando o último dia do mês se marcado ou se for fevereiro/meses curtos
        if ($consideraUltimoDia) {
            $diaFim = (int)date('t'); // Último dia do mês atual
        } else {
            $diaFim = $diaFimOriginal;
            // Ajuste para fevereiro ou meses com menos dias se necessário
            $ultimoDiaMesAtual = (int)date('t');
            if ($diaFim > $ultimoDiaMesAtual) {
                $diaFim = $ultimoDiaMesAtual;
            }
        }

        $dataInicioPeriodo = sprintf('%s-%02d', $anoMesAtual, $diaIni);
        $dataFimPeriodo = sprintf('%s-%02d', $anoMesAtual, $diaFim);

        // Verifica se já existe um fechamento registrado para este parceiro/cliente neste mês/período
        // (Assumindo que ID_CLIENTE na tabela FECHAMENTO corresponda ao ID ou vínculo do parceiro)
        $sqlVerificaFechamento = "SELECT ID_FECHAMENTO FROM FECHAMENTO 
                                  WHERE ID_CLIENTE = $idParceiro 
                                    AND DATA_INICIO = '$dataInicioPeriodo' 
                                    AND DATA_FIM = '$dataFimPeriodo' 
                                  LIMIT 1";
        $jaFechado = $db->Query($sqlVerificaFechamento);

        if (empty($jaFechado)) {
            $parceirosFechamentoPendentes[] = [
                'RAZAO_SOCIAL' => $par['RAZAO_SOCIAL'],
                'PERIODO' => date('d/m/Y', strtotime($dataInicioPeriodo)) . ' até ' . date('d/m/Y', strtotime($dataFimPeriodo))
            ];
        }
    }

    // 6. Fechamentos ainda não faturados
    $sqlNaoFaturados = "SELECT F.ID_FECHAMENTO, F.DATA_INICIO, F.DATA_FIM, C.RAZAO_SOCIAL 
                        FROM FECHAMENTO F
                        INNER JOIN CLIENTES C ON F.ID_CLIENTE = C.ID_CLIENTE
                        WHERE F.FATURADO = 0 OR F.FATURADO IS FALSE
                        ORDER BY F.DATA_INICIO ASC";
    $fechamentosNaoFaturados = $db->Query($sqlNaoFaturados) ?: [];
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Painel de Suporte - PrimeSys</title>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/suporte.css">
    
    <!-- CDN Chart.js para o Gráfico -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>

    <header class="top-header">
        <div class="brand">
            <i class="fa-solid fa-headset"></i> PrimeSys Suporte
        </div>
        <div class="user-info">
            <span>
                <i class="fa-solid fa-user"></i> 
                <strong><?= htmlspecialchars($nomeUsuario) ?></strong> 
                <?= $isMaster ? '<small style="opacity:0.8;">(Master)</small>' : '' ?>
            </span>
            <a href="logout.php" class="btn-logout" title="Sair do sistema">
                <i class="fa-solid fa-right-from-bracket"></i> Sair
            </a>
        </div>
    </header>

    <div class="app-container">
        <?php 
            $sidebar = new Sidebar('home_suporte', $isMaster); 
            $sidebar->render(); 
        ?>

        <main class="main-content">
            <h1 class="page-title">Painel Geral de Atendimento</h1>

            <div class="dashboard-grid">
                
                <!-- Card 1: Próximos de Vencer -->
                <div class="dashboard-card alert-card">
                    <div class="card-header">
                        <h3><i class="fa-solid fa-clock-rotate-left" style="color: #822412;"></i> Vencendo em até 3 Dias</h3>
                        <span class="badge-tag"><?= count($chamadosVencendo) ?></span>
                    </div>
                    <ul class="card-list">
                        <?php if (empty($chamadosVencendo)): ?>
                            <li class="card-list-item">Nenhum chamado próximo do vencimento.</li>
                        <?php else: ?>
                            <?php foreach ($chamadosVencendo as $c): ?>
                                <li class="card-list-item">
                                    <span><strong>#<?= $c['ID_CHAMADO'] ?></strong> - <?= htmlspecialchars($c['TIPO_CHAMADO']) ?></span>
                                    <span style="color: #822412; font-weight: bold;"><?= date('d/m/Y', strtotime($c['DATA_PREVISAO'])) ?></span>
                                </li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </div>

                <!-- Card 2: Sem Movimentação -->
                <div class="dashboard-card alert-card">
                    <div class="card-header">
                        <h3><i class="fa-solid fa-triangle-exclamation" style="color: #822412;"></i> Sem Movimentação (+2 dias)</h3>
                        <span class="badge-tag"><?= count($chamadosParados) ?></span>
                    </div>
                    <ul class="card-list">
                        <?php if (empty($chamadosParados)): ?>
                            <li class="card-list-item">Nenhum chamado estagnado.</li>
                        <?php else: ?>
                            <?php foreach ($chamadosParados as $c): ?>
                                <li class="card-list-item">
                                    <span><strong>#<?= $c['ID_CHAMADO'] ?></strong> - <?= htmlspecialchars($c['TIPO_CHAMADO']) ?></span>
                                    <span>Última: <?= date('d/m/Y', strtotime($c['ULTIMA_MOV'])) ?></span>
                                </li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </div>

                <!-- Card 3: Top 5 Clientes -->
                <div class="dashboard-card">
                    <div class="card-header">
                        <h3><i class="fa-solid fa-ranking-star" style="color: #001517;"></i> Top 5 Clientes</h3>
                    </div>
                    <ul class="card-list">
                        <?php if (empty($topClientes)): ?>
                            <li class="card-list-item">Nenhum registro encontrado.</li>
                        <?php else: ?>
                            <?php foreach ($topClientes as $index => $tc): ?>
                                <li class="card-list-item">
                                    <span><strong><?= $index + 1 ?>º</strong> #<?= $tc['CLIENTE'] ?></span>
                                    <span class="badge-tag"><?= $tc['TOTAL'] ?> chamados</span>
                                </li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </div>

                <!-- Card 4: Gráfico de Pizza -->
                <div class="dashboard-card">
                    <div class="card-header">
                        <h3><i class="fa-solid fa-chart-pie" style="color: #001517;"></i> Chamados (Últimos 30 dias)</h3>
                    </div>
                    <div class="chart-container">
                        <canvas id="tiposChart"></canvas>
                    </div>
                </div>

                <?php if ($isMaster): ?>
                    <!-- Card 5 (Master): Parceiros com Fechamento Pendente no Mês -->
                    <div class="dashboard-card alert-card">
                        <div class="card-header">
                            <h3><i class="fa-solid fa-file-invoice-dollar" style="color: #822412;"></i> Parceiros Pendentes (Fechamento)</h3>
                            <span class="badge-tag"><?= count($parceirosFechamentoPendentes) ?></span>
                        </div>
                        <ul class="card-list">
                            <?php if (empty($parceirosFechamentoPendentes)): ?>
                                <li class="card-list-item">Todos os fechamentos do período estão em dia.</li>
                            <?php else: ?>
                                <?php foreach ($parceirosFechamentoPendentes as $p): ?>
                                    <li class="card-list-item">
                                        <span><strong><?= htmlspecialchars($p['RAZAO_SOCIAL']) ?></strong></span>
                                        <span style="font-size: 11px; color: #64748b;"><?= $p['PERIODO'] ?></span>
                                    </li>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </ul>
                    </div>

                    <!-- Card 6 (Master): Fechamentos Não Faturados -->
                    <div class="dashboard-card alert-card">
                        <div class="card-header">
                            <h3><i class="fa-solid fa-triangle-exclamation" style="color: #d97706;"></i> Fechamentos Não Faturados</h3>
                            <span class="badge-tag"><?= count($fechamentosNaoFaturados) ?></span>
                        </div>
                        <ul class="card-list">
                            <?php if (empty($fechamentosNaoFaturados)): ?>
                                <li class="card-list-item">Nenhum fechamento pendente de faturamento.</li>
                            <?php else: ?>
                                <?php foreach ($fechamentosNaoFaturados as $f): ?>
                                    <li class="card-list-item">
                                        <span><strong>#<?= $f['ID_FECHAMENTO'] ?></strong> - <?= htmlspecialchars($f['RAZAO_SOCIAL']) ?></span>
                                        <span style="font-size: 11px; color: #64748b;"><?= date('d/m/Y', strtotime($f['DATA_INICIO'])) ?> a <?= date('d/m/Y', strtotime($f['DATA_FIM'])) ?></span>
                                    </li>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </ul>
                    </div>
                <?php endif; ?>

            </div>
        </main>
    </div>

    <script>
        // Inicialização do Gráfico de Pizza (Chart.js)
        const ctx = document.getElementById('tiposChart').getContext('2d');
        new Chart(ctx, {
            type: 'pie',
            data: {
                labels: ['Atendimento', 'Erro', 'Ajuste', 'Personalização'],
                datasets: [{
                    data: [
                        <?= $tiposAgrupados['ATENDIMENTO'] ?>,
                        <?= $tiposAgrupados['ERRO'] ?>,
                        <?= $tiposAgrupados['AJUSTE'] ?>,
                        <?= $tiposAgrupados['PERSONALIZAÇÃO'] ?>
                    ],
                    backgroundColor: ['#001517', '#822412', '#d97706', '#2563eb']
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });
    </script>
</body>
</html>