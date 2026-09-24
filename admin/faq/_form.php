<?php
/**
 * ShopInnKart Admin - FAQ form body.
 *
 * Shared by create.php and edit.php. Both set:
 *   $faq        array of field values (existing row, or defaults + submitted input)
 *   $errors     field => message
 *   $isEdit     bool
 *   $categories list of existing category names for the datalist
 */

declare(strict_types=1);

if (!defined('SIK_BOOTSTRAPPED')) {
    http_response_code(403);
    exit;
}

/** @var array $faq @var array $errors @var bool $isEdit @var array $categories */
$isEdit     = $isEdit ?? false;
$errors     = $errors ?? [];
$categories = $categories ?? [];
?>
<form class="ad-form" method="post" data-guard-unsaved>
    <?= csrf_field() ?>

    <div class="ad-grid ad-grid--sidebar">
        <div style="display:grid;gap:16px">

            <div class="ad-card" style="margin:0">
                <div class="ad-card__head"><div class="ad-card__title">Question &amp; answer</div></div>
                <div class="ad-card__body">
                    <div class="ad-field">
                        <label class="sik-label" for="faqQuestion">Question <span class="req">*</span></label>
                        <input class="sik-input<?= isset($errors['question']) ? ' is-invalid' : '' ?>" type="text"
                               id="faqQuestion" name="question" maxlength="500" required
                               value="<?= e($faq['question'] ?? '') ?>"
                               placeholder="e.g. How long does delivery take?">
                        <?php if (isset($errors['question'])): ?>
                            <span class="sik-error"><?= e($errors['question']) ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="ad-field">
                        <label class="sik-label" for="faqAnswer">Answer <span class="req">*</span></label>
                        <textarea class="sik-textarea<?= isset($errors['answer']) ? ' is-invalid' : '' ?>"
                                  id="faqAnswer" name="answer" rows="10" required style="min-height:220px"
                                  placeholder="Answer in plain language. Keep it to a few sentences."><?= e($faq['answer'] ?? '') ?></textarea>
                        <?php if (isset($errors['answer'])): ?>
                            <span class="sik-error"><?= e($errors['answer']) ?></span>
                        <?php else: ?>
                            <span class="sik-help">Plain text, shown in the accordion body.</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div style="display:grid;gap:16px;align-content:start">
            <div class="ad-card" style="margin:0">
                <div class="ad-card__head"><div class="ad-card__title">Placement</div></div>
                <div class="ad-card__body" style="display:grid;gap:14px">
                    <div class="ad-field">
                        <label class="sik-label" for="faqCategoryField">Category <span class="req">*</span></label>
                        <input class="sik-input<?= isset($errors['category']) ? ' is-invalid' : '' ?>" type="text"
                               id="faqCategoryField" name="category" maxlength="100" required
                               list="faqCategoryOptions" autocomplete="off"
                               value="<?= e($faq['category'] ?? 'General') ?>" placeholder="General">
                        <datalist id="faqCategoryOptions">
                            <?php foreach ($categories as $categoryName): ?>
                                <option value="<?= e_attr((string) $categoryName) ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                        <?php if (isset($errors['category'])): ?>
                            <span class="sik-error"><?= e($errors['category']) ?></span>
                        <?php else: ?>
                            <span class="sik-help">Type a new name to start a group.</span>
                        <?php endif; ?>
                    </div>

                    <div class="ad-field">
                        <label class="sik-label" for="faqSortOrder">Sort order</label>
                        <input class="sik-input<?= isset($errors['sort_order']) ? ' is-invalid' : '' ?>" type="number"
                               id="faqSortOrder" name="sort_order" min="0" max="9999" step="1"
                               value="<?= (int) ($faq['sort_order'] ?? 0) ?>">
                        <?php if (isset($errors['sort_order'])): ?>
                            <span class="sik-error"><?= e($errors['sort_order']) ?></span>
                        <?php else: ?>
                            <span class="sik-help">Lower numbers come first in the category.</span>
                        <?php endif; ?>
                    </div>

                    <div class="ad-field">
                        <label class="sik-label" for="faqStatus">Status</label>
                        <select class="sik-select" id="faqStatus" name="status">
                            <?= admin_options(
                                ['active' => 'Active', 'inactive' => 'Inactive'],
                                $faq['status'] ?? 'active'
                            ) ?>
                        </select>
                        <?php // The help line here said "Inactive questions are hidden from the
                              // storefront FAQ page", which is the Inactive option restated. ?>
                        <?php if (isset($errors['status'])): ?>
                            <span class="sik-error"><?= e($errors['status']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="ad-card__foot">
                    <a class="ad-btn" href="<?= e(admin_url('faq/')) ?>">Cancel</a>
                    <button type="submit" class="ad-btn ad-btn--primary">
                        <?= icon('check', 'w-4 h-4') ?>
                        <?= $isEdit ? 'Save Changes' : 'Add Question' ?>
                    </button>
                </div>
            </div>
        </div>
    </div>
</form>
