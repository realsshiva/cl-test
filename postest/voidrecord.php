<?php
require_once __DIR__ . '/../invtest/db.php';

function e($value): string
{
	return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function voidRecordMoney($amount): string
{
	return '₱' . number_format((float)$amount, 2);
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
	error_log('Void records date lookup error: ' . $exception->getMessage());
	http_response_code(500);
	$loadError = 'Void records could not be loaded. Please try again.';
}

if ($range === 'day' && $selectedDate !== '') {
	$parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $selectedDate);
	$dateProblems = DateTimeImmutable::getLastErrors();
	if (!$parsedDate
		|| ($dateProblems !== false && ($dateProblems['warning_count'] > 0 || $dateProblems['error_count'] > 0))
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
$voidRecords = [];
$totalVoidedSales = null;

if ($loadError === '') {
	try {
		$recordsQuery = $conn->prepare(
			'SELECT vm.`vm-Id`, vm.`tm-ItemId`, vm.`vm-TransactionDate`,
			        vm.`vm-UnitPrice`, vm.`vm-LineTotal`, vm.`vm-Quantity`,
			        vm.`vm-ProductId`, vm.`vm-ProductName`, vm.`vm-VoidType`,
			        vm.`vm-Reason`, t.`tm-InvoiceNo`
			 FROM `m12-void_master` vm
			 LEFT JOIN `m11.2-order_items` oi ON oi.`oi-OrderId` = vm.`tm-ItemId`
			 LEFT JOIN `m11.1-transaction_master` t
			   ON t.`tm-TransactionId` = oi.`tm-TransactionId`
			 WHERE vm.`vm-TransactionDate` >= ?
			   AND vm.`vm-TransactionDate` < ?
			 ORDER BY vm.`vm-TransactionDate` DESC, vm.`vm-Id` DESC'
		);
		$recordsQuery->bind_param('ss', $startDateTime, $endDateTime);
		$recordsQuery->execute();
		$recordsResult = $recordsQuery->get_result();
		while ($record = $recordsResult->fetch_assoc()) {
			$voidRecords[] = $record;
		}
		$recordsQuery->close();

		$totalQuery = $conn->prepare(
			'SELECT COALESCE(SUM(`vm-LineTotal`), 0) AS `totalVoidedSales`
			 FROM `m12-void_master`
			 WHERE `vm-TransactionDate` >= ?
			   AND `vm-TransactionDate` < ?
			   AND `vm-VoidType` = ?'
		);
		$postCheckoutType = 'Post-Checkout';
		$totalQuery->bind_param('sss', $startDateTime, $endDateTime, $postCheckoutType);
		$totalQuery->execute();
		$totalResult = $totalQuery->get_result()->fetch_assoc();
		$totalQuery->close();
		if (!$totalResult || !isset($totalResult['totalVoidedSales'])) {
			throw new RuntimeException('The database did not return total voided sales.');
		}
		$totalVoidedSales = (float)$totalResult['totalVoidedSales'];
	} catch (mysqli_sql_exception | RuntimeException $exception) {
		error_log('Void records query error: ' . $exception->getMessage());
		http_response_code(500);
		$loadError = 'Void records could not be loaded. Please try again.';
	}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Void Records</title>
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
	<link rel="stylesheet" href="/cl-test/invtest/nav.css?v=20261103">
</head>
<body class="bg-body-tertiary">
	<?php if (!$embedded): ?>
		<?php include __DIR__ . '/pos_nav.php'; ?>
	<?php endif; ?>
	<?php
	$filterBase = 'voidrecord.php?' . ($embedded ? 'embedded=1&' : '');
	$rangeLinks = ['today' => 'Today', 'week' => 'This Week', 'month' => 'This Month'];
	$voidTypeBadges = ['Post-Checkout' => 'text-bg-danger', 'Pre-Checkout' => 'text-bg-secondary'];
	?>

	<main class="<?php echo $embedded ? '' : 'app-main'; ?>">
		<div class="container-fluid py-4 px-3 px-lg-4">
			<div class="mb-4">
				<h1 class="<?php echo $embedded ? 'h5 fw-semibold' : 'page-title'; ?> mb-1">Void Records</h1>
				<p class="text-body-secondary mb-0">Items removed from an order at the counter (pre-checkout) or voided after payment (post-checkout).</p>
			</div>

			<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
				<nav class="nav nav-pills gap-1" aria-label="Filter void records">
					<?php foreach ($rangeLinks as $rangeKey => $rangeLabel): ?>
						<a class="nav-link py-1 px-3<?php echo $range === $rangeKey ? ' active' : ' border bg-body'; ?>"
							href="<?php echo e($filterBase . 'range=' . $rangeKey); ?>"
							<?php echo $range === $rangeKey ? 'aria-current="page"' : ''; ?>><?php echo e($rangeLabel); ?></a>
					<?php endforeach; ?>
				</nav>
				<form class="d-flex flex-wrap align-items-center gap-2 ms-lg-2" method="get" action="voidrecord.php">
					<?php if ($embedded): ?><input type="hidden" name="embedded" value="1"><?php endif; ?>
					<input type="hidden" name="range" value="day">
					<label for="void-date" class="small text-body-secondary">Date</label>
					<input id="void-date" class="form-control form-control-sm w-auto<?php echo $range === 'day' ? ' border-primary' : ''; ?>" name="date" type="date" value="<?php echo e($range === 'day' ? $selectedDate : ''); ?>" required>
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
								<th scope="col">Timestamp</th>
								<th scope="col">Invoice</th>
								<th scope="col">Item ID</th>
								<th scope="col">Product ID</th>
								<th scope="col">Product Name</th>
								<th scope="col" class="text-end">Unit Price</th>
								<th scope="col" class="text-end">Quantity</th>
								<th scope="col" class="text-end">Line Total</th>
								<th scope="col">Void Type</th>
								<th scope="col">Reason</th>
							</tr>
						</thead>
						<tbody>
						<?php if ($loadError !== ''): ?>
							<tr><td colspan="10" class="text-center text-body-secondary py-5"><i class="bi bi-exclamation-circle d-block fs-2 mb-2"></i>Void records are unavailable.</td></tr>
						<?php elseif (!$voidRecords): ?>
							<tr><td colspan="10" class="text-center text-body-secondary py-5"><i class="bi bi-check2-circle d-block fs-2 mb-2"></i>There are no void records for this period.</td></tr>
						<?php else: ?>
							<?php foreach ($voidRecords as $record): ?>
								<tr>
									<td class="text-nowrap"><?php echo e(date('M j, Y g:i A', strtotime((string)$record['vm-TransactionDate']))); ?></td>
									<td class="text-nowrap"><?php echo e($record['tm-InvoiceNo'] ?? '—'); ?></td>
									<td><?php echo e($record['tm-ItemId'] ?? '—'); ?></td>
									<td><?php echo e($record['vm-ProductId']); ?></td>
									<td class="fw-medium"><?php echo e($record['vm-ProductName']); ?></td>
									<td class="text-end text-nowrap"><?php echo e(voidRecordMoney($record['vm-UnitPrice'])); ?></td>
									<td class="text-end"><?php echo e($record['vm-Quantity']); ?></td>
									<td class="text-end text-nowrap"><?php echo e(voidRecordMoney($record['vm-LineTotal'])); ?></td>
									<td><span class="badge <?php echo $voidTypeBadges[$record['vm-VoidType']] ?? 'text-bg-light'; ?>"><?php echo e($record['vm-VoidType']); ?></span></td>
									<td><?php echo e($record['vm-Reason']); ?></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
						</tbody>
						<?php if ($loadError === ''): ?>
							<tfoot class="table-light">
								<tr>
									<th scope="row" colspan="7" class="text-end">Total Voided Sales (post-checkout)</th>
									<th class="text-end text-nowrap"><?php echo e(voidRecordMoney($totalVoidedSales ?? 0)); ?></th>
									<th colspan="2"></th>
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
