<?php
/**
 * ShopInnKart Admin - Edit a category.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('categories.edit');

// The SEO card renders the shared editor; seo_editor_input() reads the nine
// fields it posts, already trimmed, null-when-blank and validated.
require_once ADMIN_PATH . '/includes/seo-editor.php';

$id = input_int('id');
$category = $id > 0
    ? Database::fetch('SELECT * FROM `categories` WHERE `id` = :id', ['id' => $id])
    : null;

if ($category === null) {
    flash('error', 'That category no longer exists.');
    redirect(admin_url('categories/'));
}

/** Every id below $id in the tree. A category may not be moved under itself. */
$descendantIds = static function (int $rootId): array {
    $pairs = Database::fetchAll('SELECT `id`, `parent_id` FROM `categories`');
    $childrenOf = [];
    foreach ($pairs as $pair) {
        $childrenOf[(int) ($pair['parent_id'] ?? 0)][] = (int) $pair['id'];
    }

    $ids = [];
    $queue = [$rootId];
    while ($queue !== []) {
        $current = (int) array_shift($queue);
        foreach ($childrenOf[$current] ?? [] as $childId) {
            if (!in_array($childId, $ids, true)) {
                $ids[] = $childId;
                $queue[] = $childId;
            }
        }
    }
    return $ids;
};

$errors = [];
// Held before the submitted values are merged in below: it is what decides
// whether the slug really changed, and so whether the old URL needs a 301.
$originalSlug = (string) $category['slug'];

if (is_post()) {
    csrf_require();

    $parentRaw = (string) input('parent_id', '');
    $seo       = seo_editor_input();
    // The panel's extended fields (keywords, the Twitter trio, breadcrumbs and
    // the custom code). Staged rather than written, so a refused code field
    // fails the whole save instead of appearing to succeed while dropping it.
    $seoErrors = [];
    $seoMeta   = seo_editor_meta_input($seoErrors);
    // A slug the admin TYPED that collides with another record or with a
    // reserved address is refused here, so it joins the validator errors
    // below. A blank one is still derived and quietly made unique.
    seo_editor_slug_check('category', (string) input('slug', ''), $id, $seoErrors);
    $submitted = [
        'name'         => (string) input('name', ''),
        'slug'         => (string) input('slug', ''),
        'parent_id'    => $parentRaw === '' ? null : (int) $parentRaw,
        'description'  => sanitize_html((string) input('description', '')),
        'icon'         => (string) input('icon', ''),
        'sort_order'   => input_int('sort_order', 0),
        'is_featured'  => input_bool('is_featured') ? 1 : 0,
        'show_in_menu' => input_bool('show_in_menu') ? 1 : 0,
        'status'       => (string) input('status', 'active'),
        ...$seo,
    ];
    $category = array_merge($category, $submitted);

    $v = new Validator($submitted, ['name' => 'Category name', 'parent_id' => 'Parent category']);
    $v->required('name')->max('name', 150)
      ->max('slug', 180)
      ->max('meta_title', 255)
      ->integer('sort_order')->between('sort_order', 0, 9999)
      ->in('status', ['active', 'inactive'])
      ->rule('icon', $submitted['icon'] === '' || icon_exists($submitted['icon']), 'Pick an icon from the list.');

    if ($submitted['parent_id'] !== null) {
        $v->exists('parent_id', 'categories');
        // The form hides these options, but a hand-crafted POST must not create
        // a cycle that would make the tree unwalkable.
        $blocked = array_merge([$id], $descendantIds($id));
        $v->rule(
            'parent_id',
            !in_array($submitted['parent_id'], $blocked, true),
            'A category cannot sit under itself or one of its own subcategories.'
        );
    }

    if ($v->fails() || $seoErrors !== []) {
        $errors = $v->errors() + $seoErrors;
        flash('error', 'Please correct the highlighted fields.');
    } else {
        // A typed slug is a decision, so a collision is refused and named;
        // a blank one is still derived from the name and quietly made unique.
        $slug = seo_editor_slug('category', $submitted['slug'], $submitted['name'], $id, $errors);

        $image = $category['image'];
        if ((string) input('remove_image', '0') === '1' && empty($_FILES['image']['name'])) {
            delete_upload($image);
            $image = null;
        }
        $image = admin_handle_image('image', 'categories', $image);

        $banner = $category['banner'];
        if ((string) input('remove_banner', '0') === '1' && empty($_FILES['banner']['name'])) {
            delete_upload($banner);
            $banner = null;
        }
        $banner = admin_handle_image('banner', 'categories', $banner);

        Database::update('categories', [
            'parent_id'    => $submitted['parent_id'],
            'name'         => $submitted['name'],
            'slug'         => $slug,
            'description'  => $submitted['description'] !== '' ? $submitted['description'] : null,
            'image'        => $image,
            'icon'         => $submitted['icon'] !== '' ? $submitted['icon'] : null,
            'banner'       => $banner,
            'sort_order'   => $submitted['sort_order'],
            'is_featured'  => $submitted['is_featured'],
            'show_in_menu' => $submitted['show_in_menu'],
            'status'       => $submitted['status'],
            ...$seo,
        ], '`id` = :id', ['id' => $id]);

        seo_entity_meta_save('category', $id, $seoMeta);
        // The old /category/<slug> keeps working as a 301 unless the admin
        // unticked the box beside the slug field.
        seo_editor_slug_change('category', $originalSlug, $slug);

        log_activity('category.updated', 'category', $id, 'Updated category "' . $submitted['name'] . '"');
        admin_after_write();

        flash('success', 'Category "' . $submitted['name'] . '" saved.');
        redirect(admin_url('categories/edit.php?id=' . $id));
    }
}

$childCount   = Database::count('categories', '`parent_id` = :id', ['id' => $id]);
$productCount = Database::count('products', '`category_id` = :id', ['id' => $id]);

$isEdit       = true;
$pageTitle    = 'Edit Category';
$pageSubtitle = $category['name'] . ' · ' . $childCount . ' subcategor' . ($childCount === 1 ? 'y' : 'ies')
    . ' · ' . $productCount . ' product' . ($productCount === 1 ? '' : 's');
$breadcrumbs  = [
    ['label' => 'Dashboard',  'url' => admin_url('dashboard.php')],
    ['label' => 'Categories', 'url' => admin_url('categories/')],
    ['label' => (string) $category['name']],
];

$pageActions = '<a class="ad-btn" href="' . e(category_url((string) $category['slug'])) . '" target="_blank" rel="noopener">'
    . icon('external', 'w-4 h-4') . ' View on store</a>';
if (admin_can('categories.delete')) {
    // Written out rather than using admin_delete_form() so the page action can
    // be a full-width labelled button instead of an icon.
    $confirm = ('Delete "' . $category['name'] . '"? This cannot be undone.');
    $pageActions .= '<form method="post" action="' . e(admin_url('categories/delete.php')) . '" class="ad-inline-form"'
        . admin_confirm_form_attrs($confirm, ['label' => 'Delete']) . '>'
        . csrf_field()
        . '<input type="hidden" name="id" value="' . $id . '">'
        . '<button type="submit" class="ad-btn ad-btn--danger">' . icon('trash', 'w-4 h-4') . ' Delete</button>'
        . '</form>';
}

require ADMIN_PATH . '/includes/header.php';
require __DIR__ . '/_form.php';
require ADMIN_PATH . '/includes/footer.php';
