<?php
/**
 * ShopInnKart - Product listing endpoint (AJAX shop grid).
 *
 * Returns the cards, the pager, and the applied-filter chips as ready-made
 * markup so assets/js/products.js only has to swap innerHTML.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/init.php';
require_once INCLUDES_PATH . '/widgets.php';

api_require_method(['GET']);

/**
 * Read a list of ids from the query string.
 * The filter form posts `name[]`, but SIK.apiRequest flattens arrays through
 * URLSearchParams into "1,2,3", so both shapes have to be accepted.
 */
function list_filter_ids(string $key): array
{
    $raw = $_GET[$key] ?? [];
    if (is_string($raw)) {
        $raw = explode(',', $raw);
    }
    if (!is_array($raw)) {
        return [];
    }

    $ids = [];
    foreach ($raw as $value) {
        if (is_scalar($value) && (int) $value > 0) {
            $ids[] = (int) $value;
        }
    }
    return array_values(array_unique($ids));
}

/** A numeric filter, or null when it was not supplied. */
function list_filter_number(string $key): ?float
{
    $value = input($key, null);
    return is_numeric($value) ? (float) $value : null;
}

// ---------------------------------------------------------------------------
//  Filters
// ---------------------------------------------------------------------------
$filters = [];

foreach (['q', 'category', 'brand', 'tag'] as $key) {
    $value = trim((string) input($key, ''));
    if ($value !== '') {
        $filters[$key] = $value;
    }
}

foreach (['category_ids', 'brand_ids', 'attribute_values'] as $key) {
    $ids = list_filter_ids($key);
    if ($ids !== []) {
        $filters[$key] = $ids;
    }
}

if (input_int('category_id') > 0) {
    $filters['category_id'] = input_int('category_id');
}
if (input_int('exclude_id') > 0) {
    $filters['exclude_id'] = input_int('exclude_id');
}
if (input_int('vendor_id') > 0) {
    $filters['vendor_id'] = input_int('vendor_id');
}

foreach (['min_price', 'max_price', 'rating', 'discount'] as $key) {
    $value = list_filter_number($key);
    if ($value !== null && $value >= 0) {
        $filters[$key] = $value;
    }
}

$availability = (string) input('availability', '');
if (in_array($availability, ['in_stock', 'out_of_stock'], true)) {
    $filters['availability'] = $availability;
}

foreach (['featured', 'new', 'best', 'trending', 'deal', 'flash'] as $flag) {
    if (input_bool($flag)) {
        $filters[$flag] = 1;
    }
}

$sort = (string) input('sort', 'popularity');
$allowedSorts = ['popularity', 'newest', 'price_asc', 'price_desc', 'rating', 'reviews', 'discount', 'best_selling', 'featured'];
$filters['sort'] = in_array($sort, $allowedSorts, true) ? $sort : 'popularity';

$filters['page'] = max(1, input_int('page', 1));

$perPage = input_int('per_page', 0);
if ($perPage > 0) {
    $filters['per_page'] = max(4, min(60, $perPage));
}

$viewMode = input('view', 'grid') === 'list' ? 'list' : 'grid';

// ---------------------------------------------------------------------------
//  Query
// ---------------------------------------------------------------------------
$result = query_products($filters);
$pagination = $result['pagination'];

// ---------------------------------------------------------------------------
//  Cards
// ---------------------------------------------------------------------------
$cardStyle = $viewMode === 'list' ? 'horizontal' : 'standard';
$html = '';

if ($result['items'] === []) {
    ob_start();
    ?>
    <div class="sik-empty" style="grid-column:1/-1">
        <?= icon('search', 'w-14 h-14') ?>
        <p class="sik-empty__title">No products match these filters</p>
        <p class="sik-empty__text">Try removing a filter, widening the price range, or searching for a different model.</p>
        <button type="button" class="sik-btn sik-btn--primary" data-filter-clear>Clear all filters</button>
    </div>
    <?php
    $html = (string) ob_get_clean();
} else {
    foreach ($result['items'] as $product) {
        $html .= product_card($product, $cardStyle);
    }
}

