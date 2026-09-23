<?php
/**
 * ShopInnKart Admin - Book a shipment for one order.
 *
 * One order, top to bottom in the order an admin works through it: what is
 * being shipped, what is already booked for it, how to book it, and what went
 * wrong with earlier attempts.
 *
 * The screen never writes a shipments or orders row. Booking, AWB, pickup,
 * refresh and cancel all go through shipping-service.php, which holds the lock
 * that stops two admins booking the same order, the forward-only status rule
 * and the order sync. This file validates input and shows answers. The
 * shipment buttons post to action.php, which the tracking screen shares, so
 * "Cancel" means the same thing whichever screen it was pressed on.
 *
 * Choices worth knowing before changing this file:
 *
 *   - While a live shipment exists the booking form is not rendered at all. The
 *     service would refuse a second booking anyway, and a form that can only
 *     fail is worse than showing the shipment that holds the order. For the
 *     same reason an order the service would refuse (shipping_order_block_reason:
 *     closed, or prepaid and unpaid) gets the reason instead of rates.
 *
 *   - Rates are quoted on every view, never cached. A quote is the courier's
 *     answer about now, and a stale one is how a lane that stopped being
 *     serviceable gets booked. The quote includes the parcel's dimensions:
 *     couriers bill the larger of dead and volumetric weight.
 *
 *   - The chosen rate carries who (provider, courier id, courier name) and
 *     never a price. Booking quotes the same parcel again and takes the price
 *     - stored as the shipment's shipping charge - from the courier's fresh
 *     answer for that courier, so a price posted from the browser is never
 *     read, and a rate that is no longer offered is refused, not booked blind.
 *
 *   - Booking posts the parcel the rates were quoted FOR, from hidden fields,
 *     not whatever is typed in the parcel boxes at that moment. Editing the
 *     parcel disables Book until the rates are fetched again. The weight
 *     rules are the same for a typed weight and the catalogue estimate, so a
 *     page never offers to book a parcel the POST will refuse.
 *
 *   - A courier with no sandbox (Shiprocket) in Test mode still quotes - rates
 *     are read-only and come from the real account - but its driver refuses
 *     every billable call, booking included. Its rates are listed, unpickable,
 *     under a note saying why, and a POST naming it is refused here: sent on to
 *     shipping_book() it would only leave a failed_booking row behind.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('orders.view');

require_once INCLUDES_PATH . '/shipping-service.php';

$orderId = input_int('order');
$order   = $orderId > 0 ? shipping_order_payload($orderId) : null;

if ($order === null) {
    flash('error', 'That order does not exist.');
    redirect(admin_url('shipping/shipments.php'));
}

$canEdit     = admin_can('orders.edit');
$orderStatus = (string) $order['status'];
$selfUrl     = admin_url('shipping/book.php?order=' . $orderId);

// action.php takes its way back from the request, and admin_safe_return()
// accepts only a path inside this admin - so the path, not the absolute URL.
$returnPath = (string) parse_url($selfUrl, PHP_URL_PATH) . '?' . (string) parse_url($selfUrl, PHP_URL_QUERY);

// Whatever shipping_book() would refuse the order for - asked of the service
// itself rather than kept here as a second list, which is how an unpaid
// prepaid order came to be quoted and offered a Book that could only fail.
$blockReason = shipping_order_block_reason($order);

/**
 * Does this provider row quote but refuse to book? A courier with no sandbox
 * (see the docblock) outside Live mode - and, as its driver does, a row with no
 * mode at all counts as Test.
 */
$quotesOnly = static function (array $provider): bool {
    return in_array((string) ($provider['code'] ?? ''), ['shiprocket'], true)
        && (string) ($provider['mode'] ?? '') !== 'live';
};

/**
 * The parcel as the admin described it, falling back to the catalogue estimate.
 *
 * Returns [values, errors, raw]. Dimensions are optional - without them the
 * courier charges dead weight - but they come as a set, and a dimension that is
 * given must be sane: couriers bill the larger of dead and volumetric weight,
 * so 300 typed for 30 is a tenfold bill.
 */
$readParcel = static function (int $estimatedGrams): array {
    $text = static function (string $key): string {
        $value = input($key, '');
        return is_string($value) ? trim($value) : '';
    };

    $raw = [
        'weight_grams' => $text('weight_grams'),
        'length_cm'    => $text('length_cm'),
        'width_cm'     => $text('width_cm'),
        'height_cm'    => $text('height_cm'),
    ];
    $values = ['weight_grams' => $estimatedGrams, 'length_cm' => null, 'width_cm' => null, 'height_cm' => null];
    $errors = [];

    if ($raw['weight_grams'] !== '') {
        $grams = ctype_digit($raw['weight_grams']) ? (int) $raw['weight_grams'] : 0;
        if ($grams < 50 || $grams > 50000) {
            $errors['weight_grams'] = 'Weight must be whole grams, between 50 and 50,000.';
        } else {
            $values['weight_grams'] = $grams;
        }
    } elseif ($estimatedGrams < 50 || $estimatedGrams > 50000) {
        // The same bounds as a typed weight. The estimate is only a default;
        // quoting it unchecked offered rates and a Book button for a parcel
        // the POST then refused.
        $errors['weight_grams'] = 'The catalogue estimate (' . number_format($estimatedGrams / 1000, 1)
            . ' kg) is outside the 50 g to 50 kg a booking takes. Weigh the packed parcel and enter its weight.';
    }

    $given = 0;
    foreach (['length_cm' => 'Length', 'width_cm' => 'Width', 'height_cm' => 'Height'] as $key => $label) {
        if ($raw[$key] === '') {
            continue;
        }
        $given++;
        if (!is_numeric($raw[$key]) || (float) $raw[$key] < 1 || (float) $raw[$key] > 300) {
            $errors[$key] = $label . ' must be between 1 and 300 cm.';
            continue;
        }
        $values[$key] = round((float) $raw[$key], 1);
    }
    if ($given > 0 && $given < 3) {
        $errors['dimensions'] = 'Give all three dimensions, or leave all three blank.';
    }

    return [$values, $errors, $raw];
};

[$parcel, $parcelErrors, $parcelRaw] = $readParcel((int) $order['estimated_grams']);

