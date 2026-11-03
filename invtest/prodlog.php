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

$error = '';
$batchId = filter_var($_POST['prodbatch-Id'] ?? $_GET['prodbatch-Id'] ?? '', FILTER_VALIDATE_INT);
$successQuantity = trim((string)($_POST['prodlog-QtySuccess'] ?? ''));
$errorQuantity = trim((string)($_POST['prodlog-QtyError'] ?? '0'));
$expiryDate = trim((string)($_POST['prodlog-ExpiryDate'] ?? ''));
$reason = trim((string)($_POST['prodlog-Reason'] ?? ''));

if ($batchId === false || $batchId < 1) {
	$error = 'Select a valid production batch from the production queue.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$successValue = filter_var($successQuantity, FILTER_VALIDATE_INT);
	$errorValue = filter_var($errorQuantity, FILTER_VALIDATE_INT);
	$dateHasExpectedFormat = preg_match('/^\d{4}-\d{2}-\d{2}$/D', $expiryDate) === 1;
	$date = $dateHasExpectedFormat ? DateTimeImmutable::createFromFormat('!Y-m-d', $expiryDate) : false;
	$dateErrors = $date !== false ? DateTimeImmutable::getLastErrors() : false;
	$dateIsValid = $dateHasExpectedFormat
		&& $date !== false
		&& $date->format('Y-m-d') === $expiryDate
		&& ($dateErrors === false || ($dateErrors['warning_count'] === 0 && $dateErrors['error_count'] === 0));

	if ($successValue === false || $successValue < 0 || $errorValue === false || $errorValue < 0) {
		$error = 'Success and Error must be whole numbers greater than or equal to zero.';
	} elseif ($successValue + $errorValue === 0) {
		$error = 'Enter at least one successful or errored item.';
	} elseif (!$dateIsValid) {
		$error = 'Enter a valid Best Before date.';
	} elseif ($errorValue > 0 && $reason === '') {
		$error = 'Enter a reason when the batch contains errored items.';
	} else {
		$transactionStarted = false;
		try {
			$conn->begin_transaction();
			$transactionStarted = true;

			$lookup = $conn->prepare(
				'SELECT `prodbatch-Qty`, `recvar-Id` FROM `m3-production_batch`
				 WHERE `prodbatch-Id` = ? AND `prodbatch-Status` = \'Pending\' FOR UPDATE'
			);
			$lookup->bind_param('i', $batchId);
			$lookup->execute();
			$batchResult = $lookup->get_result();
			$batch = $batchResult->fetch_assoc();
			$lookup->close();

			if (!$batch) {
				$conn->rollback();
				$transactionStarted = false;
				$error = 'This batch is no longer pending and cannot be logged.';
			} else {
				$recipeQuery = $conn->prepare(
					'SELECT ri.`recing-QtyNeed`, ri.`recing-UnitType`, ri.`ingmaster-Id`, im.`ingmaster-UnitType`
					 FROM `m2222-recipe_ingredient` ri
					 JOIN `m11-ingredient_master` im ON im.`ingmaster-Id` = ri.`ingmaster-Id`
					 WHERE ri.`recvar-Id` = ? ORDER BY ri.`recing-Id`'
				);
				$variantId = (string)$batch['recvar-Id'];
				$recipeQuery->bind_param('s', $variantId);
				$recipeQuery->execute();
				$recipeResult = $recipeQuery->get_result();
				$recipeRows = [];
				while ($recipeRow = $recipeResult->fetch_assoc()) {
					$recipeRows[] = $recipeRow;
				}
				$recipeQuery->close();

				$inventoryError = '';
				if (count($recipeRows) === 0) {
					$inventoryError = 'This recipe has no ingredients configured, so its stock cannot be deducted.';
				}

				foreach ($recipeRows as $recipeRow) {
					if ($inventoryError !== '') break;

					$requiredUnit = (string)$recipeRow['recing-UnitType'];
					$requiredFamily = unitFamily($requiredUnit);
					if ($requiredFamily !== unitFamily((string)$recipeRow['ingmaster-UnitType'])) {
						$inventoryError = 'Ingredient units do not match the recipe. Check the recipe ingredient units before logging this batch.';
						break;
					}

					$requiredBase = (float)$recipeRow['recing-QtyNeed']
						* (int)$batch['prodbatch-Qty']
						* unitFactor($requiredUnit);
					$stockQuery = $conn->prepare(
						'SELECT `ing-Id`, `ing-StockQty`, `ing-UnitType` FROM `m111-ingredient_item`
						 WHERE `ingmaster-Id` = ? AND `ing-StockQty` > 0 AND `ing-ExpiryDate` > CURDATE()
						 ORDER BY `ing-ExpiryDate` ASC, `ing-Id` ASC FOR UPDATE'
					);
					$ingredientId = (string)$recipeRow['ingmaster-Id'];
					$stockQuery->bind_param('s', $ingredientId);
					$stockQuery->execute();
					$stockResult = $stockQuery->get_result();
					$stockRows = [];
					$availableBase = 0.0;
					while ($stockRow = $stockResult->fetch_assoc()) {
						if (unitFamily((string)$stockRow['ing-UnitType']) !== $requiredFamily) continue;
						$stockRows[] = $stockRow;
						$availableBase += (float)$stockRow['ing-StockQty'] * unitFactor((string)$stockRow['ing-UnitType']);
					}
					$stockQuery->close();

					if ($availableBase + 0.000001 < $requiredBase) {
						$inventoryError = 'There is not enough unexpired stock for ingredient '
							. (string)$recipeRow['ingmaster-Id'] . '. The batch was not logged.';
						break;
					}

					$remainingBase = $requiredBase;
					foreach ($stockRows as $stockRow) {
						if ($remainingBase <= 0.000001) break;
						$stockUnit = (string)$stockRow['ing-UnitType'];
						$stockFactor = unitFactor($stockUnit);
						$currentStock = (float)$stockRow['ing-StockQty'];
						$deductBase = min($remainingBase, $currentStock * $stockFactor);
						$updatedStock = max(0.0, $currentStock - $deductBase / $stockFactor);
						$stockId = (int)$stockRow['ing-Id'];
						$stockUpdate = $conn->prepare(
							'UPDATE `m111-ingredient_item` SET `ing-StockQty` = ? WHERE `ing-Id` = ?'
						);
						$stockUpdate->bind_param('di', $updatedStock, $stockId);
						$stockUpdate->execute();
						$stockUpdate->close();
						$remainingBase -= $deductBase;
					}
				}

				if ($inventoryError !== '') {
					$conn->rollback();
					$transactionStarted = false;
					$error = $inventoryError;
				} else {
					$insert = $conn->prepare(
						'INSERT INTO `m33-production_log`
						 (`prodbatch-Id`, `prodlog-QtySuccess`, `prodlog-QtyError`, `prodlog-ExpiryDate`, `prodlog-Reason`, `prodlog-Timestamp`)
						 VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)'
					);
					$insert->bind_param('iiiss', $batchId, $successValue, $errorValue, $expiryDate, $reason);
					$insert->execute();
					$insert->close();

					$finishedStatus = 'Finished';
					$update = $conn->prepare(
						'UPDATE `m3-production_batch` SET `prodbatch-Status` = ?
						 WHERE `prodbatch-Id` = ? AND `prodbatch-Status` = \'Pending\''
					);
					$update->bind_param('si', $finishedStatus, $batchId);
					$update->execute();
					$updatedRows = $update->affected_rows;
					$update->close();

					if ($updatedRows !== 1) {
						$conn->rollback();
						$transactionStarted = false;
						$error = 'The batch status changed before its production log could be saved. Please try again.';
					} else {
						$conn->commit();
						$transactionStarted = false;
						header('Location: prodqueue.php?message=logged');
						exit;
					}
				}
			}
		} catch (mysqli_sql_exception $exception) {
			if ($transactionStarted) {
				$conn->rollback();
			}
			$error = 'The production log could not be saved: ' . $exception->getMessage();
		}
	}
}

