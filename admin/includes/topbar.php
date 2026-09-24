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

    <?php
    /* The account menu is the first component on admin_dropdown() (A2). It is
       the same list it always was; what it gains is role="menu" with real
       menuitems, aria-controls, roving arrow keys, Home/End, type-ahead, an
       Escape that closes exactly this layer and hands focus back to the
       button, and a bottom sheet on a phone instead of a 230px panel pinned
       to the right edge of a 360px screen. */
    $acctItems = [];
    $acctItems[] = ['html' =>
        '<div class="ad-menu__head-name">' . e((string) $admin['name']) . '</div>'
        . '<div class="ad-menu__head-meta">' . e((string) $admin['email']) . '</div>'
        . '<div class="ad-menu__head-role">'
        . '<span class="sik-status sik-status--blue">' . e((string) $admin['role_name']) . '</span></div>',
    ];
    if (admin_can('admins.edit')) {
        $acctItems[] = ['label' => 'My Profile', 'icon' => 'user',
                        'url' => admin_url('admins/edit.php?id=' . (int) $admin['id'])];
    }
    // No permission check: every admin owns their own password and second
    // factor, including one whose role grants nothing else.
    $acctItems[] = ['label' => 'My Security', 'icon' => 'lock', 'url' => admin_url('account/index.php')];
    if (admin_can('settings.view')) {
        $acctItems[] = ['label' => 'Settings', 'icon' => 'settings', 'url' => admin_url('settings/general.php')];
    }
    if (admin_can('logs.view')) {
        $acctItems[] = ['label' => 'Login History', 'icon' => 'clock', 'url' => admin_url('logs/login-history.php')];
    }
    $acctItems[] = ['divider' => true];
    // Sign-out stays a POST so a stray link or a prefetch cannot end the session.
    $acctItems[] = ['label' => 'Sign Out', 'icon' => 'logout', 'tone' => 'danger',
                    'form' => ['action' => admin_url('logout.php')]];

    echo admin_dropdown([
        'id'      => 'adAccountMenu',
        'trigger' => [
            'variant' => 'icon',
            'class'   => 'ad-topbar__account',
            // The panel's identity block is aria-hidden (a role="menu" may
            // hold only menuitems), so whose account this is has to be said
            // here, where a screen reader will actually reach it.
            'aria_label' => 'Account menu: ' . $admin['name'] . ', ' . $admin['role_name'],
            'html'    => '<span class="ad-avatar">' . e(initials((string) $admin['name'])) . '</span>'
                       . '<span class="hidden md:inline ad-topbar__account-name">' . e((string) $admin['name']) . '</span>'
                       . icon('chevron-down', 'w-3.5 h-3.5'),
        ],
        'items'          => $acctItems,
        'align'          => 'end',
        'sheet_on_phone' => true,
    ]);
    ?>
</header>
