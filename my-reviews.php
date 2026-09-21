<?php
/**
 * ShopInnKart - Reviews written by the signed-in customer.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_once INCLUDES_PATH . '/account-layout.php';

$user = require_login();
$userId = (int) $user['id'];

$page = max(1, input_int('page', 1));
$total = Database::count('reviews', '`user_id` = :uid', ['uid' => $userId]);
$pagination = paginate($total, 8, $page);

$reviews = Database::fetchAll(
    'SELECT r.*, p.`name` AS product_name, p.`slug` AS product_slug, p.`main_image` AS product_image,
            o.`order_number`
     FROM `reviews` r
     LEFT JOIN `products` p ON p.`id` = r.`product_id`
     LEFT JOIN `orders` o ON o.`id` = r.`order_id` AND o.`user_id` = :ouid
     WHERE r.`user_id` = :uid
     ORDER BY r.`id` DESC
     LIMIT ' . $pagination['per_page'] . ' OFFSET ' . $pagination['offset'],
    ['uid' => $userId, 'ouid' => $userId]
);

// Photos attached to the reviews on this page, grouped by review.
$images = [];
if ($reviews !== []) {
    $reviewIds = array_map(static fn (array $row): int => (int) $row['id'], $reviews);
    [$placeholders, $params] = Database::inPlaceholders($reviewIds, 'r');
    foreach (Database::fetchAll(
        'SELECT `review_id`, `image` FROM `review_images` WHERE `review_id` IN (' . $placeholders . ') ORDER BY `id`',
        $params
    ) as $row) {
        $images[(int) $row['review_id']][] = (string) $row['image'];
    }
}

$approvedCount = Database::count(
    'reviews',
    '`user_id` = :uid AND `status` = :status',
    ['uid' => $userId, 'status' => REVIEW_STATUS_APPROVED]
);

seo_set([
    'title'  => 'My Reviews',
    'robots' => 'noindex, nofollow',
]);

require INCLUDES_PATH . '/header.php';

account_layout_open('my-reviews', [
    'subtitle' => $total === 0
        ? 'Reviews you write on product pages will be listed here.'
        : $total . ' review' . ($total === 1 ? '' : 's') . ' written, ' . $approvedCount . ' published.',
]);
?>

<?php if ($reviews === []): ?>
    <div class="sik-panel">
        <div class="sik-panel__body">
            <?= account_empty(
                'star',
                'No reviews yet',
                'Bought something recently? Open the product page and tell other shoppers what you thought.',
                'View my orders',
                url('orders.php')
            ) ?>
        </div>
    </div>
<?php else: ?>
    <div style="display:grid;gap:var(--sp-4)">
        <?php foreach ($reviews as $review): ?>
            <?php
            $reviewId = (int) $review['id'];
            $productUrl = $review['product_slug'] !== null ? product_url((string) $review['product_slug']) : null;
            ?>
            <article class="sik-panel">
                <div class="sik-panel__head">
                    <div style="display:flex;gap:var(--sp-3);align-items:center;min-width:0">
                        <img src="<?= e(img_url($review['product_image'])) ?>"
                             alt="<?= e((string) ($review['product_name'] ?? 'Product')) ?>"
                             width="44" height="44" loading="lazy"
                             style="width:44px;height:44px;object-fit:contain;border:1px solid var(--sik-border);border-radius:8px;background:#fff;padding:3px;flex:none">
                        <div style="min-width:0">
                            <?php if ($productUrl !== null): ?>
                                <a href="<?= e($productUrl) ?>" style="font-size:13.5px;font-weight:700;display:block">
                                    <?= e(str_limit((string) $review['product_name'], 60)) ?>
                                </a>
                            <?php else: ?>
                                <span style="font-size:13.5px;font-weight:700;display:block;color:var(--sik-muted)">
                                    Product no longer available
                                </span>
                            <?php endif; ?>
                            <span style="font-size:12px;color:var(--sik-muted)">
                                <?= e(format_date($review['created_at'])) ?>
                                <?php if (!empty($review['order_number'])): ?>
                                    &middot; order #<?= e($review['order_number']) ?>
                                <?php endif; ?>
                            </span>
                        </div>
                    </div>
                    <?= account_review_pill((string) $review['status']) ?>
                </div>

                <div class="sik-panel__body">
                    <div style="display:flex;gap:var(--sp-2);align-items:center;flex-wrap:wrap;margin-bottom:var(--sp-2)">
                        <?= rating_stars((float) $review['rating']) ?>
                        <?php if (!empty($review['title'])): ?>
                            <strong style="font-size:14px"><?= e($review['title']) ?></strong>
                        <?php endif; ?>
                        <?php if ((int) $review['verified_purchase'] === 1): ?>
                            <span class="sik-badge sik-badge--green">Verified purchase</span>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($review['comment'])): ?>
                        <p style="font-size:13.5px;line-height:1.7;color:var(--sik-muted)"><?= nl2br(e($review['comment'])) ?></p>
                    <?php endif; ?>

                    <?php if (!empty($images[$reviewId])): ?>
                        <div style="display:flex;gap:var(--sp-2);flex-wrap:wrap;margin-top:var(--sp-3)">
                            <?php foreach ($images[$reviewId] as $image): ?>
                                <img src="<?= e(img_url($image)) ?>" alt="" width="64" height="64" loading="lazy"
                                     style="width:64px;height:64px;object-fit:cover;border-radius:8px;border:1px solid var(--sik-border)">
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($review['status'] === REVIEW_STATUS_PENDING): ?>
                        <p class="sik-help" style="margin-top:var(--sp-3)">
                            Our team reads every review before it goes live. This usually takes a day.
                        </p>
                    <?php elseif ($review['status'] === REVIEW_STATUS_REJECTED): ?>
                        <p class="sik-help" style="margin-top:var(--sp-3)">
                            This review was not published because it did not meet our review guidelines.
                        </p>
                    <?php elseif ((int) $review['helpful_count'] > 0): ?>
                        <p class="sik-help" style="margin-top:var(--sp-3)">
                            <?= (int) $review['helpful_count'] ?> shopper<?= (int) $review['helpful_count'] === 1 ? '' : 's' ?>
                            found this helpful.
                        </p>
                    <?php endif; ?>

                    <?php if (!empty($review['admin_reply'])): ?>
                        <div style="margin-top:var(--sp-4);border-left:3px solid var(--sik-primary);background:var(--sik-soft);border-radius:0 var(--sik-radius-sm) var(--sik-radius-sm) 0;padding:var(--sp-3) var(--sp-4)">
                            <strong style="display:flex;gap:var(--sp-2);align-items:center;font-size:12.5px;color:var(--sik-primary)">
                                <?= icon('store', 'w-4 h-4') ?> Reply from ShopInnKart
                            </strong>
                            <p style="font-size:13px;line-height:1.7;margin-top:var(--sp-1)"><?= nl2br(e($review['admin_reply'])) ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </div>

    <?= account_pager($pagination) ?>
<?php endif; ?>

<?php
account_layout_close();
require INCLUDES_PATH . '/footer.php';
