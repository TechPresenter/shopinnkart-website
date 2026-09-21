<?php
/**
 * ShopInnKart Admin - Delete a testimonial.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require_action('homepage.edit');

$id = input_int('id');
$testimonial = $id > 0
    ? Database::fetch('SELECT `id`, `customer_name`, `avatar` FROM `testimonials` WHERE `id` = :id', ['id' => $id])
    : null;

if ($testimonial === null) {
    flash('error', 'That testimonial no longer exists.');
    redirect(admin_url('testimonials/'));
}

// The row owns its avatar file, so the upload goes with it.
delete_upload($testimonial['avatar']);
Database::delete('testimonials', '`id` = :id', ['id' => $id]);

log_activity('testimonial.deleted', 'testimonial', $id,
    'Deleted testimonial from "' . $testimonial['customer_name'] . '"');
admin_after_write();

flash('success', 'Testimonial from "' . $testimonial['customer_name'] . '" deleted.');
redirect(admin_url('testimonials/'));
