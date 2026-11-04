<?php
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/db.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');
register_shutdown_function(function() use ($conn) {
    if ($conn instanceof mysqli) {
        $conn->close();
    }
});
$role = $_SESSION['role'] ?? 'Admin';
if ($role !== 'Admin') {
    header("Location: pos.php");
    exit();
}

$selectedDate = $_GET['date'] ?? date('Y-m-d');

// Fetch aggregated sales for the day
$stmt = $conn->prepare("
    SELECT p.ProductName,
           c.CategoryName,
           SUM(ii.Quantity) AS qty,
           SUM(ii.TotalPrice) AS total
    FROM InvoiceItems ii
    JOIN Invoices inv ON inv.InvoiceID = ii.InvoiceID
    JOIN Products p ON p.ProductID = ii.ProductID
    LEFT JOIN Categories c ON c.CategoryID = p.CategoryID
    WHERE DATE(inv.InvoiceDate) = ?
    GROUP BY p.ProductName, c.CategoryName
    ORDER BY total DESC
");
$stmt->bind_param('s', $selectedDate);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$totals = $conn->prepare("SELECT SUM(TotalAmount) AS grand_total FROM Invoices WHERE DATE(InvoiceDate) = ?");
$totals->bind_param('s', $selectedDate);
$totals->execute();
$grandTotal = $totals->get_result()->fetch_assoc()['grand_total'] ?? 0;
?>

<style>
@media print {
  @page { size: 58mm auto; margin: 0; }
  body {
    width: 58mm;
    margin: 0;
    padding: 6px;
    font-family: 'SFMono-Regular', 'Consolas', 'Roboto Mono', monospace;
    background: #fff;
  }
  nav, .navbar, .no-print { display: none !important; }
  .card, .card-body {
    border: 0;
    border-radius: 0;
    box-shadow: none !important;
    padding: 0;
    margin: 0;
    background: #fff;
  }
  .print-card { box-shadow: none !important; }
  .print-title { text-align: center; font-weight: 700; margin-bottom: 4px; }
  .print-sub { text-align: center; font-size: 11px; margin-bottom: 6px; }
  .table { width: 100%; font-size: 11px; }
  .table thead { display: none; }
  .table td { padding: 2px 0; border: 0 !important; }
  .summary-line { display: flex; justify-content: space-between; font-size: 11px; margin-bottom: 2px; }
}
</style>

<h2 class="mb-3 fw-bold no-print">Day Sales Summary</h2>
<form class="row g-3 mb-3 no-print" method="get">
  <div class="col-auto">
    <input type="date" name="date" class="form-control" value="<?= htmlspecialchars($selectedDate) ?>">
  </div>
  <div class="col-auto">
    <button class="btn btn-primary">Load</button>
  </div>
  <div class="col-auto">
    <button type="button" class="btn btn-outline-secondary" onclick="window.print()">Print</button>
  </div>
</form>

<div class="card shadow-sm print-card">
  <div class="card-body">
    <div class="print-title">Day Sales Summary</div>
    <div class="print-sub">Date: <?= htmlspecialchars($selectedDate) ?></div>
    <div class="summary-line"><span>Total</span><span>PKR <?= number_format($grandTotal, 0) ?></span></div>
    <div class="table-responsive">
      <table class="table table-sm mb-0">
        <thead class="table-light no-print">
          <tr>
            <th>Product</th>
            <th>Category</th>
            <th class="text-end">Peti</th>
            <th class="text-end">Total</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="4" class="text-center text-muted py-3">No sales found for this date.</td></tr>
          <?php else: ?>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td><?= htmlspecialchars($r['ProductName']) ?></td>
                <td><?= htmlspecialchars($r['CategoryName'] ?? 'Uncategorized') ?></td>
                <td class="text-end"><?= rtrim(rtrim(number_format((float)$r['qty'], 2), '0'), '.') ?></td>
                <td class="text-end">PKR <?= number_format($r['total'], 0) ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

