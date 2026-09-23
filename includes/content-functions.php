<?php
/**
 * ShopInnKart - CMS pages, FAQ and blog helpers.
 *
 * page.php and every fixed policy route render through cms_page_render(),
 * so a content page has exactly one implementation.
 */

declare(strict_types=1);

// ===========================================================================
//  CMS pages
// ===========================================================================

/**
 * Slugs that also have a hard route of their own.
 * The fixed route is the canonical URL for that content, so /page/about-us
 * never competes with /about in search results.
 */
function cms_fixed_routes(): array
{
    return [
        'about-us'         => 'about',
        'contact-us'       => 'contact',
        'faq'              => 'faq',
        'shipping-policy'  => 'shipping-policy',
        'return-policy'    => 'return-policy',
        'refund-policy'    => 'refund-policy',
        'privacy-policy'   => 'privacy-policy',
        'terms-conditions' => 'terms',
        'warranty-policy'  => 'warranty',
    ];
}

/** Canonical URL for a CMS slug. */
function cms_page_canonical(string $slug): string
{
    $routes = cms_fixed_routes();
    return isset($routes[$slug]) ? url($routes[$slug]) : page_url($slug);
}

/**
 * Active CMS page row, or null when it is missing or switched off.
 *
 * Tokens are resolved here rather than at each echo, so every consumer -
 * page.php, the fixed policy routes, contact.php, the SEO head - sees the same
 * finished text and none of them can forget to substitute.
 */
function cms_page(string $slug): ?array
{
    $page = Database::fetch(
        "SELECT * FROM `pages` WHERE `slug` = :slug AND `status` = 'active' LIMIT 1",
        ['slug' => $slug]
    );

    if ($page === null) {
        return null;
    }

    $context = [
        'last_updated' => !empty($page['updated_at']) ? format_date($page['updated_at']) : '',
    ];

    // The body is spliced into markup, so values are escaped. Title and meta
    // are plain-text fields their callers already run through e().
    $page['content'] = content_apply_tokens($page['content'] ?? null, $context, true);
    foreach (['title', 'meta_title', 'meta_description'] as $field) {
        $page[$field] = content_apply_tokens($page[$field] ?? null, $context, false);
    }

    return $page;
}

/**
 * URL for a stored image, or null when the file is not actually on disk.
 * img_url() substitutes a placeholder, which is right for a product tile and
 * wrong for a decorative banner - there we would rather show nothing.
 */
function content_image(?string $path): ?string
{
    $path = trim((string) $path);
    if ($path === '') {
        return null;
    }
    if (preg_match('#^(https?:)?//#i', $path) === 1) {
        return $path;
    }

    $relative = ltrim($path, '/');
    return is_file(ROOT_PATH . '/' . $relative) ? url($relative) : null;
}

/**
 * The store facts a policy page is allowed to state, read from `settings`.
 *
 * A legal document must never assert a registration, an address, a tax number
 * or a contact channel that the store owner has not actually configured, and a
 * seeded policy that hard-codes them goes stale the moment an admin edits
 * Settings. So every store-specific value in the seeded copy is a {{token}}
 * resolved here. A key with no configured value resolves to an empty string,
 * and the {{#key}}...{{/key}} section wrapped around it is dropped whole - the
 * claim disappears rather than rendering as a blank or a placeholder.
 *
 * Keys whose value is only ever '1' or '' are flags: they exist to gate a
 * section, not to be printed.
 */
