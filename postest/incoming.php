<?php
require_once __DIR__ . '/../invtest/db.php';

function e($value): string
{
	return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$error = '';
$messageInput = $_GET['message'] ?? '';
$message = is_string($messageInput) ? $messageInput : '';
$embedded = ($_GET['embedded'] ?? '') === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$actionInput = $_POST['action'] ?? '';
	$dispatchIdInput = $_POST['dl-Id'] ?? '';
	$receivedInput = $_POST['dr-ReceivedQty'] ?? '';
	$action = is_string($actionInput) ? $actionInput : '';
	$dispatchId = is_string($dispatchIdInput)
		? filter_var($dispatchIdInput, FILTER_VALIDATE_INT)
		: false;

	if (!in_array($action, ['accept_dispatch', 'cancel_dispatch'], true)) {
		$error = 'Choose a valid dispatch action.';
	} elseif ($dispatchId === false || $dispatchId < 1) {
		$error = 'Select a valid dispatch record.';
	} elseif ($action === 'accept_dispatch'
		&& (!is_string($receivedInput)
			|| ($receivedQuantity = filter_var($receivedInput, FILTER_VALIDATE_INT)) === false
			|| $receivedQuantity < 0)) {
		$error = 'Received quantity must be a whole number greater than or equal to zero.';
	} else {
		$transactionStarted = false;
		try {
			$conn->begin_transaction();
			$transactionStarted = true;

			$dispatchLookup = $conn->prepare(
				'SELECT dl.`fgm-Id`, dl.`dl-QtyToSend`, dl.`rec-Id`,
				        dl.`dl-RecipeVariant`, r.`rec-Name`, r.`reccat-Id`,
				        v.`recvar-Type`
				 FROM `m8-dispatch_log` dl
				 JOIN `m22-recipe_master` r ON r.`rec-Id` = dl.`rec-Id`
				 LEFT JOIN `m222-recipe_variant3` v
				   ON v.`recvar-Id` = dl.`dl-RecipeVariant` AND v.`rec-Id` = dl.`rec-Id`
				 WHERE dl.`dl-Id` = ? AND dl.`dl-Status` = \'Pending\'
				 FOR UPDATE'
			);
			$dispatchLookup->bind_param('i', $dispatchId);
			$dispatchLookup->execute();
			$dispatch = $dispatchLookup->get_result()->fetch_assoc();
			$dispatchLookup->close();

			if (!$dispatch) {
				$error = 'This dispatch is no longer pending.';
			} else {
				$finishedGoodId = (int)$dispatch['fgm-Id'];
				$targetQuantity = (int)$dispatch['dl-QtyToSend'];
				$finishedGoodLookup = $conn->prepare(
					'SELECT `fgm-QtyStock`, `fgm-ExpiryDate`
					 FROM `m4-finishedgoods_master`
					 WHERE `fgm-Id` = ?
					 FOR UPDATE'
				);
				$finishedGoodLookup->bind_param('i', $finishedGoodId);
				$finishedGoodLookup->execute();
				$finishedGood = $finishedGoodLookup->get_result()->fetch_assoc();
				$finishedGoodLookup->close();

				if (!$finishedGood) {
					$error = 'The finished-goods record for this dispatch could not be found.';
				} elseif ($action === 'accept_dispatch' && $receivedQuantity > $targetQuantity) {
					$error = 'Received quantity cannot exceed the dispatched target of ' . $targetQuantity . '.';
				} elseif ($action === 'accept_dispatch'
					&& $receivedQuantity > (int)$finishedGood['fgm-QtyStock']) {
					$error = 'There is not enough finished-goods stock to deduct the received quantity.';
				} else {
					$productId = null;
					if ($action === 'accept_dispatch' && $receivedQuantity > 0) {
						if ($dispatch['dl-RecipeVariant'] !== null && $dispatch['recvar-Type'] === null) {
							$error = 'The recipe variant for this dispatch could not be found.';
						} else {
							$productLookup = $conn->prepare(
								'SELECT p.`pti-Id`
								 FROM `m10.1-product_information` p
								 JOIN `m10-product_category` c ON c.`ptc-Id` = p.`ptc-Id`
								 WHERE c.`reccat-Id` = ?
								   AND p.`pti-Name` = ?
								   AND p.`pti-Variant` <=> ?
								 FOR UPDATE'
							);
							$recipeCategoryId = (string)$dispatch['reccat-Id'];
							$productName = (string)$dispatch['rec-Name'];
							$productVariant = $dispatch['recvar-Type'];
							$productLookup->bind_param('sss', $recipeCategoryId, $productName, $productVariant);
							$productLookup->execute();
							$productResult = $productLookup->get_result();
							if ($productResult->num_rows === 0) {
								$error = 'No POS product matches this recipe and variant. Add it under the matching product category before receiving this dispatch.';
							} elseif ($productResult->num_rows > 1) {
								$error = 'Multiple POS products match this recipe and variant. Resolve the duplicate products before receiving this dispatch.';
							} else {
								$product = $productResult->fetch_assoc();
								$productId = (int)$product['pti-Id'];
							}
							$productLookup->close();
						}
					}

					if ($error === '') {
						$status = $action === 'accept_dispatch' ? 'Received' : 'Cancelled';
						$recordQuantity = $action === 'accept_dispatch' ? $receivedQuantity : 0;
						$recordInsert = $conn->prepare(
							'INSERT INTO `m9-dispatch_record`
							 (`dl-Id`, `dr-Timestamp`, `dr-ReceivedQty`, `dr-ExpiryDate`, `dr-Status`)
							 VALUES (?, CURRENT_TIMESTAMP, ?, ?, ?)'
						);
						$expiryDate = (string)$finishedGood['fgm-ExpiryDate'];
						$recordInsert->bind_param('iiss', $dispatchId, $recordQuantity, $expiryDate, $status);
						$recordInsert->execute();
						$recordInsert->close();

						if ($action === 'accept_dispatch' && $receivedQuantity > 0) {
							$stockUpdate = $conn->prepare(
								'UPDATE `m4-finishedgoods_master`
								 SET `fgm-QtyStock` = `fgm-QtyStock` - ?
								 WHERE `fgm-Id` = ? AND `fgm-QtyStock` >= ?'
							);
							$stockUpdate->bind_param('iii', $receivedQuantity, $finishedGoodId, $receivedQuantity);
							$stockUpdate->execute();
							if ($stockUpdate->affected_rows !== 1) {
								$stockUpdate->close();
								throw new RuntimeException('Finished-goods stock changed before the receipt could be applied.');
							}
							$stockUpdate->close();

							$productStockInsert = $conn->prepare(
								'INSERT INTO `m10.2-product_stock`
								 (`pti-Id`, `pts-Quantity`, `pts-Expiry`)
								 VALUES (?, ?, ?)'
							);
							$productStockInsert->bind_param('iis', $productId, $receivedQuantity, $expiryDate);
							$productStockInsert->execute();
							$productStockInsert->close();
						}

						$dispatchUpdate = $conn->prepare(
							'UPDATE `m8-dispatch_log`
							 SET `dl-Received` = ?, `dl-Status` = ?
							 WHERE `dl-Id` = ? AND `dl-Status` = \'Pending\''
						);
						$dispatchUpdate->bind_param('isi', $recordQuantity, $status, $dispatchId);
						$dispatchUpdate->execute();
						if ($dispatchUpdate->affected_rows !== 1) {
							$dispatchUpdate->close();
							throw new RuntimeException('The dispatch status changed before it could be confirmed.');
						}
						$dispatchUpdate->close();

						$conn->commit();
						$transactionStarted = false;
						$redirectQuery = 'message=' . rawurlencode(strtolower($status));
						if ($embedded) {
							$redirectQuery .= '&embedded=1';
						}
						header('Location: incoming.php?' . $redirectQuery);
						exit;
					}
				}
			}

			$conn->rollback();
			$transactionStarted = false;
		} catch (mysqli_sql_exception | RuntimeException $exception) {
			if ($transactionStarted) {
				$conn->rollback();
			}
			$error = 'The dispatch could not be updated: ' . $exception->getMessage();
		}
	}
}

