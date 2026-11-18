<?php
require_once __DIR__ . '/../invtest/db.php';

final class CounterTransactionException extends RuntimeException
{
	public int $httpStatus;

	public function __construct(string $message, int $httpStatus = 422)
	{
		parent::__construct($message);
		$this->httpStatus = $httpStatus;
	}
}

function respondWithJson(int $status, array $data): void
{
	http_response_code($status);
	header('Content-Type: application/json; charset=utf-8');
	echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	exit;
}

function requestInteger($value, string $label, int $minimum = 0): int
{
	if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
		throw new CounterTransactionException($label . ' must be a whole number.');
	}
	$number = (int)$value;
	if ($number < $minimum || $number > 2147483647) {
		throw new CounterTransactionException($label . ' is outside the allowed range.');
	}
	return $number;
}

function decimalToCents($value): int
{
	$value = (string)$value;
	if (!preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $value, $matches)) {
		throw new CounterTransactionException('A monetary value is invalid.');
	}
	if (strlen($matches[1]) > 8) {
		throw new CounterTransactionException('A monetary value exceeds the supported limit.');
	}
	$fraction = str_pad($matches[2] ?? '', 2, '0');
	return ((int)$matches[1] * 100) + (int)$fraction;
}

function centsToDecimal(int $cents): string
{
	return intdiv($cents, 100) . '.' . str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT);
}

function normalizeSeniorIdentities($entries, string $label, array &$allIdentities): array
{
	if (!is_array($entries) || !array_is_list($entries)) {
		throw new CounterTransactionException($label . ' must be a list of SC credentials.');
	}

	$identities = [];
	$seen = [];
	foreach ($entries as $entry) {
		if (!is_array($entry)) {
			throw new CounterTransactionException($label . ' contains an invalid SC credential.');
		}
		$idInput = $entry['SCId'] ?? null;
		$nameInput = $entry['SCName'] ?? null;
		if ((!is_string($idInput) && !is_int($idInput)) || !is_string($nameInput)) {
			throw new CounterTransactionException($label . ' contains an invalid SC credential.');
		}
		$id = trim((string)$idInput);
		$name = trim($nameInput);
		if ($id === '' || strlen($id) > 100 || $name === '' || strlen($name) > 150) {
			throw new CounterTransactionException('Enter an SC ID number and name for every senior citizen.');
		}
		$key = strtoupper($id);
		if (isset($seen[$key])) {
			throw new CounterTransactionException('An SC ID may only be entered once for the same item.');
		}
		$seen[$key] = true;
		if (isset($allIdentities[$key]) && $allIdentities[$key]['SCName'] !== $name) {
			throw new CounterTransactionException('The same SC ID has different names in this transaction.');
		}
		$allIdentities[$key] = ['SCId' => $id, 'SCName' => $name];
		$identities[] = ['SCId' => $id, 'SCName' => $name];
	}
	return $identities;
}

function parseTenderedCents($value): int
{
	if (!is_string($value) && !is_int($value)) {
		throw new CounterTransactionException('Enter a valid amount tendered.');
	}
	$value = trim((string)$value);
	if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $value)) {
		throw new CounterTransactionException('Enter a valid amount tendered.');
	}
	return decimalToCents($value);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
	respondWithJson(405, ['message' => 'Use POST to complete a transaction.']);
}

$transactionStarted = false;
$invoiceLockAcquired = false;
$response = null;
$status = 200;

