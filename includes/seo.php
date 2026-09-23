<?php
/**
 * ShopInnKart - SEO metadata and structured data.
 *
 * A page calls seo_set([...]) before including the header; the header calls
 * seo_render() to emit titles, meta, Open Graph, canonical and JSON-LD.
 */

declare(strict_types=1);

/** Set or merge SEO values for the current page. */
function seo_set(array $values): void
{
    $GLOBALS['_seo'] = array_merge($GLOBALS['_seo'] ?? [], $values);
}

/** Read one SEO value. */
function seo_get(string $key, $default = null)
{
    return $GLOBALS['_seo'][$key] ?? $default;
}

/** Load the admin-managed defaults for a named route (home, shop, ...). */
function seo_from_page(string $pageKey): void
{
    $row = Database::fetch('SELECT * FROM `seo_settings` WHERE `page_key` = :key LIMIT 1', ['key' => $pageKey]);
    if ($row === null) {
        return;
    }

    seo_set(array_filter([
        'title'       => $row['meta_title'],
        'description' => $row['meta_description'],
        'keywords'    => $row['meta_keywords'],
        'og_title'    => $row['og_title'],
        'og_description' => $row['og_description'],
        'og_image'    => $row['og_image'],
        'robots'      => $row['robots'],
        'canonical'   => $row['canonical'],
    ], static fn ($v) => $v !== null && $v !== ''));
}

/** Register a JSON-LD block to be printed with the rest of the head. */
function seo_add_schema(array $schema): void
{
    $GLOBALS['_seo_schema'][] = $schema;
}

/** Emit every head tag. Called once, from includes/header.php. */
function seo_render(): string
{
    $storeName = (string) setting('store_name', SITE_NAME);

    $title = (string) seo_get('title', setting('meta_title', $storeName));
    if (seo_get('append_store', true) && stripos($title, $storeName) === false) {
        $title .= ' | ' . $storeName;
    }

    $description = str_limit((string) seo_get('description', setting('meta_description', '')), 300, '');
    $keywords = (string) seo_get('keywords', setting('meta_keywords', ''));
    $robots = (string) seo_get('robots', 'index, follow');
    $canonical = (string) seo_get('canonical', seo_default_canonical());
    $ogImage = seo_get('og_image', setting('og_image', 'assets/images/banners/og-default.svg'));
    $ogType = (string) seo_get('og_type', 'website');

    $out = [];
    $out[] = '<title>' . e($title) . '</title>';
    if ($description !== '') {
        $out[] = '<meta name="description" content="' . e($description) . '">';
    }
    if ($keywords !== '') {
        $out[] = '<meta name="keywords" content="' . e($keywords) . '">';
    }
    $out[] = '<meta name="robots" content="' . e($robots) . '">';
    $out[] = '<link rel="canonical" href="' . e($canonical) . '">';

    // Open Graph
    $out[] = '<meta property="og:site_name" content="' . e($storeName) . '">';
    $out[] = '<meta property="og:type" content="' . e($ogType) . '">';
    $out[] = '<meta property="og:title" content="' . e((string) seo_get('og_title', $title)) . '">';
    $out[] = '<meta property="og:description" content="' . e((string) seo_get('og_description', $description)) . '">';
    $out[] = '<meta property="og:url" content="' . e($canonical) . '">';
    $out[] = '<meta property="og:image" content="' . e(img_url((string) $ogImage)) . '">';
    $out[] = '<meta property="og:locale" content="en_IN">';

    // Twitter / X. The card type and @username are admin settings rather than
    // constants: an account without a large image asset is better served by a
    // plain summary card, and that is the operator's call, not ours.
    $cardType = (string) setting('twitter_card_type', 'summary_large_image');
    if (!in_array($cardType, ['summary', 'summary_large_image', 'app', 'player'], true)) {
        $cardType = 'summary_large_image';
    }
    $out[] = '<meta name="twitter:card" content="' . e($cardType) . '">';
    if ($twitterSite = trim((string) setting('twitter_site', ''))) {
        $out[] = '<meta name="twitter:site" content="' . e('@' . ltrim($twitterSite, '@')) . '">';
    }
    $out[] = '<meta name="twitter:title" content="' . e((string) seo_get('twitter_title', seo_get('og_title', $title))) . '">';
    $out[] = '<meta name="twitter:description" content="' . e((string) seo_get('twitter_description', seo_get('og_description', $description))) . '">';
    $out[] = '<meta name="twitter:image" content="' . e(img_url((string) seo_get('twitter_image', $ogImage))) . '">';

    // Pagination. rel=prev/next is no longer a Google ranking signal, but it is
    // still read by Bing and by other crawlers, and it costs one tag.
    if ($prev = seo_get('prev_url')) {
        $out[] = '<link rel="prev" href="' . e((string) $prev) . '">';
    }
    if ($next = seo_get('next_url')) {
        $out[] = '<link rel="next" href="' . e((string) $next) . '">';
    }

    // Webmaster verification. Each one renders only when the operator has
    // pasted their own token in Admin > Settings > SEO; an empty setting emits
    // nothing rather than an empty tag that would fail verification anyway.
    $verifications = [
        'google-site-verification' => 'google_site_verification',
        'msvalidate.01'            => 'bing_site_verification',
        'p:domain_verify'          => 'pinterest_site_verification',
        'yandex-verification'      => 'yandex_site_verification',
    ];
    foreach ($verifications as $metaName => $settingKey) {
        if ($token = trim((string) setting($settingKey, ''))) {
            $out[] = '<meta name="' . e($metaName) . '" content="' . e($token) . '">';
        }
    }

    // JSON-LD
    $schemas = $GLOBALS['_seo_schema'] ?? [];
    array_unshift($schemas, seo_organization_schema());

    // e_json(), not a bare json_encode: this is the project's one hardened
    // script-context encoder, and it is the only thing between an admin-typed
    // product name and the page. Without JSON_HEX_TAG a name containing the
    // literal "</script><script>..." closed this element and ran as script on
    // every storefront visit - a stored XSS any Product or Content Manager
    // could plant, and a way to ride the Super Admin's session. < is
    // still valid JSON, so crawlers read the schema exactly as before.
    foreach ($schemas as $schema) {
        $out[] = '<script type="application/ld+json">' . e_json($schema) . '</script>';
    }

    return implode("\n    ", $out);
}

