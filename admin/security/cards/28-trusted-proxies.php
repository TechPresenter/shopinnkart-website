<?php
/**
 * Security card: trusted proxies.
 *
 * Behind a CDN, REMOTE_ADDR is the edge server: every visitor shares one
 * rate-limit bucket and every log line records Cloudflare. The forwarded
 * headers say who the visitor really is - but anybody can send those headers,
 * so they only count when the machine that connected is listed here.
 */

declare(strict_types=1);

return [
    'key'    => 'trusted-proxies',
    'order'  => 28,
    'column' => 'side',

    'actions' => [
        'proxies_save' => static function (string $back): void {
            $raw = trim((string) input('sec_trusted_proxies', ''));

            $clean = [];
            $bad   = [];
            foreach (preg_split('/[\s,;]+/', strtolower($raw)) ?: [] as $entry) {
                $entry = trim($entry);
                if ($entry === '') {
                    continue;
                }
                if ($entry === 'cloudflare' || $entry === 'cf') {
                    $clean[] = 'cloudflare';
                    continue;
                }
                [$ip] = array_pad(explode('/', $entry, 2), 2, null);
                if (filter_var((string) $ip, FILTER_VALIDATE_IP) === false) {
                    $bad[] = $entry;
                    continue;
                }
                $clean[] = $entry;
            }

            if ($bad !== []) {
                flash_errors(['sec_trusted_proxies' => 'Not an address or range: ' . e(implode(', ', array_slice($bad, 0, 3)))]);
                flash_old($_POST);
                flash('error', 'The proxy list was not changed.');
                redirect($back);
            }

            setting_save('sec_trusted_proxies', implode(', ', array_values(array_unique($clean))), 'security', 'text');
            site_url_policy_sync();
            admin_after_write();

            log_activity('security.trusted_proxies', 'settings', null,
                $clean === [] ? 'Stopped trusting any proxy headers' : 'Set the trusted proxy list');
            security_event('settings.trusted_proxies', 'high', ['entries' => $clean], admin_id(), 'admin');

            flash('success', $clean === []
                ? 'Saved. Forwarded headers are ignored; the connecting address is used as-is.'
                : 'Saved. Visitor addresses now come from the forwarded headers behind those proxies.');
            redirect($back);
        },
    ],

    'render' => static function (array $errors, bool $canEdit): void {
        $value  = (string) setting('sec_trusted_proxies', '');
        $ranges = trusted_proxies();
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $seen   = client_ip();
        $behind = $remote !== '' && $ranges !== [] && is_trusted_proxy($remote);
        ?>
        <div class="ad-card" style="margin:0" id="trusted-proxies">
            <div class="ad-card__head">
                <div>
                    <h2 class="ad-card__title">Trusted proxies</h2>
                    <div class="ad-card__sub">Only for a CDN or load balancer in front.</div>
                </div>
                <?= $ranges === []
                    ? '<span class="sik-status sik-status--grey">Direct</span>'
                    : '<span class="sik-status sik-status--green">' . count($ranges) . ' range(s)</span>' ?>
            </div>

            <div class="ad-card__body" style="display:grid;gap:14px">
                <div class="ad-field">
                    <span class="sik-label">Your address, as this store sees it</span>
                    <div class="ad-mono" style="font-size:13px">
                        <?= e($seen === '' ? '(none)' : $seen) ?>
                        <?php if ($behind && $seen !== $remote): ?>
                            <span class="ad-muted">(via proxy <?= e($remote) ?>)</span>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($canEdit): ?>
                    <form method="post" class="ad-form" style="display:grid;gap:12px">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="proxies_save">

                        <div class="ad-field">
                            <label class="sik-label" for="sec_trusted_proxies">Proxy addresses and ranges</label>
                            <textarea class="sik-input ad-mono<?= isset($errors['sec_trusted_proxies']) ? ' is-invalid' : '' ?>"
                                      id="sec_trusted_proxies" name="sec_trusted_proxies" rows="3" spellcheck="false"
                                      placeholder="cloudflare&#10;10.0.0.0/8"><?= e((string) (old('sec_trusted_proxies', null) ?? $value)) ?></textarea>
                            <?php if (isset($errors['sec_trusted_proxies'])): ?>
                                <span class="sik-error"><?= e($errors['sec_trusted_proxies']) ?></span>
                            <?php else: ?>
                                <span class="sik-help">
                                    <code>cloudflare</code> expands to
                                    <?= count(trusted_proxy_preset('cloudflare')) ?> published ranges. Otherwise one per line.
                                </span>
                            <?php endif; ?>
                        </div>

                        <div style="display:flex;gap:8px;flex-wrap:wrap">
                            <button type="submit" class="ad-btn ad-btn--primary"
                                    <?= admin_confirm_attrs('Anything listed here is allowed to say who a visitor is. A wrong entry lets someone forge their own address.', ['title' => 'Trust these proxies?', 'label' => 'Save the list', 'tone' => 'danger']) ?>>
                                <?= icon('check', 'w-4 h-4') ?> Save proxies
                            </button>
                            <?php if ($value === ''): ?>
                                <button type="button" class="ad-btn ad-btn--sm" data-fill-proxies="cloudflare">Use Cloudflare</button>
                            <?php endif; ?>
                        </div>
                    </form>
                    <script>
                        document.querySelectorAll('[data-fill-proxies]').forEach(function (button) {
                            button.addEventListener('click', function () {
                                var box = document.getElementById('sec_trusted_proxies');
                                if (box) { box.value = button.getAttribute('data-fill-proxies'); box.focus(); }
                            });
                        });
                    </script>
                <?php endif; ?>

                <p class="sik-help" style="margin:0">Absorbing a flood stays the CDN's job.</p>

                <details style="font-size:13px">
                    <summary style="cursor:pointer;font-weight:600">How this changes what the store sees</summary>
                    <div style="display:grid;gap:8px;margin-top:8px">
                        <p>Behind a CDN, the connecting address is the edge server. Every visitor then shares
                           one rate-limit bucket and every log line records the CDN instead of the person.
                           If the address shown above is the CDN&rsquo;s rather than yours, its ranges belong
                           in the box.</p>
                        <p>The forwarded headers say who the visitor really is, but anybody can send those
                           headers &mdash; so they only count when the machine that connected is listed here.
                           A wrong entry lets someone forge their own address.</p>
                        <p>The store can only rate-limit and log what it can identify. It cannot absorb a
                           flood at any setting; that is the CDN&rsquo;s or the host&rsquo;s job.</p>
                    </div>
                </details>
            </div>
        </div>
        <?php
    },
];
