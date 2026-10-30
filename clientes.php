<?php
session_start();

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

// Processamento do Formulário (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? 'salvar_cliente';

    // 1. Salvar ou Editar Cliente
    if ($acao === 'salvar_cliente') {
        $id_cliente = $_POST['id_cliente'] ?? '';
        $razao_social = addslashes($_POST['razao_social'] ?? '');
        $cnpj = addslashes($_POST['cnpj'] ?? '');
        $endereco = addslashes($_POST['endereco'] ?? '');
        $telefone1 = addslashes($_POST['telefone1'] ?? '');
        $telefone2 = addslashes($_POST['telefone2'] ?? '');
        $email = addslashes($_POST['email'] ?? '');
        $valor_hora = !empty($_POST['valor_hora']) ? (float)str_replace(['.', ','], ['', '.'], $_POST['valor_hora']) : 0;
        $id_parceiro = !empty($_POST['id_parceiro']) ? (int)$_POST['id_parceiro'] : "NULL";

        if (!empty($id_cliente)) {
            // Update
            $sql = "UPDATE CLIENTES SET 
                        RAZAO_SOCIAL = '$razao_social', 
                        CNPJ = '$cnpj', 
                        ENDERECO = '$endereco', 
                        TELEFONE1 = '$telefone1', 
                        TELEFONE2 = '$telefone2', 
                        EMAIL = '$email',
                        VALOR_HORA = $valor_hora,
                        ID_PARCEIRO = $id_parceiro
                    WHERE ID_CLIENTE = " . (int)$id_cliente;
        } else {
            // Insert
            $sql = "INSERT INTO CLIENTES (RAZAO_SOCIAL, CNPJ, ENDERECO, TELEFONE1, TELEFONE2, EMAIL, VALOR_HORA, ID_PARCEIRO) 
                    VALUES ('$razao_social', '$cnpj', '$endereco', '$telefone1', '$telefone2', '$email', $valor_hora, $id_parceiro)";
        }

        $db->Query($sql);
        header("Location: clientes.php?msg=sucesso");
        exit;
    }

    // 2. Inserir Contato do Cliente
    if ($acao === 'salvar_contato') {
        $id_cliente = (int)($_POST['id_cliente_contato'] ?? 0);
        $nome_contato = addslashes(trim($_POST['nome_contato'] ?? ''));
        $tel1 = addslashes(trim($_POST['contato_tel1'] ?? ''));
        $tel2 = addslashes(trim($_POST['contato_tel2'] ?? ''));
        $email_contato = addslashes(trim($_POST['contato_email'] ?? ''));

        if ($id_cliente > 0 && !empty($nome_contato) && !empty($tel1)) {
            $db->Query("INSERT INTO CONTATOS_CLIENTES (ID_CLIENTE, NOME_CONTATO, TELEFONE1, TELEFONE2, EMAIL) 
                        VALUES ($id_cliente, '$nome_contato', '$tel1', '$tel2', '$email_contato')");
            header("Location: clientes.php?msg=contato_salvo&tab=contatos&id=" . $id_cliente);
            exit;
        }
    }

    // 3. Excluir Contato
    if ($acao === 'excluir_contato') {
        $id_contato = (int)($_POST['id_contato'] ?? 0);
        $id_cliente = (int)($_POST['id_cliente_redirecionar'] ?? 0);
        if ($id_contato > 0) {
            $db->Query("DELETE FROM CONTATOS_CLIENTES WHERE ID_CONTATO = $id_contato");
            header("Location: clientes.php?msg=contato_removido&tab=contatos&id=" . $id_cliente);
            exit;
        }
    }
}

// Pesquisa
$busca = addslashes($_GET['q'] ?? '');
$sqlSearch = "";
if (!empty($busca)) {
    $sqlSearch = " WHERE C.RAZAO_SOCIAL LIKE '%$busca%' OR C.CNPJ LIKE '%$busca%' OR C.EMAIL LIKE '%$busca%' ";
}

