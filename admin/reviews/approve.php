<?php
/**
 * ShopInnKart Admin - Approve one or more reviews.
 *
 * Accepts a single `id` from a row action or `ids[]` from the bulk bar.
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

// Already-approved rows are skipped so re-submitting the form does not send a
// second "your review is live" email to the same customer.
$changed = array_values(array_filter($rows, static fn (array $row): bool => $row['status'] !== 'approved'));

if ($changed === []) {
    flash('info', 'Those reviews were already approved.');
    redirect_back(reviews_return_url());
}

[$placeholders, $params] = Database::inPlaceholders(array_column($changed, 'id'), 'id');
Database::update('reviews', ['status' => 'approved'], '`id` IN (' . $placeholders . ')', $params);

// rating_avg / rating_count only count approved rows, so both move here.
reviews_recalculate(array_column($changed, 'product_id'));

foreach ($changed as $row) {
    notify_review_approved($row);
}

log_activity('review.approved', 'review', count($changed) === 1 ? (int) $changed[0]['id'] : null,
    count($changed) . ' review(s) approved');
admin_after_write();

flash('success', count($changed) . ' review' . (count($changed) === 1 ? '' : 's')
    . ' approved and now visible on the storefront.');
redirect_back(reviews_return_url());
