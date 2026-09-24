<?php
/**
 * ShopInnKart Admin - Brands list.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('brands.view');

$search   = trim((string) ($_GET['q'] ?? ''));
$status   = admin_filter('status', ['active', 'inactive']);
$featured = admin_filter('featured', ['1', '0']);
$page     = max(1, (int) ($_GET['page'] ?? 1));

// ORDER BY is built from this map only, never from the raw query string.
$sortMap = [
    'name'       => 'b.`name`',
    'sort_order' => 'b.`sort_order`',
    'products'   => 'product_count',
    'created'    => 'b.`created_at`',
];
$sort   = admin_safe_sort((string) ($_GET['sort'] ?? ''), array_keys($sortMap), 'sort_order');
$dir    = strtolower((string) ($_GET['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
$dirSql = admin_safe_dir($dir);

$where  = ['1'];
$params = [];

if ($search !== '') {
    // Each occurrence needs its own placeholder: with emulated prepares off,
    // PDO binds a named marker exactly once.
    $where[] = '(b.`name` LIKE :q_name OR b.`slug` LIKE :q_slug OR b.`website` LIKE :q_site)';
    $params['q_name'] = $params['q_slug'] = $params['q_site'] = '%' . $search . '%';
}
if ($status !== '') {
    $where[] = 'b.`status` = :status';
    $params['status'] = $status;
}
if ($featured !== '') {
    $where[] = 'b.`is_featured` = :featured';
    $params['featured'] = (int) $featured;
}
$whereSql = implode(' AND ', $where);

$total      = (int) Database::fetchColumn("SELECT COUNT(*) FROM `brands` b WHERE {$whereSql}", $params);
$pagination = paginate($total, ADMIN_PER_PAGE, $page);

// PDO cannot bind LIMIT/OFFSET with emulated prepares off, so they are cast here.
$limit  = (int) $pagination['per_page'];
$offset = (int) $pagination['offset'];

$brands = Database::fetchAll(
    "SELECT b.`id`, b.`name`, b.`slug`, b.`logo`, b.`website`, b.`sort_order`,
            b.`is_featured`, b.`status`, b.`created_at`,
            (SELECT COUNT(*) FROM `products` p WHERE p.`brand_id` = b.`id`) AS product_count
     FROM `brands` b
     WHERE {$whereSql}
     ORDER BY {$sortMap[$sort]} {$dirSql}, b.`name` ASC
     LIMIT {$limit} OFFSET {$offset}",
    $params
);

$counts = [
    'all'      => Database::count('brands'),
    'active'   => Database::count('brands', "`status` = 'active'"),
    'inactive' => Database::count('brands', "`status` = 'inactive'"),
];

$canEdit   = admin_can('brands.edit');
$canDelete = admin_can('brands.delete');

$pageTitle    = 'Brands';
$pageSubtitle = $counts['all'] . ' brands · ' . $counts['active'] . ' active';
$breadcrumbs  = [
    ['label' => 'Dashboard', 'url' => admin_url('dashboard.php')],
    ['label' => 'Brands'],
];
$pageActions = '';
if (admin_can('brands.create')) {
    $pageActions = '<a class="ad-btn ad-btn--primary" href="' . e(admin_url('brands/create.php')) . '">'
        . icon('plus', 'w-4 h-4') . ' Add Brand</a>';
}

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

    <form class="ad-filters" method="get" action="<?= e(admin_url('brands/')) ?>">
        <?php if ($status !== ''): ?>
            <input type="hidden" name="status" value="<?= e($status) ?>">
        <?php endif; ?>
        <div class="ad-search">
            <?= icon('search', 'w-4 h-4') ?>
            <label class="sik-sr" for="brandSearch">Search brands</label>
            <input class="sik-input" type="search" id="brandSearch" name="q" data-filter-search
                   value="<?= e($search) ?>" placeholder="Search by name, slug or website&hellip;" autocomplete="off">
        </div>

        <label class="sik-sr" for="brandFeatured">Featured filter</label>
        <select class="sik-select" id="brandFeatured" name="featured" data-auto-submit>
            <?= admin_options(['1' => 'Featured only', '0' => 'Not featured'], $featured, 'All brands') ?>
        </select>

        <button type="submit" class="ad-btn ad-btn--sm"><?= icon('filter', 'w-4 h-4') ?> Filter</button>
        <?php if ($search !== '' || $status !== '' || $featured !== ''): ?>
            <a class="ad-btn ad-btn--sm" href="<?= e(admin_url('brands/')) ?>">Reset</a>
        <?php endif; ?>
    </form>

    <div class="ad-card__body ad-card__body--flush">
        <?php if ($brands === []): ?>
            <?= $search !== '' || $status !== '' || $featured !== ''
                ? admin_empty('No brands match', 'Try a different search term or clear the filters.', null, null, 'search')
                : admin_empty(
                    'No brands yet',
                    'Brands power the shop filters and the brand landing pages. Add your first one.',
                    admin_can('brands.create') ? 'Add Brand' : null,
                    admin_can('brands.create') ? admin_url('brands/create.php') : null,
                    'award'
                ) ?>
        <?php else: ?>
            <div class="ad-tablewrap">
                <table class="ad-table">
                    <thead>
                        <tr>
                            <th><?= admin_sort_header('Brand', 'name', $sort, $dir) ?></th>
                            <th>Website</th>
                            <th class="ad-table__num"><?= admin_sort_header('Products', 'products', $sort, $dir) ?></th>
                            <th><?= admin_sort_header('Sort', 'sort_order', $sort, $dir) ?></th>
                            <th>Featured</th>
                            <th>Status</th>
                            <th><?= admin_sort_header('Added', 'created', $sort, $dir) ?></th>
                            <th class="ad-table__actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($brands as $brand): ?>
                            <?php
                            $brandId  = (int) $brand['id'];
                            $website  = trim((string) ($brand['website'] ?? ''));
                            $products = (int) $brand['product_count'];
                            ?>
                            <tr>
                                <td>
                                    <div class="ad-cellflex">
                                        <img class="ad-thumb" src="<?= e(img_url($brand['logo'])) ?>"
                                             alt="" width="38" height="38" loading="lazy">
                                        <span style="min-width:0">
                                            <span class="ad-cellflex__name" style="display:block">
                                                <?php if ($canEdit): ?>
                                                    <a href="<?= e(admin_url('brands/edit.php?id=' . $brandId)) ?>">
                                                        <?= e($brand['name']) ?>
                                                    </a>
                                                <?php else: ?>
                                                    <?= e($brand['name']) ?>
                                                <?php endif; ?>
                                            </span>
                                            <span class="ad-cellflex__meta ad-mono">/<?= e($brand['slug']) ?></span>
                                        </span>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($website !== ''): ?>
                                        <a href="<?= e($website) ?>" target="_blank" rel="noopener nofollow"
                                           style="display:inline-flex;align-items:center;gap:5px">
                                            <?= e(str_limit(preg_replace('#^https?://#i', '', $website) ?? $website, 28)) ?>
                                            <?= icon('external', 'w-3.5 h-3.5') ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="ad-muted">&mdash;</span>
                                    <?php endif; ?>
                                </td>
                                <td class="ad-table__num"><?= number_format($products) ?></td>
                                <td><?= (int) $brand['sort_order'] ?></td>
                                <td>
                                    <?php if ((int) $brand['is_featured'] === 1): ?>
                                        <span class="sik-status sik-status--amber">Featured</span>
                                    <?php else: ?>
                                        <span class="ad-muted">&mdash;</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= admin_state_badge((string) $brand['status']) ?></td>
                                <td class="ad-muted"><?= e(format_date($brand['created_at'])) ?></td>
                                <td class="ad-table__actions">
                                    <?php if ($canEdit): ?>
                                        <a class="ad-btn ad-btn--icon" title="Edit"
                                           aria-label="Edit <?= e_attr($brand['name']) ?>"
                                           href="<?= e(admin_url('brands/edit.php?id=' . $brandId)) ?>">
                                            <?= icon('edit', 'w-4 h-4') ?>
                                        </a>
                                    <?php endif; ?>
                                    <?php if ($canDelete): ?>
                                        <?php
                                        // Deleting a brand nulls brand_id on its products (FK ON DELETE
                                        // SET NULL), so the confirmation has to name the damage and the
                                        // endpoint re-checks the acknowledgement server-side.
                                        $confirmText = $products > 0
                                            ? 'Delete "' . $brand['name'] . '"? ' . $products . ' product'
                                                . ($products === 1 ? '' : 's') . ' will be left with no brand.'
                                            : 'Delete "' . $brand['name'] . '"? This cannot be undone.';
                                        ?>
                                        <form method="post" action="<?= e(admin_url('brands/delete.php')) ?>"
                                              class="ad-inline-form"
                                              <?= admin_confirm_form_attrs($confirmText, [
                                                  'title' => 'Delete this brand?',
                                                  'label' => 'Delete brand',
                                              ]) ?>>
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= $brandId ?>">
                                            <input type="hidden" name="confirm" value="1">
                                            <button type="submit" class="ad-btn ad-btn--icon ad-btn--danger-ghost"
                                                    title="Delete" aria-label="Delete <?= e_attr($brand['name']) ?>">
                                                <?= icon('trash', 'w-4 h-4') ?>
                                            </button>
                                        </form>
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
            <?= admin_pagination($pagination, admin_url('brands/')) ?>
        </div>
    <?php endif; ?>
</div>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
