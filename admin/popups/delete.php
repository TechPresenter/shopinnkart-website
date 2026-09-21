<?php
/**
 * ShopInnKart Admin - Delete a popup.
 *
 * Nothing references a popup row, so the delete is unconditional — but the
 * uploaded images belong to it and would otherwise be orphaned in /uploads.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require_action('banners.delete');

require_once __DIR__ . '/_shared.php';

$id = input_int('id');
$popup = $id > 0
    ? Database::fetch(
        'SELECT `id`, `name`, `display_mode`, `image`, `mobile_image`, `impressions`, `conversions`
         FROM `popups` WHERE `id` = :id',
        ['id' => $id]
    )
    : null;

if ($popup === null) {
    flash('error', 'That popup no longer exists.');
    redirect(admin_url('popups/'));
}

$mode = (string) $popup['display_mode'];

delete_upload($popup['image']);
delete_upload($popup['mobile_image']);
Database::delete('popups', '`id` = :id', ['id' => $id]);

log_activity(
    'popup.deleted',
    'popup',
    $id,
    'Deleted ' . $mode . ' "' . $popup['name'] . '" ('
        . (int) $popup['impressions'] . ' impressions, ' . (int) $popup['conversions'] . ' conversions)'
);
admin_after_write();

flash('success', 'Popup "' . $popup['name'] . '" deleted.');
redirect(admin_url('popups/?mode=' . urlencode($mode)));
