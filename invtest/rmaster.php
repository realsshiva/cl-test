<?php
require_once __DIR__ . '/db.php';

$recipeId = trim($_GET['rec-Id'] ?? $_POST['rec-Id'] ?? '');
$selectedVariantId = trim($_GET['recvar-Id'] ?? $_POST['recvar-Id'] ?? '');
$requestedSize = trim($_GET['recvar-SizeOrPcs'] ?? '');
$error = '';
$unitGroups = [
    'Weight' => ['g', 'kg', 'mg', 'lb', 'oz'],
    'Volume' => ['ml', 'L', 'cup', 'tbsp', 'tsp', 'fl oz'],
    'Count' => ['pcs'],
];
$allowedUnits = array_merge(...array_values($unitGroups));

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function redirectToRecipe(string $recipeId, string $variantId = ''): void
{
    $url = '/cl-test/invtest/rmaster.php?rec-Id=' . rawurlencode($recipeId);
    if ($variantId !== '') {
        $url .= '&recvar-Id=' . rawurlencode($variantId);
    }
    header('Location: ' . $url);
    exit;
}

function ingredientIdByName(mysqli $conn, string $name): string
{
    $query = $conn->prepare('SELECT `ingmaster-Id` FROM `m11-ingredient_master` WHERE LOWER(`ingmaster-Name`) = LOWER(?) LIMIT 1');
    $query->bind_param('s', $name);
    $query->execute();
    $query->bind_result($ingredientId);
    $found = $query->fetch() ? (string)$ingredientId : '';
    $query->close();
    return $found;
}

function convertToBaseUnit(float $quantity, string $unit): ?float
{
    $unit = strtolower(trim($unit)) === 'ml' ? 'ml' : trim($unit);
    $factors = [
        'g' => 1.0, 'kg' => 1000.0, 'mg' => 0.001, 'lb' => 453.592, 'oz' => 28.3495,
        'ml' => 1.0, 'L' => 1000.0, 'cup' => 240.0, 'tbsp' => 15.0, 'tsp' => 5.0, 'fl oz' => 29.5735,
        'pcs' => 1.0,
    ];
    return array_key_exists($unit, $factors) ? $quantity * $factors[$unit] : null;
}

function unitFamily(string $unit): ?string
{
    $unit = strtolower(trim($unit)) === 'ml' ? 'ml' : trim($unit);
    if (in_array($unit, ['g', 'kg', 'mg', 'lb', 'oz'], true)) {
        return 'weight';
    }
    if (in_array($unit, ['ml', 'L', 'cup', 'tbsp', 'tsp', 'fl oz'], true)) {
        return 'volume';
    }
    return $unit === 'pcs' ? 'count' : null;
}

function renderUnitOptions(array $unitGroups, string $selected = ''): void
{
    foreach ($unitGroups as $group => $units) {
        echo '<optgroup label="' . e($group) . '">';
        foreach ($units as $unit) {
            $isSelected = $unit === $selected ? ' selected' : '';
            echo '<option value="' . e($unit) . '"' . $isSelected . '>' . e($unit) . '</option>';
        }
        echo '</optgroup>';
    }
}

function fifoCostPerRecipe(mysqli $conn, string $ingredientId, float $recipeQuantity, string $recipeUnit): ?float
{
    $recipeBaseQuantity = convertToBaseUnit($recipeQuantity, $recipeUnit);
    $recipeFamily = unitFamily($recipeUnit);
    if ($recipeBaseQuantity === null || $recipeFamily === null) {
        return null;
    }

    $stock = $conn->prepare(
        'SELECT `ing-OriginalQty`, `ing-Price`, `ing-UnitType`
         FROM `m111-ingredient_item`
         WHERE `ingmaster-Id` = ? AND `ing-StockQty` > 0 AND `Ing-ExpiryDate` > CURDATE()
         ORDER BY `Ing-ExpiryDate` ASC, `ing-Id` ASC'
    );
    $stock->bind_param('s', $ingredientId);
    $stock->execute();
    $stock->bind_result($originalQuantity, $price, $stockUnit);
    while ($stock->fetch()) {
        if (unitFamily((string)$stockUnit) === $recipeFamily) {
            $originalBaseQuantity = convertToBaseUnit((float)$originalQuantity, (string)$stockUnit);
            $stock->close();
            return $originalBaseQuantity && $originalBaseQuantity > 0
                ? $recipeBaseQuantity * ((float)$price / $originalBaseQuantity)
                : null;
        }
    }
    $stock->close();
    return null;
}

