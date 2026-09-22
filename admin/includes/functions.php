<?php
/**
 * ShopInnKart Admin - Shared helpers.
 *
 * UI building blocks (tables, badges, filters, pagination) plus the small
 * amount of admin-only data logic that several modules need.
 */

declare(strict_types=1);

// ===========================================================================
//  NAVIGATION
// ===========================================================================

/**
 * The sidebar tree. Each entry declares the permission that reveals it, so the
 * menu and the page guards can never disagree.
 */
function admin_menu(): array
{
    return [
        [
            'label' => 'Dashboard', 'icon' => 'chart', 'url' => 'dashboard.php', 'permission' => 'dashboard.view',
        ],
        [
            'label' => 'Catalog', 'icon' => 'package', 'permission' => 'products',
            'children' => [
                ['label' => 'Products',   'url' => 'products/',   'permission' => 'products.view'],
                ['label' => 'Categories', 'url' => 'categories/', 'permission' => 'categories.view'],
                ['label' => 'Brands',     'url' => 'brands/',     'permission' => 'brands.view'],
                ['label' => 'Attributes', 'url' => 'attributes/', 'permission' => 'attributes.view'],
                ['label' => 'Inventory',  'url' => 'products/inventory.php', 'permission' => 'products.edit'],
            ],
        ],
        [
            'label' => 'Orders', 'icon' => 'cart', 'permission' => 'orders',
            'badge' => 'pending_orders',
            'children' => [
                ['label' => 'All Orders', 'url' => 'orders/',                        'permission' => 'orders.view'],
                ['label' => 'Pending',    'url' => 'orders/?status=pending',          'permission' => 'orders.view'],
                ['label' => 'Processing', 'url' => 'orders/?status=processing',       'permission' => 'orders.view'],
                ['label' => 'Shipped',    'url' => 'orders/?status=shipped',          'permission' => 'orders.view'],
                ['label' => 'Delivered',  'url' => 'orders/?status=delivered',        'permission' => 'orders.view'],
                ['label' => 'Cancelled',  'url' => 'orders/?status=cancelled',        'permission' => 'orders.view'],
                ['label' => 'Returns',    'url' => 'orders/returns.php',              'permission' => 'orders.view'],
            ],
        ],
        [
            // Fulfilment has enough screens to be its own group rather than a
            // tail on Orders. Same permission as orders, because it IS orders:
            // a shipping.* key would resolve to nobody until every role was edited.
            'label' => 'Shipping', 'icon' => 'truck', 'permission' => 'orders',
            'children' => [
                // book.php is a shipment's own page, so it lights Shipments -
                // `also` lets one entry own a page outside its path.
                ['label' => 'Shipments',    'url' => 'shipping/shipments.php', 'permission' => 'orders.view',
                 'also' => ['shipping/book.php']],
                ['label' => 'Tracking',     'url' => 'shipping/track.php',     'permission' => 'orders.view'],
                // The directory entry catches index.php and configure.php.
                ['label' => 'Integrations', 'url' => 'shipping/',              'permission' => 'orders.view'],
            ],
        ],
        [
            'label' => 'Customers', 'icon' => 'users', 'url' => 'customers/', 'permission' => 'customers.view',
        ],
        [
            'label' => 'Marketing', 'icon' => 'percent', 'permission' => 'coupons',
            'children' => [
                ['label' => 'Coupons',     'url' => 'coupons/',     'permission' => 'coupons.view'],
                // Combos sit with the other things that change what an order costs.
                // They reuse the coupons permission rather than declaring a new one:
                // an unregistered key would resolve to "nobody" and hide the screen
                // from every role including the owner.
                ['label' => 'Combo Offers', 'url' => 'combos/',      'permission' => 'coupons.view'],
                ['label' => 'Deals',       'url' => 'deals/',       'permission' => 'deals.view'],
                ['label' => 'Flash Sales', 'url' => 'flash-sales/', 'permission' => 'flash_sales.view'],
                ['label' => 'Banners',     'url' => 'banners/',     'permission' => 'banners.view'],
                ['label' => 'Popups',      'url' => 'popups/',      'permission' => 'banners.view'],
                ['label' => 'Newsletter',  'url' => 'newsletter/',  'permission' => 'newsletter.view'],
            ],
        ],
        [
            'label' => 'Content', 'icon' => 'edit', 'permission' => 'homepage',
            'children' => [
                ['label' => 'Homepage Builder', 'url' => 'homepage/',   'permission' => 'homepage.view'],
                ['label' => 'Menu Builder',     'url' => 'menus/',      'permission' => 'homepage.edit'],
                ['label' => 'Footer Builder',   'url' => 'footer/',     'permission' => 'homepage.edit'],
                ['label' => 'Pages',            'url' => 'pages/',      'permission' => 'pages.view'],
                ['label' => 'FAQ',              'url' => 'faq/',        'permission' => 'faq.view'],
                ['label' => 'Blog',             'url' => 'blog/',       'permission' => 'blog.view'],
                ['label' => 'Testimonials',     'url' => 'testimonials/', 'permission' => 'homepage.edit'],
            ],
        ],
        [
            // The hub for everything that decides how the storefront looks.
            // Deliberately short: Customization links out to the Menu, Footer,
            // Popup, Homepage and Theme editors rather than listing them a
            // second time under a different heading.
            'label' => 'Appearance', 'icon' => 'sparkle', 'permission' => 'settings.view',
            'children' => [
                ['label' => 'Customization',    'url' => 'appearance/',           'permission' => 'settings.view'],
                ['label' => 'Header',           'url' => 'appearance/header.php', 'permission' => 'settings.view'],
                ['label' => 'Floating Buttons', 'url' => 'floating/',             'permission' => 'homepage.view'],
            ],
        ],
        [
            'label' => 'Reviews', 'icon' => 'star', 'url' => 'reviews/', 'permission' => 'reviews.view',
            'badge' => 'pending_reviews',
        ],
        [
            'label' => 'Messages', 'icon' => 'mail', 'url' => 'messages/', 'permission' => 'customers.view',
            'badge' => 'new_messages',
        ],
        [
            'label' => 'Reports', 'icon' => 'trending', 'permission' => 'reports.view',
            'children' => [
                ['label' => 'Sales',     'url' => 'reports/sales.php',     'permission' => 'reports.view'],
                ['label' => 'Products',  'url' => 'reports/products.php',  'permission' => 'reports.view'],
                ['label' => 'Orders',    'url' => 'reports/orders.php',    'permission' => 'reports.view'],
                ['label' => 'Customers', 'url' => 'reports/customers.php', 'permission' => 'reports.view'],
                ['label' => 'Marketing', 'url' => 'reports/marketing.php', 'permission' => 'reports.view'],
            ],
        ],
        [
            'label' => 'Settings', 'icon' => 'settings', 'permission' => 'settings',
            'children' => [
                ['label' => 'General',  'url' => 'settings/general.php',  'permission' => 'settings.view'],
                ['label' => 'Store',    'url' => 'settings/store.php',    'permission' => 'settings.view'],
                ['label' => 'Payment',  'url' => 'settings/payment.php',  'permission' => 'settings.view'],
                ['label' => 'Shipping', 'url' => 'settings/shipping.php', 'permission' => 'settings.view'],
                ['label' => 'Tax',      'url' => 'settings/tax.php',      'permission' => 'settings.view'],
                ['label' => 'Email',    'url' => 'settings/email.php',    'permission' => 'settings.view'],
                ['label' => 'SEO',      'url' => 'settings/seo.php',      'permission' => 'settings.view'],
                ['label' => 'Theme',    'url' => 'settings/theme.php',    'permission' => 'settings.view'],
                ['label' => 'Social',   'url' => 'settings/social.php',   'permission' => 'settings.view'],
                ['label' => 'Widgets',  'url' => 'settings/widgets.php',  'permission' => 'settings.view'],
            ],
        ],
        [
            'label' => 'System', 'icon' => 'shield', 'permission' => 'admins',
            'children' => [
                ['label' => 'Admin Users',    'url' => 'admins/',            'permission' => 'admins.view'],
                ['label' => 'Roles',          'url' => 'admins/roles.php',   'permission' => 'admins.view'],
                ['label' => 'Activity Log',   'url' => 'logs/activity.php',  'permission' => 'logs.view'],
                ['label' => 'Error Log',      'url' => 'logs/errors.php',    'permission' => 'logs.view'],
                ['label' => 'Login History',  'url' => 'logs/login-history.php', 'permission' => 'logs.view'],
                ['label' => 'Maintenance',    'url' => 'system/maintenance.php', 'permission' => 'settings.edit'],
                ['label' => 'Backup',         'url' => 'system/backup.php',      'permission' => 'settings.edit'],
            ],
        ],
    ];
}

