<?php
require_once __DIR__ . '/../invtest/db.php';

function e($value): string
{
	return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$embedded = ($_GET['embedded'] ?? '') === '1';
$rangeInput = $_GET['range'] ?? 'today';
$range = is_string($rangeInput) ? $rangeInput : 'today';
$dateInput = $_GET['date'] ?? '';
$selectedDate = is_string($dateInput) ? $dateInput : '';
$loadError = '';
$dateError = '';
$today = new DateTimeImmutable('today');

try {
	$databaseDateResult = $conn->query('SELECT CURDATE() AS `today`');
	$databaseDate = $databaseDateResult->fetch_assoc();
	if (!$databaseDate || !isset($databaseDate['today'])) {
		throw new RuntimeException('The database did not return its current date.');
	}
	$today = new DateTimeImmutable((string)$databaseDate['today']);
} catch (mysqli_sql_exception | RuntimeException $exception) {
	error_log('Dispatch records date lookup error: ' . $exception->getMessage());
	http_response_code(500);
	$loadError = 'Dispatch records could not be loaded. Please try again.';
}

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

$dispatchRecords = [];

if ($loadError === '') {
	try {
		$dispatchRecordsQuery = $conn->prepare(
		'SELECT dr.`dr-Id`, dr.`dr-Timestamp`, dr.`dr-ReceivedQty`,
		        dr.`dr-ExpiryDate`, dr.`dr-Status`, dl.`dl-QtyToSend`,
		        r.`rec-Name`, v.`recvar-Type`
		 FROM `m9-dispatch_record` dr
		 JOIN `m8-dispatch_log` dl ON dl.`dl-Id` = dr.`dl-Id`
		 JOIN `m22-recipe_master` r ON r.`rec-Id` = dl.`rec-Id`
		 LEFT JOIN `m222-recipe_variant3` v
		   ON v.`recvar-Id` = dl.`dl-RecipeVariant` AND v.`rec-Id` = dl.`rec-Id`
		 WHERE dr.`dr-Status` IN (\'Received\', \'Cancelled\')
		   AND dl.`dl-Status` IN (\'Received\', \'Cancelled\')
		   AND dr.`dr-Timestamp` >= ? AND dr.`dr-Timestamp` < ?
		 ORDER BY dr.`dr-Timestamp` DESC, dr.`dr-Id` DESC'
		);
		$startDateTime = $startDate->format('Y-m-d 00:00:00');
		$endDateTime = $endDate->format('Y-m-d 00:00:00');
		$dispatchRecordsQuery->bind_param('ss', $startDateTime, $endDateTime);
		$dispatchRecordsQuery->execute();
		$dispatchResult = $dispatchRecordsQuery->get_result();
		while ($record = $dispatchResult->fetch_assoc()) {
			$dispatchRecords[] = $record;
		}
		$dispatchRecordsQuery->close();
	} catch (mysqli_sql_exception $exception) {
		error_log('Dispatch records query error: ' . $exception->getMessage());
		http_response_code(500);
		$loadError = 'Dispatch records could not be loaded. Please try again.';
	}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Dispatch Records</title>
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
	<link rel="stylesheet" href="/cl-test/invtest/nav.css?v=20261103">
</head>
<body class="bg-body-tertiary">
	<?php if (!$embedded): ?>
		<?php include __DIR__ . '/pos_nav.php'; ?>
	<?php endif; ?>
	<?php
	$statusBadges = ['Received' => 'text-bg-success', 'Cancelled' => 'text-bg-secondary'];
	// Inside the Records frame, these links open the full page instead of nesting it in the frame.
	$linkTarget = $embedded ? ' target="_top"' : '';
	$filterBase = 'dprecords.php?' . ($embedded ? 'embedded=1&' : '');
	$rangeLinks = ['today' => 'Today', 'week' => 'This Week', 'month' => 'This Month'];
	?>

	<main class="<?php echo $embedded ? '' : 'app-main'; ?>">
		<div class="container-fluid py-4 px-3 px-lg-4">
			<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
				<div>
					<h1 class="<?php echo $embedded ? 'h5 fw-semibold' : 'page-title'; ?> mb-1">Dispatch Records</h1>
					<p class="text-body-secondary mb-0">Dispatches the POS has received or cancelled.</p>
				</div>
				<div class="d-flex flex-wrap gap-2">
					<a class="btn btn-outline-secondary btn-sm" href="<?php echo $embedded ? 'records.php?view=dispatches' : 'incoming.php'; ?>"<?php echo $linkTarget; ?>><i class="bi bi-box-arrow-in-down me-1"></i>Incoming Dispatches</a>
					<a class="btn btn-outline-secondary btn-sm" href="<?php echo $embedded ? 'records.php?view=waste' : 'inventory.php'; ?>"<?php echo $linkTarget; ?>><i class="bi bi-boxes me-1"></i>Inventory</a>
				</div>
			</div>

			<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
				<nav class="nav nav-pills gap-1" aria-label="Filter dispatch records">
					<?php foreach ($rangeLinks as $rangeKey => $rangeLabel): ?>
						<a class="nav-link py-1 px-3<?php echo $range === $rangeKey ? ' active' : ' border bg-body'; ?>"
							href="<?php echo e($filterBase . 'range=' . $rangeKey); ?>"
							<?php echo $range === $rangeKey ? 'aria-current="page"' : ''; ?>><?php echo e($rangeLabel); ?></a>
					<?php endforeach; ?>
				</nav>
				<form class="d-flex flex-wrap align-items-center gap-2 ms-lg-2" method="get" action="dprecords.php">
					<?php if ($embedded): ?><input type="hidden" name="embedded" value="1"><?php endif; ?>
					<input type="hidden" name="range" value="day">
					<label for="dispatch-date" class="small text-body-secondary">Date</label>
					<input id="dispatch-date" class="form-control form-control-sm w-auto<?php echo $range === 'day' ? ' border-primary' : ''; ?>" name="date" type="date" value="<?php echo e($range === 'day' ? $selectedDate : ''); ?>" required>
					<button class="btn btn-outline-primary btn-sm" type="submit"><i class="bi bi-calendar-event me-1"></i>Show Date</button>
				</form>
			</div>

			<?php if ($dateError !== ''): ?>
				<div class="alert alert-warning py-2" role="alert"><i class="bi bi-exclamation-triangle me-2"></i><?php echo e($dateError); ?></div>
			<?php endif; ?>
			<?php if ($loadError !== ''): ?>
				<div class="alert alert-danger py-2" role="alert"><i class="bi bi-exclamation-octagon me-2"></i><?php echo e($loadError); ?></div>
			<?php endif; ?>

			<div class="card border-0 shadow-sm">
				<div class="card-header bg-body small text-body-secondary">
					<i class="bi bi-calendar3 me-1"></i>Showing: <span class="fw-semibold text-body"><?php echo e($periodLabel); ?></span>
				</div>
				<div class="table-responsive">
					<table class="table table-hover align-middle mb-0">
						<thead class="table-light">
							<tr>
								<th>Timestamp</th>
								<th>Product Name</th>
								<th>Product Variant</th>
								<th class="text-end">Quantity Sent</th>
								<th class="text-end">Received Quantity</th>
								<th>Expiration Date</th>
								<th>Status</th>
							</tr>
						</thead>
						<tbody>
						<?php if ($loadError !== ''): ?>
							<tr><td colspan="7" class="text-center text-body-secondary py-5"><i class="bi bi-exclamation-circle d-block fs-2 mb-2"></i>Dispatch records are unavailable.</td></tr>
						<?php elseif (!$dispatchRecords): ?>
							<tr><td colspan="7" class="text-center text-body-secondary py-5"><i class="bi bi-inbox d-block fs-2 mb-2"></i>There are no received or cancelled dispatch records for this period.</td></tr>
						<?php else: ?>
							<?php foreach ($dispatchRecords as $record): ?>
								<tr>
									<td class="text-nowrap"><?php echo e($record['dr-Timestamp']); ?></td>
									<td class="fw-medium"><?php echo e($record['rec-Name']); ?></td>
									<td><?php echo e($record['recvar-Type'] ?? 'Unknown variant'); ?></td>
									<td class="text-end"><?php echo e($record['dl-QtyToSend']); ?></td>
									<td class="text-end"><?php echo e($record['dr-ReceivedQty']); ?></td>
									<td class="text-nowrap"><?php echo e($record['dr-ExpiryDate']); ?></td>
									<td><span class="badge <?php echo $statusBadges[$record['dr-Status']] ?? 'text-bg-light'; ?>"><?php echo e($record['dr-Status']); ?></span></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
						</tbody>
					</table>
				</div>
				<?php if ($dispatchRecords): ?>
					<div class="card-footer bg-body small text-body-secondary">Showing <?php echo number_format(count($dispatchRecords)); ?> records</div>
				<?php endif; ?>
			</div>
		</div>
	</main>

	<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
</body>
</html>
