<?php
require_once __DIR__ . '/../invtest/db.php';

function e($value): string
{
	return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$embedded = ($_GET['embedded'] ?? '') === '1';

try {
	$dispatchRecords = $conn->query(
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
		 ORDER BY dr.`dr-Timestamp` DESC, dr.`dr-Id` DESC'
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

			<div class="card border-0 shadow-sm">
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
						<?php if ($dispatchRecords->num_rows === 0): ?>
							<tr><td colspan="7" class="text-center text-body-secondary py-5"><i class="bi bi-inbox d-block fs-2 mb-2"></i>There are no received or cancelled dispatch records.</td></tr>
						<?php else: ?>
							<?php while ($record = $dispatchRecords->fetch_assoc()): ?>
								<tr>
									<td class="text-nowrap"><?php echo e($record['dr-Timestamp']); ?></td>
									<td class="fw-medium"><?php echo e($record['rec-Name']); ?></td>
									<td><?php echo e($record['recvar-Type'] ?? 'Unknown variant'); ?></td>
									<td class="text-end"><?php echo e($record['dl-QtyToSend']); ?></td>
									<td class="text-end"><?php echo e($record['dr-ReceivedQty']); ?></td>
									<td class="text-nowrap"><?php echo e($record['dr-ExpiryDate']); ?></td>
									<td><span class="badge <?php echo $statusBadges[$record['dr-Status']] ?? 'text-bg-light'; ?>"><?php echo e($record['dr-Status']); ?></span></td>
								</tr>
							<?php endwhile; ?>
						<?php endif; ?>
						</tbody>
					</table>
				</div>
				<?php if ($dispatchRecords->num_rows > 0): ?>
					<div class="card-footer bg-body small text-body-secondary">Showing <?php echo number_format($dispatchRecords->num_rows); ?> records</div>
				<?php endif; ?>
			</div>
		</div>
	</main>

	<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
</body>
</html>
