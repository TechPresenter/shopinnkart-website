<?php
/**
 * ShopInnKart - Mock courier.
 *
 * A complete, honest implementation of ShippingProviderInterface that talks to
 * nothing. It exists so the whole hub - admin screens, shipment records, the
 * tracking timeline, labels, RTO - can be built, demonstrated and tested before
 * any real courier account exists, and so a regression in the shared layer is
 * found without spending a live booking to find it.
 *
 * It is deliberately NOT a stub that answers ok to everything. A courier
 * refuses things, and code that has only ever seen success handles refusal
 * badly. So this one:
 *
 *   - declares some PIN codes unserviceable
 *   - refuses COD above a limit
 *   - prices by weight and distance rather than returning a constant
 *   - advances a shipment through the real status sequence over time
 *
 * Every answer is deterministic, derived from the AWB or the PIN, so a test
 * asserts a value rather than a range and the same shipment tells the same
 * story on every call.
 */

declare(strict_types=1);

final class MockShippingProvider implements ShippingProviderInterface
{
    private array $provider;

    public function __construct(array $provider = [])
    {
        $this->provider = $provider;
    }

    public function code(): string
    {
        return 'mock';
    }

    public function label(): string
    {
        return 'Mock Courier (testing)';
    }

    /**
     * Nothing is needed to talk to nothing, but the admin form should still
     * have something to save so the configure screen can be exercised.
     */
    public function credentialFields(): array
    {
        return [
            'api_key' => [
                'label'    => 'API key',
                'type'     => 'text',
                'help'     => 'Any value. The mock courier never checks it.',
                'required' => false,
            ],
        ];
    }

    public function testConnection(): array
    {
        return ['ok' => true, 'message' => 'Mock courier reachable. No network call was made.'];
    }

    // -----------------------------------------------------------------
    //  Rating and serviceability
    // -----------------------------------------------------------------

    /**
     * PIN codes ending 00 are treated as unserviceable.
     *
     * An arbitrary rule, but a stable one: it gives every test a guaranteed
     * failing PIN without needing a list of real ones.
     */
    private function serviceable(string $pin): bool
    {
        $pin = preg_replace('/\D+/', '', $pin) ?? '';
        return preg_match('/^[1-9]\d{5}$/', $pin) === 1 && substr($pin, -2) !== '00';
    }

    /** Distance stands in for the gap between the two PIN prefixes. */
    private function zoneDistance(string $origin, string $destination): int
    {
        $a = (int) substr(preg_replace('/\D+/', '', $origin) ?: '110001', 0, 2);
        $b = (int) substr(preg_replace('/\D+/', '', $destination) ?: '110001', 0, 2);
        return abs($a - $b);
    }

    public function checkServiceability(string $originPin, string $destinationPin, array $parcel = []): array
    {
        if (!$this->serviceable($destinationPin)) {
            return [
                'ok'          => true,
                'serviceable' => false,
                'cod'         => false,
                'eta_days'    => null,
                'message'     => 'Not serviceable at ' . $destinationPin . '.',
            ];
        }

        $distance = $this->zoneDistance($originPin, $destinationPin);

        return [
            'ok'          => true,
            'serviceable' => true,
            'cod'         => true,
            'eta_days'    => 2 + (int) floor($distance / 15),
            'message'     => 'Serviceable.',
        ];
    }

    public function getRates(array $shipment): array
    {
        $origin      = (string) ($shipment['origin_pin'] ?? '');
        $destination = (string) ($shipment['destination_pin'] ?? '');
        $grams       = max(100, (int) ($shipment['weight_grams'] ?? 500));
        $isCod       = !empty($shipment['is_cod']);
        $codAmount   = (float) ($shipment['cod_amount'] ?? 0);

        $service = $this->checkServiceability($origin, $destination);
        if (!$service['serviceable']) {
            return ['ok' => true, 'rates' => [], 'message' => $service['message']];
        }

        $distance = $this->zoneDistance($origin, $destination);
        $slabs    = (int) ceil($grams / 500);

        // Three services, so the auto-selection logic has something to choose
        // between: cheapest, fastest, and one in the middle.
        $rates = [
            ['courier' => 'Mock Surface', 'service' => 'surface', 'speed' => 0],
            ['courier' => 'Mock Express', 'service' => 'express', 'speed' => 1],
            ['courier' => 'Mock Priority', 'service' => 'priority', 'speed' => 2],
        ];

        $out = [];
        foreach ($rates as $rate) {
            $cost = 28 + ($slabs - 1) * 22 + $distance * 1.6 + $rate['speed'] * 24;

            // COD costs the courier money to collect and remit, so it costs us.
            $codFee = 0.0;
            if ($isCod) {
                if ($codAmount > 50000) {
                    continue;   // over the mock courier's COD ceiling
                }
                $codFee = max(35, round($codAmount * 0.015, 2));
            }

            $out[] = [
                'courier'  => $rate['courier'],
                'service'  => $rate['service'],
                'cost'     => round($cost + $codFee, 2),
                'cod_fee'  => $codFee,
                'eta_days' => max(1, ($service['eta_days'] ?? 3) - $rate['speed']),
                'cod'      => $isCod,
                'rating'   => 4.6 - $rate['speed'] * 0.2,
            ];
        }

        if ($out === []) {
            return ['ok' => true, 'rates' => [], 'message' => 'No service available for this COD amount.'];
        }

        return ['ok' => true, 'rates' => $out, 'message' => count($out) . ' service(s) available.'];
    }

