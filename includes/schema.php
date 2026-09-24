<?php
/**
 * ShopInnKart - Structured data (JSON-LD) manager.
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * The store already emitted JSON-LD: product.php builds a Product, faq.php an
 * FAQPage, blog-post.php a BlogPosting, and includes/seo.php prepends an
 * Organization to every page. What none of them had was an owner. There was
 * no way to switch a type off, nothing checked that a block carried the fields
 * the type requires, and nothing checked that what we claimed was true.
 *
 * That last one is not a nicety. products.rating_count on this store reads
 * 4,981 on one line and 3,753 on another while the `reviews` table is empty,
 * so every product page was publishing an aggregateRating with a review count
 * that has no review behind it. Structured data is a claim made to a search
 * engine in the store's name; an invented rating is the kind of claim that
 * costs a site its rich results altogether. So:
 *
 *   - a rating is emitted only when approved rows in `reviews` back it, and
 *     the numbers are recomputed from those rows rather than trusted;
 *   - a LocalBusiness needs a real address and phone;
 *   - an Offer needs a real price and currency;
 *   - anything that fails its type's required fields is dropped, not shipped.
 *
 * HOW IT HOOKS IN
 * ---------------
 * Pages keep calling seo_add_schema() exactly as before. seo_render() passes
 * the collected list through schema_prepare(), which filters it against the
 * switchboard, cleans and validates each block, verifies the claims that can
 * be verified, and appends the admin's own custom JSON-LD. Nothing else in the
 * storefront changed.
 *
 * NOT A CONSENT MATTER
 * --------------------
 * JSON-LD is server-rendered markup in our own page. It loads nothing, sets no
 * cookie and contacts no third party, so it is outside includes/consent.php on
 * purpose - the consent layer gates scripts that run in the visitor's browser.
 */

declare(strict_types=1);

/** Page kinds a schema type can be assigned to. */
const SCHEMA_CONTEXTS = [
    'home'      => 'Homepage',
    'product'   => 'Product pages',
    'category'  => 'Category pages',
    'brand'     => 'Brand pages',
    'listing'   => 'Listings (shop, deals, search)',
    'combo'     => 'Combo pages',
    'blog'      => 'Blog index',
    'blog_post' => 'Blog posts',
    'page'      => 'CMS pages',
    'faq'       => 'FAQ page',
    'other'     => 'Everything else',
];

/** Biggest custom JSON-LD block an admin may store, in bytes. */
const SCHEMA_CUSTOM_MAX_BYTES = 20480;

/** How deep a custom block may nest before we call it a bomb rather than data. */
const SCHEMA_CUSTOM_MAX_DEPTH = 12;

/** How many individual Review nodes a product page may carry. */
const SCHEMA_MAX_REVIEWS = 5;

// ===========================================================================
//  THE CATALOGUE
// ===========================================================================

/**
 * Every type the manager knows about.
 *
 * `types` are the schema.org @type values that map to this entry - one key can
 * own several, because OnlineStore is an Organization and BlogPosting is an
 * Article, and an operator should not need to know that to switch one off.
 *
 * `contexts` is where the type is allowed to appear at all; `default_contexts`
 * is where it ships switched on. '*' means every context in `contexts`.
 *
 * @return array<string,array<string,mixed>>
 */
