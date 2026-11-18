<?php
require_once __DIR__ . '/../invtest/db.php';

final class PosVoidException extends RuntimeException
{
	public int $httpStatus;

	public function __construct(string $message, int $httpStatus = 422)
	{
		parent::__construct($message);
		$this->httpStatus = $httpStatus;
	}
}

function respondWithVoidJson(int $status, array $data): void
{
	http_response_code($status);
	header('Content-Type: application/json; charset=utf-8');
	header('X-Content-Type-Options: nosniff');
	echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	exit;
}

function voidInteger($value, string $label, int $minimum = 0): int
{
	if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
		throw new PosVoidException($label . ' must be a whole number.');
	}
	$number = (int)$value;
	if ($number < $minimum || $number > 2147483647) {
		throw new PosVoidException($label . ' is outside the allowed range.');
	}
	return $number;
}

function voidMoneyToCents($value): int
{
	$value = (string)$value;
	if (!preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $value, $matches)
		|| strlen($matches[1]) > 8) {
		throw new RuntimeException('A saved product price is invalid.');
	}
	return ((int)$matches[1] * 100) + (int)str_pad($matches[2] ?? '', 2, '0');
}

function voidCentsToDecimal(int $cents): string
{
	return intdiv($cents, 100) . '.' . str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT);
}

function voidInsertMaster(
	mysqli $conn,
	?int $itemId,
	int $unitPriceCents,
	int $quantity,
	int $productId,
	string $productName,
	string $voidType,
	string $reason
): int {
	if ($unitPriceCents < 0 || $quantity < 1
		|| $unitPriceCents > intdiv(9999999999, $quantity)) {
		throw new PosVoidException('The void total exceeds the supported amount.');
	}
	$lineTotalCents = $unitPriceCents * $quantity;
	$unitPrice = voidCentsToDecimal($unitPriceCents);
	$lineTotal = voidCentsToDecimal($lineTotalCents);
	$insert = $conn->prepare(
		'INSERT INTO `m12-void_master`
		 (`tm-ItemId`, `vm-UnitPrice`, `vm-LineTotal`, `vm-Quantity`,
		  `vm-ProductId`, `vm-ProductName`, `vm-VoidType`, `vm-Reason`)
		 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
	);
	$insert->bind_param(
		'issiisss',
		$itemId,
		$unitPrice,
		$lineTotal,
		$quantity,
		$productId,
		$productName,
		$voidType,
		$reason
	);
	$insert->execute();
	$voidId = (int)$conn->insert_id;
	$insert->close();
	return $voidId;
}

function voidAllocationSlices(
	?string $allocationJson,
	int $originalQuantity,
	int $alreadyVoided,
	int $voidQuantity,
	int $restockQuantity
): ?array {
	if ($allocationJson === null || $allocationJson === '') {
		return null;
	}
	try {
		$allocations = json_decode($allocationJson, true, 512, JSON_THROW_ON_ERROR);
	} catch (JsonException $exception) {
		throw new RuntimeException('A saved stock allocation is invalid.', 0, $exception);
	}
	if (!is_array($allocations) || !array_is_list($allocations)) {
		throw new RuntimeException('A saved stock allocation is invalid.');
	}
	$allocationTotal = 0;
	foreach ($allocations as $allocation) {
		if (!is_array($allocation)
			|| !isset($allocation['stockId'], $allocation['quantity'])
			|| !is_int($allocation['stockId']) || $allocation['stockId'] < 1
			|| !is_int($allocation['quantity']) || $allocation['quantity'] < 1) {
			throw new RuntimeException('A saved stock allocation is invalid.');
		}
		$allocationTotal += $allocation['quantity'];
	}
	if ($allocationTotal !== $originalQuantity) {
		throw new RuntimeException('A saved stock allocation does not match the sold quantity.');
	}

	$voidStart = $alreadyVoided;
	$voidEnd = $alreadyVoided + $voidQuantity;
	$position = 0;
	$restockRemaining = $restockQuantity;
	$slices = [];
	foreach ($allocations as $allocation) {
		$segmentStart = $position;
		$segmentEnd = $position + $allocation['quantity'];
		$overlap = max(0, min($segmentEnd, $voidEnd) - max($segmentStart, $voidStart));
		if ($overlap > 0) {
			$restock = min($overlap, $restockRemaining);
			$restockRemaining -= $restock;
			$slices[] = [
				'stockId' => $allocation['stockId'],
				'restock' => $restock,
				'waste' => $overlap - $restock,
			];
		}
		$position = $segmentEnd;
	}
	if ($restockRemaining !== 0 || array_sum(array_column($slices, 'restock'))
		+ array_sum(array_column($slices, 'waste')) !== $voidQuantity) {
		throw new RuntimeException('A saved stock allocation could not be matched to the void quantity.');
	}
	return $slices;
}

