<?php
session_start();

if (!isset($_SESSION['id_usuario']) || ($_SESSION['id_cliente'] ?? 0) <= 0) {
    header("Location: index.php?error=" . urlencode("Acesso negado. Página restrita a clientes."));
    exit;
}

require_once __DIR__ . '/php/database.php';

$idUsuarioLogado = $_SESSION['id_usuario'];
$idClienteLogado = $_SESSION['id_cliente'];
$nomeUsuario = $_SESSION['nome'] ?? 'Cliente';
$db = new database();

$tiposDefinidos = ['ATENDIMENTO', 'ERRO', 'AJUSTE', 'PERSONALIZAÇÃO'];
$etapasDefinidas = ['Aguardando Atendimento', 'Análise', 'Resolvendo', 'Desenvolvimento', 'Testes', 'Atualização', 'Concluído'];

// --- PROCESSAMENTO: ABRIR NOVO CHAMADO ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'novo_chamado_cliente') {
    $tipo = addslashes($_POST['tipo_chamado']);
    $descricaoInicial = addslashes($_POST['descricao_inicial']);
    $etapa = 'Aguardando Atendimento';

    $agora = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));
    $dataHoje = $agora->format('Y-m-d');
    $horaHoje = $agora->format('H:i:s');

    $sql = "INSERT INTO CHAMADOS (DATA_ABERTURA, ID_USUARIO, ID_CLIENTE, TIPO_CHAMADO, ETAPA_CHAMADO, ULTIMA_MOV) 
            VALUES ('$dataHoje', $idUsuarioLogado, $idClienteLogado, '$tipo', '$etapa', '$dataHoje')";
    $db->Query($sql);

    $resCh = $db->Query("SELECT ID_CHAMADO FROM CHAMADOS WHERE ID_CLIENTE = $idClienteLogado ORDER BY ID_CHAMADO DESC LIMIT 1");
    $idChamadoCriado = $resCh[0]['ID_CHAMADO'];

    $sqlInt = "INSERT INTO INTERACOES_CHAMADOS (DATA_INTERACAO, HORA_INTERACAO, ID_CHAMADO, ID_USUARIO, ID_EMPRESA, MENSAGEM)
               VALUES ('$dataHoje', '$horaHoje', $idChamadoCriado, $idUsuarioLogado, $idClienteLogado, '$descricaoInicial')";
    $db->Query($sqlInt);

    header("Location: ver_chamado_cliente.php?id=$idChamadoCriado");
    exit;
}

// --- FILTROS DE CONSULTA ---
$filtroTipo = addslashes($_GET['tipo'] ?? '');
$filtroEtapa = addslashes($_GET['etapa'] ?? '');

$where = [
    "CH.ID_CLIENTE = $idClienteLogado",
    "(CH.ETAPA_CHAMADO != 'Concluído' OR CH.DATA_CONCLUSAO IS NULL OR CH.DATA_CONCLUSAO >= DATE_SUB(CURDATE(), INTERVAL 7 DAY))"
];

if (!empty($filtroTipo)) { $where[] = "CH.TIPO_CHAMADO = '$filtroTipo'"; }
if (!empty($filtroEtapa)) { $where[] = "CH.ETAPA_CHAMADO = '$filtroEtapa'"; }

$sqlWhere = " WHERE " . implode(" AND ", $where);

// Consulta com LEFT JOIN para capturar o responsável
$sqlChamados = "SELECT CH.*, RESP.NOME AS NOME_RESPONSAVEL 
                FROM CHAMADOS CH 
                LEFT JOIN USUARIOS RESP ON CH.ID_RESPONSAVEL = RESP.ID_USUARIO 
                $sqlWhere 
                ORDER BY CH.ID_CHAMADO DESC";
$chamados = $db->Query($sqlChamados) ?: [];

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Minhas Solicitações - PrimeSys</title>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/suporte.css">
    <style>
        .client-main-content {
            margin-left: 0 !important;
            width: 100% !important;
            max-width: 1200px;
            margin: 80px auto 30px auto;
            padding: 0 20px;
        }
    </style>
