<?php
/**
 * ShopInnKart - Catalogue data layer.
 *
 * Every price shown to a customer and every price charged at checkout comes
 * from product_effective_price() below. Nothing reads a price from the browser.
 */

declare(strict_types=1);

// ===========================================================================
//  PRICING
// ===========================================================================

/**
 * Canonical price resolution for a product (optionally a specific variant).
 *
 * Precedence: active flash sale > active deal > variant sale price >
 *             variant price > product sale price > product price.
 *
 * @return array{mrp:float, price:float, discount:int, saving:float, source:string}
 */
function product_effective_price(array $product, ?array $variant = null): array
{
    $base = product_base_price($product, $variant);
    $mrp = $base['mrp'];
    $price = $base['price'];
    $source = $price < $mrp ? 'sale' : 'mrp';

    // An active flash sale overrides the regular sale price when it is cheaper.
    // The variant goes in: a flash price is stored against the product, and
    // applying that one number to every variant sold a ₹5,000 option for the
    // ₹500 the base product was marked down to.
    $flashPrice = flash_sale_price_for((int) $product['id'], $variant, (float) ($product['price'] ?? 0));
    if ($flashPrice !== null && $flashPrice > 0 && $flashPrice < $price) {
        $price = $flashPrice;
        $source = 'flash';
    }

    // A running deal on this product can beat both.
    $dealPrice = deal_price_for((int) $product['id'], $mrp);
    if ($dealPrice !== null && $dealPrice > 0 && $dealPrice < $price) {
        $price = $dealPrice;
        $source = 'deal';
    }

    $price = money_round(max(0.0, $price));
    $mrp = money_round($mrp);

    return [
        'mrp'      => $mrp,
        'price'    => $price,
        'discount' => discount_percent($mrp, $price),
        'saving'   => money_round(max(0.0, $mrp - $price)),
        'source'   => $source,
    ];
}

/**
 * What a product costs before any promotion: the variant's own sale price,
 * else its list price, else the product's.
 *
 * create_order() needs this on its own, because a flash sale or a deal only
 * covers so many units - the ones past the cap are sold at this.
 *
 * @return array{mrp:float, price:float}
 */
function product_base_price(array $product, ?array $variant = null): array
{
    $mrp = (float) ($variant['price'] ?? $product['price'] ?? 0);
    $price = $mrp;

    $salePrice = $variant !== null
        ? ($variant['sale_price'] ?? null)
        : ($product['sale_price'] ?? null);

    if ($salePrice !== null && (float) $salePrice > 0 && (float) $salePrice < $mrp) {
        $price = (float) $salePrice;
    }

    return ['mrp' => money_round($mrp), 'price' => money_round(max(0.0, $price))];
}

/**
 * What one unit costs under a flash-sale row that create_order() has locked.
 *
 * Same rule as flash_sale_price_for(): a price pinned against the product is
 * applied to a variant as the same proportion off, never as the same number.
 */
function flash_row_price(array $flashRow, array $product, ?array $variant = null): float
{
    $base = (float) ($product['price'] ?? 0);
    $unit = $variant !== null ? (float) ($variant['price'] ?? 0) : $base;

    $pinned = $flashRow['sale_price'] !== null && (float) $flashRow['sale_price'] > 0
        ? (float) $flashRow['sale_price']
        : null;

    if ($pinned !== null) {
        if ($variant === null || $base <= 0) {
            return money_round(max(0.0, $pinned));
        }
        return money_round($unit * max(0.0, min(1.0, $pinned / $base)));
    }

    return (string) $flashRow['discount_type'] === 'fixed'
        ? money_round(max(0.0, $unit - (float) $flashRow['discount_value']))
        : money_round($unit * (1 - ((float) $flashRow['discount_value'] / 100)));
}

/** The same, for a deal row create_order() has locked. */
function deal_row_price(array $dealRow, array $product, ?array $variant = null): float
{
    $base = (float) ($product['price'] ?? 0);
    $unit = $variant !== null ? (float) ($variant['price'] ?? 0) : $base;

    $pinned = ($dealRow['deal_price'] ?? null) !== null && (float) $dealRow['deal_price'] > 0
        ? (float) $dealRow['deal_price']
        : null;

    if ($pinned !== null) {
        if ($variant === null || $base <= 0) {
            return money_round(max(0.0, $pinned));
        }
        return money_round($unit * max(0.0, min(1.0, $pinned / $base)));
    }

    return (string) $dealRow['discount_type'] === 'fixed'
        ? money_round(max(0.0, $unit - (float) $dealRow['discount_value']))
        : money_round($unit * (1 - ((float) $dealRow['discount_value'] / 100)));
}

/**
 * Flash-sale price for a product, or for one of its variants.
 *
 * A flash price is stored per product, so a variant has to be priced by what
 * the sale is worth rather than by the number itself: a sale that takes the
 * ₹1,000 base product to ₹500 is half off, and half off a ₹5,000 variant is
 * ₹2,500 - not ₹500, which is what pricing every variant from the product's
 * own row used to charge.
 *
 * @param array|null $variant the chosen variant, when the product has any
 * @param float|null $base    the product's own price, for the ratio
 */
function flash_sale_price_for(int $productId, ?array $variant = null, ?float $base = null): ?float
{
    $rule = flash_sale_rule_for($productId);
    if ($rule === null) {
        return null;
    }

    $variantPrice = $variant === null ? null : (float) ($variant['price'] ?? 0);
    if ($variantPrice === null || $variantPrice <= 0) {
        return $rule['price'];
    }

    // A sale-wide rule already knows how to apply itself to any price.
    if ($rule['sale_price'] === null) {
        return $rule['type'] === 'fixed'
            ? money_round(max(0.0, $variantPrice - $rule['value']))
            : money_round($variantPrice * (1 - ($rule['value'] / 100)));
    }

    $base = $base !== null && $base > 0 ? $base : $rule['base'];
    if ($base <= 0) {
        return null;
    }

    return money_round($variantPrice * max(0.0, min(1.0, $rule['sale_price'] / $base)));
}

/** The flash-sale rule covering a product, or null. */
function flash_sale_rule_for(int $productId): ?array
{
    return active_flash_sale_prices()[$productId] ?? null;
}

/**
 * productId => the running flash sale's rule for it.
 *
 * `price` is what the base product costs under the sale; `sale_price` is the
 * absolute price the admin pinned (null when the sale-wide discount applies),
 * and `headroom` is how many units of the per-product cap are left - null when
 * there is no cap.
 */
