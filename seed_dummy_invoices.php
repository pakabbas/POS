<?php
// Seed 20 dummy invoices over the last 5 days using existing products
date_default_timezone_set('Asia/Karachi');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once 'includes/db.php';
$conn->set_charset('utf8mb4');

try {
    // Read products first (per request)
    $prodRes = $conn->query("SELECT ProductID, ProductName, Price FROM Products WHERE IsActive = 1");
    if (!$prodRes || $prodRes->num_rows === 0) {
        throw new Exception("No active products found. Cannot seed invoices.");
    }
    $products = $prodRes->fetch_all(MYSQLI_ASSOC);

    // Tax rate
    $taxRes = $conn->query("SELECT TaxRate FROM Taxes WHERE TaxID = 1");
    $taxRate = $taxRes && $taxRes->num_rows ? (float)$taxRes->fetch_assoc()['TaxRate'] : 0.0;

    $paymentMethods = ['Cash', 'Card', 'Digital Wallet'];
    $created = 0;

    for ($i = 0; $i < 20; $i++) {
        // Random date within last 5 days
        $daysAgo = rand(0, 4);
        $randomSeconds = rand(9 * 3600, 23 * 3600); // between 9 AM and 11:59 PM
        $invoiceTs = strtotime("-{$daysAgo} days") + $randomSeconds;
        $invoiceDate = date('Y-m-d H:i:s', $invoiceTs);

        // Build cart items
        $itemCount = rand(1, 4);
        $items = [];
        $subtotal = 0.0;
        for ($j = 0; $j < $itemCount; $j++) {
            $prod = $products[array_rand($products)];
            $qty = rand(1, 3);
            $unit = (float)$prod['Price'];
            $lineTotal = $unit * $qty;
            $subtotal += $lineTotal;
            $items[] = [
                'ProductID'  => (int)$prod['ProductID'],
                'Quantity'   => $qty,
                'UnitPrice'  => $unit,
                'TotalPrice' => $lineTotal,
            ];
        }

        $taxAmount = round($subtotal * ($taxRate / 100), 2);
        $discount = rand(0, 1) ? round($subtotal * (rand(0, 5) / 100), 2) : 0.0;
        $total = $subtotal + $taxAmount - $discount;
        $paymentMethod = $paymentMethods[array_rand($paymentMethods)];

        $invoiceNumber = 'INV-' . date('Ymd', $invoiceTs) . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));

        try {
            $conn->begin_transaction();

            // Insert invoice (UserID left NULL)
            $invStmt = $conn->prepare("
                INSERT INTO Invoices (InvoiceNumber, InvoiceDate, Subtotal, TaxAmount, Discount, TotalAmount, isShow)
                VALUES (?,?,?,?,?,?,1)
            ");
            $invStmt->bind_param(
                "ssdddd",
                $invoiceNumber,
                $invoiceDate,
                $subtotal,
                $taxAmount,
                $discount,
                $total
            );
            $invStmt->execute();
            $invoiceId = $conn->insert_id;
            $invStmt->close();

            // Insert items
            $itemStmt = $conn->prepare("
                INSERT INTO InvoiceItems (InvoiceID, ProductID, Quantity, UnitPrice, TotalPrice)
                VALUES (?,?,?,?,?)
            ");
            foreach ($items as $it) {
                $itemStmt->bind_param(
                    "iiidd",
                    $invoiceId,
                    $it['ProductID'],
                    $it['Quantity'],
                    $it['UnitPrice'],
                    $it['TotalPrice']
                );
                $itemStmt->execute();
            }
            $itemStmt->close();

            // Insert payment
            $payStmt = $conn->prepare("
                INSERT INTO Payments (InvoiceID, PaymentDate, AmountPaid, PaymentMethod)
                VALUES (?,?,?,?)
            ");
            $payStmt->bind_param(
                "isds",
                $invoiceId,
                $invoiceDate,
                $total,
                $paymentMethod
            );
            $payStmt->execute();
            $payStmt->close();

            $conn->commit();
            $created++;
        } catch (Throwable $t) {
            $conn->rollback();
            throw $t;
        }
    }

    echo "Seeded {$created} invoices over last 5 days using " . count($products) . " products. TaxRate={$taxRate}%\n";
} catch (Throwable $e) {
    echo "Seeding failed: " . $e->getMessage() . "\n";
}

if ($conn instanceof mysqli) {
    $conn->close();
}