function refreshVariantCost(mysqli $conn, string $variantId): void
{
    $ingredients = $conn->prepare('SELECT `ingmaster-Id`, `recing-QtyNeed`, `recing-UnitType` FROM `m2222-recipe_ingredient` WHERE `recvar-Id` = ?');
    $ingredients->bind_param('s', $variantId);
    $ingredients->execute();
    $result = $ingredients->get_result();
    $total = 0.0;
    while ($row = $result->fetch_assoc()) {
        $lineCost = fifoCostPerRecipe($conn, $row['ingmaster-Id'], (float)$row['recing-QtyNeed'], $row['recing-UnitType']);
        if ($lineCost !== null) {
            $total += $lineCost;
        }
    }
    $ingredients->close();

    $update = $conn->prepare('UPDATE `m222-recipe_variant` SET `recvar-RecCost` = ? WHERE `recvar-Id` = ?');
    $update->bind_param('ds', $total, $variantId);
    $update->execute();
    $update->close();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_variant' && $recipeId !== '') {
        $variantId = trim($_POST['recvar-Id'] ?? '');
        $servings = (int)($_POST['recvar-SvgPerBatch'] ?? 0);
        $size = trim($_POST['recvar-SizeOrPcs'] ?? '');
        $foundation = trim($_POST['recvar-Foundation'] ?? '');
        $names = $_POST['ingredient-name'] ?? [];
        $quantities = $_POST['recing-QtyNeed'] ?? [];
        $units = $_POST['recing-UnitType'] ?? [];
        $rows = [];

        foreach ($names as $index => $name) {
            $name = trim((string)$name);
            $quantity = (float)($quantities[$index] ?? 0);
            $unit = trim((string)($units[$index] ?? ''));
            if ($name !== '' || $quantity > 0) {
                $ingredientId = ingredientIdByName($conn, $name);
                if ($ingredientId === '') {
                    $error = 'Ingredient "' . $name . '" does not exist in the ingredient master.';
                    break;
                }
                if ($quantity <= 0 || !in_array($unit, $allowedUnits, true)) {
                    $error = 'Every ingredient row must have a valid quantity and unit.';
                    break;
                }
                $rows[] = ['id' => $ingredientId, 'quantity' => $quantity, 'unit' => $unit];
            }
        }

        // Only trigger this generic error if an error hasn't already been set
        if ($error === '' && ($variantId === '' || strlen($variantId) > 15 || $servings <= 0 || $size === '' || count($rows) === 0)) {
            $error = 'Enter a unique Recipe ID, servings, size/pieces, and at least one ingredient.';
        }

        if ($error === '') {
            $exists = $conn->prepare('SELECT 1 FROM `m222-recipe_variant` WHERE `recvar-Id` = ? LIMIT 1');
            $exists->bind_param('s', $variantId);
            $exists->execute();
            if ($exists->get_result()->num_rows > 0) {
                $error = 'That Recipe ID already belongs to a variant.';
            }
            $exists->close();
        }

        if ($error === '') {
            $conn->begin_transaction();
            try {
                $insertVariant = $conn->prepare(
                    'INSERT INTO `m222-recipe_variant` (`recvar-Id`, `rec-Id`, `recvar-SvgPerBatch`, `recvar-SizeOrPcs`, `recvar-Foundation`, `recvar-RecCost`) VALUES (?, ?, ?, ?, NULLIF(?, \'\'), 0)'
                );
                $insertVariant->bind_param('ssiss', $variantId, $recipeId, $servings, $size, $foundation);
                $insertVariant->execute();
                $insertVariant->close();

                $insertIngredient = $conn->prepare('INSERT INTO `m2222-recipe_ingredient` (`recvar-Id`, `ingmaster-Id`, `recing-QtyNeed`, `recing-UnitType`) VALUES (?, ?, ?, ?)');
                foreach ($rows as $row) {
                    $insertIngredient->bind_param('ssds', $variantId, $row['id'], $row['quantity'], $row['unit']);
                    $insertIngredient->execute();
                }
                $insertIngredient->close();
                refreshVariantCost($conn, $variantId);
                $conn->commit();
                redirectToRecipe($recipeId, $variantId);
            } catch (Throwable $exception) {
                $conn->rollback();
                $error = 'The variant could not be saved. Check the database constraints.';
            }
        }
    }

    if ($action === 'save_ingredient' || $action === 'delete_ingredient') {
        $variantId = trim($_POST['recvar-Id'] ?? '');
        $ingredientRowId = (int)($_POST['recing-Id'] ?? 0);
        if ($action === 'delete_ingredient') {
            $delete = $conn->prepare('DELETE FROM `m2222-recipe_ingredient` WHERE `recing-Id` = ? AND `recvar-Id` = ?');
            $delete->bind_param('is', $ingredientRowId, $variantId);
            $delete->execute();
            $delete->close();
        } else {
            $name = trim($_POST['ingredient-name'] ?? '');
            $quantity = (float)($_POST['recing-QtyNeed'] ?? 0);
            $unit = trim($_POST['recing-UnitType'] ?? '');
            $ingredientId = ingredientIdByName($conn, $name);
            if ($ingredientId === '' || $quantity <= 0 || !in_array($unit, $allowedUnits, true) || $variantId === '') {
                $error = 'Choose an existing ingredient and enter a valid quantity and unit.';
            } elseif ($ingredientRowId <= 0) {
                $insert = $conn->prepare('INSERT INTO `m2222-recipe_ingredient` (`recvar-Id`, `ingmaster-Id`, `recing-QtyNeed`, `recing-UnitType`) VALUES (?, ?, ?, ?)');
                $insert->bind_param('ssds', $variantId, $ingredientId, $quantity, $unit);
                $insert->execute();
                $insert->close();
            } else {
                $update = $conn->prepare('UPDATE `m2222-recipe_ingredient` SET `ingmaster-Id` = ?, `recing-QtyNeed` = ?, `recing-UnitType` = ? WHERE `recing-Id` = ? AND `recvar-Id` = ?');
                $update->bind_param('sdsis', $ingredientId, $quantity, $unit, $ingredientRowId, $variantId);
                $update->execute();
                $update->close();
            }
        }
        if ($error === '') {
            refreshVariantCost($conn, $variantId);
            redirectToRecipe($recipeId, $variantId);
        }
    }
}

