<?php
/**
 * ShopInnKart Admin - Category tree.
 *
 * Categories are a tree, so this list cannot paginate rows the usual way: a
 * child on page 2 without its parent on page 1 is meaningless. Instead the
 * top-level categories paginate and each one always renders with its whole
 * subtree. Searching keeps the ancestors of every match so the nesting still
 * reads correctly.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('categories.view');

$search = trim((string) ($_GET['q'] ?? ''));
$status = admin_filter('status', ['active', 'inactive']);
$page   = max(1, (int) ($_GET['page'] ?? 1));

// The whole table in one read. A category tree is small by nature, and the
// alternative — a query per level — is far more expensive.
$rows = Database::fetchAll(
    "SELECT c.`id`, c.`parent_id`, c.`name`, c.`slug`, c.`image`, c.`icon`, c.`sort_order`,
            c.`is_featured`, c.`show_in_menu`, c.`status`,
            (SELECT COUNT(*) FROM `products` p WHERE p.`category_id` = c.`id`) AS product_count
     FROM `categories` c
     ORDER BY c.`sort_order` ASC, c.`name` ASC"
);

$byId       = [];
$childrenOf = [];
$counts     = ['all' => 0, 'active' => 0, 'inactive' => 0];

foreach ($rows as $row) {
    $id = (int) $row['id'];
    $byId[$id] = $row;
    $childrenOf[(int) ($row['parent_id'] ?? 0)][] = $id;
    $counts['all']++;
    $counts[(string) $row['status']] = ($counts[(string) $row['status']] ?? 0) + 1;
}

// A match pulls its ancestors in with it, otherwise the row would render at
// the wrong depth with no parent above it.
$visible = [];
foreach ($byId as $id => $row) {
    if ($status !== '' && (string) $row['status'] !== $status) {
        continue;
    }
    if ($search !== ''
        && stripos((string) $row['name'], $search) === false
        && stripos((string) $row['slug'], $search) === false) {
        continue;
    }

    $visible[$id] = true;
    $parentId = (int) ($row['parent_id'] ?? 0);
    // The FK allows self-reference in theory; the guard stops a bad row looping.
    $guard = 0;
    while ($parentId > 0 && isset($byId[$parentId]) && !isset($visible[$parentId]) && $guard++ < 50) {
        $visible[$parentId] = true;
        $parentId = (int) ($byId[$parentId]['parent_id'] ?? 0);
    }
}

$flatten = static function (int $parentId, int $depth) use (&$flatten, $childrenOf, $byId, $visible): array {
    $out = [];
    foreach ($childrenOf[$parentId] ?? [] as $id) {
        if (!isset($visible[$id])) {
            continue;
        }
        $out[] = ['row' => $byId[$id], 'depth' => $depth];
        foreach ($flatten($id, $depth + 1) as $descendant) {
            $out[] = $descendant;
        }
    }
    return $out;
};

$roots = array_values(array_filter(
    $childrenOf[0] ?? [],
    static fn (int $id): bool => isset($visible[$id])
));

$pagination = paginate(count($roots), ADMIN_PER_PAGE, $page);
$treeRows   = [];
foreach (array_slice($roots, $pagination['offset'], $pagination['per_page']) as $rootId) {
    $treeRows[] = ['row' => $byId[$rootId], 'depth' => 0];
    foreach ($flatten($rootId, 1) as $descendant) {
        $treeRows[] = $descendant;
    }
}

$canEdit   = admin_can('categories.edit');
$canDelete = admin_can('categories.delete');

$pageTitle    = 'Categories';
$pageSubtitle = $counts['all'] . ' categories · ' . $counts['active'] . ' active';
$breadcrumbs  = [
    ['label' => 'Dashboard', 'url' => admin_url('dashboard.php')],
    ['label' => 'Categories'],
];
$pageActions = '';
if (admin_can('categories.create')) {
    $pageActions = '<a class="ad-btn ad-btn--primary" href="' . e(admin_url('categories/create.php')) . '">'
        . icon('plus', 'w-4 h-4') . ' Add Category</a>';
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

    <form class="ad-filters" method="get" action="<?= e(admin_url('categories/')) ?>">
        <?php if ($status !== ''): ?>
            <input type="hidden" name="status" value="<?= e($status) ?>">
        <?php endif; ?>
        <div class="ad-search">
            <?= icon('search', 'w-4 h-4') ?>
            <label class="sik-sr" for="catSearch">Search categories</label>
            <input class="sik-input" type="search" id="catSearch" name="q" data-filter-search
                   value="<?= e($search) ?>" placeholder="Search by name or slug&hellip;" autocomplete="off">
        </div>
        <button type="submit" class="ad-btn ad-btn--sm"><?= icon('filter', 'w-4 h-4') ?> Filter</button>
        <?php if ($search !== '' || $status !== ''): ?>
            <a class="ad-btn ad-btn--sm" href="<?= e(admin_url('categories/')) ?>">Reset</a>
        <?php endif; ?>
    </form>

    <div class="ad-card__body ad-card__body--flush">
        <?php if ($treeRows === []): ?>
            <?= $search !== '' || $status !== ''
                ? admin_empty('No categories match', 'Try a different search term or clear the filters.', null, null, 'search')
                : admin_empty(
                    'No categories yet',
                    'Categories group your catalogue and drive the storefront menu. Create the first one to get started.',
                    admin_can('categories.create') ? 'Add Category' : null,
                    admin_can('categories.create') ? admin_url('categories/create.php') : null,
                    'grid'
                ) ?>
        <?php else: ?>
            <div class="ad-tablewrap">
                <table class="ad-table">
                    <thead>
                        <tr>
                            <th>Category</th>
                            <th>Depth</th>
                            <th>Icon</th>
                            <th class="ad-table__num">Products</th>
                            <th>Sort</th>
                            <th>Visibility</th>
                            <th>Status</th>
                            <th class="ad-table__actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($treeRows as $entry): ?>
                            <?php
                            $row      = $entry['row'];
                            $depth    = (int) $entry['depth'];
                            $id       = (int) $row['id'];
                            $iconName = (string) ($row['icon'] ?? '');
                            ?>
                            <tr>
                                <td>
                                    <div class="ad-cellflex" style="padding-left:<?= $depth * 22 ?>px">
                                        <?php if ($depth > 0): ?>
                                            <span class="ad-muted" aria-hidden="true"
                                                  style="font-family:monospace">&#9492;</span>
                                        <?php endif; ?>
                                        <img class="ad-thumb" src="<?= e(img_url($row['image'])) ?>"
                                             alt="" width="38" height="38" loading="lazy">
                                        <span style="min-width:0">
                                            <span class="ad-cellflex__name" style="display:block">
                                                <?php if ($canEdit): ?>
                                                    <a href="<?= e(admin_url('categories/edit.php?id=' . $id)) ?>">
                                                        <?= e($row['name']) ?>
                                                    </a>
                                                <?php else: ?>
                                                    <?= e($row['name']) ?>
                                                <?php endif; ?>
                                            </span>
                                            <span class="ad-cellflex__meta ad-mono">/<?= e($row['slug']) ?></span>
                                        </span>
                                    </div>
                                </td>
                                <td class="ad-muted"><?= $depth === 0 ? 'Top level' : 'Level ' . ($depth + 1) ?></td>
                                <td>
                                    <?php if ($iconName !== '' && icon_exists($iconName)): ?>
                                        <span title="<?= e_attr($iconName) ?>"><?= icon($iconName, 'w-5 h-5') ?></span>
                                    <?php else: ?>
                                        <span class="ad-muted">&mdash;</span>
                                    <?php endif; ?>
                                </td>
                                <td class="ad-table__num"><?= number_format((int) $row['product_count']) ?></td>
                                <td>
                                    <?php if ($canEdit): ?>
                                        <label class="sik-sr" for="sort<?= $id ?>">Sort order for <?= e($row['name']) ?></label>
                                        <input class="sik-input" type="number" id="sort<?= $id ?>"
                                               value="<?= (int) $row['sort_order'] ?>" min="0" max="9999" step="1"
                                               data-sort-order data-id="<?= $id ?>"
                                               style="width:76px;padding:6px 8px;font-size:13px">
                                    <?php else: ?>
                                        <?= (int) $row['sort_order'] ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ((int) $row['is_featured'] === 1): ?>
                                        <span class="sik-status sik-status--amber">Featured</span>
                                    <?php endif; ?>
                                    <?php if ((int) $row['show_in_menu'] === 1): ?>
                                        <span class="sik-status sik-status--blue">In menu</span>
                                    <?php else: ?>
                                        <span class="sik-status sik-status--gray">Hidden</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= admin_state_badge((string) $row['status']) ?></td>
                                <td class="ad-table__actions">
                                    <?php if ($canEdit): ?>
                                        <a class="ad-btn ad-btn--icon" title="Edit" aria-label="Edit <?= e_attr($row['name']) ?>"
                                           href="<?= e(admin_url('categories/edit.php?id=' . $id)) ?>">
                                            <?= icon('edit', 'w-4 h-4') ?>
                                        </a>
                                    <?php endif; ?>
                                    <?php if ($canDelete): ?>
                                        <?= admin_delete_form(
                                            admin_url('categories/delete.php'),
                                            $id,
                                            'Delete "' . $row['name'] . '"? This cannot be undone.'
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
                Top-level <?= (int) $pagination['from'] ?>&ndash;<?= (int) $pagination['to'] ?>
                of <?= (int) $pagination['total'] ?>
            </span>
            <?= admin_pagination($pagination, admin_url('categories/')) ?>
        </div>
    <?php endif; ?>
</div>

<?php if ($canEdit && $treeRows !== []): ?>
<script>
    // Reordering is one number per row rather than drag-and-drop: it keeps the
    // tree readable, works on touch, and survives pagination.
    document.addEventListener('DOMContentLoaded', function () {
        var endpoint = (window.SIK_CONFIG && SIK_CONFIG.adminUrl ? SIK_CONFIG.adminUrl : '') + '/categories/reorder.php';

        SIK.on('change', '[data-sort-order]', async function () {
            var input = this;
            if (input.dataset.saving === '1') return;

            var value = parseInt(input.value, 10);
            if (isNaN(value) || value < 0) { value = 0; }
            input.value = String(value);

            input.dataset.saving = '1';
            input.disabled = true;

            var result = await SIK.post(endpoint, { id: parseInt(input.dataset.id, 10), sort_order: value });

            input.disabled = false;
            delete input.dataset.saving;

            if (result.success) {
                SIK.toast(result.message || 'Order saved.', 'success');
            } else {
                SIK.toast(result.message || 'Could not save the sort order.', 'error');
            }
        });
    });
</script>
<?php endif; ?>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
