<?php
/**
 * ShopInnKart - Shiprocket driver.
 *
 * Shiprocket is an aggregator: one account books with Delhivery, Blue Dart,
 * DTDC, Ecom Express, XpressBees and others, so this one driver covers most of
 * the couriers the hub was asked to support. `shipments.provider_code` records
 * that Shiprocket booked a consignment; `courier_name` records which carrier
 * actually moves it.
 *
 * Written against Shiprocket's external API v1 (apiv2.shiprocket.in/v1/external).
 * The response mapping is verified against recorded fixtures; the live calls are
 * only as verified as the first real connection test.
 *
 * Four things about this API that shape the code below:
 *
 *   1. Auth is an email/password login that returns a bearer token valid for
 *      ten days. Logging in on every call would work until Shiprocket rate-limits
 *      the login endpoint, so the token is cached in the encrypted credentials
 *      and refreshed a day before it expires, or immediately on a 401.
 *
 *   2. `pickup_location` on an order is the NICKNAME of a pickup address
 *      registered in the Shiprocket dashboard, not an address. A booking with a
 *      nickname Shiprocket does not know fails, so it is a required credential.
 *
 *   3. Weight is in kilograms and dimensions in centimetres. The hub stores
 *      grams; the conversion lives here and nowhere else.
 *
 *   4. Status labels are free text ("REACHED AT DESTINATION HUB"). They are
 *      mapped to the hub's own vocabulary by keyword, in an order that matters -
 *      "RTO DELIVERED" contains "DELIVERED", and must not be read as delivered.
 */

declare(strict_types=1);

final class ShiprocketShippingProvider implements ShippingProviderInterface
{
    private const BASE = 'https://apiv2.shiprocket.in/v1/external';

    /** Refresh this long before the ten-day expiry, so a token never lapses mid-booking. */
    private const TOKEN_MARGIN = 86400;

    private array $provider;

    /** @var callable|null */
    private $transport;

    /**
     * @param callable|null $transport replaces cURL; tests pass one in so the
     *                                  mapping can be checked without a network
     */
    public function __construct(array $provider = [], ?callable $transport = null)
    {
        $this->provider  = $provider;
        $this->transport = $transport;
    }

    public function code(): string
    {
        return 'shiprocket';
    }

    public function label(): string
    {
        return 'Shiprocket';
    }

    public function credentialFields(): array
    {
        return [
            'email' => [
                'label'    => 'API user email',
                'type'     => 'text',
                'required' => true,
                'help'     => 'Create a dedicated API user in Shiprocket: Settings → API → Configure. Your own login works too, but an API user can be revoked on its own.',
            ],
            'password' => [
                'label'    => 'API user password',
                'type'     => 'password',
                'required' => true,
            ],
            'pickup_location' => [
                'label'    => 'Pickup location nickname',
                'type'     => 'text',
                'required' => true,
                'help'     => 'Exactly as it appears in Shiprocket under Settings → Pickup Addresses, e.g. "Primary". This is a name, not an address.',
            ],
            'channel_id' => [
                'label'    => 'Channel ID',
                'type'     => 'text',
                'required' => false,
                'help'     => 'Optional. Leave blank to use your custom channel.',
            ],
        ];
    }

    // ---------------------------------------------------------------------
    //  Auth
    // ---------------------------------------------------------------------

