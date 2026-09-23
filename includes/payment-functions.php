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
 * already verified the signature, and every figure is checked against the
 * order before it is allowed to move anything. An event that cannot prove what
 * it claims is RECORDED and left for a human - never applied "just in case".
 */

declare(strict_types=1);

/**
 * Where a payment may go next.
 *
 * Gateways redeliver, and they redeliver out of order: the customer's first
 * attempt fails, the second succeeds, and the two webhooks arrive the wrong
 * way round. Without this table the late `payment.failed` (a different event
 * id, so not a duplicate) set a paid, confirmed order back to failed and
 * emailed the customer that their payment had not gone through.
 *
 * Read as "from => the statuses an event may move it to". A status mapping to
 * itself is what makes a redelivery a harmless no-op. 'partially_refunded' is
 * never what an event says - it is what WE work out from the refund total -
 * so it appears only on the left of a refund row.
 */
const PAYMENT_STATUS_FLOW = [
    PAYMENT_STATUS_PENDING => [PAYMENT_STATUS_PENDING, PAYMENT_STATUS_PAID, PAYMENT_STATUS_FAILED],
    // A failed attempt is not final: the customer pays again on the same order.
    PAYMENT_STATUS_FAILED  => [PAYMENT_STATUS_FAILED, PAYMENT_STATUS_PAID],
    // Money that has been taken can only be given back.
    PAYMENT_STATUS_PAID    => [PAYMENT_STATUS_PAID, PAYMENT_STATUS_REFUNDED],
    PAYMENT_STATUS_PARTIALLY_REFUNDED => [PAYMENT_STATUS_PARTIALLY_REFUNDED, PAYMENT_STATUS_REFUNDED],
    PAYMENT_STATUS_REFUNDED => [PAYMENT_STATUS_REFUNDED],
];

/** May a gateway event reporting $to be applied to an order that is $from? */
function payment_status_move_allowed(string $from, string $to): bool
{
    return in_array($to, PAYMENT_STATUS_FLOW[$from] ?? [], true);
}

/**
 * The currency this order was priced and charged in.
 *
 * The payments row written at checkout is the order's own record of it, so a
 * later change to the store's currency setting cannot retroactively re-price
 * an old order - or make a foreign-currency webhook match one.
 */
function order_currency(array $order): string
{
    $stored = (string) (Database::fetchColumn(
        'SELECT `currency` FROM `payments` WHERE `order_id` = :o ORDER BY `id` ASC LIMIT 1',
        ['o' => (int) $order['id']]
    ) ?? '');

    return strtoupper(trim($stored) !== '' ? trim($stored) : (string) setting('currency_code', CURRENCY));
}

/** Everything a gateway has told us it gave back on this order. */
function payment_refunded_total(int $orderId): float
{
    return money_round((float) Database::fetchColumn(
        'SELECT COALESCE(SUM(`amount`), 0) FROM `payment_refunds` WHERE `order_id` = :o',
        ['o' => $orderId]
    ));
}

/**
 * Record one refund. UNIQUE(gateway, reference) is what stops a redelivered
 * refund event being counted twice - the amounts are SUMMED, so a duplicate
 * row would refund the order twice on paper and flip it to fully refunded.
 *
 * @return bool true when this refund was new
 */
function payment_record_refund(
    int $orderId,
    string $gateway,
    string $reference,
    string $eventId,
    float $amount,
    string $currency,
    ?int $paymentId = null,
    string $reason = ''
): bool {
    try {
        Database::insert('payment_refunds', [
            'order_id'   => $orderId,
            'payment_id' => $paymentId !== null && $paymentId > 0 ? $paymentId : null,
            'gateway'    => $gateway,
            // A gateway that sends no refund id still has an event id, and that
            // is unique per delivery - enough to recognise the redelivery.
            'reference'  => mb_substr($reference !== '' ? $reference : $eventId, 0, 190),
            'event_id'   => mb_substr($eventId, 0, 190),
            'amount'     => money_round($amount),
            'currency'   => mb_substr(strtoupper($currency), 0, 10),
            'reason'     => $reason !== '' ? mb_substr($reason, 0, 255) : null,
        ]);

        return true;
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            return false;   // already on record
        }
        throw $e;
    }
}

