<?php
/**
 * ShopInnKart Admin - Image alt text.
 *
 * Every gallery image with no alt text, on one screen, editable in place.
 * Before this the only way to fill one in was to open the product, find the
 * gallery tab and type it there, one product at a time - which is why the
 * health report has been reporting hundreds of them for months.
 *
 * Alt text is not an SEO field first. It is what a shopper using a screen
 * reader hears instead of the picture, and the help text on this page says so,
 * because "write the keyword in it" is how alt text ends up useless to the
 * person it exists for.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('settings.view');

require_once __DIR__ . '/_layout.php';

$page = max(1, (int) ($_GET['page'] ?? 1));

if (is_post()) {
    // Alt text belongs to the product, so it is the products permission that
    // governs it - not settings.edit. An admin who may not edit a product may
    // not retitle its pictures either.
    admin_require_action('products.edit');

    if ((string) input('action', '') === 'save') {
        $submitted = $_POST['alt'] ?? [];
        $saved     = 0;

        if (is_array($submitted)) {
            foreach ($submitted as $imageId => $altText) {
                $imageId = (int) $imageId;
                $altText = trim((string) $altText);

                if ($imageId <= 0 || $altText === '') {
                    continue;                  // a blank box is "I skipped this one"
                }

                // The column is varchar(200); a longer description is truncated
                // rather than refused, because losing the whole sentence over a
                // few characters helps nobody.
                Database::update(
                    'product_images',
                    ['alt_text' => mb_substr($altText, 0, 200)],
                    '`id` = :id',
                    ['id' => $imageId]
                );
                $saved++;
            }
        }

        if ($saved > 0) {
            log_activity('product_image.alt_updated', 'product_image', null,
                'Filled in alt text for ' . $saved . ' image(s)');
            admin_after_write();
        }

        flash($saved > 0 ? 'success' : 'error', $saved > 0
            ? number_format($saved) . ' image' . ($saved === 1 ? '' : 's') . ' described.'
            : 'Nothing was filled in.');

        redirect(seo_admin_url('images') . ($page > 1 ? '?page=' . $page : ''));
    }
}

$total      = seo_images_missing_alt_count();
$pagination = paginate($total, ADMIN_PER_PAGE, $page);
$images     = seo_images_missing_alt((int) $pagination['per_page'], (int) $pagination['offset']);

$described = (int) Database::fetchColumn(
    "SELECT COUNT(*) FROM `product_images` WHERE `alt_text` IS NOT NULL AND TRIM(`alt_text`) <> ''"
);
$canEdit = admin_can('products.edit');

$pageTitle    = 'Image alt text';
$pageSubtitle = number_format($total) . ' to describe · ' . number_format($described) . ' already done';
$breadcrumbs  = seo_admin_breadcrumbs('Image alt text');

require ADMIN_PATH . '/includes/header.php';
?>

<div class="ad-card">
    <?= seo_admin_tabs('images') ?>

    <div class="ad-card__body">
        <div class="sik-alert sik-alert--info">
            <?= icon('info', 'w-5 h-5') ?>
            <div>
                Describe what is in the picture, in a sentence you would say out loud &mdash; this is what a
                shopper using a screen reader hears instead of the image. &ldquo;Warm white curtain lights
                hung across a window&rdquo;, not &ldquo;lights buy online best price&rdquo;.
                A product&rsquo;s main image is not listed here: the storefront uses the product name for it.
            </div>
        </div>
    </div>

    <?php if ($images === []): ?>
        <div class="ad-card__body ad-card__body--flush">
            <?= admin_empty(
                'Every gallery image is described',
                'Nothing here needs alt text. New images appear on this screen until somebody describes them.',
                null,
                null,
                'image'
            ) ?>
        </div>
    <?php else: ?>
        <form method="post" action="<?= e(seo_admin_url('images') . ($page > 1 ? '?page=' . $page : '')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">

            <div class="ad-card__body ad-card__body--flush">
                <div class="ad-tablewrap">
                    <table class="ad-table">
                        <thead>
                            <tr>
                                <th style="width:90px">Image</th>
                                <th>Product</th>
                                <th>What is in this picture?</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($images as $image): ?>
                                <?php $imageId = (int) $image['id']; ?>
                                <tr>
                                    <td>
                                        <img class="ad-thumb" src="<?= e(img_url((string) $image['image'])) ?>"
                                             alt="" width="56" height="56" loading="lazy">
                                    </td>
                                    <td>
                                        <a href="<?= e(admin_url('products/edit.php?id=' . (int) $image['product_id'])) ?>">
                                            <?= e((string) $image['product_name']) ?>
                                        </a>
                                        <div class="ad-muted" style="font-size:12px">
                                            <?= e(basename((string) $image['image'])) ?>
                                        </div>
                                    </td>
                                    <td>
                                        <label class="sik-sr" for="alt<?= $imageId ?>">
                                            Alt text for <?= e((string) $image['product_name']) ?>
                                        </label>
                                        <input class="sik-input" type="text" id="alt<?= $imageId ?>"
                                               name="alt[<?= $imageId ?>]" maxlength="200"
                                               <?= $canEdit ? '' : 'disabled' ?>
                                               placeholder="e.g. <?= e_attr((string) $image['product_name']) ?> hung across a window">
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="ad-card__foot" style="display:flex;gap:12px;justify-content:space-between;flex-wrap:wrap">
                <span class="ad-muted">
                    Showing <?= (int) $pagination['from'] ?>&ndash;<?= (int) $pagination['to'] ?>
                    of <?= number_format((int) $pagination['total']) ?>.
                    Boxes left blank are skipped, not cleared.
                </span>
                <?php if ($canEdit): ?>
                    <button type="submit" class="ad-btn ad-btn--primary">
                        <?= icon('check', 'w-4 h-4') ?> Save these descriptions
                    </button>
                <?php else: ?>
                    <span class="ad-muted">Filling these in needs the products.edit permission.</span>
                <?php endif; ?>
            </div>
        </form>
    <?php endif; ?>

    <?php if ($pagination['last'] > 1): ?>
        <div class="ad-card__foot">
            <?= admin_pagination($pagination, seo_admin_url('images')) ?>
        </div>
    <?php endif; ?>
</div>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
