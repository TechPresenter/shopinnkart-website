<?php
/**
 * ShopInnKart Admin - Layout close.
 */

declare(strict_types=1);
?>
            </div><!-- /.ad-container -->
        </div><!-- /.ad-content -->

        <footer style="padding:16px 22px;border-top:1px solid var(--ad-border);background:var(--ad-surface);
                       font-size:12.5px;color:var(--ad-muted);display:flex;justify-content:space-between;
                       gap:12px;flex-wrap:wrap">
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
                    &middot; <span style="color:#D97706;font-weight:600">Development mode</span>
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
</body>
</html>