    /** A valid bearer token, logging in only when the cached one is near expiry. */
    private function token(bool $force = false): ?string
    {
        $creds   = shipping_credentials($this->provider);
        $cached  = (string) ($creds['_token'] ?? '');
        $expires = (int) ($creds['_token_expires'] ?? 0);

        if (!$force && $cached !== '' && $expires - self::TOKEN_MARGIN > time()) {
            return $cached;
        }

        $email    = (string) ($creds['email'] ?? '');
        $password = (string) ($creds['password'] ?? '');
        if ($email === '' || $password === '') {
            return null;
        }

        $res = shipping_http('shiprocket', 'auth', 'POST', self::BASE . '/auth/login', [
            'json'      => ['email' => $email, 'password' => $password],
            'retries'   => 1,
            'transport' => $this->transport,
        ]);

        $token = (string) ($res['body']['token'] ?? '');
        if (!$res['ok'] || $token === '') {
            return null;
        }

        // Cached inside the encrypted blob, so a database dump alone does not
        // yield a working session. The underscore keeps it out of the admin
        // form, which renders only the fields credentialFields() declares.
        $creds['_token']         = $token;
        $creds['_token_expires'] = time() + 10 * 86400;
        if (!empty($this->provider['id'])) {
            shipping_store_credentials((int) $this->provider['id'], $creds);
            $this->provider['credentials'] = Database::fetchColumn(
                'SELECT `credentials` FROM `shipping_providers` WHERE `id` = :id',
                ['id' => (int) $this->provider['id']]
            );
        }

        return $token;
    }

    /**
     * An authenticated call. A 401 means the cached token was revoked early -
     * log in once more and retry once, rather than failing a booking over a
     * token Shiprocket has already forgotten.
     */
    private function call(string $operation, string $method, string $path, array $opts = []): array
    {
        $token = $this->token();
        if ($token === null) {
            return ['ok' => false, 'status' => 0, 'body' => null, 'raw' => '',
                    'error' => 'Shiprocket login failed. Check the API email and password.'];
        }

        $opts['transport'] = $this->transport;
        $opts['headers']   = ['Authorization' => 'Bearer ' . $token] + (array) ($opts['headers'] ?? []);

        $res = shipping_http('shiprocket', $operation, $method, self::BASE . $path, $opts);

        if ($res['status'] === 401) {
            $token = $this->token(true);
            if ($token !== null) {
                $opts['headers']['Authorization'] = 'Bearer ' . $token;
                $res = shipping_http('shiprocket', $operation, $method, self::BASE . $path, $opts);
            }
        }

        return $res;
    }

    public function testConnection(): array
    {
        $creds = shipping_credentials($this->provider);
        if (empty($creds['email']) || empty($creds['password'])) {
            return shipping_fail('Enter the API user email and password, save, then test.');
        }

        $token = $this->token(true);
        if ($token === null) {
            return shipping_fail('Shiprocket rejected the login. Check the API user email and password.');
        }

        // Login alone proves the password, not that the account can book. The
        // pickup nickname is the thing most often wrong, so check it exists.
        $res = $this->call('test', 'GET', '/settings/company/pickup');
        if (!$res['ok']) {
            return shipping_fail('Logged in, but could not read pickup addresses: ' . $res['error']);
        }

        $want  = strtolower(trim((string) ($creds['pickup_location'] ?? '')));
        $names = [];
        foreach ((array) ($res['body']['data']['shipping_address'] ?? []) as $address) {
            $names[] = (string) ($address['pickup_location'] ?? '');
        }

        if ($want !== '' && !in_array($want, array_map('strtolower', $names), true)) {
            return shipping_fail('Logged in, but no pickup address is called "' . $creds['pickup_location']
                . '". Shiprocket has: ' . ($names === [] ? 'none' : implode(', ', $names)) . '.');
        }

        return ['ok' => true, 'message' => 'Connected to Shiprocket. ' . count($names) . ' pickup address(es) found.'];
    }

    // ---------------------------------------------------------------------
    //  Rates & serviceability
    // ---------------------------------------------------------------------

    /** Grams to Shiprocket's kilograms, never below its 0.5 kg minimum slab. */
    private function kg($grams): float
    {
        return max(0.5, round(((int) $grams) / 1000, 3));
    }

