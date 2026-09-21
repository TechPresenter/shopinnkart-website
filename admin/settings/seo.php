<?php
/**
 * ShopInnKart Admin - SEO.
 *
 * Two layers: the site-wide defaults in the "seo" settings group, and the
 * per-route overrides in seo_settings that seo_from_page() loads. A blank
 * field on a route row simply falls through to the default below it.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('settings.view');

require_once ADMIN_PATH . '/settings/_layout.php';

/** Route keys the storefront asks for by name - deleting one loses its overrides. */
const SEO_ROUTE_KEYS = [
    'home', 'shop', 'deals', 'new-arrivals', 'best-sellers', 'brands',
    'blog', 'contact', 'track-order', 'cart', 'checkout',
];

const SEO_ROBOTS_OPTIONS = [
    'index, follow'     => 'Index, follow (normal page)',
    'index, nofollow'   => 'Index, nofollow',
    'noindex, follow'   => 'Noindex, follow (private but crawlable links)',
    'noindex, nofollow' => 'Noindex, nofollow (hidden entirely)',
];

$spec = [
    'meta_title' => [
        'type' => 'text', 'label' => 'Default meta title', 'required' => true, 'max' => 255,
        'help' => 'Used when a page has nothing more specific. Aim for 50-60 characters.',
    ],
    'meta_description' => [
        'type' => 'textarea', 'label' => 'Default meta description', 'max' => 320, 'rows' => 3,
        'help' => 'Around 155 characters shows in full on Google.',
    ],
    'meta_keywords' => [
        'type' => 'textarea', 'label' => 'Default meta keywords', 'max' => 500, 'rows' => 2,
        'help' => 'Comma separated. Ignored by Google, still read by a few other engines.',
    ],
    'og_image' => [
        'type' => 'image', 'label' => 'Default social share image', 'folder' => 'seo',
        'help' => '1200x630 works everywhere. Used whenever a page has no image of its own.',
    ],
    'google_analytics_id' => [
        'type' => 'text', 'label' => 'Google Analytics ID', 'max' => 60,
        'placeholder' => 'G-XXXXXXXXXX',
    ],
    'meta_pixel_id' => [
        'type' => 'text', 'label' => 'Meta Pixel ID', 'max' => 60,
        'placeholder' => '1234567890',
    ],
    'google_site_verification' => [
        'type' => 'text', 'label' => 'Google site verification', 'max' => 190,
        'help' => 'The content value of the verification meta tag, not the whole tag.',
    ],
];

$action = (string) input('action', '');

// ---------------------------------------------------------------------------
//  Site-wide defaults
// ---------------------------------------------------------------------------
if (is_post() && ($action === '' || $action === 'settings')) {
    settings_handle_save('seo', 'seo', $spec, settings_group('seo'));
}