function active_flash_sale_prices(): array
{
    static $map = null;
    if ($map !== null) {
        return $map;
    }

    $sale = get_active_flash_sale();
    if ($sale === null) {
        return $map = [];
    }

    $rows = Database::fetchAll(
        'SELECT fsp.`id`, fsp.`product_id`, fsp.`sale_price`, fsp.`stock_limit`, fsp.`stock_sold`, p.`price`
         FROM `flash_sale_products` fsp
         INNER JOIN `products` p ON p.`id` = fsp.`product_id`
         WHERE fsp.`flash_sale_id` = :id',
        ['id' => (int) $sale['id']]
    );

    $map = [];
    foreach ($rows as $row) {
        // A per-product cap that has been exhausted ends the offer for that item.
        $headroom = $row['stock_limit'] === null
            ? null
            : max(0, (int) $row['stock_limit'] - (int) $row['stock_sold']);
        if ($headroom !== null && $headroom <= 0) {
            continue;
        }

        $base = (float) $row['price'];
        $pinned = $row['sale_price'] !== null && (float) $row['sale_price'] > 0 ? (float) $row['sale_price'] : null;

        $map[(int) $row['product_id']] = [
            'row_id'     => (int) $row['id'],
            'sale_id'    => (int) $sale['id'],
            'sale_price' => $pinned,
            'type'       => (string) $sale['discount_type'],
            'value'      => (float) $sale['discount_value'],
            'base'       => $base,
            'headroom'   => $headroom,
            'price'      => $pinned ?? ($sale['discount_type'] === 'fixed'
                ? max(0.0, $base - (float) $sale['discount_value'])
                : money_round($base * (1 - ((float) $sale['discount_value'] / 100)))),
        ];
    }

    return $map;
}

/** Deal price for a product, or null. */
function deal_price_for(int $productId, float $mrp): ?float
{
    $map = active_deal_prices();
    if (!isset($map[$productId])) {
        return null;
    }

    $deal = $map[$productId];
    if ($deal['deal_price'] !== null && (float) $deal['deal_price'] > 0) {
        return (float) $deal['deal_price'];
    }
    return $deal['discount_type'] === 'fixed'
        ? max(0.0, $mrp - (float) $deal['discount_value'])
        : money_round($mrp * (1 - ((float) $deal['discount_value'] / 100)));
}

/** productId => deal pricing rule for every product in a running deal. */
function active_deal_prices(): array
{
    static $map = null;
    if ($map !== null) {
        return $map;
    }

    $rows = Database::fetchAll(
        "SELECT dp.`product_id`, dp.`deal_price`, d.`discount_type`, d.`discount_value`
         FROM `deal_products` dp
         INNER JOIN `deals` d ON d.`id` = dp.`deal_id`
         WHERE d.`status` = 'active' AND d.`start_time` <= NOW() AND d.`end_time` >= NOW()
           AND (d.`stock_limit` IS NULL OR d.`stock_sold` < d.`stock_limit`)"
    );

    $map = [];
    foreach ($rows as $row) {
        $map[(int) $row['product_id']] = $row;
    }
    return $map;
}

/** The running "Deal of the Day", with its product joined in. */
function get_active_deal(): ?array
{
    return cache_remember('deal.active', 60, static function () {
        return Database::fetch(
            "SELECT d.*, p.`name` AS product_name, p.`slug` AS product_slug, p.`main_image`,
                    p.`price` AS product_price, p.`sale_price` AS product_sale_price,
                    p.`stock` AS product_stock, p.`rating_avg`, p.`rating_count`, b.`name` AS brand_name
             FROM `deals` d
             LEFT JOIN `products` p ON p.`id` = d.`product_id`
             LEFT JOIN `brands` b ON b.`id` = p.`brand_id`
             WHERE d.`status` = 'active' AND d.`start_time` <= NOW() AND d.`end_time` >= NOW()
               AND d.`product_id` IS NOT NULL
             ORDER BY d.`end_time` ASC
             LIMIT 1"
        );
    });
}

/** The running flash sale, or null. */
function get_active_flash_sale(): ?array
{
    static $sale = false;
    if ($sale !== false) {
        return $sale;
    }
    return $sale = Database::fetch(
        "SELECT * FROM `flash_sales`
         WHERE `status` = 'active' AND `start_time` <= NOW() AND `end_time` >= NOW()
         ORDER BY `end_time` ASC LIMIT 1"
    );
}

// ===========================================================================
//  PRODUCT QUERIES
// ===========================================================================

/** The SELECT list + joins shared by every product listing query. */
function product_select_sql(): string
{
    return 'SELECT p.`id`, p.`name`, p.`slug`, p.`sku`, p.`short_description`, p.`price`, p.`sale_price`,
                   p.`stock`, p.`low_stock_threshold`, p.`main_image`, p.`hover_image`, p.`badge_text`, p.`badge_color`,
                   p.`warranty`, p.`emi_text`, p.`rating_avg`, p.`rating_count`, p.`sold_count`, p.`views`,
                   p.`is_featured`, p.`is_new_arrival`, p.`is_best_seller`, p.`is_trending`, p.`has_variants`,
                   p.`cod_available`, p.`free_shipping`, p.`min_order_qty`, p.`max_order_qty`, p.`created_at`,
                   p.`brand_id`, p.`category_id`,
                   b.`name` AS brand_name, b.`slug` AS brand_slug,
                   c.`name` AS category_name, c.`slug` AS category_slug
            FROM `products` p
            LEFT JOIN `brands` b ON b.`id` = p.`brand_id`
            LEFT JOIN `categories` c ON c.`id` = p.`category_id`';
}

/** WHERE fragment limiting results to products a customer may see. */
function product_visible_sql(string $alias = 'p'): string
{
    return "{$alias}.`status` = 'active' AND ({$alias}.`published_at` IS NULL OR {$alias}.`published_at` <= NOW())";
}

/**
 * The one filterable product query used by /shop, /search, category and
 * brand pages and the products API.
 *
 * Supported $filters keys:
 *   q, category, category_id, brand, brand_ids[], category_ids[], tag,
 *   min_price, max_price, rating, discount, availability, featured,
 *   new, best, trending, deal, flash, attribute_values[], sort, page, per_page
 *
 * @return array{items:array, pagination:array, filters:array}
 */
