<?php
session_start();

if (!isset($_SESSION['id_usuario']) || ($_SESSION['id_cliente'] ?? 0) <= 0) {
    header("Location: index.php?error=" . urlencode("Acesso negado."));
    exit;
}

require_once __DIR__ . '/php/database.php';

$idUsuarioLogado = $_SESSION['id_usuario'];
$idClienteLogado = $_SESSION['id_cliente'];
$nomeUsuario = $_SESSION['nome'] ?? 'Cliente';
$db = new database();

$idChamado = (int)($_GET['id'] ?? 0);
if ($idChamado <= 0) {
    header("Location: home_cliente.php");
    exit;
}

$ordemInteracao = $_GET['ordem_int'] ?? 'ASC';
$abaAtiva = $_GET['aba'] ?? 'historico';

// --- PROCESSAMENTO DE AÇÕES DO CLIENTE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';

    // Nova Resposta
    if ($acao === 'nova_interacao') {
        $mensagem = addslashes($_POST['mensagem']);

        $agora = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));
        $dataHoje = $agora->format('Y-m-d');
        $horaHoje = $agora->format('H:i:s');

        $sql = "INSERT INTO INTERACOES_CHAMADOS (DATA_INTERACAO, HORA_INTERACAO, ID_CHAMADO, ID_USUARIO, ID_EMPRESA, MENSAGEM)
                VALUES ('$dataHoje', '$horaHoje', $idChamado, $idUsuarioLogado, $idClienteLogado, '$mensagem')";
        $db->Query($sql);

        $resInt = $db->Query("SELECT ID_INTERACAO FROM INTERACOES_CHAMADOS WHERE ID_CHAMADO = $idChamado ORDER BY ID_INTERACAO DESC LIMIT 1");
        $idInteracao = $resInt[0]['ID_INTERACAO'];

        $db->Query("UPDATE CHAMADOS SET ULTIMA_MOV = '$dataHoje' WHERE ID_CHAMADO = $idChamado");

        // Anexo enviado pelo cliente
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

        header("Location: ver_chamado_cliente.php?id=$idChamado&aba=historico");
        exit;
    }

    // Editar APENAS a própria mensagem
    if ($acao === 'editar_interacao') {
        $idInteracao = (int)$_POST['id_interacao'];
        $mensagem = addslashes($_POST['mensagem']);

        $db->Query("UPDATE INTERACOES_CHAMADOS SET MENSAGEM = '$mensagem' WHERE ID_INTERACAO = $idInteracao AND ID_USUARIO = $idUsuarioLogado");

        header("Location: ver_chamado_cliente.php?id=$idChamado&aba=historico");
        exit;
    }
}