/** Live counters shown as sidebar badges. */
function admin_sidebar_badges(): array
{
    return cache_remember('admin.badges', 60, static function () {
        return [
            'pending_orders'  => (int) Database::fetchColumn("SELECT COUNT(*) FROM `orders` WHERE `status` = 'pending'"),
            'pending_reviews' => (int) Database::fetchColumn("SELECT COUNT(*) FROM `reviews` WHERE `status` = 'pending'"),
            'new_messages'    => (int) Database::fetchColumn("SELECT COUNT(*) FROM `contact_messages` WHERE `status` = 'new'"),
        ];
    });
}

/** Is this sidebar entry (or one of its children) the current page? */
/**
 * How well one menu url describes the page being viewed.
 *
 * Returns 0 for no match. Higher is more specific, so the caller can pick a
 * single winner: `orders/?status=shipped` must beat `orders/` on the shipped
 * list, while `orders/` still wins on `orders/view.php?id=1`, which no status
 * child describes at all.
 */
function admin_menu_match_score(string $url, string $current, string $adminBase): int
{
    $parts = array_pad(explode('?', $url, 2), 2, '');
    $path  = $adminBase . '/' . ltrim($parts[0], '/');

    $currentPath = (string) (parse_url($current, PHP_URL_PATH) ?: $current);

    // A directory entry covers everything beneath it; a file entry must be
    // that file. Substring matching would let `admins/` claim `admins/roles`.
    if (substr($path, -1) === '/') {
        if (strncmp($currentPath, $path, strlen($path)) !== 0) {
            return 0;
        }
    } elseif ($currentPath !== $path) {
        return 0;
    }

    $score = strlen($path);

    // An entry that names query parameters only describes a page carrying all
    // of them. That is what separates the five order status filters, and it is
    // why they must outrank the plain `orders/` entry when they do match.
    if ($parts[1] !== '') {
        parse_str($parts[1], $want);
        parse_str((string) parse_url($current, PHP_URL_QUERY), $have);
        foreach ($want as $key => $value) {
            if (!isset($have[$key]) || (string) $have[$key] !== (string) $value) {
                return 0;
            }
        }
        $score += 1000 + strlen($parts[1]);
    }

    return $score;
}

