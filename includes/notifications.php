<?php
/**
 * ShopInnKart - Channel-agnostic notifications.
 *
 * Application code calls notify_*() which queues a row. A separate dispatcher
 * drains the queue, so adding SMS or WhatsApp later means implementing one
 * more send_via_*() branch — nothing in checkout or the admin changes.
 */

declare(strict_types=1);

/**
 * Queue a notification built from a stored template.
 *
 * @param array $vars    {{placeholder}} => value
 * @param array $options email_type, recipient_type, recipient_name, user_id,
 *                       order_id, invoice_id, attach_invoice (bool),
 *                       idempotency_key (string), force (bool)
 */
function notify(
    string $templateKey,
    string $recipient,
    array $vars = [],
    ?string $referenceType = null,
    ?int $referenceId = null,
    string $channel = 'email',
    array $options = []
): bool {
    $recipient = trim($recipient);
    if ($recipient === '') {
        return false;
    }
    if ($channel === 'email' && !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        ErrorHandler::log('warning', 'Notification skipped (' . $templateKey . '): "' . mask_email($recipient) . '" is not a valid address.');
        return false;
    }

    $recipientType = (string) ($options['recipient_type'] ?? 'customer');

    try {
        $template = Database::fetch(
            "SELECT * FROM `notification_templates`
             WHERE `template_key` = :key AND `channel` = :channel AND `status` = 'active' LIMIT 1",
            ['key' => $templateKey, 'channel' => $channel]
        );

        if ($template === null) {
            // No template configured for this event - nothing to send.
            return false;
        }

        // A customer who has switched a category of mail off in their account
        // does not get it. Admin copies and account-security mail are exempt.
        if ($recipientType === 'customer'
            && !notification_allowed_for_user($templateKey, $options['user_id'] ?? null)) {
            return false;
        }

        $vars = array_merge(notification_default_vars(), $vars);

        // The queue's subject column is VARCHAR(255); a long rendered subject
        // used to throw and be swallowed, silently dropping the message.
        // The subject is a text header, so it takes the unescaped values; the
        // body is markup and takes the escaped ones.
        $subject = mb_substr(render_template((string) $template['subject'], template_text_vars($vars)), 0, 255);
        $bodyHtml = render_template((string) $template['body'], template_html_vars($vars));

        $bodyText = trim((string) ($template['body_text'] ?? ''));
        $bodyText = $bodyText === ''
            ? html_to_text($bodyHtml)
            : render_template($bodyText, template_text_vars($vars));

        $attachInvoice = (bool) ($options['attach_invoice'] ?? (int) ($template['attach_invoice'] ?? 0));

        Database::insert('notification_queue', [
            'channel'         => $channel,
            'template_key'    => $templateKey,
            'email_type'      => mb_substr((string) ($options['email_type'] ?? $templateKey), 0, 40),
            'recipient'       => mb_substr($recipient, 0, 190),
            'recipient_name'  => isset($options['recipient_name']) ? mb_substr((string) $options['recipient_name'], 0, 150) : null,
            'recipient_type'  => $recipientType === 'admin' ? 'admin' : 'customer',
            'subject'         => $subject,
            'body'            => $bodyHtml,
            'body_text'       => $bodyText,
            'reference_type'  => $referenceType,
            'reference_id'    => $referenceId,
            'user_id'         => isset($options['user_id']) ? (int) $options['user_id'] : null,
            'order_id'        => isset($options['order_id']) ? (int) $options['order_id'] : null,
            'invoice_id'      => isset($options['invoice_id']) ? (int) $options['invoice_id'] : null,
            // The path is resolved at send time, not now: the PDF may still be
            // rendering when the row is queued.
            'attachment_name' => $attachInvoice ? 'invoice' : null,
            'idempotency_key' => notification_idempotency_key($templateKey, $recipient, $options),
            'status'          => 'pending',
        ]);

        return true;
    } catch (PDOException $e) {
        // 23000 on uq_queue_idempotency means this exact message is already
        // queued or sent — a duplicate webhook, not a failure.
        if ($e->getCode() === '23000') {
            return true;
        }
        ErrorHandler::log('warning', 'Notification queue failed (' . $templateKey . '): ' . $e->getMessage());
        return false;
    } catch (Throwable $e) {
        ErrorHandler::log('warning', 'Notification queue failed (' . $templateKey . '): ' . $e->getMessage());
        return false;
    }
}

/**
 * The value stored in notification_queue.idempotency_key.
 *
 * Returning null opts a message out of duplicate protection, which is what a
 * deliberate manual resend needs.
 */
function notification_idempotency_key(string $templateKey, string $recipient, array $options): ?string
{
    if (!empty($options['force'])) {
        return null;
    }

    $explicit = trim((string) ($options['idempotency_key'] ?? ''));
    if ($explicit !== '') {
        return mb_substr($explicit, 0, 150);
    }

    // Automatic order mail is deduped per (event, order, recipient) so five
    // identical gateway callbacks produce one email.
    $orderId = (int) ($options['order_id'] ?? 0);
    if ($orderId > 0) {
        return mb_substr($templateKey . ':order:' . $orderId . ':' . strtolower($recipient), 0, 150);
    }

    return null;
}

/**
 * Respect the toggles on preferences.php.
 *
 * Transactional mail a customer cannot opt out of (order lifecycle, invoices,
 * password resets) is always allowed; only the promotional categories check.
 */
