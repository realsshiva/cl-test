<?php
require_once __DIR__ . '/../invtest/db.php';
require_once __DIR__ . '/product_stock_helpers.php';

function e($value): string
{
	return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function wasteMoney($amount): string
{
	return '₱' . number_format((float)$amount, 2);
}

$embedded = ($_GET['embedded'] ?? '') === '1';
$error = '';
$today = new DateTimeImmutable('today');

try {
	$databaseDateResult = $conn->query('SELECT CURDATE() AS `today`');
	$databaseDate = $databaseDateResult->fetch_assoc();
	if (!$databaseDate || !isset($databaseDate['today'])) {
		throw new RuntimeException('The database did not return its current date.');
	}
	$today = new DateTimeImmutable((string)$databaseDate['today']);
	recordExpiredProductStock($conn);
} catch (mysqli_sql_exception | RuntimeException $exception) {
	error_log('Product waste processing error: ' . $exception->getMessage());
	http_response_code(500);
	$error = 'Expired stock could not be processed. Please try again.';
}

$rangeInput = $_GET['range'] ?? 'today';
$range = is_string($rangeInput) ? $rangeInput : 'today';
$dateInput = $_GET['date'] ?? '';
$selectedDate = is_string($dateInput) ? $dateInput : '';
$dateError = '';

if ($range === 'day' && $selectedDate !== '') {
	$parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $selectedDate);
	$dateProblems = DateTimeImmutable::getLastErrors();
	if (!$parsedDate || ($dateProblems !== false && ($dateProblems['warning_count'] > 0 || $dateProblems['error_count'] > 0))
		|| $parsedDate->format('Y-m-d') !== $selectedDate) {
		$dateError = 'Choose a valid date.';
		$range = 'today';
		$selectedDate = '';
	} else {
		$selectedDate = $parsedDate->format('Y-m-d');
	}
}

if (!in_array($range, ['today', 'week', 'month', 'day'], true)) {
	$range = 'today';
}

if ($range === 'week') {
	$startDate = $today->modify('monday this week');
	$endDate = $startDate->modify('+1 week');
	$periodLabel = 'This Week';
} elseif ($range === 'month') {
	$startDate = $today->modify('first day of this month');
	$endDate = $startDate->modify('+1 month');
	$periodLabel = 'This Month';
} elseif ($range === 'day' && $selectedDate !== '') {
	$startDate = new DateTimeImmutable($selectedDate);
	$endDate = $startDate->modify('+1 day');
	$periodLabel = $startDate->format('F j, Y');
} else {
	$range = 'today';
	$startDate = $today;
	$endDate = $today->modify('+1 day');
	$periodLabel = 'Today';
}

$startDateTime = $startDate->format('Y-m-d 00:00:00');
$endDateTime = $endDate->format('Y-m-d 00:00:00');
$wasteRows = [];
$totalWasteCost = null;

