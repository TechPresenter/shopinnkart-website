<?php
/**
 * POST /api/shipping/webhook.php?hook=<webhook slug>
 *
 * The one URL a courier may call to push a status change. Unauthenticated by
 * nature, so it follows the payment webhook's contract exactly
 * (api/payments/webhook.php) and fails closed at every step:
 *
 *   unknown hook or provider, webhooks not enabled, no usable secret, bad
 *   signature, empty or oversized body, unparseable JSON
 *
 * are all refusals. CSRF is deliberately not enforced - a courier cannot hold a
 * token, and the signature is the stronger proof.
 *
 * The URL names the integration by an opaque slug, never by courier: Shiprocket
 * refuses a webhook URL containing "shiprocket", "sr" or "kr", and a guessable
 * URL tells a prober which secret to attack. ?provider=<code> is still accepted
 * for URLs already registered with a courier; the secret is required either way.
 *
 * A provider with no USABLE webhook secret is refused even if its driver would
 * accept an unsigned call. Usable means it decrypts: after the application key
 * changes the column still holds ciphertext, but it decrypts to '', and an HMAC
 * keyed with '' is one anybody can compute. Otherwise anybody who found the URL
 * could mark any order delivered, and delivered is where a COD order is marked
 * paid.
 *
 * An INACTIVE provider is still heard. Inactive means "offer it for no new
 * bookings"; the parcels already out with it keep moving, and their delivered
 * and RTO events are what settle COD and put stock back. webhook_enabled is the
 * switch that makes a URL inert.
 *
 * ONE refusal for all of them. Answering 404 for an unknown code, 403 for
 * webhooks-off and 401 for a bad signature told an anonymous caller which
 * couriers are configured and which have a working secret - the very thing the
 * opaque slug exists to hide. The reason is kept in the server-side log. The
 * malformed-request refusals (405, 400, 413) are answered BEFORE the URL is
 * resolved, so they are a property of the request and say nothing either.
 *
 * The throttle counts failures only, per CALLER, and a call whose signature
 * verifies is never refused by it (see step 5). Throttling a verified push
 * would let a stranger silence the courier by spending the budget itself, and
 * a lost update does not come back: Shiprocket, for one, does not promise to
 * retry. Per caller and not per integration, because a budget that empties
 * separately for each one is an enumeration oracle in its own right: a prober
 * filled the "code did not resolve" bucket and then read a live hook slug off
 * the reply, 401 against 429. How much of a refusal is written down is still
 * decided per integration, so one courier's flood cannot hide another's
 * malformed push.
 *
 * Response contract: 200 whenever the delivery has been dealt with - including
 * a duplicate, and an AWB we do not know - so the courier stops retrying.
 * 4xx only when the request itself is untrustworthy or malformed. Responses are
 * terse; a caller gets no detail that would help it probe the signature.
 *
 * Idempotency lives in shipping_record_events(): a resent event is a no-op.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/init.php';
require_once INCLUDES_PATH . '/shipping-service.php';

$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');

// The integration this call turns out to name, for the failure counter and the
// log. Everything before it resolves shares the one "unresolved" counter.
$code = '';

$respond = static function (int $status, string $message, array $context = []): void {
    if ($context !== []) {
        // Our own failures are warnings; a refusal is routine traffic on a URL
        // anyone can post to, and logging those as warnings filled the admin's
        // error log with a stranger's noise.
        ErrorHandler::log(
            $status >= 500 ? 'warning' : 'info',
            'Shipping webhook: ' . $message . ' ' . json_encode($context, JSON_UNESCAPED_SLASHES)
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

/**
 * Refuse, count it against the caller, and write it down sparingly.
 *
 * $status is what the caller is told unless it is already over its failure
 * budget, when every unverified call from that address is 429 instead -
 * whichever integration it named, and whether or not it named one that exists.
 * Only the first few refusals in a window are logged - to shipping_api_logs
 * ($entry) and to the error log - because a stranger choosing both the body
 * and the rate is otherwise choosing how much of the database to fill.
 */
$refuse = static function (int $status, string $message, array $context = [], array $entry = []) use ($respond, $ip, &$code): void {
    $seen = shipping_webhook_note_failure($ip, $code);

    if ($seen['over']) {
        $status = 429;
        if (!headers_sent()) {
            header('Retry-After: ' . SHIPPING_WEBHOOK_WINDOW);
        }
    }

    if ($seen['tripped']) {
        // Where an admin looks when updates go missing, once per window - or
        // the flood writes the very log it is being kept out of.
        shipping_log($code === '' ? 'unknown' : $code, 'webhook', [
            'ok'    => false,
            'error' => 'Throttled: more than ' . SHIPPING_WEBHOOK_MAX_FAILURES . ' failed calls from ' . $ip
                     . ' within ' . SHIPPING_WEBHOOK_WINDOW . 's; unverified calls from it are refused (429) until they age out.',
        ]);
    } elseif (!$seen['quiet'] && $entry !== []) {
        shipping_log($code === '' ? 'unknown' : $code, 'webhook', $entry);
    }

    $respond($status, $message, $seen['quiet'] ? []
        : $context + ['ip' => $ip, 'failures' => $seen['count'], 'failures_from_ip' => $seen['total']]);
};

