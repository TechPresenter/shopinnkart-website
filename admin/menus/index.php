<?php
/**
 * ShopInnKart Admin - Menu Builder.
 *
 * One screen per location. The list is a real tree — indentation is the only
 * honest way to show a menu that nests — and the editor opens on the same page
 * so an admin never loses sight of where the item sits.
 *
 * Reordering is per sibling group: an item only ever swaps with the item above
 * or below it under the same parent, which is what "move up" means in a menu.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('homepage.edit');

require_once __DIR__ . '/_meta.php';

$locations = menu_locations();
$location  = admin_filter('location', array_keys($locations), 'main');

$menu = Database::fetch(
    'SELECT * FROM `menus` WHERE `location` = :loc LIMIT 1',
    ['loc' => $location]
);

$itemCounts = Database::fetchPairs(
    'SELECT m.`location`, COUNT(i.`id`)
     FROM `menus` m LEFT JOIN `menu_items` i ON i.`menu_id` = m.`id`
     GROUP BY m.`location`'
);

$childrenOf = $menu !== null ? menu_children_map((int) $menu['id']) : [];

$flatten = static function (int $parentId, int $depth) use (&$flatten, $childrenOf): array {
    $out = [];
    $siblings = $childrenOf[$parentId] ?? [];
    $last = count($siblings) - 1;

    foreach ($siblings as $index => $row) {
        $out[] = ['row' => $row, 'depth' => $depth, 'first' => $index === 0, 'last' => $index === $last];
        foreach ($flatten((int) $row['id'], $depth + 1) as $descendant) {
            $out[] = $descendant;
        }
    }
    return $out;
};
$tree = $flatten(0, 0);

// ---------------------------------------------------------------------------
//  Editor state. item-save.php bounces failed input back through the flash bag.
// ---------------------------------------------------------------------------
$errors    = errors_pull();
$itemParam = trim((string) input('item', ''));

// Prefixed on purpose: header.php and sidebar.php render inside this scope,
// so a plain name like $item is not safe to hold across the layout include.
$formItem  = null;

if ($itemParam === 'new') {
    $parentId = input_int('parent');
    $formItem = [
        'id'                => 0,
        'label'             => '',
        'link_type'         => 'custom',
        'reference_id'      => null,
        'url'               => '',
        'icon'              => '',
        'icon_visibility'   => 'all',
        'badge'             => '',
        'badge_color'       => '',
        'badge_style'       => 'solid',
        'badge_text_color'  => '',
        'badge_position'    => 'after',
        'badge_animation'   => 'none',
        'is_mega'           => 0,
        'mega_columns'      => 4,
        'mega_image'        => null,
        'mega_image_url'    => '',
        'mega_products'     => '',
        'mega_promo'        => 0,
        'open_new_tab'      => 0,
        'device_visibility' => 'all',
        'auth_visibility'   => 'all',
        'parent_id'         => $parentId > 0 ? $parentId : null,
        'sort_order'        => 0,
        'status'            => 'active',
    ];
} elseif ((int) $itemParam > 0 && $menu !== null) {
    $formItem = Database::fetch(
        'SELECT * FROM `menu_items` WHERE `id` = :id AND `menu_id` = :menu',
        ['id' => (int) $itemParam, 'menu' => (int) $menu['id']]
    );
    if ($formItem === null) {
        flash('error', 'That menu item no longer exists in this menu.');
        redirect(admin_url('menus/?location=' . urlencode($location)));
    }
}

// Repopulate from the last failed submission.
if ($formItem !== null && $errors !== []) {
    foreach (array_keys($formItem) as $field) {
        $remembered = old($field, null);
        if ($remembered !== null && $field !== 'id') {
            $formItem[$field] = $remembered;
        }
    }
    // Checkboxes post nothing when they are off, so old() cannot tell "unticked"
    // from "not in the form" — each one is read as its own presence test.
    $formItem['is_mega']      = old('is_mega', '') !== '' ? 1 : 0;
    $formItem['open_new_tab'] = old('open_new_tab', '') !== '' ? 1 : 0;
    $formItem['mega_promo']   = old('mega_promo', '') !== '' ? 1 : 0;

    // The icon radio posts __upload__ for "keep the custom icon this item has",
    // which is a form token and never a column value. The hidden icon_current
    // field carries the real one back, so the picker re-opens on the upload
    // instead of on nothing.
    if ((string) $formItem['icon'] === '__upload__') {
        $formItem['icon'] = (string) old('icon_current', '');
    }

    // The route picker posts under its own name but lands in the url column.
    if ((string) $formItem['link_type'] === 'route') {
        $formItem['url'] = (string) old('route_url', $formItem['url']);
    }
}
old_clear();

// Parent options: every item except this one and anything beneath it.
$parentOptions = [];
if ($formItem !== null) {
    $excluded = (int) $formItem['id'] > 0
        ? array_merge([(int) $formItem['id']], menu_descendant_ids($childrenOf, (int) $formItem['id']))
        : [];

    foreach ($tree as $entry) {
        $rowId = (int) $entry['row']['id'];
        if (in_array($rowId, $excluded, true)) {
            continue;
        }
        $parentOptions[$rowId] = str_repeat('— ', $entry['depth']) . (string) $entry['row']['label'];
    }
}

// Hand-picked mega products, in the stored order.
$megaProducts = [];
if ($formItem !== null && trim((string) $formItem['mega_products']) !== '') {
    $ids = array_values(array_filter(array_map('intval', explode(',', (string) $formItem['mega_products']))));
    if ($ids !== []) {
        [$placeholders, $params] = Database::inPlaceholders($ids, 'p');
        $rows = Database::fetchAll(
            'SELECT `id`, `name`, `sku`, `main_image` FROM `products` WHERE `id` IN (' . $placeholders . ')',
            $params
        );
        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row['id']] = $row;
        }
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $megaProducts[] = $byId[$id];
            }
        }
    }
}

$references = menu_reference_lists();
$isEdit     = $formItem !== null && (int) $formItem['id'] > 0;

$pageTitle    = 'Menu Builder';
$pageSubtitle = $locations[$location]['label'] . ' · ' . count($tree) . ' item' . (count($tree) === 1 ? '' : 's');
$breadcrumbs  = [
    ['label' => 'Dashboard', 'url' => admin_url('dashboard.php')],
    ['label' => 'Menu Builder'],
];
$pageActions = '';
if ($menu !== null) {
    $pageActions = '<a class="ad-btn ad-btn--primary" href="'
        . e(admin_url('menus/?location=' . urlencode($location) . '&item=new')) . '#menuForm">'
        . icon('plus', 'w-4 h-4') . ' Add Item</a>';
}

require ADMIN_PATH . '/includes/header.php';
?>
<style>
    /* Scoped to this screen — same picker the category form uses. */
    .ad-iconpick {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(84px, 1fr));
        gap: 8px; max-height: 330px; overflow-y: auto; padding: 10px;
        border: 1px solid var(--ad-border); border-radius: 10px; background: var(--ad-bg);
    }
    .ad-iconpick input { position: absolute; opacity: 0; width: 0; height: 0; }
    .ad-iconpick__box {
        display: flex; flex-direction: column; align-items: center; gap: 5px;
        padding: 9px 4px; border: 1px solid var(--ad-border); border-radius: 9px;
        background: #fff; cursor: pointer; font-size: 11.5px; color: var(--ad-muted); text-align: center;
    }
    /* Wraps rather than clipping: the icon key is the only thing identifying a
       tile, and an 84px tile cuts the longest keys with no way to read them.
       Same fix as homepage/trust-features.php and categories/_form.php. */
    .ad-iconpick__box em { font-style: normal; max-width: 100%; line-height: 1.3; overflow-wrap: anywhere; }
    .ad-iconpick input:checked + .ad-iconpick__box {
        border-color: var(--ad-primary); color: var(--ad-primary); box-shadow: 0 0 0 2px rgba(244, 81, 30, .16);
    }
    /* A group heading spans the whole grid so the 90 glyphs read as five short
       lists instead of one wall. It is a real heading, not a separator: the
       search below hides a heading whose group has been filtered away. */
    .mn-iconpick__head {
        grid-column: 1 / -1;
        margin: 6px 2px 0;
        font-size: 10.5px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase;
        color: var(--ad-muted);
    }
    .mn-iconpick__head:first-child { margin-top: 0; }
    .mn-iconpick__cell[hidden], .mn-iconpick__head[hidden] { display: none; }
    .mn-iconpick__empty { grid-column: 1 / -1; margin: 10px 2px; color: var(--ad-muted); font-size: 13px; }
    .mn-iconbar { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }
    .mn-iconbar .sik-input { flex: 1; min-width: 0; }
    .mn-iconbar__count { font-size: 12px; white-space: nowrap; }

    /* Badge presets. Each button holds the storefront's real chip, so the
       button is a swatch of the thing it produces. */
    .mn-presets { display: flex; flex-wrap: wrap; gap: 8px; }
    .mn-preset {
        display: inline-flex; align-items: center; gap: 6px;
        padding: 7px 10px; border: 1px solid var(--ad-border); border-radius: 9px;
        background: #fff; cursor: pointer; font: inherit; font-size: 12px; color: var(--ad-muted);
    }
    .mn-preset:hover { border-color: var(--ad-primary); }
    .mn-preset.is-active { border-color: var(--ad-primary); box-shadow: 0 0 0 2px rgba(244, 81, 30, .16); }
    .mn-preset--clear { color: var(--ad-muted); }

    /* Preview. The two wells give the storefront components the surfaces they
       are drawn against — the bar sits on white, a panel row on the card. */
    .mn-preview__cap {
        margin: 0 0 6px; font-size: 10.5px; font-weight: 700; letter-spacing: .08em;
        text-transform: uppercase; color: var(--ad-muted);
    }
    .mn-preview__bar, .mn-preview__panel {
        padding: 10px; margin-bottom: 14px;
        border: 1px solid var(--ad-border); border-radius: 10px; background: #fff;
    }
    .mn-preview__panel { margin-bottom: 0; }
    /* .sik-nav__link and .sik-mmenu__link are <a>/<button> on the store and a
       <span> here; they need the flex row they would have had. Nothing else
       about either is restated — the padding, colours and chip placement are
       app.css doing its own job. */
    .mn-preview [data-preview-nav], .mn-preview [data-preview-drawer] { display: flex; }
    .mn-preview [data-preview-glyph]:empty { display: none; }

    .mn-arrows { display: grid; gap: 2px; }
    .mn-arrows form { margin: 0; }

    /* The link-type switch: `hidden` was doing nothing on this screen.
       [hidden]{display:none} is declared near the top of app.css, and
       admin.css's `.ad-field { display: block }` is the same specificity but
       loads after it — so every target block (Category, Store page, URL) was
       drawn at once, all three marked required, whatever the link type said.
       Re-stated here rather than in admin.css, which is shared: this is the one
       screen that hides an .ad-field. */
    .ad-field[hidden] { display: none; }
