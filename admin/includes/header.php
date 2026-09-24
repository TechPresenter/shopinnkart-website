<?php
/**
 * ShopInnKart Admin - Layout head + shell open.
 *
 * Include AFTER admin_require(). Set $pageTitle (and optionally $pageSubtitle,
 * $pageActions, $breadcrumbs) before requiring this file.
 */

declare(strict_types=1);

if (!defined('SIK_BOOTSTRAPPED')) {
    require_once dirname(__DIR__, 2) . '/includes/init.php';
}

$admin        = admin_user();
$pageTitle    = $pageTitle ?? 'Dashboard';
$pageSubtitle = $pageSubtitle ?? '';
$breadcrumbs  = $breadcrumbs ?? [];
$storeName    = (string) setting('store_name', SITE_NAME);

/* PARTIAL MODE (A2). A quick view fetches this very page with ?partial=1 and
   wants the body only - no document, no shell, no flashes, no page header. It
   is an early return rather than a branch around 90 lines of layout, and the
   matching `</div>` is the whole of footer.php in the same mode.

   Authorisation is untouched: the page ran admin_require() before including
   this file, so a partial fetched while logged out gets the same redirect a
   full page gets. Flashes are deliberately NOT pulled here - flash_pull()
   consumes them, and a fragment swallowing the operator's "Order updated"
   banner would lose it for the page underneath. */
if (admin_partial_request()) {
    echo '<div class="ad-partial" data-title="' . e_attr($pageTitle) . '">';
    return;
}

$badges       = admin_sidebar_badges();

// The collapse preference lives in a cookie so it survives navigation without
// a round trip; the admin setting supplies the default for a new browser.
$collapsed = ($_COOKIE['sik_admin_sidebar'] ?? setting('admin_sidebar_collapsed', '0')) === '1';
?>
<!doctype html>
<?php
/* `no-js` is swapped for `js` by boot() in admin.js. It is a class rather than
   an inline script because the admin is being kept CSP-ready (script-src
   'self'), and the only inline script this layout is allowed is A18's
   pre-paint theme bootstrap. Nothing styles on it yet, so a boot-time swap
   cannot flash; when A18 lands, its bootstrap sets the class before paint. */
?>
<html lang="en" class="no-js">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <?= csrf_meta() ?>
    <title><?= e($pageTitle) ?> &middot; <?= e($storeName) ?> Admin</title>

    <?= brand_favicon_links() ?>
    <?php
    /* utilities.css, not tailwind.css. The admin referenced 22 tailwind
       classes (the w- and h- icon sizes, `hidden`, `md:inline`, `text-sm`,
       `font-semibold`) and every one of them is already in utilities.css,
       verbatim and in source order - so the cascade is identical and the page
       stops downloading 460 KB to use 22 rules. The checker that proves it is
       scratchpad/adminv2/util_check.php; tailwind.css stays on disk as the
       source those rules are copied from. */
    ?>
    <link rel="stylesheet" href="<?= e(asset('css/utilities.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/admin.css')) ?>">
    <?php
    /* The two INPUT tokens of admin.css section 1. Every other admin colour is
       a color-mix() of these two, so one setting moves the whole palette. The
       fallbacks are the "Calm Wine" defaults, not the pre-rebrand orange and
       navy they replaced. */
    ?>
    <style>
        :root {
            --ad-primary: <?= e(setting('admin_primary', '#D4134E')) ?>;
            --ad-sidebar: <?= e(setting('admin_sidebar_bg', '#4A041C')) ?>;
        }
    </style>
</head>
<body class="ad-body<?= $collapsed ? ' ad-collapsed' : '' ?>">

<div class="ad-shell">

    <?php require ADMIN_PATH . '/includes/sidebar.php'; ?>

    <div class="ad-overlay" id="adOverlay"></div>

    <div class="ad-main">

        <?php require ADMIN_PATH . '/includes/topbar.php'; ?>

        <div class="ad-content">
            <div class="ad-container">

                <?php
                /* A3: a flash and an AJAX result are the same news, so they
                   now arrive as the same thing - a toast. The banner that
                   used to sit here said "saved" in one shape and SIK.toast()
                   said it in another, on the same screen, minutes apart.

                   The payload is the storefront's own #sikFlash contract, so
                   notifications.js needs nothing added to read it, and the
                   admin and the shop cannot drift.

                   The banner survives inside <noscript>, which is the only
                   fallback with no flash of content: with scripts on the
                   parser never builds those nodes, so there is nothing to
                   hide and nothing to unhide. flash_pull() clears the bag, so
                   it is read ONCE into a variable and used twice. */
                $adFlashes = [];
                foreach (flash_pull() as $adFlash) {
                    $adTone = in_array($adFlash['type'] ?? '', ['success', 'error', 'warning'], true)
                        ? $adFlash['type']
                        : 'info';
                    $adFlashes[] = ['type' => $adTone, 'message' => (string) ($adFlash['message'] ?? '')];
                }
                ?>
                <?php if ($adFlashes !== []): ?>
                    <script type="application/json" id="sikFlash"><?= e_json($adFlashes) ?></script>
                    <noscript>
                        <div class="ad-flash-noscript">
                            <?php foreach ($adFlashes as $adFlash): ?>
                                <div class="sik-alert sik-alert--<?= e($adFlash['type']) ?>">
                                    <?= icon($adFlash['type'] === 'success' ? 'check-circle'
                                        : ($adFlash['type'] === 'error' ? 'alert' : 'info'), 'w-5 h-5') ?>
                                    <div><?= e($adFlash['message']) ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </noscript>
                <?php endif; ?>

                <div class="ad-page">
                    <div>
                        <?php if ($breadcrumbs !== []): ?>
                            <nav class="ad-crumb" aria-label="Breadcrumb">
                                <?php $lastIndex = count($breadcrumbs) - 1; ?>
                                <?php foreach ($breadcrumbs as $i => $crumb): ?>
                                    <?php if ($i < $lastIndex && !empty($crumb['url'])): ?>
                                        <a href="<?= e($crumb['url']) ?>"><?= e($crumb['label']) ?></a>
                                        <?= icon('chevron-right', 'w-3 h-3') ?>
                                    <?php else: ?>
                                        <span><?= e($crumb['label']) ?></span>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </nav>
                        <?php endif; ?>

                        <?php // The page header carries the document's single h1. ?>
                        <h1 class="ad-page__title"><?= e($pageTitle) ?></h1>
                        <?php if ($pageSubtitle !== ''): ?>
                            <p class="ad-page__sub"><?= e($pageSubtitle) ?></p>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($pageActions)): ?>
                        <div class="ad-page__actions"><?= $pageActions ?></div>
                    <?php endif; ?>
                </div>
