<?php
/**
 * ShopInnKart - Bot protection for public forms.
 *
 * One layer in front of every form a stranger can post to: sign-up, sign-in,
 * forgot-password, contact, newsletter, reviews and (through
 * bot_guard_coupon()) coupon codes.
 *
 * It has two halves and the store works with either one on its own:
 *
 *  1. A CAPTCHA provider, if the owner has configured one. Cloudflare
 *     Turnstile, hCaptcha and reCAPTCHA v3 all speak the same shape - a token
 *     in the POST, verified server-side against the provider - so they sit
 *     behind one interface here and the pages never know which is in use.
 *     A refusal fails CLOSED (the post is rejected). A provider OUTAGE fails
 *     OPEN, because a store that cannot register customers because a CAPTCHA
 *     vendor is down has been hurt more by the defence than by the spam.
 *     Both are written to security_events, so "we were open for two hours" is
 *     a fact the owner can see rather than a silent hole.
 *
 *  2. The built-in fallback, which needs no vendor, no keys and no JavaScript:
 *     a honeypot field a person never sees, a minimum time-on-form (signed, so
 *     it cannot be back-dated) and a per-IP + per-form ceiling in the shared
 *     `rate_limits` table.
 *
 * It is ADAPTIVE by default: an ordinary customer filling in one form once
 * never sees a puzzle. The challenge only appears after failed attempts from
 * that address, or above a submission rate no human reaches.
 *
 * Nothing here throws. A form must still work when the settings table, the
 * rate limiter or the provider is having a bad day.
 */

declare(strict_types=1);

/** Hidden field names. `website` is what register.php and contact.php already use. */
const BOT_HONEYPOT_FIELD = 'website';
const BOT_STAMP_FIELD    = 'form_ts';

/**
 * The forms this layer knows about.
 *
 * 'widget' says whether that form actually renders a CAPTCHA box. It matters:
 * demanding a puzzle from a form that cannot show one is an unanswerable
 * refusal, so those forms escalate to "too many attempts, try later" instead.
 */
function bot_forms(): array
{
    return [
        'register'        => ['label' => 'Create account',   'widget' => true,  'min_seconds' => 3, 'burst' => 12],
        'login'           => ['label' => 'Sign in',          'widget' => true,  'min_seconds' => 1, 'burst' => 20],
        'forgot_password' => ['label' => 'Forgot password',  'widget' => true,  'min_seconds' => 2, 'burst' => 8],
        'contact'         => ['label' => 'Contact form',     'widget' => true,  'min_seconds' => 5, 'burst' => 8],
        'newsletter'      => ['label' => 'Newsletter',       'widget' => false, 'min_seconds' => 2, 'burst' => 10],
        'review'          => ['label' => 'Product review',   'widget' => false, 'min_seconds' => 5, 'burst' => 10],
        'coupon'          => ['label' => 'Coupon code',      'widget' => false, 'min_seconds' => 0, 'burst' => 20],
    ];
}

function bot_form_config(string $form): array
{
    $forms = bot_forms();
    return $forms[$form] ?? ['label' => $form, 'widget' => false, 'min_seconds' => 2, 'burst' => 12];
}

// ===========================================================================
//  Configuration
// ===========================================================================

/** 'off' | 'adaptive' | 'always'. */
function bot_mode(): string
{
    $mode = (string) setting('sec_bot_mode', 'adaptive');
    return in_array($mode, ['off', 'adaptive', 'always'], true) ? $mode : 'adaptive';
}

/** '' | 'turnstile' | 'hcaptcha' | 'recaptcha'. */
function bot_provider(): string
{
    $provider = (string) setting('sec_bot_provider', '');
    return isset(bot_providers()[$provider]) ? $provider : '';
}

