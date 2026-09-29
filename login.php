<?php
session_start();
require_once __DIR__ . '/php/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $senha = $_POST['senha'] ?? '';

    if (empty($email) || empty($senha)) {
        header("Location: index.php?error=" . urlencode("Preencha todos os campos."));
        exit;
    }

    $db = new database();
    
    // Busca o usuário apenas pelo e-mail para validar a hash da senha com segurança
    // Nota: Recomenda-se ajustar o método Query() futuramente para suportar PDO bindParam
    $sql = "SELECT * FROM USUARIOS WHERE EMAIL = '" . addslashes($email) . "' LIMIT 1";
    $resultados = $db->Query($sql);

    if (!empty($resultados)) {
        $usuario = $resultados[0];

        // Validação da senha criptografada (password_hash)
        if (password_verify($senha, $usuario['SENHA']) || $senha === $usuario['SENHA']) {
            
            // Definição das flags de permissão
            $isAtendente = (bool)$usuario['ATENDENTE'];
            $isProprietario = (bool)$usuario['PROPRIETARIO_SISTEMA'];

            $_SESSION['id_usuario'] = $usuario['ID_USUARIO'];
            $_SESSION['nome'] = $usuario['NOME'];
            $_SESSION['id_cliente'] = $usuario['ID_CLIENTE'];
            
            // Variáveis de controle de acesso
            $_SESSION['atendente'] = ($isAtendente && !$isProprietario);
            $_SESSION['master'] = $isProprietario;

            // Redirecionamento por tipo de usuário
            if (!$isAtendente && !$isProprietario) {
                header("Location: home_cliente.php");
            } else {
                header("Location: home_suporte.php");
            }
            exit;
        }
    }

    header("Location: index.php?error=" . urlencode("E-mail ou senha inválidos."));
    exit;
}