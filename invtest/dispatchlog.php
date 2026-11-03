<?php
require_once __DIR__ . '/db.php';

function e($value): string
{
	return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$finishedGoodInput = $_POST['fgm-Id'] ?? '';
	$quantityInput = $_POST['dispatch-Qty'] ?? '';
	$finishedGoodId = is_string($finishedGoodInput)
		? filter_var($finishedGoodInput, FILTER_VALIDATE_INT)
		: false;
	$quantity = is_string($quantityInput)
		? filter_var($quantityInput, FILTER_VALIDATE_INT)
		: false;

	if ($finishedGoodId === false || $finishedGoodId < 1) {
		$error = 'Select a valid finished-goods record.';
	} elseif ($quantity === false || $quantity < 1) {
		$error = 'Dispatch quantity must be a whole number greater than zero.';
	} else {
		$transactionStarted = false;
		try {
			$conn->begin_transaction();
			$transactionStarted = true;

			$lookup = $conn->prepare(
				'SELECT fg.`fgm-QtyStock`, fg.`fgm-Status`,
				        (fg.`fgm-ExpiryDate` > CURDATE()) AS `is-unexpired`,
				        b.`recvar-Id`, v.`rec-Id`
				 FROM `m4-finishedgoods_master` fg
				 JOIN `m33-production_log` pl ON pl.`prodlog-Id` = fg.`prodlog-Id`
				 JOIN `m3-production_batch` b ON b.`prodbatch-Id` = pl.`prodbatch-Id`
				 JOIN `m222-recipe_variant3` v ON v.`recvar-Id` = b.`recvar-Id`
				 WHERE fg.`fgm-Id` = ?
				 FOR UPDATE'
			);
			$lookup->bind_param('i', $finishedGoodId);
			$lookup->execute();
			$record = $lookup->get_result()->fetch_assoc();
			$lookup->close();

			if (!$record) {
				$error = 'The finished-goods record or its recipe information could not be found.';
			} elseif ((int)$record['fgm-QtyStock'] < 1
				|| (int)$record['is-unexpired'] !== 1
				|| $record['fgm-Status'] === 'Dispatched') {
				$error = 'This finished-goods record is not available for dispatch.';
			} elseif ($quantity > (int)$record['fgm-QtyStock']) {
				$error = 'Dispatch quantity cannot exceed the available quantity of '
					. (int)$record['fgm-QtyStock'] . '.';
			} else {
				$insert = $conn->prepare(
					'INSERT INTO `m8-dispatch_log`
					 (`fgm-Id`, `rec-Id`, `dl-RecipeVariant`, `dl-Timestamp`,
					  `dl-QtyToSend`, `dl-Received`, `dl-Status`)
					 VALUES (?, ?, ?, CURRENT_TIMESTAMP, ?, 0, \'Pending\')'
				);
				$recipeId = (string)$record['rec-Id'];
				$recipeVariant = (string)$record['recvar-Id'];
				$insert->bind_param('issi', $finishedGoodId, $recipeId, $recipeVariant, $quantity);
				$insert->execute();
				$insert->close();

				$conn->commit();
				$transactionStarted = false;
				header('Location: dispatchlog.php?message=dispatch_logged');
				exit;
			}

			$conn->rollback();
			$transactionStarted = false;
		} catch (mysqli_sql_exception $exception) {
			if ($transactionStarted) {
				$conn->rollback();
			}
			$error = 'The dispatch could not be recorded: ' . $exception->getMessage();
		}
	}
}

try {
	$pendingDispatches = $conn->query(
		'SELECT dl.`dl-Id`, dl.`fgm-Id`, r.`rec-Name`, v.`recvar-Type`,
		        dl.`dl-Timestamp`, dl.`dl-QtyToSend`, dl.`dl-Received`, dl.`dl-Status`
		 FROM `m8-dispatch_log` dl
		 LEFT JOIN `m22-recipe_master` r ON r.`rec-Id` = dl.`rec-Id`
		 LEFT JOIN `m222-recipe_variant3` v ON v.`recvar-Id` = dl.`dl-RecipeVariant`
		 WHERE dl.`dl-Status` = \'Pending\'
		 ORDER BY dl.`dl-Timestamp` DESC, dl.`dl-Id` DESC'
	);
	$completedDispatches = $conn->query(
		'SELECT dl.`dl-Id`, dl.`fgm-Id`, r.`rec-Name`, v.`recvar-Type`,
		        dl.`dl-Timestamp`, dl.`dl-QtyToSend`, dl.`dl-Received`, dl.`dl-Status`
		 FROM `m8-dispatch_log` dl
		 LEFT JOIN `m22-recipe_master` r ON r.`rec-Id` = dl.`rec-Id`
		 LEFT JOIN `m222-recipe_variant3` v ON v.`recvar-Id` = dl.`dl-RecipeVariant`
		 WHERE dl.`dl-Status` IN (\'Received\', \'Cancelled\')
		 ORDER BY dl.`dl-Timestamp` DESC, dl.`dl-Id` DESC'
	);
} catch (mysqli_sql_exception $exception) {
	http_response_code(500);
	die('Dispatch records could not be loaded: ' . e($exception->getMessage()));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Dispatch Log</title>
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
	<link rel="stylesheet" href="/cl-test/invtest/nav.css?v=20261103">
</head>
<body class="bg-body-tertiary">
<?php include 'nav.php'; ?>
<?php
$statusBadges = [
	'Pending' => 'text-bg-warning',
	'Received' => 'text-bg-success',
	'Cancelled' => 'text-bg-secondary',
];
$dispatchSections = [
	[
		'title' => 'Pending Dispatches',
		'icon' => 'bi-hourglass-split',
		'tile' => 'bg-warning-subtle text-warning-emphasis',
		'rows' => $pendingDispatches,
		'empty' => 'There are no pending dispatches.',
	],
	[
		'title' => 'Received and Cancelled Dispatches',
		'icon' => 'bi-check2-all',
		'tile' => 'bg-success-subtle text-success-emphasis',
		'rows' => $completedDispatches,
		'empty' => 'There are no received or cancelled dispatches.',
	],
];
?>

<main class="app-main">
	<div class="container-fluid py-4 px-3 px-lg-4">
		<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
			<div>
				<h1 class="page-title mb-1">Dispatch Log</h1>
				<p class="text-body-secondary mb-0">Finished goods sent from the bakeshop to the POS, and what the POS confirmed.</p>
			</div>
			<a class="btn btn-outline-secondary" href="finishg.php"><i class="bi bi-arrow-left me-1"></i>Return to Finished Goods</a>
		</div>

		<?php if ($error !== ''): ?>
			<div class="alert alert-danger" role="alert"><i class="bi bi-exclamation-triangle me-2"></i><?php echo e($error); ?></div>
		<?php endif; ?>
		<?php if (($_GET['message'] ?? '') === 'dispatch_logged'): ?>
			<div class="alert alert-success" role="status"><i class="bi bi-check-circle me-2"></i>Dispatch recorded as Pending.</div>
		<?php endif; ?>

		<div class="row row-cols-1 row-cols-sm-2 g-3 mb-4">
			<?php foreach ($dispatchSections as $section): ?>
				<div class="col">
					<div class="card border-0 shadow-sm h-100">
						<div class="card-body d-flex align-items-center gap-3">
							<span class="icon-tile <?php echo $section['tile']; ?>"><i class="bi <?php echo $section['icon']; ?>"></i></span>
							<div>
								<div class="stat-label"><?php echo e($section['title']); ?></div>
								<div class="stat-value"><?php echo number_format($section['rows']->num_rows); ?></div>
							</div>
						</div>
					</div>
				</div>
			<?php endforeach; ?>
		</div>

		<?php foreach ($dispatchSections as $section): ?>
			<div class="card border-0 shadow-sm mb-4">
				<div class="card-header bg-body border-0 pt-3 d-flex align-items-center justify-content-between gap-2">
					<h2 class="h6 fw-semibold mb-0"><i class="bi <?php echo $section['icon']; ?> me-2 text-body-secondary"></i><?php echo e($section['title']); ?></h2>
					<span class="badge rounded-pill text-bg-light border"><?php echo number_format($section['rows']->num_rows); ?></span>
				</div>
				<div class="table-responsive">
					<table class="table table-hover align-middle mb-0">
						<thead class="table-light">
							<tr>
								<th>Timestamp</th>
								<th>Product Name</th>
								<th>Product Variant</th>
								<th class="text-end">Quantity to Send</th>
								<th class="text-end">Quantity Received</th>
								<th>Status</th>
							</tr>
						</thead>
						<tbody>
						<?php if ($section['rows']->num_rows === 0): ?>
							<tr><td colspan="6" class="text-center text-body-secondary py-4"><i class="bi bi-inbox d-block fs-3 mb-1"></i><?php echo e($section['empty']); ?></td></tr>
						<?php else: ?>
							<?php while ($dispatch = $section['rows']->fetch_assoc()): ?>
								<tr>
									<td class="text-nowrap"><?php echo e($dispatch['dl-Timestamp']); ?></td>
									<td class="fw-medium"><?php echo e($dispatch['rec-Name'] ?? 'Unknown recipe'); ?></td>
									<td><?php echo e($dispatch['recvar-Type'] ?? 'Unknown variant'); ?></td>
									<td class="text-end"><?php echo e($dispatch['dl-QtyToSend']); ?></td>
									<td class="text-end"><?php echo e($dispatch['dl-Received']); ?></td>
									<td><span class="badge <?php echo $statusBadges[$dispatch['dl-Status']] ?? 'text-bg-light'; ?>"><?php echo e($dispatch['dl-Status']); ?></span></td>
								</tr>
							<?php endwhile; ?>
						<?php endif; ?>
						</tbody>
					</table>
				</div>
			</div>
		<?php endforeach; ?>
	</div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
</body>
</html>
