<?php
/**
 * GET /api/checkout/totals.php
 *
 * Recalculates the cart totals for a chosen shipping + payment method so the
 * checkout summary can update without a page reload. The browser only picks
 * the method codes; every amount comes back from cart_totals().
 *
 * Params: shipping_method, payment_method
 * Returns: { totals, count }
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/init.php';

api_require_method(['GET']);

$shippingMethod = (string) request_input('shipping_method', SHIPPING_STANDARD);
$paymentMethod = (string) request_input('payment_method', '');

// Only an active, configured method may move the total. Anything else is
// dropped so a tampered code cannot invent a discount - or dodge the COD fee.
$shippingMethod = Database::exists(
    'shipping_methods',
    "`code` = :code AND `status` = 'active'",
    ['code' => $shippingMethod]
) ? $shippingMethod : SHIPPING_STANDARD;

$paymentRow = $paymentMethod === '' ? null : Database::fetch(
    "SELECT `code`, `name`, `extra_charge`, `discount_percent`
     FROM `payment_methods` WHERE `code` = :code AND `status` = 'active' LIMIT 1",
    ['code' => $paymentMethod]
);

$items = cart_items();
$totals = cart_totals($items, $shippingMethod, $paymentRow === null ? null : (string) $paymentRow['code']);

// Label the surcharge / discount rows so the summary can name them.
$totals['payment_method'] = $paymentRow === null ? null : (string) $paymentRow['code'];
$totals['payment_charge_label'] = $paymentRow === null || (float) $paymentRow['extra_charge'] <= 0
    ? null
    : $paymentRow['name'] . ' handling fee';
$totals['payment_discount_label'] = $paymentRow === null || (float) $paymentRow['discount_percent'] <= 0
    ? null
    : rtrim(rtrim(number_format((float) $paymentRow['discount_percent'], 2, '.', ''), '0'), '.') . '% prepaid discount';
$totals['display']['payment_charge'] = money($totals['payment_charge']);
$totals['display']['payment_discount'] = money($totals['payment_discount']);

json_success('OK', [
    'totals' => $totals,
    'count'  => cart_count(),
]);
