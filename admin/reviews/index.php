<?php
/**
 * ShopInnKart Admin - Review moderation queue.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('reviews.view');

$search = trim((string) ($_GET['q'] ?? ''));
$status = admin_filter('status', ['pending', 'approved', 'rejected'], 'pending');
$rating = admin_filter('rating', ['5', '4', '3', '2', '1']);
$page   = max(1, (int) ($_GET['page'] ?? 1));

// "all" is a real choice, so it is spelled out rather than smuggled in as "".
if (($_GET['status'] ?? '') === 'all') {
    $status = '';
}

// ORDER BY is built from this map only, never from the raw query string.
$sortMap = [
    'created' => 'r.`created_at`',
    'rating'  => 'r.`rating`',
    'product' => 'p.`name`',
];
$sort   = admin_safe_sort((string) ($_GET['sort'] ?? ''), array_keys($sortMap), 'created');
$dir    = strtolower((string) ($_GET['dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
$dirSql = admin_safe_dir($dir);

$where  = ['1'];
$params = [];

if ($search !== '') {
    // Each occurrence needs its own placeholder: with emulated prepares off,
    // PDO binds a named marker exactly once.
    $where[] = '(p.`name` LIKE :q_product OR p.`sku` LIKE :q_sku
                 OR r.`customer_name` LIKE :q_customer OR r.`title` LIKE :q_title)';
    $params['q_product'] = $params['q_sku'] = $params['q_customer'] = $params['q_title'] = '%' . $search . '%';
}
if ($status !== '') {
    $where[] = 'r.`status` = :status';
    $params['status'] = $status;
}
if ($rating !== '') {
    $where[] = 'r.`rating` = :rating';
    $params['rating'] = (int) $rating;
}
$whereSql = implode(' AND ', $where);

$total = (int) Database::fetchColumn(
    "SELECT COUNT(*) FROM `reviews` r INNER JOIN `products` p ON p.`id` = r.`product_id` WHERE {$whereSql}",
    $params
);
$pagination = paginate($total, ADMIN_PER_PAGE, $page);

// PDO cannot bind LIMIT/OFFSET with emulated prepares off, so they are cast here.
$limit  = (int) $pagination['per_page'];
$offset = (int) $pagination['offset'];

$reviews = Database::fetchAll(
    "SELECT r.`id`, r.`product_id`, r.`user_id`, r.`customer_name`, r.`rating`, r.`title`, r.`comment`,
            r.`verified_purchase`, r.`status`, r.`admin_reply`, r.`created_at`,
            p.`name` AS product_name, p.`slug` AS product_slug, p.`main_image` AS product_image
     FROM `reviews` r
     INNER JOIN `products` p ON p.`id` = r.`product_id`
     WHERE {$whereSql}
     ORDER BY {$sortMap[$sort]} {$dirSql}, r.`id` DESC
     LIMIT {$limit} OFFSET {$offset}",
    $params
);

$statusCounts = Database::fetchPairs('SELECT `status`, COUNT(*) FROM `reviews` GROUP BY `status`');
$counts = [
    'pending'  => (int) ($statusCounts['pending'] ?? 0),
    'approved' => (int) ($statusCounts['approved'] ?? 0),
    'rejected' => (int) ($statusCounts['rejected'] ?? 0),
];
$counts['all'] = array_sum($counts);

$canModerate = admin_can('reviews.edit');
$canDelete   = admin_can('reviews.delete');
$hasFilter   = $search !== '' || $rating !== '';

$pageTitle    = 'Reviews';
$pageSubtitle = $counts['pending'] . ' awaiting moderation · ' . $counts['approved'] . ' live on the storefront';
$breadcrumbs  = [
    ['label' => 'Dashboard', 'url' => admin_url('dashboard.php')],
    ['label' => 'Reviews'],
];

require ADMIN_PATH . '/includes/header.php';
?>

<div class="ad-card">
    <div class="ad-tabs">
        <?php
        $tabs = [
            'pending'  => 'Pending',
            'approved' => 'Approved',
            'rejected' => 'Rejected',
            'all'      => 'All',
        ];
        $currentTab = $status === '' ? 'all' : $status;
        ?>
        <?php foreach ($tabs as $key => $label): ?>
            <a class="ad-tab <?= $currentTab === $key ? 'is-active' : '' ?>"
               href="<?= e(url_with(['status' => $key, 'page' => null])) ?>">
                <?= e($label) ?>
                <span class="ad-tab__count"><?= number_format((int) ($counts[$key] ?? 0)) ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <form class="ad-filters" method="get" action="<?= e(admin_url('reviews/')) ?>">
        <input type="hidden" name="status" value="<?= e($currentTab) ?>">
        <div class="ad-search">
            <?= icon('search', 'w-4 h-4') ?>
            <label class="sik-sr" for="reviewSearch">Search reviews</label>
            <input class="sik-input" type="search" id="reviewSearch" name="q" data-filter-search
                   value="<?= e($search) ?>" placeholder="Product name, SKU or customer&hellip;" autocomplete="off">
        </div>

        <label class="sik-sr" for="reviewRating">Rating filter</label>
        <select class="sik-select" id="reviewRating" name="rating" data-auto-submit>
            <?= admin_options(
                ['5' => '5 stars', '4' => '4 stars', '3' => '3 stars', '2' => '2 stars', '1' => '1 star'],
                $rating,
                'Any rating'
            ) ?>
        </select>

        <button type="submit" class="ad-btn ad-btn--sm"><?= icon('filter', 'w-4 h-4') ?> Filter</button>
        <?php if ($hasFilter): ?>
            <a class="ad-btn ad-btn--sm" href="<?= e(admin_url('reviews/?status=' . urlencode($currentTab))) ?>">Reset</a>
        <?php endif; ?>
    </form>

    <?php if (($canModerate || $canDelete) && $reviews !== []): ?>
        <?php
        // No bulk_action select here: each button carries its own formaction, so
        // the endpoint that runs is the one whose rules the admin just read.
        ?>
        <form class="ad-bulk" method="post" action="<?= e(admin_url('reviews/approve.php')) ?>"
              data-bulk-bar data-bulk-form>
            <?= csrf_field() ?>
            <strong><span data-bulk-count>0</span> selected</strong>
            <?php if ($canModerate): ?>
                <button type="submit" class="ad-btn ad-btn--success"
                        formaction="<?= e(admin_url('reviews/approve.php')) ?>">
                    <?= icon('check', 'w-4 h-4') ?> Approve
                </button>
                <button type="submit" class="ad-btn"
                        formaction="<?= e(admin_url('reviews/reject.php')) ?>">
                    <?= icon('close', 'w-4 h-4') ?> Reject
                </button>
            <?php endif; ?>
            <?php if ($canDelete): ?>
                <button type="submit" class="ad-btn ad-btn--danger"
                        formaction="<?= e(admin_url('reviews/delete.php')) ?>"
                        data-confirm="Delete the selected review(s)? This cannot be undone.">
                    <?= icon('trash', 'w-4 h-4') ?> Delete
                </button>
            <?php endif; ?>
        </form>
    <?php endif; ?>

    <div class="ad-card__body ad-card__body--flush">
        <?php if ($reviews === []): ?>
            <?= $hasFilter
                ? admin_empty('No reviews match', 'Try a different search term or clear the filters.', null, null, 'search')
                : admin_empty(
                    $status === 'pending' ? 'Nothing waiting for you' : 'No reviews here yet',
                    $status === 'pending'
                        ? 'Every review has been moderated. New ones land in this tab as customers submit them.'
                        : 'Customer reviews appear here as soon as they are submitted from a product page.',
                    null,
                    null,
                    'star'
                ) ?>
        <?php else: ?>
            <div class="ad-tablewrap">
                <table class="ad-table">
                    <thead>
                        <tr>
                            <?php if ($canModerate || $canDelete): ?>
                                <th class="ad-table__check">
                                    <input type="checkbox" data-check-all aria-label="Select all rows on this page">
                                </th>
                            <?php endif; ?>
                            <th><?= admin_sort_header('Product', 'product', $sort, $dir) ?></th>
                            <th>Customer</th>
                            <th><?= admin_sort_header('Rating', 'rating', $sort, $dir) ?></th>
                            <th>Review</th>
                            <th>Status</th>
                            <th><?= admin_sort_header('Date', 'created', $sort, $dir) ?></th>
                            <th class="ad-table__actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reviews as $review): ?>
                            <?php
                            $reviewId  = (int) $review['id'];
                            $productId = (int) $review['product_id'];
                            ?>
                            <tr>
                                <?php if ($canModerate || $canDelete): ?>
                                    <td class="ad-table__check">
                                        <input type="checkbox" data-check-row value="<?= $reviewId ?>"
                                               aria-label="Select review #<?= $reviewId ?>">
                                    </td>
                                <?php endif; ?>
                                <td>
                                    <div class="ad-cellflex">
                                        <img class="ad-thumb" src="<?= e(img_url($review['product_image'])) ?>"
                                             alt="" width="38" height="38" loading="lazy">
                                        <span style="min-width:0">
                                            <span class="ad-cellflex__name" style="display:block">
                                                <?php if (admin_can('products.edit')): ?>
                                                    <a href="<?= e(admin_url('products/edit.php?id=' . $productId)) ?>">
                                                        <?= e(str_limit($review['product_name'], 44)) ?>
                                                    </a>
                                                <?php else: ?>
                                                    <?= e(str_limit($review['product_name'], 44)) ?>
                                                <?php endif; ?>
                                            </span>
                                            <span class="ad-cellflex__meta">
                                                <a href="<?= e(product_url((string) $review['product_slug'])) ?>"
                                                   target="_blank" rel="noopener">View on store</a>
                                            </span>
                                        </span>
                                    </div>
                                </td>
                                <td>
                                    <span class="ad-cellflex__name" style="display:block">
                                        <?php if ($review['user_id'] !== null && admin_can('customers.view')): ?>
                                            <a href="<?= e(admin_url('customers/view.php?id=' . (int) $review['user_id'])) ?>">
                                                <?= e($review['customer_name']) ?>
                                            </a>
                                        <?php else: ?>
                                            <?= e($review['customer_name']) ?>
                                        <?php endif; ?>
                                    </span>
                                    <?php if ((int) $review['verified_purchase'] === 1): ?>
                                        <span class="sik-status sik-status--green">Verified purchase</span>
                                    <?php else: ?>
                                        <span class="ad-cellflex__meta">Unverified</span>
                                    <?php endif; ?>
                                </td>
                                <td style="color:#F59E0B;white-space:nowrap">
                                    <?= rating_stars((float) $review['rating']) ?>
                                </td>
                                <td>
                                    <?php if (!empty($review['title'])): ?>
                                        <span class="ad-cellflex__name" style="display:block">
                                            <?= e(str_limit($review['title'], 50)) ?>
                                        </span>
                                    <?php endif; ?>
                                    <span class="ad-cellflex__meta"><?= e(str_limit($review['comment'], 90) ?: '—') ?></span>
                                    <?php if (!empty($review['admin_reply'])): ?>
                                        <span class="sik-status sik-status--blue">Replied</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= admin_state_badge((string) $review['status']) ?></td>
                                <td class="ad-muted" style="white-space:nowrap">
                                    <?= e(format_date($review['created_at'], 'd M Y')) ?>
                                </td>
                                <td class="ad-table__actions">
                                    <a class="ad-btn ad-btn--icon" title="Open review" aria-label="Open review"
                                       href="<?= e(admin_url('reviews/view.php?id=' . $reviewId)) ?>">
                                        <?= icon('eye', 'w-4 h-4') ?>
                                    </a>
                                    <?php if ($canModerate && $review['status'] !== 'approved'): ?>
                                        <form method="post" action="<?= e(admin_url('reviews/approve.php')) ?>"
                                              class="ad-inline-form">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= $reviewId ?>">
                                            <button type="submit" class="ad-btn ad-btn--icon ad-btn--success"
                                                    title="Approve" aria-label="Approve review">
                                                <?= icon('check', 'w-4 h-4') ?>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($canModerate && $review['status'] !== 'rejected'): ?>
                                        <form method="post" action="<?= e(admin_url('reviews/reject.php')) ?>"
                                              class="ad-inline-form">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= $reviewId ?>">
                                            <button type="submit" class="ad-btn ad-btn--icon"
                                                    title="Reject" aria-label="Reject review">
                                                <?= icon('close', 'w-4 h-4') ?>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($canDelete): ?>
                                        <?= admin_delete_form(
                                            admin_url('reviews/delete.php'),
                                            $reviewId,
                                            'Delete this review from "' . $review['customer_name'] . '"? This cannot be undone.'
                                        ) ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($pagination['last'] > 1): ?>
        <div class="ad-card__foot">
            <span class="ad-muted">
                Showing <?= (int) $pagination['from'] ?>&ndash;<?= (int) $pagination['to'] ?>
                of <?= (int) $pagination['total'] ?>
            </span>
            <?= admin_pagination($pagination, admin_url('reviews/')) ?>
        </div>
    <?php endif; ?>
</div>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
