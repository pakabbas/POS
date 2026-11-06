<?php
ob_start();
date_default_timezone_set('Asia/Karachi');
include('includes/header.php');
include('includes/db.php');
require_once __DIR__ . '/includes/inventory_lib.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');
$role = $_SESSION['role'] ?? 'Admin';
if ($role !== 'Admin') {
    ob_end_clean();
    header('Location: pos.php');
    exit();
}

$today = date('Y-m-d');
$range = $_GET['range'] ?? 'today';
$from = $_GET['from'] ?? $today;
$to = $_GET['to'] ?? $today;
if ($range === 'today') { $from = $to = $today; }
elseif ($range === 'week') { $from = date('Y-m-d', strtotime('monday this week')); $to = $today; }
elseif ($range === 'month') { $from = date('Y-m-01'); $to = $today; }
else { $range = 'custom'; }
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = $today;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) $to = $today;
if ($from > $to) { [$from, $to] = [$to, $from]; }
$fromDt = $from . ' 00:00:00';
$toDt = $to . ' 23:59:59';

$type = $_GET['type'] ?? 'all';
$userFilter = (int)($_GET['user'] ?? 0);
$limit = 200;

$types = [
    'all' => 'All',
    'sale' => 'Sales',
    'adjust' => 'Stock adjustments',
    'count' => 'Stock counts',
    'purchase' => 'Purchases',
    'vendor_pay' => 'Vendor payouts',
    'customer_pay' => 'Customer receipts',
    'expense' => 'Expenses',
    'user' => 'Users created',
    'invoice_edit' => 'Invoice edits',
];
if (!isset($types[$type])) $type = 'all';

$users = $conn->query("SELECT UserID, UserName, FullName FROM Users ORDER BY UserName")->fetch_all(MYSQLI_ASSOC);
$userName = [];
foreach ($users as $u) $userName[(int)$u['UserID']] = $u['FullName'] ?: $u['UserName'];

function log_who(?int $id, array $map): string {
    if (!$id) return '—';
    return $map[$id] ?? ('User #' . $id);
}
function log_money($n): string { return 'Rs. ' . number_format((float)$n, 0); }

$events = [];

if ($type === 'all' || $type === 'sale') {
    $sql = "SELECT i.InvoiceID, i.InvoiceNumber, i.InvoiceDate, i.TotalAmount, i.PaymentType, i.Discount, i.UserID,
                   COALESCE(c.CustomerName, NULLIF(i.CustomerName,''), 'Walk-in') AS Cust,
                   (SELECT COALESCE(SUM(ii.Quantity),0) FROM InvoiceItems ii WHERE ii.InvoiceID = i.InvoiceID) AS Peti
            FROM Invoices i LEFT JOIN Customers c ON c.CustomerID = i.CustomerID
            WHERE i.InvoiceDate BETWEEN ? AND ?";
    $params = [$fromDt, $toDt]; $ts = 'ss';
    if ($userFilter) { $sql .= " AND i.UserID = ?"; $ts .= 'i'; $params[] = $userFilter; }
    $st = $conn->prepare($sql); $st->bind_param($ts, ...$params); $st->execute();
    foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $uid = $r['UserID'] ? (int)$r['UserID'] : null;
        $events[] = [
            'when' => $r['InvoiceDate'], 'who' => log_who($uid, $userName),
            'label' => 'Sale',
            'what' => ($r['PaymentType'] === 'Credit' ? 'Credit' : 'Cash') . ' sale ' . $r['InvoiceNumber']
                . ' · ' . $r['Cust'] . ' · ' . qty_fmt($r['Peti']) . ' peti'
                . ((float)$r['Discount'] > 0 ? ' · discount ' . log_money($r['Discount']) : ''),
            'amount' => (float)$r['TotalAmount'],
        ];
    }
}

