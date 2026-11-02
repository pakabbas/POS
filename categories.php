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

// Create or update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $desc = trim($_POST['description'] ?? '');
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    $id = isset($_POST['id']) ? (int) $_POST['id'] : null;

    if ($name === '') {
        $alert = ['type' => 'danger', 'msg' => 'Category name is required.'];
    } else {
        try {
            if ($id) {
                $stmt = $conn->prepare("UPDATE Categories SET CategoryName = ?, Description = ?, IsActive = ? WHERE CategoryID = ?");
                $stmt->bind_param('ssii', $name, $desc, $isActive, $id);
                $stmt->execute();
                $alert = ['type' => 'success', 'msg' => 'Category updated.'];
            } else {
                $stmt = $conn->prepare("INSERT INTO Categories (CategoryName, Description, IsActive) VALUES (?, ?, ?)");
                $stmt->bind_param('ssi', $name, $desc, $isActive);
                $stmt->execute();
                $alert = ['type' => 'success', 'msg' => 'Category added.'];
            }
        } catch (Throwable $e) {
            $alert = ['type' => 'danger', 'msg' => 'Error: ' . $e->getMessage()];
        }
    }
}

// Delete
if (($_GET['delete'] ?? '') !== '') {
    $delId = (int) $_GET['delete'];
    try {
        $stmt = $conn->prepare("DELETE FROM Categories WHERE CategoryID = ?");
        $stmt->bind_param('i', $delId);
        $stmt->execute();
        $alert = ['type' => 'success', 'msg' => 'Category deleted.'];
    } catch (Throwable $e) {
        $alert = ['type' => 'danger', 'msg' => 'Cannot delete category (likely in use).'];
    }
}

// Single fetch for edit
$editData = null;
if (($_GET['edit'] ?? '') !== '') {
    $editId = (int) $_GET['edit'];
    $stmt = $conn->prepare("SELECT * FROM Categories WHERE CategoryID = ?");
    $stmt->bind_param('i', $editId);
    $stmt->execute();
    $editData = $stmt->get_result()->fetch_assoc();
}

$rows = $conn->query("SELECT CategoryID, CategoryName, Description, IsActive FROM Categories ORDER BY CategoryName")->fetch_all(MYSQLI_ASSOC);
?>

<h2 class="mb-4 fw-bold">Categories</h2>

<?php if ($alert): ?>
  <div class="alert alert-<?= $alert['type'] ?>"><?= htmlspecialchars($alert['msg']) ?></div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-4">
    <div class="card shadow-sm">
      <div class="card-header bg-white fw-semibold"><?= $editData ? 'Edit Category' : 'Add Category' ?></div>
      <div class="card-body">
        <form method="post">
          <?php if ($editData): ?>
            <input type="hidden" name="id" value="<?= htmlspecialchars($editData['CategoryID']) ?>">
          <?php endif; ?>
          <div class="mb-3">
            <label class="form-label">Name</label>
            <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($editData['CategoryName'] ?? '') ?>" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Description</label>
            <textarea name="description" class="form-control" rows="2"><?= htmlspecialchars($editData['Description'] ?? '') ?></textarea>
          </div>
          <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" name="is_active" id="isActive" <?= ($editData['IsActive'] ?? 1) ? 'checked' : '' ?>>
            <label class="form-check-label" for="isActive">Active</label>
          </div>
          <div class="d-flex gap-2">
            <button class="btn btn-primary" type="submit"><?= $editData ? 'Update' : 'Add' ?></button>
            <?php if ($editData): ?>
              <a class="btn btn-secondary" href="categories.php">Cancel</a>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </div>
  </div>
  <div class="col-lg-8">
    <div class="card shadow-sm">
      <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <span class="fw-semibold">All Categories</span>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-striped mb-0 align-middle">
            <thead class="table-light">
              <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Description</th>
                <th>Status</th>
                <th class="text-end">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $row): ?>
                <tr>
                  <td><?= htmlspecialchars($row['CategoryID']) ?></td>
                  <td><?= htmlspecialchars($row['CategoryName']) ?></td>
                  <td><?= htmlspecialchars($row['Description']) ?></td>
                  <td><span class="badge bg-<?= $row['IsActive'] ? 'success' : 'secondary' ?>"><?= $row['IsActive'] ? 'Active' : 'Inactive' ?></span></td>
                  <td class="text-end">
                    <a class="btn btn-sm btn-outline-primary" href="categories.php?edit=<?= $row['CategoryID'] ?>">Edit</a>
                    <a class="btn btn-sm btn-outline-danger" href="categories.php?delete=<?= $row['CategoryID'] ?>" onclick="return confirm('Delete this category?')">Delete</a>
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

