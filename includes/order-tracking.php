<?php
/**
 * ShopInnKart - Order tracking view model + renderer.
 *
 * ONE renderer for every tracking surface. track-order.php renders it server
 * side, /api/orders/track.php returns the same HTML for the JavaScript path,
 * and order-details.php reuses the timeline half. Before this file each of the
 * three built its own markup from order_timeline() and they had already drifted.
 *
 * ---------------------------------------------------------------------------
 *  WHAT THIS SHOP ACTUALLY RECORDS  (nothing here may be invented)
 * ---------------------------------------------------------------------------
 *  order_status_history  one row per status change, with created_at and a
 *                        human note. Every status in ORDER_TIMELINE can appear
 *                        here, so pending / confirmed / processing / packed /
 *                        shipped / out_for_delivery / delivered all carry a
 *                        REAL timestamp once they have been reached.
 *  orders                created_at, confirmed_at, shipped_at, delivered_at,
 *                        cancelled_at - used only as a fallback when the
 *                        history row is missing.
 *  payments              status + paid_at - the only evidence of "payment
 *                        confirmed". It is NOT a value of orders.status, so the
 *                        payment stage is inserted at the position its own
 *                        timestamp puts it in (for Cash on Delivery that is
 *                        after delivery, because that is when the cash is
 *                        collected), never at a fixed spot that the data does
 *                        not support.
 *
 *  There is NO "in transit" status, column or history row anywhere in the
 *  schema, so no such stage is emitted. While an order sits at `shipped` the
 *  Shipped stage describes the live state as "in transit with <courier>",
 *  which is a restatement of status + courier_name, not an invented event.
 *
 *  A stage that has not been reached shows NO date and says so. A stage that
 *  was passed without a history row (an admin jumping two statuses) shows
 *  "time not recorded" rather than borrowing a neighbour's timestamp.
 */

declare(strict_types=1);

// ===========================================================================
//  VIEW MODEL
// ===========================================================================

/**
 * Label, icon and plain-English meaning of every canonical status.
 *
 * `done` describes what the stage means once it has been recorded - it is used
 * only when the real history note is empty. `wait` is deliberately written in
 * the future tense so a pending stage can never read as something that
 * happened.
 */
function order_tracking_stage_meta(): array
{
    static $meta = null;

    if ($meta === null) {
        $meta = [
            ORDER_STATUS_PENDING => [
                'label' => 'Order placed',
                'icon'  => 'file-text',
                'done'  => 'We received your order and sent the confirmation.',
                'wait'  => '',
            ],
            ORDER_STATUS_CONFIRMED => [
                'label' => 'Order confirmed',
                'icon'  => 'check-circle',
                'done'  => 'Your order was accepted and the items were reserved for you.',
                'wait'  => 'We confirm every order before it is picked.',
            ],
            ORDER_STATUS_PROCESSING => [
                'label' => 'Processing',
                'icon'  => 'settings',
                'done'  => 'Your items are being picked and quality checked.',
                'wait'  => 'Picking begins once the order is confirmed.',
            ],
            ORDER_STATUS_PACKED => [
                'label' => 'Packed',
                'icon'  => 'box',
                'done'  => 'Packed, sealed and waiting for the courier pickup.',
                'wait'  => 'We will record this the moment your parcel is packed.',
            ],
            ORDER_STATUS_SHIPPED => [
                'label' => 'Shipped',
                'icon'  => 'truck',
                'done'  => 'The parcel left our warehouse with the delivery partner.',
                'wait'  => 'The courier and tracking number appear here once it ships.',
            ],
            ORDER_STATUS_OUT_FOR_DELIVERY => [
                'label' => 'Out for delivery',
                'icon'  => 'location',
                'done'  => 'The parcel is with the delivery agent for the final leg.',
                'wait'  => 'This is recorded on the morning the parcel goes out.',
            ],
            ORDER_STATUS_DELIVERED => [
                'label' => 'Delivered',
                'icon'  => 'home',
                'done'  => 'The parcel was handed over at the delivery address.',
                'wait'  => 'Nothing has been delivered yet.',
            ],
            ORDER_STATUS_CANCELLED => [
                'label' => 'Cancelled',
                'icon'  => 'close',
                'done'  => 'This order was cancelled and the items were returned to stock.',
                'wait'  => '',
            ],
            ORDER_STATUS_RETURNED => [
                'label' => 'Returned',
                'icon'  => 'rotate',
                'done'  => 'The parcel came back to us and the items were returned to stock.',
                'wait'  => '',
            ],
            ORDER_STATUS_REFUNDED => [
                'label' => 'Refunded',
                'icon'  => 'wallet',
                'done'  => 'The amount paid for this order was refunded.',
                'wait'  => '',
            ],
        ];
    }

    return $meta;
}

