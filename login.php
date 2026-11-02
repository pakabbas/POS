<?php
session_start();
require __DIR__ . '/includes/db.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');
register_shutdown_function(function() use ($conn) {
    if ($conn instanceof mysqli) {
        $conn->close();
    }
});

$error = null;
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $user = trim($_POST['username'] ?? '');
    $pass = $_POST['password'] ?? '';

    if ($user === '' || $pass === '') {
        $error = "Username and password are required.";
    } else {
        $stmt = $conn->prepare("SELECT UserID, UserName, PasswordHash, IsActive FROM Users WHERE UserName = ? LIMIT 1");
        $stmt->bind_param('s', $user);
        $stmt->execute();
        $dbUser = $stmt->get_result()->fetch_assoc();

        if ($dbUser && (int)$dbUser['IsActive'] === 1) {
            $stored = $dbUser['PasswordHash'] ?? '';
            $valid = password_verify($pass, $stored);
            if ($valid) {
                session_regenerate_id(true);
                $_SESSION['username'] = $dbUser['UserName'];
                $_SESSION['user_id'] = $dbUser['UserID'];
                $_SESSION['role'] = $dbUser['Role'] ?? 'Admin';
                header("Location: dashboard.php");
                exit();
            }
        } else {
            // Bootstrap a default admin if no users exist yet
            $countRes = $conn->query("SELECT COUNT(*) AS cnt FROM Users");
            $cnt = (int) ($countRes->fetch_assoc()['cnt'] ?? 0);
            if ($cnt === 0 && $user === 'admin' && $pass === 'Admin@123') {
                $hash = password_hash($pass, PASSWORD_BCRYPT);
                $ins = $conn->prepare("INSERT INTO Users (UserName, PasswordHash, FullName, Role, IsActive) VALUES ('admin', ?, 'Administrator', 'Admin', 1)");
                $ins->bind_param('s', $hash);
                $ins->execute();
                session_regenerate_id(true);
                $_SESSION['username'] = 'admin';
                $_SESSION['user_id'] = $conn->insert_id;
                $_SESSION['role'] = 'Admin';
                header("Location: dashboard.php");
                exit();
            }
        }
        $error = "Invalid credentials!";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>POS Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="style.css" rel="stylesheet">
</head>
<body>
<?php
// Load restaurant info for display
$infoFile = __DIR__ . '/restaurantInfo.txt';
$bizName = 'POS';
$tagline = '';
$phone = '';
$address = '';
if (file_exists($infoFile)) {
    $infoLines = array_values(array_filter(array_map('trim', file($infoFile)), 'strlen'));
    $bizName = $infoLines[0] ?? $bizName;
    $tagline = $infoLines[1] ?? $tagline;
    $phone = $infoLines[2] ?? $phone;
    $address = $infoLines[3] ?? $address;
}
?>
<div class="auth-page">
<div class="card shadow-lg p-4 auth-card">
    <?php if (file_exists(__DIR__ . '/Logo.jpg')): ?>
      <img src="Logo.jpg" alt="Logo" class="login-logo">
    <?php endif; ?>
    <!-- <h4 class="text-center mb-1"><?= htmlspecialchars($bizName) ?></h4> -->
    <!-- <?php if ($tagline): ?><div class="text-center text-muted small mb-2"><?= htmlspecialchars($tagline) ?></div><?php endif; ?> -->
    <!-- <?php if ($phone): ?><div class="text-center text-muted small mb-3"><?= htmlspecialchars($phone) ?></div><?php endif; ?> -->
    <h5 class="text-center mb-3 auth-title">Admin Login</h5>
    <?php if(isset($error)): ?>
        <div class="alert alert-danger py-2"><?= $error ?></div>
    <?php endif; ?>
    <form method="post">
        <div class="mb-3">
            <label class="form-label">Username</label>
            <input type="text" name="username" class="form-control" required autofocus>
        </div>
        <div class="mb-3">
            <label class="form-label">Password</label>
            <input type="password" name="password" class="form-control" required>
        </div>
        <button class="btn btn-primary w-100">Login</button>
    </form>
    <div class="info-footer auth-footer small mt-3 text-center">
      <?php if ($address): ?>
        <div><?= htmlspecialchars($address) ?></div>
      <?php endif; ?>
      <div class="mt-2 fw-semibold">
        Software Developed by
        <a href="https://btkdeals.com" target="_blank" rel="noreferrer" class="text-decoration-none fw-bold">BTKDeals.com</a>
      </div>
      <div class="auth-contact">
        <span class="dot">🟢</span>
        <a href="https://wa.me/923052848987" target="_blank" rel="noreferrer" class="text-decoration-none fw-semibold">
          WhatsApp: +92 305 2848987
        </a>
      </div>
    </div>
</div>
</div>
</body>
</html>
