<?php
// Export MySQL schema (tables/views) to stdout using includes/db.php connection.
// Usage: php dump_schema.php > db.txt

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    require __DIR__ . '/includes/db.php';
    $conn->set_charset('utf8mb4');

    $result = $conn->query('SHOW FULL TABLES');
    $items = [];
    while ($row = $result->fetch_array(MYSQLI_NUM)) {
        $items[] = ['name' => $row[0], 'type' => strtoupper($row[1])];
    }

    $output = [];
    foreach ($items as $item) {
        if ($item['type'] === 'BASE TABLE') {
            $create = $conn->query("SHOW CREATE TABLE `{$item['name']}`")->fetch_array(MYSQLI_NUM)[1];
            $output[] = $create . ";\n";
        } elseif ($item['type'] === 'VIEW') {
            $create = $conn->query("SHOW CREATE VIEW `{$item['name']}`")->fetch_array(MYSQLI_NUM)[1];
            $output[] = $create . ";\n";
        }
    }

    echo implode("\n", $output);
} catch (Throwable $e) {
    fwrite(STDERR, 'Schema export failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

