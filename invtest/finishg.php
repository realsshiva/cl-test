<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/history_log.php';

function e($value): string
{
	return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$error = '';
$message = $_GET['message'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_finished_good') {
	$finishedGoodId = filter_var($_POST['fgm-Id'] ?? '', FILTER_VALIDATE_INT);
	$quantity = filter_var($_POST['fgm-QtyStock'] ?? '', FILTER_VALIDATE_INT);
	$expiryDate = trim((string)($_POST['fgm-ExpiryDate'] ?? ''));
	$dateHasExpectedFormat = preg_match('/^\d{4}-\d{2}-\d{2}$/D', $expiryDate) === 1;
	$date = $dateHasExpectedFormat ? DateTimeImmutable::createFromFormat('!Y-m-d', $expiryDate) : false;
	$dateErrors = $date !== false ? DateTimeImmutable::getLastErrors() : false;
	$dateIsValid = $dateHasExpectedFormat
		&& $date !== false
		&& $date->format('Y-m-d') === $expiryDate
		&& ($dateErrors === false || ($dateErrors['warning_count'] === 0 && $dateErrors['error_count'] === 0));

	if ($finishedGoodId === false || $finishedGoodId < 1) {
		$error = 'Select a valid finished-goods record.';
	} elseif ($quantity === false || $quantity < 1 || $quantity > 32767) {
		$error = 'Quantity must be a whole number between 1 and 32767.';
	} elseif (!$dateIsValid) {
		$error = 'Enter a valid expiration date.';
	} else {
		$transactionStarted = false;
		try {
			$conn->begin_transaction();
			$transactionStarted = true;

			$lookup = $conn->prepare(
				'SELECT `fgm-Status` FROM `m4-finishedgoods_master`
				 WHERE `fgm-Id` = ? FOR UPDATE'
			);
			$lookup->bind_param('i', $finishedGoodId);
			$lookup->execute();
			$record = $lookup->get_result()->fetch_assoc();
			$lookup->close();

			if (!$record || $record['fgm-Status'] === 'Dispatched') {
				$conn->rollback();
				$transactionStarted = false;
				$error = 'This finished-goods record cannot be edited.';
			} else {
				$update = $conn->prepare(
					'UPDATE `m4-finishedgoods_master`
					 SET `fgm-QtyStock` = ?, `fgm-ExpiryDate` = ?,
					     `fgm-Status` = CASE WHEN ? <= CURDATE() THEN \'Expired\' ELSE \'In-Stock\' END
					 WHERE `fgm-Id` = ? AND `fgm-Status` <> \'Dispatched\''
				);
				$update->bind_param('issi', $quantity, $expiryDate, $expiryDate, $finishedGoodId);
				$update->execute();
				$update->close();

				recordHistory($conn, null, null, null, $finishedGoodId, 'Edited Batch Quantity/Expiration Date', 'Finished Goods');
				$conn->commit();
				$transactionStarted = false;
				header('Location: finishg.php?message=updated');
				exit;
			}
		} catch (mysqli_sql_exception $exception) {
			if ($transactionStarted) {
				$conn->rollback();
			}
			$error = 'The finished-goods record could not be updated: ' . $exception->getMessage();
		}
	}
}

try {
	$conn->query(
		'UPDATE `m4-finishedgoods_master`
		 SET `fgm-Status` = CASE WHEN `fgm-ExpiryDate` <= CURDATE() THEN \'Expired\' ELSE \'In-Stock\' END
		 WHERE `fgm-Status` <> \'Dispatched\' AND `fgm-QtyStock` > 0'
	);
	$finishedGoods = $conn->query(
		'SELECT fg.`fgm-Id`, b.`prodbatch-Name`, fg.`fgm-QtyStock`, fg.`fgm-ExpiryDate`, fg.`fgm-Status`
		 FROM `m4-finishedgoods_master` fg
		 JOIN `m33-production_log` pl ON pl.`prodlog-Id` = fg.`prodlog-Id`
		 JOIN `m3-production_batch` b ON b.`prodbatch-Id` = pl.`prodbatch-Id`
		 WHERE fg.`fgm-QtyStock` > 0 AND fg.`fgm-ExpiryDate` > CURDATE()
		 ORDER BY fg.`fgm-ExpiryDate` ASC, fg.`fgm-Id` ASC'
	);
} catch (mysqli_sql_exception $exception) {
	http_response_code(500);
	die('Finished goods could not be loaded: ' . e($exception->getMessage()));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Finished Goods</title>
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
	<link rel="stylesheet" href="/cl-test/invtest/nav.css">
</head>
<body class="bg-body-tertiary">
<?php include 'nav.php'; ?>

<main class="app-main">
	<div class="container-fluid py-4 px-3 px-lg-4">
		<h1 class="page-title mb-4">Finished Goods</h1>

		<?php if ($error !== ''): ?>
			<div class="alert alert-danger" role="alert"><i class="bi bi-exclamation-triangle me-2"></i><?php echo e($error); ?></div>
		<?php endif; ?>
		<?php if ($message === 'updated'): ?>
			<div class="alert alert-success" role="status"><i class="bi bi-check-circle me-2"></i>Finished-goods record updated.</div>
		<?php endif; ?>
		<?php if ($message === 'dispatch_logged'): ?>
			<div class="alert alert-success" role="status"><i class="bi bi-check-circle me-2"></i>Dispatch recorded as Pending. Finished-goods stock has not been changed.</div>
		<?php endif; ?>

		<div class="card border-0 shadow-sm">
			<div class="table-responsive">
				<table class="table table-hover align-middle mb-0">
					<thead class="table-light">
						<tr><th>Product</th><th>Quantity</th><th>Expiration Date</th><th class="text-end">Action</th></tr>
					</thead>
					<tbody>
					<?php if ($finishedGoods->num_rows === 0): ?>
						<tr><td colspan="4" class="text-center text-body-secondary py-4">No finished goods are currently in stock.</td></tr>
					<?php else: ?>
						<?php while ($finishedGood = $finishedGoods->fetch_assoc()): ?>
							<tr>
								<td><?php echo e($finishedGood['prodbatch-Name']); ?></td>
								<td><?php echo e($finishedGood['fgm-QtyStock']); ?></td>
								<td class="text-nowrap"><?php echo e($finishedGood['fgm-ExpiryDate']); ?></td>
								<td class="text-end">
									<div class="d-inline-flex flex-wrap justify-content-end gap-2">
										<button class="btn btn-outline-secondary btn-sm edit-button" type="button"
											data-bs-toggle="modal" data-bs-target="#edit-modal"
											data-id="<?php echo e($finishedGood['fgm-Id']); ?>"
											data-product="<?php echo e($finishedGood['prodbatch-Name']); ?>"
											data-quantity="<?php echo e($finishedGood['fgm-QtyStock']); ?>"
											data-expiry="<?php echo e($finishedGood['fgm-ExpiryDate']); ?>">
											<i class="bi bi-pencil me-1"></i>Edit
										</button>
										<button class="btn btn-outline-primary btn-sm dispatch-button" type="button"
											data-bs-toggle="modal" data-bs-target="#dispatch-modal"
											data-id="<?php echo e($finishedGood['fgm-Id']); ?>"
											data-product="<?php echo e($finishedGood['prodbatch-Name']); ?>"
											data-quantity="<?php echo e($finishedGood['fgm-QtyStock']); ?>">
											<i class="bi bi-truck me-1"></i>Dispatch
										</button>
									</div>
								</td>
							</tr>
						<?php endwhile; ?>
					<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>
	</div>
</main>

<div class="modal fade" id="edit-modal" tabindex="-1" aria-labelledby="edit-title" aria-hidden="true">
	<div class="modal-dialog modal-dialog-centered">
		<div class="modal-content">
			<form method="post" action="finishg.php">
				<div class="modal-header">
					<h2 class="modal-title fs-5" id="edit-title">Edit finished goods</h2>
					<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
				</div>
				<div class="modal-body">
					<input type="hidden" name="action" value="update_finished_good">
					<input type="hidden" name="fgm-Id" id="edit-fgm-id">
					<p id="edit-product" class="text-body-secondary mb-3"></p>
					<div class="mb-3">
						<label for="edit-quantity" class="form-label">Quantity</label>
						<input id="edit-quantity" class="form-control" name="fgm-QtyStock" type="number" min="1" max="32767" step="1" required>
					</div>
					<div>
						<label for="edit-expiry" class="form-label">Expiration Date</label>
						<input id="edit-expiry" class="form-control" name="fgm-ExpiryDate" type="date" required>
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

<div class="modal fade" id="dispatch-modal" tabindex="-1" aria-labelledby="dispatch-title" aria-hidden="true">
	<div class="modal-dialog modal-dialog-centered">
		<div class="modal-content">
			<form method="post" action="dispatchlog.php">
				<div class="modal-header">
					<h2 class="modal-title fs-5" id="dispatch-title">Dispatch finished goods</h2>
					<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
				</div>
				<div class="modal-body">
					<input type="hidden" name="fgm-Id" id="dispatch-fgm-id">
					<p id="dispatch-product" class="text-body-secondary mb-2"></p>
					<p class="mb-3">Available quantity: <strong id="dispatch-available"></strong></p>
					<div>
						<label for="dispatch-quantity" class="form-label">Quantity to dispatch</label>
						<input id="dispatch-quantity" class="form-control" name="dispatch-Qty" type="number" min="1" step="1" required>
					</div>
				</div>
				<div class="modal-footer">
					<button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
					<button class="btn btn-primary" type="submit"><i class="bi bi-truck me-1"></i>Confirm</button>
				</div>
			</form>
		</div>
	</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
<script>
	// Fill the edit form with the row's data before the Bootstrap modal opens.
	document.getElementById('edit-modal').addEventListener('show.bs.modal', (event) => {
		const button = event.relatedTarget;
		if (!button || !button.classList.contains('edit-button')) return;
		document.getElementById('edit-fgm-id').value = button.dataset.id;
		document.getElementById('edit-product').textContent = button.dataset.product;
		document.getElementById('edit-quantity').value = button.dataset.quantity;
		document.getElementById('edit-expiry').value = button.dataset.expiry;
	});

	// Fill the dispatch form with the row's data and cap the quantity at what is in stock.
	const dispatchModal = document.getElementById('dispatch-modal');
	dispatchModal.addEventListener('show.bs.modal', (event) => {
		const button = event.relatedTarget;
		if (!button || !button.classList.contains('dispatch-button')) return;
		const availableQuantity = button.dataset.quantity;
		document.getElementById('dispatch-fgm-id').value = button.dataset.id;
		document.getElementById('dispatch-product').textContent = button.dataset.product;
		document.getElementById('dispatch-available').textContent = availableQuantity;
		const quantityInput = document.getElementById('dispatch-quantity');
		quantityInput.max = availableQuantity;
		quantityInput.value = '';
	});
	dispatchModal.addEventListener('shown.bs.modal', () => document.getElementById('dispatch-quantity').focus());
</script>
</body>
</html>
