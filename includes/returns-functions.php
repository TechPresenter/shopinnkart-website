<?php
/**
 * ShopInnKart - Customer return and exchange requests.
 *
 * The store already had the staff half of returns: admin/orders/returns.php
 * marks a delivered order Returned or Refunded and puts the stock back. What
 * was missing is the customer half — asking for one, and the approval trail
 * that decides it. This file is that half.
 *
 * Deliberately whole-order, because the rest of the application is: there is
 * no line-level return anywhere, orders.return_reason is a single field on the
 * order, and update_order_status() restocks every unit at once. Pretending to
 * support partial returns here would produce records nothing else could honour.
 *
 * Raising or approving a request does NOT move the order status. Marking an
 * order 'returned' restocks the goods, reverses the coupon and releases the
 * money — all while the parcel is still in the customer's house. That step
 * stays where it always was: staff run it from admin/orders/returns.php once
 * the goods are physically back.
 */

declare(strict_types=1);

/** The fixed reason list. Free text goes in the comment, never the reason. */
const RETURN_REASONS = [
    'damaged'        => 'Arrived damaged or broken',
    'defective'      => 'Faulty or not working',
    'wrong_item'     => 'Wrong item delivered',
    'not_as_described' => 'Not as described on the website',
    'size_fit'       => 'Size or fit is wrong',
    'missing_parts'  => 'Parts or accessories missing',
    'changed_mind'   => 'No longer needed',
    'better_price'   => 'Found a better price elsewhere',
    'other'          => 'Something else',
];

const RETURN_STATUS_PENDING   = 'pending';
const RETURN_STATUS_APPROVED  = 'approved';
const RETURN_STATUS_REJECTED  = 'rejected';
const RETURN_STATUS_COMPLETED = 'completed';
const RETURN_STATUS_CANCELLED = 'cancelled';

const RETURN_STATUSES = [
    RETURN_STATUS_PENDING   => 'Awaiting review',
    RETURN_STATUS_APPROVED  => 'Approved',
    RETURN_STATUS_REJECTED  => 'Rejected',
    RETURN_STATUS_COMPLETED => 'Completed',
    RETURN_STATUS_CANCELLED => 'Cancelled',
];

const RETURN_STATUS_COLORS = [
    RETURN_STATUS_PENDING   => 'amber',
    RETURN_STATUS_APPROVED  => 'green',
    RETURN_STATUS_REJECTED  => 'red',
    RETURN_STATUS_COMPLETED => 'blue',
    RETURN_STATUS_CANCELLED => 'gray',
];

// ===========================================================================
//  ELIGIBILITY
// ===========================================================================

/**
 * Days left in the return window, or 0 when it has closed.
 *
 * account_return_days_left() computes the same thing but lives in
 * account-layout.php, which the bootstrap does not load — calling it from an
 * API endpoint or an admin page would fatal. This copy is the one safe
 * everywhere, and it is the one the eligibility decision uses.
 */
function return_days_left(array $order): int
{
    $window = max(0, setting_int('return_window_days', 7));
    if ($window === 0 || empty($order['delivered_at'])) {
        return 0;
    }

    $deliveredAt = strtotime((string) $order['delivered_at']);
    if ($deliveredAt === false) {
        return 0;
    }

    $elapsed = (int) floor((time() - $deliveredAt) / 86400);

    return max(0, $window - $elapsed);
}

/**
 * May the customer raise a request for this order right now?
 *
 * @return array{ok:bool, reason:string}
 */
function can_request_return(array $order): array
{
    $no = static fn (string $reason): array => ['ok' => false, 'reason' => $reason];

    if (!setting_bool('returns_enabled', true)) {
        return $no('Returns are not accepted at the moment.');
    }

    // The staff form only accepts delivered -> returned / refunded, so a
    // request against anything else could never be actioned.
    if ((string) $order['status'] !== ORDER_STATUS_DELIVERED) {
        return $no('Only delivered orders can be returned.');
    }

    $daysLeft = return_days_left($order);
    if ($daysLeft <= 0) {
        return $no('The return window for this order has closed.');
    }

    if (get_open_return_request((int) $order['id']) !== null) {
        return $no('There is already an open request for this order.');
    }

    return ['ok' => true, 'reason' => ''];
}

