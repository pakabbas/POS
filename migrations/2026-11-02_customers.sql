-- 2026-11-02: Customers, cash/credit sales, customer ledger, product sort order
-- Applied to live DB on 2026-11-02 (backup: ~/pos_backups/pos_before_customers_20261102_1036.sql)

CREATE TABLE IF NOT EXISTS `Customers` (
  `CustomerID` int(11) NOT NULL AUTO_INCREMENT,
  `CustomerName` varchar(150) NOT NULL,
  `Phone` varchar(30) DEFAULT NULL,
  `Address` varchar(255) DEFAULT NULL,
  `Area` varchar(100) DEFAULT NULL,
  `OpeningBalance` decimal(12,2) NOT NULL DEFAULT 0.00,
  `CreditLimit` decimal(12,2) DEFAULT NULL,
  `Notes` text DEFAULT NULL,
  `IsActive` tinyint(1) NOT NULL DEFAULT 1,
  `CreatedAt` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`CustomerID`),
  KEY `idx_customers_name` (`CustomerName`),
  KEY `idx_customers_phone` (`Phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Invoices: who the sale was for, Cash vs Credit, and how much was received at the time of sale
ALTER TABLE `Invoices`
  ADD COLUMN `CustomerID` int(11) DEFAULT NULL AFTER `UserID`,
  ADD COLUMN `PaymentType` varchar(10) NOT NULL DEFAULT 'Cash' AFTER `TotalAmount`,
  ADD COLUMN `AmountPaid` decimal(10,2) DEFAULT NULL AFTER `PaymentType`,
  ADD KEY `idx_invoices_customer` (`CustomerID`),
  ADD CONSTRAINT `Invoices_customer_fk` FOREIGN KEY (`CustomerID`) REFERENCES `Customers` (`CustomerID`);

-- Money received from customers (credit side of the ledger).
-- InvoiceID is set when the amount was received at the time of a credit sale;
-- it cascades so deleting an invoice (reports.php) also removes its at-sale receipt.
CREATE TABLE IF NOT EXISTS `CustomerPayments` (
  `CustomerPaymentID` int(11) NOT NULL AUTO_INCREMENT,
  `CustomerID` int(11) NOT NULL,
  `InvoiceID` int(11) DEFAULT NULL,
  `Amount` decimal(12,2) NOT NULL,
  `PaymentDate` datetime NOT NULL DEFAULT current_timestamp(),
  `Method` varchar(50) DEFAULT 'Cash',
  `Note` varchar(255) DEFAULT NULL,
  `UserID` int(11) DEFAULT NULL,
  `CreatedAt` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`CustomerPaymentID`),
  KEY `idx_cp_customer_date` (`CustomerID`, `PaymentDate`),
  KEY `idx_cp_invoice` (`InvoiceID`),
  CONSTRAINT `CustomerPayments_customer_fk` FOREIGN KEY (`CustomerID`) REFERENCES `Customers` (`CustomerID`),
  CONSTRAINT `CustomerPayments_invoice_fk` FOREIGN KEY (`InvoiceID`) REFERENCES `Invoices` (`InvoiceID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Products: explicit display order on POS / products list
ALTER TABLE `Products` ADD COLUMN `SortOrder` int(11) NOT NULL DEFAULT 100 AFTER `Price`;
UPDATE `Products` SET `SortOrder` = 1 WHERE `ProductName` = 'Desi Egg';
UPDATE `Products` SET `SortOrder` = 2 WHERE `ProductName` = 'XL Egg';
UPDATE `Products` SET `SortOrder` = 3 WHERE `ProductName` = 'Large Egg';
UPDATE `Products` SET `SortOrder` = 4 WHERE `ProductName` = 'Medium Egg';
UPDATE `Products` SET `SortOrder` = 5 WHERE `ProductName` = 'Small Egg';