/**
 * Look up an admin-managed redirect for a request path and send it.
 *
 * Called from 404.php, which is precisely where a moved URL arrives: routing
 * has already failed to find a real file, a rewrite target or a database
 * record, so anything matched here is genuinely a stale link rather than
 * something that would have worked anyway. Doing it at this boundary also
 * means the lookup costs nothing on the pages people actually visit.
 *
 * Exact matches are tried first and are a single indexed lookup. Regex rules
 * are a deliberate second class: they need a full scan, so they are only
 * consulted when no literal rule matched, and there are normally very few.
 *
 * @return never|void  exits with a redirect when one matches
 */
function seo_apply_redirect(?string $path = null): void
{
    $path = $path ?? strtok((string) ($_SERVER['REQUEST_URI'] ?? '/'), '?');

    // Compare on the app-relative path so rules stay portable between a
    // sub-folder install and a domain root.
    $base = rtrim(parse_url(SITE_URL, PHP_URL_PATH) ?: '', '/');
    if ($base !== '' && str_starts_with($path, $base)) {
        $path = substr($path, strlen($base));
    }
    $path = '/' . trim((string) $path, '/');

    try {
        $rule = Database::fetch(
            "SELECT * FROM `redirects`
             WHERE `status` = 'active' AND `is_regex` = 0 AND `source_path` = :p LIMIT 1",
            ['p' => $path]
        );

        if (!$rule) {
            foreach (Database::fetchAll(
                "SELECT * FROM `redirects` WHERE `status` = 'active' AND `is_regex` = 1 ORDER BY `id`"
            ) as $candidate) {
                // The pattern is admin-supplied, so it is delimited here rather
                // than trusted to carry its own delimiters, and a pattern that
                // fails to compile is skipped instead of fataling the 404 page.
                $pattern = '~' . str_replace('~', '\~', (string) $candidate['source_path']) . '~';
                $matched = @preg_match($pattern, $path);
                if ($matched === 1) {
                    $rule = $candidate;
                    $rule['target_path'] = (string) @preg_replace($pattern, (string) $candidate['target_path'], $path);
                    break;
                }
            }
        }
    } catch (Throwable $e) {
        // A missing redirects table must never turn a 404 into a 500.
        return;
    }

    if (!$rule) {
        return;
    }

    $target = (string) $rule['target_path'];
    if ($target === '' || $target === $path) {
        return;                      // never redirect a URL to itself
    }

    $status = (int) $rule['status_code'];
    if (!in_array($status, [301, 302, 307, 308], true)) {
        $status = 301;
    }

    // Best-effort analytics; a failed counter is not worth losing the redirect.
    try {
        Database::query(
            'UPDATE `redirects` SET `hits` = `hits` + 1, `last_hit_at` = NOW() WHERE `id` = :id',
            ['id' => (int) $rule['id']]
        );
    } catch (Throwable $e) {
        // ignored
    }

    header('Location: ' . (str_starts_with($target, 'http') ? $target : url(ltrim($target, '/'))), true, $status);
    exit;
}