// ---------------------------------------------------------------------------
//  Per-page row
// ---------------------------------------------------------------------------
if (is_post() && $action === 'page_save') {
    admin_require_action('settings.edit');

    $id = input_int('id');
    $existing = $id > 0
        ? Database::fetch('SELECT * FROM `seo_settings` WHERE `id` = :id', ['id' => $id])
        : null;

    if ($id > 0 && $existing === null) {
        flash('error', 'That page row no longer exists.');
        redirect(settings_url('seo'));
    }

    // A route key is what the storefront looks up, so it is fixed once created.
    $pageKey = $existing !== null
        ? (string) $existing['page_key']
        : mb_strtolower(trim((string) input('page_key', '')));

    $label = trim((string) input('page_label', ''));
    $canonical = trim((string) input('canonical', ''));
    $robots = (string) input('robots', 'index, follow');

    $errors = [];

    if ($existing === null) {
        if (preg_match('/^[a-z0-9-]{2,100}$/', $pageKey) !== 1) {
            $errors['page_key'] = 'Page key may only contain lower-case letters, numbers and hyphens.';
        } elseif (Database::exists('seo_settings', '`page_key` = :k', ['k' => $pageKey])) {
            $errors['page_key'] = 'That page key is already in the list.';
        }
    }

    if ($label === '' || mb_strlen($label) > 150) {
        $errors['page_label'] = 'Label is required and must not exceed 150 characters.';
    }
    if (mb_strlen((string) input('meta_title', '')) > 255) {
        $errors['meta_title'] = 'Meta title must not exceed 255 characters.';
    }
    if ($canonical !== '' && !filter_var($canonical, FILTER_VALIDATE_URL)) {
        $errors['canonical'] = 'Canonical must be a full URL, or blank to use the page URL.';
    }
    if (!array_key_exists($robots, SEO_ROBOTS_OPTIONS)) {
        $errors['robots'] = 'Choose a valid robots directive.';
    }

    if ($errors !== []) {
        flash_errors($errors);
        flash_old($_POST);
        flash('error', 'Nothing was saved. Please fix the highlighted fields.');
        redirect(settings_url('seo') . '?page=' . ($id > 0 ? $id : 'new'));
    }

    $nullable = static fn (string $key): ?string => trim((string) input($key, '')) ?: null;

    $row = [
        'page_label'       => $label,
        'meta_title'       => $nullable('meta_title'),
        'meta_description' => $nullable('meta_description'),
        'meta_keywords'    => $nullable('meta_keywords'),
        'og_title'         => $nullable('og_title'),
        'og_description'   => $nullable('og_description'),
        // seo_normalise_robots() exists precisely to keep this inside the
        // varchar(60) — its own docblock records the SQLSTATE[22001] 500 this
        // form produced — but this save path never called it. The page-level
        // writer at includes/seo.php:261 always did.
        'robots'           => seo_normalise_robots($robots),
        'canonical'        => $canonical === '' ? null : $canonical,
    ];

    if ($existing !== null) {
        if (input_bool('remove_og_image')) {
            delete_upload($existing['og_image']);
            $row['og_image'] = null;
        } else {
            $row['og_image'] = admin_handle_image('og_image', 'seo', $existing['og_image']);
        }

        Database::update('seo_settings', $row, '`id` = :id', ['id' => $id]);
        log_activity('seo_page.updated', 'seo_settings', $id, 'Updated SEO for "' . $pageKey . '"');
        flash('success', 'SEO for ' . $label . ' saved.');
    } else {
        $row['page_key'] = $pageKey;
        $row['og_image'] = admin_handle_image('og_image', 'seo', null);

        $id = Database::insert('seo_settings', $row);
        log_activity('seo_page.created', 'seo_settings', $id, 'Added SEO row for "' . $pageKey . '"');
        flash('success', 'SEO for ' . $label . ' added.');
    }

    admin_after_write();
    redirect(settings_url('seo') . '?page=' . $id);
}

if (is_post() && $action === 'page_delete') {
    admin_require_action('settings.edit');

    $id = input_int('id');
    $row = Database::fetch('SELECT * FROM `seo_settings` WHERE `id` = :id', ['id' => $id]);

    if ($row === null) {
        flash('error', 'That page row no longer exists.');
        redirect(settings_url('seo'));
    }
    if (in_array((string) $row['page_key'], SEO_ROUTE_KEYS, true)) {
        flash('error', '"' . $row['page_key'] . '" is a storefront route. Clear its fields instead of deleting the row.');
        redirect(settings_url('seo'));
    }

    delete_upload($row['og_image']);
    Database::delete('seo_settings', '`id` = :id', ['id' => $id]);

    log_activity('seo_page.deleted', 'seo_settings', $id, 'Deleted SEO row for "' . $row['page_key'] . '"');
    admin_after_write();

    flash('success', 'SEO row for ' . $row['page_label'] . ' deleted.');
    redirect(settings_url('seo'));
}

// ---------------------------------------------------------------------------
//  Read
// ---------------------------------------------------------------------------
$stored = settings_group('seo');
$errors = errors_pull();

// The page editor's rejected input has to be read before settings_values()
// empties the old-input bag.
$oldPage = [];
if (settings_has_old()) {
    foreach (['page_key', 'page_label', 'meta_title', 'meta_description', 'meta_keywords',
              'og_title', 'og_description', 'robots', 'canonical'] as $field) {
        $oldPage[$field] = old($field, null);
    }
}

$values = settings_values($spec, $stored);

$pages = Database::fetchAll('SELECT * FROM `seo_settings` ORDER BY `page_key`');

$editing = null;
$pageParam = (string) ($_GET['page'] ?? '');
if ($pageParam === 'new') {
    $editing = [
        'id' => 0, 'page_key' => '', 'page_label' => '', 'meta_title' => '', 'meta_description' => '',
        'meta_keywords' => '', 'og_title' => '', 'og_description' => '', 'og_image' => null,
        'robots' => 'index, follow', 'canonical' => '',
    ];
} elseif ($pageParam !== '' && ctype_digit($pageParam)) {
    $editing = Database::fetch('SELECT * FROM `seo_settings` WHERE `id` = :id', ['id' => (int) $pageParam]);
    if ($editing === null) {
        flash('error', 'That page row no longer exists.');
        redirect(settings_url('seo'));
    }
}

