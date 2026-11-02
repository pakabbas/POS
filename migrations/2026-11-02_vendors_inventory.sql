-- 2026-11-02: Vendors, purchases (stock-in), inventory / stock adjustments, vendor ledger and payouts.
-- Written for MariaDB (live server). Safe to run more than once:
--   * CREATE TABLE IF NOT EXISTS for every table (the live DB already has empty
--     Vendors / VendorPayments / PurchaseOrders / PurchaseOrderItems / StockMovements /
--     ProductInventory tables from the original install; they are reused, not recreated)
--   * ADD COLUMN IF NOT EXISTS / ADD INDEX IF NOT EXISTS for the new columns.
--
-- Rules used by the app (includes/vendors_lib.php, includes/inventory_lib.php):
--   Vendor payable = Vendors.OpeningBalance + TotalAmount of Credit purchases - VendorPayments
--   (cash purchases are settled on the spot and do not touch the ledger; the amount paid at a
--    credit purchase is stored as a VendorPayments row linked by PurchaseOrderID).
--   Stock (crates) per product = ProductInventory.OpeningStock (counted at OpeningStockDate)
--     + received purchase quantities + StockMovements adjustments (signed)
--     - InvoiceItems quantities sold, everything dated on/after OpeningStockDate.

CREATE TABLE IF NOT EXISTS `Vendors` (
  `VendorID` int(11) NOT NULL AUTO_INCREMENT,
  `VendorName` varchar(200) NOT NULL,
  `ContactPerson` varchar(150) DEFAULT NULL,
  `Email` varchar(255) DEFAULT NULL,
  `Phone` varchar(50) DEFAULT NULL,
  `Mobile` varchar(50) DEFAULT NULL,
  `Address` text DEFAULT NULL,
  `City` varchar(100) DEFAULT NULL,
  `State` varchar(100) DEFAULT NULL,
  `PostalCode` varchar(20) DEFAULT NULL,
  `Country` varchar(100) DEFAULT 'Pakistan',
  `TaxID` varchar(100) DEFAULT NULL,
  `PaymentTerms` varchar(100) DEFAULT NULL,
  `CreditLimit` decimal(12,2) DEFAULT 0.00,
  `AccountNumber` varchar(100) DEFAULT NULL,
  `BankName` varchar(200) DEFAULT NULL,
  `Notes` text DEFAULT NULL,
  `IsActive` tinyint(1) DEFAULT 1,
  `CreatedAt` datetime DEFAULT current_timestamp(),
  `UpdatedAt` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`VendorID`),
  KEY `IsActive` (`IsActive`),
  KEY `VendorName` (`VendorName`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `Vendors`
  ADD COLUMN IF NOT EXISTS `OpeningBalance` decimal(12,2) NOT NULL DEFAULT 0.00 AFTER `CreditLimit`;

CREATE TABLE IF NOT EXISTS `PurchaseOrders` (
  `PurchaseOrderID` int(11) NOT NULL AUTO_INCREMENT,
  `PONumber` varchar(50) NOT NULL,
  `VendorID` int(11) NOT NULL,
  `OrderDate` datetime DEFAULT current_timestamp(),
  `ExpectedDeliveryDate` datetime DEFAULT NULL,
  `Status` enum('Draft','Pending','Approved','PartiallyReceived','Received','Cancelled') DEFAULT 'Draft',
  `Subtotal` decimal(12,2) DEFAULT 0.00,
  `TaxAmount` decimal(12,2) DEFAULT 0.00,
  `ShippingCost` decimal(12,2) DEFAULT 0.00,
  `Discount` decimal(12,2) DEFAULT 0.00,
  `TotalAmount` decimal(12,2) DEFAULT 0.00,
  `CreatedBy` int(11) DEFAULT NULL,
  `ApprovedBy` int(11) DEFAULT NULL,
  `ApprovedAt` datetime DEFAULT NULL,
  `ReceivedBy` int(11) DEFAULT NULL,
  `ReceivedAt` datetime DEFAULT NULL,
  `Notes` text DEFAULT NULL,
  `CreatedAt` datetime DEFAULT current_timestamp(),
  `UpdatedAt` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`PurchaseOrderID`),
  UNIQUE KEY `PONumber` (`PONumber`),
  KEY `VendorID` (`VendorID`),
  KEY `Status` (`Status`),
  KEY `OrderDate` (`OrderDate`),
  KEY `PurchaseOrders_ibfk_2` (`CreatedBy`),
  KEY `PurchaseOrders_ibfk_3` (`ApprovedBy`),
  KEY `PurchaseOrders_ibfk_4` (`ReceivedBy`),
  CONSTRAINT `PurchaseOrders_ibfk_1` FOREIGN KEY (`VendorID`) REFERENCES `Vendors` (`VendorID`),
  CONSTRAINT `PurchaseOrders_ibfk_2` FOREIGN KEY (`CreatedBy`) REFERENCES `Users` (`UserID`) ON DELETE SET NULL,
  CONSTRAINT `PurchaseOrders_ibfk_3` FOREIGN KEY (`ApprovedBy`) REFERENCES `Users` (`UserID`) ON DELETE SET NULL,
  CONSTRAINT `PurchaseOrders_ibfk_4` FOREIGN KEY (`ReceivedBy`) REFERENCES `Users` (`UserID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A purchase entry (stock-in) is a PurchaseOrders row with Status = 'Received'.
ALTER TABLE `PurchaseOrders`
  ADD COLUMN IF NOT EXISTS `PaymentType` varchar(10) NOT NULL DEFAULT 'Credit' AFTER `TotalAmount`,
  ADD COLUMN IF NOT EXISTS `AmountPaid` decimal(12,2) NOT NULL DEFAULT 0.00 AFTER `PaymentType`;

CREATE TABLE IF NOT EXISTS `PurchaseOrderItems` (
  `POItemID` int(11) NOT NULL AUTO_INCREMENT,
  `PurchaseOrderID` int(11) NOT NULL,
  `ProductID` int(11) NOT NULL,
  `QuantityOrdered` decimal(10,2) NOT NULL DEFAULT 0.00,
  `UnitPrice` decimal(10,2) NOT NULL DEFAULT 0.00,
  `TotalPrice` decimal(12,2) NOT NULL DEFAULT 0.00,
  `QuantityReceived` decimal(10,2) DEFAULT 0.00,
  `Notes` text DEFAULT NULL,
  `CreatedAt` datetime DEFAULT current_timestamp(),
  `UpdatedAt` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`POItemID`),
  KEY `PurchaseOrderID` (`PurchaseOrderID`),
  KEY `ProductID` (`ProductID`),
  CONSTRAINT `PurchaseOrderItems_ibfk_1` FOREIGN KEY (`PurchaseOrderID`) REFERENCES `PurchaseOrders` (`PurchaseOrderID`) ON DELETE CASCADE,
  CONSTRAINT `PurchaseOrderItems_ibfk_2` FOREIGN KEY (`ProductID`) REFERENCES `Products` (`ProductID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `VendorPayments` (
  `PaymentID` int(11) NOT NULL AUTO_INCREMENT,
  `VendorID` int(11) NOT NULL,
  `PurchaseOrderID` int(11) DEFAULT NULL,
  `PaymentDate` datetime DEFAULT current_timestamp(),
  `AmountPaid` decimal(12,2) NOT NULL DEFAULT 0.00,
  `PaymentMethod` varchar(50) DEFAULT NULL,
  `ReferenceNumber` varchar(100) DEFAULT NULL,
  `Notes` text DEFAULT NULL,
  `CreatedBy` int(11) DEFAULT NULL,
  `CreatedAt` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`PaymentID`),
  KEY `VendorID` (`VendorID`),
  KEY `PurchaseOrderID` (`PurchaseOrderID`),
  KEY `PaymentDate` (`PaymentDate`),
  KEY `VendorPayments_ibfk_3` (`CreatedBy`),
  CONSTRAINT `VendorPayments_ibfk_1` FOREIGN KEY (`VendorID`) REFERENCES `Vendors` (`VendorID`),
  CONSTRAINT `VendorPayments_ibfk_2` FOREIGN KEY (`PurchaseOrderID`) REFERENCES `PurchaseOrders` (`PurchaseOrderID`) ON DELETE SET NULL,
  CONSTRAINT `VendorPayments_ibfk_3` FOREIGN KEY (`CreatedBy`) REFERENCES `Users` (`UserID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ProductInventory` (
  `InventoryID` int(11) NOT NULL AUTO_INCREMENT,
  `ProductID` int(11) NOT NULL,
  `CurrentStock` decimal(10,2) DEFAULT 0.00,
  `ReorderLevel` decimal(10,2) DEFAULT 0.00,
  `ReorderQuantity` decimal(10,2) DEFAULT 0.00,
  `UnitOfMeasure` varchar(50) DEFAULT 'Unit',
  `Location` varchar(100) DEFAULT NULL,
  `LastRestockedDate` datetime DEFAULT NULL,
  `LastRestockedBy` int(11) DEFAULT NULL,
  `CostPrice` decimal(10,2) DEFAULT 0.00,
  `AverageCost` decimal(10,2) DEFAULT 0.00,
  `Notes` text DEFAULT NULL,
  `CreatedAt` datetime DEFAULT current_timestamp(),
  `UpdatedAt` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`InventoryID`),
  UNIQUE KEY `ProductID` (`ProductID`),
  KEY `CurrentStock` (`CurrentStock`),
  KEY `ReorderLevel` (`ReorderLevel`),
  KEY `ProductInventory_ibfk_2` (`LastRestockedBy`),
  CONSTRAINT `ProductInventory_ibfk_1` FOREIGN KEY (`ProductID`) REFERENCES `Products` (`ProductID`) ON DELETE CASCADE,
  CONSTRAINT `ProductInventory_ibfk_2` FOREIGN KEY (`LastRestockedBy`) REFERENCES `Users` (`UserID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Opening stock is a physical count taken at OpeningStockDate; only movements on/after that
-- moment change it. The old CurrentStock column is not used; stock is always computed
-- (includes/inventory_lib.php) so it can never drift from invoices and purchases.
ALTER TABLE `ProductInventory`
  ADD COLUMN IF NOT EXISTS `OpeningStock` decimal(10,2) NOT NULL DEFAULT 0.00 AFTER `ProductID`,
  ADD COLUMN IF NOT EXISTS `OpeningStockDate` datetime DEFAULT NULL AFTER `OpeningStock`;

-- One inventory row per product, unit = crate
INSERT INTO `ProductInventory` (`ProductID`, `UnitOfMeasure`)
SELECT p.`ProductID`, 'Crate' FROM `Products` p
WHERE NOT EXISTS (SELECT 1 FROM `ProductInventory` pi WHERE pi.`ProductID` = p.`ProductID`);

-- Products that have never been counted start at 0 crates from the moment this migration runs
-- (Asia/Karachi time, the zone the app stamps invoices in), instead of subtracting every
-- historical sale. Enter the real count on inventory.php -> "Set count".
UPDATE `ProductInventory`
   SET `OpeningStockDate` = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 5 HOUR)
 WHERE `OpeningStockDate` IS NULL AND `OpeningStock` = 0;

CREATE TABLE IF NOT EXISTS `StockMovements` (
  `MovementID` int(11) NOT NULL AUTO_INCREMENT,
  `ProductID` int(11) NOT NULL,
  `MovementType` enum('Purchase','Sale','Adjustment','Return','Transfer','Waste','Expired') NOT NULL,
  `Quantity` decimal(10,2) NOT NULL,
  `ReferenceID` int(11) DEFAULT NULL,
  `ReferenceType` varchar(50) DEFAULT NULL,
  `MovementDate` datetime DEFAULT current_timestamp(),
  `PerformedBy` int(11) DEFAULT NULL,
  `CostPrice` decimal(10,2) DEFAULT NULL,
  `Notes` text DEFAULT NULL,
  `CreatedAt` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`MovementID`),
  KEY `ProductID` (`ProductID`),
  KEY `MovementType` (`MovementType`),
  KEY `MovementDate` (`MovementDate`),
  KEY `ReferenceID` (`ReferenceID`,`ReferenceType`),
  KEY `StockMovements_ibfk_2` (`PerformedBy`),
  CONSTRAINT `StockMovements_ibfk_1` FOREIGN KEY (`ProductID`) REFERENCES `Products` (`ProductID`),
  CONSTRAINT `StockMovements_ibfk_2` FOREIGN KEY (`PerformedBy`) REFERENCES `Users` (`UserID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- StockMovements holds manual adjustments only (Waste = damage/breakage, Adjustment = count
-- correction, Return = returned to vendor/from customer). Quantity is signed (+ adds, - removes).
-- Purchases and sales are read from PurchaseOrderItems / InvoiceItems so there is a single source.

ALTER TABLE `Invoices` ADD INDEX IF NOT EXISTS `idx_invoices_date` (`InvoiceDate`);
