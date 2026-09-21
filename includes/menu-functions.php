<?php
/**
 * ShopInnKart - Menu builder.
 *
 * Menus live in the `menus` / `menu_items` tables and are edited from
 * Admin > Content > Menu Builder. Nothing in the navigation is hard-coded.
 */

declare(strict_types=1);

/**
 * Build a nested menu tree for a location ('main', 'mobile', 'footer_quick' ...).
 * Cached, because the header renders on every page.
 */
function build_menu(string $location): array
{
    return cache_remember('menu.' . $location, 600, static function () use ($location) {
        $menu = Database::fetch(
            "SELECT * FROM `menus` WHERE `location` = :loc AND `status` = 'active' LIMIT 1",
            ['loc' => $location]
        );
        if ($menu === null) {
            return [];
        }

        $rows = Database::fetchAll(
            "SELECT * FROM `menu_items`
             WHERE `menu_id` = :id AND `status` = 'active'
             ORDER BY `sort_order`, `id`",
            ['id' => (int) $menu['id']]
        );

        $refs = menu_reference_rows($rows);

        $byParent = [];
        foreach ($rows as $row) {
            if (!menu_item_reference_visible($row, $refs)) {
                continue;
            }

            $reference    = $refs[$row['link_type'] . ':' . (int) $row['reference_id']] ?? null;
            $row['href']  = menu_item_url($row, $reference);
            $image        = trim((string) ($reference['image'] ?? $reference['logo'] ?? ''));
            $row['image'] = $image !== '' ? $image : null;

            $row['children'] = [];
            $parentId = $row['parent_id'] === null ? 0 : (int) $row['parent_id'];
            $byParent[$parentId][] = $row;
        }

        return attach_menu_children($byParent, 0);
    });
}

/**
 * The records a set of menu items point at, one query per link *type*.
 *
 * `menu_items` stores a type and an id and nothing else, so the storefront has
 * to look the record up for three things: the picture (a category entry shows
 * the image the admin already uploaded against that category rather than a
 * second copy uploaded into the menu builder), the record's *current* slug, and
 * — for a category — whether Admin > Categories still wants it on the store at
 * all. Resolved in bulk here because a mega menu references dozens of records,
 * and this result is cached with the menu itself.
 *
 * @return array<string, array<string, mixed>> "<link_type>:<reference_id>" => row
 */
function menu_reference_rows(array $rows): array
{
    // Fixed allowlist. The table and column names below are interpolated into
    // SQL, so they may never come from a row value.
    $sources = [
        'category' => ['categories', '`id`, `slug`, `image`, `status`, `show_in_menu`'],
        'brand'    => ['brands',     '`id`, `slug`, `logo`'],
        'page'     => ['pages',      '`id`, `slug`'],
    ];

    $wanted = [];
    foreach ($rows as $row) {
        $type  = (string) ($row['link_type'] ?? '');
        $refId = (int) ($row['reference_id'] ?? 0);
        if ($refId > 0 && isset($sources[$type])) {
            $wanted[$type][$refId] = $refId;
        }
    }

    $found = ['@resolved' => []];
    foreach ($wanted as $type => $ids) {
        [$table, $columns] = $sources[$type];
        [$placeholders, $params] = Database::inPlaceholders(array_values($ids), 'r');

        try {
            $records = Database::fetchAll(
                "SELECT {$columns} FROM `{$table}` WHERE `id` IN ({$placeholders})",
                $params
            );
        } catch (Throwable $e) {
            continue;
        }

        // Records the fact that this lookup *ran*, which is not the same thing
        // as it returning rows. menu_item_reference_visible() hides a category
        // whose row is missing, so without this flag one failed query - a
        // schema that predates `show_in_menu`, say - would read as "every
        // category has been deleted" and empty the entire navigation.
        $found['@resolved'][$type] = true;

        foreach ($records as $record) {
            $found[$type . ':' . (int) $record['id']] = $record;
        }
    }

    return $found;
}

