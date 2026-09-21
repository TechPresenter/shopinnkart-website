<?php
/**
 * ShopInnKart - Purchase history.
 *
 * orders.php answers "where is my parcel". This answers "what have I bought" —
 * the complete record, filterable by date range and status, readable either as
 * orders or as the individual items inside them.
 *
 * Every filter is normalised by account_order_filters() and every query runs
 * through account_order_where(), which scopes on user_id. There is no path here
 * that reads an order id from the request, so there is nothing to enumerate.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_once INCLUDES_PATH . '/account-layout.php';
// add_to_cart_button() lives in widgets.php, which init.php does not load.
require_once INCLUDES_PATH . '/widgets.php';

$user = require_login();
$userId = (int) $user['id'];

$view = (string) input('view', 'orders');
if (!in_array($view, ['orders', 'items'], true)) {
    $view = 'orders';
}

$filters = account_order_filters([
    'scope'    => 'all',
    'status'   => input('status', ''),
    'from'     => input('from', ''),
    'to'       => input('to', ''),
    'q'        => input('q', ''),
    'page'     => input_int('page', 1),
    'per_page' => $view === 'items' ? 20 : 10,
]);

$hasFilter = $filters['status'] !== '' || $filters['from'] !== '' || $filters['to'] !== '' || $filters['q'] !== '';

$summary = account_order_summary($userId, $filters);
$statusCounts = account_order_status_counts($userId, $filters);

$result = $view === 'items'
    ? account_order_lines($userId, $filters)
    : account_orders($userId, $filters);
$rows = $result['items'];
$pagination = $result['pagination'];

// A range the customer can pick without typing a date. "All time" is the
// absence of a range rather than a made-up earliest date.
$today = date('Y-m-d');
$presets = [
    '30d'  => ['label' => 'Last 30 days',  'from' => date('Y-m-d', strtotime('-30 days')),  'to' => $today],
    '6m'   => ['label' => 'Last 6 months', 'from' => date('Y-m-d', strtotime('-6 months')), 'to' => $today],
    'year' => ['label' => date('Y'),       'from' => date('Y') . '-01-01',                  'to' => $today],
];

$firstOrderYear = (int) Database::fetchColumn(
    'SELECT YEAR(MIN(`created_at`)) FROM `orders` WHERE `user_id` = :uid',
    ['uid' => $userId]
);

seo_set([
    'title'       => 'Purchase History',
    'description' => 'Every order you have placed, filterable by date and status.',
    'robots'      => 'noindex, nofollow',
]);

require INCLUDES_PATH . '/header.php';

account_layout_open('purchase-history', [
    'subtitle' => $summary['orders'] === 0
        ? ($hasFilter ? 'No purchases match these filters.' : 'Your complete purchase record will build up here.')
        // account_layout_open() escapes the subtitle, so this is the literal
        // character rather than an entity that would render as "&middot;".
        : $summary['orders'] . ' order' . ($summary['orders'] === 1 ? '' : 's')
            . ' · ' . $summary['units'] . ' item' . ($summary['units'] === 1 ? '' : 's')
            . ' · ' . money($summary['spent']) . ' spent',
]);
?>

<?php if ($summary['orders'] > 0 || $hasFilter): ?>
    <div class="sik-history__stats">
        <div class="sik-statcard">
            <span class="sik-statcard__label">Orders</span>
            <strong class="sik-statcard__value sik-num"><?= (int) $summary['orders'] ?></strong>
        </div>
        <div class="sik-statcard">
            <span class="sik-statcard__label">Items</span>
            <strong class="sik-statcard__value sik-num"><?= (int) $summary['units'] ?></strong>
        </div>
        <div class="sik-statcard">
            <span class="sik-statcard__label">Spent</span>
            <strong class="sik-statcard__value sik-num"><?= e(money($summary['spent'])) ?></strong>
        </div>
    </div>
<?php endif; ?>

<form class="sik-panel sik-historyfilter" method="get" action="<?= e(url('purchase-history.php')) ?>">
    <input type="hidden" name="view" value="<?= e_attr($view) ?>">

    <div class="sik-panel__body sik-historyfilter__body">
        <div class="sik-field sik-historyfilter__search">
            <label class="sik-label" for="histQ">Search</label>
            <input class="sik-input" id="histQ" type="search" name="q" value="<?= e_attr($filters['q']) ?>"
                   placeholder="Order number or product name" maxlength="80">
        </div>

        <div class="sik-field">
            <label class="sik-label" for="histFrom">From</label>
            <input class="sik-input" id="histFrom" type="date" name="from" value="<?= e_attr($filters['from']) ?>"
                   max="<?= e_attr($today) ?>"
                   <?= $firstOrderYear > 0 ? 'min="' . e_attr($firstOrderYear . '-01-01') . '"' : '' ?>>
        </div>

        <div class="sik-field">
            <label class="sik-label" for="histTo">To</label>
            <input class="sik-input" id="histTo" type="date" name="to" value="<?= e_attr($filters['to']) ?>"
                   max="<?= e_attr($today) ?>"
                   <?= $firstOrderYear > 0 ? 'min="' . e_attr($firstOrderYear . '-01-01') . '"' : '' ?>>
        </div>

        <div class="sik-field">
            <label class="sik-label" for="histStatus">Status</label>
            <select class="sik-select" id="histStatus" name="status">
                <option value="">Any status</option>
                <?php foreach (ORDER_STATUSES as $key => $label): ?>
                    <?php // Only a status this account has ever reached is offered. ?>
                    <?php if (!empty($statusCounts[$key]) || $filters['status'] === $key): ?>
                        <option value="<?= e_attr($key) ?>" <?= $filters['status'] === $key ? 'selected' : '' ?>>
                            <?= e($label) ?> (<?= (int) ($statusCounts[$key] ?? 0) ?>)
                        </option>
                    <?php endif; ?>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="sik-historyfilter__go">
            <button type="submit" class="sik-btn sik-btn--primary sik-btn--sm">
                <?= icon('filter', 'w-4 h-4') ?> <span class="sik-btn__label">Apply</span>
            </button>
            <?php if ($hasFilter): ?>
                <a class="sik-btn sik-btn--ghost sik-btn--sm" href="<?= e(url('purchase-history.php?view=' . $view)) ?>">Clear</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="sik-historyfilter__foot">
        <div class="sik-pills sik-historyfilter__presets">
            <?php foreach ($presets as $preset): ?>
                <?php $active = $filters['from'] === $preset['from'] && $filters['to'] === $preset['to']; ?>
                <a class="sik-pill<?= $active ? ' is-active' : '' ?>"
                   href="<?= e(url_with(['from' => $preset['from'], 'to' => $preset['to'], 'page' => null])) ?>">
                    <?= e($preset['label']) ?>
                </a>
            <?php endforeach; ?>
            <?php if ($filters['from'] !== '' || $filters['to'] !== ''): ?>
                <a class="sik-pill" href="<?= e(url_with(['from' => null, 'to' => null, 'page' => null])) ?>">All time</a>
            <?php endif; ?>
        </div>

        <?php // .sik-pills is the existing chooser component — same one the date
              // presets above use, so the two rows read as one control set. ?>
        <div class="sik-pills sik-historyfilter__view" role="group" aria-label="View purchase history as">
            <a class="sik-pill<?= $view === 'orders' ? ' is-active' : '' ?>"
               href="<?= e(url_with(['view' => null, 'page' => null])) ?>"
               <?= $view === 'orders' ? 'aria-current="true"' : '' ?>>
                <?= icon('package', 'w-4 h-4') ?> <span>Orders</span>
            </a>
            <a class="sik-pill<?= $view === 'items' ? ' is-active' : '' ?>"
               href="<?= e(url_with(['view' => 'items', 'page' => null])) ?>"
               <?= $view === 'items' ? 'aria-current="true"' : '' ?>>
                <?= icon('list', 'w-4 h-4') ?> <span>Items</span>
            </a>
        </div>
    </div>
</form>

<?php if ($rows === []): ?>
    <div class="sik-panel">
        <div class="sik-panel__body">
            <?php if ($hasFilter): ?>
                <?= account_empty(
                    'search',
                    'Nothing matches those filters',
                    'Try a wider date range, or clear the filters to see everything you have bought.',
                    'Clear filters',
                    url('purchase-history.php?view=' . $view)
                ) ?>
            <?php else: ?>
                <?= account_empty(
                    'clock',
                    'No purchases yet',
                    'Once you place your first order it joins your permanent record here, with invoices and one-tap reordering.',
                    'Start shopping',
                    url('shop.php')
                ) ?>
            <?php endif; ?>
        </div>
    </div>

<?php elseif ($view === 'items'): ?>
    <div class="sik-panel">
        <div class="sik-panel__head">
            <h2 class="sik-panel__title">Items you have bought</h2>
            <span class="sik-caption sik-num">
                <?= (int) $pagination['from'] ?>&ndash;<?= (int) $pagination['to'] ?> of <?= (int) $pagination['total'] ?>
            </span>
        </div>
        <div class="sik-panel__body" style="padding:0">
            <ul class="sik-buylist">
                <?php foreach ($rows as $line): ?>
                    <li class="sik-buylist__row">
                        <img class="sik-buylist__thumb" src="<?= e((string) $line['image_url']) ?>"
                             alt="<?= e((string) $line['product_name']) ?>"
                             width="56" height="56" loading="lazy">

                        <div class="sik-buylist__main">
                            <?php if ($line['url'] !== null): ?>
                                <a class="sik-buylist__name" href="<?= e((string) $line['url']) ?>">
                                    <?= e((string) $line['product_name']) ?>
                                </a>
                            <?php else: ?>
                                <span class="sik-buylist__name"><?= e((string) $line['product_name']) ?></span>
                            <?php endif; ?>
                            <p class="sik-buylist__meta">
                                <?php if (!empty($line['variant_name'])): ?>
                                    <span><?= e((string) $line['variant_name']) ?></span>
                                    <span aria-hidden="true">&middot;</span>
                                <?php endif; ?>
                                <span>Qty <?= (int) $line['quantity'] ?></span>
                                <span aria-hidden="true">&middot;</span>
                                <span class="sik-num"><?= e((string) $line['price_display']) ?> each</span>
                            </p>
                            <p class="sik-buylist__order">
                                <a href="<?= e((string) $line['order_url']) ?>">#<?= e((string) $line['order_number']) ?></a>
                                <span aria-hidden="true">&middot;</span>
                                <span><?= e(format_date($line['ordered_at'])) ?></span>
                                <?= account_status_pill((string) $line['status']) ?>
                            </p>
                        </div>

                        <div class="sik-buylist__side">
                            <strong class="sik-buylist__total sik-num"><?= e((string) $line['subtotal_display']) ?></strong>
                            <?php
                            // Routed through the one add-to-cart renderer rather
                            // than hand-rolled, so Buy again gets the same
                            // loading / success / in-cart / out-of-stock states
                            // as every other add control on the site. The variant
                            // is already known from the original line, so this
                            // never asks the customer to choose again.
                            if ($line['product_id'] !== null):
                                // Built explicitly rather than passing $line: the
                                // row comes from `oi.*`, so its `id` is the
                                // order-item id, and handing that straight to the
                                // button would put someone else's product id on
                                // the control.
                                echo add_to_cart_button([
                                    'id'       => (int) $line['product_id'],
                                    'name'     => (string) $line['product_name'],
                                    'slug'     => (string) ($line['product_slug'] ?? ''),
                                    'url'      => (string) ($line['url'] ?? ''),
                                    'in_stock' => (bool) $line['can_reorder'],
                                ], [
                                    'size'       => 'sm',
                                    'tone'       => 'outline',
                                    'block'      => false,
                                    'label'      => 'Buy again',
                                    'variant_id' => (int) ($line['variant_id'] ?? 0),
                                    'qty'        => max(1, (int) $line['quantity']),
                                ]);
                            endif;
                            ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>

    <?= account_pager($pagination) ?>

<?php else: ?>
    <div class="sik-orderlist">
        <?php foreach ($rows as $order): ?>
            <?php account_order_card($order, ['items_limit' => 3]); ?>
        <?php endforeach; ?>
    </div>

    <?= account_pager($pagination) ?>
<?php endif; ?>

<?php
account_layout_close();
require INCLUDES_PATH . '/footer.php';
