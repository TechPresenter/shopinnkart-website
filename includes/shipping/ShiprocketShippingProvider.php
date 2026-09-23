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
 * Six things about this API that shape the code below:
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
 *   4. Status arrives three ways, trusted in this order: Shiprocket's own
 *      label ("REACHED AT DESTINATION HUB"), its numeric status id (often the
 *      only thing a tracking activity carries), and the courier's free-text
 *      remark. Only the first two may end a shipment, settle money or start an
 *      RTO - "RTO DELIVERED" contains "DELIVERED", and "carton" contains "RTO".
 *
 *   5. Times are Indian wall-clock time with no zone, in two shapes: scans say
 *      "2026-09-22 13:05:00", the webhook's current_timestamp "22 09 2026
 *      13:05:00" - which strtotime() cannot read at all.
 *
 *   6. There is no sandbox. Every booking call is real and billed, so an
 *      integration in Test mode makes none of them (see liveOnly()).
 */

declare(strict_types=1);

final class ShiprocketShippingProvider implements ShippingProviderInterface
{
    private const BASE = 'https://apiv2.shiprocket.in/v1/external';

    /** Refresh this long before the ten-day expiry, so a token never lapses mid-booking. */
    private const TOKEN_MARGIN = 86400;

    /** Shiprocket's own clock: every timestamp it sends is this, unzoned. */
    private const COURIER_TZ = 'Asia/Kolkata';

    /**
     * The box declared when the admin measures none, in cm.
     *
     * Shiprocket requires dimensions on a booking and bills the greater of dead
     * weight and L x B x H / 5000. The old made-up 20 x 15 x 10 was 0.6 kg
     * volumetric, so a 400 g parcel quoted in the 0.5 kg slab was billed in the
     * next. 1000 cm3 is 0.2 kg - under the smallest slab even at a /4000
     * divisor - so an unmeasured parcel is billed on its dead weight, and the
     * quote (which sends the same box) says so.
     */
    private const DEFAULT_BOX_CM = ['length' => 10, 'breadth' => 10, 'height' => 10];

    /**
     * Shiprocket's own status labels, after "_" becomes a space and case is
     * folded. Matched exactly, so "PARTIAL_DELIVERED" and "NOT DELIVERED" can
     * never collide with "DELIVERED" the way a substring test let them. null is
     * a real Shiprocket status that moves nothing here - known, so its scan is
     * not second-guessed from the free text.
     */
    private const LABELS = [
        'DELIVERED'                      => 'delivered',
        'RTO DELIVERED'                  => 'rto_delivered',
        'RTO INITIATED'                  => 'rto_initiated',
        'RTO IN TRANSIT'                 => 'rto_initiated',
        'RTO IN INTRANSIT'               => 'rto_initiated',
        'RTO OFD'                        => 'rto_initiated',
        'RTO ACKNOWLEDGED'               => 'rto_initiated',
        'RTO NDR'                        => 'rto_initiated',
        'RTO LOCK'                       => 'rto_initiated',
        'REACHED BACK AT SELLER CITY'    => 'rto_initiated',
        'CANCELED'                       => 'cancelled',
        'CANCELLED'                      => 'cancelled',
        'CANCELLED BEFORE DISPATCHED'    => 'cancelled',
        'OUT FOR DELIVERY'               => 'out_for_delivery',
        'IN TRANSIT'                     => 'in_transit',
        'IN FLIGHT'                      => 'in_transit',
        'IN TRANSIT OVERSEAS'            => 'in_transit',
        'SHIPPED'                        => 'in_transit',
        'PICKED UP'                      => 'in_transit',
        'HANDOVER TO COURIER'            => 'in_transit',
        'REACHED AT DESTINATION HUB'     => 'in_transit',
        'REACHED AT DESTINATION'         => 'in_transit',
        'REACHED DESTINATION HUB'        => 'in_transit',
        'MISROUTED'                      => 'in_transit',
        'OUT FOR PICKUP'                 => 'pickup_scheduled',
        'PICKUP SCHEDULED'               => 'pickup_scheduled',
        'PICKUP SCHEDULED/GENERATED'     => 'pickup_scheduled',
        'PICKUP GENERATED'               => 'pickup_scheduled',
        'PICKUP QUEUED'                  => 'pickup_scheduled',
        'PICKUP RESCHEDULED'             => 'pickup_scheduled',
        'PICKUP BOOKED'                  => 'pickup_scheduled',
        'AWB ASSIGNED'                   => 'booked',
        'SHIPMENT BOOKED'                => 'booked',
        // Money is not settled by any of these, so none may read as delivered.
        'UNDELIVERED'                    => 'failed',
        'NOT DELIVERED'                  => 'failed',
        'PARTIAL DELIVERED'              => 'failed',
        'PARTIALLY DELIVERED'            => 'failed',
        'LOST'                           => 'failed',
        'DAMAGED'                        => 'failed',
        'DESTROYED'                      => 'failed',
        'DISPOSED OFF'                   => 'failed',
        'UNTRACEABLE'                    => 'failed',
        'ISSUE RELATED TO THE RECIPIENT' => 'failed',
        // Only a request; the consignment is still live until confirmed.
        'CANCELLATION REQUESTED'         => null,
        'MANIFEST GENERATED'             => null,
        'LABEL GENERATED'                => null,
        'PENDING'                        => null,
        'PICKUP ERROR'                   => null,
        'PICKUP EXCEPTION'               => null,
        'DELAYED'                        => null,
        'FULFILLED'                      => null,
        'SELF FULFILLED'                 => null,
        'QC FAILED'                      => null,
    ];

