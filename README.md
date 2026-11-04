# POS

Point of sale and inventory management app (PHP + MySQL), live at https://pos.btkdeals.com.

## Setup

1. Copy `includes/db.example.php` to `includes/db.php` and fill in the database host, user, password and name. `includes/db.php` is git-ignored so credentials never reach this public repo.
2. The database schema is in `db.txt`.
3. Database changes are in `migrations/` (run them in date order on an existing database, e.g. `mysql -u USER -p DBNAME < migrations/2026-11-02_vendors_inventory.sql`). `db.txt` always holds the current full schema.

## Modules

- **POS** (`pos.php`, `pos_api.php`, `receipt.php`): cash or credit sales per **peti** (1 peti = 12 crates = 360 eggs; whole peti typed or clicked in the cart), customer search / new customer popup. Product tiles show stock in peti (plus loose crates) (display only, sales are never blocked).
- **Customers** (`customers.php`, `customer_ledger.php`): customer balances = opening + credit sales − payments received.
- **Vendors** (`vendors.php`): add / edit / search / deactivate vendors, current payable per vendor, Pay popup.
- **Purchases / stock in** (`purchases.php`): purchase entries from a vendor with product lines (peti × cost per peti), Cash (paid in full) or Credit with an optional amount paid now. Admins can delete a wrong entry (its stock and at-purchase payment are removed too); to change one, delete it and enter it again.
- **Inventory** (`inventory.php`): stock per product in peti (with the loose crates, e.g. `9 peti 7 crates`), low-stock view, stock adjustments **entered in crates** (damage / breakage, expired, return to vendor, count correction) with a reason, and **Set count** to enter a physical count (peti + crates) and the low-stock level (peti).
- **Vendor ledger** (`vendor_ledger.php`): per vendor, date range, printable running payable.
- **Vendor payouts** (`vendor_payouts.php`): record and list payments to vendors (Cash, Bank Transfer, Cheque, Easypaisa, JazzCash, Other) with reference.
- **Cash summary** (`cash_summary.php`, admin): cash sales + customer receipts − vendor payouts − cash purchases − expenses for a date range, all methods and cash only.

### How balances and stock are calculated

- Vendor payable = vendor opening balance + credit purchases − all payments to the vendor (including the amount paid at a credit purchase). Cash purchases do not touch the ledger.
- Units: the business trades in **peti** (1 peti = 12 crates = 360 eggs). All prices are per peti and all trading quantities (sales, purchases, counts, low-stock levels, reports) are stored in peti as `DECIMAL(12,4)`. Breakage / stock adjustments are entered in crates: the crates typed are kept in `StockMovements.Crates` and the stock effect is `Crates / 12` peti in `StockMovements.Quantity` (migration `2026-11-04_peti_units.sql` converted the earlier crate data: quantities ÷ 12, unit prices × 12, totals unchanged).
- Peti in stock = counted stock (at the product's count time) + purchases − peti sold on invoices ± adjustments, counting only entries after the count time. Stock is computed from the invoices / purchases / adjustments themselves, so deleting an invoice in Reports puts its peti back. The 2026-11-02 migration starts every product at 0 from the moment it is run; enter the real count on Inventory → Set count.

## Deployment (GitHub Actions)

Every push to `main` (or a manual run from the Actions tab, "Deploy to pos.btkdeals.com" → Run workflow) runs `.github/workflows/deploy.yml`:

1. `php -l` on every PHP file; any syntax error stops the deploy.
2. Connects over SSH (port 65002) with a dedicated deploy key and backs up the live folder to `~/pos_backups/deploy_<timestamp>.tar.gz` on the server (last 10 kept).
3. `rsync` uploads the code to `domains/btkdeals.com/public_html/pos` **without deleting** anything on the server. Never uploaded / overwritten: `includes/db.php`, `error_log`, `*.log`, `restaurantInfo.txt` (business name / phone / address, edited from Settings), `README.md`, `db.txt`, `migrations/`, `.git*`.
4. `php -l` on the server and a smoke check that https://pos.btkdeals.com/login.php returns 200.

Repository secrets used: `SSH_PRIVATE_KEY`, `SSH_KNOWN_HOSTS`, `SSH_HOST`, `SSH_PORT`, `SSH_USER`, `DEPLOY_PATH`. The deploy key's public half is in `~/.ssh/authorized_keys` on the server (comment `pos-github-actions-deploy`); remove that line to revoke it.

**Database migrations are never run automatically.** When a commit adds a file in `migrations/`, back up the database and run it on the server by hand *before* pushing the code that needs it (or push with the workflow disabled, migrate, then re-run the workflow).

## Logo / credits

The app logo (`assets/logo.svg`, used in the navbar, login page and as favicon; `assets/logo-mono.svg` on printed receipts) is the "building-warehouse" icon from [Tabler Icons](https://tabler.io/icons) v3.50.0, MIT License, Copyright (c) 2020-2024 Paweł Kuna. The business name shown in the app comes from the first line of `restaurantInfo.txt` (Settings page) and defaults to "Inventory & POS".
