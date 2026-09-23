<?php
/**
 * ShopInnKart Admin - Edit a customer.
 *
 * The stored password hash is never read into this page. The only thing an
 * admin can do with a password here is replace it.
 *
 * Replacing it, or changing the sign-in address, is account takeover with a
 * friendly face: a support agent could set the email to one they control and
 * then sign in as the shopper, with their addresses, order history and saved
 * payment options. So both sit behind customers.credentials (held by nobody by
 * default), need the agent's own password, and always tell the customer at the
 * address that was on file BEFORE the change. customers.edit on its own edits
 * the profile, and view.php's "send a reset link" stays the normal route.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('customers.edit');

require_once ADMIN_PATH . '/includes/rbac.php';
require_once __DIR__ . '/_filters.php';

/** May this admin set a shopper's password or sign-in address? */
$canCredentials = admin_can('customers.credentials');

$customerId = (int) input_int('id');

if ($customerId <= 0) {
    flash('error', 'No customer was selected.');
    redirect(admin_url('customers/'));
}

$customer = Database::fetch(
    'SELECT `id`, `first_name`, `last_name`, `email`, `phone`, `gender`, `date_of_birth`,
            `status`, `created_at`
     FROM `users` WHERE `id` = :id LIMIT 1',
    ['id' => $customerId]
);

if ($customer === null) {
    flash('error', 'That customer no longer exists.');
    redirect(admin_url('customers/'));
}

const CUSTOMER_GENDERS = ['male' => 'Male', 'female' => 'Female', 'other' => 'Other'];

$editUrl = admin_url('customers/edit.php?id=' . $customerId);
$viewUrl = admin_url('customers/view.php?id=' . $customerId);

