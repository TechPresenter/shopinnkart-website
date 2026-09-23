<?php
/**
 * ShopInnKart - SMTP transport (PHPMailer).
 *
 * This file owns *how* a message leaves the building. What to send and to whom
 * stays in notifications.php, which queues rows; process_notification_queue()
 * drains them through mailer_send() below.
 *
 * PHP's mail() is deliberately not used: it gives no delivery signal, no auth,
 * no TLS and no way to attach a PDF reliably.
 *
 * Credential precedence, mirroring config/config.php's database handling:
 *     environment variables  >  config/mail.local.php  >  admin settings (DB)
 *
 * A password typed into the admin panel is encrypted at rest with the app key
 * and is never rendered back into the HTML.
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
//  PHPMailer — no composer in this project, so the three classes we use are
//  required on demand rather than autoloaded.
// ---------------------------------------------------------------------------

function mailer_load_phpmailer(): bool
{
    if (class_exists(\PHPMailer\PHPMailer\PHPMailer::class, false)) {
        return true;
    }

    $dir = ROOT_PATH . '/vendor/phpmailer';
    foreach (['Exception.php', 'SMTP.php', 'PHPMailer.php'] as $file) {
        $path = $dir . '/' . $file;
        if (!is_file($path)) {
            return false;
        }
        require_once $path;
    }

    return class_exists(\PHPMailer\PHPMailer\PHPMailer::class, false);
}

// ---------------------------------------------------------------------------
//  Application key + secret storage
// ---------------------------------------------------------------------------

/**
 * Work out the installation key, without touching any global state.
 *
 * Split out from app_key() so the read-only-config path can be tested: it is
 * the branch nobody exercises until a shared host locks config/ down, and it
 * used to be the dangerous one.
 *
 * @param string      $file where the key is kept (config/app.key.php)
 * @param string|null $env  the SIK_APP_KEY value, or null
 * @return array{key:string, source:string} source: env|file|generated|unavailable
 */
function app_key_resolve(string $file, ?string $env): array
{
    if (is_string($env) && strlen($env) >= 32) {
        return ['key' => $env, 'source' => 'env'];
    }

    if (is_file($file)) {
        $stored = require $file;
        if (is_string($stored) && strlen($stored) >= 32) {
            return ['key' => $stored, 'source' => 'file'];
        }
    }

    $generated = bin2hex(random_bytes(32));
    $php = "<?php\n// ShopInnKart application key. Generated automatically.\n"
        . "// Rotating this makes every stored SMTP password unreadable - re-enter them after a change.\n"
        . 'return ' . var_export($generated, true) . ";\n";

    if (!is_dir(dirname($file)) || @file_put_contents($file, $php, LOCK_EX) === false) {
        // There used to be a fallback here: sha256(DB_NAME|ROOT_PATH|constant).
        // On shared hosting both inputs are guessable - the database name is
        // usually the cPanel user plus the app name, and the document root is
        // /home/<user>/public_html - so anyone who could guess them could
        // decrypt every SMTP password and courier API secret, forge the
        // unsubscribe tokens and forge the admin-gate pass. A secret that the
        // attacker can compute is not a secret, and the fact that it is silent
        // is what makes it worse than failing.
        //
        // So: no key. Callers refuse to encrypt and the security card says so.
        return ['key' => '', 'source' => 'unavailable'];
    }

    @chmod($file, 0640);

    return ['key' => $generated, 'source' => 'generated'];
}

/**
 * A per-installation random key used to encrypt stored secrets.
 * Written once to config/app.key.php, which is gitignored and blocked by
 * config/.htaccess. An env var wins so containers can inject one.
 *
 * Returns '' when no key could be obtained - see app_key_available().
 */
function app_key(): string
{
    static $resolved = null;
    if ($resolved !== null) {
        return $resolved['key'];
    }

    $fromEnv  = getenv('SIK_APP_KEY');
    $resolved = app_key_resolve(CONFIG_PATH . '/app.key.php', $fromEnv === false ? null : $fromEnv);

    if ($resolved['source'] === 'unavailable' && function_exists('security_event')) {
        security_event('platform.app_key_unavailable', 'critical', [
            'config_path' => CONFIG_PATH,
            'hint'        => 'config/ is not writable and SIK_APP_KEY is not set',
        ]);
    }

    return $resolved['key'];
}

