<?php
/**
 * Security card: hidden admin login address.
 *
 * Returned to admin/security/settings.php, which renders the card and routes
 * this card's POST actions to the handlers below. See that file for the shape.
 */

declare(strict_types=1);

return [
    'key'    => 'login-address',
    'order'  => 10,
    'column' => 'main',

    'actions' => [
        'gate_save' => static function (string $back): void {
            $slug  = strtolower(trim((string) input('admin_login_slug', '')));
            $error = admin_gate_slug_error($slug);
            if ($error !== null) {
                flash_errors(['admin_login_slug' => $error]);
                flash_old($_POST);
                flash('error', 'The login address was not changed.');
                redirect($back);
            }

            $previous = admin_gate_slug();
            setting_save('admin_login_slug', $slug, 'security', 'text');
            admin_after_write();

            // The admin making the change must not be the first one locked out:
            // hand this browser the new pass before the old one stops working.
            admin_gate_grant($slug);

            log_activity('security.admin_login_address', 'settings', null,
                $previous === '' ? 'Hid the admin login behind a secret address' : 'Changed the secret admin login address');
            flash('success', 'Saved. The admin login is now only at ' . admin_gate_url($slug)
                . ' - bookmark it and share it only with other admins.');
            redirect($back);
        },

        'gate_disable' => static function (string $back): void {
            setting_save('admin_login_slug', '', 'security', 'text');
            admin_after_write();
            log_activity('security.admin_login_address', 'settings', null, 'Made the admin login public again (/admin/)');
            flash('success', 'The admin login is back at ' . admin_url('login.php') . '.');
            redirect($back);
        },
    ],

    'render' => static function (array $errors, bool $canEdit): void {
        $slug       = admin_gate_slug();
        $gateOn     = $slug !== '';
        $slugValue  = (string) (old('admin_login_slug', null) ?? $slug);
        $suggestion = admin_gate_suggest();
        ?>
        <div class="ad-card" style="margin:0" id="admin-login-address">
            <div class="ad-card__head">
                <div>
                    <h2 class="ad-card__title">Hidden admin login address</h2>
                    <div class="ad-card__sub">
                        Take the login form off the public map. Without the secret address,
                        <code>/admin/</code> answers "page not found" like any missing page.
                    </div>
                </div>
                <?= $gateOn
                    ? '<span class="sik-status sik-status--green">Hidden</span>'
                    : '<span class="sik-status sik-status--amber">Public at /admin/</span>' ?>
            </div>

            <div class="ad-card__body" style="display:grid;gap:14px">
                <?php if ($gateOn): ?>
                    <div class="ad-field">
                        <span class="sik-label">Your admin login address</span>
                        <div class="ad-stackbar" style="display:flex;gap:8px;flex-wrap:wrap">
                            <input class="sik-input ad-mono" type="text" readonly style="flex:1 1 260px"
                                   value="<?= e(admin_gate_url()) ?>" aria-label="Admin login address">
                            <button type="button" class="ad-btn" data-copy="<?= e_attr(admin_gate_url()) ?>">
                                <?= icon('copy', 'w-4 h-4') ?> Copy
                            </button>
                        </div>
                        <span class="sik-help">Bookmark it. Other admins need it too - send it to them privately.</span>
                    </div>
                <?php endif; ?>

                <?php if ($canEdit): ?>
                    <form method="post" class="ad-form" style="display:grid;gap:12px">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="gate_save">
                        <div class="ad-field">
                            <label class="sik-label" for="admin_login_slug">
                                <?= $gateOn ? 'Change the secret part' : 'Secret part of the address' ?>
                            </label>
                            <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap">
                                <span class="ad-muted ad-mono" style="font-size:13px"><?= e(rtrim(url(), '/')) ?>/</span>
                                <input class="sik-input ad-mono<?= isset($errors['admin_login_slug']) ? ' is-invalid' : '' ?>"
                                       id="admin_login_slug" name="admin_login_slug" type="text"
                                       style="flex:1 1 200px" maxlength="40" spellcheck="false" autocomplete="off"
                                       pattern="[a-z0-9][a-z0-9\-]{7,39}" required
                                       value="<?= e_attr($slugValue) ?>" placeholder="<?= e_attr($suggestion) ?>">
                            </div>
                            <?php if (isset($errors['admin_login_slug'])): ?>
                                <span class="sik-error"><?= e($errors['admin_login_slug']) ?></span>
                            <?php else: ?>
                                <span class="sik-help">
                                    8-40 lowercase letters, digits and hyphens. Avoid words like "admin" or "login".
                                    <button type="button" class="ad-btn ad-btn--sm" style="margin-top:6px"
                                            data-fill-slug="<?= e_attr($suggestion) ?>">Use <?= e($suggestion) ?></button>
                                </span>
                            <?php endif; ?>
                        </div>
                        <div style="display:flex;gap:8px;flex-wrap:wrap">
                            <button type="submit" class="ad-btn ad-btn--primary"
                                    <?= admin_confirm_attrs('From now on the admin login only opens at the new address. Bookmark it before you go on.', ['title' => 'Move the admin login?', 'label' => 'Move it', 'tone' => 'danger']) ?>>
                                <?= icon('lock', 'w-4 h-4') ?> <?= $gateOn ? 'Change address' : 'Hide the admin login' ?>
                            </button>
                        </div>
                    </form>

                    <?php if ($gateOn): ?>
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="gate_disable">
                            <button type="submit" class="ad-btn ad-btn--danger"
                                    <?= admin_confirm_attrs('Anyone who finds /admin/login.php will see the sign-in form again.', ['title' => 'Make the login public?', 'label' => 'Make it public', 'tone' => 'danger']) ?>>
                                Make it public again
                            </button>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>

                <details style="font-size:13px">
                    <summary style="cursor:pointer;font-weight:600">How it works</summary>
                    <div style="display:grid;gap:8px;margin-top:8px">
                        <p>Opening the secret address once lets that browser see the login form for 30 days.
                           Anyone else - and every bot scanning for <code>/admin/login.php</code> - gets the store's
                           normal "page not found". Admins who are already signed in are never affected.</p>
                        <p>It hides the door; the password and its lockout still guard it. A leaked address only
                           brings back the login form - change it here and the old one stops working at once.</p>
                        <p><strong>Lost the address?</strong> On the server run
                           <code class="ad-mono">php bin/admin-login-url.php</code> to print it, or add
                           <code class="ad-mono">--disable</code> to make the login public again.</p>
                    </div>
                </details>
            </div>
        </div>
        <script>
            document.querySelectorAll('[data-fill-slug]').forEach(function (button) {
                button.addEventListener('click', function () {
                    var input = document.getElementById('admin_login_slug');
                    if (input) { input.value = button.getAttribute('data-fill-slug'); input.focus(); }
                });
            });
        </script>
        <?php
    },
];
