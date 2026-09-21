<?php
/**
 * ShopInnKart Admin - Delete an FAQ entry.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require_action('faq.delete');

$id = input_int('id');
$faq = $id > 0
    ? Database::fetch('SELECT `id`, `category`, `question` FROM `faqs` WHERE `id` = :id', ['id' => $id])
    : null;

if ($faq === null) {
    flash('error', 'That question no longer exists.');
    redirect(admin_url('faq/'));
}

Database::delete('faqs', '`id` = :id', ['id' => $id]);

log_activity('faq.deleted', 'faq', $id,
    'Deleted FAQ "' . str_limit((string) $faq['question'], 60) . '" from ' . $faq['category']);
admin_after_write();

flash('success', 'Question deleted.');
redirect(admin_url('faq/'));
