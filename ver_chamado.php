<?php
session_start();

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

$idChamado = (int)($_GET['id'] ?? 0);
if ($idChamado <= 0) {
    header("Location: chamados.php");
    exit;
}

date_default_timezone_set('America/Sao_Paulo');
$etapasDefinidas = ['Aguardando Atendimento', 'Análise', 'Resolvendo', 'Desenvolvimento', 'Testes', 'Atualização', 'Concluído'];
$tiposDefinidos = ['ATENDIMENTO', 'ERRO', 'AJUSTE', 'PERSONALIZAÇÃO'];
$ordemInteracao = $_GET['ordem_int'] ?? 'ASC';

// Aba ativa ('historico' ou 'apontamentos')
$abaAtiva = $_GET['aba'] ?? 'historico';

// --- PROCESSAMENTO DE AÇÕES (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';

    // 1. Atualizar Propriedades do Chamado
    if ($acao === 'atualizar_chamado') {
        $novaEtapa = addslashes($_POST['etapa_chamado']);
        $novoTipo = addslashes($_POST['tipo_chamado']);
        $dataPrevisao = !empty($_POST['data_previsao']) ? "'" . $_POST['data_previsao'] . "'" : "NULL";
        
        $agora = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));
        $dataHoje = $agora->format('Y-m-d');
        
        $sqlConclusao = ($novaEtapa === 'Concluído') ? ", DATA_CONCLUSAO = '$dataHoje'" : ", DATA_CONCLUSAO = NULL";
        
        $sql = "UPDATE CHAMADOS SET 
                    ETAPA_CHAMADO = '$novaEtapa', 
                    TIPO_CHAMADO = '$novoTipo', 
                    DATA_PREVISAO = $dataPrevisao, 
                    ULTIMA_MOV = '$dataHoje' 
                    $sqlConclusao 
                WHERE ID_CHAMADO = $idChamado";
        $db->Query($sql);
        header("Location: ver_chamado.php?id=$idChamado&aba=$abaAtiva");
        exit;
    }

    // 2. Assumir Responsabilidade
    if ($acao === 'assumir_responsavel') {
        $agora = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));
        $dataHoje = $agora->format('Y-m-d');

        $sql = "UPDATE CHAMADOS SET ID_RESPONSAVEL = $idUsuarioLogado, ULTIMA_MOV = '$dataHoje' WHERE ID_CHAMADO = $idChamado";
        $db->Query($sql);
        header("Location: ver_chamado.php?id=$idChamado&aba=$abaAtiva");
        exit;
    }

    // 3. Atribuir Responsável (Master)
    if ($acao === 'atribuir_responsavel' && $isMaster) {
        $idNovoResp = (int)($_POST['id_responsavel'] ?? 0);
        if ($idNovoResp > 0) {
            $agora = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));
            $dataHoje = $agora->format('Y-m-d');

            $sql = "UPDATE CHAMADOS SET ID_RESPONSAVEL = $idNovoResp, ULTIMA_MOV = '$dataHoje' WHERE ID_CHAMADO = $idChamado";
            $db->Query($sql);
        }
        header("Location: ver_chamado.php?id=$idChamado&aba=$abaAtiva");
        exit;
    }

    // 4. Nova Interação com Anexo
    if ($acao === 'nova_interacao') {
        $mensagem = addslashes($_POST['mensagem']);
        
        $agora = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));
        $dataHoje = $agora->format('Y-m-d');
        $horaHoje = $agora->format('H:i:s');

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

        header("Location: ver_chamado.php?id=$idChamado&aba=historico");
        exit;
    }

    // 5. Editar Interação Existente
    if ($acao === 'editar_interacao') {
        $idInteracao = (int)$_POST['id_interacao'];
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

        header("Location: ver_chamado.php?id=$idChamado&aba=historico");
        exit;
    }
}

