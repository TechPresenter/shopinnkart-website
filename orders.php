<?php
/**
 * ShopInnKart - Orders on their way.
 *
 * This screen answers "where is my parcel". Everything that has finished —
 * delivered, cancelled, returned, refunded — lives in purchase-history.php,
 * which is the searchable permanent record. `?status=` still reaches a closed
 * status directly so the links that already exist keep working.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_once INCLUDES_PATH . '/account-layout.php';

$user = require_login();
$userId = (int) $user['id'];

$status = (string) input('status', '');
if ($status !== '' && !array_key_exists($status, ORDER_STATUSES)) {
    $status = '';
}

// Asking for a closed status widens the scope rather than returning nothing —
// an old bookmark to ?status=delivered must not land on an empty page.
$scope = ($status !== '' && !in_array($status, account_active_statuses(), true)) ? 'all' : 'active';

$filters = account_order_filters([
    'scope'    => $scope,
    'status'   => $status,
    'page'     => input_int('page', 1),
    'per_page' => 10,
]);

$result = account_orders($userId, $filters);
$orders = $result['items'];
$pagination = $result['pagination'];

$statusCounts = account_order_status_counts($userId, $filters);
$activeTotal = array_sum($statusCounts);

// Only a status this customer actually has orders in gets a tab, so the filter
// bar can never send anyone to a guaranteed-empty list.
$tabs = ['' => ['label' => 'All', 'count' => $activeTotal]];
foreach (ORDER_STATUSES as $key => $label) {
    if (!empty($statusCounts[$key])) {
        $tabs[$key] = ['label' => $label, 'count' => (int) $statusCounts[$key]];
    }
}

// Whether there is a past to point at, so the empty state can offer it.
[$closedPlaceholders, $closedParams] = Database::inPlaceholders(account_closed_statuses(), 'cl');
$closedTotal = Database::count(
    'orders',
    '`user_id` = :uid AND `status` IN (' . $closedPlaceholders . ')',
    array_merge(['uid' => $userId], $closedParams)
);

seo_set([
    'title'       => 'My Orders',
    'description' => 'Track, cancel, reorder and download invoices for your ShopInnKart orders.',
    'robots'      => 'noindex, nofollow',
]);

require INCLUDES_PATH . '/header.php';

account_layout_open('orders', [
    'subtitle' => $activeTotal === 0
        ? 'Nothing is on its way right now.'
        : 'Showing ' . $pagination['from'] . '-' . $pagination['to'] . ' of ' . $pagination['total']
            . ' order' . ($pagination['total'] === 1 ? '' : 's') . '.',
    'action'   => '<a class="sik-viewall" href="' . e(url('purchase-history.php')) . '">'
        . 'Purchase history ' . icon('arrow-right', 'w-3.5 h-3.5') . '</a>',
]);
?>

<?php if ($activeTotal > 0 && count($tabs) > 2): ?>
    <div class="sik-tabs sik-tabs--filters" aria-label="Filter orders by status">
        <?php foreach ($tabs as $key => $tab): ?>
            <a class="sik-tab<?= $status === (string) $key ? ' is-active' : '' ?>"
               href="<?= e(url_with(['status' => $key === '' ? null : $key, 'page' => null])) ?>"
               <?= $status === (string) $key ? 'aria-current="page"' : '' ?>>
                <?= e($tab['label']) ?>
                <span class="sik-tab__count sik-num"><?= (int) $tab['count'] ?></span>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($orders === []): ?>
    <div class="sik-panel">
        <div class="sik-panel__body">
            <?php if ($status !== ''): ?>
                <?= account_empty(
                    'package',
                    'No ' . strtolower(ORDER_STATUSES[$status]) . ' orders',
                    'Nothing matches this filter right now.',
                    'Show all open orders',
                    url('orders.php')
                ) ?>
            <?php elseif ($closedTotal > 0): ?>
                <?= account_empty(
                    'package',
                    'Nothing on its way',
                    'Every one of your orders has been completed. The full record, with invoices and reordering, is in your purchase history.',
                    'Open purchase history',
                    url('purchase-history.php')
                ) ?>
            <?php else: ?>
                <?= account_empty(
                    'package',
                    'You have not ordered yet',
                    'Orders you place appear here with live tracking until they are delivered, then move into your purchase history.',
                    'Start shopping',
                    url('shop.php')
                ) ?>
            <?php endif; ?>
        </div>
    </div>
<?php else: ?>
    <div class="sik-orderlist">
        <?php foreach ($orders as $order): ?>
            <?php account_order_card($order, ['items_limit' => 3]); ?>
        <?php endforeach; ?>
    </div>

    <?= account_pager($pagination) ?>
<?php endif; ?>

<?php
account_layout_close();
require INCLUDES_PATH . '/footer.php';