function schema_catalogue(): array
{
    return [
        'organization' => [
            'label'    => 'Organization',
            'types'    => ['Organization', 'OnlineStore', 'Store'],
            'summary'  => 'Who the store is: name, logo, contact details and social profiles.',
            'contexts' => array_keys(SCHEMA_CONTEXTS),
            'default_contexts' => '*',
            'default_enabled'  => true,
            'required' => ['name', 'url'],
        ],
        'website' => [
            'label'    => 'WebSite',
            'types'    => ['WebSite'],
            'summary'  => 'The site itself, with the sitelinks search box (SearchAction).',
            'contexts' => ['home'],
            'default_contexts' => 'home',
            'default_enabled'  => true,
            'required' => ['name', 'url'],
        ],
        'webpage' => [
            'label'    => 'WebPage',
            'types'    => ['WebPage', 'AboutPage', 'ContactPage'],
            'summary'  => 'A plain content page - policies, about, contact.',
            'contexts' => ['page', 'other'],
            'default_contexts' => '*',
            'default_enabled'  => true,
            'required' => ['name', 'url'],
        ],
        'breadcrumb' => [
            'label'    => 'BreadcrumbList',
            'types'    => ['BreadcrumbList'],
            'summary'  => 'The trail shown under a search result instead of a bare URL.',
            'contexts' => array_keys(SCHEMA_CONTEXTS),
            'default_contexts' => '*',
            'default_enabled'  => true,
            'required' => ['itemListElement'],
        ],
        'product' => [
            'label'    => 'Product',
            'types'    => ['Product', 'ProductGroup'],
            'summary'  => 'Product with its Offer: price, currency, availability and condition.',
            'contexts' => ['product', 'combo'],
            'default_contexts' => '*',
            'default_enabled'  => true,
            'required' => ['name'],
        ],
        'rating' => [
            'label'    => 'AggregateRating',
            'types'    => ['AggregateRating'],
            'summary'  => 'The star rating on a product. Emitted only from approved reviews - '
                        . 'never from the rating columns on the product row.',
            'contexts' => ['product', 'combo'],
            'default_contexts' => '*',
            'default_enabled'  => true,
            'required' => ['ratingValue'],
        ],
        'review' => [
            'label'    => 'Review',
            'types'    => ['Review'],
            'summary'  => 'Individual approved reviews, attached to the product they are about.',
            'contexts' => ['product'],
            'default_contexts' => '*',
            'default_enabled'  => true,
            'required' => ['reviewRating'],
        ],
        'faq' => [
            'label'    => 'FAQPage',
            'types'    => ['FAQPage'],
            'summary'  => 'Questions and answers from the store\'s own FAQ rows.',
            'contexts' => ['faq', 'page', 'product'],
            'default_contexts' => 'faq',
            'default_enabled'  => true,
            'required' => ['mainEntity'],
        ],
        'article' => [
            'label'    => 'Article',
            'types'    => ['Article', 'BlogPosting', 'NewsArticle'],
            'summary'  => 'A blog post: headline, author, published and modified dates.',
            'contexts' => ['blog_post'],
            'default_contexts' => '*',
            'default_enabled'  => true,
            'required' => ['headline'],
        ],
        'local_business' => [
            'label'    => 'LocalBusiness',
            'types'    => ['LocalBusiness'],
            'summary'  => 'A physical shop. Needs a real street address and phone number, '
                        . 'so it stays silent until both are filled in on Settings > Store.',
            'contexts' => ['home', 'page'],
            'default_contexts' => 'home',
            'default_enabled'  => true,
            'required' => ['name', 'address', 'telephone'],
        ],
        'item_list' => [
            'label'    => 'ItemList',
            'types'    => ['ItemList'],
            'summary'  => 'The products on a listing page, in the order they are shown.',
            'contexts' => ['listing', 'category', 'brand', 'combo', 'blog'],
            'default_contexts' => '*',
            'default_enabled'  => true,
            'required' => ['itemListElement'],
        ],
    ];
}

/** The catalogue entry a schema.org @type belongs to, or '' when unknown. */
function schema_key_for_type(string $type): string
{
    static $map = null;

    if ($map === null) {
        $map = [];
        foreach (schema_catalogue() as $key => $entry) {
            foreach ((array) $entry['types'] as $schemaType) {
                $map[strtolower($schemaType)] = $key;
            }
        }
    }

    return $map[strtolower(trim($type))] ?? '';
}

// ===========================================================================
//  THE SWITCHBOARD
// ===========================================================================

/**
 * Catalogue merged with the operator's stored choices.
 *
 * Cached for five minutes and busted by admin_after_write(), because this is
 * read on every storefront page view. A missing table is not an error here: a
 * store that has not run the migration yet behaves exactly as it did before,
 * with every type on.
 *
 * @return array<string,array<string,mixed>>
 */
function schema_registry(): array
{
    static $registry = null;

    if ($registry !== null) {
        return $registry;
    }

    $stored = cache_remember('schema.registry', 300, static function (): array {
        try {
            $rows = Database::fetchAll('SELECT `type_key`, `enabled`, `contexts` FROM `seo_schema_types`');
        } catch (Throwable $e) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['type_key']] = [
                'enabled'  => (int) $row['enabled'] === 1,
                'contexts' => (string) $row['contexts'],
            ];
        }
        return $out;
    });

    $registry = [];
    foreach (schema_catalogue() as $key => $entry) {
        $row = $stored[$key] ?? null;
        $entry['enabled']  = $row === null ? (bool) $entry['default_enabled'] : (bool) $row['enabled'];
        $entry['assigned'] = schema_context_list(
            $row === null ? (string) $entry['default_contexts'] : (string) $row['contexts'],
            (array) $entry['contexts']
        );
        $entry['configured'] = $row !== null;
        $registry[$key] = $entry;
    }

    return $registry;
}

