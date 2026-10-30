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

$msgErro = '';
$msgSucesso = '';

// --- PROCESSAMENTO DE FORMULÁRIOS (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';

    // 1. Salvar / Editar Parceiro
    if ($acao === 'salvar_parceiro') {
        $idParceiro = (int)($_POST['id_parceiro'] ?? 0);
        $razaoSocial = addslashes(trim($_POST['razao_social']));
        $cnpj = addslashes(trim($_POST['cnpj'] ?? ''));
        $telefone = addslashes(trim($_POST['telefone']));
        $contato = addslashes(trim($_POST['contato'] ?? ''));
        $email = addslashes(trim($_POST['email'] ?? ''));
        $dataIni = !empty($_POST['data_ini_periodo']) ? "'" . $_POST['data_ini_periodo'] . "'" : "NULL";
        $dataFim = !empty($_POST['data_fim_periodo']) ? "'" . $_POST['data_fim_periodo'] . "'" : "NULL";
        $diaFaturamento = (int)($_POST['dia_faturamento'] ?? 1);
        $consideraUltimoDia = isset($_POST['considers_ultimo_dia']) ? 1 : 0;

        if (empty($razaoSocial) || empty($telefone)) {
            $msgErro = "A Razão Social e o Telefone são de preenchimento obrigatório!";
        } else {
            if ($idParceiro > 0) {
                $sql = "UPDATE PARCEIROS SET 
                            RAZAO_SOCIAL = '$razaoSocial', 
                            CNPJ = '$cnpj', 
                            TELEFONE = '$telefone', 
                            CONTATO = '$contato', 
                            EMAIL = '$email', 
                            DATA_INI_PERIODO = $dataIni, 
                            DATA_FIM_PERIODO = $dataFim, 
                            DIA_FATURAMENTO = $diaFaturamento, 
                            CONSIDERA_ULTIMO_DIA_DO_MES = $consideraUltimoDia 
                        WHERE ID_PARCEIRO = $idParceiro";
                $db->Query($sql);
                $msgSucesso = "Parceiro atualizado com sucesso!";
            } else {
                $sql = "INSERT INTO PARCEIROS (RAZAO_SOCIAL, CNPJ, TELEFONE, CONTATO, EMAIL, DATA_INI_PERIODO, DATA_FIM_PERIODO, DIA_FATURAMENTO, CONSIDERA_ULTIMO_DIA_DO_MES) 
                        VALUES ('$razaoSocial', '$cnpj', '$telefone', '$contato', '$email', $dataIni, $dataFim, $diaFaturamento, $consideraUltimoDia)";
                $db->Query($sql);
                $msgSucesso = "Parceiro cadastrado com sucesso!";
            }
        }
    }

    // 2. Excluir Parceiro (Com validação se está em uso em CLIENTES)
    if ($acao === 'excluir_parceiro') {
        $idParceiro = (int)$_POST['id_parceiro'];

        $resUso = $db->Query("SELECT COUNT(*) AS QTD FROM CLIENTES WHERE ID_PARCEIRO = $idParceiro");
        $qtdVinculos = $resUso[0]['QTD'] ?? 0;

        if ($qtdVinculos > 0) {
            $msgErro = "Não é possível excluir este parceiro pois ele está vinculado a $qtdVinculos cliente(s) cadastrado(s).";
        } else {
            $db->Query("DELETE FROM PARCEIROS WHERE ID_PARCEIRO = $idParceiro");
            $msgSucesso = "Parceiro excluído com sucesso!";
        }
    }
}

// --- FILTRO / BUSCA ---
$busca = $_GET['busca'] ?? '';
$where = "";
if (!empty($busca)) {
    $buscaEsc = addslashes($busca);
    $where = " WHERE RAZAO_SOCIAL LIKE '%$buscaEsc%' OR CNPJ LIKE '%$buscaEsc%' OR CONTATO LIKE '%$buscaEsc%'";
}

