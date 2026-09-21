<?php
/**
 * ShopInnKart - Shipping provider abstraction.
 *
 * One interface, one registry, one driver per courier. The rule this exists to
 * enforce: adding Delhivery must mean adding a class, not editing the order,
 * payment, product or customer modules.
 *
 * Modelled on PaymentGatewayFactory (includes/order-functions.php), which
 * already solves the same problem for payments. Following the precedent beats
 * inventing a second pattern for the same shape of thing.
 *
 * Every method answers in a fixed shape regardless of courier, because the
 * caller must never branch on which provider it is talking to. A provider that
 * cannot do something returns a refusal, not an exception - "this PIN is not
 * serviceable" is an ordinary answer, not a failure.
 */

declare(strict_types=1);

// Drivers. One require per courier; the registry below maps code to class.
require_once __DIR__ . '/shipping/MockShippingProvider.php';

/**
 * What every courier integration must be able to do.
 *
 * The eleven operations the admin panel drives. A driver that genuinely cannot
 * perform one returns ['ok' => false, 'message' => '...'] rather than throwing;
 * not every courier offers manifests or return pickups.
 */
interface ShippingProviderInterface
{
    /** Machine code, matching shipping_providers.code. */
    public function code(): string;

    /** Name shown in the admin. */
    public function label(): string;

    /**
     * The credential fields this provider needs, for the admin form to render.
     *
     * @return array<string, array{label:string, type:string, help?:string, required?:bool}>
     */
    public function credentialFields(): array;

    /** Prove the stored credentials work. ['ok'=>bool, 'message'=>string] */
    public function testConnection(): array;

    /** ['ok'=>bool, 'rates'=>[['courier'=>, 'service'=>, 'cost'=>, 'eta_days'=>, 'cod'=>bool]], 'message'=>string] */
    public function getRates(array $shipment): array;

    /** ['ok'=>bool, 'serviceable'=>bool, 'cod'=>bool, 'eta_days'=>?int, 'message'=>string] */
    public function checkServiceability(string $originPin, string $destinationPin, array $parcel = []): array;

    /** ['ok'=>bool, 'shipment_ref'=>string, 'courier_name'=>?string, 'message'=>string] */
    public function createShipment(array $order, array $options = []): array;

    /** ['ok'=>bool, 'awb'=>string, 'courier_name'=>?string, 'message'=>string] */
    public function generateAwb(array $shipment, array $options = []): array;

    /** ['ok'=>bool, 'url'=>string, 'message'=>string] */
    public function generateLabel(array $shipment): array;

    /** ['ok'=>bool, 'url'=>string, 'message'=>string] */
    public function generateManifest(array $shipments): array;

    /** ['ok'=>bool, 'pickup_date'=>?string, 'message'=>string] */
    public function schedulePickup(array $shipment, array $options = []): array;

    /** ['ok'=>bool, 'status'=>string, 'events'=>[['status'=>,'message'=>,'location'=>,'occurred_at'=>,'event_key'=>]], 'message'=>string] */
    public function trackShipment(array $shipment): array;

    /** ['ok'=>bool, 'message'=>string] */
    public function cancelShipment(array $shipment, string $reason = ''): array;

    /** ['ok'=>bool, 'shipment_ref'=>string, 'awb'=>?string, 'message'=>string] */
    public function createReturn(array $shipment, array $options = []): array;

    /** ['ok'=>bool, 'status'=>string, 'message'=>string] */
    public function checkReturnStatus(array $shipment): array;

    /**
     * Verify an inbound webhook and normalise it.
     *
     * ['ok'=>bool, 'awb'=>?string, 'shipment_ref'=>?string, 'events'=>[...], 'message'=>string]
     */
    public function parseWebhook(array $payload, array $headers = []): array;
}

/**
 * The registry.
 *
 * `available()` returns only providers that are BOTH configured in the database
 * AND implemented in code - the same rule PaymentGatewayFactory applies, and for
 * the same reason: a row an admin switched on for a courier nobody wrote a
 * driver for must not be offered and then fail at booking time.
 */
final class ShippingProviderFactory
{
    /** @var array<string, class-string<ShippingProviderInterface>> */
    private static array $drivers = [
        'mock' => MockShippingProvider::class,
    ];

    public static function register(string $code, string $class): void
    {
        if (!is_subclass_of($class, ShippingProviderInterface::class)) {
            throw new InvalidArgumentException($class . ' must implement ShippingProviderInterface.');
        }
        self::$drivers[$code] = $class;
    }

    /** Is there code for this provider, whatever the database says? */
    public static function implemented(string $code): bool
    {
        return isset(self::$drivers[$code]);
    }

