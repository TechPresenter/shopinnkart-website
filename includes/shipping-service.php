<?php
/**
 * ShopInnKart - Shipment lifecycle.
 *
 * shipping-functions.php knows how to TALK to a courier. This file knows what a
 * shipment IS: the record, its status, its timeline, and how it moves the order
 * it belongs to. Screens and the webhook call these functions and never a
 * driver directly, so the rules below hold whichever door a change came in by.
 *
 * Four rules everything here enforces:
 *
 *   1. One live consignment per order at a time. The pending row is inserted
 *      under a lock on the order BEFORE the courier is called, so two admins
 *      pressing Book at once produce one booking and one refusal - not two AWBs.
 *
 *   2. Status only moves forward. Couriers deliver webhooks late and out of
 *      order; an "in transit" that arrives after "delivered" is recorded in the
 *      timeline but does not drag the shipment - or the order - backwards. The
 *      status is worked out from the whole stored timeline under the
 *      shipment's row lock, so it does not depend on which request won a race.
 *
 *   3. Events are idempotent. The (shipment, event_key) unique key turns a
 *      resent webhook or an overlapping poll into a no-op instead of a second
 *      timeline row and a second customer email - while still settling the
 *      shipment and order against what is stored, so a retry repairs.
 *
 *   4. The order moves only through update_order_status(), because that is
 *      where stock release, COD-paid-on-delivery, history and the customer email
 *      live. Writing orders.status here would skip all of it.
 */

declare(strict_types=1);

require_once __DIR__ . '/shipping-functions.php';

// ===========================================================================
//  Vocabulary
// ===========================================================================

/** Every shipment status, in the order a normal consignment passes through them. */
function shipping_statuses(): array
{
    return [
        'pending'          => 'Pending',
        'ready'            => 'Ready to ship',
        'booked'           => 'AWB assigned',
        'pickup_scheduled' => 'Pickup scheduled',
        // Only ever on a reverse consignment: the courier is going to the
        // CUSTOMER to collect. The forward leg's equivalent is above it.
        'return_pickup'    => 'Return pickup scheduled',
        'in_transit'       => 'In transit',
        'failed'           => 'Delivery attempt failed',
        'out_for_delivery' => 'Out for delivery',
        'rto_initiated'    => 'RTO initiated',
        'delivered'        => 'Delivered',
        'rto_delivered'    => 'RTO delivered',
        'returned'         => 'Returned',
        'cancelled'        => 'Cancelled',
        // Never reached the courier: frees the order to be booked again.
        'failed_booking'   => 'Booking failed',
    ];
}

function shipping_status_label(string $status): string
{
    return shipping_statuses()[$status] ?? ucwords(str_replace('_', ' ', $status));
}

/** Badge tone per status, in the admin's sik-status vocabulary. */
function shipping_status_tone(string $status): string
{
    return [
        'pending' => 'gray', 'ready' => 'amber', 'booked' => 'blue', 'pickup_scheduled' => 'blue',
        'return_pickup' => 'amber',
        'in_transit' => 'indigo', 'failed' => 'amber', 'out_for_delivery' => 'teal',
        'delivered' => 'green', 'rto_initiated' => 'red', 'rto_delivered' => 'red',
        'returned' => 'gray', 'cancelled' => 'red', 'failed_booking' => 'red',
    ][$status] ?? 'gray';
}

/**
 * How far along a status is. Higher never yields to lower.
 *
 * `failed` sits below out_for_delivery on purpose: a failed attempt is followed
 * by another attempt the next day, and the headline should say "out for
 * delivery" again rather than stay stuck on the failure. The failure itself is
 * still in the timeline.
 *
 * The four terminal statuses are ranked highest so nothing supersedes them.
 */
function shipping_status_rank(string $status): int
{
    return [
        'pending'          => 0,
        'ready'            => 5,
        'booked'           => 10,
        'pickup_scheduled' => 20,
        // Above "pickup scheduled" and below "in transit" for the same reason
        // the forward leg's are in that order: the courier has been booked but
        // does not have the parcel yet.
        'return_pickup'    => 25,
        'in_transit'       => 30,
        'failed'           => 35,
        'out_for_delivery' => 40,
        'rto_initiated'    => 60,
        'delivered'        => 100,
        'rto_delivered'    => 110,
        'returned'         => 120,
        'cancelled'        => 130,
    ][$status] ?? -1;
}

/** Statuses after which nothing further changes the headline. */
function shipping_is_terminal(string $status): bool
{
    return in_array($status, ['delivered', 'rto_delivered', 'returned', 'cancelled', 'failed_booking'], true);
}

/** A shipment in one of these no longer holds the order's "one live consignment" slot. */
function shipping_is_dead(string $status): bool
{
    return in_array($status, ['cancelled', 'failed_booking'], true);
}

/**
 * Can this consignment still be called off? Only before the courier has the
 * parcel. From pickup on - a failed attempt and an RTO included - cancelling
 * the booking would not stop the parcel, and voiding it here would free the
 * order for a second consignment while the first one is still being delivered.
 */
function shipping_is_cancellable(string $status): bool
{
    return in_array($status, ['ready', 'booked', 'pickup_scheduled', 'return_pickup'], true);
}

/** Statuses that say the courier has physically had the parcel. */
function shipping_status_left_us(string $status): bool
{
    return in_array($status, ['in_transit', 'failed', 'out_for_delivery', 'rto_initiated', 'delivered', 'rto_delivered', 'returned'], true);
}

/**
 * How long a 'pending' row may take before it is treated as a booking that
 * died mid-request. Longer than max_execution_time plus every courier timeout
 * and retry (Shiprocket's re-login path alone can take two minutes), so a
 * booking that is merely slow is never released under its own feet.
 */
const SHIPPING_PENDING_STALE_MINUTES = 10;

/** Is this a 'pending' booking old enough to have died mid-request? */
function shipping_pending_is_stale(array $shipment): bool
{
    if ((string) ($shipment['status'] ?? '') !== 'pending') {
        return false;
    }
    $created = strtotime((string) ($shipment['created_at'] ?? ''));
    return $created !== false && $created < time() - SHIPPING_PENDING_STALE_MINUTES * 60;
}

/**
 * Has the shipping migration been run? Pages outside the hub (the order view,
 * the manual tracking form) ask before touching its tables, so a site that
 * has deployed the code but not yet migrated shows "no shipment" instead of a
 * 500. Not cached: it is two cheap queries, and a cached answer would outlive
 * the migration that changes it.
 */
function shipping_hub_installed(): bool
{
    try {
        Database::query('SELECT 1 FROM `shipments` LIMIT 0');
        Database::query('SELECT 1 FROM `shipping_providers` LIMIT 0');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * What the order screen's Shipment card needs, safe before the migration.
 *
 * @return array{installed:bool, live:?array, couriers:bool}
 */
function shipping_order_panel(int $orderId): array
{
    if (!shipping_hub_installed()) {
        return ['installed' => false, 'live' => null, 'couriers' => false];
    }
    return [
        'installed' => true,
        'live'      => shipment_live_for_order($orderId),
        'couriers'  => ShippingProviderFactory::available() !== [],
    ];
}

/**
 * A courier time as 'Y-m-d H:i:s' in the store's timezone, or null when it
 * cannot be read.
 *
 * Drivers are meant to hand times over in that shape already; this is the
 * backstop. An ISO-8601 time with an offset used to go into the event row
 * coerced and then fail the strict-mode shipment UPDATE - after the event was
 * stored, so the courier's retry was a duplicate and the delivery was lost.
 */
function shipping_normalise_time($value): ?string
{
    if (!is_string($value)) {
        return null;
    }
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value) === 1) {
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);
        return $dt !== false && $dt->format('Y-m-d H:i:s') === $value ? $value : null;
    }
    try {
        // An offset in the string wins over the default zone; the result is
        // then moved into the store's zone, which is what the columns hold.
        $dt = new DateTimeImmutable($value);
    } catch (Exception $e) {
        return null;
    }
    $year = (int) $dt->format('Y');
    if ($year < 2000 || $year > 2100) {
        return null;
    }
    return $dt->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
}

/**
 * A URL a courier hosts, or null. Only https on a real host: anything else
 * (the mock pointing back at our own pages, a plain-http link) is not a
 * courier document, and the screens fall back to the hub's own print pages.
 */
function shipping_courier_url($url): ?string
{
    if (!is_string($url) || $url === '' || strlen($url) > 500 || preg_match('/[\x00-\x20\x7F\\\\]/', $url) === 1) {
        return null;
    }
    $parts = parse_url($url);
    return is_array($parts) && strtolower((string) ($parts['scheme'] ?? '')) === 'https' && !empty($parts['host'])
        ? $url
        : null;
}

/**
 * The order status a shipment status implies, or null for "leave the order be".
 *
 * A cancelled SHIPMENT does not cancel the ORDER - the usual reason to cancel a
 * consignment is to rebook it with another courier. RTO initiated leaves the
 * order alone because the parcel is still out; only its arrival back is a return.
 */
function shipping_order_status_for(string $shipmentStatus): ?string
{
    return [
        'booked'           => ORDER_STATUS_PACKED,
        'pickup_scheduled' => ORDER_STATUS_PACKED,
        'in_transit'       => ORDER_STATUS_SHIPPED,
        'out_for_delivery' => ORDER_STATUS_OUT_FOR_DELIVERY,
        'delivered'        => ORDER_STATUS_DELIVERED,
        'rto_delivered'    => ORDER_STATUS_RETURNED,
        // No driver reports 'returned' on a FORWARD leg - it is the reverse
        // leg's arrival - but a row that carries it (older data, a hand edit)
        // means the same thing, and leaving it unmapped left the Returns desk
        // offering a Settle button that could never do anything.
        'returned'         => ORDER_STATUS_RETURNED,
    ][$shipmentStatus] ?? null;
}

/**
 * The same question for a consignment whose direction is known.
 *
 * A reverse pickup answers almost nothing: the goods are with the customer
 * until it arrives, and the order must stay exactly where it was. Reading the
 * forward table for it would move the order to 'packed' the moment the return
 * was booked, and 'shipped' as it travelled - backwards, on a delivered order.
 * Only the arrival counts, and that is a return.
 */
function shipping_order_status_for_shipment(array $shipment): ?string
{
    $status = (string) ($shipment['status'] ?? '');

    if (!shipping_is_return($shipment)) {
        return shipping_order_status_for($status);
    }

    return $status === 'returned' ? ORDER_STATUS_RETURNED : null;
}

/**
 * Order statuses in the order they advance. Terminal ones rank highest.
 *
 * The table itself lives with the order lifecycle (order_status_rank), which
 * is where update_order_status() applies it to every caller: two copies had
 * to agree forever, and the hub's copy was the only one being enforced.
 */
function shipping_order_rank(string $status): int
{
    return order_status_rank($status);
}

// ===========================================================================
//  Reads
// ===========================================================================

function shipment_get(int $id): ?array
{
    return $id > 0 ? Database::fetch('SELECT * FROM `shipments` WHERE `id` = :id', ['id' => $id]) : null;
}

/**
 * Resolve a courier's reference to our record. Scoped by provider: AWB formats
 * collide across couriers.
 *
 * AWB first, then the courier's shipment id, then its order id: a courier can
 * reassign the AWB (or assign one we never heard back about), and the push then
 * carries an AWB we do not know on a booking we do.
 */
function shipment_find(string $providerCode, string $awb = '', string $shipmentRef = '', string $orderRef = ''): ?array
{
    if ($awb !== '') {
        $row = Database::fetch(
            'SELECT * FROM `shipments` WHERE `provider_code` = :p AND `awb` = :a ORDER BY `id` DESC LIMIT 1',
            ['p' => $providerCode, 'a' => $awb]
        );
        if ($row !== null) {
            return $row;
        }
    }
    if ($shipmentRef !== '') {
        $row = Database::fetch(
            'SELECT * FROM `shipments` WHERE `provider_code` = :p AND `shipment_ref` = :r ORDER BY `id` DESC LIMIT 1',
            ['p' => $providerCode, 'r' => $shipmentRef]
        );
        if ($row !== null) {
            return $row;
        }
    }
    if ($orderRef !== '') {
        return Database::fetch(
            'SELECT * FROM `shipments` WHERE `provider_code` = :p AND `order_ref` = :r ORDER BY `id` DESC LIMIT 1',
            ['p' => $providerCode, 'r' => $orderRef]
        );
    }
    return null;
}

/**
 * Apply one normalised courier update (a parsed webhook) to our records.
 *
 * $parsed is what a driver's parseWebhook() returns: awb, shipment_ref,
 * order_ref, courier_name, events. Returns found=false for a consignment that
 * is not ours. Throws on our own failure, so the caller can answer 500 and the
 * courier retries.
 *
 * @return array{found:bool, shipment_id:int, added:int}
 */
function shipping_handle_update(string $providerCode, array $parsed, string $source = 'webhook'): array
{
    $awb = trim((string) ($parsed['awb'] ?? ''));
    $shipment = shipment_find(
        $providerCode,
        $awb,
        trim((string) ($parsed['shipment_ref'] ?? '')),
        trim((string) ($parsed['order_ref'] ?? ''))
    );
    if ($shipment === null) {
        return ['found' => false, 'shipment_id' => 0, 'added' => 0];
    }
    $shipmentId = (int) $shipment['id'];

    // Found by booking reference with an AWB we do not hold: the courier
    // assigned (or reassigned) it outside our own request. Adopt it, or every
    // later push for that AWB is "unknown shipment" and a delivered COD parcel
    // never registers.
    if ($awb !== '' && (string) $shipment['awb'] !== $awb && !shipping_is_dead((string) $shipment['status'])) {
        shipping_adopt_awb($shipmentId, $awb, trim((string) ($parsed['courier_name'] ?? '')));
    }

    $added = shipping_record_events($shipmentId, (array) ($parsed['events'] ?? []), $source);

    return ['found' => true, 'shipment_id' => $shipmentId, 'added' => $added];
}

/** Record an AWB the courier assigned on its own, and put it on the order. */
function shipping_adopt_awb(int $shipmentId, string $awb, string $courierName = ''): void
{
    $shipment = shipment_get($shipmentId);
    if ($shipment === null || $awb === '' || (string) $shipment['awb'] === $awb) {
        return;
    }
    $previous = (string) $shipment['awb'];

    Database::update('shipments', array_filter([
        'awb'          => $awb,
        'courier_name' => $courierName !== '' ? $courierName : null,
        'tracking_url' => shipping_tracking_url((string) $shipment['provider_code'], $awb),
    ], static fn ($v) => $v !== null), '`id` = :id', ['id' => $shipmentId]);

    log_activity('shipment.awb_adopted', 'shipment', $shipmentId,
        'Courier ' . ($previous === '' ? 'assigned' : 'reassigned ' . $previous . ' ->') . ' AWB ' . $awb);

    // 'booked' only moves a shipment that had no AWB yet; for one already on
    // its way the event is recorded and the rank rule leaves the status alone.
    // Either way the order sync that follows carries the new AWB to the order.
    shipping_record_events($shipmentId, [[
        'status'      => 'booked',
        'message'     => 'AWB ' . $awb . ' assigned by the courier' . ($courierName !== '' ? ' (' . $courierName . ')' : ''),
        'location'    => '',
        'occurred_at' => date('Y-m-d H:i:s'),
        'event_key'   => 'awb:' . $awb,
    ]], 'manual');
}

function shipments_for_order(int $orderId): array
{
    return Database::fetchAll('SELECT * FROM `shipments` WHERE `order_id` = :o ORDER BY `id` DESC', ['o' => $orderId]);
}

