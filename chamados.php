<?php
session_start();

if (!isset($_SESSION['id_usuario']) || (!($_SESSION['atendente'] ?? false) && !($_SESSION['master'] ?? false))) {
    header("Location: index.php?error=" . urlencode("Acesso negado."));
    exit;
}

date_default_timezone_set('America/Sao_Paulo');

require_once __DIR__ . '/php/database.php';
require_once __DIR__ . '/php/Sidebar.php';

$isMaster = $_SESSION['master'] ?? false;
$idUsuarioLogado = $_SESSION['id_usuario'];
$nomeUsuario = $_SESSION['nome'] ?? 'Atendente';
$db = new database();

$etapasDefinidas = ['Aguardando Atendimento', 'Análise', 'Resolvendo', 'Desenvolvimento', 'Testes', 'Atualização', 'Concluído'];
$tiposDefinidos = ['ATENDIMENTO', 'ERRO', 'AJUSTE', 'PERSONALIZAÇÃO'];

// --- PROCESSAMENTO DE AÇÕES (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';

    // 1. Novo Chamado
    if ($acao === 'novo_chamado') {
        $idCliente = (int)$_POST['id_cliente'];
        $idUsuarioSolicitante = (int)$_POST['id_usuario_solicitante'];
        $tipo = addslashes($_POST['tipo_chamado']);
        $descricaoInicial = addslashes($_POST['descricao_inicial']);
        $etapa = 'Aguardando Atendimento';
        
        $agora = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));
        $dataHoje = date('Y-m-d');
        $horaHoje = date('H:i:s');

        $sql = "INSERT INTO CHAMADOS (DATA_ABERTURA, ID_USUARIO, ID_CLIENTE, TIPO_CHAMADO, ETAPA_CHAMADO, ULTIMA_MOV) 
                VALUES ('$dataHoje', $idUsuarioSolicitante, $idCliente, '$tipo', '$etapa', '$dataHoje')";
        $db->Query($sql);

        $resCh = $db->Query("SELECT ID_CHAMADO FROM CHAMADOS WHERE ID_CLIENTE = $idCliente ORDER BY ID_CHAMADO DESC LIMIT 1");
        $idChamadoCriado = $resCh[0]['ID_CHAMADO'];

        $sqlInt = "INSERT INTO INTERACOES_CHAMADOS (DATA_INTERACAO, HORA_INTERACAO, ID_CHAMADO, ID_USUARIO, ID_EMPRESA, MENSAGEM)
                   VALUES ('$dataHoje', '$horaHoje', $idChamadoCriado, $idUsuarioSolicitante, 1, '$descricaoInicial')";
        $db->Query($sqlInt);

        header("Location: chamados.php?view_id=$idChamadoCriado");
        exit;
    }

    // 2. Atualizar Dados do Chamado (Etapa, Tipo e Previsão)
    if ($acao === 'atualizar_chamado') {
        $idChamado = (int)$_POST['id_chamado'];
        $novaEtapa = addslashes($_POST['etapa_chamado']);
        $novoTipo = addslashes($_POST['tipo_chamado']);
        $dataPrevisao = !empty($_POST['data_previsao']) ? "'" . $_POST['data_previsao'] . "'" : "NULL";
        $dataHoje = date('Y-m-d');
        
        $sqlConclusao = ($novaEtapa === 'Concluído') ? ", DATA_CONCLUSAO = '$dataHoje'" : ", DATA_CONCLUSAO = NULL";
        
        $sql = "UPDATE CHAMADOS SET 
                    ETAPA_CHAMADO = '$novaEtapa', 
                    TIPO_CHAMADO = '$novoTipo', 
                    DATA_PREVISAO = $dataPrevisao, 
                    ULTIMA_MOV = '$dataHoje' 
                    $sqlConclusao 
                WHERE ID_CHAMADO = $idChamado";
        $db->Query($sql);
        header("Location: chamados.php?view_id=$idChamado");
        exit;
    }

    // 3. Nova Interação com Anexo
    if ($acao === 'nova_interacao') {
        $idChamado = (int)$_POST['id_chamado'];
        $mensagem = addslashes($_POST['mensagem']);
        $dataHoje = date('Y-m-d');
        $horaHoje = date('H:i:s');

        $sql = "INSERT INTO INTERACOES_CHAMADOS (DATA_INTERACAO, HORA_INTERACAO, ID_CHAMADO, ID_USUARIO, ID_EMPRESA, MENSAGEM)
                VALUES ('$dataHoje', '$horaHoje', $idChamado, $idUsuarioLogado, 1, '$mensagem')";
        $db->Query($sql);
        
        $resInt = $db->Query("SELECT ID_INTERACAO FROM INTERACOES_CHAMADOS WHERE ID_CHAMADO = $idChamado ORDER BY ID_INTERACAO DESC LIMIT 1");
        $idInteracao = $resInt[0]['ID_INTERACAO'];

        $db->Query("UPDATE CHAMADOS SET ULTIMA_MOV = '$dataHoje' WHERE ID_CHAMADO = $idChamado");

        if (isset($_FILES['anexo']) && $_FILES['anexo']['error'] === UPLOAD_ERR_OK) {
            $nomeOriginal = $_FILES['anexo']['name'];
            $ext = pathinfo($nomeOriginal, PATHINFO_EXTENSION);
            $conteudo = file_get_contents($_FILES['anexo']['tmp_name']);

            $stmt = $db->connect()->prepare("INSERT INTO ANEXOS_INTERACOES (ID_INTERACAO, ID_CHAMADO, NOME_ARQUIVO, EXTENSAO_ARQUIVO, ARQUIVO) VALUES (?, ?, ?, ?, ?)");
            $stmt->bindParam(1, $idInteracao);
            $stmt->bindParam(2, $idChamado);
            $stmt->bindParam(3, $nomeOriginal);
            $stmt->bindParam(4, $ext);
            $stmt->bindParam(5, $conteudo, PDO::PARAM_LOB);
            $stmt->execute();
        }

        header("Location: chamados.php?view_id=$idChamado");
        exit;
    }

    // 4. Editar Interação Existente
    if ($acao === 'editar_interacao') {
        $idInteracao = (int)$_POST['id_interacao'];
        $idChamado = (int)$_POST['id_chamado'];
        $mensagem = addslashes($_POST['mensagem']);

        $db->Query("UPDATE INTERACOES_CHAMADOS SET MENSAGEM = '$mensagem' WHERE ID_INTERACAO = $idInteracao");

        if (isset($_FILES['anexo_edicao']) && $_FILES['anexo_edicao']['error'] === UPLOAD_ERR_OK) {
            $nomeOriginal = $_FILES['anexo_edicao']['name'];
            $ext = pathinfo($nomeOriginal, PATHINFO_EXTENSION);
            $conteudo = file_get_contents($_FILES['anexo_edicao']['tmp_name']);

            $stmt = $db->connect()->prepare("INSERT INTO ANEXOS_INTERACOES (ID_INTERACAO, ID_CHAMADO, NOME_ARQUIVO, EXTENSAO_ARQUIVO, ARQUIVO) VALUES (?, ?, ?, ?, ?)");
            $stmt->bindParam(1, $idInteracao);
            $stmt->bindParam(2, $idChamado);
            $stmt->bindParam(3, $nomeOriginal);
            $stmt->bindParam(4, $ext);
            $stmt->bindParam(5, $conteudo, PDO::PARAM_LOB);
            $stmt->execute();
        }

        header("Location: chamados.php?view_id=$idChamado");
        exit;
    }
}

