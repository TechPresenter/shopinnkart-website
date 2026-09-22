<?php
/**
 * ShopInnKart Admin - Shipping > Shipments.
 *
 * The fulfilment desk's list of every consignment, across every courier.
 *
 * Tiles and tabs are grouped by what an operator does NEXT, not by the thirteen
 * raw courier statuses, because nobody chases "pickup_scheduled" separately
 * from "booked" - both mean the parcel is still on our shelf:
 *
 *   Awaiting pickup    ready, booked, pickup_scheduled    still with us
 *   In transit         in_transit, failed                 with the courier; a
 *                                                         failed attempt is
 *                                                         retried next day
 *   Out for delivery   out_for_delivery
 *   Delivered          delivered
 *   RTO                rto_initiated, rto_delivered       coming, or came, back
 *   Cancelled/failed   cancelled, failed_booking          hold no slot on the order
 *
 * `pending` (a booking between our insert and the courier's reply) and
 * `returned` (a customer return) belong to no group. Each gets a tab of its own
 * only while a row sits in it: a pending row that never moved is a booking
 * that died mid-call, and filing it under "All" is how it would go unnoticed.
 *
 * ?status= takes a group key or a raw status, both checked against allowlists,
 * so a tile, a tab and a hand-typed ?status=booked all work and anything else
 * is ignored. ?pay=cod|prepaid is what the COD and Prepaid tiles link to.
 *
 * Read-only. Every action on a shipment lives on the order's shipping screen,
 * which goes through shipping-service.php; this page never writes a row.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('orders.view');

require_once INCLUDES_PATH . '/shipping-service.php';

$perPage = 25;
$listUrl = admin_url('shipping/shipments.php');

$groups = [
    'awaiting_pickup'  => ['label' => 'Awaiting pickup',    'icon' => 'box',          'tone' => 'amber',
                           'statuses' => ['ready', 'booked', 'pickup_scheduled']],
    'transit'          => ['label' => 'In transit',         'icon' => 'truck',        'tone' => 'blue',
                           'statuses' => ['in_transit', 'failed']],
    'out_for_delivery' => ['label' => 'Out for delivery',   'icon' => 'location',     'tone' => 'violet',
                           'statuses' => ['out_for_delivery']],
    'delivered'        => ['label' => 'Delivered',          'icon' => 'check-circle', 'tone' => 'green',
                           'statuses' => ['delivered']],
    'rto'              => ['label' => 'RTO',                'icon' => 'rotate',       'tone' => 'red',
                           'statuses' => ['rto_initiated', 'rto_delivered']],
    'cancelled_failed' => ['label' => 'Cancelled / failed', 'icon' => 'close',        'tone' => 'primary',
                           'statuses' => ['cancelled', 'failed_booking']],
];

$grouped   = array_merge(...array_column($groups, 'statuses'));
$ungrouped = array_values(array_diff(array_keys(shipping_statuses()), $grouped));

// ---------------------------------------------------------------------------
//  Request
// ---------------------------------------------------------------------------

// is_string, not a cast: ?q[]=x would otherwise become the word "Array" plus
// a warning in the page.
$query = static fn (string $key): string => is_string($_GET[$key] ?? null) ? trim($_GET[$key]) : '';

$statusKey = $query('status');
if (isset($groups[$statusKey])) {
    $filterStatuses = $groups[$statusKey]['statuses'];
} elseif (array_key_exists($statusKey, shipping_statuses())) {
    $filterStatuses = [$statusKey];
} else {
    $statusKey      = '';
    $filterStatuses = [];
}

$pay = in_array($query('pay'), ['cod', 'prepaid'], true) ? $query('pay') : '';
$q   = mb_substr($query('q'), 0, 100);

$isFiltered = $statusKey !== '' || $pay !== '' || $q !== '';

// ---------------------------------------------------------------------------
//  Counters: one grouped pass over the whole table
// ---------------------------------------------------------------------------

$statusTotals = [];
$grandTotal   = 0;
$codCount     = 0;
$codValue     = 0.0;
$prepaidCount = 0;
$chargeTotal  = 0.0;

$rows = Database::fetchAll(
    'SELECT `status`, `is_cod`, COUNT(*) AS n, SUM(`cod_amount`) AS cod_value, SUM(`shipping_charge`) AS charge
       FROM `shipments`
      GROUP BY `status`, `is_cod`'
);
foreach ($rows as $row) {
    $status = (string) $row['status'];
    $n      = (int) $row['n'];

    $statusTotals[$status] = ($statusTotals[$status] ?? 0) + $n;
    $grandTotal += $n;

    if ((int) $row['is_cod'] === 1) {
        $codCount += $n;
    } else {
        $prepaidCount += $n;
    }

    // Money leaves out consignments that never went anywhere. Cancel-and-rebook
    // is the normal way to change courier, and counting the cancelled leg too
    // would show the same order's COD - and its freight - twice.
    if (!shipping_is_dead($status)) {
        if ((int) $row['is_cod'] === 1) {
            $codValue += (float) $row['cod_value'];
        }
        $chargeTotal += (float) $row['charge'];
    }
}

$groupCount = static function (array $counts, array $statuses): int {
    return array_sum(array_map(static fn (string $s): int => (int) ($counts[$s] ?? 0), $statuses));
};

/** "AWB assigned: 2 · Pickup scheduled: 1" - which statuses a group tile is made of. */
$groupBreakdown = static function (array $statuses) use ($statusTotals): ?string {
    if (count($statuses) < 2) {
        return null;
    }
    $parts = [];
    foreach ($statuses as $status) {
        if (!empty($statusTotals[$status])) {
            $parts[] = shipping_status_label($status) . ': ' . number_format($statusTotals[$status]);
        }
    }
    return $parts === [] ? null : implode(' · ', $parts);
};

