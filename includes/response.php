<?php
/**
 * ShopInnKart - REST API response helpers.
 *
 * Every endpoint answers with the same envelope:
 *   { "success": bool, "message": string, "data": {...}, "errors": {...} }
 */

declare(strict_types=1);

/** Emit a JSON response and stop. */
function json_response(array $payload, int $status = 200): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('X-Content-Type-Options: nosniff');
    }

    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** 2xx success envelope. */
function json_success(string $message = 'OK', $data = null, int $status = 200): void
{
    json_response([
        'success' => true,
        'message' => $message,
        'data'    => $data ?? new stdClass(),
    ], $status);
}

/**
 * 4xx/5xx error envelope.
 *
 * $code is a short machine-readable reason ('csrf', 'auth', 'forbidden',
 * 'validation', 'rate_limit') so the client can react without string-matching
 * the message.
 */
function json_error(
    string $message = 'Something went wrong. Please try again.',
    array $errors = [],
    int $status = 400,
    string $code = ''
): void {
    $payload = [
        'success' => false,
        'message' => $message,
        'errors'  => $errors === [] ? new stdClass() : $errors,
    ];
    if ($code !== '') {
        $payload['code'] = $code;
    }

    json_response($payload, $status);
}

/** 422 validation failure. */
function json_validation_error(array $errors, string $message = 'Please correct the highlighted fields.'): void
{
    json_error($message, $errors, 422);
}

/** Restrict an endpoint to specific HTTP verbs. */
function api_require_method($methods): void
{
    $methods = array_map('strtoupper', (array) $methods);
    $current = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

    // Browsers preflight cross-origin JSON; we are same-origin, so just allow OPTIONS through.
    if ($current === 'OPTIONS') {
        if (!headers_sent()) {
            header('Allow: ' . implode(', ', $methods));
            http_response_code(204);
        }
        exit;
    }

    if (!in_array($current, $methods, true)) {
        if (!headers_sent()) {
            header('Allow: ' . implode(', ', $methods));
        }
        json_error('Method not allowed.', [], 405);
    }
}

/**
 * CSRF gate for every state-changing endpoint.
 *
 * Answers 403, not the Laravel-style 419: 419 is not a registered status code
 * and Apache rewrites it to a 500, which hides the real reason from the client.
 */
function api_require_csrf(): void
{
    if (!csrf_verify()) {
        json_error('Your session expired. Please refresh the page and try again.', [], 403, 'csrf');
    }
}

/** Require a signed-in customer; returns the user row. */
function api_require_login(): array
{
    $user = current_user();
    if ($user === null) {
        json_error('Please sign in to continue.', [], 401, 'auth');
    }
    return $user;
}

/** Require a signed-in admin with a permission; returns the admin row. */
function api_require_admin(string $permission = ''): array
{
    $admin = admin_user();
    if ($admin === null) {
        json_error('Admin authentication required.', [], 401, 'auth');
    }
    if ($permission !== '' && !admin_can($permission)) {
        json_error('You do not have permission to perform this action.', [], 403, 'forbidden');
    }
    return $admin;
}

/**
 * Very small fixed-window rate limiter backed by the session.
 * Enough to stop accidental double-submits and casual abuse of write
 * endpoints; a reverse proxy should handle anything larger.
 */
function api_rate_limit(string $bucket, int $maxAttempts = 20, int $windowSeconds = 60): void
{
    $key = '_rate_' . $bucket;
    $now = time();
    $state = $_SESSION[$key] ?? ['count' => 0, 'reset' => $now + $windowSeconds];

    if ($now > $state['reset']) {
        $state = ['count' => 0, 'reset' => $now + $windowSeconds];
    }

    $state['count']++;
    $_SESSION[$key] = $state;

    if ($state['count'] > $maxAttempts) {
        if (!headers_sent()) {
            header('Retry-After: ' . max(1, $state['reset'] - $now));
        }
        json_error('Too many requests. Please slow down and try again shortly.', [], 429);
    }
}

/** Paginated list envelope used by list endpoints. */
function json_paginated(array $items, array $pagination, string $message = 'OK', array $extra = []): void
{
    json_success($message, array_merge([
        'items'      => $items,
        'pagination' => [
            'current_page' => $pagination['current'],
            'last_page'    => $pagination['last'],
            'per_page'     => $pagination['per_page'],
            'total'        => $pagination['total'],
            'from'         => $pagination['from'],
            'to'           => $pagination['to'],
        ],
    ], $extra));
}
