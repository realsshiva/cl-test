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
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
	<link rel="stylesheet" href="/cl-test/invtest/nav.css">
</head>
<body class="bg-body-tertiary">
<?php include 'nav.php'; ?>

<main class="app-main">
	<div class="container-fluid py-4 px-3 px-lg-4">
		<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-4">
			<h1 class="page-title mb-0">Production Queue</h1>
			<nav class="nav nav-pills gap-2" aria-label="Production pages">
				<a class="nav-link border" href="prodform.php"><i class="bi bi-clipboard-check me-1"></i>Production Form</a>
				<a class="nav-link active" href="prodqueue.php" aria-current="page"><i class="bi bi-list-task me-1"></i>Production Queue</a>
			</nav>
		</div>

		<?php if ($error !== ''): ?>
			<div class="alert alert-danger" role="alert"><i class="bi bi-exclamation-triangle me-2"></i><?php echo e($error); ?></div>
		<?php endif; ?>
		<?php if (isset($messages[$message])): ?>
			<div class="alert alert-success" role="status"><i class="bi bi-check-circle me-2"></i><?php echo e($messages[$message]); ?></div>
		<?php endif; ?>

		<div class="card border-0 shadow-sm mb-4">
			<div class="table-responsive">
				<table class="table table-hover align-middle mb-0">
					<thead class="table-light">
						<tr><th>Item Name</th><th>Batch Quantity</th><th>Ingredient Status</th><th class="text-end">Actions</th></tr>
					</thead>
					<tbody>
					<?php if (count($activeBatches) === 0): ?>
						<tr><td colspan="4" class="text-center text-body-secondary py-4">No production batches are waiting in the queue.</td></tr>
					<?php else: ?>
						<?php foreach ($activeBatches as $batch): ?>
							<tr<?php echo (string)$batch['prodbatch-Id'] === $selectedId ? ' class="table-active"' : ''; ?>>
								<td><a class="link-primary fw-semibold" href="prodqueue.php?selected=<?php echo rawurlencode((string)$batch['prodbatch-Id']); ?>"><?php echo e($batch['prodbatch-Name']); ?></a></td>
								<td><?php echo e($batch['prodbatch-Qty']); ?></td>
								<td>
									<?php if ($batch['sufficient']): ?>
										<span class="badge rounded-pill bg-success-subtle text-success-emphasis">Sufficient</span>
									<?php else: ?>
										<span class="badge rounded-pill bg-danger-subtle text-danger-emphasis">Insufficient</span>
									<?php endif; ?>
								</td>
								<td class="text-end">
									<div class="d-inline-flex flex-wrap justify-content-end gap-2">
										<?php if ($batch['prodbatch-Status'] === 'Pending'): ?>
											<button class="btn btn-outline-secondary btn-sm edit-button" type="button"
												data-bs-toggle="modal" data-bs-target="#edit-modal"
												data-id="<?php echo e($batch['prodbatch-Id']); ?>" data-qty="<?php echo e($batch['prodbatch-Qty']); ?>">
												<i class="bi bi-pencil me-1"></i>Edit
											</button>
											<form class="d-inline" method="get" action="prodlog.php">
												<input type="hidden" name="prodbatch-Id" value="<?php echo e($batch['prodbatch-Id']); ?>">
												<?php if ($batch['sufficient']): ?>
													<button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-check2-circle me-1"></i>Confirm</button>
												<?php else: ?>
													<span class="d-inline-block" tabindex="0" title="There is not enough available ingredient stock to produce this batch.">
														<button class="btn btn-primary btn-sm" type="submit" disabled><i class="bi bi-check2-circle me-1"></i>Confirm</button>
													</span>
												<?php endif; ?>
											</form>
											<button class="btn btn-outline-danger btn-sm cancel-button" type="button"
												data-bs-toggle="modal" data-bs-target="#cancel-modal"
												data-id="<?php echo e($batch['prodbatch-Id']); ?>">
												<i class="bi bi-x-lg me-1"></i>Cancel
											</button>
										<?php else: ?>
											<span class="text-body-secondary"><?php echo e($batch['prodbatch-Status']); ?></span>
										<?php endif; ?>
									</div>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>

		<?php if ($selectedBatch !== null): ?>
			<div class="card border-0 shadow-sm">
				<div class="card-header bg-body border-bottom d-flex flex-wrap align-items-center justify-content-between gap-2 py-3">
					<h2 class="h6 mb-0">Ingredients Needed: <?php echo e($selectedBatch['prodbatch-Name']); ?></h2>
					<button class="btn btn-outline-secondary btn-sm" id="toggle-ingredients" type="button" aria-controls="ingredients-table" aria-expanded="true">Hide</button>
				</div>
				<div class="table-responsive" id="ingredients-table">
					<table class="table table-hover align-middle mb-0">
						<thead class="table-light">
							<tr><th>Ingredient Name</th><th class="text-end">Quantity</th><th class="text-end">In-Stock</th><th>Ingredient Status</th></tr>
						</thead>
						<tbody>
						<?php if (count($selectedBatch['ingredients']) === 0): ?>
							<tr><td colspan="4" class="text-center text-body-secondary py-4">No ingredients are configured for this recipe variant.</td></tr>
						<?php else: ?>
							<?php foreach ($selectedBatch['ingredients'] as $ingredient): ?>
								<?php $statusClass = $ingredient['status'] === 'OK' ? 'bg-success-subtle text-success-emphasis' : ($ingredient['status'] === 'Low' ? 'bg-warning-subtle text-warning-emphasis' : 'text-bg-danger'); ?>
								<tr>
									<td><?php echo e($ingredient['name']); ?></td>
									<td class="text-end text-nowrap"><?php echo e(number_format((float)$ingredient['required'], 2, '.', '')); ?> <?php echo e($ingredient['requiredUnit']); ?></td>
									<td class="text-end text-nowrap"><?php echo e($ingredient['stockText']); ?></td>
									<td><span class="badge rounded-pill <?php echo e($statusClass); ?>"><?php echo e($ingredient['status']); ?></span></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
						</tbody>
					</table>
				</div>
			</div>
		<?php endif; ?>
	</div>
