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

// Processamento do Formulário (Salvar / Editar)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id_cliente = $_POST['id_cliente'] ?? '';
    $razao_social = addslashes($_POST['razao_social'] ?? '');
    $cnpj = addslashes($_POST['cnpj'] ?? '');
    $endereco = addslashes($_POST['endereco'] ?? '');
    $telefone1 = addslashes($_POST['telefone1'] ?? '');
    $telefone2 = addslashes($_POST['telefone2'] ?? '');
    $email = addslashes($_POST['email'] ?? '');
    $valor_hora = !empty($_POST['valor_hora']) ? (float)str_replace(['.', ','], ['', '.'], $_POST['valor_hora']) : 0;

    if (!empty($id_cliente)) {
        // Update
        $sql = "UPDATE CLIENTES SET 
                    RAZAO_SOCIAL = '$razao_social', 
                    CNPJ = '$cnpj', 
                    ENDERECO = '$endereco', 
                    TELEFONE1 = '$telefone1', 
                    TELEFONE2 = '$telefone2', 
                    EMAIL = '$email',
                    VALOR_HORA = $valor_hora
                WHERE ID_CLIENTE = " . (int)$id_cliente;
    } else {
        // Insert
        $sql = "INSERT INTO CLIENTES (RAZAO_SOCIAL, CNPJ, ENDERECO, TELEFONE1, TELEFONE2, EMAIL, VALOR_HORA) 
                VALUES ('$razao_social', '$cnpj', '$endereco', '$telefone1', '$telefone2', '$email', $valor_hora)";
    }

    $db->Query($sql);
    header("Location: clientes.php?msg=sucesso");
    exit;
}

// Pesquisa
$busca = addslashes($_GET['q'] ?? '');
$sqlSearch = "";
if (!empty($busca)) {
    $sqlSearch = " WHERE RAZAO_SOCIAL LIKE '%$busca%' OR CNPJ LIKE '%$busca%' OR EMAIL LIKE '%$busca%' ";
}

$clientes = $db->Query("SELECT * FROM CLIENTES $sqlSearch ORDER BY ID_CLIENTE DESC") ?: [];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestão de Clientes - PrimeSys</title>
    
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
                            <th>Telefone</th>
                            <th>E-mail</th>
                            <th>Valor Hora</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($clientes)): ?>
                            <tr>
                                <td colspan="7" style="text-align: center;">Nenhum cliente encontrado.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($clientes as $c): ?>
                                <tr>
                                    <td><strong>#<?= $c['ID_CLIENTE'] ?></strong></td>
                                    <td><?= htmlspecialchars($c['RAZAO_SOCIAL']) ?></td>
                                    <td><?= htmlspecialchars($c['CNPJ']) ?></td>
                                    <td><?= htmlspecialchars($c['TELEFONE1']) ?></td>
                                    <td><?= htmlspecialchars($c['EMAIL']) ?></td>
                                    <td>R$ <?= number_format($c['VALOR_HORA'] ?? 0, 2, ',', '.') ?></td>
                                    <td>
                                        <button class="btn-3d btn-secondary-3d btn-sm-3d" 
                                                onclick='editCliente(<?= json_encode($c) ?>)'>
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
<div class="modal-overlay" id="clienteModal">
    <div class="modal-card">
        <div class="modal-header">
            <h3 id="modalTitle"><i class="fa-solid fa-user-plus"></i> Novo Cliente</h3>
            <i class="fa-solid fa-xmark" style="cursor: pointer; font-size: 18px;" onclick="closeModal()"></i>
        </div>
        <form method="POST" action="clientes.php">
            <div class="modal-body">
                <input type="hidden" name="id_cliente" id="id_cliente">

                <div class="form-grid">
                    <!-- Razão Social Ampliada -->
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

                    <div class="form-group">
                        <label for="email">E-mail</label>
                        <input type="email" name="email" id="email" maxlength="100" placeholder="contato@empresa.com.br">
                    </div>

                    <!-- Endereço Expandido em Textarea -->
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

                    <div class="form-group full-width">
                        <label for="valor_hora">Valor Hora (R$)</label>
                        <input type="text" name="valor_hora" id="valor_hora" placeholder="0,00">
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
    function openModal() {
        document.getElementById('id_cliente').value = '';
        document.getElementById('razao_social').value = '';
        document.getElementById('cnpj').value = '';
        document.getElementById('endereco').value = '';
        document.getElementById('telefone1').value = '';
        document.getElementById('telefone2').value = '';
        document.getElementById('email').value = '';
        document.getElementById('valor_hora').value = '';
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
        document.getElementById('modalTitle').innerHTML = '<i class="fa-solid fa-pen-to-square"></i> Editar Cliente #' + data.ID_CLIENTE;
        document.getElementById('clienteModal').classList.add('active');
    }
</script>
</body>
</html>