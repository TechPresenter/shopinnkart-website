<?php
/**
 * ShopInnKart Admin - Shipping > Tracking.
 *
 * The screen an operator opens with a customer on the phone asking "where is
 * my parcel". They will have whatever the customer read out - an AWB, an order
 * number from the email, a name, a phone number - so there is one box that
 * takes any of them rather than a form with a field per identifier.
 *
 * Why the search behaves the way it does:
 *
 *   - An exact AWB or order number wins outright. "SIK-1001" also LIKE-matches
 *     SIK-10010..SIK-10019; showing a list of eleven when the operator typed a
 *     complete identifier is noise, so the exact pass runs first and the fuzzy
 *     pass only when it finds nothing.
 *
 *   - Phones are compared as digits, last ten. Customers say "+91 98765-43210",
 *     checkout stored "9876543210", and an older import stored "098765 43210";
 *     all three are one number. Both sides are normalised - the typed term in
 *     PHP, the stored columns with REGEXP_REPLACE - because normalising only one
 *     side still misses whichever form the other side happens to be in.
 *
 *   - One match is shown directly. Several get a compact pick list, because the
 *     common case of several is one order booked twice (a cancelled consignment
 *     and its replacement) and the operator needs to see both statuses to know
 *     which one the customer means.
 *
 * Read-only. The actions here, "Refresh from courier" and (while the parcel is
 * still with us) "Print label", POST to action.php, which calls the service -
 * the timeline is only ever written by the service layer, whose forward-only
 * and idempotency rules a screen writing rows itself would bypass.
 *
 * Permission: orders.view to look, orders.edit to refresh - the same keys every
 * other shipping screen uses (see index.php for why there is no shipping.* key).
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('orders.view');

require_once INCLUDES_PATH . '/shipping-service.php';

$canEdit = admin_can('orders.edit');

// A crafted ?q[]=x arrives as an array; treat it as no search rather than
// letting a string cast print "Array" and a warning.
$q = is_string($_GET['q'] ?? null) ? mb_substr(trim((string) $_GET['q']), 0, 100) : '';
$shipmentId = input_int('shipment');

// Customers see their order as "#SIK202609210002" in emails and on the account
// page, so that is what gets pasted. No AWB, name or phone starts with "#".
$term = trim(ltrim($q, '#'));

$pickLimit = 50;
$listSelect =
    'SELECT s.`id`, s.`order_id`, s.`awb`, s.`courier_name`, s.`provider_code`, s.`status`, s.`updated_at`,
            o.`order_number`, o.`customer_name`, o.`shipping_name`, o.`shipping_city`, o.`shipping_pincode`,
            p.`name` AS provider_name
       FROM `shipments` s
       LEFT JOIN `orders` o ON o.`id` = s.`order_id`
       LEFT JOIN `shipping_providers` p ON p.`code` = s.`provider_code`';

// ---------------------------------------------------------------------------
//  Search
// ---------------------------------------------------------------------------

$results     = [];
$moreResults = false;
$orderNoShip = null;   // an order that matched by number but was never booked

if ($term !== '') {
    // Pass 1: the operator typed a whole identifier. The column collation is
    // case-insensitive, so "mk0188..." finds "MK0188...".
    $results = Database::fetchAll(
        $listSelect . ' WHERE s.`awb` = :x_awb OR o.`order_number` = :x_number
          ORDER BY s.`id` DESC LIMIT ' . ($pickLimit + 1),
        ['x_awb' => $term, 'x_number' => $term]
    );

    if ($results === []) {
        // Pass 2: any fragment. LIKE wildcards in the term are escaped, so a
        // search for "50%" or "a_b" means those characters, not "anything".
        // Native prepares cannot reuse one placeholder, hence one per column.
        $like   = '%' . addcslashes($term, '%_\\') . '%';
        $where  = ['s.`awb` LIKE :q_awb', 'o.`order_number` LIKE :q_number', 'o.`customer_name` LIKE :q_customer',
                   'o.`shipping_name` LIKE :q_recipient', 's.`courier_name` LIKE :q_courier', 'p.`name` LIKE :q_provider'];
        $params = ['q_awb' => $like, 'q_number' => $like, 'q_customer' => $like,
                   'q_recipient' => $like, 'q_courier' => $like, 'q_provider' => $like];

        // Phone matching only for a term that could be a phone - digits and
        // the separators people type between them. "Rahul 2" is not a phone,
        // and matching its "2" against every number on file would bury the
        // name match under noise.
        $digits = (string) preg_replace('/\D+/', '', $term);
        if (preg_match('/^[\d\s().+\-]+$/', $term) === 1) {
            if (strlen($digits) >= 10) {
                // Last ten drops a +91 or a trunk 0 from either side.
                $where[] = "RIGHT(REGEXP_REPLACE(o.`customer_phone`, '[^0-9]', ''), 10) = :p_customer";
                $where[] = "RIGHT(REGEXP_REPLACE(o.`shipping_phone`, '[^0-9]', ''), 10) = :p_recipient";
                $params['p_customer'] = $params['p_recipient'] = substr($digits, -10);
            } elseif (strlen($digits) >= 6) {
                // A partial number ("the one ending 43210"). Six digits keeps a
                // PIN-sized fragment useful without matching half the book.
                $where[] = "REGEXP_REPLACE(o.`customer_phone`, '[^0-9]', '') LIKE :p_customer";
                $where[] = "REGEXP_REPLACE(o.`shipping_phone`, '[^0-9]', '') LIKE :p_recipient";
                $params['p_customer'] = $params['p_recipient'] = '%' . $digits . '%';
            }
        }

        $results = Database::fetchAll(
            $listSelect . ' WHERE ' . implode(' OR ', $where) . '
              ORDER BY s.`id` DESC LIMIT ' . ($pickLimit + 1),
            $params
        );
    }

    if (count($results) > $pickLimit) {
        $moreResults = true;
        $results     = array_slice($results, 0, $pickLimit);
    }

    // Nothing shipped, but the term is an order number: say so and offer the
    // booking screen, instead of implying the order does not exist.
    if ($results === []) {
        $orderNoShip = get_order_by_number($term);
    }

    if ($shipmentId === 0 && count($results) === 1) {
        $shipmentId = (int) $results[0]['id'];
    }
}

// ---------------------------------------------------------------------------
//  The selected shipment
// ---------------------------------------------------------------------------

$shipment = $shipmentId > 0 ? shipment_get($shipmentId) : null;
$order    = null;
$events   = [];
$siblings = [];

if ($shipment !== null) {
    $order    = get_order((int) $shipment['order_id']);
    $provider = shipping_provider((string) $shipment['provider_code']);

    // Stored oldest first, which is the order they happened in. Read newest
    // first, because "where is it now" is the question this screen answers.
    $events = array_reverse(shipment_events((int) $shipment['id']));

    // Other consignments on the same order - usually a cancelled one and its
    // replacement. The customer's AWB may be either.
    $siblings = array_values(array_filter(
        shipments_for_order((int) $shipment['order_id']),
        static fn (array $row): bool => (int) $row['id'] !== (int) $shipment['id']
    ));
}

// The landing view: nothing searched, nothing (findable) picked. Show what
// moved last, so the page is useful before anything is typed.
$recent = ($q === '' && $shipment === null)
    ? Database::fetchAll($listSelect . ' ORDER BY s.`updated_at` DESC, s.`id` DESC LIMIT 10')
    : [];

/**
 * This page's query string for one shipment, carrying the search so "back to
 * results" still works. Built by hand rather than array_filter()ed, which
 * would silently drop a search for "0".
 */
