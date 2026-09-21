<?php
/**
 * ShopInnKart Admin - Delete a banner.
 *
 * A banner owns its uploaded files, so both images are removed from disk
 * before the row goes — nothing else references them.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require_action('banners.delete');

$id = input_int('id');
$banner = $id > 0
    ? Database::fetch(
        'SELECT `id`, `title`, `position`, `desktop_image`, `mobile_image` FROM `banners` WHERE `id` = :id',
        ['id' => $id]
    )
    : null;

if ($banner === null) {
    flash('error', 'That banner no longer exists.');
    redirect(admin_url('banners/'));
}

delete_upload($banner['desktop_image']);
delete_upload($banner['mobile_image']);
Database::delete('banners', '`id` = :id', ['id' => $id]);

$label = trim((string) ($banner['title'] ?? '')) !== '' ? (string) $banner['title'] : 'Banner #' . $id;

log_activity('banner.deleted', 'banner', $id, 'Deleted ' . $banner['position'] . ' banner "' . $label . '"');
admin_after_write();

flash('success', 'Banner "' . $label . '" deleted.');
redirect(admin_url('banners/?position=' . urlencode((string) $banner['position'])));
