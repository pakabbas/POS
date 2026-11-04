<?php
// Shared helpers for the dashboard and reports (egg wholesale: money in Rs., quantities in PETI = 12 crates; breakage in crates).
// All dates are Asia/Karachi; ranges are passed to SQL as 'Y-m-d 00:00:00' .. 'Y-m-d 23:59:59'.

require_once __DIR__ . '/customers_lib.php';
require_once __DIR__ . '/vendors_lib.php';
require_once __DIR__ . '/inventory_lib.php';

/**
 * Resolve the period from ?range=today|yesterday|week|month|last30|custom (&from=&to=).
 * Returns ['key','from','to','label','fromDt','toDt','days'].
 */
function report_period(array $q, string $default = 'month'): array
{
    $today = date('Y-m-d');
    $key = $q['range'] ?? $default;
    $from = $to = $today;
    switch ($key) {
        case 'today':     $label = 'Today'; break;
        case 'yesterday': $from = $to = date('Y-m-d', strtotime('-1 day')); $label = 'Yesterday'; break;
        case 'week':      $from = date('Y-m-d', strtotime('monday this week')); $label = 'This week'; break;
        case 'last30':    $from = date('Y-m-d', strtotime('-29 days')); $label = 'Last 30 days'; break;
        case 'custom':
            $f = (string)($q['from'] ?? ''); $t = (string)($q['to'] ?? '');
            $from = valid_ymd($f) ? $f : date('Y-m-01');
            $to = valid_ymd($t) ? $t : $today;
            if ($from > $to) { [$from, $to] = [$to, $from]; }
            $label = date('d M Y', strtotime($from)) . ($from === $to ? '' : ' – ' . date('d M Y', strtotime($to)));
            break;
        case 'month':
        default:
            $key = 'month'; $from = date('Y-m-01'); $label = 'This month (' . date('M Y') . ')';
    }
    $days = (int)((strtotime($to) - strtotime($from)) / 86400) + 1;
    return ['key' => $key, 'from' => $from, 'to' => $to, 'label' => $label,
            'fromDt' => $from . ' 00:00:00', 'toDt' => $to . ' 23:59:59', 'days' => max(1, $days)];
}

function period_query(array $p, array $extra = []): string
{
    $q = ['range' => $p['key']];
    if ($p['key'] === 'custom') { $q['from'] = $p['from']; $q['to'] = $p['to']; }
    return http_build_query(array_merge($q, $extra));
}

function q_all(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $stmt = $conn->prepare($sql);
    if ($types !== '') $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}

function q_one(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    return q_all($conn, $sql, $types, $params)[0] ?? [];
}

function rs0($v): string { return 'Rs. ' . number_format((float)$v, 0); }
function pct($part, $whole): float { return (float)$whole != 0.0 ? round((float)$part / (float)$whole * 100, 1) : 0.0; }