$trackQuery = static function (int $id) use ($q): string {
    return http_build_query(['shipment' => $id] + ($q !== '' ? ['q' => $q] : []));
};
$trackUrl = static fn (int $id): string => admin_url('shipping/track.php?' . $trackQuery($id));

$pageTitle    = 'Shipment Tracking';
$pageSubtitle = 'Find a consignment by AWB, order number, customer, phone or courier.';
// 'Shipping' lands on the shipments list: the Integrations page it used to
// open needs settings.view, which an orders-only role tracking a parcel lacks.
$breadcrumbs  = [
    ['label' => 'Dashboard', 'url' => admin_url('dashboard.php')],
    ['label' => 'Shipping',  'url' => admin_url('shipping/shipments.php')],
    ['label' => 'Tracking'],
];

require ADMIN_PATH . '/includes/header.php';
?>

<style>
/* Scoped to this page. The shared .sik-timeline is a done/current order rail
   with one brand colour; a courier timeline needs a colour per status, and
   changing the shared one would restyle order-success and the order view. */
.trk-search { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
.trk-search .ad-search { flex: 1 1 260px; max-width: none; }
/* 16px, not the filter bar's 13px: this is the page's one control, and under
   16px iOS zooms the layout on focus. */
.trk-search .ad-search input { font-size: 16px; padding-top: 11px; padding-bottom: 11px; }
.trk-search__hint { flex-basis: 100%; margin: 2px 0 0; font-size: 12px; color: var(--ad-muted); }

.trk-headline { display: flex; align-items: center; gap: 8px 12px; flex-wrap: wrap; min-width: 0; }
.trk-awb { margin: 0; font-size: 17px; font-weight: 700; overflow-wrap: anywhere; }
.trk-detail { margin: 0 0 16px; font-size: 13px; color: var(--ad-muted); }

/* auto-fill with a 140px floor: two columns at 390px, as many as fit above. */
.trk-facts { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 14px 20px; margin: 0; }
.trk-facts dt { margin: 0 0 3px; font-size: 11px; font-weight: 600; letter-spacing: .05em; text-transform: uppercase; color: var(--ad-muted); }
.trk-facts dd { margin: 0; font-size: 13.5px; overflow-wrap: anywhere; }

/* --- the timeline: a left rule, a dot per event coloured by its tone ------ */
.trk-tl { list-style: none; margin: 0; padding: 0 0 0 24px; }
.trk-tl__item { position: relative; padding-bottom: 18px; min-width: 0; }
.trk-tl__item:last-child { padding-bottom: 0; }
/* The rule is drawn per item, from under this dot to just above the next, so
   it stops at the oldest event instead of running past it. Centre line at
   -15px: the dot spans -22..-8, the 2px rule -16..-14. */
.trk-tl__item:not(:last-child)::before {
    content: "";
    position: absolute;
    left: -16px; top: 22px; bottom: 2px;
    width: 2px;
    border-radius: 2px;
    background: var(--ad-border);
}
/* The dot carries a sik-status--{tone} class purely to inherit that tone's
   --tone colour, so the dots follow a re-themed status palette with the
   pills. The pill rule's tinted background and ring are overridden here. */
.trk-tl .trk-tl__dot {
    position: absolute;
    left: -22px; top: 4px;
    width: 14px; height: 14px;
    border-radius: 50%;
    background: var(--tone, var(--ad-faint));
    box-shadow: 0 0 0 3px var(--ad-surface);
}
.trk-tl .trk-tl__item:first-child .trk-tl__dot {
    box-shadow: 0 0 0 3px var(--ad-surface), 0 0 0 6px color-mix(in srgb, var(--tone, var(--ad-faint)) 28%, transparent);
}
.trk-tl__head { display: flex; align-items: center; gap: 4px 10px; flex-wrap: wrap; }
.trk-tl__time { font-size: 12px; color: var(--ad-muted); }
.trk-tl__msg { margin: 5px 0 0; font-size: 13.5px; overflow-wrap: anywhere; }
.trk-tl__meta { display: flex; align-items: center; gap: 4px 12px; flex-wrap: wrap; margin-top: 4px; font-size: 12px; color: var(--ad-muted); }
.trk-tl__meta svg { vertical-align: -2px; }
.trk-tl__src {
    padding: 1px 7px;
    border: 1px solid var(--ad-border);
    border-radius: 999px;
    background: var(--ad-surface-alt);
    font-size: 10.5px;
    letter-spacing: .05em;
    text-transform: uppercase;
}
</style>

<div class="ad-container">

    <!-- ============================ Search ============================ -->
    <div class="ad-card">
        <div class="ad-card__body">
            <form class="trk-search" method="get" action="<?= e(admin_url('shipping/track.php')) ?>" role="search">
                <span class="ad-search">
                    <?= icon('search', 'w-4 h-4') ?>
                    <label class="sik-sr" for="trackSearch">Search shipments</label>
                    <input class="sik-input" type="search" id="trackSearch" name="q"
                           value="<?= e($q) ?>" maxlength="100" autocomplete="off"
                           placeholder="AWB, order number, customer name, phone or courier"
                           <?= $q === '' && $shipmentId === 0 ? 'autofocus' : '' ?>>
                </span>
                <button type="submit" class="ad-btn ad-btn--primary"><?= icon('search', 'w-4 h-4') ?> Track</button>
                <?php // The matcher strips spaces, dashes and a +91 prefix before comparing,
                      // so "+91 98765-43210", "098765 43210" and "9876543210" are one number. ?>
                <p class="trk-search__hint">Phone numbers match however they are typed.</p>
            </form>
        </div>
    </div>

    <?php if ($shipmentId > 0 && $shipment === null): ?>
        <!-- ======================= Unknown ?shipment ====================== -->
        <div class="ad-card">
            <?= admin_empty(
                'Shipment #' . $shipmentId . ' was not found',
                'It may have been removed, or the link is wrong. Search for it by AWB or order number instead.',
                null, null, 'truck'
            ) ?>
        </div>
    <?php endif; ?>

    <?php if ($shipment !== null): ?>
        <?php
        $sid        = (int) $shipment['id'];
        $status     = (string) $shipment['status'];
        $awb        = (string) ($shipment['awb'] ?? '');
        $courier    = (string) ($shipment['courier_name'] ?? '');
        $provName   = (string) ($provider['name'] ?? $shipment['provider_code']);
        $isCod      = (int) $shipment['is_cod'] === 1;

        // Only an http(s) link is rendered as one. The value comes from a
        // courier driver, and escaping stops markup but not a javascript: URL.
        $publicTrack = (string) ($shipment['tracking_url'] ?? '');
        $publicTrack = preg_match('~^https?://~i', $publicTrack) === 1 ? $publicTrack : '';
        // The courier's own label and manifest, once fetched; https only.
        $courierDocs = array_filter([
            'Label'    => (string) ($shipment['label_url'] ?? ''),
            'Manifest' => (string) ($shipment['manifest_url'] ?? ''),
        ], static fn (string $url): bool => preg_match('~^https://~i', $url) === 1);

        // action.php hands `return` to admin_safe_return(), which accepts only
        // a root-relative path inside the admin and rejects any target
        // containing "..". Dots in the search term are therefore encoded, or a
        // search for "a..b" would bounce the operator to the booking screen.
        $selfPath = (string) parse_url(admin_url('shipping/track.php'), PHP_URL_PATH);
        $returnTo = $selfPath . '?' . str_replace('.', '%2E', $trackQuery($sid));

        $lastEvent = $events[0] ?? null;
        ?>

        <?php if ($q !== '' && count($results) > 1): ?>
            <p style="margin:0 0 12px">
                <a class="ad-btn ad-btn--sm" href="<?= e(admin_url('shipping/track.php?' . http_build_query(['q' => $q]))) ?>">
                    <?= icon('arrow-left', 'w-4 h-4') ?>
                    All <?= count($results) ?><?= $moreResults ? '+' : '' ?> results for &ldquo;<?= e($q) ?>&rdquo;
                </a>
            </p>
        <?php endif; ?>

        <!-- ========================== Header ============================= -->
        <div class="ad-card">
            <div class="ad-card__head">
                <div style="min-width:0">
                    <div class="ad-card__sub">Shipment #<?= $sid ?> &middot; <?= e($provName) ?></div>
                    <div class="trk-headline">
                        <?php if ($awb !== ''): ?>
                            <h2 class="trk-awb ad-mono"><?= e($awb) ?></h2>
                        <?php else: ?>
                            <h2 class="trk-awb">No AWB yet</h2>
                        <?php endif; ?>
                        <span class="sik-status sik-status--<?= e_attr(shipping_status_tone($status)) ?>">
                            <?= e(shipping_status_label($status)) ?>
                        </span>
                    </div>
                    <div class="ad-card__sub">
                        <?= $courier !== '' ? e($courier) : 'Courier not assigned yet' ?>
                        <?php if ($lastEvent !== null): ?>
                            &middot; last update <?= e(time_ago((string) $lastEvent['occurred_at'])) ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="ad-card__body">
                <?php if ((string) ($shipment['status_detail'] ?? '') !== ''): ?>
                    <p class="trk-detail"><?= e((string) $shipment['status_detail']) ?></p>
                <?php endif; ?>

                <dl class="trk-facts">
                    <div>
                        <dt>Order</dt>
                        <dd>
                            <?php if ($order !== null): ?>
                                <a class="ad-mono" href="<?= e(admin_url('shipping/book.php?order=' . (int) $order['id'])) ?>">
                                    <?= e((string) $order['order_number']) ?>
                                </a>
                            <?php else: ?>
                                <span class="ad-muted">Order #<?= (int) $shipment['order_id'] ?> no longer exists</span>
                            <?php endif; ?>
                        </dd>
                    </div>

                    <?php if ($order !== null): ?>
                        <?php
                        $recipient = (string) ($order['shipping_name'] ?: $order['customer_name']);
                        $phone     = (string) ($order['shipping_phone'] ?: $order['customer_phone']);
                        ?>
                        <div>
                            <dt>Customer</dt>
                            <dd>
                                <?= e($recipient) ?>
                                <?php if ($phone !== ''): ?>
                                    <br><span class="ad-muted"><?= e($phone) ?></span>
                                <?php endif; ?>
                                <?php if ((string) $order['customer_name'] !== '' && $order['customer_name'] !== $recipient): ?>
                                    <br><span class="ad-muted">Ordered by <?= e((string) $order['customer_name']) ?></span>
                                <?php endif; ?>
                            </dd>
                        </div>
                        <div>
                            <dt>Destination</dt>
                            <dd>
                                <?= e(implode(', ', array_filter([(string) $order['shipping_city'], (string) $order['shipping_state']]))) ?>
                                <br><span class="ad-mono"><?= e((string) $order['shipping_pincode']) ?></span>
                            </dd>
                        </div>
                    <?php endif; ?>

                    <div>
                        <dt>Payment</dt>
                        <dd><?= $isCod ? 'COD &middot; ' . e(money((float) $shipment['cod_amount'])) : 'Prepaid' ?></dd>
                    </div>
                    <div>
                        <dt>Booked</dt>
                        <dd><?= e(format_datetime((string) $shipment['created_at'])) ?></dd>
                    </div>
                    <?php if (!empty($shipment['pickup_date'])): ?>
                        <div>
                            <dt>Pickup</dt>
                            <dd><?= e(format_date((string) $shipment['pickup_date'])) ?></dd>
                        </div>
                    <?php endif; ?>
                    <div>
                        <dt>Shipped</dt>
                        <dd><?= !empty($shipment['shipped_at']) ? e(format_datetime((string) $shipment['shipped_at'])) : '<span class="ad-muted">&mdash;</span>' ?></dd>
                    </div>
                    <div>
                        <dt>Delivered</dt>
                        <dd><?= !empty($shipment['delivered_at']) ? e(format_datetime((string) $shipment['delivered_at'])) : '<span class="ad-muted">&mdash;</span>' ?></dd>
                    </div>
                    <?php if (!empty($shipment['expected_at']) && empty($shipment['delivered_at'])): ?>
                        <div>
                            <dt>Expected</dt>
                            <dd><?= e(format_date((string) $shipment['expected_at'])) ?></dd>
                        </div>
                    <?php endif; ?>
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
                    <?php if ($publicTrack !== ''): ?>
                        <div>
                            <dt>Public tracking</dt>
                            <dd>
                                <a href="<?= e($publicTrack) ?>" target="_blank" rel="noopener noreferrer">
                                    Courier's page <?= icon('external', 'w-3 h-3') ?>
                                </a>
                            </dd>
                        </div>
                    <?php endif; ?>
                </dl>
            </div>

            <?php if ($siblings !== []): ?>
                <div class="ad-card__foot" style="justify-content:flex-start;align-items:center">
                    <span class="ad-muted" style="font-size:var(--ad-text-xs)">Also on this order:</span>
                    <?php foreach ($siblings as $other): ?>
                        <a class="ad-btn ad-btn--sm" href="<?= e($trackUrl((int) $other['id'])) ?>">
                            <span class="ad-mono"><?= e((string) ($other['awb'] ?: 'Shipment #' . (int) $other['id'])) ?></span>
                            <span class="sik-status sik-status--<?= e_attr(shipping_status_tone((string) $other['status'])) ?>">
                                <?= e(shipping_status_label((string) $other['status'])) ?>
                            </span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- ========================= Timeline ============================ -->
        <div class="ad-card">
            <div class="ad-card__head">
                <div>
                    <h2 class="ad-card__title">Tracking history</h2>
                    <div class="ad-card__sub">
                        <?= count($events) ?> event<?= count($events) === 1 ? '' : 's' ?>, newest first.
                    </div>
                </div>
                <?php if ($canEdit): ?>
                    <?php if ($awb !== ''): ?>
                        <div style="display:flex;gap:8px;flex-wrap:wrap">
                        <?php if (in_array($status, ['ready', 'booked', 'pickup_scheduled'], true)): ?>
                            <?php
                            // The same Print label as the booking screen: through
                            // action.php, so a courier that hosts its own label is
                            // asked for it first. Only while the parcel is still
                            // with us, as there.
                            ?>
                            <form method="post" action="<?= e(admin_url('shipping/action.php')) ?>" class="ad-inline-form" target="_blank">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="label">
                                <input type="hidden" name="shipment_id" value="<?= $sid ?>">
                                <input type="hidden" name="return" value="<?= e($returnTo) ?>">
                                <button type="submit" class="ad-btn ad-btn--sm">
                                    <?= icon('printer', 'w-4 h-4') ?> Print label
                                </button>
                            </form>
                        <?php endif; ?>
                        <form method="post" action="<?= e(admin_url('shipping/action.php')) ?>" class="ad-inline-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="refresh">
                            <input type="hidden" name="shipment_id" value="<?= $sid ?>">
                            <input type="hidden" name="return" value="<?= e($returnTo) ?>">
                            <button type="submit" class="ad-btn ad-btn--sm">
                                <?= icon('refresh', 'w-4 h-4') ?> Refresh from courier
                            </button>
                        </form>
                        </div>
                    <?php else: ?>
                        <?php // The service refuses to track without an AWB; say why here rather than after a click. ?>
                        <span class="ad-muted" style="font-size:var(--ad-text-xs)">No AWB yet, so nothing to ask the courier.</span>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <?php if ($events === []): ?>
                <?= admin_empty('No tracking events yet', 'Scans appear here as the courier reports them, by webhook or when you refresh.', null, null, 'clock') ?>
            <?php else: ?>
                <div class="ad-card__body">
                    <ol class="trk-tl">
                        <?php foreach ($events as $event): ?>
                            <?php
                            $evStatus = (string) $event['status'];
                            $evTone   = shipping_status_tone($evStatus);
                            $at       = (string) $event['occurred_at'];
                            $source   = (string) $event['source'];
                            ?>
                            <li class="trk-tl__item">
                                <span class="trk-tl__dot sik-status--<?= e_attr($evTone) ?>" aria-hidden="true"></span>
                                <div class="trk-tl__head">
                                    <span class="sik-status sik-status--<?= e_attr($evTone) ?>"><?= e(shipping_status_label($evStatus)) ?></span>
                                    <time class="trk-tl__time" datetime="<?= e(format_date($at, 'Y-m-d\TH:i:s')) ?>">
                                        <?= e(format_datetime($at)) ?>
                                    </time>
                                </div>
                                <?php if ((string) ($event['message'] ?? '') !== ''): ?>
                                    <p class="trk-tl__msg"><?= e((string) $event['message']) ?></p>
                                <?php endif; ?>
                                <div class="trk-tl__meta">
                                    <?php if ((string) ($event['location'] ?? '') !== ''): ?>
                                        <span><?= icon('location', 'w-3 h-3') ?> <?= e((string) $event['location']) ?></span>
                                    <?php endif; ?>
                                    <span class="trk-tl__src" title="<?= e_attr([
                                        'webhook' => 'Pushed by the courier',
                                        'poll'    => 'Fetched by a refresh',
                                        'manual'  => 'Recorded by an admin action',
                                    ][$source] ?? '') ?>"><?= e($source) ?></span>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                </div>
            <?php endif; ?>
        </div>

    <?php elseif ($q !== ''): ?>

        <?php if ($results === []): ?>
            <!-- ========================= No match ========================= -->
            <div class="ad-card">
                <?php if ($orderNoShip !== null): ?>
                    <?= admin_empty(
                        'Order ' . $orderNoShip['order_number'] . ' has not been shipped',
                        'The order exists, but no consignment has been booked for it yet.',
                        'Book shipment',
                        admin_url('shipping/book.php?order=' . (int) $orderNoShip['id']),
                        'truck'
                    ) ?>
                <?php else: ?>
                    <?= admin_empty(
                        'No shipment matches “' . $q . '”',
                        'Try the full AWB, the order number, the customer’s name or phone, or the courier’s name.',
                        null, null, 'search'
                    ) ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>

    <?php endif; ?>

    <?php
    // One list block for "several matches" and the landing view. It also
    // follows a ?shipment that was not found, so a stale link still leaves
    // the operator something to pick from.
    $listRows = $shipment === null ? ($q !== '' ? $results : $recent) : [];
    ?>
    <?php if ($listRows !== []): ?>
        <!-- ========================= Pick list ============================ -->
        <div class="ad-card">
            <div class="ad-card__head">
                <div>
                    <?php if ($q !== ''): ?>
                        <h2 class="ad-card__title">
                            <?= count($listRows) ?><?= $moreResults ? '+' : '' ?> shipments match &ldquo;<?= e($q) ?>&rdquo;
                        </h2>
                        <div class="ad-card__sub">
                            Newest first.<?= $moreResults ? ' Showing the first ' . $pickLimit . ' &mdash; add more of the AWB, number or name to narrow it.' : ' Pick one to see its timeline.' ?>
                        </div>
                    <?php else: ?>
                        <h2 class="ad-card__title">Recently updated</h2>
                        <div class="ad-card__sub">The ten consignments that moved last.</div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="ad-tablewrap">
                <table class="ad-table">
                    <thead>
                        <tr>
                            <th>AWB</th>
                            <th>Order</th>
                            <th>Destination</th>
                            <th>Status</th>
                            <th>Updated</th>
                            <th class="ad-table__actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($listRows as $row): ?>
                            <?php $link = $trackUrl((int) $row['id']); ?>
                            <tr>
                                <td>
                                    <span class="ad-cellflex__name" style="display:block">
                                        <a class="ad-mono" href="<?= e($link) ?>">
                                            <?= e((string) ($row['awb'] ?: 'No AWB #' . (int) $row['id'])) ?>
                                        </a>
                                    </span>
                                    <span class="ad-cellflex__meta">
                                        <?= e((string) ($row['courier_name'] ?: ($row['provider_name'] ?? $row['provider_code']))) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="ad-cellflex__name ad-mono" style="display:block">
                                        <?= e((string) ($row['order_number'] ?? ('#' . (int) $row['order_id']))) ?>
                                    </span>
                                    <span class="ad-cellflex__meta"><?= e((string) ($row['shipping_name'] ?: $row['customer_name'] ?? '')) ?></span>
                                </td>
                                <td>
                                    <?= e((string) ($row['shipping_city'] ?? '')) ?>
                                    <span class="ad-cellflex__meta ad-mono" style="display:block"><?= e((string) ($row['shipping_pincode'] ?? '')) ?></span>
                                </td>
                                <td>
                                    <span class="sik-status sik-status--<?= e_attr(shipping_status_tone((string) $row['status'])) ?>">
                                        <?= e(shipping_status_label((string) $row['status'])) ?>
                                    </span>
                                </td>
                                <td class="ad-muted"><?= e(format_datetime((string) $row['updated_at'])) ?></td>
                                <td class="ad-table__actions">
                                    <a class="ad-btn ad-btn--sm" href="<?= e($link) ?>"><?= icon('eye', 'w-4 h-4') ?> View</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

</div>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