$clientes = $db->Query("SELECT C.*, P.RAZAO_SOCIAL AS NOME_PARCEIRO FROM CLIENTES C LEFT JOIN PARCEIROS P ON C.ID_PARCEIRO = P.ID_PARCEIRO $sqlSearch ORDER BY C.ID_CLIENTE DESC") ?: [];
$parceirosLista = $db->Query("SELECT ID_PARCEIRO, RAZAO_SOCIAL FROM PARCEIROS ORDER BY RAZAO_SOCIAL ASC") ?: [];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestão de Clientes - PrimeSys</title>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/suporte.css">
    <style>
        /* Estilos de Abas no Modal */
        .modal-tabs {
            display: flex;
            background: #f1f5f9;
            border-bottom: 1px solid #cbd5e1;
            padding: 0 20px;
            gap: 10px;
        }
        .tab-btn {
            background: transparent;
            border: none;
            padding: 12px 18px;
            font-size: 14px;
            font-weight: 600;
            color: #64748b;
            cursor: pointer;
            border-bottom: 3px solid transparent;
            transition: all 0.2s ease;
        }
        .tab-btn:hover {
            color: #001517;
        }
        .tab-btn.active {
            color: #001517;
            border-bottom-color: #001517;
            background: #fff;
        }
        .tab-pane {
            display: none;
            padding: 20px;
        }
        .tab-pane.active {
            display: block;
        }
        .disabled-tab {
            opacity: 0.5;
            cursor: not-allowed !important;
        }
    </style>
</head>
<body>

    <header class="top-header">
        <div class="brand">
            <i class="fa-solid fa-headset"></i> PrimeSys Suporte
        </div>
        <div class="user-info">
            <span><i class="fa-solid fa-user"></i> <strong><?= htmlspecialchars($nomeUsuario) ?></strong></span>
            <a href="logout.php" class="btn-logout"><i class="fa-solid fa-right-from-bracket"></i> Sair</a>
        </div>
    </header>

    <div class="app-container">
        <?php 
            $sidebar = new Sidebar('clientes', $isMaster); 
            $sidebar->render(); 
        ?>

        <main class="main-content">
            <h1 class="page-title">Gestão de Clientes</h1>

            <div class="actions-bar">
                <form class="search-box" method="GET" action="clientes.php">
                    <input type="text" name="q" placeholder="Buscar por Razão Social, CNPJ ou E-mail..." value="<?= htmlspecialchars($busca) ?>">
                    <button type="submit" class="btn-3d btn-secondary-3d"><i class="fa-solid fa-magnifying-glass"></i></button>
                </form>

                <button class="btn-3d btn-primary-3d" onclick="openModal()">
                    <i class="fa-solid fa-plus"></i> Novo Cliente
                </button>
            </div>

            <div class="table-responsive">
                <table class="custom-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Razão Social</th>
                            <th>CNPJ</th>
                            <th>Parceiro</th>
                            <th>Telefone</th>
                            <th>E-mail</th>
                            <th>Valor Hora</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($clientes)): ?>
                            <tr>
                                <td colspan="8" style="text-align: center;">Nenhum cliente encontrado.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($clientes as $c): ?>
                                <tr>
                                    <td><strong>#<?= $c['ID_CLIENTE'] ?></strong></td>
                                    <td><?= htmlspecialchars($c['RAZAO_SOCIAL']) ?></td>
                                    <td><?= htmlspecialchars($c['CNPJ']) ?></td>
                                    <td><?= !empty($c['NOME_PARCEIRO']) ? htmlspecialchars($c['NOME_PARCEIRO']) : '<span style="color:#94a3b8">Nenhum</span>' ?></td>
                                    <td><?= htmlspecialchars($c['TELEFONE1']) ?></td>
                                    <td><?= htmlspecialchars($c['EMAIL']) ?></td>
                                    <td>R$ <?= number_format($c['VALOR_HORA'] ?? 0, 2, ',', '.') ?></td>
                                    <td>
                                        <button class="btn-3d btn-secondary-3d btn-sm-3d" 
                                                onclick='editCliente(<?= json_encode($c) ?>)'>
                                            <i class="fa-solid fa-pen-to-square"></i> Editar / Contatos
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>

   <!-- Modal de Cadastro / Edição com Abas -->
