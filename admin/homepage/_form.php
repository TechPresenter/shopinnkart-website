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

/** @var array $section @var array $errors @var bool $isEdit */
$isEdit  = $isEdit ?? false;
$errors  = $errors ?? [];
$types   = homepage_widget_types();
$lists   = homepage_source_lists();
$type    = (string) ($section['widget_type'] ?? 'product_grid');
$source  = (string) ($section['data_source'] ?? 'auto');
$picked  = homepage_manual_ids($section);

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
                    <div class="ad-card__sub">Which page the section belongs to, and what it renders.</div>
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
                            <span class="sik-help">
                                Unique across the whole builder. It becomes the anchor
                                <span class="ad-mono">#w-<?= e(($section['section_key'] ?? '') !== '' ? $section['section_key'] : 'your-key') ?></span>
                                on the storefront and the handle the lazy-load endpoint uses.
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
                            <span class="sik-help">Rendered right after the title in the accent colour.</span>
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
                        <span class="sik-help">Basic HTML is allowed; scripts and event handlers are stripped on save.</span>
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
                                <span class="sik-help">Relative paths resolve against the store URL. Both fields are needed for the link to render.</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- =========================== Custom HTML ========================== -->
            <div class="ad-card" data-insp="content" style="margin:0" data-when-type="html"
                 <?= $type === 'html' ? '' : 'hidden' ?>>
                <div class="ad-card__head">
                    <div class="ad-card__title">Custom HTML</div>
                    <div class="ad-card__sub">Only used by the Custom HTML widget type.</div>
                </div>
                <div class="ad-card__body">
                    <div class="ad-field">
                        <label class="sik-label" for="whHtml">Markup</label>
                        <textarea class="sik-textarea ad-mono" id="whHtml" name="custom_html" rows="10"
                                  style="min-height:220px;font-size:12.5px"
                                  placeholder="&lt;h2&gt;Anything you like&lt;/h2&gt;"><?= e($section['custom_html'] ?? '') ?></textarea>
                        <span class="sik-help">
                            Sanitised on save: <code>&lt;script&gt;</code>, inline event handlers and
                            <code>javascript:</code> URLs are removed. Layout tags, links, images and tables survive.
                        </span>
                    </div>
                </div>
            </div>

            <!-- ============================ Products ============================ -->
            <div class="ad-card" data-insp="content" style="margin:0">
                <div class="ad-card__head">
                    <div class="ad-card__title">Data source</div>
                    <div class="ad-card__sub">Where the items in this section come from.</div>
                </div>
                <div class="ad-card__body">
                    <div class="ad-row ad-row--2">
                        <div class="ad-field">
                            <label class="sik-label" for="whSource">Source</label>
                            <select class="sik-select" id="whSource" name="data_source" data-data-source>
                                <?= admin_options(homepage_data_sources(), $source) ?>
                            </select>
                            <span class="sik-help">Product widgets honour this. Hero, ticker, trust, stats and testimonials read their own tables.</span>
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
                        <span class="sik-help">Saved into the section's settings as <span class="ad-mono">product_ids</span>, in the order shown. Only used while the source is "Hand-picked products".</span>
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
                                    <span class="sik-help">Time each slide holds before advancing. Ignored while autoplay is off.</span>
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
                    <span class="sik-help" style="margin-top:-6px">
                        Renders an empty shell and fetches the contents over AJAX when it scrolls into view.
                        Good for heavy sections near the bottom; leave off for anything above the fold.
                    </span>
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
                        <p class="ad-muted" style="font-size:12.5px;margin-bottom:10px">
                            Opens the storefront scrolled to this section. Inactive or out-of-schedule
                            sections are not rendered, so the anchor will not resolve.
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
