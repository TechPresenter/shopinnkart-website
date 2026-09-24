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

    // A page may deliberately have no canonical, by passing '' to seo_set().
    // That is the 404 page's case: the URL being asked for does not exist, so
    // naming it as the canonical version of anything is a claim we cannot make,
    // and it also reflected whatever path a bot invented straight back into the
    // head. Emitting href="" instead would be worse - an empty canonical
    // resolves to the current URL, which is the very thing we are avoiding.
    if ($canonical !== '') {
        $out[] = '<link rel="canonical" href="' . e($canonical) . '">';
    }

    // Open Graph
    $out[] = '<meta property="og:site_name" content="' . e($storeName) . '">';
    $out[] = '<meta property="og:type" content="' . e($ogType) . '">';
    $out[] = '<meta property="og:title" content="' . e((string) seo_get('og_title', $title)) . '">';
    $out[] = '<meta property="og:description" content="' . e((string) seo_get('og_description', $description)) . '">';
    // Follows the canonical, including its absence: og:url is the same claim
    // made to a social crawler, so an empty one would be a broken share card.
    if ($canonical !== '') {
        $out[] = '<meta property="og:url" content="' . e($canonical) . '">';
    }
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

    // The schema manager owns what may actually be published: which types are
    // switched on for this kind of page, whether each block carries the fields
    // its type requires, whether the claims in it are true (a rating is
    // rebuilt from approved reviews, never from the product's rating columns),
    // and the admin's own custom JSON-LD. Required here rather than from
    // init.php so a request that renders no head never pays for it.
    require_once INCLUDES_PATH . '/schema.php';
    $schemas = schema_prepare($schemas);

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

    $rendered = implode("\n    ", $out);

    // Per-page custom head code, last, so an operator's own <meta> can override
    // something above it the way a later tag wins in HTML. seo_custom_code_html()
    // is what decides whether it runs at all: never in the admin, and never
    // before the visitor has granted the consent category it is filed under.
    if (!empty($GLOBALS['_seo_code'])) {
        $rendered .= seo_custom_code_html((array) $GLOBALS['_seo_code'], 'head');
    }

    return $rendered;
}

/**
 * The per-page custom body block, emitted immediately after <body>.
 *
 * Its own function rather than part of seo_render() because it goes in a
 * different place in the document: this is where a tag manager's <noscript>
 * fallback belongs, and where markup that has to exist before the page content
 * is parsed has to go.
 *
 * Page-specific JavaScript is NOT here - seo_custom_code_html() emits it in the
 * head with `defer`, which runs it after the document is parsed, exactly like
 * an end-of-body script, while keeping every injection point in one file.
 */