/** Editor value: rejected input first, then the row. */
$pageValue = static function (string $key, string $default = '') use ($editing, $oldPage): string {
    return (string) ($oldPage[$key] ?? $editing[$key] ?? $default);
};

$canEdit = admin_can('settings.edit');

$pageTitle    = 'SEO Settings';
$pageSubtitle = 'Search and social defaults, plus an override for every storefront route.';
$breadcrumbs  = settings_breadcrumbs('seo');
$pageActions  = $canEdit
    ? '<a class="ad-btn" href="' . e(settings_url('seo') . '?page=new') . '">'
        . icon('plus', 'w-4 h-4') . ' Add Page Row</a>'
    : '';

require ADMIN_PATH . '/includes/header.php';
?>

<?= settings_tabs('seo') ?>

<form class="ad-form" method="post" enctype="multipart/form-data"
      action="<?= e(settings_url('seo')) ?>" data-guard-unsaved>
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="settings">

    <div class="ad-grid ad-grid--2">
        <div class="ad-card" style="margin:0">
            <div class="ad-card__head">
                <div>
                    <div class="ad-card__title">Site-wide defaults</div>
                    <div class="ad-card__sub">Applied to any page that does not override them.</div>
                </div>
            </div>
            <div class="ad-card__body">
                <?= settings_field('meta_title', $spec, $values, $errors) ?>
                <?= settings_field('meta_description', $spec, $values, $errors) ?>
                <?= settings_field('meta_keywords', $spec, $values, $errors) ?>
                <?= settings_field('og_image', $spec, $values, $errors) ?>
            </div>
        </div>

        <div class="ad-card" style="margin:0">
            <div class="ad-card__head">
                <div>
                    <div class="ad-card__title">Analytics &amp; verification</div>
                    <div class="ad-card__sub">Blank means the tag is not printed at all.</div>
                </div>
            </div>
            <div class="ad-card__body">
                <?= settings_field('google_analytics_id', $spec, $values, $errors) ?>
                <?= settings_field('meta_pixel_id', $spec, $values, $errors) ?>
                <?= settings_field('google_site_verification', $spec, $values, $errors) ?>

                <div class="sik-alert sik-alert--info" style="margin:0">
                    <?= icon('info', 'w-5 h-5') ?>
                    <div>
                        These identifiers are stored for the storefront head. Keep them empty on a staging
                        copy so test traffic never reaches your production analytics property.
                    </div>
                </div>
            </div>
            <?= settings_save_bar() ?>
        </div>
    </div>
</form>

