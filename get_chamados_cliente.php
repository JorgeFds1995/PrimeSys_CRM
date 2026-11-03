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

// Lista chamados do mesmo cliente do agendamento, cuja etapa seja diferente de 'Aguardando Atendimento' e 'Concluído'
$sql = "SELECT ID_CHAMADO, TIPO_CHAMADO, ETAPA_CHAMADO, DATA_ABERTURA 
        FROM CHAMADOS 
        WHERE ID_CLIENTE = $idCliente 
          AND ETAPA_CHAMADO NOT IN ('Aguardando Atendimento', 'Concluído') 
        ORDER BY ID_CHAMADO DESC";

$chamados = $db->Query($sql) ?: [];
echo json_encode($chamados);
exit;