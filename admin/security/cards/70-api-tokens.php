<?php
/**
 * Security card: per-device API tokens for a mobile app.
 *
 * Returned to admin/security/settings.php, which renders the card and routes
 * this card's POST actions to the handlers below. See that file for the shape.
 */

declare(strict_types=1);

return [
    'key'    => 'api-tokens',
    'order'  => 70,
    'column' => 'side',

    'actions' => [
        'api_tokens_save' => static function (string $back): void {
            $errors = [];
            $days   = (int) input('sec_api_token_days', 30);
            $max    = (int) input('sec_api_token_max', 10);

            if ($days < 1 || $days > 365) {
                $errors['sec_api_token_days'] = 'Enter a number of days between 1 and 365.';
            }
            if ($max < 1 || $max > 100) {
                $errors['sec_api_token_max'] = 'Enter a number between 1 and 100.';
            }

            if ($errors !== []) {
                flash_errors($errors);
                flash_old($_POST);
                flash('error', 'The app sign-in settings were not changed.');
                redirect($back);
            }

            $adminWas = api_tokens_admin_enabled();
            $adminNow = input_bool('sec_api_tokens_admin');

            setting_save('sec_api_tokens_enabled', input_bool('sec_api_tokens_enabled') ? '1' : '0', 'security', 'boolean');
            setting_save('sec_api_tokens_admin', $adminNow ? '1' : '0', 'security', 'boolean');
            setting_save('sec_api_token_days', (string) $days, 'security', 'number');
            setting_save('sec_api_token_max', (string) $max, 'security', 'number');
            admin_after_write();

            log_activity('security.api_tokens', 'settings', null, 'Changed the app sign-in settings');
            security_event('settings.api_tokens', $adminNow && !$adminWas ? 'high' : 'medium', [
                'enabled'       => input_bool('sec_api_tokens_enabled'),
                'admin_tokens'  => $adminNow,
                'days'          => $days,
                'max_per_user'  => $max,
            ], admin_id(), 'admin');

            flash('success', 'Saved.');
            redirect($back);
        },

        'api_tokens_revoke_all' => static function (string $back): void {
            $count = 0;
            foreach (['customer', 'admin'] as $type) {
                $count += (int) Database::update('api_tokens', [
                    'revoked_at'     => date('Y-m-d H:i:s'),
                    'revoked_reason' => 'admin_revoked_all',
                ], '`user_type` = :t AND `revoked_at` IS NULL', ['t' => $type]);
            }

            log_activity('security.api_tokens', 'settings', null, 'Revoked every API token (' . $count . ')');
            security_event('api.tokens_revoked_everything', 'high', ['count' => $count], admin_id(), 'admin');

            flash($count > 0 ? 'success' : 'info', $count > 0
                ? $count . ' app token' . ($count === 1 ? '' : 's') . ' revoked. Every device has to sign in again.'
                : 'There were no active app tokens to revoke.');
            redirect($back);
        },
    ],

    'render' => static function (array $errors, bool $canEdit): void {
        $enabled  = api_tokens_enabled();
        $adminOn  = api_tokens_admin_enabled();
        $days     = api_token_lifetime_days();
        $max      = api_token_max_per_account();

        $active = (int) Database::fetchColumn(
            'SELECT COUNT(*) FROM `api_tokens` WHERE `revoked_at` IS NULL AND `expires_at` > NOW()'
        );
        $usedWeek = (int) Database::fetchColumn(
            'SELECT COUNT(*) FROM `api_tokens` WHERE `last_used_at` >= DATE_SUB(NOW(), INTERVAL 7 DAY)'
        );
        ?>
        <div class="ad-card" style="margin:0" id="api-tokens">
            <div class="ad-card__head">
                <div>
                    <h2 class="ad-card__title">App sign-in (API tokens)</h2>
                    <div class="ad-card__sub">Website sessions are untouched by anything here.</div>
                </div>
                <?= $enabled
                    ? '<span class="sik-status sik-status--green">On</span>'
                    : '<span class="sik-status sik-status--gray">Off</span>' ?>
            </div>

            <div class="ad-card__body" style="display:grid;gap:14px">
                <div class="ad-stackbar" style="display:flex;gap:16px;flex-wrap:wrap">
                    <div>
                        <div class="ad-muted" style="font-size:12px">Active devices</div>
                        <div style="font-size:22px;font-weight:700"><?= number_format($active) ?></div>
                    </div>
                    <div>
                        <div class="ad-muted" style="font-size:12px">Used this week</div>
                        <div style="font-size:22px;font-weight:700"><?= number_format($usedWeek) ?></div>
                    </div>
                </div>

                <a class="ad-btn ad-btn--sm" href="<?= e(admin_url('security/devices.php')) ?>">
                    <?= icon('list', 'w-4 h-4') ?> Devices &amp; tokens
                </a>

                <?php if ($adminOn): ?>
                    <div class="sik-alert sik-alert--warning">
                        <?= icon('alert', 'w-5 h-5') ?>
                        <div>
                            <strong>Admin accounts may hold API tokens.</strong>
                            A stolen one can change the catalogue. Leave this off unless an admin app needs it.
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($canEdit): ?>
                    <form method="post" class="ad-form" style="display:grid;gap:12px">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="api_tokens_save">

                        <label class="sik-check" style="align-items:flex-start">
                            <input type="checkbox" name="sec_api_tokens_enabled" value="1" <?= $enabled ? 'checked' : '' ?>>
                            <span>
                                <strong>Allow app sign-in</strong>
                                <span class="sik-help" style="display:block">
                                    Off refuses every existing token at once.
                                </span>
                            </span>
                        </label>

                        <label class="sik-check" style="align-items:flex-start">
                            <input type="checkbox" name="sec_api_tokens_admin" value="1" <?= $adminOn ? 'checked' : '' ?>>
                            <span><strong>Let admin accounts hold tokens</strong></span>
                        </label>

                        <div class="ad-grid ad-grid--2" style="gap:12px">
                            <div class="ad-field">
                                <label class="sik-label" for="sec_api_token_days">Token lifetime (days)</label>
                                <input class="sik-input<?= isset($errors['sec_api_token_days']) ? ' is-invalid' : '' ?>"
                                       type="number" id="sec_api_token_days" name="sec_api_token_days"
                                       min="1" max="365" value="<?= e_attr((string) $days) ?>">
                                <?php if (isset($errors['sec_api_token_days'])): ?>
                                    <span class="sik-error"><?= e($errors['sec_api_token_days']) ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="ad-field">
                                <label class="sik-label" for="sec_api_token_max">Devices per account</label>
                                <input class="sik-input<?= isset($errors['sec_api_token_max']) ? ' is-invalid' : '' ?>"
                                       type="number" id="sec_api_token_max" name="sec_api_token_max"
                                       min="1" max="100" value="<?= e_attr((string) $max) ?>">
                                <?php if (isset($errors['sec_api_token_max'])): ?>
                                    <span class="sik-error"><?= e($errors['sec_api_token_max']) ?></span>
                                <?php else: ?>
                                    <span class="sik-help">The oldest is retired when full.</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <button type="submit" class="ad-btn ad-btn--primary"><?= icon('check', 'w-4 h-4') ?> Save</button>
                    </form>

                    <?php if ($active > 0): ?>
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="api_tokens_revoke_all">
                            <button type="submit" class="ad-btn ad-btn--danger ad-btn--sm"
                                    <?= admin_confirm_attrs('Every device has to sign in again.', ['title' => 'Revoke every app token?', 'label' => 'Revoke all tokens', 'tone' => 'danger']) ?>>
                                Revoke every app token
                            </button>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>

                <details style="font-size:13px">
                    <summary style="cursor:pointer;font-weight:600">How a device signs in</summary>
                    <div style="display:grid;gap:8px;margin-top:8px">
                        <p>The app posts the customer's email and password to
                           <code class="ad-mono">/api/auth/token.php</code> once per device and keeps the token it
                           gets back. Every later request carries
                           <code class="ad-mono">Authorization: Bearer &lt;token&gt;</code>.</p>
                        <p>Only a hash of each token is stored here, so this database cannot be read for a
                           credential. A password change, a reset or "sign out everywhere" retires every token on
                           that account, exactly as it ends its browser sessions.</p>
                        <p>An admin token opens only what that admin's role allows, exactly as their browser
                           session does.</p>
                        <p>There is no app in this build yet - this is the server half, ready for one.</p>
                    </div>
                </details>
            </div>
        </div>
        <?php
    },
];