    private function serviceabilityQuery(string $origin, string $destination, array $parcel): array
    {
        return $this->call('serviceability', 'GET', '/courier/serviceability/', [
            'query' => [
                'pickup_postcode'   => preg_replace('/\D+/', '', $origin),
                'delivery_postcode' => preg_replace('/\D+/', '', $destination),
                'weight'            => $this->kg($parcel['weight_grams'] ?? 500),
                'cod'               => !empty($parcel['is_cod']) ? 1 : 0,
                'declared_value'    => (float) ($parcel['declared_value'] ?? $parcel['cod_amount'] ?? 0),
            ],
        ]);
    }

    public function checkServiceability(string $originPin, string $destinationPin, array $parcel = []): array
    {
        $res = $this->serviceabilityQuery($originPin, $destinationPin, $parcel);
        if (!$res['ok']) {
            // A 404 here is Shiprocket's way of saying "no courier serves this
            // lane" - an answer, not an outage.
            if ($res['status'] === 404) {
                return ['ok' => true, 'serviceable' => false, 'cod' => false, 'eta_days' => null,
                        'message' => 'No courier serves ' . $destinationPin . '.'];
            }
            return shipping_fail($res['error'] ?? 'Serviceability check failed.', ['serviceable' => false]);
        }

        $companies = (array) ($res['body']['data']['available_courier_companies'] ?? []);
        if ($companies === []) {
            return ['ok' => true, 'serviceable' => false, 'cod' => false, 'eta_days' => null,
                    'message' => 'No courier serves ' . $destinationPin . '.'];
        }

        $etas = array_filter(array_map(
            static fn (array $c): int => (int) ($c['estimated_delivery_days'] ?? 0),
            $companies
        ));
        $cod = false;
        foreach ($companies as $company) {
            if ((int) ($company['cod'] ?? 0) === 1) {
                $cod = true;
                break;
            }
        }

        return [
            'ok'          => true,
            'serviceable' => true,
            'cod'         => $cod,
            'eta_days'    => $etas === [] ? null : min($etas),
            'message'     => count($companies) . ' courier(s) available.',
        ];
    }

    public function getRates(array $shipment): array
    {
        $res = $this->serviceabilityQuery(
            (string) ($shipment['origin_pin'] ?? ''),
            (string) ($shipment['destination_pin'] ?? ''),
            $shipment
        );

        if (!$res['ok']) {
            if ($res['status'] === 404) {
                return ['ok' => true, 'rates' => [], 'message' => 'No courier serves this lane.'];
            }
            return shipping_fail($res['error'] ?? 'Rate request failed.', ['rates' => []]);
        }

        $rates = [];
        foreach ((array) ($res['body']['data']['available_courier_companies'] ?? []) as $c) {
            $rates[] = [
                'courier'    => (string) ($c['courier_name'] ?? ''),
                'courier_id' => (int) ($c['courier_company_id'] ?? 0),
                'service'    => (string) ($c['courier_type'] ?? ''),
                'cost'       => round((float) ($c['rate'] ?? $c['freight_charge'] ?? 0), 2),
                'cod_fee'    => round((float) ($c['cod_charges'] ?? 0), 2),
                'eta_days'   => ($c['estimated_delivery_days'] ?? '') === '' ? null : (int) $c['estimated_delivery_days'],
                'etd'        => (string) ($c['etd'] ?? ''),
                'cod'        => (int) ($c['cod'] ?? 0) === 1,
                'rating'     => isset($c['rating']) ? (float) $c['rating'] : null,
            ];
        }

        // Cheapest first, so "the first rate" is a sensible default everywhere.
        usort($rates, static fn (array $a, array $b): int => $a['cost'] <=> $b['cost']);

        return ['ok' => true, 'rates' => $rates, 'message' => count($rates) . ' rate(s) returned.'];
    }

    // ---------------------------------------------------------------------
    //  Booking
    // ---------------------------------------------------------------------

