<?php
// Stock (in crates) per product, computed from the source documents so it never drifts:
//   Stock = ProductInventory.OpeningStock (physical count at OpeningStockDate)
//         + purchased  (PurchaseOrderItems.QuantityReceived of Received purchases)
//         + adjustments (StockMovements.Quantity, signed: damage/breakage negative, corrections +/-)
//         - sold       (InvoiceItems.Quantity of invoices)
// Only purchases / adjustments / sales dated on or after OpeningStockDate are counted
// (no OpeningStockDate = count everything). Deleting an invoice in reports.php puts its
// crates back automatically because its InvoiceItems rows are removed.

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
            $r[$k] = round((float)$r[$k], 2);
        }
        $r['Stock'] = round($r['OpeningStock'] + $r['Purchased'] + $r['Adjusted'] - $r['Sold'], 2);
        $out[(int)$r['ProductID']] = $r;
    }
    return $out;
}

/** [ProductID => crates in stock]; returns [] if the inventory tables are missing (migration not run yet). */
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
