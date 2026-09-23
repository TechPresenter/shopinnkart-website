<?php
/**
 * ShopInnKart - Customer sign in.
 *
 * The form posts through /api/auth/login.php when JavaScript is on. With it
 * off the browser posts back to this file and the block below does the same
 * work, so signing in never depends on scripting.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_once INCLUDES_PATH . '/auth-layout.php';

if (is_logged_in()) {
    redirect(url('account.php'));
}

// A password was already accepted and the second factor is outstanding.
if (mfa_pending('customer') !== null) {
    redirect(url('login-2fa.php'));
}

$errors    = [];
$formError = '';
$email     = '';
$remember  = false;

if (is_post()) {
    csrf_require();

    $email = mb_strtolower((string) input('email', ''));
    // Never trimmed: a trailing space is part of the password.
    $password = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
    $remember = input_bool('remember');

    $validator = new Validator(
        ['email' => $email, 'password' => $password],
        ['email' => 'Email address', 'password' => 'Password']
    );
    $validator->required('email')->email('email')->required('password');

    if ($validator->fails()) {
        $errors = $validator->errors();
    } elseif (($botError = bot_guard_form('login', ['key' => $email, 'min_seconds' => 0])) !== null) {
        // Only after this address has run up several refusals; an ordinary
        // customer signing in never meets this branch.
        $formError = $botError;
    } else {
        $attempt = attempt_login($email, $password);

        if ($attempt['ok']) {
            bot_note_success('login', $email);

            // The password is only the first half. mfa_sign_in() parks the
            // browser in a half-authenticated state when this account has a
            // second factor - no customer session is written until
            // login-2fa.php is satisfied. "Keep me signed in" is carried
            // across the challenge rather than honoured before it.
            $gate = mfa_sign_in('customer', $attempt['user'], ['remember' => $remember]);
            if ($gate['stage'] !== 'complete') {
                redirect($gate['url']);
            }

            login_user($attempt['user'], $remember, $gate['method']);
            flash('success', 'Welcome back, ' . trim((string) $attempt['user']['first_name']) . '.');
            // Sends them on to whatever page asked them to sign in.
            redirect(intended_url());
        }

        bot_note_failure('login', $email);

        // Kept off the individual fields on purpose: highlighting "email" or
        // "password" would say which half was wrong, and whether the address
        // has an account here at all.
        $formError = (string) $attempt['error'];
    }
}

$storeName = (string) setting('store_name', SITE_NAME);

auth_layout_start([
    'title'       => 'Sign In',
    'description' => 'Sign in to your ' . $storeName . ' account to track orders, manage addresses and check out faster.',
    'heading'     => 'Welcome back',
    'subheading'  => 'Sign in to track your orders, save favourites and check out faster.',
]);
?>

<?php if ($formError !== ''): ?>
    <?= auth_alert('error', $formError) ?>
<?php endif; ?>

<form method="post" action="<?= e(url('login.php')) ?>" data-ajax-form="auth/login.php" novalidate>
    <?= csrf_field() ?>
    <?php // Invisible unless this address has been refused several times. ?>
    <?= bot_form_html('login', $email) ?>

    <div class="sik-field">
        <label class="sik-label" for="sikLoginEmail">Email address</label>
        <input class="sik-input<?= isset($errors['email']) ? ' is-invalid' : '' ?>"
               type="email" id="sikLoginEmail" name="email" value="<?= e($email) ?>"
               autocomplete="email" inputmode="email" placeholder="you@example.com" required autofocus>
        <?php if (isset($errors['email'])): ?>
            <span class="sik-error"><?= e($errors['email']) ?></span>
        <?php endif; ?>
    </div>

    <?php auth_password_field([
        'id'           => 'sikLoginPassword',
        'label'        => 'Password',
        'autocomplete' => 'current-password',
        'placeholder'  => 'Enter your password',
        'error'        => (string) ($errors['password'] ?? ''),
        'aside'        => ['label' => 'Forgot password?', 'url' => url('forgot-password.php')],
    ]); ?>

    <?php if (setting_bool('sec_remember_enabled', true)): ?>
        <?php // A real remembered device: a rotated token of its own, good for
              // sec_remember_days. Unticked, the sign-in ends with the browser. ?>
        <label class="sik-check" style="margin-bottom:var(--sp-5)">
            <input type="checkbox" name="remember" value="1"<?= $remember ? ' checked' : '' ?>>
            <span>Keep me signed in on this device</span>
        </label>
    <?php endif; ?>

    <button type="submit" class="sik-btn sik-btn--primary sik-btn--block sik-btn--lg">
        <span class="sik-btn__label">Sign In</span>
    </button>
</form>

<?php
auth_layout_end([
    'text'  => 'New to ' . $storeName . '?',
    'label' => 'Create an account',
    'url'   => url('register.php'),
]);
