<?php
session_start();
if (!defined('ALLOW_GUEST') && !isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}
$role = $_SESSION['role'] ?? 'Admin';
require_once __DIR__ . '/business_info.php';
$bizInfo = business_info();
$bizName = $bizInfo['name'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($bizName) ?></title>
    <link rel="icon" type="image/svg+xml" href="assets/logo.svg">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="style.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="bg-app">

<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
  <div class="container-fluid">
    <a class="navbar-brand fw-bold d-flex align-items-center gap-2" href="dashboard.php"><img src="assets/logo.svg" alt="" width="30" height="30"><?= htmlspecialchars($bizName) ?></a>
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
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">Customer</a>
          <ul class="dropdown-menu dropdown-menu-end">
            <li><a class="dropdown-item" href="customers.php">Manage Customers</a></li>
            <li><a class="dropdown-item" href="customer_ledger.php">Ledger</a></li>
          </ul>
        </li>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">Vendors</a>
          <ul class="dropdown-menu dropdown-menu-end">
            <li><a class="dropdown-item" href="vendors.php">Vendors</a></li>
            <li><a class="dropdown-item" href="purchases.php">Purchases (Stock In)</a></li>
            <li><a class="dropdown-item" href="inventory.php">Inventory</a></li>
            <li><a class="dropdown-item" href="vendor_ledger.php">Vendor Ledger</a></li>
            <li><a class="dropdown-item" href="vendor_payouts.php">Vendor Payouts</a></li>
            <?php if ($role === 'Admin'): ?>
              <li><hr class="dropdown-divider"></li>
              <li><a class="dropdown-item" href="cash_summary.php">Cash Summary</a></li>
            <?php endif; ?>
          </ul>
        </li>
        <?php if ($role === 'Admin'): ?>
          <li class="nav-item"><a class="nav-link" href="Expense.php">Expenses</a></li>
          <li class="nav-item"><a class="nav-link" href="logs.php">Logs</a></li>
          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">Reports</a>
            <ul class="dropdown-menu dropdown-menu-end">
              <li><a class="dropdown-item" href="reports.php">All Reports</a></li>
              <li><a class="dropdown-item" href="DaySales.php">Day Sales</a></li>
            </ul>
          </li>
        <?php endif; ?>
        <?php if ($role === 'Admin'): ?>
          <li class="nav-item"><a class="nav-link" href="users.php" title="Users" aria-label="Users"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 16 16"><path d="M7 14s-1 0-1-1 1-4 5-4 5 3 5 4-1 1-1 1zm4-6a3 3 0 1 0 0-6 3 3 0 0 0 0 6m-5.784 6A2.24 2.24 0 0 1 5 13c0-1.355.68-2.75 1.936-3.72A6.3 6.3 0 0 0 5 9c-4 0-5 3-5 4s1 1 1 1zM4.5 8a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5"/></svg><span class="d-lg-none ms-2">Users</span></a></li>
        <?php endif; ?>
        <li class="nav-item"><a class="nav-link" href="settings.php" title="Settings" aria-label="Settings"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 16 16"><path d="M9.405 1.05c-.413-1.4-2.397-1.4-2.81 0l-.1.34a1.464 1.464 0 0 1-2.105.872l-.31-.17c-1.283-.698-2.686.705-1.987 1.987l.169.311c.446.82.023 1.841-.872 2.105l-.34.1c-1.4.413-1.4 2.397 0 2.81l.34.1a1.464 1.464 0 0 1 .872 2.105l-.17.31c-.698 1.283.705 2.686 1.987 1.987l.311-.169a1.464 1.464 0 0 1 2.105.872l.1.34c.413 1.4 2.397 1.4 2.81 0l.1-.34a1.464 1.464 0 0 1 2.105-.872l.31.17c1.283.698 2.686-.705 1.987-1.987l-.169-.311a1.464 1.464 0 0 1 .872-2.105l.34-.1c1.4-.413 1.4-2.397 0-2.81l-.34-.1a1.464 1.464 0 0 1-.872-2.105l.17-.31c.698-1.283-.705-2.686-1.987-1.987l-.311.169a1.464 1.464 0 0 1-2.105-.872zM8 10.93a2.929 2.929 0 1 1 0-5.86 2.929 2.929 0 0 1 0 5.858z"/></svg><span class="d-lg-none ms-2">Settings</span></a></li>
        <li class="nav-item"><a class="nav-link" href="logout.php" title="Logout" aria-label="Logout"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 16 16"><path fill-rule="evenodd" d="M10 12.5a.5.5 0 0 1-.5.5h-8a.5.5 0 0 1-.5-.5v-9a.5.5 0 0 1 .5-.5h8a.5.5 0 0 1 .5.5v2a.5.5 0 0 0 1 0v-2A1.5 1.5 0 0 0 9.5 2h-8A1.5 1.5 0 0 0 0 3.5v9A1.5 1.5 0 0 0 1.5 14h8a1.5 1.5 0 0 0 1.5-1.5v-2a.5.5 0 0 0-1 0z"/><path fill-rule="evenodd" d="M15.854 8.354a.5.5 0 0 0 0-.708l-3-3a.5.5 0 0 0-.708.708L14.293 7.5H5.5a.5.5 0 0 0 0 1h8.793l-2.147 2.146a.5.5 0 0 0 .708.708z"/></svg><span class="d-lg-none ms-2">Logout</span></a></li>
      </ul>
    </div>
  </div>
</nav>
<div class="container mt-4">