/**
 * May this item reach the storefront at all?
 *
 * The Menu Builder owns the label, the order and the nesting, but Admin >
 * Categories owns two switches it cannot see: an inactive category has no page
 * left to point at, and one with "Show in the main menu" turned off is asking
 * not to appear in navigation. Neither used to be read here, so turning either
 * one off changed nothing in the header — and an inactive category left a link
 * to a 404 in the nav, the mega panel and the mobile drawer of every page.
 *
 * A category id that resolves to no row at all is dropped for the same reason.
 * Only the reference is judged: a `custom` link, or a category item with no id
 * behind it, is the menu builder's own business and passes through untouched.
 */
function menu_item_reference_visible(array $item, array $refs): bool
{
    if ((string) ($item['link_type'] ?? '') !== 'category') {
        return true;
    }

    $refId = (int) ($item['reference_id'] ?? 0);
    if ($refId <= 0) {
        return true;
    }

    // The category lookup never ran, so "no row" carries no information. Show
    // the item rather than blanking the whole nav on a database hiccup.
    if (empty($refs['@resolved']['category'])) {
        return true;
    }

    $category = $refs['category:' . $refId] ?? null;
    if ($category === null) {
        return false;
    }

    return (string) $category['status'] === 'active' && (int) $category['show_in_menu'] === 1;
}

/**
 * Narrow a built menu tree down to what one surface is allowed to show.
 *
 * Two surfaces render the same menu: the bar in navbar.php, which CSS reveals
 * from 1024px up, and the drawer in footer.php, which is the navigation below
 * that. `device_visibility` says which of the two an item belongs to, so the
 * surface being rendered decides it — not the User-Agent. Sniffing the UA
 * instead marks an iPad in landscape as a tablet and drops every "Desktop only"
 * item from a bar the visitor can plainly see, and would empty the drawer of
 * every "Mobile only" item whenever a desktop browser is narrow enough to show
 * it. The surface is a fact about the markup; the UA is a guess about the
 * screen.
 *
 * `auth_visibility` is applied here too, and both rules are applied at every
 * depth: a "My account" link sitting inside a mega panel has to appear on
 * signing in exactly like a top-level one, and only the top level was ever
 * being filtered.
 *
 * Called at render time, never inside build_menu(), because the cached tree is
 * shared by every visitor and these two answers are not.
 *
 * @param array  $items   Nodes from build_menu(), each with a 'children' list.
 * @param string $surface 'desktop' or 'mobile'.
 */
function menu_surface_items(array $items, string $surface): array
{
    // Only 'all', 'desktop' and 'mobile' are offered by the Menu Builder; the
    // rest are the wider vocabulary visibility_allows() accepts for widgets, and
    // are mapped rather than dropped in case a row ever carries one.
    static $surfacesFor = [
        'desktop'        => ['desktop'],
        'mobile'         => ['mobile'],
        'tablet'         => ['desktop', 'mobile'],
        'desktop_tablet' => ['desktop'],
        'tablet_mobile'  => ['mobile'],
    ];

    $loggedIn = is_logged_in();
    $kept = [];

    foreach ($items as $item) {
        $device  = (string) ($item['device_visibility'] ?? 'all');
        $allowed = $surfacesFor[$device] ?? null;
        if ($allowed !== null && !in_array($surface, $allowed, true)) {
            continue;
        }

        $auth = (string) ($item['auth_visibility'] ?? 'all');
        if (($auth === 'guest' && $loggedIn) || ($auth === 'user' && !$loggedIn)) {
            continue;
        }

        $item['children'] = menu_surface_items($item['children'] ?? [], $surface);
        $kept[] = $item;
    }

    return $kept;
}

// ===========================================================================
//  Menu item presentation — the icon and the badge
//
//  Four templates draw a menu item: the bar and the mega panel in navbar.php,
//  and the drawer's parent and child rows in footer.php. Every rule about what
//  a glyph or a chip looks like lives in the three functions below so those
//  four cannot drift, which is exactly how the drawer ended up silently
//  dropping icons that the bar was drawing.
// ===========================================================================

