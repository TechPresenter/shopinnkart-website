<?php
/**
 * POST /api/payments/webhook.php?gateway=razorpay
 *
 * The one URL a payment gateway may call. It is unauthenticated by nature, so
 * the signature is the authentication and this file fails closed at every
 * step: unknown gateway, missing secret, bad signature, unparseable body and
 * unknown event type are all refusals.
 *
 * CSRF is deliberately NOT enforced — a gateway cannot hold a token, and the
 * HMAC over the raw body is a stronger proof than a CSRF token would be.
 *
 * Idempotency lives in payment_record_event(): a replayed delivery collapses
 * onto the row the first one wrote and never produces a second invoice, a
 * second payment or a second email.
 *
 * Response contract: 200 whenever the delivery has been dealt with (including
 * duplicates and deliberately ignored event types) so the gateway stops
 * retrying; 4xx only when the request itself is untrustworthy or malformed.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/init.php';
require_once INCLUDES_PATH . '/webhook-functions.php';

// ---------------------------------------------------------------------------
//  The response is always terse. A webhook caller gets no diagnostic detail —
//  that would let an attacker probe for a valid signature format.
// ---------------------------------------------------------------------------
$respond = static function (int $status, string $message, array $context = []): void {
    if ($context !== []) {
        ErrorHandler::log(
            $status >= 500 || $status === 401 ? 'warning' : 'info',
            'Webhook: ' . $message . ' ' . json_encode($context, JSON_UNESCAPED_SLASHES)
        );
    }

    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }

    echo json_encode(['ok' => $status < 400]);
    exit;
};

// ---------------------------------------------------------------------------
//  1. Method
// ---------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    $respond(405, 'Non-POST request rejected.');
}

$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');

// ---------------------------------------------------------------------------
//  2. Rate limit, before any parsing work
// ---------------------------------------------------------------------------
if (!webhook_rate_limit_hit('ip:' . $ip, 120, 60)) {
    if (!headers_sent()) {
        header('Retry-After: 60');
    }
    $respond(429, 'Rate limited.', ['ip' => $ip]);
}

// ---------------------------------------------------------------------------
//  3. Gateway
// ---------------------------------------------------------------------------
$gateway = strtolower(trim((string) ($_GET['gateway'] ?? '')));

if ($gateway === '' || !in_array($gateway, WEBHOOK_GATEWAYS, true)) {
    $respond(404, 'Unknown gateway.', ['gateway' => $gateway, 'ip' => $ip]);
}

// The gateway must be switched on in the admin before its webhook does
// anything, so a stale endpoint from a decommissioned provider is inert.
$isActive = (string) Database::fetchColumn(
    'SELECT `status` FROM `payment_methods` WHERE `code` = :c LIMIT 1',
    ['c' => $gateway]
) === STATUS_ACTIVE;

if (!$isActive) {
    $respond(403, 'Gateway is not active.', ['gateway' => $gateway, 'ip' => $ip]);
}

// ---------------------------------------------------------------------------
//  4. Raw body
//
//  Signatures are computed over the exact bytes received. Anything that
//  re-encodes the payload (json_decode + json_encode, $_POST) reorders keys
//  and changes whitespace, and the digest will never match.
// ---------------------------------------------------------------------------
$rawBody = (string) file_get_contents('php://input');

if ($rawBody === '') {
    $respond(400, 'Empty body.', ['gateway' => $gateway, 'ip' => $ip]);
}
if (strlen($rawBody) > 1048576) {
    $respond(413, 'Body too large.', ['gateway' => $gateway, 'ip' => $ip, 'bytes' => strlen($rawBody)]);
}

$headers = [];
foreach ($_SERVER as $key => $value) {
    if (strpos($key, 'HTTP_') === 0) {
        $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = (string) $value;
    }
}

// ---------------------------------------------------------------------------
//  5. Signature — the actual authentication
// ---------------------------------------------------------------------------
$verified = webhook_verify_signature($gateway, $rawBody, $headers);

if (!$verified['ok']) {
    // A tighter limit for anything that fails verification, so a brute-force
    // attempt exhausts its budget long before the general one.
    webhook_rate_limit_hit('bad:' . $ip, 10, 300);

    $respond(401, 'Signature verification failed: ' . $verified['reason'], [
        'gateway' => $gateway,
        'ip'      => $ip,
    ]);
}

// ---------------------------------------------------------------------------
//  6. Parse and normalise
// ---------------------------------------------------------------------------
$decoded = [];
if ($gateway !== 'payu') {
    $decoded = json_decode($rawBody, true);
    if (!is_array($decoded)) {
        $respond(400, 'Body is not valid JSON.', ['gateway' => $gateway]);
    }
}

$normalised = webhook_normalise_event($gateway, $rawBody, $decoded);

if (!$normalised['ok']) {
    // A signed delivery we choose not to act on is still a success from the
    // gateway's point of view — 200 stops it retrying forever.
    $respond(200, $normalised['reason'], ['gateway' => $gateway]);
}

$event = $normalised['event'];

if ((string) ($event['order_number'] ?? '') === '' && (int) ($event['order_id'] ?? 0) === 0) {
    $respond(200, 'Signed event carried no order reference; nothing to apply.', [
        'gateway' => $gateway,
        'event'   => (string) ($event['event'] ?? ''),
    ]);
}

// ---------------------------------------------------------------------------
//  7. Apply — idempotent, and never allowed to 500 at the gateway
// ---------------------------------------------------------------------------
try {
    $result = payment_record_event($event);
} catch (Throwable $e) {
    ErrorHandler::log('error', 'Webhook processing failed for ' . $gateway . ': ' . $e->getMessage(),
        $e->getFile(), $e->getLine(), $e->getTraceAsString());

    // 500 asks the gateway to redeliver, which is what we want for a transient
    // fault: the idempotency key makes the retry safe.
    $respond(500, 'Processing error.', ['gateway' => $gateway]);
}

if ($result['duplicate']) {
    $respond(200, 'Duplicate delivery ignored.', [
        'gateway' => $gateway,
        'order'   => (string) ($result['order']['order_number'] ?? ''),
    ]);
}

if (!$result['ok']) {
    // A mismatched amount or an unknown order is a real problem for a human,
    // but redelivering will not fix it — acknowledge and log loudly.
    ErrorHandler::log('error', 'Webhook rejected by payment_record_event (' . $gateway . '): ' . $result['message']);
    $respond(200, 'Not applied: ' . $result['message'], ['gateway' => $gateway]);
}

$respond(200, 'Applied.', [
    'gateway' => $gateway,
    'order'   => (string) ($result['order']['order_number'] ?? ''),
    'status'  => (string) ($event['status'] ?? ''),
]);