/** Everything that differs between the three vendors, in one place. */
function bot_providers(): array
{
    return [
        'turnstile' => [
            'label'   => 'Cloudflare Turnstile',
            'field'   => 'cf-turnstile-response',
            'verify'  => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
            'script'  => 'https://challenges.cloudflare.com/turnstile/v0/api.js',
            'class'   => 'cf-turnstile',
            'scored'  => false,
            'csp'     => [
                'script-src'  => ['https://challenges.cloudflare.com'],
                'frame-src'   => ['https://challenges.cloudflare.com'],
                'connect-src' => ['https://challenges.cloudflare.com'],
            ],
            'keys_at' => 'https://dash.cloudflare.com/?to=/:account/turnstile',
        ],
        'hcaptcha' => [
            'label'   => 'hCaptcha',
            'field'   => 'h-captcha-response',
            'verify'  => 'https://api.hcaptcha.com/siteverify',
            'script'  => 'https://js.hcaptcha.com/1/api.js',
            'class'   => 'h-captcha',
            'scored'  => false,
            'csp'     => [
                'script-src'  => ['https://js.hcaptcha.com', 'https://newassets.hcaptcha.com'],
                'frame-src'   => ['https://newassets.hcaptcha.com', 'https://js.hcaptcha.com'],
                'connect-src' => ['https://api.hcaptcha.com', 'https://newassets.hcaptcha.com'],
                'style-src'   => ['https://newassets.hcaptcha.com'],
            ],
            'keys_at' => 'https://dashboard.hcaptcha.com/sites',
        ],
        'recaptcha' => [
            'label'   => 'Google reCAPTCHA v3',
            'field'   => 'g-recaptcha-response',
            'verify'  => 'https://www.google.com/recaptcha/api/siteverify',
            'script'  => 'https://www.google.com/recaptcha/api.js',
            'class'   => '',
            'scored'  => true,
            'csp'     => [
                'script-src'  => ['https://www.google.com', 'https://www.gstatic.com'],
                'frame-src'   => ['https://www.google.com'],
                'connect-src' => ['https://www.google.com'],
            ],
            'keys_at' => 'https://www.google.com/recaptcha/admin',
        ],
    ];
}

function bot_provider_meta(): array
{
    $provider = bot_provider();
    return $provider === '' ? [] : bot_providers()[$provider];
}

function bot_site_key(): string
{
    return trim((string) setting('sec_bot_site_key', ''));
}

/** The provider secret, decrypted. Never printed anywhere. */
function bot_secret(): string
{
    $stored = (string) setting('sec_bot_secret', '');
    return $stored === '' ? '' : secret_decrypt($stored);
}

/** A provider is only "configured" when both halves of the key pair are there. */
function bot_provider_ready(): bool
{
    return bot_provider() !== '' && bot_site_key() !== '' && bot_secret() !== '';
}

function bot_fail_open(): bool
{
    return setting_bool('sec_bot_fail_open', true);
}

// ===========================================================================
//  Adaptive decision
// ===========================================================================

/**
 * Count one failure against this client, so the next attempt meets a challenge.
 *
 * Called for a wrong password, a refused coupon code, a failed CAPTCHA - any
 * outcome a person rarely repeats and a script always does.
 */
function bot_note_failure(string $form, string $key = ''): void
{
    rate_limit_attempt('bot.fail.' . $form, bot_client_key($key), 1000000, 3600);
}

/** A success clears the suspicion, so one fat-fingered password is not a tax. */
function bot_note_success(string $form, string $key = ''): void
{
    rate_limit_clear('bot.fail.' . $form, bot_client_key($key));
}

/** How many failures this client has run up on this form in the last hour. */
function bot_failure_count(string $form, string $key = ''): int
{
    return (int) floor(rate_limit_count('bot.fail.' . $form, bot_client_key($key), 3600, false));
}

/**
 * Does this submission have to answer a challenge?
 *
 * 'always' means every time. 'adaptive' - the default - means only once this
 * client has failed this form repeatedly, or is posting it faster than a
 * person could. Everybody else is waved through.
 */
function bot_challenge_due(string $form, string $key = ''): bool
{
    return bot_challenge_reason($form, $key) !== '';
}

/**
 * WHY a challenge is due: '' (it is not), 'always', 'failures' or 'rate'.
 *
 * The reason matters when no provider is configured, because then there is no
 * challenge to show. A client that is merely posting too fast can be told to
 * slow down - that is an ordinary rate limit. A client that has just got a
 * password wrong a few times must NOT be, or three typos would cost a customer
 * an hour of not being able to sign in, which is a worse lockout than the one
 * the login throttle was rebuilt to remove. Failures without a provider are
 * left to auth_throttle_gate(), which already handles them properly.
 */
