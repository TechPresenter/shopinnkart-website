<?php
/**
 * ShopInnKart Admin - Delete one floating action button.
 *
 * Nothing else references the row, so this is a plain delete. A shipped button
 * can be deleted like any other; it comes back only by re-seeding.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require_action('homepage.edit');

$listUrl = admin_url('floating/');
$id = input_int('id');

$button = $id > 0
    ? Database::fetch('SELECT `id`, `label` FROM `floating_buttons` WHERE `id` = :id', ['id' => $id])
    : null;

if ($button === null) {
    flash('error', 'That floating button no longer exists.');
    redirect($listUrl);
}

Database::delete('floating_buttons', '`id` = :id', ['id' => $id]);

log_activity('floating_button.deleted', 'floating_button', $id, 'Deleted floating button "' . $button['label'] . '"');
admin_after_write();

flash('success', 'Floating button "' . $button['label'] . '" deleted.');
redirect($listUrl);