<div class="modal-overlay" id="clienteModal">
    <div class="modal-card" style="max-width: 800px; padding: 0; overflow: hidden;">
        <div class="modal-header" style="padding: 15px 20px;">
            <h3 id="modalTitle"><i class="fa-solid fa-user-plus"></i> Novo Cliente</h3>
            <i class="fa-solid fa-xmark" style="cursor: pointer; font-size: 18px;" onclick="closeModal()"></i>
        </div>

        <!-- Abas do Modal -->
        <div class="modal-tabs">
            <button type="button" class="tab-btn active" id="btnTabDados" onclick="switchTab('dados')">
                <i class="fa-solid fa-building"></i> Dados Cadastrais
            </button>
            <button type="button" class="tab-btn disabled-tab" id="btnTabContatos" onclick="switchTab('contatos')">
                <i class="fa-solid fa-address-book"></i> Contatos do Cliente
            </button>
        </div>

        <!-- Conteúdo Aba 1: Dados Cadastrais -->
        <div class="tab-pane active" id="paneDados">
            <form method="POST" action="clientes.php">
                <input type="hidden" name="acao" value="salvar_cliente">
                <input type="hidden" name="id_cliente" id="id_cliente">

                <div class="form-grid">
                    <div class="form-group full-width">
                        <label for="razao_social">
                            Razão Social <span class="required-asterisk">*</span>
                        </label>
                        <input type="text" name="razao_social" id="razao_social" maxlength="255" placeholder="Nome completo ou Razão Social da Empresa" required>
                    </div>

                    <div class="form-group">
                        <label for="cnpj">
                            CNPJ / CPF <span class="required-asterisk">*</span>
                        </label>
                        <input type="text" name="cnpj" id="cnpj" maxlength="30" placeholder="00.000.000/0000-00" required>
                    </div>

                    <!-- Combobox com busca (Autocomplete via Datalist) para Parceiro -->
                    <div class="form-group">
                        <label>Parceiro Vinculado</label>
                        <input type="text" id="input_busca_parceiro" list="lista_parceiros_combo" placeholder="Digite para pesquisar o parceiro..." autocomplete="off" style="padding: 10px; border-radius:6px; border:1.5px solid #cbd5e1; width:100%;" onchange="sincronizarParceiroId(this)">
                        <input type="hidden" name="id_parceiro" id="id_parceiro">
                        <datalist id="lista_parceiros_combo">
                            <?php foreach ($parceirosLista as $p): ?>
                                <option value="<?= htmlspecialchars($p['RAZAO_SOCIAL']) ?>" data-id="<?= $p['ID_PARCEIRO'] ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                    </div>

                    <div class="form-group">
                        <label for="email">E-mail</label>
                        <input type="email" name="email" id="email" maxlength="100" placeholder="contato@empresa.com.br">
                    </div>

                    <div class="form-group">
                        <label for="valor_hora">Valor Hora (R$)</label>
                        <input type="text" name="valor_hora" id="valor_hora" placeholder="0,00">
                    </div>

                    <div class="form-group full-width">
                        <label for="endereco">
                            Endereço Completo <span class="required-asterisk">*</span>
                        </label>
                        <textarea name="endereco" id="endereco" rows="2" maxlength="255" placeholder="Rua, Número, Bairro, Cidade - UF, CEP" required></textarea>
                    </div>

                    <div class="form-group">
                        <label for="telefone1">
                            Telefone Principal <span class="required-asterisk">*</span>
                        </label>
                        <input type="text" name="telefone1" id="telefone1" maxlength="20" placeholder="(00) 00000-0000" required>
                    </div>

                    <div class="form-group">
                        <label for="telefone2">Telefone Secundário</label>
                        <input type="text" name="telefone2" id="telefone2" maxlength="20" placeholder="(00) 0000-0000">
                    </div>
                </div>

                <div class="modal-footer" style="margin-top: 20px;">
                    <button type="button" class="btn-3d btn-danger-3d" onclick="closeModal()">
                        <i class="fa-solid fa-xmark"></i> Cancelar
                    </button>
                    <button type="submit" class="btn-3d btn-success-3d">
                        <i class="fa-solid fa-floppy-disk"></i> Salvar Cliente
                    </button>
                </div>
            </form>
        </div>

        <!-- Conteúdo Aba 2: Contatos do Cliente -->
        <div class="tab-pane" id="paneContatos">
            <div style="background: #f8fafc; padding: 15px; border-radius: 8px; border: 1px solid #e2e8f0; margin-bottom: 20px;">
                <h4 style="margin-bottom: 12px; color: #001517; font-size: 14px;"><i class="fa-solid fa-user-plus"></i> Adicionar Novo Contato</h4>
                <form method="POST" action="clientes.php">
                    <input type="hidden" name="acao" value="salvar_contato">
                    <input type="hidden" name="id_cliente_contato" id="form_id_cliente_contato">

                    <div class="form-grid" style="grid-template-columns: 2fr 1fr 1fr; gap: 10px;">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label>Nome do Contato <span class="required-asterisk">*</span></label>
                            <input type="text" name="nome_contato" required placeholder="Nome completo..." style="padding: 8px;">
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label>Telefone 1 <span class="required-asterisk">*</span></label>
                            <input type="text" name="contato_tel1" required placeholder="(00) 00000-0000" style="padding: 8px;">
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label>Telefone 2</label>
                            <input type="text" name="contato_tel2" placeholder="(00) 0000-0000" style="padding: 8px;">
                        </div>
                        <div class="form-group full-width" style="margin-bottom: 0; grid-column: span 2;">
                            <label>E-mail</label>
                            <input type="email" name="contato_email" placeholder="email@contato.com" style="padding: 8px;">
                        </div>
                        <div style="display: flex; align-items: flex-end;">
                            <button type="submit" class="btn-3d btn-success-3d btn-sm-3d" style="width: 100%; padding: 9px;"><i class="fa-solid fa-plus"></i> Salvar Contato</button>
                        </div>
                    </div>
                </form>
            </div>

            <h4 style="margin-bottom: 10px; color: #001517; font-size: 14px;">Contatos Cadastrados:</h4>
            <div class="table-responsive" style="max-height: 200px; overflow-y: auto; border: 1px solid #e2e8f0; border-radius: 6px;">
                <table class="custom-table" style="font-size: 12px;">
                    <thead>
                        <tr>
                            <th>Nome</th>
                            <th>Telefone 1</th>
                            <th>Telefone 2</th>
                            <th>E-mail</th>
                            <th>Ação</th>
                        </tr>
                    </thead>
                    <tbody id="lista_contatos_tbody">
                        <tr><td colspan="5" style="text-align: center; color: #888; padding: 15px;">Carregando contatos...</td></tr>
                    </tbody>
                </table>
            </div>

            <div class="modal-footer" style="margin-top: 20px;">
                <button type="button" class="btn-3d btn-secondary-3d" onclick="closeModal()">Fechar</button>
            </div>
        </div>
    </div>
