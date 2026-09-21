<?php
/**
 * ShopInnKart - Shared helpers for the order API endpoints.
 *
 * api_place_order() backs both /api/checkout/process.php and
 * /api/orders/create.php so the two entry points can never drift apart.
 * All pricing, stock and coupon logic belongs to create_order() - nothing
 * here recalculates money.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/init.php';

// Not a route - api/index.php excludes api/includes/ for the same reason.
if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

/**
 * Validate the request, place the order and answer with the standard
 * envelope. Never returns - every path ends in a JSON response.
 */
function api_place_order(): void
{
    api_require_method(['POST']);
    api_require_csrf();
    api_rate_limit('checkout', 6, 300);

    $input = request_all();
    // The token is an authentication detail, not order data.
    unset($input[CSRF_TOKEN_NAME]);

    $result = create_order($input);

    if (!$result['ok']) {
        // 422 whether the payload was malformed or the cart went stale, so the
        // client can render field errors and a toast from one branch.
        json_error($result['message'], $result['errors'], 422);
    }

    $order = $result['order'];
    $orderNumber = (string) $order['order_number'];

    remember_placed_order($orderNumber);

    json_success($result['message'], [
        'order_number' => $orderNumber,
        'order_id'     => (int) $order['id'],
        'redirect'     => url('order-success.php?order=' . rawurlencode($orderNumber)),
    ]);
}

/**
 * Keep freshly placed order numbers in the session.
 * A guest has no account to look the order up in, so order-success.php uses
 * this list to decide whether the visitor may see the confirmation.
 */
function remember_placed_order(string $orderNumber): void
{
    $_SESSION['_last_order'] = $orderNumber;

    $recent = $_SESSION['_recent_orders'] ?? [];
    if (!is_array($recent)) {
        $recent = [];
    }
    $recent[] = $orderNumber;

    $_SESSION['_recent_orders'] = array_slice(array_values(array_unique($recent)), -10);
}

/** True when this session placed the given order. */
function session_placed_order(string $orderNumber): bool
{
    $recent = $_SESSION['_recent_orders'] ?? [];
    return is_array($recent) && in_array($orderNumber, $recent, true);
}

/** Status label + pill colour token for a status code. */
function api_order_status_pill(string $status): array
{
    return [
        'label' => ORDER_STATUSES[$status] ?? ucfirst(str_replace('_', ' ', $status)),
        'color' => ORDER_STATUS_COLORS[$status] ?? 'gray',
    ];
}

/**
 * An order row reduced to what a customer is allowed to see.
 * Built as an allowlist so internal columns (ip_address, user_agent,
 * admin_note) can never leak into an API response.
 */
function api_order_public(array $order): array
{
    $status = (string) $order['status'];
    $pill = api_order_status_pill($status);
    $paymentStatus = (string) $order['payment_status'];

    $subtotal = (float) $order['subtotal'];
    $discount = (float) $order['discount_amount'];
    $shipping = (float) $order['shipping_amount'];
    $tax = (float) $order['tax_amount'];
    $total = (float) $order['total_amount'];

    return [
        'id'                  => (int) $order['id'],
        'order_number'        => (string) $order['order_number'],
        'url'                 => url('order-details.php?order=' . rawurlencode((string) $order['order_number'])),

        'status'              => $status,
        'status_label'        => $pill['label'],
        'status_color'        => $pill['color'],
        'payment_status'      => $paymentStatus,
        'payment_status_label' => PAYMENT_STATUSES[$paymentStatus] ?? ucfirst($paymentStatus),
        'payment_method'      => (string) $order['payment_method'],
        'shipping_method'     => (string) $order['shipping_method'],

        'placed_at'           => $order['created_at'],
        'placed_on'           => format_datetime($order['created_at']),
        'placed_ago'          => time_ago($order['created_at']),
        'estimated_delivery'  => $order['estimated_delivery'],
        'estimated_delivery_display' => $order['estimated_delivery'] ? format_date($order['estimated_delivery']) : null,
        'delivered_at'        => $order['delivered_at'],
        'cancelled_at'        => $order['cancelled_at'],
        'cancel_reason'       => $order['cancel_reason'],

        'courier_name'        => $order['courier_name'],
        'tracking_number'     => $order['tracking_number'],
        'customer_note'       => $order['customer_note'],
        'coupon_code'         => $order['coupon_code'],

        'subtotal'            => $subtotal,
        'discount_amount'     => $discount,
        'shipping_amount'     => $shipping,
        'tax_amount'          => $tax,
        'total_amount'        => $total,

        'customer' => [
            'name'  => (string) $order['customer_name'],
            'email' => (string) $order['customer_email'],
            'phone' => (string) $order['customer_phone'],
        ],
        'shipping_address' => [
            'name'     => (string) $order['shipping_name'],
            'phone'    => (string) $order['shipping_phone'],
            'address'  => (string) $order['shipping_address'],
            'address2' => $order['shipping_address2'],
            'landmark' => $order['shipping_landmark'],
            'city'     => (string) $order['shipping_city'],
            'state'    => (string) $order['shipping_state'],
            'pincode'  => (string) $order['shipping_pincode'],
            'country'  => (string) $order['shipping_country'],
        ],

        'can_cancel' => can_cancel_order($order),

        'display' => [
            'subtotal' => money($subtotal),
            'discount' => money($discount),
            'shipping' => $shipping > 0 ? money($shipping) : 'FREE',
            'tax'      => money($tax),
            'total'    => money($total),
        ],
    ];
}

/**
 * Load an order the signed-in customer owns, or answer 403/404 and stop.
 * Guest orders (user_id NULL) are never reachable this way - they are looked
 * up through /api/orders/track.php instead.
 */
function api_require_own_order(int $userId, ?int $orderId, string $orderNumber = ''): array
{
    $order = null;
    if ($orderId !== null && $orderId > 0) {
        $order = get_order($orderId);
    } elseif ($orderNumber !== '') {
        $order = get_order_by_number($orderNumber);
    }

    if ($order === null) {
        json_error('We could not find that order.', [], 404);
    }
    if ($order['user_id'] === null || (int) $order['user_id'] !== $userId) {
        json_error('This order does not belong to your account.', [], 403);
    }

    return $order;
}
