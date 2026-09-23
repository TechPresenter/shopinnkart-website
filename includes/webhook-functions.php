<?php
/**
 * ShopInnKart - Payment webhook signature verification.
 *
 * A webhook endpoint is an unauthenticated URL that can mark orders paid, so
 * the signature IS the authentication. Everything here fails closed: an
 * unknown gateway, a missing secret, a malformed header or a signature that
 * does not match are all refusals, never "assume it is fine".
 *
 * Every scheme below signs the RAW request body. Re-encoding a decoded array
 * changes key order and whitespace and will never reproduce the gateway's
 * digest, so the raw string has to be carried through untouched.
 *
 * The credentials live in payment_methods.config, which is a JSON blob edited
 * in Admin -> Settings -> Payment.
 */

declare(strict_types=1);

/** Gateways this file knows how to verify. */
const WEBHOOK_GATEWAYS = ['razorpay', 'stripe', 'cashfree', 'payu'];

/**
 * Gateways whose payloads carry the currency of the payment.
 *
 * It matters because a paid event is refused when it cannot prove its
 * currency: an account that can accept USD as well as INR would otherwise let
 * a genuine, signed payment of the same NUMBER in the cheaper currency settle
 * an INR order. PayU's form POST has no currency field anywhere in the scheme
 * - the merchant account itself is single-currency - so requiring one there
 * would refuse every real PayU payment. It is exempt by protocol, not by
 * convenience, and the amount and order matching still apply to it.
 */
const WEBHOOK_GATEWAYS_WITH_CURRENCY = ['razorpay', 'stripe', 'cashfree'];

/** Does this gateway tell us the currency it charged in? */
function webhook_gateway_reports_currency(string $gateway): bool
{
    return in_array(strtolower($gateway), WEBHOOK_GATEWAYS_WITH_CURRENCY, true);
}

/**
 * Config keys whose value is a credential, not a setting.
 *
 * Everything named here is encrypted at rest with secret_encrypt() and is
 * never rendered back into the admin form. The publishable half of a gateway
 * key pair (key_id, merchant_id, mode) deliberately is not: it appears in the
 * checkout page source anyway, and keeping it readable is what lets an
 * operator confirm which account a method points at.
 */
const GATEWAY_SECRET_KEYS = [
    'key_secret', 'secret_key', 'webhook_secret', 'merchant_salt', 'salt',
    'api_secret', 'client_secret', 'auth_token', 'access_token', 'private_key',
];

/** True for a config key that must be stored encrypted. */
function gateway_key_is_secret(string $key): bool
{
    return in_array(strtolower($key), GATEWAY_SECRET_KEYS, true);
}

/**
 * The decoded config blob for a payment method, or [] when there is none.
 *
 * Secret values come back in plaintext: this is the one place that decrypts
 * them, so a gateway class keeps working unchanged while the column holds
 * ciphertext. Values written before encryption existed pass through
 * secret_decrypt() untouched, so an install mid-migration still charges cards.
 */
function gateway_config(string $code): array
{
    try {
        $raw = Database::fetchColumn(
            'SELECT `config` FROM `payment_methods` WHERE `code` = :c LIMIT 1',
            ['c' => $code]
        );
    } catch (Throwable $e) {
        return [];
    }

    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }

    foreach ($decoded as $key => $value) {
        if (is_string($value) && gateway_key_is_secret((string) $key)) {
            $decoded[$key] = secret_decrypt($value);
        }
    }

    return $decoded;
}

/**
 * Encrypt the secret keys of a config array for storage.
 *
 * A value that is already ciphertext is left alone, which is what makes the
 * migration - and a re-save that carried the stored value along - idempotent.
 * The version is matched loosely rather than pinned to the envelope of the day:
 * secret_encrypt() moved from enc:v1 to enc:v2 when per-purpose key derivation
 * arrived, and a hardcoded "v1" here would have re-encrypted every already
 * encrypted secret into an unreadable double wrapper.
 */
function gateway_config_encrypt(array $config): array
{
    foreach ($config as $key => $value) {
        if (!is_string($value) || $value === '' || !gateway_key_is_secret((string) $key)) {
            continue;
        }
        $config[$key] = preg_match('/^enc:v\d+:/', $value) === 1 ? $value : secret_encrypt($value);
    }

    return $config;
}

