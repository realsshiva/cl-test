<?php
require_once __DIR__ . '/../invtest/db.php';

function e($value): string
{
	return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function reportMoney($amount): string
{
	return '₱' . number_format((float)$amount, 2);
}

$today = new DateTimeImmutable('today');
$loadError = '';

try {
	$databaseDateResult = $conn->query('SELECT CURDATE() AS `today`');
	$databaseDate = $databaseDateResult->fetch_assoc();
	if (!$databaseDate || !isset($databaseDate['today'])) {
		throw new RuntimeException('The database did not return its current date.');
	}
	$today = new DateTimeImmutable((string)$databaseDate['today']);
} catch (mysqli_sql_exception | RuntimeException $exception) {
	error_log('Sales report date lookup error: ' . $exception->getMessage());
	http_response_code(500);
	$loadError = 'The sales report could not be loaded. Please try again.';
}

$periodInput = $_GET['period'] ?? 'today';
$period = is_string($periodInput) ? $periodInput : 'today';
if (!in_array($period, ['today', 'week', 'month', 'quarter', 'year'], true)) {
	$period = 'today';
}

$currentYear = (int)$today->format('Y');
$firstYear = 2026;
$lastYear = max($firstYear, $currentYear);
$yearInput = $_GET['year'] ?? (string)$firstYear;
$year = is_string($yearInput) && ctype_digit($yearInput) ? (int)$yearInput : $firstYear;
if ($year < $firstYear || $year > $lastYear) {
	$year = $firstYear;
}

$weeksInYear = (int)(new DateTimeImmutable($year . '-12-28'))->format('W');
$weekInput = $_GET['week'] ?? $today->format('W');
$week = is_string($weekInput) && ctype_digit($weekInput) ? (int)$weekInput : (int)$today->format('W');
if ($week < 1 || $week > $weeksInYear) {
	$week = 1;
}

$monthInput = $_GET['month'] ?? $today->format('n');
$month = is_string($monthInput) && ctype_digit($monthInput) ? (int)$monthInput : (int)$today->format('n');
if ($month < 1 || $month > 12) {
	$month = 1;
}

$quarterInput = $_GET['quarter'] ?? (string)((int)ceil((int)$today->format('n') / 3));
$quarter = is_string($quarterInput) && ctype_digit($quarterInput) ? (int)$quarterInput : 1;
if ($quarter < 1 || $quarter > 4) {
	$quarter = 1;
}

if ($period === 'week') {
	$startDate = (new DateTimeImmutable())->setISODate($year, $week);
	$periodLabel = sprintf('Week %d, %d', $week, $year);
} elseif ($period === 'month') {
	$startDate = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
	$periodLabel = $startDate->format('F Y');
} elseif ($period === 'quarter') {
	$quarterStartMonth = ($quarter - 1) * 3 + 1;
	$startDate = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $quarterStartMonth));
	$periodLabel = sprintf('Q%d %d', $quarter, $year);
} elseif ($period === 'year') {
	$startDate = new DateTimeImmutable($year . '-01-01');
	$periodLabel = (string)$year;
} elseif ($period === 'today') {
	$startDate = $today;
	$periodLabel = $today->format('F j, Y');
} else {
	$period = 'today';
	$startDate = $today;
	$periodLabel = $today->format('F j, Y');
}
$endDate = match ($period) {
	'week' => $startDate->modify('+1 week'),
	'month' => $startDate->modify('+1 month'),
	'quarter' => $startDate->modify('+3 months'),
	'year' => $startDate->modify('+1 year'),
	default => $startDate->modify('+1 day'),
};

$channelTotals = [
	'PoS-Retail' => ['orders' => 0, 'gross' => 0, 'discount' => 0, 'net' => 0],
	'PoS-Kiosk' => ['orders' => 0, 'gross' => 0, 'discount' => 0, 'net' => 0],
	'PoS-B2B' => ['orders' => 0, 'gross' => 0, 'discount' => 0, 'net' => 0],
];
$paymentModeLabels = [
	'Cash' => 'Cash',
	'E-Wallet' => 'E-Wallet',
	'Bank' => 'Bank Transfer',
];
$paymentModeTotals = [
	'Cash' => 0.0,
	'E-Wallet' => 0.0,
	'Bank Transfer' => 0.0,
];
$voidAdjustmentTotals = ['items' => 0, 'sales' => 0.0];

