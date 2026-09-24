<?php
/**
 * ShopInnKart - Sitemap index, section sitemaps, robots.txt and their health.
 *
 * WHY THIS IS A LIBRARY AND NOT JUST sitemap.php
 * ----------------------------------------------
 * The old /sitemap.xml was one flat file built in the page that served it, so
 * three things were impossible: telling a search engine which part of the
 * catalogue changed, listing more than one file's worth of URLs (the format
 * caps a sitemap at 50,000 URLs / 50 MB), and checking any of it from the
 * admin without fetching the whole thing over HTTP.
 *
 * Everything is built here instead, in process:
 *
 *     /sitemap.xml                  the index - one line per section file
 *     /sitemap-products-1.xml       products, 50,000 at a time
 *     /sitemap-categories-1.xml     categories
 *     /sitemap-brands-1.xml         brands
 *     /sitemap-pages-1.xml          the homepage, fixed routes and CMS pages
 *     /sitemap-blog-1.xml           published posts
 *     /sitemap-images-1.xml         product images, attached to their product
 *
 * The pretty names need the rewrite rule in .htaccess. Where there is no
 * rewriting at all - the PHP built-in server used for tests - the index prints
 * the sitemap.php?type=...&page=... form of the same URL, so what the index
 * advertises is always something this server can actually serve.
 *
 * WHAT IS LEFT OUT, AND WHY
 * -------------------------
 * A sitemap is a list of pages the store WANTS indexed, so anything carrying
 * noindex is excluded - listing a page you have told crawlers to skip is a
 * contradiction, and Search Console reports it as one. Drafts, inactive rows
 * and unpublished posts never appear. Out-of-stock products stay in by
 * default (the page is still the right answer to a search for it) and can be
 * excluded with one switch for stores that treat sold out as gone.
 */

declare(strict_types=1);

/** Format limits. Both are from sitemaps.org and neither is ours to raise. */
const SITEMAP_MAX_URLS  = 50000;
const SITEMAP_MAX_BYTES = 50 * 1024 * 1024;

/** Images per URL entry. Google reads the first 1,000 and ignores the rest. */
const SITEMAP_MAX_IMAGES = 1000;

/** How long a health check may wait for one of our own URLs, in seconds. */
const SITEMAP_FETCH_TIMEOUT = 0.5;

/** The whole remote half of a health check, in seconds. Never exceeded. */
const SITEMAP_HEALTH_BUDGET = 8.0;

// ===========================================================================
//  SECTIONS
// ===========================================================================

/**
 * Every section the index can list.
 *
 * `setting` is the switch on Admin > SEO > Sitemap; a section switched off is
 * not built, not listed and not counted.
 *
 * @return array<string,array{label:string,setting:string,priority:string,changefreq:string}>
 */
function sitemap_sections(): array
{
    return [
        'products' => [
            'label' => 'Products', 'setting' => 'sitemap_include_products',
            'priority' => '0.8', 'changefreq' => 'weekly',
        ],
        'categories' => [
            'label' => 'Categories', 'setting' => 'sitemap_include_categories',
            'priority' => '0.8', 'changefreq' => 'weekly',
        ],
        'brands' => [
            'label' => 'Brands', 'setting' => 'sitemap_include_brands',
            'priority' => '0.6', 'changefreq' => 'weekly',
        ],
        'pages' => [
            'label' => 'Pages', 'setting' => 'sitemap_include_pages',
            'priority' => '0.6', 'changefreq' => 'monthly',
        ],
        'blog' => [
            'label' => 'Blog', 'setting' => 'sitemap_include_blog',
            'priority' => '0.6', 'changefreq' => 'monthly',
        ],
        'images' => [
            'label' => 'Images', 'setting' => 'sitemap_include_images',
            'priority' => '0.5', 'changefreq' => 'monthly',
        ],
    ];
}

/** Is this section switched on? Unknown sections are off, not missing. */
function sitemap_section_enabled(string $key): bool
{
    $section = sitemap_sections()[$key] ?? null;
    return $section !== null && setting_bool($section['setting'], true);
}

/**
 * URLs per section file.
 *
 * Capped at the format's own 50,000. A store with a huge catalogue can lower
 * it so each file downloads quickly; nothing can raise it past the limit,
 * because a file over it is rejected whole.
 */
function sitemap_chunk_size(): int
{
    $configured = (int) setting('sitemap_urls_per_file', (string) SITEMAP_MAX_URLS);

    // Zero or negative is "not set", not "no URLs": a missing or mangled
    // setting must never produce an empty sitemap.
    if ($configured < 1) {
        $configured = SITEMAP_MAX_URLS;
    }

    return min($configured, SITEMAP_MAX_URLS);
}

/** Does a rewrite engine exist to serve /sitemap-products-1.xml? */
function sitemap_pretty_urls(): bool
{
    // The PHP built-in server reads no .htaccess and has no rewrite engine, so
    // a pretty child URL would 404 there. Anything else is Apache/LiteSpeed
    // with the rule in .htaccess.
    return PHP_SAPI !== 'cli-server';
}

/** Absolute URL of the sitemap index. */
function sitemap_index_url(): string
{
    return sitemap_pretty_urls() ? url('sitemap.xml') : url('sitemap.php');
}

