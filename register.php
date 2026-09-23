<?php
/**
 * ShopInnKart - Create a customer account.
 *
 * Posts through /api/auth/register.php when JavaScript is on, and back to
 * this file when it is not. Both routes run the same validation rules.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_once INCLUDES_PATH . '/auth-layout.php';
// For cms_page_canonical(): the policy pages have fixed routes of their own.
require_once INCLUDES_PATH . '/content-functions.php';

if (is_logged_in()) {
    redirect(url('account.php'));
}

$errors    = [];
$formError = '';
$form = [
    'first_name'    => '',
    'last_name'     => '',
    'email'         => '',
    'phone'         => '',
    'accepts_terms' => false,
    'newsletter'    => true,
];

if (is_post()) {
    csrf_require();
}

// The no-JavaScript path needs the same ceiling the API endpoint applies: per
// client address, in the shared table. Without it, "an account already exists
// with this email" is an unlimited yes/no oracle over any address somebody
// cares to try, and an unlimited source of welcome mail from our domain.
if (is_post() && !form_rate_limit('register', 5, 3600)) {
    $formError = 'Too many sign-up attempts from this device. Please try again in a little while.';
} elseif (is_post() && ($botError = bot_guard_form('register', ['key' => (string) input('email', '')])) !== null) {
    // The honeypot, the time-on-form check and, once this address has been
    // refused a few times, the CAPTCHA. See includes/bot-protection.php.
    $formError = $botError;
} elseif (is_post()) {
    $form['first_name']    = (string) input('first_name', '');
    $form['last_name']     = (string) input('last_name', '');
    $form['email']         = mb_strtolower((string) input('email', ''));
    $form['phone']         = (string) input('phone', '');
    $form['accepts_terms'] = input_bool('accepts_terms');
    $form['newsletter']    = input_bool('newsletter');

    $password = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
    $confirm  = isset($_POST['password_confirmation']) && is_string($_POST['password_confirmation'])
        ? $_POST['password_confirmation'] : '';

    $validator = new Validator(
        array_merge($form, ['password' => $password, 'password_confirmation' => $confirm]),
        [
            'first_name'            => 'First name',
            'last_name'             => 'Last name',
            'email'                 => 'Email address',
            'phone'                 => 'Mobile number',
            'password'              => 'Password',
            'password_confirmation' => 'Confirm password',
        ]
    );

    $validator->required('first_name')->max('first_name', 100)
        ->max('last_name', 100)
        ->required('email')->email('email')->max('email', 190)
        ->unique('email', 'users', 'email', null, 'An account already exists with this email. Try signing in instead.')
        ->required('phone')->phone('phone')
        ->required('password')->password('password', null, 'customer', $form['email'])
        ->required('password_confirmation')
        ->matches('password_confirmation', 'password', 'Passwords do not match.')
        ->rule('accepts_terms', $form['accepts_terms'], 'Please accept the Terms & Conditions to continue.');

    if ($validator->fails()) {
        $errors = $validator->errors();
    } else {
        $lastName = trim($form['last_name']);
        $userId   = 0;

        try {
            $userId = Database::insert('users', [
                'first_name' => trim($form['first_name']),
                'last_name'  => $lastName === '' ? null : $lastName,
                'email'      => $form['email'],
                'phone'      => normalize_phone($form['phone']),
                'password'   => password_hash_app($password),
                'status'     => 'active',
            ]);
        } catch (PDOException $e) {
            // Two submissions racing for the same address get past unique().
            if ($e->getCode() !== '23000') {
                throw $e;
            }
            $errors['email'] = 'An account already exists with this email. Try signing in instead.';
        }

        if ($userId > 0) {
            $user = Database::fetch('SELECT * FROM `users` WHERE `id` = :id LIMIT 1', ['id' => $userId]);

            if ($user === null) {
                $formError = 'We could not finish creating your account. Please try again.';
            } else {
                login_user($user);
                notify_welcome($user);

                // Confirmation link. Never fatal, for the same reason the
                // newsletter block below is not: a mail problem must not cost
                // a completed signup. The account is usable either way.
                try {
                    send_email_verification($user);
                } catch (Throwable $e) {
                    ErrorHandler::log('warning', 'Verification email failed at registration: ' . $e->getMessage());
                }

                if ($form['newsletter']) {
                    // Never fatal: a failed subscription must not cost us a
                    // completed signup.
                    try {
                        $existing = Database::fetch(
                            'SELECT `id`, `status` FROM `newsletter_subscribers` WHERE `email` = :email LIMIT 1',
                            ['email' => $form['email']]
                        );
                        $name = trim($form['first_name'] . ' ' . $lastName);

                        if ($existing === null) {
                            Database::insert('newsletter_subscribers', [
                                'email'      => $form['email'],
                                'name'       => $name === '' ? null : mb_substr($name, 0, 150),
                                'source'     => 'register',
                                'ip_address' => client_ip(),
                                'status'     => 'active',
                            ]);
                            notify_newsletter_welcome($form['email']);
                        } elseif ($existing['status'] !== 'active') {
                            Database::update('newsletter_subscribers', ['status' => 'active'], '`id` = :id', ['id' => (int) $existing['id']]);
                            notify_newsletter_welcome($form['email']);
                        }
                    } catch (Throwable $e) {
                        ErrorHandler::log('warning', 'Newsletter opt-in at registration failed: ' . $e->getMessage());
                    }
                }

                flash('success', 'Welcome to ' . setting('store_name', SITE_NAME) . ', ' . trim($form['first_name']) . '!');
                redirect(intended_url());
            }
        }
    }
}

$storeName = (string) setting('store_name', SITE_NAME);

auth_layout_start([
    'title'       => 'Create Account',
    'description' => 'Create your ' . $storeName . ' account for faster checkout, order tracking and members-only offers.',
    'heading'     => 'Create your account',
    'subheading'  => 'It takes a minute, and checkout is quicker every time after that.',
    'width'       => 560,
]);
?>

<?php if ($formError !== ''): ?>
    <?= auth_alert('error', $formError) ?>
<?php endif; ?>

<form method="post" action="<?= e(url('register.php')) ?>" data-ajax-form="auth/register.php" novalidate>
    <?= csrf_field() ?>

    <?php // Honeypot, the signed time-on-form stamp, and the CAPTCHA if this
          // visitor has earned one. Nothing visible in the ordinary case. ?>
    <?= bot_form_html('register', (string) $form['email']) ?>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-x-4">
        <div class="sik-field">
            <label class="sik-label" for="sikRegFirst">First name <span class="req">*</span></label>
            <input class="sik-input<?= isset($errors['first_name']) ? ' is-invalid' : '' ?>"
                   type="text" id="sikRegFirst" name="first_name" value="<?= e($form['first_name']) ?>"
                   autocomplete="given-name" maxlength="100" placeholder="Aarav" required autofocus>
            <?php if (isset($errors['first_name'])): ?>
                <span class="sik-error"><?= e($errors['first_name']) ?></span>
            <?php endif; ?>
        </div>

        <div class="sik-field">
            <label class="sik-label" for="sikRegLast">Last name</label>
            <input class="sik-input<?= isset($errors['last_name']) ? ' is-invalid' : '' ?>"
                   type="text" id="sikRegLast" name="last_name" value="<?= e($form['last_name']) ?>"
                   autocomplete="family-name" maxlength="100" placeholder="Sharma">
            <?php if (isset($errors['last_name'])): ?>
                <span class="sik-error"><?= e($errors['last_name']) ?></span>
            <?php endif; ?>
        </div>
    </div>

    <div class="sik-field">
        <label class="sik-label" for="sikRegEmail">Email address <span class="req">*</span></label>
        <input class="sik-input<?= isset($errors['email']) ? ' is-invalid' : '' ?>"
               type="email" id="sikRegEmail" name="email" value="<?= e($form['email']) ?>"
               autocomplete="email" inputmode="email" maxlength="190" placeholder="you@example.com" required>
        <?php if (isset($errors['email'])): ?>
            <span class="sik-error"><?= e($errors['email']) ?></span>
        <?php else: ?>
            <span class="sik-help">Order updates and invoices go to this address.</span>
        <?php endif; ?>
    </div>

    <div class="sik-field">
        <label class="sik-label" for="sikRegPhone">Mobile number <span class="req">*</span></label>
        <input class="sik-input<?= isset($errors['phone']) ? ' is-invalid' : '' ?>"
               type="tel" id="sikRegPhone" name="phone" value="<?= e($form['phone']) ?>"
               autocomplete="tel" inputmode="numeric" maxlength="15" placeholder="98765 43210" required>
        <?php if (isset($errors['phone'])): ?>
            <span class="sik-error"><?= e($errors['phone']) ?></span>
        <?php else: ?>
            <span class="sik-help">10-digit Indian mobile number, used for delivery updates.</span>
        <?php endif; ?>
    </div>

    <?php auth_password_field([
        'id'           => 'sikRegPassword',
        'label'        => 'Password',
        'autocomplete' => 'new-password',
        'placeholder'  => 'Choose a strong password',
        'new_password' => true,
        'required_mark' => true,
        'meter'        => '#sikRegPasswordMeter',
        'help'         => 'At least ' . PASSWORD_MIN_LENGTH . ' characters, with one letter and one number.',
        'error'        => (string) ($errors['password'] ?? ''),
    ]); ?>

    <?php auth_password_field([
        'id'           => 'sikRegConfirm',
        'name'         => 'password_confirmation',
        'label'        => 'Confirm password',
        'autocomplete' => 'new-password',
        'placeholder'  => 'Re-enter your password',
        'new_password' => true,
        'required_mark' => true,
        'error'        => (string) ($errors['password_confirmation'] ?? ''),
    ]); ?>

    <label class="sik-check" style="margin-bottom:var(--sp-3)">
        <input type="checkbox" name="accepts_terms" value="1"<?= $form['accepts_terms'] ? ' checked' : '' ?> required>
        <span>
            I agree to the
            <a href="<?= e(cms_page_canonical('terms-conditions')) ?>" style="color:var(--sik-primary);font-weight:600">Terms &amp; Conditions</a>
            and the
            <a href="<?= e(cms_page_canonical('privacy-policy')) ?>" style="color:var(--sik-primary);font-weight:600">Privacy Policy</a>.
        </span>
    </label>
    <?php if (isset($errors['accepts_terms'])): ?>
        <span class="sik-error" style="margin-bottom:var(--sp-3)"><?= e($errors['accepts_terms']) ?></span>
    <?php endif; ?>

    <label class="sik-check" style="margin-bottom:var(--sp-5)">
        <input type="checkbox" name="newsletter" value="1"<?= $form['newsletter'] ? ' checked' : '' ?>>
        <span>Email me new arrivals, price drops and member offers. You can unsubscribe any time.</span>
    </label>

    <button type="submit" class="sik-btn sik-btn--primary sik-btn--block sik-btn--lg">
        <span class="sik-btn__label">Create Account</span>
    </button>
</form>

<?php
auth_layout_end([
    'text'  => 'Already have an account?',
    'label' => 'Sign in instead',
    'url'   => url('login.php'),
]);
