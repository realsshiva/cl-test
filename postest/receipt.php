<?php
require_once __DIR__ . '/../invtest/db.php';

function e($value): string
{
	return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$invoiceNoInput = $_GET['invoiceNo'] ?? '';
$invoiceNo = is_string($invoiceNoInput) ? trim($invoiceNoInput) : '';
$transaction = null;
$items = [];
$loadError = '';

if (!preg_match('/^OR-\d{8}-\d{3,}$/', $invoiceNo)) {
	http_response_code(400);
	$loadError = 'A valid invoice number is required to display a receipt.';
} else {
	try {
		$transactionQuery = $conn->prepare(
			'SELECT `tm-TransactionId`, `tm-InvoiceNo`, `tm-TransactionDate`,
			        `tm-GrossPrice`, `tm-ScDiscount`, `tm-AmountDue`,
			        `tm-AmountTendered`, `tm-Change`, `tm-DiscountMode`,
			        `tm-PaymentMode`, `tm-ReferenceNo`,
			        `tm-TotalPax`, `tm-ScCount`
			 FROM `m11.1-transaction_master`
			 WHERE `tm-InvoiceNo` = ?
			 LIMIT 1'
		);
		$transactionQuery->bind_param('s', $invoiceNo);
		$transactionQuery->execute();
		$transaction = $transactionQuery->get_result()->fetch_assoc();
		$transactionQuery->close();

		if (!$transaction) {
			http_response_code(404);
			$loadError = 'No completed transaction was found for this invoice number.';
		} else {
			$transactionId = (int)$transaction['tm-TransactionId'];
			$itemQuery = $conn->prepare(
				'SELECT `oi-ProductName`, `oi-Quantity`, `oi-UnitPrice`,
				        `oi-isDiscounted`, `oi-LineTotal`
				 FROM `m11.2-order_items`
				 WHERE `tm-TransactionId` = ?
				 ORDER BY `oi-OrderId`'
			);
			$itemQuery->bind_param('i', $transactionId);
			$itemQuery->execute();
			$itemResult = $itemQuery->get_result();
			while ($item = $itemResult->fetch_assoc()) {
				$items[] = $item;
			}
			$itemQuery->close();
		}
	} catch (mysqli_sql_exception $exception) {
		error_log('Receipt database error: ' . $exception->getMessage());
		http_response_code(500);
		$loadError = 'The receipt could not be loaded. Please try again.';
	}
}

function receiptMoney($amount): string
{
	return '₱' . number_format((float)$amount, 2);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title><?= $transaction ? 'Receipt ' . e($transaction['tm-InvoiceNo']) : 'Receipt' ?></title>
	<style>
		* { box-sizing: border-box; }
		body {
			margin: 0;
			padding: 24px;
			background: #f0f2f4;
			color: #111;
			font-family: Arial, sans-serif;
		}
		.receipt {
			width: 58mm;
			margin: 0 auto;
			padding: 4mm 2mm;
			background: #fff;
			font-size: 9px;
			line-height: 1.25;
		}
		.receipt-header { margin-bottom: 8px; text-align: center; }
		.receipt-header h1 { margin: 0 0 3px; font-size: 14px; }
		.receipt-header p, .receipt-meta p { margin: 2px 0; }
		.receipt-meta { margin-bottom: 7px; overflow-wrap: anywhere; }
		.receipt-rule { border: 0; border-top: 1px dashed #333; margin: 6px 0; }
		.item { margin: 6px 0; }
		.item-name { margin-bottom: 2px; overflow-wrap: anywhere; font-weight: 700; }
		.item-detail, .total-line {
			display: grid;
			grid-template-columns: minmax(0, 1fr) max-content;
			gap: 3px;
		}
		.item-detail span:last-child, .total-line span:last-child { white-space: nowrap; text-align: right; }
		.total-line span:first-child { min-width: 0; overflow-wrap: anywhere; }
		.item-tag { font-size: 8px; font-weight: 400; }
		.total-line { margin: 3px 0; }
		.total-due { margin-top: 5px; font-size: 10px; font-weight: 700; }
		.receipt-footer { margin-top: 10px; text-align: center; }
		.receipt-footer p { margin: 3px 0; }
		.receipt-error { width: min(420px, 100%); margin: 60px auto; }
		@page { size: 58mm 200mm; margin: 0; }
		@media print {
			html, body { width: 58mm; margin: 0; padding: 0; background: #fff; }
			.receipt {
				width: 58mm;
				margin: 0;
				padding: 1mm 5mm 1mm 0;
				zoom: 0.85;
			}
		}
	</style>
</head>
<body>
	<?php if ($loadError !== ''): ?>
		<main class="receipt-error">
			<h1>Receipt unavailable</h1>
			<p><?= e($loadError) ?></p>
		</main>
	<?php else: ?>
		<main class="receipt">
			<header class="receipt-header">
				<h1>The Dessert Shop by Grace</h1>
				<p>Hidden Garden - Vigan City</p>
                <p>Bulala Centro, Vigan City, Ilocos Sur, 2700</p>
                <p>0917-158-0960</p>
			</header>
			<section class="receipt-meta" aria-label="Transaction details">
				<p><strong>Invoice:</strong> <?= e($transaction['tm-InvoiceNo']) ?></p>
				<p><strong>Date:</strong> <?= e(date('M j, Y g:i A', strtotime((string)$transaction['tm-TransactionDate']))) ?></p>
				<p><strong>Payment:</strong> <?= e($transaction['tm-PaymentMode']) ?></p>
				<?php if (!empty($transaction['tm-ReferenceNo'])): ?>
					<p><strong>Reference No.:</strong> <?= e($transaction['tm-ReferenceNo']) ?></p>
				<?php endif; ?>
			</section>
			<hr class="receipt-rule">
			<section aria-label="Purchased items">
				<?php foreach ($items as $item): ?>
					<div class="item">
						<div class="item-name">
							<?= e($item['oi-ProductName']) ?>
							<?php if ((int)$item['oi-isDiscounted'] === 1): ?>
								<span class="item-tag">(SC discount)</span>
							<?php endif; ?>
						</div>
						<div class="item-detail">
							<span><?= e($item['oi-Quantity']) ?> × <?= e(receiptMoney($item['oi-UnitPrice'])) ?></span>
							<span><?= e(receiptMoney($item['oi-LineTotal'])) ?></span>
						</div>
					</div>
				<?php endforeach; ?>
			</section>
			<hr class="receipt-rule">
			<section aria-label="Payment totals">
				<p class="total-line"><span>Subtotal</span><span><?= e(receiptMoney($transaction['tm-GrossPrice'])) ?></span></p>
				<?php if ((float)$transaction['tm-ScDiscount'] > 0): ?>
					<p class="total-line"><span>SC discount</span><span>-<?= e(receiptMoney($transaction['tm-ScDiscount'])) ?></span></p>
				<?php endif; ?>
				<p class="total-line total-due"><span>Total due</span><span><?= e(receiptMoney($transaction['tm-AmountDue'])) ?></span></p>
				<p class="total-line"><span>Amount tendered</span><span><?= e(receiptMoney($transaction['tm-AmountTendered'])) ?></span></p>
				<?php if ($transaction['tm-Change'] !== null): ?>
					<p class="total-line"><span>Change</span><span><?= e(receiptMoney($transaction['tm-Change'])) ?></span></p>
				<?php endif; ?>
				<?php if ((int)$transaction['tm-ScCount'] > 0): ?>
					<p>SC count: <?= e($transaction['tm-ScCount']) ?><?php if ($transaction['tm-DiscountMode'] === 'Shared Bill'): ?> / Total pax: <?= e($transaction['tm-TotalPax']) ?><?php endif; ?></p>
				<?php endif; ?>
			</section>
			<footer class="receipt-footer">
				<hr class="receipt-rule">
				<p>Thank you, please come again!</p>
                
			</footer>
		</main>
	<?php endif; ?>
	<?php if ($loadError === '' && ($_GET['autoPrint'] ?? '') === '1'): ?>
		<script>
			window.addEventListener('load', function () {
				window.print();
			});
		</script>
	<?php endif; ?>
</body>
</html>
