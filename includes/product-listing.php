<?php
/**
 * ShopInnKart - Shared catalogue listing.
 *
 * shop / category / brand / search / deals / new-arrivals / best-sellers all
 * render through here so the filter markup, the toolbar and the pager can
 * never drift apart.
 *
 * A page calls product_listing_query() *before* including the header (so it
 * can add schema for the products it is about to show), then
 * product_listing_render() where the markup belongs.
 *
 * Everything works without JavaScript: both filter forms are plain GET forms
 * pointing back at the current URL and PHP renders the filtered result.
 * assets/js/products.js only upgrades that into an in-place grid swap, so the
 * field names here are exactly the ones api/products/list.php reads.
 */

declare(strict_types=1);

require_once __DIR__ . '/widgets.php';

/** Presets a visitor may narrow further with the sidebar controls. */
const PRODUCT_LISTING_SOFT_PRESETS = ['min_price', 'max_price', 'rating', 'discount', 'availability'];

/** Presets that are part of the page identity and travel as hidden fields. */
const PRODUCT_LISTING_HARD_PRESETS = ['q', 'category', 'brand', 'tag', 'featured', 'new', 'best', 'trending', 'deal', 'flash'];

// ===========================================================================
//  QUERY
// ===========================================================================

/**
 * Read the filters off the query string, merge the page's presets and run the
 * listing query.
 *
 * $config keys: base, per_page, default_sort, sorts, title, subtitle,
 *               breadcrumbs, banner, logo, description, pills, clear_url,
 *               empty (callable), toolbar_note.
 *
 * @return array{config:array,filters:array,items:array,pagination:array,view:string,options:array,sorts:array}
 */
function product_listing_query(array $config = []): array
{
    $base  = $config['base'] ?? [];
    $sorts = $config['sorts'] ?? PRODUCT_SORT_OPTIONS;

    $filters = [];

    foreach (['q', 'category', 'brand', 'tag'] as $key) {
        $value = trim((string) input($key, ''));
        if ($value !== '') {
            $filters[$key] = $value;
        }
    }

    foreach (['category_ids', 'brand_ids', 'attribute_values'] as $key) {
        $ids = product_listing_ids($key);
        if ($ids !== []) {
            $filters[$key] = $ids;
        }
    }

    foreach (['min_price', 'max_price', 'rating', 'discount'] as $key) {
        $value = input($key, null);
        if (is_numeric($value) && (float) $value >= 0) {
            $filters[$key] = (float) $value;
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

    // The page's presets win, except where the visitor has narrowed the same
    // facet further with the sidebar (a 40%-off pick beats the deals page 10%).
    foreach ($base as $key => $value) {
        if (isset($filters[$key]) && in_array($key, PRODUCT_LISTING_SOFT_PRESETS, true)) {
            continue;
        }
        $filters[$key] = $value;
    }

    $sort = (string) input('sort', '');
    $filters['sort'] = isset($sorts[$sort]) ? $sort : (string) ($config['default_sort'] ?? 'popularity');
    $filters['page'] = max(1, input_int('page', 1));

    if (!empty($config['per_page'])) {
        $filters['per_page'] = (int) $config['per_page'];
    }

    $result = query_products($filters);

    // A facet combination is not a page worth indexing; the clean listing is.
    $chosen = array_diff(array_keys($filters), array_keys($base), ['sort', 'page', 'per_page']);

    return [
        'config'     => $config,
        'filters'    => $filters,
        'items'      => $result['items'],
        'pagination' => $result['pagination'],
        'view'       => input('view', 'grid') === 'list' ? 'list' : 'grid',
        'options'    => shop_filter_options(),
        'sorts'      => $sorts,
        'robots'     => $chosen === [] ? null : 'noindex, follow',
    ];
}

/**
 * Ids from a repeated query parameter.
 * The forms post `name[]`; SIK.apiRequest flattens arrays into "1,2,3", so a
 * comma-separated string has to be accepted too.
 */
function product_listing_ids(string $key): array
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

/**
 * Product counts per brand or category, scoped to the page's presets so the
 * sidebar never offers a filter that leads nowhere.
 *
 * @return array<int,int> id => count
 */
function product_listing_facet_counts(string $column, array $base): array
{
    if (!in_array($column, ['brand_id', 'category_id'], true)) {
        return [];
    }

    static $cache = [];
    $cacheKey = $column . '|' . md5(serialize($base));
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $where = [product_visible_sql()];
    $params = [];

    if (!empty($base['q'])) {
        $where[] = '(p.`name` LIKE :q1 OR p.`sku` LIKE :q2 OR p.`short_description` LIKE :q3
                     OR b.`name` LIKE :q4 OR c.`name` LIKE :q5 OR p.`model_number` LIKE :q6)';
        $like = '%' . $base['q'] . '%';
        $params += ['q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like, 'q5' => $like, 'q6' => $like];
    }

    if (!empty($base['category'])) {
        $category = get_category_by_slug((string) $base['category']);
        $ids = $category === null ? [-1] : category_with_descendants((int) $category['id']);
        [$placeholders, $catParams] = Database::inPlaceholders($ids, 'fcat');
        $where[] = 'p.`category_id` IN (' . $placeholders . ')';
        $params += $catParams;
    }

    if (!empty($base['brand'])) {
        $where[] = 'b.`slug` = :fbrand';
        $params['fbrand'] = (string) $base['brand'];
    }

    foreach ([
        'featured' => 'is_featured',
        'new'      => 'is_new_arrival',
        'best'     => 'is_best_seller',
        'trending' => 'is_trending',
    ] as $key => $flagColumn) {
        if (!empty($base[$key])) {
            $where[] = 'p.`' . $flagColumn . '` = 1';
        }
    }

    if (!empty($base['discount']) && is_numeric($base['discount'])) {
        $where[] = '(p.`sale_price` IS NOT NULL AND p.`sale_price` > 0 AND p.`price` > 0
                     AND ((p.`price` - p.`sale_price`) / p.`price`) * 100 >= :fdiscount)';
        $params['fdiscount'] = (float) $base['discount'];
    }

    if (!empty($base['deal'])) {
        $where[] = "p.`id` IN (
            SELECT dp.`product_id` FROM `deal_products` dp
            INNER JOIN `deals` d ON d.`id` = dp.`deal_id`
            WHERE d.`status` = 'active' AND d.`start_time` <= NOW() AND d.`end_time` >= NOW()
        )";
    }

    if (!empty($base['flash'])) {
        $sale = get_active_flash_sale();
        if ($sale === null) {
            $where[] = '1 = 0';
        } else {
            $where[] = 'p.`id` IN (SELECT `product_id` FROM `flash_sale_products` WHERE `flash_sale_id` = :ffsid)';
            $params['ffsid'] = (int) $sale['id'];
        }
    }

    $rows = Database::fetchPairs(
        'SELECT p.`' . $column . '`, COUNT(*)
         FROM `products` p
         LEFT JOIN `brands` b ON b.`id` = p.`brand_id`
         LEFT JOIN `categories` c ON c.`id` = p.`category_id`
         WHERE ' . implode(' AND ', $where) . '
         GROUP BY p.`' . $column . '`',
        $params
    );

    $counts = [];
    foreach ($rows as $id => $count) {
        $counts[(int) $id] = (int) $count;
    }

    return $cache[$cacheKey] = $counts;
}