/** Can secrets be stored at all? False means config/ is not writable. */
function app_key_available(): bool
{
    return app_key() !== '';
}

/**
 * A separate key per purpose, derived from the master key.
 *
 * One key doing encryption, unsubscribe links and the admin-gate pass at once
 * means a weakness in any of them is a weakness in all of them. HKDF costs
 * nothing and keeps them independent.
 */
function app_key_derive(string $purpose): string
{
    $master = app_key();
    if ($master === '') {
        return '';
    }

    return hash_hkdf('sha256', $master, 32, 'shopinnkart|' . $purpose);
}

/** Encrypt a secret for storage in the settings table. */
function secret_encrypt(string $plain): string
{
    if ($plain === '') {
        return '';
    }

    // Fail closed. Returning the plaintext, or encrypting under a guessable
    // key, would look like it worked and quietly put an SMTP password in the
    // settings table in the clear.
    if (!app_key_available()) {
        if (function_exists('security_event')) {
            security_event('platform.secret_not_stored', 'high', ['reason' => 'no application key']);
        }
        return '';
    }

    $iv  = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt(
        $plain,
        'aes-256-gcm',
        secret_storage_key('v2'),
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );

    if ($cipher === false) {
        return '';
    }

    return 'enc:v2:' . base64_encode($iv . $tag . $cipher);
}

/**
 * The AES key for a given ciphertext version.
 *
 * v1 hashed the master key directly. v2 runs it through HKDF with a purpose
 * label, so the storage key, the unsubscribe-link key and the admin-gate key
 * are independent of each other. v1 is still read - an SMTP password saved
 * before this change must keep working - but nothing is written as v1 again.
 */
function secret_storage_key(string $version): string
{
    return $version === 'v1'
        ? hash('sha256', app_key(), true)
        : hash('sha256', app_key_derive('secret-storage'), true);
}

/** Decrypt a secret written by secret_encrypt(). Plaintext passes through. */
function secret_decrypt(string $stored): string
{
    if ($stored === '') {
        return '';
    }

    // Values written before encryption existed (or restored from a SQL dump)
    // are still usable rather than silently breaking mail delivery.
    if (preg_match('/^enc:(v1|v2):/', $stored, $m) !== 1) {
        return $stored;
    }

    if (!app_key_available()) {
        return '';     // no key: an encrypted value is unreadable, not plaintext
    }

    $raw = base64_decode(substr($stored, 7), true);
    if ($raw === false || strlen($raw) < 29) {
        return '';
    }

    $plain = openssl_decrypt(
        substr($raw, 28),
        'aes-256-gcm',
        secret_storage_key($m[1]),
        OPENSSL_RAW_DATA,
        substr($raw, 0, 12),
        substr($raw, 12, 16)
    );

    return $plain === false ? '' : $plain;
}

// ---------------------------------------------------------------------------
//  Configuration
// ---------------------------------------------------------------------------

/** Values from config/mail.local.php, if the file exists. */
function mail_local_config(): array
{
    static $local = null;
    if ($local !== null) {
        return $local;
    }

    $local = [];
    $file = CONFIG_PATH . '/mail.local.php';
    if (is_file($file)) {
        $loaded = require $file;
        if (is_array($loaded)) {
            $local = $loaded;
        }
    }

    return $local;
}

/**
 * Resolve one mail setting across all three sources.
 * $envKey is checked first so a production host never depends on the DB.
 */
function mail_config_value(string $envKey, string $localKey, string $settingKey, $default = '')
{
    $env = getenv($envKey);
    if ($env !== false && $env !== '') {
        return $env;
    }

    $local = mail_local_config();
    if (array_key_exists($localKey, $local) && $local[$localKey] !== '' && $local[$localKey] !== null) {
        return $local[$localKey];
    }

    return setting($settingKey, $default);
}

