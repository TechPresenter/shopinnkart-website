<?php
/**
 * ShopInnKart Admin - Delete an attribute.
 *
 * attribute_values cascade from here and product_variant_attributes cascades
 * from those, so an attribute still wired into a variant would take the
 * variant's option with it. That is refused, with the numbers stated.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require_action('attributes.delete');

$id = input_int('id');
$attribute = $id > 0
    ? Database::fetch('SELECT `id`, `name` FROM `attributes` WHERE `id` = :id', ['id' => $id])
    : null;

if ($attribute === null) {
    flash('error', 'That attribute no longer exists.');
    redirect(admin_url('attributes/'));
}

$usage = Database::fetch(
    'SELECT COUNT(DISTINCT pva.`variant_id`) AS variants, COUNT(DISTINCT v.`product_id`) AS products
     FROM `product_variant_attributes` pva
     INNER JOIN `product_variants` v ON v.`id` = pva.`variant_id`
     WHERE pva.`attribute_id` = :id',
    ['id' => $id]
);

$variantCount = (int) ($usage['variants'] ?? 0);
$productCount = (int) ($usage['products'] ?? 0);

if ($variantCount > 0) {
    flash('error', '"' . $attribute['name'] . '" is used by ' . $variantCount . ' product variant'
        . ($variantCount === 1 ? '' : 's') . ' across ' . $productCount . ' product'
        . ($productCount === 1 ? '' : 's') . '. Remove it from those variants first.');
    redirect(admin_url('attributes/'));
}

$valueCount = Database::count('attribute_values', '`attribute_id` = :id', ['id' => $id]);

// attribute_values go with it through fk_attrvalue_attribute ON DELETE CASCADE.
Database::delete('attributes', '`id` = :id', ['id' => $id]);

log_activity(
    'attribute.deleted',
    'attribute',
    $id,
    'Deleted attribute "' . $attribute['name'] . '" and its ' . $valueCount . ' value(s)'
);
admin_after_write();

flash('success', 'Attribute "' . $attribute['name'] . '" and its ' . $valueCount . ' value(s) deleted.');
redirect(admin_url('attributes/'));
