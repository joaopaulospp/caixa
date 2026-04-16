<?php
require_once 'conexao.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    die("Erro: variável \\$pdo não encontrada ou não é uma instância de PDO. Verifique conexao.php.");
}
$conn = $pdo;

/* ===== helpers ===== */
function runQuery(PDO $conn, string $sql, array $params = []) {
    $stmt = $conn->prepare($sql);
    if (!$stmt) return false;
    $stmt->execute($params);
    return $stmt;
}

function sanitize($data) {
    return htmlspecialchars(trim((string)$data), ENT_QUOTES, 'UTF-8');
}

function moneyToFloat($value): float {
    if ($value === null) return 0.0;
    $v = trim((string)$value);
    if ($v === '') return 0.0;
    $v = preg_replace('/[^
\d,.\-]/', '', $v);
    if (strpos($v, ',') !== false) {
        $v = str_replace('.', '', $v);
        $v = str_replace(',', '.', $v);
    }
    if ($v === '-' || $v === '') return 0.0;
    return (float)$v;
}

function formatBRL($value): string {
    return number_format(moneyToFloat($value), 2, ',', '.');
}

/**
 * Parse OFX file and extract transactions.
 * Supports both SGML (Sicoob) and XML (Inter) formats.
 */
function parseOFX($filePath) {
    $content = file_get_contents($filePath);
    $transactions = [];

    // Extrair blocos de transações usando regex
    preg_match_all('/<STMTTRN>(.*?)<\/STMTTRN>/s', $content, $matches);

    foreach ($matches[1] as $stmttrn) {
        // Extrair campos usando regex
        $trntype = preg_match('/<TRNTYPE>(.*?)<\/TRNTYPE>/s', $stmttrn, $m) ? $m[1] : '';
        $dtposted = preg_match('/<DTPOSTED>(.*?)<\/DTPOSTED>/s', $stmttrn, $m) ? $m[1] : '';
        $trnamt = preg_match('/<TRNAMT>(.*?)<\/TRNAMT>/s', $stmttrn, $m) ? $m[1] : '';
        $memo = preg_match('/<MEMO>(.*?)<\/MEMO>/s', $stmttrn, $m) ? $m[1] : '';

        if ($trntype && $dtposted && $trnamt) {
            $amount = abs((float)$trnamt);
            $type = (strtoupper($trntype) === 'CREDIT') ? 'credito' : 'debito';

            // Date format: remove timezone if present
            $date = preg_replace('/\[.*\]/', '', $dtposted);
            if (strlen($date) > 8) {
                $date = substr($date, 0, 8);
            }
            $date = date('Y-m-d', strtotime($date));

            $transactions[] = [
                'data' => $date,
                'descricao' => $memo ?: 'Transação OFX',
                'valor' => number_format($amount, 2, '.', ''),
                'tipo' => $type,
                'status' => 'pending'
            ];
        }
    }

    return $transactions;
}

/* ===== UPDATE INLINE ===== */
if (isset($_POST['action']) && $_POST['action'] === 'update_inline' && isset($_POST['id'], $_POST['field'], $_POST['value'])) {
    $id = (int)$_POST['id'];
    $field = $_POST['field'];
    $value = sanitize($_POST['value']);
    if (in_array($field, ['descricao', 'valor']) && runQuery($conn, "UPDATE caixa SET $field = ? WHERE id = ? AND status = 'pending'", [$value, $id])) {
        $_SESSION['success_msg'] = 'Campo atualizado!';
    } else {
        $_SESSION['error_msg'] = 'Erro ao atualizar!';
    }
    header('Location: caixa.php');
    exit;
}

/* ===== UPLOAD OFX ===== */
$upload_msg = '';
$pending_transactions = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['ofx_file'])) {
    $file = $_FILES['ofx_file'];
    if ($file['error'] === UPLOAD_ERR_OK) {
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        if (strtolower($ext) === 'ofx') {
            $tempPath = $file['tmp_name'];
            $transactions = parseOFX($tempPath);
            if (count($transactions) === 0) {
                $upload_msg = 'Erro ao parsear o arquivo OFX. Verifique o formato.';
            } else {
                // Insert pending transactions into caixa
                foreach ($transactions as $txn) {
                    runQuery($conn, "INSERT INTO caixa (data, descricao, valor, tipo, status) VALUES (?, ?, ?, ?, 'pending')", [
                        $txn['data'], $txn['descricao'], $txn['valor'], $txn['tipo']
                    ]);
                }
                $upload_msg = 'Arquivo importado com sucesso. ' . count($transactions) . ' transações pendentes aguardam aprovação.';
            }
        } else {
            $upload_msg = 'Arquivo deve ser .ofx';
        }
    } else {
        $upload_msg = 'Erro no upload.';
    }
}