// --- FILTROS DE CONSULTA ---
$filtroCliente = (int)($_GET['cliente'] ?? 0);
$filtroTipo = addslashes($_GET['tipo'] ?? '');
$filtroEtapa = addslashes($_GET['etapa'] ?? '');
$filtroResponsavel = $_GET['responsavel'] ?? '';
$visaoModo = $_GET['modo'] ?? 'kanban';
$ordemInteracao = $_GET['ordem_int'] ?? 'ASC';

$where = ["1=1"];
if ($filtroCliente > 0) { $where[] = "CH.ID_CLIENTE = $filtroCliente"; }
if (!empty($filtroTipo)) { $where[] = "CH.TIPO_CHAMADO = '$filtroTipo'"; }
if (!empty($filtroEtapa)) { $where[] = "CH.ETAPA_CHAMADO = '$filtroEtapa'"; }

if ($filtroResponsavel !== '') {
    if ($filtroResponsavel === '0') {
        $where[] = "(CH.ID_RESPONSAVEL IS NULL OR CH.ID_RESPONSAVEL = 0)";
    } else {
        $where[] = "CH.ID_RESPONSAVEL = " . (int)$filtroResponsavel;
    }
}

$sqlWhere = " WHERE " . implode(" AND ", $where);

// Query com Left Join para trazer o nome do Responsável
$sqlChamados = "SELECT CH.*, 
                       CLI.RAZAO_SOCIAL AS NOME_CLIENTE, 
                       U.NOME AS NOME_USUARIO,
                       RESP.NOME AS NOME_RESPONSAVEL
                FROM CHAMADOS CH 
                INNER JOIN CLIENTES CLI ON CH.ID_CLIENTE = CLI.ID_CLIENTE 
                INNER JOIN USUARIOS U ON CH.ID_USUARIO = U.ID_USUARIO 
                LEFT JOIN USUARIOS RESP ON CH.ID_RESPONSAVEL = RESP.ID_USUARIO
                $sqlWhere 
                ORDER BY CH.ID_CHAMADO DESC";

