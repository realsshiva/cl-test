<?php
require_once __DIR__ . '/db.php';

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function toBaseQuantity(float $quantity, string $unit): float
{
    $units = [
        'mg' => 0.001, 'g' => 1.0, 'kg' => 1000.0, 'lb' => 453.592, 'oz' => 28.3495,
        'mL' => 1.0, 'ml' => 1.0, 'L' => 1000.0, 'cup' => 240.0, 'tbsp' => 15.0,
        'tsp' => 5.0, 'fl oz' => 29.5735, 'pcs' => 1.0,
    ];
    return $quantity * ($units[$unit] ?? 1.0);
}

function formatQuantity(float $quantity, string $unit): string
{
    $value = round($quantity, 2);
    return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') . ' ' . $unit;
}

function fromBaseQuantity(float $quantity, string $unit): float
{
    $units = ['mg' => 0.001, 'g' => 1.0, 'kg' => 1000.0, 'mL' => 1.0, 'ml' => 1.0, 'L' => 1000.0, 'pcs' => 1.0];
    return $quantity / ($units[$unit] ?? 1.0);
}

$categoryId = trim($_GET['reccat-Id'] ?? '');
$recipeId = trim($_GET['rec-Id'] ?? '');
$variantId = trim($_GET['recvar-Id'] ?? '');
$batchQuantity = (float)($_GET['batch-quantity'] ?? 0);
$isLoaded = ($_GET['action'] ?? '') === 'load';
$error = '';

$categories = [];
$result = $conn->query('SELECT `reccat-Id`, `reccat-Name` FROM `m2-recipe_category` ORDER BY `reccat-Name`');
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $categories[] = $row;
    }
}

$recipes = [];
$result = $conn->query('SELECT `rec-Id`, `rec-Name`, `reccat-Id` FROM `m22-recipe_master` ORDER BY `rec-Name`');
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $recipes[] = $row;
    }
}

$variants = [];
$result = $conn->query('SELECT `recvar-Id`, `rec-Id`, `recvar-SizeOrPcs`, `recvar-Foundation` FROM `m222-recipe_variant` ORDER BY `recvar-SizeOrPcs`, `recvar-Foundation`');
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $variants[] = $row;
    }
}

$categoryName = '';
$recipeName = '';
$variantName = '';
foreach ($categories as $category) {
    if ($category['reccat-Id'] === $categoryId) {
        $categoryName = $category['reccat-Name'];
        break;
    }
}
foreach ($recipes as $recipe) {
    if ($recipe['rec-Id'] === $recipeId) {
        $recipeName = $recipe['rec-Name'];
        break;
    }
}
foreach ($variants as $variant) {
    if ($variant['recvar-Id'] === $variantId) {
        $variantName = trim($variant['recvar-SizeOrPcs'] . ($variant['recvar-Foundation'] !== '' ? ' | ' . $variant['recvar-Foundation'] : ''));
        break;
    }
}

