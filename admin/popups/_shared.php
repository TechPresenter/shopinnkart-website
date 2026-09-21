<?php
/**
 * ShopInnKart Admin - Popup module shared pieces.
 *
 * The list screen, the form and the write endpoints all have to agree on what
 * a `popups` row may legally contain, so every enum and every derived label
 * lives here once. Everything is read-only lookup data plus the two functions
 * that turn a submitted form into a validated row.
 */

declare(strict_types=1);

// Include-only: this partial assumes its parent page already ran the
// authentication and permission checks. Refuse to run as an entry point.
if (!defined('SIK_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

// ===========================================================================
//  Option lists — these mirror the ENUMs in database/schema.sql
// ===========================================================================

function popup_display_modes(): array
{
    return [
        'popup' => 'Popup',
        'popin' => 'Pop-in',
    ];
}

/** popup_type is a VARCHAR in the schema; this list is the allowlist. */
function popup_type_options(): array
{
    return [
        'newsletter'    => 'Newsletter signup',
        'coupon'        => 'Coupon code',
        'promo'         => 'Promotion',
        'product'       => 'Product spotlight',
        'image'         => 'Image only',
        'html'          => 'Custom HTML',
        'video'         => 'Video',
        'cart_reminder' => 'Cart reminder',
        'stock'         => 'Stock alert',
    ];
}

/** Pill tone per type so the list scans quickly. */
function popup_type_tone(string $type): string
{
    return [
        'newsletter'    => 'blue',
        'coupon'        => 'green',
        'promo'         => 'orange',
        'product'       => 'violet',
        'image'         => 'cyan',
        'html'          => 'gray',
        'video'         => 'indigo',
        'cart_reminder' => 'amber',
        'stock'         => 'red',
    ][$type] ?? 'gray';
}

function popup_position_options(): array
{
    return [
        'center'       => 'Center',
        'top'          => 'Top',
        'bottom'       => 'Bottom',
        'top-right'    => 'Top right',
        'bottom-left'  => 'Bottom left',
        'bottom-right' => 'Bottom right',
        'left'         => 'Left',
        'right'        => 'Right',
    ];
}

function popup_size_options(): array
{
    return ['sm' => 'Small', 'md' => 'Medium', 'lg' => 'Large'];
}

function popup_trigger_options(): array
{
    return [
        'immediate'  => 'Immediately on load',
        'timed'      => 'After a delay',
        'scroll'     => 'At a scroll depth',
        'exit'       => 'On exit intent',
        'cart_value' => 'When the cart reaches a value',
    ];
}

function popup_frequency_options(): array
{
    return [
        'always'  => 'Every page view',
        'session' => 'Once per session',
        'daily'   => 'Once a day',
        'once'    => 'Once ever',
    ];
}

/**
 * The six device audiences visibility_allows() can actually decide.
 *
 * device_type() sorts a visitor into exactly one of mobile | tablet | desktop,
 * so with only 'desktop' and 'mobile' on offer a tablet matched neither and was
 * silently dropped from every device-targeted popup — an iPad could be reached
 * by "All devices" and by nothing else. visibility_allows() has always
 * understood the three combinations below; only this list and the column enum
 * were missing, so they are what changed.
 */
function popup_device_options(): array
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

function popup_auth_options(): array
{
    return ['all' => 'Everyone', 'guest' => 'Signed-out visitors', 'user' => 'Signed-in customers'];
}

/**
 * Route keys grouped the way the targeting form draws them.
 *
 * current_route_key() is the storefront script's basename, so every key here is
 * literally a file in the web root that ends by requiring includes/footer.php —
 * the only place a popup can be printed. Keys are grouped for the form only;
 * popup_page_options() flattens them for validation and for labelling.
 *
 * The list was built by hand and had drifted from the web root. Setting a popup
 * to every page and walking the whole root showed 46 routes that actually print
 * one, against 26 offered here — so the six policy pages and seven of the nine
 * account sub-pages could not be targeted at all, while their siblings (About,
 * FAQ, Account dashboard, My orders) could. That was an oversight, not a
 * policy, and the groups below now cover every route a popup reaches.
 *
 * Five reachable routes are still deliberately absent, because offering them
 * would be offering a mistake:
 *
 *   403, 500        Error pages. A promo on a failure the visitor did not cause
 *                   reads as indifference. 404 IS offered — a missing product is
 *                   a browsing dead end, and a recovery offer belongs there.
 *   logout          Prints for one moment before redirecting; nothing can be
 *                   read, let alone acted on.
 *   invoice,        Financial documents, and invoice-download streams a PDF.
 *   invoice-download Advertising over a receipt is the wrong surface.
 *
 * The auth-flow routes (forgot-password, reset-password, verify-email) are
 * likewise absent: each is a single-purpose transactional step where anything
 * covering the form is a support ticket.
 *
 * @return array<string, array<string, string>> group heading => (key => label)
 */
function popup_page_groups(): array
{
    return [
        'Storefront' => [
            'home'          => 'Home',
            'shop'          => 'Shop',
            'category'      => 'Category',
            'product'       => 'Product',
            'brand'         => 'Brand',
            'brands'        => 'All brands',
            'search'        => 'Search results',
            'deals'         => 'Deals',
            'new-arrivals'  => 'New arrivals',
            'best-sellers'  => 'Best sellers',
        ],
        'Content' => [
            'blog'      => 'Blog index',
            // Articles rewrite to blog-post.php, so this is the key an article
            // reports. Without it a popup could target the blog index but never
            // a single article, which is the page a content promo is written for.
            'blog-post' => 'Blog article',
            'page'      => 'CMS page',
            'about'     => 'About',
            'contact'   => 'Contact',
            'faq'       => 'FAQ',
            // A dead end is the one error page worth an offer on.
            '404'       => 'Page not found (404)',
        ],
        // Each of these is its own script in the web root, not a CMS page, so
        // each reports its own route key. They were the largest single gap:
        // six pages with real traffic that no popup could reach.
        'Policies' => [
            'terms'           => 'Terms and conditions',
            'privacy-policy'  => 'Privacy policy',
            'refund-policy'   => 'Refund policy',
            'return-policy'   => 'Return policy',
            'shipping-policy' => 'Shipping policy',
            'warranty'        => 'Warranty',
        ],
        'Buying' => [
            'cart'          => 'Cart',
            'checkout'      => 'Checkout',
            'order-success' => 'Order confirmation',
            'order-details' => 'Order details',
            'track-order'   => 'Track order',
        ],
        'Account' => [
            'login'             => 'Sign in',
            'register'          => 'Create account',
            'account'           => 'Account dashboard',
            'orders'            => 'My orders',
            'wishlist'          => 'Wishlist',
            'compare'           => 'Compare',
            // Signed-in sub-pages. Their siblings above were already offered,
            // so leaving these out only made the group look arbitrary.
            'profile'           => 'Profile',
            'addresses'         => 'Address book',
            'preferences'       => 'Preferences',
            'notifications'     => 'Notifications',
            'my-reviews'        => 'My reviews',
            'purchase-history'  => 'Purchase history',
            'change-password'   => 'Change password',
        ],
    ];
}

/**
 * Route keys understood by current_route_key() in includes/menu-functions.php.
 * Anything not in this list can never match, so it is also the allowlist.
 *
 * The union operator, NOT array_merge(). '404' is a real route key — 404.php —
 * and PHP stores a decimal-string array key as an integer, so this list holds
 * one int among forty strings. array_merge() renumbers integer keys, which
 * quietly turned 404 into 0: the option rendered, the checkbox posted, and
 * array_intersect() in popup_form_input() then dropped it on every save, so a
 * popup targeting the 404 page saved as though the box had never been ticked.
 * '+' preserves keys and has no such behaviour. The groups share no key, so
 * nothing is lost to the union's first-wins rule.
 *
 * Everything downstream compares these loosely on purpose — array_intersect()
 * casts to string, and $labels['404'] normalises back to $labels[404]. Do not
 * introduce in_array(..., true) or === against these keys without casting: a
 * strict test against the posted string '404' fails on the stored int 404.
 */
function popup_page_options(): array
{
    static $flat = null;
    if ($flat === null) {
        $flat = [];
        foreach (popup_page_groups() as $rows) {
            $flat += $rows;
        }
    }
    return $flat;
}

// ===========================================================================
//  Presentation helpers
// ===========================================================================

/**
 * The trigger_value column means a different thing per trigger, so the form
 * label, the limits and the plain-English summary all come from here.
 *
 * @return array{show:bool,label:string,help:string,min:int,max:int,suffix:string}
 */
function popup_trigger_field(string $trigger): array
{
    switch ($trigger) {
        case 'timed':
            return ['show' => true, 'label' => 'Delay (seconds)', 'min' => 0, 'max' => 600,
                'suffix' => 'seconds', 'help' => 'How long after the page loads before it appears.'];
        case 'scroll':
            return ['show' => true, 'label' => 'Scroll depth (percent)', 'min' => 1, 'max' => 100,
                'suffix' => '%', 'help' => 'Fires once the visitor has scrolled this far down the page.'];
        case 'cart_value':
            return ['show' => true, 'label' => 'Cart value (' . CURRENCY_SYMBOL . ')', 'min' => 1, 'max' => 10000000,
                'suffix' => CURRENCY_SYMBOL, 'help' => 'Fires when the cart subtotal reaches this amount.'];
        case 'immediate':
        case 'exit':
        default:
            return ['show' => false, 'label' => 'Trigger value', 'min' => 0, 'max' => 0,
                'suffix' => '', 'help' => 'This trigger has nothing to measure, so no value is stored.'];
    }
}

/** One-line English description of when a popup fires. */
function popup_trigger_summary(string $trigger, int $value): string
{
    switch ($trigger) {
        case 'timed':
            return $value <= 0 ? 'immediately on load' : $value . 's after load';
        case 'scroll':
            return 'at ' . $value . '% scroll';
        case 'exit':
            return 'on exit intent';
        case 'cart_value':
            return 'when the cart reaches ' . money((float) $value);
        case 'immediate':
        default:
            return 'immediately on load';
    }
}

/** Stored display_pages CSV -> the route keys it actually targets. */
function popup_pages_list(?string $pages): array
{
    $pages = trim((string) $pages);
    if ($pages === '' || $pages === 'all') {
        return [];
    }
    return array_values(array_filter(array_map('trim', explode(',', $pages)), static fn ($p) => $p !== ''));
}

/** Stored display_pages CSV -> "All pages" or "Home, Shop, Cart". */
function popup_pages_label(?string $pages): string
{
    $keys = popup_pages_list($pages);
    if ($keys === []) {
        return 'All pages';
    }

    $labels = popup_page_options();
    return implode(', ', array_map(static fn ($key) => $labels[$key] ?? $key, $keys));
}

/** Conversion rate as a percentage; zero impressions means zero, not a divide by zero. */
function popup_conversion_rate(int $impressions, int $conversions): float
{
    return $impressions > 0 ? round(($conversions / $impressions) * 100, 1) : 0.0;
}

/**
 * Where the schedule window sits relative to right now.
 * Independent of `status` — a disabled popup can still be "scheduled".
 *
 * @return array{state:string,label:string,tone:string}
 */
function popup_schedule_state(?string $start, ?string $end): array
{
    $now = time();
    $startAt = !empty($start) ? strtotime((string) $start) : false;
    $endAt   = !empty($end) ? strtotime((string) $end) : false;

    if ($startAt !== false && $startAt > $now) {
        return ['state' => 'scheduled', 'label' => 'Starts ' . format_date((string) $start, 'd M'), 'tone' => 'blue'];
    }
    if ($endAt !== false && $endAt < $now) {
        return ['state' => 'expired', 'label' => 'Ended ' . format_date((string) $end, 'd M'), 'tone' => 'red'];
    }
    if ($endAt !== false) {
        return ['state' => 'running', 'label' => 'Until ' . format_date((string) $end, 'd M'), 'tone' => 'green'];
    }
    return ['state' => 'always', 'label' => 'No end date', 'tone' => 'gray'];
}

/** DATETIME from the database -> the value a datetime-local input expects. */
function popup_datetime_input(?string $value): string
{
    if (empty($value)) {
        return '';
    }
    $timestamp = strtotime((string) $value);
    return $timestamp === false ? '' : date('Y-m-d\TH:i', $timestamp);
}

/** datetime-local value -> a DATETIME string, or NULL when left blank. */
function popup_datetime_store(?string $value): ?string
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }
    $timestamp = strtotime($value);
    return $timestamp === false ? null : date('Y-m-d H:i:s', $timestamp);
}