/**
 * The resolved SMTP profile.
 *
 * @return array{transport:string, host:string, port:int, username:string, password:string,
 *               encryption:string, from_email:string, from_name:string, reply_to:string,
 *               timeout:int, auth:bool, allow_self_signed:bool, source:array<string,string>}
 */
function mail_config(): array
{
    // `mail_driver` is the key admin/settings/email.php has always written.
    $transport = strtolower((string) mail_config_value('SIK_MAIL_TRANSPORT', 'transport', 'mail_driver', 'smtp'));
    if (!in_array($transport, ['smtp', 'log', 'disabled'], true)) {
        // 'mail' was the old default. PHP mail() is no longer a transport, so
        // installs still carrying that value fall through to SMTP.
        $transport = 'smtp';
    }

    $password = (string) mail_config_value('SIK_SMTP_PASSWORD', 'password', 'smtp_pass', '');
    // Only the DB copy is encrypted; env/local files hold plaintext by design.
    $password = secret_decrypt($password);

    $fromEmail = (string) mail_config_value('SIK_MAIL_FROM_EMAIL', 'from_email', 'mail_from_email', '');
    if ($fromEmail === '') {
        $fromEmail = (string) setting('store_email', 'no-reply@' . SITE_DOMAIN);
    }

    $replyTo = (string) mail_config_value('SIK_MAIL_REPLY_TO', 'reply_to', 'mail_reply_to', '');
    if ($replyTo === '') {
        $replyTo = (string) setting('store_email', $fromEmail);
    }

    return [
        'transport'  => $transport,
        'host'       => (string) mail_config_value('SIK_SMTP_HOST', 'host', 'smtp_host', ''),
        'port'       => (int) mail_config_value('SIK_SMTP_PORT', 'port', 'smtp_port', 587),
        'username'   => (string) mail_config_value('SIK_SMTP_USERNAME', 'username', 'smtp_user', ''),
        'password'   => $password,
        'encryption' => strtolower((string) mail_config_value('SIK_SMTP_ENCRYPTION', 'encryption', 'smtp_encryption', 'tls')),
        'from_email' => $fromEmail,
        'from_name'  => (string) mail_config_value('SIK_MAIL_FROM_NAME', 'from_name', 'mail_from_name', (string) setting('store_name', SITE_NAME)),
        'reply_to'   => $replyTo,
        'timeout'    => max(5, (int) mail_config_value('SIK_SMTP_TIMEOUT', 'timeout', 'smtp_timeout', 20)),
        'auth'       => (string) mail_config_value('SIK_SMTP_AUTH', 'auth', 'smtp_auth', '1') !== '0',
        'allow_self_signed' => setting_bool('smtp_allow_self_signed', false),
        'source'     => mail_config_sources(),
    ];
}

/** Where each credential actually came from — shown in the admin so nobody debugs the wrong copy. */
function mail_config_sources(): array
{
    $sources = [];
    foreach ([
        'host'       => ['SIK_SMTP_HOST', 'host'],
        'port'       => ['SIK_SMTP_PORT', 'port'],
        'username'   => ['SIK_SMTP_USERNAME', 'username'],
        'password'   => ['SIK_SMTP_PASSWORD', 'password'],
        'encryption' => ['SIK_SMTP_ENCRYPTION', 'encryption'],
        'transport'  => ['SIK_MAIL_TRANSPORT', 'transport'],
    ] as $field => [$envKey, $localKey]) {
        $local = mail_local_config();
        if (getenv($envKey) !== false && getenv($envKey) !== '') {
            $sources[$field] = 'environment';
        } elseif (array_key_exists($localKey, $local) && $local[$localKey] !== '') {
            $sources[$field] = 'config/mail.local.php';
        } else {
            $sources[$field] = 'admin settings';
        }
    }

    return $sources;
}

/** True when enough is configured for a real SMTP handshake. */
function mail_is_configured(): bool
{
    $config = mail_config();
    if ($config['transport'] === 'log') {
        return true;
    }
    if ($config['transport'] === 'disabled') {
        return false;
    }

    return $config['host'] !== '' && $config['from_email'] !== '';
}

// ---------------------------------------------------------------------------
//  Sending
// ---------------------------------------------------------------------------