function query_products(array $filters = []): array
{
    $where = [product_visible_sql()];
    $params = [];
    $joins = '';

    // --- text search --------------------------------------------------------
    $q = trim((string) ($filters['q'] ?? ''));
    if ($q !== '') {
        $where[] = '(p.`name` LIKE :q1 OR p.`sku` LIKE :q2 OR p.`short_description` LIKE :q3
                     OR b.`name` LIKE :q4 OR c.`name` LIKE :q5 OR p.`model_number` LIKE :q6)';
        $like = '%' . $q . '%';
        $params += ['q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like, 'q5' => $like, 'q6' => $like];
    }

    // --- category (includes children) --------------------------------------
    $categoryIds = [];
    if (!empty($filters['category'])) {
        $category = get_category_by_slug((string) $filters['category']);
        if ($category !== null) {
            $categoryIds = category_with_descendants((int) $category['id']);
        } else {
            $categoryIds = [-1]; // unknown slug -> no results
        }
    } elseif (!empty($filters['category_id'])) {
        $categoryIds = category_with_descendants((int) $filters['category_id']);
    }

    if (!empty($filters['category_ids']) && is_array($filters['category_ids'])) {
        $selected = [];
        foreach ($filters['category_ids'] as $id) {
            $selected = array_merge($selected, category_with_descendants((int) $id));
        }
        $categoryIds = $categoryIds === [] ? $selected : array_intersect($categoryIds, $selected);
        if ($categoryIds === []) {
            $categoryIds = [-1];
        }
    }

    if ($categoryIds !== []) {
        [$placeholders, $catParams] = Database::inPlaceholders(array_values(array_unique($categoryIds)), 'cat');
        $where[] = 'p.`category_id` IN (' . $placeholders . ')';
        $params += $catParams;
    }

    // --- brand --------------------------------------------------------------
    if (!empty($filters['brand'])) {
        $where[] = 'b.`slug` = :brand_slug';
        $params['brand_slug'] = (string) $filters['brand'];
    }
    if (!empty($filters['brand_ids']) && is_array($filters['brand_ids'])) {
        $ids = array_values(array_filter(array_map('intval', $filters['brand_ids'])));
        if ($ids !== []) {
            [$placeholders, $brandParams] = Database::inPlaceholders($ids, 'brnd');
            $where[] = 'p.`brand_id` IN (' . $placeholders . ')';
            $params += $brandParams;
        }
    }

    // --- tag ----------------------------------------------------------------
    if (!empty($filters['tag'])) {
        $joins .= ' INNER JOIN `product_tags` pt ON pt.`product_id` = p.`id`
                    INNER JOIN `tags` t ON t.`id` = pt.`tag_id`';
        $where[] = 't.`slug` = :tag_slug';
        $params['tag_slug'] = (string) $filters['tag'];
    }

    // --- attribute value filters (colour / storage / RAM ...) ---------------
    if (!empty($filters['attribute_values']) && is_array($filters['attribute_values'])) {
        $ids = array_values(array_filter(array_map('intval', $filters['attribute_values'])));
        if ($ids !== []) {
            [$placeholders, $attrParams] = Database::inPlaceholders($ids, 'attr');
            $where[] = 'p.`id` IN (
                SELECT pv.`product_id` FROM `product_variants` pv
                INNER JOIN `product_variant_attributes` pva ON pva.`variant_id` = pv.`id`
                WHERE pva.`attribute_value_id` IN (' . $placeholders . ')
            )';
            $params += $attrParams;
        }
    }

    // --- price --------------------------------------------------------------
    // Compare against the effective selling price, matching what is displayed.
    $priceExpr = 'COALESCE(NULLIF(p.`sale_price`, 0), p.`price`)';
    if (isset($filters['min_price']) && $filters['min_price'] !== '' && is_numeric($filters['min_price'])) {
        $where[] = $priceExpr . ' >= :min_price';
        $params['min_price'] = (float) $filters['min_price'];
    }
    if (isset($filters['max_price']) && $filters['max_price'] !== '' && is_numeric($filters['max_price'])) {
        $where[] = $priceExpr . ' <= :max_price';
        $params['max_price'] = (float) $filters['max_price'];
    }

    // --- rating -------------------------------------------------------------
    if (!empty($filters['rating']) && is_numeric($filters['rating'])) {
        $where[] = 'p.`rating_avg` >= :rating';
        $params['rating'] = (float) $filters['rating'];
    }

    // --- discount -----------------------------------------------------------
    if (!empty($filters['discount']) && is_numeric($filters['discount'])) {
        $where[] = '(p.`sale_price` IS NOT NULL AND p.`sale_price` > 0 AND p.`price` > 0
                     AND ((p.`price` - p.`sale_price`) / p.`price`) * 100 >= :discount)';
        $params['discount'] = (float) $filters['discount'];
    }

    // --- availability -------------------------------------------------------
    $availability = (string) ($filters['availability'] ?? '');
    if ($availability === 'in_stock') {
        $where[] = 'p.`stock` > 0';
    } elseif ($availability === 'out_of_stock') {
        $where[] = 'p.`stock` <= 0';
    }

    // --- flags --------------------------------------------------------------
    foreach ([
        'featured' => 'is_featured',
        'new'      => 'is_new_arrival',
        'best'     => 'is_best_seller',
        'trending' => 'is_trending',
    ] as $key => $column) {
        if (!empty($filters[$key])) {
            $where[] = 'p.`' . $column . '` = 1';
        }
    }

    // Everything that is genuinely marked down right now. A "sale" section
    // built this way needs no curation and can never advertise a discount
    // that is not on the product page - the two read the same two columns.
    if (!empty($filters['on_sale'])) {
        $where[] = 'p.`sale_price` IS NOT NULL AND p.`sale_price` > 0 AND p.`sale_price` < p.`price`';
    }

    if (!empty($filters['flash'])) {
        $sale = get_active_flash_sale();
        if ($sale === null) {
            $where[] = '1 = 0';
        } else {
            $where[] = 'p.`id` IN (SELECT `product_id` FROM `flash_sale_products` WHERE `flash_sale_id` = :fsid)';
            $params['fsid'] = (int) $sale['id'];
        }
    }

    if (!empty($filters['deal'])) {
        $where[] = "p.`id` IN (
            SELECT dp.`product_id` FROM `deal_products` dp
            INNER JOIN `deals` d ON d.`id` = dp.`deal_id`
            WHERE d.`status` = 'active' AND d.`start_time` <= NOW() AND d.`end_time` >= NOW()
        )";
    }

    if (!empty($filters['exclude_id'])) {
        $where[] = 'p.`id` <> :exclude_id';
        $params['exclude_id'] = (int) $filters['exclude_id'];
    }

    if (!empty($filters['vendor_id'])) {
        $where[] = 'p.`vendor_id` = :vendor_id';
        $params['vendor_id'] = (int) $filters['vendor_id'];
    }

    $whereSql = implode(' AND ', $where);

    // --- sorting ------------------------------------------------------------
    $orderBy = product_sort_sql((string) ($filters['sort'] ?? 'popularity'), $priceExpr);

    // --- pagination ---------------------------------------------------------
    $perPage = (int) ($filters['per_page'] ?? setting_int('products_per_page', PRODUCTS_PER_PAGE));
    $perPage = max(1, min(60, $perPage));
    $page = max(1, (int) ($filters['page'] ?? 1));

    $countSql = 'SELECT COUNT(DISTINCT p.`id`)
                 FROM `products` p
                 LEFT JOIN `brands` b ON b.`id` = p.`brand_id`
                 LEFT JOIN `categories` c ON c.`id` = p.`category_id`'
                 . $joins . ' WHERE ' . $whereSql;
    $total = (int) Database::fetchColumn($countSql, $params);

    $pagination = paginate($total, $perPage, $page);

    $sql = product_select_sql() . $joins
        . ' WHERE ' . $whereSql
        . ' GROUP BY p.`id`'
        . ' ORDER BY ' . $orderBy
        . ' LIMIT ' . $perPage . ' OFFSET ' . $pagination['offset'];

    $items = Database::fetchAll($sql, $params);

    return [
        'items'      => array_map('decorate_product', $items),
        'pagination' => $pagination,
        'filters'    => $filters,
    ];
}

