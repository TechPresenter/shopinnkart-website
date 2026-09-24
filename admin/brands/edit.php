<?php
/**
 * ShopInnKart Admin - Edit a brand.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('brands.edit');

// The SEO card renders the shared editor; seo_editor_input() reads the nine
// fields it posts, already trimmed, null-when-blank and validated.
require_once ADMIN_PATH . '/includes/seo-editor.php';

$id = input_int('id');
$brand = $id > 0
    ? Database::fetch('SELECT * FROM `brands` WHERE `id` = :id', ['id' => $id])
    : null;

if ($brand === null) {
    flash('error', 'That brand no longer exists.');
    redirect(admin_url('brands/'));
}

$errors = [];
// Held before the submitted values are merged in below: it is what decides
// whether the slug really changed, and so whether the old URL needs a 301.
$originalSlug = (string) $brand['slug'];

if (is_post()) {
    csrf_require();

    $seo       = seo_editor_input();
    // The panel's extended fields (keywords, the Twitter trio, breadcrumbs and
    // the custom code). Staged rather than written, so a refused code field
    // fails the whole save instead of appearing to succeed while dropping it.
    $seoErrors = [];
    $seoMeta   = seo_editor_meta_input($seoErrors);
    // A slug the admin TYPED that collides with another record or with a
    // reserved address is refused here, so it joins the validator errors
    // below. A blank one is still derived and quietly made unique.
    seo_editor_slug_check('brand', (string) input('slug', ''), $id, $seoErrors);
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
        // A typed slug is a decision, so a collision is refused and named;
        // a blank one is still derived from the name and quietly made unique.
        $slug = seo_editor_slug('brand', $submitted['slug'], $submitted['name'], $id, $errors);

        $logo = $brand['logo'];
        if ((string) input('remove_logo', '0') === '1' && empty($_FILES['logo']['name'])) {
            delete_upload($logo);
            $logo = null;
        }
        $logo = admin_handle_image('logo', 'brands', $logo);

        Database::update('brands', [
            'name'        => $submitted['name'],
            'slug'        => $slug,
            'logo'        => $logo,
            'description' => $submitted['description'] !== '' ? $submitted['description'] : null,
            'website'     => $submitted['website'] !== '' ? $submitted['website'] : null,
            'sort_order'  => $submitted['sort_order'],
            'is_featured' => $submitted['is_featured'],
            'status'      => $submitted['status'],
            ...$seo,
        ], '`id` = :id', ['id' => $id]);

        seo_entity_meta_save('brand', $id, $seoMeta);
        // A renamed brand's old URL keeps working as a 301 unless the admin
        // unticked the box next to the slug. Every link to it that already
        // exists out in the world is otherwise a hard 404.
        seo_editor_slug_change('brand', $originalSlug, $slug);

        log_activity('brand.updated', 'brand', $id, 'Updated brand "' . $submitted['name'] . '"');
        admin_after_write();

        flash('success', 'Brand "' . $submitted['name'] . '" saved.');
        redirect(admin_url('brands/edit.php?id=' . $id));
    }
}

$productCount = Database::count('products', '`brand_id` = :id', ['id' => $id]);

$isEdit       = true;
$pageTitle    = 'Edit Brand';
$pageSubtitle = $brand['name'] . ' · ' . $productCount . ' product' . ($productCount === 1 ? '' : 's');
$breadcrumbs  = [
    ['label' => 'Dashboard', 'url' => admin_url('dashboard.php')],
    ['label' => 'Brands',    'url' => admin_url('brands/')],
    ['label' => (string) $brand['name']],
];

$pageActions = '<a class="ad-btn" href="' . e(brand_url((string) $brand['slug'])) . '" target="_blank" rel="noopener">'
    . icon('external', 'w-4 h-4') . ' View on store</a>';

if (admin_can('brands.delete')) {
    // Products keep existing with brand_id set to NULL, so the confirmation
    // states the count and delete.php re-checks the acknowledgement.
    $confirm = $productCount > 0
        ? 'Delete "' . $brand['name'] . '"? ' . $productCount . ' product'
            . ($productCount === 1 ? '' : 's') . ' will be left with no brand.'
        : 'Delete "' . $brand['name'] . '"? This cannot be undone.';

    $pageActions .= '<form method="post" action="' . e(admin_url('brands/delete.php')) . '" class="ad-inline-form"'
        . admin_confirm_form_attrs($confirm, ['label' => 'Delete']) . '>'
        . csrf_field()
        . '<input type="hidden" name="id" value="' . $id . '">'
        . '<input type="hidden" name="confirm" value="1">'
        . '<button type="submit" class="ad-btn ad-btn--danger">' . icon('trash', 'w-4 h-4') . ' Delete</button>'
        . '</form>';
}

require ADMIN_PATH . '/includes/header.php';
?>

<?php if ($productCount > 0): ?>
    <div class="sik-alert sik-alert--info">
        <?= icon('info', 'w-5 h-5') ?>
        <div>
            <?= number_format($productCount) ?> product<?= $productCount === 1 ? '' : 's' ?>
            currently carr<?= $productCount === 1 ? 'ies' : 'y' ?> this brand.
            Deleting it leaves them in the catalogue with no brand assigned.
        </div>
    </div>
<?php endif; ?>

<?php
require __DIR__ . '/_form.php';
require ADMIN_PATH . '/includes/footer.php';
