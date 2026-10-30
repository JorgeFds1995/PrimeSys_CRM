<?php
session_start();

if (!isset($_SESSION['id_usuario']) || (!($_SESSION['atendente'] ?? false) && !($_SESSION['master'] ?? false))) {
    echo json_encode(['error' => 'Acesso negado']);
    exit;
}

require_once __DIR__ . '/php/database.php';
$db = new database();

$tipoFiltro = $_GET['tipo'] ?? 'cliente';
$dataIni = $_GET['data_ini'] ?? date('Y-m-01');
$dataFim = $_GET['data_fim'] ?? date('Y-m-t');
$faturado = $_GET['faturado'] ?? 'T'; // 'T' = Todos, 'N' = Não Faturados, 'S' = Faturados

// Busca nome da empresa para o relatório
$resEmpresa = $db->Query("SELECT RAZAO_SOCIAL FROM DADOS_EMPRESA LIMIT 1");
$nomeEmpresa = (!empty($resEmpresa) && !empty($resEmpresa[0]['RAZAO_SOCIAL'])) ? $resEmpresa[0]['RAZAO_SOCIAL'] : 'PrimeSys';

// Monta condição de faturamento se aplicável
$whereFaturado = "";
if ($faturado === 'N') {
    $whereFaturado = " AND A.FATURADO = 'N'";
} elseif ($faturado === 'S') {
    $whereFaturado = " AND A.FATURADO = 'S'";
}

$itensRaw = [];

if ($tipoFiltro === 'cliente') {
    $idCliente = (int)($_GET['id_cliente'] ?? 0);
    if ($idCliente <= 0) {
        echo json_encode(['grupos' => [], 'totais_gerais' => null, 'empresa_nome' => $nomeEmpresa]);
        exit;
    }

    $sql = "SELECT AP.*, A.ID_CLIENTE, A.FATURADO, C.RAZAO_SOCIAL AS NOME_CLIENTE 
            FROM APONTAMENTO_AGENDAMENTOS AP
            INNER JOIN AGENDAMENTOS A ON AP.ID_AGENDAMENTO = A.ID_AGENDAMENTO
            INNER JOIN CLIENTES C ON A.ID_CLIENTE = C.ID_CLIENTE
            WHERE A.ID_CLIENTE = $idCliente 
              AND AP.DATA_APONTAMENTO BETWEEN '$dataIni' AND '$dataFim'
              $whereFaturado
            ORDER BY AP.DATA_APONTAMENTO ASC, AP.HORA_INICIAL ASC";
    $itensRaw = $db->Query($sql) ?: [];

} else {
    // Por Parceiro
    $idParceiro = (int)($_GET['id_parceiro'] ?? 0);
    if ($idParceiro <= 0) {
        echo json_encode(['grupos' => [], 'totais_gerais' => null, 'empresa_nome' => $nomeEmpresa]);
        exit;
    }

    $sql = "SELECT AP.*, A.ID_CLIENTE, A.FATURADO, C.RAZAO_SOCIAL AS NOME_CLIENTE 
            FROM APONTAMENTO_AGENDAMENTOS AP
            INNER JOIN AGENDAMENTOS A ON AP.ID_AGENDAMENTO = A.ID_AGENDAMENTO
            INNER JOIN CLIENTES C ON A.ID_CLIENTE = C.ID_CLIENTE
            WHERE C.ID_PARCEIRO = $idParceiro 
              AND AP.DATA_APONTAMENTO BETWEEN '$dataIni' AND '$dataFim'
              $whereFaturado
            ORDER BY AP.DATA_APONTAMENTO ASC, AP.HORA_INICIAL ASC";
    $itensRaw = $db->Query($sql) ?: [];
}

// Função auxiliar para converter segundos em HH:MM:SS formatado
function segundosParaHoras($segundos) {
    $h = floor($segundos / 3600);
    $m = floor(($segundos / 60) % 60);
    $s = $segundos % 60;
    return sprintf('%02d:%02d:%02d', $h, $m, $s);
}

function converterTempoParaSegundos($strTempo) {
    list($h, $m, $s) = explode(':', $strTempo);
    return ((int)$h * 3600) + ((int)$m * 60) + (int)$s;
}

$ordenacao = $_GET['ordenacao'] ?? 'data';
$gruposTemp = [];
$totaisClientesMap = [];

foreach ($itensRaw as $item) {
    $dataAp = $item['DATA_APONTAMENTO'];
    $idCliente = $item['ID_CLIENTE'];
    $nomeCli = $item['NOME_CLIENTE'];
    $segundosItem = converterTempoParaSegundos($item['TOTAL_HORAS_APONTAMENTO']);

    // Agrupamento para exibição no relatório
    if ($tipoFiltro === 'cliente' || $ordenacao === 'data') {
        // Chave de agrupamento por Data
        $chaveGrupo = $dataAp;
        $tituloGrupo = "Data: " . date('d/m/Y', strtotime($dataAp));
    } else {
        // Chave de agrupamento por Cliente
        $chaveGrupo = $idCliente;
        $tituloGrupo = "Cliente: " . $nomeCli;
    }

    if (!isset($gruposTemp[$chaveGrupo])) {
        $gruposTemp[$chaveGrupo] = [
            'titulo' => $tituloGrupo,
            'total_apontamentos' => 0,
            'total_segundos' => 0,
            'itens' => []
        ];
    }

    $gruposTemp[$chaveGrupo]['total_apontamentos']++;
    $gruposTemp[$chaveGrupo]['total_segundos'] += $segundosItem;
    $gruposTemp[$chaveGrupo]['itens'][] = $item;

    // Totais gerais por cliente
    if (!isset($totaisClientesMap[$idCliente])) {
        $totaisClientesMap[$idCliente] = [
            'cliente_nome' => $nomeCli,
            'qtd_apontamentos' => 0,
            'total_segundos' => 0
        ];
    }
    $totaisClientesMap[$idCliente]['qtd_apontamentos']++;
    $totaisClientesMap[$idCliente]['total_segundos'] += $segundosItem;
}

// Formata os grupos para o JSON final
$gruposFinais = [];
foreach ($gruposTemp as $g) {
    $g['total_horas_formatado'] = segundosParaHoras($g['total_segundos']);
    unset($g['total_segundos']);
    $gruposFinais[] = $g;
}

// Se ordenação por cliente solicitada, ordena os itens de cada grupo por data
if ($tipoFiltro === 'parceiro' && $ordenacao === 'cliente') {
    usort($gruposFinais, function($a, $b) {
        return strcmp($a['titulo'], $b['titulo']);
    });
}

// Totais gerais
$qtdGeral = 0;
$segundosGeral = 0;
$clientesTotaisLista = [];

foreach ($totaisClientesMap as $tc) {
    $qtdGeral += $tc['qtd_apontamentos'];
    $segundosGeral += $tc['total_segundos'];
    $clientesTotaisLista[] = [
        'cliente_nome' => $tc['cliente_nome'],
        'qtd_apontamentos' => $tc['qtd_apontamentos'],
        'total_horas_formatado' => segundosParaHoras($tc['total_segundos'])
    ];
}

$totaisGerais = [
    'qtd_geral' => $qtdGeral,
    'tempo_geral' => segundosParaHoras($segundosGeral),
    'clientes' => $clientesTotaisLista
];

echo json_encode([
    'empresa_nome' => $nomeEmpresa,
    'grupos' => $gruposFinais,
    'totais_gerais' => $totaisGerais
]);
exit;