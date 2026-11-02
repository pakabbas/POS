<?php
date_default_timezone_set('Asia/Karachi');
include('includes/header.php'); include('includes/db.php'); ?>

<?php
$role = $_SESSION['role'] ?? 'Admin';
if ($role !== 'Admin') {
    header("Location: pos.php");
    exit();
}
// Handle date filters
$start = $_GET['start'] ?? date('Y-m-01');
$end = $_GET['end'] ?? date('Y-m-d');

// Total Sales (range)
$salesStmt = $conn->prepare("SELECT SUM(TotalAmount) AS total_sales, COUNT(*) AS invoice_count FROM Invoices WHERE DATE(InvoiceDate) BETWEEN ? AND ?");
$salesStmt->bind_param('ss', $start, $end);
$salesStmt->execute();
$salesRow = $salesStmt->get_result()->fetch_assoc();
$totalSales = $salesRow['total_sales'] ?? 0;
$invoiceCount = $salesRow['invoice_count'] ?? 0;

// Items sold (range)
$itemStmt = $conn->prepare("
    SELECT SUM(ii.Quantity) AS qty
    FROM InvoiceItems ii
    JOIN Invoices inv ON inv.InvoiceID = ii.InvoiceID
    WHERE DATE(inv.InvoiceDate) BETWEEN ? AND ?
");
$itemStmt->bind_param('ss', $start, $end);
$itemStmt->execute();
$totalItems = $itemStmt->get_result()->fetch_assoc()['qty'] ?? 0;
$itemStmt->close();

// Today and Month-to-date Sales
$today = date('Y-m-d');
$monthStart = date('Y-m-01');
$ts = $conn->prepare("SELECT SUM(TotalAmount) AS s FROM Invoices WHERE DATE(InvoiceDate) = ?");
$ts->bind_param('s', $today);
$ts->execute();
$todaySales = $ts->get_result()->fetch_assoc()['s'] ?? 0;

$mtdStmt = $conn->prepare("SELECT SUM(TotalAmount) AS s FROM Invoices WHERE DATE(InvoiceDate) BETWEEN ? AND ?");
$mtdStmt->bind_param('ss', $monthStart, $today);
$mtdStmt->execute();
$mtdSales = $mtdStmt->get_result()->fetch_assoc()['s'] ?? 0;

// Average order value for range
$avgOrder = $invoiceCount > 0 ? $totalSales / $invoiceCount : 0;

// Total Expenses (range)
$exp = $conn->query("SELECT SUM(Amount) AS total_expenses FROM Expenses WHERE ExpenseDate BETWEEN '$start' AND '$end'");
$totalExpenses = $exp->fetch_assoc()['total_expenses'] ?? 0;

$profit = $totalSales - $totalExpenses;

// Recent Invoices
$recentInvoices = $conn->query("SELECT * FROM Invoices ORDER BY InvoiceDate DESC LIMIT 5");

// Top Products (by qty)
$topProductsRes = $conn->query("
    SELECT p.ProductName, SUM(ii.Quantity) AS qty
    FROM InvoiceItems ii
    JOIN Products p ON p.ProductID = ii.ProductID
    GROUP BY p.ProductName
    ORDER BY qty DESC
    LIMIT 5
");
$topProducts = $topProductsRes ? $topProductsRes->fetch_all(MYSQLI_ASSOC) : [];

// Category sales (amount)
$categorySales = $conn->query("
    SELECT c.CategoryName, SUM(ii.TotalPrice) AS total
    FROM InvoiceItems ii
    JOIN Products p ON p.ProductID = ii.ProductID
    LEFT JOIN Categories c ON c.CategoryID = p.CategoryID
    GROUP BY c.CategoryName
    ORDER BY total DESC
    LIMIT 5
");

// Sales by hour (for selected range)
$hourStmt = $conn->prepare("
    SELECT HOUR(InvoiceDate) AS hr, SUM(TotalAmount) AS total, COUNT(*) AS cnt
    FROM Invoices
    WHERE DATE(InvoiceDate) BETWEEN ? AND ?
    GROUP BY hr
    ORDER BY hr
");
$hourStmt->bind_param('ss', $start, $end);
$hourStmt->execute();
$hourSales = $hourStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Counts
$prodRes = $conn->query("SELECT COUNT(*) AS c FROM Products");
$totalProducts = $prodRes ? ($prodRes->fetch_assoc()['c'] ?? 0) : 0;
$catRes = $conn->query("SELECT COUNT(*) AS c FROM Categories");
$totalCategories = $catRes ? ($catRes->fetch_assoc()['c'] ?? 0) : 0;

// Sales trend (last 30 days, daily)
$trendStmt = $conn->query("
    SELECT DATE(InvoiceDate) AS d, SUM(TotalAmount) AS total
    FROM Invoices
    WHERE InvoiceDate >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
    GROUP BY d
    ORDER BY d
");
$monthlyTrend = $trendStmt ? $trendStmt->fetch_all(MYSQLI_ASSOC) : [];
?>

<h2 class="mb-3 fw-bold">Dashboard</h2>
<div class="mb-4 d-flex flex-wrap quick-actions">
    <a class="quick-btn" href="pos.php"><span class="icon">🧾</span>Open POS</a>
    <a class="quick-btn" href="categories.php"><span class="icon">🏷️</span>Manage Categories</a>
    <a class="quick-btn" href="products.php"><span class="icon">📦</span>Manage Products</a>
    <a class="quick-btn" href="users.php"><span class="icon">👥</span>Manage Users</a>
</div>

<form class="row mb-4">
    <div class="col-md-3">
        <input type="date" name="start" class="form-control" value="<?= $start ?>">
    </div>
    <div class="col-md-3">
        <input type="date" name="end" class="form-control" value="<?= $end ?>">
    </div>
    <div class="col-md-2">
        <button class="btn btn-dark w-100">Filter</button>
    </div>
</form>

<div class="row g-3">
    <div class="col-md-3">
        <div class="card text-center shadow metric-card">
            <div class="card-body">
                <div class="metric-icon text-success">💰</div>
                <div class="metric-title text-muted mb-1">Total Sales (Range)</div>
                <div class="metric-value text-success fw-bold">PKR <?= number_format($totalSales,0) ?></div>
                <div class="metric-sub text-muted">Invoices: <?= $invoiceCount ?> | Avg: PKR <?= number_format($avgOrder,0) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-center shadow metric-card">
            <div class="card-body">
                <div class="metric-icon text-info">📦</div>
                <div class="metric-title text-muted mb-1">Items Sold (Range)</div>
                <div class="metric-value text-info fw-bold"><?= number_format($totalItems ?: 0, 0) ?></div>
                <div class="metric-sub text-muted">Avg Items/Invoice: <?= $invoiceCount ? number_format($totalItems / $invoiceCount, 0) : '0' ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-center shadow metric-card">
            <div class="card-body">
                <div class="metric-icon text-primary">📅</div>
                <div class="metric-title text-muted mb-1">Today Sales</div>
                <div class="metric-value text-primary fw-bold">PKR <?= number_format($todaySales,0) ?></div>
                <div class="metric-sub text-muted"><?= date('d-M-Y') ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-center shadow metric-card">
            <div class="card-body">
                <div class="metric-icon text-info">📆</div>
                <div class="metric-title text-muted mb-1">MTD Sales</div>
                <div class="metric-value text-info fw-bold">PKR <?= number_format($mtdSales,0) ?></div>
                <div class="metric-sub text-muted"><?= date('M Y') ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-center shadow metric-card">
            <div class="card-body">
                <div class="metric-icon text-danger">💸</div>
                <div class="metric-title text-muted mb-1">Expenses (Range)</div>
                <div class="metric-value text-danger fw-bold">PKR <?= number_format($totalExpenses,0) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-center shadow metric-card">
            <div class="card-body">
                <div class="metric-icon text-primary">📈</div>
                <div class="metric-title text-muted mb-1">Profit (Range)</div>
                <div class="metric-value text-primary fw-bold">PKR <?= number_format($profit,0) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-center shadow metric-card">
            <div class="card-body">
                <div class="metric-icon text-secondary">🛒</div>
                <div class="metric-title text-muted mb-1">Total Products</div>
                <div class="metric-value text-secondary fw-bold"><?= number_format($totalProducts,0) ?></div>
                <div class="metric-sub text-muted">Active & inactive</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-center shadow metric-card">
            <div class="card-body">
                <div class="metric-icon text-secondary">🏷️</div>
                <div class="metric-title text-muted mb-1">Total Categories</div>
                <div class="metric-value text-secondary fw-bold"><?= number_format($totalCategories,0) ?></div>
                <div class="metric-sub text-muted">Active & inactive</div>
            </div>
        </div>
    </div>
</div>

<hr class="my-4">

<div class="row g-3">
    <div class="col-md-6">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <h5 class="fw-bold mb-3">Recent Invoices</h5>
                <div class="table-responsive">
                    <table class="table table-striped mb-0">
                        <thead class="table-light"><tr><th>#</th><th>Date</th><th>Total</th></tr></thead>
                        <tbody>
                        <?php while($r = $recentInvoices->fetch_assoc()): ?>
                            <tr>
                                <td>
                                    <a href="receipt.php?id=<?= $r['InvoiceID'] ?>" target="_blank" rel="noopener">
                                        <?= $r['InvoiceNumber'] ?>
                                    </a>
                                </td>
                                <td><?= date('Y-m-d', strtotime($r['InvoiceDate'])) ?></td>
                                <td>PKR <?= $r['TotalAmount'] ?></td>
                            </tr>
                        <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <h5 class="fw-bold mb-3">Top Selling Products</h5>
                <canvas id="productChart" height="220"></canvas>
            </div>
        </div>
    </div>
</div>

<div class="row mt-4 g-3">
    <div class="col-md-6">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <h5 class="fw-bold mb-3">Sales by Category</h5>
                <div class="table-responsive">
                    <table class="table table-sm table-striped mb-0">
                        <thead class="table-light"><tr><th>Category</th><th class="text-end">Total</th></tr></thead>
                        <tbody>
                        <?php foreach ($categorySales as $c): ?>
                            <tr>
                                <td><?= htmlspecialchars($c['CategoryName'] ?? 'Uncategorized') ?></td>
                                <td class="text-end">PKR <?= number_format($c['total'],2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <h5 class="fw-bold mb-3">Sales by Hour</h5>
                <canvas id="hourChart" height="220"></canvas>
            </div>
        </div>
    </div>
</div>
<div class="card shadow-sm mb-3">
  <div class="card-body">
    <h5 class="fw-bold mb-3">Sales Trend (Last 30 Days)</h5>
    <canvas id="trendChart" height="200"></canvas>
  </div>
</div>
<br><br>
<script>
const ctx = document.getElementById('productChart').getContext('2d');
const chart = new Chart(ctx, {
    type: 'bar',
    data: {
        labels: [<?php foreach ($topProducts as $p){ echo "'".$p['ProductName']."',"; } ?>],
        datasets: [{
            label: 'Quantity Sold',
            data: [<?php
                foreach ($topProducts as $p){ echo $p['qty'].","; }
            ?>],
            backgroundColor: 'rgba(54,162,235,0.7)'
        }]
    }
});

// Hourly sales chart
const hourCtx = document.getElementById('hourChart').getContext('2d');
const hourLabels = [<?php foreach ($hourSales as $h) { $label = str_pad($h['hr'], 2, '0', STR_PAD_LEFT) . ':00'; echo "'$label',"; } ?>];
const hourData = [<?php foreach ($hourSales as $h) { echo ($h['total'] ?? 0) . ','; } ?>];
new Chart(hourCtx, {
    type: 'bar',
    data: {
        labels: hourLabels,
        datasets: [{
            label: 'Sales',
            data: hourData,
            backgroundColor: 'rgba(102, 126, 234, 0.7)'
        }]
    },
    options: {
        scales: {
            y: { beginAtZero: true }
        }
    }
});

// 30-day trend chart
const trendCtx = document.getElementById('trendChart').getContext('2d');
const trendLabels = [<?php foreach ($monthlyTrend as $m) { echo "'".$m['d']."',"; } ?>];
const trendData = [<?php foreach ($monthlyTrend as $m) { echo ($m['total'] ?? 0) . ','; } ?>];
new Chart(trendCtx, {
    type: 'line',
    data: {
        labels: trendLabels,
        datasets: [{
            label: 'Sales',
            data: trendData,
            borderColor: 'rgba(102, 126, 234, 0.9)',
            backgroundColor: 'rgba(102, 126, 234, 0.2)',
            tension: 0.2,
            fill: true,
            pointRadius: 4
        }]
    },
    options: {
        scales: {
            y: { beginAtZero: true }
        }
    }
});
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php $conn->close(); ?>
</div></body></html>
