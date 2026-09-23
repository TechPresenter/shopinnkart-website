<?php
/**
 * ShopInnKart Admin - Appearance registry.
 *
 * Admin > Appearance is a HUB, not a seventeenth settings form. Every control it
 * talks about is owned by a screen that already exists - Settings → Theme, the
 * Menu Builder, the Footer Builder, the Popup manager - and this file is the map
 * that lets one page describe all of them without becoming a second place to
 * edit any of them.
 *
 * Three things live here and nowhere else:
 *
 *   APPEARANCE_KEYS      which section owns which settings key, for reset.
 *                        Exactly one section owns a key; other sections may
 *                        show it read-only, so "Reset this section" can never
 *                        surprise anyone with a key they thought belonged
 *                        somewhere else.
 *   appearance_sections()  what each section controls, where its real editor
 *                        is, and how to read its current state.
 *   appearance_specs()   the harvested field specs the shipped defaults come
 *                        from.
 *
 * What is deliberately NOT here: any default value. Every value used by
 * "Reset to shipped defaults" is read back out of the screen that owns the
 * field - Settings → Theme's THEME_DEFAULTS, Settings → Widgets' per-field
 * `default`, includes/header-settings.php's HEADER_DEFAULTS - through
 * settings_spec_harvest(). A copy here would be a second source of truth, and a
 * reset button driven by a stale copy is worse than no reset button at all.
 */

declare(strict_types=1);

