<?php
date_default_timezone_set('Asia/Karachi');
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

$alert = null;

// Defaults
$today = date('Y-m-d');
$defaultStart = date('Y-m-d', strtotime('-1 month', strtotime($today)));
$start = $_GET['start'] ?? $defaultStart;
$end = $_GET['end'] ?? $today;
$search = trim($_GET['search'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 50;
$offset = ($page - 1) * $limit;

// Handle create/update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = isset($_POST['id']) ? (int) $_POST['id'] : null;
    $title = trim($_POST['title'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $desc = trim($_POST['description'] ?? '');
    $amount = (float) ($_POST['amount'] ?? 0);
    $date = $_POST['date'] ?? $today;
    $person = trim($_POST['person'] ?? '');

    if ($title === '' || $amount <= 0 || $date === '') {
        $alert = ['type' => 'danger', 'msg' => 'Title, positive amount, and date are required.'];
    } else {
        try {
            if ($id) {
                $stmt = $conn->prepare("UPDATE Expenses SET Title=?, Category=?, Description=?, Amount=?, ExpenseDate=?, Person=? WHERE ExpenseID=?");
                $stmt->bind_param('sssdssi', $title, $category, $desc, $amount, $date, $person, $id);
            } else {
                $stmt = $conn->prepare("INSERT INTO Expenses (Title, Category, Description, Amount, ExpenseDate, Person) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->bind_param('sssdss', $title, $category, $desc, $amount, $date, $person);
            }
            $stmt->execute();
            $alert = ['type' => 'success', 'msg' => $id ? 'Expense updated.' : 'Expense added.'];
            if (!$id) {
                // clear form after insert
                $title = $category = $desc = $person = '';
                $amount = 0;
                $date = $today;
            }
        } catch (Throwable $e) {
            $alert = ['type' => 'danger', 'msg' => 'Error: ' . $e->getMessage()];
        }
    }
}

// Handle delete
if (($_GET['delete'] ?? '') !== '') {
    $delId = (int) $_GET['delete'];
    try {
        $stmt = $conn->prepare("DELETE FROM Expenses WHERE ExpenseID = ?");
        $stmt->bind_param('i', $delId);
        $stmt->execute();
        $alert = ['type' => 'success', 'msg' => 'Expense deleted.'];
    } catch (Throwable $e) {
        $alert = ['type' => 'danger', 'msg' => 'Cannot delete expense.'];
    }
}

// Load edit data
$editData = null;
if (($_GET['edit'] ?? '') !== '') {
    $editId = (int) $_GET['edit'];
    $stmt = $conn->prepare("SELECT * FROM Expenses WHERE ExpenseID = ?");
    $stmt->bind_param('i', $editId);
    $stmt->execute();
    $editData = $stmt->get_result()->fetch_assoc();
}

// Count
$countSql = "SELECT COUNT(*) AS cnt FROM Expenses WHERE ExpenseDate BETWEEN ? AND ?";
$countTypes = 'ss';
$countParams = [$start, $end];
if ($search !== '') {
    $countSql .= " AND (Title LIKE ? OR Category LIKE ? OR Person LIKE ?)";
    $countTypes .= 'sss';
    $like = "%{$search}%";
    $countParams = array_merge($countParams, [$like, $like, $like]);
}
$cstmt = $conn->prepare($countSql);
$cstmt->bind_param($countTypes, ...$countParams);
$cstmt->execute();
$totalRows = (int) ($cstmt->get_result()->fetch_assoc()['cnt'] ?? 0);
$cstmt->close();

// Fetch list
$sql = "SELECT ExpenseID, Title, Category, Description, Amount, ExpenseDate, Person, CreatedAt
        FROM Expenses
        WHERE ExpenseDate BETWEEN ? AND ?";
$types = 'ss';
$params = [$start, $end];
if ($search !== '') {
    $sql .= " AND (Title LIKE ? OR Category LIKE ? OR Person LIKE ?)";
    $types .= 'sss';
    $params = array_merge($params, [$like, $like, $like]);
}
$sql .= " ORDER BY ExpenseDate DESC, ExpenseID DESC LIMIT ? OFFSET ?";
$types .= 'ii';
$params[] = $limit;
$params[] = $offset;
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$totalPages = max(1, (int)ceil($totalRows / $limit));

// Summary by category and totals
$sumStmt = $conn->prepare("SELECT SUM(Amount) AS total FROM Expenses WHERE ExpenseDate BETWEEN ? AND ?");
$sumStmt->bind_param('ss', $start, $end);
$sumStmt->execute();
$totalAmount = $sumStmt->get_result()->fetch_assoc()['total'] ?? 0;
$sumStmt->close();

$catStmt = $conn->prepare("
    SELECT Category, SUM(Amount) AS total
    FROM Expenses
    WHERE ExpenseDate BETWEEN ? AND ?
    GROUP BY Category
    ORDER BY total DESC
");
$catStmt->bind_param('ss', $start, $end);
$catStmt->execute();
$cats = $catStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$catStmt->close();
?>

<h2 class="mb-3 fw-bold">Expenses</h2>

<?php if ($alert): ?>
  <div class="alert alert-<?= $alert['type'] ?>"><?= htmlspecialchars($alert['msg']) ?></div>
<?php endif; ?>

<div class="card shadow-sm mb-3">
  <div class="card-body">
    <h5 class="fw-semibold mb-3"><?= $editData ? 'Edit Expense' : 'Add Expense' ?></h5>
    <form method="post" class="row g-3">
      <?php if ($editData): ?>
        <input type="hidden" name="id" value="<?= htmlspecialchars($editData['ExpenseID']) ?>">
      <?php endif; ?>
      <div class="col-md-4">
        <label class="form-label">Title *</label>
        <input type="text" name="title" class="form-control" value="<?= htmlspecialchars($editData['Title'] ?? '') ?>" required>
      </div>
      <div class="col-md-3">
        <label class="form-label">Category</label>
        <input type="text" name="category" class="form-control" value="<?= htmlspecialchars($editData['Category'] ?? '') ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">Person</label>
        <input type="text" name="person" class="form-control" value="<?= htmlspecialchars($editData['Person'] ?? '') ?>">
      </div>
      <div class="col-md-2">
        <label class="form-label">Amount *</label>
        <input type="number" step="0.01" min="0" name="amount" class="form-control" value="<?= htmlspecialchars($editData['Amount'] ?? '') ?>" required>
      </div>
      <div class="col-md-3">
        <label class="form-label">Date *</label>
        <input type="date" name="date" class="form-control" value="<?= htmlspecialchars($editData['ExpenseDate'] ?? $today) ?>" required>
      </div>
      <div class="col-md-12">
        <label class="form-label">Description</label>
        <textarea name="description" class="form-control" rows="2"><?= htmlspecialchars($editData['Description'] ?? '') ?></textarea>
      </div>
      <div class="col-12 d-flex gap-2">
        <button class="btn btn-primary"><?= $editData ? 'Update' : 'Add' ?></button>
        <?php if ($editData): ?>
          <a class="btn btn-secondary" href="Expense.php">Cancel</a>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div>

<div class="card shadow-sm mb-3">
  <div class="card-body">
    <form class="row g-2 align-items-end" method="get">
      <div class="col-auto">
        <label class="form-label mb-1">From</label>
        <input type="date" name="start" class="form-control" value="<?= htmlspecialchars($start) ?>">
      </div>
      <div class="col-auto">
        <label class="form-label mb-1">To</label>
        <input type="date" name="end" class="form-control" value="<?= htmlspecialchars($end) ?>">
      </div>
      <div class="col-auto">
        <label class="form-label mb-1">Search</label>
        <input type="text" name="search" class="form-control" placeholder="Title/Category/Person" value="<?= htmlspecialchars($search) ?>">
      </div>
      <div class="col-auto">
        <button class="btn btn-outline-secondary">Filter</button>
      </div>
    </form>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-md-6">
    <div class="card shadow-sm h-100">
      <div class="card-body">
        <h6 class="fw-semibold mb-2">Total (Filtered)</h6>
        <div class="h4 fw-bold">PKR <?= number_format($totalAmount, 2) ?></div>
      </div>
    </div>
  </div>
  <div class="col-md-6">
    <div class="card shadow-sm h-100">
      <div class="card-body">
        <h6 class="fw-semibold mb-2">By Category</h6>
        <div class="table-responsive">
          <table class="table table-sm mb-0">
            <thead class="table-light"><tr><th>Category</th><th class="text-end">Total</th></tr></thead>
            <tbody>
              <?php if (!$cats): ?>
                <tr><td colspan="2" class="text-muted text-center">No data</td></tr>
              <?php else: ?>
                <?php foreach ($cats as $c): ?>
                  <tr>
                    <td><?= htmlspecialchars($c['Category'] ?? 'Uncategorized') ?></td>
                    <td class="text-end">PKR <?= number_format($c['total'], 2) ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="card shadow-sm">
  <div class="card-body">
    <div class="d-flex justify-content-between align-items-center mb-2">
      <div class="text-muted small">Page <?= $page ?> of <?= $totalPages ?> (<?= $totalRows ?> records)</div>
      <div class="text-muted small">Limit: <?= $limit ?></div>
    </div>
    <div class="table-responsive">
      <table class="table table-striped mb-0 align-middle">
        <thead class="table-light">
          <tr>
            <th>Title</th>
            <th>Category</th>
            <th>Person</th>
            <th class="text-end">Amount</th>
            <th>Date</th>
            <th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="6" class="text-center text-muted py-3">No expenses found</td></tr>
          <?php else: ?>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td><?= htmlspecialchars($r['Title']) ?></td>
                <td><?= htmlspecialchars($r['Category']) ?></td>
                <td><?= htmlspecialchars($r['Person']) ?></td>
                <td class="text-end">PKR <?= number_format($r['Amount'], 2) ?></td>
                <td><?= htmlspecialchars($r['ExpenseDate']) ?></td>
                <td class="text-end">
                  <a class="btn btn-sm btn-outline-primary" href="Expense.php?edit=<?= $r['ExpenseID'] ?>">Edit</a>
                  <a class="btn btn-sm btn-outline-danger" href="Expense.php?delete=<?= $r['ExpenseID'] ?>" onclick="return confirm('Delete this expense?')">Delete</a>
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