/**
 * The consignment currently holding the order, if any.
 *
 * FORWARD legs only. A reverse pickup has its own AWB, its own scans and its
 * own "delivered" - which means the parcel reached OUR warehouse. Letting it
 * hold the slot put its AWB on the order the customer is looking at, and its
 * arrival would have marked the order delivered and, for COD, paid.
 */
function shipment_live_for_order(int $orderId): ?array
{
    return Database::fetch(
        "SELECT * FROM `shipments`
          WHERE `order_id` = :o AND `direction` = 'forward' AND `status` NOT IN ('cancelled','failed_booking')
          ORDER BY `id` DESC LIMIT 1",
        ['o' => $orderId]
    );
}

/** Is this row a reverse consignment (a return or an RTO pickup)? */
function shipping_is_return(array $shipment): bool
{
    return (string) ($shipment['direction'] ?? 'forward') === 'return';
}

/** The reverse consignment currently in force for an order, if any. */
function shipping_return_live_for_order(int $orderId): ?array
{
    return Database::fetch(
        "SELECT * FROM `shipments`
          WHERE `order_id` = :o AND `direction` = 'return' AND `status` NOT IN ('cancelled','failed_booking')
          ORDER BY `id` DESC LIMIT 1",
        ['o' => $orderId]
    );
}

/** Every reverse consignment an order has had, newest first. */
function shipping_returns_for_order(int $orderId): array
{
    return Database::fetchAll(
        "SELECT * FROM `shipments` WHERE `order_id` = :o AND `direction` = 'return' ORDER BY `id` DESC",
        ['o' => $orderId]
    );
}

function shipment_events(int $shipmentId): array
{
    return Database::fetchAll(
        'SELECT * FROM `shipment_events` WHERE `shipment_id` = :s ORDER BY `occurred_at` ASC, `id` ASC',
        ['s' => $shipmentId]
    );
}

/**
 * The order in the shape a driver expects: the row plus its items, the email
 * under the key drivers read, and a parcel weight estimated from the catalogue.
 */
function shipping_order_payload(int $orderId): ?array
{
    $order = get_order($orderId);
    if ($order === null) {
        return null;
    }

    $items = Database::fetchAll(
        'SELECT oi.`product_id`, oi.`product_name`, oi.`product_sku`, oi.`quantity`, oi.`price`,
                p.`weight`, p.`hsn_code`
           FROM `order_items` oi
           LEFT JOIN `products` p ON p.`id` = oi.`product_id`
          WHERE oi.`order_id` = :o',
        ['o' => $orderId]
    );

    $grams = 0;
    $out   = [];
    foreach ($items as $item) {
        $qty = max(1, (int) $item['quantity']);
        // Catalogue weight is kilograms; an item with none is assumed 400 g,
        // which is a boxed string light. The admin can override the total.
        $grams += (int) round(((float) ($item['weight'] ?? 0) ?: 0.4) * 1000) * $qty;
        $out[] = [
            'product_id'   => (int) $item['product_id'],
            'product_name' => (string) $item['product_name'],
            'sku'          => (string) ($item['product_sku'] ?: ('SKU-' . (int) $item['product_id'])),
            'quantity'     => $qty,
            'unit_price'   => (float) $item['price'],
            'hsn_code'     => (string) ($item['hsn_code'] ?? ''),
        ];
    }

    $order['items']            = $out;
    $order['email']            = (string) ($order['customer_email'] ?? '');
    $order['estimated_grams']  = max(200, $grams);
    $order['is_cod']           = shipping_order_owes_cod($order);

    return $order;
}

/**
 * Does the courier collect cash for this order at the door?
 *
 * COD that is already paid - delivered and settled by hand, or paid some other
 * way before dispatch - ships as prepaid. Otherwise the courier is told to
 * collect the full total from a customer who has already paid it, and an RTO
 * of that parcel then "resets" a payment that was genuinely received.
 */
function shipping_order_owes_cod(array $order): bool
{
    return strtolower((string) ($order['payment_method'] ?? '')) === 'cod'
        && (string) ($order['payment_status'] ?? '') !== PAYMENT_STATUS_PAID;
}

// ===========================================================================
//  Rates
// ===========================================================================

/**
 * Quotes from every live courier for one parcel, cheapest first. No order
 * needed: this is what the standalone rate calculator asks, and what
 * shipping_quote_order() asks once it has turned an order into a parcel.
 *
 * $request: destination_pin (required), origin_pin (optional - each
 * integration's own pickup PIN is used when none is forced), weight_grams,
 * length_cm / width_cm / height_cm, is_cod, cod_amount, declared_value.
 *
 * One failing courier does not fail the lot - its error is carried alongside,
 * so the admin sees "Shiprocket: login failed" next to the mock's rates rather
 * than an empty screen.
 *
 * Serviceability is NOT a second round of calls: a courier that quotes serves
 * the lane, and one that does not says why in `errors` and in `providers`.
 * Asking twice would double every real integration's API bill for an answer
 * the rates call has already given.
 *
 * @return array{rates:array, errors:string[], blocked:array<string,string>,
 *               origin_pin:string, providers:array<string,array>}
 */
function shipping_rate_quote(array $request): array
{
    $digits      = static fn ($value): string => preg_replace('/\D+/', '', (string) $value);
    $destination = $digits($request['destination_pin'] ?? '');
    $forcedOrigin = $digits($request['origin_pin'] ?? '');

    $out = [
        'rates'      => [],
        'errors'     => [],
        'blocked'    => [],
        'origin_pin' => $forcedOrigin,
        'providers'  => [],
    ];

    $providers = ShippingProviderFactory::available();

    // What the form should show when the admin has not typed an origin: the
    // default integration's pickup PIN, which is where parcels actually leave
    // from.
    if ($out['origin_pin'] === '') {
        $default = shipping_default_provider();
        $out['origin_pin'] = (string) ($default['pickup_pincode'] ?? '') ?: (string) setting('store_pincode', '');
    }

    if (strlen($destination) !== 6) {
        $out['errors'][] = 'Enter a six-digit destination PIN code.';
        return $out;
    }
    if ($providers === []) {
        $out['errors'][] = 'No courier integration is active, so nothing can be quoted.';
        return $out;
    }

    $isCod     = !empty($request['is_cod']);
    $codAmount = $isCod ? max(0.0, (float) ($request['cod_amount'] ?? 0)) : 0.0;
    $declared  = max(0.0, (float) ($request['declared_value'] ?? $codAmount));
    $grams     = max(1, (int) ($request['weight_grams'] ?? 500));

    foreach ($providers as $provider) {
        $driver = ShippingProviderFactory::make($provider);
        if ($driver === null) {
            continue;
        }
        $code = (string) $provider['code'];
        $name = (string) $provider['name'];

        // A courier that quotes but cannot book is still worth showing - the
        // price is real - so it is recorded here and excluded from the
        // recommendation by shipping_score_rates() rather than dropped.
        $block = shipping_provider_book_block($provider);
        if ($block !== null) {
            $out['blocked'][$code] = $block;
        }

        $entry = ['name' => $name, 'mode' => (string) $provider['mode'], 'origin_pin' => '',
                  'serviceable' => null, 'cod' => (int) $provider['supports_cod'] === 1,
                  'blocked' => $block, 'message' => '', 'rates' => 0];

        if ($isCod && (int) $provider['supports_cod'] !== 1) {
            $entry['serviceable']  = false;
            $entry['message']      = 'COD is switched off for this courier.';
            $out['errors'][]       = $name . ': COD is switched off for this courier.';
            $out['providers'][$code] = $entry;
            continue;
        }

        $origin = $forcedOrigin !== '' ? $forcedOrigin : (string) ($provider['pickup_pincode'] ?: setting('store_pincode', ''));
        if ($origin === '') {
            $entry['message']        = 'Set a pickup PIN code on the integration first.';
            $out['errors'][]         = $name . ': set a pickup PIN code on the integration first.';
            $out['providers'][$code] = $entry;
            continue;
        }
        $entry['origin_pin'] = $origin;

        try {
            // The dimensions go in too, null when not given: couriers bill the
            // larger of dead and volumetric weight, and a quote on weight alone
            // understated a big light box that the booking then declared. With
            // null the driver uses the same default box it books with.
            $res = $driver->getRates([
                'origin_pin'      => $origin,
                'destination_pin' => $destination,
                'weight_grams'    => $grams,
                'length_cm'       => isset($request['length_cm']) ? (float) $request['length_cm'] : null,
                'width_cm'        => isset($request['width_cm']) ? (float) $request['width_cm'] : null,
                'height_cm'       => isset($request['height_cm']) ? (float) $request['height_cm'] : null,
                'is_cod'          => $isCod,
                'cod_amount'      => $codAmount,
                'declared_value'  => $declared,
            ]);
        } catch (Throwable $e) {
            $res = ['ok' => false, 'message' => $e->getMessage()];
        }

        if (empty($res['ok'])) {
            $entry['message']        = (string) ($res['message'] ?? 'rates unavailable');
            $out['errors'][]         = $name . ': ' . $entry['message'];
            $out['providers'][$code] = $entry;
            continue;
        }

        $offered = (array) ($res['rates'] ?? []);
        foreach ($offered as $rate) {
            $out['rates'][] = $rate + [
                'provider'      => $code,
                'provider_name' => $name,
                'origin_pin'    => $origin,
            ];
        }
        $entry['rates']       = count($offered);
        $entry['serviceable'] = $offered !== [];
        $entry['message']     = (string) ($res['message'] ?? '');
        if ($offered === []) {
            $entry['message'] = $entry['message'] ?: 'no service for this PIN code';
            $out['errors'][]  = $name . ': ' . $entry['message'];
        }
        $out['providers'][$code] = $entry;
    }

    usort($out['rates'], static fn (array $a, array $b): int => $a['cost'] <=> $b['cost']);

    return $out;
}

/**
 * Quotes from every live courier for one order, cheapest first.
 *
 * The order's own parcel - the catalogue weight estimate, the delivery PIN,
 * whether the courier collects cash - unless the caller overrides it with a
 * weighed parcel.
 */
function shipping_quote_order(int $orderId, array $parcel = []): array
{
    $order = shipping_order_payload($orderId);
    if ($order === null) {
        return ['rates' => [], 'errors' => ['Order not found.'], 'blocked' => [], 'providers' => []];
    }

    $quote = shipping_rate_quote([
        'destination_pin' => (string) $order['shipping_pincode'],
        'weight_grams'    => (int) ($parcel['weight_grams'] ?? $order['estimated_grams']),
        'length_cm'       => $parcel['length_cm'] ?? null,
        'width_cm'        => $parcel['width_cm'] ?? null,
        'height_cm'       => $parcel['height_cm'] ?? null,
        'is_cod'          => $order['is_cod'],
        'cod_amount'      => $order['is_cod'] ? (float) $order['total_amount'] : 0,
        'declared_value'  => (float) $order['total_amount'],
    ]);

    return $quote + ['order' => $order];
}

// ===========================================================================
//  Choosing a courier
// ===========================================================================

/**
 * Score a quote the way the selection does. The calculator and the booking
 * screen both go through here, so a rate the calculator calls best is the one
 * the booking screen pre-selects.
 */
function shipping_score_quote(array $quote): array
{
    return shipping_score_rates(
        (array) ($quote['rates'] ?? []),
        ['blocked' => (array) ($quote['blocked'] ?? [])]
    );
}

/**
 * Which courier should carry this order, and why.
 *
 * Every live quote is scored on cost, promised days, the courier's own record
 * and its rating; COD support and serviceability are gates rather than weights
 * because a courier that will not take the parcel cannot be "nearly right" -
 * it never reaches the scoring, and says why in `errors` instead.
 *
 * The reasoning comes back with the choice so the screen can show WHY this
 * courier won, and so the sentence stored on the shipment is the one the admin
 * was shown - the weights are settings and the performance window moves, so a
 * reason recomputed a month later would not be the reason it was booked for.
 *
 * $opts['quote'] lets a caller that has already paid for a quote pass it in
 * rather than ask every courier twice on one page view.
 *
 * @return array{ok:bool, choice:?array, ranked:array, rates:array, reason:string,
 *                reasons:string[], weights:array, errors:string[], blocked:array,
 *                order:?array, message:string}
 */
function shipping_select_courier(int $orderId, array $parcel = [], array $opts = []): array
{
    $quote = is_array($opts['quote'] ?? null) ? $opts['quote'] : shipping_quote_order($orderId, $parcel);
    $order = $quote['order'] ?? null;

    $scored = shipping_score_quote($quote);

    $message = '';
    if ($scored['choice'] === null) {
        $message = $order === null
            ? 'Order not found.'
            : ((array) ($quote['rates'] ?? []) === []
                ? 'No courier quoted for this parcel.'
                : 'Every courier that quoted is in Test mode and cannot book.');
    }

    return [
        'ok'      => $scored['choice'] !== null,
        'choice'  => $scored['choice'],
        'ranked'  => $scored['ranked'],
        'rates'   => (array) ($quote['rates'] ?? []),
        'reason'  => $scored['reason'],
        'reasons' => $scored['reasons'],
        'weights' => $scored['weights'],
        'errors'  => (array) ($quote['errors'] ?? []),
        'blocked' => (array) ($quote['blocked'] ?? []),
        'order'   => $order,
        'message' => $message,
    ];
}

// ===========================================================================
//  Booking
// ===========================================================================

/**
 * Book an order with a courier and ask for its AWB.
 *
 * Options: weight_grams, length_cm, width_cm, height_cm, courier_id (the
 * aggregator sub-courier chosen from a quote), courier (its display name),
 * shipping_charge (what the chosen rate costs us, re-derived by the caller
 * from a fresh quote - never the posted figure), eta_days (the rate's ETA).
 *
 * Two steps, recorded separately, because they fail separately: Shiprocket can
 * accept a booking and then refuse the AWB for an empty wallet. That leaves a
 * `ready` shipment whose AWB can be requested again - not a phantom booking
 * that forces a second consignment.
 */