/**
 * Expand a stored contexts string against the contexts a type supports.
 *
 * '*' means all of them. Anything the type does not support is dropped rather
 * than kept: a stale assignment left behind by an edit to the catalogue must
 * not resurrect a type somewhere it was never meant to appear.
 *
 * @param string[] $supported
 * @return string[]
 */
function schema_context_list(string $stored, array $supported): array
{
    $stored = trim($stored);
    if ($stored === '*') {
        return array_values($supported);
    }

    $out = [];
    foreach (explode(',', $stored) as $context) {
        $context = trim($context);
        if ($context !== '' && in_array($context, $supported, true) && !in_array($context, $out, true)) {
            $out[] = $context;
        }
    }

    return $out;
}

/** Is this type switched on for this page kind? */
function schema_enabled(string $key, ?string $context = null): bool
{
    $entry = schema_registry()[$key] ?? null;
    if ($entry === null) {
        // Not a type we manage (a page built its own block). Nothing to switch
        // off, so it passes - it is still cleaned and validated below.
        return true;
    }
    if (!$entry['enabled']) {
        return false;
    }

    return in_array($context ?? schema_context(), (array) $entry['assigned'], true);
}

// ===========================================================================
//  WHERE WE ARE
// ===========================================================================

/** Override the detected page kind (for tests and for pages that know better). */
function schema_set_context(string $context, string $entityType = '', string $entitySlug = ''): void
{
    $GLOBALS['_schema_context'] = isset(SCHEMA_CONTEXTS[$context]) ? $context : 'other';
    if ($entityType !== '') {
        $GLOBALS['_schema_entity'] = ['type' => $entityType, 'slug' => $entitySlug];
    }
}

/**
 * The page kind of the current request.
 *
 * Derived from the script being run rather than from a call each page has to
 * remember to make: a page that forgets would silently lose every assignment
 * rule, which is a failure nobody would see until a crawl.
 */
function schema_context(): string
{
    if (isset($GLOBALS['_schema_context'])) {
        return (string) $GLOBALS['_schema_context'];
    }

    $script = strtolower(basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''), '.php'));

    $map = [
        'index'         => 'home',
        'product'       => 'product',
        'category'      => 'category',
        'brand'         => 'brand',
        'brands'        => 'listing',
        'shop'          => 'listing',
        'search'        => 'listing',
        'deals'         => 'listing',
        'new-arrivals'  => 'listing',
        'best-sellers'  => 'listing',
        'combos'        => 'listing',
        'combo'         => 'combo',
        'blog'          => 'blog',
        'blog-post'     => 'blog_post',
        'faq'           => 'faq',
        'page'          => 'page',
        'about'         => 'page',
        'contact'       => 'page',
        'terms'         => 'page',
        'warranty'      => 'page',
        'privacy-policy'  => 'page',
        'refund-policy'   => 'page',
        'return-policy'   => 'page',
        'shipping-policy' => 'page',
    ];

    return $GLOBALS['_schema_context'] = ($map[$script] ?? 'other');
}

/**
 * The entity this page is about: ['type' => 'product', 'slug' => '...'].
 *
 * Read from the rewrite parameter the page was routed with, so it costs no
 * query. Used only to target a custom block at one record.
 *
 * @return array{type:string,slug:string}
 */
function schema_entity(): array
{
    if (isset($GLOBALS['_schema_entity'])) {
        return $GLOBALS['_schema_entity'];
    }

    $context = schema_context();
    $types   = [
        'product'   => 'product',
        'category'  => 'category',
        'brand'     => 'brand',
        'blog_post' => 'blog_post',
        'page'      => 'page',
        'combo'     => 'combo',
    ];

    $slug = '';
    if (isset($types[$context])) {
        $raw = $_GET['slug'] ?? '';
        if (is_string($raw)) {
            $slug = strtolower(trim($raw));
        }
    }

    return $GLOBALS['_schema_entity'] = ['type' => $types[$context] ?? '', 'slug' => $slug];
}

// ===========================================================================
//  CLEANING AND VALIDATION
// ===========================================================================