$recipe = null;
if ($recipeId !== '') {
    $query = $conn->prepare('SELECT `rec-Name`, `rec-PhotoPath`, `reccat-Id` FROM `m22-recipe_master` WHERE `rec-Id` = ? LIMIT 1');
    $query->bind_param('s', $recipeId);
    $query->execute();
    $recipe = $query->get_result()->fetch_assoc();
    $query->close();
}

$variants = [];
if ($recipe) {
    $query = $conn->prepare('SELECT `recvar-Id`, `recvar-SvgPerBatch`, `recvar-SizeOrPcs`, `recvar-Foundation`, `recvar-RecCost` FROM `m222-recipe_variant` WHERE `rec-Id` = ? ORDER BY `recvar-SizeOrPcs`, `recvar-Foundation`');
    $query->bind_param('s', $recipeId);
    $query->execute();
    $result = $query->get_result();
    while ($row = $result->fetch_assoc()) {
        $variants[] = $row;
    }
    $query->close();
}

$selectedVariant = null;
foreach ($variants as $variant) {
    $sameRequestedSize = $requestedSize === '' || (string)$variant['recvar-SizeOrPcs'] === $requestedSize;
    if ($sameRequestedSize && $variant['recvar-Id'] === $selectedVariantId) {
        $selectedVariant = $variant;
        break;
    }
}