function shipping_book(int $orderId, string $providerCode, array $opts = []): array
{
    $provider = shipping_provider($providerCode);
    if ($provider === null || $provider['status'] !== 'active') {
        return shipping_fail('That courier is not active.');
    }
    $driver = ShippingProviderFactory::make($provider);
    if ($driver === null) {
        return shipping_fail('No driver is installed for ' . $providerCode . '.');
    }

    $order = shipping_order_payload($orderId);
    if ($order === null) {
        return shipping_fail('Order not found.');
    }
    if (($blocked = shipping_order_block_reason($order)) !== null) {
        return shipping_fail($blocked);
    }
    if ($order['is_cod'] && (int) $provider['supports_cod'] !== 1) {
        return shipping_fail('COD is switched off for ' . $provider['name'] . '.');
    }

    $grams = max(50, (int) ($opts['weight_grams'] ?? $order['estimated_grams']));

    // The sub-courier chosen from the quote is kept on the row, so an AWB
    // retried after a refusal asks for the same one instead of letting the
    // aggregator pick its own (and its own price).
    $courierId = trim((string) ($opts['courier_id'] ?? ''));
    $charge    = isset($opts['shipping_charge']) && is_numeric($opts['shipping_charge']) && (float) $opts['shipping_charge'] >= 0
        ? round((float) $opts['shipping_charge'], 2)
        : 0.0;
    $etaDays   = isset($opts['eta_days']) && is_numeric($opts['eta_days']) && (int) $opts['eta_days'] > 0
        ? min(60, (int) $opts['eta_days'])
        : null;

    // How this courier came to be chosen, kept on the consignment. A value
    // outside the three the column knows is stored as nothing rather than
    // rejected: a caller that does not record its choice is the old behaviour,
    // and a booking must not fail over a label.
    $selectedBy = in_array((string) ($opts['selected_by'] ?? ''), ['manual', 'recommended', 'auto'], true)
        ? (string) $opts['selected_by']
        : null;
    $selectionScore = isset($opts['selection_score']) && is_numeric($opts['selection_score'])
        ? round(max(0.0, min(100.0, (float) $opts['selection_score'])), 2)
        : null;
    // Stored as the screen showed it: the weights are settings and the
    // performance window moves, so this sentence cannot be recomputed later
    // and still be the reason the parcel went where it went.
    $selectionReason = trim((string) ($opts['selection_reason'] ?? ''));
    $selectionReason = $selectionReason !== '' ? mb_substr($selectionReason, 0, 500) : null;

    // --- claim the order's slot, under a lock, before calling out ----------
    try {
        $claim = Database::transaction(static function () use ($orderId, $provider, $order, $grams, $opts, $courierId,
            $charge, $etaDays, $selectedBy, $selectionScore, $selectionReason): array {
            // FOR UPDATE serialises concurrent bookings of the same order: the
            // second waits here, then sees the first one's pending row. It also
            // serialises against update_order_status(), which takes the same
            // lock - so the order is re-read here, under it: a cancellation
            // that landed after the read above must stop the booking, or the
            // courier gets a consignment for an order the books say is dead.
            $locked = Database::fetch('SELECT * FROM `orders` WHERE `id` = :id FOR UPDATE', ['id' => $orderId]);
            if ($locked === null) {
                throw new RuntimeException('Order not found.');
            }
            if (($blocked = shipping_order_block_reason($locked)) !== null) {
                throw new RuntimeException($blocked);
            }
            $isCod = shipping_order_owes_cod($locked);
            if ($isCod && (int) $provider['supports_cod'] !== 1) {
                throw new RuntimeException('COD is switched off for ' . $provider['name'] . '.');
            }

            if (shipment_live_for_order($orderId) !== null) {
                throw new RuntimeException('This order already has a live shipment. Cancel it before booking another.');
            }

            $id = Database::insert('shipments', [
                'order_id'        => $orderId,
                'provider_id'     => (int) $provider['id'],
                'provider_code'   => (string) $provider['code'],
                'status'          => 'pending',
                'is_cod'          => $isCod ? 1 : 0,
                'cod_amount'      => $isCod ? (float) $locked['total_amount'] : 0,
                'shipping_charge' => $charge,
                'weight_grams'    => $grams,
                'length_cm'       => isset($opts['length_cm']) ? (float) $opts['length_cm'] : null,
                'width_cm'        => isset($opts['width_cm']) ? (float) $opts['width_cm'] : null,
                'height_cm'       => isset($opts['height_cm']) ? (float) $opts['height_cm'] : null,
                'courier_name'    => isset($opts['courier']) ? (string) $opts['courier'] : null,
                'courier_id'      => $courierId !== '' && strlen($courierId) <= 40 ? $courierId : null,
                'expected_at'     => $etaDays !== null ? date('Y-m-d', strtotime('+' . $etaDays . ' days')) : null,
                'selected_by'      => $selectedBy,
                'selection_score'  => $selectionScore,
                'selection_reason' => $selectionReason,
            ]);

            return ['id' => $id, 'is_cod' => $isCod, 'total' => (float) $locked['total_amount']];
        });
    } catch (RuntimeException $e) {
        return shipping_fail($e->getMessage());
    }
    $shipmentId      = (int) $claim['id'];
    $order['is_cod'] = $claim['is_cod'];

    // --- create at the courier --------------------------------------------
    try {
        $created = $driver->createShipment($order, [
            'weight_grams' => $grams,
            'length_cm'    => $opts['length_cm'] ?? null,
            'width_cm'     => $opts['width_cm'] ?? null,
            'height_cm'    => $opts['height_cm'] ?? null,
            'is_cod'       => $order['is_cod'],
            'service'      => $opts['courier'] ?? null,
            'shipment_id'  => $shipmentId,
        ]);
    } catch (Throwable $e) {
        $created = ['ok' => false, 'message' => 'Driver error: ' . $e->getMessage()];
    }

    if (empty($created['ok'])) {
        // Frees the slot: a booking that never reached the courier holds nothing.
        Database::update('shipments', [
            'status'        => 'failed_booking',
            'status_detail' => mb_substr((string) ($created['message'] ?? 'Booking failed.'), 0, 255),
        ], "`id` = :id AND `status` = 'pending'", ['id' => $shipmentId]);
        return shipping_fail((string) ($created['message'] ?? 'Booking failed.'), ['shipment_id' => $shipmentId]);
    }

    $ready = [
        'status'        => 'ready',
        'shipment_ref'  => (string) ($created['shipment_ref'] ?? ''),
        'order_ref'     => isset($created['order_ref']) && $created['order_ref'] !== '' ? (string) $created['order_ref'] : null,
        'courier_name'  => (string) ($created['courier_name'] ?? $opts['courier'] ?? '') ?: null,
        'status_detail' => mb_substr((string) ($created['message'] ?? ''), 0, 255),
    ];
    // What the courier says it will bill beats what the quote said.
    if (isset($created['charge']) && is_numeric($created['charge']) && (float) $created['charge'] >= 0) {
        $ready['shipping_charge'] = round((float) $created['charge'], 2);
    }
    // An account that auto-assigns answers with the AWB already on the
    // consignment, and with the courier it chose. Kept when the admin chose
    // none, so the row says which carrier really has the parcel; an admin's
    // own choice is never overwritten.
    $autoAwb       = trim((string) ($created['awb'] ?? ''));
    $autoCourierId = trim((string) ($created['courier_id'] ?? ''));
    if ($autoAwb !== '' && $courierId === '' && $autoCourierId !== '' && strlen($autoCourierId) <= 40) {
        $ready['courier_id'] = $autoCourierId;
    }

    // Only from 'pending': if anything moved the row meanwhile - an admin
    // released it as a booking that never finished - the courier booking is
    // orphaned and must not overwrite that, nor be given an AWB.
    $claimed = Database::update('shipments', $ready, "`id` = :id AND `status` = 'pending'", ['id' => $shipmentId]);
    if ($claimed === 0) {
        $note = 'Order #' . $orderId . ': ' . $provider['name'] . ' created consignment '
            . ($ready['shipment_ref'] !== '' ? $ready['shipment_ref'] : '(no reference)')
            . ' after its booking #' . $shipmentId . ' had been released. Cancel it in the courier panel.';
        log_activity('shipment.orphaned', 'shipment', $shipmentId, $note);
        ErrorHandler::log('warning', 'Shipping: ' . $note);
        return shipping_fail('This booking was released while the courier was still answering. ' . $provider['name']
            . ' created consignment ' . ($ready['shipment_ref'] !== '' ? $ready['shipment_ref'] : 'without a reference')
            . ' anyway - cancel it in the courier panel.', ['shipment_id' => $shipmentId]);
    }

    $lag = shipping_record_step($shipmentId, [
        'status'      => 'ready',
        'message'     => 'Booked with ' . $provider['name'],
        'location'    => (string) ($provider['pickup_city'] ?? ''),
        'occurred_at' => date('Y-m-d H:i:s'),
        'event_key'   => 'booking:' . $shipmentId,
    ]);

    log_activity('shipment.booked', 'shipment', $shipmentId,
        'Booked order #' . $orderId . ' with ' . $provider['name']);

    // --- an AWB that came with the booking ---------------------------------
    // Adopted here, the AWB request below finds it and asks the courier for
    // nothing. Left to that request, Shiprocket answered "Cannot reassign
    // courier" and the AWB had to be looked up again - the one we had just
    // been handed. Only onto a live row: one an admin cancelled meanwhile
    // keeps no AWB for the order to show.
    if ($autoAwb !== '') {
        $live = shipment_get($shipmentId);
        if ($live !== null && !shipping_is_dead((string) $live['status'])) {
            $lag = shipping_quiet_step($shipmentId, 'awb', static function () use ($shipmentId, $autoAwb, $created): void {
                shipping_adopt_awb($shipmentId, $autoAwb, trim((string) ($created['courier_name'] ?? '')));
            }) ?? $lag;
        }
    }

    // --- then the AWB ------------------------------------------------------
    // With the AWB adopted above this answers "AWB already assigned" and
    // records nothing, so the timeline holds one 'booked' either way.
    $awb = shipping_assign_awb($shipmentId, ['courier_id' => $courierId !== '' ? $courierId : null]);
    $lag = $awb['warning'] ?? $lag;

    return [
        'ok'          => true,
        'shipment_id' => $shipmentId,
        'awb'         => $awb['awb'] ?? null,
        'message'     => ($awb['ok'] ?? false
            ? 'Booked. AWB ' . $awb['awb'] . '.'
            : 'Booked, but the AWB was refused: ' . ($awb['message'] ?? 'unknown') . ' Request it again from the shipment.')
            . ($lag !== null ? ' ' . $lag : ''),
        'awb_ok'      => (bool) ($awb['ok'] ?? false),
    ];
}

// ===========================================================================
//  Booking without an admin
//
//  Off by default, and it stays off until somebody turns it on: this spends
//  money, hands parcels to couriers and moves orders with nobody watching.
//  Every refusal below leaves the order exactly as it was, so it is still
//  bookable by hand from admin/shipping/book.php - a refusal is never a dead
//  end, it is "a person should look at this one".
// ===========================================================================

/** Is unattended booking switched on? */
function shipping_autoship_enabled(): bool
{
    return (string) setting('shipping_auto_book_enabled', '0') === '1';
}

/**
 * The most cash an unattended booking will send a courier to collect.
 *
 * Zero means no ceiling. A cap exists because a COD parcel is goods handed to
 * a stranger against a promise of cash, and the loss on a refused one is the
 * whole order - so the value at which a human should look at it first is a
 * business decision, not a constant.
 */
function shipping_autoship_cod_limit(): float
{
    return max(0.0, (float) setting('shipping_auto_book_cod_max', 10000));
}

/** Order statuses an unattended booking will act on. */
function shipping_autoship_statuses(): array
{
    return [ORDER_STATUS_CONFIRMED, ORDER_STATUS_PROCESSING, ORDER_STATUS_PACKED];
}

/**
 * Book one order with the recommended courier, without an admin.
 *
 * Returns ['ok' => true, ...] on a booking, else ['ok' => false, 'refused' =>
 * <code>, 'message' => <why>, 'bookable_by_hand' => true].
 *
 * The refusal codes, each proved by its own case in the suite:
 *
 *   disabled        the setting is off
 *   no_order        there is no such order
 *   blocked         shipping_order_block_reason() says no - cancelled,
 *                   refunded, returned, delivered, or prepaid and unpaid
 *   not_confirmed   still pending: nobody has confirmed it
 *   already_shipped the order is already shipped or beyond
 *   already_shipping it already holds a live consignment
 *   manual_tracking someone entered an AWB by hand; a booking would replace it
 *   cod_over_limit  more cash than the ceiling allows
 *   no_courier      nothing quoted: a PIN nobody serves, no active
 *                   integration, or every one that quoted cannot book
 *   booking_failed  the courier refused the booking itself
 *
 * Only decisions ABOUT THIS ORDER are logged. "The feature is off" is not: it
 * is true of every order in the shop, and logging it would write a row per
 * order per sweep for as long as the switch stays off. The same reason is not
 * logged twice in a day either: most refusals here - a COD ceiling, a PIN
 * nobody serves - do not change on their own, and the order stays in the
 * sweep's queue, so one row per pass would bury the activity log within a
 * week. A refusal whose WORDING changes is a new fact and is recorded again.
 */
function shipping_autoship_order(int $orderId, array $parcel = []): array
{
    $refuse = static function (string $code, string $message, array $extra = []) use ($orderId): array {
        if (!in_array($code, ['disabled', 'no_order'], true)) {
            $note = 'Auto-ship refused: ' . $message;
            $said = Database::exists(
                'activity_logs',
                "`action` = 'shipment.autoship_refused' AND `entity` = 'order' AND `entity_id` = :o
                 AND `description` = :d AND `created_at` >= DATE_SUB(NOW(), INTERVAL 1 DAY)",
                ['o' => $orderId, 'd' => mb_substr($note, 0, 500)]
            );
            if (!$said) {
                log_activity('shipment.autoship_refused', 'order', $orderId, $note);
            }
        }
        return ['ok' => false, 'refused' => $code, 'message' => $message, 'bookable_by_hand' => true] + $extra;
    };

    if (!shipping_autoship_enabled()) {
        return $refuse('disabled', 'Automatic shipping is switched off.');
    }

    $order = shipping_order_payload($orderId);
    if ($order === null) {
        return $refuse('no_order', 'Order not found.');
    }

    // The same rule booking itself applies, asked of the service rather than
    // kept here as a second list that would drift from it.
    if (($blocked = shipping_order_block_reason($order)) !== null) {
        return $refuse('blocked', $blocked);
    }

    $status = (string) $order['status'];
    if ($status === ORDER_STATUS_PENDING) {
        return $refuse('not_confirmed', 'This order has not been confirmed yet.');
    }
    if (!in_array($status, shipping_autoship_statuses(), true)) {
        return $refuse('already_shipped', 'This order is already '
            . strtolower(ORDER_STATUSES[$status] ?? $status) . '.');
    }

    // Before the hand-entered check: a booked order carries the AWB we gave it,
    // which would otherwise read as somebody's manual entry.
    if (shipment_live_for_order($orderId) !== null) {
        return $refuse('already_shipping', 'This order already has a live consignment.');
    }
    if (trim((string) ($order['tracking_number'] ?? '')) !== '') {
        return $refuse('manual_tracking', 'This order already carries tracking number '
            . trim((string) $order['tracking_number']) . ', entered by hand.');
    }

    $limit = shipping_autoship_cod_limit();
    if ($order['is_cod'] && $limit > 0 && (float) $order['total_amount'] > $limit) {
        return $refuse('cod_over_limit', 'COD of ' . money((float) $order['total_amount'])
            . ' is over the ' . money($limit) . ' ceiling for automatic shipping.');
    }

    $pick = shipping_select_courier($orderId, $parcel);
    if (!$pick['ok'] || $pick['choice'] === null) {
        return $refuse(
            'no_courier',
            ($pick['message'] !== '' ? $pick['message'] : 'No courier quoted for this parcel.')
                . ($pick['errors'] !== [] ? ' ' . implode(' ', $pick['errors']) : ''),
            ['errors' => $pick['errors']]
        );
    }

    $choice = $pick['choice'];
    $opts   = [
        'weight_grams'     => (int) ($parcel['weight_grams'] ?? $order['estimated_grams']),
        'shipping_charge'  => round((float) $choice['cost'], 2),
        'courier'          => mb_substr((string) $choice['courier'], 0, 100),
        'selected_by'      => 'auto',
        'selection_score'  => (float) $choice['score'],
        'selection_reason' => 'Chosen automatically: ' . $pick['reason'],
    ];
    foreach (['length_cm', 'width_cm', 'height_cm'] as $key) {
        if (isset($parcel[$key]) && $parcel[$key] !== null) {
            $opts[$key] = (float) $parcel[$key];
        }
    }
    $courierId = trim((string) ($choice['courier_id'] ?? ''));
    if ($courierId !== '' && $courierId !== '0') {
        $opts['courier_id'] = mb_substr($courierId, 0, 40);
    }
    if (isset($choice['eta_days']) && is_numeric($choice['eta_days']) && (int) $choice['eta_days'] > 0) {
        $opts['eta_days'] = (int) $choice['eta_days'];
    }

    $result = shipping_book($orderId, (string) $choice['provider'], $opts);

    if (empty($result['ok'])) {
        // The courier refused. The shipment row records the failure; the order
        // keeps its slot and an admin can book it by hand from the screen.
        return $refuse('booking_failed', (string) ($result['message'] ?? 'The courier refused the booking.'),
            ['shipment_id' => $result['shipment_id'] ?? null]);
    }

    log_activity('shipment.autoship', 'order', $orderId,
        'Auto-ship booked order #' . $orderId . ' with ' . (string) $choice['courier']
        . ' (' . (string) ($choice['provider_name'] ?? $choice['provider']) . '), score '
        . number_format((float) $choice['score'], 1) . '. ' . $pick['reason']);

    return $result + ['refused' => null, 'choice' => $choice, 'reason' => $pick['reason']];
}