/** The payments row behind an order, or null. */
function order_tracking_payment(array $order): ?array
{
    return Database::fetch(
        'SELECT * FROM `payments` WHERE `order_id` = :id ORDER BY `id` DESC LIMIT 1',
        ['id' => (int) $order['id']]
    );
}

/**
 * The stages to draw, in the order they are drawn.
 *
 * Every element:
 *   key    stable identifier (a status, or "payment")
 *   label  heading
 *   icon   glyph name for icon()
 *   state  done | current | pending   (exactly one - they are mutually exclusive)
 *   tone   default | stopped          (stopped = cancelled / returned / refunded)
 *   at     REAL timestamp or null. Never derived, never guessed.
 *   desc   the recorded note when there is one, otherwise a description of the
 *          stage written in the tense that matches `state`.
 */
function order_tracking_stages(array $order): array
{
    $meta    = order_tracking_stage_meta();
    $history = get_order_history((int) $order['id']);
    $status  = (string) $order['status'];

    // First occurrence wins: an order that was touched again after delivery has
    // several `delivered` rows, and the first one is when it was delivered.
    $firstAt = [];
    $firstNote = [];
    foreach ($history as $row) {
        $rowStatus = (string) $row['status'];
        if (!array_key_exists($rowStatus, $firstAt)) {
            $firstAt[$rowStatus] = (string) $row['created_at'];
            $note = trim((string) ($row['note'] ?? ''));
            $firstNote[$rowStatus] = $note === '' ? null : $note;
        }
    }

    // The order row stamps these four moments directly; they only stand in when
    // the history row is missing, and they are just as real.
    $columnAt = [
        ORDER_STATUS_PENDING   => $order['created_at'] ?? null,
        ORDER_STATUS_CONFIRMED => $order['confirmed_at'] ?? null,
        ORDER_STATUS_SHIPPED   => $order['shipped_at'] ?? null,
        ORDER_STATUS_DELIVERED => $order['delivered_at'] ?? null,
        ORDER_STATUS_CANCELLED => $order['cancelled_at'] ?? null,
    ];

    $timeOf = static function (string $step) use ($firstAt, $columnAt): ?string {
        $value = $firstAt[$step] ?? $columnAt[$step] ?? null;
        return $value === null || $value === '' ? null : (string) $value;
    };

    $build = static function (string $step, string $state, ?string $at, ?string $note) use ($meta): array {
        $info = $meta[$step] ?? ['label' => ucfirst(str_replace('_', ' ', $step)), 'icon' => 'info', 'done' => '', 'wait' => ''];
        $stopped = in_array($step, STOCK_RELEASING_STATUSES, true);

        return [
            'key'   => $step,
            'label' => $info['label'],
            'icon'  => $info['icon'],
            'state' => $state,
            'tone'  => $stopped ? 'stopped' : 'default',
            'at'    => $at,
            'desc'  => $note !== null && $note !== ''
                ? $note
                : ($state === 'pending' ? $info['wait'] : $info['done']),
        ];
    };

    // ---- Cancelled / returned / refunded ---------------------------------
    // Drawn from the history rows that exist, so the stages the order really
    // passed through before it stopped are still shown with their own times.
    if (in_array($status, STOCK_RELEASING_STATUSES, true)) {
        $stages = [];
        $seen = [];

        foreach ($history as $row) {
            $rowStatus = (string) $row['status'];
            if (isset($seen[$rowStatus]) || !isset($meta[$rowStatus])) {
                continue;
            }
            $seen[$rowStatus] = true;
            $stages[] = $build($rowStatus, 'done', $timeOf($rowStatus), $firstNote[$rowStatus] ?? null);
        }

        if ($stages === []) {
            $stages[] = $build(ORDER_STATUS_PENDING, 'done', $timeOf(ORDER_STATUS_PENDING), null);
        }
        if (!isset($seen[$status])) {
            $stages[] = $build($status, 'done', $timeOf($status), $firstNote[$status] ?? null);
        }

        $stages[count($stages) - 1]['state'] = 'current';

        return $stages;
    }

    // ---- The live lifecycle ----------------------------------------------
    $currentIndex = array_search($status, ORDER_TIMELINE, true);
    $currentIndex = $currentIndex === false ? 0 : (int) $currentIndex;

    $stages = [];
    foreach (ORDER_TIMELINE as $index => $step) {
        $state = $index < $currentIndex ? 'done' : ($index === $currentIndex ? 'current' : 'pending');
        $at = $state === 'pending' ? null : $timeOf($step);
        $stages[] = $build($step, $state, $at, $state === 'pending' ? null : ($firstNote[$step] ?? null));
    }

    // A delivered order has finished rather than being mid-flight, so its
    // current stage is drawn in the success tone instead of the in-progress one.
    if ($status === ORDER_STATUS_DELIVERED) {
        $stages[$currentIndex]['tone'] = 'complete';
    }

    // While an order sits at `shipped` the only true statement about where the
    // parcel is comes from the status itself plus the courier on the row.
    if ($status === ORDER_STATUS_SHIPPED) {
        $courier = trim((string) ($order['courier_name'] ?? ''));
        $stages[$currentIndex]['live'] = $courier !== ''
            ? 'In transit with ' . $courier . '.'
            : 'In transit with our delivery partner.';
    }

    $payment = order_tracking_payment($order);
    $stages = order_tracking_insert_payment_stage($stages, $order, $payment);

    return $stages;
}

