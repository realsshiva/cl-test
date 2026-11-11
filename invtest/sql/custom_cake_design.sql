-- Design details for cakes made in the Cake Builder (cakebuilder.php).
-- One row per production batch. Run once:
--   D:\Xampp\mysql\bin\mysql.exe -uroot cl-inv_test < invtest/sql/custom_cake_design.sql

CREATE TABLE IF NOT EXISTS `m3.1-custom_cake_design` (
  `ccd-Id` int(11) NOT NULL AUTO_INCREMENT,
  `prodbatch-Id` int(11) NOT NULL,
  `ccd-BaseColor` varchar(7) NOT NULL,
  `ccd-AccentColor` varchar(7) NOT NULL,
  `ccd-Toppings` varchar(255) NOT NULL DEFAULT '',
  `ccd-Message` varchar(40) NOT NULL DEFAULT '',
  `ccd-CustomerName` varchar(60) DEFAULT NULL,
  `ccd-PickupDate` date DEFAULT NULL,
  `ccd-Notes` text DEFAULT NULL,
  `ccd-CreatedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`ccd-Id`),
  UNIQUE KEY `uq_ccd_prodbatch` (`prodbatch-Id`),
  CONSTRAINT `fk_ccd_prodbatch` FOREIGN KEY (`prodbatch-Id`) REFERENCES `m3-production_batch` (`prodbatch-Id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