/**
 * How far back an unattended sweep will reach, in days. 0 means no floor.
 *
 * A floor exists because of what the FIRST pass does. The switch ships off, so
 * by the time anybody turns it on the shop has a tail of old orders that are
 * confirmed, packed or processing and were never shipped - abandoned, settled
 * by hand, written off, waiting on stock. Without a floor the very first cron
 * pass books couriers for all of them, oldest first, with nobody watching:
 * real money, real parcels, against orders whose customers stopped expecting
 * anything months ago. On this project's own scratch database an unbounded
 * first pass booked 24 consignments for orders years past.
 *
 * Seven days is the default because that is comfortably longer than any
 * healthy order spends waiting to be dispatched, and far shorter than a
 * backlog. Raising it is a deliberate act; 0 removes the floor entirely and
 * the settings screen says what that means.
 */
function shipping_autoship_max_age_days(): int
{
    $days = (int) setting('shipping_auto_book_max_age_days', 7);
    return $days <= 0 ? 0 : max(1, min(3650, $days));
}

/**
 * Orders an unattended booking would look at, oldest first.
 *
 * "Never attempted" rather than "has no live shipment": an order whose booking
 * the courier refused must not be retried every five minutes forever - that is
 * a courier's API being hammered and, on a bad day, a wallet being drained. A
 * failed booking is left for an admin, who can see what the courier said.
 *
 * Bounded in time by shipping_autoship_max_age_days(), for the reason given
 * there: the first pass after the switch is turned on must not reach into the
 * shop's whole history. An old order left behind is still bookable by hand,
 * which is the right way to dispatch one nobody has looked at in months.
 *
 * $orderIds narrows it to a chosen list, which is what a bulk "ship these"
 * action needs; empty means every eligible order. A named list is NOT aged
 * out: an admin who ticks an old order and presses "ship these" has looked at
 * it, which is exactly the judgement the floor exists to wait for.
 *
 * @return int[]
 */
function shipping_autoship_candidates(int $limit = 50, array $orderIds = []): array
{
    $limit = max(1, min(500, $limit));

    [$statusSql, $params] = Database::inPlaceholders(shipping_autoship_statuses(), 'st');
    $where   = ['o.`status` IN (' . $statusSql . ')'];
    $where[] = "(o.`tracking_number` IS NULL OR o.`tracking_number` = '')";
    $where[] = 'NOT EXISTS (SELECT 1 FROM `shipments` s WHERE s.`order_id` = o.`id`)';

    $maxAge = shipping_autoship_max_age_days();
    if ($maxAge > 0 && $orderIds === []) {
        $where[]       = 'o.`created_at` >= DATE_SUB(NOW(), INTERVAL :age DAY)';
        $params['age'] = $maxAge;
    }

    if ($orderIds !== []) {
        $ids = array_values(array_unique(array_filter(array_map('intval', $orderIds))));
        if ($ids === []) {
            return [];
        }
        [$sql, $bound] = Database::inPlaceholders($ids, 'oid');
        $where[]       = 'o.`id` IN (' . $sql . ')';
        $params       += $bound;
    }

    return array_map('intval', Database::fetchColumnAll(
        'SELECT o.`id` FROM `orders` o WHERE ' . implode(' AND ', $where)
        . ' ORDER BY o.`created_at` ASC, o.`id` ASC LIMIT ' . $limit,
        $params
    ));
}

/**
 * One pass of unattended booking, for a cron runner or a bulk action.
 *
 * Returns what it did, per order, so the runner can print it and a bulk action
 * can show it. Nothing is thrown: one order the courier refuses must not stop
 * the rest of the queue.
 */
function shipping_autoship_sweep(int $limit = 50, array $opts = []): array
{
    $out = ['enabled' => shipping_autoship_enabled(), 'looked_at' => 0, 'booked' => 0, 'refused' => 0, 'results' => []];
    if (!$out['enabled']) {
        return $out;
    }

    foreach (shipping_autoship_candidates($limit, (array) ($opts['order_ids'] ?? [])) as $orderId) {
        $out['looked_at']++;
        try {
            $one = shipping_autoship_order($orderId);
        } catch (Throwable $e) {
            ErrorHandler::log('warning', 'Auto-ship failed on order #' . $orderId . ': ' . $e->getMessage());
            $one = ['ok' => false, 'refused' => 'exception', 'message' => $e->getMessage()];
        }
        if (!empty($one['ok'])) {
            $out['booked']++;
            $out['results'][$orderId] = 'booked';
            continue;
        }
        $out['refused']++;
        $out['results'][$orderId] = (string) ($one['refused'] ?? 'refused');
    }

    return $out;
}

/**
 * Put one of our own steps (booked, AWB, pickup) on the timeline and settle
 * the order. The step has already happened at the courier, so a failure to
 * carry it onto the order is reported rather than thrown: the next tracking
 * update settles the order again, and an error page would invite the admin to
 * repeat a step that worked. Returns that report, or null.
 */
function shipping_record_step(int $shipmentId, array $event): ?string
{
    return shipping_quiet_step($shipmentId, (string) ($event['status'] ?? '?'),
        static function () use ($shipmentId, $event): void {
            shipping_record_events($shipmentId, [$event], 'manual');
        });
}

/**
 * Run the bookkeeping that follows a step the courier has already taken,
 * reporting a failure instead of throwing it - see shipping_record_step().
 */
function shipping_quiet_step(int $shipmentId, string $what, callable $step): ?string
{
    try {
        $step();
        return null;
    } catch (Throwable $e) {
        ErrorHandler::log('error', 'Shipping: shipment #' . $shipmentId . ' (' . $what . '): ' . $e->getMessage());
        return 'The order could not be updated to match just now; the next tracking update will do it.';
    }
}

/** Ask the courier for an AWB on a shipment that has been created but has none. */
function shipping_assign_awb(int $shipmentId, array $opts = []): array
{
    $shipment = shipment_get($shipmentId);
    if ($shipment === null) {
        return shipping_fail('Shipment not found.');
    }
    if ((string) $shipment['awb'] !== '') {
        return ['ok' => true, 'awb' => (string) $shipment['awb'], 'message' => 'AWB already assigned.'];
    }
    if ($shipment['status'] !== 'ready') {
        return shipping_fail('Only a shipment that is ready can be given an AWB.');
    }
    // The order may have been cancelled since the booking.
    $awbOrder = get_order((int) $shipment['order_id']);
    if ($awbOrder === null || ($blocked = shipping_order_block_reason($awbOrder)) !== null) {
        return shipping_fail($blocked ?? 'Order not found.');
    }

    $driver = ShippingProviderFactory::makeByCode((string) $shipment['provider_code']);
    if ($driver === null) {
        return shipping_fail('The courier for this shipment is no longer installed.');
    }

    // No courier asked for: the one chosen at booking. Without it a retry after
    // a refused AWB went out bare, and the aggregator assigned its default
    // courier at its own price.
    $courierId = trim((string) ($opts['courier_id'] ?? ''));
    if ($courierId === '') {
        $courierId = trim((string) ($shipment['courier_id'] ?? ''));
    }

    try {
        $res = $driver->generateAwb($shipment, $courierId !== '' ? ['courier_id' => $courierId] : []);
    } catch (Throwable $e) {
        $res = ['ok' => false, 'message' => 'Driver error: ' . $e->getMessage()];
    }

    if (empty($res['ok']) || (string) ($res['awb'] ?? '') === '') {
        Database::update('shipments', ['status_detail' => mb_substr((string) ($res['message'] ?? 'AWB refused.'), 0, 255)],
            '`id` = :id', ['id' => $shipmentId]);
        return shipping_fail((string) ($res['message'] ?? 'AWB refused.'));
    }

    $awb = (string) $res['awb'];
    $columns = [
        'awb'           => $awb,
        'courier_name'  => (string) ($res['courier_name'] ?? '') ?: $shipment['courier_name'],
        'tracking_url'  => shipping_tracking_url((string) $shipment['provider_code'], $awb),
    ];
    if ($courierId !== '' && strlen($courierId) <= 40) {
        $columns['courier_id'] = $courierId;
    }
    if (isset($res['charge']) && is_numeric($res['charge']) && (float) $res['charge'] >= 0) {
        $columns['shipping_charge'] = round((float) $res['charge'], 2);
    }

    // Only onto the row this AWB was asked for, re-read under its lock - the
    // way shipping_book() claims its own answer. A consignment cancelled while
    // the request was in flight (the order was cancelled and restocked, say)
    // was left cancelled locally while the courier held a live waybill, and
    // nothing said so. A second AWB request that got in first is refused here
    // too, so the order never points at the loser's waybill.
    $claimed = Database::transaction(static function () use ($shipmentId, $columns): bool {
        $locked = shipment_lock($shipmentId);
        if ($locked === null || (string) $locked['status'] !== 'ready' || (string) $locked['awb'] !== '') {
            return false;
        }
        Database::update('shipments', $columns, '`id` = :id', ['id' => $shipmentId]);
        return true;
    });
    if (!$claimed) {
        $now      = shipment_get($shipmentId);
        $nowLabel = $now === null ? 'gone' : strtolower(shipping_status_label((string) $now['status']));
        $courier  = (string) (shipping_provider((string) $shipment['provider_code'])['name'] ?? $shipment['provider_code']);
        $note     = 'Order #' . (int) $shipment['order_id'] . ': ' . $courier
            . ' issued AWB ' . $awb . ' for booking #' . $shipmentId . ', which is ' . $nowLabel
            . ($now !== null && (string) $now['awb'] !== '' ? ' and already carries AWB ' . $now['awb'] : '')
            . '. That waybill is live at the courier - cancel it in the courier panel.';
        log_activity('shipment.orphaned', 'shipment', $shipmentId, $note);
        ErrorHandler::log('warning', 'Shipping: ' . $note);
        return shipping_fail('This shipment changed while the courier was answering (it is ' . $nowLabel
            . '), so AWB ' . $awb . ' was not recorded here. It is live at the courier - cancel it in the courier panel.');
    }

    $lag = shipping_record_step($shipmentId, [
        'status'      => 'booked',
        'message'     => 'AWB ' . $awb . ' assigned' . (!empty($res['courier_name']) ? ' (' . $res['courier_name'] . ')' : ''),
        'location'    => '',
        'occurred_at' => date('Y-m-d H:i:s'),
        'event_key'   => 'awb:' . $awb,
    ]);

    return ['ok' => true, 'awb' => $awb, 'message' => 'AWB ' . $awb . ' assigned.' . ($lag !== null ? ' ' . $lag : ''),
            'warning' => $lag];
}

/** A public tracking link, where the courier offers one. */
function shipping_tracking_url(string $providerCode, string $awb): ?string
{
    if ($awb === '') {
        return null;
    }
    return [
        'shiprocket' => 'https://shiprocket.co/tracking/' . rawurlencode($awb),
    ][$providerCode] ?? null;
}

function shipping_generate_label(int $shipmentId): array
{
    $shipment = shipment_get($shipmentId);
    if ($shipment === null) {
        return shipping_fail('Shipment not found.');
    }
    $driver = ShippingProviderFactory::makeByCode((string) $shipment['provider_code']);
    if ($driver === null) {
        return shipping_fail('The courier for this shipment is no longer installed.');
    }

    try {
        $res = $driver->generateLabel($shipment);
    } catch (Throwable $e) {
        $res = ['ok' => false, 'message' => 'Driver error: ' . $e->getMessage()];
    }
    if (empty($res['ok'])) {
        return $res;
    }
    // url is set only for a label the courier hosts; null means "print the
    // hub's own", which is what a courier without label hosting gets.
    $url = shipping_courier_url($res['url'] ?? null);
    if ($url !== null) {
        Database::update('shipments', ['label_url' => $url], '`id` = :id', ['id' => $shipmentId]);
    }
    return ['url' => $url] + $res;
}

/**
 * Ask the courier for its pickup manifest covering these shipments.
 *
 * One courier per manifest - it is that courier's rider who signs it - and
 * only parcels still waiting for pickup: live, not finished, with an AWB.
 * url is the courier-hosted manifest when there is one (stored on each
 * shipment), else null and the hub's own manifest page is the document.
 *
 * @param int[] $shipmentIds
 * @return array{ok:bool, url:?string, message:string}
 */
function shipping_generate_manifest(array $shipmentIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $shipmentIds), static fn (int $id): bool => $id > 0)));
    if ($ids === []) {
        return shipping_fail('Choose at least one shipment for the manifest.', ['url' => null]);
    }
    if (count($ids) > 200) {
        return shipping_fail('A manifest covers at most 200 shipments.', ['url' => null]);
    }

    [$in, $params] = Database::inPlaceholders($ids, 'm');
    $rows = Database::fetchAll('SELECT * FROM `shipments` WHERE `id` IN (' . $in . ') ORDER BY `id`', $params);
    if (count($rows) !== count($ids)) {
        return shipping_fail('One of those shipments no longer exists.', ['url' => null]);
    }

    $codes = array_unique(array_column($rows, 'provider_code'));
    if (count($codes) !== 1) {
        return shipping_fail('A manifest covers one courier. Choose shipments booked with the same courier.', ['url' => null]);
    }
    foreach ($rows as $row) {
        $status = (string) $row['status'];
        if (shipping_is_terminal($status) || $status === 'pending') {
            return shipping_fail('Shipment #' . $row['id'] . ' is ' . strtolower(shipping_status_label($status))
                . ' - only parcels waiting for pickup go on a manifest.', ['url' => null]);
        }
        if ((string) $row['awb'] === '') {
            return shipping_fail('Shipment #' . $row['id'] . ' has no AWB yet.', ['url' => null]);
        }
    }

    $driver = ShippingProviderFactory::makeByCode((string) reset($codes));
    if ($driver === null) {
        return shipping_fail('The courier for these shipments is no longer installed.', ['url' => null]);
    }

    try {
        $res = $driver->generateManifest($rows);
    } catch (Throwable $e) {
        $res = ['ok' => false, 'message' => 'Driver error: ' . $e->getMessage()];
    }
    if (empty($res['ok'])) {
        return shipping_fail((string) ($res['message'] ?? 'The courier refused the manifest.'), ['url' => null]);
    }

    $url = shipping_courier_url($res['url'] ?? null);
    if ($url !== null) {
        Database::query('UPDATE `shipments` SET `manifest_url` = :u WHERE `id` IN (' . $in . ')', ['u' => $url] + $params);
    }
    log_activity('shipment.manifest', 'shipment', (int) $ids[0],
        'Manifest for ' . count($ids) . ' shipment(s): #' . implode(', #', $ids));

    return ['ok' => true, 'url' => $url, 'message' => (string) ($res['message'] ?? count($ids) . ' shipment(s) manifested.')];
}

