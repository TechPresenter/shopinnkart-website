<?php
/**
 * Security card: bot protection on the public forms.
 *
 * Returned to admin/security/settings.php, which renders the card and routes
 * this card's POST actions to the handlers below. See that file for the shape.
 */

declare(strict_types=1);

return [
    'key'    => 'bot-protection',
    'order'  => 60,
    'column' => 'main',

    'actions' => [
        'bot_save' => static function (string $back): void {
            $mode     = (string) input('sec_bot_mode', 'adaptive');
            $provider = (string) input('sec_bot_provider', '');
            $siteKey  = trim((string) input('sec_bot_site_key', ''));
            $secret   = isset($_POST['sec_bot_secret']) && is_string($_POST['sec_bot_secret'])
                ? trim($_POST['sec_bot_secret']) : '';

            $errors = [];
            if (!in_array($mode, ['off', 'adaptive', 'always'], true)) {
                $errors['sec_bot_mode'] = 'Choose one of the three modes.';
            }
            if ($provider !== '' && !isset(bot_providers()[$provider])) {
                $errors['sec_bot_provider'] = 'Choose one of the listed providers.';
            }

            // Half a key pair is worse than none: the form would draw a widget
            // nobody could pass. Either both halves or neither.
            $secretOnFile = (string) setting('sec_bot_secret', '') !== '';
            if ($provider !== '' && $siteKey === '') {
                $errors['sec_bot_site_key'] = 'The site key is needed as well as the secret.';
            }
            if ($provider !== '' && $secret === '' && !$secretOnFile) {
                $errors['sec_bot_secret'] = 'Paste the secret key from your provider dashboard.';
            }
            if ($secret !== '' && !app_key_available()) {
                $errors['sec_bot_secret'] = 'The application key is missing, so a secret cannot be stored safely. '
                    . 'Set APP_KEY in config/config.php first.';
            }

            $numbers = [
                'sec_bot_min_seconds' => [0, 60],
                'sec_bot_failures'    => [1, 50],
                'sec_bot_burst'       => [0, 500],
                'sec_bot_timeout'     => [1, 10],
            ];
            $values = [];
            foreach ($numbers as $key => [$low, $high]) {
                $value = (int) input($key, 0);
                if ($value < $low || $value > $high) {
                    $errors[$key] = 'Enter a number between ' . $low . ' and ' . $high . '.';
                    continue;
                }
                $values[$key] = $value;
            }

            $score = (float) input('sec_bot_score', '0.5');
            if ($score < 0 || $score > 1) {
                $errors['sec_bot_score'] = 'The score has to be between 0 and 1.';
            }

            if ($errors !== []) {
                flash_errors($errors);
                flash_old($_POST);
                flash('error', 'Bot protection was not changed.');
                redirect($back);
            }

            setting_save('sec_bot_mode', $mode, 'security', 'text');
            setting_save('sec_bot_provider', $provider, 'security', 'text');
            setting_save('sec_bot_site_key', $siteKey, 'security', 'text');
            setting_save('sec_bot_score', number_format($score, 2, '.', ''), 'security', 'text');
            setting_save('sec_bot_fail_open', input_bool('sec_bot_fail_open') ? '1' : '0', 'security', 'boolean');
            foreach ($values as $key => $value) {
                setting_save($key, (string) $value, 'security', 'number');
            }

            // Only written when the admin actually typed one - the field shows
            // a placeholder, never the stored secret, so an empty box means
            // "leave it alone" and not "erase it".
            if ($secret !== '') {
                setting_save('sec_bot_secret', secret_encrypt($secret), 'security', 'text');
            }
            if ($provider === '') {
                setting_save('sec_bot_secret', '', 'security', 'text');
            }

            admin_after_write();
            log_activity('security.bot_protection', 'settings', null,
                'Changed bot protection (' . $mode . ($provider === '' ? ', built-in only' : ', ' . $provider) . ')');
            security_event('settings.bot_protection', 'medium', [
                'mode' => $mode, 'provider' => $provider === '' ? 'none' : $provider,
            ], admin_id(), 'admin');

            flash('success', 'Saved. ' . ($mode === 'off'
                ? 'Bot protection is now off on every public form.'
                : 'Public forms are protected' . ($provider === '' ? ' by the built-in checks.' : ' by ' . bot_providers()[$provider]['label'] . '.')));
            redirect($back);
        },

        'bot_test' => static function (string $back): void {
            $result = bot_test_connection();
            security_event('settings.bot_provider_test', 'info', [
                'provider' => bot_provider() === '' ? 'none' : bot_provider(),
                'status'   => $result['status'],
            ], admin_id(), 'admin');
            flash($result['ok'] ? 'success' : 'error', $result['detail']);
            redirect($back);
        },
    ],

    'render' => static function (array $errors, bool $canEdit): void {
        $mode       = bot_mode();
        $provider   = bot_provider();
        $providers  = bot_providers();
        $ready      = bot_provider_ready();
        $hasSecret  = (string) setting('sec_bot_secret', '') !== '';
        $siteKey    = (string) (old('sec_bot_site_key', null) ?? bot_site_key());
        $minSeconds = setting_int('sec_bot_min_seconds', 3);
        $failures   = setting_int('sec_bot_failures', 3);
        $burst      = setting_int('sec_bot_burst', 0);
        $timeout    = setting_int('sec_bot_timeout', 4);
        $score      = (string) setting('sec_bot_score', '0.5');

        // What the fallback has actually caught, so the card is not a promise.
        $caught = (int) Database::fetchColumn(
            "SELECT COUNT(*) FROM `security_events`
              WHERE `type` IN ('bot.honeypot','bot.too_fast','bot.flood','bot.captcha_failed','bot.captcha_low_score','bot.throttled')
                AND `created_at` >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
        );
        $outages = (int) Database::fetchColumn(
            "SELECT COUNT(*) FROM `security_events`
              WHERE `type` IN ('bot.provider_outage','bot.provider_misconfigured')
                AND `created_at` >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
        );

        $status = $mode === 'off'
            ? ['amber', 'Off']
            : ($ready ? ['green', 'On · ' . $providers[$provider]['label']] : ['blue', 'On · built-in checks']);
        ?>
        <div class="ad-card" style="margin:0" id="bot-protection">
            <div class="ad-card__head">
                <div>
                    <h2 class="ad-card__title">Bot protection</h2>
                    <div class="ad-card__sub">Keeps scripts off the public forms.</div>
                </div>
                <span class="sik-status sik-status--<?= e($status[0]) ?>"><?= e($status[1]) ?></span>
            </div>

            <div class="ad-card__body" style="display:grid;gap:14px">
                <div class="ad-grid ad-grid--2" style="gap:12px">
                    <?= admin_stat_card('Blocked in the last 7 days', number_format($caught), 'shield',
                        $caught > 0 ? 'green' : 'gray', 'Honeypot, too-fast and flood refusals') ?>
                    <?= admin_stat_card('Provider problems (7 days)', number_format($outages), 'alert',
                        $outages > 0 ? 'amber' : 'gray',
                        $outages > 0 ? 'Check the security event log' : ($ready ? 'None' : 'No provider configured')) ?>
                </div>

                <?php if (!$canEdit): ?>
                    <div class="sik-alert sik-alert--info">
                        <?= icon('info', 'w-5 h-5') ?>
                        <div>Read-only: bot protection is <strong><?= e($mode) ?></strong><?= $ready ? ', using ' . e($providers[$provider]['label']) : ', using the built-in checks only' ?>.</div>
                    </div>
                <?php else: ?>
                <form method="post" class="ad-form" style="display:grid;gap:14px">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="bot_save">

                    <div class="ad-field">
                        <span class="sik-label">When to challenge</span>
                        <div style="display:grid;gap:8px">
                            <?php foreach ([
                                'adaptive' => ['Adaptive (recommended)', ''],
                                'always'   => ['Always', ''],
                                'off'      => ['Off', 'No honeypot, no timing check, no rate ceiling.'],
                            ] as $value => [$label, $help]): ?>
                                <label class="sik-check" style="align-items:flex-start">
                                    <input type="radio" name="sec_bot_mode" value="<?= e_attr($value) ?>"
                                           <?= $mode === $value ? 'checked' : '' ?>>
                                    <span>
                                        <strong><?= e($label) ?></strong>
                                        <?php if ($help !== ''): ?>
                                            <span class="sik-help" style="display:block"><?= e($help) ?></span>
                                        <?php endif; ?>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <?php if (isset($errors['sec_bot_mode'])): ?>
                            <span class="sik-error"><?= e($errors['sec_bot_mode']) ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="ad-field">
                        <label class="sik-label" for="sec_bot_provider">CAPTCHA provider</label>
                        <select class="sik-select" id="sec_bot_provider" name="sec_bot_provider">
                            <option value="">None - use the built-in checks only</option>
                            <?php foreach ($providers as $key => $meta): ?>
                                <option value="<?= e_attr($key) ?>" <?= $provider === $key ? 'selected' : '' ?>>
                                    <?= e($meta['label']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <span class="sik-help">
                            <?php foreach ($providers as $key => $meta): ?>
                                <?= $key === $provider ? '<a href="' . e($meta['keys_at']) . '" target="_blank" rel="noopener">Get ' . e($meta['label']) . ' keys</a>' : '' ?>
                            <?php endforeach; ?>
                        </span>
                        <?php if (isset($errors['sec_bot_provider'])): ?>
                            <span class="sik-error"><?= e($errors['sec_bot_provider']) ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="ad-grid ad-grid--2" style="gap:12px">
                        <div class="ad-field">
                            <label class="sik-label" for="sec_bot_site_key">Site key (public)</label>
                            <input class="sik-input ad-mono<?= isset($errors['sec_bot_site_key']) ? ' is-invalid' : '' ?>"
                                   type="text" id="sec_bot_site_key" name="sec_bot_site_key" maxlength="190"
                                   autocomplete="off" spellcheck="false" value="<?= e_attr($siteKey) ?>">
                            <?php if (isset($errors['sec_bot_site_key'])): ?>
                                <span class="sik-error"><?= e($errors['sec_bot_site_key']) ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="ad-field">
                            <label class="sik-label" for="sec_bot_secret">Secret key</label>
                            <input class="sik-input ad-mono<?= isset($errors['sec_bot_secret']) ? ' is-invalid' : '' ?>"
                                   type="password" id="sec_bot_secret" name="sec_bot_secret" maxlength="190"
                                   autocomplete="new-password" spellcheck="false" value=""
                                   placeholder="<?= $hasSecret ? 'Stored - leave blank to keep it' : 'Paste the secret key' ?>">
                            <?php if (isset($errors['sec_bot_secret'])): ?>
                                <span class="sik-error"><?= e($errors['sec_bot_secret']) ?></span>
                            <?php else: ?>
                                <span class="sik-help">Encrypted, and never shown again.</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <details>
                        <summary style="cursor:pointer;font-weight:600;font-size:13px">Fine tuning</summary>
                        <div class="ad-grid ad-grid--2" style="gap:12px;margin-top:10px">
                            <div class="ad-field">
                                <label class="sik-label" for="sec_bot_min_seconds">Minimum seconds on a form</label>
                                <input class="sik-input<?= isset($errors['sec_bot_min_seconds']) ? ' is-invalid' : '' ?>"
                                       type="number" id="sec_bot_min_seconds" name="sec_bot_min_seconds"
                                       min="0" max="60" value="<?= e_attr((string) $minSeconds) ?>">
                                <span class="sik-help">0 switches the timing check off. 3 is comfortable.</span>
                            </div>
                            <div class="ad-field">
                                <label class="sik-label" for="sec_bot_failures">Failures before a challenge</label>
                                <input class="sik-input<?= isset($errors['sec_bot_failures']) ? ' is-invalid' : '' ?>"
                                       type="number" id="sec_bot_failures" name="sec_bot_failures"
                                       min="1" max="50" value="<?= e_attr((string) $failures) ?>">
                                <span class="sik-help">Counted per address, per form, over an hour.</span>
                            </div>
                            <div class="ad-field">
                                <label class="sik-label" for="sec_bot_burst">Submissions per 10 minutes</label>
                                <input class="sik-input<?= isset($errors['sec_bot_burst']) ? ' is-invalid' : '' ?>"
                                       type="number" id="sec_bot_burst" name="sec_bot_burst"
                                       min="0" max="500" value="<?= e_attr((string) $burst) ?>">
                                <span class="sik-help">0 keeps the sensible per-form defaults (8-20).</span>
                            </div>
                            <div class="ad-field">
                                <label class="sik-label" for="sec_bot_timeout">Provider timeout (seconds)</label>
                                <input class="sik-input<?= isset($errors['sec_bot_timeout']) ? ' is-invalid' : '' ?>"
                                       type="number" id="sec_bot_timeout" name="sec_bot_timeout"
                                       min="1" max="10" value="<?= e_attr((string) $timeout) ?>">
                            </div>
                            <div class="ad-field">
                                <label class="sik-label" for="sec_bot_score">reCAPTCHA v3 score floor</label>
                                <input class="sik-input<?= isset($errors['sec_bot_score']) ? ' is-invalid' : '' ?>"
                                       type="number" id="sec_bot_score" name="sec_bot_score"
                                       min="0" max="1" step="0.1" value="<?= e_attr($score) ?>">
                                <span class="sik-help">Only used by reCAPTCHA. 0.5 is Google's own default.</span>
                            </div>
                        </div>

                        <label class="sik-check" style="margin-top:10px;align-items:flex-start">
                            <input type="checkbox" name="sec_bot_fail_open" value="1" <?= bot_fail_open() ? 'checked' : '' ?>>
                            <span>
                                <strong>Let customers through when the provider is down</strong>
                                <span class="sik-help" style="display:block">
                                    Recommended. A store that cannot take a sign-up because a CAPTCHA vendor is
                                    having an outage has lost more than it saved. Every outage is written to the
                                    security event log either way.
                                </span>
                            </span>
                        </label>
                    </details>

                    <div style="display:flex;gap:8px;flex-wrap:wrap">
                        <button type="submit" class="ad-btn ad-btn--primary"><?= icon('check', 'w-4 h-4') ?> Save</button>
                    </div>
                </form>

                <?php if ($ready): ?>
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="bot_test">
                        <button type="submit" class="ad-btn ad-btn--sm">
                            <?= icon('refresh', 'w-4 h-4') ?> Test the connection
                        </button>
                    </form>
                <?php endif; ?>
                <?php endif; ?>

                <details style="font-size:13px">
                    <summary style="cursor:pointer;font-weight:600">What runs with no provider configured</summary>
                    <div style="display:grid;gap:8px;margin-top:8px">
                        <p>Protected forms are sign-up, sign-in, forgot-password, contact, the newsletter,
                           reviews and coupon codes. Three checks guard them with no vendor, no keys and no
                           JavaScript:</p>
                        <ul style="margin:0;padding-left:18px;display:grid;gap:4px">
                            <li><strong>A honeypot field</strong> - hidden from people and from screen readers. Bots fill it in.</li>
                            <li><strong>A minimum time on the form</strong> - signed, so the timestamp cannot be back-dated.</li>
                            <li><strong>A per-address, per-form ceiling</strong> in the shared rate-limit table, which survives
                                a dropped cookie and a fresh session.</li>
                        </ul>
                        <p>Configuring a provider adds a real challenge on top for the clients those three distrust.
                           Without one, a distrusted client is asked to slow down instead.</p>
                        <p><strong>Adaptive</strong> challenges only after repeated failures from one address,
                           or above a submission rate no person reaches, so an ordinary customer never sees a
                           puzzle. <strong>Always</strong> is only worth it while you are actually being hit.
                           <strong>Off</strong> leaves the endpoint rate limits, and nothing else.</p>
                        <p>The provider keys are yours to create; we cannot make them for you.</p>
                    </div>
                </details>
            </div>
        </div>
        <?php
    },
];