/**
 * Apply a record's own SEO fields.
 *
 * products, categories, brands, blog_posts and pages all carry the same seven
 * SEO columns, so one resolver serves every entity page instead of each
 * template hand-rolling its own seo_set() call and drifting from the others.
 *
 * Only non-empty values override: an admin who fills in nothing still gets the
 * sensible defaults the page already computed (name as title, excerpt as
 * description, main image as the share image).
 *
 * @param array<string,mixed> $row      the entity row
 * @param array<string,mixed> $fallback title/description/og_image defaults
 */
function seo_from_entity(array $row, array $fallback = []): void
{
    $take = static function (string $key) use ($row): string {
        return trim((string) ($row[$key] ?? ''));
    };

    $values = [];

    $title = $take('meta_title') ?: (string) ($fallback['title'] ?? '');
    if ($title !== '') {
        $values['title'] = $title;
    }

    $description = $take('meta_description') ?: (string) ($fallback['description'] ?? '');
    if ($description !== '') {
        $values['description'] = $description;
    }

    // A per-record canonical is how an admin points a duplicate at the original.
    if ($canonical = $take('canonical_url')) {
        $values['canonical'] = str_starts_with($canonical, 'http') ? $canonical : url($canonical);
    }

    // Robots is validated rather than trusted: a typo here silently
    // de-indexes a page, and the failure is invisible until traffic drops.
    if ($robots = $take('robots')) {
        $values['robots'] = seo_normalise_robots($robots);
    }

    if ($ogTitle = $take('og_title')) {
        $values['og_title'] = $ogTitle;
    }
    if ($ogDescription = $take('og_description')) {
        $values['og_description'] = $ogDescription;
    }

    $ogImage = $take('og_image') ?: (string) ($fallback['og_image'] ?? '');
    if ($ogImage !== '') {
        $values['og_image'] = $ogImage;
    }

    if ($values !== []) {
        seo_set($values);
    }

    // Custom JSON-LD, if the admin supplied any. Invalid JSON is dropped rather
    // than emitted: a malformed block is worse than no block, because it
    // invalidates the whole script tag for the crawler.
    if ($custom = $take('schema_json')) {
        $decoded = json_decode($custom, true);
        if (is_array($decoded) && json_last_error() === JSON_ERROR_NONE) {
            seo_add_schema($decoded);
        }
    }
}

/**
 * Reduce a robots string to the directives that are actually meaningful, so a
 * stray value cannot produce something a crawler will read unpredictably.
 *
 * Three things are guaranteed about the return value, because every caller
 * writes it into a `robots` varchar(60):
 *   - only allowlisted directives survive, de-duplicated;
 *   - contradictions are resolved rather than emitted (a crawler handed
 *     "index, noindex" picks the restrictive one, so we write what it would
 *     actually obey instead of leaving it ambiguous);
 *   - the result never exceeds SEO_ROBOTS_MAX_LENGTH characters. Without the
 *     cap a hand-crafted POST listing every allowlisted directive produced a
 *     92-character string and the save died with SQLSTATE[22001] "Data too
 *     long for column 'robots'" - an unhandled 500 on a state-changing form.
 */
const SEO_ROBOTS_MAX_LENGTH = 60;

