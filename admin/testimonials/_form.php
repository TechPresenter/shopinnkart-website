<?php
/**
 * ShopInnKart Admin - Testimonial form body.
 *
 * Shared by create.php and edit.php. Both set:
 *   $testimonial array of field values (existing row, or defaults + submitted input)
 *   $errors      field => message
 *   $isEdit      bool
 */

declare(strict_types=1);

if (!defined('SIK_BOOTSTRAPPED')) {
    http_response_code(403);
    exit;
}

/** @var array $testimonial @var array $errors @var bool $isEdit */
$isEdit = $isEdit ?? false;
$errors = $errors ?? [];
?>
<form class="ad-form" method="post" enctype="multipart/form-data" data-guard-unsaved>
    <?= csrf_field() ?>

    <div class="ad-grid ad-grid--sidebar">
        <div style="display:grid;gap:16px">

            <div class="ad-card" style="margin:0">
                <div class="ad-card__head"><div class="ad-card__title">Customer</div></div>
                <div class="ad-card__body">
                    <div class="ad-row ad-row--2">
                        <div class="ad-field">
                            <label class="sik-label" for="testimonialName">Customer name <span class="req">*</span></label>
                            <input class="sik-input<?= isset($errors['customer_name']) ? ' is-invalid' : '' ?>" type="text"
                                   id="testimonialName" name="customer_name" maxlength="120" required
                                   value="<?= e($testimonial['customer_name'] ?? '') ?>" placeholder="e.g. Ananya Sharma">
                            <?php if (isset($errors['customer_name'])): ?>
                                <span class="sik-error"><?= e($errors['customer_name']) ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="ad-field">
                            <label class="sik-label" for="testimonialDesignation">Designation</label>
                            <input class="sik-input<?= isset($errors['designation']) ? ' is-invalid' : '' ?>" type="text"
                                   id="testimonialDesignation" name="designation" maxlength="120"
                                   value="<?= e($testimonial['designation'] ?? '') ?>" placeholder="Verified Buyer">
                            <?php if (isset($errors['designation'])): ?>
                                <span class="sik-error"><?= e($errors['designation']) ?></span>
                            <?php else: ?>
                                <span class="sik-help">Shown under the name, e.g. "Verified Buyer" or a job title.</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="ad-field">
                        <label class="sik-label" for="testimonialTitle">Headline</label>
                        <input class="sik-input<?= isset($errors['title']) ? ' is-invalid' : '' ?>" type="text"
                               id="testimonialTitle" name="title" maxlength="200"
                               value="<?= e($testimonial['title'] ?? '') ?>"
                               placeholder="e.g. Delivered a day early, packed perfectly">
                        <?php if (isset($errors['title'])): ?>
                            <span class="sik-error"><?= e($errors['title']) ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="ad-field">
                        <label class="sik-label" for="testimonialMessage">Testimonial <span class="req">*</span></label>
                        <textarea class="sik-textarea<?= isset($errors['message']) ? ' is-invalid' : '' ?>"
                                  id="testimonialMessage" name="message" rows="7" required style="min-height:170px"
                                  placeholder="What the customer said, in their words."><?= e($testimonial['message'] ?? '') ?></textarea>
                        <?php if (isset($errors['message'])): ?>
                            <span class="sik-error"><?= e($errors['message']) ?></span>
                        <?php else: ?>
                            <span class="sik-help">Plain text. Two or three sentences read best on the homepage card.</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="ad-card" style="margin:0">
                <div class="ad-card__head">
                    <div class="ad-card__title">Avatar</div>
                    <div class="ad-card__sub">Square image, up to <?= e(format_bytes(MAX_UPLOAD_SIZE)) ?>. Initials are shown when empty.</div>
                </div>
                <div class="ad-card__body">
                    <div class="ad-drop" data-drop="#testimonialAvatarPreview">
                        <input type="file" name="avatar" accept="image/*">
                        <?= icon('upload', 'w-6 h-6') ?>
                        <div style="font-size:13px;margin-top:6px">Click or drop the customer photo here</div>
                    </div>
                    <div class="ad-preview" id="testimonialAvatarPreview">
                        <?php if (!empty($testimonial['avatar'])): ?>
                            <div class="ad-preview__item">
                                <img src="<?= e(img_url($testimonial['avatar'])) ?>" alt="Current avatar">
                                <button type="button" class="ad-preview__remove"
                                        data-remove-image="#testimonialRemoveAvatar"
                                        aria-label="Remove avatar">&times;</button>
                            </div>
                        <?php endif; ?>
                    </div>
                    <input type="hidden" name="remove_avatar" id="testimonialRemoveAvatar" value="0">
                </div>
            </div>
        </div>

        <div style="display:grid;gap:16px;align-content:start">
            <div class="ad-card" style="margin:0">
                <div class="ad-card__head"><div class="ad-card__title">Placement</div></div>
                <div class="ad-card__body" style="display:grid;gap:14px">
                    <div class="ad-field">
                        <label class="sik-label" for="testimonialRating">Rating</label>
                        <select class="sik-select" id="testimonialRating" name="rating">
                            <?= admin_options(
                                ['5' => '5 stars', '4' => '4 stars', '3' => '3 stars', '2' => '2 stars', '1' => '1 star'],
                                (string) ($testimonial['rating'] ?? 5)
                            ) ?>
                        </select>
                        <?php if (isset($errors['rating'])): ?>
                            <span class="sik-error"><?= e($errors['rating']) ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="ad-field">
                        <label class="sik-label" for="testimonialSortOrder">Sort order</label>
                        <input class="sik-input<?= isset($errors['sort_order']) ? ' is-invalid' : '' ?>" type="number"
                               id="testimonialSortOrder" name="sort_order" min="0" max="9999" step="1"
                               value="<?= (int) ($testimonial['sort_order'] ?? 0) ?>">
                        <?php if (isset($errors['sort_order'])): ?>
                            <span class="sik-error"><?= e($errors['sort_order']) ?></span>
                        <?php else: ?>
                            <span class="sik-help">Lower numbers appear first in the testimonial rail.</span>
                        <?php endif; ?>
                    </div>

                    <div class="ad-field">
                        <label class="sik-label" for="testimonialStatus">Status</label>
                        <select class="sik-select" id="testimonialStatus" name="status">
                            <?= admin_options(
                                ['active' => 'Active', 'inactive' => 'Inactive'],
                                $testimonial['status'] ?? 'active'
                            ) ?>
                        </select>
                        <?php if (isset($errors['status'])): ?>
                            <span class="sik-error"><?= e($errors['status']) ?></span>
                        <?php else: ?>
                            <span class="sik-help">Inactive testimonials stay saved but drop off the storefront.</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="ad-card__foot">
                    <a class="ad-btn" href="<?= e(admin_url('testimonials/')) ?>">Cancel</a>
                    <button type="submit" class="ad-btn ad-btn--primary">
                        <?= icon('check', 'w-4 h-4') ?>
                        <?= $isEdit ? 'Save Changes' : 'Add Testimonial' ?>
                    </button>
                </div>
            </div>
        </div>
    </div>
</form>