function bot_challenge_reason(string $form, string $key = ''): string
{
    $mode = bot_mode();
    if ($mode === 'off') {
        return '';
    }
    if ($mode === 'always') {
        return 'always';
    }

    $failures = max(1, setting_int('sec_bot_failures', 3));
    if (bot_failure_count($form, $key) >= $failures) {
        return 'failures';
    }

    // Submission rate. The counter is recorded by bot_check() and only read
    // here, so asking "should I draw a challenge?" while rendering the page
    // never inflates the number the next POST is judged against.
    return rate_limit_allows('bot.rate.' . $form, bot_client_key($key), bot_burst($form), 600) ? '' : 'rate';
}

/**
 * Submissions of one form, from one client, in ten minutes before a challenge
 * appears. The per-form number is the sensible default; sec_bot_burst lets the
 * owner move every form at once.
 */
function bot_burst(string $form): int
{
    $configured = setting_int('sec_bot_burst', 0);
    if ($configured > 0) {
        return max(2, $configured);
    }
    return max(2, (int) (bot_form_config($form)['burst'] ?? 12));
}

/** IP, plus whatever the caller can add (an email, an account id). */
function bot_client_key(string $key = ''): string
{
    return client_ip() . ($key === '' ? '' : '|' . mb_strtolower($key));
}

// ===========================================================================
//  The check
// ===========================================================================

/**
 * Decide whether this POST looks like a person.
 *
 * @param array $opts  'key' => extra identity for the counters (an email),
 *                     'input' => the request array (defaults to request_all()),
 *                     'record' => false to check without counting the attempt.
 * @return array{ok:bool, reason:string, message:string, challenged:bool}
 */
