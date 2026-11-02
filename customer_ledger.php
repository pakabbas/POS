<?php
ob_start(); // allows redirect-after-POST even though header.php prints the page header
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/customers_lib.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');
date_default_timezone_set('Asia/Karachi');
$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

function valid_date($s) {
    $d = DateTime::createFromFormat('Y-m-d', (string)$s);
    return $d && $d->format('Y-m-d') === $s;
}

$customerId = (int)($_REQUEST['customer_id'] ?? 0);
$today = date('Y-m-d');
$from = valid_date($_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-01');
$to = valid_date($_GET['to'] ?? '') ? $_GET['to'] : $today;
if ($from > $to) { [$from, $to] = [$to, $from]; }
$selfUrl = 'customer_ledger.php?customer_id=' . $customerId . '&from=' . urlencode($from) . '&to=' . urlencode($to);

$alert = null;
if (($_GET['msg'] ?? '') === 'paid') $alert = ['type' => 'success', 'msg' => 'Payment recorded.'];
if (($_GET['msg'] ?? '') === 'deleted') $alert = ['type' => 'success', 'msg' => 'Payment deleted.'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $which = $_POST['form'] ?? '';
    $cid = (int)($_POST['customer_id'] ?? 0);
    if ($which === 'receive_payment') {
        try {
            $err = record_customer_payment($conn, $cid, $_POST['amount'] ?? 0, (string)($_POST['date'] ?? ''), (string)($_POST['method'] ?? 'Cash'), (string)($_POST['note'] ?? ''), $userId);
        } catch (Throwable $e) {
            $err = 'Error: ' . $e->getMessage();
        }
        if ($err === null) {
            ob_end_clean();
            header('Location: customer_ledger.php?customer_id=' . $cid . '&from=' . urlencode($from) . '&to=' . urlencode($to) . '&msg=paid');
            exit();
        }
        $alert = ['type' => 'danger', 'msg' => $err];
    } elseif ($which === 'delete_payment' && $role === 'Admin') {
        // Only payments entered via "Receive Payment"; amounts received at a sale belong to the invoice.
        $pid = (int)($_POST['payment_id'] ?? 0);
        $stmt = $conn->prepare("DELETE FROM CustomerPayments WHERE CustomerPaymentID = ? AND CustomerID = ? AND InvoiceID IS NULL");
        $stmt->bind_param('ii', $pid, $cid);
        $stmt->execute();
        ob_end_clean();
        header('Location: customer_ledger.php?customer_id=' . $cid . '&from=' . urlencode($from) . '&to=' . urlencode($to) . '&msg=deleted');
        exit();
    }
}
ob_end_flush();

$customers = $conn->query("SELECT CustomerID, CustomerName, Phone, IsActive FROM Customers ORDER BY IsActive DESC, CustomerName")->fetch_all(MYSQLI_ASSOC);

$customer = null;
$ledger = null;
$currentBalance = 0;
if ($customerId > 0) {
    $stmt = $conn->prepare("SELECT * FROM Customers WHERE CustomerID = ?");
    $stmt->bind_param('i', $customerId);
    $stmt->execute();
    $customer = $stmt->get_result()->fetch_assoc();
    if ($customer) {
        $ledger = customer_ledger($conn, $customerId, $from, $to);
        $currentBalance = customer_balance($conn, $customerId);
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
  <h2 class="fw-bold mb-0">Customer Ledger</h2>
  <a class="btn btn-outline-secondary btn-sm" href="customers.php">← Customers</a>
</div>

<?php if ($alert): ?>
  <div class="alert alert-<?= $alert['type'] ?> no-print"><?= htmlspecialchars($alert['msg']) ?></div>
<?php endif; ?>

<div class="card shadow-sm mb-3 no-print">
  <div class="card-body">
    <form method="get" action="customer_ledger.php" class="row g-2 align-items-end">
      <div class="col-md-5">
        <label class="form-label">Customer</label>
        <select name="customer_id" class="form-select" required>
          <option value="">— Select customer —</option>
          <?php foreach ($customers as $c): ?>
            <option value="<?= (int)$c['CustomerID'] ?>" <?= (int)$c['CustomerID'] === $customerId ? 'selected' : '' ?>>
              <?= htmlspecialchars($c['CustomerName'] . ($c['Phone'] ? ' (' . $c['Phone'] . ')' : '') . ($c['IsActive'] ? '' : ' [inactive]')) ?>
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
        <?php if ($customer): ?>
          <button class="btn btn-outline-dark" type="button" onclick="window.print()">Print</button>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div>

<?php if ($customerId > 0 && !$customer): ?>
  <div class="alert alert-warning">Customer not found.</div>
<?php elseif ($customer): ?>
<div class="card shadow-sm">
  <div class="card-header bg-white">
    <div class="print-only text-center mb-2"><strong><?= htmlspecialchars($bizName) ?></strong> — Customer Ledger</div>
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
      <div>
        <div class="fw-bold fs-5"><?= htmlspecialchars($customer['CustomerName']) ?></div>
        <div class="text-muted small">
          <?= htmlspecialchars(implode(' · ', array_filter([$customer['Phone'] ?? '', $customer['Address'] ?? '', $customer['Area'] ?? '']))) ?>
        </div>
        <div class="small">Period: <?= date('d-M-Y', strtotime($from)) ?> to <?= date('d-M-Y', strtotime($to)) ?></div>
      </div>
      <div class="text-end">
        <div class="small text-muted">Current balance</div>
        <div class="fs-4 fw-bold <?= $currentBalance > 0 ? 'text-danger' : 'text-success' ?>">Rs. <?= $rs($currentBalance) ?></div>
        <button type="button" class="btn btn-success btn-sm no-print" data-receive-payment
                data-customer-id="<?= (int)$customer['CustomerID'] ?>"
                data-customer-name="<?= htmlspecialchars($customer['CustomerName']) ?>"
                data-balance="Rs. <?= $rs($currentBalance) ?>">Receive Payment</button>
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
            <th class="text-end">Debit (Sale)</th>
            <th class="text-end">Credit (Received)</th>
            <th class="text-end">Balance</th>
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
          <?php $sumD = 0; $sumC = 0; foreach ($ledger['rows'] as $r): $sumD += $r['Debit']; $sumC += $r['Credit']; ?>
            <tr>
              <td class="text-nowrap"><?= date('d-M-Y g:i A', strtotime($r['TxDate'])) ?></td>
              <td>
                <?php if ($r['TxType'] === 'Sale'): ?>
                  <a href="receipt.php?id=<?= (int)$r['RefID'] ?>" target="_blank"><?= htmlspecialchars($r['Ref']) ?></a>
                <?php else: ?>
                  <?= htmlspecialchars($r['Ref'] !== '' ? $r['Ref'] : 'PMT-' . $r['RefID']) ?>
                <?php endif; ?>
              </td>
              <td><?= htmlspecialchars($r['Description']) ?></td>
              <td class="text-end"><?= (float)$r['Debit'] ? $rs($r['Debit']) : '' ?></td>
              <td class="text-end"><?= (float)$r['Credit'] ? $rs($r['Credit']) : '' ?></td>
              <td class="text-end">Rs. <?= $rs($r['Balance']) ?></td>
              <?php if ($role === 'Admin'): ?>
                <td class="no-print text-end">
                  <?php if ($r['TxType'] === 'Payment' && $r['Ref'] === ''): ?>
                    <form method="post" action="<?= htmlspecialchars($selfUrl) ?>" onsubmit="return confirm('Delete this payment entry?')">
                      <input type="hidden" name="form" value="delete_payment">
                      <input type="hidden" name="customer_id" value="<?= (int)$customer['CustomerID'] ?>">
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
            <td colspan="3" class="text-end">Totals / Closing balance</td>
            <td class="text-end"><?= $rs($sumD) ?></td>
            <td class="text-end"><?= $rs($sumC) ?></td>
            <td class="text-end">Rs. <?= $rs($ledger['closing']) ?></td>
            <?php if ($role === 'Admin'): ?><td class="no-print"></td><?php endif; ?>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>
</div>
<p class="small text-muted mt-2">Debit = credit sales (customer owes more). Credit = payments received. Positive balance = amount the customer owes. Cash sales are settled at the counter and are not listed.</p>
<?php endif; ?>

</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php render_receive_payment_modal($selfUrl); ?>
</body>
</html>
