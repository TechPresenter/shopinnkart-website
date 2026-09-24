<?php
/**
 * ShopInnKart Admin - Shipping > Returns & RTO.
 *
 * Everything coming back, in one place, with the money question attached to it.
 *
 * There are two Returns screens in this admin and they answer different
 * questions on purpose:
 *
 *   orders/returns.php    the DECISION desk. Should this customer be allowed to
 *                         return the item, and is the order now Returned or
 *                         Refunded? It moves order status; it knows nothing
 *                         about couriers.
 *   this screen           the LOGISTICS desk. What is physically travelling back
 *                         to the warehouse right now, what is already here, and
 *                         what does each of those still owe us or the customer?
 *
 * Three things end up here, and they are not the same:
 *
 *   RTO in flight     a forward consignment the courier turned round. No second
 *                     AWB, nobody asked for it, and the goods are still out.
 *   RTO delivered     ...and now back on our dock.
 *   return pickup     a reverse consignment WE booked to collect from the
 *                     customer (shipments.direction = 'return'), with its own
 *                     AWB and its own scans.
 *
 * Plus the queue that has none of those yet: an approved customer request with
 * no courier booked. That is the one tab that is a list of work, not a list of
 * parcels.
 *
 * WHAT IS OWED is computed by shipping_return_money() - never re-derived here -
 * and it asks the three questions an owner actually has:
 *
 *   stock not back      the units are still counted as sold. Stock goes back
 *                       exactly once, when the ORDER first enters a releasing
 *                       status, so the order status is the answer and the
 *                       courier's is not.
 *   COD never collected cash the courier was told to collect and did not. An RTO
 *                       of a COD parcel is the whole order value that never came.
 *   refund not made     money we DID take and have not given back. Never shown
 *                       for a COD parcel that came back: nothing was ever paid.
 *
 * Every action posts back here and goes through shipping-service.php, which is
 * where the rules and the order moves live. This file never writes orders.status
 * and never calls a driver: "Settle" re-reads the stored timeline and lets the
 * engine finish an order a failed call left behind, which is the repair path
 * for "the parcel is back but the stock never went on the shelf".
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('orders.view');

require_once INCLUDES_PATH . '/shipping-service.php';

$listUrl = admin_url('shipping/returns.php');
$canEdit = admin_can('orders.edit');
$perPage = 25;

/** The statuses that mean a forward consignment turned round. */
const RETURNS_RTO_TRANSIT = ['rto_initiated'];
const RETURNS_RTO_HOME    = ['rto_delivered', 'returned'];

// ---------------------------------------------------------------------------
//  Actions - every one of them a service call
// ---------------------------------------------------------------------------

if (is_post()) {
    admin_require_action('orders.edit');   // POST + CSRF + permission

    /** A posted field as a string; an array smuggled in under the same name reads as empty. */
    $text = static function (string $key): string {
        $value = input($key, '');
        return is_string($value) ? trim($value) : '';
    };

    $action     = $text('action');
    $shipmentId = input_int('shipment_id');
    $back       = admin_safe_return($text('return'), $listUrl);

    $shipment = shipment_get($shipmentId);
    if ($shipment === null) {
        flash('error', 'That consignment no longer exists.');
        redirect($back);
    }

    switch ($action) {
        case 'book_return':
            // One line, bounded: it becomes the consignment's reason and the
            // note on the order when the parcel arrives back.
            $reason = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $text('reason'))), 0, 200);
            $result = shipping_book_return($shipmentId, [
                'reason'            => $reason,
                'return_request_id' => input_int('return_request_id'),
            ]);
            break;

        case 'refresh':
            // Knows which leg it is on: a return consignment is asked with
            // checkReturnStatus(), a forward one with trackShipment().
            $result = shipping_refresh_tracking($shipmentId);
            break;

        case 'cancel':
            $reason = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $text('reason'))), 0, 200);
            $result = shipping_cancel($shipmentId, $reason);
            break;

        case 'settle':
            // No courier call at all: re-read the stored timeline and let the
            // engine move the order to match it. This is what finishes an
            // order whose "parcel is back" update committed the shipment and
            // then failed on the order - the case where the stock is still
            // counted as sold with nothing retrying it.
            try {
                shipping_record_events($shipmentId, [], 'manual');
                $result = ['ok' => true, 'message' => 'Re-read the courier timeline and brought the order into line.'];
            } catch (Throwable $e) {
                ErrorHandler::log('error', 'Shipping: settle from the returns desk failed for #' . $shipmentId . ': ' . $e->getMessage());
                $result = shipping_fail('The order could not be brought into line just now. Try again in a moment.');
            }
            break;

        default:
            flash('error', 'Unknown returns action.');
            redirect($back);
    }

    $ok = !empty($result['ok']);
    if ($ok) {
        // A return moves stock and status, and the storefront's order page and
        // the sidebar counts both read them.
        admin_after_write();
        // The milestone mail the service queued goes out on the next drain;
        // nudging a few here means the customer hears about a pickup booked
        // from this screen while the admin is still looking at it.
        process_notification_queue(3);
    }

    $message = trim((string) ($result['message'] ?? ''));
    flash($ok ? 'success' : 'error', $message !== '' ? $message : ($ok ? 'Done.' : 'The courier refused without saying why.'));
    redirect($back);
}

