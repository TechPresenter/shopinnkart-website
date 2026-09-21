<?php
/**
 * ShopInnKart Admin - Create a CMS page.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('pages.create');

// The SEO card renders the shared editor; seo_editor_input() reads the nine
// fields it posts, already trimmed, null-when-blank and validated.
require_once ADMIN_PATH . '/includes/seo-editor.php';

$cmsPage = [
    'title'            => '',
    'slug'             => '',
    'content'          => '',
    'banner_image'     => null,
    'is_system'        => 0,
    'show_in_footer'   => 1,
    'sort_order'       => 0,
    'status'           => 'active',
    'meta_title'       => '',
    'meta_description' => '',
];
$errors = [];

if (is_post()) {
    csrf_require();

    $seo       = seo_editor_input();
    $submitted = [
        'title'          => (string) input('title', ''),
        'slug'           => (string) input('slug', ''),
        'content'        => sanitize_html((string) ($_POST['content'] ?? '')),
        'show_in_footer' => input_bool('show_in_footer') ? 1 : 0,
        'sort_order'     => input_int('sort_order', 0),
        'status'         => (string) input('status', 'active'),
        ...$seo,
    ];
    $cmsPage = array_merge($cmsPage, $submitted);

    $v = new Validator($submitted, ['title' => 'Page title']);
    $v->required('title')->max('title', 200)
      ->max('slug', 220)
      ->max('meta_title', 255)
      ->integer('sort_order')->between('sort_order', 0, 9999)
      ->in('status', ['active', 'inactive']);

    if ($v->fails()) {
        $errors = $v->errors();
        flash('error', 'Please correct the highlighted fields.');
    } else {
        $slug   = unique_slug('pages', slugify($submitted['slug'] !== '' ? $submitted['slug'] : $submitted['title']));
        $banner = admin_handle_image('banner_image', 'pages');

        $id = Database::insert('pages', [
            'title'          => $submitted['title'],
            'slug'           => $slug,
            'content'        => $submitted['content'] !== '' ? $submitted['content'] : null,
            'banner_image'   => $banner,
            // is_system is set by the installer, never by this form: it marks the
            // pages a hard-coded storefront route depends on.
            'is_system'      => 0,
            'show_in_footer' => $submitted['show_in_footer'],
            'sort_order'     => $submitted['sort_order'],
            'status'         => $submitted['status'],
            ...$seo,
        ]);

        log_activity('page.created', 'page', $id, 'Created page "' . $submitted['title'] . '"');
        admin_after_write();

        flash('success', 'Page "' . $submitted['title'] . '" created.');
        redirect(admin_url('pages/edit.php?id=' . $id));
    }
}

$isEdit       = false;
$pageTitle    = 'Add Page';
$pageSubtitle = 'Pages are published at /page/<slug> and can be listed in the footer.';
$breadcrumbs  = [
    ['label' => 'Dashboard', 'url' => admin_url('dashboard.php')],
    ['label' => 'Pages',     'url' => admin_url('pages/')],
    ['label' => 'Add Page'],
];

require ADMIN_PATH . '/includes/header.php';
require __DIR__ . '/_form.php';
require ADMIN_PATH . '/includes/footer.php';
