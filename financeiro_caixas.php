<?php
session_start();

// Trava de segurança: Apenas administradores Master possuem acesso ao Financeiro
if (!isset($_SESSION['id_usuario']) || !($_SESSION['master'] ?? false)) {
    header("Location: home_suporte.php?error=" . urlencode("Acesso negado. Área restrita ao financeiro master."));
    exit;
}

require_once __DIR__ . '/php/database.php';
require_once __DIR__ . '/php/Sidebar.php';

$isMaster = $_SESSION['master'] ?? false;
$nomeUsuario = $_SESSION['nome'] ?? 'Administrador';
$db = new database();

// --- PROCESSAMENTO DE FORMULÁRIOS (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';

    // 1. Salvar ou Editar Banco/Caixa
    if ($acao === 'salvar_banco') {
        $idBanco = (int)($_POST['id_banco'] ?? 0);
        $descricao = addslashes($_POST['descricao'] ?? '');
        $saldoInicial = (float)str_replace(['.', ','], ['', '.'], $_POST['saldo_inicial'] ?? '0');
        $desconsiderarBalanco = isset($_POST['desconsiderar_no_balanco']) ? 1 : 0;
        $inativo = isset($_POST['inativo']) ? 1 : 0;

        if ($idBanco > 0) {
            $sql = "UPDATE BANCOS SET 
                        DESCRICAO = '$descricao', 
                        SALDO_INICIAL = $saldoInicial, 
                        DESCONSIDERAR_NO_BALANCO = $desconsiderarBalanco, 
                        INATIVO = $inativo 
                    WHERE ID_BANCO = $idBanco";
        } else {
            $sql = "INSERT INTO BANCOS (DESCRICAO, SALDO_INICIAL, DESCONSIDERAR_NO_BALANCO, INATIVO) 
                    VALUES ('$descricao', $saldoInicial, $desconsiderarBalanco, $inativo)";
        }

        $db->Query($sql);
        header("Location: financeiro_caixas.php?msg=sucesso");
        exit;
    }

    // 2. Registrar Transferência Entre Caixas
    if ($acao === 'transferencia') {
        $bancoOrigem = (int)$_POST['id_banco_origem'];
        $bancoDestino = (int)$_POST['id_banco_destino'];
        $valor = (float)str_replace(['.', ','], ['', '.'], $_POST['valor_transferencia'] ?? '0');
        $observacao = addslashes($_POST['observacao'] ?? '');

        $agora = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));
        $dataTransf = $agora->format('Y-m-d');

        if ($bancoOrigem !== $bancoDestino && $valor > 0) {
            $sql = "INSERT INTO TRANSFERENCIAS_BANCARIAS (DATA_TRANSFERENCIA, ID_BANCO_ORIGEM, ID_BANCO_DESTINO, VALOR_TRANSFERENCIA, OBSERVACAO) 
                    VALUES ('$dataTransf', $bancoOrigem, $bancoDestino, $valor, '$observacao')";
            $db->Query($sql);
            header("Location: financeiro_caixas.php?msg=transf_ok");
            exit;
        }
    }
}

// --- CONSULTAS E EXTRATO CORRIGIDO ---
$bancos = $db->Query("SELECT * FROM BANCOS ORDER BY ID_BANCO DESC");
$bancosAtivos = $db->Query("SELECT ID_BANCO, DESCRICAO FROM BANCOS WHERE INATIVO IS NULL OR INATIVO = 0 ORDER BY DESCRICAO ASC");

$bancoSelecionado = (int)($_GET['banco_extrato'] ?? 0);
$dataInicio = $_GET['data_inicio'] ?? date('Y-m-01');
$dataFim = $_GET['data_fim'] ?? date('Y-m-t');

$extrato = [];
$saldoAtual = 0;

