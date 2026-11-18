<?php
require_once __DIR__ . '/../invtest/db.php';

function e($value): string
{
	return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function dashboardMoney($amount): string
{
	return '₱' . number_format((float)$amount, 2);
}

function dashboardUrl(array $parameters): string
{
	return 'dashboard.php?' . http_build_query($parameters);
}

function dashboardSalesMetrics(mysqli $conn, string $startDateTime, string $endDateTime): array
{
	$query = $conn->prepare(
		'SELECT COUNT(*) AS `orders`,
		        COALESCE(SUM(`tm-AmountDue`), 0) AS `netRevenue`
		 FROM `m11.1-transaction_master`
		 WHERE `tm-TransactionDate` >= ?
		   AND `tm-TransactionDate` < ?
		   AND `tm-Status` = ?'
	);
	$completedStatus = 'Completed';
	$query->bind_param('sss', $startDateTime, $endDateTime, $completedStatus);
	$query->execute();
	$metrics = $query->get_result()->fetch_assoc();
	$query->close();
	if (!$metrics) {
		throw new RuntimeException('The database did not return dashboard sales totals.');
	}
	return [
		'orders' => (int)$metrics['orders'],
		'netRevenue' => (float)$metrics['netRevenue'],
	];
}

$loadError = '';
$today = new DateTimeImmutable('today');
$currentDateTime = new DateTimeImmutable();

try {
	$databaseClock = $conn->query('SELECT CURDATE() AS `today`, NOW() AS `currentDateTime`')->fetch_assoc();
	if (!$databaseClock || !isset($databaseClock['today'], $databaseClock['currentDateTime'])) {
		throw new RuntimeException('The database did not return its current date and time.');
	}
	$today = new DateTimeImmutable((string)$databaseClock['today']);
	$currentDateTime = new DateTimeImmutable((string)$databaseClock['currentDateTime']);
} catch (mysqli_sql_exception | RuntimeException $exception) {
	error_log('Dashboard date lookup error: ' . $exception->getMessage());
	http_response_code(500);
	$loadError = 'Dashboard data could not be loaded. Please try again.';
}

$kpiPeriodInput = $_GET['kpi'] ?? 'today';
$kpiPeriod = is_string($kpiPeriodInput) && in_array($kpiPeriodInput, ['today', 'week'], true)
	? $kpiPeriodInput
	: 'today';
$shareMonthInput = $_GET['shareMonth'] ?? $today->format('n');
$shareMonth = is_string($shareMonthInput) && ctype_digit($shareMonthInput)
	? (int)$shareMonthInput
	: (int)$today->format('n');
if ($shareMonth < 1 || $shareMonth > 12) {
	$shareMonth = (int)$today->format('n');
}
$currentYear = (int)$today->format('Y');
$firstYear = 2026;
$shareYearInput = $_GET['shareYear'] ?? (string)$currentYear;
$shareYear = is_string($shareYearInput) && ctype_digit($shareYearInput)
	? (int)$shareYearInput
	: $currentYear;
if ($shareYear < $firstYear || $shareYear > $currentYear) {
	$shareYear = $currentYear;
}
$shareStart = new DateTimeImmutable(sprintf('%04d-%02d-01', $shareYear, $shareMonth));
$shareEnd = $shareStart->modify('+1 month');
$shareStartDateTime = $shareStart->format('Y-m-d 00:00:00');
$shareEndDateTime = $shareEnd->format('Y-m-d 00:00:00');

$trendInput = $_GET['trend'] ?? 'week';
$trendMode = is_string($trendInput) && in_array($trendInput, ['week', 'month'], true)
	? $trendInput
	: 'week';
$trendAnchorInput = $_GET['trendAnchor'] ?? $today->format('Y-m');
$trendAnchor = null;
if (is_string($trendAnchorInput) && preg_match('/^\d{4}-\d{2}$/', $trendAnchorInput)) {
	$parsedTrendAnchor = DateTimeImmutable::createFromFormat('!Y-m', $trendAnchorInput);
	$trendAnchorErrors = DateTimeImmutable::getLastErrors();
	if ($parsedTrendAnchor
		&& ($trendAnchorErrors === false
			|| ($trendAnchorErrors['warning_count'] === 0 && $trendAnchorErrors['error_count'] === 0))
		&& $parsedTrendAnchor->format('Y-m') === $trendAnchorInput) {
		$trendAnchor = $parsedTrendAnchor;
	}
}
if (!$trendAnchor) {
	$trendAnchor = $today->modify('first day of this month');
}
$minimumTrendAnchor = new DateTimeImmutable($firstYear . '-01-01');
$currentMonth = $today->modify('first day of this month');
if ($trendAnchor < $minimumTrendAnchor) {
	$trendAnchor = $minimumTrendAnchor;
} elseif ($trendAnchor > $currentMonth) {
	$trendAnchor = $currentMonth;
}

$tomorrow = $today->modify('+1 day');
$weekStart = $today->modify('-' . (int)$today->format('w') . ' days');
$weekStartDateTime = $weekStart->format('Y-m-d 00:00:00');
$weekToDateEndDateTime = $tomorrow->format('Y-m-d 00:00:00');
$todayStartDateTime = $today->format('Y-m-d 00:00:00');
$todayEndDateTime = $tomorrow->format('Y-m-d 00:00:00');
$averageDays = max(1, (int)$weekStart->diff($today)->days + 1);
$kpiStartDateTime = $kpiPeriod === 'week' ? $weekStartDateTime : $todayStartDateTime;
$kpiEndDateTime = $kpiPeriod === 'week' ? $weekToDateEndDateTime : $todayEndDateTime;

if ($trendMode === 'week') {
	$trendStart = $weekStart;
	$trendEnd = $weekStart->modify('+1 week');
	$trendLabel = $weekStart->format('M j') . ' - ' . $trendEnd->modify('-1 day')->format('M j, Y');
} else {
	$trendEnd = $trendAnchor->modify('+1 month');
	$trendStart = $trendAnchor->modify('-6 months');
	if ($trendStart < $minimumTrendAnchor) {
		$trendStart = $minimumTrendAnchor;
	}
	$trendLabel = $trendStart->format('M Y') . ' - ' . $trendAnchor->format('M Y');
}

$trendStartDateTime = $trendStart->format('Y-m-d 00:00:00');
$trendEndDateTime = $trendEnd->format('Y-m-d 00:00:00');
$trendBuckets = [];
$trendChannels = [
	'PoS-Retail' => ['color' => '#2471a3'],
	'PoS-Kiosk' => ['color' => '#29885f'],
	'PoS-B2B' => ['color' => '#d18b21'],
];
$shareTotals = ['PoS-Retail' => 0.0, 'PoS-Kiosk' => 0.0, 'PoS-B2B' => 0.0];
$topItems = [];
$recentTransactions = [];
$todayMetrics = ['orders' => 0, 'netRevenue' => 0.0];
$weekMetrics = ['orders' => 0, 'netRevenue' => 0.0];
$kpiMetrics = ['orders' => 0, 'netRevenue' => 0.0];

if ($loadError === '') {
	try {
		$todayMetrics = dashboardSalesMetrics($conn, $todayStartDateTime, $todayEndDateTime);
		$weekMetrics = dashboardSalesMetrics($conn, $weekStartDateTime, $weekToDateEndDateTime);
		$kpiMetrics = $kpiPeriod === 'week' ? $weekMetrics : $todayMetrics;

		$shareQuery = $conn->prepare(
			'SELECT COALESCE(SUM(`tm-AmountDue`), 0) AS `netRevenue`
			 FROM `m11.1-transaction_master`
			 WHERE `tm-TransactionDate` >= ?
			   AND `tm-TransactionDate` < ?
			   AND `tm-Status` = ?'
		);
		$completedStatus = 'Completed';
		$shareQuery->bind_param('sss', $shareStartDateTime, $shareEndDateTime, $completedStatus);
		$shareQuery->execute();
		$shareResult = $shareQuery->get_result()->fetch_assoc();
		$shareQuery->close();
		if (!$shareResult || !isset($shareResult['netRevenue'])) {
			throw new RuntimeException('The database did not return revenue share totals.');
		}
		$shareTotals['PoS-Retail'] = (float)$shareResult['netRevenue'];

		$bucketExpression = $trendMode === 'week'
			? 'DATE_FORMAT(`tm-TransactionDate`, \'%Y-%m-%d\')'
			: 'DATE_FORMAT(`tm-TransactionDate`, \'%Y-%m-01\')';
		$trendQuery = $conn->prepare(
			'SELECT ' . $bucketExpression . ' AS `bucket`,
			        COALESCE(SUM(`tm-AmountDue`), 0) AS `netRevenue`
			 FROM `m11.1-transaction_master`
			 WHERE `tm-TransactionDate` >= ?
			   AND `tm-TransactionDate` < ?
			   AND `tm-Status` = ?
			 GROUP BY `bucket`
			 ORDER BY `bucket`'
		);
		$trendQuery->bind_param('sss', $trendStartDateTime, $trendEndDateTime, $completedStatus);
		$trendQuery->execute();
		$trendResult = $trendQuery->get_result();
		while ($row = $trendResult->fetch_assoc()) {
			$trendBuckets[(string)$row['bucket']] = (float)$row['netRevenue'];
		}
		$trendQuery->close();

		if ($trendMode === 'week') {
			for ($offset = 0; $offset < 7; $offset++) {
				$bucketDate = $trendStart->modify('+' . $offset . ' days');
				$bucketKey = $bucketDate->format('Y-m-d');
				$trendData[] = [
					'label' => $bucketDate->format('D'),
					'fullLabel' => $bucketDate->format('l, M j'),
					'values' => [
						'PoS-Retail' => $trendBuckets[$bucketKey] ?? 0.0,
						'PoS-Kiosk' => 0.0,
						'PoS-B2B' => 0.0,
					],
				];
			}
		} else {
			for ($bucketDate = $trendStart; $bucketDate < $trendEnd; $bucketDate = $bucketDate->modify('+1 month')) {
				$bucketKey = $bucketDate->format('Y-m-01');
				$trendData[] = [
					'label' => $bucketDate->format('M'),
					'fullLabel' => $bucketDate->format('F Y'),
					'values' => [
						'PoS-Retail' => $trendBuckets[$bucketKey] ?? 0.0,
						'PoS-Kiosk' => 0.0,
						'PoS-B2B' => 0.0,
					],
				];
			}
		}

		$topItemsQuery = $conn->prepare(
			'SELECT oi.`oi-ProductName`, SUM(oi.`oi-Quantity`) AS `quantitySold`
			 FROM `m11.2-order_items` oi
			 JOIN `m11.1-transaction_master` tm
			   ON tm.`tm-TransactionId` = oi.`tm-TransactionId`
			 WHERE tm.`tm-TransactionDate` >= ?
			   AND tm.`tm-TransactionDate` < ?
			   AND tm.`tm-Status` = ?
			 GROUP BY oi.`oi-ProductName`
			 ORDER BY `quantitySold` DESC, oi.`oi-ProductName` ASC
			 LIMIT 5'
		);
		$topItemsQuery->bind_param('sss', $shareStartDateTime, $shareEndDateTime, $completedStatus);
		$topItemsQuery->execute();
		$topItemsResult = $topItemsQuery->get_result();
		while ($item = $topItemsResult->fetch_assoc()) {
			$topItems[] = [
				'name' => (string)$item['oi-ProductName'],
				'quantity' => (int)$item['quantitySold'],
			];
		}
		$topItemsQuery->close();

		$recentQuery = $conn->query(
			'SELECT `tm-InvoiceNo`, `tm-TransactionDate`, `tm-AmountDue`,
			        `tm-Status`, `tm-PaymentMode`
			 FROM `m11.1-transaction_master`
			 ORDER BY `tm-TransactionDate` DESC, `tm-TransactionId` DESC
			 LIMIT 5'
		);
		while ($transaction = $recentQuery->fetch_assoc()) {
			$recentTransactions[] = $transaction;
		}
		$recentQuery->free();
	} catch (mysqli_sql_exception | RuntimeException $exception) {
		error_log('Dashboard data query error: ' . $exception->getMessage());
		http_response_code(500);
		$loadError = 'Dashboard data could not be loaded. Please try again.';
	}
}

$trendData = $trendData ?? [];
$trendMax = 0.0;
foreach ($trendData as $bucket) {
	foreach ($bucket['values'] as $value) {
		$trendMax = max($trendMax, $value);
	}
}
$averageOrders = $weekMetrics['orders'] / $averageDays;
$shareRevenueTotal = array_sum($shareTotals);
$sharePercentages = [];
$shareCursor = 0.0;
$shareSegments = [];
foreach ($shareTotals as $channel => $amount) {
	$percentage = $shareRevenueTotal > 0 ? ($amount / $shareRevenueTotal) * 100 : 0.0;
	$sharePercentages[$channel] = $percentage;
	$nextCursor = $shareCursor + $percentage;
	$channelColor = $trendChannels[$channel]['color'];
	if ($shareRevenueTotal > 0) {
		$shareSegments[] = $channelColor . ' ' . number_format($shareCursor, 3, '.', '') . '% ' .
			number_format($nextCursor, 3, '.', '') . '%';
	}
	$shareCursor = $nextCursor;
}
$pieStyle = $shareRevenueTotal > 0
	? 'conic-gradient(' . implode(', ', $shareSegments) . ')'
	: 'conic-gradient(#e3e8ec 0% 100%)';

$baseParams = [
	'kpi' => $kpiPeriod,
	'trend' => $trendMode,
	'trendAnchor' => $trendAnchor->format('Y-m'),
	'shareMonth' => $shareMonth,
	'shareYear' => $shareYear,
];
$previousTrendUrl = dashboardUrl(array_merge($baseParams, [
	'trend' => 'month',
	'trendAnchor' => $trendAnchor->modify('-1 month')->format('Y-m'),
]));
$nextTrendUrl = dashboardUrl(array_merge($baseParams, [
	'trend' => 'month',
	'trendAnchor' => $trendAnchor->modify('+1 month')->format('Y-m'),
]));
$previousTrendDisabled = $trendAnchor <= $minimumTrendAnchor;
$nextTrendDisabled = $trendAnchor >= $currentMonth;
$sharePeriodLabel = $shareStart->format('F Y');
$shareYearLabel = (string)$shareYear;
$currentDateTimeAttribute = $currentDateTime->format(DateTimeInterface::ATOM);
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Sales Dashboard</title>
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
	<link rel="stylesheet" href="/cl-test/invtest/nav.css?v=20261103">
	<style>
		/* Toggle buttons: the pressed one is filled (state is kept in aria-pressed by the page script). */
		.btn-toggle[aria-pressed="true"] { color: #fff; background-color: var(--bs-primary); border-color: var(--bs-primary); }

		/* Sales trend bar chart */
		.trend-chart { overflow-x: auto; padding-top: 4px; }
		.trend-columns { display: grid; grid-template-columns: repeat(7, minmax(48px, 1fr)); align-items: end; gap: 8px; min-width: 500px; }
		.trend-column { display: flex; min-width: 0; flex-direction: column; align-items: center; gap: 7px; }
		.trend-bars { display: flex; width: 100%; height: 154px; align-items: flex-end; justify-content: center; gap: 4px; padding: 0 3px; border-bottom: 1px solid var(--bs-border-color); }
		.trend-bar { width: min(18px, 28%); min-height: 0; border-radius: 4px 4px 0 0; transition: height .2s ease; }
		.trend-label { color: var(--bs-secondary-color); font-size: .75rem; white-space: nowrap; }
		.legend-swatch { display: inline-block; width: 10px; height: 10px; flex-shrink: 0; border-radius: 2px; }

		/* Revenue share pie */
		.revenue-share-layout { display: grid; grid-template-columns: 180px minmax(0, 1fr); align-items: center; gap: 18px; }
		.share-pie { width: 180px; aspect-ratio: 1; border-radius: 50%; }
		@media (max-width: 650px) {
			.revenue-share-layout { grid-template-columns: 130px minmax(0, 1fr); gap: 12px; }
			.share-pie { width: 130px; }
		}
		@media (max-width: 420px) {
			.revenue-share-layout { grid-template-columns: 1fr; justify-items: center; }
			.revenue-share-layout > :last-child { width: 100%; }
		}
	</style>
</head>
<body class="bg-body-tertiary">
	<?php include __DIR__ . '/snav.php'; ?>

	<main class="app-main">
		<div class="container-fluid py-4 px-3 px-lg-4">
			<header class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-4">
				<h1 class="page-title mb-0">Hello, Admin</h1>
				<span class="text-body-secondary small">
					<i class="bi bi-calendar3 me-1"></i><time id="dashboard-clock" datetime="<?= e($currentDateTimeAttribute) ?>"><?= e($currentDateTime->format('l, F j, Y g:i:s A')) ?></time>
				</span>
			</header>
			<?php if ($loadError !== ''): ?>
				<div class="alert alert-danger" role="alert"><i class="bi bi-exclamation-octagon me-2"></i><?= e($loadError) ?></div>
			<?php endif; ?>

			<section class="row row-cols-1 row-cols-sm-2 row-cols-xl-4 g-3 mb-4" aria-label="Sales overview">
				<div class="col">
					<article class="card border-0 shadow-sm h-100">
						<div class="card-body">
							<div class="d-flex align-items-center gap-3">
								<span class="icon-tile bg-success-subtle text-success-emphasis"><i class="bi bi-cash-stack"></i></span>
								<div class="min-w-0">
									<h2 class="stat-label mb-1">Total Net Revenue</h2>
									<p class="stat-value mb-0"><?= $loadError === '' ? e(dashboardMoney($kpiMetrics['netRevenue'])) : '—' ?></p>
								</div>
							</div>
							<p class="small text-body-secondary mt-2 mb-2"><?= $kpiPeriod === 'week' ? 'Completed orders this week to date' : 'Completed orders today' ?></p>
							<nav class="btn-group btn-group-sm" aria-label="Revenue time range">
								<a class="btn <?= $kpiPeriod === 'today' ? 'btn-primary' : 'btn-outline-secondary' ?>" href="<?= e(dashboardUrl(array_merge($baseParams, ['kpi' => 'today']))) ?>" <?= $kpiPeriod === 'today' ? 'aria-current="page"' : '' ?>>Today</a>
								<a class="btn <?= $kpiPeriod === 'week' ? 'btn-primary' : 'btn-outline-secondary' ?>" href="<?= e(dashboardUrl(array_merge($baseParams, ['kpi' => 'week']))) ?>" <?= $kpiPeriod === 'week' ? 'aria-current="page"' : '' ?>>This Week</a>
							</nav>
						</div>
					</article>
				</div>
				<div class="col">
					<article class="card border-0 shadow-sm h-100">
						<div class="card-body">
							<div class="d-flex align-items-center gap-3">
								<span class="icon-tile bg-primary-subtle text-primary-emphasis"><i class="bi bi-bag-check"></i></span>
								<div class="min-w-0">
									<h2 class="stat-label mb-1">Completed Orders</h2>
									<p class="stat-value mb-0"><?= $loadError === '' ? e($kpiMetrics['orders']) : '—' ?></p>
								</div>
							</div>
							<p class="small text-body-secondary mt-2 mb-2"><?= $kpiPeriod === 'week' ? 'Completed orders this week to date' : 'Completed orders today' ?></p>
							<nav class="btn-group btn-group-sm" aria-label="Order time range">
								<a class="btn <?= $kpiPeriod === 'today' ? 'btn-primary' : 'btn-outline-secondary' ?>" href="<?= e(dashboardUrl(array_merge($baseParams, ['kpi' => 'today']))) ?>" <?= $kpiPeriod === 'today' ? 'aria-current="page"' : '' ?>>Today</a>
								<a class="btn <?= $kpiPeriod === 'week' ? 'btn-primary' : 'btn-outline-secondary' ?>" href="<?= e(dashboardUrl(array_merge($baseParams, ['kpi' => 'week']))) ?>" <?= $kpiPeriod === 'week' ? 'aria-current="page"' : '' ?>>This Week</a>
							</nav>
						</div>
					</article>
				</div>
				<div class="col">
					<article class="card border-0 shadow-sm h-100">
						<div class="card-body">
							<div class="d-flex align-items-center gap-3">
								<span class="icon-tile bg-info-subtle text-info-emphasis"><i class="bi bi-graph-up"></i></span>
								<div class="min-w-0">
									<h2 class="stat-label mb-1">Average Orders</h2>
									<p class="stat-value mb-0"><?= $loadError === '' ? e(number_format($averageOrders, 1)) : '—' ?></p>
								</div>
							</div>
							<p class="small text-body-secondary mt-2 mb-0">Completed orders per elapsed day this week</p>
						</div>
					</article>
				</div>
				<div class="col">
					<article class="card border-0 shadow-sm h-100">
						<div class="card-body">
							<div class="d-flex align-items-center gap-3">
								<span class="icon-tile bg-warning-subtle text-warning-emphasis"><i class="bi bi-display"></i></span>
								<div class="min-w-0">
									<h2 class="stat-label mb-1">Kiosk Queue</h2>
									<p class="stat-value mb-0" aria-hidden="true">&nbsp;</p>
								</div>
							</div>
						</div>
					</article>
				</div>
			</section>

			<div class="row g-3 mb-3">
				<div class="col-12 col-xl-7">
					<section class="card border-0 shadow-sm h-100" aria-labelledby="sales-trend-heading">
						<div class="card-body">
							<h2 class="h5 fw-semibold mb-1" id="sales-trend-heading">Sales Trend</h2>
							<p class="small text-body-secondary mb-3"><?= e($trendLabel) ?> · Net revenue by channel</p>
							<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
								<nav class="btn-group btn-group-sm" aria-label="Sales trend period">
									<a class="btn <?= $trendMode === 'week' ? 'btn-primary' : 'btn-outline-secondary' ?>" href="<?= e(dashboardUrl(array_merge($baseParams, ['trend' => 'week']))) ?>" <?= $trendMode === 'week' ? 'aria-current="page"' : '' ?>>Week</a>
									<a class="btn <?= $trendMode === 'month' ? 'btn-primary' : 'btn-outline-secondary' ?>" href="<?= e(dashboardUrl(array_merge($baseParams, ['trend' => 'month']))) ?>" <?= $trendMode === 'month' ? 'aria-current="page"' : '' ?>>7 Months</a>
								</nav>
								<?php if ($trendMode === 'month'): ?>
									<nav class="btn-group btn-group-sm" aria-label="Move sales trend window">
										<?php if ($previousTrendDisabled): ?>
											<span class="btn btn-outline-secondary disabled" aria-disabled="true"><i class="bi bi-chevron-left"></i></span>
										<?php else: ?>
											<a class="btn btn-outline-secondary" href="<?= e($previousTrendUrl) ?>" aria-label="Previous month"><i class="bi bi-chevron-left"></i></a>
										<?php endif; ?>
										<?php if ($nextTrendDisabled): ?>
											<span class="btn btn-outline-secondary disabled" aria-disabled="true"><i class="bi bi-chevron-right"></i></span>
										<?php else: ?>
											<a class="btn btn-outline-secondary" href="<?= e($nextTrendUrl) ?>" aria-label="Next month"><i class="bi bi-chevron-right"></i></a>
										<?php endif; ?>
									</nav>
								<?php endif; ?>
							</div>
							<?php if ($loadError === ''): ?>
								<div class="trend-chart">
									<div class="trend-columns">
										<?php foreach ($trendData as $bucket): ?>
											<div class="trend-column" title="<?= e($bucket['fullLabel']) ?>">
												<div class="trend-bars">
													<?php foreach ($trendChannels as $channel => $channelStyle): ?>
														<?php
															$amount = $bucket['values'][$channel];
															$height = $trendMax > 0 && $amount > 0 ? ($amount / $trendMax) * 100 : 0;
														?>
														<div
															class="trend-bar"
															style="height: <?= e(number_format($height, 2, '.', '')) ?>%; background: <?= e($channelStyle['color']) ?>;"
															title="<?= e($channel . ': ' . dashboardMoney($amount)) ?>"
															aria-label="<?= e($bucket['fullLabel'] . ', ' . $channel . ': ' . dashboardMoney($amount)) ?>">
														</div>
													<?php endforeach; ?>
												</div>
												<span class="trend-label"><?= e($bucket['label']) ?></span>
											</div>
										<?php endforeach; ?>
									</div>
								</div>
								<div class="d-flex flex-wrap gap-3 mt-3" aria-label="Sales trend legend">
									<?php foreach ($trendChannels as $channel => $channelStyle): ?>
										<span class="d-inline-flex align-items-center gap-2 small text-body-secondary"><span class="legend-swatch" style="background: <?= e($channelStyle['color']) ?>;"></span><?= e($channel) ?></span>
									<?php endforeach; ?>
								</div>
							<?php endif; ?>
						</div>
					</section>
				</div>

				<div class="col-12 col-xl-5">
					<section class="card border-0 shadow-sm h-100" aria-labelledby="revenue-share-heading">
						<div class="card-body">
							<h2 class="h5 fw-semibold mb-1" id="revenue-share-heading">Revenue Share by Channel</h2>
							<p class="small text-body-secondary mb-3"><?= e($sharePeriodLabel) ?> · Completed net revenue</p>
							<?php if ($loadError === ''): ?>
								<div class="revenue-share-layout mb-3">
									<div class="share-pie" role="img" aria-label="Revenue share by channel for <?= e($sharePeriodLabel) ?>" style="background: <?= e($pieStyle) ?>;"></div>
									<ul class="list-unstyled mb-0 d-grid gap-2">
										<?php foreach ($shareTotals as $channel => $amount): ?>
											<li>
												<div class="d-flex align-items-center justify-content-between gap-2">
													<span class="d-inline-flex align-items-center gap-2 small"><span class="legend-swatch" style="background: <?= e($trendChannels[$channel]['color']) ?>;"></span><?= e($channel) ?></span>
													<span class="fw-semibold"><?= e(number_format($sharePercentages[$channel], 1)) ?>%</span>
												</div>
												<div class="small text-body-secondary"><?= e(dashboardMoney($amount)) ?></div>
											</li>
										<?php endforeach; ?>
									</ul>
								</div>
							<?php endif; ?>
							<form class="d-flex flex-wrap align-items-end gap-2" method="get" action="dashboard.php" aria-label="Revenue share period">
								<input type="hidden" name="kpi" value="<?= e($kpiPeriod) ?>">
								<input type="hidden" name="trend" value="<?= e($trendMode) ?>">
								<input type="hidden" name="trendAnchor" value="<?= e($trendAnchor->format('Y-m')) ?>">
								<div>
									<label for="share-month" class="form-label small text-body-secondary mb-1">Month</label>
									<select id="share-month" class="form-select form-select-sm" name="shareMonth">
										<?php for ($optionMonth = 1; $optionMonth <= 12; $optionMonth++): ?>
											<option value="<?= e($optionMonth) ?>" <?= $shareMonth === $optionMonth ? 'selected' : '' ?>><?= e(date('F', mktime(0, 0, 0, $optionMonth, 1))) ?></option>
										<?php endfor; ?>
									</select>
								</div>
								<div>
									<label for="share-year" class="form-label small text-body-secondary mb-1">Year</label>
									<select id="share-year" class="form-select form-select-sm" name="shareYear">
										<?php for ($optionYear = $firstYear; $optionYear <= $currentYear; $optionYear++): ?>
											<option value="<?= e($optionYear) ?>" <?= $shareYear === $optionYear ? 'selected' : '' ?>><?= e($optionYear) ?></option>
										<?php endfor; ?>
									</select>
								</div>
								<button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1"></i>Show</button>
							</form>
						</div>
					</section>
				</div>
			</div>

			<div class="row g-3">
				<div class="col-12 col-xl-7">
					<section class="card border-0 shadow-sm h-100" aria-labelledby="top-items-heading">
						<div class="card-body pb-0">
							<div class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-3">
								<div>
									<h2 class="h5 fw-semibold mb-1" id="top-items-heading">Top 5 Best-Selling Items</h2>
									<p class="small text-body-secondary mb-0">Units sold during <?= e($sharePeriodLabel) ?></p>
								</div>
								<nav class="btn-group btn-group-sm" aria-label="Best-selling items channel">
									<button type="button" class="btn btn-outline-primary btn-toggle" data-item-channel-tab="retail" aria-pressed="true">PoS-Retail</button>
									<button type="button" class="btn btn-outline-primary btn-toggle" data-item-channel-tab="kiosk" aria-pressed="false">PoS-Kiosk</button>
									<button type="button" class="btn btn-outline-primary btn-toggle" data-item-channel-tab="b2b" aria-pressed="false">PoS-B2B</button>
								</nav>
							</div>
						</div>
						<div class="item-channel-panel" data-item-channel-panel="retail">
							<?php if ($loadError !== ''): ?>
								<div class="alert alert-danger mx-3" role="alert"><?= e($loadError) ?></div>
							<?php elseif (!$topItems): ?>
								<p class="text-center text-body-secondary py-4 mb-0"><i class="bi bi-inbox d-block fs-2 mb-2"></i>No completed item sales for this month.</p>
							<?php else: ?>
								<div class="table-responsive">
									<table class="table table-hover align-middle mb-0">
										<thead class="table-light"><tr><th scope="col">#</th><th scope="col">Item</th><th scope="col" class="text-end">Qty Sold</th></tr></thead>
										<tbody>
											<?php foreach ($topItems as $index => $item): ?>
												<tr>
													<td class="text-body-secondary" style="width: 3rem;"><?= e($index + 1) ?></td>
													<td class="fw-medium"><?= e($item['name']) ?></td>
													<td class="text-end"><?= e($item['quantity']) ?></td>
												</tr>
											<?php endforeach; ?>
										</tbody>
									</table>
								</div>
							<?php endif; ?>
						</div>
						<div class="item-channel-panel" data-item-channel-panel="kiosk" hidden></div>
						<div class="item-channel-panel" data-item-channel-panel="b2b" hidden></div>
					</section>
				</div>

				<div class="col-12 col-xl-5">
					<section class="card border-0 shadow-sm h-100" aria-labelledby="recent-transactions-heading">
						<div class="card-body">
							<div class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-2">
								<div>
									<h2 class="h5 fw-semibold mb-1" id="recent-transactions-heading">Recent Transactions Feed</h2>
									<p class="small text-body-secondary mb-0">Latest five transactions across available channels</p>
								</div>
								<a class="btn btn-outline-secondary btn-sm" href="thistory.php">View all</a>
							</div>
							<?php if ($loadError !== ''): ?>
								<div class="alert alert-danger mb-0" role="alert"><?= e($loadError) ?></div>
							<?php elseif (!$recentTransactions): ?>
								<p class="text-center text-body-secondary py-4 mb-0"><i class="bi bi-inbox d-block fs-2 mb-2"></i>No transactions yet.</p>
							<?php else: ?>
								<ul class="list-group list-group-flush">
									<?php foreach ($recentTransactions as $transaction): ?>
										<?php
											$paymentMode = $transaction['tm-PaymentMode'] === 'Bank' ? 'Bank Transfer' : $transaction['tm-PaymentMode'];
											$statusBadge = $transaction['tm-Status'] === 'Completed' ? 'text-bg-success' : 'text-bg-secondary';
										?>
										<li class="list-group-item px-0">
											<div class="d-flex justify-content-between gap-3">
												<span class="fw-semibold text-primary-emphasis text-truncate"><?= e($transaction['tm-InvoiceNo']) ?></span>
												<span class="fw-semibold text-nowrap"><?= e(dashboardMoney($transaction['tm-AmountDue'])) ?></span>
											</div>
											<div class="d-flex flex-wrap align-items-center gap-2 small text-body-secondary mt-1">
												<span class="fw-semibold text-primary">PoS-Retail</span>
												<time datetime="<?= e(date('c', strtotime((string)$transaction['tm-TransactionDate']))) ?>"><?= e(date('M j, g:i A', strtotime((string)$transaction['tm-TransactionDate']))) ?></time>
												<span><?= e($paymentMode) ?></span>
												<span class="badge rounded-pill <?= $statusBadge ?>"><?= e($transaction['tm-Status']) ?></span>
											</div>
										</li>
									<?php endforeach; ?>
								</ul>
							<?php endif; ?>
						</div>
					</section>
				</div>
			</div>
		</div>
	</main>

	<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
	<script>
		(function () {
			'use strict';

			const clock = document.getElementById('dashboard-clock');
			function updateClock() {
				const now = new Date();
				clock.dateTime = now.toISOString();
				clock.textContent = new Intl.DateTimeFormat(undefined, {
					weekday: 'long',
					year: 'numeric',
					month: 'long',
					day: 'numeric',
					hour: 'numeric',
					minute: '2-digit',
					second: '2-digit'
				}).format(now);
			}
			window.setInterval(updateClock, 1000);

			const itemChannelTabs = document.querySelectorAll('[data-item-channel-tab]');
			const itemChannelPanels = document.querySelectorAll('[data-item-channel-panel]');
			itemChannelTabs.forEach(function (tab) {
				tab.addEventListener('click', function () {
					const selectedChannel = tab.dataset.itemChannelTab;
					itemChannelTabs.forEach(function (channelTab) {
						channelTab.setAttribute('aria-pressed', channelTab === tab ? 'true' : 'false');
					});
					itemChannelPanels.forEach(function (panel) {
						panel.hidden = panel.dataset.itemChannelPanel !== selectedChannel;
					});
				});
			});
		}());
	</script>
</body>
</html>