function seo_render_body_open(): string
{
    if (empty($GLOBALS['_seo_code'])) {
        return '';
    }

    return seo_custom_code_html((array) $GLOBALS['_seo_code'], 'body');
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
    // sub-folder install and a domain root. seo_app_path() is shared with the
    // 404 monitor and with the slug-change writer, so the path a rule is stored
    // under and the path looked up here can never be computed differently.
    $path = seo_app_path((string) $path);

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
 * Passing $entityType additionally pulls in the fields that have no column on
 * the entity tables - keywords, the Twitter trio, the breadcrumb controls and
 * the per-page custom code - from `seo_entity_meta`. It is optional so that a
 * caller with a row but no type still gets everything it got before.
 *
 * @param array<string,mixed> $row        the entity row
 * @param array<string,mixed> $fallback   title/description/og_image defaults
 * @param string              $entityType one of SEO_ENTITY_TYPES, or '' to skip
 */
function seo_from_entity(array $row, array $fallback = [], string $entityType = ''): void
{
    $take = static function (string $key) use ($row): string {
        return trim((string) ($row[$key] ?? ''));
    };

    $values = [];

    // The extended fields first, so the entity's own columns below still win.
    if ($entityType !== '' && seo_entity_type($entityType) !== null) {
        $id   = (int) ($row['id'] ?? 0);
        $meta = seo_entity_meta($entityType, $id);

        foreach ([
            'meta_keywords'       => 'keywords',
            'twitter_title'       => 'twitter_title',
            'twitter_description' => 'twitter_description',
            'twitter_image'       => 'twitter_image',
        ] as $column => $seoKey) {
            if ($text = trim((string) $meta[$column])) {
                $values[$seoKey] = $text;
            }
        }

        if ($label = trim((string) $meta['breadcrumb_label'])) {
            $values['breadcrumb_label'] = $label;
        }
        $values['breadcrumb_hide'] = (int) $meta['breadcrumb_hide'] === 1;

        // Held for seo_render() and seo_render_body_open() to emit. Stored as
        // the raw meta rather than rendered markup, because the consent state
        // and the admin check both belong at the moment of output.
        $GLOBALS['_seo_code'] = $meta;
    }

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

    // The ORIGIN comes from SITE_URL, never from the request.
    //
    // This used to build the canonical out of $_SERVER['HTTP_HOST'] and the
    // request scheme. Both are attacker-supplied: a crawler-facing request
    // carrying "Host: evil.example" made every page that does not set its own
    // canonical - shop, search, deals, the 404 page - publish
    // <link rel="canonical" href="http://evil.example/..."> in the store's own
    // name, which is how a site hands its rankings to somebody else. The
    // password-reset round already hit this and built the answer:
    // includes/request-trust.php resolves SITE_URL from an allow-list and
    // falls back to the canonical domain when the Host is not one we know.
    // Reading HTTP_HOST here was a second, unguarded copy of a decision that
    // is already made once, properly, at boot.
    //
    // seo_app_path() strips the install sub-folder so the two halves join
    // cleanly, and it is the same normaliser the redirect table and the 404
    // monitor use - so a canonical, a redirect rule and a logged miss can no
    // longer disagree about what the path of a page is.
    $base = rtrim(SITE_URL, '/') . seo_app_path((string) $path);

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

/**
 * Apply the record's own breadcrumb settings to a trail.
 *
 * Only the LAST crumb is touched, because that is the record itself - the
 * ancestors belong to other records with settings of their own. Two controls:
 *
 *   breadcrumb_label  a shorter name for the trail. "Curtain lights" reads
 *                     better in a crumb than "Warm White LED Curtain Lights
 *                     3m x 3m With Remote", and the page title is unchanged.
 *   breadcrumb_hide   leave the record out of its own trail, for a page whose
 *                     name would only repeat the H1 directly below it.
 *
 * Called from both breadcrumbs() and seo_breadcrumb_schema(), so the markup a
 * shopper sees and the structured data a crawler reads can never disagree -
 * which is precisely the sort of mismatch that gets breadcrumb rich results
 * dropped.
 */
function seo_breadcrumb_apply(array $items): array
{
    if ($items === []) {
        return $items;
    }

    $lastKey = array_key_last($items);

    if (seo_get('breadcrumb_hide') === true && count($items) > 1) {
        unset($items[$lastKey]);
        return array_values($items);
    }

    if ($label = trim((string) seo_get('breadcrumb_label', ''))) {
        $items[$lastKey]['label'] = $label;
    }

    return array_values($items);
}

/** Breadcrumb schema from the same array used to render the trail. */
function seo_breadcrumb_schema(array $items): array
{
    $items = seo_breadcrumb_apply($items);

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
// ===========================================================================
//  THE PER-ENTITY SEO LAYER
// ===========================================================================
//
//  Everything above this line answers "what do we print in the head?".
//  Everything below answers "what has the admin said about THIS record?" -
//  the shared panel's storage, the score that grades it, the slug change that
//  keeps the old URL alive, the 404 monitor and the per-page custom code.
//
//  One registry, six entity types. Every screen that needs to know how to
//  reach a product, a combo or a blog post asks here rather than restating a
//  table name and a URL builder, which is how the five entity forms drifted
//  apart the first time.
// ===========================================================================

/**
 * Every record type that can be indexed, and how to find it.
 *
 *   table       where the nine base SEO columns live
 *   title_col   the human name, used as the meta-title fallback
 *   body_col    the prose the score reads for keyword and link checks
 *   live        the SQL condition for "published", because an unpublished
 *               record is not in the index and must not be scored as if it were
 *   url         the function that builds the public URL from a slug
 *   admin       the edit screen, relative to the admin root
 */
const SEO_ENTITY_TYPES = [
    'product' => [
        'label' => 'Product',  'table' => 'products',    'title_col' => 'name',
        'body_col' => 'description', 'live' => "`status` = 'active'",
        'url' => 'product_url',  'admin' => 'products/edit.php',
    ],
    'category' => [
        'label' => 'Category', 'table' => 'categories',  'title_col' => 'name',
        'body_col' => 'description', 'live' => "`status` = 'active'",
        'url' => 'category_url', 'admin' => 'categories/edit.php',
    ],
    'brand' => [
        'label' => 'Brand',    'table' => 'brands',      'title_col' => 'name',
        'body_col' => 'description', 'live' => "`status` = 'active'",
        'url' => 'brand_url',    'admin' => 'brands/edit.php',
    ],
    'page' => [
        'label' => 'Page',     'table' => 'pages',       'title_col' => 'title',
        'body_col' => 'content', 'live' => "`status` = 'active'",
        'url' => 'page_url',     'admin' => 'pages/edit.php',
    ],
    'post' => [
        'label' => 'Blog post', 'table' => 'blog_posts', 'title_col' => 'title',
        'body_col' => 'content', 'live' => "`status` = 'published'",
        'url' => 'blog_url',     'admin' => 'blog/edit.php',
    ],
    'combo' => [
        'label' => 'Combo',    'table' => 'combos',      'title_col' => 'name',
        'body_col' => 'description', 'live' => "`status` = 'active'",
        'url' => 'combo_url',    'admin' => 'combos/edit.php',
    ],
];

/** The extended fields the side table holds, with their blank values. */
const SEO_ENTITY_META_FIELDS = [
    'meta_keywords'       => '',
    'twitter_title'       => '',
    'twitter_description' => '',
    'twitter_image'       => '',
    'breadcrumb_label'    => '',
    'breadcrumb_hide'     => 0,
    'custom_head'         => '',
    'custom_body'         => '',
    'custom_css'          => '',
    'custom_js'           => '',
    'code_consent'        => 'marketing',
];

/** The four fields only a settings.scripts holder may write. */
const SEO_ENTITY_CODE_FIELDS = ['custom_head', 'custom_body', 'custom_css', 'custom_js'];

/** Which consent category a block of custom code waits for. */
const SEO_CODE_CONSENT = [
    'none'      => 'Run always (first-party code: no third party is contacted)',
    'analytics' => 'Only with Analytics consent',
    'marketing' => 'Only with Marketing consent (default for any third-party tag)',
];

/** The registry entry for a type, or null when the type is not one of ours. */
function seo_entity_type(string $type): ?array
{
    return SEO_ENTITY_TYPES[$type] ?? null;
}

/** The public URL of one record, or '' when the type or slug is unusable. */
function seo_entity_url(string $type, string $slug): string
{
    $spec = seo_entity_type($type);
    if ($spec === null || $slug === '' || !function_exists($spec['url'])) {
        return '';
    }

    return (string) call_user_func($spec['url'], $slug);
}

/**
 * The app-relative path of one record - what a redirect rule matches on.
 *
 * seo_apply_redirect() compares on the path with the install's base folder
 * stripped, so rules stay portable between /ecomweb/ and a domain root. The
 * same stripping has to happen here or every rule written from an admin screen
 * would carry "/ecomweb" and never match in production.
 */
function seo_entity_path(string $type, string $slug): string
{
    $url = seo_entity_url($type, $slug);

    return $url === '' ? '' : seo_app_path((string) parse_url($url, PHP_URL_PATH));
}

/**
 * A URL path with the install's base folder removed.
 *
 * Every redirect rule is stored app-relative, so a store that lives at
 * /ecomweb in development and at a domain root in production has one set of
 * rules rather than two. Three things have to agree on where that folder ends -
 * the rule the admin writes, the path the 404 monitor records, and the lookup
 * seo_apply_redirect() does - so all three ask here.
 *
 * BASE_PATH is what config.php already resolved for this request (from the
 * trusted Host and the app root), so it is correct in a sub-folder install and
 * empty at a domain root. Falling back to SITE_URL's path covers a bootstrap
 * that defined SITE_URL by hand without BASE_PATH.
 */
function seo_app_path(string $path): string
{
    $base = defined('BASE_PATH')
        ? rtrim((string) BASE_PATH, '/')
        : rtrim((string) parse_url(SITE_URL, PHP_URL_PATH), '/');

    if ($base !== '' && str_starts_with($path, $base)) {
        $path = substr($path, strlen($base));
    }

    return '/' . trim($path, '/');
}

// ---------------------------------------------------------------------------
//  Storage for the extended fields
// ---------------------------------------------------------------------------

/**
 * The extended SEO fields for one record, always as a complete array.
 *
 * A record with no row yet is not an error and not null - it is the defaults.
 * Every caller can therefore read $meta['twitter_title'] without first asking
 * whether a row exists, which is what stops half the call sites growing their
 * own ?? '' and the other half not.
 *
 * @return array<string,mixed>
 */
function seo_entity_meta(string $type, int $id): array
{
    $meta = SEO_ENTITY_META_FIELDS;
    $meta['entity_type'] = $type;
    $meta['entity_id']   = $id;
    $meta['code_updated_by'] = null;
    $meta['code_updated_at'] = null;

    if (seo_entity_type($type) === null || $id <= 0) {
        return $meta;
    }

    try {
        $row = Database::fetch(
            'SELECT * FROM `seo_entity_meta` WHERE `entity_type` = :t AND `entity_id` = :i LIMIT 1',
            ['t' => $type, 'i' => $id]
        );
    } catch (Throwable $e) {
        // A missing table must never take a storefront page down. Before the
        // migration has run, every record simply has no extended SEO.
        return $meta;
    }

    if ($row === null) {
        return $meta;
    }

    foreach (SEO_ENTITY_META_FIELDS as $field => $blank) {
        $meta[$field] = is_int($blank) ? (int) ($row[$field] ?? 0) : (string) ($row[$field] ?? '');
    }

    $meta['code_updated_by'] = $row['code_updated_by'] === null ? null : (int) $row['code_updated_by'];
    $meta['code_updated_at'] = $row['code_updated_at'];

    return $meta;
}

/**
 * Write the extended fields for one record.
 *
 * Only the keys actually passed are written. That is the whole contract: the
 * entity forms save the panel's fields, but the code fields are stripped from
 * $values by the caller when the actor may not write code, and a partial save
 * must not therefore wipe the code a Super Admin put there earlier. "Not
 * submitted" and "submitted empty" are different things, and only the second
 * one clears a field.
 *
 * @param array<string,mixed> $values any subset of SEO_ENTITY_META_FIELDS
 */
function seo_entity_meta_save(string $type, int $id, array $values): void
{
    if (seo_entity_type($type) === null || $id <= 0) {
        return;
    }

    $row = [];
    foreach ($values as $field => $value) {
        if (!array_key_exists($field, SEO_ENTITY_META_FIELDS)) {
            continue;                                    // never a column we do not own
        }

        if ($field === 'breadcrumb_hide') {
            $row[$field] = $value ? 1 : 0;
            continue;
        }

        if ($field === 'code_consent') {
            $row[$field] = array_key_exists((string) $value, SEO_CODE_CONSENT) ? (string) $value : 'marketing';
            continue;
        }

        $text = trim((string) $value);
        $row[$field] = $text === '' ? null : $text;
    }

    if ($row === []) {
        return;
    }

    // Any change to code gets a stamp: the inventory screen's whole job is
    // answering "who put this here and when?", and it cannot do that from the
    // updated_at of a row that also moves when somebody edits the keywords.
    if (array_intersect(array_keys($row), SEO_ENTITY_CODE_FIELDS) !== []) {
        $actor = function_exists('admin_user') ? admin_user() : null;
        $row['code_updated_by'] = $actor !== null ? (int) $actor['id'] : null;
        $row['code_updated_at'] = date('Y-m-d H:i:s');
    }

    $exists = Database::fetchColumn(
        'SELECT `id` FROM `seo_entity_meta` WHERE `entity_type` = :t AND `entity_id` = :i',
        ['t' => $type, 'i' => $id]
    );

    if ($exists) {
        Database::update('seo_entity_meta', $row, '`id` = :id', ['id' => (int) $exists]);
        return;
    }

    Database::insert('seo_entity_meta', $row + ['entity_type' => $type, 'entity_id' => $id]);
}

/** Drop the extended fields when the record itself is deleted. */
function seo_entity_meta_delete(string $type, int $id): void
{
    if (seo_entity_type($type) === null || $id <= 0) {
        return;
    }

    try {
        Database::delete('seo_entity_meta', '`entity_type` = :t AND `entity_id` = :i', ['t' => $type, 'i' => $id]);
    } catch (Throwable $e) {
        // A leftover row is harmless: nothing reads it without a live record.
    }
}

// ---------------------------------------------------------------------------
//  Resolving what the head should say
// ---------------------------------------------------------------------------

/**
 * The finished SEO values for one record: entity -> type default -> store.
 *
 * Three layers, applied in that order, each one only filling what the layer
 * above left blank:
 *
 *   1. the record's own fields (both tables);
 *   2. the caller's defaults - the name, the excerpt, the main image - which
 *      are the "type default" in practice, because each storefront template
 *      passes the sensible thing for its own kind of record;
 *   3. the store-wide settings, which seo_render() already applies to anything
 *      still unset.
 *
 * Returning an array rather than calling seo_set() directly is what makes this
 * testable and what lets the admin preview show exactly what the page will do.
 *
 * @param array<string,mixed> $row      the entity row
 * @param array<string,mixed> $fallback title / description / og_image
 * @return array<string,mixed>
 */
function seo_entity_resolve(string $type, ?array $row, array $fallback = []): array
{
    $row  = $row ?? [];
    $id   = (int) ($row['id'] ?? 0);
    $meta = seo_entity_meta($type, $id);

    $take = static function (string $key) use ($row): string {
        return trim((string) ($row[$key] ?? ''));
    };

    $title = $take('meta_title') ?: trim((string) ($fallback['title'] ?? ''));
    $description = $take('meta_description') ?: trim((string) ($fallback['description'] ?? ''));

    $ogTitle = $take('og_title') ?: $title;
    $ogDescription = $take('og_description') ?: $description;
    $ogImage = $take('og_image') ?: trim((string) ($fallback['og_image'] ?? ''));

    $values = [
        'title'       => $title,
        'description' => $description,
        // Keywords have no column on the entity tables, so the side table is
        // the only source; the store-wide default still applies underneath.
        'keywords'    => trim((string) $meta['meta_keywords']),
        'og_title'    => $ogTitle,
        'og_description' => $ogDescription,
        'og_image'    => $ogImage,
        // Twitter falls back to the social card, which falls back to the meta
        // pair. An operator who fills in nothing gets three consistent cards
        // rather than one filled and two blank.
        'twitter_title'       => trim((string) $meta['twitter_title']) ?: $ogTitle,
        'twitter_description' => trim((string) $meta['twitter_description']) ?: $ogDescription,
        'twitter_image'       => trim((string) $meta['twitter_image']) ?: $ogImage,
        'breadcrumb_label'    => trim((string) $meta['breadcrumb_label']),
        'breadcrumb_hide'     => (int) $meta['breadcrumb_hide'] === 1,
    ];

    if ($canonical = $take('canonical_url')) {
        $values['canonical'] = str_starts_with($canonical, 'http') ? $canonical : url(ltrim($canonical, '/'));
    }
    if ($robots = $take('robots')) {
        $values['robots'] = seo_normalise_robots($robots);
    }

    return array_filter($values, static fn ($v) => $v !== '' && $v !== null && $v !== false);
}

// ---------------------------------------------------------------------------
//  Per-page custom code
// ---------------------------------------------------------------------------

/**
 * Turn one record's stored code into the markup for the head or the body.
 *
 * Three rules, and they are the reason this is a function rather than four
 * echoes in a template:
 *
 *   1. NEVER IN THE ADMIN. custom_js runs on the same origin as /admin and on
 *      the same session. The whole point of the field is "run this on the
 *      storefront"; letting it also run on the panel would turn a Content
 *      Manager's snippet into a way to ride the Super Admin's session. The
 *      guard is here, at the one place that can emit it, not at each caller.
 *
 *   2. CSS AND JS CANNOT ESCAPE THEIR ELEMENT. An admin who types "</style>"
 *      or "</script>" into a code box - by accident or otherwise - would
 *      otherwise close the element and have the rest of their text parsed as
 *      markup. Those two sequences are the only thing that can end a raw text
 *      element, so neutralising exactly them is a complete fix and leaves
 *      every legitimate stylesheet and script byte-identical.
 *
 *      custom_head and custom_body are markup by definition and are emitted as
 *      written: they are validated at the door instead (see
 *      seo_code_problem()), and only a settings.scripts holder ever gets to
 *      the door.
 *
 *   3. CONSENT IS ASKED, NOT ASSUMED. The default category is `marketing`,
 *      because the overwhelmingly common use of these boxes is somebody
 *      else's tag, and a tag that runs before the visitor says yes is exactly
 *      what includes/consent.php exists to prevent. An operator pasting
 *      first-party code sets the category to `none` deliberately.
 *
 * @param array<string,mixed> $meta    a seo_entity_meta() array
 * @param string              $slot    'head' or 'body'
 * @param bool|null           $inAdmin override the automatic admin detection
 */
function seo_custom_code_html(array $meta, string $slot = 'head', ?bool $inAdmin = null): string
{
    $inAdmin = $inAdmin ?? (defined('IS_ADMIN') && IS_ADMIN);
    if ($inAdmin) {
        return '';
    }

    $consent = (string) ($meta['code_consent'] ?? 'marketing');
    if ($consent !== 'none') {
        // consent_allows() is the single mechanism for this question. A second
        // answer computed here would drift from the banner the visitor used.
        //
        // It is loaded on demand because this runs from seo_render(), in the
        // HEAD, and includes/footer.php - the file that normally requires
        // consent.php - has not been reached yet. Without this the function did
        // not exist at head time and every gated block was silently withheld
        // from everybody, consent or no consent.
        if (!function_exists('consent_allows') && is_file(INCLUDES_PATH . '/consent.php')) {
            require_once INCLUDES_PATH . '/consent.php';
        }
        if (!function_exists('consent_allows') || !consent_allows($consent)) {
            return '';
        }
    }

    $head = trim((string) ($meta['custom_head'] ?? ''));
    $body = trim((string) ($meta['custom_body'] ?? ''));
    $css  = trim((string) ($meta['custom_css'] ?? ''));
    $js   = trim((string) ($meta['custom_js'] ?? ''));

    if ($slot === 'body') {
        return $body === '' ? '' : "\n" . $body . "\n";
    }

    $out = [];

    if ($head !== '') {
        $out[] = $head;
    }
    if ($css !== '') {
        $out[] = '<style>' . seo_code_escape($css, 'style') . '</style>';
    }
    if ($js !== '') {
        // defer, not an end-of-body tag: it runs after the document is parsed,
        // which is what an operator pasting "wait for the DOM" code expects,
        // and it keeps every injection point in one file instead of splitting
        // it across the header and the footer.
        $out[] = '<script defer>' . seo_code_escape($js, 'script') . '</script>';
    }

    return $out === [] ? '' : "\n    " . implode("\n    ", $out) . "\n";
}

/**
 * Stop a raw text element being closed from inside.
 *
 * "</script" and "</style" are the only byte sequences that terminate their
 * element, so replacing the slash with its escape is enough - and it is a
 * no-op for any code that does not contain them. In JavaScript "<\/script" is
 * an identical string literal; in CSS the sequence cannot appear in valid
 * syntax at all, so nothing legitimate changes meaning.
 */
function seo_code_escape(string $code, string $element): string
{
    return (string) preg_replace('~</(' . preg_quote($element, '~') . ')~i', '<\\\\/$1', $code);
}

/**
 * Why this head/body block cannot be accepted, or null when it is fine.
 *
 * Validation at the door rather than escaping on the way out, because these
 * two fields are markup on purpose: escaping them would make them useless.
 * What is checked is that the block is a list of head-shaped elements and
 * nothing else - no stray text, and nothing in the head slot that could paint
 * over the page it is supposed to describe.
 */
function seo_code_problem(string $markup, string $slot = 'head'): ?string
{
    $markup = trim($markup);
    if ($markup === '') {
        return null;
    }

    if (strlen($markup) > 20000) {
        return 'That block is over 20,000 characters. Put anything that long in a file and link to it.';
    }

    $allowed = $slot === 'head'
        ? ['script', 'style', 'link', 'meta', 'noscript', 'template']
        : ['script', 'noscript', 'div', 'span', 'img', 'iframe', 'template'];

    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    // A fragment, wrapped so the parser does not go hunting for <html>.
    $doc->loadHTML('<?xml encoding="utf-8"?><div id="sikfrag">' . $markup . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();

    $fragment = $doc->getElementById('sikfrag');
    if ($fragment === null) {
        return 'That block could not be parsed as HTML.';
    }

    foreach ($fragment->childNodes as $node) {
        if ($node->nodeType === XML_TEXT_NODE) {
            if (trim($node->textContent) !== '') {
                return 'Loose text is not allowed here - wrap everything in a tag.';
            }
            continue;
        }
        if ($node->nodeType === XML_COMMENT_NODE) {
            continue;
        }
        if ($node->nodeType !== XML_ELEMENT_NODE) {
            return 'Only elements and comments are allowed here.';
        }
        $tag = strtolower($node->nodeName);
        if (!in_array($tag, $allowed, true)) {
            return 'A <' . $tag . '> is not allowed in the ' . $slot . ' block. Allowed: '
                . implode(', ', $allowed) . '.';
        }
    }

    return null;
}

/**
 * Every record that injects code, newest change first.
 *
 * The owner's answer to "what is running on my shop?". It is one query because
 * all four code fields live in one table - which is most of the reason that
 * table exists.
 *
 * @return array<int,array<string,mixed>>
 */
function seo_injected_code_inventory(int $limit = 200): array
{
    try {
        $rows = Database::fetchAll(
            "SELECT m.*, a.`name` AS actor_name
               FROM `seo_entity_meta` m
               LEFT JOIN `admins` a ON a.`id` = m.`code_updated_by`
              WHERE COALESCE(NULLIF(TRIM(m.`custom_head`), ''), NULLIF(TRIM(m.`custom_body`), ''),
                             NULLIF(TRIM(m.`custom_css`), ''), NULLIF(TRIM(m.`custom_js`), '')) IS NOT NULL
              ORDER BY m.`code_updated_at` DESC, m.`id` DESC
              LIMIT " . max(1, min(1000, $limit))
        );
    } catch (Throwable $e) {
        return [];
    }

    foreach ($rows as $i => $row) {
        $type = (string) $row['entity_type'];
        $spec = seo_entity_type($type);
        $rows[$i]['type_label']  = (string) ($spec['label'] ?? $type);
        $rows[$i]['record_name'] = '';
        $rows[$i]['record_url']  = '';

        if ($spec !== null) {
            $record = Database::fetch(
                'SELECT `' . $spec['title_col'] . '` AS `t`, `slug` FROM `' . $spec['table'] . '` WHERE `id` = :id',
                ['id' => (int) $row['entity_id']]
            );
            if ($record !== null) {
                $rows[$i]['record_name'] = (string) $record['t'];
                $rows[$i]['record_url']  = seo_entity_url($type, (string) $record['slug']);
            }
        }

        $rows[$i]['bytes'] = [
            'custom_head' => strlen(trim((string) $row['custom_head'])),
            'custom_body' => strlen(trim((string) $row['custom_body'])),
            'custom_css'  => strlen(trim((string) $row['custom_css'])),
            'custom_js'   => strlen(trim((string) $row['custom_js'])),
        ];
    }

    return $rows;
}
// ---------------------------------------------------------------------------
//  An honest score
// ---------------------------------------------------------------------------

/**
 * Grade one record against the things that can actually be verified from here.
 *
 * WHAT THIS IS NOT
 * ----------------
 * It is not a prediction, a grade out of 100 weighted by invented importance,
 * or a promise about where the page will rank. Nobody can compute that from a
 * database row, and a number that cannot be traced back to a fact invites
 * work on whatever moves the number instead of whatever helps the shopper.
 *
 * WHAT IT IS
 * ----------
 * A checklist. Every item is a yes/no question this code can answer by
 * reading the record - is the title inside the length search results show, is
 * it used twice, does the focus keyword appear where a reader would expect it,
 * does every image have alt text, is there exactly one H1 - and every item
 * carries the next action in plain words.
 *
 * Three statuses and no others:
 *   pass  the check was run and the record satisfies it
 *   todo  the check was run and it does not
 *   na    the check could not be run (usually: no focus keyword to look for),
 *         and it is excluded from the count rather than scored as a failure
 *
 * @param array<string,mixed> $row  the entity row
 * @param array<string,mixed> $meta seo_entity_meta(), fetched when omitted
 * @return array{items:array<int,array<string,string>>, passed:int, applicable:int, percent:int}
 */
function seo_score(string $type, array $row, array $meta = []): array
{
    $spec = seo_entity_type($type);
    if ($spec === null) {
        return ['items' => [], 'passed' => 0, 'applicable' => 0, 'percent' => 0];
    }

    $id   = (int) ($row['id'] ?? 0);
    $meta = $meta !== [] ? $meta : seo_entity_meta($type, $id);

    $name        = trim((string) ($row[$spec['title_col']] ?? ''));
    $title       = trim((string) ($row['meta_title'] ?? '')) ?: $name;
    $description = trim((string) ($row['meta_description'] ?? ''));
    $body        = (string) ($row[$spec['body_col']] ?? '');
    $slug        = trim((string) ($row['slug'] ?? ''));
    $focus       = mb_strtolower(trim((string) ($row['focus_keyword'] ?? '')));
    $canonical   = trim((string) ($row['canonical_url'] ?? ''));

    $items = [];
    $add = static function (string $id, string $label, string $status, string $advice) use (&$items): void {
        $items[] = ['id' => $id, 'label' => $label, 'status' => $status, 'advice' => $advice];
    };

    // --- title ------------------------------------------------------------
    $titleLength = mb_strlen($title);
    $add(
        'title_length',
        'Title length (' . $titleLength . ' characters)',
        ($titleLength >= 30 && $titleLength <= 60) ? 'pass' : 'todo',
        $titleLength === 0
            ? 'Write a meta title. Without one the page is listed under whatever the crawler picks out of the body.'
            : ($titleLength < 30
                ? 'Aim for 30-60 characters. A short title wastes the space a search result gives you.'
                : 'Aim for 30-60 characters. Past about 60 the end is replaced with an ellipsis.')
    );

    // Uniqueness is a real query, not a guess: two records sharing a title are
    // two pages competing for the same search, which is the single most common
    // self-inflicted problem in a catalogue built from a template.
    if ($title === '' || $id <= 0) {
        $add('title_unique', 'Title is not shared with another record', 'na',
            'Fill in a meta title first, then this can be checked.');
    } else {
        $clashes = (int) Database::fetchColumn(
            'SELECT COUNT(*) FROM `' . $spec['table'] . '`
              WHERE `id` <> :id AND TRIM(COALESCE(`meta_title`, "")) = :t AND (' . $spec['live'] . ')',
            ['id' => $id, 't' => $title]
        );
        $add(
            'title_unique',
            'Title is not shared with another ' . mb_strtolower($spec['label']),
            $clashes === 0 ? 'pass' : 'todo',
            $clashes === 0
                ? 'No other published record uses this exact title.'
                : $clashes . ' other published record' . ($clashes === 1 ? '' : 's')
                    . ' use the same title. Give each one a title that says what is different about it.'
        );
    }

    // --- description ------------------------------------------------------
    $descLength = mb_strlen($description);
    $add(
        'description_length',
        'Description length (' . $descLength . ' characters)',
        ($descLength >= 70 && $descLength <= 160) ? 'pass' : 'todo',
        $descLength === 0
            ? 'Write a meta description. It is the sentence under the link, and it is what decides the click.'
            : ($descLength < 70
                ? 'Aim for 70-160 characters. There is room for a full sentence here.'
                : 'Aim for 70-160 characters. Past about 160 the rest is cut off.')
    );

    // --- focus keyword ----------------------------------------------------
    // Absent keyword means the four checks below cannot be run at all. They
    // are reported N/A and left out of the count, because "you did not set an
    // optional field" is not a fault to be graded down for.
    $intro = seo_first_paragraph($body);
    $alts  = seo_image_alts($body);
    if ($type === 'product' && $id > 0) {
        try {
            $alts = array_merge($alts, Database::fetchColumnAll(
                'SELECT `alt_text` FROM `product_images` WHERE `product_id` = :id',
                ['id' => $id]
            ));
        } catch (Throwable $e) {
            // no gallery table: the body alts are all there is to check
        }
    }

    if ($focus === '') {
        foreach ([
            'focus_in_title'       => 'Focus keyword appears in the title',
            'focus_in_description' => 'Focus keyword appears in the description',
            'focus_in_intro'       => 'Focus keyword appears in the first paragraph',
            'focus_in_alt'         => 'Focus keyword appears in an image alt text',
        ] as $checkId => $label) {
            $add($checkId, $label, 'na', 'Set a focus keyword above and this can be checked.');
        }
    } else {
        $has = static fn (string $haystack): bool => $haystack !== ''
            && mb_strpos(mb_strtolower($haystack), $focus) !== false;

        $add('focus_in_title', 'Focus keyword appears in the title',
            $has($title) ? 'pass' : 'todo',
            $has($title)
                ? 'The title contains the keyword.'
                : 'Work "' . $focus . '" into the meta title, near the front if it reads naturally.');

        $add('focus_in_description', 'Focus keyword appears in the description',
            $has($description) ? 'pass' : 'todo',
            $has($description)
                ? 'The description contains the keyword.'
                : 'Use "' . $focus . '" once in the meta description, in a sentence a person would actually read.');

        $add('focus_in_intro', 'Focus keyword appears in the first paragraph',
            $has($intro) ? 'pass' : 'todo',
            $has($intro)
                ? 'The opening paragraph contains the keyword.'
                : ($intro === ''
                    ? 'There is no opening paragraph yet. Write one that says what this is, using "' . $focus . '".'
                    : 'Mention "' . $focus . '" in the first paragraph, where a reader confirms they are in the right place.'));

        $altHit = false;
        foreach ($alts as $alt) {
            if ($has((string) $alt)) {
                $altHit = true;
                break;
            }
        }
        $add('focus_in_alt', 'Focus keyword appears in an image alt text',
            $altHit ? 'pass' : 'todo',
            $altHit
                ? 'At least one image describes itself using the keyword.'
                : 'Describe one image in words that include "' . $focus . '" - and describe it honestly, '
                    . 'because the alt text is what a screen reader says out loud.');
    }

    // --- structure --------------------------------------------------------
    $h1Count = preg_match_all('/<h1\b/i', $body);
    $add('single_h1', 'Exactly one H1 in the content',
        $h1Count === 1 ? 'pass' : ($h1Count === 0 ? 'na' : 'todo'),
        $h1Count === 1
            ? 'One H1, as it should be.'
            : ($h1Count === 0
                ? 'The content has no H1 of its own; the page template supplies the heading, which is fine.'
                : $h1Count . ' H1 headings found. Keep the first and make the rest H2s - a page has one subject.'));

    $missingAlt = 0;
    foreach (seo_image_tags($body) as $img) {
        if (trim((string) $img) === '') {
            $missingAlt++;
        }
    }
    $galleryMissing = 0;
    if ($type === 'product' && $id > 0) {
        try {
            $galleryMissing = (int) Database::fetchColumn(
                'SELECT COUNT(*) FROM `product_images`
                  WHERE `product_id` = :id AND (`alt_text` IS NULL OR TRIM(`alt_text`) = "")',
                ['id' => $id]
            );
        } catch (Throwable $e) {
            $galleryMissing = 0;
        }
    }
    $noAlt = $missingAlt + $galleryMissing;
    $add('image_alt', 'Every image has alt text',
        $noAlt === 0 ? 'pass' : 'todo',
        $noAlt === 0
            ? 'Every image here describes itself.'
            : $noAlt . ' image' . ($noAlt === 1 ? '' : 's') . ' with no alt text. '
                . 'A shopper using a screen reader gets silence where the picture is.');

    // --- canonical --------------------------------------------------------
    if ($canonical === '') {
        $add('canonical_sane', 'Canonical URL', 'pass',
            'No override set, so the page is its own canonical. That is the right answer for most records.');
    } else {
        $host = (string) parse_url($canonical, PHP_URL_HOST);
        $own  = (string) parse_url(SITE_URL, PHP_URL_HOST);
        $offSite = $host !== '' && strcasecmp($host, $own) !== 0;
        $selfUrl = seo_entity_url($type, $slug);
        $pointsAtSelf = $selfUrl !== '' && rtrim($canonical, '/') === rtrim($selfUrl, '/');

        $add('canonical_sane', 'Canonical URL points somewhere sensible',
            ($offSite || $pointsAtSelf) ? 'todo' : 'pass',
            $offSite
                ? 'This canonical points at ' . $host . ', which tells search engines to credit that site '
                    . 'instead of yours. Clear it unless you meant exactly that.'
                : ($pointsAtSelf
                    ? 'This canonical just points back at this page, which is already the default. Clear it.'
                    : 'Points at another page on this site, which is how you mark a duplicate.'));
    }

    // --- internal links ---------------------------------------------------
    $internal = seo_internal_link_count($body);
    $add('internal_links', 'Links to somewhere else on the site',
        $internal > 0 ? 'pass' : 'todo',
        $internal > 0
            ? $internal . ' internal link' . ($internal === 1 ? '' : 's') . ' in the content.'
            : 'Add a link to a related category or guide. It is how a reader (and a crawler) finds the next page.');

    // --- slug -------------------------------------------------------------
    $slugProblem = seo_slug_readability($slug);
    $add('slug_readable', 'Readable URL slug',
        $slugProblem === null ? 'pass' : 'todo',
        $slugProblem ?? 'The slug reads as words a person could type from memory.');

    $passed = 0;
    $applicable = 0;
    foreach ($items as $item) {
        if ($item['status'] === 'na') {
            continue;
        }
        $applicable++;
        if ($item['status'] === 'pass') {
            $passed++;
        }
    }

    return [
        'items'      => $items,
        'passed'     => $passed,
        'applicable' => $applicable,
        // A percentage of checks passed, which is a fact about this list and
        // nothing more. The UI labels it "N of M checks pass", never "SEO 72".
        'percent'    => $applicable === 0 ? 0 : (int) round($passed / $applicable * 100),
    ];
}

/** The first real paragraph of a body, as plain text. */
function seo_first_paragraph(string $html): string
{
    $html = trim($html);
    if ($html === '') {
        return '';
    }

    if (preg_match_all('~<p\b[^>]*>(.*?)</p>~is', $html, $matches)) {
        foreach ($matches[1] as $candidate) {
            $text = trim(html_entity_decode(strip_tags($candidate), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($text !== '') {
                return $text;
            }
        }
    }

    // Plain-text bodies have no <p> at all; the first line is the paragraph.
    $plain = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $first = preg_split('/\R{2,}|\R/', $plain)[0] ?? '';

    return trim((string) $first);
}

/**
 * The alt attribute of every <img> in a body, including the blank ones.
 *
 * The value is taken from the OUTER capture and unwrapped, rather than from a
 * per-quote-style group. With one group per style, the style that did not match
 * is still SET - to '' - whenever a later group participates, so the obvious
 * `$m[2] ?? $m[3]` read every single-quoted alt as empty: `??` tests for null
 * and an unused group is an empty string, not null. That turned real alt text
 * into a false "this image has no alt text" on the checklist and made the
 * focus-keyword-in-alt check impossible to pass on any body written with single
 * quotes - the score inventing work, which is the one thing it must never do.
 *
 * Unquoted values (alt=lamp) are read too. HTML allows them, editors emit them,
 * and an unread one is the same false to-do by another route.
 */
function seo_image_tags(string $html): array
{
    if (!preg_match_all('~<img\b[^>]*>~i', $html, $tags)) {
        return [];
    }

    $alts = [];
    foreach ($tags[0] as $tag) {
        if (!preg_match('~\balt\s*=\s*("[^"]*"|\'[^\']*\'|[^\s"\'>]+)~i', $tag, $m)) {
            $alts[] = '';
            continue;
        }

        $value = $m[1];
        $first = $value[0] ?? '';
        if (($first === '"' || $first === "'") && str_ends_with($value, $first)) {
            $value = substr($value, 1, -1);
        }

        $alts[] = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    return $alts;
}

/** Only the alt texts that are actually filled in. */
function seo_image_alts(string $html): array
{
    return array_values(array_filter(seo_image_tags($html), static fn (string $a): bool => trim($a) !== ''));
}

/** How many links in a body point back into this site. */
function seo_internal_link_count(string $html): int
{
    if (!preg_match_all('~<a\b[^>]*href\s*=\s*("([^"]*)"|\'([^\']*)\')~i', $html, $matches)) {
        return 0;
    }

    $own   = (string) parse_url(SITE_URL, PHP_URL_HOST);
    $count = 0;

    foreach ($matches[2] as $i => $quoted) {
        $href = trim($quoted !== '' ? $quoted : ($matches[3][$i] ?? ''));
        if ($href === '' || str_starts_with($href, '#')
            || str_starts_with($href, 'mailto:') || str_starts_with($href, 'tel:')) {
            continue;
        }
        $host = (string) parse_url($href, PHP_URL_HOST);
        if ($host === '' || strcasecmp($host, $own) === 0) {
            $count++;
        }
    }

    return $count;
}

/** Why this slug is hard to read, or null when it is fine. */
function seo_slug_readability(string $slug): ?string
{
    $slug = trim($slug);

    if ($slug === '') {
        return 'There is no slug yet. It is generated from the name when you save.';
    }
    if (strlen($slug) > 75) {
        return 'The slug is ' . strlen($slug) . ' characters. Trim it to the few words that identify this page.';
    }
    if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1) {
        return 'Use lower-case words separated by single hyphens - no spaces, underscores or capitals.';
    }
    if (preg_match('/^[0-9-]+$/', $slug) === 1) {
        return 'The slug is only digits. Put the words in it, so the URL says what the page is.';
    }
    if (substr_count($slug, '-') >= 8) {
        return 'The slug has ' . (substr_count($slug, '-') + 1) . ' words in it. Keep the ones that identify the page.';
    }

    return null;
}

// ---------------------------------------------------------------------------
//  Slug changes that do not break the web
// ---------------------------------------------------------------------------

/**
 * Names a slug may not take, whatever the entity type.
 *
 * These are either real directories at the web root, or the routing prefixes
 * the .htaccess rewrites own. A record that takes one of them is not "a page
 * with an awkward URL", it is a page the rewrite will never reach.
 */
const SEO_RESERVED_SLUGS = [
    'admin', 'api', 'assets', 'uploads', 'includes', 'config', 'database', 'bin', 'vendor',
    'product', 'category', 'brand', 'blog', 'page', 'combo',
    'index', 'sitemap', 'sitemap.xml', 'robots', 'robots.txt', 'favicon.ico',
];

/**
 * Why this slug cannot be used, or null when it is free.
 *
 * Deliberately a REFUSAL rather than unique_slug()'s "here is a free variant".
 * Silently saving `winter-lights-2` when the admin typed `winter-lights` is
 * how a shop ends up with two products one digit apart and nobody knowing
 * which one the campaign links to. The form says no and explains why.
 *
 * @param int|null $ignoreId the record being edited, which may keep its own slug
 */
function seo_slug_problem(string $type, string $slug, ?int $ignoreId = null): ?string
{
    $spec = seo_entity_type($type);
    if ($spec === null) {
        return 'Unknown record type.';
    }

    $slug = trim(mb_strtolower($slug));

    if ($slug === '') {
        return 'A slug is required.';
    }
    if (slugify($slug) === '') {
        return 'That slug has no letters or digits in it.';
    }
    if (slugify($slug) !== $slug) {
        return 'Use lower-case words separated by single hyphens. "' . slugify($slug) . '" would work.';
    }
    if (in_array($slug, SEO_RESERVED_SLUGS, true)) {
        return '"' . $slug . '" is a reserved address on this site and would never be reachable.';
    }

    $params = ['s' => $slug];
    $where  = '`slug` = :s';
    if ($ignoreId !== null && $ignoreId > 0) {
        $where .= ' AND `id` <> :id';
        $params['id'] = $ignoreId;
    }

    try {
        if (Database::exists($spec['table'], $where, $params)) {
            return 'Another ' . mb_strtolower($spec['label']) . ' already uses "' . $slug . '".';
        }
    } catch (Throwable $e) {
        return null;                       // never block a save on a broken probe
    }

    return null;
}

/**
 * Keep the old URL working after a slug change.
 *
 * Writes a 301 from the record's old path to its new one, through the same
 * `redirects` table 404.php already consults - there is no second redirect
 * mechanism and there must not be.
 *
 * Three things it refuses to do, each one a way this goes wrong in practice:
 *
 *   - nothing when the slug did not really change (a save that only touched
 *     the price would otherwise write a rule pointing a URL at itself);
 *   - nothing when $keepOld is false, because "let the old link die" is a
 *     legitimate choice an admin is allowed to make;
 *   - never leave A->B and B->A both active. Renaming a product and then
 *     renaming it back is ordinary, and the pair of rules it would otherwise
 *     leave behind is an infinite redirect loop that takes the URL off the
 *     web entirely. The opposing rule is retired first.
 *
 * @return int|null the redirect row id, or null when nothing was written
 */
function seo_slug_redirect(string $type, string $oldSlug, string $newSlug, bool $keepOld): ?int
{
    $oldSlug = trim($oldSlug);
    $newSlug = trim($newSlug);

    if (!$keepOld || $oldSlug === '' || $newSlug === '' || $oldSlug === $newSlug) {
        return null;
    }

    $from = seo_entity_path($type, $oldSlug);
    $to   = seo_entity_path($type, $newSlug);

    if ($from === '' || $to === '' || $from === $to) {
        return null;
    }

    try {
        // The loop guard. A rule that would send the new URL back to the old
        // one is retired, not deleted: the admin can see what happened.
        Database::query(
            "UPDATE `redirects` SET `status` = 'inactive'
              WHERE `source_path` = :newPath AND `target_path` = :oldPath AND `is_regex` = 0",
            ['newPath' => $to, 'oldPath' => $from]
        );

        // Any rule that pointed at the OLD path now points at a dead URL, so it
        // is re-aimed at the new one. Without this, a product renamed twice
        // leaves the first URL redirecting into a 404.
        //
        // :to appears twice in the SQL and therefore needs two placeholders:
        // with emulated prepares off, PDO binds a named marker exactly once and
        // the second occurrence raises HY093, which silently cost the whole
        // redirect (the catch below swallowed it and the rename broke the URL).
        Database::query(
            "UPDATE `redirects` SET `target_path` = :to_set
              WHERE `target_path` = :from AND `source_path` <> :to_guard AND `is_regex` = 0",
            ['to_set' => $to, 'from' => $from, 'to_guard' => $to]
        );

        $existing = Database::fetch(
            'SELECT `id` FROM `redirects` WHERE `source_path` = :from AND `is_regex` = 0 LIMIT 1',
            ['from' => $from]
        );

        if ($existing !== null) {
            Database::update('redirects', [
                'target_path' => $to,
                'status_code' => 301,
                'status'      => 'active',
            ], '`id` = :id', ['id' => (int) $existing['id']]);

            return (int) $existing['id'];
        }

        return Database::insert('redirects', [
            'source_path' => $from,
            'target_path' => $to,
            'status_code' => 301,
            'is_regex'    => 0,
            'notes'       => 'Slug change on ' . $type . ' "' . $newSlug . '"',
            'status'      => 'active',
        ]);
    } catch (Throwable $e) {
        // A redirect that could not be written is worth reporting, but it must
        // never turn a successful save into a 500 the admin cannot get past.
        error_log('seo_slug_redirect(' . $type . '): ' . $e->getMessage());
        return null;
    }
}

/**
 * Would storing source -> target close a redirect loop?
 *
 * seo_slug_redirect() already retires the one rule that would point the new URL
 * straight back at the old one, so a RENAME cannot build a loop. The manual
 * editor had no such guard, and it did not need a careless admin to break: two
 * individually-legal rules, saved months apart, are enough.
 *
 *     /a -> /b        perfectly sensible on its own
 *     /b -> /a        also perfectly sensible on its own
 *
 * Together they are an endless bounce. Every browser gives up with
 * ERR_TOO_MANY_REDIRECTS and Googlebot drops both URLs from the index - and
 * because each hop is a 404 that reaches 404.php first, the pair also burns two
 * database round trips per bounce for as long as anything keeps following it.
 *
 * Only literal, active rules are walked. A regex rule cannot be followed
 * statically - working out whether one pattern's output matches another's input
 * is not a thing this can decide - so the walk stops there rather than
 * guessing, and an off-site target ends the chain because it leaves the store.
 *
 * @param int $ignoreId the rule being edited, which is about to be replaced
 */
function seo_redirect_would_loop(string $source, string $target, int $ignoreId = 0): bool
{
    if ($source === '' || $target === '') {
        return false;
    }
    if ($source === $target) {
        return true;
    }
    // An absolute target leaves this site; nothing here can send it back.
    if (preg_match('~^https?://~i', $target)) {
        return false;
    }

    $seen = [$source => true];
    $hop  = $target;

    // Ten hops is far more chain than any real store has, and it bounds the
    // walk even if the table somehow already contains a cycle.
    for ($i = 0; $i < 10; $i++) {
        if (isset($seen[$hop])) {
            return true;                      // back somewhere we have been
        }
        $seen[$hop] = true;

        try {
            $next = Database::fetch(
                "SELECT `target_path` FROM `redirects`
                  WHERE `status` = 'active' AND `is_regex` = 0
                    AND `source_path` = :p AND `id` <> :ignore
                  LIMIT 1",
                ['p' => $hop, 'ignore' => $ignoreId]
            );
        } catch (Throwable $e) {
            return false;                     // cannot tell, so do not block
        }

        if ($next === null) {
            return false;                     // the chain ends somewhere real
        }

        $hop = (string) $next['target_path'];
        if (preg_match('~^https?://~i', $hop)) {
            return false;
        }
    }

    // Still going after ten hops: treat that as a loop rather than ship it.
    return true;
}

// ---------------------------------------------------------------------------
//  404 monitoring
// ---------------------------------------------------------------------------

/**
 * Record that somebody asked for a URL that is not there.
 *
 * WHAT IS STORED, AND WHAT DELIBERATELY IS NOT
 * --------------------------------------------
 * The path, where the link was, how many times, first and last seen. No IP, no
 * user agent, no visitor id, no session. A broken-link list does not need to
 * know who followed the link, and the moment it does it stops being a
 * maintenance tool and becomes personal data with a retention obligation.
 *
 * Both the path and the referrer have their query strings removed. A referrer
 * is the one field here that routinely carries other people's parameters -
 * utm tags, search terms, sometimes an email address in a badly built link -
 * and none of that is any of this table's business.
 *
 * BOUNDING IT
 * -----------
 * One row per path, so a crawler retrying the same dead URL a thousand times
 * costs one row and a counter. Past the row ceiling no NEW paths are inserted
 * (existing counters still tick), which is what stops a scanner walking
 * /aaa, /aab, /aac... from turning this into the largest table in the
 * database. The ceiling and the retention window are both admin settings.
 *
 * Every failure is swallowed. This is called from 404.php, and a page that is
 * already an error must not be able to become a 500.
 */
function seo_log_404(?string $path = null, ?string $referrer = null): void
{
    try {
        // Enforce the retention WINDOW, at most once a day. The ceiling below
        // only fires when the table is full, which on a quiet store is never -
        // so a row could sit here long past the 90 days the setting promises
        // the operator. A stated retention period that nothing enforces is not
        // a retention period. See seo_404_retention_sweep() for why this hangs
        // off the logger rather than off a cron entry.
        seo_404_retention_sweep();

        $path = $path ?? (string) ($_SERVER['REQUEST_URI'] ?? '');
        $path = (string) strtok($path, '?');

        // Control characters and NULs cannot reach the column: they are not a
        // URL anybody typed, and MySQL would either reject or silently mangle.
        $path = preg_replace('/[\x00-\x1F\x7F]+/', '', $path) ?? '';

        // The app-relative path, computed by the same helper seo_apply_redirect()
        // uses, so a rule written from the monitor matches the URL that produced
        // the row.
        $path = seo_app_path(trim($path));

        if ($path === '/' || strlen($path) < 2) {
            return;                       // the home page is not a broken link
        }

        // Nor is anything under the admin directory. With the login hidden,
        // EVERY /admin/... request lands on this page, so without this rule the
        // broken-link monitor fills up with exactly the scans that hiding the
        // login was meant to absorb - and once the row ceiling is reached those
        // scans push the operator's real broken links out of the list. A refused
        // admin request is a security event, and the security log already has it.
        $adminDir = '/' . basename(defined('ADMIN_PATH') ? ADMIN_PATH : 'admin');
        if ($path === $adminDir || str_starts_with($path, $adminDir . '/')) {
            return;
        }

        $path = mb_substr($path, 0, 255);

        $referrer = $referrer ?? (string) ($_SERVER['HTTP_REFERER'] ?? '');
        $referrer = trim((string) strtok((string) $referrer, '?'));
        if ($referrer !== '' && !preg_match('~^https?://~i', $referrer)) {
            $referrer = '';               // not a referrer we can make sense of
        }
        $referrer = $referrer === '' ? null : mb_substr($referrer, 0, 255);

        $existing = Database::fetch(
            'SELECT `id`, `referrer` FROM `seo_404_log` WHERE `path` = :p LIMIT 1',
            ['p' => $path]
        );

        if ($existing !== null) {
            // The stored referrer is the FIRST one seen, kept rather than
            // overwritten: the original source of the bad link is what the
            // admin needs, not whichever page linked to it most recently.
            Database::query(
                'UPDATE `seo_404_log`
                    SET `hits` = `hits` + 1,
                        `last_seen_at` = NOW(),
                        `referrer` = COALESCE(`referrer`, :r)
                  WHERE `id` = :id',
                ['r' => $referrer, 'id' => (int) $existing['id']]
            );
            return;
        }

        $ceiling = max(100, (int) setting('seo_404_max_rows', 5000));
        if ((int) Database::fetchColumn('SELECT COUNT(*) FROM `seo_404_log`') >= $ceiling) {
            // Full. Rather than grow without limit, make room by dropping the
            // least-evidenced entries, and only insert if that freed space.
            //
            // The ceiling is passed as $ceiling - 1 on purpose. seo_404_prune()
            // evicts COUNT - maxRows rows, so handing it the ceiling itself
            // computed 0 whenever the table sat at exactly the ceiling - which
            // is precisely the state this branch is reached in. Nothing was
            // ever freed, the insert was skipped, and the monitor stopped
            // recording ANY new broken link until something aged out 90 days
            // later. A scanner could put it in that state in about a minute,
            // and it looked from the admin screen like the store had simply
            // stopped having broken links. Asking for one slot below the
            // ceiling makes "make room" actually make room.
            if (seo_404_prune((int) setting('seo_404_retention_days', 90), $ceiling - 1) === 0) {
                return;
            }
        }

        Database::insert('seo_404_log', [
            'path'          => $path,
            'referrer'      => $referrer,
            'hits'          => 1,
            'status'        => 'open',
            'first_seen_at' => date('Y-m-d H:i:s'),
            'last_seen_at'  => date('Y-m-d H:i:s'),
        ]);
    } catch (Throwable $e) {
        // A 404 page must render even when the monitor is broken or absent.
    }
}

/**
 * Apply the retention window once a day, from whatever traffic happens by.
 *
 * WHY NOT A CRON ENTRY
 * --------------------
 * bin/prune-logs.php and the retention_tables() registry are the house
 * mechanism for tables that hold personal data, and this table deliberately
 * holds none - no IP, no user agent, no visitor id (see seo_log_404()). It has
 * its own window setting, its own row ceiling and a prune that understands
 * both, so registering it there would mean two mechanisms disagreeing about
 * the same table. It also means the window is enforced on a shared host whose
 * owner never set the cron up, which is the common case.
 *
 * The cost is one cached settings read per 404 and, once a day, a single
 * indexed DELETE. The marker is written BEFORE the sweep runs, so two requests
 * arriving together do not both do the work; losing a day's sweep to a crash
 * is of no consequence, because the next 404 picks it up.
 *
 * @return int rows removed (0 when it was not due)
 */
function seo_404_retention_sweep(): int
{
    try {
        $today = date('Y-m-d');
        if ((string) setting('seo_404_last_prune', '') === $today) {
            return 0;
        }

        setting_save('seo_404_last_prune', $today, 'seo', 'text');

        // Age only. The ceiling is the insert path's business, and applying it
        // here would delete live rows from a store that is simply busy.
        return seo_404_prune((int) setting('seo_404_retention_days', 90), PHP_INT_MAX);
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Bound the 404 table: drop what is stale, then what is oldest.
 *
 * Two passes, because "old" and "too many" are different problems. A store
 * with three broken links keeps them for as long as the retention window; a
 * store being scanned hits the ceiling long before anything is stale, and the
 * least recently seen rows are the ones worth losing.
 *
 * Rows an admin has marked `resolved` or `ignored` are pruned by age like any
 * other: the redirect they produced lives in `redirects`, so nothing is lost.
 *
 * @return int how many rows went
 */
function seo_404_prune(?int $days = null, ?int $maxRows = null): int
{
    $days    = max(1, $days ?? (int) setting('seo_404_retention_days', 90));

    // The 100-row floor guards the SETTING, not the argument. A caller that
    // names a target has already decided - the logger asks for one slot below
    // the ceiling precisely so a full table can be made room in - and clamping
    // that back up to 100 was what made "make room" a no-op on a small store.
    $maxRows = max(1, $maxRows ?? max(100, (int) setting('seo_404_max_rows', 5000)));

    try {
        $removed = Database::delete(
            'seo_404_log',
            '`last_seen_at` < DATE_SUB(NOW(), INTERVAL ' . (int) $days . ' DAY)'
        );

        $over = (int) Database::fetchColumn('SELECT COUNT(*) FROM `seo_404_log`') - $maxRows;
        if ($over > 0) {
            // LIMIT inside DELETE. PDO cannot bind a LIMIT with emulated
            // prepares off, so it is cast rather than bound.
            //
            // Ordered by HITS first, not by age. A scanner produces thousands
            // of paths it asks for exactly once; a genuinely broken link is
            // asked for again and again, by real visitors following a real
            // stale link, and its hit counter climbs. Evicting by age alone
            // therefore threw away the operator's oldest REAL findings to make
            // room for a bot's one-off noise - the monitor emptying itself of
            // exactly the rows it exists to keep. Least-evidenced first, and
            // age only to break the tie, keeps the signal and drops the noise.
            $removed += Database::query(
                'DELETE FROM `seo_404_log` ORDER BY `hits` ASC, `last_seen_at` ASC LIMIT ' . (int) $over
            )->rowCount();
        }

        return $removed;
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Images with no alt text, across the catalogue.
 *
 * product_images.alt_text is the only stored alt in the schema; a product's
 * main_image has no alt column and the storefront uses the product name for
 * it, which is a reasonable default and not a gap to report. So this is the
 * gallery, which is where the silence actually happens.
 *
 * @return array<int,array<string,mixed>>
 */
function seo_images_missing_alt(int $limit = 100, int $offset = 0): array
{
    try {
        return Database::fetchAll(
            'SELECT i.`id`, i.`image`, i.`product_id`, p.`name` AS product_name, p.`slug` AS product_slug
               FROM `product_images` i
               INNER JOIN `products` p ON p.`id` = i.`product_id`
              WHERE i.`alt_text` IS NULL OR TRIM(i.`alt_text`) = ""
              ORDER BY p.`name`, i.`sort_order`, i.`id`
              LIMIT ' . max(1, min(500, $limit)) . ' OFFSET ' . max(0, $offset)
        );
    } catch (Throwable $e) {
        return [];
    }
}

/** How many gallery images still have no alt text. */
function seo_images_missing_alt_count(): int
{
    try {
        return (int) Database::fetchColumn(
            'SELECT COUNT(*) FROM `product_images` WHERE `alt_text` IS NULL OR TRIM(`alt_text`) = ""'
        );
    } catch (Throwable $e) {
        return 0;
    }
}