/**
 * Absolute URL of robots.txt.
 *
 * /robots.txt is a rewrite onto robots.php, so where there is no rewrite
 * engine the real script name is the only address that answers. Crawlers only
 * ever ask for /robots.txt, which is what production serves; this is for the
 * admin's own "Open" link and its health checks.
 */
function robots_txt_url(): string
{
    return sitemap_pretty_urls() ? url('robots.txt') : url('robots.php');
}

/** Absolute URL of one section file. */
function sitemap_child_url(string $key, int $page = 1): string
{
    $page = max(1, $page);

    if (sitemap_pretty_urls()) {
        return url('sitemap-' . $key . '-' . $page . '.xml');
    }

    return url('sitemap.php') . '?type=' . rawurlencode($key) . '&page=' . $page;
}

// ===========================================================================
//  WHAT GOES IN
// ===========================================================================

/** SQL that keeps rows the operator has told crawlers to skip out of the file. */
function sitemap_indexable_sql(string $alias = ''): string
{
    $column = ($alias === '' ? '' : $alias . '.') . '`robots`';
    return "($column IS NULL OR $column NOT LIKE '%noindex%')";
}

/**
 * SQL for "this product has at least one picture worth listing".
 *
 * Shared by the builder and by the count deliberately. They used to spell it
 * out separately as `main_image IS NOT NULL`, which does not match what the
 * builder then does in PHP: a path is skipped once trimmed to nothing, so a
 * row storing '' (the column is nullable, but a form post saves an empty
 * string) was COUNTED and then produced no entry. Enough of those on one page
 * and the index advertises a file that builds to nothing and 404s.
 *
 * One function, so the two can no longer drift.
 */
function sitemap_has_image_sql(string $alias = 'p'): string
{
    return "(NULLIF(TRIM($alias.`main_image`), '') IS NOT NULL"
        . " OR EXISTS (SELECT 1 FROM `product_images` i"
        . " WHERE i.`product_id` = $alias.`id` AND NULLIF(TRIM(i.`image`), '') IS NOT NULL))";
}

/**
 * One page of one section, as sitemap entries.
 *
 * Each entry is ['loc','lastmod','changefreq','priority','images'].
 * Paging is done in SQL so a 200,000-product catalogue never loads at once.
 *
 * @return array<int,array<string,mixed>>
 */
function sitemap_section_entries(string $key, int $page = 1): array
{
    $chunk   = sitemap_chunk_size();
    $offset  = (max(1, $page) - 1) * $chunk;
    $section = sitemap_sections()[$key] ?? null;
    if ($section === null) {
        return [];
    }

    // LIMIT/OFFSET are cast integers rather than bound parameters: with
    // emulated prepares off, PDO sends a bound LIMIT as a quoted string and
    // MariaDB rejects it.
    $window = ' LIMIT ' . (int) $chunk . ' OFFSET ' . (int) $offset;

    $entry = static function (string $loc, $lastmod, string $changefreq, string $priority, array $images = []): array {
        $timestamp = is_string($lastmod) && $lastmod !== '' ? strtotime($lastmod) : false;
        return [
            'loc'        => $loc,
            'lastmod'    => $timestamp === false ? null : date('c', $timestamp),
            'changefreq' => $changefreq,
            'priority'   => $priority,
            'images'     => $images,
        ];
    };

    $entries = [];

    switch ($key) {
        case 'products':
            $where = 'WHERE ' . product_visible_sql('p') . ' AND ' . sitemap_indexable_sql('p');
            if (setting_bool('sitemap_exclude_oos', false)) {
                $where .= ' AND p.`stock` > 0';
            }
            foreach (Database::fetchAll(
                "SELECT p.`slug`, p.`updated_at` FROM `products` p $where ORDER BY p.`id`$window"
            ) as $row) {
                $entries[] = $entry(product_url((string) $row['slug']), $row['updated_at'], 'weekly', '0.8');
            }
            break;

        case 'categories':
            foreach (Database::fetchAll(
                "SELECT `slug`, `updated_at` FROM `categories`
                  WHERE `status` = 'active' AND " . sitemap_indexable_sql() . "
                  ORDER BY `id`$window"
            ) as $row) {
                $entries[] = $entry(category_url((string) $row['slug']), $row['updated_at'], 'weekly', '0.8');
            }
            break;

        case 'brands':
            foreach (Database::fetchAll(
                "SELECT `slug`, `updated_at` FROM `brands`
                  WHERE `status` = 'active' AND " . sitemap_indexable_sql() . "
                  ORDER BY `id`$window"
            ) as $row) {
                $entries[] = $entry(brand_url((string) $row['slug']), $row['updated_at'], 'weekly', '0.6');
            }
            break;

        case 'pages':
            // The homepage and the fixed routes are code, not rows, so they are
            // assembled first and then sliced with the CMS pages behind them.
            // The list is small by definition - a store has tens of pages, not
            // tens of thousands - so slicing in PHP is honest here.
            $all = sitemap_page_entries($entry);
            $entries = array_slice($all, $offset, $chunk);
            break;

        case 'blog':
            foreach (Database::fetchAll(
                'SELECT p.`slug`, p.`updated_at` FROM `blog_posts` p
                  WHERE ' . blog_visible_sql('p') . ' AND ' . sitemap_indexable_sql('p') . "
                  ORDER BY p.`id`$window"
            ) as $row) {
                $entries[] = $entry(blog_url((string) $row['slug']), $row['updated_at'], 'monthly', '0.6');
            }
            break;

        case 'images':
            // One entry per product, carrying that product's images. An image
            // sitemap says "these pictures live on this page", so the <loc> is
            // the product page and never the file.
            $where = 'WHERE ' . product_visible_sql('p') . ' AND ' . sitemap_indexable_sql('p')
                . ' AND ' . sitemap_has_image_sql('p');

            $products = Database::fetchAll(
                "SELECT p.`id`, p.`slug`, p.`name`, p.`main_image`, p.`updated_at`
                   FROM `products` p $where ORDER BY p.`id`$window"
            );
            if ($products === []) {
                break;
            }

            $ids = array_map(static fn (array $p): int => (int) $p['id'], $products);
            [$placeholders, $params] = Database::inPlaceholders($ids);
            $byProduct = [];
            foreach (Database::fetchAll(
                "SELECT `product_id`, `image`, `alt_text` FROM `product_images`
                  WHERE `product_id` IN ($placeholders) ORDER BY `product_id`, `sort_order`, `id`",
                $params
            ) as $image) {
                $byProduct[(int) $image['product_id']][] = $image;
            }

            foreach ($products as $product) {
                $images = [];
                $seen   = [];
                // array_merge, not +: both lists are 0-indexed, so the union
                // operator would keep only the main image and silently drop
                // every gallery row that shared an index with it.
                $candidates = array_merge(
                    [['image' => $product['main_image'], 'alt_text' => $product['name']]],
                    $byProduct[(int) $product['id']] ?? []
                );
                foreach ($candidates as $image) {
                    $path = trim((string) ($image['image'] ?? ''));
                    if ($path === '' || isset($seen[$path]) || count($images) >= SITEMAP_MAX_IMAGES) {
                        continue;
                    }
                    $seen[$path] = true;
                    $images[] = [
                        'loc'   => img_url($path),
                        'title' => trim((string) ($image['alt_text'] ?? '')) ?: (string) $product['name'],
                    ];
                }

                if ($images !== []) {
                    $entries[] = $entry(
                        product_url((string) $product['slug']),
                        $product['updated_at'],
                        'monthly',
                        '0.5',
                        $images
                    );
                }
            }
            break;
    }

    return $entries;
}

