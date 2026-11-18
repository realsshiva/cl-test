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

$embedded = ($_GET['embedded'] ?? '') === '1';
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
$totalRevenue = null;
$totalVoidedSales = null;

if ($loadError === '') {
	try {
		$transactionsQuery = $conn->prepare(
			'SELECT t.`tm-TransactionId`, t.`tm-TransactionDate`, t.`tm-InvoiceNo`,
			        t.`tm-GrossPrice`, t.`tm-ScDiscount`, t.`tm-AmountDue`,
			        t.`tm-DiscountMode`, t.`tm-Status`, o.`oi-OrderId`,
			        o.`oi-ProductId`, o.`oi-ProductName`, o.`oi-Quantity`,
			        o.`oi-UnitPrice`, o.`oi-isDiscounted`, o.`oi-LineTotal`
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
					'amountDue' => $row['tm-AmountDue'],
					'discountMode' => $row['tm-DiscountMode'],
					'status' => $row['tm-Status'],
					'quantity' => 0,
					'items' => [],
				];
			}
			if ($row['oi-OrderId'] !== null) {
				$transactions[$transactionId]['quantity'] += (int)$row['oi-Quantity'];
				$transactions[$transactionId]['items'][] = [
					'id' => (int)$row['oi-OrderId'],
					'productId' => (int)$row['oi-ProductId'],
					'name' => $row['oi-ProductName'],
					'quantity' => (int)$row['oi-Quantity'],
					'unitPrice' => $row['oi-UnitPrice'],
					'isDiscounted' => (int)$row['oi-isDiscounted'] === 1,
					'lineTotal' => $row['oi-LineTotal'],
				];
			}
		}
		$transactionsQuery->close();

		$revenueQuery = $conn->prepare(
			'SELECT COALESCE(SUM(`tm-AmountDue`), 0) AS `totalRevenue`
			 FROM `m11.1-transaction_master`
			 WHERE `tm-TransactionDate` >= ?
			   AND `tm-TransactionDate` < ?
			   AND `tm-Status` = ?'
		);
		$completedStatus = 'Completed';
		$revenueQuery->bind_param('sss', $startDateTime, $endDateTime, $completedStatus);
		$revenueQuery->execute();
		$revenueResult = $revenueQuery->get_result()->fetch_assoc();
		$revenueQuery->close();
		if (!$revenueResult || !isset($revenueResult['totalRevenue'])) {
			throw new RuntimeException('The database did not return total revenue.');
		}
		$totalRevenue = (float)$revenueResult['totalRevenue'];

		$voidedSalesQuery = $conn->prepare(
			'SELECT COALESCE(SUM(`vm-LineTotal`), 0) AS `totalVoidedSales`
			 FROM `m12-void_master`
			 WHERE `vm-TransactionDate` >= ?
			   AND `vm-TransactionDate` < ?
			   AND `vm-VoidType` = ?'
		);
		$postCheckoutType = 'Post-Checkout';
		$voidedSalesQuery->bind_param('sss', $startDateTime, $endDateTime, $postCheckoutType);
		$voidedSalesQuery->execute();
		$voidedSalesResult = $voidedSalesQuery->get_result()->fetch_assoc();
		$voidedSalesQuery->close();
		if (!$voidedSalesResult || !isset($voidedSalesResult['totalVoidedSales'])) {
			throw new RuntimeException('The database did not return total voided sales.');
		}
		$totalVoidedSales = (float)$voidedSalesResult['totalVoidedSales'];
	} catch (mysqli_sql_exception $exception) {
		error_log('Transaction history database error: ' . $exception->getMessage());
		http_response_code(500);
		$loadError = 'Transaction history could not be loaded. Please try again.';
	} catch (RuntimeException $exception) {
		error_log('Transaction history query error: ' . $exception->getMessage());
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
		/* Off-screen frame used to print a receipt without leaving the page. */
		.print-frame { position: fixed; top: 0; left: -10000px; width: 58mm; height: 200mm; border: 0; }
		.details-row[hidden] { display: none; }
	</style>
</head>
<body class="bg-body-tertiary">
	<?php if (!$embedded): ?>
		<?php include __DIR__ . '/pos_nav.php'; ?>
	<?php endif; ?>
	<?php
	$filterBase = 'transaction.php?' . ($embedded ? 'embedded=1&' : '');
	$rangeLinks = ['today' => 'Today', 'week' => 'This Week', 'month' => 'This Month'];
	?>

	<main class="<?php echo $embedded ? '' : 'app-main'; ?>">
		<div class="container-fluid py-4 px-3 px-lg-4">
			<div class="mb-4">
				<h1 class="<?php echo $embedded ? 'h5 fw-semibold' : 'page-title'; ?> mb-1">POS Transactions</h1>
				<p class="text-body-secondary mb-0">Counter sales with their items. Reprint receipts or void a completed sale.</p>
			</div>

			<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
				<nav class="nav nav-pills gap-1" aria-label="Filter transactions">
					<?php foreach ($rangeLinks as $rangeKey => $rangeLabel): ?>
						<a class="nav-link py-1 px-3<?php echo $range === $rangeKey ? ' active' : ' border bg-body'; ?>"
							href="<?php echo e($filterBase . 'range=' . $rangeKey); ?>"
							<?php echo $range === $rangeKey ? 'aria-current="page"' : ''; ?>><?php echo e($rangeLabel); ?></a>
					<?php endforeach; ?>
				</nav>
				<form class="d-flex flex-wrap align-items-center gap-2 ms-lg-2" method="get" action="transaction.php">
					<?php if ($embedded): ?><input type="hidden" name="embedded" value="1"><?php endif; ?>
					<input type="hidden" name="range" value="day">
					<label for="transaction-date" class="small text-body-secondary">Date</label>
					<input id="transaction-date" class="form-control form-control-sm w-auto<?php echo $range === 'day' ? ' border-primary' : ''; ?>" name="date" type="date" value="<?php echo e($range === 'day' ? $selectedDate : ''); ?>" required>
					<button class="btn btn-outline-primary btn-sm" type="submit"><i class="bi bi-calendar-event me-1"></i>Show Date</button>
				</form>
			</div>

			<?php if ($dateError !== ''): ?>
				<div class="alert alert-warning py-2" role="alert"><i class="bi bi-exclamation-triangle me-2"></i><?php echo e($dateError); ?></div>
			<?php endif; ?>

			<?php if ($loadError === ''): ?>
				<div class="row row-cols-1 row-cols-sm-3 g-3 mb-4">
					<div class="col">
						<div class="card border-0 shadow-sm h-100">
							<div class="card-body d-flex align-items-center gap-3">
								<span class="icon-tile bg-primary-subtle text-primary-emphasis"><i class="bi bi-receipt"></i></span>
								<div>
									<div class="stat-label">Transactions</div>
									<div class="stat-value"><?php echo number_format(count($transactions)); ?></div>
								</div>
							</div>
						</div>
					</div>
					<div class="col">
						<div class="card border-0 shadow-sm h-100">
							<div class="card-body d-flex align-items-center gap-3">
								<span class="icon-tile bg-success-subtle text-success-emphasis"><i class="bi bi-cash-coin"></i></span>
								<div>
									<div class="stat-label">Total Revenue (Completed)</div>
									<div class="stat-value"><?php echo e(transactionMoney($totalRevenue ?? 0)); ?></div>
								</div>
							</div>
						</div>
					</div>
					<div class="col">
						<div class="card border-0 shadow-sm h-100">
							<div class="card-body d-flex align-items-center gap-3">
								<span class="icon-tile bg-danger-subtle text-danger-emphasis"><i class="bi bi-x-octagon"></i></span>
								<div>
									<div class="stat-label">Total Voided Sales</div>
									<div class="stat-value"><?php echo e(transactionMoney($totalVoidedSales ?? 0)); ?></div>
								</div>
							</div>
						</div>
					</div>
				</div>
			<?php endif; ?>

			<div class="card border-0 shadow-sm">
				<div class="card-header bg-body small text-body-secondary">
					<i class="bi bi-calendar3 me-1"></i>Showing: <span class="fw-semibold text-body"><?php echo e($periodLabel); ?></span>
				</div>
				<?php if ($loadError !== ''): ?>
					<div class="card-body">
						<div class="alert alert-danger mb-0" role="alert"><i class="bi bi-exclamation-octagon me-2"></i><?php echo e($loadError); ?></div>
					</div>
				<?php elseif (!$transactions): ?>
					<div class="card-body text-center text-body-secondary py-5"><i class="bi bi-inbox d-block fs-2 mb-2"></i>No transactions found for this period.</div>
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
									<th scope="col" class="text-end">Total Price</th>
									<th scope="col">Status</th>
									<th scope="col">Action</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ($transactions as $transaction): ?>
									<?php
									$detailsId = 'details-' . $transaction['id'];
									$isVoided = $transaction['status'] === 'Voided Order';
									$statusBadge = $transaction['status'] === 'Completed' ? 'text-bg-success' : ($isVoided ? 'text-bg-danger' : 'text-bg-warning');
									?>
									<tr>
										<td class="text-nowrap"><?php echo e(date('M j, Y g:i A', strtotime((string)$transaction['date']))); ?></td>
										<td class="fw-medium text-nowrap"><?php echo e($transaction['invoice']); ?></td>
										<td class="text-end"><?php echo e($transaction['quantity']); ?></td>
										<td class="text-end text-nowrap"><?php echo e(transactionMoney($transaction['gross'])); ?></td>
										<td class="text-end text-nowrap"><?php echo e(transactionMoney($transaction['discount'])); ?></td>
										<td class="text-end text-nowrap fw-semibold"><?php echo e(transactionMoney($transaction['amountDue'])); ?></td>
										<td><span class="badge <?php echo $statusBadge; ?>"><?php echo e($transaction['status']); ?></span></td>
										<td class="text-nowrap">
											<div class="d-flex gap-1">
												<button class="btn btn-outline-secondary btn-sm" type="button" aria-expanded="false" aria-controls="<?php echo e($detailsId); ?>" data-details-toggle>View Details</button>
												<button class="btn btn-outline-primary btn-sm" type="button" data-print-invoice="<?php echo e($transaction['invoice']); ?>"><i class="bi bi-printer me-1"></i>Print Receipt</button>
												<button class="btn btn-outline-danger btn-sm" type="button" data-void-event="<?php echo e($transaction['id']); ?>" <?php echo $transaction['status'] !== 'Completed' ? 'disabled' : ''; ?>><i class="bi bi-x-octagon me-1"></i>Void Event</button>
											</div>
										</td>
									</tr>
									<tr class="details-row" id="<?php echo e($detailsId); ?>" hidden>
										<td class="bg-body-tertiary p-3" colspan="8">
											<div class="d-flex align-items-center justify-content-between gap-2 mb-2">
												<h2 class="h6 fw-semibold mb-0">Items for <?php echo e($transaction['invoice']); ?></h2>
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
																<td><?php echo e($item['name']); ?></td>
																<td class="text-end"><?php echo e($item['quantity']); ?></td>
																<td><?php echo e($discountLabel); ?></td>
																<td class="text-end text-nowrap"><?php echo e(transactionMoney($item['lineTotal'])); ?></td>
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
				<?php endif; ?>
			</div>
			<p id="print-status" class="small text-body-secondary mt-3 mb-0" role="status" aria-live="polite"></p>
		</div>
	</main>

	<div class="modal fade" id="void-event-dialog" tabindex="-1" aria-labelledby="void-event-heading" aria-hidden="true">
		<div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
			<form class="modal-content" id="void-event-form">
				<div class="modal-header">
					<h2 class="modal-title h5" id="void-event-heading"><i class="bi bi-x-octagon me-2 text-danger"></i>Void Event</h2>
					<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
				</div>
				<div class="modal-body">
					<p class="fw-semibold mb-1" id="void-event-invoice"></p>
					<p class="text-body-secondary small">Allocate every remaining item between restock and waste to void the complete transaction.</p>
					<div class="mb-3">
						<label class="form-label" for="void-event-reason">Reason</label>
						<input id="void-event-reason" class="form-control" type="text" maxlength="100" required>
					</div>
					<div class="d-grid gap-2" id="void-event-items"></div>
					<div class="alert alert-danger py-2 mt-3 mb-0" id="void-event-error" role="alert" hidden></div>
				</div>
				<div class="modal-footer">
					<button class="btn btn-outline-secondary" type="button" data-void-dialog-close>Cancel</button>
					<button class="btn btn-danger" id="void-event-submit" type="submit">Save Void</button>
				</div>
			</form>
		</div>
	</div>

	<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
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
					frame.src = 'receipt.php?invoiceNo=' + encodeURIComponent(invoice);
					document.body.appendChild(frame);
				});
			});

			const voidDialog = document.getElementById('void-event-dialog');
			const voidModal = bootstrap.Modal.getOrCreateInstance(voidDialog);
					const voidItemsContainer = document.getElementById('void-event-items');
					const voidError = document.getElementById('void-event-error');
					let activeVoidTransactionId = null;

					document.querySelectorAll('[data-void-event]').forEach(function (button) {
						button.addEventListener('click', async function () {
							button.disabled = true;
							voidError.hidden = true;
							voidError.textContent = '';
							voidItemsContainer.replaceChildren();
							try {
								const response = await fetch('pvoid.php?transactionId=' + encodeURIComponent(button.dataset.voidEvent));
								const result = await response.json();
								if (!response.ok) throw new Error(result.message || 'Transaction items could not be loaded.');
								activeVoidTransactionId = result.transaction.id;
								document.getElementById('void-event-invoice').textContent =
									'Invoice ' + result.transaction.invoice + ' — enter quantities to restock or record as waste.';
								document.getElementById('void-event-reason').value = '';
								result.items.forEach(function (item) {
									const card = document.createElement('section');
									card.className = 'void-item-card border rounded p-3 bg-body-tertiary';
									card.dataset.itemId = String(item.id);
									card.dataset.remaining = String(item.remainingQuantity);

									const heading = document.createElement('h3');
									heading.textContent = item.name;
									heading.className = 'h6 fw-semibold mb-1';
									const meta = document.createElement('p');
									meta.className = 'void-item-meta small text-body-secondary mb-2';
									meta.textContent = 'Sold: ' + item.quantity + ' · Previously voided: '
										+ item.voidedQuantity + ' · Remaining: ' + item.remainingQuantity
										+ ' · Unit price: ₱' + Number(item.unitPrice).toFixed(2);
									const fields = document.createElement('div');
									fields.className = 'void-quantity-fields row g-2';
									[['Restock quantity', 'restock'], ['Wasted quantity', 'waste']].forEach(function (field) {
										const label = document.createElement('label');
										label.className = 'col-12 col-sm-6 small d-grid gap-1';
										label.textContent = field[0];
										const input = document.createElement('input');
										input.className = 'void-quantity form-control form-control-sm';
										input.type = 'number';
										input.min = '0';
										input.max = String(item.remainingQuantity);
										input.step = '1';
										input.value = '0';
										input.dataset.quantityType = field[1];
										input.setAttribute('aria-label', field[0] + ' for ' + item.name);
										label.appendChild(input);
										fields.appendChild(label);
									});
									card.append(heading, meta, fields);
									voidItemsContainer.appendChild(card);
								});
								if (result.items.length === 0) {
									voidError.textContent = 'This transaction has no items to void.';
									voidError.hidden = false;
								}
								voidModal.show();
							} catch (error) {
								voidError.textContent = error instanceof Error ? error.message : 'Transaction items could not be loaded.';
								voidError.hidden = false;
								voidModal.show();
							} finally {
								button.disabled = false;
							}
						});
					});

					document.querySelector('[data-void-dialog-close]').addEventListener('click', function () {
						voidModal.hide();
					});
					document.getElementById('void-event-form').addEventListener('submit', async function (event) {
						event.preventDefault();
						const reason = document.getElementById('void-event-reason').value.trim();
						const items = [];
						let hasQuantity = false;
						let validationMessage = '';
						voidItemsContainer.querySelectorAll('.void-item-card').forEach(function (card) {
							const restockValue = card.querySelector('[data-quantity-type="restock"]').value;
							const wasteValue = card.querySelector('[data-quantity-type="waste"]').value;
							if (!/^\d+$/.test(restockValue) || !/^\d+$/.test(wasteValue)) {
								validationMessage = 'Enter whole-number quantities for restock and waste.';
							}
							const restock = Number(restockValue);
							const waste = Number(wasteValue);
							const remaining = Number.parseInt(card.dataset.remaining, 10);
							if (!Number.isSafeInteger(restock) || !Number.isSafeInteger(waste)
								|| restock + waste !== remaining) {
								validationMessage = 'Restock and wasted quantities must add up to the remaining quantity for every item.';
							}
							if (restock + waste > 0) {
								hasQuantity = true;
								items.push({itemId: Number(card.dataset.itemId), restock: restock, waste: waste});
							}
						});
						if (reason === '') validationMessage = 'Enter a reason for the void.';
						else if (!hasQuantity && validationMessage === '') validationMessage = 'Enter a restock or wasted quantity for at least one item.';
						if (validationMessage !== '') {
							voidError.textContent = validationMessage;
							voidError.hidden = false;
							return;
						}

						const submitButton = document.getElementById('void-event-submit');
						submitButton.disabled = true;
						voidError.hidden = true;
						try {
							const response = await fetch('pvoid.php', {
								method: 'POST',
								headers: {'Content-Type': 'application/json'},
								body: JSON.stringify({
									action: 'post_checkout',
									transactionId: activeVoidTransactionId,
									reason: reason,
									items: items
								})
							});
							const result = await response.json();
							if (!response.ok) throw new Error(result.message || 'The void could not be saved.');
							voidModal.hide();
							window.location.reload();
						} catch (error) {
							voidError.textContent = error instanceof Error ? error.message : 'The void could not be saved.';
							voidError.hidden = false;
						} finally {
							submitButton.disabled = false;
						}
					});
		}());
	</script>
</body>
</html>