try {
	$pendingDispatches = $conn->query(
		'SELECT dl.`dl-Id`, dl.`dl-Timestamp`, dl.`dl-QtyToSend`,
		        r.`rec-Name`, v.`recvar-Type`, fg.`fgm-ExpiryDate`
		 FROM `m8-dispatch_log` dl
		 JOIN `m22-recipe_master` r ON r.`rec-Id` = dl.`rec-Id`
		 LEFT JOIN `m222-recipe_variant3` v ON v.`recvar-Id` = dl.`dl-RecipeVariant`
		 JOIN `m4-finishedgoods_master` fg ON fg.`fgm-Id` = dl.`fgm-Id`
		 WHERE dl.`dl-Status` = \'Pending\'
		 ORDER BY dl.`dl-Timestamp` DESC, dl.`dl-Id` DESC'
	);
} catch (mysqli_sql_exception $exception) {
	http_response_code(500);
	die('Pending dispatches could not be loaded: ' . e($exception->getMessage()));
}

$successMessages = [
	'received' => 'Dispatch receipt confirmed. Finished-goods stock was deducted and the received batch was added to POS inventory.',
	'cancelled' => 'Dispatch cancelled. Finished-goods stock was not changed.',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Incoming Dispatches</title>
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
	<link rel="stylesheet" href="/cl-test/invtest/nav.css?v=20261103">
</head>
<body class="bg-body-tertiary">
	<?php if (!$embedded): ?>
		<?php include __DIR__ . '/pos_nav.php'; ?>
	<?php endif; ?>

	<main class="<?php echo $embedded ? '' : 'app-main'; ?>">
		<div class="container-fluid py-4 px-3 px-lg-4">
			<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
				<div>
					<h1 class="<?php echo $embedded ? 'h5 fw-semibold' : 'page-title'; ?> mb-1">
						Incoming Dispatches
						<span class="badge rounded-pill text-bg-warning align-middle fs-6 ms-1"><?php echo number_format($pendingDispatches->num_rows); ?> pending</span>
					</h1>
					<p class="text-body-secondary mb-0">Confirm how many items arrived, or cancel a dispatch that never came.</p>
				</div>
				<a class="btn btn-outline-secondary btn-sm" href="dprecords.php"<?php echo $embedded ? ' target="_top"' : ''; ?>><i class="bi bi-journal-text me-1"></i>Dispatch Records</a>
			</div>

			<?php if ($error !== ''): ?>
				<div class="alert alert-danger" role="alert"><i class="bi bi-exclamation-triangle me-2"></i><?php echo e($error); ?></div>
			<?php endif; ?>
			<?php if (isset($successMessages[$message])): ?>
				<div class="alert alert-success" role="status"><i class="bi bi-check-circle me-2"></i><?php echo e($successMessages[$message]); ?></div>
			<?php endif; ?>

			<div class="card border-0 shadow-sm">
				<div class="table-responsive">
					<table class="table table-hover align-middle mb-0">
						<thead class="table-light">
							<tr>
								<th>Timestamp</th>
								<th>Product Name</th>
								<th>Product Variant</th>
								<th class="text-end">Quantity</th>
								<th>Expiration Date</th>
								<th class="text-end">Action</th>
							</tr>
						</thead>
						<tbody>
						<?php if ($pendingDispatches->num_rows === 0): ?>
							<tr><td colspan="6" class="text-center text-body-secondary py-5"><i class="bi bi-inbox d-block fs-2 mb-2"></i>There are no pending dispatches.</td></tr>
						<?php else: ?>
							<?php while ($dispatch = $pendingDispatches->fetch_assoc()): ?>
								<tr>
									<td class="text-nowrap"><?php echo e($dispatch['dl-Timestamp']); ?></td>
									<td class="fw-medium"><?php echo e($dispatch['rec-Name']); ?></td>
									<td><?php echo e($dispatch['recvar-Type'] ?? 'Unknown variant'); ?></td>
									<td class="text-end"><?php echo e($dispatch['dl-QtyToSend']); ?></td>
									<td class="text-nowrap"><?php echo e($dispatch['fgm-ExpiryDate']); ?></td>
									<td class="text-end">
										<div class="d-inline-flex flex-wrap justify-content-end gap-2">
											<button class="btn btn-success btn-sm accept-button" type="button"
												data-bs-toggle="modal" data-bs-target="#accept-modal"
												data-id="<?php echo e($dispatch['dl-Id']); ?>"
												data-product="<?php echo e($dispatch['rec-Name']); ?>"
												data-variant="<?php echo e($dispatch['recvar-Type'] ?? 'Unknown variant'); ?>"
												data-quantity="<?php echo e($dispatch['dl-QtyToSend']); ?>"><i class="bi bi-check-lg me-1"></i>Accept</button>
											<button class="btn btn-outline-danger btn-sm cancel-button" type="button"
												data-bs-toggle="modal" data-bs-target="#cancel-modal"
												data-id="<?php echo e($dispatch['dl-Id']); ?>"
												data-product="<?php echo e($dispatch['rec-Name']); ?>"
												data-variant="<?php echo e($dispatch['recvar-Type'] ?? 'Unknown variant'); ?>"><i class="bi bi-x-lg me-1"></i>Cancel</button>
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

	<div class="modal fade" id="accept-modal" tabindex="-1" aria-labelledby="accept-title" aria-hidden="true">
		<div class="modal-dialog modal-dialog-centered">
			<div class="modal-content">
				<form method="post" action="incoming.php<?php echo $embedded ? '?embedded=1' : ''; ?>">
					<div class="modal-header">
						<h2 class="modal-title fs-5" id="accept-title"><i class="bi bi-box-arrow-in-down me-2 text-success"></i>Confirm received dispatch</h2>
						<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
					</div>
					<div class="modal-body">
						<input type="hidden" name="action" value="accept_dispatch">
						<input type="hidden" name="dl-Id" id="accept-dispatch-id">
						<p id="accept-product" class="fw-semibold mb-1"></p>
						<p class="text-body-secondary mb-3">Dispatched quantity: <strong id="accept-target" class="text-body"></strong></p>
						<label for="received-quantity" class="form-label">Received Quantity</label>
						<input id="received-quantity" class="form-control" name="dr-ReceivedQty" type="number" min="0" step="1" required>
						<div class="form-text">Enter 0 if nothing usable arrived.</div>
					</div>
					<div class="modal-footer">
						<button class="btn btn-outline-secondary close-modal" type="button" data-bs-dismiss="modal">Cancel</button>
						<button class="btn btn-success" type="submit"><i class="bi bi-check-lg me-1"></i>Confirm</button>
					</div>
				</form>
			</div>
		</div>
	</div>

	<div class="modal fade" id="cancel-modal" tabindex="-1" aria-labelledby="cancel-title" aria-hidden="true">
		<div class="modal-dialog modal-dialog-centered">
			<div class="modal-content">
				<form method="post" action="incoming.php<?php echo $embedded ? '?embedded=1' : ''; ?>">
					<div class="modal-header">
						<h2 class="modal-title fs-5" id="cancel-title"><i class="bi bi-exclamation-triangle me-2 text-danger"></i>Cancel this dispatch?</h2>
						<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
					</div>
					<div class="modal-body">
						<input type="hidden" name="action" value="cancel_dispatch">
						<input type="hidden" name="dl-Id" id="cancel-dispatch-id">
						<p id="cancel-product" class="fw-semibold mb-1"></p>
						<p class="text-body-secondary mb-0">Finished-goods stock will not change.</p>
					</div>
					<div class="modal-footer">
						<button class="btn btn-outline-secondary close-modal" type="button" data-bs-dismiss="modal">Keep Pending</button>
						<button class="btn btn-danger" type="submit">Confirm Cancel</button>
					</div>
				</form>
			</div>
		</div>
	</div>

	<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
	<script>
		const acceptModal = document.getElementById('accept-modal');
		const cancelModal = document.getElementById('cancel-modal');
		const receivedInput = document.getElementById('received-quantity');

		// Fill the accept form with the row's data before the Bootstrap modal opens.
		acceptModal.addEventListener('show.bs.modal', (event) => {
			const button = event.relatedTarget;
			if (!button || !button.classList.contains('accept-button')) return;
			const quantity = button.dataset.quantity;
			document.getElementById('accept-dispatch-id').value = button.dataset.id;
			document.getElementById('accept-product').textContent =
				button.dataset.product + ' — ' + button.dataset.variant;
			document.getElementById('accept-target').textContent = quantity;
			receivedInput.max = quantity;
			receivedInput.value = quantity;
		});
		acceptModal.addEventListener('shown.bs.modal', () => receivedInput.focus());

		cancelModal.addEventListener('show.bs.modal', (event) => {
			const button = event.relatedTarget;
			if (!button || !button.classList.contains('cancel-button')) return;
			document.getElementById('cancel-dispatch-id').value = button.dataset.id;
			document.getElementById('cancel-product').textContent =
				button.dataset.product + ' — ' + button.dataset.variant;
		});
	</script>
</body>
</html>