function shipping_schedule_pickup(int $shipmentId, array $opts = []): array
{
    $shipment = shipment_get($shipmentId);
    if ($shipment === null) {
        return shipping_fail('Shipment not found.');
    }
    if (shipping_is_terminal((string) $shipment['status'])) {
        return shipping_fail('A ' . shipping_status_label((string) $shipment['status']) . ' shipment cannot be picked up.');
    }
    // Pressed on a page opened before the pickup scan: the courier already has
    // the parcel, and a second pickup request sends a rider for nothing.
    if (shipping_status_left_us((string) $shipment['status'])) {
        return shipping_fail('The courier already has this parcel (' . strtolower(shipping_status_label((string) $shipment['status']))
            . '), so there is nothing to pick up.');
    }
    $pickupOrder = get_order((int) $shipment['order_id']);
    if ($pickupOrder === null || ($blocked = shipping_order_block_reason($pickupOrder)) !== null) {
        return shipping_fail($blocked ?? 'Order not found.');
    }
    $driver = ShippingProviderFactory::makeByCode((string) $shipment['provider_code']);
    if ($driver === null) {
        return shipping_fail('The courier for this shipment is no longer installed.');
    }

    try {
        $res = $driver->schedulePickup($shipment, $opts);
    } catch (Throwable $e) {
        $res = ['ok' => false, 'message' => 'Driver error: ' . $e->getMessage()];
    }
    if (empty($res['ok'])) {
        return $res;
    }

    $date = (string) ($res['pickup_date'] ?? '');

    // Only onto a row still waiting for that pickup, re-read under its lock:
    // the status was checked before the courier call and never again, so a
    // consignment cancelled meanwhile kept the pickup date - and a rider was
    // sent for a parcel we had voided, with nothing recording it.
    $claimed = Database::transaction(static function () use ($shipmentId, $date): bool {
        $locked = shipment_lock($shipmentId);
        if ($locked === null || !in_array((string) $locked['status'], ['ready', 'booked', 'pickup_scheduled'], true)) {
            return false;
        }
        Database::update('shipments', ['pickup_date' => $date !== '' ? $date : null], '`id` = :id', ['id' => $shipmentId]);
        return true;
    });
    if (!$claimed) {
        $now      = shipment_get($shipmentId);
        $nowLabel = $now === null ? 'gone' : strtolower(shipping_status_label((string) $now['status']));
        $courier  = (string) (shipping_provider((string) $shipment['provider_code'])['name'] ?? $shipment['provider_code']);
        $note     = 'Order #' . (int) $shipment['order_id'] . ': ' . $courier
            . ' accepted a pickup' . ($date !== '' ? ' on ' . $date : '') . ' for booking #' . $shipmentId
            . ', which is ' . $nowLabel . '. Call that pickup off in the courier panel.';
        log_activity('shipment.orphaned', 'shipment', $shipmentId, $note);
        ErrorHandler::log('warning', 'Shipping: ' . $note);
        return shipping_fail('This shipment changed while the courier was answering (it is ' . $nowLabel
            . '), so the pickup was not recorded here. The courier has it booked - call it off in the courier panel.');
    }

    $lag = shipping_record_step($shipmentId, [
        'status'      => 'pickup_scheduled',
        'message'     => $date !== '' ? 'Pickup scheduled for ' . $date : 'Pickup requested',
        'location'    => '',
        'occurred_at' => date('Y-m-d H:i:s'),
        'event_key'   => 'pickup:' . $shipmentId . ':' . ($date !== '' ? $date : date('Ymd')),
    ]);
    if ($lag !== null) {
        $res['message'] = trim((string) ($res['message'] ?? '') . ' ' . $lag);
    }

    return $res;
}

/**
 * Serialise the courier-facing part of a job on one shipment, across requests
 * and across processes.
 *
 * An advisory lock, not a row lock: the courier call must not run inside a
 * transaction (a row lock held across a network call blocks every other
 * request behind it), and a named lock holds no InnoDB lock. Named per
 * database, because the scratch and live schemas share a server.
 *
 * Best effort. A caller that cannot get it in time carries on, because the
 * row lock inside the transaction is what keeps the data right; this only
 * stops N requests making N identical calls to the courier.
 *
 * @template T
 * @param callable():T $work
 * @return T
 */
function shipping_with_shipment_lock(int $shipmentId, callable $work, int $waitSeconds = 15)
{
    $key  = 'sik_shipment_' . DB_NAME . '_' . $shipmentId;
    $held = false;
    try {
        $held = (int) Database::fetchColumn('SELECT GET_LOCK(:k, ' . max(0, $waitSeconds) . ')', ['k' => $key]) === 1;
    } catch (Throwable $e) {
        ErrorHandler::log('warning', 'Shipping: no lock for shipment #' . $shipmentId . ': ' . $e->getMessage());
    }

    try {
        return $work();
    } finally {
        if ($held) {
            try {
                Database::fetchColumn('SELECT RELEASE_LOCK(:k)', ['k' => $key]);
            } catch (Throwable $e) {
                // The connection closing releases it anyway.
            }
        }
    }
}

/**
 * Cancel a consignment with the courier and here.
 *
 * Serialised per shipment: a customer's cancel, support's cancel and the
 * order-cancellation path can all arrive at once, and each one reaching the
 * courier meant N calls for one parcel - with every answer after the first an
 * "already cancelled" error that was reported as a failed cancellation.
 */
function shipping_cancel(int $shipmentId, string $reason = ''): array
{
    return shipping_with_shipment_lock($shipmentId,
        static fn (): array => shipping_cancel_serialised($shipmentId, $reason));
}

/** The cancellation itself; only ever called under shipping_with_shipment_lock(). */
function shipping_cancel_serialised(int $shipmentId, string $reason): array
{
    $shipment = shipment_get($shipmentId);
    if ($shipment === null) {
        return shipping_fail('Shipment not found.');
    }
    $status = (string) $shipment['status'];
    // Already cancelled is the outcome this call wanted. Reported as a failure
    // ("a cancelled shipment cannot be cancelled"), it refused the ORDER
    // cancellation behind it for every request that lost the race.
    if ($status === 'cancelled') {
        return ['ok' => true, 'message' => 'Shipment cancelled. The order can be booked again.'];
    }
    if (shipping_is_terminal($status)) {
        return shipping_fail('A ' . strtolower(shipping_status_label($status)) . ' shipment cannot be cancelled.');
    }
    // 'pending' lasts only while the courier call is in flight. Cancelling
    // it then would free the order, the call would succeed and write the row
    // back to 'ready' - and the order could be booked a second time. A pending
    // row that has outlived any request, though, is a booking that died, and
    // releasing it is the only way the order ever moves again.
    if ($status === 'pending') {
        return shipping_release_pending($shipment, $reason);
    }
    // With the courier already: the same rule order cancellation applies.
    // Cancelling here voided the consignment, freed the order for a second
    // one, and the real delivery was then ignored - no delivered, no COD.
    if (!shipping_is_cancellable($status)) {
        return shipping_fail('The parcel is already with the courier (' . strtolower(shipping_status_label($status))
            . '). Cancelling now would not stop it - handle it as a return (RTO) instead.');
    }

    // Nothing reached the courier yet: cancel locally, nothing to call.
    if ((string) $shipment['shipment_ref'] !== '') {
        $driver = ShippingProviderFactory::makeByCode((string) $shipment['provider_code']);
        if ($driver === null) {
            return shipping_fail('The courier for this shipment is no longer installed.');
        }
        try {
            $res = $driver->cancelShipment($shipment, $reason);
        } catch (Throwable $e) {
            $res = ['ok' => false, 'message' => 'Driver error: ' . $e->getMessage()];
        }
        if (empty($res['ok'])) {
            // A courier that errors on a consignment it has already cancelled
            // must not turn a cancellation that DID happen into a refusal. The
            // row is the truth: if it is dead now, the job is done.
            $now = shipment_get($shipmentId);
            if ($now !== null && (string) $now['status'] === 'cancelled') {
                return ['ok' => true, 'message' => 'Shipment cancelled. The order can be booked again.'];
            }
            return $res;
        }
    }

    // Applied under the shipment's row lock and only from a pre-pickup status,
    // re-read under that lock: a scan recorded while the courier call was in
    // flight either lands first (and is then overridden, if it was still
    // pre-pickup) or waits and finds the shipment cancelled. Success is
    // reported only for a row that really is cancelled.
    $settled = Database::transaction(static function () use ($shipmentId, $reason): array {
        $locked = shipment_lock($shipmentId);
        $now    = (string) ($locked['status'] ?? '');
        if ($locked === null || !shipping_is_cancellable($now)) {
            return ['refused' => $now];
        }
        shipping_insert_events($shipmentId, [[
            'status'      => 'cancelled',
            'message'     => 'Cancelled' . ($reason !== '' ? ': ' . $reason : ''),
            'location'    => '',
            'occurred_at' => date('Y-m-d H:i:s'),
            'event_key'   => 'cancel:' . $shipmentId,
        ]], 'manual');
        Database::update('shipments', [
            'status'        => 'cancelled',
            'status_detail' => mb_substr('Cancelled' . ($reason !== '' ? ': ' . $reason : ''), 0, 255),
        ], '`id` = :id', ['id' => $shipmentId]);
        return ['shipment' => array_merge($locked, ['status' => 'cancelled']), 'moved' => true, 'from' => $now, 'rto_after_delivery' => false];
    });

    if (isset($settled['refused'])) {
        $now = (string) $settled['refused'];
        if ($now === 'cancelled') {
            return ['ok' => true, 'message' => 'Shipment cancelled. The order can be booked again.'];
        }
        if ((string) $shipment['shipment_ref'] !== '') {
            $note = 'Shipment #' . $shipmentId . ': the courier accepted a cancellation, but the parcel had moved to '
                . $now . ' meanwhile. Check with the courier.';
            log_activity('shipment.mismatch', 'shipment', $shipmentId, $note);
            ErrorHandler::log('warning', 'Shipping: ' . $note);
        }
        return shipping_fail('The shipment moved on while it was being cancelled (now '
            . strtolower(shipping_status_label($now)) . '), so it was not cancelled here. Check with the courier - once picked up, getting it back is an RTO.');
    }

    // The order row still carries this consignment's AWB, and the storefront
    // shows it to the customer: shipping_after_events() clears it - but only if
    // it is this one, not a replacement booked since.
    shipping_after_events($settled);

    log_activity('shipment.cancelled', 'shipment', $shipmentId, 'Cancelled shipment #' . $shipmentId);

    return ['ok' => true, 'message' => 'Shipment cancelled. The order can be booked again.'];
}

/**
 * Free the order from a booking that never finished: a 'pending' row older
 * than any request can run. Its request died between our insert and the
 * courier's answer, so the courier may or may not hold a consignment for it -
 * which is why the admin is told to look.
 */
function shipping_release_pending(array $shipment, string $reason = ''): array
{
    $shipmentId = (int) $shipment['id'];
    if (!shipping_pending_is_stale($shipment)) {
        return shipping_fail('This booking is still being made. Try again in a moment - a booking that has not finished after '
            . SHIPPING_PENDING_STALE_MINUTES . ' minutes can be released.');
    }

    $provider = shipping_provider((string) $shipment['provider_code']);
    $courier  = (string) ($provider['name'] ?? $shipment['provider_code']);
    $detail   = 'Released: this booking never finished. ' . $courier
        . ' may still hold a consignment for it - check the courier panel and cancel it there.';

    // Conditional on the row still being pending AND old, measured by the
    // database clock the row was stamped with. shipping_book()'s own
    // pending->ready write is conditional on 'pending', so a request that
    // answers late cannot bring the row back.
    $moved = Database::query(
        "UPDATE `shipments` SET `status` = 'failed_booking', `status_detail` = :d
          WHERE `id` = :id AND `status` = 'pending'
            AND `created_at` < NOW() - INTERVAL " . (int) SHIPPING_PENDING_STALE_MINUTES . ' MINUTE',
        ['id' => $shipmentId, 'd' => mb_substr($detail, 0, 255)]
    )->rowCount();
    if ($moved === 0) {
        return shipping_fail('This booking changed while you were looking at it. Reload the page.');
    }

    log_activity('shipment.released', 'shipment', $shipmentId,
        'Released booking #' . $shipmentId . ' for order #' . (int) $shipment['order_id'] . ' that never finished'
            . ($reason !== '' ? ': ' . $reason : ''));

    return ['ok' => true, 'message' => 'Released the booking that never finished; the order can be booked again. First check the '
        . $courier . ' courier panel for a consignment it may have created for this order, and cancel it there.'];
}

/**
 * Can this courier's driver track a booking by its shipment reference alone,
 * before we hold an AWB? A driver says so with tracksByReference(). It is
 * optional and not part of the interface, so a driver without it answers no.
 * The poller uses this to decide which AWB-less bookings are worth asking about.
 */
function shipping_tracks_by_reference(string $providerCode): bool
{
    // Built from the code alone: no credentials, and nothing is called.
    $driver = ShippingProviderFactory::make(['code' => $providerCode]);
    return $driver !== null && method_exists($driver, 'tracksByReference') && $driver->tracksByReference() === true;
}

/**
 * Poll the courier for the latest scans.
 *
 * With no AWB on our side the booking is still asked about by its shipment
 * reference, where the driver can do that (Shiprocket). An AWB assigned in
 * the courier's panel, or by a request whose answer never reached us, exists
 * only at the courier, and before this a webhook was the only way it arrived.
 */
function shipping_refresh_tracking(int $shipmentId): array
{
    $shipment = shipment_get($shipmentId);
    if ($shipment === null) {
        return shipping_fail('Shipment not found.');
    }
    // A reverse consignment is asked about with checkReturnStatus(). Sent
    // through trackShipment() its "delivered" would come back meaning the
    // customer received it - so the poller, the webhook retry and the admin's
    // Refresh button all come through here and are handed over.
    if (shipping_is_return($shipment)) {
        return shipping_refresh_return($shipmentId);
    }
    if ((string) $shipment['awb'] === '' && (string) $shipment['shipment_ref'] === '') {
        return shipping_fail('No AWB to track yet.');
    }
    $driver = ShippingProviderFactory::makeByCode((string) $shipment['provider_code']);
    if ($driver === null) {
        return shipping_fail('The courier for this shipment is no longer installed.');
    }

    try {
        $res = $driver->trackShipment($shipment);
    } catch (Throwable $e) {
        $res = ['ok' => false, 'message' => 'Driver error: ' . $e->getMessage()];
    }
    if (empty($res['ok'])) {
        return $res;
    }

    $events  = (array) ($res['events'] ?? []);
    $found   = trim((string) ($res['awb'] ?? ''));
    $adopted = false;

    // A failure is an answer here, not an exception: a poll is repeated by the
    // next one (the cron, or the admin's button), which settles whatever this
    // one could not.
    try {
        // Re-read: the courier call takes a while, and the row may have died
        // meanwhile. A dead consignment keeps the AWB it died with.
        $now = shipment_get($shipmentId) ?? $shipment;
        if ($found !== '' && $found !== (string) $now['awb'] && !shipping_is_dead((string) $now['status'])) {
            // Before the events, so they settle a shipment that has its AWB,
            // and the order gets the AWB with them.
            shipping_adopt_awb($shipmentId, $found, trim((string) ($res['courier_name'] ?? '')));
            $adopted = true;
        } elseif ($found === '' && (string) $now['awb'] === '') {
            // Asked by reference, and the courier has no AWB either. 'booked'
            // means "AWB assigned": a headline saying so would move the row
            // past 'ready', where the AWB can no longer be requested, with no
            // AWB to show for it.
            $events = array_values(array_filter($events,
                static fn ($event): bool => is_array($event) && (string) ($event['status'] ?? '') !== 'booked'));
        }

        $added = shipping_record_events($shipmentId, $events, 'poll');
    } catch (Throwable $e) {
        ErrorHandler::log('error', 'Shipping: tracking poll for shipment #' . $shipmentId . ' failed: ' . $e->getMessage());
        return shipping_fail('The tracking update could not be applied just now. Try again in a moment.');
    }

    return ['ok' => true, 'added' => $added, 'awb_adopted' => $adopted ? $found : null,
            'message' => ($adopted ? 'AWB ' . $found . ' recorded from the courier. ' : '')
                . ($added === 0 ? 'Already up to date.' : $added . ' new tracking update(s).')];
}

