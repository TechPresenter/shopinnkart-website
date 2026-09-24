<?php
/**
 * ShopInnKart Admin - Single review, with product and customer context.
 *
 * The reply saved here is the public "Store response" shown under the review
 * on the product page, so it is stored on the review row itself.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('reviews.view');

$id = input_int('id');
$review = $id > 0
    ? Database::fetch(
        'SELECT r.*, p.`name` AS product_name, p.`slug` AS product_slug, p.`sku` AS product_sku,
                p.`main_image` AS product_image, p.`rating_avg`, p.`rating_count`,
                u.`email` AS user_email, u.`phone` AS user_phone, u.`created_at` AS user_since,
                o.`order_number`
         FROM `reviews` r
         INNER JOIN `products` p ON p.`id` = r.`product_id`
         LEFT JOIN `users` u ON u.`id` = r.`user_id`
         LEFT JOIN `orders` o ON o.`id` = r.`order_id`
         WHERE r.`id` = :id',
        ['id' => $id]
    )
    : null;

if ($review === null) {
    flash('error', 'That review no longer exists.');
    redirect(admin_url('reviews/'));
}

$errors = [];

if (is_post()) {
    csrf_require();

    if (!admin_can('reviews.edit')) {
        flash('error', 'You do not have permission to reply to reviews.');
        redirect(admin_url('reviews/view.php?id=' . $id));
    }

    $reply = trim((string) ($_POST['admin_reply'] ?? ''));

    $v = new Validator(['admin_reply' => $reply], ['admin_reply' => 'Reply']);
    $v->max('admin_reply', 1500);

    if ($v->fails()) {
        $errors = $v->errors();
        $review['admin_reply'] = $reply;
    } else {
        Database::update('reviews', [
            'admin_reply' => $reply !== '' ? $reply : null,
        ], '`id` = :id', ['id' => $id]);

        log_activity('review.replied', 'review', $id,
            $reply !== ''
                ? 'Replied to a review on "' . $review['product_name'] . '"'
                : 'Removed the store reply on a review for "' . $review['product_name'] . '"');
        admin_after_write();

        flash('success', $reply !== '' ? 'Reply saved.' : 'Reply removed.');
        redirect(admin_url('reviews/view.php?id=' . $id));
    }
}

$reviewImages = Database::fetchColumnAll(
    'SELECT `image` FROM `review_images` WHERE `review_id` = :id ORDER BY `id`',
    ['id' => $id]
);

$productId   = (int) $review['product_id'];
$canModerate = admin_can('reviews.edit');

$pageTitle    = 'Review #' . $id;
$pageSubtitle = $review['customer_name'] . ' on ' . str_limit((string) $review['product_name'], 60);
$breadcrumbs  = [
    ['label' => 'Dashboard', 'url' => admin_url('dashboard.php')],
    ['label' => 'Reviews',   'url' => admin_url('reviews/')],
    ['label' => '#' . $id],
];

$pageActions = '<a class="ad-btn" href="' . e(admin_url('reviews/')) . '">'
    . icon('arrow-left', 'w-4 h-4') . ' Back to queue</a>';

require ADMIN_PATH . '/includes/header.php';
?>

<div class="ad-grid ad-grid--sidebar">
    <div style="display:grid;gap:16px">

        <div class="ad-card" style="margin:0">
            <div class="ad-card__head">
                <div>
                    <div class="ad-card__title">The review</div>
                    <div class="ad-card__sub">
                        Submitted <?= e(format_datetime($review['created_at'])) ?>
                        &middot; <?= e(time_ago($review['created_at'])) ?>
                    </div>
                </div>
                <?= admin_state_badge((string) $review['status']) ?>
            </div>
            <div class="ad-card__body">
                <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px">
                    <span style="color:#F59E0B"><?= rating_stars((float) $review['rating'], 'w-5 h-5') ?></span>
                    <strong><?= (int) $review['rating'] ?> / 5</strong>
                    <?php if ((int) $review['verified_purchase'] === 1): ?>
                        <span class="sik-status sik-status--green">Verified purchase</span>
                    <?php else: ?>
                        <span class="sik-status sik-status--gray">Unverified</span>
                    <?php endif; ?>
                    <?php if ((int) $review['helpful_count'] > 0): ?>
                        <span class="ad-muted"><?= number_format((int) $review['helpful_count']) ?> found this helpful</span>
                    <?php endif; ?>
                </div>

                <?php if (!empty($review['title'])): ?>
                    <h3 style="font-size:17px;font-weight:700;margin:0 0 8px"><?= e($review['title']) ?></h3>
                <?php endif; ?>

                <?php if (trim((string) $review['comment']) !== ''): ?>
                    <p style="margin:0;white-space:pre-line;line-height:1.7"><?= e($review['comment']) ?></p>
                <?php else: ?>
                    <p class="ad-muted" style="margin:0">The customer left a rating without a written comment.</p>
                <?php endif; ?>

                <?php if ($reviewImages !== []): ?>
                    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:16px">
                        <?php foreach ($reviewImages as $image): ?>
                            <a href="<?= e(img_url((string) $image)) ?>" target="_blank" rel="noopener">
                                <img src="<?= e(img_url((string) $image)) ?>" alt="Customer photo"
                                     style="width:92px;height:92px;object-fit:cover;border-radius:9px;
                                            border:1px solid var(--ad-border)" loading="lazy">
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="ad-card" style="margin:0">
            <div class="ad-card__head">
                <div>
                    <div class="ad-card__title">Store reply</div>
                    <div class="ad-card__sub">Published under the review on the product page.</div>
                </div>
            </div>
            <?php if ($canModerate): ?>
                <form method="post" action="<?= e(admin_url('reviews/view.php?id=' . $id)) ?>" data-guard-unsaved>
                    <?= csrf_field() ?>
                    <div class="ad-card__body">
                        <div class="ad-field">
                            <label class="sik-label" for="reviewAdminReply">Reply</label>
                            <textarea class="sik-textarea<?= isset($errors['admin_reply']) ? ' is-invalid' : '' ?>"
                                      id="reviewAdminReply" name="admin_reply" rows="6" maxlength="1500"
                                      style="min-height:150px"
                                      placeholder="Thanks for the feedback — here is what we are doing about it."><?= e($review['admin_reply'] ?? '') ?></textarea>
                            <?php if (isset($errors['admin_reply'])): ?>
                                <span class="sik-error"><?= e($errors['admin_reply']) ?></span>
                            <?php else: ?>
                                <span class="sik-help">
                                    Plain text, up to 1500 characters. Clear the box and save to remove the reply.
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="ad-card__foot">
                        <button type="submit" class="ad-btn ad-btn--primary">
                            <?= icon('check', 'w-4 h-4') ?> Save Reply
                        </button>
                    </div>
                </form>
            <?php else: ?>
                <div class="ad-card__body">
                    <?php if (!empty($review['admin_reply'])): ?>
                        <p style="margin:0;white-space:pre-line"><?= e($review['admin_reply']) ?></p>
                    <?php else: ?>
                        <p class="ad-muted" style="margin:0">No reply has been published for this review.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div style="display:grid;gap:16px;align-content:start">
        <?php if ($canModerate || admin_can('reviews.delete')): ?>
            <div class="ad-card" style="margin:0">
                <div class="ad-card__head"><div class="ad-card__title">Moderation</div></div>
                <div class="ad-card__body" style="display:grid;gap:10px">
                    <?php if ($canModerate && $review['status'] !== 'approved'): ?>
                        <form method="post" action="<?= e(admin_url('reviews/approve.php')) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= $id ?>">
                            <button type="submit" class="ad-btn ad-btn--success ad-btn--block">
                                <?= icon('check', 'w-4 h-4') ?> Approve &amp; publish
                            </button>
                        </form>
                    <?php endif; ?>

                    <?php if ($canModerate && $review['status'] !== 'rejected'): ?>
                        <form method="post" action="<?= e(admin_url('reviews/reject.php')) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= $id ?>">
                            <button type="submit" class="ad-btn ad-btn--block">
                                <?= icon('close', 'w-4 h-4') ?> Reject
                            </button>
                        </form>
                    <?php endif; ?>

                    <?php if (admin_can('reviews.delete')): ?>
                        <form method="post" action="<?= e(admin_url('reviews/delete.php')) ?>"
                              <?= admin_confirm_form_attrs(
                                  'The review from "' . $review['customer_name'] . '" is removed for good.',
                                  ['title' => 'Delete this review?', 'label' => 'Delete review']
                              ) ?>>
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= $id ?>">
                            <button type="submit" class="ad-btn ad-btn--danger ad-btn--block">
                                <?= icon('trash', 'w-4 h-4') ?> Delete permanently
                            </button>
                        </form>
                    <?php endif; ?>

                    <span class="ad-muted" style="font-size:var(--ad-text-xs)">
                        Approving or rejecting recalculates the product rating immediately.
                    </span>
                </div>
            </div>
        <?php endif; ?>

        <div class="ad-card" style="margin:0">
            <div class="ad-card__head"><div class="ad-card__title">Product</div></div>
            <div class="ad-card__body">
                <div class="ad-cellflex" style="margin-bottom:12px">
                    <img class="ad-thumb" src="<?= e(img_url($review['product_image'])) ?>"
                         alt="" width="48" height="48" loading="lazy">
                    <span style="min-width:0">
                        <span class="ad-cellflex__name" style="display:block"><?= e($review['product_name']) ?></span>
                        <span class="ad-cellflex__meta ad-mono"><?= e($review['product_sku']) ?></span>
                    </span>
                </div>
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px">
                    <span style="color:#F59E0B"><?= rating_stars((float) $review['rating_avg']) ?></span>
                    <span class="ad-muted" style="font-size:var(--ad-text-xs)">
                        <?= number_format((float) $review['rating_avg'], 2) ?> from
                        <?= number_format((int) $review['rating_count']) ?> approved review(s)
                    </span>
                </div>
                <div class="ad-btngroup">
                    <?php if (admin_can('products.edit')): ?>
                        <a class="ad-btn ad-btn--sm" href="<?= e(admin_url('products/edit.php?id=' . $productId)) ?>">
                            <?= icon('edit', 'w-4 h-4') ?> Edit
                        </a>
                    <?php endif; ?>
                    <a class="ad-btn ad-btn--sm" href="<?= e(product_url((string) $review['product_slug'])) ?>"
                       target="_blank" rel="noopener">
                        <?= icon('external', 'w-4 h-4') ?> Storefront
                    </a>
                </div>
            </div>
        </div>

        <div class="ad-card" style="margin:0">
            <div class="ad-card__head"><div class="ad-card__title">Customer</div></div>
            <div class="ad-card__body" style="display:grid;gap:10px;font-size:13.5px">
                <div class="ad-cellflex">
                    <span class="ad-avatar"><?= e(initials((string) $review['customer_name'])) ?></span>
                    <span style="min-width:0">
                        <span class="ad-cellflex__name" style="display:block"><?= e($review['customer_name']) ?></span>
                        <span class="ad-cellflex__meta">
                            <?= $review['user_id'] !== null ? 'Registered customer' : 'Guest' ?>
                        </span>
                    </span>
                </div>

                <?php if (!empty($review['user_email'])): ?>
                    <div style="display:flex;justify-content:space-between;gap:10px">
                        <span class="ad-muted">Email</span>
                        <span><?= e($review['user_email']) ?></span>
                    </div>
                <?php endif; ?>
                <?php if (!empty($review['user_phone'])): ?>
                    <div style="display:flex;justify-content:space-between;gap:10px">
                        <span class="ad-muted">Phone</span>
                        <span><?= e($review['user_phone']) ?></span>
                    </div>
                <?php endif; ?>
                <?php if (!empty($review['user_since'])): ?>
                    <div style="display:flex;justify-content:space-between;gap:10px">
                        <span class="ad-muted">Customer since</span>
                        <span><?= e(format_date($review['user_since'])) ?></span>
                    </div>
                <?php endif; ?>
                <div style="display:flex;justify-content:space-between;gap:10px">
                    <span class="ad-muted">Linked order</span>
                    <span>
                        <?php if (!empty($review['order_number']) && admin_can('orders.view')): ?>
                            <a class="ad-mono" href="<?= e(admin_url('orders/view.php?id=' . (int) $review['order_id'])) ?>">
                                <?= e($review['order_number']) ?>
                            </a>
                        <?php elseif (!empty($review['order_number'])): ?>
                            <span class="ad-mono"><?= e($review['order_number']) ?></span>
                        <?php else: ?>
                            <span class="ad-muted">&mdash;</span>
                        <?php endif; ?>
                    </span>
                </div>

                <?php if ($review['user_id'] !== null && admin_can('customers.view')): ?>
                    <a class="ad-btn ad-btn--sm ad-btn--block"
                       href="<?= e(admin_url('customers/view.php?id=' . (int) $review['user_id'])) ?>">
                        <?= icon('user', 'w-4 h-4') ?> Open customer
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
