<?php
/**
 * ShopInnKart Admin - Edit a CMS page.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('pages.edit');

// The SEO card renders the shared editor; seo_editor_input() reads the nine
// fields it posts, already trimmed, null-when-blank and validated.
require_once ADMIN_PATH . '/includes/seo-editor.php';

$id = input_int('id');
$cmsPage = $id > 0
    ? Database::fetch('SELECT * FROM `pages` WHERE `id` = :id', ['id' => $id])
    : null;

if ($cmsPage === null) {
    flash('error', 'That page no longer exists.');
    redirect(admin_url('pages/'));
}

$isSystem = (int) $cmsPage['is_system'] === 1;
// Held before the form values are merged in: on a system page this is the slug
// that gets written back, whatever the (read-only) input posts.
$storedSlug = (string) $cmsPage['slug'];
$errors     = [];

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
    if ($isSystem) {
        // Keep the redisplayed field honest about what will actually be saved.
        $cmsPage['slug'] = $storedSlug;
    }

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
        // A fixed route resolves a system page by its slug, so that slug is
        // kept whatever the (read-only) input sends back.
        $slug = $isSystem
            ? $storedSlug
            : unique_slug(
                'pages',
                slugify($submitted['slug'] !== '' ? $submitted['slug'] : $submitted['title']),
                $id
            );

        $banner = $cmsPage['banner_image'];
        if ((string) input('remove_banner_image', '0') === '1' && empty($_FILES['banner_image']['name'])) {
            delete_upload($banner);
            $banner = null;
        }
        $banner = admin_handle_image('banner_image', 'pages', $banner);

        Database::update('pages', [
            'title'          => $submitted['title'],
            'slug'           => $slug,
            'content'        => $submitted['content'] !== '' ? $submitted['content'] : null,
            'banner_image'   => $banner,
            'show_in_footer' => $submitted['show_in_footer'],
            'sort_order'     => $submitted['sort_order'],
            'status'         => $submitted['status'],
            ...$seo,
        ], '`id` = :id', ['id' => $id]);

        log_activity('page.updated', 'page', $id, 'Updated page "' . $submitted['title'] . '"');
        admin_after_write();

        flash('success', 'Page "' . $submitted['title'] . '" saved.');
        redirect(admin_url('pages/edit.php?id=' . $id));
    }
}

$isEdit       = true;
$pageTitle    = 'Edit Page';
$pageSubtitle = '/page/' . $cmsPage['slug'] . ($isSystem ? ' · system page' : '');
$breadcrumbs  = [
    ['label' => 'Dashboard', 'url' => admin_url('dashboard.php')],
    ['label' => 'Pages',     'url' => admin_url('pages/')],
    ['label' => (string) $cmsPage['title']],
];

$pageActions = '<a class="ad-btn" href="' . e(page_url((string) $cmsPage['slug'])) . '" target="_blank" rel="noopener">'
    . icon('external', 'w-4 h-4') . ' View on store</a>';

if (admin_can('pages.delete') && !$isSystem) {
    $confirm = 'Delete "' . $cmsPage['title'] . '"? This cannot be undone.';
    $pageActions .= '<form method="post" action="' . e(admin_url('pages/delete.php')) . '" class="ad-inline-form"'
        . ' onsubmit="return confirm(' . e_attr((string) json_encode($confirm)) . ')">'
        . csrf_field()
        . '<input type="hidden" name="id" value="' . $id . '">'
        . '<button type="submit" class="ad-btn ad-btn--danger">' . icon('trash', 'w-4 h-4') . ' Delete</button>'
        . '</form>';
}

require ADMIN_PATH . '/includes/header.php';
?>

<?php if ($isSystem): ?>
    <div class="sik-alert sik-alert--info">
        <?= icon('lock', 'w-5 h-5') ?>
        <div>
            A fixed storefront route resolves this page by its slug
            (<code class="ad-mono">/page/<?= e($cmsPage['slug']) ?></code>).
            Edit the content freely &mdash; the slug stays put and the page cannot be deleted.
        </div>
    </div>
<?php endif; ?>

<?php
require __DIR__ . '/_form.php';
require ADMIN_PATH . '/includes/footer.php';