/**
 * Record a gateway event and apply it to the order exactly once.
 *
 * The claim and the money are ONE transaction. They used to be separate
 * statements: a failure after the claim (a deadlock on the orders UPDATE, a
 * database blip) answered 500, the gateway redelivered, the redelivery hit
 * UNIQUE(gateway, gateway_event_id) and was waved through as "already
 * processed" - leaving an order that had been paid for sitting at pending
 * forever. Now the claim rolls back with everything else and the retry has
 * something to do.
 *
 * Emails, the invoice and the order-status move run AFTER the commit, and are
 * written so that running them twice changes nothing - which is also how a
 * process that died between the commit and them is put right by the
 * redelivery (see payment_reconcile_order()).
 *
 * @param array $event gateway (required), event_id, order_id|order_number,
 *                     status (paid|failed|pending|refunded), amount, currency,
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

    $orderId   = (int) $order['id'];
    $reference = trim((string) ($event['reference'] ?? ''));

    // An event id is what makes replay detection possible. When the gateway
    // gives none, the payment reference plus the status is the next best key.
    if ($eventId === '') {
        $eventId = $reference !== ''
            ? $reference . ':' . $status
            : 'order:' . $orderId . ':' . $status;
    }

    // ---- What the event has to prove before it may move money -------------
    //
    // Every one of these used to fail OPEN. A signed "paid" with no amount
    // field fell back to the order total and confirmed the order; the currency
    // was never looked at at all, so a genuine signed payment of the same
    // NUMBER in a cheaper currency settled an INR order; and no event was ever
    // matched to the method the order was actually placed with, so one
    // gateway's signed event could pay a COD order.
    $amount = isset($event['amount']) && is_numeric($event['amount'])
        ? money_round((float) $event['amount'])
        : null;
    $currency      = strtoupper(trim((string) ($event['currency'] ?? '')));
    $orderCurrency = order_currency($order);
    $current       = (string) $order['payment_status'];
    $movesMoney    = in_array($status, [PAYMENT_STATUS_PAID, PAYMENT_STATUS_REFUNDED], true);

    // 'review' is "this does not add up, a human must look"; 'ignore' is
    // "sound, but there is nothing to do with it" - a stale redelivery. They
    // are told apart so an out-of-order webhook does not fill the reconcile
    // queue an operator is meant to be able to trust.
    $verdict = null;
    $reason  = '';

    if (strtolower((string) $order['payment_method']) !== $gateway) {
        $verdict = 'review';
        $reason  = sprintf('Event came from %s but order %s was placed with %s.',
            $gateway, $order['order_number'], $order['payment_method']);
    } elseif ($movesMoney && $amount === null) {
        $verdict = 'review';
        $reason  = 'The gateway reported no amount, so there is nothing to check against the order.';
    } elseif ($movesMoney && $amount <= 0) {
        $verdict = 'review';
        $reason  = 'The gateway reported an amount of ' . money((float) $amount) . '.';
    } elseif ($movesMoney && $currency === '' && webhook_gateway_reports_currency($gateway)) {
        $verdict = 'review';
        $reason  = 'The gateway reported no currency.';
    } elseif ($movesMoney && $currency !== '' && $currency !== $orderCurrency) {
        $verdict = 'review';
        $reason  = sprintf('Paid in %s; order %s is in %s.', $currency, $order['order_number'], $orderCurrency);
    } elseif ($status === PAYMENT_STATUS_PAID && abs((float) $amount - (float) $order['total_amount']) >= 0.01) {
        $verdict = 'review';
        $reason  = sprintf('Gateway says %.2f, order total is %.2f.', (float) $amount, (float) $order['total_amount']);
    } elseif ($status === PAYMENT_STATUS_REFUNDED
        && !in_array($current, [PAYMENT_STATUS_PAID, PAYMENT_STATUS_PARTIALLY_REFUNDED], true)) {
        $verdict = 'review';
        $reason  = 'A refund arrived for an order whose payment status is ' . $current . '.';
    } elseif ($status === PAYMENT_STATUS_REFUNDED
        && (float) $amount > money_round((float) $order['total_amount'] - payment_refunded_total($orderId)) + 0.005) {
        // A paid event has to match the order to the paisa; a refund had no
        // ceiling at all, so a single event could bank ₹9,99,999 against a ₹428
        // order. The row went into payment_refunds at its full face value, every
        // report that SUMs that table was wrong by the difference, and
        // notify_refund() told the customer that amount was on its way back.
        // What is still refundable is what was charged, less what has already
        // come back.
        $verdict = 'review';
        $reason  = sprintf('A refund of %s on order %s, where only %s of the %s charged is still refundable.',
            money((float) $amount), $order['order_number'],
            money(max(0.0, (float) $order['total_amount'] - payment_refunded_total($orderId))),
            money((float) $order['total_amount']));
    } elseif (!payment_status_move_allowed($current, $status)) {
        $verdict = 'ignore';
        $reason  = sprintf('Order %s is %s; a %s event cannot follow that.',
            $order['order_number'], $current, $status);
    }

    // What the ledger row is called, so a reconciliation screen can filter it.
    $rowStatus = $verdict === 'review' ? 'needs_review' : ($verdict === 'ignore' ? 'ignored' : $status);
    $rowAmount = $amount ?? 0.0;

    // ---- Claim and apply, in ONE transaction ------------------------------
    $outcome = Database::transaction(static function () use (
        $event, $gateway, $status, $eventId, $orderId, $order, $reference,
        $amount, $currency, $orderCurrency, $verdict, $rowStatus, $rowAmount
    ): array {
        // The INSERT is the lock: whoever wins it owns this event, and every
        // replay lands on the duplicate branch.
        try {
            Database::insert('payment_transactions', [
                'payment_id'       => null,
                'order_id'         => $orderId,
                'gateway'          => $gateway,
                'event'            => mb_substr((string) ($event['event'] ?? ('payment.' . $status)), 0, 60),
                'gateway_event_id' => mb_substr($eventId, 0, 190),
                'amount'           => $rowAmount,
                'status'           => $rowStatus,
                'message'          => isset($event['message']) ? mb_substr((string) $event['message'], 0, 500) : null,
                'payload'          => isset($event['payload'])
                    ? json_encode(payment_redact_payload((array) $event['payload']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : null,
            ]);
        } catch (PDOException $e) {
            // 23000 is the replay. Anything else is our own fault and must
            // reach the gateway as a 500 so it redelivers - answering "not
            // applied" to a database blip threw the payment away.
            if ($e->getCode() !== '23000') {
                throw $e;
            }

            return ['duplicate' => true];
        }

        // A refused event is still WRITTEN DOWN, and the write is committed:
        // the replay of an event we have already judged must be recognised as
        // one, not judged again.
        if ($verdict !== null) {
            return ['blocked' => $verdict];
        }

        $paymentId = payment_upsert_row($orderId, $gateway, $reference, (float) $amount,
            $status === PAYMENT_STATUS_REFUNDED ? PAYMENT_STATUS_PAID : $status, $orderCurrency);

        if ($paymentId > 0) {
            Database::update('payment_transactions', ['payment_id' => $paymentId],
                '`gateway` = :g AND `gateway_event_id` = :e',
                ['g' => $gateway, 'e' => mb_substr($eventId, 0, 190)]);
        }

        // A refund is an amount, not a flag. The order's status follows the
        // SUM of what has come back, so one rupee off a twelve-thousand rupee
        // order is partially_refunded, not refunded.
        $target   = $status;
        $refunded = 0.0;
        if ($status === PAYMENT_STATUS_REFUNDED) {
            payment_record_refund($orderId, $gateway, $reference, $eventId, (float) $amount,
                $currency !== '' ? $currency : $orderCurrency, $paymentId,
                (string) ($event['message'] ?? ''));

            $refunded = payment_refunded_total($orderId);
            $target = ($refunded + 0.005) >= (float) $order['total_amount']
                ? PAYMENT_STATUS_REFUNDED
                : PAYMENT_STATUS_PARTIALLY_REFUNDED;

            Database::update('payments', ['status' => $target], '`order_id` = :o', ['o' => $orderId]);
        }

        if ((string) $order['payment_status'] !== $target) {
            Database::update('orders', ['payment_status' => $target], '`id` = :id', ['id' => $orderId]);
        }

        return ['applied' => true, 'target' => $target, 'refunded' => $refunded, 'payment_id' => $paymentId];
    });

    // ---- After the commit -------------------------------------------------
    if (!empty($outcome['duplicate'])) {
        ErrorHandler::log('info', sprintf('Duplicate %s callback for order %s (event %s).',
            $gateway, $order['order_number'], $eventId));

        // Quietly finish anything the first delivery committed but died before
        // doing. No customer mail: they were told the first time round.
        payment_reconcile_order($orderId, $gateway);

        return [
            'ok' => true, 'duplicate' => true, 'order' => get_order($orderId) ?? $order,
            'invoice' => get_invoice_for_order($orderId), 'message' => 'Already processed.',
        ];
    }

    if (isset($outcome['blocked'])) {
        if ($outcome['blocked'] === 'review') {
            ErrorHandler::log('error', 'Payment event held for review (' . $gateway . '): ' . $reason);
            security_event('webhook.payment_needs_review', 'high', [
                'gateway' => $gateway, 'order' => (string) $order['order_number'],
                'event' => $eventId, 'reason' => $reason,
            ]);

            return ['ok' => false, 'duplicate' => false, 'order' => $order, 'invoice' => null,
                'message' => 'Payment event held for review: ' . $reason];
        }

        ErrorHandler::log('info', 'Payment event ignored (' . $gateway . '): ' . $reason);

        // Sound, just late. The gateway is told it was dealt with so it stops.
        return ['ok' => true, 'duplicate' => false, 'order' => $order,
            'invoice' => get_invoice_for_order($orderId), 'message' => 'Nothing to apply: ' . $reason];
    }

    $target  = (string) $outcome['target'];
    $order   = get_order($orderId) ?? $order;
    $invoice = null;

    if ($status === PAYMENT_STATUS_PAID) {
        $invoice = payment_reconcile_order($orderId, $gateway);
        $order   = get_order($orderId) ?? $order;
        notify_payment_result($order, 'paid');
    } elseif ($status === PAYMENT_STATUS_FAILED) {
        notify_payment_result($order, 'failed', (string) ($event['message'] ?? ''));
    } elseif ($status === PAYMENT_STATUS_PENDING) {
        notify_payment_result($order, 'pending');
    } elseif ($status === PAYMENT_STATUS_REFUNDED) {
        invoice_sync_payment($orderId);
        // Goods that were supplied are credited, not un-invoiced.
        credit_note_for_refund($orderId, (float) $amount, 'refund:' . $eventId,
            'Refund reported by ' . $gateway);
        // The customer is told what actually came back, not the order total.
        notify_refund($order, 'completed', '', (float) $amount);
    }

    log_activity('payment.' . $target, 'order', $orderId, sprintf(
        'Gateway %s reported %s for order %s (%s).',
        $gateway,
        $status,
        $order['order_number'],
        money((float) $rowAmount)
    ));

    return [
        'ok'        => true,
        'duplicate' => false,
        'order'     => get_order($orderId) ?? $order,
        'invoice'   => $invoice ?? get_invoice_for_order($orderId),
        'message'   => 'Payment ' . $target . ' recorded.',
    ];
}

/**
 * Bring an order's paperwork in line with the payment already recorded on it.
 *
 * Everything here is idempotent, which is what lets it run on a redelivery: an
 * order that was marked paid and then lost its process before the confirmation
 * is picked up and finished by the gateway's next attempt, instead of being
 * waved through as a duplicate and left pending forever.
 *
 * @return array|null the invoice, when there is one
 */
