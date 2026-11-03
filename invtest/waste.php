<?php
require_once __DIR__ . '/db.php';

function e($value): string
{
	return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function ensureWasteRecord(
	mysqli $conn,
	string $sourceColumn,
	int $sourceId,
	int $quantity,
	float $unitCost,
	float $totalCost,
	string $reason
): void {
	$allowedColumns = ['fgm-Id', 'prodlog-Id', 'ing-Id'];
	if (!in_array($sourceColumn, $allowedColumns, true)) {
		throw new InvalidArgumentException('Unsupported waste source.');
	}

	$lookup = $conn->prepare("SELECT `waste-Id` FROM `m5-waste_log` WHERE `$sourceColumn` = ? LIMIT 1 FOR UPDATE");
	$lookup->bind_param('i', $sourceId);
	$lookup->execute();
	$existing = $lookup->get_result()->fetch_assoc();
	$lookup->close();
	if ($existing) return;

	$insert = $conn->prepare(
		"INSERT INTO `m5-waste_log`
		 (`$sourceColumn`, `waste-Qty`, `waste-UnitCost`, `waste-TotalCost`, `waste-Reason`)
		 VALUES (?, ?, ?, ?, ?)"
	);
	$insert->bind_param('iidds', $sourceId, $quantity, $unitCost, $totalCost, $reason);
	$insert->execute();
	$insert->close();
}

$error = '';
$today = new DateTimeImmutable('today');
$selectedDate = trim((string)($_GET['date'] ?? $today->format('Y-m-d')));
$period = trim((string)($_GET['period'] ?? 'day'));
$dateHasExpectedFormat = preg_match('/^\d{4}-\d{2}-\d{2}$/D', $selectedDate) === 1;
$date = $dateHasExpectedFormat ? DateTimeImmutable::createFromFormat('!Y-m-d', $selectedDate) : false;
$dateErrors = $date !== false ? DateTimeImmutable::getLastErrors() : false;
$dateIsValid = $dateHasExpectedFormat
	&& $date !== false
	&& $date->format('Y-m-d') === $selectedDate
	&& ($dateErrors === false || ($dateErrors['warning_count'] === 0 && $dateErrors['error_count'] === 0));

if (!$dateIsValid) {
	$error = 'Choose a valid date for the waste-log filter.';
	$date = $today;
	$selectedDate = $today->format('Y-m-d');
}
if (!in_array($period, ['day', 'week', 'month', 'year'], true)) {
	$error = 'Choose a valid waste-log timeframe.';
	$period = 'day';
}

switch ($period) {
	case 'week':
		$rangeStart = $date->modify('monday this week');
		$rangeEnd = $rangeStart->modify('+1 week');
		break;
	case 'month':
		$rangeStart = $date->modify('first day of this month');
		$rangeEnd = $rangeStart->modify('+1 month');
		break;
	case 'year':
		$rangeStart = $date->setDate((int)$date->format('Y'), 1, 1);
		$rangeEnd = $rangeStart->modify('+1 year');
		break;
	default:
		$rangeStart = $date;
		$rangeEnd = $date->modify('+1 day');
}
$rangeStartValue = $rangeStart->format('Y-m-d 00:00:00');
$rangeEndValue = $rangeEnd->format('Y-m-d 00:00:00');
$rowsPerPage = 15;
$page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT);
if ($page === false || $page < 1) {
	$page = 1;
}