$ingredients = [];
if ($isLoaded) {
    if ($categoryId === '' || $recipeId === '' || $variantId === '' || $batchQuantity <= 0) {
        $error = 'Choose a category, product, size/pieces, and enter a batch quantity greater than zero.';
    } else {
        $query = $conn->prepare(
            'SELECT ri.`recing-QtyNeed`, ri.`recing-UnitType`, ri.`ingmaster-Id`, im.`ingmaster-Name`, im.`ingmaster-UnitType`
             FROM `m2222-recipe_ingredient` ri
             JOIN `m11-ingredient_master` im ON im.`ingmaster-Id` = ri.`ingmaster-Id`
             WHERE ri.`recvar-Id` = ? ORDER BY ri.`recing-Id`'
        );
        $query->bind_param('s', $variantId);
        $query->execute();
        $result = $query->get_result();
        while ($row = $result->fetch_assoc()) {
            $stock = $conn->prepare(
                'SELECT `ing-StockQty`, `ing-UnitType`
                 FROM `m111-ingredient_item`
                 WHERE `ingmaster-Id` = ? AND `ing-StockQty` > 0 AND `ing-ExpiryDate` > CURDATE()'
            );
            $stock->bind_param('s', $row['ingmaster-Id']);
            $stock->execute();
            $stock->bind_result($stockQuantity, $stockUnit);
            $currentStock = 0.0;
            while ($stock->fetch()) {
                $currentStock += toBaseQuantity((float)$stockQuantity, (string)$stockUnit);
            }
            $stock->close();

            $ingredients[] = [
                'name' => $row['ingmaster-Name'],
                'required' => (float)$row['recing-QtyNeed'] * $batchQuantity,
                'requiredUnit' => $row['recing-UnitType'],
                'stock' => fromBaseQuantity($currentStock, $row['ingmaster-UnitType']),
                'stockUnit' => $row['ingmaster-UnitType'],
            ];
        }
        $query->close();
        if (count($ingredients) === 0) {
            $error = 'The selected recipe variant has no ingredients.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Production Form</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/cl-test/invtest/nav.css">
</head>
<body class="bg-body-tertiary">
    <?php include 'nav.php'; ?>

    <main class="app-main">
        <div class="container-fluid py-4 px-3 px-lg-4">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-4">
                <h1 class="page-title mb-0">Production Selection</h1>
                <nav class="nav nav-pills gap-2" aria-label="Production pages">
                    <a class="nav-link active" href="prodform.php" aria-current="page"><i class="bi bi-clipboard-check me-1"></i>Production Form</a>
                    <a class="nav-link border" href="prodqueue.php"><i class="bi bi-list-task me-1"></i>Production Queue</a>
                </nav>
            </div>

            <?php if ($error !== ''): ?>
                <div class="alert alert-warning" role="alert"><i class="bi bi-exclamation-triangle me-2"></i><?php echo e($error); ?></div>
            <?php endif; ?>

            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body">
                    <form method="get" action="prodform.php">
                        <input type="hidden" name="action" value="load">
                        <div class="row g-3">
                            <div class="col-12 col-md-6">
                                <label for="category" class="form-label">Category</label>
                                <select id="category" class="form-select" name="reccat-Id" required>
                                    <option value="">Select category</option>
                                    <?php foreach ($categories as $category): ?>
                                        <option value="<?php echo e($category['reccat-Id']); ?>" <?php echo $categoryId === $category['reccat-Id'] ? 'selected' : ''; ?>><?php echo e($category['reccat-Name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12 col-md-6">
                                <label for="recipe" class="form-label">Product Name</label>
                                <select id="recipe" class="form-select" name="rec-Id" required>
                                    <option value="">Select product</option>
                                    <?php foreach ($recipes as $recipe): ?>
                                        <option value="<?php echo e($recipe['rec-Id']); ?>" data-category="<?php echo e($recipe['reccat-Id']); ?>" <?php echo $recipeId === $recipe['rec-Id'] ? 'selected' : ''; ?>><?php echo e($recipe['rec-Name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12 col-md-6">
                                <label for="variant" class="form-label">Size/Pieces and Foundation</label>
                                <select id="variant" class="form-select" name="recvar-Id" required>
                                    <option value="">Select size/pieces and foundation</option>
                                    <?php $shownVariantLabels = []; ?>
                                    <?php foreach ($variants as $variant): ?>
                                        <?php $variantLabel = trim($variant['recvar-SizeOrPcs'] . ($variant['recvar-Foundation'] !== '' ? ' | ' . $variant['recvar-Foundation'] : '')); ?>
                                        <?php if (isset($shownVariantLabels[$variant['rec-Id'] . '|' . $variantLabel])) continue; ?>
                                        <?php $shownVariantLabels[$variant['rec-Id'] . '|' . $variantLabel] = true; ?>
                                        <option value="<?php echo e($variant['recvar-Id']); ?>" data-recipe="<?php echo e($variant['rec-Id']); ?>" <?php echo $variantId === $variant['recvar-Id'] ? 'selected' : ''; ?>><?php echo e($variantLabel); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12 col-md-6">
                                <label for="batch-quantity" class="form-label">Batch Quantity</label>
                                <!-- Whole numbers only: prodqueue.php rejects decimal batch quantities on Confirm -->
                                <input id="batch-quantity" class="form-control" name="batch-quantity" type="number" min="1" step="1" value="<?php echo $batchQuantity > 0 ? e($batchQuantity) : ''; ?>" required>
                            </div>
                        </div>
                        <div class="mt-3">
                            <button class="btn btn-primary" type="submit"><i class="bi bi-arrow-down-circle me-1"></i>Load</button>
                        </div>
                    </form>
                </div>
            </div>

            <?php if ($isLoaded && $error === ''): ?>
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-body border-bottom py-3">
                        <h2 class="h6 mb-0">Ingredients Needed</h2>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr><th>Ingredients Needed</th><th class="text-end">Batch Quantity</th><th class="text-end">Current Stock</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($ingredients as $ingredient): ?>
                                    <tr>
                                        <td><?php echo e($ingredient['name']); ?></td>
                                        <td class="text-end text-nowrap"><?php echo e(formatQuantity($ingredient['required'], $ingredient['requiredUnit'])); ?></td>
                                        <td class="text-end text-nowrap"><?php echo e(formatQuantity($ingredient['stock'], $ingredient['stockUnit'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="card-footer bg-body py-3">
                        <form class="d-flex flex-wrap gap-2" method="post" action="prodqueue.php">
                            <input type="hidden" name="action" value="confirm_production">
                            <input type="hidden" name="reccat-Id" value="<?php echo e($categoryId); ?>">
                            <input type="hidden" name="rec-Id" value="<?php echo e($recipeId); ?>">
                            <input type="hidden" name="recvar-Id" value="<?php echo e($variantId); ?>">
                            <input type="hidden" name="batch-quantity" value="<?php echo e($batchQuantity); ?>">
                            <input type="hidden" name="prodbatch-Name" value="<?php echo e($categoryName . ' | ' . $recipeName . ' | ' . $variantName); ?>">
                            <button class="btn btn-primary" type="submit"><i class="bi bi-check2-circle me-1"></i>Confirm</button>
                            <a class="btn btn-outline-secondary" href="prodform.php">Clear</a>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    <script>
        const category = document.getElementById('category');
        const recipe = document.getElementById('recipe');
        const variant = document.getElementById('variant');

        function filterOptions(select, attribute, value) {
            Array.from(select.options).forEach((option, index) => {
                option.hidden = index > 0 && option.dataset[attribute] !== value;
            });
            if (select.selectedOptions[0] && select.selectedOptions[0].hidden) select.value = '';
        }

        category.addEventListener('change', () => {
            filterOptions(recipe, 'category', category.value);
            filterOptions(variant, 'recipe', recipe.value);
        });
        recipe.addEventListener('change', () => filterOptions(variant, 'recipe', recipe.value));
        filterOptions(recipe, 'category', category.value);
        filterOptions(variant, 'recipe', recipe.value);
    </script>
</body>
</html>