/**
 * Deliver one message.
 *
 * $message keys:
 *   to (string, required), to_name, subject (required), html (required),
 *   text, reply_to, attachments => [['path' => absolute, 'name' => filename], ...],
 *   cc => [], bcc => []
 *
 * Never throws — the caller is usually a post-checkout hook that must not be
 * able to fail an order.
 *
 * @return array{ok:bool, error:?string, smtp:?string, transport:string}
 */
function mailer_send(array $message): array
{
    $to = trim((string) ($message['to'] ?? ''));
    $config = mail_config();
    $result = ['ok' => false, 'error' => null, 'smtp' => null, 'transport' => $config['transport']];

    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $result['error'] = 'Invalid recipient address.';
        return $result;
    }

    $subject = (string) ($message['subject'] ?? '');
    $html    = (string) ($message['html'] ?? '');
    $text    = trim((string) ($message['text'] ?? ''));
    if ($text === '') {
        $text = html_to_text($html);
    }

    if ($config['transport'] === 'disabled') {
        $result['error'] = 'Email sending is disabled in settings.';
        return $result;
    }

    // Attachments are resolved and existence-checked up front so a missing PDF
    // is reported as such instead of surfacing as an opaque SMTP failure.
    $attachments = [];
    foreach ((array) ($message['attachments'] ?? []) as $attachment) {
        $path = (string) ($attachment['path'] ?? '');
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            $result['error'] = 'Attachment missing or unreadable: ' . basename($path);
            return $result;
        }
        $attachments[] = [
            'path' => $path,
            'name' => (string) ($attachment['name'] ?? basename($path)),
        ];
    }

    if ($config['transport'] === 'log') {
        return mailer_write_to_log($to, $subject, $html, $text, $attachments, $config);
    }

    if (!mailer_load_phpmailer()) {
        $result['error'] = 'PHPMailer is not installed in vendor/phpmailer.';
        return $result;
    }

    if ($config['host'] === '') {
        $result['error'] = 'No SMTP host configured.';
        return $result;
    }

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

    // The SMTP conversation is captured rather than printed; the last server
    // reply is what gets stored against the log row.
    $transcript = [];
    $mail->SMTPDebug = \PHPMailer\PHPMailer\SMTP::DEBUG_SERVER;
    $mail->Debugoutput = static function (string $line, int $level) use (&$transcript): void {
        $line = trim($line);
        if ($line !== '') {
            $transcript[] = $line;
        }
    };

    try {
        $mail->isSMTP();
        $mail->Host       = $config['host'];
        $mail->Port       = $config['port'];
        $mail->SMTPAuth   = $config['auth'] && $config['username'] !== '';
        $mail->Username   = $config['username'];
        $mail->Password   = $config['password'];
        $mail->Timeout    = $config['timeout'];
        $mail->CharSet    = 'UTF-8';
        $mail->Encoding   = 'base64';
        $mail->XMailer    = 'ShopInnKart';

        if ($config['encryption'] === 'ssl' || $config['encryption'] === 'smtps') {
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($config['encryption'] === 'tls' || $config['encryption'] === 'starttls') {
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPSecure = '';
            $mail->SMTPAutoTLS = false;
        }

        if ($config['allow_self_signed']) {
            // Opt-in only. Local XAMPP/Mailhog setups present self-signed certs;
            // production installs should never turn this on.
            $mail->SMTPOptions = ['ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ]];
        }

        $mail->setFrom($config['from_email'], $config['from_name']);
        $mail->addAddress($to, (string) ($message['to_name'] ?? ''));

        $replyTo = trim((string) ($message['reply_to'] ?? $config['reply_to']));
        if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $mail->addReplyTo($replyTo);
        }

        foreach ((array) ($message['cc'] ?? []) as $cc) {
            if (filter_var($cc, FILTER_VALIDATE_EMAIL)) {
                $mail->addCC($cc);
            }
        }
        foreach ((array) ($message['bcc'] ?? []) as $bcc) {
            if (filter_var($bcc, FILTER_VALIDATE_EMAIL)) {
                $mail->addBCC($bcc);
            }
        }

        foreach ($attachments as $attachment) {
            $mail->addAttachment($attachment['path'], $attachment['name']);
        }

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html;
        $mail->AltBody = $text;

        $mail->send();

        $result['ok'] = true;
        $result['smtp'] = mailer_last_server_reply($transcript);
    } catch (\PHPMailer\PHPMailer\Exception $e) {
        $result['error'] = $mail->ErrorInfo !== '' ? $mail->ErrorInfo : $e->getMessage();
        $result['smtp']  = mailer_last_server_reply($transcript);
    } catch (Throwable $e) {
        $result['error'] = $e->getMessage();
        $result['smtp']  = mailer_last_server_reply($transcript);
    }

    return $result;
}

