<?php
/**
 * ShopInnKart - Account menu.
 *
 * account_menu() (account-functions.php) decides *what* is in the menu.
 * This file is the single renderer for it: the header dropdown and the account
 * sidebar both draw from here, so a destination added in one can never be
 * missing from the other.
 */

declare(strict_types=1);

/**
 * Route key for a menu URL — 'orders.php' => 'orders'.
 * Account pages identify themselves by this key when marking a link current.
 */
function account_menu_key(string $url): string
{
    $path = parse_url($url, PHP_URL_PATH);
    return basename(is_string($path) && $path !== '' ? $path : $url, '.php');
}

/** Does this menu entry point at a page that is actually in the build? */
function account_menu_page_exists(string $url): bool
{
    $path = ltrim((string) (parse_url($url, PHP_URL_PATH) ?: $url), '/');
    return $path !== '' && is_file(ROOT_PATH . '/' . $path);
}

/**
 * Is the feature behind this menu entry switched on?
 *
 * Two of the account destinations are optional features (Admin > Settings >
 * Widgets), and when one is off its page answers 404. Filtering here rather
 * than in each renderer means the dropdown and the sidebar drop it together,
 * exactly as they already do for a page that has not shipped.
 */
function account_menu_feature_live(string $url): bool
{
    return match (account_menu_key($url)) {
        'wishlist' => wishlist_shows_on('header'),
        'compare'  => compare_shows_on('header'),
        default    => true,
    };
}

/**
 * The menu, minus anything whose page is not in this build.
 *
 * account_menu() describes the intended shape of the account area. Rendering an
 * entry whose file has not shipped puts a 404 in front of a customer, so it is
 * dropped here — from both surfaces at once, and it returns on its own the
 * moment the page lands.
 */
function account_menu_live(): array
{
    static $live = null;
    if ($live !== null) {
        return $live;
    }

    $live = [];
    foreach (account_menu() as $group) {
        $items = array_values(array_filter(
            $group['items'],
            static fn (array $item): bool => account_menu_page_exists((string) $item['url'])
                && account_menu_feature_live((string) $item['url'])
        ));
        if ($items !== []) {
            $group['items'] = $items;
            $live[] = $group;
        }
    }
    return $live;
}

/** Route key => label, so a page can title itself from the menu it lives in. */
function account_menu_labels(): array
{
    static $labels = null;
    if ($labels !== null) {
        return $labels;
    }

    $labels = [];
    foreach (account_menu() as $group) {
        foreach ($group['items'] as $item) {
            $labels[account_menu_key($item['url'])] = $item['label'];
        }
    }
    return $labels;
}

/** The live count behind an item's badge, 0 when it carries none. */
function account_menu_count(array $item, array $badges): int
{
    $key = (string) ($item['badge'] ?? '');
    return $key === '' ? 0 : (int) ($badges[$key] ?? 0);
}

/**
 * One row of the menu.
 *
 * @param string $variant 'menu' for the header dropdown, 'nav' for the sidebar.
 */
function account_menu_item_html(array $item, int $count, bool $isCurrent, string $variant = 'menu'): string
{
    $base = $variant === 'nav' ? 'sik-accountnav__link' : 'sik-menu__item';

    $html = '<a class="' . $base . ($isCurrent ? ' is-current' : '') . '"'
        . ' href="' . e(url((string) $item['url'])) . '"'
        . ($isCurrent ? ' aria-current="page"' : '') . '>'
        . icon((string) $item['icon'], 'sik-menu__icon')
        . '<span class="sik-menu__text">' . e((string) $item['label']) . '</span>';

    // The badge is aria-hidden because the count is already spoken as part of
    // the link text below; rendering it twice would read "Wishlist 3 3".
    if ($count > 0) {
        $html .= '<span class="sik-sr">, ' . $count . ' item' . ($count === 1 ? '' : 's') . '</span>'
            . '<span class="sik-menu__count sik-num" aria-hidden="true">'
            . e($count > 99 ? '99+' : (string) $count) . '</span>';
    }

    return $html . '</a>';
}

/**
 * The dropdown panel behind the header's account button.
 *
 * Signed in: identity, the three groups, then a destructive-styled sign out
 * that POSTs with a token. Guests get the two things they actually need —
 * sign in, create an account — plus the pages that work without one.
 */
