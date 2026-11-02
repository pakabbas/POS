<?php
ob_start(); // allows redirect-after-POST even though header.php prints the page header
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/vendors_lib.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');
date_default_timezone_set('Asia/Karachi');
$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

$today = date('Y-m-d');
$from = valid_ymd($_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-01');
$to = valid_ymd($_GET['to'] ?? '') ? $_GET['to'] : $today;
if ($from > $to) { [$from, $to] = [$to, $from]; }
$fVendor = (int)($_GET['vendor_id'] ?? 0);
$fMethod = in_array($_GET['method'] ?? '', vendor_payment_methods(), true) ? $_GET['method'] : '';
$selfUrl = 'vendor_payouts.php?' . http_build_query(['from' => $from, 'to' => $to, 'vendor_id' => $fVendor, 'method' => $fMethod]);

$alert = null;
if (($_GET['msg'] ?? '') === 'paid') $alert = ['type' => 'success', 'msg' => 'Payment to vendor recorded.'];
if (($_GET['msg'] ?? '') === 'deleted') $alert = ['type' => 'success', 'msg' => 'Payment deleted.'];

$post = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $which = $_POST['form'] ?? '';
    if ($which === 'vendor_payment') {
        $post = $_POST;
        try {
            $err = record_vendor_payment($conn, (int)($_POST['vendor_id'] ?? 0), $_POST['amount'] ?? 0, (string)($_POST['date'] ?? ''), (string)($_POST['method'] ?? 'Cash'),
                (string)($_POST['reference'] ?? ''), (string)($_POST['note'] ?? ''), $userId);
        } catch (Throwable $e) {
            $err = 'Error: ' . $e->getMessage();
        }
        if ($err === null) {
            ob_end_clean();
            header('Location: ' . $selfUrl . '&msg=paid');
            exit();
        }
        $alert = ['type' => 'danger', 'msg' => $err];
    } elseif ($which === 'delete_payment' && $role === 'Admin') {
        $pid = (int)($_POST['payment_id'] ?? 0);
        $stmt = $conn->prepare("DELETE FROM VendorPayments WHERE PaymentID = ? AND PurchaseOrderID IS NULL");
        $stmt->bind_param('i', $pid);
        $stmt->execute();
        ob_end_clean();
        header('Location: ' . $selfUrl . '&msg=deleted');
        exit();
    }
}
ob_end_flush();

$vendors = $conn->query("SELECT VendorID, VendorName, IsActive FROM Vendors ORDER BY IsActive DESC, VendorName")->fetch_all(MYSQLI_ASSOC);
$balances = vendor_balances($conn);

$sql = "SELECT p.*, v.VendorName, po.PONumber, u.UserName FROM VendorPayments p
        JOIN Vendors v ON v.VendorID = p.VendorID
        LEFT JOIN PurchaseOrders po ON po.PurchaseOrderID = p.PurchaseOrderID
        LEFT JOIN Users u ON u.UserID = p.CreatedBy
        WHERE p.PaymentDate BETWEEN ? AND ?";
$types = 'ss';
$params = [$from . ' 00:00:00', $to . ' 23:59:59'];
if ($fVendor > 0) { $sql .= " AND p.VendorID = ?"; $types .= 'i'; $params[] = $fVendor; }
if ($fMethod !== '') { $sql .= " AND p.PaymentMethod = ?"; $types .= 's'; $params[] = $fMethod; }
$sql .= " ORDER BY p.PaymentDate DESC, p.PaymentID DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$byMethod = [];
foreach ($rows as $r) {
    $m = $r['PaymentMethod'] ?: 'Cash';
    $byMethod[$m] = ($byMethod[$m] ?? 0) + (float)$r['AmountPaid'];
}
$total = array_sum($byMethod);
$rs = fn($n) => number_format((float)$n, 2);
$pv = fn($k, $d = '') => htmlspecialchars((string)($post[$k] ?? $d));
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
  <h2 class="fw-bold mb-0">Vendor Payouts</h2>
  <div class="d-flex gap-2">
    <a class="btn btn-outline-secondary btn-sm" href="vendors.php">Vendors</a>
    <?php if ($role === 'Admin'): ?><a class="btn btn-outline-secondary btn-sm" href="cash_summary.php">Cash Summary</a><?php endif; ?>
  </div>
</div>

