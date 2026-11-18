<?php
require_once __DIR__ . '/../invtest/db.php';

function e($value): string
{
	return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function transactionMoney($amount): string
{
	return '₱' . number_format((float)$amount, 2);
}

$rangeInput = $_GET['range'] ?? 'today';
$range = is_string($rangeInput) ? $rangeInput : 'today';
$dateInput = $_GET['date'] ?? '';
$selectedDate = is_string($dateInput) ? $dateInput : '';
$loadError = '';
$today = new DateTimeImmutable('today');

try {
	$databaseDateResult = $conn->query('SELECT CURDATE() AS `today`');
	$databaseDate = $databaseDateResult->fetch_assoc();
	if (!$databaseDate || !isset($databaseDate['today'])) {
		throw new RuntimeException('The database did not return its current date.');
	}
	$today = new DateTimeImmutable((string)$databaseDate['today']);
} catch (mysqli_sql_exception | RuntimeException $exception) {
	error_log('Transaction history date lookup error: ' . $exception->getMessage());
	http_response_code(500);
	$loadError = 'Transaction history could not be loaded. Please try again.';
}

$dateError = '';

if ($range === 'day' && $selectedDate !== '') {
	$parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $selectedDate);
	$dateProblems = DateTimeImmutable::getLastErrors();
	if (!$parsedDate || ($dateProblems !== false && ($dateProblems['warning_count'] > 0 || $dateProblems['error_count'] > 0))
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

$transactions = [];

if ($loadError === '') {
	try {
		$transactionsQuery = $conn->prepare(
			'SELECT t.`tm-TransactionId`, t.`tm-TransactionDate`, t.`tm-InvoiceNo`,
			        t.`tm-GrossPrice`, t.`tm-ScDiscount`, t.`tm-AmountTendered`,
			        t.`tm-DiscountMode`, o.`oi-OrderId`, o.`oi-ProductName`,
			        o.`oi-Quantity`, o.`oi-isDiscounted`, o.`oi-LineTotal`
			 FROM `m11.1-transaction_master` t
			 LEFT JOIN `m11.2-order_items` o
			   ON o.`tm-TransactionId` = t.`tm-TransactionId`
			 WHERE t.`tm-TransactionDate` >= ?
			   AND t.`tm-TransactionDate` < ?
			 ORDER BY t.`tm-TransactionDate` DESC, t.`tm-TransactionId` DESC,
			          o.`oi-OrderId` ASC'
		);
		$startDateTime = $startDate->format('Y-m-d 00:00:00');
		$endDateTime = $endDate->format('Y-m-d 00:00:00');
		$transactionsQuery->bind_param('ss', $startDateTime, $endDateTime);
		$transactionsQuery->execute();
		$result = $transactionsQuery->get_result();

		while ($row = $result->fetch_assoc()) {
			$transactionId = (int)$row['tm-TransactionId'];
			if (!isset($transactions[$transactionId])) {
				$transactions[$transactionId] = [
					'id' => $transactionId,
					'date' => $row['tm-TransactionDate'],
					'invoice' => $row['tm-InvoiceNo'],
					'gross' => $row['tm-GrossPrice'],
					'discount' => $row['tm-ScDiscount'],
					'paid' => $row['tm-AmountTendered'],
					'discountMode' => $row['tm-DiscountMode'],
					'quantity' => 0,
					'items' => [],
				];
			}
			if ($row['oi-OrderId'] !== null) {
				$transactions[$transactionId]['quantity'] += (int)$row['oi-Quantity'];
				$transactions[$transactionId]['items'][] = [
					'name' => $row['oi-ProductName'],
					'quantity' => (int)$row['oi-Quantity'],
					'isDiscounted' => (int)$row['oi-isDiscounted'] === 1,
					'lineTotal' => $row['oi-LineTotal'],
				];
			}
		}
		$transactionsQuery->close();
	} catch (mysqli_sql_exception $exception) {
		error_log('Transaction history database error: ' . $exception->getMessage());
		http_response_code(500);
		$loadError = 'Transaction history could not be loaded. Please try again.';
	}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>POS Transactions</title>
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
	<link rel="stylesheet" href="/cl-test/invtest/nav.css?v=20261103">
	<style>
		/* Off-screen frame that loads a receipt for printing. */
		.print-frame { position: fixed; top: 0; left: -10000px; width: 58mm; height: 200mm; border: 0; }
	</style>
</head>
<body class="bg-body-tertiary">
	<?php
	// This page is shown inside the Transaction History frame (thistory.php).
	$rangeLinks = ['today' => 'Today', 'week' => 'This Week', 'month' => 'This Month'];
	?>
	<main>
		<div class="container-fluid py-4 px-3 px-lg-4">
			<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
				<div>
					<h1 class="h5 fw-semibold mb-1">PoS-Retail Transactions</h1>
					<p class="text-body-secondary mb-0">Showing: <span class="fw-medium text-body"><?= e($periodLabel) ?></span></p>
				</div>
			</div>

			<div class="card border-0 shadow-sm mb-3">
				<div class="card-body d-flex flex-wrap align-items-center gap-3">
					<nav class="btn-group" role="group" aria-label="Filter transactions">
						<?php foreach ($rangeLinks as $rangeValue => $rangeLabel): ?>
							<a class="btn btn-sm <?= $range === $rangeValue ? 'btn-primary' : 'btn-outline-secondary' ?>"
								href="tretail.php?range=<?= e($rangeValue) ?>" <?= $range === $rangeValue ? 'aria-current="page"' : '' ?>><?= e($rangeLabel) ?></a>
						<?php endforeach; ?>
					</nav>
					<form class="d-flex flex-wrap align-items-center gap-2" method="get" action="tretail.php">
						<input type="hidden" name="range" value="day">
						<label for="transaction-date" class="form-label small text-body-secondary mb-0">Date</label>
						<input id="transaction-date" class="form-control form-control-sm w-auto" name="date" type="date" value="<?= e($range === 'day' ? $selectedDate : '') ?>" required>
						<button type="submit" class="btn btn-sm <?= $range === 'day' ? 'btn-primary' : 'btn-outline-primary' ?>"><i class="bi bi-calendar-event me-1"></i>Show Date</button>
					</form>
				</div>
			</div>

			<?php if ($dateError !== ''): ?>
				<div class="alert alert-warning py-2" role="alert"><i class="bi bi-exclamation-triangle me-2"></i><?= e($dateError) ?></div>
			<?php endif; ?>

			<div class="card border-0 shadow-sm">
				<?php if ($loadError !== ''): ?>
					<div class="alert alert-danger m-3 mb-3" role="alert"><i class="bi bi-exclamation-octagon me-2"></i><?= e($loadError) ?></div>
				<?php elseif (!$transactions): ?>
					<div class="text-center text-body-secondary py-5"><i class="bi bi-inbox d-block fs-2 mb-2"></i>No transactions found for this period.</div>
				<?php else: ?>
					<div class="table-responsive">
						<table class="table table-hover align-middle mb-0">
							<thead class="table-light">
								<tr>
									<th scope="col">Date</th>
									<th scope="col">Invoice</th>
									<th scope="col" class="text-end">Order Qty</th>
									<th scope="col" class="text-end">Gross Price</th>
									<th scope="col" class="text-end">Discount</th>
									<th scope="col" class="text-end">Amount Paid</th>
									<th scope="col">Action</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ($transactions as $transaction): ?>
									<?php $detailsId = 'details-' . $transaction['id']; ?>
									<tr>
										<td class="text-nowrap"><?= e(date('M j, Y g:i A', strtotime((string)$transaction['date']))) ?></td>
										<td class="fw-medium"><?= e($transaction['invoice']) ?></td>
										<td class="text-end"><?= e($transaction['quantity']) ?></td>
										<td class="text-end text-nowrap"><?= e(transactionMoney($transaction['gross'])) ?></td>
										<td class="text-end text-nowrap"><?= e(transactionMoney($transaction['discount'])) ?></td>
										<td class="text-end text-nowrap fw-semibold"><?= e(transactionMoney($transaction['paid'])) ?></td>
										<td class="text-nowrap">
											<button class="btn btn-outline-primary btn-sm" type="button" aria-expanded="false" aria-controls="<?= e($detailsId) ?>" data-details-toggle>View Details</button>
											<button class="btn btn-outline-secondary btn-sm ms-1" type="button" data-print-invoice="<?= e($transaction['invoice']) ?>"><i class="bi bi-printer me-1"></i>Print Receipt</button>
										</td>
									</tr>
									<tr class="details-row" id="<?= e($detailsId) ?>" hidden>
										<td class="bg-body-tertiary p-3" colspan="7">
											<div class="d-flex align-items-center justify-content-between gap-3 mb-2">
												<h2 class="h6 fw-semibold mb-0">Items for <?= e($transaction['invoice']) ?></h2>
												<button class="btn btn-outline-secondary btn-sm" type="button" data-details-hide>Hide</button>
											</div>
											<div class="table-responsive border rounded bg-body">
												<table class="table table-sm align-middle mb-0">
													<thead class="table-light">
														<tr>
															<th scope="col">Product Name</th>
															<th scope="col" class="text-end">Qty</th>
															<th scope="col">Discount</th>
															<th scope="col" class="text-end">Line Total</th>
														</tr>
													</thead>
													<tbody>
														<?php foreach ($transaction['items'] as $item): ?>
															<?php
																$discountLabel = 'None';
																if ($item['isDiscounted']) {
																	$discountLabel = $transaction['discountMode'] === 'Shared Bill'
																		? 'Shared'
																		: (string)$item['quantity'];
																}
															?>
															<tr>
																<td><?= e($item['name']) ?></td>
																<td class="text-end"><?= e($item['quantity']) ?></td>
																<td><?php if ($item['isDiscounted']): ?><span class="badge text-bg-info"><?= e($discountLabel) ?></span><?php else: ?><span class="text-body-secondary"><?= e($discountLabel) ?></span><?php endif; ?></td>
																<td class="text-end text-nowrap"><?= e(transactionMoney($item['lineTotal'])) ?></td>
															</tr>
														<?php endforeach; ?>
													</tbody>
												</table>
											</div>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
					<div class="card-footer bg-body small text-body-secondary">Showing <?= e(number_format(count($transactions))) ?> transactions</div>
				<?php endif; ?>
			</div>
			<p id="print-status" class="small text-body-secondary mt-2 mb-0" role="status" aria-live="polite"></p>
		</div>
	</main>
	<script>
		(function () {
			'use strict';

			document.querySelectorAll('[data-details-toggle]').forEach(function (button) {
				button.addEventListener('click', function () {
					const details = document.getElementById(button.getAttribute('aria-controls'));
					const expanded = button.getAttribute('aria-expanded') === 'true';
					details.hidden = expanded;
					button.setAttribute('aria-expanded', expanded ? 'false' : 'true');
					button.textContent = expanded ? 'View Details' : 'Hide Details';
				});
			});

			document.querySelectorAll('[data-details-hide]').forEach(function (button) {
				button.addEventListener('click', function () {
					const details = button.closest('.details-row');
					details.hidden = true;
					const toggle = document.querySelector('[aria-controls="' + details.id + '"]');
					toggle.setAttribute('aria-expanded', 'false');
					toggle.textContent = 'View Details';
				});
			});

			document.querySelectorAll('[data-print-invoice]').forEach(function (button) {
				button.addEventListener('click', function () {
					const invoice = button.dataset.printInvoice;
					const frame = document.createElement('iframe');
					const status = document.getElementById('print-status');
					frame.className = 'print-frame';
					frame.title = 'Receipt print frame';
					frame.setAttribute('aria-hidden', 'true');
					frame.tabIndex = -1;

					let removed = false;
					function removeFrame() {
						if (removed) return;
						removed = true;
						window.clearTimeout(cleanupTimer);
						frame.remove();
						button.disabled = false;
					}
					const cleanupTimer = window.setTimeout(removeFrame, 120000);
					button.disabled = true;
					status.textContent = 'Preparing receipt ' + invoice + '...';
					frame.addEventListener('load', function () {
						const receiptWindow = frame.contentWindow;
						if (!receiptWindow || !receiptWindow.document.querySelector('.receipt')) {
							removeFrame();
							status.textContent = 'Receipt could not be prepared for printing.';
							return;
						}
						receiptWindow.addEventListener('afterprint', function () {
							removeFrame();
							status.textContent = 'Print dialog closed for receipt ' + invoice + '.';
						}, {once: true});
						try {
							receiptWindow.focus();
							receiptWindow.print();
						} catch (error) {
							removeFrame();
							status.textContent = 'Printing failed: '
								+ (error instanceof Error ? error.message : 'check browser print settings.');
						}
					});
					frame.src = '../postest/receipt.php?invoiceNo=' + encodeURIComponent(invoice);
					document.body.appendChild(frame);
				});
			});
		}());
	</script>
</body>
</html>