/**
 * The single menu url that best describes this request, or '' for a page the
 * menu does not cover at all.
 *
 * Memoised: the sidebar asks about every entry and every child on each render,
 * and the answer cannot change inside one request.
 */
function admin_menu_current_url(): string
{
    static $winner = null;
    if ($winner !== null) {
        return $winner;
    }

    $current   = str_replace('\\', '/', (string) ($_SERVER['REQUEST_URI'] ?? ''));
    $adminBase = rtrim(parse_url(ADMIN_URL, PHP_URL_PATH) ?: '/admin', '/');

    $best      = 0;
    $bestUrl   = '';

    foreach (admin_menu() as $item) {
        $candidates = [];
        if (!empty($item['url'])) {
            $candidates[] = (string) $item['url'];
        }
        foreach ($item['children'] ?? [] as $child) {
            if (!empty($child['url'])) {
                $candidates[] = (string) $child['url'];
            }
        }

        // Each candidate is [the url to match, the entry url that wins]. An
        // entry's `also` pages match on their own path but credit the entry.
        $pairs = [];
        foreach ($candidates as $url) {
            $pairs[] = [$url, $url];
        }
        foreach (array_merge([$item], $item['children'] ?? []) as $entry) {
            foreach ((array) ($entry['also'] ?? []) as $alias) {
                if (!empty($entry['url'])) {
                    $pairs[] = [(string) $alias, (string) $entry['url']];
                }
            }
        }

        foreach ($pairs as [$match, $owner]) {
            $score = admin_menu_match_score($match, $current, $adminBase);
            if ($score > $best) {
                $best    = $score;
                $bestUrl = $owner;
            }
        }
    }

    return $winner = $bestUrl;
}

/**
 * Is this entry the current page, or the parent of it?
 *
 * A parent is "active" so its group opens and its row is tinted; only the leaf
 * that actually won carries aria-current, which sidebar.php decides by calling
 * this on the child itself.
 */
