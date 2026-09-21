<?php
/**
 * ShopInnKart Admin - Topbar.
 * Sidebar toggle, page title, global search, quick links and the account menu.
 */

declare(strict_types=1);

/** @var array $admin  @var array $badges  @var string $pageTitle */
$admin = $admin ?? admin_user();
$badges = $badges ?? admin_sidebar_badges();
$pageTitle = $pageTitle ?? 'Dashboard';
?>
<header class="ad-topbar">
    <button type="button" class="ad-iconbtn" id="adSidebarToggle"
            aria-label="Toggle sidebar" aria-controls="adSidebar">
        <?= icon('menu', 'w-5 h-5') ?>
    </button>

    <?php
    // Deliberately not a heading. The same string is rendered again a few
    // pixels below as the page's real <h1>, and two headings carrying identical
    // text made every admin page announce its title twice and broke the
    // document outline (h1 in the bar, h2 on the page).
    ?>
    <span class="ad-topbar__title" aria-hidden="true"><?= e($pageTitle) ?></span>

    <div class="ad-topbar__spacer"></div>

    <form class="ad-topbar__search" action="<?= e(admin_url('search.php')) ?>" method="get" role="search">
        <?= icon('search', 'w-4 h-4') ?>
        <label class="sik-sr" for="adGlobalSearch">Search orders, products and customers</label>
        <input type="search" id="adGlobalSearch" name="q"
               placeholder="Search orders, products, customers&hellip;"
               value="<?= e((string) ($_GET['q'] ?? '')) ?>" autocomplete="off">
    </form>

    <a class="ad-iconbtn" href="<?= e(url()) ?>" target="_blank" rel="noopener"
       title="View storefront" aria-label="View storefront">
        <?= icon('store', 'w-5 h-5') ?>
    </a>

    <?php if (admin_can('orders.view')): ?>
        <a class="ad-iconbtn" href="<?= e(admin_url('orders/?status=pending')) ?>"
           title="Pending orders" aria-label="Pending orders">
            <?= icon('bell', 'w-5 h-5') ?>
            <?php if (($badges['pending_orders'] ?? 0) > 0): ?><span class="ad-dot"></span><?php endif; ?>
        </a>
    <?php endif; ?>

    <div class="ad-dropdown" data-dropdown>
        <button type="button" class="ad-iconbtn" data-dropdown-toggle
                aria-haspopup="true" aria-expanded="false"
                style="width:auto;gap:8px;padding:0 6px 0 4px" aria-label="Account menu">
            <span class="ad-avatar"><?= e(initials((string) $admin['name'])) ?></span>
            <span class="hidden md:inline" style="font-size:13px;font-weight:600;color:var(--ad-text)"><?= e($admin['name']) ?></span>
            <?= icon('chevron-down', 'w-3.5 h-3.5') ?>
        </button>

        <div class="ad-dropdown__panel">
            <div class="ad-dropdown__head">
                <div style="font-weight:700;font-size:13.5px"><?= e($admin['name']) ?></div>
                <div style="font-size:12px;color:var(--ad-muted)"><?= e($admin['email']) ?></div>
                <div style="margin-top:6px">
                    <span class="sik-status sik-status--blue"><?= e((string) $admin['role_name']) ?></span>
                </div>
            </div>

            <?php if (admin_can('admins.edit')): ?>
                <a class="ad-dropdown__item" href="<?= e(admin_url('admins/edit.php?id=' . (int) $admin['id'])) ?>">
                    <?= icon('user', 'w-4 h-4') ?> My Profile
                </a>
            <?php endif; ?>
            <?php if (admin_can('settings.view')): ?>
                <a class="ad-dropdown__item" href="<?= e(admin_url('settings/general.php')) ?>">
                    <?= icon('settings', 'w-4 h-4') ?> Settings
                </a>
            <?php endif; ?>
            <?php if (admin_can('logs.view')): ?>
                <a class="ad-dropdown__item" href="<?= e(admin_url('logs/login-history.php')) ?>">
                    <?= icon('clock', 'w-4 h-4') ?> Login History
                </a>
            <?php endif; ?>

            <div class="ad-dropdown__divider"></div>

            <!-- Sign-out is a POST so a stray link or prefetch cannot end the session. -->
            <form method="post" action="<?= e(admin_url('logout.php')) ?>">
                <?= csrf_field() ?>
                <button type="submit" class="ad-dropdown__item ad-dropdown__item--danger">
                    <?= icon('logout', 'w-4 h-4') ?> Sign Out
                </button>
            </form>
        </div>
    </div>
</header>