<?php if ($editing !== null && $canEdit): ?>
    <?php
    $isNew = (int) ($editing['id'] ?? 0) === 0;
    $isRoute = in_array((string) $editing['page_key'], SEO_ROUTE_KEYS, true);
    ?>
    <form class="ad-form" method="post" enctype="multipart/form-data"
          action="<?= e(settings_url('seo')) ?>" data-guard-unsaved>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="page_save">
        <input type="hidden" name="id" value="<?= (int) $editing['id'] ?>">

        <div class="ad-card">
            <div class="ad-card__head">
                <div>
                    <div class="ad-card__title">
                        <?= $isNew ? 'Add a page row' : 'Editing: ' . e((string) $editing['page_label']) ?>
                    </div>
                    <div class="ad-card__sub">
                        <?php if ($isRoute): ?>
                            <span class="sik-status sik-status--green">Storefront route</span>
                            Loaded by <span class="ad-mono">seo_from_page('<?= e((string) $editing['page_key']) ?>')</span>.
                        <?php elseif ($isNew): ?>
                            The key must match the value the page passes to <span class="ad-mono">seo_from_page()</span>.
                        <?php else: ?>
                            <span class="sik-status sik-status--gray">Custom key</span>
                            Nothing reads this key yet.
                        <?php endif; ?>
                    </div>
                </div>
                <a class="ad-btn ad-btn--sm" href="<?= e(settings_url('seo')) ?>">Close</a>
            </div>

            <div class="ad-card__body">
                <div class="ad-row ad-row--2">
                    <div class="ad-field">
                        <label class="sik-label" for="seoKey">Page key <span class="req">*</span></label>
                        <?php if ($isNew): ?>
                            <input class="sik-input<?= isset($errors['page_key']) ? ' is-invalid' : '' ?>" type="text"
                                   id="seoKey" name="page_key" maxlength="100" required spellcheck="false"
                                   value="<?= e($pageValue('page_key')) ?>" placeholder="wishlist">
                        <?php else: ?>
                            <input class="sik-input ad-mono" type="text" id="seoKey"
                                   value="<?= e((string) $editing['page_key']) ?>" readonly disabled>
                        <?php endif; ?>
                        <?php if (isset($errors['page_key'])): ?>
                            <span class="sik-error"><?= e($errors['page_key']) ?></span>
                        <?php else: ?>
                            <span class="sik-help">
                                <?= $isNew ? 'Lower case, hyphens allowed.' : 'Frozen after creation.' ?>
                            </span>
                        <?php endif; ?>
                    </div>

                    <div class="ad-field">
                        <label class="sik-label" for="seoLabel">Label <span class="req">*</span></label>
                        <input class="sik-input<?= isset($errors['page_label']) ? ' is-invalid' : '' ?>" type="text"
                               id="seoLabel" name="page_label" maxlength="150" required
                               value="<?= e($pageValue('page_label')) ?>" placeholder="Wishlist">
                        <?php if (isset($errors['page_label'])): ?>
                            <span class="sik-error"><?= e($errors['page_label']) ?></span>
                        <?php else: ?>
                            <span class="sik-help">Admin-only name for this row.</span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="ad-field">
                    <label class="sik-label" for="seoTitle">Meta title</label>
                    <input class="sik-input<?= isset($errors['meta_title']) ? ' is-invalid' : '' ?>" type="text"
                           id="seoTitle" name="meta_title" maxlength="255"
                           value="<?= e($pageValue('meta_title')) ?>"
                           placeholder="Falls back to the site-wide default">
                    <?php if (isset($errors['meta_title'])): ?>
                        <span class="sik-error"><?= e($errors['meta_title']) ?></span>
                    <?php endif; ?>
                </div>

                <div class="ad-field">
                    <label class="sik-label" for="seoDescription">Meta description</label>
                    <textarea class="sik-textarea" id="seoDescription" name="meta_description" rows="3"
                              style="min-height:84px"
                              placeholder="Around 155 characters."><?= e($pageValue('meta_description')) ?></textarea>
                </div>

                <div class="ad-field">
                    <label class="sik-label" for="seoKeywords">Meta keywords</label>
                    <input class="sik-input" type="text" id="seoKeywords" name="meta_keywords" maxlength="500"
                           value="<?= e($pageValue('meta_keywords')) ?>" placeholder="Comma separated">
                </div>

                <fieldset class="ad-fieldset">
                    <legend>Social sharing</legend>
                    <div class="ad-row ad-row--2">
                        <div style="display:grid;gap:14px;align-content:start">
                            <div class="ad-field">
                                <label class="sik-label" for="seoOgTitle">OG title</label>
                                <input class="sik-input" type="text" id="seoOgTitle" name="og_title" maxlength="255"
                                       value="<?= e($pageValue('og_title')) ?>"
                                       placeholder="Falls back to the meta title">
                            </div>
                            <div class="ad-field">
                                <label class="sik-label" for="seoOgDescription">OG description</label>
                                <textarea class="sik-textarea" id="seoOgDescription" name="og_description" rows="3"
                                          style="min-height:84px"
                                          placeholder="Falls back to the meta description"><?= e($pageValue('og_description')) ?></textarea>
                            </div>
                        </div>

                        <div class="ad-field">
                            <span class="sik-label">OG image</span>
                            <div class="ad-drop" data-drop="#seoOgPreview">
                                <input type="file" name="og_image" accept="image/*">
                                <?= icon('upload', 'w-6 h-6') ?>
                                <div style="font-size:13px;margin-top:6px">Click or drop a 1200x630 image</div>
                            </div>
                            <div class="ad-preview" id="seoOgPreview">
                                <?php if (!empty($editing['og_image'])): ?>
                                    <div class="ad-preview__item">
                                        <img src="<?= e(img_url((string) $editing['og_image'])) ?>" alt="Current share image">
                                        <button type="button" class="ad-preview__remove" data-remove-image="#seoRemoveOg"
                                                aria-label="Remove share image">&times;</button>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <input type="hidden" name="remove_og_image" id="seoRemoveOg" value="0">
                        </div>
                    </div>
                </fieldset>

                <div class="ad-row ad-row--2">
                    <div class="ad-field">
                        <label class="sik-label" for="seoRobots">Robots</label>
                        <select class="sik-select<?= isset($errors['robots']) ? ' is-invalid' : '' ?>"
                                id="seoRobots" name="robots">
                            <?= admin_options(SEO_ROBOTS_OPTIONS, $pageValue('robots', 'index, follow')) ?>
                        </select>
                        <?php if (isset($errors['robots'])): ?>
                            <span class="sik-error"><?= e($errors['robots']) ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="ad-field">
                        <label class="sik-label" for="seoCanonical">Canonical URL</label>
                        <input class="sik-input<?= isset($errors['canonical']) ? ' is-invalid' : '' ?>" type="url"
                               id="seoCanonical" name="canonical" maxlength="255"
                               value="<?= e($pageValue('canonical')) ?>"
                               placeholder="<?= e(url()) ?>">
                        <?php if (isset($errors['canonical'])): ?>
                            <span class="sik-error"><?= e($errors['canonical']) ?></span>
                        <?php else: ?>
                            <span class="sik-help">Leave blank to let the page canonicalise to its own URL.</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="ad-card__foot">
                <a class="ad-btn" href="<?= e(settings_url('seo')) ?>">Cancel</a>
                <button type="submit" class="ad-btn ad-btn--primary">
                    <?= icon('check', 'w-4 h-4') ?> <?= $isNew ? 'Add Page Row' : 'Save Page SEO' ?>
                </button>
            </div>
        </div>
    </form>
