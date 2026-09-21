<?php
/**
 * ShopInnKart Admin - Flash sale form body.
 *
 * Shared by create.php and edit.php. Both set:
 *   $sale     field values (stored row, or defaults overlaid with the submit)
 *   $errors   field => message
 *   $isEdit   bool
 */

declare(strict_types=1);

/** @var array $sale @var array $errors @var bool $isEdit */
$isEdit = $isEdit ?? false;
$errors = $errors ?? [];
$saleId = (int) ($sale['id'] ?? 0);

$attached  = $sale['products'] ?? [];
$totalSold = 0;

$productRows = [];
foreach (marketing_product_rows(array_keys($attached)) as $productId => $product) {
    $sold = (int) ($attached[$productId]['stock_sold'] ?? 0);
    $totalSold += $sold;

    $productRows[] = [
        'id'     => $productId,
        'name'   => $product['name'],
        'image'  => $product['main_image'],
        // stock_sold is written by the order transaction, so it is reported
        // here rather than offered as an editable field.
        'meta'   => trim((string) $product['sku']) . ' · ' . money(marketing_product_price($product))
            . ' · ' . (int) $product['stock'] . ' in stock · ' . $sold . ' sold in this sale',
        'values' => [
            'sale_price'          => (string) ($attached[$productId]['sale_price'] ?? ''),
            'product_stock_limit' => (string) ($attached[$productId]['product_stock_limit'] ?? ''),
        ],
    ];
}

$state = marketing_state(
    marketing_dt_save((string) ($sale['start_time'] ?? '')),
    marketing_dt_save((string) ($sale['end_time'] ?? '')),
    (string) ($sale['status'] ?? 'active')
);
?>

