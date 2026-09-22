<?php
/**
 * POST /api/shipping/webhook.php?provider=shiprocket
 *
 * The one URL a courier may call to push a status change. Unauthenticated by
 * nature, so it follows the payment webhook's contract exactly
 * (api/payments/webhook.php) and fails closed at every step:
 *
 *   unknown provider, provider switched off, webhooks not enabled, no secret
 *   configured, bad signature, empty or oversized body, unparseable JSON
 *
 * are all refusals. CSRF is deliberately not enforced - a courier cannot hold a
 * token, and the signature is the stronger proof.
 *
 * A provider with no webhook secret is refused even if its driver would accept
 * an unsigned call. Otherwise anybody who guessed the URL could mark any order
 * delivered, and delivered is where a COD order is marked paid.
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
require_once INCLUDES_PATH . '/webhook-functions.php';
require_once INCLUDES_PATH . '/shipping-service.php';

$respond = static function (int $status, string $message, array $context = []): void {
    if ($context !== []) {
        ErrorHandler::log(
            $status >= 500 || $status === 401 ? 'warning' : 'info',
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

// 1. Method -----------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    $respond(405, 'Non-POST request rejected.');
}

$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');

// 2. Rate limit, before any parsing work. IP-keyed: a courier carries no
//    session, so the session-based api_rate_limit() would never trip.
if (!webhook_rate_limit_hit('ship-ip:' . $ip, 120, 60)) {
    if (!headers_sent()) {
        header('Retry-After: 60');
    }
    $respond(429, 'Rate limited.', ['ip' => $ip]);
}

// 3. Provider ---------------------------------------------------------------
$code = strtolower(trim((string) ($_GET['provider'] ?? '')));
if ($code === '' || preg_match('/^[a-z0-9_-]{2,40}$/', $code) !== 1) {
    $respond(404, 'Unknown provider.', ['ip' => $ip]);
}

$provider = shipping_provider($code);
if ($provider === null || !ShippingProviderFactory::implemented($code)) {
    $respond(404, 'Unknown provider.', ['provider' => $code, 'ip' => $ip]);
}

// A decommissioned integration's old URL must be inert.
if ($provider['status'] !== 'active' || (int) $provider['webhook_enabled'] !== 1) {
    $respond(403, 'Webhooks are not enabled for this provider.', ['provider' => $code, 'ip' => $ip]);
}
if ((string) ($provider['webhook_secret'] ?? '') === '') {
    $respond(403, 'No webhook secret configured; refusing unsigned updates.', ['provider' => $code, 'ip' => $ip]);
}

// 4. Raw body ---------------------------------------------------------------
//    Signatures are over the exact bytes received; re-encoding changes key
//    order and whitespace and the digest would never match.
$rawBody = (string) file_get_contents('php://input');
if ($rawBody === '') {
    $respond(400, 'Empty body.', ['provider' => $code, 'ip' => $ip]);
}
if (strlen($rawBody) > 1048576) {
    $respond(413, 'Body too large.', ['provider' => $code, 'bytes' => strlen($rawBody)]);
}

$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    $respond(400, 'Body is not JSON.', ['provider' => $code, 'ip' => $ip]);
}

$headers = [];
foreach ($_SERVER as $key => $value) {
    if (strpos($key, 'HTTP_') === 0) {
        $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = (string) $value;
    }
}

// 5. Verify and normalise, in the driver ------------------------------------
$driver = ShippingProviderFactory::make($provider);
try {
    $parsed = $driver->parseWebhook($payload, $headers, $rawBody);
} catch (Throwable $e) {
    $respond(500, 'Driver error.', ['provider' => $code, 'error' => $e->getMessage()]);
}

if (empty($parsed['ok'])) {
    // The driver refuses for a bad signature and for a malformed payload. Both
    // are 401 here: telling a caller which it was helps it forge the next one.
    shipping_log($code, 'webhook', ['ok' => false, 'request' => $rawBody, 'error' => (string) ($parsed['message'] ?? 'refused')]);
    $respond(401, 'Webhook refused.', ['provider' => $code, 'ip' => $ip, 'reason' => $parsed['message'] ?? '']);
}

// 6. Resolve the shipment ---------------------------------------------------
$shipment = shipment_find($code, (string) ($parsed['awb'] ?? ''), (string) ($parsed['shipment_ref'] ?? ''));
if ($shipment === null) {
    // Not ours, or not yet recorded. 200 anyway: a courier retrying an AWB we
    // will never know about achieves nothing but load.
    shipping_log($code, 'webhook', ['ok' => true, 'request' => $rawBody, 'error' => 'unknown shipment, ignored']);
    $respond(200, 'Unknown shipment ignored.', ['provider' => $code, 'awb' => $parsed['awb'] ?? '']);
}

// 7. Record -----------------------------------------------------------------
try {
    $added = shipping_record_events((int) $shipment['id'], (array) ($parsed['events'] ?? []), 'webhook');
} catch (Throwable $e) {
    // A 500 makes the courier retry, which is what we want for our own failure.
    $respond(500, 'Could not record the update.', ['provider' => $code, 'shipment' => $shipment['id'], 'error' => $e->getMessage()]);
}

shipping_log($code, 'webhook', [
    'shipment_id' => (int) $shipment['id'],
    'ok'          => true,
    'request'     => $rawBody,
    'error'       => $added === 0 ? 'duplicate, no change' : null,
]);

$respond(200, 'Recorded.');