function payment_reconcile_order(int $orderId, string $gateway = ''): ?array
{
    $order = get_order($orderId);
    if ($order === null || (string) $order['payment_status'] !== PAYMENT_STATUS_PAID) {
        return $orderId > 0 ? get_invoice_for_order($orderId) : null;
    }

    $invoice = null;

    // Confirming issues the invoice and sends the confirmation mail.
    if ((string) $order['status'] === ORDER_STATUS_PENDING) {
        update_order_status($orderId, ORDER_STATUS_CONFIRMED,
            'Payment received' . ($gateway !== '' ? ' via ' . $gateway : ''), 'system');
    } else {
        $invoice = issue_order_invoice(get_order($orderId));
    }

    $invoice = $invoice ?? get_invoice_for_order($orderId);
    invoice_sync_payment($orderId);

    return $invoice;
}

/**
 * Create or update the payments row for an order.
 * UNIQUE(gateway, reference) makes a repeated reference land on the update
 * branch rather than creating a second row.
 *
 * Failures are NOT swallowed any more: this runs inside the caller's
 * transaction, and a payments row that could not be written has to take the
 * claim down with it rather than leave an order marked paid with no payment
 * against it.
 */
function payment_upsert_row(int $orderId, string $gateway, string $reference, float $amount, string $status, string $currency = ''): int
{
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
        // The order's own currency, not today's store setting.
        'currency'  => $currency !== '' ? $currency : (string) setting('currency_code', CURRENCY),
        'reference' => $reference !== '' ? $reference : null,
    ]));
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

/**
 * Refund lifecycle mail. $stage is 'initiated' or 'completed'.
 *
 * @param float|null $amount What actually came back. It used to be assumed to
 *        be the order total, so a partial refund told the customer the whole
 *        order had been refunded - and then the money never arrived.
 */
function notify_refund(?array $order, string $stage, string $note = '', ?float $amount = null): void
{
    if ($order === null) {
        return;
    }

    $templateKey = $stage === 'initiated' ? 'refund_initiated' : 'refund_completed';
    $orderId = (int) $order['id'];

    $vars = order_notification_vars($order);
    $vars['refund_amount'] = money($amount !== null ? $amount : (float) $order['total_amount']);
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
