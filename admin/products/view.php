<?php
/**
 * ShopInnKart Admin - Product detail (read only).
 *
 * Everything the catalogue knows about one product in one place: fields,
 * media, specs, variants, the stock ledger, the orders it appears on and
 * its reviews.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('products.view');

require_once ADMIN_PATH . '/products/_shared.php';

$productId = max(0, (int) ($_GET['id'] ?? 0));
$product   = $productId > 0 ? Database::fetch(
    'SELECT p.*, b.`name` AS brand_name, c.`name` AS category_name
     FROM `products` p
     LEFT JOIN `brands` b ON b.`id` = p.`brand_id`
     LEFT JOIN `categories` c ON c.`id` = p.`category_id`
     WHERE p.`id` = :id',
    ['id' => $productId]
) : null;

if ($product === null) {
    flash('error', 'That product could not be found.');
    redirect(admin_url('products/'));
}

$images = Database::fetchAll(
    'SELECT `image`, `alt_text` FROM `product_images` WHERE `product_id` = :id ORDER BY `sort_order`, `id`',
    ['id' => $productId]
);

$specs = [];
foreach (Database::fetchAll(
    'SELECT `spec_group`, `spec_key`, `spec_value` FROM `product_specifications`
     WHERE `product_id` = :id ORDER BY `sort_order`, `id`',
    ['id' => $productId]
) as $spec) {
    $specs[(string) $spec['spec_group']][] = $spec;
}

$features = Database::fetchColumnAll(
    'SELECT `feature` FROM `product_features` WHERE `product_id` = :id ORDER BY `sort_order`, `id`',
    ['id' => $productId]
);

$tags = Database::fetchColumnAll(
    'SELECT t.`name` FROM `tags` t INNER JOIN `product_tags` pt ON pt.`tag_id` = t.`id`
     WHERE pt.`product_id` = :id ORDER BY t.`name`',
    ['id' => $productId]
);

$variants = Database::fetchAll(
    'SELECT * FROM `product_variants` WHERE `product_id` = :id ORDER BY `is_default` DESC, `id`',
    ['id' => $productId]
);

$variantAttributes = [];
if ($variants !== []) {
    [$placeholders, $variantParams] = Database::inPlaceholders(array_map(static fn ($v) => (int) $v['id'], $variants), 'v');
    foreach (Database::fetchAll(
        'SELECT pva.`variant_id`, a.`name` AS attribute_name, av.`value`
         FROM `product_variant_attributes` pva
         INNER JOIN `attributes` a ON a.`id` = pva.`attribute_id`
         INNER JOIN `attribute_values` av ON av.`id` = pva.`attribute_value_id`
         WHERE pva.`variant_id` IN (' . $placeholders . ')
         ORDER BY a.`sort_order`, a.`name`',
        $variantParams
    ) as $link) {
        $variantAttributes[(int) $link['variant_id']][] = $link['attribute_name'] . ': ' . $link['value'];
    }
}

$movements = Database::fetchAll(
    'SELECT sm.*, pv.`variant_name`, ad.`name` AS admin_name
     FROM `stock_movements` sm
     LEFT JOIN `product_variants` pv ON pv.`id` = sm.`variant_id`
     LEFT JOIN `admins` ad ON ad.`id` = sm.`admin_id`
     WHERE sm.`product_id` = :id
     ORDER BY sm.`id` DESC
     LIMIT 50',
    ['id' => $productId]
);

$orderLines = Database::fetchAll(
    'SELECT o.`id`, o.`order_number`, o.`customer_name`, o.`status`, o.`created_at`,
            oi.`variant_name`, oi.`quantity`, oi.`price`, oi.`subtotal`
     FROM `order_items` oi
     INNER JOIN `orders` o ON o.`id` = oi.`order_id`
     WHERE oi.`product_id` = :id
     ORDER BY o.`id` DESC
     LIMIT 15',
    ['id' => $productId]
);

$reviews = Database::fetchAll(
    'SELECT `id`, `customer_name`, `rating`, `title`, `comment`, `status`, `verified_purchase`, `created_at`
     FROM `reviews` WHERE `product_id` = :id ORDER BY `id` DESC LIMIT 15',
    ['id' => $productId]
);

$relatedProducts = Database::fetchAll(
    "SELECT p.`id`, p.`name`, p.`sku`, p.`main_image`
     FROM `product_relations` pr
     INNER JOIN `products` p ON p.`id` = pr.`related_id`
     WHERE pr.`product_id` = :id AND pr.`relation_type` = 'related'
     ORDER BY pr.`sort_order`, pr.`id`",
    ['id' => $productId]
);

$price  = (float) $product['price'];
$sale   = $product['sale_price'] === null ? 0.0 : (float) $product['sale_price'];
$onSale = $sale > 0 && $sale < $price;
$revenue = (float) Database::fetchColumn(
    "SELECT COALESCE(SUM(oi.`subtotal`), 0) FROM `order_items` oi
     INNER JOIN `orders` o ON o.`id` = oi.`order_id`
     WHERE oi.`product_id` = :id AND o.`status` <> 'cancelled'",
    ['id' => $productId]
);

$pageTitle    = str_limit((string) $product['name'], 70);
$pageSubtitle = 'SKU ' . $product['sku'] . '  ·  added ' . format_date($product['created_at'], 'd M Y');
$breadcrumbs  = [
    ['label' => 'Dashboard', 'url' => admin_url('dashboard.php')],
    ['label' => 'Products', 'url' => admin_url('products/')],
    ['label' => str_limit((string) $product['name'], 40)],
];

$pageActions = '<a class="ad-btn" href="' . e(product_url((string) $product['slug'])) . '" target="_blank" rel="noopener">'
    . icon('external', 'w-4 h-4') . ' Storefront</a>';

if (admin_can('products.edit')) {
    $pageActions .= '<a class="ad-btn" href="' . e(admin_url('products/inventory.php?q=' . urlencode((string) $product['sku']))) . '">'
        . icon('package', 'w-4 h-4') . ' Adjust stock</a>'
        . '<a class="ad-btn ad-btn--primary" href="' . e(admin_url('products/edit.php?id=' . $productId)) . '">'
        . icon('edit', 'w-4 h-4') . ' Edit</a>';
}

/** Small labelled row for the field tables. */
$field = static function (string $label, ?string $value, bool $muted = false): void {
    ?>
    <tr>
        <th><?= e($label) ?></th>
        <td<?= $muted ? ' class="ad-muted"' : '' ?>><?= $value === null || $value === '' ? '<span class="ad-muted">—</span>' : e($value) ?></td>
    </tr>
    <?php
};

