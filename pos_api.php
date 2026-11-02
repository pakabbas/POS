<?php
error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', '0');
header('Content-Type: application/json');
// Correct path to DB connection
require_once __DIR__ . '/includes/db.php';
// Ensure connection closes per request
register_shutdown_function(function() use ($conn) {
    if ($conn instanceof mysqli) {
        $conn->close();
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
    }
}

function handlePost($conn) {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = isset($input['action']) ? $input['action'] : '';
    
    if ($action === 'createInvoice') {
        createInvoice($conn, $input);
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
    $query .= " ORDER BY p.ProductName ASC";
    
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
        
        // User can be null (FK allows NULL per schema)
        $userId = null;
        
        // Totals
        $subtotal = max(0, floatval($data['subtotal'] ?? 0));
        $taxAmount = max(0, floatval($data['taxAmount'] ?? 0));
        $discount = max(0, floatval($data['discount'] ?? 0));
        $total = max(0, floatval($data['total'] ?? 0));

        // Insert invoice (InvoiceDate uses default current_timestamp)
        $stmt = $conn->prepare("INSERT INTO Invoices (InvoiceNumber, UserID, Subtotal, TaxAmount, Discount, TotalAmount) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sidddd", $invoiceNumber, $userId, $subtotal, $taxAmount, $discount, $total);
        
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
        
        // Insert payment record
        $paymentMethod = isset($data['paymentMethod']) ? $data['paymentMethod'] : 'Cash';
        $paymentStmt = $conn->prepare("INSERT INTO Payments (InvoiceID, AmountPaid, PaymentMethod) VALUES (?, ?, ?)");
        $paymentStmt->bind_param("ids", $invoiceId, $total, $paymentMethod);
        
        if (!$paymentStmt->execute()) {
            throw new Exception("Failed to record payment");
        }
        
        // Commit transaction
        $conn->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Invoice created successfully',
            'invoiceId' => $invoiceId,
            'invoiceNumber' => $invoiceNumber
        ]);
        
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
}

$conn->close();
?>