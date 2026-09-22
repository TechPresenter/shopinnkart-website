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
 *      timeline but does not drag the shipment - or the order - backwards.
 *
 *   3. Events are idempotent. The (shipment, event_key) unique key turns a
 *      resent webhook or an overlapping poll into a no-op instead of a second
 *      timeline row and a second customer email.
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
    ][$shipmentStatus] ?? null;
}

/** Order statuses in the order they advance. Terminal ones rank highest. */
function shipping_order_rank(string $status): int
{
    return [
        ORDER_STATUS_PENDING          => 0,
        ORDER_STATUS_CONFIRMED        => 10,
        ORDER_STATUS_PROCESSING       => 20,
        ORDER_STATUS_PACKED           => 30,
        ORDER_STATUS_SHIPPED          => 40,
        ORDER_STATUS_OUT_FOR_DELIVERY => 50,
        ORDER_STATUS_DELIVERED        => 60,
        ORDER_STATUS_RETURNED         => 70,
        ORDER_STATUS_REFUNDED         => 80,
        ORDER_STATUS_CANCELLED        => 90,
    ][$status] ?? -1;
}

// ===========================================================================
//  Reads
// ===========================================================================

function shipment_get(int $id): ?array
{
    return $id > 0 ? Database::fetch('SELECT * FROM `shipments` WHERE `id` = :id', ['id' => $id]) : null;
}

/** Resolve a courier's reference to our record. Scoped by provider: AWB formats collide across couriers. */
function shipment_find(string $providerCode, string $awb = '', string $shipmentRef = ''): ?array
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
        return Database::fetch(
            'SELECT * FROM `shipments` WHERE `provider_code` = :p AND `shipment_ref` = :r ORDER BY `id` DESC LIMIT 1',
            ['p' => $providerCode, 'r' => $shipmentRef]
        );
    }
    return null;
}

function shipments_for_order(int $orderId): array
{
    return Database::fetchAll('SELECT * FROM `shipments` WHERE `order_id` = :o ORDER BY `id` DESC', ['o' => $orderId]);
}