/**
 * The glyph markup for one item on one surface, or '' for no glyph.
 *
 * `icon_visibility` is a separate switch from `device_visibility` on purpose.
 * The latter removes the whole ITEM from a surface; this one removes only the
 * picture, which is what an operator means by "no icon on mobile" — the link
 * still has to be reachable there. 'none' is the disable: the chosen icon stays
 * on file so turning it back on is one select, not a second hunt through 90
 * glyphs.
 *
 * @param string $surface 'desktop' or 'mobile' — the surface being rendered,
 *                        never the User-Agent, for the reason given on
 *                        menu_surface_items().
 */
function menu_item_glyph(array $item, string $surface, string $class = 'w-4 h-4'): string
{
    $visibility = (string) ($item['icon_visibility'] ?? 'all');
    if ($visibility === 'none' || ($visibility !== 'all' && $visibility !== $surface)) {
        return '';
    }

    return menu_glyph(isset($item['icon']) ? (string) $item['icon'] : null, $class);
}

/** Does this item draw a glyph on this surface? */
function menu_item_has_glyph(array $item, string $surface): bool
{
    return menu_item_glyph($item, $surface) !== '';
}

/**
 * The badge chip for one item, or '' when it has no badge.
 *
 * Three styles, all of which have to stay legible at --fs-2xs (11px):
 *   solid    the admin's colour as the background, light text on it
 *   soft     the same colour at 14% as a tint, the colour itself as the ink
 *   outline  no fill, the colour as a hairline border and as the ink
 *
 * Soft and outline paint TEXT in the admin's colour, so a pale pick would drop
 * an 11px chip well under AA. menu_badge_ink() is what stops that: the ink is
 * darkened until it passes against the surface it sits on, which is the "-ink
 * variant" rule applied to a colour that is not known until an admin types it.
 *
 * $onDark is the mega panel's promo tile and any navy band — there the
 * relationship inverts and the ink shade is the wrong direction to move in.
 */
function menu_badge_chip(array $item, string $baseClass = 'sik-badge', bool $onDark = false): string
{
    $text = trim((string) ($item['badge'] ?? ''));
    if ($text === '') {
        return '';
    }

    $style     = (string) ($item['badge_style'] ?? 'solid');
    $animation = (string) ($item['badge_animation'] ?? 'none');
    $color     = menu_badge_color((string) ($item['badge_color'] ?? ''));
    $ink       = menu_badge_color((string) ($item['badge_text_color'] ?? ''));

    $classes = [$baseClass];
    $css     = [];

    if ($style === 'soft' || $style === 'outline') {
        $classes[] = 'sik-badge--' . $style;
        if ($color !== '') {
            $resolved = $ink !== '' ? $ink : menu_badge_ink($color, $onDark);
            $css[] = 'color:' . $resolved;
            $css[] = $style === 'soft'
                ? 'background:' . menu_badge_tint($color, $onDark)
                : 'border-color:' . $resolved;
        } elseif ($ink !== '') {
            $css[] = 'color:' . $ink;
        }
    } else {
        // Solid. No colour at all keeps the stylesheet's own chip — that is the
        // soft neutral the panel has always fallen back to.
        if ($color === '') {
            $classes[] = 'sik-badge--soft';
            if ($ink !== '') {
                $css[] = 'color:' . $ink;
            }
        } else {
            $css[] = 'background:' . $color;
            $css[] = 'color:' . ($ink !== '' ? $ink : menu_badge_on_color($color));
        }
    }

    if ($animation === 'pulse') {
        $classes[] = 'sik-badge--pulse';
    }

    return '<span class="' . e_attr(implode(' ', $classes)) . '"'
        . ($css === [] ? '' : ' style="' . e_attr(implode(';', $css)) . '"')
        . '>' . e($text) . '</span>';
}

/** Does the chip sit before the label instead of after it? */
function menu_badge_first(array $item): bool
{
    return trim((string) ($item['badge'] ?? '')) !== ''
        && (string) ($item['badge_position'] ?? 'after') === 'before';
}

/**
 * An admin badge colour, normalised, or '' when it is not usable.
 *
 * The value ends up inside a style attribute, so it is re-checked at render
 * rather than trusted to have been validated on the way in — an older row, or a
 * hand-edited one, must never be able to close the attribute. Same contract as
 * popup_color() next door; hex and the CSS colour keywords are all the admin
 * form offers.
 */