// ===========================================================================
//  Form input
// ===========================================================================

/**
 * Read the whole popup form into one normalised array.
 * Values are shaped here, checked in popup_validate_input(), and only then
 * written — create.php and edit.php never touch $_POST directly.
 */
function popup_form_input(): array
{
    // array_unique as well as the allowlist intersect: the checkboxes cannot
    // post the same key twice, but a hand-made POST can, and array_intersect
    // keeps duplicates - which would store "blog-post,blog-post" and read back
    // as a doubled label.
    $allPages = array_keys(popup_page_options());
    $pages = array_values(array_unique(array_intersect(
        array_map('strval', input_array('display_pages')),
        $allPages
    )));

    // Ticking every box means "all pages", so store the sentinel rather than a
    // 381-character CSV that says the same thing. It has to be the literal
    // 'all' and not an empty list: active_popups() treats both as every page,
    // but popup_validate_input() rejects '' with "Pick at least one page", so
    // emptying the array here would fail the very save it is meant to protect.
    //
    // See the note on display_pages in schema.sql for why an overflow there is
    // dangerous rather than merely wrong: sql_mode carries no strict flag, so
    // MySQL truncates mid-key in silence and the popup just stops matching.
    $pagesAll = input_bool('pages_all')
        || ($pages !== [] && count($pages) === count($allPages));

    $trigger = (string) input('trigger_type', 'timed');

    // "Immediate" and "exit intent" have nothing to measure, so the stored
    // value is forced to zero rather than keeping a stale number from a
    // trigger the admin switched away from.
    $triggerValue = popup_trigger_field($trigger)['show'] ? max(0, input_int('trigger_value', 0)) : 0;

    return [
        'name'              => (string) input('name', ''),
        'display_mode'      => (string) input('display_mode', 'popup'),
        'popup_type'        => (string) input('popup_type', 'promo'),
        'title'             => (string) input('title', ''),
        'subtitle'          => (string) input('subtitle', ''),
        'content'           => sanitize_html((string) input('content', '')),
        'video_url'         => (string) input('video_url', ''),
        'coupon_code'       => strtoupper((string) input('coupon_code', '')),
        'product_id'        => max(0, input_int('product_id', 0)),
        'button_text'       => (string) input('button_text', ''),
        'button_url'        => (string) input('button_url', ''),
        'position'          => (string) input('position', 'center'),
        'size'              => (string) input('size', 'md'),
        'bg_color'          => strtoupper((string) input('bg_color', '')),
        'text_color'        => strtoupper((string) input('text_color', '')),
        'trigger_type'      => $trigger,
        'trigger_value'     => $triggerValue,
        'frequency'         => (string) input('frequency', 'session'),
        'display_pages'     => $pagesAll ? 'all' : implode(',', $pages),
        'device_visibility' => (string) input('device_visibility', 'all'),
        'auth_visibility'   => (string) input('auth_visibility', 'all'),
        'show_close'        => input_bool('show_close') ? 1 : 0,
        'start_date'        => (string) input('start_date', ''),
        'end_date'          => (string) input('end_date', ''),
        'sort_order'        => input_int('sort_order', 0),
        'status'            => (string) input('status', 'active'),
    ];
}

