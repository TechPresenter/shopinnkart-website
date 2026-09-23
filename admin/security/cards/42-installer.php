<?php
/**
 * Security card: installer status and the application key.
 *
 * Returned to admin/security/settings.php, which renders the card and routes
 * this card's POST actions to the handlers below. See that file for the shape.
 *
 * Two facts about the deployment itself rather than settings to change: is the
 * web installer still sitting in the document root, and can this copy store a
 * secret at all.
 */

declare(strict_types=1);

return [
    'key'    => 'installer',
    'order'  => 42,
    'column' => 'side',

    'actions' => [],

    'render' => static function (array $errors, bool $canEdit): void {
        $installer = security_installer_status();
        $keyOk     = app_key_available();
        $allClear  = !$installer['present'] && $keyOk;
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
                        <?= $installer['present']
                            ? '<span class="sik-status sik-status--amber">Present</span>'
                            : '<span class="sik-status sik-status--green">Deleted</span>' ?>
                        <span>install.php</span>
                    </div>
                    <div class="ad-muted" style="font-size:13px;margin-top:6px;line-height:1.7">
                        <?php if (!$installer['present']): ?>
                            The installer has been deleted from the server. Nothing to do.
                        <?php else: ?>
                            It is still in the document root. It refuses to run - it checks the database
                            itself, not just a lock file, so it cannot reset your administrator account,
                            rewrite the database credentials or clear the catalogue. Even so, delete
                            <code class="ad-mono">install.php</code> from the server: nothing on a live
                            store needs it.
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
                            Stored SMTP passwords and courier API secrets are encrypted with a key unique
                            to this installation (<code class="ad-mono">config/app.key.php</code>).
                            Back that file up: without it, every saved secret has to be re-entered.
                        <?php else: ?>
                            <strong>This copy cannot store secrets.</strong>
                            <code class="ad-mono">config/</code> is not writable and no
                            <code class="ad-mono">SIK_APP_KEY</code> is set, so there is nowhere to keep the
                            encryption key. Rather than fall back to a value an attacker could work out,
                            the app refuses: saving an SMTP password or a courier secret will not stick.
                            Make <code class="ad-mono">config/</code> writable once, load any page, then
                            set it back to read-only.
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php
    },
];