</main>

<div class="modal fade" id="edit-modal" tabindex="-1" aria-labelledby="edit-title" aria-hidden="true">
	<div class="modal-dialog modal-dialog-centered">
		<div class="modal-content">
			<form method="post" action="prodqueue.php">
				<div class="modal-header">
					<h2 class="modal-title fs-5" id="edit-title">Edit batch quantity</h2>
					<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
				</div>
				<div class="modal-body">
					<input type="hidden" name="action" value="update_batch">
					<input type="hidden" name="prodbatch-Id" id="edit-batch-id">
					<label for="edit-quantity" class="form-label">Batch Quantity</label>
					<input id="edit-quantity" class="form-control" name="prodbatch-Qty" type="number" min="1" step="1" required>
				</div>
				<div class="modal-footer">
					<button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">No</button>
					<button class="btn btn-primary" type="submit">Save</button>
				</div>
			</form>
		</div>
	</div>
</div>

<div class="modal fade" id="cancel-modal" tabindex="-1" aria-labelledby="cancel-title" aria-hidden="true">
	<div class="modal-dialog modal-dialog-centered">
		<div class="modal-content">
			<form method="post" action="prodqueue.php">
				<div class="modal-header">
					<h2 class="modal-title fs-5" id="cancel-title">Are you sure to cancel this batch?</h2>
					<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
				</div>
				<input type="hidden" name="action" value="cancel_batch">
				<input type="hidden" name="prodbatch-Id" id="cancel-batch-id">
				<div class="modal-footer">
					<button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">No</button>
					<button class="btn btn-danger" type="submit">Yes</button>
				</div>
			</form>
		</div>
	</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
<script>
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

	// Fill each Bootstrap modal with the clicked row's data before it opens.
	document.getElementById('edit-modal').addEventListener('show.bs.modal', (event) => {
		const button = event.relatedTarget;
		if (!button) return;
		document.getElementById('edit-batch-id').value = button.dataset.id;
		document.getElementById('edit-quantity').value = button.dataset.qty;
	});
	document.getElementById('cancel-modal').addEventListener('show.bs.modal', (event) => {
		const button = event.relatedTarget;
		if (!button) return;
		document.getElementById('cancel-batch-id').value = button.dataset.id;
	});
</script>
</body>
</html>