if ($bancoSelecionado > 0) {
    $resB = $db->Query("SELECT * FROM BANCOS WHERE ID_BANCO = $bancoSelecionado");
    $infoBanco = $resB[0] ?? null;

    if ($infoBanco) {
        $saldoInicialCadastro = (float)$infoBanco['SALDO_INICIAL'];

        // 1. CALCULA O SALDO ANTERIOR (Movimentações antes da dataInicio)
        $sqlAnterior = "
            SELECT COALESCE(SUM(VALOR_MOV), 0) AS TOTAL_ANTERIOR FROM (
                SELECT VALOR_TRANSFERENCIA AS VALOR_MOV
                FROM TRANSFERENCIAS_BANCARIAS 
                WHERE ID_BANCO_DESTINO = $bancoSelecionado AND DATA_TRANSFERENCIA < '$dataInicio'
                
                UNION ALL
                
                SELECT (VALOR_TRANSFERENCIA * -1) AS VALOR_MOV
                FROM TRANSFERENCIAS_BANCARIAS 
                WHERE ID_BANCO_ORIGEM = $bancoSelecionado AND DATA_TRANSFERENCIA < '$dataInicio'
                
                UNION ALL
                
                SELECT CASE WHEN UPPER(M.PAGREC) = 'R' THEN P.VALOR_BAIXADO ELSE (P.VALOR_BAIXADO * -1) END AS VALOR_MOV
                FROM MOVIMENTO_FINANCEIRO_PARCELAS P
                INNER JOIN MOVIMENTO_FINANCEIRO M ON P.ID_FIN_MOV = M.ID_FIN_MOV
                WHERE P.ID_BANCO = $bancoSelecionado 
                  AND P.DATA_BAIXA IS NOT NULL 
                  AND P.DATA_BAIXA < '$dataInicio'
            ) AS MOV_ANTERIORES
        ";
        
        $resAnterior = $db->Query($sqlAnterior);
        $totalAnterior = (float)($resAnterior[0]['TOTAL_ANTERIOR'] ?? 0);
        $saldoAtual = $saldoInicialCadastro + $totalAnterior;

        // 2. CONSULTA UNIFICADA DO PERÍODO (Com LEFT JOIN para garantir exibição)
        $sqlMov = "
            SELECT 
                T.DATA_TRANSFERENCIA AS DATA_MOV,
                CASE WHEN T.ID_BANCO_DESTINO = $bancoSelecionado THEN 'E' ELSE 'S' END AS TIPO_MOV,
                T.VALOR_TRANSFERENCIA AS VALOR,
                CONCAT('Transferência ', IF(T.ID_BANCO_DESTINO = $bancoSelecionado, 'Recebida', 'Enviada')) AS DESCRICAO_MOV
            FROM TRANSFERENCIAS_BANCARIAS T
            WHERE (T.ID_BANCO_ORIGEM = $bancoSelecionado OR T.ID_BANCO_DESTINO = $bancoSelecionado)
              AND T.DATA_TRANSFERENCIA BETWEEN '$dataInicio' AND '$dataFim'

            UNION ALL

            SELECT 
                P.DATA_BAIXA AS DATA_MOV,
                CASE WHEN UPPER(M.PAGREC) = 'R' THEN 'E' ELSE 'S' END AS TIPO_MOV,
                P.VALOR_BAIXADO AS VALOR,
                CONCAT(
                    IF(UPPER(M.PAGREC) = 'R', 'Recebimento: ', 'Pagamento: '), 
                    M.DESCRICAO, 
                    ' (Parc. #', P.PARCELA, ')',
                    COALESCE(CONCAT(' - ', C.RAZAO_SOCIAL), '')
                ) AS DESCRICAO_MOV
            FROM MOVIMENTO_FINANCEIRO_PARCELAS P
            INNER JOIN MOVIMENTO_FINANCEIRO M ON P.ID_FIN_MOV = M.ID_FIN_MOV
            LEFT JOIN CLIENTES C ON M.ID_CLIENTE = C.ID_CLIENTE
            WHERE P.ID_BANCO = $bancoSelecionado
              AND P.DATA_BAIXA IS NOT NULL
              AND P.DATA_BAIXA BETWEEN '$dataInicio' AND '$dataFim'

            ORDER BY DATA_MOV ASC
        ";

        $movimentacoes = $db->Query($sqlMov);

        if (!empty($movimentacoes)) {
            foreach ($movimentacoes as $mov) {
                $isEntrada = ($mov['TIPO_MOV'] === 'E');
                $valorMov = (float)$mov['VALOR'];

                if ($isEntrada) {
                    $saldoAtual += $valorMov;
                } else {
                    $saldoAtual -= $valorMov;
                }

                $extrato[] = [
                    'data' => $mov['DATA_MOV'],
                    'descricao' => $mov['DESCRICAO_MOV'],
                    'tipo' => $isEntrada ? 'ENTRADA' : 'SAIDA',
                    'valor' => $valorMov,
                    'saldo_momento' => $saldoAtual
                ];
            }
        }
    }
}

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Caixas e Bancos - PrimeSys</title>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/suporte.css">
    <link rel="stylesheet" href="css/financeiro.css">