// ---------------------------------------------------------------------------
// Book: POST the chosen rate.
// ---------------------------------------------------------------------------
if (is_post()) {
    admin_require_action('orders.edit');   // POST + CSRF + permission

    // Every failure lands back on the same quote, so the admin is not made to
    // re-enter a parcel they had just weighed.
    $retryQuery = array_filter($parcelRaw, static fn (string $v): bool => $v !== '');
    $retryUrl   = $selfUrl . ($retryQuery !== [] ? '&' . http_build_query($retryQuery) : '');

    if ($blockReason !== null) {
        flash('error', $blockReason);
        redirect($selfUrl);
    }

    if ($parcelErrors !== []) {
        flash('error', (string) reset($parcelErrors));
        redirect($retryUrl);
    }

    $rateRaw = input('rate', '');
    $choice  = is_string($rateRaw) && $rateRaw !== '' ? json_decode($rateRaw, true) : null;
    if (!is_array($choice) || !is_string($choice['provider'] ?? null)) {
        flash('error', 'Choose a courier from the rates before booking.');
        redirect($retryUrl);
    }

    // The code must name a courier that is switched on AND has a driver right
    // now - not merely one that was when the rates were drawn.
    $providerCode = $choice['provider'];
    $liveRows     = array_column(ShippingProviderFactory::available(), null, 'code');
    $liveNames    = array_column($liveRows, 'name', 'code');
    if (!isset($liveNames[$providerCode])) {
        flash('error', 'That courier is no longer available. Get rates again.');
        redirect($retryUrl);
    }
    // The page offers no pick for it; this catches a page drawn while it was
    // Live, or a hand-made POST, before the service writes a row that can only fail.
    if ($quotesOnly($liveRows[$providerCode])) {
        flash('error', (string) $liveNames[$providerCode] . ' is in Test mode and has no sandbox, so it cannot book. '
            . 'Choose another courier, or switch it to Live first.');
        redirect($retryUrl);
    }

    // Identity only. The courier id is an aggregator's sub-courier (Shiprocket
    // sends an integer), the name is what the shipment displays.
    $courierId   = is_scalar($choice['courier_id'] ?? null) ? trim((string) $choice['courier_id']) : '';
    $courierName = is_string($choice['courier'] ?? null)
        ? trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $choice['courier']))
        : '';
    if ($courierId !== '' && preg_match('/^[A-Za-z0-9_.:-]{1,64}$/', $courierId) !== 1) {
        flash('error', 'That rate could not be read. Get rates again.');
        redirect($retryUrl);
    }
    $byId = $courierId !== '' && $courierId !== '0';
    if (!$byId && $courierName === '') {
        flash('error', 'That rate could not be read. Get rates again.');
        redirect($retryUrl);
    }

    // The price, from the courier: the same parcel quoted again and the chosen
    // rate found in the answer - by the aggregator's courier id where there is
    // one, else by name. See the docblock for why the browser never sends it.
    $quoteParcel = array_filter($parcel, static fn ($v): bool => $v !== null);
    $fresh       = shipping_quote_order($orderId, $quoteParcel);
    $rate        = null;
    foreach ($fresh['rates'] as $offered) {
        if ((string) ($offered['provider'] ?? '') !== $providerCode) {
            continue;
        }
        $offeredId = trim((string) ($offered['courier_id'] ?? ''));
        if ($byId ? $offeredId === $courierId : trim((string) ($offered['courier'] ?? '')) === $courierName) {
            $rate = $offered;
            break;
        }
    }
    if ($rate === null) {
        // The provider's own complaint, when it made one ("Shiprocket: login
        // failed"), says more than "no longer quotes".
        $providerName = (string) $liveNames[$providerCode];
        $why          = '';
        foreach ($fresh['errors'] as $error) {
            if ($providerName !== '' && strncmp((string) $error, $providerName . ':', strlen($providerName) + 1) === 0) {
                $why = ' ' . (string) $error;
                break;
            }
        }
        flash('error', 'That courier no longer quotes for this parcel.' . $why . ' Get rates again.');
        redirect($retryUrl);
    }

    // The quote's ETA rides along, so the shipment (and the order the customer
    // sees) gets an expected date from the same answer as the price.
    $opts = ['weight_grams' => $parcel['weight_grams'], 'shipping_charge' => round((float) $rate['cost'], 2)];
    if (isset($rate['eta_days']) && is_numeric($rate['eta_days']) && (int) $rate['eta_days'] > 0) {
        $opts['eta_days'] = (int) $rate['eta_days'];
    }
    foreach (['length_cm', 'width_cm', 'height_cm'] as $key) {
        if ($parcel[$key] !== null) {
            $opts[$key] = $parcel[$key];
        }
    }
    // Who, too, as the fresh quote names it rather than as the browser echoed it.
    $rateId   = trim((string) ($rate['courier_id'] ?? ''));
    $rateName = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) ($rate['courier'] ?? '')));
    if ($rateId !== '' && $rateId !== '0') {
        $opts['courier_id'] = mb_substr($rateId, 0, 40);
    }
    if ($rateName !== '') {
        $opts['courier'] = mb_substr($rateName, 0, 100);
    }

    $result = shipping_book($orderId, $providerCode, $opts);

    // A booking writes a shipment row whether or not the courier accepted it,
    // and a success moves the order the storefront shows.
    admin_after_write();

    if (!empty($result['ok'])) {
        // Booked without an AWB is not a failure - the consignment exists and
        // the AWB can be asked for again - but it is not done either.
        flash(!empty($result['awb_ok']) ? 'success' : 'warning', (string) $result['message']);
        redirect($selfUrl);
    }

    flash('error', 'Booking failed: ' . (string) ($result['message'] ?? 'unknown error'));
    redirect($retryUrl);
}

// ---------------------------------------------------------------------------
// Read everything the page shows.
// ---------------------------------------------------------------------------
$live      = shipment_live_for_order($orderId);
$previous  = array_values(array_filter(
    shipments_for_order($orderId),
    static fn (array $row): bool => $live === null || (int) $row['id'] !== (int) $live['id']
));
$events    = $live !== null ? shipment_events((int) $live['id']) : [];
$available = ShippingProviderFactory::available();

