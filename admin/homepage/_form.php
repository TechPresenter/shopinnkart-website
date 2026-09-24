<?php
/**
 * ShopInnKart Admin - Homepage section form body.
 *
 * Shared by create.php and edit.php. Both set:
 *   $section   field values (existing row, or defaults merged with input)
 *   $errors    field => message
 *   $isEdit    bool
 *
 * Every column on `homepage_sections` is editable here. The controls that a
 * widget type ignores are still shown but explained, because hiding them
 * outright makes a saved value invisible when the type changes later.
 */

declare(strict_types=1);

// Include-only: the parent page already ran the auth and permission checks.
if (!defined('SIK_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

// The rows -> items model. It lives outside the admin because the storefront
// renders from the same file; nothing here reimplements any of it.
require_once INCLUDES_PATH . '/homepage-rows.php';

/** @var array $section @var array $errors @var bool $isEdit */
$isEdit  = $isEdit ?? false;
$errors  = $errors ?? [];
$types   = homepage_widget_types();
$lists   = homepage_source_lists();
$type    = (string) ($section['widget_type'] ?? 'product_grid');
$source  = (string) ($section['data_source'] ?? 'auto');
$picked  = homepage_manual_ids($section);
$sectionRows = homepage_rows_of($section);

/** datetime-local wants Y-m-d\TH:i; the column stores a plain DATETIME. */
$dtValue = static function ($value): string {
    return empty($value) ? '' : date('Y-m-d\TH:i', (int) strtotime((string) $value));
};

$pickedProducts = [];
if ($picked !== []) {
    [$placeholders, $params] = Database::inPlaceholders($picked, 'p');
    $rows = Database::fetchAll(
        'SELECT `id`, `name`, `sku`, `main_image` FROM `products` WHERE `id` IN (' . $placeholders . ')',
        $params
    );
    $byId = [];
    foreach ($rows as $row) {
        $byId[(int) $row['id']] = $row;
    }
    // Keep the admin's chosen order, not the order MySQL returned them in.
    foreach ($picked as $id) {
        if (isset($byId[$id])) {
            $pickedProducts[] = $byId[$id];
        }
    }
}

// ---------------------------------------------------------------------------
//  Rows -> items
//
//  The list, the "add row" template and the "add item" template are all drawn
//  by the SAME two closures. A template that was a second copy of the markup
//  is a template that drifts, and the field a new row was missing would only
//  show up as a column quietly not saving.
//
//  $r and $i are the array indices the fields post under. The templates render
//  with the literal tokens __R__ / __I__, which the script swaps for real
//  numbers on insert and renumbers on every move - so a save without
//  JavaScript posts the server-rendered indices, and a save after a drag posts
//  whatever the DOM now reads.
// ---------------------------------------------------------------------------

/** Field name for a row-level control. */
$rowName = static fn (string $r, string $field): string => 'rows[' . $r . '][' . $field . ']';

/** Field name for an item-level control. */
$itemName = static fn (string $r, string $i, string $field): string
    => 'rows[' . $r . '][items][' . $i . '][' . $field . ']';

/** One item row inside a row. */
$renderItem = static function (string $r, string $i, array $item) use ($itemName): void {
    $payload = $item['payload'];
    $vis     = $item['visibility'];
    $label   = 'Item ' . ($i === '__I__' ? '' : (string) ((int) $i + 1));
    ?>
    <li class="hrx-item" data-hrx-item data-name="<?= e_attr(trim($label)) ?>">
        <div class="hrx-line">
            <button type="button" class="hrx-grip" data-grip
                    aria-label="Drag to reorder this item">
                <?= icon('grip', 'w-4 h-4') ?>
            </button>
            <span class="hrx-line__name" data-item-num><?= e(trim($label)) ?></span>
            <select class="sik-select hrx-mini" data-hrx-if="type"
                    name="<?= e_attr($itemName($r, $i, 'type')) ?>"
                    aria-label="What this item is">
                <?= admin_options(array_map(
                    static fn (array $meta): string => $meta['label'],
                    homepage_item_types()
                ), $item['type']) ?>
            </select>
            <span class="hrx-tools">
                <button type="button" data-move="up" aria-label="Move this item up">
                    <?= icon('chevron-up', 'w-3 h-3') ?>
                </button>
                <button type="button" data-move="down" aria-label="Move this item down">
                    <?= icon('chevron-down', 'w-3 h-3') ?>
                </button>
                <button type="button" class="hrx-del" data-del-item aria-label="Delete this item">
                    <?= icon('trash', 'w-3.5 h-3.5') ?>
                </button>
            </span>
        </div>

        <?php /* Collapsed by default: a row may hold two dozen items, and a
                 pane 370px wide cannot show two dozen open field sets. Every
                 control is still a real input, posted whether it is on screen
                 or not. */ ?>
        <details class="hrx-more">
            <summary>Edit item</summary>
            <div class="hrx-fields">
                <label class="sik-label">Title
                    <input class="sik-input" type="text" maxlength="150" data-hrx-if="payload][title"
                           name="<?= e_attr($itemName($r, $i, 'payload][title')) ?>"
                           value="<?= e($payload['title']) ?>">
                </label>
                <label class="sik-label">Link
                    <input class="sik-input" type="text" maxlength="255" data-hrx-if="payload][url"
                           name="<?= e_attr($itemName($r, $i, 'payload][url')) ?>"
                           value="<?= e($payload['url']) ?>" placeholder="shop.php?c=diyas">
                </label>
                <label class="sik-label">Image path
                    <input class="sik-input ad-mono" type="text" maxlength="255" data-hrx-if="payload][image"
                           name="<?= e_attr($itemName($r, $i, 'payload][image')) ?>"
                           value="<?= e($payload['image']) ?>" placeholder="uploads/widgets/diya.webp">
                    <span class="sik-help">Root-relative, under <span class="ad-mono">uploads/</span>.
                        Anything else is dropped on save.</span>
                </label>
                <label class="sik-label">Alt text
                    <input class="sik-input" type="text" maxlength="150" data-hrx-if="payload][alt"
                           name="<?= e_attr($itemName($r, $i, 'payload][alt')) ?>"
                           value="<?= e($payload['alt']) ?>">
                </label>
                <label class="sik-label">Product / category ID
                    <input class="sik-input" type="number" min="0" step="1" data-hrx-if="payload][ref_id"
                           name="<?= e_attr($itemName($r, $i, 'payload][ref_id')) ?>"
                           value="<?= (int) $payload['ref_id'] ?>">
                    <span class="sik-help">Only read by the Product and Category types.</span>
                </label>
                <label class="sik-label">Copy
                    <textarea class="sik-textarea" rows="2" data-hrx-if="payload][text"
                              name="<?= e_attr($itemName($r, $i, 'payload][text')) ?>"><?= e($payload['text']) ?></textarea>
                </label>
                <div class="hrx-trio">
                    <label class="sik-label">Shown
                        <select class="sik-select" data-hrx-if="visibility][status"
                                name="<?= e_attr($itemName($r, $i, 'visibility][status')) ?>">
                            <?= admin_options(['active' => 'Yes', 'inactive' => 'No'], $vis['status']) ?>
                        </select>
                    </label>
                    <label class="sik-label">Devices
                        <select class="sik-select" data-hrx-if="visibility][device_visibility"
                                name="<?= e_attr($itemName($r, $i, 'visibility][device_visibility')) ?>">
                            <?= admin_options(homepage_device_visibility(), $vis['device_visibility']) ?>
                        </select>
                    </label>
                    <label class="sik-label">Audience
                        <select class="sik-select" data-hrx-if="visibility][auth_visibility"
                                name="<?= e_attr($itemName($r, $i, 'visibility][auth_visibility')) ?>">
                            <?= admin_options(homepage_auth_visibility(), $vis['auth_visibility']) ?>
                        </select>
                    </label>
                </div>
            </div>
        </details>
    </li>
    <?php
};

/** One row, with its items nested inside it. */
$renderRow = static function (string $r, array $row) use ($rowName, $renderItem): void {
    $vis   = $row['visibility'];
    $label = 'Row ' . ($r === '__R__' ? '' : (string) ((int) $r + 1));
    ?>
    <li class="hrx-row" data-hrx-row data-name="<?= e_attr(trim($label)) ?>">
        <div class="hrx-line">
            <button type="button" class="hrx-grip" data-grip aria-label="Drag to reorder this row">
                <?= icon('grip', 'w-4 h-4') ?>
            </button>
            <span class="hrx-line__name">
                <span data-row-num><?= e(trim($label)) ?></span>
                <?php /* "Row 1 - 1 item - Grid", the line the reference shows. */ ?>
                <span class="hrx-line__sum" data-row-sum><?= e(homepage_row_summary($row)) ?></span>
            </span>
            <span class="hrx-tools">
                <button type="button" data-move="up" aria-label="Move this row up">
                    <?= icon('chevron-up', 'w-3 h-3') ?>
                </button>
                <button type="button" data-move="down" aria-label="Move this row down">
                    <?= icon('chevron-down', 'w-3 h-3') ?>
                </button>
                <button type="button" class="hrx-del" data-del-row aria-label="Delete this row">
                    <?= icon('trash', 'w-3.5 h-3.5') ?>
                </button>
            </span>
        </div>

        <div class="hrx-fields">
            <div class="hrx-duo">
                <label class="sik-label">Layout
                    <select class="sik-select" data-hrx-f="layout" data-row-layout
                            name="<?= e_attr($rowName($r, 'layout')) ?>">
                        <?= admin_options(array_map(
                            static fn (array $meta): string => $meta['label'],
                            homepage_row_layouts()
                        ), $row['layout']) ?>
                    </select>
                </label>
                <label class="sik-label" data-row-columns <?= $row['layout'] === 'banner' ? 'hidden' : '' ?>>
                    Columns
                    <input class="sik-input" type="number" min="1" max="6" step="1" data-hrx-f="columns"
                           name="<?= e_attr($rowName($r, 'columns')) ?>" value="<?= (int) $row['columns'] ?>">
                </label>
            </div>
            <div class="hrx-duo">
                <label class="sik-label">Gap
                    <select class="sik-select" data-hrx-f="gap" name="<?= e_attr($rowName($r, 'gap')) ?>">
                        <?= admin_options(homepage_row_gaps(), $row['gap']) ?>
                    </select>
                </label>
                <label class="sik-label">Shape
                    <select class="sik-select" data-hrx-f="aspect" name="<?= e_attr($rowName($r, 'aspect')) ?>">
                        <?= admin_options(homepage_row_aspects(), $row['aspect']) ?>
                    </select>
                </label>
            </div>

            <details class="hrx-more">
                <summary>Row heading &amp; visibility</summary>
                <div class="hrx-fields">
                    <label class="sik-label">Heading
                        <input class="sik-input" type="text" maxlength="150" data-hrx-f="title"
                               name="<?= e_attr($rowName($r, 'title')) ?>" value="<?= e($row['title']) ?>">
                    </label>
                    <div class="hrx-trio">
                        <label class="sik-label">Shown
                            <select class="sik-select" data-hrx-f="visibility][status"
                                    name="<?= e_attr($rowName($r, 'visibility][status')) ?>">
                                <?= admin_options(['active' => 'Yes', 'inactive' => 'No'], $vis['status']) ?>
                            </select>
                        </label>
                        <label class="sik-label">Devices
                            <select class="sik-select" data-hrx-f="visibility][device_visibility"
                                    name="<?= e_attr($rowName($r, 'visibility][device_visibility')) ?>">
                                <?= admin_options(homepage_device_visibility(), $vis['device_visibility']) ?>
                            </select>
                        </label>
                        <label class="sik-label">Audience
                            <select class="sik-select" data-hrx-f="visibility][auth_visibility"
                                    name="<?= e_attr($rowName($r, 'visibility][auth_visibility')) ?>">
                                <?= admin_options(homepage_auth_visibility(), $vis['auth_visibility']) ?>
                            </select>
                        </label>
                    </div>
                </div>
            </details>

            <ol class="hrx-items" data-itemlist>
                <?php foreach ($row['items'] as $itemIndex => $item): ?>
                    <?php $renderItem($r, (string) $itemIndex, $item); ?>
                <?php endforeach; ?>
            </ol>
            <p class="sik-sr" role="status" aria-live="polite" data-item-status></p>

            <button type="button" class="ad-btn ad-btn--sm" data-add-item>
                <?= icon('plus', 'w-3.5 h-3.5') ?> Add item
            </button>
        </div>
    </li>
    <?php
};

/** An empty row / item, used by both templates and by "Add". */
$blankNotes = [];
$blankRow  = homepage_rows_normalise([['layout' => 'grid', 'columns' => 3, 'items' => []]], $blankNotes)[0];
$blankItem = homepage_rows_normalise([['items' => [['type' => 'image']]]], $blankNotes)[0]['items'][0];
?>
<style>
    /* Scoped to this form. The type reference is long, so it scrolls inside
       its own box rather than pushing the save button off the screen. */
    .wh-help { display: grid; gap: 9px; max-height: 260px; overflow-y: auto; padding-right: 4px; }
    .wh-help__row { display: flex; gap: 9px; align-items: flex-start; font-size: 12.5px; line-height: 1.55; }
    .wh-help__row svg { flex: none; margin-top: 2px; color: var(--ad-muted); }
    .wh-help__row strong { display: block; color: var(--ad-text); }
    .wh-help__row.is-current { color: var(--ad-text); }
    .wh-help__row.is-current strong { color: var(--ad-primary); }
    .wh-pick { display: grid; gap: 6px; }
    .wh-pick__results { position: relative; display: grid; gap: 2px; }
    .wh-pick__results .ad-dropdown__item { width: 100%; text-align: left; }

    /* ---- the rows editor ------------------------------------------------
       Built to survive a 370px inspector pane as well as the full-page form,
       so everything stacks and nothing relies on a second column. */
    .hrx-rows, .hrx-items { list-style: none; margin: 0; padding: 0; display: grid; gap: 8px; }
    .hrx-items { margin-top: 4px; }
    .hrx-row {
        border: 1px solid var(--ad-border);
        border-radius: 10px;
        background: var(--ad-surface, transparent);
        padding: 8px;
    }
    .hrx-item {
        border: 1px solid var(--ad-border);
        border-radius: 8px;
        padding: 6px 6px 6px 2px;
    }
    .hrx-line { display: flex; align-items: center; gap: 6px; min-width: 0; }
    .hrx-line__name {
        flex: 1; min-width: 0;
        display: grid;
        font-size: 12.5px; font-weight: 600; line-height: 1.35;
    }
    .hrx-line__sum { font-weight: 500; font-size: 11.5px; color: var(--ad-muted); }
    .hrx-grip {
        flex: none; display: grid; place-items: center;
        width: 26px; height: 26px; padding: 0;
        border: 0; background: transparent; color: var(--ad-muted);
        cursor: grab;
        /* manipulation, not none: the grips form a tall strip down the left of
           the list, and touch-action:none there would make a flick inside it
           unable to scroll the page at all. admin.js arms a finger drag with a
           deliberate hold instead. */
        touch-action: manipulation;
    }
    .hrx-row.is-dragging, .hrx-item.is-dragging { opacity: .5; }
    .hrx-tools { flex: none; display: flex; align-items: center; gap: 2px; }
    .hrx-tools button {
        display: grid; place-items: center;
        width: 24px; height: 24px; padding: 0;
        border: 1px solid var(--ad-border); border-radius: 6px;
        background: transparent; color: var(--ad-muted); cursor: pointer;
    }
    .hrx-tools button:hover { color: var(--ad-text); }
    .hrx-tools button[aria-disabled="true"] { opacity: .35; cursor: default; }
    .hrx-tools .hrx-del:hover { color: var(--ad-danger, #DC2626); border-color: currentColor; }
    .hrx-fields { display: grid; gap: 8px; margin-top: 8px; }
    .hrx-fields .sik-label { display: grid; gap: 4px; font-size: 12px; }
    .hrx-duo, .hrx-trio { display: grid; gap: 8px; grid-template-columns: 1fr 1fr; }
    .hrx-trio { grid-template-columns: 1fr; }
    @media (min-width: 720px) { .hrx-trio { grid-template-columns: repeat(3, 1fr); } }
    /* The inspector pane is ~370px wide whatever the viewport is, so its
       columns have to collapse on the pane's width rather than the page's -
       a media query would keep two 150px selects side by side on a desktop. */
    .ad-insp .hrx-duo, .ad-insp .hrx-trio { grid-template-columns: 1fr; }
    .hrx-mini { flex: none; width: 108px; font-size: 12px; padding-block: 4px; }
    .hrx-more > summary {
        cursor: pointer; font-size: 12px; font-weight: 600;
        color: var(--ad-muted); padding: 4px 0; list-style-position: inside;
    }
    .hrx-more > summary:hover { color: var(--ad-text); }
    .hrx-foot { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin-top: 10px; }
    .hrx-none { margin: 0; }
</style>

<form class="ad-form" method="post" enctype="multipart/form-data" data-guard-unsaved
      action="<?= e($formAction ?? '') ?>">
    <?= csrf_field() ?>

    <div class="ad-grid ad-grid--sidebar">
        <div style="display:grid;gap:16px">

            <!-- ============================ Placement =========================== -->
            <div class="ad-card" data-insp="content" style="margin:0">
                <div class="ad-card__head">
                    <div class="ad-card__title">Placement</div>
                </div>
                <div class="ad-card__body">
                    <div class="ad-row ad-row--2">
                        <div class="ad-field">
                            <label class="sik-label" for="whZone">Zone <span class="req">*</span></label>
                            <select class="sik-select<?= isset($errors['zone']) ? ' is-invalid' : '' ?>"
                                    id="whZone" name="zone" required>
                                <?php foreach (homepage_zones() as $key => $meta): ?>
                                    <option value="<?= e_attr($key) ?>"
                                        <?= (string) ($section['zone'] ?? 'home') === $key ? 'selected' : '' ?>>
                                        <?= e($meta['label']) ?> — <?= e($meta['sub']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (isset($errors['zone'])): ?>
                                <span class="sik-error"><?= e($errors['zone']) ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="ad-field">
                            <label class="sik-label" for="whType">Widget type <span class="req">*</span></label>
                            <select class="sik-select<?= isset($errors['widget_type']) ? ' is-invalid' : '' ?>"
                                    id="whType" name="widget_type" required data-widget-type>
                                <?= admin_options(array_map(
                                    static fn (array $meta): string => $meta['label'],
                                    $types
                                ), $type) ?>
                            </select>
                            <?php if (isset($errors['widget_type'])): ?>
                                <span class="sik-error"><?= e($errors['widget_type']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="ad-field">
                        <label class="sik-label" for="whKey">Section key <span class="req">*</span></label>
                        <input class="sik-input ad-mono<?= isset($errors['section_key']) ? ' is-invalid' : '' ?>"
                               type="text" id="whKey" name="section_key" maxlength="60" required data-slugify
                               value="<?= e($section['section_key'] ?? '') ?>" placeholder="home_best_sellers">
                        <?php if (isset($errors['section_key'])): ?>
                            <span class="sik-error"><?= e($errors['section_key']) ?></span>
                        <?php else: ?>
                            <?php // Also the handle the lazy-load endpoint fetches the section by. ?>
                            <span class="sik-help">
                                Unique. Becomes the anchor
                                <span class="ad-mono">#w-<?= e(($section['section_key'] ?? '') !== '' ? $section['section_key'] : 'your-key') ?></span>.
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- ============================= Content ============================ -->
            <div class="ad-card" data-insp="content" style="margin:0">
                <div class="ad-card__head">
                    <div class="ad-card__title">Heading &amp; copy</div>
                    <div class="ad-card__sub">The accent words render in the brand colour after the title.</div>
                </div>
                <div class="ad-card__body">
                    <div class="ad-row ad-row--2">
                        <div class="ad-field">
                            <label class="sik-label" for="whTitle">Title</label>
                            <input class="sik-input" type="text" id="whTitle" name="title" maxlength="150"
                                   data-slug-source="#whKey"
                                   value="<?= e($section['title'] ?? '') ?>" placeholder="BEST">
                        </div>
                        <div class="ad-field">
                            <label class="sik-label" for="whAccent">Accent words</label>
                            <input class="sik-input" type="text" id="whAccent" name="title_accent" maxlength="150"
                                   value="<?= e($section['title_accent'] ?? '') ?>" placeholder="SELLERS">
                        </div>
                    </div>

                    <div class="ad-field">
                        <label class="sik-label" for="whSubtitle">Subtitle</label>
                        <input class="sik-input" type="text" id="whSubtitle" name="subtitle" maxlength="255"
                               value="<?= e($section['subtitle'] ?? '') ?>"
                               placeholder="What shoppers are buying most this week">
                    </div>

                    <div class="ad-field">
                        <label class="sik-label" for="whDescription">Description</label>
                        <textarea class="sik-textarea" id="whDescription" name="description" rows="3"
                                  placeholder="Longer copy, used by the widget types that have room for it."><?= e($section['description'] ?? '') ?></textarea>
                        <span class="sik-help">Basic HTML. Scripts and event handlers are stripped on save.</span>
                    </div>

                    <div class="ad-row ad-row--2">
                        <div class="ad-field">
                            <label class="sik-label" for="whLinkText">Link text</label>
                            <input class="sik-input" type="text" id="whLinkText" name="link_text" maxlength="60"
                                   value="<?= e($section['link_text'] ?? '') ?>" placeholder="VIEW ALL">
                        </div>
                        <div class="ad-field">
                            <label class="sik-label" for="whLinkUrl">Link URL</label>
                            <input class="sik-input<?= isset($errors['link_url']) ? ' is-invalid' : '' ?>" type="text"
                                   id="whLinkUrl" name="link_url" maxlength="255"
                                   value="<?= e($section['link_url'] ?? '') ?>" placeholder="shop.php?sort=best_selling">
                            <?php if (isset($errors['link_url'])): ?>
                                <span class="sik-error"><?= e($errors['link_url']) ?></span>
                            <?php else: ?>
                                <span class="sik-help">Both fields are needed for the link to render.</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ============================== Rows ============================== -->
            <?php /* The nested level: a section holds rows, a row holds items,
                     and the layout is chosen per row. Stored in the section's
                     `settings` JSON - see includes/homepage-rows.php - so no
                     section that has none is affected in any way. */ ?>
            <div class="ad-card" data-insp="content" style="margin:0">
                <div class="ad-card__head">
                    <div class="ad-card__title">Rows</div>
                    <div class="ad-card__sub">Leave empty and the widget type renders as it always has.</div>
                </div>
                <div class="ad-card__body">
                    <?php /* The marker that says "this post is about rows". A save from
                             any form WITHOUT it leaves the stored rows alone, which is
                             what keeps an unrelated endpoint from wiping them. */ ?>
                    <input type="hidden" name="rows_present" value="1">

                    <ol class="hrx-rows" data-rowlist>
                        <?php foreach ($sectionRows as $rowIndex => $row): ?>
                            <?php $renderRow((string) $rowIndex, $row); ?>
                        <?php endforeach; ?>
                    </ol>

                    <p class="ad-insp__empty hrx-none" data-rows-empty
                       <?= $sectionRows === [] ? '' : 'hidden' ?>>
                        No rows yet.
                    </p>

                    <!-- Reorder outcomes are announced: a drag that silently failed
                         to move anything looks exactly like one that worked. -->
                    <p class="sik-sr" role="status" aria-live="polite" data-rows-status></p>

                    <div class="hrx-foot">
                        <button type="button" class="ad-btn ad-btn--sm ad-btn--primary" data-add-row>
                            <?= icon('plus', 'w-3.5 h-3.5') ?> Add row
                        </button>
                        <?php /* The second sentence is not padding: one form post carries a
                                 fixed number of fields (php.ini's max_input_vars), and a row
                                 costs 8 of them while an item costs 10. A save that crosses
                                 the budget is refused outright rather than written with half
                                 the section's columns reset, so the number is worth printing
                                 where the rows are built. */ ?>
                        <span class="sik-help" style="margin:0">
                            Up to <?= HOMEPAGE_ROWS_MAX ?> rows, <?= HOMEPAGE_ROW_ITEMS_MAX ?> items each,
                            <?= homepage_rows_item_budget(HOMEPAGE_ROWS_MAX) ?> items in all. Bigger saves
                            are refused, not half-applied.
                        </span>
                    </div>

                    <?php /* Templates, drawn by the same closures as the live list so
                             the two can never drift. __R__ / __I__ are swapped for the
                             real indices on insert. */ ?>
                    <template data-row-tpl><?php $renderRow('__R__', $blankRow); ?></template>
                    <template data-item-tpl><?php $renderItem('__R__', '__I__', $blankItem); ?></template>
                </div>
            </div>

            <!-- =========================== Custom HTML ========================== -->
            <div class="ad-card" data-insp="content" style="margin:0" data-when-type="html"
                 <?= $type === 'html' ? '' : 'hidden' ?>>
                <?php // The card only renders while widget_type is "html", so a subtitle
                      // saying "only used by the Custom HTML widget type" said nothing. ?>
                <div class="ad-card__head">
                    <div class="ad-card__title">Custom HTML</div>
                </div>
                <div class="ad-card__body">
                    <div class="ad-field">
                        <label class="sik-label" for="whHtml">Markup</label>
                        <textarea class="sik-textarea ad-mono" id="whHtml" name="custom_html" rows="10"
                                  style="min-height:220px;font-size:var(--ad-text-sm)"
                                  placeholder="&lt;h2&gt;Anything you like&lt;/h2&gt;"><?= e($section['custom_html'] ?? '') ?></textarea>
                        <?php // Layout tags, links, images and tables survive the sanitiser. ?>
                        <span class="sik-help">
                            Sanitised on save: scripts, event handlers and
                            <code>javascript:</code> URLs are removed.
                        </span>
                    </div>
                </div>
            </div>

            <!-- ============================ Products ============================ -->
            <div class="ad-card" data-insp="content" style="margin:0">
                <div class="ad-card__head">
                    <div class="ad-card__title">Data source</div>
                </div>
                <div class="ad-card__body">
                    <div class="ad-row ad-row--2">
                        <div class="ad-field">
                            <label class="sik-label" for="whSource">Source</label>
                            <select class="sik-select" id="whSource" name="data_source" data-data-source>
                                <?= admin_options(homepage_data_sources(), $source) ?>
                            </select>
                            <span class="sik-help">Hero, ticker, trust, stats and testimonials ignore this.</span>
                        </div>

                        <div class="ad-field" data-source-row <?= in_array($source, homepage_lookup_sources(), true) ? '' : 'hidden' ?>>
                            <label class="sik-label" for="whSourceId">
                                <span data-source-label><?= e(ucfirst($source)) ?></span> <span class="req">*</span>
                            </label>
                            <select class="sik-select<?= isset($errors['source_id']) ? ' is-invalid' : '' ?>"
                                    id="whSourceId" name="source_id">
                                <option value="">— Select —</option>
                                <?php if (in_array($source, homepage_lookup_sources(), true)): ?>
                                    <?= admin_options($lists[$source], $section['source_id'] ?? null) ?>
                                <?php endif; ?>
                            </select>
                            <?php if (isset($errors['source_id'])): ?>
                                <span class="sik-error"><?= e($errors['source_id']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="ad-field" data-manual-row <?= $source === 'manual' ? '' : 'hidden' ?>>
                        <label class="sik-label" for="whProductSearch">Hand-picked products</label>
                        <div class="wh-pick">
                            <input class="sik-input" type="search" id="whProductSearch"
                                   data-product-search="#whProductResults"
                                   placeholder="Search by product name or SKU&hellip;" autocomplete="off">
                            <div class="wh-pick__results" id="whProductResults" data-product-results></div>
                            <div data-picked-products>
                                <?php foreach ($pickedProducts as $product): ?>
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
                        </div>
                        <?php // Saved into the section's settings as `product_ids`, in the order shown.
                              // The list is read only while data_source is "manual". ?>
                        <span class="sik-help">Used only while the source is &ldquo;Hand-picked products&rdquo;.</span>
                    </div>

                    <div class="ad-row ad-row--3">
                        <div class="ad-field">
                            <label class="sik-label" for="whLimit">Item limit</label>
                            <input class="sik-input<?= isset($errors['item_limit']) ? ' is-invalid' : '' ?>" type="number"
                                   id="whLimit" name="item_limit" min="1" max="48" step="1"
                                   value="<?= (int) ($section['item_limit'] ?? 8) ?>">
                            <?php if (isset($errors['item_limit'])): ?>
                                <span class="sik-error"><?= e($errors['item_limit']) ?></span>
                            <?php else: ?>
                                <span class="sik-help">Product queries cap at 24.</span>
                            <?php endif; ?>
                        </div>
                        <div class="ad-field">
                            <label class="sik-label" for="whLayout">Layout</label>
                            <select class="sik-select" id="whLayout" name="layout">
                                <?= admin_options(homepage_layouts(), $section['layout'] ?? 'grid') ?>
                            </select>
                        </div>
                        <div class="ad-field">
                            <label class="sik-label" for="whCardStyle">Card style</label>
                            <select class="sik-select" id="whCardStyle" name="card_style">
                                <?= admin_options(homepage_card_styles(), $section['card_style'] ?? 'standard') ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ============================= Artwork ============================ -->
            <div class="ad-card" data-insp="content" style="margin:0">
                <div class="ad-card__head">
                    <div class="ad-card__title">Artwork</div>
                    <div class="ad-card__sub">JPG, PNG, WEBP, GIF or SVG up to <?= e(format_bytes(MAX_UPLOAD_SIZE)) ?>.</div>
                </div>
                <div class="ad-card__body">
                    <div class="ad-row ad-row--2">
                        <div class="ad-field">
                            <label class="sik-label">Background / feature image</label>
                            <div class="ad-drop" data-drop="#whImagePreview">
                                <input type="file" name="image" accept="image/*">
                                <?= icon('upload', 'w-6 h-6') ?>
                                <div style="font-size:13px;margin-top:6px">Click or drop an image here</div>
                            </div>
                            <div class="ad-preview" id="whImagePreview">
                                <?php if (!empty($section['image'])): ?>
                                    <div class="ad-preview__item">
                                        <img src="<?= e(img_url($section['image'])) ?>" alt="Current section image">
                                        <button type="button" class="ad-preview__remove" data-remove-image="#whRemoveImage"
                                                aria-label="Remove image">&times;</button>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <input type="hidden" name="remove_image" id="whRemoveImage" value="0">
                        </div>

                        <div class="ad-field">
                            <label class="sik-label">Mobile image</label>
                            <div class="ad-drop" data-drop="#whMobilePreview">
                                <input type="file" name="mobile_image" accept="image/*">
                                <?= icon('upload', 'w-6 h-6') ?>
                                <div style="font-size:13px;margin-top:6px">Portrait crop for small screens</div>
                            </div>
                            <div class="ad-preview" id="whMobilePreview">
                                <?php if (!empty($section['mobile_image'])): ?>
                                    <div class="ad-preview__item">
                                        <img src="<?= e(img_url($section['mobile_image'])) ?>" alt="Current mobile image">
                                        <button type="button" class="ad-preview__remove" data-remove-image="#whRemoveMobile"
                                                aria-label="Remove mobile image">&times;</button>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <input type="hidden" name="remove_mobile_image" id="whRemoveMobile" value="0">
                        </div>
                    </div>
                </div>
            </div>

            <!-- ============================ Appearance ========================== -->
            <div class="ad-card" data-insp="style" style="margin:0">
                <div class="ad-card__head">
                    <div class="ad-card__title">Appearance</div>
                    <div class="ad-card__sub">Columns, colours and the carousel behaviour.</div>
                </div>
                <div class="ad-card__body">
                    <div class="ad-row ad-row--3">
                        <div class="ad-field">
                            <label class="sik-label" for="whColsDesktop">Columns — desktop</label>
                            <input class="sik-input<?= isset($errors['cols_desktop']) ? ' is-invalid' : '' ?>" type="number"
                                   id="whColsDesktop" name="cols_desktop" min="1" max="8" step="1"
                                   value="<?= (int) ($section['cols_desktop'] ?? 4) ?>">
                        </div>
                        <div class="ad-field">
                            <label class="sik-label" for="whColsTablet">Columns — tablet</label>
                            <input class="sik-input<?= isset($errors['cols_tablet']) ? ' is-invalid' : '' ?>" type="number"
                                   id="whColsTablet" name="cols_tablet" min="1" max="8" step="1"
                                   value="<?= (int) ($section['cols_tablet'] ?? 3) ?>">
                        </div>
                        <div class="ad-field">
                            <label class="sik-label" for="whColsMobile">Columns — mobile</label>
                            <input class="sik-input<?= isset($errors['cols_mobile']) ? ' is-invalid' : '' ?>" type="number"
                                   id="whColsMobile" name="cols_mobile" min="1" max="8" step="1"
                                   value="<?= (int) ($section['cols_mobile'] ?? 2) ?>">
                            <?php if (isset($errors['cols_mobile'])): ?>
                                <span class="sik-error"><?= e($errors['cols_mobile']) ?></span>
                            <?php else: ?>
                                <span class="sik-help">Also sets how many cards a rail shows at each breakpoint.</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <fieldset class="ad-fieldset" style="margin-bottom:14px">
                        <legend>Carousel</legend>
                        <div class="ad-row ad-row--2">
                            <div class="ad-field" style="display:grid;gap:10px;align-content:start">
                                <label class="ad-switch">
                                    <input type="checkbox" name="autoplay" value="1"
                                           <?= (int) ($section['autoplay'] ?? 0) === 1 ? 'checked' : '' ?>>
                                    <span class="ad-switch__track"></span>
                                    <span>Autoplay</span>
                                </label>
                                <label class="ad-switch">
                                    <input type="checkbox" name="show_arrows" value="1"
                                           <?= (int) ($section['show_arrows'] ?? 1) === 1 ? 'checked' : '' ?>>
                                    <span class="ad-switch__track"></span>
                                    <span>Show arrows</span>
                                </label>
                                <label class="ad-switch">
                                    <input type="checkbox" name="show_dots" value="1"
                                           <?= (int) ($section['show_dots'] ?? 1) === 1 ? 'checked' : '' ?>>
                                    <span class="ad-switch__track"></span>
                                    <span>Show dots</span>
                                </label>
                            </div>
                            <div class="ad-field">
                                <label class="sik-label" for="whSpeed">Autoplay speed (ms)</label>
                                <input class="sik-input<?= isset($errors['autoplay_speed']) ? ' is-invalid' : '' ?>"
                                       type="number" id="whSpeed" name="autoplay_speed" min="500" max="20000" step="100"
                                       value="<?= (int) ($section['autoplay_speed'] ?? 4000) ?>">
                                <?php if (isset($errors['autoplay_speed'])): ?>
                                    <span class="sik-error"><?= e($errors['autoplay_speed']) ?></span>
                                <?php else: ?>
                                    <span class="sik-help">Ignored while autoplay is off.</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </fieldset>

                    <div class="ad-row ad-row--2">
                        <div class="ad-field">
                            <label class="sik-label" for="whBgColor">Background colour</label>
                            <div class="ad-colorfield">
                                <input type="color" aria-label="Pick a background colour"
                                       value="<?= e(preg_match('/^#[0-9a-f]{6}$/i', (string) ($section['bg_color'] ?? '')) === 1 ? $section['bg_color'] : '#ffffff') ?>"
                                       data-color-for="#whBgColor">
                                <input class="sik-input ad-mono" type="text" id="whBgColor" name="bg_color" maxlength="20"
                                       value="<?= e($section['bg_color'] ?? '') ?>" placeholder="#F8FAFC">
                            </div>
                            <span class="sik-help">Leave blank to keep the theme default.</span>
                        </div>
                        <div class="ad-field">
                            <label class="sik-label" for="whTextColor">Text colour</label>
                            <div class="ad-colorfield">
                                <input type="color" aria-label="Pick a text colour"
                                       value="<?= e(preg_match('/^#[0-9a-f]{6}$/i', (string) ($section['text_color'] ?? '')) === 1 ? $section['text_color'] : '#0f2143') ?>"
                                       data-color-for="#whTextColor">
                                <input class="sik-input ad-mono" type="text" id="whTextColor" name="text_color" maxlength="20"
                                       value="<?= e($section['text_color'] ?? '') ?>" placeholder="#0F2143">
                            </div>
                        </div>
                    </div>

                    <div class="ad-row ad-row--3">
                        <div class="ad-field">
                            <label class="sik-label" for="whContainer">Container</label>
                            <select class="sik-select" id="whContainer" name="container">
                                <?= admin_options(homepage_containers(), $section['container'] ?? 'boxed') ?>
                            </select>
                        </div>
                        <div class="ad-field">
                            <label class="sik-label" for="whPadding">Vertical padding</label>
                            <select class="sik-select" id="whPadding" name="padding">
                                <?= admin_options(homepage_paddings(), $section['padding'] ?? 'md') ?>
                            </select>
                        </div>
                        <div class="ad-field">
                            <label class="sik-label" for="whAnimation">Entrance animation</label>
                            <select class="sik-select" id="whAnimation" name="animation">
                                <?= admin_options(homepage_animations(), $section['animation'] ?? 'fade-up') ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ============================== Sidebar ============================== -->
        <div style="display:grid;gap:16px;align-content:start">

            <div class="ad-card" data-insp="visibility" style="margin:0">
                <div class="ad-card__head"><div class="ad-card__title">Publish</div></div>
                <div class="ad-card__body" style="display:grid;gap:14px">
                    <div class="ad-field">
                        <label class="sik-label" for="whStatus">Status</label>
                        <select class="sik-select" id="whStatus" name="status">
                            <?= admin_options(['active' => 'Active', 'inactive' => 'Inactive'], $section['status'] ?? 'active') ?>
                        </select>
                        <span class="sik-help">Inactive sections disappear from the storefront completely.</span>
                    </div>

                    <div class="ad-field">
                        <label class="sik-label" for="whSortOrder">Sort order</label>
                        <input class="sik-input<?= isset($errors['sort_order']) ? ' is-invalid' : '' ?>" type="number"
                               id="whSortOrder" name="sort_order" min="0" max="9999" step="1"
                               value="<?= (int) ($section['sort_order'] ?? 0) ?>">
                        <?php if (isset($errors['sort_order'])): ?>
                            <span class="sik-error"><?= e($errors['sort_order']) ?></span>
                        <?php else: ?>
                            <span class="sik-help">Lower numbers render higher up the page.</span>
                        <?php endif; ?>
                    </div>

                    <label class="ad-switch">
                        <input type="checkbox" name="lazy_load" value="1"
                               <?= (int) ($section['lazy_load'] ?? 0) === 1 ? 'checked' : '' ?>>
                        <span class="ad-switch__track"></span>
                        <span>Load on scroll</span>
                    </label>
                    <?php // Renders an empty shell and fetches the contents over AJAX when the
                          // shell scrolls into view - worth it for a heavy section near the
                          // foot of the page, a visible delay for one above the fold. ?>
                    <span class="sik-help" style="margin-top:-6px">Leave off for anything above the fold.</span>
                </div>
                <div class="ad-card__foot">
                    <a class="ad-btn" href="<?= e(admin_url('homepage/?zone=' . urlencode((string) ($section['zone'] ?? 'home')))) ?>">Cancel</a>
                    <button type="submit" class="ad-btn ad-btn--primary">
                        <?= icon('check', 'w-4 h-4') ?>
                        <?= $isEdit ? 'Save Section' : 'Create Section' ?>
                    </button>
                </div>
            </div>

            <?php if ($isEdit && !empty($section['section_key'])): ?>
                <div class="ad-card" data-insp="none" style="margin:0">
                    <div class="ad-card__head"><div class="ad-card__title">Preview</div></div>
                    <div class="ad-card__body">
                        <p class="ad-muted" style="font-size:var(--ad-text-xs);margin-bottom:10px">
                            Opens the storefront at this section. Inactive or scheduled-out sections
                            are not rendered, so the anchor will not resolve.
                        </p>
                        <a class="ad-btn ad-btn--block" target="_blank" rel="noopener"
                           href="<?= e(homepage_preview_url($section)) ?>">
                            <?= icon('external', 'w-4 h-4') ?> Preview on storefront
                        </a>
                    </div>
                </div>
            <?php endif; ?>

            <div class="ad-card" data-insp="visibility" style="margin:0">
                <div class="ad-card__head"><div class="ad-card__title">Visibility</div></div>
                <div class="ad-card__body" style="display:grid;gap:14px">
                    <div class="ad-field">
                        <label class="sik-label" for="whDevice">Devices</label>
                        <select class="sik-select" id="whDevice" name="device_visibility">
                            <?= admin_options(homepage_device_visibility(), $section['device_visibility'] ?? 'all') ?>
                        </select>
                    </div>
                    <div class="ad-field">
                        <label class="sik-label" for="whAuth">Audience</label>
                        <select class="sik-select" id="whAuth" name="auth_visibility">
                            <?= admin_options(homepage_auth_visibility(), $section['auth_visibility'] ?? 'all') ?>
                        </select>
                        <span class="sik-help">Use "signed-out visitors" for sign-up prompts.</span>
                    </div>
                    <div class="ad-field">
                        <label class="sik-label" for="whStart">Starts</label>
                        <input class="sik-input<?= isset($errors['start_date']) ? ' is-invalid' : '' ?>"
                               type="datetime-local" id="whStart" name="start_date"
                               value="<?= e($dtValue($section['start_date'] ?? null)) ?>">
                    </div>
                    <div class="ad-field">
                        <label class="sik-label" for="whEnd">Ends</label>
                        <input class="sik-input<?= isset($errors['end_date']) ? ' is-invalid' : '' ?>"
                               type="datetime-local" id="whEnd" name="end_date"
                               value="<?= e($dtValue($section['end_date'] ?? null)) ?>">
                        <?php if (isset($errors['end_date'])): ?>
                            <span class="sik-error"><?= e($errors['end_date']) ?></span>
                        <?php else: ?>
                            <span class="sik-help">Leave both blank to run the section indefinitely.</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="ad-card" data-insp="content" style="margin:0">
                <div class="ad-card__head">
                    <div class="ad-card__title">What each type renders</div>
                    <div class="ad-card__sub">The selected type is highlighted.</div>
                </div>
                <div class="ad-card__body">
                    <div class="wh-help">
                        <?php foreach ($types as $key => $meta): ?>
                            <div class="wh-help__row <?= $key === $type ? 'is-current' : '' ?>" data-type-help="<?= e_attr($key) ?>">
                                <?= icon(homepage_widget_icon($key), 'w-4 h-4') ?>
                                <span><strong><?= e($meta['label']) ?></strong><?= e($meta['help']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php /* The LAST field in the form, and it has to stay last.
             php.ini's max_input_vars caps how many name=value pairs reach
             $_POST; PHP silently drops the rest rather than failing, and the
             rows fieldset is big enough to reach that ceiling. If this marker
             did not arrive, neither did everything between it and wherever
             PHP stopped - so homepage_rows_post_truncated() refuses the save
             instead of writing a section with half its columns reset to their
             defaults. See the note on that function. */ ?>
    <input type="hidden" name="form_complete" value="1">
</form>

<script>
    // The source picker, the HTML box and the type help all depend on two
    // selects. Everything still renders correctly server-side, so this only
    // saves a round trip.
    (function () {
        var lists = <?= e_json(homepage_source_lists()) ?>;
        var labels = { category: 'Category', brand: 'Brand', tag: 'Tag' };

        var typeSelect   = document.querySelector('[data-widget-type]');
        var sourceSelect = document.querySelector('[data-data-source]');
        var sourceRow    = document.querySelector('[data-source-row]');
        var sourceLabel  = document.querySelector('[data-source-label]');
        var sourceId     = document.getElementById('whSourceId');
        var manualRow    = document.querySelector('[data-manual-row]');

        function syncSource() {
            if (!sourceSelect) return;
            var value = sourceSelect.value;
            var isLookup = Object.prototype.hasOwnProperty.call(lists, value);

            if (manualRow) manualRow.hidden = value !== 'manual';
            if (sourceRow) sourceRow.hidden = !isLookup;
            if (!isLookup || !sourceId) return;

            if (sourceLabel) sourceLabel.textContent = labels[value] || 'Source';

            var current = sourceId.value;
            var options = ['<option value="">— Select —</option>'];
            Object.keys(lists[value]).forEach(function (id) {
                options.push('<option value="' + id + '">' + SIK.escapeHtml(lists[value][id]) + '</option>');
            });
            sourceId.innerHTML = options.join('');
            // Keep the saved id when the admin flips back to the same source.
            if (current) sourceId.value = current;
        }

        function syncType() {
            if (!typeSelect) return;
            var value = typeSelect.value;

            document.querySelectorAll('[data-when-type]').forEach(function (block) {
                block.hidden = block.dataset.whenType !== value;
            });
            document.querySelectorAll('[data-type-help]').forEach(function (row) {
                row.classList.toggle('is-current', row.dataset.typeHelp === value);
            });
        }

        if (typeSelect) typeSelect.addEventListener('change', syncType);
        if (sourceSelect) sourceSelect.addEventListener('change', syncSource);

        // Colour swatches write into the text field, which is what gets saved.
        document.querySelectorAll('[data-color-for]').forEach(function (picker) {
            var field = document.querySelector(picker.dataset.colorFor);
            if (!field) return;
            picker.addEventListener('input', function () { field.value = picker.value; });
            field.addEventListener('input', function () {
                if (/^#[0-9a-f]{6}$/i.test(field.value)) picker.value = field.value;
            });
        });
    })();
</script>

<script>
/* ---------------------------------------------------------------------------
   The rows editor.

   Reordering is NOT reimplemented here. assets/js/admin.js already owns one
   sortable list - pointer drag from [data-grip], plus [data-move="up|down"]
   buttons as the keyboard and screen-reader path - and it is exposed as
   SIK.admin.sortable(list, opts). That file belongs to another team this week,
   so this script drives it through the contract it already understands:

     - rows are direct children of the list and match opts.row
     - each carries [data-grip] and a data-name for the announcements
     - each carries [data-move="up"] / [data-move="down"]
     - opts.onChange fires once a row has landed somewhere new

   The one thing that contract does not cover is NESTING. Its handlers sit on
   the list element and match with closest(), so an event from an item's grip
   or move button, left to bubble, reaches the ROW list too and passes its
   `row.parentNode === list` test - grabbing an item would drag its whole row.
   So every item list stops those event types at itself. That is the only
   liberty taken, and it is taken on our own element, not in admin.js.

   Deferred to DOMContentLoaded because admin.js is a deferred script: it runs
   after the document is parsed and before that event, so SIK.admin exists by
   the time this does.
   --------------------------------------------------------------------------- */
document.addEventListener('DOMContentLoaded', function () {
    var rowList = document.querySelector('[data-rowlist]');
    if (!rowList) { return; }

    var form      = rowList.closest('form');
    var rowTpl    = form.querySelector('[data-row-tpl]');
    var itemTpl   = form.querySelector('[data-item-tpl]');
    var emptyNote = form.querySelector('[data-rows-empty]');
    var status    = form.querySelector('[data-rows-status]');
    var addRowBtn = form.querySelector('[data-add-row]');
    var scroller  = rowList.closest('.ad-designer__body');

    var MAX_ROWS  = <?= HOMEPAGE_ROWS_MAX ?>;
    var MAX_ITEMS = <?= HOMEPAGE_ROW_ITEMS_MAX ?>;

    var sortable = (window.SIK && SIK.admin && SIK.admin.sortable) || null;

    function say(message) { if (status) { status.textContent = message; } }

    /**
     * Adding, deleting or moving a row is an unsaved change, but none of those
     * fires input or change - so the form's existing unsaved-work guard would
     * let the admin navigate away from ten minutes of building. One synthetic
     * change on the form is all that guard needs, and needs no edit to it.
     */
    function markDirty() {
        form.dispatchEvent(new Event('change', { bubbles: false }));
    }

    function rows() {
        return Array.prototype.slice.call(rowList.querySelectorAll(':scope > [data-hrx-row]'));
    }
    function itemListOf(row) { return row.querySelector('[data-itemlist]'); }
    function itemsOf(row) {
        var list = itemListOf(row);
        return list ? Array.prototype.slice.call(list.querySelectorAll(':scope > [data-hrx-item]')) : [];
    }

    /** "1 item - Grid": the same sentence homepage_row_summary() prints. */
    function summarise(row) {
        var out = row.querySelector('[data-row-sum]');
        if (!out) { return; }
        var count = itemsOf(row).length;
        var pick = row.querySelector('[data-row-layout]');
        var label = (pick && pick.options[pick.selectedIndex]) ? pick.options[pick.selectedIndex].text : 'Grid';
        out.textContent = (count === 1 ? '1 item' : count + ' items') + ' - ' + label;
    }

    /**
     * Rewrite every field's name from its position in the DOM.
     *
     * This is what makes a drag mean something: PHP reads rows[0], rows[1] …
     * in the order the body carries them, and renumbering here means the post
     * says exactly what the screen says rather than relying on the parser's
     * ordering. It also keeps a template's __R__ / __I__ tokens from ever
     * reaching the server.
     */
    function renumber() {
        var all = rows();
        all.forEach(function (row, r) {
            var name = 'Row ' + (r + 1);
            row.dataset.name = name;
            var label = row.querySelector('[data-row-num]');
            if (label) { label.textContent = name; }

            row.querySelectorAll('[data-hrx-f]').forEach(function (field) {
                field.name = 'rows[' + r + '][' + field.dataset.hrxF + ']';
            });

            itemsOf(row).forEach(function (item, i) {
                var itemName = 'Item ' + (i + 1);
                item.dataset.name = itemName;
                var itemLabel = item.querySelector('[data-item-num]');
                if (itemLabel) { itemLabel.textContent = itemName; }

                item.querySelectorAll('[data-hrx-if]').forEach(function (field) {
                    field.name = 'rows[' + r + '][items][' + i + '][' + field.dataset.hrxIf + ']';
                });
            });

            summarise(row);
        });

        if (emptyNote) { emptyNote.hidden = all.length > 0; }
        if (addRowBtn) { addRowBtn.disabled = all.length >= MAX_ROWS; }
    }

    function fromTemplate(tpl) {
        return tpl.content.cloneNode(true).querySelector('[data-hrx-row], [data-hrx-item]');
    }

    /** Open a freshly added block and put the caret in it. */
    function reveal(node) {
        var more = node.querySelector('details');
        if (more) { more.open = true; }
        var first = node.querySelector('select, input, textarea');
        if (first) { first.focus(); }
    }

    function addItem(row) {
        var list = itemListOf(row);
        if (!list) { return; }
        if (itemsOf(row).length >= MAX_ITEMS) {
            say('A row holds at most ' + MAX_ITEMS + ' items.');
            return;
        }
        var node = fromTemplate(itemTpl);
        list.appendChild(node);
        renumber();
        markDirty();
        reveal(node);
        say('Item added to ' + row.dataset.name + '.');
    }

    /**
     * Wire one item list: the nesting guard, its own delete button, and the
     * shared sortable.
     */
    function wireItemList(list) {
        if (!list || list.dataset.hrxWired === '1') { return; }
        list.dataset.hrxWired = '1';

        // See the header: these are the event types the shared sortable listens
        // for, and an item's must not reach the row list above it.
        ['pointerdown', 'pointermove', 'pointerup', 'pointercancel',
         'lostpointercapture', 'touchmove', 'click'].forEach(function (type) {
            list.addEventListener(type, function (e) { e.stopPropagation(); });
        });

        // On THIS element, because nothing above it sees these clicks any more.
        list.addEventListener('click', function (e) {
            var button = e.target.closest('[data-del-item]');
            if (!button) { return; }
            var item = button.closest('[data-hrx-item]');
            if (!item) { return; }
            var owner = item.dataset.name;
            item.remove();
            renumber();
            markDirty();
            say(owner + ' deleted.');
        });

        if (sortable) {
            sortable(list, {
                row: '[data-hrx-item]',
                status: list.parentNode.querySelector('[data-item-status]'),
                scroller: scroller,
                onChange: function () { renumber(); markDirty(); }
            });
        }
    }

    // ---- the row list ----------------------------------------------------
    rowList.addEventListener('click', function (e) {
        var remove = e.target.closest('[data-del-row]');
        if (remove) {
            var row = remove.closest('[data-hrx-row]');
            var name = row.dataset.name;
            row.remove();
            renumber();
            markDirty();
            say(name + ' deleted.');
            return;
        }
        var add = e.target.closest('[data-add-item]');
        if (add) { addItem(add.closest('[data-hrx-row]')); }
    });

    rowList.addEventListener('change', function (e) {
        var row = e.target.closest('[data-hrx-row]');
        if (!row) { return; }
        if (e.target.matches('[data-row-layout]')) {
            // A banner is one thing across the width, so its column count is
            // not a question - the server forces it to 1 either way.
            var columns = row.querySelector('[data-row-columns]');
            if (columns) { columns.hidden = e.target.value === 'banner'; }
        }
        summarise(row);
    });

    if (addRowBtn) {
        addRowBtn.addEventListener('click', function () {
            if (rows().length >= MAX_ROWS) {
                say('A section holds at most ' + MAX_ROWS + ' rows.');
                return;
            }
            var node = fromTemplate(rowTpl);
            rowList.appendChild(node);
            wireItemList(itemListOf(node));
            renumber();
            markDirty();
            reveal(node);
            say(node.dataset.name + ' added.');
        });
    }

    if (sortable) {
        sortable(rowList, {
            row: '[data-hrx-row]',
            status: status,
            scroller: scroller,
            onChange: function () { renumber(); markDirty(); }
        });
    }

    rows().forEach(function (row) { wireItemList(itemListOf(row)); });
    renumber();
});
</script>
