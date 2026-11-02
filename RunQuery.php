<?php
session_start();
date_default_timezone_set('Asia/Karachi');
require_once 'includes/db.php';
register_shutdown_function(function() use ($conn) {
    if ($conn instanceof mysqli) {
        $conn->close();
    }
});

$role = $_SESSION['role'] ?? '';
if ($role !== 'Admin') {
    header("Location: pos.php");
    exit();
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

$tables = [];
$tRes = $conn->query("SHOW TABLES");
while ($row = $tRes->fetch_array()) {
    $tables[] = $row[0];
}

$resultRows = [];
$columns = [];
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sql = trim($_POST['sql'] ?? '');
    // Basic single-statement guard
    if ($sql === '' || preg_match('/;\\s*;/', $sql)) {
        $error = 'Please provide a single SQL statement.';
    } else {
        try {
            $res = $conn->query($sql);
            if ($res instanceof mysqli_result) {
                $columns = array_keys($res->fetch_assoc() ?: []);
                $res->data_seek(0);
                while ($row = $res->fetch_assoc()) {
                    $resultRows[] = $row;
                }
                $message = 'Query executed. Rows returned: ' . count($resultRows);
            } else {
                $message = 'Query executed. Affected rows: ' . $conn->affected_rows;
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RunQuery Playground (Admin)</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #0f172a; color: #e2e8f0; font-family: 'Inter', sans-serif; }
        .container { max-width: 1200px; }
        .card { background: #111827; border: 1px solid #1f2937; }
        .table thead { background: #1f2937; color: #e5e7eb; }
        .table tbody tr:nth-child(odd) { background: #0b1220; }
        .btn-table { background: #1e293b; color: #e2e8f0; border: 1px solid #334155; }
        .btn-table:hover { background: #334155; }
        textarea { background: #0b1220; color: #e2e8f0; border: 1px solid #334155; }
    </style>
</head>
<body class="py-4">
<div class="container">
    <div class="d-flex align-items-center mb-3">
        <h3 class="me-3 mb-0">RunQuery Playground</h3>
        <span class="badge bg-danger">Admin Only</span>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <div class="mb-2 fw-semibold text-uppercase small text-muted">Tables</div>
            <div class="d-flex flex-wrap gap-2">
                <?php foreach ($tables as $tbl): ?>
                    <button type="button" class="btn btn-sm btn-table" onclick="setQuery('SELECT * FROM `<?= htmlspecialchars($tbl, ENT_QUOTES) ?>` LIMIT 50;')">
                        <?= htmlspecialchars($tbl) ?>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <form method="POST">
                <label class="form-label fw-semibold">SQL</label>
                <textarea name="sql" id="sql" rows="5" class="form-control mb-3" placeholder="SELECT * FROM `Invoices` LIMIT 50;"><?php echo htmlspecialchars($_POST['sql'] ?? ''); ?></textarea>
                <button class="btn btn-primary">Run</button>
                <button type="button" class="btn btn-secondary ms-2" onclick="clearQuery()">Clear</button>
            </form>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <?php if ($columns && $resultRows): ?>
        <div class="card">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm table-dark table-striped align-middle">
                        <thead>
                            <tr>
                                <?php foreach ($columns as $col): ?>
                                    <th><?= htmlspecialchars($col) ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($resultRows as $row): ?>
                                <tr>
                                    <?php foreach ($columns as $col): ?>
                                        <td><?= htmlspecialchars($row[$col] ?? '') ?></td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
function setQuery(q) {
    document.getElementById('sql').value = q;
}
function clearQuery() {
    document.getElementById('sql').value = '';
}
</script>
</body>
</html>

