<?php
ob_start(); // allows redirect-after-POST even though header.php prints the page header
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/vendors_lib.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');
date_default_timezone_set('Asia/Karachi');
$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

$alert = null;
$flash = [
    'saved' => ['success', 'Vendor saved.'],
    'paid' => ['success', 'Payment to vendor recorded.'],
    'status' => ['success', 'Vendor status updated.'],
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
    $which = $_POST['form'] ?? 'vendor';
    if ($which === 'vendor_payment') {
        $vid = (int)($_POST['vendor_id'] ?? 0);
        try {
            $err = record_vendor_payment($conn, $vid, $_POST['amount'] ?? 0, (string)($_POST['date'] ?? ''), (string)($_POST['method'] ?? 'Cash'),
                (string)($_POST['reference'] ?? ''), (string)($_POST['note'] ?? ''), $userId);
        } catch (Throwable $e) {
            $err = 'Error: ' . $e->getMessage();
        }
        if ($err === null) {
            redirect_to('vendors.php?msg=paid');
        }
        $alert = ['type' => 'danger', 'msg' => $err];
    } elseif ($which === 'toggle') {
        $vid = (int)($_POST['vendor_id'] ?? 0);
        $stmt = $conn->prepare("UPDATE Vendors SET IsActive = 1 - IsActive WHERE VendorID = ?");
        $stmt->bind_param('i', $vid);
        $stmt->execute();
        redirect_to('vendors.php?msg=status');
    } else {
        $id = isset($_POST['id']) && $_POST['id'] !== '' ? (int)$_POST['id'] : null;
        $name = trim($_POST['name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $opening = round((float)($_POST['opening_balance'] ?? 0), 2);
        $notes = trim($_POST['notes'] ?? '');
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $form = ['VendorID' => $id, 'VendorName' => $name, 'Phone' => $phone, 'Address' => $address,
                 'OpeningBalance' => $opening, 'Notes' => $notes, 'IsActive' => $isActive];

        $dup = null;
        if ($name !== '') {
            $stmt = $conn->prepare("SELECT VendorName FROM Vendors WHERE VendorName = ? AND VendorID <> ? LIMIT 1");
            $selfId = $id ?? 0;
            $stmt->bind_param('si', $name, $selfId);
            $stmt->execute();
            $dup = $stmt->get_result()->fetch_assoc();
        }

        if ($name === '') {
            $alert = ['type' => 'danger', 'msg' => 'Vendor name is required.'];
        } elseif (mb_strlen($name) > 200 || mb_strlen($phone) > 50 || mb_strlen($address) > 500) {
            $alert = ['type' => 'danger', 'msg' => 'One of the fields is too long.'];
        } elseif ($dup) {
            $alert = ['type' => 'danger', 'msg' => 'A vendor with this name already exists.'];
        } else {
            $phoneVal = $phone !== '' ? $phone : null;
            $addrVal = $address !== '' ? $address : null;
            $notesVal = $notes !== '' ? $notes : null;
            try {
                if ($id) {
                    $stmt = $conn->prepare("UPDATE Vendors SET VendorName=?, Phone=?, Address=?, OpeningBalance=?, Notes=?, IsActive=? WHERE VendorID=?");
                    $stmt->bind_param('sssdsii', $name, $phoneVal, $addrVal, $opening, $notesVal, $isActive, $id);
                } else {
                    $stmt = $conn->prepare("INSERT INTO Vendors (VendorName, Phone, Address, OpeningBalance, Notes, IsActive) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param('sssdsi', $name, $phoneVal, $addrVal, $opening, $notesVal, $isActive);
                }
                $stmt->execute();
                redirect_to('vendors.php?msg=saved');
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
    $stmt = $conn->prepare("SELECT * FROM Vendors WHERE VendorID = ?");
    $stmt->bind_param('i', $editId);
    $stmt->execute();
    $editData = $stmt->get_result()->fetch_assoc();
}
$isEdit = $editData && !empty($editData['VendorID']);

$q = trim($_GET['q'] ?? '');
$show = $_GET['show'] ?? 'active';
$sql = "SELECT v.VendorID, v.VendorName, v.Phone, v.Address, v.OpeningBalance, v.IsActive,
               (SELECT MAX(po.OrderDate) FROM PurchaseOrders po WHERE po.VendorID = v.VendorID AND po.Status = 'Received') AS LastPurchase
        FROM Vendors v WHERE 1=1";
$types = '';
$params = [];
if ($show !== 'all') {
    $sql .= " AND v.IsActive = 1";
}
if ($q !== '') {
    $sql .= " AND (v.VendorName LIKE ? OR v.Phone LIKE ? OR v.Address LIKE ?)";
    $like = '%' . $q . '%';
    $types = 'sss';
    $params = [$like, $like, $like];
}
$sql .= " ORDER BY v.VendorName";
$stmt = $conn->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$balances = vendor_balances($conn);
$totalPayable = 0;
foreach ($rows as $r) {
    $totalPayable += $balances[(int)$r['VendorID']] ?? 0;
}
$v = fn($k, $d = '') => htmlspecialchars((string)($editData[$k] ?? $d));
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
  <h2 class="fw-bold mb-0">Vendors</h2>
  <div class="d-flex gap-2">
    <a class="btn btn-primary btn-sm" href="purchases.php?new=1">+ New Purchase</a>
    <a class="btn btn-outline-secondary btn-sm" href="vendor_payouts.php">Payouts</a>
    <a class="btn btn-outline-secondary btn-sm" href="vendor_ledger.php">Vendor Ledger</a>
  </div>
</div>

<?php if ($alert): ?>
  <div class="alert alert-<?= $alert['type'] ?>"><?= htmlspecialchars($alert['msg']) ?></div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-4">
    <div class="card shadow-sm">
      <div class="card-header bg-white fw-semibold"><?= $isEdit ? 'Edit Vendor' : 'Add Vendor' ?></div>
      <div class="card-body">
        <form method="post" action="vendors.php">
          <input type="hidden" name="form" value="vendor">
          <?php if ($isEdit): ?>
            <input type="hidden" name="id" value="<?= (int)$editData['VendorID'] ?>">
          <?php endif; ?>
          <div class="mb-3">
            <label class="form-label">Name *</label>
            <input type="text" name="name" maxlength="200" class="form-control" value="<?= $v('VendorName') ?>" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Phone</label>
            <input type="text" name="phone" maxlength="50" class="form-control" value="<?= $v('Phone') ?>">
          </div>
          <div class="mb-3">
            <label class="form-label">Address</label>
            <input type="text" name="address" maxlength="500" class="form-control" value="<?= $v('Address') ?>">
          </div>
          <div class="mb-3">
            <label class="form-label">Opening Balance (payable)</label>
            <input type="number" name="opening_balance" step="0.01" class="form-control" value="<?= $v('OpeningBalance', '0') ?>">
            <div class="form-text">Amount you already owed this vendor before using the app.</div>
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
              <a class="btn btn-secondary" href="vendors.php">Cancel</a>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </div>
  </div>
  <div class="col-lg-8">
    <div class="card shadow-sm">
      <div class="card-header bg-white">
        <form class="d-flex flex-wrap gap-2 align-items-center" method="get" action="vendors.php">
          <span class="fw-semibold me-auto">All Vendors <span class="text-muted small">(<?= count($rows) ?>, total payable Rs. <?= number_format($totalPayable, 2) ?>)</span></span>
          <input type="text" name="q" class="form-control form-control-sm" style="max-width:220px" placeholder="Search name / phone / address" value="<?= htmlspecialchars($q) ?>">
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
                <th>Last purchase</th>
                <th class="text-end">Payable</th>
                <th>Status</th>
                <th class="text-end">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$rows): ?>
                <tr><td colspan="6" class="text-center text-muted py-4">No vendors found.</td></tr>
              <?php endif; ?>
              <?php foreach ($rows as $row): $vid = (int)$row['VendorID']; $bal = $balances[$vid] ?? 0; ?>
                <tr>
                  <td>
                    <?= htmlspecialchars($row['VendorName']) ?>
                    <?php if (!empty($row['Address'])): ?>
                      <div class="small text-muted"><?= htmlspecialchars($row['Address']) ?></div>
                    <?php endif; ?>
                  </td>
                  <td><?= htmlspecialchars($row['Phone'] ?? '') ?></td>
                  <td class="small"><?= $row['LastPurchase'] ? date('d-M-Y', strtotime($row['LastPurchase'])) : '<span class="text-muted">—</span>' ?></td>
                  <td class="text-end fw-semibold <?= $bal > 0 ? 'text-danger' : ($bal < 0 ? 'text-success' : '') ?>">Rs. <?= number_format($bal, 2) ?></td>
                  <td><span class="badge bg-<?= $row['IsActive'] ? 'success' : 'secondary' ?>"><?= $row['IsActive'] ? 'Active' : 'Inactive' ?></span></td>
                  <td class="text-end text-nowrap">
                    <button type="button" class="btn btn-sm btn-success" data-vendor-payment
                            data-vendor-id="<?= $vid ?>"
                            data-vendor-name="<?= htmlspecialchars($row['VendorName']) ?>"
                            data-balance="Rs. <?= number_format($bal, 2) ?>">Pay</button>
                    <a class="btn btn-sm btn-outline-primary" href="purchases.php?new=1&vendor_id=<?= $vid ?>">Purchase</a>
                    <a class="btn btn-sm btn-outline-secondary" href="vendor_ledger.php?vendor_id=<?= $vid ?>">Ledger</a>
                    <a class="btn btn-sm btn-outline-primary" href="vendors.php?edit=<?= $vid ?>">Edit</a>
                    <form method="post" action="vendors.php" class="d-inline" onsubmit="return confirm('<?= $row['IsActive'] ? 'Deactivate' : 'Activate' ?> this vendor?')">
                      <input type="hidden" name="form" value="toggle">
                      <input type="hidden" name="vendor_id" value="<?= $vid ?>">
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
<?php render_vendor_payment_modal('vendors.php'); ?>
</body>
</html>