    public function createShipment(array $order, array $options = []): array
    {
        $creds = shipping_credentials($this->provider);
        $pickupLocation = (string) ($creds['pickup_location'] ?? '');
        if ($pickupLocation === '') {
            return shipping_fail('Set the Shiprocket pickup location nickname before booking.');
        }

        $items = [];
        foreach ((array) ($order['items'] ?? []) as $item) {
            $items[] = [
                'name'          => mb_substr((string) ($item['product_name'] ?? $item['name'] ?? 'Item'), 0, 250),
                'sku'           => (string) ($item['sku'] ?? ('SKU-' . (int) ($item['product_id'] ?? 0))),
                'units'         => max(1, (int) ($item['quantity'] ?? 1)),
                'selling_price' => round((float) ($item['unit_price'] ?? $item['price'] ?? 0), 2),
                'hsn'           => (string) ($item['hsn_code'] ?? ''),
            ];
        }
        if ($items === []) {
            return shipping_fail('An order with no items cannot be shipped.');
        }

        // Shiprocket wants the name split; a single-word name is still valid.
        $fullName = trim((string) ($order['shipping_name'] ?? ''));
        $space    = strpos($fullName, ' ');
        $first    = $space === false ? $fullName : substr($fullName, 0, $space);
        $last     = $space === false ? '' : trim(substr($fullName, $space + 1));

        $isCod = !empty($options['is_cod'])
            || strtolower((string) ($order['payment_method'] ?? '')) === 'cod';

        $payload = [
            'order_id'              => (string) ($order['order_number'] ?? $order['id']),
            'order_date'            => date('Y-m-d H:i', strtotime((string) ($order['created_at'] ?? 'now'))),
            'pickup_location'       => $pickupLocation,
            'billing_customer_name' => $first,
            'billing_last_name'     => $last,
            'billing_address'       => (string) ($order['shipping_address'] ?? ''),
            'billing_address_2'     => trim((string) ($order['shipping_address2'] ?? '') . ' ' . (string) ($order['shipping_landmark'] ?? '')),
            'billing_city'          => (string) ($order['shipping_city'] ?? ''),
            'billing_pincode'       => (string) ($order['shipping_pincode'] ?? ''),
            'billing_state'         => (string) ($order['shipping_state'] ?? ''),
            'billing_country'       => (string) ($order['shipping_country'] ?? 'India'),
            'billing_email'         => (string) ($order['email'] ?? $order['customer_email'] ?? ''),
            'billing_phone'         => preg_replace('/\D+/', '', (string) ($order['shipping_phone'] ?? '')),
            'shipping_is_billing'   => true,
            'order_items'           => $items,
            'payment_method'        => $isCod ? 'COD' : 'Prepaid',
            'sub_total'             => round((float) ($order['total_amount'] ?? $order['total'] ?? 0), 2),
            'length'                => (float) ($options['length_cm'] ?? 20),
            'breadth'               => (float) ($options['width_cm'] ?? 15),
            'height'                => (float) ($options['height_cm'] ?? 10),
            'weight'                => $this->kg($options['weight_grams'] ?? 500),
        ];
        if (!empty($creds['channel_id'])) {
            $payload['channel_id'] = (string) $creds['channel_id'];
        }

        // One attempt only. A booking that timed out may still have been
        // created, and retrying would book the same order twice.
        $res = $this->call('create', 'POST', '/orders/create/adhoc', ['json' => $payload, 'retries' => 0]);
        if (!$res['ok']) {
            return shipping_fail('Shiprocket refused the booking: ' . ($res['error'] ?? 'unknown error'));
        }

        $shipmentId = (string) ($res['body']['shipment_id'] ?? '');
        if ($shipmentId === '') {
            return shipping_fail('Shiprocket accepted the order but returned no shipment id.');
        }

        return [
            'ok'           => true,
            // The shipment id is what AWB, label, pickup and track all key on.
            'shipment_ref' => $shipmentId,
            'order_ref'    => (string) ($res['body']['order_id'] ?? ''),
            'courier_name' => null,
            'message'      => 'Booked with Shiprocket (shipment ' . $shipmentId . ').',
        ];
    }

