<?php
/**
 * Security card: installer status and the application key.
 *
 * Returned to admin/security/settings.php, which renders the card and routes
 * this card's POST actions to the handlers below. See that file for the shape.
 *
 * Three facts about the deployment itself rather than settings to change: is
 * the web installer still sitting in the document root, can this copy store a
 * secret at all, and can anybody who has read the project's public repository
 * simply sign in.
 *
 * Why the installer line is short on screen: install.php checks the database
 * itself rather than a lock file, so a leftover copy cannot reset the
 * administrator account, rewrite the database credentials or clear the
 * catalogue. That is why the card says "delete it" without alarm - the
 * reassurance is here, where it does not cost the operator a paragraph.
 */

declare(strict_types=1);

require_once INCLUDES_PATH . '/deployment-checks.php';

return [
    'key'    => 'installer',
    'order'  => 42,
    'column' => 'side',

    'actions' => [],

    'render' => static function (array $errors, bool $canEdit): void {
        $installer = security_installer_status();
        $keyOk     = app_key_available();
        $seeded    = deployment_seeded_logins();
        $seededMsg = deployment_seeded_logins_message($seeded);
        $allClear  = !$installer['present'] && $keyOk && $seededMsg === null;
        ?>
        <div class="ad-card" style="margin:0" id="installer">
            <div class="ad-card__head">
                <div>
                    <h2 class="ad-card__title">Deployment</h2>
                    <div class="ad-card__sub">Leftovers from setting the store up.</div>
                </div>
                <?= $allClear
                    ? '<span class="sik-status sik-status--green">Clean</span>'
                    : '<span class="sik-status sik-status--amber">Needs attention</span>' ?>
            </div>

            <div class="ad-card__body" style="display:grid;gap:14px">
                <div>
                    <div style="display:flex;gap:8px;align-items:center;font-weight:600">
                        <?= $seededMsg === null
                            ? '<span class="sik-status sik-status--green">Changed</span>'
                            : '<span class="sik-status sik-status--red">Still the shipped one</span>' ?>
                        <span>Sign-in password</span>
                    </div>
                    <div class="ad-muted" style="font-size:13px;margin-top:6px;line-height:1.7">
                        <?php if ($seededMsg === null): ?>
                            No account still uses a README password.
                        <?php else: ?>
                            <?= e($seededMsg) ?>
                            <?php if ($seeded['admins'] !== [] && admin_can('admins.edit')): ?>
                                <div style="margin-top:8px">
                                    <a class="ad-btn ad-btn--danger ad-btn--sm"
                                       href="<?= e(admin_url('admins/')) ?>">Change it now</a>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div>
                    <div style="display:flex;gap:8px;align-items:center;font-weight:600">
                        <?= $installer['present']
                            ? '<span class="sik-status sik-status--amber">Present</span>'
                            : '<span class="sik-status sik-status--green">Deleted</span>' ?>
                        <span>install.php</span>
                    </div>
                    <div class="ad-muted" style="font-size:13px;margin-top:6px;line-height:1.7">
                        <?php if (!$installer['present']): ?>
                            Deleted from the server.
                        <?php else: ?>
                            Still in the document root. It refuses to run, but delete it.
                        <?php endif; ?>
                    </div>
                </div>

                <div>
                    <div style="display:flex;gap:8px;align-items:center;font-weight:600">
                        <?= $keyOk
                            ? '<span class="sik-status sik-status--green">Ready</span>'
                            : '<span class="sik-status sik-status--red">Missing</span>' ?>
                        <span>Application key</span>
                    </div>
                    <div class="ad-muted" style="font-size:13px;margin-top:6px;line-height:1.7">
                        <?php if ($keyOk): ?>
                            Encrypted with <code class="ad-mono">config/app.key.php</code>. Back it up, or
                            every saved secret is lost.
                        <?php else: ?>
                            <strong>This copy cannot store secrets.</strong>
                            A saved SMTP password or courier secret will not stick. Make
                            <code class="ad-mono">config/</code> writable once, load any page, then set it
                            back to read-only.
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php
    },
];
