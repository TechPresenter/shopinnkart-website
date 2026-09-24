<?php
/**
 * Security card: what the Content Security Policy would block, and the switch
 * that makes it real.
 *
 * Card 41 owns the policy's MODE and the optional external collector. This one
 * owns the inbox: the grouped violations that arrived at our own endpoint, the
 * one-click move to Enforce, the way back, and the measurement that says
 * whether a nonce-based policy is reachable on this codebase yet.
 *
 * Returned to admin/security/settings.php, which renders the card and routes
 * this card's POST actions to the handlers below. See that file for the shape.
 */

declare(strict_types=1);

return [
    'key'    => 'csp-reports',
    'order'  => 80,
    'column' => 'main',

    'actions' => [
        /**
         * Switch to Enforce, but only when the report list is quiet.
         *
         * The whole point of report-only is that it tells you what enforcing
         * would break. Letting the owner enforce while first-party violations
         * are still unresolved would be offering them a button whose only
         * outcome is a broken shop and an automatic revert ten minutes later.
         */
        'csp_enforce' => static function (string $back): void {
            $pending = (int) Database::fetchColumn(
                "SELECT COUNT(*) FROM `csp_reports` WHERE `origin` = 'first-party' AND `status` = 'new'"
            );

            if ($pending > 0 && !input_bool('csp_force')) {
                flash('error', $pending . ' violation(s) are the site refusing its own code. Enforcing now would'
                    . ' break those pages. Mark each one "Allowed" once the policy covers it, or tick the override.');
                redirect($back);
            }

            csp_enforce_start(admin_id());
            log_activity('security.csp', 'settings', null, 'Switched the Content Security Policy to Enforce');

            flash('success', 'The policy is enforced. For the next '
                . max(1, setting_int('sec_csp_probation_hours', 24)) . ' hours the site watches its own reports:'
                . ' if it starts refusing its own scripts it switches itself back and emails you. Click through the'
                . ' shop and the admin now.');
            redirect($back);
        },

        'csp_report_only' => static function (string $back): void {
            csp_enforce_stop('', admin_id());
            log_activity('security.csp', 'settings', null, 'Switched the Content Security Policy back to Report only');
            flash('success', 'Back on Report only. Nothing is blocked; violations are still collected.');
            redirect($back);
        },

        'csp_report_status' => static function (string $back): void {
            $id     = (int) input('id', 0);
            $status = (string) input('status', 'acknowledged');
            if (!in_array($status, ['new', 'acknowledged', 'allowed', 'ignored'], true)) {
                $status = 'acknowledged';
            }

            $changed = Database::update('csp_reports', ['status' => $status], '`id` = :id', ['id' => $id]);
            flash($changed > 0 ? 'success' : 'error', $changed > 0
                ? 'Marked as ' . $status . '.'
                : 'That violation is no longer in the list.');
            redirect($back);
        },

        'csp_reports_clear' => static function (string $back): void {
            $removed = Database::query('DELETE FROM `csp_reports`')->rowCount();
            log_activity('security.csp', 'settings', null, 'Cleared ' . $removed . ' CSP violation group(s)');
            flash('success', $removed . ' violation group(s) cleared. The list starts again from now.');
            redirect($back);
        },

        /**
         * Nonce mode, and the reason it is not simply a checkbox.
         *
         * A nonce only covers inline <script> tags that PRINT the nonce. Every
         * one that does not is blocked the moment the mode changes. So the
         * switch measures the live site first and refuses on the measurement,
         * not on the operator's confidence.
         */
        'csp_script_mode' => static function (string $back): void {
            $mode = (string) input('sec_csp_script_mode', 'unsafe-inline') === 'nonce' ? 'nonce' : 'unsafe-inline';

            if ($mode === 'nonce') {
                $audit = security_csp_inline_audit();
                $t     = $audit['totals'];

                if ($audit['pages'] === []) {
                    flash('error', 'The site could not reach itself over HTTP, so nothing could be measured.'
                        . ' Nonce mode was not switched on.');
                    redirect($back);
                }
                if ((int) $t['unnonced'] > 0 || (int) $t['handlers'] > 0 || (int) $t['js_href'] > 0) {
                    flash('error', 'Not yet: the pages still carry ' . (int) $t['unnonced']
                        . ' inline script block(s) without a nonce and ' . ((int) $t['handlers'] + (int) $t['js_href'])
                        . ' inline handler(s). Nonce mode would blank them. The breakdown is below.');
                    redirect($back);
                }
            }

            setting_save('sec_csp_script_mode', $mode, 'security', 'text');
            admin_after_write();
            security_event('platform.csp_script_mode', 'medium', ['mode' => $mode], admin_id(), 'admin');
            log_activity('security.csp', 'settings', null, 'Set the inline-script policy to ' . $mode);

            flash('success', $mode === 'nonce'
                ? "Inline scripts now need this request's nonce. Click through the shop and the admin once."
                : "Inline scripts are allowed by 'unsafe-inline' again.");
            redirect($back);
        },
    ],

    'render' => static function (array $errors, bool $canEdit): void {
        $mode        = (string) setting('sec_csp_mode', 'report-only');
        $scriptMode  = security_csp_script_mode();
        $collecting  = setting_bool('sec_csp_collect', true);
        $revertNote  = trim((string) setting('sec_csp_revert_note', ''));
        $enforcedAt  = trim((string) setting('sec_csp_enforced_at', ''));

        $groups = Database::fetchAll(
            'SELECT * FROM `csp_reports` ORDER BY `origin` = \'first-party\' DESC, `hits` DESC LIMIT 40'
        );
        $firstParty = (int) Database::fetchColumn(
            "SELECT COUNT(*) FROM `csp_reports` WHERE `origin` = 'first-party' AND `status` = 'new'"
        );
        $totalGroups = (int) Database::fetchColumn('SELECT COUNT(*) FROM `csp_reports`');
        $week = (int) Database::fetchColumn(
            'SELECT COALESCE(SUM(`hits`), 0) FROM `csp_reports` WHERE `last_seen` >= (NOW() - INTERVAL 7 DAY)'
        );

        // Measured on demand only: it makes a real HTTP request per page type.
        $audit = ((string) ($_GET['csp_audit'] ?? '')) === '1' ? security_csp_inline_audit() : null;
        ?>
        <div class="ad-card" style="margin:0" id="csp-reports">
            <div class="ad-card__head">
                <div>
                    <h2 class="ad-card__title">What the policy would block</h2>
                    <div class="ad-card__sub">This list must be quiet before Enforce.</div>
                </div>
                <?= $mode === 'enforce'
                    ? '<span class="sik-status sik-status--green">Enforced</span>'
                    : '<span class="sik-status sik-status--amber">Report only</span>' ?>
            </div>

            <div class="ad-card__body" style="display:grid;gap:14px">
                <?php if ($revertNote !== ''): ?>
                    <div class="sik-alert sik-alert--warning">
                        <?= icon('alert', 'w-5 h-5') ?>
                        <div>
                            <strong>The policy switched itself back.</strong>
                            <?= e($revertNote) ?>
                            Fix what is listed below, then try Enforce again.
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!$collecting): ?>
                    <div class="sik-alert sik-alert--info">
                        <?= icon('info', 'w-5 h-5') ?>
                        <div>Collection is switched off, so this list cannot fill. Turn it on below.</div>
                    </div>
                <?php endif; ?>

                <div style="display:flex;gap:20px;flex-wrap:wrap">
                    <div>
                        <div class="ad-muted" style="font-size:12px">Violation groups</div>
                        <div style="font-size:22px;font-weight:700"><?= number_format($totalGroups) ?></div>
                    </div>
                    <div>
                        <div class="ad-muted" style="font-size:12px">Reports this week</div>
                        <div style="font-size:22px;font-weight:700"><?= number_format($week) ?></div>
                    </div>
                    <div>
                        <div class="ad-muted" style="font-size:12px">Our own code being refused</div>
                        <div style="font-size:22px;font-weight:700;color:<?= $firstParty > 0 ? 'var(--ad-danger,#c62828)' : 'inherit' ?>">
                            <?= number_format($firstParty) ?>
                        </div>
                    </div>
                </div>

                <?php // Reports arrive at csp_report_endpoint(), capped at
                      // sec_csp_report_max per address per hour and at
                      // sec_csp_group_cap distinct groups, so one misbehaving
                      // browser extension cannot flood the table. That is why
                      // the screen no longer explains the caps: they are the
                      // reason the list stays readable, not a decision the
                      // operator makes. ?>
                <div class="ad-muted" style="font-size:13px;line-height:1.7">
                    <strong>Only the first-party rows matter here.</strong>
                    A <code>chrome-extension://</code> row is a visitor's own browser.
                </div>

                <?php if ($groups === []): ?>
                    <?= admin_empty('No violations reported',
                        $collecting
                            ? 'Either nothing has been blocked, or no visitor has loaded a page since the policy was set.'
                            : 'Collection is off.',
                        null, null, 'shield') ?>
                <?php else: ?>
                    <div class="ad-tablewrap">
                        <table class="ad-table">
                            <thead>
                                <tr>
                                    <th>Directive</th>
                                    <th>Blocked</th>
                                    <th>Where</th>
                                    <th>Hits</th>
                                    <?php if ($canEdit): ?><th>Actions</th><?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($groups as $row): ?>
                                    <tr>
                                        <td class="ad-mono" style="white-space:nowrap;font-size:12px">
                                            <?= e((string) $row['directive']) ?>
                                            <div>
                                                <?= (string) $row['origin'] === 'first-party'
                                                    ? '<span class="sik-status sik-status--red">Our code</span>'
                                                    : '<span class="sik-status sik-status--gray">Third party</span>' ?>
                                                <?= (string) $row['disposition'] === 'enforce'
                                                    ? '<span class="sik-status sik-status--amber">Blocked</span>'
                                                    : '' ?>
                                            </div>
                                        </td>
                                        <td class="ad-mono" style="font-size:12px;max-width:280px;word-break:break-all">
                                            <?= e((string) $row['blocked_uri']) ?>
                                            <?php if (!empty($row['sample'])): ?>
                                                <div class="ad-cellflex__meta"><?= e(str_limit((string) $row['sample'], 80)) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td style="font-size:12px">
                                            <span class="ad-mono"><?= e(str_limit((string) ($row['document_path'] ?? '/'), 40)) ?></span>
                                            <div class="ad-cellflex__meta"><?= e((string) $row['page_context']) ?></div>
                                        </td>
                                        <td style="white-space:nowrap">
                                            <?= number_format((int) $row['hits']) ?>
                                            <div class="ad-cellflex__meta"><?= e(time_ago($row['last_seen'])) ?></div>
                                        </td>
                                        <?php if ($canEdit): ?>
                                            <td style="white-space:nowrap">
                                                <form method="post" style="display:inline">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="csp_report_status">
                                                    <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                                    <input type="hidden" name="status"
                                                           value="<?= (string) $row['status'] === 'new' ? 'allowed' : 'new' ?>">
                                                    <button type="submit" class="ad-btn ad-btn--sm">
                                                        <?= (string) $row['status'] === 'new' ? 'Mark handled' : 'Reopen' ?>
                                                    </button>
                                                </form>
                                            </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <?php if ($canEdit): ?>
                    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
                        <?php if ($mode !== 'enforce'): ?>
                            <form method="post" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="csp_enforce">
                                <button type="submit" class="ad-btn ad-btn--primary"
                                        <?= admin_confirm_attrs('Anything the policy does not allow stops working at once - including, if the policy is wrong, your own shop.', ['title' => 'Enforce the policy?', 'label' => 'Enforce it', 'tone' => 'danger']) ?>>
                                    <?= icon('shield', 'w-4 h-4') ?> Enforce the policy
                                </button>
                                <?php if ($firstParty > 0): ?>
                                    <label class="sik-check" style="font-size:12px">
                                        <input type="checkbox" name="csp_force" value="1">
                                        <span>Enforce anyway (<?= (int) $firstParty ?> unresolved)</span>
                                    </label>
                                <?php endif; ?>
                            </form>
                        <?php else: ?>
                            <form method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="csp_report_only">
                                <button type="submit" class="ad-btn ad-btn--danger">
                                    <?= icon('undo', 'w-4 h-4') ?> Switch back to Report only
                                </button>
                            </form>
                            <span class="ad-muted" style="font-size:12px">
                                Enforced since <?= e($enforcedAt === '' ? 'unknown' : format_datetime($enforcedAt)) ?>.
                                Auto-revert is watching for
                                <?= (int) setting_int('sec_csp_probation_hours', 24) ?> hours.
                            </span>
                        <?php endif; ?>

                        <?php if ($totalGroups > 0): ?>
                            <form method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="csp_reports_clear">
                                <button type="submit" class="ad-btn ad-btn--sm"
                                        <?= admin_confirm_attrs('The list starts again from now.', ['title' => 'Clear every violation?', 'label' => 'Clear the list', 'tone' => 'warning']) ?>>
                                    Clear the list
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <!-- ---------------------------------------------------------
                     Nonce readiness
                     --------------------------------------------------------- -->
                <details <?= $audit !== null ? 'open' : '' ?> style="font-size:13px">
                    <summary style="cursor:pointer;font-weight:600">
                        Inline scripts: <?= $scriptMode === 'nonce' ? 'nonce' : "'unsafe-inline'" ?>
                    </summary>
                    <div style="display:grid;gap:12px;margin-top:10px">
                        <p>
                            The policy still allows inline <code>&lt;script&gt;</code> because this codebase prints
                            them - the theme colours, the widget configuration, the analytics bootstrap. That is the
                            one weak point in the whole policy: an injected inline script would be allowed. The way
                            out is a <strong>nonce</strong>, a fresh random value per request that every legitimate
                            inline block prints and an injected one cannot know.
                        </p>
                        <p>
                            The switch below refuses to turn nonce mode on until a real measurement says every page
                            type would survive it, because the failure mode is a blank shop.
                        </p>

                        <p>
                            <a class="ad-btn ad-btn--sm"
                               href="<?= e(admin_url('security/settings.php?csp_audit=1')) ?>#csp-reports">
                                <?= icon('activity', 'w-4 h-4') ?> Measure the live pages now
                            </a>
                        </p>

                        <?php if ($audit !== null): ?>
                            <?php $t = $audit['totals']; ?>
                            <div class="ad-tablewrap">
                                <table class="ad-table">
                                    <thead>
                                        <tr>
                                            <th>Page</th>
                                            <th>Inline scripts</th>
                                            <th>With a nonce</th>
                                            <th>onclick= etc</th>
                                            <th>javascript: links</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($audit['pages'] as $page): ?>
                                            <tr>
                                                <td><?= e($page['label']) ?>
                                                    <div class="ad-cellflex__meta ad-mono"><?= e($page['path']) ?></div>
                                                </td>
                                                <td><?= (int) $page['inline'] ?></td>
                                                <td><?= (int) $page['nonced'] ?>
                                                    <?php if ((int) $page['unnonced'] > 0): ?>
                                                        <span class="sik-status sik-status--red"><?= (int) $page['unnonced'] ?> would break</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= (int) $page['handlers'] ?></td>
                                                <td><?= (int) $page['js_href'] ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <?php foreach ($audit['errors'] as $line): ?>
                                <div class="ad-muted" style="font-size:12px"><?= e($line) ?></div>
                            <?php endforeach; ?>

                            <div class="sik-alert <?= ((int) $t['unnonced'] + (int) $t['handlers'] + (int) $t['js_href']) > 0
                                ? 'sik-alert--warning' : 'sik-alert--success' ?>">
                                <?= icon(((int) $t['unnonced'] + (int) $t['handlers'] + (int) $t['js_href']) > 0 ? 'alert' : 'check', 'w-5 h-5') ?>
                                <div>
                                    <?php if (((int) $t['unnonced'] + (int) $t['handlers'] + (int) $t['js_href']) > 0): ?>
                                        <strong>Nonce mode is not reachable yet.</strong>
                                        <?= (int) $t['unnonced'] ?> inline script block(s) across these pages carry no
                                        nonce<?= ((int) $t['handlers'] + (int) $t['js_href']) > 0
                                            ? ', and ' . ((int) $t['handlers'] + (int) $t['js_href'])
                                              . ' inline handler(s) cannot be covered by a nonce at all'
                                            : '' ?>.
                                        Each un-nonced block needs one attribute -
                                        <code>&lt;script&lt;?= csp_nonce_attr() ?&gt;&gt;</code> - in the file that prints it.
                                    <?php else: ?>
                                        <strong>Every inline block on every measured page carries a nonce.</strong>
                                        Nonce mode can be switched on.
                                    <?php endif; ?>
                                </div>
                            </div>

                            <?php $sources = security_csp_inline_sources(); ?>
                            <?php if ($sources !== []): ?>
                                <details>
                                    <summary style="cursor:pointer">
                                        The <?= count($sources) ?> file(s) that still print an inline script without a nonce
                                    </summary>
                                    <pre class="ad-mono" style="white-space:pre-wrap;font-size:11px;margin-top:8px"><?php
                                        foreach ($sources as $file => $count) {
                                            echo e($file) . '  (' . (int) $count . ")\n";
                                        }
                                    ?></pre>
                                </details>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php if ($canEdit): ?>
                            <form method="post" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="csp_script_mode">
                                <label class="sik-sr" for="sec_csp_script_mode">Inline script policy</label>
                                <select class="sik-select" id="sec_csp_script_mode" name="sec_csp_script_mode" style="max-width:280px">
                                    <option value="unsafe-inline" <?= $scriptMode === 'unsafe-inline' ? 'selected' : '' ?>>
                                        Allow inline scripts ('unsafe-inline')
                                    </option>
                                    <option value="nonce" <?= $scriptMode === 'nonce' ? 'selected' : '' ?>>
                                        Require a nonce on every inline script
                                    </option>
                                </select>
                                <button type="submit" class="ad-btn ad-btn--sm"><?= icon('check', 'w-4 h-4') ?> Apply</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </details>
            </div>
        </div>
        <?php
    },
];
