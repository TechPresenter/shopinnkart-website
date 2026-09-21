<?php
/**
 * ShopInnKart - Payment recording and callback idempotency.
 *
 * Gateways retry. A webhook that times out on our side is redelivered, often
 * several times, and a "pay" button double-click sends the same success
 * payload twice. Every one of those must converge on a single payment row, a
 * single invoice and a single confirmation email.
 *
 * The guarantee is made at three levels:
 *   - payment_transactions has UNIQUE(gateway, gateway_event_id), so a replayed
 *     event fails to insert and is recognised as already-seen,
 *   - payments has UNIQUE(gateway, reference), so one gateway payment id maps
 *     to one row,
 *   - invoices has UNIQUE(order_id) and notification_queue has
 *     UNIQUE(idempotency_key).
 *
 * Nothing here trusts a request body for an amount or a status: the caller has
 * already verified the signature, and the amount is checked against the order.
 */

declare(strict_types=1);

/**
 * Record a gateway event and apply it to the order exactly once.
 *
 * @param array $event gateway (required), event_id, order_id|order_number,
 *                     status (paid|failed|pending|refunded), amount,
 *                     reference, message, payload (array)
 *
 * @return array{ok:bool, duplicate:bool, order:?array, invoice:?array, message:string}
 */
function payment_record_event(array $event): array
{
    $gateway = strtolower(trim((string) ($event['gateway'] ?? '')));
    $status  = strtolower(trim((string) ($event['status'] ?? '')));
    $eventId = trim((string) ($event['event_id'] ?? ''));

    $miss = static fn (string $message): array => [
        'ok' => false, 'duplicate' => false, 'order' => null, 'invoice' => null, 'message' => $message,
    ];

    if ($gateway === '') {
        return $miss('No gateway supplied.');
    }
    if (!in_array($status, [PAYMENT_STATUS_PAID, PAYMENT_STATUS_FAILED, PAYMENT_STATUS_PENDING, PAYMENT_STATUS_REFUNDED], true)) {
        return $miss('Unknown payment status "' . $status . '".');
    }

    // ---- Resolve the order ------------------------------------------------
    $order = null;
    if (!empty($event['order_id'])) {
        $order = get_order((int) $event['order_id']);
    } elseif (!empty($event['order_number'])) {
        $order = get_order_by_number((string) $event['order_number']);
    }

    if ($order === null) {
        return $miss('Order not found for this payment event.');
    }

    $orderId = (int) $order['id'];
    $reference = trim((string) ($event['reference'] ?? ''));
    $amount = isset($event['amount']) ? money_round((float) $event['amount']) : (float) $order['total_amount'];

    // An event id is what makes replay detection possible. When the gateway
    // gives none, the payment reference plus the status is the next best key.
    if ($eventId === '') {
        $eventId = $reference !== ''
            ? $reference . ':' . $status
            : 'order:' . $orderId . ':' . $status;
    }

    // ---- Claim the event --------------------------------------------------
    // The INSERT is the lock: whoever wins it owns this event, and every
    // replay lands on the duplicate branch below.
    try {
        Database::insert('payment_transactions', [
            'payment_id'       => null,
            'order_id'         => $orderId,
            'gateway'          => $gateway,
            'event'            => mb_substr((string) ($event['event'] ?? ('payment.' . $status)), 0, 60),
            'gateway_event_id' => mb_substr($eventId, 0, 190),
            'amount'           => $amount,
            'status'           => $status,
            'message'          => isset($event['message']) ? mb_substr((string) $event['message'], 0, 500) : null,
            'payload'          => isset($event['payload'])
                ? json_encode(payment_redact_payload((array) $event['payload']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null,
        ]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            ErrorHandler::log('info', sprintf(
                'Duplicate %s callback ignored for order %s (event %s).',
                $gateway,
                $order['order_number'],
                $eventId
            ));

            return [
                'ok'        => true,
                'duplicate' => true,
                'order'     => $order,
                'invoice'   => get_invoice_for_order($orderId),
                'message'   => 'Already processed.',
            ];
        }

        ErrorHandler::log('error', 'Payment event insert failed for order ' . $orderId . ': ' . $e->getMessage());
        return $miss('Could not record the payment event.');
    } catch (Throwable $e) {
        ErrorHandler::log('error', 'Payment event insert failed for order ' . $orderId . ': ' . $e->getMessage());
        return $miss('Could not record the payment event.');
    }

    // ---- Amount check -----------------------------------------------------
    // A paid event for the wrong amount is recorded but never confirms the
    // order; it is a reconciliation problem for a human, not an auto-approve.
    if ($status === PAYMENT_STATUS_PAID && abs($amount - (float) $order['total_amount']) >= 0.01) {
        ErrorHandler::log('error', sprintf(
            'Payment amount mismatch on order %s: gateway says %.2f, order total is %.2f. Left unconfirmed.',
            $order['order_number'],
            $amount,
            (float) $order['total_amount']
        ));

        payment_upsert_row($orderId, $gateway, $reference, $amount, PAYMENT_STATUS_PENDING);

        return [
            'ok' => false, 'duplicate' => false, 'order' => $order, 'invoice' => null,
            'message' => 'Payment amount does not match the order total.',
        ];
    }

    // ---- Apply ------------------------------------------------------------
    $paymentId = payment_upsert_row($orderId, $gateway, $reference, $amount, $status);
    if ($paymentId > 0) {
        Database::update('payment_transactions', ['payment_id' => $paymentId], '`gateway` = :g AND `gateway_event_id` = :e', [
            'g' => $gateway,
            'e' => mb_substr($eventId, 0, 190),
        ]);
    }

    // Only move the order forward; never walk a paid order back to pending.
    if ((string) $order['payment_status'] !== $status
        && !((string) $order['payment_status'] === PAYMENT_STATUS_PAID && $status === PAYMENT_STATUS_PENDING)) {
        Database::update('orders', ['payment_status' => $status], '`id` = :id', ['id' => $orderId]);
        $order = get_order($orderId) ?? $order;
    }

    $invoice = null;

    if ($status === PAYMENT_STATUS_PAID) {
        // Confirming issues the invoice and sends the confirmation mail.
        if (in_array((string) $order['status'], [ORDER_STATUS_PENDING], true)) {
            update_order_status($orderId, ORDER_STATUS_CONFIRMED, 'Payment received via ' . $gateway, 'system');
            $order = get_order($orderId) ?? $order;
        } else {
            $invoice = issue_order_invoice($order);
        }

        $invoice = $invoice ?? get_invoice_for_order($orderId);
        invoice_sync_payment($orderId);

        notify_payment_result($order, 'paid');
    } elseif ($status === PAYMENT_STATUS_FAILED) {
        notify_payment_result($order, 'failed', (string) ($event['message'] ?? ''));
    } elseif ($status === PAYMENT_STATUS_PENDING) {
        notify_payment_result($order, 'pending');
    } elseif ($status === PAYMENT_STATUS_REFUNDED) {
        invoice_sync_payment($orderId);
        notify_refund($order, 'completed');
    }

    log_activity('payment.' . $status, 'order', $orderId, sprintf(
        'Gateway %s reported %s for order %s (%s).',
        $gateway,
        $status,
        $order['order_number'],
        money($amount)
    ));

    return [
        'ok'        => true,
        'duplicate' => false,
        'order'     => get_order($orderId) ?? $order,
        'invoice'   => $invoice ?? get_invoice_for_order($orderId),
        'message'   => 'Payment ' . $status . ' recorded.',
    ];
}

/**
 * Create or update the payments row for an order.
 * UNIQUE(gateway, reference) makes a repeated reference land on the update
 * branch rather than creating a second row.
 */
function payment_upsert_row(int $orderId, string $gateway, string $reference, float $amount, string $status): int
{
    try {
        $existing = $reference !== ''
            ? Database::fetch(
                'SELECT * FROM `payments` WHERE `gateway` = :g AND `reference` = :r LIMIT 1',
                ['g' => $gateway, 'r' => $reference]
            )
            : Database::fetch(
                'SELECT * FROM `payments` WHERE `order_id` = :o AND `gateway` = :g ORDER BY `id` DESC LIMIT 1',
                ['o' => $orderId, 'g' => $gateway]
            );

        $data = [
            'status'  => $status,
            'amount'  => $amount,
            'paid_at' => $status === PAYMENT_STATUS_PAID ? date('Y-m-d H:i:s') : null,
        ];

        if ($existing !== null) {
            // Keep the original paid_at if it is already set.
            if ($status === PAYMENT_STATUS_PAID && !empty($existing['paid_at'])) {
                $data['paid_at'] = $existing['paid_at'];
            }
            Database::update('payments', $data, '`id` = :id', ['id' => (int) $existing['id']]);

            return (int) $existing['id'];
        }

        return Database::insert('payments', array_merge($data, [
            'order_id'  => $orderId,
            'gateway'   => $gateway,
            'currency'  => (string) setting('currency_code', CURRENCY),
            'reference' => $reference !== '' ? $reference : null,
        ]));
    } catch (Throwable $e) {
        ErrorHandler::log('warning', 'payments upsert failed for order ' . $orderId . ': ' . $e->getMessage());
        return 0;
    }
}

/**
 * Strip anything secret before a gateway payload is written to the database.
 * Webhook bodies routinely carry card fragments and signing material.
 */
function payment_redact_payload(array $payload): array
{
    $secret = [
        'card', 'card_number', 'cardnumber', 'cvv', 'cvc', 'pin', 'password',
        'signature', 'secret', 'key', 'api_key', 'token', 'access_token',
        'authorization', 'auth', 'upi_pin',
    ];

    $clean = [];
    foreach ($payload as $key => $value) {
        $needle = strtolower((string) $key);

        $isSecret = false;
        foreach ($secret as $word) {
            if (strpos($needle, $word) !== false) {
                $isSecret = true;
                break;
            }
        }

        if ($isSecret) {
            $clean[$key] = '[redacted]';
        } elseif (is_array($value)) {
            $clean[$key] = payment_redact_payload($value);
        } elseif (is_scalar($value) || $value === null) {
            $clean[$key] = $value;
        } else {
            $clean[$key] = '[unserialisable]';
        }
    }

    return $clean;
}

/** Refund lifecycle mail. $stage is 'initiated' or 'completed'. */
function notify_refund(?array $order, string $stage, string $note = ''): void
{
    if ($order === null) {
        return;
    }

    $templateKey = $stage === 'initiated' ? 'refund_initiated' : 'refund_completed';
    $orderId = (int) $order['id'];

    $vars = order_notification_vars($order);
    $vars['refund_amount'] = money((float) $order['total_amount']);
    $vars['refund_note'] = $note !== '' ? $note : $vars['refund_note'];
    $vars['refund_eta'] = '5-7 working days';

    notify($templateKey, (string) $order['customer_email'], $vars, 'order', $orderId, 'email', [
        'email_type'     => 'refund_' . $stage,
        'recipient_name' => (string) $order['customer_name'],
        'user_id'        => $order['user_id'] === null ? null : (int) $order['user_id'],
        'order_id'       => $orderId,
    ]);
}

/**
 * Return-request lifecycle mail. $stage is requested|approved|rejected.
 *
 * $request is the return_requests row, when there is one; it supplies the
 * reason label, the request type and the admin link. The order row alone is
 * enough for a caller that has no request record.
 */
function notify_return(?array $order, string $stage, string $note = '', ?array $request = null): void
{
    if ($order === null) {
        return;
    }

    $templateKey = [
        'requested' => 'return_requested',
        'approved'  => 'return_approved',
        'rejected'  => 'return_rejected',
    ][$stage] ?? null;

    if ($templateKey === null) {
        return;
    }

    $orderId = (int) $order['id'];
    $type = (string) ($request['type'] ?? 'return');

    $vars = order_notification_vars($order);
    $vars['return_type'] = $type === 'exchange' ? 'exchange' : 'return';
    $vars['return_id'] = (string) ($request['id'] ?? '');
    $vars['return_reason'] = $request !== null && function_exists('return_reason_label')
        ? return_reason_label((string) $request['reason'])
        : (string) ($order['return_reason'] ?? $note);

    // The customer sees their own words back on the acknowledgement, and the
    // staff decision on the approve/reject mails.
    $vars['return_note'] = $note !== ''
        ? $note
        : (string) ($request['comment'] ?? ($stage === 'approved'
            ? 'Our team will be in touch with pickup instructions.'
            : ''));

    $vars['return_admin_url'] = admin_url('orders/returns.php?request=' . (int) ($request['id'] ?? 0));

    notify($templateKey, (string) $order['customer_email'], $vars, 'order', $orderId, 'email', [
        'email_type'     => 'return_' . $stage,
        'recipient_name' => (string) $order['customer_name'],
        'user_id'        => $order['user_id'] === null ? null : (int) $order['user_id'],
        'order_id'       => $orderId,
        // A second decision on the same request is a real event, not a replay.
        'idempotency_key' => $request === null
            ? null
            : 'return:' . $stage . ':' . (int) $request['id'],
    ]);

    if ($stage === 'requested') {
        notify_admins('return_requested_admin', $vars, 'order', $orderId, [
            'email_type' => 'admin_return_request',
            'idempotency_key' => $request === null ? null : 'return_admin:' . (int) $request['id'],
        ]);
    }
}

/**
 * The card networks the store's enabled gateways actually settle.
 *
 * A footer that shows a Visa mark is telling a shopper "you can pay with a
 * Visa card here", so the marks are derived from the gateways that are ACTIVE
 * in Settings > Payment and backed by a gateway class - never typed into a
 * template. Enable Razorpay and the four Indian marks appear on the next page
 * load; turn it off and they go, along with the checkout option behind them.
 *
 * The mapping is what each gateway settles in India, which is why Stripe and
 * the domestic gateways differ.
 *
 * @return array<int, string> mark keys, in a stable display order
 */
function accepted_payment_marks(): array
{
    return cache_remember('payment.marks', 300, static function (): array {
        // Admin > Settings > Store > "Payment marks in the footer" wins when it
        // is filled in. Left empty the row derives itself from the gateways that
        // are actually enabled, which is the safer default: it can never show a
        // rail checkout would refuse. An operator who sets it explicitly is
        // making a display choice and owns it.
        $chosen = trim((string) setting('footer_payment_marks', ''));
        if ($chosen !== '') {
            $known = ['visa', 'mastercard', 'rupay', 'upi', 'amex', 'discover', 'cod'];
            // "cash" is what an operator naturally writes for cash on delivery,
            // and silently dropping it would leave the mark they asked for off
            // the row with nothing to explain why.
            $aliases = ['cash' => 'cod', 'mastercard' => 'mastercard', 'master' => 'mastercard', 'rupay' => 'rupay'];
            $wanted = array_map(static function ($m) use ($aliases) {
                $m = strtolower(trim((string) $m));
                return $aliases[$m] ?? $m;
            }, explode(',', $chosen));

            return array_values(array_intersect($known, $wanted));
        }

        require_once INCLUDES_PATH . '/order-functions.php';

        $byGateway = [
            'razorpay' => ['visa', 'mastercard', 'rupay', 'upi'],
            'cashfree' => ['visa', 'mastercard', 'rupay', 'upi'],
            'payu'     => ['visa', 'mastercard', 'rupay', 'upi'],
            // Stripe does not acquire RuPay or UPI for Indian merchants.
            'stripe'   => ['visa', 'mastercard', 'amex'],
            'cod'      => ['cod'],
        ];

        $marks = [];
        foreach (PaymentGatewayFactory::available() as $method) {
            foreach ($byGateway[(string) $method['code']] ?? [] as $mark) {
                $marks[$mark] = true;
            }
        }

        // One fixed order, so the row does not reshuffle when a gateway is
        // switched on or off.
        $order = ['visa', 'mastercard', 'rupay', 'upi', 'amex', 'cod'];
        return array_values(array_filter($order, static fn ($m) => isset($marks[$m])));
    });
}

/**
 * One acceptance mark.
 *
 * Deliberately simplified wordmarks and shapes rather than reproductions of
 * the official artwork: they scale, they stay crisp at 20px, they need no
 * image files, and they carry no risk of shipping a stale or altered version
 * of somebody's logo. Each is labelled for assistive tech by the caller.
 */
function payment_mark_html(string $key): string
{
    switch ($key) {
        case 'visa':
            return '<b class="sik-paymark__word" style="color:#1A1F71;letter-spacing:.04em">VISA</b>';

        case 'mastercard':
            // The one mark that is a shape rather than a word: two interlocking
            // circles are recognised without any text at all.
            return '<svg class="sik-paymark__svg" viewBox="0 0 40 24" role="img" aria-hidden="true" focusable="false">'
                . '<circle cx="16" cy="12" r="8" fill="#EB001B"/>'
                . '<circle cx="24" cy="12" r="8" fill="#F79E1B"/>'
                . '<path d="M20 5.6a8 8 0 000 12.8 8 8 0 000-12.8z" fill="#FF5F00"/>'
                . '</svg>';

        case 'rupay':
            return '<b class="sik-paymark__word"><span style="color:#0B7BC1">Ru</span><span style="color:#4CA22F">Pay</span></b>';

        case 'upi':
            return '<b class="sik-paymark__word"><span style="color:#097939">UP</span><span style="color:#E8801A">I</span></b>';

        case 'discover':
            // The wordmark plus the orange arc the card is recognised by.
            return '<b class="sik-paymark__word sik-paymark__word--wide" style="color:#231F20">Discover</b>'
                . '<i class="sik-paymark__arc" aria-hidden="true"></i>';

        case 'amex':
            return '<b class="sik-paymark__word" style="color:#006FCF;letter-spacing:.02em">AMEX</b>';

        case 'cod':
            return '<span class="sik-paymark__cod">' . icon('wallet', 'w-4 h-4') . '<b>Cash</b></span>';
    }

    return '';
}

/** The accessible name for a mark, since the artwork itself is decorative. */
function payment_mark_label(string $key): string
{
    return [
        'visa'       => 'Visa',
        'mastercard' => 'Mastercard',
        'rupay'      => 'RuPay',
        'upi'        => 'UPI',
        'amex'       => 'American Express',
        'discover'   => 'Discover',
        'cod'        => 'Cash on delivery',
    ][$key] ?? $key;
}