    /**
     * Shiprocket's numeric status ids (`sr-status` on a scan, current_status_id
     * on a webhook, shipment_status in tracking), from the status table in its
     * API docs. A tracking activity often carries only the number; reading its
     * free text instead can never yield delivered, so a parcel Shiprocket calls
     * delivered stayed "out for delivery". Ids not listed are left to the text.
     */
    private const STATUS_CODES = [
        1  => 'booked',            // AWB Assigned
        2  => null,                // Label Generated
        3  => 'pickup_scheduled',  // Pickup Scheduled/Generated
        4  => 'pickup_scheduled',  // Pickup Queued
        5  => null,                // Manifest Generated
        6  => 'in_transit',        // Shipped
        7  => 'delivered',         // Delivered
        8  => 'cancelled',         // Canceled
        9  => 'rto_initiated',     // RTO Initiated
        10 => 'rto_delivered',     // RTO Delivered
        11 => null,                // Pending
        12 => 'failed',            // Lost
        13 => null,                // Pickup Error
        14 => 'rto_initiated',     // RTO Acknowledged
        15 => 'pickup_scheduled',  // Pickup Rescheduled
        16 => null,                // Cancellation Requested - only a request
        17 => 'out_for_delivery',  // Out For Delivery
        18 => 'in_transit',        // In Transit
        19 => 'pickup_scheduled',  // Out For Pickup
        20 => null,                // Pickup Exception
        21 => 'failed',            // Undelivered
        22 => null,                // Delayed
        23 => 'failed',            // Partial_Delivered
        24 => 'failed',            // Destroyed
        25 => 'failed',            // Damaged
        26 => null,                // Fulfilled
        27 => 'pickup_scheduled',  // Pickup Booked
        38 => 'in_transit',        // Reached at Destination Hub
        39 => 'in_transit',        // Misrouted
        40 => 'rto_initiated',     // RTO NDR
        41 => 'rto_initiated',     // RTO OFD
        42 => 'in_transit',        // Picked Up
        43 => null,                // Self Fulfilled
        44 => 'failed',            // Disposed Off
        45 => 'cancelled',         // Cancelled before dispatched
        46 => 'rto_initiated',     // RTO In Transit
        47 => null,                // QC Failed
        50 => 'in_transit',        // In Flight
        51 => 'in_transit',        // Handover to Courier
        52 => 'booked',            // Shipment Booked
        54 => 'in_transit',        // In Transit Overseas
        75 => 'rto_initiated',     // RTO Lock
        76 => 'failed',            // Untraceable
        77 => 'failed',            // Issue related to the recipient
        78 => 'rto_initiated',     // Reached back at seller city
    ];

    private array $provider;

    /** @var callable|null */
    private $transport;

    /**
     * Logins this process has already watched fail, so dead credentials are
     * tried once and not once per shipment.
     *
     * The poller builds a fresh driver per shipment, so an instance property
     * would memoise nothing: a run of 50 shipments made 50 /auth/login calls
     * into Shiprocket's own login rate limit, each of them able to wait out a
     * 20s timeout. Keyed on the credentials rather than the provider id
     * because an in-memory row has no id, and re-saving the credentials is
     * meant to be retried at once - new password, new key.
     *
     * $force clears it: a forced login is the caller saying it knows something
     * new (a 401 on a cached token, or an admin pressing Test), which is also
     * why a bad password can still be reported plainly twice in one process.
     *
     * @var array<string, true>
     */
    private static array $failedLogins = [];

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
    //  Mode
    // ---------------------------------------------------------------------