function notification_allowed_for_user(string $templateKey, $userId): bool
{
    $optional = [
        'back_in_stock'      => 'notify_back_in_stock',
        'newsletter_welcome' => 'notify_promotions',
        'review_approved'    => 'notify_promotions',
    ];

    if (!isset($optional[$templateKey]) || empty($userId)) {
        return true;
    }

    try {
        $preferences = Database::fetch(
            'SELECT * FROM `user_preferences` WHERE `user_id` = :id LIMIT 1',
            ['id' => (int) $userId]
        );
    } catch (Throwable $e) {
        return true;
    }

    if ($preferences === null) {
        return true;
    }

    if (array_key_exists('channel_email', $preferences) && (int) $preferences['channel_email'] === 0) {
        return false;
    }

    $column = $optional[$templateKey];

    return !array_key_exists($column, $preferences) || (int) $preferences[$column] === 1;
}

/** Store-wide placeholders available to every template. */
function notification_default_vars(): array
{
    return [
        'store_name'    => (string) setting('store_name', SITE_NAME),
        'store_url'     => canonical_url(),
        'store_email'   => (string) setting('store_email', 'support@' . SITE_DOMAIN),
        'store_phone'   => (string) setting('store_phone', ''),
        'store_address' => (string) setting('store_address', ''),
        'year'          => date('Y'),
    ];
}

/**
 * The same variables, safe to substitute into an HTML body.
 *
 * render_template() is a plain strtr(), so whatever a variable holds lands in
 * the message markup verbatim. Most values are customer-supplied at some
 * remove — a name, an address, a return comment — and an admin alert renders
 * them straight into HTML. Escaping here keeps that from distorting the
 * message or smuggling markup into somebody's inbox.
 *
 * Variables whose name ends in _html are markup on purpose (a block of table
 * rows) and are built server-side, so they pass through untouched.
 */
function template_html_vars(array $vars): array
{
    foreach ($vars as $key => $value) {
        if (is_string($value) && substr($key, -5) !== '_html') {
            // nl2br after escaping keeps a multi-line value (an address, an
            // enquiry, a return comment) readable instead of collapsing it to
            // one run-on line. It only ever inserts <br />, so it cannot
            // reintroduce anything that was just escaped.
            $vars[$key] = strpos($value, "\n") === false ? e($value) : nl2br(e($value), false);
        }
    }

    return $vars;
}

/**
 * The same variables, safe to substitute into a plain-text body.
 *
 * Some placeholders carry markup by design — {{order_items_html}} is a block of
 * <tr> rows. Substituting those straight into the text part dumps raw HTML in
 * front of anyone whose client shows the text alternative, so every *_html
 * variable is flattened to readable text first.
 */
function template_text_vars(array $vars): array
{
    foreach ($vars as $key => $value) {
        if (is_string($value) && substr($key, -5) === '_html') {
            $vars[$key] = html_to_text($value);
        }
    }

    return $vars;
}

/** Replace {{placeholders}} in a template body. */
function render_template(string $template, array $vars): string
{
    if ($template === '') {
        return '';
    }
    $replacements = [];
    foreach ($vars as $key => $value) {
        $replacements['{{' . $key . '}}'] = is_scalar($value) ? (string) $value : '';
    }
    return strtr($template, $replacements);
}

// ===========================================================================
//  EVENT HELPERS
// ===========================================================================

function notify_order_placed(?array $order, bool $force = false): void
{
    if ($order === null) {
        return;
    }

    $orderId = (int) $order['id'];
    $vars = order_notification_vars($order);
    $userId = $order['user_id'] === null ? null : (int) $order['user_id'];

    // The confirmation carries the PDF invoice whenever one has been issued.
    $invoice = get_invoice_for_order($orderId);

    notify('order_placed', (string) $order['customer_email'], $vars, 'order', $orderId, 'email', [
        'email_type'     => 'order_confirmation',
        'recipient_name' => (string) $order['customer_name'],
        'user_id'        => $userId,
        'order_id'       => $orderId,
        'invoice_id'     => $invoice === null ? null : (int) $invoice['id'],
        'attach_invoice' => $invoice !== null,
        'force'          => $force,
    ]);

    notify_admins('order_placed_admin', $vars, 'order', $orderId, [
        'email_type' => 'admin_new_order',
        'force'      => $force,
    ]);
}

/** The invoice-ready email, sent once an invoice exists for the order. */
function notify_invoice_generated(?array $order, ?array $invoice, bool $force = false): void
{
    if ($order === null || $invoice === null) {
        return;
    }

    $orderId = (int) $order['id'];

    notify('invoice_generated', (string) $order['customer_email'], order_notification_vars($order, $invoice), 'order', $orderId, 'email', [
        'email_type'     => 'invoice',
        'recipient_name' => (string) $order['customer_name'],
        'user_id'        => $order['user_id'] === null ? null : (int) $order['user_id'],
        'order_id'       => $orderId,
        'invoice_id'     => (int) $invoice['id'],
        'attach_invoice' => true,
        'force'          => $force,
    ]);
}

/** Payment outcome mail. $event is one of paid / failed / pending. */
function notify_payment_result(?array $order, string $event, string $reason = ''): void
{
    if ($order === null) {
        return;
    }

    $templateKey = [
        'paid'    => 'payment_success',
        'failed'  => 'payment_failed',
        'pending' => 'payment_pending',
    ][$event] ?? null;

    if ($templateKey === null) {
        return;
    }

    $orderId = (int) $order['id'];
    $vars = order_notification_vars($order);
    $vars['failure_reason'] = $reason !== '' ? $reason : 'The payment could not be completed.';
    $vars['retry_url'] = canonical_url('order-details.php?order=' . urlencode((string) $order['order_number']));

    notify($templateKey, (string) $order['customer_email'], $vars, 'order', $orderId, 'email', [
        'email_type'     => 'payment_' . $event,
        'recipient_name' => (string) $order['customer_name'],
        'user_id'        => $order['user_id'] === null ? null : (int) $order['user_id'],
        'order_id'       => $orderId,
    ]);

    if ($event === 'failed') {
        notify_admins('payment_failed_admin', $vars, 'order', $orderId, ['email_type' => 'admin_payment_failed']);
    }
}

