<?php
/**
 * ShopInnKart Admin - Footer Builder.
 *
 * Columns render left to right by sort order; each one expands to reveal its
 * own settings and its link list. Everything is a plain form post — a footer
 * is edited a handful of times a year, so it is not worth an AJAX layer that
 * behaves differently from every other screen.
 *
 * The column type decides what the storefront actually draws, and the help
 * under the selector says so for each one, including the two that ignore the
 * link list entirely.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/_meta.php';

$admin = admin_require('homepage.edit');

/**
 * What each column_type renders in includes/footer.php. Lives in _meta.php so
 * this screen and column-save.php cannot drift apart again — and so the
 * "reads Content / reads links" answer that disables a field here is the same
 * one the saver uses to protect the value behind it.
 */
$columnTypes = footer_column_type_options();
$typeHelp    = array_map(
    static fn (array $meta): string => $meta['help'],
    footer_column_types()
);

/** type => 1|0 for the Content box, handed to the inline script as JSON. */
$typeReadsContent = array_map(
    static fn (array $meta): int => $meta['content'] ? 1 : 0,
    footer_column_types()
);

$columns = Database::fetchAll(
    'SELECT * FROM `footer_columns` ORDER BY `sort_order` ASC, `id` ASC'
);

$links = Database::fetchAll(
    'SELECT * FROM `footer_links` ORDER BY `column_id` ASC, `sort_order` ASC, `id` ASC'
);
$linksByColumn = [];
foreach ($links as $link) {
    $linksByColumn[(int) $link['column_id']][] = $link;
}

// ---------------------------------------------------------------------------
// The save endpoints bounce a failed submission back through the flash bag.
// __form says which of the many forms on this page it belongs to.
// ---------------------------------------------------------------------------
$errors = errors_pull();

// The bag is copied out and cleared up front: the closures below run during
// rendering, long after old_clear() would have emptied the session copy.
$oldBag = $_SESSION['_old'] ?? [];
old_clear();

$errorForm = $errors !== [] ? (string) ($oldBag['__form'] ?? '') : '';

$field = static function (string $form, string $formField, $default) use ($errorForm, $oldBag) {
    return $errorForm === $form && array_key_exists($formField, $oldBag) ? $oldBag[$formField] : $default;
};
$errorIn = static function (string $form, string $formField) use ($errorForm, $errors): string {
    return $errorForm === $form ? (string) ($errors[$formField] ?? '') : '';
};

$openColumnId = 0;
if (strpos($errorForm, 'column:') === 0) {
    $openColumnId = (int) substr($errorForm, 7);
} elseif (strpos($errorForm, 'link:') === 0) {
    $openColumnId = (int) ($oldBag['column_id'] ?? 0);
}
$addColumnOpen = $errorForm === 'column:0';

$activeColumns = count(array_filter($columns, static fn (array $c): bool => $c['status'] === 'active'));
$activeLinks   = count(array_filter($links, static fn (array $l): bool => $l['status'] === 'active'));

$pageTitle    = 'Footer Builder';
$pageSubtitle = count($columns) . ' column' . (count($columns) === 1 ? '' : 's') . ' · '
    . $activeColumns . ' live · ' . $activeLinks . ' active links';
$breadcrumbs  = [
    ['label' => 'Dashboard', 'url' => admin_url('dashboard.php')],
    ['label' => 'Footer Builder'],
];
$pageActions = '<a class="ad-btn" href="' . e(url()) . '#footer" target="_blank" rel="noopener">'
    . icon('external', 'w-4 h-4') . ' View footer</a>';

