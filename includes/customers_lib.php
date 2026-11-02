<?php
// Shared customer balance / ledger helpers.
//
// Ledger rules (single source of truth for every page):
//   Debit  (+) : customer's OpeningBalance, and TotalAmount of every Credit invoice for the customer
//   Credit (-) : every row in CustomerPayments for the customer (amount received at a credit sale,
//                or a later "Receive Payment")
//   Balance    = Opening + Credit invoices - Payments   (positive = customer owes us)
// Cash invoices are fully settled at the till and do not touch the ledger.

function customer_balances(mysqli $conn, ?int $customerId = null): array
{
    $sql = "SELECT c.CustomerID,
                   c.OpeningBalance,
                   COALESCE(inv.Total, 0) AS CreditSales,
                   COALESCE(pay.Total, 0) AS Payments,
                   c.OpeningBalance + COALESCE(inv.Total, 0) - COALESCE(pay.Total, 0) AS Balance
            FROM Customers c
            LEFT JOIN (SELECT CustomerID, SUM(TotalAmount) AS Total FROM Invoices
                       WHERE PaymentType = 'Credit' AND CustomerID IS NOT NULL GROUP BY CustomerID) inv
                   ON inv.CustomerID = c.CustomerID
            LEFT JOIN (SELECT CustomerID, SUM(Amount) AS Total FROM CustomerPayments GROUP BY CustomerID) pay
                   ON pay.CustomerID = c.CustomerID";
    if ($customerId !== null) {
        $stmt = $conn->prepare($sql . " WHERE c.CustomerID = ?");
        $stmt->bind_param('i', $customerId);
    } else {
        $stmt = $conn->prepare($sql);
    }
    $stmt->execute();
    $out = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $out[(int)$r['CustomerID']] = round((float)$r['Balance'], 2);
    }
    return $out;
}

function customer_balance(mysqli $conn, int $customerId): float
{
    $b = customer_balances($conn, $customerId);
    return $b[$customerId] ?? 0.0;
}

/**
 * Ledger rows for one customer between two dates (inclusive, Y-m-d).
 * Returns ['opening' => balance brought forward at $from, 'rows' => [...], 'closing' => float]
 */
