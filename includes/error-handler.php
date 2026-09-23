<?php
/**
 * ShopInnKart - Centralised error handling.
 *
 * Customers never see a stack trace. Everything is written to
 * /storage/logs and mirrored into the error_logs table when the
 * database is reachable.
 */

declare(strict_types=1);

final class ErrorHandler
{
    /** Guards against a logging failure re-entering the handler. */
    private static bool $logging = false;

    /** Severities that are worth recording but must never take a page down. */
    private const NON_FATAL = [E_WARNING, E_NOTICE, E_DEPRECATED, E_STRICT, E_USER_WARNING, E_USER_NOTICE, E_USER_DEPRECATED];

    public static function register(): void
    {
        // Keep function arguments out of every stack trace this process builds.
        // PHP records the first 15 characters of each argument, so a DB timeout
        // inside attempt_login() used to write the customer's password into
        // storage/logs AND into the error_logs table that any admin with
        // logs.view can read. It is set here rather than in php.ini because a
        // shared host will not change php.ini for us.
        ini_set('zend.exception_ignore_args', '1');
        ini_set('zend.exception_string_param_max_len', '0');

        set_error_handler([self::class, 'handleError']);
        set_exception_handler([self::class, 'handleException']);
        register_shutdown_function([self::class, 'handleShutdown']);
    }

    public static function handleError(int $severity, string $message, string $file = '', int $line = 0): bool
    {
        if (!(error_reporting() & $severity)) {
            return false; // suppressed with @
        }

        // Development: turn everything into an exception so a notice is
        // impossible to ignore while the code is being written.
        //
        // Production: a warning or a deprecation is a thing to fix, not a
        // reason to show a customer a 500 page. Turning "Undefined array key"
        // or the next release's deprecation into a fatal meant one PHP upgrade
        // could take down checkout. Log it and let the request finish; real
        // errors (E_ERROR, TypeError, division by zero) never come through
        // this handler and still stop the request.
        if (!APP_DEBUG && in_array($severity, self::NON_FATAL, true)) {
            self::log('warning', self::severityName($severity) . ': ' . $message, $file, $line, null);
            return true;
        }

        throw new ErrorException($message, 0, $severity, $file, $line);
    }

    /** Readable name for an E_* constant, for the log line. */
    private static function severityName(int $severity): string
    {
        static $names = [
            E_WARNING => 'Warning', E_NOTICE => 'Notice', E_DEPRECATED => 'Deprecated',
            E_STRICT => 'Strict', E_USER_WARNING => 'User warning',
            E_USER_NOTICE => 'User notice', E_USER_DEPRECATED => 'User deprecated',
        ];

        return $names[$severity] ?? ('Error ' . $severity);
    }

    public static function handleException(Throwable $e): void
    {
        self::log('error', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());
        self::render($e);
    }

    public static function handleShutdown(): void
    {
        $error = error_get_last();
        if ($error === null) {
            return;
        }
        $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
        if (!in_array($error['type'], $fatal, true)) {
            return;
        }

        self::log('fatal', $error['message'], $error['file'], $error['line'], null);
        self::render(new ErrorException(
            $error['message'],
            0,
            $error['type'],
            $error['file'],
            $error['line']
        ));
    }

    /**
     * Write to the rotating file log and, when possible, the error_logs table.
     */
    public static function log(
        string $level,
        string $message,
        string $file = '',
        int $line = 0,
        ?string $trace = null
    ): void {
        if (self::$logging) {
            return;
        }
        self::$logging = true;

        $url   = self::sanitizeUrl(self::currentUrl());
        $trace = $trace === null ? null : self::sanitizeTrace($trace);
        $entry = sprintf(
            "[%s] %s: %s in %s:%d%s%s%s",
            date('Y-m-d H:i:s'),
            strtoupper($level),
            $message,
            $file,
            $line,
            PHP_EOL . '  URL: ' . $url,
            $trace ? PHP_EOL . '  Trace: ' . $trace : '',
            PHP_EOL
        );

        $logFile = LOG_PATH . '/app-' . date('Y-m-d') . '.log';
        @file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);

        // Mirror into the database so admins can triage from /admin/logs/errors.php.
        try {
            if (class_exists('Database')) {
                Database::insert('error_logs', [
                    'level'      => substr($level, 0, 20),
                    'message'    => mb_substr($message, 0, 5000),
                    'file'       => mb_substr($file, 0, 255),
                    'line'       => $line,
                    'url'        => mb_substr($url, 0, 500),
                    'trace'      => $trace,
                    'ip_address' => self::clientIp(),
                ]);
            }
        } catch (Throwable $ignored) {
            // The database is the thing that failed; the file log already has it.
        }