function content_tokens(): array
{
    static $tokens = null;
    if ($tokens !== null) {
        return $tokens;
    }

    $text = static fn (string $key): string => trim((string) setting($key, ''));
    $flag = static fn (string $key): string => setting_bool($key) ? '1' : '';

    // A percentage reads as "18", not "18.00", but must survive 12.5.
    $rate = (string) setting_float('default_tax_rate', 18.0);

    // The COD figures a policy page quotes are the ones checkout will actually
    // apply, not a second copy of them kept on another screen. The fee is the
    // payment_methods row's extra_charge (Settings > Payment) - the only figure
    // create_order() charges - and the ceiling is whichever limit bites first,
    // that row's max_amount or settings.cod_max_amount. settings.cod_charge
    // survives only as the fallback for a store with no active COD row.
    $codMethod = Database::fetch(
        "SELECT `extra_charge`, `max_amount` FROM `payment_methods`
          WHERE `code` = :code AND `status` = 'active' LIMIT 1",
        ['code' => PAYMENT_METHOD_COD]
    );
    $codFee = $codMethod !== null ? (float) $codMethod['extra_charge'] : setting_float('cod_charge', 0);
    $codCap = setting_float('cod_max_amount', 50000);
    $rowCap = $codMethod === null || $codMethod['max_amount'] === null ? 0.0 : (float) $codMethod['max_amount'];
    if ($rowCap > 0 && ($codCap <= 0 || $rowCap < $codCap)) {
        $codCap = $rowCap;
    }

    $tokens = [
        'store_name'     => $text('store_name') !== '' ? $text('store_name') : SITE_NAME,
        'store_email'    => $text('store_email'),
        'store_phone'    => $text('store_phone'),
        'store_address'  => $text('store_address'),
        'business_hours' => $text('business_hours'),
        'gst_number'     => $text('gst_number'),
        'order_prefix'   => $text('order_prefix'),
        'invoice_prefix' => $text('invoice_prefix'),
        'currency_symbol' => (string) setting('currency_symbol', CURRENCY_SYMBOL),

        // Defaults mirror the ones the order and cart code already uses, so a
        // policy can never quote a window the checkout does not enforce.
        'return_window_days'  => (string) setting_int('return_window_days', 7),
        'cancel_window_hours' => (string) setting_int('cancel_window_hours', 24),
        'delivery_days'       => (string) setting_int('default_delivery_days', 4),
        'tax_rate'            => $rate,

        'shipping_cost'           => money(setting_float('default_shipping_cost', 0)),
        'free_shipping_threshold' => money(setting_float('free_shipping_threshold', 0)),
        'cod_charge'              => money($codFee),
        'cod_max_amount'          => money($codCap),

        'free_shipping_enabled' => $flag('free_shipping_enabled'),
        'cod_enabled'           => $flag('cod_enabled'),
        'tax_enabled'           => $flag('tax_enabled'),
        'analytics_enabled'     => $text('google_analytics_id') !== '' ? '1' : '',
        'ads_enabled'           => $text('meta_pixel_id') !== '' ? '1' : '',
    ];

    return $tokens;
}

/**
 * Fill {{tokens}} in admin-authored copy.
 *
 * Three forms, resolved in this order:
 *   {{#key}}...{{/key}}  section kept only when the key resolves to something
 *   {{page:slug}}        canonical URL of a CMS page
 *   {{url:path}}         URL inside this application
 *   {{key}}              a plain value
 *
 * $escape is false for plain-text fields (title, meta) because the caller runs
 * them through e() later; true for HTML bodies, where a setting is untrusted
 * admin input being spliced into markup. An unrecognised token is removed
 * rather than left visible - a customer must never be shown template syntax.
 *
 * @param array<string,string> $context per-page values, e.g. last_updated
 */