function seo_normalise_robots(string $value): string
{
    $allowed = [
        'index', 'noindex', 'follow', 'nofollow', 'noarchive',
        'nosnippet', 'noimageindex', 'notranslate', 'none', 'all',
    ];

    $parts = [];
    foreach (preg_split('/[,\s]+/', strtolower(trim($value))) as $token) {
        $token = trim($token);
        if ($token !== '' && in_array($token, $allowed, true) && !in_array($token, $parts, true)) {
            $parts[] = $token;
        }
    }

    // "none" is shorthand for noindex+nofollow and "all" for index+follow, so
    // either one alongside anything else is a contradiction. The restrictive
    // reading wins, which is what a crawler does with a conflicting list.
    if (in_array('none', $parts, true) || in_array('noindex', $parts, true)) {
        $parts = array_values(array_diff($parts, ['index', 'all']));
    }
    if (in_array('none', $parts, true) || in_array('nofollow', $parts, true)) {
        $parts = array_values(array_diff($parts, ['follow', 'all']));
    }
    if (in_array('none', $parts, true)) {
        $parts = array_values(array_diff($parts, ['noindex', 'nofollow']));
    }
    if (in_array('all', $parts, true)) {
        $parts = array_values(array_diff($parts, ['index', 'follow']));
    }

    // Indexing and following are the directives that decide whether the page
    // exists for a crawler at all; noarchive/nosnippet and friends only shape
    // how an already-indexed page is displayed. Sort the decisive ones first so
    // that if the cap below has to drop anything, it drops the cosmetic tail.
    // Index family before follow family, so the five values the editor's select
    // offers come back out byte-identical and stay `selected` on re-render.
    $priority = ['none', 'all', 'index', 'noindex', 'follow', 'nofollow'];
    usort($parts, static function (string $a, string $b) use ($priority): int {
        $ra = array_search($a, $priority, true);
        $rb = array_search($b, $priority, true);
        return ($ra === false ? PHP_INT_MAX : $ra) <=> ($rb === false ? PHP_INT_MAX : $rb);
    });

    // Keep whole directives only - a mid-token cut ("noimageinde") would be
    // read as an unknown directive rather than as nothing.
    $kept   = [];
    $length = 0;
    foreach ($parts as $token) {
        $add = $kept === [] ? strlen($token) : strlen($token) + 2;
        if ($length + $add > SEO_ROBOTS_MAX_LENGTH) {
            break;
        }
        $kept[]  = $token;
        $length += $add;
    }

    return $kept === [] ? 'index, follow' : implode(', ', $kept);
}

/** Canonical URL for the current request, with tracking params stripped. */
function seo_default_canonical(): string
{
    $path = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');

    // 'slug' is deliberately NOT kept. It is an internal rewrite parameter -
    // /category/smartphones is served by category.php?slug=smartphones - so
    // echoing it back produced canonicals like
    // /category/smartphones?slug=smartphones, which points the crawler at a
    // second URL for the same page. That is the exact duplicate it is meant to
    // prevent. 'sort' is kept only because a sorted listing is a distinct view;
    // robots.txt already discourages crawling those.
    $keep = ['page', 'q', 'sort'];

    $query = [];
    foreach ($_GET as $key => $value) {
        if (in_array($key, $keep, true) && is_scalar($value) && (string) $value !== '') {
            $query[$key] = $value;
        }
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $base = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? SITE_DOMAIN) . $path;

    return $query === [] ? $base : $base . '?' . http_build_query($query);
}

// ===========================================================================
//  STRUCTURED DATA
// ===========================================================================

function seo_organization_schema(): array
{
    $socials = array_values(array_filter([
        setting('social_facebook', ''),
        setting('social_instagram', ''),
        setting('social_twitter', ''),
        setting('social_youtube', ''),
        setting('social_linkedin', ''),
    ]));

    // Every optional property is included only when it has a value. An empty
    // streetAddress or telephone is not a neutral omission to a validator - it
    // is a declared-but-blank property, which reads as worse data than saying
    // nothing. The address block disappears entirely until an operator sets one.
    $schema = [
        '@context' => 'https://schema.org',
        '@type'    => 'OnlineStore',
        'name'     => (string) setting('store_name', SITE_NAME),
        'url'      => SITE_URL,
        'logo'     => brand_logo_src(),
    ];

    foreach ([
        'description' => 'store_description',
        'email'       => 'store_email',
        'telephone'   => 'store_phone',
    ] as $property => $settingKey) {
        if ($value = trim((string) setting($settingKey, ''))) {
            $schema[$property] = $value;
        }
    }

    if ($address = trim((string) setting('store_address', ''))) {
        $schema['address'] = [
            '@type'          => 'PostalAddress',
            'streetAddress'  => $address,
            'addressCountry' => 'IN',
        ];
    }

    if ($socials !== []) {
        $schema['sameAs'] = $socials;
    }

    // The SearchAction deliberately does NOT live here. A sitelinks search box
    // is a property of the site, so it belongs on the WebSite node below;
    // hanging it off the Organization is a common miscoding that validators
    // accept but Google does not act on.
    return $schema;
}

