<?php
/**
 * ShopInnKart - Notification centre.
 *
 * The full-screen version of the bell dropdown. Both surfaces render the same
 * row through notification_row_html() and read the same API, so what a customer
 * sees here and what they see under the bell can never disagree.
 *
 * The feed is exactly what `user_notifications` holds. Nothing on this page
 * writes a notification, and an account with no events shows the empty state
 * rather than filler.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_once INCLUDES_PATH . '/account-layout.php';
require_once INCLUDES_PATH . '/header-actions.php';

$user = require_login();
$userId = (int) $user['id'];

$types = notification_types();

$filter = trim((string) input('filter', ''));
if ($filter !== '' && $filter !== 'unread' && !array_key_exists($filter, $types)) {
    $filter = '';
}

$perPage = 20;
$page = max(1, input_int('page', 1));

$total  = notification_total($userId, $filter);
$pagination = paginate($total, $perPage, $page);
$rows = user_notifications($userId, $perPage, (int) $pagination['offset'], $filter);

$unread = unread_notification_count($userId);
$typeCounts = notification_type_counts($userId);
$allCount = array_sum($typeCounts);

/*
 * A tab is offered only for a type the customer actually has rows in — the
 * same rule orders.php uses for its status tabs. Sending someone to a filter
 * that is guaranteed to be empty is not a filter, it is a dead end.
 */
$tabs = ['' => ['label' => 'All', 'count' => $allCount]];
if ($unread > 0 || $filter === 'unread') {
    $tabs['unread'] = ['label' => 'Unread', 'count' => $unread];
}
foreach ($types as $key => $meta) {
    if (!empty($typeCounts[$key])) {
        $tabs[$key] = ['label' => (string) $meta['label'], 'count' => (int) $typeCounts[$key]];
    }
}

$subtitle = $allCount === 0
    ? 'Order updates, delivery alerts and offers will arrive here.'
    : ($unread === 0
        ? 'You are all caught up.'
        : $unread . ' unread of ' . $allCount . ' notification' . ($allCount === 1 ? '' : 's') . '.');

seo_set([
    'title'       => 'Notifications',
    'description' => 'Order updates, delivery alerts and offers for your ShopInnKart account.',
    'robots'      => 'noindex, nofollow',
]);

require INCLUDES_PATH . '/header.php';

// The button is hidden rather than absent when everything is read, so marking
// the last one read client-side has something to hide again without a reload.
account_layout_open('notifications', [
    'subtitle' => $subtitle,
    'action'   => '<button type="button" class="sik-btn sik-btn--outline sik-btn--sm"'
        . ' data-notif-read-all' . ($unread === 0 ? ' hidden' : '') . '>'
        . icon('check-circle', 'w-4 h-4')
        . ' <span class="sik-btn__label">Mark all read</span></button>',
]);
?>

<?php if ($allCount > 0): ?>
    <div class="sik-tabs sik-tabs--filters" aria-label="Filter notifications">
        <?php foreach ($tabs as $key => $tab): ?>
            <a class="sik-tab<?= $filter === (string) $key ? ' is-active' : '' ?>"
               href="<?= e(url_with(['filter' => $key === '' ? null : $key, 'page' => null])) ?>"
               <?= $filter === (string) $key ? 'aria-current="page"' : '' ?>>
                <?= e($tab['label']) ?>
                <span class="sik-tab__count sik-num"><?= (int) $tab['count'] ?></span>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($rows === []): ?>
    <div class="sik-panel">
        <div class="sik-panel__body">
            <?php if ($filter !== ''): ?>
                <?= account_empty(
                    'inbox',
                    $filter === 'unread' ? 'Nothing unread' : 'Nothing here yet',
                    $filter === 'unread'
                        ? 'You have read everything in your feed.'
                        : 'No notifications of this kind have arrived yet.',
                    'Show everything',
                    url('notifications.php')
                ) ?>
            <?php else: ?>
                <?= account_empty(
                    'inbox',
                    'No notifications yet',
                    'When an order moves, a delivery is on its way or a saved product drops in price, you will hear about it here.',
                    'Start shopping',
                    url('shop.php')
                ) ?>
            <?php endif; ?>
        </div>
    </div>
<?php else: ?>
    <div class="sik-panel sik-feed" data-notif-feed
         data-offset="<?= (int) ($pagination['offset'] + count($rows)) ?>"
         data-filter="<?= e_attr($filter) ?>">
        <ul class="sik-feed__list" data-notif-list>
            <?php foreach ($rows as $row): ?>
                <?= account_notification_item_html($row) ?>
            <?php endforeach; ?>
        </ul>

        <?php // Shown only once JS has replaced the pager below with it. ?>
        <div class="sik-feed__foot">
            <button type="button" class="sik-btn sik-btn--outline sik-btn--block" data-notif-more hidden>
                <span class="sik-btn__label">Load older notifications</span>
            </button>
        </div>

        <?php // The row controls, defined once, for account.js to clone onto the
              // rows it appends. Both buttons are present; the clone drops the
              // mark-read one when the row it is going on is already read. ?>
        <template data-notif-tools><?= account_notification_tools_html() ?></template>
    </div>

    <?php // The no-JS path. account.js hides this and shows the button above. ?>
    <div data-notif-pager><?= account_pager($pagination) ?></div>
<?php endif; ?>

<?php
account_layout_close();
require INCLUDES_PATH . '/footer.php';