$chamados = $db->Query($sqlChamados) ?: [];

// Lista de Clientes e Atendentes para os Selects dos Filtros
$clientesLista = $db->Query("SELECT ID_CLIENTE, RAZAO_SOCIAL FROM CLIENTES ORDER BY RAZAO_SOCIAL ASC") ?: [];
$responsaveisLista = $db->Query("SELECT ID_USUARIO, NOME FROM USUARIOS WHERE (ATENDENTE = 1 OR PROPRIETARIO_SISTEMA = 1) ORDER BY NOME ASC") ?: [];

// --- CARREGAR DETALHES DO CHAMADO (SE SOLICITADO) ---
$chamadoDetalhe = null;
$interacoes = [];
$viewId = (int)($_GET['view_id'] ?? 0);

if ($viewId > 0) {
    $resCh = $db->Query("SELECT CH.*, 
                                CLI.RAZAO_SOCIAL AS NOME_CLIENTE, 
                                U.NOME AS NOME_USUARIO,
                                RESP.NOME AS NOME_RESPONSAVEL
                         FROM CHAMADOS CH 
                         INNER JOIN CLIENTES CLI ON CH.ID_CLIENTE = CLI.ID_CLIENTE 
                         INNER JOIN USUARIOS U ON CH.ID_USUARIO = U.ID_USUARIO 
                         LEFT JOIN USUARIOS RESP ON CH.ID_RESPONSAVEL = RESP.ID_USUARIO
                         WHERE CH.ID_CHAMADO = $viewId");
    if (!empty($resCh)) {
        $chamadoDetalhe = $resCh[0];

        $sqlInt = "SELECT I.*, U.NOME AS NOME_USUARIO 
                   FROM INTERACOES_CHAMADOS I 
                   INNER JOIN USUARIOS U ON I.ID_USUARIO = U.ID_USUARIO 
                   WHERE I.ID_CHAMADO = $viewId 
                   ORDER BY I.ID_INTERACAO $ordemInteracao";
        $interacoes = $db->Query($sqlInt) ?: [];

        $anexosRaw = $db->Query("SELECT ID_ANEXO, ID_INTERACAO, NOME_ARQUIVO FROM ANEXOS_INTERACOES WHERE ID_CHAMADO = $viewId") ?: [];
        $anexos = [];
        foreach ($anexosRaw as $anx) {
            $anexos[$anx['ID_INTERACAO']][] = $anx;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Atendimento de Chamados - PrimeSys</title>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/suporte.css">
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
            $sidebar = new Sidebar('chamados', $isMaster); 
            $sidebar->render(); 
        ?>

        <main class="main-content">
            <h1 class="page-title">Painel de Chamados</h1>

            <!-- Barra de Filtros e Modo de Visualização -->
            <div class="filters-card">
                <form method="GET" action="chamados.php" class="filters-grid">
                    <input type="hidden" name="modo" value="<?= htmlspecialchars($visaoModo) ?>">
                    
                    <div class="form-group">
                        <label>Cliente:</label>
                        <select name="cliente" onchange="this.form.submit()">
                            <option value="">Todos os Clientes</option>
                            <?php foreach ($clientesLista as $c): ?>
                                <option value="<?= $c['ID_CLIENTE'] ?>" <?= $filtroCliente == $c['ID_CLIENTE'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($c['RAZAO_SOCIAL']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Responsável:</label>
                        <select name="responsavel" onchange="this.form.submit()">
                            <option value="">Todos os Responsáveis</option>
                            <option value="0" <?= $filtroResponsavel === '0' ? 'selected' : '' ?>>Sem Responsável</option>
                            <?php foreach ($responsaveisLista as $resp): ?>
                                <option value="<?= $resp['ID_USUARIO'] ?>" <?= $filtroResponsavel == $resp['ID_USUARIO'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($resp['NOME']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Tipo:</label>
                        <select name="tipo" onchange="this.form.submit()">
                            <option value="">Todos os Tipos</option>
                            <?php foreach ($tiposDefinidos as $t): ?>
                                <option value="<?= $t ?>" <?= $filtroTipo === $t ? 'selected' : '' ?>><?= $t ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Etapa:</label>
                        <select name="etapa" onchange="this.form.submit()">
                            <option value="">Todas as Etapas</option>
                            <?php foreach ($etapasDefinidas as $e): ?>
                                <option value="<?= $e ?>" <?= $filtroEtapa === $e ? 'selected' : '' ?>><?= $e ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filters-actions">
                        <div class="view-toggle">
                            <a href="chamados.php?modo=kanban&cliente=<?= $filtroCliente ?>&responsavel=<?= $filtroResponsavel ?>&tipo=<?= $filtroTipo ?>&etapa=<?= $filtroEtapa ?>" 
                               class="view-toggle-btn <?= $visaoModo === 'kanban' ? 'active' : '' ?>">
                                <i class="fa-solid fa-table-columns"></i> Kanban
                            </a>
                            <a href="chamados.php?modo=lista&cliente=<?= $filtroCliente ?>&responsavel=<?= $filtroResponsavel ?>&tipo=<?= $filtroTipo ?>&etapa=<?= $filtroEtapa ?>" 
                               class="view-toggle-btn <?= $visaoModo === 'lista' ? 'active' : '' ?>">
                                <i class="fa-solid fa-list"></i> Lista
                            </a>
                        </div>

                        <button type="button" class="btn-3d btn-primary-3d" onclick="openNovoChamadoModal()">
                            <i class="fa-solid fa-plus"></i> Novo Chamado
                        </button>
                    </div>
                </form>
            </div>

            <!-- VISÃO KANBAN -->
            <?php if ($visaoModo === 'kanban'): ?>
                <div class="kanban-board">
                    <?php foreach ($etapasDefinidas as $etapa): ?>
                        <div class="kanban-column">
                            <div class="kanban-column-header">
                                <span><?= $etapa ?></span>
                                <?php 
                                    $qtdEtapa = array_reduce($chamados, function($acc, $item) use ($etapa) {
                                        return $acc + ($item['ETAPA_CHAMADO'] === $etapa ? 1 : 0);
                                    }, 0);
                                ?>
                                <span class="badge-tag"><?= $qtdEtapa ?></span>
                            </div>
                            <div class="kanban-cards">
                                <?php foreach ($chamados as $ch): ?>
                                    <?php if ($ch['ETAPA_CHAMADO'] === $etapa): ?>
                                        <div class="kanban-card" onclick="window.location.href='ver_chamado.php?id=<?= $ch['ID_CHAMADO'] ?>'">
                                            <div class="ticket-id">#<?= $ch['ID_CHAMADO'] ?> - <?= htmlspecialchars($ch['TIPO_CHAMADO']) ?></div>
                                            <div class="ticket-client"><?= htmlspecialchars($ch['NOME_CLIENTE']) ?></div>
                                            
                                            <!-- Exibição do Responsável no Card -->
                                            <div style="font-size: 11px; margin-top: 6px; color: #475569;">
                                                <i class="fa-solid fa-user-gear"></i> Resp: 
                                                <?php if (!empty($ch['NOME_RESPONSAVEL'])): ?>
                                                    <strong><?= htmlspecialchars($ch['NOME_RESPONSAVEL']) ?></strong>
                                                <?php else: ?>
                                                    <span style="color: #dc2626; font-style: italic;">Sem Responsável</span>
                                                <?php endif; ?>
                                            </div>

                                            <div class="ticket-meta" style="margin-top: 6px;">
                                                <span>Abertura: <?= date('d/m/Y', strtotime($ch['DATA_ABERTURA'])) ?></span>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

            <!-- VISÃO LISTA -->
            <?php else: ?>
                <div class="table-responsive">
                    <table class="custom-table">
                        <thead>
                            <tr>
                                <th>#ID</th>
                                <th>Cliente</th>
                                <th>Responsável</th>
                                <th>Tipo</th>
                                <th>Etapa</th>
                                <th>Abertura</th>
                                <th>Previsão</th>
                                <th>Ação</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($chamados as $ch): ?>
                                <tr>
                                    <td><strong>#<?= $ch['ID_CHAMADO'] ?></strong></td>
                                    <td><?= htmlspecialchars($ch['NOME_CLIENTE']) ?></td>
                                    <td>
                                        <?php if (!empty($ch['NOME_RESPONSAVEL'])): ?>
                                            <span style="color: #15803d; font-weight: bold;"><?= htmlspecialchars($ch['NOME_RESPONSAVEL']) ?></span>
                                        <?php else: ?>
                                            <span style="color: #dc2626; font-style: italic;">Não Atribuído</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="badge-tag"><?= $ch['TIPO_CHAMADO'] ?></span></td>
                                    <td><?= $ch['ETAPA_CHAMADO'] ?></td>
                                    <td><?= date('d/m/Y', strtotime($ch['DATA_ABERTURA'])) ?></td>
                                    <td><?= $ch['DATA_PREVISAO'] ? date('d/m/Y', strtotime($ch['DATA_PREVISAO'])) : '-' ?></td>
                                    <td>
                                        <a href="ver_chamado.php?id=<?= $ch['ID_CHAMADO'] ?>" class="btn-3d btn-secondary-3d btn-sm-3d">
                                          <i class="fa-solid fa-eye"></i> Visualizar
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <!-- MODAL DE CRIAÇÃO DE CHAMADO -->
    <div class="modal-overlay" id="novoChamadoModal">
        <div class="modal-card">
            <div class="modal-header">
                <h3><i class="fa-solid fa-headset"></i> Abrir Novo Chamado</h3>
                <i class="fa-solid fa-xmark" style="cursor: pointer;" onclick="closeNovoChamadoModal()"></i>
            </div>
            <form method="POST" action="chamados.php">
                <input type="hidden" name="acao" value="novo_chamado">
                <div class="modal-body">
                    <div class="form-grid">
                        
                        <div class="form-group full-width">
                            <label>Cliente <span class="required-asterisk">*</span></label>
                            <select name="id_cliente" id="select_cliente" required onchange="carregarUsuariosCliente(this.value)" style="padding: 10px; border-radius:6px; border:1.5px solid #cbd5e1;">
                                <option value="">-- Selecione o Cliente --</option>
                                <?php foreach ($clientesLista as $c): ?>
                                    <option value="<?= $c['ID_CLIENTE'] ?>"><?= htmlspecialchars($c['RAZAO_SOCIAL']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
    
                        <div class="form-group">
                            <label>Usuário Solicitante <span class="required-asterisk">*</span></label>
                            <select name="id_usuario_solicitante" id="select_usuario_cliente" required style="padding: 10px; border-radius:6px; border:1.5px solid #cbd5e1;">
                                <option value="">-- Selecione o Cliente Primeiro --</option>
                            </select>
                        </div>
    
                        <div class="form-group">
                            <label>Tipo de Chamado <span class="required-asterisk">*</span></label>
                            <select name="tipo_chamado" required style="padding: 10px; border-radius:6px; border:1.5px solid #cbd5e1;">
                                <?php foreach ($tiposDefinidos as $t): ?>
                                    <option value="<?= $t ?>"><?= $t ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
    
                        <div class="form-group full-width">
                            <label>Descrição do Problema / Solicitação <span class="required-asterisk">*</span></label>
                            <textarea name="descricao_inicial" required rows="4" placeholder="Descreva detalhadamente o chamado..." style="padding: 10px; border-radius:6px; border:1.5px solid #cbd5e1;"></textarea>
                        </div>
                    </div>
    
                    <div class="modal-footer">
                        <button type="button" class="btn-3d btn-danger-3d" onclick="closeNovoChamadoModal()">Cancelar</button>
                        <button type="submit" class="btn-3d btn-success-3d"><i class="fa-solid fa-floppy-disk"></i> Abrir Chamado</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL DE VISUALIZAÇÃO E INTERAÇÃO DO CHAMADO -->
    <?php if ($chamadoDetalhe): ?>
        <div class="modal-overlay active" id="viewChamadoModal">
            <div class="modal-card" style="max-width: 850px;">
                <div class="modal-header">
                    <h3><i class="fa-solid fa-ticket"></i> Chamado #<?= $chamadoDetalhe['ID_CHAMADO'] ?></h3>
                    <i class="fa-solid fa-xmark" style="cursor: pointer;" onclick="window.location.href='chamados.php'"></i>
                </div>
                <div class="modal-body">
                    
                    <div class="ticket-header-info">
                        <div><strong>Cliente:</strong> <?= htmlspecialchars($chamadoDetalhe['NOME_CLIENTE']) ?></div>
                        <div><strong>Aberto por:</strong> <?= htmlspecialchars($chamadoDetalhe['NOME_USUARIO']) ?></div>
                        <div><strong>Responsável:</strong> <?= !empty($chamadoDetalhe['NOME_RESPONSAVEL']) ? htmlspecialchars($chamadoDetalhe['NOME_RESPONSAVEL']) : '<em style="color:#dc2626;">Pendente</em>' ?></div>
                        <div><strong>Data de Abertura:</strong> <?= date('d/m/Y', strtotime($chamadoDetalhe['DATA_ABERTURA'])) ?></div>
                        <div><strong>Tipo:</strong> <span class="badge-tag"><?= $chamadoDetalhe['TIPO_CHAMADO'] ?></span></div>
                        
                        <div>
                            <strong>Tempo de Existência:</strong> 
                            <?php 
                                $dtInicio = new DateTime($chamadoDetalhe['DATA_ABERTURA']);
                                $dtFim = !empty($chamadoDetalhe['DATA_CONCLUSAO']) ? new DateTime($chamadoDetalhe['DATA_CONCLUSAO']) : new DateTime();
                                $diasExistencia = $dtInicio->diff($dtFim)->days;
                                echo "<strong>$diasExistencia dia(s)</strong>";
                            ?>
                        </div>

                        <div>
                            <strong>Tempo para Conclusão:</strong>
                            <?php 
                                if (!empty($chamadoDetalhe['DATA_PREVISAO'])) {
                                    $dtPrevisao = new DateTime($chamadoDetalhe['DATA_PREVISAO']);
                                    $hoje = new DateTime();
                                    if ($hoje > $dtPrevisao && empty($chamadoDetalhe['DATA_CONCLUSAO'])) {
                                        echo "<span style='color:#e53e3e; font-weight:bold;'>Atrasado</span>";
                                    } else {
                                        $intervalo = $hoje->diff($dtPrevisao);
                                        echo $intervalo->format('%r%a dia(s)');
                                    }
                                } else {
                                    echo "Não definida";
                                }
                            ?>
                        </div>
                    </div>

                    <form method="POST" action="chamados.php" style="margin-bottom: 20px; background: #f1f5f9; padding: 15px; border-radius: 6px;">
                        <input type="hidden" name="acao" value="atualizar_chamado">
                        <input type="hidden" name="id_chamado" value="<?= $chamadoDetalhe['ID_CHAMADO'] ?>">
                        
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; align-items: end;">
                            
                            <div class="form-group">
                                <label style="font-weight: bold; font-size: 12px;">Etapa:</label>
                                <select name="etapa_chamado" style="padding: 6px 10px; border-radius:4px; border:1px solid #ccc;">
                                    <?php foreach ($etapasDefinidas as $et): ?>
                                        <option value="<?= $et ?>" <?= $chamadoDetalhe['ETAPA_CHAMADO'] === $et ? 'selected' : '' ?>><?= $et ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                    
                            <div class="form-group">
                                <label style="font-weight: bold; font-size: 12px;">Tipo de Chamado:</label>
                                <select name="tipo_chamado" style="padding: 6px 10px; border-radius:4px; border:1px solid #ccc;">
                                    <?php foreach ($tiposDefinidos as $tp): ?>
                                        <option value="<?= $tp ?>" <?= $chamadoDetalhe['TIPO_CHAMADO'] === $tp ? 'selected' : '' ?>><?= $tp ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                    
                            <div class="form-group">
                                <label style="font-weight: bold; font-size: 12px;">Previsão Conclusão:</label>
                                <input type="date" name="data_previsao" value="<?= $chamadoDetalhe['DATA_PREVISAO'] ?>" style="padding: 6px 10px; border-radius:4px; border:1px solid #ccc;">
                            </div>
                    
                            <div>
                                <button type="submit" class="btn-3d btn-secondary-3d btn-sm-3d" style="width: 100%; height: 34px;">
                                    <i class="fa-solid fa-rotate"></i> Atualizar Dados
                                </button>
                            </div>
                        </div>
                    </form>

                    <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 15px;">
                        <h4 style="color:#001517;"><i class="fa-solid fa-comments"></i> Histórico de Interações</h4>
                        <a href="chamados.php?view_id=<?= $viewId ?>&ordem_int=<?= $ordemInteracao === 'ASC' ? 'DESC' : 'ASC' ?>" style="font-size: 12px; color: #822412; text-decoration: none; font-weight: bold;">
                            <i class="fa-solid fa-sort"></i> Exibir: <?= $ordemInteracao === 'ASC' ? 'Mais Antigas Primeiro' : 'Mais Recentes Primeiro' ?>
                        </a>
                    </div>

                    <div class="timeline">
                        <?php if (empty($interacoes)): ?>
                            <p style="text-align: center; color: #888; padding: 15px;">Nenhuma interação registrada ainda.</p>
                        <?php else: ?>
                            <?php foreach ($interacoes as $i): ?>
                                <div class="timeline-item">
                                    <div class="timeline-item-header">
                                        <span><strong><?= htmlspecialchars($i['NOME_USUARIO']) ?></strong> em <?= date('d/m/Y', strtotime($i['DATA_INTERACAO'])) ?> às <?= $i['HORA_INTERACAO'] ?></span>
                                        <button class="btn-3d btn-secondary-3d btn-sm-3d" onclick="openEditarInteracao(<?= $i['ID_INTERACAO'] ?>, '<?= htmlspecialchars(addslashes($i['MENSAGEM'])) ?>')">
                                            <i class="fa-solid fa-pen"></i> Editar
                                        </button>
                                    </div>
                                    <p style="font-size: 14px; color: #334155; white-space: pre-wrap;"><?= htmlspecialchars($i['MENSAGEM']) ?></p>
                                    
                                    <?php if (isset($anexos[$i['ID_INTERACAO']])): ?>
                                        <div class="timeline-attachments">
                                            <?php foreach ($anexos[$i['ID_INTERACAO']] as $anx): ?>
                                                <a href="download_anexo.php?id=<?= $anx['ID_ANEXO'] ?>" class="attachment-badge" target="_blank">
                                                    <i class="fa-solid fa-paperclip"></i> <?= htmlspecialchars($anx['NOME_ARQUIVO']) ?>
                                                </a>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <form method="POST" action="chamados.php" enctype="multipart/form-data" style="margin-top: 15px; border-top: 1px solid #eee; padding-top: 15px;">
                        <input type="hidden" name="acao" value="nova_interacao">
                        <input type="hidden" name="id_chamado" value="<?= $chamadoDetalhe['ID_CHAMADO'] ?>">
                        
                        <div class="form-group">
                            <label>Adicionar Resposta / Movimentação:</label>
                            <textarea name="mensagem" required placeholder="Digite os detalhes da interação..." rows="3"></textarea>
                        </div>

                        <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 10px;">
                            <input type="file" name="anexo" style="font-size: 12px;">
                            <button type="submit" class="btn-3d btn-success-3d">
                                <i class="fa-solid fa-paper-plane"></i> Enviar Interação
                            </button>
                        </div>
                    </form>

                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- MODAL EDITAR INTERAÇÃO -->
    <div class="modal-overlay" id="editarInteracaoModal">
        <div class="modal-card">
            <div class="modal-header">
                <h3>Editar Interação</h3>
                <i class="fa-solid fa-xmark" style="cursor: pointer;" onclick="closeEditarInteracao()"></i>
            </div>
            <form method="POST" action="chamados.php" enctype="multipart/form-data">
                <input type="hidden" name="acao" value="editar_interacao">
                <input type="hidden" name="id_chamado" value="<?= $viewId ?>">
                <input type="hidden" name="id_interacao" id="edit_id_interacao">
                
                <div class="modal-body">
                    <div class="form-group">
                        <label>Mensagem:</label>
                        <textarea name="mensagem" id="edit_mensagem" required rows="4"></textarea>
                    </div>
                    <div class="form-group" style="margin-top: 10px;">
                        <label>Adicionar Novo Anexo (opcional):</label>
                        <input type="file" name="anexo_edicao">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn-3d btn-danger-3d" onclick="closeEditarInteracao()">Cancelar</button>
                        <button type="submit" class="btn-3d btn-success-3d"><i class="fa-solid fa-floppy-disk"></i> Salvar Alterações</button>
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
        function openEditarInteracao(id, msg) {
            document.getElementById('edit_id_interacao').value = id;
            document.getElementById('edit_mensagem').value = msg;
            document.getElementById('editarInteracaoModal').classList.add('active');
        }
        function closeEditarInteracao() {
            document.getElementById('editarInteracaoModal').classList.remove('active');
        }
        
        function carregarUsuariosCliente(idCliente) {
            const selectUsuario = document.getElementById('select_usuario_cliente');
            selectUsuario.innerHTML = '<option value="">Carregando...</option>';
        
            if (!idCliente) {
                selectUsuario.innerHTML = '<option value="">-- Selecione o Cliente Primeiro --</option>';
                return;
            }
        
            fetch('get_usuarios_cliente.php?id_cliente=' + idCliente)
                .then(response => response.text())
                .then(text => {
                    try {
                        const data = JSON.parse(text);
                        selectUsuario.innerHTML = '<option value="">-- Selecione o Usuário --</option>';
                        
                        if (!data || data.length === 0) {
                            selectUsuario.innerHTML = '<option value="">Nenhum usuário encontrado para este cliente</option>';
                        } else {
                            data.forEach(user => {
                                selectUsuario.innerHTML += `<option value="${user.ID_USUARIO}">${user.NOME}</option>`;
                            });
                        }
                    } catch (err) {
                        console.error("Erro no retorno do PHP:", text);
                        selectUsuario.innerHTML = '<option value="">Erro na resposta do servidor</option>';
                    }
                })
                .catch(err => {
                    console.error("Erro na requisição Fetch:", err);
                    selectUsuario.innerHTML = '<option value="">Erro ao carregar usuários</option>';
                });
        }
    </script>
</body>
</html>