/**
 * Validate a popup_form_input() array.
 *
 * @return array field => message (empty when the row is safe to write)
 */
function popup_validate_input(array $data): array
{
    $v = new Validator($data, [
        'name'          => 'Name',
        'title'         => 'Title',
        'subtitle'      => 'Subtitle',
        'coupon_code'   => 'Coupon code',
        'button_text'   => 'Button text',
        'button_url'    => 'Button URL',
        'video_url'     => 'Video URL',
        'trigger_value' => 'Trigger value',
        'sort_order'    => 'Sort order',
        'product_id'    => 'Product',
    ]);

    $v->required('name')->max('name', 150)
      ->in('display_mode', array_keys(popup_display_modes()))
      ->in('popup_type', array_keys(popup_type_options()))
      ->max('title', 200)
      ->max('subtitle', 255)
      ->max('coupon_code', 60)
      ->max('button_text', 60)
      ->max('button_url', 255)
      ->max('video_url', 255)
      ->in('position', array_keys(popup_position_options()))
      ->in('size', array_keys(popup_size_options()))
      ->in('trigger_type', array_keys(popup_trigger_options()))
      ->in('frequency', array_keys(popup_frequency_options()))
      ->in('device_visibility', array_keys(popup_device_options()))
      ->in('auth_visibility', array_keys(popup_auth_options()))
      ->in('status', ['active', 'inactive'])
      ->integer('sort_order')->between('sort_order', -9999, 9999)
      ->date('start_date')->date('end_date');

    $trigger = popup_trigger_field((string) $data['trigger_type']);
    if ($trigger['show']) {
        $v->between(
            'trigger_value',
            (float) $trigger['min'],
            (float) $trigger['max'],
            $trigger['label'] . ' must be between ' . $trigger['min'] . ' and ' . $trigger['max'] . '.'
        );
    }

    $v->rule(
        'display_pages',
        (string) $data['display_pages'] !== '',
        'Pick at least one page, or tick "All pages".'
    );

    foreach (['bg_color' => 'Background colour', 'text_color' => 'Text colour'] as $field => $label) {
        $value = (string) $data[$field];
        $v->rule(
            $field,
            $value === '' || preg_match('/^#[0-9A-F]{6}$/', $value) === 1,
            $label . ' must be a 6-digit hex value such as #0F2143.'
        );
    }

    // A storefront button may point at a path like /shop, which is not a URL
    // as far as filter_var is concerned, so only absolute links are checked.
    $buttonUrl = (string) $data['button_url'];
    $v->rule(
        'button_url',
        $buttonUrl === '' || stripos($buttonUrl, 'http') !== 0 || filter_var($buttonUrl, FILTER_VALIDATE_URL) !== false,
        'Enter a full URL, or a path such as /shop.'
    );

    $v->url('video_url');

    $productId = (int) $data['product_id'];
    if ($productId > 0 && !Database::exists('products', '`id` = :id', ['id' => $productId])) {
        $v->rule('product_id', false, 'That product no longer exists.');
    }

    $start = popup_datetime_store((string) $data['start_date']);
    $end   = popup_datetime_store((string) $data['end_date']);
    $v->rule(
        'end_date',
        $start === null || $end === null || $end > $start,
        'The end date has to be after the start date.'
    );

    // A popup whose type carries no payload renders an empty box on the
    // storefront, so the payload is required rather than merely suggested.
    switch ((string) $data['popup_type']) {
        case 'coupon':
            $v->rule('coupon_code', (string) $data['coupon_code'] !== '', 'A coupon popup needs a code to show.');
            break;
        case 'product':
            $v->rule('product_id', $productId > 0, 'A product popup needs a product to feature.');
            break;
        case 'video':
            $v->rule('video_url', (string) $data['video_url'] !== '', 'A video popup needs a video URL.');
            break;
    }

    return $v->errors();
}

