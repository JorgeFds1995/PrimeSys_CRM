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

// Array auxiliar para as formas de cobrança
$formasCobrancaDesc = [
    1 => 'Por hora',
    2 => 'Mensal',
    3 => 'Por execução'
];

// --- PROCESSAMENTO DE FORMULÁRIOS (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';

    // 1. Salvar / Editar Serviço
    if ($acao === 'salvar_servico') {
        $idServico = (int)($_POST['id_servico'] ?? 0);
        $descricao = addslashes(trim($_POST['descricao']));
        $valor = !empty($_POST['valor']) ? str_replace(',', '.', $_POST['valor']) : '0.00';
        $formaCobranca = (int)($_POST['forma_cobranca'] ?? 1);
        $observacao = addslashes(trim($_POST['observacao'] ?? ''));
        $idProjeto = (int)$_POST['id_projeto'];

        if (empty($descricao) || $idProjeto <= 0) {
            $msgErro = "A Descrição do Serviço e o Projeto são de preenchimento obrigatório!";
        } else {
            // Valida se o projeto selecionado está realmente com status 'A' (Em Aberto)
            $resProj = $db->Query("SELECT STATUS_PROJETO FROM PROJETOS WHERE ID_PROJETO = $idProjeto");
            if (empty($resProj) || $resProj[0]['STATUS_PROJETO'] !== 'A') {
                $msgErro = "O projeto selecionado não está em aberto ou não existe.";
            } else {
                if ($idServico > 0) {
                    $sql = "UPDATE SERVICOS SET 
                                DESCRICAO = '$descricao', 
                                VALOR = $valor, 
                                FORMA_COBRANCA = $formaCobranca, 
                                OBSERVACAO = '$observacao', 
                                ID_PROJETO = $idProjeto 
                            WHERE ID_SERVICO = $idServico";
                    $db->Query($sql);
                    $msgSucesso = "Serviço atualizado com sucesso!";
                } else {
                    $sql = "INSERT INTO SERVICOS (DESCRICAO, VALOR, FORMA_COBRANCA, OBSERVACAO, ID_PROJETO) 
                            VALUES ('$descricao', $valor, $formaCobranca, '$observacao', $idProjeto)";
                    $db->Query($sql);
                    $msgSucesso = "Serviço cadastrado com sucesso!";
                }
            }
        }
    }

    // 2. Excluir Serviço
    if ($acao === 'excluir_servico') {
        $idServico = (int)$_POST['id_servico'];
        $db->Query("DELETE FROM SERVICOS WHERE ID_SERVICO = $idServico");
        $msgSucesso = "Serviço excluído com sucesso!";
    }
}

// --- FILTROS DE PESQUISA ---
$busca = $_GET['busca'] ?? '';
$filtroProjeto = (int)($_GET['projeto'] ?? 0);

$where = [];
if (!empty($busca)) {
    $buscaEsc = addslashes($busca);
    $where[] = "(S.DESCRICAO LIKE '%$buscaEsc%' OR S.OBSERVACAO LIKE '%$buscaEsc%' OR P.NOME_PROJETO LIKE '%$buscaEsc%')";
}
if ($filtroProjeto > 0) {
    $where[] = "S.ID_PROJETO = $filtroProjeto";
}

$sqlWhere = !empty($where) ? " WHERE " . implode(" AND ", $where) : "";

$sqlServicos = "SELECT S.*, P.NOME_PROJETO, C.RAZAO_SOCIAL AS NOME_CLIENTE 
                FROM SERVICOS S 
                INNER JOIN PROJETOS P ON S.ID_PROJETO = P.ID_PROJETO 
                INNER JOIN CLIENTES C ON P.ID_CLIENTE = C.ID_CLIENTE 
                $sqlWhere 
                ORDER BY S.DESCRICAO ASC";

$servicos = $db->Query($sqlServicos) ?: [];

