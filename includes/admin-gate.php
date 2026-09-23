<?php
/**
 * ShopInnKart - Hidden admin login address.
 *
 * With `admin_login_slug` set (Admin > Settings > General), the admin area
 * answers "page not found" to anybody who is not signed in and has not come in
 * through the secret address https://<store>/<slug>. Visiting that address
 * leaves a signed cookie and forwards to the normal login screen.
 *
 * What this is and is not: it takes the login form off the map, so the bots
 * that hammer /admin/login.php of every site they find get a 404 like any other
 * missing page, and nobody can tell from outside that an admin panel exists at
 * all. It is NOT a substitute for the password - a leaked slug only brings back
 * the login form, which still has its lockout. Signed-in admins are never
 * affected: their session is the proof.
 *
 * The cookie is an HMAC of the slug under the application key, so it cannot be
 * forged without the key, and changing the slug invalidates every old cookie.
 * Leaving the setting empty switches the whole feature off.
 */

declare(strict_types=1);

const ADMIN_GATE_COOKIE = 'sik_ag';

/** The configured secret path segment, or '' when the feature is off. */
function admin_gate_slug(): string
{
    $slug = strtolower(trim((string) setting('admin_login_slug', '')));
    return preg_match('/^[a-z0-9][a-z0-9-]{7,39}$/', $slug) === 1 ? $slug : '';
}

function admin_gate_enabled(): bool
{
    return admin_gate_slug() !== '';
}

/** The full secret login address, or '' when the feature is off. */
function admin_gate_url(?string $slug = null): string
{
    $slug = $slug ?? admin_gate_slug();
    return $slug === '' ? '' : url($slug);
}

function admin_gate_token(string $slug): string
{
    return hash_hmac('sha256', 'admin-gate|' . $slug, app_key());
}

/** True when the visitor may see the login screen: feature off, or the cookie matches. */
function admin_gate_passed(): bool
{
    $slug = admin_gate_slug();
    if ($slug === '') {
        return true;
    }
    $cookie = $_COOKIE[ADMIN_GATE_COOKIE] ?? '';
    return is_string($cookie) && $cookie !== '' && hash_equals(admin_gate_token($slug), $cookie);
}

/**
 * Remember that this browser came through the secret address. 30 days, so a
 * bookmark is not needed for every sign-in, and scoped to the admin folder so
 * the storefront never carries it.
 */
function admin_gate_grant(?string $slug = null): void
{
    $slug = $slug ?? admin_gate_slug();
    if ($slug === '' || headers_sent()) {
        return;
    }
    $token = admin_gate_token($slug);
    setcookie(ADMIN_GATE_COOKIE, $token, [
        'expires'  => time() + 30 * 86400,
        'path'     => (BASE_PATH !== '' ? BASE_PATH : '') . '/admin/',
        // SITE_URL's scheme is already derived from HTTPS / port / proxy headers.
        'secure'   => strpos(SITE_URL, 'https://') === 0,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $_COOKIE[ADMIN_GATE_COOKIE] = $token;
}

/** Is this request for the secret address itself? */
function admin_gate_request_matches(): bool
{
    $slug = admin_gate_slug();
    if ($slug === '') {
        return false;
    }
    $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    if (BASE_PATH !== '' && strpos($path, BASE_PATH . '/') === 0) {
        $path = substr($path, strlen(BASE_PATH));
    }
    return hash_equals($slug, strtolower(trim(rawurldecode($path), '/')));
}

/**
 * Called by 404.php: the secret address is deliberately not a file, so it
 * arrives there. Grant the cookie and forward to the login screen.
 */
function admin_gate_try_enter(): void
{
    if (!admin_gate_request_matches()) {
        return;
    }
    admin_gate_grant();
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    redirect(admin_url('login.php'));
}

/**
 * Answer exactly as a missing page would, and stop. Rendering the storefront's
 * own 404 (not a bare "Not Found") matters: a page that looks different from
 * every other missing URL is itself the tell that something lives here.
 */
function admin_gate_deny(): void
{
    if (is_ajax()) {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Not found.']);
        exit;
    }
    require ROOT_PATH . '/404.php';
    exit;
}

/**
 * Why a proposed slug cannot be used, or null if it can. It must not shadow a
 * real route, or that page would become unreachable - or the admin gate would.
 */
function admin_gate_slug_error(string $slug): ?string
{
    if (preg_match('/^[a-z0-9][a-z0-9-]{7,39}$/', $slug) !== 1) {
        return 'Use 8-40 characters: lowercase letters, digits and hyphens, starting with a letter or digit.';
    }
    if (preg_match('/admin|login|signin|dashboard|wp-|panel/', $slug) === 1) {
        return 'Pick something that does not look like an admin address - words like "admin" or "login" are the first thing bots try.';
    }
    if (is_file(ROOT_PATH . '/' . $slug . '.php') || is_dir(ROOT_PATH . '/' . $slug)) {
        return 'That address is already a page of the store.';
    }
    if (Database::exists('redirects', '`source_path` IN (:a, :b)', ['a' => '/' . $slug, 'b' => $slug])) {
        return 'A redirect already uses that address.';
    }
    return null;
}

/** A fresh suggestion for the settings screen. */
function admin_gate_suggest(): string
{
    $words = ['studio', 'desk', 'office', 'hub', 'console', 'room', 'base', 'deck'];
    return $words[random_int(0, count($words) - 1)] . '-' . bin2hex(random_bytes(5));
}