// ---------------------------------------------------------------------------
//  Request
// ---------------------------------------------------------------------------

// is_string, not a cast: ?q[]=x would otherwise become the word "Array" plus
// a warning in the page.
$query = static fn (string $key): string => is_string($_GET[$key] ?? null) ? trim($_GET[$key]) : '';

$views = [
    ''            => ['label' => 'Everything coming back', 'icon' => 'rotate'],
    'rto_transit' => ['label' => 'RTO in flight',          'icon' => 'truck'],
    'rto_home'    => ['label' => 'RTO delivered',          'icon' => 'box'],
    'pickup'      => ['label' => 'Return pickups',         'icon' => 'refresh'],
    'received'    => ['label' => 'Received back',          'icon' => 'check-circle'],
    'owed'        => ['label' => 'Money outstanding',      'icon' => 'wallet'],
    'to_book'     => ['label' => 'Approved, not booked',   'icon' => 'clock'],
];

// Checked against the allowlist, so ?view=../../etc is simply the default view
// rather than anything at all.
$view = $query('view');
if (!array_key_exists($view, $views)) {
    $view = '';
}
$q = mb_substr($query('q'), 0, 100);
$isFiltered = $view !== '' || $q !== '';

$returnNow = (string) parse_url($listUrl, PHP_URL_PATH)
    . (($qs = (is_string($_SERVER['QUERY_STRING'] ?? null) ? (string) $_SERVER['QUERY_STRING'] : '')) !== '' ? '?' . $qs : '');

// ---------------------------------------------------------------------------
//  Shared SQL fragments
// ---------------------------------------------------------------------------

[$rtoTransitIn, $rtoTransitParams] = Database::inPlaceholders(RETURNS_RTO_TRANSIT, 'rt');
[$rtoHomeIn,    $rtoHomeParams]    = Database::inPlaceholders(RETURNS_RTO_HOME, 'rh');
[$releasingIn,  $releasingParams]  = Database::inPlaceholders(STOCK_RELEASING_STATUSES, 'rel');
[$settledIn,    $settledParams]    = Database::inPlaceholders(PAYMENT_STATUSES_SETTLED, 'set');
[$paidIn,       $paidParams]       = Database::inPlaceholders([PAYMENT_STATUS_PAID, PAYMENT_STATUS_PARTIALLY_REFUNDED], 'pd');

// "Coming back" is a forward consignment that turned round, or any reverse
// consignment that is not a dead booking.
$comingBack = "((s.`direction` = 'forward' AND s.`status` IN ($rtoTransitIn, $rtoHomeIn))
                OR (s.`direction` = 'return' AND s.`status` NOT IN ('cancelled','failed_booking')))";
$comingBackParams = $rtoTransitParams + $rtoHomeParams;

// Back on our dock: the leg that carries the goods has arrived.
$isHome = "((s.`direction` = 'forward' AND s.`status` IN ($rtoHomeIn))
            OR (s.`direction` = 'return' AND s.`status` = 'returned'))";

$from = ' FROM `shipments` s
          JOIN `orders` o ON o.`id` = s.`order_id`
          LEFT JOIN `shipping_providers` p ON p.`id` = s.`provider_id`
          LEFT JOIN `return_requests` r ON r.`id` = s.`return_request_id`';

