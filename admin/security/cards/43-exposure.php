<?php
/**
 * Security card: "can the internet download my source code?" self-test.
 *
 * Returned to admin/security/settings.php, which renders the card and routes
 * this card's POST actions to the handlers below. See that file for the shape.
 *
 * .htaccess denies .git, config/, the SQL dumps and the rest - but only if the
 * host honours .htaccess. Reading the rules off disk proves nothing, so this
 * asks the live site over HTTP, the same way an attacker would. It was exactly
 * this that found /.git/config being served here: the old dotfile rule matched
 * only the last part of a path, so everything inside .git was public.
 *
 * The probe runs on demand, never on page load: it is a dozen HTTP requests
 * to our own site and it has no business firing every time someone opens the
 * security screen.
 */

declare(strict_types=1);

return [
    'key'    => 'exposure',
    'order'  => 43,
    'column' => 'side',

    'actions' => [
        'exposure_scan' => static function (string $back): void {
            $result = security_exposure_probe();

            $_SESSION['sec_exposure_result'] = [
                'at'      => time(),
                'checked' => $result['checked'],
                'exposed' => $result['exposed'],
                'errors'  => $result['errors'],
            ];

            if ($result['exposed'] !== []) {
                security_event('platform.exposed_files', 'critical', [
                    'paths' => array_column($result['exposed'], 'path'),
                ], admin_id(), 'admin');
                flash('error', count($result['exposed']) . ' file(s) can be downloaded from your site. See the list below.');
            } elseif ($result['checked'] === 0) {
                flash('error', 'The site could not reach itself over HTTP. Check that ' . SITE_URL . ' is correct.');
            } else {
                flash('success', 'Nothing sensitive was downloadable.');
            }

            redirect($back);
        },
    ],

    'render' => static function (array $errors, bool $canEdit): void {
        $result = $_SESSION['sec_exposure_result'] ?? null;
        unset($_SESSION['sec_exposure_result']);
        $paths = security_exposure_paths();
        ?>
        <div class="ad-card" style="margin:0" id="exposure">
            <div class="ad-card__head">
                <div>
                    <h2 class="ad-card__title">Exposed-file check</h2>
                    <div class="ad-card__sub">Ask the live site for the files nobody should be able to download.</div>
                </div>
                <?php if (is_array($result)): ?>
                    <?= $result['exposed'] === []
                        ? '<span class="sik-status sik-status--green">All denied</span>'
                        : '<span class="sik-status sik-status--red">' . (int) count($result['exposed']) . ' exposed</span>' ?>
                <?php endif; ?>
            </div>

            <div class="ad-card__body" style="display:grid;gap:14px">
                <?php if (is_array($result) && $result['exposed'] !== []): ?>
                    <div class="sik-alert sik-alert--error">
                        <?= icon('alert-triangle', 'w-5 h-5') ?>
                        <div>
                            These answered <strong>200 OK</strong> to an anonymous request:
                            <ul style="margin:8px 0 0 18px">
                                <?php foreach ($result['exposed'] as $row): ?>
                                    <li><code class="ad-mono"><?= e($row['path']) ?></code> (<?= (int) $row['bytes'] ?> bytes)</li>
                                <?php endforeach; ?>
                            </ul>
                            <p style="margin:8px 0 0">
                                Your host is ignoring <code>.htaccess</code>, or the folder was uploaded without it.
                                Delete <code>.git</code> from the server, and ask your host to enable
                                <code>AllowOverride All</code> for the site.
                            </p>
                        </div>
                    </div>
                <?php elseif (is_array($result)): ?>
                    <div class="sik-alert sik-alert--success">
                        <?= icon('check', 'w-5 h-5') ?>
                        <div><?= (int) $result['checked'] ?> path(s) checked, none downloadable.</div>
                    </div>
                <?php endif; ?>

                <?php if (is_array($result) && $result['errors'] !== []): ?>
                    <div class="ad-muted" style="font-size:12.5px">
                        Could not reach: <?= e(implode(', ', array_slice($result['errors'], 0, 5))) ?>
                    </div>
                <?php endif; ?>

                <div class="ad-muted" style="font-size:13px;line-height:1.7">
                    Checks <?= (int) count($paths) ?> paths on <code class="ad-mono"><?= e(rtrim(SITE_URL, '/')) ?></code>,
                    including <code>.git/config</code>, <code>config/db.local.php</code>,
                    <code>database/schema.sql</code> and <code>vendor/</code>.
                </div>

                <?php if ($canEdit): ?>
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="exposure_scan">
                        <button type="submit" class="ad-btn">
                            <?= icon('search', 'w-4 h-4') ?> Run the check
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
        <?php
    },
];