/** Map a sort key to a safe ORDER BY clause. */
function product_sort_sql(string $sort, string $priceExpr): string
{
    switch ($sort) {
        case 'newest':
            return 'p.`created_at` DESC, p.`id` DESC';
        case 'price_asc':
            return $priceExpr . ' ASC, p.`id` DESC';
        case 'price_desc':
            return $priceExpr . ' DESC, p.`id` DESC';
        case 'rating':
            return 'p.`rating_avg` DESC, p.`rating_count` DESC, p.`id` DESC';
        case 'reviews':
            return 'p.`rating_count` DESC, p.`rating_avg` DESC, p.`id` DESC';
        case 'discount':
            return 'CASE WHEN p.`sale_price` > 0 AND p.`price` > 0
                         THEN ((p.`price` - p.`sale_price`) / p.`price`) ELSE 0 END DESC, p.`id` DESC';
        case 'best_selling':
            return 'p.`sold_count` DESC, p.`id` DESC';
        case 'featured':
            return 'p.`is_featured` DESC, p.`sold_count` DESC, p.`id` DESC';
        case 'popularity':
        default:
            return 'p.`sold_count` DESC, p.`views` DESC, p.`rating_avg` DESC, p.`id` DESC';
    }
}

/**
 * Attach computed presentation fields to a product row: resolved pricing,
 * stock state, badges and URLs. Every card and API payload uses this.
 */
function decorate_product(array $product): array
{
    $pricing = product_effective_price($product);

    $product['mrp']            = $pricing['mrp'];
    $product['final_price']    = $pricing['price'];
    $product['discount']       = $pricing['discount'];
    $product['saving']         = $pricing['saving'];
    $product['price_source']   = $pricing['source'];
    $product['price_display']  = money($pricing['price']);
    $product['mrp_display']    = money($pricing['mrp']);
    $product['on_sale']        = $pricing['price'] < $pricing['mrp'];

    $stock = (int) ($product['stock'] ?? 0);
    $product['stock_state'] = stock_status($stock, (int) ($product['low_stock_threshold'] ?? 5));
    $product['stock_label'] = stock_label($stock, (int) ($product['low_stock_threshold'] ?? 5));
    $product['in_stock']    = $stock > 0;

    $product['url']       = product_url((string) $product['slug']);
    $product['image_url'] = img_url($product['main_image'] ?? null);
    $product['hover_url'] = !empty($product['hover_image'])
        ? img_url($product['hover_image'])
        : $product['image_url'];

    $product['badges'] = product_badges($product);
    $product['rating'] = (float) ($product['rating_avg'] ?? 0);

    return $product;
}

/**
 * Badges shown on the product card, in priority order.
 * Only the first two are rendered so cards stay readable.
 */
function product_badges(array $product): array
{
    // `kind` lets a surface choose which badges it has room for without
    // matching on label text; the card, for one, drops the discount because
    // its price row already shows it.
    $badges = [];

    if (!empty($product['badge_text'])) {
        $badges[] = ['label' => (string) $product['badge_text'], 'tone' => $product['badge_color'] ?: 'navy', 'kind' => 'custom'];
    }
    if (($product['price_source'] ?? '') === 'flash') {
        $badges[] = ['label' => 'Flash sale', 'tone' => 'red', 'kind' => 'flash'];
    } elseif (($product['price_source'] ?? '') === 'deal') {
        $badges[] = ['label' => 'Deal', 'tone' => 'red', 'kind' => 'deal'];
    }
    if ((int) ($product['discount'] ?? 0) >= 10) {
        $badges[] = ['label' => (int) $product['discount'] . '% off', 'tone' => 'orange', 'kind' => 'discount'];
    }
    if (!empty($product['is_new_arrival'])) {
        $badges[] = ['label' => 'New', 'tone' => 'green', 'kind' => 'new'];
    }
    if (!empty($product['is_best_seller'])) {
        $badges[] = ['label' => 'Best seller', 'tone' => 'navy', 'kind' => 'bestseller'];
    }
    if (!empty($product['is_trending'])) {
        $badges[] = ['label' => 'Trending', 'tone' => 'purple', 'kind' => 'trending'];
    }
    if (($product['stock_state'] ?? '') === STOCK_OUT) {
        array_unshift($badges, ['label' => 'Out of stock', 'tone' => 'grey', 'kind' => 'out']);
    } elseif (($product['stock_state'] ?? '') === STOCK_LOW) {
        $badges[] = ['label' => 'Low stock', 'tone' => 'amber', 'kind' => 'low'];
    }

    return array_slice($badges, 0, 3);
}

/** A single product by slug, decorated, or null. */
function get_product_by_slug(string $slug): ?array
{
    $row = Database::fetch(
        product_select_sql() . ' WHERE p.`slug` = :slug AND ' . product_visible_sql() . ' LIMIT 1',
        ['slug' => $slug]
    );
    return $row === null ? null : decorate_product($row);
}

