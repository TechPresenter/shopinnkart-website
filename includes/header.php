<?php
/**
 * ShopInnKart - Storefront header.
 *
 * Included at the top of every customer-facing page in the project root.
 * A page sets its SEO with seo_set([...]) before including this file.
 */

declare(strict_types=1);

if (!defined('SIK_BOOTSTRAPPED')) {
    require_once __DIR__ . '/init.php';
}
require_once INCLUDES_PATH . '/menu-functions.php';
require_once INCLUDES_PATH . '/header-actions.php';
require_once INCLUDES_PATH . '/account-menu.php';
require_once INCLUDES_PATH . '/header-settings.php';
require_once INCLUDES_PATH . '/theme-fonts.php';

$storeName     = (string) setting('store_name', SITE_NAME);
$announcements = active_announcements();
$mainMenu      = build_menu('main');
$mobileMenu    = build_menu('mobile') ?: $mainMenu;
$cartCount     = cart_count();
$wishlistCount = wishlist_count();
$compareCount  = compare_count();
$currentUser   = current_user();
$routeKey      = current_route_key();
$stickyHeader  = setting('header_style', 'sticky') === 'sticky';

// Admin > Appearance > Header. Every value is clamped and enum-checked inside
// header_settings(), and every default reproduces the shipped header exactly.
$hd = header_settings();

// The burger travels with the wordmark by default; "Burger on the right" moves
// the same button into the action cluster. It is built once into a string
// rather than written out in both branches, so the two placements can never
// drift apart.
ob_start(); ?>
<button type="button" class="sik-action sik-burger" data-open-drawer="sikMenuDrawer"
        aria-label="Open menu" aria-controls="sikMenuDrawer" aria-expanded="false">
    <?php // Three spans rather than a glyph, so the button can morph into the
          // close X while the drawer opens. aria-label already says what it
          // does, so the lines themselves are decoration. ?>
    <span class="sik-burger__box" aria-hidden="true"><i></i><i></i><i></i></span>