/**
 * The homepage, the fixed routes and the CMS pages, in one list.
 *
 * A route is listed only when its PHP file exists (so the sitemap never
 * advertises a 404) and only when Admin > Settings > SEO has not marked that
 * page key noindex - the same rule the database-backed sections follow.
 *
 * @param callable $entry builder from sitemap_section_entries()
 * @return array<int,array<string,mixed>>
 */
function sitemap_page_entries(callable $entry): array
{
    $entries = [];
    $seen    = [];

    $add = static function (string $loc, $lastmod, string $changefreq, string $priority)
        use (&$entries, &$seen, $entry): void {
        if (isset($seen[$loc])) {
            return;
        }
        $seen[$loc] = true;
        $entries[] = $entry($loc, $lastmod, $changefreq, $priority);
    };

    // page_key => robots, for the routes Settings > SEO manages.
    $pageRobots = [];
    try {
        $pageRobots = Database::fetchPairs('SELECT `page_key`, `robots` FROM `seo_settings`');
    } catch (Throwable $e) {
        $pageRobots = [];
    }
    $indexable = static function (string $pageKey) use ($pageRobots): bool {
        return stripos((string) ($pageRobots[$pageKey] ?? ''), 'noindex') === false;
    };

    $routeExists = static fn (string $script): bool => is_file(ROOT_PATH . '/' . $script);

    if ($indexable('home')) {
        $newest = Database::fetchColumn('SELECT MAX(`updated_at`) FROM `products`');
        $add(url(), is_string($newest) ? $newest : null, 'daily', '1.0');
    }

    $routes = [
        'shop'         => ['shop.php', 'daily', '0.9'],
        'deals'        => ['deals.php', 'daily', '0.9'],
        'new-arrivals' => ['new-arrivals.php', 'daily', '0.8'],
        'best-sellers' => ['best-sellers.php', 'daily', '0.8'],
        'combos'       => ['combos.php', 'weekly', '0.7'],
        'brands'       => ['brands.php', 'weekly', '0.7'],
        'blog'         => ['blog.php', 'daily', '0.7'],
        'contact'      => ['contact.php', 'monthly', '0.6'],
        'about'        => ['about.php', 'monthly', '0.5'],
        'faq'          => ['faq.php', 'monthly', '0.6'],
    ];
    foreach ($routes as $path => [$script, $changefreq, $priority]) {
        if ($routeExists($script) && $indexable($path)) {
            $add(url($path), null, $changefreq, $priority);
        }
    }

    foreach (Database::fetchAll(
        "SELECT `slug`, `updated_at` FROM `pages`
          WHERE `status` = 'active' AND " . sitemap_indexable_sql() . '
          ORDER BY `sort_order`, `title`'
    ) as $row) {
        $slug  = (string) $row['slug'];
        $fixed = cms_fixed_routes()[$slug] ?? null;

        if ($fixed !== null && !$routeExists($fixed . '.php')) {
            $fixed = null;           // still reachable at /page/<slug>
        }

        $add(
            $fixed !== null ? url($fixed) : page_url($slug),
            (string) $row['updated_at'],
            'monthly',
            $fixed !== null ? '0.6' : '0.4'
        );
    }

    return $entries;
}

