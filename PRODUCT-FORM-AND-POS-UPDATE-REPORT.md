GOODLUCKS GOODS — PRODUCT FORM AND POS RESPONSIVE UPDATE

Changes in this package
- Widened the Quantity and Unit Price controls in the POS Add a manual item form. At small mobile widths, the form fields stack to full width and use touch-friendly input heights/font sizes.
- Added SEO Keywords, Product Tags, and Publication Status controls to the main Admin Dashboard Add Product/Edit Product form.
- The main product form can now save a product as a private draft or publish it. Tags and SEO keywords are saved with the product.
- Extended the existing product create/update API to persist publication status, SEO keywords, and normalized tags while preserving existing product images, variants, stock fields, and other product data.
- Kept the existing Draft Products manager available. The current compiled admin dashboard does not yet include the full draft listing/editing/publishing UI, so removing the manager now would risk making saved drafts harder to retrieve and manage. Use it to revisit, preview, and publish drafts. This is intentionally retained rather than deleting a needed workflow.
- No live database was accessed or changed during this source-code update. Existing schema migrations in the project already define product_status, seo_keywords, published_at, and tag tables; make sure the site's Third Upgrade migration has already been applied.

Validation performed
- PHP syntax check passed for api/index.php and admin-tools.php.
- JavaScript syntax check passed for assets/index-third-upgrade.js.
- No live website/browser/database end-to-end test was available in this workspace. Test the changes on a staging copy before deploying to production.
