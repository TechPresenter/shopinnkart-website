<?php
/**
 * Security card: the block and allow lists, in summary.
 *
 * The lists themselves live on their own screen (admin/security/ip-rules.php)
 * because they need a table, a form and a warning; this card is the entry
 * point from Security Settings and the one place that says out loud what this
 * control can and cannot do.
 *
 * Returned to admin/security/settings.php, which renders the card and routes
 * this card's POST actions to the handlers below. See that file for the shape.
 */

declare(strict_types=1);

return [
    'key'    => 'ip-rules',
    'order'  => 84,
    'column' => 'side',

    'actions' => [
        'ip_rules_allow_me' => static function (string $back): void {
            // The first thing anyone should do here, and the thing most
            // people only think of after locking themselves out.
            $result = security_ip_rule_add([
                'cidr'     => client_ip(),
                'kind'     => 'allow',
                'reason'   => 'The address Security Settings was opened from',
                'admin_id' => admin_id(),
            ]);

            if (!$result['ok']) {
                flash('error', $result['error']);
            } else {
                log_activity('security.ip_rule_added', 'ip_rule', $result['id'],
                    'Added ' . $result['cidr'] . ' to the allow list');
                flash('success', $result['cidr'] . ' is on the allow list. No block rule can ever refuse it.');
            }
            redirect($back);
        },
    ],

    'render' => static function (array $errors, bool $canEdit): void {
        $blocks = 0;
        $allows = 0;
        $used   = 0;
        try {
            $blocks = (int) Database::fetchColumn(
                "SELECT COUNT(*) FROM `ip_rules` WHERE `kind` = 'block' AND (`expires_at` IS NULL OR `expires_at` > NOW())"
            );
            $allows = (int) Database::fetchColumn(
                "SELECT COUNT(*) FROM `ip_rules` WHERE `kind` = 'allow' AND (`expires_at` IS NULL OR `expires_at` > NOW())"
            );
            $used = (int) Database::fetchColumn(
                'SELECT COALESCE(SUM(`hits`), 0) FROM `ip_rules`'
            );
        } catch (Throwable $e) {
            // The table belongs to this card's own migration; never fail here.
        }

        $myIp    = client_ip();
        $myRule  = $allows + $blocks > 0 ? security_ip_rule_match($myIp) : null;
        $allowed = $myRule !== null && $myRule['kind'] === 'allow';
        $proxied = function_exists('trusted_proxies') && trusted_proxies() !== [];
        ?>
        <div class="ad-card" style="margin:0" id="ip-rules">
            <div class="ad-card__head">
                <div>
                    <h2 class="ad-card__title">Blocked &amp; allowed addresses</h2>
                    <div class="ad-card__sub">Refused before the page runs. Allow always beats block.</div>
                </div>
                <?= $blocks > 0
                    ? '<span class="sik-status sik-status--red">' . (int) $blocks . ' blocked</span>'
                    : '<span class="sik-status sik-status--gray">None</span>' ?>
            </div>

            <div class="ad-card__body" style="display:grid;gap:14px">
                <div style="display:flex;gap:16px;flex-wrap:wrap">
                    <div>
                        <div class="ad-muted" style="font-size:12px">Block rules</div>
                        <div style="font-size:22px;font-weight:700"><?= number_format($blocks) ?></div>
                    </div>
                    <div>
                        <div class="ad-muted" style="font-size:12px">Allow rules</div>
                        <div style="font-size:22px;font-weight:700"><?= number_format($allows) ?></div>
                    </div>
                    <div>
                        <div class="ad-muted" style="font-size:12px">Requests refused</div>
                        <div style="font-size:22px;font-weight:700"><?= number_format($used) ?></div>
                    </div>
                </div>

                <div class="ad-muted" style="font-size:13px;line-height:1.7">
                    You are on <code class="ad-mono"><?= e($myIp !== '' ? $myIp : 'an unknown address') ?></code>.
                </div>

                <a class="ad-btn ad-btn--sm" href="<?= e(admin_url('security/ip-rules.php')) ?>">
                    <?= icon('lock', 'w-4 h-4') ?> Manage the lists
                </a>

                <?php if ($canEdit && !$allowed && $myIp !== ''): ?>
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="ip_rules_allow_me">
                        <button type="submit" class="ad-btn ad-btn--sm">
                            <?= icon('check', 'w-4 h-4') ?> Put my address on the allow list
                        </button>
                    </form>
                <?php elseif ($allowed): ?>
                    <div class="sik-alert sik-alert--success" style="margin:0">
                        <?= icon('check-circle', 'w-5 h-5') ?>
                        <div>Your address is on the allow list.</div>
                    </div>
                <?php endif; ?>

                <details style="font-size:13px">
                    <summary style="cursor:pointer;font-weight:600">What this is not</summary>
                    <div style="display:grid;gap:8px;margin-top:8px">
                        <p>It cannot lock you out: a rule covering the address you are on is refused at the
                           moment you try to save it. With no trusted proxy configured, that address is taken
                           straight from the connection &mdash; right for a direct host, wrong behind a CDN.</p>
                        <p>It is <strong>not</strong> DDoS protection. A blocked request still wakes PHP, reads one
                           small file and answers 403. That stops a scanner and a password sprayer; it does not stop
                           a flood, and it saves no bandwidth. Anything at that scale belongs at the CDN.</p>
                        <p>It is <strong>not</strong> a substitute for the sign-in throttles, which stop guessing
                           from addresses nobody has listed yet. Use this for the ones the security log has already
                           named.</p>
                        <p>Addresses move. A permanent block on a home or mobile address eventually refuses a
                           stranger who did nothing - give those an expiry.</p>
                    </div>
                </details>
            </div>
        </div>
        <?php
    },
];