/**
 * Keys that describe a node without saying anything about the thing itself.
 *
 * `@id` is deliberately NOT here: a node that is only an `@id` is a reference
 * to another node, which is the one legitimate way to write a marker-only
 * object in JSON-LD.
 */
const SCHEMA_MARKER_KEYS = ['@type', '@context'];

/**
 * Drop everything blank, recursively.
 *
 * A declared-but-empty property is worse than an absent one: a validator reads
 * `"telephone": ""` as a claim that the phone number is the empty string, and
 * Google's own guidance is to omit what you do not have.
 *
 * That applies one level further down than it first looks. Once the blank
 * fields of a nested node are gone, what can be left is the label and nothing
 * else - `"address": {"@type": "PostalAddress"}` - and that is not a neutral
 * omission either: it is still a PostalAddress node, so a validator reads it
 * as an address the store has declared and failed to fill in. An admin who
 * writes a custom LocalBusiness block and leaves the street fields empty gets
 * exactly that, so a hollow node is dropped rather than published.
 *
 * Only nested nodes are dropped. The block itself is left for
 * schema_validate(), which names the missing required fields instead of
 * silently returning nothing.
 *
 * @param mixed $value
 * @param int   $depth Recursion depth; 0 is the block itself.
 * @return mixed
 */
function schema_clean($value, int $depth = 0)
{
    if (is_array($value)) {
        $isList = array_is_list($value);
        $out    = [];

        foreach ($value as $key => $item) {
            $item = schema_clean($item, $depth + 1);
            if ($item === null || $item === '' || $item === []) {
                continue;
            }
            if ($isList) {
                $out[] = $item;
            } else {
                $out[$key] = $item;
            }
        }

        // A nested node left holding only its label describes nothing. Lists
        // are exempt - a list is not a claim about anything by itself.
        if ($depth > 0 && !$isList && $out !== []
            && array_diff(array_keys($out), SCHEMA_MARKER_KEYS) === []) {
            return null;
        }

        return $out;
    }

    if (is_string($value)) {
        // Control characters would survive JSON encoding as \u0000 escapes and
        // make a validator reject the whole block.
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value) ?? $value;
        return trim($value);
    }

    return $value;
}

/**
 * Everything wrong with one block, as sentences an admin can act on.
 *
 * Shape first (@context, @type), then the fields the type requires, then the
 * claims that can be checked against themselves - a rating outside its own
 * best/worst bounds, an Offer with a price but no currency, a BreadcrumbList
 * whose positions do not count up.
 *
 * @return string[]
 */