// Include-only: the parent page already ran authentication and permissions.
// The .htaccess rule refuses /_*.php outright; this is the backstop for a
// host that does not read .htaccess at all.
if (!defined('SIK_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

require_once ADMIN_PATH . '/settings/_layout.php';
require_once ADMIN_PATH . '/appearance/_header-spec.php';

/**
 * Reset ownership: section => the settings keys resetting it restores.
 *
 * A key that appears here must exist in appearance_specs(); the hub reports any
 * that does not rather than silently skipping it, because a typo here would
 * otherwise turn into a control that quietly never resets.
 *
 * Sections absent from this map own no settings at all. That is a real state,
 * not an oversight - the Menu, Mega Menu, Categories and Order Tracking cards
 * say so on screen.
 */
const APPEARANCE_KEYS = [
    'header' => [
        'menu_style', 'header_logo_height', 'header_show_tagline', 'header_height',
        'header_nav_enabled', 'menu_align', 'menu_spacing', 'menu_item_padding',
        'menu_hover_effect', 'header_show_notifications', 'header_show_cart',
        'header_show_account', 'header_show_track', 'header_search_width',
        'header_search_placeholder', 'header_search_popular',
        'header_search_voice', 'header_bg', 'header_border_width', 'header_border_color',
        'header_shadow', 'header_stuck_shadow', 'header_nav_bg', 'menu_link_color',
        'menu_hover_color', 'menu_hover_bg', 'menu_active_color',
        'announce_bg', 'announce_color',
        'header_style',                       // theme group - sticky vs static
        'ticker_enabled', 'ticker_speed',     // widgets group - the announcement strip
    ],
    'footer' => ['footer_style'],
    'popup'  => ['popups_enabled', 'popins_enabled'],
    'wishlist' => [
        'wishlist_enabled', 'wishlist_show_header', 'wishlist_show_card',
        'wishlist_show_pdp', 'wishlist_guest_mode', 'header_show_wishlist',
    ],
    'compare' => [
        'compare_enabled', 'compare_show_header', 'compare_show_card',
        'compare_show_pdp', 'compare_guest_mode', 'max_compare_items',
        'header_show_compare',
    ],
    'floating'   => ['floating_buttons_enabled'],
    'typography' => ['font_family', 'menu_font_size', 'menu_font_weight', 'menu_text_transform'],
    'colors'     => [
        'primary_color', 'secondary_color', 'accent_color', 'body_bg', 'soft_bg',
        'text_color', 'muted_color', 'border_color',
    ],
    'buttons' => ['button_color', 'button_text_color', 'button_style', 'border_radius'],
    'icons'   => [
        'header_icon_size', 'header_action_size', 'header_action_color', 'header_action_hover_bg',
    ],
    'mobile' => [
        'header_height_mobile', 'header_burger_position', 'header_search_from',
        'mobile_bottom_nav',
    ],
    'general' => [
        'container_width', 'card_style', 'product_card_style', 'enable_animations',
        'custom_css', 'custom_js', 'recently_viewed_enabled',
    ],
];

/**
 * Every field spec that backs an Appearance control, merged into one map of
 * key => spec, plus key => shipped default and key => settings group.
 *
 * Sources, in the order they are read:
 *   Settings → Theme     (harvested)   colours, shape, type, custom code
 *   Settings → Widgets   (harvested)   popups, wishlist, compare, mobile, ticker
 *   Settings → Social    (harvested)   the profile URLs
 *   header_field_spec()  (derived)     the 39-knob header contract
 *
 * Social is harvested even though the hub refuses to reset it, so the card can
 * still show the real field list and the real labels.
 */
function appearance_specs(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $spec = [];
    $defaults = [];
    $groups = [];

    foreach (['theme', 'widgets', 'social'] as $screen) {
        $harvest = settings_spec_harvest($screen);
        foreach ($harvest['spec'] as $key => $field) {
            $spec[$key]     = $field;
            $defaults[$key] = (string) ($harvest['defaults'][$key] ?? '');
            $groups[$key]   = settings_spec_group($harvest, $key);
        }
    }

    $headerDefaults = header_field_defaults();
    foreach (header_field_spec() as $key => $field) {
        $spec[$key]     = $field;
        $defaults[$key] = (string) ($headerDefaults[$key] ?? '');
        $groups[$key]   = 'header';
    }

    return $cache = ['spec' => $spec, 'defaults' => $defaults, 'groups' => $groups];
}

/** Currently stored value for every key Appearance knows about. */
function appearance_stored(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $stored = [];
    foreach (['theme', 'widgets', 'social', 'header', 'store'] as $group) {
        $stored += settings_group($group);
    }

    return $cache = $stored;
}

/**
 * The live value of a key: what is stored, or the shipped default when nothing
 * has ever been saved. This is the same precedence the storefront applies.
 */
function appearance_value(string $key): string
{
    $stored = appearance_stored();
    if (array_key_exists($key, $stored)) {
        return (string) $stored[$key];
    }
    return (string) (appearance_specs()['defaults'][$key] ?? '');
}

/** Keys in this section whose live value differs from the shipped default. */
function appearance_changed_keys(string $section): array
{
    $defaults = appearance_specs()['defaults'];
    $changed  = [];

    foreach (APPEARANCE_KEYS[$section] ?? [] as $key) {
        if (!array_key_exists($key, $defaults)) {
            continue;
        }
        if (appearance_value($key) !== (string) $defaults[$key]) {
            $changed[] = $key;
        }
    }

    return $changed;
}

/**
 * Keys named in APPEARANCE_KEYS that no harvested spec knows about.
 *
 * Rendered on the hub as a warning. A section whose keys have drifted out of
 * their owning screen would otherwise reset nothing and say nothing.
 */
function appearance_unknown_keys(): array
{
    $defaults = appearance_specs()['defaults'];
    $unknown  = [];

    foreach (APPEARANCE_KEYS as $section => $keys) {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $defaults)) {
                $unknown[] = $section . '.' . $key;
            }
        }
    }

    return $unknown;
}

/**
 * Harvested keys that no Appearance section claims.
 *
 * Also rendered on the hub. These are not bugs by definition - the admin theme
 * colours and the social URLs are excluded on purpose - but listing them is
 * what stops the hub from quietly pretending a setting does not exist.
 */
function appearance_unclaimed_keys(): array
{
    $claimed = [];
    foreach (APPEARANCE_KEYS as $keys) {
        foreach ($keys as $key) {
            $claimed[$key] = true;
        }
    }

    return array_values(array_diff(array_keys(appearance_specs()['spec']), array_keys($claimed)));
}

