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

$erro = '';

// Processamento do Formulário (Salvar / Editar)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id_usuario = $_POST['id_usuario'] ?? '';
    $nome = addslashes($_POST['nome'] ?? '');
    $email = addslashes($_POST['email'] ?? '');
    $atendente = isset($_POST['atendente']) ? 1 : 0;
    $proprietario = isset($_POST['proprietario_sistema']) ? 1 : 0;
    $id_cliente = !empty($_POST['id_cliente']) ? (int)$_POST['id_cliente'] : 0;
    
    $senha = $_POST['senha'] ?? '';
    $confirma_senha = $_POST['confirma_senha'] ?? '';

    // Validação da senha
    if (!empty($senha) && $senha !== $confirma_senha) {
        $erro = "As senhas não coincidem!";
    } elseif (empty($id_usuario) && empty($senha)) {
        $erro = "A senha é obrigatória para novos usuários!";
    } else {
        if (!empty($id_usuario)) {
            // Edição
            if (!empty($senha)) {
                $senhaHash = password_hash($senha, PASSWORD_DEFAULT);
                $sqlSenha = ", SENHA = '$senhaHash'";
            } else {
                $sqlSenha = "";
            }

            $sql = "UPDATE USUARIOS SET 
                        NOME = '$nome', 
                        EMAIL = '$email', 
                        ID_CLIENTE = $id_cliente, 
                        ATENDENTE = $atendente, 
                        PROPRIETARIO_SISTEMA = $proprietario 
                        $sqlSenha 
                    WHERE ID_USUARIO = " . (int)$id_usuario;
        } else {
            // Novo Usuário
            $senhaHash = password_hash($senha, PASSWORD_DEFAULT);
            $sql = "INSERT INTO USUARIOS (NOME, EMAIL, ID_CLIENTE, ATENDENTE, PROPRIETARIO_SISTEMA, SENHA) 
                    VALUES ('$nome', '$email', $id_cliente, $atendente, $proprietario, '$senhaHash')";
        }

        $db->Query($sql);
        header("Location: usuarios.php?msg=sucesso");
        exit;
    }
}

// Pesquisa
$busca = addslashes($_GET['q'] ?? '');
$sqlSearch = "";
if (!empty($busca)) {
    $sqlSearch = " WHERE U.NOME LIKE '%$busca%' OR U.EMAIL LIKE '%$busca%' ";
}

// Busca lista de usuários com o nome da empresa cliente
$sqlUsuarios = "SELECT U.*, C.RAZAO_SOCIAL AS NOME_CLIENTE 
                FROM USUARIOS U 
                LEFT JOIN CLIENTES C ON U.ID_CLIENTE = C.ID_CLIENTE 
                $sqlSearch 
                ORDER BY U.ID_USUARIO DESC";
$usuarios = $db->Query($sqlUsuarios);