function notify_order_status_changed(?array $order, string $status): void
{
    if ($order === null) {
        return;
    }

    // Previously only 4 of the 10 statuses mailed, while the in-app feed
    // raised a notification for 8 — the two disagreed on every other status.
    $templateKey = [
        ORDER_STATUS_CONFIRMED        => 'order_confirmed',
        ORDER_STATUS_PROCESSING       => 'order_processing',
        ORDER_STATUS_PACKED           => 'order_packed',
        ORDER_STATUS_SHIPPED          => 'order_shipped',
        ORDER_STATUS_OUT_FOR_DELIVERY => 'order_out_for_delivery',
        ORDER_STATUS_DELIVERED        => 'order_delivered',
        ORDER_STATUS_CANCELLED        => 'order_cancelled',
        ORDER_STATUS_RETURNED         => 'order_returned',
        ORDER_STATUS_REFUNDED         => 'order_refunded',
    ][$status] ?? null;

    if ($templateKey === null) {
        return;
    }

    $orderId = (int) $order['id'];
    $invoice = get_invoice_for_order($orderId);

    // Confirmation is the point the customer expects their bill.
    $attachInvoice = $invoice !== null && $status === ORDER_STATUS_CONFIRMED;

    notify($templateKey, (string) $order['customer_email'], order_notification_vars($order, $invoice), 'order', $orderId, 'email', [
        'email_type'     => 'order_' . $status,
        'recipient_name' => (string) $order['customer_name'],
        'user_id'        => $order['user_id'] === null ? null : (int) $order['user_id'],
        'order_id'       => $orderId,
        'invoice_id'     => $invoice === null ? null : (int) $invoice['id'],
        'attach_invoice' => $attachInvoice,
    ]);

    if ($status === ORDER_STATUS_CANCELLED) {
        notify_admins('order_cancelled_admin', order_notification_vars($order, $invoice), 'order', $orderId, [
            'email_type' => 'admin_order_cancelled',
        ]);
    }
}

/**
 * Send an admin-facing template to every configured notification address.
 * `admin_notify_email` accepts a comma-separated list.
 */
function notify_admins(string $templateKey, array $vars, ?string $referenceType, ?int $referenceId, array $options = []): void
{
    $configured = (string) setting('admin_notify_email', (string) setting('store_email', ''));

    $recipients = array_unique(array_filter(
        array_map('trim', preg_split('/[,;]+/', $configured) ?: []),
        static fn (string $address): bool => $address !== '' && filter_var($address, FILTER_VALIDATE_EMAIL) !== false
    ));

    foreach ($recipients as $address) {
        notify($templateKey, $address, $vars, $referenceType, $referenceId, 'email', array_merge($options, [
            'recipient_type' => 'admin',
            'order_id'       => $options['order_id'] ?? ($referenceType === 'order' ? $referenceId : null),
        ]));
    }
}

/**
 * Placeholder set shared by every order template.
 *
 * Every key the seeded templates reference is supplied here. Anything missing
 * is emitted to the customer as a literal '{{name}}', which is what used to
 * happen for order_items, courier_name, delivered_date, cancel_reason and the
 * rest.
 */
