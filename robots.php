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
 * The rules themselves now live in includes/sitemap-functions.php, as
 * structure. That is what lets Admin > SEO > Sitemap answer "is this URL
 * allowed?" with the same rules this file prints, instead of fetching its own
 * robots.txt and parsing the text back.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_once INCLUDES_PATH . '/sitemap-functions.php';

while (ob_get_level() > 0) {
    ob_end_clean();
}

if (!headers_sent()) {
    header('Content-Type: text/plain; charset=utf-8');
    // Crawlers re-read this often; a day is long enough to spare the database
    // and short enough that a settings change is picked up the same day.
    header('Cache-Control: public, max-age=86400');
}

echo robots_txt_body();