/** Weighted-average purchase cost per crate for each product (all received purchases up to $uptoDt), falling back to the last entered cost. */
function product_costs(mysqli $conn, ?string $uptoDt = null): array
{
    $uptoDt = $uptoDt ?? date('Y-m-d H:i:s');
    $rows = q_all($conn, "SELECT p.ProductID,
              (SELECT SUM(poi.TotalPrice) / NULLIF(SUM(poi.QuantityReceived), 0)
                 FROM PurchaseOrderItems poi JOIN PurchaseOrders po ON po.PurchaseOrderID = poi.PurchaseOrderID
                WHERE poi.ProductID = p.ProductID AND po.Status = 'Received' AND po.OrderDate <= ? AND poi.UnitPrice > 0) AS AvgCost,
              (SELECT pi.CostPrice FROM ProductInventory pi WHERE pi.ProductID = p.ProductID) AS LastCost
            FROM Products p", 's', [$uptoDt]);
    $out = [];
    foreach ($rows as $r) {
        $c = (float)($r['AvgCost'] ?? 0);
        if ($c <= 0) $c = (float)($r['LastCost'] ?? 0);
        $out[(int)$r['ProductID']] = $c > 0 ? round($c, 2) : null;
    }
    return $out;
}

/** Per-product sales in a period: ProductID, ProductName, SortOrder, Crates, Amount, Invoices. */
function sales_by_product(mysqli $conn, string $fromDt, string $toDt): array
{
    return q_all($conn, "SELECT p.ProductID, p.ProductName, p.SortOrder,
               COALESCE(SUM(ii.Quantity), 0) AS Crates, COALESCE(SUM(ii.TotalPrice), 0) AS Amount,
               COUNT(DISTINCT ii.InvoiceID) AS Invoices
        FROM Products p
        LEFT JOIN (SELECT ii.* FROM InvoiceItems ii JOIN Invoices i ON i.InvoiceID = ii.InvoiceID
                   WHERE i.InvoiceDate BETWEEN ? AND ?) ii ON ii.ProductID = p.ProductID
        WHERE p.IsActive = 1 OR ii.InvoiceItemID IS NOT NULL
        GROUP BY p.ProductID, p.ProductName, p.SortOrder
        ORDER BY p.SortOrder, p.ProductName", 'ss', [$fromDt, $toDt]);
}

/** Per-product received purchases in a period. [ProductID => ['Crates','Amount']] */
function purchases_by_product(mysqli $conn, string $fromDt, string $toDt): array
{
    $out = [];
    foreach (q_all($conn, "SELECT poi.ProductID, SUM(poi.QuantityReceived) AS Crates, SUM(poi.TotalPrice) AS Amount
            FROM PurchaseOrderItems poi JOIN PurchaseOrders po ON po.PurchaseOrderID = poi.PurchaseOrderID
            WHERE po.Status = 'Received' AND po.OrderDate BETWEEN ? AND ? GROUP BY poi.ProductID", 'ss', [$fromDt, $toDt]) as $r) {
        $out[(int)$r['ProductID']] = ['Crates' => (float)$r['Crates'], 'Amount' => (float)$r['Amount']];
    }
    return $out;
}

/**
 * Customers with a balance, oldest-unpaid aging (payments are applied to the oldest debt first,
 * so what is still owed is the newest credit sales; anything beyond them is opening balance = 30+ days).
 * Returns rows: CustomerID, CustomerName, Phone, Balance, LastPayment, DaysSincePayment, LastSale, b0_7, b8_30, b30
 */
function receivables_aging(mysqli $conn): array
{
    $bal = customer_balances($conn);
    $cust = [];
    foreach (q_all($conn, "SELECT c.CustomerID, c.CustomerName, c.Phone, c.CreatedAt,
                (SELECT MAX(PaymentDate) FROM CustomerPayments cp WHERE cp.CustomerID = c.CustomerID) AS LastPayment,
                (SELECT MAX(InvoiceDate) FROM Invoices i WHERE i.CustomerID = c.CustomerID) AS LastSale
            FROM Customers c") as $r) {
        $cust[(int)$r['CustomerID']] = $r;
    }
    $now = time();
    $out = [];
    foreach ($bal as $cid => $b) {
        if ($b <= 0.005 || !isset($cust[$cid])) continue;
        $r = $cust[$cid];
        $left = $b;
        $buckets = ['b0_7' => 0.0, 'b8_30' => 0.0, 'b30' => 0.0];
        $inv = q_all($conn, "SELECT InvoiceDate, TotalAmount FROM Invoices WHERE CustomerID = ? AND PaymentType = 'Credit' ORDER BY InvoiceDate DESC, InvoiceID DESC", 'i', [$cid]);
        foreach ($inv as $i) {
            if ($left <= 0.005) break;
            $amt = min($left, (float)$i['TotalAmount']);
            $age = (int)floor(($now - strtotime($i['InvoiceDate'])) / 86400);
            $buckets[$age <= 7 ? 'b0_7' : ($age <= 30 ? 'b8_30' : 'b30')] += $amt;
            $left -= $amt;
        }
        if ($left > 0.005) $buckets['b30'] += $left; // opening balance
        $lp = $r['LastPayment'];
        $out[] = [
            'CustomerID' => $cid, 'CustomerName' => $r['CustomerName'], 'Phone' => $r['Phone'],
            'Balance' => $b, 'LastPayment' => $lp, 'LastSale' => $r['LastSale'],
            'DaysSincePayment' => $lp ? (int)floor(($now - strtotime($lp)) / 86400) : null,
        ] + array_map(fn($v) => round($v, 2), $buckets);
    }
    usort($out, fn($a, $b) => $b['Balance'] <=> $a['Balance']);
    return $out;
}

/** Send rows as a CSV download and stop. */
function send_csv(string $filename, array $header, array $rows): void
{
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // Excel-friendly UTF-8
    fputcsv($out, $header);
    foreach ($rows as $r) fputcsv($out, array_values($r));
    fclose($out);
    exit();
}

/** Net stock movement per product (purchased + adjusted - sold) with dates in [fromDt, toDt]. */
function stock_flows(mysqli $conn, string $fromDt, string $toDt): array
{
    $out = [];
    $add = function ($pid, $k, $v) use (&$out) {
        $out[$pid] = $out[$pid] ?? ['Purchased' => 0.0, 'Sold' => 0.0, 'Adjusted' => 0.0, 'Breakage' => 0.0];
        $out[$pid][$k] += (float)$v;
    };
    foreach (q_all($conn, "SELECT poi.ProductID, SUM(poi.QuantityReceived) q FROM PurchaseOrderItems poi JOIN PurchaseOrders po ON po.PurchaseOrderID = poi.PurchaseOrderID
            WHERE po.Status = 'Received' AND po.OrderDate BETWEEN ? AND ? GROUP BY poi.ProductID", 'ss', [$fromDt, $toDt]) as $r) $add((int)$r['ProductID'], 'Purchased', $r['q']);
    foreach (q_all($conn, "SELECT ii.ProductID, SUM(ii.Quantity) q FROM InvoiceItems ii JOIN Invoices i ON i.InvoiceID = ii.InvoiceID
            WHERE i.InvoiceDate BETWEEN ? AND ? GROUP BY ii.ProductID", 'ss', [$fromDt, $toDt]) as $r) $add((int)$r['ProductID'], 'Sold', $r['q']);
    foreach (q_all($conn, "SELECT ProductID, SUM(Quantity) q, SUM(CASE WHEN MovementType IN ('Waste','Expired') THEN -COALESCE(Crates, Quantity * 12) ELSE 0 END) b FROM StockMovements
            WHERE MovementType NOT IN ('Purchase','Sale') AND MovementDate BETWEEN ? AND ? GROUP BY ProductID", 'ss', [$fromDt, $toDt]) as $r) {
        $add((int)$r['ProductID'], 'Adjusted', $r['q']);
        $add((int)$r['ProductID'], 'Breakage', $r['b']);
    }
    return $out;
}

/** Peti in stock per product at a moment (uses each product's count as the anchor). [ProductID => float] */
function stock_at(mysqli $conn, string $atDt): array
{
    $out = [];
    $anchors = q_all($conn, "SELECT p.ProductID, COALESCE(pi.OpeningStock, 0) os, pi.OpeningStockDate osd FROM Products p LEFT JOIN ProductInventory pi ON pi.ProductID = p.ProductID");
    foreach ($anchors as $a) {
        $pid = (int)$a['ProductID'];
        $os = (float)$a['os'];
        $osd = $a['osd'];
        if ($osd === null) {
            $f = stock_flows($conn, '1970-01-01 00:00:00', $atDt)[$pid] ?? null;
            $out[$pid] = $f ? $f['Purchased'] + $f['Adjusted'] - $f['Sold'] : 0.0;
        } elseif ($osd <= $atDt) {
            $f = stock_flows($conn, $osd, $atDt)[$pid] ?? null;
            $out[$pid] = $os + ($f ? $f['Purchased'] + $f['Adjusted'] - $f['Sold'] : 0.0);
        } else {
            // Before the count: work backwards from the count.
            $f = stock_flows($conn, date('Y-m-d H:i:s', strtotime($atDt) + 1), date('Y-m-d H:i:s', strtotime($osd) - 1))[$pid] ?? null;
            $out[$pid] = $os - ($f ? $f['Purchased'] + $f['Adjusted'] - $f['Sold'] : 0.0);
        }
        $out[$pid] = round($out[$pid], 4);
    }
    return $out;
}