    public function generateAwb(array $shipment, array $options = []): array
    {
        $ref = (string) ($shipment['shipment_ref'] ?? '');
        if ($ref === '') {
            return shipping_fail('Create the shipment before requesting an AWB.');
        }

        $body = ['shipment_id' => $ref];
        // Without a courier id Shiprocket picks one by its own rules; passing
        // the id from getRates() is how the admin's choice is honoured.
        if (!empty($options['courier_id'])) {
            $body['courier_id'] = (int) $options['courier_id'];
        }

        $res = $this->call('awb', 'POST', '/courier/assign/awb', [
            'json' => $body, 'retries' => 0, 'shipment_id' => $shipment['id'] ?? null,
        ]);
        if (!$res['ok']) {
            return shipping_fail('AWB assignment failed: ' . ($res['error'] ?? 'unknown error'));
        }

        $data = (array) ($res['body']['response']['data'] ?? []);
        $awb  = (string) ($data['awb_code'] ?? '');
        if ($awb === '') {
            // Shiprocket answers 200 with an explanatory message when it cannot
            // assign - most often an unserviceable lane or a wallet short of funds.
            return shipping_fail((string) ($res['body']['message'] ?? $data['awb_assign_error'] ?? 'No AWB was assigned.'));
        }

        return [
            'ok'           => true,
            'awb'          => $awb,
            'courier_name' => (string) ($data['courier_name'] ?? ''),
            'message'      => 'AWB ' . $awb . ' assigned.',
        ];
    }

    public function generateLabel(array $shipment): array
    {
        $ref = (string) ($shipment['shipment_ref'] ?? '');
        if ($ref === '' || (string) ($shipment['awb'] ?? '') === '') {
            return shipping_fail('Assign an AWB before generating the label.');
        }

        $res = $this->call('label', 'POST', '/courier/generate/label', [
            'json' => ['shipment_id' => [$ref]], 'shipment_id' => $shipment['id'] ?? null,
        ]);
        $url = (string) ($res['body']['label_url'] ?? '');

        return $res['ok'] && $url !== ''
            ? ['ok' => true, 'url' => $url, 'message' => 'Label ready.']
            : shipping_fail('Label generation failed: ' . ($res['error'] ?? 'no label returned'));
    }

    public function generateManifest(array $shipments): array
    {
        $refs = array_values(array_filter(array_map(
            static fn (array $s): string => (string) ($s['shipment_ref'] ?? ''),
            $shipments
        )));
        if ($refs === []) {
            return shipping_fail('No booked shipments to manifest.');
        }

        $res = $this->call('manifest', 'POST', '/manifests/generate', ['json' => ['shipment_id' => $refs]]);
        $url = (string) ($res['body']['manifest_url'] ?? '');

        return $res['ok'] && $url !== ''
            ? ['ok' => true, 'url' => $url, 'message' => count($refs) . ' shipment(s) manifested.']
            : shipping_fail('Manifest generation failed: ' . ($res['error'] ?? 'no manifest returned'));
    }

    public function schedulePickup(array $shipment, array $options = []): array
    {
        $ref = (string) ($shipment['shipment_ref'] ?? '');
        if ($ref === '' || (string) ($shipment['awb'] ?? '') === '') {
            return shipping_fail('Assign an AWB before scheduling a pickup.');
        }

        $body = ['shipment_id' => [$ref]];
        if (!empty($options['pickup_date'])) {
            $body['pickup_date'] = [(string) $options['pickup_date']];
        }

        $res = $this->call('pickup', 'POST', '/courier/generate/pickup', [
            'json' => $body, 'retries' => 0, 'shipment_id' => $shipment['id'] ?? null,
        ]);
        if (!$res['ok']) {
            return shipping_fail('Pickup request failed: ' . ($res['error'] ?? 'unknown error'));
        }

        $date = (string) ($res['body']['response']['pickup_scheduled_date'] ?? '');
        return [
            'ok'          => true,
            'pickup_date' => $date === '' ? null : substr($date, 0, 10),
            'message'     => $date === '' ? 'Pickup requested.' : 'Pickup scheduled for ' . substr($date, 0, 10) . '.',
        ];
    }