// ---------------------------------------------------------------------------
//  Pager
// ---------------------------------------------------------------------------
/** Query string for a page link, keeping every active filter. */
function list_page_href(array $filters, int $page): string
{
    $query = $filters;
    unset($query['page']);
    $query['page'] = $page;

    // http_build_query numbers array keys (brand_ids[0]); the filter form uses
    // bare brand_ids[], and PHP parses both into the same array.
    return '?' . preg_replace('/%5B\d+%5D=/', '%5B%5D=', http_build_query($query));
}

function list_pager_html(array $pagination, array $filters): string
{
    if ((int) $pagination['last'] <= 1) {
        return '';
    }

    $current = (int) $pagination['current'];
    $last = (int) $pagination['last'];

    $html = '<nav class="sik-pager" aria-label="Product pages">';

    if ($current > 1) {
        $html .= '<a class="sik-pager__link" href="' . e(list_page_href($filters, $current - 1)) . '"'
            . ' data-page="' . ($current - 1) . '" rel="prev" aria-label="Previous page">'
            . icon('chevron-left', 'w-4 h-4') . '</a>';
    } else {
        $html .= '<span class="sik-pager__link is-disabled" aria-hidden="true">' . icon('chevron-left', 'w-4 h-4') . '</span>';
    }

    foreach ($pagination['pages'] as $page) {
        if (!is_int($page)) {
            $html .= '<span class="sik-pager__gap" aria-hidden="true">' . e((string) $page) . '</span>';
            continue;
        }
        if ($page === $current) {
            $html .= '<a class="sik-pager__link is-current" href="' . e(list_page_href($filters, $page)) . '"'
                . ' data-page="' . $page . '" aria-current="page">' . $page . '</a>';
            continue;
        }
        $html .= '<a class="sik-pager__link" href="' . e(list_page_href($filters, $page)) . '"'
            . ' data-page="' . $page . '" aria-label="Page ' . $page . '">' . $page . '</a>';
    }

    if ($current < $last) {
        $html .= '<a class="sik-pager__link" href="' . e(list_page_href($filters, $current + 1)) . '"'
            . ' data-page="' . ($current + 1) . '" rel="next" aria-label="Next page">'
            . icon('chevron-right', 'w-4 h-4') . '</a>';
    } else {
        $html .= '<span class="sik-pager__link is-disabled" aria-hidden="true">' . icon('chevron-right', 'w-4 h-4') . '</span>';
    }

    return $html . '</nav>';
}

