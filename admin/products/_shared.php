<?php
/**
 * ShopInnKart Admin - Product module shared pieces.
 *
 * The list screen and the CSV export have to agree on what "the current
 * selection" means, so the filter clause is built in exactly one place.
 * Everything here is read-only lookup/option data.
 */

declare(strict_types=1);


// Include-only: this partial assumes its parent page already ran the
// authentication and permission checks. Refuse to run as an entry point.
if (!defined('SIK_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}
// ===========================================================================
//  Option lists
// ===========================================================================

/** Sortable list columns -> the SQL they actually order by. */
function product_sort_columns(): array
{
    return [
        'name'       => 'p.`name`',
        'sku'        => 'p.`sku`',
        'price'      => 'COALESCE(NULLIF(p.`sale_price`, 0), p.`price`)',
        'stock'      => 'p.`stock`',
        'sold_count' => 'p.`sold_count`',
        'rating_avg' => 'p.`rating_avg`',
        'created_at' => 'p.`created_at`',
    ];
}

function product_status_options(): array
{
    return ['active' => 'Active', 'inactive' => 'Inactive', 'draft' => 'Draft'];
}

function product_stock_options(): array
{
    return ['in' => 'In stock', 'low' => 'Low stock', 'out' => 'Out of stock'];
}

/** Marketing flags: filter key => label. */
function product_flag_options(): array
{
    return [
        'featured' => 'Featured',
        'new'      => 'New arrival',
        'best'     => 'Best seller',
        'trending' => 'Trending',
    ];
}

/** Marketing flags: filter key => products column. */
function product_flag_columns(): array
{
    return [
        'featured' => 'is_featured',
        'new'      => 'is_new_arrival',
        'best'     => 'is_best_seller',
        'trending' => 'is_trending',
    ];
}

/** Badge tones that actually have a .sik-badge--* rule behind them. */
function product_badge_colors(): array
{
    return [
        'orange' => 'Orange', 'navy' => 'Navy', 'red' => 'Red', 'green' => 'Green',
        'amber'  => 'Amber',  'purple' => 'Purple', 'grey' => 'Grey', 'soft' => 'Soft',
    ];
}

/** Reasons offered by the inventory adjustment form. */
function product_movement_reasons(): array
{
    return [
        'restock' => 'Restock / goods received',
        'adjust'  => 'Manual correction',
        'return'  => 'Customer return put back',
        'import'  => 'Bulk import',
    ];
}

/** Attributes that may build variants, each with its selectable values. */
function product_variant_attributes(): array
{
    static $attributes = null;
    if ($attributes !== null) {
        return $attributes;
    }

    $attributes = Database::fetchAll(
        "SELECT `id`, `name`, `slug`, `type` FROM `attributes`
         WHERE `is_variant` = 1 AND `status` = 'active'
         ORDER BY `sort_order`, `name`"
    );

    foreach ($attributes as &$attribute) {
        $attribute['values'] = Database::fetchPairs(
            'SELECT `id`, `value` FROM `attribute_values` WHERE `attribute_id` = :id ORDER BY `sort_order`, `value`',
            ['id' => (int) $attribute['id']]
        );
    }
    unset($attribute);

    return $attributes;
}

// ===========================================================================
//  List filtering
// ===========================================================================

/**
 * Read the list filters from the query string and turn them into a WHERE
 * clause. index.php renders these rows, export.php streams the same set.
 *
 * @return array{filters:array,where:string,params:array}
 */
function product_list_filters(): array
{
    $filters = [
        'q'           => trim((string) ($_GET['q'] ?? '')),
        'category_id' => max(0, (int) ($_GET['category_id'] ?? 0)),
        'brand_id'    => max(0, (int) ($_GET['brand_id'] ?? 0)),
        'status'      => admin_filter('status', array_keys(product_status_options())),
        'stock'       => admin_filter('stock', array_keys(product_stock_options())),
        'flag'        => admin_filter('flag', array_keys(product_flag_options())),
    ];

    $where  = ['1'];
    $params = [];

    if ($filters['q'] !== '') {
        $where[] = '(p.`name` LIKE :q1 OR p.`sku` LIKE :q2 OR p.`model_number` LIKE :q3 OR p.`part_number` LIKE :q4)';
        $like = '%' . $filters['q'] . '%';
        $params += ['q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like];
    }

    if ($filters['category_id'] > 0) {
        // Picking a parent category should show what sits under it too.
        [$placeholders, $catParams] = Database::inPlaceholders(
            category_with_descendants($filters['category_id']),
            'cat'
        );
        $where[] = 'p.`category_id` IN (' . $placeholders . ')';
        $params += $catParams;
    }

    if ($filters['brand_id'] > 0) {
        $where[] = 'p.`brand_id` = :brand_id';
        $params['brand_id'] = $filters['brand_id'];
    }

    if ($filters['status'] !== '') {
        $where[] = 'p.`status` = :status';
        $params['status'] = $filters['status'];
    }

    if ($filters['stock'] === 'in') {
        $where[] = 'p.`stock` > p.`low_stock_threshold`';
    } elseif ($filters['stock'] === 'low') {
        $where[] = 'p.`stock` > 0 AND p.`stock` <= p.`low_stock_threshold`';
    } elseif ($filters['stock'] === 'out') {
        $where[] = 'p.`stock` <= 0';
    }

    if ($filters['flag'] !== '') {
        // Column name comes from the allowlist above, never from the request.
        $where[] = 'p.`' . product_flag_columns()[$filters['flag']] . '` = 1';
    }

    return ['filters' => $filters, 'where' => implode(' AND ', $where), 'params' => $params];
}

/** Sort/direction for the list, whitelisted. */
function product_list_order(): array
{
    $columns = product_sort_columns();
    $sort = admin_safe_sort((string) ($_GET['sort'] ?? ''), array_keys($columns), 'created_at');
    $dir  = admin_safe_dir((string) ($_GET['dir'] ?? 'desc'));

    return ['sort' => $sort, 'dir' => strtolower($dir), 'sql' => $columns[$sort] . ' ' . $dir . ', p.`id` DESC'];
}

// ===========================================================================
//  CSV
// ===========================================================================

/**
 * Column order for import and export. Both sides read this list, so a file
 * exported from the list screen can be edited and imported straight back.
 */
function product_csv_columns(): array
{
    return [
        'sku', 'name', 'slug', 'brand', 'category', 'short_description', 'description',
        'price', 'sale_price', 'cost_price', 'stock', 'low_stock_threshold', 'weight',
        'tax_rate', 'hsn_code', 'warranty', 'emi_text', 'manufacturer', 'model_number',
        'part_number', 'compatibility', 'min_order_qty', 'max_order_qty', 'cod_available',
        'free_shipping', 'status', 'is_featured', 'is_new_arrival', 'is_best_seller',
        'is_trending', 'badge_text', 'badge_color', 'video_url', 'meta_title',
        'meta_description', 'main_image', 'tags',
    ];
}

/** Columns that must be present in an uploaded CSV. */
function product_csv_required_columns(): array
{
    return ['sku', 'name', 'price'];
}

// ===========================================================================
//  SKU helpers
// ===========================================================================

/** A product SKU nobody else holds, suffixing -2, -3 … until it is free. */
function product_unique_sku(string $sku): string
{
    $base = mb_substr(trim($sku) === '' ? 'SKU' : trim($sku), 0, 74);
    $candidate = $base;
    $suffix = 1;

    while (Database::exists('products', '`sku` = :sku', ['sku' => $candidate])) {
        $candidate = $base . '-' . (++$suffix);
    }
    return $candidate;
}

/** Same, for the variant SKU space (which has its own unique index). */
function product_unique_variant_sku(string $sku): string
{
    $base = mb_substr(trim($sku) === '' ? 'VAR' : trim($sku), 0, 74);
    $candidate = $base;
    $suffix = 1;

    while (Database::exists('product_variants', '`sku` = :sku', ['sku' => $candidate])) {
        $candidate = $base . '-' . (++$suffix);
    }
    return $candidate;
}

/**
 * Order lines that would be orphaned by deleting a product.
 * Cancelled orders do not count — nothing was fulfilled from them.
 */
function product_live_order_lines(int $productId): int
{
    return (int) Database::fetchColumn(
        "SELECT COUNT(*) FROM `order_items` oi
         INNER JOIN `orders` o ON o.`id` = oi.`order_id`
         WHERE oi.`product_id` = :id AND o.`status` <> 'cancelled'",
        ['id' => $productId]
    );
}