$transactionStarted = false;
try {
	$conn->begin_transaction();
	$transactionStarted = true;

	$productionErrors = $conn->query(
		'SELECT pl.`prodlog-Id`, pl.`prodlog-QtyError`, pl.`prodlog-Reason`, v.`recvar-RecCost`
		 FROM `m33-production_log` pl
		 JOIN `m3-production_batch` b ON b.`prodbatch-Id` = pl.`prodbatch-Id`
		 JOIN `m222-recipe_variant` v ON v.`recvar-Id` = b.`recvar-Id`
		 WHERE pl.`prodlog-QtyError` > 0
		 ORDER BY pl.`prodlog-Id` ASC
		 FOR UPDATE'
	);
	while ($productionError = $productionErrors->fetch_assoc()) {
		$quantity = (int)$productionError['prodlog-QtyError'];
		$unitCost = (float)($productionError['recvar-RecCost'] ?? 0);
		$totalCost = $unitCost * $quantity;
		$reason = trim((string)($productionError['prodlog-Reason'] ?? ''));
		if ($reason === '') $reason = 'Production error';
		ensureWasteRecord(
			$conn,
			'prodlog-Id',
			(int)$productionError['prodlog-Id'],
			$quantity,
			$unitCost,
			$totalCost,
			$reason
		);
	}
	$productionErrors->free();

	$conn->query(
		'UPDATE `m4-finishedgoods_master`
		 SET `fgm-Status` = \'Expired\'
		 WHERE `fgm-QtyStock` > 0 AND `fgm-ExpiryDate` <= CURDATE() AND `fgm-Status` <> \'Dispatched\''
	);
	$expiredGoods = $conn->query(
		'SELECT fg.`fgm-Id`, fg.`fgm-QtyStock`, COALESCE(v.`recvar-RecCost`, 0) AS `recipe-cost`
		 FROM `m4-finishedgoods_master` fg
		 JOIN `m33-production_log` pl ON pl.`prodlog-Id` = fg.`prodlog-Id`
		 JOIN `m3-production_batch` b ON b.`prodbatch-Id` = pl.`prodbatch-Id`
		 JOIN `m222-recipe_variant` v ON v.`recvar-Id` = b.`recvar-Id`
		 WHERE fg.`fgm-QtyStock` > 0 AND fg.`fgm-ExpiryDate` <= CURDATE() AND fg.`fgm-Status` <> \'Dispatched\'
		 ORDER BY fg.`fgm-Id` ASC
		 FOR UPDATE'
	);
	while ($finishedGood = $expiredGoods->fetch_assoc()) {
		$quantity = (int)$finishedGood['fgm-QtyStock'];
		$totalCost = (float)$finishedGood['recipe-cost'];
		$unitCost = $quantity > 0 ? $totalCost / $quantity : 0.0;
		ensureWasteRecord(
			$conn,
			'fgm-Id',
			(int)$finishedGood['fgm-Id'],
			$quantity,
			$unitCost,
			$totalCost,
			'Expired'
		);
	}
	$expiredGoods->free();

	$expiredIngredients = $conn->query(
		'SELECT `ing-Id`, `ing-StockQty`, `ing-OriginalQty`, `ing-Price`
		 FROM `m111-ingredient_item`
		 WHERE `ing-StockQty` > 0 AND `Ing-ExpiryDate` <= CURDATE()
		 ORDER BY `ing-Id` ASC
		 FOR UPDATE'
	);
	while ($ingredient = $expiredIngredients->fetch_assoc()) {
		$originalQuantity = (float)$ingredient['ing-OriginalQty'];
		$unitCost = $originalQuantity > 0 ? (float)$ingredient['ing-Price'] / $originalQuantity : 0.0;
		$totalCost = $unitCost * (float)$ingredient['ing-StockQty'];
		ensureWasteRecord(
			$conn,
			'ing-Id',
			(int)$ingredient['ing-Id'],
			1,
			$unitCost,
			$totalCost,
			'Expired'
		);
	}
	$expiredIngredients->free();

	$conn->commit();
	$transactionStarted = false;

	$summaryQuery = $conn->prepare(
		'SELECT COUNT(*) AS `record-count`,
		        COALESCE(SUM(CASE WHEN w.`ing-Id` IS NOT NULL THEN 1 ELSE w.`waste-Qty` END), 0) AS `total-items`,
		        COALESCE(SUM(w.`waste-TotalCost`), 0) AS `total-loss`
		 FROM `m5-waste_log` w
		 WHERE w.`waste-Timestamp` >= ? AND w.`waste-Timestamp` < ?'
	);
	$summaryQuery->bind_param('ss', $rangeStartValue, $rangeEndValue);
	$summaryQuery->execute();
	$summary = $summaryQuery->get_result()->fetch_assoc();
	$summaryQuery->close();

	$totalRecords = (int)$summary['record-count'];
	$totalItems = (int)$summary['total-items'];
	$totalLoss = (float)$summary['total-loss'];
	$totalPages = max(1, (int)ceil($totalRecords / $rowsPerPage));
	$page = min($page, $totalPages);
	$offset = ($page - 1) * $rowsPerPage;

	$recordsQuery = $conn->prepare(
		'SELECT w.`waste-Id`, w.`waste-Timestamp`, w.`waste-Qty`, w.`waste-UnitCost`, w.`waste-TotalCost`, w.`waste-Reason`,
		        CASE
		            WHEN w.`fgm-Id` IS NOT NULL THEN \'Finished Goods\'
		            WHEN w.`ing-Id` IS NOT NULL THEN \'Raw Ingredients\'
		            ELSE \'Waste Log\'
		        END AS `source`,
		        CASE
		            WHEN w.`fgm-Id` IS NOT NULL THEN finished_batch.`prodbatch-Name`
		            WHEN w.`ing-Id` IS NOT NULL THEN ing.`ing-Brand`
		            ELSE error_batch.`prodbatch-Name`
		        END AS `item`,
		        CASE WHEN w.`ing-Id` IS NOT NULL THEN 1 ELSE w.`waste-Qty` END AS `quantity`,
		        CASE WHEN w.`prodlog-Id` IS NOT NULL THEN w.`waste-Reason` ELSE \'Expired\' END AS `outcome`
		 FROM `m5-waste_log` w
		 LEFT JOIN `m4-finishedgoods_master` fg ON fg.`fgm-Id` = w.`fgm-Id`
		 LEFT JOIN `m33-production_log` finished_log ON finished_log.`prodlog-Id` = fg.`prodlog-Id`
		 LEFT JOIN `m3-production_batch` finished_batch ON finished_batch.`prodbatch-Id` = finished_log.`prodbatch-Id`
		 LEFT JOIN `m111-ingredient_item` ing ON ing.`ing-Id` = w.`ing-Id`
		 LEFT JOIN `m33-production_log` error_log ON error_log.`prodlog-Id` = w.`prodlog-Id`
		 LEFT JOIN `m3-production_batch` error_batch ON error_batch.`prodbatch-Id` = error_log.`prodbatch-Id`
		 WHERE w.`waste-Timestamp` >= ? AND w.`waste-Timestamp` < ?
		 ORDER BY w.`waste-Timestamp` DESC, w.`waste-Id` DESC
		 LIMIT ? OFFSET ?'
	);
	$recordsQuery->bind_param('ssii', $rangeStartValue, $rangeEndValue, $rowsPerPage, $offset);
	$recordsQuery->execute();
	$records = $recordsQuery->get_result();
} catch (mysqli_sql_exception $exception) {
	if ($transactionStarted) {
		$conn->rollback();
	}
	http_response_code(500);
	die('Waste log could not be loaded: ' . e($exception->getMessage()));
}

