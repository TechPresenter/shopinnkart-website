<?php
/**
 * ShopInnKart Admin - Testimonials list.
 *
 * Gated on homepage.edit to match the sidebar entry in admin/includes/functions.php:
 * testimonials are a homepage content block, not a catalogue object.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('homepage.edit');

$search = trim((string) ($_GET['q'] ?? ''));
$status = admin_filter('status', ['active', 'inactive']);
$rating = admin_filter('rating', ['5', '4', '3', '2', '1']);
$page   = max(1, (int) ($_GET['page'] ?? 1));

// ORDER BY is built from this map only, never from the raw query string.
$sortMap = [
    'name'       => '`customer_name`',
    'rating'     => '`rating`',
    'sort_order' => '`sort_order`',
    'created'    => '`created_at`',
];
$sort   = admin_safe_sort((string) ($_GET['sort'] ?? ''), array_keys($sortMap), 'sort_order');
$dir    = strtolower((string) ($_GET['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
$dirSql = admin_safe_dir($dir);

$where  = ['1'];
$params = [];

if ($search !== '') {
    // Each occurrence needs its own placeholder: with emulated prepares off,
    // PDO binds a named marker exactly once.
    $where[] = '(`customer_name` LIKE :q_name OR `designation` LIKE :q_role
                 OR `title` LIKE :q_title OR `message` LIKE :q_message)';
    $params['q_name'] = $params['q_role'] = $params['q_title'] = $params['q_message'] = '%' . $search . '%';
}
if ($status !== '') {
    $where[] = '`status` = :status';
    $params['status'] = $status;
}
if ($rating !== '') {
    $where[] = '`rating` = :rating';
    $params['rating'] = (int) $rating;
}
$whereSql = implode(' AND ', $where);

$total      = (int) Database::fetchColumn("SELECT COUNT(*) FROM `testimonials` WHERE {$whereSql}", $params);
$pagination = paginate($total, ADMIN_PER_PAGE, $page);

// PDO cannot bind LIMIT/OFFSET with emulated prepares off, so they are cast here.
$limit  = (int) $pagination['per_page'];
$offset = (int) $pagination['offset'];

$testimonials = Database::fetchAll(
    "SELECT `id`, `customer_name`, `designation`, `avatar`, `rating`, `title`, `message`,
            `sort_order`, `status`, `created_at`
     FROM `testimonials`
     WHERE {$whereSql}
     ORDER BY {$sortMap[$sort]} {$dirSql}, `id` DESC
     LIMIT {$limit} OFFSET {$offset}",
    $params
);

$counts = [
    'all'      => Database::count('testimonials'),
    'active'   => Database::count('testimonials', "`status` = 'active'"),
    'inactive' => Database::count('testimonials', "`status` = 'inactive'"),
];

// Reaching this page already required homepage.edit, so create/edit/delete are
// all available: testimonials have no separate read-only permission.
$hasFilter = $search !== '' || $status !== '' || $rating !== '';

$pageTitle    = 'Testimonials';
$pageSubtitle = $counts['all'] . ' testimonials · ' . $counts['active'] . ' live on the storefront';
$breadcrumbs  = [
    ['label' => 'Dashboard', 'url' => admin_url('dashboard.php')],
    ['label' => 'Testimonials'],
];
$pageActions = '<a class="ad-btn ad-btn--primary" href="' . e(admin_url('testimonials/create.php')) . '">'
    . icon('plus', 'w-4 h-4') . ' Add Testimonial</a>';

require ADMIN_PATH . '/includes/header.php';
?>

<div class="ad-card">
    <div class="ad-tabs">
        <?php foreach (['' => 'All', 'active' => 'Active', 'inactive' => 'Inactive'] as $key => $label): ?>
            <a class="ad-tab <?= $status === $key ? 'is-active' : '' ?>"
               href="<?= e(url_with(['status' => $key === '' ? null : $key, 'page' => null])) ?>">
                <?= e($label) ?>
                <span class="ad-tab__count"><?= (int) ($counts[$key === '' ? 'all' : $key] ?? 0) ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <form class="ad-filters" method="get" action="<?= e(admin_url('testimonials/')) ?>">
        <?php if ($status !== ''): ?>
            <input type="hidden" name="status" value="<?= e($status) ?>">
        <?php endif; ?>
        <div class="ad-search">
            <?= icon('search', 'w-4 h-4') ?>
            <label class="sik-sr" for="testimonialSearch">Search testimonials</label>
            <input class="sik-input" type="search" id="testimonialSearch" name="q" data-filter-search
                   value="<?= e($search) ?>" placeholder="Name, designation or message&hellip;" autocomplete="off">
        </div>

        <label class="sik-sr" for="testimonialRating">Rating filter</label>
        <select class="sik-select" id="testimonialRating" name="rating" data-auto-submit>
            <?= admin_options(
                ['5' => '5 stars', '4' => '4 stars', '3' => '3 stars', '2' => '2 stars', '1' => '1 star'],
                $rating,
                'Any rating'
            ) ?>
        </select>

        <button type="submit" class="ad-btn ad-btn--sm"><?= icon('filter', 'w-4 h-4') ?> Filter</button>
        <?php if ($hasFilter): ?>
            <a class="ad-btn ad-btn--sm" href="<?= e(admin_url('testimonials/')) ?>">Reset</a>
        <?php endif; ?>
    </form>

    <div class="ad-card__body ad-card__body--flush">
        <?php if ($testimonials === []): ?>
            <?= $hasFilter
                ? admin_empty('No testimonials match', 'Try a different search term or clear the filters.', null, null, 'search')
                : admin_empty(
                    'No testimonials yet',
                    'Testimonials feed the social-proof block on the homepage. Add the first one.',
                    'Add Testimonial',
                    admin_url('testimonials/create.php'),
                    'award'
                ) ?>
        <?php else: ?>
            <div class="ad-tablewrap">
                <table class="ad-table">
                    <thead>
                        <tr>
                            <th><?= admin_sort_header('Customer', 'name', $sort, $dir) ?></th>
                            <th><?= admin_sort_header('Rating', 'rating', $sort, $dir) ?></th>
                            <th>Testimonial</th>
                            <th><?= admin_sort_header('Sort', 'sort_order', $sort, $dir) ?></th>
                            <th>Status</th>
                            <th><?= admin_sort_header('Added', 'created', $sort, $dir) ?></th>
                            <th class="ad-table__actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($testimonials as $testimonial): ?>
                            <?php $testimonialId = (int) $testimonial['id']; ?>
                            <tr>
                                <td>
                                    <div class="ad-cellflex">
                                        <?php if (!empty($testimonial['avatar'])): ?>
                                            <img class="ad-thumb" src="<?= e(img_url($testimonial['avatar'])) ?>"
                                                 alt="" width="38" height="38" loading="lazy"
                                                 style="border-radius:50%">
                                        <?php else: ?>
                                            <span class="ad-avatar"><?= e(initials((string) $testimonial['customer_name'])) ?></span>
                                        <?php endif; ?>
                                        <span style="min-width:0">
                                            <span class="ad-cellflex__name" style="display:block">
                                                <a href="<?= e(admin_url('testimonials/edit.php?id=' . $testimonialId)) ?>">
                                                    <?= e($testimonial['customer_name']) ?>
                                                </a>
                                            </span>
                                            <span class="ad-cellflex__meta"><?= e($testimonial['designation'] ?: '—') ?></span>
                                        </span>
                                    </div>
                                </td>
                                <td style="color:#F59E0B"><?= rating_stars((float) $testimonial['rating']) ?></td>
                                <td>
                                    <?php if (!empty($testimonial['title'])): ?>
                                        <span class="ad-cellflex__name" style="display:block">
                                            <?= e(str_limit($testimonial['title'], 50)) ?>
                                        </span>
                                    <?php endif; ?>
                                    <span class="ad-cellflex__meta"><?= e(str_limit($testimonial['message'], 90)) ?></span>
                                </td>
                                <td><?= (int) $testimonial['sort_order'] ?></td>
                                <td><?= admin_state_badge((string) $testimonial['status']) ?></td>
                                <td class="ad-muted"><?= e(format_date($testimonial['created_at'])) ?></td>
                                <td class="ad-table__actions">
                                    <a class="ad-btn ad-btn--icon" title="Edit"
                                       aria-label="Edit testimonial from <?= e_attr($testimonial['customer_name']) ?>"
                                       href="<?= e(admin_url('testimonials/edit.php?id=' . $testimonialId)) ?>">
                                        <?= icon('edit', 'w-4 h-4') ?>
                                    </a>
                                    <?= admin_delete_form(
                                        admin_url('testimonials/delete.php'),
                                        $testimonialId,
                                        'Delete the testimonial from "' . $testimonial['customer_name'] . '"? This cannot be undone.'
                                    ) ?>
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
            <?= admin_pagination($pagination, admin_url('testimonials/')) ?>
        </div>
    <?php endif; ?>
</div>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
