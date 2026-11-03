<?php
require_once __DIR__ . '/db.php';

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function ingredientBaseQuantity(float $quantity, string $unit): float
{
    return in_array($unit, ['kg', 'L'], true) ? $quantity * 1000.0 : $quantity;
}

function ingredientThresholdBase(float $threshold, string $unit): float
{
    return in_array($unit, ['kg', 'L'], true) ? $threshold * 1000.0 : $threshold;
}

$now = new DateTimeImmutable('now');
$rawIngredients = [
    'low' => [],
    'out' => [],
    'expiring' => [],
];

try {
    $ingredientResult = $conn->query(
        'SELECT im.`ingmaster-Id` AS ingredient_id, im.`ingmaster-Name` AS ingredient_name,
                im.`ingmaster-UnitType` AS master_unit, im.`ingmaster-Threshold` AS threshold,
                c.`ingcat-Name` AS category_name, ii.`ing-Id` AS batch_id,
                ii.`ing-StockQty` AS stock_qty, ii.`ing-UnitType` AS stock_unit,
                CASE WHEN DATEDIFF(ii.`ing-ExpiryDate`, CURDATE()) BETWEEN 1 AND 3
                     THEN 1 ELSE 0 END AS expiring_soon
         FROM `m11-ingredient_master` im
         LEFT JOIN `m1-ingredient_category` c ON c.`ingcat-Id` = im.`ingcat-Id`
         LEFT JOIN `m111-ingredient_item` ii
                ON ii.`ingmaster-Id` = im.`ingmaster-Id`
               AND ii.`ing-StockQty` > 0
               AND ii.`ing-ExpiryDate` > CURDATE()
         ORDER BY c.`ingcat-Name` ASC, im.`ingmaster-Name` ASC, ii.`ing-ExpiryDate` ASC'
    );

    $ingredients = [];
    while ($row = $ingredientResult->fetch_assoc()) {
        $ingredientId = (string)$row['ingredient_id'];
        if (!isset($ingredients[$ingredientId])) {
            $ingredients[$ingredientId] = [
                'name' => (string)$row['ingredient_name'],
                'category' => (string)($row['category_name'] ?? 'Uncategorized'),
                'unit' => (string)$row['master_unit'],
                'threshold' => (float)$row['threshold'],
                'total' => 0.0,
                'expiring' => false,
            ];
        }

        if ($row['batch_id'] !== null) {
            $ingredients[$ingredientId]['total'] += ingredientBaseQuantity(
                (float)$row['stock_qty'],
                (string)$row['stock_unit']
            );
            if ((int)$row['expiring_soon'] === 1) {
                $ingredients[$ingredientId]['expiring'] = true;
            }
        }
    }

    foreach ($ingredients as $ingredient) {
        $category = $ingredient['category'] !== '' ? $ingredient['category'] : 'Uncategorized';
        $statusTotal = $ingredient['total'];
        $threshold = ingredientThresholdBase($ingredient['threshold'], $ingredient['unit']);

        if ($statusTotal <= 0.0) {
            $rawIngredients['out'][$category][] = $ingredient['name'];
        } elseif ($statusTotal <= $threshold) {
            $rawIngredients['low'][$category][] = $ingredient['name'];
        }

        if ($ingredient['expiring']) {
            $rawIngredients['expiring'][$category][] = $ingredient['name'];
        }
    }

    $finishedGoodsResult = $conn->query(
        'SELECT COUNT(*) AS stored,
                COALESCE(SUM(CASE
                    WHEN `fgm-ExpiryDate` <= DATE_ADD(CURDATE(), INTERVAL 3 DAY) THEN 1
                    ELSE 0
                END), 0) AS expiring
         FROM `m4-finishedgoods_master`
         WHERE `fgm-QtyStock` > 0 AND `fgm-ExpiryDate` > CURDATE()'
    );
    $finishedGoods = $finishedGoodsResult->fetch_assoc();

    $pendingResult = $conn->query(
        'SELECT COUNT(*) AS pending
         FROM `m3-production_batch`
         WHERE `prodbatch-Status` = \'Pending\''
    );
    $pending = $pendingResult->fetch_assoc();

    $productionResult = $conn->query(
        'SELECT COALESCE(SUM(`prodlog-QtySuccess`), 0) AS success,
                COALESCE(SUM(`prodlog-QtyError`), 0) AS errors
         FROM `m33-production_log`
         WHERE DATE(`prodlog-Timestamp`) = CURDATE()'
    );
    $production = $productionResult->fetch_assoc();
} catch (mysqli_sql_exception $exception) {
    http_response_code(500);
    die('Dashboard data could not be loaded: ' . e($exception->getMessage()));
}

$countFor = static function (array $categories): int {
    $count = 0;
    foreach ($categories as $names) {
        $count += count($names);
    }
    return $count;
};

