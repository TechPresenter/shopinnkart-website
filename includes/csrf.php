<?php
/**
 * ShopInnKart - CSRF protection.
 *
 * One rotating token per session. Every state-changing request (form POST
 * or fetch()) must present it, either as a form field or the X-CSRF-Token
 * header.
 */

declare(strict_types=1);

/** Current session CSRF token, generated on first use. */
function csrf_token(): string
{
    if (empty($_SESSION[CSRF_TOKEN_NAME])) {
        $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
    }
    return $_SESSION[CSRF_TOKEN_NAME];
}

/** Hidden input for HTML forms. */
function csrf_field(): string
{
    return '<input type="hidden" name="' . CSRF_TOKEN_NAME . '" value="' . e(csrf_token()) . '">';
}

/** Meta tag read by assets/js/app.js so fetch() can send the header. */
function csrf_meta(): string
{
    return '<meta name="csrf-token" content="' . e(csrf_token()) . '">';
}

/**
 * Extract the token supplied by the current request.
 * Checks the POST body, then the JSON body, then the header.
 */
function csrf_supplied_token(): ?string
{
    if (!empty($_POST[CSRF_TOKEN_NAME]) && is_string($_POST[CSRF_TOKEN_NAME])) {
        return $_POST[CSRF_TOKEN_NAME];
    }

    $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    if (!empty($header) && is_string($header)) {
        return $header;
    }

    // JSON request bodies carry the token in the payload.
    $body = request_json_body();
    if (!empty($body[CSRF_TOKEN_NAME]) && is_string($body[CSRF_TOKEN_NAME])) {
        return $body[CSRF_TOKEN_NAME];
    }

    return null;
}

/** Constant-time comparison against the session token. */
function csrf_verify(?string $token = null): bool
{
    $token = $token ?? csrf_supplied_token();
    $expected = $_SESSION[CSRF_TOKEN_NAME] ?? '';

    if ($token === null || $expected === '') {
        return false;
    }
    return hash_equals($expected, $token);
}

/**
 * Enforce CSRF on the current request.
 * Responds 403 as JSON for API/AJAX calls, or flashes and redirects back
 * for regular form posts.
 */
function csrf_require(): void
{
    if (csrf_verify()) {
        return;
    }

    // Recorded so the security monitor can tell one stale tab (one failure)
    // from a script posting to forms it never loaded (fifteen in a quarter of
    // an hour). Guarded because csrf.php is loaded before security-monitor.php.
    if (function_exists('security_csrf_failed')) {
        security_csrf_failed('form');
    }

    if (is_ajax() || strpos((string) ($_SERVER['SCRIPT_NAME'] ?? ''), '/api/') !== false) {
        json_error('Your session expired. Please refresh the page and try again.', [], 403, 'csrf');
    }

    flash('error', 'Your session expired. Please try again.');
    redirect_back();
}

/**
 * Read and memoise a JSON request body.
 * Returns [] for non-JSON or malformed payloads.
 */
function request_json_body(): array
{
    static $parsed = null;
    if ($parsed !== null) {
        return $parsed;
    }

    $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'application/json') === false) {
        return $parsed = [];
    }

    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return $parsed = [];
    }

    $decoded = json_decode($raw, true);
    return $parsed = is_array($decoded) ? $decoded : [];
}

/**
 * Unified request input for API endpoints: JSON body, then POST, then GET.
 */
function request_input(string $key, $default = null)
{
    $json = request_json_body();
    if (array_key_exists($key, $json)) {
        $value = $json[$key];
        return is_string($value) ? trim($value) : $value;
    }
    return input($key, $default);
}

function request_int(string $key, int $default = 0): int
{
    $value = request_input($key, null);
    return is_numeric($value) ? (int) $value : $default;
}

function request_float(string $key, float $default = 0.0): float
{
    $value = request_input($key, null);
    return is_numeric($value) ? (float) $value : $default;
}

function request_bool(string $key): bool
{
    $value = request_input($key, null);
    if (is_bool($value)) {
        return $value;
    }
    return in_array((string) $value, ['1', 'on', 'true', 'yes'], true);
}

function request_array(string $key): array
{
    $json = request_json_body();
    if (isset($json[$key]) && is_array($json[$key])) {
        return array_values(array_filter($json[$key], 'is_scalar'));
    }
    return input_array($key);
}

/** All request input merged, for validation. */
function request_all(): array
{
    return array_merge($_GET, $_POST, request_json_body());
}
