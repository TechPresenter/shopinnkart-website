<?php
/**
 * ShopInnKart - Contact form submission.
 *
 * Public and unauthenticated, so the rate limit is deliberately tight.
 * The message is always stored; the admin alert mail is best-effort.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/init.php';

api_require_method(['POST']);
api_require_csrf();
api_rate_limit('contact_send', 3, 900);

$v = new Validator(request_all(), [
    'name'    => 'Name',
    'email'   => 'Email address',
    'phone'   => 'Phone number',
    'subject' => 'Subject',
    'message' => 'Message',
]);
$v->required('name')->min('name', 2)->max('name', 150)
  ->required('email')->email('email')->max('email', 190)
  ->phone('phone')
  ->required('subject')->min('subject', 3)->max('subject', 200)
  ->required('message')->min('message', 10)->max('message', 5000);

if ($v->fails()) {
    json_validation_error($v->errors());
}

$name    = (string) request_input('name', '');
$email   = mb_strtolower(trim((string) request_input('email', '')));
$phone   = normalize_phone((string) request_input('phone', ''));
$subject = (string) request_input('subject', '');
$message = (string) request_input('message', '');

$messageId = Database::insert('contact_messages', [
    'name'       => $name,
    'email'      => $email,
    'phone'      => $phone,
    'subject'    => $subject,
    'message'    => $message,
    'status'     => 'new',
    'ip_address' => client_ip(),
]);

// There is no contact_message template out of the box. Queue the alert only
// when an admin has created one - a missing template must not fail the
// customer's request, since the message is already safely stored.
$adminEmail = (string) setting('admin_notify_email', (string) setting('store_email', ''));
if ($adminEmail !== '') {
    try {
        $hasTemplate = Database::exists(
            'notification_templates',
            "`template_key` = :key AND `channel` = 'email' AND `status` = 'active'",
            ['key' => 'contact_message']
        );
        if ($hasTemplate) {
            notify('contact_message', $adminEmail, [
                'customer_name'  => $name,
                'customer_email' => $email,
                'customer_phone' => $phone ?? '-',
                'subject'        => $subject,
                'message'        => $message,   // notify() escapes and line-breaks it
                'submitted_at'   => format_datetime(date('Y-m-d H:i:s')),
            ], 'contact_message', $messageId, 'email', [
                'email_type'     => 'admin_contact_enquiry',
                'recipient_type' => 'admin',
            ]);
        }
    } catch (Throwable $e) {
        ErrorHandler::log('warning', 'Contact alert failed: ' . $e->getMessage());
    }
}

// Acknowledge to the customer so a message never disappears into silence.
try {
    notify('contact_received', $email, [
        'customer_name'  => $name,
        'customer_email' => $email,
        'subject'        => $subject,
        'message'        => $message,   // notify() escapes and line-breaks it
    ], 'contact_message', $messageId, 'email', [
        'email_type'     => 'contact_received',
        'recipient_name' => $name,
        // One acknowledgement per stored message, so a double-submitted form
        // does not thank the customer twice.
        'idempotency_key' => 'contact_received:' . $messageId,
    ]);
} catch (Throwable $e) {
    ErrorHandler::log('warning', 'Contact acknowledgement failed: ' . $e->getMessage());
}

json_success('Thanks for writing in. Our support team replies within one business day.', [
    'message_id' => $messageId,
], 201);
