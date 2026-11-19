-- cl-inv_test: the whole database (every table, including the Custom Cake / Cake Builder tables:
-- m22-recipe_master, m222-recipe_variant2/3 and m3.1-custom_cake_design).
-- Exported from the live database on 2026-11-19. Test cakes (production batches 15-19, "TEST - please ignore")
-- and their history rows were left out.
-- Import into an empty database: mysql -u root cl-inv_test < cl-inv_test_1117.sql
-- (existing tables with the same names are replaced).

-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: cl-inv_test
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `m1-ingredient_category`
--

DROP TABLE IF EXISTS `m1-ingredient_category`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `m1-ingredient_category` (
  `ingcat-Id` varchar(10) NOT NULL,
  `ingcat-Name` text NOT NULL,
  PRIMARY KEY (`ingcat-Id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `m1-ingredient_category`
--

INSERT INTO `m1-ingredient_category` (`ingcat-Id`, `ingcat-Name`) VALUES
('ICAT-1','Dry'),
('ICAT-2','Dairy'),
('ICAT-3','Liquid'),
('ICAT-4','Fruit'),
('ICAT-5','Topping');

--
-- Table structure for table `m10-product_category`
--

DROP TABLE IF EXISTS `m10-product_category`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `m10-product_category` (
  `ptc-Id` int(11) NOT NULL AUTO_INCREMENT,
  `reccat-Id` varchar(15) DEFAULT NULL,
  `ptc-Category` varchar(30) NOT NULL,
  PRIMARY KEY (`ptc-Id`),
  KEY `fkp_reccat-Id` (`reccat-Id`),
  CONSTRAINT `fkp_reccat-Id` FOREIGN KEY (`reccat-Id`) REFERENCES `m2-recipe_category` (`reccat-Id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `m10-product_category`
--

INSERT INTO `m10-product_category` (`ptc-Id`, `reccat-Id`, `ptc-Category`) VALUES
(3,'CAT-VIE','Viennoiserie'),
(4,NULL,'Ice Cream');

--
-- Table structure for table `m10.1-product_information`
--

DROP TABLE IF EXISTS `m10.1-product_information`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `m10.1-product_information` (
  `pti-Id` int(11) NOT NULL AUTO_INCREMENT,
  `ptc-Id` int(11) DEFAULT NULL,
  `pti-Photo` varchar(255) DEFAULT NULL,
  `pti-Name` varchar(50) NOT NULL,
  `pti-Variant` varchar(50) DEFAULT NULL,
  `pti-Price` decimal(10,2) NOT NULL,
  PRIMARY KEY (`pti-Id`),
  KEY `fk_ptc-Id` (`ptc-Id`),
  CONSTRAINT `fk_ptc-Id` FOREIGN KEY (`ptc-Id`) REFERENCES `m10-product_category` (`ptc-Id`)
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `m10.1-product_information`
--

INSERT INTO `m10.1-product_information` (`pti-Id`, `ptc-Id`, `pti-Photo`, `pti-Name`, `pti-Variant`, `pti-Price`) VALUES
(3,3,NULL,'Leche Flan Danish','Standard',250.00),
(4,3,NULL,'Double Baked Almond Croissant','Standard',250.00),
(5,3,NULL,'Double Pain Au Chocolat Croissant','Standard',250.00),
(6,3,NULL,'Ham and Cheese Croissant','Standard',250.00),
(7,3,NULL,'Plain Butter Croissant','Standard',250.00),
(8,4,NULL,'Cornetto','Rocky Road',20.00),
(9,4,NULL,'Cornetto','Strawberry',25.00),
(10,4,NULL,'Cornetto','Vanilla',25.00),
(11,4,NULL,'Cornetto','Matcha',25.00);

--
-- Table structure for table `m10.2-product_stock`
--

DROP TABLE IF EXISTS `m10.2-product_stock`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `m10.2-product_stock` (
  `pts-Id` int(11) NOT NULL AUTO_INCREMENT,
  `pti-Id` int(11) NOT NULL,
  `pts-Quantity` int(11) DEFAULT NULL,
  `pts-Expiry` date DEFAULT NULL,
  PRIMARY KEY (`pts-Id`),
  KEY `fk_pti-Id` (`pti-Id`),
  CONSTRAINT `fk_pti-Id` FOREIGN KEY (`pti-Id`) REFERENCES `m10.1-product_information` (`pti-Id`)
) ENGINE=InnoDB AUTO_INCREMENT=24 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `m10.2-product_stock`
--

INSERT INTO `m10.2-product_stock` (`pts-Id`, `pti-Id`, `pts-Quantity`, `pts-Expiry`) VALUES
(3,4,0,'2026-11-30'),
(4,4,0,'2026-11-30'),
(5,9,0,'2026-11-30'),
(6,9,0,'2026-11-05'),
(7,10,0,'2026-11-30'),
(8,11,0,'2026-11-30'),
(9,8,0,'2026-11-30'),
(10,11,0,'2026-11-30'),
(17,10,0,NULL),
(18,10,23,'2026-12-08'),
(19,11,9,'2026-11-30'),
(20,8,10,'2026-11-30'),
(21,9,10,'2026-11-30'),
(22,10,9,'2026-11-30'),
(23,4,1,'2026-11-30');

--
-- Table structure for table `m10.3-product_waste`
--

DROP TABLE IF EXISTS `m10.3-product_waste`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `m10.3-product_waste` (
  `ptw-Id` int(11) NOT NULL AUTO_INCREMENT,
  `pts-Id` int(11) NOT NULL,
  `vm-Id` int(11) DEFAULT NULL,
  `ptw-WastedQty` int(11) NOT NULL,
  `ptw-WasteType` text NOT NULL,
  `ptw-Timestamp` datetime NOT NULL DEFAULT current_timestamp(),
  `ptw-TotalCost` decimal(10,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`ptw-Id`),
  KEY `fk_pts-Id` (`pts-Id`),
  KEY `fk_vm-Id` (`vm-Id`),
  CONSTRAINT `fk_pts-Id` FOREIGN KEY (`pts-Id`) REFERENCES `m10.2-product_stock` (`pts-Id`),
  CONSTRAINT `fk_vm-Id` FOREIGN KEY (`vm-Id`) REFERENCES `m12-void_master` (`vm-Id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `m10.3-product_waste`
--

INSERT INTO `m10.3-product_waste` (`ptw-Id`, `pts-Id`, `vm-Id`, `ptw-WastedQty`, `ptw-WasteType`, `ptw-Timestamp`, `ptw-TotalCost`) VALUES
(2,6,NULL,1,'Expired','2026-11-05 00:00:00',25.00),
(6,17,11,1,'Voided Waste','2026-11-13 03:43:22',25.00),
(7,10,16,1,'Voided Waste','2026-11-18 13:59:07',25.00),
(8,19,20,1,'Voided Waste','2026-11-19 11:40:11',25.00);

--
-- Table structure for table `m11-ingredient_master`
--

DROP TABLE IF EXISTS `m11-ingredient_master`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `m11-ingredient_master` (
  `ingmaster-Id` varchar(30) NOT NULL,
  `ingcat-Id` varchar(10) NOT NULL,
  `ingmaster-Name` varchar(50) NOT NULL,
  `ingmaster-UnitType` tinytext NOT NULL,
  `ingmaster-Threshold` decimal(10,2) NOT NULL,
  PRIMARY KEY (`ingmaster-Id`),
  KEY `fk_ingcat-Id` (`ingcat-Id`),
  CONSTRAINT `fk_ingcat-Id` FOREIGN KEY (`ingcat-Id`) REFERENCES `m1-ingredient_category` (`ingcat-Id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `m11-ingredient_master`
--

INSERT INTO `m11-ingredient_master` (`ingmaster-Id`, `ingcat-Id`, `ingmaster-Name`, `ingmaster-UnitType`, `ingmaster-Threshold`) VALUES
('MAS-DAI-BTR','ICAT-2','Butter','kg',10.00),
('MAS-DAI-FM','ICAT-2','Fresh Milk','mL',100.00),
('MAS-DAI-LBS','ICAT-2','Laminating Buttersheet','g',50.00),
('MAS-DAI-PM','ICAT-2','Powdered Milk','kg',5.00),
('MAS-DAI-WPC','ICAT-2','Whipping Cream','L',5.00),
('MAS-DRY-APF','ICAT-1','All Purpose Flour','kg',50.00),
('MAS-DRY-BF','ICAT-1','Bread Flour','kg',25.00),
('MAS-DRY-SLT','ICAT-1','Salt','kg',5.00),
('MAS-DRY-WSR','ICAT-1','White Sugar','kg',25.00),
('MAS-DRY-YST','ICAT-1','Yeast','kg',1.00),
('MAS-FRT-STR','ICAT-4','Strawberry Toppings','g',100.00),
('MAS-TOP-CHC','ICAT-5','White Chocolate','g',100.00);

--
-- Table structure for table `m11.1-transaction_master`
--

DROP TABLE IF EXISTS `m11.1-transaction_master`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `m11.1-transaction_master` (
  `tm-TransactionId` int(11) NOT NULL AUTO_INCREMENT,
  `tm-InvoiceNo` varchar(50) NOT NULL,
  `tm-TransactionDate` timestamp NOT NULL DEFAULT current_timestamp(),
  `tm-GrossPrice` decimal(10,2) NOT NULL,
  `tm-ScDiscount` decimal(10,2) NOT NULL,
  `tm-AmountDue` decimal(10,2) NOT NULL,
  `tm-AmountTendered` decimal(10,2) NOT NULL,
  `tm-Change` decimal(10,2) DEFAULT NULL,
  `tm-DiscountMode` text NOT NULL,
  `tm-TotalPax` int(11) NOT NULL,
  `tm-ScCount` int(11) NOT NULL,
  `tm-ScIdArray` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`tm-ScIdArray`)),
  `tm-PaymentMode` text NOT NULL,
  `tm-ReferenceNo` varchar(50) DEFAULT NULL,
  `tm-Status` text NOT NULL,
  PRIMARY KEY (`tm-TransactionId`)
) ENGINE=InnoDB AUTO_INCREMENT=29 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `m11.1-transaction_master`
--

INSERT INTO `m11.1-transaction_master` (`tm-TransactionId`, `tm-InvoiceNo`, `tm-TransactionDate`, `tm-GrossPrice`, `tm-ScDiscount`, `tm-AmountDue`, `tm-AmountTendered`, `tm-Change`, `tm-DiscountMode`, `tm-TotalPax`, `tm-ScCount`, `tm-ScIdArray`, `tm-PaymentMode`, `tm-ReferenceNo`, `tm-Status`) VALUES
(1,'OR-20261109-001','2026-11-12 17:40:05',25.00,0.00,25.00,100.00,75.00,'None',0,0,'[]','Cash',NULL,'Completed'),
(2,'OR-20261109-002','2026-11-12 17:40:46',85.00,12.85,72.15,100.00,27.85,'Itemized',4,2,'[{\"SCId\":\"02345\",\"SCName\":\"Juana Sample\"},{\"SCId\":\"sc-02341\",\"SCName\":\"Juan Sample\"}]','Cash',NULL,'Completed'),
(3,'OR-20261109-003','2026-11-12 17:41:09',100.00,7.14,92.86,100.00,7.14,'Shared Bill',4,1,'[{\"SCId\":\"sc-02346\",\"SCName\":\"Juano Sample\"}]','Cash',NULL,'Completed'),
(4,'OR-20261109-004','2026-11-12 17:41:35',25.00,0.00,25.00,50.00,25.00,'None',0,0,'[]','Cash',NULL,'Completed'),
(5,'OR-20261109-005','2026-11-12 17:44:20',25.00,0.00,25.00,50.00,25.00,'None',0,0,'[]','Cash',NULL,'Completed'),
(6,'OR-20261109-006','2026-11-12 17:44:20',20.00,0.00,20.00,50.00,30.00,'None',0,0,'[]','Cash',NULL,'Completed'),
(7,'OR-20261109-007','2026-11-12 17:44:20',25.00,0.00,25.00,100.00,75.00,'None',0,0,'[]','Cash',NULL,'Completed'),
(8,'OR-20261109-008','2026-11-12 17:44:20',500.00,71.43,428.57,500.00,71.43,'Shared Bill',2,1,'[{\"SCId\":\"sc-2341\",\"SCName\":\"Juan Sample\"}]','Cash',NULL,'Completed'),
(9,'OR-20261109-009','2026-11-12 17:44:20',25.00,7.14,17.86,50.00,32.14,'Itemized',1,1,'[{\"SCId\":\"sc-02341\",\"SCName\":\"Juan Sample\"}]','Cash',NULL,'Completed'),
(10,'OR-20261109-010','2026-11-12 17:44:20',20.00,5.71,14.29,20.00,5.71,'Itemized',1,1,'[{\"SCId\":\"sc-02341\",\"SCName\":\"Juan Sample\"}]','Cash',NULL,'Completed'),
(11,'OR-20261109-011','2026-11-12 17:44:20',25.00,7.14,17.86,120.00,102.14,'Shared Bill',1,1,'[{\"SCId\":\"SC123\",\"SCName\":\"JUAN\"}]','Cash',NULL,'Completed'),
(12,'OR-20261109-012','2026-11-12 17:44:20',25.00,0.00,25.00,25.00,0.00,'None',0,0,'[]','Cash',NULL,'Completed'),
(13,'OR-20261109-013','2026-11-12 17:44:20',25.00,7.14,17.86,20.00,2.14,'Itemized',1,1,'[{\"SCId\":\"sc-02341\",\"SCName\":\"Juan Sample\"}]','Cash',NULL,'Voided Order'),
(18,'OR-20261113-001','2026-11-12 19:44:40',50.00,0.00,50.00,50.00,NULL,'None',0,0,'[]','E-Wallet','021324553','Completed'),
(19,'OR-20261113-002','2026-11-12 19:44:59',25.00,0.00,25.00,25.00,NULL,'None',0,0,'[]','Bank','232534623','Completed'),
(20,'OR-20261118-001','2026-11-18 05:52:04',25.00,0.00,25.00,200.00,175.00,'None',0,0,'[]','Cash',NULL,'Completed'),
(21,'OR-20261118-002','2026-11-18 05:53:26',25.00,0.00,25.00,25.00,NULL,'None',0,0,'[]','E-Wallet','2554792','Voided Order'),
(22,'OR-20261118-003','2026-11-18 08:35:32',25.00,0.00,25.00,25.00,0.00,'None',0,0,'[]','Cash',NULL,'Completed'),
(23,'OR-20261118-004','2026-11-18 08:36:03',25.00,0.00,25.00,25.00,0.00,'None',0,0,'[]','Cash',NULL,'Completed'),
(24,'OR-20261119-001','2026-11-19 02:22:30',25.00,0.00,25.00,50.00,25.00,'None',0,0,'[]','Cash',NULL,'Completed'),
(25,'OR-20261119-002','2026-11-19 02:27:13',25.00,0.00,25.00,50.00,25.00,'None',0,0,'[]','Cash',NULL,'Completed'),
(26,'OR-20261119-003','2026-11-19 02:30:57',25.00,0.00,25.00,50.00,25.00,'None',0,0,'[]','Cash',NULL,'Completed'),
(27,'OR-20261119-004','2026-11-19 02:33:07',25.00,0.00,25.00,50.00,25.00,'None',0,0,'[]','Cash',NULL,'Completed'),
(28,'OR-20261119-005','2026-11-19 03:39:01',50.00,0.00,50.00,100.00,50.00,'None',0,0,'[]','Cash',NULL,'Voided Order');

--
-- Table structure for table `m11.2-order_items`
--

DROP TABLE IF EXISTS `m11.2-order_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `m11.2-order_items` (
  `oi-OrderId` int(11) NOT NULL AUTO_INCREMENT,
  `tm-TransactionId` int(11) NOT NULL,
  `oi-ProductId` int(11) NOT NULL,
  `oi-ProductName` varchar(50) NOT NULL,
  `oi-Quantity` int(11) NOT NULL,
  `oi-UnitPrice` decimal(10,2) NOT NULL,
  `oi-isDiscounted` tinyint(1) NOT NULL,
  `oi-AssignedScId` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `oi-LineTotal` decimal(10,2) NOT NULL,
  `oi-StockAllocations` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (`oi-StockAllocations` is null or json_valid(`oi-StockAllocations`)),
  PRIMARY KEY (`oi-OrderId`),
  KEY `fk_tm-TransactionId` (`tm-TransactionId`),
  CONSTRAINT `fk_tm-TransactionId` FOREIGN KEY (`tm-TransactionId`) REFERENCES `m11.1-transaction_master` (`tm-TransactionId`)
) ENGINE=InnoDB AUTO_INCREMENT=32 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `m11.2-order_items`
--

INSERT INTO `m11.2-order_items` (`oi-OrderId`, `tm-TransactionId`, `oi-ProductId`, `oi-ProductName`, `oi-Quantity`, `oi-UnitPrice`, `oi-isDiscounted`, `oi-AssignedScId`, `oi-LineTotal`, `oi-StockAllocations`) VALUES
(1,1,9,'Cornetto - Strawberry',1,25.00,0,'[]',25.00,NULL),
(2,2,8,'Cornetto - Rocky Road',1,20.00,1,'[{\"SCId\":\"sc-02341\",\"SCName\":\"Juan Sample\"}]',20.00,NULL),
(3,2,8,'Cornetto - Rocky Road',2,20.00,0,'[]',40.00,NULL),
(4,2,9,'Cornetto - Strawberry',1,25.00,1,'[{\"SCId\":\"02345\",\"SCName\":\"Juana Sample\"}]',25.00,NULL),
(5,3,10,'Cornetto - Vanilla',2,25.00,1,'[{\"SCId\":\"sc-02346\",\"SCName\":\"Juano Sample\"}]',50.00,NULL),
(6,3,11,'Cornetto - Matcha',2,25.00,1,'[{\"SCId\":\"sc-02346\",\"SCName\":\"Juano Sample\"}]',50.00,NULL),
(7,4,11,'Cornetto - Matcha',1,25.00,0,'[]',25.00,NULL),
(8,5,11,'Cornetto - Matcha',1,25.00,0,'[]',25.00,NULL),
(9,6,8,'Cornetto - Rocky Road',1,20.00,0,'[]',20.00,NULL),
(10,7,10,'Cornetto - Vanilla',1,25.00,0,'[]',25.00,NULL),
(11,8,4,'Double Baked Almond Croissant - Standard',2,250.00,1,'[{\"SCId\":\"sc-2341\",\"SCName\":\"Juan Sample\"}]',500.00,NULL),
(12,9,10,'Cornetto - Vanilla',1,25.00,1,'[{\"SCId\":\"sc-02341\",\"SCName\":\"Juan Sample\"}]',25.00,NULL),
(13,10,8,'Cornetto - Rocky Road',1,20.00,1,'[{\"SCId\":\"sc-02341\",\"SCName\":\"Juan Sample\"}]',20.00,NULL),
(14,11,9,'Cornetto - Strawberry',1,25.00,1,'[{\"SCId\":\"SC123\",\"SCName\":\"JUAN\"}]',25.00,NULL),
(15,12,11,'Cornetto - Matcha',1,25.00,0,'[]',25.00,NULL),
(16,13,10,'Cornetto - Vanilla',1,25.00,1,'[{\"SCId\":\"sc-02341\",\"SCName\":\"Juan Sample\"}]',25.00,NULL),
(21,18,11,'Cornetto - Matcha',2,25.00,0,'[]',50.00,'[{\"stockId\":10,\"quantity\":2}]'),
(22,19,11,'Cornetto - Matcha',1,25.00,0,'[]',25.00,'[{\"stockId\":10,\"quantity\":1}]'),
(23,20,11,'Cornetto - Matcha',1,25.00,0,'[]',25.00,'[{\"stockId\":10,\"quantity\":1}]'),
(24,21,11,'Cornetto - Matcha',1,25.00,0,'[]',25.00,'[{\"stockId\":10,\"quantity\":1}]'),
(25,22,11,'Cornetto - Matcha',1,25.00,0,'[]',25.00,'[{\"stockId\":10,\"quantity\":1}]'),
(26,23,11,'Cornetto - Matcha',1,25.00,0,'[]',25.00,'[{\"stockId\":10,\"quantity\":1}]'),
(27,24,11,'Cornetto - Matcha',1,25.00,0,'[]',25.00,'[{\"stockId\":10,\"quantity\":1}]'),
(28,25,11,'Cornetto - Matcha',1,25.00,0,'[]',25.00,'[{\"stockId\":10,\"quantity\":1}]'),
(29,26,11,'Cornetto - Matcha',1,25.00,0,'[]',25.00,'[{\"stockId\":10,\"quantity\":1}]'),
(30,27,10,'Cornetto - Vanilla',1,25.00,0,'[]',25.00,'[{\"stockId\":22,\"quantity\":1}]'),
(31,28,11,'Cornetto - Matcha',2,25.00,0,'[]',50.00,'[{\"stockId\":19,\"quantity\":2}]');

--
-- Table structure for table `m111-ingredient_item`
--

DROP TABLE IF EXISTS `m111-ingredient_item`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `m111-ingredient_item` (
  `ing-Id` int(11) NOT NULL AUTO_INCREMENT,
  `ingmaster-Id` varchar(30) NOT NULL,
  `ing-BatchCode` varchar(250) NOT NULL,
  `ing-Brand` varchar(50) NOT NULL,
  `ing-UnitType` tinytext NOT NULL,
  `ing-OriginalQty` decimal(10,2) NOT NULL,
  `ing-StockQty` decimal(10,2) NOT NULL,
  `ing-Price` decimal(10,2) NOT NULL,
  `Ing-ExpiryDate` date NOT NULL,
  PRIMARY KEY (`ing-Id`),
  KEY `ingmaster-Id` (`ingmaster-Id`),
  KEY `ingmaster-Id_2` (`ingmaster-Id`),
  CONSTRAINT `fk_ingmaster-Id` FOREIGN KEY (`ingmaster-Id`) REFERENCES `m11-ingredient_master` (`ingmaster-Id`)
) ENGINE=InnoDB AUTO_INCREMENT=39 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `m111-ingredient_item`
--

INSERT INTO `m111-ingredient_item` (`ing-Id`, `ingmaster-Id`, `ing-BatchCode`, `ing-Brand`, `ing-UnitType`, `ing-OriginalQty`, `ing-StockQty`, `ing-Price`, `Ing-ExpiryDate`) VALUES
(1,'MAS-DRY-APF','BATCH-1','Maya All-Purpose Flour','g',400.00,270.00,46.00,'2027-05-15'),
(2,'MAS-DRY-APF','BATCH-2','White King All-Purpose Flour','kg',50.00,0.99,92.50,'2026-07-16'),
(3,'MAS-DRY-APF','BATCH-3','White King All-Purpose Flour','g',400.00,400.00,40.00,'2026-07-15'),
(4,'MAS-DRY-APF','BATCH-2','White King All-Purpose Flour','kg',50.00,0.00,92.50,'2027-06-20'),
(5,'MAS-DRY-APF','BATCH-2','White King All-Purpose Flour','kg',50.00,0.00,92.50,'2027-06-20'),
(6,'MAS-DRY-APF','BATCH-3','Wooden Spoon All-Purpose Flour','kg',30.00,19.98,1150.00,'2026-11-10'),
(7,'MAS-DRY-APF','BATCH-3','Wooden Spoon All-Purpose Flour','kg',30.00,25.00,1150.00,'2026-11-10'),
(8,'MAS-DRY-APF','BATCH-3','White King All-Purpose Flour','kg',50.00,15.00,2000.00,'2026-11-15'),
(9,'MAS-DRY-APF','BATCH-4','White King All-Purpose Flour','g',400.00,400.00,350.00,'2026-08-22'),
(10,'MAS-DRY-APF','BATCH-1','sean1','g',30.00,30.00,65.00,'2026-08-29'),
(11,'MAS-DRY-APF','BATCH-4','sean2','g',60.00,60.00,65.00,'2026-08-27'),
(12,'MAS-DRY-BF','BATCH-1','SampleBrand Bread Flour','g',200.00,200.00,300.00,'2026-09-25'),
(13,'MAS-DRY-SLT','BATCH-1','SampleBrand Salt','g',2.00,2.00,1.00,'2026-09-19'),
(14,'MAS-DRY-SLT','BATCH-1','SampleBrand Salt 2','g',2.00,2.00,2.00,'2026-09-24'),
(15,'MAS-DRY-WSR','BATCH-1','SampleBrand Sugar','g',20.00,20.00,30.00,'2026-10-01'),
(16,'MAS-DRY-YST','BATCH-1','SampleBrand Yeast','g',5.00,5.00,20.00,'2026-09-23'),
(17,'MAS-FRT-STR','BATCH-1','SampleBrand Strawberry','g',60.00,60.00,90.00,'2026-09-30'),
(18,'MAS-TOP-CHC','BATCH-1','SampleBrand WhiteChocolate','g',100.00,100.00,80.00,'2026-09-29'),
(19,'MAS-DAI-LBS','BATCH-1','SampleBrand Laminating Buttersheets','g',40.00,40.00,700.00,'2026-09-30'),
(20,'MAS-DAI-FM','BATCH-1','SampleBrand Fresh Milk','mL',50.00,50.00,300.00,'2026-10-01'),
(21,'MAS-DAI-BTR','BATCH-1','SampleBrand Butter','g',30.00,30.00,100.00,'2026-09-29'),
(22,'MAS-DAI-PM','BATCH-1','SampleBrand Powdered MIlk','g',20.00,20.00,15.00,'2026-09-30'),
(23,'MAS-DRY-SLT','BATCH-1','White King All-Purpose Flour','g',40.00,0.00,50.00,'2026-09-19'),
(24,'MAS-DRY-SLT','BATCH-1','SampleBrand Salt 3','g',40.00,40.00,50.00,'2026-09-18'),
(25,'MAS-DRY-WSR','BATCH-1','SugarWhite','g',1000.00,0.00,300.00,'2026-10-31'),
(26,'MAS-DRY-SLT','BATCH-1','SaltBae','g',500.00,450.00,200.00,'2026-10-31'),
(27,'MAS-DRY-BF','BATCH-3','SampleBrand Bread Flour','g',50.00,0.00,30.00,'2026-10-31'),
(28,'MAS-DRY-WSR','BATCH-1','SugarWhiteMax','kg',30.00,0.00,500.00,'2026-10-31'),
(29,'MAS-DRY-BF','BATCH-1','SampleBrand Bread Flour','g',10.00,0.00,20.00,'2026-10-31'),
(30,'MAS-DRY-BF','BATCH-3','SampleBrand Bread Flour','g',200.00,170.00,400.00,'2026-10-31'),
(31,'MAS-DRY-WSR','BATCH-3','SampleBrand Sugar','g',200.00,170.00,300.00,'2026-10-31'),
(32,'MAS-DRY-BF','BATCH-1','oldbrand','g',400.00,400.00,300.00,'2025-06-21'),
(33,'MAS-DAI-BTR','BATCH-1','ButterCoco','g',200.00,200.00,400.00,'2026-10-31'),
(34,'MAS-DRY-APF','BATCH-1','expbrand','g',100.00,100.00,50.00,'2026-10-28'),
(35,'MAS-DRY-SLT','BATCH-1','SaltNovember','g',500.00,485.00,20.00,'2026-11-05'),
(36,'MAS-DRY-WSR','BATCH-1','SugarNovember','g',500.00,490.00,100.00,'2026-11-30'),
(37,'MAS-DAI-BTR','BATCH-1','ButterNovember','g',500.00,490.00,100.00,'2026-11-30'),
(38,'MAS-DRY-BF','BATCH-1','BFlourNovember','g',500.00,460.00,100.00,'2026-11-30');

--
-- Table structure for table `m12-void_master`
--

DROP TABLE IF EXISTS `m12-void_master`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `m12-void_master` (
  `vm-Id` int(11) NOT NULL AUTO_INCREMENT,
  `tm-ItemId` int(11) DEFAULT NULL,
  `vm-TransactionDate` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `vm-UnitPrice` double(10,2) NOT NULL,
  `vm-LineTotal` double(10,2) NOT NULL,
  `vm-Quantity` int(11) NOT NULL,
  `vm-ProductId` int(11) NOT NULL,
  `vm-ProductName` varchar(50) NOT NULL,
  `vm-VoidType` text NOT NULL,
  `vm-Reason` varchar(100) NOT NULL,
  PRIMARY KEY (`vm-Id`),
  KEY `fk_tm-ItemId` (`tm-ItemId`),
  KEY `idx_vm-TransactionDate` (`vm-TransactionDate`),
  CONSTRAINT `fk_void_tm-ItemId` FOREIGN KEY (`tm-ItemId`) REFERENCES `m11.2-order_items` (`oi-OrderId`)
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `m12-void_master`
--

INSERT INTO `m12-void_master` (`vm-Id`, `tm-ItemId`, `vm-TransactionDate`, `vm-UnitPrice`, `vm-LineTotal`, `vm-Quantity`, `vm-ProductId`, `vm-ProductName`, `vm-VoidType`, `vm-Reason`) VALUES
(11,16,'2026-11-12 19:43:22',25.00,25.00,1,10,'Cornetto - Vanilla','Post-Checkout','Cancelled'),
(12,NULL,'2026-11-12 19:43:44',25.00,25.00,1,11,'Cornetto - Matcha','Pre-Checkout','Item removed from cart'),
(13,NULL,'2026-11-12 19:43:53',25.00,50.00,2,11,'Cornetto - Matcha','Pre-Checkout','Cart cleared before checkout'),
(14,NULL,'2026-11-18 05:52:30',25.00,25.00,1,11,'Cornetto - Matcha','Pre-Checkout','Item removed from cart'),
(15,NULL,'2026-11-18 05:58:04',25.00,25.00,1,11,'Cornetto - Matcha','Pre-Checkout','Item removed from cart'),
(16,24,'2026-11-18 05:59:07',25.00,25.00,1,11,'Cornetto - Matcha','Post-Checkout','Error'),
(17,NULL,'2026-11-19 03:37:26',25.00,50.00,2,11,'Cornetto - Matcha','Pre-Checkout','Item removed from cart'),
(18,NULL,'2026-11-19 03:38:14',25.00,50.00,2,11,'Cornetto - Matcha','Pre-Checkout','Cart cleared before checkout'),
(19,NULL,'2026-11-19 03:38:14',20.00,60.00,3,8,'Cornetto - Rocky Road','Pre-Checkout','Cart cleared before checkout'),
(20,31,'2026-11-19 03:40:11',25.00,50.00,2,11,'Cornetto - Matcha','Post-Checkout','error');

--
-- Table structure for table `m2-recipe_category`
--

DROP TABLE IF EXISTS `m2-recipe_category`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `m2-recipe_category` (
  `reccat-Id` varchar(15) NOT NULL,
  `reccat-Name` text NOT NULL,
  PRIMARY KEY (`reccat-Id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `m2-recipe_category`
--

INSERT INTO `m2-recipe_category` (`reccat-Id`, `reccat-Name`) VALUES
('CAT-CCK','Custom Cake'),
('CAT-CON','Confection'),
('CAT-CUP','Cupcake'),
('CAT-DNT','Donut'),
('CAT-MAC','Macaron'),
('CAT-SCK','Standard Cake'),
('CAT-VIE','Viennoiserie');

--
-- Table structure for table `m22-recipe_master`
--

DROP TABLE IF EXISTS `m22-recipe_master`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `m22-recipe_master` (
  `rec-Id` varchar(15) NOT NULL,
  `reccat-Id` varchar(15) NOT NULL,
  `rec-Name` text NOT NULL,
  `rec-PhotoPath` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`rec-Id`),
  KEY `fk_reccat-Id` (`reccat-Id`),
  CONSTRAINT `fk_reccat-Id` FOREIGN KEY (`reccat-Id`) REFERENCES `m2-recipe_category` (`reccat-Id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `m22-recipe_master`
--

INSERT INTO `m22-recipe_master` (`rec-Id`, `reccat-Id`, `rec-Name`, `rec-PhotoPath`) VALUES
('CHI-ALC-CHE','CAT-SCK','Alcapone Cheesecake',NULL),
('CHI-BCC-CHE','CAT-SCK','Banana Choco Chip Cheesecake',NULL),
('CHI-BLU-CHE','CAT-SCK','Blueberry Cheesecake',NULL),
('CHI-MWC-CHE','CAT-SCK','Matcha White Choco Cheesecake',NULL),
('CHI-ORO-CHE','CAT-SCK','Oreo Cheesecake',NULL),
('CHI-SMO-CHE','CAT-SCK','S\'mores Cheesecake',NULL),
('CHI-UBE-CHE','CAT-SCK','Ube Macapuno Cheesecake',NULL),
('CLA-BBL-CHE','CAT-SCK','Baked Blueberry Cheesecake',NULL),
('CLA-BST-CHE','CAT-SCK','Baked Strawberry Cheesecake',NULL),
('CLA-CHL-BLU','CAT-SCK','Chilled Blueberry Cheesecake',NULL),
('CLA-CHL-MAN','CAT-SCK','Chilled Mango Cheesecake',NULL),
('CLA-CHL-STR','CAT-SCK','Chilled Strawberry Cheesecake',NULL),
('CLA-MAN-CAR','CAT-SCK','Mango Caramel Chiffon Cake',NULL),
('CLA-UBE-CAR','CAT-SCK','Ube Caramel Chiffon Cake',NULL),
('CLA-YEM-CAR','CAT-SCK','Yema Caramel Chiffon Cake',NULL),
('CON-CAK-POP','CAT-CON','Base Cake Pop',NULL),
('CON-CAK-SCL','CAT-CON','Base Cakesicle',NULL),
('CON-CPP-TWR','CAT-CON','Cake Pop Tower',NULL),
('CON-CSC-TWR','CAT-CON','Cakesicle Tower',NULL),
('CON-THE-DRC','CAT-CON','Base Theme Dessert Cup',NULL),
('CUP-CAK-BOU','CAT-CUP','Cupcake Bouquet',NULL),
('CUP-CAK-CUS','CAT-CUP','Base Cupcake',NULL),
('CUS-1TR-MIN','CAT-CCK','1-Tier Base Mini Cake',NULL),
('CUS-1TR-RND','CAT-CCK','1-Tier Base Round Cake',NULL),
('CUS-2TR-MIN','CAT-CCK','2-Tier Base Mini Cake',NULL),
('CUS-2TR-RND','CAT-CCK','2-Tier Base Round Cake',NULL),
('CUS-CHC-BNT','CAT-CCK','Chocolate Bento Cake',NULL),
('CUS-RDV-BNT','CAT-CCK','Red Velvet Bento Cake',NULL),
('CUS-REC-CAK','CAT-CCK','Base Rectangle Cake',NULL),
('CUS-SQR-CAK','CAT-CCK','Base Square Cake',NULL),
('DON-MIN-TWR','CAT-DNT','Base Donut Tower',NULL),
('DON-PER-WFD','CAT-DNT','Personalized Base Donut',NULL),
('MAC-DCH-PST','CAT-MAC','Dark Chocolate Pistachio',NULL),
('MAC-LEM-WCH','CAT-MAC','Lemon White Chocolate',NULL),
('MAC-MAN-GCH','CAT-MAC','Mango Ganache',NULL),
('MAC-SLC-MOC','CAT-MAC','Salted Caramel Mocha',NULL),
('MAC-STR-CRE','CAT-MAC','Strawberry Cream',NULL),
('MAC-UBE-GCH','CAT-MAC','Ube Ganache',NULL),
('MAC-WCH-PST','CAT-MAC','White Chocolate Pistachio',NULL),
('PRE-CAR-CIN','CAT-SCK','Carrot Cinnamon Cake',NULL),
('PRE-CHC-RUM','CAT-SCK','Chocolate Rum Cake',NULL),
('PRE-DRU-MOI','CAT-SCK','Drunken Moist Chocolate Cake',NULL),
('PRE-RED-VEL','CAT-SCK','Red Velvet Cake',NULL),
('PRE-TIR-CRE','CAT-SCK','Tiramisu Cream Cake',NULL),
('PRE-UBE-MAC','CAT-SCK','Ube Macapuno Cake',NULL),
('VIE-DBL-ALM','CAT-VIE','Double Baked Almond Croissant',NULL),
('VIE-DBL-PPC','CAT-VIE','Double Pain Au Chocolat Croissant',NULL),
('VIE-HAC-CRO','CAT-VIE','Ham and Cheese Croissant',NULL),
('VIE-LEC-DAN','CAT-VIE','Leche Flan Danish',NULL),
('VIE-PBU-CRO','CAT-VIE','Plain Butter Croissant',NULL),
('VIE-PPC-CRO','CAT-VIE','Plain Pain Au Chocolat Croissant',NULL),
('VIE-STR-CRO','CAT-VIE','Strawberry Croissant',NULL);

--
-- Table structure for table `m222-recipe_variant`
--

DROP TABLE IF EXISTS `m222-recipe_variant`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `m222-recipe_variant` (
  `recvar-Id` varchar(15) NOT NULL,
  `rec-Id` varchar(15) NOT NULL,
  `recvar-SvgPerBatch` int(5) NOT NULL,
  `recvar-SizeOrPcs` varchar(10) NOT NULL,
  `recvar-RecCost` decimal(10,4) DEFAULT NULL,
  `recvar-Foundation` varchar(20) DEFAULT NULL,
  PRIMARY KEY (`recvar-Id`),
  KEY `fk_rec-Id` (`rec-Id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `m222-recipe_variant`
--

INSERT INTO `m222-recipe_variant` (`recvar-Id`, `rec-Id`, `recvar-SvgPerBatch`, `recvar-SizeOrPcs`, `recvar-RecCost`, `recvar-Foundation`) VALUES
('OVR-VIE-STR','VIE-STR-CRO',1,'1',0.0000,NULL),
('SQR-662-BTR','CUS-SQR-CAK',1,'6x6x2.5',61.0000,'Buttercream'),
('SQR-662-FLF','CUS-SQR-CAK',1,'6x6x2.5',40.7667,'Full Fondant'),
('SQR-662-SMF','CUS-SQR-CAK',1,'6x6x2.5',50.0000,'Semi Fondant'),
('SQR-882-BTR','CUS-SQR-CAK',1,'8x8x2.5',85.0000,'Buttercream'),
('SQR-882-FLF','CUS-SQR-CAK',1,'8x8x2.5',146.1500,'Full Fondant'),
('SQR-882-SMF','CUS-SQR-CAK',1,'8x8x2.5',70.0000,'Semi Fondant');

--
-- Table structure for table `m222-recipe_variant2`
--

DROP TABLE IF EXISTS `m222-recipe_variant2`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `m222-recipe_variant2` (
  `recvar-Id` varchar(15) NOT NULL,
  `rec-Id` varchar(15) NOT NULL,
  `recvar-SvgPerBatch` int(5) NOT NULL,
  `recvar-SizeOrPcs` varchar(10) NOT NULL,
  `recvar-RecCost` decimal(10,4) DEFAULT NULL,
  `recvar-Foundation` varchar(20) DEFAULT NULL,
  PRIMARY KEY (`recvar-Id`),
  KEY `fk_rec-Id` (`rec-Id`),
  CONSTRAINT `fk_rec-Id` FOREIGN KEY (`rec-Id`) REFERENCES `m22-recipe_master` (`rec-Id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `m222-recipe_variant2`
--

INSERT INTO `m222-recipe_variant2` (`recvar-Id`, `rec-Id`, `recvar-SvgPerBatch`, `recvar-SizeOrPcs`, `recvar-RecCost`, `recvar-Foundation`) VALUES
('3OZ-TWL-TDC','CON-THE-DRC',1,'12',NULL,NULL),
('3OZ-TWN-TDC','CON-THE-DRC',1,'20',NULL,NULL),
('BAL-CPP-TWR','CON-CPP-TWR',1,'25',NULL,NULL),
('BNT-C42-ALL','CUS-CHC-BNT',1,'4x2',NULL,'All Fondant'),
('BNT-C42-I2D','CUS-CHC-BNT',1,'4x2',NULL,'Icing with 2D'),
('BNT-R42-ALL','CUS-RDV-BNT',1,'4x2',NULL,'All Fondant'),
('BNT-R42-I2D','CUS-RDV-BNT',1,'4x2',NULL,'Icing with 2D'),
('CHI-6IN-ALC','CHI-ALC-CHE',1,'6\"',NULL,NULL),
('CHI-6IN-BCC','CHI-BCC-CHE',1,'6\"',NULL,NULL),
('CHI-6IN-BLU','CHI-BLU-CHE',1,'6\"',NULL,NULL),
('CHI-6IN-MWC','CHI-MWC-CHE',1,'6\"',NULL,NULL),
('CHI-6IN-ORO','CHI-ORO-CHE',1,'6\"',NULL,NULL),
('CHI-6IN-SMO','CHI-SMO-CHE',1,'6\"',NULL,NULL),
('CHI-6IN-UBE','CHI-UBE-CHE',1,'6\"',NULL,NULL),
('CHI-8IN-ALC','CHI-ALC-CHE',1,'8\"',NULL,NULL),
('CHI-8IN-BCC','CHI-BCC-CHE',1,'8\"',NULL,NULL),
('CHI-8IN-BLU','CHI-BLU-CHE',1,'8\"',NULL,NULL),
('CHI-8IN-MWC','CHI-MWC-CHE',1,'8\"',NULL,NULL),
('CHI-8IN-ORO','CHI-ORO-CHE',1,'8\"',NULL,NULL),
('CHI-8IN-SMO','CHI-SMO-CHE',1,'8\"',NULL,NULL),
('CHI-8IN-UBE','CHI-UBE-CHE',1,'8\"',NULL,NULL),
('CLA-B62-BKD','CLA-BBL-CHE',1,'6x2.5',NULL,NULL),
('CLA-B62-CHL','CLA-CHL-BLU',1,'6x2.5',NULL,NULL),
('CLA-B82-BKD','CLA-BBL-CHE',1,'8x2.5',NULL,NULL),
('CLA-M62-CHL','CLA-CHL-MAN',1,'6x2.5',NULL,NULL),
('CLA-M62-CML','CLA-MAN-CAR',1,'6x2',NULL,NULL),
('CLA-M63-CML','CLA-MAN-CAR',1,'6x3',NULL,NULL),
('CLA-M64-CML','CLA-MAN-CAR',1,'6x4',NULL,NULL),
('CLA-M82-CML','CLA-MAN-CAR',1,'8x2',NULL,NULL),
('CLA-S62-BKD','CLA-BST-CHE',1,'6x2.5',NULL,NULL),
('CLA-S62-CHL','CLA-CHL-STR',1,'6x2.5',NULL,NULL),
('CLA-S82-BKD','CLA-BST-CHE',1,'8x2.5',NULL,NULL),
('CLA-U62-CML','CLA-UBE-CAR',1,'6x2',NULL,NULL),
('CLA-U63-CML','CLA-UBE-CAR',1,'6x3',NULL,NULL),
('CLA-U64-CML','CLA-UBE-CAR',1,'6x4',NULL,NULL),
('CLA-U82-CML','CLA-UBE-CAR',1,'8x2',NULL,NULL),
('CLA-Y62-CML','CLA-YEM-CAR',1,'6x2',NULL,NULL),
('CLA-Y63-CML','CLA-YEM-CAR',1,'6x3',NULL,NULL),
('CLA-Y64-CML','CLA-YEM-CAR',1,'6x4',NULL,NULL),
('CLA-Y82-CML','CLA-YEM-CAR',1,'8x2',NULL,NULL),
('LAR-CPC-BOQ','CUP-CAK-BOU',1,'12',NULL,NULL),
('LAR-CPP-TWR','CON-CPP-TWR',1,'50',NULL,NULL),
('LAR-CSC-TWR','CON-CSC-TWR',1,'18',NULL,NULL),
('LAR-DON-TWR','DON-MIN-TWR',1,'24',NULL,NULL),
('MED-CPC-BOQ','CUP-CAK-BOU',1,'7',NULL,NULL),
('MED-CPP-TWR','CON-CPP-TWR',1,'35',NULL,NULL),
('MED-CSC-TWR','CON-CSC-TWR',1,'12',NULL,NULL),
('MED-DON-TWR','DON-MIN-TWR',1,'18',NULL,NULL),
('MIN-3X3-BTR','CUS-1TR-MIN',1,'3x3',NULL,'Buttercream'),
('MIN-3X3-FLF','CUS-1TR-MIN',1,'3x3',NULL,'Full Fondant'),
('MIN-3X3-SMF','CUS-1TR-MIN',1,'3x3',NULL,'Semi Fondant'),
('MIN-3X4-BTR','CUS-1TR-MIN',1,'3x4',NULL,'Buttercream'),
('MIN-3X4-FLF','CUS-1TR-MIN',1,'3x4',NULL,'Full Fondant'),
('MIN-3X4-SMF','CUS-1TR-MIN',1,'3x4',NULL,'Semi Fondant'),
('MIN-4X3-BTR','CUS-1TR-MIN',1,'4x3',NULL,'Buttercream'),
('MIN-4X3-FLF','CUS-1TR-MIN',1,'4x3',NULL,'Full Fondant'),
('MIN-4X3-SMF','CUS-1TR-MIN',1,'4x3',NULL,'Semi Fondant'),
('MIN-4X4-BTR','CUS-1TR-MIN',1,'4x4',NULL,'Buttercream'),
('MIN-4X4-FLF','CUS-1TR-MIN',1,'4x4',NULL,'Full Fondant'),
('MIN-4X4-SMF','CUS-1TR-MIN',1,'4x4',NULL,'Semi Fondant'),
('MIN-4X5-BTR','CUS-1TR-MIN',1,'4x5',NULL,'Buttercream'),
('MIN-4X5-FLF','CUS-1TR-MIN',1,'4x5',NULL,'Full Fondant'),
('MIN-4X5-SMF','CUS-1TR-MIN',1,'4x5',NULL,'Semi Fondant'),
('MIN-B33-ALL','CUS-2TR-MIN',1,'3x3/4x3',NULL,'All Fondant'),
('MIN-B33-SMF','CUS-2TR-MIN',1,'3x3/4x3',NULL,'Semi Fondant'),
('MIN-B44-ALL','CUS-2TR-MIN',1,'3x4/4x4',NULL,'All Fondant'),
('MIN-B44-SMF','CUS-2TR-MIN',1,'3x4/4x4',NULL,'Semi Fondant'),
('MIN-CPP-TWR','CON-CPP-TWR',1,'20',NULL,NULL),
('OVR-CON-CPP','CON-CAK-POP',1,'1',NULL,NULL),
('OVR-CON-SCL','CON-CAK-SCL',1,'1',NULL,NULL),
('OVR-CUP-CAK','CUP-CAK-CUS',1,'1',NULL,NULL),
('OVR-DON-PBD','DON-PER-WFD',1,'1',NULL,NULL),
('OVR-MAC-DCH','MAC-DCH-PST',1,'1',NULL,NULL),
('OVR-MAC-LEM','MAC-LEM-WCH',1,'1',NULL,NULL),
('OVR-MAC-MAN','MAC-MAN-GCH',1,'1',NULL,NULL),
('OVR-MAC-SLC','MAC-SLC-MOC',1,'1',NULL,NULL),
('OVR-MAC-STR','MAC-STR-CRE',1,'1',NULL,NULL),
('OVR-MAC-UBE','MAC-UBE-GCH',1,'1',NULL,NULL),
('OVR-MAC-WCH','MAC-WCH-PST',1,'1',NULL,NULL),
('OVR-VIE-DAN','VIE-LEC-DAN',1,'1',NULL,NULL),
('OVR-VIE-DLM','VIE-DBL-ALM',1,'1',NULL,NULL),
('OVR-VIE-DPC','VIE-DBL-PPC',1,'1',NULL,NULL),
('OVR-VIE-HAC','VIE-HAC-CRO',1,'1',NULL,NULL),
('OVR-VIE-PBU','VIE-PBU-CRO',1,'1',NULL,NULL),
('OVR-VIE-PPC','VIE-PPC-CRO',1,'1',NULL,NULL),
('OVR-VIE-STR','VIE-STR-CRO',1,'1',NULL,NULL),
('PRE-C62-CIN','PRE-CAR-CIN',1,'6x2',NULL,NULL),
('PRE-C63-CIN','PRE-CAR-CIN',1,'6x3',NULL,NULL),
('PRE-C64-CIN','PRE-CAR-CIN',1,'6x4',NULL,NULL),
('PRE-C82-CIN','PRE-CAR-CIN',1,'8x2',NULL,NULL),
('PRE-D62-MCH','PRE-DRU-MOI',1,'6x2',NULL,NULL),
('PRE-D63-MCH','PRE-DRU-MOI',1,'6x3',NULL,NULL),
('PRE-D64-MCH','PRE-DRU-MOI',1,'6x4',NULL,NULL),
('PRE-D82-MCH','PRE-DRU-MOI',1,'8x2',NULL,NULL),
('PRE-R62-RMC','PRE-CHC-RUM',1,'6x2',NULL,NULL),
('PRE-R62-VEL','PRE-RED-VEL',1,'6x2',NULL,NULL),
('PRE-R63-RMC','PRE-CHC-RUM',1,'6x3',NULL,NULL),
('PRE-R63-VEL','PRE-RED-VEL',1,'6x3',NULL,NULL),
('PRE-R64-RMC','PRE-CHC-RUM',1,'6x4',NULL,NULL),
('PRE-R64-VEL','PRE-RED-VEL',1,'6x4',NULL,NULL),
('PRE-R82-RMC','PRE-CHC-RUM',1,'8x2',NULL,NULL),
('PRE-R82-VEL','PRE-RED-VEL',1,'8x2',NULL,NULL),
('PRE-T62-CCC','PRE-TIR-CRE',1,'6x2',NULL,NULL),
('PRE-T63-CCC','PRE-TIR-CRE',1,'6x3',NULL,NULL),
('PRE-T64-CCC','PRE-TIR-CRE',1,'6x4',NULL,NULL),
('PRE-T82-CCC','PRE-TIR-CRE',1,'8x2',NULL,NULL),
('PRE-U62-MCP','PRE-UBE-MAC',1,'6x2',NULL,NULL),
('PRE-U63-MCP','PRE-UBE-MAC',1,'6x3',NULL,NULL),
('PRE-U64-MCP','PRE-UBE-MAC',1,'6x4',NULL,NULL),
('PRE-U82-MCP','PRE-UBE-MAC',1,'8x2',NULL,NULL),
('R2T-682-BTR','CUS-2TR-RND',1,'6x2/8x2',NULL,'Buttercream'),
('R2T-682-FLF','CUS-2TR-RND',1,'6x2/8x2',NULL,'Full Fondant'),
('R2T-682-SMF','CUS-2TR-RND',1,'6x2/8x2',NULL,'Semi Fondant'),
('R2T-683-BTR','CUS-2TR-RND',1,'6x3/8x3',NULL,'Buttercream'),
('R2T-683-FLF','CUS-2TR-RND',1,'6x3/8x3',NULL,'Full Fondant'),
('R2T-683-SMF','CUS-2TR-RND',1,'6x3/8x3',NULL,'Semi Fondant'),
('R2T-684-BTR','CUS-2TR-RND',1,'6x4/8x4',NULL,'Buttercream'),
('R2T-684-FLF','CUS-2TR-RND',1,'6x4/8x4',NULL,'Full Fondant'),
('R2T-684-SMF','CUS-2TR-RND',1,'6x4/8x4',NULL,'Semi Fondant'),
('R2T-685-BTR','CUS-2TR-RND',1,'6x5/8x5',NULL,'Buttercream'),
('R2T-685-FLF','CUS-2TR-RND',1,'6x5/8x5',NULL,'Full Fondant'),
('R2T-685-SMF','CUS-2TR-RND',1,'6x5/8x5',NULL,'Semi Fondant'),
('R2T-686-BTR','CUS-2TR-RND',1,'6x6/8x6',NULL,'Buttercream'),
('R2T-686-FLF','CUS-2TR-RND',1,'6x6/8x6',NULL,'Full Fondant'),
('R2T-686-SMF','CUS-2TR-RND',1,'6x6/8x6',NULL,'Semi Fondant'),
('R2T-687-BTR','CUS-2TR-RND',1,'6x7/8x7',NULL,'Buttercream'),
('R2T-687-FLF','CUS-2TR-RND',1,'6x7/8x7',NULL,'Full Fondant'),
('R2T-687-SMF','CUS-2TR-RND',1,'6x7/8x7',NULL,'Semi Fondant'),
('RD6-6X2-BTR','CUS-1TR-RND',1,'6x2',NULL,'Buttercream'),
('RD6-6X2-FLF','CUS-1TR-RND',1,'6x2',NULL,'Full Fondant'),
('RD6-6X2-SMF','CUS-1TR-RND',1,'6x2',NULL,'Semi Fondant'),
('RD6-6X3-BTR','CUS-1TR-RND',1,'6x3',NULL,'Buttercream'),
('RD6-6X3-FLF','CUS-1TR-RND',1,'6x3',NULL,'Full Fondant'),
('RD6-6X3-SMF','CUS-1TR-RND',1,'6x3',NULL,'Semi Fondant'),
('RD6-6X4-BTR','CUS-1TR-RND',1,'6x4',NULL,'Buttercream'),
('RD6-6X4-FLF','CUS-1TR-RND',1,'6x4',NULL,'Full Fondant'),
('RD6-6X4-SMF','CUS-1TR-RND',1,'6x4',NULL,'Semi Fondant'),
('RD6-6X5-BTR','CUS-1TR-RND',1,'6x5',NULL,'Buttercream'),
('RD6-6X5-FLF','CUS-1TR-RND',1,'6x5',NULL,'Full Fondant'),
('RD6-6X5-SMF','CUS-1TR-RND',1,'6x5',NULL,'Semi Fondant'),
('RD6-6X6-BTR','CUS-1TR-RND',1,'6x6',NULL,'Buttercream'),
('RD6-6X6-FLF','CUS-1TR-RND',1,'6x6',NULL,'Full Fondant'),
('RD6-6X6-SMF','CUS-1TR-RND',1,'6x6',NULL,'Semi Fondant'),
('RD6-6X7-BTR','CUS-1TR-RND',1,'6x7',NULL,'Buttercream'),
('RD6-6X7-FLF','CUS-1TR-RND',1,'6x7',NULL,'Full Fondant'),
('RD6-6X7-SMF','CUS-1TR-RND',1,'6x7',NULL,'Semi Fondant'),
('RD8-8X2-BTR','CUS-1TR-RND',1,'8x2',NULL,'Buttercream'),
('RD8-8X2-FLF','CUS-1TR-RND',1,'8x2',NULL,'Full Fondant'),
('RD8-8X2-SMF','CUS-1TR-RND',1,'8x2',NULL,'Semi Fondant'),
('RD8-8X3-BTR','CUS-1TR-RND',1,'8x3',NULL,'Buttercream'),
('RD8-8X3-FLF','CUS-1TR-RND',1,'8x3',NULL,'Full Fondant'),
('RD8-8X3-SMF','CUS-1TR-RND',1,'8x3',NULL,'Semi Fondant'),
('RD8-8X4-BTR','CUS-1TR-RND',1,'8x4',NULL,'Buttercream'),
('RD8-8X4-FLF','CUS-1TR-RND',1,'8x4',NULL,'Full Fondant'),
('RD8-8X4-SMF','CUS-1TR-RND',1,'8x4',NULL,'Semi Fondant'),
('RD8-8X5-BTR','CUS-1TR-RND',1,'8x5',NULL,'Buttercream'),
('RD8-8X5-FLF','CUS-1TR-RND',1,'8x5',NULL,'Full Fondant'),
('RD8-8X5-SMF','CUS-1TR-RND',1,'8x5',NULL,'Semi Fondant'),
('RD8-8X6-BTR','CUS-1TR-RND',1,'8x6',NULL,'Buttercream'),
('RD8-8X6-FLF','CUS-1TR-RND',1,'8x6',NULL,'Full Fondant'),
('RD8-8X6-SMF','CUS-1TR-RND',1,'8x6',NULL,'Semi Fondant'),
('RD8-8X7-BTR','CUS-1TR-RND',1,'8x7',NULL,'Buttercream'),
('RD8-8X7-FLF','CUS-1TR-RND',1,'8x7',NULL,'Full Fondant'),
('RD8-8X7-SMF','CUS-1TR-RND',1,'8x7',NULL,'Semi Fondant'),
('REC-192-BTR','CUS-REC-CAK',1,'12x9x2.5',NULL,'Buttercream'),
('REC-192-FLF','CUS-REC-CAK',1,'12x9x2.5',NULL,'Full Fondant'),
('REC-192-SMF','CUS-REC-CAK',1,'12x9x2.5',NULL,'Semi Fondant'),
('REC-962-BTR','CUS-REC-CAK',1,'9x6x2.5',NULL,'Buttercream'),
('REC-962-FLF','CUS-REC-CAK',1,'9x6x2.5',NULL,'Full Fondant'),
('REC-962-SMF','CUS-REC-CAK',1,'9x6x2.5',NULL,'Semi Fondant'),
('SMA-CSC-TWR','CON-CSC-TWR',1,'8',NULL,NULL),
('SML-DON-TWR','DON-MIN-TWR',1,'12',NULL,NULL),
('SQR-662-BTR','CUS-SQR-CAK',1,'6x6x2.5',NULL,'Buttercream'),
('SQR-662-FLF','CUS-SQR-CAK',1,'6x6x2.5',NULL,'Full Fondant'),
('SQR-662-SMF','CUS-SQR-CAK',1,'6x6x2.5',NULL,'Semi Fondant'),
('SQR-882-BTR','CUS-SQR-CAK',1,'8x8x2.5',NULL,'Buttercream'),
('SQR-882-FLF','CUS-SQR-CAK',1,'8x8x2.5',NULL,'Full Fondant'),
('SQR-882-SMF','CUS-SQR-CAK',1,'8x8x2.5',NULL,'Semi Fondant'),
('XLA-CPP-TWR','CON-CPP-TWR',1,'60',NULL,NULL);

--
-- Table structure for table `m222-recipe_variant3`
--

DROP TABLE IF EXISTS `m222-recipe_variant3`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `m222-recipe_variant3` (
  `recvar-Id` varchar(15) NOT NULL,
  `rec-Id` varchar(15) NOT NULL,
  `recvar-Type` varchar(50) DEFAULT NULL,
  `recvar-SvgPerBatch` int(5) NOT NULL,
  `recvar-RecCost` decimal(10,4) DEFAULT NULL,
  PRIMARY KEY (`recvar-Id`),
  KEY `fkv_rec-Id` (`rec-Id`),
  CONSTRAINT `fkv_rec-Id` FOREIGN KEY (`rec-Id`) REFERENCES `m22-recipe_master` (`rec-Id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `m222-recipe_variant3`
--

INSERT INTO `m222-recipe_variant3` (`recvar-Id`, `rec-Id`, `recvar-Type`, `recvar-SvgPerBatch`, `recvar-RecCost`) VALUES
('BNT-C42-ALL','CUS-CHC-BNT','4x2 All Fondant',1,NULL),
('BNT-C42-I2D','CUS-CHC-BNT','4x2 Icing with 2D',1,NULL),
('BNT-R42-ALL','CUS-RDV-BNT','4x2 All Fondant',1,0.0000),
('BNT-R42-I2D','CUS-RDV-BNT','4x2 Icing with 2D',1,NULL),
('CHI-6IN-ALC','CHI-ALC-CHE','6\" (Inches)',1,NULL),
('CHI-6IN-BCC','CHI-BCC-CHE','6\" (Inches)',1,NULL),
('CHI-6IN-BLU','CHI-BLU-CHE','6\" (Inches)',1,NULL),
('CHI-6IN-MWC','CHI-MWC-CHE','6\" (Inches)',1,NULL),
('CHI-6IN-ORO','CHI-ORO-CHE','6\" (Inches)',1,NULL),
('CHI-6IN-SMO','CHI-SMO-CHE','6\" (Inches)',1,NULL),
('CHI-6IN-UBE','CHI-UBE-CHE','6\" (Inches)',1,NULL),
('CHI-8IN-ALC','CHI-ALC-CHE','8\" (Inches)',1,NULL),
('CHI-8IN-BCC','CHI-BCC-CHE','8\" (Inches)',1,NULL),
('CHI-8IN-BLU','CHI-BLU-CHE','8\" (Inches)',1,NULL),
('CHI-8IN-MWC','CHI-MWC-CHE','8\" (Inches)',1,NULL),
('CHI-8IN-ORO','CHI-ORO-CHE','8\" (Inches)',1,NULL),
('CHI-8IN-SMO','CHI-SMO-CHE','8\" (Inches)',1,NULL),
('CHI-8IN-UBE','CHI-UBE-CHE','8\" (Inches)',1,NULL),
('CLA-B62-BKD','CLA-BBL-CHE','6x2.5',1,NULL),
('CLA-B62-CHL','CLA-CHL-BLU','6x2.5',1,NULL),
('CLA-B82-BKD','CLA-BBL-CHE','8x2.5',1,NULL),
('CLA-M62-CHL','CLA-CHL-MAN','6x2.5',1,NULL),
('CLA-M62-CML','CLA-MAN-CAR','6x2',1,NULL),
('CLA-M63-CML','CLA-MAN-CAR','6x3',1,NULL),
('CLA-M64-CML','CLA-MAN-CAR','6x4',1,NULL),
('CLA-M82-CML','CLA-MAN-CAR','8x2',1,NULL),
('CLA-S62-BKD','CLA-BST-CHE','6x2.5',1,NULL),
('CLA-S62-CHL','CLA-CHL-STR','6x2.5',1,NULL),
('CLA-S82-BKD','CLA-BST-CHE','8x2.5',1,NULL),
('CLA-U62-CML','CLA-UBE-CAR','6x2',1,NULL),
('CLA-U63-CML','CLA-UBE-CAR','6x3',1,NULL),
('CLA-U64-CML','CLA-UBE-CAR','6x4',1,NULL),
('CLA-U82-CML','CLA-UBE-CAR','8x2',1,NULL),
('CLA-Y62-CML','CLA-YEM-CAR','6x2',1,0.0000),
('CLA-Y63-CML','CLA-YEM-CAR','6x3',1,NULL),
('CLA-Y64-CML','CLA-YEM-CAR','6x4',1,NULL),
('CLA-Y82-CML','CLA-YEM-CAR','8x2',1,NULL),
('MIN-3X3-BTR','CUS-1TR-MIN','3x3 Buttercream',1,NULL),
('MIN-3X3-FLF','CUS-1TR-MIN','3x3 Full Fondant',1,NULL),
('MIN-3X3-SMF','CUS-1TR-MIN','3x3 Semi Fondant',1,NULL),
('MIN-3X4-BTR','CUS-1TR-MIN','3x4 Buttercream',1,NULL),
('MIN-3X4-FLF','CUS-1TR-MIN','3x4 Full Fondant',1,NULL),
('MIN-3X4-SMF','CUS-1TR-MIN','3x4 Semi Fondant',1,NULL),
('MIN-4X3-BTR','CUS-1TR-MIN','4x3 Buttercream',1,NULL),
('MIN-4X3-FLF','CUS-1TR-MIN','4x3 Full Fondant',1,NULL),
('MIN-4X3-SMF','CUS-1TR-MIN','4x3 Semi Fondant',1,NULL),
('MIN-4X4-BTR','CUS-1TR-MIN','4x4 Buttercream',1,NULL),
('MIN-4X4-FLF','CUS-1TR-MIN','4x4 Full Fondant',1,NULL),
('MIN-4X4-SMF','CUS-1TR-MIN','4x4 Semi Fondant',1,NULL),
('MIN-4X5-BTR','CUS-1TR-MIN','4x5 Buttercream',1,NULL),
('MIN-4X5-FLF','CUS-1TR-MIN','4x5 Full Fondant',1,NULL),
('MIN-4X5-SMF','CUS-1TR-MIN','4x5 Semi Fondant',1,NULL),
('MIN-B33-ALL','CUS-2TR-MIN','3x3/4x3 All Fondant',1,NULL),
('MIN-B33-SMF','CUS-2TR-MIN','3x3/4x3 Semi Fondant',1,NULL),
('MIN-B44-ALL','CUS-2TR-MIN','3x4/4x4 All Fondant',1,NULL),
('MIN-B44-SMF','CUS-2TR-MIN','3x4/4x4 Semi Fondant',1,NULL),
('OVR-VIE-DAN','VIE-LEC-DAN','Standard',1,NULL),
('OVR-VIE-DLM','VIE-DBL-ALM','Standard',1,2.3833),
('OVR-VIE-DPC','VIE-DBL-PPC','Standard',1,NULL),
('OVR-VIE-HAC','VIE-HAC-CRO','Standard',1,NULL),
('OVR-VIE-PBU','VIE-PBU-CRO','Standard',1,NULL),
('OVR-VIE-PPC','VIE-PPC-CRO','Standard',1,NULL),
('OVR-VIE-STR','VIE-STR-CRO','Standard',1,NULL),
('PRE-C62-CIN','PRE-CAR-CIN','6x2',1,0.0000),
('PRE-C63-CIN','PRE-CAR-CIN','6x3',1,NULL),
('PRE-C64-CIN','PRE-CAR-CIN','6x4',1,NULL),
('PRE-C82-CIN','PRE-CAR-CIN','8x2',1,NULL),
('PRE-D62-MCH','PRE-DRU-MOI','6x2',1,NULL),
('PRE-D63-MCH','PRE-DRU-MOI','6x3',1,NULL),
('PRE-D64-MCH','PRE-DRU-MOI','6x4',1,NULL),
('PRE-D82-MCH','PRE-DRU-MOI','8x2',1,NULL),
('PRE-R62-RMC','PRE-CHC-RUM','6x2',1,NULL),
('PRE-R62-VEL','PRE-RED-VEL','6x2',1,NULL),
('PRE-R63-RMC','PRE-CHC-RUM','6x3',1,NULL),
('PRE-R63-VEL','PRE-RED-VEL','6x3',1,NULL),
('PRE-R64-RMC','PRE-CHC-RUM','6x4',1,NULL),
('PRE-R64-VEL','PRE-RED-VEL','6x4',1,NULL),
('PRE-R82-RMC','PRE-CHC-RUM','8x2',1,NULL),
('PRE-R82-VEL','PRE-RED-VEL','8x2',1,NULL),
('PRE-T62-CCC','PRE-TIR-CRE','6x2',1,NULL),
('PRE-T63-CCC','PRE-TIR-CRE','6x3',1,NULL),
('PRE-T64-CCC','PRE-TIR-CRE','6x4',1,NULL),
('PRE-T82-CCC','PRE-TIR-CRE','8x2',1,NULL),
('PRE-U62-MCP','PRE-UBE-MAC','6x2',1,NULL),
('PRE-U63-MCP','PRE-UBE-MAC','6x3',1,NULL),
('PRE-U64-MCP','PRE-UBE-MAC','6x4',1,NULL),
('PRE-U82-MCP','PRE-UBE-MAC','8x2',1,NULL),
('R2T-682-BTR','CUS-2TR-RND','6x2/8x2 Buttercream',1,NULL),
('R2T-682-FLF','CUS-2TR-RND','6x2/8x2 Full Fondant',1,NULL),
('R2T-682-SMF','CUS-2TR-RND','6x2/8x2 Semi Fondant',1,NULL),
('R2T-683-BTR','CUS-2TR-RND','6x3/8x3 Buttercream',1,NULL),
('R2T-683-FLF','CUS-2TR-RND','6x3/8x3 Full Fondant',1,NULL),
('R2T-683-SMF','CUS-2TR-RND','6x3/8x3 Semi Fondant',1,NULL),
('R2T-684-BTR','CUS-2TR-RND','6x4/8x4 Buttercream',1,NULL),
('R2T-684-FLF','CUS-2TR-RND','6x4/8x4 Full Fondant',1,NULL),
('R2T-684-SMF','CUS-2TR-RND','6x4/8x4 Semi Fondant',1,NULL),
('R2T-685-BTR','CUS-2TR-RND','6x5/8x5 Buttercream',1,NULL),
('R2T-685-FLF','CUS-2TR-RND','6x5/8x5 Full Fondant',1,NULL),
('R2T-685-SMF','CUS-2TR-RND','6x5/8x5 Semi Fondant',1,NULL),
('R2T-686-BTR','CUS-2TR-RND','6x6/8x6 Buttercream',1,NULL),
('R2T-686-FLF','CUS-2TR-RND','6x6/8x6 Full Fondant',1,NULL),
('R2T-686-SMF','CUS-2TR-RND','6x6/8x6 Semi Fondant',1,NULL),
('R2T-687-BTR','CUS-2TR-RND','6x7/8x7 Buttercream',1,NULL),
('R2T-687-FLF','CUS-2TR-RND','6x7/8x7 Full Fondant',1,NULL),
('R2T-687-SMF','CUS-2TR-RND','6x7/8x7 Semi Fondant',1,NULL),
('RD6-6X2-BTR','CUS-1TR-RND','6x2 Buttercream',1,0.0000),
('RD6-6X2-FLF','CUS-1TR-RND','6x2 Full Fondant',1,NULL),
('RD6-6X2-SMF','CUS-1TR-RND','6x2 Semi Fondant',1,NULL),
('RD6-6X3-BTR','CUS-1TR-RND','6x3 Buttercream',1,NULL),
('RD6-6X3-FLF','CUS-1TR-RND','6x3 Full Fondant',1,NULL),
('RD6-6X3-SMF','CUS-1TR-RND','6x3 Semi Fondant',1,NULL),
('RD6-6X4-BTR','CUS-1TR-RND','6x4 Buttercream',1,NULL),
('RD6-6X4-FLF','CUS-1TR-RND','6x4 Full Fondant',1,NULL),
('RD6-6X4-SMF','CUS-1TR-RND','6x4 Semi Fondant',1,NULL),
('RD6-6X5-BTR','CUS-1TR-RND','6x5 Buttercream',1,NULL),
('RD6-6X5-FLF','CUS-1TR-RND','6x5 Full Fondant',1,NULL),
('RD6-6X5-SMF','CUS-1TR-RND','6x5 Semi Fondant',1,NULL),
('RD6-6X6-BTR','CUS-1TR-RND','6x6 Buttercream',1,0.0000),
('RD6-6X6-FLF','CUS-1TR-RND','6x6 Full Fondant',1,NULL),
('RD6-6X6-SMF','CUS-1TR-RND','6x6 Semi Fondant',1,NULL),
('RD6-6X7-BTR','CUS-1TR-RND','6x7 Buttercream',1,NULL),
('RD6-6X7-FLF','CUS-1TR-RND','6x7 Full Fondant',1,0.0000),
('RD6-6X7-SMF','CUS-1TR-RND','6x7 Semi Fondant',1,NULL),
('RD8-8X2-BTR','CUS-1TR-RND','8x2 Buttercream',1,NULL),
('RD8-8X2-FLF','CUS-1TR-RND','8x2 Full Fondant',1,NULL),
('RD8-8X2-SMF','CUS-1TR-RND','8x2 Semi Fondant',1,NULL),
('RD8-8X3-BTR','CUS-1TR-RND','8x3 Buttercream',1,NULL),
('RD8-8X3-FLF','CUS-1TR-RND','8x3 Full Fondant',1,NULL),
('RD8-8X3-SMF','CUS-1TR-RND','8x3 Semi Fondant',1,NULL),
('RD8-8X4-BTR','CUS-1TR-RND','8x4 Buttercream',1,NULL),
('RD8-8X4-FLF','CUS-1TR-RND','8x4 Full Fondant',1,NULL),
('RD8-8X4-SMF','CUS-1TR-RND','8x4 Semi Fondant',1,NULL),
('RD8-8X5-BTR','CUS-1TR-RND','8x5 Buttercream',1,NULL),
('RD8-8X5-FLF','CUS-1TR-RND','8x5 Full Fondant',1,NULL),
('RD8-8X5-SMF','CUS-1TR-RND','8x5 Semi Fondant',1,NULL),
('RD8-8X6-BTR','CUS-1TR-RND','8x6 Buttercream',1,NULL),
('RD8-8X6-FLF','CUS-1TR-RND','8x6 Full Fondant',1,NULL),
('RD8-8X6-SMF','CUS-1TR-RND','8x6 Semi Fondant',1,NULL),
('RD8-8X7-BTR','CUS-1TR-RND','8x7 Buttercream',1,NULL),
('RD8-8X7-FLF','CUS-1TR-RND','8x7 Full Fondant',1,NULL),
('RD8-8X7-SMF','CUS-1TR-RND','8x7 Semi Fondant',1,NULL),
('REC-192-BTR','CUS-REC-CAK','12x9x2.5 Buttercream',1,NULL),
('REC-192-FLF','CUS-REC-CAK','12x9x2.5 Full Fondant',1,NULL),
('REC-192-SMF','CUS-REC-CAK','12x9x2.5 Semi Fondant',1,NULL),
('REC-962-BTR','CUS-REC-CAK','9x6x2.5 Buttercream',1,NULL),
('REC-962-FLF','CUS-REC-CAK','9x6x2.5 Full Fondant',1,NULL),
('REC-962-SMF','CUS-REC-CAK','9x6x2.5 Semi Fondant',1,NULL),
('SQR-662-BTR','CUS-SQR-CAK','6x6x2.5 Buttercream',1,NULL),
('SQR-662-FLF','CUS-SQR-CAK','6x6x2.5 Full Fondant',1,NULL),
('SQR-662-SMF','CUS-SQR-CAK','6x6x2.5 Semi Fondant',1,NULL),
('SQR-882-BTR','CUS-SQR-CAK','8x8x2.5 Buttercream',1,NULL),
('SQR-882-FLF','CUS-SQR-CAK','8x8x2.5 Full Fondant',1,NULL),
('SQR-882-SMF','CUS-SQR-CAK','8x8x2.5 Semi Fondant',1,NULL);

--
-- Table structure for table `m2222-recipe_ingredient`
--

DROP TABLE IF EXISTS `m2222-recipe_ingredient`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `m2222-recipe_ingredient` (
  `recing-Id` int(11) NOT NULL AUTO_INCREMENT,
  `recvar-Id` varchar(15) NOT NULL,
  `ingmaster-Id` varchar(30) NOT NULL,
  `recing-UnitType` text DEFAULT NULL,
  `recing-QtyNeed` decimal(10,2) NOT NULL,
  PRIMARY KEY (`recing-Id`),
  KEY `fk_recvar-Id` (`recvar-Id`),
  KEY `fkv_ingmaster-Id` (`ingmaster-Id`),
  CONSTRAINT `fk_recvar-Id` FOREIGN KEY (`recvar-Id`) REFERENCES `m222-recipe_variant2` (`recvar-Id`),
  CONSTRAINT `fkv_ingmaster-Id` FOREIGN KEY (`ingmaster-Id`) REFERENCES `m11-ingredient_master` (`ingmaster-Id`)
) ENGINE=InnoDB AUTO_INCREMENT=33 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `m2222-recipe_ingredient`
--

INSERT INTO `m2222-recipe_ingredient` (`recing-Id`, `recvar-Id`, `ingmaster-Id`, `recing-UnitType`, `recing-QtyNeed`) VALUES
(1,'OVR-VIE-STR','MAS-DRY-BF','',113.55),
(2,'OVR-VIE-STR','MAS-DRY-APF','',37.80),
(3,'OVR-VIE-STR','MAS-DRY-SLT','',3.00),
(4,'OVR-VIE-STR','MAS-DRY-WSR','',18.15),
(5,'OVR-VIE-STR','MAS-DAI-PM','',3.90),
(6,'OVR-VIE-STR','MAS-DRY-YST','',3.30),
(7,'OVR-VIE-STR','MAS-DAI-BTR','',4.80),
(8,'OVR-VIE-STR','MAS-DAI-FM','',40.05),
(9,'OVR-VIE-STR','MAS-DAI-LBS','',34.97),
(10,'OVR-VIE-STR','MAS-FRT-STR','',50.00),
(11,'OVR-VIE-STR','MAS-TOP-CHC','',55.00),
(12,'SQR-662-BTR','MAS-DRY-SLT','g',15.00),
(13,'SQR-662-BTR','MAS-DRY-BF','g',10.00),
(14,'SQR-662-BTR','MAS-DRY-WSR','g',10.00),
(15,'SQR-662-SMF','MAS-DRY-SLT','g',10.00),
(16,'SQR-662-SMF','MAS-DRY-BF','g',10.00),
(17,'SQR-662-SMF','MAS-DRY-WSR','g',20.00),
(18,'SQR-882-SMF','MAS-DRY-SLT','g',20.00),
(19,'SQR-882-SMF','MAS-DRY-BF','g',20.00),
(20,'SQR-882-SMF','MAS-DRY-WSR','g',20.00),
(21,'SQR-882-BTR','MAS-DRY-SLT','g',20.00),
(22,'SQR-882-BTR','MAS-DRY-BF','g',20.00),
(23,'SQR-882-BTR','MAS-DRY-WSR','g',30.00),
(24,'SQR-882-FLF','MAS-DAI-BTR','g',30.00),
(25,'SQR-882-FLF','MAS-DRY-BF','g',30.00),
(26,'SQR-882-FLF','MAS-DRY-APF','g',30.00),
(27,'SQR-662-FLF','MAS-DRY-APF','g',20.00),
(28,'SQR-662-FLF','MAS-DRY-SLT','g',20.00),
(29,'SQR-662-FLF','MAS-DRY-BF','g',20.00),
(30,'SQR-662-BTR','MAS-DAI-BTR','g',10.00),
(31,'OVR-VIE-DLM','MAS-DRY-APF','g',10.00),
(32,'OVR-VIE-DLM','MAS-DRY-BF','g',10.00);

--
-- Table structure for table `m3-production_batch`
--

DROP TABLE IF EXISTS `m3-production_batch`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `m3-production_batch` (
  `prodbatch-Id` int(11) NOT NULL AUTO_INCREMENT,
  `recvar-Id` varchar(15) NOT NULL,
  `prodbatch-Name` varchar(60) NOT NULL,
  `prodbatch-Qty` int(11) NOT NULL,
  `prodbatch-Status` text NOT NULL,
  PRIMARY KEY (`prodbatch-Id`),
  KEY `fk_prod_batch_recipe_variant3` (`recvar-Id`),
  CONSTRAINT `fk_prod_batch_recipe_variant3` FOREIGN KEY (`recvar-Id`) REFERENCES `m222-recipe_variant3` (`recvar-Id`)
) ENGINE=InnoDB AUTO_INCREMENT=23 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `m3-production_batch`
--

INSERT INTO `m3-production_batch` (`prodbatch-Id`, `recvar-Id`, `prodbatch-Name`, `prodbatch-Qty`, `prodbatch-Status`) VALUES
(4,'SQR-662-BTR','Base Square Cake | 6x6x2.5 | Buttercream',1,'Finished'),
(5,'SQR-662-SMF','Base Square Cake | 6x6x2.5 | Semi Fondant',2,'Cancelled'),
(6,'SQR-662-BTR','Base Square Cake | 6x6x2.5 | Buttercream',2,'Finished'),
(7,'SQR-662-BTR','Base Square Cake | 6x6x2.5 | Buttercream',2,'Finished'),
(8,'SQR-662-BTR','Base Square Cake | 6x6x2.5 | Buttercream',1,'Finished'),
(9,'SQR-662-BTR','Base Square Cake | 6x6x2.5 | Buttercream',1,'Finished'),
(10,'SQR-662-BTR','Base Square Cake | 6x6x2.5 | Buttercream',1,'Finished'),
(11,'SQR-662-BTR','Base Square Cake | 6x6x2.5 | Buttercream',2,'Cancelled'),
(12,'SQR-662-BTR','Base Square Cake | 6x6x2.5 | Buttercream',1,'Finished'),
(13,'SQR-662-BTR','Base Square Cake | 6x6x2.5 | Buttercream',1,'Pending'),
(14,'OVR-VIE-DLM','Double Baked Almond Croissant | Standard',2,'Finished'),
(20,'OVR-VIE-DLM','Double Baked Almond Croissant | Standard',1,'Finished'),
(21,'OVR-VIE-STR','Strawberry Croissant | Standard',1,'Pending');

--
-- Table structure for table `m3.1-custom_cake_design`
--

DROP TABLE IF EXISTS `m3.1-custom_cake_design`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `m3.1-custom_cake_design` (
  `ccd-Id` int(11) NOT NULL AUTO_INCREMENT,
  `prodbatch-Id` int(11) NOT NULL,
  `ccd-BaseColor` varchar(7) NOT NULL,
  `ccd-AccentColor` varchar(7) NOT NULL,
  `ccd-Toppings` varchar(255) NOT NULL DEFAULT '',
  `ccd-Message` varchar(40) NOT NULL DEFAULT '',
  `ccd-CustomerName` varchar(60) DEFAULT NULL,
  `ccd-PickupDate` date DEFAULT NULL,
  `ccd-Notes` text DEFAULT NULL,
  `ccd-Design` text DEFAULT NULL,
  `ccd-CreatedAt` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`ccd-Id`),
  UNIQUE KEY `uq_ccd_prodbatch` (`prodbatch-Id`),
  CONSTRAINT `fk_ccd_prodbatch` FOREIGN KEY (`prodbatch-Id`) REFERENCES `m3-production_batch` (`prodbatch-Id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `m3.1-custom_cake_design`
--


--
-- Table structure for table `m33-production_log`
--

DROP TABLE IF EXISTS `m33-production_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `m33-production_log` (
  `prodlog-Id` int(11) NOT NULL AUTO_INCREMENT,
  `prodbatch-Id` int(11) NOT NULL,
  `prodlog-QtySuccess` int(10) DEFAULT NULL,
  `prodlog-QtyError` int(10) DEFAULT NULL,
  `prodlog-ExpiryDate` date DEFAULT NULL,
  `prodlog-Timestamp` datetime NOT NULL,
  `prodlog-Reason` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`prodlog-Id`),
  KEY `fk_prodbatch-Id` (`prodbatch-Id`),
  CONSTRAINT `fk_prodbatch-Id` FOREIGN KEY (`prodbatch-Id`) REFERENCES `m3-production_batch` (`prodbatch-Id`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `m33-production_log`
--

INSERT INTO `m33-production_log` (`prodlog-Id`, `prodbatch-Id`, `prodlog-QtySuccess`, `prodlog-QtyError`, `prodlog-ExpiryDate`, `prodlog-Timestamp`, `prodlog-Reason`) VALUES
(1,4,1,0,'2026-10-31','2026-10-20 14:52:47',''),
(2,6,1,1,'2026-10-31','2026-10-20 15:01:29','Burnt'),
(3,7,1,1,'2026-10-30','2026-10-20 15:08:28','Burnt'),
(4,9,1,0,'2026-10-31','2026-10-20 16:54:26',''),
(5,10,1,0,'2026-10-31','2026-10-20 16:55:13',''),
(6,8,1,0,'2026-10-19','2026-10-20 16:56:15',''),
(7,12,1,0,'2026-11-30','2026-11-01 11:25:30',''),
(8,14,2,0,'2026-11-30','2026-11-01 12:41:55',''),
(9,20,1,0,'2026-11-30','2026-11-19 10:34:32','');

--
-- Table structure for table `m4-finishedgoods_master`
--

DROP TABLE IF EXISTS `m4-finishedgoods_master`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `m4-finishedgoods_master` (
  `fgm-Id` int(11) NOT NULL AUTO_INCREMENT,
  `prodlog-Id` int(11) NOT NULL,
  `fgm-QtyStock` smallint(6) NOT NULL,
  `fgm-ExpiryDate` date NOT NULL,
  `fgm-Status` text NOT NULL,
  PRIMARY KEY (`fgm-Id`),
  KEY `fk_prodlog-Id` (`prodlog-Id`),
  CONSTRAINT `fk_prodlog-Id` FOREIGN KEY (`prodlog-Id`) REFERENCES `m33-production_log` (`prodlog-Id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `m4-finishedgoods_master`
--

INSERT INTO `m4-finishedgoods_master` (`fgm-Id`, `prodlog-Id`, `fgm-QtyStock`, `fgm-ExpiryDate`, `fgm-Status`) VALUES
(1,4,2,'2026-10-31','Expired'),
(2,5,1,'2026-10-20','Expired'),
(3,6,1,'2026-10-19','Expired'),
(4,7,1,'2026-11-30','In-Stock'),
(5,8,0,'2026-11-30','In-Stock'),
(6,9,0,'2026-11-30','In-Stock');

--
-- Table structure for table `m5-waste_log`
--

DROP TABLE IF EXISTS `m5-waste_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `m5-waste_log` (
  `waste-Id` int(11) NOT NULL AUTO_INCREMENT,
  `fgm-Id` int(11) DEFAULT NULL,
  `prodlog-Id` int(11) DEFAULT NULL,
  `ing-Id` int(11) DEFAULT NULL,
  `waste-Qty` int(11) NOT NULL,
  `waste-UnitCost` double(10,5) NOT NULL,
  `waste-TotalCost` double(11,2) NOT NULL,
  `waste-Reason` varchar(255) NOT NULL,
  `waste-Timestamp` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`waste-Id`),
  KEY `fk_fgmId` (`fgm-Id`),
  KEY `fk_ing-Id` (`ing-Id`),
  KEY `fk_prlg` (`prodlog-Id`),
  CONSTRAINT `fk_fgmId` FOREIGN KEY (`fgm-Id`) REFERENCES `m4-finishedgoods_master` (`fgm-Id`),
  CONSTRAINT `fk_ing-Id` FOREIGN KEY (`ing-Id`) REFERENCES `m111-ingredient_item` (`ing-Id`),
  CONSTRAINT `fk_prlg` FOREIGN KEY (`prodlog-Id`) REFERENCES `m33-production_log` (`prodlog-Id`)
) ENGINE=InnoDB AUTO_INCREMENT=33 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `m5-waste_log`
--

INSERT INTO `m5-waste_log` (`waste-Id`, `fgm-Id`, `prodlog-Id`, `ing-Id`, `waste-Qty`, `waste-UnitCost`, `waste-TotalCost`, `waste-Reason`, `waste-Timestamp`) VALUES
(1,NULL,2,NULL,1,39.00000,39.00,'Burnt','2026-10-20 16:06:20'),
(2,NULL,3,NULL,1,39.00000,39.00,'Burnt','2026-10-20 16:06:20'),
(3,3,NULL,NULL,1,39.00000,39.00,'Expired','2026-10-20 16:06:20'),
(4,NULL,NULL,2,1,1.85000,1.83,'Expired','2026-10-20 16:06:20'),
(5,NULL,NULL,3,1,0.10000,40.00,'Expired','2026-10-20 16:06:20'),
(6,NULL,NULL,9,1,0.87500,350.00,'Expired','2026-10-20 16:06:20'),
(7,NULL,NULL,10,1,2.16667,65.00,'Expired','2026-10-20 16:06:20'),
(8,NULL,NULL,11,1,1.08333,65.00,'Expired','2026-10-20 16:06:20'),
(9,NULL,NULL,12,1,1.50000,300.00,'Expired','2026-10-20 16:06:20'),
(10,NULL,NULL,13,1,0.50000,1.00,'Expired','2026-10-20 16:06:20'),
(11,NULL,NULL,14,1,1.00000,2.00,'Expired','2026-10-20 16:06:20'),
(12,NULL,NULL,15,1,1.50000,30.00,'Expired','2026-10-20 16:06:20'),
(13,NULL,NULL,16,1,4.00000,20.00,'Expired','2026-10-20 16:06:20'),
(14,NULL,NULL,17,1,1.50000,90.00,'Expired','2026-10-20 16:06:20'),
(15,NULL,NULL,18,1,0.80000,80.00,'Expired','2026-10-20 16:06:20'),
(16,NULL,NULL,19,1,17.50000,700.00,'Expired','2026-10-20 16:06:20'),
(17,NULL,NULL,20,1,6.00000,300.00,'Expired','2026-10-20 16:06:20'),
(18,NULL,NULL,21,1,3.33333,100.00,'Expired','2026-10-20 16:06:20'),
(19,NULL,NULL,22,1,0.75000,15.00,'Expired','2026-10-20 16:06:20'),
(20,NULL,NULL,24,1,1.25000,50.00,'Expired','2026-10-20 16:06:20'),
(21,NULL,NULL,32,1,0.75000,300.00,'Expired','2026-10-20 16:14:07'),
(22,2,NULL,NULL,1,39.00000,39.00,'Expired','2026-10-20 16:17:10'),
(23,1,NULL,NULL,2,30.50000,61.00,'Expired','2026-11-18 04:32:52'),
(24,NULL,NULL,6,1,38.33333,765.90,'Expired','2026-11-18 04:32:52'),
(25,NULL,NULL,7,1,38.33333,958.33,'Expired','2026-11-18 04:32:52'),
(26,NULL,NULL,8,1,40.00000,600.00,'Expired','2026-11-18 04:32:52'),
(27,NULL,NULL,26,1,0.40000,180.00,'Expired','2026-11-18 04:32:52'),
(28,NULL,NULL,30,1,2.00000,340.00,'Expired','2026-11-18 04:32:52'),
(29,NULL,NULL,31,1,1.50000,255.00,'Expired','2026-11-18 04:32:52'),
(30,NULL,NULL,33,1,2.00000,400.00,'Expired','2026-11-18 04:32:52'),
(31,NULL,NULL,34,1,0.50000,50.00,'Expired','2026-11-18 04:32:52'),
(32,NULL,NULL,35,1,0.04000,19.40,'Expired','2026-11-18 04:32:52');

--
-- Table structure for table `m6-history`
--

DROP TABLE IF EXISTS `m6-history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `m6-history` (
  `hist-Id` int(11) NOT NULL AUTO_INCREMENT,
  `ing-Id` int(11) DEFAULT NULL,
  `recing-Id` int(11) DEFAULT NULL,
  `prodbatch-Id` int(11) DEFAULT NULL,
  `fgm-Id` int(11) DEFAULT NULL,
  `hist-Action` text NOT NULL,
  `hist-Source` text NOT NULL,
  `hist-Timestamp` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`hist-Id`),
  KEY `fk_ing` (`ing-Id`),
  KEY `fk_prodb` (`prodbatch-Id`),
  KEY `fk_fgm` (`fgm-Id`),
  KEY `fk_recing` (`recing-Id`),
  CONSTRAINT `fk_fgm` FOREIGN KEY (`fgm-Id`) REFERENCES `m4-finishedgoods_master` (`fgm-Id`),
  CONSTRAINT `fk_ing` FOREIGN KEY (`ing-Id`) REFERENCES `m111-ingredient_item` (`ing-Id`),
  CONSTRAINT `fk_prodb` FOREIGN KEY (`prodbatch-Id`) REFERENCES `m3-production_batch` (`prodbatch-Id`),
  CONSTRAINT `fk_recing` FOREIGN KEY (`recing-Id`) REFERENCES `m2222-recipe_ingredient` (`recing-Id`)
) ENGINE=InnoDB AUTO_INCREMENT=23 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `m6-history`
--

INSERT INTO `m6-history` (`hist-Id`, `ing-Id`, `recing-Id`, `prodbatch-Id`, `fgm-Id`, `hist-Action`, `hist-Source`, `hist-Timestamp`) VALUES
(1,1,NULL,NULL,NULL,'Stock Out Ingredients','Raw Ingredients','2026-10-21 05:59:55'),
(2,6,NULL,NULL,NULL,'Stock Out Ingredients','Raw Ingredients','2026-10-21 06:04:20'),
(3,8,NULL,NULL,NULL,'Stock Out Ingredients','Raw Ingredients','2026-10-21 06:05:44'),
(4,33,NULL,NULL,NULL,'Stock In Ingredients','Raw Ingredients','2026-10-21 06:06:18'),
(5,NULL,NULL,NULL,1,'Edited Batch Quantity/Expiration Date','Finished Goods','2026-10-21 06:06:57'),
(6,NULL,12,NULL,NULL,'Edited Recipe Ingredient','Recipe Ingredient Table','2026-10-21 06:07:46'),
(7,NULL,30,NULL,NULL,'Added Recipe Ingredient','Recipe Ingredient Table','2026-10-21 06:08:59'),
(8,NULL,NULL,11,NULL,'Edited Batch','Production Queue','2026-10-21 06:10:10'),
(9,NULL,NULL,11,NULL,'Cancelled Batch','Production Queue','2026-10-21 06:10:20'),
(10,34,NULL,NULL,NULL,'Stock In Ingredients','Raw Ingredients','2026-10-27 11:52:35'),
(11,35,NULL,NULL,NULL,'Stock In Ingredients','Raw Ingredients','2026-11-01 03:23:13'),
(12,36,NULL,NULL,NULL,'Stock In Ingredients','Raw Ingredients','2026-11-01 03:24:05'),
(13,37,NULL,NULL,NULL,'Stock In Ingredients','Raw Ingredients','2026-11-01 03:24:32'),
(14,38,NULL,NULL,NULL,'Stock In Ingredients','Raw Ingredients','2026-11-01 03:25:09'),
(15,NULL,31,NULL,NULL,'Added Recipe Ingredient','Recipe Ingredient Table','2026-11-01 04:18:01'),
(16,NULL,32,NULL,NULL,'Added Recipe Ingredient','Recipe Ingredient Table','2026-11-01 04:18:11'),
(22,NULL,NULL,21,NULL,'Edited Batch','Production Queue','2026-11-19 03:30:03');

--
-- Table structure for table `m8-dispatch_log`
--

DROP TABLE IF EXISTS `m8-dispatch_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `m8-dispatch_log` (
  `dl-Id` int(11) NOT NULL AUTO_INCREMENT,
  `fgm-Id` int(11) NOT NULL,
  `rec-Id` varchar(15) NOT NULL,
  `dl-RecipeVariant` varchar(50) DEFAULT NULL,
  `dl-Timestamp` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `dl-QtyToSend` int(11) NOT NULL,
  `dl-Received` int(11) DEFAULT NULL,
  `dl-Status` text NOT NULL,
  PRIMARY KEY (`dl-Id`),
  KEY `fkd_fgmId` (`fgm-Id`),
  KEY `fkd_rec-Id` (`rec-Id`),
  CONSTRAINT `fkd_fgmId` FOREIGN KEY (`fgm-Id`) REFERENCES `m4-finishedgoods_master` (`fgm-Id`),
  CONSTRAINT `fkd_rec-Id` FOREIGN KEY (`rec-Id`) REFERENCES `m22-recipe_master` (`rec-Id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `m8-dispatch_log`
--

INSERT INTO `m8-dispatch_log` (`dl-Id`, `fgm-Id`, `rec-Id`, `dl-RecipeVariant`, `dl-Timestamp`, `dl-QtyToSend`, `dl-Received`, `dl-Status`) VALUES
(1,4,'CUS-SQR-CAK','SQR-662-BTR','2026-11-01 18:47:28',1,0,'Cancelled'),
(2,5,'VIE-DBL-ALM','OVR-VIE-DLM','2026-11-01 18:46:47',1,1,'Received'),
(3,5,'VIE-DBL-ALM','OVR-VIE-DLM','2026-11-01 18:55:43',1,1,'Received'),
(4,4,'CUS-SQR-CAK','SQR-662-BTR','2026-11-18 05:55:15',1,0,'Received'),
(5,6,'VIE-DBL-ALM','OVR-VIE-DLM','2026-11-19 02:34:59',1,1,'Received'),
(6,4,'CUS-SQR-CAK','SQR-662-BTR','2026-11-19 03:30:52',1,0,'Pending');

--
-- Table structure for table `m9-dispatch_record`
--

DROP TABLE IF EXISTS `m9-dispatch_record`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `m9-dispatch_record` (
  `dr-Id` int(11) NOT NULL AUTO_INCREMENT,
  `dl-Id` int(11) NOT NULL,
  `dr-Timestamp` timestamp NULL DEFAULT NULL,
  `dr-ReceivedQty` int(11) NOT NULL,
  `dr-ExpiryDate` date NOT NULL,
  `dr-Status` text NOT NULL,
  PRIMARY KEY (`dr-Id`),
  KEY `fk_dl-Id` (`dl-Id`),
  CONSTRAINT `fk_dl-Id` FOREIGN KEY (`dl-Id`) REFERENCES `m8-dispatch_log` (`dl-Id`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `m9-dispatch_record`
--

INSERT INTO `m9-dispatch_record` (`dr-Id`, `dl-Id`, `dr-Timestamp`, `dr-ReceivedQty`, `dr-ExpiryDate`, `dr-Status`) VALUES
(5,2,'2026-11-01 18:46:47',1,'2026-11-30','Received'),
(6,1,'2026-11-01 18:47:28',0,'2026-11-30','Cancelled'),
(7,3,'2026-11-01 18:55:43',1,'2026-11-30','Received'),
(8,4,'2026-11-18 05:55:15',0,'2026-11-30','Received'),
(9,5,'2026-11-19 02:34:59',1,'2026-11-30','Received');
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-11-19 17:19:57