/** The consignment currently holding the order, if any. */
function shipment_live_for_order(int $orderId): ?array
{
    return Database::fetch(
        "SELECT * FROM `shipments` WHERE `order_id` = :o AND `status` NOT IN ('cancelled','failed_booking')
          ORDER BY `id` DESC LIMIT 1",
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
    $order['is_cod']           = strtolower((string) $order['payment_method']) === 'cod';

    return $order;
}

// ===========================================================================
//  Rates
// ===========================================================================

/**
 * Quotes from every live courier for one order, cheapest first.
 *
 * One failing courier does not fail the lot - its error is carried alongside,
 * so the admin sees "Shiprocket: login failed" next to the mock's rates rather
 * than an empty screen.
 */
function shipping_quote_order(int $orderId, array $parcel = []): array
{
    $order = shipping_order_payload($orderId);
    if ($order === null) {
        return ['rates' => [], 'errors' => ['Order not found.']];
    }

    $rates  = [];
    $errors = [];

    foreach (ShippingProviderFactory::available() as $provider) {
        $driver = ShippingProviderFactory::make($provider);
        if ($driver === null) {
            continue;
        }

        if ($order['is_cod'] && (int) $provider['supports_cod'] !== 1) {
            $errors[] = $provider['name'] . ': COD is switched off for this courier.';
            continue;
        }

        $origin = (string) ($provider['pickup_pincode'] ?: setting('store_pincode', ''));
        if ($origin === '') {
            $errors[] = $provider['name'] . ': set a pickup PIN code on the integration first.';
            continue;
        }

        try {
            $res = $driver->getRates([
                'origin_pin'      => $origin,
                'destination_pin' => (string) $order['shipping_pincode'],
                'weight_grams'    => (int) ($parcel['weight_grams'] ?? $order['estimated_grams']),
                'is_cod'          => $order['is_cod'],
                'cod_amount'      => $order['is_cod'] ? (float) $order['total_amount'] : 0,
                'declared_value'  => (float) $order['total_amount'],
            ]);
        } catch (Throwable $e) {
            $res = ['ok' => false, 'message' => $e->getMessage()];
        }

        if (empty($res['ok'])) {
            $errors[] = $provider['name'] . ': ' . ($res['message'] ?? 'rates unavailable');
            continue;
        }
        foreach ((array) ($res['rates'] ?? []) as $rate) {
            $rates[] = $rate + ['provider' => (string) $provider['code'], 'provider_name' => (string) $provider['name']];
        }
        if (($res['rates'] ?? []) === []) {
            $errors[] = $provider['name'] . ': ' . ($res['message'] ?? 'no service for this PIN code');
        }
    }

    usort($rates, static fn (array $a, array $b): int => $a['cost'] <=> $b['cost']);

    return ['rates' => $rates, 'errors' => $errors, 'order' => $order];
}

// ===========================================================================
//  Booking
// ===========================================================================

/**
 * Book an order with a courier and ask for its AWB.
 *
 * Options: weight_grams, length_cm, width_cm, height_cm, courier_id (the
 * aggregator sub-courier chosen from a quote), courier (its display name).
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
    if (in_array($order['status'], [ORDER_STATUS_CANCELLED, ORDER_STATUS_REFUNDED, ORDER_STATUS_RETURNED], true)) {
        return shipping_fail('A ' . strtolower(ORDER_STATUSES[$order['status']] ?? $order['status']) . ' order cannot be shipped.');
    }
    if ($order['is_cod'] && (int) $provider['supports_cod'] !== 1) {
        return shipping_fail('COD is switched off for ' . $provider['name'] . '.');
    }

    $grams = max(50, (int) ($opts['weight_grams'] ?? $order['estimated_grams']));

    // --- claim the order's slot, under a lock, before calling out ----------
    try {
        $shipmentId = Database::transaction(static function () use ($orderId, $provider, $order, $grams, $opts): int {
            // FOR UPDATE serialises concurrent bookings of the same order: the
            // second waits here, then sees the first one's pending row.
            Database::query('SELECT `id` FROM `orders` WHERE `id` = :id FOR UPDATE', ['id' => $orderId]);

            if (shipment_live_for_order($orderId) !== null) {
                throw new RuntimeException('This order already has a live shipment. Cancel it before booking another.');
            }

            return Database::insert('shipments', [
                'order_id'      => $orderId,
                'provider_id'   => (int) $provider['id'],
                'provider_code' => (string) $provider['code'],
                'status'        => 'pending',
                'is_cod'        => $order['is_cod'] ? 1 : 0,
                'cod_amount'    => $order['is_cod'] ? (float) $order['total_amount'] : 0,
                'weight_grams'  => $grams,
                'length_cm'     => isset($opts['length_cm']) ? (float) $opts['length_cm'] : null,
                'width_cm'      => isset($opts['width_cm']) ? (float) $opts['width_cm'] : null,
                'height_cm'     => isset($opts['height_cm']) ? (float) $opts['height_cm'] : null,
                'courier_name'  => isset($opts['courier']) ? (string) $opts['courier'] : null,
            ]);
        });
    } catch (RuntimeException $e) {
        return shipping_fail($e->getMessage());
    }

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
        ], '`id` = :id', ['id' => $shipmentId]);
        return shipping_fail((string) ($created['message'] ?? 'Booking failed.'), ['shipment_id' => $shipmentId]);
    }

    Database::update('shipments', [
        'status'        => 'ready',
        'shipment_ref'  => (string) ($created['shipment_ref'] ?? ''),
        'order_ref'     => isset($created['order_ref']) && $created['order_ref'] !== '' ? (string) $created['order_ref'] : null,
        'courier_name'  => (string) ($created['courier_name'] ?? $opts['courier'] ?? '') ?: null,
        'status_detail' => mb_substr((string) ($created['message'] ?? ''), 0, 255),
    ], '`id` = :id', ['id' => $shipmentId]);

    shipping_record_events($shipmentId, [[
        'status'      => 'ready',
        'message'     => 'Booked with ' . $provider['name'],
        'location'    => (string) ($provider['pickup_city'] ?? ''),
        'occurred_at' => date('Y-m-d H:i:s'),
        'event_key'   => 'booking:' . $shipmentId,
    ]], 'manual');

    log_activity('shipment.booked', 'shipment', $shipmentId,
        'Booked order #' . $orderId . ' with ' . $provider['name']);

    // --- then the AWB ------------------------------------------------------
    $awb = shipping_assign_awb($shipmentId, ['courier_id' => $opts['courier_id'] ?? null]);

    return [
        'ok'          => true,
        'shipment_id' => $shipmentId,
        'awb'         => $awb['awb'] ?? null,
        'message'     => $awb['ok'] ?? false
            ? 'Booked. AWB ' . $awb['awb'] . '.'
            : 'Booked, but the AWB was refused: ' . ($awb['message'] ?? 'unknown') . ' Request it again from the shipment.',
        'awb_ok'      => (bool) ($awb['ok'] ?? false),
    ];
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

    $driver = ShippingProviderFactory::makeByCode((string) $shipment['provider_code']);
    if ($driver === null) {
        return shipping_fail('The courier for this shipment is no longer installed.');
    }

    try {
        $res = $driver->generateAwb($shipment, array_filter(['courier_id' => $opts['courier_id'] ?? null]));
    } catch (Throwable $e) {
        $res = ['ok' => false, 'message' => 'Driver error: ' . $e->getMessage()];
    }

    if (empty($res['ok']) || (string) ($res['awb'] ?? '') === '') {
        Database::update('shipments', ['status_detail' => mb_substr((string) ($res['message'] ?? 'AWB refused.'), 0, 255)],
            '`id` = :id', ['id' => $shipmentId]);
        return shipping_fail((string) ($res['message'] ?? 'AWB refused.'));
    }

    $awb = (string) $res['awb'];
    Database::update('shipments', [
        'awb'           => $awb,
        'courier_name'  => (string) ($res['courier_name'] ?? '') ?: $shipment['courier_name'],
        'tracking_url'  => shipping_tracking_url((string) $shipment['provider_code'], $awb),
    ], '`id` = :id', ['id' => $shipmentId]);

    shipping_record_events($shipmentId, [[
        'status'      => 'booked',
        'message'     => 'AWB ' . $awb . ' assigned' . (!empty($res['courier_name']) ? ' (' . $res['courier_name'] . ')' : ''),
        'location'    => '',
        'occurred_at' => date('Y-m-d H:i:s'),
        'event_key'   => 'awb:' . $awb,
    ]], 'manual');

    return ['ok' => true, 'awb' => $awb, 'message' => 'AWB ' . $awb . ' assigned.'];
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
    if (!empty($res['ok']) && !empty($res['url'])) {
        Database::update('shipments', ['label_url' => (string) $res['url']], '`id` = :id', ['id' => $shipmentId]);
    }
    return $res;
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
    Database::update('shipments', ['pickup_date' => $date !== '' ? $date : null], '`id` = :id', ['id' => $shipmentId]);

    shipping_record_events($shipmentId, [[
        'status'      => 'pickup_scheduled',
        'message'     => $date !== '' ? 'Pickup scheduled for ' . $date : 'Pickup requested',
        'location'    => '',
        'occurred_at' => date('Y-m-d H:i:s'),
        'event_key'   => 'pickup:' . $shipmentId . ':' . ($date !== '' ? $date : date('Ymd')),
    ]], 'manual');

    return $res;
}

function shipping_cancel(int $shipmentId, string $reason = ''): array
{
    $shipment = shipment_get($shipmentId);
    if ($shipment === null) {
        return shipping_fail('Shipment not found.');
    }
    if (shipping_is_terminal((string) $shipment['status'])) {
        return shipping_fail('A ' . strtolower(shipping_status_label((string) $shipment['status'])) . ' shipment cannot be cancelled.');
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
            return $res;
        }
    }

    shipping_record_events($shipmentId, [[
        'status'      => 'cancelled',
        'message'     => 'Cancelled' . ($reason !== '' ? ': ' . $reason : ''),
        'location'    => '',
        'occurred_at' => date('Y-m-d H:i:s'),
        'event_key'   => 'cancel:' . $shipmentId,
    ]], 'manual');

    // The order row still carries this consignment's AWB, and the storefront
    // shows it to the customer. Clear it - but only if it is this one, not a
    // replacement booked since.
    Database::query(
        'UPDATE `orders` SET `tracking_number` = NULL, `courier_name` = NULL, `shipment_id` = NULL
          WHERE `id` = :o AND `shipment_id` = :s',
        ['o' => (int) $shipment['order_id'], 's' => $shipmentId]
    );

    log_activity('shipment.cancelled', 'shipment', $shipmentId, 'Cancelled shipment #' . $shipmentId);

    return ['ok' => true, 'message' => 'Shipment cancelled. The order can be booked again.'];
}

/** Poll the courier for the latest scans. */
function shipping_refresh_tracking(int $shipmentId): array
{
    $shipment = shipment_get($shipmentId);
    if ($shipment === null) {
        return shipping_fail('Shipment not found.');
    }
    if ((string) $shipment['awb'] === '') {
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

    $added = shipping_record_events($shipmentId, (array) ($res['events'] ?? []), 'poll');

    return ['ok' => true, 'added' => $added,
            'message' => $added === 0 ? 'Already up to date.' : $added . ' new tracking update(s).'];
}

// ===========================================================================
//  Events - the one place status changes
// ===========================================================================

/**
 * Store courier events and move the shipment, then the order, forward.
 *
 * Returns how many events were NEW. A resent webhook returns 0 and changes
 * nothing, which is what makes it safe for a courier to retry.
 */
function shipping_record_events(int $shipmentId, array $events, string $source = 'webhook'): int
{
    $shipment = shipment_get($shipmentId);
    if ($shipment === null || $events === []) {
        return 0;
    }

    $added = 0;
    $best  = null;   // the most advanced status among the NEW events

    foreach ($events as $event) {
        $status = (string) ($event['status'] ?? '');
        if ($status === '' || shipping_status_rank($status) < 0) {
            continue;
        }

        // Once a parcel is on its way back, a plain "delivered" is the return
        // leg arriving at our warehouse. Reading it as a delivery would mark
        // the order delivered - and a COD order PAID - for a parcel the
        // customer refused.
        if ($status === 'delivered' && in_array((string) $shipment['status'], ['rto_initiated', 'rto_delivered'], true)) {
            $status = 'rto_delivered';
        }

        $key = mb_substr((string) ($event['event_key'] ?? ''), 0, 120);
        if ($key === '') {
            $key = substr(sha1($status . '|' . ($event['occurred_at'] ?? '') . '|' . ($event['message'] ?? '')), 0, 40);
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
                'at'  => (string) ($event['occurred_at'] ?? date('Y-m-d H:i:s')),
                'src' => in_array($source, ['webhook', 'poll', 'manual'], true) ? $source : 'webhook',
                'k'   => $key,
            ]
        );

        if ($stmt->rowCount() === 0) {
            continue;   // seen before: a resend, or a poll catching up with a webhook
        }
        $added++;

        if ($best === null || shipping_status_rank($status) > shipping_status_rank($best['status'])) {
            $best = ['status' => $status] + $event;
        }
    }

    if ($best === null) {
        return $added;
    }

    // --- advance the shipment, never regress it ----------------------------
    $current = (string) $shipment['status'];
    $next    = (string) $best['status'];

    if (!shipping_is_terminal($current) && shipping_status_rank($next) > shipping_status_rank($current)) {
        $update = [
            'status'        => $next,
            'status_detail' => mb_substr((string) ($best['message'] ?? ''), 0, 255),
        ];
        if ($next === 'in_transit' && empty($shipment['shipped_at'])) {
            $update['shipped_at'] = (string) ($best['occurred_at'] ?? date('Y-m-d H:i:s'));
        }
        if ($next === 'delivered') {
            $update['delivered_at'] = (string) ($best['occurred_at'] ?? date('Y-m-d H:i:s'));
        }
        Database::update('shipments', $update, '`id` = :id', ['id' => $shipmentId]);
        $shipment = array_merge($shipment, $update);
    }

    shipping_sync_order($shipment);

    return $added;
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
 */
function shipping_sync_order(array $shipment): void
{
    $orderId = (int) $shipment['order_id'];
    $order   = get_order($orderId);
    if ($order === null) {
        return;
    }

    // Only the shipment that currently holds the order may speak for it. A
    // late webhook for a consignment that was cancelled and rebooked must not
    // overwrite the new one's AWB.
    $live = shipment_live_for_order($orderId);
    if ($live === null || (int) $live['id'] !== (int) $shipment['id']) {
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
    Database::update('orders', $columns, '`id` = :id', ['id' => $orderId]);

    $target = shipping_order_status_for((string) $shipment['status']);
    if ($target === null) {
        return;
    }

    $current = (string) $order['status'];
    // Terminal orders stay put: a courier cannot un-cancel or un-refund.
    if (in_array($current, [ORDER_STATUS_CANCELLED, ORDER_STATUS_REFUNDED], true)) {
        return;
    }
    if (shipping_order_rank($target) <= shipping_order_rank($current)) {
        return;
    }

    update_order_status(
        $orderId,
        $target,
        'Courier: ' . shipping_status_label((string) $shipment['status'])
            . ((string) ($shipment['awb'] ?? '') !== '' ? ' (AWB ' . $shipment['awb'] . ')' : ''),
        'system'
    );
}
