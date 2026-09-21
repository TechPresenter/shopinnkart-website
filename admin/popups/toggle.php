<?php
/**
 * ShopInnKart Admin - Enable/disable switch for a popup.
 *
 * Driven by [data-toggle-endpoint] in assets/js/admin.js, which POSTs JSON.
 * The same endpoint answers a plain form POST so the switch still works with
 * JavaScript unavailable — the JSON branch only fires for AJAX callers.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require_action('banners.edit');

require_once __DIR__ . '/_shared.php';

// Only columns that are safe to flip from a list row.
$toggleable = ['status', 'show_close'];

$id    = request_int('id');
$field = (string) request_input('field', 'status');
$on    = request_bool('value');

if (!in_array($field, $toggleable, true)) {
    if (is_ajax()) {
        json_error('That field cannot be toggled.', [], 422);
    }
    flash('error', 'That field cannot be toggled.');
    redirect_back(admin_url('popups/'));
}

$popup = $id > 0
    ? Database::fetch('SELECT `id`, `name`, `display_mode` FROM `popups` WHERE `id` = :id', ['id' => $id])
    : null;

if ($popup === null) {
    if (is_ajax()) {
        json_error('That popup no longer exists.', [], 404);
    }
    flash('error', 'That popup no longer exists.');
    redirect(admin_url('popups/'));
}

$value = $field === 'status' ? ($on ? 'active' : 'inactive') : ($on ? 1 : 0);

Database::update('popups', [$field => $value], '`id` = :id', ['id' => $id]);

$description = $field === 'status'
    ? ($on ? 'Enabled' : 'Disabled') . ' ' . $popup['display_mode'] . ' "' . $popup['name'] . '"'
    : ($on ? 'Showed' : 'Hid') . ' the close button on "' . $popup['name'] . '"';

log_activity('popup.toggled', 'popup', $id, $description);
admin_after_write();

if (is_ajax()) {
    json_success($description . '.', ['id' => $id, 'field' => $field, 'value' => $value]);
}

flash('success', $description . '.');
redirect_back(admin_url('popups/'));