if ($type === 'all' || $type === 'adjust') {
    $sql = "SELECT sm.MovementDate, sm.MovementType, sm.Quantity, sm.Crates, sm.Notes, sm.PerformedBy, p.ProductName
            FROM StockMovements sm JOIN Products p ON p.ProductID = sm.ProductID
            WHERE sm.ReferenceType = 'Manual' AND sm.MovementDate BETWEEN ? AND ?";
    $params = [$fromDt, $toDt]; $ts = 'ss';
    if ($userFilter) { $sql .= " AND sm.PerformedBy = ?"; $ts .= 'i'; $params[] = $userFilter; }
    $st = $conn->prepare($sql); $st->bind_param($ts, ...$params); $st->execute();
    foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $crates = ($r['Crates'] !== null && $r['Crates'] !== '') ? (float)$r['Crates'] : round((float)$r['Quantity'] * 12, 2);
        $map = ['Waste' => 'Damage / Breakage', 'Expired' => 'Expired / Spoiled', 'Return' => 'Return to vendor', 'Adjustment' => 'Count correction'];
        $label = $map[$r['MovementType']] ?? 'Stock adjustment';
        $uid = $r['PerformedBy'] ? (int)$r['PerformedBy'] : null;
        $events[] = [
            'when' => $r['MovementDate'], 'who' => log_who($uid, $userName),
            'label' => $label,
            'what' => $label . ': ' . crates_fmt($crates) . ' of ' . $r['ProductName'] . ($r['Notes'] ? ' — ' . $r['Notes'] : ''),
            'amount' => null,
        ];
    }
}

if ($type === 'all' || $type === 'count') {
    $sql = "SELECT pi.OpeningStockDate, pi.OpeningStock, pi.ReorderLevel, pi.LastRestockedBy, p.ProductName
            FROM ProductInventory pi JOIN Products p ON p.ProductID = pi.ProductID
            WHERE pi.OpeningStockDate BETWEEN ? AND ?";
    $params = [$fromDt, $toDt]; $ts = 'ss';
    if ($userFilter) { $sql .= " AND pi.LastRestockedBy = ?"; $ts .= 'i'; $params[] = $userFilter; }
    $st = $conn->prepare($sql); $st->bind_param($ts, ...$params); $st->execute();
    foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $uid = $r['LastRestockedBy'] ? (int)$r['LastRestockedBy'] : null;
        $events[] = [
            'when' => $r['OpeningStockDate'], 'who' => log_who($uid, $userName),
            'label' => 'Stock count',
            'what' => 'Set count for ' . $r['ProductName'] . ' → ' . peti_fmt($r['OpeningStock']) . ' (reorder at ' . peti_fmt($r['ReorderLevel']) . ')',
            'amount' => null,
        ];
    }
}

if ($type === 'all' || $type === 'purchase') {
    $sql = "SELECT po.OrderDate, po.PONumber, po.TotalAmount, po.PaymentType, po.CreatedBy, v.VendorName,
                   (SELECT COALESCE(SUM(poi.QuantityReceived),0) FROM PurchaseOrderItems poi WHERE poi.PurchaseOrderID = po.PurchaseOrderID) AS Peti
            FROM PurchaseOrders po JOIN Vendors v ON v.VendorID = po.VendorID
            WHERE po.Status = 'Received' AND po.OrderDate BETWEEN ? AND ?";
    $params = [$fromDt, $toDt]; $ts = 'ss';
    if ($userFilter) { $sql .= " AND po.CreatedBy = ?"; $ts .= 'i'; $params[] = $userFilter; }
    $st = $conn->prepare($sql); $st->bind_param($ts, ...$params); $st->execute();
    foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $uid = $r['CreatedBy'] ? (int)$r['CreatedBy'] : null;
        $events[] = [
            'when' => $r['OrderDate'], 'who' => log_who($uid, $userName),
            'label' => 'Purchase',
            'what' => 'Stock in ' . $r['PONumber'] . ' from ' . $r['VendorName'] . ' · ' . qty_fmt($r['Peti']) . ' peti · ' . $r['PaymentType'],
            'amount' => (float)$r['TotalAmount'],
        ];
    }
}