// ---------------------------------------------------------------------------
//  List
// ---------------------------------------------------------------------------

$from = ' FROM `shipments` s
          LEFT JOIN `orders` o ON o.`id` = s.`order_id`
          LEFT JOIN `shipping_providers` p ON p.`id` = s.`provider_id`';

// Search and payment type first: the tabs count within these, the same way
// the order list's tabs count within its filter bar.
$baseWhere  = [];
$baseParams = [];

if ($q !== '') {
    // Escape the LIKE wildcards so "50%" is a literal, not "starts with 50".
    $needle = '%' . addcslashes($q, '%_\\') . '%';
    // Native prepares: a named placeholder cannot be used twice, so each
    // column gets its own copy of the needle.
    $columns = [
        'q_awb'        => 's.`awb`',
        'q_order'      => 'o.`order_number`',
        'q_name'       => 'o.`customer_name`',
        'q_ship_name'  => 'o.`shipping_name`',
        'q_phone'      => 'o.`customer_phone`',
        'q_ship_phone' => 'o.`shipping_phone`',
        'q_courier'    => 's.`courier_name`',
        'q_provider'   => 'p.`name`',
    ];
    $like = [];
    foreach ($columns as $placeholder => $column) {
        $like[]                   = $column . ' LIKE :' . $placeholder;
        $baseParams[$placeholder] = $needle;
    }
    $baseWhere[] = '(' . implode(' OR ', $like) . ')';
}
if ($pay !== '') {
    $baseWhere[] = 's.`is_cod` = :is_cod';
    $baseParams['is_cod'] = $pay === 'cod' ? 1 : 0;
}

$where  = $baseWhere;
$params = $baseParams;
if ($filterStatuses !== []) {
    $in = [];
    foreach (array_values($filterStatuses) as $i => $status) {
        $in[]              = ':st' . $i;
        $params['st' . $i] = $status;
    }
    $where[] = 's.`status` IN (' . implode(', ', $in) . ')';
}

$baseSql  = $baseWhere === [] ? '' : ' WHERE ' . implode(' AND ', $baseWhere);
$whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

// Unfiltered, the tab counts ARE the tile counts; only a search or a payment
// filter needs a second pass.
$tabCounts = $baseWhere === []
    ? $statusTotals
    : array_map('intval', Database::fetchPairs(
        'SELECT s.`status`, COUNT(*)' . $from . $baseSql . ' GROUP BY s.`status`',
        $baseParams
    ));