</button>
<?php
$burgerHtml   = (string) ob_get_clean();
$burgerOnLeft = $hd['header_burger_position'] === 'left';
?>
<!doctype html>
<html lang="en" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <?php
    // Marks the document as scripted before the first paint, so the entrance
    // animations can hide their elements ONLY when there is JavaScript around to
    // reveal them again. Without this the rule is unconditional, and anything
    // that stops the reveal running - JS disabled or blocked, a script error
    // earlier in the file, an IntersectionObserver callback that never fires -
    // leaves that content at opacity:0 permanently. The footer is the case that
    // caught it: its whole link grid and bottom bar were invisible.
    //
    // Inline and first in the head on purpose: a deferred or external script
    // would run after the initial paint and flash the content in.
    ?>
    <script>document.documentElement.className += ' js';</script>
    <?php
    // Light / dark, decided BEFORE the stylesheet can paint. It has to be inline
    // and ahead of app.css for the same reason as the line above: anything
    // deferred runs after first paint and flashes the wrong palette.
    //
    // The shopper's own choice (localStorage `sik-theme`) wins over the store
    // default from Settings > Theme — unless the operator has switched the
    // toggle off, in which case there is no way to make a choice and a stale
    // one must not stick. "system" follows the OS setting.
    $themeModeDefault = (string) setting('theme_mode_default', 'system');
    if (!in_array($themeModeDefault, ['system', 'light', 'dark'], true)) {
        $themeModeDefault = 'system';
    }
    $themeToggleOn = setting_bool('theme_toggle_enabled', true);
    ?>
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" content="<?= e(setting('body_bg', '#FFFFFF')) ?>"
          data-light="<?= e(setting('body_bg', '#FFFFFF')) ?>" data-dark="#0E0F12">
    <script>(function(d){var m=<?= e_json($themeModeDefault) ?>,s=null;<?php if ($themeToggleOn): ?>try{s=localStorage.getItem('sik-theme')}catch(e){}if(s==='light'||s==='dark'||s==='system')m=s;<?php endif; ?>var t=m==='system'?(window.matchMedia&&matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light'):m;d.setAttribute('data-theme',t);d.setAttribute('data-theme-mode',m);d.setAttribute('data-theme-default',<?= e_json($themeModeDefault) ?>);var c=document.querySelector('meta[name=theme-color]');if(c)c.setAttribute('content',c.getAttribute('data-'+t));})(document.documentElement);</script>
    <?= csrf_meta() ?>
    <?= seo_render() ?>

    <?= brand_favicon_links() ?>
    <link rel="preconnect" href="<?= e(SITE_URL) ?>">

    <?php
    /* The two faces that carry above-the-fold text on every page: the Latin
       sans, and the latin-ext sans that owns U+20B9 — the rupee sign in front
       of every price. Ahead of the stylesheets on purpose: a webfont inside
       @font-face is only discovered once app.css has been fetched AND parsed
       AND a glyph needs it, which is three round trips too late.

       These two links are what make the typeface reachable at all. app.css
       declares the faces `font-display: optional`, so there is no swap period:
       a face that is not in hand by first paint is not used for that page
       view. Preloading is what puts both files in flight with the document
       instead of behind a 658 KB stylesheet, and so what decides whether the
       store renders in Plus Jakarta Sans or in the metric-matched fallback.

       Playfair is NOT preloaded: it is one decorative line on some pages, so
       38 KB on every page would cost more than the flourish is worth. It
       therefore sets in Georgia italic on a cold view and in Playfair once
       cached — the same line box either way, which is the trade app.css's
       overrides make affordable.

       asset() is deliberately NOT used. It appends ?v=<filemtime>, and
       app.css asks for these files as ../fonts/<name>.woff2 with no query —
       a preload of a different URL is a second download, not a head start.
       crossorigin is required even same-origin: a font is fetched in CORS
       mode, and a preload without it is discarded and fetched again. */
    foreach (['plus-jakarta-sans-latin.woff2', 'plus-jakarta-sans-latin-ext.woff2'] as $sikFontFile): ?>
    <link rel="preload" as="font" type="font/woff2" crossorigin
          href="<?= e(ASSET_URL . '/fonts/' . $sikFontFile) ?>">
    <?php endforeach; ?>

    <?php /* The utility layer, reduced to the ~76 classes this project actually
             uses. tailwind.css is a hand-built 8,487-rule subset (460 KB) that
             was downloaded and parsed on every page view to serve those few;
             utilities.css carries the same rules verbatim in 5 KB. Regenerate
             it if you add new utility classes to a template. */ ?>
    <link rel="stylesheet" href="<?= e(asset('css/utilities.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">

    <?php
    // Theme tokens come from Admin > Settings > Theme, never from a stylesheet.
    $theme = settings_group('theme');

    // Button shape and Card style were saved by Settings > Theme and shown in
    // its preview, but nothing on the storefront ever read them. They now land
    // as two role tokens every button and product card is written against.
    // Both are allowlisted: only these fixed strings can reach the style block.
    $themeBtnRadius = [
        'pill'    => 'var(--sik-radius-pill)',
        'square'  => '2px',
        'rounded' => 'var(--sik-radius-sm)',
    ][$theme['button_style'] ?? 'rounded'] ?? 'var(--sik-radius-sm)';

    [$themeCardBorder, $themeCardShadow] = [
        'flat'     => ['transparent',     'none'],
        'bordered' => ['var(--sik-line)', 'none'],
        'soft'     => ['var(--sik-line)', 'var(--sik-shadow-soft)'],
        'elevated' => ['transparent',     'var(--sik-shadow-card)'],
    ][$theme['card_style'] ?? 'flat'] ?? ['transparent', 'none'];
    ?>
    <style>
        :root {
            --sik-primary:      <?= e($theme['primary_color'] ?? '#F4511E') ?>;
            --sik-accent:       <?= e($theme['accent_color'] ?? '#FF8A3D') ?>;
            --sik-navy:         <?= e($theme['secondary_color'] ?? '#0F2143') ?>;
            --sik-text:         <?= e($theme['text_color'] ?? '#111827') ?>;
            --sik-muted:        <?= e($theme['muted_color'] ?? '#6B7280') ?>;
            --sik-border:       <?= e($theme['border_color'] ?? '#E5E7EB') ?>;
            --sik-surface:      <?= e($theme['body_bg'] ?? '#FFFFFF') ?>;
            --sik-soft:         <?= e($theme['soft_bg'] ?? '#F8F7F4') ?>;
            <?php // Settings > Theme's font family. app.css declares --sik-font
                  // with the shipped Inter stack; this line, printed after it,
                  // is what makes choosing another family reach the page at all.
                  // theme_font_stack() is an allowlist, so only one of the six
                  // fixed strings can ever land inside this style block. ?>
            --sik-font:         <?= theme_font_stack($theme['font_family'] ?? null) ?>;
            --sik-radius:       <?= (int) ($theme['border_radius'] ?? 12) ?>px;
            --sik-radius-sm:    <?= max(4, (int) ($theme['border_radius'] ?? 12) - 4) ?>px;
            --sik-radius-lg:    <?= (int) ($theme['border_radius'] ?? 12) + 6 ?>px;
            --sik-radius-btn:   <?= $themeBtnRadius ?>;
            --sik-card-border:  <?= $themeCardBorder ?>;
            --sik-card-shadow:  <?= $themeCardShadow ?>;
            --sik-container:    <?= (int) ($theme['container_width'] ?? 1280) ?>px;
            --sik-ticker-speed: <?= max(10, setting_int('ticker_speed', 40)) ?>s;

<?php
            // Admin > Appearance > Header, printed through the SAME mechanism as
            // the theme tokens above rather than a second stylesheet or a second
            // style block. app.css reads these under the "Header customization"
            // heading in section 6b; a value that is not overridden there falls
            // back to a var(--sik-…) reference, which is why an untouched store
            // renders identically.
?>
<?= header_css_vars($hd) ?>        }
        <?php /* The derived shades (-dark, -ink, -soft, navy tints, focus ring)
                 used to be re-declared here against #fff. They live in app.css
                 now, derived against the CURRENT surface, so the dark theme can
                 re-point them — a copy here would pin them to light mode. */ ?>
        <?php if (!empty($theme['custom_css'])): ?>
        <?= strip_tags((string) $theme['custom_css']) ?>
        <?php endif; ?>
    </style>
</head>
<?php
// Footer and Animations are two more Settings > Theme fields that were saved
// and never read. Classes rather than tokens: each switches a structure.
$themeBodyClass = (($theme['footer_style'] ?? 'dark') === 'light' ? 'sik-footer-light ' : '')
    . (($theme['enable_animations'] ?? '1') === '0' ? 'sik-noanim ' : '')
    // Bordered, Soft and Elevated put product cards in a surface box; Flat
    // leaves the photo and its text on the page.
    . (in_array($theme['card_style'] ?? 'flat', ['bordered', 'soft', 'elevated'], true) ? 'sik-cards-boxed ' : '')
    // Checkout drops the navigation, search and bottom bar: every exit that is
    // not "finish the order" or "go back to the cart" steps aside.
    . (basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')) === 'checkout.php' ? 'sik-focus ' : '');
?>
<body class="<?= setting_bool('mobile_bottom_nav', true) ? 'sik-has-bottomnav ' : '' ?><?= $themeBodyClass ?><?= e_attr(header_body_class($hd)) ?>">
<?php
/* The record's own body-open block, when it has one: a tag manager's
   <noscript> fallback and anything else that must exist before the page
   content is parsed. Empty unless an admin with settings.scripts put code on
   this exact record AND the visitor's consent allows it - seo_render_body_open()
   is what decides both, so nothing here needs to know. */
?><?= seo_render_body_open() ?>

<a href="#sikMain" class="sik-skip">Skip to content</a>

<?php
/* The strip carries two things, and either can be absent: the store's own
   messages (Admin > Content > Announcements) and the one running offer
   (Admin > Marketing > Coupons, "Show as offer" with the header placement).
   With neither there is no strip at all. */
$annPromo = header_promo();
$annShow  = setting_bool('ticker_enabled', true) && ($announcements !== [] || $annPromo !== null);
?>
<?php if ($annShow): ?>
<!-- ============================ Announcement bar ======================== -->
<div class="sik-announce" id="sikAnnounce" data-announce>
    <div class="sik-container">
        <div class="sik-announce__bar">

            <?php if ($announcements !== []): ?>
            <div class="sik-announce__msgs">
                <div class="sik-announce__row sik-announce__row--static">
                    <?php foreach (array_slice($announcements, 0, 3) as $annItem): ?>
                        <?php $annTag = !empty($annItem['link']) ? 'a' : 'span'; ?>
                        <<?= $annTag ?> class="sik-announce__item"<?= !empty($annItem['link']) ? ' href="' . e(url($annItem['link'])) . '"' : '' ?>>
                            <?= icon($annItem['icon'] ?: 'tag', 'w-4 h-4') ?>
                            <span><strong><?= e($annItem['text']) ?></strong><?php if (!empty($annItem['subtext'])): ?> <?= e($annItem['subtext']) ?><?php endif; ?></span>
                        </<?= $annTag ?>>
                    <?php endforeach; ?>
                </div>

                <!-- Small screens get the same messages as a scrolling ticker. -->
                <div class="sik-announce__row sik-announce__row--ticker sik-ticker">
                    <div class="sik-ticker__track">
                        <?php for ($annPass = 0; $annPass < 2; $annPass++): ?>
                            <?php foreach ($announcements as $annItem): ?>
                                <?php
                                /* Same tag decision the static row makes. The ticker is
                                   the ONLY announcement surface below 768px, and it used
                                   to hard-code <span> — so every promo link was dead on
                                   phones, and with no focusable child the
                                   `.sik-ticker:focus-within` pause rule could never fire
                                   either. The duplicated second pass stays inert. */
                                $annHasLink = !empty($annItem['link']) && $annPass === 0;
                                $annTag = $annHasLink ? 'a' : 'span';
                                ?>
                                <<?= $annTag ?> class="sik-announce__item"<?= $annHasLink ? ' href="' . e(url($annItem['link'])) . '"' : '' ?><?= $annPass === 1 ? ' aria-hidden="true"' : '' ?>>
                                    <?= icon($annItem['icon'] ?: 'tag', 'w-4 h-4') ?>
                                    <span><strong><?= e($annItem['text']) ?></strong><?php if (!empty($annItem['subtext'])): ?> <?= e($annItem['subtext']) ?><?php endif; ?></span>
                                </<?= $annTag ?>>
                            <?php endforeach; ?>
                        <?php endfor; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($annPromo !== null): ?>
                <div class="sik-announce__promo">
                    <?php // The code is the point of the chip, so it is the one thing a
                          // shopper can copy straight out of it. ?>
                    <button type="button" class="sik-announce__code" data-copy="<?= e_attr((string) $annPromo['code']) ?>"
                            title="Copy code <?= e_attr((string) $annPromo['code']) ?>">
                        <span class="sik-announce__code-label">Use code</span>
                        <span class="sik-announce__code-value"><?= e((string) $annPromo['code']) ?></span>
                    </button>
                    <span class="sik-announce__promo-text"><?= e((string) $annPromo['headline']) ?></span>

                    <?php if (!empty($annPromo['seconds_left'])): ?>
                        <?php $annDays = (int) floor($annPromo['seconds_left'] / 86400); ?>
                        <span class="sik-announce__cd sik-countdown" data-countdown="<?= (int) $annPromo['seconds_left'] ?>"
                              data-expired-text="Offer ended" aria-label="Offer ends in">
                            <?php
                            // Days only while there is more than a day left: a
                            // permanently "00 Days" cell beside a live clock reads as
                            // broken. app.js looks each cell up by data-cd and no-ops
                            // when one is absent, so dropping it is safe.
                            $annUnits = ['hours' => 'Hours', 'minutes' => 'Mins', 'seconds' => 'Secs'];
                            if ($annDays > 0) {
                                $annUnits = ['days' => 'Days'] + $annUnits;
                            }
                            ?>
                            <?php foreach ($annUnits as $annUnit => $annLabel): ?>
                                <span class="sik-announce__cell">
                                    <b data-cd="<?= e($annUnit) ?>">00</b>
                                    <i><?= e($annLabel) ?></i>
                                </span>
                            <?php endforeach; ?>
                        </span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php // Dismissal is remembered per browser by app.js. It is the last
                  // child so it always sits at the end of the strip. ?>
            <button type="button" class="sik-announce__close" data-announce-close aria-label="Dismiss announcement">
                <?= icon('close', 'w-4 h-4') ?>
            </button>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ================================ Header ============================== -->
<header class="sik-header <?= $stickyHeader ? 'sik-header--sticky' : '' ?>" id="sikHeader">
    <div class="sik-container">
        <div class="sik-header__row">

            <?php
            // Lead slot: the burger and the wordmark travel together so the row
            // has the same three children at every width - lead | search |
            // actions. They used to be siblings of the search and the action
            // cluster, which meant the row held two items on a phone and three
            // on a desktop and no single rule could describe both; the search
            // then fought .sik-actions' own auto margin for the leftover space
            // and landed 92px from the logo and 164px from the actions.
            ?>
            <div class="sik-header__lead">
                <?= $burgerOnLeft ? $burgerHtml : '' ?>

                <a href="<?= e(url()) ?>" class="sik-logo" aria-label="<?= e($storeName) ?> home">
                    <?php // Both files are rendered and the theme decides which shows, so
                          // switching to dark needs no round trip. The logo is above the
                          // fold on every page, so it is the one image never deferred. ?>
                    <?= brand_logo($storeName, false, true) ?>
                </a>

                <?php
                // The store tagline, off by default so the shipped header is
                // unchanged. It is always in the markup and revealed by
                // body.sik-htagline rather than rendered conditionally, so the
                // admin preview can turn it on inside the live iframe without a
                // page reload. aria-hidden because it repeats the wordmark's own
                // strapline and adds nothing to the accessible name.
                $headerTagline = trim((string) setting('store_tagline', ''));
                ?>
                <?php if ($headerTagline !== ''): ?>
                    <span class="sik-header__tagline" aria-hidden="true"><?= e($headerTagline) ?></span>
                <?php endif; ?>
            </div>

            <?php
            // The navigation, inline with the logo, the search field and the
            // actions. It used to be a band of its own below this row.
            //
            // That band existed for a real reason: the seeded nav carried long
            // department names (Smartphones, Computer Accessories) and one row
            // was ~240px over budget at 1280, so every label ellipsised. The
            // nav is now six short page links, and measured at 1280 the row
            // fits with the strapline and the promo badge suppressed.
            //
            // It is a grid item of .sik-header__row, so it needs no second
            // layout to fall back to: below 1200px it takes grid-column 1/-1
            // and wraps to its own line, which reproduces the old band exactly.
            // Still inside <header>, so mega panels keep dropping from the
            // header's bottom edge and header.js keeps measuring --sik-header-h.
            ?>
            <div class="sik-header__nav">
                <?php require INCLUDES_PATH . '/navbar.php'; ?>
            </div>

            <!-- Search (desktop). One component, so the mic and the suggestion
                 behaviour are identical here, in the mobile panel and on /search.
                 The navigation is no longer in this row, so the field finally has
                 room to be a real search box rather than a 180px stub.
                 Placeholder, microphone and the width it is allowed to grow to
                 all come from Admin > Appearance > Header. -->
            <?php search_bar([
                'variant'     => 'header',
                'id'          => 'sikSearchInput',
                'placeholder' => $hd['header_search_placeholder'],
                'voice'       => $hd['header_search_voice'] === '1',
                'popular'     => true,
            ]); ?>

            <?php
            // Every action is the same size, carries the same icon scale and, where
            // it has a count, the same badge from header_action_badge(). The number
            // is repeated into the accessible name because the badge is aria-hidden.
            //
            // Order is deliberate and does not change with width: utility (search,
            // notifications), saved (wishlist, compare), commerce (cart), identity
            // (account). Controls leave from the middle of that list on small
            // screens, so the first and last never move.
            ?>
            <div class="sik-actions">
                <button type="button" class="sik-action sik-search-toggle" data-open-search aria-label="Search"
                        aria-controls="sikMobileSearch" aria-expanded="false">
                    <?= icon('search', 'sik-action__icon') ?>
                </button>

                <?php
                // Each control below can be switched off in Admin > Appearance >
                // Header. A control that is off is NOT rendered rather than
                // hidden with CSS - the bell in particular costs two queries
                // through user_notifications() before it paints anything, and a
                // store that has turned notifications off should not pay for
                // them on every page view.
                ?>
                <?php if ($hd['header_show_notifications'] === '1') { notification_menu(); } ?>

                <?php // Light / dark. Desktop only: below 1024px the drawer carries a
                      // three-way Light / Dark / System switch, which is the better
                      // control on a phone and costs the crowded row nothing. Both
                      // glyphs are rendered and CSS shows the one for the theme you
                      // would switch TO, so the first paint is right without JS. ?>
                <?php if ($themeToggleOn): ?>
                <button type="button" class="sik-action sik-action--theme" data-theme-toggle
                        aria-label="Switch colour theme">
                    <?= icon('moon', 'sik-action__icon sik-theme-glyph sik-theme-glyph--moon') ?>
                    <?= icon('sun', 'sik-action__icon sik-theme-glyph sik-theme-glyph--sun') ?>
                </button>
                <?php endif; ?>

                <?php // The bottom bar carries the wishlist on every phone and tablet,
                      // so this one stands down there rather than being the second
                      // control on screen pointing at the same page. ?>
                <?php // Two different questions, both of which have to say yes: Appearance >
                      // Header decides whether this row carries a wishlist action at all,
                      // and Settings > Widgets decides whether the wishlist feature exists
                      // for this visitor. When the feature is off the page behind this link
                      // and the api/wishlist/* endpoints refuse as well, so the icon is not
                      // merely hidden. ?>
                <?php // Order tracking takes a number, not a session, so it is not
                      // hidden behind the account menu - a guest who checked out with
                      // Cash on Delivery has an order to follow too. ?>
                <?php if ($hd['header_show_track'] === '1'): ?>
                <a href="<?= e(url('track-order.php')) ?>" class="sik-action sik-action--track"
                   aria-label="Track your order">
                    <?= icon('truck', 'sik-action__icon') ?>
                    <span class="sik-action__label">Track order</span>
                </a>
                <?php endif; ?>

                <?php if ($hd['header_show_wishlist'] === '1' && wishlist_shows_on('header')): ?>
                <?php // --phone marks the two actions that stay in the bar at every
                      // width. Everything else in this cluster is desktop-side, and on
                      // a phone the drawer or the bottom bar carries it instead. ?>
                <a href="<?= e(url('wishlist.php')) ?>" class="sik-action sik-action--wishlist sik-action--phone"
                   aria-label="<?= e(header_action_label('Wishlist', $wishlistCount)) ?>">
                    <?= icon('heart', 'sik-action__icon') ?>
                    <?= header_action_badge($wishlistCount, 'data-wishlist-count') ?>
                    <span class="sik-action__label">Wishlist</span>
                </a>
                <?php endif; ?>

                <?php // Compare is a desktop affordance; the mobile drawer carries it. ?>
                <?php if ($hd['header_show_compare'] === '1' && compare_shows_on('header')): ?>
                <a href="<?= e(url('compare.php')) ?>" class="sik-action sik-action--compare"
                   aria-label="<?= e(header_action_label('Compare products', $compareCount)) ?>">
                    <?= icon('compare', 'sik-action__icon') ?>
                    <?= header_action_badge($compareCount, 'data-compare-count') ?>
                    <span class="sik-action__label">Compare</span>
                </a>
                <?php endif; ?>

                <?php if ($hd['header_show_cart'] === '1'): ?>
                <button type="button" class="sik-action sik-action--phone" data-open-cart aria-controls="sikCartDrawer"
                        aria-label="<?= e(header_action_label('Cart', $cartCount)) ?>">
                    <?= icon('cart', 'sik-action__icon') ?>
                    <?= header_action_badge($cartCount, 'data-cart-count') ?>
                    <span class="sik-action__label">Cart</span>
                </button>
                <?php endif; ?>

                <?php // Phones reach the account through the bottom bar and the drawer;
                      // showing this as well would be two controls for one destination.
                      //
                      // Signed in, the trigger carries the customer's initials instead
                      // of the generic person glyph: same 44px target, but the header
                      // now says whose session this is without adding a control. ?>
                <?php if ($hd['header_show_account'] === '1'): ?>
                <div class="sik-menu sik-menu--desk" data-menu>
                    <a class="sik-action" id="sikAccountToggle" data-menu-toggle
                       href="<?= e(url($currentUser ? 'account.php' : 'login.php')) ?>"
                       aria-label="<?= $currentUser ? 'Account menu' : 'Sign in' ?>">
                        <?php if ($currentUser !== null): ?>
                            <?php $headerName = trim((string) $currentUser['first_name'] . ' ' . (string) ($currentUser['last_name'] ?? '')); ?>
                            <span class="sik-action__avatar" aria-hidden="true"><?= e(initials($headerName)) ?></span>
                        <?php else: ?>
                            <?= icon('user', 'sik-action__icon') ?>
                        <?php endif; ?>
                        <span class="sik-action__label"><?= $currentUser ? 'Account' : 'Sign in' ?></span>
                    </a>
                    <?php account_menu_dropdown($currentUser); ?>
                </div>
                <?php endif; ?>

                <?php // Rightmost when the operator moved it here, so the visual
                      // order and the tab order still agree. ?>
                <?= $burgerOnLeft ? '' : $burgerHtml ?>
            </div>

        </div>

            <?php
            /* The phone's search, in the header rather than behind a magnifier.
               A toggle costs a tap before a shopper can even see the field, and
               on a store this size search IS the navigation.

               It is the same search_bar() the desktop row uses, so the
               suggestion panel, the recent-search list, the trending chips and
               the voice button come with it rather than being rebuilt for a
               second surface. Rendered at every width and hidden above the
               breakpoint by CSS, because the admin's header preview swaps
               widths inside a live iframe with no page reload. */
            ?>
            <div class="sik-header__searchrow">
                <?php search_bar([
                    'variant'     => 'header',
                    'id'          => 'sikSearchMobile',
                    'placeholder' => $hd['header_search_placeholder'],
                    'voice'       => $hd['header_search_voice'] === '1',
                    'popular'     => true,
                ]); ?>
            </div>



    <?php
    // ---------------------------------------------------------------------
    // Category bar.
    //
    // The navigation used to share the row above with the logo, the search
    // field and five action buttons. On the default 1280px container that row
    // is over budget by roughly 240px, so the nav was compressed until every
    // single label ellipsized — "Smartphones" rendered as "SMARTP…" — and the
    // search box was squeezed to 181px. No amount of tuning fixes that inside
    // one row; the row simply has more in it than it can hold.
    //
    // Giving the nav its own full-width row is the standard storefront answer:
    // the labels get the whole container instead of a leftover slice, and the
    // search field gets back the space the nav was fighting it for. It stays
    // inside <header> so the mega panels still drop from the header's bottom
    // edge, and header.js measures the real height into --sik-header-h, so
    // sticky offsets and anchor scrolling follow it automatically.
    //
    // "Show the category bar" in Admin > Appearance > Header switches this off
    // through body.sik-hnav-off rather than by skipping the render. This block
    // is already the one element in the header whose visibility is decided by
    // `display` at a breakpoint - it is display:none below 1024px - so one more
    // display rule is the mechanism that is already here, and it is what lets
    // the admin preview toggle the bar inside the live iframe. Every link in it
    // is also in the mobile drawer, so nothing becomes unreachable.
    // ---------------------------------------------------------------------
    ?>
</header>

<?php
// Flash messages queued by the previous request are handed to the toast system.
$flashMessages = flash_pull();
if ($flashMessages !== []):
?>
<script type="application/json" id="sikFlash"><?= e_json($flashMessages) ?></script>
<?php endif; ?>

<?php // tabindex="-1" makes the skip link actually move focus. Without it Safari
      // and Firefox scroll the page but leave focus in the header, so the next
      // Tab press lands back on the navigation the user was trying to skip. ?>
<main id="sikMain" tabindex="-1">