if ($type === 'all' || $type === 'vendor_pay') {
    $sql = "SELECT vp.PaymentDate, vp.AmountPaid, vp.PaymentMethod, vp.CreatedBy, vp.Notes, v.VendorName
            FROM VendorPayments vp JOIN Vendors v ON v.VendorID = vp.VendorID
            WHERE vp.PaymentDate BETWEEN ? AND ?";
    $params = [$fromDt, $toDt]; $ts = 'ss';
    if ($userFilter) { $sql .= " AND vp.CreatedBy = ?"; $ts .= 'i'; $params[] = $userFilter; }
    $st = $conn->prepare($sql); $st->bind_param($ts, ...$params); $st->execute();
    foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $uid = $r['CreatedBy'] ? (int)$r['CreatedBy'] : null;
        $events[] = [
            'when' => $r['PaymentDate'], 'who' => log_who($uid, $userName),
            'label' => 'Vendor payout',
            'what' => 'Paid ' . $r['VendorName'] . ($r['PaymentMethod'] ? ' via ' . $r['PaymentMethod'] : '') . ($r['Notes'] ? ' — ' . $r['Notes'] : ''),
            'amount' => (float)$r['AmountPaid'],
        ];
    }
}

if ($type === 'all' || $type === 'customer_pay') {
    $sql = "SELECT cp.PaymentDate, cp.Amount, cp.Method, cp.Note, cp.UserID, c.CustomerName
            FROM CustomerPayments cp JOIN Customers c ON c.CustomerID = cp.CustomerID
            WHERE cp.PaymentDate BETWEEN ? AND ?";
    $params = [$fromDt, $toDt]; $ts = 'ss';
    if ($userFilter) { $sql .= " AND cp.UserID = ?"; $ts .= 'i'; $params[] = $userFilter; }
    $st = $conn->prepare($sql); $st->bind_param($ts, ...$params); $st->execute();
    foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $uid = $r['UserID'] ? (int)$r['UserID'] : null;
        $events[] = [
            'when' => $r['PaymentDate'], 'who' => log_who($uid, $userName),
            'label' => 'Customer receipt',
            'what' => 'Received from ' . $r['CustomerName'] . ($r['Method'] ? ' via ' . $r['Method'] : '') . ($r['Note'] ? ' — ' . $r['Note'] : ''),
            'amount' => (float)$r['Amount'],
        ];
    }
}

if (($type === 'all' || $type === 'expense') && !$userFilter) {
    $st = $conn->prepare("SELECT ExpenseDate, CreatedAt, Title, Category, Amount, Person FROM Expenses WHERE ExpenseDate BETWEEN ? AND ?");
    $st->bind_param('ss', $from, $to); $st->execute();
    foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $events[] = [
            'when' => $r['CreatedAt'] ?: ($r['ExpenseDate'] . ' 12:00:00'),
            'who' => $r['Person'] ?: '—',
            'label' => 'Expense',
            'what' => $r['Title'] . ($r['Category'] ? ' (' . $r['Category'] . ')' : ''),
            'amount' => (float)$r['Amount'],
        ];
    }
}

if (($type === 'all' || $type === 'user') && !$userFilter) {
    $st = $conn->prepare("SELECT UserName, FullName, Role, CreatedAt FROM Users WHERE CreatedAt BETWEEN ? AND ?");
    $st->bind_param('ss', $fromDt, $toDt); $st->execute();
    foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $events[] = [
            'when' => $r['CreatedAt'], 'who' => '—',
            'label' => 'User created',
            'what' => ($r['FullName'] ?: $r['UserName']) . ' · login ' . $r['UserName'] . ' · role ' . $r['Role'],
            'amount' => null,
        ];
    }
}


if ($type === 'all' || $type === 'invoice_edit') {
    $sql = "SELECT al.CreatedAt, al.Summary, al.UserID, al.EntityRef
            FROM ActivityLog al
            WHERE al.EventType = 'invoice_edit' AND al.CreatedAt BETWEEN ? AND ?";
    $params = [$fromDt, $toDt]; $ts = 'ss';
    if ($userFilter) { $sql .= " AND al.UserID = ?"; $ts .= 'i'; $params[] = $userFilter; }
    try {
        $st = $conn->prepare($sql); $st->bind_param($ts, ...$params); $st->execute();
        foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
            $uid = $r['UserID'] ? (int)$r['UserID'] : null;
            $events[] = [
                'when' => $r['CreatedAt'], 'who' => log_who($uid, $userName),
                'label' => 'Invoice edit',
                'what' => $r['Summary'],
                'amount' => null,
            ];
        }
    } catch (Throwable $e) {
        // table may not exist yet on a copy without migration
    }
}

