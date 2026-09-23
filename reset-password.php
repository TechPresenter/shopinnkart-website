<?php
/**
 * ShopInnKart - Choose a new password from an emailed link.
 *
 * The token is checked against the database before the form is drawn and
 * again when it is submitted, so the hidden fields below are never trusted.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_once INCLUDES_PATH . '/auth-layout.php';

// The emailed link arrives with the token in the query string - the block
// below gets it out of there within one request, but for that one request it
// must not leak sideways: no Referer to anybody, nothing cached, and no
// third-party tag (analytics, pixel, the store's own custom_js) on the page -
// a tag manager reports document.location, token and all, to somebody else's
// server, and it would do it on the redirect as readily as on the form.
$GLOBALS['SIK_NO_THIRD_PARTY'] = true;
if (!headers_sent()) {
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: no-store, no-cache, must-revalidate');
}

// input() reads POST before GET, so this covers the emailed link and the
// round trip through the form.
$token = (string) input('token', '');
$email = mb_strtolower((string) input('email', ''));

// V56, second half: the emailed link carries the token in the query string,
// and a URL in the address bar is in the history, in a bookmark, in whatever
// the visitor pastes into a chat asking for help, and in every screenshot of
// the window. So a GET that carries one parks it in the session and bounces
// to a clean address; the form below is drawn from the session copy, and the
// token itself only ever travels again inside a POST body.
if (!is_post() && $token !== '') {
    $_SESSION['_reset_link'] = ['token' => $token, 'email' => $email, 'at' => time()];
    redirect(url('reset-password.php'), 303);
}

// Picked up again on the clean URL. Short-lived on purpose: a stash left in a
// shared browser is a working reset link for as long as it lasts, and the
// token's own hour is the outer bound anyway.
if (!is_post() && is_array($_SESSION['_reset_link'] ?? null)) {
    $stash = $_SESSION['_reset_link'];
    if (time() - (int) ($stash['at'] ?? 0) <= 1800) {
        $token = (string) ($stash['token'] ?? '');
        $email = mb_strtolower((string) ($stash['email'] ?? ''));
    } else {
        unset($_SESSION['_reset_link']);
    }
}

// Guessing at 64 hex characters is hopeless, but the attempt should still cost
// something - and this is also the endpoint a stolen token gets replayed on.
if ($token !== '' && !form_rate_limit('reset_password', 15, 3600)) {
    $token = '';
}

$errors       = [];
$linkProblem  = '';
$reset        = $token === '' ? null : find_password_reset($token, 'customer');
$user         = null;

if ($reset === null) {
    $linkProblem = 'This reset link has expired or has already been used. Password reset links are valid for one hour.';
} elseif ($email === '' || !hash_equals(mb_strtolower((string) $reset['email']), $email)) {
    // The link carries both halves. A token on its own must not be replayable
    // against another account if it leaks from a shared inbox or a log.
    $linkProblem = 'This reset link is not valid. Please request a new one.';
} else {
    $user = Database::fetch('SELECT * FROM `users` WHERE `email` = :email LIMIT 1', ['email' => $reset['email']]);
    if ($user === null || $user['status'] !== 'active') {
        $linkProblem = 'This account is no longer active. Please contact support.';
        $user = null;
    }
}

if (is_post()) {
    csrf_require();

    if ($user !== null) {
        $password = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
        $confirm  = isset($_POST['password_confirmation']) && is_string($_POST['password_confirmation'])
            ? $_POST['password_confirmation'] : '';

        $validator = new Validator(
            ['password' => $password, 'password_confirmation' => $confirm, 'email' => (string) $user['email']],
            ['password' => 'New password', 'password_confirmation' => 'Confirm password']
        );
        $validator->required('password')->password('password')
            ->required('password_confirmation')
            ->matches('password_confirmation', 'password', 'Passwords do not match.');

        if ($validator->fails()) {
            $errors = $validator->errors();
        } else {
            // Claim the token first and only continue if this request is the
            // one that got it: the old find-then-update pair let two
            // submissions of the same leaked link both set a password.
            $claimed = false;
            Database::transaction(static function () use ($reset, $user, $password, &$claimed): void {
                if (!claim_password_reset((int) $reset['id'])) {
                    return;
                }
                $claimed = true;

                Database::update('users', [
                    'password'      => password_hash_app($password),
                    // A successful reset clears the counters left behind by
                    // the failed attempts that sent them here.
                    'failed_logins' => 0,
                    'locked_until'  => null,
                ], '`id` = :id', ['id' => (int) $user['id']]);
            });

            if (!$claimed) {
                $linkProblem = 'This reset link has already been used. Please request a new one.';
                $user = null;
            } else {
                // Every other session and remembered device of this account
                // ends here - that is the whole point of resetting a password
                // somebody else may know - and the owner is told by email.
                auth_after_password_change('customer', $user, 'reset');

                // The parked link is spent; it must not draw the form again
                // for whoever opens this browser next.
                unset($_SESSION['_reset_link']);

                // Deliberately NOT signed in: whoever holds the emailed link
                // would otherwise get a live session out of one intercepted
                // message. They now have to sign in with the new password.
                flash('success', 'Your password has been updated. Please sign in with it.');
                redirect(url('login.php'));
            }
        }
    }
}

auth_layout_start([
    'title'       => 'Reset Password',
    'description' => 'Choose a new password for your ShopInnKart account.',
    'heading'     => $linkProblem === '' ? 'Choose a new password' : 'This link is no longer valid',
    'subheading'  => $linkProblem === '' ? 'Pick something you have not used here before.' : '',
]);
?>

<?php if ($linkProblem !== ''): ?>

    <div class="sik-empty sik-empty--sm">
        <?= icon('lock', 'w-12 h-12') ?>
        <h2 class="sik-empty__title">We cannot use this link</h2>
        <p class="sik-empty__text"><?= e($linkProblem) ?></p>
        <a class="sik-btn sik-btn--primary" href="<?= e(url('forgot-password.php')) ?>">Request a new link</a>
    </div>

<?php else: ?>

    <p style="text-align:center;font-size:13px;color:var(--sik-muted);margin-bottom:var(--sp-5)">
        Resetting the password for <strong><?= e((string) $reset['email']) ?></strong>
    </p>

    <form method="post" action="<?= e(url('reset-password.php')) ?>"
          data-ajax-form="auth/reset-password.php" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <input type="hidden" name="email" value="<?= e((string) $reset['email']) ?>">

        <?php auth_password_field([
            'id'           => 'sikResetPassword',
            'label'        => 'New password',
            'autocomplete' => 'new-password',
            'placeholder'  => 'Choose a strong password',
            'new_password' => true,
            'meter'        => '#sikResetPasswordMeter',
            'help'         => 'At least ' . PASSWORD_MIN_LENGTH . ' characters, with one letter and one number.',
            'error'        => (string) ($errors['password'] ?? ''),
        ]); ?>

        <?php auth_password_field([
            'id'           => 'sikResetConfirm',
            'name'         => 'password_confirmation',
            'label'        => 'Confirm new password',
            'autocomplete' => 'new-password',
            'placeholder'  => 'Re-enter your new password',
            'new_password' => true,
            'error'        => (string) ($errors['password_confirmation'] ?? ''),
        ]); ?>

        <button type="submit" class="sik-btn sik-btn--primary sik-btn--block sik-btn--lg">
            <span class="sik-btn__label">Update Password</span>
        </button>
    </form>

<?php endif; ?>

<?php
auth_layout_end([
    'text'  => 'Remembered your password?',
    'label' => 'Back to sign in',
    'url'   => url('login.php'),
]);