function menu_badge_color(string $value): string
{
    $value = trim($value);
    if (preg_match('/^#([0-9A-Fa-f]{3}|[0-9A-Fa-f]{6})$/', $value) === 1) {
        return strtoupper($value);
    }
    return preg_match('/^[a-zA-Z]{3,20}$/', $value) === 1 ? strtolower($value) : '';
}

/** A colour's three channels, 0-255, or null when it is not a hex literal. */
function menu_badge_rgb(string $color): ?array
{
    if (preg_match('/^#([0-9A-Fa-f]{3}|[0-9A-Fa-f]{6})$/', $color) !== 1) {
        return null;
    }

    $hex = substr($color, 1);
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }

    return [
        (int) hexdec(substr($hex, 0, 2)),
        (int) hexdec(substr($hex, 2, 2)),
        (int) hexdec(substr($hex, 4, 2)),
    ];
}

/** Relative luminance, per WCAG 2.x. */
function menu_badge_luminance(array $rgb): float
{
    $channels = [];
    foreach ($rgb as $value) {
        $c = $value / 255;
        $channels[] = $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    }
    return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
}

/** Contrast ratio between two luminances. */
function menu_badge_contrast(float $a, float $b): float
{
    return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
}

/**
 * Which of white or near-black to print on a solid chip of this colour.
 *
 * The bar hard-coded #fff, which is 3.48:1 on the store's own primary — the
 * comment above .sik-nav__badge says so, and the stylesheet works around it by
 * using the darker shade. An admin who picks a pale amber gets 1.6:1 with the
 * same hard-coded white. Whichever of the two extremes actually wins, wins.
 */
function menu_badge_on_color(string $color): string
{
    $rgb = menu_badge_rgb($color);
    if ($rgb === null) {
        return '#FFFFFF';   // A colour keyword tells us nothing; keep the old answer.
    }

    $luminance = menu_badge_luminance($rgb);
    $onWhite   = menu_badge_contrast($luminance, 1.0);
    $onInk     = menu_badge_contrast($luminance, menu_badge_luminance([15, 33, 67]));

    return $onWhite >= $onInk ? '#FFFFFF' : '#0F2143';
}

/**
 * The admin's colour, darkened until 11px text in it passes AA on the panel.
 *
 * This is the "-ink variant" rule for a colour nobody can enumerate in advance:
 * the token set ships --sik-primary-ink and friends precisely because the bright
 * shade fails as small text, and a soft or outline chip is small text in the
 * admin's own colour. Multiplied toward black in 8% steps and stopped at the
 * first shade that clears 4.5:1, so #F4511E (3.48:1) lands on its own darker
 * relative rather than on some unrelated brown.
 */
function menu_badge_ink(string $color, bool $onDark = false): string
{
    $rgb = menu_badge_rgb($color);
    if ($rgb === null) {
        return $color;      // A keyword cannot be adjusted; print it as asked.
    }

    // Against a dark band the ink shade is the wrong direction — the bright
    // colour is the one that reads there, which is why app.css re-aliases
    // --sik-primary-ink back to --sik-primary inside its navy sections.
    if ($onDark) {
        return $color;
    }

    for ($step = 0; $step <= 10; $step++) {
        $factor  = 1 - ($step * 0.08);
        $shade   = array_map(static fn (int $c): int => (int) round($c * $factor), $rgb);
        if (menu_badge_contrast(menu_badge_luminance($shade), 1.0) >= 4.5) {
            return sprintf('#%02X%02X%02X', $shade[0], $shade[1], $shade[2]);
        }
    }

    return '#0F2143';
}

/**
 * The same colour as a background wash behind that ink.
 *
 * rgb() with an alpha would let the panel's own surface show through, which is
 * what --sik-primary-soft does; kept as a flat mix instead so the chip reads
 * identically over the panel, over a hover row and over the promo tile.
 */