// ---------------------------------------------------------------------------
//  Applied-filter chips
// ---------------------------------------------------------------------------
/** [['name'=>form field, 'value'=>submitted value, 'label'=>human text], ...] */
function list_chip_model(array $filters): array
{
    $chips = [];

    if (!empty($filters['q'])) {
        $chips[] = ['name' => 'q', 'value' => $filters['q'], 'label' => 'Search: ' . $filters['q']];
    }

    $categories = all_categories();

    if (!empty($filters['category'])) {
        $category = get_category_by_slug((string) $filters['category']);
        $chips[] = [
            'name'  => 'category',
            'value' => $filters['category'],
            'label' => (string) ($category['name'] ?? $filters['category']),
        ];
    }
    if (!empty($filters['category_id']) && isset($categories[(int) $filters['category_id']])) {
        $chips[] = [
            'name'  => 'category_id',
            'value' => (string) $filters['category_id'],
            'label' => (string) $categories[(int) $filters['category_id']]['name'],
        ];
    }
    foreach ($filters['category_ids'] ?? [] as $categoryId) {
        if (isset($categories[(int) $categoryId])) {
            $chips[] = [
                'name'  => 'category_ids',
                'value' => (string) $categoryId,
                'label' => (string) $categories[(int) $categoryId]['name'],
            ];
        }
    }

    $brandNames = [];
    foreach (all_brands() as $brand) {
        $brandNames[(int) $brand['id']] = (string) $brand['name'];
    }
    if (!empty($filters['brand'])) {
        $brand = get_brand_by_slug((string) $filters['brand']);
        $chips[] = [
            'name'  => 'brand',
            'value' => $filters['brand'],
            'label' => (string) ($brand['name'] ?? $filters['brand']),
        ];
    }
    foreach ($filters['brand_ids'] ?? [] as $brandId) {
        if (isset($brandNames[(int) $brandId])) {
            $chips[] = ['name' => 'brand_ids', 'value' => (string) $brandId, 'label' => $brandNames[(int) $brandId]];
        }
    }

    if (!empty($filters['tag'])) {
        $tagName = Database::fetchColumn('SELECT `name` FROM `tags` WHERE `slug` = :slug', ['slug' => $filters['tag']]);
        $chips[] = ['name' => 'tag', 'value' => $filters['tag'], 'label' => (string) ($tagName ?? $filters['tag'])];
    }

    if (!empty($filters['attribute_values'])) {
        [$placeholders, $params] = Database::inPlaceholders($filters['attribute_values'], 'av');
        $rows = Database::fetchAll(
            'SELECT av.`id`, av.`value`, a.`name` AS attribute_name
             FROM `attribute_values` av
             INNER JOIN `attributes` a ON a.`id` = av.`attribute_id`
             WHERE av.`id` IN (' . $placeholders . ')
             ORDER BY a.`sort_order`, av.`sort_order`',
            $params
        );
        foreach ($rows as $row) {
            $chips[] = [
                'name'  => 'attribute_values',
                'value' => (string) $row['id'],
                'label' => $row['attribute_name'] . ': ' . $row['value'],
            ];
        }
    }

    if (isset($filters['min_price'])) {
        $chips[] = ['name' => 'min_price', 'value' => (string) $filters['min_price'], 'label' => 'From ' . money($filters['min_price'])];
    }
    if (isset($filters['max_price'])) {
        $chips[] = ['name' => 'max_price', 'value' => (string) $filters['max_price'], 'label' => 'Up to ' . money($filters['max_price'])];
    }
    if (!empty($filters['rating'])) {
        $rating = (float) $filters['rating'];
        $chips[] = [
            'name'  => 'rating',
            'value' => (string) $filters['rating'],
            'label' => rtrim(rtrim(number_format($rating, 1, '.', ''), '0'), '.') . '★ & above',
        ];
    }
    if (!empty($filters['discount'])) {
        $chips[] = ['name' => 'discount', 'value' => (string) $filters['discount'], 'label' => (int) $filters['discount'] . '% off or more'];
    }
    if (!empty($filters['availability'])) {
        $chips[] = [
            'name'  => 'availability',
            'value' => (string) $filters['availability'],
            'label' => $filters['availability'] === 'in_stock' ? 'In Stock' : 'Out of Stock',
        ];
    }

    foreach ([
        'featured' => 'Featured',
        'new'      => 'New Arrivals',
        'best'     => 'Best Sellers',
        'trending' => 'Trending',
        'deal'     => 'On Deal',
        'flash'    => 'Flash Sale',
    ] as $flag => $label) {
        if (!empty($filters[$flag])) {
            $chips[] = ['name' => $flag, 'value' => '1', 'label' => $label];
        }
    }

    return $chips;
}

function list_chips_html(array $filters): string
{
    $chips = list_chip_model($filters);
    if ($chips === []) {
        return '';
    }

    $html = '<div class="sik-chips">';
    foreach ($chips as $chip) {
        $html .= '<span class="sik-chip">' . e($chip['label'])
            . '<button type="button" data-filter-chip-remove'
            . ' data-name="' . e_attr($chip['name']) . '"'
            . ' data-value="' . e_attr($chip['value']) . '"'
            . ' aria-label="Remove filter ' . e_attr($chip['label']) . '">'
            . icon('close', 'w-3.5 h-3.5') . '</button></span>';
    }

    $html .= '<button type="button" class="sik-chip" data-filter-clear'
        . ' style="background:transparent;border:1px dashed var(--sik-border);color:var(--sik-muted);padding-inline:12px">'
        . icon('refresh', 'w-3.5 h-3.5') . ' Clear all</button>';

    return $html . '</div>';
}

json_success('OK', [
    'html'            => $html,
    'pagination'      => $pagination,
    'pagination_html' => list_pager_html($pagination, $filters),
    'chips_html'      => list_chips_html($filters),
    'filters'         => $filters,
    'view'            => $viewMode,
]);
