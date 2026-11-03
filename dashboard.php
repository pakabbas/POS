<?php
ob_start(); // allows the non-admin redirect below even though header.php prints the page header
date_default_timezone_set('Asia/Karachi');
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/reports_lib.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

if ($role !== 'Admin') {
    ob_end_clean();
    header('Location: pos.php');
    exit();
}
ob_end_flush();

$P = report_period($_GET, 'month');
$F = $P['fromDt'];
$T = $P['toDt'];
$todayYmd = date('Y-m-d');

// ---- Sales in period -------------------------------------------------------
$s = q_one($conn, "SELECT COUNT(*) AS Invoices, COALESCE(SUM(TotalAmount),0) AS Total, COALESCE(SUM(Discount),0) AS Discount,
        COALESCE(SUM(CASE WHEN PaymentType = 'Cash' THEN TotalAmount END),0) AS CashSales,
        COALESCE(SUM(CASE WHEN PaymentType = 'Credit' THEN TotalAmount END),0) AS CreditSales,
        COUNT(CASE WHEN PaymentType = 'Credit' THEN 1 END) AS CreditInvoices
    FROM Invoices WHERE InvoiceDate BETWEEN ? AND ?", 'ss', [$F, $T]);
$byProduct = sales_by_product($conn, $F, $T);
$cratesSold = array_sum(array_map(fn($r) => (float)$r['Crates'], $byProduct));
$receipts = (float)(q_one($conn, "SELECT COALESCE(SUM(Amount),0) t FROM CustomerPayments WHERE PaymentDate BETWEEN ? AND ?", 'ss', [$F, $T])['t'] ?? 0);
$receiptsCash = (float)(q_one($conn, "SELECT COALESCE(SUM(Amount),0) t FROM CustomerPayments WHERE PaymentDate BETWEEN ? AND ? AND (Method = 'Cash' OR Method IS NULL)", 'ss', [$F, $T])['t'] ?? 0);
$cashIn = (float)$s['CashSales'] + $receipts;

// ---- Money out in period ----------------------------------------------------
$vendorPaid = (float)(q_one($conn, "SELECT COALESCE(SUM(AmountPaid),0) t FROM VendorPayments WHERE PaymentDate BETWEEN ? AND ?", 'ss', [$F, $T])['t'] ?? 0);
$cashPurch = (float)(q_one($conn, "SELECT COALESCE(SUM(TotalAmount),0) t FROM PurchaseOrders WHERE Status = 'Received' AND PaymentType = 'Cash' AND OrderDate BETWEEN ? AND ?", 'ss', [$F, $T])['t'] ?? 0);
$expenses = (float)(q_one($conn, "SELECT COALESCE(SUM(Amount),0) t FROM Expenses WHERE ExpenseDate BETWEEN ? AND ?", 'ss', [$P['from'], $P['to']])['t'] ?? 0);
$cashOut = $vendorPaid + $cashPurch + $expenses;
$netCash = $cashIn - $cashOut;

// ---- Gross profit estimate ---------------------------------------------------
$costs = product_costs($conn, $T);
$gp = 0.0; $gpRevenue = 0.0; $unknownCrates = 0.0; $knownCrates = 0.0;
foreach ($byProduct as &$r) {
    $pid = (int)$r['ProductID'];
    $r['Cost'] = $costs[$pid] ?? null;
    $r['AvgPrice'] = (float)$r['Crates'] > 0 ? (float)$r['Amount'] / (float)$r['Crates'] : null;
    $r['Margin'] = ($r['AvgPrice'] !== null && $r['Cost'] !== null) ? $r['AvgPrice'] - $r['Cost'] : null;
    if ((float)$r['Crates'] > 0) {
        if ($r['Cost'] !== null) {
            $gp += (float)$r['Amount'] - (float)$r['Crates'] * $r['Cost'];
            $gpRevenue += (float)$r['Amount'];
            $knownCrates += (float)$r['Crates'];
        } else {
            $unknownCrates += (float)$r['Crates'];
        }
    }
}
unset($r);
$gp -= (float)$s['Discount'] * ($cratesSold > 0 ? $knownCrates / $cratesSold : 0); // discounts on costed sales
$gpMargin = pct($gp, $gpRevenue);

// ---- Balances (as of now) ----------------------------------------------------
$custBal = customer_balances($conn);
$receivable = array_sum(array_filter($custBal, fn($v) => $v > 0));
$debtors = count(array_filter($custBal, fn($v) => $v > 0));
$vendBal = vendor_balances($conn);
$payable = array_sum(array_filter($vendBal, fn($v) => $v > 0));

// ---- Stock -------------------------------------------------------------------
$stock = stock_levels($conn, true);
$d14 = date('Y-m-d', strtotime('-13 days'));
$sold14 = [];
foreach (q_all($conn, "SELECT ii.ProductID, SUM(ii.Quantity) q FROM InvoiceItems ii JOIN Invoices i ON i.InvoiceID = ii.InvoiceID
        WHERE i.InvoiceDate BETWEEN ? AND ? GROUP BY ii.ProductID", 'ss', [$d14 . ' 00:00:00', $todayYmd . ' 23:59:59']) as $r) {
    $sold14[(int)$r['ProductID']] = (float)$r['q'];
}
$stockCrates = 0.0; $stockValue = 0.0; $lowCount = 0;
foreach ($stock as $pid => &$r) {
    $r['AvgDaily'] = ($sold14[$pid] ?? 0) / 14;
    $r['DaysLeft'] = $r['AvgDaily'] > 0 ? max(0, $r['Stock']) / $r['AvgDaily'] : null;
    $r['Low'] = $r['Stock'] <= $r['ReorderLevel'] || ($r['DaysLeft'] !== null && $r['DaysLeft'] < 2);
    if ($r['Low']) $lowCount++;
    $stockCrates += max(0, $r['Stock']);
    $stockValue += max(0, $r['Stock']) * ($costs[$pid] ?? 0);
}
unset($r);

// ---- Charts --------------------------------------------------------------------
$trendFrom = date('Y-m-d', strtotime('-29 days'));
$trend = [];
for ($i = 0; $i < 30; $i++) {
    $d = date('Y-m-d', strtotime($trendFrom . " +$i days"));
    $trend[$d] = ['crates' => 0, 'amount' => 0];
}
foreach (q_all($conn, "SELECT DATE(i.InvoiceDate) d, SUM(i.TotalAmount) amt FROM Invoices i WHERE i.InvoiceDate BETWEEN ? AND ? GROUP BY DATE(i.InvoiceDate)", 'ss', [$trendFrom . ' 00:00:00', $todayYmd . ' 23:59:59']) as $r) {
    if (isset($trend[$r['d']])) $trend[$r['d']]['amount'] = round((float)$r['amt'], 2);
}
foreach (q_all($conn, "SELECT DATE(i.InvoiceDate) d, SUM(ii.Quantity) q FROM InvoiceItems ii JOIN Invoices i ON i.InvoiceID = ii.InvoiceID WHERE i.InvoiceDate BETWEEN ? AND ? GROUP BY DATE(i.InvoiceDate)", 'ss', [$trendFrom . ' 00:00:00', $todayYmd . ' 23:59:59']) as $r) {
    if (isset($trend[$r['d']])) $trend[$r['d']]['crates'] = (float)$r['q'];
}
$purchByProduct = purchases_by_product($conn, $F, $T);
$chart = [
    'trendLabels' => array_map(fn($d) => date('d M', strtotime($d)), array_keys($trend)),
    'trendCrates' => array_column(array_values($trend), 'crates'),
    'trendAmount' => array_column(array_values($trend), 'amount'),
    'sizeLabels' => array_column($byProduct, 'ProductName'),
    'sizeCrates' => array_map(fn($r) => (float)$r['Crates'], $byProduct),
    'sizePurchased' => array_map(fn($r) => (float)($purchByProduct[(int)$r['ProductID']]['Crates'] ?? 0), $byProduct),
    'cashCredit' => [(float)$s['CashSales'], (float)$s['CreditSales']],
];

// ---- Tables -----------------------------------------------------------------------
$topCustomers = q_all($conn, "SELECT i.CustomerID, COALESCE(c.CustomerName, 'Walk-in (cash)') AS Name,
        COUNT(*) AS Invoices, SUM(i.TotalAmount) AS Amount,
        SUM((SELECT COALESCE(SUM(ii.Quantity),0) FROM InvoiceItems ii WHERE ii.InvoiceID = i.InvoiceID)) AS Crates
    FROM Invoices i LEFT JOIN Customers c ON c.CustomerID = i.CustomerID
    WHERE i.InvoiceDate BETWEEN ? AND ?
    GROUP BY i.CustomerID, c.CustomerName ORDER BY Crates DESC, Amount DESC LIMIT 8", 'ss', [$F, $T]);
$aging = array_slice(receivables_aging($conn), 0, 8);
$vendorNames = [];
foreach (q_all($conn, "SELECT VendorID, VendorName FROM Vendors") as $r) $vendorNames[(int)$r['VendorID']] = $r['VendorName'];
$owe = array_filter($vendBal, fn($v) => $v > 0.005);
arsort($owe);
$owe = array_slice($owe, 0, 6, true);
$recent = q_all($conn, "SELECT i.InvoiceID, i.InvoiceNumber, i.InvoiceDate, i.TotalAmount, i.PaymentType,
        COALESCE(c.CustomerName, NULLIF(i.CustomerName, ''), 'Walk-in') AS Name,
        (SELECT COALESCE(SUM(ii.Quantity),0) FROM InvoiceItems ii WHERE ii.InvoiceID = i.InvoiceID) AS Crates
    FROM Invoices i LEFT JOIN Customers c ON c.CustomerID = i.CustomerID ORDER BY i.InvoiceDate DESC, i.InvoiceID DESC LIMIT 8");
$breakage = q_all($conn, "SELECT sm.MovementDate, sm.MovementType, sm.Quantity, sm.Notes, p.ProductName
    FROM StockMovements sm JOIN Products p ON p.ProductID = sm.ProductID
    WHERE sm.MovementType NOT IN ('Purchase','Sale') AND sm.MovementDate BETWEEN ? AND ?
    ORDER BY sm.MovementDate DESC LIMIT 8", 'ss', [$F, $T]);
$breakageCrates = (float)(q_one($conn, "SELECT COALESCE(-SUM(Quantity),0) t FROM StockMovements WHERE MovementType IN ('Waste','Expired') AND MovementDate BETWEEN ? AND ?", 'ss', [$F, $T])['t'] ?? 0);

$sizeColors = ['#b45309', '#2563eb', '#0891b2', '#16a34a', '#a855f7', '#f59e0b', '#64748b'];
$n0 = fn($v) => number_format((float)$v, 0);
$q = fn($v) => qty_fmt($v);
$pctCash = pct($s['CashSales'], $s['Total']);
?>
<style>
.dash-head { gap: .75rem; }
.period-pills .btn { border-radius: 999px; }
.kpi { border-radius: 16px; padding: 14px 16px; height: 100%; }
.kpi .lbl { font-size: .78rem; color: #64748b; text-transform: uppercase; letter-spacing: .04em; font-weight: 600; }
.kpi .val { font-size: 1.45rem; font-weight: 700; color: #0f172a; line-height: 1.2; margin-top: 4px; }
.kpi .sub { font-size: .8rem; color: #64748b; margin-top: 2px; }
.kpi .ico { width: 38px; height: 38px; border-radius: 12px; display: inline-flex; align-items: center; justify-content: center; font-size: 1.1rem; }
.ico-blue { background: #dbeafe; } .ico-cyan { background: #cffafe; } .ico-green { background: #dcfce7; } .ico-amber { background: #fef3c7; } .ico-red { background: #fee2e2; } .ico-violet { background: #ede9fe; }
.section-title { font-weight: 700; font-size: 1rem; margin: 0; }
.egg-card { border-radius: 16px; padding: 12px 14px; height: 100%; }
.egg-card .size { font-weight: 700; }
.egg-card .stock { font-size: 1.6rem; font-weight: 700; line-height: 1.1; }
.egg-card.low { border: 2px solid #ef4444 !important; }
.dot { display: inline-block; width: 10px; height: 10px; border-radius: 50%; margin-right: 6px; }
.chart-box { position: relative; height: 260px; }
.chart-box.sm { height: 220px; }
.empty-chart { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; color: #94a3b8; font-size: .9rem; }
.table-dash td, .table-dash th { font-size: .86rem; }
.card:hover { transform: none; }
@media (max-width: 576px) { .kpi .val { font-size: 1.2rem; } }
</style>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 dash-head">
  <div class="d-flex align-items-center gap-2">
    <img src="assets/logo.svg" alt="" width="40" height="40">
    <div>
      <h2 class="fw-bold mb-0">Dashboard</h2>
      <div class="text-muted small"><?= htmlspecialchars($P['label']) ?> · updated <?= date('d M Y, g:i A') ?> (PKT)</div>
    </div>
  </div>
  <div class="d-flex flex-wrap gap-2">
    <a class="btn btn-primary btn-sm" href="pos.php">🧾 New Sale</a>
    <a class="btn btn-outline-primary btn-sm" href="purchases.php?new=1">🚚 Stock In</a>
    <a class="btn btn-outline-primary btn-sm" href="customers.php">💵 Receive Payment</a>
    <a class="btn btn-outline-primary btn-sm" href="vendor_payouts.php">🏦 Pay Vendor</a>
    <a class="btn btn-outline-secondary btn-sm" href="reports.php?r=sales">📊 Reports</a>
  </div>
</div>

<div class="card shadow-sm mb-3">
  <div class="card-body py-2">
    <form class="d-flex flex-wrap align-items-center gap-2" method="get" action="dashboard.php">
      <div class="period-pills d-flex flex-wrap gap-1">
        <?php foreach (['today' => 'Today', 'yesterday' => 'Yesterday', 'week' => 'This week', 'month' => 'This month', 'last30' => 'Last 30 days'] as $k => $l): ?>
          <a class="btn btn-sm <?= $P['key'] === $k ? 'btn-primary' : 'btn-outline-primary' ?>" href="dashboard.php?range=<?= $k ?>"><?= $l ?></a>
        <?php endforeach; ?>
      </div>
      <div class="d-flex flex-wrap align-items-center gap-1 ms-md-auto">
        <input type="hidden" name="range" value="custom">
        <input type="date" name="from" class="form-control form-control-sm" style="width:auto" value="<?= htmlspecialchars($P['from']) ?>" max="<?= $todayYmd ?>">
        <span class="small text-muted">to</span>
        <input type="date" name="to" class="form-control form-control-sm" style="width:auto" value="<?= htmlspecialchars($P['to']) ?>" max="<?= $todayYmd ?>">
        <button class="btn btn-sm <?= $P['key'] === 'custom' ? 'btn-primary' : 'btn-outline-secondary' ?>" type="submit">Custom</button>
      </div>
    </form>
  </div>
</div>

<!-- KPIs for the period -->
<div class="row g-3 mb-3">
  <div class="col-6 col-lg-3">
    <div class="card kpi">
      <div class="d-flex justify-content-between"><div class="lbl">Sales</div><span class="ico ico-blue">🥚</span></div>
      <div class="val"><?= rs0($s['Total']) ?></div>
      <div class="sub"><strong><?= $q($cratesSold) ?></strong> crates · <?= (int)$s['Invoices'] ?> invoices</div>
      <div class="sub">Avg <?= $cratesSold > 0 ? rs0($s['Total'] / $cratesSold) : '—' ?> / crate</div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card kpi">
      <div class="d-flex justify-content-between"><div class="lbl">Cash vs Credit</div><span class="ico ico-cyan">⚖️</span></div>
      <div class="val" style="font-size:1.1rem">Cash <?= rs0($s['CashSales']) ?></div>
      <div class="sub">Credit <?= rs0($s['CreditSales']) ?> (<?= (int)$s['CreditInvoices'] ?> invoices)</div>
      <div class="progress mt-2" style="height:8px" title="Cash <?= $pctCash ?>%">
        <div class="progress-bar bg-success" style="width: <?= $s['Total'] > 0 ? $pctCash : 0 ?>%"></div>
        <div class="progress-bar bg-warning" style="width: <?= $s['Total'] > 0 ? 100 - $pctCash : 0 ?>%"></div>
      </div>
      <div class="sub"><?= $s['Total'] > 0 ? $pctCash . '% cash' : 'No sales yet' ?></div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card kpi">
      <div class="d-flex justify-content-between"><div class="lbl">Money received</div><span class="ico ico-green">💵</span></div>
      <div class="val text-success"><?= rs0($cashIn) ?></div>
      <div class="sub">Cash sales <?= rs0($s['CashSales']) ?> + customer receipts <?= rs0($receipts) ?></div>
      <div class="sub">Cash-in-hand part: <?= rs0((float)$s['CashSales'] + $receiptsCash) ?></div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card kpi">
      <div class="d-flex justify-content-between"><div class="lbl">Gross profit <span class="badge bg-light text-secondary border">estimate</span></div><span class="ico ico-violet">📈</span></div>
      <?php if ($knownCrates > 0): ?>
        <div class="val <?= $gp < 0 ? 'text-danger' : '' ?>"><?= rs0($gp) ?></div>
        <div class="sub"><?= $gpMargin ?>% margin · <?= rs0($knownCrates > 0 ? $gp / $knownCrates : 0) ?> / crate</div>
      <?php else: ?>
        <div class="val text-muted">—</div>
      <?php endif; ?>
      <div class="sub"><?= $unknownCrates > 0 ? $q($unknownCrates) . ' crates sold without a purchase cost' : ($cratesSold > 0 ? 'Using average purchase cost per size' : 'No sales in this period') ?></div>
    </div>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-6 col-lg-3">
    <a class="text-decoration-none" href="reports.php?r=aging"><div class="card kpi">
      <div class="d-flex justify-content-between"><div class="lbl">Customers owe us</div><span class="ico ico-amber">📒</span></div>
      <div class="val text-danger"><?= rs0($receivable) ?></div>
      <div class="sub"><?= $debtors ?> customer<?= $debtors === 1 ? '' : 's' ?> with a balance · today</div>
    </div></a>
  </div>
  <div class="col-6 col-lg-3">
    <a class="text-decoration-none" href="vendors.php"><div class="card kpi">
      <div class="d-flex justify-content-between"><div class="lbl">We owe vendors</div><span class="ico ico-red">🚚</span></div>
      <div class="val"><?= rs0($payable) ?></div>
      <div class="sub"><?= count($owe) ? count(array_filter($vendBal, fn($v) => $v > 0.005)) . ' vendors with a balance · today' : 'Nothing payable' ?></div>
    </div></a>
  </div>
  <div class="col-6 col-lg-3">
    <a class="text-decoration-none" href="cash_summary.php?from=<?= $P['from'] ?>&to=<?= $P['to'] ?>"><div class="card kpi">
      <div class="d-flex justify-content-between"><div class="lbl">Net cash position</div><span class="ico ico-cyan">🏦</span></div>
      <div class="val <?= $netCash < 0 ? 'text-danger' : 'text-success' ?>"><?= rs0($netCash) ?></div>
      <div class="sub">In <?= rs0($cashIn) ?> − out <?= rs0($cashOut) ?></div>
      <div class="sub">(vendor payouts <?= rs0($vendorPaid) ?>, cash purchases <?= rs0($cashPurch) ?>, expenses <?= rs0($expenses) ?>)</div>
    </div></a>
  </div>
  <div class="col-6 col-lg-3">
    <a class="text-decoration-none" href="inventory.php"><div class="card kpi">
      <div class="d-flex justify-content-between"><div class="lbl">Stock in hand</div><span class="ico ico-blue">📦</span></div>
      <div class="val"><?= $q($stockCrates) ?> crates</div>
      <div class="sub">≈ <?= rs0($stockValue) ?> at cost<?= $lowCount ? ' · <span class="text-danger fw-semibold">' . $lowCount . ' low</span>' : '' ?></div>
      <div class="sub">Breakage this period: <?= $q($breakageCrates) ?> crates</div>
    </div></a>
  </div>
</div>

<!-- Stock per egg size -->
<div class="d-flex justify-content-between align-items-center mb-2">
  <h5 class="section-title">Stock by egg size</h5>
  <a class="small" href="inventory.php">Inventory →</a>
</div>
<div class="row g-3 mb-4">
  <?php $ci = 0; foreach ($stock as $pid => $r): $color = $sizeColors[$ci++ % count($sizeColors)]; ?>
    <div class="col-6 col-md-4 col-xl">
      <div class="card egg-card <?= $r['Low'] ? 'low' : '' ?>">
        <div class="d-flex justify-content-between align-items-center">
          <span class="size"><span class="dot" style="background:<?= $color ?>"></span><?= htmlspecialchars($r['ProductName']) ?></span>
          <?php if ($r['Low']): ?><span class="badge bg-danger">Low</span><?php endif; ?>
        </div>
        <div class="stock mt-1 <?= $r['Stock'] <= 0 ? 'text-danger' : '' ?>"><?= $q($r['Stock']) ?> <small class="fs-6 fw-normal text-muted">crates</small></div>
        <div class="small text-muted">
          <?php if ($r['Stock'] <= 0): ?>
            <strong class="text-danger"><?= $r['Stock'] < 0 ? 'Below zero — recount' : 'Out of stock' ?></strong><?= $r['AvgDaily'] > 0 ? ' · sells ' . number_format($r['AvgDaily'], 1) . '/day' : '' ?>
          <?php elseif ($r['DaysLeft'] !== null): ?>
            ≈ <strong class="<?= $r['DaysLeft'] < 2 ? 'text-danger' : '' ?>"><?= number_format($r['DaysLeft'], 1) ?> days</strong> left · sells <?= number_format($r['AvgDaily'], 1) ?>/day
          <?php else: ?>
            No sales in last 14 days
          <?php endif; ?>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
  <?php if (!$stock): ?><div class="col-12 text-muted">No products yet.</div><?php endif; ?>
</div>

<!-- Charts -->
<div class="row g-3 mb-4">
  <div class="col-lg-8">
    <div class="card shadow-sm h-100"><div class="card-body">
      <h5 class="section-title mb-2">Daily sales — last 30 days</h5>
      <div class="chart-box"><canvas id="trendChart"></canvas></div>
    </div></div>
  </div>
  <div class="col-lg-4">
    <div class="card shadow-sm h-100"><div class="card-body">
      <h5 class="section-title mb-2">Crates sold by size <span class="text-muted small fw-normal">· <?= htmlspecialchars($P['label']) ?></span></h5>
      <div class="chart-box"><canvas id="sizeChart"></canvas></div>
    </div></div>
  </div>
  <div class="col-lg-8">
    <div class="card shadow-sm h-100"><div class="card-body">
      <h5 class="section-title mb-2">Purchased vs sold (crates) <span class="text-muted small fw-normal">· <?= htmlspecialchars($P['label']) ?></span></h5>
      <div class="chart-box sm"><canvas id="pvsChart"></canvas></div>
    </div></div>
  </div>
  <div class="col-lg-4">
    <div class="card shadow-sm h-100"><div class="card-body">
      <h5 class="section-title mb-2">Cash vs credit sales</h5>
      <div class="chart-box sm"><canvas id="ccChart"></canvas></div>
    </div></div>
  </div>
</div>

<!-- Margin per size -->
<div class="card shadow-sm mb-4"><div class="card-body">
  <div class="d-flex justify-content-between align-items-center mb-2">
    <h5 class="section-title">Price & margin per crate <span class="text-muted small fw-normal">· <?= htmlspecialchars($P['label']) ?></span></h5>
    <a class="small" href="reports.php?r=sales&<?= period_query($P) ?>">Sales by size report →</a>
  </div>
  <div class="table-responsive">
    <table class="table table-sm table-dash align-middle mb-0">
      <thead class="table-light"><tr><th>Size</th><th class="text-end">Crates sold</th><th class="text-end">Sales</th><th class="text-end">Avg sell / crate</th><th class="text-end">Avg cost / crate</th><th class="text-end">Margin / crate</th><th class="text-end">Est. profit</th></tr></thead>
      <tbody>
        <?php foreach ($byProduct as $r): $c = (float)$r['Crates']; ?>
          <tr>
            <td><?= htmlspecialchars($r['ProductName']) ?></td>
            <td class="text-end"><?= $q($c) ?></td>
            <td class="text-end"><?= $n0($r['Amount']) ?></td>
            <td class="text-end"><?= $r['AvgPrice'] !== null ? $n0($r['AvgPrice']) : '—' ?></td>
            <td class="text-end"><?= $r['Cost'] !== null ? $n0($r['Cost']) : '<span class="text-muted">no cost</span>' ?></td>
            <td class="text-end fw-semibold <?= $r['Margin'] !== null && $r['Margin'] < 0 ? 'text-danger' : 'text-success' ?>"><?= $r['Margin'] !== null ? $n0($r['Margin']) : '—' ?></td>
            <td class="text-end"><?= $r['Margin'] !== null ? $n0($r['Margin'] * $c) : '—' ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$byProduct): ?><tr><td colspan="7" class="text-center text-muted py-3">No data yet</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <div class="small text-muted mt-1">Cost = weighted average purchase cost per crate (from Purchases), so profit is an estimate. Discounts given are deducted in the Gross profit card.</div>
</div></div>

<div class="row g-3 mb-4">
  <div class="col-lg-6">
    <div class="card shadow-sm h-100"><div class="card-body">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h5 class="section-title">Top customers <span class="text-muted small fw-normal">· <?= htmlspecialchars($P['label']) ?></span></h5>
        <a class="small" href="reports.php?r=customers&<?= period_query($P) ?>">All →</a>
      </div>
      <table class="table table-sm table-dash mb-0">
        <thead class="table-light"><tr><th>Customer</th><th class="text-end">Crates</th><th class="text-end">Sales</th><th class="text-end">Invoices</th></tr></thead>
        <tbody>
          <?php foreach ($topCustomers as $r): ?>
            <tr>
              <td><?php if ($r['CustomerID']): ?><a href="customer_ledger.php?customer_id=<?= (int)$r['CustomerID'] ?>"><?= htmlspecialchars($r['Name']) ?></a><?php else: ?><span class="text-muted"><?= htmlspecialchars($r['Name']) ?></span><?php endif; ?></td>
              <td class="text-end"><?= $q($r['Crates']) ?></td>
              <td class="text-end"><?= $n0($r['Amount']) ?></td>
              <td class="text-end"><?= (int)$r['Invoices'] ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$topCustomers): ?><tr><td colspan="4" class="text-center text-muted py-3">No sales in this period yet</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div></div>
  </div>
  <div class="col-lg-6">
    <div class="card shadow-sm h-100"><div class="card-body">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h5 class="section-title">Highest outstanding balances</h5>
        <a class="small" href="reports.php?r=aging">Aging report →</a>
      </div>
      <table class="table table-sm table-dash mb-0">
        <thead class="table-light"><tr><th>Customer</th><th class="text-end">Balance</th><th class="text-end">Last paid</th><th class="text-end">30+ days</th></tr></thead>
        <tbody>
          <?php foreach ($aging as $r): ?>
            <tr>
              <td><a href="customer_ledger.php?customer_id=<?= (int)$r['CustomerID'] ?>"><?= htmlspecialchars($r['CustomerName']) ?></a></td>
              <td class="text-end fw-semibold text-danger"><?= $n0($r['Balance']) ?></td>
              <td class="text-end <?= ($r['DaysSincePayment'] === null || $r['DaysSincePayment'] > 14) ? 'text-danger' : '' ?>"><?= $r['DaysSincePayment'] === null ? 'never' : ($r['DaysSincePayment'] === 0 ? 'today' : ($r['DaysSincePayment'] === 1 ? 'yesterday' : $r['DaysSincePayment'] . ' days ago')) ?></td>
              <td class="text-end"><?= $r['b30'] > 0 ? $n0($r['b30']) : '—' ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$aging): ?><tr><td colspan="4" class="text-center text-muted py-3">No customer owes anything 🎉</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div></div>
  </div>
  <div class="col-lg-6">
    <div class="card shadow-sm h-100"><div class="card-body">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h5 class="section-title">Vendors we owe most</h5>
        <a class="small" href="vendors.php">Vendors →</a>
      </div>
      <table class="table table-sm table-dash mb-0">
        <thead class="table-light"><tr><th>Vendor</th><th class="text-end">Payable</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($owe as $vid => $amt): ?>
            <tr>
              <td><?= htmlspecialchars($vendorNames[$vid] ?? ('#' . $vid)) ?></td>
              <td class="text-end fw-semibold"><?= $n0($amt) ?></td>
              <td class="text-end"><a class="btn btn-sm btn-outline-secondary py-0" href="vendor_ledger.php?vendor_id=<?= (int)$vid ?>">Ledger</a></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$owe): ?><tr><td colspan="3" class="text-center text-muted py-3">Nothing payable</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div></div>
  </div>
  <div class="col-lg-6">
    <div class="card shadow-sm h-100"><div class="card-body">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h5 class="section-title">Recent sales</h5>
        <a class="small" href="reports.php">Invoices →</a>
      </div>
      <table class="table table-sm table-dash mb-0">
        <thead class="table-light"><tr><th>Invoice</th><th>Customer</th><th class="text-end">Crates</th><th class="text-end">Total</th></tr></thead>
        <tbody>
          <?php foreach ($recent as $r): ?>
            <tr>
              <td class="text-nowrap"><a href="receipt.php?id=<?= (int)$r['InvoiceID'] ?>" target="_blank"><?= htmlspecialchars($r['InvoiceNumber']) ?></a><div class="small text-muted"><?= date('d M, g:i A', strtotime($r['InvoiceDate'])) ?></div></td>
              <td><?= htmlspecialchars($r['Name']) ?> <span class="badge <?= $r['PaymentType'] === 'Credit' ? 'bg-warning text-dark' : 'bg-success' ?>"><?= htmlspecialchars($r['PaymentType']) ?></span></td>
              <td class="text-end"><?= $q($r['Crates']) ?></td>
              <td class="text-end"><?= $n0($r['TotalAmount']) ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$recent): ?><tr><td colspan="4" class="text-center text-muted py-3">No sales yet</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div></div>
  </div>
  <div class="col-12">
    <div class="card shadow-sm"><div class="card-body">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h5 class="section-title">Breakage & stock adjustments <span class="text-muted small fw-normal">· <?= htmlspecialchars($P['label']) ?></span></h5>
        <a class="small" href="reports.php?r=stock&<?= period_query($P) ?>">Stock movement →</a>
      </div>
      <table class="table table-sm table-dash mb-0">
        <thead class="table-light"><tr><th>Date</th><th>Size</th><th>Type</th><th class="text-end">Crates</th><th>Reason</th></tr></thead>
        <tbody>
          <?php foreach ($breakage as $r): ?>
            <tr>
              <td class="text-nowrap"><?= date('d M, g:i A', strtotime($r['MovementDate'])) ?></td>
              <td><?= htmlspecialchars($r['ProductName']) ?></td>
              <td><?= htmlspecialchars($r['MovementType'] === 'Waste' ? 'Damage / breakage' : $r['MovementType']) ?></td>
              <td class="text-end <?= $r['Quantity'] < 0 ? 'text-danger' : 'text-success' ?>"><?= ($r['Quantity'] > 0 ? '+' : '') . $q($r['Quantity']) ?></td>
              <td class="small"><?= htmlspecialchars($r['Notes'] ?? '') ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$breakage): ?><tr><td colspan="5" class="text-center text-muted py-3">No breakage or adjustments in this period</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div></div>
  </div>
</div>

</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
  const D = <?= json_encode($chart, JSON_UNESCAPED_UNICODE) ?>;
  const colors = <?= json_encode($sizeColors) ?>;
  const rs = v => 'Rs. ' + Math.round(v).toLocaleString('en-PK');
  const hasData = arr => arr.some(v => Number(v) > 0);
  function empty(id, msg) {
    const c = document.getElementById(id);
    const d = document.createElement('div'); d.className = 'empty-chart'; d.textContent = msg || 'No data yet';
    c.parentNode.appendChild(d); c.style.display = 'none';
  }
  if (typeof Chart === 'undefined') return;
  Chart.defaults.font.family = 'Poppins, sans-serif';
  Chart.defaults.maintainAspectRatio = false;

  if (hasData(D.trendCrates) || hasData(D.trendAmount)) {
    new Chart(document.getElementById('trendChart'), {
      data: { labels: D.trendLabels, datasets: [
        { type: 'bar', label: 'Crates', data: D.trendCrates, backgroundColor: 'rgba(34,211,238,0.55)', borderRadius: 4, yAxisID: 'y' },
        { type: 'line', label: 'Sales (Rs.)', data: D.trendAmount, borderColor: '#2563eb', backgroundColor: 'rgba(37,99,235,0.1)', tension: 0.3, pointRadius: 2, fill: true, yAxisID: 'y1' }
      ]},
      options: { interaction: { mode: 'index', intersect: false },
        plugins: { tooltip: { callbacks: { label: c => c.dataset.yAxisID === 'y1' ? ' ' + rs(c.parsed.y) : ' ' + c.parsed.y + ' crates' } } },
        scales: { y: { beginAtZero: true, title: { display: true, text: 'Crates' } },
                  y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, ticks: { callback: v => (v >= 1000 ? (v / 1000) + 'k' : v) } },
                  x: { ticks: { maxTicksLimit: 10 } } } }
    });
  } else empty('trendChart', 'No sales in the last 30 days yet');

  if (hasData(D.sizeCrates)) {
    new Chart(document.getElementById('sizeChart'), {
      type: 'doughnut',
      data: { labels: D.sizeLabels, datasets: [{ data: D.sizeCrates, backgroundColor: colors }] },
      options: { plugins: { legend: { position: 'bottom' }, tooltip: { callbacks: { label: c => ' ' + c.label + ': ' + c.parsed + ' crates' } } }, cutout: '60%' }
    });
  } else empty('sizeChart', 'No crates sold in this period');

  if (hasData(D.sizeCrates) || hasData(D.sizePurchased)) {
    new Chart(document.getElementById('pvsChart'), {
      type: 'bar',
      data: { labels: D.sizeLabels, datasets: [
        { label: 'Purchased', data: D.sizePurchased, backgroundColor: 'rgba(37,99,235,0.75)', borderRadius: 4 },
        { label: 'Sold', data: D.sizeCrates, backgroundColor: 'rgba(34,211,238,0.75)', borderRadius: 4 }
      ]},
      options: { scales: { y: { beginAtZero: true, title: { display: true, text: 'Crates' } } } }
    });
  } else empty('pvsChart', 'No purchases or sales in this period');

  if (hasData(D.cashCredit)) {
    new Chart(document.getElementById('ccChart'), {
      type: 'doughnut',
      data: { labels: ['Cash', 'Credit'], datasets: [{ data: D.cashCredit, backgroundColor: ['#16a34a', '#f59e0b'] }] },
      options: { plugins: { legend: { position: 'bottom' }, tooltip: { callbacks: { label: c => ' ' + c.label + ': ' + rs(c.parsed) } } }, cutout: '60%' }
    });
  } else empty('ccChart', 'No sales in this period');
})();
</script>
</body>
</html>
