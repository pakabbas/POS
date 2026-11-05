<?php
ob_start(); // allows redirect-after-POST even though header.php prints the page header
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/vendors_lib.php';
require_once __DIR__ . '/includes/inventory_lib.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');
date_default_timezone_set('Asia/Karachi');
$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

function redirect_to(string $url): void
{
    ob_end_clean();
    header('Location: ' . $url);
    exit();
}

$today = date('Y-m-d');
$alert = null;
$flash = [
    'opening' => ['success', 'Opening stock / reorder level saved.'],
    'adjusted' => ['success', 'Stock adjustment saved.'],
    'deleted' => ['success', 'Adjustment deleted.'],
];
if (isset($flash[$_GET['msg'] ?? ''])) {
    $alert = ['type' => $flash[$_GET['msg']][0], 'msg' => $flash[$_GET['msg']][1]];
}
$lowOnly = ($_GET['low'] ?? '') === '1';
$selfUrl = 'inventory.php' . ($lowOnly ? '?low=1' : ((int)($_GET['product'] ?? 0) ? '?product=' . (int)$_GET['product'] : ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $which = $_POST['form'] ?? '';
    $pid = (int)($_POST['product_id'] ?? 0);
    try {
        if ($which === 'opening' && $role === 'Admin') {
            // Count is entered as whole peti + loose crates; stored in peti.
            $opening = round((float)($_POST['opening_stock'] ?? 0) + (float)($_POST['opening_crates'] ?? 0) / CRATES_PER_PETI, 4);
            $reorder = round((float)($_POST['reorder_level'] ?? 0), 4);
            $countDate = (string)($_POST['count_date'] ?? $today);
            $keepCount = isset($_POST['reorder_only']);
            if ($pid <= 0) {
                $alert = ['type' => 'danger', 'msg' => 'Select a product.'];
            } elseif ($opening < 0 || $reorder < 0) {
                $alert = ['type' => 'danger', 'msg' => 'Stock and reorder level cannot be negative.'];
            } elseif (!$keepCount && (!valid_ymd($countDate) || $countDate > $today)) {
                $alert = ['type' => 'danger', 'msg' => 'Invalid count date.'];
            } else {
                if ($keepCount) {
                    $stmt = $conn->prepare("INSERT INTO ProductInventory (ProductID, UnitOfMeasure, ReorderLevel) VALUES (?, 'Peti', ?)
                                            ON DUPLICATE KEY UPDATE ReorderLevel = VALUES(ReorderLevel)");
                    $stmt->bind_param('id', $pid, $reorder);
                } else {
                    // The count is the stock at that moment; only movements after it are added/subtracted.
                    $countAt = $countDate === $today ? date('Y-m-d H:i:s') : $countDate . ' 23:59:59';
                    $stmt = $conn->prepare("INSERT INTO ProductInventory (ProductID, UnitOfMeasure, OpeningStock, OpeningStockDate, ReorderLevel) VALUES (?, 'Peti', ?, ?, ?)
                                            ON DUPLICATE KEY UPDATE OpeningStock = VALUES(OpeningStock), OpeningStockDate = VALUES(OpeningStockDate), ReorderLevel = VALUES(ReorderLevel)");
                    $stmt->bind_param('idsd', $pid, $opening, $countAt, $reorder);
                }
                $stmt->execute();
                redirect_to($selfUrl . (strpos($selfUrl, '?') !== false ? '&' : '?') . 'msg=opening');
            }
        } elseif ($which === 'adjust') {
            $types = stock_adjustment_types();
            $label = (string)($_POST['adj_type'] ?? '');
            $crates = round((float)($_POST['qty'] ?? 0), 2); // adjustments are entered in crates
            $reason = mb_substr(trim((string)($_POST['reason'] ?? '')), 0, 500);
            $date = (string)($_POST['date'] ?? $today);
            if ($pid <= 0) {
                $alert = ['type' => 'danger', 'msg' => 'Select a product.'];
            } elseif (!isset($types[$label])) {
                $alert = ['type' => 'danger', 'msg' => 'Select an adjustment type.'];
            } elseif ($crates == 0) {
                $alert = ['type' => 'danger', 'msg' => 'Quantity cannot be zero.'];
            } elseif ($reason === '') {
                $alert = ['type' => 'danger', 'msg' => 'Please enter a reason.'];
            } elseif (!valid_ymd($date) || $date > $today) {
                $alert = ['type' => 'danger', 'msg' => 'Invalid date.'];
            } else {
                [$mType, $sign] = $types[$label];
                if ($sign !== 0) $crates = $sign * abs($crates);
                $qty = round($crates / CRATES_PER_PETI, 4); // stock effect in peti
                $when = $date === $today ? date('Y-m-d H:i:s') : $date . ' ' . date('H:i:s');
                $note = $label . ': ' . $reason;
                $stmt = $conn->prepare("INSERT INTO StockMovements (ProductID, MovementType, Quantity, Crates, ReferenceType, MovementDate, PerformedBy, Notes) VALUES (?, ?, ?, ?, 'Manual', ?, ?, ?)");
                $stmt->bind_param('isddsis', $pid, $mType, $qty, $crates, $when, $userId, $note);
                $stmt->execute();
                redirect_to($selfUrl . (strpos($selfUrl, '?') !== false ? '&' : '?') . 'msg=adjusted');
            }
        } elseif ($which === 'delete_adjust' && $role === 'Admin') {
            $mid = (int)($_POST['movement_id'] ?? 0);
            $stmt = $conn->prepare("DELETE FROM StockMovements WHERE MovementID = ? AND ReferenceType = 'Manual'");
            $stmt->bind_param('i', $mid);
            $stmt->execute();
            redirect_to($selfUrl . (strpos($selfUrl, '?') !== false ? '&' : '?') . 'msg=deleted');
        }
    } catch (Throwable $e) {
        $alert = ['type' => 'danger', 'msg' => 'Error: ' . $e->getMessage()];
    }
}
ob_end_flush();

$focusId = (int)($_GET['product'] ?? 0);
$levels = stock_levels($conn, ($_GET['show'] ?? '') !== 'all' && !$focusId);
$lowCount = 0;
foreach ($levels as $r) {
    if ($r['Stock'] <= $r['ReorderLevel']) $lowCount++;
}
$shown = $lowOnly ? array_filter($levels, fn($r) => $r['Stock'] <= $r['ReorderLevel']) : $levels;
$focusName = '';
if ($focusId) {
    $shown = array_filter($levels, fn($r) => (int)$r['ProductID'] === $focusId);
    $focusName = $shown ? reset($shown)['ProductName'] : '';
}
$totalPeti = array_sum(array_column($levels, 'Stock'));

$moves = $conn->query("SELECT sm.*, p.ProductName, u.UserName FROM StockMovements sm
                       JOIN Products p ON p.ProductID = sm.ProductID LEFT JOIN Users u ON u.UserID = sm.PerformedBy
                       WHERE sm.MovementType NOT IN ('Purchase', 'Sale')" . ($focusId ? " AND sm.ProductID = " . $focusId : "") . "
                       ORDER BY sm.MovementDate DESC, sm.MovementID DESC LIMIT 50")->fetch_all(MYSQLI_ASSOC);
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
  <h2 class="fw-bold mb-0">Inventory</h2>
  <div class="d-flex gap-2">
    <a class="btn btn-primary btn-sm" href="purchases.php?new=1">+ Stock In (Purchase)</a>
    <button class="btn btn-warning btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#adjustModal">Stock Adjustment</button>
    <a class="btn btn-outline-secondary btn-sm" href="purchases.php">Purchases</a>
  </div>
</div>

<?php if ($focusId): ?>
  <div class="alert alert-info d-flex justify-content-between align-items-center py-2">
    <span>Showing inventory for <strong><?= htmlspecialchars($focusName ?: 'this product') ?></strong></span>
    <span><a class="btn btn-sm btn-outline-secondary" href="products.php">Back to products</a> <a class="btn btn-sm btn-secondary" href="inventory.php">All products</a></span>
  </div>
<?php endif; ?>

<?php if ($alert): ?>
  <div class="alert alert-<?= $alert['type'] ?>"><?= htmlspecialchars($alert['msg']) ?></div>
<?php endif; ?>

<div class="card shadow-sm mb-4">
  <div class="card-header bg-white d-flex flex-wrap gap-2 align-items-center">
    <span class="fw-semibold me-auto">Stock levels <span class="text-muted small">(in peti · 1 peti = 12 crates · total in stock <?= peti_fmt($totalPeti) ?>)</span></span>
    <a class="btn btn-sm <?= $lowOnly ? 'btn-outline-secondary' : 'btn-secondary' ?>" href="inventory.php">All</a>
    <a class="btn btn-sm <?= $lowOnly ? 'btn-danger' : 'btn-outline-danger' ?>" href="inventory.php?low=1">Low stock (<?= $lowCount ?>)</a>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-striped mb-0 align-middle">
        <thead class="table-light">
          <tr>
            <th>Product</th>
            <th class="text-end">Opening</th>
            <th class="text-end">+ Purchased</th>
            <th class="text-end">− Sold</th>
            <th class="text-end">± Adjusted</th>
            <th class="text-end">In stock</th>
            <th class="text-end">Reorder at</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$shown): ?>
            <tr><td colspan="8" class="text-center text-muted py-4"><?= $lowOnly ? 'No products are low on stock.' : 'No products.' ?></td></tr>
          <?php endif; ?>
          <?php foreach ($shown as $r): $low = $r['Stock'] <= $r['ReorderLevel']; ?>
            <tr class="<?= $r['Stock'] < 0 ? 'table-danger' : '' ?>">
              <td>
                <?= htmlspecialchars($r['ProductName']) ?>
                <?php if (!$r['IsActive']): ?><span class="badge bg-secondary">Inactive</span><?php endif; ?>
                <div class="small text-muted">
                  <?= $r['OpeningStockDate'] ? 'Counted from ' . date('d-M-Y g:i A', strtotime($r['OpeningStockDate'])) : 'No opening count yet (all history counted)' ?>
                </div>
              </td>
              <td class="text-end text-nowrap"><?= peti_fmt($r['OpeningStock'], true) ?></td>
              <td class="text-end text-nowrap text-success"><?= peti_fmt($r['Purchased'], true) ?></td>
              <td class="text-end text-nowrap text-primary"><?= peti_fmt($r['Sold'], true) ?></td>
              <td class="text-end text-nowrap"><?= peti_fmt($r['Adjusted'], true) ?></td>
              <td class="text-end text-nowrap fw-bold fs-5 <?= $low ? 'text-danger' : '' ?>"><?= peti_fmt($r['Stock'], true) ?></td>
              <td class="text-end text-nowrap"><?= peti_fmt($r['ReorderLevel']) ?> <?php if ($low): ?><span class="badge bg-danger">Low</span><?php endif; ?></td>
              <td class="text-end text-nowrap">
                <?php if ($role === 'Admin'): ?>
                  <button type="button" class="btn btn-sm btn-outline-primary" data-opening
                          data-product-id="<?= (int)$r['ProductID'] ?>"
                          data-product-name="<?= htmlspecialchars($r['ProductName']) ?>"
                          data-stock="<?= htmlspecialchars((string)$r['Stock']) ?>"
                          data-stock-text="<?= htmlspecialchars(peti_fmt($r['Stock'])) ?>"
                          data-reorder="<?= htmlspecialchars((string)$r['ReorderLevel']) ?>">Set count</button>
                <?php endif; ?>
                <button type="button" class="btn btn-sm btn-outline-warning" data-adjust data-product-id="<?= (int)$r['ProductID'] ?>">Adjust</button>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<p class="small text-muted">In stock = opening count + purchases − peti sold on invoices ± adjustments (adjustments are entered in crates; 12 crates = 1 peti), counting only entries after the product's count time. Use <strong>Set count</strong> after a physical count to reset a product's stock to what is actually in the shop.</p>

<div class="card shadow-sm">
  <div class="card-header bg-white fw-semibold">Recent stock adjustments</div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-sm table-striped mb-0 align-middle">
        <thead class="table-light"><tr><th>Date</th><th>Product</th><th>Type</th><th class="text-end">Crates</th><th class="text-end">= Peti</th><th>Reason</th><th>By</th><?php if ($role === 'Admin'): ?><th></th><?php endif; ?></tr></thead>
        <tbody>
          <?php if (!$moves): ?>
            <tr><td colspan="8" class="text-center text-muted py-3">No adjustments yet.</td></tr>
          <?php endif; ?>
          <?php foreach ($moves as $m): $mc = movement_crates($m); ?>
            <tr>
              <td class="text-nowrap"><?= date('d-M-Y g:i A', strtotime($m['MovementDate'])) ?></td>
              <td><?= htmlspecialchars($m['ProductName']) ?></td>
              <td><?= htmlspecialchars($m['MovementType']) ?></td>
              <td class="text-end <?= $mc < 0 ? 'text-danger' : 'text-success' ?>"><?= crates_fmt($mc, true) ?></td>
              <td class="text-end small text-muted"><?= ($m['Quantity'] > 0 ? '+' : '') . rtrim(rtrim(number_format((float)$m['Quantity'], 4), '0'), '.') ?></td>
              <td class="small"><?= htmlspecialchars($m['Notes'] ?? '') ?></td>
              <td class="small"><?= htmlspecialchars($m['UserName'] ?? '') ?></td>
              <?php if ($role === 'Admin'): ?>
                <td class="text-end">
                  <?php if ($m['ReferenceType'] === 'Manual'): ?>
                  <form method="post" action="<?= htmlspecialchars($selfUrl) ?>" onsubmit="return confirm('Delete this adjustment?')">
                    <input type="hidden" name="form" value="delete_adjust">
                    <input type="hidden" name="movement_id" value="<?= (int)$m['MovementID'] ?>">
                    <button class="btn btn-sm btn-outline-danger py-0" type="submit">✕</button>
                  </form>
                  <?php endif; ?>
                </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Stock adjustment modal -->
<div class="modal fade" id="adjustModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" action="<?= htmlspecialchars($selfUrl) ?>" class="modal-content">
      <input type="hidden" name="form" value="adjust">
      <div class="modal-header"><h5 class="modal-title">Stock Adjustment</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label">Product</label>
          <select name="product_id" class="form-select" id="adjProduct" required>
            <option value="">— Product —</option>
            <?php foreach ($levels as $r): ?>
              <option value="<?= (int)$r['ProductID'] ?>"><?= htmlspecialchars($r['ProductName']) ?> (in stock <?= peti_fmt($r['Stock']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="row g-2 mb-3">
          <div class="col-7">
            <label class="form-label">Type</label>
            <select name="adj_type" class="form-select" id="adjType">
              <?php foreach (stock_adjustment_types() as $label => $t): ?>
                <option value="<?= htmlspecialchars($label) ?>" data-sign="<?= $t[1] ?>"><?= htmlspecialchars($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-5">
            <label class="form-label">Crates <span class="text-muted small">(not peti)</span></label>
            <input type="number" name="qty" step="1" class="form-control" inputmode="numeric" required id="adjQty">
          </div>
        </div>
        <div class="form-text mb-3" id="adjHint"></div>
        <div class="mb-3">
          <label class="form-label">Reason *</label>
          <input type="text" name="reason" maxlength="500" class="form-control" required placeholder="e.g. 3 crates broken in loading">
        </div>
        <div class="mb-1">
          <label class="form-label">Date</label>
          <input type="date" name="date" class="form-control" value="<?= $today ?>" max="<?= $today ?>" required>
        </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-warning">Save Adjustment</button></div>
    </form>
  </div>
</div>

<?php if ($role === 'Admin'): ?>
<!-- Opening stock / count modal -->
<div class="modal fade" id="openingModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" action="<?= htmlspecialchars($selfUrl) ?>" class="modal-content">
      <input type="hidden" name="form" value="opening">
      <input type="hidden" name="product_id" id="opProduct">
      <div class="modal-header"><h5 class="modal-title">Set stock count — <span id="opName"></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="mb-2 small text-muted">Current computed stock: <strong id="opCurrent"></strong></div>
        <div class="row g-2 mb-3">
          <div class="col-4">
            <label class="form-label">Peti counted</label>
            <input type="number" name="opening_stock" min="0" step="1" class="form-control" id="opStock">
          </div>
          <div class="col-3">
            <label class="form-label">+ crates</label>
            <input type="number" name="opening_crates" min="0" max="11" step="1" class="form-control" id="opCrates">
          </div>
          <div class="col">
            <label class="form-label">Counted on</label>
            <input type="date" name="count_date" class="form-control" value="<?= $today ?>" max="<?= $today ?>">
          </div>
        </div>
        <div class="form-text mb-3">Today = counted right now. A past date = counted at the end of that day; sales, purchases and adjustments after it are applied on top.</div>
        <div class="mb-3">
          <label class="form-label">Low-stock alert at (peti)</label>
          <input type="number" name="reorder_level" min="0" step="0.5" class="form-control" id="opReorder">
        </div>
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="reorder_only" id="opReorderOnly">
          <label class="form-check-label" for="opReorderOnly">Only change the low-stock level (keep current count)</label>
        </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary">Save</button></div>
    </form>
  </div>
</div>
<?php endif; ?>

</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('click', function (e) {
  const op = e.target.closest('[data-opening]');
  if (op) {
    document.getElementById('opProduct').value = op.dataset.productId;
    document.getElementById('opName').textContent = op.dataset.productName;
    document.getElementById('opCurrent').textContent = op.dataset.stockText;
    const crates = Math.max(Math.round((parseFloat(op.dataset.stock) || 0) * 12), 0);
    document.getElementById('opStock').value = Math.floor(crates / 12);
    document.getElementById('opCrates').value = crates % 12;
    document.getElementById('opReorder').value = +(parseFloat(op.dataset.reorder) || 0).toFixed(2);
    document.getElementById('opReorderOnly').checked = false;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('openingModal')).show();
  }
  const adj = e.target.closest('[data-adjust]');
  if (adj) {
    document.getElementById('adjProduct').value = adj.dataset.productId;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('adjustModal')).show();
  }
});
(function () {
  const t = document.getElementById('adjType'), h = document.getElementById('adjHint');
  function hint() {
    const s = t.selectedOptions[0].dataset.sign;
    const q = Math.abs(parseFloat(document.getElementById('adjQty').value) || 0);
    const peti = q ? ' (= ' + +(q / 12).toFixed(4) + ' peti)' : '';
    h.textContent = (s === '-1' ? 'These crates will be removed from stock' : 'Crates: positive adds to stock, negative removes') + peti + '. 12 crates = 1 peti.';
  }
  t.addEventListener('change', hint); document.getElementById('adjQty').addEventListener('input', hint); hint();
})();
</script>
</body>
</html>
