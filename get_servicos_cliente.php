<?php
session_start();

if (!isset($_SESSION['id_usuario']) || (!($_SESSION['atendente'] ?? false) && !($_SESSION['master'] ?? false))) {
    echo json_encode([]);
    exit;
}

require_once __DIR__ . '/php/database.php';
$db = new database();

$idCliente = (int)($_GET['id_cliente'] ?? 0);
if ($idCliente <= 0) {
    echo json_encode([]);
    exit;
}

// Busca serviços vinculados estritamente a projetos em aberto (STATUS = 'A') do cliente
$sql = "SELECT S.ID_SERVICO, S.DESCRICAO AS SERVICO_DESC, P.NOME_PROJETO AS PROJETO_DESC
        FROM SERVICOS S
        INNER JOIN PROJETOS P ON S.ID_PROJETO = P.ID_PROJETO
        WHERE P.ID_CLIENTE = $idCliente 
          AND P.STATUS_PROJETO = 'A'
        ORDER BY S.DESCRICAO ASC";

$servicos = $db->Query($sql) ?: [];
echo json_encode($servicos);
exit;