// ===========================================================================
//  READ
// ===========================================================================

function get_return_request(int $id): ?array
{
    return Database::fetch('SELECT * FROM `return_requests` WHERE `id` = :id LIMIT 1', ['id' => $id]);
}

/** The live request for an order, if there is one. */
function get_open_return_request(int $orderId): ?array
{
    return Database::fetch(
        "SELECT * FROM `return_requests`
         WHERE `order_id` = :id AND `status` IN ('pending', 'approved')
         ORDER BY `id` DESC LIMIT 1",
        ['id' => $orderId]
    );
}

/** Every request raised against an order, newest first. */
function get_return_requests_for_order(int $orderId): array
{
    return Database::fetchAll(
        'SELECT * FROM `return_requests` WHERE `order_id` = :id ORDER BY `id` DESC',
        ['id' => $orderId]
    );
}

function return_reason_label(string $code): string
{
    return RETURN_REASONS[$code] ?? ucfirst(str_replace('_', ' ', $code));
}

// ===========================================================================
//  WRITE
// ===========================================================================

/**
 * Raise a return or exchange request.
 *
 * The caller must already have proved the visitor owns the order — this
 * re-checks eligibility (the button may have been rendered before the window
 * closed) but not identity.
 *
 * @return array{ok:bool, message:string, request:?array}
 */
function create_return_request(array $order, string $reasonCode, string $comment, string $type = 'return'): array
{
    $fail = static fn (string $message): array => ['ok' => false, 'message' => $message, 'request' => null];

    if (!array_key_exists($reasonCode, RETURN_REASONS)) {
        return $fail('Please choose a reason from the list.');
    }

    $type = in_array($type, ['return', 'exchange'], true) ? $type : 'return';
    if ($type === 'exchange' && !setting_bool('exchange_enabled', true)) {
        $type = 'return';
    }

    $orderId = (int) $order['id'];

    // A customer who double-submits, or hits back and resubmits, is not making
    // a mistake worth an error message — hand them the request they already
    // have. Checked before the eligibility test so it does not surface as
    // "there is already an open request", which reads like a rejection.
    $open = get_open_return_request($orderId);
    if ($open !== null) {
        return ['ok' => true, 'message' => 'You already have an open request for this order.', 'request' => $open];
    }

    // Re-checked server-side: the form may have been rendered before the
    // warehouse moved the order on or the window ran out.
    $eligible = can_request_return($order);
    if (!$eligible['ok']) {
        return $fail($eligible['reason']);
    }

    try {
        $id = Database::insert('return_requests', [
            'order_id'       => $orderId,
            'order_number'   => (string) $order['order_number'],
            'user_id'        => $order['user_id'] === null ? null : (int) $order['user_id'],
            'customer_name'  => (string) $order['customer_name'],
            'customer_email' => (string) $order['customer_email'],
            'type'           => $type,
            'reason'         => $reasonCode,
            'comment'        => $comment === '' ? null : mb_substr($comment, 0, 2000),
            'status'         => RETURN_STATUS_PENDING,
        ]);
    } catch (PDOException $e) {
        // uq_return_open: a double-submitted form, not a failure.
        if ($e->getCode() === '23000') {
            $existing = get_open_return_request($orderId);

            return $existing === null
                ? $fail('We could not record that request. Please try again.')
                : ['ok' => true, 'message' => 'You already have an open request for this order.', 'request' => $existing];
        }

        ErrorHandler::log('error', 'Return request failed for order ' . $orderId . ': ' . $e->getMessage());
        return $fail('We could not record that request. Please try again.');
    } catch (Throwable $e) {
        ErrorHandler::log('error', 'Return request failed for order ' . $orderId . ': ' . $e->getMessage());
        return $fail('We could not record that request. Please try again.');
    }

    $request = get_return_request($id);

    // The order carries the reason so the admin order screen and the existing
    // {{return_reason}} placeholder both see it without a join.
    Database::update(
        'orders',
        ['return_reason' => mb_substr(return_reason_label($reasonCode) . ($comment !== '' ? ' — ' . $comment : ''), 0, 255)],
        '`id` = :id',
        ['id' => $orderId]
    );

    Database::insert('order_status_history', [
        'order_id'   => $orderId,
        'status'     => (string) $order['status'],
        'note'       => ucfirst($type) . ' requested by the customer: ' . return_reason_label($reasonCode),
        'changed_by' => 'customer',
    ]);

    // notify_return() fans out to the admins itself for the 'requested' stage.
    notify_return(get_order($orderId), 'requested', $comment, $request);

    return ['ok' => true, 'message' => 'Your request has been received.', 'request' => $request];
}

