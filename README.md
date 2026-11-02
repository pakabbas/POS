# POS

Point of sale and inventory management app (PHP + MySQL), live at https://pos.btkdeals.com.

## Setup

1. Copy `includes/db.example.php` to `includes/db.php` and fill in the database host, user, password and name. `includes/db.php` is git-ignored so credentials never reach this public repo.
2. The database schema is in `db.txt`.
3. Database changes are in `migrations/` (run them in date order on an existing database, e.g. `mysql -u USER -p DBNAME < migrations/2026-11-02_vendors_inventory.sql`). `db.txt` always holds the current full schema.

## Modules

- **POS** (`pos.php`, `pos_api.php`, `receipt.php`): cash or credit sales per crate, customer search / new customer popup. Product tiles show crates in stock (display only, sales are never blocked).
- **Customers** (`customers.php`, `customer_ledger.php`): customer balances = opening + credit sales − payments received.
- **Vendors** (`vendors.php`): add / edit / search / deactivate vendors, current payable per vendor, Pay popup.
- **Purchases / stock in** (`purchases.php`): purchase entries from a vendor with product lines (crates × cost per crate), Cash (paid in full) or Credit with an optional amount paid now. Admins can delete a wrong entry (its stock and at-purchase payment are removed too); to change one, delete it and enter it again.
- **Inventory** (`inventory.php`): crates in stock per product, low-stock view, stock adjustments (damage / breakage, expired, return to vendor, count correction) with a reason, and **Set count** to enter a physical count and low-stock level.
- **Vendor ledger** (`vendor_ledger.php`): per vendor, date range, printable running payable.
- **Vendor payouts** (`vendor_payouts.php`): record and list payments to vendors (Cash, Bank Transfer, Cheque, Easypaisa, JazzCash, Other) with reference.
- **Cash summary** (`cash_summary.php`, admin): cash sales + customer receipts − vendor payouts − cash purchases − expenses for a date range, all methods and cash only.

### How balances and stock are calculated

- Vendor payable = vendor opening balance + credit purchases − all payments to the vendor (including the amount paid at a credit purchase). Cash purchases do not touch the ledger.
- Crates in stock = counted stock (at the product's count time) + purchases − crates sold on invoices ± adjustments, counting only entries after the count time. Stock is computed from the invoices / purchases / adjustments themselves, so deleting an invoice in Reports puts its crates back. The 2026-11-02 migration starts every product at 0 crates from the moment it is run; enter the real count on Inventory → Set count.