/** A single product by id, decorated, or null. */
function get_product(int $id, bool $visibleOnly = true): ?array
{
    $sql = product_select_sql() . ' WHERE p.`id` = :id';
    if ($visibleOnly) {
        $sql .= ' AND ' . product_visible_sql();
    }
    $row = Database::fetch($sql . ' LIMIT 1', ['id' => $id]);
    return $row === null ? null : decorate_product($row);
}

/** Full product detail: description, images, specs, features, variants, videos. */
function get_product_detail(string $slug): ?array
{
    $product = Database::fetch(
        'SELECT p.*, b.`name` AS brand_name, b.`slug` AS brand_slug, b.`logo` AS brand_logo,
                c.`name` AS category_name, c.`slug` AS category_slug, c.`parent_id` AS category_parent_id
         FROM `products` p
         LEFT JOIN `brands` b ON b.`id` = p.`brand_id`
         LEFT JOIN `categories` c ON c.`id` = p.`category_id`
         WHERE p.`slug` = :slug AND ' . product_visible_sql() . ' LIMIT 1',
        ['slug' => $slug]
    );

    if ($product === null) {
        return null;
    }

    $id = (int) $product['id'];
    $product = decorate_product($product);

    $product['images'] = Database::fetchAll(
        'SELECT * FROM `product_images` WHERE `product_id` = :id ORDER BY `sort_order`, `id`',
        ['id' => $id]
    );
    if ($product['images'] === [] && !empty($product['main_image'])) {
        $product['images'] = [[
            'id' => 0, 'product_id' => $id, 'image' => $product['main_image'],
            'alt_text' => $product['name'], 'sort_order' => 0,
        ]];
    }

    $product['specifications'] = [];
    foreach (Database::fetchAll(
        'SELECT * FROM `product_specifications` WHERE `product_id` = :id ORDER BY `sort_order`, `id`',
        ['id' => $id]
    ) as $spec) {
        $product['specifications'][$spec['spec_group']][] = $spec;
    }

    $product['features'] = Database::fetchColumnAll(
        'SELECT `feature` FROM `product_features` WHERE `product_id` = :id ORDER BY `sort_order`, `id`',
        ['id' => $id]
    );

    $product['videos'] = Database::fetchAll(
        'SELECT * FROM `product_videos` WHERE `product_id` = :id ORDER BY `sort_order`, `id`',
        ['id' => $id]
    );

    $product['variants'] = get_product_variants($id);
    $product['variant_attributes'] = build_variant_attribute_map($product['variants']);

    $product['tags'] = Database::fetchAll(
        'SELECT t.`id`, t.`name`, t.`slug` FROM `tags` t
         INNER JOIN `product_tags` pt ON pt.`tag_id` = t.`id`
         WHERE pt.`product_id` = :id ORDER BY t.`name`',
        ['id' => $id]
    );

    return $product;
}

/** Active variants for a product, each with resolved pricing. */
function get_product_variants(int $productId): array
{
    $variants = Database::fetchAll(
        "SELECT * FROM `product_variants`
         WHERE `product_id` = :id AND `status` = 'active'
         ORDER BY `is_default` DESC, `id`",
        ['id' => $productId]
    );

    if ($variants === []) {
        return [];
    }

    $product = Database::fetch('SELECT * FROM `products` WHERE `id` = :id', ['id' => $productId]);
    if ($product === null) {
        return [];
    }

    $ids = array_map(static fn ($v) => (int) $v['id'], $variants);
    [$placeholders, $params] = Database::inPlaceholders($ids, 'v');

    $attributeRows = Database::fetchAll(
        'SELECT pva.`variant_id`, pva.`attribute_id`, pva.`attribute_value_id`,
                a.`name` AS attribute_name, a.`slug` AS attribute_slug, a.`type` AS attribute_type,
                av.`value`, av.`slug` AS value_slug, av.`color_code`
         FROM `product_variant_attributes` pva
         INNER JOIN `attributes` a ON a.`id` = pva.`attribute_id`
         INNER JOIN `attribute_values` av ON av.`id` = pva.`attribute_value_id`
         WHERE pva.`variant_id` IN (' . $placeholders . ')
         ORDER BY a.`sort_order`, av.`sort_order`',
        $params
    );

    $byVariant = [];
    foreach ($attributeRows as $row) {
        $byVariant[(int) $row['variant_id']][] = $row;
    }

    foreach ($variants as &$variant) {
        $pricing = product_effective_price($product, $variant);
        $variant['mrp']           = $pricing['mrp'];
        $variant['final_price']   = $pricing['price'];
        $variant['discount']      = $pricing['discount'];
        $variant['price_display'] = money($pricing['price']);
        $variant['mrp_display']   = money($pricing['mrp']);
        $variant['stock_state']   = stock_status((int) $variant['stock'], (int) $product['low_stock_threshold']);
        $variant['stock_label']   = stock_label((int) $variant['stock'], (int) $product['low_stock_threshold']);
        $variant['in_stock']      = (int) $variant['stock'] > 0;
        $variant['image_url']     = img_url($variant['image'] ?: $product['main_image']);
        $variant['attributes']    = $byVariant[(int) $variant['id']] ?? [];
    }
    unset($variant);

    return $variants;
}

/**
 * Group variant attributes into selectable swatch groups for the PDP.
 * Returns [attribute_slug => ['name'=>, 'type'=>, 'values'=>[...]]]
 */
function build_variant_attribute_map(array $variants): array
{
    $map = [];
    foreach ($variants as $variant) {
        foreach ($variant['attributes'] ?? [] as $attribute) {
            $slug = $attribute['attribute_slug'];
            if (!isset($map[$slug])) {
                $map[$slug] = [
                    'id'     => (int) $attribute['attribute_id'],
                    'name'   => $attribute['attribute_name'],
                    'type'   => $attribute['attribute_type'],
                    'values' => [],
                ];
            }
            $valueId = (int) $attribute['attribute_value_id'];
            if (!isset($map[$slug]['values'][$valueId])) {
                $map[$slug]['values'][$valueId] = [
                    'id'         => $valueId,
                    'value'      => $attribute['value'],
                    'slug'       => $attribute['value_slug'],
                    'color_code' => $attribute['color_code'],
                ];
            }
        }
    }
    foreach ($map as $slug => $group) {
        $map[$slug]['values'] = array_values($group['values']);
    }
    return $map;
}