function bot_check(string $form, array $opts = []): array
{
    $key    = (string) ($opts['key'] ?? '');
    $input  = is_array($opts['input'] ?? null) ? $opts['input'] : bot_request_input();
    $record = (bool) ($opts['record'] ?? true);
    $config = bot_form_config($form);

    $pass = static fn (): array => ['ok' => true, 'reason' => 'ok', 'message' => '', 'challenged' => false];

    if (bot_mode() === 'off') {
        return $pass();
    }

    // -- 1. Honeypot. A field that is hidden from people and from screen
    //       readers; only a form-filling script ever puts anything in it.
    $honeypot = $input[BOT_HONEYPOT_FIELD] ?? '';
    if (is_string($honeypot) && trim($honeypot) !== '') {
        security_event('bot.honeypot', 'low', ['form' => $form], null, null);
        bot_note_failure($form, $key);
        return [
            'ok'      => false,
            'reason'  => 'honeypot',
            'message' => 'We could not complete that. Please try again.',
            'challenged' => false,
        ];
    }

    // -- 2. Per-IP + per-form ceiling, and the rate that feeds the adaptive
    //       decision. Recorded before the provider is consulted so a flood
    //       cannot be used to run up our bill with the vendor.
    $hardCeiling = bot_burst($form) * 5;
    if ($record && !rate_limit_attempt('bot.rate.' . $form, bot_client_key($key), $hardCeiling, 600)) {
        security_event('bot.flood', 'medium', ['form' => $form], null, null);
        return [
            'ok'      => false,
            'reason'  => 'rate',
            'message' => 'Too many attempts from this device. Please try again in a few minutes.',
            'challenged' => false,
        ];
    }

    // -- 3. Minimum time on the form. The stamp is signed, so a script cannot
    //       simply post an older number.
    $minSeconds = (int) ($opts['min_seconds'] ?? $config['min_seconds'] ?? setting_int('sec_bot_min_seconds', 3));
    $noStamp    = false;
    if ($minSeconds > 0) {
        $age = bot_stamp_age($form, is_string($input[BOT_STAMP_FIELD] ?? null) ? (string) $input[BOT_STAMP_FIELD] : '');
        if ($age !== null && $age < $minSeconds) {
            security_event('bot.too_fast', 'low', ['form' => $form, 'seconds' => $age], null, null);
            bot_note_failure($form, $key);
            return [
                'ok'      => false,
                'reason'  => 'too_fast',
                'message' => 'That was submitted a little too quickly. Please try again.',
                'challenged' => false,
            ];
        }

        // A missing or unsigned stamp used to be read as "unknown" and waved
        // through, which is precisely what a script posting straight at the
        // endpoint looks like: it never loaded the form, so it has nothing to
        // strip. It is still not proof of a robot - a cached page or a client
        // that drops hidden fields produces the same thing - so it is not a
        // refusal on its own. It counts as a failure, which arms the adaptive
        // challenge, and it earns a CAPTCHA where one is configured.
        $noStamp = $age === null && bot_stamp_key() !== '';
        if ($noStamp) {
            security_event('bot.no_stamp', 'low', ['form' => $form], null, null);
            if ($record) {
                bot_note_failure($form, $key);
            }
        }
    }

    // -- 4. The CAPTCHA, when this client has earned one.
    $token = bot_submitted_token($input);
    $why   = bot_challenge_reason($form, $key);
    if ($why === '' && $noStamp) {
        $why = 'no_stamp';
    }

    if ($token === '' && $why === '') {
        return $pass();
    }

    if (!bot_provider_ready()) {
        // No vendor configured, so there is no challenge to put in front of
        // them. Somebody posting far too fast is told to slow down; somebody
        // who has merely got their password wrong is NOT - see
        // bot_challenge_reason() for why that distinction matters.
        if ($why === 'rate') {
            security_event('bot.throttled', 'low', ['form' => $form, 'reason' => 'no provider'], null, null);
            return [
                'ok'      => false,
                'reason'  => 'rate',
                'message' => 'Too many attempts from this device. Please wait a few minutes and try again.',
                'challenged' => false,
            ];
        }
        return $pass();
    }

    if ($token === '') {
        // A form that cannot draw a CAPTCHA box must not be sent away to
        // answer one: for those, "you have earned a challenge" means "slow
        // down", which is an outcome the sender can actually act on.
        $hasWidget = (bool) ($config['widget'] ?? false);
        return [
            'ok'      => false,
            'reason'  => $hasWidget ? 'captcha_missing' : 'rate',
            'message' => $hasWidget
                ? 'Please complete the "I am not a robot" check and submit again.'
                : 'Too many attempts from this device. Please wait a few minutes and try again.',
            'challenged' => true,
        ];
    }

    $result = bot_verify_provider($token, $form);

    if ($result['outage']) {
        // The vendor did not answer. Failing closed here would shut the store's
        // front door because somebody else's service is down.
        if (bot_fail_open()) {
            return ['ok' => true, 'reason' => 'provider_outage', 'message' => '', 'challenged' => true];
        }
        return [
            'ok'      => false,
            'reason'  => 'provider_outage',
            'message' => 'We could not reach our anti-spam check just now. Please try again in a moment.',
            'challenged' => true,
        ];
    }

    if (!$result['ok']) {
        bot_note_failure($form, $key);
        return [
            'ok'      => false,
            'reason'  => 'captcha_failed',
            'message' => 'That anti-robot check did not pass. Please try again.',
            'challenged' => true,
        ];
    }

    return ['ok' => true, 'reason' => 'ok', 'message' => '', 'challenged' => true];
}

/** The provider's token out of whichever field name it uses. */
function bot_submitted_token(array $input): string
{
    foreach (['cf-turnstile-response', 'h-captcha-response', 'g-recaptcha-response', 'captcha_token'] as $field) {
        $value = $input[$field] ?? null;
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }
    }
    return '';
}

/** POST + GET + a JSON body, without depending on the API helpers. */
function bot_request_input(): array
{
    $json = function_exists('request_json_body') ? request_json_body() : [];
    return array_merge($_GET, $_POST, $json);
}

// ===========================================================================
//  Provider verification
// ===========================================================================

/**
 * Ask the provider whether this token is genuine.
 *
 * @return array{ok:bool, outage:bool, score:?float, codes:array}
 */