$parceiros = $db->Query("SELECT * FROM PARCEIROS $where ORDER BY RAZAO_SOCIAL ASC") ?: [];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gerenciamento de Parceiros - PrimeSys</title>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" />
    <link rel="stylesheet" href="css/suporte.css">
    <style>
        .parceiros-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        .parceiro-card {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.06);
            border: 1px solid #e2e8f0;
            display: flex;
            flex-direction: column;
            position: relative;
            overflow: hidden;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .parceiro-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 16px rgba(0,0,0,0.1);
        }
        .parceiro-card-header {
            background: #001517;
            color: #fff;
            padding: 14px 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .parceiro-id-badge {
            background: rgba(255,255,255,0.15);
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
        }
        .parceiro-card-body {
            padding: 16px;
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .parceiro-nome {
            font-size: 16px;
            font-weight: 700;
            color: #0f172a;
        }
        .parceiro-info-row {
            font-size: 13px;
            color: #475569;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .parceiro-info-row i {
            color: #001517;
            width: 16px;
            text-align: center;
        }
        .parceiro-card-footer {
            background: #f8fafc;
            padding: 12px 16px;
            border-top: 1px solid #e2e8f0;
            display: flex;
            justify-content: flex-end;
            gap: 8px;
        }
        .alert-box {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .alert-success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
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
            $sidebar = new Sidebar('parceiros', $isMaster); 
            $sidebar->render(); 
        ?>

        <main class="main-content">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px;">
                <h1 class="page-title" style="margin-bottom: 0;"><i class="fa-solid fa-handshake"></i> Gerenciamento de Parceiros</h1>
                
                <button type="button" class="btn-3d btn-primary-3d" onclick="openParceiroModal()">
                    <i class="fa-solid fa-plus"></i> Novo Parceiro
                </button>
            </div>

            <?php if (!empty($msgSucesso)): ?>
                <div class="alert-box alert-success"><i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($msgSucesso) ?></div>
            <?php endif; ?>
            <?php if (!empty($msgErro)): ?>
                <div class="alert-box alert-error"><i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($msgErro) ?></div>
            <?php endif; ?>

            <!-- FILTROS DE PESQUISA -->
            <div class="filters-card">
                <form method="GET" action="parceiros.php" class="filters-grid" style="grid-template-columns: 1fr auto;">
                    <div class="form-group" style="margin-bottom: 0;">
                        <label>Pesquisar Parceiro:</label>
                        <input type="text" name="busca" value="<?= htmlspecialchars($busca) ?>" placeholder="Busque por Razão Social, CNPJ ou Contato...">
                    </div>
                    <div class="filters-actions" style="display: flex; gap: 10px; align-items: flex-end;">
                        <button type="submit" class="btn-3d btn-secondary-3d"><i class="fa-solid fa-magnifying-glass"></i> Buscar</button>
                        <?php if (!empty($busca)): ?>
                            <a href="parceiros.php" class="btn-3d btn-danger-3d" style="text-decoration: none; display: inline-flex; align-items: center; justify-content: center; height: 38px; padding: 0 15px;"><i class="fa-solid fa-xmark"></i> Limpar</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- LISTAGEM EM CARDS -->
            <?php if (empty($parceiros)): ?>
                <div style="text-align: center; padding: 40px; background: #fff; border-radius: 8px; border: 1px solid #e2e8f0; color: #64748b;">
                    <i class="fa-regular fa-folder-open" style="font-size: 48px; margin-bottom: 10px; color: #cbd5e1;"></i>
                    <p>Nenhum parceiro encontrado.</p>
                </div>
            <?php else: ?>
                <div class="parceiros-grid">
                    <?php foreach ($parceiros as $p): ?>
                        <div class="parceiro-card">
                            <div class="parceiro-card-header">
                                <span style="font-size: 13px; font-weight: 600;"><i class="fa-regular fa-id-card"></i> CNPJ: <?= !empty($p['CNPJ']) ? htmlspecialchars($p['CNPJ']) : 'Não informado' ?></span>
                                <span class="parceiro-id-badge">#<?= $p['ID_PARCEIRO'] ?></span>
                            </div>
                            <div class="parceiro-card-body">
                                <div class="parceiro-nome"><?= htmlspecialchars($p['RAZAO_SOCIAL']) ?></div>
                                
                                <div class="parceiro-info-row">
                                    <i class="fa-solid fa-phone"></i> <span><?= htmlspecialchars($p['TELEFONE']) ?></span>
                                </div>
                                <div class="parceiro-info-row">
                                    <i class="fa-solid fa-envelope"></i> <span><?= !empty($p['EMAIL']) ? htmlspecialchars($p['EMAIL']) : 'E-mail não informado' ?></span>
                                </div>
                                <div class="parceiro-info-row">
                                    <i class="fa-solid fa-user-tie"></i> <span>Contato: <?= !empty($p['CONTATO']) ? htmlspecialchars($p['CONTATO']) : '-' ?></span>
                                </div>
                                <div class="parceiro-info-row" style="margin-top: 5px; border-top: 1px solid #f1f5f9; padding-top: 8px;">
                                    <i class="fa-solid fa-calendar-days"></i> <span>Faturamento: Dia <strong><?= $p['DIA_FATURAMENTO'] ?></strong> <?= $p['CONSIDERA_ULTIMO_DIA_DO_MES'] ? '(Último dia)' : '' ?></span>
                                </div>
                            </div>
                            <div class="parceiro-card-footer">
                                <button type="button" class="btn-3d btn-secondary-3d btn-sm-3d" onclick='openParceiroModal(<?= json_encode($p) ?>)'>
                                    <i class="fa-solid fa-pen"></i> Editar
                                </button>
                                <form method="POST" action="parceiros.php" style="display:inline;" onsubmit="return confirm('Deseja realmente excluir este parceiro?');">
                                    <input type="hidden" name="acao" value="excluir_parceiro">
                                    <input type="hidden" name="id_parceiro" value="<?= $p['ID_PARCEIRO'] ?>">
                                    <button type="submit" class="btn-3d btn-danger-3d btn-sm-3d">
                                        <i class="fa-solid fa-trash"></i> Excluir
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        </main>
    </div>

    <!-- MODAL: CADASTRAR / EDITAR PARCEIRO -->
    <div class="modal-overlay" id="parceiroModal">
        <div class="modal-card" style="max-width: 700px;">
            <div class="modal-header">
                <h3 id="modalParceiroTitle"><i class="fa-solid fa-handshake-angle"></i> Novo Parceiro</h3>
                <i class="fa-solid fa-xmark" style="cursor: pointer;" onclick="closeParceiroModal()"></i>
            </div>
            <form method="POST" action="parceiros.php">
                <input type="hidden" name="acao" value="salvar_parceiro">
                <input type="hidden" name="id_parceiro" id="modal_id_parceiro">
                
                <div class="modal-body">
                    <div class="form-grid">
                        <div class="form-group full-width">
                            <label>Razão Social <span class="required-asterisk">*</span></label>
                            <input type="text" name="razao_social" id="modal_razao_social" required placeholder="Nome ou Razão Social do parceiro...">
                        </div>

                        <div class="form-group">
                            <label>CNPJ</label>
                            <input type="text" name="cnpj" id="modal_cnpj" placeholder="00.000.000/0000-00">
                        </div>

                        <div class="form-group">
                            <label>Telefone <span class="required-asterisk">*</span></label>
                            <input type="text" name="telefone" id="modal_telefone" required placeholder="(00) 00000-0000">
                        </div>

                        <div class="form-group">
                            <label>Nome do Contato</label>
                            <input type="text" name="contato" id="modal_contato" placeholder="Responsável...">
                        </div>

                        <div class="form-group">
                            <label>E-mail</label>
                            <input type="email" name="email" id="modal_email" placeholder="email@parceiro.com">
                        </div>

                        <div class="form-group">
                            <label>Data Início Período</label>
                            <input type="date" name="data_ini_periodo" id="modal_data_ini_periodo">
                        </div>

                        <div class="form-group">
                            <label>Data Fim Período</label>
                            <input type="date" name="data_fim_periodo" id="modal_data_fim_periodo">
                        </div>

                        <div class="form-group">
                            <label>Dia de Faturamento <span class="required-asterisk">*</span></label>
                            <input type="number" name="dia_faturamento" id="modal_dia_faturamento" min="1" max="31" value="10" required>
                        </div>

                        <div class="form-group" style="display: flex; align-items: center; gap: 10px; margin-top: 25px;">
                            <input type="checkbox" name="considers_ultimo_dia" id="modal_considers_ultimo_dia" value="1" style="width: 18px; height: 18px; cursor: pointer;">
                            <label for="modal_considers_ultimo_dia" style="cursor: pointer; margin-bottom: 0; font-weight: 600; color: #0f172a;">Considera último dia do mês</label>
                        </div>
                    </div>

                    <div class="modal-footer" style="margin-top: 20px;">
                        <button type="button" class="btn-3d btn-danger-3d" onclick="closeParceiroModal()">Cancelar</button>
                        <button type="submit" class="btn-3d btn-success-3d"><i class="fa-solid fa-floppy-disk"></i> Salvar Parceiro</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openParceiroModal(data = null) {
            if (data) {
                document.getElementById('modalParceiroTitle').innerHTML = '<i class="fa-solid fa-pen-to-square"></i> Editar Parceiro #' + data.ID_PARCEIRO;
                document.getElementById('modal_id_parceiro').value = data.ID_PARCEIRO;
                document.getElementById('modal_razao_social').value = data.RAZAO_SOCIAL || '';
                document.getElementById('modal_cnpj').value = data.CNPJ || '';
                document.getElementById('modal_telefone').value = data.TELEFONE || '';
                document.getElementById('modal_contato').value = data.CONTATO || '';
                document.getElementById('modal_email').value = data.EMAIL || '';
                document.getElementById('modal_data_ini_periodo').value = data.DATA_INI_PERIODO || '';
                document.getElementById('modal_data_fim_periodo').value = data.DATA_FIM_PERIODO || '';
                document.getElementById('modal_dia_faturamento').value = data.DIA_FATURAMENTO || 10;
                document.getElementById('modal_considers_ultimo_dia').checked = (data.CONSIDERA_ULTIMO_DIA_DO_MES == 1);
            } else {
                document.getElementById('modalParceiroTitle').innerHTML = '<i class="fa-solid fa-handshake-angle"></i> Novo Parceiro';
                document.getElementById('modal_id_parceiro').value = '';
                document.getElementById('modal_razao_social').value = '';
                document.getElementById('modal_cnpj').value = '';
                document.getElementById('modal_telefone').value = '';
                document.getElementById('modal_contato').value = '';
                document.getElementById('modal_email').value = '';
                document.getElementById('modal_data_ini_periodo').value = '';
                document.getElementById('modal_data_fim_periodo').value = '';
                document.getElementById('modal_dia_faturamento').value = '10';
                document.getElementById('modal_considers_ultimo_dia').checked = false;
            }
            document.getElementById('parceiroModal').classList.add('active');
        }

        function closeParceiroModal() {
            document.getElementById('parceiroModal').classList.remove('active');
        }
    </script>
</body>
</html>