$batch = null;
if ($batchId !== false && $batchId > 0) {
	$lookup = $conn->prepare(
		'SELECT `prodbatch-Id`, `prodbatch-Name`, `prodbatch-Qty`
		 FROM `m3-production_batch`
		 WHERE `prodbatch-Id` = ? AND `prodbatch-Status` = \'Pending\' LIMIT 1'
	);
	$lookup->bind_param('i', $batchId);
	$lookup->execute();
	$batch = $lookup->get_result()->fetch_assoc();
	$lookup->close();
	if (!$batch && $error === '') {
		$error = 'This batch is no longer pending and cannot be logged.';
	}
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $batch) {
	$successQuantity = (string)$batch['prodbatch-Qty'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Production Log</title>
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
	<link rel="stylesheet" href="/cl-test/invtest/nav.css">
</head>
<body class="bg-body-tertiary">
<?php include 'nav.php'; ?>

<main class="app-main">
	<div class="container-fluid py-4 px-3 px-lg-4">
		<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-4">
			<h1 class="page-title mb-0">Production Log</h1>
			<a class="btn btn-outline-secondary" href="prodqueue.php"><i class="bi bi-arrow-left me-1"></i>Back to Production Queue</a>
		</div>

		<?php if ($error !== ''): ?>
			<div class="alert alert-danger" role="alert"><i class="bi bi-exclamation-triangle me-2"></i><?php echo e($error); ?></div>
		<?php endif; ?>

		<?php if ($batch): ?>
			<div class="card border-0 shadow-sm" style="max-width: 560px;">
				<div class="card-header bg-body border-bottom py-3">
					<h2 class="h6 mb-1" id="production-log-title"><?php echo e($batch['prodbatch-Name']); ?></h2>
					<div class="small text-body-secondary">Planned quantity: <?php echo e($batch['prodbatch-Qty']); ?></div>
				</div>
				<form method="post" action="prodlog.php" aria-labelledby="production-log-title">
					<div class="card-body">
						<input type="hidden" name="prodbatch-Id" value="<?php echo e($batch['prodbatch-Id']); ?>">
						<div class="row g-3">
							<div class="col-12 col-sm-6">
								<label for="success-quantity" class="form-label">Success</label>
								<input id="success-quantity" class="form-control" name="prodlog-QtySuccess" type="number" min="0" step="1" value="<?php echo e($successQuantity); ?>" required>
							</div>
							<div class="col-12 col-sm-6">
								<label for="best-before" class="form-label">Best Before</label>
								<input id="best-before" class="form-control" name="prodlog-ExpiryDate" type="date" value="<?php echo e($expiryDate); ?>" required>
							</div>
							<div class="col-12 col-sm-6">
								<label for="error-quantity" class="form-label">Error</label>
								<input id="error-quantity" class="form-control" name="prodlog-QtyError" type="number" min="0" step="1" value="<?php echo e($errorQuantity); ?>" required>
							</div>
							<div class="col-12">
								<label for="reason" class="form-label">Reason</label>
								<input id="reason" class="form-control" name="prodlog-Reason" type="text" value="<?php echo e($reason); ?>" <?php echo (int)$errorQuantity > 0 ? 'required' : ''; ?>>
								<div class="form-text">Required when Error is more than zero.</div>
							</div>
						</div>
					</div>
					<div class="card-footer bg-body d-flex justify-content-end gap-2 py-3">
						<a class="btn btn-outline-secondary" href="prodqueue.php">Cancel</a>
						<button class="btn btn-primary" type="submit"><i class="bi bi-check2-circle me-1"></i>Confirm</button>
					</div>
				</form>
			</div>
		<?php endif; ?>
	</div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
<?php if ($batch): ?>
<script>
	const errorQuantity = document.getElementById('error-quantity');
	const reason = document.getElementById('reason');
	errorQuantity.addEventListener('input', () => {
		reason.required = Number(errorQuantity.value) > 0;
	});
</script>
<?php endif; ?>
</body>
</html>
