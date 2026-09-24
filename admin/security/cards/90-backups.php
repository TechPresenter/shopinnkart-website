<?php
/**
 * Security card: where backups go, how many are kept, and what protects them.
 *
 * Returned to admin/security/settings.php, which renders the card and routes
 * this card's POST actions to the handlers below. See that file for the shape.
 *
 * The backup screen itself lives at System > Backup and needs system.backup.
 * What is here is the configuration a backup depends on and that nobody
 * should have to edit a file to change: the folder (ideally above the
 * document root), the retention count, and the passphrase that decides
 * whether a dump survives losing config/app.key.php.
 */

declare(strict_types=1);

require_once INCLUDES_PATH . '/backup.php';

return [
    'key'    => 'backups',
    'order'  => 90,
    'column' => 'main',

    'actions' => [
        'backups_save' => static function (string $back): void {
            $errors = [];

            $path = trim((string) input('sec_backup_path', ''));
            if ($path !== '') {
                if (!backup_path_absolute($path)) {
                    $errors['sec_backup_path'] = 'Give the full path, starting from the root of the disk '
                        . '(/home/you/backups or C:/backups). A relative path means something different '
                        . 'to cron than it does to the web server.';
                } elseif (!backup_dir_usable($path)) {
                    $errors['sec_backup_path'] = 'That folder cannot be created or written to by PHP. '
                        . 'Check the path and its permissions.';
                }
            }

            $keep = (int) input('sec_backup_keep', 7);
            if ($keep < 0 || $keep > 365) {
                $errors['sec_backup_keep'] = 'Enter a number between 0 and 365. 0 keeps every backup.';
            }

            $hours = (int) input('sec_backup_alert_hours', 48);
            if ($hours < 1 || $hours > 720) {
                $errors['sec_backup_alert_hours'] = 'Enter a number of hours between 1 and 720.';
            }

            $passphrase = (string) input('sec_backup_passphrase', '');
            $clear      = (string) input('passphrase_clear', '') === '1';
            if ($passphrase !== '' && mb_strlen($passphrase) < 12) {
                $errors['sec_backup_passphrase'] = 'A backup passphrase has to be at least 12 characters.';
            }
            if ($passphrase !== '' && !app_key_available()) {
                $errors['sec_backup_passphrase'] = 'The passphrase cannot be stored: this copy has no '
                    . 'application key to encrypt it with, so it would sit in the settings table in the clear.';
            }

            if ($errors !== []) {
                flash_errors($errors);
                flash_old($_POST);
                flash('error', 'The backup settings were not changed.');
                redirect($back);
            }

            setting_save('sec_backup_path', $path, 'security', 'text');
            setting_save('sec_backup_keep', (string) $keep, 'security', 'number');
            setting_save('sec_backup_alert_hours', (string) $hours, 'security', 'number');

            // Write-only. An empty box means "leave it alone", or the
            // passphrase would be wiped every time the folder was edited.
            if ($clear) {
                setting_save('sec_backup_passphrase', '', 'security', 'text');
            } elseif ($passphrase !== '') {
                setting_save('sec_backup_passphrase', secret_encrypt($passphrase), 'security', 'text');
            }

            admin_after_write();

            log_activity('security.backups', 'settings', null, 'Changed the backup settings');
            security_event('settings.backups', 'high', [
                'path'              => $path,
                'keep'              => $keep,
                'passphrase_set'    => $passphrase !== '',
                'passphrase_cleared' => $clear,
            ], admin_id(), 'admin');

            flash('success', $passphrase !== ''
                ? 'Saved. New backups will use the passphrase. Backups already on disk still need '
                  . 'whatever protected them when they were taken.'
                : 'Saved.');
            redirect($back);
        },
    ],

    'render' => static function (array $errors, bool $canEdit): void {
        try {
            $status = backup_dir_status();
        } catch (Throwable $e) {
            $status = ['path' => STORAGE_PATH . '/backups', 'writable' => false,
                       'outside_web_root' => false, 'custom' => false, 'custom_requested' => ''];
        }

        $source = backup_passphrase_source();
        $latest = null;
        $count  = 0;
        try {
            $latest = Database::fetchColumn('SELECT `created_at` FROM `backups` ORDER BY `id` DESC LIMIT 1');
            $count  = Database::count('backups');
        } catch (Throwable $e) {
            // The backup table is System > Backup's business; never fail here.
        }

        $hours = max(1, setting_int('sec_backup_alert_hours', 48));
        $stale = $latest === null || strtotime((string) $latest) < time() - ($hours * 3600);
        ?>
        <div class="ad-card" style="margin:0" id="backups">
            <div class="ad-card__head">
                <div>
                    <h2 class="ad-card__title">Backups</h2>
                </div>
                <span class="sik-status sik-status--<?= $stale ? 'amber' : 'green' ?>">
                    <?= $latest === null ? 'None yet' : e(time_ago($latest)) ?>
                </span>
            </div>

            <div class="ad-card__body" style="display:grid;gap:14px">
                <?php if ($stale): ?>
                    <div class="sik-alert sik-alert--warning">
                        <?= icon('alert', 'w-5 h-5') ?>
                        <div>
                            <strong><?= $latest === null
                                ? 'No backup has ever been taken.'
                                : 'The last backup was ' . e(time_ago($latest)) . '.' ?></strong>
                            Put it on a nightly cron:
                            <code class="ad-mono">php <?= e(ROOT_PATH) ?>/bin/backup.php --quiet</code>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!$status['outside_web_root']): ?>
                    <div class="sik-alert sik-alert--info">
                        <?= icon('info', 'w-5 h-5') ?>
                        <div>
                            <?php // An .htaccess rule keeps the folder from being served; the
                                  // warning is about where it sits, not about that rule. ?>
                            Dumps sit in <code class="ad-mono"><?= e($status['path']) ?></code>. Above
                            <code class="ad-mono">public_html</code> is safer.
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($canEdit): ?>
                    <form method="post" class="ad-form" style="display:grid;gap:12px">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="backups_save">

                        <div class="ad-field">
                            <label class="sik-label" for="sec_backup_path">Backups folder</label>
                            <input class="sik-input<?= isset($errors['sec_backup_path']) ? ' is-invalid' : '' ?>"
                                   type="text" id="sec_backup_path" name="sec_backup_path"
                                   value="<?= e_attr((string) (old('sec_backup_path', null) ?? setting('sec_backup_path', ''))) ?>"
                                   placeholder="<?= e_attr(STORAGE_PATH . '/backups') ?>">
                            <?php if (isset($errors['sec_backup_path'])): ?>
                                <span class="sik-error"><?= e($errors['sec_backup_path']) ?></span>
                            <?php else: ?>
                                <span class="sik-help">
                                    Full path. Empty means <code class="ad-mono">storage/backups</code>.
                                </span>
                            <?php endif; ?>
                        </div>

                        <div class="ad-field">
                            <label class="sik-label" for="sec_backup_keep">Backups kept on disk</label>
                            <input class="sik-input<?= isset($errors['sec_backup_keep']) ? ' is-invalid' : '' ?>"
                                   style="max-width:140px" type="number" min="0" max="365" step="1"
                                   id="sec_backup_keep" name="sec_backup_keep"
                                   value="<?= e_attr((string) (old('sec_backup_keep', null) ?? backup_keep())) ?>">
                            <?php if (isset($errors['sec_backup_keep'])): ?>
                                <span class="sik-error"><?= e($errors['sec_backup_keep']) ?></span>
                            <?php else: ?>
                                <span class="sik-help">
                                    0 keeps every one and fills the disk. <?= number_format($count) ?> stored now.
                                </span>
                            <?php endif; ?>
                        </div>

                        <div class="ad-field">
                            <label class="sik-label" for="sec_backup_alert_hours">Warn when the newest backup is older than</label>
                            <div style="display:flex;gap:8px;align-items:center">
                                <input class="sik-input<?= isset($errors['sec_backup_alert_hours']) ? ' is-invalid' : '' ?>"
                                       style="max-width:120px" type="number" min="1" max="720" step="1"
                                       id="sec_backup_alert_hours" name="sec_backup_alert_hours"
                                       value="<?= e_attr((string) (old('sec_backup_alert_hours', null) ?? $hours)) ?>">
                                <span class="ad-muted">hours</span>
                            </div>
                            <?php if (isset($errors['sec_backup_alert_hours'])): ?>
                                <span class="sik-error"><?= e($errors['sec_backup_alert_hours']) ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="ad-field">
                            <label class="sik-label" for="sec_backup_passphrase">Backup passphrase</label>
                            <input class="sik-input<?= isset($errors['sec_backup_passphrase']) ? ' is-invalid' : '' ?>"
                                   style="max-width:360px" type="password" id="sec_backup_passphrase"
                                   name="sec_backup_passphrase" autocomplete="new-password" minlength="12"
                                   placeholder="<?= $source === 'none' ? 'Not set - dumps use the application key' : 'Set - type a new one to change it' ?>">
                            <?php if (isset($errors['sec_backup_passphrase'])): ?>
                                <span class="sik-error"><?= e($errors['sec_backup_passphrase']) ?></span>
                            <?php endif; ?>
                            <?php if ($source === 'env'): ?>
                                <span class="sik-help">
                                    Set as <code class="ad-mono">SIK_BACKUP_PASSPHRASE</code>, which wins over
                                    anything typed here.
                                </span>
                            <?php else: ?>
                                <span class="sik-help">Changing it never re-encrypts older dumps.</span>
                            <?php endif; ?>

                            <?php // Outside the branch on purpose. It sat in the `else` and so was
                                  // hidden from the one operator who most needs it: a passphrase
                                  // held in SIK_BACKUP_PASSPHRASE is the easiest of the three to
                                  // lose, because it lives in a host's environment rather than in
                                  // the project, and moving host is exactly when it goes missing. ?>
                            <div class="sik-alert sik-alert--warning" style="margin-top:8px">
                                <?= icon('alert', 'w-5 h-5') ?>
                                <div>
                                    <strong>Neither key can be recovered.</strong>
                                    Lose <code class="ad-mono">config/app.key.php</code>, or forget the
                                    passphrase, and every backup is noise.
                                </div>
                            </div>
                            <?php if ($source === 'settings'): ?>
                                <label style="display:flex;gap:8px;align-items:center;margin-top:8px">
                                    <input type="checkbox" name="passphrase_clear" value="1">
                                    <span>Remove the stored passphrase and go back to the application key</span>
                                </label>
                            <?php endif; ?>
                        </div>

                        <div>
                            <button type="submit" class="ad-btn ad-btn--primary">
                                <?= icon('check', 'w-4 h-4') ?> Save
                            </button>
                            <?php if (admin_can('system.backup')): ?>
                                <a class="ad-btn" href="<?= e(admin_url('system/backup.php')) ?>">
                                    Backup screen
                                </a>
                            <?php endif; ?>
                        </div>
                    </form>
                <?php else: ?>
                    <p class="ad-muted" style="font-size:13px">
                        Writing to <code class="ad-mono"><?= e($status['path']) ?></code>, keeping
                        <?= backup_keep() > 0 ? backup_keep() : 'every' ?> backup,
                        protected by <?= $source === 'none' ? 'the application key' : 'a passphrase' ?>.
                    </p>
                <?php endif; ?>

                <details style="font-size:13px">
                    <summary style="cursor:pointer;font-weight:600">What the passphrase changes</summary>
                    <div style="display:grid;gap:8px;margin-top:8px">
                        <p>With no passphrase, dumps are encrypted with
                           <code class="ad-mono">config/app.key.php</code>: nothing to remember, but lose
                           that file and every backup on disk is noise.</p>
                        <p>With one, a dump can be restored anywhere by anyone who knows it &mdash; which is
                           exactly the case a backup exists for. Nobody, including us, can recover it for
                           you if you forget it.</p>
                        <p>Typed here it is encrypted with the application key, but it is still
                           <em>in the database it protects a copy of</em>. Set
                           <code class="ad-mono">SIK_BACKUP_PASSPHRASE</code> in the environment instead if
                           your host allows it: stealing the database then does not hand over the backups
                           as well.</p>
                        <p>Changing it never re-encrypts what is already on disk. An older dump still needs
                           whatever protected it the day it was written.</p>
                    </div>
                </details>
            </div>
        </div>
        <?php
    },
];