if ($selectedVariant === null && $requestedSize !== '') {
    foreach ($variants as $variant) {
        if ((string)$variant['recvar-SizeOrPcs'] === $requestedSize) {
            $selectedVariant = $variant;
            $selectedVariantId = $variant['recvar-Id'];
            break;
        }
    }
}
if ($selectedVariant === null && count($variants) > 0) {
    $selectedVariant = $variants[0];
    $selectedVariantId = $selectedVariant['recvar-Id'];
}

$sizeOptions = [];
foreach ($variants as $variant) {
    $size = (string)$variant['recvar-SizeOrPcs'];
    if (!in_array($size, $sizeOptions, true)) {
        $sizeOptions[] = $size;
    }
}
$activeSize = $selectedVariant['recvar-SizeOrPcs'] ?? ($sizeOptions[0] ?? '');
$foundationOptions = [];
$foundationVariantIds = [];
foreach ($variants as $variant) {
    if ((string)$variant['recvar-SizeOrPcs'] !== $activeSize) {
        continue;
    }
    $foundation = trim((string)$variant['recvar-Foundation']);
    if ($foundation !== '' && !in_array($foundation, $foundationOptions, true)) {
        $foundationOptions[] = $foundation;
        $foundationVariantIds[$foundation] = $variant['recvar-Id'];
    }
}

$ingredients = [];
if ($selectedVariant) {
    refreshVariantCost($conn, $selectedVariantId);
    $costQuery = $conn->prepare('SELECT `recvar-RecCost` FROM `m222-recipe_variant` WHERE `recvar-Id` = ?');
    $costQuery->bind_param('s', $selectedVariantId);
    $costQuery->execute();
    $selectedCost = 0.0;
    $costQuery->bind_result($selectedCost);
    $costQuery->fetch();
    $costQuery->close();
    $selectedVariant['recvar-RecCost'] = $selectedCost;

    $query = $conn->prepare(
        'SELECT ri.`recing-Id`, ri.`recing-QtyNeed`, ri.`recing-UnitType` AS `recing-UnitType`, ri.`ingmaster-Id`, im.`ingmaster-Name`
         FROM `m2222-recipe_ingredient` ri
         JOIN `m11-ingredient_master` im ON im.`ingmaster-Id` = ri.`ingmaster-Id`
         WHERE ri.`recvar-Id` = ? ORDER BY ri.`recing-Id`'
    );
    $query->bind_param('s', $selectedVariantId);
    $query->execute();
    $result = $query->get_result();
    while ($row = $result->fetch_assoc()) {
        $lineCost = fifoCostPerRecipe($conn, $row['ingmaster-Id'], (float)$row['recing-QtyNeed'], $row['recing-UnitType']);
        $row['cost_per_unit'] = $lineCost === null || (float)$row['recing-QtyNeed'] <= 0
            ? null
            : $lineCost / (float)$row['recing-QtyNeed'];
        $ingredients[] = $row;
    }
    $query->close();
}

