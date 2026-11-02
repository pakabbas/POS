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
    }
}

function handlePost($conn) {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = isset($input['action']) ? $input['action'] : '';
    
    if ($action === 'createInvoice') {
        createInvoice($conn, $input);
    } elseif ($action === 'createCustomer') {
        createCustomer($conn, $input);
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
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
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

        // Generate invoice number
        $invoiceNumber = 'INV-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        
        // Logged-in user (FK allows NULL)
        $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        
        // Totals
        $subtotal = max(0, floatval($data['subtotal'] ?? 0));
        $taxAmount = max(0, floatval($data['taxAmount'] ?? 0));
        $discount = max(0, floatval($data['discount'] ?? 0));
        $total = max(0, floatval($data['total'] ?? 0));
        $total = round($total, 2);

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
        
        foreach ($data['items'] as $item) {
            $productId = intval($item['ProductID']);
            $quantity = max(1, intval($item['quantity']));
            $unitPrice = max(0, floatval($item['Price']));
            $totalPrice = $unitPrice * $quantity;
            
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
