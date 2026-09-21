<?php
/**
 * ShopInnKart - Header action cluster.
 *
 * The count badge on every header action is rendered from one function here and
 * updated from one function in app.js (SIK.setBadge), which is what keeps the
 * cart, wishlist, compare and notification counters looking and behaving alike.
 *
 * This file also owns the notification bell: the button, its dropdown, and the
 * markup for a single notification row (shared with /api/notifications/list.php
 * so the panel's first paint and its refresh can never disagree).
 */

declare(strict_types=1);

// account_menu_page_exists() decides whether the feed screen is in this build.
require_once __DIR__ . '/account-menu.php';

/**
 * Accessible name for an action that carries a count.
 *
 * The badge is aria-hidden — it duplicates the number visually — so without the
 * count stated here a screen-reader user hears "Cart" and never learns whether
 * anything is in it. SIK.setBadge() rewrites this same string client-side.
 */
function header_action_label(string $noun, int $count, string $unit = 'item'): string
{
    if ($count === 0) {
        return $noun . ', empty';
    }
    return $noun . ', ' . $count . ' ' . $unit . ($count === 1 ? '' : 's');
}

/**
 * The one count badge. `$hook` is the data attribute the matching JS module
 * targets, e.g. 'data-cart-count'.
 */
function header_action_badge(int $count, string $hook): string
{
    return '<span class="sik-action__count sik-num" ' . $hook
        . ' data-count="' . $count . '" aria-hidden="true"'
        . ($count === 0 ? ' style="display:none"' : '') . '>'
        . e($count > 99 ? '99+' : (string) $count)
        . '</span>';
}

/**
 * One notification row.
 *
 * $row is a decorated row from user_notifications(): icon, tone, type_label,
 * ago, href and image_url are already resolved there.
 */
function notification_row_html(array $row): string
{
    $isUnread = (int) ($row['is_read'] ?? 0) === 0;
    $href     = (string) ($row['href'] ?? '');
    $tag      = $href !== '' ? 'a' : 'div';

    $html = '<' . $tag . ' class="sik-notif' . ($isUnread ? ' is-unread' : '') . '"'
        . ' data-notification="' . (int) $row['id'] . '"'
        . ($href !== '' ? ' href="' . e($href) . '"' : '') . '>';

    // A product picture beats a generic glyph when the event has one.
    if (!empty($row['image_url'])) {
        $html .= '<img class="sik-notif__thumb" src="' . e((string) $row['image_url'])
            . '" alt="" loading="lazy" width="40" height="40">';
    } else {
        $html .= '<span class="sik-notif__icon sik-tone--' . e_attr((string) ($row['tone'] ?? 'gray')) . '">'
            . icon((string) ($row['icon'] ?? 'bell'), 'sik-notif__glyph')
            . '</span>';
    }

    $html .= '<span class="sik-notif__body">';

    if ($isUnread) {
        $html .= '<span class="sik-sr">Unread. </span>';
    }

    $html .= '<span class="sik-notif__title">' . e((string) $row['title']) . '</span>';

    if (!empty($row['message'])) {
        $html .= '<span class="sik-notif__text">' . e(str_limit((string) $row['message'], 110)) . '</span>';
    }

    $html .= '<span class="sik-notif__meta">'
        . '<span class="sik-notif__type">' . e((string) ($row['type_label'] ?? 'Updates')) . '</span>'
        . '<time datetime="' . e_attr(date('c', strtotime((string) $row['created_at']) ?: time())) . '">'
        . e((string) ($row['ago'] ?? '')) . '</time>'
        . '</span></span>';

    if ($isUnread) {
        $html .= '<span class="sik-notif__dot" aria-hidden="true"></span>';
    }

    return $html . '</' . $tag . '>';
}

/**
 * The body of the bell dropdown: the rows, or the empty state.
 * Returned as a string so the API can hand back exactly what the panel painted.
 */
function notification_list_html(array $rows): string
{
    if ($rows === []) {
        return '<div class="sik-menu__empty">'
            . icon('bell', 'sik-menu__empty-icon')
            . '<p class="sik-menu__empty-title">No notifications yet</p>'
            . '<p class="sik-menu__empty-text">Order updates and price alerts will show up here.</p>'
            . '</div>';
    }

    $html = '';
    foreach ($rows as $row) {
        $html .= notification_row_html($row);
    }
    return $html;
}

