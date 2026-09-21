<?php
/**
 * ShopInnKart Admin - Delete a newsletter subscriber.
 *
 * Deleting drops the opt-out record too, so the same address can be added back
 * by any sign-up form. Unsubscribe is the safer action almost every time.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require_action('newsletter.delete');

$id = input_int('id');
$subscriber = $id > 0
    ? Database::fetch('SELECT `id`, `email`, `status` FROM `newsletter_subscribers` WHERE `id` = :id', ['id' => $id])
    : null;

if ($subscriber === null) {
    flash('error', 'That subscriber no longer exists.');
    redirect_back(admin_url('newsletter/'));
}

Database::delete('newsletter_subscribers', '`id` = :id', ['id' => $id]);

log_activity('newsletter.deleted', 'newsletter_subscriber', $id,
    'Deleted subscriber ' . $subscriber['email']);
admin_after_write();

flash('success', $subscriber['email'] . ' removed from the list.');
redirect_back(admin_url('newsletter/'));
