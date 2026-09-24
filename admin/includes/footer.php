<?php
/**
 * ShopInnKart Admin - Layout close.
 */

declare(strict_types=1);

/* The closing half of header.php's partial mode (A2). A fragment carries no
   scripts and no SIK_CONFIG: it is injected into a page that already has
   both, and a second window.SIK_CONFIG would silently replace the live one
   (including its CSRF token) on every quick view. */
if (function_exists('admin_partial_request') && admin_partial_request()) {
    echo '</div>';
    return;
}
?>
            </div><!-- /.ad-container -->
        </div><!-- /.ad-content -->

        <?php // Every value below is a token; A4 moves the block to .ad-footer. ?>
        <footer style="padding:var(--ad-space-4) var(--ad-space-6);border-top:1px solid var(--ad-line);
                       background:var(--ad-surface);font-size:var(--ad-text-xs);color:var(--ad-muted);
                       display:flex;justify-content:space-between;gap:var(--ad-space-3);flex-wrap:wrap">
            <span>
                <?= e(setting('store_name', SITE_NAME)) ?> Admin &middot; v1.0
                <?php
                /* Same admin-owned setting the storefront footer reads, so the
                   credit is worded once and cannot drift between the two. */
                $adCredit = trim((string) setting('credit_text', ''));
                $adCreditUrl = trim((string) setting('credit_url', ''));
                ?>
                <?php if ($adCredit !== ''): ?>
                    &middot;
                    <?php if ($adCreditUrl !== ''): ?>
                        <a href="<?= e($adCreditUrl) ?>" target="_blank" rel="noopener noreferrer"
                           style="color:inherit;text-decoration:underline;text-underline-offset:2px"><?= e($adCredit) ?></a>
                    <?php else: ?>
                        <?= e($adCredit) ?>
                    <?php endif; ?>
                <?php endif; ?>
            </span>
            <span>
                PHP <?= e(PHP_VERSION) ?>
                <?php if (APP_DEBUG): ?>
                    &middot; <span style="color:var(--ad-warning-ink);font-weight:var(--ad-weight-semibold)">Development mode</span>
                <?php endif; ?>
            </span>
        </footer>
    </div><!-- /.ad-main -->
</div><!-- /.ad-shell -->

<div class="sik-toasts" id="sikToasts" role="status" aria-live="polite"></div>

<script>
    window.SIK_CONFIG = <?= e_json([
        'baseUrl'        => SITE_URL,
        'apiUrl'         => API_URL,
        'adminUrl'       => ADMIN_URL,
        'csrfToken'      => csrf_token(),
        'currencySymbol' => (string) setting('currency_symbol', CURRENCY_SYMBOL),
        'grouping'       => (string) setting('number_grouping', 'indian'),
    ]) ?>;
</script>
<script src="<?= e(asset('js/app.js')) ?>" defer></script>
<script src="<?= e(asset('js/notifications.js')) ?>" defer></script>
<script src="<?= e(asset('js/admin.js')) ?>" defer></script>
<?php
/* Optional modules, after admin.js so they can extend SIK.admin. The list is
   whatever the page's helpers asked for through admin_require_script(); a
   page that draws no chart never downloads the chart module. Sealing it here
   turns a late call into a debug notice instead of a silent miss. */
foreach (admin_required_scripts(null, true) as $adModule): ?>
<script src="<?= e(asset('js/admin-' . $adModule . '.js')) ?>" defer></script>
<?php endforeach; ?>
</body>
</html>