$tabAll = array_sum($tabCounts);

$total      = (int) Database::fetchColumn('SELECT COUNT(*)' . $from . $whereSql, $params);
$pagination = paginate($total, $perPage, max(1, (int) $query('page')));

// PDO cannot bind LIMIT/OFFSET with emulated prepares off, so cast them here.
$limit  = (int) $pagination['per_page'];
$offset = (int) $pagination['offset'];

$shipments = Database::fetchAll(
    'SELECT s.`id`, s.`order_id`, s.`provider_code`, s.`awb`, s.`courier_name`, s.`status`, s.`status_detail`,
            s.`is_cod`, s.`cod_amount`, s.`weight_grams`, s.`created_at`,
            o.`order_number`, o.`customer_name`, p.`name` AS provider_name'
    . $from . $whereSql . '
      ORDER BY s.`created_at` DESC, s.`id` DESC
      LIMIT ' . $limit . ' OFFSET ' . $offset,
    $params
);

// Tabs for ungrouped statuses, only while something is in them - plus the raw
// status being filtered on, so a hand-typed ?status=booked is visibly the
// filter in force rather than a list with no tab selected.
$extraTabs = array_values(array_filter($ungrouped, static fn (string $s): bool => !empty($tabCounts[$s])));
if ($statusKey !== '' && !isset($groups[$statusKey]) && !in_array($statusKey, $extraTabs, true)) {
    $extraTabs[] = $statusKey;
}

$pageTitle    = 'Shipments';
$pageSubtitle = number_format($total) . ' shipment' . ($total === 1 ? '' : 's')
    . ($isFiltered ? ' in the current view.' : ' across every courier.');
$breadcrumbs  = [
    ['label' => 'Dashboard', 'url' => admin_url('dashboard.php')],
    ['label' => 'Shipping',  'url' => admin_url('shipping/')],
    ['label' => 'Shipments'],
];
$pageActions = '<a class="ad-btn" href="' . e(admin_url('shipping/')) . '">'
    . icon('settings', 'w-4 h-4') . ' Integrations</a>'
    . '<a class="ad-btn" href="' . e(admin_url('shipping/track.php')) . '">'
    . icon('location', 'w-4 h-4') . ' Tracking</a>';

require ADMIN_PATH . '/includes/header.php';
?>