// ---------------------------------------------------------------------------
//  Tiles: what is out, what is here, and what it costs
// ---------------------------------------------------------------------------

$tile = static function (string $where, array $params, string $sum = 'COUNT(*)') use ($from) {
    try {
        return Database::fetchColumn('SELECT COALESCE(' . $sum . ', 0)' . $from . ' WHERE ' . $where, $params);
    } catch (Throwable $e) {
        ErrorHandler::log('warning', 'Returns desk tile failed: ' . $e->getMessage());
        return 0;
    }
};

$rtoTransitCount = (int) $tile("s.`direction` = 'forward' AND s.`status` IN ($rtoTransitIn)", $rtoTransitParams);
$rtoHomeCount    = (int) $tile("s.`direction` = 'forward' AND s.`status` IN ($rtoHomeIn)", $rtoHomeParams);
$pickupCount     = (int) $tile("s.`direction` = 'return' AND s.`status` NOT IN ('returned','cancelled','failed_booking')", []);
$receivedCount   = (int) $tile("s.`direction` = 'return' AND s.`status` = 'returned'", []);

// Stock still counted as sold although the parcel is on our dock. The ORDER
// status is the evidence, because that is what releases it.
$stockOut = (int) $tile($isHome . " AND o.`status` NOT IN ($releasingIn)", $rtoHomeParams + $releasingParams);

// COD the courier was told to collect and never did. Only the forward leg
// carries a COD; a reverse pickup collects goods, not cash.
$codLost = (float) $tile(
    "s.`direction` = 'forward' AND s.`is_cod` = 1 AND s.`status` IN ($rtoTransitIn, $rtoHomeIn)
       AND o.`payment_status` NOT IN ($settledIn)",
    $rtoTransitParams + $rtoHomeParams + $settledParams,
    'SUM(s.`cod_amount`)'
);

// Money we DID take on a parcel that is back, and have not given back.
// DISTINCT on the order: an order with an RTO leg and a return leg would
// otherwise be counted twice.
//
// Minus what has already gone back, the same rule shipping_return_money()
// applies to each row: a partially refunded order still owes the rest, not the
// whole total over again, and a tile that says otherwise is the figure an
// admin refunds from.
$refundOwed = 0.0;
try {
    $refundOwed = (float) Database::fetchColumn(
        'SELECT COALESCE(SUM(GREATEST(t.`total_amount` - t.`refunded`, 0)), 0) FROM (
            SELECT DISTINCT o.`id`, o.`total_amount`,
                   COALESCE((SELECT SUM(pr.`amount`) FROM `payment_refunds` pr
                              WHERE pr.`order_id` = o.`id`), 0) AS `refunded`' . $from . '
             WHERE ' . $isHome . " AND o.`payment_status` IN ($paidIn)) t",
        $rtoHomeParams + $paidParams
    );
} catch (Throwable $e) {
    ErrorHandler::log('warning', 'Returns desk refund tile failed: ' . $e->getMessage());
}

// The work queue: an approved customer request with no courier booked for it.
$toBook = [];
try {
    $toBook = Database::fetchAll(
        "SELECT r.*, o.`status` AS order_status, o.`payment_status`, o.`total_amount`,
                (SELECT x.`id` FROM `shipments` x
                  WHERE x.`order_id` = r.`order_id` AND x.`direction` = 'forward' AND x.`status` = 'delivered'
                  ORDER BY x.`id` DESC LIMIT 1) AS forward_id
           FROM `return_requests` r
           JOIN `orders` o ON o.`id` = r.`order_id`
          WHERE r.`status` = :approved
            AND NOT EXISTS (SELECT 1 FROM `shipments` y
                             WHERE y.`order_id` = r.`order_id` AND y.`direction` = 'return'
                               AND y.`status` NOT IN ('cancelled','failed_booking'))
          ORDER BY r.`decided_at` IS NULL, r.`decided_at` ASC, r.`id` ASC
          LIMIT 50",
        ['approved' => RETURN_STATUS_APPROVED]
    );
} catch (Throwable $e) {
    ErrorHandler::log('warning', 'Returns desk queue failed: ' . $e->getMessage());
}