usort($events, fn($a, $b) => strcmp($b['when'], $a['when']));
$totalEvents = count($events);
$truncated = $totalEvents > $limit;
$events = array_slice($events, 0, $limit);
?>
<style>
@media print { nav.navbar, .no-print { display: none !important; } body { background:#fff !important; } }
</style>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
  <h2 class="fw-bold mb-0">Logs</h2>
  <button type="button" class="btn btn-outline-dark btn-sm no-print" onclick="window.print()">🖨 Print</button>
</div>
<p class="text-muted small no-print mb-2">Daily-ops activity for theft oversight. Invoice <em>edits</em> are logged here. Invoice <em>delete</em> under Reports is still a hard delete with no audit trail. Showing up to <?= (int)$limit ?> newest events.</p>

<form class="card shadow-sm mb-3 no-print" method="get"><div class="card-body py-2">
  <div class="d-flex flex-wrap gap-2 align-items-center">
    <?php foreach (['today' => 'Today', 'week' => 'This week', 'month' => 'This month'] as $k => $l): ?>
      <a class="btn btn-sm rounded-pill <?= $range === $k ? 'btn-primary' : 'btn-outline-primary' ?>"
         href="logs.php?<?= http_build_query(array_filter(['range' => $k, 'type' => $type !== 'all' ? $type : null, 'user' => $userFilter ?: null])) ?>"><?= $l ?></a>
    <?php endforeach; ?>
    <input type="hidden" name="range" value="custom">
    <input type="date" name="from" class="form-control form-control-sm" style="width:auto" value="<?= htmlspecialchars($from) ?>">
    <span class="small text-muted">to</span>
    <input type="date" name="to" class="form-control form-control-sm" style="width:auto" value="<?= htmlspecialchars($to) ?>">
    <select name="type" class="form-select form-select-sm" style="width:auto">
      <?php foreach ($types as $k => $l): ?><option value="<?= $k ?>" <?= $type === $k ? 'selected' : '' ?>><?= htmlspecialchars($l) ?></option><?php endforeach; ?>
    </select>
    <select name="user" class="form-select form-select-sm" style="width:auto">
      <option value="0">All users</option>
      <?php foreach ($users as $u): ?>
        <option value="<?= (int)$u['UserID'] ?>" <?= $userFilter === (int)$u['UserID'] ? 'selected' : '' ?>><?= htmlspecialchars($u['FullName'] ?: $u['UserName']) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-sm btn-outline-secondary" type="submit">Filter</button>
  </div>
</div></form>

<div class="card shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-sm table-striped mb-0 align-middle">
        <thead class="table-light"><tr><th>When</th><th>Who</th><th>Type</th><th>What happened</th><th class="text-end">Amount</th></tr></thead>
        <tbody>
        <?php if (!$events): ?>
          <tr><td colspan="5" class="text-center text-muted py-4">No activity in this period</td></tr>
        <?php else: foreach ($events as $e): ?>
          <tr>
            <td class="text-nowrap small"><?= date('d M Y, g:i A', strtotime($e['when'])) ?></td>
            <td class="small"><?= htmlspecialchars($e['who']) ?></td>
            <td><span class="badge text-bg-light border"><?= htmlspecialchars($e['label']) ?></span></td>
            <td><?= htmlspecialchars($e['what']) ?></td>
            <td class="text-end text-nowrap"><?= $e['amount'] !== null ? log_money($e['amount']) : '' ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php if ($truncated): ?><div class="card-footer small text-muted">Showing first <?= (int)$limit ?> of <?= (int)$totalEvents ?> events — narrow the filters.</div><?php endif; ?>
</div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body></html>