function order_notification_vars(array $order, ?array $invoice = null): array
{
    $orderId = (int) $order['id'];

    $items = [];
    try {
        $items = Database::fetchAll(
            'SELECT `product_name`, `variant_name`, `quantity`, `total` FROM `order_items` WHERE `order_id` = :id ORDER BY `id`',
            ['id' => $orderId]
        );
    } catch (Throwable $e) {
        $items = [];
    }

    $itemSummary = implode(', ', array_map(
        static fn (array $item): string => trim((string) $item['product_name'])
            . ((string) ($item['variant_name'] ?? '') !== '' ? ' (' . $item['variant_name'] . ')' : '')
            . ' × ' . (int) $item['quantity'],
        $items
    ));

    $itemRows = '';
    foreach ($items as $item) {
        $itemRows .= '<tr>'
            . '<td style="padding:6px 0;border-bottom:1px solid #e5e7eb">' . e((string) $item['product_name'])
            . ((string) ($item['variant_name'] ?? '') !== '' ? ' <span style="color:#6b7280">(' . e((string) $item['variant_name']) . ')</span>' : '')
            . '</td>'
            . '<td style="padding:6px 0;border-bottom:1px solid #e5e7eb;text-align:center">' . (int) $item['quantity'] . '</td>'
            . '<td style="padding:6px 0;border-bottom:1px solid #e5e7eb;text-align:right">' . e(money((float) $item['total'])) . '</td>'
            . '</tr>';
    }

    if ($invoice === null) {
        $invoice = get_invoice_for_order($orderId);
    }

    $storeEmail = (string) setting('store_email', '');
    $orderNumber = (string) $order['order_number'];

    return [
        'customer_name'   => (string) $order['customer_name'],
        'customer_email'  => (string) $order['customer_email'],
        'customer_phone'  => (string) $order['customer_phone'],
        'order_number'    => $orderNumber,
        'order_id'        => (string) $orderId,
        'order_date'      => format_date($order['created_at'], 'd M Y'),
        'order_total'     => money((float) $order['total_amount']),
        'order_subtotal'  => money((float) $order['subtotal']),
        'order_discount'  => money((float) $order['discount_amount']),
        'order_shipping'  => (float) $order['shipping_amount'] > 0 ? money((float) $order['shipping_amount']) : 'FREE',
        'order_tax'       => money((float) $order['tax_amount']),
        'order_status'    => ORDER_STATUSES[$order['status']] ?? (string) $order['status'],
        'order_items'     => $itemSummary !== '' ? $itemSummary : 'your items',
        'order_items_html' => $itemRows,
        'item_count'      => (string) count($items),
        'payment_method'  => strtoupper((string) $order['payment_method']),
        'payment_status'  => PAYMENT_STATUSES[$order['payment_status']] ?? (string) $order['payment_status'],
        'coupon_code'     => (string) ($order['coupon_code'] ?? ''),

        'shipping_address' => trim(implode(', ', array_filter([
            $order['shipping_name'],
            $order['shipping_address'],
            $order['shipping_address2'],
            $order['shipping_city'],
            $order['shipping_state'],
            $order['shipping_pincode'],
        ]))),
        'shipping_city'   => (string) ($order['shipping_city'] ?? ''),
        'shipping_state'  => (string) ($order['shipping_state'] ?? ''),
        'shipping_pincode' => (string) ($order['shipping_pincode'] ?? ''),

        'estimated_delivery' => $order['estimated_delivery'] ? format_date($order['estimated_delivery'], 'd M Y') : 'shortly',
        'delivered_date'  => $order['delivered_at'] ? format_date($order['delivered_at'], 'd M Y') : format_date(date('Y-m-d'), 'd M Y'),
        'shipped_date'    => $order['shipped_at'] ? format_date($order['shipped_at'], 'd M Y') : '',
        'tracking_number' => (string) ($order['tracking_number'] ?? 'will be shared shortly'),
        'courier_name'    => (string) ($order['courier_name'] ?? 'our delivery partner'),
        'tracking_url'    => canonical_url('track-order.php?order=' . urlencode($orderNumber)),
        'order_url'       => canonical_url('order-details.php?order=' . urlencode($orderNumber)),
        'review_url'      => canonical_url('my-reviews.php'),

        'cancel_reason'   => (string) ($order['cancel_reason'] ?? 'as requested'),
        'return_reason'   => (string) ($order['return_reason'] ?? ''),
        // A refund is promised only where money was taken. "Anything but
        // pending" also covered 'failed' - which is what a COD parcel that
        // came back (RTO) is left at - and promised a refund of cash the
        // courier never collected.
        'refund_note'     => (float) $order['total_amount'] > 0
            && in_array((string) $order['payment_status'], [PAYMENT_STATUS_PAID, PAYMENT_STATUS_REFUNDED], true)
            ? 'Any amount already paid is refunded to the original payment method within 5-7 working days.'
            : 'No amount was charged for this order.',
        'support_email'   => $storeEmail,
        'support_phone'   => (string) setting('store_phone', ''),

        'invoice_number'  => $invoice === null ? '' : (string) $invoice['invoice_number'],
        'invoice_date'    => $invoice === null ? '' : format_date((string) $invoice['invoice_date'], 'd M Y'),
        'invoice_url'     => canonical_url('invoice.php?order=' . urlencode($orderNumber)),
        'invoice_download_url' => canonical_url('invoice-download.php?order=' . urlencode($orderNumber)),
        'amount_paid'     => $invoice === null ? money(0) : money((float) $invoice['amount_paid']),
        'balance_due'     => $invoice === null ? money(0) : money((float) $invoice['balance_due']),
    ];
}

/** The signup coupon the welcome templates advertise, if one is configured. */
function notification_welcome_coupon(): array
{
    $code = trim((string) setting('welcome_coupon_code', ''));
    if ($code === '') {
        return ['coupon_code' => '', 'coupon_value' => ''];
    }

    try {
        $coupon = Database::fetch(
            "SELECT `type`, `value` FROM `coupons` WHERE `code` = :c AND `status` = 'active' LIMIT 1",
            ['c' => $code]
        );
    } catch (Throwable $e) {
        $coupon = null;
    }

    if ($coupon === null) {
        return ['coupon_code' => $code, 'coupon_value' => (string) setting('welcome_coupon_value', '')];
    }

    return [
        'coupon_code'  => $code,
        'coupon_value' => (string) $coupon['type'] === COUPON_TYPE_PERCENTAGE
            ? rtrim(rtrim(number_format((float) $coupon['value'], 2, '.', ''), '0'), '.') . '%'
            : money((float) $coupon['value']),
    ];
}

function notify_welcome(array $user): void
{
    $name = trim($user['first_name'] . ' ' . (string) ($user['last_name'] ?? ''));

    notify('welcome', (string) $user['email'], array_merge(notification_welcome_coupon(), [
        'customer_name'  => $name !== '' ? $name : 'there',
        'customer_email' => (string) $user['email'],
        'login_url'      => canonical_url('login.php'),
        'shop_url'       => canonical_url('shop.php'),
        'account_url'    => canonical_url('account.php'),
    ]), 'user', (int) $user['id'], 'email', [
        'email_type'     => 'welcome',
        'recipient_name' => $name,
        'user_id'        => (int) $user['id'],
    ]);

    notify_admins('customer_registered_admin', [
        'customer_name'  => $name,
        'customer_email' => (string) $user['email'],
        'signup_date'    => format_datetime(date('Y-m-d H:i:s')),
        'customer_url'   => admin_url('customers/view.php?id=' . (int) $user['id']),
    ], 'user', (int) $user['id'], ['email_type' => 'admin_new_customer']);
}

/**
 * Send the "confirm your email address" link.
 *
 * Verification is not enforced at sign-in. Blocking an unverified login would
 * not stop the purchase — guest checkout is on by default — it would only move
 * the customer out of their account and lose the order history. So the account
 * is usable immediately and the storefront nags until the address is proved.
 *
 * `force` is mandatory: without it the idempotency key would collapse a genuine
 * resend into the original queue row and report success while sending nothing.
 */
function notify_email_verification(array $user, string $verifyUrl): void
{
    $name = trim((string) ($user['first_name'] ?? '') . ' ' . (string) ($user['last_name'] ?? ''));
    $hours = max(1, min(720, setting_int('email_verify_expiry_hours', 48)));

    notify('email_verify', (string) $user['email'], [
        'customer_name'  => $name !== '' ? $name : 'there',
        'customer_email' => (string) $user['email'],
        'verify_url'     => $verifyUrl,
        'expiry_hours'   => (string) $hours,
    ], 'user', (int) $user['id'], 'email', [
        'email_type'     => 'email_verify',
        'recipient_name' => $name,
        'user_id'        => (int) $user['id'],
        'force'          => true,
    ]);
}

