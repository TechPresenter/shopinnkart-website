<?php
/**
 * ShopInnKart - Shared shape for product data leaving the API.
 *
 * api_product_public() is the counterpart of api_order_public(): an ALLOWLIST,
 * so a column added to `products` tomorrow - supplier, margin, an internal
 * note - stays inside unless somebody names it here.
 *
 * It replaced an unset() denylist that removed four keys and shipped
 * everything else, which is how low_stock_threshold, sold_count, views,
 * focus_keyword, schema_json, the exact stock figure and the row timestamps
 * were readable by anyone who could fetch a product.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/init.php';

// Not a route - api/index.php excludes api/includes/ for the same reason.
if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

/**
 * A decorated product reduced to what a shopper is allowed to see.
 *
 * The rule for what belongs: if the storefront already renders it on the
 * product page, it is public. Buying prices, stock levels, how many people
 * have looked at it and how many have bought it are none of those - they are
 * what a competitor would scrape - so the shopper gets in_stock, the stock
 * STATE and its label instead of the number behind them.
 */
function api_product_public(array $product): array
{
    $public = [];

    foreach ([
        // Identity and copy
        'id', 'name', 'slug', 'sku', 'short_description', 'description',
        'brand_id', 'brand_name', 'brand_slug', 'brand_logo',
        'category_id', 'category_name', 'category_slug', 'category_parent_id',
        // What it is
        'warranty', 'emi_text', 'manufacturer', 'model_number', 'part_number',
        'compatibility', 'weight', 'tax_rate',
        // Money, already resolved by decorate_product()
        'price', 'sale_price', 'mrp', 'final_price', 'discount', 'saving',
        'price_source', 'price_display', 'mrp_display', 'on_sale',
        // Buying rules the cart enforces anyway
        'min_order_qty', 'max_order_qty', 'cod_available', 'free_shipping',
        // Availability WITHOUT the number behind it
        'in_stock', 'stock_state', 'stock_label',
        // Merchandising flags the badges are built from
        'is_featured', 'is_new_arrival', 'is_best_seller', 'is_trending',
        'badge_text', 'badge_color', 'has_variants',
        // Social proof
        'rating', 'rating_avg', 'rating_count',
        // Media and links
        'url', 'image_url', 'hover_url', 'main_image', 'hover_image', 'video_url',
        // The SEO fields the page itself renders in its <head>
        'meta_title', 'meta_description', 'canonical_url',
    ] as $key) {
        if (array_key_exists($key, $product)) {
            $public[$key] = $product[$key];
        }
    }

    $public['badges'] = array_map(static fn (array $badge): array => [
        'label' => (string) ($badge['label'] ?? ''),
        'tone'  => (string) ($badge['tone'] ?? ''),
        'kind'  => (string) ($badge['kind'] ?? ''),
    ], array_values((array) ($product['badges'] ?? [])));

    $public['images'] = array_map(static fn (array $image): array => [
        'url'      => img_url($image['image'] ?? ''),
        'alt_text' => (string) ($image['alt_text'] ?? ''),
    ], array_values((array) ($product['images'] ?? [])));

    $public['videos'] = array_map(static fn (array $video): array => [
        'title'     => (string) ($video['title'] ?? ''),
        'provider'  => (string) ($video['provider'] ?? ''),
        'url'       => (string) ($video['url'] ?? ''),
        'thumbnail' => (string) ($video['thumbnail'] ?? ''),
    ], array_values((array) ($product['videos'] ?? [])));

    // Specifications arrive grouped; the group name is part of the answer.
    $specs = [];
    foreach ((array) ($product['specifications'] ?? []) as $group => $rows) {
        $specs[(string) $group] = array_map(static fn (array $row): array => [
            'key'   => (string) ($row['spec_key'] ?? ''),
            'value' => (string) ($row['spec_value'] ?? ''),
        ], array_values((array) $rows));
    }
    $public['specifications'] = $specs;

    $public['features'] = array_values(array_map('strval', (array) ($product['features'] ?? [])));

    $public['tags'] = array_map(static fn (array $tag): array => [
        'id'   => (int) ($tag['id'] ?? 0),
        'name' => (string) ($tag['name'] ?? ''),
        'slug' => (string) ($tag['slug'] ?? ''),
    ], array_values((array) ($product['tags'] ?? [])));

    // The same fields assets/js/products.js reads from Quick View, so the two
    // shapes cannot drift: variant rows carry cost-side prices and timestamps.
    $public['variants'] = array_map(static fn (array $variant): array => [
        'id'            => (int) ($variant['id'] ?? 0),
        'sku'           => (string) ($variant['sku'] ?? ''),
        'variant_name'  => (string) ($variant['variant_name'] ?? ''),
        'is_default'    => (int) ($variant['is_default'] ?? 0),
        'price_display' => (string) ($variant['price_display'] ?? ''),
        'mrp_display'   => (string) ($variant['mrp_display'] ?? ''),
        'discount'      => (int) ($variant['discount'] ?? 0),
        'stock_state'   => (string) ($variant['stock_state'] ?? ''),
        'stock_label'   => (string) ($variant['stock_label'] ?? ''),
        'in_stock'      => (bool) ($variant['in_stock'] ?? false),
        'image_url'     => (string) ($variant['image_url'] ?? ''),
        'attributes'    => array_map(static fn (array $a): array => [
            'attribute_id'       => (int) ($a['attribute_id'] ?? 0),
            'attribute_value_id' => (int) ($a['attribute_value_id'] ?? 0),
        ], array_values((array) ($variant['attributes'] ?? []))),
    ], array_values((array) ($product['variants'] ?? [])));

    $public['variant_attributes'] = array_map(static fn (array $group): array => [
        'id'     => (int) ($group['id'] ?? 0),
        'name'   => (string) ($group['name'] ?? ''),
        'type'   => (string) ($group['type'] ?? ''),
        'values' => array_map(static fn (array $value): array => [
            'id'         => (int) ($value['id'] ?? 0),
            'value'      => (string) ($value['value'] ?? ''),
            'color_code' => (string) ($value['color_code'] ?? ''),
        ], array_values((array) ($group['values'] ?? []))),
    ], array_values((array) ($product['variant_attributes'] ?? [])));

    return $public;
}
