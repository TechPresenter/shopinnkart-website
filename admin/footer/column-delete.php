<?php
/**
 * ShopInnKart Admin - Delete a footer column.
 *
 * fk_footerlink_column cascades, so the column's links go with it. The confirm
 * text on the builder says how many; this counts them for the audit entry.
 *
 * POST + CSRF + permission, enforced by admin_require_action().
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require_action('homepage.edit');

$id = input_int('id');
$column = $id > 0
    ? Database::fetch('SELECT `id`, `title` FROM `footer_columns` WHERE `id` = :id', ['id' => $id])
    : null;

if ($column === null) {
    flash('error', 'That footer column no longer exists.');
    redirect(admin_url('footer/'));
}

$linkCount = Database::count('footer_links', '`column_id` = :id', ['id' => $id]);

Database::delete('footer_columns', '`id` = :id', ['id' => $id]);

log_activity('footer_column.deleted', 'footer_column', $id,
    'Deleted footer column "' . $column['title'] . '"'
    . ($linkCount > 0 ? ' with ' . $linkCount . ' link(s)' : ''));
admin_after_write();

flash('success', 'Footer column "' . $column['title'] . '" deleted'
    . ($linkCount > 0 ? ' along with ' . $linkCount . ' link(s).' : '.'));
redirect(admin_url('footer/'));