/**
 * WebSite, with the sitelinks search box.
 *
 * Emitted on the homepage only. Declaring it on every page would repeat the
 * same node dozens of times across a crawl without adding anything.
 */
function seo_website_schema(): array
{
    return [
        '@context'        => 'https://schema.org',
        '@type'           => 'WebSite',
        'name'            => (string) setting('store_name', SITE_NAME),
        'url'             => SITE_URL,
        'potentialAction' => [
            '@type'       => 'SearchAction',
            'target'      => [
                '@type'       => 'EntryPoint',
                'urlTemplate' => url('search.php') . '?q={search_term_string}',
            ],
            'query-input' => 'required name=search_term_string',
        ],
    ];
}

/**
 * WebPage for a content document.
 *
 * Used by the CMS pages, where there is no richer type that fits: a privacy
 * policy is not an Article and pretending otherwise to win a rich result is
 * exactly the sort of miscoding that gets a site's structured data ignored.
 */
function seo_webpage_schema(array $page, string $url): array
{
    $schema = [
        '@context' => 'https://schema.org',
        '@type'    => 'WebPage',
        'name'     => (string) ($page['title'] ?? ''),
        'url'      => $url,
    ];

    if ($description = trim((string) ($page['meta_description'] ?? ''))) {
        $schema['description'] = $description;
    }
    if (!empty($page['updated_at'])) {
        $schema['dateModified'] = date(DATE_ATOM, strtotime((string) $page['updated_at']));
    }
    $schema['isPartOf'] = ['@type' => 'WebSite', 'url' => SITE_URL];

    return $schema;
}

/**
 * LocalBusiness - only when there is a real address and phone to describe.
 *
 * A LocalBusiness node with no address is not a partial answer, it is a false
 * claim that the business has a physical location. Both values are blank until
 * an operator fills them in, so on a fresh install this returns null and
 * nothing is emitted.
 */
function seo_local_business_schema(): ?array
{
    $address = trim((string) setting('store_address', ''));
    $phone   = trim((string) setting('store_phone', ''));

    if ($address === '' || $phone === '') {
        return null;
    }

    $schema = [
        '@context'  => 'https://schema.org',
        '@type'     => 'LocalBusiness',
        'name'      => (string) setting('store_name', SITE_NAME),
        'url'       => SITE_URL,
        'telephone' => $phone,
        'address'   => [
            '@type'          => 'PostalAddress',
            'streetAddress'  => $address,
            'addressCountry' => 'IN',
        ],
    ];

    if ($hours = trim((string) setting('business_hours', ''))) {
        // Free text rather than a parsed openingHoursSpecification: the setting
        // is a human sentence, and guessing structure from it would invent data.
        $schema['openingHours'] = $hours;
    }
    if ($logo = trim((string) setting('store_logo', ''))) {
        $schema['image'] = img_url($logo);
    }

    return $schema;
}

