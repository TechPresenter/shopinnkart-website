<?php
/**
 * ShopInnKart Admin - Schema (JSON-LD) manager.
 *
 * The storefront has always emitted structured data; what it never had was a
 * switchboard. This screen is that: one row per type, where it is allowed to
 * appear, what it looks like built from the store's own data, and what is
 * wrong with it if anything is.
 *
 * Two rules the UI is built around, because both are ways a store loses its
 * rich results rather than gains them:
 *
 *   - nothing is invented. A rating comes from approved reviews or is not
 *     published; a LocalBusiness needs a real address. Where the store has no
 *     data, the preview says so instead of showing a specimen.
 *   - custom JSON-LD is published markup in the store's name, so writing it
 *     needs the same settings.scripts permission as the custom JavaScript box.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('settings.view');

require_once ADMIN_PATH . '/includes/rbac.php';   // admin_can_edit_scripts()
require_once INCLUDES_PATH . '/content-functions.php';
require_once INCLUDES_PATH . '/schema.php';

$canEdit    = admin_can('settings.edit');
$canScripts = admin_can_edit_scripts();

$errors = [];
$editing = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_require_action('settings.edit');       // POST + CSRF + permission

    $action = (string) input('action', '');

    // -----------------------------------------------------------------
    //  The switchboard
    // -----------------------------------------------------------------
    if ($action === 'types') {
        $posted = $_POST['types'] ?? [];
        $saved  = 0;

        foreach (schema_catalogue() as $key => $type) {
            $row = is_array($posted) ? ($posted[$key] ?? []) : [];

            // An unchecked switch posts nothing, so absence is the answer.
            $enabled = !empty($row['enabled']) ? 1 : 0;

            // Only contexts this type supports survive. A hand-crafted POST
            // naming 'checkout' must not be able to put a Product block on the
            // checkout page.
            $contexts = [];
            foreach ((array) ($row['contexts'] ?? []) as $context) {
                $context = is_string($context) ? trim($context) : '';
                if ($context !== ''
                    && in_array($context, (array) $type['contexts'], true)
                    && !in_array($context, $contexts, true)) {
                    $contexts[] = $context;
                }
            }
            $stored = count($contexts) === count((array) $type['contexts']) ? '*' : implode(',', $contexts);

            if (Database::exists('seo_schema_types', '`type_key` = :k', ['k' => $key])) {
                Database::update(
                    'seo_schema_types',
                    ['enabled' => $enabled, 'contexts' => $stored],
                    '`type_key` = :k',
                    ['k' => $key]
                );
            } else {
                Database::insert('seo_schema_types', [
                    'type_key' => $key, 'enabled' => $enabled, 'contexts' => $stored,
                ]);
            }
            $saved++;
        }

        log_activity('seo.schema_types', 'settings', null, 'Updated schema types (' . $saved . ')');
        admin_after_write();
        flash('success', 'Schema types saved.');
        redirect(admin_url('seo/schema.php'));
    }

    // -----------------------------------------------------------------
    //  Custom JSON-LD
    // -----------------------------------------------------------------
    if (in_array($action, ['custom_save', 'custom_delete', 'custom_toggle'], true)) {
        // Publishing arbitrary markup in the store's name is the same class of
        // power as the custom script boxes, so it takes the same permission.
        if (!$canScripts) {
            security_event('seo.schema_custom_denied', 'high', ['action' => $action],
                (int) $admin['id'], 'admin');
            flash('error', 'Custom JSON-LD needs the settings.scripts permission (or Super Admin).');
            redirect(admin_url('seo/schema.php'));
        }

        $id = input_int('id');

        if ($action === 'custom_delete' && $id > 0) {
            Database::delete('seo_schema_custom', '`id` = :id', ['id' => $id]);
            security_event('seo.schema_custom_deleted', 'medium', ['id' => $id], (int) $admin['id'], 'admin');
            admin_after_write();
            flash('success', 'Custom block deleted.');
            redirect(admin_url('seo/schema.php'));
        }

        if ($action === 'custom_toggle' && $id > 0) {
            Database::query(
                "UPDATE `seo_schema_custom`
                    SET `status` = IF(`status` = 'active', 'inactive', 'active')
                  WHERE `id` = :id",
                ['id' => $id]
            );
            admin_after_write();
            flash('success', 'Custom block updated.');
            redirect(admin_url('seo/schema.php'));
        }

        if ($action === 'custom_save') {
            $name       = trim((string) input('name', ''));
            $json       = (string) input('json_ld', '');
            $entityType = trim((string) input('entity_type', ''));
            $entitySlug = strtolower(trim((string) input('entity_slug', '')));

            $contexts = [];
            foreach ((array) ($_POST['contexts'] ?? []) as $context) {
                $context = is_string($context) ? trim($context) : '';
                if (isset(SCHEMA_CONTEXTS[$context]) && !in_array($context, $contexts, true)) {
                    $contexts[] = $context;
                }
            }

            if ($name === '') {
                $errors['name'] = 'Give the block a name you will recognise later.';
            }

            $check = schema_custom_validate($json);
            if (!$check['ok']) {
                $errors['json_ld'] = $check['error'];
            }

            $entityTypes = ['', 'product', 'category', 'brand', 'blog_post', 'page', 'combo'];
            if (!in_array($entityType, $entityTypes, true)) {
                $errors['entity_type'] = 'Choose a record type from the list.';
            }
            if ($entityType !== '' && $entitySlug !== ''
                && preg_match('/^[a-z0-9\-_]{1,191}$/', $entitySlug) !== 1) {
                $errors['entity_slug'] = 'A slug is the part of the URL: letters, numbers and dashes.';
            }
            if ($entityType === '' && $contexts === []) {
                $errors['contexts'] = 'Choose where this block should appear, or target one record.';
            }

            if ($errors === []) {
                $row = [
                    'name'        => $name,
                    'json_ld'     => trim($json),
                    'contexts'    => implode(',', $contexts),
                    'entity_type' => $entityType !== '' ? $entityType : null,
                    'entity_slug' => $entityType !== '' && $entitySlug !== '' ? $entitySlug : null,
                    'status'      => input('status') === 'active' ? 'active' : 'inactive',
                    'notes'       => trim((string) input('notes', '')) ?: null,
                ];

                if ($id > 0) {
                    Database::update('seo_schema_custom', $row, '`id` = :id', ['id' => $id]);
                } else {
                    $row['created_by'] = (int) $admin['id'];
                    $id = Database::insert('seo_schema_custom', $row);
                }

                // Recorded like the other powerful settings: who published what
                // markup, when. The event log cannot be cleared from the UI.
                security_event('seo.schema_custom_saved', 'medium', [
                    'id'     => $id,
                    'name'   => $name,
                    'status' => $row['status'],
                    'bytes'  => strlen($row['json_ld']),
                ], (int) $admin['id'], 'admin');

                log_activity('seo.schema_custom', 'settings', $id, 'Saved custom JSON-LD "' . $name . '"');
                admin_after_write();
                flash('success', 'Custom block saved.');
                redirect(admin_url('seo/schema.php'));
            }

            // Fell through with errors: re-render the form as submitted.
            $editing = [
                'id' => $id, 'name' => $name, 'json_ld' => $json,
                'contexts' => implode(',', $contexts), 'entity_type' => $entityType,
                'entity_slug' => $entitySlug, 'status' => input('status') === 'active' ? 'active' : 'inactive',
                'notes' => (string) input('notes', ''),
            ];
        }
    }
}

if ($editing === null && ($editId = input_int('edit')) > 0) {
    $editing = Database::fetch('SELECT * FROM `seo_schema_custom` WHERE `id` = :id', ['id' => $editId]);
}

$registry = schema_registry();
$custom   = Database::fetchAll('SELECT * FROM `seo_schema_custom` ORDER BY `status`, `name`');

$onCount = 0;
foreach ($registry as $entry) {
    if ($entry['enabled']) {
        $onCount++;
    }
}
$activeCustom = 0;
foreach ($custom as $row) {
    if ($row['status'] === 'active') {
        $activeCustom++;
    }
}

// A preview per type. Each one is built from real rows, so a store with an
// empty catalogue gets "nothing to build one from" rather than a specimen.
$samples = [];
foreach (array_keys($registry) as $key) {
    $samples[$key] = schema_sample($key);
}

$pageTitle    = 'Schema manager';
$pageSubtitle = $onCount . ' of ' . count($registry) . ' types enabled';
$breadcrumbs  = [
    ['label' => 'Dashboard', 'url' => admin_url('dashboard.php')],
    ['label' => 'SEO', 'url' => admin_url('settings/seo.php')],
    ['label' => 'Schema'],
];
$pageActions = '<a class="ad-btn" href="' . e(admin_url('seo/sitemap.php')) . '">'
    . icon('globe', 'w-4 h-4') . ' Sitemap &amp; robots</a>'
    . '<a class="ad-btn" href="' . e(schema_rich_results_url(url())) . '" target="_blank" rel="noopener noreferrer">'
    . icon('search', 'w-4 h-4') . ' Rich Results test</a>';

require ADMIN_PATH . '/includes/header.php';
?>

<div class="ad-grid ad-grid--4" style="margin-bottom:20px">
    <?= admin_stat_card('Types enabled', (string) $onCount, 'check-circle', $onCount > 0 ? 'green' : 'amber',
        'of ' . count($registry) . ' available') ?>
    <?= admin_stat_card('Custom blocks', (string) $activeCustom, 'code', $activeCustom > 0 ? 'blue' : 'navy',
        'active of ' . count($custom)) ?>
    <?= admin_stat_card('Ratings published', $samples['rating']['schema'] !== null ? 'Yes' : 'No', 'star',
        $samples['rating']['schema'] !== null ? 'green' : 'navy',
        $samples['rating']['schema'] !== null ? 'from approved reviews' : 'no approved reviews yet') ?>
    <?= admin_stat_card('Local business', $samples['local_business']['schema'] !== null ? 'Yes' : 'No', 'store',
        $samples['local_business']['schema'] !== null ? 'green' : 'navy',
        $samples['local_business']['schema'] !== null ? 'address published' : 'needs address + phone') ?>
</div>

<form method="post" action="<?= e(admin_url('seo/schema.php')) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="types">

    <div class="ad-card" style="margin-bottom:20px">
        <div class="ad-card__head">
            <h2 class="ad-card__title">Structured data types</h2>
        </div>
        <div class="ad-card__body">
            <p class="sik-help" style="margin-top:0">
                A type that is switched on is still only emitted where it belongs and only when the store
                genuinely has the fields it requires. Nothing here invents data: switching a type on does
                not create a rating, an address or a price that is not already in the database.
            </p>
        </div>

        <div class="ad-tablewrap">
            <table class="ad-table">
                <thead>
                    <tr>
                        <th>Type</th>
                        <th>Appears on</th>
                        <th>Preview</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($registry as $key => $entry): ?>
                        <?php $sample = $samples[$key]; ?>
                        <tr>
                            <td style="min-width:220px">
                                <label class="ad-switch">
                                    <input type="checkbox" name="types[<?= e_attr($key) ?>][enabled]" value="1"
                                        <?= $entry['enabled'] ? ' checked' : '' ?>
                                        <?= $canEdit ? '' : ' disabled' ?>>
                                    <span class="ad-switch__track"></span>
                                    <span><strong><?= e((string) $entry['label']) ?></strong></span>
                                </label>
                                <div class="sik-help"><?= e((string) $entry['summary']) ?></div>
                            </td>
                            <td>
                                <?php foreach ((array) $entry['contexts'] as $context): ?>
                                    <label class="ad-check" style="display:inline-flex;gap:6px;margin:0 10px 6px 0">
                                        <input type="checkbox" name="types[<?= e_attr($key) ?>][contexts][]"
                                            value="<?= e_attr($context) ?>"
                                            <?= in_array($context, (array) $entry['assigned'], true) ? ' checked' : '' ?>
                                            <?= $canEdit ? '' : ' disabled' ?>>
                                        <span><?= e(SCHEMA_CONTEXTS[$context] ?? $context) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </td>
                            <td style="min-width:260px">
                                <?php if ($sample['schema'] === null): ?>
                                    <span class="ad-muted">Nothing to show &mdash; <?= e($sample['source']) ?>.</span>
                                <?php else: ?>
                                    <details>
                                        <summary>Built from <?= e(admin_trunc($sample['source'], 48)) ?></summary>
                                        <?php /* e_json(), the project's script-context encoder, even though this
                                                 is inside <pre>: the same admin-typed product name reaches both
                                                 places and one encoder means one set of rules. */ ?>
                                        <pre style="white-space:pre-wrap;word-break:break-word;font-size:12px;margin:8px 0 0;max-height:260px;overflow:auto"><?= e(json_encode($sample['schema'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '') ?></pre>
                                    </details>
                                    <?php if ($sample['problems'] !== []): ?>
                                        <ul class="sik-error" style="margin:6px 0 0;padding-left:18px">
                                            <?php foreach ($sample['problems'] as $problem): ?>
                                                <li><?= e($problem) ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($canEdit): ?>
            <div class="ad-card__foot">
                <span class="ad-muted" style="margin-right:auto;font-size:12.5px">
                    Test a live page with Google's Rich Results test before and after a change.
                </span>
                <button type="submit" class="ad-btn ad-btn--primary"><?= icon('check', 'w-4 h-4') ?> Save types</button>
            </div>
        <?php else: ?>
            <div class="ad-card__foot">
                <span class="ad-muted" style="font-size:12.5px">
                    You have read-only access to settings. Ask a Super Admin for <code>settings.edit</code>.
                </span>
            </div>
        <?php endif; ?>
    </div>
</form>

<div class="ad-card" style="margin-bottom:20px">
    <div class="ad-card__head"><h2 class="ad-card__title">Custom JSON-LD</h2></div>
    <div class="ad-card__body">
        <?php if (!$canScripts): ?>
            <p class="sik-help" style="margin-top:0">
                Custom JSON-LD is published markup in the store's name, so editing it needs the
                <code>settings.scripts</code> permission (or Super Admin) &mdash; the same permission as the
                storefront's custom JavaScript. You can see what is published; you cannot change it here.
            </p>
        <?php endif; ?>

        <?php if ($custom === []): ?>
            <p class="ad-muted" style="margin:0">No custom blocks. The types above cover the common cases.</p>
        <?php else: ?>
            <div class="ad-tablewrap">
                <table class="ad-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Where</th>
                            <th>Size</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($custom as $row): ?>
                            <?php
                            $check = schema_custom_validate((string) $row['json_ld']);
                            $where = trim((string) ($row['entity_type'] ?? '')) !== ''
                                ? ucfirst(str_replace('_', ' ', (string) $row['entity_type']))
                                    . ((string) ($row['entity_slug'] ?? '') !== '' ? ': ' . (string) $row['entity_slug'] : ' (all)')
                                : implode(', ', array_map(
                                    static fn (string $c): string => SCHEMA_CONTEXTS[$c] ?? $c,
                                    schema_context_list((string) $row['contexts'], array_keys(SCHEMA_CONTEXTS))
                                ));
                            ?>
                            <tr>
                                <td>
                                    <strong><?= e((string) $row['name']) ?></strong>
                                    <?php if (!$check['ok']): ?>
                                        <div class="sik-error"><?= e($check['error']) ?> Not published.</div>
                                    <?php endif; ?>
                                    <?php if (trim((string) ($row['notes'] ?? '')) !== ''): ?>
                                        <div class="sik-help"><?= e((string) $row['notes']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?= e($where !== '' ? $where : 'nowhere yet') ?></td>
                                <td class="ad-table__num"><?= e(number_format(strlen((string) $row['json_ld']) / 1024, 1)) ?> KB</td>
                                <td><?= admin_state_badge((string) $row['status']) ?></td>
                                <td class="ad-table__actions">
                                    <?php if ($canScripts): ?>
                                        <a class="ad-btn ad-btn--sm"
                                           href="<?= e(admin_url('seo/schema.php?edit=' . (int) $row['id'])) ?>">Edit</a>
                                        <form method="post" style="display:inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="custom_toggle">
                                            <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                            <button type="submit" class="ad-btn ad-btn--sm">
                                                <?= $row['status'] === 'active' ? 'Disable' : 'Enable' ?>
                                            </button>
                                        </form>
                                        <form method="post" style="display:inline"
                                              <?= admin_confirm_form_attrs(
                                                  'The block stops being written into the page source. This cannot be undone.',
                                                  ['title' => 'Delete this custom block?', 'label' => 'Delete block']
                                              ) ?>>
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="custom_delete">
                                            <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                            <button type="submit" class="ad-btn ad-btn--sm ad-btn--danger">Delete</button>
                                        </form>
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

<?php if ($canScripts): ?>
    <div class="ad-card" style="margin-bottom:20px">
        <div class="ad-card__head">
            <h2 class="ad-card__title"><?= $editing ? 'Edit custom block' : 'Add a custom block' ?></h2>
        </div>
        <form method="post" action="<?= e(admin_url('seo/schema.php')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="custom_save">
            <input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">

            <div class="ad-card__body">
                <div class="ad-field">
                    <label class="sik-label" for="cs_name">Name <span class="req">*</span></label>
                    <input class="sik-input<?= isset($errors['name']) ? ' is-invalid' : '' ?>" type="text"
                           id="cs_name" name="name" maxlength="120"
                           value="<?= e_attr((string) ($editing['name'] ?? '')) ?>"
                           placeholder="Diwali sale event">
                    <?php if (isset($errors['name'])): ?>
                        <span class="sik-error"><?= e($errors['name']) ?></span>
                    <?php endif; ?>
                </div>

                <div class="ad-field">
                    <label class="sik-label" for="cs_json">JSON-LD <span class="req">*</span></label>
                    <textarea class="sik-textarea<?= isset($errors['json_ld']) ? ' is-invalid' : '' ?>"
                              id="cs_json" name="json_ld" rows="10" spellcheck="false"
                              style="font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12.5px"
                              placeholder='{"@context":"https://schema.org","@type":"Event","name":"Diwali sale"}'><?= e((string) ($editing['json_ld'] ?? '')) ?></textarea>
                    <?php if (isset($errors['json_ld'])): ?>
                        <span class="sik-error"><?= e($errors['json_ld']) ?></span>
                    <?php else: ?>
                        <span class="sik-help">
                            One object, or a list of them. Each needs an <code>@type</code>. Up to
                            <?= (int) (SCHEMA_CUSTOM_MAX_BYTES / 1024) ?> KB. It is printed as data, never executed,
                            and a block that stops parsing is skipped rather than published broken.
                        </span>
                    <?php endif; ?>
                </div>

                <div class="ad-grid ad-grid--2" style="gap:20px">
                    <div class="ad-field">
                        <span class="sik-label">Appears on</span>
                        <?php $chosen = schema_context_list((string) ($editing['contexts'] ?? ''), array_keys(SCHEMA_CONTEXTS)); ?>
                        <?php foreach (SCHEMA_CONTEXTS as $context => $label): ?>
                            <label class="ad-check" style="display:inline-flex;gap:6px;margin:0 10px 6px 0">
                                <input type="checkbox" name="contexts[]" value="<?= e_attr($context) ?>"
                                    <?= in_array($context, $chosen, true) ? ' checked' : '' ?>>
                                <span><?= e($label) ?></span>
                            </label>
                        <?php endforeach; ?>
                        <?php if (isset($errors['contexts'])): ?>
                            <span class="sik-error"><?= e($errors['contexts']) ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="ad-field">
                        <label class="sik-label" for="cs_entity">Or one record only</label>
                        <select class="sik-select" id="cs_entity" name="entity_type">
                            <?= admin_options([
                                ''          => 'Use the page kinds on the left',
                                'product'   => 'Product',
                                'category'  => 'Category',
                                'brand'     => 'Brand',
                                'blog_post' => 'Blog post',
                                'page'      => 'CMS page',
                                'combo'     => 'Combo',
                            ], (string) ($editing['entity_type'] ?? '')) ?>
                        </select>
                        <input class="sik-input<?= isset($errors['entity_slug']) ? ' is-invalid' : '' ?>" type="text"
                               name="entity_slug" maxlength="191" style="margin-top:8px"
                               value="<?= e_attr((string) ($editing['entity_slug'] ?? '')) ?>"
                               placeholder="slug from the URL, or blank for every record of that type">
                        <?php if (isset($errors['entity_slug'])): ?>
                            <span class="sik-error"><?= e($errors['entity_slug']) ?></span>
                        <?php else: ?>
                            <span class="sik-help">
                                The slug is the last part of the page's address, e.g.
                                <code>/product/<strong>warm-white-fairy-lights</strong></code>.
                            </span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="ad-field">
                    <label class="sik-label" for="cs_notes">Note to yourself</label>
                    <input class="sik-input" type="text" id="cs_notes" name="notes" maxlength="255"
                           value="<?= e_attr((string) ($editing['notes'] ?? '')) ?>">
                </div>

                <label class="ad-switch">
                    <input type="checkbox" name="status" value="active"
                        <?= (string) ($editing['status'] ?? '') === 'active' ? ' checked' : '' ?>>
                    <span class="ad-switch__track"></span>
                    <span>Publish this block</span>
                </label>
            </div>

            <div class="ad-card__foot">
                <?php if ($editing): ?>
                    <a class="ad-btn" href="<?= e(admin_url('seo/schema.php')) ?>">Cancel</a>
                <?php endif; ?>
                <a class="ad-btn" href="<?= e(schema_validator_url()) ?>" target="_blank" rel="noopener noreferrer">
                    <?= icon('external', 'w-4 h-4') ?> Validator
                </a>
                <button type="submit" class="ad-btn ad-btn--primary"><?= icon('check', 'w-4 h-4') ?> Save block</button>
            </div>
        </form>
    </div>
<?php endif; ?>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