function admin_menu_active(array $item): bool
{
    $winner = admin_menu_current_url();
    if ($winner === '') {
        return false;
    }

    if (!empty($item['url']) && (string) $item['url'] === $winner) {
        return true;
    }
    foreach ($item['children'] ?? [] as $child) {
        if (!empty($child['url']) && (string) $child['url'] === $winner) {
            return true;
        }
    }
    return false;
}

// ===========================================================================
//  UI BUILDING BLOCKS
// ===========================================================================

/** Coloured pill for an order status. */
function admin_status_badge(string $status): string
{
    $label = ORDER_STATUSES[$status] ?? ucfirst(str_replace('_', ' ', $status));
    $tone = ORDER_STATUS_COLORS[$status] ?? 'gray';
    return '<span class="sik-status sik-status--' . e_attr($tone) . '">' . e($label) . '</span>';
}

/** Generic active/inactive pill. */
function admin_state_badge(string $state): string
{
    $map = [
        'active'       => ['green', 'Active'],
        'inactive'     => ['gray', 'Inactive'],
        'draft'        => ['amber', 'Draft'],
        'blocked'      => ['red', 'Blocked'],
        'pending'      => ['amber', 'Pending'],
        'approved'     => ['green', 'Approved'],
        'rejected'     => ['red', 'Rejected'],
        'published'    => ['green', 'Published'],
        'paid'         => ['green', 'Paid'],
        'failed'       => ['red', 'Failed'],
        'refunded'     => ['gray', 'Refunded'],
        'unsubscribed' => ['gray', 'Unsubscribed'],
        'new'          => ['blue', 'New'],
        'read'         => ['gray', 'Read'],
        'replied'      => ['green', 'Replied'],
        'closed'       => ['gray', 'Closed'],
    ];
    [$tone, $label] = $map[$state] ?? ['gray', ucfirst($state)];
    return '<span class="sik-status sik-status--' . e_attr($tone) . '">' . e($label) . '</span>';
}

/** Stock pill with the right tone for the level. */
function admin_stock_badge(int $stock, int $threshold = 5): string
{
    $state = stock_status($stock, $threshold);
    $tone = [STOCK_IN => 'green', STOCK_LOW => 'amber', STOCK_OUT => 'red'][$state];
    $label = $state === STOCK_OUT ? 'Out of stock' : $stock . ' in stock';
    return '<span class="sik-status sik-status--' . $tone . '">' . e($label) . '</span>';
}

/**
 * Render admin pagination.
 * Keeps every other query parameter so filters survive a page change.
 */
function admin_pagination(array $pagination, string $baseUrl = ''): string
{
    if ($pagination['last'] <= 1) {
        return '';
    }

    $link = static function (int $page) use ($baseUrl): string {
        $query = $_GET;
        $query['page'] = $page;
        $base = $baseUrl !== '' ? $baseUrl : strtok((string) $_SERVER['REQUEST_URI'], '?');
        return e($base . '?' . http_build_query($query));
    };

    $html = '<nav class="sik-pager" aria-label="Pagination">';
    $html .= $pagination['current'] > 1
        ? '<a class="sik-pager__link" href="' . $link($pagination['current'] - 1) . '" rel="prev">Prev</a>'
        : '<span class="sik-pager__link is-disabled">Prev</span>';

    foreach ($pagination['pages'] as $page) {
        if ($page === '…') {
            $html .= '<span class="sik-pager__gap">…</span>';
            continue;
        }
        $html .= $page === $pagination['current']
            ? '<span class="sik-pager__link is-current" aria-current="page">' . (int) $page . '</span>'
            : '<a class="sik-pager__link" href="' . $link((int) $page) . '">' . (int) $page . '</a>';
    }

    $html .= $pagination['current'] < $pagination['last']
        ? '<a class="sik-pager__link" href="' . $link($pagination['current'] + 1) . '" rel="next">Next</a>'
        : '<span class="sik-pager__link is-disabled">Next</span>';

    return $html . '</nav>';
}