/**
 * Map a validated popup_form_input() array onto the `popups` columns.
 * Image paths are handled by the caller because create and edit differ.
 */
function popup_row_from_input(array $data, ?string $image, ?string $mobileImage): array
{
    $nullable = static fn (string $value): ?string => $value !== '' ? $value : null;

    return [
        'name'              => $data['name'],
        'display_mode'      => $data['display_mode'],
        'popup_type'        => $data['popup_type'],
        'title'             => $nullable((string) $data['title']),
        'subtitle'          => $nullable((string) $data['subtitle']),
        'content'           => $nullable((string) $data['content']),
        'image'             => $image,
        'mobile_image'      => $mobileImage,
        'video_url'         => $nullable((string) $data['video_url']),
        'coupon_code'       => $nullable((string) $data['coupon_code']),
        'product_id'        => (int) $data['product_id'] > 0 ? (int) $data['product_id'] : null,
        'button_text'       => $nullable((string) $data['button_text']),
        'button_url'        => $nullable((string) $data['button_url']),
        'position'          => $data['position'],
        'size'              => $data['size'],
        'bg_color'          => $nullable((string) $data['bg_color']),
        'text_color'        => $nullable((string) $data['text_color']),
        'trigger_type'      => $data['trigger_type'],
        'trigger_value'     => (int) $data['trigger_value'],
        'frequency'         => $data['frequency'],
        'display_pages'     => $data['display_pages'],
        'device_visibility' => $data['device_visibility'],
        'auth_visibility'   => $data['auth_visibility'],
        'show_close'        => (int) $data['show_close'],
        'start_date'        => popup_datetime_store((string) $data['start_date']),
        'end_date'          => popup_datetime_store((string) $data['end_date']),
        'sort_order'        => (int) $data['sort_order'],
        'status'            => $data['status'],
    ];
}
