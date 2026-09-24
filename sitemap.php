<?php
/**
 * ShopInnKart - XML sitemaps.
 *
 * One route, several files:
 *
 *     /sitemap.xml                  the index (this file, no parameters)
 *     /sitemap-products-1.xml       a section file, via the .htaccess rewrite
 *     /sitemap.php?type=products&page=1   the same file where there is no
 *                                         rewrite engine (the built-in server)
 *
 * All of the building lives in includes/sitemap-functions.php so that the
 * admin screen and the CLI job can produce and check exactly what a crawler
 * gets, without going over HTTP to do it.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_once INCLUDES_PATH . '/content-functions.php';
require_once INCLUDES_PATH . '/sitemap-functions.php';

// Both parameters are read as scalars before anything else touches them.
// `?type[]=products` hands PHP an array, and casting one to string is a
// diagnostic and an HTTP 500 - on a URL a crawler can request.
$rawType = $_GET['type'] ?? 'index';
$rawPage = $_GET['page'] ?? 1;

$type = is_scalar($rawType) ? strtolower(trim((string) $rawType)) : '';
$page = is_scalar($rawPage) ? (int) $rawPage : 1;
if ($page < 1) {
    $page = 1;
}

// The type has to be one we know before anything else happens: it is used to
// pick a builder and to build a cache key, and neither should ever see an
// arbitrary string from the query.
$known = array_merge(['index'], array_keys(sitemap_sections()));
$xml = in_array($type, $known, true) ? sitemap_cached($type, $page) : null;

// Nothing buffered may reach a crawler: one stray byte of HTML in front of the
// declaration makes the whole file unparseable.
while (ob_get_level() > 0) {
    ob_end_clean();
}

if ($xml === null) {
    // A section that is switched off, empty, or asked for past its last page
    // is genuinely not there. 404 says so; an empty <urlset> would be reported
    // by Search Console as a sitemap that lists nothing, which is a different
    // and misleading complaint.
    http_response_code(404);
    if (!headers_sent()) {
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Robots-Tag: noindex');
    }
    echo "Not found\n";
    exit;
}

if (!headers_sent()) {
    header('Content-Type: application/xml; charset=utf-8');
    // Crawlers re-fetch sitemaps often. An hour at the edge is long enough to
    // spare the database and short enough that a new product is found today.
    header('Cache-Control: public, max-age=3600');
    header('X-Robots-Tag: noindex');   // the file is for crawlers, not results
}

echo $xml;
