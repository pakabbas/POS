<?php
define('ALLOW_GUEST', true);
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/db.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');
// $role = $_SESSION['role'] ?? 'Admin';
// if ($role !== 'Admin') {
//     header("Location: pos.php");
//     exit();
// }

$alert = null;

// Handle create/update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = isset($_POST['id']) ? (int) $_POST['id'] : null;
    $username = trim($_POST['username'] ?? '');
    $fullname = trim($_POST['fullname'] ?? '');
    $roleVal = trim($_POST['role'] ?? '');
    $password = $_POST['password'] ?? '';
    $isActive = isset($_POST['is_active']) ? 1 : 0;

    if ($username === '') {
        $alert = ['type' => 'danger', 'msg' => 'Username is required.'];
    } elseif (!$id && $password === '') {
        $alert = ['type' => 'danger', 'msg' => 'Password is required for new users.'];
    } else {
        try {
            if ($id) {
                // Update
                if ($password !== '') {
                    $hash = password_hash($password, PASSWORD_BCRYPT);
                    $stmt = $conn->prepare("UPDATE Users SET UserName=?, FullName=?, Role=?, PasswordHash=?, IsActive=? WHERE UserID=?");
                    $stmt->bind_param('ssssii', $username, $fullname, $roleVal, $hash, $isActive, $id);
                } else {
                    $stmt = $conn->prepare("UPDATE Users SET UserName=?, FullName=?, Role=?, IsActive=? WHERE UserID=?");
                    $stmt->bind_param('sssii', $username, $fullname, $roleVal, $isActive, $id);
                }
                $stmt->execute();
                $alert = ['type' => 'success', 'msg' => 'User updated.'];
            } else {
                // Create
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $stmt = $conn->prepare("INSERT INTO Users (UserName, PasswordHash, FullName, Role, IsActive) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param('ssssi', $username, $hash, $fullname, $roleVal, $isActive);
                $stmt->execute();
                $alert = ['type' => 'success', 'msg' => 'User created.'];
            }
        } catch (Throwable $e) {
            $alert = ['type' => 'danger', 'msg' => 'Error: ' . $e->getMessage()];
        }
    }
}

// Handle delete
if (($_GET['delete'] ?? '') !== '') {
    $delId = (int) $_GET['delete'];
    try {
        $stmt = $conn->prepare("DELETE FROM Users WHERE UserID = ?");
        $stmt->bind_param('i', $delId);
        $stmt->execute();
        $alert = ['type' => 'success', 'msg' => 'User deleted.'];
    } catch (Throwable $e) {
        $alert = ['type' => 'danger', 'msg' => 'Cannot delete user (in use).'];
    }
}

// Load edit record
$editData = null;
if (($_GET['edit'] ?? '') !== '') {
    $editId = (int) $_GET['edit'];
    $stmt = $conn->prepare("SELECT * FROM Users WHERE UserID = ?");
    $stmt->bind_param('i', $editId);
    $stmt->execute();
    $editData = $stmt->get_result()->fetch_assoc();
}

// Fetch all users
$rows = $conn->query("SELECT UserID, UserName, FullName, Role, IsActive, CreatedAt FROM Users ORDER BY UserID DESC")->fetch_all(MYSQLI_ASSOC);
?>

<h2 class="mb-4 fw-bold">Users</h2>

<?php if ($alert): ?>
  <div class="alert alert-<?= $alert['type'] ?>"><?= htmlspecialchars($alert['msg']) ?></div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-4">
    <div class="card shadow-sm">
      <div class="card-header bg-white fw-semibold"><?= $editData ? 'Edit User' : 'Add User' ?></div>
      <div class="card-body">
        <form method="post">
          <?php if ($editData): ?>
            <input type="hidden" name="id" value="<?= htmlspecialchars($editData['UserID']) ?>">
          <?php endif; ?>
          <div class="mb-3">
            <label class="form-label">Username</label>
            <input type="text" name="username" class="form-control" value="<?= htmlspecialchars($editData['UserName'] ?? '') ?>" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Full Name</label>
            <input type="text" name="fullname" class="form-control" value="<?= htmlspecialchars($editData['FullName'] ?? '') ?>">
          </div>
          <div class="mb-3">
            <label class="form-label">Role</label>
            <select name="role" class="form-select">
              <option value="Admin" <?= (($editData['Role'] ?? '') === 'Admin') ? 'selected' : '' ?>>Admin</option>
              <option value="Cashier" <?= (($editData['Role'] ?? '') === 'Cashier') ? 'selected' : '' ?>>Cashier</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label"><?= $editData ? 'New Password (optional)' : 'Password' ?></label>
            <input type="password" name="password" class="form-control" <?= $editData ? '' : 'required' ?> autocomplete="new-password">
            <?php if ($editData): ?>
              <small class="text-muted">Leave blank to keep current password.</small>
            <?php endif; ?>
          </div>
          <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" name="is_active" id="isActive" <?= ($editData['IsActive'] ?? 1) ? 'checked' : '' ?>>
            <label class="form-check-label" for="isActive">Active</label>
          </div>
          <div class="d-flex gap-2">
            <button class="btn btn-primary" type="submit"><?= $editData ? 'Update' : 'Add' ?></button>
            <?php if ($editData): ?>
              <a class="btn btn-secondary" href="users.php">Cancel</a>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </div>
  </div>
  <div class="col-lg-8">
    <div class="card shadow-sm">
      <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <span class="fw-semibold">All Users</span>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-striped mb-0 align-middle">
            <thead class="table-light">
              <tr>
                <th>ID</th>
                <th>Username</th>
                <th>Name</th>
                <th>Role</th>
                <th>Status</th>
                <th>Created</th>
                <th class="text-end">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $row): ?>
                <tr>
                  <td><?= htmlspecialchars($row['UserID']) ?></td>
                  <td><?= htmlspecialchars($row['UserName']) ?></td>
                  <td><?= htmlspecialchars($row['FullName'] ?? '') ?></td>
                  <td><?= htmlspecialchars($row['Role'] ?? '') ?></td>
                  <td><span class="badge bg-<?= $row['IsActive'] ? 'success' : 'secondary' ?>"><?= $row['IsActive'] ? 'Active' : 'Inactive' ?></span></td>
                  <td><?= htmlspecialchars(substr($row['CreatedAt'], 0, 10)) ?></td>
                  <td class="text-end">
                    <a class="btn btn-sm btn-outline-primary" href="users.php?edit=<?= $row['UserID'] ?>">Edit</a>
                    <a class="btn btn-sm btn-outline-danger" href="users.php?delete=<?= $row['UserID'] ?>" onclick="return confirm('Delete this user?')">Delete</a>
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

