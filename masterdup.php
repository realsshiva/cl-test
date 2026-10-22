<?php
require_once __DIR__ . '/db.php';

$ingmasterId = isset($_GET['ingmaster-Id']) ? $_GET['ingmaster-Id'] : '';

function toBaseQuantity(float $quantity, string $unit): float
{
    switch ($unit) {
        case 'kg': return $quantity * 1000.0;
        case 'g': return $quantity;
        case 'L': return $quantity * 1000.0;
        case 'mL': return $quantity;
        case 'pcs': return $quantity;
    }
    return $quantity;
}

function formatStock(float $baseQuantity, string $unitType): string
{
    if ($unitType === 'g' || $unitType === 'kg') {
        if ($baseQuantity >= 1000.0) {
            return round($baseQuantity / 1000.0, 2) . ' kg';
        }
        return round($baseQuantity, 2) . ' g';
    }

    if ($unitType === 'mL' || $unitType === 'L') {
        if ($baseQuantity >= 1000.0) {
            return round($baseQuantity / 1000.0, 2) . ' L';
        }
        return round($baseQuantity, 2) . ' mL';
    }

    if ($unitType === 'pcs') {
        $count = round($baseQuantity);
        return $count === 1 ? '1 pc' : $count . ' pcs';
    }

    return round($baseQuantity, 2) . ' ' . $unitType;
}

function thresholdBase(float $threshold, string $unitType): float
{
    switch ($unitType) {
        case 'kg': return $threshold * 1000.0;
        case 'g': return $threshold;
        case 'L': return $threshold * 1000.0;
        case 'mL': return $threshold;
        case 'pcs': return $threshold;
    }
    return $threshold;
}

function convertQuantityToBatchUnit(float $quantity, string $fromUnit, string $batchUnit): float
{
    if ($fromUnit === $batchUnit) {
        return $quantity;
    }

    $massUnits = ['g', 'kg'];
    $volumeUnits = ['mL', 'L'];

    if (in_array($fromUnit, $massUnits, true) && in_array($batchUnit, $massUnits, true)) {
        if ($fromUnit === 'kg') {
            $quantity *= 1000.0;
        }
        if ($batchUnit === 'kg') {
            $quantity /= 1000.0;
        }
        return $quantity;
    }

    if (in_array($fromUnit, $volumeUnits, true) && in_array($batchUnit, $volumeUnits, true)) {
        if ($fromUnit === 'L') {
            $quantity *= 1000.0;
        }
        if ($batchUnit === 'L') {
            $quantity /= 1000.0;
        }
        return $quantity;
    }

    return $quantity;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'stock_in' && $ingmasterId !== '') {
        $brand = trim($_POST['ing-Brand'] ?? '');
        $batch = trim($_POST['ing-BatchCode'] ?? '');
        $unit = trim($_POST['ing-UnitType'] ?? '');
        $qty = trim($_POST['ing-StockQty'] ?? '');
        $price = trim($_POST['ing-Price'] ?? '');
        $expiry = trim($_POST['ing-ExpiryDate'] ?? '');

        if ($brand !== '' && $batch !== '' && $unit !== '' && $qty !== '' && $expiry !== '') {
            $insert = $conn->prepare('INSERT INTO `m111-ingredient_item` (`ingmaster-Id`,`ing-BatchCode`,`ing-Brand`,`ing-UnitType`,`ing-StockQty`,`ing-Price`,`ing-ExpiryDate`) VALUES (?,?,?,?,?,?,?)');
            $insert->bind_param('ssssdds', $ingmasterId, $batch, $brand, $unit, $qty, $price, $expiry);
            $insert->execute();
            $insert->close();
        }
    }

    if ($_POST['action'] === 'stock_out' && !empty($_POST['ing-Id'])) {
        $stockId = trim($_POST['ing-Id']);
        $removeQty = floatval($_POST['outQty'] ?? 0);
        $removeUnit = trim($_POST['outUnit'] ?? '');
        if ($removeQty > 0 && $removeUnit !== '') {
            $stmt = $conn->prepare('SELECT `ing-StockQty`,`ing-UnitType` FROM `m111-ingredient_item` WHERE `ing-Id` = ? LIMIT 1');
            $stmt->bind_param('i', $stockId);
            $stmt->execute();
            $stmt->bind_result($currentQty, $batchUnit);
            if ($stmt->fetch()) {
                $stmt->close();
                $qtyToRemove = convertQuantityToBatchUnit($removeQty, $removeUnit, $batchUnit);
                $updatedQty = max(0.0, $currentQty - $qtyToRemove);

                $update = $conn->prepare('UPDATE `m111-ingredient_item` SET `ing-StockQty` = ? WHERE `ing-Id` = ?');
                $update->bind_param('di', $updatedQty, $stockId);
                $update->execute();
                $update->close();

                // Future feature: insert waste log here when the item is removed from stock
                // INSERT INTO waste_log (ing-Id, reason, qty, date) VALUES (?, ?, ?, ?);
            } else {
                $stmt->close();
            }
        }
    }

    $redirect = '/cl-test/invtest/master.php';
    if ($ingmasterId !== '') {
        $redirect .= '?ingmaster-Id=' . urlencode($ingmasterId);
    }
    header('Location: ' . $redirect);
    exit;
}

