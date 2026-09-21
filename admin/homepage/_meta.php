<?php
/**
 * ShopInnKart Admin - Homepage builder vocabulary.
 *
 * Zones, widget types, data sources and the rest of the option lists live in
 * one place so the list screen, the form and the shortcut pages can never
 * drift apart. Every key here matches an ENUM value or a COMMENT entry on
 * `homepage_sections` — nothing is invented.
 */

declare(strict_types=1);

// Include-only: the parent page has already run the auth and permission checks.
if (!defined('SIK_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

/**
 * The zones the storefront actually renders. `checkout` and `footer_top`
 * appear in the schema comment but no page calls render_zone() for them, so
 * offering them here would build sections nobody would ever see.
 */
function homepage_zones(): array
{
    return [
        'home'           => ['label' => 'Homepage',        'icon' => 'home',    'sub' => 'index.php — the full landing page'],
        'shop_top'       => ['label' => 'Shop top',        'icon' => 'grid',    'sub' => 'Above the grid on shop, category, brand and listing pages'],
        'product_bottom' => ['label' => 'Product page',    'icon' => 'package', 'sub' => 'Below the product detail tabs'],
        'cart'           => ['label' => 'Cart',            'icon' => 'cart',    'sub' => 'Under the cart table'],
    ];
}

/**
 * Widget types, with what each one actually renders and which controls it
 * honours. `uses` drives the inline help so an admin does not fill in a
 * carousel speed for a widget that never scrolls.
 */
function homepage_widget_types(): array
{
    return [
        'hero' => [
            'label' => 'Hero slider', 'icon' => 'monitor',
            'help'  => 'Hero panel built from Marketing → Banners with position "hero". A banner with an uploaded image shows it; one still using the shipped placeholder art shows photos of the products its button links to. Nothing renders until at least one hero banner is active.',
            'uses'  => ['carousel'],
        ],
        'ticker' => [
            'label' => 'Announcement ticker', 'icon' => 'zap',
            'help'  => 'Scrolling strip of the active announcement bar messages. Edit the messages under Content → Announcements.',
            'uses'  => [],
        ],
        'trust' => [
            'label' => 'Trust badges', 'icon' => 'shield',
            'help'  => 'Row of trust features with placement "strip". Manage them on the Trust Features screen; item limit caps how many show.',
            'uses'  => ['limit'],
        ],
        'category_grid' => [
            'label' => 'Category grid', 'icon' => 'grid',
            'help'  => 'Tiles for the categories flagged as featured. Pick which ones on the Featured Categories screen.',
            'uses'  => ['limit', 'layout', 'cols', 'carousel'],
        ],
        'product_grid' => [
            'label' => 'Product grid', 'icon' => 'package',
            'help'  => 'Products in a responsive grid. The data source decides which products.',
            'uses'  => ['source', 'limit', 'cards', 'cols', 'layout'],
        ],
        'product_carousel' => [
            'label' => 'Product carousel', 'icon' => 'arrow-right',
            'help'  => 'The same products as a grid, in a horizontal rail with arrows and dots.',
            'uses'  => ['source', 'limit', 'cards', 'cols', 'carousel'],
        ],
        'deal_of_day' => [
            'label' => 'Deal of the day', 'icon' => 'percent',
            'help'  => 'The running Deal of the Day with its countdown and stock bar. Hidden while no deal is live.',
            'uses'  => [],
        ],
        'flash_sale' => [
            'label' => 'Flash sale', 'icon' => 'fire',
            'help'  => 'The active flash sale: countdown plus its product rail. Hidden while no sale is live.',
            'uses'  => ['cards', 'carousel'],
        ],
        'brand_slider' => [
            'label' => 'Brand slider', 'icon' => 'award',
            'help'  => 'Logo rail of featured brands, falling back to all active brands.',
            'uses'  => ['limit', 'carousel'],
        ],
        'promo_banner' => [
            'label' => 'Promo banner', 'icon' => 'tag',
            'help'  => 'One banner picked at random from Marketing → Banners with position "promo".',
            'uses'  => [],
        ],
        'stats' => [
            'label' => 'Stats strip', 'icon' => 'chart',
            'help'  => 'The site statistics counters (orders shipped, happy customers, …).',
            'uses'  => [],
        ],
        'testimonials' => [
            'label' => 'Testimonials', 'icon' => 'star',
            'help'  => 'Customer testimonial cards in a rail. Manage them under Content → Testimonials.',
            'uses'  => ['limit', 'carousel'],
        ],
        'reviews' => [
            'label' => 'Customer reviews', 'icon' => 'star',
            'help'  => 'Recent approved product reviews rated four stars or more, under the average of every approved review. Hidden until the first review is approved under Reviews.',
            'uses'  => ['limit'],
        ],
        'faq' => [
            'label' => 'FAQ', 'icon' => 'info',
            'help'  => 'Questions from Content → FAQ as an accordion, in the order and on/off state set there. Item limit caps how many; the link only shows when the FAQ page has more.',
            'uses'  => ['limit'],
        ],
        'newsletter' => [
            'label' => 'Newsletter band', 'icon' => 'mail',
            'help'  => 'Email capture band. Title, accent and subtitle are the copy shown next to the field.',
            'uses'  => [],
        ],
        'offer_slider' => [
            'label' => 'Coupon slider', 'icon' => 'gift',
            'help'  => 'Coupons switched to "Show as an offer" with the Homepage placement, each with its code. Hidden while there are none.',
            'uses'  => ['carousel'],
        ],
        'recently_viewed' => [
            'label' => 'Recently viewed', 'icon' => 'clock',
            'help'  => 'Products this visitor looked at. Stays hidden for a first-time visitor and needs at least two products.',
            'uses'  => ['limit', 'carousel'],
        ],
        'recommendations' => [
            'label' => 'Recommendations', 'icon' => 'trending',
            'help'  => 'Related products for the current product page; best sellers everywhere else.',
            'uses'  => ['limit', 'cards', 'carousel'],
        ],
        'blog_grid' => [
            'label' => 'Blog posts', 'icon' => 'edit',
            'help'  => 'The latest published blog posts.',
            'uses'  => ['limit', 'cols'],
        ],
        'html' => [
            'label' => 'Custom HTML', 'icon' => 'cpu',
            'help'  => 'Your own markup, sanitised on save. Scripts and event handlers are stripped.',
            'uses'  => ['html'],
        ],
    ];
}

/** Icon key for a widget type, falling back to something sensible. */
function homepage_widget_icon(string $type): string
{
    $icon = (string) (homepage_widget_types()[$type]['icon'] ?? 'grid');
    return icon_exists($icon) ? $icon : 'grid';
}

/** Human label for a widget type, even for a row written before a rename. */
function homepage_widget_label(string $type): string
{
    return (string) (homepage_widget_types()[$type]['label'] ?? ucwords(str_replace('_', ' ', $type)));
}

/** Data sources understood by get_products_for_source(). */
function homepage_data_sources(): array
{
    return [
        'auto'        => 'Auto (best sellers)',
        'manual'      => 'Hand-picked products',
        'category'    => 'From a category',
        'brand'       => 'From a brand',
        'tag'         => 'From a tag',
        'featured'    => 'Featured products',
        'new'         => 'New arrivals',
        'best'        => 'Best sellers',
        'trending'    => 'Trending',
        'deal'        => 'On deal',
        'flash'       => 'In the flash sale',
        'most_viewed' => 'Most viewed',
    ];
}

/** The three sources that need a source_id. */
function homepage_lookup_sources(): array
{
    return ['category', 'brand', 'tag'];
}

function homepage_layouts(): array
{
    return ['grid' => 'Grid', 'carousel' => 'Carousel', 'list' => 'List', 'masonry' => 'Masonry'];
}

function homepage_card_styles(): array
{
    return [
        'standard'   => 'Standard',
        'minimal'    => 'Minimal',
        'premium'    => 'Premium',
        'compact'    => 'Compact',
        'horizontal' => 'Horizontal',
    ];
}

function homepage_animations(): array
{
    return [
        'none'       => 'None',
        'fade'       => 'Fade',
        'fade-up'    => 'Fade up',
        'fade-down'  => 'Fade down',
        'fade-left'  => 'Fade left',
        'fade-right' => 'Fade right',
        'zoom-in'    => 'Zoom in',
    ];
}

function homepage_containers(): array
{
    return ['boxed' => 'Boxed (max width)', 'full' => 'Full bleed'];
}

function homepage_paddings(): array
{
    return ['none' => 'None', 'sm' => 'Small', 'md' => 'Medium', 'lg' => 'Large'];
}

function homepage_device_visibility(): array
{
    return [
        'all'            => 'All devices',
        'desktop'        => 'Desktop only',
        'tablet'         => 'Tablet only',
        'mobile'         => 'Mobile only',
        'desktop_tablet' => 'Desktop + tablet',
        'tablet_mobile'  => 'Tablet + mobile',
    ];
}

function homepage_auth_visibility(): array
{
    return ['all' => 'Everyone', 'guest' => 'Signed-out visitors', 'user' => 'Signed-in customers'];
}

/**
 * id => label lists for the dependent source picker.
 * Categories keep their tree shape through an indent prefix.
 */
function homepage_source_lists(): array
{
    static $lists = null;
    if ($lists !== null) {
        return $lists;
    }

    $categories = Database::fetchAll(
        'SELECT `id`, `name`, `parent_id` FROM `categories` ORDER BY `sort_order`, `name`'
    );
    $childrenOf = [];
    foreach ($categories as $category) {
        $childrenOf[(int) ($category['parent_id'] ?? 0)][] = $category;
    }

    $flat = [];
    $walk = static function (int $parentId, int $depth) use (&$walk, $childrenOf, &$flat): void {
        foreach ($childrenOf[$parentId] ?? [] as $category) {
            $flat[(int) $category['id']] = str_repeat('— ', $depth) . (string) $category['name'];
            $walk((int) $category['id'], $depth + 1);
        }
    };
    $walk(0, 0);

    return $lists = [
        'category' => $flat,
        'brand'    => admin_lookup('brands'),
        // `tags` has no status column, so the usual active-only filter cannot apply.
        'tag'      => admin_lookup('tags', 'name', '1'),
    ];
}

/**
 * Where this widget shows up on the storefront, anchored at the section.
 * widget_section_attrs() renders id="w-<section_key>", so the fragment lands
 * the browser on the exact block.
 */
function homepage_preview_url(array $widget): string
{
    // No key means "just show me this zone" — the arrow on the list header.
    $key = (string) ($widget['section_key'] ?? '');
    $anchor = $key === '' ? '' : '#w-' . rawurlencode($key);

    switch ((string) $widget['zone']) {
        case 'shop_top':
            return url('shop.php') . $anchor;
        case 'cart':
            return url('cart.php') . $anchor;
        case 'product_bottom':
            $slug = Database::fetchColumn(
                "SELECT `slug` FROM `products` WHERE `status` = 'active' ORDER BY `sold_count` DESC, `id` DESC LIMIT 1"
            );
            return $slug !== null ? product_url((string) $slug) . $anchor : url('shop.php');
        case 'home':
        default:
            return url() . $anchor;
    }
}

/** Schedule state as a label + status pill tone. */
function homepage_schedule_state(array $widget): array
{
    $start = $widget['start_date'] ?? null;
    $end   = $widget['end_date'] ?? null;

    if (empty($start) && empty($end)) {
        return ['label' => 'Always on', 'tone' => 'gray'];
    }
    if (!empty($start) && strtotime((string) $start) > time()) {
        return ['label' => 'Starts ' . format_date($start, 'd M, g:i A'), 'tone' => 'amber'];
    }
    if (!empty($end) && strtotime((string) $end) < time()) {
        return ['label' => 'Ended ' . format_date($end, 'd M Y'), 'tone' => 'red'];
    }
    if (!empty($end)) {
        return ['label' => 'Until ' . format_date($end, 'd M, g:i A'), 'tone' => 'green'];
    }
    return ['label' => 'Live', 'tone' => 'green'];
}

/**
 * Short description of where a widget's items come from, for the list screen.
 */
function homepage_source_summary(array $widget): string
{
    $type = (string) $widget['widget_type'];
    $uses = (array) (homepage_widget_types()[$type]['uses'] ?? []);

    if (!in_array('source', $uses, true)) {
        return '—';
    }

    $source = (string) $widget['data_source'];
    $label  = homepage_data_sources()[$source] ?? ucfirst($source);

    if (in_array($source, homepage_lookup_sources(), true)) {
        $lists = homepage_source_lists();
        $name  = $lists[$source][(int) ($widget['source_id'] ?? 0)] ?? null;
        return $name !== null
            ? $label . ': ' . trim(str_replace('—', '', $name))
            : $label . ': not set';
    }

    if ($source === 'manual') {
        $ids = homepage_manual_ids($widget);
        return $label . ': ' . count($ids) . ' picked';
    }

    return $label;
}

/**
 * Hand-picked product ids for a widget.
 *
 * widgets.php reads this with explode(',', (string) widget_setting(...)), so
 * the value inside the settings JSON has to stay a comma-separated string.
 */
function homepage_manual_ids(array $widget): array
{
    $settings = json_decode_safe($widget['settings'] ?? null, []);
    $raw = (string) ($settings['product_ids'] ?? '');
    if (trim($raw) === '') {
        return [];
    }
    return array_values(array_filter(array_map('intval', explode(',', $raw))));
}

// ===========================================================================
//  FORM INPUT — shared by create.php and edit.php
// ===========================================================================

/** Lowercase key limited to letters, digits, dash and underscore. */
function homepage_normalize_key(string $raw): string
{
    $key = strtolower(trim($raw));
    $key = preg_replace('/[^a-z0-9_-]+/', '-', $key) ?? $key;
    return trim($key, '-_');
}

/** Append -2, -3 … until the section key is free. */
function homepage_unique_key(string $key, ?int $ignoreId = null): string
{
    $base = $key;
    $suffix = 1;

    while (true) {
        $sql = 'SELECT `id` FROM `homepage_sections` WHERE `section_key` = :k';
        $params = ['k' => $key];
        if ($ignoreId !== null) {
            $sql .= ' AND `id` <> :id';
            $params['id'] = $ignoreId;
        }
        if (Database::fetchColumn($sql . ' LIMIT 1', $params) === null) {
            return $key;
        }
        $suffix++;
        $key = mb_substr($base, 0, 56) . '-' . $suffix;
    }
}

/** datetime-local ("2026-08-12T09:30") to a storable DATETIME, or null. */
function homepage_datetime(string $field): ?string
{
    $raw = trim((string) input($field, ''));
    if ($raw === '') {
        return null;
    }
    $timestamp = strtotime($raw);
    return $timestamp === false ? null : date('Y-m-d H:i:s', $timestamp);
}

/**
 * Every posted column, normalised. Nothing is written from here — the caller
 * validates first, then decides between INSERT and UPDATE.
 */
function homepage_form_input(): array
{
    $sourceIdRaw = trim((string) input('source_id', ''));

    return [
        'section_key'       => homepage_normalize_key((string) input('section_key', '')),
        'zone'              => (string) input('zone', 'home'),
        'widget_type'       => (string) input('widget_type', 'product_grid'),
        'title'             => (string) input('title', ''),
        'title_accent'      => (string) input('title_accent', ''),
        'subtitle'          => (string) input('subtitle', ''),
        'description'       => sanitize_html((string) input('description', '')),
        'custom_html'       => sanitize_html((string) input('custom_html', '')),
        'link_text'         => (string) input('link_text', ''),
        'link_url'          => (string) input('link_url', ''),
        'data_source'       => (string) input('data_source', 'auto'),
        'source_id'         => $sourceIdRaw === '' ? null : (int) $sourceIdRaw,
        'item_limit'        => input_int('item_limit', 8),
        'layout'            => (string) input('layout', 'grid'),
        'card_style'        => (string) input('card_style', 'standard'),
        'cols_desktop'      => input_int('cols_desktop', 4),
        'cols_tablet'       => input_int('cols_tablet', 3),
        'cols_mobile'       => input_int('cols_mobile', 2),
        'autoplay'          => input_bool('autoplay') ? 1 : 0,
        'autoplay_speed'    => input_int('autoplay_speed', 4000),
        'show_arrows'       => input_bool('show_arrows') ? 1 : 0,
        'show_dots'         => input_bool('show_dots') ? 1 : 0,
        'animation'         => (string) input('animation', 'fade-up'),
        'bg_color'          => (string) input('bg_color', ''),
        'text_color'        => (string) input('text_color', ''),
        'container'         => (string) input('container', 'boxed'),
        'padding'           => (string) input('padding', 'md'),
        'device_visibility' => (string) input('device_visibility', 'all'),
        'auth_visibility'   => (string) input('auth_visibility', 'all'),
        'start_date'        => homepage_datetime('start_date'),
        'end_date'          => homepage_datetime('end_date'),
        'lazy_load'         => input_bool('lazy_load') ? 1 : 0,
        'sort_order'        => input_int('sort_order', 0),
        'status'            => (string) input('status', 'active'),
        // Not a column: folded into `settings` by the caller.
        'product_ids'       => array_values(array_filter(array_map('intval', input_array('product_ids')))),
    ];
}

/** Field => message for anything the form got wrong. */
function homepage_validate(array $data): array
{
    $v = new Validator($data, [
        'section_key' => 'Section key',
        'item_limit'  => 'Item limit',
        'sort_order'  => 'Sort order',
        'source_id'   => 'Source',
    ]);

    $v->required('section_key')->max('section_key', 60)
      ->in('zone', array_keys(homepage_zones()))
      ->in('widget_type', array_keys(homepage_widget_types()))
      ->in('data_source', array_keys(homepage_data_sources()))
      ->in('layout', array_keys(homepage_layouts()))
      ->in('card_style', array_keys(homepage_card_styles()))
      ->in('animation', array_keys(homepage_animations()))
      ->in('container', array_keys(homepage_containers()))
      ->in('padding', array_keys(homepage_paddings()))
      ->in('device_visibility', array_keys(homepage_device_visibility()))
      ->in('auth_visibility', array_keys(homepage_auth_visibility()))
      ->in('status', ['active', 'inactive'])
      ->between('item_limit', 1, 48)
      ->between('sort_order', 0, 9999)
      ->between('cols_desktop', 1, 8)
      ->between('cols_tablet', 1, 8)
      ->between('cols_mobile', 1, 8)
      ->between('autoplay_speed', 500, 20000)
      ->max('title', 150)->max('title_accent', 150)->max('subtitle', 255)
      ->max('link_text', 60)->max('link_url', 255)
      ->max('bg_color', 20)->max('text_color', 20);

    // A category/brand/tag source is meaningless without the row it points at.
    if (in_array($data['data_source'], homepage_lookup_sources(), true)) {
        $lists = homepage_source_lists();
        $v->rule(
            'source_id',
            $data['source_id'] !== null && isset($lists[$data['data_source']][$data['source_id']]),
            'Pick which ' . $data['data_source'] . ' this section pulls from.'
        );
    }

    if ($data['start_date'] !== null && $data['end_date'] !== null
        && strtotime($data['end_date']) <= strtotime($data['start_date'])) {
        $v->rule('end_date', false, 'The end date must be after the start date.');
    }

    return $v->errors();
}

/**
 * Merge the hand-picked ids into the existing settings JSON.
 * Other keys (a widget's own extras) are preserved untouched.
 */
function homepage_merge_settings(?string $currentJson, array $productIds): ?string
{
    $settings = json_decode_safe($currentJson, []);

    if ($productIds === []) {
        unset($settings['product_ids']);
    } else {
        // Stored as CSV because widgets.php explodes this value on commas.
        $settings['product_ids'] = implode(',', $productIds);
    }

    return $settings === [] ? null : json_encode($settings, JSON_UNESCAPED_SLASHES);
}

/** The column subset of a validated form payload. */
function homepage_columns(array $data): array
{
    $nullable = ['title', 'title_accent', 'subtitle', 'description', 'custom_html',
                 'link_text', 'link_url', 'bg_color', 'text_color'];

    unset($data['product_ids']);
    foreach ($nullable as $field) {
        if (isset($data[$field]) && trim((string) $data[$field]) === '') {
            $data[$field] = null;
        }
    }
    return $data;
}
