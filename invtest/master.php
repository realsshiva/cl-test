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
            $insert = $conn->prepare('INSERT INTO `m111-ingredient_item` (`ingmaster-Id`,`ing-BatchCode`,`ing-Brand`,`ing-UnitType`,`ing-OriginalQty`,`ing-StockQty`,`ing-Price`,`ing-ExpiryDate`) VALUES (?,?,?,?,?,?,?,?)');
            $insert->bind_param('ssssddds', $ingmasterId, $batch, $brand, $unit, $qty, $qty, $price, $expiry);
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
<?php
// View helpers for the status widget (presentation only)
$statusStyles = [
    'OK' => ['text-bg-success', 'bi-check-circle', 'bg-success-subtle text-success-emphasis'],
    'Low' => ['text-bg-warning', 'bi-exclamation-triangle', 'bg-warning-subtle text-warning-emphasis'],
    'Out Of Stock' => ['text-bg-danger', 'bi-x-octagon', 'bg-danger-subtle text-danger-emphasis'],
];
[$statusBadge, $statusIcon, $statusTile] = $statusStyles[$status] ?? ['text-bg-secondary', 'bi-question-circle', 'bg-secondary-subtle text-secondary-emphasis'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($masterName ?: 'ingmaster-Id', ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/cl-test/invtest/nav.css">
</head>
<body class="bg-body-tertiary">
    <?php include 'nav.php'; ?>

    <main class="app-main">
        <div class="container-fluid py-4 px-3 px-lg-4">

            <nav aria-label="breadcrumb">
                <ol class="breadcrumb small mb-2">
                    <li class="breadcrumb-item"><a href="/cl-test/invtest/category.php">Raw Ingredients</a></li>
                    <?php if ($masterCategory): ?>
                        <li class="breadcrumb-item"><a href="/cl-test/invtest/item.php?ingcat-Id=<?php echo urlencode($masterCategory); ?>"><?php echo htmlspecialchars($masterCategory, ENT_QUOTES, 'UTF-8'); ?></a></li>
                    <?php endif; ?>
                    <li class="breadcrumb-item active" aria-current="page"><?php echo htmlspecialchars($masterName ?: 'Master Item', ENT_QUOTES, 'UTF-8'); ?></li>
                </ol>
            </nav>

            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
                <div>
                    <h1 class="page-title mb-1"><?php echo htmlspecialchars($masterName ?: 'Master Item', ENT_QUOTES, 'UTF-8'); ?></h1>
                    <div class="text-body-secondary small"><?php echo $masterCategory ? 'Category ID: ' . htmlspecialchars($masterCategory, ENT_QUOTES, 'UTF-8') : ''; ?></div>
                </div>
                <a class="btn btn-outline-secondary" href="/cl-test/invtest/item.php?ingcat-Id=<?php echo urlencode($masterCategory); ?>">
                    <i class="bi bi-arrow-left me-1"></i>Back to item
                </a>
            </div>

            <!-- Summary widgets -->
            <div class="row row-cols-1 row-cols-sm-3 g-3 mb-4">
                <div class="col">
                    <div class="card h-100 border-0 shadow-sm">
                        <div class="card-body d-flex align-items-center gap-3">
                            <span class="icon-tile bg-primary-subtle text-primary-emphasis"><i class="bi bi-boxes"></i></span>
                            <div>
                                <div class="stat-label">Total stock</div>
                                <div class="stat-value"><?php echo htmlspecialchars($summaryTotal, ENT_QUOTES, 'UTF-8'); ?></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col">
                    <div class="card h-100 border-0 shadow-sm">
                        <div class="card-body d-flex align-items-center gap-3">
                            <span class="icon-tile bg-info-subtle text-info-emphasis"><i class="bi bi-rulers"></i></span>
                            <div>
                                <div class="stat-label">Unit type</div>
                                <div class="stat-value"><?php echo htmlspecialchars($masterUnitType ?: '-', ENT_QUOTES, 'UTF-8'); ?></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col">
                    <div class="card h-100 border-0 shadow-sm">
                        <div class="card-body d-flex align-items-center gap-3">
                            <span class="icon-tile <?php echo $statusTile; ?>"><i class="bi <?php echo $statusIcon; ?>"></i></span>
                            <div>
                                <div class="stat-label">Status</div>
                                <span class="badge rounded-pill fs-6 fw-semibold <?php echo $statusBadge; ?>"><?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Stock list -->
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-body border-bottom d-flex flex-wrap align-items-center justify-content-between gap-2 py-3">
                    <h2 class="h6 fw-semibold mb-0">Product Stock List</h2>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-success btn-sm" id="openStockIn" data-bs-toggle="modal" data-bs-target="#stockInModal">
                            <i class="bi bi-box-arrow-in-down me-1"></i>Stock In
                        </button>
                        <button type="button" class="btn btn-outline-danger btn-sm" id="openStockOut" data-bs-toggle="modal" data-bs-target="#stockOutModal">
                            <i class="bi bi-box-arrow-up me-1"></i>Stock Out
                        </button>
                    </div>
                </div>

                <?php if ($ingmasterId === '' || $masterName === ''): ?>
                    <div class="card-body">
                        <div class="alert alert-warning mb-0" role="alert"><i class="bi bi-exclamation-triangle me-2"></i>Invalid item selected.</div>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover table-striped align-middle text-nowrap mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th scope="col" class="ps-3">Brand</th>
                                    <th scope="col">Batch</th>
                                    <th scope="col" class="text-end">Qty</th>
                                    <th scope="col" class="text-end">Price</th>
                                    <th scope="col">Expiry</th>
                                    <th scope="col">Condition</th>
                                    <th scope="col" class="text-end pe-3">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($activeItems) === 0): ?>
                                    <tr>
                                        <td colspan="7" class="text-center text-body-secondary py-5">
                                            <i class="bi bi-inbox fs-2 d-block mb-1"></i>No active stock batches for this item.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($activeItems as $item): ?>
                                        <tr>
                                            <td class="ps-3 fw-medium"><?php echo htmlspecialchars($item['brand'], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><code class="text-body"><?php echo htmlspecialchars($item['batch'], ENT_QUOTES, 'UTF-8'); ?></code></td>
                                            <td class="text-end"><?php echo htmlspecialchars($item['qty'] . ' ' . $item['unit'], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td class="text-end"><?php echo htmlspecialchars(number_format((float)$item['price'], 2), ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><?php echo htmlspecialchars($item['expiry'], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td>
                                                <?php if ($item['condition'] === 'near-expiry'): ?>
                                                    <span class="badge rounded-pill text-bg-warning"><i class="bi bi-hourglass-split me-1"></i><?php echo htmlspecialchars($item['condition'], ENT_QUOTES, 'UTF-8'); ?></span>
                                                <?php else: ?>
                                                    <span class="badge rounded-pill text-bg-success"><i class="bi bi-check2 me-1"></i><?php echo htmlspecialchars($item['condition'], ENT_QUOTES, 'UTF-8'); ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end pe-3">
                                                <button class="btn btn-outline-secondary btn-sm edit-row" type="button"
                                                    data-bs-toggle="modal" data-bs-target="#stockOutModal"
                                                    data-stock-id="<?php echo (int)$item['id']; ?>"
                                                    data-brand="<?php echo htmlspecialchars($item['brand'], ENT_QUOTES, 'UTF-8'); ?>"
                                                    data-batch="<?php echo htmlspecialchars($item['batch'], ENT_QUOTES, 'UTF-8'); ?>"
                                                    data-qty="<?php echo htmlspecialchars($item['qty'], ENT_QUOTES, 'UTF-8'); ?>"
                                                    data-unit="<?php echo htmlspecialchars($item['unit'], ENT_QUOTES, 'UTF-8'); ?>"
                                                ><i class="bi bi-pencil-square me-1"></i>Edit</button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

        </div>
    </main>

    <!-- Stock In modal -->
    <div class="modal fade" id="stockInModal" tabindex="-1" aria-labelledby="stockInModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="post" action="/cl-test/invtest/master.php?ingmaster-Id=<?php echo urlencode($ingmasterId); ?>">
                    <input type="hidden" name="action" value="stock_in">
                    <div class="modal-header">
                        <h2 class="modal-title fs-5" id="stockInModalLabel"><i class="bi bi-box-arrow-in-down text-success me-2"></i>Stock In</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="stockBrand" class="form-label">Brand</label>
                                <input id="stockBrand" name="ing-Brand" type="text" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label for="stockBatch" class="form-label">Batch</label>
                                <input id="stockBatch" name="ing-BatchCode" type="text" class="form-control" required>
                            </div>
                            <div class="col-md-4">
                                <label for="stockQty" class="form-label">Quantity</label>
                                <input id="stockQty" name="ing-StockQty" type="number" step="0.01" class="form-control" required>
                            </div>
                            <div class="col-md-4">
                                <label for="stockUnit" class="form-label">Unit Type</label>
                                <select id="stockUnit" name="ing-UnitType" class="form-select" required>
                                    <option value="g">g</option>
                                    <option value="kg">kg</option>
                                    <option value="mL">mL</option>
                                    <option value="L">L</option>
                                    <option value="pcs">pcs</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label for="stockPrice" class="form-label">Price</label>
                                <input id="stockPrice" name="ing-Price" type="number" step="0.01" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label for="stockExpiry" class="form-label">Expiry Date</label>
                                <input id="stockExpiry" name="ing-ExpiryDate" type="date" class="form-control" required>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">Add</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Stock Out modal -->
    <div class="modal fade" id="stockOutModal" tabindex="-1" aria-labelledby="stockOutModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="post" action="/cl-test/invtest/master.php?ingmaster-Id=<?php echo urlencode($ingmasterId); ?>">
                    <input type="hidden" name="action" value="stock_out">
                    <input type="hidden" id="outStockId" name="ing-Id" value="">
                    <div class="modal-header">
                        <h2 class="modal-title fs-5" id="stockOutModalLabel"><i class="bi bi-box-arrow-up text-danger me-2"></i>Stock Out</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="outBrand" class="form-label">Brand</label>
                                <select id="outBrand" name="outBrand" class="form-select" required><option value="">Select brand</option></select>
                            </div>
                            <div class="col-md-6">
                                <label for="outBatch" class="form-label">Batch</label>
                                <select id="outBatch" name="outBatch" class="form-select" required><option value="">Select batch</option></select>
                            </div>
                            <div class="col-12">
                                <div class="d-flex align-items-center justify-content-between rounded border bg-body-tertiary px-3 py-2">
                                    <span class="small text-body-secondary">Available</span>
                                    <span id="outAvailable" class="fw-semibold"></span>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label for="outQty" class="form-label">Quantity</label>
                                <input id="outQty" name="outQty" type="number" step="0.01" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label for="outUnit" class="form-label">Unit</label>
                                <select id="outUnit" name="outUnit" class="form-select" required>
                                    <option value="g">g</option>
                                    <option value="kg">kg</option>
                                    <option value="mL">mL</option>
                                    <option value="L">L</option>
                                    <option value="pcs">pcs</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="outDate" class="form-label">Date</label>
                                <input id="outDate" name="outDate" type="date" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label for="outReason" class="form-label">Reason</label>
                                <select id="outReason" name="outReason" class="form-select" required>
                                    <option value="">Select reason</option>
                                    <option value="Event Order">Event Order</option>
                                    <option value="Error/Burned">Error/Burned</option>
                                    <option value="Adjustment">Adjustment</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Update</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    <script>
        const stockItems = <?php echo json_encode($activeItems, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
        const stockOutModal = document.getElementById('stockOutModal');
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

        // Bootstrap opens the modal; we prepare the form just before it shows.
        // event.relatedTarget is the button that was clicked: the header
        // "Stock Out" button (blank form) or a row's "Edit" button (pre-filled).
        stockOutModal.addEventListener('show.bs.modal', (event) => {
            const trigger = event.relatedTarget;
            populateStockOutOptions();

            if (trigger && trigger.classList.contains('edit-row')) {
                outBrand.value = trigger.dataset.brand;
                filterBatchesByBrand(trigger.dataset.brand);
                outBatch.value = trigger.dataset.stockId;
                updateStockOutDisplay();
            } else {
                outStockId.value = '';
                outBrand.value = '';
                outBatch.value = '';
                outAvailable.textContent = '';
                outUnit.value = '';
            }

            outQty.value = '';
            outDate.value = new Date().toISOString().split('T')[0];
        });

        outBatch.addEventListener('change', () => {
            updateStockOutDisplay();
        });

        outBrand.addEventListener('change', () => {
            filterBatchesByBrand(outBrand.value);
        });
    </script>
</body>
</html>
