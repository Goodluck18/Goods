<?php
declare(strict_types=1);

// Server-rendered metadata shell for the SPA. The React UI remains unchanged;
// this file only prepares the initial HTML head for crawlers and social platforms.
$apiDir = is_dir(__DIR__.'/api') ? __DIR__.'/api' : __DIR__.'/backend';
require_once $apiDir.'/helpers.php';

$baseUrl = rtrim((string)envv('APP_URL', 'https://shop.goodlucks.co'), '/');
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = '/' . ltrim($path, '/');

function seo_esc(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function seo_absolute_url(string $url, string $baseUrl): string {
    $url = trim($url);
    if ($url === '') return $baseUrl . '/goodlucks-social-preview.png';
    if (preg_match('#^https?://#i', $url)) return $url;
    return $baseUrl . '/' . ltrim($url, '/');
}

function seo_media_url(?string $path, string $baseUrl): string {
    $path = trim((string)$path);
    if ($path === '') return $baseUrl . '/goodlucks-social-preview.png';
    if (preg_match('#^https?://#i', $path)) return $path;
    while (str_starts_with($path, '/api/api/')) $path = substr($path, 4);
    while (str_starts_with($path, 'api/api/')) $path = substr($path, 4);
    if (str_starts_with($path, '/api/')) return $baseUrl . $path;
    if (str_starts_with($path, 'api/')) return $baseUrl . '/' . $path;
    return seo_absolute_url(media_public_url($path), $baseUrl);
}

function seo_excerpt(string $text, int $max = 180): string {
    $text = trim(preg_replace('/\s+/u', ' ', strip_tags($text)) ?? '');
    if ($text === '') return '';
    $length = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
    if ($length <= $max) return $text;
    $cut = function_exists('mb_substr') ? mb_substr($text, 0, max(1, $max - 3)) : substr($text, 0, max(1, $max - 3));
    return rtrim($cut) . '...';
}

function seo_image_source_is_valid(string $path): bool {
    $path = trim($path);
    if ($path === '') return false;
    if (preg_match('#^https?://#i', $path)) return (bool)filter_var($path, FILTER_VALIDATE_URL);
    $clean = ltrim($path, '/');
    while (str_starts_with($clean, 'api/api/')) $clean = substr($clean, 4);
    $candidates = [];
    if (str_starts_with($clean, 'api/')) $candidates[] = __DIR__ . '/backend/' . substr($clean, 4);
    if (str_starts_with($clean, 'uploads/')) $candidates[] = __DIR__ . '/backend/' . $clean;
    $candidates[] = __DIR__ . '/' . $clean;
    foreach (array_unique($candidates) as $candidate) if (is_file($candidate) && is_readable($candidate)) return true;
    // During production, the database may contain a public URL whose file lives
    // outside the source package. Keep syntactically valid absolute paths usable.
    return false;
}

function seo_product(string $slug): ?array {
    $pdo = db();
    $st = $pdo->prepare("SELECT p.*, c.name AS category_name, c.slug AS category_slug, sc.name AS subcategory_name, sc.slug AS subcategory_slug FROM products p JOIN categories c ON c.id=p.category_id LEFT JOIN categories sc ON sc.id=p.subcategory_id WHERE p.slug=? AND p.product_status='published' LIMIT 1");
    $st->execute([$slug]);
    $product = $st->fetch();
    if (!$product) return null;

    // The dedicated featured_image column is the storefront's primary image.
    // Prefer it for social previews so gallery ordering or variant images can
    // never replace the product's default share image.
    $featuredImage = trim((string)($product['featured_image'] ?? ''));
    if ($featuredImage !== '' && seo_image_source_is_valid($featuredImage)) {
        $product['_social_image'] = $featuredImage;
        return $product;
    }

    $images = [];
    try {
        $q = $pdo->prepare('SELECT image_path FROM product_images WHERE product_id=? ORDER BY display_order ASC, id ASC');
        $q->execute([(int)$product['id']]);
        foreach ($q->fetchAll() as $row) {
            $image = trim((string)($row['image_path'] ?? ''));
            if ($image !== '' && seo_image_source_is_valid($image)) $images[] = $image;
        }
    } catch (Throwable $e) {}
    if (!$images) {
        $legacy = json_decode((string)($product['images'] ?? '[]'), true);
        if (is_array($legacy)) foreach ($legacy as $image) {
            $image = trim((string)$image);
            if ($image !== '' && seo_image_source_is_valid($image)) $images[] = $image;
        }
    }
    if (!$images && !empty($product['featured_image']) && seo_image_source_is_valid((string)$product['featured_image'])) $images[] = (string)$product['featured_image'];
    $product['_social_image'] = $images[0] ?? '';
    return $product;
}

function seo_review_request(string $token): ?array {
    $token = trim($token);
    if (!preg_match('/^[a-f0-9]{64}$/i', $token)) return null;
    try {
        $st = db()->prepare(
            "SELECT rr.*,
                    p.id AS product_id, p.name AS product_name, p.slug AS product_slug,
                    p.description AS product_description, p.featured_image, p.images
             FROM review_requests rr
             JOIN products p ON p.id = rr.product_id
             WHERE rr.secure_token=?
             LIMIT 1"
        );
        $st->execute([$token]);
        $row = $st->fetch();
        if (!$row) return null;
        if (in_array((string)$row['status'], ['submitted','approved','rejected','expired'], true)) return null;
        $expires = trim((string)($row['expires_at'] ?? ''));
        if ($expires !== '' && strtotime($expires) !== false && strtotime($expires) < time()) return null;
        if ($expires === '') {
            $created = trim((string)($row['created_at'] ?? ''));
            if ($created !== '' && strtotime($created . ' +30 days') < time()) return null;
        }
        return $row;
    } catch (Throwable $e) {
        // Metadata generation must never break the public storefront because the
        // optional review-request table is unavailable during deployment.
        error_log('Goodlucks SEO review-request lookup failed: '.$e->getMessage());
        return null;
    }
}

function seo_review_request_image(array $row, string $baseUrl): string {
    $featured = trim((string)($row['featured_image'] ?? ''));
    if ($featured !== '' && seo_image_source_is_valid($featured)) return seo_media_url($featured, $baseUrl);
    $legacy = json_decode((string)($row['images'] ?? '[]'), true);
    if (is_array($legacy)) {
        foreach ($legacy as $image) {
            $image = trim((string)$image);
            if ($image !== '' && seo_image_source_is_valid($image)) return seo_media_url($image, $baseUrl);
        }
    }
    try {
        $q = db()->prepare('SELECT image_path FROM product_images WHERE product_id=? ORDER BY display_order ASC, id ASC LIMIT 1');
        $q->execute([(int)$row['product_id']]);
        $image = trim((string)($q->fetchColumn() ?: ''));
        if ($image !== '' && seo_image_source_is_valid($image)) return seo_media_url($image, $baseUrl);
    } catch (Throwable $e) {}
    return $baseUrl . '/goodlucks-social-preview.png';
}

function seo_category(string $slug): ?array {
    $st = db()->prepare('SELECT * FROM categories WHERE slug=? LIMIT 1');
    $st->execute([$slug]);
    $row = $st->fetch();
    return $row ?: null;
}

function seo_subcategory(string $parentSlug, string $subcategorySlug): ?array {
    $st = db()->prepare('SELECT sc.*, p.name AS parent_name, p.slug AS parent_slug, p.image AS parent_image, p.description AS parent_description FROM categories sc JOIN categories p ON p.id=sc.parent_id WHERE p.slug=? AND sc.slug=? LIMIT 1');
    $st->execute([$parentSlug, $subcategorySlug]);
    return $st->fetch() ?: null;
}

function seo_first_subcategory_product_image(int $subcategoryId, string $baseUrl): string {
    try {
        $st = db()->prepare("SELECT p.id, p.featured_image, p.images FROM products p WHERE p.subcategory_id=? AND p.product_status='published' ORDER BY p.is_featured DESC, p.created_at DESC, p.id DESC LIMIT 10");
        $st->execute([$subcategoryId]);
        foreach ($st->fetchAll() as $row) {
            try {
                $q = db()->prepare('SELECT image_path FROM product_images WHERE product_id=? ORDER BY display_order ASC, id ASC LIMIT 1');
                $q->execute([(int)$row['id']]);
                $image = trim((string)($q->fetchColumn() ?: ''));
                if ($image !== '' && seo_image_source_is_valid($image)) return seo_media_url($image, $baseUrl);
            } catch (Throwable $e) {}
            if (!empty($row['featured_image']) && seo_image_source_is_valid((string)$row['featured_image'])) return seo_media_url((string)$row['featured_image'], $baseUrl);
            $legacy = json_decode((string)($row['images'] ?? '[]'), true);
            if (is_array($legacy) && !empty($legacy[0]) && seo_image_source_is_valid((string)$legacy[0])) return seo_media_url((string)$legacy[0], $baseUrl);
        }
    } catch (Throwable $e) {}
    return '';
}

$title = 'Goodlucks Goods | Furniture & Home Products in Ghana';
$description = 'Shop quality furniture and home products at Goodlucks Goods in Ghana. Explore beds, wardrobes, sofas and more, with convenient ordering and delivery.';
$image = $baseUrl . '/goodlucks-social-preview.png';
$canonical = $baseUrl . ($path === '/' ? '/' : $path);
$ogUrl = $canonical;
$type = 'website';
$robots = 'index,follow';
$keywords = '';

try {
    try {
        $redirectLookup = db()->prepare('SELECT target_path FROM seo_url_redirects WHERE old_path=? LIMIT 1');
        $redirectLookup->execute([$path]);
        $redirectTarget = trim((string)($redirectLookup->fetchColumn() ?: ''));
        if ($redirectTarget !== '' && str_starts_with($redirectTarget, '/') && !str_starts_with($redirectTarget, '//')) {
            header('Location: ' . $baseUrl . $redirectTarget, true, 301);
            exit;
        }
    } catch (Throwable $redirectError) {
        // Existing canonical pages remain available during deployment before the redirect table is migrated.
    }
    if (preg_match('#^/product/([^/]+)$#', $path, $m)) {
        $product = seo_product(urldecode($m[1]));
        if (!$product) { http_response_code(404); }
        else {
            $name = trim((string)$product['name']);
            $title = trim((string)($product['seo_title'] ?? '')) ?: ($name . ' | Goodlucks Goods');
            $description = trim((string)($product['seo_description'] ?? '')) ?: (seo_excerpt((string)($product['description'] ?? ''), 180) ?: $description);
            $keywords = trim((string)($product['seo_keywords'] ?? ''));
            $image = seo_media_url((string)($product['_social_image'] ?? ''), $baseUrl);
            $type = 'product';
            $canonical = $baseUrl . '/product/' . rawurlencode((string)$product['slug']);

            // Review-request links intentionally keep the existing product URL +
            // query-string format used by the storefront. The token changes only
            // the server-rendered social metadata; it does not alter tracking.
            $reviewToken = trim((string)($_GET['review_request'] ?? ''));
            if ($reviewToken !== '') {
                $request = seo_review_request($reviewToken);
                if ($request && (int)$request['product_id'] === (int)$product['id']) {
                    $requestProductName = trim((string)$request['product_name']);
                    $title = 'Write a Review for ' . $requestProductName . ' | Goodlucks Goods';
                    $description = 'Share your experience with ' . $requestProductName . ' and help other customers shop with confidence.';
                    $image = seo_review_request_image($request, $baseUrl);
                    $type = 'website';
                    $ogUrl = $baseUrl . '/product/' . rawurlencode((string)$product['slug']) . '?review_request=' . rawurlencode($reviewToken);
                }
            }
        }
    } elseif (preg_match('#^/(?:shop|category)/([^/]+)(?:/([^/]+))?$#', $path, $m)) {
        $parentSlug = urldecode($m[1]);
        $subSlug = isset($m[2]) && $m[2] !== '' ? urldecode($m[2]) : null;
        if ($subSlug !== null) {
            $sub = seo_subcategory($parentSlug, $subSlug);
            if (!$sub) { http_response_code(404); }
            else {
                $name = trim((string)$sub['name']);
                $title = $name . ' | Goodlucks Goods';
                $description = seo_excerpt((string)($sub['description'] ?? ''), 180) ?: 'Browse ' . $name . ' at Goodlucks Goods.';
                $subImage = seo_first_subcategory_product_image((int)$sub['id'], $baseUrl);
                $parentImage = trim((string)($sub['parent_image'] ?? ''));
                $image = $subImage ?: ($parentImage !== '' && seo_image_source_is_valid($parentImage) ? seo_media_url($parentImage, $baseUrl) : $baseUrl . '/goodlucks-social-preview.png');
                $canonical = $baseUrl . '/shop/' . rawurlencode($parentSlug) . '/' . rawurlencode((string)$sub['slug']);
            }
        } else {
            $category = seo_category($parentSlug);
            if (!$category) { http_response_code(404); }
            else {
                $name = trim((string)$category['name']);
                $title = $name . ' | Goodlucks Goods';
                $description = seo_excerpt((string)($category['description'] ?? ''), 180)
                    ?: seo_excerpt((string)($category['meta_description'] ?? ''), 180)
                    ?: $description;
                $categoryImage = trim((string)($category['image'] ?? ''));
                $image = $categoryImage !== '' && seo_image_source_is_valid($categoryImage) ? seo_media_url($categoryImage, $baseUrl) : $baseUrl . '/goodlucks-social-preview.png';
                $canonical = $baseUrl . '/shop/' . rawurlencode((string)$category['slug']);
            }
        }
    } elseif ($path === '/categories') {
        $title = 'Furniture Categories | Goodlucks Goods';
        $description = 'Browse furniture categories from Goodlucks Goods and find pieces for your home in Ghana.';
    } elseif ($path === '/reviews') {
        $title = 'Customer Reviews | Goodlucks Goods';
        $description = 'Read customer reviews and experiences from Goodlucks Goods shoppers.';
    } elseif ($path === '/search') {
        $title = 'Search Furniture | Goodlucks Goods';
        $description = 'Search the Goodlucks Goods furniture collection.';
    } elseif ($path === '/about') {
        $title = 'About Goodlucks Goods | Goodlucks Goods';
        $description = 'Learn more about Goodlucks Goods and our furniture collection in Ghana.';
    } elseif ($path === '/contact') {
        $title = 'Contact Goodlucks Goods | Goodlucks Goods';
        $description = 'Contact Goodlucks Goods for furniture enquiries, orders and support in Ghana.';
    } elseif ($path === '/faq' || $path === '/faqs') {
        $title = 'Frequently Asked Questions | Goodlucks Goods';
        $description = 'Find answers to common questions about Goodlucks Goods products, orders and delivery.';
    } elseif ($path === '/collections') {
        $title = 'Furniture Collections | Goodlucks Goods';
        $description = 'Explore furniture collections from Goodlucks Goods.';
    } elseif ($path === '/admin' || $path === '/admin-login') {
        $robots = 'noindex,nofollow';
    }
} catch (Throwable $e) {
    // Keep the public shell available even if the database is temporarily unavailable.
    error_log('Goodlucks SEO shell: ' . $e->getMessage());
}

// Keep the established homepage Open Graph/Twitter preview unchanged while using the new SEO title/description for search metadata.
$ogTitle = $path === '/' ? 'Goodlucks Goods | Furniture in Ghana' : $title;
$ogDescription = $path === '/' ? 'Discover furniture and home pieces from Goodlucks Goods in Ghana, with products available to shop online.' : $description;

$index = file_get_contents(__DIR__ . '/index.html');
if ($index === false) { http_response_code(500); exit('Application shell unavailable'); }

// Remove static/default SEO tags before injecting one authoritative server-rendered set.
$patterns = [
    '#\s*<title>.*?</title>#is',
    '#\s*<meta name="description"[^>]*>#i',
    '#\s*<meta name="keywords"[^>]*>#i',
    '#\s*<meta name="robots"[^>]*>#i',
    '#\s*<link rel="canonical"[^>]*>#i',
    '#\s*<meta property="og:[^"]+"[^>]*>#i',
    '#\s*<meta name="twitter:[^"]+"[^>]*>#i',
];
foreach ($patterns as $pattern) $index = preg_replace($pattern, '', $index) ?? $index;

$tags = "\n"
    . '    <title>' . seo_esc($title) . "</title>\n"
    . '    <meta name="description" content="' . seo_esc($description) . '" />' . "\n"
    . ($keywords !== '' ? '    <meta name="keywords" content="' . seo_esc($keywords) . '" />' . "\n" : '')
    . '    <meta name="robots" content="' . seo_esc($robots) . '" />' . "\n"
    . '    <link rel="canonical" href="' . seo_esc($canonical) . '" />' . "\n"
    . '    <meta property="og:site_name" content="Goodlucks Goods" />' . "\n"
    . '    <meta property="og:title" content="' . seo_esc($ogTitle) . '" />' . "\n"
    . '    <meta property="og:description" content="' . seo_esc($ogDescription) . '" />' . "\n"
    . '    <meta property="og:image" content="' . seo_esc($image) . '" />' . "\n"
    . '    <meta property="og:image:secure_url" content="' . seo_esc($image) . '" />' . "\n"
    . '    <meta property="og:image:width" content="1200" />' . "\n"
    . '    <meta property="og:image:height" content="630" />' . "\n"
    . '    <meta property="og:image:alt" content="' . seo_esc($title) . '" />' . "\n"
    . '    <meta property="og:url" content="' . seo_esc($ogUrl) . '" />' . "\n"
    . '    <meta property="og:type" content="' . seo_esc($type) . '" />' . "\n"
    . '    <meta property="og:locale" content="en_GH" />' . "\n"
    . '    <meta name="twitter:card" content="summary_large_image" />' . "\n"
    . '    <meta name="twitter:title" content="' . seo_esc($ogTitle) . '" />' . "\n"
    . '    <meta name="twitter:description" content="' . seo_esc($ogDescription) . '" />' . "\n"
    . '    <meta name="twitter:image" content="' . seo_esc($image) . '" />' . "\n"
    . '    <meta name="twitter:url" content="' . seo_esc($ogUrl) . '" />';

$index = preg_replace('#<head([^>]*)>#i', '<head$1>' . $tags, $index, 1);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
echo $index;