function customer_ledger(mysqli $conn, int $customerId, string $from, string $to): array
{
    $fromDt = $from . ' 00:00:00';
    $toDt = $to . ' 23:59:59';

    $stmt = $conn->prepare("SELECT OpeningBalance FROM Customers WHERE CustomerID = ?");
    $stmt->bind_param('i', $customerId);
    $stmt->execute();
    $opening = (float)($stmt->get_result()->fetch_assoc()['OpeningBalance'] ?? 0);

    // Brought forward: everything before $from
    $stmt = $conn->prepare("SELECT COALESCE(SUM(TotalAmount),0) t FROM Invoices WHERE CustomerID = ? AND PaymentType = 'Credit' AND InvoiceDate < ?");
    $stmt->bind_param('is', $customerId, $fromDt);
    $stmt->execute();
    $opening += (float)$stmt->get_result()->fetch_assoc()['t'];
    $stmt = $conn->prepare("SELECT COALESCE(SUM(Amount),0) t FROM CustomerPayments WHERE CustomerID = ? AND PaymentDate < ?");
    $stmt->bind_param('is', $customerId, $fromDt);
    $stmt->execute();
    $opening -= (float)$stmt->get_result()->fetch_assoc()['t'];

    $stmt = $conn->prepare("
        SELECT * FROM (
            SELECT i.InvoiceDate AS TxDate, 1 AS SortKey, 'Sale' AS TxType, i.InvoiceID AS RefID,
                   i.InvoiceNumber AS Ref, 'Credit sale' AS Description, i.TotalAmount AS Debit, 0 AS Credit
            FROM Invoices i
            WHERE i.CustomerID = ? AND i.PaymentType = 'Credit' AND i.InvoiceDate BETWEEN ? AND ?
            UNION ALL
            SELECT p.PaymentDate, 2, 'Payment', p.CustomerPaymentID,
                   COALESCE(i2.InvoiceNumber, ''),
                   CONCAT(IF(p.InvoiceID IS NULL, 'Payment received', 'Received at sale'),
                          ' (', COALESCE(p.Method, 'Cash'), ')',
                          IF(p.Note IS NULL OR p.Note = '', '', CONCAT(' - ', p.Note))),
                   0, p.Amount
            FROM CustomerPayments p
            LEFT JOIN Invoices i2 ON i2.InvoiceID = p.InvoiceID
            WHERE p.CustomerID = ? AND p.PaymentDate BETWEEN ? AND ?
        ) t
        ORDER BY TxDate, SortKey, RefID");
    $stmt->bind_param('ississ', $customerId, $fromDt, $toDt, $customerId, $fromDt, $toDt);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $bal = round($opening, 2);
    foreach ($rows as &$r) {
        $bal = round($bal + (float)$r['Debit'] - (float)$r['Credit'], 2);
        $r['Balance'] = $bal;
    }
    unset($r);

    return ['opening' => round($opening, 2), 'rows' => $rows, 'closing' => $bal];
}

function money_rs($v): string
{
    return 'Rs. ' . number_format((float)$v, 2);
}

/**
 * Record money received from a customer (reduces their balance).
 * Returns null on success, or an error message.
 */
function record_customer_payment(mysqli $conn, int $customerId, $amount, string $date, string $method, string $note, ?int $userId): ?string
{
    $amount = round((float)$amount, 2);
    if ($customerId <= 0) {
        return 'Select a customer.';
    }
    if ($amount <= 0) {
        return 'Amount must be greater than zero.';
    }
    $d = DateTime::createFromFormat('Y-m-d', $date);
    if (!$d || $d->format('Y-m-d') !== $date) {
        return 'Invalid date.';
    }
    if ($date > date('Y-m-d')) {
        return 'Payment date cannot be in the future.';
    }
    if (!in_array($method, payment_methods(), true)) {
        $method = 'Cash';
    }
    $note = mb_substr(trim($note), 0, 255);
    $stmt = $conn->prepare("SELECT CustomerID FROM Customers WHERE CustomerID = ?");
    $stmt->bind_param('i', $customerId);
    $stmt->execute();
    if (!$stmt->get_result()->fetch_assoc()) {
        return 'Customer not found.';
    }
    $when = $date . ' ' . date('H:i:s');
    $noteVal = $note !== '' ? $note : null;
    $stmt = $conn->prepare("INSERT INTO CustomerPayments (CustomerID, InvoiceID, Amount, PaymentDate, Method, Note, UserID) VALUES (?, NULL, ?, ?, ?, ?, ?)");
    $stmt->bind_param('idsssi', $customerId, $amount, $when, $method, $noteVal, $userId);
    $stmt->execute();
    return null;
}

function payment_methods(): array
{
    return ['Cash', 'Bank Transfer', 'Cheque', 'Easypaisa', 'JazzCash', 'Other'];
}

/** Shared "Receive Payment" Bootstrap modal. Posts to $action with customer_id/amount/date/method/note. */
function render_receive_payment_modal(string $action): void
{
    $today = date('Y-m-d');
    ?>
<div class="modal fade" id="receivePaymentModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" action="<?= htmlspecialchars($action) ?>" class="modal-content">
      <input type="hidden" name="form" value="receive_payment">
      <input type="hidden" name="customer_id" id="rpCustomerId" value="">
      <div class="modal-header">
        <h5 class="modal-title">Receive Payment</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="mb-2"><span class="text-muted">Customer:</span> <strong id="rpCustomerName"></strong></div>
        <div class="mb-3"><span class="text-muted">Current balance:</span> <strong id="rpBalance"></strong></div>
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
              <?php foreach (payment_methods() as $m): ?>
                <option value="<?= htmlspecialchars($m) ?>"><?= htmlspecialchars($m) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
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
  const btn = e.target.closest('[data-receive-payment]');
  if (!btn) return;
  document.getElementById('rpCustomerId').value = btn.dataset.customerId;
  document.getElementById('rpCustomerName').textContent = btn.dataset.customerName;
  document.getElementById('rpBalance').textContent = btn.dataset.balance;
  const form = document.querySelector('#receivePaymentModal form');
  form.amount.value = '';
  form.note.value = '';
  bootstrap.Modal.getOrCreateInstance(document.getElementById('receivePaymentModal')).show();
  setTimeout(() => form.amount.focus(), 300);
});
</script>
    <?php
}

/** Customer balance right after a given invoice (used on receipts so reprints show the balance at that time). */
function customer_balance_after_invoice(mysqli $conn, int $customerId, int $invoiceId): float
{
    $stmt = $conn->prepare("SELECT InvoiceDate FROM Invoices WHERE InvoiceID = ?");
    $stmt->bind_param('i', $invoiceId);
    $stmt->execute();
    $d = $stmt->get_result()->fetch_assoc()['InvoiceDate'] ?? null;
    if ($d === null) {
        return customer_balance($conn, $customerId);
    }
    $stmt = $conn->prepare("SELECT
        (SELECT OpeningBalance FROM Customers WHERE CustomerID = ?)
      + (SELECT COALESCE(SUM(TotalAmount),0) FROM Invoices WHERE CustomerID = ? AND PaymentType = 'Credit'
           AND (InvoiceDate < ? OR (InvoiceDate = ? AND InvoiceID <= ?)))
      - (SELECT COALESCE(SUM(Amount),0) FROM CustomerPayments WHERE CustomerID = ?
           AND (PaymentDate <= ? OR InvoiceID = ?)) AS Bal");
    $stmt->bind_param('iissiisi', $customerId, $customerId, $d, $d, $invoiceId, $customerId, $d, $invoiceId);
    $stmt->execute();
    return round((float)$stmt->get_result()->fetch_assoc()['Bal'], 2);
}
