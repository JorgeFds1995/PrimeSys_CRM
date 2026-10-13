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
$faturadoFiltro = $_GET['faturado'] ?? 'T'; // 'T' = Todos, 'S' = Faturados, 'N' = A Faturar

if ($idCliente <= 0) {
    echo json_encode([]);
    exit;
}

$db = new database();

$whereFiltroFat = "";
if ($faturadoFiltro === 'S') {
    $whereFiltroFat = " AND A.FATURADO = 'S'";
} elseif ($faturadoFiltro === 'N') {
    $whereFiltroFat = " AND A.FATURADO = 'N'";
}

$sqlAg = "SELECT A.ID_AGENDAMENTO, A.DATA_AGENDAMENTO, A.HORA_AGENDAMENTO, A.DESCRICAO_AGENDAMENTO, A.FATURADO
          FROM AGENDAMENTOS A
          WHERE A.ID_CLIENTE = $idCliente
          AND A.DATA_AGENDAMENTO BETWEEN '$dataIni' AND '$dataFim'
          $whereFiltroFat
          ORDER BY A.DATA_AGENDAMENTO ASC, A.HORA_AGENDAMENTO ASC";

$agendamentos = $db->Query($sqlAg) ?: [];
$resultado = [];

foreach ($agendamentos as $ag) {
    $idAg = $ag['ID_AGENDAMENTO'];
    $apontamentos = $db->Query("SELECT * FROM APONTAMENTO_AGENDAMENTOS WHERE ID_AGENDAMENTO = $idAg ORDER BY DATA_APONTAMENTO ASC, HORA_INICIAL ASC") ?: [];
    
    // Se o filtro exigir agendamentos que possuem apontamentos, garante que só traz se houver
    if (!empty($apontamentos)) {
        $ag['apontamentos'] = $apontamentos;
        $resultado[] = $ag;
    }
}

echo json_encode($resultado);