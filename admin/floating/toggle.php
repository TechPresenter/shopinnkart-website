<?php
/**
 * ShopInnKart Admin - Enable/disable switch for one floating button.
 *
 * Driven by [data-toggle-endpoint] in assets/js/admin.js, which POSTs JSON.
 * The same endpoint answers a plain form POST so the switch still works with
 * JavaScript unavailable - the JSON branch only fires for AJAX callers. Same
 * contract as admin/popups/toggle.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require_action('homepage.edit');

// Only columns that are safe to flip from a list row.
$toggleable = ['status', 'show_label', 'open_new_tab'];

$id    = request_int('id');
$field = (string) request_input('field', 'status');
$on    = request_bool('value');

if (!in_array($field, $toggleable, true)) {
    if (is_ajax()) {
        json_error('That field cannot be toggled.', [], 422);
    }
    flash('error', 'That field cannot be toggled.');
    redirect_back(admin_url('floating/'));
}

$button = $id > 0
    ? Database::fetch('SELECT `id`, `label` FROM `floating_buttons` WHERE `id` = :id', ['id' => $id])
    : null;

if ($button === null) {
    if (is_ajax()) {
        json_error('That floating button no longer exists.', [], 404);
    }
    flash('error', 'That floating button no longer exists.');
    redirect(admin_url('floating/'));
}

$value = $field === 'status' ? ($on ? 'active' : 'inactive') : ($on ? 1 : 0);

Database::update('floating_buttons', [$field => $value], '`id` = :id', ['id' => $id]);

$description = $field === 'status'
    ? ($on ? 'Enabled' : 'Disabled') . ' floating button "' . $button['label'] . '"'
    : ($on ? 'Showed' : 'Hid') . ' the label on "' . $button['label'] . '"';

log_activity('floating_button.toggled', 'floating_button', $id, $description);
admin_after_write();

if (is_ajax()) {
    json_success($description . '.', ['id' => $id, 'field' => $field, 'value' => $value]);
}

flash('success', $description . '.');
redirect_back(admin_url('floating/'));