function menu_badge_tint(string $color, bool $onDark = false): string
{
    $rgb = menu_badge_rgb($color);
    if ($rgb === null) {
        return 'transparent';
    }

    $base = $onDark ? [15, 33, 67] : [255, 255, 255];
    $mix  = [];
    foreach ($rgb as $index => $channel) {
        $mix[] = (int) round($base[$index] + ($channel - $base[$index]) * 0.14);
    }

    return sprintf('#%02X%02X%02X', $mix[0], $mix[1], $mix[2]);
}

/**
 * The promo tile for a mega panel, or null when there is nothing to draw.
 *
 * `mega_image` / `mega_image_url` have been stored by the Menu Builder and read
 * by nothing since the panel became a single column. They render again — but
 * only behind `mega_promo`, so an operator who uploaded an image years ago does
 * not find it suddenly appearing in the navigation.
 *
 * @return array{image:string, href:string, label:string}|null
 */
function mega_panel_promo(array $item): ?array
{
    if ((int) ($item['mega_promo'] ?? 0) !== 1) {
        return null;
    }

    $image = trim((string) ($item['mega_image'] ?? ''));
    if ($image === '' || !is_file(ROOT_PATH . '/' . ltrim($image, '/'))) {
        return null;
    }

    $link = trim((string) ($item['mega_image_url'] ?? ''));

    return [
        'image' => $image,
        // No promo link falls back to the panel's own destination rather than
        // rendering a tile that does nothing when it is clicked.
        'href'  => $link !== '' ? nav_link_url($link) : (string) ($item['href'] ?? '#'),
        'label' => (string) ($item['label'] ?? ''),
    ];
}

/** Recursively nest children under their parents. */
function attach_menu_children(array $byParent, int $parentId, int $depth = 0): array
{
    if ($depth > 4 || !isset($byParent[$parentId])) {
        return [];
    }

    $items = $byParent[$parentId];
    foreach ($items as &$item) {
        $item['children'] = attach_menu_children($byParent, (int) $item['id'], $depth + 1);
    }
    unset($item);

    return $items;
}

/**
 * Resolve a link target typed into an admin builder.
 *
 * Absolute links pass through untouched — http(s), protocol-relative, and the
 * mailto:/tel: schemes an operator reasonably reaches for. Anything else is a
 * path relative to the store and gets the site URL prefixed.
 *
 * This lived inline in menu_item_url() and the Footer Builder had its own
 * cruder copy (`strpos($url, 'http') === 0`), so the same value typed into the
 * two screens resolved differently: the Menu Builder linked
 * mailto:support@… correctly while the footer turned it into the dead
 * https://store/mailto:support@… . One resolver, one answer.
 */
function nav_link_url(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '' || $raw === '#') {
        return '#';
    }
    if (preg_match('#^(https?:)?//#i', $raw) === 1
        || stripos($raw, 'mailto:') === 0
        || stripos($raw, 'tel:') === 0) {
        return $raw;
    }

    // Canonicalise the legacy CMS form. The Footer Builder's seeded links are
    // stored as `page.php?slug=privacy-policy`, which url() would hand back
    // verbatim — so the footer columns linked the query-string URL while the
    // policy bar right beneath them linked page_url()'s `/page/privacy-policy`.
    // Two URLs for one page, on the same screen: a split for crawlers and a
    // mismatch for anything comparing the current path to decide what is
    // active. Normalising here fixes the Menu Builder's copies at the same
    // time, since both screens resolve through this one function.
    if (preg_match('#^page\.php\?slug=([A-Za-z0-9_-]+)$#', $raw, $m) === 1) {
        return page_url($m[1]);
    }

    return url($raw);
}

/**
 * Resolve a menu item's link based on its type.
 *
 * $reference is the already-fetched row from menu_reference_rows(); pass it and
 * the whole menu costs one query per link type instead of one per *item*. Left
 * optional so a single item can still be resolved on its own.
 */
