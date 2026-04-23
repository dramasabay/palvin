# PALVIN Premium

PHP + MySQL retail and consignment system for PALVIN.

## What is included in this package
- Retail inventory, POS orders, invoices, order history, and retail reports
- Consignment stock, Issue DO, Issue INV, payments, and consignment reports
- CSV, colored Excel, and print-to-PDF exports
- Cambodia timezone defaults for PHP and MySQL (`Asia/Phnom_Penh` / `+07:00`)
- Telegram alerts for Retail POS invoices and Issue INV sales
- Runtime schema/index updater for faster reporting on larger datasets

## Default admin
- Email: `admin@palvin.local`
- Password: `admin123`

## Quick setup
1. Extract the zip into your web root.
2. Create a MySQL database named `palvin_premium`.
3. Import `sql/palvin_premium.sql`.
4. Update database credentials in `config/db.php`, or use environment variables.
5. Open the project URL and log in.

## Live server / Webmin notes
You can either edit `config/db.php`, or set these environment variables for safer deployment:
- `PALVIN_DB_HOST`
- `PALVIN_DB_PORT`
- `PALVIN_DB_NAME`
- `PALVIN_DB_USER`
- `PALVIN_DB_PASS`
- `PALVIN_DB_CHARSET`
- `PALVIN_BASE_URL`
- `PALVIN_TIMEZONE`
- `PALVIN_DB_TIMEZONE`

Suggested live values:
- `PALVIN_BASE_URL=https://rc.palvinpavilion.com`
- `PALVIN_TIMEZONE=Asia/Phnom_Penh`
- `PALVIN_DB_TIMEZONE=+07:00`

## Telegram configuration
Go to **System > Settings** and fill in:
- Telegram Bot Token
- Telegram Chat ID
- optional Message Thread ID (only if you use Telegram forum topics)
- enable Telegram alerts
- choose whether to send Retail POS and/or Issue INV alerts

Then click **Save & Send Telegram Test**.

## Report defaults
- Retail Reports default to the latest 1-month range
- Consignment Reports default to the latest 1-month range
- Exports keep the full selected date range

## Database scalability improvements in this build
- Added indexes for report-heavy tables and date fields
- Removed report filters that wrapped indexed datetime columns in `DATE(...)`
- Improved document number generation to reduce collisions on busy/live systems
- Added runtime schema updater so existing databases can receive missing indexes/settings automatically

## Storage notes
- Branding uploads are stored in `uploads/branding`
- Product images are stored in `uploads/products`
- PDF export uses browser print layout
