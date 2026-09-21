<?php
/**
 * ShopInnKart Admin - Delete a homepage section.
 *
 * POST + CSRF only. The row owns its uploaded artwork, so those files go too.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require_action('homepage.edit');

require_once __DIR__ . '/_meta.php';

$id = input_int('id');
$section = $id > 0
    ? Database::fetch(
        'SELECT `id`, `section_key`, `zone`, `widget_type`, `image`, `mobile_image` FROM `homepage_sections` WHERE `id` = :id',
        ['id' => $id]
    )
    : null;

if ($section === null) {
    flash('error', 'That section no longer exists.');
    redirect(admin_url('homepage/'));
}

delete_upload($section['image']);
delete_upload($section['mobile_image']);

Database::delete('homepage_sections', '`id` = :id', ['id' => $id]);

log_activity('homepage_section.deleted', 'homepage_section', $id,
    'Deleted ' . homepage_widget_label((string) $section['widget_type']) . ' section "'
    . $section['section_key'] . '" from the ' . $section['zone'] . ' zone');
admin_after_write();

flash('success', 'Section "' . $section['section_key'] . '" deleted.');
redirect(admin_url('homepage/?zone=' . urlencode((string) $section['zone'])));
