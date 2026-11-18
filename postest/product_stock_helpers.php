<?php
function recordExpiredProductStock(mysqli $conn, ?int $productId = null): void
{
	$transactionStarted = false;
	try {
		$conn->begin_transaction();
		$transactionStarted = true;

		if ($productId === null) {
			$expiredStock = $conn->query(
				'SELECT s.`pts-Id`, s.`pts-Quantity`, p.`pti-Price`
				 FROM `m10.2-product_stock` s
				 JOIN `m10.1-product_information` p ON p.`pti-Id` = s.`pti-Id`
				 WHERE s.`pts-Expiry` <= CURDATE() AND s.`pts-Quantity` > 0
				 ORDER BY s.`pts-Expiry` ASC, s.`pts-Id` ASC
				 FOR UPDATE'
			);
		} else {
			$expiredStock = $conn->prepare(
				'SELECT s.`pts-Id`, s.`pts-Quantity`, p.`pti-Price`
				 FROM `m10.2-product_stock` s
				 JOIN `m10.1-product_information` p ON p.`pti-Id` = s.`pti-Id`
				 WHERE s.`pti-Id` = ? AND s.`pts-Expiry` <= CURDATE() AND s.`pts-Quantity` > 0
				 ORDER BY s.`pts-Expiry` ASC, s.`pts-Id` ASC
				 FOR UPDATE'
			);
			$expiredStock->bind_param('i', $productId);
			$expiredStock->execute();
			$expiredStock = $expiredStock->get_result();
		}

		$stockRows = [];
		while ($stock = $expiredStock->fetch_assoc()) {
			$stockRows[] = $stock;
		}

		foreach ($stockRows as $stock) {
			$stockId = (int)$stock['pts-Id'];
			$expiredQuantity = (int)$stock['pts-Quantity'];
			$wasteType = 'Expired';
			$totalCost = number_format((float)$stock['pti-Price'] * $expiredQuantity, 2, '.', '');

			$wasteInsert = $conn->prepare(
				'INSERT INTO `m10.3-product_waste`
				 (`pts-Id`, `ptw-WastedQty`, `ptw-WasteType`, `ptw-TotalCost`)
				 VALUES (?, ?, ?, ?)'
			);
			$wasteInsert->bind_param('iiss', $stockId, $expiredQuantity, $wasteType, $totalCost);
			$wasteInsert->execute();
			$wasteInsert->close();

			$stockUpdate = $conn->prepare(
				'UPDATE `m10.2-product_stock`
				 SET `pts-Quantity` = 0
				 WHERE `pts-Id` = ? AND `pts-Quantity` = ? AND `pts-Expiry` <= CURDATE()'
			);
			$stockUpdate->bind_param('ii', $stockId, $expiredQuantity);
			$stockUpdate->execute();
			if ($stockUpdate->affected_rows !== 1) {
				$stockUpdate->close();
				throw new RuntimeException('An expired stock batch changed before it could be recorded as waste.');
			}
			$stockUpdate->close();
		}

		$conn->commit();
		$transactionStarted = false;
	} catch (mysqli_sql_exception | RuntimeException $exception) {
		if ($transactionStarted) {
			$conn->rollback();
		}
		throw $exception;
	}
}
