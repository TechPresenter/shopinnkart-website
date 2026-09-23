<?php
/**
 * ShopInnKart - Security event log.
 *
 * One append-only table for everything a security monitor needs to see:
 * failed and locked logins, rate-limit trips, CSRF failures, permission
 * denials, privilege changes, 2FA events, webhook signature failures,
 * installer probes. activity_logs records what admins DID; this records what
 * looked like an attack or changed who can do what.
 *
 * security_event() never throws and never blocks the request it describes:
 * a logging failure must not turn a refused login into a 500.
 */

declare(strict_types=1);

const SECURITY_EVENT_SEVERITIES = ['info', 'low', 'medium', 'high', 'critical'];

/**
 * @param string $type     dotted name, e.g. 'auth.login_failed', 'rbac.role_changed'
 * @param string $severity one of SECURITY_EVENT_SEVERITIES
 * @param array  $context  small, JSON-encodable detail; secrets are masked
 */
function security_event(string $type, string $severity = 'info', array $context = [], ?int $userId = null, ?string $userType = null): void
{
    try {
        if (!in_array($severity, SECURITY_EVENT_SEVERITIES, true)) {
            $severity = 'info';
        }
        $json = json_encode(security_event_mask($context), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        Database::insert('security_events', [
            'type'       => mb_substr($type, 0, 60),
            'severity'   => $severity,
            'user_type'  => $userType !== null ? mb_substr($userType, 0, 20) : null,
            'user_id'    => $userId,
            'ip_address' => mb_substr(client_ip(), 0, 45),
            'user_agent' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? (PHP_SAPI === 'cli' ? 'cli' : '')), 0, 255),
            'path'       => mb_substr((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), 0, 255),
            'context'    => $json === false ? null : mb_substr($json, 0, 4000),
        ]);
    } catch (Throwable $e) {
        // Last resort: the PHP error log, never an exception.
        error_log('security_event(' . $type . ') not recorded: ' . $e->getMessage());
    }
}

/** Replace the values of secret-looking keys, recursively. */
function security_event_mask(array $context): array
{
    foreach ($context as $key => $value) {
        if (is_array($value)) {
            $context[$key] = security_event_mask($value);
        } elseif (is_string($key) && preg_match('/pass(word)?|secret|token|otp|api[_-]?key|authorization|cookie|^code$|backup_code|totp/i', $key) === 1) {
            $context[$key] = '[masked]';
        } elseif (is_string($value) && mb_strlen($value) > 500) {
            $context[$key] = mb_substr($value, 0, 500) . '...';
        }
    }
    return $context;
}
