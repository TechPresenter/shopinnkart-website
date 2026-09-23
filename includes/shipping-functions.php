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

/**
 * Is this a simulator rather than a courier that carries parcels?
 *
 * The mock driver invents its own scans from the clock - forty hours after
 * booking it reports "delivered", which marks a COD order paid, puts the
 * delivery email in the queue and closes the return window. That is what makes
 * it useful on a test bench and what makes it unsafe for anything running
 * unattended: the tracking cron must never walk a leftover test order forward
 * on its own. An admin pressing Refresh on one shipment still can.
 *
 * A driver may answer for itself with isSimulated(), optional and outside the
 * interface exactly like tracksByReference(); one that does not is taken at its
 * code, so a future test driver only has to say so.
 */
function shipping_is_simulated(string $code): bool
{
    // Built from the code alone: no credentials, and nothing is called.
    $driver = ShippingProviderFactory::make(['code' => $code]);
    if ($driver !== null && method_exists($driver, 'isSimulated')) {
        return $driver->isSimulated() === true;
    }
    return in_array($code, ['mock'], true);
}

/**
 * Every installed courier that really carries parcels.
 *
 * @return string[]
 */
function shipping_real_codes(): array
{
    return array_values(array_filter(
        ShippingProviderFactory::implementedCodes(),
        static fn (string $code): bool => !shipping_is_simulated($code)
    ));
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
    if (!is_array($data)) {
        return [];
    }

    // Every secret read is remembered, so shipping_mask() can take its VALUE
    // out of a log wherever it turns up - not only where a key names it.
    foreach ($data as $key => $value) {
        if (is_scalar($value) && shipping_is_secret_key((string) $key)) {
            shipping_remember_secret((string) $value);
        }
    }

    return $data;
}

/**
 * Credentials are stored but cannot be read.
 *
 * That happens when the application key changed after they were saved (a
 * restored dump, a regenerated config/app.key.php). The column still holds
 * ciphertext, so "is anything stored?" says yes while every call fails to log
 * in - the admin has to be told to enter them again.
 */
function shipping_credentials_unreadable(array $provider): bool
{
    return (string) ($provider['credentials'] ?? '') !== '' && shipping_credentials($provider) === [];
}

/**
 * The webhook secret in plain text, or '' when there is no usable one.
 *
 * '' covers two cases: none was ever saved, and one was saved under an
 * application key this install no longer has. In the second the column is
 * NOT empty, which is why nothing may judge "is a secret configured?" by the
 * column: an HMAC keyed with '' is one anybody can compute.
 */
function shipping_webhook_secret(array $provider): string
{
    $secret = secret_decrypt((string) ($provider['webhook_secret'] ?? ''));
    shipping_remember_secret($secret);
    return $secret;
}

/** A webhook secret is stored but no longer decrypts. */
function shipping_webhook_secret_unreadable(array $provider): bool
{
    return (string) ($provider['webhook_secret'] ?? '') !== '' && shipping_webhook_secret($provider) === '';
}

/** Does this credential or payload key hold something that must never be logged? */
function shipping_is_secret_key(string $key): bool
{
    // Expiry timestamps sit beside tokens under token-ish names; they are not
    // secret, and remembering "1790000000" would mask every such number.
    return preg_match('/passw|passphrase|(^|[_-])pass$|secret|token|api[_-]?key|apikey|authori[sz]ation|^auth$|private[_-]?key/i', $key) === 1
        && preg_match('/expir/i', $key) !== 1;
}

/**
 * Every secret value this request has read, for shipping_mask().
 *
 * Masking by key alone misses a password a courier echoes back in an error
 * message, or one that reaches a log url-encoded. Knowing the value itself
 * catches it wherever it lands.
 *
 * @return string[]
 */
function shipping_secret_values(string ...$add): array
{
    static $values = [];
    foreach ($add as $value) {
        // Shorter than this and masking would start eating ordinary words and
        // numbers. A short secret under its own key is still masked by name.
        if (strlen($value) >= 4) {
            $values[$value] = true;
        }
    }
    return array_map('strval', array_keys($values));
}

function shipping_remember_secret(string $value): void
{
    shipping_secret_values($value);
}

/**
 * Remember a provider row's own secrets before writing its log.
 *
 * Read from the row rather than trusted to have been decrypted earlier in the
 * request: a refused webhook, say, never touches the credentials, yet its body
 * may carry them. Skipped when the row has not changed since the last look.
 */
