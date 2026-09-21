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

// input() reads POST before GET, so this covers the emailed link and the
// round trip through the form.
$token = (string) input('token', '');
$email = mb_strtolower((string) input('email', ''));

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
            ['password' => $password, 'password_confirmation' => $confirm],
            ['password' => 'New password', 'password_confirmation' => 'Confirm password']
        );
        $validator->required('password')->password('password')
            ->required('password_confirmation')
            ->matches('password_confirmation', 'password', 'Passwords do not match.');

        if ($validator->fails()) {
            $errors = $validator->errors();
        } else {
            Database::update('users', [
                'password'      => password_hash($password, PASSWORD_DEFAULT),
                // A successful reset clears the lockout left behind by the
                // failed attempts that sent them here.
                'failed_logins' => 0,
                'locked_until'  => null,
            ], '`id` = :id', ['id' => (int) $user['id']]);

            consume_password_reset((int) $reset['id']);
            login_user($user);

            flash('success', 'Your password has been updated. You are signed in.');
            redirect(url('account.php'));
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