function schema_validate(array $schema): array
{
    $problems = [];

    $type = (string) ($schema['@type'] ?? '');
    if ($type === '') {
        $problems[] = 'No @type, so a crawler cannot tell what this describes.';
        return $problems;   // nothing else can be judged without it
    }
    if (!isset($schema['@context']) && !isset($schema['@graph'])) {
        $problems[] = 'No @context: it must be https://schema.org.';
    } elseif (isset($schema['@context']) && stripos((string) $schema['@context'], 'schema.org') === false) {
        $problems[] = '@context is not schema.org.';
    }

    $key      = schema_key_for_type($type);
    $required = (array) (schema_catalogue()[$key]['required'] ?? []);
    foreach ($required as $field) {
        if (!isset($schema[$field]) || $schema[$field] === '' || $schema[$field] === []) {
            $problems[] = $type . ' is missing ' . $field . ', which the type requires.';
        }
    }

    if (isset($schema['offers']) && is_array($schema['offers'])) {
        foreach (array_is_list($schema['offers']) ? $schema['offers'] : [$schema['offers']] as $offer) {
            if (!is_array($offer)) {
                continue;
            }
            if (!isset($offer['price']) || $offer['price'] === '') {
                $problems[] = 'An Offer has no price.';
            }
            if (!isset($offer['priceCurrency']) || $offer['priceCurrency'] === '') {
                $problems[] = 'An Offer has no priceCurrency, so its price means nothing.';
            }
            if (isset($offer['availability'])
                && stripos((string) $offer['availability'], 'schema.org/') === false) {
                $problems[] = 'Offer availability must be a schema.org URL such as https://schema.org/InStock.';
            }
        }
    }

    if (isset($schema['aggregateRating']) && is_array($schema['aggregateRating'])) {
        $rating = $schema['aggregateRating'];
        $value  = (float) ($rating['ratingValue'] ?? 0);
        $best   = (float) ($rating['bestRating'] ?? 5);
        $worst  = (float) ($rating['worstRating'] ?? 1);
        $count  = (int) ($rating['reviewCount'] ?? $rating['ratingCount'] ?? 0);

        if ($count < 1) {
            $problems[] = 'aggregateRating has no reviewCount, so there is nothing behind the stars.';
        }
        if ($value < $worst || $value > $best) {
            $problems[] = 'aggregateRating ratingValue ' . $value . ' is outside its own '
                . $worst . '-' . $best . ' range.';
        }
    }

    foreach (['itemListElement' => 'BreadcrumbList/ItemList', 'mainEntity' => 'FAQPage'] as $field => $owner) {
        if (isset($schema[$field]) && is_array($schema[$field]) && $schema[$field] === []) {
            $problems[] = $owner . ' has an empty ' . $field . '.';
        }
    }

    if (strcasecmp($type, 'BreadcrumbList') === 0 && isset($schema['itemListElement'])) {
        $position = 0;
        foreach ((array) $schema['itemListElement'] as $item) {
            $position++;
            if (!is_array($item)) {
                continue;
            }
            if ((int) ($item['position'] ?? 0) !== $position) {
                $problems[] = 'Breadcrumb positions must run 1, 2, 3 in order.';
                break;
            }
            if (trim((string) ($item['name'] ?? '')) === '') {
                $problems[] = 'A breadcrumb step has no name.';
                break;
            }
        }
    }

    if (strcasecmp($type, 'FAQPage') === 0) {
        foreach ((array) ($schema['mainEntity'] ?? []) as $question) {
            if (!is_array($question)) {
                continue;
            }
            $answer = $question['acceptedAnswer']['text'] ?? '';
            if (trim((string) ($question['name'] ?? '')) === '' || trim((string) $answer) === '') {
                $problems[] = 'A FAQ entry is missing its question or its answer.';
                break;
            }
        }
    }

    return $problems;
}

// ===========================================================================
//  CLAIMS WE CHECK BEFORE MAKING THEM
// ===========================================================================

/**
 * What the `reviews` table actually says about one product.
 *
 * Approved rows only, and the average is recomputed here rather than read from
 * products.rating_avg: the column is maintained by the review screens and by
 * the seed data, and on this store it disagrees with reality by thousands.
 *
 * Keyed by SKU because that is the field the Product block already carries, so
 * no extra plumbing is needed between the page and this check.
 *
 * @return array{count:int,avg:float,reviews:array<int,array<string,mixed>>}
 */
function schema_real_reviews(string $sku): array
{
    static $memo = [];

    $sku = trim($sku);
    if ($sku === '') {
        return ['count' => 0, 'avg' => 0.0, 'reviews' => []];
    }
    if (isset($memo[$sku])) {
        return $memo[$sku];
    }

    try {
        $summary = Database::fetch(
            "SELECT COUNT(*) AS n, AVG(r.`rating`) AS avg_rating
               FROM `reviews` r
               JOIN `products` p ON p.`id` = r.`product_id`
              WHERE p.`sku` = :sku AND r.`status` = 'approved'",
            ['sku' => $sku]
        );

        $count = (int) ($summary['n'] ?? 0);
        $rows  = $count > 0 ? Database::fetchAll(
            "SELECT r.`rating`, r.`title`, r.`comment`, r.`customer_name`, r.`created_at`
               FROM `reviews` r
               JOIN `products` p ON p.`id` = r.`product_id`
              WHERE p.`sku` = :sku AND r.`status` = 'approved'
              ORDER BY r.`created_at` DESC
              LIMIT " . SCHEMA_MAX_REVIEWS,
            ['sku' => $sku]
        ) : [];
    } catch (Throwable $e) {
        // No reviews table is the same answer as no reviews: say nothing.
        return $memo[$sku] = ['count' => 0, 'avg' => 0.0, 'reviews' => []];
    }

    return $memo[$sku] = [
        'count'   => $count,
        'avg'     => $count > 0 ? round((float) $summary['avg_rating'], 1) : 0.0,
        'reviews' => $rows,
    ];
}

/**
 * Make a Product block tell the truth about its reviews.
 *
 * Three outcomes, and only the first one publishes stars:
 *   - approved reviews exist: the rating is rewritten from them, and up to
 *     five Review nodes are attached when that type is on;
 *   - none exist: aggregateRating and review are removed;
 *   - the rating type is switched off: removed regardless.
 */
