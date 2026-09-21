<?php
/**
 * ShopInnKart - Change password.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_once INCLUDES_PATH . '/account-layout.php';

require_login();

seo_set([
    'title'  => 'Change Password',
    'robots' => 'noindex, nofollow',
]);

require INCLUDES_PATH . '/header.php';

account_layout_open('change-password', [
    'subtitle' => 'Pick something you do not use anywhere else.',
]);
?>

<div class="sik-panel" style="max-width:520px">
    <div class="sik-panel__head">
        <h2 class="sik-panel__title">Update Your Password</h2>
    </div>
    <div class="sik-panel__body">
        <?php
        // method="post" is load-bearing even though this form is submitted by
        // JS. A <form> with no method defaults to GET and with no action
        // submits to the current URL — so with JavaScript off or broken, this
        // one navigated to
        //     change-password.php?current_password=…&new_password=…
        // putting all three passwords in the address bar, the browser history,
        // the Referer header of the next request, and Apache's access log.
        //
        // login.php, register.php and reset-password.php all already declare
        // method + action; this was the only credential form that did not.
        // The JS path ignores both attributes (it posts to data-ajax-form), so
        // this changes nothing when scripting works.
        ?>
        <form method="post" action="<?= e(url('change-password.php')) ?>"
              data-ajax-form="account/password-change.php" data-reset-on-success="true" novalidate>
            <div class="sik-field">
                <label class="sik-label" for="currentPassword">Current password <span class="req">*</span></label>
                <div style="display:flex;gap:var(--sp-2)">
                    <input class="sik-input" id="currentPassword" name="current_password" type="password"
                           autocomplete="current-password" required>
                    <button type="button" class="sik-iconbtn" data-toggle-password="#currentPassword"
                            aria-label="Show password"><?= icon('eye', 'w-4 h-4') ?></button>
                </div>
            </div>

            <div class="sik-field">
                <label class="sik-label" for="newPassword">New password <span class="req">*</span></label>
                <div style="display:flex;gap:var(--sp-2)">
                    <input class="sik-input" id="newPassword" name="password" type="password"
                           autocomplete="new-password" minlength="<?= PASSWORD_MIN_LENGTH ?>" required
                           data-password-meter="#sikPasswordMeter" aria-describedby="passwordRule">
                    <button type="button" class="sik-iconbtn" data-toggle-password="#newPassword"
                            aria-label="Show password"><?= icon('eye', 'w-4 h-4') ?></button>
                </div>
                <?php // Matches includes/auth-layout.php:171. No inline display rule:
                      // it would beat [hidden], so meter.hidden = true had no effect and
                      // a stale "Strong" stayed on screen under an emptied field. ?>
                <div id="sikPasswordMeter" hidden style="margin-top:var(--sp-2)" aria-live="polite"></div>
                <span class="sik-help" id="passwordRule">
                    At least <?= PASSWORD_MIN_LENGTH ?> characters, including one letter and one number.
                </span>
            </div>

            <div class="sik-field">
                <label class="sik-label" for="confirmPassword">Confirm new password <span class="req">*</span></label>
                <div style="display:flex;gap:var(--sp-2)">
                    <input class="sik-input" id="confirmPassword" name="password_confirmation" type="password"
                           autocomplete="new-password" required>
                    <button type="button" class="sik-iconbtn" data-toggle-password="#confirmPassword"
                            aria-label="Show password"><?= icon('eye', 'w-4 h-4') ?></button>
                </div>
            </div>

            <button type="submit" class="sik-btn sik-btn--primary sik-btn--block">
                <span class="sik-btn__label">Change password</span>
            </button>
        </form>
    </div>
</div>

<div class="sik-alert sik-alert--info" style="max-width:520px;margin-top:var(--sp-4)">
    <?= icon('shield', 'w-5 h-5') ?>
    <span>
        We never ask for your password by email, phone or chat. If a message claims to be
        from ShopInnKart and asks for it, it is not from us.
    </span>
</div>

<?php
account_layout_close();
require INCLUDES_PATH . '/footer.php';
