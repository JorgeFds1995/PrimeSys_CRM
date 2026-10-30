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

    // 1. Salvar / Editar Projeto
    if ($acao === 'salvar_projeto') {
        $idProjeto = (int)($_POST['id_projeto'] ?? 0);
        $nomeProjeto = addslashes(trim($_POST['nome_projeto']));
        $descricao = addslashes(trim($_POST['descricao'] ?? ''));
        $idCliente = (int)$_POST['id_cliente'];
        $statusProjeto = $_POST['status_projeto'] ?? 'A';

        if (empty($nomeProjeto) || $idCliente <= 0) {
            $msgErro = "O Nome do Projeto e o Cliente são de preenchimento obrigatório!";
        } else {
            if ($idProjeto > 0) {
                $sql = "UPDATE PROJETOS SET 
                            NOME_PROJETO = '$nomeProjeto', 
                            DESCRICAO = '$descricao', 
                            ID_CLIENTE = $idCliente, 
                            STATUS_PROJETO = '$statusProjeto' 
                        WHERE ID_PROJETO = $idProjeto";
                $db->Query($sql);
                $msgSucesso = "Projeto atualizado com sucesso!";
            } else {
                $sql = "INSERT INTO PROJETOS (NOME_PROJETO, DESCRICAO, ID_CLIENTE, STATUS_PROJETO) 
                        VALUES ('$nomeProjeto', '$descricao', $idCliente, '$statusProjeto')";
                $db->Query($sql);
                $msgSucesso = "Projeto cadastrado com sucesso!";
            }
        }
    }

    // 2. Excluir Projeto
    if ($acao === 'excluir_projeto') {
        $idProjeto = (int)$_POST['id_projeto'];
        $db->Query("DELETE FROM PROJETOS WHERE ID_PROJETO = $idProjeto");
        $msgSucesso = "Projeto excluído com sucesso!";
    }
}

// --- FILTROS DE PESQUISA ---
$busca = $_GET['busca'] ?? '';
$filtroStatus = $_GET['status'] ?? '';
$filtroCliente = (int)($_GET['cliente'] ?? 0);

$where = [];
if (!empty($busca)) {
    $buscaEsc = addslashes($busca);
    $where[] = "(P.NOME_PROJETO LIKE '%$buscaEsc%' OR P.DESCRICAO LIKE '%$buscaEsc%')";
}
if (!empty($filtroStatus)) {
    $where[] = "P.STATUS_PROJETO = '$filtroStatus'";
}
if ($filtroCliente > 0) {
    $where[] = "P.ID_CLIENTE = $filtroCliente";
}

$sqlWhere = !empty($where) ? " WHERE " . implode(" AND ", $where) : "";

$sqlProjetos = "SELECT P.*, C.RAZAO_SOCIAL AS NOME_CLIENTE 
                FROM PROJETOS P 
                INNER JOIN CLIENTES C ON P.ID_CLIENTE = C.ID_CLIENTE 
                $sqlWhere 
                ORDER BY P.NOME_PROJETO ASC";

