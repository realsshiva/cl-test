<?php
require_once __DIR__ . '/../invtest/db.php';
require_once __DIR__ . '/product_stock_helpers.php';

function e($value): string
{
	return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$productIdInput = $_GET['pti-Id'] ?? '';
$embedded = ($_GET['embedded'] ?? '') === '1';
$productId = is_string($productIdInput)
	? filter_var($productIdInput, FILTER_VALIDATE_INT)
	: false;

if ($productId === false || $productId < 1) {
	http_response_code(400);
	exit('A valid product is required.');
}

$productQuery = $conn->prepare(
	'SELECT p.`pti-Id`, p.`pti-Name`, p.`pti-Variant`, p.`pti-Price`,
	        p.`ptc-Id`, c.`ptc-Category`
	 FROM `m10.1-product_information` p
	 LEFT JOIN `m10-product_category` c ON c.`ptc-Id` = p.`ptc-Id`
	 WHERE p.`pti-Id` = ?
	 LIMIT 1'
);
$productQuery->bind_param('i', $productId);
$productQuery->execute();
$product = $productQuery->get_result()->fetch_assoc();
$productQuery->close();

if (!$product) {
	http_response_code(404);
	exit('Product not found.');
}

try {
	recordExpiredProductStock($conn, (int)$productId);
	$stockQuery = $conn->prepare(
		'SELECT `pts-Id`, `pts-Quantity`, `pts-Expiry`,
		        CASE WHEN `pts-Expiry` <= CURDATE() THEN \'Expired\' ELSE \'In Stock\' END AS `stock-status`
		 FROM `m10.2-product_stock`
		 WHERE `pti-Id` = ?
		 ORDER BY `pts-Expiry` IS NULL, `pts-Expiry` ASC, `pts-Id` DESC'
	);
	$stockQuery->bind_param('i', $productId);
	$stockQuery->execute();
	$stockResult = $stockQuery->get_result();
} catch (mysqli_sql_exception | RuntimeException $exception) {
	http_response_code(500);
	die('Product stock could not be loaded: ' . e($exception->getMessage()));
}

$categoryUrl = $product['ptc-Id'] !== null
	? 'pmaster.php?ptc-Id=' . rawurlencode((string)$product['ptc-Id']) . '&view=inventory' . ($embedded ? '&embedded=1' : '')
	: 'inventory.php' . ($embedded ? '?embedded=1' : '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title><?php echo e($product['pti-Name']); ?> Stock</title>
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
	<link rel="stylesheet" href="/cl-test/invtest/nav.css?v=20261103">
</head>
<body class="bg-body-tertiary">
	<?php if (!$embedded): ?>
		<?php include __DIR__ . '/pos_nav.php'; ?>
	<?php endif; ?>
	<?php
	$stockRows = [];
	$inStockQuantity = 0;
	while ($stock = $stockResult->fetch_assoc()) {
		$stockRows[] = $stock;
		if ($stock['stock-status'] !== 'Expired') {
			$inStockQuantity += (int)($stock['pts-Quantity'] ?? 0);
		}
	}
	$hasVariant = $product['pti-Variant'] !== null && $product['pti-Variant'] !== '';
	?>

	<main class="<?php echo $embedded ? '' : 'app-main'; ?>">
		<div class="container-fluid py-4 px-3 px-lg-4">
			<nav aria-label="breadcrumb">
				<ol class="breadcrumb small mb-2">
					<li class="breadcrumb-item"><a href="pcat.php?view=inventory<?php echo $embedded ? '&amp;embedded=1' : ''; ?>">Inventory</a></li>
					<li class="breadcrumb-item"><a href="<?php echo e($categoryUrl); ?>"><?php echo e($product['ptc-Category'] ?? 'Uncategorized'); ?></a></li>
					<li class="breadcrumb-item active" aria-current="page"><?php echo e($product['pti-Name']); ?></li>
				</ol>
			</nav>

			<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
				<div>
					<h1 class="<?php echo $embedded ? 'h5 fw-semibold' : 'page-title'; ?> mb-1"><?php echo e($product['pti-Name']); ?></h1>
					<?php if ($hasVariant): ?>
						<span class="badge bg-primary-subtle text-primary-emphasis"><?php echo e($product['pti-Variant']); ?></span>
					<?php endif; ?>
				</div>
				<div class="d-flex flex-wrap gap-2">
					<a class="btn btn-outline-secondary btn-sm" href="<?php echo e($categoryUrl); ?>"><i class="bi bi-arrow-left me-1"></i>Back to Inventory</a>
					<a class="btn btn-outline-danger btn-sm" href="pos_waste.php"<?php echo $embedded ? ' target="_top"' : ''; ?>><i class="bi bi-trash3 me-1"></i>Expired Stock</a>
				</div>
			</div>

			<div class="row row-cols-1 row-cols-sm-3 g-3 mb-4">
				<div class="col">
					<div class="card border-0 shadow-sm h-100">
						<div class="card-body d-flex align-items-center gap-3">
							<span class="icon-tile bg-primary-subtle text-primary-emphasis"><i class="bi bi-tags"></i></span>
							<div class="min-w-0">
								<div class="stat-label">Category</div>
								<div class="fw-semibold text-truncate"><?php echo e($product['ptc-Category'] ?? 'Uncategorized'); ?></div>
							</div>
						</div>
					</div>
				</div>
				<div class="col">
					<div class="card border-0 shadow-sm h-100">
						<div class="card-body d-flex align-items-center gap-3">
							<span class="icon-tile bg-success-subtle text-success-emphasis"><i class="bi bi-cash-coin"></i></span>
							<div>
								<div class="stat-label">Price</div>
								<div class="stat-value"><?php echo e(number_format((float)$product['pti-Price'], 2)); ?></div>
							</div>
						</div>
					</div>
				</div>
				<div class="col">
					<div class="card border-0 shadow-sm h-100">
						<div class="card-body d-flex align-items-center gap-3">
							<span class="icon-tile <?php echo $inStockQuantity > 0 ? 'bg-success-subtle text-success-emphasis' : 'bg-danger-subtle text-danger-emphasis'; ?>"><i class="bi bi-box-seam"></i></span>
							<div>
								<div class="stat-label">In Stock</div>
								<div class="stat-value"><?php echo number_format($inStockQuantity); ?></div>
							</div>
						</div>
					</div>
				</div>
			</div>

			<div class="card border-0 shadow-sm">
				<div class="card-header bg-body border-0 pt-3 d-flex align-items-center justify-content-between gap-2">
					<h2 class="h6 fw-semibold mb-0"><i class="bi bi-stack me-2 text-body-secondary"></i>Stock Batches</h2>
					<span class="badge rounded-pill text-bg-light border"><?php echo number_format(count($stockRows)); ?></span>
				</div>
				<div class="table-responsive">
					<table class="table table-hover align-middle mb-0">
						<thead class="table-light"><tr><th>Stock ID</th><th class="text-end">Quantity</th><th>Expiration Date</th><th>Status</th></tr></thead>
						<tbody>
						<?php if (count($stockRows) === 0): ?>
							<tr><td colspan="4" class="text-center text-body-secondary py-5"><i class="bi bi-inbox d-block fs-2 mb-2"></i>No stock batches have been received for this product.</td></tr>
						<?php else: ?>
							<?php foreach ($stockRows as $stock): ?>
								<?php $expired = $stock['stock-status'] === 'Expired'; ?>
								<tr>
									<td><?php echo e($stock['pts-Id']); ?></td>
									<td class="text-end"><?php echo e($stock['pts-Quantity'] ?? 0); ?></td>
									<td class="text-nowrap"><?php echo e($stock['pts-Expiry'] ?? 'Not set'); ?></td>
									<td>
										<span class="badge <?php echo $expired ? 'text-bg-danger' : 'text-bg-success'; ?>">
											<?php echo e($stock['stock-status']); ?>
										</span>
									</td>
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