/**
 * product_id => number of sellable variants, for the whole catalogue.
 *
 * One cached query answers it for every card on a listing page; asking per
 * card would put 24 round-trips behind a grid render.
 *
 * @return array<int,int>
 */
function active_variant_counts(): array
{
    return cache_remember('variants.active_counts', 300, static function (): array {
        $rows = Database::fetchAll(
            "SELECT `product_id`, COUNT(*) AS `n`
             FROM `product_variants` WHERE `status` = 'active'
             GROUP BY `product_id`"
        );

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['product_id']] = (int) $row['n'];
        }
        return $map;
    });
}

/**
 * Must the shopper pick a variant before this product can be added to the cart?
 *
 * cart_add() falls back to the *default* variant when the caller supplies none.
 * That is a guess, and on a card it is an invisible one: the card shows the base
 * price while the cart quietly receives the 256 GB model when the shopper may
 * have wanted the 1 TB. So anything with a real choice to make - more than one
 * sellable variant - sends the shopper to the product page instead of adding
 * blind. A product with exactly one variant has nothing to choose, so it adds
 * directly.
 */
function variant_choice_required(array $product): bool
{
    // An explicit has_variants = 0 is authoritative: no variant rows are used.
    if (array_key_exists('has_variants', $product) && empty($product['has_variants'])) {
        return false;
    }

    return (active_variant_counts()[(int) ($product['id'] ?? 0)] ?? 0) > 1;
}

/** A single active variant belonging to a product, or null. */
function get_variant(int $variantId, ?int $productId = null): ?array
{
    $sql = "SELECT * FROM `product_variants` WHERE `id` = :id AND `status` = 'active'";
    $params = ['id' => $variantId];
    if ($productId !== null) {
        $sql .= ' AND `product_id` = :pid';
        $params['pid'] = $productId;
    }
    return Database::fetch($sql . ' LIMIT 1', $params);
}

/**
 * Products for a widget, chosen by data_source.
 * Mirrors the options exposed in the admin Homepage Builder.
 */
function get_products_for_source(string $source, int $limit = 8, ?int $sourceId = null, array $manualIds = []): array
{
    $limit = max(1, min(24, $limit));

    switch ($source) {
        case 'manual':
            if ($manualIds === []) {
                return [];
            }
            $ids = array_slice(array_values(array_filter(array_map('intval', $manualIds))), 0, $limit);
            if ($ids === []) {
                return [];
            }
            [$placeholders, $params] = Database::inPlaceholders($ids, 'm');
            $rows = Database::fetchAll(
                product_select_sql() . ' WHERE p.`id` IN (' . $placeholders . ') AND ' . product_visible_sql()
                . ' ORDER BY FIELD(p.`id`, ' . implode(',', $ids) . ')',
                $params
            );
            return array_map('decorate_product', $rows);

        case 'category':
            return query_products(['category_id' => $sourceId, 'per_page' => $limit, 'sort' => 'popularity'])['items'];

        case 'brand':
            return query_products(['brand_ids' => [$sourceId], 'per_page' => $limit, 'sort' => 'popularity'])['items'];

        case 'tag':
            $tag = $sourceId ? Database::fetchColumn('SELECT `slug` FROM `tags` WHERE `id` = :id', ['id' => $sourceId]) : null;
            return $tag ? query_products(['tag' => $tag, 'per_page' => $limit])['items'] : [];

        case 'featured':
            return query_products(['featured' => 1, 'per_page' => $limit, 'sort' => 'popularity'])['items'];

        case 'new':
            return query_products(['new' => 1, 'per_page' => $limit, 'sort' => 'newest'])['items'];

        case 'best':
            // Products an admin flagged as best sellers; when none are flagged,
            // the ones that actually sell most. A "Best sellers" section that
            // renders nothing because a checkbox was never ticked helps no one,
            // and sold_count is a fact rather than a claim.
            $flagged = query_products(['best' => 1, 'per_page' => $limit, 'sort' => 'best_selling'])['items'];
            return $flagged !== []
                ? $flagged
                : query_products(['per_page' => $limit, 'sort' => 'best_selling'])['items'];

        case 'trending':
            return query_products(['trending' => 1, 'per_page' => $limit, 'sort' => 'popularity'])['items'];

        case 'deal':
            return query_products(['deal' => 1, 'per_page' => $limit])['items'];

        case 'flash':
            return query_products(['flash' => 1, 'per_page' => $limit])['items'];

        case 'sale':
            // Deepest discount first: the point of the section is the saving.
            return query_products(['on_sale' => 1, 'per_page' => $limit, 'sort' => 'discount'])['items'];

        case 'most_viewed':
            $rows = Database::fetchAll(
                product_select_sql() . ' WHERE ' . product_visible_sql()
                . ' ORDER BY p.`views` DESC, p.`id` DESC LIMIT ' . $limit
            );
            return array_map('decorate_product', $rows);

        case 'auto':
        default:
            return query_products(['per_page' => $limit, 'sort' => 'popularity'])['items'];
    }
}

/** Related products, falling back to same-category when none are curated. */
function related_products(int $productId, int $categoryId, int $limit = 8, string $type = 'related'): array
{
    $curated = Database::fetchAll(
        product_select_sql() . '
         INNER JOIN `product_relations` pr ON pr.`related_id` = p.`id`
         WHERE pr.`product_id` = :pid AND pr.`relation_type` = :type AND ' . product_visible_sql() . '
         ORDER BY pr.`sort_order`, p.`id`
         LIMIT ' . max(1, min(24, $limit)),
        ['pid' => $productId, 'type' => $type]
    );

    if ($curated !== []) {
        return array_map('decorate_product', $curated);
    }

    if ($type !== 'related' || $categoryId <= 0) {
        return [];
    }

    return query_products([
        'category_id' => $categoryId,
        'exclude_id'  => $productId,
        'per_page'    => $limit,
        'sort'        => 'popularity',
    ])['items'];
}

// ===========================================================================
//  CATEGORIES & BRANDS
// ===========================================================================

/** All active categories, keyed by id, with a live product count. */
function all_categories(): array
{
    return cache_remember('categories.all', 300, static function () {
        $rows = Database::fetchAll(
            "SELECT c.*,
                    (SELECT COUNT(*) FROM `products` p
                     WHERE p.`category_id` = c.`id` AND p.`status` = 'active') AS product_count
             FROM `categories` c
             WHERE c.`status` = 'active'
             ORDER BY c.`sort_order`, c.`name`"
        );
        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row['id']] = $row;
        }
        return $byId;
    });
}

