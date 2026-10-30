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

// --- PROCESSAMENTO DO FORMULÁRIO (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $razaoSocial = addslashes(trim($_POST['razao_social'] ?? ''));
    $cnpj = addslashes(trim($_POST['cnpj'] ?? ''));
    $endereco = addslashes(trim($_POST['endereco'] ?? ''));
    $cep = addslashes(trim($_POST['cep'] ?? ''));
    $telefone1 = addslashes(trim($_POST['telefone1'] ?? ''));
    $telefone2 = addslashes(trim($_POST['telefone2'] ?? ''));
    $email = addslashes(trim($_POST['email'] ?? ''));

    if (empty($razaoSocial) || empty($telefone1)) {
        $msgErro = "A Razão Social e o Telefone Principal são de preenchimento obrigatório!";
    } else {
        // Verifica se já existe registro cadastrado
        $resEmpresa = $db->Query("SELECT ID_EMPRESA FROM DADOS_EMPRESA ORDER BY ID_EMPRESA ASC LIMIT 1");

        if (!empty($resEmpresa)) {
            $idEmpresa = $resEmpresa[0]['ID_EMPRESA'];
            $sql = "UPDATE DADOS_EMPRESA SET 
                        RAZAO_SOCIAL = '$razaoSocial', 
                        CNPJ = '$cnpj', 
                        ENDERECO = '$endereco', 
                        CEP = '$cep', 
                        TELEFONE1 = '$telefone1', 
                        TELEFONE2 = '$telefone2', 
                        EMAIL = '$email' 
                    WHERE ID_EMPRESA = $idEmpresa";
            $db->Query($sql);
            $msgSucesso = "Dados da empresa atualizados com sucesso!";
        } else {
            $sql = "INSERT INTO DADOS_EMPRESA (RAZAO_SOCIAL, CNPJ, ENDERECO, CEP, TELEFONE1, TELEFONE2, EMAIL) 
                    VALUES ('$razaoSocial', '$cnpj', '$endereco', '$cep', '$telefone1', '$telefone2', '$email')";
            $db->Query($sql);
            $msgSucesso = "Dados da empresa cadastrados com sucesso!";
        }
    }
}

// --- BUSCA REGISTRO ATUAL PARA EXIBIÇÃO NO FORMULÁRIO ---
$empresaAtual = [
    'RAZAO_SOCIAL' => '',
    'CNPJ' => '',
    'ENDERECO' => '',
    'CEP' => '',
    'TELEFONE1' => '',
    'TELEFONE2' => '',
    'EMAIL' => ''
];

$resBusca = $db->Query("SELECT * FROM DADOS_EMPRESA ORDER BY ID_EMPRESA ASC LIMIT 1");
if (!empty($resBusca)) {
    $empresaAtual = $resBusca[0];
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dados da Empresa - PrimeSys</title>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" />
    <link rel="stylesheet" href="css/suporte.css">
    <style>
        .form-card {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.06);
            border: 1px solid #e2e8f0;
            padding: 25px;
            max-width: 900px;
            margin: 0 auto;
        }
        .form-card-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f1f5f9;
        }
        .form-card-header i {
            font-size: 24px;
            color: #001517;
        }
        .form-card-header h2 {
            font-size: 18px;
            color: #0f172a;
            margin: 0;
            font-weight: 700;
        }
        .alert-box {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
            max-width: 900px;
            margin-left: auto;
            margin-right: auto;
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
            $sidebar = new Sidebar('dados_empresa', $isMaster); 
            $sidebar->render(); 
        ?>

        <main class="main-content">
            <h1 class="page-title" style="margin-bottom: 20px;"><i class="fa-solid fa-building"></i> Configuração dos Dados da Empresa</h1>

            <?php if (!empty($msgSucesso)): ?>
                <div class="alert-box alert-success"><i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($msgSucesso) ?></div>
            <?php endif; ?>
            <?php if (!empty($msgErro)): ?>
                <div class="alert-box alert-error"><i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($msgErro) ?></div>
            <?php endif; ?>

            <div class="form-card">
                <div class="form-card-header">
                    <i class="fa-solid fa-id-card-clip"></i>
                    <div>
                        <h2>Informações Cadastrais</h2>
                        <span style="font-size: 12px; color: #64748b;">Estes dados são utilizados nos relatórios, documentos e cabeçalhos do sistema.</span>
                    </div>
                </div>

                <form method="POST" action="dados_empresa.php">
                    <div class="form-grid">
                        <div class="form-group full-width">
                            <label>Razão Social / Nome da Empresa <span class="required-asterisk">*</span></label>
                            <input type="text" name="razao_social" value="<?= htmlspecialchars($empresaAtual['RAZAO_SOCIAL']) ?>" required placeholder="Ex: Minha Empresa Ltda">
                        </div>

                        <div class="form-group">
                            <label>CNPJ</label>
                            <input type="text" name="cnpj" value="<?= htmlspecialchars($empresaAtual['CNPJ']) ?>" placeholder="00.000.000/0000-00">
                        </div>

                        <div class="form-group">
                            <label>CEP</label>
                            <input type="text" name="cep" value="<?= htmlspecialchars($empresaAtual['CEP']) ?>" maxlength="8" placeholder="00000000">
                        </div>

                        <div class="form-group full-width">
                            <label>Endereço Completo</label>
                            <input type="text" name="endereco" value="<?= htmlspecialchars($empresaAtual['ENDERECO']) ?>" placeholder="Rua, Número, Bairro, Cidade - UF">
                        </div>

                        <div class="form-group">
                            <label>Telefone Principal <span class="required-asterisk">*</span></label>
                            <input type="text" name="telefone1" value="<?= htmlspecialchars($empresaAtual['TELEFONE1']) ?>" required placeholder="(00) 00000-0000">
                        </div>

                        <div class="form-group">
                            <label>Telefone Secundário</label>
                            <input type="text" name="telefone2" value="<?= htmlspecialchars($empresaAtual['TELEFONE2']) ?>" placeholder="(00) 00000-0000">
                        </div>

                        <div class="form-group full-width">
                            <label>E-mail Corporativo</label>
                            <input type="email" name="email" value="<?= htmlspecialchars($empresaAtual['EMAIL']) ?>" placeholder="contato@suaempresa.com">
                        </div>
                    </div>

                    <div style="display: flex; justify-content: flex-end; margin-top: 25px;">
                        <button type="submit" class="btn-3d btn-success-3d" style="padding: 12px 25px; font-size: 15px;">
                            <i class="fa-solid fa-floppy-disk"></i> Salvar Dados da Empresa
                        </button>
                    </div>
                </form>
            </div>
        </main>
    </div>

</body>
</html>