<?php
/**
 * ShopInnKart - Request a password reset link.
 *
 * The answer is identical for every address, registered or not, so the form
 * cannot be used to work out who shops here.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_once INCLUDES_PATH . '/auth-layout.php';

// Same wording as /api/auth/forgot-password.php, on purpose: the two paths
// must be indistinguishable to someone probing for registered addresses.
$neutralMessage = 'If that email is registered with us, a password reset link is on its way. The link is valid for 1 hour.';

$errors    = [];
$email     = '';
$sent      = false;
$throttled = false;

if (is_post()) {
    csrf_require();

    $email = mb_strtolower((string) input('email', ''));

    $validator = new Validator(['email' => $email], ['email' => 'Email address']);
    $validator->required('email')->email('email');

    if ($validator->fails()) {
        $errors = $validator->errors();
    } elseif (($botError = bot_guard_form('forgot_password', ['key' => $email])) !== null) {
        // The honeypot and the time-on-form check. Shown as the same "please
        // try again" as any other refusal, so it says nothing about the
        // address that was typed.
        $errors['email'] = $botError;
    } else {
        // This form sends mail to an address a stranger typed, so the no-JS
        // path needs the same ceiling the API endpoint applies - and the same
        // kind of ceiling: counted per client address in the shared table, not
        // in $_SESSION, which the sender could reset by dropping the cookie.
        $throttled = !form_rate_limit('forgot_password', 5, 3600);

        if (!$throttled) {
            // The same cap on the receiving end, so a sender coming from many
            // addresses still cannot fill one inbox. Silent on purpose:
            // "you have asked too often" would confirm the address is ours.
            $mayEmail = rate_limit_attempt('forgot.email', $email, 3, 3600);

            $user = Database::fetch(
                'SELECT `id`, `email`, `first_name`, `status` FROM `users` WHERE `email` = :email LIMIT 1',
                ['email' => $email]
            );

            // Blocked and deactivated accounts get nothing - they cannot sign
            // in even with a fresh password.
            if ($mayEmail && $user !== null && $user['status'] === 'active') {
                $token = create_password_reset((string) $user['email'], 'customer');

                // canonical_url(), never url(): the link in the email must not
                // follow whatever Host header this request carried.
                $resetUrl = canonical_url('reset-password.php')
                    . '?token=' . urlencode($token)
                    . '&email=' . urlencode((string) $user['email']);

                notify_password_reset((string) $user['email'], (string) $user['first_name'], $resetUrl);
                security_event('auth.password_reset_requested', 'info', [], (int) $user['id'], 'customer');
            }

            $sent = true;
        }
    }
}

auth_layout_start([
    'title'       => 'Forgot Password',
    'description' => 'Reset your ShopInnKart password. We will email you a secure link that is valid for one hour.',
    'heading'     => 'Forgot your password?',
    'subheading'  => $sent ? '' : 'Enter the email address on your account and we will send you a reset link.',
]);
?>

<?php if ($sent): ?>

    <div class="sik-empty sik-empty--sm">
        <?= icon('mail', 'w-12 h-12') ?>
        <h2 class="sik-empty__title">Check your inbox</h2>
        <p class="sik-empty__text"><?= e($neutralMessage) ?></p>
        <a class="sik-btn sik-btn--primary" href="<?= e(url('login.php')) ?>">Back to sign in</a>
    </div>

    <p style="text-align:center;font-size:12.5px;color:var(--sik-muted);margin-top:var(--sp-4)">
        Nothing after a few minutes? Check your spam folder, or
        <a href="<?= e(url('forgot-password.php')) ?>" style="color:var(--sik-primary);font-weight:600">try another address</a>.
    </p>

<?php else: ?>

    <?php if ($throttled): ?>
        <?= auth_alert('warning', 'You have asked for several reset links already. Please check your inbox, then try again in a few minutes.') ?>
    <?php endif; ?>

    <form method="post" action="<?= e(url('forgot-password.php')) ?>"
          data-ajax-form="auth/forgot-password.php" data-reset-on-success="true" novalidate>
        <?= csrf_field() ?>
        <?= bot_form_html('forgot_password', $email) ?>

        <div class="sik-field">
            <label class="sik-label" for="sikForgotEmail">Email address</label>
            <input class="sik-input<?= isset($errors['email']) ? ' is-invalid' : '' ?>"
                   type="email" id="sikForgotEmail" name="email" value="<?= e($email) ?>"
                   autocomplete="email" inputmode="email" placeholder="you@example.com" required autofocus>
            <?php if (isset($errors['email'])): ?>
                <span class="sik-error"><?= e($errors['email']) ?></span>
            <?php else: ?>
                <span class="sik-help">The link expires one hour after we send it.</span>
            <?php endif; ?>
        </div>

        <button type="submit" class="sik-btn sik-btn--primary sik-btn--block sik-btn--lg">
            <span class="sik-btn__label">Send Reset Link</span>
        </button>
    </form>

<?php endif; ?>

<?php
auth_layout_end([
    'text'  => 'Remembered it?',
    'label' => 'Back to sign in',
    'url'   => url('login.php'),
]);
