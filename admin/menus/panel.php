<?php
/**
 * ShopInnKart Admin - Mega panel content builder.
 *
 * One screen for one panel. The tree in index.php is where a menu's *shape* is
 * edited — parents, nesting, link targets — and editing a panel's contents
 * there means opening eight child items one after another to change eight
 * icons. This screen puts the panel's rows in front of the operator in panel
 * order, with the three things that decide how each one looks, beside a preview
 * of the panel those settings produce.
 *
 * Everything here writes the same `menu_items` columns the item editor writes.
 * There is no second table and no second notion of "in the panel": a row is in
 * the panel when it is an active child of the panel's item, which is what the
 * storefront already reads.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('homepage.edit');

require_once __DIR__ . '/_meta.php';

$itemId = input_int('item');
$panel  = $itemId > 0
    ? Database::fetch('SELECT * FROM `menu_items` WHERE `id` = :id', ['id' => $itemId])
    : null;

if ($panel === null) {
    flash('error', 'That menu item no longer exists.');
    redirect(admin_url('menus/'));
}

$location = (string) Database::fetchColumn(
    'SELECT `location` FROM `menus` WHERE `id` = :id',
    ['id' => (int) $panel['menu_id']]
);
if (!isset(menu_locations()[$location])) {
    $location = 'main';
}
$listUrl = admin_url('menus/?location=' . urlencode($location));
$editUrl = admin_url('menus/?location=' . urlencode($location) . '&item=' . $itemId);

// The rows of the panel: this item's direct children, in the order they are
// stored. Inactive ones are kept — this screen is where they are switched back
// on, so hiding them here would make that impossible.
$rows = Database::fetchAll(
    'SELECT * FROM `menu_items` WHERE `parent_id` = :id ORDER BY `sort_order` ASC, `id` ASC',
    ['id' => $itemId]
);

// Grandchildren, so a row that opens a sub-list of its own says so. The panel
// renders those as an indented group; the operator needs to know which rows
// carry one before wondering why the panel is longer than this list.
$grandchildren = [];
if ($rows !== []) {
    [$placeholders, $params] = Database::inPlaceholders(array_map(
        static fn (array $r): int => (int) $r['id'],
        $rows
    ), 'g');
    $grandchildren = Database::fetchPairs(
        'SELECT `parent_id`, COUNT(*) FROM `menu_items`
         WHERE `parent_id` IN (' . $placeholders . ") AND `status` = 'active'
         GROUP BY `parent_id`",
        $params
    );
}

/**
 * The panel exactly as the storefront builds it.
 *
 * build_menu() is the storefront's own call — cached, filtered by category
 * status and "show in menu", with hrefs and cover images resolved — and
 * menu_surface_items() applies the device and audience rules for the surface
 * this panel lives on. Finding this item inside that tree, rather than
 * assembling one from the rows above, is what makes the preview below the real
 * panel: anything the store would drop is already missing from it.
 */
$findNode = static function (array $nodes, int $id) use (&$findNode): ?array {
    foreach ($nodes as $node) {
        if ((int) $node['id'] === $id) {
            return $node;
        }
        $found = $findNode($node['children'] ?? [], $id);
        if ($found !== null) {
            return $found;
        }
    }
    return null;
};

$liveNode = $findNode(menu_surface_items(build_menu($location), 'desktop'), $itemId);

$errors = errors_pull();
old_clear();

$pageTitle    = 'Panel content';
$pageSubtitle = (string) $panel['label'] . ' · ' . count($rows) . ' row' . (count($rows) === 1 ? '' : 's');
$breadcrumbs  = [
    ['label' => 'Dashboard', 'url' => admin_url('dashboard.php')],
    ['label' => 'Menu Builder', 'url' => $listUrl],
    ['label' => (string) $panel['label']],
];
$pageActions = '<a class="ad-btn" href="' . e($editUrl) . '#menuForm">' . icon('edit', 'w-4 h-4')
    . ' Edit the item</a>'
    . '<a class="ad-btn ad-btn--primary" href="'
    . e(admin_url('menus/?location=' . urlencode($location) . '&item=new&parent=' . $itemId)) . '#menuForm">'
    . icon('plus', 'w-4 h-4') . ' Add a row</a>';