/**
 * Put the payment stage where this order's own payment record says it belongs.
 *
 * Cash on Delivery is collected when the parcel is handed over, so on a COD
 * order the payment stage sits AFTER delivery - which is exactly where paid_at
 * lands it. A prepaid order that is still unpaid has nothing to date, so the
 * stage sits early and stays pending.
 */
function order_tracking_insert_payment_stage(array $stages, array $order, ?array $payment): array
{
    $paymentStatus = (string) $order['payment_status'];
    $paidAt = $payment !== null && !empty($payment['paid_at']) ? (string) $payment['paid_at'] : null;
    $isCod = (string) $order['payment_method'] === PAYMENT_METHOD_COD;
    $total = money((float) $order['total_amount']);

    if ($paymentStatus === PAYMENT_STATUS_PAID) {
        $stage = [
            'key'   => 'payment',
            'label' => 'Payment confirmed',
            'icon'  => $isCod ? 'wallet' : 'credit-card',
            'state' => 'done',
            'tone'  => 'default',
            'at'    => $paidAt,
            'desc'  => $isCod
                ? $total . ' collected in cash on delivery.'
                : $total . ' received.',
        ];
    } elseif ($paymentStatus === PAYMENT_STATUS_FAILED) {
        $stage = [
            'key'   => 'payment',
            'label' => 'Payment not completed',
            'icon'  => 'credit-card',
            'state' => 'pending',
            'tone'  => 'default',
            'at'    => null,
            'desc'  => 'The payment for this order was not completed.',
        ];
    } elseif ($isCod) {
        $stage = [
            'key'   => 'payment',
            'label' => 'Payment on delivery',
            'icon'  => 'wallet',
            'state' => 'pending',
            'tone'  => 'default',
            'at'    => null,
            'desc'  => $total . ' is payable in cash to the delivery agent when the parcel arrives.',
        ];
    } else {
        $stage = [
            'key'   => 'payment',
            'label' => 'Payment confirmed',
            'icon'  => 'credit-card',
            'state' => 'pending',
            'tone'  => 'default',
            'at'    => null,
            'desc'  => 'We have not recorded a payment for this order yet.',
        ];
    }

    // A dated payment goes after the last stage that happened before it. An
    // undated COD payment goes at the end, because that is when it will happen;
    // an undated prepaid payment goes straight after the order was placed.
    $position = count($stages);

    if ($stage['at'] !== null) {
        $paidTs = strtotime($stage['at']);
        $position = 1;
        foreach ($stages as $index => $existing) {
            if ($existing['at'] === null) {
                continue;
            }
            $ts = strtotime((string) $existing['at']);
            if ($ts !== false && $paidTs !== false && $ts <= $paidTs) {
                $position = $index + 1;
            }
        }
    } elseif (!$isCod) {
        $position = 1;
    }

    array_splice($stages, $position, 0, [$stage]);

    return $stages;
}

