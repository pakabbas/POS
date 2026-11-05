<?php
ob_start(); // allows redirect-after-POST even though header.php prints the page header
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/vendors_lib.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');
date_default_timezone_set('Asia/Karachi');
$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

$vendorId = (int)($_REQUEST['vendor_id'] ?? 0);
$today = date('Y-m-d');
$from = valid_ymd($_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-01');
$to = valid_ymd($_GET['to'] ?? '') ? $_GET['to'] : $today;
if ($from > $to) { [$from, $to] = [$to, $from]; }
$selfUrl = 'vendor_ledger.php?vendor_id=' . $vendorId . '&from=' . urlencode($from) . '&to=' . urlencode($to);

$alert = null;
if (($_GET['msg'] ?? '') === 'paid') $alert = ['type' => 'success', 'msg' => 'Payment to vendor recorded.'];
if (($_GET['msg'] ?? '') === 'deleted') $alert = ['type' => 'success', 'msg' => 'Payment deleted.'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $which = $_POST['form'] ?? '';
    $vid = (int)($_POST['vendor_id'] ?? 0);
    $back = 'vendor_ledger.php?vendor_id=' . $vid . '&from=' . urlencode($from) . '&to=' . urlencode($to);
    if ($which === 'vendor_payment') {
        try {
            $err = record_vendor_payment($conn, $vid, $_POST['amount'] ?? 0, (string)($_POST['date'] ?? ''), (string)($_POST['method'] ?? 'Cash'),
                (string)($_POST['reference'] ?? ''), (string)($_POST['note'] ?? ''), $userId);
        } catch (Throwable $e) {
            $err = 'Error: ' . $e->getMessage();
        }
        if ($err === null) {
            ob_end_clean();
            header('Location: ' . $back . '&msg=paid');
            exit();
        }
        $alert = ['type' => 'danger', 'msg' => $err];
    } elseif ($which === 'delete_payment' && $role === 'Admin') {
        // Only standalone payouts; an amount paid at a purchase belongs to that purchase entry.
        $pid = (int)($_POST['payment_id'] ?? 0);
        $stmt = $conn->prepare("DELETE FROM VendorPayments WHERE PaymentID = ? AND VendorID = ? AND PurchaseOrderID IS NULL");
        $stmt->bind_param('ii', $pid, $vid);
        $stmt->execute();
        ob_end_clean();
        header('Location: ' . $back . '&msg=deleted');
        exit();
    }
}
ob_end_flush();

$vendors = $conn->query("SELECT VendorID, VendorName, Phone, IsActive FROM Vendors ORDER BY IsActive DESC, VendorName")->fetch_all(MYSQLI_ASSOC);

$vendor = null;
$ledger = null;
$currentBalance = 0;
if ($vendorId > 0) {
    $stmt = $conn->prepare("SELECT * FROM Vendors WHERE VendorID = ?");
    $stmt->bind_param('i', $vendorId);
    $stmt->execute();
    $vendor = $stmt->get_result()->fetch_assoc();
    if ($vendor) {
        $ledger = vendor_ledger($conn, $vendorId, $from, $to);
        $currentBalance = vendor_balance($conn, $vendorId);
    }
}
$rs = fn($n) => number_format((float)$n, 2);
?>
<style>
@media print {
  nav.navbar, .no-print { display: none !important; }
  body.bg-app { background: #fff !important; }
  .card { border: none !important; box-shadow: none !important; }
  .container { max-width: 100% !important; }
  .table { font-size: 12px; }
}
.print-only { display: none; }
@media print { .print-only { display: block; } }
</style>

<div class="d-flex justify-content-between align-items-center mb-3 no-print">
  <h2 class="fw-bold mb-0">Vendor Ledger</h2>
  <a class="btn btn-outline-secondary btn-sm" href="vendors.php">← Vendors</a>
</div>

<?php if ($alert): ?>
  <div class="alert alert-<?= $alert['type'] ?> no-print"><?= htmlspecialchars($alert['msg']) ?></div>
<?php endif; ?>

<div class="card shadow-sm mb-3 no-print">
  <div class="card-body">
    <form method="get" action="vendor_ledger.php" class="row g-2 align-items-end">
      <div class="col-md-5">
        <label class="form-label">Vendor</label>
        <select name="vendor_id" class="form-select" required>
          <option value="">— Select vendor —</option>
          <?php foreach ($vendors as $vv): ?>
            <option value="<?= (int)$vv['VendorID'] ?>" <?= (int)$vv['VendorID'] === $vendorId ? 'selected' : '' ?>>
              <?= htmlspecialchars($vv['VendorName'] . ($vv['Phone'] ? ' (' . $vv['Phone'] . ')' : '') . ($vv['IsActive'] ? '' : ' [inactive]')) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label">From</label>
        <input type="date" name="from" class="form-control" value="<?= htmlspecialchars($from) ?>">
      </div>
      <div class="col-md-2">
        <label class="form-label">To</label>
        <input type="date" name="to" class="form-control" value="<?= htmlspecialchars($to) ?>">
      </div>
      <div class="col-md-3 d-flex gap-2">
        <button class="btn btn-primary" type="submit">Show</button>
        <?php if ($vendor): ?>
          <button class="btn btn-outline-dark" type="button" onclick="window.print()">Print</button>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div>

<?php if ($vendorId > 0 && !$vendor): ?>
  <div class="alert alert-warning">Vendor not found.</div>
<?php elseif ($vendor): ?>
<div class="card shadow-sm">
  <div class="card-header bg-white">
    <div class="print-only text-center mb-2"><strong><?= htmlspecialchars($bizName) ?></strong> — Vendor Ledger<?php $bi = business_info(); ?><div class="small">Contact: <?= htmlspecialchars($bi['phone']) ?> · <?= htmlspecialchars($bi['address']) ?></div></div>
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
      <div>
        <div class="fw-bold fs-5"><?= htmlspecialchars($vendor['VendorName']) ?></div>
        <div class="text-muted small">
          <?= htmlspecialchars(implode(' · ', array_filter([$vendor['Phone'] ?? '', $vendor['Address'] ?? '']))) ?>
        </div>
        <div class="small">Period: <?= date('d-M-Y', strtotime($from)) ?> to <?= date('d-M-Y', strtotime($to)) ?></div>
      </div>
      <div class="text-end">
        <div class="small text-muted">Currently payable</div>
        <div class="fs-4 fw-bold <?= $currentBalance > 0 ? 'text-danger' : 'text-success' ?>">Rs. <?= $rs($currentBalance) ?></div>
        <button type="button" class="btn btn-success btn-sm no-print" data-vendor-payment
                data-vendor-id="<?= (int)$vendor['VendorID'] ?>"
                data-vendor-name="<?= htmlspecialchars($vendor['VendorName']) ?>"
                data-balance="Rs. <?= $rs($currentBalance) ?>">Pay Vendor</button>
        <a class="btn btn-outline-primary btn-sm no-print" href="purchases.php?new=1&vendor_id=<?= (int)$vendor['VendorID'] ?>">New Purchase</a>
      </div>
    </div>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-sm table-striped mb-0 align-middle">
        <thead class="table-light">
          <tr>
            <th>Date</th>
            <th>Ref</th>
            <th>Description</th>
            <th class="text-end">Purchase (Cr)</th>
            <th class="text-end">Paid (Dr)</th>
            <th class="text-end">Payable</th>
            <?php if ($role === 'Admin'): ?><th class="no-print"></th><?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <tr class="fw-semibold">
            <td><?= date('d-M-Y', strtotime($from)) ?></td>
            <td></td>
            <td>Opening balance / brought forward</td>
            <td></td><td></td>
            <td class="text-end">Rs. <?= $rs($ledger['opening']) ?></td>
            <?php if ($role === 'Admin'): ?><td class="no-print"></td><?php endif; ?>
          </tr>
          <?php $sumC = 0; $sumD = 0; foreach ($ledger['rows'] as $r): $sumC += $r['Credit']; $sumD += $r['Debit']; ?>
            <tr>
              <td class="text-nowrap"><?= date('d-M-Y g:i A', strtotime($r['TxDate'])) ?></td>
              <td>
                <?php if ($r['TxType'] === 'Purchase'): ?>
                  <a href="purchases.php?view=<?= (int)$r['RefID'] ?>"><?= htmlspecialchars($r['Ref']) ?></a>
                <?php elseif ($r['LinkedPO']): ?>
                  <a href="purchases.php?view=<?= (int)$r['LinkedPO'] ?>"><?= htmlspecialchars($r['Ref']) ?></a>
                <?php else: ?>
                  <?= 'VP-' . (int)$r['RefID'] ?>
                <?php endif; ?>
              </td>
              <td><?= htmlspecialchars($r['Description']) ?></td>
              <td class="text-end"><?= (float)$r['Credit'] ? $rs($r['Credit']) : '' ?></td>
              <td class="text-end"><?= (float)$r['Debit'] ? $rs($r['Debit']) : '' ?></td>
              <td class="text-end">Rs. <?= $rs($r['Balance']) ?></td>
              <?php if ($role === 'Admin'): ?>
                <td class="no-print text-end">
                  <?php if ($r['TxType'] === 'Payment' && !$r['LinkedPO']): ?>
                    <form method="post" action="<?= htmlspecialchars($selfUrl) ?>" onsubmit="return confirm('Delete this payment entry?')">
                      <input type="hidden" name="form" value="delete_payment">
                      <input type="hidden" name="vendor_id" value="<?= (int)$vendor['VendorID'] ?>">
                      <input type="hidden" name="payment_id" value="<?= (int)$r['RefID'] ?>">
                      <button class="btn btn-sm btn-outline-danger py-0" type="submit" title="Delete payment">✕</button>
                    </form>
                  <?php endif; ?>
                </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
          <?php if (!$ledger['rows']): ?>
            <tr><td colspan="<?= $role === 'Admin' ? 7 : 6 ?>" class="text-center text-muted py-3">No transactions in this period.</td></tr>
          <?php endif; ?>
        </tbody>
        <tfoot class="table-light fw-bold">
          <tr>
            <td colspan="3" class="text-end">Totals / Closing payable</td>
            <td class="text-end"><?= $rs($sumC) ?></td>
            <td class="text-end"><?= $rs($sumD) ?></td>
            <td class="text-end">Rs. <?= $rs($ledger['closing']) ?></td>
            <?php if ($role === 'Admin'): ?><td class="no-print"></td><?php endif; ?>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>
</div>
<p class="small text-muted mt-2">Purchase = credit purchases (you owe more). Paid = payments made to the vendor. Positive payable = amount you owe the vendor. Cash purchases are paid on the spot and are not listed.</p>
<?php endif; ?>

</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php render_vendor_payment_modal($selfUrl); ?>
</body>
</html>
