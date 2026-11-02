<?php
// Shared vendor balance / ledger / payout helpers.
//
// Ledger rules (single source of truth for every vendor page):
//   Credit (+) : vendor's OpeningBalance, and TotalAmount of every Credit purchase from the vendor
//   Debit  (-) : every row in VendorPayments for the vendor (amount paid at a credit purchase,
//                or a later payout)
//   Payable    = Opening + Credit purchases - Payments   (positive = we owe the vendor)
// Cash purchases are fully settled on the spot and do not touch the ledger.
// A purchase entry is a PurchaseOrders row with Status = 'Received'.

require_once __DIR__ . '/customers_lib.php'; // money_rs(), payment_methods()

function vendor_payment_methods(): array
{
    return ['Cash', 'Bank Transfer', 'Cheque', 'Easypaisa', 'JazzCash', 'Other'];
}

function valid_ymd($s): bool
{
    $d = DateTime::createFromFormat('Y-m-d', (string)$s);
    return $d && $d->format('Y-m-d') === $s;
}

function vendor_balances(mysqli $conn, ?int $vendorId = null): array
{
    $sql = "SELECT v.VendorID,
                   v.OpeningBalance + COALESCE(po.Total, 0) - COALESCE(pay.Total, 0) AS Balance
            FROM Vendors v
            LEFT JOIN (SELECT VendorID, SUM(TotalAmount) AS Total FROM PurchaseOrders
                       WHERE Status = 'Received' AND PaymentType = 'Credit' GROUP BY VendorID) po
                   ON po.VendorID = v.VendorID
            LEFT JOIN (SELECT VendorID, SUM(AmountPaid) AS Total FROM VendorPayments GROUP BY VendorID) pay
                   ON pay.VendorID = v.VendorID";
    if ($vendorId !== null) {
        $stmt = $conn->prepare($sql . " WHERE v.VendorID = ?");
        $stmt->bind_param('i', $vendorId);
    } else {
        $stmt = $conn->prepare($sql);
    }
    $stmt->execute();
    $out = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $out[(int)$r['VendorID']] = round((float)$r['Balance'], 2);
    }
    return $out;
}

function vendor_balance(mysqli $conn, int $vendorId): float
{
    $b = vendor_balances($conn, $vendorId);
    return $b[$vendorId] ?? 0.0;
}

/**
 * Ledger rows for one vendor between two dates (inclusive, Y-m-d).
 * Returns ['opening' => payable brought forward at $from, 'rows' => [...], 'closing' => float]
 * Each row: TxDate, TxType (Purchase|Payment), RefID, Ref, Description, Credit (purchase), Debit (paid), Balance
 */
