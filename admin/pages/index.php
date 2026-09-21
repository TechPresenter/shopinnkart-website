<?php
/**
 * ShopInnKart Admin - CMS pages list.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('pages.view');

$search = trim((string) ($_GET['q'] ?? ''));
$status = admin_filter('status', ['active', 'inactive']);
$page   = max(1, (int) ($_GET['page'] ?? 1));

// ORDER BY is built from this map only, never from the raw query string.
$sortMap = [
    'title'      => '`title`',
    'slug'       => '`slug`',
    'sort_order' => '`sort_order`',
    'updated'    => '`updated_at`',
];
$sort   = admin_safe_sort((string) ($_GET['sort'] ?? ''), array_keys($sortMap), 'sort_order');
$dir    = strtolower((string) ($_GET['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
$dirSql = admin_safe_dir($dir);

$where  = ['1'];
$params = [];

if ($search !== '') {
    // Each occurrence needs its own placeholder: with emulated prepares off,
    // PDO binds a named marker exactly once.
    $where[] = '(`title` LIKE :q_title OR `slug` LIKE :q_slug OR `meta_title` LIKE :q_meta)';
    $params['q_title'] = $params['q_slug'] = $params['q_meta'] = '%' . $search . '%';
}
if ($status !== '') {
    $where[] = '`status` = :status';
    $params['status'] = $status;
}
$whereSql = implode(' AND ', $where);

$total      = (int) Database::fetchColumn("SELECT COUNT(*) FROM `pages` WHERE {$whereSql}", $params);
$pagination = paginate($total, ADMIN_PER_PAGE, $page);

// PDO cannot bind LIMIT/OFFSET with emulated prepares off, so they are cast here.
$limit  = (int) $pagination['per_page'];
$offset = (int) $pagination['offset'];

$rows = Database::fetchAll(
    "SELECT `id`, `title`, `slug`, `is_system`, `show_in_footer`, `sort_order`, `status`, `updated_at`
     FROM `pages`
     WHERE {$whereSql}
     ORDER BY {$sortMap[$sort]} {$dirSql}, `title` ASC
     LIMIT {$limit} OFFSET {$offset}",
    $params
);

$counts = [
    'all'      => Database::count('pages'),
    'active'   => Database::count('pages', "`status` = 'active'"),
    'inactive' => Database::count('pages', "`status` = 'inactive'"),
];
$systemCount = Database::count('pages', '`is_system` = 1');

$canEdit   = admin_can('pages.edit');
$canDelete = admin_can('pages.delete');
$hasFilter = $search !== '' || $status !== '';

$pageTitle    = 'Pages';
$pageSubtitle = $counts['all'] . ' pages · ' . $systemCount . ' locked to a fixed route';
$breadcrumbs  = [
    ['label' => 'Dashboard', 'url' => admin_url('dashboard.php')],
    ['label' => 'Pages'],
];
$pageActions = '';
if (admin_can('pages.create')) {
    $pageActions = '<a class="ad-btn ad-btn--primary" href="' . e(admin_url('pages/create.php')) . '">'
        . icon('plus', 'w-4 h-4') . ' Add Page</a>';
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

    <form class="ad-filters" method="get" action="<?= e(admin_url('pages/')) ?>">
        <?php if ($status !== ''): ?>
            <input type="hidden" name="status" value="<?= e($status) ?>">
        <?php endif; ?>
        <div class="ad-search">
            <?= icon('search', 'w-4 h-4') ?>
            <label class="sik-sr" for="pageSearch">Search pages</label>
            <input class="sik-input" type="search" id="pageSearch" name="q" data-filter-search
                   value="<?= e($search) ?>" placeholder="Search by title, slug or meta title&hellip;" autocomplete="off">
        </div>

        <button type="submit" class="ad-btn ad-btn--sm"><?= icon('filter', 'w-4 h-4') ?> Filter</button>
        <?php if ($hasFilter): ?>
            <a class="ad-btn ad-btn--sm" href="<?= e(admin_url('pages/')) ?>">Reset</a>
        <?php endif; ?>
    </form>

    <div class="ad-card__body ad-card__body--flush">
        <?php if ($rows === []): ?>
            <?= $hasFilter
                ? admin_empty('No pages match', 'Try a different search term or clear the filters.', null, null, 'search')
                : admin_empty(
                    'No pages yet',
                    'Pages hold the policy and information content the footer links to.',
                    admin_can('pages.create') ? 'Add Page' : null,
                    admin_can('pages.create') ? admin_url('pages/create.php') : null,
                    'edit'
                ) ?>
        <?php else: ?>
            <div class="ad-tablewrap">
                <table class="ad-table">
                    <thead>
                        <tr>
                            <th><?= admin_sort_header('Title', 'title', $sort, $dir) ?></th>
                            <th><?= admin_sort_header('Slug', 'slug', $sort, $dir) ?></th>
                            <th>Type</th>
                            <th>Footer</th>
                            <th><?= admin_sort_header('Sort', 'sort_order', $sort, $dir) ?></th>
                            <th>Status</th>
                            <th><?= admin_sort_header('Updated', 'updated', $sort, $dir) ?></th>
                            <th class="ad-table__actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row): ?>
                            <?php
                            $rowId    = (int) $row['id'];
                            $slug     = (string) $row['slug'];
                            $isSystem = (int) $row['is_system'] === 1;
                            ?>
                            <tr>
                                <td>
                                    <span class="ad-cellflex__name">
                                        <?php if ($canEdit): ?>
                                            <a href="<?= e(admin_url('pages/edit.php?id=' . $rowId)) ?>"><?= e($row['title']) ?></a>
                                        <?php else: ?>
                                            <?= e($row['title']) ?>
                                        <?php endif; ?>
                                    </span>
                                </td>
                                <td>
                                    <a class="ad-mono" href="<?= e(page_url($slug)) ?>" target="_blank" rel="noopener"
                                       style="display:inline-flex;align-items:center;gap:5px"
                                       title="Open /page/<?= e_attr($slug) ?> on the storefront">
                                        /page/<?= e($slug) ?>
                                        <?= icon('external', 'w-3.5 h-3.5') ?>
                                    </a>
                                </td>
                                <td>
                                    <?php if ($isSystem): ?>
                                        <span class="sik-status sik-status--violet">System</span>
                                    <?php else: ?>
                                        <span class="ad-muted">Custom</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ((int) $row['show_in_footer'] === 1): ?>
                                        <span class="sik-status sik-status--blue">In footer</span>
                                    <?php else: ?>
                                        <span class="sik-status sik-status--gray">Hidden</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= (int) $row['sort_order'] ?></td>
                                <td><?= admin_state_badge((string) $row['status']) ?></td>
                                <td class="ad-muted"><?= e(format_date($row['updated_at'], 'd M Y')) ?></td>
                                <td class="ad-table__actions">
                                    <?php if ($canEdit): ?>
                                        <a class="ad-btn ad-btn--icon" title="Edit"
                                           aria-label="Edit <?= e_attr($row['title']) ?>"
                                           href="<?= e(admin_url('pages/edit.php?id=' . $rowId)) ?>">
                                            <?= icon('edit', 'w-4 h-4') ?>
                                        </a>
                                    <?php endif; ?>
                                    <?php if ($canDelete && !$isSystem): ?>
                                        <?= admin_delete_form(
                                            admin_url('pages/delete.php'),
                                            $rowId,
                                            'Delete "' . $row['title'] . '"? This cannot be undone.'
                                        ) ?>
                                    <?php elseif ($canDelete): ?>
                                        <span class="ad-btn ad-btn--icon" style="opacity:.35;cursor:not-allowed"
                                              title="System pages are wired to a fixed route and cannot be deleted"
                                              aria-hidden="true"><?= icon('lock', 'w-4 h-4') ?></span>
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
            <?= admin_pagination($pagination, admin_url('pages/')) ?>
        </div>
    <?php endif; ?>
</div>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
