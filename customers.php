<?php
ob_start(); // allows redirect-after-POST even though header.php prints the page header
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/customers_lib.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');
date_default_timezone_set('Asia/Karachi');
$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

$alert = null;
$flash = [
    'saved' => ['success', 'Customer saved.'],
    'paid' => ['success', 'Payment recorded.'],
    'status' => ['success', 'Customer status updated.'],
];
if (isset($flash[$_GET['msg'] ?? ''])) {
    $alert = ['type' => $flash[$_GET['msg']][0], 'msg' => $flash[$_GET['msg']][1]];
}

function redirect_to(string $url): void
{
    ob_end_clean();
    header('Location: ' . $url);
    exit();
}

$form = null; // re-filled form values after a validation error
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $which = $_POST['form'] ?? 'customer';
    if ($which === 'receive_payment') {
        $cid = (int)($_POST['customer_id'] ?? 0);
        try {
            $err = record_customer_payment($conn, $cid, $_POST['amount'] ?? 0, (string)($_POST['date'] ?? ''), (string)($_POST['method'] ?? 'Cash'), (string)($_POST['note'] ?? ''), $userId);
        } catch (Throwable $e) {
            $err = 'Error: ' . $e->getMessage();
        }
        if ($err === null) {
            redirect_to('customers.php?msg=paid');
        }
        $alert = ['type' => 'danger', 'msg' => $err];
    } elseif ($which === 'toggle') {
        $cid = (int)($_POST['customer_id'] ?? 0);
        $stmt = $conn->prepare("UPDATE Customers SET IsActive = 1 - IsActive WHERE CustomerID = ?");
        $stmt->bind_param('i', $cid);
        $stmt->execute();
        redirect_to('customers.php?msg=status');
    } else {
        $id = isset($_POST['id']) && $_POST['id'] !== '' ? (int)$_POST['id'] : null;
        $name = trim($_POST['name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $area = trim($_POST['area'] ?? '');
        $opening = round((float)($_POST['opening_balance'] ?? 0), 2);
        $limitRaw = trim($_POST['credit_limit'] ?? '');
        $limit = $limitRaw === '' ? null : round((float)$limitRaw, 2);
        $notes = trim($_POST['notes'] ?? '');
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $form = ['CustomerID' => $id, 'CustomerName' => $name, 'Phone' => $phone, 'Address' => $address, 'Area' => $area,
                 'OpeningBalance' => $opening, 'CreditLimit' => $limit, 'Notes' => $notes, 'IsActive' => $isActive];

        $dup = null;
        if ($phone !== '') {
            $stmt = $conn->prepare("SELECT CustomerName FROM Customers WHERE Phone = ? AND IsActive = 1 AND CustomerID <> ? LIMIT 1");
            $selfId = $id ?? 0;
            $stmt->bind_param('si', $phone, $selfId);
            $stmt->execute();
            $dup = $stmt->get_result()->fetch_assoc();
        }

        if ($name === '') {
            $alert = ['type' => 'danger', 'msg' => 'Customer name is required.'];
        } elseif (mb_strlen($name) > 150 || mb_strlen($phone) > 30 || mb_strlen($address) > 255 || mb_strlen($area) > 100) {
            $alert = ['type' => 'danger', 'msg' => 'One of the fields is too long.'];
        } elseif ($limit !== null && $limit < 0) {
            $alert = ['type' => 'danger', 'msg' => 'Credit limit cannot be negative.'];
        } elseif ($dup) {
            $alert = ['type' => 'danger', 'msg' => 'This phone already belongs to customer: ' . $dup['CustomerName']];
        } else {
            $phoneVal = $phone !== '' ? $phone : null;
            $addrVal = $address !== '' ? $address : null;
            $areaVal = $area !== '' ? $area : null;
            $notesVal = $notes !== '' ? $notes : null;
            try {
                if ($id) {
                    $stmt = $conn->prepare("UPDATE Customers SET CustomerName=?, Phone=?, Address=?, Area=?, OpeningBalance=?, CreditLimit=?, Notes=?, IsActive=? WHERE CustomerID=?");
                    $stmt->bind_param('ssssddsii', $name, $phoneVal, $addrVal, $areaVal, $opening, $limit, $notesVal, $isActive, $id);
                } else {
                    $stmt = $conn->prepare("INSERT INTO Customers (CustomerName, Phone, Address, Area, OpeningBalance, CreditLimit, Notes, IsActive) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param('ssssddsi', $name, $phoneVal, $addrVal, $areaVal, $opening, $limit, $notesVal, $isActive);
                }
                $stmt->execute();
                redirect_to('customers.php?msg=saved');
            } catch (Throwable $e) {
                $alert = ['type' => 'danger', 'msg' => 'Error: ' . $e->getMessage()];
            }
        }
    }
}
ob_end_flush();

$editData = $form;
if (!$editData && ($_GET['edit'] ?? '') !== '') {
    $editId = (int)$_GET['edit'];
    $stmt = $conn->prepare("SELECT * FROM Customers WHERE CustomerID = ?");
    $stmt->bind_param('i', $editId);
    $stmt->execute();
    $editData = $stmt->get_result()->fetch_assoc();
}
$isEdit = $editData && !empty($editData['CustomerID']);

$q = trim($_GET['q'] ?? '');
$show = $_GET['show'] ?? 'active';
$sql = "SELECT CustomerID, CustomerName, Phone, Address, Area, OpeningBalance, CreditLimit, IsActive FROM Customers WHERE 1=1";
$types = '';
$params = [];
if ($show !== 'all') {
    $sql .= " AND IsActive = 1";
}
if ($q !== '') {
    $sql .= " AND (CustomerName LIKE ? OR Phone LIKE ? OR Area LIKE ? OR Address LIKE ?)";
    $like = '%' . $q . '%';
    $types = 'ssss';
    $params = [$like, $like, $like, $like];
}
$sql .= " ORDER BY CustomerName";
$stmt = $conn->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$balances = customer_balances($conn);
$totalDue = 0;
foreach ($rows as $r) {
    $totalDue += $balances[(int)$r['CustomerID']] ?? 0;
}
$v = fn($k, $d = '') => htmlspecialchars((string)($editData[$k] ?? $d));
?>

<h2 class="mb-4 fw-bold">Customers</h2>

<?php if ($alert): ?>
  <div class="alert alert-<?= $alert['type'] ?>"><?= htmlspecialchars($alert['msg']) ?></div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-4">
    <div class="card shadow-sm">
      <div class="card-header bg-white fw-semibold"><?= $isEdit ? 'Edit Customer' : 'Add Customer' ?></div>
      <div class="card-body">
        <form method="post" action="customers.php">
          <input type="hidden" name="form" value="customer">
          <?php if ($isEdit): ?>
            <input type="hidden" name="id" value="<?= (int)$editData['CustomerID'] ?>">
          <?php endif; ?>
          <div class="mb-3">
            <label class="form-label">Name *</label>
            <input type="text" name="name" maxlength="150" class="form-control" value="<?= $v('CustomerName') ?>" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Phone</label>
            <input type="text" name="phone" maxlength="30" class="form-control" value="<?= $v('Phone') ?>">
          </div>
          <div class="mb-3">
            <label class="form-label">Address</label>
            <input type="text" name="address" maxlength="255" class="form-control" value="<?= $v('Address') ?>">
          </div>
          <div class="mb-3">
            <label class="form-label">Area</label>
            <input type="text" name="area" maxlength="100" class="form-control" value="<?= $v('Area') ?>">
          </div>
          <div class="row g-2 mb-3">
            <div class="col">
              <label class="form-label">Opening Balance</label>
              <input type="number" name="opening_balance" step="0.01" class="form-control" value="<?= $v('OpeningBalance', '0') ?>">
            </div>
            <div class="col">
              <label class="form-label">Credit Limit</label>
              <input type="number" name="credit_limit" min="0" step="0.01" class="form-control" placeholder="No limit" value="<?= $v('CreditLimit') ?>">
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">Notes</label>
            <textarea name="notes" rows="2" class="form-control"><?= $v('Notes') ?></textarea>
          </div>
          <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" name="is_active" id="isActive" <?= ($editData['IsActive'] ?? 1) ? 'checked' : '' ?>>
            <label class="form-check-label" for="isActive">Active</label>
          </div>
          <div class="d-flex gap-2">
            <button class="btn btn-primary" type="submit"><?= $isEdit ? 'Update' : 'Add' ?></button>
            <?php if ($isEdit || $form): ?>
              <a class="btn btn-secondary" href="customers.php">Cancel</a>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </div>
  </div>
  <div class="col-lg-8">
    <div class="card shadow-sm">
      <div class="card-header bg-white">
        <form class="d-flex flex-wrap gap-2 align-items-center" method="get" action="customers.php">
          <span class="fw-semibold me-auto">All Customers <span class="text-muted small">(<?= count($rows) ?>, total due Rs. <?= number_format($totalDue, 2) ?>)</span></span>
          <input type="text" name="q" class="form-control form-control-sm" style="max-width:220px" placeholder="Search name / phone / area" value="<?= htmlspecialchars($q) ?>">
          <select name="show" class="form-select form-select-sm" style="max-width:130px">
            <option value="active" <?= $show !== 'all' ? 'selected' : '' ?>>Active only</option>
            <option value="all" <?= $show === 'all' ? 'selected' : '' ?>>All</option>
          </select>
          <button class="btn btn-sm btn-outline-primary" type="submit">Search</button>
        </form>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-striped mb-0 align-middle">
            <thead class="table-light">
              <tr>
                <th>Name</th>
                <th>Phone</th>
                <th>Area</th>
                <th class="text-end">Balance</th>
                <th>Status</th>
                <th class="text-end">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$rows): ?>
                <tr><td colspan="6" class="text-center text-muted py-4">No customers found.</td></tr>
              <?php endif; ?>
              <?php foreach ($rows as $row): $cid = (int)$row['CustomerID']; $bal = $balances[$cid] ?? 0; ?>
                <tr>
                  <td>
                    <?= htmlspecialchars($row['CustomerName']) ?>
                    <?php if ($row['CreditLimit'] !== null): ?>
                      <div class="small text-muted">Limit Rs. <?= number_format((float)$row['CreditLimit'], 2) ?></div>
                    <?php endif; ?>
                  </td>
                  <td><?= htmlspecialchars($row['Phone'] ?? '') ?></td>
                  <td><?= htmlspecialchars($row['Area'] ?? '') ?></td>
                  <td class="text-end fw-semibold <?= $bal > 0 ? 'text-danger' : ($bal < 0 ? 'text-success' : '') ?>">Rs. <?= number_format($bal, 2) ?></td>
                  <td><span class="badge bg-<?= $row['IsActive'] ? 'success' : 'secondary' ?>"><?= $row['IsActive'] ? 'Active' : 'Inactive' ?></span></td>
                  <td class="text-end text-nowrap">
                    <button type="button" class="btn btn-sm btn-success" data-receive-payment
                            data-customer-id="<?= $cid ?>"
                            data-customer-name="<?= htmlspecialchars($row['CustomerName']) ?>"
                            data-balance="Rs. <?= number_format($bal, 2) ?>">Receive</button>
                    <a class="btn btn-sm btn-outline-secondary" href="customer_ledger.php?customer_id=<?= $cid ?>">Ledger</a>
                    <a class="btn btn-sm btn-outline-primary" href="customers.php?edit=<?= $cid ?>">Edit</a>
                    <form method="post" action="customers.php" class="d-inline" onsubmit="return confirm('<?= $row['IsActive'] ? 'Deactivate' : 'Activate' ?> this customer?')">
                      <input type="hidden" name="form" value="toggle">
                      <input type="hidden" name="customer_id" value="<?= $cid ?>">
                      <button class="btn btn-sm btn-outline-<?= $row['IsActive'] ? 'danger' : 'success' ?>" type="submit"><?= $row['IsActive'] ? 'Deactivate' : 'Activate' ?></button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php render_receive_payment_modal('customers.php'); ?>
</body>
</html>