/**
 * How many URLs a section holds in total.
 *
 * Counted with the same WHERE the builder uses, so the index can never
 * advertise a page of a section that turns out to be empty.
 */
function sitemap_section_count(string $key): int
{
    if (!sitemap_section_enabled($key)) {
        return 0;
    }

    try {
        switch ($key) {
            case 'products':
                $where = product_visible_sql('p') . ' AND ' . sitemap_indexable_sql('p')
                    . (setting_bool('sitemap_exclude_oos', false) ? ' AND p.`stock` > 0' : '');
                return (int) Database::fetchColumn("SELECT COUNT(*) FROM `products` p WHERE $where");

            case 'categories':
                return (int) Database::fetchColumn(
                    "SELECT COUNT(*) FROM `categories` WHERE `status` = 'active' AND " . sitemap_indexable_sql()
                );

            case 'brands':
                return (int) Database::fetchColumn(
                    "SELECT COUNT(*) FROM `brands` WHERE `status` = 'active' AND " . sitemap_indexable_sql()
                );

            case 'blog':
                return (int) Database::fetchColumn(
                    'SELECT COUNT(*) FROM `blog_posts` p WHERE ' . blog_visible_sql('p')
                    . ' AND ' . sitemap_indexable_sql('p')
                );

            case 'images':
                return (int) Database::fetchColumn(
                    'SELECT COUNT(*) FROM `products` p WHERE ' . product_visible_sql('p')
                    . ' AND ' . sitemap_indexable_sql('p')
                    . ' AND ' . sitemap_has_image_sql('p')
                );

            case 'pages':
                return count(sitemap_page_entries(static fn (string $loc, $lastmod, string $c, string $p): array
                    => ['loc' => $loc]));
        }
    } catch (Throwable $e) {
        return 0;
    }

    return 0;
}

/** The most recent change in a section, as a timestamp string or null. */
function sitemap_section_lastmod(string $key): ?string
{
    $queries = [
        'products'   => 'SELECT MAX(`updated_at`) FROM `products`',
        'categories' => 'SELECT MAX(`updated_at`) FROM `categories`',
        'brands'     => 'SELECT MAX(`updated_at`) FROM `brands`',
        'pages'      => 'SELECT MAX(`updated_at`) FROM `pages`',
        'blog'       => 'SELECT MAX(`updated_at`) FROM `blog_posts`',
        'images'     => 'SELECT MAX(`created_at`) FROM `product_images`',
    ];

    if (!isset($queries[$key])) {
        return null;
    }

    try {
        $value = Database::fetchColumn($queries[$key]);
    } catch (Throwable $e) {
        return null;
    }

    if (!is_string($value) || $value === '') {
        return null;
    }
    $timestamp = strtotime($value);

    return $timestamp === false ? null : date('c', $timestamp);
}

/** How many files a section needs at the configured chunk size. */
function sitemap_section_pages(string $key): int
{
    $count = sitemap_section_count($key);
    return $count < 1 ? 0 : (int) ceil($count / sitemap_chunk_size());
}

// ===========================================================================
//  XML
// ===========================================================================

/** The index: one <sitemap> per section file. */
function sitemap_index_xml(): string
{
    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
         . '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

    foreach (array_keys(sitemap_sections()) as $key) {
        $pages = sitemap_section_pages($key);
        if ($pages < 1) {
            continue;                 // an empty section is left out, not listed empty
        }

        $lastmod = sitemap_section_lastmod($key);
        for ($page = 1; $page <= $pages; $page++) {
            $xml .= '  <sitemap>' . "\n"
                 . '    <loc>' . e(sitemap_child_url($key, $page)) . '</loc>' . "\n"
                 . ($lastmod !== null ? '    <lastmod>' . e($lastmod) . '</lastmod>' . "\n" : '')
                 . '  </sitemap>' . "\n";
        }
    }

    return $xml . '</sitemapindex>' . "\n";
}

/**
 * One section file.
 *
 * Returns null when the section is off, unknown, or the page is past the end -
 * which the route turns into a 404 rather than an empty <urlset>, so a stale
 * link in Search Console is reported as gone instead of as empty.
 */
