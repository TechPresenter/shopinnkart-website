<?php
/**
 * ShopInnKart Admin - Flip a shipping integration on or off.
 *
 * Answers JSON so the list keeps its scroll position. Mirrors
 * admin/homepage/toggle.php, including the field allowlist: the name is
 * interpolated into the UPDATE by Database::update().
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require_action('orders.edit');

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

// Switching on a courier with no driver would offer a booking that must fail.
if ($field === 'status' && $value && !ShippingProviderFactory::implemented((string) $provider['code'])) {
    json_error('No driver is installed for ' . $provider['code'] . '.', [], 422);
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

json_success('Updated.', ['id' => $id, 'field' => $field, 'value' => $stored]);