<div class="ad-container">

    <!-- ============================== Tiles ============================== -->
    <?php
    // 4 + 3 + 3 rather than 7 in a 4-up grid, which would leave a hole in the
    // second row; the money row is kept apart because it measures a different
    // thing (payment type) from the status rows above it.
    $statusTiles = array_keys($groups);
    ?>
    <div class="ad-grid ad-grid--4" style="margin-bottom:18px">
        <?= admin_stat_card('Total shipments', number_format($grandTotal), 'package', 'navy', 'All time', $listUrl) ?>
        <?php foreach (array_slice($statusTiles, 0, 3) as $key): ?>
            <?= admin_stat_card(
                $groups[$key]['label'],
                number_format($groupCount($statusTotals, $groups[$key]['statuses'])),
                $groups[$key]['icon'],
                $groups[$key]['tone'],
                $groupBreakdown($groups[$key]['statuses']),
                $listUrl . '?status=' . urlencode($key)
            ) ?>
        <?php endforeach; ?>
    </div>

    <div class="ad-grid ad-grid--3" style="margin-bottom:18px">
        <?php foreach (array_slice($statusTiles, 3) as $key): ?>
            <?= admin_stat_card(
                $groups[$key]['label'],
                number_format($groupCount($statusTotals, $groups[$key]['statuses'])),
                $groups[$key]['icon'],
                $groups[$key]['tone'],
                $groupBreakdown($groups[$key]['statuses']),
                $listUrl . '?status=' . urlencode($key)
            ) ?>
        <?php endforeach; ?>
    </div>

    <div class="ad-grid ad-grid--3" style="margin-bottom:18px">
        <?= admin_stat_card('COD shipments', number_format($codCount), 'wallet', 'amber',
            money($codValue) . ' COD value; cancelled and failed bookings excluded', $listUrl . '?pay=cod') ?>
        <?= admin_stat_card('Prepaid shipments', number_format($prepaidCount), 'credit-card', 'green',
            'Paid before dispatch', $listUrl . '?pay=prepaid') ?>
        <?= admin_stat_card('Shipping cost', money($chargeTotal), 'tag', 'navy',
            'What couriers charged us; cancelled and failed bookings excluded') ?>
    </div>

    <div class="ad-card">

        <!-- ------------------------------ Tabs ------------------------------ -->
        <div class="ad-tabs">
            <a class="ad-tab <?= $statusKey === '' ? 'is-active' : '' ?>"
               href="<?= e(url_with(['status' => null, 'page' => null])) ?>">
                All <span class="ad-tab__count"><?= number_format($tabAll) ?></span>
            </a>
            <?php foreach ($groups as $key => $group): ?>
                <a class="ad-tab <?= $statusKey === $key ? 'is-active' : '' ?>"
                   href="<?= e(url_with(['status' => $key, 'page' => null])) ?>">
                    <?= e($group['label']) ?>
                    <span class="ad-tab__count"><?= number_format($groupCount($tabCounts, $group['statuses'])) ?></span>
                </a>
            <?php endforeach; ?>
            <?php foreach ($extraTabs as $status): ?>
                <a class="ad-tab <?= $statusKey === $status ? 'is-active' : '' ?>"
                   href="<?= e(url_with(['status' => $status, 'page' => null])) ?>">
                    <?= e(shipping_status_label($status)) ?>
                    <span class="ad-tab__count"><?= number_format((int) ($tabCounts[$status] ?? 0)) ?></span>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- ----------------------------- Filters ---------------------------- -->
        <form class="ad-filters" method="get" action="<?= e($listUrl) ?>">
            <?php if ($statusKey !== ''): ?>
                <input type="hidden" name="status" value="<?= e_attr($statusKey) ?>">
            <?php endif; ?>

            <span class="ad-search">
                <?= icon('search', 'w-4 h-4') ?>
                <label class="sik-sr" for="shipmentSearch">Search shipments</label>
                <input class="sik-input" type="search" id="shipmentSearch" name="q" data-filter-search
                       maxlength="100" value="<?= e_attr($q) ?>"
                       placeholder="AWB, order number, customer, phone or courier" autocomplete="off">
            </span>

            <label class="sik-sr" for="filterPay">Payment type</label>
            <select class="sik-select" id="filterPay" name="pay" data-auto-submit>
                <?= admin_options(['cod' => 'COD', 'prepaid' => 'Prepaid'], $pay, 'COD and prepaid') ?>
            </select>

            <button type="submit" class="ad-btn ad-btn--sm"><?= icon('search', 'w-4 h-4') ?> Search</button>
            <?php if ($isFiltered): ?>
                <a class="ad-btn ad-btn--sm" href="<?= e($listUrl) ?>">Reset</a>
            <?php endif; ?>
        </form>

        <!-- ------------------------------ Table ----------------------------- -->
        <div class="ad-card__body ad-card__body--flush">
            <?php if ($shipments === [] && $grandTotal === 0): ?>
                <?php $hasCourier = ShippingProviderFactory::available() !== []; ?>
                <?= admin_empty(
                    'No shipments yet',
                    $hasCourier
                        ? 'A consignment appears here as soon as an order is booked with a courier.'
                        : 'No courier is active yet. Set one up - the Mock Courier works without an account - then book an order.',
                    $hasCourier ? null : 'Set up a courier',
                    $hasCourier ? null : admin_url('shipping/'),
                    'truck'
                ) ?>
            <?php elseif ($shipments === []): ?>
                <?= admin_empty(
                    'No shipments match',
                    $q !== ''
                        ? 'Nothing in this view matches "' . $q . '". Check the AWB or order number, or clear the search.'
                        : 'No shipment is in this view right now. Try another tab, or clear the filters.',
                    'Clear filters',
                    $listUrl,
                    'search'
                ) ?>
            <?php else: ?>
                <div class="ad-tablewrap">
                    <table class="ad-table">
                        <thead>
                            <tr>
                                <th>Order</th>
                                <th>AWB</th>
                                <th>Courier</th>
                                <th>Status</th>
                                <th class="ad-table__num">COD</th>
                                <th class="ad-table__num">Weight</th>
                                <th>Booked</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($shipments as $row): ?>
                                <?php
                                $orderId = (int) $row['order_id'];
                                $awb     = (string) ($row['awb'] ?? '');
                                $status  = (string) $row['status'];
                                $tone    = shipping_status_tone($status);
                                $detail  = trim((string) ($row['status_detail'] ?? ''));
                                $grams   = (int) ($row['weight_grams'] ?? 0);
                                ?>
                                <tr>
                                    <td>
                                        <?php if ($row['order_number'] !== null): ?>
                                            <a class="ad-mono" style="font-weight:700"
                                               href="<?= e(admin_url('shipping/book.php?order=' . $orderId)) ?>">
                                                <?= e((string) $row['order_number']) ?>
                                            </a>
                                            <div class="ad-cellflex__meta"><?= e((string) $row['customer_name']) ?></div>
                                        <?php else: ?>
                                            <?php // The order was deleted; the consignment still happened. ?>
                                            <span class="ad-mono">#<?= $orderId ?></span>
                                            <div class="ad-cellflex__meta">Order no longer exists</div>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <?php if ($awb !== ''): ?>
                                            <div class="ad-cellflex">
                                                <span class="ad-mono"><?= e($awb) ?></span>
                                                <button type="button" class="ad-btn ad-btn--icon ad-btn--sm"
                                                        data-copy="<?= e_attr($awb) ?>"
                                                        title="Copy AWB" aria-label="Copy AWB <?= e_attr($awb) ?>">
                                                    <?= icon('copy', 'w-3.5 h-3.5') ?>
                                                </button>
                                            </div>
                                        <?php else: ?>
                                            <span class="ad-muted">Not assigned</span>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <div class="ad-cellflex__name"><?= e((string) ($row['courier_name'] ?: '—')) ?></div>
                                        <div class="ad-cellflex__meta">
                                            <?= e((string) ($row['provider_name'] ?: $row['provider_code'])) ?>
                                        </div>
                                    </td>

                                    <td>
                                        <span class="sik-status sik-status--<?= e_attr($tone) ?>">
                                            <?= e(shipping_status_label($status)) ?>
                                        </span>
                                        <?php
                                        // The courier's own words, but only where something needs a
                                        // look: an AWB refusal on a `ready` row, a failed attempt, an
                                        // RTO reason. On a happy row it is just noise - and so is a
                                        // detail that only repeats the badge ("delivered" under
                                        // "RTO delivered").
                                        $label = shipping_status_label($status);
                                        if ($detail !== '' && in_array($tone, ['amber', 'red'], true)
                                            && mb_stripos($label, $detail) === false):
                                        ?>
                                            <div class="ad-cellflex__meta" title="<?= e_attr($detail) ?>">
                                                <?= e(mb_strimwidth($detail, 0, 90, '…')) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>

                                    <td class="ad-table__num">
                                        <?php if ((int) $row['is_cod'] === 1): ?>
                                            <strong><?= e(money((float) $row['cod_amount'])) ?></strong>
                                        <?php else: ?>
                                            <span class="ad-muted">Prepaid</span>
                                        <?php endif; ?>
                                    </td>

                                    <td class="ad-table__num">
                                        <?= $grams > 0 ? e(number_format($grams / 1000, 2) . ' kg') : '<span class="ad-muted">—</span>' ?>
                                    </td>

                                    <td class="ad-muted" style="white-space:nowrap">
                                        <?= e(format_date((string) $row['created_at'], 'd M Y')) ?><br>
                                        <span class="ad-cellflex__meta"><?= e(format_date((string) $row['created_at'], 'g:i A')) ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($pagination['last'] > 1): ?>
            <div class="ad-card__foot" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
                <span class="ad-muted" style="font-size:12.5px">
                    Showing <?= number_format($pagination['from']) ?>&ndash;<?= number_format($pagination['to']) ?>
                    of <?= number_format($pagination['total']) ?>
                </span>
                <?= admin_pagination($pagination, $listUrl) ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