/**
 * One sentence describing where the order is right now, built only from the
 * order row. Returned as ['line' => string, 'sub' => string].
 */
function order_tracking_headline(array $order): array
{
    $status = (string) $order['status'];
    $meta = order_tracking_stage_meta();
    $line = $meta[$status]['done'] ?? '';
    $sub = '';

    switch ($status) {
        case ORDER_STATUS_DELIVERED:
            $line = !empty($order['delivered_at'])
                ? 'Delivered on ' . format_datetime($order['delivered_at'])
                : 'Delivered.';
            break;
        case ORDER_STATUS_SHIPPED:
            $courier = trim((string) ($order['courier_name'] ?? ''));
            $line = $courier !== ''
                ? 'In transit with ' . $courier . '.'
                : 'In transit with our delivery partner.';
            break;
        case ORDER_STATUS_CANCELLED:
            $line = !empty($order['cancelled_at'])
                ? 'Cancelled on ' . format_datetime($order['cancelled_at'])
                : 'This order was cancelled.';
            break;
    }

    if (!in_array($status, STOCK_RELEASING_STATUSES, true)
        && $status !== ORDER_STATUS_DELIVERED
        && !empty($order['estimated_delivery'])) {
        $sub = 'Expected by ' . format_date($order['estimated_delivery']);
    }

    return ['line' => $line, 'sub' => $sub];
}

// ===========================================================================
//  RENDERERS
// ===========================================================================

/** The vertical stage timeline. Shared by the tracking page and the account order page. */
function order_timeline_html(array $stages): string
{
    if ($stages === []) {
        return '';
    }

    ob_start(); ?>
    <ol class="sik-timeline sik-timeline--rich">
        <?php foreach ($stages as $index => $stage): ?>
            <?php
            $state = (string) $stage['state'];
            $tone  = (string) ($stage['tone'] ?? 'default');
            $classes = 'sik-timeline__step is-' . $state;
            if ($tone !== 'default') {
                $classes .= ' is-' . $tone;
            }

            // The connector below a step is only drawn as "travelled" when the
            // NEXT stage has also happened. Deciding it here rather than from
            // .is-done alone keeps the rail correct where a completed stage
            // follows the current one - a COD payment collected on delivery.
            $next = $stages[$index + 1] ?? null;
            if ($next !== null && $next['state'] !== 'pending') {
                $classes .= ' is-linked';
                if (($next['tone'] ?? 'default') === 'stopped') {
                    $classes .= ' is-linked-stop';
                }
            }
            ?>
            <li class="<?= e_attr($classes) ?>"<?= $state === 'current' ? ' aria-current="step"' : '' ?>>
                <span class="sik-timeline__dot"><?= icon((string) $stage['icon'], 'w-4 h-4') ?></span>
                <div class="sik-timeline__body">
                    <p class="sik-timeline__label">
                        <?php // The dot alone carries no meaning for a screen reader; these
                              // prefixes give it the same three-way state the colours do. ?>
                        <?php if ($state === 'current'): ?><span class="sik-sr">Current stage: </span><?php endif; ?>
                        <?php if ($state === 'pending'): ?><span class="sik-sr">Not yet reached: </span><?php endif; ?>
                        <?= e((string) $stage['label']) ?>
                        <?php if ($state === 'current'): ?>
                            <span class="sik-timeline__chip"><?php
                                echo $tone === 'stopped' ? 'Final status' : ($tone === 'complete' ? 'Completed' : 'You are here');
                            ?></span>
                        <?php endif; ?>
                    </p>

                    <p class="sik-timeline__time">
                        <?php if (!empty($stage['at'])): ?>
                            <?= icon('clock', 'w-3.5 h-3.5') ?>
                            <span class="sik-num"><?= e(format_datetime($stage['at'])) ?></span>
                        <?php elseif ($state === 'pending'): ?>
                            <span class="sik-timeline__pending">Pending</span>
                        <?php else: ?>
                            <?php // Passed, but this shop never wrote a time for it. Saying so
                                  // is the only honest option - borrowing the next stage's
                                  // timestamp would invent an event. ?>
                            <span class="sik-timeline__pending">Time not recorded</span>
                        <?php endif; ?>
                    </p>

                    <?php if (!empty($stage['desc'])): ?>
                        <p class="sik-timeline__desc"><?= e((string) $stage['desc']) ?></p>
                    <?php endif; ?>

                    <?php if (!empty($stage['live'])): ?>
                        <p class="sik-timeline__live"><?= e((string) $stage['live']) ?></p>
                    <?php endif; ?>
                </div>
            </li>
        <?php endforeach; ?>
    </ol>
    <?php
    return (string) ob_get_clean();
}