// ===========================================================================
//  RENDER
// ===========================================================================

/** The whole listing: header, filters, toolbar, grid, pager and drawer. */
function product_listing_render(array $listing): void
{
    $config     = $listing['config'];
    $filters    = $listing['filters'];
    $pagination = $listing['pagination'];
    $view       = $listing['view'];
    $action     = product_listing_action();
    ?>
    <div class="sik-container sik-listing">
        <?php product_listing_header($config); ?>
    </div>

    <div class="sik-container sik-listing__body">
        <div class="sik-listing__layout">

            <aside class="sik-listing__aside" aria-label="Filters">
                <form id="sikFilterForm" data-filter-form method="get" action="<?= e($action) ?>">
                    <?php product_listing_hidden_fields($listing); ?>
                    <div class="sik-filters">
                        <div class="sik-filters__head">
                            <span class="sik-filters__title"><?= icon('sliders', 'w-4 h-4') ?> Filters</span>
                            <a class="sik-filters__clear" href="<?= e(product_listing_clear_url($config)) ?>" data-filter-clear>Clear all</a>
                        </div>
                        <?php product_listing_groups($listing, 'd'); ?>
                    </div>
                    <noscript>
                        <button type="submit" class="sik-btn sik-btn--primary sik-btn--block">Apply filters</button>
                    </noscript>
                </form>
            </aside>

            <div id="sikResults" aria-labelledby="sikResultsHeading">
                <?php /* The product cards are h3; without this the results jump
                         from the page h1 straight to h3. Visually hidden because
                         the page title already says what these are. */ ?>
                <h2 class="sik-sr" id="sikResultsHeading">Products</h2>

                <div class="sik-toolbar">
                    <div class="sik-toolbar__meta">
                        <a class="sik-btn sik-btn--outline sik-btn--sm sik-filtertoggle" href="#sikFilterDrawer"
                           data-open-filters aria-controls="sikFilterDrawer">
                            <?= icon('sliders', 'w-4 h-4') ?> Filters
                        </a>
                        <span>
                            <b data-result-count><?= (int) $pagination['total'] ?></b>
                            <?= $pagination['total'] === 1 ? 'product' : 'products' ?>
                            <?php if (!empty($config['toolbar_note'])): ?>
                                &middot; <?= e((string) $config['toolbar_note']) ?>
                            <?php endif; ?>
                        </span>
                    </div>

                    <div class="sik-toolbar__controls">
                        <label class="sik-sr" for="sikSortSelect">Sort products by</label>
                        <select class="sik-select sik-toolbar__sort" id="sikSortSelect" name="sort" data-sort form="sikFilterForm">
                            <?php foreach ($listing['sorts'] as $value => $label): ?>
                                <option value="<?= e($value) ?>" <?= $filters['sort'] === $value ? 'selected' : '' ?>>
                                    <?= e($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <noscript>
                            <button type="submit" class="sik-btn sik-btn--outline sik-btn--sm" form="sikFilterForm">Go</button>
                        </noscript>

                        <div class="sik-viewtoggle" role="group" aria-label="Result layout">
                            <a href="<?= e(url_with(['view' => null, 'page' => null])) ?>"
                               class="<?= $view === 'grid' ? 'is-active' : '' ?>" data-view-mode="grid"
                               aria-label="Grid view"><?= icon('grid', 'w-4 h-4') ?></a>
                            <a href="<?= e(url_with(['view' => 'list', 'page' => null])) ?>"
                               class="<?= $view === 'list' ? 'is-active' : '' ?>" data-view-mode="list"
                               aria-label="List view"><?= icon('list', 'w-4 h-4') ?></a>
                        </div>
                    </div>
                </div>

                <div class="sik-active-filters" data-active-filters><?= product_listing_chips_html($filters) ?></div>

                <div class="<?= $view === 'list' ? 'sik-list' : 'sik-grid' ?>"
                     style="--cols-desktop:3;--cols-tablet:3;--cols-mobile:2" data-product-grid>
                    <?php if ($listing['items'] === []): ?>
                        <?php product_listing_empty($config); ?>
                    <?php else: ?>
                        <?php foreach ($listing['items'] as $product): ?>
                            <?= product_card($product, $view === 'list' ? 'horizontal' : 'standard') ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <?php // Scripted, so rendered hidden: products.js shows it when there
                      // is a next page. It appends that page under the grid; the
                      // numbered pager below stays the no-JS and deep-link path. ?>
                <div class="sik-loadmore" data-load-more hidden>
                    <button type="button" class="sik-btn sik-btn--outline" data-load-more-btn>
                        <span class="sik-btn__label">Show more products</span>
                    </button>
                </div>

                <?php // The paging facts travel with the pager, so the script can
                      // size a skeleton to the page it is about to fetch. ?>
                <div data-pagination
                     data-current="<?= (int) $pagination['current'] ?>"
                     data-last="<?= (int) $pagination['last'] ?>"
                     data-total="<?= (int) $pagination['total'] ?>"
                     data-per-page="<?= (int) $pagination['per_page'] ?>"
                     data-from="<?= (int) $pagination['from'] ?>"
                     data-to="<?= (int) $pagination['to'] ?>">
                    <?= product_listing_pager_html($pagination) ?>
                </div>

                <?php $showRange = $pagination['total'] > 0 && (int) $pagination['last'] > 1; ?>
                <p class="sik-listing__count" data-listing-range aria-live="polite" <?= $showRange ? '' : 'hidden' ?>>
                    <?php if ($showRange): ?>
                        Showing <?= (int) $pagination['from'] ?>&ndash;<?= (int) $pagination['to'] ?>
                        of <?= (int) $pagination['total'] ?>
                    <?php endif; ?>
                </p>
            </div>
        </div>
    </div>

    <!-- Mobile filters. Same fields as the sidebar, rendered from the same code. -->
    <aside class="sik-drawer sik-drawer--left" id="sikFilterDrawer" aria-hidden="true" role="dialog" aria-label="Filters">
        <div class="sik-drawer__head">
            <span class="sik-drawer__title">Filters</span>
            <a class="sik-iconbtn" href="#sikResults" data-close-drawer aria-label="Close filters">
                <?= icon('close', 'w-4 h-4') ?>
            </a>
        </div>

        <form class="sik-drawer__body sik-filterdrawer__body" id="sikFilterFormMobile" data-filter-form method="get" action="<?= e($action) ?>">
            <?php product_listing_hidden_fields($listing, 'm'); ?>
            <?php product_listing_groups($listing, 'm'); ?>
        </form>

        <div class="sik-drawer__foot sik-filterdrawer__foot">
            <a class="sik-btn sik-btn--outline" href="<?= e(product_listing_clear_url($config)) ?>" data-filter-clear>
                Clear all
            </a>
            <button type="submit" class="sik-btn sik-btn--primary" form="sikFilterFormMobile">
                <span class="sik-btn__label">Show results</span>
            </button>
        </div>
    </aside>

    <?php product_listing_assets(); ?>
    <?php
}

/** Breadcrumbs, page title and any banner / logo / child links above the grid. */
function product_listing_header(array $config): void
{
    if (!empty($config['breadcrumbs'])) {
        echo breadcrumbs($config['breadcrumbs']);
    }

    $description = trim((string) ($config['description'] ?? ''));
    ?>
    <header class="sik-listing__head">
        <?php if (!empty($config['banner'])): ?>
            <img class="sik-listing__banner" src="<?= e(img_url((string) $config['banner'])) ?>" alt=""
                 width="1200" height="240" fetchpriority="high">
        <?php endif; ?>

        <div class="sik-listing__headrow">
            <?php if (!empty($config['logo'])): ?>
                <span class="sik-listing__logo">
                    <img src="<?= e(img_url((string) $config['logo'])) ?>" alt="<?= e((string) ($config['title'] ?? '')) ?>"
                         width="120" height="60">
                </span>
            <?php endif; ?>

            <div class="sik-listing__heading">
                <h1 class="sik-listing__title"><?= e((string) ($config['title'] ?? 'Products')) ?></h1>
                <?php if (!empty($config['subtitle'])): ?>
                    <p class="sik-listing__sub"><?= e((string) $config['subtitle']) ?></p>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($description !== ''): ?>
            <div class="sik-prose sik-listing__desc"><?= sanitize_html($description) ?></div>
        <?php endif; ?>

        <?php if (!empty($config['pills'])): ?>
            <nav class="sik-pills sik-listing__pills" aria-label="<?= e((string) ($config['pills_label'] ?? 'Sub categories')) ?>">
                <?php foreach ($config['pills'] as $pill): ?>
                    <a class="sik-pill" href="<?= e((string) $pill['url']) ?>">
                        <?= e((string) $pill['label']) ?>
                        <?php if (isset($pill['count'])): ?>
                            <span class="sik-pill__count"><?= (int) $pill['count'] ?></span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </nav>
        <?php endif; ?>
    </header>
    <?php
}

/** Where both forms submit: the current path, so pretty URLs keep working. */
function product_listing_action(): string
{
    return (string) strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
}

/** "Clear all" target - keeps the page context, drops every chosen filter. */
function product_listing_clear_url(array $config): string
{
    if (!empty($config['clear_url'])) {
        return (string) $config['clear_url'];
    }

    $path = product_listing_action();
    // The slug travels in the path on pretty URLs but in the query string when
    // Apache rewriting is off, so it has to survive a reset.
    $slug = isset($_GET['slug']) && is_string($_GET['slug']) ? trim($_GET['slug']) : '';

    return $slug === '' ? $path : $path . '?slug=' . rawurlencode($slug);
}

/**
 * Presets and page context that every submit must carry.
 * Only hard presets go in: the soft ones are visible controls further down and
 * would otherwise be submitted twice.
 */
function product_listing_hidden_fields(array $listing, string $scope = 'd'): void
{
    $base = $listing['config']['base'] ?? [];

    // The sort control lives in the toolbar and belongs to the sidebar form, so
    // the drawer needs its own copy or applying filters there would reset it.
    if ($scope === 'm') {
        echo '<input type="hidden" name="sort" value="' . e((string) $listing['filters']['sort']) . '">';
    }

    foreach (PRODUCT_LISTING_HARD_PRESETS as $key) {
        if (!isset($base[$key]) || $base[$key] === '' || $base[$key] === null) {
            continue;
        }
        echo '<input type="hidden" name="' . e($key) . '" value="' . e((string) $base[$key]) . '">';
    }

    if (isset($_GET['slug']) && is_string($_GET['slug']) && trim($_GET['slug']) !== '') {
        echo '<input type="hidden" name="slug" value="' . e(trim($_GET['slug'])) . '">';
    }

    if ($listing['view'] === 'list') {
        echo '<input type="hidden" name="view" value="list">';
    }
}

// ---------------------------------------------------------------------------
//  Filter groups (rendered twice: sidebar + drawer)
// ---------------------------------------------------------------------------

/** One collapsible group wrapper. */
function product_listing_group_open(string $title, string $id, bool $collapsed = false): void
{
    ?>
    <div class="sik-filter <?= $collapsed ? 'is-collapsed' : '' ?>">
        <button type="button" class="sik-filter__head" aria-expanded="<?= $collapsed ? 'false' : 'true' ?>"
                aria-controls="<?= e($id) ?>">
            <?= e($title) ?><?= icon('chevron-down', 'w-4 h-4') ?>
        </button>
        <div class="sik-filter__body" id="<?= e($id) ?>">
    <?php
}

function product_listing_group_close(): void
{
    echo '</div></div>';
}

/** Every filter group. $scope keeps the input ids unique between the two copies. */
function product_listing_groups(array $listing, string $scope): void
{
    $filters = $listing['filters'];
    $options = $listing['options'];
    $base    = $listing['config']['base'] ?? [];

    product_listing_categories_group($listing, $scope, $base);
    product_listing_brands_group($listing, $scope, $base);
    product_listing_price_group($filters, $options, $scope);
    product_listing_rating_group($filters, $scope);
    product_listing_discount_group($filters, $scope);
    product_listing_availability_group($filters, $scope);

    foreach ($options['attributes'] as $attribute) {
        product_listing_attribute_group($attribute, $filters, $scope);
    }
}

function product_listing_categories_group(array $listing, string $scope, array $base): void
{
    // A category page already scopes the listing; offering the whole tree again
    // would only take the visitor away from it.
    if (!empty($base['category'])) {
        return;
    }

    $selected = $listing['filters']['category_ids'] ?? [];
    $counts   = product_listing_facet_counts('category_id', $base);
    $tree     = $listing['options']['categories'];

    /** Roll a branch's own count up from its descendants. */
    $branchCount = static function (array $category) use ($counts): int {
        $total = 0;
        foreach (category_with_descendants((int) $category['id']) as $id) {
            $total += $counts[$id] ?? 0;
        }
        return $total;
    };

    $rows = [];
    foreach ($tree as $category) {
        $id = (int) $category['id'];
        $count = $branchCount($category);
        $checked = in_array($id, $selected, true);
        if ($count === 0 && !$checked) {
            continue;
        }

        $children = [];
        foreach ($category['children'] ?? [] as $child) {
            $childId = (int) $child['id'];
            $childCount = $branchCount($child);
            $childChecked = in_array($childId, $selected, true);
            if ($childCount === 0 && !$childChecked) {
                continue;
            }
            $children[] = ['row' => $child, 'count' => $childCount, 'checked' => $childChecked];
        }

        $rows[] = ['row' => $category, 'count' => $count, 'checked' => $checked, 'children' => $children];
    }

    if ($rows === []) {
        return;
    }

    product_listing_group_open('Category', 'sikFilterCategory' . $scope);
    foreach ($rows as $entry) {
        product_listing_checkbox(
            'category_ids[]',
            (string) $entry['row']['id'],
            (string) $entry['row']['name'],
            $entry['checked'],
            $entry['count'],
            'cat' . $scope . $entry['row']['id']
        );
        foreach ($entry['children'] as $child) {
            product_listing_checkbox(
                'category_ids[]',
                (string) $child['row']['id'],
                (string) $child['row']['name'],
                $child['checked'],
                $child['count'],
                'cat' . $scope . $child['row']['id'],
                true
            );
        }
    }
    product_listing_group_close();
}

function product_listing_brands_group(array $listing, string $scope, array $base): void
{
    if (!empty($base['brand'])) {
        return;
    }

    $selected = $listing['filters']['brand_ids'] ?? [];
    $counts   = product_listing_facet_counts('brand_id', $base);

    $rows = [];
    foreach ($listing['options']['brands'] as $brand) {
        $id = (int) $brand['id'];
        $count = $counts[$id] ?? 0;
        $checked = in_array($id, $selected, true);
        if ($count === 0 && !$checked) {
            continue;
        }
        $rows[] = ['brand' => $brand, 'count' => $count, 'checked' => $checked];
    }

    if ($rows === []) {
        return;
    }

    product_listing_group_open('Brand', 'sikFilterBrand' . $scope);
    foreach ($rows as $entry) {
        product_listing_checkbox(
            'brand_ids[]',
            (string) $entry['brand']['id'],
            (string) $entry['brand']['name'],
            $entry['checked'],
            $entry['count'],
            'brand' . $scope . $entry['brand']['id']
        );
    }
    product_listing_group_close();
}

function product_listing_price_group(array $filters, array $options, string $scope): void
{
    $floor = (int) floor((float) $options['min_price']);
    $ceil  = (int) ceil((float) $options['max_price']);
    if ($ceil <= $floor) {
        $ceil = $floor + 1000;
    }
    $step = max(100, (int) round(($ceil - $floor) / 100 / 100) * 100);

    $min = isset($filters['min_price']) ? (string) (int) $filters['min_price'] : '';
    $max = isset($filters['max_price']) ? (string) (int) $filters['max_price'] : '';

    product_listing_group_open('Price', 'sikFilterPrice' . $scope);
    ?>
    <div class="sik-price-inputs">
        <label class="sik-sr" for="minPrice<?= e($scope) ?>">Minimum price</label>
        <input class="sik-input" id="minPrice<?= e($scope) ?>" type="number" name="min_price" inputmode="numeric"
               min="<?= $floor ?>" max="<?= $ceil ?>" step="1" placeholder="<?= e(money($floor, false)) ?>"
               value="<?= e($min) ?>">
        <span aria-hidden="true">&ndash;</span>
        <label class="sik-sr" for="maxPrice<?= e($scope) ?>">Maximum price</label>
        <input class="sik-input" id="maxPrice<?= e($scope) ?>" type="number" name="max_price" inputmode="numeric"
               min="<?= $floor ?>" max="<?= $ceil ?>" step="1" placeholder="<?= e(money($ceil, false)) ?>"
               value="<?= e($max) ?>">
    </div>

    <div class="sik-range">
        <label class="sik-sr" for="priceRange<?= e($scope) ?>">Maximum price slider</label>
        <input type="range" id="priceRange<?= e($scope) ?>" min="<?= $floor ?>" max="<?= $ceil ?>" step="<?= $step ?>"
               value="<?= e($max === '' ? (string) $ceil : $max) ?>"
               data-price-range="maxPrice<?= e($scope) ?>">
    </div>
    <p class="sik-filter__note">Store range <?= e(money($floor)) ?> &ndash; <?= e(money($ceil)) ?></p>
    <?php
    product_listing_group_close();
}

function product_listing_rating_group(array $filters, string $scope): void
{
    $current = isset($filters['rating']) ? (string) (int) $filters['rating'] : '';

    product_listing_group_open('Customer rating', 'sikFilterRating' . $scope);
    product_listing_radio('rating', '', 'Any rating', $current === '', 'rating' . $scope . 'any');
    foreach ([4, 3, 2, 1] as $stars) {
        $id = 'rating' . $scope . $stars;
        ?>
        <label class="sik-filter__option" for="<?= e($id) ?>">
            <input type="radio" id="<?= e($id) ?>" name="rating" value="<?= $stars ?>"
                   <?= $current === (string) $stars ? 'checked' : '' ?>>
            <span class="sik-filter__stars">
                <?= rating_stars((float) $stars, 'w-3.5 h-3.5') ?> &amp; above
            </span>
        </label>
        <?php
    }
    product_listing_group_close();
}

function product_listing_discount_group(array $filters, string $scope): void
{
    $current = isset($filters['discount']) ? (string) (int) $filters['discount'] : '';

    product_listing_group_open('Discount', 'sikFilterDiscount' . $scope);
    product_listing_radio('discount', '', 'Any discount', $current === '', 'disc' . $scope . 'any');
    foreach ([10, 25, 40, 50, 70] as $percent) {
        product_listing_radio(
            'discount',
            (string) $percent,
            $percent . '% or more',
            $current === (string) $percent,
            'disc' . $scope . $percent
        );
    }
    product_listing_group_close();
}

function product_listing_availability_group(array $filters, string $scope): void
{
    $current = (string) ($filters['availability'] ?? '');

    product_listing_group_open('Availability', 'sikFilterStock' . $scope);
    product_listing_radio('availability', '', 'All products', $current === '', 'avail' . $scope . 'any');
    product_listing_radio('availability', 'in_stock', 'In stock only', $current === 'in_stock', 'avail' . $scope . 'in');
    product_listing_radio('availability', 'out_of_stock', 'Out of stock', $current === 'out_of_stock', 'avail' . $scope . 'out');
    product_listing_group_close();
}

function product_listing_attribute_group(array $attribute, array $filters, string $scope): void
{
    if (empty($attribute['values'])) {
        return;
    }

    $selected = array_map('intval', $filters['attribute_values'] ?? []);
    $id = 'sikFilterAttr' . (int) $attribute['id'] . $scope;

    product_listing_group_open((string) $attribute['name'], $id, true);

    if ($attribute['type'] === 'color') {
        echo '<div class="sik-swatches">';
        foreach ($attribute['values'] as $value) {
            $valueId = (int) $value['id'];
            $checked = in_array($valueId, $selected, true);
            $swatchId = 'attr' . $scope . $valueId;
            ?>
            <label class="sik-swatch <?= $checked ? 'is-active' : '' ?>" for="<?= e($swatchId) ?>"
                   style="background:<?= e($value['color_code'] ?: '#E5E7EB') ?>" title="<?= e($value['value']) ?>">
                <input type="checkbox" id="<?= e($swatchId) ?>" name="attribute_values[]" value="<?= $valueId ?>"
                       <?= $checked ? 'checked' : '' ?>>
                <span class="sik-sr"><?= e($value['value']) ?></span>
                <?= icon('check', 'w-3.5 h-3.5') ?>
            </label>
            <?php
        }
        echo '</div>';
    } else {
        foreach ($attribute['values'] as $value) {
            $valueId = (int) $value['id'];
            product_listing_checkbox(
                'attribute_values[]',
                (string) $valueId,
                (string) $value['value'],
                in_array($valueId, $selected, true),
                null,
                'attr' . $scope . $valueId
            );
        }
    }

    product_listing_group_close();
}

function product_listing_checkbox(
    string $name,
    string $value,
    string $label,
    bool $checked,
    ?int $count,
    string $id,
    bool $nested = false
): void {
    ?>
    <label class="sik-filter__option<?= $nested ? ' sik-filter__option--nested' : '' ?>" for="<?= e($id) ?>">
        <input type="checkbox" id="<?= e($id) ?>" name="<?= e($name) ?>" value="<?= e($value) ?>"
               <?= $checked ? 'checked' : '' ?>>
        <span><?= e($label) ?></span>
        <?php if ($count !== null): ?>
            <span class="sik-filter__count"><?= (int) $count ?></span>
        <?php endif; ?>
    </label>
    <?php
}

function product_listing_radio(string $name, string $value, string $label, bool $checked, string $id): void
{
    ?>
    <label class="sik-filter__option" for="<?= e($id) ?>">
        <input type="radio" id="<?= e($id) ?>" name="<?= e($name) ?>" value="<?= e($value) ?>"
               <?= $checked ? 'checked' : '' ?>>
        <span><?= e($label) ?></span>
    </label>
    <?php
}

// ---------------------------------------------------------------------------
//  Chips, pager, empty state
// ---------------------------------------------------------------------------

/**
 * Applied filters as removable chips.
 * Mirrors api/products/list.php so the markup is identical before and after an
 * AJAX refresh.
 */
function product_listing_chip_model(array $filters): array
{
    $chips = [];

    if (!empty($filters['q'])) {
        $chips[] = ['name' => 'q', 'value' => (string) $filters['q'], 'label' => 'Search: ' . $filters['q']];
    }

    $categories = all_categories();

    if (!empty($filters['category'])) {
        $category = get_category_by_slug((string) $filters['category']);
        $chips[] = [
            'name'  => 'category',
            'value' => (string) $filters['category'],
            'label' => (string) ($category['name'] ?? $filters['category']),
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

    if (!empty($filters['brand'])) {
        $brand = get_brand_by_slug((string) $filters['brand']);
        $chips[] = [
            'name'  => 'brand',
            'value' => (string) $filters['brand'],
            'label' => (string) ($brand['name'] ?? $filters['brand']),
        ];
    }
    if (!empty($filters['brand_ids'])) {
        $brandNames = [];
        foreach (all_brands() as $brand) {
            $brandNames[(int) $brand['id']] = (string) $brand['name'];
        }
        foreach ($filters['brand_ids'] as $brandId) {
            if (isset($brandNames[(int) $brandId])) {
                $chips[] = ['name' => 'brand_ids', 'value' => (string) $brandId, 'label' => $brandNames[(int) $brandId]];
            }
        }
    }

    if (!empty($filters['tag'])) {
        $tagName = Database::fetchColumn('SELECT `name` FROM `tags` WHERE `slug` = :slug', ['slug' => $filters['tag']]);
        $chips[] = ['name' => 'tag', 'value' => (string) $filters['tag'], 'label' => (string) ($tagName ?? $filters['tag'])];
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
            'label' => $filters['availability'] === 'in_stock' ? 'In stock' : 'Out of stock',
        ];
    }

    foreach ([
        'featured' => 'Featured',
        'new'      => 'New arrivals',
        'best'     => 'Best sellers',
        'trending' => 'Trending',
        'deal'     => 'On deal',
        'flash'    => 'Flash sale',
    ] as $flag => $label) {
        if (!empty($filters[$flag])) {
            $chips[] = ['name' => $flag, 'value' => '1', 'label' => $label];
        }
    }

    return $chips;
}

function product_listing_chips_html(array $filters): string
{
    $chips = product_listing_chip_model($filters);
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

    $html .= '<button type="button" class="sik-chip sik-chip--clear" data-filter-clear>'
        . icon('refresh', 'w-3.5 h-3.5') . ' Clear all</button>';

    return $html . '</div>';
}

/** Pager links keep the whole query string so they work without JavaScript. */
function product_listing_pager_html(array $pagination): string
{
    if ((int) $pagination['last'] <= 1) {
        return '';
    }

    $current = (int) $pagination['current'];
    $last = (int) $pagination['last'];

    $html = '<nav class="sik-pager" aria-label="Product pages">';

    if ($current > 1) {
        $html .= '<a class="sik-pager__link" href="' . e(url_with(['page' => $current - 1])) . '"'
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
        $classes = 'sik-pager__link' . ($page === $current ? ' is-current' : '');
        $html .= '<a class="' . $classes . '" href="' . e(url_with(['page' => $page])) . '"'
            . ' data-page="' . $page . '"'
            . ($page === $current ? ' aria-current="page"' : ' aria-label="Page ' . $page . '"')
            . '>' . $page . '</a>';
    }

    if ($current < $last) {
        $html .= '<a class="sik-pager__link" href="' . e(url_with(['page' => $current + 1])) . '"'
            . ' data-page="' . ($current + 1) . '" rel="next" aria-label="Next page">'
            . icon('chevron-right', 'w-4 h-4') . '</a>';
    } else {
        $html .= '<span class="sik-pager__link is-disabled" aria-hidden="true">' . icon('chevron-right', 'w-4 h-4') . '</span>';
    }

    return $html . '</nav>';
}

/** Empty result. Pages can hand over their own renderer (search does). */
function product_listing_empty(array $config): void
{
    if (isset($config['empty']) && is_callable($config['empty'])) {
        ($config['empty'])();
        return;
    }
    ?>
    <div class="sik-empty" style="grid-column:1/-1">
        <?= icon('search', 'w-14 h-14') ?>
        <p class="sik-empty__title">No products match these filters</p>
        <p class="sik-empty__text">Try removing a filter, widening the price range, or searching for something else.</p>
        <a class="sik-btn sik-btn--primary" href="<?= e(product_listing_clear_url($config)) ?>" data-filter-clear>
            Clear all filters
        </a>
    </div>
    <?php
}

// ---------------------------------------------------------------------------
//  Progressive enhancement
// ---------------------------------------------------------------------------

/**
 * The few rules and behaviours that only exist on listing pages:
 * the list layout products.js switches to, the swatch checkbox, a no-JS way
 * into the filter drawer, and keeping the two filter forms in step.
 */
function product_listing_assets(): void
{
    static $printed = false;
    if ($printed) {
        return;
    }
    $printed = true;
    ?>
    <style>
        label.sik-swatch { display: inline-flex; align-items: center; justify-content: center; }
        .sik-swatch input { position: absolute; opacity: 0; width: 0; height: 0; }
        .sik-swatch svg { opacity: 0; color: #fff; filter: drop-shadow(0 0 1.5px rgba(0,0,0,.55)); }
        .sik-swatch.is-active svg,
        .sik-swatch:has(input:checked) svg { opacity: 1; }
        .sik-swatch:has(input:checked) { box-shadow: inset 0 0 0 2px var(--sik-bg), 0 0 0 2px var(--sik-ink); border-color: transparent; }
        .sik-swatch:has(input:focus-visible) { outline: 2px solid var(--sik-primary); outline-offset: 2px; }
    </style>

    <script>
    (function () {
        var forms = Array.prototype.slice.call(document.querySelectorAll('[data-filter-form]'));

        // The sidebar and the drawer are separate forms so each one submits on
        // its own without JavaScript. Mirror every change between them, in the
        // capture phase, so the AJAX path (which reads the first form) is never
        // one step behind.
        document.addEventListener('change', function (e) {
            var input = e.target;
            if (!input || !input.name || !input.form || !input.form.hasAttribute('data-filter-form')) return;

            forms.forEach(function (form) {
                if (form === input.form) return;
                Array.prototype.forEach.call(form.elements, function (twin) {
                    if (twin.name !== input.name) return;
                    if (twin.type === 'checkbox' || twin.type === 'radio') {
                        if (twin.value === input.value) twin.checked = input.checked;
                    } else {
                        twin.value = input.value;
                    }
                });
            });
        }, true);

        // Collapsible groups.
        document.addEventListener('click', function (e) {
            var head = e.target.closest && e.target.closest('.sik-filter__head');
            if (!head) return;
            var collapsed = head.parentNode.classList.toggle('is-collapsed');
            head.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        });

        // The slider drives the max-price box; the box is what gets submitted.
        Array.prototype.forEach.call(document.querySelectorAll('[data-price-range]'), function (range) {
            var box = document.getElementById(range.dataset.priceRange);
            if (!box) return;
            range.addEventListener('input', function () { box.value = range.value; });
            range.addEventListener('change', function () {
                box.value = range.value;
                box.dispatchEvent(new Event('change', { bubbles: true }));
            });
            box.addEventListener('input', function () { if (box.value !== '') range.value = box.value; });
        });

        // Applying from the drawer should also close it.
        document.addEventListener('submit', function (e) {
            if (e.target.id === 'sikFilterFormMobile' && window.SIK && SIK.closeDrawer) {
                SIK.closeDrawer('sikFilterDrawer');
            }
        }, true);
    })();
    </script>
    <?php
}