$rows = [];
while ($record = $records->fetch_assoc()) {
	$rows[] = $record;
}
$recordsQuery->close();
$paginationQuery = [
	'date' => $selectedDate,
	'period' => $period,
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Waste Log</title>
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
	<link rel="stylesheet" href="/cl-test/invtest/nav.css">
</head>
<body class="bg-body-tertiary">
<?php include 'nav.php'; ?>

<main class="app-main d-flex flex-column">
	<div class="container-fluid flex-grow-1 py-4 px-3 px-lg-4">
		<h1 class="page-title mb-4">Waste Log</h1>

		<?php if ($error !== ''): ?>
			<div class="alert alert-warning" role="alert"><i class="bi bi-exclamation-triangle me-2"></i><?php echo e($error); ?></div>
		<?php endif; ?>

		<div class="card border-0 shadow-sm mb-4">
			<div class="card-body">
				<form class="row g-3 align-items-end" method="get" action="waste.php">
					<div class="col-12 col-sm-auto">
						<label for="filter-date" class="form-label">Choose date</label>
						<input id="filter-date" class="form-control" name="date" type="date" value="<?php echo e($selectedDate); ?>" required>
					</div>
					<fieldset class="col-12 col-sm-auto">
						<legend class="form-label fs-6 float-none w-auto mb-2">Timeframe</legend>
						<div class="btn-group flex-wrap" role="group">
							<?php foreach (['day' => 'Day', 'week' => 'Week', 'month' => 'Month', 'year' => 'Year'] as $periodValue => $periodLabel): ?>
								<input class="btn-check" type="radio" name="period" id="period-<?php echo e($periodValue); ?>" value="<?php echo e($periodValue); ?>" autocomplete="off" <?php echo $period === $periodValue ? 'checked' : ''; ?>>
								<label class="btn btn-outline-primary" for="period-<?php echo e($periodValue); ?>"><?php echo e($periodLabel); ?></label>
							<?php endforeach; ?>
						</div>
					</fieldset>
					<div class="col-12 col-sm-auto">
						<button class="btn btn-primary" type="submit"><i class="bi bi-funnel me-1"></i>Filter</button>
					</div>
				</form>
			</div>
		</div>

		<div class="card border-0 shadow-sm">
			<div class="table-responsive">
				<table class="table table-hover align-middle mb-0">
					<thead class="table-light">
						<tr>
							<th>Date</th>
							<th>Source</th>
							<th>Item</th>
							<th class="text-end">Quantity</th>
							<th class="text-end">Unit Cost</th>
							<th class="text-end">Total Cost</th>
							<th>Outcome</th>
						</tr>
					</thead>
					<tbody>
					<?php if (count($rows) === 0): ?>
						<tr><td colspan="7" class="text-center text-body-secondary py-4">No waste records were found for this timeframe.</td></tr>
					<?php else: ?>
						<?php foreach ($rows as $row): ?>
							<tr>
								<td class="text-nowrap"><?php echo e($row['waste-Timestamp']); ?></td>
								<td><?php echo e($row['source']); ?></td>
								<td><?php echo e($row['item'] ?? 'Unknown item'); ?></td>
								<td class="text-end text-nowrap"><?php echo e($row['quantity']); ?></td>
								<td class="text-end text-nowrap"><?php echo number_format((float)$row['waste-UnitCost'], 2); ?></td>
								<td class="text-end text-nowrap"><?php echo number_format((float)$row['waste-TotalCost'], 2); ?></td>
								<td><?php echo e($row['outcome']); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>

		<?php if ($totalPages > 1): ?>
			<?php
			$previousPage = $page - 1;
			$nextPage = $page + 1;
			?>
			<p class="small text-body-secondary text-center mt-4 mb-2">
				Showing <?php echo number_format(($page - 1) * $rowsPerPage + 1); ?>-<?php echo number_format(min($page * $rowsPerPage, $totalRecords)); ?>
				of <?php echo number_format($totalRecords); ?> records
			</p>
			<nav aria-label="Waste log pages">
				<ul class="pagination justify-content-center flex-wrap mb-0">
					<li class="page-item<?php echo $page === 1 ? ' disabled' : ''; ?>">
						<a class="page-link" href="waste.php?<?php echo e(http_build_query($paginationQuery + ['page' => max(1, $previousPage)])); ?>"<?php echo $page === 1 ? ' aria-disabled="true" tabindex="-1"' : ''; ?>>Previous</a>
					</li>
					<?php for ($pageNumber = 1; $pageNumber <= $totalPages; $pageNumber++): ?>
						<li class="page-item<?php echo $pageNumber === $page ? ' active' : ''; ?>">
							<a class="page-link" href="waste.php?<?php echo e(http_build_query($paginationQuery + ['page' => $pageNumber])); ?>"<?php echo $pageNumber === $page ? ' aria-current="page"' : ''; ?>><?php echo $pageNumber; ?></a>
						</li>
					<?php endfor; ?>
					<li class="page-item<?php echo $page === $totalPages ? ' disabled' : ''; ?>">
						<a class="page-link" href="waste.php?<?php echo e(http_build_query($paginationQuery + ['page' => min($totalPages, $nextPage)])); ?>"<?php echo $page === $totalPages ? ' aria-disabled="true" tabindex="-1"' : ''; ?>>Next</a>
					</li>
				</ul>
			</nav>
		<?php elseif ($totalRecords > 0): ?>
			<p class="small text-body-secondary text-center mt-3 mb-0">Showing <?php echo number_format($totalRecords); ?> records</p>
		<?php endif; ?>
	</div>

	<!-- Totals bar: sticks to the bottom of the screen and follows the sidebar offset of .app-main -->
	<footer class="sticky-bottom bg-body border-top shadow-sm px-3 px-lg-4 py-3 d-flex flex-wrap justify-content-end gap-3 gap-sm-4" aria-live="polite">
		<div>Total Items: <strong class="text-success-emphasis"><?php echo number_format($totalItems); ?></strong></div>
		<div>Total Loss: <strong class="text-success-emphasis"><?php echo number_format($totalLoss, 2); ?></strong></div>
	</footer>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
</body>
</html>