// ---------------------------------------------------------------------------
//  The list
// ---------------------------------------------------------------------------

$where  = [$comingBack];
$params = $comingBackParams;

if ($q !== '') {
    // Escape the LIKE wildcards so "50%" is a literal, not "starts with 50".
    $needle = '%' . addcslashes($q, '%_\\') . '%';
    // Native prepares: a named placeholder cannot be used twice, so each
    // column gets its own copy of the needle.
    $columns = [
        'q_awb'      => 's.`awb`',
        'q_order'    => 'o.`order_number`',
        'q_name'     => 'o.`customer_name`',
        'q_email'    => 'o.`customer_email`',
        'q_phone'    => 'o.`customer_phone`',
        'q_courier'  => 's.`courier_name`',
    ];
    $like = [];
    foreach ($columns as $placeholder => $column) {
        $like[]             = $column . ' LIKE :' . $placeholder;
        $params[$placeholder] = $needle;
    }
    $where[] = '(' . implode(' OR ', $like) . ')';
}

// Native prepares: a named placeholder cannot be used twice in one statement,
// and the base "coming back" clause already holds these lists. Each view filter
// gets its own copy - reusing :rt0 threw, and the catch below turned the whole
// tab into an empty list with the reason only in the log.
[$viewTransitIn, $viewTransitParams] = Database::inPlaceholders(RETURNS_RTO_TRANSIT, 'vrt');
[$viewHomeIn,    $viewHomeParams]    = Database::inPlaceholders(RETURNS_RTO_HOME, 'vrh');

switch ($view) {
    case 'rto_transit':
        $where[] = "s.`direction` = 'forward' AND s.`status` IN ($viewTransitIn)";
        $params += $viewTransitParams;
        break;
    case 'rto_home':
        $where[] = "s.`direction` = 'forward' AND s.`status` IN ($viewHomeIn)";
        $params += $viewHomeParams;
        break;
    case 'pickup':
        $where[] = "s.`direction` = 'return' AND s.`status` NOT IN ('returned','cancelled','failed_booking')";
        break;
    case 'received':
        $where[] = "s.`direction` = 'return' AND s.`status` = 'returned'";
        break;
    case 'owed':
        // Anything with a question still open against it. The exact figures
        // come from shipping_return_money() per row; this is the filter that
        // gets those rows onto the page.
        $where[] = "(o.`status` NOT IN ($releasingIn)
                     OR (s.`direction` = 'forward' AND s.`is_cod` = 1 AND o.`payment_status` NOT IN ($settledIn))
                     OR o.`payment_status` IN ($paidIn))";
        $params += $releasingParams + $settledParams + $paidParams;
        break;
}

$whereSql = ' WHERE ' . implode(' AND ', $where);

$total     = 0;
$rows      = [];
// A query that throws must not read as "nothing is coming back": that is how a
// duplicate placeholder turned a whole tab into an empty list with the reason
// only in the log.
$listError = false;
if ($view !== 'to_book') {
    try {
        $total      = (int) Database::fetchColumn('SELECT COUNT(*)' . $from . $whereSql, $params);
        $pagination = paginate($total, $perPage, max(1, (int) $query('page')));
        // PDO cannot bind LIMIT/OFFSET with emulated prepares off, so cast them here.
        $limit  = (int) $pagination['per_page'];
        $offset = (int) $pagination['offset'];

        $rows = Database::fetchAll(
            'SELECT s.`id`, s.`order_id`, s.`direction`, s.`parent_shipment_id`, s.`awb`, s.`courier_name`,
                    s.`provider_code`, s.`status`, s.`status_detail`, s.`is_cod`, s.`cod_amount`,
                    s.`return_reason`, s.`created_at`, s.`updated_at`,
                    o.`order_number`, o.`customer_name`, o.`customer_email`, o.`status` AS order_status,
                    o.`payment_status`, o.`payment_method`, o.`total_amount`,
                    p.`name` AS provider_name, r.`type` AS request_type, r.`status` AS request_status'
            . $from . $whereSql . '
              ORDER BY s.`updated_at` DESC, s.`id` DESC
              LIMIT ' . $limit . ' OFFSET ' . $offset,
            $params
        );
    } catch (Throwable $e) {
        ErrorHandler::log('error', 'Returns desk list failed: ' . $e->getMessage());
        $pagination = paginate(0, $perPage, 1);
        $listError  = true;
    }
} else {
    $pagination = paginate(count($toBook), $perPage, 1);
}

