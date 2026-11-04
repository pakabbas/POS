-- 2026-11-04: trade in PETI instead of crates.
--   1 peti = 12 crates = 360 eggs.
--
-- Storage design
--   * Every stock / trading quantity column now holds PETI as DECIMAL(12,4):
--       InvoiceItems.Quantity, InvoiceItems_Deleted.Quantity (were INT),
--       PurchaseOrderItems.QuantityOrdered / QuantityReceived,
--       ProductInventory.OpeningStock / CurrentStock / ReorderLevel / ReorderQuantity,
--       StockMovements.Quantity (signed stock effect in peti).
--   * Every unit price is per peti: Products.Price, InvoiceItems.UnitPrice,
--     PurchaseOrderItems.UnitPrice, ProductInventory.CostPrice / AverageCost, StockMovements.CostPrice.
--   * Stock adjustments (breakage, expired, returns, count corrections) are entered in CRATES.
--     The crates typed are kept in the new column StockMovements.Crates and the stock effect is
--     stored as Quantity = Crates / 12 peti (4 decimals; shown rounded to whole crates).
--   * Sales on the POS are whole peti (1-9999); purchases and counts may use fractions of a peti.
--
-- Existing data conversion (all old quantities were crates):
--   quantities / 12, unit prices x 12, line totals and invoice totals unchanged,
--   old adjustment rows get Crates = their old crate quantity.
--
-- Safe to run more than once: the column changes are idempotent and the data conversion only
-- runs while there is no 'peti_units' row in SchemaMigrations.

CREATE TABLE IF NOT EXISTS `SchemaMigrations` (
  `Name` varchar(100) NOT NULL,
  `AppliedAt` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`Name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `InvoiceItems` MODIFY `Quantity` decimal(12,4) DEFAULT NULL;
ALTER TABLE `InvoiceItems_Deleted` MODIFY `Quantity` decimal(12,4) DEFAULT NULL;
ALTER TABLE `PurchaseOrderItems`
  MODIFY `QuantityOrdered` decimal(12,4) NOT NULL DEFAULT 0.0000,
  MODIFY `QuantityReceived` decimal(12,4) DEFAULT 0.0000;
ALTER TABLE `ProductInventory`
  MODIFY `OpeningStock` decimal(12,4) NOT NULL DEFAULT 0.0000,
  MODIFY `CurrentStock` decimal(12,4) DEFAULT 0.0000,
  MODIFY `ReorderLevel` decimal(12,4) DEFAULT 0.0000,
  MODIFY `ReorderQuantity` decimal(12,4) DEFAULT 0.0000,
  MODIFY `UnitOfMeasure` varchar(50) DEFAULT 'Peti';
ALTER TABLE `StockMovements`
  MODIFY `Quantity` decimal(12,4) NOT NULL,
  ADD COLUMN IF NOT EXISTS `Crates` decimal(10,2) DEFAULT NULL COMMENT 'adjustment as entered, in crates (12 crates = 1 peti)' AFTER `Quantity`;

-- ---- one-time data conversion (crates -> peti) ----
SET @done = (SELECT COUNT(*) FROM `SchemaMigrations` WHERE `Name` = 'peti_units');

UPDATE `InvoiceItems` SET `Quantity` = `Quantity` / 12, `UnitPrice` = `UnitPrice` * 12 WHERE @done = 0;
UPDATE `InvoiceItems_Deleted` SET `Quantity` = `Quantity` / 12, `UnitPrice` = `UnitPrice` * 12 WHERE @done = 0;
UPDATE `PurchaseOrderItems` SET `QuantityOrdered` = `QuantityOrdered` / 12, `QuantityReceived` = `QuantityReceived` / 12, `UnitPrice` = `UnitPrice` * 12 WHERE @done = 0;
UPDATE `ProductInventory` SET `OpeningStock` = `OpeningStock` / 12, `CurrentStock` = `CurrentStock` / 12,
       `ReorderLevel` = `ReorderLevel` / 12, `ReorderQuantity` = `ReorderQuantity` / 12,
       `CostPrice` = `CostPrice` * 12, `AverageCost` = `AverageCost` * 12, `UnitOfMeasure` = 'Peti' WHERE @done = 0;
UPDATE `StockMovements` SET `Crates` = `Quantity`, `Quantity` = `Quantity` / 12, `CostPrice` = `CostPrice` * 12 WHERE @done = 0;
UPDATE `Products` SET `Price` = `Price` * 12 WHERE @done = 0;

INSERT IGNORE INTO `SchemaMigrations` (`Name`) VALUES ('peti_units');
