<?php
/**
 * Security card: how long the store keeps what it has already collected.
 *
 * Returned to admin/security/settings.php, which renders the card and routes
 * this card's POST actions to the handlers below. See that file for the shape.
 *
 * Seven tables used to grow without a ceiling, every one of them holding an
 * IP address, an email address or something a customer typed. This is the
 * single place that decides when each of them stops.
 *
 * Four of the seven are evidence - what happened when the store was attacked,
 * and who did what - so their window has a floor. Without it this card would
 * be a tidy way to erase the audit trail, which is exactly what the clear-log
 * button in System > Logs already refuses to be.
 */

declare(strict_types=1);

require_once INCLUDES_PATH . '/retention.php';

return [
    'key'    => 'retention',
    'order'  => 92,
    'column' => 'main',

    'actions' => [
        'retention_save' => static function (string $back): void {
            $errors = [];
            $values = [];

            foreach (retention_tables() as $table => $spec) {
                $value = (int) input($spec['setting'], $spec['days']);
                $floor = $spec['evidence'] ? RETENTION_EVIDENCE_FLOOR_DAYS : 1;

                if ($value < $floor || $value > 3650) {
                    $errors[$spec['setting']] = $spec['evidence']
                        ? 'The ' . $spec['label'] . ' is evidence and keeps at least '
                          . RETENTION_EVIDENCE_FLOOR_DAYS . ' days. Enter ' . RETENTION_EVIDENCE_FLOOR_DAYS
                          . '-3650.'
                        : 'Enter a number of days between 1 and 3650.';
                    continue;
                }

                $values[$spec['setting']] = $value;
            }

            if ($errors !== []) {
                flash_errors($errors);
                flash_old($_POST);
                flash('error', 'The retention windows were not changed.');
                redirect($back);
            }

            foreach ($values as $key => $value) {
                setting_save($key, (string) $value, 'security', 'number');
            }
            admin_after_write();

            log_activity('security.retention', 'settings', null, 'Changed the data retention windows');
            security_event('settings.retention', 'high', $values, admin_id(), 'admin');

            flash('success', 'Saved. The windows apply on the next run of bin/prune-logs.php.');
            redirect($back);
        },

        'retention_run' => static function (string $back): void {
            // Capped harder than the cron job: this one is holding a browser
            // request open, and a page that times out half-way through a
            // delete teaches the operator to stop trusting the button.
            $result = retention_run(['max_batches' => 5]);

            log_activity('security.retention_run', 'settings', null,
                'Ran data retention by hand - ' . number_format($result['total']) . ' row(s) removed');

            if ($result['errors'] !== []) {
                flash('error', 'Retention ran with problems: '
                    . e(implode('; ', array_slice($result['errors'], 0, 2))));
                redirect($back);
            }

            flash('success', $result['total'] > 0
                ? number_format($result['total']) . ' expired row(s) removed in ' . $result['seconds'] . 's.'
                  . ($result['capped'] !== []
                      ? ' Stopped at the batch cap for ' . implode(', ', $result['capped'])
                        . ' - run it again, or let the nightly job finish it.'
                      : '')
                : 'Nothing was past its retention window.');
            redirect($back);
        },
    ],

    'render' => static function (array $errors, bool $canEdit): void {
        $preview = retention_preview();
        $last    = retention_last_run();

        $expired = 0;
        foreach ($preview as $row) {
            $expired += (int) $row['expired'];
        }
        ?>
        <div class="ad-card" style="margin:0" id="retention">
            <div class="ad-card__head">
                <div>
                    <h2 class="ad-card__title">Data retention</h2>
                    <div class="ad-card__sub">
                        How long logs are kept. Everything here holds an IP address, an email address
                        or something a customer typed.
                    </div>
                </div>
                <span class="sik-status sik-status--<?= $expired > 0 ? 'amber' : 'green' ?>">
                    <?= number_format($expired) ?> expired
                </span>
            </div>

            <div class="ad-card__body" style="display:grid;gap:14px">
                <div class="sik-alert sik-alert--info">
                    <?= icon('info', 'w-5 h-5') ?>
                    <div>
                        Nothing is deleted by loading this page. The windows are enforced by
                        <code class="ad-mono">php <?= e(ROOT_PATH) ?>/bin/prune-logs.php --quiet</code>,
                        which belongs on a nightly cron next to the backup.
                        <?php if ($last !== null): ?>
                            Last run <?= e(time_ago((string) ($last['at'] ?? ''))) ?>
                            (<?= number_format((int) ($last['total'] ?? 0)) ?> rows,
                            by <?= e((string) ($last['by'] ?? 'cron')) ?>).
                        <?php else: ?>
                            <strong>It has never run.</strong>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="ad-tablewrap">
                    <table class="ad-table">
                        <thead>
                            <tr>
                                <th>Log</th>
                                <th style="width:150px">Keep for</th>
                                <th class="ad-table__num">Rows</th>
                                <th class="ad-table__num">Past the window</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (retention_tables() as $table => $spec): ?>
                                <?php $row = $preview[$table] ?? ['total' => 0, 'expired' => 0, 'error' => '']; ?>
                                <tr>
                                    <td>
                                        <div style="font-weight:600"><?= e($spec['label']) ?></div>
                                        <div class="ad-cellflex__meta"><?= e($spec['why']) ?></div>
                                        <?php if ((string) $row['error'] !== ''): ?>
                                            <div class="ad-cellflex__meta" style="color:#DC2626">
                                                <?= e((string) $row['error']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($canEdit): ?>
                                            <div style="display:flex;gap:6px;align-items:center">
                                                <input class="sik-input<?= isset($errors[$spec['setting']]) ? ' is-invalid' : '' ?>"
                                                       style="max-width:96px" type="number" step="1"
                                                       min="<?= $spec['evidence'] ? RETENTION_EVIDENCE_FLOOR_DAYS : 1 ?>"
                                                       max="3650"
                                                       form="retentionForm"
                                                       name="<?= e_attr($spec['setting']) ?>"
                                                       value="<?= e_attr((string) (old($spec['setting'], null) ?? retention_days($table))) ?>">
                                                <span class="ad-muted">days</span>
                                            </div>
                                            <?php if (isset($errors[$spec['setting']])): ?>
                                                <span class="sik-error"><?= e($errors[$spec['setting']]) ?></span>
                                            <?php elseif ($spec['evidence']): ?>
                                                <span class="sik-help">
                                                    at least <?= RETENTION_EVIDENCE_FLOOR_DAYS ?>
                                                </span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <?= (int) retention_days($table) ?> days
                                        <?php endif; ?>
                                    </td>
                                    <td class="ad-table__num"><?= number_format((int) $row['total']) ?></td>
                                    <td class="ad-table__num">
                                        <?= (int) $row['expired'] > 0
                                            ? '<strong>' . number_format((int) $row['expired']) . '</strong>'
                                            : '0' ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($canEdit): ?>
                    <form method="post" id="retentionForm" style="display:flex;gap:8px;flex-wrap:wrap">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="retention_save">
                        <button type="submit" class="ad-btn ad-btn--primary">
                            <?= icon('check', 'w-4 h-4') ?> Save windows
                        </button>
                    </form>

                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="retention_run">
                        <button type="submit" class="ad-btn"
                                <?= admin_confirm_attrs('Every record past its retention window is deleted now. This cannot be undone.', ['title' => 'Run the retention sweep?', 'label' => 'Run it now', 'tone' => 'danger']) ?>>
                            Run retention now
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
        <?php
    },
];