    /** @return string[] */
    public static function implementedCodes(): array
    {
        return array_keys(self::$drivers);
    }

    /**
     * Build a driver for a provider row.
     *
     * Takes the row rather than the code so the driver has its own credentials
     * and mode without going back to the database - and so a caller cannot
     * accidentally build a live driver while looking at a test row.
     */
    public static function make(array $provider): ?ShippingProviderInterface
    {
        $class = self::$drivers[(string) ($provider['code'] ?? '')] ?? null;
        return $class === null ? null : new $class($provider);
    }

    /** Build from a code, reading the row. */
    public static function makeByCode(string $code): ?ShippingProviderInterface
    {
        $row = shipping_provider($code);
        return $row === null ? null : self::make($row);
    }

    /** Active rows that also have a driver. */
    public static function available(): array
    {
        $rows = Database::fetchAll(
            "SELECT * FROM `shipping_providers` WHERE `status` = 'active' ORDER BY `is_default` DESC, `sort_order`, `id`"
        );
        return array_values(array_filter(
            $rows,
            static fn (array $row): bool => self::implemented((string) $row['code'])
        ));
    }
}

// ===========================================================================
//  Rows
// ===========================================================================

/** One provider row by code, or null. */
function shipping_provider(string $code): ?array
{
    return Database::fetch('SELECT * FROM `shipping_providers` WHERE `code` = :c LIMIT 1', ['c' => $code]);
}

/** Every provider row, whether or not a driver exists for it. */
function shipping_providers_all(): array
{
    return Database::fetchAll('SELECT * FROM `shipping_providers` ORDER BY `sort_order`, `id`');
}

/** The provider a booking uses when the admin does not pick one. */
function shipping_default_provider(): ?array
{
    foreach (ShippingProviderFactory::available() as $row) {
        if ((int) $row['is_default'] === 1) {
            return $row;
        }
    }
    return ShippingProviderFactory::available()[0] ?? null;
}

// ===========================================================================
//  Credentials
// ===========================================================================

/**
 * Decrypt a provider's credentials.
 *
 * Stored as one encrypted JSON blob rather than a column per field, because
 * every courier wants a different set and an ALTER TABLE per integration is
 * exactly the coupling this layer exists to avoid.
 */
function shipping_credentials(array $provider): array
{
    $raw = (string) ($provider['credentials'] ?? '');
    if ($raw === '') {
        return [];
    }

    $json = secret_decrypt($raw);
    if (!is_string($json) || $json === '') {
        return [];
    }

    $data = json_decode($json, true);
    return is_array($data) ? $data : [];
}

/** Encrypt and store credentials for a provider. */
function shipping_store_credentials(int $providerId, array $credentials): void
{
    $clean = [];
    foreach ($credentials as $key => $value) {
        $value = trim((string) $value);
        if ($value !== '') {
            $clean[$key] = $value;
        }
    }

    Database::update(
        'shipping_providers',
        ['credentials' => $clean === [] ? null : secret_encrypt((string) json_encode($clean))],
        '`id` = :id',
        ['id' => $providerId]
    );
}

// ===========================================================================
//  Logging
// ===========================================================================

/**
 * Record one API call.
 *
 * Bodies arrive already masked - shipping_mask() below - because a log that
 * quietly records an API key is a second place to leak it, and the one place
 * nobody thinks to check.
 */
function shipping_log(string $providerCode, string $operation, array $entry): void
{
    Database::insert('shipping_api_logs', [
        'provider_code' => $providerCode,
        'shipment_id'   => $entry['shipment_id'] ?? null,
        'operation'     => $operation,
        'endpoint'      => $entry['endpoint'] ?? null,
        'http_status'   => $entry['http_status'] ?? null,
        'duration_ms'   => $entry['duration_ms'] ?? null,
        'ok'            => !empty($entry['ok']) ? 1 : 0,
        'request_body'  => isset($entry['request']) ? shipping_mask($entry['request']) : null,
        'response_body' => isset($entry['response']) ? shipping_mask($entry['response']) : null,
        'error'         => isset($entry['error']) ? mb_substr((string) $entry['error'], 0, 500) : null,
    ]);
}

/** Replace anything that looks like a secret before it reaches the log. */
function shipping_mask($payload): string
{
    $text = is_string($payload) ? $payload : (string) json_encode($payload);

    return (string) preg_replace(
        '~("?(?:password|passwd|secret|token|api[_-]?key|authorization|auth)"?\s*[:=]\s*"?)([^",}\s]{4,})~i',
        '$1***',
        $text
    );
}

/** Uniform failure shape, so callers never branch on which provider failed. */
function shipping_fail(string $message, array $extra = []): array
{
    return array_merge(['ok' => false, 'message' => $message], $extra);
}