$masterName = '';
$masterUnitType = '';
$masterThreshold = 0.0;
$masterCategory = '';

if ($ingmasterId !== '') {
    $stmt = $conn->prepare('SELECT `ingmaster-Name`,`ingmaster-UnitType`,`ingmaster-Threshold`,`ingcat-Id` FROM `m11-ingredient_master` WHERE `ingmaster-Id` = ? LIMIT 1');
    $stmt->bind_param('s', $ingmasterId);
    $stmt->execute();
    $stmt->bind_result($masterName, $masterUnitType, $masterThreshold, $masterCategory);
    $stmt->fetch();
    $stmt->close();
}

$activeItems = [];
$totalBase = 0.0;
$status = 'Out Of Stock';
$summaryUnit = $masterUnitType ?: 'pcs';
$summaryTotal = '0';

if ($ingmasterId !== '' && $masterName !== '') {
    $today = new DateTimeImmutable('today');
    $query = $conn->prepare('SELECT `ing-Id`,`ing-BatchCode`,`ing-Brand`,`ing-UnitType`,`ing-StockQty`,`ing-Price`,`ing-ExpiryDate` FROM `m111-ingredient_item` WHERE `ingmaster-Id` = ? AND `ing-StockQty` > 0 AND `ing-ExpiryDate` > CURDATE() ORDER BY `ing-ExpiryDate` ASC');
    $query->bind_param('s', $ingmasterId);
    $query->execute();
    $result = $query->get_result();

    while ($row = $result->fetch_assoc()) {
        $expiry = DateTimeImmutable::createFromFormat('Y-m-d', $row['ing-ExpiryDate']);
        $days = $expiry && $expiry >= $today ? (int)$today->diff($expiry)->format('%a') : -1;
        $condition = 'good';
        if ($days <= 0) {
            continue; // hide expired and same-day expiry
        }
        if ($days <= 7) {
            $condition = 'near-expiry';
        }

        $baseQty = toBaseQuantity((float)$row['ing-StockQty'], $row['ing-UnitType']);
        if (in_array($row['ing-UnitType'], ['g', 'kg', 'mL', 'L', 'pcs'], true)) {
            $totalBase += $baseQty;
        }

        $activeItems[] = [
            'id' => $row['ing-Id'],
            'batch' => $row['ing-BatchCode'],
            'brand' => $row['ing-Brand'],
            'unit' => $row['ing-UnitType'],
            'qty' => $row['ing-StockQty'],
            'price' => $row['ing-Price'],
            'expiry' => $row['ing-ExpiryDate'],
            'condition' => $condition,
        ];
    }
    $query->close();

    $baseThreshold = thresholdBase((float)$masterThreshold, $masterUnitType);
    $summaryTotal = formatStock($totalBase, $masterUnitType);

    if ($totalBase <= 0.0) {
        $status = 'Out Of Stock';
    } elseif ($totalBase <= $baseThreshold) {
        $status = 'Low';
    } else {
        $status = 'OK';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($masterName ?: 'ingmaster-Id', ENT_QUOTES, 'UTF-8'); ?></title>
    <style>
        body { font-family: Arial, sans-serif; margin:40px; }
        .top { display:flex; gap:16px; align-items:center; flex-wrap: wrap; }
        .page-title { margin:0; }
        .summary { display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:16px; margin-top:20px; }
        .summary-card { padding:16px; border:1px solid #ccc; border-radius:10px; background:#f8f8f8; }
        .summary-card h3 { margin:0 0 10px; font-size:16px; }
        .summary-card div { margin:6px 0; }
        .table-actions { display:flex; gap:10px; align-items:center; margin:24px 0 10px; }
        .btn { padding:8px 12px; border:1px solid #444; border-radius:4px; background:#fff; cursor:pointer; }
        .btn-primary { background:#2b7; border-color:#2a6; }
        .btn-secondary { background:#eef; }
        table { width:100%; border-collapse:collapse; }
        th, td { padding:10px 8px; border:1px solid #ddd; text-align:left; }
        th { background:#f7f7f7; }
        .badge { padding:4px 8px; border-radius:5px; font-size:12px; color:#fff; display:inline-block; }
        .badge-good { background:#3a7; }
        .badge-near { background:#ea0; }
        .card-link { color:inherit; text-decoration:none; }
        .modal { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.4); align-items:center; justify-content:center; padding:20px; }
        .modal .panel { background:#fff; padding:20px; border-radius:10px; width:100%; max-width:420px; }
        .field { margin-bottom:12px; }
        label { display:block; margin-bottom:4px; font-size:14px; }
        input[type=text], input[type=number], input[type=date], select, textarea { width:100%; padding:8px; box-sizing:border-box; }
        textarea { resize:vertical; }
        .modal-footer { display:flex; justify-content:flex-end; gap:10px; margin-top:16px; }
    </style>
</head>
<body>
    <div class="top">
        <div>
            <h1 class="page-title"><?php echo htmlspecialchars($masterName ?: 'Master Item', ENT_QUOTES, 'UTF-8'); ?></h1>
            <div><?php echo $masterCategory ? 'Category ID: ' . htmlspecialchars($masterCategory, ENT_QUOTES, 'UTF-8') : ''; ?></div>
        </div>
        <a class="btn" href="/cl-test/invtest/item.php?ingcat-Id=<?php echo urlencode($masterCategory); ?>">Back to item</a>
    </div>

    <div class="summary">
        <div class="summary-card">
            <h3>Total stock</h3>
            <div><?php echo htmlspecialchars($summaryTotal, ENT_QUOTES, 'UTF-8'); ?></div>
        </div>
        <div class="summary-card">
            <h3>Unit type</h3>
            <div><?php echo htmlspecialchars($masterUnitType ?: '-', ENT_QUOTES, 'UTF-8'); ?></div>
        </div>
        <div class="summary-card">
            <h3>Status</h3>
            <div><?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?></div>
        </div>
    </div>

    <div class="table-actions">
        <strong>Product Stock List</strong>
        <button class="btn btn-primary" id="openStockIn">Stock In</button>
        <button class="btn btn-secondary" id="openStockOut">Stock Out</button>
    </div>

    <?php if ($ingmasterId === '' || $masterName === ''): ?>
        <p>Invalid item selected.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Brand</th>
                    <th>Batch</th>
                    <th>Qty</th>
                    <th>Price</th>
                    <th>Expiry</th>
                    <th>Condition</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($activeItems) === 0): ?>
                    <tr><td colspan="7">No active stock batches for this item.</td></tr>
                <?php else: ?>
                    <?php foreach ($activeItems as $item): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($item['brand'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($item['batch'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($item['qty'] . ' ' . $item['unit'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars(number_format((float)$item['price'], 2), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($item['expiry'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td>
                                <span class="badge <?php echo $item['condition'] === 'near-expiry' ? 'badge-near' : 'badge-good'; ?>">
                                    <?php echo htmlspecialchars($item['condition'], ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                            </td>
                            <td>
                                <button class="btn btn-secondary edit-row" type="button"
                                    data-stock-id="<?php echo (int)$item['id']; ?>"
                                    data-brand="<?php echo htmlspecialchars($item['brand'], ENT_QUOTES, 'UTF-8'); ?>"
                                    data-batch="<?php echo htmlspecialchars($item['batch'], ENT_QUOTES, 'UTF-8'); ?>"
                                    data-qty="<?php echo htmlspecialchars($item['qty'], ENT_QUOTES, 'UTF-8'); ?>"
                                    data-unit="<?php echo htmlspecialchars($item['unit'], ENT_QUOTES, 'UTF-8'); ?>"
                                >Edit</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <div id="stockInModal" class="modal">
        <div class="panel">
            <h3>Stock In</h3>
            <form method="post" action="/cl-test/invtest/master.php?ingmaster-Id=<?php echo urlencode($ingmasterId); ?>">
                <input type="hidden" name="action" value="stock_in">
                <div class="field"><label for="stockBrand">Brand</label><input id="stockBrand" name="ing-Brand" type="text" required></div>
                <div class="field"><label for="stockBatch">Batch</label><input id="stockBatch" name="ing-BatchCode" type="text" required></div>
                <div class="field"><label for="stockUnit">Unit Type</label><select id="stockUnit" name="ing-UnitType" required><option value="g">g</option><option value="kg">kg</option><option value="mL">mL</option><option value="L">L</option><option value="pcs">pcs</option></select></div>
                <div class="field"><label for="stockQty">Quantity</label><input id="stockQty" name="ing-StockQty" type="number" step="0.01" required></div>
                <div class="field"><label for="stockPrice">Price</label><input id="stockPrice" name="ing-Price" type="number" step="0.01" required></div>
                <div class="field"><label for="stockExpiry">Expiry Date</label><input id="stockExpiry" name="ing-ExpiryDate" type="date" required></div>
                <div class="modal-footer"><button type="button" class="btn" data-close="stockInModal">Cancel</button><button type="submit" class="btn btn-primary">Add</button></div>
            </form>
        </div>
    </div>

    <div id="stockOutModal" class="modal">
        <div class="panel">
            <h3>Stock Out</h3>
            <form method="post" action="/cl-test/invtest/master.php?ingmaster-Id=<?php echo urlencode($ingmasterId); ?>">
                <input type="hidden" name="action" value="stock_out">
                <input type="hidden" id="outStockId" name="ing-Id" value="">
                <div class="field"><label for="outBrand">Brand</label><select id="outBrand" name="outBrand" required><option value="">Select brand</option></select></div>
                <div class="field"><label for="outBatch">Batch</label><select id="outBatch" name="outBatch" required><option value="">Select batch</option></select></div>
                <div class="field"><label>Available</label><div id="outAvailable"></div></div>
                <div class="field"><label for="outQty">Quantity</label><input id="outQty" name="outQty" type="number" step="0.01" required></div>
                <div class="field"><label for="outUnit">Unit</label><select id="outUnit" name="outUnit" required><option value="g">g</option><option value="kg">kg</option><option value="mL">mL</option><option value="L">L</option><option value="pcs">pcs</option></select></div>
                <div class="field"><label for="outDate">Date</label><input id="outDate" name="outDate" type="date" required></div>
                <div class="field"><label for="outReason">Reason</label><select id="outReason" name="outReason" required>
                    <option value="">Select reason</option>
                    <option value="Event Order">Event Order</option>
                    <option value="Error/Burned">Error/Burned</option>
                    <option value="Adjustment">Adjustment</option>
                </select></div>
                <div class="modal-footer"><button type="button" class="btn" data-close="stockOutModal">Cancel</button><button type="submit" class="btn btn-primary">Update</button></div>
            </form>
        </div>
    </div>

    <script>
        const stockItems = <?php echo json_encode($activeItems, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
        const openStockIn = document.getElementById('openStockIn');
        const openStockOut = document.getElementById('openStockOut');
        const stockInModal = document.getElementById('stockInModal');
        const stockOutModal = document.getElementById('stockOutModal');
        const closeButtons = document.querySelectorAll('[data-close]');
        const outBrand = document.getElementById('outBrand');
        const outBatch = document.getElementById('outBatch');
        const outAvailable = document.getElementById('outAvailable');
        const outQty = document.getElementById('outQty');
        const outUnit = document.getElementById('outUnit');
        const outStockId = document.getElementById('outStockId');
        const outDate = document.getElementById('outDate');

        function populateStockOutOptions() {
            outBrand.innerHTML = '<option value="">Select brand</option>';
            outBatch.innerHTML = '<option value="">Select batch</option>';

            const uniqueBrands = new Set();
            stockItems.forEach(item => {
                if (!uniqueBrands.has(item.brand)) {
                    uniqueBrands.add(item.brand);
                    const opt = document.createElement('option');
                    opt.value = item.brand;
                    opt.textContent = item.brand;
                    outBrand.appendChild(opt);
                }
            });

            stockItems.forEach(item => {
                const opt = document.createElement('option');
                opt.value = item.id;
                opt.textContent = item.batch + ' (' + item.brand + ')';
                opt.dataset.brand = item.brand;
                opt.dataset.qty = item.qty;
                opt.dataset.unit = item.unit;
                outBatch.appendChild(opt);
            });
        }

        function updateStockOutDisplay() {
            const selectedBatchId = outBatch.value;
            const selected = stockItems.find(i => String(i.id) === String(selectedBatchId));
            if (selected) {
                outStockId.value = selected.id;
                outBrand.value = selected.brand;
                outAvailable.textContent = selected.qty + ' ' + selected.unit;
                outUnit.value = selected.unit;
            } else {
                outStockId.value = '';
                outAvailable.textContent = '';
                outUnit.value = '';
            }
        }

        function filterBatchesByBrand(brandValue) {
            Array.from(outBatch.options).forEach(option => {
                if (!option.dataset.brand) {
                    option.style.display = 'block';
                    return;
                }
                option.style.display = brandValue === '' || option.dataset.brand === brandValue ? 'block' : 'none';
            });
            const visible = Array.from(outBatch.options).filter(option => option.style.display === 'block');
            if (visible.length > 1) {
                outBatch.value = visible[1].value;
            } else if (visible.length === 1) {
                outBatch.value = visible[0].value;
            } else {
                outBatch.value = '';
            }
            updateStockOutDisplay();
        }

        openStockIn.addEventListener('click', () => stockInModal.style.display = 'flex');
        openStockOut.addEventListener('click', () => {
            stockOutModal.style.display = 'flex';
            populateStockOutOptions();
            outBrand.value = '';
            outBatch.value = '';
            outAvailable.textContent = '';
            outUnit.value = '';
            outQty.value = '';
            outDate.value = new Date().toISOString().split('T')[0];
        });
        closeButtons.forEach(btn => btn.addEventListener('click', () => document.getElementById(btn.dataset.close).style.display = 'none'));
        window.addEventListener('click', (event) => {
            if (event.target === stockInModal) stockInModal.style.display = 'none';
            if (event.target === stockOutModal) stockOutModal.style.display = 'none';
        });

        outBatch.addEventListener('change', () => {
            updateStockOutDisplay();
        });

        outBrand.addEventListener('change', () => {
            filterBatchesByBrand(outBrand.value);
        });

        document.querySelectorAll('.edit-row').forEach(button => {
            button.addEventListener('click', () => {
                stockOutModal.style.display = 'flex';
                populateStockOutOptions();
                outBrand.value = button.dataset.brand;
                filterBatchesByBrand(button.dataset.brand);
                outBatch.value = button.dataset.stockId;
                updateStockOutDisplay();
                outQty.value = '';
                outDate.value = new Date().toISOString().split('T')[0];
            });
        });
    </script>
</body>
</html>