if ($error === '') {
	try {
		$wasteQuery = $conn->prepare(
			'SELECT w.`ptw-Timestamp`, w.`ptw-WastedQty`, w.`ptw-TotalCost`,
			        w.`ptw-WasteType`, p.`pti-Name`, p.`pti-Variant`
			 FROM `m10.3-product_waste` w
			 JOIN `m10.2-product_stock` s ON s.`pts-Id` = w.`pts-Id`
			 JOIN `m10.1-product_information` p ON p.`pti-Id` = s.`pti-Id`
			 WHERE w.`ptw-Timestamp` >= ? AND w.`ptw-Timestamp` < ?
			 ORDER BY w.`ptw-Timestamp` DESC, w.`ptw-Id` DESC'
		);
		$wasteQuery->bind_param('ss', $startDateTime, $endDateTime);
		$wasteQuery->execute();
		$wasteResult = $wasteQuery->get_result();
		while ($waste = $wasteResult->fetch_assoc()) {
			$wasteRows[] = $waste;
		}
		$wasteQuery->close();

		$totalQuery = $conn->prepare(
			'SELECT COALESCE(SUM(`ptw-TotalCost`), 0) AS `totalWasteCost`
			 FROM `m10.3-product_waste`
			 WHERE `ptw-Timestamp` >= ? AND `ptw-Timestamp` < ?'
		);
		$totalQuery->bind_param('ss', $startDateTime, $endDateTime);
		$totalQuery->execute();
		$totalResult = $totalQuery->get_result()->fetch_assoc();
		$totalQuery->close();
		if (!$totalResult || !isset($totalResult['totalWasteCost'])) {
			throw new RuntimeException('The database did not return the total waste cost.');
		}
		$totalWasteCost = (float)$totalResult['totalWasteCost'];
	} catch (mysqli_sql_exception | RuntimeException $exception) {
		error_log('Product waste query error: ' . $exception->getMessage());
		http_response_code(500);
		$error = 'Product waste could not be loaded. Please try again.';
	}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Product Waste</title>
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
	<link rel="stylesheet" href="/cl-test/invtest/nav.css?v=20261103">
</head>
<body class="bg-body-tertiary">
	<?php if (!$embedded): ?>
		<?php include __DIR__ . '/pos_nav.php'; ?>
	<?php endif; ?>
	<?php
	$totalWastedQty = 0;
	foreach ($wasteRows as $waste) {
		$totalWastedQty += (int)$waste['ptw-WastedQty'];
	}
	$filterBase = 'pos_waste.php?' . ($embedded ? 'embedded=1&' : '');
	$rangeLinks = ['today' => 'Today', 'week' => 'This Week', 'month' => 'This Month'];
	?>

	<main class="<?php echo $embedded ? '' : 'app-main'; ?>">
		<div class="container-fluid py-4 px-3 px-lg-4">
			<div class="mb-4">
				<h1 class="<?php echo $embedded ? 'h5 fw-semibold' : 'page-title'; ?> mb-1">Product Waste</h1>
				<p class="text-body-secondary mb-0">Expired stock batches (moved here automatically) and items recorded as waste when a sale is voided.</p>
			</div>

			<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
				<nav class="nav nav-pills gap-1" aria-label="Filter product waste">
					<?php foreach ($rangeLinks as $rangeKey => $rangeLabel): ?>
						<a class="nav-link py-1 px-3<?php echo $range === $rangeKey ? ' active' : ' border bg-body'; ?>"
							href="<?php echo e($filterBase . 'range=' . $rangeKey); ?>"
							<?php echo $range === $rangeKey ? 'aria-current="page"' : ''; ?>><?php echo e($rangeLabel); ?></a>
					<?php endforeach; ?>
				</nav>
				<form class="d-flex flex-wrap align-items-center gap-2 ms-lg-2" method="get" action="pos_waste.php">
					<?php if ($embedded): ?><input type="hidden" name="embedded" value="1"><?php endif; ?>
					<input type="hidden" name="range" value="day">
					<label for="waste-date" class="small text-body-secondary">Date</label>
					<input id="waste-date" class="form-control form-control-sm w-auto<?php echo $range === 'day' ? ' border-primary' : ''; ?>" name="date" type="date" value="<?php echo e($range === 'day' ? $selectedDate : ''); ?>" required>
					<button class="btn btn-outline-primary btn-sm" type="submit"><i class="bi bi-calendar-event me-1"></i>Show Date</button>
				</form>
			</div>

			<?php if ($dateError !== ''): ?>
				<div class="alert alert-warning py-2" role="alert"><i class="bi bi-exclamation-triangle me-2"></i><?php echo e($dateError); ?></div>
			<?php endif; ?>
			<?php if ($error !== ''): ?>
				<div class="alert alert-danger py-2" role="alert"><i class="bi bi-exclamation-octagon me-2"></i><?php echo e($error); ?></div>
			<?php endif; ?>

			<?php if ($error === ''): ?>
				<div class="row row-cols-1 row-cols-sm-3 g-3 mb-4">
					<div class="col">
						<div class="card border-0 shadow-sm h-100">
							<div class="card-body d-flex align-items-center gap-3">
								<span class="icon-tile bg-danger-subtle text-danger-emphasis"><i class="bi bi-trash3"></i></span>
								<div>
									<div class="stat-label">Waste Records</div>
									<div class="stat-value"><?php echo number_format(count($wasteRows)); ?></div>
								</div>
							</div>
						</div>
					</div>
					<div class="col">
						<div class="card border-0 shadow-sm h-100">
							<div class="card-body d-flex align-items-center gap-3">
								<span class="icon-tile bg-warning-subtle text-warning-emphasis"><i class="bi bi-box"></i></span>
								<div>
									<div class="stat-label">Total Wasted Quantity</div>
									<div class="stat-value"><?php echo number_format($totalWastedQty); ?></div>
								</div>
							</div>
						</div>
					</div>
					<div class="col">
						<div class="card border-0 shadow-sm h-100">
							<div class="card-body d-flex align-items-center gap-3">
								<span class="icon-tile bg-primary-subtle text-primary-emphasis"><i class="bi bi-cash-coin"></i></span>
								<div>
									<div class="stat-label">Total Waste Cost</div>
									<div class="stat-value"><?php echo e(wasteMoney($totalWasteCost ?? 0)); ?></div>
								</div>
							</div>
						</div>
					</div>
				</div>
			<?php endif; ?>

			<div class="card border-0 shadow-sm">
				<div class="card-header bg-body small text-body-secondary">
					<i class="bi bi-calendar3 me-1"></i>Showing: <span class="fw-semibold text-body"><?php echo e($periodLabel); ?></span>
				</div>
				<div class="table-responsive">
					<table class="table table-hover align-middle mb-0">
						<thead class="table-light">
							<tr>
								<th scope="col">Timestamp</th>
								<th scope="col">Product Name</th>
								<th scope="col">Product Variant</th>
								<th scope="col" class="text-end">Wasted Quantity</th>
								<th scope="col" class="text-end">Total Cost</th>
								<th scope="col">Reason</th>
							</tr>
						</thead>
						<tbody>
						<?php if ($error !== ''): ?>
							<tr><td colspan="6" class="text-center text-body-secondary py-5"><i class="bi bi-exclamation-circle d-block fs-2 mb-2"></i>Product waste is unavailable.</td></tr>
						<?php elseif (!$wasteRows): ?>
							<tr><td colspan="6" class="text-center text-body-secondary py-5"><i class="bi bi-check2-circle d-block fs-2 mb-2"></i>There are no product waste records for this period.</td></tr>
						<?php else: ?>
							<?php foreach ($wasteRows as $waste): ?>
								<tr>
									<td class="text-nowrap"><?php echo e(date('M j, Y g:i A', strtotime((string)$waste['ptw-Timestamp']))); ?></td>
									<td class="fw-medium"><?php echo e($waste['pti-Name']); ?></td>
									<td><?php echo e($waste['pti-Variant'] ?? '—'); ?></td>
									<td class="text-end"><?php echo e($waste['ptw-WastedQty']); ?></td>
									<td class="text-end text-nowrap"><?php echo e(wasteMoney($waste['ptw-TotalCost'])); ?></td>
									<td><span class="badge bg-danger-subtle text-danger-emphasis"><?php echo e($waste['ptw-WasteType']); ?></span></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
						</tbody>
						<?php if ($error === '' && $wasteRows): ?>
							<tfoot class="table-light">
								<tr>
									<th scope="row" colspan="4" class="text-end">Total Waste Cost</th>
									<th class="text-end text-nowrap"><?php echo e(wasteMoney($totalWasteCost ?? 0)); ?></th>
									<th></th>
								</tr>
							</tfoot>
						<?php endif; ?>
					</table>
				</div>
			</div>
		</div>
	</main>

	<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
</body>
</html>