    // ---------------------------------------------------------------------
    //  Tracking
    // ---------------------------------------------------------------------

    /**
     * Shiprocket's free-text status to the hub's vocabulary.
     *
     * Order matters: every label is tested against the rules top to bottom and
     * the first match wins. RTO comes before DELIVERED because "RTO DELIVERED"
     * contains "DELIVERED" and means the parcel came BACK; OUT FOR DELIVERY
     * comes before DELIVERED for the same reason.
     */
    public static function mapStatus(string $label): ?string
    {
        $label = strtoupper(trim($label));
        if ($label === '') {
            return null;
        }

        $rules = [
            'RTO DELIVERED'     => 'rto_delivered',
            'RTO'               => 'rto_initiated',
            'CANCEL'            => 'cancelled',
            'OUT FOR DELIVERY'  => 'out_for_delivery',
            'UNDELIVERED'       => 'failed',
            'LOST'              => 'failed',
            'DAMAGED'           => 'failed',
            'DELIVERED'         => 'delivered',
            'OUT FOR PICKUP'    => 'pickup_scheduled',
            'PICKUP SCHEDULED'  => 'pickup_scheduled',
            'PICKUP GENERATED'  => 'pickup_scheduled',
            'AWB ASSIGNED'      => 'booked',
            'PICKED UP'         => 'in_transit',
            'SHIPPED'           => 'in_transit',
            'IN TRANSIT'        => 'in_transit',
            'REACHED'           => 'in_transit',
        ];

        foreach ($rules as $needle => $status) {
            if (strpos($label, $needle) !== false) {
                return $status;
            }
        }
        return null;
    }

    public function trackShipment(array $shipment): array
    {
        $awb = (string) ($shipment['awb'] ?? '');
        if ($awb === '') {
            return shipping_fail('No AWB to track.');
        }

        $res = $this->call('track', 'GET', '/courier/track/awb/' . rawurlencode($awb), [
            'shipment_id' => $shipment['id'] ?? null,
        ]);
        if (!$res['ok']) {
            return shipping_fail('Tracking failed: ' . ($res['error'] ?? 'unknown error'));
        }

        $data       = (array) ($res['body']['tracking_data'] ?? []);
        $activities = (array) ($data['shipment_track_activities'] ?? []);

        $events = [];
        foreach ($activities as $activity) {
            $label  = (string) ($activity['sr-status-label'] ?? $activity['activity'] ?? '');
            $status = self::mapStatus($label) ?? self::mapStatus((string) ($activity['activity'] ?? ''));
            $at     = (string) ($activity['date'] ?? '');
            if ($at === '' || $status === null) {
                continue;
            }
            $events[] = [
                'status'      => $status,
                'message'     => (string) ($activity['activity'] ?? $label),
                'location'    => (string) ($activity['location'] ?? ''),
                'occurred_at' => date('Y-m-d H:i:s', strtotime($at) ?: time()),
                // Stable across polls and webhooks, so the same scan is stored once.
                'event_key'   => $awb . ':' . $status . ':' . date('YmdHis', strtotime($at) ?: 0),
            ];
        }

        // Oldest first, the order a timeline reads in.
        usort($events, static fn (array $a, array $b): int => strcmp($a['occurred_at'], $b['occurred_at']));

        $current = self::mapStatus((string) ($data['shipment_track'][0]['current_status'] ?? ''))
            ?? ($events === [] ? 'booked' : end($events)['status']);

        return ['ok' => true, 'status' => $current, 'events' => $events, 'message' => 'Tracking updated.'];
    }

