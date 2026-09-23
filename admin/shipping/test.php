<?php
/**
 * ShopInnKart Admin - Test one courier integration.
 *
 * Runs the driver's own testConnection() against the STORED credentials, not
 * whatever is currently typed into the form - a test that passes on unsaved
 * values tells the operator nothing about what a booking will do.
 *
 * The outcome is written to the provider row so the list can show health
 * without re-testing on every page view.
 *
 * Permission: settings.edit, like the rest of courier configuration - the test
 * logs in with the stored credentials and rewrites the row's health.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require_action('settings.edit');

require_once INCLUDES_PATH . '/shipping-functions.php';

$code     = (string) request_input('code', '');
$provider = $code === '' ? null : shipping_provider($code);

if ($provider === null) {
    json_error('That integration no longer exists.', [], 404);
}

$driver = ShippingProviderFactory::make($provider);
if ($driver === null) {
    json_error('No driver is installed for ' . $code . '.', [], 422);
}

$started = microtime(true);

try {
    $result = $driver->testConnection();
} catch (Throwable $e) {
    // A driver that throws is a failed test, not a 500 - the operator needs
    // the message, not a stack trace.
    $result = ['ok' => false, 'message' => 'Driver error: ' . $e->getMessage()];
}

$ok      = !empty($result['ok']);
$message = mb_substr((string) ($result['message'] ?? ''), 0, 255);
$ms      = (int) round((microtime(true) - $started) * 1000);

Database::update('shipping_providers', [
    'last_checked_at' => date('Y-m-d H:i:s'),
    'last_status'     => $ok ? 'ok' : 'failed',
    'last_message'    => $message,
], '`id` = :id', ['id' => (int) $provider['id']]);

shipping_log($code, 'test', [
    'ok'          => $ok,
    'duration_ms' => $ms,
    'response'    => $result,
    'error'       => $ok ? null : $message,
]);

admin_after_write();

if (!$ok) {
    json_error($message !== '' ? $message : 'Connection failed.', [], 422);
}

json_success($message !== '' ? $message : 'Connected.', ['duration_ms' => $ms]);