function bot_verify_provider(string $token, string $form = ''): array
{
    $meta   = bot_provider_meta();
    $secret = bot_secret();

    if ($meta === [] || $secret === '') {
        return ['ok' => false, 'outage' => true, 'score' => null, 'codes' => ['not-configured']];
    }

    $timeout = max(1, min(10, setting_int('sec_bot_timeout', 4)));
    $body    = http_build_query([
        'secret'   => $secret,
        'response' => $token,
        'remoteip' => client_ip(),
    ]);

    $raw = bot_http_post((string) $meta['verify'], $body, $timeout);

    if ($raw === null) {
        security_event('bot.provider_outage', 'medium', [
            'provider' => bot_provider(),
            'form'     => $form,
            'action'   => bot_fail_open() ? 'allowed (fail open)' : 'refused (fail closed)',
        ]);
        return ['ok' => false, 'outage' => true, 'score' => null, 'codes' => ['no-response']];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        security_event('bot.provider_outage', 'medium', [
            'provider' => bot_provider(),
            'form'     => $form,
            'action'   => bot_fail_open() ? 'allowed (fail open)' : 'refused (fail closed)',
            'detail'   => 'unreadable answer',
        ]);
        return ['ok' => false, 'outage' => true, 'score' => null, 'codes' => ['bad-json']];
    }

    $verdict = bot_verify_decide($decoded, (bool) ($meta['scored'] ?? false));

    if ($verdict['outage']) {
        security_event('bot.provider_misconfigured', 'high', [
            'provider' => bot_provider(),
            'form'     => $form,
            'codes'    => $verdict['codes'],
        ]);
    } elseif (!$verdict['ok']) {
        security_event(
            in_array('low-score', $verdict['codes'], true) ? 'bot.captcha_low_score' : 'bot.captcha_failed',
            'low',
            ['provider' => bot_provider(), 'form' => $form, 'codes' => $verdict['codes'], 'score' => $verdict['score']]
        );
    }

    return $verdict;
}

/**
 * Turn a provider's answer into a verdict.
 *
 * Separate from the HTTP call so the rule can be checked without a vendor, and
 * because the distinction it draws is the whole design:
 *
 *   - "success": false  -> the visitor failed. Fails CLOSED.
 *   - a bad or missing SECRET, or a malformed request -> OUR misconfiguration,
 *     not the visitor's fault. Counted as an outage, so the fail-open setting
 *     decides and the owner gets a high-severity event instead of a store full
 *     of customers who cannot register.
 *   - reCAPTCHA v3 answers "success": true for everyone and grades instead, so
 *     the score is the real verdict there.
 *
 * @return array{ok:bool, outage:bool, score:?float, codes:array}
 */
function bot_verify_decide(array $decoded, bool $scored): array
{
    $codes   = array_values(array_filter((array) ($decoded['error-codes'] ?? []), 'is_string'));
    $success = (bool) ($decoded['success'] ?? false);
    $score   = isset($decoded['score']) && is_numeric($decoded['score']) ? (float) $decoded['score'] : null;

    if (!$success && array_intersect($codes, ['invalid-input-secret', 'missing-input-secret', 'bad-request'])) {
        return ['ok' => false, 'outage' => true, 'score' => $score, 'codes' => $codes];
    }

    if (!$success) {
        return ['ok' => false, 'outage' => false, 'score' => $score, 'codes' => $codes];
    }

    if ($scored && $score !== null && $score < (float) setting('sec_bot_score', '0.5')) {
        return ['ok' => false, 'outage' => false, 'score' => $score, 'codes' => ['low-score']];
    }

    return ['ok' => true, 'outage' => false, 'score' => $score, 'codes' => []];
}

/**
 * One short POST to the provider. Returns null for anything that is not a
 * readable 2xx answer - that is what "outage" means to the caller.
 */
function bot_http_post(string $url, string $body, int $timeout): ?string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => max(1, (int) ceil($timeout / 2)),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_USERAGENT      => 'ShopInnKart/1.0',
        ]);
        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return (is_string($raw) && $code >= 200 && $code < 300) ? $raw : null;
    }

    // Shared hosting without curl: the stream wrapper does the same job, as
    // long as allow_url_fopen is on. If it is not, this is an outage too.
    $context = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content'       => $body,
            'timeout'       => $timeout,
            'ignore_errors' => true,
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);

    $raw = @file_get_contents($url, false, $context);
    if (!is_string($raw) || $raw === '') {
        return null;
    }

    $status = 0;
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
            $status = (int) $m[1];
        }
    }

    return ($status >= 200 && $status < 300) ? $raw : null;
}

