<?php
/**
 * ShopInnKart Admin - Edit a customer.
 *
 * The stored password hash is never read into this page. The only thing an
 * admin can do with a password here is replace it.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('customers.edit');

require_once __DIR__ . '/_filters.php';

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
        $data['password'] = password_hash($password, PASSWORD_DEFAULT);
        // A freshly set password with a live lockout still cannot be used, so
        // clear the counter at the same time.
        $data['failed_logins'] = 0;
        $data['locked_until']  = null;
    }

    Database::update('users', $data, '`id` = :id', ['id' => $customerId]);

    $name = trim($data['first_name'] . ' ' . (string) $data['last_name']);
    log_activity(
        'customer.updated',
        'user',
        $customerId,
        'Updated customer "' . $name . '"' . ($password !== '' ? ' and set a new password' : '')
    );
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
                           value="<?= e($value('email')) ?>">
                    <p class="sik-help">This is the customer's sign-in identifier, so it must stay unique.</p>
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
                <div class="ad-card__sub">Leave both fields empty to keep the current password.</div>
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

            <div class="ad-row ad-row--2">
                <div class="ad-field">
                    <label class="sik-label" for="password">New password</label>
                    <input class="sik-input" type="password" id="password" name="password"
                           autocomplete="new-password" minlength="<?= (int) PASSWORD_MIN_LENGTH ?>">
                    <p class="sik-help">
                        At least <?= (int) PASSWORD_MIN_LENGTH ?> characters, with a letter and a number.
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