/** Nested category tree: top-level rows each with a 'children' array. */
function category_tree(): array
{
    $categories = all_categories();
    $tree = [];
    $children = [];

    foreach ($categories as $id => $category) {
        $categories[$id]['children'] = [];
    }
    foreach ($categories as $id => $category) {
        $parentId = $category['parent_id'] === null ? 0 : (int) $category['parent_id'];
        if ($parentId === 0) {
            $tree[$id] = $categories[$id];
        } else {
            $children[$parentId][] = $categories[$id];
        }
    }
    foreach ($tree as $id => $category) {
        $tree[$id]['children'] = $children[$id] ?? [];
        // Roll child product counts up into the parent for menu display.
        foreach ($tree[$id]['children'] as $child) {
            $tree[$id]['product_count'] = (int) $tree[$id]['product_count'] + (int) $child['product_count'];
        }
    }

    return array_values($tree);
}

function get_category_by_slug(string $slug): ?array
{
    foreach (all_categories() as $category) {
        if ($category['slug'] === $slug) {
            return $category;
        }
    }
    return null;
}

function get_category(int $id): ?array
{
    return all_categories()[$id] ?? null;
}

/** A category id plus every descendant id. */
function category_with_descendants(int $categoryId): array
{
    $categories = all_categories();
    if (!isset($categories[$categoryId])) {
        return [$categoryId];
    }

    $ids = [$categoryId];
    $queue = [$categoryId];

    while ($queue !== []) {
        $current = array_shift($queue);
        foreach ($categories as $id => $category) {
            if ((int) ($category['parent_id'] ?? 0) === $current && !in_array($id, $ids, true)) {
                $ids[] = $id;
                $queue[] = $id;
            }
        }
    }
    return $ids;
}

/** Ancestor chain for breadcrumbs, root first. */
function category_ancestors(int $categoryId): array
{
    $categories = all_categories();
    $chain = [];
    $guard = 0;

    while (isset($categories[$categoryId]) && $guard++ < 10) {
        array_unshift($chain, $categories[$categoryId]);
        $parentId = $categories[$categoryId]['parent_id'];
        if ($parentId === null) {
            break;
        }
        $categoryId = (int) $parentId;
    }
    return $chain;
}

/**
 * Featured top-level categories for the homepage carousel.
 *
 * Uses category_tree() rather than all_categories() so the product count
 * includes sub-category products — most products sit in a child category
 * (a phone is in "Android Phones", not "Smartphones"), so the raw count on
 * the parent would read zero.
 */
function featured_categories(int $limit = 12): array
{
    $featured = array_filter(category_tree(), static fn ($c) => (int) $c['is_featured'] === 1);
    return array_slice(array_values($featured), 0, $limit);
}

/** All active brands with product counts. */
function all_brands(bool $featuredOnly = false): array
{
    $key = 'brands.' . ($featuredOnly ? 'featured' : 'all');
    return cache_remember($key, 300, static function () use ($featuredOnly) {
        $sql = "SELECT b.*,
                       (SELECT COUNT(*) FROM `products` p
                        WHERE p.`brand_id` = b.`id` AND p.`status` = 'active') AS product_count
                FROM `brands` b
                WHERE b.`status` = 'active'";
        if ($featuredOnly) {
            $sql .= ' AND b.`is_featured` = 1';
        }
        return Database::fetchAll($sql . ' ORDER BY b.`sort_order`, b.`name`');
    });
}

function get_brand_by_slug(string $slug): ?array
{
    return Database::fetch(
        "SELECT * FROM `brands` WHERE `slug` = :slug AND `status` = 'active' LIMIT 1",
        ['slug' => $slug]
    );
}

/** Filter facets for the shop sidebar, scoped to the current result set. */
function shop_filter_options(): array
{
    return cache_remember('shop.facets', 300, static function () {
        $priceRow = Database::fetch(
            'SELECT MIN(COALESCE(NULLIF(`sale_price`, 0), `price`)) AS min_price,
                    MAX(COALESCE(NULLIF(`sale_price`, 0), `price`)) AS max_price
             FROM `products` WHERE ' . product_visible_sql('products')
        );

        $attributes = Database::fetchAll(
            "SELECT a.`id`, a.`name`, a.`slug`, a.`type`
             FROM `attributes` a
             WHERE a.`status` = 'active' AND a.`is_filter` = 1
             ORDER BY a.`sort_order`, a.`name`"
        );
        // Only values a visible product actually carries.
        //
        // Reading attribute_values straight out offered every value the table
        // held, whether or not anything in the catalogue had it: the seeded
        // Colour attribute put eight swatches in the rail — Midnight Black,
        // Titanium Grey and so on — and every one of them returned "no
        // products found", because no variant referenced them. A filter that
        // can only ever empty the grid is worse than an absent one.
        //
        // A group left with no values is dropped by the empty() guard in
        // product_listing_attribute_group(), so this also removes the heading.
        foreach ($attributes as &$attribute) {
            $attribute['values'] = Database::fetchAll(
                'SELECT DISTINCT av.`id`, av.`value`, av.`slug`, av.`color_code`
                 FROM `attribute_values` av
                 INNER JOIN `product_variant_attributes` pva ON pva.`attribute_value_id` = av.`id`
                 INNER JOIN `product_variants` pv ON pv.`id` = pva.`variant_id` AND pv.`status` = \'active\'
                 INNER JOIN `products` p ON p.`id` = pv.`product_id`
                 WHERE av.`attribute_id` = :id AND ' . product_visible_sql('p') . '
                 ORDER BY av.`sort_order`, av.`value`',
                ['id' => (int) $attribute['id']]
            );
        }
        unset($attribute);

        return [
            'min_price'  => (float) ($priceRow['min_price'] ?? 0),
            'max_price'  => (float) ($priceRow['max_price'] ?? 100000),
            'categories' => category_tree(),
            'brands'     => all_brands(),
            'attributes' => $attributes,
        ];
    });
}

// ===========================================================================
//  VIEWS & RECENTLY VIEWED
// ===========================================================================