// --- BUSCA DADOS DO CHAMADO ---
$resCh = $db->Query("SELECT CH.*, 
                            CLI.RAZAO_SOCIAL AS NOME_CLIENTE, 
                            U.NOME AS NOME_USUARIO,
                            RESP.NOME AS NOME_RESPONSAVEL
                     FROM CHAMADOS CH 
                     INNER JOIN CLIENTES CLI ON CH.ID_CLIENTE = CLI.ID_CLIENTE 
                     INNER JOIN USUARIOS U ON CH.ID_USUARIO = U.ID_USUARIO 
                     LEFT JOIN USUARIOS RESP ON CH.ID_RESPONSAVEL = RESP.ID_USUARIO
                     WHERE CH.ID_CHAMADO = $idChamado");

if (empty($resCh)) {
    header("Location: chamados.php");
    exit;
}
$chamado = $resCh[0];

$listaResponsaveis = [];
if ($isMaster) {
    $listaResponsaveis = $db->Query("SELECT ID_USUARIO, NOME FROM USUARIOS WHERE ATENDENTE = 1 ORDER BY NOME ASC");
}

// Busca Interações
$sqlInt = "SELECT I.*, U.NOME AS NOME_USUARIO 
           FROM INTERACOES_CHAMADOS I 
           INNER JOIN USUARIOS U ON I.ID_USUARIO = U.ID_USUARIO 
           WHERE I.ID_CHAMADO = $idChamado 
           ORDER BY I.ID_INTERACAO $ordemInteracao";
$interacoes = $db->Query($sqlInt);

// Busca Anexos
$anexosRaw = $db->Query("SELECT ID_ANEXO, ID_INTERACAO, NOME_ARQUIVO FROM ANEXOS_INTERACOES WHERE ID_CHAMADO = $idChamado");
$anexos = [];
foreach ($anexosRaw as $anx) {
    $anexos[$anx['ID_INTERACAO']][] = $anx;
}

// --- BUSCA APONTAMENTOS VINCULADOS AO CHAMADO ---
$sqlApontamentos = "SELECT AP.*, 
                           AG.DATA_AGENDAMENTO, 
                           U.NOME AS NOME_RESPONSAVEL
                    FROM APONTAMENTO_AGENDAMENTOS AP
                    INNER JOIN AGENDAMENTOS AG ON AP.ID_AGENDAMENTO = AG.ID_AGENDAMENTO
                    INNER JOIN USUARIOS U ON AG.ID_USUARIO = U.ID_USUARIO
                    WHERE AP.ID_CHAMADO = $idChamado
                    ORDER BY AG.DATA_AGENDAMENTO ASC, AP.HORA_INICIAL ASC";
$apontamentosChamado = $db->Query($sqlApontamentos) ?: [];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chamado #<?= $chamado['ID_CHAMADO'] ?> - PrimeSys</title>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/suporte.css">
    <style>
        .tabs-nav {
            display: flex;
            gap: 5px;
            border-bottom: 2px solid #e2e8f0;
            margin-bottom: 20px;
        }
        .tab-btn {
            padding: 10px 20px;
            background: #f1f5f9;
            border: none;
            border-top-left-radius: 6px;
            border-top-right-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            color: #475569;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .tab-btn.active {
            background: #001517;
            color: #fff;
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
            $sidebar = new Sidebar('chamados', $isMaster); 
            $sidebar->render(); 
        ?>

        <main class="main-content">
            <!-- Barra Superior de Navegação -->
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <a href="chamados.php" class="btn-3d btn-secondary-3d">
                    <i class="fa-solid fa-arrow-left"></i> Voltar aos Chamados
                </a>
                <span class="badge-tag" style="font-size: 14px; padding: 6px 12px; background: #001517; color: #fff;">
                    #<?= $chamado['ID_CHAMADO'] ?> - <?= htmlspecialchars($chamado['TIPO_CHAMADO']) ?>
                </span>
            </div>

            <!-- Abas de Navegação -->
            <div class="tabs-nav">
                <a href="ver_chamado.php?id=<?= $idChamado ?>&aba=historico" class="tab-btn <?= $abaAtiva === 'historico' ? 'active' : '' ?>">
                    <i class="fa-solid fa-comments"></i> Histórico / Interações
                </a>
                <a href="ver_chamado.php?id=<?= $idChamado ?>&aba=apontamentos" class="tab-btn <?= $abaAtiva === 'apontamentos' ? 'active' : '' ?>">
                    <i class="fa-solid fa-stopwatch"></i> Apontamentos (<?= count($apontamentosChamado) ?>)
                </a>
            </div>

            <!-- Grid de Detalhes -->
            <div class="ticket-view-layout">
                
                <!-- Coluna Principal -->
                <div class="ticket-main-column">
                    
                    <?php if ($abaAtiva === 'historico'): ?>
                        <!-- Novo Formulário de Interação no Topo -->
                        <div class="ticket-card-panel">
                            <h3 style="color: #001517; margin-bottom: 12px;"><i class="fa-solid fa-reply"></i> Nova Resposta / Movimentação</h3>
                            <form method="POST" action="ver_chamado.php?id=<?= $idChamado ?>&aba=historico" enctype="multipart/form-data">
                                <input type="hidden" name="acao" value="nova_interacao">
                                
                                <div class="form-group">
                                    <textarea name="mensagem" required placeholder="Digite a resposta ou atualização do atendimento..." rows="4"></textarea>
                                </div>

                                <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 10px; flex-wrap: wrap; gap: 10px;">
                                    <input type="file" name="anexo" style="font-size: 12px;">
                                    <button type="submit" class="btn-3d btn-success-3d">
                                        <i class="fa-solid fa-paper-plane"></i> Enviar Resposta
                                    </button>
                                </div>
                            </form>
                        </div>

                        <!-- Linha do Tempo / Histórico -->
                        <div class="ticket-card-panel" style="margin-top: 20px;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; border-bottom: 2px solid #f1f5f9; padding-bottom: 10px;">
                                <h3 style="color: #001517;"><i class="fa-solid fa-comments"></i> Histórico do Chamado</h3>
                                <a href="ver_chamado.php?id=<?= $idChamado ?>&aba=historico&ordem_int=<?= $ordemInteracao === 'ASC' ? 'DESC' : 'ASC' ?>" class="btn-3d btn-secondary-3d btn-sm-3d">
                                    <i class="fa-solid fa-sort"></i> Exibir: <?= $ordemInteracao === 'ASC' ? 'Mais Antigas Primeiro' : 'Mais Recentes Primeiro' ?>
                                </a>
                            </div>

                            <div class="timeline-page">
                                <?php if (empty($interacoes)): ?>
                                    <p style="text-align: center; color: #888; padding: 20px;">Nenhuma interação cadastrada.</p>
                                <?php else: ?>
                                    <?php foreach ($interacoes as $i): ?>
                                        <div class="timeline-item">
                                            <div class="timeline-item-header">
                                                <span><strong><?= htmlspecialchars($i['NOME_USUARIO']) ?></strong> em <?= date('d/m/Y', strtotime($i['DATA_INTERACAO'])) ?> às <?= $i['HORA_INTERACAO'] ?></span>
                                                <button class="btn-3d btn-secondary-3d btn-sm-3d" onclick="openEditarInteracao(<?= $i['ID_INTERACAO'] ?>, '<?= htmlspecialchars(addslashes($i['MENSAGEM'])) ?>')">
                                                    <i class="fa-solid fa-pen"></i> Editar
                                                </button>
                                            </div>
                                            <p style="font-size: 14px; color: #334155; white-space: pre-wrap; margin-top: 8px;"><?= htmlspecialchars($i['MENSAGEM']) ?></p>
                                            
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
                        </div>

                    <?php else: ?>
                        <!-- ABA DE APONTAMENTOS -->
                        <div class="ticket-card-panel">
                            <h3 style="color: #001517; margin-bottom: 15px; border-bottom: 2px solid #f1f5f9; padding-bottom: 10px;">
                                <i class="fa-solid fa-stopwatch"></i> Apontamentos Vinculados ao Chamado
                            </h3>

                            <?php if (empty($apontamentosChamado)): ?>
                                <p style="text-align: center; color: #888; padding: 30px;">Nenhum apontamento vinculado a este chamado.</p>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="custom-table" style="font-size: 13px; width: 100%;">
                                        <thead>
                                            <tr style="background: #f8fafc; text-align: left;">
                                                <th style="padding: 10px;">Responsável</th>
                                                <th style="padding: 10px;">Data</th>
                                                <th style="padding: 10px;">Início</th>
                                                <th style="padding: 10px;">Fim</th>
                                                <th style="padding: 10px; text-align: right;">Tempo Total</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            $dataAtual = '';
                                            $subtotalSegundosDia = 0;
                                            $totalGeralSegundos = 0;

                                            foreach ($apontamentosChamado as $index => $ap):
                                                list($h, $m, $s) = explode(':', $ap['TOTAL_HORAS_APONTAMENTO']);
                                                $segundosAp = ($h * 3600) + ($m * 60) + $s;
                                                $totalGeralSegundos += $segundosAp;

                                                // Mudança de data para subtotal
                                                if ($dataAtual !== '' && $dataAtual !== $ap['DATA_AGENDAMENTO']) {
                                                    $subH = floor($subtotalSegundosDia / 3600);
                                                    $subM = floor(($subtotalSegundosDia / 60) % 60);
                                                    $subS = $subtotalSegundosDia % 60;
                                                    $subtotalFormatado = sprintf('%02d:%02d:%02d', $subH, $subM, $subS);
                                                    
                                                    echo '<tr style="background: #f1f5f9; font-weight: bold;"><td colspan="4" style="text-align: right; padding: 8px;">Subtotal do dia ' . date('d/m/Y', strtotime($dataAtual)) . ':</td><td style="text-align: right; padding: 8px; color: #0284c7;">' . $subtotalFormatado . '</td></tr>';
                                                    
                                                    $subtotalSegundosDia = 0;
                                                }

                                                $dataAtual = $ap['DATA_AGENDAMENTO'];
                                                $subtotalSegundosDia += $segundosAp;
                                            ?>
                                                <tr style="border-bottom: 1px solid #f1f5f9;">
                                                    <td style="padding: 10px;"><?= htmlspecialchars($ap['NOME_RESPONSAVEL']) ?></td>
                                                    <td style="padding: 10px;"><?= date('d/m/Y', strtotime($ap['DATA_AGENDAMENTO'])) ?></td>
                                                    <td style="padding: 10px;"><?= substr($ap['HORA_INICIAL'], 0, 5) ?></td>
                                                    <td style="padding: 10px;"><?= substr($ap['HORA_FINAL'], 0, 5) ?></td>
                                                    <td style="padding: 10px; text-align: right; font-weight: bold;"><?= $ap['TOTAL_HORAS_APONTAMENTO'] ?></td>
                                                </tr>

                                                <?php
                                                // Se for o último registro, exibe o subtotal do último dia
                                                if ($index === count($apontamentosChamado) - 1):
                                                    $subH = floor($subtotalSegundosDia / 3600);
                                                    $subM = floor(($subtotalSegundosDia / 60) % 60);
                                                    $subS = $subtotalSegundosDia % 60;
                                                    $subtotalFormatado = sprintf('%02d:%02d:%02d', $subH, $subM, $subS);
                                                ?>
                                                    <tr style="background: #f1f5f9; font-weight: bold;">
                                                        <td colspan="4" style="text-align: right; padding: 8px;">Subtotal do dia <?= date('d/m/Y', strtotime($dataAtual)) ?>:</td>
                                                        <td style="text-align: right; padding: 8px; color: #0284c7;"><?= $subtotalFormatado ?></td>
                                                    </tr>
                                                <?php endif; ?>

                                            <?php endforeach; ?>

                                            <?php
                                            // Totalizador Geral
                                            $totH = floor($totalGeralSegundos / 3600);
                                            $totM = floor(($totalGeralSegundos / 60) % 60);
                                            $totS = $totalGeralSegundos % 60;
                                            $totalGeralFormatado = sprintf('%02d:%02d:%02d', $totH, $totM, $totS);
                                            ?>
                                            <tr style="background: #001517; color: #fff; font-weight: bold; font-size: 14px;">
                                                <td colspan="4" style="text-align: right; padding: 12px;">Total Geral de Horas do Chamado:</td>
                                                <td style="text-align: right; padding: 12px; color: #10b981;"><?= $totalGeralFormatado ?></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                </div>

                <!-- Coluna Lateral: Propriedades e Gerenciamento -->
                <div class="ticket-sidebar-column">

                    <!-- GERENCIAMENTO DE RESPONSÁVEL -->
                    <div class="ticket-card-panel" style="margin-bottom: 15px; border-top: 4px solid #001517;">
                        <h3 style="color: #001517; margin-bottom: 12px; border-bottom: 1px solid #eee; padding-bottom: 8px;">
                            <i class="fa-solid fa-user-gear"></i> Responsável
                        </h3>

                        <div style="margin-bottom: 12px; font-size: 13px;">
                            <strong>Atual:</strong> 
                            <?php if (!empty($chamado['NOME_RESPONSAVEL'])): ?>
                                <span class="badge-tag" style="background: #16a34a; color: #fff; font-size: 12px; margin-left: 5px;">
                                    <i class="fa-solid fa-user-check"></i> <?= htmlspecialchars($chamado['NOME_RESPONSAVEL']) ?>
                                </span>
                            <?php else: ?>
                                <span class="badge-tag" style="background: #dc2626; color: #fff; font-size: 12px; margin-left: 5px;">
                                    <i class="fa-solid fa-user-xmark"></i> Não atribuído
                                </span>
                            <?php endif; ?>
                        </div>

                        <?php if ($chamado['ID_RESPONSAVEL'] != $idUsuarioLogado): ?>
                            <form method="POST" action="ver_chamado.php?id=<?= $idChamado ?>&aba=<?= $abaAtiva ?>" style="margin-bottom: 10px;">
                                <input type="hidden" name="acao" value="assumir_responsavel">
                                <button type="submit" class="btn-3d btn-secondary-3d" style="width: 100%; justify-content: center;">
                                    <i class="fa-solid fa-hand-holding-hand"></i> Assumir Chamado
                                </button>
                            </form>
                        <?php endif; ?>

                        <?php if ($isMaster): ?>
                            <form method="POST" action="ver_chamado.php?id=<?= $idChamado ?>&aba=<?= $abaAtiva ?>" style="margin-top: 10px; border-top: 1px dashed #cbd5e1; padding-top: 10px;">
                                <input type="hidden" name="acao" value="atribuir_responsavel">
                                <div class="form-group" style="margin-bottom: 8px;">
                                    <label style="font-size: 11px; font-weight: bold;">Atribuir a outro usuário:</label>
                                    <select name="id_responsavel" required style="padding: 6px; border-radius:4px; border:1px solid #cbd5e1; width: 100%; font-size: 12px;">
                                        <option value="">-- Selecione o Atendente --</option>
                                        <?php foreach ($listaResponsaveis as $resp): ?>
                                            <option value="<?= $resp['ID_USUARIO'] ?>" <?= $chamado['ID_RESPONSAVEL'] == $resp['ID_USUARIO'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($resp['NOME']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <button type="submit" class="btn-3d btn-primary-3d" style="width: 100%; justify-content: center; font-size: 12px;">
                                    <i class="fa-solid fa-user-plus"></i> Confirmar Atribuição
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>

                    <!-- GERENCIAR PARÂMETROS DO CHAMADO -->
                    <div class="ticket-card-panel">
                        <h3 style="color: #001517; margin-bottom: 15px; border-bottom: 1px solid #eee; padding-bottom: 8px;">
                            <i class="fa-solid fa-sliders"></i> Gerenciar Chamado
                        </h3>

                        <form method="POST" action="ver_chamado.php?id=<?= $idChamado ?>&aba=<?= $abaAtiva ?>">
                            <input type="hidden" name="acao" value="atualizar_chamado">
                            
                            <div class="form-group" style="margin-bottom: 12px;">
                                <label>Etapa Atual:</label>
                                <select name="etapa_chamado" style="padding: 8px; border-radius:4px; border:1px solid #cbd5e1; width: 100%;">
                                    <?php foreach ($etapasDefinidas as $et): ?>
                                        <option value="<?= $et ?>" <?= $chamado['ETAPA_CHAMADO'] === $et ? 'selected' : '' ?>><?= $et ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group" style="margin-bottom: 12px;">
                                <label>Tipo de Atendimento:</label>
                                <select name="tipo_chamado" style="padding: 8px; border-radius:4px; border:1px solid #cbd5e1; width: 100%;">
                                    <?php foreach ($tiposDefinidos as $tp): ?>
                                        <option value="<?= $tp ?>" <?= $chamado['TIPO_CHAMADO'] === $tp ? 'selected' : '' ?>><?= $tp ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group" style="margin-bottom: 15px;">
                                <label>Previsão de Conclusão:</label>
                                <input type="date" name="data_previsao" value="<?= $chamado['DATA_PREVISAO'] ?>" style="padding: 8px; border-radius:4px; border:1px solid #cbd5e1; width: 100%;">
                            </div>

                            <button type="submit" class="btn-3d btn-primary-3d" style="width: 100%; justify-content: center;">
                                <i class="fa-solid fa-rotate"></i> Salvar Alterações
                            </button>
                        </form>
                    </div>

                    <!-- Métricas do Chamado -->
                    <div class="ticket-card-panel" style="margin-top: 15px;">
                        <h3 style="color: #001517; margin-bottom: 12px; border-bottom: 1px solid #eee; padding-bottom: 8px;">
                            <i class="fa-solid fa-circle-info"></i> Informações
                        </h3>

                        <ul class="info-list">
                            <li><strong>Cliente:</strong> <?= htmlspecialchars($chamado['NOME_CLIENTE']) ?></li>
                            <li><strong>Solicitante:</strong> <?= htmlspecialchars($chamado['NOME_USUARIO']) ?></li>
                            <li>
                                <strong>Responsável:</strong> 
                                <?= !empty($chamado['NOME_RESPONSAVEL']) ? htmlspecialchars($chamado['NOME_RESPONSAVEL']) : '<em style="color:#dc2626;">Pendente</em>' ?>
                            </li>
                            <li><strong>Abertura:</strong> <?= date('d/m/Y', strtotime($chamado['DATA_ABERTURA'])) ?></li>
                            <li><strong>Última Movimentação:</strong> <?= date('d/m/Y', strtotime($chamado['ULTIMA_MOV'])) ?></li>
                            <li>
                                <strong>Tempo de Existência:</strong> 
                                <?php 
                                    $dtInicio = new DateTime($chamado['DATA_ABERTURA']);
                                    $dtFim = !empty($chamado['DATA_CONCLUSAO']) ? new DateTime($chamado['DATA_CONCLUSAO']) : new DateTime();
                                    echo $dtInicio->diff($dtFim)->days . ' dia(s)';
                                ?>
                            </li>
                            <li>
                                <strong>Status Previsão:</strong>
                                <?php 
                                    if (!empty($chamado['DATA_PREVISAO'])) {
                                        $dtPrevisao = new DateTime($chamado['DATA_PREVISAO']);
                                        $hoje = new DateTime();
                                        if ($hoje > $dtPrevisao && empty($chamado['DATA_CONCLUSAO'])) {
                                            echo "<span style='color:#e53e3e; font-weight:bold;'>Atrasado</span>";
                                        } else {
                                            echo $hoje->diff($dtPrevisao)->format('%r%a dia(s)');
                                        }
                                    } else {
                                        echo "Não definida";
                                    }
                                ?>
                            </li>
                        </ul>
                    </div>
                </div>

            </div>
        </main>
    </div>

    <!-- MODAL EDITAR INTERAÇÃO -->
    <div class="modal-overlay" id="editarInteracaoModal">
        <div class="modal-card">
            <div class="modal-header">
                <h3>Editar Interação</h3>
                <i class="fa-solid fa-xmark" style="cursor: pointer;" onclick="closeEditarInteracao()"></i>
            </div>
            <form method="POST" action="ver_chamado.php?id=<?= $idChamado ?>&aba=<?= $abaAtiva ?>" enctype="multipart/form-data">
                <input type="hidden" name="acao" value="editar_interacao">
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
        function openEditarInteracao(id, msg) {
            document.getElementById('edit_id_interacao').value = id;
            document.getElementById('edit_mensagem').value = msg;
            document.getElementById('editarInteracaoModal').classList.add('active');
        }
        function closeEditarInteracao() {
            document.getElementById('editarInteracaoModal').classList.remove('active');
        }
    </script>
</body>
</html>