// Names for every provider, including ones switched off since: an old
// shipment should still say who carried it.
$providerNames = array_column(shipping_providers_all(), 'name', 'code');
$providerModes = array_column($available, 'mode', 'code');
// code => name, for the live providers whose rates are shown but not bookable.
$quoteOnlyNames = array_column(array_filter($available, $quotesOnly), 'name', 'code');

$quote = null;
if ($canEdit && $blockReason === null && $live === null && $available !== [] && $parcelErrors === []) {
    $quote = shipping_quote_order($orderId, array_filter($parcel, static fn ($v): bool => $v !== null));
}

$grams = static function (int $g): string {
    return $g >= 1000 ? rtrim(rtrim(number_format($g / 1000, 2), '0'), '.') . ' kg' : $g . ' g';
};
$sourceLabels = ['webhook' => 'Courier push', 'poll' => 'Tracking refresh', 'manual' => 'Admin'];
$badge = static function (string $status): string {
    return '<span class="sik-status sik-status--' . e_attr(shipping_status_tone($status)) . '">'
        . e(shipping_status_label($status)) . '</span>';
};

$pageTitle    = 'Ship order ' . $order['order_number'];
$pageSubtitle = (string) $order['customer_name'] . ' · ' . (ORDER_STATUSES[$orderStatus] ?? ucfirst($orderStatus))
    . ' · placed ' . format_date((string) $order['created_at']);
// 'Shipping' leads to the shipments list, not the Integrations page: that one
// needs settings.view, and this screen is opened by orders-only roles too.
$breadcrumbs  = [
    ['label' => 'Dashboard', 'url' => admin_url('dashboard.php')],
    ['label' => 'Shipping',  'url' => admin_url('shipping/shipments.php')],
    ['label' => (string) $order['order_number']],
];
$pageActions = '<a class="ad-btn" href="' . e(admin_url('orders/view.php?id=' . $orderId)) . '">'
    . icon('file-text', 'w-4 h-4') . ' Order details</a>';

require ADMIN_PATH . '/includes/header.php';
?>