function content_apply_tokens(?string $text, array $context = [], bool $escape = true): string
{
    $text = (string) $text;
    if ($text === '' || strpos($text, '{{') === false) {
        return $text;
    }

    $values = $context + content_tokens();
    $out    = static fn (string $value): string => $escape ? e($value) : $value;

    // preg_replace_callback does not re-scan what it substituted, so a section
    // nested inside a kept section needs another pass. Four is far more nesting
    // than a policy page will ever have, and it terminates either way.
    for ($pass = 0; $pass < 4 && strpos($text, '{{#') !== false; $pass++) {
        $before = $text;
        $text = (string) preg_replace_callback(
            '/\{\{#([a-z0-9_]+)\}\}((?:(?!\{\{[#\/]\1\}\}).)*)\{\{\/\1\}\}/is',
            static function (array $m) use ($values): string {
                $value = trim((string) ($values[strtolower($m[1])] ?? ''));
                return ($value === '' || $value === '0') ? '' : $m[2];
            },
            $text
        );
        if ($text === $before) {
            break;
        }
    }

    $text = (string) preg_replace_callback(
        '/\{\{(page|url):([a-z0-9\-_\/\.]+)\}\}/i',
        static fn (array $m): string => $out(
            strtolower($m[1]) === 'page' ? cms_page_canonical(strtolower($m[2])) : url($m[2])
        ),
        $text
    );

    $text = (string) preg_replace_callback(
        '/\{\{([a-z0-9_]+)\}\}/i',
        static fn (array $m): string => $out((string) ($values[strtolower($m[1])] ?? '')),
        $text
    );

    // A section an author forgot to close would otherwise show its markers to
    // a customer. Drop the marker, keep the words.
    return (string) preg_replace('/\{\{[#\/^][a-z0-9_]+\}\}/i', '', $text);
}

/**
 * A stable, unique anchor id for a heading, derived from its own text so a
 * deep link keeps working when a section moves.
 *
 * @param array<string,bool> $used ids already handed out on this page
 */
