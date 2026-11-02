<?php
$action = $_GET['action'] ?? '';

if ($action !== '') {
    // AJAX endpoints
session_start();
    if (!isset($_SESSION['username'])) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }

    require __DIR__ . '/includes/db.php';
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn->set_charset('utf8mb4');

    if ($action === 'products') {
        header('Content-Type: application/json');
        $category = isset($_GET['category']) ? (int) $_GET['category'] : null;
        $search = trim($_GET['search'] ?? '');

        $sql = "SELECT p.ProductID, p.ProductName, p.Price, c.CategoryName
                FROM Products p
                LEFT JOIN Categories c ON p.CategoryID = c.CategoryID
                WHERE p.IsActive = 1";
        $params = [];
        $types = '';

        if ($category) {
            $sql .= " AND p.CategoryID = ?";
            $types .= 'i';
            $params[] = $category;
        }
        if ($search !== '') {
            $sql .= " AND p.ProductName LIKE ?";
            $types .= 's';
            $params[] = '%' . $search . '%';
        }

        $stmt = $conn->prepare($sql);
        if ($types) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $res = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        echo json_encode($res);
        exit;
    }

    if ($action === 'create_invoice' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json');
        $payload = json_decode(file_get_contents('php://input'), true) ?? [];
        $items = $payload['items'] ?? [];
        $discount = max(0, (float) ($payload['discount'] ?? 0));
        $taxRate = max(0, (float) ($payload['taxRate'] ?? 0));
        $paymentMethod = trim($payload['paymentMethod'] ?? 'Cash');
        $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;

        if (!$items || !is_array($items)) {
            http_response_code(422);
            echo json_encode(['error' => 'No items supplied']);
            exit;
        }

        try {
            $conn->begin_transaction();

            $subtotal = 0.0;
            $cleanItems = [];
            $priceStmt = $conn->prepare("SELECT Price FROM Products WHERE ProductID = ? AND IsActive = 1");

            foreach ($items as $item) {
                $pid = (int) ($item['productId'] ?? 0);
                $qty = max(1, (int) ($item['quantity'] ?? 0));
                if ($pid <= 0) {
                    throw new RuntimeException('Invalid product');
                }

                $priceStmt->bind_param('i', $pid);
                $priceStmt->execute();
                $row = $priceStmt->get_result()->fetch_assoc();
                if (!$row) {
                    throw new RuntimeException('Product not found or inactive');
                }
                $unitPrice = (float) $row['Price'];
                $lineTotal = $unitPrice * $qty;
                $subtotal += $lineTotal;
                $cleanItems[] = [
                    'productId' => $pid,
                    'quantity' => $qty,
                    'unitPrice' => $unitPrice,
                    'lineTotal' => $lineTotal,
                ];
            }

            $taxAmount = round($subtotal * ($taxRate / 100), 2);
            $total = round($subtotal + $taxAmount - $discount, 2);
            $total = max(0, $total);

            $invoiceNumber = 'INV-' . date('YmdHis') . '-' . random_int(100, 999);
            $now = date('Y-m-d H:i:s');

            $invStmt = $conn->prepare(
                "INSERT INTO Invoices (InvoiceNumber, UserID, InvoiceDate, Subtotal, TaxAmount, Discount, TotalAmount, isShow)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 1)"
            );
            $invStmt->bind_param(
                'sissddd',
                $invoiceNumber,
                $userId,
                $now,
                $subtotal,
                $taxAmount,
                $discount,
                $total
            );
            $invStmt->execute();
            $invoiceId = $conn->insert_id;

            $itemStmt = $conn->prepare(
                "INSERT INTO InvoiceItems (InvoiceID, ProductID, Quantity, UnitPrice, TotalPrice)
                 VALUES (?, ?, ?, ?, ?)"
            );
            foreach ($cleanItems as $ci) {
                $itemStmt->bind_param(
                    'iiidd',
                    $invoiceId,
                    $ci['productId'],
                    $ci['quantity'],
                    $ci['unitPrice'],
                    $ci['lineTotal']
                );
                $itemStmt->execute();
            }

            $payStmt = $conn->prepare(
                "INSERT INTO Payments (InvoiceID, AmountPaid, PaymentMethod) VALUES (?, ?, ?)"
            );
            $payStmt->bind_param('ids', $invoiceId, $total, $paymentMethod);
            $payStmt->execute();

            $conn->commit();
            echo json_encode(['success' => true, 'invoiceId' => $invoiceId, 'invoiceNumber' => $invoiceNumber]);
        } catch (Throwable $e) {
            if ($conn->errno) {
                $conn->rollback();
            }
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unknown action']);
    exit;
}

// Page render
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/db.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

$categories = $conn->query("SELECT CategoryID, CategoryName FROM Categories WHERE IsActive = 1 ORDER BY CategoryName")->fetch_all(MYSQLI_ASSOC);
$products = $conn->query(
    "SELECT p.ProductID, p.ProductName, p.Price, p.CategoryID, c.CategoryName
     FROM Products p
     LEFT JOIN Categories c ON p.CategoryID = c.CategoryID
     WHERE p.IsActive = 1
     ORDER BY p.ProductName"
)->fetch_all(MYSQLI_ASSOC);
$taxRow = $conn->query("SELECT TaxRate FROM Taxes ORDER BY TaxID LIMIT 1")->fetch_assoc();
$defaultTaxRate = $taxRow ? (float) $taxRow['TaxRate'] : 0.0;
?>

<div class="card shadow-sm mb-4">
  <div class="card-body">
    <div class="d-flex flex-wrap gap-2 align-items-center">
      <div class="flex-grow-1">
        <h3 class="mb-1 fw-bold">Restaurant POS</h3>
        <p class="text-muted mb-0">Fast, modern, ready to ship</p>
            </div>
      <div class="d-flex gap-2">
        <input type="text" id="searchInput" class="form-control" placeholder="Search products...">
        <select id="categoryFilter" class="form-select">
          <option value="">All Categories</option>
          <?php foreach ($categories as $cat): ?>
            <option value="<?= htmlspecialchars($cat['CategoryID']) ?>"><?= htmlspecialchars($cat['CategoryName']) ?></option>
          <?php endforeach; ?>
        </select>
            </div>
            </div>
            </div>
        </div>
        
<div class="row g-3">
  <div class="col-lg-8">
    <div class="card shadow-sm">
      <div class="card-header bg-white">
        <div class="d-flex justify-content-between align-items-center">
          <span class="fw-semibold">Products</span>
          <small class="text-muted" id="productCount"></small>
        </div>
      </div>
      <div class="card-body">
        <div id="productGrid" class="row g-3"></div>
            </div>
                </div>
            </div>
  <div class="col-lg-4">
    <div class="card shadow-sm h-100">
      <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <span class="fw-semibold">Cart</span>
        <button class="btn btn-sm btn-outline-secondary" id="clearCartBtn">Clear</button>
                </div>
      <div class="card-body d-flex flex-column">
        <div id="cartList" class="mb-3" style="max-height: 320px; overflow-y: auto;"></div>
        <div class="mb-3">
          <label class="form-label small mb-1">Discount (flat)</label>
          <input type="number" min="0" step="0.01" class="form-control" id="discountInput" value="0">
                </div>
        <div class="mb-3">
          <label class="form-label small mb-1">Tax Rate (%)</label>
          <input type="number" min="0" step="0.01" class="form-control" id="taxRateInput" value="<?= htmlspecialchars($defaultTaxRate) ?>">
                </div>
        <div class="mb-3">
          <label class="form-label small mb-1">Payment Method</label>
          <select class="form-select" id="paymentMethod">
            <option>Cash</option>
            <option>Card</option>
            <option>Online</option>
          </select>
                </div>
        <div class="mb-3 bg-light rounded p-3">
          <div class="d-flex justify-content-between"><span>Subtotal</span><strong id="subtotalDisplay">0.00</strong></div>
          <div class="d-flex justify-content-between"><span>Tax</span><strong id="taxDisplay">0.00</strong></div>
          <div class="d-flex justify-content-between"><span>Discount</span><strong id="discountDisplay">0.00</strong></div>
          <hr>
          <div class="d-flex justify-content-between fs-5"><span>Total</span><strong id="totalDisplay">0.00</strong></div>
                </div>
        <button class="btn btn-primary w-100 mt-auto" id="checkoutBtn">Checkout</button>
                </div>
            </div>
        </div>
    </div>

<template id="productCardTemplate">
  <div class="col-6 col-md-4 col-xl-3">
    <div class="card h-100 product-card border-0 shadow-sm">
      <div class="card-body d-flex flex-column">
        <div class="fw-semibold product-name"></div>
        <small class="text-muted product-category"></small>
        <div class="mt-auto d-flex justify-content-between align-items-center">
          <span class="fw-bold product-price"></span>
          <button class="btn btn-sm btn-outline-primary add-btn">Add</button>
            </div>
            </div>
        </div>
    </div>
</template>

<template id="cartItemTemplate">
  <div class="d-flex align-items-center justify-content-between border rounded p-2 mb-2">
    <div>
      <div class="fw-semibold item-name"></div>
      <small class="text-muted item-price"></small>
    </div>
    <div class="d-flex align-items-center gap-1">
      <button class="btn btn-sm btn-outline-secondary decrement">-</button>
      <input class="form-control form-control-sm qty-input" type="number" min="1" value="1" style="width:70px;">
      <button class="btn btn-sm btn-outline-secondary increment">+</button>
    </div>
    <div class="text-end">
      <div class="fw-semibold item-total"></div>
      <button class="btn btn-link text-danger btn-sm remove-btn">Remove</button>
    </div>
    </div>
</template>

    <script>
(() => {
  const products = <?= json_encode($products) ?>;
  const productMap = new Map(products.map(p => [p.ProductID, {...p}]));
  let cart = {};

  const els = {
    productGrid: document.getElementById('productGrid'),
    productCount: document.getElementById('productCount'),
    searchInput: document.getElementById('searchInput'),
    categoryFilter: document.getElementById('categoryFilter'),
    cartList: document.getElementById('cartList'),
    subtotalDisplay: document.getElementById('subtotalDisplay'),
    taxDisplay: document.getElementById('taxDisplay'),
    discountDisplay: document.getElementById('discountDisplay'),
    totalDisplay: document.getElementById('totalDisplay'),
    discountInput: document.getElementById('discountInput'),
    taxRateInput: document.getElementById('taxRateInput'),
    paymentMethod: document.getElementById('paymentMethod'),
    checkoutBtn: document.getElementById('checkoutBtn'),
    clearCartBtn: document.getElementById('clearCartBtn')
  };

  const templates = {
    productCard: document.getElementById('productCardTemplate'),
    cartItem: document.getElementById('cartItemTemplate')
  };

  function renderProducts(list) {
    els.productGrid.innerHTML = '';
    list.forEach(p => {
      const node = templates.productCard.content.cloneNode(true);
      node.querySelector('.product-name').textContent = p.ProductName;
      node.querySelector('.product-price').textContent = formatCurrency(p.Price);
      node.querySelector('.product-category').textContent = p.CategoryName ? p.CategoryName : '';
      node.querySelector('.add-btn').addEventListener('click', () => addToCart(p.ProductID));
      els.productGrid.appendChild(node);
    });
    els.productCount.textContent = `${list.length} item(s)`;
  }

  function addToCart(id) {
    const prod = productMap.get(id);
    if (!prod) return;
    if (!cart[id]) {
      cart[id] = { ...prod, quantity: 1 };
            } else {
      cart[id].quantity += 1;
    }
    renderCart();
  }

  function renderCart() {
    els.cartList.innerHTML = '';
    Object.values(cart).forEach(item => {
      const node = templates.cartItem.content.cloneNode(true);
      node.querySelector('.item-name').textContent = item.ProductName;
      node.querySelector('.item-price').textContent = `${formatCurrency(item.Price)} ea`;
      const qtyInput = node.querySelector('.qty-input');
      qtyInput.value = item.quantity;
      node.querySelector('.item-total').textContent = formatCurrency(item.Price * item.quantity);
      node.querySelector('.increment').addEventListener('click', () => { item.quantity += 1; renderCart(); });
      node.querySelector('.decrement').addEventListener('click', () => {
        item.quantity = Math.max(1, item.quantity - 1);
        renderCart();
      });
      qtyInput.addEventListener('change', e => {
        const val = Math.max(1, parseInt(e.target.value || '1', 10));
        item.quantity = val;
        renderCart();
      });
      node.querySelector('.remove-btn').addEventListener('click', () => { delete cart[item.ProductID]; renderCart(); });
      els.cartList.appendChild(node);
    });
    recalcTotals();
  }

  function recalcTotals() {
    const subtotal = Object.values(cart).reduce((sum, item) => sum + item.Price * item.quantity, 0);
    const discount = Math.max(0, parseFloat(els.discountInput.value) || 0);
    const taxRate = Math.max(0, parseFloat(els.taxRateInput.value) || 0);
    const tax = subtotal * (taxRate / 100);
    const total = Math.max(0, subtotal + tax - discount);
    els.subtotalDisplay.textContent = formatCurrency(subtotal);
    els.taxDisplay.textContent = formatCurrency(tax);
    els.discountDisplay.textContent = formatCurrency(discount);
    els.totalDisplay.textContent = formatCurrency(total);
  }

  function formatCurrency(val) {
    return new Intl.NumberFormat(undefined, { style: 'currency', currency: 'PKR' }).format(val);
  }

  async function fetchProducts() {
    const params = new URLSearchParams();
    const cat = els.categoryFilter.value;
    const search = els.searchInput.value.trim();
    if (cat) params.set('category', cat);
    if (search) params.set('search', search);
    const res = await fetch(`pos.php?action=products&${params.toString()}`);
    const data = await res.json();
    renderProducts(data);
  }

  async function checkout() {
    const items = Object.values(cart).map(item => ({
      productId: item.ProductID,
      quantity: item.quantity
    }));
    if (!items.length) {
      alert('Add items to cart first.');
                return;
            }
    const payload = {
      items,
      discount: parseFloat(els.discountInput.value) || 0,
      taxRate: parseFloat(els.taxRateInput.value) || 0,
      paymentMethod: els.paymentMethod.value
    };
    els.checkoutBtn.disabled = true;
    try {
      const res = await fetch('pos.php?action=create_invoice', {
                method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || 'Failed to save invoice');
      alert(`Invoice ${data.invoiceNumber} created successfully.`);
      cart = {};
      renderCart();
    } catch (err) {
      alert(err.message);
    } finally {
      els.checkoutBtn.disabled = false;
    }
  }

  els.searchInput.addEventListener('input', fetchProducts);
  els.categoryFilter.addEventListener('change', fetchProducts);
  els.discountInput.addEventListener('input', recalcTotals);
  els.taxRateInput.addEventListener('input', recalcTotals);
  els.checkoutBtn.addEventListener('click', checkout);
  els.clearCartBtn.addEventListener('click', () => { cart = {}; renderCart(); });

  renderProducts(products);
  renderCart();
})();
</script>

<style>
  .product-card:hover {
    transform: translateY(-2px);
    transition: all 0.15s ease-in-out;
    box-shadow: 0 0.5rem 1rem rgba(0,0,0,0.08);
  }
</style>

</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