/** Product schema with offer, brand and aggregate rating. */
function seo_product_schema(array $product): array
{
    $availability = ((int) $product['stock'] > 0)
        ? 'https://schema.org/InStock'
        : 'https://schema.org/OutOfStock';

    $images = [];
    foreach ($product['images'] ?? [] as $image) {
        $images[] = img_url($image['image']);
    }
    if ($images === []) {
        $images[] = img_url($product['main_image'] ?? null);
    }

    $schema = [
        '@context'    => 'https://schema.org',
        '@type'       => 'Product',
        'name'        => (string) $product['name'],
        'image'       => $images,
        'description' => str_limit((string) ($product['short_description'] ?: $product['description']), 400, ''),
        'sku'         => (string) $product['sku'],
        'url'         => product_url((string) $product['slug']),
        'offers'      => [
            '@type'         => 'Offer',
            'url'           => product_url((string) $product['slug']),
            'priceCurrency' => (string) setting('currency_code', CURRENCY),
            'price'         => number_format((float) ($product['final_price'] ?? $product['price']), 2, '.', ''),
            'availability'  => $availability,
            'itemCondition' => 'https://schema.org/NewCondition',
            'seller'        => ['@type' => 'Organization', 'name' => (string) setting('store_name', SITE_NAME)],
        ],
    ];

    if (!empty($product['brand_name'])) {
        $schema['brand'] = ['@type' => 'Brand', 'name' => (string) $product['brand_name']];
    }
    if (!empty($product['model_number'])) {
        $schema['model'] = (string) $product['model_number'];
    }
    if (!empty($product['mpn'] ?? $product['part_number'] ?? null)) {
        $schema['mpn'] = (string) ($product['part_number'] ?? '');
    }
    if ((int) ($product['rating_count'] ?? 0) > 0) {
        $schema['aggregateRating'] = [
            '@type'       => 'AggregateRating',
            'ratingValue' => number_format((float) $product['rating_avg'], 1, '.', ''),
            'reviewCount' => (int) $product['rating_count'],
            'bestRating'  => 5,
            'worstRating' => 1,
        ];
    }

    return $schema;
}

/** Breadcrumb schema from the same array used to render the trail. */
function seo_breadcrumb_schema(array $items): array
{
    $elements = [];
    foreach (array_values($items) as $index => $item) {
        $elements[] = array_filter([
            '@type'    => 'ListItem',
            'position' => $index + 1,
            'name'     => (string) ($item['label'] ?? ''),
            'item'     => $item['url'] ?? null,
        ], static fn ($v) => $v !== null);
    }

    return [
        '@context'        => 'https://schema.org',
        '@type'           => 'BreadcrumbList',
        'itemListElement' => $elements,
    ];
}

/** Article schema for blog posts. */
function seo_article_schema(array $post): array
{
    return [
        '@context'      => 'https://schema.org',
        '@type'         => 'BlogPosting',
        'headline'      => (string) $post['title'],
        'description'   => str_limit((string) $post['excerpt'], 300, ''),
        'image'         => img_url($post['featured_image'] ?? null),
        'datePublished' => date('c', strtotime((string) ($post['published_at'] ?: $post['created_at']))),
        'dateModified'  => date('c', strtotime((string) $post['updated_at'])),
        'author'        => ['@type' => 'Person', 'name' => (string) ($post['author_name'] ?: setting('store_name', SITE_NAME))],
        'publisher'     => [
            '@type' => 'Organization',
            'name'  => (string) setting('store_name', SITE_NAME),
            'logo'  => ['@type' => 'ImageObject', 'url' => img_url((string) setting('store_logo', ''))],
        ],
        'mainEntityOfPage' => blog_url((string) $post['slug']),
    ];
}

/** FAQPage schema for the FAQ route. */
function seo_faq_schema(array $faqs): array
{
    $entities = [];
    foreach ($faqs as $faq) {
        $entities[] = [
            '@type' => 'Question',
            'name'  => (string) $faq['question'],
            'acceptedAnswer' => [
                '@type' => 'Answer',
                'text'  => strip_tags((string) $faq['answer']),
            ],
        ];
    }

    return [
        '@context'   => 'https://schema.org',
        '@type'      => 'FAQPage',
        'mainEntity' => $entities,
    ];
}

/** ItemList schema for a product grid. */
function seo_item_list_schema(array $products, string $name): array
{
    $elements = [];
    foreach (array_values($products) as $index => $product) {
        $elements[] = [
            '@type'    => 'ListItem',
            'position' => $index + 1,
            'url'      => product_url((string) $product['slug']),
            'name'     => (string) $product['name'],
        ];
    }

    return [
        '@context'        => 'https://schema.org',
        '@type'           => 'ItemList',
        'name'            => $name,
        'numberOfItems'   => count($elements),
        'itemListElement' => $elements,
    ];
}
