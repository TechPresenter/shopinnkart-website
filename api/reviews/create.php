<?php
/**
 * ShopInnKart - Submit a product review.
 *
 * One review per customer per product. Whether a purchase is required and
 * whether the review appears immediately are both admin settings, so the
 * response message has to explain which state the review landed in.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/init.php';

api_require_method(['POST']);
api_require_csrf();
$user = api_require_login();
api_rate_limit('reviews_create', 5, 600);

// Signed in, so this is a low bar: the honeypot and the timing check, which
// together stop a compromised account being driven as a review farm.
bot_guard_api('review', ['key' => (string) $user['id']]);

$v = new Validator(request_all(), [
    'product_id' => 'Product',
    'rating'     => 'Rating',
    'title'      => 'Review title',
    'comment'    => 'Review',
]);
$v->required('product_id')->integer('product_id')
  ->required('rating')->integer('rating')->between('rating', 1, 5)
  ->required('title')->min('title', 3)->max('title', 200)
  ->required('comment')->min('comment', 10)->max('comment', 2000);

if ($v->fails()) {
    json_validation_error($v->errors());
}

$productId = request_int('product_id');
$userId    = (int) $user['id'];

$product = Database::fetch(
    'SELECT p.`id`, p.`name` FROM `products` p WHERE p.`id` = :id AND ' . product_visible_sql() . ' LIMIT 1',
    ['id' => $productId]
);
if ($product === null) {
    json_error('That product is no longer available.', [], 404);
}

if (Database::exists('reviews', '`product_id` = :pid AND `user_id` = :uid', ['pid' => $productId, 'uid' => $userId])) {
    json_error('You have already reviewed this product. Edit requests go through our support team.', [], 409);
}

$verifiedPurchase = has_purchased_product($userId, $productId);

if (setting_bool('reviews_require_purchase', true) && !$verifiedPurchase) {
    json_error('Only verified buyers can review this product. Reviews open once your order is on its way.', [], 403);
}

// Tie the review to the order it came from so moderators can check the claim.
$orderId = Database::fetchColumn(
    'SELECT o.`id` FROM `order_items` oi
     INNER JOIN `orders` o ON o.`id` = oi.`order_id`
     WHERE o.`user_id` = :uid AND oi.`product_id` = :pid
     ORDER BY o.`id` DESC LIMIT 1',
    ['uid' => $userId, 'pid' => $productId]
);

$autoApprove = setting_bool('reviews_auto_approve', false);
$status = $autoApprove ? REVIEW_STATUS_APPROVED : REVIEW_STATUS_PENDING;

$customerName = current_user_name();
if ($customerName === '') {
    $customerName = 'ShopInnKart Customer';
}

$reviewId = Database::insert('reviews', [
    'product_id'        => $productId,
    'user_id'           => $userId,
    'order_id'          => $orderId === null ? null : (int) $orderId,
    'customer_name'     => $customerName,
    'rating'            => request_int('rating'),
    'title'             => (string) request_input('title', ''),
    'comment'           => (string) request_input('comment', ''),
    'verified_purchase' => $verifiedPurchase ? 1 : 0,
    'status'            => $status,
]);

if ($autoApprove) {
    recalculate_product_rating($productId);
}

json_success(
    $autoApprove
        ? 'Thanks! Your review is now live on ' . $product['name'] . '.'
        : 'Thanks! Your review has been sent for moderation and will appear once our team approves it (usually within 24 hours).',
    [
        'review_id'         => $reviewId,
        'status'            => $status,
        'published'         => $autoApprove,
        'verified_purchase' => $verifiedPurchase,
    ],
    201
);