    // -----------------------------------------------------------------
    //  Booking
    // -----------------------------------------------------------------

    public function createShipment(array $order, array $options = []): array
    {
        $pin = (string) ($order['shipping_pincode'] ?? '');
        if (!$this->serviceable($pin)) {
            return shipping_fail('Mock courier does not serve ' . $pin . '.');
        }

        // Deterministic, so a test asserts a value - but per shipment, not per
        // order: a real courier issues a fresh AWB when a cancelled order is
        // rebooked, and the AWB is derived from this reference.
        $ref = 'MOCK' . str_pad((string) (int) ($order['id'] ?? 0), 6, '0', STR_PAD_LEFT)
            . str_pad((string) ((int) ($options['shipment_id'] ?? 0) % 10000), 4, '0', STR_PAD_LEFT);

        return [
            'ok'           => true,
            'shipment_ref' => $ref,
            'courier_name' => (string) ($options['service'] ?? 'Mock Surface'),
            'message'      => 'Shipment created with the mock courier.',
        ];
    }

    public function generateAwb(array $shipment, array $options = []): array
    {
        $ref = (string) ($shipment['shipment_ref'] ?? '');
        if ($ref === '') {
            return shipping_fail('Create the shipment before requesting an AWB.');
        }

        // A checksum digit makes the AWB look like a real one and gives the
        // label's barcode something that can be validated.
        $base = substr(preg_replace('/\D+/', '', $ref) ?: '0', -8);
        $base = str_pad($base, 8, '0', STR_PAD_LEFT);
        $sum  = 0;
        foreach (str_split($base) as $i => $digit) {
            $sum += (int) $digit * (($i % 2 === 0) ? 3 : 1);
        }

        return [
            'ok'           => true,
            'awb'          => 'MK' . $base . ($sum % 10),
            'courier_name' => (string) ($shipment['courier_name'] ?? 'Mock Surface'),
            'message'      => 'AWB assigned.',
        ];
    }

    public function generateLabel(array $shipment): array
    {
        $awb = (string) ($shipment['awb'] ?? '');
        if ($awb === '') {
            return shipping_fail('Generate an AWB before the label.');
        }

        // The hub renders the PDF itself from the shipment record, so the mock
        // only has to say where it will live. A real courier returns its own URL.
        return [
            'ok'      => true,
            'url'     => admin_url('shipping/label.php?awb=' . rawurlencode($awb)),
            'message' => 'Label ready.',
        ];
    }

    public function generateManifest(array $shipments): array
    {
        $awbs = array_values(array_filter(array_map(
            static fn (array $s): string => (string) ($s['awb'] ?? ''),
            $shipments
        )));

        if ($awbs === []) {
            return shipping_fail('No shipments with an AWB to manifest.');
        }

        return [
            'ok'      => true,
            'url'     => admin_url('shipping/manifest.php?awb=' . rawurlencode(implode(',', $awbs))),
            'message' => count($awbs) . ' shipment(s) manifested.',
        ];
    }

    public function schedulePickup(array $shipment, array $options = []): array
    {
        if ((string) ($shipment['awb'] ?? '') === '') {
            return shipping_fail('Assign an AWB before scheduling a pickup.');
        }

        $date = (string) ($options['pickup_date'] ?? '');
        if ($date === '') {
            // Same-day requests after 16:00 roll to tomorrow, the way a real
            // cut-off works.
            $date = (int) date('H') >= 16
                ? date('Y-m-d', strtotime('+1 day'))
                : date('Y-m-d');
        }

        return ['ok' => true, 'pickup_date' => $date, 'message' => 'Pickup scheduled for ' . $date . '.'];
    }

    // -----------------------------------------------------------------
    //  Tracking
    // -----------------------------------------------------------------