function account_menu_dropdown(?array $user = null, string $panelId = 'sikAccountMenu'): void
{
    $user = $user ?? current_user();
    ?>
    <div class="sik-menu__panel sik-menu__panel--account" id="<?= e_attr($panelId) ?>"
         data-menu-panel aria-label="Account menu" hidden>

        <?php if ($user !== null): ?>
            <?php
            $name = trim((string) $user['first_name'] . ' ' . (string) ($user['last_name'] ?? ''));
            $badges = account_menu_badges();
            $current = current_route_key();
            ?>
            <a class="sik-menu__user" href="<?= e(url('account.php')) ?>">
                <span class="sik-menu__avatar" aria-hidden="true"><?= e(initials($name)) ?></span>
                <span class="sik-menu__ident">
                    <span class="sik-menu__name"><?= e($name !== '' ? $name : 'My account') ?></span>
                    <span class="sik-menu__mail"><?= e((string) $user['email']) ?></span>
                </span>
                <?= icon('chevron-right', 'sik-menu__go') ?>
            </a>

            <?php foreach (account_menu_live() as $group): ?>
                <div class="sik-menu__group" role="group"
                     aria-labelledby="<?= e_attr($panelId . 'G' . slugify((string) $group['label'])) ?>">
                    <p class="sik-menu__label" id="<?= e_attr($panelId . 'G' . slugify((string) $group['label'])) ?>">
                        <?= e((string) $group['label']) ?>
                    </p>
                    <?php foreach ($group['items'] as $item): ?>
                        <?= account_menu_item_html(
                            $item,
                            account_menu_count($item, $badges),
                            account_menu_key((string) $item['url']) === $current
                        ) ?>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>

            <?php // Signing out is a state change, so it posts with a token. ?>
            <form class="sik-menu__foot" method="post" action="<?= e(url('logout.php')) ?>">
                <?= csrf_field() ?>
                <button type="submit" class="sik-menu__item sik-menu__item--danger">
                    <?= icon('logout', 'sik-menu__icon') ?>
                    <span class="sik-menu__text">Log out</span>
                </button>
            </form>

        <?php else: ?>
            <div class="sik-menu__intro">
                <p class="sik-menu__welcome">Welcome to <?= e((string) setting('store_name', SITE_NAME)) ?></p>
                <p class="sik-menu__blurb">Sign in for faster checkout, order tracking and saved addresses.</p>
                <div class="sik-menu__auth">
                    <a class="sik-btn sik-btn--primary sik-btn--sm" href="<?= e(url('login.php')) ?>">Sign In</a>
                    <a class="sik-btn sik-btn--outline sik-btn--sm" href="<?= e(url('register.php')) ?>">Create Account</a>
                </div>
            </div>

            <div class="sik-menu__group">
                <?php
                // Everything here works without an account, so a guest is never
                // sent to a sign-in wall they did not ask for.
                $guestLinks = [
                    ['label' => 'Track Order',      'url' => 'track-order.php', 'icon' => 'truck'],
                ];
                // Only while a signed-out visitor can actually have a wishlist -
                // with the guest mode set to "prompt" or "hidden" this row would
                // point straight at a sign-in wall the guest did not ask for.
                if (wishlist_shows_on('header') && wishlist_usable()) {
                    $guestLinks[] = ['label' => 'Wishlist', 'url' => 'wishlist.php', 'icon' => 'heart', 'badge' => 'wishlist'];
                }
                $guestLinks[] = ['label' => 'Help &amp; Support', 'url' => 'contact.php', 'icon' => 'headset'];
                $guestBadges = account_menu_badges();
                ?>
                <?php foreach ($guestLinks as $guestLink): ?>
                    <a class="sik-menu__item" href="<?= e(url((string) $guestLink['url'])) ?>">
                        <?= icon((string) $guestLink['icon'], 'sik-menu__icon') ?>
                        <span class="sik-menu__text"><?= $guestLink['label'] ?></span>
                        <?php $guestCount = account_menu_count($guestLink, $guestBadges); ?>
                        <?php if ($guestCount > 0): ?>
                            <span class="sik-menu__count sik-num" aria-hidden="true"><?= (int) $guestCount ?></span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * The sidebar navigation on every account page.
 * Same source as the header dropdown — that is the whole point of this file.
 *
 * @param string $current Route key of the page rendering the sidebar.
 */
function account_menu_nav(string $current): void
{
    $badges = account_menu_badges();
    ?>
    <nav aria-label="Account sections">
        <?php foreach (account_menu_live() as $group): ?>
            <p class="sik-accountnav__label"><?= e((string) $group['label']) ?></p>
            <?php foreach ($group['items'] as $item): ?>
                <?= account_menu_item_html(
                    $item,
                    account_menu_count($item, $badges),
                    account_menu_key((string) $item['url']) === $current,
                    'nav'
                ) ?>
            <?php endforeach; ?>
        <?php endforeach; ?>

        <form method="post" action="<?= e(url('logout.php')) ?>">
            <?= csrf_field() ?>
            <button type="submit" class="sik-accountnav__link sik-accountnav__link--danger">
                <?= icon('logout', 'sik-menu__icon') ?>
                <span class="sik-menu__text">Log out</span>
            </button>
        </form>
    </nav>
    <?php
}