/**
 * The whole tracking result: header, timeline, shipment, address, items.
 *
 * $options:
 *   invoice_url  string|null  shown as a button when set
 *   anchor       string|null  id for the wrapper
 */
function order_tracking_panel_html(array $order, ?array $items = null, array $options = []): string
{
    $orderId = (int) $order['id'];
    $items = $items ?? get_order_items($orderId);
    $stages = order_tracking_stages($order);
    $payment = order_tracking_payment($order);
    $pill = api_order_status_pill((string) $order['status']);
    $headline = order_tracking_headline($order);

    $status = (string) $order['status'];
    $isStopped = in_array($status, STOCK_RELEASING_STATUSES, true);
    $isDelivered = $status === ORDER_STATUS_DELIVERED;

    $units = 0;
    foreach ($items as $item) {
        $units += (int) $item['quantity'];
    }

    $courier  = trim((string) ($order['courier_name'] ?? ''));
    $tracking = trim((string) ($order['tracking_number'] ?? ''));
    $eta      = (string) ($order['estimated_delivery'] ?? '');
    $showShipment = $courier !== '' || $tracking !== '' || ($eta !== '' && !$isStopped && !$isDelivered);

    $paymentStatus = (string) $order['payment_status'];
    $paymentColors = [
        PAYMENT_STATUS_PENDING  => 'amber',
        PAYMENT_STATUS_PAID     => 'green',
        PAYMENT_STATUS_FAILED   => 'red',
        PAYMENT_STATUS_REFUNDED => 'gray',
    ];

    // The shopper proved they know one of the two contacts on the order, not
    // both, so the other one is only ever shown partially.
    $phone = preg_replace('/\D+/', '', (string) ($order['shipping_phone'] ?? '')) ?? '';
    $phoneMasked = strlen($phone) >= 6
        ? substr($phone, 0, 2) . str_repeat('*', max(2, strlen($phone) - 5)) . substr($phone, -3)
        : '';

    $trackUrl = url('track-order.php?order=' . rawurlencode((string) $order['order_number']));
    $invoiceUrl = $options['invoice_url'] ?? null;

    ob_start(); ?>
    <article class="sik-tracking"<?= isset($options['anchor']) ? ' id="' . e_attr((string) $options['anchor']) . '"' : '' ?>>

        <!-- ---------------------------------------------------------- hero -->
        <header class="sik-tracking__hero">
            <div class="sik-tracking__heroTop">
                <div class="sik-tracking__id">
                    <span class="sik-tracking__eyebrow">Order number</span>
                    <p class="sik-tracking__number">
                        <span class="sik-num"><?= e((string) $order['order_number']) ?></span>
                        <button type="button" class="sik-iconbtn"
                                data-copy="<?= e_attr((string) $order['order_number']) ?>"
                                aria-label="Copy order number"><?= icon('copy', 'w-4 h-4') ?></button>
                    </p>
                    <p class="sik-tracking__placed">Placed on <?= e(format_datetime($order['created_at'])) ?></p>
                </div>

                <div class="sik-tracking__state">
                    <span class="sik-status sik-status--<?= e_attr($pill['color']) ?>"><?= e($pill['label']) ?></span>
                    <?php if ($headline['line'] !== ''): ?>
                        <p class="sik-tracking__headline"><?= e($headline['line']) ?></p>
                    <?php endif; ?>
                    <?php if ($headline['sub'] !== ''): ?>
                        <p class="sik-tracking__eta"><?= icon('calendar', 'w-4 h-4') ?><?= e($headline['sub']) ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($isStopped && !empty($order['cancel_reason'])): ?>
                <p class="sik-tracking__reason">
                    <?= icon('info', 'w-4 h-4') ?>
                    <span>Reason: <?= e((string) $order['cancel_reason']) ?></span>
                </p>
            <?php endif; ?>
        </header>

        <div class="sik-tracking__grid">

            <!-- ------------------------------------------------ timeline -->
            <section class="sik-tracking__main" aria-labelledby="trkTimelineHead<?= $orderId ?>">
                <h3 class="sik-tracking__h" id="trkTimelineHead<?= $orderId ?>">Delivery timeline</h3>
                <?= order_timeline_html($stages) ?>
                <p class="sik-tracking__note">
                    <?= icon('shield-check', 'w-4 h-4') ?>
                    <span>Every time shown above is taken from this order's own record. A stage with no
                          time has not happened yet.</span>
                </p>
            </section>

            <!-- ---------------------------------------------------- facts -->
            <aside class="sik-tracking__side">

                <?php if ($showShipment): ?>
                    <div class="sik-panel">
                        <div class="sik-panel__head"><span class="sik-panel__title">Shipment</span></div>
                        <div class="sik-panel__body">
                            <div class="sik-summary">
                                <?php if ($courier !== ''): ?>
                                    <div class="sik-summary__row">
                                        <span class="sik-tracking__k">Delivery partner</span>
                                        <span class="sik-tracking__v"><?= e($courier) ?></span>
                                    </div>
                                <?php endif; ?>

                                <?php if ($tracking !== ''): ?>
                                    <div class="sik-summary__row sik-tracking__row--wrap">
                                        <span class="sik-tracking__k">Tracking number</span>
                                        <span class="sik-tracking__v sik-tracking__awb">
                                            <span class="sik-num"><?= e($tracking) ?></span>
                                            <button type="button" class="sik-iconbtn"
                                                    data-copy="<?= e_attr($tracking) ?>"
                                                    aria-label="Copy tracking number"><?= icon('copy', 'w-4 h-4') ?></button>
                                        </span>
                                    </div>
                                <?php endif; ?>

                                <?php if ($eta !== '' && !$isStopped): ?>
                                    <div class="sik-summary__row">
                                        <span class="sik-tracking__k"><?= $isDelivered ? 'Was expected by' : 'Estimated delivery' ?></span>
                                        <span class="sik-tracking__v"><?= e(format_date($eta)) ?></span>
                                    </div>
                                <?php endif; ?>

                                <?php if ($isDelivered && !empty($order['delivered_at'])): ?>
                                    <div class="sik-summary__row">
                                        <span class="sik-tracking__k">Delivered on</span>
                                        <span class="sik-tracking__v"><?= e(format_date($order['delivered_at'])) ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <?php // The courier's own tracking URL is not stored anywhere in this
                                  // shop, so none is invented. The link that DOES exist is this
                                  // page - the same one the confirmation email sends - and it is
                                  // safe to share because it still asks for the email or mobile. ?>
                            <div class="sik-tracking__link">
                                <button type="button" class="sik-btn sik-btn--outline sik-btn--sm sik-btn--block"
                                        data-copy="<?= e_attr($trackUrl) ?>">
                                    <?= icon('copy', 'w-4 h-4') ?><span class="sik-btn__label">Copy tracking link</span>
                                </button>
                                <?php if ($tracking !== ''): ?>
                                    <p class="sik-tracking__hint">
                                        Quote the tracking number above to <?= e($courier !== '' ? $courier : 'the delivery partner') ?>
                                        if you contact them directly.
                                    </p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="sik-panel">
                    <div class="sik-panel__head"><span class="sik-panel__title">Delivery address</span></div>
                    <div class="sik-panel__body">
                        <p class="sik-tracking__addrName"><?= e((string) $order['shipping_name']) ?></p>
                        <address class="sik-tracking__addr">
                            <?= e((string) $order['shipping_address']) ?><br>
                            <?php if (!empty($order['shipping_address2'])): ?><?= e((string) $order['shipping_address2']) ?><br><?php endif; ?>
                            <?php if (!empty($order['shipping_landmark'])): ?>Near <?= e((string) $order['shipping_landmark']) ?><br><?php endif; ?>
                            <?= e((string) $order['shipping_city']) ?>, <?= e((string) $order['shipping_state']) ?>
                            <span class="sik-num"><?= e((string) $order['shipping_pincode']) ?></span><br>
                            <?= e((string) $order['shipping_country']) ?>
                        </address>
                        <?php if ($phoneMasked !== ''): ?>
                            <p class="sik-tracking__phone">
                                <?= icon('phone', 'w-4 h-4') ?><span class="sik-num"><?= e($phoneMasked) ?></span>
                            </p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="sik-panel">
                    <div class="sik-panel__head">
                        <span class="sik-panel__title">Your items</span>
                        <span class="sik-tracking__count"><?= e((string) $units) ?> <?= $units === 1 ? 'unit' : 'units' ?></span>
                    </div>
                    <div class="sik-panel__body">
                        <?php if ($items === []): ?>
                            <p class="sik-tracking__hint">No items are recorded on this order. Please contact support
                                quoting the order number.</p>
                        <?php else: ?>
                            <ul class="sik-tracking__items">
                                <?php foreach ($items as $item): ?>
                                    <li class="sik-tracking__item">
                                        <?php if ($item['url'] !== null): ?>
                                            <a href="<?= e_attr((string) $item['url']) ?>" class="sik-tracking__thumb">
                                                <img src="<?= e_attr((string) $item['image_url']) ?>" width="56" height="56" loading="lazy"
                                                     alt="<?= e_attr((string) $item['product_name']) ?>">
                                            </a>
                                        <?php else: ?>
                                            <span class="sik-tracking__thumb">
                                                <img src="<?= e_attr((string) $item['image_url']) ?>" width="56" height="56" loading="lazy"
                                                     alt="<?= e_attr((string) $item['product_name']) ?>">
                                            </span>
                                        <?php endif; ?>
                                        <div class="sik-tracking__itemBody">
                                            <p class="sik-tracking__itemName"><?= e((string) $item['product_name']) ?></p>
                                            <?php if (!empty($item['variant_name'])): ?>
                                                <p class="sik-tracking__itemMeta"><?= e((string) $item['variant_name']) ?></p>
                                            <?php endif; ?>
                                            <p class="sik-tracking__itemMeta">
                                                Qty <span class="sik-num"><?= e((string) $item['quantity']) ?></span>
                                            </p>
                                        </div>
                                        <span class="sik-tracking__itemPrice sik-num"><?= e((string) $item['subtotal_display']) ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>

                        <div class="sik-summary sik-tracking__totals">
                            <div class="sik-summary__row">
                                <span class="sik-tracking__k">Subtotal</span>
                                <span class="sik-num"><?= e(money((float) $order['subtotal'])) ?></span>
                            </div>
                            <?php if ((float) $order['discount_amount'] > 0): ?>
                                <div class="sik-summary__row sik-summary__row--save">
                                    <span>Discount<?= !empty($order['coupon_code']) ? ' (' . e((string) $order['coupon_code']) . ')' : '' ?></span>
                                    <span class="sik-num">- <?= e(money((float) $order['discount_amount'])) ?></span>
                                </div>
                            <?php endif; ?>
                            <div class="sik-summary__row">
                                <span class="sik-tracking__k">Shipping</span>
                                <span class="sik-num"><?= (float) $order['shipping_amount'] > 0 ? e(money((float) $order['shipping_amount'])) : 'Free' ?></span>
                            </div>
                            <div class="sik-summary__row sik-summary__row--total">
                                <span>Order total</span>
                                <span class="sik-num"><?= e(money((float) $order['total_amount'])) ?></span>
                            </div>
                            <div class="sik-summary__row">
                                <span class="sik-tracking__k">Payment</span>
                                <span class="sik-status sik-status--<?= e_attr($paymentColors[$paymentStatus] ?? 'gray') ?>">
                                    <?= e(PAYMENT_STATUSES[$paymentStatus] ?? $paymentStatus) ?>
                                </span>
                            </div>
                            <?php if ($payment !== null && !empty($payment['paid_at'])): ?>
                                <div class="sik-summary__row">
                                    <span class="sik-tracking__k">Paid on</span>
                                    <span class="sik-num"><?= e(format_datetime($payment['paid_at'])) ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </aside>
        </div>

        <footer class="sik-tracking__foot">
            <?php if ($invoiceUrl !== null): ?>
                <a class="sik-btn sik-btn--outline sik-btn--sm" href="<?= e_attr((string) $invoiceUrl) ?>">
                    <?= icon('file-text', 'w-4 h-4') ?><span class="sik-btn__label">View invoice</span>
                </a>
            <?php endif; ?>
            <a class="sik-btn sik-btn--ghost sik-btn--sm" href="<?= e_attr(url('contact.php')) ?>">
                <?= icon('headset', 'w-4 h-4') ?><span class="sik-btn__label">Get help with this order</span>
            </a>
        </footer>
    </article>
    <?php
    return (string) ob_get_clean();
}