/** Build the full verification link for a freshly issued token. */
function email_verification_url(string $token, string $email): string
{
    // Both halves travel together: a token on its own must not be replayable
    // against another account if it leaks from a shared inbox or a log.
    return canonical_url('verify-email.php') . '?token=' . urlencode($token) . '&email=' . urlencode($email);
}

/**
 * Issue a token and queue the verification email in one call.
 * Never throws — a mail problem must not cost a completed registration.
 */
function send_email_verification(array $user): bool
{
    if (!setting_bool('email_verification_enabled', true)) {
        return false;
    }
    if (user_email_verified($user)) {
        return false;
    }

    try {
        $token = create_email_verification((string) $user['email'], (int) $user['id']);
        notify_email_verification($user, email_verification_url($token, (string) $user['email']));

        return true;
    } catch (Throwable $e) {
        ErrorHandler::log('warning', 'Verification email failed for user ' . ($user['id'] ?? '?') . ': ' . $e->getMessage());
        return false;
    }
}

function notify_password_reset(string $email, string $name, string $resetUrl): void
{
    $minutes = max(1, setting_int('password_reset_expiry_minutes', 60));

    notify('password_reset', $email, [
        'customer_name'   => $name !== '' ? $name : 'there',
        'customer_email'  => $email,
        'reset_url'       => $resetUrl,
        // The seeded template asks for expiry_minutes; 'expiry' was never used.
        'expiry_minutes'  => (string) $minutes,
        'expiry'          => $minutes >= 60
            ? (int) round($minutes / 60) . ' hour' . ((int) round($minutes / 60) === 1 ? '' : 's')
            : $minutes . ' minutes',
        'request_ip'      => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
    ], null, null, 'email', [
        'email_type'     => 'password_reset',
        'recipient_name' => $name,
        // A second reset request must always send, so no idempotency key.
        'force'          => true,
    ]);
}

function notify_newsletter_welcome(string $email): void
{
    notify('newsletter_welcome', $email, array_merge(notification_welcome_coupon(), [
        'subscriber_email' => $email,
        'customer_email'   => $email,
        'shop_url'         => canonical_url('shop.php'),
        'unsubscribe_url'  => newsletter_unsubscribe_url($email),
    ]), null, null, 'email', ['email_type' => 'newsletter_welcome']);
}

/**
 * A signed unsubscribe link. The token is derived from the address and a key
 * of its own, so no column is needed and the link cannot be forged for someone
 * else - nor can knowing it tell you anything about the stored secrets.
 */
function newsletter_unsubscribe_url(string $email): string
{
    return canonical_url('newsletter-unsubscribe.php?email=' . rawurlencode($email)
        . '&token=' . newsletter_unsubscribe_token($email));
}

/** The token half of the link above. '' when no application key is available. */
function newsletter_unsubscribe_token(string $email): string
{
    $key = app_key_derive('newsletter-unsubscribe');
    if ($key === '') {
        return '';
    }

    return substr(hash_hmac('sha256', mb_strtolower(trim($email)), $key), 0, 32);
}

/** Constant-time check of a token from an unsubscribe link. */
function newsletter_unsubscribe_token_valid(string $email, string $token): bool
{
    $expected = newsletter_unsubscribe_token($email);

    return $expected !== '' && $token !== '' && hash_equals($expected, $token);
}

// ---------------------------------------------------------------------------
//  Secrets in the queue
//
//  password_resets stores only a SHA-256 of the token, which is the right
//  thing - but the rendered email, with the working link in it, was kept in
//  notification_queue.body forever. The database, every nightly backup and the
//  hosting export therefore carried live password-reset links, which undid the
//  hashing completely. Failed rows were never even cleaned up.
//
//  So the body is scrubbed as soon as it can no longer be needed: on a
//  successful send, on a permanent failure, and in any case once the token
//  behind it has expired.
// ---------------------------------------------------------------------------

/** Template keys whose body contains a working, account-taking-over secret. */
const NOTIFICATION_SECRET_TEMPLATES = [
    'password_reset', 'admin_password_reset', 'email_verify', 'email_verification',
];

/** Replace secret query parameters in a rendered mail body. */
function notification_strip_tokens(?string $body): string
{
    $body = (string) $body;
    if ($body === '') {
        return '';
    }

    return (string) preg_replace(
        '/([?&](?:token|selector|verifier|code|otp|key|signature)=)[^"\'&\s<>]+/i',
        '$1[redacted]',
        $body
    );
}

/**
 * Scrub one queued row, if it is one of the token-bearing kinds.
 * Safe to call on anything; a row without a secret is left untouched.
 */
function notification_redact_secrets(int $id): void
{
    try {
        $row = Database::fetch(
            'SELECT `id`, `template_key`, `email_type`, `body`, `body_text`
             FROM `notification_queue` WHERE `id` = :id',
            ['id' => $id]
        );
        if ($row === null) {
            return;
        }
        if (!in_array((string) $row['template_key'], NOTIFICATION_SECRET_TEMPLATES, true)
            && !in_array((string) ($row['email_type'] ?? ''), NOTIFICATION_SECRET_TEMPLATES, true)) {
            return;
        }

        $body = notification_strip_tokens((string) $row['body']);
        $text = notification_strip_tokens((string) $row['body_text']);
        if ($body === (string) $row['body'] && $text === (string) $row['body_text']) {
            return;   // already scrubbed
        }

        Database::update('notification_queue', ['body' => $body, 'body_text' => $text], '`id` = :id', ['id' => $id]);
    } catch (Throwable $e) {
        ErrorHandler::log('warning', 'Could not redact notification #' . $id . ': ' . $e->getMessage());
    }
}

