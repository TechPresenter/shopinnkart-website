<?php
/**
 * ShopInnKart Admin - Reject one or more reviews.
 *
 * Rejection keeps the row (so the same customer cannot simply resubmit it and
 * so the audit trail survives) but pulls it off the product page.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require_action('reviews.edit');

require_once ADMIN_PATH . '/reviews/_shared.php';

$ids  = review_target_ids();
$rows = review_rows($ids);

if ($rows === []) {
    flash('error', 'Select at least one review first.');
    redirect_back(reviews_return_url());
}

$changed = array_values(array_filter($rows, static fn (array $row): bool => $row['status'] !== 'rejected'));

if ($changed === []) {
    flash('info', 'Those reviews were already rejected.');
    redirect_back(reviews_return_url());
}

[$placeholders, $params] = Database::inPlaceholders(array_column($changed, 'id'), 'id');
Database::update('reviews', ['status' => 'rejected'], '`id` IN (' . $placeholders . ')', $params);

// A previously approved review dropping out has to come back off the average.
reviews_recalculate(array_column($changed, 'product_id'));

log_activity('review.rejected', 'review', count($changed) === 1 ? (int) $changed[0]['id'] : null,
    count($changed) . ' review(s) rejected');
admin_after_write();

flash('success', count($changed) . ' review' . (count($changed) === 1 ? '' : 's')
    . ' rejected and removed from the storefront.');
redirect_back(reviews_return_url());
