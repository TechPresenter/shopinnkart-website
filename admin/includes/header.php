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
$badges       = admin_sidebar_badges();

// The collapse preference lives in a cookie so it survives navigation without
// a round trip; the admin setting supplies the default for a new browser.
$collapsed = ($_COOKIE['sik_admin_sidebar'] ?? setting('admin_sidebar_collapsed', '0')) === '1';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <?= csrf_meta() ?>
    <title><?= e($pageTitle) ?> &middot; <?= e($storeName) ?> Admin</title>

    <?= brand_favicon_links() ?>
    <link rel="stylesheet" href="<?= e(asset('css/tailwind.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/admin.css')) ?>">
    <style>
        :root {
            --ad-primary: <?= e(setting('admin_primary', '#F4511E')) ?>;
            --ad-sidebar: <?= e(setting('admin_sidebar_bg', '#0F2143')) ?>;
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

                <?php foreach (flash_pull() as $flash): ?>
                    <?php $tone = in_array($flash['type'], ['success', 'error', 'warning'], true) ? $flash['type'] : 'info'; ?>
                    <div class="sik-alert sik-alert--<?= e($tone) ?>">
                        <?= icon($tone === 'success' ? 'check-circle' : ($tone === 'error' ? 'alert' : 'info'), 'w-5 h-5') ?>
                        <div><?= e($flash['message']) ?></div>
                    </div>
                <?php endforeach; ?>

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