function menu_item_url(array $item, ?array $reference = null): string
{
    $refSlug = static function (string $table) use ($item, $reference): ?string {
        if ($reference !== null && isset($reference['slug'])) {
            return (string) $reference['slug'];
        }
        if (empty($item['reference_id'])) {
            return null;
        }
        $slug = Database::fetchColumn("SELECT `slug` FROM `{$table}` WHERE `id` = :id", ['id' => (int) $item['reference_id']]);
        return $slug === null ? null : (string) $slug;
    };

    switch ($item['link_type']) {
        case 'category':
            $slug = $refSlug('categories');
            return $slug ? category_url($slug) : url('shop.php');

        case 'brand':
            $slug = $refSlug('brands');
            return $slug ? brand_url($slug) : url('brands.php');

        case 'page':
            $slug = $refSlug('pages');
            return $slug ? page_url($slug) : url();

        case 'route':
        case 'custom':
        default:
            return nav_link_url((string) ($item['url'] ?? ''));
    }
}

/*
 * menu_mega_products() lived here. The mega panel is a single vertical list of
 * categories now — it has no product rail to feed, and navbar.php was the only
 * caller. The `menu_items.mega_products` column and its Admin > Menu Builder
 * picker still store ids; nothing on the storefront reads them.
 */

/** Is this menu item (or one of its children) the page we are on? */
function menu_item_is_active(array $item): bool
{
    $current = current_url();
    $currentPath = parse_url($current, PHP_URL_PATH) ?: '';
    $itemPath = parse_url($item['href'] ?? '', PHP_URL_PATH) ?: '';

    /**
     * The site root and /index.php are the same page, but they never compared
     * equal, so the Home item was the one link that could never be marked
     * active — on the store's most visited page. Collapsing the index filename
     * lets the "you are here" state work there too.
     */
    $normalise = static function (string $path): string {
        $path = rtrim($path, '/');
        if (substr($path, -10) === '/index.php') {
            $path = substr($path, 0, -10);
        }
        return $path === '' ? '/' : $path;
    };

    $currentPath = $normalise($currentPath);
    $itemPath    = $normalise($itemPath);

    if ($itemPath !== '' && $currentPath === $itemPath) {
        return true;
    }

    foreach ($item['children'] ?? [] as $child) {
        if (menu_item_is_active($child)) {
            return true;
        }
    }
    return false;
}

/** Footer columns with their links, from the Footer Builder. */
function footer_columns(): array
{
    return cache_remember('footer.columns', 600, static function () {
        $columns = Database::fetchAll(
            "SELECT * FROM `footer_columns` WHERE `status` = 'active' ORDER BY `sort_order`, `id`"
        );
        foreach ($columns as &$column) {
            $column['links'] = Database::fetchAll(
                "SELECT * FROM `footer_links` WHERE `column_id` = :id AND `status` = 'active' ORDER BY `sort_order`, `id`",
                ['id' => (int) $column['id']]
            );
        }
        unset($column);
        return $columns;
    });
}

/** Active announcement bar messages. */
function active_announcements(): array
{
    return cache_remember('announcements.active', 300, static function () {
        return Database::fetchAll(
            "SELECT * FROM `announcements`
             WHERE `status` = 'active'
               AND (`start_date` IS NULL OR `start_date` <= NOW())
               AND (`end_date` IS NULL OR `end_date` >= NOW())
             ORDER BY `sort_order`, `id`"
        );
    });
}

/** Trust features for a placement ('hero' or 'strip'). */
function trust_features(string $placement = 'strip'): array
{
    return cache_remember('trust.' . $placement, 600, static function () use ($placement) {
        return Database::fetchAll(
            "SELECT * FROM `trust_features`
             WHERE `status` = 'active' AND `placement` = :p
             ORDER BY `sort_order`, `id`",
            ['p' => $placement]
        );
    });
}

/** Popups / pop-ins eligible for the current page. */
function active_popups(string $routeKey): array
{
    if (!setting_bool('popups_enabled', true) && !setting_bool('popins_enabled', true)) {
        return [];
    }

    $rows = Database::fetchAll(
        "SELECT * FROM `popups`
         WHERE `status` = 'active'
           AND (`start_date` IS NULL OR `start_date` <= NOW())
           AND (`end_date` IS NULL OR `end_date` >= NOW())
         ORDER BY `sort_order`, `id`"
    );

    return array_values(array_filter($rows, static function ($popup) use ($routeKey) {
        if ($popup['display_mode'] === 'popup' && !setting_bool('popups_enabled', true)) {
            return false;
        }
        if ($popup['display_mode'] === 'popin' && !setting_bool('popins_enabled', true)) {
            return false;
        }
        if (!visibility_allows($popup)) {
            return false;
        }

        $pages = trim((string) $popup['display_pages']);
        if ($pages === '' || $pages === 'all') {
            return true;
        }
        $allowed = array_map('trim', explode(',', $pages));
        return in_array($routeKey, $allowed, true);
    }));
}

