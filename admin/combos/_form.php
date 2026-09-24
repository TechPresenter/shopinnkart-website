<?php
/**
 * ShopInnKart Admin - Combo form body.
 *
 * Shared by create.php and edit.php. Both set:
 *   $combo    field values (stored row, or defaults overlaid with the submit)
 *   $errors   field => message
 *   $isEdit   bool
 */

declare(strict_types=1);

// Include-only: the parent page already ran authentication and permissions.
// The .htaccess rule refuses /_*.php outright; this is the backstop for a
// host that does not read .htaccess at all.
if (!defined('SIK_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

/** @var array $combo @var array $errors @var bool $isEdit */
$isEdit  = $isEdit ?? false;
$errors  = $errors ?? [];
$comboId = (int) ($combo['id'] ?? 0);

$picked      = $combo['items'] ?? [];
$productRows = combo_form_products(array_column($picked, 'product_id'));

/*
 * Every number on this screen comes from the data layer. The synthetic item
 * array is what combo_pricing() and combo_available_sets() would be handed if
 * this form were saved right now, so combo_decorate() can hand back the very
 * card the shopper would see - price_display, regular_display, saving_display,
 * percent and badge - instead of the template guessing at them a second time.
 */
$previewItems = combo_form_synthetic_items($picked, $productRows);
$preview      = combo_decorate([
    'id'               => $comboId,
    'slug'             => (string) ($combo['slug'] ?? ''),
    'image'            => $combo['image'] ?? null,
    'pricing_mode'     => (string) ($combo['pricing_mode'] ?? 'fixed'),
    'price'            => (float) ($combo['price'] ?? 0),
    'discount_percent' => (float) ($combo['discount_percent'] ?? 0),
    'stock_mode'       => (string) ($combo['stock_mode'] ?? 'components'),
    'stock'            => ($combo['stock'] ?? '') === '' ? null : (int) $combo['stock'],
    'sold_count'       => (int) ($combo['sold_count'] ?? 0),
    'badge_text'       => (string) ($combo['badge_text'] ?? ''),
    'is_featured'      => (int) ($combo['is_featured'] ?? 0),
    'is_flash'         => (int) ($combo['is_flash'] ?? 0),
    'is_best_seller'   => (int) ($combo['is_best_seller'] ?? 0),
], $previewItems);

$gallery = $comboId > 0
    ? Database::fetchAll(
        'SELECT `id`, `image`, `alt_text` FROM `combo_images`
         WHERE `combo_id` = :id ORDER BY `sort_order`, `id`',
        ['id' => $comboId]
    )
    : [];

// marketing_state() knows two statuses, and reports anything that is not
// "active" as Inactive. A draft is not something somebody switched off, so it
// gets its own pill rather than being mislabelled.
$status = (string) ($combo['status'] ?? 'draft');
$state  = $status === STATUS_DRAFT
    ? ['key' => 'draft', 'label' => 'Draft', 'tone' => 'gray']
    : marketing_state(
        marketing_dt_save((string) ($combo['start_date'] ?? '')),
        marketing_dt_save((string) ($combo['end_date'] ?? '')),
        $status
    );

// The fallback badge labels are asked of combo_badge() itself - once per flag -
// rather than retyped here, so the preview can never advertise a label the
// storefront does not actually use. The order is combo_badge()'s own.
$badgeFallbacks = [];
foreach (['is_flash', 'is_best_seller', 'is_featured'] as $flag) {
    $fallback = combo_badge([$flag => 1]);
    if ($fallback !== null) {
        $badgeFallbacks[] = ['field' => $flag, 'label' => $fallback['label']];
    }
}

$currencySymbol = (string) setting('currency_symbol', CURRENCY_SYMBOL);

// No currency symbol in here: SIK.formatCurrency() already mirrors money(),
// Indian grouping and the currency-position setting included, so handing the
// browser a second copy of the symbol is how the two start disagreeing.
$formConfig = [
    'statuses' => combo_status_options(),
    'badges'   => $badgeFallbacks,
    // The two glyphs a JS-built picker row needs, rendered by icon() here
    // rather than pasted into the script as SVG: a copied path is a copy that
    // stops matching the rest of the admin the first time icons.php changes.
    'icons'    => [
        'grip'   => icon('dots', 'w-4 h-4'),
        'remove' => icon('close', 'w-4 h-4'),
        'up'     => icon('chevron-up', 'w-4 h-4'),
        'down'   => icon('chevron-down', 'w-4 h-4'),
    ],
];
?>
<style>
    /* Scoped to this form: the .ad-picker rules only exist on a page where
       marketing_product_picker() has printed them, and this form builds its
       own rows so each one can carry a quantity, a position and a drag grip.
       Only the handful of rules those rows need are re-declared. */
    .ad-picker { display: grid; gap: 10px; }
    .ad-picker__search { position: relative; }
    .ad-picker__search > svg { position: absolute; left: 11px; top: 50%; transform: translateY(-50%); color: var(--ad-muted); }
    .ad-picker__search .sik-input { padding-left: 34px; }
    .ad-picker__results {
        position: absolute; z-index: 30; left: 0; right: 0; top: calc(100% + 4px);
        background: #fff; border: 1px solid var(--ad-border); border-radius: 10px;
        box-shadow: 0 12px 30px rgba(16, 35, 61, .14); max-height: 300px; overflow-y: auto; padding: 5px;
    }
    .ad-picker__results[hidden] { display: none; }
    .ad-picker__results .ad-dropdown__item { width: 100%; text-align: left; }
    .ad-picker__list { display: grid; gap: 8px; }
    .ad-picker__row {
        display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
        padding: 9px 10px; border: 1px solid var(--ad-border); border-radius: 10px; background: var(--ad-bg);
    }
    .ad-picker__info { flex: 1; min-width: 150px; display: grid; }
    .ad-picker__field { display: grid; gap: 3px; font-size: 11px; color: var(--ad-muted); }
    .ad-picker__field .sik-input { padding: 7px 10px; font-size: 12.5px; width: 78px; }
    @media (max-width: 767px) { .ad-picker__field .sik-input { font-size: 16px; width: 64px; } }
    .ad-picker__empty { font-size: 13px; padding: 14px; text-align: center; border: 1px dashed var(--ad-border); border-radius: 10px; }
    .ad-picker__empty[hidden] { display: none; }
    .ad-picker__warn { color: #B45309; font-weight: 600; }

    /* Drag affordances, shared by the component list and the gallery. The
       grip is a full-height strip rather than a bare 16px glyph, so a finger
       can find it; touch-action comes from admin.css. The row being carried
       stays in place, faded, while Admin.sortable() draws where it will land. */
    .ad-grip {
        display: flex; align-items: center; justify-content: center; align-self: stretch;
        width: 28px; flex: none; border-radius: 7px;
        cursor: grab; color: var(--ad-muted);
    }
    .ad-grip:hover { background: #fff; color: var(--ad-text); }
    .ad-grip:active { cursor: grabbing; }
    .is-dragging { opacity: .4; }
    /* With a mouse the arrows stack into one 30px column, so the product name
       keeps the width it had before they existed; 24px each is still the
       WCAG 2.2 minimum target. A finger gets admin.css's 44px pair. */
    @media (min-width: 768px) and (pointer: fine) {
        .ad-picker__row > .ad-movebtns,
        .ad-gallery__row > .ad-movebtns { flex-direction: column; gap: 2px; }
        .ad-picker__row > .ad-movebtns button,
        .ad-gallery__row > .ad-movebtns button { width: 30px; height: 24px; }
    }

    /* Phones: the remove button takes the card's top-right corner, where a
       dismiss control is looked for, so the second line has the whole width
       for Qty, Order and the arrows. Left in the flow it wrapped onto a third
       line of its own at 360px. The name keeps clear of it. */
    @media (max-width: 767px) {
        .ad-picker__row { position: relative; }
        .ad-picker__row > [data-combo-remove] { position: absolute; top: 4px; right: 4px; }
        .ad-picker__row > .ad-picker__info { padding-right: 40px; }
        /* Level with the Qty/Order inputs on that second line, not with
           their labels. */
        .ad-picker__row > .ad-movebtns { align-self: flex-end; }
    }

    /* The live summary. `regular` is the struck-through number everywhere on
       this screen - never `mrp` - because most of this catalogue is already
       discounted and an MRP comparison would advertise a saving the shopper
       could get anyway by adding the items one at a time. */
    .ad-combosum { display: grid; gap: 6px; margin-top: 14px; font-size: 13.5px; }
    .ad-combosum__row { display: flex; justify-content: space-between; gap: 12px; }
    .ad-combosum__row span { color: var(--ad-muted); }
    .ad-combosum__row strong { font-variant-numeric: tabular-nums; }
    .ad-combosum__row--total { border-top: 1px solid var(--ad-border); padding-top: 6px; }
    .ad-combosum.is-flat .ad-combosum__row--total strong { color: #B45309; }

    /* Read-only stand-in for the storefront card. */
    .ad-comboprev { border: 1px solid var(--ad-border); border-radius: 12px; overflow: hidden; background: #fff; }
    .ad-comboprev__media { position: relative; background: var(--ad-bg); }
    .ad-comboprev__media img { display: block; width: 100%; height: 150px; object-fit: contain; }
    .ad-comboprev__badge {
        position: absolute; top: 9px; left: 9px; font-size: 10.5px; font-weight: 800;
        letter-spacing: .06em; text-transform: uppercase; padding: 4px 9px; border-radius: 999px;
        background: var(--ad-primary); color: #fff;
    }
    .ad-comboprev__badge:empty { display: none; }
    .ad-comboprev__body { padding: 12px; display: grid; gap: 5px; }
    .ad-comboprev__title { font-size: 14.5px; font-weight: 700; line-height: 1.3; }
    .ad-comboprev__title:empty::before { content: 'Untitled combo'; color: var(--ad-muted); }
    .ad-comboprev__sub { font-size: 12.5px; color: var(--ad-muted); }
    .ad-comboprev__sub:empty { display: none; }
    .ad-comboprev__prices { display: flex; align-items: baseline; gap: 8px; flex-wrap: wrap; }
    .ad-comboprev__prices strong { font-size: 17px; }
    .ad-comboprev__prices s { font-size: 13px; color: var(--ad-muted); }
    .ad-comboprev__off { font-size: 12px; font-weight: 700; color: #15803D; }
    .ad-comboprev__save { font-size: 12.5px; color: #15803D; font-weight: 600; }
    .ad-comboprev__meta { font-size: 11.5px; color: var(--ad-muted); }

    /* Saved gallery rows: one per line so the position input and the grip sit
       where they do in the component list. */
    .ad-gallery { display: grid; gap: 8px; margin-top: 8px; }
    .ad-gallery__row {
        display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
        padding: 9px 10px; border: 1px solid var(--ad-border); border-radius: 10px; background: var(--ad-bg);
    }
    .ad-gallery__row img { width: 54px; height: 54px; object-fit: contain; background: #fff; border-radius: 6px; flex: none; }
    .ad-gallery__row .ad-picker__field { flex: 1; min-width: 160px; }
    .ad-gallery__row .ad-picker__field .sik-input { width: 100%; }
    .ad-gallery__order.sik-input { width: 66px; padding: 7px 10px; font-size: 12.5px; }
    @media (max-width: 767px) { .ad-gallery__order.sik-input { font-size: 16px; width: 64px; } }
</style>

<form class="ad-form" id="comboForm" method="post" enctype="multipart/form-data" data-guard-unsaved>
    <?= csrf_field() ?>

    <div class="ad-grid ad-grid--sidebar">
        <div style="display:grid;gap:16px">

            <div class="ad-card" style="margin:0">
                <div class="ad-card__head">
                    <div class="ad-card__title">Combo</div>
                    <div class="ad-card__sub">What the set is called and how it is introduced.</div>
                </div>
                <div class="ad-card__body">
                    <div class="ad-row ad-row--2">
                        <div class="ad-field">
                            <label class="sik-label" for="comboName">Name <span class="req">*</span></label>
                            <input class="sik-input<?= isset($errors['name']) ? ' is-invalid' : '' ?>" type="text"
                                   id="comboName" name="name" maxlength="200" required
                                   data-slug-source="#comboSlug" data-preview-source="name"
                                   value="<?= e($combo['name'] ?? '') ?>" placeholder="Work From Home Starter Set">
                            <?php if (isset($errors['name'])): ?>
                                <span class="sik-error"><?= e($errors['name']) ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="ad-field">
                            <label class="sik-label" for="comboSlug">Slug</label>
                            <input class="sik-input<?= isset($errors['slug']) ? ' is-invalid' : '' ?>" type="text"
                                   id="comboSlug" name="slug" maxlength="220" data-slugify
                                   value="<?= e($combo['slug'] ?? '') ?>">
                            <?php if (isset($errors['slug'])): ?>
                                <span class="sik-error"><?= e($errors['slug']) ?></span>
                            <?php else: ?>
                                <span class="sik-help">
                                    Auto-filled from the name. The storefront URL is /combo/&lt;slug&gt;.
                                    A slug already in use gets a number added.
                                </span>
                            <?php endif; ?>
                            <?php
                            /* Offers to keep the current URL alive as a 301 when the slug
                               changes. Renders nothing on a create, where there is no old URL. */
                            seo_slug_keep_checkbox('combo', (string) ($combo['slug'] ?? ''));
                            ?>
                        </div>
                    </div>

                    <div class="ad-field">
                        <label class="sik-label" for="comboSubtitle">Subtitle</label>
                        <input class="sik-input<?= isset($errors['subtitle']) ? ' is-invalid' : '' ?>" type="text"
                               id="comboSubtitle" name="subtitle" maxlength="255" data-preview-source="subtitle"
                               value="<?= e($combo['subtitle'] ?? '') ?>"
                               placeholder="Everything one desk needs, in one box">
                        <?php if (isset($errors['subtitle'])): ?>
                            <span class="sik-error"><?= e($errors['subtitle']) ?></span>
                        <?php else: ?>
                            <span class="sik-help">One line under the name on the combo card.</span>
                        <?php endif; ?>
                    </div>

                    <div class="ad-field">
                        <label class="sik-label" for="comboDescription">Description</label>
                        <textarea class="sik-textarea" id="comboDescription" name="description" rows="4"
                                  placeholder="What the set is for, and why these products go together."><?= e($combo['description'] ?? '') ?></textarea>
                        <span class="sik-help">Basic HTML is allowed; scripts and event handlers are stripped on save.</span>
                    </div>
                </div>
            </div>

            <div class="ad-card" style="margin:0">
                <div class="ad-card__head">
                    <div class="ad-card__title">What is in the set</div>
                    <div class="ad-card__sub">
                        Search, add, set how many of each, and drag the handle or use the arrows to put them in
                        the order a shopper reads them.
                    </div>
                </div>
                <div class="ad-card__body">
                    <div class="ad-picker" data-combo-picker>
                        <div class="ad-picker__search">
                            <?= icon('search', 'w-4 h-4') ?>
                            <input class="sik-input" type="search" data-combo-search autocomplete="off"
                                   placeholder="Search products by name or SKU&hellip;"
                                   aria-label="Search products to add to this combo">
                            <div class="ad-picker__results" data-combo-results hidden></div>
                        </div>

                        <?php if (isset($errors['items'])): ?>
                            <span class="sik-error"><?= e($errors['items']) ?></span>
                        <?php elseif (isset($errors['quantity'])): ?>
                            <span class="sik-error"><?= e($errors['quantity']) ?></span>
                        <?php else: ?>
                            <span class="sik-help">
                                The price shown on each row is what that product sells for today, including any
                                flash sale or deal running on it — the same number the set is priced against.
                            </span>
                        <?php endif; ?>

                        <div class="ad-picker__list" data-combo-list>
                            <?php foreach ($picked as $index => $row): ?>
                                <?php
                                $productId = (int) $row['product_id'];
                                $product   = $productRows[$productId] ?? null;

                                // product_effective_price() is the function
                                // combo_items() itself uses, so the row prints
                                // the number the data layer will price with -
                                // not sale_price, which is all the search
                                // endpoint can see.
                                $unit = $product !== null
                                    ? product_effective_price($product)
                                    : ['mrp' => 0.0, 'price' => 0.0];

                                $name = $product !== null
                                    ? (string) $product['name']
                                    : 'Product #' . $productId;

                                if ($product === null) {
                                    $meta = 'No longer in the catalogue — remove this row.';
                                } else {
                                    $meta = implode(' · ', array_filter([
                                        (string) $product['sku'],
                                        money((float) $unit['price']),
                                        (int) $product['stock'] > 0
                                            ? (int) $product['stock'] . ' in stock'
                                            : 'Out of stock',
                                        (string) $product['status'] !== STATUS_ACTIVE
                                            ? 'Not published — the storefront drops it'
                                            : '',
                                    ]));
                                }
                                $isProblem = $product === null || (string) $product['status'] !== STATUS_ACTIVE;
                                ?>
                                <div class="ad-picker__row" data-combo-row="<?= $productId ?>"
                                     data-name="<?= e_attr($name) ?>"
                                     data-unit-price="<?= e_attr((string) $unit['price']) ?>"
                                     data-stock="<?= (int) ($product['stock'] ?? 0) ?>">
                                    <span class="ad-grip" data-grip aria-hidden="true" title="Drag to reorder">
                                        <?= icon('dots', 'w-4 h-4') ?>
                                    </span>
                                    <img class="ad-thumb" src="<?= e(img_url($product['main_image'] ?? null)) ?>" alt=""
                                         width="38" height="38" loading="lazy">
                                    <span class="ad-picker__info">
                                        <span class="ad-cellflex__name"><?= e($name) ?></span>
                                        <span class="ad-cellflex__meta<?= $isProblem ? ' ad-picker__warn' : '' ?>"><?= e($meta) ?></span>
                                    </span>
                                    <label class="ad-picker__field">
                                        <span>Qty</span>
                                        <input class="sik-input" type="number" name="quantity[]" data-qty
                                               min="1" max="99" step="1" value="<?= (int) $row['quantity'] ?>"
                                               aria-label="Quantity of <?= e_attr($name) ?>">
                                    </label>
                                    <label class="ad-picker__field">
                                        <span>Order</span>
                                        <input class="sik-input" type="number" name="item_sort[]" data-order
                                               min="1" max="99" step="1" value="<?= $index + 1 ?>"
                                               aria-label="Position of <?= e_attr($name) ?>">
                                    </label>
                                    <?php // Unnamed, so they post nothing: item_sort[] stays the
                                          // only order the save reads. ?>
                                    <span class="ad-movebtns">
                                        <button type="button" data-move="up" aria-label="Move <?= e_attr($name) ?> up">
                                            <?= icon('chevron-up', 'w-4 h-4') ?>
                                        </button>
                                        <button type="button" data-move="down" aria-label="Move <?= e_attr($name) ?> down">
                                            <?= icon('chevron-down', 'w-4 h-4') ?>
                                        </button>
                                    </span>
                                    <input type="hidden" name="product_id[]" value="<?= $productId ?>">
                                    <button type="button" class="ad-btn ad-btn--icon ad-btn--danger-ghost"
                                            data-combo-remove aria-label="Remove <?= e_attr($name) ?> from the set">
                                        <?= icon('close', 'w-4 h-4') ?>
                                    </button>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <p class="ad-picker__empty ad-muted" data-combo-empty<?= $picked !== [] ? ' hidden' : '' ?>>
                            Nothing in the set yet. A live combo needs at least two products.
                        </p>
                    </div>
                </div>
            </div>

            <div class="ad-card" style="margin:0">
                <div class="ad-card__head">
                    <div class="ad-card__title">Pricing</div>
                    <div class="ad-card__sub">
                        A fixed price for the set, or a percentage off what its components cost today.
                    </div>
                </div>
                <div class="ad-card__body">
                    <div class="ad-row ad-row--2">
                        <div class="ad-field">
                            <label class="sik-label" for="comboPricingMode">Pricing mode</label>
                            <select class="sik-select" id="comboPricingMode" name="pricing_mode">
                                <?= admin_options(combo_pricing_mode_options(), $combo['pricing_mode'] ?? 'fixed') ?>
                            </select>
                            <?php if (isset($errors['pricing_mode'])): ?>
                                <span class="sik-error"><?= e($errors['pricing_mode']) ?></span>
                            <?php else: ?>
                                <span class="sik-help">
                                    A percentage stays a percentage as the catalogue's own prices move; a fixed
                                    price does not.
                                </span>
                            <?php endif; ?>
                        </div>

                        <div class="ad-field" id="comboPriceField">
                            <label class="sik-label" for="comboPrice">
                                Set price (<?= e($currencySymbol) ?>) <span class="req">*</span>
                            </label>
                            <input class="sik-input<?= isset($errors['price']) ? ' is-invalid' : '' ?>" type="number"
                                   id="comboPrice" name="price" min="0" step="0.01" data-price
                                   value="<?= e($combo['price'] ?? '') ?>" placeholder="2499">
                            <?php if (isset($errors['price'])): ?>
                                <span class="sik-error"><?= e($errors['price']) ?></span>
                            <?php else: ?>
                                <span class="sik-help">What one complete set costs.</span>
                            <?php endif; ?>
                        </div>

                        <div class="ad-field" id="comboPercentField">
                            <label class="sik-label" for="comboPercent">Discount (%) <span class="req">*</span></label>
                            <input class="sik-input<?= isset($errors['discount_percent']) ? ' is-invalid' : '' ?>"
                                   type="number" id="comboPercent" name="discount_percent"
                                   min="0" max="100" step="0.01" data-percent
                                   value="<?= e($combo['discount_percent'] ?? '') ?>" placeholder="15">
                            <?php if (isset($errors['discount_percent'])): ?>
                                <span class="sik-error"><?= e($errors['discount_percent']) ?></span>
                            <?php else: ?>
                                <span class="sik-help">Taken off what the components cost today, not off their MRP.</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="ad-combosum" data-combo-summary>
                        <div class="ad-combosum__row">
                            <span>Components today</span>
                            <strong data-sum="regular"><?= e($preview['regular_display']) ?></strong>
                        </div>
                        <div class="ad-combosum__row">
                            <span>Combo price</span>
                            <strong data-sum="price"><?= e($preview['price_display']) ?></strong>
                        </div>
                        <div class="ad-combosum__row ad-combosum__row--total">
                            <span data-sum="items"><?= (int) $preview['item_count'] ?> products &middot; <?= (int) $preview['unit_count'] ?> items</span>
                            <strong data-sum="saving">
                                Saves <?= e($preview['saving_display']) ?> (<?= (int) $preview['percent'] ?>%)
                            </strong>
                        </div>
                    </div>
                    <span class="sik-help" style="display:block;margin-top:8px">
                        "Components today" is what these products cost bought separately right now — never their
                        MRP, which would claim a saving the shopper could get anyway. This total is worked out in
                        the browser; the server recomputes it on save, so a product whose price moves in between
                        changes the stored figures, not this one.
                    </span>
                </div>
            </div>

            <div class="ad-card" style="margin:0">
                <div class="ad-card__head">
                    <div class="ad-card__title">Media</div>
                    <div class="ad-card__sub">JPG, PNG, WEBP, GIF or SVG up to <?= e(format_bytes(MAX_UPLOAD_SIZE)) ?></div>
                </div>
                <div class="ad-card__body">
                    <div class="ad-row ad-row--2">
                        <div class="ad-field">
                            <label class="sik-label">Card image</label>
                            <div class="ad-drop" data-drop="#comboImagePreview">
                                <input type="file" name="image" accept="image/*">
                                <?= icon('upload', 'w-6 h-6') ?>
                                <div style="font-size:13px;margin-top:6px">Click or drop the square card image</div>
                            </div>
                            <div class="ad-preview" id="comboImagePreview">
                                <?php if (!empty($combo['image'])): ?>
                                    <div class="ad-preview__item">
                                        <img src="<?= e(img_url($combo['image'])) ?>" alt="Current card image">
                                        <button type="button" class="ad-preview__remove"
                                                data-remove-image="#comboRemoveImage"
                                                aria-label="Remove card image">&times;</button>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <input type="hidden" name="remove_image" id="comboRemoveImage" value="0">
                            <span class="sik-help">The only image a combo card shows.</span>
                        </div>

                        <div class="ad-field">
                            <label class="sik-label">Banner</label>
                            <div class="ad-drop" data-drop="#comboBannerPreview">
                                <input type="file" name="banner" accept="image/*">
                                <?= icon('upload', 'w-6 h-6') ?>
                                <div style="font-size:13px;margin-top:6px">Wide artwork for the combo's own page</div>
                            </div>
                            <div class="ad-preview" id="comboBannerPreview">
                                <?php if (!empty($combo['banner'])): ?>
                                    <div class="ad-preview__item">
                                        <img src="<?= e(img_url($combo['banner'])) ?>" alt="Current banner">
                                        <button type="button" class="ad-preview__remove"
                                                data-remove-image="#comboRemoveBanner"
                                                aria-label="Remove banner">&times;</button>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <input type="hidden" name="remove_banner" id="comboRemoveBanner" value="0">
                        </div>
                    </div>

                    <div class="ad-field">
                        <span class="sik-label">Gallery</span>
                        <div class="ad-drop" data-drop="#comboGalleryPreview">
                            <input type="file" name="gallery[]" accept="image/*" multiple>
                            <?= icon('upload', 'w-6 h-6') ?>
                            <div style="font-size:13px;margin-top:6px">Click or drop one or more images</div>
                        </div>
                        <div class="ad-preview" id="comboGalleryPreview"></div>

                        <?php if ($gallery !== []): ?>
                            <p class="sik-help" style="margin-top:14px">
                                Saved gallery images. Drag a row by its handle, use the arrows or type its position
                                to reorder; tick "Remove" to delete the file on save. The gallery is separate from
                                the card image above.
                            </p>
                            <div class="ad-gallery" data-gallery-list>
                                <?php foreach ($gallery as $index => $image): ?>
                                    <?php
                                    $imageId = (int) $image['id'];
                                    // What the arrows and announcements call this row. Its
                                    // alt text if it has one, else the file name - not
                                    // "image 3", which stops being true after one move.
                                    $imageName = trim((string) ($image['alt_text'] ?? '')) !== ''
                                        ? trim((string) $image['alt_text'])
                                        : basename((string) $image['image']);
                                    ?>
                                    <div class="ad-gallery__row" data-gallery-row data-name="<?= e_attr($imageName) ?>">
                                        <span class="ad-grip" data-grip aria-hidden="true" title="Drag to reorder">
                                            <?= icon('dots', 'w-4 h-4') ?>
                                        </span>
                                        <input class="sik-input ad-gallery__order" type="number" data-order
                                               min="1" max="999" step="1" name="image_order[<?= $imageId ?>]"
                                               value="<?= $index + 1 ?>"
                                               aria-label="Position of <?= e_attr($imageName) ?>">
                                        <span class="ad-movebtns">
                                            <button type="button" data-move="up" aria-label="Move <?= e_attr($imageName) ?> up">
                                                <?= icon('chevron-up', 'w-4 h-4') ?>
                                            </button>
                                            <button type="button" data-move="down" aria-label="Move <?= e_attr($imageName) ?> down">
                                                <?= icon('chevron-down', 'w-4 h-4') ?>
                                            </button>
                                        </span>
                                        <img src="<?= e(img_url((string) $image['image'])) ?>" alt="">
                                        <label class="ad-picker__field">
                                            <span>Alt text</span>
                                            <input class="sik-input" type="text" maxlength="200"
                                                   name="image_alt[<?= $imageId ?>]"
                                                   value="<?= e((string) ($image['alt_text'] ?? '')) ?>"
                                                   placeholder="What the picture shows">
                                        </label>
                                        <label class="sik-check">
                                            <input type="checkbox" name="remove_images[]" value="<?= $imageId ?>">
                                            <span>Remove</span>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="ad-card" style="margin:0">
                <div class="ad-card__head">
                    <div class="ad-card__title">SEO</div>
                    <div class="ad-card__sub">Leave these blank to fall back to the combo's own name and subtitle.</div>
                </div>
                <div class="ad-card__body">
                    <?php
                    // The shared editor, the same one the other five entity
                    // forms use. It used to be three hand-written fields here
                    // because `combos` only had columns for three of the nine
                    // it posts; the per-entity SEO migration added the other
                    // six, so the exception is gone and so is the drift.
                    require_once ADMIN_PATH . '/includes/seo-editor.php';
                    seo_editor($combo, [
                        'url'              => (string) ($combo['slug'] ?? '') !== ''
                            ? combo_url((string) $combo['slug'])
                            : url(),
                        'title_from'       => 'name',
                        'description_from' => 'subtitle',
                        'type'             => 'combo',
                        'id'               => (int) ($combo['id'] ?? 0),
                    ]);
                    ?>
                    <?php if (isset($errors['meta_title'])): ?>
                        <span class="sik-error"><?= e($errors['meta_title']) ?></span>
                    <?php endif; ?>
                    <?php if (isset($errors['og_image'])): ?>
                        <span class="sik-error"><?= e($errors['og_image']) ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div style="display:grid;gap:16px;align-content:start">
            <div class="ad-card" style="margin:0">
                <div class="ad-card__head"><div class="ad-card__title">Preview</div></div>
                <div class="ad-card__body">
                    <div class="ad-comboprev">
                        <div class="ad-comboprev__media">
                            <img src="<?= e($preview['image_url']) ?>" alt="">
                            <span class="ad-comboprev__badge" data-preview="badge"><?= e($preview['badge']['label'] ?? '') ?></span>
                        </div>
                        <div class="ad-comboprev__body">
                            <div class="ad-comboprev__title" data-preview="name"><?= e($combo['name'] ?? '') ?></div>
                            <div class="ad-comboprev__sub" data-preview="subtitle"><?= e($combo['subtitle'] ?? '') ?></div>
                            <div class="ad-comboprev__prices">
                                <strong data-preview="price"><?= e($preview['price_display']) ?></strong>
                                <s data-preview="regular"><?= e($preview['regular_display']) ?></s>
                                <span class="ad-comboprev__off" data-preview="percent"><?= (int) $preview['percent'] ?>% off</span>
                            </div>
                            <div class="ad-comboprev__save" data-preview="saving">
                                You save <?= e($preview['saving_display']) ?>
                            </div>
                            <div class="ad-comboprev__meta">
                                <span data-preview="items"><?= (int) $preview['item_count'] ?> products &middot; <?= (int) $preview['unit_count'] ?> items</span>
                                &middot; <span data-preview="status"><?= e(combo_status_options()[$status] ?? $status) ?></span>
                            </div>
                        </div>
                    </div>
                    <span class="sik-help" style="display:block;margin-top:8px">
                        Copy and prices follow what you type. The artwork, the badge that the switches produce and
                        the schedule are as they were last saved, and
                        <?= (int) $preview['available'] ?> complete set<?= (int) $preview['available'] === 1 ? '' : 's' ?>
                        can be assembled from stock right now.
                    </span>
                </div>
            </div>

            <div class="ad-card" style="margin:0">
                <div class="ad-card__head"><div class="ad-card__title">Placement</div></div>
                <div class="ad-card__body" style="display:grid;gap:14px">
                    <label class="ad-switch">
                        <input type="checkbox" name="is_featured" value="1"
                               <?= (int) ($combo['is_featured'] ?? 0) === 1 ? 'checked' : '' ?>>
                        <span class="ad-switch__track"></span><span>Featured</span>
                    </label>
                    <label class="ad-switch">
                        <input type="checkbox" name="is_flash" value="1"
                               <?= (int) ($combo['is_flash'] ?? 0) === 1 ? 'checked' : '' ?>>
                        <span class="ad-switch__track"></span><span>Flash deal</span>
                    </label>
                    <label class="ad-switch">
                        <input type="checkbox" name="is_best_seller" value="1"
                               <?= (int) ($combo['is_best_seller'] ?? 0) === 1 ? 'checked' : '' ?>>
                        <span class="ad-switch__track"></span><span>Best seller</span>
                    </label>

                    <div class="ad-field">
                        <label class="sik-label" for="comboBadge">Badge text</label>
                        <input class="sik-input<?= isset($errors['badge_text']) ? ' is-invalid' : '' ?>" type="text"
                               id="comboBadge" name="badge_text" maxlength="40"
                               value="<?= e($combo['badge_text'] ?? '') ?>" placeholder="Only 20 sets">
                        <?php if (isset($errors['badge_text'])): ?>
                            <span class="sik-error"><?= e($errors['badge_text']) ?></span>
                        <?php else: ?>
                            <span class="sik-help">Overrides the badge the switches above would produce.</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="ad-card" style="margin:0">
                <div class="ad-card__head"><div class="ad-card__title">Availability</div></div>
                <div class="ad-card__body" style="display:grid;gap:14px">
                    <div class="ad-field">
                        <label class="sik-label" for="comboStockMode">Stock</label>
                        <select class="sik-select" id="comboStockMode" name="stock_mode">
                            <?= admin_options(combo_stock_mode_options(), $combo['stock_mode'] ?? 'components') ?>
                        </select>
                        <?php if (isset($errors['stock_mode'])): ?>
                            <span class="sik-error"><?= e($errors['stock_mode']) ?></span>
                        <?php else: ?>
                            <span class="sik-help">
                                Following the components means the product with the least headroom decides how many
                                sets can ship.
                            </span>
                        <?php endif; ?>
                    </div>

                    <div class="ad-field" id="comboStockField">
                        <label class="sik-label" for="comboStock">Sets in the run <span class="req">*</span></label>
                        <input class="sik-input<?= isset($errors['stock']) ? ' is-invalid' : '' ?>" type="number"
                               id="comboStock" name="stock" min="1" max="999999" step="1"
                               value="<?= e($combo['stock'] ?? '') ?>">
                        <?php if (isset($errors['stock'])): ?>
                            <span class="sik-error"><?= e($errors['stock']) ?></span>
                        <?php else: ?>
                            <span class="sik-help">
                                A second ceiling on top of component stock.
                                <?= (int) ($combo['sold_count'] ?? 0) ?> sold so far.
                            </span>
                        <?php endif; ?>
                    </div>

                    <div class="ad-field">
                        <label class="sik-label" for="comboMaxPerOrder">Maximum per order <span class="req">*</span></label>
                        <input class="sik-input<?= isset($errors['max_per_order']) ? ' is-invalid' : '' ?>" type="number"
                               id="comboMaxPerOrder" name="max_per_order" min="1" max="255" step="1"
                               value="<?= e($combo['max_per_order'] ?? '5') ?>">
                        <?php if (isset($errors['max_per_order'])): ?>
                            <span class="sik-error"><?= e($errors['max_per_order']) ?></span>
                        <?php else: ?>
                            <span class="sik-help">The basket clamps to this and to what component stock allows.</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="ad-card" style="margin:0">
                <div class="ad-card__head">
                    <div class="ad-card__title">Visibility</div>
                    <div><?= marketing_state_badge($state) ?></div>
                </div>
                <div class="ad-card__body" style="display:grid;gap:14px">
                    <div class="ad-field">
                        <label class="sik-label" for="comboStatus">Status</label>
                        <select class="sik-select" id="comboStatus" name="status">
                            <?= admin_options(combo_status_options(), $status) ?>
                        </select>
                        <?php if (isset($errors['status'])): ?>
                            <span class="sik-error"><?= e($errors['status']) ?></span>
                        <?php else: ?>
                            <span class="sik-help">
                                A draft is saved without being checked for sellability, so a half-built set can be
                                parked. Going active is refused unless the set has at least two published products,
                                enough stock for one whole set, and a real saving against what the items cost today.
                            </span>
                        <?php endif; ?>
                    </div>

                    <div class="ad-field">
                        <label class="sik-label" for="comboSort">Sort order</label>
                        <input class="sik-input<?= isset($errors['sort_order']) ? ' is-invalid' : '' ?>" type="number"
                               id="comboSort" name="sort_order" min="0" max="9999" step="1"
                               value="<?= (int) ($combo['sort_order'] ?? 0) ?>">
                        <?php if (isset($errors['sort_order'])): ?>
                            <span class="sik-error"><?= e($errors['sort_order']) ?></span>
                        <?php else: ?>
                            <span class="sik-help">Lower numbers come first in the combo listings.</span>
                        <?php endif; ?>
                    </div>

                    <div class="ad-field">
                        <label class="sik-label" for="comboStart">Starts</label>
                        <input class="sik-input<?= isset($errors['start_date']) ? ' is-invalid' : '' ?>"
                               type="datetime-local" id="comboStart" name="start_date"
                               value="<?= e($combo['start_date'] ?? '') ?>">
                        <?php if (isset($errors['start_date'])): ?>
                            <span class="sik-error"><?= e($errors['start_date']) ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="ad-field">
                        <label class="sik-label" for="comboEnd">Ends</label>
                        <input class="sik-input<?= isset($errors['end_date']) ? ' is-invalid' : '' ?>"
                               type="datetime-local" id="comboEnd" name="end_date"
                               value="<?= e($combo['end_date'] ?? '') ?>">
                        <?php if (isset($errors['end_date'])): ?>
                            <span class="sik-error"><?= e($errors['end_date']) ?></span>
                        <?php else: ?>
                            <span class="sik-help">Leave both blank to run it until you turn it off.</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="ad-card__foot">
                    <a class="ad-btn" href="<?= e(admin_url('combos/')) ?>">Cancel</a>
                    <button type="submit" class="ad-btn ad-btn--primary">
                        <?= icon('check', 'w-4 h-4') ?>
                        <?= $isEdit ? 'Save Changes' : 'Create Combo' ?>
                    </button>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
window.SIK_COMBO_FORM = <?= e_json($formConfig) ?>;
</script>
<script>
(function () {
    'use strict';

    // app.js and admin.js are deferred, so SIK only exists once the document
    // has parsed. Everything below waits for that rather than for load.
    document.addEventListener('DOMContentLoaded', function () {
        var cfg  = window.SIK_COMBO_FORM || {};
        var form = document.getElementById('comboForm');
        if (!form) { return; }

        var picker  = form.querySelector('[data-combo-picker]');
        var list    = form.querySelector('[data-combo-list]');
        var results = form.querySelector('[data-combo-results]');
        var empty   = form.querySelector('[data-combo-empty]');
        if (!picker || !list) { return; }

        function esc(value) { return SIK.escapeHtml(value == null ? '' : String(value)); }

        // Server-rendered icon markup, so it is never escaped like a value.
        function glyph(name) { return (cfg.icons || {})[name] || ''; }

        function number(el) {
            var value = parseFloat(el && el.value);
            return isNaN(value) ? 0 : value;
        }

        /* ------------------------------------------------------------------
           The picker.

           SIK.get resolves relative endpoints against /api, and the lookup is
           the admin-only endpoint there rather than the storefront's own
           search: that one writes every query into search_logs, which would
           fill the shopper-facing trending list with whatever an operator
           typed into this box.
           ------------------------------------------------------------------ */
        var ENDPOINT = 'admin/product-search.php';

        function closeResults() {
            if (results) { results.innerHTML = ''; results.hidden = true; }
        }

        function syncEmpty() {
            if (empty) { empty.hidden = list.querySelectorAll('[data-combo-row]').length > 0; }
        }

        // Kept deliberately in step with the PHP row above: the same classes,
        // the same three inputs, the same order. `data-unit-price` is what the
        // summary multiplies, and for a row added here it is the endpoint's
        // figure - sale_price only. A component inside a running flash sale or
        // deal prices lower on the server, which is why the help text says the
        // server recomputes on save.
        function rowHtml(data) {
            return '<div class="ad-picker__row" data-combo-row="' + esc(data.id) + '"'
                + ' data-name="' + esc(data.name) + '"'
                + ' data-unit-price="' + esc(data.price) + '" data-stock="' + esc(data.stock) + '">'
                + '<span class="ad-grip" data-grip aria-hidden="true" title="Drag to reorder">'
                + glyph('grip') + '</span>'
                + '<img class="ad-thumb" src="' + esc(data.image) + '" alt="" width="38" height="38">'
                + '<span class="ad-picker__info">'
                + '<span class="ad-cellflex__name">' + esc(data.name) + '</span>'
                + '<span class="ad-cellflex__meta">' + esc(data.meta) + '</span>'
                + '</span>'
                + '<label class="ad-picker__field"><span>Qty</span>'
                + '<input class="sik-input" type="number" name="quantity[]" data-qty min="1" max="99" step="1"'
                + ' value="1" aria-label="Quantity of ' + esc(data.name) + '"></label>'
                + '<label class="ad-picker__field"><span>Order</span>'
                + '<input class="sik-input" type="number" name="item_sort[]" data-order min="1" max="99" step="1"'
                + ' value="1" aria-label="Position of ' + esc(data.name) + '"></label>'
                + '<span class="ad-movebtns">'
                + '<button type="button" data-move="up" aria-label="Move ' + esc(data.name) + ' up">'
                + glyph('up') + '</button>'
                + '<button type="button" data-move="down" aria-label="Move ' + esc(data.name) + ' down">'
                + glyph('down') + '</button>'
                + '</span>'
                + '<input type="hidden" name="product_id[]" value="' + esc(data.id) + '">'
                + '<button type="button" class="ad-btn ad-btn--icon ad-btn--danger-ghost" data-combo-remove'
                + ' aria-label="Remove ' + esc(data.name) + ' from the set">' + glyph('remove') + '</button>'
                + '</div>';
        }

        SIK.on('input', '[data-combo-search]', SIK.debounce(async function () {
            var term = this.value.trim();
            if (term.length < 2) { closeResults(); return; }

            var response = await SIK.get(ENDPOINT, { q: term, limit: 8 });
            if (!response.success) {
                SIK.toast(response.message || 'Product search failed.', 'error');
                return;
            }

            var products = (response.data && response.data.products) || [];
            results.innerHTML = products.length
                ? products.map(function (p) {
                    return '<button type="button" class="ad-dropdown__item" data-combo-add'
                        + ' data-id="' + esc(p.id) + '" data-name="' + esc(p.name) + '"'
                        + ' data-image="' + esc(p.image_url) + '" data-meta="' + esc(p.meta) + '"'
                        + ' data-price="' + esc(p.price) + '" data-stock="' + esc(p.stock) + '">'
                        + '<img src="' + esc(p.image_url) + '" alt="" width="28" height="28" style="border-radius:5px">'
                        + '<span style="flex:1;min-width:0">'
                        + '<span style="display:block;font-weight:600">' + esc(p.name) + '</span>'
                        + '<span style="font-size:11.5px;color:var(--ad-muted)">' + esc(p.meta) + '</span>'
                        + '</span></button>';
                }).join('')
                : '<div class="ad-dropdown__item ad-muted">No products match that search.</div>';
            results.hidden = false;
        }, 300));

        SIK.on('click', '[data-combo-add]', function (e) {
            e.preventDefault();
            // A product twice over would each report stock/quantity to the
            // availability maths, so one unit left would read as a whole set.
            // Raise the quantity on the row that is already there instead.
            var existing = list.querySelector('[data-combo-row="' + this.dataset.id + '"]');
            if (existing) {
                var qty = existing.querySelector('[data-qty]');
                if (qty) { qty.value = String(Math.min(99, (parseInt(qty.value, 10) || 1) + 1)); }
                SIK.toast('Already in the set - raised its quantity instead.', 'info');
            } else {
                list.insertAdjacentHTML('beforeend', rowHtml(this.dataset));
            }

            closeResults();
            var search = picker.querySelector('[data-combo-search]');
            if (search) { search.value = ''; }
            renumber(list);
            syncEmpty();
            render();
        });

        SIK.on('click', '[data-combo-remove]', function (e) {
            e.preventDefault();
            var row = this.closest('[data-combo-row]');
            if (row) { row.remove(); }
            renumber(list);
            syncEmpty();
            render();
        });

        document.addEventListener('click', function (e) {
            if (e.target.closest('[data-combo-picker]')) { return; }
            closeResults();
        });

        /* ------------------------------------------------------------------
           Reordering, for the component list and the saved gallery alike.

           The number inputs are the control; dragging and the arrows are
           shortcuts that rewrite them. They are what is posted, so a drag or
           an arrow press changes exactly the item_sort[] / image_order[]
           values a typed number would, and the save cannot tell them apart.

           The drag is Admin.sortable() in admin.js - Pointer Events, so it
           works under a finger. The HTML5 drag-and-drop it replaces never
           fired from a touch screen, which left typing numbers as the only
           way to reorder a set on a phone or tablet.
           ------------------------------------------------------------------ */
        function renumber(container) {
            Array.prototype.forEach.call(container.querySelectorAll('[data-order]'), function (input, index) {
                input.value = index + 1;
            });
        }

        function bindReorder(container, rowSelector) {
            SIK.admin.sortable(container, {
                row: rowSelector,
                onChange: function () {
                    renumber(container);
                    // Values set from script fire no event, so without this
                    // the unsaved-changes guard would let a reordered set be
                    // walked away from without a word.
                    container.dispatchEvent(new Event('change', { bubbles: true }));
                }
            });

            // Typing a number is the other half of the same control: sorting
            // the DOM to match keeps the list and the values telling one story.
            container.addEventListener('change', function (e) {
                if (!e.target.matches('[data-order]')) { return; }
                var rows = Array.prototype.slice.call(container.querySelectorAll(rowSelector));
                rows.sort(function (a, b) {
                    return (parseInt(a.querySelector('[data-order]').value, 10) || 0)
                         - (parseInt(b.querySelector('[data-order]').value, 10) || 0);
                });
                rows.forEach(function (row) { container.appendChild(row); });
                renumber(container);
            });
        }

        bindReorder(list, '[data-combo-row]');

        var gallery = form.querySelector('[data-gallery-list]');
        if (gallery) { bindReorder(gallery, '[data-gallery-row]'); }

        /* ------------------------------------------------------------------
           Only the field the chosen mode uses is on screen, so nobody types a
           set price into a combo that is going to derive one.
           ------------------------------------------------------------------ */
        var mode        = document.getElementById('comboPricingMode');
        var priceField  = document.getElementById('comboPriceField');
        var percentField = document.getElementById('comboPercentField');
        var stockMode   = document.getElementById('comboStockMode');
        var stockField  = document.getElementById('comboStockField');

        function syncModes() {
            var isPercent = mode.value === 'percentage';
            priceField.style.display = isPercent ? 'none' : '';
            percentField.style.display = isPercent ? '' : 'none';
            stockField.style.display = stockMode.value === 'track' ? '' : 'none';
        }

        /* ------------------------------------------------------------------
           The live summary and the preview card.

           The arithmetic below mirrors combo_pricing() step for step - the
           same clamps, the same floor at zero, `regular` as the number the
           saving is measured against and never `mrp` - so the browser and the
           server cannot tell the operator two different stories. The server
           still recomputes on save, because a component's price can move
           between this keystroke and that write.
           ------------------------------------------------------------------ */
        function summarise() {
            var regular = 0;
            var items = 0;
            var units = 0;

            Array.prototype.forEach.call(list.querySelectorAll('[data-combo-row]'), function (row) {
                var qty = parseInt(row.querySelector('[data-qty]').value, 10);
                if (isNaN(qty) || qty < 1) { qty = 1; }
                regular += (parseFloat(row.dataset.unitPrice) || 0) * qty;
                items += 1;
                units += qty;
            });

            var price = mode.value === 'percentage'
                ? regular * (1 - Math.max(0, Math.min(100, number(document.getElementById('comboPercent')))) / 100)
                : Math.max(0, number(document.getElementById('comboPrice')));

            var saving = Math.max(0, regular - price);

            return {
                regular: regular,
                price: price,
                saving: saving,
                percent: regular > 0 ? Math.round(saving / regular * 100) : 0,
                items: items,
                units: units
            };
        }

        function paint(selector, text) {
            Array.prototype.forEach.call(form.querySelectorAll(selector), function (node) {
                node.textContent = text;
            });
        }

        function field(name) { return form.querySelector('[name="' + name + '"]'); }

        function badgeLabel() {
            var custom = field('badge_text');
            if (custom && custom.value.trim() !== '') { return custom.value.trim(); }

            var fallbacks = cfg.badges || [];
            for (var i = 0; i < fallbacks.length; i++) {
                var box = field(fallbacks[i].field);
                if (box && box.checked) { return fallbacks[i].label; }
            }
            return '';
        }

        function render() {
            var sums = summarise();
            var countText = sums.items + ' product' + (sums.items === 1 ? '' : 's')
                + ' · ' + sums.units + ' item' + (sums.units === 1 ? '' : 's');

            paint('[data-sum="regular"]', SIK.formatCurrency(sums.regular));
            paint('[data-sum="price"]', SIK.formatCurrency(sums.price));
            paint('[data-sum="items"]', countText);
            paint('[data-sum="saving"]', sums.saving > 0
                ? 'Saves ' + SIK.formatCurrency(sums.saving) + ' (' + sums.percent + '%)'
                : 'No saving on today’s prices');

            var summary = form.querySelector('[data-combo-summary]');
            if (summary) { summary.classList.toggle('is-flat', sums.saving <= 0); }

            paint('[data-preview="price"]', SIK.formatCurrency(sums.price));
            paint('[data-preview="regular"]', SIK.formatCurrency(sums.regular));
            paint('[data-preview="saving"]', 'You save ' + SIK.formatCurrency(sums.saving));
            paint('[data-preview="percent"]', sums.percent + '% off');
            paint('[data-preview="items"]', countText);
            paint('[data-preview="badge"]', badgeLabel());

            // The plain copy fields, the banners way: whatever carries a
            // data-preview-source lands in the preview node of that name.
            Array.prototype.forEach.call(form.querySelectorAll('[data-preview-source]'), function (input) {
                paint('[data-preview="' + input.dataset.previewSource + '"]', input.value.trim());
            });

            // The status pill prints the label, not the stored key.
            var status = field('status');
            paint('[data-preview="status"]', status ? ((cfg.statuses || {})[status.value] || status.value) : '');
        }

        form.addEventListener('input', render);
        form.addEventListener('change', function () { syncModes(); render(); });

        syncModes();
        syncEmpty();
        render();
    });
})();
</script>