try {
	$payload = json_decode(file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
	if (!is_array($payload)) {
		throw new CounterTransactionException('The transaction request is invalid.');
	}

	$invoiceNo = $payload['invoiceNo'] ?? '';
	if (!is_string($invoiceNo) || !preg_match('/^OR-(\d{8})-(\d{3,})$/', $invoiceNo, $invoiceParts)) {
		throw new CounterTransactionException('The invoice number is invalid.');
	}
	$invoiceDay = $invoiceParts[1];
	$submittedSequence = (int)$invoiceParts[2];
	$paymentMode = $payload['paymentMode'] ?? null;
	if (!in_array($paymentMode, ['Cash', 'E-Wallet', 'Bank'], true)) {
		throw new CounterTransactionException('Choose a valid payment mode.');
	}
	$referenceNo = null;
	if ($paymentMode !== 'Cash') {
		$referenceInput = $payload['referenceNo'] ?? null;
		if (!is_string($referenceInput)) {
			throw new CounterTransactionException('Enter a reference number for this payment.');
		}
		$referenceNo = trim($referenceInput);
		if ($referenceNo === '' || strlen($referenceNo) > 50) {
			throw new CounterTransactionException('Enter a reference number of no more than 50 characters.');
		}
	}
	$discountMode = $payload['discountMode'] ?? null;
	if (!in_array($discountMode, ['None', 'Itemized', 'Shared Bill'], true)) {
		throw new CounterTransactionException('Choose a valid SC discount mode.');
	}

	$cartInput = $payload['cart'] ?? null;
	if (!is_array($cartInput) || !array_is_list($cartInput) || count($cartInput) === 0) {
		throw new CounterTransactionException('The cart is empty.');
	}

	$items = [];
	$allIdentities = [];
	$cartQuantity = 0;
	$itemizedQuantity = 0;
	$requestedQuantityByProduct = [];
	foreach ($cartInput as $cartEntry) {
		if (!is_array($cartEntry)) {
			throw new CounterTransactionException('The cart contains an invalid item.');
		}
		$productId = requestInteger($cartEntry['productId'] ?? null, 'Product ID', 1);
		$quantity = requestInteger($cartEntry['quantity'] ?? null, 'Quantity', 1);
		$currentProductQuantity = $requestedQuantityByProduct[$productId] ?? 0;
		if ($currentProductQuantity > 2147483647 - $quantity) {
			throw new CounterTransactionException('The total quantity for a product exceeds the supported limit.');
		}
		$requestedQuantityByProduct[$productId] = $currentProductQuantity + $quantity;

		$discountedQuantity = 0;
		$assignedSc = [];
		if ($discountMode === 'Itemized') {
			$discountedQuantity = requestInteger($cartEntry['discountedQuantity'] ?? 0, 'Discounted quantity');
			if ($discountedQuantity > $quantity) {
				throw new CounterTransactionException('The discounted quantity cannot exceed the item quantity.');
			}
			$assignedSc = normalizeSeniorIdentities($cartEntry['assignedSc'] ?? [], 'Itemized SC credentials', $allIdentities);
			if (($discountedQuantity > 0) !== (count($assignedSc) > 0)
				|| count($assignedSc) > $discountedQuantity) {
				throw new CounterTransactionException('Each tagged item needs credentials for at least one SC, no more than the tagged quantity.');
			}
			$itemizedQuantity += $discountedQuantity;
		} elseif ($discountMode === 'None') {
			if (requestInteger($cartEntry['discountedQuantity'] ?? 0, 'Discounted quantity') !== 0
				|| !empty($cartEntry['assignedSc'])) {
				throw new CounterTransactionException('SC credentials were submitted without an active discount.');
			}
		} elseif (!empty($cartEntry['assignedSc']) || requestInteger($cartEntry['discountedQuantity'] ?? 0, 'Discounted quantity') !== 0) {
			throw new CounterTransactionException('Itemized SC tags cannot be combined with a shared bill discount.');
		}

		$cartQuantity += $quantity;
		$items[] = [
			'productId' => $productId,
			'quantity' => $quantity,
			'discountedQuantity' => $discountedQuantity,
			'assignedSc' => $assignedSc,
		];
	}

	$sharedSc = [];
	$totalPax = 0;
	if ($discountMode === 'Shared Bill') {
		$shared = $payload['shared'] ?? null;
		if (!is_array($shared)) {
			throw new CounterTransactionException('Enter the shared bill headcount and SC credentials.');
		}
		$totalPax = requestInteger($shared['totalPax'] ?? null, 'Total Pax', 1);
		$sharedSc = normalizeSeniorIdentities($shared['seniorCitizens'] ?? null, 'Shared bill SC credentials', $allIdentities);
		if (count($sharedSc) === 0 || count($sharedSc) > $totalPax) {
			throw new CounterTransactionException('The SC count must be between one and Total Pax.');
		}
	} elseif ($discountMode === 'Itemized') {
		if (count($allIdentities) === 0) {
			throw new CounterTransactionException('Tag at least one cart item for an SC discount.');
		}
		$totalPax = count($allIdentities) + $cartQuantity - $itemizedQuantity;
	} elseif (!empty($payload['shared'])) {
		throw new CounterTransactionException('Shared SC credentials were submitted without a shared bill discount.');
	}
	if ($totalPax > 2147483647) {
		throw new CounterTransactionException('Total Pax exceeds the supported limit.');
	}

	$databaseDayResult = $conn->query("SELECT DATE_FORMAT(CURDATE(), '%Y%m%d') AS invoice_day");
	$databaseDay = (string)$databaseDayResult->fetch_assoc()['invoice_day'];
	if ($invoiceDay !== $databaseDay) {
		throw new CounterTransactionException('The invoice date has changed. Refresh the counter and try again.', 409);
	}

	$lockName = 'pos_invoice_' . $databaseDay;
	$invoiceLock = $conn->prepare('SELECT GET_LOCK(?, 10) AS lock_acquired');
	$invoiceLock->bind_param('s', $lockName);
	$invoiceLock->execute();
	$lockAcquired = $invoiceLock->get_result()->fetch_assoc();
	$invoiceLock->close();
	if ((int)($lockAcquired['lock_acquired'] ?? 0) !== 1) {
		throw new CounterTransactionException('Could not reserve an invoice number. Please try again.', 409);
	}
	$invoiceLockAcquired = true;

	usort($items, static function (array $left, array $right): int {
		return $left['productId'] <=> $right['productId'];
	});
	$conn->begin_transaction();
	$transactionStarted = true;

	$sequenceQuery = $conn->prepare(
		"SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(`tm-InvoiceNo`, '-', -1) AS UNSIGNED)), 0) AS last_sequence
		 FROM `m11.1-transaction_master`
		 WHERE `tm-InvoiceNo` LIKE CONCAT('OR-', ?, '-%')
		 FOR UPDATE"
	);
	$sequenceQuery->bind_param('s', $databaseDay);
	$sequenceQuery->execute();
	$lastSequence = (int)$sequenceQuery->get_result()->fetch_assoc()['last_sequence'];
	$sequenceQuery->close();
	if ($submittedSequence !== $lastSequence + 1) {
		throw new CounterTransactionException('The invoice number is no longer current. Refresh the counter and try again.', 409);
	}

	$subtotalCents = 0;
	$itemizedDeductionCents = 0;
	foreach ($items as &$item) {
		$productQuery = $conn->prepare(
			'SELECT `pti-Name`, `pti-Variant`, `pti-Price`
			 FROM `m10.1-product_information`
			 WHERE `pti-Id` = ?
			 FOR UPDATE'
		);
		$productQuery->bind_param('i', $item['productId']);
		$productQuery->execute();
		$product = $productQuery->get_result()->fetch_assoc();
		$productQuery->close();
		if (!$product) {
			throw new CounterTransactionException('A product in the cart no longer exists.', 409);
		}

		$item['name'] = (string)$product['pti-Name'];
		if ($product['pti-Variant'] !== null && (string)$product['pti-Variant'] !== '') {
			$item['name'] .= ' - ' . (string)$product['pti-Variant'];
		}
		if (mb_strlen($item['name'], 'UTF-8') > 50) {
			throw new CounterTransactionException('A product name is too long to save in the order items table.');
		}
		$item['unitPriceCents'] = decimalToCents($product['pti-Price']);
		if ($item['unitPriceCents'] > 0 && $item['quantity'] > intdiv(9999999999, $item['unitPriceCents'])) {
			throw new CounterTransactionException('A cart line exceeds the supported transaction amount.');
		}
		$item['lineTotalCents'] = $item['unitPriceCents'] * $item['quantity'];
		if ($subtotalCents > 9999999999 - $item['lineTotalCents']) {
			throw new CounterTransactionException('The cart exceeds the supported transaction amount.');
		}
		$subtotalCents += $item['lineTotalCents'];

		$stockQuery = $conn->prepare(
			'SELECT `pts-Id`, `pts-Quantity`
			 FROM `m10.2-product_stock`
			 WHERE `pti-Id` = ?
			   AND (`pts-Expiry` IS NULL OR `pts-Expiry` > CURDATE())
			   AND `pts-Quantity` > 0
			 ORDER BY `pts-Expiry` IS NULL, `pts-Expiry` ASC, `pts-Id` ASC
			 FOR UPDATE'
		);
		$stockQuery->bind_param('i', $item['productId']);
		$stockQuery->execute();
		$stockResult = $stockQuery->get_result();
		$item['stockBatches'] = [];
		$available = 0;
		while ($stock = $stockResult->fetch_assoc()) {
			$batch = ['id' => (int)$stock['pts-Id'], 'quantity' => (int)$stock['pts-Quantity']];
			$item['stockBatches'][] = $batch;
			$available += $batch['quantity'];
		}
		$stockQuery->close();
		if ($available < $requestedQuantityByProduct[$item['productId']]) {
			throw new CounterTransactionException('Not enough stock remains for ' . $item['name'] . '. Refresh the counter and adjust the cart.', 409);
		}

		if ($discountMode === 'Itemized' && $item['discountedQuantity'] > 0) {
			$netUnitCents = (int)round($item['unitPriceCents'] / 1.12, 0, PHP_ROUND_HALF_UP);
			$discountedNetUnitCents = (int)round($netUnitCents * 0.20, 0, PHP_ROUND_HALF_UP);
			$payableUnitCents = $netUnitCents - $discountedNetUnitCents;
			$itemizedDeductionCents += ($item['unitPriceCents'] - $payableUnitCents) * $item['discountedQuantity'];
		}
	}
	unset($item);

	$scCount = count($allIdentities);
	$scIdentities = array_values($allIdentities);
	$discountCents = 0;
	if ($discountMode === 'Itemized') {
		$discountCents = $itemizedDeductionCents;
	} elseif ($discountMode === 'Shared Bill') {
		$eligibleGrossCents = (int)round($subtotalCents * $scCount / $totalPax, 0, PHP_ROUND_HALF_UP);
		$netCents = (int)round($eligibleGrossCents / 1.12, 0, PHP_ROUND_HALF_UP);
		$seniorDiscountCents = (int)round($netCents * 0.20, 0, PHP_ROUND_HALF_UP);
		$discountCents = $eligibleGrossCents - ($netCents - $seniorDiscountCents);
	}
	$amountDueCents = $subtotalCents - $discountCents;
	if ($paymentMode === 'Cash') {
		$amountTenderedCents = parseTenderedCents($payload['amountTendered'] ?? null);
		if ($amountTenderedCents < $amountDueCents) {
			throw new CounterTransactionException('The amount tendered must cover the total amount due.');
		}
		$changeCents = $amountTenderedCents - $amountDueCents;
		$changeDecimal = centsToDecimal($changeCents);
	} else {
		$amountTenderedCents = $amountDueCents;
		$changeDecimal = null;
	}
	$storedMode = $discountMode;
	$transactionStatus = 'Completed';
	$scArrayJson = json_encode($scIdentities, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

	$masterInsert = $conn->prepare(
		'INSERT INTO `m11.1-transaction_master`
		 (`tm-InvoiceNo`, `tm-GrossPrice`, `tm-ScDiscount`, `tm-AmountDue`,
		  `tm-AmountTendered`, `tm-Change`, `tm-DiscountMode`, `tm-PaymentMode`,
		  `tm-TotalPax`, `tm-ScCount`, `tm-ScIdArray`, `tm-ReferenceNo`, `tm-Status`)
		 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
	);
	$grossDecimal = centsToDecimal($subtotalCents);
	$discountDecimal = centsToDecimal($discountCents);
	$dueDecimal = centsToDecimal($amountDueCents);
	$tenderDecimal = centsToDecimal($amountTenderedCents);
	$masterInsert->bind_param(
		'ssssssssiisss',
		$invoiceNo,
		$grossDecimal,
		$discountDecimal,
		$dueDecimal,
		$tenderDecimal,
		$changeDecimal,
		$storedMode,
		$paymentMode,
		$totalPax,
		$scCount,
		$scArrayJson,
		$referenceNo,
		$transactionStatus
	);
	$masterInsert->execute();
	$transactionId = (int)$conn->insert_id;
	$masterInsert->close();

	$orderInsert = $conn->prepare(
		'INSERT INTO `m11.2-order_items`
		 (`tm-TransactionId`, `oi-ProductId`, `oi-ProductName`, `oi-Quantity`,
		  `oi-UnitPrice`, `oi-isDiscounted`, `oi-AssignedScId`, `oi-LineTotal`,
		  `oi-StockAllocations`)
		 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
	);
	$stockUpdate = $conn->prepare(
		'UPDATE `m10.2-product_stock`
		 SET `pts-Quantity` = `pts-Quantity` - ?
		 WHERE `pts-Id` = ? AND `pts-Quantity` >= ?'
	);
	$updatedStocks = [];
	$deductedProducts = [];
	$remainingAllocationsByProduct = [];

	foreach ($items as $item) {
		$productId = $item['productId'];
		if (!isset($remainingAllocationsByProduct[$productId])) {
			$remainingAllocationsByProduct[$productId] = [];
			foreach ($item['stockBatches'] as $batch) {
				$remainingAllocationsByProduct[$productId][$batch['id']] = $batch['quantity'];
			}
		}
		$itemAllocations = [];
		$quantityToAllocate = $item['quantity'];
		foreach ($remainingAllocationsByProduct[$productId] as $batchId => &$batchQuantity) {
			if ($quantityToAllocate === 0) {
				break;
			}
			$allocated = min($quantityToAllocate, $batchQuantity);
			if ($allocated > 0) {
				$itemAllocations[] = ['stockId' => (int)$batchId, 'quantity' => $allocated];
				$batchQuantity -= $allocated;
				$quantityToAllocate -= $allocated;
			}
		}
		unset($batchQuantity);
		if ($quantityToAllocate !== 0) {
			throw new CounterTransactionException('Stock allocations changed during checkout. Please try again.', 409);
		}
		$stockAllocationsJson = json_encode($itemAllocations, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

		$isDiscounted = $discountMode === 'Shared Bill'
			? 1
			: ($discountMode === 'Itemized' && $item['discountedQuantity'] > 0 ? 1 : 0);
		$assignedSc = $discountMode === 'Shared Bill'
			? $sharedSc
			: ($discountMode === 'Itemized' ? $item['assignedSc'] : []);
		$assignedScJson = json_encode($assignedSc, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
		$unitPriceDecimal = centsToDecimal($item['unitPriceCents']);
		$lineTotalDecimal = centsToDecimal($item['lineTotalCents']);
		$quantity = $item['quantity'];
		$productName = $item['name'];
		$orderInsert->bind_param(
			'iisisisss',
			$transactionId,
			$productId,
			$productName,
			$quantity,
			$unitPriceDecimal,
			$isDiscounted,
			$assignedScJson,
			$lineTotalDecimal,
			$stockAllocationsJson
		);
		$orderInsert->execute();

		if (!isset($deductedProducts[$productId])) {
			$deductedProducts[$productId] = true;
			$remainingToDeduct = $requestedQuantityByProduct[$productId];
			$remainingStock = array_sum(array_column($item['stockBatches'], 'quantity'));
			foreach ($item['stockBatches'] as $batch) {
				if ($remainingToDeduct === 0) {
					break;
				}
				$deduct = min($remainingToDeduct, $batch['quantity']);
				$batchId = $batch['id'];
				$stockUpdate->bind_param('iii', $deduct, $batchId, $deduct);
				$stockUpdate->execute();
				if ($stockUpdate->affected_rows !== 1) {
					throw new CounterTransactionException('Stock changed during checkout. Please try again.', 409);
				}
				$remainingToDeduct -= $deduct;
				$remainingStock -= $deduct;
			}
			if ($remainingToDeduct !== 0) {
				throw new CounterTransactionException('Stock changed during checkout. Please try again.', 409);
			}
			$updatedStocks[(string)$productId] = $remainingStock;
		}
	}
	$orderInsert->close();
	$stockUpdate->close();

	$conn->commit();
	$transactionStarted = false;
	$response = [
		'message' => 'Transaction completed.',
		'invoiceNo' => $invoiceNo,
		'updatedStocks' => $updatedStocks,
	];
} catch (JsonException $exception) {
	$status = 400;
	$response = ['message' => 'The transaction request is not valid JSON.'];
} catch (CounterTransactionException $exception) {
	$status = $exception->httpStatus;
	$response = ['message' => $exception->getMessage()];
} catch (mysqli_sql_exception $exception) {
	error_log('Counter transaction database error: ' . $exception->getMessage());
	$status = 500;
	$response = ['message' => 'The transaction could not be saved. No stock was deducted. Please try again.'];
} catch (RuntimeException $exception) {
	error_log('Counter transaction error: ' . $exception->getMessage());
	$status = 500;
	$response = ['message' => 'The transaction could not be completed. Please try again.'];
} finally {
	if ($transactionStarted) {
		$conn->rollback();
	}
	if ($invoiceLockAcquired) {
		$releaseLock = $conn->prepare('SELECT RELEASE_LOCK(?)');
		$releaseLock->bind_param('s', $lockName);
		$releaseLock->execute();
		$releaseLock->close();
	}
}

respondWithJson($status, $response ?? ['message' => 'The transaction could not be completed.']);