/**
 * Approve or reject a pending request.
 *
 * Deliberately does not touch the order status: the goods are still with the
 * customer at this point. Staff mark the order Returned or Refunded from
 * admin/orders/returns.php once the parcel is physically back, which is what
 * restocks it.
 *
 * @return array{ok:bool, message:string}
 */
function decide_return_request(int $requestId, string $decision, string $note, ?int $adminId = null): array
{
    $request = get_return_request($requestId);

    if ($request === null) {
        return ['ok' => false, 'message' => 'That request no longer exists.'];
    }
    if (!in_array($decision, [RETURN_STATUS_APPROVED, RETURN_STATUS_REJECTED, RETURN_STATUS_COMPLETED, RETURN_STATUS_CANCELLED], true)) {
        return ['ok' => false, 'message' => 'Unknown decision.'];
    }
    if ((string) $request['status'] === $decision) {
        return ['ok' => false, 'message' => 'This request is already ' . RETURN_STATUSES[$decision] . '.'];
    }
    if ($decision === RETURN_STATUS_REJECTED && trim($note) === '') {
        return ['ok' => false, 'message' => 'A rejection needs a reason the customer can read.'];
    }

    $orderId = (int) $request['order_id'];

    Database::update('return_requests', [
        'status'     => $decision,
        'admin_note' => $note === '' ? null : mb_substr($note, 0, 500),
        'decided_at' => date('Y-m-d H:i:s'),
        'decided_by' => $adminId,
    ], '`id` = :id', ['id' => $requestId]);

    Database::insert('order_status_history', [
        'order_id'   => $orderId,
        'status'     => (string) (get_order($orderId)['status'] ?? ORDER_STATUS_DELIVERED),
        'note'       => ucfirst((string) $request['type']) . ' request ' . $decision
            . ($note !== '' ? ': ' . mb_substr($note, 0, 300) : ''),
        'changed_by' => 'admin',
        'admin_id'   => $adminId,
    ]);

    $updated = get_return_request($requestId);

    if ($decision === RETURN_STATUS_APPROVED || $decision === RETURN_STATUS_REJECTED) {
        notify_return(get_order($orderId), $decision === RETURN_STATUS_APPROVED ? 'approved' : 'rejected', $note, $updated);
    }

    log_activity(
        'return.' . $decision,
        'order',
        $orderId,
        ucfirst((string) $request['type']) . ' request #' . $requestId . ' for order '
        . $request['order_number'] . ' marked ' . $decision
    );

    return ['ok' => true, 'message' => 'Request marked ' . RETURN_STATUSES[$decision] . '.'];
}

/** Counts for the admin queue badges. */
function return_request_counts(): array
{
    try {
        $counts = Database::fetchPairs('SELECT `status`, COUNT(*) FROM `return_requests` GROUP BY `status`');
    } catch (Throwable $e) {
        return [];
    }

    return $counts;
}
