<?php
require_once __DIR__ . '/../invtest/db.php';
require_once __DIR__ . '/product_stock_helpers.php';

function e($value): string
{
	return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$categories = [];
$products = [];

try {
	recordExpiredProductStock($conn);

	$categoryResult = $conn->query(
		'SELECT `ptc-Id`, `ptc-Category`
		 FROM `m10-product_category`
		 ORDER BY `ptc-Category`'
	);
	while ($category = $categoryResult->fetch_assoc()) {
		$categories[] = $category;
	}

	$productResult = $conn->query(
		'SELECT p.`pti-Id`, p.`pti-Photo`, p.`pti-Name`, p.`pti-Variant`,
		        p.`pti-Price`, p.`ptc-Id`, c.`ptc-Category`,
		        COALESCE(SUM(s.`pts-Quantity`), 0) AS `stock-quantity`
		 FROM `m10.1-product_information` p
		 LEFT JOIN `m10-product_category` c ON c.`ptc-Id` = p.`ptc-Id`
		 LEFT JOIN `m10.2-product_stock` s
		   ON s.`pti-Id` = p.`pti-Id`
		  AND (s.`pts-Expiry` IS NULL OR s.`pts-Expiry` > CURDATE())
		  AND s.`pts-Quantity` > 0
		 GROUP BY p.`pti-Id`, p.`pti-Photo`, p.`pti-Name`, p.`pti-Variant`,
		          p.`pti-Price`, p.`ptc-Id`, c.`ptc-Category`
		 ORDER BY p.`pti-Name`, p.`pti-Variant`'
	);
	while ($product = $productResult->fetch_assoc()) {
		$products[] = $product;
	}

	$invoiceDayResult = $conn->query("SELECT DATE_FORMAT(CURDATE(), '%Y%m%d') AS invoice_day");
	$invoiceDay = (string)$invoiceDayResult->fetch_assoc()['invoice_day'];
	$sequenceQuery = $conn->prepare(
		"SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(`tm-InvoiceNo`, '-', -1) AS UNSIGNED)), 0) AS last_sequence
		 FROM `m11.1-transaction_master`
		 WHERE `tm-InvoiceNo` LIKE CONCAT('OR-', ?, '-%')"
	);
	$sequenceQuery->bind_param('s', $invoiceDay);
	$sequenceQuery->execute();
	$nextInvoiceSequence = (int)$sequenceQuery->get_result()->fetch_assoc()['last_sequence'] + 1;
	$sequenceQuery->close();
} catch (mysqli_sql_exception | RuntimeException $exception) {
	http_response_code(500);
	die('Counter data could not be loaded: ' . e($exception->getMessage()));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Counter</title>
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
	<link rel="stylesheet" href="/cl-test/invtest/nav.css?v=20261103">
	<style>
		/* Counter-only styles. Everything is scoped to .counter-page or the counter dialogs. */
		.counter-page .product-grid {
			display: grid;
			grid-template-columns: repeat(auto-fill, minmax(165px, 1fr));
			gap: .875rem;
		}
		.counter-page .product-card {
			display: flex;
			min-width: 0;
			min-height: 250px;
			flex-direction: column;
			overflow: hidden;
			padding: 0;
			border: 1px solid var(--bs-border-color);
			border-radius: var(--bs-border-radius-lg);
			background: var(--bs-body-bg);
			color: inherit;
			text-align: left;
			transition: border-color .15s, box-shadow .15s, transform .15s;
		}
		.counter-page .product-card:hover:not(:disabled),
		.counter-page .product-card:focus-visible:not(:disabled) {
			transform: translateY(-2px);
			border-color: var(--bs-primary);
			box-shadow: var(--bs-box-shadow-sm);
		}
		.counter-page .product-card:disabled {
			background: var(--bs-secondary-bg);
			color: var(--bs-secondary-color);
			cursor: not-allowed;
			filter: grayscale(1);
		}
		.counter-page .product-photo,
		.counter-page .product-photo-empty {
			display: grid;
			width: 100%;
			height: 128px;
			flex: 0 0 128px;
			place-items: center;
			object-fit: cover;
			background: var(--bs-tertiary-bg);
			color: var(--bs-secondary-color);
		}
		.counter-page .product-details {
			display: flex;
			flex: 1;
			flex-direction: column;
			padding: .75rem;
		}
		.counter-page .product-stock { margin-top: auto; padding-top: .75rem; }
		.counter-page .product-card:disabled .product-stock { font-weight: 700; }
		.counter-page .category-button[aria-pressed="true"] {
			--bs-btn-color: #fff;
			--bs-btn-bg: var(--bs-primary);
			--bs-btn-border-color: var(--bs-primary);
			--bs-btn-hover-color: #fff;
			--bs-btn-hover-bg: var(--bs-primary);
			--bs-btn-hover-border-color: var(--bs-primary);
		}
		.counter-page .cart-items {
			display: grid;
			gap: .75rem;
			max-height: min(52vh, 570px);
			overflow: auto;
			padding-right: 2px;
		}
		.counter-page .cart-row {
			overflow: hidden;
			border: 1px solid var(--bs-border-color);
			border-radius: var(--bs-border-radius);
			background: var(--bs-body-bg);
		}
		.counter-page .cart-row-actions {
			display: flex;
			justify-content: flex-end;
			gap: .375rem;
			padding: .5rem .625rem;
			border-bottom: 1px solid var(--bs-border-color-translucent);
			background: var(--bs-tertiary-bg);
		}
		.counter-page .cart-row-content {
			display: grid;
			grid-template-columns: minmax(0, 1fr) auto;
			gap: .5rem .75rem;
			padding: .75rem;
		}
		.counter-page .cart-product-name { grid-column: 1; margin: 0; overflow-wrap: anywhere; font-weight: 600; }
		.counter-page .quantity-control { display: flex; grid-column: 2; grid-row: 1; align-items: center; gap: .3rem; }
		.counter-page .quantity-control .btn { width: 2rem; padding-left: 0; padding-right: 0; }
		.counter-page .quantity-input { width: 3.25rem; text-align: center; }
		.counter-page .cart-price, .counter-page .cart-total { margin: 0; font-size: .9rem; }
		.counter-page .cart-price { grid-column: 1; color: var(--bs-secondary-color); }
		.counter-page .cart-total { grid-column: 2; text-align: right; font-weight: 700; }
		.counter-page .discount-summary { grid-column: 1 / -1; margin: 0; color: var(--bs-success-text-emphasis); font-size: .85rem; }
		.billing-line { display: flex; justify-content: space-between; gap: .875rem; margin: .5rem 0; }
		.billing-line span:last-child { white-space: nowrap; font-weight: 600; }
		.transaction-status.error {
			--bs-alert-color: var(--bs-danger-text-emphasis);
			--bs-alert-bg: var(--bs-danger-bg-subtle);
			--bs-alert-border-color: var(--bs-danger-border-subtle);
		}
		.transaction-status a { color: inherit; font-weight: 700; }
		@media (min-width: 1200px) {
			.counter-page .cart-panel { position: sticky; top: 1rem; }
		}

		/* Native <dialog> elements styled to match Bootstrap modals (JS uses showModal/close). */
		.counter-dialog {
			width: min(460px, calc(100% - 2rem));
			padding: 0;
			border: 0;
			border-radius: var(--bs-border-radius-lg);
			background: var(--bs-body-bg);
			color: var(--bs-body-color);
			box-shadow: var(--bs-box-shadow-lg);
		}
		.counter-dialog::backdrop { background: rgba(0, 0, 0, .5); }
		#checkout-dialog { width: min(640px, calc(100% - 2rem)); }
		.counter-dialog-header { padding: 1rem 1.5rem; border-bottom: 1px solid var(--bs-border-color); }
		.counter-dialog-body { padding: 1.25rem 1.5rem; }
		.counter-dialog-footer {
			display: flex;
			flex-wrap: wrap;
			justify-content: flex-end;
			gap: .5rem;
			padding: .875rem 1.5rem;
			border-top: 1px solid var(--bs-border-color);
		}
		.checkout-entry { display: grid; grid-template-columns: minmax(0, 1fr) 180px; gap: 1.125rem; align-items: start; }
		.tender-input-wrap { position: relative; }
		.tender-input-wrap .tender-input { padding-right: 2.75rem; }
		.tender-clear {
			position: absolute;
			top: 50%;
			right: .4rem;
			width: 2rem;
			height: 2rem;
			transform: translateY(-50%);
			border: 0;
			border-radius: 50%;
			background: transparent;
			color: var(--bs-secondary-color);
			font-size: 1.2rem;
			line-height: 1;
		}
		.tender-clear:hover { background: var(--bs-secondary-bg); }
		.checkout-keypad { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: .4rem; padding-top: 2rem; }
		.checkout-keypad .btn { min-height: 46px; font-size: 1.1rem; }
		.change-value.insufficient { color: var(--bs-danger); }
		.sc-credentials { display: grid; gap: .625rem; }
		.sc-credential {
			display: grid;
			grid-template-columns: minmax(0, .8fr) minmax(0, 1.2fr);
			gap: .5rem;
			padding: .625rem;
			border: 1px solid var(--bs-border-color);
			border-radius: var(--bs-border-radius);
		}
		@media (max-width: 575.98px) {
			.counter-page .product-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .625rem; }
			.counter-page .product-card { min-height: 230px; }
			.counter-page .product-photo, .counter-page .product-photo-empty { height: 108px; flex-basis: 108px; }
			.checkout-entry { grid-template-columns: minmax(0, 1fr) 140px; gap: .625rem; }
		}
	</style>
</head>
<body class="bg-body-tertiary">
	<?php include __DIR__ . '/pos_nav.php'; ?>

	<main class="app-main">
		<div class="container-fluid py-4 px-3 px-lg-4 counter-page">
			<div class="d-flex flex-wrap align-items-end justify-content-between gap-3 mb-4">
				<div>
					<h1 class="page-title mb-1">Counter</h1>
					<p class="text-body-secondary mb-0">Ring up sales at the shop counter.</p>
				</div>
			</div>

			<p class="alert alert-success transaction-status mb-4" id="transaction-status" role="status" hidden></p>

			<div class="row g-4 align-items-start">
				<section class="col-12 col-xl-7" aria-labelledby="products-heading">
					<div class="card border-0 shadow-sm">
						<div class="card-body">
							<h2 class="h5 mb-3" id="products-heading"><i class="bi bi-grid me-2 text-primary"></i>Products</h2>
							<div class="mb-3">
								<div class="input-group mb-3">
									<span class="input-group-text bg-body"><i class="bi bi-search"></i></span>
									<input id="product-search" class="form-control" type="search" placeholder="Search products..." aria-label="Search products">
								</div>
								<div class="d-flex flex-wrap gap-2" id="category-buttons" role="group" aria-label="Filter products by category">
									<button class="btn btn-sm btn-outline-primary rounded-pill category-button" type="button" data-category-id="all" aria-pressed="true">All Category</button>
									<?php foreach ($categories as $category): ?>
										<button class="btn btn-sm btn-outline-primary rounded-pill category-button" type="button" data-category-id="<?= e($category['ptc-Id']) ?>" aria-pressed="false"><?= e($category['ptc-Category']) ?></button>
									<?php endforeach; ?>
								</div>
							</div>
							<div class="product-grid" id="product-grid">
								<?php foreach ($products as $product):
									$stockQuantity = (int)$product['stock-quantity'];
									$productName = (string)$product['pti-Name'];
									$productVariant = (string)($product['pti-Variant'] ?? '');
									$productLabel = $productVariant !== '' ? $productName . ' - ' . $productVariant : $productName;
									$photo = trim((string)($product['pti-Photo'] ?? ''));
								?>
									<button class="product-card"
										type="button"
										data-product-id="<?= e($product['pti-Id']) ?>"
										data-product-name="<?= e($productLabel) ?>"
										data-product-price="<?= e($product['pti-Price']) ?>"
										data-product-stock="<?= e($stockQuantity) ?>"
										data-category-id="<?= e($product['ptc-Id'] ?? '') ?>"
										data-search-text="<?= e($productLabel . ' ' . ($product['ptc-Category'] ?? '')) ?>"
										<?= $stockQuantity < 1 ? 'disabled' : '' ?>>
										<?php if ($photo !== ''): ?>
											<img class="product-photo" src="<?= e($photo) ?>" alt="">
										<?php else: ?>
											<div class="product-photo-empty" aria-hidden="true"><i class="bi bi-image fs-2"></i></div>
										<?php endif; ?>
										<span class="product-details">
											<strong class="product-name"><?= e($productName) ?></strong>
											<?php if ($productVariant !== ''): ?>
												<span class="product-variant small text-body-secondary"><?= e($productVariant) ?></span>
											<?php endif; ?>
											<span class="product-price fw-bold mt-1">₱<?= e(number_format((float)$product['pti-Price'], 2)) ?></span>
											<span class="product-stock small text-body-secondary text-center"><?= $stockQuantity > 0 ? e($stockQuantity) . ' Stock Left' : 'No Stock' ?></span>
										</span>
									</button>
								<?php endforeach; ?>
								<p class="text-center text-body-secondary py-4 mb-0" style="grid-column: 1 / -1;" id="no-products" <?= $products ? 'hidden' : '' ?>>
									<?= $products ? 'No matching products found.' : 'No products are available.' ?>
								</p>
							</div>
						</div>
					</div>
				</section>

				<section class="col-12 col-xl-5 cart-panel" aria-labelledby="cart-heading">
					<div class="card border-0 shadow-sm">
						<div class="card-body">
							<h2 class="h5 mb-3" id="cart-heading"><i class="bi bi-cart3 me-2 text-primary"></i>Cart</h2>
							<div class="cart-items" id="cart-items" aria-live="polite">
								<p class="text-center text-body-secondary py-4 mb-0" id="cart-empty">Your cart is empty.</p>
							</div>
							<div class="mt-3 pt-3 border-top border-2">
								<button class="btn btn-outline-secondary w-100 mb-3" id="open-shared-discount" type="button" disabled>Apply SC Discount (Shared)</button>
								<p class="billing-line"><span>Sub-Total:</span><span id="subtotal">₱0.00</span></p>
								<p class="billing-line"><span>SC Discount &amp; VAT Exemption:</span><span id="discount-total">- ₱0.00</span></p>
								<hr class="border-secondary" style="border-top-style: dashed;">
								<p class="billing-line fs-5 fw-bold"><span>Total Amount Due:</span><span id="amount-due">₱0.00</span></p>
								<div class="row g-2 mt-2">
									<div class="col-6">
										<button class="btn btn-outline-danger w-100" id="void-cart" type="button" disabled><i class="bi bi-x-circle me-1"></i>Void Cart</button>
									</div>
									<div class="col-6">
										<button class="btn btn-primary w-100" id="open-checkout" type="button" disabled><i class="bi bi-cash-coin me-1"></i>Checkout</button>
									</div>
								</div>
							</div>
						</div>
					</div>
				</section>
			</div>
		</div>
	</main>

	<dialog class="counter-dialog" id="void-dialog" aria-labelledby="void-dialog-heading">
		<div class="counter-dialog-header">
			<h2 class="h5 mb-0" id="void-dialog-heading"><i class="bi bi-x-octagon text-danger me-2"></i>Void Cart?</h2>
		</div>
		<div class="counter-dialog-body">
			<p class="mb-0">Record the cart items as pre-checkout voids and remove them from this cart?</p>
			<p class="alert alert-danger py-2 mt-3 mb-0" id="void-dialog-error" role="alert" hidden></p>
		</div>
		<div class="counter-dialog-footer">
			<button class="btn btn-outline-secondary" type="button" data-close-dialog="void-dialog">Cancel</button>
			<button class="btn btn-danger" id="confirm-void-cart" type="button">Void Cart</button>
		</div>
	</dialog>

	<dialog class="counter-dialog" id="checkout-dialog" aria-labelledby="checkout-heading">
		<div class="counter-dialog-header">
			<h2 class="h5 mb-0" id="checkout-heading"><i class="bi bi-cash-coin text-primary me-2"></i>Checkout</h2>
		</div>
		<div class="counter-dialog-body">
			<p class="billing-line fs-5 mt-0"><span>Amount Due:</span><strong id="checkout-due">₱0.00</strong></p>
			<div class="d-flex flex-wrap gap-2 mb-3" role="group" aria-label="Payment mode">
				<button class="btn btn-primary payment-mode" type="button" data-payment-mode="Cash" aria-pressed="true"><i class="bi bi-cash me-1"></i>Cash</button>
				<button class="btn btn-outline-primary payment-mode" type="button" data-payment-mode="E-Wallet" aria-pressed="false"><i class="bi bi-phone me-1"></i>E-Wallet</button>
				<button class="btn btn-outline-primary payment-mode" type="button" data-payment-mode="Bank" aria-pressed="false"><i class="bi bi-bank me-1"></i>Bank Payment</button>
			</div>
			<div class="checkout-entry">
				<div>
					<label class="form-label fw-semibold" for="amount-tendered">Amount Tendered</label>
					<div class="tender-input-wrap">
						<input class="form-control form-control-lg tender-input" id="amount-tendered" type="text" inputmode="decimal" autocomplete="off" placeholder="0.00">
						<button class="tender-clear" id="clear-tendered" type="button" aria-label="Clear amount tendered">&times;</button>
					</div>
					<p class="billing-line mt-3" id="change-line"><span>Change:</span><span class="change-value" id="change-value">₱0.00</span></p>
					<div class="mt-3" id="reference-field" hidden>
						<label class="form-label fw-semibold" for="payment-reference">Reference No.:</label>
						<input class="form-control" id="payment-reference" type="text" maxlength="50" autocomplete="off">
					</div>
				</div>
				<div class="checkout-keypad" id="checkout-keypad" aria-label="Amount keypad">
					<?php for ($digit = 1; $digit <= 9; $digit++): ?>
						<button class="btn btn-light border" type="button" data-tender-key="<?= $digit ?>" aria-label="<?= $digit ?>"><?= $digit ?></button>
					<?php endfor; ?>
					<button class="btn btn-light border" type="button" data-tender-key="." aria-label="Decimal point">.</button>
					<button class="btn btn-light border" type="button" data-tender-key="0" aria-label="0">0</button>
					<button class="btn btn-light border" type="button" data-tender-backspace aria-label="Backspace"><i class="bi bi-backspace"></i></button>
				</div>
			</div>
		</div>
		<div class="counter-dialog-footer">
			<button class="btn btn-outline-secondary" type="button" data-close-dialog="checkout-dialog">Close</button>
			<button class="btn btn-success" id="complete-transaction" type="button" disabled><i class="bi bi-check2-circle me-1"></i>Complete Transaction</button>
		</div>
	</dialog>

	<dialog class="counter-dialog" id="itemized-discount-dialog" aria-labelledby="itemized-discount-heading">
		<form id="itemized-discount-form">
			<div class="counter-dialog-header">
				<h2 class="h5 mb-0" id="itemized-discount-heading"><i class="bi bi-person-badge text-primary me-2"></i>Tag Senior Citizen Discount</h2>
			</div>
			<div class="counter-dialog-body d-grid gap-3">
				<p class="fw-semibold mb-0" id="itemized-product-label"></p>
				<div>
					<label class="form-label" for="itemized-quantity">Quantity to discount</label>
					<input class="form-control" id="itemized-quantity" type="number" min="1" step="1" required>
				</div>
				<div>
					<label class="form-label" for="itemized-sc-count">Senior Citizens assigned</label>
					<input class="form-control" id="itemized-sc-count" type="number" min="1" step="1" required>
				</div>
				<div class="sc-credentials" id="itemized-credentials"></div>
				<p class="alert alert-danger py-2 mb-0" id="itemized-discount-error" role="alert" hidden></p>
			</div>
			<div class="counter-dialog-footer">
				<button class="btn btn-outline-secondary" type="button" data-close-dialog="itemized-discount-dialog">Cancel</button>
				<button class="btn btn-outline-danger" id="remove-itemized-tag" type="button" hidden>Remove Tag</button>
				<button class="btn btn-primary" type="submit">Apply Tag</button>
			</div>
		</form>
	</dialog>

	<dialog class="counter-dialog" id="itemized-discount-view-dialog" aria-labelledby="itemized-discount-view-heading">
		<div class="counter-dialog-header">
			<h2 class="h5 mb-0" id="itemized-discount-view-heading"><i class="bi bi-person-badge text-primary me-2"></i>Applied SC Discount</h2>
		</div>
		<div class="counter-dialog-body">
			<p class="fw-semibold" id="itemized-discount-view-product"></p>
			<p class="mb-1" id="itemized-discount-view-quantity"></p>
			<p id="itemized-discount-view-amount"></p>
			<div id="itemized-discount-view-credentials"></div>
		</div>
		<div class="counter-dialog-footer">
			<button class="btn btn-outline-secondary" type="button" data-close-dialog="itemized-discount-view-dialog">Close</button>
		</div>
	</dialog>

	<dialog class="counter-dialog" id="shared-discount-dialog" aria-labelledby="shared-discount-heading">
		<form id="shared-discount-form">
			<div class="counter-dialog-header">
				<h2 class="h5 mb-0" id="shared-discount-heading"><i class="bi bi-people text-primary me-2"></i>Shared Bill SC Discount</h2>
			</div>
			<div class="counter-dialog-body d-grid gap-3">
				<div>
					<label class="form-label" for="shared-total-pax">Total Pax</label>
					<input class="form-control" id="shared-total-pax" type="number" min="1" step="1" required>
				</div>
				<div>
					<label class="form-label" for="shared-sc-count">Senior Citizen Count</label>
					<input class="form-control" id="shared-sc-count" type="number" min="1" step="1" required>
				</div>
				<div class="sc-credentials" id="shared-credentials"></div>
				<p class="alert alert-danger py-2 mb-0" id="shared-discount-error" role="alert" hidden></p>
			</div>
			<div class="counter-dialog-footer">
				<button class="btn btn-outline-secondary" type="button" data-close-dialog="shared-discount-dialog">Cancel</button>
				<button class="btn btn-outline-danger" id="remove-shared-discount" type="button" hidden>Remove Discount</button>
				<button class="btn btn-primary" type="submit">Apply Shared Discount</button>
			</div>
		</form>
	</dialog>

	<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
	<script>
		(function () {
			'use strict';

			const currency = new Intl.NumberFormat('en-PH', {
				style: 'currency',
				currency: 'PHP',
				minimumFractionDigits: 2,
				maximumFractionDigits: 2
			});
			const productGrid = document.getElementById('product-grid');
			const cartItems = document.getElementById('cart-items');
			const cartEmpty = document.getElementById('cart-empty');
			const noProducts = document.getElementById('no-products');
			const searchInput = document.getElementById('product-search');
			const categoryButtons = document.getElementById('category-buttons');
			const subtotalOutput = document.getElementById('subtotal');
			const amountDueOutput = document.getElementById('amount-due');
			const discountOutput = document.getElementById('discount-total');
			const sharedDiscountButton = document.getElementById('open-shared-discount');
			const itemizedDialog = document.getElementById('itemized-discount-dialog');
			const sharedDialog = document.getElementById('shared-discount-dialog');
			const cart = new Map();
			let nextCartLineId = 1;
			const invoiceDay = <?= json_encode($invoiceDay) ?>;
			let nextInvoiceSequence = <?= (int)$nextInvoiceSequence ?>;
			let discountMode = 'None';
			let sharedDiscount = null;
			let activeLineId = null;
			let transactionInProgress = false;
			let voidActionInProgress = false;
			let amountDueCents = 0;
			let paymentMode = 'Cash';

			function formatMoney(cents) {
				return currency.format(cents / 100);
			}

			function parseMoneyInputToCents(value) {
				if (!/^\d+(?:\.\d{1,2})?$/.test(value)) return null;
				const parts = value.split('.');
				if (parts[0].length > 8) return null;
				const cents = Number(parts[0]) * 100 + Number(((parts[1] || '') + '00').slice(0, 2));
				return Number.isSafeInteger(cents) && cents <= 9999999999 ? cents : null;
			}

			function collectCredentials(container) {
				return Array.from(container.querySelectorAll('.sc-credential')).map(function (row) {
					return {
						SCId: row.querySelector('[name="sc-id"]').value.trim(),
						SCName: row.querySelector('[name="sc-name"]').value.trim()
					};
				});
			}

			function buildCredentialInputs(container, count, existing) {
				const previous = existing || collectCredentials(container);
				container.replaceChildren();
				for (let index = 0; index < count; index += 1) {
					const fieldset = document.createElement('fieldset');
					fieldset.className = 'sc-credential';
					const legend = document.createElement('legend');
					legend.className = 'float-none w-auto px-1 mb-0 fs-6 fw-semibold';
					legend.textContent = 'Senior Citizen ' + (index + 1);
					fieldset.appendChild(legend);

					[['SC ID Number', 'sc-id', 'text', 100], ['Name', 'sc-name', 'text', 150]].forEach(function (field, fieldIndex) {
						const inputId = container.id + '-' + index + '-' + fieldIndex;
						const label = document.createElement('label');
						label.htmlFor = inputId;
						label.className = 'form-label small mb-0 align-self-center';
						label.textContent = field[0];
						const input = document.createElement('input');
						input.id = inputId;
						input.className = 'form-control form-control-sm';
						input.name = field[1];
						input.type = field[2];
						input.maxLength = field[3];
						input.required = true;
						input.autocomplete = 'off';
						input.value = previous[index] ? (fieldIndex === 0 ? previous[index].SCId : previous[index].SCName) : '';
						fieldset.append(label, input);
					});
					container.appendChild(fieldset);
				}
			}

			function validateCredentials(entries, allowExistingAcrossItems) {
				const seen = new Set();
				for (const entry of entries) {
					if (entry.SCId === '' || entry.SCName === '') return 'Enter both the SC ID number and name.';
					const idKey = entry.SCId.toLocaleUpperCase();
					if (seen.has(idKey) && !allowExistingAcrossItems) return 'Enter each SC ID only once.';
					seen.add(idKey);
				}
				return '';
			}

			function hasItemizedDiscount() {
				return Array.from(cart.values()).some(function (item) {
					return item.discountedQuantity > 0;
				});
			}

			function getProductQuantity(productId, excludedLineId) {
				let quantity = 0;
				cart.forEach(function (item, lineId) {
					if (item.productId === productId && lineId !== excludedLineId) {
						quantity += item.quantity;
					}
				});
				return quantity;
			}

			function createCartLineId() {
				const lineId = String(nextCartLineId);
				nextCartLineId += 1;
				return lineId;
			}

			function calculateDiscount(subtotalCents) {
				if (discountMode === 'Itemized') {
					let deduction = 0;
					cart.forEach(function (item) {
						if (item.discountedQuantity === 0) return;
						const netUnitCents = Math.round(item.priceCents / 1.12);
						const seniorDiscountCents = Math.round(netUnitCents * 0.20);
						const payableUnitCents = netUnitCents - seniorDiscountCents;
						deduction += (item.priceCents - payableUnitCents) * item.discountedQuantity;
					});
					return deduction;
				}
				if (discountMode === 'Shared Bill' && sharedDiscount) {
					const scCount = sharedDiscount.seniorCitizens.length;
					const eligibleGrossCents = Math.round(subtotalCents * scCount / sharedDiscount.totalPax);
					const netCents = Math.round(eligibleGrossCents / 1.12);
					const seniorDiscountCents = Math.round(netCents * 0.20);
					return eligibleGrossCents - (netCents - seniorDiscountCents);
				}
				return 0;
			}

			function updateProductFilter() {
				const search = searchInput.value.trim().toLocaleLowerCase();
				const selectedCategory = categoryButtons.querySelector('[aria-pressed="true"]').dataset.categoryId;
				let visibleCount = 0;

				productGrid.querySelectorAll('.product-card').forEach(function (card) {
					const matchesSearch = card.dataset.searchText.toLocaleLowerCase().includes(search);
					const matchesCategory = selectedCategory === 'all' || card.dataset.categoryId === selectedCategory;
					card.hidden = !matchesSearch || !matchesCategory;
					if (!card.hidden) visibleCount += 1;
				});
				noProducts.hidden = visibleCount > 0;
				if (visibleCount === 0 && productGrid.querySelector('.product-card')) {
					noProducts.textContent = 'No matching products found.';
				}
			}

			function makeCartRow(lineId, item) {
				const row = document.createElement('article');
				row.className = 'cart-row';
				row.dataset.productId = item.productId;
				row.dataset.lineId = lineId;

				const rowActions = document.createElement('div');
				rowActions.className = 'cart-row-actions';
				const tagButton = document.createElement('button');
				tagButton.className = 'btn btn-outline-primary btn-sm';
				tagButton.type = 'button';
				tagButton.disabled = discountMode === 'Shared Bill';
				tagButton.textContent = 'Tag SC';
				tagButton.addEventListener('click', function () {
					openItemizedDiscount(lineId);
				});
				const viewButton = document.createElement('button');
				viewButton.className = 'btn btn-outline-secondary btn-sm';
				viewButton.type = 'button';
				viewButton.hidden = item.discountedQuantity === 0;
				viewButton.textContent = 'View';
				viewButton.addEventListener('click', function () {
					openItemizedDiscountView(lineId);
				});
				const voidButton = document.createElement('button');
				voidButton.className = 'btn btn-outline-danger btn-sm void-item';
				voidButton.type = 'button';
				voidButton.textContent = 'Void Item';
				voidButton.disabled = voidActionInProgress || transactionInProgress;
				voidButton.addEventListener('click', function () {
					if (voidActionInProgress || transactionInProgress) return;
					voidActionInProgress = true;
					renderCart();
					recordPreCheckoutVoid([item], 'Item removed from cart').then(function (saved) {
						if (saved) {
							cart.delete(lineId);
							if (cart.size === 0 && discountMode === 'Shared Bill') {
								discountMode = 'None';
								sharedDiscount = null;
							}
							if (discountMode === 'Itemized' && !hasItemizedDiscount()) {
								discountMode = 'None';
							}
						}
						voidActionInProgress = false;
						renderCart();
					});
				});
				if (item.discountedQuantity === 0) {
					rowActions.appendChild(tagButton);
				}
				rowActions.append(viewButton, voidButton);

				const content = document.createElement('div');
				content.className = 'cart-row-content';
				const name = document.createElement('p');
				name.className = 'cart-product-name';
				name.textContent = item.name + (item.discountedQuantity > 0 ? ' (SC)' : '');

				const quantityControl = document.createElement('div');
				quantityControl.className = 'quantity-control';
				const decrease = document.createElement('button');
				decrease.className = 'btn btn-outline-secondary btn-sm';
				decrease.type = 'button';
				decrease.setAttribute('aria-label', 'Decrease quantity');
				decrease.textContent = '-';
				const minimumQuantity = item.discountedQuantity > 0 ? item.assignedSc.length : 1;
				decrease.disabled = item.quantity <= minimumQuantity;
				decrease.addEventListener('click', function () {
					setQuantity(lineId, item.quantity - 1);
				});
				const quantity = document.createElement('input');
				quantity.className = 'form-control form-control-sm quantity-input';
				quantity.type = 'number';
				quantity.min = String(minimumQuantity);
				quantity.max = String(Math.max(item.quantity, item.stock - getProductQuantity(item.productId, lineId)));
				quantity.step = '1';
				quantity.inputMode = 'numeric';
				quantity.value = String(item.quantity);
				quantity.setAttribute('aria-label', 'Quantity for ' + item.name);
				quantity.addEventListener('change', function () {
					const entered = Number.parseInt(quantity.value, 10);
					setQuantity(lineId, Number.isNaN(entered) ? minimumQuantity : entered);
				});
				const increase = document.createElement('button');
				increase.className = 'btn btn-outline-secondary btn-sm';
				increase.type = 'button';
				increase.setAttribute('aria-label', 'Increase quantity');
				increase.textContent = '+';
				increase.disabled = item.quantity + getProductQuantity(item.productId, lineId) >= item.stock;
				increase.addEventListener('click', function () {
					setQuantity(lineId, item.quantity + 1);
				});
				quantityControl.append(decrease, quantity, increase);

				const price = document.createElement('p');
				price.className = 'cart-price';
				price.textContent = formatMoney(item.priceCents);
				const total = document.createElement('p');
				total.className = 'cart-total';
				total.textContent = formatMoney(item.priceCents * item.quantity);
				content.append(name, quantityControl, price, total);
				if (item.discountedQuantity > 0) {
					const summary = document.createElement('p');
					summary.className = 'discount-summary';
					summary.textContent = 'SC discount applied';
					content.appendChild(summary);
				}

				row.append(rowActions, content);
				return row;
			}

			function renderCart() {
				cartItems.replaceChildren();
				let subtotalCents = 0;
				cart.forEach(function (item, lineId) {
					subtotalCents += item.priceCents * item.quantity;
					cartItems.appendChild(makeCartRow(lineId, item));
				});
				cartEmpty.hidden = cart.size > 0;
				if (cart.size === 0) cartItems.appendChild(cartEmpty);

				const discountCents = calculateDiscount(subtotalCents);
				amountDueCents = subtotalCents - discountCents;
				subtotalOutput.textContent = formatMoney(subtotalCents);
				discountOutput.textContent = '- ' + formatMoney(discountCents);
				amountDueOutput.textContent = formatMoney(amountDueCents);
				document.getElementById('void-cart').disabled = cart.size === 0 || voidActionInProgress || transactionInProgress;
				document.getElementById('open-checkout').disabled = cart.size === 0 || voidActionInProgress || transactionInProgress;
				sharedDiscountButton.disabled = cart.size === 0 || discountMode === 'Itemized';
				sharedDiscountButton.textContent = discountMode === 'Shared Bill'
					? 'Edit Shared SC Discount'
					: 'Apply SC Discount (Shared)';
				productGrid.querySelectorAll('.product-card').forEach(function (card) {
					const stock = Number.parseInt(card.dataset.productStock, 10);
					const remaining = Math.max(0, stock - getProductQuantity(card.dataset.productId));
					card.disabled = remaining === 0 || voidActionInProgress || transactionInProgress;
					card.querySelector('.product-stock').textContent = remaining > 0
						? remaining + ' Stock Left'
						: 'No Stock';
				});
			}

			function setQuantity(lineId, quantity) {
				const item = cart.get(lineId);
				if (!item) return;
				const minimum = item.discountedQuantity > 0 ? item.assignedSc.length : 1;
				const maximum = Math.max(item.quantity, item.stock - getProductQuantity(item.productId, lineId));
				item.quantity = Math.max(minimum, Math.min(maximum, quantity));
				if (item.discountedQuantity > 0) item.discountedQuantity = item.quantity;
				renderCart();
			}

			function openItemizedDiscount(lineId) {
				if (discountMode === 'Shared Bill') return;
				const item = cart.get(lineId);
				if (!item) return;
				activeLineId = lineId;
				document.getElementById('itemized-product-label').textContent = item.name;
				const discountedQuantity = document.getElementById('itemized-quantity');
				discountedQuantity.max = String(item.quantity);
				discountedQuantity.value = String(item.discountedQuantity || 1);
				const scCount = document.getElementById('itemized-sc-count');
				scCount.max = discountedQuantity.value;
				scCount.value = String(item.assignedSc.length || 1);
				buildCredentialInputs(document.getElementById('itemized-credentials'), Number(scCount.value), item.assignedSc);
				document.getElementById('remove-itemized-tag').hidden = item.discountedQuantity === 0;
				document.getElementById('itemized-discount-error').hidden = true;
				itemizedDialog.showModal();
			}

			function openItemizedDiscountView(lineId) {
				const item = cart.get(lineId);
				if (!item || item.discountedQuantity === 0) return;

				const netUnitCents = Math.round(item.priceCents / 1.12);
				const payableUnitCents = netUnitCents - Math.round(netUnitCents * 0.20);
				const discountCents = (item.priceCents - payableUnitCents) * item.discountedQuantity;
				document.getElementById('itemized-discount-view-product').textContent = item.name;
				document.getElementById('itemized-discount-view-quantity').textContent =
					'Discounted quantity: ' + item.discountedQuantity;
				document.getElementById('itemized-discount-view-amount').textContent =
					'SC discount applied: ' + formatMoney(discountCents);

				const credentials = document.getElementById('itemized-discount-view-credentials');
				credentials.replaceChildren();
				const heading = document.createElement('p');
				heading.className = 'fw-semibold mb-1';
				heading.textContent = 'Senior Citizen(s):';
				const list = document.createElement('ul');
				list.className = 'mb-0';
				item.assignedSc.forEach(function (entry) {
					const listItem = document.createElement('li');
					listItem.textContent = entry.SCName + ' (ID: ' + entry.SCId + ')';
					list.appendChild(listItem);
				});
				credentials.append(heading, list);
				document.getElementById('itemized-discount-view-dialog').showModal();
			}

			function openSharedDiscount() {
				if (discountMode === 'Itemized' || cart.size === 0) return;
				document.getElementById('shared-total-pax').value = sharedDiscount ? String(sharedDiscount.totalPax) : '';
				document.getElementById('shared-sc-count').value = sharedDiscount
					? String(sharedDiscount.seniorCitizens.length)
					: '1';
				document.getElementById('shared-sc-count').max = sharedDiscount ? String(sharedDiscount.totalPax) : '';
				buildCredentialInputs(
					document.getElementById('shared-credentials'),
					sharedDiscount ? sharedDiscount.seniorCitizens.length : 1,
					sharedDiscount ? sharedDiscount.seniorCitizens : []
				);
				document.getElementById('remove-shared-discount').hidden = !sharedDiscount;
				document.getElementById('shared-discount-error').hidden = true;
				sharedDialog.showModal();
			}

			function bindCredentialCount(inputId, containerId, maxInputId) {
				const input = document.getElementById(inputId);
				const container = document.getElementById(containerId);
				input.addEventListener('input', function () {
					const maxInput = maxInputId ? document.getElementById(maxInputId) : null;
					if (maxInput && maxInput.value === '') {
						container.replaceChildren();
						return;
					}
					const max = maxInput ? Number(maxInput.value) : Number.POSITIVE_INFINITY;
					const count = Number.parseInt(input.value, 10);
					if (!Number.isInteger(count) || count < 0) return;
					if (count > max) input.value = String(max);
					buildCredentialInputs(container, Math.min(count, max));
				});
			}

			document.getElementById('shared-total-pax').addEventListener('input', function () {
				const pax = Number.parseInt(this.value, 10);
				const countInput = document.getElementById('shared-sc-count');
				if (!Number.isInteger(pax) || pax < 1) {
					countInput.removeAttribute('max');
					return;
				}
				countInput.max = String(pax);
				if (Number(countInput.value) > pax) {
					countInput.value = String(pax);
				}
				const count = Number.parseInt(countInput.value, 10);
				if (Number.isInteger(count) && count > 0) {
					buildCredentialInputs(document.getElementById('shared-credentials'), count);
				}
			});
			document.getElementById('itemized-quantity').addEventListener('input', function () {
				const quantity = document.getElementById('itemized-quantity');
				const scCount = document.getElementById('itemized-sc-count');
				const max = Number.parseInt(quantity.value, 10);
				if (Number.isInteger(max) && max > 0) {
					scCount.max = String(max);
					if (Number(scCount.value) > max) {
						scCount.value = String(max);
						buildCredentialInputs(document.getElementById('itemized-credentials'), max);
					}
				}
			});
			bindCredentialCount('itemized-sc-count', 'itemized-credentials', 'itemized-quantity');
			bindCredentialCount('shared-sc-count', 'shared-credentials', null);

			document.getElementById('itemized-discount-form').addEventListener('submit', function (event) {
				event.preventDefault();
				const item = cart.get(activeLineId);
				if (!item) return;
				const quantity = Number.parseInt(document.getElementById('itemized-quantity').value, 10);
				const entries = collectCredentials(document.getElementById('itemized-credentials'));
				const error = document.getElementById('itemized-discount-error');
				const validationMessage = validateCredentials(entries, false);
				if (!Number.isInteger(quantity) || quantity < 1 || quantity > item.quantity) {
					error.textContent = 'Choose a discounted quantity within the cart quantity.';
				} else if (entries.length < 1 || entries.length > quantity) {
					error.textContent = 'SC count must be at least one and cannot exceed the discounted quantity.';
				} else if (validationMessage) {
					error.textContent = validationMessage;
				} else {
					const untaggedQuantity = item.quantity - quantity;
					if (untaggedQuantity > 0) {
						item.quantity = quantity;
						item.discountedQuantity = quantity;
						item.assignedSc = entries;
						const untaggedLine = {
							name: item.name,
							productId: item.productId,
							priceCents: item.priceCents,
							stock: item.stock,
							quantity: untaggedQuantity,
							discountedQuantity: 0,
							assignedSc: []
						};
						cart.set(createCartLineId(), untaggedLine);
					} else {
						item.discountedQuantity = quantity;
						item.assignedSc = entries;
					}
					discountMode = 'Itemized';
					sharedDiscount = null;
					itemizedDialog.close();
					renderCart();
					return;
				}
				error.hidden = false;
			});

			document.getElementById('shared-discount-form').addEventListener('submit', function (event) {
				event.preventDefault();
				const totalPax = Number.parseInt(document.getElementById('shared-total-pax').value, 10);
				const entries = collectCredentials(document.getElementById('shared-credentials'));
				const error = document.getElementById('shared-discount-error');
				const validationMessage = validateCredentials(entries, false);
				if (!Number.isInteger(totalPax) || totalPax < 1 || entries.length < 1 || entries.length > totalPax) {
					error.textContent = 'Total Pax must be at least the SC count.';
				} else if (validationMessage) {
					error.textContent = validationMessage;
				} else {
					discountMode = 'Shared Bill';
					sharedDiscount = {totalPax: totalPax, seniorCitizens: entries};
					cart.forEach(function (item) {
						item.discountedQuantity = 0;
						item.assignedSc = [];
					});
					sharedDialog.close();
					renderCart();
					return;
				}
				error.hidden = false;
			});
			document.getElementById('remove-itemized-tag').addEventListener('click', function () {
				const item = cart.get(activeLineId);
				if (!item) return;
				item.discountedQuantity = 0;
				item.assignedSc = [];
				if (!hasItemizedDiscount()) discountMode = 'None';
				itemizedDialog.close();
				renderCart();
			});
			document.getElementById('remove-shared-discount').addEventListener('click', function () {
				discountMode = 'None';
				sharedDiscount = null;
				sharedDialog.close();
				renderCart();
			});

			productGrid.addEventListener('click', function (event) {
				if (voidActionInProgress || transactionInProgress) return;
				const card = event.target.closest('.product-card');
				if (!card || card.disabled) return;

				const productId = card.dataset.productId;
				if (getProductQuantity(productId) >= Number.parseInt(card.dataset.productStock, 10)) return;
				let existing = null;
				cart.forEach(function (item) {
					if (item.productId === productId && item.discountedQuantity === 0 && !existing) {
						existing = item;
					}
				});
				if (existing) {
					existing.quantity += 1;
				} else {
					const priceCents = Math.round(Number(card.dataset.productPrice) * 100);
					const stock = Number.parseInt(card.dataset.productStock, 10);
					if (!Number.isFinite(priceCents) || stock < 1) return;
					cart.set(createCartLineId(), {
						name: card.dataset.productName,
						productId: productId,
						priceCents: priceCents,
						stock: stock,
						quantity: 1,
						discountedQuantity: 0,
						assignedSc: []
					});
				}
				renderCart();
			});

			searchInput.addEventListener('input', updateProductFilter);
			categoryButtons.addEventListener('click', function (event) {
				const button = event.target.closest('.category-button');
				if (!button) return;
				categoryButtons.querySelectorAll('.category-button').forEach(function (categoryButton) {
					categoryButton.setAttribute('aria-pressed', categoryButton === button ? 'true' : 'false');
				});
				updateProductFilter();
			});

			document.getElementById('void-cart').addEventListener('click', function () {
				document.getElementById('void-dialog-error').hidden = true;
				document.getElementById('void-dialog-error').textContent = '';
				document.getElementById('void-dialog').showModal();
			});
			document.getElementById('confirm-void-cart').addEventListener('click', async function () {
				if (voidActionInProgress || transactionInProgress || cart.size === 0) return;
				voidActionInProgress = true;
				this.disabled = true;
				document.getElementById('void-dialog-error').hidden = true;
				document.querySelector('[data-close-dialog="void-dialog"]').disabled = true;
				renderCart();
				const saved = await recordPreCheckoutVoid(
					Array.from(cart.values()),
					'Cart cleared before checkout'
				);
				if (saved) {
					cart.clear();
					discountMode = 'None';
					sharedDiscount = null;
					document.getElementById('void-dialog').close();
				} else {
					const status = document.getElementById('transaction-status');
					const errorMessage = status.textContent;
					const dialogError = document.getElementById('void-dialog-error');
					dialogError.textContent = errorMessage || 'The cart void could not be recorded.';
					dialogError.hidden = false;
				}
				voidActionInProgress = false;
				this.disabled = false;
				document.querySelector('[data-close-dialog="void-dialog"]').disabled = false;
				renderCart();
			});

			async function recordPreCheckoutVoid(items, reason) {
				const status = document.getElementById('transaction-status');
				status.hidden = false;
				status.classList.remove('error');
				status.textContent = 'Recording pre-checkout void...';
				try {
					const response = await fetch('pvoid.php', {
						method: 'POST',
						headers: {'Content-Type': 'application/json'},
						body: JSON.stringify({
							action: 'pre_checkout',
							reason: reason,
							cart: items.map(function (item) {
								return {
									productId: Number(item.productId),
									quantity: item.quantity
								};
							})
						})
					});
					const result = await response.json();
					if (!response.ok) throw new Error(result.message || 'The cart void could not be recorded.');
					status.textContent = result.message;
					return true;
				} catch (error) {
					status.classList.add('error');
					status.textContent = error instanceof Error ? error.message : 'The cart void could not be recorded.';
					return false;
				}
			}
			sharedDiscountButton.addEventListener('click', openSharedDiscount);
			document.getElementById('open-checkout').addEventListener('click', function () {
				document.getElementById('checkout-due').textContent = formatMoney(amountDueCents);
				document.getElementById('payment-reference').value = '';
				setPaymentMode('Cash');
				updateChange();
				document.getElementById('checkout-dialog').showModal();
			});

			function setPaymentMode(mode) {
				paymentMode = mode;
				const tenderInput = document.getElementById('amount-tendered');
				const isCash = paymentMode === 'Cash';
				tenderInput.readOnly = !isCash;
				tenderInput.inputMode = isCash ? 'decimal' : 'none';
				tenderInput.value = isCash
					? ''
					: (Math.floor(amountDueCents / 100) + '.' + String(amountDueCents % 100).padStart(2, '0'));
				document.getElementById('clear-tendered').hidden = !isCash;
				document.getElementById('change-line').hidden = !isCash;
				document.getElementById('reference-field').hidden = isCash;
				document.getElementById('payment-reference').required = !isCash;
				document.getElementById('checkout-keypad').hidden = !isCash;
				document.querySelectorAll('[data-payment-mode]').forEach(function (button) {
					const selected = button.dataset.paymentMode === paymentMode;
					button.setAttribute('aria-pressed', selected ? 'true' : 'false');
					button.classList.toggle('btn-primary', selected);
					button.classList.toggle('btn-outline-primary', !selected);
				});
			}
			document.querySelectorAll('[data-payment-mode]').forEach(function (button) {
				button.addEventListener('click', function () {
					setPaymentMode(button.dataset.paymentMode);
					updateChange();
				});
			});

			function updateChange() {
				const tenderInput = document.getElementById('amount-tendered');
				const changeOutput = document.getElementById('change-value');
				const tenderedCents = parseMoneyInputToCents(tenderInput.value);
				const enoughTendered = tenderedCents !== null && tenderedCents >= amountDueCents;
				const reference = document.getElementById('payment-reference').value.trim();
				const referenceValid = paymentMode === 'Cash' || (reference !== '' && reference.length <= 50);
				changeOutput.classList.toggle('insufficient', !enoughTendered);
				changeOutput.textContent = enoughTendered
					? formatMoney(tenderedCents - amountDueCents)
					: 'Insufficient amount';
				document.getElementById('complete-transaction').disabled =
					!enoughTendered || !referenceValid || cart.size === 0
					|| transactionInProgress || voidActionInProgress;
			}
			document.getElementById('amount-tendered').addEventListener('input', updateChange);
			document.getElementById('payment-reference').addEventListener('input', updateChange);
			document.getElementById('clear-tendered').addEventListener('click', function () {
				const tenderInput = document.getElementById('amount-tendered');
				tenderInput.value = '';
				tenderInput.dispatchEvent(new Event('input', {bubbles: true}));
				tenderInput.focus();
			});
			document.getElementById('checkout-keypad').addEventListener('click', function (event) {
				const button = event.target.closest('button');
				if (!button) return;

				const tenderInput = document.getElementById('amount-tendered');
				if (button.hasAttribute('data-tender-backspace')) {
					tenderInput.value = tenderInput.value.slice(0, -1);
				} else {
					const key = button.dataset.tenderKey;
					if (key === '.') {
						if (tenderInput.value.includes('.')) return;
						tenderInput.value += tenderInput.value === '' ? '0.' : '.';
					} else {
						const parts = tenderInput.value.split('.');
						if (parts[0].replace(/\D/g, '').length >= 8
							&& !tenderInput.value.includes('.')) return;
						if (parts.length > 1 && parts[1].length >= 2) return;
						tenderInput.value += key;
					}
				}
				tenderInput.dispatchEvent(new Event('input', {bubbles: true}));
				tenderInput.focus();
			});

			document.getElementById('complete-transaction').addEventListener('click', async function () {
				if (transactionInProgress || cart.size === 0) return;
				const tendered = document.getElementById('amount-tendered').value.trim();
				const tenderedCents = parseMoneyInputToCents(tendered);
				const referenceNo = document.getElementById('payment-reference').value.trim();
				if (tenderedCents === null || tenderedCents < amountDueCents
					|| (paymentMode !== 'Cash' && referenceNo === '')) {
					updateChange();
					return;
				}

				const invoiceNo = 'OR-' + invoiceDay + '-' + String(nextInvoiceSequence).padStart(3, '0');
				const payload = {
					invoiceNo: invoiceNo,
					amountTendered: tendered,
					paymentMode: paymentMode,
					referenceNo: paymentMode === 'Cash' ? null : referenceNo,
					discountMode: discountMode,
					cart: Array.from(cart.entries()).map(function (entry) {
						return {
							productId: Number(entry[1].productId),
							quantity: entry[1].quantity,
							discountedQuantity: entry[1].discountedQuantity,
							assignedSc: entry[1].assignedSc
						};
					})
				};
				if (discountMode === 'Shared Bill') {
					payload.shared = sharedDiscount;
				}

				const status = document.getElementById('transaction-status');
				status.hidden = false;
				status.classList.remove('error');
				status.textContent = 'Saving transaction...';
				transactionInProgress = true;
				updateChange();
				try {
					const response = await fetch('complete_counter_transaction.php', {
						method: 'POST',
						headers: {'Content-Type': 'application/json'},
						body: JSON.stringify(payload)
					});
					const result = await response.json();
					if (!response.ok) throw new Error(result.message || 'The transaction could not be completed.');

					Object.entries(result.updatedStocks).forEach(function (entry) {
						const card = productGrid.querySelector('[data-product-id="' + entry[0] + '"]');
						if (!card) return;
						const stock = Number(entry[1]);
						card.dataset.productStock = String(stock);
						card.disabled = stock < 1;
						card.querySelector('.product-stock').textContent = stock > 0 ? stock + ' Stock Left' : 'No Stock';
					});
					cart.clear();
					discountMode = 'None';
					sharedDiscount = null;
					nextInvoiceSequence += 1;
					renderCart();
					document.getElementById('checkout-dialog').close();
					status.textContent = 'Transaction completed. Invoice No: ' + result.invoiceNo;
					const receiptUrl = 'receipt.php?invoiceNo=' + encodeURIComponent(result.invoiceNo);
					printReceipt(receiptUrl, status);
					const receiptLink = document.createElement('a');
					receiptLink.href = receiptUrl;
					receiptLink.target = '_blank';
					receiptLink.rel = 'noopener';
					receiptLink.textContent = ' View Receipt';
					status.appendChild(receiptLink);
				} catch (error) {
					status.classList.add('error');
					status.textContent = error instanceof Error ? error.message : 'The transaction could not be completed.';
				} finally {
					transactionInProgress = false;
					updateChange();
				}
			});

			function printReceipt(receiptUrl, status) {
				const frame = document.createElement('iframe');
				frame.title = 'Receipt print frame';
				frame.setAttribute('aria-hidden', 'true');
				frame.tabIndex = -1;
				frame.style.position = 'fixed';
				frame.style.left = '-10000px';
				frame.style.top = '0';
				frame.style.width = '58mm';
				frame.style.height = '200mm';
				frame.style.border = '0';

				let removed = false;
				function removeFrame() {
					if (removed) return;
					removed = true;
					window.clearTimeout(cleanupTimer);
					frame.remove();
				}
				const cleanupTimer = window.setTimeout(removeFrame, 120000);
				frame.addEventListener('load', function () {
					const receiptWindow = frame.contentWindow;
					if (!receiptWindow || !receiptWindow.document.querySelector('.receipt')) {
						removeFrame();
						status.classList.add('error');
						status.textContent = 'Transaction completed, but the receipt could not be prepared for printing.';
						return;
					}
					receiptWindow.addEventListener('afterprint', removeFrame, {once: true});
					try {
						receiptWindow.focus();
						receiptWindow.print();
					} catch (error) {
						removeFrame();
						status.classList.add('error');
						status.textContent = 'Transaction completed, but printing failed: '
							+ (error instanceof Error ? error.message : 'Please check the browser print settings.');
					}
				});
				frame.src = receiptUrl;
				document.body.appendChild(frame);
			}

			document.querySelectorAll('[data-close-dialog]').forEach(function (button) {
				button.addEventListener('click', function () {
					document.getElementById(button.dataset.closeDialog).close();
				});
			});

			renderCart();
			updateProductFilter();
		}());
	</script>
</body>
</html>