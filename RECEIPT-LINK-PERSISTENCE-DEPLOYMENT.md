# Receipt-link persistence deployment note

This is a targeted update to the existing Goodlucks Goods app; it does not recreate sales or payment records.

## Required one-time database migration

Back up the database first. On the existing database, run `api/migrations-receipt-link-persistence.sql` once. It adds `pos_sales.receipt_token_encrypted`; it does not alter or delete existing sale/payment rows. Fresh installs using the updated `migrations-third-upgrade-expansion.sql` already include the column, so do not run the ALTER migration twice.

## Secret requirement

The API uses the existing `APP_SECRET` in `api/.env` to encrypt receipt bearer tokens at rest with AES-256-GCM. Keep `APP_SECRET` strong, private, and unchanged after deployment. Do not use the example placeholder. PHP OpenSSL support is required.

## Existing receipt links

The old database stores only token hashes, so the raw URL for an already-existing link cannot be recovered safely. For each older sale, use **Generate / Replace** once in Sales History. The newly generated link is encrypted and persisted, and thereafter appears beside that sale with **Copy Link**, **Share** (first name, newline, URL), and **Revoke** actions. A replaced token invalidates the previous link. Revoked links no longer resolve. These actions do not create a sale or payment.

## Verification notes

PHP syntax checks passed for `api/index.php`, `api/pos_helpers.php`, `admin-tools.php`, and `receipt.php`; the inline admin JavaScript passed `node --check`. The PDF policy wrap was shortened to fit the printable content width and continues onto another page as needed. The PDF logo asset exists in the package. A live database/browser/social-platform test was not possible in this packaging environment, so please test on staging after applying the migration. Social platforms can cache previews independently; the page now uses generic receipt title/description and a shared preview image, without customer data in its metadata.