/**
 * Check the configured keys against the provider, without a real visitor.
 *
 * A deliberately invalid response token is sent: the provider answers
 * "invalid-input-response" when the SECRET is right and the token is not,
 * and "invalid-input-secret" when the secret itself is wrong. That is enough
 * to tell a typo in the secret from a firewall blocking outbound HTTPS, which
 * are the two ways this goes wrong on shared hosting.
 *
 * @return array{ok:bool, status:string, detail:string}
 */
function bot_test_connection(): array
{
    if (!bot_provider_ready()) {
        return ['ok' => false, 'status' => 'not_configured', 'detail' => 'Add the site key and the secret first.'];
    }

    $meta = bot_provider_meta();
    $raw  = bot_http_post((string) $meta['verify'], http_build_query([
        'secret'   => bot_secret(),
        'response' => 'shopinnkart-connection-test',
    ]), max(1, min(10, setting_int('sec_bot_timeout', 4))));

    if ($raw === null) {
        return [
            'ok'     => false,
            'status' => 'unreachable',
            'detail' => 'This server could not reach ' . $meta['label'] . '. Outbound HTTPS may be blocked by the host.',
        ];
    }

    $decoded = json_decode($raw, true);
    $codes   = is_array($decoded) ? array_values(array_filter((array) ($decoded['error-codes'] ?? []), 'is_string')) : [];

    if (array_intersect($codes, ['invalid-input-secret', 'missing-input-secret', 'bad-request'])) {
        return ['ok' => false, 'status' => 'bad_secret', 'detail' => $meta['label'] . ' rejected the secret key.'];
    }

    return [
        'ok'     => true,
        'status' => 'ok',
        'detail' => $meta['label'] . ' answered this server and accepted the secret key.',
    ];
}

// ===========================================================================
//  The signed time-on-form stamp
// ===========================================================================

/** "<unix time>.<signature>", so a bot cannot back-date it. */
function bot_stamp(string $form): string
{
    $key = bot_stamp_key();
    if ($key === '') {
        return '';
    }
    $now = time();
    return $now . '.' . substr(hash_hmac('sha256', $form . '|' . $now, $key), 0, 32);
}

/**
 * Seconds since the form was rendered, or null when we cannot tell (no stamp,
 * a forged one, or one so old the page has clearly been sitting open).
 */
function bot_stamp_age(string $form, string $value): ?int
{
    $key = bot_stamp_key();
    if ($key === '' || $value === '' || strpos($value, '.') === false) {
        return null;
    }

    [$issued, $signature] = explode('.', $value, 2);
    if (!ctype_digit($issued)) {
        return null;
    }

    $expected = substr(hash_hmac('sha256', $form . '|' . $issued, $key), 0, 32);
    if (!hash_equals($expected, $signature)) {
        // A stamp we did not issue. Not "too fast" - it is simply not evidence.
        security_event('bot.stamp_forged', 'low', ['form' => $form]);
        return null;
    }

    $age = time() - (int) $issued;
    // Older than six hours: the visitor left the tab open. Say nothing.
    return ($age < 0 || $age > 21600) ? null : $age;
}

function bot_stamp_key(): string
{
    if (!function_exists('app_key_derive') || !app_key_available()) {
        return '';
    }
    return app_key_derive('bot-form-stamp');
}

// ===========================================================================
//  Rendering
// ===========================================================================

/**
 * The hidden fields every protected form carries: the honeypot and the stamp.
 * Safe to call on any form - it prints nothing a person can see.
 */
function bot_fields(string $form): string
{
    if (bot_mode() === 'off') {
        return '';
    }

    $stamp = bot_stamp($form);
    $html  = '<div aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden">'
        . '<label>Website<input type="text" name="' . BOT_HONEYPOT_FIELD . '" tabindex="-1" autocomplete="off" value=""></label>'
        . '</div>';

    if ($stamp !== '') {
        $html .= '<input type="hidden" name="' . BOT_STAMP_FIELD . '" value="' . e_attr($stamp) . '">';
    }

    return $html;
}

/**
 * The CAPTCHA box, printed only when this client has actually earned one (or
 * the owner asked for "always"). Includes the hidden fields, so a form only
 * needs this one call.
 */