</head>
<body>

    <header class="top-header">
        <div class="brand"><i class="fa-solid fa-headset"></i> Área do Cliente</div>
        <div class="user-info">
            <span><i class="fa-solid fa-user"></i> <strong><?= htmlspecialchars($nomeUsuario) ?></strong></span>
            <a href="logout.php" class="btn-logout"><i class="fa-solid fa-right-from-bracket"></i> Sair</a>
        </div>
    </header>

    <main class="client-main-content">
        <h1 class="page-title">Minhas Solicitações de Suporte</h1>

        <div class="filters-card">
            <form method="GET" action="home_cliente.php" class="filters-grid">
                <div class="form-group">
                    <label>Tipo de Chamado:</label>
                    <select name="tipo" onchange="this.form.submit()">
                        <option value="">Todos os Tipos</option>
                        <?php foreach ($tiposDefinidos as $t): ?>
                            <option value="<?= $t ?>" <?= $filtroTipo === $t ? 'selected' : '' ?>><?= $t ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Etapa Atual:</label>
                    <select name="etapa" onchange="this.form.submit()">
                        <option value="">Todas as Etapas</option>
                        <?php foreach ($etapasDefinidas as $e): ?>
                            <option value="<?= $e ?>" <?= $filtroEtapa === $e ? 'selected' : '' ?>><?= $e ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filters-actions">
                    <button type="button" class="btn-3d btn-primary-3d" onclick="openNovoChamadoModal()">
                        <i class="fa-solid fa-plus"></i> Abrir Solicitação
                    </button>
                </div>
            </form>
        </div>

        <div class="table-responsive">
            <table class="custom-table">
                <thead>
                    <tr>
                        <th>#ID</th>
                        <th>Tipo</th>
                        <th>Etapa / Status</th>
                        <th>Atendente Responsável</th>
                        <th>Abertura</th>
                        <th>Previsão Solução</th>
                        <th>Prazo / Atraso</th>
                        <th>Ação</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($chamados)): ?>
                        <tr>
                            <td colspan="8" style="text-align: center; color: #888; padding: 20px;">Nenhuma solicitação encontrada.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($chamados as $ch): ?>
                            <tr>
                                <td><strong>#<?= $ch['ID_CHAMADO'] ?></strong></td>
                                <td><span class="badge-tag"><?= $ch['TIPO_CHAMADO'] ?></span></td>
                                <td>
                                    <span class="badge-tag" style="background: #001517; color: #fff;">
                                        <?= $ch['ETAPA_CHAMADO'] ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if (!empty($ch['NOME_RESPONSAVEL'])): ?>
                                        <span style="color: #16a34a; font-weight: bold;">
                                            <i class="fa-solid fa-user-check"></i> <?= htmlspecialchars($ch['NOME_RESPONSAVEL']) ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color: #64748b; font-style: italic;">Aguardando atribuição</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= date('d/m/Y', strtotime($ch['DATA_ABERTURA'])) ?></td>
                                <td><?= !empty($ch['DATA_PREVISAO']) ? date('d/m/Y', strtotime($ch['DATA_PREVISAO'])) : 'Em definição' ?></td>
                                <td>
                                    <?php 
                                        if (empty($ch['DATA_PREVISAO'])) {
                                            echo '<span style="color: #666;">-</span>';
                                        } else {
                                            $hoje = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));
                                            $hoje->setTime(0, 0, 0);
                                            
                                            $dtPrevisao = new DateTime($ch['DATA_PREVISAO']);
                                            $dtPrevisao->setTime(0, 0, 0);

                                            if (!empty($ch['DATA_CONCLUSAO'])) {
                                                $dtConclusao = new DateTime($ch['DATA_CONCLUSAO']);
                                                $dtConclusao->setTime(0, 0, 0);
                                                
                                                if ($dtConclusao > $dtPrevisao) {
                                                    $diasAtraso = $dtPrevisao->diff($dtConclusao)->days;
                                                    echo "<span style='color: #dc2626; font-weight: bold;'>Entregue com {$diasAtraso} dia(s) de atraso</span>";
                                                } else {
                                                    echo "<span style='color: #16a34a; font-weight: bold;'>Entregue no prazo</span>";
                                                }
                                            } else {
                                                if ($hoje > $dtPrevisao) {
                                                    $diasAtrasado = $dtPrevisao->diff($hoje)->days;
                                                    echo "<span style='color: #dc2626; font-weight: bold;'><i class='fa-solid fa-triangle-exclamation'></i> {$diasAtrasado} dia(s) de atraso</span>";
                                                } else {
                                                    $diasRestantes = $hoje->diff($dtPrevisao)->days;
                                                    if ($diasRestantes == 0) {
                                                        echo "<span style='color: #d97706; font-weight: bold;'>Vence Hoje</span>";
                                                    } else {
                                                        echo "<span style='color: #16a34a; font-weight: bold;'>{$diasRestantes} dia(s) restantes</span>";
                                                    }
                                                }
                                            }
                                        }
                                    ?>
                                </td>
                                <td>
                                    <a href="ver_chamado_cliente.php?id=<?= $ch['ID_CHAMADO'] ?>" class="btn-3d btn-secondary-3d btn-sm-3d">
                                        <i class="fa-solid fa-eye"></i> Acompanhar
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>

    <div class="modal-overlay" id="novoChamadoModal">
        <div class="modal-card">
            <div class="modal-header">
                <h3><i class="fa-solid fa-headset"></i> Nova Solicitação</h3>
                <i class="fa-solid fa-xmark" style="cursor: pointer;" onclick="closeNovoChamadoModal()"></i>
            </div>
            <form method="POST" action="home_cliente.php">
                <input type="hidden" name="acao" value="novo_chamado_cliente">
                <div class="modal-body">
                    <div class="form-grid">
                        <div class="form-group full-width">
                            <label>Tipo de Solicitação <span class="required-asterisk">*</span></label>
                            <select name="tipo_chamado" required style="padding: 10px; border-radius:6px; border:1.5px solid #cbd5e1;">
                                <?php foreach ($tiposDefinidos as $t): ?>
                                    <option value="<?= $t ?>"><?= $t ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group full-width">
                            <label>Descrição Detalhada do Problema / Dúvida <span class="required-asterisk">*</span></label>
                            <textarea name="descricao_inicial" required rows="5" placeholder="Descreva o que está acontecendo..." style="padding: 10px; border-radius:6px; border:1.5px solid #cbd5e1;"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn-3d btn-danger-3d" onclick="closeNovoChamadoModal()">Cancelar</button>
                        <button type="submit" class="btn-3d btn-success-3d"><i class="fa-solid fa-paper-plane"></i> Enviar</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openNovoChamadoModal() {
            document.getElementById('novoChamadoModal').classList.add('active');
        }
        function closeNovoChamadoModal() {
            document.getElementById('novoChamadoModal').classList.remove('active');
        }
    </script>
</body>
</html>