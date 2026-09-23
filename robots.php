<?php
/**
 * ShopInnKart - robots.txt
 *
 * Served at /robots.txt through a rewrite rather than as a static file, for two
 * reasons the static version got wrong:
 *
 *   1. The Sitemap line has to name the host it is actually served from. The
 *      static file hard-coded https://shopinnkart.com/sitemap.xml, so on a
 *      staging domain, a sub-folder install or plain localhost it pointed
 *      crawlers at the wrong site.
 *   2. An operator needs to be able to add rules without editing a file on
 *      disk, so Admin > Settings > SEO can append its own block.
 *
 * The disallow list itself is deliberately code, not settings: these paths are
 * a property of how the application is routed, and an admin who could edit them
 * could silently de-index the whole catalogue.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';

header('Content-Type: text/plain; charset=utf-8');
// Crawlers re-read this often; a day is long enough to spare the database and
// short enough that a settings change is picked up the same day.
header('Cache-Control: public, max-age=86400');

$base = rtrim(SITE_URL, '/');

$lines = [
    '# ShopInnKart - crawler rules',
    '# The catalogue is open. Anything private, transactional or server-side is not.',
    '',
    'User-agent: *',
    '',
    '# Server-side and private folders',
];

// With a hidden admin login address, naming /admin/ here would tell every
// visitor where the panel lives; it answers 404 to crawlers anyway.
$private = ['/admin/', '/api/', '/config/', '/includes/', '/storage/', '/uploads/', '/database/'];
if (admin_gate_enabled()) {
    $private = array_values(array_diff($private, ['/admin/']));
}
foreach ($private as $path) {
    $lines[] = 'Disallow: ' . $path;
}

$lines[] = '';
$lines[] = '# Session-specific pages - nothing here is useful in search results';
foreach ([
    'cart', 'checkout', 'account', 'login', 'register', 'logout',
    'wishlist', 'compare', 'orders', 'order-details', 'purchase-history',
    'notifications', 'preferences', 'addresses', 'change-password',
    'forgot-password', 'reset-password', 'track-order',
] as $route) {
    // Both spellings, because the clean URL and the .php file both resolve.
    $lines[] = 'Disallow: /' . $route;
    $lines[] = 'Disallow: /' . $route . '.php';
}

$lines[] = '';
$lines[] = '# Faceted shop URLs multiply into near-duplicates; the plain listings are enough';
foreach (['sort=', 'brand_ids=', 'attribute_values=', 'min_price=', 'max_price=', 'rating='] as $param) {
    $lines[] = 'Disallow: /*?*' . $param;
}

$lines[] = '';
$lines[] = '# Catalogue';
foreach (['/shop', '/product/', '/category/', '/brand/', '/blog', '/page/'] as $path) {
    $lines[] = 'Allow: ' . $path;
}

// Operator-supplied rules. Stripped of anything that is not a plain directive
// line so a paste cannot inject headers or break the format.
$extra = trim((string) setting('robots_txt_extra', ''));
if ($extra !== '') {
    $clean = [];
    foreach (preg_split('/\r\n|\r|\n/', $extra) as $line) {
        $line = trim($line);
        if ($line === '' || strlen($line) > 200) {
            continue;
        }
        if (preg_match('/^(#|User-agent:|Disallow:|Allow:|Crawl-delay:|Sitemap:|Host:)/i', $line) === 1) {
            $clean[] = $line;
        }
    }
    if ($clean !== []) {
        $lines[] = '';
        $lines[] = '# Added from Admin > Settings > SEO';
        array_push($lines, ...$clean);
    }
}

$lines[] = '';
$lines[] = 'Sitemap: ' . $base . '/sitemap.xml';

echo implode("\n", $lines) . "\n";
