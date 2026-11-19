<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/history_log.php';
require_once __DIR__ . '/cake_options.php';

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

function productionItemName(string $productName, string $variantType): string
{
	$parts = [trim($productName), trim($variantType)];
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
				'SELECT r.`rec-Name`, v.`recvar-Type` FROM `m222-recipe_variant3` v
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
					(string)$selection['recvar-Type']
				);
				if (strlen($batchName) > 60) {
					$error = 'The product name and variant type exceed the production batch name limit.';
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
		if ($changed > 0) {
			recordHistory($conn, null, null, $batchId, null, 'Cancelled Batch', 'Production Queue');
		}
		redirectQueue($changed ? 'cancelled' : 'unchanged');
	} elseif ($action === 'update_batch' && $batchId !== false && $batchId > 0) {
		$quantity = filter_var($_POST['prodbatch-Qty'] ?? '', FILTER_VALIDATE_INT);
		// Cakes from the Cake Builder keep their own name; their variants live in variant2/variant3.
		$customCheck = $conn->prepare('SELECT 1 FROM `m3.1-custom_cake_design` WHERE `prodbatch-Id` = ? LIMIT 1');
		$customCheck->bind_param('i', $batchId);
		$customCheck->execute();
		$isCustomCake = (bool)$customCheck->get_result()->fetch_row();
		$customCheck->close();

		if ($quantity === false || $quantity < 1) {
			$error = 'Batch quantity must be a whole number greater than zero.';
		} elseif ($isCustomCake) {
			$update = $conn->prepare('UPDATE `m3-production_batch` SET `prodbatch-Qty` = ? WHERE `prodbatch-Id` = ? AND `prodbatch-Status` = \'Pending\'');
			$update->bind_param('ii', $quantity, $batchId);
			$update->execute();
			$found = $conn->query('SELECT 1 FROM `m3-production_batch` WHERE `prodbatch-Id` = ' . (int)$batchId . ' AND `prodbatch-Status` = \'Pending\'')->num_rows > 0;
			$update->close();
			if ($found) {
				recordHistory($conn, null, null, $batchId, null, 'Edited Batch', 'Production Queue');
			}
			redirectQueue($found ? 'updated' : 'unchanged', (string)$batchId);
		} else {
			$lookup = $conn->prepare(
				'SELECT b.`recvar-Id`, r.`rec-Name`, v.`recvar-Type` FROM `m3-production_batch` b
				 JOIN `m222-recipe_variant3` v ON v.`recvar-Id` = b.`recvar-Id`
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
					(string)$batch['recvar-Type']
				);
				if (strlen($batchName) > 60) {
					$error = 'The product name and variant type exceed the production batch name limit.';
				} else {
					$update = $conn->prepare('UPDATE `m3-production_batch` SET `prodbatch-Name` = ?, `prodbatch-Qty` = ? WHERE `prodbatch-Id` = ? AND `prodbatch-Status` = \'Pending\'');
					$update->bind_param('sii', $batchName, $quantity, $batchId);
					$update->execute();
					$edited = $update->affected_rows > 0;
					$update->close();
					if (!$edited) {
						$check = $conn->prepare('SELECT 1 FROM `m3-production_batch` WHERE `prodbatch-Id` = ? AND `prodbatch-Status` = \'Pending\' LIMIT 1');
						$check->bind_param('i', $batchId);
						$check->execute();
						$edited = $check->get_result()->num_rows > 0;
						$check->close();
					}
					if ($edited) {
						recordHistory($conn, null, null, $batchId, null, 'Edited Batch', 'Production Queue');
					}
					redirectQueue('updated', (string)$batchId);
				}
			}
		}
	}
}

