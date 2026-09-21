<?php
/**
 * ShopInnKart Admin - Flip one boolean-ish field on a homepage section.
 *
 * Backs the switches on the builder list ([data-toggle-endpoint]). Answers
 * JSON so the list keeps its scroll position instead of reloading.
 * POST + CSRF, enforced by admin_require_action().
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require_action('homepage.edit');

$id    = request_int('id');
$field = (string) request_input('field', 'status');
$value = request_bool('value');

// Allowlist: the field name is interpolated into the UPDATE by Database::update().
$allowed = ['status', 'lazy_load', 'autoplay', 'show_arrows', 'show_dots'];
if (!in_array($field, $allowed, true)) {
    json_error('That field cannot be toggled from here.', [], 422);
}

$section = $id > 0
    ? Database::fetch('SELECT `id`, `section_key` FROM `homepage_sections` WHERE `id` = :id', ['id' => $id])
    : null;

if ($section === null) {
    json_error('That section no longer exists.', [], 404);
}

$stored = $field === 'status' ? ($value ? 'active' : 'inactive') : ($value ? 1 : 0);

Database::update('homepage_sections', [$field => $stored], '`id` = :id', ['id' => $id]);

log_activity('homepage_section.toggled', 'homepage_section', $id,
    'Set ' . $field . ' of "' . $section['section_key'] . '" to ' . $stored);
admin_after_write();

$message = $field === 'status'
    ? ($value ? 'Section enabled.' : 'Section disabled.')
    : 'Section updated.';

json_success($message, ['id' => $id, 'field' => $field, 'value' => $stored]);
