<?php
ob_start(); // allows redirect-after-POST even though header.php prints the page header
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/vendors_lib.php';
require_once __DIR__ . '/includes/inventory_lib.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');
date_default_timezone_set('Asia/Karachi');
$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

function redirect_to(string $url): void
{
    ob_end_clean();
    header('Location: ' . $url);
    exit();
}

$today = date('Y-m-d');
$alert = null;
$flash = [
    'saved' => ['success', 'Purchase saved and stock updated.'],
    'deleted' => ['success', 'Purchase deleted. Its stock and any amount paid with it were removed.'],
];
if (isset($flash[$_GET['msg'] ?? ''])) {
    $alert = ['type' => $flash[$_GET['msg']][0], 'msg' => $flash[$_GET['msg']][1]];
}

$post = null; // re-fill after validation error
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $which = $_POST['form'] ?? '';
    if ($which === 'purchase') {
        $post = $_POST;
        $vendorId = (int)($_POST['vendor_id'] ?? 0);
        $date = (string)($_POST['date'] ?? '');
        $payType = ($_POST['payment_type'] ?? 'Credit') === 'Cash' ? 'Cash' : 'Credit';
        $method = (string)($_POST['method'] ?? 'Cash');
        if (!in_array($method, vendor_payment_methods(), true)) $method = 'Cash';
        $reference = mb_substr(trim((string)($_POST['reference'] ?? '')), 0, 100);
        $notes = mb_substr(trim((string)($_POST['notes'] ?? '')), 0, 1000);
        $pids = $_POST['product_id'] ?? [];
        $qtys = $_POST['qty'] ?? [];
        $costs = $_POST['cost'] ?? [];
        $lines = [];
        $err = null;
        foreach ((array)$pids as $i => $pid) {
            $pid = (int)$pid;
            $qty = round((float)($qtys[$i] ?? 0), 4); // peti
            $cost = round((float)($costs[$i] ?? 0), 2);
            if ($pid <= 0 && $qty == 0) continue;
            if ($pid <= 0) { $err = 'Select a product on every line.'; break; }
            if ($qty <= 0) { $err = 'Peti must be greater than zero on every line.'; break; }
            if ($cost < 0) { $err = 'Cost per peti cannot be negative.'; break; }
            $lines[] = ['pid' => $pid, 'qty' => $qty, 'cost' => $cost, 'total' => round($qty * $cost, 2)];
        }
        $total = round(array_sum(array_column($lines, 'total')), 2);
        $paidNow = $payType === 'Cash' ? $total : round((float)($_POST['amount_paid'] ?? 0), 2);

        if ($err === null) {
            if ($vendorId <= 0) $err = 'Select a vendor.';
            elseif (!valid_ymd($date)) $err = 'Invalid date.';
            elseif ($date > $today) $err = 'Purchase date cannot be in the future.';
            elseif (!$lines) $err = 'Add at least one product line.';
            elseif ($paidNow < 0) $err = 'Amount paid cannot be negative.';
            elseif ($paidNow > $total) $err = 'Amount paid now cannot be more than the purchase total.';
        }
        if ($err === null) {
            $stmt = $conn->prepare("SELECT VendorID FROM Vendors WHERE VendorID = ?");
            $stmt->bind_param('i', $vendorId);
            $stmt->execute();
            if (!$stmt->get_result()->fetch_assoc()) $err = 'Vendor not found.';
        }
        if ($err === null) {
            $when = $date === $today ? date('Y-m-d H:i:s') : $date . ' ' . date('H:i:s');
            $conn->begin_transaction();
            try {
                $tmpNo = 'TMP-' . bin2hex(random_bytes(6));
                $notesVal = $notes !== '' ? $notes : null;
                $now = date('Y-m-d H:i:s');
                $stmt = $conn->prepare("INSERT INTO PurchaseOrders (PONumber, VendorID, OrderDate, Status, Subtotal, TotalAmount, PaymentType, AmountPaid, CreatedBy, ReceivedBy, ReceivedAt, Notes, CreatedAt)
                                        VALUES (?, ?, ?, 'Received', ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param('sisddsdiisss', $tmpNo, $vendorId, $when, $total, $total, $payType, $paidNow, $userId, $userId, $now, $notesVal, $now);
                $stmt->execute();
                $poId = (int)$conn->insert_id;
                $poNo = 'PUR-' . str_pad((string)$poId, 5, '0', STR_PAD_LEFT);
                $stmt = $conn->prepare("UPDATE PurchaseOrders SET PONumber = ? WHERE PurchaseOrderID = ?");
                $stmt->bind_param('si', $poNo, $poId);
                $stmt->execute();

                $stmtItem = $conn->prepare("INSERT INTO PurchaseOrderItems (PurchaseOrderID, ProductID, QuantityOrdered, UnitPrice, TotalPrice, QuantityReceived) VALUES (?, ?, ?, ?, ?, ?)");
                $stmtInv = $conn->prepare("INSERT INTO ProductInventory (ProductID, UnitOfMeasure, CostPrice, LastRestockedDate, LastRestockedBy) VALUES (?, 'Peti', ?, ?, ?)
                                           ON DUPLICATE KEY UPDATE CostPrice = VALUES(CostPrice), LastRestockedDate = VALUES(LastRestockedDate), LastRestockedBy = VALUES(LastRestockedBy)");
                foreach ($lines as $l) {
                    $stmtItem->bind_param('iidddd', $poId, $l['pid'], $l['qty'], $l['cost'], $l['total'], $l['qty']);
                    $stmtItem->execute();
                    $stmtInv->bind_param('idsi', $l['pid'], $l['cost'], $when, $userId);
                    $stmtInv->execute();
                }
                // Money paid at a credit purchase goes to the vendor ledger as a payment linked to this purchase.
                if ($payType === 'Credit' && $paidNow > 0) {
                    $e2 = record_vendor_payment($conn, $vendorId, $paidNow, $date, $method, $reference, '', $userId, $poId);
                    if ($e2 !== null) throw new RuntimeException($e2);
                }
                $conn->commit();
                redirect_to('purchases.php?msg=saved&view=' . $poId);
            } catch (Throwable $e) {
                $conn->rollback();
                $err = 'Error: ' . $e->getMessage();
            }
        }
        $alert = ['type' => 'danger', 'msg' => $err];
    } elseif ($which === 'delete_purchase' && $role === 'Admin') {
        $poId = (int)($_POST['purchase_id'] ?? 0);
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("DELETE FROM VendorPayments WHERE PurchaseOrderID = ?");
            $stmt->bind_param('i', $poId);
            $stmt->execute();
            $stmt = $conn->prepare("DELETE FROM PurchaseOrders WHERE PurchaseOrderID = ?"); // items cascade
            $stmt->bind_param('i', $poId);
            $stmt->execute();
            $conn->commit();
            redirect_to('purchases.php?msg=deleted');
        } catch (Throwable $e) {
            $conn->rollback();
            $alert = ['type' => 'danger', 'msg' => 'Error: ' . $e->getMessage()];
        }
    }
}
ob_end_flush();

$vendors = $conn->query("SELECT VendorID, VendorName, Phone FROM Vendors WHERE IsActive = 1 ORDER BY VendorName")->fetch_all(MYSQLI_ASSOC);
$products = $conn->query("SELECT p.ProductID, p.ProductName, COALESCE(pi.CostPrice, 0) AS LastCost
                          FROM Products p LEFT JOIN ProductInventory pi ON pi.ProductID = p.ProductID
                          WHERE p.IsActive = 1 ORDER BY p.SortOrder, p.ProductName")->fetch_all(MYSQLI_ASSOC);

$viewId = (int)($_GET['view'] ?? 0);
$showNew = isset($_GET['new']) || $post !== null;
$rs = fn($n) => number_format((float)$n, 2);

$from = valid_ymd($_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-01');
$to = valid_ymd($_GET['to'] ?? '') ? $_GET['to'] : $today;
if ($from > $to) { [$from, $to] = [$to, $from]; }
$fVendor = (int)($_GET['vendor_id'] ?? 0);
?>
<style>
@media print {
  nav.navbar, .no-print { display: none !important; }
  body.bg-app { background: #fff !important; }
  .card { border: none !important; box-shadow: none !important; }
}
</style>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2 no-print">
  <h2 class="fw-bold mb-0">Purchases (Stock In)</h2>
  <div class="d-flex gap-2">
    <?php if (!$showNew): ?><a class="btn btn-primary btn-sm" href="purchases.php?new=1">+ New Purchase</a><?php endif; ?>
    <a class="btn btn-outline-secondary btn-sm" href="inventory.php">Inventory</a>
    <a class="btn btn-outline-secondary btn-sm" href="vendors.php">Vendors</a>
  </div>
</div>

<?php if ($alert): ?>
  <div class="alert alert-<?= $alert['type'] ?> no-print"><?= htmlspecialchars($alert['msg']) ?></div>
<?php endif; ?>

<?php if ($viewId > 0):
    $stmt = $conn->prepare("SELECT po.*, v.VendorName, v.Phone, u.UserName FROM PurchaseOrders po
                            JOIN Vendors v ON v.VendorID = po.VendorID LEFT JOIN Users u ON u.UserID = po.CreatedBy
                            WHERE po.PurchaseOrderID = ?");
    $stmt->bind_param('i', $viewId);
    $stmt->execute();
    $po = $stmt->get_result()->fetch_assoc();
    if (!$po): ?>
  <div class="alert alert-warning">Purchase not found.</div>
<?php else:
    $stmt = $conn->prepare("SELECT poi.*, p.ProductName FROM PurchaseOrderItems poi JOIN Products p ON p.ProductID = poi.ProductID WHERE poi.PurchaseOrderID = ? ORDER BY poi.POItemID");
    $stmt->bind_param('i', $viewId);
    $stmt->execute();
    $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt = $conn->prepare("SELECT * FROM VendorPayments WHERE PurchaseOrderID = ?");
    $stmt->bind_param('i', $viewId);
    $stmt->execute();
    $atPay = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>
<div class="card shadow-sm mb-4">
  <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
    <div>
      <div class="fw-bold fs-5"><?= htmlspecialchars($po['PONumber']) ?> — <?= htmlspecialchars($po['VendorName']) ?></div>
      <div class="small text-muted"><?= date('d-M-Y g:i A', strtotime($po['OrderDate'])) ?><?= $po['UserName'] ? ' · entered by ' . htmlspecialchars($po['UserName']) : '' ?></div>
    </div>
    <div class="d-flex gap-2 no-print">
      <button class="btn btn-outline-dark btn-sm" onclick="window.print()">Print</button>
      <a class="btn btn-outline-secondary btn-sm" href="vendor_ledger.php?vendor_id=<?= (int)$po['VendorID'] ?>">Vendor Ledger</a>
      <?php if ($role === 'Admin'): ?>
        <form method="post" action="purchases.php" onsubmit="return confirm('Delete this purchase? Its peti will be removed from stock and any amount paid with it will be removed from the vendor ledger.')">
          <input type="hidden" name="form" value="delete_purchase">
          <input type="hidden" name="purchase_id" value="<?= (int)$po['PurchaseOrderID'] ?>">
          <button class="btn btn-outline-danger btn-sm" type="submit">Delete</button>
        </form>
      <?php endif; ?>
      <a class="btn-close ms-1" href="purchases.php" title="Close"></a>
    </div>
  </div>
  <div class="card-body p-0">
    <table class="table table-sm mb-0">
      <thead class="table-light"><tr><th>Product</th><th class="text-end">Peti</th><th class="text-end">Cost / peti</th><th class="text-end">Total</th></tr></thead>
      <tbody>
        <?php foreach ($items as $it): ?>
          <tr>
            <td><?= htmlspecialchars($it['ProductName']) ?></td>
            <td class="text-end"><?= qty_fmt($it['QuantityReceived']) ?></td>
            <td class="text-end"><?= $rs($it['UnitPrice']) ?></td>
            <td class="text-end"><?= $rs($it['TotalPrice']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot class="fw-bold">
        <tr><td colspan="3" class="text-end">Total</td><td class="text-end">Rs. <?= $rs($po['TotalAmount']) ?></td></tr>
        <tr><td colspan="3" class="text-end"><?= $po['PaymentType'] === 'Cash' ? 'Paid in cash' : 'Credit purchase — paid now' ?></td><td class="text-end">Rs. <?= $rs($po['AmountPaid']) ?></td></tr>
        <?php if ($po['PaymentType'] === 'Credit'): ?>
          <tr><td colspan="3" class="text-end">Added to payable</td><td class="text-end">Rs. <?= $rs($po['TotalAmount'] - $po['AmountPaid']) ?></td></tr>
        <?php endif; ?>
      </tfoot>
    </table>
    <?php if ($atPay || $po['Notes']): ?>
      <div class="p-3 small">
        <?php foreach ($atPay as $p): ?>
          <div>Payment: Rs. <?= $rs($p['AmountPaid']) ?> via <?= htmlspecialchars($p['PaymentMethod'] ?? 'Cash') ?><?= $p['ReferenceNumber'] ? ' (Ref ' . htmlspecialchars($p['ReferenceNumber']) . ')' : '' ?></div>
        <?php endforeach; ?>
        <?php if ($po['Notes']): ?><div>Notes: <?= htmlspecialchars($po['Notes']) ?></div><?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; endif; ?>

<?php if ($showNew):
    $pv = fn($k, $d = '') => htmlspecialchars((string)($post[$k] ?? $d));
    $selVendor = (int)($post['vendor_id'] ?? ($_GET['vendor_id'] ?? 0));
    $oldLines = [];
    if ($post) {
        foreach ((array)($post['product_id'] ?? []) as $i => $pid) {
            $oldLines[] = ['pid' => (int)$pid, 'qty' => $post['qty'][$i] ?? '', 'cost' => $post['cost'][$i] ?? ''];
        }
    }
    if (!$oldLines) $oldLines = [['pid' => 0, 'qty' => '', 'cost' => '']];
    $payTypeSel = ($post['payment_type'] ?? 'Credit') === 'Cash' ? 'Cash' : 'Credit';
?>
<div class="card shadow-sm mb-4 no-print">
  <div class="card-header bg-white fw-semibold d-flex justify-content-between">
    <span>New Purchase</span>
    <a class="btn-close" href="purchases.php" title="Close"></a>
  </div>
  <div class="card-body">
    <?php if (!$vendors): ?>
      <div class="alert alert-warning mb-0">Add a vendor first on the <a href="vendors.php">Vendors</a> page.</div>
    <?php else: ?>
    <form method="post" action="purchases.php" id="purchaseForm">
      <input type="hidden" name="form" value="purchase">
      <div class="row g-2 mb-3">
        <div class="col-md-5">
          <label class="form-label">Vendor *</label>
          <select name="vendor_id" class="form-select" required>
            <option value="">— Select vendor —</option>
            <?php foreach ($vendors as $vv): ?>
              <option value="<?= (int)$vv['VendorID'] ?>" <?= (int)$vv['VendorID'] === $selVendor ? 'selected' : '' ?>><?= htmlspecialchars($vv['VendorName'] . ($vv['Phone'] ? ' (' . $vv['Phone'] . ')' : '')) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Date *</label>
          <input type="date" name="date" class="form-control" value="<?= $pv('date', $today) ?>" max="<?= $today ?>" required>
        </div>
        <div class="col-md-4">
          <label class="form-label">Notes</label>
          <input type="text" name="notes" class="form-control" maxlength="1000" value="<?= $pv('notes') ?>" placeholder="Vehicle / bilty no. (optional)">
        </div>
      </div>

      <table class="table table-sm align-middle" id="lineTable">
        <thead class="table-light"><tr><th style="width:40%">Product</th><th>Peti</th><th>Cost / peti (Rs.)</th><th class="text-end">Line total</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($oldLines as $l): ?>
          <tr class="line">
            <td>
              <select name="product_id[]" class="form-select form-select-sm prod">
                <option value="">— Product —</option>
                <?php foreach ($products as $p): ?>
                  <option value="<?= (int)$p['ProductID'] ?>" data-cost="<?= htmlspecialchars($p['LastCost']) ?>" <?= (int)$p['ProductID'] === $l['pid'] ? 'selected' : '' ?>><?= htmlspecialchars($p['ProductName']) ?></option>
                <?php endforeach; ?>
              </select>
            </td>
            <td><input type="number" name="qty[]" class="form-control form-control-sm qty" min="0" step="0.01" value="<?= htmlspecialchars((string)$l['qty']) ?>"></td>
            <td><input type="number" name="cost[]" class="form-control form-control-sm cost" min="0" step="0.01" value="<?= htmlspecialchars((string)$l['cost']) ?>"></td>
            <td class="text-end lt">0.00</td>
            <td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger py-0 rm">✕</button></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <td colspan="3"><button type="button" class="btn btn-sm btn-outline-primary" id="addLine">+ Add line</button></td>
            <td class="text-end fw-bold">Rs. <span id="grand">0.00</span></td><td></td>
          </tr>
        </tfoot>
      </table>

      <div class="row g-2 align-items-end mb-3">
        <div class="col-md-3">
          <label class="form-label">Payment</label>
          <div class="btn-group w-100" role="group">
            <input type="radio" class="btn-check" name="payment_type" id="ptCredit" value="Credit" <?= $payTypeSel === 'Credit' ? 'checked' : '' ?>>
            <label class="btn btn-outline-primary" for="ptCredit">Credit</label>
            <input type="radio" class="btn-check" name="payment_type" id="ptCash" value="Cash" <?= $payTypeSel === 'Cash' ? 'checked' : '' ?>>
            <label class="btn btn-outline-success" for="ptCash">Cash (paid in full)</label>
          </div>
        </div>
        <div class="col-md-3" id="paidNowWrap">
          <label class="form-label">Amount paid now (Rs.)</label>
          <input type="number" name="amount_paid" class="form-control" min="0" step="0.01" value="<?= $pv('amount_paid', '0') ?>">
        </div>
        <div class="col-md-2" id="methodWrap">
          <label class="form-label">Method</label>
          <select name="method" class="form-select">
            <?php foreach (vendor_payment_methods() as $m): ?>
              <option value="<?= htmlspecialchars($m) ?>" <?= ($post['method'] ?? 'Cash') === $m ? 'selected' : '' ?>><?= htmlspecialchars($m) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2" id="refWrap">
          <label class="form-label">Reference</label>
          <input type="text" name="reference" class="form-control" maxlength="100" value="<?= $pv('reference') ?>" placeholder="Optional">
        </div>
        <div class="col-md-2 text-end">
          <button class="btn btn-success w-100" type="submit">Save Purchase</button>
        </div>
      </div>
      <div class="small text-muted" id="payHint"></div>
    </form>
    <?php endif; ?>
  </div>
</div>
<script>
(function () {
  const form = document.getElementById('purchaseForm');
  if (!form) return;
  const tbody = document.querySelector('#lineTable tbody');
  const fmt = n => (Math.round(n * 100) / 100).toLocaleString('en-PK', {minimumFractionDigits: 2, maximumFractionDigits: 2});
  function recalc() {
    let g = 0;
    tbody.querySelectorAll('tr.line').forEach(tr => {
      const q = parseFloat(tr.querySelector('.qty').value) || 0;
      const c = parseFloat(tr.querySelector('.cost').value) || 0;
      const t = Math.round(q * c * 100) / 100;
      tr.querySelector('.lt').textContent = fmt(t);
      g += t;
    });
    document.getElementById('grand').textContent = fmt(g);
    const cash = document.getElementById('ptCash').checked;
    document.getElementById('paidNowWrap').style.display = cash ? 'none' : '';
    document.getElementById('refWrap').style.display = cash ? 'none' : '';
    document.getElementById('methodWrap').style.display = cash ? 'none' : '';
    const paid = parseFloat(form.amount_paid.value) || 0;
    document.getElementById('payHint').textContent = cash
      ? 'Cash purchase: paid in full now, the vendor ledger is not affected.'
      : 'Credit purchase: Rs. ' + fmt(Math.max(g - paid, 0)) + ' will be added to the vendor payable.';
  }
  tbody.addEventListener('change', e => {
    if (e.target.classList.contains('prod')) {
      const tr = e.target.closest('tr');
      const opt = e.target.selectedOptions[0];
      const costIn = tr.querySelector('.cost');
      if (opt && opt.dataset.cost && parseFloat(opt.dataset.cost) > 0 && !costIn.value) costIn.value = opt.dataset.cost;
    }
    recalc();
  });
  tbody.addEventListener('input', recalc);
  tbody.addEventListener('click', e => {
    if (!e.target.classList.contains('rm')) return;
    const rows = tbody.querySelectorAll('tr.line');
    if (rows.length > 1) e.target.closest('tr').remove();
    else { rows[0].querySelectorAll('input').forEach(i => i.value = ''); rows[0].querySelector('select').value = ''; }
    recalc();
  });
  document.getElementById('addLine').addEventListener('click', () => {
    const tr = tbody.querySelector('tr.line').cloneNode(true);
    tr.querySelectorAll('input').forEach(i => i.value = '');
    tr.querySelector('select').value = '';
    tbody.appendChild(tr);
    recalc();
  });
  form.addEventListener('change', recalc);
  form.amount_paid.addEventListener('input', recalc);
  recalc();
})();
</script>
<?php endif; ?>

<?php
$sql = "SELECT po.PurchaseOrderID, po.PONumber, po.OrderDate, po.TotalAmount, po.PaymentType, po.AmountPaid, v.VendorName,
               (SELECT GROUP_CONCAT(CONCAT(p.ProductName, ' × ', TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM poi.QuantityReceived))) ORDER BY p.SortOrder SEPARATOR ', ')
                  FROM PurchaseOrderItems poi JOIN Products p ON p.ProductID = poi.ProductID WHERE poi.PurchaseOrderID = po.PurchaseOrderID) AS Items,
               (SELECT SUM(poi.QuantityReceived) FROM PurchaseOrderItems poi WHERE poi.PurchaseOrderID = po.PurchaseOrderID) AS Crates
        FROM PurchaseOrders po JOIN Vendors v ON v.VendorID = po.VendorID
        WHERE po.Status = 'Received' AND po.OrderDate BETWEEN ? AND ?";
$types = 'ss';
$fromDt = $from . ' 00:00:00';
$toDt = $to . ' 23:59:59';
$params = [$fromDt, $toDt];
if ($fVendor > 0) { $sql .= " AND po.VendorID = ?"; $types .= 'i'; $params[] = $fVendor; }
$sql .= " ORDER BY po.OrderDate DESC, po.PurchaseOrderID DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$list = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$allVendors = $conn->query("SELECT VendorID, VendorName FROM Vendors ORDER BY VendorName")->fetch_all(MYSQLI_ASSOC);
$sumT = array_sum(array_column($list, 'TotalAmount'));
$sumP = array_sum(array_column($list, 'AmountPaid'));
$sumC = array_sum(array_column($list, 'Crates'));
?>
<div class="card shadow-sm no-print">
  <div class="card-header bg-white">
    <form class="row g-2 align-items-end" method="get" action="purchases.php">
      <div class="col-md-4">
        <label class="form-label small mb-0">Vendor</label>
        <select name="vendor_id" class="form-select form-select-sm">
          <option value="0">All vendors</option>
          <?php foreach ($allVendors as $vv): ?>
            <option value="<?= (int)$vv['VendorID'] ?>" <?= (int)$vv['VendorID'] === $fVendor ? 'selected' : '' ?>><?= htmlspecialchars($vv['VendorName']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3"><label class="form-label small mb-0">From</label><input type="date" name="from" class="form-control form-control-sm" value="<?= htmlspecialchars($from) ?>"></div>
      <div class="col-md-3"><label class="form-label small mb-0">To</label><input type="date" name="to" class="form-control form-control-sm" value="<?= htmlspecialchars($to) ?>"></div>
      <div class="col-md-2"><button class="btn btn-sm btn-outline-primary w-100" type="submit">Filter</button></div>
    </form>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-striped table-sm mb-0 align-middle">
        <thead class="table-light">
          <tr><th>Date</th><th>No.</th><th>Vendor</th><th>Items (peti)</th><th class="text-end">Total</th><th>Type</th><th class="text-end">Paid now</th><th></th></tr>
        </thead>
        <tbody>
          <?php if (!$list): ?>
            <tr><td colspan="8" class="text-center text-muted py-4">No purchases in this period.</td></tr>
          <?php endif; ?>
          <?php foreach ($list as $r): ?>
            <tr>
              <td class="text-nowrap"><?= date('d-M-Y g:i A', strtotime($r['OrderDate'])) ?></td>
              <td><?= htmlspecialchars($r['PONumber']) ?></td>
              <td><?= htmlspecialchars($r['VendorName']) ?></td>
              <td class="small"><?= htmlspecialchars($r['Items'] ?? '') ?></td>
              <td class="text-end"><?= $rs($r['TotalAmount']) ?></td>
              <td><span class="badge bg-<?= $r['PaymentType'] === 'Cash' ? 'success' : 'primary' ?>"><?= htmlspecialchars($r['PaymentType']) ?></span></td>
              <td class="text-end"><?= $rs($r['AmountPaid']) ?></td>
              <td class="text-end"><a class="btn btn-sm btn-outline-secondary py-0" href="purchases.php?view=<?= (int)$r['PurchaseOrderID'] ?>&from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>">View</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot class="table-light fw-bold">
          <tr><td colspan="3" class="text-end">Totals (<?= count($list) ?>)</td><td><?= qty_fmt($sumC) ?> peti</td><td class="text-end"><?= $rs($sumT) ?></td><td></td><td class="text-end"><?= $rs($sumP) ?></td><td></td></tr>
        </tfoot>
      </table>
    </div>
  </div>
</div>

</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
