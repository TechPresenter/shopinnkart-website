<?php
/**
 * ShopInnKart Admin - Sidebar navigation.
 *
 * The tree comes from admin_menu(); every entry declares the permission that
 * reveals it, so the menu can never show a page the admin cannot open.
 * Hiding a link is cosmetic — admin_require() on the page is the real gate.
 *
 * Every local variable here is prefixed `$nav`. This file is included at the
 * file scope of whichever admin page is rendering, so a plain name like $item
 * would silently overwrite that page's own variable.
 */

declare(strict_types=1);

/** @var array $badges live counters from admin_sidebar_badges() */
$navBadges = $badges ?? admin_sidebar_badges();
$navStore  = $storeName ?? (string) setting('store_name', SITE_NAME);
?>
<aside class="ad-sidebar" id="adSidebar">
    <div class="ad-sidebar__head">
        <a href="<?= e(admin_url('dashboard.php')) ?>" aria-label="<?= e($navStore) ?> admin home">
            <img class="ad-sidebar__logo-full"
                 src="<?= e(brand_logo_light_src()) ?>"
                 alt="<?= e($navStore) ?>" width="170" height="47">
            <img class="ad-sidebar__logo-icon"
                 src="<?= e(brand_mark_src()) ?>"
                 alt="<?= e($navStore) ?>" width="32" height="32">
        </a>
    </div>

    <nav class="ad-sidebar__nav" aria-label="Admin navigation">
        <?php foreach (admin_menu() as $navItem): ?>
            <?php
            if (!empty($navItem['permission']) && !admin_can($navItem['permission'])) {
                continue;
            }

            $navChildren = array_values(array_filter(
                $navItem['children'] ?? [],
                static fn ($child) => empty($child['permission']) || admin_can($child['permission'])
            ));

            // A parent whose children are all hidden is not worth rendering.
            if (!empty($navItem['children']) && $navChildren === []) {
                continue;
            }

            $navActive = admin_menu_active($navItem);
            $navCount  = !empty($navItem['badge']) ? (int) ($navBadges[$navItem['badge']] ?? 0) : 0;
            ?>

            <?php if ($navChildren === []): ?>
                <a class="ad-nav__link <?= $navActive ? 'is-active' : '' ?>"
                   href="<?= e(admin_url((string) $navItem['url'])) ?>"
                   <?= $navActive ? 'aria-current="page"' : '' ?>
                   data-tip="<?= e_attr($navItem['label']) ?>">
                    <?= icon($navItem['icon'] ?? 'grid', 'w-5 h-5') ?>
                    <span class="ad-nav__label"><?= e($navItem['label']) ?></span>
                    <?php if ($navCount > 0): ?>
                        <span class="ad-nav__badge"><?= $navCount > 99 ? '99+' : $navCount ?></span>
                    <?php endif; ?>
                </a>
            <?php else: ?>
                <div class="ad-nav__group <?= $navActive ? 'is-open' : '' ?>" data-nav-group>
                    <button type="button" class="ad-nav__link <?= $navActive ? 'is-active' : '' ?>"
                            data-nav-toggle aria-expanded="<?= $navActive ? 'true' : 'false' ?>"
                            data-tip="<?= e_attr($navItem['label']) ?>">
                        <?= icon($navItem['icon'] ?? 'grid', 'w-5 h-5') ?>
                        <span class="ad-nav__label"><?= e($navItem['label']) ?></span>
                        <?php if ($navCount > 0): ?>
                            <span class="ad-nav__badge"><?= $navCount > 99 ? '99+' : $navCount ?></span>
                        <?php endif; ?>
                        <span class="ad-nav__caret"><?= icon('chevron-down', 'w-3.5 h-3.5') ?></span>
                    </button>
                    <div class="ad-nav__sub">
                        <?php foreach ($navChildren as $navChild): ?>
                            <?php $navChildActive = admin_menu_active($navChild); ?>
                            <a class="ad-nav__sublink <?= $navChildActive ? 'is-active' : '' ?>"
                               <?= $navChildActive ? 'aria-current="page"' : '' ?>
                               href="<?= e(admin_url((string) $navChild['url'])) ?>">
                                <span><?= e($navChild['label']) ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>

    <div class="ad-sidebar__foot">
        <a href="<?= e(url()) ?>" target="_blank" rel="noopener"
           style="display:flex;align-items:center;gap:8px;color:inherit">
            <?= icon('external', 'w-4 h-4') ?> View storefront
        </a>
    </div>
</aside>
<?php
// Release the loop variables so nothing downstream inherits them.
unset($navItem, $navChild, $navChildren, $navActive, $navCount, $navChildActive);
