<?php
/**
 * ShopInnKart - Back-in-stock alert signup.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/init.php';

api_require_method(['POST']);
api_require_csrf();
api_rate_limit('stock_alert', 10, 300);

$v = new Validator(request_all(), [
    'product_id' => 'Product',
    'email'      => 'Email address',
]);
$v->required('product_id')->integer('product_id')
  ->required('email')->email('email')->max('email', 190);

if ($v->fails()) {
    json_validation_error($v->errors());
}

$productId = request_int('product_id');
$variantId = request_int('variant_id');
$email = mb_strtolower(trim((string) request_input('email', '')));

$product = get_product($productId);
if ($product === null) {
    json_error('That product is no longer available.', [], 404);
}

$variant = null;
if ($variantId > 0) {
    $variant = get_variant($variantId, $productId);
    if ($variant === null) {
        json_validation_error(['variant_id' => 'Choose a valid variant.']);
    }
}

// Stock always comes from the database, never from the form that was posted.
$stock = $variant !== null ? (int) $variant['stock'] : (int) $product['stock'];
if ($stock > 0) {
    json_success('Good news - this item is in stock right now.', [
        'in_stock' => true,
        'url'      => $product['url'],
    ]);
}

// MySQL treats NULLs as distinct in a unique index, so the variant-less case
// needs its own duplicate check before INSERT IGNORE can be relied on.
$already = Database::fetchColumn(
    'SELECT `id` FROM `stock_alerts`
     WHERE `product_id` = :pid AND `variant_id` <=> :vid AND `email` = :email AND `notified_at` IS NULL
     LIMIT 1',
    ['pid' => $productId, 'vid' => $variant !== null ? (int) $variant['id'] : null, 'email' => $email]
);

if ($already !== null) {
    json_success('You are already on the list. We will email you the moment it is back.', ['duplicate' => true]);
}

Database::query(
    'INSERT IGNORE INTO `stock_alerts` (`product_id`, `variant_id`, `user_id`, `email`)
     VALUES (:pid, :vid, :uid, :email)',
    [
        'pid'   => $productId,
        'vid'   => $variant !== null ? (int) $variant['id'] : null,
        'uid'   => current_user_id(),
        'email' => $email,
    ]
);

json_success('We will email you when this is back in stock.', [
    'product_id' => $productId,
    'variant_id' => $variant !== null ? (int) $variant['id'] : null,
]);