// --- BUSCA E VALIDAÇÃO DO CHAMADO DO CLIENTE ---
$resCh = $db->Query("SELECT CH.*, 
                            U.NOME AS NOME_USUARIO,
                            RESP.NOME AS NOME_RESPONSAVEL
                     FROM CHAMADOS CH 
                     INNER JOIN USUARIOS U ON CH.ID_USUARIO = U.ID_USUARIO 
                     LEFT JOIN USUARIOS RESP ON CH.ID_RESPONSAVEL = RESP.ID_USUARIO
                     WHERE CH.ID_CHAMADO = $idChamado AND CH.ID_CLIENTE = $idClienteLogado");

if (empty($resCh)) {
    header("Location: home_cliente.php");
    exit;
}
$chamado = $resCh[0];

// Busca Interações
$sqlInt = "SELECT I.*, U.NOME AS NOME_USUARIO 
           FROM INTERACOES_CHAMADOS I 
           INNER JOIN USUARIOS U ON I.ID_USUARIO = U.ID_USUARIO 
           WHERE I.ID_CHAMADO = $idChamado 
           ORDER BY I.ID_INTERACAO $ordemInteracao";
$interacoes = $db->Query($sqlInt) ?: [];

// Busca Anexos
$anexosRaw = $db->Query("SELECT ID_ANEXO, ID_INTERACAO, NOME_ARQUIVO FROM ANEXOS_INTERACOES WHERE ID_CHAMADO = $idChamado") ?: [];
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
    <title>Acompanhar Chamado #<?= $chamado['ID_CHAMADO'] ?> - PrimeSys</title>
    
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
        <div class="brand"><i class="fa-solid fa-headset"></i> Área do Cliente</div>
        <div class="user-info">
            <span><i class="fa-solid fa-user"></i> <strong><?= htmlspecialchars($nomeUsuario) ?></strong></span>
            <a href="logout.php" class="btn-logout"><i class="fa-solid fa-right-from-bracket"></i> Sair</a>
        </div>
    </header>

    <main class="client-main-content">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <a href="home_cliente.php" class="btn-3d btn-secondary-3d">
                <i class="fa-solid fa-arrow-left"></i> Voltar às Minhas Solicitações
            </a>
            <span class="badge-tag" style="font-size: 14px; padding: 6px 12px; background: #001517; color: #fff;">
                #<?= $chamado['ID_CHAMADO'] ?> - <?= htmlspecialchars($chamado['TIPO_CHAMADO']) ?>
            </span>
        </div>

        <!-- Abas de Navegação -->
        <div class="tabs-nav">
            <a href="ver_chamado_cliente.php?id=<?= $idChamado ?>&aba=historico" class="tab-btn <?= $abaAtiva === 'historico' ? 'active' : '' ?>">
                <i class="fa-solid fa-comments"></i> Histórico / Interações
            </a>
            <a href="ver_chamado_cliente.php?id=<?= $idChamado ?>&aba=apontamentos" class="tab-btn <?= $abaAtiva === 'apontamentos' ? 'active' : '' ?>">
                <i class="fa-solid fa-stopwatch"></i> Apontamentos (<?= count($apontamentosChamado) ?>)
            </a>
        </div>

        <div class="ticket-view-layout">
            
            <!-- Coluna Principal -->
            <div class="ticket-main-column">
                
                <?php if ($abaAtiva === 'historico'): ?>
                    <div class="ticket-card-panel">
                        <h3 style="color: #001517; margin-bottom: 12px;"><i class="fa-solid fa-reply"></i> Adicionar Resposta</h3>
                        <form method="POST" action="ver_chamado_cliente.php?id=<?= $idChamado ?>&aba=historico" enctype="multipart/form-data">
                            <input type="hidden" name="acao" value="nova_interacao">
                            
                            <div class="form-group">
                                <textarea name="mensagem" required placeholder="Digite sua dúvida ou informação complementar..." rows="4"></textarea>
                            </div>

                            <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 10px; flex-wrap: wrap; gap: 10px;">
                                <input type="file" name="anexo" style="font-size: 12px;">
                                <button type="submit" class="btn-3d btn-success-3d">
                                    <i class="fa-solid fa-paper-plane"></i> Enviar Mensagem
                                </button>
                            </div>
                        </form>
                    </div>

                    <div class="ticket-card-panel" style="margin-top: 20px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; border-bottom: 2px solid #f1f5f9; padding-bottom: 10px;">
                            <h3 style="color: #001517;"><i class="fa-solid fa-comments"></i> Histórico da Solicitação</h3>
                            <a href="ver_chamado_cliente.php?id=<?= $idChamado ?>&aba=historico&ordem_int=<?= $ordemInteracao === 'ASC' ? 'DESC' : 'ASC' ?>" class="btn-3d btn-secondary-3d btn-sm-3d">
                                <i class="fa-solid fa-sort"></i> Exibir: <?= $ordemInteracao === 'ASC' ? 'Mais Antigas' : 'Mais Recentes' ?>
                            </a>
                        </div>

                        <div class="timeline-page">
                            <?php foreach ($interacoes as $i): ?>
                                <div class="timeline-item">
                                    <div class="timeline-item-header">
                                        <span><strong><?= htmlspecialchars($i['NOME_USUARIO']) ?></strong> em <?= date('d/m/Y', strtotime($i['DATA_INTERACAO'])) ?> às <?= $i['HORA_INTERACAO'] ?></span>
                                        
                                        <?php if ((int)$i['ID_USUARIO'] === (int)$idUsuarioLogado): ?>
                                            <button class="btn-3d btn-secondary-3d btn-sm-3d" onclick="openEditarInteracao(<?= $i['ID_INTERACAO'] ?>, '<?= htmlspecialchars(addslashes($i['MENSAGEM'])) ?>')">
                                                <i class="fa-solid fa-pen"></i> Editar
                                            </button>
                                        <?php endif; ?>
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
                        </div>
                    </div>

                <?php else: ?>
                    <!-- ABA DE APONTAMENTOS DO CLIENTE -->
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

            <!-- Coluna Lateral Informativa -->
            <div class="ticket-sidebar-column">
                <div class="ticket-card-panel">
                    <h3 style="color: #001517; margin-bottom: 12px; border-bottom: 1px solid #eee; padding-bottom: 8px;">
                        <i class="fa-solid fa-circle-info"></i> Status da Solicitação
                    </h3>

                    <ul class="info-list">
                        <li><strong>Etapa Atual:</strong> <span class="badge-tag" style="background: #001517; color:#fff;"><?= $chamado['ETAPA_CHAMADO'] ?></span></li>
                        <li><strong>Tipo:</strong> <?= htmlspecialchars($chamado['TIPO_CHAMADO']) ?></li>
                        <li><strong>Solicitante:</strong> <?= htmlspecialchars($chamado['NOME_USUARIO']) ?></li>
                        <li>
                            <strong>Atendente Responsável:</strong> 
                            <?php if (!empty($chamado['NOME_RESPONSAVEL'])): ?>
                                <span style="color: #16a34a; font-weight: bold;">
                                    <i class="fa-solid fa-user-check"></i> <?= htmlspecialchars($chamado['NOME_RESPONSAVEL']) ?>
                                </span>
                            <?php else: ?>
                                <span style="color: #64748b; font-style: italic;">Aguardando atribuição</span>
                            <?php endif; ?>
                        </li>
                        <li><strong>Abertura:</strong> <?= date('d/m/Y', strtotime($chamado['DATA_ABERTURA'])) ?></li>
                        <li><strong>Última Resposta:</strong> <?= date('d/m/Y', strtotime($chamado['ULTIMA_MOV'])) ?></li>
                        <li>
                            <strong>Previsão de Solução:</strong> 
                            <?= !empty($chamado['DATA_PREVISAO']) ? date('d/m/Y', strtotime($chamado['DATA_PREVISAO'])) : 'Em análise' ?>
                        </li>
                    </ul>
                </div>
            </div>

        </div>
    </main>

    <!-- MODAL EDITAR APENAS PRÓPRIA INTERAÇÃO -->
    <div class="modal-overlay" id="editarInteracaoModal">
        <div class="modal-card">
            <div class="modal-header">
                <h3>Editar Minha Mensagem</h3>
                <i class="fa-solid fa-xmark" style="cursor: pointer;" onclick="closeEditarInteracao()"></i>
            </div>
            <form method="POST" action="ver_chamado_cliente.php?id=<?= $idChamado ?>&aba=<?= $abaAtiva ?>">
                <input type="hidden" name="acao" value="editar_interacao">
                <input type="hidden" name="id_interacao" id="edit_id_interacao">
                
                <div class="modal-body">
                    <div class="form-group">
                        <label>Mensagem:</label>
                        <textarea name="mensagem" id="edit_mensagem" required rows="4"></textarea>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn-3d btn-danger-3d" onclick="closeEditarInteracao()">Cancelar</button>
                        <button type="submit" class="btn-3d btn-success-3d"><i class="fa-solid fa-floppy-disk"></i> Salvar</button>
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