    /**
     * The status sequence, advanced by how long ago the shipment was booked.
     *
     * Time-driven rather than random so a shipment tells the same story on
     * every call, and so a test can move a shipment along by backdating its
     * created_at instead of waiting.
     */
    public function trackShipment(array $shipment): array
    {
        $awb = (string) ($shipment['awb'] ?? '');
        if ($awb === '') {
            return shipping_fail('No AWB to track.');
        }

        $bookedAt = strtotime((string) ($shipment['created_at'] ?? 'now')) ?: time();
        $hours    = max(0, (int) floor((time() - $bookedAt) / 3600));

        $stages = [
            ['after' => 0,  'status' => 'booked',           'message' => 'Shipment booked',        'location' => 'Origin hub'],
            ['after' => 2,  'status' => 'pickup_scheduled', 'message' => 'Pickup scheduled',       'location' => 'Origin hub'],
            ['after' => 6,  'status' => 'in_transit',       'message' => 'In transit',             'location' => 'Sorting centre'],
            ['after' => 30, 'status' => 'out_for_delivery', 'message' => 'Out for delivery',       'location' => 'Destination hub'],
            ['after' => 40, 'status' => 'delivered',        'message' => 'Delivered',              'location' => 'Destination'],
        ];

        $events = [];
        $status = 'booked';
        foreach ($stages as $stage) {
            if ($hours < $stage['after']) {
                break;
            }
            $at     = date('Y-m-d H:i:s', $bookedAt + $stage['after'] * 3600);
            $status = $stage['status'];
            $events[] = [
                'status'      => $stage['status'],
                'message'     => $stage['message'],
                'location'    => $stage['location'],
                'occurred_at' => $at,
                // Stable key: the same stage for the same AWB is always the same
                // event, so a re-poll updates nothing rather than duplicating.
                'event_key'   => $awb . ':' . $stage['status'],
            ];
        }

        return ['ok' => true, 'status' => $status, 'events' => $events, 'message' => 'Tracking updated.'];
    }

    public function cancelShipment(array $shipment, string $reason = ''): array
    {
        $status = (string) ($shipment['status'] ?? '');
        if (in_array($status, ['delivered', 'rto_delivered', 'returned'], true)) {
            return shipping_fail('A ' . $status . ' shipment cannot be cancelled.');
        }
        return ['ok' => true, 'message' => 'Shipment cancelled with the mock courier.'];
    }

    // -----------------------------------------------------------------
    //  Returns
    // -----------------------------------------------------------------

    public function createReturn(array $shipment, array $options = []): array
    {
        $awb = (string) ($shipment['awb'] ?? '');
        if ($awb === '') {
            return shipping_fail('Only a booked shipment can be returned.');
        }

        return [
            'ok'           => true,
            'shipment_ref' => 'RET' . substr($awb, 2),
            'awb'          => 'RT' . substr($awb, 2),
            'message'      => 'Return pickup created.',
        ];
    }

    public function checkReturnStatus(array $shipment): array
    {
        $bookedAt = strtotime((string) ($shipment['updated_at'] ?? 'now')) ?: time();
        $hours    = max(0, (int) floor((time() - $bookedAt) / 3600));

        $status = $hours >= 48 ? 'returned' : ($hours >= 12 ? 'in_transit' : 'return_pickup');

        return ['ok' => true, 'status' => $status, 'message' => 'Return is ' . $status . '.'];
    }

    // -----------------------------------------------------------------
    //  Webhook
    // -----------------------------------------------------------------

    /**
     * Verify and normalise an inbound call.
     *
     * The signature check is real even though the courier is not: it is the
     * part most likely to be wrong when a live provider is added, so it should
     * be exercised from the start rather than written for the first time
     * against a courier that is also new.
     */
    public function parseWebhook(array $payload, array $headers = [], string $rawBody = ''): array
    {
        $secretRaw = (string) ($this->provider['webhook_secret'] ?? '');
        if ($secretRaw !== '') {
            $secret    = secret_decrypt($secretRaw);
            $signature = (string) ($headers['x-mock-signature'] ?? $headers['X-Mock-Signature'] ?? '');
            // Over the exact bytes received, never a re-encoding: json_encode
            // reorders keys and changes whitespace, so a courier's real
            // signature would never match. The fallback exists only for callers
            // that have no raw body, i.e. unit tests.
            $signed    = $rawBody !== '' ? $rawBody : (string) json_encode($payload);
            $expected  = hash_hmac('sha256', $signed, $secret);

            // hash_equals, not ===: a timing-safe compare is the whole point of
            // signing, and the habit matters more in the template than here.
            if ($signature === '' || !hash_equals($expected, $signature)) {
                return shipping_fail('Webhook signature did not verify.');
            }
        }

        $awb    = (string) ($payload['awb'] ?? '');
        $status = (string) ($payload['status'] ?? '');
        if ($awb === '' || $status === '') {
            return shipping_fail('Webhook is missing awb or status.');
        }

        $occurredAt = (string) ($payload['occurred_at'] ?? date('Y-m-d H:i:s'));

        return [
            'ok'           => true,
            'awb'          => $awb,
            'shipment_ref' => (string) ($payload['shipment_ref'] ?? ''),
            'events'       => [[
                'status'      => $status,
                'message'     => (string) ($payload['message'] ?? ucfirst(str_replace('_', ' ', $status))),
                'location'    => (string) ($payload['location'] ?? ''),
                'occurred_at' => $occurredAt,
                // Prefer the courier's own event id; fall back to something
                // stable so a resend is still recognised as the same event.
                'event_key'   => (string) ($payload['event_id'] ?? $awb . ':' . $status . ':' . $occurredAt),
            ]],
            'message' => 'Webhook accepted.',
        ];
    }
}