function schema_apply_real_reviews(array $product, ?string $context = null): array
{
    $sku  = (string) ($product['sku'] ?? '');
    $real = schema_real_reviews($sku);

    unset($product['aggregateRating'], $product['review']);

    if ($real['count'] < 1 || !schema_enabled('rating', $context)) {
        return $product;
    }

    $product['aggregateRating'] = [
        '@type'       => 'AggregateRating',
        'ratingValue' => number_format($real['avg'], 1, '.', ''),
        'reviewCount' => $real['count'],
        'bestRating'  => 5,
        'worstRating' => 1,
    ];

    if (!schema_enabled('review', $context)) {
        return $product;
    }

    $reviews = [];
    foreach ($real['reviews'] as $row) {
        $rating = (float) $row['rating'];
        if ($rating <= 0) {
            continue;                      // a review without a rating is not one
        }
        $author = trim((string) ($row['customer_name'] ?? ''));
        $reviews[] = array_filter([
            '@type'        => 'Review',
            'reviewRating' => [
                '@type'       => 'Rating',
                'ratingValue' => number_format($rating, 1, '.', ''),
                'bestRating'  => 5,
                'worstRating' => 1,
            ],
            'author'        => $author !== '' ? ['@type' => 'Person', 'name' => $author] : null,
            'name'          => trim((string) ($row['title'] ?? '')) ?: null,
            'reviewBody'    => str_limit(strip_tags((string) ($row['comment'] ?? '')), 500, ''),
            'datePublished' => !empty($row['created_at'])
                ? date('Y-m-d', (int) strtotime((string) $row['created_at']))
                : null,
        ], static fn ($v) => $v !== null && $v !== '');
    }

    if ($reviews !== []) {
        $product['review'] = $reviews;
    }

    return $product;
}

// ===========================================================================
//  ADMIN-WRITTEN BLOCKS
// ===========================================================================

/**
 * Check a custom JSON-LD block before it is ever stored.
 *
 * Nothing here executes the value - it is printed as data through e_json() -
 * but it IS published in the store's name, so it is held to the same bar as
 * the blocks we build: parseable, an object (or a list of objects), not a
 * nesting bomb, not enormous, and carrying an @type a crawler can use.
 *
 * @return array{ok:bool,error:string,decoded:array}
 */
function schema_custom_validate(string $json): array
{
    $json = trim($json);

    if ($json === '') {
        return ['ok' => false, 'error' => 'The JSON-LD is empty.', 'decoded' => []];
    }
    if (strlen($json) > SCHEMA_CUSTOM_MAX_BYTES) {
        return [
            'ok'      => false,
            'error'   => 'That block is ' . number_format(strlen($json) / 1024, 1) . ' KB; the limit is '
                       . (SCHEMA_CUSTOM_MAX_BYTES / 1024) . ' KB.',
            'decoded' => [],
        ];
    }

    // The depth argument is the guard: json_decode() recurses, so a deeply
    // nested paste is a stack overflow rather than a validation failure.
    $decoded = json_decode($json, true, SCHEMA_CUSTOM_MAX_DEPTH);
    if ($decoded === null || json_last_error() !== JSON_ERROR_NONE) {
        return ['ok' => false, 'error' => 'That is not valid JSON: ' . json_last_error_msg() . '.', 'decoded' => []];
    }
    if (!is_array($decoded) || $decoded === []) {
        return ['ok' => false, 'error' => 'JSON-LD must be an object, or a list of objects.', 'decoded' => []];
    }

    $blocks = array_is_list($decoded) ? $decoded : [$decoded];
    foreach ($blocks as $block) {
        if (!is_array($block) || array_is_list($block)) {
            return ['ok' => false, 'error' => 'Every entry must be a JSON object with an @type.', 'decoded' => []];
        }
        if (!isset($block['@type']) && !isset($block['@graph'])) {
            return ['ok' => false, 'error' => 'Every block needs an @type (or an @graph of typed nodes).', 'decoded' => []];
        }
    }

    return ['ok' => true, 'error' => '', 'decoded' => $decoded];
}

/**
 * Active custom blocks for this page.
 *
 * A row targets either a context (every product page) or one record
 * (entity_type + entity_slug). Both are matched here; the row that names a
 * record still has to be active, and its context list still has to include
 * this page kind when it has one.
 *
 * @return array<int,array<string,mixed>>
 */
