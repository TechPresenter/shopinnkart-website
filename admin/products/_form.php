<?php
/**
 * ShopInnKart Admin - The product form.
 *
 * create.php and edit.php both render this file, so the two screens cannot
 * drift apart. Expects $state (from product_form_state()) and $errors.
 */

declare(strict_types=1);


// Include-only: this partial assumes its parent page already ran the
// authentication and permission checks. Refuse to run as an entry point.
if (!defined('SIK_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}
/** @var array $state @var array $errors */
$errors   = $errors ?? [];
$product  = $state['product'];
$images   = $state['images'];
$specs    = $state['specs'];
$features = $state['features'];
$tagIds   = $state['tag_ids'];
$related  = $state['related'];
$variants = $state['variants'];

$productId = (int) ($product['id'] ?? 0);
$isEdit    = $productId > 0;

$formAction  = $isEdit ? admin_url('products/edit.php?id=' . $productId) : admin_url('products/create.php');
$submitLabel = $isEdit ? 'Save Changes' : 'Create Product';

$variantAttributes = product_variant_attributes();
$brandOptions      = admin_lookup('brands');
$allTags           = Database::fetchPairs('SELECT `id`, `name` FROM `tags` ORDER BY `name`');

$err = static fn (string $field): string => error_for($errors, $field);
$bad = static fn (string $field): string => error_for($errors, $field) !== '' ? ' is-invalid' : '';

/** One variant row. Reused verbatim for the repeater template. */
$variantRow = static function (array $variant, string $key, array $variantAttributes, bool $isTemplate) use ($err, $bad): void {
    $prefix = $isTemplate ? 'variant_new_' : 'variant_' . (int) $variant['row'] . '_';
    ?>
    <div class="ad-repeater__row"<?= $isTemplate ? ' data-repeat-template style="display:none"' : '' ?>>
        <div style="grid-column:1/-1;display:grid;gap:10px;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));align-items:end">
            <input type="hidden" name="variant_id[]" value="<?= (int) $variant['id'] ?>">
            <input type="hidden" name="variant_key[]" value="<?= e_attr((string) $key) ?>">

            <label class="ad-field">
                <span class="sik-label">Variant SKU</span>
                <input type="text" class="sik-input<?= $bad($prefix . 'sku') ?>" name="variant_sku[]"
                       value="<?= e($variant['sku']) ?>" maxlength="80" placeholder="SKU-WW-5M">
                <?php if ($err($prefix . 'sku') !== ''): ?>
                    <span class="sik-error"><?= e($err($prefix . 'sku')) ?></span>
                <?php endif; ?>
            </label>

            <label class="ad-field">
                <span class="sik-label">Variant name</span>
                <input type="text" class="sik-input<?= $bad($prefix . 'name') ?>" name="variant_name[]"
                       value="<?= e($variant['variant_name']) ?>" maxlength="255" placeholder="Warm White / 5 m">
                <?php if ($err($prefix . 'name') !== ''): ?>
                    <span class="sik-error"><?= e($err($prefix . 'name')) ?></span>
                <?php endif; ?>
            </label>

            <label class="ad-field">
                <span class="sik-label">Price</span>
                <input type="number" step="0.01" min="0" class="sik-input<?= $bad($prefix . 'price') ?>"
                       name="variant_price[]" value="<?= e($variant['price']) ?>">
                <?php if ($err($prefix . 'price') !== ''): ?>
                    <span class="sik-error"><?= e($err($prefix . 'price')) ?></span>
                <?php endif; ?>
            </label>

            <label class="ad-field">
                <span class="sik-label">Sale price</span>
                <input type="number" step="0.01" min="0" class="sik-input<?= $bad($prefix . 'sale_price') ?>"
                       name="variant_sale_price[]" value="<?= e($variant['sale_price']) ?>">
                <?php if ($err($prefix . 'sale_price') !== ''): ?>
                    <span class="sik-error"><?= e($err($prefix . 'sale_price')) ?></span>
                <?php endif; ?>
            </label>

            <label class="ad-field">
                <span class="sik-label">Stock</span>
                <input type="number" step="1" min="0" class="sik-input<?= $bad($prefix . 'stock') ?>"
                       name="variant_stock[]" value="<?= e($variant['stock']) ?>">
                <?php if ($err($prefix . 'stock') !== ''): ?>
                    <span class="sik-error"><?= e($err($prefix . 'stock')) ?></span>
                <?php endif; ?>
            </label>

            <?php foreach ($variantAttributes as $attribute): ?>
                <?php $attributeId = (int) $attribute['id']; ?>
                <label class="ad-field">
                    <span class="sik-label"><?= e($attribute['name']) ?></span>
                    <select class="sik-select" name="variant_attr_<?= $attributeId ?>[]">
                        <?= admin_options($attribute['values'], $variant['attrs'][$attributeId] ?? '', '— none —') ?>
                    </select>
                </label>
            <?php endforeach; ?>

            <label class="ad-field">
                <span class="sik-label">Status</span>
                <select class="sik-select" name="variant_status[]">
                    <?= admin_options(['active' => 'Active', 'inactive' => 'Inactive'], $variant['status']) ?>
                </select>
            </label>

            <div class="ad-field" style="display:flex;gap:10px;align-items:center">
                <label class="sik-check" style="white-space:nowrap">
                    <input type="radio" name="variant_default" value="<?= e_attr((string) $key) ?>"
                           <?= (int) $variant['is_default'] === 1 ? 'checked' : '' ?>>
                    <span>Default</span>
                </label>
                <button type="button" class="ad-btn ad-btn--icon ad-btn--danger-ghost" data-repeat-remove
                        title="Remove variant" aria-label="Remove variant"><?= icon('trash', 'w-4 h-4') ?></button>
            </div>
        </div>
    </div>
    <?php
};

$blankVariant = [
    'row' => 0, 'id' => 0, 'sku' => '', 'variant_name' => '', 'price' => '',
    'sale_price' => '', 'stock' => '0', 'status' => 'active', 'is_default' => 0, 'attrs' => [],
];
?>

<form method="post" action="<?= e($formAction) ?>" enctype="multipart/form-data" class="ad-form" data-guard-unsaved>
    <?= csrf_field() ?>

    <?php if ($errors !== []): ?>
        <div class="sik-alert sik-alert--error">
            <?= icon('alert', 'w-5 h-5') ?>
            <div>
                <strong>This product was not saved.</strong>
                Fix the <?= count($errors) ?> highlighted field(s) below and submit again.
            </div>
        </div>
    <?php endif; ?>

    <div class="ad-card" data-tabs>
        <div class="ad-card__body ad-card__body--flush">
            <div class="sik-tabs" role="tablist" style="padding-inline:14px">
                <button type="button" class="sik-tab is-active" data-tab="general" role="tab" aria-selected="true">General</button>
                <button type="button" class="sik-tab" data-tab="pricing" role="tab" aria-selected="false">Pricing &amp; Stock</button>
                <button type="button" class="sik-tab" data-tab="media" role="tab" aria-selected="false">Media</button>
                <button type="button" class="sik-tab" data-tab="details" role="tab" aria-selected="false">Details</button>
                <button type="button" class="sik-tab" data-tab="variants" role="tab" aria-selected="false">Variants</button>
                <button type="button" class="sik-tab" data-tab="marketing" role="tab" aria-selected="false">Marketing</button>
                <button type="button" class="sik-tab" data-tab="seo" role="tab" aria-selected="false">SEO</button>
            </div>

            <!-- ======================= General ======================= -->
            <div class="sik-tabpanel is-active" data-tabpanel="general" style="padding:20px">
                <div class="ad-row ad-row--2">
                    <label class="ad-field">
                        <span class="sik-label">Product name <span class="req">*</span></span>
                        <input type="text" class="sik-input<?= $bad('name') ?>" name="name" required maxlength="255"
                               value="<?= e($product['name']) ?>" data-slug-source="#productSlug"
                               placeholder="Star Curtain Light, 138 LED, Warm White">
                        <?php if ($err('name') !== ''): ?><span class="sik-error"><?= e($err('name')) ?></span><?php endif; ?>
                    </label>

                    <label class="ad-field">
                        <span class="sik-label">Slug <span class="req">*</span></span>
                        <input type="text" class="sik-input<?= $bad('slug') ?>" name="slug" id="productSlug"
                               maxlength="280" data-slugify value="<?= e($product['slug']) ?>">
                        <span class="sik-help">Auto-filled from the name. The storefront URL is /product/&lt;slug&gt;.</span>
                        <?php
                        /* Offers to keep the current URL alive as a 301 when the slug
                           changes. Renders nothing on a create, where there is no old URL. */
                        seo_slug_keep_checkbox('product', $isEdit ? (string) $product['slug'] : '');
                        ?>
                        <?php if ($err('slug') !== ''): ?><span class="sik-error"><?= e($err('slug')) ?></span><?php endif; ?>
                    </label>
                </div>

                <div class="ad-row ad-row--3">
                    <label class="ad-field">
                        <span class="sik-label">SKU <span class="req">*</span></span>
                        <input type="text" class="sik-input<?= $bad('sku') ?>" name="sku" required maxlength="80"
                               value="<?= e($product['sku']) ?>" placeholder="LT-STAR-138-WW">
                        <?php if ($err('sku') !== ''): ?><span class="sik-error"><?= e($err('sku')) ?></span><?php endif; ?>
                    </label>

                    <label class="ad-field">
                        <span class="sik-label">Category</span>
                        <select class="sik-select<?= $bad('category_id') ?>" name="category_id">
                            <?= admin_category_options($product['category_id'], null, '— Uncategorised —') ?>
                        </select>
                        <?php if ($err('category_id') !== ''): ?><span class="sik-error"><?= e($err('category_id')) ?></span><?php endif; ?>
                    </label>

                    <label class="ad-field">
                        <span class="sik-label">Brand</span>
                        <select class="sik-select<?= $bad('brand_id') ?>" name="brand_id">
                            <?= admin_options($brandOptions, $product['brand_id'], '— No brand —') ?>
                        </select>
                        <?php if ($err('brand_id') !== ''): ?><span class="sik-error"><?= e($err('brand_id')) ?></span><?php endif; ?>
                    </label>
                </div>

                <label class="ad-field">
                    <span class="sik-label">Short description</span>
                    <textarea class="sik-textarea" name="short_description" rows="3" style="min-height:80px"
                              placeholder="One or two lines shown on cards and in search results."><?= e($product['short_description']) ?></textarea>
                </label>

                <label class="ad-field">
                    <span class="sik-label">Full description</span>
                    <textarea class="sik-textarea" name="description" rows="12"
                              style="min-height:240px"><?= e($product['description']) ?></textarea>
                    <span class="sik-help">Basic HTML is allowed (headings, lists, links, tables). Scripts are stripped on save.</span>
                </label>

                <div class="ad-row ad-row--2">
                    <label class="ad-field">
                        <span class="sik-label">Status</span>
                        <select class="sik-select<?= $bad('status') ?>" name="status">
                            <?= admin_options(product_status_options(), $product['status']) ?>
                        </select>
                        <span class="sik-help">Only "Active" products are visible on the storefront.</span>
                    </label>

                    <label class="ad-field">
                        <span class="sik-label">Publish at</span>
                        <input type="datetime-local" class="sik-input<?= $bad('published_at') ?>" name="published_at"
                               value="<?= e($product['published_at'] !== '' && $product['published_at'] !== null
                                   ? date('Y-m-d\TH:i', (int) strtotime((string) $product['published_at'])) : '') ?>">
                        <span class="sik-help">Leave blank to go live as soon as the status is Active.</span>
                        <?php if ($err('published_at') !== ''): ?><span class="sik-error"><?= e($err('published_at')) ?></span><?php endif; ?>
                    </label>
                </div>
            </div>

            <!-- ==================== Pricing & Stock =================== -->
            <div class="sik-tabpanel" data-tabpanel="pricing" style="padding:20px">
                <div class="ad-row ad-row--3">
                    <label class="ad-field">
                        <span class="sik-label">Price (MRP) <span class="req">*</span></span>
                        <input type="number" step="0.01" min="0" class="sik-input<?= $bad('price') ?>" name="price"
                               required value="<?= e($product['price']) ?>">
                        <?php if ($err('price') !== ''): ?><span class="sik-error"><?= e($err('price')) ?></span><?php endif; ?>
                    </label>

                    <label class="ad-field">
                        <span class="sik-label">Sale price</span>
                        <input type="number" step="0.01" min="0" class="sik-input<?= $bad('sale_price') ?>"
                               name="sale_price" value="<?= e($product['sale_price']) ?>">
                        <span class="sik-help">Leave blank when the product is not on offer.</span>
                        <?php if ($err('sale_price') !== ''): ?><span class="sik-error"><?= e($err('sale_price')) ?></span><?php endif; ?>
                    </label>

                    <label class="ad-field">
                        <span class="sik-label">Cost price</span>
                        <input type="number" step="0.01" min="0" class="sik-input<?= $bad('cost_price') ?>"
                               name="cost_price" value="<?= e($product['cost_price']) ?>">
                        <span class="sik-help">Internal only — used for margin reports.</span>
                        <?php if ($err('cost_price') !== ''): ?><span class="sik-error"><?= e($err('cost_price')) ?></span><?php endif; ?>
                    </label>
                </div>

                <div class="ad-row ad-row--3">
                    <label class="ad-field">
                        <span class="sik-label">Stock</span>
                        <input type="number" step="1" min="0" class="sik-input<?= $bad('stock') ?>" name="stock"
                               value="<?= e($product['stock']) ?>">
                        <span class="sik-help">Changes here are journalled to the stock log. Ignored once variants exist.</span>
                        <?php if ($err('stock') !== ''): ?><span class="sik-error"><?= e($err('stock')) ?></span><?php endif; ?>
                    </label>

                    <label class="ad-field">
                        <span class="sik-label">Low stock threshold</span>
                        <input type="number" step="1" min="0" class="sik-input<?= $bad('low_stock_threshold') ?>"
                               name="low_stock_threshold" value="<?= e($product['low_stock_threshold']) ?>">
                        <?php if ($err('low_stock_threshold') !== ''): ?><span class="sik-error"><?= e($err('low_stock_threshold')) ?></span><?php endif; ?>
                    </label>

                    <label class="ad-field">
                        <span class="sik-label">Weight (kg)</span>
                        <input type="number" step="0.001" min="0" class="sik-input<?= $bad('weight') ?>" name="weight"
                               value="<?= e($product['weight']) ?>">
                        <?php if ($err('weight') !== ''): ?><span class="sik-error"><?= e($err('weight')) ?></span><?php endif; ?>
                    </label>
                </div>

                <div class="ad-row ad-row--3">
                    <label class="ad-field">
                        <span class="sik-label">Minimum order qty</span>
                        <input type="number" step="1" min="1" class="sik-input<?= $bad('min_order_qty') ?>"
                               name="min_order_qty" value="<?= e($product['min_order_qty']) ?>">
                        <?php if ($err('min_order_qty') !== ''): ?><span class="sik-error"><?= e($err('min_order_qty')) ?></span><?php endif; ?>
                    </label>

                    <label class="ad-field">
                        <span class="sik-label">Maximum order qty</span>
                        <input type="number" step="1" min="1" class="sik-input<?= $bad('max_order_qty') ?>"
                               name="max_order_qty" value="<?= e($product['max_order_qty']) ?>">
                        <?php if ($err('max_order_qty') !== ''): ?><span class="sik-error"><?= e($err('max_order_qty')) ?></span><?php endif; ?>
                    </label>

                    <label class="ad-field">
                        <span class="sik-label">GST rate (%)</span>
                        <input type="number" step="0.01" min="0" max="100" class="sik-input<?= $bad('tax_rate') ?>"
                               name="tax_rate" value="<?= e($product['tax_rate']) ?>">
                        <?php if ($err('tax_rate') !== ''): ?><span class="sik-error"><?= e($err('tax_rate')) ?></span><?php endif; ?>
                    </label>
                </div>

                <div class="ad-row ad-row--3">
                    <label class="ad-field">
                        <span class="sik-label">HSN code</span>
                        <input type="text" class="sik-input<?= $bad('hsn_code') ?>" name="hsn_code" maxlength="20"
                               value="<?= e($product['hsn_code']) ?>">
                        <?php if ($err('hsn_code') !== ''): ?><span class="sik-error"><?= e($err('hsn_code')) ?></span><?php endif; ?>
                    </label>

                    <div class="ad-field">
                        <span class="sik-label">Cash on delivery</span>
                        <label class="ad-switch">
                            <input type="checkbox" name="cod_available" value="1" <?= (int) $product['cod_available'] === 1 ? 'checked' : '' ?>>
                            <span class="ad-switch__track"></span>
                            <span>Allow COD for this product</span>
                        </label>
                    </div>

                    <div class="ad-field">
                        <span class="sik-label">Shipping</span>
                        <label class="ad-switch">
                            <input type="checkbox" name="free_shipping" value="1" <?= (int) $product['free_shipping'] === 1 ? 'checked' : '' ?>>
                            <span class="ad-switch__track"></span>
                            <span>Always ship free</span>
                        </label>
                    </div>
                </div>
            </div>

            <!-- ========================= Media ======================== -->
            <div class="sik-tabpanel" data-tabpanel="media" style="padding:20px">
                <div class="ad-row ad-row--2">
                    <div class="ad-field">
                        <span class="sik-label">Main image</span>
                        <input type="hidden" name="remove_main_image" id="removeMainImage" value="0">
                        <div class="ad-drop" data-drop="#mainImagePreview">
                            <?= icon('upload', 'w-6 h-6') ?>
                            <div style="font-size:13px;margin-top:6px">Click or drop an image here</div>
                            <input type="file" name="main_image" accept="image/*">
                        </div>
                        <div class="ad-preview" id="mainImagePreview">
                            <?php if (!empty($product['main_image'])): ?>
                                <div class="ad-preview__item">
                                    <img src="<?= e(img_url((string) $product['main_image'])) ?>" alt="Current main image">
                                    <button type="button" class="ad-preview__remove" data-remove-image="#removeMainImage"
                                            aria-label="Remove main image">&times;</button>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="ad-field">
                        <span class="sik-label">Hover image</span>
                        <input type="hidden" name="remove_hover_image" id="removeHoverImage" value="0">
                        <div class="ad-drop" data-drop="#hoverImagePreview">
                            <?= icon('upload', 'w-6 h-6') ?>
                            <div style="font-size:13px;margin-top:6px">Shown when a shopper hovers the card</div>
                            <input type="file" name="hover_image" accept="image/*">
                        </div>
                        <div class="ad-preview" id="hoverImagePreview">
                            <?php if (!empty($product['hover_image'])): ?>
                                <div class="ad-preview__item">
                                    <img src="<?= e(img_url((string) $product['hover_image'])) ?>" alt="Current hover image">
                                    <button type="button" class="ad-preview__remove" data-remove-image="#removeHoverImage"
                                            aria-label="Remove hover image">&times;</button>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="ad-field">
                    <span class="sik-label">Gallery</span>
                    <div class="ad-drop" data-drop="#galleryPreview">
                        <?= icon('upload', 'w-6 h-6') ?>
                        <div style="font-size:13px;margin-top:6px">Click or drop one or more images</div>
                        <input type="file" name="gallery[]" accept="image/*" multiple>
                    </div>
                    <div class="ad-preview" id="galleryPreview"></div>

                    <?php if ($images !== []): ?>
                        <p class="sik-help" style="margin-top:14px">Saved gallery images — tick "Remove" to delete the file on save.</p>
                        <div style="display:grid;gap:10px;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));margin-top:8px">
                            <?php foreach ($images as $image): ?>
                                <div style="border:1px solid var(--ad-border);border-radius:9px;padding:10px;background:var(--ad-bg)">
                                    <img src="<?= e(img_url((string) $image['image'])) ?>" alt=""
                                         style="width:100%;height:110px;object-fit:contain;background:#fff;border-radius:6px">
                                    <label class="ad-field" style="margin-top:8px">
                                        <span class="sik-label" style="font-size:11.5px">Alt text</span>
                                        <input type="text" class="sik-input" style="padding:7px 10px;font-size:var(--ad-text-sm)"
                                               name="image_alt[<?= (int) $image['id'] ?>]" maxlength="200"
                                               value="<?= e((string) ($image['alt_text'] ?? '')) ?>">
                                    </label>
                                    <label class="sik-check" style="margin-top:8px">
                                        <input type="checkbox" name="remove_images[]" value="<?= (int) $image['id'] ?>">
                                        <span>Remove</span>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <label class="ad-field">
                    <span class="sik-label">Video URL</span>
                    <input type="url" class="sik-input<?= $bad('video_url') ?>" name="video_url" maxlength="255"
                           value="<?= e($product['video_url']) ?>" placeholder="https://www.youtube.com/watch?v=...">
                    <?php if ($err('video_url') !== ''): ?><span class="sik-error"><?= e($err('video_url')) ?></span><?php endif; ?>
                </label>
            </div>

            <!-- ======================== Details ======================= -->
            <div class="sik-tabpanel" data-tabpanel="details" style="padding:20px">
                <div class="ad-row ad-row--3">
                    <label class="ad-field">
                        <span class="sik-label">Manufacturer</span>
                        <input type="text" class="sik-input<?= $bad('manufacturer') ?>" name="manufacturer"
                               maxlength="150" value="<?= e($product['manufacturer']) ?>">
                        <?php if ($err('manufacturer') !== ''): ?><span class="sik-error"><?= e($err('manufacturer')) ?></span><?php endif; ?>
                    </label>

                    <label class="ad-field">
                        <span class="sik-label">Model number</span>
                        <input type="text" class="sik-input<?= $bad('model_number') ?>" name="model_number"
                               maxlength="100" value="<?= e($product['model_number']) ?>">
                        <?php if ($err('model_number') !== ''): ?><span class="sik-error"><?= e($err('model_number')) ?></span><?php endif; ?>
                    </label>

                    <label class="ad-field">
                        <span class="sik-label">Part number</span>
                        <input type="text" class="sik-input<?= $bad('part_number') ?>" name="part_number"
                               maxlength="100" value="<?= e($product['part_number']) ?>">
                        <?php if ($err('part_number') !== ''): ?><span class="sik-error"><?= e($err('part_number')) ?></span><?php endif; ?>
                    </label>
                </div>

                <div class="ad-row ad-row--2">
                    <label class="ad-field">
                        <span class="sik-label">Warranty</span>
                        <input type="text" class="sik-input<?= $bad('warranty') ?>" name="warranty" maxlength="150"
                               <?php // The hint has to be the cover the store actually gives. Every
                                     // product in the catalogue carries "6 Month Seller Warranty", and
                                     // that is what the seed and the CSV import template emit, so a
                                     // manufacturer-warranty hint would invite a claim nobody honours. ?>
                               value="<?= e($product['warranty']) ?>" placeholder="6 Month Seller Warranty">
                        <?php if ($err('warranty') !== ''): ?><span class="sik-error"><?= e($err('warranty')) ?></span><?php endif; ?>
                    </label>

                    <label class="ad-field">
                        <span class="sik-label">EMI note</span>
                        <input type="text" class="sik-input<?= $bad('emi_text') ?>" name="emi_text" maxlength="150"
                               value="<?= e($product['emi_text']) ?>" placeholder="Leave blank unless EMI is offered">
                        <?php if ($err('emi_text') !== ''): ?><span class="sik-error"><?= e($err('emi_text')) ?></span><?php endif; ?>
                    </label>
                </div>

                <label class="ad-field">
                    <span class="sik-label">Compatibility</span>
                    <textarea class="sik-textarea<?= $bad('compatibility') ?>" name="compatibility" rows="2"
                              maxlength="500" style="min-height:70px"
                              placeholder="Runs from any 5V USB adapter or power bank; indoor use only"><?= e($product['compatibility']) ?></textarea>
                    <?php if ($err('compatibility') !== ''): ?><span class="sik-error"><?= e($err('compatibility')) ?></span><?php endif; ?>
                </label>

                <fieldset class="ad-fieldset">
                    <legend>Specifications</legend>
                    <div id="specRepeater">
                        <?php foreach ($specs as $spec): ?>
                            <div class="ad-repeater__row">
                                <div style="grid-column:1/-1;display:grid;gap:10px;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));align-items:end">
                                    <label class="ad-field">
                                        <span class="sik-label">Group</span>
                                        <input type="text" class="sik-input" name="spec_group[]" maxlength="100"
                                               value="<?= e((string) $spec['spec_group']) ?>" placeholder="Lighting">
                                    </label>
                                    <label class="ad-field">
                                        <span class="sik-label">Specification</span>
                                        <input type="text" class="sik-input" name="spec_key[]" maxlength="150"
                                               value="<?= e((string) $spec['spec_key']) ?>" placeholder="LED count">
                                    </label>
                                    <label class="ad-field">
                                        <span class="sik-label">Value</span>
                                        <input type="text" class="sik-input" name="spec_value[]" maxlength="500"
                                               value="<?= e((string) $spec['spec_value']) ?>" placeholder="138 LEDs, warm white">
                                    </label>
                                    <div class="ad-field">
                                        <button type="button" class="ad-btn ad-btn--icon ad-btn--danger-ghost" data-repeat-remove
                                                title="Remove" aria-label="Remove specification"><?= icon('trash', 'w-4 h-4') ?></button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>

                        <div class="ad-repeater__row" data-repeat-template style="display:none">
                            <div style="grid-column:1/-1;display:grid;gap:10px;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));align-items:end">
                                <label class="ad-field">
                                    <span class="sik-label">Group</span>
                                    <input type="text" class="sik-input" name="spec_group[]" maxlength="100" placeholder="Lighting">
                                </label>
                                <label class="ad-field">
                                    <span class="sik-label">Specification</span>
                                    <input type="text" class="sik-input" name="spec_key[]" maxlength="150" placeholder="LED count">
                                </label>
                                <label class="ad-field">
                                    <span class="sik-label">Value</span>
                                    <input type="text" class="sik-input" name="spec_value[]" maxlength="500" placeholder="138 LEDs, warm white">
                                </label>
                                <div class="ad-field">
                                    <button type="button" class="ad-btn ad-btn--icon ad-btn--danger-ghost" data-repeat-remove
                                            title="Remove" aria-label="Remove specification"><?= icon('trash', 'w-4 h-4') ?></button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <button type="button" class="ad-btn ad-btn--sm" data-repeat-add="#specRepeater">
                        <?= icon('plus', 'w-4 h-4') ?> Add specification
                    </button>
                </fieldset>

                <fieldset class="ad-fieldset">
                    <legend>Key features</legend>
                    <div id="featureRepeater">
                        <?php foreach ($features as $feature): ?>
                            <div class="ad-repeater__row">
                                <div style="grid-column:1/-1;display:grid;gap:10px;grid-template-columns:1fr auto;align-items:end">
                                    <label class="ad-field">
                                        <span class="sik-label">Feature</span>
                                        <input type="text" class="sik-input" name="feature[]" maxlength="300"
                                               value="<?= e((string) $feature) ?>">
                                    </label>
                                    <div class="ad-field">
                                        <button type="button" class="ad-btn ad-btn--icon ad-btn--danger-ghost" data-repeat-remove
                                                title="Remove" aria-label="Remove feature"><?= icon('trash', 'w-4 h-4') ?></button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>

                        <div class="ad-repeater__row" data-repeat-template style="display:none">
                            <div style="grid-column:1/-1;display:grid;gap:10px;grid-template-columns:1fr auto;align-items:end">
                                <label class="ad-field">
                                    <span class="sik-label">Feature</span>
                                    <input type="text" class="sik-input" name="feature[]" maxlength="300"
                                           placeholder="8 lighting modes with memory">
                                </label>
                                <div class="ad-field">
                                    <button type="button" class="ad-btn ad-btn--icon ad-btn--danger-ghost" data-repeat-remove
                                            title="Remove" aria-label="Remove feature"><?= icon('trash', 'w-4 h-4') ?></button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <button type="button" class="ad-btn ad-btn--sm" data-repeat-add="#featureRepeater">
                        <?= icon('plus', 'w-4 h-4') ?> Add feature
                    </button>
                </fieldset>
            </div>

            <!-- ======================= Variants ======================= -->
            <div class="sik-tabpanel" data-tabpanel="variants" style="padding:20px">
                <?php /* Variant stock changes go through adjust_stock() like any other movement, so the
                         journal stays complete. The Stock field on the General tab says "Ignored once
                         variants exist", which is the half of this an operator has to act on. */ ?>
                <div class="sik-alert sik-alert--info">
                    <?= icon('info', 'w-5 h-5') ?>
                    <div>
                        One row per buyable combination. The product&rsquo;s stock becomes the sum
                        of its active variants.
                    </div>
                </div>

                <div id="variantRepeater">
                    <?php foreach ($variants as $variant): ?>
                        <?php $variantRow($variant, (string) ($variant['key'] ?? $variant['row']), $variantAttributes, false); ?>
                    <?php endforeach; ?>
                    <?php $variantRow($blankVariant, '__INDEX__', $variantAttributes, true); ?>
                </div>

                <button type="button" class="ad-btn ad-btn--sm" data-repeat-add="#variantRepeater">
                    <?= icon('plus', 'w-4 h-4') ?> Add variant
                </button>
            </div>

            <!-- ======================= Marketing ====================== -->
            <div class="sik-tabpanel" data-tabpanel="marketing" style="padding:20px">
                <fieldset class="ad-fieldset">
                    <legend>Placement flags</legend>
                    <div class="ad-row ad-row--2">
                        <label class="ad-switch">
                            <input type="checkbox" name="is_featured" value="1" <?= (int) $product['is_featured'] === 1 ? 'checked' : '' ?>>
                            <span class="ad-switch__track"></span><span>Featured</span>
                        </label>
                        <label class="ad-switch">
                            <input type="checkbox" name="is_new_arrival" value="1" <?= (int) $product['is_new_arrival'] === 1 ? 'checked' : '' ?>>
                            <span class="ad-switch__track"></span><span>New arrival</span>
                        </label>
                        <label class="ad-switch">
                            <input type="checkbox" name="is_best_seller" value="1" <?= (int) $product['is_best_seller'] === 1 ? 'checked' : '' ?>>
                            <span class="ad-switch__track"></span><span>Best seller</span>
                        </label>
                        <label class="ad-switch">
                            <input type="checkbox" name="is_trending" value="1" <?= (int) $product['is_trending'] === 1 ? 'checked' : '' ?>>
                            <span class="ad-switch__track"></span><span>Trending</span>
                        </label>
                    </div>
                </fieldset>

                <div class="ad-row ad-row--2">
                    <label class="ad-field">
                        <span class="sik-label">Badge text</span>
                        <input type="text" class="sik-input<?= $bad('badge_text') ?>" name="badge_text" maxlength="40"
                               value="<?= e($product['badge_text']) ?>" placeholder="EXCLUSIVE">
                        <span class="sik-help">Overrides the automatic card badge.</span>
                        <?php if ($err('badge_text') !== ''): ?><span class="sik-error"><?= e($err('badge_text')) ?></span><?php endif; ?>
                    </label>

                    <label class="ad-field">
                        <span class="sik-label">Badge colour</span>
                        <select class="sik-select<?= $bad('badge_color') ?>" name="badge_color">
                            <?= admin_options(product_badge_colors(), $product['badge_color'], '— Default (navy) —') ?>
                        </select>
                        <?php if ($err('badge_color') !== ''): ?><span class="sik-error"><?= e($err('badge_color')) ?></span><?php endif; ?>
                    </label>
                </div>

                <div class="ad-row ad-row--2">
                    <label class="ad-field">
                        <span class="sik-label">Tags</span>
                        <select class="sik-select" name="tags[]" multiple size="8" style="height:auto;padding:8px">
                            <?php foreach ($allTags as $tagId => $tagName): ?>
                                <option value="<?= (int) $tagId ?>" <?= in_array((int) $tagId, $tagIds, true) ? 'selected' : '' ?>>
                                    <?= e((string) $tagName) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <span class="sik-help">Ctrl/Cmd-click to select more than one.</span>
                    </label>

                    <label class="ad-field">
                        <span class="sik-label">New tags</span>
                        <input type="text" class="sik-input" name="new_tags" value="<?= e($state['new_tags']) ?>"
                               placeholder="Diwali, Warm White">
                        <span class="sik-help">Comma separated. Anything new here is created and attached on save.</span>
                    </label>
                </div>

                <fieldset class="ad-fieldset">
                    <legend>Related products</legend>
                    <div class="ad-field" style="position:relative">
                        <span class="sik-label">Search the catalogue</span>
                        <input type="search" class="sik-input" data-product-search="#relatedResults"
                               placeholder="Type at least 2 characters&hellip;" autocomplete="off"
                               onkeydown="if(event.key==='Enter'){event.preventDefault();}">
                        <div id="relatedResults" data-product-results
                             style="background:#fff;border:1px solid var(--ad-border);border-radius:9px;margin-top:6px;
                                    max-height:260px;overflow-y:auto"></div>
                    </div>

                    <div data-picked-products style="margin-top:10px">
                        <?php foreach ($related as $relatedProduct): ?>
                            <div class="ad-cellflex" data-picked="<?= (int) $relatedProduct['id'] ?>"
                                 style="padding:8px;border:1px solid var(--ad-border);border-radius:8px;margin-bottom:6px">
                                <img class="ad-thumb" src="<?= e(img_url($relatedProduct['main_image'])) ?>" alt="">
                                <span class="ad-cellflex__name" style="flex:1"><?= e((string) $relatedProduct['name']) ?></span>
                                <input type="hidden" name="product_ids[]" value="<?= (int) $relatedProduct['id'] ?>">
                                <button type="button" class="ad-btn ad-btn--icon ad-btn--danger-ghost" data-unpick
                                        aria-label="Remove related product">&times;</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <span class="sik-help">These appear in the "Related products" rail on the product page.</span>
                </fieldset>
            </div>

            <!-- ========================== SEO ========================= -->
            <div class="sik-tabpanel" data-tabpanel="seo" style="padding:20px">
                <?php
                // The shared editor, not a second pair of meta fields. It owns
                // meta title/description, focus keyword, canonical, robots, the
                // social trio and custom JSON-LD, and previews the result as
                // Google and a social card will actually show it.
                require_once ADMIN_PATH . '/includes/seo-editor.php';
                seo_editor($product, [
                    'url'              => $isEdit && $product['slug'] !== ''
                        ? product_url((string) $product['slug'])
                        : url(),
                    'title_from'       => 'name',
                    'description_from' => 'short_description',
                    'type'             => 'product',
                    'id'               => $isEdit ? (int) $product['id'] : 0,
                ]);
                ?>
                <?php if ($err('meta_title') !== ''): ?>
                    <span class="sik-error"><?= e($err('meta_title')) ?></span>
                <?php endif; ?>
            </div>
        </div>

        <div class="ad-card__foot" style="display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap">
            <a class="ad-btn" href="<?= e(admin_url('products/')) ?>">Cancel</a>
            <?php if ($isEdit): ?>
                <a class="ad-btn" href="<?= e(admin_url('products/view.php?id=' . $productId)) ?>">
                    <?= icon('eye', 'w-4 h-4') ?> View
                </a>
            <?php endif; ?>
            <button type="submit" class="ad-btn ad-btn--primary">
                <?= icon('check', 'w-4 h-4') ?> <?= e($submitLabel) ?>
            </button>
        </div>
    </div>
</form>