</head>
<body>

    <header class="top-header">
        <div class="brand"><i class="fa-solid fa-headset"></i> PrimeSys Financeiro</div>
        <div class="user-info">
            <span><i class="fa-solid fa-user"></i> <strong><?= htmlspecialchars($nomeUsuario) ?></strong> <small>(Master)</small></span>
            <a href="logout.php" class="btn-logout"><i class="fa-solid fa-right-from-bracket"></i> Sair</a>
        </div>
    </header>

    <div class="app-container">
        <?php 
            $sidebar = new Sidebar('caixas_bancarios', $isMaster); 
            $sidebar->render(); 
        ?>

        <main class="main-content">
            <h1 class="page-title">Caixas Bancários e Transferências</h1>

            <!-- Barra de Ações -->
            <div class="actions-bar">
                <div></div>
                <div style="display: flex; gap: 10px;">
                    <button class="btn-3d btn-secondary-3d" onclick="openTransferenciaModal()">
                        <i class="fa-solid fa-money-bill-transfer"></i> Transferência entre Caixas
                    </button>
                    <button class="btn-3d btn-primary-3d" onclick="openBancoModal()">
                        <i class="fa-solid fa-plus"></i> Novo Caixa / Banco
                    </button>
                </div>
            </div>

            <!-- Tabela de Bancos Cadastrados -->
            <div class="table-responsive">
                <table class="custom-table">
                    <thead>
                        <tr>
                            <th>#ID</th>
                            <th>Descrição / Banco</th>
                            <th>Saldo Inicial</th>
                            <th>Balanço</th>
                            <th>Status</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($bancos)): ?>
                            <tr><td colspan="6" style="text-align: center;">Nenhum caixa/banco cadastrado.</td></tr>
                        <?php else: ?>
                            <?php foreach ($bancos as $b): ?>
                                <tr>
                                    <td><strong>#<?= $b['ID_BANCO'] ?></strong></td>
                                    <td><?= htmlspecialchars($b['DESCRICAO']) ?></td>
                                    <td>R$ <?= number_format($b['SALDO_INICIAL'], 2, ',', '.') ?></td>
                                    <td>
                                        <?= $b['DESCONSIDERAR_NO_BALANCO'] ? '<span class="badge-tag">Desconsiderar</span>' : '<span class="badge-tag" style="background:#001517; color:#fff;">Considerar</span>' ?>
                                    </td>
                                    <td>
                                        <?= $b['INATIVO'] ? '<span class="badge-tag badge-inativo">Inativo</span>' : '<span class="badge-tag" style="background:#16a34a; color:#fff;">Ativo</span>' ?>
                                    </td>
                                    <td>
                                        <button class="btn-3d btn-secondary-3d btn-sm-3d" onclick='editBanco(<?= json_encode($b) ?>)'>
                                            <i class="fa-solid fa-pen-to-square"></i> Editar
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Seção de Extrato Bancário por Período -->
            <div class="extrato-container">
                <h3 style="color: #001517; margin-bottom: 15px;"><i class="fa-solid fa-file-invoice"></i> Extrato de Movimentação por Período</h3>
                
                <form method="GET" action="financeiro_caixas.php" class="filters-grid">
                    <div class="form-group">
                        <label>Selecione o Caixa:</label>
                        <select name="banco_extrato" required>
                            <option value="">-- Escolha um Banco --</option>
                            <?php foreach ($bancos as $b): ?>
                                <option value="<?= $b['ID_BANCO'] ?>" <?= $bancoSelecionado == $b['ID_BANCO'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($b['DESCRICAO']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Data Início:</label>
                        <input type="date" name="data_inicio" value="<?= $dataInicio ?>" required>
                    </div>

                    <div class="form-group">
                        <label>Data Fim:</label>
                        <input type="date" name="data_fim" value="<?= $dataFim ?>" required>
                    </div>

                    <div class="filters-actions">
                        <button type="submit" class="btn-3d btn-primary-3d">
                            <i class="fa-solid fa-magnifying-glass"></i> Gerar Extrato
                        </button>
                    </div>
                </form>

                <?php if ($bancoSelecionado > 0): ?>
                    <div class="extrato-header" style="margin-top: 20px;">
                        <h4>Extrato: <?= htmlspecialchars($infoBanco['DESCRICAO']) ?></h4>
                        <div class="extrato-saldo-box">
                            Saldo Final no Período: 
                            <span class="<?= $saldoAtual >= 0 ? 'saldo-positivo' : 'saldo-negativo' ?>">
                                R$ <?= number_format($saldoAtual, 2, ',', '.') ?>
                            </span>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="custom-table">
                            <thead>
                                <tr>
                                    <th>Data</th>
                                    <th>Descrição da Movimentação</th>
                                    <th>Tipo</th>
                                    <th>Valor</th>
                                    <th>Saldo Corrente</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($extrato)): ?>
                                    <tr><td colspan="5" style="text-align: center; padding: 15px;">Nenhuma movimentação no período selecionado.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($extrato as $item): ?>
                                        <tr>
                                            <td><?= date('d/m/Y', strtotime($item['data'])) ?></td>
                                            <td><?= htmlspecialchars($item['descricao']) ?></td>
                                            <td>
                                                <span class="badge-tag <?= $item['tipo'] === 'ENTRADA' ? 'valor-entrada' : 'valor-saida' ?>">
                                                    <?= $item['tipo'] ?>
                                                </span>
                                            </td>
                                            <td class="<?= $item['tipo'] === 'ENTRADA' ? 'valor-entrada' : 'valor-saida' ?>">
                                                <?= $item['tipo'] === 'ENTRADA' ? '+' : '-' ?> R$ <?= number_format($item['valor'], 2, ',', '.') ?>
                                            </td>
                                            <td class="<?= $item['saldo_momento'] >= 0 ? 'saldo-positivo' : 'saldo-negativo' ?>">
                                                R$ <?= number_format($item['saldo_momento'], 2, ',', '.') ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- MODAL DE BANCO/CAIXA -->
    <div class="modal-overlay" id="bancoModal">
        <div class="modal-card">
            <div class="modal-header">
                <h3 id="modalBancoTitle"><i class="fa-solid fa-building-columns"></i> Cadastrar Caixa / Banco</h3>
                <i class="fa-solid fa-xmark" style="cursor: pointer;" onclick="closeBancoModal()"></i>
            </div>
            <form method="POST" action="financeiro_caixas.php">
                <input type="hidden" name="acao" value="salvar_banco">
                <input type="hidden" name="id_banco" id="id_banco">
                
                <div class="modal-body">
                    <div class="form-grid">
                        <div class="form-group full-width">
                            <label>Descrição do Banco / Caixa <span class="required-asterisk">*</span></label>
                            <input type="text" name="descricao" id="banco_descricao" required placeholder="Ex: Itaú, Caixa Geral, Cofre">
                        </div>

                        <div class="form-group full-width">
                            <label>Saldo Inicial (R$)</label>
                            <input type="text" name="saldo_inicial" id="banco_saldo_inicial" placeholder="0,00">
                        </div>

                        <div class="form-group full-width" style="background: #f8f9fa; padding: 10px; border-radius: 6px;">
                            <label style="cursor: pointer; display: flex; align-items: center; gap: 8px;">
                                <input type="checkbox" name="desconsiderar_no_balanco" id="banco_desconsiderar" value="1">
                                Desconsiderar no Balanço Geral
                            </label>
                        </div>

                        <div class="form-group full-width" style="background: #f8f9fa; padding: 10px; border-radius: 6px;">
                            <label style="cursor: pointer; display: flex; align-items: center; gap: 8px;">
                                <input type="checkbox" name="inativo" id="banco_inativo" value="1">
                                Inativo
                            </label>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn-3d btn-danger-3d" onclick="closeBancoModal()">Cancelar</button>
                        <button type="submit" class="btn-3d btn-success-3d"><i class="fa-solid fa-floppy-disk"></i> Salvar Banco</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL DE TRANSFERÊNCIA -->
    <div class="modal-overlay" id="transferenciaModal">
        <div class="modal-card">
            <div class="modal-header">
                <h3><i class="fa-solid fa-money-bill-transfer"></i> Transferência Entre Caixas</h3>
                <i class="fa-solid fa-xmark" style="cursor: pointer;" onclick="closeTransferenciaModal()"></i>
            </div>
            <form method="POST" action="financeiro_caixas.php">
                <input type="hidden" name="acao" value="transferencia">
                <div class="modal-body">
                    <div class="form-grid">
                        
                        <div class="form-group full-width">
                            <label>Caixa de Origem (Saída) <span class="required-asterisk">*</span></label>
                            <select name="id_banco_origem" required style="padding: 10px; border-radius: 6px; border: 1.5px solid #cbd5e1;">
                                <option value="">-- Selecione a Origem --</option>
                                <?php foreach ($bancosAtivos as $ba): ?>
                                    <option value="<?= $ba['ID_BANCO'] ?>"><?= htmlspecialchars($ba['DESCRICAO']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group full-width">
                            <label>Caixa de Destino (Entrada) <span class="required-asterisk">*</span></label>
                            <select name="id_banco_destino" required style="padding: 10px; border-radius: 6px; border: 1.5px solid #cbd5e1;">
                                <option value="">-- Selecione o Destino --</option>
                                <?php foreach ($bancosAtivos as $ba): ?>
                                    <option value="<?= $ba['ID_BANCO'] ?>"><?= htmlspecialchars($ba['DESCRICAO']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group full-width">
                            <label>Valor da Transferência (R$) <span class="required-asterisk">*</span></label>
                            <input type="text" name="valor_transferencia" required placeholder="0,00">
                        </div>

                        <div class="form-group full-width">
                            <label>Observação / Motivo</label>
                            <input type="text" name="observacao" placeholder="Ex: Suprimento de caixa">
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn-3d btn-danger-3d" onclick="closeTransferenciaModal()">Cancelar</button>
                        <button type="submit" class="btn-3d btn-success-3d"><i class="fa-solid fa-check"></i> Realizar Transferência</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openBancoModal() {
            document.getElementById('id_banco').value = '';
            document.getElementById('banco_descricao').value = '';
            document.getElementById('banco_saldo_inicial').value = '0,00';
            document.getElementById('banco_desconsiderar').checked = false;
            document.getElementById('banco_inativo').checked = false;
            document.getElementById('modalBancoTitle').innerHTML = '<i class="fa-solid fa-building-columns"></i> Cadastrar Caixa / Banco';
            document.getElementById('bancoModal').classList.add('active');
        }

        function closeBancoModal() {
            document.getElementById('bancoModal').classList.remove('active');
        }

        function editBanco(data) {
            document.getElementById('id_banco').value = data.ID_BANCO;
            document.getElementById('banco_descricao').value = data.DESCRICAO;
            document.getElementById('banco_saldo_inicial').value = data.SALDO_INICIAL ? data.SALDO_INICIAL.toString().replace('.', ',') : '0,00';
            document.getElementById('banco_desconsiderar').checked = (data.DESCONSIDERAR_NO_BALANCO == 1);
            document.getElementById('banco_inativo').checked = (data.INATIVO == 1);
            document.getElementById('modalBancoTitle').innerHTML = '<i class="fa-solid fa-pen-to-square"></i> Editar Caixa / Banco #' + data.ID_BANCO;
            document.getElementById('bancoModal').classList.add('active');
        }

        function openTransferenciaModal() {
            document.getElementById('transferenciaModal').classList.add('active');
        }

        function closeTransferenciaModal() {
            document.getElementById('transferenciaModal').classList.remove('active');
        }
    </script>
</body>
</html>