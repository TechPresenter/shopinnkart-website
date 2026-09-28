<?php
/**
 * ShopInnKart - the two server-side senders: Meta Conversions API and the
 * custom webhook.
 *
 * Until now both were storage only, and Settings > Integrations said so. This
 * is the part that makes them real. It is deliberately one file, because the
 * two channels share everything that is hard - the queue, the backoff, the
 * give-up, the injectable transport - and differ only in how a body is built
 * and how it is authenticated.
 *
 * ===========================================================================
 *  NOTHING SENDS DURING A REQUEST
 * ===========================================================================
 * Every public entry point here WRITES A ROW and returns. The delivery happens
 * in bin/send-integration-events.php, from cron. That is not tidiness: an
 * inline send hands a third party two powers it must not have over this store -
 * it can make a checkout slow by being slow, and it can lose a conversion by
 * being down at the one moment we would ever have tried. The shape is the mail
 * queue's shape (notification_queue + bin/send-queued-emails.php) so that an
 * operator who has debugged one has already debugged this.
 *
 * ===========================================================================
 *  DEDUPLICATION, AND WHY ONLY Purchase IS SENT
 * ===========================================================================
 * A Conversions API event next to a browser pixel is a double count unless both
 * carry the same `event_id`. So the id is DERIVED, not random:
 *
 *     integration_meta_event_id('Purchase', 'SCF-1AF05AE9')
 *         -> 'sik.purchase.SCF-1AF05AE9'
 *
 * Anyone can compute it - this file, a retry an hour later, or a browser
 * template - without coordinating, which is what makes the two sides agree.
 *
 * The pixel in includes/consent.php fires exactly one event today: PageView,
 * and it fires it WITHOUT an eventID. That has two consequences, and both are
 * enforced below rather than written in a comment and hoped for:
 *
 *   - We never send PageView from the server. There is no shared id, so Meta
 *     would count every page twice.
 *   - We DO send Purchase, ViewContent, AddToCart and InitiateCheckout only
 *     insofar as the pixel agrees. Since the pixel fires none of them, the only
 *     event with no double-count risk - and the only one the brief requires -
 *     is Purchase. INTEGRATION_PIXEL_EVENTS records what the pixel really
 *     fires, INTEGRATION_PIXEL_SHARED_ID_EVENTS records which of those emit our
 *     id, and integration_meta_blocked_events() refuses anything that would
 *     double count. If somebody teaches the pixel to fire Purchase without
 *     passing the id above, this file stops sending Purchase and the screen says
 *     why. The regression suite fails in that situation too, on purpose.
 *
 * ===========================================================================
 *  CONSENT
 * ===========================================================================
 * The pixel is gated by includes/consent.php. A server-side send that ignored
 * that gate would be doing the refused thing from a different machine, so the
 * gate is honoured here twice: the decision in force is READ when the event is
 * queued (the only moment the visitor is present) and RECORDED on the row, and
 * it is CHECKED again before the row is sent. A row whose recorded decision is
 * anything but `granted` is marked `skipped` and never leaves the building.
 *
 * The limit of that, stated plainly because the screen states it too: the
 * decision honoured is the decision that was in force when the event was
 * queued. Nothing here can link a visitor who later withdraws consent back to a
 * queued row, because the store deliberately stores no visitor identifier that
 * survives a session. A withdrawal is honoured for every event after it.
 *
 * The custom webhook is a different case and is NOT consent-gated: it posts to
 * an endpoint the owner controls, which is a processor of the store's own order
 * data, not a third-party tag on a customer's page. Its rows record
 * `not_applicable` so that the difference is visible rather than implied.
 *
 * ===========================================================================
 *  WHAT IS SENT, AND WHAT IS NOT
 * ===========================================================================
 * Meta receives SHA-256 hashes of email, phone, first name, last name, city and
 * country, normalised per Meta's rules (trimmed, lowercased, punctuation
 * stripped) before hashing. It does not receive a readable email or phone, and
 * it does not receive the customer's IP address or browser string either -
 * `orders` holds both, Meta would happily use both for matching, and quietly
 * starting to share them is not something a sender should decide on an owner's
 * behalf. If that is ever wanted it should be a switch somebody turned on.
 *
 * No readable email is written to the queue by the Meta path, and none is
 * written to any log by either path: integration_safe_text() masks addresses and
 * redacts the access token and signing secret before anything is stored.
 *
 * ===========================================================================
 *  WEBHOOK AUTHENTICATION
 * ===========================================================================
 * Every request carries:
 *
 *     X-ShopInnKart-Event:      order.paid
 *     X-ShopInnKart-Delivery:   sik.order.paid.SCF-1AF05AE9
 *     X-ShopInnKart-Timestamp:  1759050000
 *     X-ShopInnKart-Signature:  sha256=<hex>
 *
 * where the signature is HMAC-SHA256, keyed with the stored signing secret,
 * over the exact bytes `"<timestamp>.<raw body>"`. The timestamp is INSIDE the
 * signed material, so a captured request cannot be replayed later with a fresh
 * timestamp - the receiver rejects anything outside its own tolerance and the
 * signature cannot be recomputed without the secret. That is the same scheme
 * this project already verifies for Cashfree in includes/webhook-functions.php,
 * and the exact recipe is printed on the Integrations screen, because a webhook
 * nobody can verify is just an unauthenticated POST.
 *
 * ===========================================================================
 *  TESTING
 * ===========================================================================
 * The HTTP call sits behind a transport that integration_set_transport()
 * replaces, so a suite asserts on what WOULD have been sent - the hashing, the
 * event_id, the signature, the retry schedule, the give-up and the consent
 * refusal - without a network and without ever touching Meta.
 */

declare(strict_types=1);

if (!defined('SIK_BOOTSTRAPPED') && !defined('SIK_LEAN_BOOTSTRAP')) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/integrations.php';

// Country names in `orders` are names ("India"), and Meta wants a two-letter
// ISO code. phone_countries() is the only name/ISO table this project has.
if (!function_exists('phone_countries')) {
    require_once __DIR__ . '/countries.php';
}

// ===========================================================================
//  CONSTANTS
// ===========================================================================

/** Graph API version. Pinned: an unpinned version changes behaviour silently. */
const INTEGRATION_META_VERSION = 'v21.0';

/** Header names. Printed verbatim on the admin screen - keep them in step. */
const INTEGRATION_HEADER_EVENT     = 'X-ShopInnKart-Event';
const INTEGRATION_HEADER_DELIVERY  = 'X-ShopInnKart-Delivery';
const INTEGRATION_HEADER_TIMESTAMP = 'X-ShopInnKart-Timestamp';
const INTEGRATION_HEADER_SIGNATURE = 'X-ShopInnKart-Signature';

/**
 * What the browser pixel actually fires today, from includes/consent.php.
 *
 * Not a guess and not documentation: integration_meta_blocked_events() reads it
 * to decide what the server may send, and the regression suite reads the pixel
 * source and fails if this list has drifted from it.
 */
const INTEGRATION_PIXEL_EVENTS = ['PageView'];

/**
 * Which of those the pixel emits with OUR shared event_id.
 *
 * Empty, because the pixel passes no eventID at all. An event that is in
 * INTEGRATION_PIXEL_EVENTS and not in here can never be sent from the server
 * without double counting.
 */