function shipping_remember_provider_secrets(string $providerCode): void
{
    static $seen = [];

    $row = Database::fetch(
        'SELECT `credentials`, `webhook_secret` FROM `shipping_providers` WHERE `code` = :c LIMIT 1',
        ['c' => $providerCode]
    );
    if ($row === null) {
        return;
    }
    $fingerprint = md5((string) $row['credentials'] . '|' . (string) $row['webhook_secret']);
    if (($seen[$providerCode] ?? null) === $fingerprint) {
        return;
    }
    $seen[$providerCode] = $fingerprint;

    shipping_credentials($row);
    shipping_webhook_secret($row);
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
//  Webhook routing
// ===========================================================================

/** The provider a webhook slug belongs to, or null. */
function shipping_provider_by_hook(string $slug): ?array
{
    if (preg_match('/^[A-Za-z0-9_-]{8,40}$/', $slug) !== 1) {
        return null;
    }
    return Database::fetch('SELECT * FROM `shipping_providers` WHERE `webhook_slug` = :s LIMIT 1', ['s' => $slug]);
}

/**
 * The provider's webhook slug, or '' if it has none yet.
 *
 * The migration gives every existing row one. A row inserted by hand later -
 * "Adding a courier" tells the owner to insert one - has none until something
 * asks for it with $create, which is the save in Admin > Shipping > Configure.
 *
 * $create is deliberately not the default. This used to fill the slot on
 * whoever looked first, which made a plain GET of the configure page UPDATE the
 * row - a write performed by a settings.view-only admin who may not change the
 * integration, and one that moved the row under anything else reading it.
 */
function shipping_webhook_slug(array $provider, bool $create = false): string
{
    $slug = (string) ($provider['webhook_slug'] ?? '');
    if ($slug !== '' || !$create || empty($provider['id'])) {
        return $slug;
    }

    // Hex, so no letter past "f": no courier name, "sr" or "kr" can ever
    // appear in it. Only fills an empty slot, so two first saves agree.
    Database::query(
        "UPDATE `shipping_providers` SET `webhook_slug` = :s
          WHERE `id` = :id AND (`webhook_slug` IS NULL OR `webhook_slug` = '')",
        ['s' => bin2hex(random_bytes(12)), 'id' => (int) $provider['id']]
    );
    return (string) Database::fetchColumn(
        'SELECT `webhook_slug` FROM `shipping_providers` WHERE `id` = :id',
        ['id' => (int) $provider['id']]
    );
}

/**
 * The URL a courier is given, or '' while the integration has no slug.
 *
 * It names the integration by an opaque slug, never by courier: Shiprocket
 * refuses a webhook URL containing "shiprocket", "kartrocket", "sr" or "kr",
 * and a code in the URL tells whoever sees it which secret to go after.
 *
 * Empty rather than a half URL, so a screen can say "save it once" instead of
 * handing the courier an endpoint that resolves to nothing.
 */
function shipping_webhook_url(array $provider, bool $create = false): string
{
    $slug = shipping_webhook_slug($provider, $create);

    return $slug === '' ? '' : url('api/shipping/webhook.php?hook=' . rawurlencode($slug));
}

// ===========================================================================
//  Webhook throttle
// ===========================================================================

/**
 * Failed webhook calls one caller may make per window before its UNVERIFIED
 * calls are refused for the rest of the window.
 *
 * Failures only, and a verified call is never refused by it - see step 5 of
 * api/shipping/webhook.php. A courier pushing one scan per parcel of a
 * 300-parcel pickup from a single egress IP is doing its job, and a 429 there
 * loses the update for good: Shiprocket, for one, does not promise to retry.
 * Anything else would hand an anonymous caller a way to silence the courier by
 * spending the budget itself.
 */
const SHIPPING_WEBHOOK_MAX_FAILURES = 120;
const SHIPPING_WEBHOOK_WINDOW       = 60;
const SHIPPING_WEBHOOK_BUCKET       = 'shipping_webhook';

/**
 * How many refusals in a window are written down in full before only the count
 * is kept. A refused call logs the body a stranger chose, so "log every one"
 * is a way to fill the database from outside; "log none" loses the courier's
 * malformed push, which is the one an admin has to see.
 */
const SHIPPING_WEBHOOK_LOG_FAILURES = 3;

/**
 * The counter's key: the caller AND the integration it named.
 *
 * On the IP alone, an attacker POSTing to a guessed ?provider= spends the
 * budget the courier's own pushes need - and behind a proxy every push and
 * every attacker share one REMOTE_ADDR. On both, one integration's noise
 * cannot silence another's. The integration part is the resolved provider
 * code, never the string the caller sent: keyed on that, a prober would mint a
 * fresh counter (and a fresh log line) per request just by changing it.
 */
function shipping_webhook_key(string $ip, string $providerCode): string
{
    return $ip . '|' . ($providerCode === '' ? '-' : mb_substr($providerCode, 0, 40));
}

/**
 * Record one failed call and say where that leaves the caller.
 *
 * Database-backed (includes/rate-limit.php), not the file cache: the cache is
 * switched off with cache_enabled=0 - which would make this inert - wiped by
 * any admin save through cache_bust(), and never swept, so every source
 * address left a file behind that nothing deleted. rate_limits is atomic,
 * survives both and prunes itself.
 *
 * @return array{count:int, first:bool, quiet:bool, over:bool, tripped:bool}
 */
function shipping_webhook_note_failure(string $key): array
{
    $count = (int) ceil(rate_limit_count(SHIPPING_WEBHOOK_BUCKET, $key, SHIPPING_WEBHOOK_WINDOW, true));

    return [
        'count'   => $count,
        'first'   => $count <= 1,
        // Past this the refusal is counted but not written down.
        'quiet'   => $count > SHIPPING_WEBHOOK_LOG_FAILURES,
        'over'    => $count > SHIPPING_WEBHOOK_MAX_FAILURES,
        // The one call that crosses the line, so the throttle is recorded once.
        'tripped' => $count > SHIPPING_WEBHOOK_MAX_FAILURES && $count - 1 <= SHIPPING_WEBHOOK_MAX_FAILURES,
    ];
}

/** Is this caller over its failure budget? Records nothing. */
function shipping_webhook_throttled(string $key): bool
{
    return !rate_limit_allows(SHIPPING_WEBHOOK_BUCKET, $key, SHIPPING_WEBHOOK_MAX_FAILURES, SHIPPING_WEBHOOK_WINDOW);
}

// ===========================================================================
//  Logging
// ===========================================================================

/**
 * Largest body kept per log column. The server's max_allowed_packet is often
 * 1 MB, and a courier call that answered must never fail because its log row
 * was too big to insert; two bodies at this size stay far below it.
 */
const SHIPPING_LOG_MAX_BODY = 65536;

/**
 * Days of shipping_api_logs to keep.
 *
 * The webhook endpoint is unauthenticated, so anyone who finds it can put rows
 * in this table - the same reason notification_queue has
 * email_log_retention_days. A floor of a week, because a courier dispute is
 * argued from these rows and a mistyped 0 must not empty them.
 */
function shipping_log_retention_days(): int
{
    return max(7, setting_int('shipping_log_retention_days', 30));
}

/**
 * Delete log rows past their retention.
 *
 * Bounded and silent: housekeeping must never turn a courier's answer into a
 * failure, and a big unbounded DELETE would hold locks the call that triggered
 * it is waiting on. Called once per poller run, and - for an install with no
 * cron at all - on roughly one log write in 500.
 *
 * @return int rows deleted
 */
function shipping_prune_logs(int $limit = 1000): int
{
    try {
        return Database::query(
            'DELETE FROM `shipping_api_logs` WHERE `created_at` < DATE_SUB(NOW(), INTERVAL :days DAY)
              ORDER BY `id` ASC LIMIT ' . max(1, $limit),
            ['days' => shipping_log_retention_days()]
        )->rowCount();
    } catch (Throwable $e) {
        ErrorHandler::log('warning', 'Could not prune shipping_api_logs: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Record one API call.
 *
 * Bodies are masked here - shipping_mask() below - because a log that quietly
 * records an API key is a second place to leak it, and the one place nobody
 * thinks to check.
 *
 * Never throws. The log is a record OF the call, not part of it: a courier
 * that accepted a booking has accepted it whether or not we could write that
 * down, and an exception here would turn its answer into a failure and invite
 * a second booking.
 *
 * $entry['max_body'] caps each body lower still, for callers logging text a
 * stranger chose (a refused webhook).
 */
function shipping_log(string $providerCode, string $operation, array $entry): void
{
    try {
        shipping_remember_provider_secrets($providerCode);
        $limit = max(256, (int) ($entry['max_body'] ?? SHIPPING_LOG_MAX_BODY));

        Database::insert('shipping_api_logs', [
            'provider_code' => mb_substr($providerCode, 0, 40),
            'shipment_id'   => $entry['shipment_id'] ?? null,
            'operation'     => mb_substr($operation, 0, 40),
            'endpoint'      => isset($entry['endpoint']) ? mb_substr(shipping_utf8((string) $entry['endpoint']), 0, 255) : null,
            'http_status'   => $entry['http_status'] ?? null,
            'duration_ms'   => $entry['duration_ms'] ?? null,
            'ok'            => !empty($entry['ok']) ? 1 : 0,
            'request_body'  => isset($entry['request']) ? shipping_log_body($entry['request'], $limit) : null,
            'response_body' => isset($entry['response']) ? shipping_log_body($entry['response'], $limit) : null,
            'error'         => isset($entry['error'])
                ? mb_substr(shipping_mask(shipping_utf8((string) $entry['error'])), 0, 500)
                : null,
        ]);

        // An unauthenticated caller can write here (a refused webhook logs
        // one), so the table cannot be left to grow forever on an install
        // whose owner never set up the poller's cron. The same 1-in-N
        // housekeeping rate_limit_count() uses, and never inside a
        // transaction, where the DELETE would hold locks on the caller's path.
        if (!Database::inTransaction() && random_int(1, 500) === 1) {
            shipping_prune_logs(200);
        }
    } catch (Throwable $e) {
        // A deadlock has already rolled the caller's transaction back.
        // Swallowing that would let the caller carry on as if its earlier
        // writes had stood.
        if ($e instanceof PDOException && $e->getCode() === '40001' && Database::inTransaction()) {
            throw $e;
        }
        ErrorHandler::log('warning', 'Could not write shipping_api_logs (' . $providerCode . ' ' . $operation . '): '
            . shipping_mask(shipping_utf8($e->getMessage())));
    }
}

/** A body as stored: valid UTF-8, masked, then cut to size. */
function shipping_log_body($value, int $maxBytes): string
{
    // Masked BEFORE it is cut, so a secret straddling the cut is already gone
    // rather than half-kept.
    $text = shipping_mask(is_string($value) ? shipping_utf8($value) : $value);
    if (strlen($text) <= $maxBytes) {
        return $text;
    }
    return mb_strcut($text, 0, $maxBytes, 'UTF-8') . ' ...[' . strlen($text) . ' bytes, cut]';
}

/**
 * Text the utf8mb4 log columns will accept.
 *
 * A courier's (or a proxy's) error page in windows-1252 is ordinary; in strict
 * mode MySQL refuses the whole row over one smart quote.
 */
function shipping_utf8(string $text): string
{
    return mb_check_encoding($text, 'UTF-8') ? $text : mb_scrub($text, 'UTF-8');
}

/**
 * Take every secret out of a payload before it reaches the log.
 *
 * Three passes, because each alone leaks:
 *   1. structure - JSON (or an array) is decoded and every value under a
 *      secret-sounding key replaced, whatever characters the value holds. A
 *      regex over JSON text stops at the first comma, space or quote inside a
 *      password and leaves the rest - or all of it.
 *   2. text - for bodies that are not JSON: headers, form bodies, JSON cut
 *      short.
 *   3. values - every secret this request has read (credentials, webhook
 *      secret, the bearer token in use, plus $secrets) is replaced wherever it
 *      appears, raw, JSON-escaped, url-encoded or HTML-escaped. That catches a
 *      courier echoing a password back inside an error message.
 *
 * @param mixed    $payload string, array or object
 * @param string[] $secrets extra values to remove
 */
function shipping_mask($payload, array $secrets = []): string
{
    if (is_string($payload)) {
        // Bearer/basic first: the text rules below would take the scheme word
        // for the value and leave the token after it.
        $text = shipping_mask_bearer(shipping_utf8($payload));
        $data = json_decode($text, true);
        if (is_array($data)) {
            $changed = false;
            $masked  = shipping_mask_keys($data, $changed);
            // Re-encode only when something was replaced, so an innocent body
            // is stored byte for byte as it was sent.
            if ($changed) {
                $text = shipping_json($masked);
            }
        } else {
            $text = shipping_mask_text($text);
        }
    } else {
        $data    = json_decode(shipping_json($payload), true);
        $changed = false;
        $text    = shipping_mask_bearer(is_array($data) ? shipping_json(shipping_mask_keys($data, $changed)) : shipping_json($payload));
    }

    $forms = [];
    foreach (array_merge(shipping_secret_values(), array_map('strval', $secrets)) as $secret) {
        if (strlen($secret) < 4) {
            continue;
        }
        foreach ([
            $secret,
            substr((string) json_encode($secret, JSON_INVALID_UTF8_SUBSTITUTE), 1, -1),
            substr((string) json_encode($secret, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), 1, -1),
            urlencode($secret),
            rawurlencode($secret),
            htmlspecialchars($secret, ENT_QUOTES),
        ] as $form) {
            if ($form !== '') {
                $forms[$form] = strlen($form);
            }
        }
    }
    if ($forms === []) {
        return $text;
    }
    // Longest first, so an encoding that contains another is replaced whole.
    arsort($forms);
    return str_replace(array_map('strval', array_keys($forms)), '***', $text);
}

/** Replace every value under a secret-sounding key, at any depth. */
function shipping_mask_keys(array $data, bool &$changed): array
{
    foreach ($data as $key => $value) {
        if (is_string($key) && shipping_is_secret_key($key) && $value !== null && $value !== '' && !is_bool($value)) {
            $data[$key] = '***';
            $changed    = true;
        } elseif (is_array($value)) {
            $data[$key] = shipping_mask_keys($value, $changed);
        }
    }
    return $data;
}

/** A bearer or basic credential, inside any string - JSON included. */
function shipping_mask_bearer(string $text): string
{
    $masked = preg_replace('#\b(Bearer|Basic)\s+[A-Za-z0-9._~+/=-]{6,}#i', '$1 ***', $text);
    return is_string($masked) ? $masked : '[body not logged: it could not be masked, ' . strlen($text) . ' bytes]';
}

/** The fallback for text that is not JSON. */
function shipping_mask_text(string $text): string
{
    // Bounded, not *: an unbounded run before the keyword backtracks
    // quadratically over a megabyte of junk and preg_replace gives up.
    $keys = '[\w-]{0,30}(?:passw|passphrase|secret|token|api[_-]?key|apikey|authori[sz]ation|private[_-]?key)[\w-]{0,30}|auth|pass';

    $masked = preg_replace(
        [
            // "key": "value" in JSON that did not decode (cut short, say): the
            // whole quoted string, escaped quotes included.
            '~("(?:' . $keys . ')"\s*:\s*")(?:[^"\\\\]|\\\\.)*"?~i',
            // key=value in a query string or form body; "key: value" in headers.
            '~\b((?:' . $keys . ')\s*[=:]\s*)(?!\*\*\*)[^&\s,;"\'<>]+~i',
        ],
        ['$1***"', '$1***'],
        $text
    );

    // A regex that failed (backtrack limit) must not become "log it as is".
    return is_string($masked) ? $masked : '[body not logged: it could not be masked, ' . strlen($text) . ' bytes]';
}

/** JSON for the log: readable, and never false over one bad byte. */
function shipping_json($value): string
{
    return (string) json_encode(
        $value,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR
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

    // The token in use is a secret the log may meet again - a courier echoing
    // the request, say - so the value pass in shipping_mask() must know it.
    foreach ($headers as $name => $value) {
        if (shipping_is_secret_key((string) $name)) {
            shipping_remember_secret((string) preg_replace('~^(Bearer|Basic|Token)\s+~i', '', (string) $value));
        }
    }

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
        $headline = '';
        foreach (['message', 'error', 'msg', 'detail'] as $key) {
            if (!empty($body[$key]) && is_string($body[$key])) {
                $headline = trim($body[$key]);
                break;
            }
        }

        // Validation errors arrive as {"errors": {"field": ["..."]}} beside a
        // headline that says only "Oops! Invalid Data." - the field and its
        // reason are the part an admin can act on, so both are kept.
        $fields = shipping_error_fields($body['errors'] ?? null);
        if ($headline !== '' && $fields !== '' && stripos($fields, $headline) === false) {
            return mb_substr($headline . ' ' . $fields, 0, 400);
        }
        if ($fields !== '' || $headline !== '') {
            return mb_substr($fields !== '' ? $fields : $headline, 0, 400);
        }
    }
    return $status === 0 ? 'The courier could not be reached.' : 'The courier returned HTTP ' . $status . '.';
}

/** {"order_id": ["Order Id already exists"]} as "order_id: Order Id already exists". */
function shipping_error_fields($errors): string
{
    if (!is_array($errors)) {
        return is_string($errors) ? trim($errors) : '';
    }

    $parts = [];
    foreach ($errors as $field => $messages) {
        $list = [];
        $messages = is_array($messages) ? $messages : [$messages];
        array_walk_recursive($messages, static function ($message) use (&$list): void {
            if ((is_string($message) || is_numeric($message)) && trim((string) $message) !== '') {
                $list[] = trim((string) $message);
            }
        });
        if ($list !== []) {
            $parts[] = (is_string($field) ? $field . ': ' : '') . implode(', ', $list);
        }
    }
    return implode('; ', $parts);
}