function sitemap_section_xml(string $key, int $page = 1): ?string
{
    if (!sitemap_section_enabled($key)) {
        return null;
    }

    $pages = sitemap_section_pages($key);
    if ($pages < 1 || $page < 1 || $page > $pages) {
        return null;
    }

    $entries = sitemap_section_entries($key, $page);
    if ($entries === []) {
        return null;
    }

    $withImages = $key === 'images';

    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
         . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"'
         . ($withImages ? ' xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"' : '')
         . '>' . "\n";

    $bytes = strlen($xml);

    foreach ($entries as $entry) {
        $block = '  <url>' . "\n"
            . '    <loc>' . e((string) $entry['loc']) . '</loc>' . "\n";

        if (!empty($entry['lastmod'])) {
            $block .= '    <lastmod>' . e((string) $entry['lastmod']) . '</lastmod>' . "\n";
        }
        $block .= '    <changefreq>' . e((string) $entry['changefreq']) . '</changefreq>' . "\n"
                . '    <priority>' . e((string) $entry['priority']) . '</priority>' . "\n";

        foreach ((array) ($entry['images'] ?? []) as $image) {
            $block .= '    <image:image>' . "\n"
                . '      <image:loc>' . e((string) $image['loc']) . '</image:loc>' . "\n"
                . (trim((string) ($image['title'] ?? '')) !== ''
                    ? '      <image:title>' . e((string) $image['title']) . '</image:title>' . "\n" : '')
                . '    </image:image>' . "\n";
        }

        $block .= '  </url>' . "\n";

        // The 50 MB ceiling is the format's, and a file over it is rejected
        // whole rather than truncated by the crawler - so we stop short of it
        // ourselves. Reaching this means the chunk size is too high for a
        // catalogue with very long URLs; the admin screen reports it.
        if ($bytes + strlen($block) > SITEMAP_MAX_BYTES - 64) {
            break;
        }

        $xml   .= $block;
        $bytes += strlen($block);
    }

    return $xml . '</urlset>' . "\n";
}

/**
 * A built sitemap, from cache when it is fresh enough.
 *
 * The cache is what makes an on-demand sitemap affordable for a big catalogue:
 * a crawler that pulls twelve section files in a row builds them once. Busted
 * by admin_after_write() like every other storefront cache, so a product saved
 * in the admin is in the sitemap on the next request.
 */
function sitemap_cached(string $key, int $page = 1): ?string
{
    $ttl = (int) setting('sitemap_cache_ttl', '900');
    if ($ttl < 1) {
        return $key === 'index' ? sitemap_index_xml() : sitemap_section_xml($key, $page);
    }

    $cacheKey = 'sitemap.' . $key . '.' . $page;

    $xml = cache_remember($cacheKey, $ttl, static function () use ($key, $page) {
        // false rather than null: the cache cannot tell a stored null from a
        // miss, so "this section has nothing" would be rebuilt on every hit.
        return ($key === 'index' ? sitemap_index_xml() : sitemap_section_xml($key, $page)) ?? false;
    });

    return is_string($xml) ? $xml : null;
}

// ===========================================================================
//  ROBOTS
// ===========================================================================

/** The comment that heads each block of rules in the rendered file. */
const ROBOTS_GROUPS = [
    'private'   => '# Server-side and private folders',
    'session'   => '# Session-specific pages - nothing here is useful in search results',
    'facets'    => '# Faceted shop URLs multiply into near-duplicates; the plain listings are enough',
    'catalogue' => '# Catalogue',
    'sitemap'   => '# The sitemap files themselves',
];

/**
 * The crawl rules, as structure rather than text.
 *
 * Kept separate from the rendering so the admin can be told whether a given
 * URL is allowed without fetching and re-parsing robots.txt over HTTP, and so
 * the rendered file and the answer can never disagree.
 *
 * The disallow list is deliberately code, not settings: these paths are a
 * property of how the application is routed, and an admin who could edit them
 * could silently de-index the whole catalogue. The operator's own lines are
 * appended (group `extra`) and are the only editable part.
 *
 * @return array<int,array{allow:bool,path:string,group:string}>
 */
function robots_rules(): array
{
    $rules = [];

    // With a hidden admin login address, naming /admin/ would tell every
    // visitor where the panel lives; it answers 404 to crawlers anyway.
    $private = ['/admin/', '/api/', '/config/', '/includes/', '/storage/', '/uploads/', '/database/'];
    if (function_exists('admin_gate_enabled') && admin_gate_enabled()) {
        $private = array_values(array_diff($private, ['/admin/']));
    }
    foreach ($private as $path) {
        $rules[] = ['allow' => false, 'path' => $path, 'group' => 'private'];
    }

    foreach ([
        'cart', 'checkout', 'account', 'login', 'register', 'logout',
        'wishlist', 'compare', 'orders', 'order-details', 'purchase-history',
        'notifications', 'preferences', 'addresses', 'change-password',
        'forgot-password', 'reset-password', 'track-order',
    ] as $route) {
        // Both spellings, because the clean URL and the .php file both resolve.
        $rules[] = ['allow' => false, 'path' => '/' . $route, 'group' => 'session'];
        $rules[] = ['allow' => false, 'path' => '/' . $route . '.php', 'group' => 'session'];
    }

    foreach (['sort=', 'brand_ids=', 'attribute_values=', 'min_price=', 'max_price=', 'rating='] as $param) {
        $rules[] = ['allow' => false, 'path' => '/*?*' . $param, 'group' => 'facets'];
    }

    foreach (['/shop', '/product/', '/category/', '/brand/', '/blog', '/page/'] as $path) {
        $rules[] = ['allow' => true, 'path' => $path, 'group' => 'catalogue'];
    }

    // The sitemap files themselves, explicitly: a crawler that cannot fetch
    // the index cannot use anything it points at, and a Disallow the operator
    // adds later must not take them out by accident. These are listed after
    // the catalogue block so their longer, more specific match wins.
    foreach (['/sitemap.xml', '/sitemap-', '/sitemap.php'] as $path) {
        $rules[] = ['allow' => true, 'path' => $path, 'group' => 'sitemap'];
    }

    foreach (robots_extra_rules() as $extra) {
        $rules[] = $extra;
    }

    return $rules;
}

