<?php
/**
 * ShopInnKart Admin - Save one category's sort order.
 *
 * Backs the number input on the tree screen. It answers JSON because the tree
 * would lose its scroll position on a full page reload after every nudge.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require_action('categories.edit');

$id        = request_int('id');
$sortOrder = request_int('sort_order', -1);

if ($id <= 0) {
    json_error('Missing category id.', [], 422);
}
if ($sortOrder < 0 || $sortOrder > 9999) {
    json_validation_error(['sort_order' => 'Sort order must be between 0 and 9999.']);
}

$name = Database::fetchColumn('SELECT `name` FROM `categories` WHERE `id` = :id', ['id' => $id]);
if ($name === null) {
    json_error('That category no longer exists.', [], 404);
}

Database::update('categories', ['sort_order' => $sortOrder], '`id` = :id', ['id' => $id]);

log_activity('category.reordered', 'category', $id, 'Set sort order of "' . $name . '" to ' . $sortOrder);
admin_after_write();

json_success('Order saved.', ['id' => $id, 'sort_order' => $sortOrder]);
