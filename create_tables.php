<?php
// Recreate database tables using db.txt definitions and includes/db.php connection.
// Usage: php create_tables.php

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require __DIR__ . '/includes/db.php';
$conn->set_charset('utf8mb4');

$schemaFile = __DIR__ . '/db.txt';
if (!file_exists($schemaFile)) {
    fwrite(STDERR, "Schema file db.txt not found.\n");
    exit(1);
}

$sql = trim(file_get_contents($schemaFile));
if ($sql === '') {
    fwrite(STDERR, "Schema file db.txt is empty.\n");
    exit(1);
}

// Drop in dependency-safe order, then recreate.
$drops = [
    'DROP TABLE IF EXISTS InvoiceItems',
    'DROP TABLE IF EXISTS Payments',
    'DROP TABLE IF EXISTS Invoices',
    'DROP TABLE IF EXISTS Products',
    'DROP TABLE IF EXISTS Categories',
    'DROP TABLE IF EXISTS Taxes',
    'DROP TABLE IF EXISTS Users',
    'DROP TABLE IF EXISTS Expenses',
];

$fullSql = "SET FOREIGN_KEY_CHECKS=0;\n" .
           implode(";\n", $drops) . ";\n" .
           $sql . "\nSET FOREIGN_KEY_CHECKS=1;";

try {
    if (!$conn->multi_query($fullSql)) {
        throw new RuntimeException("Multi query failed to start");
    }
    // Flush results
    do {
        if ($result = $conn->store_result()) {
            $result->free();
        }
    } while ($conn->more_results() && $conn->next_result());

    echo "Tables recreated successfully.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Error recreating tables: " . $e->getMessage() . "\n");
    exit(1);
}