        self::$logging = false;
    }

    private static function render(Throwable $e): void
    {
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, 'ERROR: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() . PHP_EOL);
            exit(1);
        }

        // Clear any partial output so the error page renders cleanly.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        if (!headers_sent()) {
            http_response_code(500);
        }

        if (self::expectsJson()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'message' => APP_DEBUG ? $e->getMessage() : 'Something went wrong. Please try again.',
                'errors'  => APP_DEBUG ? ['file' => $e->getFile(), 'line' => $e->getLine()] : new stdClass(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        if (APP_DEBUG) {
            echo '<!doctype html><meta charset="utf-8"><title>Application Error</title>'
                . '<div style="font-family:ui-monospace,Menlo,Consolas,monospace;padding:28px;max-width:1000px;margin:auto">'
                . '<h1 style="color:#b91c1c;font-size:19px;margin:0 0 12px">' . htmlspecialchars(get_class($e), ENT_QUOTES, 'UTF-8') . '</h1>'
                . '<p style="font-size:15px;background:#fef2f2;padding:12px;border-left:3px solid #b91c1c">'
                . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>'
                . '<p style="color:#6b7280">' . htmlspecialchars($e->getFile(), ENT_QUOTES, 'UTF-8') . ':' . $e->getLine() . '</p>'
                . '<pre style="background:#f9fafb;padding:14px;overflow:auto;font-size:12px;line-height:1.6">'
                . htmlspecialchars($e->getTraceAsString(), ENT_QUOTES, 'UTF-8') . '</pre></div>';
            exit;
        }

        $errorPage = ROOT_PATH . '/500.php';
        if (is_file($errorPage)) {
            require $errorPage;
        } else {
            echo '<!doctype html><meta charset="utf-8"><title>Something went wrong</title>'
                . '<div style="font-family:system-ui;text-align:center;margin:15vh auto;max-width:460px">'
                . '<h1 style="font-size:20px">Something went wrong. Please try again.</h1></div>';
        }
        exit;
    }

    private static function expectsJson(): bool
    {
        $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        if (strpos($script, '/api/') !== false) {
            return true;
        }
        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
            return true;
        }
        return strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false;
    }

    private static function currentUrl(): string
    {
        if (PHP_SAPI === 'cli') {
            return 'cli:' . implode(' ', $_SERVER['argv'] ?? []);
        }
        return ($_SERVER['REQUEST_METHOD'] ?? 'GET') . ' ' . ($_SERVER['REQUEST_URI'] ?? '');
    }

    /**
     * Drop the query string on the pages whose query string IS the secret.
     *
     * A reset or verification link carries a working token in the URL. Logging
     * it - to a file an admin can download, and to a table an admin with
     * logs.view can read - hands out account takeover to anyone who can read
     * the logs, which is the whole point of storing only the token's hash.
     * Ordinary pages keep their query string; ?page=2 is what makes a log
     * entry useful.
     */
    public static function sanitizeUrl(string $url): string
    {
        static $sensitive = [
            'reset-password', 'forgot-password', 'verify-email', 'set-password',
            'resend-verification', 'newsletter-unsubscribe', 'unsubscribe',
        ];

        foreach ($sensitive as $needle) {
            if (stripos($url, $needle) !== false) {
                return (string) strtok($url, '?');
            }
        }

        // Anything that looks like a secret in a query string goes too, whatever
        // page it is on: a signed webhook callback, a share link, an API key
        // pasted into a URL by an integration.
        return (string) preg_replace(
            '/\b(token|code|secret|signature|sig|key|password|otp|auth|access_token)=[^&\s]*/i',
            '$1=[redacted]',
            $url
        );
    }

    /**
     * Strip quoted argument values out of a stack trace.
     *
     * zend.exception_ignore_args is set in register() so new traces carry no
     * arguments at all. This is the belt to that pair of braces: traces that
     * arrive from somewhere else (a caught exception re-logged by hand, a
     * trace captured before the ini_set on an unusual SAPI) still must not
     * carry the password that was passed to the function that failed.
     */
    public static function sanitizeTrace(string $trace): string
    {
        return (string) preg_replace(
            ["/'(?:[^'\\\\]|\\\\.)*'/", '/\bArray\s*\(\s*\.\.\.\s*\)/'],
            ["'...'", 'Array'],
            $trace
        );
    }

    private static function clientIp(): string
    {
        return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    }
}