/**
 * Sweep: scrub every token-bearing row whose token has expired anyway, and
 * delete the .eml files the "log" mail driver leaves behind.
 *
 * Reset tokens live for an hour, so anything older than a day is certainly
 * dead - including the rows that failed three times and would otherwise sit
 * there with a working link in them until the end of time.
 *
 * @return int rows scrubbed
 */
function notification_prune_secrets(int $olderThanSeconds = 86400): int
{
    $scrubbed = 0;

    try {
        // Two placeholder sets, not one reused twice: a named parameter that
        // appears more than once in a statement is not portable.
        [$byTemplate, $templateParams] = Database::inPlaceholders(NOTIFICATION_SECRET_TEMPLATES, 'tpl');
        [$byType, $typeParams]         = Database::inPlaceholders(NOTIFICATION_SECRET_TEMPLATES, 'typ');
        $params = $templateParams + $typeParams + ['cutoff' => date('Y-m-d H:i:s', time() - max(3600, $olderThanSeconds))];

        $ids = Database::fetchColumnAll(
            'SELECT `id` FROM `notification_queue`
             WHERE (`template_key` IN (' . $byTemplate . ') OR `email_type` IN (' . $byType . '))
               AND `created_at` < :cutoff
               AND (
                    (`body` LIKE \'%token=%\' AND `body` NOT LIKE \'%token=[redacted]%\')
                 OR (`body_text` LIKE \'%token=%\' AND `body_text` NOT LIKE \'%token=[redacted]%\')
               )
             LIMIT 500',
            $params
        );
        foreach ($ids as $id) {
            notification_redact_secrets((int) $id);
            $scrubbed++;
        }
    } catch (Throwable $e) {
        ErrorHandler::log('warning', 'Notification secret sweep failed: ' . $e->getMessage());
    }

    // The "log" mail driver writes the whole message, links included, to
    // storage/logs/mail/*.eml. Useful while developing, a folder of working
    // reset links after a week.
    $mailLog = LOG_PATH . '/mail';
    if (is_dir($mailLog)) {
        foreach (glob($mailLog . '/*.eml') ?: [] as $file) {
            if (filemtime($file) < time() - 7 * 86400) {
                @unlink($file);
            }
        }
    }

    return $scrubbed;
}

function notify_review_approved(array $review): void
{
    if (empty($review['user_id'])) {
        return;
    }
    $user = Database::fetch('SELECT `email`, `first_name` FROM `users` WHERE `id` = :id', ['id' => (int) $review['user_id']]);
    if ($user === null) {
        return;
    }

    $product = Database::fetch('SELECT `name`, `slug` FROM `products` WHERE `id` = :id', ['id' => (int) $review['product_id']]);

    notify('review_approved', (string) $user['email'], [
        'customer_name' => (string) $user['first_name'],
        'product_name'  => (string) ($product['name'] ?? 'your purchase'),
        'product_url'   => $product ? product_url((string) $product['slug']) : SITE_URL,
        'rating'        => (string) ((int) ($review['rating'] ?? 0)) . ' out of 5',
    ], 'review', (int) $review['id'], 'email', [
        'email_type' => 'review_approved',
        'user_id'    => (int) $review['user_id'],
    ]);
}

/** Queue "back in stock" mails for everyone waiting on a product. */
function queue_back_in_stock_alerts(int $productId, ?int $variantId = null): void
{
    try {
        $alerts = Database::fetchAll(
            'SELECT * FROM `stock_alerts`
             WHERE `product_id` = :pid AND (`variant_id` <=> :vid) AND `notified_at` IS NULL',
            ['pid' => $productId, 'vid' => $variantId]
        );
        if ($alerts === []) {
            return;
        }

        $product = Database::fetch('SELECT `name`, `slug`, `price` FROM `products` WHERE `id` = :id', ['id' => $productId]);
        if ($product === null) {
            return;
        }

        foreach ($alerts as $alert) {
            notify('back_in_stock', (string) $alert['email'], [
                'product_name'  => (string) $product['name'],
                'product_url'   => product_url((string) $product['slug']),
                'product_price' => money((float) $product['price']),
            ], 'product', $productId, 'email', [
                'email_type' => 'back_in_stock',
                'user_id'    => isset($alert['user_id']) && $alert['user_id'] !== null ? (int) $alert['user_id'] : null,
            ]);

            Database::update('stock_alerts', ['notified_at' => date('Y-m-d H:i:s')], '`id` = :id', ['id' => (int) $alert['id']]);
        }
    } catch (Throwable $e) {
        ErrorHandler::log('warning', 'Back-in-stock alerts failed: ' . $e->getMessage());
    }
}

// ===========================================================================
//  DISPATCH
// ===========================================================================

/**
 * Send pending notifications. Called opportunistically after checkout and
 * from the admin, so the store works without a cron job configured.
 *
 * @return array{sent:int, failed:int}
 */
function process_notification_queue(int $limit = 20): array
{
    $sent = 0;
    $failed = 0;

    // Nothing is deliverable until a transport is configured. Attempting anyway
    // would burn all three attempts on every queued message and mark it failed,
    // so a backlog raised before SMTP was set up could never be delivered once
    // it was. Park the queue instead and say so once.
    if (!mail_is_configured()) {
        // Throttled to once an hour. The storefront drain runs on roughly one
        // page view in six, so an unthrottled warning would bury the error log
        // in copies of the same message.
        if (Cache::get('mail_unconfigured_warned') === null) {
            Cache::put('mail_unconfigured_warned', 1, 3600);

            $pending = 0;
            try {
                $pending = (int) Database::fetchColumn(
                    "SELECT COUNT(*) FROM `notification_queue` WHERE `status` = 'pending' AND `attempts` < 3"
                );
            } catch (Throwable $e) {
                // Counting is only for the message; the guard still holds.
            }
            if ($pending > 0) {
                ErrorHandler::log('warning', sprintf(
                    'Mail transport is not configured — %d message(s) held in the queue. Set an SMTP host in Settings > Email.',
                    $pending
                ));
            }
        }

        return ['sent' => 0, 'failed' => 0];
    }

    try {
        $rows = Database::fetchAll(
            "SELECT * FROM `notification_queue`
             WHERE `status` = 'pending' AND `attempts` < 3
             ORDER BY `id` ASC LIMIT " . max(1, min(100, $limit))
        );
    } catch (Throwable $e) {
        return ['sent' => 0, 'failed' => 0];
    }

    foreach ($rows as $row) {
        $ok = false;
        $error = null;
        $smtpResponse = null;
        $attachmentName = null;
        $now = date('Y-m-d H:i:s');

        try {
            switch ($row['channel']) {
                case 'email':
                    $attachments = [];

                    // A row queued with attachment_name = 'invoice' resolves to
                    // the real PDF here rather than at queue time, because the
                    // file may still have been rendering when it was queued.
                    if ((string) ($row['attachment_name'] ?? '') !== '') {
                        $resolved = notification_resolve_attachment($row);
                        if ($resolved === null) {
                            // Send the mail without the PDF rather than never
                            // telling the customer their order was placed.
                            ErrorHandler::log('warning', 'Notification #' . $row['id'] . ': invoice attachment unavailable, sending without it.');
                        } else {
                            $attachments[] = $resolved;
                            $attachmentName = $resolved['name'];
                        }
                    }

                    $result = send_email_detailed(
                        (string) $row['recipient'],
                        (string) $row['subject'],
                        (string) $row['body'],
                        $attachments,
                        [
                            'to_name' => (string) ($row['recipient_name'] ?? ''),
                            'text'    => (string) ($row['body_text'] ?? ''),
                        ]
                    );

                    $ok = $result['ok'];
                    $error = $result['error'];
                    $smtpResponse = $result['smtp'];
                    break;

                case 'sms':
                case 'whatsapp':
                case 'push':
                    // No provider wired up yet - park the row rather than
                    // burning attempts on a channel that cannot send.
                    $error = 'No provider configured for the ' . $row['channel'] . ' channel.';
                    break;
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        if ($ok) {
            Database::update('notification_queue', [
                'status'          => 'sent',
                'sent_at'         => $now,
                'last_attempt_at' => $now,
                'attempts'        => (int) $row['attempts'] + 1,
                // Clear any error left over from an earlier failed attempt so
                // a row that eventually succeeded does not read as failed.
                'error'           => null,
                'smtp_response'   => $smtpResponse === null ? null : mb_substr($smtpResponse, 0, 500),
                'attachment_name' => $attachmentName,
            ], '`id` = :id', ['id' => (int) $row['id']]);

            // The mail is gone; the working reset link in its body is of no
            // further use to us and of a great deal of use to anyone who can
            // read the database or a backup of it.
            notification_redact_secrets((int) $row['id']);
            $sent++;
        } else {
            $attempts = (int) $row['attempts'] + 1;
            $message = $error === null || trim($error) === '' ? 'Send failed.' : $error;

            Database::update('notification_queue', [
                'attempts'        => $attempts,
                'status'          => $attempts >= 3 ? 'failed' : 'pending',
                'error'           => mb_substr($message, 0, 500),
                'smtp_response'   => $smtpResponse === null ? null : mb_substr($smtpResponse, 0, 500),
                'last_attempt_at' => $now,
            ], '`id` = :id', ['id' => (int) $row['id']]);

            if ($attempts >= 3) {
                // Given up on: nobody will ever send this body, so nothing is
                // lost by scrubbing it - and a "failed" row used to keep its
                // link for the lifetime of the database.
                notification_redact_secrets((int) $row['id']);
            }

            // The old code swallowed this entirely; a failing mail transport
            // left nothing behind but the literal string 'Send failed.'
            ErrorHandler::log(
                $attempts >= 3 ? 'error' : 'warning',
                sprintf(
                    'Email to %s failed (attempt %d, template %s): %s',
                    mask_email((string) $row['recipient']),
                    $attempts,
                    (string) ($row['template_key'] ?? '-'),
                    $message
                )
            );
            $failed++;
        }
    }

    // Once per drain, at the cost of one indexed SELECT: catch the rows that
    // never got a send attempt at all (queued while SMTP was down, then
    // abandoned) and the .eml files the log driver leaves behind.
    notification_prune_secrets();

    return ['sent' => $sent, 'failed' => $failed];
}

/**
 * Resolve a queued row's attachment placeholder to a real file.
 * Only the invoice PDF is supported; the file is rendered on demand when it
 * has not been produced yet.
 *
 * @return array{path:string, name:string}|null
 */
function notification_resolve_attachment(array $row): ?array
{
    if ((string) $row['attachment_name'] !== 'invoice') {
        // An already-resolved row keeps whatever path it was given.
        $path = (string) ($row['attachment_path'] ?? '');
        if ($path === '') {
            return null;
        }
        $absolute = STORAGE_PATH . '/' . ltrim($path, '/\\');

        return is_file($absolute) ? ['path' => $absolute, 'name' => (string) $row['attachment_name']] : null;
    }

    $orderId = (int) ($row['order_id'] ?? 0);
    $invoiceId = (int) ($row['invoice_id'] ?? 0);

    try {
        $invoice = $invoiceId > 0 ? get_invoice($invoiceId) : ($orderId > 0 ? get_invoice_for_order($orderId) : null);
        if ($invoice === null) {
            return null;
        }

        $pdf = invoice_render_pdf($invoice);
        if (!$pdf['ok'] || $pdf['path'] === null) {
            return null;
        }

        Database::update('notification_queue', [
            'attachment_path' => (string) Database::fetchColumn('SELECT `pdf_path` FROM `invoices` WHERE `id` = :id', ['id' => (int) $invoice['id']]),
            'invoice_id'      => (int) $invoice['id'],
        ], '`id` = :id', ['id' => (int) $row['id']]);

        return ['path' => $pdf['path'], 'name' => $pdf['filename']];
    } catch (Throwable $e) {
        ErrorHandler::log('warning', 'Invoice attachment lookup failed for queue row ' . $row['id'] . ': ' . $e->getMessage());
        return null;
    }
}

/**
 * Send one HTML email over SMTP.
 *
 * The single transport choke point for the whole application. It used to call
 * PHP's mail(), which gave no delivery signal, no authentication and no way to
 * attach a PDF; it now hands off to PHPMailer via mailer_send().
 *
 * The 3-argument shape every existing caller uses is unchanged.
 *
 * @param array $attachments [['path' => absolute, 'name' => filename], ...]
 * @param array $options     text, to_name, reply_to, cc, bcc, skip_layout
 */
function send_email(string $to, string $subject, string $htmlBody, array $attachments = [], array $options = []): bool
{
    return send_email_detailed($to, $subject, $htmlBody, $attachments, $options)['ok'];
}

/**
 * As send_email(), but returns the outcome instead of collapsing it to a bool,
 * so the queue can record why a message failed.
 *
 * @return array{ok:bool, error:?string, smtp:?string, transport:string}
 */
function send_email_detailed(string $to, string $subject, string $htmlBody, array $attachments = [], array $options = []): array
{
    $html = empty($options['skip_layout']) ? email_layout($subject, $htmlBody) : $htmlBody;

    $text = trim((string) ($options['text'] ?? ''));
    if ($text === '') {
        $text = html_to_text($htmlBody);
    }
    $text = email_text_layout($text);

    return mailer_send([
        'to'          => $to,
        'to_name'     => (string) ($options['to_name'] ?? ''),
        'subject'     => $subject,
        'html'        => $html,
        'text'        => $text,
        'reply_to'    => (string) ($options['reply_to'] ?? ''),
        'cc'          => (array) ($options['cc'] ?? []),
        'bcc'         => (array) ($options['bcc'] ?? []),
        'attachments' => $attachments,
    ]);
}

/**
 * Wrap template body HTML in the branded email shell.
 *
 * Every length here is an absolute px value. The shell previously used the
 * storefront's CSS custom properties (var(--sp-6) and friends), which are
 * defined in a stylesheet that is not part of the message — no mail client can
 * resolve them, so every padding collapsed to zero and every email the store
 * sent arrived with its content flush against the edges.
 */
function email_layout(string $title, string $body): string
{
    $storeName = e((string) setting('store_name', SITE_NAME));
    $primary = e((string) setting('primary_color', '#F4511E'));
    $navy = e((string) setting('secondary_color', '#0F2143'));
    $year = date('Y');
    $address = e((string) setting('store_address', ''));
    $storeEmail = e((string) setting('store_email', ''));
    $storePhone = e((string) setting('store_phone', ''));
    $title = e($title);

    $contact = trim(implode(' &nbsp;·&nbsp; ', array_filter([$storePhone, $storeEmail])));
    $contactRow = $contact === '' ? '' : '<p style="margin:0 0 6px 0">' . $contact . '</p>';
    $addressRow = $address === '' ? '' : '<p style="margin:0 0 6px 0">' . $address . '</p>';

    return <<<HTML
<!doctype html>
<html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="x-apple-disable-message-reformatting">
<title>{$title}</title>
<style>
  /* Progressive enhancement only — every critical style is inline below. */
  @media only screen and (max-width:620px){
    .sik-shell{width:100% !important}
    .sik-pad{padding:20px !important}
    .sik-head{padding:18px 20px !important}
  }
</style>
</head>
<body style="margin:0;padding:0;background-color:#f4f5f7;font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#111827;-webkit-font-smoothing:antialiased">
<div style="display:none;max-height:0;overflow:hidden;opacity:0">{$title}</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f4f5f7">
<tr><td align="center" style="padding:24px 12px">
<table role="presentation" class="sik-shell" width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%;background-color:#ffffff;border-radius:14px;overflow:hidden;border:1px solid #e5e7eb">
<tr><td class="sik-head" style="background-color:{$navy};padding:22px 32px">
<span style="color:#ffffff;font-size:20px;font-weight:700;letter-spacing:-0.3px">Shop<span style="color:{$primary}">InnKart</span></span>
</td></tr>
<tr><td class="sik-pad" style="padding:32px;font-size:15px;line-height:1.65;color:#111827">{$body}</td></tr>
<tr><td class="sik-pad" style="background-color:#f9fafb;padding:20px 32px;font-size:12px;color:#6b7280;line-height:1.6;border-top:1px solid #e5e7eb">
{$addressRow}{$contactRow}
<p style="margin:0">&copy; {$year} {$storeName}. All rights reserved.</p>
</td></tr>
</table>
</td></tr></table>
</body></html>
HTML;
}

/** Plain-text counterpart of email_layout() — the signature the text part gets. */
function email_text_layout(string $body): string
{
    $storeName = (string) setting('store_name', SITE_NAME);
    $storeEmail = (string) setting('store_email', '');
    $storePhone = (string) setting('store_phone', '');

    $footer = array_filter([
        '',
        str_repeat('-', 48),
        $storeName,
        (string) setting('store_address', ''),
        trim(implode('  |  ', array_filter([$storePhone, $storeEmail]))),
        SITE_URL,
    ], static fn ($line): bool => $line !== null);

    return trim($body) . "\n" . implode("\n", $footer) . "\n";
}
