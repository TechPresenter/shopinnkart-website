<?php
/**
 * Security card: response headers and the Content Security Policy.
 *
 * Returned to admin/security/settings.php, which renders the card and routes
 * this card's POST actions to the handlers below. See that file for the shape.
 */

declare(strict_types=1);

return [
    'key'    => 'headers-csp',
    'order'  => 41,
    'column' => 'main',

    'actions' => [
        'csp_save' => static function (string $back): void {
            $mode = (string) input('sec_csp_mode', 'report-only');
            if (!in_array($mode, ['report-only', 'enforce', 'off'], true)) {
                $mode = 'report-only';
            }

            $reportUri = trim((string) input('sec_csp_report_uri', ''));
            if ($reportUri !== '' && filter_var($reportUri, FILTER_VALIDATE_URL) === false) {
                flash_errors(['sec_csp_report_uri' => 'That is not a valid URL.']);
                flash_old($_POST);
                flash('error', 'The policy was not changed.');
                redirect($back);
            }

            setting_save('sec_csp_mode', $mode, 'security', 'text');
            setting_save('sec_csp_report_uri', $reportUri, 'security', 'text');
            admin_after_write();

            security_event('platform.csp_mode_changed', $mode === 'off' ? 'high' : 'medium', ['mode' => $mode], admin_id(), 'admin');
            log_activity('security.csp', 'settings', null, 'Set the Content Security Policy to ' . $mode);

            flash('success', $mode === 'enforce'
                ? 'The policy is now enforced. Click through the storefront and the admin once - if anything looks broken, set it back to Report only.'
                : 'Saved.');
            redirect($back);
        },
    ],

    'render' => static function (array $errors, bool $canEdit): void {
        $mode      = (string) setting('sec_csp_mode', 'report-only');
        $reportUri = (string) (old('sec_csp_report_uri', null) ?? setting('sec_csp_report_uri', ''));
        $policy    = security_csp_policy(security_request_is_https(), 'public');
        ?>
        <div class="ad-card" style="margin:0" id="headers-csp">
            <div class="ad-card__head">
                <div>
                    <h2 class="ad-card__title">Response headers &amp; Content Security Policy</h2>
                </div>
                <?= $mode === 'enforce'
                    ? '<span class="sik-status sik-status--green">Enforced</span>'
                    : ($mode === 'off'
                        ? '<span class="sik-status sik-status--red">Off</span>'
                        : '<span class="sik-status sik-status--amber">Report only</span>') ?>
            </div>

            <div class="ad-card__body" style="display:grid;gap:14px">
                <?php if ($canEdit): ?>
                    <form method="post" class="ad-form" style="display:grid;gap:14px">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="csp_save">

                        <div class="ad-field">
                            <label class="sik-label" for="sec_csp_mode">Content Security Policy</label>
                            <select class="sik-select" id="sec_csp_mode" name="sec_csp_mode">
                                <option value="report-only" <?= $mode === 'report-only' ? 'selected' : '' ?>>
                                    Report only - nothing is blocked, violations are reported
                                </option>
                                <option value="enforce" <?= $mode === 'enforce' ? 'selected' : '' ?>>
                                    Enforce - block anything the policy does not allow
                                </option>
                                <option value="off" <?= $mode === 'off' ? 'selected' : '' ?>>
                                    Off - send no policy at all
                                </option>
                            </select>
                            <span class="sik-help">Start in Report only for a day, then Enforce.</span>
                        </div>

                        <div class="ad-field">
                            <label class="sik-label" for="sec_csp_report_uri">Send violation reports to (optional)</label>
                            <input class="sik-input<?= isset($errors['sec_csp_report_uri']) ? ' is-invalid' : '' ?>"
                                   type="url" id="sec_csp_report_uri" name="sec_csp_report_uri"
                                   value="<?= e_attr($reportUri) ?>" placeholder="https://your-collector.example/csp">
                            <?php if (isset($errors['sec_csp_report_uri'])): ?>
                                <span class="sik-error"><?= e($errors['sec_csp_report_uri']) ?></span>
                            <?php else: ?>
                                <span class="sik-help">Only if you run an external collector.</span>
                            <?php endif; ?>
                        </div>

                        <div>
                            <button type="submit" class="ad-btn ad-btn--primary">
                                <?= icon('shield', 'w-4 h-4') ?> Save
                            </button>
                        </div>
                    </form>
                <?php endif; ?>

                <details style="font-size:13px">
                    <summary style="cursor:pointer;font-weight:600">What is sent, and why Report only first</summary>
                    <div style="display:grid;gap:10px;margin-top:8px">
                        <p>These headers are what every page tells the browser it is allowed to do.</p>
                        <p>Sent on every page, always: <code>X-Content-Type-Options</code>,
                           <code>X-Frame-Options</code>, <code>Referrer-Policy</code>,
                           <code>Cross-Origin-Opener-Policy</code> and a <code>Permissions-Policy</code> that
                           keeps the camera and location off but leaves the
                           <strong>microphone on for voice search</strong>. Account, admin and API responses
                           also get <code>no-store</code>, so a shared computer cannot press Back into
                           someone&rsquo;s order.</p>
                        <p>A CSP is the difference between one injected <code>&lt;script&gt;</code> being a
                           defaced page and it being a stolen session. Report only blocks nothing, so a day of
                           real use tells you what Enforce would break before it breaks it.</p>
                        <p>The storefront still prints inline scripts, so the policy allows those today. It is
                           not the strictest policy possible, it is the strictest one this code can run under
                           &mdash; <a href="#csp-reports">What the policy would block</a> measures the way out.</p>
                        <p class="sik-label" style="margin:0">The policy being sent</p>
                        <pre class="ad-mono" style="white-space:pre-wrap;word-break:break-word;margin:0;font-size:12px"><?= e(str_replace('; ', ";\n", $policy)) ?></pre>
                    </div>
                </details>
            </div>
        </div>
        <?php
    },
];
