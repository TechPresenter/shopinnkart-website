<?php
/**
 * ShopInnKart Admin - Review moderation helpers.
 *
 * approve.php, reject.php and delete.php all accept either a single `id` (row
 * action) or an `ids[]` array (bulk bar), so the moderation rules live here
 * once instead of being copied three times with three chances to drift.
 */

declare(strict_types=1);

if (!defined('SIK_BOOTSTRAPPED')) {
    http_response_code(403);
    exit;
}

/** Ids targeted by the current request, from `id` and/or `ids[]`. */
function review_target_ids(): array
{
    $ids = array_map('intval', input_array('ids'));
    $single = input_int('id', 0);
    if ($single > 0) {
        $ids[] = $single;
    }
    return array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
}

/** Load the rows a moderation action is about to touch. */
function review_rows(array $ids): array
{
    if ($ids === []) {
        return [];
    }
    [$placeholders, $params] = Database::inPlaceholders($ids, 'id');

    return Database::fetchAll(
        'SELECT r.`id`, r.`product_id`, r.`user_id`, r.`rating`, r.`status`, r.`customer_name`,
                p.`name` AS product_name
         FROM `reviews` r
         INNER JOIN `products` p ON p.`id` = r.`product_id`
         WHERE r.`id` IN (' . $placeholders . ')',
        $params
    );
}

/**
 * Re-derive rating_avg / rating_count for every product a moderation action
 * touched. Only approved reviews count, so any status change moves the number.
 */
function reviews_recalculate(array $productIds): void
{
    foreach (array_unique(array_map('intval', $productIds)) as $productId) {
        if ($productId > 0) {
            recalculate_product_rating($productId);
        }
    }
}

/** Where a moderation action should send the admin back to. */
function reviews_return_url(): string
{
    return admin_url('reviews/');
}