</style>

<div class="ad-card">
    <div class="ad-tabs">
        <?php foreach ($locations as $key => $meta): ?>
            <a class="ad-tab <?= $location === $key ? 'is-active' : '' ?>"
               href="<?= e(admin_url('menus/?location=' . urlencode($key))) ?>">
                <?= icon($meta['icon'], 'w-4 h-4') ?> <?= e($meta['label']) ?>
                <span class="ad-tab__count"><?= (int) ($itemCounts[$key] ?? 0) ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <?php if ($menu === null): ?>
        <div class="ad-card__body">
            <?= admin_empty(
                'This menu does not exist yet',
                'The ' . $locations[$location]['label'] . ' menu has no row in the database. Create it and you can start adding items.',
                null,
                null,
                'menu'
            ) ?>
            <form method="post" action="<?= e(admin_url('menus/item-save.php')) ?>" style="text-align:center">
                <?= csrf_field() ?>
                <input type="hidden" name="intent" value="menu">
                <input type="hidden" name="location" value="<?= e_attr($location) ?>">
                <input type="hidden" name="name" value="<?= e_attr(menu_default_name($location)) ?>">
                <input type="hidden" name="status" value="active">
                <button type="submit" class="ad-btn ad-btn--primary">
                    <?= icon('plus', 'w-4 h-4') ?> Create the <?= e($locations[$location]['label']) ?> menu
                </button>
            </form>
        </div>
    <?php else: ?>

        <form class="ad-filters" method="post" action="<?= e(admin_url('menus/item-save.php')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="intent" value="menu">
            <input type="hidden" name="location" value="<?= e_attr($location) ?>">
            <div class="ad-field" style="flex:1;min-width:200px">
                <label class="sik-sr" for="menuName">Menu name</label>
                <input class="sik-input" type="text" id="menuName" name="name" maxlength="100" required
                       value="<?= e($menu['name']) ?>">
            </div>
            <select class="sik-select" name="status" style="max-width:180px" aria-label="Menu status">
                <?= admin_options(['active' => 'Menu active', 'inactive' => 'Menu inactive'], $menu['status']) ?>
            </select>
            <button type="submit" class="ad-btn ad-btn--sm"><?= icon('check', 'w-4 h-4') ?> Save menu</button>
            <span class="ad-muted" style="font-size:var(--ad-text-xs)">
                <?= e($locations[$location]['sub']) ?>
            </span>
        </form>

        <div class="ad-card__body ad-card__body--flush">
            <?php if ($tree === []): ?>
                <?= admin_empty(
                    'No items in this menu yet',
                    'Add the first link and it appears on the storefront straight away.',
                    'Add Item',
                    admin_url('menus/?location=' . urlencode($location) . '&item=new'),
                    'menu'
                ) ?>
            <?php else: ?>
                <div class="ad-tablewrap">
                    <table class="ad-table">
                        <thead>
                            <tr>
                                <th style="width:62px">Order</th>
                                <th>Label</th>
                                <th>Type</th>
                                <th>Target</th>
                                <th>Flags</th>
                                <th>Visibility</th>
                                <th>Status</th>
                                <th class="ad-table__actions">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tree as $entry): ?>
                                <?php
                                $row   = $entry['row'];
                                $rowId = (int) $row['id'];
                                $depth = (int) $entry['depth'];
                                ?>
                                <tr>
                                    <td>
                                        <div class="mn-arrows">
                                            <form method="post" action="<?= e(admin_url('menus/item-save.php')) ?>">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="intent" value="move">
                                                <input type="hidden" name="id" value="<?= $rowId ?>">
                                                <input type="hidden" name="move" value="up">
                                                <button type="submit" class="ad-btn ad-btn--icon" title="Move up"
                                                        aria-label="Move <?= e_attr($row['label']) ?> up"
                                                        style="width:26px;height:20px" <?= $entry['first'] ? 'disabled' : '' ?>>
                                                    <?= icon('chevron-up', 'w-3 h-3') ?>
                                                </button>
                                            </form>
                                            <form method="post" action="<?= e(admin_url('menus/item-save.php')) ?>">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="intent" value="move">
                                                <input type="hidden" name="id" value="<?= $rowId ?>">
                                                <input type="hidden" name="move" value="down">
                                                <button type="submit" class="ad-btn ad-btn--icon" title="Move down"
                                                        aria-label="Move <?= e_attr($row['label']) ?> down"
                                                        style="width:26px;height:20px" <?= $entry['last'] ? 'disabled' : '' ?>>
                                                    <?= icon('chevron-down', 'w-3 h-3') ?>
                                                </button>
                                            </form>
                                        </div>
                                    </td>

                                    <td>
                                        <div class="ad-cellflex" style="padding-left:<?= $depth * 22 ?>px">
                                            <?php if ($depth > 0): ?>
                                                <span class="ad-muted" aria-hidden="true" style="font-family:monospace">&#9492;</span>
                                            <?php endif; ?>
                                            <?php if (!empty($row['icon']) && icon_exists((string) $row['icon'])): ?>
                                                <span class="ad-muted"><?= icon((string) $row['icon'], 'w-4 h-4') ?></span>
                                            <?php endif; ?>
                                            <span style="min-width:0">
                                                <span class="ad-cellflex__name" style="display:block">
                                                    <a href="<?= e(admin_url('menus/?location=' . urlencode($location) . '&item=' . $rowId)) ?>#menuForm">
                                                        <?= e($row['label']) ?>
                                                    </a>
                                                </span>
                                                <span class="ad-cellflex__meta">
                                                    Sort <?= (int) $row['sort_order'] ?>
                                                    <?php if ($depth === 0 && !empty($childrenOf[$rowId])): ?>
                                                        &middot; <?= count($childrenOf[$rowId]) ?> child item(s)
                                                    <?php endif; ?>
                                                </span>
                                            </span>
                                        </div>
                                    </td>

                                    <td class="ad-muted"><?= e(menu_link_types()[$row['link_type']] ?? $row['link_type']) ?></td>
                                    <td class="ad-muted"><?= e(str_limit(menu_item_target($row), 34)) ?></td>

                                    <td>
                                        <?php if (!empty($row['badge'])): ?>
                                            <span class="sik-badge"
                                                  <?= !empty($row['badge_color']) ? 'style="background:' . e_attr($row['badge_color']) . ';color:#fff"' : '' ?>>
                                                <?= e($row['badge']) ?>
                                            </span>
                                        <?php endif; ?>
                                        <?php if ((int) $row['is_mega'] === 1): ?>
                                            <span class="sik-status sik-status--indigo">Mega &times;<?= (int) $row['mega_columns'] ?></span>
                                        <?php endif; ?>
                                        <?php if ((int) $row['open_new_tab'] === 1): ?>
                                            <span class="sik-status sik-status--gray">New tab</span>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <?php if ($row['device_visibility'] !== 'all'): ?>
                                            <span class="sik-status sik-status--blue">
                                                <?= e(menu_device_visibility()[$row['device_visibility']] ?? $row['device_visibility']) ?>
                                            </span>
                                        <?php endif; ?>
                                        <?php if ($row['auth_visibility'] !== 'all'): ?>
                                            <span class="sik-status sik-status--violet">
                                                <?= e(menu_auth_visibility()[$row['auth_visibility']] ?? $row['auth_visibility']) ?>
                                            </span>
                                        <?php endif; ?>
                                        <?php if ($row['device_visibility'] === 'all' && $row['auth_visibility'] === 'all'): ?>
                                            <span class="ad-muted">Everyone</span>
                                        <?php endif; ?>
                                    </td>

                                    <td><?= admin_state_badge((string) $row['status']) ?></td>

                                    <td class="ad-table__actions">
                                        <?php // Only where there is a panel to edit: the screen is
                                              // about the rows inside one, and a leaf item has none. ?>
                                        <?php if (!empty($childrenOf[$rowId])): ?>
                                            <a class="ad-btn ad-btn--icon" title="Panel content"
                                               aria-label="Edit the panel content under <?= e_attr($row['label']) ?>"
                                               href="<?= e(admin_url('menus/panel.php?item=' . $rowId)) ?>">
                                                <?= icon('list', 'w-4 h-4') ?>
                                            </a>
                                        <?php endif; ?>
                                        <a class="ad-btn ad-btn--icon" title="Add a child item"
                                           aria-label="Add a child under <?= e_attr($row['label']) ?>"
                                           href="<?= e(admin_url('menus/?location=' . urlencode($location) . '&item=new&parent=' . $rowId)) ?>#menuForm">
                                            <?= icon('plus', 'w-4 h-4') ?>
                                        </a>
                                        <a class="ad-btn ad-btn--icon" title="Edit"
                                           aria-label="Edit <?= e_attr($row['label']) ?>"
                                           href="<?= e(admin_url('menus/?location=' . urlencode($location) . '&item=' . $rowId)) ?>#menuForm">
                                            <?= icon('edit', 'w-4 h-4') ?>
                                        </a>
                                        <?= admin_delete_form(
                                            admin_url('menus/item-delete.php'),
                                            $rowId,
                                            empty($childrenOf[$rowId])
                                                ? 'Delete "' . $row['label'] . '"?'
                                                : 'Delete "' . $row['label'] . '" and its '
                                                    . count($childrenOf[$rowId]) . ' child item(s)? This cannot be undone.'
                                        ) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php if ($formItem !== null): ?>