<?php if ($alert): ?>
  <div class="alert alert-<?= $alert['type'] ?>"><?= htmlspecialchars($alert['msg']) ?></div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-4">
    <div class="card shadow-sm">
      <div class="card-header bg-white fw-semibold">Record Payout</div>
      <div class="card-body">
        <form method="post" action="<?= htmlspecialchars($selfUrl) ?>">
          <input type="hidden" name="form" value="vendor_payment">
          <div class="mb-3">
            <label class="form-label">Vendor *</label>
            <select name="vendor_id" class="form-select" id="poVendor" required>
              <option value="">— Select vendor —</option>
              <?php foreach ($vendors as $vv): if (!$vv['IsActive']) continue; $b = $balances[(int)$vv['VendorID']] ?? 0; ?>
                <option value="<?= (int)$vv['VendorID'] ?>" <?= (int)($post['vendor_id'] ?? $fVendor) === (int)$vv['VendorID'] ? 'selected' : '' ?>><?= htmlspecialchars($vv['VendorName']) ?> — payable Rs. <?= $rs($b) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Amount (Rs.) *</label>
            <input type="number" name="amount" min="0.01" step="0.01" class="form-control" value="<?= $pv('amount') ?>" required>
          </div>
          <div class="row g-2 mb-3">
            <div class="col">
              <label class="form-label">Date</label>
              <input type="date" name="date" class="form-control" value="<?= $pv('date', $today) ?>" max="<?= $today ?>" required>
            </div>
            <div class="col">
              <label class="form-label">Method</label>
              <select name="method" class="form-select">
                <?php foreach (vendor_payment_methods() as $m): ?>
                  <option value="<?= htmlspecialchars($m) ?>" <?= ($post['method'] ?? 'Cash') === $m ? 'selected' : '' ?>><?= htmlspecialchars($m) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">Reference</label>
            <input type="text" name="reference" maxlength="100" class="form-control" value="<?= $pv('reference') ?>" placeholder="Cheque no. / transaction ID">
          </div>
          <div class="mb-3">
            <label class="form-label">Note</label>
            <input type="text" name="note" maxlength="255" class="form-control" value="<?= $pv('note') ?>">
          </div>
          <button class="btn btn-success" type="submit">Save Payment</button>
        </form>
      </div>
    </div>
  </div>
  <div class="col-lg-8">
    <div class="card shadow-sm">
      <div class="card-header bg-white">
        <form class="row g-2 align-items-end" method="get" action="vendor_payouts.php">
          <div class="col-md-4">
            <select name="vendor_id" class="form-select form-select-sm">
              <option value="0">All vendors</option>
              <?php foreach ($vendors as $vv): ?>
                <option value="<?= (int)$vv['VendorID'] ?>" <?= (int)$vv['VendorID'] === $fVendor ? 'selected' : '' ?>><?= htmlspecialchars($vv['VendorName']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-2">
            <select name="method" class="form-select form-select-sm">
              <option value="">All methods</option>
              <?php foreach (vendor_payment_methods() as $m): ?>
                <option value="<?= htmlspecialchars($m) ?>" <?= $fMethod === $m ? 'selected' : '' ?>><?= htmlspecialchars($m) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-2"><input type="date" name="from" class="form-control form-control-sm" value="<?= htmlspecialchars($from) ?>"></div>
          <div class="col-md-2"><input type="date" name="to" class="form-control form-control-sm" value="<?= htmlspecialchars($to) ?>"></div>
          <div class="col-md-2"><button class="btn btn-sm btn-outline-primary w-100" type="submit">Filter</button></div>
        </form>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-striped table-sm mb-0 align-middle">
            <thead class="table-light"><tr><th>Date</th><th>Vendor</th><th>Method</th><th>Reference / note</th><th class="text-end">Amount</th><?php if ($role === 'Admin'): ?><th></th><?php endif; ?></tr></thead>
            <tbody>
              <?php if (!$rows): ?>
                <tr><td colspan="6" class="text-center text-muted py-4">No payouts in this period.</td></tr>
              <?php endif; ?>
              <?php foreach ($rows as $r): ?>
                <tr>
                  <td class="text-nowrap"><?= date('d-M-Y g:i A', strtotime($r['PaymentDate'])) ?></td>
                  <td><a href="vendor_ledger.php?vendor_id=<?= (int)$r['VendorID'] ?>"><?= htmlspecialchars($r['VendorName']) ?></a></td>
                  <td><?= htmlspecialchars($r['PaymentMethod'] ?? 'Cash') ?></td>
                  <td class="small">
                    <?php if ($r['PONumber']): ?><a href="purchases.php?view=<?= (int)$r['PurchaseOrderID'] ?>"><?= htmlspecialchars($r['PONumber']) ?></a> · <?php endif; ?>
                    <?= htmlspecialchars(implode(' · ', array_filter([$r['ReferenceNumber'] ?? '', $r['Notes'] ?? '']))) ?>
                    <?php if ($r['UserName']): ?><span class="text-muted">(<?= htmlspecialchars($r['UserName']) ?>)</span><?php endif; ?>
                  </td>
                  <td class="text-end fw-semibold"><?= $rs($r['AmountPaid']) ?></td>
                  <?php if ($role === 'Admin'): ?>
                    <td class="text-end">
                      <?php if (!$r['PurchaseOrderID']): ?>
                        <form method="post" action="<?= htmlspecialchars($selfUrl) ?>" onsubmit="return confirm('Delete this payment entry?')">
                          <input type="hidden" name="form" value="delete_payment">
                          <input type="hidden" name="payment_id" value="<?= (int)$r['PaymentID'] ?>">
                          <button class="btn btn-sm btn-outline-danger py-0" type="submit">✕</button>
                        </form>
                      <?php endif; ?>
                    </td>
                  <?php endif; ?>
                </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot class="table-light fw-bold">
              <tr><td colspan="4" class="text-end">Total (<?= count($rows) ?>)<?php foreach ($byMethod as $m => $amt): ?> <span class="fw-normal small ms-2"><?= htmlspecialchars($m) ?>: <?= $rs($amt) ?></span><?php endforeach; ?></td><td class="text-end">Rs. <?= $rs($total) ?></td><?php if ($role === 'Admin'): ?><td></td><?php endif; ?></tr>
            </tfoot>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