// ---------------------------------------------------------------------------
// Save
// ---------------------------------------------------------------------------
if (is_post()) {
    csrf_require();

    $password = (string) ($_POST['password'] ?? '');

    $validator = new Validator($_POST, [
        'first_name'    => 'First name',
        'last_name'     => 'Last name',
        'email'         => 'Email address',
        'phone'         => 'Phone number',
        'date_of_birth' => 'Date of birth',
        'status'        => 'Status',
    ]);

    $validator
        ->required('first_name')->max('first_name', 100)
        ->max('last_name', 100)
        ->required('email')->email('email')->max('email', 190)
        ->unique('email', 'users', 'email', $customerId)
        ->phone('phone')
        ->in('gender', array_keys(CUSTOMER_GENDERS))
        ->date('date_of_birth')
        ->required('status')->in('status', CUSTOMER_STATUSES);

    $dateOfBirth = trim((string) ($_POST['date_of_birth'] ?? ''));
    if ($dateOfBirth !== '' && strtotime($dateOfBirth) !== false) {
        $validator->rule('date_of_birth', strtotime($dateOfBirth) <= time(), 'Date of birth cannot be in the future.');
    }

    // Blank means "keep the current password", so it is only checked when filled.
    if ($password !== '') {
        $validator->password('password')
            ->matches('password_confirmation', 'password', 'The two passwords do not match.');
    }

    $newEmail    = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
    $emailChange = $newEmail !== mb_strtolower((string) $customer['email']);

    // Neither of the two takeover routes is available without the dedicated
    // permission. A save that leaves both alone is an ordinary profile edit.
    if (($password !== '' || $emailChange) && !$canCredentials) {
        $what = $password !== '' && $emailChange
            ? 'a password or email address'
            : ($password !== '' ? 'a password' : 'an email address');

        security_event('rbac.denied', 'high', [
            'reason'      => 'customers.credentials required',
            'customer_id' => $customerId,
            'attempted'   => $password !== '' ? 'password' : 'email',
        ], (int) $admin['id'], 'admin');

        $validator->rule(
            $password !== '' ? 'password' : 'email',
            false,
            'Setting ' . $what . ' for a customer needs the customers.credentials permission. '
                . 'Send a password reset link from their record instead.'
        );
    } elseif (($password !== '' || $emailChange) && !admin_reauth_ok()) {
        $validator->rule('reauth_password', false,
            admin_reauth_error($password !== '' ? 'set a customer password' : 'change a customer email address'));
    }

    if ($validator->fails()) {
        flash_errors($validator->errors());
        // flash_old() drops both password fields, so nothing plaintext is
        // parked in the session while the form is re-rendered.
        flash_old($_POST);
        flash('error', 'Please correct the highlighted fields.');
        redirect($editUrl);
    }

    $lastName = trim((string) ($_POST['last_name'] ?? ''));
    $gender   = trim((string) ($_POST['gender'] ?? ''));

    $data = [
        'first_name'    => trim((string) $_POST['first_name']),
        'last_name'     => $lastName !== '' ? $lastName : null,
        'email'         => mb_strtolower(trim((string) $_POST['email'])),
        'phone'         => normalize_phone((string) ($_POST['phone'] ?? '')),
        'gender'        => isset(CUSTOMER_GENDERS[$gender]) ? $gender : null,
        'date_of_birth' => $dateOfBirth !== '' ? date('Y-m-d', (int) strtotime($dateOfBirth)) : null,
        'status'        => (string) $_POST['status'],
    ];

    if ($password !== '') {
        $data['password'] = password_hash_app($password);
        // A freshly set password with a live lockout still cannot be used, so
        // clear the counter at the same time.
        $data['failed_logins'] = 0;
        $data['locked_until']  = null;
    }

    Database::update('users', $data, '`id` = :id', ['id' => $customerId]);

    // Writing a new hash is only half of a password reset. Support sets a
    // shopper's password precisely because somebody else is in the account,
    // and that somebody keeps their session and their remembered device until
    // a timeout unless the account moves to a new session generation.
    //
    // auth_after_password_change() re-stamps the ACTING storefront session so
    // that a shopper changing their own password stays signed in. Here the
    // actor is an admin; if they happen to be shopping in the same browser,
    // that re-stamp would sign them out, so it is put back.
    if ($password !== '') {
        $shopperId    = (int) ($_SESSION[USER_SESSION_KEY]['id'] ?? 0);
        $shopperStamp = $_SESSION[USER_SESSION_KEY]['auth_version'] ?? null;

        // Always the support wording here: this screen is only ever reached by
        // an administrator setting a password for somebody else.
        auth_after_password_change('customer', $customer, 'admin');

        if ($shopperId !== 0 && $shopperId !== $customerId && is_int($shopperStamp)) {
            $_SESSION[USER_SESSION_KEY]['auth_version'] = $shopperStamp;
        }

        security_event('auth.sessions_revoked', 'high', [
            'reason'      => 'password set by an administrator',
            'customer_id' => $customerId,
            'by_admin_id' => (int) $admin['id'],
        ], $customerId, 'customer');
    } elseif ($data['status'] !== 'active' && $data['status'] !== (string) $customer['status']) {
        // current_user() already refuses a blocked account on its next
        // request, but a remembered device is a credential of its own: the
        // row outlives the block and would walk straight back in the moment
        // the account is set to active again. The bump drops those tokens.
        auth_bump_version('customer', $customerId);

        security_event('auth.sessions_revoked', 'high', [
            'reason'      => 'account set to ' . $data['status'] . ' by an administrator',
            'customer_id' => $customerId,
            'by_admin_id' => (int) $admin['id'],
        ], $customerId, 'customer');
    }

    $name = trim($data['first_name'] . ' ' . (string) $data['last_name']);
    log_activity(
        'customer.updated',
        'user',
        $customerId,
        'Updated customer "' . $name . '"' . ($password !== '' ? ' and set a new password' : '')
            . ($emailChange ? ' and changed the sign-in email' : '')
    );

    if ($password !== '' || $emailChange) {
        $changes = [];
        if ($password !== '') { $changes[] = 'password'; }
        if ($emailChange)     { $changes[] = 'email address'; }

        // Old and new addresses are both recorded: a takeover shows up as a
        // redirect, which is only visible if the previous value is kept.
        security_event('customer.credentials_changed', 'high', [
            'customer_id' => $customerId,
            'changes'     => $changes,
            'email_from'  => $emailChange ? mask_email((string) $customer['email']) : null,
            'email_to'    => $emailChange ? mask_email($data['email']) : null,
        ], (int) $admin['id'], 'admin');

        // Sent to the address on file BEFORE the change, so a hijack lands in
        // the real owner's inbox rather than the attacker's.
        admin_notify_customer_change($customer, 'your ' . implode(' and ', $changes)
            . ' ' . (count($changes) === 1 ? 'was' : 'were') . ' changed by our support team');

        if ($emailChange) {
            admin_notify_customer_change(
                ['id' => $customerId, 'first_name' => $data['first_name'], 'last_name' => $data['last_name'],
                 'email' => $data['email']],
                'this address was set as the sign-in email for your account'
            );
        }
    }

    admin_after_write();

    old_clear();
    flash('success', $name . ' has been updated.');
    redirect($viewUrl);
}