// The money line per row, from the one function that knows the rules.
$money = [];
foreach ($rows as $row) {
    $orderId = (int) $row['order_id'];
    if (!isset($money[$orderId])) {
        $money[$orderId] = shipping_return_money($orderId);
    }
}

$pageTitle    = 'Returns & RTO';
$pageSubtitle = $view === 'to_book'
    ? count($toBook) . ' approved request' . (count($toBook) === 1 ? '' : 's') . ' with no courier booked.'
    : number_format($total) . ' consignment' . ($total === 1 ? '' : 's')
        . ($isFiltered ? ' in the current view.' : ' coming back or already back.');
$breadcrumbs  = [
    ['label' => 'Dashboard', 'url' => admin_url('dashboard.php')],
    ['label' => 'Shipping',  'url' => admin_url('shipping/shipments.php')],
    ['label' => 'Returns & RTO'],
];
$pageActions = '<a class="ad-btn" href="' . e(admin_url('shipping/shipments.php')) . '">'
        . icon('truck', 'w-4 h-4') . ' All shipments</a>'
    . '<a class="ad-btn" href="' . e(admin_url('orders/returns.php')) . '">'
        . icon('undo', 'w-4 h-4') . ' Return requests</a>';

require ADMIN_PATH . '/includes/header.php';
?>