    public function cancelShipment(array $shipment, string $reason = ''): array
    {
        $orderRef = (string) ($shipment['order_ref'] ?? '');
        if ($orderRef === '') {
            return shipping_fail('No Shiprocket order id is recorded for this shipment, so it cannot be cancelled here.');
        }

        $res = $this->call('cancel', 'POST', '/orders/cancel', [
            'json' => ['ids' => [(int) $orderRef]], 'retries' => 0, 'shipment_id' => $shipment['id'] ?? null,
        ]);

        return $res['ok']
            ? ['ok' => true, 'message' => 'Cancelled with Shiprocket.']
            : shipping_fail('Cancellation failed: ' . ($res['error'] ?? 'unknown error'));
    }

    // ---------------------------------------------------------------------
    //  Returns
    // ---------------------------------------------------------------------

    public function createReturn(array $shipment, array $options = []): array
    {
        $order = (array) ($options['order'] ?? []);
        if ($order === []) {
            return shipping_fail('A return needs the original order details.');
        }

        $creds = shipping_credentials($this->provider);
        $items = [];
        foreach ((array) ($order['items'] ?? []) as $item) {
            $items[] = [
                'name'          => mb_substr((string) ($item['product_name'] ?? 'Item'), 0, 250),
                'sku'           => (string) ($item['sku'] ?? ('SKU-' . (int) ($item['product_id'] ?? 0))),
                'units'         => max(1, (int) ($item['quantity'] ?? 1)),
                'selling_price' => round((float) ($item['unit_price'] ?? 0), 2),
            ];
        }

        $payload = [
            'order_id'            => 'R-' . (string) ($order['order_number'] ?? $order['id'] ?? ''),
            'order_date'          => date('Y-m-d'),
            'pickup_customer_name'=> (string) ($order['shipping_name'] ?? ''),
            'pickup_address'      => (string) ($order['shipping_address'] ?? ''),
            'pickup_city'         => (string) ($order['shipping_city'] ?? ''),
            'pickup_state'        => (string) ($order['shipping_state'] ?? ''),
            'pickup_country'      => (string) ($order['shipping_country'] ?? 'India'),
            'pickup_pincode'      => (string) ($order['shipping_pincode'] ?? ''),
            'pickup_email'        => (string) ($order['email'] ?? ''),
            'pickup_phone'        => preg_replace('/\D+/', '', (string) ($order['shipping_phone'] ?? '')),
            'shipping_customer_name' => (string) ($this->provider['pickup_name'] ?? ''),
            'shipping_address'    => (string) ($this->provider['pickup_address'] ?? ''),
            'shipping_city'       => (string) ($this->provider['pickup_city'] ?? ''),
            'shipping_state'      => (string) ($this->provider['pickup_state'] ?? ''),
            'shipping_country'    => (string) ($this->provider['pickup_country'] ?? 'India'),
            'shipping_pincode'    => (string) ($this->provider['pickup_pincode'] ?? ''),
            'shipping_phone'      => preg_replace('/\D+/', '', (string) ($this->provider['pickup_phone'] ?? '')),
            'order_items'         => $items,
            'payment_method'      => 'Prepaid',
            'sub_total'           => round((float) ($order['total_amount'] ?? 0), 2),
            'length'              => (float) ($shipment['length_cm'] ?? 20),
            'breadth'             => (float) ($shipment['width_cm'] ?? 15),
            'height'              => (float) ($shipment['height_cm'] ?? 10),
            'weight'              => $this->kg($shipment['weight_grams'] ?? 500),
        ];

        if ($payload['shipping_pincode'] === '') {
            return shipping_fail('Set the pickup address on this integration first - returns are sent back to it.');
        }

        $res = $this->call('return', 'POST', '/orders/create/return', ['json' => $payload, 'retries' => 0]);
        if (!$res['ok']) {
            return shipping_fail('Return booking failed: ' . ($res['error'] ?? 'unknown error'));
        }

        return [
            'ok'           => true,
            'shipment_ref' => (string) ($res['body']['shipment_id'] ?? ''),
            'awb'          => null,
            'message'      => 'Return pickup booked with Shiprocket.',
        ];
    }