function bot_form_html(string $form, string $key = ''): string
{
    $html = bot_fields($form);

    if (!bot_provider_ready() || !bot_challenge_due($form, $key)) {
        return $html;
    }

    $meta    = bot_provider_meta();
    $siteKey = bot_site_key();
    $id      = 'sikBot' . preg_replace('/[^a-zA-Z0-9]/', '', $form);

    if (bot_provider() === 'recaptcha') {
        // v3 is invisible: no box, just a token fetched as the form is sent.
        return $html
            . '<input type="hidden" name="g-recaptcha-response" id="' . e_attr($id) . '" value="">'
            . '<script src="' . e_attr($meta['script'] . '?render=' . urlencode($siteKey)) . '" async defer></script>'
            . '<script>(function(){var f=document.getElementById(' . json_encode($id) . ');if(!f)return;var form=f.form;'
            . 'if(!form)return;form.addEventListener("submit",function(ev){if(f.value)return;'
            . 'if(!window.grecaptcha||!window.grecaptcha.execute)return;ev.preventDefault();'
            . 'window.grecaptcha.ready(function(){window.grecaptcha.execute(' . json_encode($siteKey)
            . ',{action:' . json_encode($form) . '}).then(function(t){f.value=t;form.submit();});});});})();</script>';
    }

    return $html
        . '<div class="sik-field sik-bot-check" style="margin-bottom:var(--sp-4)">'
        . '<div class="' . e_attr((string) $meta['class']) . '" data-sitekey="' . e_attr($siteKey) . '"></div>'
        . '<noscript><span class="sik-help">This check needs JavaScript. '
        . 'Turn it on, or write to us and we will help.</span></noscript>'
        . '</div>'
        . '<script src="' . e_attr((string) $meta['script']) . '" async defer></script>';
}

/**
 * Extra CSP sources the configured provider needs.
 *
 * includes/security-headers.php folds these into the policy; without them the
 * browser would block the very script the visitor has to run to get in.
 */
function bot_protection_csp_sources(): array
{
    return bot_provider_ready() ? (array) (bot_provider_meta()['csp'] ?? []) : [];
}

// ===========================================================================
//  Guards the pages and endpoints call
// ===========================================================================

/**
 * Guard a JSON endpoint. Answers and exits when the post fails.
 *
 * 'quiet_success' => a message to answer 200 with instead of an error. Used on
 * forms where telling a spammer "you were caught" only helps them tune: the
 * honeypot branch on register and contact has always answered like this.
 */
function bot_guard_api(string $form, array $opts = []): array
{
    $result = bot_check($form, $opts);
    if ($result['ok']) {
        return $result;
    }

    $quiet = (string) ($opts['quiet_success'] ?? '');
    if ($quiet !== '' && in_array($result['reason'], ['honeypot', 'too_fast'], true)) {
        json_success($quiet, [], (int) ($opts['quiet_status'] ?? 200));
    }

    $status = $result['reason'] === 'rate' ? 429 : 400;
    if ($status === 429 && !headers_sent()) {
        header('Retry-After: ' . rate_limit_retry_after(600));
    }

    json_error($result['message'], [], $status, $result['reason'] === 'rate' ? 'rate_limit' : 'bot');
}

/**
 * Guard a page that posts back to itself (the no-JavaScript path).
 *
 * @return string|null an error message to show, or null when the post may run.
 */
function bot_guard_form(string $form, array $opts = []): ?string
{
    $result = bot_check($form, $opts);
    return $result['ok'] ? null : $result['message'];
}

/**
 * Coupon-code guessing.
 *
 * The cart endpoints are owned by another team, so this is left here for them
 * to call: one line at the top of the apply handler, and bot_note_failure()
 * on a code that did not exist. Without it, a script can walk the whole coupon
 * namespace as fast as the server answers.
 *
 *   bot_guard_coupon($code);                       // before looking the code up
 *   bot_note_failure('coupon');                    // when the code is invalid
 *   bot_note_success('coupon');                    // when it applied
 */
function bot_guard_coupon(string $code, array $opts = []): array
{
    // Keyed on the client only, never on the code: keying on the code would
    // give every new guess a fresh allowance, which is the opposite of a limit.
    return bot_guard_api('coupon', $opts + [
        'key'         => '',
        'min_seconds' => 0,
    ]);
}