/**
 * The notification bell and its dropdown.
 * Only ever rendered for a signed-in customer — a guest has no feed to show.
 */
function notification_menu(?int $unread = null): void
{
    if (!is_logged_in()) {
        return;
    }

    $unread = $unread ?? unread_notification_count();
    $rows   = user_notifications(null, 6);

    // The full feed screen may not be in this build yet. Rather than link a 404,
    // the panel keeps the six it has and the no-JS fallback lands on the account
    // dashboard; the footer link reappears the moment the page ships.
    $feedPage = account_menu_page_exists('notifications.php');
    $feedUrl  = url($feedPage ? 'notifications.php' : 'account.php');
    ?>
    <div class="sik-menu" data-menu>
        <?php // A link, not a button: without JavaScript this still reaches the feed.
              // header.js upgrades it into the dropdown trigger and adds the ARIA
              // that only makes sense once the panel can actually open. ?>
        <a class="sik-action" id="sikBellToggle" data-menu-toggle
           href="<?= e($feedUrl) ?>"
           aria-label="<?= e(header_action_label('Notifications', $unread, 'unread')) ?>">
            <?= icon('bell', 'sik-action__icon') ?>
            <?= header_action_badge($unread, 'data-notification-count') ?>
        </a>

        <div class="sik-menu__panel sik-menu__panel--notif" id="sikBellMenu"
             data-menu-panel aria-label="Notifications" hidden>

            <div class="sik-menu__head">
                <p class="sik-menu__title">Notifications</p>
                <?php // .sik-btn is what gives SIK.showLoader() somewhere to put its spinner. ?>
                <button type="button" class="sik-btn sik-btn--ghost sik-btn--sm sik-menu__action"
                        data-notifications-read-all <?= $unread === 0 ? 'hidden' : '' ?>>
                    <span class="sik-btn__label">Mark all read</span>
                </button>
            </div>

            <div class="sik-menu__list" data-notifications-list>
                <?= notification_list_html($rows) ?>
            </div>

            <?php if ($feedPage): ?>
                <a class="sik-menu__more" href="<?= e($feedUrl) ?>">
                    <span>View all notifications</span>
                    <?= icon('arrow-right', 'sik-menu__go') ?>
                </a>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

/**
 * The shortcut links under the header search field.
 *
 * Admin > Appearance > Header > "Popular searches" wins when it is filled in:
 * a comma list, each term becoming a real search. Left empty the row builds
 * itself from the store's featured categories, which is the honest default —
 * the alternative, the top rows of search_logs, still carries terms from
 * before this shop sold lighting and would offer "laptop" to a shopper looking
 * for diyas.
 *
 * @return array<int, array{label: string, url: string}>
 */
function popular_searches(int $limit = 6): array
{
    $custom = trim((string) setting('header_search_popular', ''));
    if ($custom !== '') {
        $out = [];
        foreach (array_slice(array_filter(array_map('trim', explode(',', $custom))), 0, $limit) as $term) {
            $out[] = ['label' => $term, 'url' => url('search.php?q=' . rawurlencode($term))];
        }
        return $out;
    }

    // Featured first, then the rest of the tree in order. A store that has
    // flagged one category as featured still gets a full row rather than a
    // lonely single shortcut.
    $out  = [];
    $seen = [];
    foreach ([featured_categories($limit), all_categories()] as $pass) {
        foreach ($pass as $category) {
            $slug = (string) ($category['slug'] ?? '');
            if ($slug === '' || isset($seen[$slug])) {
                continue;
            }
            $seen[$slug] = true;
            $out[] = ['label' => (string) $category['name'], 'url' => category_url($slug)];
            if (count($out) >= $limit) {
                return $out;
            }
        }
    }

    return $out;
}

/**
 * Is anything actually discounted right now?
 *
 * The deals pill on the navigation row links to /deals.php, and a store with
 * nothing on sale should not advertise one. Cheap enough to ask on every page
 * view once it is cached.
 */
function store_has_deals(): bool
{
    return (bool) cache_remember('deals.any', 300, static function (): bool {
        return (int) Database::fetchColumn(
            "SELECT COUNT(*) FROM `products`
              WHERE `status` = 'active'
                AND `sale_price` IS NOT NULL AND `sale_price` > 0 AND `sale_price` < `price`"
        ) > 0;
    });
}