require ADMIN_PATH . '/includes/header.php';
?>
<style>
    /* Scoped to this screen: a column is a disclosure, so the page stays
       short until an admin actually opens one. */
    .ft-col { border: 1px solid var(--ad-border); border-radius: var(--ad-radius); background: var(--ad-surface); margin-bottom: 12px; }
    .ft-col__head {
        display: flex; align-items: center; gap: 12px; padding: 13px 16px;
        cursor: pointer; list-style: none; font-size: 13.5px;
    }
    .ft-col__head::-webkit-details-marker { display: none; }
    .ft-col__head:hover { background: var(--ad-bg); }
    .ft-col__title { font-weight: 700; color: var(--ad-text); }
    .ft-col__meta { font-size: 12px; color: var(--ad-muted); }
    .ft-col__caret { margin-left: auto; color: var(--ad-muted); transition: transform .16s ease; }
    .ft-col[open] .ft-col__caret { transform: rotate(180deg); }
    .ft-col__body { padding: 0 16px 16px; border-top: 1px solid var(--ad-border); }
</style>

<div class="ad-grid ad-grid--3" style="margin-bottom:18px">
    <?= admin_stat_card('Columns', (string) count($columns), 'list', 'primary', $activeColumns . ' active') ?>
    <?= admin_stat_card('Links', (string) count($links), 'external', 'blue', $activeLinks . ' active') ?>
    <?= admin_stat_card('Menus', 'Menu Builder', 'menu', 'navy',
        'Header and footer menus live there too', admin_url('menus/')) ?>
</div>

<!-- ============================== Add column ============================== -->
<details class="ad-card" <?= $addColumnOpen ? 'open' : '' ?>>
    <summary class="ad-card__head" style="cursor:pointer;list-style:none">
        <div>
            <div class="ad-card__title"><?= icon('plus', 'w-4 h-4') ?> Add a column</div>
            <div class="ad-card__sub">A new column is appended to the right of the footer.</div>
        </div>
    </summary>
    <form class="ad-form" method="post" action="<?= e(admin_url('footer/column-save.php')) ?>" data-guard-unsaved>
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="0">
        <div class="ad-card__body">
            <div class="ad-row ad-row--3">
                <div class="ad-field">
                    <label class="sik-label" for="newColTitle">Title <span class="req">*</span></label>
                    <input class="sik-input<?= $errorIn('column:0', 'title') !== '' ? ' is-invalid' : '' ?>" type="text"
                           id="newColTitle" name="title" maxlength="120" required
                           value="<?= e($field('column:0', 'title', '')) ?>" placeholder="Quick Links">
                    <?php if ($errorIn('column:0', 'title') !== ''): ?>
                        <span class="sik-error"><?= e($errorIn('column:0', 'title')) ?></span>
                    <?php endif; ?>
                </div>
                <div class="ad-field">
                    <label class="sik-label" for="newColType">Column type</label>
                    <select class="sik-select" id="newColType" name="column_type"
                            data-coltype data-content-target="newColContent">
                        <?= admin_options($columnTypes, $field('column:0', 'column_type', 'links')) ?>
                    </select>
                    <span class="sik-help" data-coltype-help><?= e(footer_column_type_help(
                        (string) $field('column:0', 'column_type', 'links')
                    )) ?></span>
                </div>
                <div class="ad-field">
                    <label class="sik-label" for="newColSort">Sort order</label>
                    <input class="sik-input<?= $errorIn('column:0', 'sort_order') !== '' ? ' is-invalid' : '' ?>"
                           type="number" id="newColSort" name="sort_order" min="0" max="9999" step="1"
                           value="<?= (int) $field('column:0', 'sort_order', count($columns) + 1) ?>">
                    <?php if ($errorIn('column:0', 'sort_order') !== ''): ?>
                        <span class="sik-error"><?= e($errorIn('column:0', 'sort_order')) ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <?php
            // Only About text and Custom HTML read this field. For every other
            // type it is disabled rather than merely ignored, because a box you
            // can type into and lose is worse than one you cannot type into.
            $newColType    = (string) $field('column:0', 'column_type', 'links');
            $newColReads   = footer_type_reads_content($newColType);
            ?>
            <div class="ad-field">
                <label class="sik-label" for="newColContent">Content</label>
                <textarea class="sik-textarea" id="newColContent" name="content" rows="3"
                          <?= $newColReads ? '' : 'disabled' ?>
                          placeholder="Read by the About text and Custom HTML types."><?= e($field('column:0', 'content', '')) ?></textarea>
                <span class="sik-help" data-content-note>
                    <?= $newColReads
                        ? 'Sanitised on save.'
                        : 'This column type does not draw the Content field, so the box is disabled.' ?>
                </span>
            </div>

            <div class="ad-field">
                <label class="sik-label" for="newColStatus">Status</label>
                <select class="sik-select" id="newColStatus" name="status" style="max-width:220px">
                    <?= admin_options(['active' => 'Active', 'inactive' => 'Inactive'], $field('column:0', 'status', 'active')) ?>
                </select>
            </div>
        </div>
        <div class="ad-card__foot">
            <button type="submit" class="ad-btn ad-btn--primary"><?= icon('plus', 'w-4 h-4') ?> Add Column</button>
        </div>
    </form>
