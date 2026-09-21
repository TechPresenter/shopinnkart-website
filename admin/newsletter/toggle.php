<?php
/**
 * ShopInnKart Admin - Activate or unsubscribe a newsletter address.
 *
 * Unsubscribing keeps the row: the address has to stay on record so a later
 * sign-up form cannot silently re-add someone who opted out.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require_action('newsletter.edit');

$id     = input_int('id');
$action = (string) input('action', '');

if (!in_array($action, ['activate', 'unsubscribe'], true)) {
    flash('error', 'That action is not recognised.');
    redirect_back(admin_url('newsletter/'));
}

$subscriber = $id > 0
    ? Database::fetch('SELECT `id`, `email`, `status` FROM `newsletter_subscribers` WHERE `id` = :id', ['id' => $id])
    : null;

if ($subscriber === null) {
    flash('error', 'That subscriber no longer exists.');
    redirect_back(admin_url('newsletter/'));
}

$newStatus = $action === 'activate' ? 'active' : 'unsubscribed';

if ($subscriber['status'] === $newStatus) {
    flash('info', $subscriber['email'] . ' was already ' . $newStatus . '.');
    redirect_back(admin_url('newsletter/'));
}

Database::update('newsletter_subscribers', ['status' => $newStatus], '`id` = :id', ['id' => $id]);

log_activity('newsletter.' . $action, 'newsletter_subscriber', $id,
    ($action === 'activate' ? 'Re-activated ' : 'Unsubscribed ') . $subscriber['email']);
admin_after_write();

flash('success', $action === 'activate'
    ? $subscriber['email'] . ' is subscribed again.'
    : $subscriber['email'] . ' has been unsubscribed.');
redirect_back(admin_url('newsletter/'));
