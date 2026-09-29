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
$nomeUsuario = $_SESSION['nome'] ?? 'Atendente';
$db = new database();

// --- FILTROS DE CONSULTA DO RELATÓRIO ---
$dataIni = $_GET['data_ini'] ?? date('Y-m-01');
$dataFim = $_GET['data_fim'] ?? date('Y-m-t');
$filtroCliente = (int)($_GET['cliente'] ?? 0);
$filtroUsuarioCliente = (int)($_GET['id_usuario_cliente'] ?? 0);
$filtroResponsavel = $_GET['responsavel'] ?? '';

$where = ["(CH.DATA_ABERTURA BETWEEN '$dataIni' AND '$dataFim')"];

if ($filtroCliente > 0) { 
    $where[] = "CH.ID_CLIENTE = $filtroCliente"; 
}

if ($filtroUsuarioCliente > 0) { 
    $where[] = "CH.ID_USUARIO = $filtroUsuarioCliente"; 
}

if ($filtroResponsavel !== '') {
    if ($filtroResponsavel === '0') {
        $where[] = "(CH.ID_RESPONSAVEL IS NULL OR CH.ID_RESPONSAVEL = 0)";
    } else {
        $where[] = "CH.ID_RESPONSAVEL = " . (int)$filtroResponsavel;
    }
}

$sqlWhere = " WHERE " . implode(" AND ", $where);

// Consulta Principal dos Chamados (com LEFT JOIN no Responsável)
$sqlChamados = "SELECT CH.*, 
                       CLI.RAZAO_SOCIAL AS NOME_CLIENTE, 
                       U.NOME AS NOME_USUARIO,
                       RESP.NOME AS NOME_RESPONSAVEL
                FROM CHAMADOS CH
                INNER JOIN CLIENTES CLI ON CH.ID_CLIENTE = CLI.ID_CLIENTE
                INNER JOIN USUARIOS U ON CH.ID_USUARIO = U.ID_USUARIO
                LEFT JOIN USUARIOS RESP ON CH.ID_RESPONSAVEL = RESP.ID_USUARIO
                $sqlWhere
                ORDER BY CH.DATA_ABERTURA ASC, CH.ID_CHAMADO ASC";

$chamados = $db->Query($sqlChamados) ?: [];

// Listas para Filtros
$clientesLista = $db->Query("SELECT ID_CLIENTE, RAZAO_SOCIAL FROM CLIENTES ORDER BY RAZAO_SOCIAL ASC") ?: [];
$responsaveisLista = $db->Query("SELECT ID_USUARIO, NOME FROM USUARIOS WHERE ATENDENTE = 1 ORDER BY NOME ASC") ?: [];

$usuariosFiltroLista = [];
if ($filtroCliente > 0) {
    $usuariosFiltroLista = $db->Query("SELECT ID_USUARIO, NOME FROM USUARIOS WHERE ID_CLIENTE = $filtroCliente ORDER BY NOME ASC") ?: [];
}

// --- ESTATÍSTICAS E SUBTOTALIZADORES ---
$totalChamados = count($chamados);
$subtotaisTipo = [];
$subtotaisEtapa = [];
$subtotaisResponsavel = [];
$noPrazo = 0;
$atrasados = 0;
$emAbertoSemPrevisao = 0;

