<?php
/**
 * Security card: suspicious-activity detection.
 *
 * The thresholds that decide when the security log stops being a log and
 * becomes an email. Every default is set ABOVE what this store's own limits
 * already allow, on purpose - see security_monitor_rules() for the reasoning
 * behind each number, which is also printed on this card so the owner can
 * argue with it rather than guess at it.
 *
 * Returned to admin/security/settings.php, which renders the card and routes
 * this card's POST actions to the handlers below. See that file for the shape.
 */

declare(strict_types=1);

return [
    'key'    => 'monitor',
    'order'  => 82,
    'column' => 'main',

    'actions' => [
        'monitor_save' => static function (string $back): void {
            // key => [floor, ceiling]. The floors are not decoration: a
            // threshold of 1 on failed sign-ins would email the owner every
            // time a customer fat-fingers a password, and an alarm that cries
            // wolf is an alarm nobody reads.
            $fields = [
                'sec_alert_login_ip'      => [5, 5000],
                'sec_alert_login_account' => [3, 1000],
                'sec_alert_lockouts'      => [3, 5000],
                'sec_alert_csrf'          => [3, 1000],
                'sec_alert_rbac'          => [2, 500],
                'sec_alert_webhook'       => [1, 500],
                'sec_alert_mfa'           => [3, 500],
                'sec_alert_token_reuse'   => [1, 100],
                'sec_alert_probe'         => [1, 1000],
                'sec_alert_404'           => [10, 10000],
            ];

            $errors = [];
            $values = [];
            foreach ($fields as $key => [$low, $high]) {
                $value = (int) input($key, 0);
                if ($value < $low || $value > $high) {
                    $errors[$key] = 'Enter a number between ' . $low . ' and ' . $high . '.';
                    continue;
                }
                $values[$key] = $value;
            }

            $cooldown = (int) input('sec_monitor_cooldown', 3600);
            if ($cooldown < 300 || $cooldown > 86400) {
                $errors['sec_monitor_cooldown'] = 'Enter a number of seconds between 300 and 86400.';
            }

            $retention = (int) input('sec_events_retention_days', 90);
            if ($retention < 0 || $retention > 3650) {
                $errors['sec_events_retention_days'] = 'Enter a number of days between 0 and 3650.';
            }

            if ($errors !== []) {
                flash_errors($errors);
                flash_old($_POST);
                flash('error', 'The monitor settings were not changed.');
                redirect($back);
            }

            foreach ($values as $key => $value) {
                setting_save($key, (string) $value, 'security', 'number');
            }
            setting_save('sec_monitor_cooldown', (string) $cooldown, 'security', 'number');
            setting_save('sec_events_retention_days', (string) $retention, 'security', 'number');
            setting_save('sec_monitor_enabled', input_bool('sec_monitor_enabled') ? '1' : '0', 'security', 'boolean');
            setting_save('sec_monitor_email', input_bool('sec_monitor_email') ? '1' : '0', 'security', 'boolean');
            admin_after_write();

            log_activity('security.monitor', 'settings', null, 'Changed the suspicious-activity thresholds');
            security_event('settings.monitor', 'medium', $values + [
                'enabled'   => input_bool('sec_monitor_enabled'),
                'email'     => input_bool('sec_monitor_email'),
                'cooldown'  => $cooldown,
                'retention' => $retention,
            ], admin_id(), 'admin');

            flash('success', 'Saved.');
            redirect($back);
        },

        'monitor_test' => static function (string $back): void {
            // Proves the whole chain end to end - detectors, alert row, event,
            // queued email - without waiting for an attack. The mail is queued
            // like any other, so a broken SMTP setup shows up here rather than
            // on the night it matters.
            $sweep = security_monitor_sweep();

            flash(count($sweep['trips']) > 0 ? 'error' : 'success',
                $sweep['skipped'] !== ''
                    ? $sweep['skipped']
                    : ($sweep['trips'] === []
                        ? 'All ' . $sweep['checked'] . ' detectors ran against the last hour. Nothing crossed a threshold.'
                        : count($sweep['trips']) . ' detector(s) tripped - they are on the security log now.'));
            redirect($back);
        },
    ],

    'render' => static function (array $errors, bool $canEdit): void {
        $enabled   = setting_bool('sec_monitor_enabled', true);
        $emailing  = setting_bool('sec_monitor_email', true);
        $lastRun   = (string) setting('sec_monitor_last_run', '');
        $overdue   = security_monitor_overdue();
        $retention = setting_int('sec_events_retention_days', 90);

        $alerts24 = 0;
        $events24 = 0;
        try {
            $alerts24 = (int) Database::fetchColumn(
                'SELECT COUNT(*) FROM `security_alerts` WHERE `created_at` >= (NOW() - INTERVAL 24 HOUR)'
            );
            $events24 = (int) Database::fetchColumn(
                'SELECT COUNT(*) FROM `security_events` WHERE `created_at` >= (NOW() - INTERVAL 24 HOUR)'
            );
        } catch (Throwable $e) {
            // The tables belong to this card's own migration; never fail here.
        }

        $recipients = trim((string) setting('admin_notify_email', (string) setting('store_email', '')));

        $field = static function (string $key, string $label, int $default, string $help, array $errors): void {
            ?>
            <div class="ad-field">
                <label class="sik-label" for="<?= e_attr($key) ?>"><?= e($label) ?></label>
                <input class="sik-input<?= isset($errors[$key]) ? ' is-invalid' : '' ?>"
                       type="number" id="<?= e_attr($key) ?>" name="<?= e_attr($key) ?>" min="0"
                       value="<?= e_attr((string) (old($key, null) ?? setting_int($key, $default))) ?>">
                <?php if (isset($errors[$key])): ?>
                    <span class="sik-error"><?= e($errors[$key]) ?></span>
                <?php else: ?>
                    <span class="sik-help"><?= e($help) ?></span>
                <?php endif; ?>
            </div>
            <?php
        };
        ?>
        <div class="ad-card" style="margin:0" id="monitor">
            <div class="ad-card__head">
                <div>
                    <h2 class="ad-card__title">Suspicious activity</h2>
                    <div class="ad-card__sub">
                        When the security log stops being a log and becomes an email.
                    </div>
                </div>
                <?= $enabled
                    ? '<span class="sik-status sik-status--green">Watching</span>'
                    : '<span class="sik-status sik-status--gray">Off</span>' ?>
            </div>

            <div class="ad-card__body" style="display:grid;gap:14px">
                <div style="display:flex;gap:20px;flex-wrap:wrap">
                    <div>
                        <div class="ad-muted" style="font-size:12px">Events (24h)</div>
                        <div style="font-size:22px;font-weight:700"><?= number_format($events24) ?></div>
                    </div>
                    <div>
                        <div class="ad-muted" style="font-size:12px">Alerts raised (24h)</div>
                        <div style="font-size:22px;font-weight:700"><?= number_format($alerts24) ?></div>
                    </div>
                    <div>
                        <div class="ad-muted" style="font-size:12px">Last sweep</div>
                        <div style="font-size:16px;font-weight:600"><?= e($lastRun === '' ? 'never' : time_ago($lastRun)) ?></div>
                    </div>
                </div>

                <?php if ($enabled && $overdue): ?>
                    <div class="sik-alert sik-alert--warning">
                        <?= icon('clock', 'w-5 h-5') ?>
                        <div>
                            <strong>Nothing is sweeping on a schedule.</strong>
                            Opening the security log runs the detectors as a fallback, but that only helps while
                            somebody is looking. Add this to cron:
                            <code class="ad-mono" style="display:block;margin-top:6px;word-break:break-all">*/5 * * * * <?= e(PHP_BINARY) ?> <?= e(ROOT_PATH) ?>/bin/security-monitor.php --quiet</code>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($emailing && $recipients === ''): ?>
                    <div class="sik-alert sik-alert--warning">
                        <?= icon('mail', 'w-5 h-5') ?>
                        <div>
                            Alerts are set to email, but no notification address is configured. Set one in
                            <a href="<?= e(admin_url('settings/general.php')) ?>">Settings &rsaquo; General</a>,
                            or nothing will be sent.
                        </div>
                    </div>
                <?php endif; ?>

                <a class="ad-btn ad-btn--sm" href="<?= e(admin_url('security/events.php')) ?>">
                    <?= icon('list', 'w-4 h-4') ?> Open the security log
                </a>

                <?php if ($canEdit): ?>
                    <form method="post" class="ad-form" style="display:grid;gap:14px">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="monitor_save">

                        <label class="sik-check" style="align-items:flex-start">
                            <input type="checkbox" name="sec_monitor_enabled" value="1" <?= $enabled ? 'checked' : '' ?>>
                            <span>
                                <strong>Watch for suspicious activity</strong>
                                <span class="sik-help" style="display:block">
                                    Reads the events the app already writes. It never blocks anybody by itself -
                                    that is what the IP rules are for.
                                </span>
                            </span>
                        </label>

                        <label class="sik-check" style="align-items:flex-start">
                            <input type="checkbox" name="sec_monitor_email" value="1" <?= $emailing ? 'checked' : '' ?>>
                            <span>
                                <strong>Email me when something trips</strong>
                                <span class="sik-help" style="display:block">
                                    To the store notification address<?= $recipients === '' ? '' : ' (' . e($recipients) . ')' ?>.
                                    One email per subject per cooldown window, never one per event.
                                </span>
                            </span>
                        </label>

                        <div class="ad-grid ad-grid--2" style="gap:12px">
                            <?php $field('sec_alert_login_ip', 'Failed sign-ins from one address / 15 min', 30,
                                'The app already refuses this address at 20. Passing 30 means it was refused and kept going.', $errors); ?>
                            <?php $field('sec_alert_login_account', 'Failed sign-ins on one account / 15 min', 12,
                                'The per-account ceiling is 5. Reaching 12 means the guessing is spread across addresses.', $errors); ?>
                            <?php $field('sec_alert_lockouts', 'Throttle refusals store-wide / 15 min', 10,
                                'Either a spray across many accounts, or limits set too tight for a shared office.', $errors); ?>
                            <?php $field('sec_alert_csrf', 'CSRF failures from one address / 15 min', 15,
                                'One stale tab is one failure. Fifteen is a script posting to forms it never loaded.', $errors); ?>
                            <?php $field('sec_alert_rbac', 'Permission denials for one admin / hour', 5,
                                'The menu hides what a role cannot open, so an honest admin cannot reach this by clicking.', $errors); ?>
                            <?php $field('sec_alert_webhook', 'Payment callbacks with a bad signature / hour', 3,
                                'A correctly configured gateway never sends one. Any of these needs looking at today.', $errors); ?>
                            <?php $field('sec_alert_mfa', 'Two-step failures on one account / 15 min', 8,
                                'Whoever is doing this already got past the password.', $errors); ?>
                            <?php $field('sec_alert_token_reuse', 'Revoked tokens presented again / hour', 1,
                                'One is already too many: a token that was taken away has come back.', $errors); ?>
                            <?php $field('sec_alert_probe', 'Installer / control-panel probes from one address / hour', 5,
                                'Background noise on any public site. Only worth an alert when it keeps up.', $errors); ?>
                            <?php $field('sec_alert_404', 'Missing pages asked for by one address / 15 min', 40,
                                'A real shopper hits a handful from old bookmarks. Forty is a directory scanner.', $errors); ?>
                        </div>

                        <div class="ad-grid ad-grid--2" style="gap:12px">
                            <div class="ad-field">
                                <label class="sik-label" for="sec_monitor_cooldown">Cooldown between repeats (seconds)</label>
                                <input class="sik-input<?= isset($errors['sec_monitor_cooldown']) ? ' is-invalid' : '' ?>"
                                       type="number" id="sec_monitor_cooldown" name="sec_monitor_cooldown"
                                       min="300" max="86400"
                                       value="<?= e_attr((string) (old('sec_monitor_cooldown', null) ?? setting_int('sec_monitor_cooldown', 3600))) ?>">
                                <?php if (isset($errors['sec_monitor_cooldown'])): ?>
                                    <span class="sik-error"><?= e($errors['sec_monitor_cooldown']) ?></span>
                                <?php else: ?>
                                    <span class="sik-help">
                                        An attack that lasts two hours is two alerts, not one every five minutes.
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div class="ad-field">
                                <label class="sik-label" for="sec_events_retention_days">Keep the security log for (days)</label>
                                <input class="sik-input<?= isset($errors['sec_events_retention_days']) ? ' is-invalid' : '' ?>"
                                       type="number" id="sec_events_retention_days" name="sec_events_retention_days"
                                       min="0" max="3650"
                                       value="<?= e_attr((string) (old('sec_events_retention_days', null) ?? $retention)) ?>">
                                <?php if (isset($errors['sec_events_retention_days'])): ?>
                                    <span class="sik-error"><?= e($errors['sec_events_retention_days']) ?></span>
                                <?php else: ?>
                                    <span class="sik-help">
                                        0 keeps everything. The cron sweep prunes past this, in slices, so it never
                                        holds the table the whole site writes to.
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div style="display:flex;gap:10px;flex-wrap:wrap">
                            <button type="submit" class="ad-btn ad-btn--primary"><?= icon('check', 'w-4 h-4') ?> Save</button>
                        </div>
                    </form>

                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="monitor_test">
                        <button type="submit" class="ad-btn ad-btn--sm">
                            <?= icon('activity', 'w-4 h-4') ?> Run the detectors now
                        </button>
                    </form>
                <?php endif; ?>

                <details style="font-size:13px">
                    <summary style="cursor:pointer;font-weight:600">What is being watched, and why these numbers</summary>
                    <div style="display:grid;gap:10px;margin-top:10px">
                        <p class="ad-muted">
                            These are not guesses at an industry norm. Each one sits above what this store's own
                            limits already permit, so crossing it means somebody was refused and carried on - which
                            no customer does.
                        </p>
                        <?php foreach (security_monitor_rules() as $rule): ?>
                            <div>
                                <strong><?= e((string) $rule['title']) ?></strong>
                                <span class="ad-muted">
                                    - <?= (int) $rule['threshold'] ?> in
                                    <?= e(security_monitor_window_label((int) $rule['window'])) ?>,
                                    per <?= e((string) $rule['scope'] === 'global' ? 'store' : $rule['scope']) ?>
                                </span>
                                <div class="ad-muted" style="font-size:12px"><?= e((string) $rule['advice']) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </details>
            </div>
        </div>
        <?php
    },
];
