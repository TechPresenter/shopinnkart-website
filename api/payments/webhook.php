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
 * The throttle counts FAILURES only, per caller and gateway, and a call whose
 * signature verifies is never refused by it (step 4). Throttling a verified
 * delivery would let a stranger silence the gateway by spending the budget
 * itself, and a lost "paid" does not come back once the gateway stops
 * retrying. See PAYMENT_WEBHOOK_MAX_FAILURES.
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

// The gateway this call turns out to name, for the failure counter and the
// log. Everything before it resolves shares the one "unresolved" counter.
$gateway = '';

$respond = static function (int $status, string $message, array $context = []): void {
    if ($context !== []) {
        ErrorHandler::log(
            $status >= 500 ? 'warning' : 'info',
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

// The caller, as the app knows it everywhere else: behind a trusted proxy
// REMOTE_ADDR is the proxy, so every gateway and every attacker shared one
// counter and the budget belonged to whoever spent it first.
$ip = client_ip();

/**
 * Refuse, count it against the caller, and write it down sparingly.
 *
 * $status is what the caller is told unless it is already over its failure
 * budget, when every unverified call is 429 instead. $alarm names a security
 * event for the admin dashboard, raised only for the first few refusals in a
 * window: the caller is anonymous and chooses the rate, so "one row per
 * refusal" is a way to fill the table from outside.
 */
$refuse = static function (int $status, string $message, array $context = [], string $alarm = '')
    use ($respond, $ip, &$gateway): void {
    $seen = payment_webhook_note_failure(payment_webhook_key($ip, $gateway));

    if ($seen['over']) {
        $status = 429;
        if (!headers_sent()) {
            header('Retry-After: ' . PAYMENT_WEBHOOK_WINDOW);
        }
    }

    if ($seen['tripped']) {
        // Where a monitor looks when payments go missing - once per window, or
        // the flood writes the very log it is being kept out of.
        security_event('webhook.payment_throttled', 'medium', [
            'gateway'  => $gateway === '' ? 'unresolved' : $gateway,
            'failures' => $seen['count'],
            'window'   => PAYMENT_WEBHOOK_WINDOW,
        ]);
    } elseif ($alarm !== '' && !$seen['quiet']) {
        security_event($alarm, 'medium', $context + [
            'gateway'  => $gateway === '' ? 'unresolved' : $gateway,
            'failures' => $seen['count'],
        ]);
    }

    $respond($status, $message, $seen['quiet'] ? [] : $context + ['ip' => $ip, 'failures' => $seen['count']]);
};

// ---------------------------------------------------------------------------
//  1. Method
// ---------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    $refuse(405, 'Non-POST request rejected.');
}

// ---------------------------------------------------------------------------
//  2. Gateway
// ---------------------------------------------------------------------------
$asked = strtolower(trim((string) ($_GET['gateway'] ?? '')));

if ($asked === '' || !in_array($asked, WEBHOOK_GATEWAYS, true)) {
    $refuse(404, 'Unknown gateway.', ['gateway' => mb_substr($asked, 0, 40)]);
}
$gateway = $asked;

// The gateway must be switched on in the admin before its webhook does
// anything, so a stale endpoint from a decommissioned provider is inert.
$isActive = (string) Database::fetchColumn(
    'SELECT `status` FROM `payment_methods` WHERE `code` = :c LIMIT 1',
    ['c' => $gateway]
) === STATUS_ACTIVE;

if (!$isActive) {
    $refuse(403, 'Gateway is not active.', ['gateway' => $gateway]);
}

// ---------------------------------------------------------------------------
//  3. Raw body
//
//  Signatures are computed over the exact bytes received. Anything that
//  re-encodes the payload (json_decode + json_encode, $_POST) reorders keys
//  and changes whitespace, and the digest will never match.
// ---------------------------------------------------------------------------
$rawBody = (string) file_get_contents('php://input');

if ($rawBody === '') {
    $refuse(400, 'Empty body.', ['gateway' => $gateway]);
}
if (strlen($rawBody) > 1048576) {
    $refuse(413, 'Body too large.', ['gateway' => $gateway, 'bytes' => strlen($rawBody)]);
}

$headers = [];
foreach ($_SERVER as $key => $value) {
    if (strpos($key, 'HTTP_') === 0) {
        $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = (string) $value;
    }
}

// ---------------------------------------------------------------------------
//  4. Signature — the actual authentication
//
//  The failure budget is spent HERE and nowhere earlier: $refuse() is what
//  counts it and what turns the refusal into a 429 once the caller is over.
//  The old code recorded a "bad signature" counter and threw its answer away,
//  so the tighter budget its own comment promised was never in force at all.
//
//  Checking the budget BEFORE verifying would save one HMAC and cost a
//  payment: a caller whose budget somebody else had spent would be refused
//  even holding the secret. Past this line the call has proved it holds that
//  secret, and nothing below may refuse it for a stranger's noise.
// ---------------------------------------------------------------------------
$verified = webhook_verify_signature($gateway, $rawBody, $headers);

if (!$verified['ok']) {
    $refuse(401, 'Signature verification failed: ' . $verified['reason'],
        ['gateway' => $gateway, 'reason' => $verified['reason']], 'webhook.bad_signature');
}

// ---------------------------------------------------------------------------
//  5. Parse and normalise
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
//  6. Apply — idempotent, and never allowed to 500 at the gateway
// ---------------------------------------------------------------------------
try {
    $result = payment_record_event($event);
} catch (Throwable $e) {
    ErrorHandler::log('error', 'Webhook processing failed for ' . $gateway . ': ' . $e->getMessage(),
        $e->getFile(), $e->getLine(), $e->getTraceAsString());

    // 500 asks the gateway to redeliver, which is what we want for a transient
    // fault: payment_record_event() claims and applies in one transaction, so
    // the failed attempt left nothing behind and the retry does the work.
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
