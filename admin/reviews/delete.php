<?php
/**
 * ShopInnKart Admin - Delete one or more reviews.
 *
 * Deleting is permanent, so it is a separate permission from moderation.
 * Prefer Reject when the review only needs to come off the product page.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require_action('reviews.delete');

require_once ADMIN_PATH . '/reviews/_shared.php';

$ids  = review_target_ids();
$rows = review_rows($ids);

if ($rows === []) {
    flash('error', 'Select at least one review first.');
    redirect_back(reviews_return_url());
}

$reviewIds  = array_map('intval', array_column($rows, 'id'));
$productIds = array_column($rows, 'product_id');

[$placeholders, $params] = Database::inPlaceholders($reviewIds, 'id');

// review_images rows are owned by the review, but the files on disk are not
// removed by the foreign key, so they are collected before the delete.
$images = Database::fetchColumnAll(
    'SELECT `image` FROM `review_images` WHERE `review_id` IN (' . $placeholders . ')',
    $params
);

Database::delete('reviews', '`id` IN (' . $placeholders . ')', $params);

foreach ($images as $image) {
    delete_upload((string) $image);
}

// The product average has to lose whatever those approved rows contributed.
reviews_recalculate($productIds);

log_activity('review.deleted', 'review', count($rows) === 1 ? $reviewIds[0] : null,
    count($rows) . ' review(s) deleted');
admin_after_write();

flash('success', count($rows) . ' review' . (count($rows) === 1 ? '' : 's') . ' deleted.');

// Going "back" to view.php would land on a row that no longer exists and stack
// a "no longer exists" error on top of the success message.
if (strpos((string) ($_SERVER['HTTP_REFERER'] ?? ''), 'reviews/view.php') !== false) {
    redirect(reviews_return_url());
}
redirect_back(reviews_return_url());
