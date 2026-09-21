<?php
/**
 * ShopInnKart Admin - Save one FAQ's sort order.
 *
 * Backs the number input on the grouped list. It answers JSON because a full
 * reload after every nudge would throw the admin back to the top of the page.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require_action('faq.edit');

$id        = request_int('id');
$sortOrder = request_int('sort_order', -1);

if ($id <= 0) {
    json_error('Missing FAQ id.', [], 422);
}
if ($sortOrder < 0 || $sortOrder > 9999) {
    json_validation_error(['sort_order' => 'Sort order must be between 0 and 9999.']);
}

$faq = Database::fetch('SELECT `question` FROM `faqs` WHERE `id` = :id', ['id' => $id]);
if ($faq === null) {
    json_error('That question no longer exists.', [], 404);
}

Database::update('faqs', ['sort_order' => $sortOrder], '`id` = :id', ['id' => $id]);

log_activity('faq.reordered', 'faq', $id,
    'Set sort order of "' . str_limit((string) $faq['question'], 60) . '" to ' . $sortOrder);
admin_after_write();

json_success('Order saved.', ['id' => $id, 'sort_order' => $sortOrder]);