// ===========================================================================
//  STATE READOUTS
// ===========================================================================

/** A small pill: ['Label', 'value', tone]. */
function appearance_chip(string $label, string $value, string $tone = 'gray'): array
{
    return ['label' => $label, 'value' => $value, 'tone' => $tone];
}

/** on/off pill for a boolean setting key. */
function appearance_switch_chip(string $label, string $key): array
{
    $on = appearance_value($key) === '1';
    return appearance_chip($label, $on ? 'On' : 'Off', $on ? 'green' : 'gray');
}

/** Row counts the hub needs, in one query each, cached for the request. */
function appearance_counts(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $count = static function (string $sql): int {
        try {
            return (int) Database::fetchColumn($sql);
        } catch (Throwable $e) {
            return 0;
        }
    };

    return $cache = [
        'menus'          => $count('SELECT COUNT(*) FROM `menus`'),
        'menu_items'     => $count('SELECT COUNT(*) FROM `menu_items`'),
        'menu_active'    => $count("SELECT COUNT(*) FROM `menu_items` WHERE `status` = 'active'"),
        'menu_icons'     => $count("SELECT COUNT(*) FROM `menu_items` WHERE `icon` <> ''"),
        'mega_parents'   => $count('SELECT COUNT(DISTINCT `parent_id`) FROM `menu_items` WHERE `parent_id` IS NOT NULL'),
        'mega_promo'     => $count('SELECT COUNT(*) FROM `menu_items` WHERE `mega_promo` = 1'),
        'categories'     => $count('SELECT COUNT(*) FROM `categories`'),
        'cat_active'     => $count("SELECT COUNT(*) FROM `categories` WHERE `status` = 'active'"),
        'cat_featured'   => $count('SELECT COUNT(*) FROM `categories` WHERE `is_featured` = 1'),
        'footer_columns' => $count('SELECT COUNT(*) FROM `footer_columns`'),
        'footer_links'   => $count('SELECT COUNT(*) FROM `footer_links`'),
        'popups'         => $count('SELECT COUNT(*) FROM `popups`'),
        'popups_active'  => $count("SELECT COUNT(*) FROM `popups` WHERE `status` = 'active'"),
        'floating'       => $count('SELECT COUNT(*) FROM `floating_buttons`'),
        'floating_on'    => $count("SELECT COUNT(*) FROM `floating_buttons` WHERE `status` = 'active'"),
        'banners'        => $count("SELECT COUNT(*) FROM `banners` WHERE `status` = 'active'"),
    ];
}

// ===========================================================================
//  THE SECTIONS
// ===========================================================================

/**
 * Every Appearance section, in the order the operator was promised them.
 *
 * Each entry carries:
 *   label, icon, blurb   what it is and what it controls
 *   status               ready | partial | none
 *                        `none` means there is genuinely nothing to configure
 *                        yet and the card says so instead of linking nowhere.
 *   chips                live state, read from the database on every load
 *   links                the real editors. The first is the primary action.
 *   reset                '' when the section owns no settings, otherwise the
 *                        section key; 'refuse' when it owns settings that must
 *                        not be reset, with `reset_note` explaining why.
 */
