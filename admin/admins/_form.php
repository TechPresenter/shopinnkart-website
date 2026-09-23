<?php
/**
 * ShopInnKart Admin - Admin-user form body.
 *
 * Shared by create.php and edit.php. Both set:
 *   $account       field values (existing row, or defaults merged with input)
 *   $errors        field => message
 *   $isEdit        bool
 *   $formAction    where the form posts
 *   $roleOptions   id => label
 *   $canAssignSuper  whether this operator may hand out wildcard roles
 *   $isSelf        editing your own account
 *   $isLastSuper   this account is the last Super Admin who can sign in
 */

declare(strict_types=1);

// Include-only: the parent page already ran authentication and permissions.
if (!defined('SIK_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

/** @var array $account @var array $errors @var bool $isEdit @var string $formAction @var array $roleOptions */
$isEdit         = $isEdit ?? false;
$errors         = $errors ?? [];
$roleOptions    = $roleOptions ?? [];
$canAssignSuper = $canAssignSuper ?? false;
$isSelf         = $isSelf ?? false;
$isLastSuper    = $isLastSuper ?? false;
?>
<form class="ad-form" method="post" action="<?= e($formAction) ?>" enctype="multipart/form-data" data-guard-unsaved>
    <?= csrf_field() ?>
    <?php if ($isEdit): ?>
        <input type="hidden" name="id" value="<?= (int) $account['id'] ?>">
    <?php endif; ?>

    <div class="ad-grid ad-grid--sidebar">
        <div style="display:grid;gap:16px">

            <div class="ad-card" style="margin:0">
                <div class="ad-card__head">
                    <div>
                        <div class="ad-card__title">Profile</div>
                        <div class="ad-card__sub">Both the username and the email address can be used to sign in.</div>
                    </div>
                </div>
                <div class="ad-card__body">
                    <div class="ad-row ad-row--2">
                        <div class="ad-field">
                            <label class="sik-label" for="adminName">Full name <span class="req">*</span></label>
                            <input class="sik-input<?= isset($errors['name']) ? ' is-invalid' : '' ?>" type="text"
                                   id="adminName" name="name" maxlength="150" required
                                   value="<?= e($account['name'] ?? '') ?>" placeholder="e.g. Priya Sharma">
                            <?php if (isset($errors['name'])): ?>
                                <span class="sik-error"><?= e($errors['name']) ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="ad-field">
                            <label class="sik-label" for="adminUsername">Username <span class="req">*</span></label>
                            <input class="sik-input<?= isset($errors['username']) ? ' is-invalid' : '' ?>" type="text"
                                   id="adminUsername" name="username" maxlength="60" required
                                   autocomplete="off" spellcheck="false"
                                   value="<?= e($account['username'] ?? '') ?>" placeholder="priya">
                            <?php if (isset($errors['username'])): ?>
                                <span class="sik-error"><?= e($errors['username']) ?></span>
                            <?php else: ?>
                                <span class="sik-help">3&ndash;60 characters: letters, numbers, dot, dash or underscore.</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="ad-row ad-row--2">
                        <div class="ad-field">
                            <label class="sik-label" for="adminEmail">Email address <span class="req">*</span></label>
                            <input class="sik-input<?= isset($errors['email']) ? ' is-invalid' : '' ?>" type="email"
                                   id="adminEmail" name="email" maxlength="190" required
                                   value="<?= e($account['email'] ?? '') ?>" placeholder="priya@shopinnkart.com">
                            <?php if (isset($errors['email'])): ?>
                                <span class="sik-error"><?= e($errors['email']) ?></span>
                            <?php else: ?>
                                <span class="sik-help">Password-reset links and admin alerts go here.</span>
                            <?php endif; ?>
                        </div>

                        <div class="ad-field">
                            <label class="sik-label" for="adminPhone">Phone number</label>
                            <input class="sik-input<?= isset($errors['phone']) ? ' is-invalid' : '' ?>" type="tel"
                                   id="adminPhone" name="phone" maxlength="20" inputmode="numeric"
                                   value="<?= e($account['phone'] ?? '') ?>" placeholder="9876543210">
                            <?php if (isset($errors['phone'])): ?>
                                <span class="sik-error"><?= e($errors['phone']) ?></span>
                            <?php else: ?>
                                <span class="sik-help">10-digit Indian mobile number. Optional.</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="ad-card" style="margin:0">
                <div class="ad-card__head">
                    <div>
                        <div class="ad-card__title">Password</div>
                        <div class="ad-card__sub">
                            <?= $isEdit
                                ? 'Leave both fields blank to keep the current password.'
                                : 'Required. Share it over a channel the new admin already trusts.' ?>
                        </div>
                    </div>
                </div>
                <div class="ad-card__body">
                    <?php if ($isEdit): ?>
                        <div class="sik-alert sik-alert--info">
                            <?= icon('info', 'w-5 h-5') ?>
                            <div>
                                Stored passwords are hashed and cannot be read back &mdash; not by this screen and
                                not by anyone with database access. The only option here is to replace one.
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="ad-row ad-row--2">
                        <div class="ad-field">
                            <label class="sik-label" for="adminPassword">
                                <?= $isEdit ? 'New password' : 'Password' ?>
                                <?php if (!$isEdit): ?><span class="req">*</span><?php endif; ?>
                            </label>
                            <input class="sik-input<?= isset($errors['password']) ? ' is-invalid' : '' ?>"
                                   type="password" id="adminPassword" name="password"
                                   autocomplete="new-password" minlength="<?= (int) password_min_length('admin') ?>"
                                   <?= $isEdit ? '' : 'required' ?>>
                            <?php if (isset($errors['password'])): ?>
                                <span class="sik-error"><?= e($errors['password']) ?></span>
                            <?php else: ?>
                                <span class="sik-help">
                                    <?php // The admin floor, not the shopper one - the validator holds this
                                          // field to sec_password_min_admin, so the hint has to say the same
                                          // number or the form refuses a password it told you to type. ?>
                                    At least <?= (int) password_min_length('admin') ?> characters, including a letter and a number.
                                </span>
                            <?php endif; ?>
                        </div>

                        <div class="ad-field">
                            <label class="sik-label" for="adminPasswordConfirm">Confirm password</label>
                            <input class="sik-input<?= isset($errors['password_confirmation']) ? ' is-invalid' : '' ?>"
                                   type="password" id="adminPasswordConfirm" name="password_confirmation"
                                   autocomplete="new-password" <?= $isEdit ? '' : 'required' ?>>
                            <?php if (isset($errors['password_confirmation'])): ?>
                                <span class="sik-error"><?= e($errors['password_confirmation']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="ad-card" style="margin:0">
                <div class="ad-card__head">
                    <div>
                        <div class="ad-card__title">Avatar</div>
                        <div class="ad-card__sub">
                            Optional. Square image up to <?= e(format_bytes(MAX_UPLOAD_SIZE)) ?>;
                            initials are shown when there is none.
                        </div>
                    </div>
                </div>
                <div class="ad-card__body">
                    <div class="ad-drop" data-drop="#adminAvatarPreview">
                        <input type="file" name="avatar" accept="image/*">
                        <?= icon('upload', 'w-6 h-6') ?>
                        <div style="font-size:13px;margin-top:6px">Click or drop a profile picture here</div>
                    </div>
                    <div class="ad-preview" id="adminAvatarPreview">
                        <?php if (!empty($account['avatar'])): ?>
                            <div class="ad-preview__item">
                                <img src="<?= e(img_url($account['avatar'])) ?>" alt="Current avatar">
                                <button type="button" class="ad-preview__remove"
                                        data-remove-image="#adminRemoveAvatar"
                                        aria-label="Remove avatar">&times;</button>
                            </div>
                        <?php endif; ?>
                    </div>
                    <input type="hidden" name="remove_avatar" id="adminRemoveAvatar" value="0">
                </div>
            </div>
        </div>

        <div style="display:grid;gap:16px;align-content:start">
            <div class="ad-card" style="margin:0">
                <div class="ad-card__head"><div class="ad-card__title">Access</div></div>
                <div class="ad-card__body" style="display:grid;gap:14px">

                    <?php if ($isLastSuper): ?>
                        <div class="sik-alert sik-alert--warning" style="margin:0">
                            <?= icon('shield', 'w-5 h-5') ?>
                            <div>
                                This is the only Super Admin who can sign in. The role and status are
                                locked until a second one exists.
                            </div>
                        </div>
                    <?php elseif ($isSelf): ?>
                        <div class="sik-alert sik-alert--info" style="margin:0">
                            <?= icon('user', 'w-5 h-5') ?>
                            <div>This is your own account. You cannot deactivate it from here.</div>
                        </div>
                    <?php endif; ?>

                    <div class="ad-field">
                        <label class="sik-label" for="adminRole">Role <span class="req">*</span></label>
                        <select class="sik-select<?= isset($errors['role_id']) ? ' is-invalid' : '' ?>"
                                id="adminRole" name="role_id" required>
                            <?= admin_options($roleOptions, (string) ($account['role_id'] ?? ''), '— Select a role —') ?>
                        </select>
                        <?php if (isset($errors['role_id'])): ?>
                            <span class="sik-error"><?= e($errors['role_id']) ?></span>
                        <?php else: ?>
                            <span class="sik-help">
                                The role decides every permission.
                                <a href="<?= e(admin_url('admins/roles.php')) ?>">Manage roles</a>.
                                <?php if (!$canAssignSuper): ?>
                                    Only a Super Admin can hand out unrestricted access.
                                <?php endif; ?>
                            </span>
                        <?php endif; ?>
                    </div>

                    <div class="ad-field">
                        <label class="sik-label" for="adminStatus">Status <span class="req">*</span></label>
                        <select class="sik-select<?= isset($errors['status']) ? ' is-invalid' : '' ?>"
                                id="adminStatus" name="status" required>
                            <?= admin_options(ADMIN_ACCOUNT_STATUSES, (string) ($account['status'] ?? 'active')) ?>
                        </select>
                        <?php if (isset($errors['status'])): ?>
                            <span class="sik-error"><?= e($errors['status']) ?></span>
                        <?php else: ?>
                            <span class="sik-help">Inactive accounts are refused at the sign-in screen.</span>
                        <?php endif; ?>
                    </div>

                    <?php if ($isEdit && !empty($account['locked_until'])
                        && strtotime((string) $account['locked_until']) > time()): ?>
                        <label class="ad-switch">
                            <input type="checkbox" name="unlock" value="1">
                            <span class="ad-switch__track"></span>
                            <span>Clear the failed-login lockout</span>
                        </label>
                        <span class="sik-help" style="margin-top:-6px">
                            Locked until <?= e(format_datetime($account['locked_until'], 'd M Y, g:i A')) ?>
                            after <?= (int) MAX_LOGIN_ATTEMPTS ?> failed attempts.
                        </span>
                    <?php endif; ?>
                </div>

                <div class="ad-card__body" style="border-top:1px solid var(--ad-border);display:grid;gap:12px">
                    <?= admin_reauth_field(
                        $isEdit
                            ? 'change a password, email, role, status or lockout'
                            : 'create an admin account',
                        (string) ($errors['reauth_password'] ?? '')
                    ) ?>
                </div>

                <div class="ad-card__foot">
                    <a class="ad-btn" href="<?= e(admin_url('admins/')) ?>">Cancel</a>
                    <button type="submit" class="ad-btn ad-btn--primary">
                        <?= icon('check', 'w-4 h-4') ?>
                        <?= $isEdit ? 'Save Changes' : 'Create Admin' ?>
                    </button>
                </div>
            </div>

            <?php if ($isEdit): ?>
                <div class="ad-card" style="margin:0">
                    <div class="ad-card__head"><div class="ad-card__title">Account activity</div></div>
                    <div class="ad-card__body" style="display:grid;gap:10px;font-size:13px">
                        <div style="display:flex;justify-content:space-between;gap:10px">
                            <span class="ad-muted">Created</span>
                            <strong><?= e(format_date($account['created_at'] ?? null, 'd M Y')) ?></strong>
                        </div>
                        <div style="display:flex;justify-content:space-between;gap:10px">
                            <span class="ad-muted">Last login</span>
                            <strong><?= !empty($account['last_login_at'])
                                ? e(format_datetime($account['last_login_at'], 'd M Y, g:i A'))
                                : 'Never' ?></strong>
                        </div>
                        <div style="display:flex;justify-content:space-between;gap:10px">
                            <span class="ad-muted">Last IP</span>
                            <strong class="ad-mono"><?= e($account['last_login_ip'] ?? '—') ?></strong>
                        </div>
                        <?php if (admin_can('logs.view')): ?>
                            <a class="ad-btn ad-btn--sm ad-btn--block"
                               href="<?= e(admin_url('logs/activity.php?admin_id=' . (int) $account['id'])) ?>">
                                <?= icon('clock', 'w-4 h-4') ?> View activity log
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</form>
