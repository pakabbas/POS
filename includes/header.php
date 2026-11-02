<?php
session_start();
if (!defined('ALLOW_GUEST') && !isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}
$role = $_SESSION['role'] ?? 'Admin';
$infoFile = __DIR__ . '/../restaurantInfo.txt';
$bizName = 'POS';
if (file_exists($infoFile)) {
    $lines = array_values(array_filter(array_map('trim', file($infoFile)), 'strlen'));
    $bizName = $lines[0] ?? $bizName;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>POS Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="style.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="bg-app">

<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
  <div class="container-fluid">
    <a class="navbar-brand fw-bold" href="dashboard.php"><?= htmlspecialchars($bizName) ?> - Dashboard</a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMenu">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="navMenu">
      <ul class="navbar-nav ms-auto">
        <?php if ($role === 'Admin'): ?>
          <li class="nav-item"><a class="nav-link" href="dashboard.php">Dashboard</a></li>
        <?php endif; ?>
        <li class="nav-item"><a class="nav-link" href="pos.php">POS</a></li>
        <li class="nav-item"><a class="nav-link" href="categories.php">Categories</a></li>
        <li class="nav-item"><a class="nav-link" href="products.php">Products</a></li>
        <li class="nav-item"><a class="nav-link" href="settings.php">Settings</a></li>
        <?php if ($role === 'Admin'): ?>
          <li class="nav-item"><a class="nav-link" href="Expense.php">Expenses</a></li>
          <li class="nav-item"><a class="nav-link" href="users.php">Users</a></li>
          <li class="nav-item"><a class="nav-link" href="reports.php">Reports</a></li>
          <li class="nav-item"><a class="nav-link" href="DaySales.php">Day Sales</a></li>
        <?php endif; ?>
        <li class="nav-item"><a class="nav-link" href="logout.php">Logout</a></li>
      </ul>
    </div>
  </div>
</nav>
<div class="container mt-4">
