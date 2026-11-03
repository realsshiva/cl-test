<?php
function e($value): string
{
	return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$activeViewInput = $_GET['view'] ?? 'dispatches';
$activeView = is_string($activeViewInput) ? $activeViewInput : 'dispatches';

$views = [
	'dispatches' => [
		'label' => 'Dispatch Records',
		'file' => 'dprecords.php?embedded=1',
	],
	'waste' => [
		'label' => 'Product Waste',
		'file' => 'pos_waste.php?embedded=1',
	],
];

if (!isset($views[$activeView])) {
	$activeView = 'dispatches';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Records</title>
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
	<link rel="stylesheet" href="/cl-test/invtest/nav.css?v=20261103">
</head>
<body class="bg-body-tertiary">
	<?php include __DIR__ . '/pos_nav.php'; ?>
	<?php
	$viewIcons = ['dispatches' => 'bi-truck', 'waste' => 'bi-trash3'];
	$viewHints = [
		'dispatches' => 'Dispatches the POS has received or cancelled.',
		'waste' => 'Product batches that expired and were removed from stock.',
	];
	?>

	<main class="app-main">
		<div class="container-fluid py-4 px-3 px-lg-4">
			<div class="d-flex flex-wrap align-items-end justify-content-between gap-3 mb-4">
				<div>
					<h1 class="page-title mb-1">Records</h1>
					<p class="text-body-secondary mb-0">Completed dispatches and expired product stock.</p>
				</div>
				<a class="btn btn-outline-secondary btn-sm" href="supply.php"><i class="bi bi-truck me-1"></i>Go to Supply</a>
			</div>

			<div class="card border-0 shadow-sm overflow-hidden">
				<div class="card-header bg-body pb-0">
					<ul class="nav nav-tabs card-header-tabs" aria-label="Record sections">
						<?php foreach ($views as $view => $details): ?>
							<li class="nav-item">
								<a class="nav-link d-flex align-items-center gap-2 px-3 py-2<?php echo $activeView === $view ? ' active fw-semibold' : ' text-body-secondary'; ?>"
									href="records.php?view=<?php echo e($view); ?>"
									<?php echo $activeView === $view ? 'aria-current="page"' : ''; ?>>
									<i class="bi <?php echo $viewIcons[$view]; ?>"></i><?php echo e($details['label']); ?>
								</a>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
				<div class="px-3 px-lg-4 py-2 small text-body-secondary border-bottom bg-body">
					<i class="bi bi-info-circle me-1"></i><?php echo e($viewHints[$activeView]); ?>
				</div>
				<iframe id="records-frame" class="d-block w-100 border-0 bg-body-tertiary"
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
			const frame = document.getElementById('records-frame');
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
