<?php
/**
 * Security card: the privacy rights the store's own policy promises.
 *
 * Returned to admin/security/settings.php, which renders the card and routes
 * this card's POST actions to the handlers below. See that file for the shape.
 *
 * Two switches and a doorway. The switches decide whether customers can run
 * an export or a deletion themselves and how long an uncollected bundle
 * waits; the doorway is the queue at Security > Privacy Requests, where an
 * admin handles the ones that arrive by email instead.
 *
 * Turning self-service off does not remove the right - it only moves the work
 * to a human, and the card says so, because "we switched the button off" is
 * not an answer to a data request.
 *
 * Two things the card used to spell out and no longer needs to. A self-service
 * request always needs a link clicked in the customer's own inbox, is rate
 * limited, and is written to the security log - that is how it is built, not a
 * choice the operator makes here. And the confirmation page, the confirmation
 * email and privacy_delete() all read one list of what deletion touches, so
 * they cannot drift apart and say different things.
 */

declare(strict_types=1);

require_once INCLUDES_PATH . '/privacy.php';

return [
    'key'    => 'privacy',
    'order'  => 91,
    'column' => 'main',

    'actions' => [
        'privacy_save' => static function (string $back): void {
            $days = (int) input('sec_privacy_bundle_days', PRIVACY_BUNDLE_DAYS);
            if ($days < 1 || $days > 90) {
                flash_errors(['sec_privacy_bundle_days' => 'Enter a number of days between 1 and 90.']);
                flash_old($_POST);
                flash('error', 'The privacy settings were not changed.');
                redirect($back);
            }

            $self = (string) input('sec_privacy_self_service', '0') === '1';

            setting_save('sec_privacy_self_service', $self ? '1' : '0', 'security', 'boolean');
            setting_save('sec_privacy_bundle_days', (string) $days, 'security', 'number');
            admin_after_write();

            log_activity('security.privacy', 'settings', null, 'Changed the privacy request settings');
            security_event('settings.privacy', 'medium',
                ['self_service' => $self, 'bundle_days' => $days], admin_id(), 'admin');

            flash('success', 'Saved.');
            redirect($back);
        },
    ],

    'render' => static function (array $errors, bool $canEdit): void {
        $open      = privacy_open_count();
        $overdue   = 0;
        $completed = 0;

        try {
            $overdue = (int) Database::fetchColumn(
                "SELECT COUNT(*) FROM `privacy_requests`
                  WHERE `status` IN ('pending', 'ready') AND `due_at` IS NOT NULL AND `due_at` < NOW()"
            );
            $completed = (int) Database::fetchColumn(
                "SELECT COUNT(*) FROM `privacy_requests`
                  WHERE `status` = 'completed' AND `completed_at` > (NOW() - INTERVAL 30 DAY)"
            );
        } catch (Throwable $e) {
            // The table arrives with this round's migration; an install that
            // has not run it yet still renders the card.
        }

        $self = privacy_enabled();
        ?>
        <div class="ad-card" style="margin:0" id="privacy">
            <div class="ad-card__head">
                <div>
                    <h2 class="ad-card__title">Privacy requests</h2>
                    <div class="ad-card__sub">Export or delete everything you hold about one customer.</div>
                </div>
                <span class="sik-status sik-status--<?= $overdue > 0 ? 'red' : ($open > 0 ? 'amber' : 'green') ?>">
                    <?= (int) $open ?> open
                </span>
            </div>

            <div class="ad-card__body" style="display:grid;gap:14px">
                <?php if ($overdue > 0): ?>
                    <div class="sik-alert sik-alert--error">
                        <?= icon('alert', 'w-5 h-5') ?>
                        <div>
                            <strong><?= (int) $overdue ?> request(s) are past the
                            <?= PRIVACY_DUE_DAYS ?>-day deadline.</strong>
                            <a href="<?= e(admin_url('security/privacy.php')) ?>">Open the queue</a>.
                        </div>
                    </div>
                <?php endif; ?>

                <div class="sik-alert sik-alert--info">
                    <?= icon('info', 'w-5 h-5') ?>
                    <div>
                        <strong>Deletion is anonymisation.</strong>
                        Tax invoices must be kept by law, so order history survives as numbers.
                        The customer is told this before they confirm.
                    </div>
                </div>

                <?php if ($canEdit): ?>
                    <form method="post" class="ad-form" style="display:grid;gap:12px">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="privacy_save">
                        <input type="hidden" name="sec_privacy_self_service" value="0">

                        <label style="display:flex;gap:8px;align-items:flex-start">
                            <input type="checkbox" name="sec_privacy_self_service" value="1"
                                   style="margin-top:4px" <?= $self ? 'checked' : '' ?>>
                            <span>
                                <strong>Let customers run these themselves</strong>
                                <span class="sik-help" style="display:block">
                                    Off does not remove the right; requests arrive here instead.
                                </span>
                            </span>
                        </label>

                        <div class="ad-field">
                            <label class="sik-label" for="sec_privacy_bundle_days">An export file waits to be collected for</label>
                            <div style="display:flex;gap:8px;align-items:center">
                                <input class="sik-input<?= isset($errors['sec_privacy_bundle_days']) ? ' is-invalid' : '' ?>"
                                       style="max-width:120px" type="number" min="1" max="90" step="1"
                                       id="sec_privacy_bundle_days" name="sec_privacy_bundle_days"
                                       value="<?= e_attr((string) (old('sec_privacy_bundle_days', null) ?? privacy_bundle_days())) ?>">
                                <span class="ad-muted">days</span>
                            </div>
                            <?php if (isset($errors['sec_privacy_bundle_days'])): ?>
                                <span class="sik-error"><?= e($errors['sec_privacy_bundle_days']) ?></span>
                            <?php else: ?>
                                <span class="sik-help">
                                    Then deleted unread. It holds every address and order they had.
                                </span>
                            <?php endif; ?>
                        </div>

                        <div>
                            <button type="submit" class="ad-btn ad-btn--primary">
                                <?= icon('check', 'w-4 h-4') ?> Save
                            </button>
                            <a class="ad-btn" href="<?= e(admin_url('security/privacy.php')) ?>">
                                Privacy requests<?= $open > 0 ? ' (' . (int) $open . ')' : '' ?>
                            </a>
                        </div>
                    </form>
                <?php else: ?>
                    <p class="ad-muted" style="font-size:13px">
                        Self-service is <?= $self ? 'on' : 'off' ?>.
                        <?= (int) $open ?> open request(s),
                        <?= (int) $completed ?> completed in the last 30 days.
                    </p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    },
];
