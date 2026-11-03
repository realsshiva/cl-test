<?php
function e($value): string
{
	return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$activeView = $_GET['view'] ?? 'incoming';
if (!in_array($activeView, ['incoming', 'inventory'], true)) {
	$activeView = 'incoming';
}

$views = [
	'incoming' => [
		'label' => 'Incoming',
		'file' => 'incoming.php?embedded=1',
	],
	'inventory' => [
		'label' => 'Inventory',
		'file' => 'inventory.php?embedded=1',
	],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Supply</title>
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
	<link rel="stylesheet" href="/cl-test/invtest/nav.css?v=20261103">
</head>
<body class="bg-body-tertiary">
	<?php include __DIR__ . '/pos_nav.php'; ?>
	<?php
	// Read-only summary numbers for the cards at the top. A failure here only hides the numbers.
	$supplyStats = null;
	try {
		require_once __DIR__ . '/../invtest/db.php';
		$supplyStats = $conn->query(
			'SELECT
				(SELECT COUNT(*) FROM `m8-dispatch_log` WHERE `dl-Status` = \'Pending\') AS `pending-count`,
				(SELECT COALESCE(SUM(`dl-QtyToSend`), 0) FROM `m8-dispatch_log` WHERE `dl-Status` = \'Pending\') AS `pending-units`,
				(SELECT COALESCE(SUM(`pts-Quantity`), 0) FROM `m10.2-product_stock`
				 WHERE `pts-Quantity` > 0 AND (`pts-Expiry` IS NULL OR `pts-Expiry` > CURDATE())) AS `stock-units`,
				(SELECT COUNT(DISTINCT `pti-Id`) FROM `m10.2-product_stock`
				 WHERE `pts-Quantity` > 0 AND (`pts-Expiry` IS NULL OR `pts-Expiry` > CURDATE())) AS `stock-products`,
				(SELECT COUNT(*) FROM `m10.2-product_stock`
				 WHERE `pts-Quantity` > 0 AND `pts-Expiry` > CURDATE()
				   AND `pts-Expiry` <= CURDATE() + INTERVAL 3 DAY) AS `expiring-batches`'
		)->fetch_assoc();
	} catch (Throwable $exception) {
		$supplyStats = null;
	}

	$viewIcons = ['incoming' => 'bi-box-arrow-in-down', 'inventory' => 'bi-boxes'];
	$viewHints = [
		'incoming' => 'Confirm or cancel dispatches sent from the bakeshop.',
		'inventory' => 'Browse product categories and check stock batches.',
	];
	?>

	<main class="app-main">
		<div class="container-fluid py-4 px-3 px-lg-4">
			<div class="d-flex flex-wrap align-items-end justify-content-between gap-3 mb-4">
				<div>
					<h1 class="page-title mb-1">Supply</h1>
					<p class="text-body-secondary mb-0">Receive dispatches from the bakeshop and check product stock.</p>
				</div>
				<a class="btn btn-outline-secondary btn-sm" href="records.php?view=dispatches"><i class="bi bi-journal-text me-1"></i>Dispatch Records</a>
			</div>

			<?php if ($supplyStats !== null): ?>
				<div class="row row-cols-1 row-cols-md-3 g-3 mb-4">
					<div class="col">
						<a class="card card-hover h-100 border-0 shadow-sm text-decoration-none" href="supply.php?view=incoming">
							<div class="card-body d-flex align-items-center gap-3">
								<span class="icon-tile <?php echo (int)$supplyStats['pending-count'] > 0 ? 'bg-warning-subtle text-warning-emphasis' : 'bg-success-subtle text-success-emphasis'; ?>"><i class="bi bi-hourglass-split"></i></span>
								<div class="flex-grow-1 min-w-0">
									<div class="stat-label">Pending Dispatches</div>
									<div class="stat-value text-body"><?php echo number_format((int)$supplyStats['pending-count']); ?></div>
									<small class="text-body-secondary"><?php echo number_format((int)$supplyStats['pending-units']); ?> units waiting</small>
								</div>
								<i class="bi bi-chevron-right text-body-secondary card-arrow"></i>
							</div>
						</a>
					</div>
					<div class="col">
						<a class="card card-hover h-100 border-0 shadow-sm text-decoration-none" href="supply.php?view=inventory">
							<div class="card-body d-flex align-items-center gap-3">
								<span class="icon-tile bg-primary-subtle text-primary-emphasis"><i class="bi bi-boxes"></i></span>
								<div class="flex-grow-1 min-w-0">
									<div class="stat-label">Units In Stock</div>
									<div class="stat-value text-body"><?php echo number_format((int)$supplyStats['stock-units']); ?></div>
									<small class="text-body-secondary">across <?php echo number_format((int)$supplyStats['stock-products']) . ((int)$supplyStats['stock-products'] === 1 ? ' product' : ' products'); ?></small>
								</div>
								<i class="bi bi-chevron-right text-body-secondary card-arrow"></i>
							</div>
						</a>
					</div>
					<div class="col">
						<a class="card card-hover h-100 border-0 shadow-sm text-decoration-none" href="supply.php?view=inventory">
							<div class="card-body d-flex align-items-center gap-3">
								<span class="icon-tile <?php echo (int)$supplyStats['expiring-batches'] > 0 ? 'bg-danger-subtle text-danger-emphasis' : 'bg-success-subtle text-success-emphasis'; ?>"><i class="bi bi-calendar-event"></i></span>
								<div class="flex-grow-1 min-w-0">
									<div class="stat-label">Expiring Soon</div>
									<div class="stat-value text-body"><?php echo number_format((int)$supplyStats['expiring-batches']); ?></div>
									<small class="text-body-secondary">batches within 3 days</small>
								</div>
								<i class="bi bi-chevron-right text-body-secondary card-arrow"></i>
							</div>
						</a>
					</div>
				</div>
			<?php endif; ?>

			<div class="card border-0 shadow-sm overflow-hidden">
				<div class="card-header bg-body pb-0">
					<ul class="nav nav-tabs card-header-tabs" aria-label="Supply sections">
						<?php foreach ($views as $view => $details): ?>
							<li class="nav-item">
								<a class="nav-link d-flex align-items-center gap-2 px-3 py-2<?php echo $activeView === $view ? ' active fw-semibold' : ' text-body-secondary'; ?>"
									href="supply.php?view=<?php echo e($view); ?>"
									<?php echo $activeView === $view ? 'aria-current="page"' : ''; ?>>
									<i class="bi <?php echo $viewIcons[$view]; ?>"></i><?php echo e($details['label']); ?>
									<?php if ($view === 'incoming' && $supplyStats !== null && (int)$supplyStats['pending-count'] > 0): ?>
										<span class="badge rounded-pill text-bg-warning"><?php echo number_format((int)$supplyStats['pending-count']); ?></span>
									<?php endif; ?>
								</a>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
				<div class="px-3 px-lg-4 py-2 small text-body-secondary border-bottom bg-body">
					<i class="bi bi-info-circle me-1"></i><?php echo e($viewHints[$activeView]); ?>
				</div>
				<iframe id="supply-frame" class="d-block w-100 border-0 bg-body-tertiary"
					style="height: 70vh; min-height: 32rem;"
					src="<?php echo e($views[$activeView]['file']); ?>"
					title="<?php echo e($views[$activeView]['label']); ?>">
				</iframe>
			</div>
		</div>
	</main>

	<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
	<script>
		// Stretch the section frame to the bottom of the window so the page has one scroll area.
		(() => {
			const frame = document.getElementById('supply-frame');
			const fit = () => {
				const available = window.innerHeight - frame.getBoundingClientRect().top - window.scrollY - 24;
				frame.style.height = Math.max(512, available) + 'px';
			};
			window.addEventListener('resize', fit);
			fit();
		})();
	</script>
</body>
</html>
