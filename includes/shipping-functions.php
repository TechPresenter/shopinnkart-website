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
require_once __DIR__ . '/shipping/ShiprocketShippingProvider.php';

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
    public function parseWebhook(array $payload, array $headers = [], string $rawBody = ''): array;
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
        'mock'       => MockShippingProvider::class,
        'shiprocket' => ShiprocketShippingProvider::class,
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

    // A bearer token first: the key/value rule below stops at the first space,
    // so "Authorization: Bearer eyJ..." would mask the word Bearer and leave
    // the token itself in the log.
    $text = (string) preg_replace('~(Bearer\s+)[A-Za-z0-9._\-]{8,}~i', '$1***', $text);

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

// ===========================================================================
//  HTTP
// ===========================================================================

/**
 * One call to a courier API, with the timeout, retry and logging every driver
 * needs and none should reimplement.
 *
 * Retries only what can succeed on a second try: a network failure, a 5xx or a
 * 429. A 4xx is the courier telling us the request is wrong, and sending it
 * again gets the same answer - or, for a booking, a second consignment.
 *
 * Options:
 *   headers     array<string,string>
 *   json        array   request body, sent as JSON
 *   query       array   appended to the URL
 *   timeout     int     seconds, default 20
 *   retries     int     extra attempts after the first, default 2
 *   shipment_id int     for the log
 *   transport   callable(method, url, headers, body, timeout): array{status:int, body:string, error:?string}
 *               Replaces cURL. Drivers pass one in tests so response mapping can
 *               be verified against recorded fixtures without a network.
 *
 * @return array{ok:bool, status:int, body:?array, raw:string, error:?string, duration_ms:int, attempts:int}
 */
function shipping_http(string $providerCode, string $operation, string $method, string $url, array $opts = []): array
{
    $method  = strtoupper($method);
    $timeout = (int) ($opts['timeout'] ?? 20);
    $retries = max(0, (int) ($opts['retries'] ?? 2));

    if (!empty($opts['query'])) {
        $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($opts['query']);
    }

    $headers = ['Accept' => 'application/json'] + (array) ($opts['headers'] ?? []);
    $body    = null;
    if (array_key_exists('json', $opts)) {
        $body = (string) json_encode($opts['json'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $headers['Content-Type'] = 'application/json';
    }

    $transport = $opts['transport'] ?? 'shipping_curl_transport';
    $started   = microtime(true);
    $attempt   = 0;
    $response  = ['status' => 0, 'body' => '', 'error' => null];

    while (true) {
        $attempt++;
        try {
            $response = $transport($method, $url, $headers, $body, $timeout);
        } catch (Throwable $e) {
            $response = ['status' => 0, 'body' => '', 'error' => $e->getMessage()];
        }

        $status    = (int) ($response['status'] ?? 0);
        $retryable = $status === 0 || $status === 429 || $status >= 500;

        if (!$retryable || $attempt > $retries) {
            break;
        }
        // 250ms, then 750ms - long enough for a rate limit to clear, short
        // enough that an admin clicking "Book" is not left waiting.
        usleep($attempt === 1 ? 250000 : 750000);
    }

    $status  = (int) ($response['status'] ?? 0);
    $raw     = (string) ($response['body'] ?? '');
    $decoded = $raw === '' ? null : json_decode($raw, true);
    $ok      = $status >= 200 && $status < 300 && empty($response['error']);
    $ms      = (int) round((microtime(true) - $started) * 1000);

    $error = $response['error'] ?? null;
    if (!$ok && $error === null) {
        $error = shipping_error_text($decoded, $status);
    }

    shipping_log($providerCode, $operation, [
        'shipment_id' => $opts['shipment_id'] ?? null,
        'endpoint'    => $method . ' ' . (string) (parse_url($url, PHP_URL_PATH) ?: $url),
        'http_status' => $status ?: null,
        'duration_ms' => $ms,
        'ok'          => $ok,
        'request'     => $body ?? (object) [],
        'response'    => $raw !== '' ? $raw : (object) [],
        'error'       => $ok ? null : ($attempt > 1 ? $error . ' (after ' . $attempt . ' attempts)' : $error),
    ]);

    return [
        'ok'          => $ok,
        'status'      => $status,
        'body'        => is_array($decoded) ? $decoded : null,
        'raw'         => $raw,
        'error'       => $ok ? null : $error,
        'duration_ms' => $ms,
        'attempts'    => $attempt,
    ];
}

/** The default transport: cURL, with TLS verification left on. */
function shipping_curl_transport(string $method, string $url, array $headers, ?string $body, int $timeout): array
{
    $ch = curl_init($url);

    $lines = [];
    foreach ($headers as $name => $value) {
        $lines[] = $name . ': ' . $value;
    }

    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $lines,
        CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_USERAGENT      => 'ShopInnKart/1.0 (+shipping)',
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $raw    = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error  = $raw === false ? (curl_error($ch) ?: 'Network error') : null;
    curl_close($ch);

    return ['status' => $status, 'body' => $raw === false ? '' : (string) $raw, 'error' => $error];
}

/** Pull a readable message out of whatever error shape a courier returns. */
function shipping_error_text(?array $body, int $status): string
{
    if (is_array($body)) {
        foreach (['message', 'error', 'msg', 'detail'] as $key) {
            if (!empty($body[$key]) && is_string($body[$key])) {
                return $body[$key];
            }
        }
        // Validation errors often arrive as {"errors": {"field": ["..."]}}.
        if (!empty($body['errors']) && is_array($body['errors'])) {
            $first = reset($body['errors']);
            if (is_array($first)) {
                $first = reset($first);
            }
            if (is_string($first) && $first !== '') {
                return $first;
            }
        }
    }
    return $status === 0 ? 'The courier could not be reached.' : 'The courier returned HTTP ' . $status . '.';
}