</div>

<script>
    // Mapeamento auxiliar de parceiros para sincronização do ID
    const parceirosMap = {};
    <?php foreach ($parceirosLista as $p): ?>
        parceirosMap[<?= json_encode($p['RAZAO_SOCIAL']) ?>] = <?= $p['ID_PARCEIRO'] ?>;
    <?php endforeach; ?>

    function sincronizarParceiroId(input) {
        const valor = input.value;
        const idHidden = document.getElementById('id_parceiro');
        if (parceirosMap[valor]) {
            idHidden.value = parceirosMap[valor];
        } else {
            idHidden.value = '';
        }
    }

    function switchTab(tabName) {
        const btnDados = document.getElementById('btnTabDados');
        const btnContatos = document.getElementById('btnTabContatos');
        const paneDados = document.getElementById('paneDados');
        const paneContatos = document.getElementById('paneContatos');

        if (tabName === 'contatos' && btnContatos.classList.contains('disabled-tab')) {
            return;
        }

        if (tabName === 'dados') {
            btnDados.classList.add('active');
            btnContatos.classList.remove('active');
            paneDados.classList.add('active');
            paneContatos.classList.remove('active');
        } else {
            btnContatos.classList.add('active');
            btnDados.classList.remove('active');
            paneContatos.classList.add('active');
            paneDados.classList.remove('active');
        }
    }

    function openModal() {
        document.getElementById('id_cliente').value = '';
        document.getElementById('razao_social').value = '';
        document.getElementById('cnpj').value = '';
        document.getElementById('endereco').value = '';
        document.getElementById('telefone1').value = '';
        document.getElementById('telefone2').value = '';
        document.getElementById('email').value = '';
        document.getElementById('valor_hora').value = '';
        document.getElementById('id_parceiro').value = '';
        document.getElementById('input_busca_parceiro').value = '';

        // Desabilita aba de contatos para novos clientes (precisa salvar primeiro)
        document.getElementById('btnTabContatos').classList.add('disabled-tab');
        switchTab('dados');

        document.getElementById('modalTitle').innerHTML = '<i class="fa-solid fa-user-plus"></i> Novo Cliente';
        document.getElementById('clienteModal').classList.add('active');
    }

    function closeModal() {
        document.getElementById('clienteModal').classList.remove('active');
    }

    function editCliente(data) {
        document.getElementById('id_cliente').value = data.ID_CLIENTE;
        document.getElementById('razao_social').value = data.RAZAO_SOCIAL;
        document.getElementById('cnpj').value = data.CNPJ;
        document.getElementById('endereco').value = data.ENDERECO;
        document.getElementById('telefone1').value = data.TELEFONE1;
        document.getElementById('telefone2').value = data.TELEFONE2 || '';
        document.getElementById('email').value = data.EMAIL || '';
        document.getElementById('valor_hora').value = data.VALOR_HORA ? data.VALOR_HORA.toString().replace('.', ',') : '';
        document.getElementById('id_parceiro').value = data.ID_PARCEIRO || '';

        // Sincroniza o input de busca do parceiro pelo ID
        let nomeParcStr = '';
        for (const [nome, id] of Object.entries(parceirosMap)) {
            if (id == data.ID_PARCEIRO) {
                nomeParcStr = nome;
                break;
            }
        }
        document.getElementById('input_busca_parceiro').value = nomeParcStr;

        // Habilita e configura a aba de contatos
        document.getElementById('btnTabContatos').classList.remove('disabled-tab');
        document.getElementById('form_id_cliente_contato').value = data.ID_CLIENTE;
        carregarContatos(data.ID_CLIENTE);

        document.getElementById('modalTitle').innerHTML = '<i class="fa-solid fa-pen-to-square"></i> Editar Cliente #' + data.ID_CLIENTE;
        document.getElementById('clienteModal').classList.add('active');
    }

    function carregarContatos(idCliente) {
        const tbody = document.getElementById('lista_contatos_tbody');
        tbody.innerHTML = '<tr><td colspan="5" style="text-align: center; padding: 10px;">Carregando...</td></tr>';

        fetch(`get_contatos_cliente.php?id_cliente=${idCliente}`)
            .then(res => res.json())
            .then(data => {
                tbody.innerHTML = '';
                if (!data || data.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="5" style="text-align: center; color: #888; padding: 10px;">Nenhum contato cadastrado para este cliente.</td></tr>';
                    return;
                }
                data.forEach(ct => {
                    tbody.innerHTML += `
                        <tr>
                            <td><strong>${ct.NOME_CONTATO}</strong></td>
                            <td>${ct.TELEFONE1}</td>
                            <td>${ct.TELEFONE2 || '-'}</td>
                            <td>${ct.EMAIL || '-'}</td>
                            <td>
                                <form method="POST" action="clientes.php" style="display:inline;" onsubmit="return confirm('Deseja excluir este contato?');">
                                    <input type="hidden" name="acao" value="excluir_contato">
                                    <input type="hidden" name="id_contato" value="${ct.ID_CONTATO}">
                                    <input type="hidden" name="id_cliente_redirecionar" value="${idCliente}">
                                    <button type="submit" class="btn-3d btn-danger-3d btn-sm-3d" style="padding: 2px 6px;">
                                        <i class="fa-solid fa-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    `;
                });
            });
    }

    // Verifica parâmetros na URL para reabrir na aba correta após submissão de contato
    document.addEventListener("DOMContentLoaded", function() {
        const urlParams = new URLSearchParams(window.location.search);
        const tab = urlParams.get('tab');
        const clienteId = urlParams.get('id');
        if (tab === 'contatos' && clienteId) {
            // Busca o botão de editar correspondente ou simula a abertura para o cliente
            fetch(`get_cliente_json.php?id=${clienteId}`)
                .then(res => res.json())
                .then(data => {
                    if (data) {
                        editCliente(data);
                        switchTab('contatos');
                    }
                });
        }
    });
</script>
</body>
</html>