<?php
session_start();

if (!isset($_SESSION['id_usuario']) || (!($_SESSION['atendente'] ?? false) && !($_SESSION['master'] ?? false))) {
    echo json_encode(['error' => 'Acesso negado']);
    exit;
}

require_once __DIR__ . '/php/database.php';
$db = new database();

$idFechamento = (int)($_GET['id_fechamento'] ?? 0);
if ($idFechamento <= 0) {
    echo json_encode(['error' => 'ID de Fechamento inválido']);
    exit;
}

// 1. Busca Fechamento e Nome do Parceiro
$resFec = $db->Query("SELECT F.*, P.RAZAO_SOCIAL AS PARCEIRO_NOME 
                      FROM FECHAMENTO F 
                      INNER JOIN PARCEIROS P ON F.ID_CLIENTE = P.ID_PARCEIRO 
                      WHERE F.ID_FECHAMENTO = $idFechamento");
if (empty($resFec)) {
    echo json_encode(['error' => 'Fechamento não encontrado']);
    exit;
}

$fec = $resFec[0];
$idParceiro = (int)$fec['ID_CLIENTE'];
$dataIni = $fec['DATA_INICIO'];
$dataFim = $fec['DATA_FIM'];

// Nome Empresa
$resEmp = $db->Query("SELECT RAZAO_SOCIAL FROM DADOS_EMPRESA LIMIT 1");
$nomeEmpresa = $resEmp[0]['RAZAO_SOCIAL'] ?? 'PrimeSys';

// Helper Horas/Segundos
function h2sec($str) {
    if (empty($str)) return 0;
    list($h,$m,$s) = array_pad(explode(':', $str), 3, 0);
    return ((int)$h * 3600) + ((int)$m * 60) + (int)$s;
}
function sec2h($sec) {
    $h = floor($sec / 3600);
    $m = floor(($sec / 60) % 60);
    $s = $sec % 60;
    return sprintf('%02d:%02d:%02d', $h, $m, $s);
}

// Busca clientes do parceiro
$clientes = $db->Query("SELECT ID_CLIENTE, RAZAO_SOCIAL, VALOR_HORA FROM CLIENTES WHERE ID_PARCEIRO = $idParceiro ORDER BY RAZAO_SOCIAL ASC") ?: [];

$clientesRelatorio = [];
$totGeralQtd = 0;
$totGeralSegundos = 0;
$totGeralValor = 0;
$totaisPorCliente = [];

foreach ($clientes as $cli) {
    $idCli = (int)$cli['ID_CLIENTE'];
    $nomeCli = $cli['RAZAO_SOCIAL'];

    // Busca apontamentos do cliente
    $sqlAps = "SELECT AP.*, S.DESCRICAO AS SERVICO_DESC, S.FORMA_COBRANCA, S.VALOR AS SERVICO_VALOR, P.NOME_PROJETO 
               FROM APONTAMENTO_AGENDAMENTOS AP
               INNER JOIN AGENDAMENTOS A ON AP.ID_AGENDAMENTO = A.ID_AGENDAMENTO
               LEFT JOIN SERVICOS S ON AP.ID_SERVICO = S.ID_SERVICO
               LEFT JOIN PROJETOS P ON S.ID_PROJETO = P.ID_PROJETO
               WHERE A.ID_CLIENTE = $idCli
                 AND AP.DATA_APONTAMENTO BETWEEN '$dataIni' AND '$dataFim'
               ORDER BY AP.DATA_APONTAMENTO ASC, AP.HORA_INICIAL ASC";
    $apontamentos = $db->Query($sqlAps) ?: [];

    // Busca serviços mensais do cliente
    $sqlMensais = "SELECT S.*, P.NOME_PROJETO 
                   FROM SERVICOS S 
                   INNER JOIN PROJETOS P ON S.ID_PROJETO = P.ID_PROJETO 
                   WHERE P.ID_CLIENTE = $idCli AND S.FORMA_COBRANCA = 2";
    $servicosMensais = $db->Query($sqlMensais) ?: [];

    $itensCliente = [];
    $subtotalQtd = 0;
    $subtotalSegundos = 0;
    $subtotalValor = 0;
    $mensaisProcessados = [];

    foreach ($apontamentos as $ap) {
        $formaCob = (int)($ap['FORMA_COBRANCA'] ?? 1);
        $valServico = (float)($ap['SERVICO_VALOR'] ?? 0);
        $valHoraCli = (float)($cli['VALOR_HORA'] ?? 0);
        $seg = h2sec($ap['TOTAL_HORAS_APONTAMENTO']);
        $valCobrado = 0;

        $modalidadeStr = 'Por Hora';
        if ($formaCob == 1) {
            $modalidadeStr = 'Por Hora';
            $valUnit = ($valServico > 0) ? $valServico : $valHoraCli;
            $valCobrado = round(($seg / 3600) * $valUnit, 2);
        } elseif ($formaCob == 2) {
            $modalidadeStr = 'Mensal';
            $idSrv = (int)$ap['ID_SERVICO'];
            if (!in_array($idSrv, $mensaisProcessados)) {
                $valCobrado = $valServico;
                $mensaisProcessados[] = $idSrv;
            } else {
                $valCobrado = 0;
            }
        } elseif ($formaCob == 3) {
            $modalidadeStr = 'Por Execução';
            $valCobrado = $valServico;
        }

        $subtotalQtd++;
        $subtotalSegundos += $seg;
        $subtotalValor += $valCobrado;

        $itensCliente[] = [
            'data' => date('d/m/Y', strtotime($ap['DATA_APONTAMENTO'])),
            'hora_ini' => substr($ap['HORA_INICIAL'], 0, 5),
            'hora_fim' => substr($ap['HORA_FINAL'], 0, 5),
            'tempo' => $ap['TOTAL_HORAS_APONTAMENTO'],
            'servico_projeto' => ($ap['SERVICO_DESC'] ? "Serviço: {$ap['SERVICO_DESC']}" : '') . ($ap['NOME_PROJETO'] ? " | Proj: {$ap['NOME_PROJETO']}" : ''),
            'modalidade' => $modalidadeStr,
            'descricao' => $ap['DESCRICAO_APONTAMENTO'] ?: '-',
            'valor' => $valCobrado
        ];
    }

    // Inclui serviços mensais sem nenhum apontamento no período
    foreach ($servicosMensais as $sm) {
        $idSm = (int)$sm['ID_SERVICO'];
        if (!in_array($idSm, $mensaisProcessados)) {
            $valM = (float)$sm['VALOR'];
            $subtotalValor += $valM;
            $itensCliente[] = [
                'data' => date('d/m/Y', strtotime($dataIni)),
                'hora_ini' => '-',
                'hora_fim' => '-',
                'tempo' => '00:00:00',
                'servico_projeto' => "Serviço: {$sm['DESCRICAO']} | Proj: {$sm['NOME_PROJETO']}",
                'modalidade' => 'Mensal',
                'descricao' => 'Cobrança Fixa Mensal do Serviço',
                'valor' => $valM
            ];
            $mensaisProcessados[] = $idSm;
        }
    }

    if (!empty($itensCliente)) {
        $totGeralQtd += $subtotalQtd;
        $totGeralSegundos += $subtotalSegundos;
        $totGeralValor += $subtotalValor;

        $clientesRelatorio[] = [
            'nome_cliente' => $nomeCli,
            'total_apontamentos' => $subtotalQtd,
            'tempo_total' => sec2h($subtotalSegundos),
            'valor_total' => $subtotalValor,
            'itens' => $itensCliente
        ];

        $totaisPorCliente[] = [
            'nome' => $nomeCli,
            'qtd_apontamentos' => $subtotalQtd,
            'tempo_total' => sec2h($subtotalSegundos),
            'valor_total' => $subtotalValor
        ];
    }
}

echo json_encode([
    'empresa' => $nomeEmpresa,
    'fechamento' => $fec,
    'parceiro' => $fec['PARCEIRO_NOME'],
    'periodo' => date('d/m/Y', strtotime($dataIni)) . ' a ' . date('d/m/Y', strtotime($dataFim)),
    'clientes' => $clientesRelatorio,
    'totalizador' => [
        'qtd_geral' => $totGeralQtd,
        'tempo_geral' => sec2h($totGeralSegundos),
        'valor_geral' => $totGeralValor,
        'clientes' => $totaisPorCliente
    ]
]);
exit;