const INTEGRATION_PIXEL_SHARED_ID_EVENTS = [];

/** The events a Conversions API sender could plausibly send for this store. */
const INTEGRATION_META_CANDIDATES = ['Purchase', 'ViewContent', 'AddToCart', 'InitiateCheckout'];

/**
 * The webhook events the owner can choose from, and how each one is detected.
 *
 * `when` is the SQL-free predicate the harvester applies to an order row, and
 * it is what makes the choice honest: an event in this list is one the store can
 * really tell has happened.
 */
const INTEGRATION_WEBHOOK_EVENTS = [
    'order.placed'    => 'A new order was created.',
    'order.paid'      => 'Payment for an order succeeded.',
    'order.shipped'   => 'An order was handed to a courier.',
    'order.delivered' => 'An order was delivered.',
    'order.cancelled' => 'An order was cancelled.',
    'order.refunded'  => 'An order was refunded, fully or partly.',
];

/**
 * Seconds to wait after attempt 1, 2, 3 and 4 fail.
 *
 * One minute, five, twenty-five, two hours: about two and a half hours of
 * trying, which covers a restart and a short outage without hammering an
 * endpoint that is simply gone. The fifth failure gives up.
 */
const INTEGRATION_BACKOFF = [60, 300, 1500, 7200];

/** Attempts before a row is marked failed. len(INTEGRATION_BACKOFF) + 1. */
const INTEGRATION_MAX_ATTEMPTS = 5;

/** Seconds per HTTP attempt. Short: the queue, not the socket, does the waiting. */
const INTEGRATION_TIMEOUT = 15;

/**
 * How far back the harvester will look, ever.
 *
 * A bulk admin UPDATE that touches `updated_at` on two years of orders must not
 * turn into two years of webhooks. Anything older than this is never queued,
 * whatever the watermark says.
 */
const INTEGRATION_HARVEST_MAX_AGE_DAYS = 30;

// ===========================================================================
//  INSTALLATION STATE
// ===========================================================================

/**
 * Has the migration run? Read once per request.
 *
 * Every public function here answers safely when it has not, so a screen on a
 * store that has not migrated shows "not installed" instead of a PDO exception.
 */