function appearance_sections(): array
{
    $c = appearance_counts();
    $h = appearance_specs();

    $styleLabel = HEADER_MENU_STYLES[appearance_value('menu_style')]['label']
        ?? appearance_value('menu_style');

    $socialFilled = 0;
    $socialTotal  = 0;
    foreach (array_keys($h['spec']) as $key) {
        if (str_starts_with($key, 'social_')) {
            $socialTotal++;
            if (trim(appearance_value($key)) !== '') {
                $socialFilled++;
            }
        }
    }

    return [
        // -------------------------------------------------------------- header
        'header' => [
            'label' => 'Header',
            'icon'  => 'grid',
            'blurb' => 'Menu style, height, logo size, the search field, which action icons appear, '
                . 'and the header\'s own colours, borders and shadows.',
            'status' => 'ready',
            'chips' => [
                appearance_chip('Style', $styleLabel, 'navy'),
                appearance_chip('Height', appearance_value('header_height') . 'px'),
                appearance_switch_chip('Category bar', 'header_nav_enabled'),
                appearance_switch_chip('Ticker', 'ticker_enabled'),
                appearance_chip('Sticky', appearance_value('header_style') === 'sticky' ? 'Yes' : 'No'),
            ],
            'links' => [
                ['label' => 'Open the Header editor', 'url' => admin_url('appearance/header.php'), 'primary' => true],
                ['label' => 'Announcement ticker', 'url' => admin_url('settings/widgets.php')],
                ['label' => 'Sticky / static', 'url' => admin_url('settings/theme.php')],
            ],
            'reset' => 'header',
        ],

        // ---------------------------------------------------------------- menu
        'menu' => [
            'label' => 'Menu',
            'icon'  => 'menu',
            'blurb' => 'The navigation itself - labels, order, nesting, badges and per-device '
                . 'visibility - across the five menu locations.',
            'status' => 'ready',
            'chips' => [
                appearance_chip('Locations', (string) $c['menus'], 'navy'),
                appearance_chip('Items', (string) $c['menu_items']),
                appearance_chip('Active', (string) $c['menu_active'], $c['menu_active'] > 0 ? 'green' : 'gray'),
            ],
            'links' => [
                ['label' => 'Open the Menu Builder', 'url' => admin_url('menus/'), 'primary' => true, 'permission' => 'homepage.edit'],
            ],
            'reset' => '',
            'reset_note' => 'The menu is content, not settings: resetting it would mean deleting rows '
                . 'an operator wrote. Remove items in the builder instead.',
        ],

        // ----------------------------------------------------------- mega menu
        'mega' => [
            'label' => 'Mega Menu',
            'icon'  => 'list',
            'blurb' => 'The panel that drops from a top-level menu item: which children show, their '
                . 'order, their glyphs and badges, and the optional promo tile.',
            'status' => 'ready',
            'chips' => [
                appearance_chip('Panels', (string) $c['mega_parents'], 'navy'),
                appearance_chip('Promo tiles', (string) $c['mega_promo'], $c['mega_promo'] > 0 ? 'green' : 'gray'),
                appearance_chip('Layout', 'Single column'),
            ],
            'links' => [
                ['label' => 'Open the Menu Builder', 'url' => admin_url('menus/'), 'primary' => true, 'permission' => 'homepage.edit'],
            ],
            'reset' => '',
            'reset_note' => 'Panels are menu rows. The Menu Builder edits them one panel at a time.',
        ],

        // ---------------------------------------------------------- categories
        'categories' => [
            'label' => 'Categories',
            'icon'  => 'package',
            'blurb' => 'The category records themselves - names, images, ordering and which ones are '
                . 'featured - plus the homepage category strip that renders them.',
            'status' => 'ready',
            'chips' => [
                appearance_chip('Categories', (string) $c['categories'], 'navy'),
                appearance_chip('Active', (string) $c['cat_active'], $c['cat_active'] > 0 ? 'green' : 'gray'),
                appearance_chip('Featured', (string) $c['cat_featured']),
            ],
            'links' => [
                ['label' => 'Manage categories', 'url' => admin_url('categories/'), 'primary' => true, 'permission' => 'categories.view'],
                ['label' => 'Homepage category strip', 'url' => admin_url('homepage/categories.php'), 'permission' => 'homepage.edit'],
            ],
            'reset' => '',
            'reset_note' => 'Categories are catalogue data. Nothing here resets them.',
        ],

        // -------------------------------------------------------------- footer
        'footer' => [
            'label' => 'Footer',
            'icon'  => 'grid',
            'blurb' => 'Footer columns and their links, the newsletter and payment blocks, and '
                . 'whether the footer renders dark or light.',
            'status' => 'ready',
            'chips' => [
                appearance_chip('Columns', (string) $c['footer_columns'], 'navy'),
                appearance_chip('Links', (string) $c['footer_links']),
                appearance_chip('Tone', ucfirst(appearance_value('footer_style'))),
            ],
            'links' => [
                ['label' => 'Open the Footer Builder', 'url' => admin_url('footer/'), 'primary' => true, 'permission' => 'homepage.edit'],
                ['label' => 'Dark / light tone', 'url' => admin_url('settings/theme.php')],
            ],
            'reset' => 'footer',
            'reset_note' => 'Reset restores the dark/light tone only. Your columns and links are content '
                . 'and are never touched.',
        ],

        // --------------------------------------------------------------- popup
        'popup' => [
            'label' => 'Popup',
            'icon'  => 'bell',
            'blurb' => 'Popup modals and corner pop-ins: their content, targeting and triggers, plus '
                . 'the two master switches that silence them store-wide.',
            'status' => 'ready',
            'chips' => [
                appearance_chip('Popups', (string) $c['popups'], 'navy'),
                appearance_chip('Active', (string) $c['popups_active'], $c['popups_active'] > 0 ? 'green' : 'gray'),
                appearance_switch_chip('Modals', 'popups_enabled'),
                appearance_switch_chip('Pop-ins', 'popins_enabled'),
            ],
            'links' => [
                ['label' => 'Manage popups', 'url' => admin_url('popups/'), 'primary' => true, 'permission' => 'banners.view'],
                ['label' => 'Master switches', 'url' => admin_url('settings/widgets.php')],
            ],
            'reset' => 'popup',
            'reset_note' => 'Reset turns both master switches back on. Your popup rows are content and '
                . 'are never touched.',
        ],

        // ------------------------------------------------------------ wishlist
        'wishlist' => [
            'label' => 'Wishlist',
            'icon'  => 'heart',
            'blurb' => 'Whether the wishlist exists at all, where its button appears, and what happens '
                . 'to a guest\'s list when they sign in.',
            'status' => 'ready',
            'chips' => [
                appearance_switch_chip('Feature', 'wishlist_enabled'),
                appearance_switch_chip('Header icon', 'header_show_wishlist'),
                appearance_switch_chip('Product card', 'wishlist_show_card'),
                appearance_chip('Guests', ucfirst(appearance_value('wishlist_guest_mode'))),
            ],
            'links' => [
                ['label' => 'Wishlist settings', 'url' => admin_url('settings/widgets.php'), 'primary' => true],
                ['label' => 'Header icon', 'url' => admin_url('appearance/header.php')],
            ],
            'reset' => 'wishlist',
        ],

        // ------------------------------------------------------------- compare
        'compare' => [
            'label' => 'Compare',
            'icon'  => 'compare',
            'blurb' => 'The comparison tray: on or off, where its button appears, how many products '
                . 'fit, and what guests may do.',
            'status' => 'ready',
            'chips' => [
                appearance_switch_chip('Feature', 'compare_enabled'),
                appearance_switch_chip('Header icon', 'header_show_compare'),
                appearance_chip('Max items', appearance_value('max_compare_items')),
                appearance_chip('Guests', ucfirst(appearance_value('compare_guest_mode'))),
            ],
            'links' => [
                ['label' => 'Comparison settings', 'url' => admin_url('settings/widgets.php'), 'primary' => true],
                ['label' => 'Header icon', 'url' => admin_url('appearance/header.php')],
            ],
            'reset' => 'compare',
        ],

        // ------------------------------------------------------ order tracking
        'tracking' => [
            'label' => 'Order Tracking',
            'icon'  => 'truck',
            'blurb' => 'The public /track-order.php page. It is live and it works, but it has no '
                . 'appearance settings of its own yet - it inherits the theme like any other page.',
            'status' => 'none',
            'chips' => [
                appearance_chip('Page', 'Live'),
                appearance_chip('Settings', 'None yet', 'amber'),
            ],
            'links' => [
                ['label' => 'View the page', 'url' => url('track-order.php'), 'external' => true],
                ['label' => 'Order number format', 'url' => admin_url('settings/store.php')],
            ],
            'reset' => '',
            'reset_note' => 'Nothing to reset: this page is scheduled for a later pass and has no '
                . 'settings behind it. The only thing it reads is the order-number prefix.',
        ],

        // ---------------------------------------------------- floating buttons
        'floating' => [
            'label' => 'Floating Buttons',
            'icon'  => 'sparkle',
            'blurb' => 'The WhatsApp, call, scroll-to-top and custom link buttons that float over the '
                . 'storefront, with their own position, colour, animation and device rules.',
            'status' => 'ready',
            'chips' => [
                appearance_chip('Buttons', (string) $c['floating'], 'navy'),
                appearance_chip('Active', (string) $c['floating_on'], $c['floating_on'] > 0 ? 'green' : 'gray'),
                appearance_switch_chip('Master switch', 'floating_buttons_enabled'),
            ],
            'links' => [
                ['label' => 'Open the button builder', 'url' => admin_url('floating/'), 'primary' => true, 'permission' => 'homepage.view'],
                ['label' => 'Master switch', 'url' => admin_url('settings/widgets.php')],
            ],
            'reset' => 'floating',
            'reset_note' => 'Reset turns the master switch back on. Your button rows are content and are '
                . 'never touched.',
        ],

        // ---------------------------------------------------------- typography
        'typography' => [
            'label' => 'Typography',
            'icon'  => 'edit',
            'blurb' => 'The storefront font stack, and the size, weight and case of the navigation '
                . 'links. Body type scale is a design token, not a setting.',
            'status' => 'ready',
            'chips' => [
                appearance_chip('Font', appearance_value('font_family'), 'navy'),
                appearance_chip('Nav size', appearance_value('menu_font_size') . 'px'),
                appearance_chip('Nav weight', appearance_value('menu_font_weight')),
                appearance_chip('Nav case', appearance_value('menu_text_transform')),
            ],
            'links' => [
                ['label' => 'Font family', 'url' => admin_url('settings/theme.php'), 'primary' => true],
                ['label' => 'Navigation type', 'url' => admin_url('appearance/header.php') . '#type'],
            ],
            'reset' => 'typography',
        ],

        // -------------------------------------------------------------- colors
        'colors' => [
            'label' => 'Colors',
            'icon'  => 'sparkle',
            'blurb' => 'The eight palette tokens every storefront surface derives from. Button colours '
                . 'have their own card below.',
            'status' => 'ready',
            'chips' => [],
            'swatches' => ['primary_color', 'secondary_color', 'accent_color', 'body_bg', 'soft_bg', 'text_color', 'muted_color', 'border_color'],
            'links' => [
                ['label' => 'Edit the palette', 'url' => admin_url('settings/theme.php'), 'primary' => true],
            ],
            'reset' => 'colors',
        ],

        // ------------------------------------------------------------- buttons
        'buttons' => [
            'label' => 'Buttons',
            'icon'  => 'check-circle',
            'blurb' => 'Button fill, label colour and shape, plus the corner radius that every card, '
                . 'input and button shares.',
            'status' => 'ready',
            'chips' => [
                appearance_chip('Shape', ucfirst(appearance_value('button_style')), 'navy'),
                appearance_chip('Radius', appearance_value('border_radius') . 'px'),
            ],
            'swatches' => ['button_color', 'button_text_color'],
            'links' => [
                ['label' => 'Edit button styling', 'url' => admin_url('settings/theme.php'), 'primary' => true],
            ],
            'reset' => 'buttons',
        ],

        // --------------------------------------------------------------- icons
        'icons' => [
            'label' => 'Icons',
            'icon'  => 'star',
            'blurb' => 'Icon sizing and colour in the header, and the glyph chosen for each menu item '
                . 'and floating button. The glyph set itself ships in code.',
            'status' => 'ready',
            'chips' => [
                appearance_chip('Set', count(icon_paths()) . ' glyphs', 'navy'),
                appearance_chip('Menu icons set', $c['menu_icons'] . ' / ' . $c['menu_items']),
                appearance_chip('Header size', appearance_value('header_icon_size') . 'px'),
                appearance_chip('Tap target', appearance_value('header_action_size') . 'px'),
            ],
            'links' => [
                ['label' => 'Header icon sizing', 'url' => admin_url('appearance/header.php') . '#icons', 'primary' => true],
                ['label' => 'Menu item glyphs', 'url' => admin_url('menus/'), 'permission' => 'homepage.edit'],
                ['label' => 'Floating button glyphs', 'url' => admin_url('floating/'), 'permission' => 'homepage.view'],
            ],
            'reset' => 'icons',
            'reset_note' => 'Reset restores the header icon sizing and colours. The glyph on each menu '
                . 'item is content and is never touched.',
        ],

        // -------------------------------------------------------------- mobile
        'mobile' => [
            'label' => 'Mobile',
            'icon'  => 'smartphone',
            'blurb' => 'Everything that only applies below the tablet breakpoint: header height, where '
                . 'the menu button sits, when search collapses, and the bottom bar.',
            'status' => 'ready',
            'chips' => [
                appearance_chip('Header height', appearance_value('header_height_mobile') . 'px', 'navy'),
                appearance_chip('Menu button', ucfirst(appearance_value('header_burger_position'))),
                appearance_chip('Inline search from', appearance_value('header_search_from') === 'never'
                    ? 'Never' : appearance_value('header_search_from') . 'px'),
                appearance_switch_chip('Bottom bar', 'mobile_bottom_nav'),
            ],
            'links' => [
                ['label' => 'Mobile header', 'url' => admin_url('appearance/header.php') . '#mobile', 'primary' => true],
                ['label' => 'Bottom bar', 'url' => admin_url('settings/widgets.php')],
            ],
            'reset' => 'mobile',
        ],

        // -------------------------------------------------------- social links
        'social' => [
            'label' => 'Social Links',
            'icon'  => 'globe',
            'blurb' => 'The profile URLs behind the footer\'s social icons. An empty field hides its '
                . 'icon, which is how the set stays honest.',
            'status' => 'ready',
            'chips' => [
                appearance_chip('Networks', $socialFilled . ' / ' . $socialTotal,
                    $socialFilled > 0 ? 'green' : 'gray'),
            ],
            'links' => [
                ['label' => 'Edit social links', 'url' => admin_url('settings/social.php'), 'primary' => true],
            ],
            'reset' => 'refuse',
            'reset_note' => 'Refused on purpose. The shipped default for every social field is blank, so '
                . '"restore defaults" here would mean erasing the store\'s real profile URLs - deleting '
                . 'operator data under a button labelled reset. Clear a field individually instead.',
        ],

        // ------------------------------------------------------------- general
        'general' => [
            'label' => 'General',
            'icon'  => 'settings',
            'blurb' => 'Content width, card treatment, entrance animations, the recently-viewed rail, '
                . 'and the custom CSS/JS injected into every storefront page.',
            'status' => 'ready',
            'chips' => [
                appearance_chip('Container', appearance_value('container_width') . 'px', 'navy'),
                appearance_chip('Cards', ucfirst(appearance_value('card_style'))),
                appearance_switch_chip('Animations', 'enable_animations'),
                appearance_chip('Custom CSS',
                    appearance_value('custom_css') !== '' ? mb_strlen(appearance_value('custom_css')) . ' chars' : 'None',
                    appearance_value('custom_css') !== '' ? 'amber' : 'gray'),
                appearance_chip('Custom JS',
                    appearance_value('custom_js') !== '' ? mb_strlen(appearance_value('custom_js')) . ' chars' : 'None',
                    appearance_value('custom_js') !== '' ? 'amber' : 'gray'),
            ],
            'links' => [
                ['label' => 'Layout, animations, custom code', 'url' => admin_url('settings/theme.php'), 'primary' => true],
                ['label' => 'Logo, favicon, store name', 'url' => admin_url('settings/general.php')],
                ['label' => 'Homepage sections', 'url' => admin_url('homepage/'), 'permission' => 'homepage.view'],
            ],
            'reset' => 'general',
            'reset_note' => 'Reset also clears any custom CSS and JavaScript, because blank is what the '
                . 'store shipped with.',
        ],
    ];
}