$rawCounts = [
    'low' => $countFor($rawIngredients['low']),
    'out' => $countFor($rawIngredients['out']),
    'expiring' => $countFor($rawIngredients['expiring']),
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/cl-test/invtest/nav.css">
    <style>
        /* Expandable raw-ingredient cards (native <details>, no JS needed) */
        .metric-card > summary { cursor: pointer; list-style: none; }
        .metric-card > summary::-webkit-details-marker { display: none; }
        .metric-card .metric-chevron { transition: transform 0.15s ease; }
        .metric-card[open] .metric-chevron { transform: rotate(180deg); }
        @media (prefers-reduced-motion: reduce) { .metric-card .metric-chevron { transition: none; } }
    </style>
</head>
<body class="bg-body-tertiary">
    <?php include 'nav.php'; ?>

    <main class="app-main">
        <div class="container-fluid py-4 px-3 px-lg-4">
            <header class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-4">
                <h1 class="page-title mb-0">Welcome, Admin!</h1>
                <time class="text-body-secondary small" datetime="<?php echo e($now->format(DATE_ATOM)); ?>">
                    <i class="bi bi-calendar3 me-1"></i><?php echo e($now->format('l, F j, Y \a\t g:i:s A')); ?>
                </time>
            </header>

            <section class="mb-4" aria-labelledby="raw-ingredients-heading">
                <div class="d-flex align-items-center justify-content-between gap-2 mb-3">
                    <h2 class="h5 mb-0" id="raw-ingredients-heading">Raw Ingredients</h2>
                </div>
                <div class="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-3 align-items-start">
                    <?php
                    $rawCards = [
                        ['key' => 'low', 'label' => 'Low Stock Ingredients', 'icon' => 'bi-exclamation-triangle', 'tone' => 'warning'],
                        ['key' => 'out', 'label' => 'Out of Stock Ingredients', 'icon' => 'bi-x-octagon', 'tone' => 'danger'],
                        ['key' => 'expiring', 'label' => 'Expiring Soon Ingredients', 'icon' => 'bi-hourglass-split', 'tone' => 'info'],
                    ];
                    foreach ($rawCards as $card):
                        $key = $card['key'];
                    ?>
                        <div class="col">
                            <details class="metric-card card border-0 shadow-sm">
                                <summary class="card-body d-flex align-items-center gap-3">
                                    <span class="icon-tile bg-<?php echo $card['tone']; ?>-subtle text-<?php echo $card['tone']; ?>-emphasis">
                                        <i class="bi <?php echo $card['icon']; ?>"></i>
                                    </span>
                                    <span class="flex-grow-1 min-w-0">
                                        <span class="stat-label d-block"><?php echo e($card['label']); ?></span>
                                        <span class="stat-value d-block"><?php echo $rawCounts[$key]; ?></span>
                                    </span>
                                    <i class="bi bi-chevron-down metric-chevron text-body-secondary" aria-hidden="true"></i>
                                </summary>
                                <div class="card-body border-top">
                                    <?php if ($rawIngredients[$key] === []): ?>
                                        <p class="text-body-secondary small mb-0">No ingredients to show.</p>
                                    <?php else: ?>
                                        <?php foreach ($rawIngredients[$key] as $category => $names): ?>
                                            <div class="mb-3">
                                                <h3 class="stat-label mb-1"><?php echo e($category); ?></h3>
                                                <ul class="list-unstyled small mb-0">
                                                    <?php foreach ($names as $name): ?>
                                                        <li class="py-1 border-bottom"><?php echo e($name); ?></li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </details>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="mb-4" aria-labelledby="finished-goods-heading">
                <div class="d-flex align-items-center justify-content-between gap-2 mb-3">
                    <h2 class="h5 mb-0" id="finished-goods-heading">Finished Goods</h2>
                    <a class="btn btn-outline-secondary btn-sm" href="/cl-test/invtest/finishg.php" aria-label="Open Finished Goods page">
                        View <i class="bi bi-arrow-right ms-1"></i>
                    </a>
                </div>
                <div class="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-3">
                    <div class="col">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-body d-flex align-items-center gap-3">
                                <span class="icon-tile bg-primary-subtle text-primary-emphasis"><i class="bi bi-box-seam"></i></span>
                                <div>
                                    <div class="stat-label">Stored</div>
                                    <div class="stat-value"><?php echo (int)$finishedGoods['stored']; ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-body d-flex align-items-center gap-3">
                                <span class="icon-tile bg-success-subtle text-success-emphasis"><i class="bi bi-truck"></i></span>
                                <div>
                                    <div class="stat-label">Dispatched</div>
                                    <div class="stat-value">0</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-body d-flex align-items-center gap-3">
                                <span class="icon-tile bg-warning-subtle text-warning-emphasis"><i class="bi bi-hourglass-split"></i></span>
                                <div>
                                    <div class="stat-label">Expiring</div>
                                    <div class="stat-value"><?php echo (int)$finishedGoods['expiring']; ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="mb-4" aria-labelledby="production-heading">
                <div class="d-flex align-items-center justify-content-between gap-2 mb-3">
                    <h2 class="h5 mb-0" id="production-heading">Production</h2>
                    <a class="btn btn-outline-secondary btn-sm" href="/cl-test/invtest/prodqueue.php" aria-label="Open Production Queue page">
                        View <i class="bi bi-arrow-right ms-1"></i>
                    </a>
                </div>
                <div class="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-3">
                    <div class="col">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-body d-flex align-items-center gap-3">
                                <span class="icon-tile bg-secondary-subtle text-secondary-emphasis"><i class="bi bi-list-check"></i></span>
                                <div>
                                    <div class="stat-label">Pending</div>
                                    <div class="stat-value"><?php echo (int)$pending['pending']; ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-body d-flex align-items-center gap-3">
                                <span class="icon-tile bg-success-subtle text-success-emphasis"><i class="bi bi-check-circle"></i></span>
                                <div>
                                    <div class="stat-label">Success Today</div>
                                    <div class="stat-value"><?php echo (int)$production['success']; ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-body d-flex align-items-center gap-3">
                                <span class="icon-tile bg-danger-subtle text-danger-emphasis"><i class="bi bi-x-circle"></i></span>
                                <div>
                                    <div class="stat-label">Error Today</div>
                                    <div class="stat-value"><?php echo (int)$production['errors']; ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
</body>
</html>
