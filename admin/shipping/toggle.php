<?php
/**
 * ShopInnKart Admin - Flip a shipping integration on or off.
 *
 * Answers JSON so the list keeps its scroll position. Mirrors
 * admin/homepage/toggle.php, including the field allowlist: the name is
 * interpolated into the UPDATE by Database::update().
 *
 * Permission: settings.edit, the key payment gateway and SMTP credentials
 * already use. Switching a courier on, or its webhooks, is configuration of an
 * account that books billed consignments and can mark COD orders paid - not
 * order processing, so orders.edit is not enough.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require_action('settings.edit');

require_once INCLUDES_PATH . '/shipping-functions.php';

$id    = request_int('id');
$field = (string) request_input('field', 'status');
$value = request_bool('value');

$allowed = ['status', 'is_default', 'supports_cod', 'supports_return', 'webhook_enabled'];
if (!in_array($field, $allowed, true)) {
    json_error('That field cannot be toggled from here.', [], 422);
}

$provider = $id > 0
    ? Database::fetch('SELECT * FROM `shipping_providers` WHERE `id` = :id', ['id' => $id])
    : null;

if ($provider === null) {
    json_error('That integration no longer exists.', [], 404);
}

$message = 'Updated.';

if ($field === 'status' && $value) {
    // Switching on a courier with no driver would offer a booking that must fail.
    $driver = ShippingProviderFactory::make($provider);
    if ($driver === null) {
        json_error('No driver is installed for ' . $provider['code'] . '.', [], 422);
    }

    // The same rule configure.php applies on save. Without it the list switch
    // made an unconfigured courier "active": offered for booking, failing every
    // quote with a login error.
    if (shipping_credentials_unreadable($provider)) {
        json_error('The saved credentials for ' . $provider['name'] . ' can no longer be read (the application key changed). '
            . 'Enter them again on the Configure page, then switch it on.', [], 422);
    }
    $creds   = shipping_credentials($provider);
    $missing = [];
    foreach ($driver->credentialFields() as $key => $meta) {
        if (!empty($meta['required']) && trim((string) ($creds[$key] ?? '')) === '') {
            $missing[] = (string) ($meta['label'] ?? $key);
        }
    }
    if ($missing !== []) {
        json_error('Enter ' . implode(', ', $missing) . ' on the Configure page before switching ' . $provider['name'] . ' on.', [], 422);
    }
}

if ($field === 'status' && !$value) {
    // Worth saying out loud: "off" stops new bookings only. Consignments
    // already out keep being tracked (webhook and poll), which is what settles
    // their COD and returns.
    $out = (int) Database::fetchColumn(
        "SELECT COUNT(*) FROM `shipments`
          WHERE `provider_code` = :c AND `awb` IS NOT NULL AND `awb` <> ''
            AND `status` NOT IN ('delivered','rto_delivered','returned','cancelled','failed_booking')",
        ['c' => (string) $provider['code']]
    );
    if ($out > 0) {
        $message = 'Switched off for new bookings. ' . $out . ' consignment' . ($out === 1 ? '' : 's')
            . ' already out with it will keep being tracked.';
    }
}

if ($field === 'webhook_enabled' && $value && shipping_webhook_secret($provider) === '') {
    $message = 'Webhooks on, but no usable signing secret is saved, so every call will be refused until one is.';
}

$stored = $field === 'status' ? ($value ? 'active' : 'inactive') : ($value ? 1 : 0);

Database::transaction(static function () use ($field, $stored, $id, $value): void {
    // Only one default courier, or a booking has to guess which to use.
    if ($field === 'is_default' && $value) {
        Database::query('UPDATE `shipping_providers` SET `is_default` = 0 WHERE `id` <> :id', ['id' => $id]);
    }
    Database::update('shipping_providers', [$field => $stored], '`id` = :id', ['id' => $id]);
});

log_activity('shipping_provider.toggled', 'shipping_provider', $id,
    'Set ' . $field . ' of "' . $provider['code'] . '" to ' . $stored);
admin_after_write();

json_success($message, ['id' => $id, 'field' => $field, 'value' => $stored]);