/** Sortable column header that toggles asc/desc. */
function admin_sort_header(string $label, string $column, string $currentSort, string $currentDir): string
{
    $isActive = $currentSort === $column;
    $nextDir = ($isActive && $currentDir === 'asc') ? 'desc' : 'asc';

    $query = $_GET;
    $query['sort'] = $column;
    $query['dir'] = $nextDir;
    $url = strtok((string) $_SERVER['REQUEST_URI'], '?') . '?' . http_build_query($query);

    $arrow = $isActive
        ? ($currentDir === 'asc' ? icon('chevron-up', 'w-3 h-3') : icon('chevron-down', 'w-3 h-3'))
        : '<span style="opacity:.3">' . icon('sort', 'w-3 h-3') . '</span>';

    return '<a class="ad-sort' . ($isActive ? ' is-active' : '') . '" href="' . e($url) . '">'
        . e($label) . $arrow . '</a>';
}

/**
 * Whitelist a sort column so ORDER BY can never take user input directly.
 */
function admin_safe_sort(string $requested, array $allowed, string $default): string
{
    return in_array($requested, $allowed, true) ? $requested : $default;
}

function admin_safe_dir(string $requested): string
{
    return strtolower($requested) === 'desc' ? 'DESC' : 'ASC';
}

/** A single stat tile for the dashboard and report screens. */
function admin_stat_card(string $label, string $value, string $icon, string $tone = 'primary', ?string $sub = null, ?string $url = null): string
{
    $inner = '<span class="ad-stat__icon ad-stat__icon--' . e_attr($tone) . '">' . icon($icon, 'w-5 h-5') . '</span>'
        . '<span class="ad-stat__body">'
        . '<span class="ad-stat__value">' . e($value) . '</span>'
        . '<span class="ad-stat__label">' . e($label) . '</span>'
        . ($sub !== null ? '<span class="ad-stat__sub">' . e($sub) . '</span>' : '')
        . '</span>';

    return $url !== null
        ? '<a class="ad-stat" href="' . e($url) . '">' . $inner . '</a>'
        : '<div class="ad-stat">' . $inner . '</div>';
}

/** Empty-state block for an admin table. */
function admin_empty(string $title, string $text, ?string $actionLabel = null, ?string $actionUrl = null, string $iconName = 'package'): string
{
    $html = '<div class="ad-empty">'
        . '<span class="ad-empty__icon">' . icon($iconName, 'w-8 h-8') . '</span>'
        . '<h3 class="ad-empty__title">' . e($title) . '</h3>'
        . '<p class="ad-empty__text">' . e($text) . '</p>';

    if ($actionLabel !== null && $actionUrl !== null) {
        $html .= '<a class="ad-btn ad-btn--primary" href="' . e($actionUrl) . '">' . icon('plus', 'w-4 h-4') . ' ' . e($actionLabel) . '</a>';
    }
    return $html . '</div>';
}

/** Confirm-and-post button for destructive actions (never a bare GET link). */
function admin_delete_form(string $action, int $id, string $confirmText, string $label = '', string $extraClass = ''): string
{
    $buttonLabel = $label !== '' ? e($label) : icon('trash', 'w-4 h-4');
    return '<form method="post" action="' . e($action) . '" class="ad-inline-form"'
        . ' onsubmit="return confirm(' . e_attr(json_encode($confirmText)) . ')">'
        . csrf_field()
        . '<input type="hidden" name="id" value="' . $id . '">'
        . '<button type="submit" class="ad-btn ad-btn--icon ad-btn--danger-ghost ' . e_attr($extraClass) . '"'
        . ' title="Delete" aria-label="Delete">' . $buttonLabel . '</button>'
        . '</form>';
}

/** Read a filter value from the query string with a whitelist. */
function admin_filter(string $key, array $allowed = [], string $default = ''): string
{
    $value = trim((string) ($_GET[$key] ?? ''));
    if ($value === '') {
        return $default;
    }
    if ($allowed !== [] && !in_array($value, $allowed, true)) {
        return $default;
    }
    return $value;
}

/** Resolve a date-range preset into [from, to] Y-m-d strings. */
function admin_date_range(string $preset = 'last_30', ?string $from = null, ?string $to = null): array
{
    $today = date('Y-m-d');

    switch ($preset) {
        case 'today':      return [$today, $today];
        case 'yesterday':  $d = date('Y-m-d', strtotime('-1 day')); return [$d, $d];
        case 'last_7':     return [date('Y-m-d', strtotime('-6 days')), $today];
        case 'this_month': return [date('Y-m-01'), $today];
        case 'last_month': return [date('Y-m-01', strtotime('first day of last month')),
                                   date('Y-m-t', strtotime('last day of last month'))];
        case 'this_year':  return [date('Y-01-01'), $today];
        case 'custom':
            $start = $from && strtotime($from) ? date('Y-m-d', strtotime($from)) : date('Y-m-d', strtotime('-29 days'));
            $end   = $to && strtotime($to) ? date('Y-m-d', strtotime($to)) : $today;
            // Swap if the user entered them backwards.
            return $start <= $end ? [$start, $end] : [$end, $start];
        case 'last_30':
        default:           return [date('Y-m-d', strtotime('-29 days')), $today];
    }
}

