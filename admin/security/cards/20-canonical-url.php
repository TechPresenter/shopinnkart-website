<?php
/**
 * Security card: canonical store address and Host allow-list.
 *
 * Returned to admin/security/settings.php, which renders the card and routes
 * this card's POST actions to the handlers below.
 */

declare(strict_types=1);

return [
    'key'    => 'canonical-url',
    'order'  => 20,
    'column' => 'main',

    'actions' => [
        'canonical_save' => static function (string $back): void {
            $raw   = trim((string) input('sec_canonical_url', ''));
            $hosts = trim((string) input('sec_host_allowlist', ''));
            $block = input_bool('sec_host_block');

            $url = $raw === '' ? '' : site_url_normalise($raw);
            if ($raw !== '' && $url === null) {
                flash_errors(['sec_canonical_url' => 'Enter the full address, for example https://shopinnkart.com']);
                flash_old($_POST);
                flash('error', 'The store address was not changed.');
                redirect($back);
            }

            // Refusing to block on a Host we cannot recognise is the difference
            // between a security setting and an outage: with the switch on and
            // the current host missing from the list, the next request - this
            // admin's - would be answered 400.
            $parsed = site_url_parse_hosts($hosts);
            $current = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
            if ($block && !site_url_host_allowed($current, $url === '' ? null : $url, SITE_DOMAIN,
                    ['hosts' => $parsed], APP_DEBUG)) {
                flash_errors(['sec_host_allowlist' => 'Add ' . $current . ' first, or you would be locked out by the next request.']);
                flash_old($_POST);
                flash('error', 'Nothing was changed.');
                redirect($back);
            }

            setting_save('sec_canonical_url', $url === null ? '' : $url, 'security', 'text');
            setting_save('sec_host_allowlist', implode(', ', $parsed), 'security', 'text');
            setting_save('sec_host_block', $block ? '1' : '0', 'security', 'boolean');
            site_url_policy_sync();
            admin_after_write();

            log_activity('security.canonical_url', 'settings', null,
                $url === '' ? 'Cleared the canonical store address' : 'Set the canonical store address to ' . $url);
            security_event('settings.canonical_url', 'medium', ['url' => $url, 'block' => $block], admin_id(), 'admin');

            flash('success', 'Saved. Emailed links are built from this address from now on.');
            redirect($back);
        },
    ],

    'render' => static function (array $errors, bool $canEdit): void {
        $canonical = (string) setting('sec_canonical_url', '');
        $hosts     = (string) setting('sec_host_allowlist', '');
        $block     = setting_bool('sec_host_block', false);
        $current   = (string) ($_SERVER['HTTP_HOST'] ?? '');
        $effective = canonical_base_url();
        $fixed     = defined('SITE_URL_CANONICAL') && SITE_URL_CANONICAL !== ''
            && $canonical !== '' && SITE_URL_CANONICAL !== $canonical;
        ?>
        <div class="ad-card" style="margin:0" id="canonical-url">
            <div class="ad-card__head">
                <div>
                    <h2 class="ad-card__title">Store address in emails</h2>
                </div>
                <?= $canonical !== ''
                    ? '<span class="sik-status sik-status--green">Fixed</span>'
                    : '<span class="sik-status sik-status--amber">Auto-detected</span>' ?>
            </div>

            <div class="ad-card__body" style="display:grid;gap:14px">
                <div class="ad-field">
                    <span class="sik-label">Links are being built from</span>
                    <input class="sik-input ad-mono" type="text" readonly value="<?= e_attr($effective) ?>"
                           aria-label="Address used in emails">
                    <span class="sik-help">
                        This request arrived as <code><?= e($current === '' ? '(command line)' : $current) ?></code>,
                        <?= SITE_URL_HOST_OK ? 'recognised.' : '<strong>not</strong> on the list.' ?>
                    </span>
                </div>

                <?php if ($fixed): ?>
                    <div class="sik-alert sik-alert--info">
                        <?= icon('info', 'w-5 h-5') ?>
                        <div>
                            <code>config/db.local.php</code> or <code>APP_URL</code> sets
                            <code><?= e((string) SITE_URL_CANONICAL) ?></code>, which wins over the setting below.
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($canEdit): ?>
                    <form method="post" class="ad-form" style="display:grid;gap:12px">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="canonical_save">

                        <div class="ad-field">
                            <label class="sik-label" for="sec_canonical_url">Canonical address</label>
                            <input class="sik-input ad-mono<?= isset($errors['sec_canonical_url']) ? ' is-invalid' : '' ?>"
                                   id="sec_canonical_url" name="sec_canonical_url" type="text" spellcheck="false"
                                   autocomplete="off" maxlength="190"
                                   value="<?= e_attr((string) (old('sec_canonical_url', null) ?? $canonical)) ?>"
                                   placeholder="https://<?= e_attr(SITE_DOMAIN) ?>">
                            <?php if (isset($errors['sec_canonical_url'])): ?>
                                <span class="sik-error"><?= e($errors['sec_canonical_url']) ?></span>
                            <?php else: ?>
                                <span class="sik-help">Leave empty to keep detecting it.</span>
                            <?php endif; ?>
                        </div>

                        <div class="ad-field">
                            <label class="sik-label" for="sec_host_allowlist">Other addresses this store answers on</label>
                            <textarea class="sik-input<?= isset($errors['sec_host_allowlist']) ? ' is-invalid' : '' ?>"
                                      id="sec_host_allowlist" name="sec_host_allowlist" rows="2" spellcheck="false"
                                      placeholder="staging.<?= e_attr(SITE_DOMAIN) ?>, shop.<?= e_attr(SITE_DOMAIN) ?>"><?= e((string) (old('sec_host_allowlist', null) ?? $hosts)) ?></textarea>
                            <?php if (isset($errors['sec_host_allowlist'])): ?>
                                <span class="sik-error"><?= e($errors['sec_host_allowlist']) ?></span>
                            <?php else: ?>
                                <span class="sik-help">
                                    <code><?= e(SITE_DOMAIN) ?></code> and <code>www.</code> are always accepted.
                                </span>
                            <?php endif; ?>
                        </div>

                        <label class="sik-check">
                            <input type="checkbox" name="sec_host_block" value="1"<?= $block ? ' checked' : '' ?>>
                            <span>
                                Answer <strong>400 Bad Request</strong> to any other host
                                <span class="sik-help" style="display:block">
                                    A host missing from the list takes the shop down.
                                </span>
                            </span>
                        </label>

                        <div>
                            <button type="submit" class="ad-btn ad-btn--primary">
                                <?= icon('check', 'w-4 h-4') ?> Save address
                            </button>
                        </div>
                    </form>
                <?php endif; ?>

                <details style="font-size:13px">
                    <summary style="cursor:pointer;font-weight:600">Why the address is fixed here</summary>
                    <div style="display:grid;gap:8px;margin-top:8px">
                        <p>Password resets, order updates and unsubscribe links are all built from this
                           address. Without it they follow whatever <code>Host</code> header the request
                           arrived with, and a stranger can aim a genuine reset email at their own server.</p>
                        <p>Setting the address is what closes that. The <strong>400 Bad Request</strong>
                           switch is extra: links are already safe without it, and a hostname missing from
                           the list would take the shop down.</p>
                    </div>
                </details>
            </div>
        </div>
        <?php
    },
];
