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
    <link rel="stylesheet" href="/cl-test/invtest/style.css">
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; }
        .top, .actions, .modal-footer, .choice-row { display: flex; align-items: center; gap: 12px; }
        .top { justify-content: space-between; flex-wrap: wrap; }
        .btn { padding: 8px 12px; border: 1px solid #444; border-radius: 4px; background: #fff; cursor: pointer; text-decoration: none; color: #222; }
        .btn-primary { background: #287a59; border-color: #23684c; color: #fff; }
        .summary { display: grid; grid-template-columns: 180px 1fr; gap: 18px; margin: 20px 0; padding: 16px; border: 1px solid #ddd; background: #f8f8f8; }
        .recipe-photo { width: 180px; height: 140px; object-fit: cover; background: #e9e9e9; grid-row: span 2; }
        .choice-row { flex-wrap: wrap; margin: 16px 0; }
        .choice-row select { width: min(100%, 320px); }
        table { width: 100%; border-collapse: collapse; margin-top: 18px; }
        th, td { padding: 10px 8px; border: 1px solid #ddd; text-align: left; }
        th { background: #f7f7f7; }
        .modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.4); align-items: center; justify-content: center; padding: 20px; }
        .panel { background: #fff; padding: 20px; border-radius: 8px; width: 100%; max-width: 760px; max-height: 90vh; overflow: auto; }
        .field { margin-bottom: 12px; }
        label { display: block; margin-bottom: 4px; font-size: 14px; }
        input, select { width: 100%; padding: 8px; box-sizing: border-box; }
        .modal-footer { justify-content: flex-end; margin-top: 16px; }
        .notice { padding: 10px; margin: 14px 0; background: #fff3cd; }
        .inline-form { display: inline; }
        .danger { color: #a00; border-color: #a00; }
    </style>
</head>
<body>
<?php include 'nav.php'; ?>
<div class="top">
    <h1><?php echo e($recipe['rec-Name'] ?? 'Recipe not found'); ?></h1>
    <a class="btn" href="/cl-test/invtest/ritem.php?reccat-Id=<?php echo rawurlencode($recipe['reccat-Id'] ?? ''); ?>">Back to recipes</a>
</div>

<?php if ($error !== ''): ?><div class="notice"><?php echo e($error); ?></div><?php endif; ?>
<?php if (!$recipe): ?>
    <p>Invalid recipe selected. Open this page from a recipe in ritem.php.</p>
<?php else: ?>
    <section class="summary">
        <?php if (!empty($recipe['rec-PhotoPath'])): ?><img class="recipe-photo" src="<?php echo e($recipe['rec-PhotoPath']); ?>" alt="imageofproduct"><?php else: ?><div class="recipe-photo" aria-label="No recipe photo"></div><?php endif; ?>
        <div><strong>Item name:</strong> <?php echo e($recipe['rec-Name']); ?></div>
        <div><strong>Recipe ID:</strong> <?php echo e($recipeId); ?></div>
    </section>

    <div class="actions"><button class="btn btn-primary" id="openVariantModal" type="button">Add Variant</button></div>
    <?php if (count($variants) > 0): ?>
        <form class="variant-selector" method="get" action="/cl-test/invtest/rmaster.php">
            <input type="hidden" name="rec-Id" value="<?php echo e($recipeId); ?>">
            <div class="choice-row">
                <label for="sizeSelect"><strong>Size / Pieces</strong></label>
                <select id="sizeSelect" name="recvar-SizeOrPcs">
                    <?php foreach ($sizeOptions as $size): ?><option value="<?php echo e($size); ?>" <?php echo $size === $activeSize ? 'selected' : ''; ?>><?php echo e($size); ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="choice-row">
                <label for="foundationSelect"><strong>Foundation</strong></label>
                <select id="foundationSelect" name="recvar-Id" <?php echo count($foundationOptions) === 0 ? 'disabled' : ''; ?>>
                    <?php if (count($foundationOptions) > 0): foreach ($foundationOptions as $foundation): ?><option value="<?php echo e($foundationVariantIds[$foundation]); ?>" <?php echo ($selectedVariant && $selectedVariant['recvar-Id'] === $foundationVariantIds[$foundation]) ? 'selected' : ''; ?>><?php echo e($foundation); ?></option><?php endforeach; else: ?><option value="">No foundation for this size</option><?php endif; ?>
                </select>
            </div>
        </form>
        <?php if ($selectedVariant): ?>
            <p><strong>Servings per batch:</strong> <?php echo (int)$selectedVariant['recvar-SvgPerBatch']; ?> &nbsp; <strong>Recipe Cost:</strong> <?php echo number_format((float)$selectedVariant['recvar-RecCost'], 2); ?></p>
            <table><thead><tr><th>Ingredient Name</th><th>Quantity Need</th><th>Unit</th><th>Cost Per Unit</th><th>Cost Per Recipe</th><th>Actions</th></tr></thead><tbody><?php foreach ($ingredients as $ingredient): $lineCost = $ingredient['cost_per_unit'] === null ? null : (float)$ingredient['cost_per_unit'] * (float)$ingredient['recing-QtyNeed']; ?><tr><td><?php echo e($ingredient['ingmaster-Name']); ?></td><td><?php echo number_format((float)$ingredient['recing-QtyNeed'], 2); ?></td><td><?php echo e($ingredient['recing-UnitType']); ?></td><td><?php echo $ingredient['cost_per_unit'] === null ? 'No compatible stock' : number_format((float)$ingredient['cost_per_unit'], 4); ?></td><td><?php echo $lineCost === null ? 'No compatible stock' : number_format($lineCost, 2); ?></td><td><button type="button" class="btn edit-ingredient" data-id="<?php echo (int)$ingredient['recing-Id']; ?>" data-name="<?php echo e($ingredient['ingmaster-Name']); ?>" data-quantity="<?php echo e($ingredient['recing-QtyNeed']); ?>" data-unit="<?php echo e($ingredient['recing-UnitType']); ?>">Edit</button> <form class="inline-form" method="post"><input type="hidden" name="action" value="delete_ingredient"><input type="hidden" name="rec-Id" value="<?php echo e($recipeId); ?>"><input type="hidden" name="recvar-Id" value="<?php echo e($selectedVariantId); ?>"><input type="hidden" name="recing-Id" value="<?php echo (int)$ingredient['recing-Id']; ?>"><button class="btn danger" type="submit">Delete</button></form></td></tr><?php endforeach; ?></tbody></table>
            <p><button class="btn" id="openIngredientModal" type="button">Add ingredient</button></p>
        <?php endif; ?>
    <?php else: ?><p>No variants have been added to this recipe yet.</p><?php endif; ?>
<?php endif; ?>

<div class="modal" id="variantModal"><div class="panel"><h2>Add Variant</h2><form method="post"><input type="hidden" name="action" value="add_variant"><input type="hidden" name="rec-Id" value="<?php echo e($recipeId); ?>"><div class="field"><label>Recipe Variant ID</label><input name="recvar-Id" maxlength="15" required></div><div class="field"><label>Recipe Servings Per Batch</label><input name="recvar-SvgPerBatch" type="number" min="1" required></div><div class="field"><label>Size or Pieces</label><input name="recvar-SizeOrPcs" required></div><div class="field"><label>Foundation (optional)</label><input name="recvar-Foundation"></div><table><thead><tr><th>Ingredient name</th><th>Quantity Need</th><th>Unit</th><th></th></tr></thead><tbody id="ingredientRows"><?php for ($i = 0; $i < 3; $i++): ?><tr><td><input name="ingredient-name[]" list="ingredientOptions"></td><td><input name="recing-QtyNeed[]" type="number" min="0" step="0.01"></td><td><select name="recing-UnitType[]"><option value="">Select unit</option><?php renderUnitOptions($unitGroups); ?></select></td><td><button class="remove-row" type="button">X</button></td></tr><?php endfor; ?></tbody></table><p><button class="btn" id="addIngredientRow" type="button">+ Add item</button></p><div class="modal-footer"><button class="btn cancel-modal" type="button">Cancel</button><button class="btn btn-primary" type="submit">Save</button></div></form></div></div>
<div class="modal" id="ingredientModal"><div class="panel"><h2 id="ingredientModalTitle">Add ingredient</h2><form method="post"><input type="hidden" name="action" value="save_ingredient"><input type="hidden" name="rec-Id" value="<?php echo e($recipeId); ?>"><input type="hidden" name="recvar-Id" value="<?php echo e($selectedVariantId); ?>"><input type="hidden" id="recingId" name="recing-Id" value="0"><div class="field"><label>Ingredient Name</label><input id="ingredientName" name="ingredient-name" list="ingredientOptions" required></div><div class="field"><label>Quantity Need</label><input id="ingredientQuantity" name="recing-QtyNeed" type="number" min="0" step="0.01" required></div><div class="field"><label>Unit</label><select id="ingredientUnit" name="recing-UnitType" required><option value="">Select unit</option><?php renderUnitOptions($unitGroups); ?></select></div><div class="modal-footer"><button class="btn cancel-modal" type="button">Cancel</button><button class="btn btn-primary" type="submit">Save</button></div></form></div></div>
<datalist id="ingredientOptions"><?php foreach ($options as $option): ?><option value="<?php echo e($option); ?>"></option><?php endforeach; ?></datalist>
<script>
    const variantModal = document.getElementById('variantModal');
    const ingredientModal = document.getElementById('ingredientModal');
    document.getElementById('openVariantModal')?.addEventListener('click', () => variantModal.style.display = 'flex');
    document.getElementById('openIngredientModal')?.addEventListener('click', () => { document.getElementById('recingId').value = '0'; document.getElementById('ingredientModalTitle').textContent = 'Add ingredient'; ingredientModal.style.display = 'flex'; });
    document.querySelectorAll('.edit-ingredient').forEach(button => button.addEventListener('click', () => { document.getElementById('recingId').value = button.dataset.id; document.getElementById('ingredientName').value = button.dataset.name; document.getElementById('ingredientQuantity').value = button.dataset.quantity; document.getElementById('ingredientUnit').value = button.dataset.unit; document.getElementById('ingredientModalTitle').textContent = 'Edit ingredient'; ingredientModal.style.display = 'flex'; }));
    document.querySelectorAll('.cancel-modal').forEach(button => button.addEventListener('click', () => button.closest('.modal').style.display = 'none'));
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
    document.getElementById('addIngredientRow')?.addEventListener('click', () => { const row = document.querySelector('#ingredientRows tr').cloneNode(true); row.querySelectorAll('input').forEach(input => input.value = ''); row.querySelectorAll('select').forEach(select => select.value = ''); document.getElementById('ingredientRows').appendChild(row); });
    document.getElementById('ingredientRows')?.addEventListener('click', event => { if (event.target.classList.contains('remove-row') && document.querySelectorAll('#ingredientRows tr').length > 1) event.target.closest('tr').remove(); });
    window.addEventListener('click', event => { if (event.target.classList.contains('modal')) event.target.style.display = 'none'; });
</script>
</body>
</html>