</details>

<!-- ================================ Columns =============================== -->
<div class="ad-card">
    <div class="ad-card__head">
        <div>
            <div class="ad-card__title">Footer columns</div>
            <div class="ad-card__sub">Open a column to edit it and manage its links.</div>
        </div>
    </div>
    <div class="ad-card__body">
        <?php if ($columns === []): ?>
            <?= admin_empty(
                'The footer has no columns yet',
                'Add a link column — Quick Links and Customer Service are the usual first two.',
                null,
                null,
                'list'
            ) ?>
        <?php else: ?>
            <?php foreach ($columns as $column): ?>
                <?php
                $columnId   = (int) $column['id'];
                $formKey    = 'column:' . $columnId;
                $columnLinks = $linksByColumn[$columnId] ?? [];
                $columnType  = (string) $column['column_type'];
                $showsLinks   = footer_type_reads_links($columnType);
                $readsContent = footer_type_reads_content($columnType);
                ?>
                <details class="ft-col" <?= $openColumnId === $columnId ? 'open' : '' ?>>
                    <summary class="ft-col__head">
                        <span class="ad-stat__icon ad-stat__icon--navy" style="width:32px;height:32px;flex:none">
                            <?= icon('list', 'w-4 h-4') ?>
                        </span>
                        <span style="min-width:0">
                            <span class="ft-col__title"><?= e($column['title']) ?></span>
                            <span class="ft-col__meta" style="display:block">
                                <?= e($columnTypes[$column['column_type']] ?? $column['column_type']) ?>
                                &middot; sort <?= (int) $column['sort_order'] ?>
                                <?php if ($showsLinks): ?>
                                    &middot; <?= count($columnLinks) ?> link(s)
                                <?php endif; ?>
                            </span>
                        </span>
                        <?= admin_state_badge((string) $column['status']) ?>
                        <span class="ft-col__caret"><?= icon('chevron-down', 'w-4 h-4') ?></span>
                    </summary>

                    <div class="ft-col__body">
                        <!-- Column settings -->
                        <form class="ad-form" method="post" action="<?= e(admin_url('footer/column-save.php')) ?>"
                              data-guard-unsaved style="padding-top:16px">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= $columnId ?>">

                            <div class="ad-row ad-row--3">
                                <div class="ad-field">
                                    <label class="sik-label" for="colTitle<?= $columnId ?>">Title <span class="req">*</span></label>
                                    <input class="sik-input<?= $errorIn($formKey, 'title') !== '' ? ' is-invalid' : '' ?>"
                                           type="text" id="colTitle<?= $columnId ?>" name="title" maxlength="120" required
                                           value="<?= e($field($formKey, 'title', $column['title'])) ?>">
                                    <?php if ($errorIn($formKey, 'title') !== ''): ?>
                                        <span class="sik-error"><?= e($errorIn($formKey, 'title')) ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="ad-field">
                                    <label class="sik-label" for="colType<?= $columnId ?>">Column type</label>
                                    <select class="sik-select" id="colType<?= $columnId ?>" name="column_type"
                                            data-coltype data-content-target="colContent<?= $columnId ?>">
                                        <?= admin_options($columnTypes, $field($formKey, 'column_type', $column['column_type'])) ?>
                                    </select>
                                    <span class="sik-help" data-coltype-help><?= e(footer_column_type_help($columnType)) ?></span>
                                </div>
                                <div class="ad-field">
                                    <label class="sik-label" for="colSort<?= $columnId ?>">Sort order</label>
                                    <input class="sik-input<?= $errorIn($formKey, 'sort_order') !== '' ? ' is-invalid' : '' ?>"
                                           type="number" id="colSort<?= $columnId ?>" name="sort_order" min="0" max="9999" step="1"
                                           value="<?= (int) $field($formKey, 'sort_order', $column['sort_order']) ?>">
                                    <?php if ($errorIn($formKey, 'sort_order') !== ''): ?>
                                        <span class="sik-error"><?= e($errorIn($formKey, 'sort_order')) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="ad-field">
                                <label class="sik-label" for="colContent<?= $columnId ?>">Content</label>
                                <textarea class="sik-textarea" id="colContent<?= $columnId ?>" name="content" rows="3"
                                          <?= $readsContent ? '' : 'disabled' ?>
                                          ><?= e($field($formKey, 'content', $column['content'] ?? '')) ?></textarea>
                                <span class="sik-help" data-content-note>
                                    <?= $readsContent
                                        ? 'Read by the About text and Custom HTML types; sanitised on save.'
                                        : 'This column type does not draw the Content field, so the box is disabled. '
                                          . 'Anything already stored is kept, not wiped, and comes back if you switch the type again.' ?>
                                </span>
                            </div>

                            <div class="ad-row ad-row--2" style="align-items:end">
                                <div class="ad-field">
                                    <label class="sik-label" for="colStatus<?= $columnId ?>">Status</label>
                                    <select class="sik-select" id="colStatus<?= $columnId ?>" name="status">
                                        <?= admin_options(['active' => 'Active', 'inactive' => 'Inactive'],
                                            $field($formKey, 'status', $column['status'])) ?>
                                    </select>
                                </div>
                                <div class="ad-field" style="display:flex;gap:8px;justify-content:flex-end">
                                    <button type="submit" class="ad-btn ad-btn--primary">
                                        <?= icon('check', 'w-4 h-4') ?> Save Column
                                    </button>
                                </div>
                            </div>
                        </form>

                        <?= admin_delete_form(
                            admin_url('footer/column-delete.php'),
                            $columnId,
                            count($columnLinks) > 0
                                ? 'Delete "' . $column['title'] . '" and its ' . count($columnLinks) . ' link(s)? This cannot be undone.'
                                : 'Delete "' . $column['title'] . '"? This cannot be undone.',
                            'Delete column'
                        ) ?>

                        <!-- Links -->
                        <?php if (!$showsLinks): ?>
                            <p class="ad-muted" style="font-size:12.5px;margin-top:16px">
                                <?= e(footer_column_type_help($columnType)) ?>
                                Links added here stay stored but are not drawn while the column is set to this type.
                            </p>
                        <?php endif; ?>

                        <div style="margin-top:18px">
                            <div class="ad-card__title" style="font-size:13px;margin-bottom:8px">Links</div>

                            <?php if ($columnLinks === []): ?>
                                <p class="ad-muted" style="font-size:12.5px">No links in this column yet.</p>
                            <?php else: ?>
                                <div class="ad-tablewrap">
                                    <table class="ad-table">
                                        <thead>
                                            <tr>
                                                <th>Label</th>
                                                <th>URL</th>
                                                <th style="width:90px">Sort</th>
                                                <th style="width:110px">New tab</th>
                                                <th style="width:130px">Status</th>
                                                <th class="ad-table__actions">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($columnLinks as $link): ?>
                                                <?php
                                                $linkId  = (int) $link['id'];
                                                $linkKey = 'link:' . $linkId;
                                                ?>
                                                <tr>
                                                    <td colspan="6" style="padding:0">
                                                        <form class="ad-form" method="post"
                                                              action="<?= e(admin_url('footer/link-save.php')) ?>"
                                                              style="display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end;padding:10px 12px">
                                                            <?= csrf_field() ?>
                                                            <input type="hidden" name="id" value="<?= $linkId ?>">
                                                            <input type="hidden" name="column_id" value="<?= $columnId ?>">

                                                            <div class="ad-field" style="flex:1 1 180px;margin:0">
                                                                <label class="sik-sr" for="lnkLabel<?= $linkId ?>">Label</label>
                                                                <input class="sik-input<?= $errorIn($linkKey, 'label') !== '' ? ' is-invalid' : '' ?>"
                                                                       type="text" id="lnkLabel<?= $linkId ?>" name="label"
                                                                       maxlength="120" required
                                                                       value="<?= e($field($linkKey, 'label', $link['label'])) ?>">
                                                                <?php if ($errorIn($linkKey, 'label') !== ''): ?>
                                                                    <span class="sik-error"><?= e($errorIn($linkKey, 'label')) ?></span>
                                                                <?php endif; ?>
                                                            </div>

                                                            <div class="ad-field" style="flex:1 1 220px;margin:0">
                                                                <label class="sik-sr" for="lnkUrl<?= $linkId ?>">URL</label>
                                                                <input class="sik-input ad-mono<?= $errorIn($linkKey, 'url') !== '' ? ' is-invalid' : '' ?>"
                                                                       type="text" id="lnkUrl<?= $linkId ?>" name="url"
                                                                       maxlength="255" required
                                                                       value="<?= e($field($linkKey, 'url', $link['url'])) ?>">
                                                                <?php if ($errorIn($linkKey, 'url') !== ''): ?>
                                                                    <span class="sik-error"><?= e($errorIn($linkKey, 'url')) ?></span>
                                                                <?php endif; ?>
                                                            </div>

                                                            <div class="ad-field" style="flex:0 0 84px;margin:0">
                                                                <label class="sik-sr" for="lnkSort<?= $linkId ?>">Sort order</label>
                                                                <input class="sik-input" type="number" id="lnkSort<?= $linkId ?>"
                                                                       name="sort_order" min="0" max="9999" step="1"
                                                                       value="<?= (int) $field($linkKey, 'sort_order', $link['sort_order']) ?>">
                                                            </div>

                                                            <label class="ad-switch" style="flex:0 0 auto;padding-bottom:9px">
                                                                <input type="checkbox" name="open_new_tab" value="1"
                                                                       <?= (int) $link['open_new_tab'] === 1 ? 'checked' : '' ?>>
                                                                <span class="ad-switch__track"></span>
                                                                <span class="sik-sr">Open <?= e($link['label']) ?> in a new tab</span>
                                                            </label>

                                                            <div class="ad-field" style="flex:0 0 128px;margin:0">
                                                                <label class="sik-sr" for="lnkStatus<?= $linkId ?>">Status</label>
                                                                <select class="sik-select" id="lnkStatus<?= $linkId ?>" name="status">
                                                                    <?= admin_options(['active' => 'Active', 'inactive' => 'Inactive'],
                                                                        $field($linkKey, 'status', $link['status'])) ?>
                                                                </select>
                                                            </div>

                                                            <button type="submit" class="ad-btn ad-btn--icon ad-btn--success"
                                                                    title="Save link" aria-label="Save <?= e_attr($link['label']) ?>">
                                                                <?= icon('check', 'w-4 h-4') ?>
                                                            </button>
                                                        </form>
                                                    </td>
                                                </tr>
                                                <tr>
                                                    <td colspan="6" style="padding:0 12px 10px;border-top:0">
                                                        <?= admin_delete_form(
                                                            admin_url('footer/link-delete.php'),
                                                            $linkId,
                                                            'Delete the "' . $link['label'] . '" link?',
                                                            'Delete link'
                                                        ) ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>

                            <!-- Add a link -->
                            <?php $newLinkKey = 'link:0:' . $columnId; ?>
                            <form class="ad-form" method="post" action="<?= e(admin_url('footer/link-save.php')) ?>"
                                  style="display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end;margin-top:10px">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="0">
                                <input type="hidden" name="column_id" value="<?= $columnId ?>">

                                <div class="ad-field" style="flex:1 1 180px;margin:0">
                                    <label class="sik-label" for="newLnkLabel<?= $columnId ?>">New link label</label>
                                    <input class="sik-input<?= $errorIn($newLinkKey, 'label') !== '' ? ' is-invalid' : '' ?>"
                                           type="text" id="newLnkLabel<?= $columnId ?>" name="label" maxlength="120"
                                           value="<?= e($field($newLinkKey, 'label', '')) ?>" placeholder="Track Order">
                                    <?php if ($errorIn($newLinkKey, 'label') !== ''): ?>
                                        <span class="sik-error"><?= e($errorIn($newLinkKey, 'label')) ?></span>
                                    <?php endif; ?>
                                </div>

                                <div class="ad-field" style="flex:1 1 220px;margin:0">
                                    <label class="sik-label" for="newLnkUrl<?= $columnId ?>">URL</label>
                                    <input class="sik-input ad-mono<?= $errorIn($newLinkKey, 'url') !== '' ? ' is-invalid' : '' ?>"
                                           type="text" id="newLnkUrl<?= $columnId ?>" name="url" maxlength="255"
                                           value="<?= e($field($newLinkKey, 'url', '')) ?>" placeholder="track-order.php">
                                    <?php if ($errorIn($newLinkKey, 'url') !== ''): ?>
                                        <span class="sik-error"><?= e($errorIn($newLinkKey, 'url')) ?></span>
                                    <?php else: ?>
                                        <span class="sik-help">Relative to the store URL, or a full https:// address.</span>
                                    <?php endif; ?>
                                </div>

                                <div class="ad-field" style="flex:0 0 84px;margin:0">
                                    <label class="sik-label" for="newLnkSort<?= $columnId ?>">Sort</label>
                                    <input class="sik-input" type="number" id="newLnkSort<?= $columnId ?>"
                                           name="sort_order" min="0" max="9999" step="1"
                                           value="<?= (int) $field($newLinkKey, 'sort_order', count($columnLinks) + 1) ?>">
                                </div>

                                <label class="ad-switch" style="flex:0 0 auto;padding-bottom:9px">
                                    <input type="checkbox" name="open_new_tab" value="1">
                                    <span class="ad-switch__track"></span>
                                    <span style="font-size:12.5px">New tab</span>
                                </label>

                                <button type="submit" class="ad-btn ad-btn--primary">
                                    <?= icon('plus', 'w-4 h-4') ?> Add Link
                                </button>
                            </form>
                        </div>
                    </div>
                </details>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php
