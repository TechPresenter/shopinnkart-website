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
// Per client address, not per session: the session counter reset itself
// whenever the sender dropped the cookie, which made this form a bulk sender.
api_rate_limit('contact_send', 5, 3600);

// Honeypot: a field no human sees and every form-filling bot completes.
if (trim((string) request_input('website', '')) !== '') {
    security_event('api.honeypot', 'low', ['endpoint' => 'contact']);
    json_success('Thanks for writing in. Our support team replies within one business day.', [], 201);
}

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

/**
 * Acknowledge to the customer so a message never disappears into silence.
 *
 * The acknowledgement goes to an address a stranger typed, so it must not
 * carry anything that stranger wrote: reflecting the subject and body turned
 * this form into a sender of store-branded mail whose content the sender
 * chose, to any inbox they chose. Only someone writing from the address on
 * their own signed-in account gets their words quoted back.
 */
$signedIn  = current_user();
$ownsInbox = $signedIn !== null && hash_equals(mb_strtolower((string) $signedIn['email']), $email);

try {
    notify('contact_received', $email, [
        'customer_name'  => $ownsInbox ? $name : 'there',
        'customer_email' => $email,
        'subject'        => $ownsInbox ? $subject : 'your message to our support team',
        'message'        => $ownsInbox
            ? $message   // notify() escapes and line-breaks it
            : 'Your message has reached our support team and someone will reply within one business day. '
              . 'If you did not write to us, you can ignore this email - nothing has been sent on your behalf.',
    ], 'contact_message', $messageId, 'email', [
        'email_type'     => 'contact_received',
        // The display name on the envelope is free text from the form too.
        'recipient_name' => $ownsInbox ? $name : null,
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