function integration_queue_installed(): bool
{
    static $installed = null;

    if ($installed !== null) {
        return $installed;
    }

    try {
        $installed = (int) Database::fetchColumn(
            'SELECT COUNT(*) FROM `information_schema`.`TABLES`
             WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` IN (\'integration_queue\', \'integration_state\')'
        ) === 2;
    } catch (Throwable $e) {
        $installed = false;
    }

    return $installed;
}

// ===========================================================================
//  CONFIGURATION
// ===========================================================================

/**
 * Everything both senders need, resolved once: switches, ids, and the two
 * secrets decrypted.
 *
 * Not memoised. A test that changes a setting and asks again must get the new
 * answer, and this is three settings reads against an already-cached table.
 *
 * @return array{
 *   meta:array{enabled:bool,dataset:string,token:string,test_code:string,configured:bool,secret:array},
 *   webhook:array{enabled:bool,url:string,secret:string,events:string[],configured:bool,secret_state:array}
 * }
 */
function integration_sender_config(): array
{
    $values = integration_values();

    $metaToken = secret_decrypt($values['meta_capi_token']);
    // Blank means the pixel id, which is what the field's help line promises.
    $dataset   = $values['meta_capi_dataset_id'] !== ''
        ? $values['meta_capi_dataset_id']
        : $values['meta_pixel_id'];

    $hookSecret = secret_decrypt($values['integration_webhook_secret']);

    return [
        'meta' => [
            'enabled'      => setting_bool('meta_capi_enabled', false),
            'dataset'      => $dataset,
            'token'        => $metaToken,
            'test_code'    => $values['meta_capi_test_code'],
            'configured'   => $dataset !== '' && $metaToken !== '',
            'secret_state' => integration_secret_state($values['meta_capi_token']),
        ],
        'webhook' => [
            'enabled'      => setting_bool('integration_webhook_enabled', false),
            'url'          => $values['integration_webhook_url'],
            'secret'       => $hookSecret,
            'events'       => integration_webhook_selected_events(),
            'configured'   => $values['integration_webhook_url'] !== '' && $hookSecret !== '',
            'secret_state' => integration_secret_state($values['integration_webhook_secret']),
        ],
    ];
}

/** The events the owner has ticked, filtered to the ones that really exist. */
function integration_webhook_selected_events(): array
{
    $raw = (string) setting('integration_webhook_events', '');
    $out = [];

    foreach (explode(',', $raw) as $event) {
        $event = trim($event);
        if ($event !== '' && isset(INTEGRATION_WEBHOOK_EVENTS[$event]) && !in_array($event, $out, true)) {
            $out[] = $event;
        }
    }

    return $out;
}

/**
 * The Meta events this store may send, and the reason for each one it may not.
 *
 * The rule, in one place: an event is sendable when the pixel does not fire it
 * (so there is nothing to collide with) or fires it carrying our shared id (so
 * Meta can collapse the pair). Anything else would be counted twice.
 *
 * @return array{send:string[], blocked:array<string,string>}
 */
function integration_meta_event_plan(): array
{
    $send    = [];
    $blocked = [];

    foreach (INTEGRATION_META_CANDIDATES as $event) {
        $firedByPixel = in_array($event, INTEGRATION_PIXEL_EVENTS, true);
        $sharesId     = in_array($event, INTEGRATION_PIXEL_SHARED_ID_EVENTS, true);

        if ($firedByPixel && !$sharesId) {
            $blocked[$event] = 'The pixel fires it without a shared event ID, so Meta would count it twice.';
            continue;
        }

        // Purchase is the brief's minimum and the only one this store can
        // observe server-side; the other three happen in a browser the server
        // never hears from, and the pixel does not fire them either, so there
        // is nothing to agree with.
        if ($event !== 'Purchase' && !$firedByPixel) {
            $blocked[$event] = 'The pixel does not fire it, so there is nothing to match server-side.';
            continue;
        }

        $send[] = $event;
    }

    return ['send' => $send, 'blocked' => $blocked];
}

/** Shorthand: may this Meta event type be sent at all? */
function integration_meta_may_send(string $event): bool
{
    return in_array($event, integration_meta_event_plan()['send'], true);
}

// ===========================================================================
//  THE SHARED EVENT ID
// ===========================================================================

/**
 * The deduplication id for one Meta event.
 *
 * Deterministic and readable on purpose. Deterministic so that this file, a
 * retry, and a browser template all produce the same string with no shared
 * state; readable so that an id in Meta's Events Manager points at an order
 * somebody can open. The reference is already sent to Meta as `order_id` in
 * custom_data, so nothing new is disclosed by putting it here.
 *
 * To make the pixel agree, a template emits exactly:
 *
 *     fbq('track', 'Purchase', { ... }, { eventID: 'sik.purchase.SCF-1AF05AE9' });
 */
function integration_meta_event_id(string $event, string $reference): string
{
    $event     = strtolower(trim($event));
    $event     = (string) preg_replace('/[^a-z0-9]+/', '', $event);
    $reference = (string) preg_replace('/[^A-Za-z0-9._-]+/', '-', trim($reference));
    $reference = trim($reference, '-');

    if ($event === '' || $reference === '') {
        return '';
    }

    return 'sik.' . $event . '.' . $reference;
}

/** The same shape for a webhook delivery: sik.order.paid.SCF-1AF05AE9 */
function integration_webhook_event_id(string $event, string $reference): string
{
    $event     = strtolower(trim($event));
    $event     = (string) preg_replace('/[^a-z0-9.]+/', '', $event);
    $reference = (string) preg_replace('/[^A-Za-z0-9._-]+/', '-', trim($reference));
    $reference = trim($reference, '-');

    if ($event === '' || $reference === '') {
        return '';
    }

    return 'sik.' . $event . '.' . $reference;
}

// ===========================================================================
//  META NORMALISATION AND HASHING
// ===========================================================================

/**
 * Normalise one customer field the way Meta specifies, then SHA-256 it.
 *
 * Returns '' when there is nothing usable, and '' means the field is OMITTED
 * rather than sent as the hash of an empty or junk string - a hash Meta can
 * never match is noise in a match-quality score.
 *
 * $field is Meta's own key: em, ph, fn, ln, ct, country.
 */
function integration_meta_hash(string $field, string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    switch ($field) {
        case 'em':
            $value = mb_strtolower($value);
            if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                return '';
            }
            break;

        case 'ph':
            $value = integration_meta_phone($value);
            break;

        case 'fn':
        case 'ln':
        case 'ct':
            // Lowercase, and strip everything that is not a letter or a digit:
            // Meta's rule for names and cities is no punctuation and no spaces.
            $value = mb_strtolower($value);
            $value = (string) preg_replace('/[^\p{L}\p{N}]+/u', '', $value);
            break;

        case 'country':
            $value = strtolower($value);
            if (preg_match('/^[a-z]{2}$/', $value) !== 1) {
                return '';
            }
            break;

        default:
            return '';
    }

    return $value === '' ? '' : hash('sha256', $value);
}

/**
 * Digits only, with a country code, the way Meta wants a phone number.
 *
 * Returns '' when the number cannot be made into something Meta could match,
 * because an unmatched hash is worse than an absent field.
 */
function integration_meta_phone(string $value, string $countryIso = ''): string
{
    $digits = (string) preg_replace('/\D+/', '', $value);
    $digits = ltrim($digits, '0');

    if (strlen($digits) < 8) {
        return '';
    }

    $countries = phone_countries();
    $dial      = (string) ($countries[strtoupper($countryIso)]['dial'] ?? '');
    $max       = (int) ($countries[strtoupper($countryIso)]['max'] ?? 0);

    // Already carries a country code when it is longer than the national
    // number can be; otherwise prefix the order's own country's dial code.
    if ($dial !== '' && $max > 0 && strlen($digits) <= $max) {
        $digits = $dial . $digits;
    }

    return strlen($digits) >= 8 && strlen($digits) <= 15 ? $digits : '';
}

/**
 * A country NAME as `orders` stores it, turned into an ISO 3166-1 alpha-2 code.
 *
 * '' when it cannot be mapped, which omits the field rather than hashing
 * "India" - a string Meta has no idea what to do with.
 */
function integration_country_iso(string $country): string
{
    $country = trim($country);
    if ($country === '') {
        return '';
    }

    if (preg_match('/^[A-Za-z]{2}$/', $country) === 1) {
        return strtoupper($country);
    }

    static $byName = null;
    if ($byName === null) {
        $byName = [];
        foreach (phone_countries() as $iso => $row) {
            $byName[mb_strtolower((string) $row['name'])] = $iso;
        }
    }

    return $byName[mb_strtolower($country)] ?? '';
}

// ===========================================================================
//  PAYLOADS
// ===========================================================================

/** One order, with its items, or null. Read-only. */
function integration_load_order(int $orderId): ?array
{
    $order = Database::fetch('SELECT * FROM `orders` WHERE `id` = :id', ['id' => $orderId]);
    if ($order === null) {
        return null;
    }

    $order['items'] = Database::fetchAll(
        'SELECT `product_id`, `variant_id`, `product_name`, `product_sku`, `variant_name`,
                `price`, `quantity`, `total`
         FROM `order_items` WHERE `order_id` = :id ORDER BY `id`',
        ['id' => $orderId]
    );

    return $order;
}

/** The store's ISO currency code, which Meta requires and the webhook states. */
function integration_currency(): string
{
    $code = strtoupper(trim((string) setting('currency_code', 'INR')));

    return preg_match('/^[A-Z]{3}$/', $code) === 1 ? $code : 'INR';
}

/**
 * The Meta user_data block: hashes only, and only the fields we really have.
 *
 * No client_ip_address and no client_user_agent - see the file docblock.
 *
 * @return array<string, array<int,string>>
 */
function integration_meta_user_data(array $order): array
{
    $name  = trim((string) ($order['customer_name'] ?? ''));
    $parts = preg_split('/\s+/', $name, 2) ?: [];
    $first = (string) ($parts[0] ?? '');
    $last  = (string) ($parts[1] ?? '');

    $iso = integration_country_iso((string) ($order['shipping_country'] ?? ''));

    $fields = [
        'em'      => integration_meta_hash('em', (string) ($order['customer_email'] ?? '')),
        'ph'      => integration_meta_hash_phone((string) ($order['customer_phone'] ?? ''), $iso),
        'fn'      => integration_meta_hash('fn', $first),
        'ln'      => integration_meta_hash('ln', $last),
        'ct'      => integration_meta_hash('ct', (string) ($order['shipping_city'] ?? '')),
        'country' => integration_meta_hash('country', $iso),
    ];

    $out = [];
    foreach ($fields as $key => $hash) {
        if ($hash !== '') {
            // Meta takes each hashed field as an array.
            $out[$key] = [$hash];
        }
    }

    return $out;
}

/**
 * Hash a phone using the order's own country for the dial code.
 *
 * A separate helper only because integration_meta_hash() takes one string and
 * the phone rule needs two. An unknown country means no dial code is added
 * rather than India's being assumed for somebody in Berlin.
 */
function integration_meta_hash_phone(string $phone, string $countryIso = ''): string
{
    $digits = integration_meta_phone($phone, $countryIso);

    return $digits === '' ? '' : hash('sha256', $digits);
}

/**
 * The Meta Conversions API body for one order event, WITHOUT the access token.
 *
 * The token is added at send time and never written to the queue, so a dump of
 * `integration_queue` carries no credential and no readable customer data.
 */
function integration_meta_payload(string $event, array $order, string $eventId): array
{
    $config  = integration_sender_config();
    $items   = (array) ($order['items'] ?? []);
    $numItems = 0;
    $contents = [];

    foreach ($items as $item) {
        $quantity   = max(1, (int) ($item['quantity'] ?? 1));
        $numItems  += $quantity;
        $contents[] = [
            'id'         => (string) ($item['product_sku'] ?? '') !== ''
                ? (string) $item['product_sku']
                : (string) (int) ($item['product_id'] ?? 0),
            'quantity'   => $quantity,
            'item_price' => round((float) ($item['price'] ?? 0), 2),
        ];
    }

    $eventTime = strtotime((string) ($order['created_at'] ?? 'now')) ?: time();

    $payload = [
        'data' => [[
            'event_name'       => $event,
            'event_time'       => $eventTime,
            'event_id'         => $eventId,
            'action_source'    => 'website',
            'event_source_url' => url('order-success.php'),
            'user_data'        => integration_meta_user_data($order),
            'custom_data'      => [
                'currency'     => integration_currency(),
                'value'        => round((float) ($order['total_amount'] ?? 0), 2),
                'order_id'     => (string) ($order['order_number'] ?? ''),
                'num_items'    => $numItems,
                'content_type' => 'product',
                'contents'     => $contents,
            ],
        ]],
    ];

    if ($config['meta']['test_code'] !== '') {
        // Routes the event away from real ad reporting. Required for the test
        // button; harmless and deliberate when an owner leaves it set.
        $payload['test_event_code'] = $config['meta']['test_code'];
    }

    return $payload;
}

/**
 * The webhook body for one order event.
 *
 * This DOES contain the order's customer name, email, phone and address,
 * because a webhook to an endpoint the owner controls exists to carry exactly
 * that. They are the same fields already in `orders`; the queue's retention
 * setting is what stops the second copy outliving the first.
 */
function integration_webhook_payload(string $event, array $order, string $eventId): array
{
    $items = [];
    foreach ((array) ($order['items'] ?? []) as $item) {
        $items[] = [
            'sku'      => (string) ($item['product_sku'] ?? ''),
            'name'     => (string) ($item['product_name'] ?? ''),
            'variant'  => (string) ($item['variant_name'] ?? ''),
            'quantity' => (int) ($item['quantity'] ?? 0),
            'price'    => round((float) ($item['price'] ?? 0), 2),
            'total'    => round((float) ($item['total'] ?? 0), 2),
        ];
    }

    return [
        'id'         => $eventId,
        'event'      => $event,
        'created_at' => date('c'),
        'test'       => false,
        'store'      => [
            'name' => (string) setting('store_name', SITE_NAME),
            'url'  => url(''),
        ],
        'data' => [
            'order' => [
                'id'             => (int) ($order['id'] ?? 0),
                'number'         => (string) ($order['order_number'] ?? ''),
                'status'         => (string) ($order['status'] ?? ''),
                'payment_status' => (string) ($order['payment_status'] ?? ''),
                'payment_method' => (string) ($order['payment_method'] ?? ''),
                'currency'       => integration_currency(),
                'subtotal'       => round((float) ($order['subtotal'] ?? 0), 2),
                'discount'       => round((float) ($order['discount_amount'] ?? 0), 2),
                'shipping'       => round((float) ($order['shipping_amount'] ?? 0), 2),
                'tax'            => round((float) ($order['tax_amount'] ?? 0), 2),
                'total'          => round((float) ($order['total_amount'] ?? 0), 2),
                'placed_at'      => (string) ($order['created_at'] ?? ''),
            ],
            'customer' => [
                'name'  => (string) ($order['customer_name'] ?? ''),
                'email' => (string) ($order['customer_email'] ?? ''),
                'phone' => (string) ($order['customer_phone'] ?? ''),
            ],
            'shipping_address' => [
                'name'     => (string) ($order['shipping_name'] ?? ''),
                'line1'    => (string) ($order['shipping_address'] ?? ''),
                'line2'    => (string) ($order['shipping_address2'] ?? ''),
                'city'     => (string) ($order['shipping_city'] ?? ''),
                'state'    => (string) ($order['shipping_state'] ?? ''),
                'pincode'  => (string) ($order['shipping_pincode'] ?? ''),
                'country'  => (string) ($order['shipping_country'] ?? ''),
            ],
            'items' => $items,
        ],
    ];
}

/** The exact bytes that get signed and sent. One encoder, one place. */
function integration_encode(array $payload): string
{
    return (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

// ===========================================================================
//  WEBHOOK SIGNATURE
// ===========================================================================

/**
 * HMAC-SHA256 over "<timestamp>.<raw body>", keyed with the signing secret.
 *
 * The timestamp is part of the signed material, which is what makes a captured
 * request unreplayable: the receiver rejects a stale timestamp, and a fresh one
 * cannot be signed without the secret.
 */
function integration_webhook_signature(string $secret, int $timestamp, string $body): string
{
    return 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
}

/**
 * The headers one webhook delivery carries.
 *
 * @return array<string,string>
 */
function integration_webhook_headers(string $secret, string $event, string $eventId, int $timestamp, string $body): array
{
    return [
        'Content-Type'                 => 'application/json',
        'Accept'                       => 'application/json',
        'User-Agent'                   => 'ShopInnKart/1.0 (+webhook)',
        INTEGRATION_HEADER_EVENT       => $event,
        INTEGRATION_HEADER_DELIVERY    => $eventId,
        INTEGRATION_HEADER_TIMESTAMP   => (string) $timestamp,
        INTEGRATION_HEADER_SIGNATURE   => integration_webhook_signature($secret, $timestamp, $body),
    ];
}

// ===========================================================================
//  THE QUEUE
// ===========================================================================

/**
 * Write one event.
 *
 * The UNIQUE key on (channel, event_id) does the deduplication, so a caller
 * that queues the same event twice gets `duplicate` rather than an exception and
 * the endpoint hears about it once.
 *
 * @return array{ok:bool, id:int, state:string, reason:string}
 */
function integration_enqueue(array $row): array
{
    if (!integration_queue_installed()) {
        return ['ok' => false, 'id' => 0, 'state' => 'not-installed',
                'reason' => 'The integration queue tables are missing. Run the 2026_09_28 migration.'];
    }

    $eventId = (string) ($row['event_id'] ?? '');
    $channel = (string) ($row['channel'] ?? '');

    if ($eventId === '' || !in_array($channel, ['meta_capi', 'webhook'], true)) {
        return ['ok' => false, 'id' => 0, 'state' => 'invalid', 'reason' => 'A queued event needs a channel and an id.'];
    }

    $consent = (string) ($row['consent'] ?? 'unknown');
    // The gate, applied at the only moment it can be: a refused or unknown
    // decision on a channel that needs one is recorded and never sent.
    $status = (string) ($row['status'] ?? 'pending');
    [$permitted, $why] = integration_row_may_send($row + ['channel' => $channel, 'consent' => $consent]);
    if ($status === 'pending' && !$permitted) {
        $status       = 'skipped';
        $row['error'] = $why;
    }

    $data = [
        'channel'         => $channel,
        'event'           => (string) ($row['event'] ?? ''),
        'event_id'        => $eventId,
        'order_id'        => isset($row['order_id']) && (int) $row['order_id'] > 0 ? (int) $row['order_id'] : null,
        'payload'         => (string) ($row['payload'] ?? '{}'),
        'consent'         => $consent,
        'status'          => $status,
        'attempts'        => 0,
        'max_attempts'    => INTEGRATION_MAX_ATTEMPTS,
        'next_attempt_at' => date('Y-m-d H:i:s'),
        'is_test'         => !empty($row['is_test']) ? 1 : 0,
        'error'           => isset($row['error']) ? mb_substr((string) $row['error'], 0, 500) : null,
    ];

    try {
        $id = Database::insert('integration_queue', $data);
    } catch (Throwable $e) {
        // 23000 is the duplicate key, i.e. this event is already queued. That
        // is the guard working, not a failure.
        if (Database::exists('integration_queue', '`channel` = :c AND `event_id` = :e',
                ['c' => $channel, 'e' => $eventId])) {
            return ['ok' => true, 'id' => 0, 'state' => 'duplicate', 'reason' => 'Already queued.'];
        }
        return ['ok' => false, 'id' => 0, 'state' => 'error', 'reason' => $e->getMessage()];
    }

    return ['ok' => true, 'id' => $id, 'state' => $status, 'reason' => (string) ($data['error'] ?? '')];
}

/** Only the Meta channel answers to the visitor's advertising decision. */
function integration_channel_needs_consent(string $channel): bool
{
    return $channel === 'meta_capi';
}

/**
 * May this row leave the building?
 *
 * One predicate, used both when the row is written and again before it is sent,
 * so the two can never disagree. Three rules and no fourth:
 *
 *   - the webhook is not consent-gated (the endpoint is the owner's own);
 *   - a Meta event needs a RECORDED `granted`; refused and unknown are both no;
 *   - a Meta event may also be `not_applicable`, but ONLY when it is the test
 *     row the owner queued by pressing a button. That row contains an invented
 *     customer, so there is no visitor whose decision could be overridden - and
 *     tying the exemption to `is_test` is what stops it from becoming a way to
 *     send a real order with no decision behind it.
 *
 * @return array{0:bool, 1:string} permitted, and why not
 */
function integration_row_may_send(array $row): array
{
    if (!integration_channel_needs_consent((string) ($row['channel'] ?? ''))) {
        return [true, ''];
    }

    $consent = (string) ($row['consent'] ?? 'unknown');

    if ($consent === 'granted') {
        return [true, ''];
    }
    if ($consent === 'not_applicable' && !empty($row['is_test'])) {
        return [true, ''];
    }
    if ($consent === 'refused') {
        return [false, 'Not sent: the visitor refused advertising consent.'];
    }

    return [false, 'Not sent: no advertising consent decision was recorded (' . $consent . ').'];
}

/**
 * The marketing decision in force for THIS request.
 *
 * 'granted' / 'refused' while a visitor is present; 'unknown' from CLI, where
 * there is no visitor and therefore no decision to honour - which is exactly
 * why the cron harvester never queues a Meta event.
 */
function integration_consent_decision(): string
{
    if (PHP_SAPI === 'cli') {
        return 'unknown';
    }

    return consent_allows('marketing') ? 'granted' : 'refused';
}

/**
 * Queue the Meta Purchase event for one order. THE checkout hook.
 *
 * Call it from the page that confirms an order to its buyer - that is the one
 * place where the order exists AND the visitor's consent cookie is readable:
 *
 *     require_once INCLUDES_PATH . '/integration-senders.php';
 *     integration_queue_purchase((int) $order['id']);
 *
 * It writes one row and returns; it makes no HTTP call, so it cannot slow the
 * page down or fail it. Calling it twice for the same order is a no-op.
 *
 * @return array{ok:bool, id:int, state:string, reason:string}
 */
function integration_queue_purchase(int $orderId): array
{
    $config = integration_sender_config();

    if (!$config['meta']['enabled']) {
        return ['ok' => false, 'id' => 0, 'state' => 'off', 'reason' => 'The Conversions API sender is switched off.'];
    }
    if (!$config['meta']['configured']) {
        return ['ok' => false, 'id' => 0, 'state' => 'unconfigured',
                'reason' => 'The Conversions API needs a dataset id and an access token.'];
    }
    if (!integration_meta_may_send('Purchase')) {
        return ['ok' => false, 'id' => 0, 'state' => 'blocked',
                'reason' => integration_meta_event_plan()['blocked']['Purchase'] ?? 'Purchase is not sendable.'];
    }

    $order = integration_load_order($orderId);
    if ($order === null) {
        return ['ok' => false, 'id' => 0, 'state' => 'no-order', 'reason' => 'Order ' . $orderId . ' does not exist.'];
    }

    $eventId = integration_meta_event_id('Purchase', (string) $order['order_number']);

    return integration_enqueue([
        'channel'  => 'meta_capi',
        'event'    => 'Purchase',
        'event_id' => $eventId,
        'order_id' => $orderId,
        'payload'  => integration_encode(integration_meta_payload('Purchase', $order, $eventId)),
        'consent'  => integration_consent_decision(),
    ]);
}

/**
 * Queue a clearly-marked test event.
 *
 * It queues; it does not send. The worker sends it on its next run, or the
 * operator presses "Run the sender now" - which keeps a page load from ever
 * being the thing that waits on somebody else's server.
 *
 * The test payload contains no real customer. A CAPI test is refused outright
 * when no test event code is set, because without one the event lands in the
 * owner's real ad reporting and cannot be taken back out.
 *
 * @return array{ok:bool, id:int, state:string, reason:string}
 */
function integration_queue_test(string $channel): array
{
    $config = integration_sender_config();

    if ($channel === 'meta_capi') {
        if (!$config['meta']['configured']) {
            return ['ok' => false, 'id' => 0, 'state' => 'unconfigured',
                    'reason' => 'Save a dataset id and an access token first.'];
        }
        // "Off means nothing is queued and nothing is sent" is what the field's
        // help line promises, and a test event is not an exception to it. The
        // alternative - queueing a test the worker then marks skipped - is a
        // button that appears to work and does nothing.
        if (!$config['meta']['enabled']) {
            return ['ok' => false, 'id' => 0, 'state' => 'off',
                    'reason' => 'Switch the sender on first, or nothing will be sent.'];
        }
        if ($config['meta']['test_code'] === '') {
            return ['ok' => false, 'id' => 0, 'state' => 'no-test-code',
                    'reason' => 'Set a test event code first, or the test event lands in your real ad reporting.'];
        }

        $reference = 'test-' . bin2hex(random_bytes(4));
        $eventId   = integration_meta_event_id('Purchase', $reference);
        $sample    = integration_test_order($reference);

        return integration_enqueue([
            'channel'  => 'meta_capi',
            'event'    => 'Purchase',
            'event_id' => $eventId,
            'payload'  => integration_encode(integration_meta_payload('Purchase', $sample, $eventId)),
            // The owner pressed the button; no visitor's decision is involved
            // and no visitor's data is in the payload.
            'consent'  => 'not_applicable',
            'is_test'  => true,
        ]);
    }

    if ($channel === 'webhook') {
        if (!$config['webhook']['configured']) {
            return ['ok' => false, 'id' => 0, 'state' => 'unconfigured',
                    'reason' => 'Save an endpoint URL and a signing secret first.'];
        }
        if (!$config['webhook']['enabled']) {
            return ['ok' => false, 'id' => 0, 'state' => 'off',
                    'reason' => 'Switch the sender on first, or nothing will be sent.'];
        }

        $reference = 'test-' . bin2hex(random_bytes(4));
        $eventId   = integration_webhook_event_id('test.ping', $reference);
        $payload   = integration_webhook_payload('test.ping', integration_test_order($reference), $eventId);
        $payload['test'] = true;

        return integration_enqueue([
            'channel'  => 'webhook',
            'event'    => 'test.ping',
            'event_id' => $eventId,
            'payload'  => integration_encode($payload),
            'consent'  => 'not_applicable',
            'is_test'  => true,
        ]);
    }

    return ['ok' => false, 'id' => 0, 'state' => 'invalid', 'reason' => 'Unknown channel.'];
}

/**
 * An obviously fake order for a test event.
 *
 * Invented rather than borrowed from the table on purpose: a test must never
 * post a real customer's name and address to an endpoint that is being set up,
 * and must never put a real purchase into Meta's reporting.
 */
function integration_test_order(string $reference): array
{
    return [
        'id'               => 0,
        'order_number'     => strtoupper($reference),
        'customer_name'    => 'Test Customer',
        'customer_email'   => 'test@example.com',
        'customer_phone'   => '9000000000',
        'shipping_name'    => 'Test Customer',
        'shipping_address' => '1 Test Street',
        'shipping_city'    => 'Testville',
        'shipping_state'   => 'Test State',
        'shipping_pincode' => '000000',
        'shipping_country' => 'India',
        'status'           => 'confirmed',
        'payment_status'   => 'paid',
        'payment_method'   => 'test',
        'subtotal'         => 100.00,
        'discount_amount'  => 0.00,
        'shipping_amount'  => 0.00,
        'tax_amount'       => 0.00,
        'total_amount'     => 100.00,
        'created_at'       => date('Y-m-d H:i:s'),
        'items'            => [[
            'product_id'   => 0,
            'product_sku'  => 'TEST-SKU',
            'product_name' => 'Test product',
            'variant_name' => '',
            'price'        => 100.00,
            'quantity'     => 1,
            'total'        => 100.00,
        ]],
    ];
}

// ===========================================================================
//  THE HARVESTER - where webhook events come from
// ===========================================================================

/** Read one watermark. null when it has never been set. */
function integration_state_get(string $name): ?string
{
    if (!integration_queue_installed()) {
        return null;
    }

    $value = Database::fetchColumn(
        'SELECT `value` FROM `integration_state` WHERE `name` = :n',
        ['n' => $name]
    );

    return $value === null || $value === false ? null : (string) $value;
}

/** Write one watermark. */
function integration_state_set(string $name, string $value): void
{
    if (!integration_queue_installed()) {
        return;
    }

    Database::query(
        'INSERT INTO `integration_state` (`name`, `value`) VALUES (:n, :v)
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)',
        ['n' => $name, 'v' => mb_substr($value, 0, 190)]
    );
}

/**
 * Which of the chosen webhook events this order row has reached.
 *
 * Pure: it reads an order and returns event names. That is what makes the
 * harvester testable without a clock.
 *
 * @return string[]
 */
function integration_order_events(array $order, array $wanted): array
{
    $paymentStatus = (string) ($order['payment_status'] ?? '');

    $reached = [
        'order.placed'    => true,
        'order.paid'      => $paymentStatus === 'paid',
        'order.shipped'   => !empty($order['shipped_at']),
        'order.delivered' => !empty($order['delivered_at']),
        'order.cancelled' => !empty($order['cancelled_at']),
        'order.refunded'  => in_array($paymentStatus, ['refunded', 'partially_refunded'], true),
    ];

    $out = [];
    foreach ($wanted as $event) {
        if (!empty($reached[$event])) {
            $out[] = $event;
        }
    }

    return $out;
}

/**
 * Find orders that changed since the last run and queue their webhook events.
 *
 * FIRST RUN QUEUES NOTHING. It records the watermark and stops. Switching a
 * webhook on must not fire the store's entire order history at an endpoint that
 * is being set up - and an operator who wants history can say so by moving the
 * watermark, which is one UPDATE they chose to run.
 *
 * Correctness does NOT depend on the watermark: the UNIQUE key refuses an event
 * that is already queued, so the watermark is only there to keep the scan small.
 * That is why it is deliberately rewound a second on each pass - two orders
 * sharing an `updated_at` at the edge of the LIMIT would otherwise be skipped.
 *
 * @return array{queued:int, scanned:int, skipped:int, first_run:bool, note:string}
 */
function integration_harvest_webhooks(int $limit = 200): array
{
    $blank = ['queued' => 0, 'scanned' => 0, 'skipped' => 0, 'first_run' => false, 'note' => ''];

    if (!integration_queue_installed()) {
        return ['note' => 'The integration queue tables are missing.'] + $blank;
    }

    $config = integration_sender_config();
    if (!$config['webhook']['enabled']) {
        return ['note' => 'The webhook sender is switched off.'] + $blank;
    }
    if (!$config['webhook']['configured']) {
        return ['note' => 'The webhook needs an endpoint URL and a signing secret.'] + $blank;
    }
    if ($config['webhook']['events'] === []) {
        return ['note' => 'No webhook events are selected.'] + $blank;
    }

    $mark = integration_state_get('webhook_watermark');
    if ($mark === null) {
        integration_state_set('webhook_watermark', date('Y-m-d H:i:s'));
        return ['first_run' => true,
                'note' => 'First run: watermark set. Orders from now on are queued; earlier ones are not backfilled.']
            + $blank;
    }

    $limit  = max(1, min(1000, $limit));
    $floor  = date('Y-m-d H:i:s', time() - (INTEGRATION_HARVEST_MAX_AGE_DAYS * 86400));

    $orders = Database::fetchAll(
        'SELECT * FROM `orders`
         WHERE `updated_at` > :mark AND `created_at` >= :floor
         ORDER BY `updated_at` ASC, `id` ASC
         LIMIT ' . $limit,
        ['mark' => $mark, 'floor' => $floor]
    );

    $queued  = 0;
    $skipped = 0;
    $last    = $mark;

    foreach ($orders as $order) {
        $last  = (string) $order['updated_at'];
        $order['items'] = Database::fetchAll(
            'SELECT `product_id`, `variant_id`, `product_name`, `product_sku`, `variant_name`,
                    `price`, `quantity`, `total`
             FROM `order_items` WHERE `order_id` = :id ORDER BY `id`',
            ['id' => (int) $order['id']]
        );

        foreach (integration_order_events($order, $config['webhook']['events']) as $event) {
            $eventId = integration_webhook_event_id($event, (string) $order['order_number']);
            $result  = integration_enqueue([
                'channel'  => 'webhook',
                'event'    => $event,
                'event_id' => $eventId,
                'order_id' => (int) $order['id'],
                'payload'  => integration_encode(
                    integration_webhook_payload($event, $order, $eventId)
                ),
                // An owner's own endpoint is not a third-party tag. See the
                // file docblock.
                'consent'  => 'not_applicable',
            ]);

            if ($result['state'] === 'duplicate') {
                $skipped++;
            } elseif ($result['ok']) {
                $queued++;
            }
        }
    }

    if ($orders !== []) {
        // Rewound by a second on purpose - see the docblock.
        $rewound = date('Y-m-d H:i:s', max(0, strtotime($last) - 1));
        integration_state_set('webhook_watermark', $rewound);
    }

    return [
        'queued'    => $queued,
        'scanned'   => count($orders),
        'skipped'   => $skipped,
        'first_run' => false,
        'note'      => '',
    ];
}

// ===========================================================================
//  THE TRANSPORT
// ===========================================================================

/**
 * Replace the HTTP call.
 *
 * A suite passes a closure with the same signature as
 * integration_curl_transport() and then asserts on what WOULD have been sent.
 * Nothing else may set this: a transport swapped in from a request would be a
 * way to make the store post anywhere.
 *
 * @param callable|null $fn fn(string $method, string $url, array $headers, ?string $body, int $timeout): array
 */
function integration_set_transport(?callable $fn): void
{
    $GLOBALS['SIK_INTEGRATION_TRANSPORT'] = $fn;
}

/** The transport in force: the injected one, or cURL. */
function integration_transport(): callable
{
    $injected = $GLOBALS['SIK_INTEGRATION_TRANSPORT'] ?? null;

    return is_callable($injected) ? $injected : 'integration_curl_transport';
}

/** The default transport: cURL, TLS verification left on. */
function integration_curl_transport(string $method, string $url, array $headers, ?string $body, int $timeout): array
{
    if (!function_exists('curl_init')) {
        return ['status' => 0, 'body' => '', 'error' => 'cURL is not available on this server.'];
    }

    $ch    = curl_init($url);
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

/**
 * Text that is safe to store: no readable address, no credential.
 *
 * An endpoint that echoes the request back, or a Meta error that quotes what it
 * was given, would otherwise put an email or a token into a column an operator
 * can read and a backup can travel with.
 */
function integration_safe_text(string $text): string
{
    $config = integration_sender_config();

    foreach ([$config['meta']['token'], $config['webhook']['secret']] as $secret) {
        if ($secret !== '' && strlen($secret) >= 8) {
            $text = str_replace($secret, '[redacted]', $text);
        }
    }

    // Any address-shaped run of characters, masked the way the mail log does it.
    $text = (string) preg_replace_callback(
        '/[\w.+-]+@[\w-]+\.[\w.-]+/',
        static fn (array $m): string => function_exists('mask_email') ? mask_email($m[0]) : '[email]',
        $text
    );

    return mb_substr(trim($text), 0, 500);
}

// ===========================================================================
//  SENDING
// ===========================================================================

/**
 * Deliver one queued row. One attempt, no internal retry - retrying is the
 * queue's job, and a loop inside here would be a loop inside a cron run.
 *
 * @return array{ok:bool, status:int, retryable:bool, response:string, error:string}
 */
function integration_send_row(array $row): array
{
    $config  = integration_sender_config();
    $channel = (string) $row['channel'];
    $body    = (string) $row['payload'];

    if ($channel === 'meta_capi') {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return ['ok' => false, 'status' => 0, 'retryable' => false, 'response' => '',
                    'error' => 'The queued payload is not valid JSON.'];
        }

        // The token is added HERE and never stored. See integration_meta_payload().
        $decoded['access_token'] = $config['meta']['token'];
        $url = 'https://graph.facebook.com/' . INTEGRATION_META_VERSION . '/'
            . rawurlencode($config['meta']['dataset']) . '/events';

        $sendBody = integration_encode($decoded);
        $headers  = ['Content-Type' => 'application/json', 'Accept' => 'application/json'];
    } elseif ($channel === 'webhook') {
        $timestamp = time();
        $url       = $config['webhook']['url'];
        $sendBody  = $body;
        $headers   = integration_webhook_headers(
            $config['webhook']['secret'],
            (string) $row['event'],
            (string) $row['event_id'],
            $timestamp,
            $body
        );
    } else {
        return ['ok' => false, 'status' => 0, 'retryable' => false, 'response' => '',
                'error' => 'Unknown channel ' . $channel . '.'];
    }

    $transport = integration_transport();
    try {
        $response = $transport('POST', $url, $headers, $sendBody, INTEGRATION_TIMEOUT);
    } catch (Throwable $e) {
        $response = ['status' => 0, 'body' => '', 'error' => $e->getMessage()];
    }

    $status = (int) ($response['status'] ?? 0);
    $raw    = (string) ($response['body'] ?? '');
    $error  = (string) ($response['error'] ?? '');
    $ok     = $status >= 200 && $status < 300 && $error === '';

    // Retry only what a second attempt could change: an unreachable host, a
    // rate limit, a server error. A 4xx is the other side saying the request is
    // wrong, and sending it again gets the same answer.
    $retryable = $status === 0 || $status === 429 || $status >= 500;

    if (!$ok && $error === '') {
        $error = integration_error_text($raw, $status);
    }

    return [
        'ok'        => $ok,
        'status'    => $status,
        'retryable' => $retryable,
        'response'  => integration_safe_text($raw),
        'error'     => $ok ? '' : integration_safe_text($error),
    ];
}

/** A readable reason out of whatever shape the other side returned. */
function integration_error_text(string $raw, int $status): string
{
    $decoded = $raw === '' ? null : json_decode($raw, true);

    if (is_array($decoded)) {
        // Meta: {"error":{"message":"...","error_user_msg":"..."}}
        $error = $decoded['error'] ?? null;
        if (is_array($error)) {
            foreach (['error_user_msg', 'message'] as $key) {
                if (!empty($error[$key]) && is_string($error[$key])) {
                    return (string) $error[$key];
                }
            }
        }
        foreach (['message', 'error', 'detail'] as $key) {
            if (!empty($decoded[$key]) && is_string($decoded[$key])) {
                return (string) $decoded[$key];
            }
        }
    }

    return $status === 0 ? 'The endpoint could not be reached.' : 'The endpoint returned HTTP ' . $status . '.';
}

/** Rows that are due. */
function integration_queue_due(int $limit = 25): array
{
    if (!integration_queue_installed()) {
        return [];
    }

    return Database::fetchAll(
        "SELECT * FROM `integration_queue`
         WHERE `status` = 'pending' AND `next_attempt_at` <= NOW()
         ORDER BY `next_attempt_at` ASC, `id` ASC
         LIMIT " . max(1, min(500, $limit))
    );
}

/**
 * Seconds to wait before attempt number $attempt + 1.
 *
 * 0 means there is no next attempt: the row has given up.
 */
function integration_backoff_seconds(int $attempt): int
{
    return (int) (INTEGRATION_BACKOFF[$attempt - 1] ?? 0);
}

/**
 * Send what is due.
 *
 * @return array{sent:int, retry:int, failed:int, skipped:int, gave_up:array<int,array>}
 */
function integration_process_queue(int $limit = 25): array
{
    $result = ['sent' => 0, 'retry' => 0, 'failed' => 0, 'skipped' => 0, 'gave_up' => []];

    $rows = integration_queue_due($limit);
    if ($rows === []) {
        return $result;
    }

    $config = integration_sender_config();
    $now    = date('Y-m-d H:i:s');

    foreach ($rows as $row) {
        $id      = (int) $row['id'];
        $channel = (string) $row['channel'];

        // --- the gate, checked again at send time -------------------------
        [$permitted, $why] = integration_row_may_send($row);
        if (!$permitted) {
            Database::update('integration_queue', [
                'status' => 'skipped',
                'error'  => $why,
            ], '`id` = :id', ['id' => $id]);
            $result['skipped']++;
            continue;
        }

        // --- the channel may have been switched off since queueing --------
        $channelOff = $channel === 'meta_capi'
            ? (!$config['meta']['enabled'] || !$config['meta']['configured'])
            : (!$config['webhook']['enabled'] || !$config['webhook']['configured']);

        if ($channelOff) {
            Database::update('integration_queue', [
                'status' => 'skipped',
                'error'  => 'Not sent: this sender is switched off or not configured.',
            ], '`id` = :id', ['id' => $id]);
            $result['skipped']++;
            continue;
        }

        $attempt = (int) $row['attempts'] + 1;
        $max     = max(1, (int) $row['max_attempts']);
        $outcome = integration_send_row($row);

        if ($outcome['ok']) {
            Database::update('integration_queue', [
                'status'          => 'sent',
                'attempts'        => $attempt,
                'last_attempt_at' => $now,
                'sent_at'         => $now,
                'http_status'     => $outcome['status'],
                'response'        => $outcome['response'],
                'error'           => null,
            ], '`id` = :id', ['id' => $id]);
            $result['sent']++;
            continue;
        }

        $wait = $outcome['retryable'] && $attempt < $max ? integration_backoff_seconds($attempt) : 0;

        if ($wait > 0) {
            Database::update('integration_queue', [
                'status'          => 'pending',
                'attempts'        => $attempt,
                'last_attempt_at' => $now,
                'next_attempt_at' => date('Y-m-d H:i:s', time() + $wait),
                'http_status'     => $outcome['status'] ?: null,
                'response'        => $outcome['response'],
                'error'           => $outcome['error'],
            ], '`id` = :id', ['id' => $id]);
            $result['retry']++;
            continue;
        }

        // Giving up. Loud, not silent: the row says failed, the worker prints
        // to STDERR and exits non-zero, and the admin screen counts it in red.
        Database::update('integration_queue', [
            'status'          => 'failed',
            'attempts'        => $attempt,
            'last_attempt_at' => $now,
            'http_status'     => $outcome['status'] ?: null,
            'response'        => $outcome['response'],
            'error'           => $outcome['error'],
        ], '`id` = :id', ['id' => $id]);

        $result['failed']++;
        $result['gave_up'][] = [
            'id'      => $id,
            'channel' => $channel,
            'event'   => (string) $row['event'],
            'attempts' => $attempt,
            'status'  => $outcome['status'],
            'error'   => $outcome['error'],
        ];
    }

    return $result;
}

/** Put failed rows back, once, on an operator's say-so. */
function integration_queue_retry_failed(int $days = 7): int
{
    if (!integration_queue_installed()) {
        return 0;
    }

    return Database::query(
        "UPDATE `integration_queue`
         SET `status` = 'pending', `attempts` = 0, `next_attempt_at` = NOW(), `error` = NULL
         WHERE `status` = 'failed' AND `created_at` > DATE_SUB(NOW(), INTERVAL :d DAY)",
        ['d' => max(1, $days)]
    )->rowCount();
}

/** The queue is also the send log, and the webhook copy holds customer data. */
function integration_queue_prune(int $days): int
{
    if (!integration_queue_installed()) {
        return 0;
    }

    return Database::query(
        "DELETE FROM `integration_queue`
         WHERE `status` IN ('sent', 'skipped') AND `created_at` < DATE_SUB(NOW(), INTERVAL :d DAY)",
        ['d' => max(1, $days)]
    )->rowCount();
}

// ===========================================================================
//  WHAT THE SCREEN SHOWS
// ===========================================================================

/**
 * Queue counts, per channel and in total.
 *
 * @return array{installed:bool, total:array<string,int>, meta:array<string,int>,
 *               webhook:array<string,int>, last_sent:?string, oldest_pending:?string}
 */
function integration_queue_stats(): array
{
    $empty = ['pending' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0];

    if (!integration_queue_installed()) {
        return ['installed' => false, 'total' => $empty, 'meta' => $empty, 'webhook' => $empty,
                'last_sent' => null, 'oldest_pending' => null];
    }

    $rows = Database::fetchAll(
        'SELECT `channel`, `status`, COUNT(*) AS `n` FROM `integration_queue` GROUP BY `channel`, `status`'
    );

    $out = ['installed' => true, 'total' => $empty, 'meta' => $empty, 'webhook' => $empty];

    foreach ($rows as $row) {
        $bucket = (string) $row['channel'] === 'meta_capi' ? 'meta' : 'webhook';
        $status = (string) $row['status'];
        if (!isset($empty[$status])) {
            continue;
        }
        $out[$bucket][$status] += (int) $row['n'];
        $out['total'][$status] += (int) $row['n'];
    }

    $out['last_sent'] = Database::fetchColumn(
        'SELECT MAX(`sent_at`) FROM `integration_queue`'
    ) ?: null;
    $out['oldest_pending'] = Database::fetchColumn(
        "SELECT MIN(`created_at`) FROM `integration_queue` WHERE `status` = 'pending'"
    ) ?: null;

    return $out;
}

/**
 * One sentence per channel saying exactly what it is doing, for the badge and
 * the card subtitle.
 *
 * This is the function the screen's honesty rests on: it never says "sending"
 * unless the switch is on, the credentials are readable, and something really
 * queues events.
 *
 * @return array{
 *   meta:array{state:string, badge:string, tone:string, line:string},
 *   webhook:array{state:string, badge:string, tone:string, line:string}
 * }
 */
function integration_sender_status(): array
{
    $config = integration_sender_config();
    $plan   = integration_meta_event_plan();

    // --- Meta -----------------------------------------------------------
    if (!integration_queue_installed()) {
        $meta = ['state' => 'not-installed', 'badge' => 'Not installed', 'tone' => 'red',
                 'line' => 'The queue tables are missing. Run the 2026_09_28 migration.'];
    } elseif (!$config['meta']['configured']) {
        $meta = ['state' => 'unconfigured', 'badge' => 'Not configured', 'tone' => 'gray',
                 'line' => 'Needs a dataset ID and an access token.'];
    } elseif (!$config['meta']['enabled']) {
        $meta = ['state' => 'off', 'badge' => 'Off', 'tone' => 'gray',
                 'line' => 'Configured and switched off. Nothing is queued or sent.'];
    } elseif ($plan['send'] === []) {
        $meta = ['state' => 'blocked', 'badge' => 'No sendable events', 'tone' => 'amber',
                 'line' => 'Every candidate event would be counted twice by the pixel.'];
    } else {
        // The honest part. The sender works; the checkout does not call it yet.
        $meta = ['state' => 'no-source', 'badge' => 'Ready, no source', 'tone' => 'amber',
                 'line' => 'Sends Purchase when the checkout queues one. Test events work now.'];
    }

    // --- webhook --------------------------------------------------------
    if (!integration_queue_installed()) {
        $hook = ['state' => 'not-installed', 'badge' => 'Not installed', 'tone' => 'red',
                 'line' => 'The queue tables are missing. Run the 2026_09_28 migration.'];
    } elseif (!$config['webhook']['configured']) {
        $hook = ['state' => 'unconfigured', 'badge' => 'Not configured', 'tone' => 'gray',
                 'line' => 'Needs an endpoint URL and a signing secret.'];
    } elseif (!$config['webhook']['enabled']) {
        $hook = ['state' => 'off', 'badge' => 'Off', 'tone' => 'gray',
                 'line' => 'Configured and switched off. Nothing is queued or sent.'];
    } elseif ($config['webhook']['events'] === []) {
        $hook = ['state' => 'no-events', 'badge' => 'No events chosen', 'tone' => 'amber',
                 'line' => 'Switched on, but no event is selected, so nothing is queued.'];
    } else {
        $hook = ['state' => 'sending', 'badge' => 'Sending', 'tone' => 'green',
                 'line' => 'Order events are queued and posted, signed, by the worker.'];
    }

    return ['meta' => $meta, 'webhook' => $hook];
}