/** The route key for the current script, used for popup targeting. */
function current_route_key(): string
{
    $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'index.php'), '.php');
    return $script === '' ? 'home' : ($script === 'index' ? 'home' : $script);
}

/**
 * Where a modal popup sits in the viewport, as an inline style fragment.
 *
 * Admin > Popups > Appearance > Position offers eight anchors and the form's
 * live preview moves the card to every one of them, but the storefront only
 * ever read `position` for pop-ins — a popup pinned to "Bottom right" in the
 * admin still rendered dead centre, so the preview was showing an arrangement
 * the shop could not produce.
 *
 * .sik-modal is already the flex container that centres the panel, so the
 * anchor is just its two alignment axes. 'center' returns '' so the stylesheet
 * keeps ownership of the default and the other modals on the page (quick view,
 * search) are untouched.
 */
function popup_anchor_style(string $position): string
{
    $map = [
        'top'          => ['flex-start', 'center'],
        'bottom'       => ['flex-end',   'center'],
        'left'         => ['center',     'flex-start'],
        'right'        => ['center',     'flex-end'],
        'top-right'    => ['flex-start', 'flex-end'],
        'bottom-left'  => ['flex-end',   'flex-start'],
        'bottom-right' => ['flex-end',   'flex-end'],
    ];

    if (!isset($map[$position])) {
        return '';
    }

    return 'align-items:' . $map[$position][0] . ';justify-content:' . $map[$position][1] . ';';
}

/**
 * A popup colour from Admin > Popups > Appearance, or '' when it is unusable.
 *
 * The admin validates both colour fields as #RRGGBB, but the value still ends
 * up inside a style attribute, so it is re-checked at render rather than
 * trusted: an older row, or a hand-edited one, must never be able to close
 * the attribute.
 */
function popup_color(array $popup, string $field): string
{
    $value = (string) ($popup[$field] ?? '');
    return preg_match('/^#[0-9A-Fa-f]{6}$/', $value) === 1 ? strtoupper($value) : '';
}

/** Panel background for a popup, as an inline style fragment (may be ''). */
function popup_theme_style(array $popup): string
{
    $bg = popup_color($popup, 'bg_color');
    return $bg === '' ? '' : 'background:' . $bg . ';';
}

/**
 * Text colour for the copy that sits directly on that background.
 *
 * Deliberately applied element by element instead of as a --sik-text override
 * on the whole panel. The panel also contains a white coupon card and the
 * newsletter input, and .sik-news__input paints its own text with
 * var(--sik-text) — re-pointing the token panel-wide turns a white-on-navy
 * popup into white-on-white typing. $muted softens the subtitle so the
 * title/subtitle hierarchy survives without touching --sik-muted either.
 */
function popup_ink_style(array $popup, bool $muted = false): string
{
    $fg = popup_color($popup, 'text_color');
    if ($fg === '') {
        return $muted ? 'color:var(--sik-muted);' : '';
    }
    return 'color:' . $fg . ';' . ($muted ? 'opacity:.78;' : '');
}

/**
 * Turn the admin's "Video URL" into something the storefront can actually
 * play. A video popup will not save without this field, so leaving it
 * unrendered meant the one thing that type exists for never appeared.
 *
 * Watch links are rewritten to the provider's player endpoint (YouTube via
 * nocookie, which stores nothing until playback starts) and a direct media
 * file is handed to the browser's own player. Any other host returns an empty
 * string: an operator-supplied URL is not a licence to frame an arbitrary
 * origin inside the store.
 */
