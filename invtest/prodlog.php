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
	<link rel="stylesheet" href="/cl-test/invtest/style.css">
	<style>
		body { margin: 40px 40px 40px 260px; font-family: Arial, sans-serif; color: #252a27; }
		main { max-width: 900px; }
		.notice { margin: 14px 0; padding: 11px 14px; background: #fff0ed; border-left: 4px solid #bd4937; }
		.modal { display: flex; position: fixed; z-index: 20; inset: 0; align-items: center; justify-content: center; padding: 20px; background: rgb(0 0 0 / 45%); }
		.modal-panel { box-sizing: border-box; width: min(100%, 480px); padding: 22px; background: #fff; border-radius: 6px; box-shadow: 0 12px 36px rgb(0 0 0 / 20%); }
		.modal-panel h2 { margin: 0 0 12px; font-size: 22px; }
		.production-navigation { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 18px; }
		.batch-details { margin: 0 0 18px; color: #59635d; }
		.field { margin: 14px 0; }
		.field label { display: block; margin-bottom: 6px; font-weight: 600; }
		.field input { box-sizing: border-box; width: 100%; padding: 9px; font: inherit; }
		.modal-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 20px; }
		.btn { display: inline-block; padding: 8px 12px; border: 1px solid #69736d; border-radius: 4px; background: #fff; color: #26312a; cursor: pointer; text-decoration: none; font: inherit; }
		.btn-primary { color: #fff; background: #287a59; border-color: #23684c; }
		.btn-secondary { background: #f1f4f2; }
		@media (max-width: 700px) { body { margin: 20px; padding-top: 220px; } }
	</style>
</head>
<body>
<?php include 'nav.php'; ?>
<main>
	<h1>Production Log</h1>
	<?php if ($error !== ''): ?><div class="notice"><?php echo e($error); ?></div><?php endif; ?>
</main>

<?php if ($batch): ?>
<div class="modal" role="dialog" aria-modal="true" aria-labelledby="production-log-title">
	<div class="modal-panel">
		<h2 id="production-log-title">Production Log</h2>
		<p class="batch-details"><strong><?php echo e($batch['prodbatch-Name']); ?></strong> &mdash; planned quantity: <?php echo e($batch['prodbatch-Qty']); ?></p>
		<form method="post" action="prodlog.php">
			<input type="hidden" name="prodbatch-Id" value="<?php echo e($batch['prodbatch-Id']); ?>">
			<div class="field">
				<label for="success-quantity">Success</label>
				<input id="success-quantity" name="prodlog-QtySuccess" type="number" min="0" step="1" value="<?php echo e($successQuantity); ?>" required>
			</div>
			<div class="field">
				<label for="best-before">Best Before</label>
				<input id="best-before" name="prodlog-ExpiryDate" type="date" value="<?php echo e($expiryDate); ?>" required>
			</div>
			<div class="field">
				<label for="error-quantity">Error</label>
				<input id="error-quantity" name="prodlog-QtyError" type="number" min="0" step="1" value="<?php echo e($errorQuantity); ?>" required>
			</div>
			<div class="field">
				<label for="reason">Reason</label>
				<input id="reason" name="prodlog-Reason" type="text" value="<?php echo e($reason); ?>" <?php echo (int)$errorQuantity > 0 ? 'required' : ''; ?>>
			</div>
			<div class="modal-actions">
				<a class="btn" href="prodqueue.php">Cancel</a>
				<button class="btn btn-primary" type="submit">Confirm</button>
			</div>
		</form>
	</div>
</div>
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