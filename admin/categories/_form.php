<?php
/**
 * ShopInnKart Admin - Category form body.
 *
 * Shared by create.php and edit.php. Both set:
 *   $category  array of field values (existing row, or defaults + submitted input)
 *   $errors    field => message
 *   $isEdit    bool
 */

declare(strict_types=1);


// Include-only: this partial assumes its parent page already ran the
// authentication and permission checks. Refuse to run as an entry point.
if (!defined('SIK_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}
/** @var array $category @var array $errors @var bool $isEdit */
$isEdit    = $isEdit ?? false;
$errors    = $errors ?? [];
$categoryId = (int) ($category['id'] ?? 0);
$iconValue = (string) ($category['icon'] ?? '');
?>
<style>
    /* Scoped to this form: the icon set is small enough to pick visually,
       which beats typing a key name that has to match icon_paths(). */
    .ad-iconpick {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(84px, 1fr));
        gap: 8px;
        max-height: 246px;
        overflow-y: auto;
        padding: 10px;
        border: 1px solid var(--ad-border);
        border-radius: 10px;
        background: var(--ad-bg);
    }
    .ad-iconpick input { position: absolute; opacity: 0; width: 0; height: 0; }
    .ad-iconpick__box {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 5px;
        padding: 9px 4px;
        border: 1px solid var(--ad-border);
        border-radius: 9px;
        background: #fff;
        cursor: pointer;
        font-size: 11.5px;
        color: var(--ad-muted);
        text-align: center;
    }
    .ad-iconpick__box em { font-style: normal; max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .ad-iconpick input:checked + .ad-iconpick__box {
        border-color: var(--ad-primary);
        color: var(--ad-primary);
        box-shadow: 0 0 0 2px rgba(244, 81, 30, .16);
    }
    .ad-iconpick input:focus-visible + .ad-iconpick__box { outline: 2px solid var(--ad-primary); outline-offset: 2px; }
</style>

<form class="ad-form" method="post" enctype="multipart/form-data" data-guard-unsaved>
    <?= csrf_field() ?>

    <div class="ad-grid ad-grid--sidebar">
        <div style="display:grid;gap:16px">

            <div class="ad-card" style="margin:0">
                <div class="ad-card__head"><div class="ad-card__title">Basics</div></div>
                <div class="ad-card__body">
                    <div class="ad-row ad-row--2">
                        <div class="ad-field">
                            <label class="sik-label" for="catName">Name <span class="req">*</span></label>
                            <input class="sik-input<?= isset($errors['name']) ? ' is-invalid' : '' ?>" type="text"
                                   id="catName" name="name" maxlength="150" required
                                   data-slug-source="#catSlug"
                                   value="<?= e($category['name'] ?? '') ?>" placeholder="e.g. Gaming Laptops">
                            <?php if (isset($errors['name'])): ?>
                                <span class="sik-error"><?= e($errors['name']) ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="ad-field">
                            <label class="sik-label" for="catSlug">Slug</label>
                            <input class="sik-input<?= isset($errors['slug']) ? ' is-invalid' : '' ?>" type="text"
                                   id="catSlug" name="slug" maxlength="180" data-slugify
                                   value="<?= e($category['slug'] ?? '') ?>" placeholder="gaming-laptops">
                            <?php if (isset($errors['slug'])): ?>
                                <span class="sik-error"><?= e($errors['slug']) ?></span>
                            <?php else: ?>
                                <span class="sik-help">Leave blank to build it from the name. Used in the category URL.</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="ad-field">
                        <label class="sik-label" for="catParent">Parent category</label>
                        <select class="sik-select" id="catParent" name="parent_id">
                            <?= admin_category_options(
                                $category['parent_id'] ?? null,
                                $isEdit && $categoryId > 0 ? $categoryId : null,
                                '— None (top level) —'
                            ) ?>
                        </select>
                        <?php if (isset($errors['parent_id'])): ?>
                            <span class="sik-error"><?= e($errors['parent_id']) ?></span>
                        <?php else: ?>
                            <span class="sik-help">A category and its own descendants are not offered here.</span>
                        <?php endif; ?>
                    </div>

                    <div class="ad-field">
                        <label class="sik-label" for="catDescription">Description</label>
                        <textarea class="sik-textarea" id="catDescription" name="description" rows="5"
                                  placeholder="Shown on the category landing page."><?= e($category['description'] ?? '') ?></textarea>
                        <span class="sik-help">Basic HTML is allowed; scripts and event handlers are stripped on save.</span>
                    </div>
                </div>
            </div>

            <div class="ad-card" style="margin:0">
                <div class="ad-card__head">
                    <div class="ad-card__title">Artwork</div>
                    <div class="ad-card__sub">JPG, PNG, WEBP, GIF or SVG up to <?= e(format_bytes(MAX_UPLOAD_SIZE)) ?></div>
                </div>
                <div class="ad-card__body">
                    <div class="ad-row ad-row--2">
                        <div class="ad-field">
                            <label class="sik-label">Thumbnail image</label>
                            <p class="sik-help" style="margin:-2px 0 8px">
                                Shown on the homepage category row. A <strong>PNG or SVG</strong> is treated as
                                artwork and sits inside the circle with space around it; a
                                <strong>JPG or WEBP</strong> is treated as a photograph and fills it. With no image
                                the tile borrows a photo from the first product in the category.
                            </p>
                            <div class="ad-drop" data-drop="#catImagePreview">
                                <input type="file" name="image" accept="image/*">
                                <?= icon('upload', 'w-6 h-6') ?>
                                <div style="font-size:13px;margin-top:6px">Click or drop an image here</div>
                            </div>
                            <div class="ad-preview" id="catImagePreview">
                                <?php if (!empty($category['image'])): ?>
                                    <div class="ad-preview__item">
                                        <img src="<?= e(img_url($category['image'])) ?>" alt="Current category image">
                                        <button type="button" class="ad-preview__remove"
                                                data-remove-image="#catRemoveImage"
                                                aria-label="Remove image">&times;</button>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <input type="hidden" name="remove_image" id="catRemoveImage" value="0">
                        </div>

                        <div class="ad-field">
                            <label class="sik-label">Banner</label>
                            <div class="ad-drop" data-drop="#catBannerPreview">
                                <input type="file" name="banner" accept="image/*">
                                <?= icon('upload', 'w-6 h-6') ?>
                                <div style="font-size:13px;margin-top:6px">Wide artwork for the category page header</div>
                            </div>
                            <div class="ad-preview" id="catBannerPreview">
                                <?php if (!empty($category['banner'])): ?>
                                    <div class="ad-preview__item">
                                        <img src="<?= e(img_url($category['banner'])) ?>" alt="Current category banner">
                                        <button type="button" class="ad-preview__remove"
                                                data-remove-image="#catRemoveBanner"
                                                aria-label="Remove banner">&times;</button>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <input type="hidden" name="remove_banner" id="catRemoveBanner" value="0">
                        </div>
                    </div>

                    <div class="ad-field">
                        <label class="sik-label" id="catIconLabel">Menu icon</label>
                        <div class="ad-iconpick" role="radiogroup" aria-labelledby="catIconLabel">
                            <label class="ad-iconpick__tile">
                                <input type="radio" name="icon" value="" <?= $iconValue === '' ? 'checked' : '' ?>>
                                <span class="ad-iconpick__box">
                                    <?= icon('close', 'w-5 h-5') ?><em>None</em>
                                </span>
                            </label>
                            <?php foreach (icon_names() as $name): ?>
                                <label class="ad-iconpick__tile">
                                    <input type="radio" name="icon" value="<?= e_attr($name) ?>"
                                           <?= $iconValue === $name ? 'checked' : '' ?>>
                                    <span class="ad-iconpick__box">
                                        <?= icon($name, 'w-5 h-5') ?><em><?= e($name) ?></em>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <?php if (isset($errors['icon'])): ?>
                            <span class="sik-error"><?= e($errors['icon']) ?></span>
                        <?php else: ?>
                            <span class="sik-help">Read by the categories API. The storefront menus draw their
                                glyph from the matching entry in Content &gt; Menu Builder, and the homepage
                                category rail uses the thumbnail above — neither reads this field.</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="ad-card" style="margin:0">
                <div class="ad-card__head"><div class="ad-card__title">Search engine listing</div></div>
                <div class="ad-card__body">
                    <?php
                    // The shared editor, not a second pair of meta fields. It owns
                    // meta title/description, focus keyword, canonical, robots, the
                    // social trio and custom JSON-LD, and previews the result as
                    // Google and a social card will actually show it. Leaving the
                    // old inputs alongside it would post meta_title twice.
                    require_once ADMIN_PATH . '/includes/seo-editor.php';
                    seo_editor($category, [
                        'url'              => $isEdit && (string) ($category['slug'] ?? '') !== ''
                            ? category_url((string) $category['slug'])
                            : url(),
                        'title_from'       => 'name',
                        'description_from' => 'description',
                    ]);
                    ?>
                    <?php if (isset($errors['meta_title'])): ?>
                        <span class="sik-error"><?= e($errors['meta_title']) ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div style="display:grid;gap:16px;align-content:start">
            <div class="ad-card" style="margin:0">
                <div class="ad-card__head"><div class="ad-card__title">Visibility</div></div>
                <div class="ad-card__body" style="display:grid;gap:14px">
                    <div class="ad-field">
                        <label class="sik-label" for="catStatus">Status</label>
                        <select class="sik-select" id="catStatus" name="status">
                            <?= admin_options(
                                ['active' => 'Active', 'inactive' => 'Inactive'],
                                $category['status'] ?? 'active'
                            ) ?>
                        </select>
                        <span class="sik-help">Inactive categories disappear from the storefront entirely.</span>
                    </div>

                    <div class="ad-field">
                        <label class="sik-label" for="catSortOrder">Sort order</label>
                        <input class="sik-input<?= isset($errors['sort_order']) ? ' is-invalid' : '' ?>" type="number"
                               id="catSortOrder" name="sort_order" min="0" max="9999" step="1"
                               value="<?= (int) ($category['sort_order'] ?? 0) ?>">
                        <?php if (isset($errors['sort_order'])): ?>
                            <span class="sik-error"><?= e($errors['sort_order']) ?></span>
                        <?php else: ?>
                            <span class="sik-help">Lower numbers come first among siblings.</span>
                        <?php endif; ?>
                    </div>

                    <label class="ad-switch">
                        <input type="checkbox" name="show_in_menu" value="1"
                               <?= (int) ($category['show_in_menu'] ?? 1) === 1 ? 'checked' : '' ?>>
                        <span class="ad-switch__track"></span>
                        <span>Show in the main menu</span>
                    </label>
                    <span class="sik-help" style="margin-top:-8px">Hides it from the header nav, the mega
                        panels and the mobile drawer. The category page, the shop filters and the sitemap
                        keep it — use Status for a full removal.</span>

                    <label class="ad-switch">
                        <input type="checkbox" name="is_featured" value="1"
                               <?= (int) ($category['is_featured'] ?? 0) === 1 ? 'checked' : '' ?>>
                        <span class="ad-switch__track"></span>
                        <span>Featured on the homepage</span>
                    </label>
                </div>
                <div class="ad-card__foot">
                    <a class="ad-btn" href="<?= e(admin_url('categories/')) ?>">Cancel</a>
                    <button type="submit" class="ad-btn ad-btn--primary">
                        <?= icon('check', 'w-4 h-4') ?>
                        <?= $isEdit ? 'Save Changes' : 'Create Category' ?>
                    </button>
                </div>
            </div>
        </div>
    </div>
</form>
