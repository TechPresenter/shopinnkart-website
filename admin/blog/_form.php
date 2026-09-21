<?php
/**
 * ShopInnKart Admin - Blog post form body.
 *
 * Shared by create.php and edit.php. Both set:
 *   $post            array of field values (existing row, or defaults + submitted input)
 *   $errors          field => message
 *   $isEdit          bool
 *   $categoryOptions id => name
 */

declare(strict_types=1);

if (!defined('SIK_BOOTSTRAPPED')) {
    http_response_code(403);
    exit;
}

/** @var array $post @var array $errors @var bool $isEdit @var array $categoryOptions */
$isEdit          = $isEdit ?? false;
$errors          = $errors ?? [];
$categoryOptions = $categoryOptions ?? [];

// datetime-local wants "Y-m-dTH:i"; the column stores a MySQL DATETIME.
$publishedInput = '';
if (!empty($post['published_at'])) {
    $timestamp = strtotime((string) $post['published_at']);
    $publishedInput = $timestamp === false ? '' : date('Y-m-d\TH:i', $timestamp);
}
?>
<form class="ad-form" method="post" enctype="multipart/form-data" data-guard-unsaved>
    <?= csrf_field() ?>

    <div class="ad-grid ad-grid--sidebar">
        <div style="display:grid;gap:16px">

            <div class="ad-card" style="margin:0">
                <div class="ad-card__head"><div class="ad-card__title">Post</div></div>
                <div class="ad-card__body">
                    <div class="ad-row ad-row--2">
                        <div class="ad-field">
                            <label class="sik-label" for="postTitleField">Title <span class="req">*</span></label>
                            <input class="sik-input<?= isset($errors['title']) ? ' is-invalid' : '' ?>" type="text"
                                   id="postTitleField" name="title" maxlength="255" required
                                   data-slug-source="#postSlugField"
                                   value="<?= e($post['title'] ?? '') ?>"
                                   placeholder="e.g. How to pick the right laptop for college">
                            <?php if (isset($errors['title'])): ?>
                                <span class="sik-error"><?= e($errors['title']) ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="ad-field">
                            <label class="sik-label" for="postSlugField">Slug</label>
                            <input class="sik-input<?= isset($errors['slug']) ? ' is-invalid' : '' ?>" type="text"
                                   id="postSlugField" name="slug" maxlength="280" data-slugify
                                   value="<?= e($post['slug'] ?? '') ?>" placeholder="pick-the-right-laptop">
                            <?php if (isset($errors['slug'])): ?>
                                <span class="sik-error"><?= e($errors['slug']) ?></span>
                            <?php else: ?>
                                <span class="sik-help">Leave blank to build it from the title. The post lives at /blog/&lt;slug&gt;.</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="ad-field">
                        <label class="sik-label" for="postExcerpt">Excerpt</label>
                        <textarea class="sik-textarea" id="postExcerpt" name="excerpt" rows="3"
                                  style="min-height:84px" maxlength="500"
                                  placeholder="One or two sentences shown on the blog listing cards."><?= e($post['excerpt'] ?? '') ?></textarea>
                        <span class="sik-help">Plain text. Falls back to the first part of the content when empty.</span>
                    </div>

                    <div class="ad-field">
                        <label class="sik-label" for="postContent">Content</label>
                        <textarea class="sik-textarea" id="postContent" name="content" rows="24"
                                  class="ad-codearea" style="min-height:460px"
                                  placeholder="<p>Write the article here.</p>"><?= e($post['content'] ?? '') ?></textarea>
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
                    <div class="ad-card__title">Featured image</div>
                    <div class="ad-card__sub">Used on the listing cards and at the top of the post, up to <?= e(format_bytes(MAX_UPLOAD_SIZE)) ?></div>
                </div>
                <div class="ad-card__body">
                    <div class="ad-drop" data-drop="#postImagePreview">
                        <input type="file" name="featured_image" accept="image/*">
                        <?= icon('upload', 'w-6 h-6') ?>
                        <div style="font-size:13px;margin-top:6px">Click or drop the featured image here</div>
                    </div>
                    <div class="ad-preview" id="postImagePreview">
                        <?php if (!empty($post['featured_image'])): ?>
                            <div class="ad-preview__item">
                                <img src="<?= e(img_url($post['featured_image'])) ?>" alt="Current featured image">
                                <button type="button" class="ad-preview__remove"
                                        data-remove-image="#postRemoveImage"
                                        aria-label="Remove featured image">&times;</button>
                            </div>
                        <?php endif; ?>
                    </div>
                    <input type="hidden" name="remove_featured_image" id="postRemoveImage" value="0">
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
                    seo_editor($post, [
                        'url'              => $isEdit && (string) ($post['slug'] ?? '') !== ''
                            ? blog_url((string) $post['slug'])
                            : url(),
                        'title_from'       => 'title',
                        'description_from' => 'excerpt',
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
                <div class="ad-card__head"><div class="ad-card__title">Publishing</div></div>
                <div class="ad-card__body" style="display:grid;gap:14px">
                    <div class="ad-field">
                        <label class="sik-label" for="postStatus">Status</label>
                        <select class="sik-select" id="postStatus" name="status">
                            <?= admin_options(
                                ['draft' => 'Draft', 'published' => 'Published'],
                                $post['status'] ?? 'draft'
                            ) ?>
                        </select>
                        <?php if (isset($errors['status'])): ?>
                            <span class="sik-error"><?= e($errors['status']) ?></span>
                        <?php else: ?>
                            <span class="sik-help">Drafts are invisible on the storefront.</span>
                        <?php endif; ?>
                    </div>

                    <div class="ad-field">
                        <label class="sik-label" for="postPublishedAt">Publish date</label>
                        <input class="sik-input<?= isset($errors['published_at']) ? ' is-invalid' : '' ?>"
                               type="datetime-local" id="postPublishedAt" name="published_at"
                               value="<?= e($publishedInput) ?>">
                        <?php if (isset($errors['published_at'])): ?>
                            <span class="sik-error"><?= e($errors['published_at']) ?></span>
                        <?php else: ?>
                            <span class="sik-help">Leave blank and publishing now stamps the current time.</span>
                        <?php endif; ?>
                    </div>

                    <div class="ad-field">
                        <label class="sik-label" for="postCategoryId">Category</label>
                        <select class="sik-select<?= isset($errors['category_id']) ? ' is-invalid' : '' ?>"
                                id="postCategoryId" name="category_id">
                            <?= admin_options($categoryOptions, $post['category_id'] ?? '', '— Uncategorised —') ?>
                        </select>
                        <?php if (isset($errors['category_id'])): ?>
                            <span class="sik-error"><?= e($errors['category_id']) ?></span>
                        <?php else: ?>
                            <span class="sik-help">
                                <a href="<?= e(admin_url('blog/categories.php')) ?>">Manage categories</a>
                            </span>
                        <?php endif; ?>
                    </div>

                    <div class="ad-field">
                        <label class="sik-label" for="postAuthorName">Author</label>
                        <input class="sik-input<?= isset($errors['author_name']) ? ' is-invalid' : '' ?>" type="text"
                               id="postAuthorName" name="author_name" maxlength="150"
                               value="<?= e($post['author_name'] ?? '') ?>">
                        <?php if (isset($errors['author_name'])): ?>
                            <span class="sik-error"><?= e($errors['author_name']) ?></span>
                        <?php else: ?>
                            <span class="sik-help">Shown as the byline. Defaults to your admin name.</span>
                        <?php endif; ?>
                    </div>

                    <label class="ad-switch">
                        <input type="checkbox" name="is_featured" value="1"
                               <?= (int) ($post['is_featured'] ?? 0) === 1 ? 'checked' : '' ?>>
                        <span class="ad-switch__track"></span>
                        <span>Featured post</span>
                    </label>
                </div>
                <div class="ad-card__foot">
                    <a class="ad-btn" href="<?= e(admin_url('blog/')) ?>">Cancel</a>
                    <button type="submit" class="ad-btn ad-btn--primary">
                        <?= icon('check', 'w-4 h-4') ?>
                        <?= $isEdit ? 'Save Changes' : 'Create Post' ?>
                    </button>
                </div>
            </div>
        </div>
    </div>
</form>
