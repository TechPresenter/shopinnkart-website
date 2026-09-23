<?php
/**
 * Security card: sign-in throttles.
 *
 * What replaced the old account lockout. The numbers here are counted in the
 * shared `rate_limits` table, atomically, so parallel guessing cannot slip
 * past them the way it slipped past the counter on the user row.
 */

declare(strict_types=1);

return [
    'key'    => 'login-throttles',
    'order'  => 22,
    'column' => 'main',

    'actions' => [
        'throttles_save' => static function (string $back): void {
            $fields = [
                'sec_login_ip_max'      => [3, 500],
                'sec_login_ip_daily'    => [10, 5000],
                'sec_login_account_max' => [3, 100],
                'sec_login_global_max'  => [0, 100000],
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
                flash('error', 'The sign-in limits were not changed.');
                redirect($back);
            }

            foreach ($values as $key => $value) {
                setting_save($key, (string) $value, 'security', 'number');
            }
            admin_after_write();

            log_activity('security.login_throttles', 'settings', null, 'Changed the sign-in throttles');
            security_event('settings.login_throttles', 'medium', $values, admin_id(), 'admin');

            flash('success', 'Saved.');
            redirect($back);
        },

        'throttles_clear' => static function (string $back): void {
            // The way back in for a customer (or an admin) whose office shares
            // one address with fifty other people and has tripped the limit.
            try {
                Database::query("DELETE FROM `rate_limits` WHERE `bucket` LIKE 'login.%'");
            } catch (Throwable $e) {
                flash('error', 'Could not clear the counters: ' . $e->getMessage());
                redirect($back);
            }

            log_activity('security.login_throttles', 'settings', null, 'Cleared every sign-in throttle counter');
            security_event('settings.login_throttles_cleared', 'medium', [], admin_id(), 'admin');

            flash('success', 'Every sign-in counter has been cleared.');
            redirect($back);
        },
    ],

    'render' => static function (array $errors, bool $canEdit): void {
        $limits = auth_throttle_limits();

        $blocked = 0;
        $alerts  = 0;
        try {
            $blocked = (int) Database::fetchColumn(
                "SELECT COUNT(*) FROM `security_events`
                  WHERE `type` = 'auth.login_throttled' AND `created_at` > (NOW() - INTERVAL 24 HOUR)"
            );
            $alerts = (int) Database::fetchColumn(
                "SELECT COUNT(*) FROM `security_events`
                  WHERE `type` = 'auth.account_under_attack' AND `created_at` > (NOW() - INTERVAL 24 HOUR)"
            );
        } catch (Throwable $e) {
            // The events table is another card's business; never fail here.
        }

        $field = static function (string $key, string $label, int $value, string $help, array $errors): void {
            ?>
            <div class="ad-field">
                <label class="sik-label" for="<?= e($key) ?>"><?= e($label) ?></label>
                <input class="sik-input<?= isset($errors[$key]) ? ' is-invalid' : '' ?>" style="max-width:140px"
                       id="<?= e($key) ?>" name="<?= e($key) ?>" type="number" min="0" step="1"
                       value="<?= e_attr((string) (old($key, null) ?? $value)) ?>">
                <?php if (isset($errors[$key])): ?>
                    <span class="sik-error"><?= e($errors[$key]) ?></span>
                <?php else: ?>
                    <span class="sik-help"><?= $help ?></span>
                <?php endif; ?>
            </div>
            <?php
        };
        ?>
        <div class="ad-card" style="margin:0" id="login-throttles">
            <div class="ad-card__head">
                <div>
                    <h2 class="ad-card__title">Sign-in throttles</h2>
                    <div class="ad-card__sub">
                        Brute force is stopped at the guesser, not at the account. Accounts are never locked:
                        locking one was a way for anybody who knew an email address to keep its owner out.
                    </div>
                </div>
                <span class="sik-status sik-status--<?= $blocked > 0 ? 'amber' : 'green' ?>">
                    <?= (int) $blocked ?> blocked / 24h
                </span>
            </div>

            <div class="ad-card__body" style="display:grid;gap:14px">
                <?php if ($alerts > 0): ?>
                    <div class="sik-alert sik-alert--warning">
                        <?= icon('alert', 'w-5 h-5') ?>
                        <div><?= (int) $alerts ?> account(s) were guessed at in the last 24 hours. Their owners were emailed.</div>
                    </div>
                <?php endif; ?>

                <?php if ($canEdit): ?>
                    <form method="post" class="ad-form" style="display:grid;gap:12px">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="throttles_save">

                        <?php $field('sec_login_ip_max', 'Attempts per device, per 15 minutes', $limits['ip'],
                            'Refused past this. Counted whether the attempt succeeds or not and never cleared by signing in &mdash; otherwise one valid account of the guesser\'s own would reopen the budget on demand. It ages out with its own window, and the button below empties it.', $errors); ?>

                        <?php $field('sec_login_ip_daily', 'Attempts per device, per day', $limits['ip_day'],
                            'The ceiling on a patient attacker who spreads the guesses out.', $errors); ?>

                        <?php $field('sec_login_account_max', 'Attempts per device on one account, per 15 minutes', $limits['account'],
                            'Also the point at which the account\'s owner is emailed and strangers start waiting a moment per try. Devices that have signed in before are never delayed.', $errors); ?>

                        <?php $field('sec_login_global_max', 'Failures across the whole store, per 15 minutes', $limits['global'],
                            'Recorded in the event log only, never refused - refusing here would let one attacker stop every customer signing in. 0 switches the signal off.', $errors); ?>

                        <div style="display:flex;gap:8px;flex-wrap:wrap">
                            <button type="submit" class="ad-btn ad-btn--primary">
                                <?= icon('check', 'w-4 h-4') ?> Save limits
                            </button>
                        </div>
                    </form>

                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="throttles_clear">
                        <button type="submit" class="ad-btn"
                                data-confirm="Clear every sign-in counter? Anyone currently blocked gets a fresh start.">
                            Clear the counters now
                        </button>
                    </form>
                <?php else: ?>
                    <p class="ad-muted" style="font-size:13px">
                        <?= (int) $limits['ip'] ?> attempts per device per 15 minutes,
                        <?= (int) $limits['account'] ?> per account.
                    </p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    },
];
