<?php
ob_start(); // lets CSV export and redirects run even though header.php prints the page header
date_default_timezone_set('Asia/Karachi');
include('includes/header.php'); include('includes/db.php');
require_once __DIR__ . '/includes/reports_lib.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');
$role = $_SESSION['role'] ?? 'Admin';
if ($role !== 'Admin') {
    ob_end_clean();
    header("Location: pos.php");
    exit();
}

$tabs = [
    'invoices'  => 'Invoices',
    'sales'     => 'Sales by egg size',
    'customers' => 'Customer-wise sales',
    'aging'     => 'Receivables aging',
    'purchases' => 'Purchases by vendor',
    'stock'     => 'Stock movement',
];
$r = isset($tabs[$_GET['r'] ?? '']) ? $_GET['r'] : 'invoices';
$P = report_period($_GET, 'month');
$F = $P['fromDt'];
$T = $P['toDt'];
$csv = ($_GET['export'] ?? '') === 'csv';
$q = fn($v) => qty_fmt($v);
$n0 = fn($v) => number_format((float)$v, 0);
$fileSuffix = $P['from'] . '_to_' . $P['to'] . '.csv';

// ============================================================================================
if ($r === 'invoices') {
    $today = date('Y-m-d');
    $start = valid_ymd($_GET['start'] ?? '') ? $_GET['start'] : date('Y-m-d', strtotime('-1 month'));
    $end = valid_ymd($_GET['end'] ?? '') ? $_GET['end'] : $today;
    $search = trim($_GET['search'] ?? '');
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = 100;
    $offset = ($page - 1) * $limit;
    $alert = null;

    // Handle delete invoice
    if (isset($_GET['delete']) && $_GET['delete'] !== '') {
        $delId = (int) $_GET['delete'];
        try {
            $conn->begin_transaction();
            $stmt = $conn->prepare("DELETE FROM InvoiceItems WHERE InvoiceID = ?");
            $stmt->bind_param('i', $delId);
            $stmt->execute();
            $stmt = $conn->prepare("DELETE FROM Payments WHERE InvoiceID = ?");
            $stmt->bind_param('i', $delId);
            $stmt->execute();
            $stmt = $conn->prepare("DELETE FROM Invoices WHERE InvoiceID = ?");
            $stmt->bind_param('i', $delId);
            $stmt->execute();
            $conn->commit();
            $alert = ['type' => 'success', 'msg' => 'Invoice deleted.'];
        } catch (Throwable $e) {
            $conn->rollback();
            $alert = ['type' => 'danger', 'msg' => 'Failed to delete invoice.'];
        }
    }

    $where = "WHERE i.InvoiceDate BETWEEN ? AND ?";
    $types = "ss";
    $params = [$start . ' 00:00:00', $end . ' 23:59:59'];
    if ($search !== '') {
        $where .= " AND (i.InvoiceNumber LIKE ? OR c.CustomerName LIKE ?)";
        $types .= "ss";
        $params[] = "%{$search}%";
        $params[] = "%{$search}%";
    }
    $tot = q_one($conn, "SELECT COUNT(*) AS cnt, COALESCE(SUM(i.TotalAmount),0) AS amt FROM Invoices i LEFT JOIN Customers c ON c.CustomerID = i.CustomerID $where", $types, $params);
    $totalRows = (int)$tot['cnt'];
    $sql = "SELECT i.InvoiceID, i.InvoiceNumber, i.InvoiceDate, i.Subtotal, i.Discount, i.TotalAmount, i.PaymentType,
                   COALESCE(c.CustomerName, NULLIF(i.CustomerName, ''), 'Walk-in') AS Customer,
                   (SELECT COALESCE(SUM(ii.Quantity),0) FROM InvoiceItems ii WHERE ii.InvoiceID = i.InvoiceID) AS Crates
            FROM Invoices i LEFT JOIN Customers c ON c.CustomerID = i.CustomerID $where
            ORDER BY i.InvoiceDate DESC, i.InvoiceID DESC";
    if ($csv) {
        $rows = q_all($conn, $sql, $types, $params);
        send_csv("invoices_{$start}_to_{$end}.csv", ['Invoice', 'Date', 'Customer', 'Type', 'Crates', 'Subtotal', 'Discount', 'Total'],
            array_map(fn($x) => [$x['InvoiceNumber'], $x['InvoiceDate'], $x['Customer'], $x['PaymentType'], $x['Crates'], $x['Subtotal'], $x['Discount'], $x['TotalAmount']], $rows));
    }
    $invoices = q_all($conn, $sql . " LIMIT ? OFFSET ?", $types . 'ii', array_merge($params, [$limit, $offset]));
    $totalPages = max(1, (int)ceil($totalRows / $limit));
}