// Lista de projetos APENAS com status 'A' (Em Aberto) para os selects e comboboxes
$projetosAtivosLista = $db->Query("SELECT P.ID_PROJETO, P.NOME_PROJETO, C.RAZAO_SOCIAL FROM PROJETOS P INNER JOIN CLIENTES C ON P.ID_CLIENTE = C.ID_CLIENTE WHERE P.STATUS_PROJETO = 'A' ORDER BY P.NOME_PROJETO ASC") ?: [];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gerenciamento de Serviços - PrimeSys</title>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" />
    <link rel="stylesheet" href="css/suporte.css">
    <style>
        .servicos-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        .servico-card {
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
        .servico-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 16px rgba(0,0,0,0.1);
        }
        .servico-card-header {
            background: #001517;
            color: #fff;
            padding: 14px 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .servico-id-badge {
            background: rgba(255,255,255,0.15);
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
        }
        .servico-card-body {
            padding: 16px;
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .servico-nome {
            font-size: 16px;
            font-weight: 700;
            color: #0f172a;
        }
        .servico-projeto {
            font-size: 13px;
            color: #2563eb;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .servico-obs {
            font-size: 13px;
            color: #475569;
            background: #f8fafc;
            padding: 10px;
            border-radius: 6px;
            border-left: 3px solid #001517;
            flex: 1;
        }
        .servico-meta {
            font-size: 12px;
            color: #64748b;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-top: 1px solid #f1f5f9;
            padding-top: 10px;
            margin-top: auto;
        }
        .servico-card-footer {
            background: #f8fafc;
            padding: 12px 16px;
            border-top: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .cobranca-badge {
            background: #e2e8f0;
            color: #334155;
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
            $sidebar = new Sidebar('servicos', $isMaster); 
            $sidebar->render(); 
        ?>

        <main class="main-content">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px;">
                <h1 class="page-title" style="margin-bottom: 0;"><i class="fa-solid fa-screwdriver-wrench"></i> Gerenciamento de Serviços</h1>
                
                <button type="button" class="btn-3d btn-primary-3d" onclick="openServicoModal()">
                    <i class="fa-solid fa-plus"></i> Novo Serviço
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
                <form method="GET" action="servicos.php" class="filters-grid">
                    <div class="form-group">
                        <label>Buscar Serviço / Descrição:</label>
                        <input type="text" name="busca" value="<?= htmlspecialchars($busca) ?>" placeholder="Serviço, observação ou projeto...">
                    </div>

                    <div class="form-group">
                        <label>Projeto:</label>
                        <select name="projeto">
                            <option value="">Todos os Projetos Ativos</option>
                            <?php foreach ($projetosAtivosLista as $proj): ?>
                                <option value="<?= $proj['ID_PROJETO'] ?>" <?= $filtroProjeto == $proj['ID_PROJETO'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($proj['NOME_PROJETO']) ?> (<?= htmlspecialchars($proj['RAZAO_SOCIAL']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filters-actions" style="display: flex; gap: 10px; align-items: flex-end;">
                        <button type="submit" class="btn-3d btn-secondary-3d"><i class="fa-solid fa-magnifying-glass"></i> Filtrar</button>
                        <?php if (!empty($busca) || $filtroProjeto > 0): ?>
                            <a href="servicos.php" class="btn-3d btn-danger-3d" style="text-decoration: none; display: inline-flex; align-items: center; justify-content: center; height: 38px; padding: 0 15px;"><i class="fa-solid fa-xmark"></i> Limpar</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- LISTAGEM EM CARDS -->
            <?php if (empty($servicos)): ?>
                <div style="text-align: center; padding: 40px; background: #fff; border-radius: 8px; border: 1px solid #e2e8f0; color: #64748b;">
                    <i class="fa-regular fa-folder-open" style="font-size: 48px; margin-bottom: 10px; color: #cbd5e1;"></i>
                    <p>Nenhum serviço encontrado.</p>
                </div>
            <?php else: ?>
                <div class="servicos-grid">
                    <?php foreach ($servicos as $s): 
                        $formaDesc = $formasCobrancaDesc[$s['FORMA_COBRANCA']] ?? 'Personalizada';
                    ?>
                        <div class="servico-card">
                            <div class="servico-card-header">
                                <span class="cobranca-badge"><i class="fa-solid fa-tag"></i> <?= $formaDesc ?></span>
                                <span class="servico-id-badge">#<?= $s['ID_SERVICO'] ?></span>
                            </div>
                            <div class="servico-card-body">
                                <div class="servico-nome"><?= htmlspecialchars($s['DESCRICAO']) ?></div>
                                <div class="servico-projeto"><i class="fa-solid fa-diagram-project"></i> <?= htmlspecialchars($s['NOME_PROJETO']) ?> <span style="font-weight: normal; color: #64748b; font-size: 11px;">(<?= htmlspecialchars($s['NOME_CLIENTE']) ?>)</span></div>
                                <div class="servico-obs"><?= !empty($s['OBSERVACAO']) ? htmlspecialchars($s['OBSERVACAO']) : '<em>Sem observações cadastradas.</em>' ?></div>
                                <div class="servico-meta">
                                    <span>Valor:</span>
                                    <strong style="color: #059669; font-size: 15px;">R$ <?= number_format($s['VALOR'] ?? 0, 2, ',', '.') ?></strong>
                                </div>
                            </div>
                            <div class="servico-card-footer">
                                <button type="button" class="btn-3d btn-secondary-3d btn-sm-3d" onclick='openServicoModal(<?= json_encode($s) ?>)'>
                                    <i class="fa-solid fa-pen"></i> Editar
                                </button>
                                <form method="POST" action="servicos.php" style="display:inline;" onsubmit="return confirm('Deseja realmente excluir este serviço?');">
                                    <input type="hidden" name="acao" value="excluir_servico">
                                    <input type="hidden" name="id_servico" value="<?= $s['ID_SERVICO'] ?>">
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

    <!-- MODAL: CADASTRAR / EDITAR SERVIÇO -->
    <div class="modal-overlay" id="servicoModal">
        <div class="modal-card" style="max-width: 650px;">
            <div class="modal-header">
                <h3 id="modalServicoTitle"><i class="fa-solid fa-screwdriver-wrench"></i> Novo Serviço</h3>
                <i class="fa-solid fa-xmark" style="cursor: pointer;" onclick="closeServicoModal()"></i>
            </div>
            <form method="POST" action="servicos.php" onsubmit="return validarFormularioServico()">
                <input type="hidden" name="acao" value="salvar_servico">
                <input type="hidden" name="id_servico" id="modal_id_servico">
                <input type="hidden" name="id_projeto" id="modal_id_projeto" required>
                
                <div class="modal-body">
                    <div class="form-grid">
                        <div class="form-group full-width">
                            <label>Descrição do Serviço <span class="required-asterisk">*</span></label>
                            <input type="text" name="descricao" id="modal_descricao" required placeholder="Ex: Suporte técnico mensal, Desenvolvimento de módulo...">
                        </div>

                        <!-- Combobox com busca (Autocomplete via Datalist) para Projetos com status 'A' -->
                        <div class="form-group full-width">
                            <label>Projeto (Apenas Em Aberto) <span class="required-asterisk">*</span></label>
                            <input type="text" id="input_busca_projeto" list="lista_projetos_combo" placeholder="Digite para pesquisar e selecionar o projeto..." autocomplete="off" style="padding: 10px; border-radius:6px; border:1.5px solid #cbd5e1; width:100%;" onchange="sincronizarProjetoId(this)">
                            <datalist id="lista_projetos_combo">
                                <?php foreach ($projetosAtivosLista as $proj): ?>
                                    <option value="<?= htmlspecialchars($proj['NOME_PROJETO'] . ' (' . $proj['RAZAO_SOCIAL'] . ')') ?>" data-id="<?= $proj['ID_PROJETO'] ?>"></option>
                                <?php endforeach; ?>
                            </datalist>
                        </div>

                        <div class="form-group">
                            <label>Valor (R$) <span class="required-asterisk">*</span></label>
                            <input type="text" name="valor" id="modal_valor" required placeholder="0,00" style="padding: 10px; border-radius:6px; border:1.5px solid #cbd5e1; width:100%;">
                        </div>

                        <div class="form-group">
                            <label>Forma de Cobrança <span class="required-asterisk">*</span></label>
                            <select name="forma_cobranca" id="modal_forma_cobranca" required style="padding: 10px; border-radius:6px; border:1.5px solid #cbd5e1; width:100%;">
                                <option value="1">Por hora</option>
                                <option value="2">Mensal</option>
                                <option value="3">Por execução</option>
                            </select>
                        </div>

                        <div class="form-group full-width">
                            <label>Observações</label>
                            <textarea name="observacao" id="modal_observacao" rows="3" placeholder="Informações adicionais sobre o serviço..." style="padding: 10px; border-radius:6px; border:1.5px solid #cbd5e1; width:100%;"></textarea>
                        </div>
                    </div>

                    <div class="modal-footer" style="margin-top: 20px;">
                        <button type="button" class="btn-3d btn-danger-3d" onclick="closeServicoModal()">Cancelar</button>
                        <button type="submit" class="btn-3d btn-success-3d"><i class="fa-solid fa-floppy-disk"></i> Salvar Serviço</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Mapeamento auxiliar de projetos para sincronização do ID
        const projetosMap = {};
        <?php foreach ($projetosAtivosLista as $proj): ?>
            projetosMap[<?= json_encode($proj['NOME_PROJETO'] . ' (' . $proj['RAZAO_SOCIAL'] . ')') ?>] = <?= $proj['ID_PROJETO'] ?>;
        <?php endforeach; ?>

        function sincronizarProjetoId(input) {
            const valor = input.value;
            const idHidden = document.getElementById('modal_id_projeto');
            if (projetosMap[valor]) {
                idHidden.value = projetosMap[valor];
            } else {
                idHidden.value = '';
            }
        }

        function validarFormularioServico() {
            const idProjeto = document.getElementById('modal_id_projeto').value;
            const inputNomeProj = document.getElementById('input_busca_projeto').value;

            if (!idProjeto) {
                if (projetosMap[inputNomeProj]) {
                    document.getElementById('modal_id_projeto').value = projetosMap[inputNomeProj];
                    return true;
                }
                alert('Por favor, selecione um projeto válido da lista (somente projetos em aberto).');
                document.getElementById('input_busca_projeto').focus();
                return false;
            }
            return true;
        }

        function openServicoModal(data = null) {
            if (data) {
                document.getElementById('modalServicoTitle').innerHTML = '<i class="fa-solid fa-pen-to-square"></i> Editar Serviço #' + data.ID_SERVICO;
                document.getElementById('modal_id_servico').value = data.ID_SERVICO;
                document.getElementById('modal_descricao').value = data.DESCRICAO || '';
                document.getElementById('modal_valor').value = data.VALOR ? parseFloat(data.VALOR).toFixed(2).replace('.', ',') : '0,00';
                document.getElementById('modal_forma_cobranca').value = data.FORMA_COBRANCA || 1;
                document.getElementById('modal_observacao').value = data.OBSERVACAO || '';
                document.getElementById('modal_id_projeto').value = data.ID_PROJETO || '';

                // Encontra a string formatada correspondente ao ID para preencher o input de busca
                let nomeProjStr = '';
                for (const [nome, id] of Object.entries(projetosMap)) {
                    if (id == data.ID_PROJETO) {
                        nomeProjStr = nome;
                        break;
                    }
                }
                document.getElementById('input_busca_projeto').value = nomeProjStr;
            } else {
                document.getElementById('modalServicoTitle').innerHTML = '<i class="fa-solid fa-screwdriver-wrench"></i> Novo Serviço';
                document.getElementById('modal_id_servico').value = '';
                document.getElementById('modal_descricao').value = '';
                document.getElementById('modal_valor').value = '0,00';
                document.getElementById('modal_forma_cobranca').value = '1';
                document.getElementById('modal_observacao').value = '';
                document.getElementById('modal_id_projeto').value = '';
                document.getElementById('input_busca_projeto').value = '';
            }
            document.getElementById('servicoModal').classList.add('active');
        }

        function closeServicoModal() {
            document.getElementById('servicoModal').classList.remove('active');
        }
    </script>
</body>
</html>