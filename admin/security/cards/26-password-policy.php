<?php
/**
 * Security card: password policy.
 */

declare(strict_types=1);

return [
    'key'    => 'password-policy',
    'order'  => 26,
    'column' => 'side',

    'actions' => [
        'password_policy_save' => static function (string $back): void {
            $customer = (int) input('sec_password_min', 0);
            $admin    = (int) input('sec_password_min_admin', 0);

            $errors = [];
            if ($customer < PASSWORD_MIN_LENGTH || $customer > 64) {
                $errors['sec_password_min'] = 'Enter a number between ' . PASSWORD_MIN_LENGTH . ' and 64.';
            }
            if ($admin < PASSWORD_MIN_LENGTH || $admin > 64) {
                $errors['sec_password_min_admin'] = 'Enter a number between ' . PASSWORD_MIN_LENGTH . ' and 64.';
            }
            if ($errors === [] && $admin < $customer) {
                $errors['sec_password_min_admin'] = 'An admin password cannot be allowed to be shorter than a customer one.';
            }

            if ($errors !== []) {
                flash_errors($errors);
                flash_old($_POST);
                flash('error', 'The password policy was not changed.');
                redirect($back);
            }

            setting_save('sec_password_min', (string) $customer, 'security', 'number');
            setting_save('sec_password_min_admin', (string) $admin, 'security', 'number');
            admin_after_write();

            log_activity('security.password_policy', 'settings', null,
                'Set the minimum password length to ' . $customer . ' (admins ' . $admin . ')');
            security_event('settings.password_policy', 'medium',
                ['customer' => $customer, 'admin' => $admin], admin_id(), 'admin');

            flash('success', 'Saved. It applies the next time anybody sets a password.');
            redirect($back);
        },
    ],

    'render' => static function (array $errors, bool $canEdit): void {
        $algo    = password_app_algo();
        $blocked = count(password_blocklist());
        ?>
        <div class="ad-card" style="margin:0" id="password-policy">
            <div class="ad-card__head">
                <div>
                    <h2 class="ad-card__title">Password policy</h2>
                    <div class="ad-card__sub">Applies to new passwords only.</div>
                </div>
                <span class="sik-status sik-status--<?= $algo === PASSWORD_BCRYPT ? 'amber' : 'green' ?>">
                    <?= e($algo === PASSWORD_BCRYPT ? 'bcrypt' : 'argon2id') ?>
                </span>
            </div>

            <div class="ad-card__body" style="display:grid;gap:14px">
                <?php if ($canEdit): ?>
                    <form method="post" class="ad-form" style="display:grid;gap:12px">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="password_policy_save">

                        <div class="ad-field">
                            <label class="sik-label" for="sec_password_min">Shortest customer password</label>
                            <input class="sik-input<?= isset($errors['sec_password_min']) ? ' is-invalid' : '' ?>"
                                   style="max-width:120px" id="sec_password_min" name="sec_password_min"
                                   type="number" min="<?= PASSWORD_MIN_LENGTH ?>" max="64" step="1"
                                   value="<?= e_attr((string) (old('sec_password_min', null) ?? password_min_length('customer'))) ?>">
                            <?php if (isset($errors['sec_password_min'])): ?>
                                <span class="sik-error"><?= e($errors['sec_password_min']) ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="ad-field">
                            <label class="sik-label" for="sec_password_min_admin">Shortest admin password</label>
                            <input class="sik-input<?= isset($errors['sec_password_min_admin']) ? ' is-invalid' : '' ?>"
                                   style="max-width:120px" id="sec_password_min_admin" name="sec_password_min_admin"
                                   type="number" min="<?= PASSWORD_MIN_LENGTH ?>" max="64" step="1"
                                   value="<?= e_attr((string) (old('sec_password_min_admin', null) ?? password_min_length('admin'))) ?>">
                            <?php if (isset($errors['sec_password_min_admin'])): ?>
                                <span class="sik-error"><?= e($errors['sec_password_min_admin']) ?></span>
                            <?php endif; ?>
                        </div>

                        <div>
                            <button type="submit" class="ad-btn ad-btn--primary">
                                <?= icon('check', 'w-4 h-4') ?> Save policy
                            </button>
                        </div>
                    </form>
                <?php endif; ?>

                <details style="font-size:13px">
                    <summary style="cursor:pointer;font-weight:600">What else is enforced</summary>
                    <ul style="margin:8px 0 0 18px;display:grid;gap:6px">
                        <li>A letter and a number, unless the password is
                            <?= PASSWORD_PASSPHRASE_LENGTH ?> characters or longer - a passphrase needs no punctuation tax.</li>
                        <li>At most <?= PASSWORD_MAX_LENGTH ?> characters.</li>
                        <li><?= (int) $blocked ?> banned passwords (<code>includes/data/common-passwords.txt</code>),
                            checked with trailing digits stripped, so "Password123" is caught by "password".</li>
                        <li>Nothing that simply repeats the email address, the name or the store name.</li>
                        <li>Hashed with
                            <?= $algo === PASSWORD_BCRYPT
                                ? 'bcrypt cost 12. Argon2id is not built into this PHP; ask the host to enable it.'
                                : 'Argon2id (19 MiB, 2 passes), which also removes bcrypt\'s silent 72-byte cut-off.' ?>
                            An older hash is upgraded quietly the next time its owner signs in.</li>
                    </ul>
                </details>
            </div>
        </div>
        <?php
    },
];