// ---------------------------------------------------------------------------
// Render
// ---------------------------------------------------------------------------
$errors = errors_pull();
$old    = $_SESSION['_old'] ?? [];
old_clear();

/** Repopulate from the rejected submission, falling back to the stored row. */
$value = static function (string $key) use ($old, $customer): string {
    return (string) ($old[$key] ?? $customer[$key] ?? '');
};

$customerName = customer_full_name($customer);

$pageTitle    = 'Edit ' . $customerName;
$pageSubtitle = 'Customer #' . $customerId . ' · joined ' . format_date($customer['created_at'], 'd M Y');
$breadcrumbs  = [
    ['label' => 'Dashboard', 'url' => admin_url('dashboard.php')],
    ['label' => 'Customers', 'url' => admin_url('customers/')],
    ['label' => $customerName, 'url' => $viewUrl],
    ['label' => 'Edit'],
];
$pageActions = '<a class="ad-btn" href="' . e($viewUrl) . '">' . icon('arrow-left', 'w-4 h-4') . ' Back to record</a>';

require ADMIN_PATH . '/includes/header.php';
?>

<form method="post" action="<?= e($editUrl) ?>" data-guard-unsaved>
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= $customerId ?>">

    <div class="ad-card">
        <div class="ad-card__head">
            <div>
                <div class="ad-card__title">Profile</div>
                <div class="ad-card__sub">Name, contact details and account state.</div>
            </div>
        </div>
        <div class="ad-card__body ad-form">
            <div class="ad-row ad-row--2">
                <div class="ad-field">
                    <label class="sik-label" for="firstName">First name <span aria-hidden="true">*</span></label>
                    <input class="sik-input" type="text" id="firstName" name="first_name" maxlength="100" required
                           value="<?= e($value('first_name')) ?>">
                    <?php if (error_for($errors, 'first_name') !== ''): ?>
                        <p class="sik-error"><?= e(error_for($errors, 'first_name')) ?></p>
                    <?php endif; ?>
                </div>

                <div class="ad-field">
                    <label class="sik-label" for="lastName">Last name</label>
                    <input class="sik-input" type="text" id="lastName" name="last_name" maxlength="100"
                           value="<?= e($value('last_name')) ?>">
                    <?php if (error_for($errors, 'last_name') !== ''): ?>
                        <p class="sik-error"><?= e(error_for($errors, 'last_name')) ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="ad-row ad-row--2">
                <div class="ad-field">
                    <label class="sik-label" for="email">Email address <span aria-hidden="true">*</span></label>
                    <input class="sik-input" type="email" id="email" name="email" maxlength="190" required
                           value="<?= e($value('email')) ?>"
                           <?= $canCredentials ? '' : 'readonly' ?>>
                    <p class="sik-help">
                        This is the customer's sign-in identifier, so it must stay unique.
                        <?php if (!$canCredentials): ?>
                            Changing it means signing in as them, so it needs the
                            <code>customers.credentials</code> permission.
                        <?php endif; ?>
                    </p>
                    <?php if (error_for($errors, 'email') !== ''): ?>
                        <p class="sik-error"><?= e(error_for($errors, 'email')) ?></p>
                    <?php endif; ?>
                </div>

                <div class="ad-field">
                    <label class="sik-label" for="phone">Phone number</label>
                    <input class="sik-input" type="tel" id="phone" name="phone" maxlength="20"
                           inputmode="numeric" value="<?= e($value('phone')) ?>">
                    <p class="sik-help">10-digit Indian mobile number. Leave blank if you don't have one.</p>
                    <?php if (error_for($errors, 'phone') !== ''): ?>
                        <p class="sik-error"><?= e(error_for($errors, 'phone')) ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="ad-row ad-row--3">
                <div class="ad-field">
                    <label class="sik-label" for="gender">Gender</label>
                    <select class="sik-select" id="gender" name="gender">
                        <?= admin_options(CUSTOMER_GENDERS, $value('gender'), 'Not specified') ?>
                    </select>
                    <?php if (error_for($errors, 'gender') !== ''): ?>
                        <p class="sik-error"><?= e(error_for($errors, 'gender')) ?></p>
                    <?php endif; ?>
                </div>

                <div class="ad-field">
                    <label class="sik-label" for="dob">Date of birth</label>
                    <input class="sik-input" type="date" id="dob" name="date_of_birth"
                           max="<?= e(date('Y-m-d')) ?>" value="<?= e($value('date_of_birth')) ?>">
                    <?php if (error_for($errors, 'date_of_birth') !== ''): ?>
                        <p class="sik-error"><?= e(error_for($errors, 'date_of_birth')) ?></p>
                    <?php endif; ?>
                </div>

                <div class="ad-field">
                    <label class="sik-label" for="status">Status <span aria-hidden="true">*</span></label>
                    <select class="sik-select" id="status" name="status" required>
                        <?= admin_options(
                            ['active' => 'Active', 'inactive' => 'Inactive', 'blocked' => 'Blocked'],
                            $value('status')
                        ) ?>
                    </select>
                    <p class="sik-help">Anything other than Active stops the customer signing in.</p>
                    <?php if (error_for($errors, 'status') !== ''): ?>
                        <p class="sik-error"><?= e(error_for($errors, 'status')) ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="ad-card">
        <div class="ad-card__head">
            <div>
                <div class="ad-card__title">Password</div>
                <div class="ad-card__sub">
                    <?= $canCredentials
                        ? 'Leave both fields empty to keep the current password.'
                        : 'Send the customer a reset link instead of choosing a password for them.' ?>
                </div>
            </div>
        </div>
        <div class="ad-card__body ad-form">
            <div class="sik-alert sik-alert--info">
                <?= icon('info', 'w-5 h-5') ?>
                <div>
                    Existing passwords are stored hashed and cannot be shown or recovered. Prefer
                    <a href="<?= e($viewUrl) ?>">sending a reset link</a> so the customer picks their own.
                </div>
            </div>

            <?php if ($canCredentials): ?>
                <div class="ad-row ad-row--2">
                    <div class="ad-field">
                        <label class="sik-label" for="password">New password</label>
                        <input class="sik-input" type="password" id="password" name="password"
                               autocomplete="new-password" minlength="<?= (int) password_min_length('customer') ?>">
                        <p class="sik-help">
                            <?php // password_min_length(), not the constant: sec_password_min can raise the
                                  // floor, and a hint below what the validator accepts is worse than none. ?>
                            At least <?= (int) password_min_length('customer') ?> characters, with a letter and a number.
                            Using this signs the customer out everywhere and emails them.
                        </p>
                        <?php if (error_for($errors, 'password') !== ''): ?>
                            <p class="sik-error"><?= e(error_for($errors, 'password')) ?></p>
                        <?php endif; ?>
                    </div>

                    <div class="ad-field">
                        <label class="sik-label" for="passwordConfirm">Confirm new password</label>
                        <input class="sik-input" type="password" id="passwordConfirm" name="password_confirmation"
                               autocomplete="new-password">
                        <?php if (error_for($errors, 'password_confirmation') !== ''): ?>
                            <p class="sik-error"><?= e(error_for($errors, 'password_confirmation')) ?></p>
                        <?php endif; ?>
                    </div>
                </div>

                <?= admin_reauth_field('set a password or change the sign-in address',
                    error_for($errors, 'reauth_password')) ?>
            <?php else: ?>
                <p class="ad-muted" style="font-size:13px;margin:0">
                    Setting a password directly would let you sign in as this customer, so it needs the
                    <code>customers.credentials</code> permission. Ask a Super Admin if your job needs it.
                </p>
            <?php endif; ?>
        </div>
        <div class="ad-card__foot">
            <a class="ad-btn" href="<?= e($viewUrl) ?>">Cancel</a>
            <button type="submit" class="ad-btn ad-btn--primary">
                <?= icon('check', 'w-4 h-4') ?> Save changes
            </button>
        </div>
    </div>
</form>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