/**
 * Pure-CSS bar chart. Avoids pulling in a charting library for what is
 * really just a list of numbers.
 *
 * @param array $data [['label' => 'Mon', 'value' => 12345], ...]
 */
function admin_bar_chart(array $data, string $valuePrefix = '', int $height = 180): string
{
    if ($data === []) {
        return '<p class="ad-muted" style="padding:24px;text-align:center">No data for this period.</p>';
    }

    $max = max(array_map(static fn ($d) => (float) $d['value'], $data)) ?: 1;

    $html = '<div class="ad-chart" style="height:' . $height . 'px">';
    foreach ($data as $point) {
        $value = (float) $point['value'];
        $pct = max(1.5, ($value / $max) * 100);
        $display = $valuePrefix === '₹' ? money($value) : $valuePrefix . number_format($value);

        $html .= '<div class="ad-chart__col" title="' . e_attr($point['label'] . ': ' . $display) . '">'
            . '<span class="ad-chart__value">' . e($display) . '</span>'
            . '<span class="ad-chart__bar" style="height:' . round($pct, 2) . '%"></span>'
            . '<span class="ad-chart__label">' . e($point['label']) . '</span>'
            . '</div>';
    }
    return $html . '</div>';
}

/** Horizontal progress row used by "top products" style lists. */
function admin_progress_row(string $label, float $value, float $max, string $valueText, ?string $url = null): string
{
    $pct = $max > 0 ? max(2, min(100, ($value / $max) * 100)) : 0;
    $name = $url !== null
        ? '<a href="' . e($url) . '">' . e($label) . '</a>'
        : e($label);

    return '<div class="ad-progressrow">'
        . '<div class="ad-progressrow__head"><span>' . $name . '</span><strong>' . e($valueText) . '</strong></div>'
        . '<div class="sik-progress"><span style="width:' . round($pct, 2) . '%"></span></div>'
        . '</div>';
}

// ===========================================================================
//  FORM HELPERS
// ===========================================================================

/** <option> list with the current value selected. */
function admin_options(array $options, $selected = null, ?string $placeholder = null): string
{
    $html = '';
    if ($placeholder !== null) {
        $html .= '<option value="">' . e($placeholder) . '</option>';
    }
    foreach ($options as $value => $label) {
        $isSelected = (string) $value === (string) $selected;
        $html .= '<option value="' . e_attr((string) $value) . '"' . ($isSelected ? ' selected' : '') . '>'
            . e((string) $label) . '</option>';
    }
    return $html;
}

/** Indented category <option> list for parent pickers. */
function admin_category_options($selected = null, ?int $excludeId = null, ?string $placeholder = '— None —'): string
{
    $categories = Database::fetchAll('SELECT `id`, `name`, `parent_id` FROM `categories` ORDER BY `sort_order`, `name`');

    $byParent = [];
    foreach ($categories as $category) {
        $byParent[(int) ($category['parent_id'] ?? 0)][] = $category;
    }

    $html = $placeholder !== null ? '<option value="">' . e($placeholder) . '</option>' : '';

    $walk = static function (int $parentId, int $depth) use (&$walk, $byParent, $selected, $excludeId): string {
        $out = '';
        foreach ($byParent[$parentId] ?? [] as $category) {
            $id = (int) $category['id'];
            // A category can never be its own ancestor.
            if ($excludeId !== null && $id === $excludeId) {
                continue;
            }
            $out .= '<option value="' . $id . '"' . ((string) $selected === (string) $id ? ' selected' : '') . '>'
                . str_repeat('&nbsp;&nbsp;&nbsp;', $depth) . ($depth > 0 ? '└ ' : '') . e($category['name'])
                . '</option>';
            $out .= $walk($id, $depth + 1);
        }
        return $out;
    };

    return $html . $walk(0, 0);
}

