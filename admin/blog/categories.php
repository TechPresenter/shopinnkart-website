<?php
/**
 * ShopInnKart Admin - Blog categories.
 *
 * Small enough to live on one screen: the list and the create/edit form share
 * a single modal, so an admin never leaves the page to add a bucket.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('blog.view');

$errors = [];

// What the modal shows. On a validation failure it is refilled from the POST
// so nothing the admin typed is lost when the modal reopens.
$formValues = [
    'id'          => 0,
    'name'        => '',
    'slug'        => '',
    'description' => '',
    'sort_order'  => 0,
    'status'      => 'active',
];

if (is_post()) {
    csrf_require();

    $categoryId = input_int('id', 0);

    // The permission depends on which half of the CRUD this is.
    $permission = $categoryId > 0 ? 'blog.edit' : 'blog.create';
    if (!admin_can($permission)) {
        flash('error', 'You do not have permission to ' . ($categoryId > 0 ? 'edit' : 'create') . ' blog categories.');
        redirect(admin_url('blog/categories.php'));
    }

    $existing = $categoryId > 0
        ? Database::fetch('SELECT * FROM `blog_categories` WHERE `id` = :id', ['id' => $categoryId])
        : null;

    if ($categoryId > 0 && $existing === null) {
        flash('error', 'That category no longer exists.');
        redirect(admin_url('blog/categories.php'));
    }

    $submitted = [
        'name'        => (string) input('name', ''),
        'slug'        => (string) input('slug', ''),
        'description' => trim((string) ($_POST['description'] ?? '')),
        'sort_order'  => input_int('sort_order', 0),
        'status'      => (string) input('status', 'active'),
    ];

    $v = new Validator($submitted, ['name' => 'Category name']);
    $v->required('name')->max('name', 150)
      ->max('slug', 180)
      ->max('description', 255)
      ->integer('sort_order')->between('sort_order', 0, 9999)
      ->in('status', ['active', 'inactive']);

    if ($v->fails()) {
        $errors = $v->errors();
        $formValues = array_merge($formValues, $submitted, ['id' => $categoryId]);
    } else {
        $slug = unique_slug(
            'blog_categories',
            slugify($submitted['slug'] !== '' ? $submitted['slug'] : $submitted['name']),
            $categoryId > 0 ? $categoryId : null
        );

        $data = [
            'name'        => $submitted['name'],
            'slug'        => $slug,
            'description' => $submitted['description'] !== '' ? $submitted['description'] : null,
            'sort_order'  => $submitted['sort_order'],
            'status'      => $submitted['status'],
        ];

        if ($categoryId > 0) {
            Database::update('blog_categories', $data, '`id` = :id', ['id' => $categoryId]);
            log_activity('blog_category.updated', 'blog_category', $categoryId,
                'Updated blog category "' . $submitted['name'] . '"');
            flash('success', 'Category "' . $submitted['name'] . '" saved.');
        } else {
            $categoryId = Database::insert('blog_categories', $data);
            log_activity('blog_category.created', 'blog_category', $categoryId,
                'Created blog category "' . $submitted['name'] . '"');
            flash('success', 'Category "' . $submitted['name'] . '" created.');
        }

        admin_after_write();
        redirect(admin_url('blog/categories.php'));
    }
}

$categories = Database::fetchAll(
    'SELECT c.`id`, c.`name`, c.`slug`, c.`description`, c.`sort_order`, c.`status`,
            (SELECT COUNT(*) FROM `blog_posts` p WHERE p.`category_id` = c.`id`) AS post_count
     FROM `blog_categories` c
     ORDER BY c.`sort_order` ASC, c.`name` ASC'
);

$canCreate = admin_can('blog.create');
$canEdit   = admin_can('blog.edit');
$canDelete = admin_can('blog.delete');

$pageTitle    = 'Blog Categories';
$pageSubtitle = count($categories) . ' categor' . (count($categories) === 1 ? 'y' : 'ies');
$breadcrumbs  = [
    ['label' => 'Dashboard', 'url' => admin_url('dashboard.php')],
    ['label' => 'Blog',      'url' => admin_url('blog/')],
    ['label' => 'Categories'],
];
$pageActions = '<a class="ad-btn" href="' . e(admin_url('blog/')) . '">' . icon('arrow-left', 'w-4 h-4') . ' Back to posts</a>';
if ($canCreate) {
    $pageActions .= '<button type="button" class="ad-btn ad-btn--primary" data-modal-open="blogCategoryModal"'
        . ' data-field-id="0" data-field-name="" data-field-slug="" data-field-description=""'
        . ' data-field-sort-order="0" data-field-status="active">'
        . icon('plus', 'w-4 h-4') . ' Add Category</button>';
}

require ADMIN_PATH . '/includes/header.php';
?>

<div class="ad-card">
    <div class="ad-card__body ad-card__body--flush">
        <?php if ($categories === []): ?>
            <?= admin_empty(
                'No blog categories yet',
                'Categories group posts on the blog listing and give /blog/category/<slug> something to show.',
                null,
                null,
                'tag'
            ) ?>
            <?php if ($canCreate): ?>
                <div style="padding:0 20px 24px;text-align:center">
                    <button type="button" class="ad-btn ad-btn--primary" data-modal-open="blogCategoryModal"
                            data-field-id="0" data-field-name="" data-field-slug="" data-field-description=""
                            data-field-sort-order="0" data-field-status="active">
                        <?= icon('plus', 'w-4 h-4') ?> Add Category
                    </button>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="ad-tablewrap">
                <table class="ad-table">
                    <thead>
                        <tr>
                            <th>Category</th>
                            <th>Description</th>
                            <th class="ad-table__num">Posts</th>
                            <th>Sort</th>
                            <th>Status</th>
                            <th class="ad-table__actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($categories as $category): ?>
                            <?php
                            $categoryId = (int) $category['id'];
                            $postCount  = (int) $category['post_count'];
                            ?>
                            <tr>
                                <td>
                                    <span class="ad-cellflex__name" style="display:block"><?= e($category['name']) ?></span>
                                    <span class="ad-cellflex__meta ad-mono">/blog/category/<?= e($category['slug']) ?></span>
                                </td>
                                <td class="ad-muted"><?= e(str_limit($category['description'], 70) ?: '—') ?></td>
                                <td class="ad-table__num">
                                    <?php if ($postCount > 0): ?>
                                        <a href="<?= e(admin_url('blog/?category_id=' . $categoryId)) ?>">
                                            <?= number_format($postCount) ?>
                                        </a>
                                    <?php else: ?>
                                        0
                                    <?php endif; ?>
                                </td>
                                <td><?= (int) $category['sort_order'] ?></td>
                                <td><?= admin_state_badge((string) $category['status']) ?></td>
                                <td class="ad-table__actions">
                                    <?php if ($canEdit): ?>
                                        <button type="button" class="ad-btn ad-btn--icon" title="Edit"
                                                aria-label="Edit <?= e_attr($category['name']) ?>"
                                                data-modal-open="blogCategoryModal"
                                                data-field-id="<?= $categoryId ?>"
                                                data-field-name="<?= e_attr($category['name']) ?>"
                                                data-field-slug="<?= e_attr($category['slug']) ?>"
                                                data-field-description="<?= e_attr((string) $category['description']) ?>"
                                                data-field-sort-order="<?= (int) $category['sort_order'] ?>"
                                                data-field-status="<?= e_attr($category['status']) ?>">
                                            <?= icon('edit', 'w-4 h-4') ?>
                                        </button>
                                    <?php endif; ?>
                                    <?php if ($canDelete): ?>
                                        <?php
                                        // fk_post_category is ON DELETE SET NULL, so the posts survive
                                        // as Uncategorised. The confirmation names that cost.
                                        $confirmText = $postCount > 0
                                            ? 'Delete "' . $category['name'] . '"? ' . $postCount . ' post'
                                                . ($postCount === 1 ? '' : 's') . ' will become uncategorised.'
                                            : 'Delete "' . $category['name'] . '"? This cannot be undone.';
                                        ?>
                                        <?= admin_delete_form(
                                            admin_url('blog/category-delete.php'),
                                            $categoryId,
                                            $confirmText
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
</div>

<?php if ($canCreate || $canEdit): ?>
    <div class="ad-modal<?= $errors !== [] ? ' is-open' : '' ?>" id="blogCategoryModal">
        <div class="ad-modal__backdrop"></div>
        <div class="ad-modal__panel">
            <form method="post" action="<?= e(admin_url('blog/categories.php')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int) $formValues['id'] ?>">

                <div class="ad-modal__head">
                    <strong>Blog category</strong>
                    <button type="button" class="ad-btn ad-btn--icon" data-modal-close aria-label="Close">
                        <?= icon('close', 'w-4 h-4') ?>
                    </button>
                </div>

                <div class="ad-modal__body">
                    <div class="ad-field">
                        <label class="sik-label" for="blogCategoryName">Name <span class="req">*</span></label>
                        <input class="sik-input<?= isset($errors['name']) ? ' is-invalid' : '' ?>" type="text"
                        <?php
                        // No data-slug-source here: the modal is prefilled by JS after
                        // the slug script has already decided the field is empty, so
                        // renaming a category would quietly rewrite its live URL.
                        ?>
                        <input class="sik-input<?= isset($errors['name']) ? ' is-invalid' : '' ?>" type="text"
                               id="blogCategoryName" name="name" maxlength="150" required
                               value="<?= e($formValues['name']) ?>" placeholder="e.g. Buying Guides">
                        <?php if (isset($errors['name'])): ?>
                            <span class="sik-error"><?= e($errors['name']) ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="ad-field">
                        <label class="sik-label" for="blogCategorySlug">Slug</label>
                        <input class="sik-input<?= isset($errors['slug']) ? ' is-invalid' : '' ?>" type="text"
                               id="blogCategorySlug" name="slug" maxlength="180" data-slugify
                               value="<?= e($formValues['slug']) ?>" placeholder="buying-guides">
                        <?php if (isset($errors['slug'])): ?>
                            <span class="sik-error"><?= e($errors['slug']) ?></span>
                        <?php else: ?>
                            <span class="sik-help">Leave blank to build it from the name.</span>
                        <?php endif; ?>
                    </div>

                    <div class="ad-field">
                        <label class="sik-label" for="blogCategoryDescription">Description</label>
                        <textarea class="sik-textarea" id="blogCategoryDescription" name="description" rows="2"
                                  maxlength="255" style="min-height:70px"
                                  placeholder="Shown on the category listing header."><?= e($formValues['description']) ?></textarea>
                        <?php if (isset($errors['description'])): ?>
                            <span class="sik-error"><?= e($errors['description']) ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="ad-row ad-row--2">
                        <div class="ad-field">
                            <label class="sik-label" for="blogCategorySortOrder">Sort order</label>
                            <input class="sik-input<?= isset($errors['sort_order']) ? ' is-invalid' : '' ?>" type="number"
                                   id="blogCategorySortOrder" name="sort_order" min="0" max="9999" step="1"
                                   value="<?= (int) $formValues['sort_order'] ?>">
                            <?php if (isset($errors['sort_order'])): ?>
                                <span class="sik-error"><?= e($errors['sort_order']) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="ad-field">
                            <label class="sik-label" for="blogCategoryStatus">Status</label>
                            <select class="sik-select" id="blogCategoryStatus" name="status">
                                <?= admin_options(['active' => 'Active', 'inactive' => 'Inactive'], $formValues['status']) ?>
                            </select>
                            <?php if (isset($errors['status'])): ?>
                                <span class="sik-error"><?= e($errors['status']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="ad-modal__foot">
                    <button type="button" class="ad-btn" data-modal-close>Cancel</button>
                    <button type="submit" class="ad-btn ad-btn--primary">
                        <?= icon('check', 'w-4 h-4') ?> Save Category
                    </button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
