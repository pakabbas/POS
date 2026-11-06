<?php
error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', '0');
date_default_timezone_set('Asia/Karachi');
header('Content-Type: application/json');

// Same login requirement as pos.php / the rest of the app
session_start();
if (!isset($_SESSION['username'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit();
}
session_write_close(); // nothing below writes to the session; release the lock

// Correct path to DB connection
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/customers_lib.php';
require_once __DIR__ . '/includes/inventory_lib.php';
$conn->set_charset('utf8mb4');
// Ensure connection closes per request
register_shutdown_function(function() use ($conn) {
    if ($conn instanceof mysqli) {
        try { $conn->close(); } catch (Throwable $e) {}
    }
});

// Get request method
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    handleGet($conn);
} elseif ($method === 'POST') {
    handlePost($conn);
}

function handleGet($conn) {
    $action = isset($_GET['action']) ? $_GET['action'] : '';
    
    if ($action === 'getProducts') {
        getProducts($conn);
    } elseif ($action === 'searchCustomers') {
        searchCustomers($conn);
    } elseif ($action === 'getCustomer') {
        getCustomer($conn);
    } elseif ($action === 'getInvoice') {
        getInvoice($conn);
    } elseif ($action === 'findInvoice') {
        findInvoice($conn);
    }
}

function handlePost($conn) {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = isset($input['action']) ? $input['action'] : '';
    
    if ($action === 'createInvoice') {
        createInvoice($conn, $input);
    } elseif ($action === 'createCustomer') {
        createCustomer($conn, $input);
    } elseif ($action === 'updateInvoice') {
        updateInvoice($conn, $input);
    }
}

function getProducts($conn) {
    $categoryId = isset($_GET['category']) ? $_GET['category'] : 'all';
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    
    $query = "SELECT p.ProductID, p.ProductName, p.Price, p.CategoryID, c.CategoryName 
              FROM Products p 
              LEFT JOIN Categories c ON p.CategoryID = c.CategoryID 
              WHERE p.IsActive = 1";
    $types = '';
    $params = [];
    if ($categoryId !== 'all' && $categoryId !== '') {
        $query .= " AND p.CategoryID = ?";
        $types .= 'i';
        $params[] = (int)$categoryId;
    }
    if (!empty($search)) {
        $query .= " AND p.ProductName LIKE ?";
        $types .= 's';
        $params[] = '%' . $search . '%';
    }
    $query .= " ORDER BY p.SortOrder ASC, p.ProductName ASC";
    
    $stmt = $conn->prepare($query);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    
    $products = [];
    // Peti in stock (null when inventory tables are not set up yet); display only, never blocks a sale
    $stock = function_exists('stock_map') ? stock_map($conn) : [];
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $pid = (int)$row['ProductID'];
            $row['Stock'] = array_key_exists($pid, $stock) ? $stock[$pid] : null;
            $row['StockText'] = ($row['Stock'] !== null && function_exists('peti_fmt')) ? peti_fmt($row['Stock']) : null;
            $products[] = $row;
        }
    }
    
    echo json_encode([
        'success' => true,
        'products' => $products
    ]);
}

function customerPayload($conn, array $c) {
    $id = (int)$c['CustomerID'];
    return [
        'CustomerID' => $id,
        'CustomerName' => $c['CustomerName'],
        'Phone' => $c['Phone'] ?? '',
        'Address' => $c['Address'] ?? '',
        'Area' => $c['Area'] ?? '',
        'CreditLimit' => $c['CreditLimit'] !== null ? (float)$c['CreditLimit'] : null,
        'Balance' => customer_balance($conn, $id),
    ];
}