<?php endif; ?>

<div class="ad-card">
    <div class="ad-card__head">
        <div>
            <div class="ad-card__title">Per-page SEO</div>
            <div class="ad-card__sub">Overrides loaded by name when the matching storefront page renders.</div>
        </div>
    </div>
    <div class="ad-card__body ad-card__body--flush">
        <?php if ($pages === []): ?>
            <?= admin_empty(
                'No page rows yet',
                'Every storefront route falls back to the site-wide defaults until it has a row here.',
                $canEdit ? 'Add Page Row' : null,
                $canEdit ? settings_url('seo') . '?page=new' : null,
                'search'
            ) ?>
        <?php else: ?>
            <div class="ad-tablewrap">
                <table class="ad-table">
                    <thead>
                        <tr>
                            <th>Page</th>
                            <th>Key</th>
                            <th>Meta title</th>
                            <th>Robots</th>
                            <th>Share image</th>
                            <th class="ad-table__actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pages as $row): ?>
                            <?php $isRoute = in_array((string) $row['page_key'], SEO_ROUTE_KEYS, true); ?>
                            <tr>
                                <td>
                                    <div class="ad-cellflex__name"><?= e((string) $row['page_label']) ?></div>
                                    <div class="ad-cellflex__meta">
                                        <?= $isRoute ? 'Storefront route' : 'Custom key' ?>
                                    </div>
                                </td>
                                <td class="ad-mono"><?= e((string) $row['page_key']) ?></td>
                                <td>
                                    <?php if ($row['meta_title'] === null || $row['meta_title'] === ''): ?>
                                        <span class="ad-muted">Uses the default</span>
                                    <?php else: ?>
                                        <?= e(str_limit((string) $row['meta_title'], 60)) ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (strpos((string) $row['robots'], 'noindex') !== false): ?>
                                        <span class="sik-status sik-status--amber"><?= e((string) $row['robots']) ?></span>
                                    <?php else: ?>
                                        <span class="ad-muted"><?= e((string) $row['robots']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= empty($row['og_image'])
                                        ? '<span class="ad-muted">Default</span>'
                                        : '<img class="ad-thumb" src="' . e(img_url((string) $row['og_image'])) . '" alt="" loading="lazy">' ?>
                                </td>
                                <td class="ad-table__actions">
                                    <?php if ($canEdit): ?>
                                        <a class="ad-btn ad-btn--icon" title="Edit" aria-label="Edit"
                                           href="<?= e(settings_url('seo') . '?page=' . (int) $row['id']) ?>">
                                            <?= icon('edit', 'w-4 h-4') ?>
                                        </a>
                                        <?php if (!$isRoute): ?>
                                            <?= admin_delete_form(
                                                settings_url('seo') . '?action=page_delete',
                                                (int) $row['id'],
                                                'Delete the SEO row for "' . $row['page_label'] . '"?'
                                            ) ?>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="ad-muted">&mdash;</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
    <div class="ad-card__foot">
        <span class="ad-muted" style="font-size:12.5px;margin-right:auto">
            Storefront routes cannot be deleted &mdash; clearing their fields is the same thing, without
            breaking the lookup.
        </span>
    </div>
</div>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
