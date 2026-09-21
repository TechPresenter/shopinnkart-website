<?php
/**
 * ShopInnKart Admin - Delete a contact message.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require_action('customers.delete');

$id = input_int('id');
$message = $id > 0
    ? Database::fetch('SELECT `id`, `name`, `email` FROM `contact_messages` WHERE `id` = :id', ['id' => $id])
    : null;

if ($message === null) {
    flash('error', 'That message no longer exists.');
    redirect(admin_url('messages/'));
}

Database::delete('contact_messages', '`id` = :id', ['id' => $id]);

log_activity('contact_message.deleted', 'contact_message', $id,
    'Deleted message from ' . $message['email']);
admin_after_write();

flash('success', 'Message from "' . $message['name'] . '" deleted.');

// Going "back" to view.php would land on a row that no longer exists.
if (strpos((string) ($_SERVER['HTTP_REFERER'] ?? ''), 'messages/view.php') !== false) {
    redirect(admin_url('messages/'));
}
redirect_back(admin_url('messages/'));