/* ===== APPROVE TRANSACTION ===== */
if (isset($_POST['action']) && $_POST['action'] === 'approve' && isset($_POST['id'])) {
    $id = (int)$_POST['id'];
    $selected_debt_id = isset($_POST['debt_id']) ? (int)$_POST['debt_id'] : null;
    $stmt = runQuery($conn, "SELECT * FROM caixa WHERE id = ?", [$id]);
    if ($stmt && $row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        runQuery($conn, "UPDATE caixa SET status = 'approved' WHERE id = ?", [$id]);
        // Reconciliation for debit transactions
        if ($row['tipo'] === 'debito') {
            $debt = null;
            if ($selected_debt_id) {
                // Use selected debt
                $stmt2 = runQuery($conn, "SELECT * FROM debito WHERE id = ?", [$selected_debt_id]);
                $debt = $stmt2 ? $stmt2->fetch(PDO::FETCH_ASSOC) : null;
            } else {
                // Automatic reconciliation
                $stmt2 = runQuery($conn, "SELECT * FROM debito WHERE nome LIKE ? AND ABS(valor_parcela - ?) < 0.01 AND (vencimento BETWEEN DATE_SUB(?, INTERVAL 30 DAY) AND DATE_ADD(?, INTERVAL 30 DAY)) AND valor_pago < total LIMIT 1", [
                    '%' . $row['descricao'] . '%', $row['valor'], $row['data'], $row['data']
                ]);
                $debt = $stmt2 ? $stmt2->fetch(PDO::FETCH_ASSOC) : null;
            }
            if ($debt) {
                // Registrar pagamento automaticamente no debito
                $currentPago = moneyToFloat($debt['valor_pago']);
                $newPago = $currentPago + (float)$row['valor'];
                $parcelasPagas = (int)$debt['parcelas_pagas'];
                $valorParcela = moneyToFloat($debt['valor_parcela']);
                // Incrementa se o novo total pago cobre mais parcelas
                while ($parcelasPagas < (int)$debt['quantidade_parcelas'] && $newPago >= $valorParcela * ($parcelasPagas + 1)) {
                    $parcelasPagas++;
                }
                runQuery($conn, "UPDATE debito SET valor_pago = ?, parcelas_pagas = ?, data_pagamento = ? WHERE id = ?", [
                    number_format($newPago, 2, '.', ''), $parcelasPagas, $row['data'], $debt['id']
                ]);
                runQuery($conn, "INSERT INTO pagamentos (debito_id, data_pagamento, valor_pagamento) VALUES (?, ?, ?)", [
                    $debt['id'], $row['data'], $row['valor']
                ]);
                $_SESSION['success_msg'] = 'Transação aprovada e pagamento reconciliado!';
            } else {
                $_SESSION['success_msg'] = 'Transação aprovada (sem reconciliação)!';
            }
        } else {
            $_SESSION['success_msg'] = 'Transação aprovada!';
        }
    }
    header('Location: caixa.php');
    exit;
}

/* ===== DELETE PENDING ===== */
if (isset($_POST['action']) && $_POST['action'] === 'delete_pending' && isset($_POST['id'])) {
    $id = (int)$_POST['id'];
    runQuery($conn, "DELETE FROM caixa WHERE id = ? AND status = 'pending'", [$id]);
    $_SESSION['success_msg'] = 'Transação pendente excluída!';
    header('Location: caixa.php');
    exit;
}

/* ===== FILTROS ===== */
$mes = isset($_GET['mes']) ? (int)$_GET['mes'] : (int)date('n');
$ano = isset($_GET['ano']) ? (int)$_GET['ano'] : (int)date('Y');
$status = isset($_GET['status']) ? $_GET['status'] : 'approved'; // approved or pending

if ($mes < 1 || $mes > 12) $mes = (int)date('n');

$registrosQuery = "
    SELECT c.*, COALESCE(d.nome, 'N/A') AS nome_cliente
    FROM caixa c
    LEFT JOIN debito d ON c.id IN (
        SELECT p.debito_id FROM pagamentos p WHERE p.data_pagamento = c.data AND ABS(p.valor_pagamento - c.valor) < 0.01
    )
    WHERE YEAR(c.data) = ? AND MONTH(c.data) = ?
";
$params = [$ano, $mes];

if ($status !== 'all') {
    $registrosQuery .= " AND c.status = ?";
    $params[] = $status;
}

$registrosQuery .= " ORDER BY c.data DESC";
$registrosResult = runQuery($conn, $registrosQuery, $params);

$totaisQuery = "SELECT SUM(CASE WHEN tipo = 'credito' THEN valor ELSE 0 END) AS total_credito,
                        SUM(CASE WHEN tipo = 'debito' THEN valor ELSE 0 END) AS total_debito
                 FROM caixa WHERE YEAR(data) = ? AND MONTH(data) = ?";