function schema_custom_blocks(?string $context = null, ?array $entity = null): array
{
    $context = $context ?? schema_context();
    $entity  = $entity ?? schema_entity();

    $rows = cache_remember('schema.custom.active', 300, static function (): array {
        try {
            return Database::fetchAll(
                "SELECT `id`, `name`, `json_ld`, `contexts`, `entity_type`, `entity_slug`
                   FROM `seo_schema_custom` WHERE `status` = 'active' ORDER BY `id`"
            );
        } catch (Throwable $e) {
            return [];
        }
    });

    $out = [];
    foreach ($rows as $row) {
        $targetType = trim((string) ($row['entity_type'] ?? ''));
        $targetSlug = trim((string) ($row['entity_slug'] ?? ''));

        if ($targetType !== '') {
            if ($targetType !== $entity['type']) {
                continue;
            }
            if ($targetSlug !== '' && $targetSlug !== $entity['slug']) {
                continue;
            }
        } else {
            $contexts = schema_context_list((string) $row['contexts'], array_keys(SCHEMA_CONTEXTS));
            if (!in_array($context, $contexts, true)) {
                continue;
            }
        }

        $check = schema_custom_validate((string) $row['json_ld']);
        if (!$check['ok']) {
            // A row that no longer parses is skipped, not emitted: a malformed
            // block invalidates the whole <script> element for a crawler.
            continue;
        }

        foreach (array_is_list($check['decoded']) ? $check['decoded'] : [$check['decoded']] as $block) {
            $out[] = $block;
        }
    }

    return $out;
}

// ===========================================================================
//  THE HOOK
// ===========================================================================

/**
 * Filter, verify and complete the JSON-LD a page collected.
 *
 * Called once, from seo_render(). Every block goes through the same four
 * steps - switchboard, clean, verify, validate - and anything that fails is
 * dropped with a reason recorded in $GLOBALS['_schema_dropped'], which the
 * admin preview reads back.
 *
 * @param array<int,array> $schemas
 * @return array<int,array>
 */
function schema_prepare(array $schemas, ?string $context = null): array
{
    $context = $context ?? schema_context();

    $GLOBALS['_schema_dropped'] = [];
    $out  = [];
    $seen = [];

    $drop = static function (array $schema, string $why): void {
        $GLOBALS['_schema_dropped'][] = [
            'type'   => (string) ($schema['@type'] ?? 'unknown'),
            'reason' => $why,
        ];
    };

    foreach ($schemas as $schema) {
        if (!is_array($schema) || $schema === []) {
            continue;
        }

        $type = (string) ($schema['@type'] ?? '');
        $key  = schema_key_for_type($type);

        if ($key !== '' && !schema_enabled($key, $context)) {
            $drop($schema, 'switched off for ' . $context . ' pages');
            continue;
        }

        // Claims that can be checked are checked before anything is published.
        if ($key === 'product') {
            $schema = schema_apply_real_reviews($schema, $context);
        }
        if ($key === 'local_business'
            && (trim((string) setting('store_address', '')) === '' || trim((string) setting('store_phone', '')) === '')) {
            $drop($schema, 'no street address and phone on Settings > Store');
            continue;
        }

        $schema = schema_clean($schema);
        if (!is_array($schema) || $schema === []) {
            continue;
        }

        $problems = schema_validate($schema);
        if ($problems !== []) {
            $drop($schema, $problems[0]);
            continue;
        }

        // One node per subject. The Organization is prepended on every page and
        // a breadcrumb trail can be added twice by a page and its layout; two
        // identical nodes are not an error but they are noise in every crawl.
        $signature = strtolower($type) . '|' . (string) ($schema['url'] ?? $schema['name'] ?? count($schema));
        if (isset($seen[$signature])) {
            continue;
        }
        $seen[$signature] = true;

        $out[] = $schema;
    }

    foreach (schema_custom_blocks($context) as $custom) {
        $custom = schema_clean($custom);
        if (is_array($custom) && $custom !== []) {
            $out[] = $custom;
        }
    }

    return $out;
}

/** Why blocks were left out of the last schema_prepare() call. */
function schema_dropped(): array
{
    return $GLOBALS['_schema_dropped'] ?? [];
}

// ===========================================================================
//  PREVIEW
// ===========================================================================