$projetos = $db->Query($sqlProjetos) ?: [];
$clientesLista = $db->Query("SELECT ID_CLIENTE, RAZAO_SOCIAL FROM CLIENTES ORDER BY RAZAO_SOCIAL ASC") ?: [];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gerenciamento de Projetos - PrimeSys</title>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" />
    <link rel="stylesheet" href="css/suporte.css">
    <style>
        .projetos-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        .projeto-card {
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
        .projeto-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 16px rgba(0,0,0,0.1);
        }
        .projeto-card-header {
            background: #001517;
            color: #fff;
            padding: 14px 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .projeto-id-badge {
            background: rgba(255,255,255,0.15);
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
        }
        .projeto-card-body {
            padding: 16px;
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .projeto-nome {
            font-size: 16px;
            font-weight: 700;
            color: #0f172a;
        }
        .projeto-cliente {
            font-size: 13px;
            color: #2563eb;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .projeto-desc {
            font-size: 13px;
            color: #475569;
            background: #f8fafc;
            padding: 10px;
            border-radius: 6px;
            border-left: 3px solid #001517;
            flex: 1;
        }
        .projeto-card-footer {
            background: #f8fafc;
            padding: 12px 16px;
            border-top: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .status-aberto {
            background: #dcfce7;
            color: #166534;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
        }
        .status-encerrado {
            background: #f1f5f9;
            color: #475569;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
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
            $sidebar = new Sidebar('projetos', $isMaster); 
            $sidebar->render(); 
        ?>

        <main class="main-content">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px;">
                <h1 class="page-title" style="margin-bottom: 0;"><i class="fa-solid fa-diagram-project"></i> Gerenciamento de Projetos</h1>
                
                <button type="button" class="btn-3d btn-primary-3d" onclick="openProjetoModal()">
                    <i class="fa-solid fa-plus"></i> Novo Projeto
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
                <form method="GET" action="projetos.php" class="filters-grid">
                    <div class="form-group">
                        <label>Buscar Projeto:</label>
                        <input type="text" name="busca" value="<?= htmlspecialchars($busca) ?>" placeholder="Nome ou descrição...">
                    </div>

                    <div class="form-group">
                        <label>Cliente:</label>
                        <select name="cliente">
                            <option value="">Todos os Clientes</option>
                            <?php foreach ($clientesLista as $cli): ?>
                                <option value="<?= $cli['ID_CLIENTE'] ?>" <?= $filtroCliente == $cli['ID_CLIENTE'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cli['RAZAO_SOCIAL']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Status:</label>
                        <select name="status">
                            <option value="">Todos os Status</option>
                            <option value="A" <?= $filtroStatus === 'A' ? 'selected' : '' ?>>Em Aberto</option>
                            <option value="E" <?= $filtroStatus === 'E' ? 'selected' : '' ?>>Encerrado</option>
                        </select>
                    </div>

                    <div class="filters-actions" style="display: flex; gap: 10px; align-items: flex-end;">
                        <button type="submit" class="btn-3d btn-secondary-3d"><i class="fa-solid fa-magnifying-glass"></i> Filtrar</button>
                        <?php if (!empty($busca) || !empty($filtroStatus) || $filtroCliente > 0): ?>
                            <a href="projetos.php" class="btn-3d btn-danger-3d" style="text-decoration: none; display: inline-flex; align-items: center; justify-content: center; height: 38px; padding: 0 15px;"><i class="fa-solid fa-xmark"></i> Limpar</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- LISTAGEM EM CARDS -->
            <?php if (empty($projetos)): ?>
                <div style="text-align: center; padding: 40px; background: #fff; border-radius: 8px; border: 1px solid #e2e8f0; color: #64748b;">
                    <i class="fa-regular fa-folder-open" style="font-size: 48px; margin-bottom: 10px; color: #cbd5e1;"></i>
                    <p>Nenhum projeto encontrado.</p>
                </div>
            <?php else: ?>
                <div class="projetos-grid">
                    <?php foreach ($projetos as $p): 
                        $emAberto = ($p['STATUS_PROJETO'] === 'A');
                    ?>
                        <div class="projeto-card">
                            <div class="projeto-card-header">
                                <span class="<?= $emAberto ? 'status-aberto' : 'status-encerrado' ?>">
                                    <?= $emAberto ? 'EM ABERTO' : 'ENCERRADO' ?>
                                </span>
                                <span class="projeto-id-badge">#<?= $p['ID_PROJETO'] ?></span>
                            </div>
                            <div class="projeto-card-body">
                                <div class="projeto-nome"><?= htmlspecialchars($p['NOME_PROJETO']) ?></div>
                                <div class="projeto-cliente"><i class="fa-solid fa-building"></i> <?= htmlspecialchars($p['NOME_CLIENTE']) ?></div>
                                <div class="projeto-desc"><?= !empty($p['DESCRICAO']) ? htmlspecialchars($p['DESCRICAO']) : '<em>Sem descrição informada.</em>' ?></div>
                            </div>
                            <div class="projeto-card-footer">
                                <button type="button" class="btn-3d btn-secondary-3d btn-sm-3d" onclick='openProjetoModal(<?= json_encode($p) ?>)'>
                                    <i class="fa-solid fa-pen"></i> Editar
                                </button>
                                <form method="POST" action="projetos.php" style="display:inline;" onsubmit="return confirm('Deseja realmente excluir este projeto?');">
                                    <input type="hidden" name="acao" value="excluir_projeto">
                                    <input type="hidden" name="id_projeto" value="<?= $p['ID_PROJETO'] ?>">
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

    <!-- MODAL: CADASTRAR / EDITAR PROJETO -->
    <div class="modal-overlay" id="projetoModal">
        <div class="modal-card" style="max-width: 650px;">
            <div class="modal-header">
                <h3 id="modalProjetoTitle"><i class="fa-solid fa-diagram-project"></i> Novo Projeto</h3>
                <i class="fa-solid fa-xmark" style="cursor: pointer;" onclick="closeProjetoModal()"></i>
            </div>
            <form method="POST" action="projetos.php" onsubmit="return validarFormularioProjeto()">
                <input type="hidden" name="acao" value="salvar_projeto">
                <input type="hidden" name="id_projeto" id="modal_id_projeto">
                <input type="hidden" name="id_cliente" id="modal_id_cliente" required>
                
                <div class="modal-body">
                    <div class="form-grid">
                        <div class="form-group full-width">
                            <label>Nome do Projeto <span class="required-asterisk">*</span></label>
                            <input type="text" name="nome_projeto" id="modal_nome_projeto" required placeholder="Ex: Implantação ERP Corporativo...">
                        </div>

                        <!-- Combobox com busca (Autocomplete via Datalist) -->
                        <div class="form-group full-width">
                            <label>Cliente <span class="required-asterisk">*</span></label>
                            <input type="text" id="input_busca_cliente" list="lista_clientes_combo" placeholder="Digite para pesquisar e selecionar o cliente..." autocomplete="off" style="padding: 10px; border-radius:6px; border:1.5px solid #cbd5e1; width:100%;" onchange="sincronizarClienteId(this)">
                            <datalist id="lista_clientes_combo">
                                <?php foreach ($clientesLista as $cli): ?>
                                    <option value="<?= htmlspecialchars($cli['RAZAO_SOCIAL']) ?>" data-id="<?= $cli['ID_CLIENTE'] ?>"></option>
                                <?php endforeach; ?>
                            </datalist>
                        </div>

                        <div class="form-group">
                            <label>Status do Projeto <span class="required-asterisk">*</span></label>
                            <select name="status_projeto" id="modal_status_projeto" required style="padding: 10px; border-radius:6px; border:1.5px solid #cbd5e1; width:100%;">
                                <option value="A">Em Aberto</option>
                                <option value="E">Encerrado</option>
                            </select>
                        </div>

                        <div class="form-group full-width">
                            <label>Descrição Detalhada</label>
                            <textarea name="descricao" id="modal_descricao" rows="4" placeholder="Descreva os objetivos e escopo do projeto..." style="padding: 10px; border-radius:6px; border:1.5px solid #cbd5e1; width:100%;"></textarea>
                        </div>
                    </div>

                    <div class="modal-footer" style="margin-top: 20px;">
                        <button type="button" class="btn-3d btn-danger-3d" onclick="closeProjetoModal()">Cancelar</button>
                        <button type="submit" class="btn-3d btn-success-3d"><i class="fa-solid fa-floppy-disk"></i> Salvar Projeto</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Mapeamento auxiliar de clientes para sincronização do ID
        const clientesMap = {};
        <?php foreach ($clientesLista as $cli): ?>
            clientesMap[<?= json_encode($cli['RAZAO_SOCIAL']) ?>] = <?= $cli['ID_CLIENTE'] ?>;
        <?php endforeach; ?>

        function sincronizarClienteId(input) {
            const valor = input.value;
            const idHidden = document.getElementById('modal_id_cliente');
            if (clientesMap[valor]) {
                idHidden.value = clientesMap[valor];
            } else {
                idHidden.value = '';
            }
        }

        function validarFormularioProjeto() {
            const idCliente = document.getElementById('modal_id_cliente').value;
            const inputNomeCli = document.getElementById('input_busca_cliente').value;

            if (!idCliente) {
                // Tenta buscar pelo nome exato caso o usuário tenha digitado sem selecionar da lista suspensa
                if (clientesMap[inputNomeCli]) {
                    document.getElementById('modal_id_cliente').value = clientesMap[inputNomeCli];
                    return true;
                }
                alert('Por favor, selecione um cliente válido da lista.');
                document.getElementById('input_busca_cliente').focus();
                return false;
            }
            return true;
        }

        function openProjetoModal(data = null) {
            if (data) {
                document.getElementById('modalProjetoTitle').innerHTML = '<i class="fa-solid fa-pen-to-square"></i> Editar Projeto #' + data.ID_PROJETO;
                document.getElementById('modal_id_projeto').value = data.ID_PROJETO;
                document.getElementById('modal_nome_projeto').value = data.NOME_PROJETO || '';
                document.getElementById('modal_descricao').value = data.DESCRICAO || '';
                document.getElementById('modal_status_projeto').value = data.STATUS_PROJETO || 'A';
                document.getElementById('modal_id_cliente').value = data.ID_CLIENTE || '';

                // Encontra a razão social correspondente ao ID para preencher o input de busca
                let nomeCliStr = '';
                for (const [nome, id] of Object.entries(clientesMap)) {
                    if (id == data.ID_CLIENTE) {
                        nomeCliStr = nome;
                        break;
                    }
                }
                document.getElementById('input_busca_cliente').value = nomeCliStr;
            } else {
                document.getElementById('modalProjetoTitle').innerHTML = '<i class="fa-solid fa-diagram-project"></i> Novo Projeto';
                document.getElementById('modal_id_projeto').value = '';
                document.getElementById('modal_nome_projeto').value = '';
                document.getElementById('modal_descricao').value = '';
                document.getElementById('modal_status_projeto').value = 'A';
                document.getElementById('modal_id_cliente').value = '';
                document.getElementById('input_busca_cliente').value = '';
            }
            document.getElementById('projetoModal').classList.add('active');
        }

        function closeProjetoModal() {
            document.getElementById('projetoModal').classList.remove('active');
        }
    </script>
</body>
</html>