/** Condense the captured SMTP transcript to the last server response. */
function mailer_last_server_reply(array $transcript): ?string
{
    $serverLines = array_values(array_filter(
        $transcript,
        static fn (string $line): bool => stripos($line, 'SERVER -> CLIENT:') !== false
    ));

    $line = $serverLines === [] ? ($transcript === [] ? null : end($transcript)) : end($serverLines);
    if ($line === null || $line === false) {
        return null;
    }

    $line = trim(str_ireplace('SERVER -> CLIENT:', '', (string) $line));

    return $line === '' ? null : mb_substr($line, 0, 500);
}

/**
 * The 'log' transport: write the composed message to storage/logs/mail/ as a
 * .eml file instead of talking to a server. It exists so a developer machine
 * with no SMTP relay can still exercise the full pipeline end to end.
 */
function mailer_write_to_log(
    string $to,
    string $subject,
    string $html,
    string $text,
    array $attachments,
    array $config
): array {
    $dir = LOG_PATH . '/mail';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return ['ok' => false, 'error' => 'Could not create ' . $dir, 'smtp' => null, 'transport' => 'log'];
    }

    $names = array_map(static fn (array $a): string => $a['name'], $attachments);

    $body = "From: {$config['from_name']} <{$config['from_email']}>\r\n"
        . "To: {$to}\r\n"
        . "Subject: {$subject}\r\n"
        . 'Date: ' . date('r') . "\r\n"
        . ($names === [] ? '' : 'X-Attachments: ' . implode(', ', $names) . "\r\n")
        . "Content-Type: text/html; charset=UTF-8\r\n\r\n"
        . $html
        . "\r\n\r\n----- plain text part -----\r\n\r\n"
        . $text;

    $file = $dir . '/' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 8) . '.eml';
    if (@file_put_contents($file, $body) === false) {
        return ['ok' => false, 'error' => 'Could not write ' . $file, 'smtp' => null, 'transport' => 'log'];
    }

    return [
        'ok'    => true,
        'error' => null,
        'smtp'  => 'Written to storage/logs/mail/' . basename($file) . ' (transport: log, nothing was delivered)',
        'transport' => 'log',
    ];
}

/**
 * Verify the SMTP credentials without sending anything.
 *
 * @return array{ok:bool, error:?string, detail:?string}
 */
