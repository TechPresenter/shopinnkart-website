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
        </main><!-- /.ad-content -->

        <?php // Every value below is a token; A4 moves the block to .ad-footer. ?>
        <footer style="padding:var(--ad-space-4) var(--ad-space-6);border-top:1px solid var(--ad-line);
                       background:var(--ad-surface);font-size:var(--ad-text-xs);color:var(--ad-muted);
                       display:flex;justify-content:space-between;gap:var(--ad-space-3);flex-wrap:wrap">
            <span>
                <?= e(setting('store_name', SITE_NAME)) ?> Admin &middot; v1.0
                <?php
                /* Same helper and the same admin-owned setting the storefront
                   footer reads, so the credit is worded once, split once and
                   styled once. The underline that used to be inline here is
                   gone: it is drawn on hover, in CSS, as a rule that can be a
                   different colour and can animate - neither of which
                   text-decoration can do. */
                require_once INCLUDES_PATH . '/credit-line.php';
                $adCredit = credit_line_html('ad-credit');
                ?>
                <?php if ($adCredit !== ''): ?>&middot; <?= $adCredit ?><?php endif; ?>
            </span>

        <?php
        /* THE SOUND SWITCH. A per-operator preference, so it is a control in
           the chrome and not a row in Settings: which room an operator is
           sitting in is not something the store knows. The state lives in
           this browser's localStorage - see assets/js/admin-sound.js.

           It is here rather than in the topbar because topbar.php is not this
           feature's to edit. The footer is on every admin page too, and a
           preference set once belongs in a status bar rather than beside the
           global search field.

           A <button aria-pressed>, not the .ad-switch checkbox: nothing is
           submitted and there is no form to submit it to.

           aria-pressed="false" is PRINTED rather than left off. The server
           cannot know what this browser stored, so the markup states the
           default - off - and admin-sound.js corrects it from localStorage.
           The script is deferred, so that correction lands after the footer
           is parsed and before first paint; an operator who has sound on
           never sees the switch flip.

           The accessible name is "Sound effects" - the visible "Sound" plus a
           screen-reader-only word - which keeps the visible label a subset of
           the name (2.5.3). The two glyphs are the NON-COLOUR half of the
           state: the tint alone would leave on/off resting on a border
           measured at 1.54:1, which 1.4.11 would not accept. */
        ?>
        <button type="button" class="ad-sound" data-sik-sound-toggle aria-pressed="false"
                title="Play a short tick when a save lands, and a chime when something arrives">
            <span class="ad-sound__glyph ad-sound__glyph--on">
                <svg class="sik-ico w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"
                     aria-hidden="true" focusable="false">
                    <path d="M11 5L6 9H3v6h3l5 4V5z"/>
                    <path d="M15.5 9.2a4.2 4.2 0 0 1 0 5.6"/>
                    <path d="M18.4 6.4a8.2 8.2 0 0 1 0 11.2"/>
                </svg>
            </span>
            <span class="ad-sound__glyph ad-sound__glyph--off">
                <svg class="sik-ico w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"
                     aria-hidden="true" focusable="false">
                    <path d="M11 5L6 9H3v6h3l5 4V5z"/>
                    <path d="M16 9.5l5 5M21 9.5l-5 5"/>
                </svg>
            </span>
            <span>Sound<span class="sik-sr"> effects</span></span>
        </button>

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
/* Last of the three globals on purpose: it WRAPS SIK.toast, which
   notifications.js defines. Both of those are deferred as well, and
   deferred scripts run in document order, so by the time this executes
   SIK.toast exists and can be wrapped.

   Unconditional rather than opt-in through admin_require_script(): the
   switch it drives is printed on every admin page, and a switch whose
   script is missing is a control that silently does nothing.

   It is not free, and it is worth saying so plainly: the module is about
   20KB, two thirds of it comment, and NEITHER Apache nor the test server
   sends it compressed (measured: no Content-Encoding on either). What makes
   the trade fair is the asset cache header - public, immutable, a year - so
   it is one download per deploy rather than one per page. Beyond that
   nothing further is fetched and no AudioContext is constructed at all
   until the operator presses the switch. */
?>
<script src="<?= e(asset('js/admin-sound.js')) ?>" defer></script>
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