// ===========================================================================
//  Events - the one place status changes
// ===========================================================================

/**
 * Store courier events and move the shipment, then the order, forward.
 *
 * Returns how many events were NEW. A resent webhook returns 0 - and still
 * settles the shipment and its order against everything stored, so a resend
 * or a poll repairs a shipment whose status lags its own timeline. Throws on
 * any failure, the order sync included, so the webhook answers 500 and the
 * courier's retry gets another go instead of a "duplicate, no change".
 *
 * Serialised per shipment: the events, the status they imply and the move are
 * one transaction under the shipment's row lock. Two couriers calls for one
 * parcel used to read the same old status, and the one carrying the LOWER
 * status could win the conditional update while the other - 'delivered',
 * say - was stored in the timeline and dropped from the status for good.
 */
function shipping_record_events(int $shipmentId, array $events, string $source = 'webhook'): int
{
    // Retried on a deadlock: this takes the shipment row, then a locking scan
    // of its timeline, while INSERT IGNORE takes key locks of its own, so two
    // courier calls for one parcel can still pick each other as victims. The
    // whole block is idempotent, so running it again is free - and the loser
    // used to reach the webhook as a 500, which Shiprocket does not promise to
    // retry.
    $settled = db_retry_deadlock(static fn (): ?array => Database::transaction(
        static function () use ($shipmentId, $events, $source): ?array {
            $shipment = shipment_lock($shipmentId);
            if ($shipment === null) {
                return null;
            }
            $added = shipping_insert_events($shipmentId, $events, $source);
            return ['added' => $added] + shipping_settle($shipment);
        }
    ));
    if ($settled === null) {
        return 0;
    }

    // Said once, when a new scan contradicts a delivery the courier already
    // reported - the one case the timeline cannot settle by itself.
    if ($settled['added'] > 0 && $settled['rto_after_delivery']) {
        $note = 'Shipment #' . $shipmentId . ' (AWB ' . ($settled['shipment']['awb'] ?? '-') . ') was reported delivered, '
            . 'then an RTO scan dated after the delivery arrived. It stays delivered - check with the courier.';
        log_activity('shipment.mismatch', 'shipment', $shipmentId, $note);
        ErrorHandler::log('warning', 'Shipping: ' . $note);
    }

    // After the commit, so the shipment is settled whatever happens next; a
    // failure here throws, and the retry finds the shipment already moved and
    // finishes the order.
    shipping_after_events($settled);

    return (int) $settled['added'];
}

/** The shipment row, locked for the rest of the transaction. */
function shipment_lock(int $shipmentId): ?array
{
    return $shipmentId > 0
        ? Database::fetch('SELECT * FROM `shipments` WHERE `id` = :id FOR UPDATE', ['id' => $shipmentId])
        : null;
}

/**
 * Insert a batch of courier events, skipping ones already stored.
 *
 * Only ever called under the shipment's row lock (inside a transaction), so a
 * failure later in the same transaction rolls these rows back too, and the
 * courier's retry is not mistaken for a duplicate.
 */
function shipping_insert_events(int $shipmentId, array $events, string $source): int
{
    $source = in_array($source, ['webhook', 'poll', 'manual'], true) ? $source : 'webhook';
    $added  = 0;

    foreach ($events as $event) {
        if (!is_array($event)) {
            continue;
        }
        $status = (string) ($event['status'] ?? '');
        if ($status === '' || shipping_status_rank($status) < 0) {
            continue;
        }

        $rawAt = $event['occurred_at'] ?? '';
        $at    = shipping_normalise_time($rawAt) ?? date('Y-m-d H:i:s');

        // A key from the event itself, never from when we heard of it: the
        // fallback hashes what the courier sent, so a resend matches.
        $key = mb_substr((string) ($event['event_key'] ?? ''), 0, 120);
        if ($key === '') {
            $key = substr(sha1($status . '|' . (is_scalar($rawAt) ? (string) $rawAt : '') . '|' . ($event['message'] ?? '')), 0, 40);
        }

        $stmt = Database::query(
            'INSERT IGNORE INTO `shipment_events`
                (`shipment_id`, `status`, `message`, `location`, `occurred_at`, `source`, `event_key`)
             VALUES (:s, :st, :m, :l, :at, :src, :k)',
            [
                's'   => $shipmentId,
                'st'  => $status,
                'm'   => mb_substr((string) ($event['message'] ?? ''), 0, 255),
                'l'   => mb_substr((string) ($event['location'] ?? ''), 0, 150),
                'at'  => $at,
                'src' => $source,
                'k'   => $key,
            ]
        );
        // 0 = seen before: a resend, or a poll catching up with a webhook.
        $added += $stmt->rowCount() > 0 ? 1 : 0;
    }

    return $added;
}

/**
 * Work out where the shipment stands from its WHOLE stored timeline, and move
 * it there. Called under the shipment's row lock.
 *
 * From the whole timeline in courier time order - not from the events that
 * happen to be new in this call - so the answer does not depend on which
 * request arrived first: an RTO scan that turns up late still turns the
 * "delivered" dated after it into the return leg's arrival, and a lost
 * advance is recovered by the next call of any kind.
 *
 * @return array{shipment:array, moved:bool, from:string, rto_after_delivery:bool}
 */
function shipping_settle(array $shipment): array
{
    $shipmentId = (int) $shipment['id'];
    $current    = (string) $shipment['status'];

    $timeline = Database::fetchAll(
        'SELECT `id`, `status`, `message`, `occurred_at`, `source` FROM `shipment_events`
          WHERE `shipment_id` = :s ORDER BY `occurred_at` ASC, `id` ASC FOR UPDATE',
        ['s' => $shipmentId]
    );

    // A reverse consignment is travelling TO us, so its scans are read on that
    // leg: the courier says "delivered" when the parcel reaches OUR warehouse.
    // Taking that at face value would mark the ORDER delivered - and a COD
    // order paid - for goods the customer sent back.
    $isReturn       = shipping_is_return($shipment);
    $inRto          = false;
    $best           = null;   // the most advanced status the timeline reaches
    $firstLeft      = null;   // earliest scan that says the courier had the parcel
    $firstDelivered = null;
    $rtoAfterDelivery = false;

    foreach ($timeline as $row) {
        $status = (string) $row['status'];

        // Our own cancellation is applied by shipping_cancel() itself, with its
        // event; a stored one is history, not an instruction to re-apply.
        if ($status === 'cancelled' && $row['source'] === 'manual') {
            continue;
        }

        if ($status === 'rto_initiated' || $status === 'rto_delivered') {
            $inRto = true;
            if ($firstDelivered !== null) {
                $rtoAfterDelivery = true;
            }
        }

        // Once a parcel is on its way back, a plain "delivered" is the return
        // leg arriving at our warehouse. Reading it as a delivery would mark
        // the order delivered - and a COD order PAID - for a parcel the
        // customer refused. The stored row is corrected too, so the timeline
        // the admin reads says what happened.
        if ($status === 'delivered' && ($inRto || $isReturn)) {
            // On a reverse consignment the arrival IS the return: 'returned' is
            // the status a customer return ends at, and the one the order reads
            // through shipping_order_status_for_shipment(). An RTO leg keeps
            // its own 'rto_delivered', which says the same thing about a parcel
            // nobody asked to send back.
            $arrival = $isReturn ? 'returned' : 'rto_delivered';
            Database::query('UPDATE `shipment_events` SET `status` = :a WHERE `id` = :id',
                ['a' => $arrival, 'id' => (int) $row['id']]);
            $status = $arrival;
        }

        if (shipping_status_rank($status) < 0) {
            continue;
        }
        if ($firstLeft === null && shipping_status_left_us($status)) {
            $firstLeft = (string) $row['occurred_at'];
        }
        if ($firstDelivered === null && $status === 'delivered') {
            $firstDelivered = (string) $row['occurred_at'];
        }
        // >= so the detail is the latest scan at that status (the second
        // day's "out for delivery", not the first).
        if ($best === null || shipping_status_rank($status) >= shipping_status_rank($best['status'])) {
            $best = ['status' => $status, 'message' => (string) $row['message']];
        }
    }

    $update = [];
    $moved  = false;
    if ($best !== null) {
        $target = $best['status'];

        // The one exception to "terminal means final": a shipment wrongly left
        // delivered (a manual override, or history from before this rule) that
        // the courier later confirms came back. Leaving it delivered would keep
        // a refused COD parcel marked paid and its stock off the shelf forever.
        $override = $current === 'delivered' && $target === 'rto_delivered';

        // By rank, never "equals what was read": under the lock the current
        // status is the real one.
        if ((!shipping_is_terminal($current) || $override)
            && shipping_status_rank($target) > shipping_status_rank($current)) {
            $update['status']        = $target;
            $update['status_detail'] = mb_substr($best['message'], 0, 255);
            $moved = true;
        }
    }

    // The courier's own times, from the scans themselves: a batch carrying
    // in_transit AND a later scan used to leave shipped_at empty forever.
    //
    // The EARLIEST such scan wins, not the first one to be written. Whichever
    // batch settles first stamps these, so a delivery arriving before the
    // in_transit scan that preceded it dated "shipped" hours or days late -
    // and the storefront, the invoice and the shipped email read it. Both
    // columns are 'Y-m-d H:i:s', where earlier sorts before later, and the
    // whole timeline is re-read on every call, so this self-heals.
    $final = (string) ($update['status'] ?? $current);
    $shipped   = (string) ($shipment['shipped_at'] ?? '');
    $delivered = (string) ($shipment['delivered_at'] ?? '');
    if ($firstLeft !== null && !shipping_is_dead($final) && ($shipped === '' || $firstLeft < $shipped)) {
        $update['shipped_at'] = $firstLeft;
    }
    if ($final === 'delivered' && $firstDelivered !== null && ($delivered === '' || $firstDelivered < $delivered)) {
        $update['delivered_at'] = $firstDelivered;
    }

    if ($update !== []) {
        Database::update('shipments', $update, '`id` = :id', ['id' => $shipmentId]);
    }

    return [
        'shipment'           => array_merge($shipment, $update),
        'moved'              => $moved,
        'from'               => $current,
        'rto_after_delivery' => $rtoAfterDelivery && $final === 'delivered',
    ];
}

/**
 * What follows a settled shipment, after its transaction has committed: the
 * dead AWB off the order, and the order brought into line. Throws when the
 * order cannot be moved.
 */
function shipping_after_events(array $settled): void
{
    $shipment = $settled['shipment'];

    // A courier-side cancellation, not just ours, must take the dead AWB off
    // the order the customer is looking at. Idempotent: only while the order
    // still points at this consignment.
    if ((string) $shipment['status'] === 'cancelled') {
        shipping_detach_order((int) $shipment['id'], (int) $shipment['order_id']);
    }

    shipping_sync_order($shipment, (bool) $settled['moved']);

    // Last, and only once the order agrees: the customer is told what the
    // courier did. After the sync because the order row is what carries the
    // AWB and the courier name into the mail, and because a sync that throws
    // is retried - a mail that had already gone out could not be.
    shipping_notify_shipment($shipment);
}

/** Remove a dead consignment's AWB from its order - only if the order still points at it. */
function shipping_detach_order(int $shipmentId, int $orderId): void
{
    Database::query(
        'UPDATE `orders` SET `tracking_number` = NULL, `courier_name` = NULL, `shipment_id` = NULL
          WHERE `id` = :o AND `shipment_id` = :s',
        ['o' => $orderId, 's' => $shipmentId]
    );
}

/**
 * Why this order must not be sent anywhere, or null if it may.
 *
 * Checked at booking AND at every later step that commits the courier - AWB,
 * pickup - because the order can be cancelled in between, and the retry paths
 * skipped the check booking made.
 */
function shipping_order_block_reason(array $order): ?string
{
    $status = (string) $order['status'];
    if (in_array($status, [ORDER_STATUS_CANCELLED, ORDER_STATUS_REFUNDED, ORDER_STATUS_RETURNED], true)) {
        return 'A ' . strtolower(ORDER_STATUSES[$status] ?? $status) . ' order cannot be shipped.';
    }
    // Delivered is done. A second consignment against it had the courier
    // collect COD again from a customer who had paid, and its RTO then moved
    // the order to returned - restocking goods the customer kept and marking
    // the real payment failed. A replacement goes out as its own order.
    if ($status === ORDER_STATUS_DELIVERED) {
        return 'This order is already delivered. Send a replacement as a new order.';
    }
    // Prepaid means paid first. An abandoned card payment leaves the order
    // pending with nothing collected; shipping it gives the goods away.
    // A partially refunded order HAS been paid - part of it has gone back, as
    // a price adjustment does - so it may still be dispatched. A fully
    // refunded one may not: there is nothing left holding the goods.
    $isCod = strtolower((string) ($order['payment_method'] ?? '')) === 'cod';
    if (!$isCod && !in_array((string) ($order['payment_status'] ?? ''),
        [PAYMENT_STATUS_PAID, PAYMENT_STATUS_PARTIALLY_REFUNDED], true)) {
        return 'This prepaid order has not been paid yet.';
    }
    return null;
}

/**
 * Called before an ORDER is cancelled. Cancels the live consignment with the
 * courier first, or refuses.
 *
 * Returns null when the order may be cancelled, else the reason it may not.
 * Without this, cancelling an order restored its stock while the parcel kept
 * moving: the courier delivered, collected the cash, and the cash matched a
 * cancelled order.
 */
function shipping_release_for_order_cancel(int $orderId): ?string
{
    $live = shipment_live_for_order($orderId);
    if ($live === null) {
        return null;
    }

    $status = (string) $live['status'];

    if ($status === 'pending') {
        // Not released automatically here: the courier may hold a consignment
        // for it, and the admin releasing it from the shipment page is told to
        // look. Saying where to go beats "try again" forever.
        return shipping_pending_is_stale($live)
            ? 'A courier booking for this order never finished. Release it from the shipment page first (and check the courier panel for a consignment it may have made), then cancel the order.'
            : 'A courier booking for this order is being made right now. Try again in a moment.';
    }

    // Physically with the courier: cancelling the order cannot stop the parcel.
    if (!shipping_is_cancellable($status)) {
        return 'The parcel is already with the courier (' . strtolower(shipping_status_label($status))
            . '). Cancelling the order would not stop it - handle it as a return (RTO) instead.';
    }

    $res = shipping_cancel((int) $live['id'], 'Order cancelled');
    if (empty($res['ok'])) {
        return 'The courier booking could not be cancelled, so the order was not either: ' . ($res['message'] ?? 'unknown error');
    }
    return null;
}
/**
 * Say so when a consignment we had written off reports that the parcel
 * reached the customer, or came back.
 *
 * A dead consignment (usually voided to rebook with another courier) no
 * longer speaks for its order, so shipping_sync_order() returns before the
 * mismatch alarm it keeps for a live one - and a rider who had already
 * collected the parcel delivered it, took the COD, and our books showed the
 * order packed and unpaid with nothing anywhere saying otherwise.
 *
 * Read from the stored timeline, not from this call's events: settle() leaves
 * a terminal 'cancelled' where it is, so the arrival only ever shows up as an
 * event row. Said once per consignment - the courier resends, and the poller
 * comes round again.
 */
