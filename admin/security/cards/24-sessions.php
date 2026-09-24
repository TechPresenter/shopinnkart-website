<?php
/**
 * Security card: how long a signed-in session lasts.
 *
 * Sessions used to be a seven-day cookie for everybody, admins included, with
 * no idle timeout and no limit at all - closing the browser on a shared
 * machine left a live admin session behind for a week.
 */

declare(strict_types=1);

return [
    'key'    => 'sessions',
    'order'  => 24,
    'column' => 'side',

    'actions' => [
        'sessions_save' => static function (string $back): void {
            $fields = [
                'sec_session_idle_admin'        => [5, 1440],
                'sec_session_absolute_admin'    => [1, 168],
                'sec_session_idle_customer'     => [15, 43200],
                'sec_session_absolute_customer' => [1, 365],
                'sec_remember_days'             => [1, 365],
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

            if ($errors !== []) {
                flash_errors($errors);
                flash_old($_POST);
                flash('error', 'The session limits were not changed.');
                redirect($back);
            }

            foreach ($values as $key => $value) {
                setting_save($key, (string) $value, 'security', 'number');
            }
            setting_save('sec_remember_enabled', input_bool('sec_remember_enabled') ? '1' : '0', 'security', 'boolean');
            admin_after_write();

            log_activity('security.sessions', 'settings', null, 'Changed the session timeouts');
            security_event('settings.sessions', 'medium', $values, admin_id(), 'admin');

            flash('success', 'Saved. The new limits apply from the next sign-in.');
            redirect($back);
        },
    ],

    'render' => static function (array $errors, bool $canEdit): void {
        [$adminIdle, $adminAbsolute]       = auth_session_timeouts('admin');
        [$customerIdle, $customerAbsolute] = auth_session_timeouts('customer');

        $remembered = 0;
        try {
            $remembered = (int) Database::fetchColumn('SELECT COUNT(*) FROM `remember_tokens` WHERE `expires_at` > NOW()');
        } catch (Throwable $e) {
            // Table missing = the migration has not run; nothing to report.
        }

        $field = static function (string $key, string $label, int $value, string $unit, array $errors): void {
            ?>
            <div class="ad-field">
                <label class="sik-label" for="<?= e($key) ?>"><?= e($label) ?></label>
                <div style="display:flex;align-items:center;gap:8px">
                    <input class="sik-input<?= isset($errors[$key]) ? ' is-invalid' : '' ?>" style="max-width:120px"
                           id="<?= e($key) ?>" name="<?= e($key) ?>" type="number" min="1" step="1"
                           value="<?= e_attr((string) (old($key, null) ?? $value)) ?>">
                    <span class="ad-muted" style="font-size:13px"><?= e($unit) ?></span>
                </div>
                <?php if (isset($errors[$key])): ?>
                    <span class="sik-error"><?= e($errors[$key]) ?></span>
                <?php endif; ?>
            </div>
            <?php
        };
        ?>
        <div class="ad-card" style="margin:0" id="sessions">
            <div class="ad-card__head">
                <div>
                    <h2 class="ad-card__title">Session length</h2>
                    <div class="ad-card__sub">Cookies end with the browser. These are the limits on top.</div>
                </div>
            </div>

            <div class="ad-card__body" style="display:grid;gap:14px">
                <?php // Sessions that were already open when this shipped carry no
                      // generation stamp. They are adopted once and then checked
                      // like any other, so switching it on signed nobody out: an
                      // adopted session still ends when it idles out, expires or
                      // the account's password changes. That was worth saying on
                      // screen the week it deployed and is only noise now, so it
                      // lives here instead. ?>
                <?php if ($canEdit): ?>
                    <form method="post" class="ad-form" style="display:grid;gap:12px">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="sessions_save">

                        <h3 style="font-size:13px;font-weight:700;margin:0">Admins</h3>
                        <?php $field('sec_session_idle_admin', 'Signed out after doing nothing for', (int) ($adminIdle / 60), 'minutes', $errors); ?>
                        <?php $field('sec_session_absolute_admin', 'And after at most', (int) ($adminAbsolute / 3600), 'hours', $errors); ?>
                        <p class="sik-help" style="margin:0">Admins never get "keep me signed in".</p>

                        <h3 style="font-size:13px;font-weight:700;margin:10px 0 0">Customers</h3>
                        <?php $field('sec_session_idle_customer', 'Signed out after doing nothing for', (int) ($customerIdle / 60), 'minutes', $errors); ?>
                        <?php $field('sec_session_absolute_customer', 'And after at most', (int) ($customerAbsolute / 86400), 'days', $errors); ?>

                        <label class="sik-check" style="margin-top:6px">
                            <input type="checkbox" name="sec_remember_enabled" value="1"<?= setting_bool('sec_remember_enabled', true) ? ' checked' : '' ?>>
                            <span>Offer "keep me signed in" on the storefront</span>
                        </label>
                        <?php $field('sec_remember_days', 'A remembered device lasts', auth_remember_days(), 'days', $errors); ?>
                        <p class="sik-help" style="margin:0">
                            <?= (int) $remembered ?> remembered. A password change forgets a customer's devices.
                        </p>

                        <div>
                            <button type="submit" class="ad-btn ad-btn--primary">
                                <?= icon('check', 'w-4 h-4') ?> Save session limits
                            </button>
                        </div>
                    </form>
                <?php else: ?>
                    <p class="ad-muted" style="font-size:13px">
                        Admin: idle <?= (int) ($adminIdle / 60) ?> min, at most <?= (int) ($adminAbsolute / 3600) ?> h.
                        Customer: idle <?= (int) ($customerIdle / 60) ?> min, at most <?= (int) ($customerAbsolute / 86400) ?> days.
                    </p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    },
];
