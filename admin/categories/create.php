<?php
/**
 * ShopInnKart Admin - Create a category.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('categories.create');

// The SEO card renders the shared editor; seo_editor_input() reads the nine
// fields it posts, already trimmed, null-when-blank and validated.
require_once ADMIN_PATH . '/includes/seo-editor.php';

$category = [
    'name'             => '',
    'slug'             => '',
    'parent_id'        => input_int('parent', 0) > 0 ? input_int('parent') : null,
    'description'      => '',
    'image'            => null,
    'icon'             => '',
    'banner'           => null,
    'sort_order'       => 0,
    'is_featured'      => 0,
    'show_in_menu'     => 1,
    'status'           => 'active',
    'meta_title'       => '',
    'meta_description' => '',
];
$errors = [];

if (is_post()) {
    csrf_require();

    $parentRaw = (string) input('parent_id', '');
    $seo       = seo_editor_input();
    // The panel's extended fields. Staged rather than written, so a refused
    // code field fails the whole save instead of appearing to succeed while
    // quietly dropping what somebody typed.
    $seoErrors = [];
    $seoMeta   = seo_editor_meta_input($seoErrors);
    // A slug the admin TYPED that collides with another record or with a
    // reserved address is refused here, so it joins the validator errors
    // below. A blank one is still derived and quietly made unique.
    seo_editor_slug_check('category', (string) input('slug', ''), null, $seoErrors);
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
    }

    if ($v->fails() || $seoErrors !== []) {
        $errors = $v->errors() + $seoErrors;
        flash('error', 'Please correct the highlighted fields.');
    } else {
        $slug   = seo_editor_slug('category', $submitted['slug'], $submitted['name'], null, $errors);
        $image  = admin_handle_image('image', 'categories');
        $banner = admin_handle_image('banner', 'categories');

        $id = Database::insert('categories', [
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
        ]);

        // The record exists now, so its extended SEO fields have an id to
        // hang off. A create has no old slug, so there is no redirect to write.
        seo_entity_meta_save('category', $id, $seoMeta);

        log_activity('category.created', 'category', $id, 'Created category "' . $submitted['name'] . '"');
        admin_after_write();

        flash('success', 'Category "' . $submitted['name'] . '" created.');
        redirect(admin_url('categories/edit.php?id=' . $id));
    }
}

$isEdit       = false;
$pageTitle    = 'Add Category';
$pageSubtitle = 'Create a catalogue category and place it in the tree.';
$breadcrumbs  = [
    ['label' => 'Dashboard',  'url' => admin_url('dashboard.php')],
    ['label' => 'Categories', 'url' => admin_url('categories/')],
    ['label' => 'Add Category'],
];

require ADMIN_PATH . '/includes/header.php';
require __DIR__ . '/_form.php';
require ADMIN_PATH . '/includes/footer.php';
