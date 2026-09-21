<?php
/**
 * ShopInnKart Admin - Menu builder vocabulary.
 *
 * Locations, link types and the reference lookups live here so index.php and
 * item-save.php validate against exactly the same sets.
 */

declare(strict_types=1);

// Include-only: the parent page already ran the auth and permission checks.
if (!defined('SIK_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

/**
 * The storefront's own menu renderers.
 *
 * Every screen in this folder needs them: the item editor and its preview draw
 * a badge chip with menu_badge_chip() and a glyph with menu_item_glyph(), the
 * panel builder renders a whole panel through build_menu(), and panel-save.php
 * normalises a colour with menu_badge_color(). Required here rather than four
 * times over, and required at all because the admin layout does not pull it in
 * the way the storefront header does.
 */
require_once INCLUDES_PATH . '/menu-functions.php';

/**
 * The menu locations this screen edits.
 *
 * Only two of them reach the storefront: includes/header.php calls build_menu()
 * for 'main' and 'mobile' and nothing calls it for the other four. The thin
 * strip above the header is drawn from `announcements`, and the footer's link
 * columns from `footer_columns` / `footer_links` — Admin > Footer owns both, and
 * re-rendering them from here would put two editors on one strip of the page.
 *
 * So the four are labelled for what they are rather than quietly promising a
 * control they do not have. Their rows are left in place: 16 saved items sit
 * under the three footer locations, and dropping the tabs would strand them
 * where no screen could reach them.
 */
function menu_locations(): array
{
    return [
        'main'           => ['label' => 'Main navigation', 'icon' => 'menu',   'sub' => 'The desktop header bar, including mega panels'],
        'mobile'         => ['label' => 'Mobile drawer',   'icon' => 'smartphone', 'sub' => 'The slide-out menu; falls back to Main when empty'],
        'topbar'         => ['label' => 'Top bar',         'icon' => 'chevron-up', 'sub' => 'Not rendered — the strip above the header comes from Admin > Announcements'],
        'footer_quick'   => ['label' => 'Footer — Quick links', 'icon' => 'list', 'sub' => 'Not rendered — the footer link columns come from Admin > Footer'],
        'footer_service' => ['label' => 'Footer — Customer service', 'icon' => 'headset', 'sub' => 'Not rendered — the footer link columns come from Admin > Footer'],
        'footer_about'   => ['label' => 'Footer — About',   'icon' => 'info',   'sub' => 'Not rendered — the footer link columns come from Admin > Footer'],
    ];
}

/** Default name used when a location's menu row is created. */
function menu_default_name(string $location): string
{
    return (string) (menu_locations()[$location]['label'] ?? ucfirst(str_replace('_', ' ', $location)));
}

function menu_link_types(): array
{
    return [
        'custom'   => 'Custom URL',
        'category' => 'Category',
        'brand'    => 'Brand',
        'page'     => 'CMS page',
        'route'    => 'Store page',
    ];
}

/** Link types that resolve through reference_id. */
function menu_reference_types(): array
{
    return ['category', 'brand', 'page'];
}

/**
 * Built-in storefront routes worth linking to. Every entry is a real file at
 * the project root, so none of these can 404.
 */
function menu_routes(): array
{
    return [
        'index.php'           => 'Home',
        'shop.php'            => 'Shop — all products',
        'deals.php'           => 'Deals',
        'new-arrivals.php'    => 'New Arrivals',
        'best-sellers.php'    => 'Best Sellers',
        'brands.php'          => 'All Brands',
        'blog.php'            => 'Blog',
        'about.php'           => 'About Us',
        'contact.php'         => 'Contact Us',
        'faq.php'             => 'FAQ',
        'track-order.php'     => 'Track Order',
        'compare.php'         => 'Compare',
        'wishlist.php'        => 'Wishlist',
        'cart.php'            => 'Cart',
        'account.php'         => 'My Account',
        'orders.php'          => 'My Orders',
        'login.php'           => 'Sign In',
        'register.php'        => 'Create Account',
        'shipping-policy.php' => 'Shipping Policy',
        'return-policy.php'   => 'Return Policy',
        'refund-policy.php'   => 'Refund Policy',
        'warranty.php'        => 'Warranty',
        'privacy-policy.php'  => 'Privacy Policy',
        'terms.php'           => 'Terms & Conditions',
        'sitemap.php'         => 'Sitemap',
    ];
}

function menu_device_visibility(): array
{
    return ['all' => 'All devices', 'desktop' => 'Desktop only', 'mobile' => 'Mobile only'];
}

/**
 * Where an item's ICON is drawn — a different question from where the item is.
 *
 * `device_visibility` above takes the whole row off a surface. This one takes
 * only the glyph off it, which is what an operator means by "no icons in the
 * drawer": the link still has to be reachable there. 'none' is the off switch
 * and keeps the chosen icon on file, so putting it back is one select rather
 * than a second hunt through ninety glyphs.
 */
function menu_icon_visibility(): array
{
    return [
        'all'     => 'Desktop and mobile',
        'desktop' => 'Desktop bar and panels only',
        'mobile'  => 'Mobile drawer only',
        'none'    => 'Hidden — keep the icon, do not draw it',
    ];
}

/**
 * The seven badges the store actually uses, as one-click fills.
 *
 * Each preset writes the text, the colour and the style into the form; nothing
 * is stored as "this item is a HOT badge", because the moment it were, renaming
 * HOT to SUPER would need a migration. What lands in the row is what the fields
 * say, and CUSTOM is simply the preset that fills nothing in.
 *
 * The colours are the palette's own: they are the darker shades on purpose, the
 * same reason .sik-badge--green points at --sik-success-ink. An 11px uppercase
 * chip is small text, and menu_badge_ink() would darken a brighter pick anyway
 * — starting at the accessible shade means the picker shows the colour that
 * will actually render.
 *
 * @return array<string, array{label:string, text:string, color:string, style:string, animation:string, hint:string}>
 */
function menu_badge_presets(): array
{
    return [
        'hot' => [
            'label' => 'HOT', 'text' => 'HOT', 'color' => '#BE3F17', 'style' => 'solid',
            'animation' => 'none', 'hint' => 'Selling fast right now',
        ],
        'new' => [
            'label' => 'NEW', 'text' => 'NEW', 'color' => '#0F2143', 'style' => 'solid',
            'animation' => 'none', 'hint' => 'Just added to the catalogue',
        ],
        'sale' => [
            'label' => 'SALE', 'text' => 'SALE', 'color' => '#DC2626', 'style' => 'solid',
            'animation' => 'none', 'hint' => 'Price is down',
        ],
        'trending' => [
            'label' => 'TRENDING', 'text' => 'TRENDING', 'color' => '#15803D', 'style' => 'soft',
            'animation' => 'none', 'hint' => 'Popular this week',
        ],
        'offer' => [
            'label' => 'OFFER', 'text' => 'OFFER', 'color' => '#7C3AED', 'style' => 'soft',
            'animation' => 'none', 'hint' => 'A bank or bundle deal',
        ],
        'limited' => [
            'label' => 'LIMITED', 'text' => 'LIMITED', 'color' => '#B45309', 'style' => 'outline',
            // The one preset that pulses: "limited" is the only one of the seven
            // whose meaning is about time running out, so it is the only one
            // where movement says something the word does not.
            'animation' => 'pulse', 'hint' => 'Stock or window is closing',
        ],
        'custom' => [
            'label' => 'CUSTOM', 'text' => '', 'color' => '', 'style' => 'solid',
            'animation' => 'none', 'hint' => 'Type your own text and colour',
        ],
    ];
}

function menu_badge_styles(): array
{
    return [
        'solid'   => 'Solid — colour fills the chip',
        'soft'    => 'Soft — tinted chip, coloured text',
        'outline' => 'Outline — hairline border, no fill',
    ];
}

function menu_badge_positions(): array
{
    return ['after' => 'After the label', 'before' => 'Before the label'];
}

function menu_badge_animations(): array
{
    return ['none' => 'None', 'pulse' => 'Slow pulse'];
}

function menu_auth_visibility(): array
{
    return ['all' => 'Everyone', 'guest' => 'Signed-out visitors', 'user' => 'Signed-in customers'];
}

/** id => label lists for the dependent reference picker. */
function menu_reference_lists(): array
{
    static $lists = null;
    if ($lists !== null) {
        return $lists;
    }

    $categories = Database::fetchAll('SELECT `id`, `name`, `parent_id` FROM `categories` ORDER BY `sort_order`, `name`');
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
        'page'     => Database::fetchPairs("SELECT `id`, `title` FROM `pages` WHERE `status` = 'active' ORDER BY `title`"),
    ];
}

/** Human-readable description of where a menu item points. */
function menu_item_target(array $item): string
{
    $type = (string) $item['link_type'];

    if (in_array($type, menu_reference_types(), true)) {
        $lists = menu_reference_lists();
        $name = $lists[$type][(int) ($item['reference_id'] ?? 0)] ?? null;
        return $name !== null ? trim(str_replace('—', '', $name)) : 'Not set';
    }
    if ($type === 'route') {
        $url = (string) ($item['url'] ?? '');
        return menu_routes()[$url] ?? ($url !== '' ? $url : 'Not set');
    }

    $url = trim((string) ($item['url'] ?? ''));
    return $url === '' ? 'Not set' : $url;
}

/**
 * Every descendant id of an item, so the parent picker can refuse to build
 * a loop. Walks the child map rather than recursing into the database.
 */
function menu_descendant_ids(array $childrenOf, int $itemId): array
{
    $found = [];
    $stack = [$itemId];

    while ($stack !== []) {
        $current = (int) array_pop($stack);
        foreach ($childrenOf[$current] ?? [] as $child) {
            $childId = (int) $child['id'];
            if (isset($found[$childId])) {
                continue;
            }
            $found[$childId] = true;
            $stack[] = $childId;
        }
    }

    return array_keys($found);
}

/** Menu items for a menu, grouped by parent id (0 = top level). */
function menu_children_map(int $menuId): array
{
    $rows = Database::fetchAll(
        'SELECT * FROM `menu_items` WHERE `menu_id` = :id ORDER BY `sort_order` ASC, `id` ASC',
        ['id' => $menuId]
    );

    $childrenOf = [];
    foreach ($rows as $row) {
        $childrenOf[(int) ($row['parent_id'] ?? 0)][] = $row;
    }
    return $childrenOf;
}
