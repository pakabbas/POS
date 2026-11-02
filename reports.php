<?php
include('includes/header.php'); include('includes/db.php');
date_default_timezone_set('Asia/Karachi');
$role = $_SESSION['role'] ?? 'Admin';
if ($role !== 'Admin') {
    header("Location: pos.php");
    exit();
}

$today = date('Y-m-d');
$defaultStart = date('Y-m-d', strtotime('-1 month', strtotime($today)));
$start = $_GET['start'] ?? $defaultStart;
$end = $_GET['end'] ?? $today;
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

// Count total
$countSql = "SELECT COUNT(*) AS cnt FROM Invoices WHERE DATE(InvoiceDate) BETWEEN ? AND ?";
$countParams = [$start, $end];
$countTypes = "ss";
if ($search !== '') {
    $countSql .= " AND InvoiceNumber LIKE ?";
    $countTypes .= "s";
    $countParams[] = "%{$search}%";
}
$countStmt = $conn->prepare($countSql);
$countStmt->bind_param($countTypes, ...$countParams);
$countStmt->execute();
$totalRows = (int)($countStmt->get_result()->fetch_assoc()['cnt'] ?? 0);
$countStmt->close();

$sql = "SELECT InvoiceID, InvoiceNumber, InvoiceDate, Subtotal, Discount, TotalAmount
        FROM Invoices
        WHERE DATE(InvoiceDate) BETWEEN ? AND ?";
$types = "ss";
$params = [$start, $end];
if ($search !== '') {
    $sql .= " AND InvoiceNumber LIKE ?";
    $types .= "s";
    $params[] = "%{$search}%";
}
$sql .= " ORDER BY InvoiceDate DESC, InvoiceID DESC LIMIT ? OFFSET ?";
$types .= "ii";
$params[] = $limit;
$params[] = $offset;

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$invoices = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$totalPages = max(1, (int)ceil($totalRows / $limit));
?>

<h2 class="fw-bold mb-4">Invoices</h2>

<?php if ($alert): ?>
  <div class="alert alert-<?= $alert['type'] ?>"><?= htmlspecialchars($alert['msg']) ?></div>
<?php endif; ?>

<form class="row g-2 mb-3" method="get">
    <div class="col-auto">
        <input type="date" name="start" class="form-control" value="<?= htmlspecialchars($start) ?>">
    </div>
    <div class="col-auto">
        <input type="date" name="end" class="form-control" value="<?= htmlspecialchars($end) ?>">
    </div>
    <div class="col-auto">
        <input type="text" name="search" class="form-control" placeholder="Search invoice #" value="<?= htmlspecialchars($search) ?>">
    </div>
    <div class="col-auto">
        <button class="btn btn-primary">Filter</button>
    </div>
</form>

<div class="card shadow-sm">
  <div class="card-body">
    <div class="d-flex justify-content-between align-items-center mb-2">
      <div class="text-muted small">Showing page <?= $page ?> of <?= $totalPages ?> (<?= $totalRows ?> invoices)</div>
      <div class="text-muted small">Limit: 100 per page</div>
    </div>
    <div class="table-responsive">
      <table class="table table-striped mb-0 align-middle">
        <thead class="table-light">
          <tr>
            <th>Invoice #</th>
            <th>Date/Time</th>
            <th class="text-end">Subtotal</th>
            <th class="text-end">Discount</th>
            <th class="text-end">Total</th>
            <th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$invoices): ?>
            <tr><td colspan="5" class="text-center text-muted py-3">No invoices found</td></tr>
          <?php else: ?>
            <?php foreach ($invoices as $inv): ?>
              <tr>
                <td><?= htmlspecialchars($inv['InvoiceNumber']) ?></td>
                <td><?= date('Y-m-d H:i', strtotime($inv['InvoiceDate'])) ?></td>
                <td class="text-end">PKR <?= number_format($inv['Subtotal'],2) ?></td>
                <td class="text-end">PKR <?= number_format($inv['Discount'],2) ?></td>
                <td class="text-end fw-semibold">PKR <?= number_format($inv['TotalAmount'],2) ?></td>
                <td class="text-end">
                  <div class="d-flex justify-content-end gap-1">
                    <button class="btn btn-sm btn-outline-secondary" onclick="window.open('receipt.php?id=<?= $inv['InvoiceID'] ?>', '_blank'); event.stopPropagation();">View</button>
                    <a class="btn btn-sm btn-outline-danger" href="reports.php?delete=<?= $inv['InvoiceID'] ?>&start=<?= urlencode($start) ?>&end=<?= urlencode($end) ?>&search=<?= urlencode($search) ?>&page=<?= $page ?>" onclick="return confirm('Delete this invoice?')">Delete</a>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <?php if ($totalPages > 1): ?>
      <nav class="mt-3">
        <ul class="pagination pagination-sm mb-0">
          <?php for ($p = 1; $p <= $totalPages; $p++): ?>
            <li class="page-item <?= $p === $page ? 'active' : '' ?>">
              <a class="page-link" href="?start=<?= urlencode($start) ?>&end=<?= urlencode($end) ?>&search=<?= urlencode($search) ?>&page=<?= $p ?>"><?= $p ?></a>
            </li>
          <?php endfor; ?>
        </ul>
      </nav>
    <?php endif; ?>
  </div>
</div>

</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body></html>
