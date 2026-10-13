<?php
session_start();
require_once __DIR__ . '/php/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['id_usuario'])) {
    echo json_encode([]);
    exit;
}

$idCliente = (int)($_GET['id_cliente'] ?? 0);
$dataIni = $_GET['data_ini'] ?? date('Y-m-01');
$dataFim = $_GET['data_fim'] ?? date('Y-m-t');

if ($idCliente <= 0) {
    echo json_encode([]);
    exit;
}

$db = new database();

// Busca agendamentos do cliente no período, com FATURADO = 'N' e que possuem pelo menos um apontamento
$sql = "SELECT A.ID_AGENDAMENTO, A.DATA_AGENDAMENTO, A.HORA_AGENDAMENTO, A.DESCRICAO_AGENDAMENTO
        FROM AGENDAMENTOS A
        WHERE A.ID_CLIENTE = $idCliente
        AND A.FATURADO = 'N'
        AND A.DATA_AGENDAMENTO BETWEEN '$dataIni' AND '$dataFim'
        AND EXISTS (SELECT 1 FROM APONTAMENTO_AGENDAMENTOS AP WHERE AP.ID_AGENDAMENTO = A.ID_AGENDAMENTO)
        ORDER BY A.DATA_AGENDAMENTO ASC, A.HORA_AGENDAMENTO ASC";

$agendamentos = $db->Query($sql) ?: [];

$resultado = [];
foreach ($agendamentos as $ag) {
    $idAg = $ag['ID_AGENDAMENTO'];
    // Soma os segundos de todos os apontamentos deste agendamento
    $aps = $db->Query("SELECT TOTAL_HORAS_APONTAMENTO FROM APONTAMENTO_AGENDAMENTOS WHERE ID_AGENDAMENTO = $idAg") ?: [];
    
    $totalSegundos = 0;
    foreach ($aps as $ap) {
        list($h, $m, $s) = explode(':', $ap['TOTAL_HORAS_APONTAMENTO']);
        $totalSegundos += ($h * 3600) + ($m * 60) + $s;
    }

    $th = floor($totalSegundos / 3600);
    $tm = floor(($totalSegundos / 60) % 60);
    $ts = $totalSegundos % 60;
    $totalHorasFormatado = sprintf('%02d:%02d:%02d', $th, $tm, $ts);

    $ag['TOTAL_SEGUNDOS'] = $totalSegundos;
    $ag['TOTAL_HORAS_FORMATADO'] = $totalHorasFormatado;
    $resultado[] = $ag;
}

echo json_encode($resultado);