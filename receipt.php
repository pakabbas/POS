<?php
session_start();
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}
session_write_close();
date_default_timezone_set('Asia/Karachi');
require __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/customers_lib.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

// Load restaurant info if available
$infoFile = __DIR__ . '/restaurantInfo.txt';
$bizName = 'Restaurant POS';
$tagline = '';
$phone = '';
$address = '';
if (file_exists($infoFile)) {
    $infoLines = array_values(array_filter(array_map('trim', file($infoFile)), 'strlen'));
    $bizName = $infoLines[0] ?? $bizName;
    $tagline = $infoLines[1] ?? $tagline;
    $phone = $infoLines[2] ?? $phone;
    $address = $infoLines[3] ?? $address;
}

$invoiceId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($invoiceId <= 0) {
    http_response_code(400);
    echo "Invalid invoice id";
    exit;
}

$invStmt = $conn->prepare("SELECT i.InvoiceID, i.InvoiceNumber, i.InvoiceDate, i.Subtotal, i.TaxAmount, i.Discount, i.TotalAmount,
       i.PaymentType, i.AmountPaid, i.CustomerID, i.CustomerName AS WalkinName, c.CustomerName, c.Phone AS CustomerPhone
FROM Invoices i LEFT JOIN Customers c ON c.CustomerID = i.CustomerID
WHERE i.InvoiceID = ?");
$invStmt->bind_param('i', $invoiceId);
$invStmt->execute();
$invoice = $invStmt->get_result()->fetch_assoc();
if (!$invoice) {
    http_response_code(404);
    echo "Invoice not found";
    exit;
}

$itemsStmt = $conn->prepare("
    SELECT ii.Quantity, ii.UnitPrice, ii.TotalPrice, p.ProductName
    FROM InvoiceItems ii
    LEFT JOIN Products p ON p.ProductID = ii.ProductID
    WHERE ii.InvoiceID = ?
");
$itemsStmt->bind_param('i', $invoiceId);
$itemsStmt->execute();
$items = $itemsStmt->get_result()->fetch_all(MYSQLI_ASSOC);

$payStmt = $conn->prepare("SELECT AmountPaid, PaymentMethod, PaymentDate FROM Payments WHERE InvoiceID = ? ORDER BY PaymentID DESC LIMIT 1");
$payStmt->bind_param('i', $invoiceId);
$payStmt->execute();
$payment = $payStmt->get_result()->fetch_assoc();

$isCredit = ($invoice['PaymentType'] ?? 'Cash') === 'Credit';
$custName = $invoice['CustomerName'] ?: ($invoice['WalkinName'] ?? '');
$custBalance = null;
if (!empty($invoice['CustomerID'])) {
    $custBalance = customer_balance_after_invoice($conn, (int)$invoice['CustomerID'], (int)$invoice['InvoiceID']);
}

function moneyPk($val) {
    // Show whole amount without currency label or decimals
    return number_format((float)$val, 0);
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Invoice <?= htmlspecialchars($invoice['InvoiceNumber']) ?></title>
<style>
@page { size: 33mm auto; margin: 0; }
body {
  margin: 0;
  padding: 6px;
  font-family: 'SFMono-Regular', 'Consolas', 'Roboto Mono', monospace;
  width: 33mm;
  color: #111;
}
.center { text-align: center; }
.bold { font-weight: 700; }
.title { font-size: 10px; font-weight: 700; letter-spacing: 0.3px; }
.muted { color: #444; }
.line { border-top: 1px dashed #000; margin: 6px 0; }
.row { display: flex; justify-content: space-between; font-size: 7px; line-height: 1.4; }
.small { font-size: 7px; }
.header { margin-bottom: 6px; line-height: 1.3; }
.items table { width: 100%; border-collapse: collapse; font-size: 7px; }
.items td { padding: 2px 0; vertical-align: top; }
.items .price { text-align: right; }
.totals .row { margin: 2px 0; }
.totals .row.bold { font-size: 10px; }
.thanks { margin-top: 6px; }
</style>
</head>
<body>
  <div class="center header">
    <?php if (file_exists(__DIR__ . '/Logo.jpg')): ?>
      <img src="Logo.jpg" alt="Logo" style="max-width:120px; height:auto; margin-bottom:4px;">
    <?php endif; ?>
    <?php if ($phone): ?><div class="small muted"><?= htmlspecialchars($phone) ?></div><?php endif; ?>
    <div class="small muted">Inv # <?= htmlspecialchars($invoice['InvoiceNumber']) ?></div>
    <div class="small"><?= date('d-M-Y g:i A', strtotime($invoice['InvoiceDate'])) ?></div>
    <div class="small bold"><?= $isCredit ? 'CREDIT SALE' : 'CASH SALE' ?></div>
    <?php if ($custName !== ''): ?>
      <div class="small">Customer: <?= htmlspecialchars($custName) ?></div>
      <?php if (!empty($invoice['CustomerPhone'])): ?><div class="small muted"><?= htmlspecialchars($invoice['CustomerPhone']) ?></div><?php endif; ?>
    <?php endif; ?>
  </div>

  <div class="line"></div>
  <div class="items">
    <table>
      <?php foreach ($items as $it): ?>
        <tr>
          <td class="bold"><?= htmlspecialchars($it['ProductName'] ?? 'Item') ?></td>
          <td class="price"><?= moneyPk($it['TotalPrice']) ?></td>
        </tr>
        <?php if ((int)$it['Quantity'] > 1): ?>
        <tr>
          <td class="small muted" colspan="2"><?= $it['Quantity'] ?> x <?= moneyPk($it['UnitPrice']) ?></td>
        </tr>
        <?php endif; ?>
      <?php endforeach; ?>
    </table>
  </div>

  <div class="line"></div>
  <div class="totals">
    <div class="row"><span>Subtotal</span><span><?= moneyPk($invoice['Subtotal']) ?></span></div>
    <!-- <div class="row"><span>Tax</span><span><?= moneyPk($invoice['TaxAmount']) ?></span></div> -->
    <div class="row"><span>Discount</span><span><?= moneyPk($invoice['Discount']) ?></span></div>
    <div class="row bold"><span>Total</span><span><?= moneyPk($invoice['TotalAmount']) ?></span></div>
    <?php if ($isCredit): ?>
      <?php $paidNow = (float)($invoice['AmountPaid'] ?? 0); ?>
      <div class="row"><span>Received</span><span><?= moneyPk($paidNow) ?></span></div>
      <div class="row"><span>Bill Due</span><span><?= moneyPk((float)$invoice['TotalAmount'] - $paidNow) ?></span></div>
      <?php if ($custBalance !== null): ?>
        <div class="row bold"><span>Balance</span><span><?= moneyPk($custBalance) ?></span></div>
      <?php endif; ?>
    <?php endif; ?>
    <?php if ($payment): ?>
      <!-- <div class="row"><span>Paid</span><span><?= moneyPk($payment['AmountPaid']) ?></span></div> -->
      <!-- <div class="row"><span>Method</span><span><?= htmlspecialchars($payment['PaymentMethod']) ?></span></div> -->
    <?php endif; ?>
  </div>

  <div class="line"></div>
  <div class="center small thanks">Thank you!</div>
  <?php if ($address): ?>
    <div class="center small muted" style="margin-top:4px;"><?= htmlspecialchars($address) ?></div>
  <?php endif; ?>

  <script>window.print();</script>
</body>
</html>