/**
 * The operator's own Allow/Disallow lines, parsed.
 *
 * Only the ones that apply to every crawler are returned: a rule written for
 * one named bot must not change the answer we give the admin about Googlebot.
 *
 * @return array<int,array{allow:bool,path:string}>
 */
function robots_extra_rules(): array
{
    $rules = [];
    $applies = true;             // lines before any User-agent apply to *

    foreach (robots_extra_lines() as $line) {
        if (preg_match('/^User-agent:\s*(.+)$/i', $line, $match) === 1) {
            $applies = trim($match[1]) === '*';
            continue;
        }
        if (!$applies) {
            continue;
        }
        if (preg_match('/^(Allow|Disallow):\s*(.*)$/i', $line, $match) === 1) {
            $path = trim($match[2]);
            if ($path !== '') {
                $rules[] = [
                    'allow' => strcasecmp($match[1], 'Allow') === 0,
                    'path'  => $path,
                    'group' => 'extra',
                ];
            }
        }
    }

    return $rules;
}

/**
 * The operator's extra robots.txt lines, filtered.
 *
 * Anything that is not a plain directive is dropped so a paste cannot inject a
 * header or break the format, and an over-long line is dropped rather than
 * wrapped.
 *
 * @return string[]
 */
function robots_extra_lines(): array
{
    $extra = trim((string) setting('robots_txt_extra', ''));
    if ($extra === '') {
        return [];
    }

    $clean = [];
    foreach (preg_split('/\r\n|\r|\n/', $extra) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || strlen($line) > 200) {
            continue;
        }
        if (preg_match('/^(#|User-agent:|Disallow:|Allow:|Crawl-delay:|Sitemap:|Host:)/i', $line) === 1) {
            $clean[] = $line;
        }
    }

    return $clean;
}

/**
 * Would our own robots.txt let a crawler fetch this path?
 *
 * Longest match wins and Allow beats Disallow at equal length, which is how
 * Google and Bing both read the file. `*` and a trailing `$` are honoured; a
 * bare prefix matches as a prefix, exactly as the format says.
 */