$totaisResult = runQuery($conn, $totaisQuery, [$ano, $mes]);

$registros = $registrosResult ? $registrosResult->fetchAll(PDO::FETCH_ASSOC) : [];
$totais = $totaisResult ? $totaisResult->fetch(PDO::FETCH_ASSOC) : [];

$totalCredito = moneyToFloat($totais['total_credito'] ?? 0);
$totalDebito = moneyToFloat($totais['total_debito'] ?? 0);
$saldo = $totalCredito - $totalDebito;

$totalRegistros = count($registros);

$meses = [
    1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril',
    5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
    9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'
];
$mesExtenso = $meses[$mes] ?? '';

$success_msg = $_SESSION['success_msg'] ?? '';
$error_msg = $_SESSION['error_msg'] ?? '';
unset($_SESSION['success_msg'], $_SESSION['error_msg']);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>JME Telecom - Caixa</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* ... (CSS original mantido, adicione o seguinte para layout similar ao Nibo) */
        .concile-container { margin-top: 20px; }
        .concile-pair { display: flex; margin-bottom: 20px; gap: 20px; }
        .card { background: #fff; border: 1px solid #ddd; padding: 15px; flex: 1; border-radius: 8px; }
        .card.bank-side { background: #f9f9f9; }
        .card.nibo-side { background: #fff; }
        .transaction-card-tabs { margin-bottom: 10px; }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        .tabs { display: flex; gap: 10px; }
        .tab { padding: 8px 12px; background: #eee; cursor: pointer; border-radius: 4px; }
        .tab.active { background: #007bff; color: #fff; }
        .concile-area { text-align: center; margin-top: 10px; }
        .btn-conciliate { background: #28a745; color: #fff; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; }
        .btn-conciliate:disabled { background: #ccc; }
        /* ... (restante do CSS) */
    </style>
</head>
<body>
<!-- ... (HTML header, sidebar, etc. mantido) -->

<main class="main" role="main">
    <!-- ... (títulos e badges mantidos) -->

    <div class="concile-container">
        <div class="concile-order-by">
            <label>Ordenar por</label>
            <label><input type="radio" name="orderBy" value="rank" checked> Relevância</label>
            <label><input type="radio" name="orderBy" value="date"> Data</label>
        </div>

        <div class="concile-pair" ng-repeat="reconciliation in Model">
            <!-- Movimentações importadas (Banco) -->
            <div class="card bank-side">
                <h4>Transação Importada</h4>
                <p>Data: <?php echo htmlspecialchars(date('d/m/Y', strtotime($row['data']))); ?></p>
                <p>Descrição: <?php echo htmlspecialchars($row['descricao']); ?></p>
                <p>Valor: R$ <?php echo formatBRL($row['valor']); ?></p>
                <p>Tipo: <?php echo htmlspecialchars($row['tipo']); ?></p>
            </div>

            <!-- Extrato no Nibo (Caixa) -->
            <div class="card nibo-side">
                <div class="transaction-card-tabs">
                    <div class="tabs">
                        <div class="tab active" onclick="showTab(this, 'sugestao')">Sugestão</div>
                        <div class="tab" onclick="showTab(this, 'nova')">Nova Transação</div>
                        <div class="tab" onclick="showTab(this, 'buscar')">Buscar</div>
                    </div>
                </div>
                <div id="sugestao" class="tab-content active">
                    <!-- Sugestões automáticas (similar ao Nibo) -->
                    <p>Sugestões baseadas em data/valor...</p>
                </div>
                <div id="nova" class="tab-content">
                    <!-- Form para nova transação -->
                    <form>
                        <input type="text" placeholder="Descrição">
                        <input type="number" placeholder="Valor">
                        <!-- Outros campos -->
                        <button type="submit">Criar</button>
                    </form>
                </div>
                <div id="buscar" class="tab-content">
                    <!-- Busca manual -->
                    <input type="text" placeholder="Buscar transações">
                    <ul id="search-results"></ul>
                </div>
            </div>

            <!-- Botão de OK -->
            <div class="concile-area">
                <form method="POST">
                    <input type="hidden" name="action" value="approve">
                    <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                    <button type="submit" class="btn-conciliate">OK</button>
                </form>
            </div>
        </div>
    </div>

    <!-- ... (footer mantido) -->
</main>

<script>
    function showTab(tab, tabName) {
        const tabs = tab.parentElement.children;
        for (let t of tabs) t.classList.remove('active');
        tab.classList.add('active');
        const contents = tab.closest('.card').querySelectorAll('.tab-content');
        for (let c of contents) c.classList.remove('active');
        document.getElementById(tabName).classList.add('active');
    }
    // ... (scripts originais)
</script>

</body>
</html>