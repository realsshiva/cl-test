<?php
require_once __DIR__ . '/db.php';

function e($value): string
{
	return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function unitFactor(string $unit): float
{
	$factors = [
		'mg' => 0.001, 'g' => 1.0, 'kg' => 1000.0, 'lb' => 453.592, 'oz' => 28.3495,
		'ml' => 1.0, 'l' => 1000.0, 'cup' => 240.0, 'tbsp' => 15.0, 'tsp' => 5.0,
		'fl oz' => 29.5735, 'pcs' => 1.0,
	];
	return $factors[strtolower(trim($unit))] ?? 1.0;
}

function unitFamily(string $unit): string
{
	$unit = strtolower(trim($unit));
	if (in_array($unit, ['mg', 'g', 'kg', 'lb', 'oz'], true)) return 'weight';
	if (in_array($unit, ['ml', 'l', 'cup', 'tbsp', 'tsp', 'fl oz'], true)) return 'volume';
	return $unit === 'pcs' ? 'count' : 'other:' . $unit;
}

function formatStock(float $baseQuantity, string $unit): string
{
	$unit = trim($unit);
	if (in_array($unit, ['g', 'kg'], true)) {
		return $baseQuantity >= 1000.0
			? number_format($baseQuantity / 1000.0, 2, '.', '') . ' kg'
			: number_format($baseQuantity, 2, '.', '') . ' g';
	}
	if (in_array($unit, ['mL', 'ml', 'L'], true)) {
		return $baseQuantity >= 1000.0
			? number_format($baseQuantity / 1000.0, 2, '.', '') . ' L'
			: number_format($baseQuantity, 2, '.', '') . ' mL';
	}
	if ($unit === 'pcs') {
		$count = round($baseQuantity);
		return $count === 1.0 ? '1 pc' : number_format($count, 0) . ' pcs';
	}
	$value = rtrim(rtrim(number_format($baseQuantity / unitFactor($unit), 2, '.', ''), '0'), '.');
	return $value . ' ' . $unit;
}

function productionItemName(string $productName, string $sizeOrPieces, string $foundation): string
{
	$parts = [trim($productName), trim($sizeOrPieces)];
	$foundation = trim($foundation);
	if ($foundation !== '') $parts[] = $foundation;
	return implode(' | ', $parts);
}

function ingredientRows(mysqli $conn, string $variantId, int $batchQuantity): array
{
	$ingredients = [];
	$query = $conn->prepare(
		'SELECT ri.`recing-Id`, ri.`recing-QtyNeed`, ri.`recing-UnitType`, im.`ingmaster-Id`, im.`ingmaster-Name`, im.`ingmaster-UnitType`, im.`ingmaster-Threshold`
		 FROM `m2222-recipe_ingredient` ri
		 JOIN `m11-ingredient_master` im ON im.`ingmaster-Id` = ri.`ingmaster-Id`
		 WHERE ri.`recvar-Id` = ? ORDER BY ri.`recing-Id`'
	);
	$query->bind_param('s', $variantId);
	$query->execute();
	$result = $query->get_result();

	while ($row = $result->fetch_assoc()) {
		$stockQuery = $conn->prepare(
			'SELECT `ing-StockQty`, `ing-UnitType` FROM `m111-ingredient_item`
			 WHERE `ingmaster-Id` = ? AND `ing-StockQty` > 0 AND `ing-ExpiryDate` > CURDATE()'
		);
		$stockQuery->bind_param('s', $row['ingmaster-Id']);
		$stockQuery->execute();
		$stockQuery->bind_result($stockQuantity, $stockUnit);
		$stockBase = 0.0;
		while ($stockQuery->fetch()) $stockBase += (float)$stockQuantity * unitFactor((string)$stockUnit);
		$stockQuery->close();

		$requiredQuantity = (float)$row['recing-QtyNeed'] * $batchQuantity;
		$requiredBase = $requiredQuantity * unitFactor((string)$row['recing-UnitType']);
		$requiredFamily = unitFamily((string)$row['recing-UnitType']);
		$stockFamily = unitFamily((string)$row['ingmaster-UnitType']);
		$thresholdBase = (float)$row['ingmaster-Threshold'] * unitFactor((string)$row['ingmaster-UnitType']);
		$status = $stockBase <= 0.0 ? 'Out of Stock' : ($stockBase <= $thresholdBase ? 'Low' : 'OK');
		$ingredients[] = [
			'id' => $row['recing-Id'],
			'name' => $row['ingmaster-Name'],
			'required' => $requiredQuantity,
			'requiredUnit' => $row['recing-UnitType'],
			'stockText' => formatStock($stockBase, (string)$row['ingmaster-UnitType']),
			'status' => $status,
			'sufficient' => $requiredFamily === $stockFamily && $stockBase + 0.000001 >= $requiredBase,
		];
	}
	$query->close();
	return $ingredients;
}

function redirectQueue(string $message, string $selectedId = ''): void
{
	$url = 'prodqueue.php?message=' . rawurlencode($message);
	if ($selectedId !== '') $url .= '&selected=' . rawurlencode($selectedId);
	header('Location: ' . $url);
	exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$action = $_POST['action'] ?? '';
	$batchId = filter_var($_POST['prodbatch-Id'] ?? '', FILTER_VALIDATE_INT);

	if ($action === 'confirm_production') {
		$categoryId = trim($_POST['reccat-Id'] ?? '');
		$recipeId = trim($_POST['rec-Id'] ?? '');
		$variantId = trim($_POST['recvar-Id'] ?? '');
		$quantity = filter_var($_POST['batch-quantity'] ?? '', FILTER_VALIDATE_INT);
		if ($categoryId === '' || $recipeId === '' || $variantId === '' || $quantity === false || $quantity < 1) {
			$error = 'The production selection is incomplete or its batch quantity is invalid.';
		} else {
			$validate = $conn->prepare(
				'SELECT r.`rec-Name`, v.`recvar-SizeOrPcs`, v.`recvar-Foundation` FROM `m222-recipe_variant` v
				 JOIN `m22-recipe_master` r ON r.`rec-Id` = v.`rec-Id`
				 JOIN `m2-recipe_category` c ON c.`reccat-Id` = r.`reccat-Id`
				 WHERE v.`recvar-Id` = ? AND r.`rec-Id` = ? AND c.`reccat-Id` = ? LIMIT 1'
			);
			$validate->bind_param('sss', $variantId, $recipeId, $categoryId);
			$validate->execute();
			$selection = $validate->get_result()->fetch_assoc();
			$validate->close();
			if (!$selection) {
				$error = 'The selected category, recipe, and variant do not match.';
			} else {
				$batchName = productionItemName(
					(string)$selection['rec-Name'],
					(string)$selection['recvar-SizeOrPcs'],
					(string)$selection['recvar-Foundation']
				);
				if (strlen($batchName) > 60) {
					$error = 'The product name, size/pieces, and foundation exceed the production batch name limit.';
				} else {
					$status = 'Pending';
					$insert = $conn->prepare('INSERT INTO `m3-production_batch` (`recvar-Id`, `prodbatch-Name`, `prodbatch-Qty`, `prodbatch-Status`) VALUES (?, ?, ?, ?)');
					$insert->bind_param('ssis', $variantId, $batchName, $quantity, $status);
					$insert->execute();
					$newBatchId = (string)$conn->insert_id;
					$insert->close();
					redirectQueue('added', $newBatchId);
				}
			}
		}
	} elseif ($action === 'cancel_batch' && $batchId !== false && $batchId > 0) {
		$status = 'Cancelled';
		$update = $conn->prepare('UPDATE `m3-production_batch` SET `prodbatch-Status` = ? WHERE `prodbatch-Id` = ? AND `prodbatch-Status` = \'Pending\'');
		$update->bind_param('si', $status, $batchId);
		$update->execute();
		$changed = $update->affected_rows;
		$update->close();
		redirectQueue($changed ? 'cancelled' : 'unchanged');
	} elseif ($action === 'update_batch' && $batchId !== false && $batchId > 0) {
		$quantity = filter_var($_POST['prodbatch-Qty'] ?? '', FILTER_VALIDATE_INT);
		if ($quantity === false || $quantity < 1) {
			$error = 'Batch quantity must be a whole number greater than zero.';
		} else {
			$lookup = $conn->prepare(
				'SELECT b.`recvar-Id`, r.`rec-Name`, v.`recvar-SizeOrPcs`, v.`recvar-Foundation` FROM `m3-production_batch` b
				 JOIN `m222-recipe_variant` v ON v.`recvar-Id` = b.`recvar-Id`
				 JOIN `m22-recipe_master` r ON r.`rec-Id` = v.`rec-Id`
				 WHERE b.`prodbatch-Id` = ? AND b.`prodbatch-Status` = \'Pending\' LIMIT 1'
			);
			$lookup->bind_param('i', $batchId);
			$lookup->execute();
			$batch = $lookup->get_result()->fetch_assoc();
			$lookup->close();
			if (!$batch) {
				$error = 'That pending batch could not be found.';
			} else {
				$batchName = productionItemName(
					(string)$batch['rec-Name'],
					(string)$batch['recvar-SizeOrPcs'],
					(string)$batch['recvar-Foundation']
				);
				if (strlen($batchName) > 60) {
					$error = 'The product name, size/pieces, and foundation exceed the production batch name limit.';
				} else {
					$update = $conn->prepare('UPDATE `m3-production_batch` SET `prodbatch-Name` = ?, `prodbatch-Qty` = ? WHERE `prodbatch-Id` = ? AND `prodbatch-Status` = \'Pending\'');
					$update->bind_param('sii', $batchName, $quantity, $batchId);
					$update->execute();
					$update->close();
					redirectQueue('updated', (string)$batchId);
				}
			}
		}
	}
}

$activeBatches = [];
$result = $conn->query(
	'SELECT `prodbatch-Id`, `prodbatch-Name`, `recvar-Id`, `prodbatch-Qty`, `prodbatch-Status`
	 FROM `m3-production_batch` WHERE `prodbatch-Status` NOT IN (\'Cancelled\', \'Finished\')
	 ORDER BY `prodbatch-Id` DESC'
);
while ($row = $result->fetch_assoc()) {
	$row['ingredients'] = ingredientRows($conn, (string)$row['recvar-Id'], (int)$row['prodbatch-Qty']);
	$row['sufficient'] = count($row['ingredients']) > 0;
	foreach ($row['ingredients'] as $ingredient) {
		if (!$ingredient['sufficient']) {
			$row['sufficient'] = false;
			break;
		}
	}
	$activeBatches[] = $row;
}

$selectedId = trim($_GET['selected'] ?? '');
$selectedBatch = null;
foreach ($activeBatches as $batch) {
	if ((string)$batch['prodbatch-Id'] === $selectedId) {
		$selectedBatch = $batch;
		break;
	}
}
$message = $_GET['message'] ?? '';
$messages = [
	'added' => 'Production batch added to the queue.',
	'updated' => 'Batch quantity updated.',
	'cancelled' => 'Production batch cancelled.',
	'unchanged' => 'That batch is no longer pending.',
	'logged' => 'Production log saved and batch marked finished.',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Production Queue</title>
	<link rel="stylesheet" href="/cl-test/invtest/style.css">
	<style>
		body { margin: 40px 40px 40px 260px; font-family: Arial, sans-serif; color: #252a27; }
		main { max-width: 1200px; }
		h1 { margin-top: 0; }
		.notice { margin: 14px 0; padding: 11px 14px; background: #e7f3ed; border-left: 4px solid #287a59; }
		.error { background: #fff0ed; border-left-color: #bd4937; }
		.table-wrap { overflow-x: auto; }
		table { width: 100%; border-collapse: collapse; margin: 16px 0 28px; }
		th, td { padding: 10px 11px; border: 1px solid #d6dcd8; text-align: left; vertical-align: middle; }
		th { background: #f1f4f2; }
		.status { display: inline-block; padding: 5px 9px; border-radius: 4px; font-size: 13px; font-weight: 700; white-space: nowrap; }
		.sufficient, .ok { color: #145c37; background: #d9f0e1; }
		.insufficient, .low { color: #872d25; background: #fde0dc; }
		.out { color: #fff; background: #a92e25; }
		.actions { display: flex; flex-wrap: wrap; gap: 6px; }
		.actions form { margin: 0; }
		.btn { display: inline-block; padding: 7px 10px; border: 1px solid #69736d; border-radius: 4px; background: #fff; color: #26312a; cursor: pointer; text-decoration: none; font: inherit; font-size: 14px; }
		.btn-primary { color: #fff; background: #287a59; border-color: #23684c; }
		.btn-danger { color: #9b3027; border-color: #b24a40; }
		.btn:disabled { opacity: .5; cursor: not-allowed; }
		.production-navigation { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 12px; }
		.production-navigation .btn[aria-current="page"] { color: #fff; background: #287a59; border-color: #23684c; }
		.item-link { color: #205d46; font-weight: 600; text-decoration: underline; text-underline-offset: 2px; }
		.muted { color: #66716a; }
		.detail-heading { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
		.detail-heading h2 { margin: 0; }
		.modal { display: none; position: fixed; z-index: 20; inset: 0; align-items: center; justify-content: center; padding: 20px; background: rgb(0 0 0 / 45%); }
		.modal.open { display: flex; }
		.modal-panel { width: min(100%, 440px); padding: 22px; background: #fff; border-radius: 6px; box-shadow: 0 12px 36px rgb(0 0 0 / 20%); }
		.modal-panel h2 { margin: 0 0 12px; font-size: 20px; }
		.modal-panel label { display: block; margin-bottom: 6px; font-weight: 600; }
		.modal-panel input { box-sizing: border-box; width: 100%; padding: 9px; }
		.modal-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 18px; }
		@media (max-width: 700px) { body { margin: 20px; padding-top: 220px; } th, td { padding: 8px; } }
	</style>
</head>
<body>
<?php include 'nav.php'; ?>
<main>
	<h1>Production Queue</h1>
	<nav class="production-navigation" aria-label="Production pages">
		<a class="btn" href="prodform.php">Production Form</a>
		<a class="btn" href="prodqueue.php" aria-current="page">Production Queue</a>
	</nav>
	<?php if ($error !== ''): ?><div class="notice error"><?php echo e($error); ?></div><?php endif; ?>
	<?php if (isset($messages[$message])): ?><div class="notice"><?php echo e($messages[$message]); ?></div><?php endif; ?>
	<div class="table-wrap">
		<table>
			<thead><tr><th>Item Name</th><th>Batch Quantity</th><th>Ingredient Status</th><th>Actions</th></tr></thead>
			<tbody>
			<?php if (count($activeBatches) === 0): ?>
				<tr><td colspan="4" class="muted">No production batches are waiting in the queue.</td></tr>
			<?php else: ?>
				<?php foreach ($activeBatches as $batch): ?>
					<tr>
						<td><a class="item-link" href="prodqueue.php?selected=<?php echo rawurlencode((string)$batch['prodbatch-Id']); ?>"><?php echo e($batch['prodbatch-Name']); ?></a></td>
						<td><?php echo e($batch['prodbatch-Qty']); ?></td>
						<td><span class="status <?php echo $batch['sufficient'] ? 'sufficient' : 'insufficient'; ?>"><?php echo $batch['sufficient'] ? 'Sufficient' : 'Insufficient'; ?></span></td>
						<td>
							<div class="actions">
								<?php if ($batch['prodbatch-Status'] === 'Pending'): ?>
									<button class="btn edit-button" type="button" data-id="<?php echo e($batch['prodbatch-Id']); ?>" data-qty="<?php echo e($batch['prodbatch-Qty']); ?>">Edit</button>
									<form method="get" action="prodlog.php">
										<input type="hidden" name="prodbatch-Id" value="<?php echo e($batch['prodbatch-Id']); ?>">
										<button class="btn btn-primary" type="submit" <?php echo $batch['sufficient'] ? '' : 'disabled title="There is not enough available ingredient stock to produce this batch."'; ?>>Confirm</button>
									</form>
									<button class="btn btn-danger cancel-button" type="button" data-id="<?php echo e($batch['prodbatch-Id']); ?>">Cancel</button>
								<?php else: ?>
									<span class="muted"><?php echo e($batch['prodbatch-Status']); ?></span>
								<?php endif; ?>
							</div>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>
	</div>
	<?php if ($selectedBatch !== null): ?>
		<div class="detail-heading">
			<h2>Ingredients Needed: <?php echo e($selectedBatch['prodbatch-Name']); ?></h2>
			<button class="btn" id="toggle-ingredients" type="button" aria-controls="ingredients-table" aria-expanded="true">Hide</button>
		</div>
		<div class="table-wrap" id="ingredients-table">
			<table>
				<thead><tr><th>Ingredient Name</th><th>Quantity</th><th>In-Stock</th><th>Ingredient Status</th></tr></thead>
				<tbody>
				<?php if (count($selectedBatch['ingredients']) === 0): ?>
					<tr><td colspan="4" class="muted">No ingredients are configured for this recipe variant.</td></tr>
				<?php else: ?>
					<?php foreach ($selectedBatch['ingredients'] as $ingredient): ?>
						<?php $statusClass = $ingredient['status'] === 'OK' ? 'ok' : ($ingredient['status'] === 'Low' ? 'low' : 'out'); ?>
						<tr>
							<td><?php echo e($ingredient['name']); ?></td>
							<td><?php echo e(number_format((float)$ingredient['required'], 2, '.', '')); ?> <?php echo e($ingredient['requiredUnit']); ?></td>
							<td><?php echo e($ingredient['stockText']); ?></td>
							<td><span class="status <?php echo e($statusClass); ?>"><?php echo e($ingredient['status']); ?></span></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>
</main>

<div class="modal" id="edit-modal" role="dialog" aria-modal="true" aria-labelledby="edit-title">
	<div class="modal-panel">
		<h2 id="edit-title">Edit batch quantity</h2>
		<form method="post" action="prodqueue.php">
			<input type="hidden" name="action" value="update_batch">
			<input type="hidden" name="prodbatch-Id" id="edit-batch-id">
			<label for="edit-quantity">Batch Quantity</label>
			<input id="edit-quantity" name="prodbatch-Qty" type="number" min="1" step="1" required>
			<div class="modal-actions"><button class="btn close-modal" type="button">No</button><button class="btn btn-primary" type="submit">Save</button></div>
		</form>
	</div>
</div>
<div class="modal" id="cancel-modal" role="dialog" aria-modal="true" aria-labelledby="cancel-title">
	<div class="modal-panel">
		<h2 id="cancel-title">Are you sure to cancel this batch?</h2>
		<form method="post" action="prodqueue.php">
			<input type="hidden" name="action" value="cancel_batch">
			<input type="hidden" name="prodbatch-Id" id="cancel-batch-id">
			<div class="modal-actions"><button class="btn close-modal" type="button">No</button><button class="btn btn-danger" type="submit">Yes</button></div>
		</form>
	</div>
</div>
<script>
	const editModal = document.getElementById('edit-modal');
	const cancelModal = document.getElementById('cancel-modal');
	const ingredientsToggle = document.getElementById('toggle-ingredients');
	if (ingredientsToggle) {
		const ingredientsTable = document.getElementById('ingredients-table');
		ingredientsToggle.addEventListener('click', () => {
			const isExpanded = ingredientsToggle.getAttribute('aria-expanded') === 'true';
			ingredientsTable.hidden = isExpanded;
			ingredientsToggle.setAttribute('aria-expanded', String(!isExpanded));
			ingredientsToggle.textContent = isExpanded ? 'Show ingredients' : 'Hide';
		});
	}
	document.querySelectorAll('.edit-button').forEach((button) => {
		button.addEventListener('click', () => {
			document.getElementById('edit-batch-id').value = button.dataset.id;
			document.getElementById('edit-quantity').value = button.dataset.qty;
			editModal.classList.add('open');
		});
	});
	document.querySelectorAll('.cancel-button').forEach((button) => {
		button.addEventListener('click', () => {
			document.getElementById('cancel-batch-id').value = button.dataset.id;
			cancelModal.classList.add('open');
		});
	});
	document.querySelectorAll('.close-modal').forEach((button) => {
		button.addEventListener('click', () => button.closest('.modal').classList.remove('open'));
	});
	document.querySelectorAll('.modal').forEach((modal) => {
		modal.addEventListener('click', (event) => {
			if (event.target === modal) modal.classList.remove('open');
		});
	});
</script>
</body>
</html>