if ($loadError === '') {
	try {
		$totalsQuery = $conn->prepare(
			'SELECT COUNT(*) AS `orders`,
			        COALESCE(SUM(`tm-GrossPrice`), 0) AS `gross`,
			        COALESCE(SUM(`tm-ScDiscount`), 0) AS `discount`,
			        COALESCE(SUM(`tm-AmountDue`), 0) AS `net`
			 FROM `m11.1-transaction_master`
			 WHERE `tm-TransactionDate` >= ?
			   AND `tm-TransactionDate` < ?
			   AND `tm-Status` = ?'
		);
		$startDateTime = $startDate->format('Y-m-d 00:00:00');
		$endDateTime = $endDate->format('Y-m-d 00:00:00');
		$completedStatus = 'Completed';
		$totalsQuery->bind_param('sss', $startDateTime, $endDateTime, $completedStatus);
		$totalsQuery->execute();
		$retailTotals = $totalsQuery->get_result()->fetch_assoc();
		$totalsQuery->close();
		if (!$retailTotals) {
			throw new RuntimeException('The database did not return sales totals.');
		}
		$channelTotals['PoS-Retail'] = [
			'orders' => (int)$retailTotals['orders'],
			'gross' => (float)$retailTotals['gross'],
			'discount' => (float)$retailTotals['discount'],
			'net' => (float)$retailTotals['net'],
		];

		$paymentModesQuery = $conn->prepare(
			'SELECT `tm-PaymentMode`, COALESCE(SUM(`tm-AmountDue`), 0) AS `totalCollected`
			 FROM `m11.1-transaction_master`
			 WHERE `tm-TransactionDate` >= ?
			   AND `tm-TransactionDate` < ?
			   AND `tm-Status` = ?
			 GROUP BY `tm-PaymentMode`'
		);
		$paymentModesQuery->bind_param('sss', $startDateTime, $endDateTime, $completedStatus);
		$paymentModesQuery->execute();
		$paymentModesResult = $paymentModesQuery->get_result();
		while ($paymentMode = $paymentModesResult->fetch_assoc()) {
			$mode = (string)$paymentMode['tm-PaymentMode'];
			if (!isset($paymentModeLabels[$mode])) {
				throw new RuntimeException('An unsupported payment mode was found in completed transactions.');
			}
			$paymentModeTotals[$paymentModeLabels[$mode]] = (float)$paymentMode['totalCollected'];
		}
		$paymentModesQuery->close();

		$voidTotalsQuery = $conn->prepare(
			'SELECT COALESCE(SUM(`vm-Quantity`), 0) AS `voidedItems`,
			        COALESCE(SUM(
			            CASE WHEN `vm-VoidType` = ? THEN `vm-LineTotal` ELSE 0 END
			        ), 0) AS `voidedSales`
			 FROM `m12-void_master`
			 WHERE `vm-TransactionDate` >= ?
			   AND `vm-TransactionDate` < ?'
		);
		$postCheckoutType = 'Post-Checkout';
		$voidTotalsQuery->bind_param('sss', $postCheckoutType, $startDateTime, $endDateTime);
		$voidTotalsQuery->execute();
		$voidTotals = $voidTotalsQuery->get_result()->fetch_assoc();
		$voidTotalsQuery->close();
		if (!$voidTotals || !isset($voidTotals['voidedItems'], $voidTotals['voidedSales'])) {
			throw new RuntimeException('The database did not return void totals.');
		}
		$voidAdjustmentTotals = [
			'items' => (int)$voidTotals['voidedItems'],
			'sales' => (float)$voidTotals['voidedSales'],
		];
	} catch (mysqli_sql_exception | RuntimeException $exception) {
		error_log('Sales report totals query error: ' . $exception->getMessage());
		http_response_code(500);
		$loadError = 'The sales report could not be loaded. Please try again.';
	}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Sales Report</title>
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
	<link rel="stylesheet" href="/cl-test/invtest/nav.css?v=20261103">
	<style>
		/* Toggle buttons: the pressed one is filled (state is kept in aria-pressed by the page script). */
		.btn-toggle[aria-pressed="true"] { color: #fff; background-color: var(--bs-primary); border-color: var(--bs-primary); }
		.audit-placeholder { min-height: 64px; }
	</style>
</head>
<body class="bg-body-tertiary">
	<?php include __DIR__ . '/snav.php'; ?>

	<main class="app-main">
		<div class="container-fluid py-4 px-3 px-lg-4">
			<div class="d-flex flex-wrap align-items-end justify-content-between gap-3 mb-4">
				<div>
					<h1 class="page-title mb-1">Sales Report</h1>
					<p class="text-body-secondary mb-0">Showing: <span class="fw-medium text-body"><?= e($periodLabel) ?></span></p>
				</div>
				<a class="btn btn-outline-secondary btn-sm" href="thistory.php"><i class="bi bi-receipt me-1"></i>Transaction History</a>
			</div>

			<div class="card border-0 shadow-sm mb-4">
				<div class="card-body">
					<form class="d-flex flex-wrap align-items-end gap-3" method="get" action="sreport.php" aria-label="Sales report time filter">
						<div class="btn-group flex-wrap" role="group" aria-label="Report period">
							<button type="submit" class="btn btn-sm btn-outline-primary btn-toggle" name="period" value="today" aria-pressed="<?= $period === 'today' ? 'true' : 'false' ?>">Today</button>
							<button type="submit" class="btn btn-sm btn-outline-primary btn-toggle" name="period" value="week" aria-pressed="<?= $period === 'week' ? 'true' : 'false' ?>">Weekly</button>
							<button type="submit" class="btn btn-sm btn-outline-primary btn-toggle" name="period" value="month" aria-pressed="<?= $period === 'month' ? 'true' : 'false' ?>">Month</button>
							<button type="submit" class="btn btn-sm btn-outline-primary btn-toggle" name="period" value="quarter" aria-pressed="<?= $period === 'quarter' ? 'true' : 'false' ?>">Quarter</button>
							<button type="submit" class="btn btn-sm btn-outline-primary btn-toggle" name="period" value="year" aria-pressed="<?= $period === 'year' ? 'true' : 'false' ?>">Year</button>
						</div>
						<div class="d-flex flex-wrap align-items-end gap-2">
							<div>
								<label for="report-year" class="form-label small text-body-secondary mb-1">Year</label>
								<select id="report-year" class="form-select form-select-sm" name="year">
									<?php for ($optionYear = $firstYear; $optionYear <= $lastYear; $optionYear++): ?>
										<option value="<?= e($optionYear) ?>" <?= $year === $optionYear ? 'selected' : '' ?>><?= e($optionYear) ?></option>
									<?php endfor; ?>
								</select>
							</div>
							<?php if ($period === 'week'): ?>
								<div>
									<label for="report-week" class="form-label small text-body-secondary mb-1">Week</label>
									<select id="report-week" class="form-select form-select-sm" name="week">
										<?php for ($optionWeek = 1; $optionWeek <= $weeksInYear; $optionWeek++): ?>
											<option value="<?= e($optionWeek) ?>" <?= $week === $optionWeek ? 'selected' : '' ?>>Week <?= e($optionWeek) ?></option>
										<?php endfor; ?>
									</select>
								</div>
							<?php elseif ($period === 'month'): ?>
								<div>
									<label for="report-month" class="form-label small text-body-secondary mb-1">Month</label>
									<select id="report-month" class="form-select form-select-sm" name="month">
										<?php for ($optionMonth = 1; $optionMonth <= 12; $optionMonth++): ?>
											<option value="<?= e($optionMonth) ?>" <?= $month === $optionMonth ? 'selected' : '' ?>><?= e(date('F', mktime(0, 0, 0, $optionMonth, 1))) ?></option>
										<?php endfor; ?>
									</select>
								</div>
							<?php elseif ($period === 'quarter'): ?>
								<div>
									<label for="report-quarter" class="form-label small text-body-secondary mb-1">Quarter</label>
									<select id="report-quarter" class="form-select form-select-sm" name="quarter">
										<?php for ($optionQuarter = 1; $optionQuarter <= 4; $optionQuarter++): ?>
											<option value="<?= e($optionQuarter) ?>" <?= $quarter === $optionQuarter ? 'selected' : '' ?>>Q<?= e($optionQuarter) ?></option>
										<?php endfor; ?>
									</select>
								</div>
							<?php endif; ?>
							<?php if ($period !== 'today'): ?>
								<button type="submit" class="btn btn-sm btn-primary" name="period" value="<?= e($period) ?>"><i class="bi bi-funnel me-1"></i>Show</button>
							<?php endif; ?>
						</div>
					</form>
				</div>
			</div>

			<section class="card border-0 shadow-sm mb-4" aria-labelledby="revenue-summary-heading">
				<div class="card-body pb-0">
					<h2 class="h5 fw-semibold mb-1" id="revenue-summary-heading">Segmented Revenue Summary</h2>
					<p class="text-body-secondary small mb-3"><?= e($periodLabel) ?></p>
				</div>
				<?php if ($loadError !== ''): ?>
					<div class="alert alert-danger mx-3 mb-3" role="alert"><i class="bi bi-exclamation-octagon me-2"></i><?= e($loadError) ?></div>
				<?php else: ?>
					<div class="table-responsive">
						<table class="table table-hover align-middle mb-0">
							<thead class="table-light">
								<tr>
									<th scope="col">Channel</th>
									<th scope="col" class="text-end">Total Orders</th>
									<th scope="col" class="text-end">Gross Revenue</th>
									<th scope="col" class="text-end">Total SC Discount</th>
									<th scope="col" class="text-end">Net Revenue</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ($channelTotals as $channel => $totals): ?>
									<tr>
										<th scope="row" class="fw-medium"><?= e($channel) ?></th>
										<td class="text-end"><?= e($totals['orders']) ?></td>
										<td class="text-end text-nowrap"><?= e(reportMoney($totals['gross'])) ?></td>
										<td class="text-end text-nowrap"><?= e(reportMoney($totals['discount'])) ?></td>
										<td class="text-end text-nowrap fw-semibold"><?= e(reportMoney($totals['net'])) ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>
			</section>

			<section class="card border-0 shadow-sm mb-4" aria-labelledby="audit-heading">
				<div class="card-body">
					<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
						<h2 class="h5 fw-semibold mb-0" id="audit-heading">Audit &amp; Disbursement Ledger</h2>
						<nav class="btn-group" role="group" aria-label="Audit channel">
							<button type="button" class="btn btn-sm btn-outline-primary btn-toggle" data-channel-tab="retail" aria-controls="audit-retail" aria-pressed="true">PoS-Retail</button>
							<button type="button" class="btn btn-sm btn-outline-primary btn-toggle" data-channel-tab="kiosk" aria-controls="audit-kiosk" aria-pressed="false">PoS-Kiosk</button>
							<button type="button" class="btn btn-sm btn-outline-primary btn-toggle" data-channel-tab="b2b" aria-controls="audit-b2b" aria-pressed="false">PoS-B2B</button>
						</nav>
					</div>
					<div class="channel-panel" id="audit-retail" data-channel-panel="retail">
						<section class="mb-4" aria-labelledby="retail-payment-heading">
							<h3 class="stat-label mb-2" id="retail-payment-heading">(A) Payment Mode Breakdown</h3>
							<?php if ($loadError !== ''): ?>
								<div class="alert alert-danger mb-0" role="alert"><i class="bi bi-exclamation-octagon me-2"></i><?= e($loadError) ?></div>
							<?php else: ?>
								<div class="table-responsive border rounded">
									<table class="table align-middle mb-0">
										<thead class="table-light">
											<tr>
												<th scope="col">Payment Mode</th>
												<th scope="col" class="text-end">Total Collected</th>
											</tr>
										</thead>
										<tbody>
											<?php foreach ($paymentModeTotals as $mode => $total): ?>
												<tr>
													<th scope="row" class="fw-medium"><?= e($mode) ?></th>
													<td class="text-end text-nowrap"><?= e(reportMoney($total)) ?></td>
												</tr>
											<?php endforeach; ?>
										</tbody>
									</table>
								</div>
							<?php endif; ?>
						</section>
						<section aria-labelledby="retail-tax-heading">
							<h3 class="stat-label mb-2" id="retail-tax-heading">(B) Tax Accountability</h3>
							<div class="audit-placeholder" aria-hidden="true"></div>
						</section>
					</div>
					<div class="channel-panel" id="audit-kiosk" data-channel-panel="kiosk" hidden>
						<section class="mb-4" aria-labelledby="kiosk-payment-heading">
							<h3 class="stat-label mb-2" id="kiosk-payment-heading">(A) Payment Mode Breakdown</h3>
							<div class="audit-placeholder" aria-hidden="true"></div>
						</section>
						<section aria-labelledby="kiosk-tax-heading">
							<h3 class="stat-label mb-2" id="kiosk-tax-heading">(B) Tax Accountability</h3>
							<div class="audit-placeholder" aria-hidden="true"></div>
						</section>
					</div>
					<div class="channel-panel" id="audit-b2b" data-channel-panel="b2b" hidden>
						<section class="mb-4" aria-labelledby="b2b-payment-heading">
							<h3 class="stat-label mb-2" id="b2b-payment-heading">(A) Payment Mode Breakdown</h3>
							<div class="audit-placeholder" aria-hidden="true"></div>
						</section>
						<section aria-labelledby="b2b-tax-heading">
							<h3 class="stat-label mb-2" id="b2b-tax-heading">(B) Tax Accountability</h3>
							<div class="audit-placeholder" aria-hidden="true"></div>
						</section>
					</div>
				</div>
			</section>

			<section class="card border-0 shadow-sm" aria-labelledby="adjustments-heading">
				<div class="card-body">
					<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
						<h2 class="h5 fw-semibold mb-0" id="adjustments-heading">Operational Adjustments Tracker</h2>
						<nav class="btn-group" role="group" aria-label="Operational adjustments channel">
							<button type="button" class="btn btn-sm btn-outline-primary btn-toggle" data-adjustment-tab="retail" aria-controls="adjustments-retail" aria-pressed="true">PoS-Retail</button>
							<button type="button" class="btn btn-sm btn-outline-primary btn-toggle" data-adjustment-tab="kiosk" aria-controls="adjustments-kiosk" aria-pressed="false">PoS-Kiosk</button>
							<button type="button" class="btn btn-sm btn-outline-primary btn-toggle" data-adjustment-tab="b2b" aria-controls="adjustments-b2b" aria-pressed="false">PoS-B2B</button>
						</nav>
					</div>
					<div class="channel-panel" id="adjustments-retail" data-adjustment-panel="retail">
						<?php if ($loadError !== ''): ?>
							<div class="alert alert-danger mb-0" role="alert"><i class="bi bi-exclamation-octagon me-2"></i><?= e($loadError) ?></div>
						<?php else: ?>
							<div class="table-responsive border rounded">
								<table class="table align-middle mb-0">
									<thead class="table-light">
										<tr>
											<th scope="col">Adjustment</th>
											<th scope="col" class="text-end">Total</th>
										</tr>
									</thead>
									<tbody>
										<tr>
											<th scope="row" class="fw-medium">Total Voided Items</th>
											<td class="text-end"><?= e($voidAdjustmentTotals['items']) ?></td>
										</tr>
										<tr>
											<th scope="row" class="fw-medium">Total Void Sales</th>
											<td class="text-end text-nowrap"><?= e(reportMoney($voidAdjustmentTotals['sales'])) ?></td>
										</tr>
									</tbody>
								</table>
							</div>
						<?php endif; ?>
					</div>
					<div class="channel-panel audit-placeholder" id="adjustments-kiosk" data-adjustment-panel="kiosk" hidden></div>
					<div class="channel-panel audit-placeholder" id="adjustments-b2b" data-adjustment-panel="b2b" hidden></div>
				</div>
			</section>
		</div>
	</main>

	<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
	<script>
		(function () {
			'use strict';

			const tabs = document.querySelectorAll('[data-channel-tab]');
			const panels = document.querySelectorAll('[data-channel-panel]');
			tabs.forEach(function (tab) {
				tab.addEventListener('click', function () {
					const selectedChannel = tab.dataset.channelTab;
					tabs.forEach(function (channelTab) {
						channelTab.setAttribute('aria-pressed', channelTab === tab ? 'true' : 'false');
					});
					panels.forEach(function (panel) {
						panel.hidden = panel.dataset.channelPanel !== selectedChannel;
					});
				});
			});

			const adjustmentTabs = document.querySelectorAll('[data-adjustment-tab]');
			const adjustmentPanels = document.querySelectorAll('[data-adjustment-panel]');
			adjustmentTabs.forEach(function (tab) {
				tab.addEventListener('click', function () {
					const selectedChannel = tab.dataset.adjustmentTab;
					adjustmentTabs.forEach(function (channelTab) {
						channelTab.setAttribute('aria-pressed', channelTab === tab ? 'true' : 'false');
					});
					adjustmentPanels.forEach(function (panel) {
						panel.hidden = panel.dataset.adjustmentPanel !== selectedChannel;
					});
				});
			});
		}());
	</script>
</body>
</html>
