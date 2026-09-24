<?php
/**
 * ShopInnKart Admin - SEO workbench shell.
 *
 * Four screens share one tab strip. They live here rather than under Settings
 * because none of them is a setting: they are work queues. A broken link, an
 * image with no alt text and a snippet somebody injected are all things an
 * operator DOES something about, one record at a time, and putting them behind
 * the settings tab strip buried them under nine forms.
 *
 * Settings > SEO (the store-wide defaults), SEO Health (the report) and
 * Redirects (the rules) stay where they are; this section links to them.
 */

declare(strict_types=1);

// Include-only: the parent page already ran authentication and permissions.
// The .htaccess rule refuses /_*.php outright; this is the backstop for a host
// that does not read .htaccess at all.
if (!defined('SIK_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

/** The tab strip, in the order an operator works through it. */
const SEO_SCREENS = [
    'index'   => ['label' => 'Overview',      'file' => 'index.php'],
    '404s'    => ['label' => 'Broken links',  'file' => '404s.php'],
    'images'  => ['label' => 'Image alt text', 'file' => 'images.php'],
    'scripts' => ['label' => 'Injected code', 'file' => 'scripts.php'],
];

function seo_admin_url(string $screen = 'index'): string
{
    $file = SEO_SCREENS[$screen]['file'] ?? 'index.php';
    return admin_url('seo/' . $file);
}

/** The tab strip, plus the links out to the three settings screens. */
function seo_admin_tabs(string $current): string
{
    $out = '<div class="ad-tabs">';

    foreach (SEO_SCREENS as $key => $screen) {
        $out .= '<a class="ad-tab ' . ($key === $current ? 'is-active' : '') . '"'
            . ' href="' . e(seo_admin_url($key)) . '">' . e($screen['label']) . '</a>';
    }

    // The schema and sitemap managers are built alongside this section rather
    // than in it, so they are linked only when their file is actually present.
    // A tab pointing at a 404 is worse than one tab fewer.
    foreach (['schema.php' => 'Schema', 'sitemap.php' => 'Sitemap'] as $file => $label) {
        if (is_file(__DIR__ . '/' . $file)) {
            $out .= '<a class="ad-tab" href="' . e(admin_url('seo/' . $file)) . '">' . e($label) . '</a>';
        }
    }

    // The settings screens are a different kind of thing - defaults and rules
    // rather than a queue - so they are linked, not tabbed alongside.
    foreach ([
        'Store defaults' => 'settings/seo.php',
        'Health report'  => 'settings/seo-health.php',
        'Redirects'      => 'settings/redirects.php',
    ] as $label => $path) {
        $out .= '<a class="ad-tab" href="' . e(admin_url($path)) . '">' . e($label)
            . ' ' . icon('external', 'w-3.5 h-3.5') . '</a>';
    }

    return $out . '</div>';
}

/** Breadcrumbs every screen in this section shares. */
function seo_admin_breadcrumbs(string $label): array
{
    return [
        ['label' => 'Dashboard', 'url' => admin_url('dashboard.php')],
        ['label' => 'SEO',       'url' => seo_admin_url()],
        ['label' => $label],
    ];
}