// The type selector rewrites its own help line and enables or disables the
// Content box beside it, so the form tells the truth before the save does
// rather than only after the page reloads. Both maps come straight from
// _meta.php, which is the same source includes/footer.php is written against.
?>
<script>
(function () {
    'use strict';

    var HELP  = <?= e_json($typeHelp) ?>;
    var READS = <?= e_json($typeReadsContent) ?>;

    var selects = document.querySelectorAll('select[data-coltype]');

    function sync(select) {
        var type = select.value;

        var help = select.parentNode.querySelector('[data-coltype-help]');
        if (help) { help.textContent = HELP[type] || ''; }

        var box = document.getElementById(select.getAttribute('data-content-target') || '');
        if (!box) { return; }

        var reads = READS[type] === 1;
        box.disabled = !reads;
        box.closest('.ad-field').style.opacity = reads ? '' : '.6';

        var note = box.parentNode.querySelector('[data-content-note]');
        if (note) {
            note.textContent = reads
                ? 'Read by the About text and Custom HTML types; sanitised on save.'
                : 'This column type does not draw the Content field, so the box is disabled. '
                  + 'Anything already stored is kept, not wiped, and comes back if you switch the type again.';
        }
    }

    for (var i = 0; i < selects.length; i++) {
        selects[i].addEventListener('change', function () { sync(this); });
    }
})();
</script>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
