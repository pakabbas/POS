<?php
require __DIR__ . '/includes/header.php';

$infoFile = __DIR__ . '/restaurantInfo.txt';
$name = $tagline = $phone = $address = '';
$role = $_SESSION['role'] ?? 'Admin';
if ($role !== 'Admin' && $role !== 'Cashier') {
    header("Location: pos.php");
    exit();
}

// Read existing info
$bi = business_info();
$name = $bi['name'];
$tagline = $bi['tagline'];
$phone = $bi['phone'];
$address = $bi['address'];

$saved = false;
$savedLogo = false;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $tagline = trim($_POST['tagline'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');

    if ($name === '') {
        $error = 'Business name is required.';
    } else {
        $data = [$name, $tagline, $phone, $address];
        $content = implode(PHP_EOL, $data) . PHP_EOL;
        if (@file_put_contents($infoFile, $content) !== false) {
            $saved = true;
        } else {
            $error = 'Failed to save settings.';
        }
    }

}
?>

<h2 class="mb-3 fw-bold">Settings</h2>
<p class="text-muted mb-4">Configure business info and reports.</p>

<?php if ($saved || $savedLogo): ?>
  <div class="alert alert-success">
    <?php
      $msgs = [];
      if ($saved) $msgs[] = 'Business info saved';
      if ($savedLogo) $msgs[] = 'Logo updated';
      echo htmlspecialchars(implode(' & ', $msgs)) . '.';
    ?>
  </div>
<?php elseif ($error): ?>
  <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="card shadow-sm mb-4">
  <div class="card-body">
    <h5 class="fw-semibold mb-3">Business Info</h5>
    <form method="post" class="row g-3">
      <div class="col-md-6">
        <label class="form-label">Name *</label>
        <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($name) ?>" required>
      </div>
      <div class="col-md-6">
        <label class="form-label">Tagline</label>
        <input type="text" name="tagline" class="form-control" value="<?= htmlspecialchars($tagline) ?>">
      </div>
      <div class="col-md-6">
        <label class="form-label">Phone</label>
        <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($phone) ?>">
      </div>
      <div class="col-md-6">
        <label class="form-label">Address</label>
        <input type="text" name="address" class="form-control" value="<?= htmlspecialchars($address) ?>">
      </div>
      <div class="col-md-6">
        <label class="form-label">Logo</label>
        <div><img src="assets/logo.svg" alt="" width="40" height="40"> <small class="text-muted ms-2">Fixed app logo (assets/logo.svg)</small></div>
      </div>
      <div class="col-12">
        <button class="btn btn-primary">Save</button>
      </div>
    </form>
  </div>
</div>

<div class="card shadow-sm">
  <div class="card-body">
    <h5 class="fw-semibold mb-3">Reports & Tools</h5>
    <div class="d-flex flex-column gap-2">
      <a class="btn btn-outline-secondary" href="DaySales.php">Day Sales Summary (Printable)</a>
      <!-- Future settings links can go here -->
    </div>
  </div>
</div>

</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