/** Increment the view counter and record the product as recently viewed. */
function record_product_view(int $productId): void
{
    // Only count a product once per session so a refresh does not inflate views.
    $seen = $_SESSION['_viewed'] ?? [];
    if (!in_array($productId, $seen, true)) {
        Database::query('UPDATE `products` SET `views` = `views` + 1 WHERE `id` = :id', ['id' => $productId]);
        $seen[] = $productId;
        $_SESSION['_viewed'] = array_slice($seen, -50);
    }

    if (!setting_bool('recently_viewed_enabled', true)) {
        return;
    }

    $userId = current_user_id();
    $sessionId = session_key();

    try {
        if ($userId !== null) {
            Database::query(
                'INSERT INTO `recently_viewed` (`user_id`, `session_id`, `product_id`, `viewed_at`)
                 VALUES (:uid, NULL, :pid, NOW())
                 ON DUPLICATE KEY UPDATE `viewed_at` = NOW()',
                ['uid' => $userId, 'pid' => $productId]
            );
        } elseif ($sessionId !== '') {
            Database::query(
                'INSERT INTO `recently_viewed` (`user_id`, `session_id`, `product_id`, `viewed_at`)
                 VALUES (NULL, :sid, :pid, NOW())
                 ON DUPLICATE KEY UPDATE `viewed_at` = NOW()',
                ['sid' => $sessionId, 'pid' => $productId]
            );
        }
    } catch (Throwable $e) {
        ErrorHandler::log('warning', 'recently_viewed write failed: ' . $e->getMessage());
    }
}

/** Recently viewed products for the current visitor. */
function recently_viewed_products(int $limit = 8, ?int $excludeId = null): array
{
    // Settings > Widgets owns the feature. The switch has to live in the READ,
    // not only in record_product_view(): turning it off stops new rows being
    // written, but rows already stored would otherwise keep feeding every rail.
    // product.php builds its "Recently viewed" section straight from this
    // function, so a check in widget_recently_viewed() alone left the product
    // page rendering the row after the switch was off - exactly the "the admin
    // control does not reach the storefront" failure. Gating here covers every
    // present and future caller from one place.
    if (!setting_bool('recently_viewed_enabled', true)) {
        return [];
    }

    $userId = current_user_id();
    $sessionId = session_key();

    if ($userId === null && $sessionId === '') {
        return [];
    }

    $where = $userId !== null ? 'rv.`user_id` = :key' : 'rv.`session_id` = :key';
    $params = ['key' => $userId ?? $sessionId];

    $sql = product_select_sql() . '
        INNER JOIN `recently_viewed` rv ON rv.`product_id` = p.`id`
        WHERE ' . $where . ' AND ' . product_visible_sql();

    if ($excludeId !== null) {
        $sql .= ' AND p.`id` <> :exclude';
        $params['exclude'] = $excludeId;
    }

    $sql .= ' ORDER BY rv.`viewed_at` DESC LIMIT ' . max(1, min(20, $limit));

    return array_map('decorate_product', Database::fetchAll($sql, $params));
}

/** Move a guest's recently-viewed rows onto their account at login. */
function recently_viewed_merge(string $sessionId, int $userId): void
{
    try {
        Database::query(
            'INSERT IGNORE INTO `recently_viewed` (`user_id`, `product_id`, `viewed_at`)
             SELECT :uid, `product_id`, `viewed_at` FROM `recently_viewed` WHERE `session_id` = :sid',
            ['uid' => $userId, 'sid' => $sessionId]
        );
        Database::delete('recently_viewed', '`session_id` = :sid', ['sid' => $sessionId]);
    } catch (Throwable $e) {
        ErrorHandler::log('warning', 'recently_viewed merge failed: ' . $e->getMessage());
    }
}

// ===========================================================================
//  REVIEWS
// ===========================================================================

/** Approved reviews for a product, paginated. */
function product_reviews(int $productId, int $page = 1, int $perPage = 5, string $sort = 'newest'): array
{
    $total = (int) Database::fetchColumn(
        "SELECT COUNT(*) FROM `reviews` WHERE `product_id` = :id AND `status` = 'approved'",
        ['id' => $productId]
    );
    $pagination = paginate($total, $perPage, $page);

    $order = [
        'newest'   => '`created_at` DESC',
        'oldest'   => '`created_at` ASC',
        'highest'  => '`rating` DESC, `created_at` DESC',
        'lowest'   => '`rating` ASC, `created_at` DESC',
        'helpful'  => '`helpful_count` DESC, `created_at` DESC',
    ][$sort] ?? '`created_at` DESC';

    $items = Database::fetchAll(
        "SELECT * FROM `reviews`
         WHERE `product_id` = :id AND `status` = 'approved'
         ORDER BY " . $order . '
         LIMIT ' . $pagination['per_page'] . ' OFFSET ' . $pagination['offset'],
        ['id' => $productId]
    );

    return ['items' => $items, 'pagination' => $pagination];
}

/** Star distribution for the rating breakdown bar chart. */
function review_breakdown(int $productId): array
{
    $rows = Database::fetchPairs(
        "SELECT `rating`, COUNT(*) FROM `reviews`
         WHERE `product_id` = :id AND `status` = 'approved' GROUP BY `rating`",
        ['id' => $productId]
    );

    $total = array_sum($rows);
    $breakdown = [];
    for ($star = 5; $star >= 1; $star--) {
        $count = (int) ($rows[$star] ?? 0);
        $breakdown[$star] = [
            'count'   => $count,
            'percent' => $total > 0 ? (int) round(($count / $total) * 100) : 0,
        ];
    }
    return ['total' => $total, 'stars' => $breakdown];
}

/** Recalculate and persist a product's average rating. */
function recalculate_product_rating(int $productId): void
{
    $row = Database::fetch(
        "SELECT AVG(`rating`) AS avg_rating, COUNT(*) AS total
         FROM `reviews` WHERE `product_id` = :id AND `status` = 'approved'",
        ['id' => $productId]
    );

    Database::update('products', [
        'rating_avg'   => round((float) ($row['avg_rating'] ?? 0), 2),
        'rating_count' => (int) ($row['total'] ?? 0),
    ], '`id` = :id', ['id' => $productId]);
}

/** Has this customer bought (and received) this product? */
function has_purchased_product(int $userId, int $productId): bool
{
    return (int) Database::fetchColumn(
        "SELECT COUNT(*) FROM `order_items` oi
         INNER JOIN `orders` o ON o.`id` = oi.`order_id`
         WHERE o.`user_id` = :uid AND oi.`product_id` = :pid
           AND o.`status` IN ('delivered', 'shipped', 'out_for_delivery')",
        ['uid' => $userId, 'pid' => $productId]
    ) > 0;
}