/** Simple id => name pairs for a lookup table. */
function admin_lookup(string $table, string $labelColumn = 'name', string $where = "`status` = 'active'"): array
{
    $allowed = ['brands', 'categories', 'attributes', 'admin_roles', 'blog_categories', 'shipping_methods', 'tags', 'vendors'];
    if (!in_array($table, $allowed, true)) {
        throw new InvalidArgumentException('admin_lookup: unsupported table ' . $table);
    }
    return Database::fetchPairs(
        sprintf('SELECT `id`, `%s` FROM `%s` WHERE %s ORDER BY `%s`', $labelColumn, $table, $where, $labelColumn)
    );
}

// ===========================================================================
//  MISC
// ===========================================================================

/** Percentage change between two periods, for the dashboard deltas. */
function admin_delta(float $current, float $previous): array
{
    if ($previous <= 0) {
        return ['percent' => $current > 0 ? 100.0 : 0.0, 'direction' => $current > 0 ? 'up' : 'flat'];
    }
    $change = (($current - $previous) / $previous) * 100;
    return [
        'percent'   => round(abs($change), 1),
        'direction' => $change > 0.5 ? 'up' : ($change < -0.5 ? 'down' : 'flat'),
    ];
}

/** Delta badge markup. */
function admin_delta_badge(array $delta): string
{
    if ($delta['direction'] === 'flat') {
        return '<span class="ad-delta ad-delta--flat">no change</span>';
    }
    $isUp = $delta['direction'] === 'up';
    return '<span class="ad-delta ad-delta--' . ($isUp ? 'up' : 'down') . '">'
        . icon($isUp ? 'arrow-up' : 'chevron-down', 'w-3 h-3')
        . $delta['percent'] . '%</span>';
}

/**
 * Save an uploaded image for an admin form, deleting the previous file.
 * Returns the new path, or the old one when nothing was uploaded.
 */
function admin_handle_image(string $field, string $folder, ?string $currentPath = null): ?string
{
    if (empty($_FILES[$field]['name'])) {
        return $currentPath;
    }

    $result = upload_image($_FILES[$field], $folder);
    if (!$result['ok']) {
        flash('error', $result['error'] ?? 'Image upload failed.');
        return $currentPath;
    }

    if ($currentPath !== null) {
        delete_upload($currentPath);
    }
    return $result['path'];
}

/** Invalidate storefront caches. Call after every admin write. */
function admin_after_write(): void
{
    cache_bust();
}

/**
 * A redirect target supplied by the browser, or a fallback.
 *
 * Pages like the homepage designer post to another page's save handler and
 * need to get their own URL back. Taking that URL from the request means the
 * request can name where the browser goes next, which is an open redirect
 * unless the target is checked - so anything that is not a path inside this
 * admin is discarded in favour of the caller's own default.
 *
 * Only a relative path is accepted. An absolute URL is refused even when its
 * host looks right, because "//evil.example/x" and "https://admin.evil/x" both
 * read as plausible and neither is us.
 */
function admin_safe_return(string $candidate, string $fallback): string
{
    $candidate = trim($candidate);
    if ($candidate === '') {
        return $fallback;
    }

    // A backslash is a path separator to some browsers and not to parse_url(),
    // which is the gap a target like "/" + backslash + "evil.example" walks
    // through. chr(92) rather than an escaped literal: this file has been
    // written through a heredoc more than once, and a heredoc eats the escape.
    if (strpos($candidate, chr(92)) !== false || strpos($candidate, chr(0)) !== false) {
        return $fallback;
    }

    // Scheme-relative and absolute URLs both leave this origin.
    if ($candidate[0] !== '/' || strncmp($candidate, '//', 2) === 0) {
        return $fallback;
    }

    // "/admin/../cart.php" satisfies the prefix test below and then resolves
    // outside the admin, so a traversal segment disqualifies the whole target.
    if (strpos($candidate, '..') !== false) {
        return $fallback;
    }

    // It must land inside the admin, not merely inside the site.
    $adminPath = (string) parse_url(ADMIN_URL, PHP_URL_PATH);
    $adminPath = rtrim($adminPath, '/') . '/';
    $path      = (string) parse_url($candidate, PHP_URL_PATH);

    return strncmp($path, $adminPath, strlen($adminPath)) === 0 ? $candidate : $fallback;
}