function insertVoidedWaste(
	mysqli $conn,
	int $stockId,
	int $voidId,
	int $quantity,
	int $unitPriceCents
): void {
	if ($quantity < 1) {
		return;
	}
	if ($unitPriceCents < 0 || $unitPriceCents > intdiv(9999999999, $quantity)) {
		throw new PosVoidException('The waste total exceeds the supported amount.');
	}
	$totalCostCents = $unitPriceCents * $quantity;
	$wasteType = 'Voided Waste';
	$totalCost = voidCentsToDecimal($totalCostCents);
	$insert = $conn->prepare(
		'INSERT INTO `m10.3-product_waste`
		 (`pts-Id`, `vm-Id`, `ptw-WastedQty`, `ptw-WasteType`, `ptw-TotalCost`)
		 VALUES (?, ?, ?, ?, ?)'
	);
	$insert->bind_param('iiiss', $stockId, $voidId, $quantity, $wasteType, $totalCost);
	$insert->execute();
	$insert->close();
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$transactionStarted = false;

try {
	if ($method === 'GET') {
		$transactionIdInput = $_GET['transactionId'] ?? '';
		$transactionId = is_string($transactionIdInput)
			? filter_var($transactionIdInput, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
			: false;
		if ($transactionId === false) {
			throw new PosVoidException('A valid transaction is required.', 400);
		}
		$transactionQuery = $conn->prepare(
			'SELECT `tm-InvoiceNo`, `tm-Status`
			 FROM `m11.1-transaction_master`
			 WHERE `tm-TransactionId` = ?'
		);
		$transactionQuery->bind_param('i', $transactionId);
		$transactionQuery->execute();
		$transaction = $transactionQuery->get_result()->fetch_assoc();
		$transactionQuery->close();
		if (!$transaction) {
			throw new PosVoidException('Transaction not found.', 404);
		}

		$itemsQuery = $conn->prepare(
			'SELECT oi.`oi-OrderId`, oi.`oi-ProductName`, oi.`oi-Quantity`,
			        oi.`oi-UnitPrice`, oi.`oi-LineTotal`,
			        COALESCE(SUM(vm.`vm-Quantity`), 0) AS `voidedQuantity`
			 FROM `m11.2-order_items` oi
			 LEFT JOIN `m12-void_master` vm ON vm.`tm-ItemId` = oi.`oi-OrderId`
			 WHERE oi.`tm-TransactionId` = ?
			 GROUP BY oi.`oi-OrderId`, oi.`oi-ProductName`, oi.`oi-Quantity`,
			          oi.`oi-UnitPrice`, oi.`oi-LineTotal`
			 ORDER BY oi.`oi-OrderId`'
		);
		$itemsQuery->bind_param('i', $transactionId);
		$itemsQuery->execute();
		$itemsResult = $itemsQuery->get_result();
		$items = [];
		while ($item = $itemsResult->fetch_assoc()) {
			$originalQuantity = (int)$item['oi-Quantity'];
			$voidedQuantity = (int)$item['voidedQuantity'];
			$items[] = [
				'id' => (int)$item['oi-OrderId'],
				'name' => (string)$item['oi-ProductName'],
				'quantity' => $originalQuantity,
				'voidedQuantity' => $voidedQuantity,
				'remainingQuantity' => max(0, $originalQuantity - $voidedQuantity),
				'unitPrice' => (string)$item['oi-UnitPrice'],
				'lineTotal' => (string)$item['oi-LineTotal'],
			];
		}
		$itemsQuery->close();
		respondWithVoidJson(200, [
			'transaction' => [
				'id' => (int)$transactionId,
				'invoice' => (string)$transaction['tm-InvoiceNo'],
				'status' => (string)$transaction['tm-Status'],
			],
			'items' => $items,
		]);
	}

	if ($method !== 'POST') {
		throw new PosVoidException('Use GET to view a transaction or POST to save a void.', 405);
	}
	if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) {
		throw new PosVoidException('Send the void request as JSON.', 415);
	}
	$payload = json_decode(file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
	if (!is_array($payload)) {
		throw new PosVoidException('The void request is invalid.', 400);
	}
	$action = $payload['action'] ?? '';
	if ($action === 'pre_checkout') {
		$cart = $payload['cart'] ?? null;
		$reason = $payload['reason'] ?? '';
		if (!is_array($cart) || !array_is_list($cart) || count($cart) < 1 || count($cart) > 100) {
			throw new PosVoidException('The cart contains no items or too many items.');
		}
		if (!in_array($reason, ['Item removed from cart', 'Cart cleared before checkout'], true)) {
			throw new PosVoidException('The pre-checkout void reason is invalid.');
		}

		$cartItems = [];
		$productIds = [];
		foreach ($cart as $cartEntry) {
			if (!is_array($cartEntry)) {
				throw new PosVoidException('The cart contains an invalid item.');
			}
			$productId = voidInteger($cartEntry['productId'] ?? null, 'Product ID', 1);
			$quantity = voidInteger($cartEntry['quantity'] ?? null, 'Quantity', 1);
			$cartItems[] = ['productId' => $productId, 'quantity' => $quantity];
			$productIds[$productId] = $productId;
		}
		sort($productIds, SORT_NUMERIC);

		$conn->begin_transaction();
		$transactionStarted = true;
		$products = [];
		$productQuery = $conn->prepare(
			'SELECT `pti-Name`, `pti-Variant`, `pti-Price`
			 FROM `m10.1-product_information`
			 WHERE `pti-Id` = ?
			 FOR UPDATE'
		);
		foreach ($productIds as $productId) {
			$productQuery->bind_param('i', $productId);
			$productQuery->execute();
			$product = $productQuery->get_result()->fetch_assoc();
			if (!$product) {
				throw new PosVoidException('A product in the cart no longer exists.', 409);
			}
			$productName = (string)$product['pti-Name'];
			if ($product['pti-Variant'] !== null && (string)$product['pti-Variant'] !== '') {
				$productName .= ' - ' . (string)$product['pti-Variant'];
			}
			if (mb_strlen($productName, 'UTF-8') > 50) {
				throw new PosVoidException('A product name is too long to save in the void record.');
			}
			$products[$productId] = [
				'name' => $productName,
				'unitPriceCents' => voidMoneyToCents($product['pti-Price']),
			];
		}
		$productQuery->close();

		$voidType = 'Pre-Checkout';
		foreach ($cartItems as $item) {
			$product = $products[$item['productId']];
			voidInsertMaster(
				$conn,
				null,
				$product['unitPriceCents'],
				$item['quantity'],
				$item['productId'],
				$product['name'],
				$voidType,
				$reason
			);
		}
		$conn->commit();
		$transactionStarted = false;
		respondWithVoidJson(200, ['message' => 'Pre-checkout void recorded.']);
	}

	if ($action !== 'post_checkout') {
		throw new PosVoidException('Choose a valid void action.');
	}
	$transactionId = voidInteger($payload['transactionId'] ?? null, 'Transaction ID', 1);
	$reasonInput = $payload['reason'] ?? null;
	if (!is_string($reasonInput)) {
		throw new PosVoidException('Enter a reason for the void.');
	}
	$reason = trim($reasonInput);
	if ($reason === '' || mb_strlen($reason, 'UTF-8') > 100) {
		throw new PosVoidException('Enter a reason of no more than 100 characters.');
	}
	$submittedItems = $payload['items'] ?? null;
	if (!is_array($submittedItems) || !array_is_list($submittedItems) || count($submittedItems) < 1) {
		throw new PosVoidException('Select at least one item quantity to void.');
	}
	$requestedItems = [];
	foreach ($submittedItems as $submittedItem) {
		if (!is_array($submittedItem)) {
			throw new PosVoidException('The void contains an invalid item.');
		}
		$itemId = voidInteger($submittedItem['itemId'] ?? null, 'Item ID', 1);
		if (isset($requestedItems[$itemId])) {
			throw new PosVoidException('An item may only be submitted once.');
		}
		$requestedItems[$itemId] = [
			'restock' => voidInteger($submittedItem['restock'] ?? null, 'Restock quantity'),
			'waste' => voidInteger($submittedItem['waste'] ?? null, 'Wasted quantity'),
		];
	}
	if (!array_filter($requestedItems, static function (array $item): bool {
		return $item['restock'] > 0 || $item['waste'] > 0;
	})) {
		throw new PosVoidException('Enter a restock or wasted quantity for at least one item.');
	}

	$conn->begin_transaction();
	$transactionStarted = true;
	$transactionQuery = $conn->prepare(
		'SELECT `tm-Status`
		 FROM `m11.1-transaction_master`
		 WHERE `tm-TransactionId` = ?
		 FOR UPDATE'
	);
	$transactionQuery->bind_param('i', $transactionId);
	$transactionQuery->execute();
	$transaction = $transactionQuery->get_result()->fetch_assoc();
	$transactionQuery->close();
	if (!$transaction) {
		throw new PosVoidException('Transaction not found.', 404);
	}
	if ($transaction['tm-Status'] !== 'Completed') {
		throw new PosVoidException('Only completed transactions can be voided.', 409);
	}

	$orderQuery = $conn->prepare(
		'SELECT `oi-OrderId`, `oi-ProductId`, `oi-ProductName`, `oi-Quantity`,
		        `oi-UnitPrice`, `oi-StockAllocations`
		 FROM `m11.2-order_items`
		 WHERE `tm-TransactionId` = ?
		 ORDER BY `oi-OrderId`
		 FOR UPDATE'
	);
	$orderQuery->bind_param('i', $transactionId);
	$orderQuery->execute();
	$orderResult = $orderQuery->get_result();
	$orderItems = [];
	while ($item = $orderResult->fetch_assoc()) {
		$itemId = (int)$item['oi-OrderId'];
		$orderItems[$itemId] = $item;
	}
	$orderQuery->close();
	if (!$orderItems) {
		throw new PosVoidException('The transaction has no items to void.', 409);
	}

	$voidedQuantityQuery = $conn->prepare(
		'SELECT COALESCE(SUM(`vm-Quantity`), 0) AS `voidedQuantity`
		 FROM `m12-void_master`
		 WHERE `tm-ItemId` = ?'
	);
	$voidedBefore = [];
	foreach ($orderItems as $itemId => $item) {
		$voidedQuantityQuery->bind_param('i', $itemId);
		$voidedQuantityQuery->execute();
		$voidedBefore[$itemId] = (int)$voidedQuantityQuery->get_result()->fetch_assoc()['voidedQuantity'];
	}
	$voidedQuantityQuery->close();

	foreach ($orderItems as $itemId => $item) {
		$remaining = (int)$item['oi-Quantity'] - $voidedBefore[$itemId];
		if ($remaining > 0 && !isset($requestedItems[$itemId])) {
			throw new PosVoidException('Allocate every remaining item between restock and waste to void the complete transaction.');
		}
	}

	foreach ($requestedItems as $itemId => $quantities) {
		if (!isset($orderItems[$itemId])) {
			throw new PosVoidException('An item does not belong to this transaction.', 409);
		}
		$item = $orderItems[$itemId];
		$voidQuantity = $quantities['restock'] + $quantities['waste'];
		$remaining = (int)$item['oi-Quantity'] - $voidedBefore[$itemId];
		if ($voidQuantity < 1 || $voidQuantity !== $remaining) {
			throw new PosVoidException('Restock and wasted quantities must add up to the remaining quantity for each item.');
		}
		$unitPriceCents = voidMoneyToCents($item['oi-UnitPrice']);
		$voidId = voidInsertMaster(
			$conn,
			$itemId,
			$unitPriceCents,
			$voidQuantity,
			(int)$item['oi-ProductId'],
			(string)$item['oi-ProductName'],
			'Post-Checkout',
			$reason
		);
		$slices = voidAllocationSlices(
			$item['oi-StockAllocations'] === null ? null : (string)$item['oi-StockAllocations'],
			(int)$item['oi-Quantity'],
			$voidedBefore[$itemId],
			$voidQuantity,
			$quantities['restock']
		);

		if ($slices === null) {
			$stockInsert = $conn->prepare(
				'INSERT INTO `m10.2-product_stock` (`pti-Id`, `pts-Quantity`, `pts-Expiry`)
				 VALUES (?, ?, NULL)'
			);
			$productId = (int)$item['oi-ProductId'];
			$restockQuantity = $quantities['restock'];
			$stockInsert->bind_param('ii', $productId, $restockQuantity);
			$stockInsert->execute();
			$stockId = (int)$conn->insert_id;
			$stockInsert->close();
			$slices = [[
				'stockId' => $stockId,
				'restock' => $quantities['restock'],
				'waste' => $quantities['waste'],
			]];
		}

		foreach ($slices as $slice) {
			$stockId = $slice['stockId'];
			$productId = (int)$item['oi-ProductId'];
			$stockLock = $conn->prepare(
				'SELECT `pti-Id`,
				        (`pts-Expiry` IS NOT NULL AND `pts-Expiry` <= CURDATE()) AS `isExpired`
				 FROM `m10.2-product_stock`
				 WHERE `pts-Id` = ?
				 FOR UPDATE'
			);
			$stockLock->bind_param('i', $stockId);
			$stockLock->execute();
			$stockRow = $stockLock->get_result()->fetch_assoc();
			$stockLock->close();
			if (!$stockRow || (int)$stockRow['pti-Id'] !== $productId) {
				throw new PosVoidException('A stock batch for this item is no longer available.', 409);
			}
			if ($slice['restock'] > 0 && (int)$stockRow['isExpired'] === 1) {
				throw new PosVoidException('A stock batch has expired. Record its quantity as wasted instead.', 409);
			}
			if ($slice['restock'] > 0) {
				$stockUpdate = $conn->prepare(
					'UPDATE `m10.2-product_stock`
					 SET `pts-Quantity` = COALESCE(`pts-Quantity`, 0) + ?
					 WHERE `pts-Id` = ?'
				);
				$stockUpdate->bind_param('ii', $slice['restock'], $stockId);
				$stockUpdate->execute();
				if ($stockUpdate->affected_rows !== 1) {
					throw new PosVoidException('Restocked quantity could not be saved.', 409);
				}
				$stockUpdate->close();
			}
			insertVoidedWaste(
				$conn,
				$stockId,
				$voidId,
				$slice['waste'],
				$unitPriceCents
			);
		}
	}

	$voidedOrderStatus = 'Voided Order';
	$statusUpdate = $conn->prepare(
		'UPDATE `m11.1-transaction_master`
		 SET `tm-Status` = ?
		 WHERE `tm-TransactionId` = ? AND `tm-Status` = ?'
	);
	$completedStatus = 'Completed';
	$statusUpdate->bind_param('sis', $voidedOrderStatus, $transactionId, $completedStatus);
	$statusUpdate->execute();
	if ($statusUpdate->affected_rows !== 1) {
		throw new PosVoidException('The transaction status changed before the void was saved.', 409);
	}
	$statusUpdate->close();

	$conn->commit();
	$transactionStarted = false;
	respondWithVoidJson(200, [
		'message' => 'Transaction voided and recorded.',
		'status' => 'Voided Order',
	]);
} catch (JsonException $exception) {
	if ($transactionStarted) {
		$conn->rollback();
	}
	respondWithVoidJson(400, ['message' => 'The void request is not valid JSON.']);
} catch (PosVoidException $exception) {
	if ($transactionStarted) {
		$conn->rollback();
	}
	respondWithVoidJson($exception->httpStatus, ['message' => $exception->getMessage()]);
} catch (mysqli_sql_exception $exception) {
	if ($transactionStarted) {
		$conn->rollback();
	}
	error_log('POS void database error: ' . $exception->getMessage());
	respondWithVoidJson(500, ['message' => 'The void could not be saved. No stock changes were made. Please try again.']);
} catch (RuntimeException $exception) {
	if ($transactionStarted) {
		$conn->rollback();
	}
	error_log('POS void data error: ' . $exception->getMessage());
	respondWithVoidJson(500, ['message' => 'The void could not be completed. Please review the saved transaction data.']);
}