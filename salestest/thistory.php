<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Transaction History</title>
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
	<link rel="stylesheet" href="/cl-test/invtest/nav.css?v=20261103">
</head>
<body class="bg-body-tertiary">
	<?php include __DIR__ . '/snav.php'; ?>

	<main class="app-main">
		<div class="container-fluid py-4 px-3 px-lg-4">
			<div class="d-flex flex-wrap align-items-end justify-content-between gap-3 mb-4">
				<div>
					<h1 class="page-title mb-1">Transaction History</h1>
					<p class="text-body-secondary mb-0">Point of sale transactions by channel.</p>
				</div>
				<a class="btn btn-outline-secondary btn-sm" href="sreport.php"><i class="bi bi-bar-chart-line me-1"></i>Sales Report</a>
			</div>

			<div class="card border-0 shadow-sm overflow-hidden">
				<div class="card-header bg-body pb-0">
					<ul class="nav nav-tabs card-header-tabs channel-nav" aria-label="Point of sale transaction history">
						<li class="nav-item">
							<a class="nav-link d-flex align-items-center gap-2 px-3 py-2 active fw-semibold" href="tretail.php" target="channel-content" aria-current="page"><i class="bi bi-shop"></i>PoS-Retail</a>
						</li>
						<li class="nav-item">
							<a class="nav-link d-flex align-items-center gap-2 px-3 py-2 text-body-secondary" href="tkiosk.php" target="channel-content"><i class="bi bi-display"></i>PoS-Kiosk</a>
						</li>
						<li class="nav-item">
							<a class="nav-link d-flex align-items-center gap-2 px-3 py-2 text-body-secondary" href="tb2b.php" target="channel-content"><i class="bi bi-building"></i>PoS-Wholesale</a>
						</li>
					</ul>
				</div>
				<iframe id="channel-frame" name="channel-content" class="d-block w-100 border-0 bg-body-tertiary"
					style="height: 75vh; min-height: 32rem;"
					title="Selected point of sale transaction history" src="tretail.php"></iframe>
			</div>
		</div>
	</main>

	<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
	<script>
		document.querySelectorAll('.channel-nav a').forEach(function (link) {
			link.addEventListener('click', function () {
				document.querySelectorAll('.channel-nav a').forEach(function (channelLink) {
					channelLink.removeAttribute('aria-current');
					channelLink.classList.remove('active', 'fw-semibold');
					channelLink.classList.add('text-body-secondary');
				});
				link.setAttribute('aria-current', 'page');
				link.classList.add('active', 'fw-semibold');
				link.classList.remove('text-body-secondary');
			});
		});

		// Stretch the channel frame to the bottom of the window so the page has one scroll area.
		(function () {
			const frame = document.getElementById('channel-frame');
			const fit = function () {
				const available = window.innerHeight - frame.getBoundingClientRect().top - window.scrollY - 24;
				frame.style.height = Math.max(512, available) + 'px';
			};
			window.addEventListener('resize', fit);
			fit();
		}());
	</script>
</body>
</html>