<form class="ad-form" method="post" data-guard-unsaved>
    <?= csrf_field() ?>

    <div class="ad-grid ad-grid--sidebar">
        <div style="display:grid;gap:16px">

            <div class="ad-card" style="margin:0">
                <div class="ad-card__head"><div class="ad-card__title">Sale</div></div>
                <div class="ad-card__body">
                    <div class="ad-field">
                        <label class="sik-label" for="flashName">Name <span class="req">*</span></label>
                        <input class="sik-input<?= isset($errors['name']) ? ' is-invalid' : '' ?>" type="text"
                               id="flashName" name="name" maxlength="200" required
                               value="<?= e($sale['name'] ?? '') ?>" placeholder="Midnight Flash Sale">
                        <?php if (isset($errors['name'])): ?>
                            <span class="sik-error"><?= e($errors['name']) ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="ad-field">
                        <label class="sik-label" for="flashSubtitle">Subtitle</label>
                        <input class="sik-input<?= isset($errors['subtitle']) ? ' is-invalid' : '' ?>" type="text"
                               id="flashSubtitle" name="subtitle" maxlength="255"
                               value="<?= e($sale['subtitle'] ?? '') ?>"
                               placeholder="6 hours only — limited units per product">
                        <?php if (isset($errors['subtitle'])): ?>
                            <span class="sik-error"><?= e($errors['subtitle']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="ad-card" style="margin:0">
                <div class="ad-card__head">
                    <div class="ad-card__title">Discount</div>
                    <div class="ad-card__sub">The fallback for any product that does not set its own sale price.</div>
                </div>
                <div class="ad-card__body">
                    <div class="ad-row ad-row--3">
                        <div class="ad-field">
                            <label class="sik-label" for="flashDiscountType">Type</label>
                            <select class="sik-select" id="flashDiscountType" name="discount_type">
                                <?= admin_options(flash_sale_discount_types(), $sale['discount_type'] ?? 'percentage') ?>
                            </select>
                        </div>

                        <div class="ad-field">
                            <label class="sik-label" for="flashDiscountValue">Value <span class="req">*</span></label>
                            <input class="sik-input<?= isset($errors['discount_value']) ? ' is-invalid' : '' ?>" type="number"
                                   id="flashDiscountValue" name="discount_value" step="0.01" min="0" required
                                   value="<?= e($sale['discount_value'] ?? '') ?>" placeholder="40">
                            <?php if (isset($errors['discount_value'])): ?>
                                <span class="sik-error"><?= e($errors['discount_value']) ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="ad-field">
                            <label class="sik-label" for="flashStockLimit">Default unit cap</label>
                            <input class="sik-input<?= isset($errors['stock_limit']) ? ' is-invalid' : '' ?>" type="number"
                                   id="flashStockLimit" name="stock_limit" step="1" min="1"
                                   value="<?= e($sale['stock_limit'] ?? '') ?>" placeholder="Unlimited">
                            <?php if (isset($errors['stock_limit'])): ?>
                                <span class="sik-error"><?= e($errors['stock_limit']) ?></span>
                            <?php else: ?>
                                <span class="sik-help">Guidance for the per-product caps below. Blank = unlimited.</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="ad-card" style="margin:0">
                <div class="ad-card__head">
                    <div class="ad-card__title">Products in this sale</div>
                    <div class="ad-card__sub">
                        Blank sale price = use the discount above. Blank units = no per-product cap.
                    </div>
                </div>
                <div class="ad-card__body">
                    <?= marketing_product_picker([
                        'name'   => 'product_ids',
                        'rows'   => $productRows,
                        'fields' => [
                            ['name' => 'sale_price', 'label' => 'Sale price', 'placeholder' => 'Auto', 'step' => '0.01', 'width' => '118px'],
                            ['name' => 'product_stock_limit', 'label' => 'Units', 'placeholder' => 'All', 'step' => '1', 'width' => '90px'],
                        ],
                        'empty'  => 'No products attached — this sale prices nothing yet.',
                        'help'   => 'A product stops being discounted once its own unit cap is sold out.',
                    ]) ?>
                    <?php foreach (['product_ids', 'sale_price', 'product_stock_limit'] as $field): ?>
                        <?php if (isset($errors[$field])): ?>
                            <span class="sik-error"><?= e($errors[$field]) ?></span>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div style="display:grid;gap:16px;align-content:start">
            <div class="ad-card" style="margin:0">
                <div class="ad-card__head">
                    <div class="ad-card__title">Schedule</div>
                    <div><?= marketing_state_badge($state) ?></div>
                </div>
                <div class="ad-card__body" style="display:grid;gap:14px">
                    <div class="ad-field">
                        <label class="sik-label" for="flashStart">Starts <span class="req">*</span></label>
                        <input class="sik-input<?= isset($errors['start_time']) ? ' is-invalid' : '' ?>"
                               type="datetime-local" id="flashStart" name="start_time" required
                               value="<?= e($sale['start_time'] ?? '') ?>">
                        <?php if (isset($errors['start_time'])): ?>
                            <span class="sik-error"><?= e($errors['start_time']) ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="ad-field">
                        <label class="sik-label" for="flashEnd">Ends <span class="req">*</span></label>
                        <input class="sik-input<?= isset($errors['end_time']) ? ' is-invalid' : '' ?>"
                               type="datetime-local" id="flashEnd" name="end_time" required
                               value="<?= e($sale['end_time'] ?? '') ?>">
                        <?php if (isset($errors['end_time'])): ?>
                            <span class="sik-error"><?= e($errors['end_time']) ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="ad-field">
                        <label class="sik-label" for="flashStatus">Status</label>
                        <select class="sik-select" id="flashStatus" name="status">
                            <?= admin_options(
                                ['active' => 'Active', 'inactive' => 'Inactive'],
                                $sale['status'] ?? 'active'
                            ) ?>
                        </select>
                        <span class="sik-help">
                            The storefront runs one flash sale at a time: the active one ending soonest.
                        </span>
                    </div>

                    <?php if ($isEdit): ?>
                        <div>
                            <div class="sik-label" style="margin-bottom:6px">Units sold in this sale</div>
                            <strong style="font-size:18px"><?= number_format($totalSold) ?></strong>
                            <span class="ad-cellflex__meta"> across <?= count($productRows) ?> product<?= count($productRows) === 1 ? '' : 's' ?></span>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="ad-card__foot">
                    <a class="ad-btn" href="<?= e(admin_url('flash-sales/')) ?>">Cancel</a>
                    <button type="submit" class="ad-btn ad-btn--primary">
                        <?= icon('check', 'w-4 h-4') ?>
                        <?= $isEdit ? 'Save Changes' : 'Create Flash Sale' ?>
                    </button>
                </div>
            </div>
        </div>
    </div>
</form>
