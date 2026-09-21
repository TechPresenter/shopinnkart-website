<?php
/**
 * ShopInnKart Admin - Featured categories.
 *
 * The category grid widget renders whichever categories carry is_featured, so
 * this screen is a curation list rather than a category editor: tick what
 * belongs on the homepage and set the order it appears in. Everything else
 * about a category still lives in Catalog → Categories.
 *
 * Gated on homepage.edit rather than categories.edit — flagging a category as
 * featured is a homepage decision, and it is the only column this page writes
 * besides the sort order shared with the storefront rails.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('homepage.edit');

require_once __DIR__ . '/_meta.php';

if (is_post()) {
    csrf_require();

    $checked = array_flip(array_map('intval', input_array('featured')));

    // sort_order arrives keyed by category id, and input_array() would drop
    // those keys, so this one reads the raw array and validates per value.
    $orders = isset($_POST['sort_order']) && is_array($_POST['sort_order']) ? $_POST['sort_order'] : [];

    // Read the ids from the database, never from the form: a posted id list
    // could otherwise reach rows this screen never rendered.
    $rows = Database::fetchAll('SELECT `id`, `name`, `is_featured`, `sort_order` FROM `categories`');

    $changed = 0;
    Database::transaction(static function () use ($rows, $checked, $orders, &$changed): void {
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $featured = isset($checked[$id]) ? 1 : 0;
            $order = isset($orders[$id]) && is_numeric($orders[$id])
                ? max(0, min(9999, (int) $orders[$id]))
                : (int) $row['sort_order'];

            if ($featured === (int) $row['is_featured'] && $order === (int) $row['sort_order']) {
                continue;
            }
            Database::update(
                'categories',
                ['is_featured' => $featured, 'sort_order' => $order],
                '`id` = :id',
                ['id' => $id]
            );
            $changed++;
        }
    });

    log_activity('homepage.featured_categories', 'category', null,
        'Updated the featured category list (' . $changed . ' row(s) changed)');
    admin_after_write();

    flash('success', $changed === 0
        ? 'Nothing changed.'
        : $changed . ' categor' . ($changed === 1 ? 'y' : 'ies') . ' updated.');
    redirect(admin_url('homepage/categories.php'));
}

$rows = Database::fetchAll(
    "SELECT c.`id`, c.`parent_id`, c.`name`, c.`slug`, c.`image`, c.`sort_order`, c.`is_featured`, c.`status`,
            (SELECT COUNT(*) FROM `products` p WHERE p.`category_id` = c.`id` AND p.`status` = 'active') AS product_count
     FROM `categories` c
     ORDER BY c.`sort_order` ASC, c.`name` ASC"
);

$childrenOf = [];
foreach ($rows as $row) {
    $childrenOf[(int) ($row['parent_id'] ?? 0)][] = $row;
}

$flatten = static function (int $parentId, int $depth) use (&$flatten, $childrenOf): array {
    $out = [];
    foreach ($childrenOf[$parentId] ?? [] as $row) {
        $out[] = ['row' => $row, 'depth' => $depth];
        foreach ($flatten((int) $row['id'], $depth + 1) as $descendant) {
            $out[] = $descendant;
        }
    }
    return $out;
};
$tree = $flatten(0, 0);

// Only active top-level categories reach the grid — featured_categories()
// filters category_tree(), which holds roots only. Call that out on the row.
$strandedCount = 0;
foreach ($tree as $entry) {
    if ((int) $entry['row']['is_featured'] === 1
        && ($entry['depth'] > 0 || $entry['row']['status'] !== 'active')) {
        $strandedCount++;
    }
}
$featuredCount = count(array_filter($rows, static fn (array $r): bool => (int) $r['is_featured'] === 1));

$gridSection = Database::fetch(
    "SELECT `id`, `item_limit`, `status` FROM `homepage_sections`
     WHERE `widget_type` = 'category_grid' ORDER BY `zone`, `sort_order` LIMIT 1"
);

$pageTitle    = 'Featured Categories';
$pageSubtitle = $featuredCount . ' of ' . count($rows) . ' categories are featured on the homepage.';
$breadcrumbs  = [
    ['label' => 'Dashboard',        'url' => admin_url('dashboard.php')],
    ['label' => 'Homepage Builder', 'url' => admin_url('homepage/')],
    ['label' => 'Featured Categories'],
];
$pageActions = '<a class="ad-btn" href="' . e(admin_url('categories/')) . '">'
    . icon('package', 'w-4 h-4') . ' Manage categories</a>';

require ADMIN_PATH . '/includes/header.php';
?>

<?php if ($gridSection !== null): ?>
    <div class="sik-alert sik-alert--info">
        <?= icon('info', 'w-5 h-5') ?>
        <div>
            The category grid section shows the first <strong><?= (int) $gridSection['item_limit'] ?></strong>
            featured categories and is currently <strong><?= e($gridSection['status']) ?></strong>.
            <a href="<?= e(admin_url('homepage/edit.php?id=' . (int) $gridSection['id'])) ?>">Edit that section</a>
            to change the limit or its heading.
        </div>
    </div>
<?php else: ?>
    <div class="sik-alert sik-alert--warning">
        <?= icon('alert', 'w-5 h-5') ?>
        <div>
            No category grid section exists yet, so nothing on the storefront reads this list.
            <a href="<?= e(admin_url('homepage/create.php')) ?>">Add one</a> to show these categories.
        </div>
    </div>
<?php endif; ?>

<?php if ($strandedCount > 0): ?>
    <div class="sik-alert sik-alert--warning">
        <?= icon('alert', 'w-5 h-5') ?>
        <div>
            <strong><?= $strandedCount ?></strong> featured categor<?= $strandedCount === 1 ? 'y is' : 'ies are' ?>
            a subcategory or inactive. The grid only renders active top-level categories, so
            <?= $strandedCount === 1 ? 'it' : 'they' ?> will not appear.
        </div>
    </div>
<?php endif; ?>

<form method="post" data-guard-unsaved>
    <?= csrf_field() ?>
    <div class="ad-card">
        <div class="ad-card__head">
            <div>
                <div class="ad-card__title">Pick what appears in the category grid</div>
                <div class="ad-card__sub">Sort order is shared with the storefront menu and category rails.</div>
            </div>
            <button type="submit" class="ad-btn ad-btn--primary">
                <?= icon('check', 'w-4 h-4') ?> Save Selection
            </button>
        </div>

        <div class="ad-card__body ad-card__body--flush">
            <?php if ($tree === []): ?>
                <?= admin_empty(
                    'No categories yet',
                    'Create a category first — the homepage grid has nothing to show without one.',
                    admin_can('categories.create') ? 'Add Category' : null,
                    admin_can('categories.create') ? admin_url('categories/create.php') : null,
                    'grid'
                ) ?>
            <?php else: ?>
                <div class="ad-tablewrap">
                    <table class="ad-table">
                        <thead>
                            <tr>
                                <th class="ad-table__check">Featured</th>
                                <th>Category</th>
                                <th class="ad-table__num">Products</th>
                                <th>Sort</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tree as $entry): ?>
                                <?php
                                $row     = $entry['row'];
                                $depth   = (int) $entry['depth'];
                                $id      = (int) $row['id'];
                                $eligible = $depth === 0 && $row['status'] === 'active';
                                ?>
                                <tr>
                                    <td class="ad-table__check">
                                        <input type="checkbox" name="featured[]" value="<?= $id ?>"
                                               id="feat<?= $id ?>"
                                               <?= (int) $row['is_featured'] === 1 ? 'checked' : '' ?>>
                                        <label class="sik-sr" for="feat<?= $id ?>">
                                            Feature <?= e($row['name']) ?> on the homepage
                                        </label>
                                    </td>
                                    <td>
                                        <div class="ad-cellflex" style="padding-left:<?= $depth * 22 ?>px">
                                            <?php if ($depth > 0): ?>
                                                <span class="ad-muted" aria-hidden="true" style="font-family:monospace">&#9492;</span>
                                            <?php endif; ?>
                                            <img class="ad-thumb" src="<?= e(img_url($row['image'])) ?>" alt=""
                                                 width="38" height="38" loading="lazy">
                                            <span style="min-width:0">
                                                <span class="ad-cellflex__name" style="display:block"><?= e($row['name']) ?></span>
                                                <span class="ad-cellflex__meta ad-mono">/<?= e($row['slug']) ?></span>
                                            </span>
                                        </div>
                                    </td>
                                    <td class="ad-table__num"><?= number_format((int) $row['product_count']) ?></td>
                                    <td>
                                        <label class="sik-sr" for="ord<?= $id ?>">Sort order for <?= e($row['name']) ?></label>
                                        <input class="sik-input" type="number" id="ord<?= $id ?>"
                                               name="sort_order[<?= $id ?>]" min="0" max="9999" step="1"
                                               value="<?= (int) $row['sort_order'] ?>"
                                               style="width:78px;padding:6px 8px;font-size:13px">
                                    </td>
                                    <td>
                                        <?= admin_state_badge((string) $row['status']) ?>
                                        <?php if (!$eligible): ?>
                                            <span class="sik-status sik-status--gray" title="Only active top-level categories render in the grid">
                                                Not in grid
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

        <?php if ($tree !== []): ?>
            <div class="ad-card__foot">
                <span class="ad-muted"><?= count($rows) ?> categories</span>
                <button type="submit" class="ad-btn ad-btn--primary">
                    <?= icon('check', 'w-4 h-4') ?> Save Selection
                </button>
            </div>
        <?php endif; ?>
    </div>
</form>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