function popup_video_embed(string $url): string
{
    $url = trim($url);
    if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
        return '';
    }

    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    if ($scheme !== 'http' && $scheme !== 'https') {
        return '';
    }

    $host = preg_replace('/^www\./', '', strtolower((string) parse_url($url, PHP_URL_HOST)));
    $path = (string) parse_url($url, PHP_URL_PATH);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    $frame = '';
    if ($host === 'youtube.com' || $host === 'm.youtube.com' || $host === 'youtube-nocookie.com') {
        $id = (string) ($query['v'] ?? '');
        if ($id === '' && preg_match('#^/(?:embed|shorts|live|v)/([A-Za-z0-9_-]{6,20})#', $path, $m) === 1) {
            $id = $m[1];
        }
        if (preg_match('/^[A-Za-z0-9_-]{6,20}$/', $id) === 1) {
            $frame = 'https://www.youtube-nocookie.com/embed/' . $id;
        }
    } elseif ($host === 'youtu.be') {
        if (preg_match('#^/([A-Za-z0-9_-]{6,20})#', $path, $m) === 1) {
            $frame = 'https://www.youtube-nocookie.com/embed/' . $m[1];
        }
    } elseif ($host === 'vimeo.com' || $host === 'player.vimeo.com') {
        if (preg_match('#/(\d{6,12})#', $path, $m) === 1) {
            $frame = 'https://player.vimeo.com/video/' . $m[1];
        }
    } elseif (preg_match('/\.(mp4|webm|ogv|ogg)$/i', $path) === 1) {
        return '<video src="' . e_attr($url) . '" controls playsinline preload="metadata"'
             . ' style="display:block;width:100%;aspect-ratio:16/9;background:#000;border:0"></video>';
    }

    if ($frame === '') {
        return '';
    }

    return '<iframe src="' . e_attr($frame) . '" title="Video" loading="lazy" allowfullscreen'
         . ' referrerpolicy="strict-origin-when-cross-origin"'
         . ' allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"'
         . ' style="display:block;width:100%;aspect-ratio:16/9;border:0"></iframe>';
}

/**
 * An icon for a drawer row that has none of its own.
 *
 * The mobile drawer is a column of icon + label, so a row without an icon
 * leaves a gap and breaks the alignment of every label under it. The Menu
 * Builder's own choice always wins; this is only reached when nothing was set.
 *
 * Matched on the label first because that is what the operator actually wrote,
 * then on the link type, which at least separates a category from a page.
 */
function drawer_fallback_icon(array $item): string
{
    $label = strtolower(trim((string) ($item['label'] ?? '')));

    $byWord = [
        'home'      => 'home',
        'categor'   => 'grid',
        'all produc'=> 'grid',
        'shop'      => 'store',
        'new'       => 'sparkle',
        'best'      => 'star',
        'trend'     => 'trending',
        'offer'     => 'percent',
        'deal'      => 'percent',
        'sale'      => 'tag',
        'festiv'    => 'sparkle',
        'gift'      => 'gift',
        'combo'     => 'package',
        'decor'     => 'sparkle',
        'lamp'      => 'zap',
        'light'     => 'zap',
        'diya'      => 'fire',
        'candle'    => 'fire',
        'projector' => 'tv',
        'string'    => 'zap',
        'curtain'   => 'zap',
        'brand'     => 'award',
        'blog'      => 'file-text',
        'about'     => 'info',
        'contact'   => 'headset',
        'support'   => 'headset',
        'help'      => 'headset',
        'faq'       => 'info',
        'track'     => 'truck',
        'order'     => 'box',
        'wishlist'  => 'heart',
        'account'   => 'user',
        'sign in'   => 'user',
        'compare'   => 'compare',
        'polic'     => 'shield',
        'return'    => 'refresh',
        'warrant'   => 'shield-check',
        'ship'      => 'truck',
        'refund'    => 'wallet',
        'term'      => 'file-text',
        'privacy'   => 'lock',
    ];

    foreach ($byWord as $needle => $glyph) {
        if ($label !== '' && strpos($label, $needle) !== false) {
            return $glyph;
        }
    }

    return match ((string) ($item['link_type'] ?? '')) {
        'category' => 'grid',
        'brand'    => 'award',
        'page'     => 'file-text',
        default    => 'chevron-right',
    };
}