require ADMIN_PATH . '/includes/header.php';
?>

<div class="ad-grid ad-grid--4" style="margin-bottom:18px">
    <?= admin_stat_card('Selling price', money($onSale ? $sale : $price), 'wallet', 'primary',
        $onSale ? 'MRP ' . money($price) . ' · ' . discount_percent($price, $sale) . '% off' : 'No offer running') ?>
    <?= admin_stat_card('Stock on hand', number_format((int) $product['stock']), 'package',
        (int) $product['stock'] <= 0 ? 'red' : ((int) $product['stock'] <= (int) $product['low_stock_threshold'] ? 'amber' : 'green'),
        'Alerts below ' . (int) $product['low_stock_threshold']
            . ((int) $product['has_variants'] === 1 ? ' · summed from variants' : '')) ?>
    <?= admin_stat_card('Units sold', number_format((int) $product['sold_count']), 'cart', 'blue',
        money($revenue) . ' revenue') ?>
    <?= admin_stat_card('Rating', (int) $product['rating_count'] > 0 ? number_format((float) $product['rating_avg'], 2) : '—',
        'star', 'violet', (int) $product['rating_count'] . ' review(s) · ' . number_format((int) $product['views']) . ' views') ?>
</div>

<div class="ad-grid ad-grid--sidebar">
    <div>
        <!-- ============================ Media ============================ -->
        <div class="ad-card">
            <div class="ad-card__head">
                <div class="ad-card__title">Media</div>
                <div><?= admin_state_badge((string) $product['status']) ?></div>
            </div>
            <div class="ad-card__body">
                <div style="display:flex;gap:12px;flex-wrap:wrap">
                    <figure style="margin:0">
                        <img src="<?= e(img_url($product['main_image'])) ?>" alt=""
                             style="width:150px;height:150px;object-fit:contain;border:1px solid var(--ad-border);border-radius:9px;background:#fff">
                        <figcaption class="ad-cellflex__meta" style="text-align:center;margin-top:4px">Main</figcaption>
                    </figure>

                    <?php if (!empty($product['hover_image'])): ?>
                        <figure style="margin:0">
                            <img src="<?= e(img_url($product['hover_image'])) ?>" alt=""
                                 style="width:150px;height:150px;object-fit:contain;border:1px solid var(--ad-border);border-radius:9px;background:#fff">
                            <figcaption class="ad-cellflex__meta" style="text-align:center;margin-top:4px">Hover</figcaption>
                        </figure>
                    <?php endif; ?>

                    <?php foreach ($images as $image): ?>
                        <figure style="margin:0">
                            <img src="<?= e(img_url((string) $image['image'])) ?>" alt="<?= e((string) ($image['alt_text'] ?? '')) ?>"
                                 style="width:150px;height:150px;object-fit:contain;border:1px solid var(--ad-border);border-radius:9px;background:#fff">
                            <figcaption class="ad-cellflex__meta" style="text-align:center;margin-top:4px">
                                <?= e(str_limit((string) ($image['alt_text'] ?? 'Gallery'), 20)) ?>
                            </figcaption>
                        </figure>
                    <?php endforeach; ?>
                </div>

                <?php if (!empty($product['video_url'])): ?>
                    <p style="margin-top:14px;font-size:13.5px">
                        <?= icon('external', 'w-4 h-4') ?>
                        <a href="<?= e((string) $product['video_url']) ?>" target="_blank" rel="noopener">
                            <?= e((string) $product['video_url']) ?>
                        </a>
                    </p>
                <?php endif; ?>
            </div>
        </div>

        <!-- =========================== Variants ========================== -->
        <div class="ad-card">
            <div class="ad-card__head">
                <div>
                    <div class="ad-card__title">Variants</div>
                    <div class="ad-card__sub"><?= count($variants) ?> row(s)</div>
                </div>
            </div>
            <div class="ad-card__body ad-card__body--flush">
                <?php if ($variants === []): ?>
                    <?= admin_empty('No variants', 'This product is sold as a single unit. Add variants from the edit screen when it comes in more than one configuration.', null, null, 'grid') ?>
                <?php else: ?>
                    <div class="ad-tablewrap">
                        <table class="ad-table">
                            <thead>
                                <tr>
                                    <th>Variant</th><th>SKU</th><th>Options</th>
                                    <th class="ad-table__num">Price</th><th>Stock</th><th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($variants as $variant): ?>
                                    <?php
                                    $variantPrice = (float) $variant['price'];
                                    $variantSale  = $variant['sale_price'] === null ? 0.0 : (float) $variant['sale_price'];
                                    $variantOnSale = $variantSale > 0 && $variantSale < $variantPrice;
                                    ?>
                                    <tr>
                                        <td>
                                            <strong><?= e((string) $variant['variant_name']) ?></strong>
                                            <?php if ((int) $variant['is_default'] === 1): ?>
                                                <span class="sik-status sik-status--blue">Default</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="ad-mono"><?= e((string) $variant['sku']) ?></td>
                                        <td class="ad-muted">
                                            <?= e(implode(' · ', $variantAttributes[(int) $variant['id']] ?? ['—'])) ?>
                                        </td>
                                        <td class="ad-table__num">
                                            <strong><?= e(money($variantOnSale ? $variantSale : $variantPrice)) ?></strong>
                                            <?php if ($variantOnSale): ?>
                                                <div><span class="sik-price--mrp"><?= e(money($variantPrice)) ?></span></div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= admin_stock_badge((int) $variant['stock'], (int) $product['low_stock_threshold']) ?></td>
                                        <td><?= admin_state_badge((string) $variant['status']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ======================== Specifications ======================= -->
        <div class="ad-card">
            <div class="ad-card__head">
                <div class="ad-card__title">Specifications &amp; features</div>
            </div>
            <div class="ad-card__body">
                <?php if ($specs === [] && $features === []): ?>
                    <p class="ad-muted">No specifications or features have been entered yet.</p>
                <?php else: ?>
                    <?php foreach ($specs as $groupName => $groupSpecs): ?>
                        <h4 style="font-size:13px;font-weight:700;margin:14px 0 6px"><?= e((string) $groupName) ?></h4>
                        <div class="ad-tablewrap">
                            <table class="sik-spectable">
                                <tbody>
                                    <?php foreach ($groupSpecs as $spec): ?>
                                        <tr>
                                            <th><?= e((string) $spec['spec_key']) ?></th>
                                            <td><?= e((string) $spec['spec_value']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endforeach; ?>

                    <?php if ($features !== []): ?>
                        <h4 style="font-size:13px;font-weight:700;margin:18px 0 6px">Key features</h4>
                        <ul style="display:grid;gap:6px;font-size:13.5px;padding-left:18px;list-style:disc">
                            <?php foreach ($features as $feature): ?>
                                <li><?= e((string) $feature) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- ======================= Stock movements ======================= -->
        <div class="ad-card">
            <div class="ad-card__head">
                <div>
                    <div class="ad-card__title">Stock movements</div>
                    <div class="ad-card__sub">Most recent 50 entries.</div>
                </div>
                <?php if (admin_can('products.edit')): ?>
                    <a class="ad-btn ad-btn--sm" href="<?= e(admin_url('products/inventory.php?q=' . urlencode((string) $product['sku']))) ?>">
                        Adjust stock
                    </a>
                <?php endif; ?>
            </div>
            <div class="ad-card__body ad-card__body--flush">
                <?php if ($movements === []): ?>
                    <?= admin_empty('No stock movements yet', 'Every sale, cancellation and manual adjustment will be journalled here.', null, null, 'clock') ?>
                <?php else: ?>
                    <div class="ad-tablewrap">
                        <table class="ad-table">
                            <thead>
                                <tr>
                                    <th>When</th><th>Type</th><th>Variant</th>
                                    <th class="ad-table__num">Change</th><th class="ad-table__num">Before → after</th>
                                    <th>By</th><th>Note</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($movements as $movement): ?>
                                    <?php $quantity = (int) $movement['quantity']; ?>
                                    <tr>
                                        <td class="ad-muted"><?= e(format_datetime($movement['created_at'], 'd M Y, g:i A')) ?></td>
                                        <td><span class="sik-status sik-status--gray"><?= e(ucfirst((string) $movement['movement_type'])) ?></span></td>
                                        <td class="ad-muted"><?= e((string) ($movement['variant_name'] ?? '—')) ?></td>
                                        <td class="ad-table__num" style="color:<?= $quantity < 0 ? '#B91C1C' : '#15803D' ?>;font-weight:700">
                                            <?= $quantity > 0 ? '+' : '' ?><?= $quantity ?>
                                        </td>
                                        <td class="ad-table__num ad-muted">
                                            <?= (int) $movement['stock_before'] ?> &rarr; <?= (int) $movement['stock_after'] ?>
                                        </td>
                                        <td class="ad-muted"><?= e((string) ($movement['admin_name'] ?? 'System')) ?></td>
                                        <td class="ad-muted"><?= e(str_limit((string) ($movement['note'] ?? ''), 60)) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ========================= Recent orders ======================= -->
        <div class="ad-card">
            <div class="ad-card__head">
                <div class="ad-card__title">Recent orders containing this product</div>
            </div>
            <div class="ad-card__body ad-card__body--flush">
                <?php if ($orderLines === []): ?>
                    <?= admin_empty('Not ordered yet', 'Once a customer buys this product the order lines will be listed here.', null, null, 'cart') ?>
                <?php else: ?>
                    <div class="ad-tablewrap">
                        <table class="ad-table">
                            <thead>
                                <tr>
                                    <th>Order</th><th>Customer</th><th>Variant</th><th>Date</th>
                                    <th class="ad-table__num">Qty</th><th class="ad-table__num">Line total</th><th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($orderLines as $line): ?>
                                    <tr>
                                        <td>
                                            <?php if (admin_can('orders.view')): ?>
                                                <a class="ad-mono" style="font-weight:700"
                                                   href="<?= e(admin_url('orders/view.php?id=' . (int) $line['id'])) ?>">
                                                    <?= e((string) $line['order_number']) ?>
                                                </a>
                                            <?php else: ?>
                                                <span class="ad-mono"><?= e((string) $line['order_number']) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= e((string) $line['customer_name']) ?></td>
                                        <td class="ad-muted"><?= e((string) ($line['variant_name'] ?? '—')) ?></td>
                                        <td class="ad-muted"><?= e(format_date($line['created_at'], 'd M Y')) ?></td>
                                        <td class="ad-table__num"><?= (int) $line['quantity'] ?></td>
                                        <td class="ad-table__num"><strong><?= e(money((float) $line['subtotal'])) ?></strong></td>
                                        <td><?= admin_status_badge((string) $line['status']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ============================ Reviews ========================== -->
        <div class="ad-card">
            <div class="ad-card__head">
                <div class="ad-card__title">Reviews</div>
                <?php if (admin_can('reviews.view')): ?>
                    <a class="ad-btn ad-btn--sm" href="<?= e(admin_url('reviews/?product_id=' . $productId)) ?>">Moderate</a>
                <?php endif; ?>
            </div>
            <div class="ad-card__body ad-card__body--flush">
                <?php if ($reviews === []): ?>
                    <?= admin_empty('No reviews yet', 'Customer reviews for this product will appear here once they are submitted.', null, null, 'star') ?>
                <?php else: ?>
                    <div class="ad-tablewrap">
                        <table class="ad-table">
                            <thead>
                                <tr><th>Customer</th><th>Rating</th><th>Review</th><th>Status</th><th>Date</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($reviews as $review): ?>
                                    <tr>
                                        <td>
                                            <?= e((string) $review['customer_name']) ?>
                                            <?php if ((int) $review['verified_purchase'] === 1): ?>
                                                <div class="ad-cellflex__meta">Verified purchase</div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= rating_stars((float) $review['rating']) ?></td>
                                        <td>
                                            <?php if (!empty($review['title'])): ?>
                                                <strong><?= e((string) $review['title']) ?></strong><br>
                                            <?php endif; ?>
                                            <span class="ad-muted"><?= e(str_limit((string) ($review['comment'] ?? ''), 90)) ?></span>
                                        </td>
                                        <td><?= admin_state_badge((string) $review['status']) ?></td>
                                        <td class="ad-muted"><?= e(format_date($review['created_at'], 'd M Y')) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- =============================== Aside ============================= -->
    <div>
        <div class="ad-card">
            <div class="ad-card__head"><div class="ad-card__title">Catalogue</div></div>
            <div class="ad-card__body ad-card__body--flush">
                <div class="ad-tablewrap">
                    <table class="sik-spectable">
                        <tbody>
                            <?php
                            $field('Name', (string) $product['name']);
                            $field('Slug', (string) $product['slug']);
                            $field('SKU', (string) $product['sku']);
                            $field('Category', $product['category_name']);
                            $field('Brand', $product['brand_name']);
                            $field('Status', ucfirst((string) $product['status']));
                            $field('Published at', $product['published_at'] === null ? 'Immediately' : format_datetime($product['published_at']));
                            $field('Short description', (string) ($product['short_description'] ?? ''));
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="ad-card">
            <div class="ad-card__head"><div class="ad-card__title">Pricing &amp; logistics</div></div>
            <div class="ad-card__body ad-card__body--flush">
                <div class="ad-tablewrap">
                    <table class="sik-spectable">
                        <tbody>
                            <?php
                            $field('Price (MRP)', money($price));
                            $field('Sale price', $sale > 0 ? money($sale) : null);
                            $field('Cost price', $product['cost_price'] === null ? null : money((float) $product['cost_price']));
                            $field('GST rate', number_format((float) $product['tax_rate'], 2) . '%');
                            $field('HSN code', $product['hsn_code']);
                            $field('Weight', $product['weight'] === null ? null : number_format((float) $product['weight'], 3) . ' kg');
                            $field('Order quantity', (int) $product['min_order_qty'] . ' – ' . (int) $product['max_order_qty']);
                            $field('Cash on delivery', (int) $product['cod_available'] === 1 ? 'Allowed' : 'Not allowed');
                            $field('Free shipping', (int) $product['free_shipping'] === 1 ? 'Always' : 'Standard rules apply');
                            $field('Low stock threshold', (string) (int) $product['low_stock_threshold']);
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="ad-card">
            <div class="ad-card__head"><div class="ad-card__title">Product details</div></div>
            <div class="ad-card__body ad-card__body--flush">
                <div class="ad-tablewrap">
                    <table class="sik-spectable">
                        <tbody>
                            <?php
                            $field('Manufacturer', $product['manufacturer']);
                            $field('Model number', $product['model_number']);
                            $field('Part number', $product['part_number']);
                            $field('Compatibility', $product['compatibility']);
                            $field('Warranty', $product['warranty']);
                            $field('EMI note', $product['emi_text']);
                            $field('Badge', $product['badge_text'] === null ? null
                                : $product['badge_text'] . ' (' . ($product['badge_color'] ?: 'navy') . ')');
                            $field('Tags', $tags === [] ? null : implode(', ', $tags));
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="ad-card">
            <div class="ad-card__head"><div class="ad-card__title">Placement &amp; SEO</div></div>
            <div class="ad-card__body ad-card__body--flush">
                <div class="ad-tablewrap">
                    <table class="sik-spectable">
                        <tbody>
                            <?php
                            $flags = [];
                            if ((int) $product['is_featured'] === 1)    { $flags[] = 'Featured'; }
                            if ((int) $product['is_new_arrival'] === 1) { $flags[] = 'New arrival'; }
                            if ((int) $product['is_best_seller'] === 1) { $flags[] = 'Best seller'; }
                            if ((int) $product['is_trending'] === 1)    { $flags[] = 'Trending'; }

                            $field('Flags', $flags === [] ? null : implode(', ', $flags));
                            $field('Has variants', (int) $product['has_variants'] === 1 ? 'Yes' : 'No');
                            $field('Meta title', $product['meta_title']);
                            $field('Meta description', $product['meta_description']);
                            $field('Last updated', format_datetime($product['updated_at']));
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <?php if ($relatedProducts !== []): ?>
            <div class="ad-card">
                <div class="ad-card__head"><div class="ad-card__title">Related products</div></div>
                <div class="ad-card__body" style="display:grid;gap:12px">
                    <?php foreach ($relatedProducts as $relatedProduct): ?>
                        <a class="ad-cellflex" href="<?= e(admin_url('products/view.php?id=' . (int) $relatedProduct['id'])) ?>">
                            <img class="ad-thumb" src="<?= e(img_url($relatedProduct['main_image'])) ?>" alt="" loading="lazy">
                            <span style="flex:1;min-width:0">
                                <span class="ad-cellflex__name" style="display:block;font-size:13px">
                                    <?= e(str_limit((string) $relatedProduct['name'], 38)) ?>
                                </span>
                                <span class="ad-cellflex__meta"><?= e((string) $relatedProduct['sku']) ?></span>
                            </span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($product['description'])): ?>
    <div class="ad-card">
        <div class="ad-card__head"><div class="ad-card__title">Description</div></div>
        <div class="ad-card__body sik-prose"><?= sanitize_html((string) $product['description']) ?></div>
    </div>
<?php endif; ?>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