$options = [];
$result = $conn->query('SELECT `ingmaster-Name` FROM `m11-ingredient_master` ORDER BY `ingmaster-Name`');
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $options[] = $row['ingmaster-Name'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($recipe['rec-Name'] ?? 'Recipe detail'); ?></title>
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
                <li class="breadcrumb-item"><a href="/cl-test/invtest/rcategory.php">Recipe Categories</a></li>
                <?php if ($recipe): ?>
                    <li class="breadcrumb-item"><a href="/cl-test/invtest/ritem.php?reccat-Id=<?php echo rawurlencode($recipe['reccat-Id'] ?? ''); ?>"><?php echo e($recipe['reccat-Id'] ?? ''); ?></a></li>
                <?php endif; ?>
                <li class="breadcrumb-item active" aria-current="page"><?php echo e($recipe['rec-Name'] ?? 'Recipe not found'); ?></li>
            </ol>
        </nav>

        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
            <h1 class="page-title mb-0"><?php echo e($recipe['rec-Name'] ?? 'Recipe not found'); ?></h1>
            <div class="d-flex gap-2">
                <a class="btn btn-outline-secondary" href="/cl-test/invtest/ritem.php?reccat-Id=<?php echo rawurlencode($recipe['reccat-Id'] ?? ''); ?>">
                    <i class="bi bi-arrow-left me-1"></i>Back to recipes
                </a>
                <?php if ($recipe): ?>
                    <button class="btn btn-primary" id="openVariantModal" type="button" data-bs-toggle="modal" data-bs-target="#variantModal">
                        <i class="bi bi-plus-lg me-1"></i>Add Variant
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($error !== ''): ?>
            <div class="alert alert-warning alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle me-2"></i><?php echo e($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if (!$recipe): ?>
            <div class="alert alert-secondary" role="alert">
                <i class="bi bi-info-circle me-2"></i>Invalid recipe selected. Open this page from a recipe in ritem.php.
            </div>
        <?php else: ?>
            <div class="row g-4 mb-4">

                <!-- Recipe summary -->
                <div class="col-lg-4">
                    <div class="card h-100 border-0 shadow-sm overflow-hidden">
                        <?php if (!empty($recipe['rec-PhotoPath'])): ?>
                            <img class="card-img-top recipe-photo" src="<?php echo e($recipe['rec-PhotoPath']); ?>" alt="imageofproduct">
                        <?php else: ?>
                            <div class="recipe-photo bg-secondary-subtle text-body-secondary d-flex flex-column align-items-center justify-content-center" aria-label="No recipe photo">
                                <i class="bi bi-image fs-1"></i>
                                <small>No photo</small>
                            </div>
                        <?php endif; ?>
                        <div class="card-body">
                            <dl class="row small mb-0">
                                <dt class="col-5 text-body-secondary fw-normal">Item name</dt>
                                <dd class="col-7 fw-semibold"><?php echo e($recipe['rec-Name']); ?></dd>
                                <dt class="col-5 text-body-secondary fw-normal">Recipe ID</dt>
                                <dd class="col-7"><code class="text-body"><?php echo e($recipeId); ?></code></dd>
                                <dt class="col-5 text-body-secondary fw-normal">Variants</dt>
                                <dd class="col-7 mb-0"><span class="badge rounded-pill text-bg-secondary"><?php echo count($variants); ?></span></dd>
                            </dl>
                        </div>
                    </div>
                </div>

                <!-- Variant selector + cost summary -->
                <div class="col-lg-8">
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-header bg-body py-3">
                            <h2 class="h6 fw-semibold mb-0"><i class="bi bi-sliders me-2 text-primary"></i>Variant</h2>
                        </div>
                        <div class="card-body">
                            <?php if (count($variants) > 0): ?>
                                <form class="variant-selector row g-3" method="get" action="/cl-test/invtest/rmaster.php">
                                    <input type="hidden" name="rec-Id" value="<?php echo e($recipeId); ?>">
                                    <div class="col-md-6">
                                        <label for="sizeSelect" class="form-label fw-semibold small">Size / Pieces</label>
                                        <select id="sizeSelect" name="recvar-SizeOrPcs" class="form-select">
                                            <?php foreach ($sizeOptions as $size): ?><option value="<?php echo e($size); ?>" <?php echo $size === $activeSize ? 'selected' : ''; ?>><?php echo e($size); ?></option><?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="foundationSelect" class="form-label fw-semibold small">Foundation</label>
                                        <select id="foundationSelect" name="recvar-Id" class="form-select" <?php echo count($foundationOptions) === 0 ? 'disabled' : ''; ?>>
                                            <?php if (count($foundationOptions) > 0): foreach ($foundationOptions as $foundation): ?><option value="<?php echo e($foundationVariantIds[$foundation]); ?>" <?php echo ($selectedVariant && $selectedVariant['recvar-Id'] === $foundationVariantIds[$foundation]) ? 'selected' : ''; ?>><?php echo e($foundation); ?></option><?php endforeach; else: ?><option value="">No foundation for this size</option><?php endif; ?>
                                        </select>
                                    </div>
                                </form>
                            <?php else: ?>
                                <div class="text-center text-body-secondary py-4">
                                    <i class="bi bi-layers fs-2 d-block mb-2"></i>
                                    <p class="mb-3">No variants have been added to this recipe yet.</p>
                                    <button class="btn btn-primary btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#variantModal">
                                        <i class="bi bi-plus-lg me-1"></i>Add Variant
                                    </button>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ($selectedVariant): ?>
                        <?php
                        $servingsPerBatch = (int)$selectedVariant['recvar-SvgPerBatch'];
                        $recipeCost = (float)$selectedVariant['recvar-RecCost'];
                        ?>
                        <div class="row row-cols-1 row-cols-sm-3 g-3">
                            <div class="col">
                                <div class="card h-100 border-0 shadow-sm">
                                    <div class="card-body">
                                        <div class="stat-label mb-1"><i class="bi bi-people me-1"></i>Servings per batch</div>
                                        <div class="stat-value"><?php echo $servingsPerBatch; ?></div>
                                    </div>
                                </div>
                            </div>
                            <div class="col">
                                <div class="card h-100 border-0 shadow-sm">
                                    <div class="card-body">
                                        <div class="stat-label mb-1"><i class="bi bi-cash-stack me-1"></i>Recipe Cost</div>
                                        <div class="stat-value text-success"><?php echo number_format($recipeCost, 2); ?></div>
                                    </div>
                                </div>
                            </div>
                            <div class="col">
                                <div class="card h-100 border-0 shadow-sm">
                                    <div class="card-body">
                                        <div class="stat-label mb-1"><i class="bi bi-pie-chart me-1"></i>Cost per serving</div>
                                        <div class="stat-value"><?php echo $servingsPerBatch > 0 ? number_format($recipeCost / $servingsPerBatch, 2) : '-'; ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($selectedVariant): ?>
                <!-- Ingredient list -->
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-body d-flex flex-wrap align-items-center justify-content-between gap-2 py-3">
                        <h2 class="h6 fw-semibold mb-0">
                            <i class="bi bi-list-ul me-2 text-primary"></i>Ingredients
                            <span class="badge rounded-pill text-bg-light border ms-1"><?php echo count($ingredients); ?></span>
                        </h2>
                        <button class="btn btn-primary btn-sm" id="openIngredientModal" type="button" data-bs-toggle="modal" data-bs-target="#ingredientModal">
                            <i class="bi bi-plus-lg me-1"></i>Add ingredient
                        </button>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover table-striped align-middle text-nowrap mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th scope="col" class="ps-3">Ingredient Name</th>
                                    <th scope="col" class="text-end">Quantity Need</th>
                                    <th scope="col">Unit</th>
                                    <th scope="col" class="text-end">Cost Per Unit</th>
                                    <th scope="col" class="text-end">Cost Per Recipe</th>
                                    <th scope="col" class="text-end pe-3">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($ingredients) === 0): ?>
                                    <tr>
                                        <td colspan="6" class="text-center text-body-secondary py-5">
                                            <i class="bi bi-inbox fs-2 d-block mb-1"></i>No ingredients for this variant yet.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                                <?php foreach ($ingredients as $ingredient): $lineCost = $ingredient['cost_per_unit'] === null ? null : (float)$ingredient['cost_per_unit'] * (float)$ingredient['recing-QtyNeed']; ?>
                                    <tr>
                                        <td class="ps-3 fw-medium"><?php echo e($ingredient['ingmaster-Name']); ?></td>
                                        <td class="text-end"><?php echo number_format((float)$ingredient['recing-QtyNeed'], 2); ?></td>
                                        <td><?php echo e($ingredient['recing-UnitType']); ?></td>
                                        <td class="text-end">
                                            <?php if ($ingredient['cost_per_unit'] === null): ?>
                                                <span class="badge bg-warning-subtle text-warning-emphasis fw-normal">No compatible stock</span>
                                            <?php else: ?>
                                                <?php echo number_format((float)$ingredient['cost_per_unit'], 4); ?>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <?php if ($lineCost === null): ?>
                                                <span class="badge bg-warning-subtle text-warning-emphasis fw-normal">No compatible stock</span>
                                            <?php else: ?>
                                                <span class="fw-semibold"><?php echo number_format($lineCost, 2); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end pe-3 text-nowrap">
                                            <button type="button" class="btn btn-outline-secondary btn-sm edit-ingredient"
                                                data-bs-toggle="modal" data-bs-target="#ingredientModal"
                                                data-id="<?php echo (int)$ingredient['recing-Id']; ?>"
                                                data-name="<?php echo e($ingredient['ingmaster-Name']); ?>"
                                                data-quantity="<?php echo e($ingredient['recing-QtyNeed']); ?>"
                                                data-unit="<?php echo e($ingredient['recing-UnitType']); ?>"
                                            ><i class="bi bi-pencil-square me-1"></i>Edit</button>
                                            <form class="d-inline" method="post">
                                                <input type="hidden" name="action" value="delete_ingredient">
                                                <input type="hidden" name="rec-Id" value="<?php echo e($recipeId); ?>">
                                                <input type="hidden" name="recvar-Id" value="<?php echo e($selectedVariantId); ?>">
                                                <input type="hidden" name="recing-Id" value="<?php echo (int)$ingredient['recing-Id']; ?>">
                                                <button class="btn btn-outline-danger btn-sm" type="submit"><i class="bi bi-trash3 me-1"></i>Delete</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>

    </div>
</main>

<!-- Add Variant modal -->
<div class="modal fade" id="variantModal" tabindex="-1" aria-labelledby="variantModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <form method="post" class="d-flex flex-column overflow-hidden">
                <input type="hidden" name="action" value="add_variant">
                <input type="hidden" name="rec-Id" value="<?php echo e($recipeId); ?>">
                <div class="modal-header">
                    <h2 class="modal-title fs-5" id="variantModalLabel">Add Variant</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label for="newVariantId" class="form-label">Recipe Variant ID</label>
                            <input id="newVariantId" name="recvar-Id" class="form-control" maxlength="15" required>
                            <div class="form-text">Up to 15 characters, must be unique.</div>
                        </div>
                        <div class="col-md-6">
                            <label for="newVariantServings" class="form-label">Recipe Servings Per Batch</label>
                            <input id="newVariantServings" name="recvar-SvgPerBatch" class="form-control" type="number" min="1" required>
                        </div>
                        <div class="col-md-6">
                            <label for="newVariantSize" class="form-label">Size or Pieces</label>
                            <input id="newVariantSize" name="recvar-SizeOrPcs" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label for="newVariantFoundation" class="form-label">Foundation <span class="text-body-secondary">(optional)</span></label>
                            <input id="newVariantFoundation" name="recvar-Foundation" class="form-control">
                        </div>
                    </div>

                    <h3 class="h6 fw-semibold mb-2">Ingredients</h3>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-2">
                            <thead class="table-light">
                                <tr>
                                    <th scope="col">Ingredient name</th>
                                    <th scope="col" style="width: 22%">Quantity Need</th>
                                    <th scope="col" style="width: 22%">Unit</th>
                                    <th scope="col" style="width: 1%"><span class="visually-hidden">Remove</span></th>
                                </tr>
                            </thead>
                            <tbody id="ingredientRows">
                                <?php for ($i = 0; $i < 3; $i++): ?>
                                    <tr>
                                        <td><input name="ingredient-name[]" class="form-control form-control-sm" list="ingredientOptions" placeholder="Start typing…" aria-label="Ingredient name"></td>
                                        <td><input name="recing-QtyNeed[]" class="form-control form-control-sm" type="number" min="0" step="0.01" aria-label="Quantity need"></td>
                                        <td><select name="recing-UnitType[]" class="form-select form-select-sm" aria-label="Unit"><option value="">Select unit</option><?php renderUnitOptions($unitGroups); ?></select></td>
                                        <td><button class="btn btn-outline-danger btn-sm remove-row" type="button" aria-label="Remove row"><i class="bi bi-x-lg"></i></button></td>
                                    </tr>
                                <?php endfor; ?>
                            </tbody>
                        </table>
                    </div>
                    <button class="btn btn-outline-primary btn-sm" id="addIngredientRow" type="button"><i class="bi bi-plus-lg me-1"></i>Add item</button>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-primary" type="submit">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add / Edit ingredient modal -->
<div class="modal fade" id="ingredientModal" tabindex="-1" aria-labelledby="ingredientModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="action" value="save_ingredient">
                <input type="hidden" name="rec-Id" value="<?php echo e($recipeId); ?>">
                <input type="hidden" name="recvar-Id" value="<?php echo e($selectedVariantId); ?>">
                <input type="hidden" id="recingId" name="recing-Id" value="0">
                <div class="modal-header">
                    <h2 class="modal-title fs-5" id="ingredientModalTitle">Add ingredient</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label for="ingredientName" class="form-label">Ingredient Name</label>
                            <input id="ingredientName" name="ingredient-name" class="form-control" list="ingredientOptions" required>
                        </div>
                        <div class="col-md-6">
                            <label for="ingredientQuantity" class="form-label">Quantity Need</label>
                            <input id="ingredientQuantity" name="recing-QtyNeed" class="form-control" type="number" min="0" step="0.01" required>
                        </div>
                        <div class="col-md-6">
                            <label for="ingredientUnit" class="form-label">Unit</label>
                            <select id="ingredientUnit" name="recing-UnitType" class="form-select" required><option value="">Select unit</option><?php renderUnitOptions($unitGroups); ?></select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-primary" type="submit">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<datalist id="ingredientOptions"><?php foreach ($options as $option): ?><option value="<?php echo e($option); ?>"></option><?php endforeach; ?></datalist>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
<script>
    // Ingredient modal: Bootstrap opens it; we fill the form just before it shows.
    // event.relatedTarget is the clicked button: a row's "Edit" (pre-fill) or "Add ingredient" (blank).
    const ingredientModal = document.getElementById('ingredientModal');
    ingredientModal.addEventListener('show.bs.modal', event => {
        const trigger = event.relatedTarget;
        const isEdit = !!(trigger && trigger.classList.contains('edit-ingredient'));
        document.getElementById('recingId').value = isEdit ? trigger.dataset.id : '0';
        document.getElementById('ingredientName').value = isEdit ? trigger.dataset.name : '';
        document.getElementById('ingredientQuantity').value = isEdit ? trigger.dataset.quantity : '';
        document.getElementById('ingredientUnit').value = isEdit ? trigger.dataset.unit : '';
        document.getElementById('ingredientModalTitle').textContent = isEdit ? 'Edit ingredient' : 'Add ingredient';
    });

    // Size / foundation selectors reload the page for the chosen variant.
    const variantSelector = document.querySelector('.variant-selector');
    const sizeSelect = document.getElementById('sizeSelect');
    const foundationSelect = document.getElementById('foundationSelect');
    sizeSelect?.addEventListener('change', () => {
        foundationSelect.value = '';
        variantSelector.submit();
    });
    foundationSelect?.addEventListener('change', () => {
        if (foundationSelect.value) {
            variantSelector.submit();
        }
    });

    // Add Variant modal: add / remove ingredient rows.
    document.getElementById('addIngredientRow')?.addEventListener('click', () => {
        const row = document.querySelector('#ingredientRows tr').cloneNode(true);
        row.querySelectorAll('input').forEach(input => input.value = '');
        row.querySelectorAll('select').forEach(select => select.value = '');
        document.getElementById('ingredientRows').appendChild(row);
    });
    document.getElementById('ingredientRows')?.addEventListener('click', event => {
        // closest() so clicks on the icon inside the button also count
        const removeButton = event.target.closest('.remove-row');
        if (removeButton && document.querySelectorAll('#ingredientRows tr').length > 1) {
            removeButton.closest('tr').remove();
        }
    });
</script>
</body>
</html>