function shipping_alert_dead_arrival(array $shipment, array $order): void
{
    $shipmentId = (int) $shipment['id'];
    if (!shipping_is_dead((string) $shipment['status'])) {
        return;
    }

    $reached = Database::fetchColumn(
        "SELECT `status` FROM `shipment_events`
          WHERE `shipment_id` = :s AND `status` IN ('delivered', 'rto_delivered', 'returned')
          ORDER BY `occurred_at` DESC, `id` DESC LIMIT 1",
        ['s' => $shipmentId]
    );
    if ($reached === null) {
        return;
    }
    if (Database::exists('activity_logs', "`entity` = 'shipment' AND `entity_id` = :s AND `action` = 'shipment.mismatch'",
        ['s' => $shipmentId])) {
        return;
    }

    $note = 'Courier reports ' . shipping_status_label((string) $reached) . ' for '
        . strtolower(shipping_status_label((string) $shipment['status'])) . ' consignment #' . $shipmentId
        . ' (AWB ' . ($shipment['awb'] ?? '-') . ') on ' . strtolower(ORDER_STATUSES[(string) $order['status']] ?? (string) $order['status'])
        . ' order ' . ($order['order_number'] ?? (int) $order['id']) . '. The parcel went out after we wrote the booking off - '
        . 'check payment and stock by hand.';
    log_activity('shipment.mismatch', 'shipment', $shipmentId, $note);
    ErrorHandler::log('warning', 'Shipping: ' . $note);
}

/**
 * Carry a shipment's state onto its order.
 *
 * Always: the AWB and courier go onto the order row, which is what the
 * storefront's order page and the customer emails already read - so the
 * customer sees tracking without any storefront change.
 *
 * Conditionally: the order status moves forward to match, through
 * update_order_status() so stock, COD payment, history and the customer email
 * all happen the way they would for a manual change.
 *
 * Idempotent, so it runs on every courier call, new events or not: that is
 * what lets a retry finish an order a failed call left behind. Throws when
 * the order cannot be moved, so that retry happens.
 *
 * @param bool $moved Whether this call moved the shipment; alerts are raised
 *        once, on the move, not on every resend after it.
 */
function shipping_sync_order(array $shipment, bool $moved = true): void
{
    $orderId = (int) $shipment['order_id'];
    $order   = get_order($orderId);
    if ($order === null) {
        return;
    }

    // A reverse consignment never carries its AWB onto the order (the customer
    // is still looking at the parcel that went out) and never holds the order's
    // slot. The one thing it can say is that the goods arrived back.
    if (shipping_is_return($shipment)) {
        shipping_sync_order_return($shipment, $order);
        return;
    }

    // Only the shipment that currently holds the order may speak for it. A
    // late webhook for a consignment that was cancelled and rebooked must not
    // overwrite the new one's AWB.
    $live = shipment_live_for_order($orderId);
    if ($live === null || (int) $live['id'] !== (int) $shipment['id']) {
        shipping_alert_dead_arrival($shipment, $order);
        return;
    }

    $columns = ['shipment_id' => (int) $shipment['id']];
    if ((string) ($shipment['awb'] ?? '') !== '') {
        $columns['tracking_number'] = (string) $shipment['awb'];
    }
    if ((string) ($shipment['courier_name'] ?? '') !== '') {
        $columns['courier_name'] = (string) $shipment['courier_name'];
    }
    if (!empty($shipment['expected_at'])) {
        $columns['estimated_delivery'] = (string) $shipment['expected_at'];
    }
    // The departure time the shipment now holds, corrected downwards too: the
    // order was stamped from the shipment when it moved, and by then a late
    // in_transit scan had not arrived (see shipping_settle()). The order row
    // is what the storefront's "Shipped on" and the invoice read. Only ever a
    // correction of a date the order already carries - stamping one on an
    // order that has not reached 'shipped' is update_order_status()'s job.
    if (!empty($shipment['shipped_at']) && !empty($order['shipped_at'])
        && (string) $shipment['shipped_at'] < (string) $order['shipped_at']) {
        $columns['shipped_at'] = (string) $shipment['shipped_at'];
    }
    // Only what differs: this now runs on every resend and poll.
    $columns = array_filter($columns, static fn ($value, string $column): bool =>
        (string) ($order[$column] ?? '') !== (string) $value, ARRAY_FILTER_USE_BOTH);
    if ($columns !== []) {
        Database::update('orders', $columns, '`id` = :id', ['id' => $orderId]);
    }

    $target = shipping_order_status_for((string) $shipment['status']);
    if ($target === null) {
        return;
    }

    $current = (string) $order['status'];

    // A courier cannot un-cancel an order - but a parcel that reached the
    // customer (or came back) after the order was cancelled is money and
    // stock out of step with the books. Say so loudly instead of returning
    // in silence, which is what hid it before.
    if (in_array($current, [ORDER_STATUS_CANCELLED, ORDER_STATUS_REFUNDED], true)) {
        if ($moved && in_array($target, [ORDER_STATUS_DELIVERED, ORDER_STATUS_RETURNED], true)) {
            $note = 'Courier reports ' . shipping_status_label((string) $shipment['status'])
                . ' for ' . strtolower(ORDER_STATUSES[$current] ?? $current) . ' order ' . ($order['order_number'] ?? $orderId)
                . ' (AWB ' . ($shipment['awb'] ?? '-') . '). Check payment and stock by hand.';
            log_activity('shipment.mismatch', 'shipment', (int) $shipment['id'], $note);
            ErrorHandler::log('warning', 'Shipping: ' . $note);
        }
        return;
    }
    if (shipping_order_rank($target) <= shipping_order_rank($current)) {
        return;
    }

    $result = update_order_status(
        $orderId,
        $target,
        'Courier: ' . shipping_status_label((string) $shipment['status'])
            . ((string) ($shipment['awb'] ?? '') !== '' ? ' (AWB ' . $shipment['awb'] . ')' : ''),
        'system',
        true,
        [
            // Re-checked on the locked order row: two courier calls for one
            // parcel both passed the check above and both restocked the order.
            'precondition' => static fn (array $locked): bool =>
                !in_array((string) $locked['status'], [ORDER_STATUS_CANCELLED, ORDER_STATUS_REFUNDED], true)
                && shipping_order_rank($target) > shipping_order_rank((string) $locked['status']),
            // The courier's times, not the time this call happened to run: a
            // delivery polled twelve days late reopened the return window.
            'shipped_at'   => $shipment['shipped_at'] ?? null,
            'delivered_at' => $shipment['delivered_at'] ?? null,
            // A COD parcel that came back was never paid for - but if the order
            // had been marked delivered first (by hand, or by a courier
            // "delivered" it later overrode), that delivery marked it paid.
            // Undone in the same transaction as the status, BEFORE the invoice
            // sync and the email read it. Only for a consignment that was
            // carrying the COD: one shipped prepaid for an already-paid order
            // coming back does not make that payment any less real.
            'cod_uncollected' => $target === ORDER_STATUS_RETURNED && (int) ($shipment['is_cod'] ?? 0) === 1,
        ]
    );
    if (empty($result['ok'])) {
        throw new RuntimeException('Shipment #' . (int) $shipment['id'] . ': order #' . $orderId . ' could not be moved to '
            . $target . ': ' . (string) ($result['message'] ?? 'unknown error'));
    }
}

// ===========================================================================
//  The reverse leg - returns and RTO
// ===========================================================================
//
// A parcel comes back in one of two ways, and they are NOT the same thing:
//
//   RTO           the courier could not deliver and turned the forward
//                 consignment round. There is no second consignment and no
//                 second AWB - the same row moves rto_initiated -> rto_delivered.
//                 Nobody asked for it, so there is no return_request behind it.
//
//   return pickup we book a courier to collect from the CUSTOMER. That is a
//                 consignment of its own, with its own AWB, its own scans and
//                 its own timeline - stored as a second `shipments` row with
//                 direction = 'return'.
//
// The reverse row is deliberately an ordinary shipment: the webhook resolves it
// by AWB, the poller polls it, the events table times it and shipping_settle()
// moves it, all without a second copy of any of that. What it must never do is
// speak for the ORDER - see shipment_live_for_order() and
// shipping_order_status_for_shipment().

/**
 * Book a courier to collect a return from the customer.
 *
 * Creates the reverse consignment against the forward one it is sending back.
 * Every refusal below is a state the courier would have accepted and we would
 * then have had to unpick by hand.
 *
 * @param array $opts return_request_id, reason, weight_grams
 * @return array{ok:bool, shipment_id?:int, awb?:?string, message:string}
 */
function shipping_book_return(int $forwardShipmentId, array $opts = []): array
{
    $forward = shipment_get($forwardShipmentId);
    if ($forward === null) {
        return shipping_fail('That consignment no longer exists.');
    }
    if (shipping_is_return($forward)) {
        return shipping_fail('That is already a return pickup. Book the reverse leg against the parcel that went out.');
    }

    // Only a parcel the customer actually has can be collected from them. One
    // still in transit is stopped by cancelling it, and one that came back as
    // an RTO is already here.
    if ((string) $forward['status'] !== 'delivered') {
        return shipping_fail('Only a delivered parcel can be collected back. This one is '
            . strtolower(shipping_status_label((string) $forward['status'])) . '.');
    }

    $orderId = (int) $forward['order_id'];
    $order   = shipping_order_payload($orderId);
    if ($order === null) {
        return shipping_fail('Order not found.');
    }

    // The customer's own request, where there is one. An RTO has none, and a
    // request nobody has approved is a decision still outstanding - booking a
    // courier for it would settle it by the back door.
    $requestId = (int) ($opts['return_request_id'] ?? 0);
    $request   = null;
    if ($requestId > 0) {
        $request = Database::fetch('SELECT * FROM `return_requests` WHERE `id` = :id', ['id' => $requestId]);
        if ($request === null || (int) $request['order_id'] !== $orderId) {
            return shipping_fail('That return request does not belong to this order.');
        }
        if ((string) $request['status'] !== RETURN_STATUS_APPROVED) {
            return shipping_fail('Approve the ' . (string) $request['type'] . ' request first - a courier booking is not a decision.');
        }
    }

    $provider = shipping_provider((string) $forward['provider_code']);
    if ($provider === null || $provider['status'] !== 'active') {
        return shipping_fail('The courier this parcel went out with is not active any more.');
    }
    if ((int) $provider['supports_return'] !== 1) {
        return shipping_fail('Reverse pickups are switched off for ' . $provider['name'] . '.');
    }
    $driver = ShippingProviderFactory::make($provider);
    if ($driver === null) {
        return shipping_fail('No driver is installed for ' . $provider['code'] . '.');
    }

    $reason = mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) ($opts['reason'] ?? ''))), 0, 255);
    if ($reason === '' && $request !== null) {
        $reason = return_reason_label((string) $request['reason']);
    }
    $grams = max(50, (int) ($opts['weight_grams'] ?? ($forward['weight_grams'] ?: $order['estimated_grams'])));

    // --- claim the order's return slot, under a lock, before calling out ---
    try {
        $claim = Database::transaction(static function () use ($orderId, $forward, $provider, $requestId, $reason, $grams): int {
            // The same lock shipping_book() takes, so a second "Book return"
            // pressed at the same moment waits here and then sees this row.
            $locked = Database::fetch('SELECT `id` FROM `orders` WHERE `id` = :id FOR UPDATE', ['id' => $orderId]);
            if ($locked === null) {
                throw new RuntimeException('Order not found.');
            }
            if (shipping_return_live_for_order($orderId) !== null) {
                throw new RuntimeException('This order already has a return pickup booked. Cancel it before booking another.');
            }

            return Database::insert('shipments', [
                'order_id'           => $orderId,
                'provider_id'        => (int) $provider['id'],
                'provider_code'      => (string) $provider['code'],
                'direction'          => 'return',
                'parent_shipment_id' => (int) $forward['id'],
                'return_request_id'  => $requestId > 0 ? $requestId : null,
                'return_reason'      => $reason !== '' ? $reason : null,
                'status'             => 'pending',
                // A reverse pickup collects goods, never cash: the courier is
                // coming to us. Leaving is_cod set would have the RTO rule in
                // update_order_status() reverse a payment that WAS collected at
                // the door on the way out.
                'is_cod'             => 0,
                'cod_amount'         => 0,
                'weight_grams'       => $grams,
                'length_cm'          => $forward['length_cm'],
                'width_cm'           => $forward['width_cm'],
                'height_cm'          => $forward['height_cm'],
                'courier_name'       => $forward['courier_name'],
            ]);
        });
    } catch (RuntimeException $e) {
        return shipping_fail($e->getMessage());
    }
    $returnId = (int) $claim;

    // --- create at the courier ---------------------------------------------
    try {
        $created = $driver->createReturn($forward, [
            'order'        => $order,
            'reason'       => $reason,
            'weight_grams' => $grams,
            'shipment_id'  => $returnId,
        ]);
    } catch (Throwable $e) {
        $created = ['ok' => false, 'message' => 'Driver error: ' . $e->getMessage()];
    }

    if (empty($created['ok'])) {
        // Frees the slot the way a refused forward booking does: a booking the
        // courier never took holds nothing.
        Database::update('shipments', [
            'status'        => 'failed_booking',
            'status_detail' => mb_substr((string) ($created['message'] ?? 'Return booking failed.'), 0, 255),
        ], "`id` = :id AND `status` = 'pending'", ['id' => $returnId]);
        return shipping_fail((string) ($created['message'] ?? 'Return booking failed.'), ['shipment_id' => $returnId]);
    }

    $awb = trim((string) ($created['awb'] ?? ''));
    $row = [
        // With an AWB the courier is coming; without one (Shiprocket issues it
        // later) the consignment exists but nothing is scheduled yet, and
        // saying otherwise puts a collection in front of a customer that
        // nobody has booked.
        'status'        => $awb !== '' ? 'return_pickup' : 'ready',
        'shipment_ref'  => (string) ($created['shipment_ref'] ?? ''),
        'order_ref'     => isset($created['order_ref']) && $created['order_ref'] !== '' ? (string) $created['order_ref'] : null,
        'status_detail' => mb_substr((string) ($created['message'] ?? ''), 0, 255),
    ];
    if ($awb !== '') {
        $row['awb']          = $awb;
        $row['tracking_url'] = shipping_tracking_url((string) $provider['code'], $awb);
    }
    if ((string) ($created['courier_name'] ?? '') !== '') {
        $row['courier_name'] = (string) $created['courier_name'];
    }

    // Only from 'pending', for the reason shipping_book() gives: anything that
    // moved the row meanwhile owns it now, and this answer is orphaned.
    $claimed = Database::update('shipments', $row, "`id` = :id AND `status` = 'pending'", ['id' => $returnId]);
    if ($claimed === 0) {
        $note = 'Order #' . $orderId . ': ' . $provider['name'] . ' created return consignment '
            . ($row['shipment_ref'] !== '' ? $row['shipment_ref'] : '(no reference)')
            . ' after its booking #' . $returnId . ' had been released. Cancel it in the courier panel.';
        log_activity('shipment.orphaned', 'shipment', $returnId, $note);
        ErrorHandler::log('warning', 'Shipping: ' . $note);
        return shipping_fail('This return booking was released while the courier was still answering - cancel consignment '
            . ($row['shipment_ref'] !== '' ? $row['shipment_ref'] : 'without a reference') . ' in the courier panel.',
            ['shipment_id' => $returnId]);
    }

    $lag = shipping_record_step($returnId, [
        'status'      => (string) $row['status'],
        'message'     => 'Return pickup booked with ' . $provider['name'] . ($reason !== '' ? ' - ' . $reason : ''),
        'location'    => (string) ($order['shipping_city'] ?? ''),
        'occurred_at' => date('Y-m-d H:i:s'),
        'event_key'   => 'return:' . $returnId,
    ]);

    log_activity('shipment.return_booked', 'shipment', $returnId,
        'Booked a return pickup for order #' . $orderId . ' with ' . $provider['name']
            . ($awb !== '' ? ' (AWB ' . $awb . ')' : ''));

    return [
        'ok'          => true,
        'shipment_id' => $returnId,
        'awb'         => $awb !== '' ? $awb : null,
        'message'     => ($awb !== ''
            ? 'Return pickup booked. AWB ' . $awb . '.'
            : 'Return pickup booked. The courier has not issued an AWB yet - it arrives with the first tracking update.')
            . ($lag !== null ? ' ' . $lag : ''),
    ];
}

