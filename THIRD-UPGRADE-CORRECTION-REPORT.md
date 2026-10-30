# Goodlucks Goods — Third Upgrade Corrections

## Implemented in this package

- Reduced the separate POS & Product Tools navigation to exactly **New Sale / POS**, **Sales & Payments**, and **Import WhatsApp Orders**. The former standalone metadata, draft, and subcategory tabs are no longer navigable there; their existing backend routes are retained.
- Kept product tags and SEO keywords in the existing Add/Edit Product form and added editable SEO title and SEO description fields to that same form.
- Added database persistence and server-rendered public product metadata for SEO title/description, with a safe additive migration at `api/migrations-product-seo-title-description.sql`.
- Updated the existing Categories subcategory chips to include an Edit action that uses the existing subcategory update endpoint.
- Made incomplete drafts saveable without a category or name. Such drafts are stored as `Untitled draft` with a unique draft slug; published products still require a name, a positive price, and a main category. The existing product ID is updated when publishing, not duplicated.
- The `/store` bootstrap includes drafts only for an authenticated admin session, so the existing inventory workflow can list and reopen drafts. Public requests continue to receive published products only; checkout and other public product endpoints retain their published-only filters.
- Added receipt-storage preflight checks for the encrypted-token column, a strong `APP_SECRET`, and PHP OpenSSL before receipt-token writes. Missing configuration now returns a specific actionable 503 response instead of a generic server error. Token encryption remains AES-256-GCM and the public lookup remains hash-based.
- Updated deployment guidance to match the supplied `goodluck_test.sql`: it already contains the POS tables, encrypted receipt-token column, publication fields, tags, and delivery-fee status. The non-idempotent Third Upgrade expansion migration must not be re-run against this already-upgraded dump/database.

## Checks performed in this workspace

- PHP syntax lint: all PHP files passed.
- JavaScript syntax checks: patched storefront bundle, page enhancements, PWA script, service worker, and extracted `admin-tools.php` inline script passed `node --check`.
- Receipt-token crypto unit check: generated token -> AES-256-GCM encrypted storage -> decrypted token round-trip passed with a test-only secret.
- SQL dump static checks: required core/POS tables and existing `receipt_token_encrypted`, `product_status`, `seo_keywords`, and `delivery_fee_status` columns were found.
- Navigation regression check: exactly three POS tabs remain and the three standalone tool tab IDs are absent.
- Static checks confirmed the product SEO fields, subcategory edit action, admin-only draft listing logic, and receipt-storage preflight are present.

## Not verified here

- No MySQL/MariaDB server/client or PDO MySQL driver is installed in this workspace. The supplied SQL dump was inspected, but it was not restored into a staging database and no database migrations were executed.
- No connected staging credentials or application runtime were available, so the original Generate Link server exception could not be reproduced against a real sale row. The dump itself already contains `receipt_token_encrypted`; therefore a missing column is not the cause in that dump. The remaining likely deployment-specific causes include a missing/placeholder `APP_SECRET`, a mismatch between the dump and the actual hosting schema, or a server-side database/permission exception. The new preflight distinguishes missing token storage/configuration; inspect the hosting PHP error log if the failure persists after deployment.
- Full browser/API tests for creating and publishing products, payment status changes, link revoke/reinstate, and live customer receipt access remain staging acceptance tests. They are not reported as passed.

## Safe deployment steps

1. Back up the live files and database; preserve `api/.env` and uploads.
2. Compare the live database schema with the supplied dump. Do **not** re-import the dump into production and do **not** rerun `migrations-third-upgrade-expansion.sql` if its columns/tables already exist.
3. Apply `api/migrations-product-seo-title-description.sql` once to the target database. It checks for each column before adding it.
4. Ensure `APP_SECRET` in the existing `api/.env` is a strong, stable secret of at least 32 characters and PHP OpenSSL is enabled. Do not use the example placeholder or change the secret after encrypted receipt tokens have been stored.
5. Deploy the package to staging first. Test an incomplete draft, edit/save, publish the same product ID, verify public draft invisibility, edit a subcategory in Categories, and generate/open/revoke/regenerate a receipt link while checking the PHP error log.
6. Promote to production only after staging acceptance passes.
