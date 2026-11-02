<?php
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/vendors_lib.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');
date_default_timezone_set('Asia/Karachi');

if ($role !== 'Admin') {
    echo '<div class="alert alert-danger">Only admins can view the cash summary.</div></div></body></html>';
    exit();
}

$today = date('Y-m-d');
$from = valid_ymd($_GET['from'] ?? '') ? $_GET['from'] : $today;
$to = valid_ymd($_GET['to'] ?? '') ? $_GET['to'] : $today;
if ($from > $to) { [$from, $to] = [$to, $from]; }
$fromDt = $from . ' 00:00:00';
$toDt = $to . ' 23:59:59';

function one(mysqli $conn, string $sql, string $types, array $params): float
{
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    return round((float)($stmt->get_result()->fetch_row()[0] ?? 0), 2);
}
function by_method(mysqli $conn, string $sql, array $params): array
{
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ss', ...$params);
    $stmt->execute();
    $out = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_NUM) as [$m, $amt]) {
        $out[$m ?: 'Cash'] = ($out[$m ?: 'Cash'] ?? 0) + round((float)$amt, 2);
    }
    return $out;
}

$cashSales = one($conn, "SELECT COALESCE(SUM(TotalAmount),0) FROM Invoices WHERE PaymentType = 'Cash' AND InvoiceDate BETWEEN ? AND ?", 'ss', [$fromDt, $toDt]);
$creditSales = one($conn, "SELECT COALESCE(SUM(TotalAmount),0) FROM Invoices WHERE PaymentType = 'Credit' AND InvoiceDate BETWEEN ? AND ?", 'ss', [$fromDt, $toDt]);
$receipts = by_method($conn, "SELECT Method, SUM(Amount) FROM CustomerPayments WHERE PaymentDate BETWEEN ? AND ? GROUP BY Method", [$fromDt, $toDt]);
$payouts = by_method($conn, "SELECT PaymentMethod, SUM(AmountPaid) FROM VendorPayments WHERE PaymentDate BETWEEN ? AND ? GROUP BY PaymentMethod", [$fromDt, $toDt]);
$cashPurchases = one($conn, "SELECT COALESCE(SUM(TotalAmount),0) FROM PurchaseOrders WHERE Status = 'Received' AND PaymentType = 'Cash' AND OrderDate BETWEEN ? AND ?", 'ss', [$fromDt, $toDt]);
$creditPurchases = one($conn, "SELECT COALESCE(SUM(TotalAmount),0) FROM PurchaseOrders WHERE Status = 'Received' AND PaymentType = 'Credit' AND OrderDate BETWEEN ? AND ?", 'ss', [$fromDt, $toDt]);
$expenses = one($conn, "SELECT COALESCE(SUM(Amount),0) FROM Expenses WHERE ExpenseDate BETWEEN ? AND ?", 'ss', [$from, $to]);

$recAll = array_sum($receipts);
$recCash = $receipts['Cash'] ?? 0;
$payAll = array_sum($payouts);
$payCash = $payouts['Cash'] ?? 0;

$inAll = $cashSales + $recAll;
$inCash = $cashSales + $recCash;
$outAll = $payAll + $cashPurchases + $expenses;
$outCash = $payCash + $cashPurchases + $expenses;
$rs = fn($n) => number_format((float)$n, 2);
$methods = fn(array $a) => implode(', ', array_map(fn($m, $v) => $m . ' ' . number_format($v, 0), array_keys($a), $a));
?>
<style>
@media print { nav.navbar, .no-print { display: none !important; } body.bg-app { background: #fff !important; } }
</style>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
  <h2 class="fw-bold mb-0">Cash Summary</h2>
  <form class="d-flex gap-2 align-items-center no-print" method="get" action="cash_summary.php">
    <input type="date" name="from" class="form-control form-control-sm" value="<?= htmlspecialchars($from) ?>">
    <span>to</span>
    <input type="date" name="to" class="form-control form-control-sm" value="<?= htmlspecialchars($to) ?>">
    <button class="btn btn-sm btn-primary" type="submit">Show</button>
    <button class="btn btn-sm btn-outline-dark" type="button" onclick="window.print()">Print</button>
  </form>
</div>
<div class="small text-muted mb-3">Period: <?= date('d-M-Y', strtotime($from)) ?> to <?= date('d-M-Y', strtotime($to)) ?></div>

<div class="card shadow-sm mb-3" style="max-width: 820px">
  <div class="card-body p-0">
    <table class="table mb-0 align-middle">
      <thead class="table-light"><tr><th></th><th class="text-end">All methods</th><th class="text-end">Cash only</th></tr></thead>
      <tbody>
        <tr class="table-success"><td colspan="3" class="fw-semibold">Money in</td></tr>
        <tr><td>Cash sales</td><td class="text-end"><?= $rs($cashSales) ?></td><td class="text-end"><?= $rs($cashSales) ?></td></tr>
        <tr><td>Received from customers <div class="small text-muted">at credit sales + Receive Payment<?= $receipts ? ' — ' . htmlspecialchars($methods($receipts)) : '' ?></div></td><td class="text-end"><?= $rs($recAll) ?></td><td class="text-end"><?= $rs($recCash) ?></td></tr>
        <tr class="fw-bold"><td>Total in</td><td class="text-end"><?= $rs($inAll) ?></td><td class="text-end"><?= $rs($inCash) ?></td></tr>
        <tr class="table-danger"><td colspan="3" class="fw-semibold">Money out</td></tr>
        <tr><td>Paid to vendors <div class="small text-muted">payouts + paid at credit purchases<?= $payouts ? ' — ' . htmlspecialchars($methods($payouts)) : '' ?></div></td><td class="text-end"><?= $rs($payAll) ?></td><td class="text-end"><?= $rs($payCash) ?></td></tr>
        <tr><td>Cash purchases</td><td class="text-end"><?= $rs($cashPurchases) ?></td><td class="text-end"><?= $rs($cashPurchases) ?></td></tr>
        <tr><td>Expenses</td><td class="text-end"><?= $rs($expenses) ?></td><td class="text-end"><?= $rs($expenses) ?></td></tr>
        <tr class="fw-bold"><td>Total out</td><td class="text-end"><?= $rs($outAll) ?></td><td class="text-end"><?= $rs($outCash) ?></td></tr>
      </tbody>
      <tfoot class="table-light fw-bold fs-5">
        <tr><td>Net</td><td class="text-end <?= $inAll - $outAll < 0 ? 'text-danger' : 'text-success' ?>">Rs. <?= $rs($inAll - $outAll) ?></td><td class="text-end <?= $inCash - $outCash < 0 ? 'text-danger' : 'text-success' ?>">Rs. <?= $rs($inCash - $outCash) ?></td></tr>
      </tfoot>
    </table>
  </div>
</div>
<div class="small text-muted" style="max-width: 820px">
  For reference (not cash movements): credit sales Rs. <?= $rs($creditSales) ?>, credit purchases Rs. <?= $rs($creditPurchases) ?>.
  "Cash only" counts customer receipts and vendor payouts entered with method Cash; expenses and cash purchases are treated as cash.
</div>

</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
