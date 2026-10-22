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
$size = trim($_GET['recvar-SizeOrPcs'] ?? '');
$foundation = trim($_GET['recvar-Foundation'] ?? '');
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

if ($variantId === '' && $recipeId !== '' && $size !== '') {
    foreach ($variants as $variant) {
        if ($variant['rec-Id'] === $recipeId && $variant['recvar-SizeOrPcs'] === $size && ($foundation === '' || $variant['recvar-Foundation'] === $foundation)) {
            $variantId = $variant['recvar-Id'];
            break;
        }
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
    <link rel="stylesheet" href="/cl-test/invtest/style.css">
    <style>
        body { margin: 40px 40px 40px 260px; font-family: Arial, sans-serif; }
        .panel { max-width: 1100px; }
        .selection { display: grid; grid-template-columns: repeat(2, minmax(220px, 1fr)); gap: 16px; margin: 20px 0; }
        .field label { display: block; margin-bottom: 5px; font-weight: bold; }
        .field select, .field input { width: 100%; box-sizing: border-box; padding: 9px; }
        .actions { display: flex; gap: 10px; margin-top: 18px; }
        .btn { padding: 9px 15px; border: 1px solid #555; border-radius: 4px; background: #fff; cursor: pointer; text-decoration: none; color: #222; }
        .btn-primary { background: #287a59; border-color: #23684c; color: #fff; }
        .notice { padding: 10px; margin: 16px 0; background: #fff3cd; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { padding: 10px 8px; border: 1px solid #ddd; text-align: left; }
        th { background: #f4f4f4; }
        @media (max-width: 700px) { body { margin: 20px; padding-top: 220px; } .selection { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <?php include 'nav.php'; ?>
    <main class="panel">
        <h1>Production Selection</h1>
        <?php if ($error !== ''): ?><div class="notice"><?php echo e($error); ?></div><?php endif; ?>

        <form method="get" action="prodform.php">
            <input type="hidden" name="action" value="load">
            <div class="selection">
                <div class="field">
                    <label for="category">Category</label>
                    <select id="category" name="reccat-Id" required>
                        <option value="">Select category</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?php echo e($category['reccat-Id']); ?>" <?php echo $categoryId === $category['reccat-Id'] ? 'selected' : ''; ?>><?php echo e($category['reccat-Name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="recipe">Product Name</label>
                    <select id="recipe" name="rec-Id" required>
                        <option value="">Select product</option>
                        <?php foreach ($recipes as $recipe): ?>
                            <option value="<?php echo e($recipe['rec-Id']); ?>" data-category="<?php echo e($recipe['reccat-Id']); ?>" <?php echo $recipeId === $recipe['rec-Id'] ? 'selected' : ''; ?>><?php echo e($recipe['rec-Name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="variant">Size/Pieces</label>
                    <select id="variant" name="recvar-Id" required>
                        <option value="">Select size/pieces</option>
                        <?php foreach ($variants as $variant): ?>
                            <option value="<?php echo e($variant['recvar-Id']); ?>" data-recipe="<?php echo e($variant['rec-Id']); ?>" data-size="<?php echo e($variant['recvar-SizeOrPcs']); ?>" data-foundation="<?php echo e($variant['recvar-Foundation']); ?>" <?php echo $variantId === $variant['recvar-Id'] ? 'selected' : ''; ?>><?php echo e($variant['recvar-SizeOrPcs']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="foundation">Foundation (optional)</label>
                    <select id="foundation" name="foundation">
                        <option value="">Select foundation</option>
                        <?php foreach ($variants as $variant): ?>
                            <option value="<?php echo e($variant['recvar-Foundation']); ?>" data-variant="<?php echo e($variant['recvar-Id']); ?>" <?php echo $foundation === $variant['recvar-Foundation'] && $variant['recvar-Id'] === $variantId ? 'selected' : ''; ?>><?php echo e($variant['recvar-Foundation']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="batch-quantity">Batch Quantity</label>
                    <input id="batch-quantity" name="batch-quantity" type="number" min="0.01" step="0.01" value="<?php echo $batchQuantity > 0 ? e($batchQuantity) : ''; ?>" required>
                </div>
            </div>
            <button class="btn btn-primary" type="submit">Load</button>
        </form>

        <?php if ($isLoaded && $error === ''): ?>
            <h2>Ingredients Needed</h2>
            <table>
                <thead><tr><th>Ingredients Needed</th><th>Batch Quantity</th><th>Current Stock</th></tr></thead>
                <tbody>
                    <?php foreach ($ingredients as $ingredient): ?>
                        <tr>
                            <td><?php echo e($ingredient['name']); ?></td>
                            <td><?php echo e(formatQuantity($ingredient['required'], $ingredient['requiredUnit'])); ?></td>
                            <td><?php echo e(formatQuantity($ingredient['stock'], $ingredient['stockUnit'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <form class="actions" method="post" action="prodqueue.php">
                <input type="hidden" name="action" value="confirm_production">
                <input type="hidden" name="reccat-Id" value="<?php echo e($categoryId); ?>">
                <input type="hidden" name="rec-Id" value="<?php echo e($recipeId); ?>">
                <input type="hidden" name="recvar-Id" value="<?php echo e($variantId); ?>">
                <input type="hidden" name="batch-quantity" value="<?php echo e($batchQuantity); ?>">
                <input type="hidden" name="prodbatch-Name" value="<?php echo e($categoryName . ' | ' . $recipeName . ' | ' . $variantName); ?>">
                <button class="btn btn-primary" type="submit">Confirm</button>
                <a class="btn" href="prodform.php">Clear</a>
            </form>
        <?php endif; ?>
    </main>
    <script>
        const category = document.getElementById('category');
        const recipe = document.getElementById('recipe');
        const variant = document.getElementById('variant');
        const foundation = document.getElementById('foundation');

        function filterOptions(select, attribute, value) {
            Array.from(select.options).forEach((option, index) => {
                option.hidden = index > 0 && option.dataset[attribute] !== value;
            });
            if (select.selectedOptions[0] && select.selectedOptions[0].hidden) select.value = '';
        }

        function selectVariantFromFoundation() {
            const selectedFoundation = foundation.value;
            const option = Array.from(variant.options).find((item) => item.dataset.foundation === selectedFoundation && !item.hidden);
            if (option) variant.value = option.value;
        }

        category.addEventListener('change', () => {
            filterOptions(recipe, 'category', category.value);
            filterOptions(variant, 'recipe', recipe.value);
        });
        recipe.addEventListener('change', () => filterOptions(variant, 'recipe', recipe.value));
        variant.addEventListener('change', () => filterOptions(foundation, 'variant', variant.value));
        foundation.addEventListener('change', selectVariantFromFoundation);
        filterOptions(recipe, 'category', category.value);
        filterOptions(variant, 'recipe', recipe.value);
        filterOptions(foundation, 'variant', variant.value);
    </script>
</body>
</html>