<?php
/**
 * ShopInnKart Admin - Delete a footer link.
 *
 * POST + CSRF + permission, enforced by admin_require_action().
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require_action('homepage.edit');

$id = input_int('id');
$link = $id > 0
    ? Database::fetch('SELECT `id`, `label`, `column_id` FROM `footer_links` WHERE `id` = :id', ['id' => $id])
    : null;

if ($link === null) {
    flash('error', 'That footer link no longer exists.');
    redirect(admin_url('footer/'));
}

Database::delete('footer_links', '`id` = :id', ['id' => $id]);

log_activity('footer_link.deleted', 'footer_link', $id, 'Deleted footer link "' . $link['label'] . '"');
admin_after_write();

flash('success', 'Link "' . $link['label'] . '" deleted.');
redirect(admin_url('footer/'));