// Which integration the URL names: ?hook=<slug>, or the older ?provider=<code>.
$resolve = static function (): ?array {
    $slug = trim((string) ($_GET['hook'] ?? ''));
    if ($slug !== '') {
        return shipping_provider_by_hook($slug);
    }
    $asked = strtolower(trim((string) ($_GET['provider'] ?? '')));
    if (preg_match('/^[a-z0-9_-]{2,40}$/', $asked) !== 1) {
        return null;
    }
    return shipping_provider($asked);
};

// 1. Method -----------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    $refuse(405, 'Non-POST request rejected.');
}

// 2. Raw body ---------------------------------------------------------------
//    Before the URL is resolved, so a malformed request is answered the same
//    whichever courier it names, or whether that courier exists at all.
//    Signatures are over the exact bytes received; re-encoding changes key
//    order and whitespace and the digest would never match.
$rawBody = (string) file_get_contents('php://input');
if ($rawBody === '') {
    $refuse(400, 'Empty body.');
}
if (strlen($rawBody) > 1048576) {
    $refuse(413, 'Body too large.', ['bytes' => strlen($rawBody)]);
}

$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    $refuse(400, 'Body is not JSON.');
}

// 3. Provider ---------------------------------------------------------------
//    A decommissioned integration's old URL must be inert. `status` is not
//    checked, on purpose: see the header. All three refusals below are the
//    same 401 to the caller and told apart only in the log.
$provider = $resolve();
if ($provider === null || !ShippingProviderFactory::implemented((string) $provider['code'])) {
    $refuse(401, 'Unknown provider.', ['hook' => mb_substr((string) ($_GET['hook'] ?? $_GET['provider'] ?? ''), 0, 40)]);
}
$code = (string) $provider['code'];

if ((int) $provider['webhook_enabled'] !== 1) {
    $refuse(401, 'Webhooks are not enabled for this provider.', ['provider' => $code]);
}
if (shipping_webhook_secret($provider) === '') {
    $refuse(401, 'No usable webhook secret (none saved, or it no longer decrypts); refusing unsigned updates.', ['provider' => $code]);
}

// 4. Headers ----------------------------------------------------------------
$headers = [];
foreach ($_SERVER as $key => $value) {
    if (strpos($key, 'HTTP_') === 0) {
        $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = (string) $value;
    }
}

// 5. Verify and normalise, in the driver ------------------------------------
//    Past this line the call has proved it holds the secret, and nothing below
//    may refuse it for the noise somebody else made from the same address.
$driver = ShippingProviderFactory::make($provider);
try {
    $parsed = $driver->parseWebhook($payload, $headers, $rawBody);
} catch (Throwable $e) {
    $respond(500, 'Driver error.', ['provider' => $code, 'error' => $e->getMessage()]);
}

if (empty($parsed['ok'])) {
    // The driver refuses for a bad signature and for a malformed payload. Both
    // are the same refusal here: telling a caller which it was helps it forge
    // the next one. The caller is anonymous, so the body is theirs to choose:
    // keep a prefix, its size and a hash - enough to recognise a courier's
    // malformed push, not enough to let a stranger fill the database.
    $refuse(401, 'Webhook refused.', ['provider' => $code, 'reason' => $parsed['message'] ?? ''], [
        'ok'       => false,
        'request'  => $rawBody,
        'max_body' => 2048,
        'error'    => sprintf('%s (%d bytes, sha256 %s)', (string) ($parsed['message'] ?? 'refused'),
            strlen($rawBody), substr(hash('sha256', $rawBody), 0, 16)),
    ]);
}

// 6. Resolve the shipment and record ----------------------------------------
try {
    $result = shipping_handle_update($code, $parsed, 'webhook');
} catch (Throwable $e) {
    // A 500 makes the courier retry, which is what we want for our own failure.
    $respond(500, 'Could not record the update.', ['provider' => $code, 'awb' => $parsed['awb'] ?? '', 'error' => $e->getMessage()]);
}

if (!$result['found']) {
    // Not ours, or not yet recorded. 200 anyway: a courier retrying an AWB we
    // will never know about achieves nothing but load.
    shipping_log($code, 'webhook', ['ok' => true, 'request' => $rawBody, 'error' => 'unknown shipment, ignored']);
    $respond(200, 'Unknown shipment ignored.', ['provider' => $code, 'awb' => $parsed['awb'] ?? '']);
}

shipping_log($code, 'webhook', [
    'shipment_id' => $result['shipment_id'],
    'ok'          => true,
    'request'     => $rawBody,
    'error'       => $result['added'] === 0 ? 'duplicate, no change' : null,
]);

$respond(200, 'Recorded.');
