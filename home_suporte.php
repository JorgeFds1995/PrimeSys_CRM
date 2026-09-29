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
            // Passa a identificação e a flag $isMaster
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