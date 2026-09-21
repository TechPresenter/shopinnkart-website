<?php
/**
 * ShopInnKart - Customer profile details.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_once INCLUDES_PATH . '/account-layout.php';

$user = require_login();

$genders = ['male' => 'Male', 'female' => 'Female', 'other' => 'Other'];
$currentGender = (string) ($user['gender'] ?? '');
$dob = $user['date_of_birth'] !== null ? format_date($user['date_of_birth'], 'Y-m-d') : '';

seo_set([
    'title'  => 'Profile',
    'robots' => 'noindex, nofollow',
]);

require INCLUDES_PATH . '/header.php';

account_layout_open('profile', [
    'subtitle' => 'Keep your details current so deliveries and order updates reach you.',
]);
?>

<div class="sik-panel">
    <div class="sik-panel__head">
        <h2 class="sik-panel__title">Personal Details</h2>
        <span style="font-size:12px;color:var(--sik-muted)">Member since <?= e(format_date($user['created_at'], 'M Y')) ?></span>
    </div>
    <div class="sik-panel__body">
        <form data-ajax-form="account/profile-update.php" novalidate>
            <div class="sik-grid" style="--cols-mobile:1;--cols-tablet:2;--cols-desktop:2;gap:0 var(--sp-4)">
                <div class="sik-field">
                    <label class="sik-label" for="firstName">First name <span class="req">*</span></label>
                    <input class="sik-input" id="firstName" name="first_name" type="text" maxlength="100"
                           autocomplete="given-name" required value="<?= e($user['first_name']) ?>">
                </div>

                <div class="sik-field">
                    <label class="sik-label" for="lastName">Last name</label>
                    <input class="sik-input" id="lastName" name="last_name" type="text" maxlength="100"
                           autocomplete="family-name" value="<?= e((string) $user['last_name']) ?>">
                </div>

                <div class="sik-field">
                    <label class="sik-label" for="profileEmail">Email address</label>
                    <input class="sik-input" id="profileEmail" type="email" value="<?= e($user['email']) ?>"
                           readonly disabled aria-describedby="emailNote" style="background:var(--sik-soft)">
                    <span class="sik-help" id="emailNote">
                        Order confirmations and password resets go to this address, so changing it
                        needs verification first. Contact support and we will move it for you.
                    </span>
                </div>

                <div class="sik-field">
                    <label class="sik-label" for="profilePhone">Mobile number <span class="req">*</span></label>
                    <input class="sik-input" id="profilePhone" name="phone" type="tel" inputmode="numeric"
                           autocomplete="tel" maxlength="15" required placeholder="10-digit mobile number"
                           value="<?= e((string) $user['phone']) ?>">
                    <span class="sik-help">Used by our delivery partner to reach you.</span>
                </div>

                <div class="sik-field">
                    <label class="sik-label" for="profileGender">Gender</label>
                    <select class="sik-select" id="profileGender" name="gender">
                        <option value="">Prefer not to say</option>
                        <?php foreach ($genders as $value => $label): ?>
                            <option value="<?= e($value) ?>" <?= $currentGender === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="sik-field">
                    <label class="sik-label" for="profileDob">Date of birth</label>
                    <input class="sik-input" id="profileDob" name="date_of_birth" type="date"
                           max="<?= e(date('Y-m-d')) ?>" value="<?= e($dob) ?>">
                    <span class="sik-help">We use it to send you a birthday offer.</span>
                </div>
            </div>

            <div style="display:flex;flex-wrap:wrap;gap:var(--sp-3);margin-top:var(--sp-2)">
                <button type="submit" class="sik-btn sik-btn--primary">
                    <span class="sik-btn__label">Save changes</span>
                </button>
                <a class="sik-btn sik-btn--outline" href="<?= e(url('change-password.php')) ?>">Change password</a>
            </div>
        </form>
    </div>
</div>

<div class="sik-panel" style="margin-top:var(--sp-5)">
    <div class="sik-panel__head">
        <h2 class="sik-panel__title">Account Activity</h2>
    </div>
    <div class="sik-panel__body">
        <div class="sik-summary">
            <div class="sik-summary__row">
                <span style="color:var(--sik-muted)">Account created</span>
                <span style="font-weight:600"><?= e(format_datetime($user['created_at'])) ?></span>
            </div>
            <div class="sik-summary__row">
                <span style="color:var(--sik-muted)">Last sign-in</span>
                <span style="font-weight:600">
                    <?= $user['last_login_at'] !== null ? e(format_datetime($user['last_login_at'])) : 'This is your first session' ?>
                </span>
            </div>
            <div class="sik-summary__row">
                <span style="color:var(--sik-muted)">Email verified</span>
                <span>
                    <?php if ($user['email_verified_at'] !== null): ?>
                        <span class="sik-status sik-status--green">Verified</span>
                    <?php else: ?>
                        <span class="sik-status sik-status--amber">Not verified</span>
                    <?php endif; ?>
                </span>
            </div>
        </div>
    </div>
</div>

<?php
account_layout_close();
require INCLUDES_PATH . '/footer.php';