    /**
     * Refuse a billable or state-changing call unless the integration is Live.
     *
     * Shiprocket has no sandbox: a "test" booking is a real consignment, a real
     * wallet debit and a real pickup - while the admin was shown a Test badge
     * and no billing warning. So Test mode here means "no call that books,
     * bills or changes a consignment": booking, AWB, pickup, cancel, return
     * and manifest refuse before any request is made. Rates, serviceability,
     * tracking, labels and the connection test are read-only and still work,
     * which is enough to set the integration up before going live.
     *
     * A row with no mode at all counts as Test: fail closed.
     */
    private function liveOnly(string $what): ?array
    {
        if ((string) ($this->provider['mode'] ?? '') === 'live') {
            return null;
        }
        return shipping_fail('Not sent: this Shiprocket integration is in Test mode, and Shiprocket has no sandbox - '
            . 'every call that ' . $what . ' is real and billed. Switch the integration to Live to do this.');
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

        // See $failedLogins: the same credentials are not asked twice in one
        // process unless the caller forces it.
        $login = hash('sha256', $email . "\0" . $password);
        if ($force) {
            unset(self::$failedLogins[$login]);
        } elseif (isset(self::$failedLogins[$login])) {
            return null;
        }

        $res = shipping_http('shiprocket', 'auth', 'POST', self::BASE . '/auth/login', [
            'json'      => ['email' => $email, 'password' => $password],
            'retries'   => 1,
            'transport' => $this->transport,
        ]);

        $token = (string) ($res['body']['token'] ?? '');
        if (!$res['ok'] || $token === '') {
            self::$failedLogins[$login] = true;
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
        // No nickname is not "nothing to check": createShipment() refuses
        // every booking without one, so reporting Connected here sent the
        // admin away with an integration that could not book.
        if ($want === '') {
            return shipping_fail('Logged in, but no pickup location nickname is saved. Enter it exactly as in Shiprocket > Settings > Pickup Addresses, save, then test.');
        }

        $names = [];
        foreach ((array) ($res['body']['data']['shipping_address'] ?? []) as $address) {
            $names[] = (string) ($address['pickup_location'] ?? '');
        }

        if (!in_array($want, array_map('strtolower', $names), true)) {
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

    /**
     * The box, in whole centimetres, that BOTH the quote and the booking send.
     *
     * Reads length_cm / width_cm / height_cm; a side not given falls back to
     * DEFAULT_BOX_CM. Rounded up to whole centimetres because the
     * serviceability API documents integers, and the booking must declare the
     * box the quote priced - a quote without dimensions and a booking with
     * them is how a parcel got billed in a heavier slab than it was quoted in.
     */
    private function box(array $source): array
    {
        $box = [];
        foreach (['length' => 'length_cm', 'breadth' => 'width_cm', 'height' => 'height_cm'] as $side => $key) {
            $cm = (float) ($source[$key] ?? 0);
            $box[$side] = $cm > 0 ? max(1, (int) ceil(round($cm, 2))) : self::DEFAULT_BOX_CM[$side];
        }
        return $box;
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
            ] + $this->box($parcel),
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

    /**
     * Takes weight_grams and length_cm / width_cm / height_cm (null = not
     * measured, which quotes the same default box a booking would declare).
     */
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

    /**
     * The order_id Shiprocket files a consignment under: unique per hub
     * booking, not per order.
     *
     * Shiprocket refuses a new order whose id matches a cancelled one ("consider
     * changing the order_id"), and cancelling a consignment is exactly what an
     * admin does to rebook with another courier - so re-sending the bare order
     * number made every rebook fail. The hub's shipment id is the suffix; the
     * order number stays first so the Shiprocket panel still reads naturally.
     * 50 characters is Shiprocket's limit, and the suffix is what must survive.
     */
    private static function channelOrderId(array $order, int $shipmentId, string $prefix = ''): string
    {
        $base   = $prefix . (string) ($order['order_number'] ?? $order['id'] ?? '');
        $suffix = $shipmentId > 0 ? '-' . $shipmentId : '';
        return substr($base, 0, 50 - strlen($suffix)) . $suffix;
    }

    public function createShipment(array $order, array $options = []): array
    {
        if (($refused = $this->liveOnly('books a consignment')) !== null) {
            return $refused;
        }

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
            'order_id'              => self::channelOrderId($order, (int) ($options['shipment_id'] ?? 0)),
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
            'weight'                => $this->kg($options['weight_grams'] ?? 500),
        ] + $this->box($options);
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

        // An account with auto-assignment answers with the AWB already on the
        // consignment. Dropped, it was lost for good: the AWB request that
        // followed got "Cannot reassign courier" and the booking sat in
        // "ready" with no AWB while the parcel shipped.
        $awb = (string) ($res['body']['awb_code'] ?? '');

        return [
            'ok'           => true,
            // The shipment id is what AWB, label, pickup and track all key on.
            'shipment_ref' => $shipmentId,
            // Shiprocket's ORDER id - what cancel keys on, and what a webhook
            // carries as sr_order_id.
            'order_ref'    => (string) ($res['body']['order_id'] ?? ''),
            'awb'          => $awb !== '' ? $awb : null,
            'courier_name' => $awb !== '' ? ((string) ($res['body']['courier_name'] ?? '') ?: null) : null,
            'courier_id'   => $awb !== '' && !empty($res['body']['courier_company_id']) ? (string) $res['body']['courier_company_id'] : null,
            'message'      => 'Booked with Shiprocket (shipment ' . $shipmentId . ').'
                . ($awb !== '' ? ' AWB ' . $awb . ' assigned with it.' : ''),
        ];
    }

    public function generateAwb(array $shipment, array $options = []): array
    {
        if (($refused = $this->liveOnly('assigns an AWB')) !== null) {
            return $refused;
        }

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

        $data = (array) ($res['body']['response']['data'] ?? []);
        $awb  = $res['ok'] ? (string) ($data['awb_code'] ?? '') : '';
        if ($awb !== '') {
            return [
                'ok'           => true,
                'awb'          => $awb,
                'courier_name' => (string) ($data['courier_name'] ?? ''),
                'message'      => 'AWB ' . $awb . ' assigned.',
            ];
        }

        // Refused - but the consignment may hold an AWB anyway: an earlier
        // request that timed out after Shiprocket assigned it, one assigned in
        // the panel, or one the create call carried. Every retry then gets
        // "Cannot reassign courier", and without this the booking sat in
        // "ready" forever. Shiprocket's own shipment record is the truth.
        $held = $this->heldAwb($ref, $shipment['id'] ?? null);
        if ($held !== null) {
            return [
                'ok'           => true,
                'awb'          => $held['awb'],
                'courier_name' => $held['courier_name'],
                'message'      => 'AWB ' . $held['awb'] . ' was already assigned at Shiprocket.',
            ];
        }

        if (!$res['ok']) {
            return shipping_fail('AWB assignment failed: ' . ($res['error'] ?? 'unknown error'));
        }
        // Shiprocket answers 200 with an explanatory message when it cannot
        // assign - most often an unserviceable lane or a wallet short of funds.
        return shipping_fail((string) ($res['body']['message'] ?? $data['awb_assign_error'] ?? 'No AWB was assigned.'));
    }

    /** The AWB Shiprocket's shipment record holds, or null. Read-only. */
    private function heldAwb(string $shipmentRef, $localId = null): ?array
    {
        $res = $this->call('awb_lookup', 'GET', '/shipments/' . rawurlencode($shipmentRef), [
            'retries' => 1, 'shipment_id' => $localId,
        ]);
        $data = (array) ($res['body']['data'] ?? []);
        $awb  = $res['ok'] ? trim((string) ($data['awb'] ?? '')) : '';

        return $awb === '' ? null : ['awb' => $awb, 'courier_name' => (string) ($data['courier'] ?? '')];
    }

    /**
     * Shiprocket's own domains - its API, its panel, and the name it traded
     * under before the rename, which its older PDF links still use.
     */
    private const LINK_DOMAINS = ['shiprocket.in', 'shiprocket.co', 'kartrocket.com'];

    /** The S3 buckets Shiprocket serves its label and manifest PDFs from. */
    private const LINK_BUCKETS = ['kr-shipmultichannel'];

    /**
     * Shiprocket's own https links only; anything else is not handed to a browser.
     *
     * The scheme alone is not the promise this makes. A label_url or
     * manifest_url is stored on the shipment and then rendered to the admin as
     * a link they click, so a hostile or compromised courier response could
     * otherwise put any https address in front of them. The host is therefore
     * checked as well. Being wrong the other way is cheap: a link we refuse
     * costs the carrier's own label layout, and the hub prints its own instead.
     */
    private static function isHttps(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return false;
        }
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        if ($host === '') {
            return false;
        }

        foreach (self::LINK_DOMAINS as $domain) {
            // The domain itself or a subdomain of it - matched on the dot, so
            // "shiprocket.in.attacker.example" is not one of Shiprocket's.
            if ($host === $domain || substr($host, -strlen($domain) - 1) === '.' . $domain) {
                return true;
            }
        }

        // S3 carries the bucket either as the leading label of the host
        // ("kr-shipmultichannel.s3.ap-south-1.amazonaws.com") or as the first
        // path segment ("s3-ap-southeast-1.amazonaws.com/kr-shipmultichannel/..").
        // A regional endpoint on its own is shared by every bucket in the
        // region, so the bucket - not the host - is what is checked.
        if (preg_match('/^(?:(.+)\.)?s3[.-][a-z0-9-]*\.?amazonaws\.com$/', $host, $m) !== 1) {
            return false;
        }
        $bucket = (string) ($m[1] ?? '');
        if ($bucket === '') {
            $bucket = explode('/', ltrim((string) ($parts['path'] ?? ''), '/'))[0];
        }
        return in_array(strtolower($bucket), self::LINK_BUCKETS, true);
    }

    /**
     * Shiprocket's label PDF for the carrier it booked - with that carrier's
     * layout and routing code, which the hub's own label does not have. Not
     * billable, so it works in Test mode too. `url` is only ever an https link
     * on one of Shiprocket's own hosts (isHttps()).
     */
    public function generateLabel(array $shipment): array
    {
        $ref = (string) ($shipment['shipment_ref'] ?? '');
        if ($ref === '' || (string) ($shipment['awb'] ?? '') === '') {
            return shipping_fail('Assign an AWB before generating the label.');
        }

        $res = $this->call('label', 'POST', '/courier/generate/label', [
            'json' => ['shipment_id' => [$ref]], 'shipment_id' => $shipment['id'] ?? null,
        ]);
        if (!$res['ok']) {
            return shipping_fail('Label generation failed: ' . ($res['error'] ?? 'unknown error'));
        }

        $url = trim((string) ($res['body']['label_url'] ?? ''));
        if ($url === '') {
            // A 200 with label_created 0 says why in `response`.
            $why = $res['body']['response'] ?? null;
            return shipping_fail('Label generation failed: ' . (is_string($why) && $why !== '' ? $why : 'no label returned'));
        }
        if (!self::isHttps($url)) {
            return shipping_fail('Shiprocket returned a label link that is not one of its own https addresses, so it was not used.');
        }

        return ['ok' => true, 'url' => $url, 'message' => 'Label ready.'];
    }

    public function generateManifest(array $shipments): array
    {
        if (($refused = $this->liveOnly('manifests consignments for pickup')) !== null) {
            return $refused;
        }

        $refs = array_values(array_filter(array_map(
            static fn (array $s): string => (string) ($s['shipment_ref'] ?? ''),
            $shipments
        )));
        if ($refs === []) {
            return shipping_fail('No booked shipments to manifest.');
        }

        $res = $this->call('manifest', 'POST', '/manifests/generate', ['json' => ['shipment_id' => $refs]]);
        $url = trim((string) ($res['body']['manifest_url'] ?? ''));

        if (!$res['ok'] || $url === '') {
            return shipping_fail('Manifest generation failed: ' . ($res['error'] ?? 'no manifest returned'));
        }
        if (!self::isHttps($url)) {
            return shipping_fail('Shiprocket returned a manifest link that is not one of its own https addresses, so it was not used.');
        }
        return ['ok' => true, 'url' => $url, 'message' => count($refs) . ' shipment(s) manifested.'];
    }

    public function schedulePickup(array $shipment, array $options = []): array
    {
        if (($refused = $this->liveOnly('requests a pickup')) !== null) {
            return $refused;
        }

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

    /** "_" and runs of space to one space, case folded - the form LABELS is keyed in. */
    private static function normaliseLabel(string $label): string
    {
        return strtoupper(trim(preg_replace('/[_\s]+/', ' ', $label) ?? ''));
    }

    /**
     * Status text to the hub's vocabulary.
     *
     * Two tiers. Shiprocket's OWN status labels are matched exactly (LABELS).
     * Anything else falls through to keywords, matched as whole words - "RTO"
     * inside "carton" turned a delivery into a return, and "NDR" inside
     * "laundry" into a failed attempt.
     *
     * $allowTerminal = false is for a courier's free-text remark: it may never
     * produce a status that ends the shipment or settles money (a remark like
     * "Shipment Not Delivered - Consignee refused" must not mark a COD order
     * paid), and it may not start an RTO either - RTO changes what a later
     * "delivered" means, so only Shiprocket's own label or id may begin one. A
     * remark that mentions RTO is left unclassified rather than guessed at.
     */
    public static function mapStatus(string $label, bool $allowTerminal = true): ?string
    {
        $label = self::normaliseLabel($label);
        if ($label === '' || $label === 'NA' || $label === 'N A') {
            return null;
        }

        $terminal = ['delivered', 'rto_delivered', 'cancelled', 'returned'];

        if (array_key_exists($label, self::LABELS)) {
            $status = self::LABELS[$label];
            if ($status !== null && !$allowTerminal && in_array($status, $terminal, true)) {
                return null;
            }
            return $status;
        }

        $has = static fn (string $word): bool => preg_match('/\b' . $word . '\b/', $label) === 1;

        // A cancellation first, because it negates everything after it.
        // "PICKUP CANCELLED" is not a scheduled pickup, and "RTO CANCELLED" is
        // not a return - both used to read as the thing that was called off.
        // Left unclassified rather than mapped to 'cancelled': only Shiprocket's
        // own label or status id may kill a consignment.
        if ($has('CANCEL(L?ED|L?ING|LATION)?')) {
            return null;
        }

        // RTO next: "RTO UNDELIVERED" is the return leg, not a failed delivery.
        if ($has('RTO')) {
            return $allowTerminal ? 'rto_initiated' : null;
        }

        // Negatives next, and never a terminal or paying status.
        foreach (['NOT DELIVERED', 'UNDELIVERED', 'REFUSED', 'PARTIAL(LY)?', 'NDR', 'FAILED'] as $negative) {
            if ($has($negative)) {
                return 'failed';
            }
        }
        $keywords = [
            'OUT FOR DELIVERY' => 'out_for_delivery',
            'IN ?TRANSIT'      => 'in_transit',
            'PICKED UP'        => 'in_transit',
            'REACHED'          => 'in_transit',
            'SHIPPED'          => 'in_transit',
            'PICKUP'           => 'pickup_scheduled',
        ];
        foreach ($keywords as $word => $status) {
            if ($has($word)) {
                return $status;
            }
        }
        return null;
    }

    /**
     * One scan's status, most trusted source first: Shiprocket's label, then
     * its numeric id, then - only when Shiprocket classified the scan neither
     * way - the courier's free text, under mapStatus()'s untrusted rules.
     */
    private static function scanStatus(array $scan): ?string
    {
        $label = self::normaliseLabel((string) ($scan['sr-status-label'] ?? ''));
        if ($label !== '' && $label !== 'NA') {
            if (array_key_exists($label, self::LABELS)) {
                return self::LABELS[$label];
            }
            if (($status = self::mapStatus($label)) !== null) {
                return $status;
            }
        }

        $code = trim((string) ($scan['sr-status'] ?? ''));
        if ($code !== '' && ctype_digit($code) && array_key_exists((int) $code, self::STATUS_CODES)) {
            return self::STATUS_CODES[(int) $code];
        }

        return self::mapStatus((string) ($scan['activity'] ?? ''), false);
    }

    /**
     * A Shiprocket timestamp in Shiprocket's own zone, or null if unreadable.
     *
     * Formats are tried strictly: a date that would roll over ("31 02") is
     * refused rather than moved to March, and a relative phrase is never read
     * as "now" - the receipt time is not when anything happened.
     */
    private static function readTime($raw): ?DateTimeImmutable
    {
        $raw = trim((string) $raw);
        if ($raw === '' || strtoupper($raw) === 'NA') {
            return null;
        }
        $zone = new DateTimeZone(self::COURIER_TZ);

        foreach (['!Y-m-d H:i:s', '!Y-m-d H:i:s.u', '!Y-m-d H:i', '!d m Y H:i:s', '!d m Y H:i',
                  '!d-m-Y H:i:s', '!d-m-Y H:i', '!Y-m-d'] as $format) {
            $at = DateTimeImmutable::createFromFormat($format, $raw, $zone);
            if ($at !== false && DateTimeImmutable::getLastErrors() === false) {
                return $at;
            }
        }

        // ISO-8601 with an explicit zone, should Shiprocket ever send one.
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})$/', $raw) === 1) {
            try {
                $at = new DateTimeImmutable($raw, $zone);
            } catch (Exception $e) {
                return null;
            }
            return DateTimeImmutable::getLastErrors() === false ? $at->setTimezone($zone) : null;
        }
        return null;
    }

    /** Store time, the zone every occurred_at in the hub is written in. */
    private static function storeTime(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
    }

    /**
     * The same courier event gets the same key whether it comes by webhook or
     * by poll, now or on a resend - built from what the courier said, never
     * from the time we heard it. An unreadable time keys on its raw text, not
     * on epoch 0, which made every repeat of a status (a second NDR, a second
     * day out for delivery) one key and dropped it as a duplicate.
     */
    private static function eventKey(string $awb, string $status, ?DateTimeImmutable $at, string $raw): string
    {
        return $awb . ':' . $status . ':'
            . ($at !== null ? $at->setTimezone(new DateTimeZone(self::COURIER_TZ))->format('YmdHis') : 'r' . substr(sha1($raw), 0, 16));
    }

    /**
     * Scans to events. Each scan is keyed on its own time; a scan whose time
     * cannot be read is left out rather than placed at "now".
     *
     * @return array{0: array, 1: ?DateTimeImmutable} the events, and the latest scan time
     */
    private static function scanEvents(string $awb, array $scans): array
    {
        $events = [];
        $latest = null;
        foreach ($scans as $scan) {
            if (!is_array($scan)) {
                continue;
            }
            $status = self::scanStatus($scan);
            $at     = self::readTime($scan['date'] ?? '');
            if ($status === null || $at === null) {
                continue;
            }
            if ($latest === null || $at > $latest) {
                $latest = $at;
            }
            $events[] = [
                'status'      => $status,
                'message'     => (string) (($scan['activity'] ?? '') !== '' ? $scan['activity'] : ($scan['sr-status-label'] ?? $status)),
                'location'    => (string) ($scan['location'] ?? ''),
                'occurred_at' => self::storeTime($at),
                'event_key'   => self::eventKey($awb, $status, $at, (string) ($scan['date'] ?? '')),
            ];
        }
        return [$events, $latest];
    }

    /**
     * Shiprocket's headline status as one more event, when no scan says it.
     *
     * The headline is Shiprocket's own verdict. Reading only the scans lost it
     * whenever any scan mapped: a courier-side CANCELED arrives alongside the
     * earlier pickup scans, and a delivery can come with its scan unlabelled -
     * both went unrecorded, leaving a dead AWB on the order or a delivered COD
     * parcel unpaid.
     *
     * A headline no time can be established for is dropped, exactly as
     * scanEvents() drops an undateable scan. Stamping it "now" would date a
     * courier event at the moment we happened to poll, and that ordering is
     * what shipping_settle() compares an RTO against and what becomes
     * shipments.delivered_at. The next poll carries the same headline, and by
     * then the courier usually has a readable time for it.
     */
    private static function headlineEvent(string $awb, string $label, $code, ?DateTimeImmutable $at, string $rawAt, array $events): ?array
    {
        if ($at === null) {
            return null;
        }
        $status = self::scanStatus(['sr-status-label' => $label, 'sr-status' => (string) $code]);
        if ($status === null || in_array($status, array_column($events, 'status'), true)) {
            return null;
        }
        $text = trim(str_replace('_', ' ', $label));
        return [
            'status'      => $status,
            'message'     => $text !== '' ? ucwords(strtolower($text)) : ucfirst(str_replace('_', ' ', $status)),
            'location'    => '',
            'occurred_at' => self::storeTime($at),
            'event_key'   => self::eventKey($awb, $status, $at, 'headline|' . $label . '|' . $code . '|' . $rawAt),
        ];
    }

    /** tracking_data, whether it comes bare or keyed by the AWB / shipment id asked for. */
    private static function trackingData($body): array
    {
        if (!is_array($body)) {
            return [];
        }
        if (isset($body['tracking_data']) && is_array($body['tracking_data'])) {
            return $body['tracking_data'];
        }
        foreach ($body as $item) {
            if (is_array($item) && isset($item['tracking_data']) && is_array($item['tracking_data'])) {
                return $item['tracking_data'];
            }
        }
        return [];
    }

    /**
     * trackShipment() works with only a shipment id, so the poller also asks
     * about bookings that have no AWB on our side (shipping_tracks_by_reference()).
     * Read-only, so Test mode does not stop it.
     */
    public function tracksByReference(): bool
    {
        return true;
    }

    /**
     * Tracks by AWB, or - with none on our side - by Shiprocket's shipment id.
     *
     * No AWB here does not mean none at Shiprocket: an AWB request that timed
     * out after assigning, or one made in the Shiprocket panel, leaves the
     * booking AWB-less on our side only. Tracking by shipment id finds it, and
     * the AWB, shipment and order ids are handed back for the caller to adopt.
     */
    public function trackShipment(array $shipment): array
    {
        $awb = trim((string) ($shipment['awb'] ?? ''));
        $ref = trim((string) ($shipment['shipment_ref'] ?? ''));
        if ($awb === '' && $ref === '') {
            return shipping_fail('No AWB to track.');
        }

        $path = $awb !== '' ? '/courier/track/awb/' . rawurlencode($awb) : '/courier/track/shipment/' . rawurlencode($ref);
        $res  = $this->call('track', 'GET', $path, ['shipment_id' => $shipment['id'] ?? null]);
        if (!$res['ok']) {
            return shipping_fail('Tracking failed: ' . ($res['error'] ?? 'unknown error'));
        }

        $data   = self::trackingData($res['body']);
        $track  = (array) ($data['shipment_track'][0] ?? []);
        $found  = trim((string) ($track['awb_code'] ?? ''));
        $keyAwb = $awb !== '' ? $awb : ($found !== '' ? $found : 'SR' . $ref);

        [$events, $latest] = self::scanEvents($keyAwb, (array) ($data['shipment_track_activities'] ?? []));

        $label = (string) ($track['current_status'] ?? '');
        $code  = (string) ($data['shipment_status'] ?? '');
        // A delivery carries its own date; any other headline is as of the
        // latest scan. Neither is the time of this poll.
        $delivered = self::readTime($track['delivered_date'] ?? '');
        $headAt    = self::scanStatus(['sr-status-label' => $label, 'sr-status' => $code]) === 'delivered' && $delivered !== null
            ? $delivered : $latest;
        $headline = self::headlineEvent($keyAwb, $label, $code, $headAt, 'poll', $events);
        if ($headline !== null) {
            $events[] = $headline;
        }

        // Oldest first, the order a timeline reads in.
        usort($events, static fn (array $a, array $b): int => strcmp($a['occurred_at'], $b['occurred_at']));

        $current = self::scanStatus(['sr-status-label' => $label, 'sr-status' => $code])
            ?? ($events === [] ? 'booked' : end($events)['status']);

        return [
            'ok'           => true,
            'status'       => $current,
            'events'       => $events,
            'awb'          => $found !== '' ? $found : $awb,
            'shipment_ref' => (string) ($track['shipment_id'] ?? $ref),
            'order_ref'    => (string) ($track['order_id'] ?? $shipment['order_ref'] ?? ''),
            'courier_name' => (string) ($track['courier_name'] ?? ''),
            'message'      => (int) ($data['track_status'] ?? 1) === 0 && $events === []
                ? 'Shiprocket has no scans for this shipment yet.'
                : 'Tracking updated.',
        ];
    }

    public function cancelShipment(array $shipment, string $reason = ''): array
    {
        if (($refused = $this->liveOnly('cancels a consignment')) !== null) {
            return $refused;
        }

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
        if (($refused = $this->liveOnly('books a return pickup')) !== null) {
            return $refused;
        }

        $order = (array) ($options['order'] ?? []);
        if ($order === []) {
            return shipping_fail('A return needs the original order details.');
        }

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
            // Unique per consignment for the same reason as a forward booking.
            'order_id'            => self::channelOrderId($order, (int) ($shipment['id'] ?? 0), 'R-'),
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
            'weight'              => $this->kg($shipment['weight_grams'] ?? 500),
        ] + $this->box($shipment);

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
            'order_ref'    => (string) ($res['body']['order_id'] ?? ''),
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
    public function parseWebhook(array $payload, array $headers = [], string $rawBody = ''): array
    {
        $secretRaw = (string) ($this->provider['webhook_secret'] ?? '');
        if ($secretRaw === '') {
            // No secret configured means anybody could post a status change.
            // Refuse rather than accept unauthenticated shipment updates.
            return shipping_fail('No webhook token is configured for Shiprocket.');
        }

        $expected = secret_decrypt($secretRaw);
        if ($expected === '') {
            // Stored but unreadable (the app key changed): as good as none.
            return shipping_fail('The Shiprocket webhook token cannot be read. Save it again.');
        }
        $given = '';
        foreach ($headers as $name => $value) {
            if (strtolower((string) $name) === 'x-api-key') {
                $given = (string) $value;
                break;
            }
        }
        if ($given === '' || !hash_equals($expected, $given)) {
            return shipping_fail('Webhook token did not match.');
        }

        $awb   = trim((string) ($payload['awb'] ?? ''));
        $label = (string) ($payload['current_status'] ?? $payload['shipment_status'] ?? '');
        $code  = (string) ($payload['current_status_id'] ?? $payload['shipment_status_id'] ?? '');
        if ($awb === '' || ($label === '' && $code === '')) {
            return shipping_fail('Webhook is missing awb or status.');
        }

        [$events, $latest] = self::scanEvents($awb, (array) ($payload['scans'] ?? []));

        $rawAt    = (string) ($payload['current_timestamp'] ?? '');
        $headline = self::headlineEvent($awb, $label, $code, self::readTime($rawAt) ?? $latest, $rawAt, $events);
        if ($headline !== null) {
            $events[] = $headline;
        }

        $srOrderId = $payload['sr_order_id'] ?? '';

        return [
            'ok'               => true,
            'awb'              => $awb,
            // The documented webhook carries no shipment id - read only in case
            // Shiprocket adds one. Its sr_order_id is Shiprocket's ORDER id,
            // which createShipment stored as order_ref: that is how a push for
            // an AWB we never heard of (assigned by a request that timed out,
            // or reassigned in the panel) still finds its booking.
            'shipment_ref'     => is_scalar($payload['shipment_id'] ?? null) ? trim((string) $payload['shipment_id']) : '',
            'order_ref'        => is_scalar($srOrderId) ? trim((string) $srOrderId) : '',
            // `order_id` is OUR channel order id (order number + hub shipment
            // id), echoed back - not a Shiprocket reference.
            'channel_order_id' => is_scalar($payload['order_id'] ?? null) ? (string) $payload['order_id'] : '',
            'courier_name'     => (string) ($payload['courier_name'] ?? ''),
            'events'           => $events,
            'message'          => 'Webhook accepted.',
        ];
    }
}
