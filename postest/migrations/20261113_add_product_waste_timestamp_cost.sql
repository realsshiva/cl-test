ALTER TABLE `m10.3-product_waste`
	ADD COLUMN `ptw-Timestamp` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	ADD COLUMN `ptw-TotalCost` DECIMAL(10,2) NOT NULL DEFAULT 0.00;

UPDATE `m10.3-product_waste` w
JOIN `m10.2-product_stock` s ON s.`pts-Id` = w.`pts-Id`
JOIN `m10.1-product_information` p ON p.`pti-Id` = s.`pti-Id`
SET w.`ptw-Timestamp` = TIMESTAMP(COALESCE(s.`pts-Expiry`, CURDATE())),
    w.`ptw-TotalCost` = ROUND(p.`pti-Price` * w.`ptw-WastedQty`, 2);
