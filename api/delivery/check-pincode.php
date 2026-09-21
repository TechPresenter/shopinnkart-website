<?php
/**
 * ShopInnKart - PIN code serviceability and delivery estimate.
 *
 * Used by the product page widget and by checkout to auto-fill city/state
 * and to enable or disable Cash on Delivery.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/init.php';

api_require_method(['GET']);
api_rate_limit('pincode_check', 40, 60);

$v = new Validator(request_all(), ['pincode' => 'PIN code']);
$v->required('pincode')->pincode('pincode');

if ($v->fails()) {
    json_validation_error($v->errors());
}

$pincode = (string) request_input('pincode', '');

$row = Database::fetch(
    'SELECT `pincode`, `city`, `state`, `is_serviceable`, `cod_available`, `delivery_days`
     FROM `pincodes` WHERE `pincode` = :pin LIMIT 1',
    ['pin' => $pincode]
);

$storeCod     = setting_bool('cod_enabled', true);
$defaultDays  = max(1, setting_int('default_delivery_days', 4));

if ($row !== null) {
    $serviceable  = (int) $row['is_serviceable'] === 1;
    $city         = (string) $row['city'];
    $state        = (string) $row['state'];
    $deliveryDays = max(1, (int) $row['delivery_days'] ?: $defaultDays);
    $codAvailable = $serviceable && $storeCod && (int) $row['cod_available'] === 1;
} else {
    // `pincodes` is a curated list of known courier SLAs, not a directory of
    // every Indian PIN code. Refusing an unlisted one would turn away real
    // customers our carriers do reach, so an unknown PIN falls back to the
    // store defaults and stays serviceable.
    $serviceable  = true;
    $city         = '';
    $state        = '';
    $deliveryDays = $defaultDays;
    $codAvailable = $storeCod;
}

// A product may be prepaid-only regardless of what the PIN code allows.
$productId = request_int('product_id');
if ($productId > 0) {
    $productCod = Database::fetchColumn(
        'SELECT p.`cod_available` FROM `products` p WHERE p.`id` = :id AND ' . product_visible_sql() . ' LIMIT 1',
        ['id' => $productId]
    );
    if ($productCod !== null && (int) $productCod === 0) {
        $codAvailable = false;
    }
}

$deliveryDate = $serviceable ? date('D, d M', strtotime('+' . $deliveryDays . ' days')) : '';

if (!$serviceable) {
    $where = $city !== '' ? $city . ' (' . $pincode . ')' : 'PIN code ' . $pincode;
    $message = 'Sorry, we do not deliver to ' . $where . ' yet.';
} else {
    $message = 'Delivery by ' . $deliveryDate
        . ($codAvailable ? ' - Cash on Delivery available.' : ' - prepaid orders only.');
}

json_success('OK', [
    'pincode'       => $pincode,
    'serviceable'   => $serviceable,
    'city'          => $city,
    'state'         => $state,
    'cod_available' => $codAvailable,
    'delivery_days' => $deliveryDays,
    'delivery_date' => $deliveryDate,
    'message'       => $message,
]);
