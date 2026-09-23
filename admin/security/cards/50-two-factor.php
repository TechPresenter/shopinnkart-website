<?php
/**
 * Security card: two-step sign in (2FA).
 *
 * Returned to admin/security/settings.php, which renders the card and routes
 * this card's POST actions to the handlers below. See that file for the shape.
 *
 * ---------------------------------------------------------------------------
 * How the Super Admin who switches this on is stopped from locking everyone out
 * ---------------------------------------------------------------------------
 * A requirement that nobody can satisfy is the one security setting with no way
 * back through the browser, so five things guard it:
 *
 *  1. You cannot require it of anyone until you are using it yourself. That is
 *     not politeness - it proves the enrolment flow works on THIS install (the
 *     server clock, the application key, the QR renderer) before it becomes
 *     compulsory for people who are not in the room.
 *  2. A requirement never touches a session that is already open. It applies at
 *     the next sign-in, so the person who switched it on is not thrown out
 *     halfway through switching it on.
 *  3. An admin who is required but not enrolled is not refused - they are sent
 *     to a setup screen that shows the secret as text as well as a QR code, and
 *     they carry on from there.
 *  4. Ten backup codes, and a "reset this admin's second factor" below that any
 *     Super Admin can use on a colleague (logged, and emailed to the account).
 *  5. With shell access and no second Super Admin left,
 *     `php bin/reset-admin-2fa.php <email>` is the last resort.
 *
 * And if the application key becomes unreadable, mfa_required() suspends the
 * requirement and records it as critical rather than sealing the panel.
 */

declare(strict_types=1);

require_once ADMIN_PATH . '/includes/rbac.php';

/** Admins with their role and enrolment state, for the table and the guards. */
function security_card_2fa_admins(): array
{
    return Database::fetchAll(
        'SELECT a.`id`, a.`name`, a.`email`, a.`status`, a.`role_id`, a.`totp_enabled_at`,
                r.`name` AS role_name, r.`require_2fa`, r.`permissions`
           FROM `admins` a
           INNER JOIN `admin_roles` r ON r.`id` = a.`role_id`
          ORDER BY a.`status` ASC, a.`name` ASC'
    );
}