/**
 * A real example of one type, built from the store's own data.
 *
 * Nothing here is invented: if there is no product, there is no Product
 * preview, and the admin screen says so rather than showing a specimen that
 * would never be published.
 *
 * @return array{schema:?array,source:string,problems:string[]}
 */
function schema_sample(string $key): array
{
    $none = static fn (string $why): array => ['schema' => null, 'source' => $why, 'problems' => []];

    try {
        switch ($key) {
            case 'organization':
                $schema = seo_organization_schema();
                $source = 'Settings > General and Settings > Store';
                break;

            case 'website':
                $schema = seo_website_schema();
                $source = 'the store name and the search page';
                break;

            case 'local_business':
                $schema = seo_local_business_schema();
                if ($schema === null) {
                    return $none('no street address or phone number on Settings > Store, so nothing is emitted');
                }
                $source = 'Settings > Store';
                break;

            case 'product':
            case 'rating':
            case 'review':
                $row = Database::fetch(
                    'SELECT * FROM `products` WHERE ' . product_visible_sql('products') . ' ORDER BY `updated_at` DESC LIMIT 1'
                );
                if ($row === null) {
                    return $none('no published product to build one from');
                }
                $row['final_price'] = $row['sale_price'] > 0 ? $row['sale_price'] : $row['price'];
                $schema = schema_apply_real_reviews(seo_product_schema($row));
                if ($key === 'rating') {
                    $schema = $schema['aggregateRating'] ?? null;
                    if ($schema === null) {
                        return $none('no approved review on any product, so no rating is published');
                    }
                    $schema = ['@context' => 'https://schema.org'] + $schema;
                } elseif ($key === 'review') {
                    $reviews = $schema['review'] ?? [];
                    if ($reviews === []) {
                        return $none('no approved review on any product');
                    }
                    $schema = ['@context' => 'https://schema.org'] + $reviews[0];
                }
                $source = 'product "' . (string) $row['name'] . '"';
                break;

            case 'breadcrumb':
                $schema = seo_breadcrumb_schema([
                    ['label' => 'Home', 'url' => url()],
                    ['label' => 'Shop', 'url' => url('shop')],
                ]);
                $source = 'the trail each page renders';
                break;

            case 'faq':
                $faqs = Database::fetchAll(
                    "SELECT `question`, `answer` FROM `faqs` WHERE `status` = 'active' ORDER BY `sort_order` LIMIT 3"
                );
                if ($faqs === []) {
                    return $none('no active FAQ rows');
                }
                $schema = seo_faq_schema($faqs);
                $source = count($faqs) . ' of the store\'s FAQ rows';
                break;

            case 'article':
                $post = Database::fetch(
                    'SELECT * FROM `blog_posts` p WHERE ' . blog_visible_sql('p') . ' ORDER BY `published_at` DESC LIMIT 1'
                );
                if ($post === null) {
                    return $none('no published blog post');
                }
                $schema = seo_article_schema($post);
                $source = 'post "' . (string) $post['title'] . '"';
                break;

            case 'webpage':
                $page = Database::fetch("SELECT * FROM `pages` WHERE `status` = 'active' ORDER BY `sort_order` LIMIT 1");
                if ($page === null) {
                    return $none('no active CMS page');
                }
                $schema = seo_webpage_schema($page, cms_page_canonical((string) $page['slug']));
                $source = 'page "' . (string) $page['title'] . '"';
                break;

            case 'item_list':
                $products = Database::fetchAll(
                    'SELECT `name`, `slug` FROM `products` WHERE ' . product_visible_sql('products') . ' LIMIT 3'
                );
                if ($products === []) {
                    return $none('no published product');
                }
                $schema = seo_item_list_schema($products, 'Shop All Products');
                $source = 'the first products on the shop listing';
                break;

            default:
                return $none('no preview for this type');
        }
    } catch (Throwable $e) {
        return $none('could not be built: ' . $e->getMessage());
    }

    $schema = schema_clean($schema);

    return [
        'schema'   => is_array($schema) && $schema !== [] ? $schema : null,
        'source'   => $source,
        'problems' => is_array($schema) ? schema_validate($schema) : [],
    ];
}

/** Google's Rich Results test, pointed at one of our own URLs. */
function schema_rich_results_url(string $pageUrl): string
{
    return 'https://search.google.com/test/rich-results?url=' . rawurlencode($pageUrl);
}

/** schema.org's own validator, which takes pasted markup rather than a URL. */
function schema_validator_url(): string
{
    return 'https://validator.schema.org/';
}
