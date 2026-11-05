<?php
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/db.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');
$role = $_SESSION['role'] ?? 'Admin';
if ($role !== 'Admin' && $role !== 'Cashier') {
    header("Location: pos.php");
    exit();
}

$alert = null;

// Fetch categories for dropdown
$categories = $conn->query("SELECT CategoryID, CategoryName FROM Categories WHERE IsActive = 1 ORDER BY CategoryName")->fetch_all(MYSQLI_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $categoryId = $_POST['category_id'] !== '' ? (int) $_POST['category_id'] : null;
    $price = isset($_POST['price']) ? (float) $_POST['price'] : 0;
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    $sortOrder = isset($_POST['sort_order']) && $_POST['sort_order'] !== '' ? (int) $_POST['sort_order'] : 100;
    $id = isset($_POST['id']) ? (int) $_POST['id'] : null;

    if ($name === '' || $price < 0) {
        $alert = ['type' => 'danger', 'msg' => 'Name is required and price cannot be negative.'];
    } else {
        try {
            if ($id) {
                $stmt = $conn->prepare("UPDATE Products SET ProductName = ?, CategoryID = ?, Price = ?, SortOrder = ?, IsActive = ? WHERE ProductID = ?");
                $stmt->bind_param('sidiii', $name, $categoryId, $price, $sortOrder, $isActive, $id);
                $stmt->execute();
                $alert = ['type' => 'success', 'msg' => 'Product updated.'];
            } else {
                $stmt = $conn->prepare("INSERT INTO Products (ProductName, CategoryID, Price, SortOrder, IsActive) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param('sidii', $name, $categoryId, $price, $sortOrder, $isActive);
                $stmt->execute();
                $alert = ['type' => 'success', 'msg' => 'Product added.'];
            }
        } catch (Throwable $e) {
            $alert = ['type' => 'danger', 'msg' => 'Error: ' . $e->getMessage()];
        }
    }
}

if (($_GET['delete'] ?? '') !== '') {
    $delId = (int) $_GET['delete'];
    try {
        $stmt = $conn->prepare("DELETE FROM Products WHERE ProductID = ?");
        $stmt->bind_param('i', $delId);
        $stmt->execute();
        $alert = ['type' => 'success', 'msg' => 'Product deleted.'];
    } catch (Throwable $e) {
        $alert = ['type' => 'danger', 'msg' => 'Cannot delete product (likely in use).'];
    }
}

$editData = null;
if (($_GET['edit'] ?? '') !== '') {
    $editId = (int) $_GET['edit'];
    $stmt = $conn->prepare("SELECT * FROM Products WHERE ProductID = ?");
    $stmt->bind_param('i', $editId);
    $stmt->execute();
    $editData = $stmt->get_result()->fetch_assoc();
}

$rows = $conn->query("
    SELECT p.ProductID, p.ProductName, p.Price, p.SortOrder, p.IsActive, c.CategoryName
    FROM Products p
    LEFT JOIN Categories c ON p.CategoryID = c.CategoryID
    ORDER BY p.SortOrder, p.ProductName
")->fetch_all(MYSQLI_ASSOC);
?>

<h2 class="mb-4 fw-bold">Products</h2>

<?php if ($alert): ?>
  <div class="alert alert-<?= $alert['type'] ?>"><?= htmlspecialchars($alert['msg']) ?></div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-4">
    <div class="card shadow-sm">
      <div class="card-header bg-white fw-semibold"><?= $editData ? 'Edit Product' : 'Add Product' ?></div>
      <div class="card-body">
        <form method="post">
          <?php if ($editData): ?>
            <input type="hidden" name="id" value="<?= htmlspecialchars($editData['ProductID']) ?>">
          <?php endif; ?>
          <div class="mb-3">
            <label class="form-label">Name</label>
            <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($editData['ProductName'] ?? '') ?>" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Category</label>
            <select name="category_id" class="form-select">
              <option value="">Unassigned</option>
              <?php foreach ($categories as $cat): ?>
                <option value="<?= $cat['CategoryID'] ?>" <?= isset($editData['CategoryID']) && (int)$editData['CategoryID'] === (int)$cat['CategoryID'] ? 'selected' : '' ?>>
                  <?= htmlspecialchars($cat['CategoryName']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Price (Rs. per peti)</label>
            <input type="number" name="price" min="0" step="0.01" class="form-control" value="<?= htmlspecialchars($editData['Price'] ?? '') ?>" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Sort Order <small class="text-muted">(lower shows first on POS)</small></label>
            <input type="number" name="sort_order" step="1" class="form-control" value="<?= htmlspecialchars($editData['SortOrder'] ?? 100) ?>">
          </div>
          <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" name="is_active" id="isActive" <?= ($editData['IsActive'] ?? 1) ? 'checked' : '' ?>>
            <label class="form-check-label" for="isActive">Active</label>
          </div>
          <div class="d-flex gap-2">
            <button class="btn btn-primary" type="submit"><?= $editData ? 'Update' : 'Add' ?></button>
            <?php if ($editData): ?>
              <a class="btn btn-secondary" href="products.php">Cancel</a>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </div>
  </div>
  <div class="col-lg-8">
    <div class="card shadow-sm">
      <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <span class="fw-semibold">All Products</span>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-striped mb-0 align-middle">
            <thead class="table-light">
              <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Category</th>
                <th>Price / peti</th>
                <th>Order</th>
                <th>Status</th>
                <th class="text-end">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $row): ?>
                <tr>
                  <td><?= htmlspecialchars($row['ProductID']) ?></td>
                  <td><?= htmlspecialchars($row['ProductName']) ?></td>
                  <td><?= htmlspecialchars($row['CategoryName'] ?? '—') ?></td>
                  <td>Rs. <?= number_format($row['Price'], 2) ?></td>
                  <td><?= (int)$row['SortOrder'] ?></td>
                  <td><span class="badge bg-<?= $row['IsActive'] ? 'success' : 'secondary' ?>"><?= $row['IsActive'] ? 'Active' : 'Inactive' ?></span></td>
                  <td class="text-end">
                    <a class="btn btn-sm btn-outline-primary" href="products.php?edit=<?= $row['ProductID'] ?>">Edit</a>
                    <a class="btn btn-sm btn-outline-success" href="inventory.php?product=<?= (int)$row['ProductID'] ?>">Manage Inventory</a>
                    <a class="btn btn-sm btn-outline-danger" href="products.php?delete=<?= $row['ProductID'] ?>" onclick="return confirm('Delete this product?')">Delete</a>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

