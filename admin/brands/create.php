<?php
/**
 * ShopInnKart Admin - Create a brand.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('brands.create');

// The SEO card renders the shared editor; seo_editor_input() reads the nine
// fields it posts, already trimmed, null-when-blank and validated.
require_once ADMIN_PATH . '/includes/seo-editor.php';

$brand = [
    'name'             => '',
    'slug'             => '',
    'logo'             => null,
    'description'      => '',
    'website'          => '',
    'sort_order'       => 0,
    'is_featured'      => 0,
    'status'           => 'active',
    'meta_title'       => '',
    'meta_description' => '',
];
$errors = [];

if (is_post()) {
    csrf_require();

    $seo       = seo_editor_input();
    // The panel's extended fields. Staged rather than written, so a refused
    // code field fails the whole save instead of appearing to succeed while
    // quietly dropping what somebody typed.
    $seoErrors = [];
    $seoMeta   = seo_editor_meta_input($seoErrors);
    // A slug the admin TYPED that collides with another record or with a
    // reserved address is refused here, so it joins the validator errors
    // below. A blank one is still derived and quietly made unique.
    seo_editor_slug_check('brand', (string) input('slug', ''), null, $seoErrors);
    $submitted = [
        'name'        => (string) input('name', ''),
        'slug'        => (string) input('slug', ''),
        'description' => sanitize_html((string) input('description', '')),
        'website'     => (string) input('website', ''),
        'sort_order'  => input_int('sort_order', 0),
        'is_featured' => input_bool('is_featured') ? 1 : 0,
        'status'      => (string) input('status', 'active'),
        ...$seo,
    ];
    $brand = array_merge($brand, $submitted);

    $v = new Validator($submitted, ['name' => 'Brand name', 'website' => 'Website']);
    $v->required('name')->max('name', 150)
      ->max('slug', 180)
      ->url('website')->max('website', 255)
      ->max('meta_title', 255)
      ->integer('sort_order')->between('sort_order', 0, 9999)
      ->in('status', ['active', 'inactive']);

    if ($v->fails() || $seoErrors !== []) {
        $errors = $v->errors() + $seoErrors;
        flash('error', 'Please correct the highlighted fields.');
    } else {
        $slug = seo_editor_slug('brand', $submitted['slug'], $submitted['name'], null, $errors);
        $logo = admin_handle_image('logo', 'brands');

        $id = Database::insert('brands', [
            'name'        => $submitted['name'],
            'slug'        => $slug,
            'logo'        => $logo,
            'description' => $submitted['description'] !== '' ? $submitted['description'] : null,
            'website'     => $submitted['website'] !== '' ? $submitted['website'] : null,
            'sort_order'  => $submitted['sort_order'],
            'is_featured' => $submitted['is_featured'],
            'status'      => $submitted['status'],
            ...$seo,
        ]);

        // The record exists now, so its extended SEO fields have an id to
        // hang off. A create has no old slug, so there is no redirect to write.
        seo_entity_meta_save('brand', $id, $seoMeta);

        log_activity('brand.created', 'brand', $id, 'Created brand "' . $submitted['name'] . '"');
        admin_after_write();

        flash('success', 'Brand "' . $submitted['name'] . '" created.');
        redirect(admin_url('brands/edit.php?id=' . $id));
    }
}

$isEdit       = false;
$pageTitle    = 'Add Brand';
$pageSubtitle = 'Brands drive the shop filters and the brand landing pages.';
$breadcrumbs  = [
    ['label' => 'Dashboard', 'url' => admin_url('dashboard.php')],
    ['label' => 'Brands',    'url' => admin_url('brands/')],
    ['label' => 'Add Brand'],
];

require ADMIN_PATH . '/includes/header.php';
require __DIR__ . '/_form.php';
require ADMIN_PATH . '/includes/footer.php';
