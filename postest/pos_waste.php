<?php
require_once __DIR__ . '/../invtest/db.php';
require_once __DIR__ . '/product_stock_helpers.php';

function e($value): string
{
	return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$embedded = ($_GET['embedded'] ?? '') === '1';
$error = '';

try {
	recordExpiredProductStock($conn);
} catch (mysqli_sql_exception | RuntimeException $exception) {
	$error = 'Expired stock could not be processed: ' . $exception->getMessage();
}

try {
	$wasteRows = $conn->query(
		'SELECT w.`ptw-Id`, w.`ptw-ExpiredQty`, s.`pts-Expiry`,
		        p.`pti-Name`, p.`pti-Variant`
		 FROM `m10.3-product_waste` w
		 JOIN `m10.2-product_stock` s ON s.`pts-Id` = w.`pts-Id`
		 JOIN `m10.1-product_information` p ON p.`pti-Id` = s.`pti-Id`
		 ORDER BY s.`pts-Expiry` DESC, w.`ptw-Id` DESC'
	);
} catch (mysqli_sql_exception $exception) {
	http_response_code(500);
	die('Product waste could not be loaded: ' . e($exception->getMessage()));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Expired Product Waste</title>
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
	<link rel="stylesheet" href="/cl-test/invtest/nav.css?v=20261103">
</head>
<body class="bg-body-tertiary">
	<?php if (!$embedded): ?>
		<?php include __DIR__ . '/pos_nav.php'; ?>
	<?php endif; ?>
	<?php
	$wasteList = [];
	$totalExpired = 0;
	while ($waste = $wasteRows->fetch_assoc()) {
		$wasteList[] = $waste;
		$totalExpired += (int)$waste['ptw-ExpiredQty'];
	}
	?>

	<main class="<?php echo $embedded ? '' : 'app-main'; ?>">
		<div class="container-fluid py-4 px-3 px-lg-4">
			<div class="mb-4">
				<h1 class="<?php echo $embedded ? 'h5 fw-semibold' : 'page-title'; ?> mb-1">Expired Product Waste</h1>
				<p class="text-body-secondary mb-0">Expired stock batches are moved here automatically and their quantity is set to zero.</p>
			</div>

			<?php if ($error !== ''): ?>
				<div class="alert alert-danger" role="alert"><i class="bi bi-exclamation-triangle me-2"></i><?php echo e($error); ?></div>
			<?php endif; ?>

			<div class="row row-cols-1 row-cols-sm-2 g-3 mb-4">
				<div class="col">
					<div class="card border-0 shadow-sm h-100">
						<div class="card-body d-flex align-items-center gap-3">
							<span class="icon-tile bg-danger-subtle text-danger-emphasis"><i class="bi bi-trash3"></i></span>
							<div>
								<div class="stat-label">Expired Batches</div>
								<div class="stat-value"><?php echo number_format(count($wasteList)); ?></div>
							</div>
						</div>
					</div>
				</div>
				<div class="col">
					<div class="card border-0 shadow-sm h-100">
						<div class="card-body d-flex align-items-center gap-3">
							<span class="icon-tile bg-warning-subtle text-warning-emphasis"><i class="bi bi-box"></i></span>
							<div>
								<div class="stat-label">Total Expired Quantity</div>
								<div class="stat-value"><?php echo number_format($totalExpired); ?></div>
							</div>
						</div>
					</div>
				</div>
			</div>

			<div class="card border-0 shadow-sm">
				<div class="table-responsive">
					<table class="table table-hover align-middle mb-0">
						<thead class="table-light">
							<tr><th>Product Name</th><th>Product Variant</th><th>Expiration Date</th><th class="text-end">Expired Quantity</th></tr>
						</thead>
						<tbody>
						<?php if (count($wasteList) === 0): ?>
							<tr><td colspan="4" class="text-center text-body-secondary py-5"><i class="bi bi-check2-circle d-block fs-2 mb-2"></i>There are no expired product batches recorded as waste.</td></tr>
						<?php else: ?>
							<?php foreach ($wasteList as $waste): ?>
								<tr>
									<td class="fw-medium"><?php echo e($waste['pti-Name']); ?></td>
									<td><?php echo e($waste['pti-Variant'] ?? '—'); ?></td>
									<td class="text-nowrap"><span class="badge bg-danger-subtle text-danger-emphasis"><i class="bi bi-calendar-x me-1"></i><?php echo e($waste['pts-Expiry']); ?></span></td>
									<td class="text-end"><?php echo e($waste['ptw-ExpiredQty']); ?></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
						</tbody>
					</table>
				</div>
			</div>
		</div>
	</main>

	<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
</body>
</html>
