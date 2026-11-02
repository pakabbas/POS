<?php
session_start();
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}
date_default_timezone_set('Asia/Karachi');
require_once 'includes/db.php';
register_shutdown_function(function() use ($conn) {
    if ($conn instanceof mysqli) {
        $conn->close();
    }
});

// Fetch active categories
$categories_query = "SELECT CategoryID, CategoryName FROM Categories WHERE IsActive = 1 ORDER BY CategoryName";
$categories_result = $conn->query($categories_query);

// Category icon map for quick visual tiles
$categoryIcons = [
    1 => '🥚', // Normal Eggs
    2 => '🐔', // Desi Egg
];

// Fetch tax rate
$tax_query = "SELECT TaxRate FROM Taxes WHERE TaxID = 1";
$tax_result = $conn->query($tax_query);
$tax_rate = $tax_result && $tax_result->num_rows > 0 ? $tax_result->fetch_assoc()['TaxRate'] : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Restaurant POS System</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        
        .pos-container {
            max-width: 1600px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: 1fr 400px;
            gap: 20px;
            height: calc(100vh - 40px);
        }
        
        .products-section {
            background: white;
            border-radius: 20px;
            padding: 25px;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        
        .cart-section {
            background: white;
            border-radius: 20px;
            padding: 25px;
            display: flex;
            flex-direction: column;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 2px solid #f0f0f0;
        }

        .back-btn {
            padding: 10px 14px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            text-decoration: none;
            color: #4a5568;
            font-weight: 600;
            background: white;
            transition: all 0.2s;
        }

        .back-btn:hover {
            border-color: #667eea;
            color: #667eea;
            transform: translateY(-2px);
        }
        
        .header h1 {
            font-size: 28px;
            color: #2d3748;
            font-weight: 700;
        }
        
        .search-box {
            position: relative;
            margin-bottom: 20px;
        }
        
        .search-box input {
            width: 100%;
            padding: 14px 20px 14px 45px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 15px;
            transition: all 0.3s;
        }
        
        .search-box input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .search-icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #a0aec0;
        }
        
        .categories {
            display: flex;
            gap: 10px;
            margin-bottom: 25px;
            flex-wrap: wrap;
        }
        
        .category-btn {
            padding: 10px 20px;
            border: 2px solid #e2e8f0;
            background: white;
            border-radius: 10px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.3s;
            color: #4a5568;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        
        .category-btn:hover {
            border-color: #667eea;
            color: #667eea;
            transform: translateY(-2px);
        }
        
        .category-btn.active {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }

        .cat-icon {
            font-size: 16px;
            line-height: 1;
        }
        
        .products-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
            gap: 12px;
        }
        
        .product-card {
            background: white;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            padding: 12px;
            cursor: pointer;
            transition: all 0.3s;
            text-align: center;
        }
        
        .product-card:hover {
            border-color: #667eea;
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(102, 126, 234, 0.2);
        }
        
        .product-icon {
            width: 52px;
            height: 52px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 10px;
            font-size: 24px;
        }
        
        .product-card .product-icon { background: linear-gradient(135deg, #fde68a 0%, #f59e0b 100%); }
        .egg-wrap { position: relative; display: inline-flex; align-items: center; justify-content: center; width: 100%; height: 100%; }
        .egg.desi { filter: sepia(0.9) saturate(2.2) hue-rotate(-10deg) brightness(0.85); }
        .egg-badge { position: absolute; right: -6px; bottom: -6px; background: #fff; color: #92400e; font-size: 10px; font-weight: 700; border-radius: 8px; padding: 1px 5px; box-shadow: 0 1px 3px rgba(0,0,0,.25); }
        .desi-badge { font-size: 11px; padding: 0 3px; }

        .product-name {
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 8px;
            font-size: 14px;
            min-height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .product-stock { font-size: 11px; color: #64748b; margin-top: 2px; }
        .product-stock.out { color: #dc2626; font-weight: 600; }

        .product-price {
            color: #667eea;
            font-weight: 700;
            font-size: 16px;
        }
        
        .cart-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 20px;
        }
        
        .cart-header h2 {
            font-size: 18px;
            color: #2d3748;
            font-weight: 700;
            margin: 0;
        }

        .quick-checkout-btn {
            padding: 6px 12px;
            border: none;
            border-radius: 8px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #fff;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }

        .quick-checkout-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(0,0,0,0.18);
        }
        
        .cart-items {
            flex: 1;
            overflow-y: auto;
            margin-bottom: 20px;
            min-height: 200px;
        }
        
        .cart-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px;
            background: #f7fafc;
            border-radius: 10px;
            margin-bottom: 10px;
            transition: all 0.3s;
        }
        
        .cart-item:hover {
            background: #edf2f7;
        }
        
        .item-info {
            flex: 1;
        }
        
        .item-name {
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 5px;
            font-size: 14px;
        }
        
        .item-price {
            color: #718096;
            font-size: 13px;
        }
        
        .item-controls {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .qty-btn {
            width: 32px;
            height: 32px;
            border: none;
            background: #667eea;
            color: white;
            border-radius: 8px;
            cursor: pointer;
            font-size: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s;
        }
        
        .qty-btn:hover {
            background: #5568d3;
            transform: scale(1.1);
        }
        
        .qty-display {
            font-weight: 600;
            min-width: 30px;
            text-align: center;
            color: #2d3748;
        }
        
        .remove-btn {
            background: #fc8181;
            color: white;
            border: none;
            padding: 8px 12px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 12px;
            transition: all 0.3s;
        }
        
        .remove-btn:hover {
            background: #f56565;
        }
        
        .cart-summary {
            border-top: 2px solid #e2e8f0;
            padding-top: 20px;
        }
        
        .summary-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 12px;
            font-size: 15px;
        }
        
        .summary-row.total {
            font-size: 20px;
            font-weight: 700;
            color: #2d3748;
            margin-top: 12px;
            padding-top: 12px;
            border-top: 2px solid #e2e8f0;
        }
        
        .discount-input {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
        }
        
        .discount-input input {
            flex: 1;
            padding: 10px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
        }
        
        .discount-input button {
            padding: 10px 20px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .discount-input button:hover {
            background: #5568d3;
        }
        
        .action-buttons {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-top: 20px;
        }
        
        .btn {
            padding: 16px;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .btn-clear {
            background: #fed7d7;
            color: #c53030;
        }
        
        .btn-clear:hover {
            background: #fc8181;
            color: white;
            transform: translateY(-2px);
        }
        
        .btn-checkout {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            grid-column: 1 / -1;
        }
        
        .btn-checkout:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(102, 126, 234, 0.3);
        }
        
        .empty-cart {
            text-align: center;
            color: #a0aec0;
            padding: 40px 20px;
        }
        
        .empty-cart-icon {
            font-size: 64px;
            margin-bottom: 15px;
        }
        
        .loading {
            text-align: center;
            padding: 20px;
            color: #667eea;
        }
        
        @media (max-width: 1024px) {
            .pos-container {
                grid-template-columns: 1fr;
                height: auto;
            }
            
            .cart-section {
                min-height: 500px;
            }
        }
        
        @media (max-width: 768px) {
            .products-grid {
                grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
            }
            
            .header h1 {
                font-size: 22px;
            }
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.6);
            align-items: center;
            justify-content: center;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: white;
            padding: 30px;
            border-radius: 20px;
            max-width: 400px;
            width: 90%;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }

        .modal-header {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 20px;
            color: #2d3748;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: #4a5568;
        }

        .form-group select,
        .form-group input {
            width: 100%;
            padding: 12px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
        }

        .modal-buttons {
            display: flex;
            gap: 10px;
            margin-top: 25px;
        }

        .modal-buttons button {
            flex: 1;
            padding: 12px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }

        .btn-cancel {
            background: #e2e8f0;
            color: #4a5568;
        }

        .btn-cancel:hover {
            background: #cbd5e0;
        }

        .btn-confirm {
            background: #48bb78;
            color: white;
        }

        .btn-confirm:hover {
            background: #38a169;
        }

        .success-message {
            position: fixed;
            top: 20px;
            right: 20px;
            background: #48bb78;
            color: white;
            padding: 15px 25px;
            border-radius: 10px;
            box-shadow: 0 10px 25px rgba(72, 187, 120, 0.3);
            z-index: 2000;
            display: none;
            animation: slideIn 0.3s ease;
        }

        @keyframes slideIn {
            from {
                transform: translateX(400px);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        /* ---- Compact Current Order panel (fits ~1366x768 with totals + Checkout visible) ---- */
        .cart-section { padding: 16px 18px; min-height: 0; overflow: hidden; }
        .cart-header { margin-bottom: 10px; }
        .cart-items { flex: 1 1 auto; min-height: 80px; overflow-y: auto; margin-bottom: 8px; padding-right: 2px; }
        .cart-item { padding: 6px 10px; margin-bottom: 5px; border-radius: 8px; }
        .item-name { font-size: 13px; margin-bottom: 1px; }
        .item-price { font-size: 12px; }
        .item-controls { gap: 6px; }
        .qty-btn { width: 26px; height: 26px; font-size: 16px; border-radius: 6px; }
        .qty-display { min-width: 22px; font-size: 13px; }
        .remove-btn { padding: 5px 8px; font-size: 11px; }
        .empty-cart { padding: 16px 10px; }
        .cart-items .empty-cart-icon { font-size: 36px; margin-bottom: 6px; }
        .cart-summary { padding-top: 10px; flex: 0 0 auto; }
        .discount-input { margin-bottom: 8px; }
        .discount-input input, .discount-input button { padding: 7px 12px; }
        .summary-row { margin-bottom: 5px; font-size: 14px; }
        .summary-row.total { font-size: 18px; margin-top: 6px; padding-top: 6px; }
        .action-buttons { grid-template-columns: 1fr; margin-top: 8px; }
        .btn { padding: 12px; }

        /* ---- Customer / Cash-Credit controls ---- */
        .customer-panel { flex: 0 0 auto; margin-bottom: 8px; }
        .pay-type { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; margin-bottom: 6px; }
        .pay-type button { padding: 7px; border: 2px solid #e2e8f0; background: #fff; border-radius: 8px; font-weight: 600; font-size: 13px; cursor: pointer; color: #4a5568; }
        .pay-type button.active[data-type="Cash"] { background: #48bb78; border-color: #48bb78; color: #fff; }
        .pay-type button.active[data-type="Credit"] { background: #dd6b20; border-color: #dd6b20; color: #fff; }
        .cust-row { display: flex; gap: 6px; }
        .cust-search { position: relative; flex: 1; }
        .cust-search input { width: 100%; padding: 7px 10px; border: 2px solid #e2e8f0; border-radius: 8px; font-size: 13px; }
        .cust-search input:focus { outline: none; border-color: #667eea; }
        .new-cust-btn { padding: 0 10px; border: none; border-radius: 8px; background: #667eea; color: #fff; font-weight: 600; font-size: 12px; cursor: pointer; white-space: nowrap; }
        .new-cust-btn:hover { background: #5568d3; }
        .cust-results { display: none; position: absolute; left: 0; right: 0; top: 100%; z-index: 50; background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; box-shadow: 0 10px 25px rgba(0,0,0,.15); max-height: 260px; overflow-y: auto; }
        .cust-results.open { display: block; }
        .cust-opt { padding: 7px 10px; cursor: pointer; font-size: 13px; border-bottom: 1px solid #f0f0f0; display: flex; justify-content: space-between; gap: 8px; }
        .cust-opt:hover, .cust-opt.hl { background: #edf2f7; }
        .cust-opt small { color: #718096; }
        .cust-opt .bal { font-weight: 600; white-space: nowrap; }
        .bal.due { color: #c53030; }
        .selected-cust { display: none; margin-top: 6px; padding: 6px 10px; background: #ebf4ff; border-radius: 8px; font-size: 13px; justify-content: space-between; align-items: center; gap: 6px; }
        .selected-cust.show { display: flex; }
        .selected-cust button { border: none; background: transparent; color: #c53030; cursor: pointer; font-size: 14px; }
        .pay-hint { font-size: 12px; color: #718096; margin-top: 4px; }
        .credit-calc { font-size: 13px; background: #f7fafc; border-radius: 8px; padding: 8px 10px; }
        .credit-calc div { display: flex; justify-content: space-between; }
        .form-group textarea { width: 100%; padding: 10px; border: 2px solid #e2e8f0; border-radius: 8px; font-size: 14px; }
        .modal-error { color: #c53030; font-size: 13px; min-height: 16px; }
        @media (max-height: 700px) { body { padding: 10px; } .pos-container { height: calc(100vh - 20px); } .header { margin-bottom: 12px; padding-bottom: 10px; } }
    </style>
</head>
<body>
    <div class="pos-container">
        <!-- Products Section -->
        <div class="products-section">
            <div class="header">
                <h1>🍽️ Restaurant POS</h1>
                <a class="back-btn" href="dashboard.php">← Back to Dashboard</a>
            </div>
            
            <div class="search-box">
                <span class="search-icon">🔍</span>
                <input type="text" id="searchInput" placeholder="Search products...">
            </div>
            
            <div class="categories">
                <button class="category-btn active" data-category="all">
                    <span class="cat-icon">⭐</span>
                    <span>All Items</span>
                </button>
                <?php while ($category = $categories_result->fetch_assoc()): ?>
                    <?php $catIcon = $categoryIcons[(int)$category['CategoryID']] ?? '🗂️'; ?>
                    <button class="category-btn" data-category="<?php echo $category['CategoryID']; ?>">
                        <span class="cat-icon"><?php echo $catIcon; ?></span>
                        <span><?php echo htmlspecialchars($category['CategoryName']); ?></span>
                    </button>
                <?php endwhile; ?>
            </div>
            
            <div class="products-grid" id="productsGrid">
                <div class="loading">Loading products...</div>
            </div>
        </div>
        
        <!-- Cart Section -->
        <div class="cart-section">
            <div class="cart-header">
                <h2>🛒 Current Order</h2>
                <button class="quick-checkout-btn" onclick="openCheckoutModal()">Checkout</button>
            </div>
            
            <div class="customer-panel">
                <div class="pay-type">
                    <button type="button" data-type="Cash" class="active" onclick="setPaymentType('Cash')">💵 Cash</button>
                    <button type="button" data-type="Credit" onclick="setPaymentType('Credit')">📒 Credit</button>
                </div>
                <div class="cust-row">
                    <div class="cust-search">
                        <input type="text" id="customerSearch" placeholder="Customer: name or phone (blank = walk-in)" autocomplete="off">
                        <div class="cust-results" id="customerResults"></div>
                    </div>
                    <button type="button" class="new-cust-btn" onclick="openNewCustomerModal()">+ New Customer</button>
                </div>
                <div class="selected-cust" id="selectedCustomer">
                    <span id="selectedCustomerText"></span>
                    <button type="button" title="Clear customer" onclick="clearCustomer()">✕</button>
                </div>
            </div>

            <div class="cart-items" id="cartItems">
                <div class="empty-cart">
                    <div class="empty-cart-icon">🛍️</div>
                    <p>Cart is empty<br>Add items to get started</p>
                </div>
            </div>
            
            <div class="cart-summary">
                <div class="discount-input">
                    <input type="number" id="discountInput" placeholder="Discount %" min="0" max="100" step="0.01">
                    <button onclick="applyDiscount()">Apply</button>
                </div>
                
                <div class="summary-row">
                    <span>Subtotal:</span>
                    <span id="subtotal">Rs. 0.00</span>
                </div>
                <div class="summary-row" style="display:none">
                    <span>Tax (<?php echo $tax_rate; ?>%):</span>
                    <span id="tax">Rs. 0.00</span>
                </div>
                <div class="summary-row">
                    <span>Discount:</span>
                    <span id="discount">Rs. 0.00</span>
                </div>
                <div class="summary-row total">
                    <span>Total:</span>
                    <span id="total">Rs. 0.00</span>
                </div>
                
                <div class="action-buttons">
                    <button class="btn btn-checkout" onclick="openCheckoutModal()">Checkout</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Checkout Modal -->
    <div class="modal" id="checkoutModal">
        <div class="modal-content">
            <div class="modal-header">Complete Order</div>
            <div class="form-group">
                <div id="checkoutSummary" style="font-size:14px;color:#4a5568"></div>
            </div>
            <div class="form-group">
                <label id="paymentMethodLabel">Payment Method</label>
                <select id="paymentMethod">
                    <option value="Cash">Cash</option>
                    <option value="Card">Card</option>
                    <option value="Digital Wallet">Digital Wallet</option>
                </select>
            </div>
            <div class="form-group" id="walkinNameGroup">
                <label>Customer Name (Optional)</label>
                <input type="text" id="customerName" placeholder="Walk-in customer name">
            </div>
            <div id="creditGroup" style="display:none">
                <div class="form-group">
                    <label>Amount Received Now (optional)</label>
                    <input type="number" id="amountPaid" min="0" step="0.01" value="0" oninput="updateCreditCalc()">
                </div>
                <div class="form-group credit-calc" id="creditCalc"></div>
            </div>
            <div class="modal-error" id="checkoutError"></div>
            <div class="modal-buttons">
                <button class="btn-cancel" onclick="closeCheckoutModal()">Cancel</button>
                <button class="btn-confirm" id="confirmCheckoutBtn" onclick="completeCheckout()">Complete Order</button>
            </div>
        </div>
    </div>

    <!-- New Customer Modal -->
    <div class="modal" id="newCustomerModal">
        <div class="modal-content">
            <div class="modal-header">New Customer</div>
            <div class="form-group">
                <label>Name *</label>
                <input type="text" id="ncName" maxlength="150" placeholder="Customer / shop name">
            </div>
            <div class="form-group">
                <label>Phone</label>
                <input type="text" id="ncPhone" maxlength="30" placeholder="03xx-xxxxxxx">
            </div>
            <div class="form-group">
                <label>Address</label>
                <input type="text" id="ncAddress" maxlength="255" placeholder="Address / area">
            </div>
            <div class="form-group">
                <label>Opening Balance (Rs.) <small style="font-weight:400;color:#718096">amount they already owe</small></label>
                <input type="number" id="ncOpening" step="0.01" value="0">
            </div>
            <div class="modal-error" id="ncError"></div>
            <div class="modal-buttons">
                <button class="btn-cancel" onclick="closeNewCustomerModal()">Cancel</button>
                <button class="btn-confirm" id="ncSaveBtn" onclick="saveNewCustomer()">Save &amp; Select</button>
            </div>
        </div>
    </div>

    <!-- Success Message -->
    <div class="success-message" id="successMessage">
        ✓ Order completed successfully!
    </div>

    <script>
        let cart = [];
        let allProducts = [];
        const taxRate = <?php echo $tax_rate; ?>;
        let discountPercent = 0;
        let paymentType = 'Cash';
        let selectedCustomer = null;
        let checkoutBusy = false;

        function esc(s) {
            return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
        }
        function rs(n) { return 'Rs. ' + (parseFloat(n) || 0).toFixed(2); }
        function currentTotals() {
            const subtotal = cart.reduce((sum, item) => sum + (item.Price * item.quantity), 0);
            const taxAmount = subtotal * (taxRate / 100);
            const discountAmount = subtotal * (discountPercent / 100);
            const total = subtotal + taxAmount - discountAmount;
            return { subtotal, taxAmount, discountAmount, total };
        }

        // ---------- Cash / Credit ----------
        function setPaymentType(t) {
            paymentType = (t === 'Credit') ? 'Credit' : 'Cash';
            document.querySelectorAll('.pay-type button').forEach(b => b.classList.toggle('active', b.dataset.type === paymentType));
            document.getElementById('customerSearch').placeholder = paymentType === 'Credit'
                ? 'Credit sale: search customer (required)'
                : 'Customer: name or phone (blank = walk-in)';
            if (paymentType === 'Credit' && !selectedCustomer) {
                document.getElementById('customerSearch').focus();
            }
        }

        // ---------- Customer search / select ----------
        let custTimer = null;
        let custResults = [];
        let custHl = -1;
        function searchCustomers(q) {
            fetch('pos_api.php?action=searchCustomers&q=' + encodeURIComponent(q))
                .then(r => r.json())
                .then(data => {
                    custResults = (data && data.customers) || [];
                    custHl = -1;
                    renderCustomerResults(q);
                })
                .catch(err => console.error(err));
        }
        function renderCustomerResults(q) {
            const box = document.getElementById('customerResults');
            if (!custResults.length) {
                box.innerHTML = '<div class="cust-opt"><small>No customer found' + (q ? ' for "' + esc(q) + '"' : '') + '. Use + New Customer.</small></div>';
            } else {
                box.innerHTML = custResults.map((c, i) => `
                    <div class="cust-opt${i === custHl ? ' hl' : ''}" data-idx="${i}">
                        <span>${esc(c.CustomerName)}<br><small>${esc(c.Phone || '')}${c.Area ? ' · ' + esc(c.Area) : ''}</small></span>
                        <span class="bal${c.Balance > 0 ? ' due' : ''}">${rs(c.Balance)}</span>
                    </div>`).join('');
                box.querySelectorAll('.cust-opt[data-idx]').forEach(el => {
                    el.addEventListener('mousedown', e => { e.preventDefault(); selectCustomer(custResults[+el.dataset.idx]); });
                });
            }
            box.classList.add('open');
        }
        function hideCustomerResults() { document.getElementById('customerResults').classList.remove('open'); }
        function selectCustomer(c) {
            selectedCustomer = c;
            const input = document.getElementById('customerSearch');
            input.value = '';
            hideCustomerResults();
            const limit = (c.CreditLimit !== null && c.CreditLimit !== undefined) ? ' · Limit ' + rs(c.CreditLimit) : '';
            document.getElementById('selectedCustomerText').innerHTML =
                '👤 <b>' + esc(c.CustomerName) + '</b>' + (c.Phone ? ' · ' + esc(c.Phone) : '') +
                ' · Bal: <b class="bal' + (c.Balance > 0 ? ' due' : '') + '">' + rs(c.Balance) + '</b>' + esc(limit);
            document.getElementById('selectedCustomer').classList.add('show');
        }
        function clearCustomer() {
            selectedCustomer = null;
            document.getElementById('selectedCustomer').classList.remove('show');
            document.getElementById('customerSearch').value = '';
        }

        // ---------- New customer popup ----------
        function openNewCustomerModal() {
            const typed = document.getElementById('customerSearch').value.trim();
            document.getElementById('ncName').value = /^[0-9+\-\s]+$/.test(typed) ? '' : typed;
            document.getElementById('ncPhone').value = /^[0-9+\-\s]+$/.test(typed) ? typed : '';
            document.getElementById('ncAddress').value = '';
            document.getElementById('ncOpening').value = '0';
            document.getElementById('ncError').textContent = '';
            hideCustomerResults();
            document.getElementById('newCustomerModal').classList.add('active');
            setTimeout(() => document.getElementById(document.getElementById('ncName').value ? 'ncPhone' : 'ncName').focus(), 50);
        }
        function closeNewCustomerModal() { document.getElementById('newCustomerModal').classList.remove('active'); }
        function saveNewCustomer() {
            const btn = document.getElementById('ncSaveBtn');
            const err = document.getElementById('ncError');
            const payload = {
                action: 'createCustomer',
                name: document.getElementById('ncName').value.trim(),
                phone: document.getElementById('ncPhone').value.trim(),
                address: document.getElementById('ncAddress').value.trim(),
                openingBalance: parseFloat(document.getElementById('ncOpening').value) || 0
            };
            if (!payload.name) { err.textContent = 'Name is required'; return; }
            btn.disabled = true;
            fetch('pos_api.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        closeNewCustomerModal();
                        selectCustomer(data.customer);
                    } else {
                        err.textContent = data.message || 'Could not save customer';
                    }
                })
                .catch(() => { err.textContent = 'Network error, customer not saved'; })
                .finally(() => { btn.disabled = false; });
        }

        function updateCreditCalc() {
            if (!selectedCustomer) return;
            const { total } = currentTotals();
            const paid = Math.max(0, parseFloat(document.getElementById('amountPaid').value) || 0);
            const due = total - paid;
            const newBal = (parseFloat(selectedCustomer.Balance) || 0) + due;
            document.getElementById('creditCalc').innerHTML =
                '<div><span>Previous balance</span><span>' + rs(selectedCustomer.Balance) + '</span></div>' +
                '<div><span>This bill</span><span>' + rs(total) + '</span></div>' +
                '<div><span>Received now</span><span>' + rs(paid) + '</span></div>' +
                '<div style="font-weight:700"><span>New balance</span><span>' + rs(newBal) + '</span></div>';
            document.getElementById('checkoutError').textContent = paid > total + 0.001 ? 'Amount received is more than the bill total' : '';
        }
        const allTabSelector = '.category-btn[data-category="all"]';
        
        // Load all products once, then filter client-side
        function loadAllProducts() {
            const grid = document.getElementById('productsGrid');
            grid.innerHTML = '<div class="loading">Loading products...</div>';
            fetch('pos_api.php?action=getProducts')
                .then(r => r.json())
                .then(data => {
                    allProducts = data.products || [];
                    refreshCategoryTabs();
                    renderFilteredProducts();
                })
                .catch(err => {
                    console.error(err);
                    grid.innerHTML = '<div class="empty-cart"><p>Error loading products</p></div>';
                });
        }

        // Quietly reload product stock after a sale (keeps current tab / search)
        function refreshStock() {
            fetch('pos_api.php?action=getProducts')
                .then(r => r.json())
                .then(data => {
                    if (data.success && data.products) {
                        allProducts = data.products;
                        renderFilteredProducts();
                    }
                })
                .catch(() => {});
        }

        // Hide categories without products
        function refreshCategoryTabs() {
            const hasCat = new Set(allProducts.map(p => String(p.CategoryID)));
            let activeStillVisible = true;
            document.querySelectorAll('.category-btn').forEach(btn => {
                const cat = btn.dataset.category;
                if (cat === 'all') {
                    btn.style.display = 'inline-flex';
                    return;
                }
                const visible = hasCat.has(String(cat));
                btn.style.display = visible ? 'inline-flex' : 'none';
                if (!visible && btn.classList.contains('active')) {
                    activeStillVisible = false;
                }
            });
            if (!activeStillVisible) {
                const allBtn = document.querySelector(allTabSelector);
                if (allBtn) {
                    document.querySelectorAll('.category-btn').forEach(b => b.classList.remove('active'));
                    allBtn.classList.add('active');
                }
            }
        }

        function stockLabel(product) {
            if (product.Stock === null || product.Stock === undefined) return '';
            const q = parseFloat(product.Stock);
            const txt = Number.isInteger(q) ? q : q.toFixed(2);
            return `<div class="product-stock${q <= 0 ? ' out' : ''}">${txt} crates in stock</div>`;
        }

        function renderFilteredProducts() {
            const grid = document.getElementById('productsGrid');
            const activeCatBtn = document.querySelector('.category-btn.active');
            const cat = activeCatBtn ? activeCatBtn.dataset.category : 'all';
            const search = document.getElementById('searchInput').value.trim().toLowerCase();

            const filtered = allProducts.filter(p => {
                const matchesCat = (cat === 'all') || (String(p.CategoryID) === String(cat));
                const matchesSearch = search === '' || (p.ProductName || '').toLowerCase().includes(search);
                return matchesCat && matchesSearch;
            });

            if (!filtered.length) {
                grid.innerHTML = '<div class="empty-cart"><p>No products found</p></div>';
                return;
            }

            grid.innerHTML = '';
            filtered.forEach(product => {
                const card = document.createElement('div');
                card.className = 'product-card';
                card.onclick = () => addToCart(product);
                card.innerHTML = `
                    <div class="product-icon">${getIcon(product)}</div>
                    <div class="product-name">${esc(product.ProductName)}</div>
                    <div class="product-price">Rs. ${parseFloat(product.Price).toFixed(2)}</div>
                    ${stockLabel(product)}
                `;
                grid.appendChild(card);
            });
        }

        function getIcon(product) {
            const name = (product.ProductName || '').toLowerCase();
            const cat = (product.CategoryName || '').toLowerCase();
            // Egg icon scaled by size, with a size badge
            const sizes = { 'small': ['S', 18], 'medium': ['M', 23], 'xl': ['XL', 32], 'large': ['L', 27] };
            if (cat.includes('desi') || name.includes('desi')) {
                return '<span class="egg-wrap"><span class="egg desi" style="font-size:27px">🥚</span><span class="egg-badge desi-badge">🐔</span></span>';
            }
            for (const k of ['small', 'medium', 'xl', 'large']) {
                if (name.includes(k)) {
                    const [label, px] = sizes[k];
                    return `<span class="egg-wrap"><span class="egg" style="font-size:${px}px">🥚</span><span class="egg-badge">${label}</span></span>`;
                }
            }
            return '🥚';
        }
        
        // Add to cart
        function addToCart(product) {
            const existingItem = cart.find(item => item.ProductID === product.ProductID);
            if (existingItem) {
                existingItem.quantity++;
            } else {
                cart.push({
                    ProductID: product.ProductID,
                    ProductName: product.ProductName,
                    Price: parseFloat(product.Price),
                    quantity: 1
                });
            }
            updateCart();
        }
        
        // Update cart display
        function updateCart() {
            const cartItems = document.getElementById('cartItems');
            
            if (cart.length === 0) {
                cartItems.innerHTML = `
                    <div class="empty-cart">
                        <div class="empty-cart-icon">🛍️</div>
                        <p>Cart is empty<br>Add items to get started</p>
                    </div>
                `;
            } else {
                cartItems.innerHTML = '';
                cart.forEach((item, index) => {
                    const itemDiv = document.createElement('div');
                    itemDiv.className = 'cart-item';
                    itemDiv.innerHTML = `
                        <div class="item-info">
                            <div class="item-name">${esc(item.ProductName)}</div>
                            <div class="item-price">Rs. ${item.Price.toFixed(2)} each</div>
                        </div>
                        <div class="item-controls">
                            <button class="qty-btn" onclick="updateQuantity(${index}, -1)">−</button>
                            <span class="qty-display">${item.quantity}</span>
                            <button class="qty-btn" onclick="updateQuantity(${index}, 1)">+</button>
                            <button class="remove-btn" onclick="removeItem(${index})">✕</button>
                        </div>
                    `;
                    cartItems.appendChild(itemDiv);
                });
            }
            
            updateSummary();
        }
        
        // Update quantity
        function updateQuantity(index, change) {
            cart[index].quantity += change;
            if (cart[index].quantity <= 0) {
                cart.splice(index, 1);
            }
            updateCart();
        }
        
        // Remove item
        function removeItem(index) {
            cart.splice(index, 1);
            updateCart();
        }
        
        // Update summary
        function updateSummary() {
            const subtotal = cart.reduce((sum, item) => sum + (item.Price * item.quantity), 0);
            const taxAmount = subtotal * (taxRate / 100);
            const discountAmount = subtotal * (discountPercent / 100);
            const total = subtotal + taxAmount - discountAmount;
            
            document.getElementById('subtotal').textContent = 'Rs. ' + subtotal.toFixed(2);
            document.getElementById('tax').textContent = 'Rs. ' + taxAmount.toFixed(2);
            document.getElementById('discount').textContent = 'Rs. ' + discountAmount.toFixed(2);
            document.getElementById('total').textContent = 'Rs. ' + total.toFixed(2);
        }
        
        // Apply discount
        function applyDiscount() {
            const input = document.getElementById('discountInput');
            const value = parseFloat(input.value) || 0;
            discountPercent = Math.max(0, Math.min(100, value));
            input.value = discountPercent;
            updateSummary();
        }
        
        // Clear cart
        function clearCart() {
            if (cart.length > 0 && confirm('Clear all items from cart?')) {
                cart = [];
                discountPercent = 0;
                document.getElementById('discountInput').value = '';
                updateCart();
            }
        }
        
        // Open checkout modal
        function openCheckoutModal() {
            if (cart.length === 0) {
                alert('Cart is empty!');
                return;
            }
            if (paymentType === 'Credit' && !selectedCustomer) {
                alert('Credit sale needs a customer. Search or create a customer first.');
                document.getElementById('customerSearch').focus();
                return;
            }
            const { total } = currentTotals();
            const isCredit = paymentType === 'Credit';
            document.getElementById('checkoutSummary').innerHTML =
                '<b>' + (isCredit ? '📒 Credit sale' : '💵 Cash sale') + '</b> · ' +
                (selectedCustomer ? esc(selectedCustomer.CustomerName) : 'Walk-in') +
                ' · Total <b>' + rs(total) + '</b>';
            document.getElementById('walkinNameGroup').style.display = (!isCredit && !selectedCustomer) ? '' : 'none';
            document.getElementById('creditGroup').style.display = isCredit ? '' : 'none';
            document.getElementById('paymentMethodLabel').textContent = isCredit ? 'Method (for amount received)' : 'Payment Method';
            document.getElementById('amountPaid').value = '0';
            document.getElementById('checkoutError').textContent = '';
            if (isCredit) updateCreditCalc();
            document.getElementById('checkoutModal').classList.add('active');
        }
        
        // Close checkout modal
        function closeCheckoutModal() {
            document.getElementById('checkoutModal').classList.remove('active');
        }
        
        // Complete checkout
        function completeCheckout() {
            if (checkoutBusy) return;
            const paymentMethod = document.getElementById('paymentMethod').value;
            const customerName = selectedCustomer ? '' : document.getElementById('customerName').value;
            const { subtotal, taxAmount, discountAmount, total } = currentTotals();
            const isCredit = paymentType === 'Credit';
            if (isCredit && !selectedCustomer) { alert('Credit sale needs a customer.'); return; }
            const amountPaid = isCredit ? Math.max(0, parseFloat(document.getElementById('amountPaid').value) || 0) : total;
            if (isCredit && amountPaid > total + 0.001) {
                document.getElementById('checkoutError').textContent = 'Amount received is more than the bill total';
                return;
            }
            if (isCredit && selectedCustomer.CreditLimit !== null && selectedCustomer.CreditLimit !== undefined) {
                const newBal = (parseFloat(selectedCustomer.Balance) || 0) + total - amountPaid;
                if (newBal > selectedCustomer.CreditLimit &&
                    !confirm('New balance ' + rs(newBal) + ' is over the credit limit ' + rs(selectedCustomer.CreditLimit) + '. Continue?')) {
                    return;
                }
            }
            
            const orderData = {
                action: 'createInvoice',
                items: cart,
                subtotal: subtotal,
                taxAmount: taxAmount,
                discount: discountAmount,
                total: total,
                paymentMethod: paymentMethod,
                customerName: customerName,
                paymentType: paymentType,
                customerId: selectedCustomer ? selectedCustomer.CustomerID : null,
                amountPaid: amountPaid
            };
            checkoutBusy = true;
            document.getElementById('confirmCheckoutBtn').disabled = true;
            
            fetch('pos_api.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(orderData)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showSuccess();
                    closeCheckoutModal();
                    cart = [];
                    discountPercent = 0;
                    document.getElementById('discountInput').value = '';
                    document.getElementById('customerName').value = '';
                    clearCustomer();
                    setPaymentType('Cash');
                    updateCart();
                    refreshStock();
                    if (data.invoiceId) {
                        window.open('receipt.php?id=' + data.invoiceId, '_blank');
                    }
                } else {
                    alert('Error: ' + (data.message || 'Failed to create invoice'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error processing order');
            })
            .finally(() => {
                checkoutBusy = false;
                document.getElementById('confirmCheckoutBtn').disabled = false;
            });
        }
        
        // Show success message
        function showSuccess() {
            const msg = document.getElementById('successMessage');
            msg.style.display = 'block';
            setTimeout(() => {
                msg.style.display = 'none';
            }, 3000);
        }
        
        // Category filter
        document.querySelectorAll('.category-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                document.querySelectorAll('.category-btn').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                renderFilteredProducts();
            });
        });
        
        // Search
        let searchTimeout;
        document.getElementById('searchInput').addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                renderFilteredProducts();
            }, 300);
        });
        
        // Customer search box
        const custInput = document.getElementById('customerSearch');
        custInput.addEventListener('input', function() {
            clearTimeout(custTimer);
            const q = this.value.trim();
            custTimer = setTimeout(() => searchCustomers(q), 250);
        });
        custInput.addEventListener('focus', function() { searchCustomers(this.value.trim()); });
        custInput.addEventListener('blur', () => setTimeout(hideCustomerResults, 150));
        custInput.addEventListener('keydown', function(e) {
            const box = document.getElementById('customerResults');
            if (!box.classList.contains('open') || !custResults.length) return;
            if (e.key === 'ArrowDown') { custHl = Math.min(custResults.length - 1, custHl + 1); renderCustomerResults(this.value); e.preventDefault(); }
            else if (e.key === 'ArrowUp') { custHl = Math.max(0, custHl - 1); renderCustomerResults(this.value); e.preventDefault(); }
            else if (e.key === 'Enter') { selectCustomer(custResults[custHl >= 0 ? custHl : 0]); e.preventDefault(); }
            else if (e.key === 'Escape') { hideCustomerResults(); }
        });

        // Initial load
        loadAllProducts();
    </script>
</body>
</html>