function vendor_ledger(mysqli $conn, int $vendorId, string $from, string $to): array
{
    $fromDt = $from . ' 00:00:00';
    $toDt = $to . ' 23:59:59';

    $stmt = $conn->prepare("SELECT OpeningBalance FROM Vendors WHERE VendorID = ?");
    $stmt->bind_param('i', $vendorId);
    $stmt->execute();
    $opening = (float)($stmt->get_result()->fetch_assoc()['OpeningBalance'] ?? 0);

    $stmt = $conn->prepare("SELECT COALESCE(SUM(TotalAmount),0) t FROM PurchaseOrders WHERE VendorID = ? AND Status = 'Received' AND PaymentType = 'Credit' AND OrderDate < ?");
    $stmt->bind_param('is', $vendorId, $fromDt);
    $stmt->execute();
    $opening += (float)$stmt->get_result()->fetch_assoc()['t'];
    $stmt = $conn->prepare("SELECT COALESCE(SUM(AmountPaid),0) t FROM VendorPayments WHERE VendorID = ? AND PaymentDate < ?");
    $stmt->bind_param('is', $vendorId, $fromDt);
    $stmt->execute();
    $opening -= (float)$stmt->get_result()->fetch_assoc()['t'];

    $stmt = $conn->prepare("
        SELECT * FROM (
            SELECT po.OrderDate AS TxDate, 1 AS SortKey, 'Purchase' AS TxType, po.PurchaseOrderID AS RefID,
                   po.PONumber AS Ref,
                   CONCAT('Credit purchase', IF(po.Notes IS NULL OR po.Notes = '', '', CONCAT(' - ', po.Notes))) AS Description,
                   po.TotalAmount AS Credit, 0 AS Debit, NULL AS LinkedPO
            FROM PurchaseOrders po
            WHERE po.VendorID = ? AND po.Status = 'Received' AND po.PaymentType = 'Credit' AND po.OrderDate BETWEEN ? AND ?
            UNION ALL
            SELECT p.PaymentDate, 2, 'Payment', p.PaymentID,
                   COALESCE(po2.PONumber, ''),
                   CONCAT(IF(p.PurchaseOrderID IS NULL, 'Payment made', 'Paid at purchase'),
                          ' (', COALESCE(p.PaymentMethod, 'Cash'), ')',
                          IF(p.ReferenceNumber IS NULL OR p.ReferenceNumber = '', '', CONCAT(' Ref ', p.ReferenceNumber)),
                          IF(p.Notes IS NULL OR p.Notes = '', '', CONCAT(' - ', p.Notes))),
                   0, p.AmountPaid, p.PurchaseOrderID
            FROM VendorPayments p
            LEFT JOIN PurchaseOrders po2 ON po2.PurchaseOrderID = p.PurchaseOrderID
            WHERE p.VendorID = ? AND p.PaymentDate BETWEEN ? AND ?
        ) t
        ORDER BY TxDate, SortKey, RefID");
    $stmt->bind_param('ississ', $vendorId, $fromDt, $toDt, $vendorId, $fromDt, $toDt);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $bal = round($opening, 2);
    foreach ($rows as &$r) {
        $bal = round($bal + (float)$r['Credit'] - (float)$r['Debit'], 2);
        $r['Balance'] = $bal;
    }
    unset($r);

    return ['opening' => round($opening, 2), 'rows' => $rows, 'closing' => $bal];
}

/**
 * Record a payout to a vendor (reduces what we owe).
 * Returns null on success, or an error message.
 */
function record_vendor_payment(mysqli $conn, int $vendorId, $amount, string $date, string $method, string $reference, string $note, ?int $userId, ?int $purchaseId = null): ?string
{
    $amount = round((float)$amount, 2);
    if ($vendorId <= 0) {
        return 'Select a vendor.';
    }
    if ($amount <= 0) {
        return 'Amount must be greater than zero.';
    }
    if (!valid_ymd($date)) {
        return 'Invalid date.';
    }
    if ($date > date('Y-m-d')) {
        return 'Payment date cannot be in the future.';
    }
    if (!in_array($method, vendor_payment_methods(), true)) {
        $method = 'Cash';
    }
    $reference = mb_substr(trim($reference), 0, 100);
    $note = mb_substr(trim($note), 0, 255);
    $stmt = $conn->prepare("SELECT VendorID FROM Vendors WHERE VendorID = ?");
    $stmt->bind_param('i', $vendorId);
    $stmt->execute();
    if (!$stmt->get_result()->fetch_assoc()) {
        return 'Vendor not found.';
    }
    $when = $date . ' ' . date('H:i:s');
    $refVal = $reference !== '' ? $reference : null;
    $noteVal = $note !== '' ? $note : null;
    $stmt = $conn->prepare("INSERT INTO VendorPayments (VendorID, PurchaseOrderID, PaymentDate, AmountPaid, PaymentMethod, ReferenceNumber, Notes, CreatedBy) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param('iisdsssi', $vendorId, $purchaseId, $when, $amount, $method, $refVal, $noteVal, $userId);
    $stmt->execute();
    return null;
}

/** Shared "Pay Vendor" Bootstrap modal. Posts to $action with form=vendor_payment, vendor_id/amount/date/method/reference/note. */
function render_vendor_payment_modal(string $action): void
{
    $today = date('Y-m-d');
    ?>
<div class="modal fade" id="vendorPaymentModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" action="<?= htmlspecialchars($action) ?>" class="modal-content">
      <input type="hidden" name="form" value="vendor_payment">
      <input type="hidden" name="vendor_id" id="vpVendorId" value="">
      <div class="modal-header">
        <h5 class="modal-title">Pay Vendor</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="mb-2"><span class="text-muted">Vendor:</span> <strong id="vpVendorName"></strong></div>
        <div class="mb-3"><span class="text-muted">Currently payable:</span> <strong id="vpBalance"></strong></div>
        <div class="mb-3">
          <label class="form-label">Amount (Rs.)</label>
          <input type="number" name="amount" min="0.01" step="0.01" class="form-control" required>
        </div>
        <div class="row g-2 mb-3">
          <div class="col">
            <label class="form-label">Date</label>
            <input type="date" name="date" class="form-control" value="<?= $today ?>" max="<?= $today ?>" required>
          </div>
          <div class="col">
            <label class="form-label">Method</label>
            <select name="method" class="form-select">
              <?php foreach (vendor_payment_methods() as $m): ?>
                <option value="<?= htmlspecialchars($m) ?>"><?= htmlspecialchars($m) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="mb-3">
          <label class="form-label">Reference</label>
          <input type="text" name="reference" maxlength="100" class="form-control" placeholder="Cheque no. / transaction ID (optional)">
        </div>
        <div class="mb-1">
          <label class="form-label">Note</label>
          <input type="text" name="note" maxlength="255" class="form-control" placeholder="Optional">
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-success">Save Payment</button>
      </div>
    </form>
  </div>
</div>
<script>
document.addEventListener('click', function (e) {
  const btn = e.target.closest('[data-vendor-payment]');
  if (!btn) return;
  document.getElementById('vpVendorId').value = btn.dataset.vendorId;
  document.getElementById('vpVendorName').textContent = btn.dataset.vendorName;
  document.getElementById('vpBalance').textContent = btn.dataset.balance;
  const form = document.querySelector('#vendorPaymentModal form');
  form.amount.value = '';
  form.reference.value = '';
  form.note.value = '';
  bootstrap.Modal.getOrCreateInstance(document.getElementById('vendorPaymentModal')).show();
  setTimeout(() => form.amount.focus(), 300);
});
</script>
    <?php
}
