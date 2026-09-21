<?php
/**
 * ShopInnKart - CMS page by slug (/page/<slug>).
 *
 * Anything in the `pages` table is reachable here, including the ten policy
 * documents. Policies are rows, not files: there is no privacy-policy.php
 * alongside this one for the body copy, only fixed routes that call the same
 * renderer. Slugs that also own a fixed route (about, terms, ...) still render
 * here and declare that route as their canonical URL, so the two never compete
 * in search results.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_once INCLUDES_PATH . '/content-functions.php';

// Slugs are stored lower-case. Folding the request means /page/Privacy-Policy
// resolves instead of 404ing on a capital letter someone pasted from an email.
$slug = strtolower(trim((string) input('slug', '')));

if ($slug === '' || preg_match('/^[a-z0-9\-_]+$/', $slug) !== 1) {
    require __DIR__ . '/404.php';
    exit;
}

cms_page_render($slug, ['missing' => '404']);
