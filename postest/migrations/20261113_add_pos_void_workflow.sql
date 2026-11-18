ALTER TABLE `m11.2-order_items`
	ADD COLUMN `oi-StockAllocations` LONGTEXT
		CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL
		CHECK (`oi-StockAllocations` IS NULL OR json_valid(`oi-StockAllocations`));

ALTER TABLE `m12-void_master`
	DROP FOREIGN KEY `fk_oi-OrderId`,
	DROP INDEX `fk_oi-OrderId`,
	CHANGE COLUMN `oi-OrderId` `tm-ItemId` int(11) DEFAULT NULL,
	ADD KEY `fk_tm-ItemId` (`tm-ItemId`),
	ADD CONSTRAINT `fk_void_tm-ItemId`
		FOREIGN KEY (`tm-ItemId`) REFERENCES `m11.2-order_items` (`oi-OrderId`),
	ADD KEY `idx_vm-TransactionDate` (`vm-TransactionDate`);

ALTER TABLE `m11.1-transaction_master`
	MODIFY COLUMN `tm-TransactionDate` timestamp NOT NULL DEFAULT current_timestamp();