function searchCustomers($conn) {
    $q = trim($_GET['q'] ?? '');
    $sql = "SELECT CustomerID, CustomerName, Phone, Address, Area, CreditLimit FROM Customers WHERE IsActive = 1";
    if ($q !== '') {
        $sql .= " AND (CustomerName LIKE ? OR Phone LIKE ?)";
    }
    $sql .= " ORDER BY CustomerName LIMIT 20";
    $stmt = $conn->prepare($sql);
    if ($q !== '') {
        $like = '%' . $q . '%';
        $stmt->bind_param('ss', $like, $like);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $balances = customer_balances($conn);
    $out = [];
    foreach ($rows as $c) {
        $id = (int)$c['CustomerID'];
        $out[] = [
            'CustomerID' => $id,
            'CustomerName' => $c['CustomerName'],
            'Phone' => $c['Phone'] ?? '',
            'Area' => $c['Area'] ?? '',
            'CreditLimit' => $c['CreditLimit'] !== null ? (float)$c['CreditLimit'] : null,
            'Balance' => $balances[$id] ?? 0,
        ];
    }
    echo json_encode(['success' => true, 'customers' => $out]);
}

function getCustomer($conn) {
    $id = (int)($_GET['id'] ?? 0);
    $stmt = $conn->prepare("SELECT CustomerID, CustomerName, Phone, Address, Area, CreditLimit FROM Customers WHERE CustomerID = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $c = $stmt->get_result()->fetch_assoc();
    if (!$c) {
        echo json_encode(['success' => false, 'message' => 'Customer not found']);
        return;
    }
    echo json_encode(['success' => true, 'customer' => customerPayload($conn, $c)]);
}

function createCustomer($conn, $data) {
    $name = trim((string)($data['name'] ?? ''));
    $phone = trim((string)($data['phone'] ?? ''));
    $address = trim((string)($data['address'] ?? ''));
    $opening = round((float)($data['openingBalance'] ?? 0), 2);

    if ($name === '') {
        echo json_encode(['success' => false, 'message' => 'Customer name is required']);
        return;
    }
    if (mb_strlen($name) > 150 || mb_strlen($phone) > 30 || mb_strlen($address) > 255) {
        echo json_encode(['success' => false, 'message' => 'Name, phone or address is too long']);
        return;
    }
    if ($phone !== '') {
        $stmt = $conn->prepare("SELECT CustomerID, CustomerName FROM Customers WHERE Phone = ? AND IsActive = 1 LIMIT 1");
        $stmt->bind_param('s', $phone);
        $stmt->execute();
        if ($dup = $stmt->get_result()->fetch_assoc()) {
            echo json_encode(['success' => false, 'message' => 'Phone already belongs to customer: ' . $dup['CustomerName']]);
            return;
        }
    }
    $phoneVal = $phone !== '' ? $phone : null;
    $addrVal = $address !== '' ? $address : null;
    $stmt = $conn->prepare("INSERT INTO Customers (CustomerName, Phone, Address, OpeningBalance) VALUES (?, ?, ?, ?)");
    $stmt->bind_param('sssd', $name, $phoneVal, $addrVal, $opening);
    if (!$stmt->execute()) {
        echo json_encode(['success' => false, 'message' => 'Failed to create customer']);
        return;
    }
    $id = $conn->insert_id;
    $stmt = $conn->prepare("SELECT CustomerID, CustomerName, Phone, Address, Area, CreditLimit FROM Customers WHERE CustomerID = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $c = $stmt->get_result()->fetch_assoc();
    echo json_encode(['success' => true, 'customer' => customerPayload($conn, $c)]);
}

function createInvoice($conn, $data) {
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Validate items
        if (empty($data['items']) || !is_array($data['items'])) {
            throw new Exception("No items supplied");
        }
        // Quantities are whole peti; rates are Rs. per peti (may differ from the product base price).
        $MAX_RATE = 999999.99;
        $lines = [];
        foreach ($data['items'] as $item) {
            $q = $item['quantity'] ?? null;
            $pid = $item['ProductID'] ?? null;
            $rate = $item['Price'] ?? null;
            if (!is_numeric($pid) || (int)$pid <= 0) {
                throw new Exception("Invalid product in cart");
            }
            if (!is_numeric($q) || (float)$q != floor((float)$q) || (int)$q < 1 || (int)$q > 9999) {
                throw new Exception("Quantity must be a whole number of peti between 1 and 9999");
            }
            if (!is_numeric($rate) || (float)$rate < 0 || (float)$rate > $MAX_RATE) {
                throw new Exception("Rate per peti must be a number between 0 and " . number_format($MAX_RATE, 2));
            }
            $qty = (int)$q;
            $unit = round((float)$rate, 2);
            $lines[] = [
                'ProductID' => (int)$pid,
                'quantity' => $qty,
                'Price' => $unit,
                'TotalPrice' => round($unit * $qty, 2),
            ];
        }
        if (!$lines) {
            throw new Exception("No items supplied");
        }

        // Generate invoice number
        $invoiceNumber = 'INV-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        
        // Logged-in user (FK allows NULL)
        $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        
        // Recompute money from the validated lines — do not trust client totals.
        $subtotal = 0.0;
        foreach ($lines as $l) $subtotal += $l['TotalPrice'];
        $subtotal = round($subtotal, 2);
        $taxAmount = 0.0; // tax line is hidden on the till; keep column for schema compatibility
        $discount = round(max(0, floatval($data['discount'] ?? 0)), 2); // rupees off the subtotal
        if ($discount > $subtotal + 0.001) {
            throw new Exception("Discount cannot be more than the subtotal");
        }
        $total = round(max(0, $subtotal + $taxAmount - $discount), 2);

        // Cash / Credit
        $paymentType = (($data['paymentType'] ?? 'Cash') === 'Credit') ? 'Credit' : 'Cash';
        $customerId = isset($data['customerId']) && (int)$data['customerId'] > 0 ? (int)$data['customerId'] : null;
        $customerName = '';
        $mobileNo = '';

        if ($customerId !== null) {
            $cs = $conn->prepare("SELECT CustomerName, Phone, IsActive FROM Customers WHERE CustomerID = ?");
            $cs->bind_param('i', $customerId);
            $cs->execute();
            $cust = $cs->get_result()->fetch_assoc();
            if (!$cust || (int)$cust['IsActive'] !== 1) {
                throw new Exception("Selected customer not found or inactive");
            }
            $customerName = (string)$cust['CustomerName'];
            $mobileNo = (string)($cust['Phone'] ?? '');
        } elseif (!empty($data['customerName'])) {
            // walk-in name typed for a cash sale (legacy field)
            $customerName = mb_substr(trim((string)$data['customerName']), 0, 100);
        }

        if ($paymentType === 'Credit') {
            if ($customerId === null) {
                throw new Exception("Credit sale requires a customer");
            }
            $amountPaid = round(max(0, floatval($data['amountPaid'] ?? 0)), 2);
            if ($amountPaid > $total) {
                throw new Exception("Amount received cannot be more than the invoice total");
            }
        } else {
            $amountPaid = $total;
        }

        // Invoice time in Asia/Karachi (the DB server clock is UTC; all pages filter dates in Karachi time)
        $now = date('Y-m-d H:i:s');
        $stmt = $conn->prepare("INSERT INTO Invoices (InvoiceNumber, UserID, CustomerID, InvoiceDate, Subtotal, TaxAmount, Discount, TotalAmount, PaymentType, AmountPaid, CustomerName, MobileNo) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("siisddddsdss", $invoiceNumber, $userId, $customerId, $now, $subtotal, $taxAmount, $discount, $total, $paymentType, $amountPaid, $customerName, $mobileNo);
        
        if (!$stmt->execute()) {
            throw new Exception("Failed to create invoice");
        }
        
        $invoiceId = $conn->insert_id;
        
        // Insert invoice items
        $itemStmt = $conn->prepare("INSERT INTO InvoiceItems (InvoiceID, ProductID, Quantity, UnitPrice, TotalPrice) VALUES (?, ?, ?, ?, ?)");
        
        foreach ($lines as $item) {
            $productId = $item['ProductID'];
            $quantity = $item['quantity'];
            $unitPrice = $item['Price'];
            $totalPrice = $item['TotalPrice'];
            
            $itemStmt->bind_param("iiidd", $invoiceId, $productId, $quantity, $unitPrice, $totalPrice);
            
            if (!$itemStmt->execute()) {
                throw new Exception("Failed to add invoice item");
            }
        }
        
        // Insert payment record (money actually received at the till)
        $paymentMethod = isset($data['paymentMethod']) ? mb_substr((string)$data['paymentMethod'], 0, 50) : 'Cash';
        if ($paymentType === 'Cash' || $amountPaid > 0) {
            $paymentStmt = $conn->prepare("INSERT INTO Payments (InvoiceID, PaymentDate, AmountPaid, PaymentMethod) VALUES (?, ?, ?, ?)");
            $paymentStmt->bind_param("isds", $invoiceId, $now, $amountPaid, $paymentMethod);
            if (!$paymentStmt->execute()) {
                throw new Exception("Failed to record payment");
            }
        }

        // Credit sale: amount received now goes on the customer's ledger as a payment
        if ($paymentType === 'Credit' && $amountPaid > 0) {
            $cp = $conn->prepare("INSERT INTO CustomerPayments (CustomerID, InvoiceID, Amount, PaymentDate, Method, Note, UserID) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $note = 'Received with ' . $invoiceNumber;
            $cp->bind_param("iidsssi", $customerId, $invoiceId, $amountPaid, $now, $paymentMethod, $note, $userId);
            if (!$cp->execute()) {
                throw new Exception("Failed to record customer payment");
            }
        }
        
        // Commit transaction
        $conn->commit();
        
        $resp = [
            'success' => true,
            'message' => 'Invoice created successfully',
            'invoiceId' => $invoiceId,
            'invoiceNumber' => $invoiceNumber,
            'paymentType' => $paymentType,
            'amountPaid' => $amountPaid,
        ];
        if ($customerId !== null) {
            $resp['customerBalance'] = customer_balance($conn, $customerId);
        }
        echo json_encode($resp);
        
    } catch (Throwable $e) {
        // Rollback transaction on error
        $conn->rollback();
        
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
}


function invoice_edit_allowed(array $inv, string $role): array
{
    // returns [ok:bool, message:string]
    if (($role ?? '') === 'Admin') return [true, ''];
    $day = substr((string)$inv['InvoiceDate'], 0, 10);
    $today = date('Y-m-d'); // Asia/Karachi already set
    if ($day === $today) return [true, ''];
    return [false, 'Cashiers can only edit invoices from today (' . date('d M Y') . '). This one is dated ' . date('d M Y', strtotime($inv['InvoiceDate'])) . '.'];
}

function findInvoice($conn)
{
    $num = trim((string)($_GET['number'] ?? ''));
    if ($num === '') {
        echo json_encode(['success' => false, 'message' => 'Enter an invoice number']);
        return;
    }
    $st = $conn->prepare("SELECT InvoiceID, InvoiceNumber, InvoiceDate FROM Invoices WHERE InvoiceNumber = ? LIMIT 1");
    $st->bind_param('s', $num);
    $st->execute();
    $inv = $st->get_result()->fetch_assoc();
    if (!$inv) {
        echo json_encode(['success' => false, 'message' => 'No invoice found with number "' . $num . '"']);
        return;
    }
    $role = $_SESSION['role'] ?? 'Admin';
    // Need session role — session_write_close already called; role still in $_SESSION
    [$ok, $msg] = invoice_edit_allowed($inv, $role);
    if (!$ok) {
        echo json_encode(['success' => false, 'message' => $msg]);
        return;
    }
    echo json_encode(['success' => true, 'invoiceId' => (int)$inv['InvoiceID'], 'invoiceNumber' => $inv['InvoiceNumber']]);
}

function getInvoice($conn)
{
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Missing invoice id']);
        return;
    }
    $st = $conn->prepare("SELECT InvoiceID, InvoiceNumber, InvoiceDate, CustomerID, CustomerName, MobileNo, Subtotal, TaxAmount, Discount, TotalAmount, PaymentType, AmountPaid, UserID FROM Invoices WHERE InvoiceID = ?");
    $st->bind_param('i', $id);
    $st->execute();
    $inv = $st->get_result()->fetch_assoc();
    if (!$inv) {
        echo json_encode(['success' => false, 'message' => 'Invoice not found']);
        return;
    }
    $role = $_SESSION['role'] ?? 'Admin';
    [$ok, $msg] = invoice_edit_allowed($inv, $role);
    if (!$ok) {
        echo json_encode(['success' => false, 'message' => $msg]);
        return;
    }
    $it = $conn->prepare("SELECT ii.ProductID, ii.Quantity, ii.UnitPrice, ii.TotalPrice, p.ProductName, p.Price AS BasePrice
                          FROM InvoiceItems ii JOIN Products p ON p.ProductID = ii.ProductID WHERE ii.InvoiceID = ?");
    $it->bind_param('i', $id);
    $it->execute();
    $items = [];
    foreach ($it->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $items[] = [
            'ProductID' => (int)$r['ProductID'],
            'ProductName' => $r['ProductName'],
            'Price' => (float)$r['UnitPrice'],
            'BasePrice' => (float)$r['BasePrice'],
            'quantity' => (int)round((float)$r['Quantity']),
        ];
    }
    $paid = 0.0;
    $ps = $conn->prepare("SELECT COALESCE(SUM(Amount),0) t FROM CustomerPayments WHERE InvoiceID = ?");
    $ps->bind_param('i', $id);
    $ps->execute();
    $paid = (float)$ps->get_result()->fetch_assoc()['t'];

    $customer = null;
    if (!empty($inv['CustomerID'])) {
        $cs = $conn->prepare("SELECT CustomerID, CustomerName, Phone, Address, CreditLimit, IsActive FROM Customers WHERE CustomerID = ?");
        $cid = (int)$inv['CustomerID'];
        $cs->bind_param('i', $cid);
        $cs->execute();
        $customer = $cs->get_result()->fetch_assoc();
        if ($customer) {
            $customer['CustomerID'] = (int)$customer['CustomerID'];
            $customer['Balance'] = customer_balance($conn, (int)$customer['CustomerID']);
            $customer['CreditLimit'] = $customer['CreditLimit'] !== null ? (float)$customer['CreditLimit'] : null;
        }
    }
    echo json_encode([
        'success' => true,
        'invoice' => [
            'InvoiceID' => (int)$inv['InvoiceID'],
            'InvoiceNumber' => $inv['InvoiceNumber'],
            'InvoiceDate' => $inv['InvoiceDate'],
            'PaymentType' => $inv['PaymentType'],
            'Discount' => (float)$inv['Discount'],
            'TotalAmount' => (float)$inv['TotalAmount'],
            'AmountPaidOnInvoice' => $paid,
            'CustomerID' => $inv['CustomerID'] ? (int)$inv['CustomerID'] : null,
        ],
        'items' => $items,
        'customer' => $customer,
    ]);
}

function updateInvoice($conn, $data)
{
    $conn->begin_transaction();
    try {
        $invoiceId = (int)($data['invoiceId'] ?? 0);
        if ($invoiceId <= 0) throw new Exception('Missing invoice id');
        $st = $conn->prepare("SELECT * FROM Invoices WHERE InvoiceID = ? FOR UPDATE");
        $st->bind_param('i', $invoiceId);
        $st->execute();
        $old = $st->get_result()->fetch_assoc();
        if (!$old) throw new Exception('Invoice not found');

        $role = $_SESSION['role'] ?? 'Admin';
        [$ok, $msg] = invoice_edit_allowed($old, $role);
        if (!$ok) throw new Exception($msg);

        if (empty($data['items']) || !is_array($data['items'])) throw new Exception('No items supplied');
        $MAX_RATE = 999999.99;
        $lines = [];
        foreach ($data['items'] as $item) {
            $q = $item['quantity'] ?? null;
            $pid = $item['ProductID'] ?? null;
            $rate = $item['Price'] ?? null;
            if (!is_numeric($pid) || (int)$pid <= 0) throw new Exception('Invalid product in cart');
            if (!is_numeric($q) || (float)$q != floor((float)$q) || (int)$q < 1 || (int)$q > 9999) {
                throw new Exception('Quantity must be a whole number of peti between 1 and 9999');
            }
            if (!is_numeric($rate) || (float)$rate < 0 || (float)$rate > $MAX_RATE) {
                throw new Exception('Rate per peti must be a number between 0 and ' . number_format($MAX_RATE, 2));
            }
            $qty = (int)$q;
            $unit = round((float)$rate, 2);
            $lines[] = ['ProductID' => (int)$pid, 'quantity' => $qty, 'Price' => $unit, 'TotalPrice' => round($unit * $qty, 2), 'ProductName' => (string)($item['ProductName'] ?? '')];
        }
        $subtotal = round(array_sum(array_column($lines, 'TotalPrice')), 2);
        $taxAmount = 0.0;
        $discount = round(max(0, floatval($data['discount'] ?? 0)), 2);
        if ($discount > $subtotal + 0.001) throw new Exception('Discount cannot be more than the subtotal');
        $total = round(max(0, $subtotal + $taxAmount - $discount), 2);

        $paymentType = (($data['paymentType'] ?? 'Cash') === 'Credit') ? 'Credit' : 'Cash';
        $customerId = isset($data['customerId']) && (int)$data['customerId'] > 0 ? (int)$data['customerId'] : null;
        $customerName = '';
        $mobileNo = '';
        if ($customerId !== null) {
            $cs = $conn->prepare("SELECT CustomerName, Phone, IsActive FROM Customers WHERE CustomerID = ?");
            $cs->bind_param('i', $customerId);
            $cs->execute();
            $cust = $cs->get_result()->fetch_assoc();
            if (!$cust || (int)$cust['IsActive'] !== 1) throw new Exception('Selected customer not found or inactive');
            $customerName = (string)$cust['CustomerName'];
            $mobileNo = (string)($cust['Phone'] ?? '');
        } elseif (!empty($data['customerName'])) {
            $customerName = mb_substr(trim((string)$data['customerName']), 0, 100);
        }
        if ($paymentType === 'Credit' && $customerId === null) {
            throw new Exception('Credit sale requires a customer');
        }

        $ps = $conn->prepare("SELECT COALESCE(SUM(Amount),0) t FROM CustomerPayments WHERE InvoiceID = ?");
        $ps->bind_param('i', $invoiceId);
        $ps->execute();
        $alreadyPaid = (float)$ps->get_result()->fetch_assoc()['t'];
        if ($total + 0.001 < $alreadyPaid) {
            throw new Exception('New total (Rs. ' . number_format($total, 2) . ') is less than Rs. ' . number_format($alreadyPaid, 2) . ' already received on this invoice');
        }
        $amountPaid = ($paymentType === 'Cash') ? $total : $alreadyPaid;

        // Old lines for log
        $oldItems = [];
        $oi = $conn->prepare("SELECT ii.ProductID, ii.Quantity, ii.UnitPrice, p.ProductName FROM InvoiceItems ii JOIN Products p ON p.ProductID = ii.ProductID WHERE ii.InvoiceID = ?");
        $oi->bind_param('i', $invoiceId);
        $oi->execute();
        foreach ($oi->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
            $oldItems[] = ['ProductID' => (int)$r['ProductID'], 'name' => $r['ProductName'], 'qty' => (float)$r['Quantity'], 'rate' => (float)$r['UnitPrice']];
        }

        // Replace lines (stock is computed from InvoiceItems — no Sale movements to rebuild)
        $del = $conn->prepare("DELETE FROM InvoiceItems WHERE InvoiceID = ?");
        $del->bind_param('i', $invoiceId);
        $del->execute();
        $itemStmt = $conn->prepare("INSERT INTO InvoiceItems (InvoiceID, ProductID, Quantity, UnitPrice, TotalPrice) VALUES (?, ?, ?, ?, ?)");
        foreach ($lines as $item) {
            $productId = $item['ProductID'];
            $quantity = $item['quantity'];
            $unitPrice = $item['Price'];
            $totalPrice = $item['TotalPrice'];
            $itemStmt->bind_param('iiidd', $invoiceId, $productId, $quantity, $unitPrice, $totalPrice);
            if (!$itemStmt->execute()) throw new Exception('Failed to save invoice item');
        }

        // Keep InvoiceDate unchanged
        if ($customerId === null) {
            $upd = $conn->prepare("UPDATE Invoices SET CustomerID=NULL, CustomerName=?, MobileNo=?, Subtotal=?, TaxAmount=?, Discount=?, TotalAmount=?, PaymentType=?, AmountPaid=? WHERE InvoiceID=?");
            $upd->bind_param('ssddddsdi', $customerName, $mobileNo, $subtotal, $taxAmount, $discount, $total, $paymentType, $amountPaid, $invoiceId);
        } else {
            $upd = $conn->prepare("UPDATE Invoices SET CustomerID=?, CustomerName=?, MobileNo=?, Subtotal=?, TaxAmount=?, Discount=?, TotalAmount=?, PaymentType=?, AmountPaid=? WHERE InvoiceID=?");
            $upd->bind_param('issddddsdi', $customerId, $customerName, $mobileNo, $subtotal, $taxAmount, $discount, $total, $paymentType, $amountPaid, $invoiceId);
        }
        if (!$upd->execute()) throw new Exception('Failed to update invoice');

        // Refresh till Payments row for cash / partial credit display
        $conn->query("DELETE FROM Payments WHERE InvoiceID = " . (int)$invoiceId);
        if ($paymentType === 'Cash' || $amountPaid > 0) {
            $method = isset($data['paymentMethod']) ? mb_substr((string)$data['paymentMethod'], 0, 50) : 'Cash';
            $payDate = $old['InvoiceDate'];
            $paymentStmt = $conn->prepare("INSERT INTO Payments (InvoiceID, PaymentDate, AmountPaid, PaymentMethod) VALUES (?, ?, ?, ?)");
            $paymentStmt->bind_param('isds', $invoiceId, $payDate, $amountPaid, $method);
            if (!$paymentStmt->execute()) throw new Exception('Failed to update payment record');
        }

        // Build change summary
        $changes = [];
        if (($old['PaymentType'] ?? '') !== $paymentType) $changes[] = 'type ' . $old['PaymentType'] . ' → ' . $paymentType;
        $oldCid = $old['CustomerID'] ? (int)$old['CustomerID'] : null;
        if ($oldCid !== $customerId) {
            $changes[] = 'customer ' . ($old['CustomerName'] ?: 'Walk-in') . ' → ' . ($customerName ?: 'Walk-in');
        }
        if (round((float)$old['Discount'], 2) !== $discount) $changes[] = 'discount Rs. ' . number_format((float)$old['Discount'], 2) . ' → Rs. ' . number_format($discount, 2);
        if (round((float)$old['TotalAmount'], 2) !== $total) $changes[] = 'total Rs. ' . number_format((float)$old['TotalAmount'], 2) . ' → Rs. ' . number_format($total, 2);
        $oldMap = [];
        foreach ($oldItems as $o) $oldMap[$o['ProductID']] = $o;
        $newMap = [];
        foreach ($lines as $l) $newMap[$l['ProductID']] = $l;
        foreach ($newMap as $pid => $l) {
            if (!isset($oldMap[$pid])) $changes[] = 'added ' . ($l['ProductName'] ?: ('#' . $pid)) . ' × ' . $l['quantity'] . ' @ ' . number_format($l['Price'], 2);
            else {
                $o = $oldMap[$pid];
                if ((int)$o['qty'] !== (int)$l['quantity'] || round($o['rate'], 2) !== round($l['Price'], 2)) {
                    $changes[] = ($l['ProductName'] ?: $o['name']) . ' ' . (int)$o['qty'] . '@' . number_format($o['rate'], 2) . ' → ' . $l['quantity'] . '@' . number_format($l['Price'], 2);
                }
            }
        }
        foreach ($oldMap as $pid => $o) {
            if (!isset($newMap[$pid])) $changes[] = 'removed ' . $o['name'] . ' × ' . (int)$o['qty'];
        }
        if (!$changes) $changes[] = 'saved with no field differences';
        $summary = 'Edited invoice ' . $old['InvoiceNumber'] . ': ' . implode('; ', $changes);
        $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        $detail = json_encode(['before' => ['total' => (float)$old['TotalAmount'], 'discount' => (float)$old['Discount'], 'type' => $old['PaymentType'], 'customerId' => $oldCid, 'items' => $oldItems], 'after' => ['total' => $total, 'discount' => $discount, 'type' => $paymentType, 'customerId' => $customerId, 'items' => $lines]], JSON_UNESCAPED_UNICODE);
        $ref = $old['InvoiceNumber'];
        if ($userId === null) {
            $log = $conn->prepare("INSERT INTO ActivityLog (EventType, EntityType, EntityID, EntityRef, UserID, Summary, Detail) VALUES ('invoice_edit', 'Invoice', ?, ?, NULL, ?, ?)");
            $log->bind_param('isss', $invoiceId, $ref, $summary, $detail);
        } else {
            $log = $conn->prepare("INSERT INTO ActivityLog (EventType, EntityType, EntityID, EntityRef, UserID, Summary, Detail) VALUES ('invoice_edit', 'Invoice', ?, ?, ?, ?, ?)");
            $log->bind_param('isiss', $invoiceId, $ref, $userId, $summary, $detail);
        }
        if (!$log->execute()) throw new Exception('Invoice updated but failed to write activity log');

        $conn->commit();
        $resp = ['success' => true, 'message' => 'Invoice updated', 'invoiceId' => $invoiceId, 'invoiceNumber' => $old['InvoiceNumber'], 'total' => $total];
        if ($customerId) $resp['customerBalance'] = customer_balance($conn, $customerId);
        echo json_encode($resp);
    } catch (Throwable $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}