// ===========================================================================
//  SNAPSHOTS
// ===========================================================================

/**
 * Why a snapshot rather than a draft.
 *
 * A draft means "stored but not live", which needs a second place to store
 * every value and a publish step that moves it. That would be honest only if it
 * covered everything this hub links to - and most of what it links to is not
 * settings at all but rows: menu items, footer links, popups, floating buttons,
 * categories. Drafting three settings groups while the Menu Builder still wrote
 * live would produce a Save Draft button that is a lie for most of the page,
 * which is the exact failure mode this must not have.
 *
 * So the hub does the useful half instead: before it changes anything, it
 * captures the current value of every Appearance key into one JSON setting, and
 * offers to put it back. That protects the operator against the destructive
 * action this screen actually introduces - reset - without claiming a staging
 * model the codebase does not have.
 *
 * Both writing and restoring run inside a transaction, so a snapshot is never
 * half-taken and a restore is never half-applied.
 */
const APPEARANCE_SNAPSHOT_KEY = 'appearance_snapshot';

/** Capture every Appearance-owned key exactly as it stands right now. */
function appearance_snapshot_capture(string $reason): void
{
    $specs  = appearance_specs();
    $values = [];

    foreach (APPEARANCE_KEYS as $keys) {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $specs['defaults'])) {
                continue;
            }
            $values[$key] = appearance_value($key);
        }
    }

    setting_save(APPEARANCE_SNAPSHOT_KEY, (string) json_encode([
        'taken_at' => date('Y-m-d H:i:s'),
        'reason'   => $reason,
        'by'       => (string) (admin_user()['name'] ?? 'Admin'),
        'values'   => $values,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'appearance', 'json');
}

/** The stored snapshot, or null when there has never been one. */
function appearance_snapshot_read(): ?array
{
    $raw = (string) setting(APPEARANCE_SNAPSHOT_KEY, '');
    if ($raw === '') {
        return null;
    }

    $data = json_decode($raw, true);
    if (!is_array($data) || !is_array($data['values'] ?? null)) {
        return null;
    }

    return $data;
}

/**
 * Write a set of key => value pairs back into the settings table.
 *
 * One transaction, so either the whole section moves or none of it does. Keys
 * the specs do not know about are dropped rather than written, which is what
 * stops a hand-edited snapshot from becoming an arbitrary settings write.
 *
 * Returns the number of keys actually written.
 */
function appearance_write(array $values): int
{
    require_once ADMIN_PATH . '/includes/rbac.php';

    $specs      = appearance_specs();
    $canScripts = admin_can_edit_scripts();
    $written    = 0;

    Database::transaction(static function () use ($values, $specs, $canScripts, &$written): void {
        foreach ($values as $key => $value) {
            if (!array_key_exists($key, $specs['spec'])) {
                continue;
            }
            // custom_js / custom_css run in the visitor's browser on this
            // site's origin, so a section reset is not a back door into them
            // for an operator who may not edit them on Settings → Theme.
            if (!$canScripts && in_array($key, ADMIN_SCRIPT_SETTING_KEYS, true)) {
                continue;
            }
            setting_save(
                (string) $key,
                (string) $value,
                (string) ($specs['groups'][$key] ?? 'theme'),
                settings_store_type($specs['spec'][$key])
            );
            $written++;
        }
    });

    return $written;
}