// ============================================================================================
if ($r === 'sales') {
    $byProduct = sales_by_product($conn, $F, $T);
    $costs = product_costs($conn, $T);
    $purch = purchases_by_product($conn, $F, $T);
    $products = array_map(fn($x) => ['id' => (int)$x['ProductID'], 'name' => $x['ProductName']], $byProduct);
    $daily = [];
    foreach (q_all($conn, "SELECT DATE(i.InvoiceDate) d, ii.ProductID, SUM(ii.Quantity) q, SUM(ii.TotalPrice) a
            FROM InvoiceItems ii JOIN Invoices i ON i.InvoiceID = ii.InvoiceID WHERE i.InvoiceDate BETWEEN ? AND ?
            GROUP BY DATE(i.InvoiceDate), ii.ProductID ORDER BY d", 'ss', [$F, $T]) as $x) {
        $daily[$x['d']]['q'][(int)$x['ProductID']] = (float)$x['q'];
        $daily[$x['d']]['a'] = ($daily[$x['d']]['a'] ?? 0) + (float)$x['a'];
    }
    $rows = [];
    foreach ($byProduct as $x) {
        $pid = (int)$x['ProductID'];
        $c = (float)$x['Crates'];
        $cost = $costs[$pid] ?? null;
        $avg = $c > 0 ? (float)$x['Amount'] / $c : null;
        $rows[] = ['Size' => $x['ProductName'], 'Crates' => $c, 'Sales' => (float)$x['Amount'], 'Invoices' => (int)$x['Invoices'],
            'AvgPrice' => $avg, 'Cost' => $cost, 'Margin' => ($avg !== null && $cost !== null) ? $avg - $cost : null,
            'Profit' => ($avg !== null && $cost !== null) ? ($avg - $cost) * $c : null,
            'Purchased' => $purch[$pid]['Crates'] ?? 0.0];
    }
    if ($csv) {
        send_csv('sales_by_size_' . $fileSuffix, ['Size', 'Crates sold', 'Sales (Rs)', 'Invoices', 'Avg price/crate', 'Avg cost/crate', 'Margin/crate', 'Est. profit', 'Crates purchased'],
            array_map(fn($x) => [$x['Size'], $x['Crates'], round($x['Sales'], 2), $x['Invoices'], $x['AvgPrice'] !== null ? round($x['AvgPrice'], 2) : '', $x['Cost'] ?? '', $x['Margin'] !== null ? round($x['Margin'], 2) : '', $x['Profit'] !== null ? round($x['Profit'], 2) : '', $x['Purchased']], $rows));
    }
}

// ============================================================================================
if ($r === 'customers') {
    $rows = q_all($conn, "SELECT i.CustomerID, COALESCE(c.CustomerName, 'Walk-in (cash)') AS Name, c.Phone,
            COUNT(*) AS Invoices,
            SUM((SELECT COALESCE(SUM(ii.Quantity),0) FROM InvoiceItems ii WHERE ii.InvoiceID = i.InvoiceID)) AS Crates,
            SUM(i.TotalAmount) AS Sales,
            SUM(CASE WHEN i.PaymentType = 'Cash' THEN i.TotalAmount ELSE 0 END) AS Cash,
            SUM(CASE WHEN i.PaymentType = 'Credit' THEN i.TotalAmount ELSE 0 END) AS Credit,
            MAX(i.InvoiceDate) AS LastSale
        FROM Invoices i LEFT JOIN Customers c ON c.CustomerID = i.CustomerID
        WHERE i.InvoiceDate BETWEEN ? AND ?
        GROUP BY i.CustomerID, c.CustomerName, c.Phone ORDER BY Crates DESC, Sales DESC", 'ss', [$F, $T]);
    $recv = [];
    foreach (q_all($conn, "SELECT CustomerID, SUM(Amount) t FROM CustomerPayments WHERE PaymentDate BETWEEN ? AND ? GROUP BY CustomerID", 'ss', [$F, $T]) as $x) $recv[(int)$x['CustomerID']] = (float)$x['t'];
    $bal = customer_balances($conn);
    foreach ($rows as &$x) {
        $cid = (int)$x['CustomerID'];
        $x['Received'] = $cid ? ($recv[$cid] ?? 0) : null;
        $x['Balance'] = $cid ? ($bal[$cid] ?? 0) : null;
    }
    unset($x);
    if ($csv) {
        send_csv('customer_sales_' . $fileSuffix, ['Customer', 'Phone', 'Invoices', 'Crates', 'Sales', 'Cash sales', 'Credit sales', 'Received in period', 'Current balance', 'Last sale'],
            array_map(fn($x) => [$x['Name'], $x['Phone'] ?? '', $x['Invoices'], $x['Crates'], $x['Sales'], $x['Cash'], $x['Credit'], $x['Received'] ?? '', $x['Balance'] ?? '', $x['LastSale']], $rows));
    }
}

// ============================================================================================
if ($r === 'aging') {
    $rows = receivables_aging($conn);
    if ($csv) {
        send_csv('receivables_aging_' . date('Y-m-d') . '.csv', ['Customer', 'Phone', 'Balance', '0-7 days', '8-30 days', '30+ days', 'Last payment', 'Days since payment', 'Last sale'],
            array_map(fn($x) => [$x['CustomerName'], $x['Phone'] ?? '', $x['Balance'], $x['b0_7'], $x['b8_30'], $x['b30'], $x['LastPayment'] ?? '', $x['DaysSincePayment'] ?? '', $x['LastSale'] ?? ''], $rows));
    }
}

// ============================================================================================
if ($r === 'purchases') {
    $rows = q_all($conn, "SELECT v.VendorID, v.VendorName,
            COUNT(po.PurchaseOrderID) AS Purchases,
            COALESCE(SUM((SELECT SUM(poi.QuantityReceived) FROM PurchaseOrderItems poi WHERE poi.PurchaseOrderID = po.PurchaseOrderID)),0) AS Crates,
            COALESCE(SUM(po.TotalAmount),0) AS Amount,
            COALESCE(SUM(CASE WHEN po.PaymentType = 'Credit' THEN po.TotalAmount ELSE 0 END),0) AS Credit,
            COALESCE(SUM(CASE WHEN po.PaymentType = 'Cash' THEN po.TotalAmount ELSE 0 END),0) AS Cash
        FROM Vendors v JOIN PurchaseOrders po ON po.VendorID = v.VendorID AND po.Status = 'Received' AND po.OrderDate BETWEEN ? AND ?
        GROUP BY v.VendorID, v.VendorName ORDER BY Amount DESC", 'ss', [$F, $T]);
    $paid = [];
    foreach (q_all($conn, "SELECT VendorID, SUM(AmountPaid) t FROM VendorPayments WHERE PaymentDate BETWEEN ? AND ? GROUP BY VendorID", 'ss', [$F, $T]) as $x) $paid[(int)$x['VendorID']] = (float)$x['t'];
    $vbal = vendor_balances($conn);
    $bySize = q_all($conn, "SELECT p.ProductName, SUM(poi.QuantityReceived) Crates, SUM(poi.TotalPrice) Amount
        FROM PurchaseOrderItems poi JOIN PurchaseOrders po ON po.PurchaseOrderID = poi.PurchaseOrderID JOIN Products p ON p.ProductID = poi.ProductID
        WHERE po.Status = 'Received' AND po.OrderDate BETWEEN ? AND ? GROUP BY p.ProductID, p.ProductName, p.SortOrder ORDER BY p.SortOrder", 'ss', [$F, $T]);
    foreach ($rows as &$x) {
        $x['Paid'] = $paid[(int)$x['VendorID']] ?? 0;
        $x['Payable'] = $vbal[(int)$x['VendorID']] ?? 0;
    }
    unset($x);
    if ($csv) {
        send_csv('purchases_by_vendor_' . $fileSuffix, ['Vendor', 'Purchases', 'Crates', 'Amount', 'Credit', 'Cash', 'Paid in period', 'Current payable'],
            array_map(fn($x) => [$x['VendorName'], $x['Purchases'], $x['Crates'], $x['Amount'], $x['Credit'], $x['Cash'], $x['Paid'], $x['Payable']], $rows));
    }
}

// ============================================================================================
if ($r === 'stock') {
    $openAt = date('Y-m-d H:i:s', strtotime($F) - 1);
    $open = stock_at($conn, $openAt);
    $close = stock_at($conn, $T);
    $flows = stock_flows($conn, $F, $T);
    $rows = [];
    foreach (q_all($conn, "SELECT ProductID, ProductName FROM Products WHERE IsActive = 1 ORDER BY SortOrder, ProductName") as $p) {
        $pid = (int)$p['ProductID'];
        $f = $flows[$pid] ?? ['Purchased' => 0, 'Sold' => 0, 'Adjusted' => 0, 'Breakage' => 0];
        $o = $open[$pid] ?? 0;
        $c = $close[$pid] ?? 0;
        $rows[] = ['Size' => $p['ProductName'], 'Opening' => $o, 'Purchased' => $f['Purchased'], 'Sold' => $f['Sold'],
                   'Adjusted' => $f['Adjusted'], 'Breakage' => $f['Breakage'],
                   'Recount' => round($c - ($o + $f['Purchased'] + $f['Adjusted'] - $f['Sold']), 2), 'Closing' => $c];
    }
    if ($csv) {
        send_csv('stock_movement_' . $fileSuffix, ['Size', 'Opening crates', 'Purchased', 'Sold', 'Adjustments (net)', 'of which breakage/expired', 'Stock count reset', 'Closing crates'],
            array_map(fn($x) => array_values($x), $rows));
    }
}
ob_end_flush();
$csvUrl = 'reports.php?' . ($r === 'invoices'
    ? http_build_query(['r' => 'invoices', 'start' => $start ?? '', 'end' => $end ?? '', 'search' => $search ?? '', 'export' => 'csv'])
    : period_query($P, ['r' => $r, 'export' => 'csv']));
?>
<style>
@media print {
  nav.navbar, .no-print { display: none !important; }
  body.bg-app { background: #fff !important; }
  .card { border: none !important; box-shadow: none !important; }
  .container { max-width: 100% !important; }
  .table { font-size: 11px; }
  a { color: inherit; text-decoration: none; }
}
.print-only { display: none; }
@media print { .print-only { display: block; } }
.report-tabs .nav-link { border-radius: 999px; }
.card:hover { transform: none; }
</style>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2 no-print">
  <h2 class="fw-bold mb-0">Reports</h2>
  <div class="d-flex gap-2">
    <a class="btn btn-outline-success btn-sm" href="<?= htmlspecialchars($csvUrl) ?>">⬇ CSV</a>
    <button class="btn btn-outline-dark btn-sm" onclick="window.print()">🖨 Print</button>
  </div>
</div>

<ul class="nav nav-pills report-tabs mb-3 flex-wrap gap-1 no-print">
  <?php foreach ($tabs as $k => $l): ?>
    <li class="nav-item"><a class="nav-link <?= $r === $k ? 'active' : '' ?>" href="reports.php?<?= $k === 'invoices' ? 'r=invoices' : period_query($P, ['r' => $k]) ?>"><?= $l ?></a></li>
  <?php endforeach; ?>
</ul>

<?php if ($r !== 'invoices' && $r !== 'aging'): ?>
<div class="card shadow-sm mb-3 no-print"><div class="card-body py-2">
  <form class="d-flex flex-wrap align-items-center gap-2" method="get" action="reports.php">
    <input type="hidden" name="r" value="<?= htmlspecialchars($r) ?>">
    <?php foreach (['today' => 'Today', 'yesterday' => 'Yesterday', 'week' => 'This week', 'month' => 'This month', 'last30' => 'Last 30 days'] as $k => $l): ?>
      <a class="btn btn-sm rounded-pill <?= $P['key'] === $k ? 'btn-primary' : 'btn-outline-primary' ?>" href="reports.php?r=<?= $r ?>&range=<?= $k ?>"><?= $l ?></a>
    <?php endforeach; ?>
    <div class="d-flex flex-wrap align-items-center gap-1 ms-md-auto">
      <input type="hidden" name="range" value="custom">
      <input type="date" name="from" class="form-control form-control-sm" style="width:auto" value="<?= htmlspecialchars($P['from']) ?>">
      <span class="small text-muted">to</span>
      <input type="date" name="to" class="form-control form-control-sm" style="width:auto" value="<?= htmlspecialchars($P['to']) ?>">
      <button class="btn btn-sm <?= $P['key'] === 'custom' ? 'btn-primary' : 'btn-outline-secondary' ?>" type="submit">Custom</button>
    </div>
  </form>
</div></div>
<?php endif; ?>

<div class="print-only mb-2"><strong><?= htmlspecialchars($bizName) ?></strong> — <?= htmlspecialchars($tabs[$r]) ?>
  <?= $r === 'aging' ? ' as of ' . date('d M Y') : ($r === 'invoices' ? '' : ' · ' . htmlspecialchars($P['label'])) ?></div>

<?php if ($r === 'invoices'): ?>
<?php if ($alert): ?>
  <div class="alert alert-<?= $alert['type'] ?>"><?= htmlspecialchars($alert['msg']) ?></div>
<?php endif; ?>
<form class="row g-2 mb-3 no-print" method="get">
  <input type="hidden" name="r" value="invoices">
  <div class="col-auto"><input type="date" name="start" class="form-control" value="<?= htmlspecialchars($start) ?>"></div>
  <div class="col-auto"><input type="date" name="end" class="form-control" value="<?= htmlspecialchars($end) ?>"></div>
  <div class="col-auto"><input type="text" name="search" class="form-control" placeholder="Invoice # or customer" value="<?= htmlspecialchars($search) ?>"></div>
  <div class="col-auto"><button class="btn btn-primary">Filter</button></div>
</form>
<div class="card shadow-sm">
  <div class="card-body">
    <div class="d-flex justify-content-between align-items-center mb-2">
      <div class="text-muted small">Page <?= $page ?> of <?= $totalPages ?> · <?= $totalRows ?> invoices · total Rs. <?= $n0($tot['amt']) ?></div>
      <div class="text-muted small no-print">100 per page</div>
    </div>
    <div class="table-responsive">
      <table class="table table-striped mb-0 align-middle">
        <thead class="table-light">
          <tr><th>Invoice #</th><th>Date/Time</th><th>Customer</th><th>Type</th><th class="text-end">Crates</th><th class="text-end">Discount</th><th class="text-end">Total (Rs.)</th><th class="text-end no-print">Actions</th></tr>
        </thead>
        <tbody>
          <?php if (!$invoices): ?>
            <tr><td colspan="8" class="text-center text-muted py-3">No invoices found</td></tr>
          <?php else: foreach ($invoices as $inv): ?>
            <tr>
              <td><?= htmlspecialchars($inv['InvoiceNumber']) ?></td>
              <td class="text-nowrap"><?= date('d-M-Y g:i A', strtotime($inv['InvoiceDate'])) ?></td>
              <td><?= htmlspecialchars($inv['Customer']) ?></td>
              <td><span class="badge <?= $inv['PaymentType'] === 'Credit' ? 'bg-warning text-dark' : 'bg-success' ?>"><?= htmlspecialchars($inv['PaymentType']) ?></span></td>
              <td class="text-end"><?= $q($inv['Crates']) ?></td>
              <td class="text-end"><?= (float)$inv['Discount'] ? number_format($inv['Discount'], 2) : '' ?></td>
              <td class="text-end fw-semibold"><?= number_format($inv['TotalAmount'], 2) ?></td>
              <td class="text-end no-print">
                <div class="d-flex justify-content-end gap-1">
                  <button class="btn btn-sm btn-outline-secondary" onclick="window.open('receipt.php?id=<?= (int)$inv['InvoiceID'] ?>', '_blank'); event.stopPropagation();">View</button>
                  <a class="btn btn-sm btn-outline-danger" href="reports.php?r=invoices&delete=<?= (int)$inv['InvoiceID'] ?>&start=<?= urlencode($start) ?>&end=<?= urlencode($end) ?>&search=<?= urlencode($search) ?>&page=<?= $page ?>" onclick="return confirm('Delete this invoice?')">Delete</a>
                </div>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <?php if ($totalPages > 1): ?>
      <nav class="mt-3 no-print"><ul class="pagination pagination-sm mb-0">
        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
          <li class="page-item <?= $p === $page ? 'active' : '' ?>"><a class="page-link" href="?r=invoices&start=<?= urlencode($start) ?>&end=<?= urlencode($end) ?>&search=<?= urlencode($search) ?>&page=<?= $p ?>"><?= $p ?></a></li>
        <?php endfor; ?>
      </ul></nav>
    <?php endif; ?>
  </div>
</div>

<?php elseif ($r === 'sales'):
    $tc = array_sum(array_column($rows, 'Crates')); $ts = array_sum(array_column($rows, 'Sales'));
    $tp = array_sum(array_map(fn($x) => $x['Profit'] ?? 0, $rows)); $tpur = array_sum(array_column($rows, 'Purchased')); ?>
<div class="card shadow-sm mb-3"><div class="card-body">
  <h5 class="fw-bold mb-2">Sales by egg size <span class="text-muted small fw-normal">· <?= htmlspecialchars($P['label']) ?></span></h5>
  <div class="table-responsive"><table class="table table-sm table-striped align-middle mb-0">
    <thead class="table-light"><tr><th>Size</th><th class="text-end">Crates sold</th><th class="text-end">Sales (Rs.)</th><th class="text-end">Invoices</th><th class="text-end">Avg price / crate</th><th class="text-end">Avg cost / crate</th><th class="text-end">Margin / crate</th><th class="text-end">Est. profit</th><th class="text-end">Crates purchased</th></tr></thead>
    <tbody>
      <?php foreach ($rows as $x): ?>
        <tr>
          <td><?= htmlspecialchars($x['Size']) ?></td>
          <td class="text-end"><?= $q($x['Crates']) ?></td>
          <td class="text-end"><?= $n0($x['Sales']) ?></td>
          <td class="text-end"><?= $x['Invoices'] ?></td>
          <td class="text-end"><?= $x['AvgPrice'] !== null ? $n0($x['AvgPrice']) : '—' ?></td>
          <td class="text-end"><?= $x['Cost'] !== null ? $n0($x['Cost']) : '<span class="text-muted">no cost</span>' ?></td>
          <td class="text-end <?= $x['Margin'] !== null && $x['Margin'] < 0 ? 'text-danger' : '' ?>"><?= $x['Margin'] !== null ? $n0($x['Margin']) : '—' ?></td>
          <td class="text-end"><?= $x['Profit'] !== null ? $n0($x['Profit']) : '—' ?></td>
          <td class="text-end"><?= $q($x['Purchased']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="9" class="text-center text-muted py-3">No data yet</td></tr><?php endif; ?>
    </tbody>
    <tfoot class="table-light fw-bold"><tr><td>Total</td><td class="text-end"><?= $q($tc) ?></td><td class="text-end"><?= $n0($ts) ?></td><td></td><td class="text-end"><?= $tc > 0 ? $n0($ts / $tc) : '—' ?></td><td></td><td></td><td class="text-end"><?= $n0($tp) ?></td><td class="text-end"><?= $q($tpur) ?></td></tr></tfoot>
  </table></div>
  <div class="small text-muted mt-1">Sales here are item totals before invoice discounts. Cost = weighted average purchase cost per crate; profit is an estimate.</div>
</div></div>
<div class="card shadow-sm"><div class="card-body">
  <h5 class="fw-bold mb-2">Day by day (crates)</h5>
  <div class="table-responsive"><table class="table table-sm table-striped align-middle mb-0">
    <thead class="table-light"><tr><th>Date</th><?php foreach ($products as $p): ?><th class="text-end"><?= htmlspecialchars($p['name']) ?></th><?php endforeach; ?><th class="text-end">Total crates</th><th class="text-end">Sales (Rs.)</th></tr></thead>
    <tbody>
      <?php foreach ($daily as $d => $x): ?>
        <tr>
          <td class="text-nowrap"><?= date('D d M', strtotime($d)) ?></td>
          <?php foreach ($products as $p): ?><td class="text-end"><?= isset($x['q'][$p['id']]) ? $q($x['q'][$p['id']]) : '' ?></td><?php endforeach; ?>
          <td class="text-end fw-semibold"><?= $q(array_sum($x['q'])) ?></td>
          <td class="text-end"><?= $n0($x['a']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$daily): ?><tr><td colspan="<?= count($products) + 3 ?>" class="text-center text-muted py-3">No sales in this period</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div></div>

<?php elseif ($r === 'customers'): ?>
<div class="card shadow-sm"><div class="card-body">
  <h5 class="fw-bold mb-2">Customer-wise sales <span class="text-muted small fw-normal">· <?= htmlspecialchars($P['label']) ?></span></h5>
  <div class="table-responsive"><table class="table table-sm table-striped align-middle mb-0">
    <thead class="table-light"><tr><th>Customer</th><th class="text-end">Invoices</th><th class="text-end">Crates</th><th class="text-end">Sales</th><th class="text-end">Cash</th><th class="text-end">Credit</th><th class="text-end">Received</th><th class="text-end">Balance now</th><th>Last sale</th></tr></thead>
    <tbody>
      <?php foreach ($rows as $x): ?>
        <tr>
          <td><?php if ($x['CustomerID']): ?><a href="customer_ledger.php?customer_id=<?= (int)$x['CustomerID'] ?>"><?= htmlspecialchars($x['Name']) ?></a><?php else: ?><span class="text-muted"><?= htmlspecialchars($x['Name']) ?></span><?php endif; ?></td>
          <td class="text-end"><?= (int)$x['Invoices'] ?></td>
          <td class="text-end"><?= $q($x['Crates']) ?></td>
          <td class="text-end"><?= $n0($x['Sales']) ?></td>
          <td class="text-end"><?= $n0($x['Cash']) ?></td>
          <td class="text-end"><?= $n0($x['Credit']) ?></td>
          <td class="text-end"><?= $x['Received'] !== null ? $n0($x['Received']) : '' ?></td>
          <td class="text-end fw-semibold <?= ($x['Balance'] ?? 0) > 0 ? 'text-danger' : '' ?>"><?= $x['Balance'] !== null ? $n0($x['Balance']) : '' ?></td>
          <td class="text-nowrap small"><?= date('d M Y', strtotime($x['LastSale'])) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="9" class="text-center text-muted py-3">No sales in this period</td></tr><?php endif; ?>
    </tbody>
    <?php if ($rows): ?>
    <tfoot class="table-light fw-bold"><tr><td>Total</td><td class="text-end"><?= array_sum(array_column($rows, 'Invoices')) ?></td><td class="text-end"><?= $q(array_sum(array_column($rows, 'Crates'))) ?></td><td class="text-end"><?= $n0(array_sum(array_column($rows, 'Sales'))) ?></td><td class="text-end"><?= $n0(array_sum(array_column($rows, 'Cash'))) ?></td><td class="text-end"><?= $n0(array_sum(array_column($rows, 'Credit'))) ?></td><td class="text-end"><?= $n0(array_sum(array_map(fn($x) => $x['Received'] ?? 0, $rows))) ?></td><td></td><td></td></tr></tfoot>
    <?php endif; ?>
  </table></div>
</div></div>

<?php elseif ($r === 'aging'):
    $tb = array_sum(array_column($rows, 'Balance')); ?>
<div class="row g-3 mb-3">
  <?php foreach (['b0_7' => ['0–7 days', 'success'], 'b8_30' => ['8–30 days', 'warning'], 'b30' => ['30+ days', 'danger']] as $k => [$l, $c]): $v = array_sum(array_column($rows, $k)); ?>
    <div class="col-md-4"><div class="card shadow-sm"><div class="card-body py-2">
      <div class="small text-muted"><?= $l ?></div>
      <div class="fs-4 fw-bold text-<?= $c ?>">Rs. <?= $n0($v) ?></div>
      <div class="small text-muted"><?= pct($v, $tb) ?>% of receivables</div>
    </div></div></div>
  <?php endforeach; ?>
</div>
<div class="card shadow-sm"><div class="card-body">
  <h5 class="fw-bold mb-2">Receivables aging <span class="text-muted small fw-normal">· as of <?= date('d M Y') ?> · total Rs. <?= $n0($tb) ?></span></h5>
  <div class="table-responsive"><table class="table table-sm table-striped align-middle mb-0">
    <thead class="table-light"><tr><th>Customer</th><th>Phone</th><th class="text-end">Balance</th><th class="text-end">0–7 days</th><th class="text-end">8–30 days</th><th class="text-end">30+ days</th><th class="text-end">Last payment</th><th>Last sale</th></tr></thead>
    <tbody>
      <?php foreach ($rows as $x): ?>
        <tr>
          <td><a href="customer_ledger.php?customer_id=<?= (int)$x['CustomerID'] ?>"><?= htmlspecialchars($x['CustomerName']) ?></a></td>
          <td class="small"><?= htmlspecialchars($x['Phone'] ?? '') ?></td>
          <td class="text-end fw-semibold"><?= $n0($x['Balance']) ?></td>
          <td class="text-end"><?= $x['b0_7'] > 0 ? $n0($x['b0_7']) : '' ?></td>
          <td class="text-end text-warning-emphasis"><?= $x['b8_30'] > 0 ? $n0($x['b8_30']) : '' ?></td>
          <td class="text-end text-danger"><?= $x['b30'] > 0 ? $n0($x['b30']) : '' ?></td>
          <td class="text-end small <?= ($x['DaysSincePayment'] === null || $x['DaysSincePayment'] > 14) ? 'text-danger' : '' ?>"><?= $x['LastPayment'] ? date('d M Y', strtotime($x['LastPayment'])) . ' (' . $x['DaysSincePayment'] . 'd)' : 'never' ?></td>
          <td class="small"><?= $x['LastSale'] ? date('d M Y', strtotime($x['LastSale'])) : '' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="8" class="text-center text-muted py-3">No customer owes anything</td></tr><?php endif; ?>
    </tbody>
  </table></div>
  <div class="small text-muted mt-1">Payments are counted against the oldest sales first, so the amount still owed is split by the age of the most recent credit sales. Opening balances count as 30+ days.</div>
</div></div>

<?php elseif ($r === 'purchases'): ?>
<div class="card shadow-sm mb-3"><div class="card-body">
  <h5 class="fw-bold mb-2">Purchases by vendor <span class="text-muted small fw-normal">· <?= htmlspecialchars($P['label']) ?></span></h5>
  <div class="table-responsive"><table class="table table-sm table-striped align-middle mb-0">
    <thead class="table-light"><tr><th>Vendor</th><th class="text-end">Purchases</th><th class="text-end">Crates</th><th class="text-end">Amount</th><th class="text-end">On credit</th><th class="text-end">Cash</th><th class="text-end">Paid in period</th><th class="text-end">Payable now</th></tr></thead>
    <tbody>
      <?php foreach ($rows as $x): ?>
        <tr>
          <td><a href="vendor_ledger.php?vendor_id=<?= (int)$x['VendorID'] ?>"><?= htmlspecialchars($x['VendorName']) ?></a></td>
          <td class="text-end"><?= (int)$x['Purchases'] ?></td>
          <td class="text-end"><?= $q($x['Crates']) ?></td>
          <td class="text-end"><?= $n0($x['Amount']) ?></td>
          <td class="text-end"><?= $n0($x['Credit']) ?></td>
          <td class="text-end"><?= $n0($x['Cash']) ?></td>
          <td class="text-end"><?= $n0($x['Paid']) ?></td>
          <td class="text-end fw-semibold"><?= $n0($x['Payable']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="8" class="text-center text-muted py-3">No purchases in this period</td></tr><?php endif; ?>
    </tbody>
    <?php if ($rows): ?>
    <tfoot class="table-light fw-bold"><tr><td>Total</td><td class="text-end"><?= array_sum(array_column($rows, 'Purchases')) ?></td><td class="text-end"><?= $q(array_sum(array_column($rows, 'Crates'))) ?></td><td class="text-end"><?= $n0(array_sum(array_column($rows, 'Amount'))) ?></td><td class="text-end"><?= $n0(array_sum(array_column($rows, 'Credit'))) ?></td><td class="text-end"><?= $n0(array_sum(array_column($rows, 'Cash'))) ?></td><td class="text-end"><?= $n0(array_sum(array_column($rows, 'Paid'))) ?></td><td></td></tr></tfoot>
    <?php endif; ?>
  </table></div>
</div></div>
<div class="card shadow-sm"><div class="card-body">
  <h5 class="fw-bold mb-2">Purchased by egg size</h5>
  <table class="table table-sm table-striped mb-0">
    <thead class="table-light"><tr><th>Size</th><th class="text-end">Crates</th><th class="text-end">Amount</th><th class="text-end">Avg cost / crate</th></tr></thead>
    <tbody>
      <?php foreach ($bySize as $x): ?>
        <tr><td><?= htmlspecialchars($x['ProductName']) ?></td><td class="text-end"><?= $q($x['Crates']) ?></td><td class="text-end"><?= $n0($x['Amount']) ?></td><td class="text-end"><?= (float)$x['Crates'] > 0 ? $n0($x['Amount'] / $x['Crates']) : '—' ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$bySize): ?><tr><td colspan="4" class="text-center text-muted py-3">No purchase lines in this period</td></tr><?php endif; ?>
    </tbody>
  </table>
</div></div>

<?php elseif ($r === 'stock'): ?>
<div class="card shadow-sm"><div class="card-body">
  <h5 class="fw-bold mb-2">Stock movement (crates) <span class="text-muted small fw-normal">· <?= htmlspecialchars($P['label']) ?></span></h5>
  <div class="table-responsive"><table class="table table-sm table-striped align-middle mb-0">
    <thead class="table-light"><tr><th>Size</th><th class="text-end">Opening</th><th class="text-end">+ Purchased</th><th class="text-end">− Sold</th><th class="text-end">± Adjustments</th><th class="text-end">of which breakage</th><th class="text-end">Count reset</th><th class="text-end">Closing</th></tr></thead>
    <tbody>
      <?php foreach ($rows as $x): ?>
        <tr>
          <td><?= htmlspecialchars($x['Size']) ?></td>
          <td class="text-end"><?= $q($x['Opening']) ?></td>
          <td class="text-end text-success"><?= $q($x['Purchased']) ?></td>
          <td class="text-end text-primary"><?= $q($x['Sold']) ?></td>
          <td class="text-end"><?= $q($x['Adjusted']) ?></td>
          <td class="text-end text-danger"><?= $x['Breakage'] > 0 ? $q($x['Breakage']) : '' ?></td>
          <td class="text-end"><?= abs($x['Recount']) > 0.004 ? (($x['Recount'] > 0 ? '+' : '') . $q($x['Recount'])) : '' ?></td>
          <td class="text-end fw-bold <?= $x['Closing'] < 0 ? 'text-danger' : '' ?>"><?= $q($x['Closing']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="8" class="text-center text-muted py-3">No products</td></tr><?php endif; ?>
    </tbody>
  </table></div>
  <div class="small text-muted mt-1">"Count reset" is the difference made by a physical stock count (Inventory → Set count) inside this period.</div>
</div></div>
<?php endif; ?>

</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body></html>
