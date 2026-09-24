<?php
/**
 * ShopInnKart - Newsletter subscribe.
 *
 * The footer, the exit popup and the account page all post here, so the
 * response has to read sensibly in a toast for all three: a fresh signup, a
 * repeat signup and a returning unsubscriber each get their own message.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/init.php';

api_require_method(['POST']);
api_require_csrf();
// Keyed on the client address, not the session: a subscriber who drops their
// cookie used to get a fresh allowance (and a fresh welcome mail) every time.
api_rate_limit('newsletter_subscribe', 6, 300);

$v = new Validator(request_all(), ['email' => 'Email address', 'name' => 'Name']);
$v->required('email')->email('email')->max('email', 190)
  ->max('name', 150);

if ($v->fails()) {
    json_validation_error($v->errors());
}

// Stored lowercase so the unique index catches "Me@x.com" vs "me@x.com".
$email = mb_strtolower(trim((string) request_input('email', '')));
$name  = trim((string) request_input('name', ''));

// The source is a form identifier from data-newsletter-form, never free text.
$source = strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '', (string) request_input('source', '')) ?? '');
$source = $source === '' ? 'website' : mb_substr($source, 0, 60);

/**
 * One answer for every state.
 *
 * The three distinguishable replies ("already on the list", "welcome back",
 * "you are subscribed", plus an already_subscribed flag) told anybody who
 * could type an address whether it was on the list - and whether it had ever
 * unsubscribed. The subscriber themselves loses nothing: what they wanted to
 * know is that the form worked.
 */
$confirmation = 'Thanks! If that address is not on the list already, offers and early access are on their way to it.';

// The honeypot and the per-address ceiling. A script stuffing the list with
// addresses it does not own is what turns a newsletter into a spam complaint
// against our sending domain. Caught submissions get $confirmation like
// everybody else - this form's whole design is that every state reads alike.
bot_guard_api('newsletter', ['key' => $email, 'quiet_success' => $confirmation]);

$existing = Database::fetch(
    'SELECT `id`, `name`, `status` FROM `newsletter_subscribers` WHERE `email` = :email LIMIT 1',
    ['email' => $email]
);

if ($existing !== null && $existing['status'] === 'active') {
    json_success($confirmation, ['email' => $email, 'status' => 'active']);
}

if ($existing !== null) {
    Database::update('newsletter_subscribers', [
        'status'     => 'active',
        'source'     => $source,
        'ip_address' => client_ip(),
        'name'       => $name === '' ? ($existing['name'] ?? null) : $name,
    ], '`id` = :id', ['id' => (int) $existing['id']]);

    // A resubscribe counts: the label is where the form was, never the
    // address, which is the subscriber's and belongs only in the table above.
    analytics_track('newsletter_signup', ['label' => $source]);

    json_success($confirmation, ['email' => $email, 'status' => 'active']);
}

Database::insert('newsletter_subscribers', [
    'email'      => $email,
    'name'       => $name === '' ? null : $name,
    'source'     => $source,
    'ip_address' => client_ip(),
    'status'     => 'active',
]);

analytics_track('newsletter_signup', ['label' => $source]);

// Only genuinely new subscribers get the welcome mail; reactivations already had it.
notify_newsletter_welcome($email);

json_success($confirmation, ['email' => $email, 'status' => 'active'], 201);
