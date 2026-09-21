<?php
/**
 * ShopInnKart Admin - Duplicate a product.
 *
 * POST only. The copy is a draft with its own slug, SKU and image files, so
 * editing or deleting it can never touch the original.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require_action('products.create');

require_once ADMIN_PATH . '/products/_save.php';

$sourceId = max(0, (int) input('id', 0));
$source   = $sourceId > 0
    ? Database::fetch('SELECT * FROM `products` WHERE `id` = :id', ['id' => $sourceId])
    : null;

if ($source === null) {
    flash('error', 'That product could not be found.');
    redirect(admin_url('products/'));
}

$newId = Database::transaction(static function () use ($source, $sourceId): int {
    $row = $source;
    unset($row['id'], $row['created_at'], $row['updated_at']);

    $row['name']         = mb_substr($source['name'] . ' (Copy)', 0, 255);
    $row['slug']         = unique_slug('products', mb_substr(slugify($source['slug'] . '-copy'), 0, 280));
    $row['sku']          = product_unique_sku($source['sku'] . '-copy');
    $row['status']       = 'draft';
    $row['published_at'] = null;
    $row['views']        = 0;
    $row['sold_count']   = 0;
    $row['rating_avg']   = 0;
    $row['rating_count'] = 0;
    // Stock is journalled in below so the copy's ledger starts from a movement.
    $row['stock']        = 0;
    $row['main_image']   = product_copy_upload($source['main_image']);
    $row['hover_image']  = product_copy_upload($source['hover_image']);

    $newId = Database::insert('products', $row);

    // --- gallery --------------------------------------------------------
    $images = Database::fetchAll(
        'SELECT `image`, `alt_text`, `sort_order` FROM `product_images` WHERE `product_id` = :id ORDER BY `sort_order`, `id`',
        ['id' => $sourceId]
    );
    foreach ($images as $image) {
        $copied = product_copy_upload((string) $image['image']);
        if ($copied === null) {
            continue;
        }
        Database::insert('product_images', [
            'product_id' => $newId,
            'image'      => $copied,
            'alt_text'   => $image['alt_text'],
            'sort_order' => (int) $image['sort_order'],
        ]);
    }

    // --- specifications, features, tags, relations ------------------------
    foreach (Database::fetchAll(
        'SELECT `spec_group`, `spec_key`, `spec_value`, `sort_order` FROM `product_specifications`
         WHERE `product_id` = :id ORDER BY `sort_order`, `id`',
        ['id' => $sourceId]
    ) as $spec) {
        Database::insert('product_specifications', $spec + ['product_id' => $newId]);
    }

    foreach (Database::fetchAll(
        'SELECT `feature`, `sort_order` FROM `product_features` WHERE `product_id` = :id ORDER BY `sort_order`, `id`',
        ['id' => $sourceId]
    ) as $feature) {
        Database::insert('product_features', $feature + ['product_id' => $newId]);
    }

    Database::query(
        'INSERT IGNORE INTO `product_tags` (`product_id`, `tag_id`)
         SELECT :new, `tag_id` FROM `product_tags` WHERE `product_id` = :old',
        ['new' => $newId, 'old' => $sourceId]
    );

    Database::query(
        'INSERT IGNORE INTO `product_relations` (`product_id`, `related_id`, `relation_type`, `sort_order`)
         SELECT :new, `related_id`, `relation_type`, `sort_order`
         FROM `product_relations` WHERE `product_id` = :old',
        ['new' => $newId, 'old' => $sourceId]
    );

    // --- variants ---------------------------------------------------------
    $variants = Database::fetchAll(
        'SELECT * FROM `product_variants` WHERE `product_id` = :id ORDER BY `is_default` DESC, `id`',
        ['id' => $sourceId]
    );

    foreach ($variants as $variant) {
        $newVariantId = Database::insert('product_variants', [
            'product_id'   => $newId,
            'sku'          => product_unique_variant_sku($variant['sku'] . '-copy'),
            'variant_name' => $variant['variant_name'],
            'price'        => $variant['price'],
            'sale_price'   => $variant['sale_price'],
            'stock'        => 0,
            'image'        => product_copy_upload($variant['image']),
            'is_default'   => (int) $variant['is_default'],
            'status'       => $variant['status'],
        ]);

        Database::query(
            'INSERT INTO `product_variant_attributes` (`variant_id`, `attribute_id`, `attribute_value_id`)
             SELECT :new, `attribute_id`, `attribute_value_id`
             FROM `product_variant_attributes` WHERE `variant_id` = :old',
            ['new' => $newVariantId, 'old' => (int) $variant['id']]
        );

        $stock = (int) $variant['stock'];
        if ($stock > 0) {
            adjust_stock($newId, $newVariantId, $stock, 'adjust', 'product_duplicate', $sourceId,
                'Copied from ' . $source['sku']);
        }
    }

    if ($variants !== []) {
        $total = (int) Database::fetchColumn(
            "SELECT COALESCE(SUM(`stock`), 0) FROM `product_variants` WHERE `product_id` = :id AND `status` = 'active'",
            ['id' => $newId]
        );
        Database::update('products', ['has_variants' => 1, 'stock' => $total], '`id` = :id', ['id' => $newId]);
    } else {
        Database::update('products', ['has_variants' => 0], '`id` = :id', ['id' => $newId]);
        $stock = (int) $source['stock'];
        if ($stock > 0) {
            adjust_stock($newId, null, $stock, 'adjust', 'product_duplicate', $sourceId,
                'Copied from ' . $source['sku']);
        }
    }

    return $newId;
});

log_activity('product.duplicated', 'product', $newId,
    'Duplicated "' . $source['name'] . '" (' . $source['sku'] . ') into product #' . $newId);
admin_after_write();

flash('success', 'Copied as a draft. Give it a real name, SKU and price before publishing.');
redirect(admin_url('products/edit.php?id=' . $newId));