/**
 * The signing secret for a gateway, resolved with the same precedence as SMTP:
 * environment variable first so a production host never depends on the DB.
 */
function gateway_webhook_secret(string $code): string
{
    $env = getenv('SIK_WEBHOOK_SECRET_' . strtoupper($code));
    if (is_string($env) && $env !== '') {
        return $env;
    }

    // gateway_config() already decrypted them.
    $config = gateway_config($code);

    // Razorpay and Stripe carry a dedicated webhook secret. Cashfree signs with
    // the API secret key, and PayU with the merchant salt.
    foreach (['webhook_secret', 'secret_key', 'merchant_salt'] as $key) {
        $value = trim((string) ($config[$key] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }

    return '';
}

/**
 * Verify a webhook request.
 *
 * @param string $gateway  razorpay|stripe|cashfree|payu
 * @param string $rawBody  the request body exactly as received
 * @param array  $headers  lowercased header name => value
 *
 * @return array{ok:bool, reason:string}
 */
function webhook_verify_signature(string $gateway, string $rawBody, array $headers): array
{
    $deny = static fn (string $reason): array => ['ok' => false, 'reason' => $reason];

    if (!in_array($gateway, WEBHOOK_GATEWAYS, true)) {
        return $deny('Unknown gateway.');
    }

    $secret = gateway_webhook_secret($gateway);
    if ($secret === '') {
        // Refusing here is the point: without a secret nothing can be proved,
        // and an endpoint that accepts unproven "payment succeeded" callbacks
        // is a way to get free orders.
        return $deny('No webhook secret configured for ' . $gateway . '.');
    }

    $tolerance = max(60, setting_int('webhook_timestamp_tolerance', 300));

    switch ($gateway) {
        // -------------------------------------------------------------------
        //  Razorpay: X-Razorpay-Signature = hex HMAC-SHA256 of the raw body.
        // -------------------------------------------------------------------
        case 'razorpay':
            $signature = trim((string) ($headers['x-razorpay-signature'] ?? ''));
            if ($signature === '') {
                return $deny('Missing X-Razorpay-Signature header.');
            }

            $expected = hash_hmac('sha256', $rawBody, $secret);

            return hash_equals($expected, $signature)
                ? ['ok' => true, 'reason' => '']
                : $deny('Signature mismatch.');

        // -------------------------------------------------------------------
        //  Stripe: Stripe-Signature = "t=<unix>,v1=<hex>[,v1=<hex>]".
        //  The signed payload is "<t>.<rawBody>".
        // -------------------------------------------------------------------
        case 'stripe':
            $header = trim((string) ($headers['stripe-signature'] ?? ''));
            if ($header === '') {
                return $deny('Missing Stripe-Signature header.');
            }

            $timestamp = null;
            $candidates = [];
            foreach (explode(',', $header) as $part) {
                $pair = explode('=', trim($part), 2);
                if (count($pair) !== 2) {
                    continue;
                }
                if ($pair[0] === 't') {
                    $timestamp = (int) $pair[1];
                } elseif ($pair[0] === 'v1') {
                    // Stripe sends several v1 values while a secret is rotating.
                    $candidates[] = $pair[1];
                }
            }

            if ($timestamp === null || $candidates === []) {
                return $deny('Malformed Stripe-Signature header.');
            }
            if (abs(time() - $timestamp) > $tolerance) {
                return $deny('Timestamp outside the tolerance window (replay protection).');
            }

            $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
            foreach ($candidates as $candidate) {
                if (hash_equals($expected, $candidate)) {
                    return ['ok' => true, 'reason' => ''];
                }
            }

            return $deny('Signature mismatch.');

        // -------------------------------------------------------------------
        //  Cashfree: x-webhook-signature = base64 HMAC-SHA256 of
        //  "<x-webhook-timestamp><rawBody>", keyed with the API secret.
        // -------------------------------------------------------------------
        case 'cashfree':
            $signature = trim((string) ($headers['x-webhook-signature'] ?? ''));
            $timestamp = trim((string) ($headers['x-webhook-timestamp'] ?? ''));

            if ($signature === '' || $timestamp === '') {
                return $deny('Missing x-webhook-signature or x-webhook-timestamp header.');
            }
            if (!ctype_digit($timestamp) || abs(time() - (int) $timestamp) > $tolerance) {
                return $deny('Timestamp outside the tolerance window (replay protection).');
            }

            $expected = base64_encode(hash_hmac('sha256', $timestamp . $rawBody, $secret, true));

            return hash_equals($expected, $signature)
                ? ['ok' => true, 'reason' => '']
                : $deny('Signature mismatch.');

        // -------------------------------------------------------------------
        //  PayU: form POST carrying its own reverse hash, keyed with the salt.
        //  sha512(salt|status||||||udf5|udf4|udf3|udf2|udf1|email|firstname|
        //         productinfo|amount|txnid|key)
        // -------------------------------------------------------------------
        case 'payu':
            parse_str($rawBody, $fields);
            if (!is_array($fields) || $fields === []) {
                return $deny('Empty PayU payload.');
            }

            $supplied = strtolower(trim((string) ($fields['hash'] ?? '')));
            if ($supplied === '') {
                return $deny('Missing hash field.');
            }

            $get = static fn (string $key): string => (string) ($fields[$key] ?? '');

            $sequence = implode('|', [
                $secret,
                $get('status'),
                '', '', '', '', '', '',
                $get('udf5'), $get('udf4'), $get('udf3'), $get('udf2'), $get('udf1'),
                $get('email'), $get('firstname'), $get('productinfo'),
                $get('amount'), $get('txnid'), $get('key'),
            ]);

            return hash_equals(hash('sha512', $sequence), $supplied)
                ? ['ok' => true, 'reason' => '']
                : $deny('Hash mismatch.');
    }

    return $deny('Unsupported gateway.');
}

/**
 * Translate a gateway payload into the shape payment_record_event() expects.
 *
 * Only the fields needed to identify the order and the outcome are read; the
 * amount AND the currency are re-checked against the order downstream, and an
 * event that carries neither is refused there rather than assumed to be right.
 * `amount` and `currency` are deliberately null when the payload does not
 * carry them - a default would be a guess, and a guess is what let a payload
 * with no amount confirm an order.
 *
 * @return array{ok:bool, event:array, reason:string}
 */
function webhook_normalise_event(string $gateway, string $rawBody, array $decoded): array
{
    $miss = static fn (string $reason): array => ['ok' => false, 'event' => [], 'reason' => $reason];

    switch ($gateway) {
        case 'razorpay':
            $event = (string) ($decoded['event'] ?? '');
            $entity = $decoded['payload']['payment']['entity']
                ?? $decoded['payload']['refund']['entity']
                ?? [];

            if ($entity === []) {
                return $miss('No payment entity in the payload.');
            }

            $status = [
                'payment.captured'  => PAYMENT_STATUS_PAID,
                'payment.authorized' => PAYMENT_STATUS_PENDING,
                'payment.failed'    => PAYMENT_STATUS_FAILED,
                'refund.processed'  => PAYMENT_STATUS_REFUNDED,
                'refund.created'    => PAYMENT_STATUS_REFUNDED,
            ][$event] ?? null;

            if ($status === null) {
                return $miss('Ignored event type "' . $event . '".');
            }

            return ['ok' => true, 'reason' => '', 'event' => [
                'gateway'      => 'razorpay',
                'event'        => $event,
                'event_id'     => (string) ($decoded['id'] ?? ($entity['id'] ?? '')),
                'order_number' => (string) ($entity['notes']['order_number'] ?? ''),
                'order_id'     => (int) ($entity['notes']['order_id'] ?? 0),
                'status'       => $status,
                // Razorpay reports paise.
                'amount'       => isset($entity['amount']) ? ((float) $entity['amount']) / 100 : null,
                'currency'     => isset($entity['currency']) ? strtoupper((string) $entity['currency']) : null,
                'reference'    => (string) ($entity['id'] ?? ''),
                'message'      => (string) ($entity['error_description'] ?? ''),
                'payload'      => $decoded,
            ]];

        case 'stripe':
            $type = (string) ($decoded['type'] ?? '');
            $object = $decoded['data']['object'] ?? [];

            $status = [
                'payment_intent.succeeded'      => PAYMENT_STATUS_PAID,
                'checkout.session.completed'    => PAYMENT_STATUS_PAID,
                'payment_intent.payment_failed' => PAYMENT_STATUS_FAILED,
                'payment_intent.processing'     => PAYMENT_STATUS_PENDING,
                'charge.refunded'               => PAYMENT_STATUS_REFUNDED,
            ][$type] ?? null;

            if ($status === null) {
                return $miss('Ignored event type "' . $type . '".');
            }

            $metadata = $object['metadata'] ?? [];

            return ['ok' => true, 'reason' => '', 'event' => [
                'gateway'      => 'stripe',
                'event'        => $type,
                'event_id'     => (string) ($decoded['id'] ?? ''),
                'order_number' => (string) ($metadata['order_number'] ?? ''),
                'order_id'     => (int) ($metadata['order_id'] ?? 0),
                'status'       => $status,
                // Stripe reports the smallest currency unit.
                'amount'       => isset($object['amount_received'])
                    ? ((float) $object['amount_received']) / 100
                    : (isset($object['amount_total']) ? ((float) $object['amount_total']) / 100 : null),
                // Stripe lowercases its currency codes.
                'currency'     => isset($object['currency']) ? strtoupper((string) $object['currency']) : null,
                'reference'    => (string) ($object['id'] ?? ''),
                'message'      => (string) ($object['last_payment_error']['message'] ?? ''),
                'payload'      => $decoded,
            ]];

        case 'cashfree':
            $type = (string) ($decoded['type'] ?? '');
            $data = $decoded['data'] ?? [];
            $payment = $data['payment'] ?? [];
            $order = $data['order'] ?? [];

            $status = [
                'PAYMENT_SUCCESS_WEBHOOK'  => PAYMENT_STATUS_PAID,
                'PAYMENT_FAILED_WEBHOOK'   => PAYMENT_STATUS_FAILED,
                'PAYMENT_USER_DROPPED_WEBHOOK' => PAYMENT_STATUS_FAILED,
                'REFUND_STATUS_WEBHOOK'    => PAYMENT_STATUS_REFUNDED,
            ][$type] ?? null;

            if ($status === null) {
                return $miss('Ignored event type "' . $type . '".');
            }

            return ['ok' => true, 'reason' => '', 'event' => [
                'gateway'      => 'cashfree',
                'event'        => $type,
                'event_id'     => (string) ($payment['cf_payment_id'] ?? ($order['order_id'] ?? '')),
                'order_number' => (string) ($order['order_tags']['order_number'] ?? ($order['order_id'] ?? '')),
                'status'       => $status,
                'amount'       => isset($payment['payment_amount']) ? (float) $payment['payment_amount'] : null,
                'currency'     => isset($payment['payment_currency'])
                    ? strtoupper((string) $payment['payment_currency'])
                    : (isset($order['order_currency']) ? strtoupper((string) $order['order_currency']) : null),
                'reference'    => (string) ($payment['cf_payment_id'] ?? ''),
                'message'      => (string) ($payment['payment_message'] ?? ''),
                'payload'      => $decoded,
            ]];

        case 'payu':
            parse_str($rawBody, $fields);
            $payuStatus = strtolower((string) ($fields['status'] ?? ''));

            $status = [
                'success' => PAYMENT_STATUS_PAID,
                'failure' => PAYMENT_STATUS_FAILED,
                'pending' => PAYMENT_STATUS_PENDING,
            ][$payuStatus] ?? null;

            if ($status === null) {
                return $miss('Ignored PayU status "' . $payuStatus . '".');
            }

            return ['ok' => true, 'reason' => '', 'event' => [
                'gateway'      => 'payu',
                'event'        => 'payu.' . $payuStatus,
                'event_id'     => (string) ($fields['mihpayid'] ?? ($fields['txnid'] ?? '')),
                // PayU echoes back the merchant transaction id, which is the
                // order number the checkout sent it.
                'order_number' => (string) ($fields['txnid'] ?? ''),
                'status'       => $status,
                'amount'       => isset($fields['amount']) ? (float) $fields['amount'] : null,
                // PayU's scheme carries no currency at all - see
                // WEBHOOK_GATEWAYS_WITH_CURRENCY for why that is not a hole.
                'currency'     => null,
                'reference'    => (string) ($fields['mihpayid'] ?? ''),
                'message'      => (string) ($fields['error_Message'] ?? ($fields['field9'] ?? '')),
                'payload'      => $fields,
            ]];
    }

    return $miss('Unsupported gateway.');
}

/**
 * IP-scoped rate limit that does not need a session.
 *
 * A gateway never sends a cookie, so a session-backed counter would never
 * bite. This used to use the file cache instead — but Cache obeys the
 * `cache_enabled` admin toggle, so switching a performance setting off in
 * Admin > Settings silently switched off webhook IP limiting and the
 * brute-force cap on bad signatures with it. It is backed by the shared
 * `rate_limits` table now, which no performance switch can reach.
 */
function webhook_rate_limit_hit(string $bucket, int $max, int $windowSeconds): bool
{
    // One bucket name, the caller's string as the key: the key is hashed, so
    // an IPv6 address cannot overflow the 40-character bucket column and drop
    // every long address into one shared counter.
    return rate_limit_attempt('webhook', $bucket, $max, $windowSeconds);
}

// ===========================================================================
//  Payment webhook throttle
//
//  Failed calls only, and a VERIFIED call is never refused by it - the same
//  rule the courier hub arrived at (includes/shipping-functions.php). A
//  gateway settling a busy hour pushes hundreds of deliveries from one egress
//  address, and refusing one of those loses a payment: the gateway eventually
//  gives up retrying. Anything that counts verified traffic also hands an
//  anonymous caller a way to silence the gateway by spending the budget for
//  it. Forging a verified call needs the HMAC secret, so "unlimited if signed"
//  costs nothing.
//
//  The budget itself is the tight one the old code only PROMISED: the counter
//  for failed verifications was recorded and its answer thrown away, so the
//  only thing in force was a general 120/minute that counted genuine
//  deliveries too.
// ===========================================================================

const PAYMENT_WEBHOOK_MAX_FAILURES = 10;
const PAYMENT_WEBHOOK_WINDOW       = 300;
const PAYMENT_WEBHOOK_BUCKET       = 'payment_webhook';

/** How many refusals in a window are logged in full before only counted. */
const PAYMENT_WEBHOOK_LOG_FAILURES = 3;

/**
 * The counter's key: the caller AND the gateway it named.
 *
 * On the address alone, noise aimed at one gateway's URL would spend the
 * budget another gateway's real deliveries need. The gateway part is the
 * RESOLVED code, never the string the caller sent - keyed on that, a prober
 * would mint itself a fresh counter per request just by changing it.
 */
function payment_webhook_key(string $ip, string $gateway): string
{
    return $ip . '|' . ($gateway === '' ? '-' : mb_substr($gateway, 0, 40));
}

/**
 * Record one failed call and say where it leaves the caller.
 *
 * @return array{count:int, quiet:bool, over:bool, tripped:bool}
 */
function payment_webhook_note_failure(string $key): array
{
    $count = (int) ceil(rate_limit_count(PAYMENT_WEBHOOK_BUCKET, $key, PAYMENT_WEBHOOK_WINDOW, true));

    return [
        'count'   => $count,
        // Past this the refusal is counted but not written down: a stranger
        // choosing both the body and the rate otherwise chooses how much of
        // the error log to fill.
        'quiet'   => $count > PAYMENT_WEBHOOK_LOG_FAILURES,
        'over'    => $count > PAYMENT_WEBHOOK_MAX_FAILURES,
        // The one call that crosses the line, so it is recorded once.
        'tripped' => $count > PAYMENT_WEBHOOK_MAX_FAILURES && $count - 1 <= PAYMENT_WEBHOOK_MAX_FAILURES,
    ];
}

/** Is this caller over its failure budget? Records nothing. */
function payment_webhook_throttled(string $key): bool
{
    return !rate_limit_allows(PAYMENT_WEBHOOK_BUCKET, $key, PAYMENT_WEBHOOK_MAX_FAILURES, PAYMENT_WEBHOOK_WINDOW);
}