$activeBatches = [];
$result = $conn->query(
	'SELECT b.`prodbatch-Id`, b.`prodbatch-Name`, b.`recvar-Id`, b.`prodbatch-Qty`, b.`prodbatch-Status`,
	        d.`ccd-Id`, d.`ccd-BaseColor`, d.`ccd-AccentColor`, d.`ccd-Toppings`, d.`ccd-Message`,
	        d.`ccd-CustomerName`, d.`ccd-PickupDate`, d.`ccd-Notes`, d.`ccd-Design`
	 FROM `m3-production_batch` b
	 LEFT JOIN `m3.1-custom_cake_design` d ON d.`prodbatch-Id` = b.`prodbatch-Id`
	 WHERE b.`prodbatch-Status` NOT IN (\'Cancelled\', \'Finished\')
	 ORDER BY b.`prodbatch-Id` DESC'
);
while ($row = $result->fetch_assoc()) {
	$row['ingredients'] = ingredientRows($conn, (string)$row['recvar-Id'], (int)$row['prodbatch-Qty']);
	// Custom cakes from the Cake Builder don't need ingredients, so an empty list is fine for them.
	$row['noIngredientsNeeded'] = $row['ccd-Id'] !== null && count($row['ingredients']) === 0;
	$row['sufficient'] = count($row['ingredients']) > 0 || $row['noIngredientsNeeded'];
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

// A custom cake gets a picture of its design, drawn by cakedraw.js exactly as the Cake Builder showed it.
$cakeDesign = null;    // the saved design extras (second color, trim, piping, font, layout...)
$cakePreview = null;   // what cakedraw.js needs to draw it
if ($selectedBatch !== null && $selectedBatch['ccd-Id'] !== null) {
	$cakeDesign = cakeDecodeDesign($selectedBatch['ccd-Design']);
	$variantQuery = $conn->prepare(
		'SELECT r.`rec-Id`, r.`rec-Name`, v.`recvar-SizeOrPcs`, v.`recvar-Foundation`
		 FROM `m222-recipe_variant2` v JOIN `m22-recipe_master` r ON r.`rec-Id` = v.`rec-Id`
		 WHERE v.`recvar-Id` = ? LIMIT 1'
	);
	$variantId = (string)$selectedBatch['recvar-Id'];
	$variantQuery->bind_param('s', $variantId);
	$variantQuery->execute();
	$variant = $variantQuery->get_result()->fetch_assoc();
	$variantQuery->close();
	if ($variant) {
		$cakePreview = cakeShapeFor((string)$variant['rec-Id'], (string)$variant['rec-Name']) + [
			'size' => trim((string)$variant['recvar-SizeOrPcs']),
			'finish' => trim((string)$variant['recvar-Foundation']) ?: 'Plain',
			'base' => (string)$selectedBatch['ccd-BaseColor'],
			'accent' => (string)$selectedBatch['ccd-AccentColor'],
			'toppings' => array_keys(cakeToppingLabels((string)$selectedBatch['ccd-Toppings'])),
			'message' => (string)$selectedBatch['ccd-Message'],
			'design' => $cakeDesign,
			// The photo printed on top, if the customer added one and the file is still there.
			'photoUrl' => $cakeDesign['photo'] && cakePhotoFile((string)$cakeDesign['photo'][0]) ? CAKE_PHOTO_URL . $cakeDesign['photo'][0] . '.jpg' : null,
			'renders' => cakeRenders(),
		];
	}
}

$message = $_GET['message'] ?? '';
$messages = [
	'added' => 'Production batch added to the queue.',
	'custom_added' => 'Your custom cake was added to the production queue.',
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
	<style>
		.cake-preview { border-radius: 1rem; background: radial-gradient(120% 90% at 50% 0%, #fff1f4, #fdf6e9 55%, #eaf7f0); max-width: 340px; }
		[data-bs-theme="dark"] .cake-preview { background: radial-gradient(120% 90% at 50% 0%, #3a2730, #2f2a22 55%, #1f302a); }
		.cake-preview svg { display: block; width: 100%; height: auto; }
		.cake-dot { display: inline-block; width: 0.85rem; height: 0.85rem; border-radius: 50%; vertical-align: -0.1em; box-shadow: 0 0 0 1px rgba(0, 0, 0, 0.25); }
	</style>
</head>
<body class="bg-body-tertiary">
<?php if ($cakePreview !== null) readfile(__DIR__ . '/assets/cake-sprite.svg'); ?>
<?php include 'nav.php'; ?>

<main class="app-main">
	<div class="container-fluid py-4 px-3 px-lg-4">
		<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-4">
			<h1 class="page-title mb-0">Production Queue</h1>
			<nav class="nav nav-pills gap-2" aria-label="Production pages">
				<a class="nav-link border" href="prodform.php"><i class="bi bi-clipboard-check me-1"></i>Production Form</a>
				<a class="nav-link active" href="prodqueue.php" aria-current="page"><i class="bi bi-list-task me-1"></i>Production Queue</a>
				<a class="nav-link border" href="cakebuilder.php"><i class="bi bi-cake2 me-1"></i>Cake Builder</a>
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
								<td>
									<a class="link-primary fw-semibold" href="prodqueue.php?selected=<?php echo rawurlencode((string)$batch['prodbatch-Id']); ?>"><?php echo e($batch['prodbatch-Name']); ?></a>
									<?php if ($batch['ccd-Id'] !== null): ?>
										<div class="d-flex flex-wrap align-items-center gap-2 mt-1 small">
											<span class="badge rounded-pill bg-danger-subtle text-danger-emphasis"><i class="bi bi-cake2 me-1"></i>Custom</span>
											<?php $rowAllergens = array_intersect_key(cakeAllergens(), array_flip(cakeDecodeDesign($batch['ccd-Design'])['allergens'])); ?>
											<?php if ($rowAllergens): ?>
												<span class="badge rounded-pill text-bg-danger"><i class="bi bi-exclamation-triangle-fill me-1"></i>No <?php echo e(strtolower(implode(', ', $rowAllergens))); ?></span>
											<?php endif; ?>
											<span class="d-inline-flex gap-1" aria-hidden="true">
												<span class="cake-dot" style="background: <?php echo e($batch['ccd-BaseColor']); ?>"></span>
												<span class="cake-dot" style="background: <?php echo e($batch['ccd-AccentColor']); ?>"></span>
											</span>
											<?php if ($batch['ccd-PickupDate'] !== null): ?>
												<span class="text-body-secondary"><i class="bi bi-calendar-event me-1"></i>Pickup <?php echo e(date('M j, Y', strtotime($batch['ccd-PickupDate']))); ?></span>
											<?php endif; ?>
										</div>
									<?php endif; ?>
								</td>
								<td><?php echo e($batch['prodbatch-Qty']); ?></td>
								<td>
									<?php if ($batch['noIngredientsNeeded']): ?>
										<span class="badge rounded-pill bg-secondary-subtle text-secondary-emphasis">Not needed</span>
									<?php elseif ($batch['sufficient']): ?>
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

		<?php if ($selectedBatch !== null && $selectedBatch['ccd-Id'] !== null): ?>
			<?php $cakeToppings = cakeToppingLabels((string)$selectedBatch['ccd-Toppings']); ?>
			<div class="card border-0 shadow-sm mb-4">
				<div class="card-header bg-body border-bottom py-3">
					<h2 class="h6 mb-0"><i class="bi bi-cake2 text-danger-emphasis me-2"></i>Cake Design: <?php echo e($selectedBatch['prodbatch-Name']); ?></h2>
				</div>
				<div class="card-body">
					<div class="row g-4 align-items-start">
					<?php if ($cakePreview !== null): ?>
						<div class="col-md-5 col-lg-4">
							<div class="cake-preview">
								<svg id="cake-preview" viewBox="0 0 400 360" role="img" aria-label="Picture of the cake design"></svg>
							</div>
							<a class="btn btn-outline-secondary btn-sm mt-2" href="cake_order.php?id=<?php echo rawurlencode((string)$selectedBatch['prodbatch-Id']); ?>"
								title="Order file for the Blender Cake Kit, to render this exact design in 3D">
								<i class="bi bi-box me-1"></i>Blender order file
							</a>
						</div>
					<?php endif; ?>
					<div class="col">
					<dl class="row mb-0 small">
						<dt class="col-sm-3 text-body-secondary">Colors</dt>
						<dd class="col-sm-9">
							<span class="cake-dot" style="background: <?php echo e($selectedBatch['ccd-BaseColor']); ?>"></span>
							<?php echo e(cakeColorName((string)$selectedBatch['ccd-BaseColor'])); ?> (<?php echo e($selectedBatch['ccd-BaseColor']); ?>)
							<?php if ($cakeDesign['style'] !== 'solid'): ?>
								<span class="mx-2 text-body-tertiary">|</span>
								<span class="cake-dot" style="background: <?php echo e($cakeDesign['base2']); ?>"></span>
								<?php echo e(cakeColorName((string)$cakeDesign['base2'])); ?> (<?php echo e($cakeDesign['base2']); ?>),
								<?php echo $cakeDesign['style'] === 'ombre' ? 'ombre: fades in from the top' : 'upper tier'; ?>
							<?php endif; ?>
							<span class="mx-2 text-body-tertiary">|</span>
							<span class="cake-dot" style="background: <?php echo e($selectedBatch['ccd-AccentColor']); ?>"></span>
							<?php echo e(cakeColorName((string)$selectedBatch['ccd-AccentColor'], true)); ?> accents (<?php echo e($selectedBatch['ccd-AccentColor']); ?>)
						</dd>
						<?php
						// Extra colors and details, only the ones the customer changed.
						$hasDrip = in_array('chocolate_drip', array_keys($cakeToppings), true);
						$extraColors = [];
						$sidesText = cakeSidesText($cakeDesign['sides']);
						foreach (['trim' => 'Piping & trim', 'side' => 'Side design', 'drip' => 'Drip', 'board' => 'Stand', 'ink' => 'Message'] as $key => $label) {
							if ($cakeDesign[$key] === 'auto' || ($key === 'drip' && !$hasDrip) || ($key === 'ink' && $selectedBatch['ccd-Message'] === '') || ($key === 'side' && $sidesText === '')) continue;
							$extraColors[$label] = (string)$cakeDesign[$key];
						}
						$isButtercream = $cakePreview !== null && stripos($cakePreview['finish'], 'butter') !== false;
						$pieceCounts = array_count_values(array_column($cakeDesign['items'] ?? [], 0));
						?>
						<?php if ($extraColors): ?>
							<dt class="col-sm-3 text-body-secondary">Other colors</dt>
							<dd class="col-sm-9">
								<?php foreach ($extraColors as $label => $hex): ?>
									<span class="me-3 text-nowrap"><span class="cake-dot" style="background: <?php echo e($hex); ?>"></span>
									<?php echo e($label); ?>: <?php echo e(cakeColorName($hex)); ?> (<?php echo e($hex); ?>)</span>
								<?php endforeach; ?>
							</dd>
						<?php endif; ?>
						<?php $cakeAllergens = array_intersect_key(cakeAllergens(), array_flip($cakeDesign['allergens'])); ?>
						<?php if ($cakeAllergens): ?>
							<dt class="col-sm-3 text-danger-emphasis">Allergies</dt>
							<dd class="col-sm-9">
								<?php foreach ($cakeAllergens as $label): ?>
									<span class="badge rounded-pill text-bg-danger me-1"><i class="bi bi-exclamation-triangle-fill me-1"></i>No <?php echo e(strtolower($label)); ?></span>
								<?php endforeach; ?>
								<span class="text-body-secondary">The customer asked to avoid these.</span>
							</dd>
						<?php endif; ?>
						<?php if ($cakeDesign['finish2'] !== ''): ?>
							<dt class="col-sm-3 text-body-secondary">Upper tier finish</dt>
							<dd class="col-sm-9"><?php echo e($cakeDesign['finish2']); ?> <span class="text-body-secondary">(bottom tier: <?php echo e($cakePreview['finish'] ?? 'as queued'); ?>)</span></dd>
						<?php endif; ?>
						<?php if ($sidesText !== ''): ?>
							<dt class="col-sm-3 text-body-secondary">Side design</dt>
							<dd class="col-sm-9"><?php echo e($sidesText); ?></dd>
						<?php endif; ?>
						<?php $isButtercream = $isButtercream || stripos((string)$cakeDesign['finish2'], 'butter') !== false; ?>
						<?php if ($isButtercream && $cakeDesign['piping'] !== 'rosette'): ?>
							<dt class="col-sm-3 text-body-secondary">Top border</dt>
							<dd class="col-sm-9"><?php echo e(cakePipingStyles()[$cakeDesign['piping']] ?? 'Rosettes'); ?></dd>
						<?php endif; ?>
						<dt class="col-sm-3 text-body-secondary">Toppings</dt>
						<dd class="col-sm-9">
							<?php if (count($cakeToppings) === 0): ?>
								<span class="text-body-secondary">None</span>
							<?php else: ?>
								<?php foreach ($cakeToppings as $key => [$label, $icon]): ?>
									<span class="badge rounded-pill bg-body-secondary text-body-emphasis border me-1"><i class="bi <?php echo e($icon); ?> me-1"></i><?php echo e($label); ?><?php echo isset($pieceCounts[$key]) ? ' &times;' . (int)$pieceCounts[$key] : ''; ?></span>
								<?php endforeach; ?>
								<?php if ($pieceCounts): ?><div class="text-body-secondary mt-1">Placed as shown in the picture.</div><?php endif; ?>
							<?php endif; ?>
						</dd>
						<?php if ($cakeDesign['photo']): ?>
							<dt class="col-sm-3 text-body-secondary">Photo on top</dt>
							<dd class="col-sm-9">
								<?php if (!empty($cakePreview['photoUrl'])): ?>
									<a class="d-inline-flex align-items-center gap-2" href="<?php echo e($cakePreview['photoUrl']); ?>" target="_blank" rel="noopener">
										<img src="<?php echo e($cakePreview['photoUrl']); ?>" alt="" width="44" height="44" class="rounded border" style="object-fit: cover">
										Open the photo to print it (edible paper)
									</a>
								<?php else: ?>
									<span class="text-danger-emphasis">The photo file is missing. Ask the customer for it again.</span>
								<?php endif; ?>
							</dd>
						<?php endif; ?>
						<dt class="col-sm-3 text-body-secondary">Message</dt>
						<dd class="col-sm-9"><?php echo $selectedBatch['ccd-Message'] !== '' ? '&ldquo;' . e($selectedBatch['ccd-Message']) . '&rdquo; <span class="text-body-secondary">in ' . e(cakeFonts()[$cakeDesign['font']][0] ?? 'Classic Script') . ' lettering</span>' : '<span class="text-body-secondary">No message</span>'; ?></dd>
						<dt class="col-sm-3 text-body-secondary">Customer</dt>
						<dd class="col-sm-9"><?php echo $selectedBatch['ccd-CustomerName'] !== null ? e($selectedBatch['ccd-CustomerName']) : '<span class="text-body-secondary">Not given</span>'; ?></dd>
						<dt class="col-sm-3 text-body-secondary">Pickup date</dt>
						<dd class="col-sm-9"><?php echo $selectedBatch['ccd-PickupDate'] !== null ? e(date('l, M j, Y', strtotime($selectedBatch['ccd-PickupDate']))) : '<span class="text-body-secondary">Not set</span>'; ?></dd>
						<dt class="col-sm-3 text-body-secondary">Notes</dt>
						<dd class="col-sm-9 mb-0" style="white-space: pre-line"><?php echo $selectedBatch['ccd-Notes'] !== null ? e($selectedBatch['ccd-Notes']) : '<span class="text-body-secondary">None</span>'; ?></dd>
					</dl>
					</div>
					</div>
				</div>
			</div>
		<?php endif; ?>

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
							<tr><td colspan="4" class="text-center text-body-secondary py-4"><?php echo $selectedBatch['noIngredientsNeeded'] ? 'Custom cakes don&rsquo;t need ingredients set up.' : 'No ingredients are configured for this recipe variant.'; ?></td></tr>
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
<?php if ($cakePreview !== null): ?>
<link rel="stylesheet" href="<?php echo e(CAKE_FONTS_URL); ?>">
<script type="application/json" id="cake-preview-data"><?php echo json_encode($cakePreview, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?></script>
<script src="/cl-test/invtest/cakedraw.js?v=<?php echo filemtime(__DIR__ . '/cakedraw.js'); ?>"></script>
<script>
	// Draws the selected custom cake the way the Cake Builder showed it to the customer.
	(() => {
		const cake = JSON.parse(document.getElementById('cake-preview-data').textContent);
		const svg = document.getElementById('cake-preview');
		const design = cake.design;
		const hex = (value) => (/^#[0-9A-F]{6}$/.test(value || '') ? value : '');
		const base2 = design.style !== 'solid' ? hex(design.base2) : '';
		CakeDraw.init(cake.renders);
		CakeDraw.applyColors(svg, {
			base: cake.base, accent: cake.accent, base2,
			trim: hex(design.trim), side: hex(design.side), board: hex(design.board), drip: hex(design.drip), ink: hex(design.ink),
			inkOn: design.style === 'tiers' ? base2 : design.style === 'ombre' ? CakeDraw.mix(cake.base, base2, 0.5).toUpperCase() : '',
		});
		const draw = () => CakeDraw.drawCake(svg, {
			shape: cake.shape, tiers: cake.tiers, size: cake.size, finish: cake.finish,
			toppings: cake.toppings, message: cake.message,
			style: base2 ? design.style : 'solid', piping: design.piping, dripTint: !!hex(design.drip), font: design.font,
			finish2: design.finish2 || '',
			sides: design.sides || [],
			photo: cake.photoUrl && design.photo ? { src: cake.photoUrl, u: design.photo[1], v: design.photo[2], s: design.photo[3] } : null,
			msg: { dx: design.msg[0], dy: design.msg[1], size: design.msg[2] },
			// Cakes saved before toppings could be moved have no layout; they get the usual spots.
			items: design.items ? design.items.map(([k, u, v, s]) => ({ k, u, v, s })) : undefined,
		});
		draw();
		// The message is sized for its font, so draw again once the web fonts have arrived.
		if (document.fonts && document.fonts.ready) document.fonts.ready.then(draw);
	})();
</script>
<?php endif; ?>
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
