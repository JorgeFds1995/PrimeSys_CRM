<?php
// Garante que nenhum Warning/Notice do PHP quebre a estrutura do JSON
ob_start();
session_start();

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['id_usuario'])) {
    ob_end_clean();
    echo json_encode(['error' => 'Sessão expirada']);
    exit;
}

require_once __DIR__ . '/php/database.php';

$idCliente = (int)($_GET['id_cliente'] ?? 0);

if ($idCliente <= 0) {
    ob_end_clean();
    echo json_encode([]);
    exit;
}

try {
    $db = new database();
    
    // Consulta buscando os usuários da empresa selecionada
    $sql = "SELECT ID_USUARIO, NOME 
            FROM USUARIOS 
            WHERE ID_CLIENTE = $idCliente 
            ORDER BY NOME ASC";
            
    $usuarios = $db->Query($sql);

    // Limpa qualquer saída indesejada antes de imprimir o JSON
    ob_end_clean();
    echo json_encode($usuarios ?: []);
    
} catch (Exception $e) {
    ob_end_clean();
    echo json_encode(['error' => $e->getMessage()]);
}
exit;