<div class="ad-container">

    <!-- ============================== Tiles ============================== -->
    <div class="ad-grid ad-grid--4" style="margin-bottom:18px">
        <?= admin_stat_card('RTO in flight', number_format($rtoTransitCount), 'truck', 'amber',
            'Turned round by the courier; goods still out', $listUrl . '?view=rto_transit') ?>
        <?= admin_stat_card('RTO delivered', number_format($rtoHomeCount), 'box', 'red',
            'Back on our dock', $listUrl . '?view=rto_home') ?>
        <?= admin_stat_card('Return pickups', number_format($pickupCount), 'refresh', 'blue',
            'Booked with a courier, not yet here', $listUrl . '?view=pickup') ?>
        <?= admin_stat_card('Received back', number_format($receivedCount), 'check-circle', 'green',
            'Customer returns checked in', $listUrl . '?view=received') ?>
    </div>

    <div class="ad-grid ad-grid--3" style="margin-bottom:18px">
        <?= admin_stat_card('Stock not put back', number_format($stockOut), 'package',
            $stockOut > 0 ? 'red' : 'navy',
            $stockOut > 0
                ? 'Parcels on our dock whose units are still counted as sold - use Settle'
                : 'Every parcel that is back has been restocked',
            $stockOut > 0 ? $listUrl . '?view=owed' : null) ?>
        <?= admin_stat_card('COD never collected', money($codLost), 'wallet', $codLost > 0 ? 'amber' : 'navy',
            'Cash the courier was told to collect on parcels that came back') ?>
        <?= admin_stat_card('Refunds owed', money($refundOwed), 'credit-card', $refundOwed > 0 ? 'amber' : 'navy',
            'Money already taken on parcels that are back with us',
            $refundOwed > 0 ? admin_url('orders/returns.php') : null) ?>
    </div>

    <div class="ad-card">

        <!-- ------------------------------ Tabs ------------------------------ -->
        <div class="ad-tabs">
            <?php foreach ($views as $key => $meta): ?>
                <a class="ad-tab <?= $view === $key ? 'is-active' : '' ?>"
                   href="<?= e(url_with(['view' => $key === '' ? null : $key, 'page' => null], $listUrl)) ?>">
                    <?= e($meta['label']) ?>
                    <?php
                    $count = [
                        'rto_transit' => $rtoTransitCount,
                        'rto_home'    => $rtoHomeCount,
                        'pickup'      => $pickupCount,
                        'received'    => $receivedCount,
                        'to_book'     => count($toBook),
                    ][$key] ?? null;
                    ?>
                    <?php if ($count !== null): ?>
                        <span class="ad-tab__count"><?= number_format($count) ?></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- ----------------------------- Filters ---------------------------- -->
        <?php if ($view !== 'to_book'): ?>
            <form class="ad-filters" method="get" action="<?= e($listUrl) ?>">
                <?php if ($view !== ''): ?>
                    <input type="hidden" name="view" value="<?= e_attr($view) ?>">
                <?php endif; ?>
                <span class="ad-search">
                    <?= icon('search', 'w-4 h-4') ?>
                    <label class="sik-sr" for="returnSearch">Search returns</label>
                    <input class="sik-input" type="search" id="returnSearch" name="q" data-filter-search
                           maxlength="100" value="<?= e_attr($q) ?>"
                           placeholder="AWB, order number, customer, email or courier" autocomplete="off">
                </span>
                <button type="submit" class="ad-btn ad-btn--sm"><?= icon('search', 'w-4 h-4') ?> Search</button>
                <?php if ($isFiltered): ?>
                    <a class="ad-btn ad-btn--sm" href="<?= e($listUrl) ?>">Reset</a>
                <?php endif; ?>
            </form>
        <?php endif; ?>

        <div class="ad-card__body ad-card__body--flush">

        <?php if ($view === 'to_book'): ?>
            <!-- ------------------- Approved, not booked ---------------------- -->
            <?php if ($toBook === []): ?>
                <?= admin_empty(
                    'Nothing waiting for a courier',
                    'Every approved return has a pickup booked against it. Approvals happen on the Return requests screen.',
                    'Return requests',
                    admin_url('orders/returns.php'),
                    'check-circle'
                ) ?>
            <?php else: ?>
                <div class="ad-tablewrap">
                    <table class="ad-table">
                        <thead>
                            <tr>
                                <th>Order</th>
                                <th>Request</th>
                                <th>Approved</th>
                                <th>Value</th>
                                <th class="ad-table__actions">Book the pickup</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($toBook as $request): ?>
                                <?php $forwardId = (int) ($request['forward_id'] ?? 0); ?>
                                <tr>
                                    <td>
                                        <a class="ad-mono" style="font-weight:700"
                                           href="<?= e(admin_url('orders/view.php?id=' . (int) $request['order_id'])) ?>">
                                            <?= e((string) $request['order_number']) ?>
                                        </a>
                                        <div class="ad-cellflex__meta"><?= e((string) $request['customer_name']) ?></div>
                                    </td>
                                    <td>
                                        <span class="sik-status sik-status--blue"><?= e(ucfirst((string) $request['type'])) ?></span>
                                        <div class="ad-cellflex__meta"><?= e(return_reason_label((string) $request['reason'])) ?></div>
                                    </td>
                                    <td class="ad-nowrap">
                                        <?= $request['decided_at'] !== null ? e(format_date((string) $request['decided_at'])) : '<span class="ad-muted">—</span>' ?>
                                    </td>
                                    <td class="ad-table__num"><?= e(money((float) $request['total_amount'])) ?></td>
                                    <td class="ad-table__actions">
                                        <?php if (!$canEdit): ?>
                                            <span class="ad-muted">View only</span>
                                        <?php elseif ($forwardId === 0): ?>
                                            <?php // No delivered consignment means no parcel to collect back
                                                  // through the hub - the customer is couriering it themselves,
                                                  // or it went out before the hub existed. ?>
                                            <span class="ad-muted">No delivered consignment to collect from</span>
                                        <?php else: ?>
                                            <form method="post" action="<?= e($listUrl) ?>" class="ad-inline-form">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="book_return">
                                                <input type="hidden" name="shipment_id" value="<?= $forwardId ?>">
                                                <input type="hidden" name="return_request_id" value="<?= (int) $request['id'] ?>">
                                                <input type="hidden" name="return" value="<?= e_attr($returnNow) ?>">
                                                <button type="submit" class="ad-btn ad-btn--sm ad-btn--primary">
                                                    <?= icon('refresh', 'w-4 h-4') ?> Book pickup
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

        <?php elseif ($listError): ?>
            <?= admin_empty(
                'This list could not be read',
                'Something went wrong building this view, so nothing is shown rather than an empty list that would '
                    . 'read as "nothing is coming back". The reason is in the error log. Try another tab.',
                'Everything coming back',
                $listUrl,
                'info'
            ) ?>

        <?php elseif ($rows === []): ?>
            <?= admin_empty(
                $isFiltered ? 'Nothing in this view' : 'Nothing is coming back',
                $isFiltered
                    ? 'No consignment matches this view right now. Try another tab, or clear the search.'
                    : 'No parcel has been turned round by a courier and no return pickup is booked. '
                        . 'This fills itself as the couriers report.',
                $isFiltered ? 'Clear filters' : null,
                $isFiltered ? $listUrl : null,
                'rotate'
            ) ?>

        <?php else: ?>
            <div class="ad-tablewrap">
                <table class="ad-table">
                    <thead>
                        <tr>
                            <th>Order</th>
                            <th>Leg</th>
                            <th>AWB &amp; courier</th>
                            <th>Status</th>
                            <th>What is owed</th>
                            <th>Last update</th>
                            <th class="ad-table__actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row): ?>
                            <?php
                            $shipmentId = (int) $row['id'];
                            $orderId    = (int) $row['order_id'];
                            $status     = (string) $row['status'];
                            $awb        = trim((string) ($row['awb'] ?? ''));
                            $isReturn   = (string) $row['direction'] === 'return';
                            $owed       = $money[$orderId] ?? null;
                            $reason     = trim((string) ($row['return_reason'] ?? ''));
                            $detail     = trim((string) ($row['status_detail'] ?? ''));
                            // Home means the goods are here: only then is "stock
                            // not back" a problem rather than simply not yet true.
                            $isHomeRow  = $isReturn ? $status === 'returned' : in_array($status, RETURNS_RTO_HOME, true);
                            ?>
                            <tr>
                                <td>
                                    <a class="ad-mono" style="font-weight:700"
                                       href="<?= e(admin_url('orders/view.php?id=' . $orderId)) ?>">
                                        <?= e((string) $row['order_number']) ?>
                                    </a>
                                    <div class="ad-cellflex__meta"><?= e((string) $row['customer_name']) ?></div>
                                    <div class="ad-cellflex__meta">
                                        <?= e(ORDER_STATUSES[(string) $row['order_status']] ?? (string) $row['order_status']) ?>
                                        &middot; <?= e(strtoupper((string) $row['payment_method'])) ?>
                                    </div>
                                </td>

                                <td>
                                    <?php if ($isReturn): ?>
                                        <span class="sik-status sik-status--blue">Return pickup</span>
                                        <div class="ad-cellflex__meta">
                                            <?= $row['request_type'] !== null
                                                ? e('Customer ' . (string) $row['request_type'])
                                                : 'Booked by us' ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="sik-status sik-status--red">RTO</span>
                                        <div class="ad-cellflex__meta">Courier turned it round</div>
                                    <?php endif; ?>
                                    <?php if ($reason !== ''): ?>
                                        <div class="ad-cellflex__meta"><?= e($reason) ?></div>
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
                                    <div class="ad-cellflex__meta">
                                        <?= e((string) ($row['courier_name'] ?: ($row['provider_name'] ?: $row['provider_code']))) ?>
                                    </div>
                                </td>

                                <td>
                                    <span class="sik-status sik-status--<?= e_attr(shipping_status_tone($status)) ?>">
                                        <?= e(shipping_status_label($status)) ?>
                                    </span>
                                    <?php
                                    // The courier's own words, but only where they add something
                                    // the badge does not already say.
                                    $label = shipping_status_label($status);
                                    if ($detail !== '' && mb_stripos($label, $detail) === false): ?>
                                        <div class="ad-cellflex__meta"><?= e($detail) ?></div>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?php if ($owed === null): ?>
                                        <span class="ad-muted">—</span>
                                    <?php else: ?>
                                        <?php if ($isHomeRow && !$owed['stock_back']): ?>
                                            <div><span class="sik-status sik-status--red">Stock not back</span></div>
                                        <?php elseif ($owed['stock_back']): ?>
                                            <div class="ad-cellflex__meta"><?= icon('check', 'w-3.5 h-3.5') ?> Stock restored</div>
                                        <?php endif; ?>

                                        <?php if ($owed['cod_uncollected'] > 0): ?>
                                            <div class="ad-cellflex__meta">
                                                COD never collected: <strong><?= e(money($owed['cod_uncollected'])) ?></strong>
                                            </div>
                                        <?php endif; ?>

                                        <?php if ($owed['refund_done']): ?>
                                            <div class="ad-cellflex__meta"><?= icon('check', 'w-3.5 h-3.5') ?> Refund made</div>
                                        <?php elseif ($owed['refund_due'] > 0): ?>
                                            <div class="ad-cellflex__meta">
                                                Refund owed: <strong><?= e(money($owed['refund_due'])) ?></strong>
                                            </div>
                                        <?php endif; ?>

                                        <?php if ($owed['cod_uncollected'] <= 0 && $owed['refund_due'] <= 0
                                                  && !$owed['refund_done'] && $owed['stock_back']): ?>
                                            <div class="ad-cellflex__meta">Nothing outstanding</div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>

                                <td class="ad-nowrap">
                                    <?= e(format_date((string) $row['updated_at'])) ?>
                                    <div class="ad-cellflex__meta">Booked <?= e(format_date((string) $row['created_at'])) ?></div>
                                </td>

                                <td class="ad-table__actions">
                                    <?php if (!$canEdit): ?>
                                        <span class="ad-muted">View only</span>
                                    <?php else: ?>
                                        <?php if (!shipping_is_terminal($status)): ?>
                                            <form method="post" action="<?= e($listUrl) ?>" class="ad-inline-form">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="refresh">
                                                <input type="hidden" name="shipment_id" value="<?= $shipmentId ?>">
                                                <input type="hidden" name="return" value="<?= e_attr($returnNow) ?>">
                                                <button type="submit" class="ad-btn ad-btn--sm"
                                                        title="Ask the courier where this parcel is">
                                                    <?= icon('refresh', 'w-4 h-4') ?> Refresh
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                        <?php if ($isHomeRow && $owed !== null && !$owed['stock_back']): ?>
                                            <form method="post" action="<?= e($listUrl) ?>" class="ad-inline-form">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="settle">
                                                <input type="hidden" name="shipment_id" value="<?= $shipmentId ?>">
                                                <input type="hidden" name="return" value="<?= e_attr($returnNow) ?>">
                                                <button type="submit" class="ad-btn ad-btn--sm ad-btn--primary"
                                                        title="Re-read the courier timeline and put the stock back">
                                                    <?= icon('undo', 'w-4 h-4') ?> Settle
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                        <?php if ($isReturn && shipping_is_cancellable($status)): ?>
                                            <form method="post" action="<?= e($listUrl) ?>" class="ad-inline-form"
                                                  <?= admin_confirm_attrs('The courier is told to stand down. The return stays open and can be booked again.', ['title' => 'Cancel this pickup?', 'label' => 'Cancel pickup', 'tone' => 'danger']) ?>>
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="cancel">
                                                <input type="hidden" name="shipment_id" value="<?= $shipmentId ?>">
                                                <input type="hidden" name="reason" value="Return pickup called off">
                                                <input type="hidden" name="return" value="<?= e_attr($returnNow) ?>">
                                                <button type="submit" class="ad-btn ad-btn--sm ad-btn--danger">
                                                    <?= icon('close', 'w-4 h-4') ?> Cancel pickup
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                        <a class="ad-btn ad-btn--sm"
                                           href="<?= e(admin_url('shipping/track.php?shipment=' . $shipmentId)) ?>">
                                            <?= icon('location', 'w-4 h-4') ?> Timeline
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?= admin_pagination($pagination, $listUrl) ?>
        <?php endif; ?>

        </div><!-- /.ad-card__body -->
    </div><!-- /.ad-card -->

    <?php /* Why "Stock restored" reads off the order and not the consignment: stock
             goes back exactly once, when the ORDER first enters a releasing status
             (Returned or Cancelled), so the courier's status is the wrong thing to
             read. The full rule is in this file's docblock. */ ?>
    <p class="ad-muted" style="margin-top:14px;font-size:var(--ad-text-xs)">
        "Stock restored" follows the order's status, not the courier's. Refunds and decisions
        are on <a href="<?= e(admin_url('orders/returns.php')) ?>">Return requests</a>.
    </p>

</div>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