if (!empty($chamados)) {
    $hoje = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));
    $hoje->setTime(0, 0, 0);

    foreach ($chamados as $ch) {
        // Subtotal por Tipo
        $tipo = $ch['TIPO_CHAMADO'] ?? 'Outros';
        $subtotaisTipo[$tipo] = ($subtotaisTipo[$tipo] ?? 0) + 1;

        // Subtotal por Etapa
        $etapa = $ch['ETAPA_CHAMADO'] ?? 'Outros';
        $subtotaisEtapa[$etapa] = ($subtotaisEtapa[$etapa] ?? 0) + 1;

        // Subtotal por Responsável
        $respNome = !empty($ch['NOME_RESPONSAVEL']) ? $ch['NOME_RESPONSAVEL'] : 'Sem Responsável';
        $subtotaisResponsavel[$respNome] = ($subtotaisResponsavel[$respNome] ?? 0) + 1;

        // Cálculo de Prazos / Atrasos
        if (!empty($ch['DATA_PREVISAO'])) {
            $dtPrevisao = new DateTime($ch['DATA_PREVISAO']);
            $dtPrevisao->setTime(0, 0, 0);

            if (!empty($ch['DATA_CONCLUSAO'])) {
                $dtConclusao = new DateTime($ch['DATA_CONCLUSAO']);
                $dtConclusao->setTime(0, 0, 0);

                if ($dtConclusao <= $dtPrevisao) {
                    $noPrazo++;
                } else {
                    $atrasados++;
                }
            } else {
                // Em andamento
                if ($hoje <= $dtPrevisao) {
                    $noPrazo++;
                } else {
                    $atrasados++;
                }
            }
        } else {
            $emAbertoSemPrevisao++;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Relatório de Chamados - PrimeSys</title>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/suporte.css">
    <style>
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        .summary-card {
            background: #fff;
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.05);
            border-left: 5px solid #001517;
        }
        .report-header-print {
            display: none;
            text-align: center;
            border-bottom: 2px solid #001517;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }
        
        @media print {
            body { background: #fff !important; font-size: 10pt; }
            .top-header, .sidebar, .filters-card, .btn-print-hide { display: none !important; }
            .main-content { margin: 0 !important; width: 100% !important; padding: 0 !important; }
            .report-header-print { display: block !important; }
            .custom-table { width: 100% !important; border: 1px solid #ccc; }
            .custom-table th, .custom-table td { padding: 5px 6px !important; font-size: 9pt; }
            .summary-card { border: 1px solid #ccc !important; box-shadow: none !important; }
        }
    </style>
</head>
<body>

    <header class="top-header">
        <div class="brand"><i class="fa-solid fa-headset"></i> PrimeSys Suporte</div>
        <div class="user-info">
            <span><i class="fa-solid fa-user"></i> <strong><?= htmlspecialchars($nomeUsuario) ?></strong></span>
            <a href="logout.php" class="btn-logout"><i class="fa-solid fa-right-from-bracket"></i> Sair</a>
        </div>
    </header>

    <div class="app-container">
        <?php 
            $sidebar = new Sidebar('relatorio_chamados', $isMaster); 
            $sidebar->render(); 
        ?>

        <main class="main-content">
            <div class="btn-print-hide" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                <h1 class="page-title" style="margin-bottom: 0;">Relatório Geral de Chamados</h1>
                <button onclick="window.print()" class="btn-3d btn-primary-3d">
                    <i class="fa-solid fa-print"></i> Imprimir / Salvar PDF
                </button>
            </div>

            <!-- FILTROS DE PESQUISA -->
            <div class="filters-card btn-print-hide">
                <form method="GET" action="relatorio_chamados.php" class="filters-grid">
                    <div class="form-group">
                        <label>Data Início Abertura:</label>
                        <input type="date" name="data_ini" value="<?= $dataIni ?>" required>
                    </div>

                    <div class="form-group">
                        <label>Data Fim Abertura:</label>
                        <input type="date" name="data_fim" value="<?= $dataFim ?>" required>
                    </div>

                    <div class="form-group">
                        <label>Cliente:</label>
                        <select name="cliente" id="select_cliente" onchange="carregarUsuariosCliente(this.value)">
                            <option value="">Todos os Clientes</option>
                            <?php foreach ($clientesLista as $c): ?>
                                <option value="<?= $c['ID_CLIENTE'] ?>" <?= $filtroCliente == $c['ID_CLIENTE'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($c['RAZAO_SOCIAL']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Usuário Solicitante:</label>
                        <select name="id_usuario_cliente" id="select_usuario_cliente">
                            <option value="">Todos os Usuários</option>
                            <?php foreach ($usuariosFiltroLista as $u): ?>
                                <option value="<?= $u['ID_USUARIO'] ?>" <?= $filtroUsuarioCliente == $u['ID_USUARIO'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($u['NOME']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Responsável:</label>
                        <select name="responsavel">
                            <option value="">Todos os Responsáveis</option>
                            <option value="0" <?= $filtroResponsavel === '0' ? 'selected' : '' ?>>Sem Responsável</option>
                            <?php foreach ($responsaveisLista as $resp): ?>
                                <option value="<?= $resp['ID_USUARIO'] ?>" <?= $filtroResponsavel == $resp['ID_USUARIO'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($resp['NOME']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filters-actions">
                        <button type="submit" class="btn-3d btn-secondary-3d"><i class="fa-solid fa-magnifying-glass"></i> Gerar Relatório</button>
                    </div>
                </form>
            </div>

            <!-- CABEÇALHO EXCLUSIVO PARA IMPRESSÃO -->
            <div class="report-header-print">
                <h2>PrimeSys CRM - Relatório Gerencial de Chamados</h2>
                <p>
                    Período de Abertura: <strong><?= date('d/m/Y', strtotime($dataIni)) ?></strong> até <strong><?= date('d/m/Y', strtotime($dataFim)) ?></strong>
                </p>
            </div>

            <!-- CARDS TOTALIZADORES E ESTATÍSTICAS -->
            <div class="summary-grid">
                <div class="summary-card" style="border-left-color: #001517;">
                    <span style="font-size: 11px; color: #666; font-weight: bold;">Total de Chamados</span>
                    <h2 style="margin-top: 5px; color: #001517;"><?= $totalChamados ?></h2>
                </div>

                <div class="summary-card" style="border-left-color: #16a34a;">
                    <span style="font-size: 11px; color: #666; font-weight: bold;">No Prazo / Dentro do Prazo</span>
                    <h2 style="margin-top: 5px; color: #16a34a;"><?= $noPrazo ?></h2>
                </div>

                <div class="summary-card" style="border-left-color: #dc2626;">
                    <span style="font-size: 11px; color: #666; font-weight: bold;">Entregue / Em Atraso</span>
                    <h2 style="margin-top: 5px; color: #dc2626;"><?= $atrasados ?></h2>
                </div>

                <div class="summary-card" style="border-left-color: #d97706;">
                    <span style="font-size: 11px; color: #666; font-weight: bold;">Sem Previsão Cadastrada</span>
                    <h2 style="margin-top: 5px; color: #d97706;"><?= $emAbertoSemPrevisao ?></h2>
                </div>
            </div>

            <!-- PAINEL DE SUBTOTALIZADORES (TIPO, ETAPA E RESPONSÁVEL) -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 15px; margin-bottom: 20px;">
                <div style="background: #fff; padding: 12px; border-radius: 6px; border: 1px solid #e2e8f0;">
                    <h4 style="margin-bottom: 8px; color: #001517;"><i class="fa-solid fa-tags"></i> Subtotal por Tipo:</h4>
                    <div style="font-size: 12px;">
                        <?php foreach ($subtotaisTipo as $tName => $q): ?>
                            <span style="display: inline-block; background: #f1f5f9; padding: 4px 8px; border-radius: 4px; margin: 2px;">
                                <strong><?= $tName ?>:</strong> <?= $q ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div style="background: #fff; padding: 12px; border-radius: 6px; border: 1px solid #e2e8f0;">
                    <h4 style="margin-bottom: 8px; color: #001517;"><i class="fa-solid fa-layer-group"></i> Subtotal por Etapa:</h4>
                    <div style="font-size: 12px;">
                        <?php foreach ($subtotaisEtapa as $eName => $q): ?>
                            <span style="display: inline-block; background: #f1f5f9; padding: 4px 8px; border-radius: 4px; margin: 2px;">
                                <strong><?= $eName ?>:</strong> <?= $q ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div style="background: #fff; padding: 12px; border-radius: 6px; border: 1px solid #e2e8f0;">
                    <h4 style="margin-bottom: 8px; color: #001517;"><i class="fa-solid fa-user-gear"></i> Subtotal por Responsável:</h4>
                    <div style="font-size: 12px;">
                        <?php foreach ($subtotaisResponsavel as $rName => $q): ?>
                            <span style="display: inline-block; background: #f1f5f9; padding: 4px 8px; border-radius: 4px; margin: 2px;">
                                <strong><?= $rName ?>:</strong> <?= $q ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- TABELA DETALHADA DOS CHAMADOS -->
            <div class="table-responsive">
                <table class="custom-table" style="font-size: 12px;">
                    <thead>
                        <tr>
                            <th>#ID</th>
                            <th>Cliente</th>
                            <th>Solicitante</th>
                            <th>Responsável</th>
                            <th>Tipo</th>
                            <th>Etapa</th>
                            <th>Abertura</th>
                            <th>Previsão</th>
                            <th>Status Prazo</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($chamados)): ?>
                            <tr><td colspan="9" style="text-align: center; padding: 15px;">Nenhum chamado encontrado para os filtros selecionados.</td></tr>
                        <?php else: ?>
                            <?php foreach ($chamados as $ch): ?>
                                <tr>
                                    <td><strong>#<?= $ch['ID_CHAMADO'] ?></strong></td>
                                    <td><?= htmlspecialchars($ch['NOME_CLIENTE']) ?></td>
                                    <td><?= htmlspecialchars($ch['NOME_USUARIO']) ?></td>
                                    <td>
                                        <?php if (!empty($ch['NOME_RESPONSAVEL'])): ?>
                                            <span style="color: #15803d; font-weight: bold;"><?= htmlspecialchars($ch['NOME_RESPONSAVEL']) ?></span>
                                        <?php else: ?>
                                            <span style="color: #dc2626; font-style: italic;">Sem Responsável</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="badge-tag"><?= $ch['TIPO_CHAMADO'] ?></span></td>
                                    <td><?= $ch['ETAPA_CHAMADO'] ?></td>
                                    <td><?= date('d/m/Y', strtotime($ch['DATA_ABERTURA'])) ?></td>
                                    <td><?= !empty($ch['DATA_PREVISAO']) ? date('d/m/Y', strtotime($ch['DATA_PREVISAO'])) : '-' ?></td>
                                    <td>
                                        <?php 
                                            if (empty($ch['DATA_PREVISAO'])) {
                                                echo '<span style="color: #666;">Sem Previsão</span>';
                                            } else {
                                                $hoje = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));
                                                $hoje->setTime(0, 0, 0);

                                                $dtPrevisao = new DateTime($ch['DATA_PREVISAO']);
                                                $dtPrevisao->setTime(0, 0, 0);

                                                if (!empty($ch['DATA_CONCLUSAO'])) {
                                                    $dtConclusao = new DateTime($ch['DATA_CONCLUSAO']);
                                                    $dtConclusao->setTime(0, 0, 0);

                                                    if ($dtConclusao > $dtPrevisao) {
                                                        echo "<span style='color: #dc2626; font-weight: bold;'>Concluído com Atraso</span>";
                                                    } else {
                                                        echo "<span style='color: #16a34a; font-weight: bold;'>Concluído no Prazo</span>";
                                                    }
                                                } else {
                                                    if ($hoje > $dtPrevisao) {
                                                        echo "<span style='color: #dc2626; font-weight: bold;'>Atrasado</span>";
                                                    } else {
                                                        echo "<span style='color: #16a34a; font-weight: bold;'>No Prazo</span>";
                                                    }
                                                }
                                            }
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        </main>
    </div>

    <script>
        function carregarUsuariosCliente(idCliente) {
            const selectUsuario = document.getElementById('select_usuario_cliente');
            selectUsuario.innerHTML = '<option value="">Carregando...</option>';

            if (!idCliente) {
                selectUsuario.innerHTML = '<option value="">Todos os Usuários</option>';
                return;
            }

            fetch('get_usuarios_cliente.php?id_cliente=' + idCliente)
                .then(response => response.json())
                .then(data => {
                    selectUsuario.innerHTML = '<option value="">Todos os Usuários</option>';
                    if (data && data.length > 0) {
                        data.forEach(user => {
                            selectUsuario.innerHTML += `<option value="${user.ID_USUARIO}">${user.NOME}</option>`;
                        });
                    }
                })
                .catch(() => {
                    selectUsuario.innerHTML = '<option value="">Erro ao carregar usuários</option>';
                });
        }
    </script>
</body>
</html>