// Busca lista de clientes para preencher a Select no Modal
$clientes = $db->Query("SELECT ID_CLIENTE, RAZAO_SOCIAL FROM CLIENTES ORDER BY RAZAO_SOCIAL ASC");
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestão de Usuários - PrimeSys</title>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/suporte.css">
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
            $sidebar = new Sidebar('usuarios', $isMaster); 
            $sidebar->render(); 
        ?>

        <main class="main-content">
            <h1 class="page-title">Gestão de Usuários</h1>

            <?php if (!empty($erro)): ?>
                <div class="alert-error" style="margin-bottom: 20px;">
                    <?= htmlspecialchars($erro) ?>
                </div>
            <?php endif; ?>

            <div class="actions-bar">
                <form class="search-box" method="GET" action="usuarios.php">
                    <input type="text" name="q" placeholder="Buscar por Nome ou E-mail..." value="<?= htmlspecialchars($busca) ?>">
                    <button type="submit" class="btn-3d btn-secondary-3d"><i class="fa-solid fa-magnifying-glass"></i></button>
                </form>

                <button class="btn-3d btn-primary-3d" onclick="openModal()">
                    <i class="fa-solid fa-user-plus"></i> Novo Usuário
                </button>
            </div>

            <div class="table-responsive">
                <table class="custom-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Nome</th>
                            <th>E-mail</th>
                            <th>Cliente Associado</th>
                            <th>Perfil</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($usuarios)): ?>
                            <tr>
                                <td colspan="6" style="text-align: center;">Nenhum usuário encontrado.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($usuarios as $u): ?>
                                <tr>
                                    <td><strong>#<?= $u['ID_USUARIO'] ?></strong></td>
                                    <td><?= htmlspecialchars($u['NOME']) ?></td>
                                    <td><?= htmlspecialchars($u['EMAIL']) ?></td>
                                    <td><?= !empty($u['NOME_CLIENTE']) ? htmlspecialchars($u['NOME_CLIENTE']) : '<span style="color:#aaa;">Nenhum / Interno</span>' ?></td>
                                    <td>
                                        <?php 
                                            if ($u['PROPRIETARIO_SISTEMA']) {
                                                echo '<span class="badge-tag" style="background:#822412; color:#fff;">Proprietário</span> ';
                                            }
                                            if ($u['ATENDENTE']) {
                                                echo '<span class="badge-tag" style="background:#001517; color:#fff;">Atendente</span> ';
                                            }
                                            if (!$u['PROPRIETARIO_SISTEMA'] && !$u['ATENDENTE']) {
                                                echo '<span class="badge-tag">Cliente</span>';
                                            }
                                        ?>
                                    </td>
                                    <td>
                                        <button class="btn-3d btn-secondary-3d btn-sm-3d" 
                                                onclick='editUsuario(<?= json_encode($u) ?>)'>
                                            <i class="fa-solid fa-pen-to-square"></i> Editar
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

    <!-- Modal de Cadastro / Edição -->
    <div class="modal-overlay" id="usuarioModal">
        <div class="modal-card">
            <div class="modal-header">
                <h3 id="modalTitle"><i class="fa-solid fa-user-plus"></i> Novo Usuário</h3>
                <i class="fa-solid fa-xmark" style="cursor: pointer; font-size: 18px;" onclick="closeModal()"></i>
            </div>
            <form method="POST" action="usuarios.php" onsubmit="return validarFormulario();">
                <div class="modal-body">
                    <input type="hidden" name="id_usuario" id="id_usuario">

                    <div class="form-grid">
                        <div class="form-group full-width">
                            <label for="nome">
                                Nome Completo <span class="required-asterisk">*</span>
                            </label>
                            <input type="text" name="nome" id="nome" maxlength="100" placeholder="Nome do Usuário" required>
                        </div>

                        <div class="form-group full-width">
                            <label for="email">
                                E-mail <span class="required-asterisk">*</span>
                            </label>
                            <input type="email" name="email" id="email" maxlength="100" placeholder="usuario@empresa.com.br" required>
                        </div>

                        <!-- Opções de Perfil (Checkboxes) -->
                        <div class="form-group full-width" style="background: #f8f9fa; padding: 12px; border-radius: 6px; border: 1px solid #e0e0e0;">
                            <label style="margin-bottom: 8px; display: block;">Permissões / Perfil:</label>
                            <div style="display: flex; gap: 20px;">
                                <label style="font-weight: normal; cursor: pointer; display: flex; align-items: center; gap: 6px;">
                                    <input type="checkbox" name="atendente" id="atendente" value="1" onchange="toggleClienteRequired()"> Atendente
                                </label>
                                <label style="font-weight: normal; cursor: pointer; display: flex; align-items: center; gap: 6px;">
                                    <input type="checkbox" name="proprietario_sistema" id="proprietario_sistema" value="1" onchange="toggleClienteRequired()"> Proprietário do Sistema
                                </label>
                            </div>
                        </div>

                        <!-- Seleção do Cliente -->
                        <div class="form-group full-width">
                            <label for="id_cliente">
                                Cliente Vinculado <span class="required-asterisk" id="asterisco_cliente">*</span>
                            </label>
                            <select name="id_cliente" id="id_cliente" style="width: 100%; padding: 10px; border: 1.5px solid #cbd5e1; border-radius: 6px; font-size: 14px; outline: none;">
                                <option value="">-- Selecione um Cliente --</option>
                                <?php foreach ($clientes as $c): ?>
                                    <option value="<?= $c['ID_CLIENTE'] ?>"><?= htmlspecialchars($c['RAZAO_SOCIAL']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Campos de Senha -->
                        <div class="form-group">
                            <label for="senha">
                                Nova Senha <span class="required-asterisk" id="asterisco_senha">*</span>
                            </label>
                            <input type="password" name="senha" id="senha" placeholder="••••••••">
                            <small id="dica_senha" style="color: #666; font-size: 11px; display: none;">Deixe em branco para não alterar.</small>
                        </div>

                        <div class="form-group">
                            <label for="confirma_senha">
                                Confirmar Nova Senha <span class="required-asterisk" id="asterisco_confirma_senha">*</span>
                            </label>
                            <input type="password" name="confirma_senha" id="confirma_senha" placeholder="••••••••">
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn-3d btn-danger-3d" onclick="closeModal()">
                            <i class="fa-solid fa-xmark"></i> Cancelar
                        </button>
                        <button type="submit" class="btn-3d btn-success-3d">
                            <i class="fa-solid fa-floppy-disk"></i> Salvar
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script>
        function toggleClienteRequired() {
            const isAtendente = document.getElementById('atendente').checked;
            const isProprietario = document.getElementById('proprietario_sistema').checked;
            const clienteSelect = document.getElementById('id_cliente');
            const asteriscoCliente = document.getElementById('asterisco_cliente');

            if (isAtendente || isProprietario) {
                clienteSelect.required = false;
                asteriscoCliente.style.display = 'none';
            } else {
                clienteSelect.required = true;
                asteriscoCliente.style.display = 'inline';
            }
        }

        function openModal() {
            document.getElementById('id_usuario').value = '';
            document.getElementById('nome').value = '';
            document.getElementById('email').value = '';
            document.getElementById('atendente').checked = false;
            document.getElementById('proprietario_sistema').checked = false;
            document.getElementById('id_cliente').value = '';
            
            document.getElementById('senha').value = '';
            document.getElementById('confirma_senha').value = '';
            document.getElementById('senha').required = true;
            document.getElementById('confirma_senha').required = true;
            
            document.getElementById('asterisco_senha').style.display = 'inline';
            document.getElementById('asterisco_confirma_senha').style.display = 'inline';
            document.getElementById('dica_senha').style.display = 'none';

            document.getElementById('modalTitle').innerHTML = '<i class="fa-solid fa-user-plus"></i> Novo Usuário';
            toggleClienteRequired();
            document.getElementById('usuarioModal').classList.add('active');
        }

        function closeModal() {
            document.getElementById('usuarioModal').classList.remove('active');
        }

        function editUsuario(data) {
            document.getElementById('id_usuario').value = data.ID_USUARIO;
            document.getElementById('nome').value = data.NOME;
            document.getElementById('email').value = data.EMAIL;
            document.getElementById('atendente').checked = data.ATENDENTE == 1;
            document.getElementById('proprietario_sistema').checked = data.PROPRIETARIO_SISTEMA == 1;
            document.getElementById('id_cliente').value = data.ID_CLIENTE || '';
            
            // Na edição, a senha não é obrigatória
            document.getElementById('senha').value = '';
            document.getElementById('confirma_senha').value = '';
            document.getElementById('senha').required = false;
            document.getElementById('confirma_senha').required = false;
            
            document.getElementById('asterisco_senha').style.display = 'none';
            document.getElementById('asterisco_confirma_senha').style.display = 'none';
            document.getElementById('dica_senha').style.display = 'block';

            document.getElementById('modalTitle').innerHTML = '<i class="fa-solid fa-pen-to-square"></i> Editar Usuário #' + data.ID_USUARIO;
            toggleClienteRequired();
            document.getElementById('usuarioModal').classList.add('active');
        }

        function validarFormulario() {
            const senha = document.getElementById('senha').value;
            const confirmaSenha = document.getElementById('confirma_senha').value;

            if (senha !== confirmaSenha) {
                alert('A senha e a confirmação de senha não coincidem.');
                return false;
            }
            return true;
        }
    </script>
</body>
</html>