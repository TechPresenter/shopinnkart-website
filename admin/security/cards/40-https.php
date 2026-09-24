<?php
/**
 * Security card: HTTPS and HSTS.
 *
 * Returned to admin/security/settings.php, which renders the card and routes
 * this card's POST actions to the handlers below. See that file for the shape.
 *
 * This replaces the commented-out RewriteRule that used to sit at the top of
 * .htaccess with a note in HOSTING.md telling the operator to uncomment it.
 * Nobody ever did, so a store could - and on this project did - run logins and
 * checkout over plain HTTP.
 */

declare(strict_types=1);

return [
    'key'    => 'https',
    'order'  => 40,
    'column' => 'main',

    'actions' => [
        'https_save' => static function (string $back): void {
            $mode = (string) input('sec_force_https', 'auto');
            if (!in_array($mode, ['auto', '1', '0'], true)) {
                $mode = 'auto';
            }

            $maxAge = (int) input('sec_hsts_max_age', 300);
            // 0 switches HSTS off; anything else is clamped to a year. Two
            // years of "must use HTTPS" cached in every returning customer's
            // browser is not a setting to fat-finger.
            $maxAge = $maxAge <= 0 ? 0 : max(60, min(31536000, $maxAge));

            $subdomains = input('sec_hsts_subdomains', '0') === '1' ? '1' : '0';

            setting_save('sec_force_https', $mode, 'security', 'text');
            setting_save('sec_hsts_max_age', (string) $maxAge, 'security', 'number');
            setting_save('sec_hsts_subdomains', $subdomains, 'security', 'boolean');
            admin_after_write();

            security_event('platform.https_settings_changed', 'medium', [
                'mode' => $mode, 'hsts_max_age' => $maxAge, 'hsts_subdomains' => $subdomains,
            ], admin_id(), 'admin');
            log_activity('security.https', 'settings', null, 'Changed the HTTPS/HSTS settings');

            flash('success', 'Saved.');
            redirect($back);
        },
    ],

    'render' => static function (array $errors, bool $canEdit): void {
        $mode       = (string) setting('sec_force_https', 'auto');
        $maxAge     = (int) setting('sec_hsts_max_age', 300);
        $subdomains = (string) setting('sec_hsts_subdomains', '0') === '1';
        $isHttps    = security_request_is_https();
        $host       = (string) preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? ''));
        $isLocal    = security_host_is_local($host);
        ?>
        <div class="ad-card" style="margin:0" id="https">
            <div class="ad-card__head">
                <div>
                    <h2 class="ad-card__title">HTTPS and HSTS</h2>
                </div>
                <?= $isHttps
                    ? '<span class="sik-status sik-status--green">This page is on HTTPS</span>'
                    : ($isLocal
                        ? '<span class="sik-status sik-status--gray">Local - no certificate</span>'
                        : '<span class="sik-status sik-status--red">This page is on plain HTTP</span>') ?>
            </div>

            <div class="ad-card__body" style="display:grid;gap:14px">
                <?php if (!$isHttps && !$isLocal): ?>
                    <div class="sik-alert sik-alert--warning">
                        <?= icon('alert-triangle', 'w-5 h-5') ?>
                        <div>
                            <strong>You are reading this over plain HTTP</strong> on <code><?= e($host) ?></code>.
                            Install a certificate first, then set <strong>Always redirect</strong> below.
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($canEdit): ?>
                    <form method="post" class="ad-form" style="display:grid;gap:14px">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="https_save">

                        <div class="ad-field">
                            <label class="sik-label" for="sec_force_https">Plain HTTP requests</label>
                            <select class="sik-select" id="sec_force_https" name="sec_force_https">
                                <option value="auto" <?= $mode === 'auto' ? 'selected' : '' ?>>
                                    Automatic - never redirect, but treat HTTPS as the real address once it works
                                </option>
                                <option value="1" <?= $mode === '1' ? 'selected' : '' ?>>
                                    Always redirect to HTTPS (recommended once your certificate is live)
                                </option>
                                <option value="0" <?= $mode === '0' ? 'selected' : '' ?>>
                                    Off - do not redirect and never send HSTS
                                </option>
                            </select>
                            <span class="sik-help">Localhost and private LAN are always exempt.</span>
                        </div>

                        <div class="ad-field">
                            <label class="sik-label" for="sec_hsts_max_age">
                                Remember "HTTPS only" for (seconds)
                            </label>
                            <input class="sik-input" type="number" min="0" max="31536000" step="60"
                                   id="sec_hsts_max_age" name="sec_hsts_max_age" value="<?= e_attr((string) $maxAge) ?>">
                            <span class="sik-help">0 is off. Start at 300, then 31536000.</span>
                        </div>

                        <label class="ad-check" style="display:flex;gap:9px;align-items:flex-start">
                            <input type="checkbox" name="sec_hsts_subdomains" value="1" <?= $subdomains ? 'checked' : '' ?>>
                            <span>
                                <strong>Apply to every subdomain</strong><br>
                                <span class="ad-muted" style="font-size:13px">
                                    Only once <em>every</em> subdomain is on HTTPS. Otherwise they become unreachable.
                                </span>
                            </span>
                        </label>

                        <div>
                            <button type="submit" class="ad-btn ad-btn--primary">
                                <?= icon('lock', 'w-4 h-4') ?> Save
                            </button>
                        </div>
                    </form>
                <?php else: ?>
                    <div class="ad-muted" style="font-size:13px">
                        Mode: <strong><?= e($mode === '1' ? 'Always redirect' : ($mode === '0' ? 'Off' : 'Automatic')) ?></strong>.
                        HSTS: <strong><?= $maxAge > 0 ? e((string) $maxAge) . 's' : 'off' ?></strong>.
                    </div>
                <?php endif; ?>

                <details style="font-size:13px">
                    <summary style="cursor:pointer;font-weight:600">What HSTS locks in</summary>
                    <div style="display:grid;gap:8px;margin-top:8px">
                        <p>Passwords, card details and session cookies must never travel in the clear, and
                           HTTPS is what stops them.</p>
                        <p>The seconds above are how long a browser remembers that this site is HTTPS only.
                           A browser that has been told cannot be told otherwise until the time runs out
                           &mdash; which is the point, and the reason to start at 300 rather than a year.</p>
                        <p>Raise it to 31536000 once the site has run happily on HTTPS for a day or two.
                           Certificates are your host&rsquo;s to issue; Hostinger gives one free.</p>
                        <p>&ldquo;Apply to every subdomain&rdquo; covers mail, cpanel, webmail and staging too.
                           Any of them still on plain HTTP becomes unreachable for the whole duration.</p>
                    </div>
                </details>
            </div>
        </div>
        <?php
    },
];
