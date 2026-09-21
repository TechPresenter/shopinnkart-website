<?php
/**
 * ShopInnKart Admin - CMS page form body.
 *
 * Shared by create.php and edit.php. Both set:
 *   $cmsPage array of field values (existing row, or defaults + submitted input)
 *   $errors  field => message
 *   $isEdit  bool
 */

declare(strict_types=1);

if (!defined('SIK_BOOTSTRAPPED')) {
    http_response_code(403);
    exit;
}

/** @var array $cmsPage @var array $errors @var bool $isEdit */
$isEdit   = $isEdit ?? false;
$errors   = $errors ?? [];
$isSystem = (int) ($cmsPage['is_system'] ?? 0) === 1;
?>
<form class="ad-form" method="post" enctype="multipart/form-data" data-guard-unsaved>
    <?= csrf_field() ?>

    <div class="ad-grid ad-grid--sidebar">
        <div style="display:grid;gap:16px">

            <div class="ad-card" style="margin:0">
                <div class="ad-card__head"><div class="ad-card__title">Content</div></div>
                <div class="ad-card__body">
                    <div class="ad-row ad-row--2">
                        <div class="ad-field">
                            <label class="sik-label" for="pageTitleField">Title <span class="req">*</span></label>
                            <input class="sik-input<?= isset($errors['title']) ? ' is-invalid' : '' ?>" type="text"
                                   id="pageTitleField" name="title" maxlength="200" required
                                   data-slug-source="#pageSlugField"
                                   value="<?= e($cmsPage['title'] ?? '') ?>" placeholder="e.g. Shipping Policy">
                            <?php if (isset($errors['title'])): ?>
                                <span class="sik-error"><?= e($errors['title']) ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="ad-field">
                            <label class="sik-label" for="pageSlugField">Slug</label>
                            <input class="sik-input<?= isset($errors['slug']) ? ' is-invalid' : '' ?>" type="text"
                                   id="pageSlugField" name="slug" maxlength="220" data-slugify
                                   value="<?= e($cmsPage['slug'] ?? '') ?>" placeholder="shipping-policy"
                                   <?= $isSystem ? 'readonly' : '' ?>>
                            <?php if (isset($errors['slug'])): ?>
                                <span class="sik-error"><?= e($errors['slug']) ?></span>
                            <?php elseif ($isSystem): ?>
                                <span class="sik-help">
                                    Locked. A fixed storefront route points at this slug, so changing it would break that link.
                                </span>
                            <?php else: ?>
                                <span class="sik-help">Leave blank to build it from the title. The page lives at /page/&lt;slug&gt;.</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="ad-field">
                        <label class="sik-label" for="pageContentField">Page content</label>
                        <textarea class="sik-textarea" id="pageContentField" name="content" rows="22"
                                  class="ad-codearea" style="min-height:440px"
                                  placeholder="<h2>Heading</h2>&#10;<p>Write the page body here.</p>"><?= e($cmsPage['content'] ?? '') ?></textarea>
                        <span class="sik-help">
                            Headings, paragraphs, lists, links, images and tables are kept, and
                            embeds from YouTube, Vimeo and Google Maps are resized to fit the page.
                            Scripts, event handlers and embeds from anywhere else are stripped on save.
                        </span>
                    </div>
                </div>
            </div>

            <div class="ad-card" style="margin:0">
                <div class="ad-card__head">
                    <div class="ad-card__title">Banner image</div>
                    <div class="ad-card__sub">Shown above the page title, up to <?= e(format_bytes(MAX_UPLOAD_SIZE)) ?></div>
                </div>
                <div class="ad-card__body">
                    <div class="ad-drop" data-drop="#pageBannerPreview">
                        <input type="file" name="banner_image" accept="image/*">
                        <?= icon('upload', 'w-6 h-6') ?>
                        <div style="font-size:13px;margin-top:6px">Click or drop a banner image here</div>
                    </div>
                    <div class="ad-preview" id="pageBannerPreview">
                        <?php if (!empty($cmsPage['banner_image'])): ?>
                            <div class="ad-preview__item">
                                <img src="<?= e(img_url($cmsPage['banner_image'])) ?>" alt="Current page banner">
                                <button type="button" class="ad-preview__remove"
                                        data-remove-image="#pageRemoveBanner"
                                        aria-label="Remove banner">&times;</button>
                            </div>
                        <?php endif; ?>
                    </div>
                    <input type="hidden" name="remove_banner_image" id="pageRemoveBanner" value="0">
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
                    //
                    // The preview URL comes from cms_page_canonical() rather than
                    // page_url(): a slug that also owns a fixed route (/about,
                    // /faq, ...) is canonical at that route, and the preview has to
                    // show the URL the storefront will actually publish.
                    require_once INCLUDES_PATH . '/content-functions.php';
                    require_once ADMIN_PATH . '/includes/seo-editor.php';
                    seo_editor($cmsPage, [
                        'url'              => $isEdit && (string) ($cmsPage['slug'] ?? '') !== ''
                            ? cms_page_canonical((string) $cmsPage['slug'])
                            : url(),
                        'title_from'       => 'title',
                        'description_from' => 'content',
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
                        <label class="sik-label" for="pageStatusField">Status</label>
                        <select class="sik-select" id="pageStatusField" name="status">
                            <?= admin_options(
                                ['active' => 'Active', 'inactive' => 'Inactive'],
                                $cmsPage['status'] ?? 'active'
                            ) ?>
                        </select>
                        <?php if (isset($errors['status'])): ?>
                            <span class="sik-error"><?= e($errors['status']) ?></span>
                        <?php else: ?>
                            <span class="sik-help">Inactive pages return a 404 on the storefront.</span>
                        <?php endif; ?>
                    </div>

                    <div class="ad-field">
                        <label class="sik-label" for="pageSortOrder">Sort order</label>
                        <input class="sik-input<?= isset($errors['sort_order']) ? ' is-invalid' : '' ?>" type="number"
                               id="pageSortOrder" name="sort_order" min="0" max="9999" step="1"
                               value="<?= (int) ($cmsPage['sort_order'] ?? 0) ?>">
                        <?php if (isset($errors['sort_order'])): ?>
                            <span class="sik-error"><?= e($errors['sort_order']) ?></span>
                        <?php else: ?>
                            <span class="sik-help">Lower numbers come first in the footer list.</span>
                        <?php endif; ?>
                    </div>

                    <label class="ad-switch">
                        <input type="checkbox" name="show_in_footer" value="1"
                               <?= (int) ($cmsPage['show_in_footer'] ?? 1) === 1 ? 'checked' : '' ?>>
                        <span class="ad-switch__track"></span>
                        <span>Show in the footer</span>
                    </label>

                    <?php if ($isSystem): ?>
                        <div class="sik-alert sik-alert--info" style="margin:0">
                            <?= icon('lock', 'w-5 h-5') ?>
                            <div>
                                This is a system page. Its content is editable, but the slug is fixed
                                and the page cannot be deleted.
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="ad-card__foot">
                    <a class="ad-btn" href="<?= e(admin_url('pages/')) ?>">Cancel</a>
                    <button type="submit" class="ad-btn ad-btn--primary">
                        <?= icon('check', 'w-4 h-4') ?>
                        <?= $isEdit ? 'Save Changes' : 'Create Page' ?>
                    </button>
                </div>
            </div>
        </div>
    </div>
</form>