function robots_allows(string $pathOrUrl): bool
{
    $path = $pathOrUrl;
    if (preg_match('~^https?://~i', $path) === 1) {
        $parts = parse_url($path);
        $path  = (string) ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    // Rules are written against the site root, so a sub-folder install has to
    // have its folder taken off before matching.
    $base = rtrim((string) (parse_url(SITE_URL, PHP_URL_PATH) ?: ''), '/');
    if ($base !== '' && str_starts_with($path, $base)) {
        $path = substr($path, strlen($base));
    }
    if ($path === '' || $path[0] !== '/') {
        $path = '/' . ltrim($path, '/');
    }

    $decision = true;
    $winner   = -1;

    foreach (robots_rules() as $rule) {
        $pattern = (string) $rule['path'];
        if ($pattern === '') {
            continue;
        }

        $anchored = str_ends_with($pattern, '$');
        $regex    = '~^' . str_replace('\*', '.*', preg_quote(rtrim($pattern, '$'), '~'))
            . ($anchored ? '$' : '') . '~';

        if (preg_match($regex, $path) !== 1) {
            continue;
        }

        $length = strlen(rtrim($pattern, '$'));
        // Equal length: Allow wins, which is the tie-break both crawlers use.
        if ($length > $winner || ($length === $winner && $rule['allow'])) {
            $winner   = $length;
            $decision = (bool) $rule['allow'];
        }
    }

    return $decision;
}

/** The whole robots.txt body, ready to echo. */
function robots_txt_body(): string
{
    $lines = [
        '# ShopInnKart - crawler rules',
        '# The catalogue is open. Anything private, transactional or server-side is not.',
        '',
        'User-agent: *',
    ];

    // Rendered from the same structure robots_allows() evaluates, grouped so
    // the file still reads like something a person wrote. The operator's own
    // lines are skipped here and printed verbatim in their own block below.
    $current = '';
    foreach (robots_rules() as $rule) {
        $group = (string) ($rule['group'] ?? '');
        if ($group === 'extra') {
            continue;
        }
        if ($group !== $current) {
            $current = $group;
            $lines[] = '';
            $lines[] = ROBOTS_GROUPS[$group] ?? '#';
        }
        $lines[] = ($rule['allow'] ? 'Allow: ' : 'Disallow: ') . $rule['path'];
    }

    $extra = robots_extra_lines();
    if ($extra !== []) {
        $lines[] = '';
        $lines[] = '# Added from Admin > Settings > SEO';
        array_push($lines, ...$extra);
    }

    $lines[] = '';
    $lines[] = 'Sitemap: ' . sitemap_index_url();

    return implode("\n", $lines) . "\n";
}

// ===========================================================================
//  HEALTH
// ===========================================================================

/**
 * Fetch one of the store's OWN addresses, briefly, and say whether it answered.
 *
 * A short timeout is the point, not an accident. These are local addresses,
 * and on any single-worker setup (the PHP built-in server, php-fpm with one
 * child) the request cannot be served while the worker is still rendering the
 * page that asks for it - so the fetch ALWAYS times out there. A store that
 * cannot answer its own robots.txt in half a second has a problem this page
 * should report, not sit and wait for.
 *
 * `reached` is kept apart from `status` because "we could not look" and "we
 * looked and it is wrong" are different answers and print differently.
 *
 * @return array{reached:bool,status:int,body:string,seconds:float}
 */
function sitemap_health_fetch(string $url, float $timeout = SITEMAP_FETCH_TIMEOUT): array
{
    $started = microtime(true);

    $context = stream_context_create([
        'http' => [
            'method'          => 'GET',
            'timeout'         => $timeout,   // connect AND read, both
            'follow_location' => 1,
            'max_redirects'   => 3,
            // A 404 body is still an answer: without this, file_get_contents
            // returns false on any 4xx/5xx and we could not tell it apart
            // from silence.
            'ignore_errors'   => true,
            'header'          => "User-Agent: ShopInnKart SEO health check\r\nConnection: close\r\n",
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);

    $http_response_header = [];
    $body = @file_get_contents($url, false, $context);
    $took = round(microtime(true) - $started, 3);

    if (!is_string($body)) {
        return ['reached' => false, 'status' => 0, 'body' => '', 'seconds' => $took];
    }

    $status = 0;
    foreach ($http_response_header as $line) {
        if (preg_match('~^HTTP/\S+\s+(\d{3})~', $line, $m) === 1) {
            $status = (int) $m[1];   // last one wins, so a chain reports where it landed
        }
    }

    return ['reached' => true, 'status' => $status, 'body' => $body, 'seconds' => $took];
}

/**
 * Everything that can be checked WITHOUT the network.
 *
 * This is the half that always works: each section is built in process and
 * parsed, so "does it parse", "how many URLs", "are they absolute", "is every
 * lastmod a real date" and "does robots.txt allow this file" are answered as
 * facts even on a single-worker server that cannot fetch itself.
 *
 * @return array<int,array<string,mixed>>
 */
function sitemap_health_local(): array
{
    $facts = [];

    $index = sitemap_index_xml();
    $facts[] = sitemap_health_parse('index', sitemap_index_url(), $index, 'sitemap');

    foreach (array_keys(sitemap_sections()) as $key) {
        if (!sitemap_section_enabled($key)) {
            continue;
        }
        $pages = sitemap_section_pages($key);
        for ($page = 1; $page <= $pages; $page++) {
            $xml = sitemap_section_xml($key, $page);
            $facts[] = sitemap_health_parse(
                $key . ($pages > 1 ? ' (' . $page . '/' . $pages . ')' : ''),
                sitemap_child_url($key, $page),
                (string) $xml,
                'url'
            );
        }
    }

    return $facts;
}

/**
 * Parse one built sitemap and report what is in it.
 *
 * @return array<string,mixed>
 */
function sitemap_health_parse(string $label, string $url, string $xml, string $childTag): array
{
    $fact = [
        'label'    => $label,
        'url'      => $url,
        'parses'   => false,
        'urls'     => 0,
        'bytes'    => strlen($xml),
        'problems' => [],
        'sample'   => [],
        'allowed'  => robots_allows($url),
    ];

    if ($xml === '') {
        $fact['problems'][] = 'Nothing was generated.';
        return $fact;
    }

    $previous = libxml_use_internal_errors(true);
    $doc = simplexml_load_string($xml);
    $errors = libxml_get_errors();
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    if ($doc === false) {
        $fact['problems'][] = 'Not well-formed XML: ' . trim((string) ($errors[0]->message ?? 'unknown error'));
        return $fact;
    }

    $fact['parses'] = true;

    $children = $doc->children('http://www.sitemaps.org/schemas/sitemap/0.9');
    $count = 0;
    foreach ($children->{$childTag} as $node) {
        $count++;
        $loc = trim((string) $node->loc);

        if ($loc === '') {
            $fact['problems'][] = 'An entry has no <loc>.';
            continue;
        }
        if (preg_match('~^https?://~i', $loc) !== 1) {
            $fact['problems'][] = 'Not an absolute URL: ' . $loc;
        }
        $lastmod = trim((string) $node->lastmod);
        if ($lastmod !== '' && strtotime($lastmod) === false) {
            $fact['problems'][] = 'lastmod is not a date a crawler can read: ' . $lastmod;
        }
        if (count($fact['sample']) < 5) {
            $fact['sample'][] = $loc;
        }
    }

    $fact['urls'] = $count;

    if ($count === 0) {
        $fact['problems'][] = 'Lists nothing.';
    }
    if ($count > SITEMAP_MAX_URLS) {
        $fact['problems'][] = 'Lists ' . $count . ' URLs; the format allows ' . SITEMAP_MAX_URLS . '.';
    }
    if ($fact['bytes'] > SITEMAP_MAX_BYTES) {
        $fact['problems'][] = 'Is ' . round($fact['bytes'] / 1048576, 1) . ' MB; the format allows 50 MB.';
    }
    if (!$fact['allowed']) {
        $fact['problems'][] = 'robots.txt disallows this file, so no crawler will read it.';
    }

    // Keep only the first of each problem: a broken template produces the same
    // sentence 50,000 times, and the admin needs the sentence, not the count.
    $fact['problems'] = array_values(array_unique($fact['problems']));

    return $fact;
}

/**
 * Ask the store for a sample of its own URLs and report what came back.
 *
 * Bounded twice: each fetch gets SITEMAP_FETCH_TIMEOUT, and the whole run gets
 * SITEMAP_HEALTH_BUDGET. The first unreachable URL stops the run - if the
 * store is not answering, every further wait buys the same answer more slowly.
 *
 * @param string[] $urls
 * @return array{checked:array<int,array<string,mixed>>,stopped:string}
 */
function sitemap_health_remote(array $urls, float $budget = SITEMAP_HEALTH_BUDGET): array
{
    $checked = [];
    $stopped = '';
    $started = microtime(true);

    foreach ($urls as $url) {
        if (microtime(true) - $started > $budget) {
            $stopped = 'time budget reached';
            break;
        }

        $response = sitemap_health_fetch($url);
        $canonical = '';
        if ($response['reached']
            && preg_match('~<link[^>]+rel=["\']canonical["\'][^>]*href=["\']([^"\']+)~i', $response['body'], $m) === 1) {
            $canonical = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
        }

        $checked[] = [
            'url'       => $url,
            'reached'   => $response['reached'],
            'status'    => $response['status'],
            'seconds'   => $response['seconds'],
            'canonical' => $canonical,
            // A canonical that points somewhere else means the URL in the
            // sitemap is not the one the store wants indexed - the single most
            // common reason for "Duplicate, submitted URL not selected as
            // canonical" in Search Console.
            'canonical_ok' => $canonical === '' || rtrim($canonical, '/') === rtrim($url, '/'),
            'allowed'   => robots_allows($url),
        ];

        if (!$response['reached']) {
            $stopped = 'the store did not answer ' . $url;
            break;
        }
    }

    return ['checked' => $checked, 'stopped' => $stopped];
}

// ===========================================================================
//  SEARCH CONSOLE VERIFICATION FILE
// ===========================================================================

/**
 * Is this a verification filename Google would actually have issued?
 *
 * Strict on purpose: this name becomes a file in the web root. Anything with a
 * path separator, an extension other than .html or a shape Google does not use
 * is a mistake at best and a write-anywhere primitive at worst.
 */
function gsc_verification_name_valid(string $name): bool
{
    return preg_match('/^google[a-z0-9]{8,40}\.html$/', strtolower(trim($name))) === 1;
}

/** The exact body Google looks for inside that file. */
function gsc_verification_body(string $name): string
{
    return 'google-site-verification: ' . strtolower(trim($name)) . "\n";
}

/**
 * Write the verification file into the web root.
 *
 * @return array{ok:bool,message:string}
 */
function gsc_verification_write(string $name): array
{
    $name = strtolower(trim($name));

    if (!gsc_verification_name_valid($name)) {
        return [
            'ok' => false,
            'message' => 'That is not a Google verification filename. It looks like google1a2b3c4d5e.html.',
        ];
    }

    $path = ROOT_PATH . '/' . $name;
    if (@file_put_contents($path, gsc_verification_body($name)) === false) {
        return [
            'ok' => false,
            'message' => 'Could not write ' . $name . ' into the site folder. '
                . 'Upload it yourself, or use the meta tag instead.',
        ];
    }

    return ['ok' => true, 'message' => $name . ' is now served from the site root.'];
}

/** Remove a verification file we wrote. Never touches anything else. */
function gsc_verification_delete(string $name): bool
{
    $name = strtolower(trim($name));
    if (!gsc_verification_name_valid($name)) {
        return false;
    }

    $path = ROOT_PATH . '/' . $name;

    return is_file($path) && @unlink($path);
}

/**
 * What the admin screen needs to say about verification.
 *
 * @return array{file:string,present:bool,url:string,meta:string}
 */
function gsc_verification_status(): array
{
    $file = strtolower(trim((string) setting('gsc_verification_file', '')));
    $valid = gsc_verification_name_valid($file);

    return [
        'file'    => $valid ? $file : '',
        'present' => $valid && is_file(ROOT_PATH . '/' . $file),
        'url'     => $valid ? url($file) : '',
        'meta'    => trim((string) setting('google_site_verification', '')),
    ];
}

/** Where the owner submits the sitemap, per engine. */
function sitemap_submission_links(): array
{
    $site = rtrim(SITE_URL, '/');

    return [
        'Google Search Console' => 'https://search.google.com/search-console/sitemaps?resource_id='
            . rawurlencode($site . '/'),
        'Bing Webmaster Tools'  => 'https://www.bing.com/webmasters/sitemaps?siteUrl=' . rawurlencode($site),
    ];
}
