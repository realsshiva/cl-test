<?php
function recordExpiredProductStock(mysqli $conn, ?int $productId = null): void
{
	$transactionStarted = false;
	try {
		$conn->begin_transaction();
		$transactionStarted = true;

		if ($productId === null) {
			$expiredStock = $conn->query(
				'SELECT `pts-Id`, `pts-Quantity`
				 FROM `m10.2-product_stock`
				 WHERE `pts-Expiry` <= CURDATE() AND `pts-Quantity` > 0
				 ORDER BY `pts-Expiry` ASC, `pts-Id` ASC
				 FOR UPDATE'
			);
		} else {
			$expiredStock = $conn->prepare(
				'SELECT `pts-Id`, `pts-Quantity`
				 FROM `m10.2-product_stock`
				 WHERE `pti-Id` = ? AND `pts-Expiry` <= CURDATE() AND `pts-Quantity` > 0
				 ORDER BY `pts-Expiry` ASC, `pts-Id` ASC
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

			$wasteInsert = $conn->prepare(
				'INSERT INTO `m10.3-product_waste` (`pts-Id`, `ptw-ExpiredQty`)
				 VALUES (?, ?)'
			);
			$wasteInsert->bind_param('ii', $stockId, $expiredQuantity);
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
