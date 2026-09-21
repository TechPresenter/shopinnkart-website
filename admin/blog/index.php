<?php
/**
 * ShopInnKart Admin - Blog post list.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('blog.view');

$search     = trim((string) ($_GET['q'] ?? ''));
$status     = admin_filter('status', ['published', 'draft']);
$categoryId = max(0, (int) ($_GET['category_id'] ?? 0));
$page       = max(1, (int) ($_GET['page'] ?? 1));

// ORDER BY is built from this map only, never from the raw query string.
$sortMap = [
    'title'     => 'p.`title`',
    'views'     => 'p.`views`',
    'published' => 'p.`published_at`',
    'created'   => 'p.`created_at`',
];
$sort   = admin_safe_sort((string) ($_GET['sort'] ?? ''), array_keys($sortMap), 'created');
$dir    = strtolower((string) ($_GET['dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
$dirSql = admin_safe_dir($dir);

$where  = ['1'];
$params = [];

if ($search !== '') {
    // Each occurrence needs its own placeholder: with emulated prepares off,
    // PDO binds a named marker exactly once.
    $where[] = '(p.`title` LIKE :q_title OR p.`slug` LIKE :q_slug OR p.`excerpt` LIKE :q_excerpt
                 OR p.`author_name` LIKE :q_author)';
    $params['q_title'] = $params['q_slug'] = $params['q_excerpt'] = $params['q_author'] = '%' . $search . '%';
}
if ($status !== '') {
    $where[] = 'p.`status` = :status';
    $params['status'] = $status;
}
if ($categoryId > 0) {
    $where[] = 'p.`category_id` = :category_id';
    $params['category_id'] = $categoryId;
}
$whereSql = implode(' AND ', $where);

$total      = (int) Database::fetchColumn("SELECT COUNT(*) FROM `blog_posts` p WHERE {$whereSql}", $params);
$pagination = paginate($total, ADMIN_PER_PAGE, $page);

// PDO cannot bind LIMIT/OFFSET with emulated prepares off, so they are cast here.
$limit  = (int) $pagination['per_page'];
$offset = (int) $pagination['offset'];

$posts = Database::fetchAll(
    "SELECT p.`id`, p.`title`, p.`slug`, p.`featured_image`, p.`author_name`, p.`views`,
            p.`is_featured`, p.`status`, p.`published_at`, p.`created_at`,
            c.`name` AS category_name, c.`id` AS category_id
     FROM `blog_posts` p
     LEFT JOIN `blog_categories` c ON c.`id` = p.`category_id`
     WHERE {$whereSql}
     ORDER BY {$sortMap[$sort]} {$dirSql}, p.`id` DESC
     LIMIT {$limit} OFFSET {$offset}",
    $params
);

$counts = [
    'all'       => Database::count('blog_posts'),
    'published' => Database::count('blog_posts', "`status` = 'published'"),
    'draft'     => Database::count('blog_posts', "`status` = 'draft'"),
];

$categoryOptions = Database::fetchPairs('SELECT `id`, `name` FROM `blog_categories` ORDER BY `sort_order`, `name`');

$canEdit   = admin_can('blog.edit');
$canDelete = admin_can('blog.delete');
$hasFilter = $search !== '' || $status !== '' || $categoryId > 0;

$pageTitle    = 'Blog';
$pageSubtitle = $counts['all'] . ' posts · ' . $counts['published'] . ' published · ' . $counts['draft'] . ' draft';
$breadcrumbs  = [
    ['label' => 'Dashboard', 'url' => admin_url('dashboard.php')],
    ['label' => 'Blog'],
];
$pageActions = '<a class="ad-btn" href="' . e(admin_url('blog/categories.php')) . '">'
    . icon('tag', 'w-4 h-4') . ' Categories</a>';
if (admin_can('blog.create')) {
    $pageActions .= '<a class="ad-btn ad-btn--primary" href="' . e(admin_url('blog/create.php')) . '">'
        . icon('plus', 'w-4 h-4') . ' Write Post</a>';
}

require ADMIN_PATH . '/includes/header.php';
?>

<div class="ad-card">
    <div class="ad-tabs">
        <?php foreach (['' => 'All', 'published' => 'Published', 'draft' => 'Draft'] as $key => $label): ?>
            <a class="ad-tab <?= $status === $key ? 'is-active' : '' ?>"
               href="<?= e(url_with(['status' => $key === '' ? null : $key, 'page' => null])) ?>">
                <?= e($label) ?>
                <span class="ad-tab__count"><?= (int) ($counts[$key === '' ? 'all' : $key] ?? 0) ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <form class="ad-filters" method="get" action="<?= e(admin_url('blog/')) ?>">
        <?php if ($status !== ''): ?>
            <input type="hidden" name="status" value="<?= e($status) ?>">
        <?php endif; ?>
        <div class="ad-search">
            <?= icon('search', 'w-4 h-4') ?>
            <label class="sik-sr" for="postSearch">Search posts</label>
            <input class="sik-input" type="search" id="postSearch" name="q" data-filter-search
                   value="<?= e($search) ?>" placeholder="Title, slug, excerpt or author&hellip;" autocomplete="off">
        </div>

        <label class="sik-sr" for="postCategory">Category filter</label>
        <select class="sik-select" id="postCategory" name="category_id" data-auto-submit>
            <?= admin_options($categoryOptions, $categoryId > 0 ? $categoryId : '', 'All categories') ?>
        </select>

        <button type="submit" class="ad-btn ad-btn--sm"><?= icon('filter', 'w-4 h-4') ?> Filter</button>
        <?php if ($hasFilter): ?>
            <a class="ad-btn ad-btn--sm" href="<?= e(admin_url('blog/')) ?>">Reset</a>
        <?php endif; ?>
    </form>

    <div class="ad-card__body ad-card__body--flush">
        <?php if ($posts === []): ?>
            <?= $hasFilter
                ? admin_empty('No posts match', 'Try a different search term or clear the filters.', null, null, 'search')
                : admin_empty(
                    'No posts yet',
                    'The blog drives organic traffic and gives the storefront something to link to. Write the first post.',
                    admin_can('blog.create') ? 'Write Post' : null,
                    admin_can('blog.create') ? admin_url('blog/create.php') : null,
                    'edit'
                ) ?>
        <?php else: ?>
            <div class="ad-tablewrap">
                <table class="ad-table">
                    <thead>
                        <tr>
                            <th style="width:64px">Image</th>
                            <th><?= admin_sort_header('Title', 'title', $sort, $dir) ?></th>
                            <th>Category</th>
                            <th>Author</th>
                            <th class="ad-table__num"><?= admin_sort_header('Views', 'views', $sort, $dir) ?></th>
                            <th><?= admin_sort_header('Published', 'published', $sort, $dir) ?></th>
                            <th>Status</th>
                            <th class="ad-table__actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($posts as $post): ?>
                            <?php $postId = (int) $post['id']; ?>
                            <tr>
                                <td>
                                    <img class="ad-thumb" src="<?= e(img_url($post['featured_image'])) ?>"
                                         alt="" width="44" height="44" loading="lazy">
                                </td>
                                <td>
                                    <span style="display:block;min-width:0">
                                        <span class="ad-cellflex__name" style="display:block">
                                            <a href="<?= e(admin_url('blog/view.php?id=' . $postId)) ?>">
                                                <?= e(str_limit($post['title'], 70)) ?>
                                            </a>
                                            <?php if ((int) $post['is_featured'] === 1): ?>
                                                <span class="sik-status sik-status--amber">Featured</span>
                                            <?php endif; ?>
                                        </span>
                                        <span class="ad-cellflex__meta ad-mono">/blog/<?= e($post['slug']) ?></span>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($post['category_name'] !== null): ?>
                                        <a href="<?= e(url_with(['category_id' => (int) $post['category_id'], 'page' => null])) ?>">
                                            <?= e($post['category_name']) ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="ad-muted">&mdash;</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= e($post['author_name'] ?: '—') ?></td>
                                <td class="ad-table__num"><?= number_format((int) $post['views']) ?></td>
                                <td class="ad-muted">
                                    <?= $post['published_at'] !== null
                                        ? e(format_date($post['published_at'], 'd M Y'))
                                        : '<span class="ad-muted">Not scheduled</span>' ?>
                                </td>
                                <td><?= admin_state_badge((string) $post['status']) ?></td>
                                <td class="ad-table__actions">
                                    <a class="ad-btn ad-btn--icon" title="Preview"
                                       aria-label="Preview <?= e_attr($post['title']) ?>"
                                       href="<?= e(admin_url('blog/view.php?id=' . $postId)) ?>">
                                        <?= icon('eye', 'w-4 h-4') ?>
                                    </a>
                                    <?php if ($canEdit): ?>
                                        <a class="ad-btn ad-btn--icon" title="Edit"
                                           aria-label="Edit <?= e_attr($post['title']) ?>"
                                           href="<?= e(admin_url('blog/edit.php?id=' . $postId)) ?>">
                                            <?= icon('edit', 'w-4 h-4') ?>
                                        </a>
                                    <?php endif; ?>
                                    <?php if ($canDelete): ?>
                                        <?= admin_delete_form(
                                            admin_url('blog/delete.php'),
                                            $postId,
                                            'Delete "' . $post['title'] . '"? This cannot be undone.'
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
            <?= admin_pagination($pagination, admin_url('blog/')) ?>
        </div>
    <?php endif; ?>
</div>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