<form class="ad-form" id="menuForm" method="post" enctype="multipart/form-data"
      action="<?= e(admin_url('menus/item-save.php')) ?>" data-guard-unsaved>
    <?= csrf_field() ?>
    <input type="hidden" name="intent" value="item">
    <input type="hidden" name="id" value="<?= (int) $formItem['id'] ?>">
    <input type="hidden" name="location" value="<?= e_attr($location) ?>">

    <div class="ad-grid ad-grid--sidebar">
        <div style="display:grid;gap:16px">

            <div class="ad-card" style="margin:0">
                <div class="ad-card__head">
                    <div>
                        <div class="ad-card__title"><?= $isEdit ? 'Edit menu item' : 'New menu item' ?></div>
                        <div class="ad-card__sub"><?= e($locations[$location]['label']) ?></div>
                    </div>
                    <a class="ad-btn ad-btn--sm" href="<?= e(admin_url('menus/?location=' . urlencode($location))) ?>">Close</a>
                </div>
                <div class="ad-card__body">
                    <div class="ad-row ad-row--2">
                        <div class="ad-field">
                            <label class="sik-label" for="miLabel">Label <span class="req">*</span></label>
                            <input class="sik-input<?= isset($errors['label']) ? ' is-invalid' : '' ?>" type="text"
                                   id="miLabel" name="label" maxlength="120" required
                                   value="<?= e($formItem['label']) ?>" placeholder="Curtain Lights">
                            <?php if (isset($errors['label'])): ?>
                                <span class="sik-error"><?= e($errors['label']) ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="ad-field">
                            <label class="sik-label" for="miLinkType">Link type</label>
                            <select class="sik-select" id="miLinkType" name="link_type" data-link-type>
                                <?= admin_options(menu_link_types(), $formItem['link_type']) ?>
                            </select>
                        </div>
                    </div>

                    <div class="ad-field" data-link-block="reference"
                         <?= in_array($formItem['link_type'], menu_reference_types(), true) ? '' : 'hidden' ?>>
                        <label class="sik-label" for="miReference">
                            <span data-reference-label><?= e(menu_link_types()[$formItem['link_type']] ?? 'Reference') ?></span>
                            <span class="req">*</span>
                        </label>
                        <select class="sik-select<?= isset($errors['reference_id']) ? ' is-invalid' : '' ?>"
                                id="miReference" name="reference_id">
                            <option value="">— Select —</option>
                            <?php if (in_array($formItem['link_type'], menu_reference_types(), true)): ?>
                                <?= admin_options($references[$formItem['link_type']], $formItem['reference_id']) ?>
                            <?php endif; ?>
                        </select>
                        <?php if (isset($errors['reference_id'])): ?>
                            <span class="sik-error"><?= e($errors['reference_id']) ?></span>
                        <?php else: ?>
                            <span class="sik-help">Resolves to that row's current slug, so renaming never breaks it.</span>
                        <?php endif; ?>
                    </div>

                    <div class="ad-field" data-link-block="route" <?= $formItem['link_type'] === 'route' ? '' : 'hidden' ?>>
                        <label class="sik-label" for="miRoute">Store page <span class="req">*</span></label>
                        <select class="sik-select<?= isset($errors['route_url']) ? ' is-invalid' : '' ?>"
                                id="miRoute" name="route_url">
                            <option value="">— Select —</option>
                            <?= admin_options(menu_routes(), $formItem['link_type'] === 'route' ? $formItem['url'] : '') ?>
                        </select>
                        <?php if (isset($errors['route_url'])): ?>
                            <span class="sik-error"><?= e($errors['route_url']) ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="ad-field" data-link-block="custom" <?= $formItem['link_type'] === 'custom' ? '' : 'hidden' ?>>
                        <label class="sik-label" for="miUrl">URL <span class="req">*</span></label>
                        <input class="sik-input<?= isset($errors['url']) ? ' is-invalid' : '' ?>" type="text"
                               id="miUrl" name="url" maxlength="255"
                               value="<?= e($formItem['link_type'] === 'custom' ? $formItem['url'] : '') ?>"
                               placeholder="shop.php?discount=50 or https://…">
                        <?php if (isset($errors['url'])): ?>
                            <span class="sik-error"><?= e($errors['url']) ?></span>
                        <?php else: ?>
                            <span class="sik-help">Absolute links, <span class="ad-mono">mailto:</span> and <span class="ad-mono">tel:</span> pass through untouched.</span>
                        <?php endif; ?>
                    </div>

                    <div class="ad-field">
                        <label class="sik-label" for="miSort">Sort order</label>
                        <input class="sik-input<?= isset($errors['sort_order']) ? ' is-invalid' : '' ?>" type="number"
                               id="miSort" name="sort_order" min="0" max="9999" step="1" style="max-width:160px"
                               value="<?= (int) $formItem['sort_order'] ?>">
                        <?php if (isset($errors['sort_order'])): ?>
                            <span class="sik-error"><?= e($errors['sort_order']) ?></span>
                        <?php else: ?>
                            <span class="sik-help">The arrows above renumber the whole group for you.</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- ============================ Icon manager ======================== -->
            <?php
            $miIcon       = (string) ($formItem['icon'] ?? '');
            $miIconUpload = icon_is_upload($miIcon);
            $miIconPath   = $miIconUpload ? icon_upload_path($miIcon) : '';
            // A key that is neither in the set nor a usable upload: the row is
            // pointing at something that draws nothing on the store.
            $miIconBroken = $miIcon !== '' && !$miIconUpload && !icon_exists($miIcon);
            ?>
            <div class="ad-card" style="margin:0">
                <div class="ad-card__head">
                    <div>
                        <div class="ad-card__title">Icon</div>
                        <?php // Nothing about the glyph is hard-coded in the storefront: the
                              // header bar, the mega panel and the mobile drawer all render
                              // whatever is picked here. ?>
                        <div class="ad-card__sub">The glyph beside this item, wherever the menu renders.</div>
                    </div>
                </div>
                <div class="ad-card__body">
                    <?php // Hidden mirror of the saved value, so a rejected save can put the
                          // picker back on a custom upload rather than on "None". ?>
                    <input type="hidden" name="icon_current" value="<?= e_attr($miIcon) ?>">

                    <div class="mn-iconbar">
                        <label class="sik-sr" for="miIconSearch">Search icons</label>
                        <?php // Counted, not typed: a hard-coded "90" goes stale the
                              // first time a glyph is added to icons.php. ?>
                        <input class="sik-input" type="search" id="miIconSearch" data-icon-search
                               placeholder="Search <?= count(icon_names()) ?> icons — try &ldquo;delivery&rdquo;, &ldquo;discount&rdquo;, &ldquo;warranty&rdquo;&hellip;"
                               autocomplete="off">
                        <span class="ad-muted mn-iconbar__count" data-icon-count aria-live="polite"></span>
                    </div>

                    <div class="ad-iconpick" role="radiogroup" aria-label="Menu icon" data-icon-grid>
                        <div class="mn-iconpick__head">Icon off</div>
                        <label class="mn-iconpick__cell" data-icon-cell data-icon-special data-icon-terms="none no icon off blank empty">
                            <input type="radio" name="icon" value="" <?= $miIcon === '' ? 'checked' : '' ?>
                                   data-icon-radio data-icon-name="">
                            <span class="ad-iconpick__box"><?= icon('close', 'w-5 h-5') ?><em>None</em></span>
                        </label>

                        <?php if ($miIconUpload && $miIconPath !== ''): ?>
                            <div class="mn-iconpick__head">Your upload</div>
                            <label class="mn-iconpick__cell" data-icon-cell data-icon-special data-icon-terms="custom upload own svg mine">
                                <input type="radio" name="icon" value="__upload__" checked
                                       data-icon-radio data-icon-name="custom">
                                <span class="ad-iconpick__box">
                                    <img src="<?= e(url($miIconPath)) ?>" alt="" width="20" height="20"
                                         style="width:20px;height:20px;object-fit:contain">
                                    <em>Custom</em>
                                </span>
                            </label>
                        <?php endif; ?>

                        <?php foreach (icon_groups() as $miGroup => $miNames): ?>
                            <div class="mn-iconpick__head"><?= e($miGroup) ?></div>
                            <?php foreach ($miNames as $miName): ?>
                                <?php // The keywords ride on the cell so the filter is a pure
                                      // string test in the browser — no second payload of icon
                                      // metadata, and the SVG the admin is judging is already
                                      // in the DOM to be cloned into the preview. ?>
                                <label class="mn-iconpick__cell" data-icon-cell
                                       data-icon-terms="<?= e_attr($miName . ' ' . str_replace('-', ' ', $miName) . ' ' . (icon_keywords()[$miName] ?? '')) ?>">
                                    <input type="radio" name="icon" value="<?= e_attr($miName) ?>"
                                           <?= $miIcon === $miName ? 'checked' : '' ?>
                                           data-icon-radio data-icon-name="<?= e_attr($miName) ?>">
                                    <span class="ad-iconpick__box"><?= icon($miName, 'w-5 h-5') ?><em><?= e($miName) ?></em></span>
                                </label>
                            <?php endforeach; ?>
                        <?php endforeach; ?>

                        <p class="mn-iconpick__empty" data-icon-empty hidden>No icon matches that search.</p>
                    </div>

                    <?php if (isset($errors['icon'])): ?>
                        <span class="sik-error"><?= e($errors['icon']) ?></span>
                    <?php elseif ($miIconBroken): ?>
                        <span class="sik-error">
                            The saved icon &ldquo;<?= e($miIcon) ?>&rdquo; is not in the icon set, so nothing is
                            selected above and this item draws no glyph on the store. Saving will clear it —
                            pick a replacement first if you want one.
                        </span>
                    <?php elseif ($miIconUpload && $miIconPath === ''): ?>
                        <span class="sik-error">
                            This item&rsquo;s custom icon file is missing from /uploads, so no glyph is drawn.
                            Upload it again or pick one from the set.
                        </span>
                    <?php endif; ?>

                    <div class="ad-row ad-row--2" style="margin-top:14px">
                        <div class="ad-field">
                            <label class="sik-label" for="miIconVisibility">Where the icon shows</label>
                            <select class="sik-select<?= isset($errors['icon_visibility']) ? ' is-invalid' : '' ?>"
                                    id="miIconVisibility" name="icon_visibility" data-icon-visibility>
                                <?= admin_options(menu_icon_visibility(), $formItem['icon_visibility']) ?>
                            </select>
                            <?php if (isset($errors['icon_visibility'])): ?>
                                <span class="sik-error"><?= e($errors['icon_visibility']) ?></span>
                            <?php else: ?>
                                <span class="sik-help">
                                    Hides the picture only. <strong>Visibility &rsaquo; Devices</strong>
                                    drops the whole item.
                                </span>
                            <?php endif; ?>
                        </div>

                        <div class="ad-field">
                            <label class="sik-label" for="miIconFile">Upload a custom icon</label>
                            <input class="sik-input" type="file" id="miIconFile" name="icon_file"
                                   accept=".svg,.png,.webp,image/svg+xml,image/png,image/webp">
                            <?php /* An uploaded SVG is rewritten from a safe allowlist on the way
                                     in and drawn through <img>, so it cannot carry script. The same
                                     <img> is why it cannot inherit currentColor the way the built-in
                                     set does - hence the warning, which has to stay: an operator who
                                     expects a recoloured icon gets a stray palette in the header. */ ?>
                            <span class="sik-help">
                                Square, drawn for 24&times;24. An uploaded SVG keeps its own colours.
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ============================ Badge =============================== -->
            <div class="ad-card" style="margin:0">
                <div class="ad-card__head">
                    <div>
                        <div class="ad-card__title">Badge</div>
                        <?php // A preset only writes text, colours and shape into the fields.
                              // Nothing stores "this is a HOT badge", so editing after picking
                              // one is ordinary editing. ?>
                        <div class="ad-card__sub">A short chip beside the label. Presets fill the fields in.</div>
                    </div>
                </div>
                <div class="ad-card__body">
                    <div class="mn-presets" role="group" aria-label="Badge presets">
                        <?php foreach (menu_badge_presets() as $miKey => $miPreset): ?>
                            <button type="button" class="mn-preset" data-badge-preset="<?= e_attr($miKey) ?>"
                                    data-preset-text="<?= e_attr($miPreset['text']) ?>"
                                    data-preset-color="<?= e_attr($miPreset['color']) ?>"
                                    data-preset-style="<?= e_attr($miPreset['style']) ?>"
                                    data-preset-animation="<?= e_attr($miPreset['animation']) ?>"
                                    title="<?= e_attr($miPreset['hint']) ?>">
                                <?php // The chip in the button is the storefront's own .sik-badge
                                      // rendered by the same function the header uses, so the
                                      // preset shows the colour it will actually produce. ?>
                                <?= menu_badge_chip([
                                    'badge'           => $miPreset['text'] !== '' ? $miPreset['text'] : 'CUSTOM',
                                    'badge_color'     => $miPreset['color'],
                                    'badge_style'     => $miPreset['style'],
                                    'badge_animation' => 'none',
                                ]) ?>
                            </button>
                        <?php endforeach; ?>
                        <button type="button" class="mn-preset mn-preset--clear" data-badge-clear>
                            <?= icon('close', 'w-4 h-4') ?> No badge
                        </button>
                    </div>

                    <div class="ad-row ad-row--2" style="margin-top:14px">
                        <div class="ad-field">
                            <label class="sik-label" for="miBadge">Badge text</label>
                            <input class="sik-input<?= isset($errors['badge']) ? ' is-invalid' : '' ?>" type="text"
                                   id="miBadge" name="badge" maxlength="30" data-badge-text
                                   value="<?= e($formItem['badge'] ?? '') ?>" placeholder="NEW">
                            <?php if (isset($errors['badge'])): ?>
                                <span class="sik-error"><?= e($errors['badge']) ?></span>
                            <?php else: ?>
                                <span class="sik-help">Empty means no badge. The chip never wraps.</span>
                            <?php endif; ?>
                        </div>
                        <div class="ad-field">
                            <label class="sik-label" for="miBadgeStyle">Style</label>
                            <select class="sik-select<?= isset($errors['badge_style']) ? ' is-invalid' : '' ?>"
                                    id="miBadgeStyle" name="badge_style" data-badge-style>
                                <?= admin_options(menu_badge_styles(), $formItem['badge_style']) ?>
                            </select>
                            <?php if (isset($errors['badge_style'])): ?>
                                <span class="sik-error"><?= e($errors['badge_style']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="ad-row ad-row--2">
                        <div class="ad-field">
                            <label class="sik-label" for="miBadgeColor">Badge colour</label>
                            <div class="ad-colorfield">
                                <input type="color" aria-label="Pick a badge colour" data-color-for="#miBadgeColor"
                                       value="<?= e(preg_match('/^#[0-9a-f]{6}$/i', (string) ($formItem['badge_color'] ?? '')) === 1 ? $formItem['badge_color'] : theme_default_badge_colour()) ?>">
                                <input class="sik-input ad-mono<?= isset($errors['badge_color']) ? ' is-invalid' : '' ?>"
                                       type="text" id="miBadgeColor" name="badge_color" maxlength="20"
                                       data-badge-color value="<?= e($formItem['badge_color'] ?? '') ?>" placeholder="<?= e_attr(theme_default_badge_colour()) ?>">
                            </div>
                            <?php if (isset($errors['badge_color'])): ?>
                                <span class="sik-error"><?= e($errors['badge_color']) ?></span>
                            <?php else: ?>
                                <span class="sik-help">Fills a solid chip, tints a soft one, outlines an outline.</span>
                            <?php endif; ?>
                        </div>
                        <div class="ad-field">
                            <label class="sik-label" for="miBadgeInk">Text colour</label>
                            <div class="ad-colorfield">
                                <input type="color" aria-label="Pick a badge text colour" data-color-for="#miBadgeInk"
                                       value="<?= e(preg_match('/^#[0-9a-f]{6}$/i', (string) ($formItem['badge_text_color'] ?? '')) === 1 ? $formItem['badge_text_color'] : '#ffffff') ?>">
                                <input class="sik-input ad-mono<?= isset($errors['badge_text_color']) ? ' is-invalid' : '' ?>"
                                       type="text" id="miBadgeInk" name="badge_text_color" maxlength="20"
                                       data-badge-ink value="<?= e($formItem['badge_text_color'] ?? '') ?>" placeholder="Automatic">
                            </div>
                            <?php if (isset($errors['badge_text_color'])): ?>
                                <span class="sik-error"><?= e($errors['badge_text_color']) ?></span>
                            <?php else: ?>
                                <span class="sik-help">
                                    Blank: darkened until 11px text clears WCAG AA.
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="ad-row ad-row--2">
                        <div class="ad-field">
                            <label class="sik-label" for="miBadgePosition">Position</label>
                            <select class="sik-select<?= isset($errors['badge_position']) ? ' is-invalid' : '' ?>"
                                    id="miBadgePosition" name="badge_position" data-badge-position>
                                <?= admin_options(menu_badge_positions(), $formItem['badge_position']) ?>
                            </select>
                            <?php if (isset($errors['badge_position'])): ?>
                                <span class="sik-error"><?= e($errors['badge_position']) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="ad-field">
                            <label class="sik-label" for="miBadgeAnimation">Animation</label>
                            <select class="sik-select<?= isset($errors['badge_animation']) ? ' is-invalid' : '' ?>"
                                    id="miBadgeAnimation" name="badge_animation" data-badge-animation>
                                <?= admin_options(menu_badge_animations(), $formItem['badge_animation']) ?>
                            </select>
                            <?php if (isset($errors['badge_animation'])): ?>
                                <span class="sik-error"><?= e($errors['badge_animation']) ?></span>
                            <?php else: ?>
                                <?php // A bar of pulsing chips is noise: nothing stands out. ?>
                                <span class="sik-help">
                                    One slow breath of opacity. Use it on one item at most.
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ============================ Mega panel ========================== -->
            <div class="ad-card" style="margin:0">
                <div class="ad-card__head">
                    <div>
                        <div class="ad-card__title">Mega panel</div>
                        <?php // One vertical column at every width. Which children it lists,
                              // and in what order, is the Panel Content screen's job. ?>
                        <div class="ad-card__sub">Top-level items with children only.</div>
                    </div>
                    <?php if ($isEdit && (int) $formItem['is_mega'] === 1): ?>
                        <a class="ad-btn ad-btn--sm"
                           href="<?= e(admin_url('menus/panel.php?item=' . (int) $formItem['id'])) ?>">
                            <?= icon('list', 'w-4 h-4') ?> Panel content
                        </a>
                    <?php endif; ?>
                </div>
                <div class="ad-card__body">
                    <label class="ad-switch" style="margin-bottom:14px">
                        <input type="checkbox" name="is_mega" value="1" <?= (int) $formItem['is_mega'] === 1 ? 'checked' : '' ?>>
                        <span class="ad-switch__track"></span>
                        <span>Render children as a mega panel</span>
                    </label>
                    <?php // On: the panel opens with a "Shop all" header row carrying the
                          // category's own picture. Off: the children are a plain dropdown.
                          // Either way it is one column of links. ?>
                    <p class="sik-help" style="margin:-8px 0 14px">
                        Off, the children are a plain dropdown.
                    </p>

                    <div class="ad-row ad-row--2">
                        <div class="ad-field">
                            <label class="sik-label" for="miMegaColumns">Columns</label>
                            <input class="sik-input<?= isset($errors['mega_columns']) ? ' is-invalid' : '' ?>" type="number"
                                   id="miMegaColumns" name="mega_columns" min="1" max="6" step="1"
                                   value="<?= (int) $formItem['mega_columns'] ?>">
                            <?php if (isset($errors['mega_columns'])): ?>
                                <span class="sik-error"><?= e($errors['mega_columns']) ?></span>
                            <?php else: ?>
                                <?php // Kept rather than dropped because the saved values are still
                                      // in the menu rows; hiding the field would hide them too. ?>
                                <span class="sik-help">
                                    Not rendered: the panel is one column at every width.
                                </span>
                            <?php endif; ?>
                        </div>
                        <div class="ad-field">
                            <label class="sik-label" for="miMegaImageUrl">Promo link</label>
                            <input class="sik-input<?= isset($errors['mega_image_url']) ? ' is-invalid' : '' ?>" type="text"
                                   id="miMegaImageUrl" name="mega_image_url" maxlength="255"
                                   value="<?= e($formItem['mega_image_url'] ?? '') ?>" placeholder="deals.php">
                            <?php if (isset($errors['mega_image_url'])): ?>
                                <span class="sik-error"><?= e($errors['mega_image_url']) ?></span>
                            <?php else: ?>
                                <span class="sik-help">
                                    Blank sends the tile to this item&rsquo;s own page.
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="ad-field">
                        <label class="sik-label">Promo image</label>
                        <div class="ad-drop" data-drop="#miMegaPreview">
                            <input type="file" name="mega_image" accept="image/*">
                            <?= icon('upload', 'w-6 h-6') ?>
                            <div style="font-size:13px;margin-top:6px">280&times;150 works best</div>
                        </div>
                        <div class="ad-preview" id="miMegaPreview">
                            <?php if (!empty($formItem['mega_image'])): ?>
                                <div class="ad-preview__item">
                                    <img src="<?= e(img_url($formItem['mega_image'])) ?>" alt="Current promo image">
                                    <button type="button" class="ad-preview__remove" data-remove-image="#miRemoveMega"
                                            aria-label="Remove promo image">&times;</button>
                                </div>
                            <?php endif; ?>
                        </div>
                        <input type="hidden" name="remove_mega_image" id="miRemoveMega" value="0">
                        <?php /* The tile sits below the category list and stays put while a long
                                 list scrolls past it. It carries no text of its own, so whatever
                                 the picture says is all it says. Worth keeping on screen: people
                                 confuse it with the thumbnail at the TOP of the panel, which is
                                 the category's own picture from Admin > Categories. */ ?>
                        <span class="sik-help">
                            Sits below the category list. Not the panel&rsquo;s top thumbnail.
                        </span>
                    </div>

                    <label class="ad-switch" style="margin-top:6px">
                        <input type="checkbox" name="mega_promo" value="1" data-mega-promo
                               <?= (int) $formItem['mega_promo'] === 1 ? 'checked' : '' ?>>
                        <span class="ad-switch__track"></span>
                        <span>Show the promo tile in this panel</span>
                    </label>
                    <?php if (isset($errors['mega_promo'])): ?>
                        <span class="sik-error"><?= e($errors['mega_promo']) ?></span>
                    <?php else: ?>
                        <?php // Deliberately off even where an image was saved before the tile
                              // existed: an upload from a year ago should not reappear in the
                              // navigation on its own. ?>
                        <p class="sik-help" style="margin:6px 0 0">
                            Off by default, even where an image is already saved.
                        </p>
                    <?php endif; ?>

                    <div class="ad-field">
                        <label class="sik-label" for="miProductSearch">Featured products</label>
                        <input class="sik-input" type="search" id="miProductSearch" data-product-search="#miProductResults"
                               placeholder="Search by product name or SKU&hellip;" autocomplete="off">
                        <div id="miProductResults" data-product-results style="display:grid;gap:2px;margin:6px 0"></div>
                        <div data-picked-products>
                            <?php foreach ($megaProducts as $product): ?>
                                <div class="ad-cellflex" data-picked="<?= (int) $product['id'] ?>"
                                     style="padding:8px;border:1px solid var(--ad-border);border-radius:8px;margin-bottom:6px">
                                    <img class="ad-thumb" src="<?= e(img_url($product['main_image'])) ?>" alt=""
                                         width="38" height="38" loading="lazy">
                                    <span class="ad-cellflex__name" style="flex:1;min-width:0">
                                        <?= e($product['name']) ?>
                                        <span class="ad-cellflex__meta"><?= e($product['sku']) ?></span>
                                    </span>
                                    <input type="hidden" name="product_ids[]" value="<?= (int) $product['id'] ?>">
                                    <button type="button" class="ad-btn ad-btn--icon ad-btn--danger-ghost"
                                            data-unpick aria-label="Remove product">&times;</button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php // Saved as a comma-separated id list, so a chosen set survives
                              // until the rail comes back. ?>
                        <span class="sik-help">
                            Not rendered: the panel has no product rail.
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <div style="display:grid;gap:16px;align-content:start">
            <?php
            /**
             * Live preview of the row itself.
             *
             * These are the storefront's own classes — .sik-nav__link and
             * .sik-mega__link out of app.css, which the admin layout already
             * loads — so this is the real component at the real size, not a
             * drawing of one. The server renders it from the saved row and the
             * JS below re-renders it as the fields change, which is why the two
             * cannot disagree at first paint.
             */
            $miPreviewGlyph = menu_item_glyph($formItem, 'desktop', 'sik-nav__icon');
            $miPreviewChip  = menu_badge_chip($formItem, 'sik-nav__badge');
            $miPreviewLabel = trim((string) $formItem['label']) !== '' ? (string) $formItem['label'] : 'Menu item';
            $miPreviewLead  = menu_badge_first($formItem);
            $miDrawerGlyph  = menu_item_glyph($formItem, 'mobile', 'w-4 h-4');
            $miDrawerChip   = menu_badge_chip(
                $formItem,
                'sik-nav__badge sik-nav__badge--drawer' . ($miPreviewLead ? ' sik-nav__badge--lead' : '')
            );
            ?>
            <div class="ad-card mn-preview" style="margin:0">
                <div class="ad-card__head">
                    <div>
                        <div class="ad-card__title">Preview</div>
                        <div class="ad-card__sub">The storefront&rsquo;s own components, live as you type.</div>
                    </div>
                </div>
                <div class="ad-card__body">
                    <?php // Two surfaces, not two sizes of one: an icon set to
                          // "mobile only" has to be visibly absent from the bar and
                          // present in the drawer, which is only legible if both are
                          // on screen. ?>
                    <p class="mn-preview__cap">Desktop header bar</p>
                    <div class="mn-preview__bar">
                        <span class="sik-nav__link" data-preview-nav>
                            <span data-preview-glyph><?= $miPreviewGlyph ?></span>
                            <?= $miPreviewLead ? $miPreviewChip : '' ?>
                            <span class="sik-nav__text" data-preview-label><?= e($miPreviewLabel) ?></span>
                            <?= $miPreviewLead ? '' : $miPreviewChip ?>
                        </span>
                    </div>

                    <p class="mn-preview__cap">Mobile drawer</p>
                    <div class="mn-preview__panel">
                        <span class="sik-mmenu__link" data-preview-drawer>
                            <?php if ($miDrawerGlyph !== ''): ?>
                                <span class="sik-mmenu__glyph" aria-hidden="true" data-preview-glyph><?= $miDrawerGlyph ?></span>
                            <?php else: ?>
                                <span data-preview-glyph></span>
                            <?php endif; ?>
                            <span data-preview-label>
                                <?= $miPreviewLead ? $miDrawerChip : '' ?><?= e($miPreviewLabel) ?><?= $miPreviewLead ? '' : $miDrawerChip ?>
                            </span>
                            <?= icon('chevron-right', 'w-4 h-4') ?>
                        </span>
                    </div>

                    <?php // Says out loud what the two selects above do to the glyph,
                          // because "Hidden" is otherwise indistinguishable from "no
                          // icon chosen" in a preview. ?>
                    <p class="sik-help" style="margin:10px 0 0" data-preview-note></p>
                </div>
            </div>

            <div class="ad-card" style="margin:0">
                <div class="ad-card__head"><div class="ad-card__title">Placement</div></div>
                <div class="ad-card__body" style="display:grid;gap:14px">
                    <div class="ad-field">
                        <label class="sik-label" for="miParent">Parent item</label>
                        <select class="sik-select<?= isset($errors['parent_id']) ? ' is-invalid' : '' ?>"
                                id="miParent" name="parent_id">
                            <option value="">— Top level —</option>
                            <?= admin_options($parentOptions, $formItem['parent_id']) ?>
                        </select>
                        <?php if (isset($errors['parent_id'])): ?>
                            <span class="sik-error"><?= e($errors['parent_id']) ?></span>
                        <?php else: ?>
                            <span class="sik-help">This item and everything beneath it are left out of the list.</span>
                        <?php endif; ?>
                    </div>

                    <div class="ad-field">
                        <label class="sik-label" for="miStatus">Status</label>
                        <select class="sik-select" id="miStatus" name="status">
                            <?= admin_options(['active' => 'Active', 'inactive' => 'Inactive'], $formItem['status']) ?>
                        </select>
                    </div>

                    <label class="ad-switch">
                        <input type="checkbox" name="open_new_tab" value="1" <?= (int) $formItem['open_new_tab'] === 1 ? 'checked' : '' ?>>
                        <span class="ad-switch__track"></span>
                        <span>Open in a new tab</span>
                    </label>
                </div>
                <div class="ad-card__foot">
                    <a class="ad-btn" href="<?= e(admin_url('menus/?location=' . urlencode($location))) ?>">Cancel</a>
                    <button type="submit" class="ad-btn ad-btn--primary">
                        <?= icon('check', 'w-4 h-4') ?> <?= $isEdit ? 'Save Item' : 'Add Item' ?>
                    </button>
                </div>
            </div>

            <div class="ad-card" style="margin:0">
                <div class="ad-card__head"><div class="ad-card__title">Visibility</div></div>
                <div class="ad-card__body" style="display:grid;gap:14px">
                    <div class="ad-field">
                        <label class="sik-label" for="miDevice">Devices</label>
                        <select class="sik-select" id="miDevice" name="device_visibility">
                            <?= admin_options(menu_device_visibility(), $formItem['device_visibility']) ?>
                        </select>
                    </div>
                    <div class="ad-field">
                        <label class="sik-label" for="miAuth">Audience</label>
                        <select class="sik-select" id="miAuth" name="auth_visibility">
                            <?= admin_options(menu_auth_visibility(), $formItem['auth_visibility']) ?>
                        </select>
                        <span class="sik-help">For &ldquo;Sign in&rdquo; and &ldquo;My account&rdquo; links.</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
    // Only one control drives the form: the link type decides which target
    // field is asked for. Everything renders correctly server-side already.
    (function () {
        var references = <?= e_json(menu_reference_lists()) ?>;
        var labels = { category: 'Category', brand: 'Brand', page: 'CMS page' };

        var typeSelect = document.querySelector('[data-link-type]');
        var reference  = document.getElementById('miReference');
        var refLabel   = document.querySelector('[data-reference-label]');
        if (!typeSelect) return;

        function sync() {
            var value = typeSelect.value;
            var isReference = Object.prototype.hasOwnProperty.call(references, value);

            document.querySelectorAll('[data-link-block]').forEach(function (block) {
                var key = block.dataset.linkBlock;
                block.hidden = key === 'reference' ? !isReference : key !== value;
            });

            if (!isReference || !reference) return;
            if (refLabel) refLabel.textContent = labels[value] || 'Reference';

            var current = reference.value;
            var options = ['<option value="">— Select —</option>'];
            Object.keys(references[value]).forEach(function (id) {
                options.push('<option value="' + id + '">' + SIK.escapeHtml(references[value][id]) + '</option>');
            });
            reference.innerHTML = options.join('');
            if (current) reference.value = current;
        }

        typeSelect.addEventListener('change', sync);

        document.querySelectorAll('[data-color-for]').forEach(function (picker) {
            var field = document.querySelector(picker.dataset.colorFor);
            if (!field) return;
            picker.addEventListener('input', function () { field.value = picker.value; });
            field.addEventListener('input', function () {
                if (/^#[0-9a-f]{6}$/i.test(field.value)) picker.value = field.value;
            });
        });
    })();

    /* ------------------------------------------------------------------
       Icon picker: search across key, hyphen-split words and keywords.
       The terms are already on each cell, so this is a string test over
       elements that are in the page — no second payload of icon metadata
       and no request. A heading disappears with the last cell under it.
       ------------------------------------------------------------------ */
    (function () {
        var grid = document.querySelector('[data-icon-grid]');
        var search = document.querySelector('[data-icon-search]');
        if (!grid || !search) return;

        var count = document.querySelector('[data-icon-count]');
        var empty = grid.querySelector('[data-icon-empty]');
        var cells = Array.prototype.slice.call(grid.querySelectorAll('[data-icon-cell]'));
        var heads = Array.prototype.slice.call(grid.querySelectorAll('.mn-iconpick__head'));
        // "None" and the item's own upload are choices, not icons in the set.
        // Counting them would make the tally disagree with the placeholder,
        // which names the size of the set itself.
        var total = cells.filter(function (c) { return !c.hasAttribute('data-icon-special'); }).length;

        function filter() {
            var q = search.value.trim().toLowerCase();
            var shown = 0;

            cells.forEach(function (cell) {
                var hit = q === '' || (cell.dataset.iconTerms || '').indexOf(q) !== -1;
                cell.hidden = !hit;
                if (hit && !cell.hasAttribute('data-icon-special')) shown++;
            });

            // A heading owns every cell up to the next heading, so it lives or
            // dies with them. Walking siblings keeps the grouping in the markup
            // rather than duplicating it in a lookup table here.
            heads.forEach(function (head) {
                var node = head.nextElementSibling;
                var any = false;
                while (node && !node.classList.contains('mn-iconpick__head')) {
                    if (node.matches('[data-icon-cell]') && !node.hidden) { any = true; break; }
                    node = node.nextElementSibling;
                }
                head.hidden = !any;
            });

            if (empty) empty.hidden = shown !== 0;
            if (count) count.textContent = q === '' ? total + ' icons' : shown + ' of ' + total;
        }

        search.addEventListener('input', filter);
        // Enter in a search box inside a form submits it; here it should only
        // ever mean "I have finished typing".
        search.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); filter(); }
        });
        filter();
    })();

    /* ------------------------------------------------------------------
       Badge presets. They fill the fields in and nothing else: what gets
       saved is whatever the fields say afterwards, so an admin can take a
       preset and change one thing about it.
       ------------------------------------------------------------------ */
    (function () {
        var text = document.querySelector('[data-badge-text]');
        if (!text) return;

        var color = document.querySelector('[data-badge-color]');
        var style = document.querySelector('[data-badge-style]');
        var anim  = document.querySelector('[data-badge-animation]');
        var swatch = document.querySelector('[data-color-for="#miBadgeColor"]');

        function apply(preset) {
            text.value = preset.dataset.presetText || '';
            if (color) color.value = preset.dataset.presetColor || '';
            if (style) style.value = preset.dataset.presetStyle || 'solid';
            if (anim)  anim.value  = preset.dataset.presetAnimation || 'none';
            if (swatch && /^#[0-9a-f]{6}$/i.test(preset.dataset.presetColor || '')) {
                swatch.value = preset.dataset.presetColor;
            }
            mark(preset);
            document.dispatchEvent(new CustomEvent('mn:preview'));
            // CUSTOM clears the fields; putting the cursor where the admin now
            // has to type is the whole difference between that being a preset
            // and it being a button that empties the form.
            if ((preset.dataset.presetText || '') === '') text.focus();
        }

        function mark(active) {
            document.querySelectorAll('[data-badge-preset]').forEach(function (b) {
                b.classList.toggle('is-active', b === active);
            });
        }

        document.querySelectorAll('[data-badge-preset]').forEach(function (button) {
            button.addEventListener('click', function () { apply(button); });
        });

        var clear = document.querySelector('[data-badge-clear]');
        if (clear) {
            clear.addEventListener('click', function () {
                text.value = '';
                if (color) color.value = '';
                if (anim) anim.value = 'none';
                mark(null);
                document.dispatchEvent(new CustomEvent('mn:preview'));
            });
        }

        // Typing by hand takes the highlight off whichever preset was clicked —
        // the buttons describe what is in the fields, not a stored choice.
        [text, color, style, anim].forEach(function (field) {
            if (field) field.addEventListener('input', function () { mark(null); });
        });
    })();

    /* ------------------------------------------------------------------
       Live preview. The markup comes back from preview.php, rendered by
       the same functions the storefront uses — see the note at the top of
       that file for why this is a round trip and not a re-implementation.
       ------------------------------------------------------------------ */
    (function () {
        var form = document.getElementById('menuForm');
        var card = document.querySelector('.mn-preview');
        if (!form || !card || !window.SIK) return;

        var nav    = card.querySelector('[data-preview-nav]');
        var drawer = card.querySelector('[data-preview-drawer]');
        var note   = card.querySelector('[data-preview-note]');
        var endpoint = <?= e_json(admin_url('menus/preview.php')) ?>;
        var inflight = null;

        function currentIcon() {
            var checked = form.querySelector('[data-icon-radio]:checked');
            return checked ? checked.value : '';
        }

        function value(name) {
            var field = form.elements[name];
            return field ? field.value : '';
        }

        var render = SIK.debounce(async function () {
            if (inflight) inflight.abort();
            inflight = new AbortController();

            // apiRequest, not the SIK.post shorthand: the shorthand takes no
            // options, and without a signal a burst of keystrokes can land out
            // of order and leave the card showing an older draft than the form.
            var result = await SIK.apiRequest(endpoint, {
                method: 'POST',
                signal: inflight.signal,
                body: {
                    label:            value('label'),
                    icon:             currentIcon(),
                    icon_current:     value('icon_current'),
                    icon_visibility:  value('icon_visibility'),
                    badge:            value('badge'),
                    badge_color:      value('badge_color'),
                    badge_style:      value('badge_style'),
                    badge_text_color: value('badge_text_color'),
                    badge_position:   value('badge_position'),
                    badge_animation:  value('badge_animation')
                }
            });

            if (!result || result.aborted || !result.success || !result.data) return;
            if (nav)    nav.innerHTML    = result.data.nav;
            if (drawer) drawer.innerHTML = result.data.drawer;
            if (note)   note.textContent = result.data.note || '';
        }, 220);

        // One listener on the form, so a field added to the card later is
        // covered without anything being wired up for it.
        form.addEventListener('input', render);
        form.addEventListener('change', render);
        document.addEventListener('mn:preview', render);
        render();
    })();
</script>
<?php endif; ?>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
