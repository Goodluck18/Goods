# Goodlucks Goods — Third Upgrade Deployment Guide

## Important project note

The supplied production project is a PHP/MySQL API with a compiled React storefront, not a conventional Laravel application. This upgrade extends the supplied PHP/MySQL architecture and uses the existing admin session, product catalogue, variants, order records, review system, and WhatsApp order flow. It does not require a framework migration or a frontend build step.

## Deploy safely

1. **Back up first.** Download a copy of the complete website files and export the current production database. Keep the backup outside the public web root.
2. **Verify schema before migrating.** The supplied `goodluck_test.sql` already contains the Third Upgrade tables and fields, including `pos_sales.receipt_token_encrypted`, product publication fields, tags, and `orders.delivery_fee_status`. Do not run `migrations-third-upgrade-expansion.sql` against a database that already has these columns: its ALTER statements are not idempotent. Back up first, inspect the target schema, and apply only missing migrations. Apply `api/migrations-product-seo-title-description.sql` to add the two optional SEO fields safely and `api/migrations-pos-transaction-date.sql` to add the nullable POS `transaction_at` field/index. Both are additive; the POS migration leaves every existing `created_at` audit timestamp untouched. The correction report documents the changed workflows and tests.
3. **Upload the modified files.** Preserve the existing `api/.env` and current uploaded product/review media. Upload the changed files and new files from this package, including `admin-tools.php`, `receipt.php`, `tag-search.php`, `service-worker.js`, the PWA icons, `assets/index-third-upgrade.js`, and the two Goodlucks enhancement scripts.
4. **Keep the existing routes and media folders.** Do not delete `api/uploads`, variant image folders, review image folders, or existing product/category data. The original build asset is retained; `index.html` now points to the targeted patched bundle.
5. **Confirm hosting settings.** `.user.ini` requests 8 MB per image, a 160 MB POST body, at least 20 uploaded files, and longer execution limits. Shared hosts can impose stricter server-side limits or ignore `.user.ini`; if 15-image uploads still fail, adjust the hosting control panel's PHP/web-server limits rather than reducing the 15-image cap.
6. **Keep backups outside the public document root.** The root `.htaccess` now denies direct requests for `.sql`, `.zip`, `.bak`, `.log`, `.ini`, and `.env`-named files. Move the SQL dump and nested backup archives out of the public web root after confirming your separate backup is safe.

## Where the new functionality lives

- **Admin entry:** a `POS & Product Tools` link in the existing admin console opens `/admin-tools.php`, protected by the current admin session.
- **POS:** create a sale using published catalogue items, manual items, or a mixture of both. Manual items are stored in `pos_sale_items` without requiring a product foreign key and do not create public catalogue items. The transaction date/time is selectable in the existing form; `created_at` remains the actual record/audit creation timestamp.
- **Sales/payments:** searchable sale history, partial/full payment records, payment reversals as additional history rows, total adjustments with reasons, and audit history.
- **Receipts:** public read-only token URLs at `/receipt/<token>`, branded HTML receipt that reloads the current saved payment state, browser printing, and a self-contained PDF download with clickable Check Current Receipt Status, website, Shop More, and per-item Review Product links. WhatsApp receipt messages use the customer's first name, payment status, and existing receipt URL. Payment status is recalculated from the payment ledger; receipt URLs stay the same when payments are recorded. Receipt links can be revoked, reinstated, or deliberately regenerated from the admin tools.
- **Order imports:** import existing WhatsApp orders into POS sales history without changing the original order or assuming that money has been received.
- **Product tags and SEO:** the `Product Tags & SEO` tab stores searchable tags and SEO keywords, preserves independent Popular/Featured flags, and edits storefront stock labels.
- **Drafts:** save incomplete products as drafts from the existing Add/Edit Product form. Drafts remain in Product Inventory, can be edited in place, and are published by changing the same product status; no separate Draft Products tab is required.
- **Subcategories:** manage subcategories within the existing Categories workflow. Edit name, slug, description, image, parent category, and meta description without deleting the subcategory. Old category/product paths are recorded for 301 redirects when slugs change.
- **Delivery locations:** unknown locations remain accepted, but no delivery fee is invented; the order total keeps the delivery charge at zero until the administrator confirms it. Known Greater Accra and outside-Accra locations retain the configured fee rules.
- **PWA:** manifest, app icons, service worker, installation prompt, and iOS manual-install guidance. The service worker deliberately does not cache API, admin, checkout, PHP, or receipt URLs.
- **Reviews/tags:** product tag links use crawlable `/tag/<slug>` pages. Valid review-request links are checked by the existing API, record the opening event, and open the existing product review modal; the request remains `Review Submitted` after moderation changes.
- **SEO:** search title and meta description are updated as specified. The established homepage Open Graph/Twitter title, description, and social image are retained; product/category metadata remains dynamic.