<style>
    /* Label-over-value facts, wrapping to one column on a phone. */
    .bk-facts { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 16px 22px; margin: 0; }
    .bk-facts dt { font-size: 11.5px; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; color: var(--ad-muted); margin-bottom: 4px; }
    .bk-facts dd { margin: 0; font-size: 13.5px; line-height: 1.6; overflow-wrap: anywhere; }
    .bk-items { list-style: none; margin: 0; padding: 0; display: grid; gap: 6px; font-size: 13.5px; }
    .bk-items li { display: flex; justify-content: space-between; gap: 12px; }
    .bk-actions { display: flex; flex-wrap: wrap; gap: 12px 16px; align-items: flex-end; padding: 14px 18px; border-top: 1px solid var(--ad-border); background: var(--ad-bg); }
    .bk-inline { display: flex; flex-wrap: wrap; gap: 8px; align-items: flex-end; margin: 0; }
    .bk-inline .sik-input { flex: 1 1 170px; min-width: 0; }
    .bk-inline--grow { flex: 1 1 320px; }
    .bk-errors { margin: 0; padding-left: 18px; font-size: 13px; line-height: 1.7; }
    .bk-stale { margin: 0; font-size: 13px; color: var(--ad-danger, #B91C1C); }
    .bk-stale[hidden] { display: none; }
    .bk-rate-label { cursor: pointer; }
</style>

<div class="ad-container">

    <!-- ================= 1. What is being shipped ================= -->
    <div class="ad-card">
        <div class="ad-card__head">
            <div>
                <h2 class="ad-card__title">Order <?= e((string) $order['order_number']) ?></h2>
                <div class="ad-card__sub">Placed <?= e(format_datetime((string) $order['created_at'])) ?></div>
            </div>
            <?= admin_status_badge($orderStatus) ?>
        </div>
        <div class="ad-card__body">
            <dl class="bk-facts">
                <div>
                    <dt>Customer</dt>
                    <dd>
                        <?= e((string) $order['customer_name']) ?><br>
                        <span class="ad-muted"><?= e((string) $order['customer_email']) ?></span><br>
                        <span class="ad-muted"><?= e((string) $order['customer_phone']) ?></span>
                    </dd>
                </div>
                <div>
                    <dt>Ship to</dt>
                    <dd>
                        <strong><?= e((string) $order['shipping_name']) ?></strong><br>
                        <?= e((string) $order['shipping_address']) ?><br>
                        <?php if (!empty($order['shipping_address2'])): ?><?= e((string) $order['shipping_address2']) ?><br><?php endif; ?>
                        <?php if (!empty($order['shipping_landmark'])): ?>
                            <?php // As the customer wrote it: they type their own "opposite", "behind" or "near". ?>
                            <span class="ad-muted">Landmark: <?= e((string) $order['shipping_landmark']) ?></span><br>
                        <?php endif; ?>
                        <?= e((string) $order['shipping_city']) ?>, <?= e((string) $order['shipping_state']) ?><br>
                        PIN <strong class="ad-mono"><?= e((string) $order['shipping_pincode']) ?></strong>
                        &middot; <?= e((string) $order['shipping_phone']) ?>
                    </dd>
                </div>
                <div>
                    <dt>Payment</dt>
                    <dd>
                        <?php // is_cod is shipping_order_owes_cod(): what the courier is told to
                              // collect, not how the order was placed. A COD order already paid
                              // ships as prepaid, so name both facts - "Prepaid" alone told the
                              // admin an order was card-paid when the cash is already in the till. ?>
                        <?php if ($order['is_cod']): ?>
                            <strong>Cash on delivery</strong><br>
                            Collect <?= e(money((float) $order['total_amount'])) ?>
                        <?php elseif (strtolower((string) ($order['payment_method'] ?? '')) === 'cod'): ?>
                            <strong>Cash on delivery - already collected</strong> &middot; <?= e(money((float) $order['total_amount'])) ?><br>
                            <?= admin_state_badge((string) $order['payment_status']) ?><br>
                            <span class="ad-muted">Booked as prepaid: the courier collects nothing at the door.</span>
                        <?php else: ?>
                            <strong>Prepaid</strong> &middot; <?= e(money((float) $order['total_amount'])) ?><br>
                            <?= admin_state_badge((string) $order['payment_status']) ?>
                        <?php endif; ?>
                    </dd>
                </div>
                <div>
                    <dt>Estimated parcel</dt>
                    <dd>
                        <?= e($grams((int) $order['estimated_grams'])) ?><br>
                        <span class="ad-muted">From catalogue weights; an item with none counts as 400 g.</span>
                    </dd>
                </div>
            </dl>

            <h3 class="sik-label" style="margin:18px 0 8px">Items</h3>
            <?php if ($order['items'] === []): ?>
                <p class="ad-muted" style="margin:0">No items are recorded against this order.</p>
            <?php else: ?>
                <ul class="bk-items">
                    <?php foreach ($order['items'] as $item): ?>
                        <li>
                            <span>
                                <?= e((string) $item['product_name']) ?>
                                <span class="ad-muted ad-mono" style="font-size:12px"><?= e((string) $item['sku']) ?></span>
                            </span>
                            <strong style="white-space:nowrap">&times; <?= (int) $item['quantity'] ?></strong>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($live !== null): ?>
        <?php
        // ================= 2. The consignment holding the order =================
        $status  = (string) $live['status'];
        $hasAwb  = (string) ($live['awb'] ?? '') !== '';
        $liveId  = (int) $live['id'];

        // Which buttons make sense. The service refuses the rest anyway; hiding
        // them means an admin is not invited to press something that must fail.
        // Cancel stops at pickup: once the courier holds the parcel, getting it
        // back is an RTO, not a cancellation. A 'pending' row is a courier call
        // still in flight, and shipping_cancel() releases it only once it has
        // outlived any request (SHIPPING_PENDING_STALE_MINUTES) - before that it
        // refuses with "still being made", so the button waits as long.
        $awaitingPickup = in_array($status, ['ready', 'booked', 'pickup_scheduled'], true);
        $pendingStale   = $status === 'pending' && shipping_pending_is_stale($live);
        $show = [
            'awb'     => $status === 'ready' && !$hasAwb,
            'label'   => $hasAwb && $awaitingPickup,
            'pickup'  => $hasAwb && $awaitingPickup,
            'refresh' => $hasAwb,
            'cancel'  => $pendingStale || $awaitingPickup,
        ];
        $anyAction = $canEdit && in_array(true, $show, true);

        $hidden = static function (string $action) use ($liveId, $returnPath): string {
            return csrf_field()
                . '<input type="hidden" name="action" value="' . e_attr($action) . '">'
                . '<input type="hidden" name="shipment_id" value="' . $liveId . '">'
                . '<input type="hidden" name="return" value="' . e_attr($returnPath) . '">';
        };
        $actionUrl = admin_url('shipping/action.php');
        // Only an http(s) link is rendered as one: escaping stops markup, not a
        // javascript: URL, and this value is stored per shipment.
        $trackUrl  = (string) ($live['tracking_url'] ?? '');
        $trackUrl  = preg_match('~^https?://~i', $trackUrl) === 1 ? $trackUrl : '';
        // The courier's own label and pickup manifest, once fetched (Print
        // label; the shipments list's Courier manifest). https only, as above.
        $courierDocs = array_filter([
            'Courier label'    => (string) ($live['label_url'] ?? ''),
            'Courier manifest' => (string) ($live['manifest_url'] ?? ''),
        ], static fn (string $url): bool => preg_match('~^https://~i', $url) === 1);
        ?>
        <div class="ad-card">
            <div class="ad-card__head">
                <div>
                    <h2 class="ad-card__title">Current shipment</h2>
                    <div class="ad-card__sub">
                        #<?= $liveId ?> &middot; booked <?= e(format_datetime((string) $live['created_at'])) ?>
                    </div>
                </div>
                <?= $badge($status) ?>
            </div>

            <div class="ad-card__body">
                <?php if ($status === 'pending'): ?>
                    <?php
                    // Two sentences, one per side of the stale line, so the page
                    // never offers a Cancel the service would refuse - nor hides
                    // the one way a dead booking frees the order.
                    ?>
                    <div class="sik-alert sik-alert--warning" style="margin:0 0 16px">
                        <?= icon('alert', 'w-5 h-5') ?>
                        <div>
                            <?php if ($pendingStale): ?>
                                This booking never finished: the courier was called more than
                                <?= (int) SHIPPING_PENDING_STALE_MINUTES ?> minutes ago and no answer was recorded.
                                Cancel releases it - then check the courier panel for a consignment it may have
                                created before booking again.
                            <?php else: ?>
                                This booking is still being made: the courier was called and has not answered yet.
                                <?= e('If it has not finished within ' . SHIPPING_PENDING_STALE_MINUTES
                                    . ' minutes, Cancel releases it - then check the courier panel for a consignment it may have created.') ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <dl class="bk-facts">
                    <div>
                        <dt>Provider</dt>
                        <dd><?= e((string) ($providerNames[(string) $live['provider_code']] ?? $live['provider_code'])) ?></dd>
                    </div>
                    <div>
                        <dt>Courier</dt>
                        <dd>
                            <?= (string) ($live['courier_name'] ?? '') !== '' ? e((string) $live['courier_name']) : '<span class="ad-muted">Not assigned yet</span>' ?>
                            <?php if ((float) ($live['shipping_charge'] ?? 0) > 0): ?>
                                <br><span class="ad-muted">Quoted <?= e(money((float) $live['shipping_charge'])) ?> at booking</span>
                            <?php endif; ?>
                        </dd>
                    </div>
                    <div>
                        <dt>AWB</dt>
                        <dd>
                            <?php if ($hasAwb): ?>
                                <span class="ad-mono"><?= e((string) $live['awb']) ?></span>
                                <?php if (strncmp($trackUrl, 'https://', 8) === 0): ?>
                                    <br><a href="<?= e($trackUrl) ?>" target="_blank" rel="noopener noreferrer">
                                        Courier tracking page <?= icon('external', 'w-3 h-3') ?>
                                    </a>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="ad-muted">None yet</span>
                            <?php endif; ?>
                        </dd>
                    </div>
                    <div>
                        <dt>Status</dt>
                        <dd>
                            <?= $badge($status) ?>
                            <?php if ((string) ($live['status_detail'] ?? '') !== ''): ?>
                                <br><span class="ad-muted"><?= e((string) $live['status_detail']) ?></span>
                            <?php endif; ?>
                        </dd>
                    </div>
                    <div>
                        <dt>Pickup</dt>
                        <dd><?= !empty($live['pickup_date']) ? e(format_date((string) $live['pickup_date'])) : '<span class="ad-muted">Not scheduled</span>' ?></dd>
                    </div>
                    <div>
                        <dt>Parcel</dt>
                        <dd>
                            <?= e($grams((int) $live['weight_grams'])) ?>
                            <?php if ($live['length_cm'] !== null && $live['width_cm'] !== null && $live['height_cm'] !== null): ?>
                                &middot; <?= e((float) $live['length_cm'] . ' × ' . (float) $live['width_cm'] . ' × ' . (float) $live['height_cm'] . ' cm') ?>
                            <?php endif; ?>
                            <?php if ((int) $live['is_cod'] === 1): ?>
                                <br>COD <?= e(money((float) $live['cod_amount'])) ?>
                            <?php endif; ?>
                        </dd>
                    </div>
                    <?php if ($courierDocs !== []): ?>
                        <div>
                            <dt>Courier documents</dt>
                            <dd>
                                <?php foreach ($courierDocs as $docLabel => $docUrl): ?>
                                    <a href="<?= e($docUrl) ?>" target="_blank" rel="noopener noreferrer">
                                        <?= e($docLabel) ?> <?= icon('external', 'w-3 h-3') ?>
                                    </a><br>
                                <?php endforeach; ?>
                            </dd>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($live['expected_at']) || !empty($live['shipped_at']) || !empty($live['delivered_at'])): ?>
                        <div>
                            <dt>Dates</dt>
                            <dd>
                                <?php if (!empty($live['shipped_at'])): ?>Shipped <?= e(format_datetime((string) $live['shipped_at'])) ?><br><?php endif; ?>
                                <?php if (!empty($live['expected_at'])): ?>Expected <?= e(format_date((string) $live['expected_at'])) ?><br><?php endif; ?>
                                <?php if (!empty($live['delivered_at'])): ?>Delivered <?= e(format_datetime((string) $live['delivered_at'])) ?><?php endif; ?>
                            </dd>
                        </div>
                    <?php endif; ?>
                </dl>
            </div>

            <?php if ($anyAction): ?>
                <div class="bk-actions">
                    <?php if ($show['awb']): ?>
                        <form method="post" action="<?= e($actionUrl) ?>" class="bk-inline" data-once>
                            <?= $hidden('awb') ?>
                            <?php
                            // The sub-courier chosen at booking. A retry without it
                            // lets an aggregator pick its own default - often a
                            // dearer one than the rate the admin chose.
                            ?>
                            <?php if ((string) ($live['courier_id'] ?? '') !== ''): ?>
                                <input type="hidden" name="courier_id" value="<?= e_attr((string) $live['courier_id']) ?>">
                            <?php endif; ?>
                            <button type="submit" class="ad-btn ad-btn--primary"><?= icon('tag', 'w-4 h-4') ?> Request AWB</button>
                        </form>
                    <?php endif; ?>

                    <?php if ($show['label']): ?>
                        <?php
                        // Through action.php, not straight to label.php: a courier
                        // that hosts its own label (routing and sort codes the hub
                        // cannot draw) is asked for it first. The hub's label is
                        // what prints when the courier has none. New tab, and no
                        // data-once - a reprint is a normal thing to want.
                        ?>
                        <form method="post" action="<?= e($actionUrl) ?>" class="bk-inline" target="_blank">
                            <?= $hidden('label') ?>
                            <button type="submit" class="ad-btn"><?= icon('printer', 'w-4 h-4') ?> Print label</button>
                        </form>
                    <?php endif; ?>

                    <?php if ($show['refresh']): ?>
                        <form method="post" action="<?= e($actionUrl) ?>" class="bk-inline" data-once>
                            <?= $hidden('refresh') ?>
                            <button type="submit" class="ad-btn"><?= icon('refresh', 'w-4 h-4') ?> Refresh tracking</button>
                        </form>
                    <?php endif; ?>

                    <?php if ($show['pickup']): ?>
                        <form method="post" action="<?= e($actionUrl) ?>" class="bk-inline bk-inline--grow" data-once>
                            <?= $hidden('pickup') ?>
                            <div class="ad-field" style="flex:1 1 170px;min-width:0">
                                <label class="sik-label" for="pickupDate">Pickup date</label>
                                <?php // The window action.php accepts: today to 30 days out. ?>
                                <input class="sik-input" type="date" id="pickupDate" name="pickup_date"
                                       min="<?= e_attr(date('Y-m-d')) ?>" max="<?= e_attr(date('Y-m-d', strtotime('+30 days'))) ?>"
                                       value="<?= e_attr((string) ($live['pickup_date'] ?? '')) ?>">
                            </div>
                            <button type="submit" class="ad-btn">
                                <?= icon('calendar', 'w-4 h-4') ?> <?= !empty($live['pickup_date']) ? 'Reschedule pickup' : 'Schedule pickup' ?>
                            </button>
                            <span class="sik-help" style="flex-basis:100%;margin:0">Blank asks the courier for its next slot.</span>
                        </form>
                    <?php endif; ?>

                    <?php if ($show['cancel']): ?>
                        <form method="post" action="<?= e($actionUrl) ?>" class="bk-inline bk-inline--grow" data-once>
                            <?= $hidden('cancel') ?>
                            <div class="ad-field" style="flex:1 1 170px;min-width:0">
                                <label class="sik-label" for="cancelReason">Cancel reason</label>
                                <input class="sik-input" type="text" id="cancelReason" name="reason" maxlength="200"
                                       placeholder="e.g. switching courier">
                            </div>
                            <?php
                            // Releasing a dead booking calls no courier, so the
                            // confirm says where to look instead of "void".
                            $cancelConfirm = $pendingStale
                                ? 'Release this booking that never finished? The order can be booked again - first check the courier panel for a consignment it may have created.'
                                : 'Cancel this shipment' . ($hasAwb ? ' and void AWB ' . (string) $live['awb'] : '')
                                    . '? The order stays open and can be booked again.';
                            ?>
                            <button type="submit" class="ad-btn ad-btn--danger" data-confirm="<?= e_attr($cancelConfirm) ?>">
                                <?= icon('close', 'w-4 h-4') ?> Cancel shipment
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="ad-card">
            <div class="ad-card__head">
                <div>
                    <h2 class="ad-card__title">Tracking timeline</h2>
                    <div class="ad-card__sub">Oldest first. Late or repeated courier updates are recorded here but never move the status backwards.</div>
                </div>
            </div>
            <?php if ($events === []): ?>
                <?= admin_empty('No tracking events yet', 'Events appear as the courier reports them, or when tracking is refreshed.', null, null, 'clock') ?>
            <?php else: ?>
                <div class="ad-tablewrap">
                    <table class="ad-table">
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>Status</th>
                                <th>Message</th>
                                <th>Location</th>
                                <th>Source</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($events as $event): ?>
                                <tr>
                                    <td style="white-space:nowrap"><?= e(format_datetime((string) $event['occurred_at'])) ?></td>
                                    <td><?= $badge((string) $event['status']) ?></td>
                                    <td><?= e((string) $event['message']) ?></td>
                                    <td class="ad-muted"><?= (string) $event['location'] !== '' ? e((string) $event['location']) : '&mdash;' ?></td>
                                    <td class="ad-muted"><?= e($sourceLabels[(string) $event['source']] ?? (string) $event['source']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

    <?php elseif ($blockReason !== null): ?>
        <!-- ================= 3a. Nothing to book: the service would refuse ================= -->
        <div class="ad-card">
            <div class="ad-card__head"><div><h2 class="ad-card__title">This order cannot be shipped</h2></div></div>
            <div class="ad-card__body">
                <p style="margin:0 0 8px">
                    <?= e($blockReason) ?>
                    <?php if ($orderStatus === ORDER_STATUS_CANCELLED && !empty($order['cancelled_at'])): ?>
                        Cancelled <?= e(format_datetime((string) $order['cancelled_at'])) ?>.
                    <?php endif; ?>
                </p>
                <p class="ad-muted" style="margin:0 0 8px">
                    No courier is quoted or booked until that changes on the order.
                </p>
                <?php if ($orderStatus === ORDER_STATUS_CANCELLED && (string) ($order['cancel_reason'] ?? '') !== ''): ?>
                    <p class="ad-muted" style="margin:0">Reason: <?= e((string) $order['cancel_reason']) ?></p>
                <?php elseif ($orderStatus === ORDER_STATUS_RETURNED && (string) ($order['return_reason'] ?? '') !== ''): ?>
                    <p class="ad-muted" style="margin:0">Return reason: <?= e((string) $order['return_reason']) ?></p>
                <?php endif; ?>
            </div>
        </div>

    <?php elseif ($available === []): ?>
        <!-- ================= 3b. Nothing to book with ================= -->
        <div class="ad-card">
            <div class="ad-card__head"><div><h2 class="ad-card__title">No courier is live</h2></div></div>
            <div class="ad-card__body">
                <p style="margin:0 0 12px">
                    Rates and booking need at least one courier integration that is switched on and has a
                    pickup PIN code. None is active right now.
                </p>
                <?php // Integrations needs settings.view; an orders-only role would get a 403 from the button. ?>
                <?php if (admin_can('settings.view')): ?>
                    <a class="ad-btn ad-btn--primary" href="<?= e(admin_url('shipping/')) ?>">
                        <?= icon('settings', 'w-4 h-4') ?> Open shipping integrations
                    </a>
                <?php else: ?>
                    <p class="ad-muted" style="margin:0">Ask an admin with settings access to set up a courier.</p>
                <?php endif; ?>
            </div>
        </div>

    <?php elseif (!$canEdit): ?>
        <div class="ad-card">
            <div class="ad-card__body ad-muted">
                This order has no shipment yet. Your role can view shipments but not book them.
            </div>
        </div>

    <?php else: ?>
        <?php
        // ================= 3c. Book it =================
        // Not refusals - the service books these (an unpaid prepaid order is a
        // refusal, shown above instead) - but each is something an admin would
        // want to see before spending a booking.
        $cautions = [];
        if ($orderStatus === ORDER_STATUS_PENDING) {
            $cautions[] = 'This order is still pending: it has not been confirmed.';
        }
        if (shipping_order_rank($orderStatus) >= shipping_order_rank(ORDER_STATUS_SHIPPED)) {
            $cautions[] = 'This order is already marked ' . strtolower(ORDER_STATUSES[$orderStatus] ?? $orderStatus)
                . '. Booking now sends a second consignment.';
        }
        if ((string) ($order['tracking_number'] ?? '') !== '') {
            $cautions[] = 'The order already carries tracking number ' . (string) $order['tracking_number']
                . ((string) ($order['courier_name'] ?? '') !== '' ? ' (' . (string) $order['courier_name'] . ')' : '')
                . ', entered by hand. A booking here replaces it.';
        }

        $fieldValue = static function (string $key) use ($parcelRaw, $parcel, $parcelErrors): string {
            if ($parcelRaw[$key] !== '') {
                return $parcelRaw[$key];
            }
            // An estimate that failed the bounds is not offered as the value:
            // the box's own max would stop the form, and the admin has to
            // weigh the parcel anyway.
            if (isset($parcelErrors[$key])) {
                return '';
            }
            return $parcel[$key] === null ? '' : (string) $parcel[$key];
        };
        $rates       = $quote['rates'] ?? [];
        $quoteErrors = $quote['errors'] ?? [];
        $anyLive     = in_array('live', $providerModes, true);
        // Split once: the note names only quote-only couriers that actually
        // quoted, and Book is drawn only when at least one rate can be picked.
        $quotedOnly = [];
        $bookable   = 0;
        foreach ($rates as $rate) {
            $code = (string) $rate['provider'];
            if (isset($quoteOnlyNames[$code])) {
                $quotedOnly[$code] = (string) $quoteOnlyNames[$code];
            } else {
                $bookable++;
            }
        }
        $canSeeSettings = admin_can('settings.view');
        ?>
        <div class="ad-card">
            <div class="ad-card__head">
                <div>
                    <h2 class="ad-card__title">Book a shipment</h2>
                    <div class="ad-card__sub">Weigh the packed parcel, get rates, pick a courier.</div>
                </div>
            </div>

            <div class="ad-card__body">
                <?php foreach ($cautions as $caution): ?>
                    <div class="sik-alert sik-alert--warning" style="margin:0 0 12px">
                        <?= icon('alert', 'w-5 h-5') ?><div><?= e($caution) ?></div>
                    </div>
                <?php endforeach; ?>

                <form method="get" action="<?= e(admin_url('shipping/book.php')) ?>" data-parcel-form>
                    <input type="hidden" name="order" value="<?= $orderId ?>">
                    <div class="ad-grid ad-grid--4" style="gap:12px">
                        <?php
                        $parcelFields = [
                            'weight_grams' => ['Weight (g)', '1', '50', '50000'],
                            'length_cm'    => ['Length (cm)', '0.1', '1', '300'],
                            'width_cm'     => ['Width (cm)', '0.1', '1', '300'],
                            'height_cm'    => ['Height (cm)', '0.1', '1', '300'],
                        ];
                        ?>
                        <?php foreach ($parcelFields as $key => [$label, $step, $min, $max]): ?>
                            <div class="ad-field">
                                <label class="sik-label" for="parcel_<?= e_attr($key) ?>">
                                    <?= e($label) ?><?php if ($key !== 'weight_grams'): ?> <span class="ad-muted">optional</span><?php endif; ?>
                                </label>
                                <input class="sik-input<?= isset($parcelErrors[$key]) ? ' is-invalid' : '' ?>"
                                       id="parcel_<?= e_attr($key) ?>" name="<?= e_attr($key) ?>" type="number"
                                       inputmode="decimal" step="<?= e_attr($step) ?>" min="<?= e_attr($min) ?>" max="<?= e_attr($max) ?>"
                                       value="<?= e_attr($fieldValue($key)) ?>"<?= $key === 'weight_grams' ? ' required' : '' ?>>
                                <?php if (isset($parcelErrors[$key])): ?>
                                    <span class="sik-error"><?= e($parcelErrors[$key]) ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php if (isset($parcelErrors['dimensions'])): ?>
                        <span class="sik-error" style="display:block;margin-top:8px"><?= e($parcelErrors['dimensions']) ?></span>
                    <?php endif; ?>
                    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:12px">
                        <button type="submit" class="ad-btn"><?= icon('refresh', 'w-4 h-4') ?> Get rates</button>
                        <span class="sik-help" style="margin:0">
                            Estimated <?= e($grams((int) $order['estimated_grams'])) ?> from the catalogue. Couriers bill the
                            larger of this and the volumetric weight.
                        </span>
                    </div>
                </form>
            </div>

            <?php if ($parcelErrors !== []): ?>
                <div class="ad-card__body" style="border-top:1px solid var(--ad-border)">
                    <p class="ad-muted" style="margin:0">Correct the parcel above to see rates.</p>
                </div>
            <?php else: ?>
                <form method="post" action="<?= e($selfUrl) ?>" data-book-form data-once>
                    <?= csrf_field() ?>
                    <input type="hidden" name="order" value="<?= $orderId ?>">
                    <?php // The parcel the rates below were quoted for - see the docblock. ?>
                    <?php foreach (['weight_grams', 'length_cm', 'width_cm', 'height_cm'] as $key): ?>
                        <?php if ($parcel[$key] !== null): ?>
                            <input type="hidden" name="<?= e_attr($key) ?>" value="<?= e_attr((string) $parcel[$key]) ?>">
                        <?php endif; ?>
                    <?php endforeach; ?>

                    <?php if ($rates === []): ?>
                        <div class="ad-card__body" style="border-top:1px solid var(--ad-border)">
                            <p style="margin:0 0 8px"><strong>No courier can take this parcel.</strong></p>
                            <?php if ($quoteErrors === []): ?>
                                <p class="ad-muted" style="margin:0">No rates came back and no courier said why.</p>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <?php foreach ($quotedOnly as $qoCode => $qoName): ?>
                            <div class="ad-card__body" style="border-top:1px solid var(--ad-border)">
                                <div class="sik-alert sik-alert--info" style="margin:0">
                                    <?= icon('info', 'w-5 h-5') ?>
                                    <div>
                                        <strong><?= e($qoName) ?> is in Test mode and has no sandbox.</strong>
                                        Its rates below come from the real account, but booking with it is refused
                                        until the integration is switched to Live - every booking there is real and billed.
                                        <?php if ($canSeeSettings): ?>
                                            <a href="<?= e(admin_url('shipping/configure.php?code=' . rawurlencode((string) $qoCode))) ?>">Open its settings</a>.
                                        <?php else: ?>
                                            Ask an admin with settings access to switch it to Live.
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <div class="ad-tablewrap" style="border-top:1px solid var(--ad-border)">
                            <table class="ad-table">
                                <thead>
                                    <tr>
                                        <th class="ad-table__check">Pick</th>
                                        <th>Courier</th>
                                        <th>Provider</th>
                                        <th class="ad-table__num">Cost</th>
                                        <th class="ad-table__num">COD fee</th>
                                        <th class="ad-table__num">Delivery</th>
                                        <th class="ad-table__num">Rating</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($rates as $i => $rate): ?>
                                        <?php
                                        $code  = (string) $rate['provider'];
                                        $value = (string) json_encode([
                                            'provider'   => $code,
                                            'courier_id' => isset($rate['courier_id']) ? (string) $rate['courier_id'] : '',
                                            'courier'    => (string) ($rate['courier'] ?? ''),
                                        ]);
                                        $eta      = $rate['eta_days'] ?? null;
                                        $rating   = $rate['rating'] ?? null;
                                        $pickable = !isset($quotedOnly[$code]);
                                        ?>
                                        <tr<?= $pickable ? '' : ' class="ad-muted"' ?>>
                                            <td class="ad-table__check">
                                                <input type="radio" name="rate" id="rate<?= (int) $i ?>" required
                                                       value="<?= e_attr($value) ?>"<?= $pickable ? '' : ' disabled' ?>
                                                       aria-label="<?= e_attr(($pickable ? 'Book with ' : 'Cannot book in Test mode: ') . (string) ($rate['courier'] ?? $code)) ?>">
                                            </td>
                                            <td>
                                                <label class="bk-rate-label" for="rate<?= (int) $i ?>">
                                                    <span class="ad-cellflex__name" style="display:block"><?= e((string) ($rate['courier'] ?? '')) ?></span>
                                                    <?php if ((string) ($rate['service'] ?? '') !== ''): ?>
                                                        <span class="ad-cellflex__meta"><?= e((string) $rate['service']) ?></span>
                                                    <?php endif; ?>
                                                </label>
                                            </td>
                                            <td>
                                                <?= e((string) ($rate['provider_name'] ?? $code)) ?>
                                                <?php if (($providerModes[$code] ?? '') === 'test'): ?>
                                                    <span class="sik-status sik-status--amber">Test</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="ad-table__num"><strong><?= e(money((float) $rate['cost'])) ?></strong></td>
                                            <td class="ad-table__num"><?= (float) ($rate['cod_fee'] ?? 0) > 0 ? e(money((float) $rate['cod_fee'])) : '&mdash;' ?></td>
                                            <td class="ad-table__num">
                                                <?= $eta === null ? '&mdash;' : e((int) $eta . ' day' . ((int) $eta === 1 ? '' : 's')) ?>
                                            </td>
                                            <td class="ad-table__num"><?= $rating === null ? '&mdash;' : e(number_format((float) $rating, 1)) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>

                    <?php if ($quoteErrors !== []): ?>
                        <div class="ad-card__body" style="border-top:1px solid var(--ad-border)">
                            <p class="sik-label" style="margin:0 0 6px">Couriers that did not quote</p>
                            <ul class="bk-errors ad-muted">
                                <?php foreach ($quoteErrors as $message): ?>
                                    <li><?= e((string) $message) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <?php if ($rates !== [] && $bookable === 0): ?>
                        <?php // No Book at all: every press would be refused, see the note above the rates. ?>
                        <div class="ad-card__foot" style="align-items:center">
                            <span class="sik-help" style="margin:0">
                                None of these rates can be booked while <?= e(implode(' and ', $quotedOnly)) ?> is in Test mode.
                            </span>
                        </div>
                    <?php elseif ($rates !== []): ?>
                        <div class="ad-card__foot" style="align-items:center">
                            <p class="bk-stale" data-stale-note hidden>The parcel changed. Get rates again before booking.</p>
                            <?php if ($anyLive): ?>
                                <span class="sik-help" style="margin:0">A live-mode courier bills the booking.</span>
                            <?php endif; ?>
                            <button type="submit" class="ad-btn ad-btn--primary"><?= icon('truck', 'w-4 h-4') ?> Book shipment</button>
                        </div>
                    <?php endif; ?>
                </form>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- ================= 4. Earlier attempts ================= -->
    <?php if ($previous !== []): ?>
        <div class="ad-card">
            <div class="ad-card__head">
                <div>
                    <h2 class="ad-card__title">Previous shipments</h2>
                    <div class="ad-card__sub">Cancelled or failed bookings for this order, newest first, with what the courier said.</div>
                </div>
            </div>
            <div class="ad-tablewrap">
                <table class="ad-table">
                    <thead>
                        <tr>
                            <th>Shipment</th>
                            <th>Courier</th>
                            <th>AWB</th>
                            <th>Status</th>
                            <th>What happened</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($previous as $row): ?>
                            <tr>
                                <td>
                                    <span class="ad-cellflex__name" style="display:block">#<?= (int) $row['id'] ?></span>
                                    <span class="ad-cellflex__meta"><?= e(format_datetime((string) $row['created_at'])) ?></span>
                                </td>
                                <td>
                                    <?= e((string) ($providerNames[(string) $row['provider_code']] ?? $row['provider_code'])) ?>
                                    <?php if ((string) ($row['courier_name'] ?? '') !== ''): ?>
                                        <span class="ad-cellflex__meta" style="display:block"><?= e((string) $row['courier_name']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?= (string) ($row['awb'] ?? '') !== '' ? '<span class="ad-mono">' . e((string) $row['awb']) . '</span>' : '<span class="ad-muted">&mdash;</span>' ?></td>
                                <td><?= $badge((string) $row['status']) ?></td>
                                <td><?= (string) ($row['status_detail'] ?? '') !== '' ? e((string) $row['status_detail']) : '<span class="ad-muted">&mdash;</span>' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
(function () {
    // Book posts the parcel the rates were drawn for. Once the parcel boxes
    // change, those rates describe a different parcel, so hold Book until the
    // admin asks for new ones.
    var parcel = document.querySelector('[data-parcel-form]');
    var book   = document.querySelector('[data-book-form]');
    if (parcel && book) {
        var note   = book.querySelector('[data-stale-note]');
        var submit = book.querySelector('button[type="submit"]');
        parcel.addEventListener('input', function () {
            if (submit) { submit.disabled = true; }
            if (note) { note.hidden = false; }
        });
    }

    // One press, one request. The service would refuse a second booking, but
    // the admin would then read its refusal instead of the first one's success.
    document.querySelectorAll('form[data-once]').forEach(function (form) {
        form.addEventListener('submit', function () {
            form.querySelectorAll('button[type="submit"]').forEach(function (b) {
                b.disabled = true;
                b.dataset.sent = '1';
            });
        });
    });

    // Back/forward restores the page with those buttons still disabled. Only
    // the ones disabled for having been pressed - a Book held for a changed
    // parcel stays held.
    window.addEventListener('pageshow', function (event) {
        if (!event.persisted) { return; }
        document.querySelectorAll('button[data-sent]').forEach(function (b) {
            b.disabled = false;
            delete b.dataset.sent;
        });
    });
}());
</script>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