/**
 * Ask the courier where a RETURN consignment is.
 *
 * The drivers answer this one with checkReturnStatus(), not trackShipment():
 * a reverse consignment's "delivered" means it reached US, and each driver
 * translates its own courier's reverse vocabulary. The answer is a status, not
 * a list of scans, so it is stored as one event with a stable key - a second
 * poll at the same status adds nothing.
 */
function shipping_refresh_return(int $returnShipmentId): array
{
    $shipment = shipment_get($returnShipmentId);
    if ($shipment === null) {
        return shipping_fail('Shipment not found.');
    }
    if (!shipping_is_return($shipment)) {
        return shipping_fail('That is not a return consignment.');
    }
    if ((string) $shipment['awb'] === '' && (string) $shipment['shipment_ref'] === '') {
        return shipping_fail('The courier has not issued a return AWB yet.');
    }
    $driver = ShippingProviderFactory::makeByCode((string) $shipment['provider_code']);
    if ($driver === null) {
        return shipping_fail('The courier for this return is no longer installed.');
    }

    try {
        $res = $driver->checkReturnStatus($shipment);
    } catch (Throwable $e) {
        $res = ['ok' => false, 'message' => 'Driver error: ' . $e->getMessage()];
    }
    if (empty($res['ok'])) {
        return ['return' => true] + $res;
    }

    $status = (string) ($res['status'] ?? '');
    if ($status === '' || shipping_status_rank($status) < 0) {
        // A vocabulary this hub does not have. Recording it would put a status
        // on the timeline that nothing can rank, label or explain.
        return ['ok' => true, 'return' => true, 'added' => 0,
                'message' => 'The courier answered with a status this hub does not recognise ('
                    . ($status !== '' ? $status : 'blank') . '). Nothing was changed.'];
    }

    try {
        $added = shipping_record_events($returnShipmentId, [[
            'status'      => $status,
            'message'     => mb_substr((string) ($res['message'] ?? shipping_status_label($status)), 0, 255),
            'location'    => '',
            'occurred_at' => date('Y-m-d H:i:s'),
            // Stable per (consignment, status): the courier sits at one status
            // for days while the poller comes round every hour.
            'event_key'   => 'return:' . $returnShipmentId . ':' . $status,
        ]], 'poll');
    } catch (Throwable $e) {
        ErrorHandler::log('error', 'Shipping: return poll for shipment #' . $returnShipmentId . ' failed: ' . $e->getMessage());
        return shipping_fail('The return update could not be applied just now. Try again in a moment.');
    }

    return ['ok' => true, 'return' => true, 'added' => $added,
            'message' => $added === 0 ? 'Already up to date.' : 'Return is ' . strtolower(shipping_status_label($status)) . '.'];
}

/**
 * Carry a RETURN consignment's state onto its order.
 *
 * Almost nothing does: the goods are with the customer until the parcel is
 * back. The arrival is the one event that moves the order, and it moves it
 * through update_order_status() so the stock goes back on the shelf, the
 * invoice is settled and the customer is told - exactly as a hand-marked
 * return would.
 */
function shipping_sync_order_return(array $shipment, array $order): void
{
    // A reverse pickup we wrote off, whose parcel turned up anyway. It no
    // longer speaks for the order, so nothing below would fire - and goods on
    // the dock with no record is exactly what wants saying out loud.
    if (shipping_is_dead((string) $shipment['status'])) {
        shipping_alert_dead_arrival($shipment, $order);
        return;
    }

    $target = shipping_order_status_for_shipment($shipment);
    if ($target === null) {
        return;
    }

    $orderId = (int) $order['id'];
    $current = (string) $order['status'];

    // A courier may not un-cancel an order. A return arriving against a
    // cancelled one is stock and money out of step with the books, which is
    // worth saying out loud rather than swallowing.
    if (in_array($current, [ORDER_STATUS_CANCELLED, ORDER_STATUS_REFUNDED], true)) {
        $note = 'Return consignment #' . (int) $shipment['id'] . ' (AWB ' . ($shipment['awb'] ?? '-') . ') arrived back for '
            . strtolower(ORDER_STATUSES[$current] ?? $current) . ' order ' . ($order['order_number'] ?? $orderId)
            . '. Check stock and any refund by hand.';
        log_activity('shipment.mismatch', 'shipment', (int) $shipment['id'], $note);
        ErrorHandler::log('warning', 'Shipping: ' . $note);
        return;
    }

    if (shipping_order_rank($target) <= shipping_order_rank($current)) {
        return;
    }

    // Only when there IS one. update_order_status() writes whatever is passed,
    // so an empty reason would blank the words the customer typed into their
    // own return request.
    $reason = trim((string) ($shipment['return_reason'] ?? ''));

    $result = update_order_status(
        $orderId,
        $target,
        'Return collected by ' . (trim((string) ($shipment['courier_name'] ?? '')) ?: 'the courier')
            . ((string) ($shipment['awb'] ?? '') !== '' ? ' (AWB ' . $shipment['awb'] . ')' : '')
            . ' and received back',
        'system',
        true,
        ($reason !== '' ? ['return_reason' => $reason] : []) + [
            // Re-checked on the LOCKED order row, for the reason the forward
            // sync gives: two courier calls for one parcel both passed the
            // check above and both restocked the order.
            'precondition' => static fn (array $locked): bool =>
                !in_array((string) $locked['status'], [ORDER_STATUS_CANCELLED, ORDER_STATUS_REFUNDED], true)
                && shipping_order_rank($target) > shipping_order_rank((string) $locked['status']),
            // Never here: a reverse pickup collects no cash, and the forward
            // leg's COD - if there was one - was collected at the door.
            'cod_uncollected' => false,
        ]
    );
    if (empty($result['ok'])) {
        throw new RuntimeException('Return #' . (int) $shipment['id'] . ': order #' . $orderId
            . ' could not be moved to ' . $target . ': ' . (string) ($result['message'] ?? 'unknown error'));
    }
}

/**
 * What a parcel coming back leaves owing, in the terms an owner asks in.
 *
 * Three questions, and none is answered by the shipment status alone:
 *
 *   stock_back       has the stock gone back on the shelf? That happens exactly
 *                    once, when the ORDER first enters a releasing status - see
 *                    update_order_status() - so the ORDER status is the answer,
 *                    not the courier's.
 *   cod_uncollected  cash the courier was told to collect and never did. An RTO
 *                    of a COD parcel is the whole order value that never came.
 *   refund_due       money we DID take and have not given back.
 *
 * @return array{has_return:bool, stage:string, stock_back:bool, cod_uncollected:float,
 *               refund_due:float, refund_done:bool, notes:string[]}
 */
function shipping_return_money(int $orderId): array
{
    $out = ['has_return' => false, 'stage' => 'none', 'stock_back' => false,
            'cod_uncollected' => 0.0, 'refund_due' => 0.0, 'refund_done' => false, 'notes' => []];

    $order = get_order($orderId);
    if ($order === null) {
        return $out;
    }

    $forward = shipment_live_for_order($orderId);
    $return  = shipping_return_live_for_order($orderId);
    $forwardStatus = (string) ($forward['status'] ?? '');
    $returnStatus  = (string) ($return['status'] ?? '');

    $rtoInFlight = $forwardStatus === 'rto_initiated';
    $rtoHome     = in_array($forwardStatus, ['rto_delivered', 'returned'], true);
    $returnHome  = $returnStatus === 'returned';
    $returnOut   = $return !== null && !$returnHome;

    $out['has_return'] = $rtoInFlight || $rtoHome || $return !== null;
    $out['stage'] = $rtoInFlight ? 'rto_transit'
        : ($rtoHome ? 'rto_delivered'
        : ($returnHome ? 'return_received'
        : ($returnOut ? 'return_booked' : 'none')));

    if (!$out['has_return']) {
        return $out;
    }

    // Stock goes back when the ORDER enters a releasing status, and only then.
    $out['stock_back'] = in_array((string) $order['status'], STOCK_RELEASING_STATUSES, true);
    if (!$out['stock_back']) {
        $out['notes'][] = $rtoInFlight || $returnOut
            ? 'Stock is still counted as sold: it goes back when the parcel reaches the warehouse.'
            : 'Stock has NOT gone back on the shelf - the order is still '
                . strtolower(ORDER_STATUSES[(string) $order['status']] ?? (string) $order['status']) . '.';
    }

    // Cash the courier was asked for and did not bring. Judged on the FORWARD
    // consignment, which is the one that carried the COD.
    $settled = in_array((string) $order['payment_status'], PAYMENT_STATUSES_SETTLED, true);
    if ($forward !== null && (int) $forward['is_cod'] === 1 && !$settled) {
        $out['cod_uncollected'] = round((float) ($forward['cod_amount'] ?: $order['total_amount']), 2);
        $out['notes'][] = 'COD of ' . money($out['cod_uncollected']) . ' was never collected - this order brought in nothing.';
    }

    // Money we actually took. A COD parcel that came back was never paid, so
    // there is nothing to give back; promising a refund there is the mistake
    // {{refund_note}} exists to avoid.
    if ((string) $order['payment_status'] === PAYMENT_STATUS_REFUNDED) {
        $out['refund_done'] = true;
        $out['notes'][] = 'The refund has been made.';
    } elseif ($settled && (float) $order['total_amount'] > 0) {
        // What is still owed, not what was charged. A partially refunded order
        // has already had some of it back, and reporting the whole total again
        // is how the same money gets sent twice - the desk is where an admin
        // decides how much to refund.
        $already = payment_refunded_total($orderId);
        $out['refund_due'] = round(max(0.0, (float) $order['total_amount'] - $already), 2);
        if ($out['refund_due'] > 0) {
            $out['notes'][] = 'A refund of ' . money($out['refund_due']) . ' is owed to the customer and has not been made.'
                . ($already > 0
                    ? ' ' . money($already) . ' of ' . money((float) $order['total_amount']) . ' has already gone back.'
                    : '');
        } else {
            $out['refund_done'] = true;
            $out['notes'][] = 'Everything taken on this order - ' . money($already) . ' - has already been refunded.';
        }
    }

    if ($out['notes'] === []) {
        $out['notes'][] = 'Nothing outstanding: the stock is back and no money changed hands.';
    }

    return $out;
}

// ===========================================================================
//  Telling the customer what the courier did
// ===========================================================================

/**
 * The milestone a shipment's CURRENT status is worth an email for, or null.
 *
 * Not every status is: 'ready' and 'booked' happen on our own shelf and mean
 * nothing to a customer, and a failed delivery attempt is followed by another
 * attempt the next day - mailing that would be alarming and, by the next
 * morning, wrong.
 *
 * Five of the eight reuse the order templates the shop already has, because
 * they say exactly the right thing and the customer expects one message per
 * event, not two. The three with no order status behind them - a pickup
 * booked, a parcel turning round, a reverse pickup - get templates of their own.
 *
 * @return array{milestone:string, template:string}|null
 */
function shipping_milestone_for(array $shipment): ?array
{
    $status = (string) ($shipment['status'] ?? '');

    $map = shipping_is_return($shipment)
        ? [
            // The reverse leg. 'ready' is in here because a courier that issues
            // the AWB later (Shiprocket) still has the pickup booked.
            'ready'         => ['return_pickup', 'shipment_return_pickup'],
            'return_pickup' => ['return_pickup', 'shipment_return_pickup'],
            'booked'        => ['return_pickup', 'shipment_return_pickup'],
            'returned'      => ['return_received', 'order_returned'],
        ]
        : [
            'pickup_scheduled' => ['pickup_scheduled', 'shipment_pickup_scheduled'],
            'in_transit'       => ['shipped', 'order_shipped'],
            'out_for_delivery' => ['out_for_delivery', 'order_out_for_delivery'],
            'delivered'        => ['delivered', 'order_delivered'],
            'rto_initiated'    => ['rto', 'shipment_rto'],
            'rto_delivered'    => ['returned', 'order_returned'],
            'returned'         => ['returned', 'order_returned'],
        ];

    if (!isset($map[$status])) {
        return null;
    }

    return ['milestone' => $map[$status][0], 'template' => $map[$status][1]];
}

/**
 * The consignment's own details, for a message about it.
 *
 * These override what order_notification_vars() read off the ORDER row, which
 * is right for the forward leg and essential for the reverse one: a return
 * pickup's AWB is never written to the order, so without this the customer
 * would be told to hand the parcel over against the outbound waybill.
 */
function shipping_notification_vars(array $order, array $shipment): array
{
    $awb     = trim((string) ($shipment['awb'] ?? ''));
    $courier = trim((string) ($shipment['courier_name'] ?? '')) ?: trim((string) ($order['courier_name'] ?? ''));
    $ourPage = canonical_url('track-order.php?order=' . urlencode((string) $order['order_number']));

    // The courier's own page when the hub holds one, this shop's tracking page
    // otherwise. Never a guess: an invented courier URL is a dead link in the
    // customer's inbox at the moment they most want it to work.
    $courierUrl = trim((string) ($shipment['tracking_url'] ?? ''));
    if ($courierUrl === '' && $awb !== '') {
        $courierUrl = (string) (shipping_tracking_url((string) ($shipment['provider_code'] ?? ''), $awb) ?? '');
    }

    return [
        'courier_name'         => $courier !== '' ? $courier : 'our delivery partner',
        'tracking_number'      => $awb !== '' ? $awb : 'will be shared shortly',
        'courier_tracking_url' => $courierUrl !== '' ? $courierUrl : $ourPage,
        'shipment_status'      => shipping_status_label((string) ($shipment['status'] ?? '')),
        'pickup_date'          => !empty($shipment['pickup_date'])
            ? format_date((string) $shipment['pickup_date'], 'd M Y')
            : 'the next available slot',
    ];
}

/**
 * Tell the customer what this consignment just did - once, ever.
 *
 * Returns the milestone that was queued, or null when there was nothing to say
 * (or it had already been said). Idempotency is two locks, not one:
 *
 *   shipment_notifications  one row per (consignment, milestone). A courier
 *                           that resends an event, and a poller catching up
 *                           with it, both find the row and stop.
 *   notification_queue      its unique idempotency key already blocks a second
 *                           (template, order, recipient). That is what keeps
 *                           this layer and update_order_status()'s own mail
 *                           from both landing in the inbox for one event.
 *
 * Never throws: a shipment is not left unsettled because a message could not be
 * queued, and the order sync that runs before this is the part worth retrying.
 */
function shipping_notify_shipment(array $shipment): ?string
{
    try {
        $milestone = shipping_milestone_for($shipment);
        if ($milestone === null) {
            return null;
        }

        $order = get_order((int) ($shipment['order_id'] ?? 0));
        if ($order === null) {
            return null;
        }

        return notify_shipment_milestone(
            $order,
            (int) ($shipment['id'] ?? 0),
            $milestone['milestone'],
            $milestone['template'],
            shipping_notification_vars($order, $shipment)
        ) ? $milestone['milestone'] : null;
    } catch (Throwable $e) {
        ErrorHandler::log('warning', 'Shipping: milestone notification for shipment #'
            . (int) ($shipment['id'] ?? 0) . ' failed: ' . $e->getMessage());
        return null;
    }
}
