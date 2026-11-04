<?php
// Stock (in PETI; 1 peti = 12 crates = 360 eggs) per product, computed from the source documents so it never drifts:
//   Stock = ProductInventory.OpeningStock (physical count at OpeningStockDate)
//         + purchased  (PurchaseOrderItems.QuantityReceived of Received purchases)
//         + adjustments (StockMovements.Quantity in peti, signed; entered in crates = StockMovements.Crates / 12)
//         - sold       (InvoiceItems.Quantity of invoices)
// Only purchases / adjustments / sales dated on or after OpeningStockDate are counted
// (no OpeningStockDate = count everything). Deleting an invoice in reports.php puts its
// peti back automatically because its InvoiceItems rows are removed.

function stock_adjustment_types(): array
{
    // label => [MovementType stored in StockMovements, sign forced (-1, +1) or 0 = as entered]
    return [
        'Damage / Breakage' => ['Waste', -1],
        'Expired / Spoiled' => ['Expired', -1],
        'Return to vendor'  => ['Return', -1],
        'Count correction'  => ['Adjustment', 0],
    ];
}

/**
 * Returns [ProductID => row] ordered by SortOrder, with keys:
 * ProductID, ProductName, CategoryName, IsActive, SortOrder, OpeningStock, OpeningStockDate,
 * ReorderLevel, Purchased, Sold, Adjusted, Stock
 */
function stock_levels(mysqli $conn, bool $activeOnly = false, ?int $productId = null): array
{
    $sql = "SELECT p.ProductID, p.ProductName, p.IsActive, p.SortOrder, c.CategoryName,
                   COALESCE(pi.OpeningStock, 0) AS OpeningStock, pi.OpeningStockDate,
                   COALESCE(pi.ReorderLevel, 0) AS ReorderLevel,
                   (SELECT COALESCE(SUM(poi.QuantityReceived), 0)
                      FROM PurchaseOrderItems poi
                      JOIN PurchaseOrders po ON po.PurchaseOrderID = poi.PurchaseOrderID
                     WHERE poi.ProductID = p.ProductID AND po.Status = 'Received'
                       AND (pi.OpeningStockDate IS NULL OR po.OrderDate >= pi.OpeningStockDate)) AS Purchased,
                   (SELECT COALESCE(SUM(ii.Quantity), 0)
                      FROM InvoiceItems ii
                      JOIN Invoices i ON i.InvoiceID = ii.InvoiceID
                     WHERE ii.ProductID = p.ProductID
                       AND (pi.OpeningStockDate IS NULL OR i.InvoiceDate >= pi.OpeningStockDate)) AS Sold,
                   (SELECT COALESCE(SUM(sm.Quantity), 0)
                      FROM StockMovements sm
                     WHERE sm.ProductID = p.ProductID AND sm.MovementType NOT IN ('Purchase', 'Sale')
                       AND (pi.OpeningStockDate IS NULL OR sm.MovementDate >= pi.OpeningStockDate)) AS Adjusted
            FROM Products p
            LEFT JOIN Categories c ON c.CategoryID = p.CategoryID
            LEFT JOIN ProductInventory pi ON pi.ProductID = p.ProductID
            WHERE 1=1";
    if ($activeOnly) {
        $sql .= " AND p.IsActive = 1";
    }
    if ($productId !== null) {
        $sql .= " AND p.ProductID = " . (int)$productId;
    }
    $sql .= " ORDER BY p.SortOrder ASC, p.ProductName ASC";
    $out = [];
    foreach ($conn->query($sql)->fetch_all(MYSQLI_ASSOC) as $r) {
        foreach (['OpeningStock', 'ReorderLevel', 'Purchased', 'Sold', 'Adjusted'] as $k) {
            $r[$k] = round((float)$r[$k], 4);
        }
        $r['Stock'] = round($r['OpeningStock'] + $r['Purchased'] + $r['Adjusted'] - $r['Sold'], 4);
        $out[(int)$r['ProductID']] = $r;
    }
    return $out;
}

/** [ProductID => peti in stock]; returns [] if the inventory tables are missing (migration not run yet). */
function stock_map(mysqli $conn): array
{
    try {
        $m = [];
        foreach (stock_levels($conn, true) as $id => $r) {
            $m[$id] = $r['Stock'];
        }
        return $m;
    } catch (Throwable $e) {
        return [];
    }
}

function qty_fmt($q): string
{
    $q = round((float)$q, 2);
    return ($q == floor($q)) ? number_format($q, 0) : number_format($q, 2);
}

// ---- Units: the shop trades in PETI; breakage / adjustments are entered in crates ----
const CRATES_PER_PETI = 12;
const EGGS_PER_CRATE = 30;

/** Peti (decimal) -> whole crates, rounded (5/12 peti stored as 0.4167 -> 5 crates). */
function peti_to_crates($peti): int
{
    return (int)round((float)$peti * CRATES_PER_PETI);
}

/** "41 peti 7 crates", "41 peti", "7 crates", "-1 peti 2 crates", "0 peti". */
function peti_fmt($peti, bool $html = false): string
{
    $c = peti_to_crates($peti);
    $neg = $c < 0;
    $c = abs($c);
    $p = intdiv($c, CRATES_PER_PETI);
    $r = $c % CRATES_PER_PETI;
    $parts = [];
    if ($p > 0 || $r === 0) $parts[] = number_format($p) . ' peti';
    if ($r > 0) $parts[] = $html ? '<span class="text-muted">' . $r . ' crate' . ($r === 1 ? '' : 's') . '</span>' : $r . ' crate' . ($r === 1 ? '' : 's');
    return ($neg ? '−' : '') . implode(' ', $parts);
}

/** Crates with sign, e.g. "-5 crates" / "+1 crate". */
function crates_fmt($crates, bool $signed = false): string
{
    $c = round((float)$crates, 2);
    $txt = qty_fmt(abs($c)) . ' crate' . (abs($c) == 1.0 ? '' : 's');
    return ($c < 0 ? '−' : ($signed && $c > 0 ? '+' : '')) . $txt;
}

/** Crates of an adjustment row: the value typed (Crates) or derived from the peti effect. */
function movement_crates(array $m): float
{
    return $m['Crates'] !== null && $m['Crates'] !== '' ? (float)$m['Crates'] : round((float)$m['Quantity'] * CRATES_PER_PETI, 2);
}
