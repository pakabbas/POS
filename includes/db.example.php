<?php
// Copy this file to includes/db.php and fill in the real values.
// includes/db.php is git-ignored so credentials never reach the repo.
$servername = "DB_HOST";
$username = "DB_USER";
$password = "DB_PASSWORD";
$dbname = "DB_NAME";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}
?>
