<?php
/**
 * ShopInnKart Admin - Attributes list.
 *
 * Attributes are the vocabulary behind product variants and the shop filters,
 * so the value count is the number that matters most on this screen.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('attributes.view');

$search = trim((string) ($_GET['q'] ?? ''));
$type   = admin_filter('type', ['select', 'color', 'text']);
$status = admin_filter('status', ['active', 'inactive']);
$page   = max(1, (int) ($_GET['page'] ?? 1));

// ORDER BY is built from this map only, never from the raw query string.
$sortMap = [
    'name'       => 'a.`name`',
    'sort_order' => 'a.`sort_order`',
    'values'     => 'value_count',
    'created'    => 'a.`created_at`',
];
$sort   = admin_safe_sort((string) ($_GET['sort'] ?? ''), array_keys($sortMap), 'sort_order');
$dir    = strtolower((string) ($_GET['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
$dirSql = admin_safe_dir($dir);

$where  = ['1'];
$params = [];

if ($search !== '') {
    // Each occurrence needs its own placeholder: with emulated prepares off,
    // PDO binds a named marker exactly once.
    $where[] = '(a.`name` LIKE :q_name OR a.`slug` LIKE :q_slug)';
    $params['q_name'] = $params['q_slug'] = '%' . $search . '%';
}
if ($type !== '') {
    $where[] = 'a.`type` = :type';
    $params['type'] = $type;
}
if ($status !== '') {
    $where[] = 'a.`status` = :status';
    $params['status'] = $status;
}
$whereSql = implode(' AND ', $where);

$total      = (int) Database::fetchColumn("SELECT COUNT(*) FROM `attributes` a WHERE {$whereSql}", $params);
$pagination = paginate($total, ADMIN_PER_PAGE, $page);

// PDO cannot bind LIMIT/OFFSET with emulated prepares off, so they are cast here.
$limit  = (int) $pagination['per_page'];
$offset = (int) $pagination['offset'];

$attributes = Database::fetchAll(
    "SELECT a.`id`, a.`name`, a.`slug`, a.`type`, a.`is_variant`, a.`is_filter`,
            a.`sort_order`, a.`status`, a.`created_at`,
            (SELECT COUNT(*) FROM `attribute_values` av WHERE av.`attribute_id` = a.`id`) AS value_count,
            (SELECT COUNT(DISTINCT pva.`variant_id`) FROM `product_variant_attributes` pva
             WHERE pva.`attribute_id` = a.`id`) AS variant_count
     FROM `attributes` a
     WHERE {$whereSql}
     ORDER BY {$sortMap[$sort]} {$dirSql}, a.`name` ASC
     LIMIT {$limit} OFFSET {$offset}",
    $params
);

// One extra query for the value chips beats one query per row.
$valuesByAttribute = [];
if ($attributes !== []) {
    [$placeholders, $inParams] = Database::inPlaceholders(array_column($attributes, 'id'), 'a');
    $valueRows = Database::fetchAll(
        "SELECT `attribute_id`, `value`, `color_code`
         FROM `attribute_values`
         WHERE `attribute_id` IN ({$placeholders})
         ORDER BY `sort_order` ASC, `value` ASC",
        $inParams
    );
    foreach ($valueRows as $valueRow) {
        $valuesByAttribute[(int) $valueRow['attribute_id']][] = $valueRow;
    }
}

$typeLabels = ['select' => 'Dropdown', 'color' => 'Colour swatch', 'text' => 'Free text'];

$counts = [
    'all'      => Database::count('attributes'),
    'active'   => Database::count('attributes', "`status` = 'active'"),
    'inactive' => Database::count('attributes', "`status` = 'inactive'"),
];

$canEdit   = admin_can('attributes.edit');
$canDelete = admin_can('attributes.delete');

$pageTitle    = 'Attributes';
$pageSubtitle = $counts['all'] . ' attributes · ' . Database::count('attribute_values') . ' values in total';
$breadcrumbs  = [
    ['label' => 'Dashboard', 'url' => admin_url('dashboard.php')],
    ['label' => 'Attributes'],
];
$pageActions = '';
if (admin_can('attributes.create')) {
    $pageActions = '<a class="ad-btn ad-btn--primary" href="' . e(admin_url('attributes/create.php')) . '">'
        . icon('plus', 'w-4 h-4') . ' Add Attribute</a>';
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

    <form class="ad-filters" method="get" action="<?= e(admin_url('attributes/')) ?>">
        <?php if ($status !== ''): ?>
            <input type="hidden" name="status" value="<?= e($status) ?>">
        <?php endif; ?>
        <div class="ad-search">
            <?= icon('search', 'w-4 h-4') ?>
            <label class="sik-sr" for="attrSearch">Search attributes</label>
            <input class="sik-input" type="search" id="attrSearch" name="q" data-filter-search
                   value="<?= e($search) ?>" placeholder="Search by name or slug&hellip;" autocomplete="off">
        </div>

        <label class="sik-sr" for="attrType">Type filter</label>
        <select class="sik-select" id="attrType" name="type" data-auto-submit>
            <?= admin_options($typeLabels, $type, 'All types') ?>
        </select>

        <button type="submit" class="ad-btn ad-btn--sm"><?= icon('filter', 'w-4 h-4') ?> Filter</button>
        <?php if ($search !== '' || $status !== '' || $type !== ''): ?>
            <a class="ad-btn ad-btn--sm" href="<?= e(admin_url('attributes/')) ?>">Reset</a>
        <?php endif; ?>
    </form>

    <div class="ad-card__body ad-card__body--flush">
        <?php if ($attributes === []): ?>
            <?= $search !== '' || $status !== '' || $type !== ''
                ? admin_empty('No attributes match', 'Try a different search term or clear the filters.', null, null, 'search')
                : admin_empty(
                    'No attributes yet',
                    'Attributes such as Colour, Length or Pack Size build product variants and the shop filters.',
                    admin_can('attributes.create') ? 'Add Attribute' : null,
                    admin_can('attributes.create') ? admin_url('attributes/create.php') : null,
                    'sort'
                ) ?>
        <?php else: ?>
            <div class="ad-tablewrap">
                <table class="ad-table">
                    <thead>
                        <tr>
                            <th><?= admin_sort_header('Attribute', 'name', $sort, $dir) ?></th>
                            <th>Type</th>
                            <th class="ad-table__num"><?= admin_sort_header('Values', 'values', $sort, $dir) ?></th>
                            <th>Sample values</th>
                            <th>Used for</th>
                            <th><?= admin_sort_header('Sort', 'sort_order', $sort, $dir) ?></th>
                            <th>Status</th>
                            <th class="ad-table__actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($attributes as $attribute): ?>
                            <?php
                            $attributeId = (int) $attribute['id'];
                            $values      = $valuesByAttribute[$attributeId] ?? [];
                            $valueCount  = (int) $attribute['value_count'];
                            $variantUse  = (int) $attribute['variant_count'];
                            ?>
                            <tr>
                                <td>
                                    <span class="ad-cellflex__name" style="display:block">
                                        <?php if ($canEdit): ?>
                                            <a href="<?= e(admin_url('attributes/edit.php?id=' . $attributeId)) ?>">
                                                <?= e($attribute['name']) ?>
                                            </a>
                                        <?php else: ?>
                                            <?= e($attribute['name']) ?>
                                        <?php endif; ?>
                                    </span>
                                    <span class="ad-cellflex__meta ad-mono"><?= e($attribute['slug']) ?></span>
                                </td>
                                <td>
                                    <span class="sik-status sik-status--<?= $attribute['type'] === 'color' ? 'violet' : ($attribute['type'] === 'text' ? 'gray' : 'blue') ?>">
                                        <?= e($typeLabels[(string) $attribute['type']] ?? (string) $attribute['type']) ?>
                                    </span>
                                </td>
                                <td class="ad-table__num"><?= number_format($valueCount) ?></td>
                                <td>
                                    <?php if ($values === []): ?>
                                        <span class="ad-muted">No values yet</span>
                                    <?php else: ?>
                                        <span style="display:flex;flex-wrap:wrap;gap:5px;align-items:center">
                                            <?php foreach (array_slice($values, 0, 4) as $value): ?>
                                                <span class="sik-status sik-status--gray"
                                                      style="display:inline-flex;align-items:center;gap:5px">
                                                    <?php if (!empty($value['color_code'])): ?>
                                                        <span style="width:11px;height:11px;border-radius:50%;border:1px solid rgba(0,0,0,.18);background:<?= e_attr((string) $value['color_code']) ?>"></span>
                                                    <?php endif; ?>
                                                    <?= e($value['value']) ?>
                                                </span>
                                            <?php endforeach; ?>
                                            <?php if ($valueCount > 4): ?>
                                                <span class="ad-muted">+<?= $valueCount - 4 ?> more</span>
                                            <?php endif; ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ((int) $attribute['is_variant'] === 1): ?>
                                        <span class="sik-status sik-status--indigo">Variants</span>
                                    <?php endif; ?>
                                    <?php if ((int) $attribute['is_filter'] === 1): ?>
                                        <span class="sik-status sik-status--teal">Filters</span>
                                    <?php endif; ?>
                                    <?php if ((int) $attribute['is_variant'] === 0 && (int) $attribute['is_filter'] === 0): ?>
                                        <span class="ad-muted">&mdash;</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= (int) $attribute['sort_order'] ?></td>
                                <td><?= admin_state_badge((string) $attribute['status']) ?></td>
                                <td class="ad-table__actions">
                                    <?php if ($canEdit): ?>
                                        <a class="ad-btn ad-btn--icon" title="Edit"
                                           aria-label="Edit <?= e_attr($attribute['name']) ?>"
                                           href="<?= e(admin_url('attributes/edit.php?id=' . $attributeId)) ?>">
                                            <?= icon('edit', 'w-4 h-4') ?>
                                        </a>
                                    <?php endif; ?>
                                    <?php if ($canDelete && $variantUse === 0): ?>
                                        <?= admin_delete_form(
                                            admin_url('attributes/delete.php'),
                                            $attributeId,
                                            'Delete "' . $attribute['name'] . '" and its ' . $valueCount
                                                . ' value(s)? This cannot be undone.'
                                        ) ?>
                                    <?php elseif ($canDelete): ?>
                                        <span class="ad-muted" style="font-size:12px"
                                              title="Used by <?= $variantUse ?> product variant(s)">
                                            In use
                                        </span>
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
            <?= admin_pagination($pagination, admin_url('attributes/')) ?>
        </div>
    <?php endif; ?>
</div>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