## Verification checklist after deployment

- Log in to the existing admin dashboard and open **POS & Product Tools**. Confirm it contains exactly **New Sale / POS**, **Sales & Payments**, and **Import WhatsApp Orders**. Product tags, SEO, and drafts are in Products / Product Inventory; subcategory edits are in Categories.
- Create one mixed sale containing an existing catalogue product and an unlisted manually typed item. Record both a current sale and a historical transaction date/time; verify the receipt date changes while `created_at` audit timestamps do not. Confirm neither duplicate public products nor altered catalogue prices are created.
- Generate a receipt; check customer details, logo, line totals, discount, delivery, total, balance, policy, print layout, and downloaded PDF. Share a receipt for a multi-word customer name, single-word name, and blank name; verify first-name/neutral greeting and payment status. Click Check Current Receipt Status, website, Shop More, and each item Review Product link in the PDF.
- Record partial and final payments; confirm outstanding balance and payment status recalculate. Retry the same payment details after a simulated network failure and confirm no duplicate ledger entry is created. Reverse a payment and verify that the original row remains while the reversal is logged. Adjust totals with a reason and inspect the audit history.
- Revoke a public receipt link and verify that it returns an unavailable response. Reinstate the original link; regenerate it and verify that the previous token is invalid.
- Import an existing WhatsApp order twice and confirm only one POS sale is created.
- Create a tag, save it on a product, click the tag from the product page, and verify that `/tag/<slug>` lists only published matching products.
- Save an incomplete draft, upload several images, edit it, preview it while signed in, and publish only after supplying required fields. Verify a draft URL is not publicly rendered or indexed.
- Change a subcategory parent/slug and test the old path redirects while the product/subcategory association remains intact.
- Test `In Stock`, `Limited Stock`, and `Out of Stock` on a product page and product cards; verify Featured and Popular work independently, while New Arrivals is ordered by newest publication/creation date and can include Featured or Popular products.
- Test a known Greater Accra location, a known outside-Accra city, and an unmatched custom location. Confirm unmatched locations are visibly flagged and do not receive a guessed fee.
- Check manifest/icon installation on Android/desktop and iOS instructions. Confirm admin and receipt pages are never cached.
- Enable the promo banner with text, update the text, disable it, and test empty text on mobile and desktop. Inspect raw homepage HTML for the required `<title>` and description while confirming the homepage social preview metadata is unchanged. Test product/category canonical metadata and receipt Review → product page → modal open → submitted review.

## Test status and limitations

The delivered source has been checked with PHP syntax linting for the changed PHP files, JavaScript syntax checks for the patched scripts, and a generated sample PDF has been structurally checked and rendered locally. The real production MySQL database, browser-based acceptance journeys, hosting upload caps, and live social-preview crawlers were not available for end-to-end verification in this workspace. Therefore the features should be treated as implemented in code but **not live-deployment certified** until the checklist above has been run against a backed-up staging copy and then production.

No production database was reset or modified while preparing this package. Run the migration and integration tests in the order above before declaring the upgrade complete.