require ADMIN_PATH . '/includes/header.php';
?>
<style>
    /* --- the row list ---
       Laid out by the width of the LIST, not of the window. The six cells
       used to sit on one line from 1100px up and fold to a three-column grid
       below it - which put the icon <select> into the 26px grip column on the
       second line, and above 1100px the one-line row wanted ~720px while the
       column beside the promo card offers ~600px at 1280, so the badge and the
       switch hung off the card. A container query asks the question that
       matters: how wide is the list itself. Without container query support
       the stacked layout applies, which works at any width. */
    .mp-list { list-style: none; margin: 0; padding: 0; display: grid; gap: 8px; container-type: inline-size; }
    .mp-row {
        display: grid;
        grid-template-columns: 34px minmax(0, 1fr) auto;
        grid-template-areas:
            "grip name  show"
            "grip icon  icon"
            "grip badge badge"
            "grip pos   pos";
        gap: 10px; align-items: center;
        padding: 10px; border: 1px solid var(--ad-border); border-radius: 10px; background: #fff;
    }
    @container (min-width: 560px) {
        .mp-row {
            grid-template-columns: 34px auto minmax(0, 1fr) minmax(0, 1fr) auto;
            grid-template-areas:
                "grip pos  name name  show"
                "grip icon icon badge badge";
        }
    }
    @container (min-width: 820px) {
        .mp-row {
            grid-template-columns: 34px auto minmax(120px, 1.2fr) minmax(170px, 1.1fr) minmax(150px, 1fr) auto;
            grid-template-areas: "grip pos name icon badge show";
        }
    }
    .mp-row.is-off { background: var(--ad-bg); opacity: .72; }
    /* The row being carried stays in place, faded; the bar that
       Admin.sortable() draws shows where it will land. */
    .mp-row.is-dragging { opacity: .4; }
    /* The whole left edge of the row is the handle - on a phone a finger
       needs a strip, not a 16px glyph. touch-action comes from admin.css. */
    .mp-grip {
        grid-area: grip; align-self: stretch;
        display: flex; align-items: center; justify-content: center;
        border-radius: 7px; color: var(--ad-muted); cursor: grab;
    }
    .mp-grip:hover { background: var(--ad-bg); color: var(--ad-text); }
    .mp-grip:active { cursor: grabbing; }
    .mp-pos { grid-area: pos; display: flex; align-items: center; gap: 8px; }
    .mp-row__name { grid-area: name; min-width: 0; }
    .mp-iconcell { grid-area: icon; }
    .mp-badgecell { grid-area: badge; }
    .mp-row > .ad-switch { grid-area: show; justify-self: end; }
    .mp-row__name a { font-weight: 600; }
    .mp-row__meta { display: block; font-size: 11.5px; color: var(--ad-muted); }
    .mp-glyph {
        display: inline-flex; align-items: center; justify-content: center;
        width: 30px; height: 30px; flex: none;
        border: 1px solid var(--ad-border); border-radius: 8px; color: var(--ad-text);
    }
    .mp-glyph img, .mp-glyph svg { width: 18px; height: 18px; object-fit: contain; }
    .mp-iconcell { display: flex; align-items: center; gap: 8px; min-width: 0; }
    .mp-iconcell .sik-select { min-width: 0; }
    .mp-badgecell { display: flex; align-items: center; gap: 6px; min-width: 0; }
    .mp-badgecell .sik-input { min-width: 0; }
    .mp-badgecell input[type="color"] { flex: none; width: 34px; height: 34px; padding: 2px; border: 1px solid var(--ad-border); border-radius: 8px; background: #fff; }
    /* A colour means nothing until the row has a badge. The UA's own disabled
       styling for a colour input is too faint to read as "not in use". */
    .mp-badgecell input[type="color"]:disabled { opacity: .3; cursor: not-allowed; }
    .mp-order { width: 64px; flex: none; text-align: center; }
    .mp-bank { display: none; }

    /* --- the preview ---
       .sik-mega is a dropdown: absolutely positioned, transparent and
       visibility:hidden until its trigger opens it. Those four declarations are
       the only thing overridden, and only inside this well — everything the
       panel is made of (the head, the rows, the glyphs, the chips, the promo
       tile) is app.css untouched, which is the point of previewing it here. */
    .mp-preview {
        padding: 16px; border-radius: 10px; background: var(--ad-bg);
        /* The panel is 368px wide and the sidebar column can be narrower than
           that. It scrolls sideways in its own well rather than being squeezed:
           a preview shown at a width the store never uses is not a preview. */
        overflow-x: auto;
    }
    .mp-preview .sik-mega {
        position: static; opacity: 1; visibility: visible; transform: none;
        border-top: 1px solid var(--sik-border); border-radius: var(--sik-radius);
        /* Left-aligned, not centred: `margin: 0 auto` on a child wider than its
           scroll container puts the overflow on BOTH sides, and the left half
           of a scroll container cannot be reached. */
        margin: 0;
    }
</style>

<div class="ad-grid ad-grid--sidebar">
    <div style="display:grid;gap:16px">
        <div class="ad-card" style="margin:0">
            <div class="ad-card__head">
                <div>
                    <div class="ad-card__title">Rows in this panel</div>
                    <div class="ad-card__sub">
                        Drag a row by its handle, move it with the arrows, or type a position. Hiding a
                        row here is the same switch as Status on the item itself — the row keeps
                        everything else it has.
                    </div>
                </div>
            </div>

            <?php if ((int) $panel['is_mega'] !== 1): ?>
                <div class="ad-card__body">
                    <?php // One wrapping <div>: .sik-alert is a flex row, so bare text,
                          // <strong> and <a> each became a column of their own and the
                          // sentence read in three narrow strips. ?>
                    <div class="sik-alert sik-alert--warning">
                        <div>
                            <strong><?= e($panel['label']) ?></strong> is not set to render as a mega panel, so its
                            children appear as a plain dropdown. The rows below still control that list, but the
                            &ldquo;Shop all&rdquo; header and the promo tile only exist on a mega panel —
                            <a href="<?= e($editUrl) ?>#menuForm">turn it on in the item editor</a>.
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($rows === []): ?>
                <div class="ad-card__body">
                    <?= admin_empty(
                        'This panel has no rows yet',
                        'A mega panel is built from the child items beneath it. Add the first one and it appears in the navigation straight away.',
                        'Add a row',
                        admin_url('menus/?location=' . urlencode($location) . '&item=new&parent=' . $itemId),
                        'list'
                    ) ?>
                </div>
            <?php else: ?>
                <form method="post" action="<?= e(admin_url('menus/panel-save.php')) ?>" data-guard-unsaved>
                    <?= csrf_field() ?>
                    <input type="hidden" name="item" value="<?= $itemId ?>">

                    <div class="ad-card__body">
                        <?php
                        // Rendered once and cloned by the icon selects below, so
                        // ninety glyphs are in the page a single time however
                        // many rows the panel has.
                        ?>
                        <div class="mp-bank" data-glyph-bank aria-hidden="true">
                            <?php foreach (icon_names() as $bankName): ?>
                                <span data-glyph="<?= e_attr($bankName) ?>"><?= icon($bankName, 'w-4 h-4') ?></span>
                            <?php endforeach; ?>
                        </div>

                        <ul class="mp-list" data-panel-rows>
                            <?php foreach ($rows as $index => $row): ?>
                                <?php
                                $rowId    = (int) $row['id'];
                                $rowIcon  = (string) ($row['icon'] ?? '');
                                $isUpload = icon_is_upload($rowIcon);
                                $kids     = (int) ($grandchildren[$rowId] ?? 0);
                                ?>
                                <li class="mp-row<?= $row['status'] === 'active' ? '' : ' is-off' ?>"
                                    data-panel-row data-name="<?= e_attr($row['label']) ?>">
                                    <?php // Not focusable and hidden from assistive tech on
                                          // purpose: the arrows beside the position are the
                                          // keyboard and screen-reader way to move a row. ?>
                                    <span class="mp-grip" data-grip aria-hidden="true" title="Drag to reorder">
                                        <?= icon('dots', 'w-4 h-4') ?>
                                    </span>

                                    <span class="mp-pos">
                                        <input class="sik-input mp-order" type="number" min="0" max="9999" step="1"
                                               name="sort[<?= $rowId ?>]" value="<?= $index + 1 ?>" data-order
                                               aria-label="Position of <?= e_attr($row['label']) ?>">
                                        <?php // No name, so they post nothing: the position
                                              // input above stays the only thing saved. ?>
                                        <span class="ad-movebtns">
                                            <button type="button" data-move="up"
                                                    aria-label="Move <?= e_attr($row['label']) ?> up">
                                                <?= icon('chevron-up', 'w-4 h-4') ?>
                                            </button>
                                            <button type="button" data-move="down"
                                                    aria-label="Move <?= e_attr($row['label']) ?> down">
                                                <?= icon('chevron-down', 'w-4 h-4') ?>
                                            </button>
                                        </span>
                                    </span>

                                    <span class="mp-row__name">
                                        <a href="<?= e(admin_url('menus/?location=' . urlencode($location) . '&item=' . $rowId)) ?>#menuForm">
                                            <?= e($row['label']) ?>
                                        </a>
                                        <span class="mp-row__meta">
                                            <?= e(str_limit(menu_item_target($row), 30)) ?>
                                            <?php if ($kids > 0): ?>
                                                &middot; opens <?= $kids ?> sub-row<?= $kids === 1 ? '' : 's' ?>
                                            <?php endif; ?>
                                        </span>
                                    </span>

                                    <span class="mp-iconcell">
                                        <span class="mp-glyph" data-glyph-slot aria-hidden="true">
                                            <?= menu_glyph($rowIcon, 'w-4 h-4') ?>
                                        </span>
                                        <label class="sik-sr" for="mpIcon<?= $rowId ?>">Icon for <?= e($row['label']) ?></label>
                                        <select class="sik-select" id="mpIcon<?= $rowId ?>" name="icon[<?= $rowId ?>]" data-icon-select>
                                            <option value="">No icon</option>
                                            <?php if ($isUpload): ?>
                                                <?php // The uploaded file is managed in the item
                                                      // editor; here it is a value to keep or drop. ?>
                                                <option value="__upload__" selected>Custom upload</option>
                                            <?php endif; ?>
                                            <?php foreach (icon_groups() as $groupName => $groupIcons): ?>
                                                <optgroup label="<?= e_attr($groupName) ?>">
                                                    <?php foreach ($groupIcons as $iconName): ?>
                                                        <option value="<?= e_attr($iconName) ?>"
                                                                <?= (!$isUpload && $rowIcon === $iconName) ? 'selected' : '' ?>>
                                                            <?= e($iconName) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </optgroup>
                                            <?php endforeach; ?>
                                        </select>
                                    </span>

                                    <span class="mp-badgecell">
                                        <label class="sik-sr" for="mpBadge<?= $rowId ?>">Badge for <?= e($row['label']) ?></label>
                                        <input class="sik-input" type="text" id="mpBadge<?= $rowId ?>"
                                               name="badge[<?= $rowId ?>]" maxlength="30" placeholder="No badge"
                                               value="<?= e($row['badge'] ?? '') ?>">
                                        <?php // Disabled while the row has no badge, so an untouched
                                              // default swatch cannot read as "a colour is set here".
                                              // A disabled input posts nothing, and panel-save.php
                                              // already writes the pair together or not at all. ?>
                                        <input type="color" name="badge_color[<?= $rowId ?>]" data-badge-color
                                               aria-label="Badge colour for <?= e_attr($row['label']) ?>"
                                               <?= trim((string) ($row['badge'] ?? '')) === '' ? 'disabled' : '' ?>
                                               value="<?= e(preg_match('/^#[0-9a-f]{6}$/i', (string) ($row['badge_color'] ?? '')) === 1 ? $row['badge_color'] : '#f4511e') ?>">
                                    </span>

                                    <label class="ad-switch">
                                        <?php // The unchecked box posts nothing, so the hidden
                                              // partner is what tells panel-save.php that this row
                                              // was on the screen and was deliberately switched off. ?>
                                        <input type="hidden" name="present[<?= $rowId ?>]" value="1">
                                        <input type="checkbox" name="show[<?= $rowId ?>]" value="1"
                                               data-row-show <?= $row['status'] === 'active' ? 'checked' : '' ?>>
                                        <span class="ad-switch__track"></span>
                                        <span class="sik-sr">Show <?= e($row['label']) ?> in the panel</span>
                                    </label>
                                </li>
                            <?php endforeach; ?>
                        </ul>

                        <?php if (isset($errors['rows'])): ?>
                            <span class="sik-error"><?= e($errors['rows']) ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="ad-card__foot">
                        <a class="ad-btn" href="<?= e($listUrl) ?>">Back to the menu</a>
                        <button type="submit" class="ad-btn ad-btn--primary">
                            <?= icon('check', 'w-4 h-4') ?> Save panel
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        </div>

        <?php
        // The preview lives in the WIDE column, not the sidebar. The panel is
        // 368px across and the sidebar is narrower than that, which would leave
        // the badges — the right-hand edge of every row — permanently off-screen
        // behind a horizontal scroll. Here it fits whole, directly under the
        // rows it is a picture of.
        ?>
        <div class="ad-card" style="margin:0">
            <div class="ad-card__head">
                <div>
                    <div class="ad-card__title">Preview</div>
                    <div class="ad-card__sub">
                        The panel as the storefront renders it, from
                        <span class="ad-mono">includes/mega-panel.php</span> — the same file the header
                        uses. It shows what is <em>saved</em>, so save to see a change here.
                    </div>
                </div>
            </div>
            <div class="ad-card__body">
                <?php if ($liveNode === null): ?>
                    <div class="sik-alert sik-alert--warning">
                        This item is not currently in the storefront navigation, so there is no panel to
                        show. Its own status, its menu&rsquo;s status, or — for a category item — Admin
                        &gt; Categories&rsquo; &ldquo;Show in the main menu&rdquo; is switching it off.
                    </div>
                <?php elseif (empty($liveNode['children'])): ?>
                    <div class="sik-alert sik-alert--warning">
                        Every row in this panel is hidden from the desktop bar, so it renders nothing.
                    </div>
                <?php else: ?>
                    <div class="mp-preview">
                        <?php
                        $megaItem     = $liveNode;
                        $megaPanelId  = 'mpPreviewPanel';
                        $megaEndClass = '';
                        $megaExtra    = '';
                        require INCLUDES_PATH . '/mega-panel.php';
                        unset($megaItem, $megaPanelId);
                        ?>
                    </div>
                    <p class="sik-help" style="margin:10px 0 0">
                        Shown at its widest (368px, the panel&rsquo;s size from 1440px up). Below 1024px this
                        item is drawn by the mobile drawer instead, which lists the same rows.
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div style="display:grid;gap:16px;align-content:start">
        <div class="ad-card" style="margin:0">
            <div class="ad-card__head"><div class="ad-card__title">Promo tile</div></div>
            <div class="ad-card__body">
                <?php if ((int) $panel['mega_promo'] === 1 && !empty($panel['mega_image'])): ?>
                    <p class="sik-help" style="margin:0 0 10px">
                        On. The tile sits below the row list and stays put while a long list scrolls
                        past it.
                    </p>
                    <img src="<?= e(img_url((string) $panel['mega_image'])) ?>" alt=""
                         style="display:block;width:100%;border-radius:8px">
                <?php elseif (!empty($panel['mega_image'])): ?>
                    <p class="sik-help" style="margin:0">
                        A promo image is saved against this panel but the tile is switched off, so
                        nothing is drawn.
                    </p>
                <?php else: ?>
                    <p class="sik-help" style="margin:0">
                        No promo image. Upload one in the item editor and switch the tile on to add a
                        banner to the bottom of this panel.
                    </p>
                <?php endif; ?>
                <a class="ad-btn ad-btn--sm" style="margin-top:12px" href="<?= e($editUrl) ?>#menuForm">
                    <?= icon('edit', 'w-4 h-4') ?> Promo settings
                </a>
            </div>
        </div>
    </div>
</div>

<script>
    /* ----------------------------------------------------------------------
       Reordering.

       The number inputs are the control; dragging and the arrows are
       shortcuts that rewrite them. They are what is posted, so a drag or an
       arrow press changes exactly the `sort[id]` values a typed number would,
       and panel-save.php sees no difference between the three.

       The drag is Admin.sortable() in admin.js - Pointer Events, so it works
       under a finger. The HTML5 drag-and-drop it replaces never fired from a
       touch screen, which left typing numbers as the only way to reorder a
       panel on a phone or tablet.
       ---------------------------------------------------------------------- */
    (function () {
        var list = document.querySelector('[data-panel-rows]');
        if (!list) return;

        function renumber() {
            Array.prototype.forEach.call(list.querySelectorAll('[data-order]'), function (input, index) {
                input.value = index + 1;
            });
        }

        // admin.js is deferred, so SIK.admin exists once the document has
        // parsed - not yet while this inline script runs.
        document.addEventListener('DOMContentLoaded', function () {
            SIK.admin.sortable(list, {
                row: '[data-panel-row]',
                onChange: function () {
                    renumber();
                    // Values set from script fire no event, so without this the
                    // unsaved-changes guard would let a reordered panel be
                    // walked away from without a word.
                    list.dispatchEvent(new Event('change', { bubbles: true }));
                }
            });
        });

        // Typing a number is the other half of the same control: sorting the
        // DOM to match keeps the list and the values telling one story.
        list.addEventListener('change', function (e) {
            if (!e.target.matches('[data-order]')) return;
            var rows = Array.prototype.slice.call(list.querySelectorAll('[data-panel-row]'));
            rows.sort(function (a, b) {
                return (parseInt(a.querySelector('[data-order]').value, 10) || 0)
                     - (parseInt(b.querySelector('[data-order]').value, 10) || 0);
            });
            rows.forEach(function (row) { list.appendChild(row); });
            renumber();
        });

        // The icon select's own preview: cloned from the one bank of glyphs at
        // the top of the form, so nothing is fetched and nothing is redrawn.
        var bank = document.querySelector('[data-glyph-bank]');
        list.addEventListener('change', function (e) {
            if (!e.target.matches('[data-icon-select]') || !bank) return;
            var slot = e.target.closest('[data-panel-row]').querySelector('[data-glyph-slot]');
            if (!slot) return;
            // "Custom upload" has no entry in the bank — its file is managed in
            // the item editor, so the slot keeps whatever it is showing.
            if (e.target.value === '__upload__') return;
            var source = e.target.value === '' ? null : bank.querySelector('[data-glyph="' + e.target.value + '"]');
            slot.innerHTML = source ? source.innerHTML : '';
        });

        list.addEventListener('change', function (e) {
            if (!e.target.matches('[data-row-show]')) return;
            e.target.closest('[data-panel-row]').classList.toggle('is-off', !e.target.checked);
        });

        // The colour only means something once there is a badge to paint.
        list.addEventListener('input', function (e) {
            if (!e.target.matches('[name^="badge["]')) return;
            var color = e.target.closest('[data-panel-row]').querySelector('[data-badge-color]');
            if (color) color.disabled = e.target.value.trim() === '';
        });
    })();
</script>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