return [
    'key'    => 'two-factor',
    'order'  => 50,
    'column' => 'main',

    'actions' => [
        '2fa_policy_save' => static function (string $back): void {
            $admin  = admin_user();
            $policy = (string) input('sec_2fa_policy', 'optional');
            if (!in_array($policy, ['optional', 'required_super', 'required_all'], true)) {
                $policy = 'optional';
            }

            // Guard 1. Checked against the CURRENT row, not the session: an
            // admin who enrolled in another tab a minute ago should pass.
            $self = mfa_admin_row((int) $admin['id']);
            if ($policy !== 'optional' && ($self === null || !mfa_totp_enabled($self))) {
                flash('error', 'Set two-step sign in up on your own account first '
                    . '(Account menu > My Security). Requiring it of other people before you have '
                    . 'proved it works here is how a panel gets locked.');
                redirect($back . '#two-factor');
            }

            if (!app_key_available() && $policy !== 'optional') {
                flash('error', 'The application key is not readable, so new secrets cannot be stored. '
                    . 'Fix that before requiring two-step sign in, or nobody will be able to enrol.');
                redirect($back . '#two-factor');
            }

            $previous = mfa_policy();
            setting_save('sec_2fa_policy', $policy, 'security', 'text');
            setting_save('sec_2fa_customers', input_bool('sec_2fa_customers') ? '1' : '0', 'security', 'boolean');
            setting_save('sec_2fa_email_otp', input_bool('sec_2fa_email_otp') ? '1' : '0', 'security', 'boolean');
            setting_save('sec_2fa_trust_enabled', input_bool('sec_2fa_trust_enabled') ? '1' : '0', 'security', 'boolean');
            setting_save('sec_2fa_trust_days', (string) max(1, min(365, (int) input('sec_2fa_trust_days', 30))), 'security', 'number');
            admin_after_write();

            security_event('mfa.policy_changed', 'high',
                ['from' => $previous, 'to' => $policy], (int) $admin['id'], 'admin');
            log_activity('security.2fa_policy', 'settings', null, 'Set the two-step sign-in policy to ' . $policy);

            flash('success', $policy === 'optional'
                ? 'Saved. Nobody is required to use two-step sign in; anyone may still switch it on.'
                : 'Saved. Admins it applies to will be asked to set it up the next time they sign in.');
            redirect($back . '#two-factor');
        },

        '2fa_roles_save' => static function (string $back): void {
            $admin = admin_user();
            $self  = mfa_admin_row((int) $admin['id']);
            $want  = array_map('intval', (array) input('require_2fa', []));

            $changed = 0;
            foreach (Database::fetchAll('SELECT `id`, `name`, `require_2fa` FROM `admin_roles`') as $role) {
                $roleId = (int) $role['id'];
                $now    = (int) $role['require_2fa'] === 1;
                $next   = in_array($roleId, $want, true);

                if ($now === $next) {
                    continue;
                }
                // Guard 1 again, and "nobody manages up": you may only change a
                // role whose permissions you already hold.
                if ($next && ($self === null || !mfa_totp_enabled($self))) {
                    flash('error', 'Set two-step sign in up on your own account before requiring it of a role.');
                    redirect($back . '#two-factor');
                }
                if (!admin_can_manage_role($roleId)) {
                    admin_deny_back('You cannot change a role that grants more than your own.',
                        $back . '#two-factor', ['role_id' => $roleId]);
                }

                // Switching a requirement ON over admins who have not enrolled
                // does not lock them out - they are walked through setup at
                // their next sign-in - but it does mean somebody will be asked
                // for an authenticator app at the moment they were trying to
                // get work done, and the CLI hatch is the only way round it.
                // So it is a confirmation, not a refusal, and it says how many.
                if ($next) {
                    $unenrolled = (int) Database::fetchColumn(
                        // Enrolled means what mfa_totp_enabled() means: a secret AND
                        // a completed verification.
                        "SELECT COUNT(*) FROM `admins` WHERE `role_id` = :r AND `status` = 'active'
                          AND (`totp_enabled_at` IS NULL OR `totp_secret` IS NULL OR `totp_secret` = '')",
                        ['r' => $roleId]
                    );
                    if ($unenrolled > 0 && !input_bool('force_2fa_roles')) {
                        flash('error', $unenrolled . ' admin(s) on "' . $role['name'] . '" have not set two-step sign in up yet. '
                            . 'They will be made to set it up before they can work. Tick the confirmation and save again '
                            . 'to go ahead, or ask them to enrol first.');
                        redirect($back . '#two-factor');
                    }
                }

                Database::update('admin_roles', ['require_2fa' => $next ? 1 : 0], '`id` = :id', ['id' => $roleId]);
                security_event('mfa.role_requirement_changed', 'high',
                    ['role' => (string) $role['name'], 'required' => $next], (int) $admin['id'], 'admin');
                $changed++;
            }

            admin_after_write();
            log_activity('security.2fa_roles', 'settings', null, 'Changed which roles require two-step sign in');
            flash('success', $changed === 0 ? 'Nothing to change.' : 'Saved for ' . $changed . ' role(s).');
            redirect($back . '#two-factor');
        },

        '2fa_reset' => static function (string $back): void {
            $actor  = admin_user();
            $target = mfa_admin_row((int) input('admin_id', 0));

            if ($target === null) {
                flash('error', 'That admin no longer exists.');
                redirect($back . '#two-factor');
            }
            if ((int) $target['id'] === (int) $actor['id']) {
                // Otherwise the password alone would remove the second factor,
                // which is exactly the attack it exists to stop. Your own is
                // removed from My Security, with a code.
                flash('error', 'Remove your own second factor from Account menu > My Security, '
                    . 'where it also asks for a current code.');
                redirect($back . '#two-factor');
            }
            if (!admin_can_manage_admin($target)) {
                admin_deny_back('You cannot manage an admin whose role grants more than your own.',
                    $back . '#two-factor', ['target' => (int) $target['id']]);
            }
            if (!admin_reauth_ok('reset_password_' . (int) $target['id'])) {
                flash_errors(['reset_password_' . (int) $target['id'] => admin_reauth_error('reset a colleague\'s second factor')]);
                flash('error', 'Nothing was reset.');
                redirect($back . '#two-factor');
            }

            mfa_disable('admin', $target, 'admin');
            security_event('mfa.reset_by_admin', 'critical', [
                'target' => (int) $target['id'],
                'email'  => mask_email((string) $target['email']),
            ], (int) $actor['id'], 'admin');
            log_activity('security.2fa_reset', 'admin', (int) $target['id'],
                'Reset two-step sign in for ' . $target['name']);

            flash('success', $target['name'] . ' can sign in with their password alone now, and will be '
                . 'asked to set two-step sign in up again if their role requires it. They have been emailed.');
            redirect($back . '#two-factor');
        },
    ],

    'render' => static function (array $errors, bool $canEdit): void {
        $policy   = mfa_policy();
        $admins   = security_card_2fa_admins();
        $roles    = Database::fetchAll('SELECT `id`, `name`, `require_2fa` FROM `admin_roles` WHERE `status` = "active" ORDER BY `name`');
        $me       = admin_user();
        $selfRow  = mfa_admin_row((int) $me['id']);
        $selfOn   = $selfRow !== null && mfa_totp_enabled($selfRow);
        $without  = array_values(array_filter($admins, static fn (array $a): bool
            => $a['status'] === 'active' && $a['totp_enabled_at'] === null));

        // Customers whose ONLY second factor is the emailed code. They are the
        // ones the two checkboxes below can silently disarm, so the count is
        // shown beside them rather than left for somebody to discover.
        try {
            $emailOnly = (int) Database::fetchColumn(
                'SELECT COUNT(*) FROM `users`
                  WHERE `mfa_method` = :m AND `status` = :s AND `totp_enabled_at` IS NULL',
                ['m' => 'email', 's' => 'active']
            );
        } catch (Throwable $e) {
            $emailOnly = 0;
        }
        ?>
        <div class="ad-card" style="margin:0" id="two-factor">
            <div class="ad-card__head">
                <div>
                    <h2 class="ad-card__title">Two-step sign in</h2>
                    <div class="ad-card__sub">
                        A code from an authenticator app on top of the password, so a leaked or
                        guessed password is not enough on its own.
                    </div>
                </div>
                <?= $policy === 'optional'
                    ? '<span class="sik-status sik-status--amber">Optional</span>'
                    : '<span class="sik-status sik-status--green">'
                        . ($policy === 'required_all' ? 'Required for all admins' : 'Required for Super Admins')
                        . '</span>' ?>
            </div>

            <div class="ad-card__body" style="display:grid;gap:18px">

                <?php if (!$selfOn): ?>
                    <div class="sik-alert sik-alert--info">
                        <?= icon('info', 'w-5 h-5') ?>
                        <div>
                            You are not using two-step sign in yet, so you cannot require it of anyone else.
                            Set it up on
                            <a href="<?= e(admin_url('account/index.php')) ?>"><strong>My Security</strong></a>
                            first &mdash; that is the check that stops a requirement nobody can satisfy.
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!app_key_available()): ?>
                    <div class="sik-alert sik-alert--error">
                        <?= icon('alert', 'w-5 h-5') ?>
                        <div>The application key cannot be read, so new secrets cannot be stored.
                             Two-step sign in cannot be required until that is fixed.</div>
                    </div>
                <?php endif; ?>

                <?php if ($canEdit): ?>
                    <form method="post" class="ad-form" style="display:grid;gap:14px">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="2fa_policy_save">

                        <div class="ad-field">
                            <label class="sik-label" for="sec_2fa_policy">Who has to use it</label>
                            <select class="sik-input" id="sec_2fa_policy" name="sec_2fa_policy"<?= $selfOn ? '' : ' disabled' ?>>
                                <option value="optional"<?= $policy === 'optional' ? ' selected' : '' ?>>
                                    Nobody is required &mdash; anyone may switch it on
                                </option>
                                <option value="required_super"<?= $policy === 'required_super' ? ' selected' : '' ?>>
                                    Super Admins must use it
                                </option>
                                <option value="required_all"<?= $policy === 'required_all' ? ' selected' : '' ?>>
                                    Every admin must use it
                                </option>
                            </select>
                            <span class="sik-help">
                                Nothing happens to sessions that are already open. An admin it applies to is
                                taken to a setup screen the next time they sign in, and cannot go anywhere
                                else until they finish.
                            </span>
                        </div>

                        <div class="ad-field">
                            <span class="sik-label">Customers</span>
                            <label class="sik-check">
                                <input type="checkbox" name="sec_2fa_customers" value="1"<?= mfa_customers_enabled() ? ' checked' : '' ?>>
                                <span>Let customers protect their account with an authenticator app</span>
                            </label>
                            <label class="sik-check">
                                <input type="checkbox" name="sec_2fa_email_otp" value="1"<?= mfa_email_otp_enabled() ? ' checked' : '' ?>>
                                <span>Offer a six-digit code by email as the customer fallback</span>
                            </label>
                            <span class="sik-help">
                                Customers are never forced. The emailed code is a fallback for shoppers who
                                have no authenticator app; admins do not get it, because an admin's inbox is
                                usually where the password reset lands too.
                            </span>
                            <?php if ($emailOnly > 0): ?>
                                <!-- Unticking either box takes the second factor off these accounts
                                     altogether - they have no authenticator app to fall back to, so
                                     their password becomes the only thing guarding them. That is a
                                     reasonable choice when the mail is broken, but not a silent one. -->
                                <div class="sik-alert sik-alert--warning" style="margin-top:10px">
                                    <?= icon('alert', 'w-5 h-5') ?>
                                    <div>
                                        <strong><?= (int) $emailOnly ?></strong>
                                        customer<?= $emailOnly === 1 ? '' : 's' ?>
                                        use<?= $emailOnly === 1 ? 's' : '' ?> the emailed code as their only
                                        second factor. Unticking either box above leaves
                                        <?= $emailOnly === 1 ? 'that account' : 'those accounts' ?>
                                        on the password alone &mdash; they have no app to fall back to.
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="ad-field">
                            <span class="sik-label">Trusted browsers</span>
                            <label class="sik-check">
                                <input type="checkbox" name="sec_2fa_trust_enabled" value="1"<?= mfa_trust_enabled() ? ' checked' : '' ?>>
                                <span>Let people tick &ldquo;trust this browser&rdquo; after passing the second step</span>
                            </label>
                            <div style="display:flex;align-items:center;gap:8px;margin-top:8px">
                                <input class="sik-input" type="number" name="sec_2fa_trust_days" min="1" max="365"
                                       style="width:100px" value="<?= (int) mfa_trust_days() ?>">
                                <span style="font-size:13px">days before that browser is asked again</span>
                            </div>
                            <span class="sik-help">
                                A trust is recorded per browser and can be revoked from My Security. It dies
                                on any password change, and &ldquo;keep me signed in&rdquo; does not replace it:
                                a remembered browser still proves the second factor once.
                            </span>
                        </div>

                        <div>
                            <button type="submit" class="ad-btn ad-btn--primary">
                                <?= icon('lock', 'w-4 h-4') ?> Save
                            </button>
                        </div>
                    </form>

                    <!-- per-role requirement -->
                    <form method="post" class="ad-form" style="display:grid;gap:10px;border-top:1px solid var(--ad-border,#e5e7eb);padding-top:16px">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="2fa_roles_save">
                        <span class="sik-label">Roles that must use it, whatever the setting above says</span>
                        <div style="display:grid;gap:6px">
                            <?php foreach ($roles as $role): ?>
                                <label class="sik-check">
                                    <input type="checkbox" name="require_2fa[]" value="<?= (int) $role['id'] ?>"
                                        <?= (int) $role['require_2fa'] === 1 ? ' checked' : '' ?>
                                        <?= $selfOn && admin_can_manage_role((int) $role['id']) ? '' : ' disabled' ?>>
                                    <span><?= e((string) $role['name']) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <label class="sik-check">
                            <input type="checkbox" name="force_2fa_roles" value="1"<?= $selfOn ? '' : ' disabled' ?>>
                            <span>Go ahead even if admins on that role have not enrolled yet</span>
                        </label>
                        <div>
                            <button type="submit" class="ad-btn ad-btn--sm"<?= $selfOn ? '' : ' disabled' ?>>Save roles</button>
                        </div>
                        <span class="sik-help">
                            A role you could not create yourself is shown but cannot be changed &mdash;
                            the same &ldquo;nobody manages up&rdquo; rule the rest of the panel uses.
                            Without the confirmation above, a requirement will not be switched on over admins
                            who have not enrolled; they are not locked out, but they are made to set it up
                            before they can work, and <code>php bin/reset-admin-2fa.php &lt;email&gt;</code> is
                            the only way round it.
                        </span>
                    </form>
                <?php endif; ?>

                <!-- who is enrolled -->
                <div style="border-top:1px solid var(--ad-border,#e5e7eb);padding-top:16px">
                    <div style="display:flex;justify-content:space-between;align-items:baseline;gap:10px;flex-wrap:wrap">
                        <strong style="font-size:13.5px">Who is using it</strong>
                        <span class="ad-muted" style="font-size:12.5px">
                            <?= count($admins) - count($without) ?> of <?= count($admins) ?> admins enrolled
                        </span>
                    </div>

                    <div class="ad-tablewrap" style="margin-top:10px">
                        <table class="ad-table">
                            <thead><tr><th>Admin</th><th>Role</th><th>Two-step</th><th></th></tr></thead>
                            <tbody>
                            <?php foreach ($admins as $row): ?>
                                <?php $on = $row['totp_enabled_at'] !== null; ?>
                                <tr>
                                    <td>
                                        <?= e((string) $row['name']) ?>
                                        <div class="ad-muted" style="font-size:12px"><?= e((string) $row['email']) ?></div>
                                    </td>
                                    <td>
                                        <?= e((string) $row['role_name']) ?>
                                        <?php if ((int) $row['require_2fa'] === 1): ?>
                                            <span class="sik-status sik-status--blue">required</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?= $on
                                            ? '<span class="sik-status sik-status--green">On</span>'
                                            : '<span class="sik-status sik-status--amber">Off</span>' ?>
                                    </td>
                                    <td style="text-align:right">
                                        <?php if ($on && $canEdit && (int) $row['id'] !== (int) $me['id'] && admin_can_manage_admin($row)): ?>
                                            <details>
                                                <summary style="cursor:pointer;font-size:12.5px">Reset</summary>
                                                <form method="post" style="display:grid;gap:8px;margin-top:8px;min-width:230px">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="2fa_reset">
                                                    <input type="hidden" name="admin_id" value="<?= (int) $row['id'] ?>">
                                                    <input class="sik-input" type="password"
                                                           name="reset_password_<?= (int) $row['id'] ?>"
                                                           placeholder="Your own password" autocomplete="current-password">
                                                    <button type="submit" class="ad-btn ad-btn--sm ad-btn--danger"
                                                            data-confirm="Reset two-step sign in for <?= e_attr((string) $row['name']) ?>? They will be emailed.">
                                                        Reset their second factor
                                                    </button>
                                                </form>
                                            </details>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <details style="font-size:13px">
                    <summary style="cursor:pointer;font-weight:600">If somebody is locked out</summary>
                    <div style="display:grid;gap:8px;margin-top:8px">
                        <p>Everyone who enrols gets ten single-use backup codes, shown once. That is the
                           first answer, and the one that needs nobody else.</p>
                        <p>After that, any Super Admin can reset a colleague's second factor with the
                           <strong>Reset</strong> button above. It asks for your own password, is written to
                           the security log, and the account is emailed &mdash; a silent reset would be a
                           takeover with a button.</p>
                        <p>With no second Super Admin left, on the server run
                           <code class="ad-mono">php bin/reset-admin-2fa.php &lt;email&gt;</code>.
                           Shell access is the owner's, so that is the floor.</p>
                        <p><strong>Text messages are not an option here and the store does not pretend
                           otherwise.</strong> There is no SMS provider configured, and sending one in India
                           needs a DLT-registered sender ID and template per message. Adding SMS is a
                           purchase and a registration, not a setting.</p>
                    </div>
                </details>
            </div>
        </div>
        <?php
    },
];