function mailer_verify_connection(): array
{
    $config = mail_config();

    if ($config['transport'] === 'log') {
        return ['ok' => true, 'error' => null, 'detail' => 'Transport is "log" — messages are written to storage/logs/mail/ and never delivered.'];
    }
    if ($config['transport'] === 'disabled') {
        return ['ok' => false, 'error' => 'Email sending is disabled in settings.', 'detail' => null];
    }
    if (!mailer_load_phpmailer()) {
        return ['ok' => false, 'error' => 'PHPMailer is not installed in vendor/phpmailer.', 'detail' => null];
    }
    if ($config['host'] === '') {
        return ['ok' => false, 'error' => 'No SMTP host configured.', 'detail' => null];
    }

    $transcript = [];
    $smtp = new \PHPMailer\PHPMailer\SMTP();
    $smtp->do_debug = \PHPMailer\PHPMailer\SMTP::DEBUG_SERVER;
    $smtp->Debugoutput = static function (string $line) use (&$transcript): void {
        $line = trim($line);
        if ($line !== '') {
            $transcript[] = $line;
        }
    };
    $smtp->Timeout = $config['timeout'];

    try {
        $host = ($config['encryption'] === 'ssl' || $config['encryption'] === 'smtps')
            ? 'ssl://' . $config['host']
            : $config['host'];

        if (!$smtp->connect($host, $config['port'], $config['timeout'])) {
            return ['ok' => false, 'error' => 'Could not connect to ' . $config['host'] . ':' . $config['port'], 'detail' => mailer_last_server_reply($transcript)];
        }
        if (!$smtp->hello(gethostname() ?: 'localhost')) {
            $smtp->quit();
            return ['ok' => false, 'error' => 'EHLO refused: ' . $smtp->getError()['error'], 'detail' => mailer_last_server_reply($transcript)];
        }

        if ($config['encryption'] === 'tls' || $config['encryption'] === 'starttls') {
            if (!$smtp->startTLS()) {
                $smtp->quit();
                return ['ok' => false, 'error' => 'STARTTLS failed: ' . $smtp->getError()['error'], 'detail' => mailer_last_server_reply($transcript)];
            }
            $smtp->hello(gethostname() ?: 'localhost');
        }

        if ($config['auth'] && $config['username'] !== ''
            && !$smtp->authenticate($config['username'], $config['password'])) {
            $error = $smtp->getError()['error'];
            $smtp->quit();
            return ['ok' => false, 'error' => 'Authentication failed: ' . $error, 'detail' => mailer_last_server_reply($transcript)];
        }

        $smtp->quit();

        return ['ok' => true, 'error' => null, 'detail' => mailer_last_server_reply($transcript)];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage(), 'detail' => mailer_last_server_reply($transcript)];
    }
}

// ---------------------------------------------------------------------------
//  Helpers
// ---------------------------------------------------------------------------

/**
 * Readable plain-text fallback from an HTML body.
 * Links become "text (url)" so the text part stays useful on its own.
 */
function html_to_text(string $html): string
{
    if (trim($html) === '') {
        return '';
    }

    $text = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $html) ?? $html;

    // "text (url)" keeps links usable in the text part, but a mailto whose
    // label already is the address would read "a@b.com (mailto:a@b.com)".
    $text = preg_replace('#<a\b[^>]*href=["\']mailto:([^"\']+)["\'][^>]*>\s*\1\s*</a>#is', '$1', $text) ?? $text;
    $text = preg_replace('#<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is', '$2 ($1)', $text) ?? $text;

    // Break on opening block tags as well as closing ones. Without the opening
    // set, a {{placeholder}} sitting immediately before a <tr> ends up glued to
    // the row that follows it once the tags are stripped.
    $text = preg_replace('#<(br|/p|/div|/tr|/h[1-6]|/li|/table)\s*/?>#i', "\n", $text) ?? $text;
    $text = preg_replace('#<(p|div|tr|h[1-6]|table)\b[^>]*>#i', "\n", $text) ?? $text;
    $text = preg_replace('#<li\b[^>]*>#i', '- ', $text) ?? $text;
    $text = preg_replace('#</(td|th)>#i', "\t", $text) ?? $text;
    $text = strip_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
    $text = preg_replace('/ ?\n ?/', "\n", $text) ?? $text;
    $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

    return trim($text);
}

/**
 * Sliding-window rate limit for the "send it again" buttons: resend invoice,
 * resend an order email, re-download an invoice, send a test email.
 *
 * It used to count in $_SESSION, which meant the cap was only ever as real as
 * the caller's cookie - dropping it handed out a fresh quota, and the sender
 * was the one choosing whether to send the cookie back. The counters live in
 * the shared `rate_limits` table now, where they survive a new session, a new
 * browser and a second process.
 *
 * $bucket already names what is being capped ("invoice_resend_<order id>",
 * "test_email_<admin id>"), and each of those is reachable only by the person
 * or the admin it belongs to, so the resource itself is the key.
 */
function mail_rate_limit_hit(string $bucket, int $max, int $windowSeconds): bool
{
    return rate_limit_attempt('mail.action', $bucket, $max, $windowSeconds);
}