    public function checkReturnStatus(array $shipment): array
    {
        // A return is tracked like any other consignment once it has an AWB.
        $track = $this->trackShipment($shipment);
        if (!$track['ok']) {
            return $track;
        }
        $status = $track['status'] === 'delivered' ? 'returned' : $track['status'];
        return ['ok' => true, 'status' => $status, 'message' => 'Return is ' . str_replace('_', ' ', $status) . '.'];
    }

    // ---------------------------------------------------------------------
    //  Webhook
    // ---------------------------------------------------------------------

    /**
     * Shiprocket authenticates a webhook with a static token sent as the
     * `x-api-key` header - the value you type into its dashboard, stored here
     * as the provider's webhook secret. It is compared with hash_equals so the
     * check cannot be timed.
     */
    public function parseWebhook(array $payload, array $headers = []): array
    {
        $secretRaw = (string) ($this->provider['webhook_secret'] ?? '');
        if ($secretRaw === '') {
            // No secret configured means anybody could post a status change.
            // Refuse rather than accept unauthenticated shipment updates.
            return shipping_fail('No webhook token is configured for Shiprocket.');
        }

        $expected = secret_decrypt($secretRaw);
        $given    = '';
        foreach ($headers as $name => $value) {
            if (strtolower((string) $name) === 'x-api-key') {
                $given = (string) $value;
                break;
            }
        }
        if ($given === '' || !hash_equals($expected, $given)) {
            return shipping_fail('Webhook token did not match.');
        }

        $awb   = (string) ($payload['awb'] ?? '');
        $label = (string) ($payload['current_status'] ?? $payload['shipment_status'] ?? '');
        if ($awb === '' || $label === '') {
            return shipping_fail('Webhook is missing awb or status.');
        }

        $events = [];
        foreach ((array) ($payload['scans'] ?? []) as $scan) {
            $scanLabel = (string) ($scan['sr-status-label'] ?? $scan['activity'] ?? '');
            $status    = self::mapStatus($scanLabel) ?? self::mapStatus((string) ($scan['activity'] ?? ''));
            $at        = (string) ($scan['date'] ?? '');
            if ($status === null || $at === '') {
                continue;
            }
            $events[] = [
                'status'      => $status,
                'message'     => (string) ($scan['activity'] ?? $scanLabel),
                'location'    => (string) ($scan['location'] ?? ''),
                'occurred_at' => date('Y-m-d H:i:s', strtotime($at) ?: time()),
                'event_key'   => $awb . ':' . $status . ':' . date('YmdHis', strtotime($at) ?: 0),
            ];
        }

        // A webhook with no scans still carries the current status.
        if ($events === [] && ($status = self::mapStatus($label)) !== null) {
            $at = (string) ($payload['current_timestamp'] ?? date('Y-m-d H:i:s'));
            $events[] = [
                'status'      => $status,
                'message'     => ucwords(strtolower($label)),
                'location'    => '',
                'occurred_at' => date('Y-m-d H:i:s', strtotime($at) ?: time()),
                'event_key'   => $awb . ':' . $status . ':' . date('YmdHis', strtotime($at) ?: 0),
            ];
        }

        return [
            'ok'           => true,
            'awb'          => $awb,
            'shipment_ref' => (string) ($payload['shipment_id'] ?? ''),
            'courier_name' => (string) ($payload['courier_name'] ?? ''),
            'events'       => $events,
            'message'      => 'Webhook accepted.',
        ];
    }
}