function content_heading_id(string $heading, array &$used): string
{
    $plain = html_entity_decode(strip_tags($heading), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $slug  = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', $plain), '-'));

    if (strlen($slug) > 60) {
        $slug = rtrim(substr($slug, 0, 60), '-');
    }
    if ($slug === '') {
        $slug = 'section';
    }

    $id = 'sec-' . $slug;
    for ($n = 2; isset($used[$id]); $n++) {
        $id = 'sec-' . $slug . '-' . $n;
    }

    $used[$id] = true;
    return $id;
}

/**
 * The h2 outline of an already-processed prose body, used to build the
 * in-page contents list. h3 is deliberately excluded: on a policy page the
 * h3s are FAQ questions, and listing them turns a navigation aid into a
 * second copy of the document.
 *
 * @return array<int,array{id:string,label:string}>
 */
function content_toc_items(string $html): array
{
    if (preg_match_all('#<h2\b[^>]*\bid\s*=\s*("|\')(.*?)\1[^>]*>(.*?)</h2\s*>#is', $html, $matches, PREG_SET_ORDER) < 1) {
        return [];
    }

    $items = [];
    foreach ($matches as $match) {
        $label = trim(html_entity_decode(strip_tags($match[3]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($label !== '') {
            $items[] = ['id' => $match[2], 'label' => $label];
        }
    }

    return $items;
}

/** Push the row's own meta fields into the document head. */
function cms_page_seo(array $page): void
{
    $description = (string) ($page['meta_description'] ?? '');
    if ($description === '') {
        $description = str_limit(strip_tags((string) $page['content']), 200, '');
    }

    // The canonical is computed rather than taken from the record, because a
    // slug that also owns a fixed route has to point at that route or the two
    // URLs compete. seo_from_entity() can still override it if an admin has
    // deliberately set canonical_url on this page.
    seo_set([
        'canonical' => cms_page_canonical((string) $page['slug']),
        'og_type'   => 'article',
    ]);
    seo_from_entity($page, [
        'title'       => (string) $page['title'],
        'description' => $description,
    ]);

    // WebPage rather than Article: a policy document is not editorial content,
    // and claiming otherwise to chase a rich result is what gets a site's
    // structured data discounted.
    seo_add_schema(seo_webpage_schema($page, cms_page_canonical((string) $page['slug'])));

    seo_add_schema(seo_breadcrumb_schema([
        ['label' => 'Home', 'url' => url()],
        ['label' => (string) $page['title'], 'url' => cms_page_canonical((string) $page['slug'])],
    ]));
}

/**
 * Breadcrumb + banner block shown at the top of every content page.
 *
 * @param array $options subtitle:string, updated:bool, trail:array
 */
function cms_page_banner(array $page, array $options = []): string
{
    $subtitle = trim((string) ($options['subtitle'] ?? ''));
    $image    = content_image($page['banner_image'] ?? null);
    $trail    = $options['trail'] ?? [
        ['label' => 'Home', 'url' => url()],
        ['label' => (string) $page['title']],
    ];

    $html = '<div class="sik-container" style="padding-top:var(--sp-4)">' . breadcrumbs($trail) . '</div>';

    $html .= '<section style="position:relative;overflow:hidden;margin-top:var(--sp-4);background:var(--sik-navy);color:#fff">';
    if ($image !== null) {
        // Painted as an <img> rather than a CSS background so a stored path can
        // never break out of a style attribute.
        $html .= '<img src="' . e($image) . '" alt="" aria-hidden="true" loading="lazy" width="1600" height="400"'
            . ' style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;opacity:.22">';
    }

    $html .= '<div class="sik-container" style="position:relative;padding-block:var(--sp-7) var(--sp-7)">'
        . '<h1 style="font-size:clamp(23px,4.4vw,34px);line-height:1.18;font-weight:800;color:#fff">'
        . e($page['title']) . '</h1>';

    if ($subtitle !== '') {
        $html .= '<p style="margin-top:var(--sp-3);font-size:14.5px;line-height:1.6;color:rgba(255,255,255,.8);max-width:620px">'
            . e($subtitle) . '</p>';
    }
    if (($options['updated'] ?? true) && !empty($page['updated_at'])) {
        $html .= '<p style="margin-top:var(--sp-3);font-size:12px;color:rgba(255,255,255,.62)">Last updated '
            . e(format_date($page['updated_at'])) . '</p>';
    }

    return $html . '</div></section>';
}

/**
 * Sanitise admin-authored rich text for a .sik-prose block. Tables are wrapped
 * so a wide one scrolls inside the page rather than pushing the layout
 * sideways on a phone.
 */
function content_prose(?string $html): string
{
    $clean = sanitize_html($html);
    if ($clean === '') {
        return '';
    }

    // Every heading gets a stable anchor, so the in-page contents list, a
    // shared deep link and "copy link to section" all resolve to one id. An
    // id the author wrote themselves is left alone and simply reserved.
    $used  = [];
    $clean = (string) preg_replace_callback(
        '#<(h2|h3)\b([^>]*)>(.*?)</\1\s*>#is',
        static function (array $m) use (&$used): string {
            if (preg_match('/\bid\s*=\s*("|\')(.*?)\1/i', $m[2], $existing) === 1) {
                $used[$existing[2]] = true;
                return $m[0];
            }
            return '<' . $m[1] . $m[2] . ' id="' . e(content_heading_id($m[3], $used)) . '">'
                . $m[3] . '</' . $m[1] . '>';
        },
        $clean
    );

    if (stripos($clean, '<table') === false) {
        return $clean;
    }

    return (string) preg_replace(
        '#(<table\b[^>]*>.*?</table>)#is',
        '<div class="sik-scroll-x" tabindex="0" role="group" aria-label="Table, scroll sideways to see all columns">$1</div>',
        $clean
    );
}

/** The page body, or an admin-facing prompt when the row has no copy yet. */
function cms_page_body(array $page): string
{
    $content = content_prose($page['content'] ?? null);

    if ($content === '') {
        // Two audiences, two messages. A shopper who lands here was told to
        // fix the store's content and handed a button into /admin/ — copy that
        // means nothing to them and a link they cannot open. Staff still get
        // the actionable version.
        if (admin_can('pages.edit')) {
            return '<div class="sik-empty">'
                . icon('edit', 'w-12 h-12')
                . '<h2 class="sik-empty__title">This page has no content yet</h2>'
                . '<p class="sik-empty__text">&ldquo;' . e($page['title'])
                . '&rdquo; exists but its body is empty. Add the copy in the admin and it will appear here.</p>'
                . '<a class="sik-btn sik-btn--outline" href="' . e(admin_url('pages/')) . '">Manage Pages</a>'
                . '</div>';
        }

        return '<div class="sik-empty">'
            . icon('file-text', 'w-12 h-12')
            . '<h2 class="sik-empty__title">Nothing here just yet</h2>'
            . '<p class="sik-empty__text">We are still writing this page. '
            . 'Get in touch and we will answer your question directly in the meantime.</p>'
            . '<div style="display:flex;gap:var(--sp-3);justify-content:center;flex-wrap:wrap">'
            . '<a class="sik-btn sik-btn--primary" href="' . e(url('contact.php')) . '">Contact us</a>'
            . '<a class="sik-btn sik-btn--outline" href="' . e(url()) . '">Back to home</a>'
            . '</div></div>';
    }

    // The measure is capped by .sik-prose itself so it stays in the stylesheet.
    return '<div class="sik-prose">' . $content . '</div>';
}

/**
 * A long-form document: the prose body plus an in-page contents list built
 * from its own h2s.
 *
 * The list is a <details> so one element serves both layouts - a sticky rail
 * beside the text on a wide screen, a collapsible disclosure above it on a
 * phone - with no duplicated markup and no JavaScript. Short pages skip it,
 * because a contents list of two entries is noise.
 */
function cms_page_document(array $page): string
{
    $content = content_prose($page['content'] ?? null);

    if ($content === '') {
        return cms_page_body($page);   // one empty state, defined once
    }

    $article = '<div class="sik-prose">' . $content . '</div>';
    $items   = content_toc_items($content);

    if (count($items) < 3) {
        return $article;
    }

    $toc = '<aside class="sik-doc__aside no-print">'
        . '<details class="sik-toc" open>'
        . '<summary class="sik-toc__head">'
        . '<span class="sik-toc__title">On this page</span>'
        . '<span class="sik-toc__count">' . count($items) . '</span>'
        . icon('chevron-down', 'sik-toc__chev')
        . '</summary>'
        . '<nav class="sik-toc__nav" aria-label="On this page">'
        . '<ol class="sik-toc__list">';

    // No ordinal is rendered here: a policy heading usually numbers itself
    // ("4. Payment Security"), and a second counter beside it would either
    // duplicate that number or contradict it when a section is configured off.
    foreach ($items as $item) {
        $toc .= '<li class="sik-toc__item">'
            . '<a class="sik-toc__link" href="#' . e($item['id']) . '">'
            . '<span class="sik-toc__label">' . e($item['label']) . '</span>'
            . '</a></li>';
    }

    $toc .= '</ol></nav></details></aside>';

    return '<div class="sik-doc">' . $toc
        . '<article class="sik-doc__body">' . $article . '</article></div>';
}

/**
 * Shown when a fixed route has no CMS row behind it. The visitor gets a way
 * out, the store owner gets told exactly which slug to create.
 */
function cms_page_unavailable(string $slug, string $title): string
{
    // Staff get the slug and the route to fix it; a customer gets a page that
    // reads like the store wrote it, not like a CMS error.
    if (admin_can('pages.edit')) {
        return '<div class="sik-empty" style="padding-block:var(--section-y-lg)">'
            . icon('info', 'w-14 h-14')
            . '<h1 class="sik-empty__title">' . e($title) . ' is not published yet</h1>'
            . '<p class="sik-empty__text">This page is driven by the content manager. Create a page with the slug '
            . '<strong>' . e($slug) . '</strong> and set it to active to publish it.</p>'
            . '<div style="display:flex;gap:var(--sp-3);justify-content:center;flex-wrap:wrap">'
            . '<a class="sik-btn sik-btn--primary" href="' . e(url()) . '">Back to Home</a>'
            . '<a class="sik-btn sik-btn--outline" href="' . e(admin_url('pages/')) . '">Manage Pages</a>'
            . '</div></div>';
    }

    return '<div class="sik-empty" style="padding-block:var(--section-y-lg)">'
        . icon('info', 'w-14 h-14')
        . '<h1 class="sik-empty__title">' . e($title) . ' is coming soon</h1>'
        . '<p class="sik-empty__text">We have not published this page yet. '
        . 'If you need this information now, our team can help you directly.</p>'
        . '<div style="display:flex;gap:var(--sp-3);justify-content:center;flex-wrap:wrap">'
        . '<a class="sik-btn sik-btn--primary" href="' . e(url()) . '">Back to home</a>'
        . '<a class="sik-btn sik-btn--outline" href="' . e(url('contact.php')) . '">Contact us</a>'
        . '</div></div>';
}

/**
 * Render a complete content page. Used by page.php and by every fixed
 * policy route, so all of them stay identical.
 *
 * @param array $options missing:'404'|'placeholder', title:string, subtitle:string
 */
function cms_page_render(string $slug, array $options = []): void
{
    $page = cms_page($slug);

    if ($page === null) {
        if (($options['missing'] ?? 'placeholder') === '404') {
            require ROOT_PATH . '/404.php';
            exit;
        }

        $title = (string) ($options['title'] ?? ucwords(str_replace('-', ' ', $slug)));
        http_response_code(404);
        seo_set(['title' => $title, 'robots' => 'noindex, follow']);

        require INCLUDES_PATH . '/header.php';
        echo '<div class="sik-container">' . cms_page_unavailable($slug, $title) . '</div>';
        require INCLUDES_PATH . '/footer.php';
        return;
    }

    cms_page_seo($page);

    // A caller-supplied subtitle wins; otherwise the row's own meta description
    // is the closest thing the admin has written to a standfirst, so a page
    // reached through /page/<slug> is not left with a bare heading.
    $subtitle = trim((string) ($options['subtitle'] ?? ''));
    if ($subtitle === '') {
        $subtitle = trim((string) ($page['meta_description'] ?? ''));
    }

    require INCLUDES_PATH . '/header.php';
    echo cms_page_banner($page, ['subtitle' => $subtitle]);
    echo '<div class="sik-container sik-section sik-section--sm">' . cms_page_document($page) . '</div>';
    require INCLUDES_PATH . '/footer.php';
}

// ===========================================================================
//  FAQ
// ===========================================================================

/**
 * Active FAQs, optionally narrowed by category or a search term.
 * Categories keep the order the admin created them in rather than falling
 * back to alphabetical, which would shuffle on every rename.
 */
function faq_list(string $category = '', string $term = ''): array
{
    $where = ["f.`status` = 'active'"];
    $params = [];

    if ($category !== '') {
        $where[] = 'f.`category` = :category';
        $params['category'] = $category;
    }
    if ($term !== '') {
        $where[] = '(f.`question` LIKE :term1 OR f.`answer` LIKE :term2)';
        $params['term1'] = '%' . $term . '%';
        $params['term2'] = '%' . $term . '%';
    }

    return Database::fetchAll(
        'SELECT f.`id`, f.`category`, f.`question`, f.`answer`
           FROM `faqs` f
           INNER JOIN (
                SELECT `category`, MIN(`id`) AS first_id
                  FROM `faqs` WHERE `status` = \'active\' GROUP BY `category`
           ) g ON g.`category` = f.`category`
          WHERE ' . implode(' AND ', $where) . '
          ORDER BY g.`first_id`, f.`sort_order`, f.`id`',
        $params
    );
}

/** Distinct active FAQ categories, in the same order as faq_list(). */
function faq_categories(): array
{
    return Database::fetchColumnAll(
        "SELECT `category` FROM `faqs` WHERE `status` = 'active'
         GROUP BY `category` ORDER BY MIN(`id`)"
    );
}

/** Group a flat FAQ list by its category column, preserving order. */
function faq_group_by_category(array $faqs): array
{
    $groups = [];
    foreach ($faqs as $faq) {
        $groups[(string) $faq['category']][] = $faq;
    }
    return $groups;
}

// ===========================================================================
//  Blog
// ===========================================================================

/** Columns every blog listing needs, with the category joined in. */
function blog_select_sql(): string
{
    return 'SELECT p.*, c.`name` AS category_name, c.`slug` AS category_slug
              FROM `blog_posts` p
              LEFT JOIN `blog_categories` c ON c.`id` = p.`category_id`';
}

/** A post is live once it is published and its publish time has passed. */
function blog_visible_sql(string $alias = 'p'): string
{
    return "{$alias}.`status` = 'published' AND ({$alias}.`published_at` IS NULL OR {$alias}.`published_at` <= NOW())";
}

/**
 * Paginated post listing.
 *
 * @param array $filters category(slug), q, page, per_page, exclude_id
 * @return array{items:array,pagination:array}
 */
function blog_posts(array $filters = []): array
{
    $where = [blog_visible_sql()];
    $params = [];

    $category = trim((string) ($filters['category'] ?? ''));
    if ($category !== '') {
        $where[] = 'c.`slug` = :category';
        $params['category'] = $category;
    }

    $q = trim((string) ($filters['q'] ?? ''));
    if ($q !== '') {
        $where[] = '(p.`title` LIKE :q1 OR p.`excerpt` LIKE :q2 OR p.`content` LIKE :q3)';
        $like = '%' . $q . '%';
        $params += ['q1' => $like, 'q2' => $like, 'q3' => $like];
    }

    if (!empty($filters['exclude_id'])) {
        $where[] = 'p.`id` <> :exclude_id';
        $params['exclude_id'] = (int) $filters['exclude_id'];
    }

    $clause = ' WHERE ' . implode(' AND ', $where);

    $total = (int) Database::fetchColumn(
        'SELECT COUNT(*) FROM `blog_posts` p LEFT JOIN `blog_categories` c ON c.`id` = p.`category_id`' . $clause,
        $params
    );

    $perPage = max(1, min(24, (int) ($filters['per_page'] ?? 9)));
    $pagination = paginate($total, $perPage, max(1, (int) ($filters['page'] ?? 1)));

    // LIMIT/OFFSET are integers we computed, never raw input.
    $items = Database::fetchAll(
        blog_select_sql() . $clause
        . ' ORDER BY p.`published_at` DESC, p.`id` DESC'
        . ' LIMIT ' . $perPage . ' OFFSET ' . $pagination['offset'],
        $params
    );

    return ['items' => $items, 'pagination' => $pagination];
}

/** One live post by slug, or null. */
function blog_post(string $slug): ?array
{
    return Database::fetch(
        blog_select_sql() . ' WHERE p.`slug` = :slug AND ' . blog_visible_sql() . ' LIMIT 1',
        ['slug' => $slug]
    );
}

/** The newest featured post, used for the listing hero. */
function blog_featured_post(): ?array
{
    return Database::fetch(
        blog_select_sql() . ' WHERE ' . blog_visible_sql() . ' AND p.`is_featured` = 1
         ORDER BY p.`published_at` DESC, p.`id` DESC LIMIT 1'
    );
}

/** Live post counts per category, keyed by category id. */
function blog_category_counts(): array
{
    return Database::fetchPairs(
        'SELECT p.`category_id`, COUNT(*) FROM `blog_posts` p
          WHERE ' . blog_visible_sql() . ' AND p.`category_id` IS NOT NULL
          GROUP BY p.`category_id`'
    );
}

/** Active blog categories in admin order. */
function blog_categories(): array
{
    return Database::fetchAll(
        "SELECT * FROM `blog_categories` WHERE `status` = 'active' ORDER BY `sort_order`, `name`"
    );
}

/** More posts from the same category. */
function blog_related_posts(array $post, int $limit = 3): array
{
    $limit = max(1, min(6, $limit));

    if (empty($post['category_id'])) {
        return Database::fetchAll(
            blog_select_sql() . ' WHERE ' . blog_visible_sql() . ' AND p.`id` <> :id
             ORDER BY p.`published_at` DESC LIMIT ' . $limit,
            ['id' => (int) $post['id']]
        );
    }

    return Database::fetchAll(
        blog_select_sql() . ' WHERE ' . blog_visible_sql() . '
          AND p.`category_id` = :category_id AND p.`id` <> :id
         ORDER BY p.`published_at` DESC LIMIT ' . $limit,
        ['category_id' => (int) $post['category_id'], 'id' => (int) $post['id']]
    );
}

/** Reading time in whole minutes, at a conservative 200 words per minute. */
function blog_reading_time(?string $content): int
{
    $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $content)));
    if ($text === '') {
        return 1;
    }
    return max(1, (int) ceil(count(explode(' ', $text)) / 200));
}

/**
 * Count a post view at most once per session, so a reader refreshing the
 * page does not inflate the counter.
 */
function blog_record_view(int $postId): void
{
    if (!empty($_SESSION['_blog_views'][$postId])) {
        return;
    }
    $_SESSION['_blog_views'][$postId] = true;

    try {
        Database::query('UPDATE `blog_posts` SET `views` = `views` + 1 WHERE `id` = :id', ['id' => $postId]);
    } catch (Throwable $e) {
        ErrorHandler::log('warning', 'Blog view counter failed: ' . $e->getMessage());
    }
}

/**
 * Storefront pager. Every other query parameter is kept, so a search or a
 * category filter survives a page change.
 */
function content_pager(array $pagination): string
{
    if ($pagination['last'] <= 1) {
        return '';
    }

    $link = static fn (int $page): string => e(url_with(['page' => $page > 1 ? $page : null]));

    $html = '<nav class="sik-pager" aria-label="Pagination">';
    $html .= $pagination['current'] > 1
        ? '<a class="sik-pager__link" rel="prev" href="' . $link($pagination['current'] - 1) . '">Prev</a>'
        : '<span class="sik-pager__link is-disabled" aria-hidden="true">Prev</span>';

    foreach ($pagination['pages'] as $page) {
        if (!is_int($page)) {
            $html .= '<span class="sik-pager__gap" aria-hidden="true">&hellip;</span>';
            continue;
        }
        $html .= $page === $pagination['current']
            ? '<span class="sik-pager__link is-current" aria-current="page">' . $page . '</span>'
            : '<a class="sik-pager__link" href="' . $link($page) . '">' . $page . '</a>';
    }

    $html .= $pagination['current'] < $pagination['last']
        ? '<a class="sik-pager__link" rel="next" href="' . $link($pagination['current'] + 1) . '">Next</a>'
        : '<span class="sik-pager__link is-disabled" aria-hidden="true">Next</span>';

    return $html . '</nav>';
}

/** Card markup shared by the blog grid and the related-posts rail. */
function blog_card(array $post): string
{
    $image = content_image($post['featured_image'] ?? null) ?? img_url(null);
    $url   = blog_url((string) $post['slug']);
    $date  = format_date($post['published_at'] ?: $post['created_at']);

    $html = '<article class="sik-card">'
        . '<a class="sik-card__media" href="' . e($url) . '" aria-label="' . e($post['title']) . '"'
        . ' style="aspect-ratio:16/10">'
        . '<img class="sik-card__img" src="' . e($image) . '" alt="' . e($post['title']) . '"'
        . ' width="640" height="400" loading="lazy" decoding="async" style="object-fit:cover;padding:0"></a>'
        . '<div class="sik-card__body">';

    if (!empty($post['category_name'])) {
        $html .= '<a class="sik-card__brand" style="color:var(--sik-primary-ink)" href="'
            . e(url('blog.php') . '?category=' . rawurlencode((string) $post['category_slug'])) . '">' . e($post['category_name']) . '</a>';
    }

    $html .= '<h3 style="font-size:15px;font-weight:700;line-height:1.4">'
        . '<a href="' . e($url) . '">' . e($post['title']) . '</a></h3>'
        . '<p style="font-size:13px;color:var(--sik-muted);line-height:1.6">'
        . e(str_limit((string) $post['excerpt'], 110)) . '</p>'
        . '<div class="sik-card__foot" style="display:flex;flex-wrap:wrap;gap:var(--sp-3);align-items:center;'
        . 'font-size:11.5px;color:var(--sik-muted)">'
        . '<span>' . e($date) . '</span><span aria-hidden="true">&middot;</span>'
        . '<span>' . blog_reading_time($post['content'] ?? '') . ' min read</span>'
        . '</div></div></article>';

    return $html;
}
