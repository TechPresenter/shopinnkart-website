<?php
/**
 * ShopInnKart Admin - Brand form body.
 *
 * Shared by create.php and edit.php. Both set:
 *   $brand   array of field values (existing row, or defaults + submitted input)
 *   $errors  field => message
 *   $isEdit  bool
 */

declare(strict_types=1);


// Include-only: this partial assumes its parent page already ran the
// authentication and permission checks. Refuse to run as an entry point.
if (!defined('SIK_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}
/** @var array $brand @var array $errors @var bool $isEdit */
$isEdit = $isEdit ?? false;
$errors = $errors ?? [];
?>
<form class="ad-form" method="post" enctype="multipart/form-data" data-guard-unsaved>
    <?= csrf_field() ?>

    <div class="ad-grid ad-grid--sidebar">
        <div style="display:grid;gap:16px">

            <div class="ad-card" style="margin:0">
                <div class="ad-card__head"><div class="ad-card__title">Basics</div></div>
                <div class="ad-card__body">
                    <div class="ad-row ad-row--2">
                        <div class="ad-field">
                            <label class="sik-label" for="brandName">Name <span class="req">*</span></label>
                            <input class="sik-input<?= isset($errors['name']) ? ' is-invalid' : '' ?>" type="text"
                                   id="brandName" name="name" maxlength="150" required
                                   data-slug-source="#brandSlug"
                                   value="<?= e($brand['name'] ?? '') ?>" placeholder="e.g. Lexton">
                            <?php if (isset($errors['name'])): ?>
                                <span class="sik-error"><?= e($errors['name']) ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="ad-field">
                            <label class="sik-label" for="brandSlug">Slug</label>
                            <input class="sik-input<?= isset($errors['slug']) ? ' is-invalid' : '' ?>" type="text"
                                   id="brandSlug" name="slug" maxlength="180" data-slugify
                                   value="<?= e($brand['slug'] ?? '') ?>" placeholder="lexton">
                            <?php if (isset($errors['slug'])): ?>
                                <span class="sik-error"><?= e($errors['slug']) ?></span>
                            <?php else: ?>
                                <span class="sik-help">Leave blank to build it from the name. Used in the brand URL.</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="ad-field">
                        <label class="sik-label" for="brandWebsite">Official website</label>
                        <input class="sik-input<?= isset($errors['website']) ? ' is-invalid' : '' ?>" type="url"
                               id="brandWebsite" name="website" maxlength="255"
                               value="<?= e($brand['website'] ?? '') ?>" placeholder="https://www.brand-website.com">
                        <?php if (isset($errors['website'])): ?>
                            <span class="sik-error"><?= e($errors['website']) ?></span>
                        <?php else: ?>
                            <span class="sik-help">Include the protocol, e.g. https://</span>
                        <?php endif; ?>
                    </div>

                    <div class="ad-field">
                        <label class="sik-label" for="brandDescription">Description</label>
                        <textarea class="sik-textarea" id="brandDescription" name="description" rows="5"
                                  placeholder="Shown on the brand landing page."><?= e($brand['description'] ?? '') ?></textarea>
                        <span class="sik-help">Basic HTML is allowed; scripts and event handlers are stripped on save.</span>
                    </div>
                </div>
            </div>

            <div class="ad-card" style="margin:0">
                <div class="ad-card__head">
                    <div class="ad-card__title">Logo</div>
                    <div class="ad-card__sub">A transparent PNG or SVG works best, up to <?= e(format_bytes(MAX_UPLOAD_SIZE)) ?></div>
                </div>
                <div class="ad-card__body">
                    <div class="ad-drop" data-drop="#brandLogoPreview">
                        <input type="file" name="logo" accept="image/*">
                        <?= icon('upload', 'w-6 h-6') ?>
                        <div style="font-size:13px;margin-top:6px">Click or drop the brand logo here</div>
                    </div>
                    <div class="ad-preview" id="brandLogoPreview">
                        <?php if (!empty($brand['logo'])): ?>
                            <div class="ad-preview__item">
                                <img src="<?= e(img_url($brand['logo'])) ?>" alt="Current brand logo">
                                <button type="button" class="ad-preview__remove"
                                        data-remove-image="#brandRemoveLogo"
                                        aria-label="Remove logo">&times;</button>
                            </div>
                        <?php endif; ?>
                    </div>
                    <input type="hidden" name="remove_logo" id="brandRemoveLogo" value="0">
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
                    seo_editor($brand, [
                        'url'              => $isEdit && (string) ($brand['slug'] ?? '') !== ''
                            ? brand_url((string) $brand['slug'])
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
                        <label class="sik-label" for="brandStatus">Status</label>
                        <select class="sik-select" id="brandStatus" name="status">
                            <?= admin_options(
                                ['active' => 'Active', 'inactive' => 'Inactive'],
                                $brand['status'] ?? 'active'
                            ) ?>
                        </select>
                        <span class="sik-help">Inactive brands drop out of the shop filters.</span>
                    </div>

                    <div class="ad-field">
                        <label class="sik-label" for="brandSortOrder">Sort order</label>
                        <input class="sik-input<?= isset($errors['sort_order']) ? ' is-invalid' : '' ?>" type="number"
                               id="brandSortOrder" name="sort_order" min="0" max="9999" step="1"
                               value="<?= (int) ($brand['sort_order'] ?? 0) ?>">
                        <?php if (isset($errors['sort_order'])): ?>
                            <span class="sik-error"><?= e($errors['sort_order']) ?></span>
                        <?php else: ?>
                            <span class="sik-help">Lower numbers come first in brand lists.</span>
                        <?php endif; ?>
                    </div>

                    <label class="ad-switch">
                        <input type="checkbox" name="is_featured" value="1"
                               <?= (int) ($brand['is_featured'] ?? 0) === 1 ? 'checked' : '' ?>>
                        <span class="ad-switch__track"></span>
                        <span>Featured brand</span>
                    </label>
                </div>
                <div class="ad-card__foot">
                    <a class="ad-btn" href="<?= e(admin_url('brands/')) ?>">Cancel</a>
                    <button type="submit" class="ad-btn ad-btn--primary">
                        <?= icon('check', 'w-4 h-4') ?>
                        <?= $isEdit ? 'Save Changes' : 'Create Brand' ?>
                    </button>
                </div>
            </div>
        </div>
    </div>
</form>
