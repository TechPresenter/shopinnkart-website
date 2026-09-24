<?php
/**
 * ShopInnKart - Storefront footer.
 *
 * Closes the document: the three footer bands, mobile drawers, cart drawer,
 * bottom navigation, popups and every script tag.
 */

declare(strict_types=1);

if (!defined('SIK_BOOTSTRAPPED')) {
    require_once __DIR__ . '/init.php';
}
require_once INCLUDES_PATH . '/menu-functions.php';
require_once INCLUDES_PATH . '/header-actions.php';
require_once INCLUDES_PATH . '/header-settings.php';
require_once INCLUDES_PATH . '/skeletons.php';
// Decides, in one place, whether any third-party tag may run and whether the
// consent banner is needed. The footer is where the tags are printed and where
// the "Cookie preferences" control lives, so it is the only page that needs it.
require_once INCLUDES_PATH . '/consent.php';

// The mobile search dialog lives down here but reads the header's settings.
// $hd is a local that includes/header.php leaves in the including page's
// scope, which holds for an ordinary page render and does not hold when the
// footer is rendered on its own - the admin's live header preview does
// exactly that, and it logged "Undefined variable $hd" on every paint.
// Asking for the settings ourselves costs one normalise of an array that is
// already in memory.
$hd = $hd ?? header_settings();

$storeName   = (string) setting('store_name', SITE_NAME);
$columns     = footer_columns();
$mobileMenu  = $mobileMenu ?? (build_menu('mobile') ?: build_menu('main'));
$currentUser = $currentUser ?? current_user();
$routeKey    = $routeKey ?? current_route_key();
$popups      = active_popups($routeKey);
$socialLinks = social_links();
$currentScript = basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'index.php'));

// A "when the cart reaches a value" popup needs the cart subtotal on the page
// to know whether it has been reached. Costing every page a cart read for a
// trigger almost nobody uses would be wasteful, so the number is only fetched
// when a popup that is actually about to render asks for it; cart.js keeps it
// current after that through its 'sik:totals' broadcast.
$popupCartSubtotal = null;
foreach ($popups as $popupRow) {
    if (($popupRow['trigger_type'] ?? '') === 'cart_value') {
        require_once INCLUDES_PATH . '/cart-functions.php';
        $popupCartSubtotal = (float) (cart_totals()['subtotal'] ?? 0);
        break;
    }
}

// A "product" popup is validated in the admin as needing a product to feature,
// so the featured card has to be resolvable here. Same deal as the subtotal
// above: the lookup only happens for a popup that is really going to render,
// and it reuses the shared card rather than growing a second product markup.
$popupProducts = [];
foreach ($popups as $popupRow) {
    if (($popupRow['popup_type'] ?? '') !== 'product' || (int) ($popupRow['product_id'] ?? 0) <= 0) {
        continue;
    }
    require_once INCLUDES_PATH . '/product-functions.php';
    require_once INCLUDES_PATH . '/widgets.php';
    $popupProducts[(int) $popupRow['id']] = get_product((int) $popupRow['product_id']);
}


// Contact details belong to Settings > Store, so a builder column of this type
// only decides the heading and where the block sits in the grid.
$contactRows = [];
if (($contactEmail = trim((string) setting('store_email', ''))) !== '') {
    $contactRows[] = ['icon' => 'mail', 'text' => $contactEmail, 'href' => 'mailto:' . $contactEmail];
}
if (($contactPhone = trim((string) setting('store_phone', ''))) !== '') {
    // tel: wants digits and an optional country prefix, nothing else.
    $contactRows[] = [
        'icon' => 'phone',
        'text' => $contactPhone,
        'href' => 'tel:' . preg_replace('/[^\d+]/', '', $contactPhone),
    ];
}
if (($contactAddress = trim((string) setting('store_address', ''))) !== '') {
    $contactRows[] = ['icon' => 'location', 'text' => $contactAddress, 'href' => null];
}
if (($businessHours = trim((string) setting('business_hours', ''))) !== '') {
    $contactRows[] = ['icon' => 'clock', 'text' => $businessHours, 'href' => null];
}

// The policy row used to be four hard-coded .php filenames. Those are CMS rows
// an admin can retitle or unpublish, so both the label and the URL now come
// from `pages`; only "which slugs count as a legal notice" stays in code,
// because that is routing rather than content.
$policyLinks = cache_remember('footer.policies', 600, static function (): array {
    [$slugIn, $slugParams] = Database::inPlaceholders(
        ['privacy-policy', 'terms-conditions', 'shipping-policy', 'return-policy', 'refund-policy'],
        'slug'
    );

    return Database::fetchAll(
        "SELECT `title`, `slug` FROM `pages`
         WHERE `slug` IN ($slugIn) AND `status` = 'active' AND `show_in_footer` = 1
         ORDER BY `sort_order`, `id`",
        $slugParams
    );
});

$footerColIndex = 0;   // gives every disclosure panel a stable, unique id
?>
</main>

<!-- ================================ Footer ============================== -->
<?php // id: Admin > Footer Builder's "View footer" button opens the store at #footer. ?>
<footer class="sik-footer" id="footer">


    <!-- --------------------------- Main grid ---------------------------- -->
    <div class="sik-footer__main">
        <div class="sik-container">
            <div class="sik-footer__grid" data-anim="fade-up" data-anim-delay="80">

                <!-- Brand -->
                <div class="sik-footer__brand">
                    <?php // A dark footer carries the light wordmark in both themes; a
                          // light one follows the page theme the way the header does. ?>
                    <a href="<?= e(url()) ?>" class="sik-footer__logo" aria-label="<?= e($storeName) ?> home">
                        <?= brand_logo($storeName, setting('footer_style', 'dark') !== 'light') ?>
                    </a>

                    <?php if (($storeAbout = str_limit((string) setting('store_description', ''), 190)) !== ''): ?>
                        <p class="sik-footer__about"><?= e($storeAbout) ?></p>
                    <?php endif; ?>

                    <?php if ($socialLinks !== []): ?>
                        <ul class="sik-social">
                            <?php foreach ($socialLinks as $social): ?>
                                <li>
                                    <a class="sik-social__link sik-social__link--<?= e($social['key']) ?>"
                                       href="<?= e($social['url']) ?>" target="_blank" rel="noopener noreferrer"
                                       aria-label="<?= e($storeName . ' on ' . $social['label']) ?>">
                                        <?= social_icon($social['key'], 'sik-social__glyph') ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>

                <!-- Builder-driven columns -->
                <?php foreach ($columns as $column): ?>
                    <?php
                    // One branch per column_type, and every type the enum allows
                    // has one. The builder's descriptions live in
                    // admin/footer/_meta.php and are written against this loop:
                    //
                    //   links    -> its footer_links rows
                    //   about    -> its `content`, in the brand column's typography
                    //   contact  -> the store details from Settings > Store
                    //   payment  -> the methods checkout can really offer
                    //   html     -> its `content`, as sanitised markup
                    //
                    // 'about' used to `continue` here, which made the shipped
                    // "About ShopInnKart" column - text and all - reach nothing.
                    // 'payment' was in the enum with no branch and no admin
                    // option, so it could never be chosen either.
                    $columnType = (string) $column['column_type'];
                    $isContact  = $columnType === 'contact';
                    $isHtml     = $columnType === 'html';
                    $isAbout    = $columnType === 'about';
                    $isPayment  = $columnType === 'payment';
                    $isLinkList = !$isContact && !$isHtml && !$isAbout && !$isPayment;

                    // sanitize_html() already ran on save; it runs again here so
                    // a row written before that was true cannot smuggle markup in.
                    $htmlBody  = ($isHtml || $isAbout)
                        ? trim(sanitize_html((string) ($column['content'] ?? '')))
                        : '';

                    // The payment strip is the methods checkout would actually
                    // offer - active in Settings > Payment *and* backed by a
                    // gateway class - so the footer can never advertise a way to
                    // pay that the checkout does not list. Loaded only when a
                    // column asks for it, like the cart subtotal above.
                    $payMethods = [];
                    if ($isPayment) {
                        require_once INCLUDES_PATH . '/order-functions.php';
                        $payMethods = PaymentGatewayFactory::available();
                    }

                    // A column with nothing to show is not a column.
                    if (($isLinkList && $column['links'] === [])
                        || ($isContact && $contactRows === [])
                        || (($isHtml || $isAbout) && $htmlBody === '')
                        || ($isPayment && $payMethods === [])) {
                        continue;
                    }

                    $footerColIndex++;
                    $panelId = 'sikFooterPanel' . $footerColIndex;
                    $titleId = 'sikFooterTitle' . $footerColIndex;
                    ?>
                    <?php
                    // The contact column is marked so it can take a wider track:
                    // a support alias and a street address need more measure than
                    // a list of one-word links, and at a single track width the
                    // email broke mid-word ("shopinnkart.co / m").
                    ?>
                    <div class="sik-footer__col<?= $isLinkList ? ' sik-footer__col--acc' : '' ?><?= $isContact ? ' sik-footer__col--contact' : '' ?>"<?= $isLinkList ? ' data-acc-item' : '' ?>>
                        <div class="sik-footer__head">
                            <?php /* h2, not h3: the footer columns are top-level
                                     sections of the document. As h3 they skipped a
                                     level on every page whose deepest heading is the
                                     page h1 (login, account, orders, …). */ ?>
                            <h2 class="sik-footer__title" id="<?= e($titleId) ?>"><?= e($column['title']) ?></h2>

                            <?php if ($isLinkList): ?>
                                <?php
                                // The toggle covers the whole heading row on phones and is
                                // display:none from 640px up, where the panel is always
                                // open — so aria-expanded never reports a state that is
                                // not real. aria-labelledby borrows the heading text
                                // instead of repeating it.
                                ?>
                                <button type="button" class="sik-footer__toggle" data-acc-toggle
                                        aria-expanded="false" aria-controls="<?= e($panelId) ?>"
                                        aria-labelledby="<?= e($titleId) ?>">
                                    <?= icon('chevron-down', 'sik-footer__chev') ?>
                                </button>
                            <?php endif; ?>
                        </div>

                        <div class="sik-footer__panel" id="<?= e($panelId) ?>">
                            <?php if ($isContact): ?>
                                <ul class="sik-footer__contact">
                                    <?php foreach ($contactRows as $row): ?>
                                        <li>
                                            <span class="sik-footer__contact-icon"><?= icon($row['icon'], 'sik-footer__glyph') ?></span>
                                            <?php if ($row['href'] !== null): ?>
                                                <a href="<?= e($row['href']) ?>"><?= e($row['text']) ?></a>
                                            <?php else: ?>
                                                <span><?= e($row['text']) ?></span>
                                            <?php endif; ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>

                            <?php elseif ($isAbout): ?>
                                <?php // Same measure and muted ink as the brand blurb above,
                                      // which is what makes this an "About text" column rather
                                      // than a second Custom HTML one. ?>
                                <div class="sik-footer__about"><?= $htmlBody ?></div>

                            <?php elseif ($isHtml): ?>
                                <div class="sik-prose"><?= $htmlBody ?></div>

                            <?php elseif ($isPayment): ?>
                                <?php
                                // A logo row, not links: each tile is the method's own
                                // artwork from Settings > Payment, with the name as the
                                // accessible text so a missing or decorative logo still
                                // reads. No claim is made here that the store does not
                                // already make at checkout.
                                ?>
                                <?php
                                /* Acceptance marks for the networks the enabled gateways
                                   really settle - see accepted_payment_marks(). The old row
                                   drew payment_methods.logo, which for every gateway but COD
                                   is the same grey placeholder SVG, so five payment options
                                   rendered as five identical tiles. */
                                $payMarks = accepted_payment_marks();
                                ?>
                                <?php if ($payMarks !== []): ?>
                                    <ul class="sik-footer__pay">
                                        <?php foreach ($payMarks as $payMark): ?>
                                            <li class="sik-footer__pay-item sik-paymark" title="<?= e_attr(payment_mark_label($payMark)) ?>">
                                                <?= payment_mark_html($payMark) ?>
                                                <span class="sik-sr"><?= e(payment_mark_label($payMark)) ?></span>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>

                                <?php // What the marks cannot say: the names checkout will
                                      // actually list. Kept as text under them. ?>
                                <p class="sik-footer__paynote">
                                    <?= e(implode(' · ', array_map(static fn ($m) => (string) $m['name'], $payMethods))) ?>
                                </p>

                            <?php else: ?>
                                <nav class="sik-footer__links" aria-label="<?= e($column['title']) ?>">
                                    <?php foreach ($column['links'] as $link): ?>
                                        <?php
                                        // Same resolver the Menu Builder uses, so a
                                        // mailto:, tel: or //cdn link typed into either
                                        // screen comes out the same way.
                                        $linkUrl = nav_link_url((string) $link['url']);
                                        ?>
                                        <a href="<?= e($linkUrl) ?>"<?= (int) $link['open_new_tab'] === 1
                                            ? ' target="_blank" rel="noopener noreferrer"' : '' ?>><?= e($link['label']) ?></a>
                                    <?php endforeach; ?>
                                </nav>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- --------------------------- Bottom bar --------------------------- -->
    <div class="sik-footer__bar">
        <div class="sik-container">
            <div class="sik-footer__bar-row" data-anim="fade" data-anim-delay="120">
                <p class="sik-footer__copy">
                    <span><?= e(setting('copyright_text', '© ' . date('Y') . ' ' . $storeName . '. All Rights Reserved.')) ?></span>
                    <?php if (($gstNumber = trim((string) setting('gst_number', ''))) !== ''): ?>
                        <span class="sik-footer__gst">GSTIN <?= e($gstNumber) ?></span>
                    <?php endif; ?>

                    <?php
                    /* Who built the store. Admin-owned like the copyright beside it, so
                       the owner can reword or remove it without an edit here; an empty
                       credit line prints nothing at all, and an empty link prints the
                       text as plain words rather than a dead anchor. */
                    $creditText = trim((string) setting('credit_text', ''));
                    $creditUrl  = trim((string) setting('credit_url', ''));
                    ?>
                    <?php if ($creditText !== ''): ?>
                        <span class="sik-footer__credit">
                            <?php if ($creditUrl !== ''): ?>
                                <a href="<?= e($creditUrl) ?>" target="_blank" rel="noopener noreferrer"><?= e($creditText) ?></a>
                            <?php else: ?>
                                <?= e($creditText) ?>
                            <?php endif; ?>
                        </span>
                    <?php endif; ?>
                </p>

                <?php
                // The preferences control sits with the policies because that is
                // where a visitor looks for it, and because it is the second half
                // of what the Cookie Policy promises: a way to change your mind.
                // It prints nothing on a store with no tags and no opt-in mode.
                $consentPrefs = consent_preferences_button('sik-consent-link');
                ?>
                <?php if ($policyLinks !== [] || $consentPrefs !== ''): ?>
                    <nav class="sik-footer__legal" aria-label="Policies">
                        <?php foreach ($policyLinks as $policy): ?>
                            <a href="<?= e(page_url((string) $policy['slug'])) ?>"><?= e($policy['title']) ?></a>
                        <?php endforeach; ?>
                        <?= $consentPrefs ?>
                    </nav>
                <?php endif; ?>
            </div>
        </div>
    </div>
</footer>

<!-- ========================= Mobile menu drawer ========================= -->
<aside class="sik-drawer sik-drawer--left" id="sikMenuDrawer" aria-hidden="true" role="dialog" aria-label="Menu">
    <div class="sik-drawer__head">
        <a href="<?= e(url()) ?>" class="sik-logo" aria-label="<?= e($storeName) ?> home">
            <?= brand_logo($storeName) ?>
        </a>
        <button type="button" class="sik-iconbtn" data-close-drawer aria-label="Close menu"><?= icon('close', 'w-4 h-4') ?></button>
    </div>

    <div class="sik-drawer__body">
        <div class="sik-drawer__account">
            <?php if ($currentUser): ?>
                <?php $drawerName = trim((string) $currentUser['first_name'] . ' ' . (string) ($currentUser['last_name'] ?? '')); ?>
                <a class="sik-drawer__user" href="<?= e(url('account.php')) ?>">
                    <span class="sik-menu__avatar" aria-hidden="true"><?= e(initials($drawerName)) ?></span>
                    <span class="sik-menu__ident">
                        <span class="sik-menu__name"><?= e($drawerName !== '' ? $drawerName : 'My account') ?></span>
                        <span class="sik-menu__mail"><?= e((string) $currentUser['email']) ?></span>
                    </span>
                    <?= icon('chevron-right', 'sik-menu__go') ?>
                </a>
            <?php else: ?>
                <?php // One card, one destination. Two equal buttons made a visitor
                      // choose between "Sign In" and "Register" before they had any
                      // reason to care which; the sign-in page carries the link to
                      // the other one anyway. ?>
                <a class="sik-drawer__signin" href="<?= e(url('login.php')) ?>">
                    <span class="sik-drawer__signin-avatar" aria-hidden="true"><?= icon('user', 'w-6 h-6') ?></span>
                    <span class="sik-drawer__signin-text">
                        <b>Hello there!</b>
                        <i>Sign in or create an account</i>
                    </span>
                    <?= icon('chevron-right', 'sik-drawer__signin-go') ?>
                </a>
            <?php endif; ?>
        </div>

        <nav aria-label="Mobile menu">
            <p class="sik-mmenu__label">Browse</p>
            <?php
            /**
             * The drawer *is* the navigation below 1024px, so it is the mobile
             * surface: an item marked "Mobile only" belongs here and one marked
             * "Desktop only" does not, and the audience rule the bar applies has
             * to apply here too. Filtered at render time rather than inside
             * build_menu(), whose tree is cached and shared by every visitor.
             */
            $drawerMenu = menu_surface_items($mobileMenu, 'mobile');

            /**
             * Three things the Menu Builder stores were rendered by the desktop
             * bar and dropped here: the icon (which its own help text promises
             * appears in this drawer), the badge and its colour, and the
             * new-tab flag. One closure each, called from both the parent and
             * the child branch, so the two never drift apart.
             */
            $drawerGlyph = static function (array $row): void {
                // menu_item_glyph() is the bar's resolver too: it returns '' for
                // an unknown key (which must render nothing rather than fall
                // through icon() and stamp an info bubble), for an upload whose
                // file has gone, and for an icon the admin has switched off for
                // this surface. 'mobile' is the surface this drawer *is*, not a
                // guess about the device — see menu_surface_items().
                $glyph = menu_item_glyph($row, 'mobile', 'w-4 h-4');
                if ($glyph === '') {
                    // The drawer reads as a list of icons, so a row without one
                    // leaves a hole in the column and the labels stop lining up.
                    // A guess from the destination beats an empty slot: the Menu
                    // Builder still wins whenever an operator has chosen an icon.
                    $glyph = icon(drawer_fallback_icon($row), 'w-4 h-4');
                }
                if ($glyph === '') {
                    return;
                }
                echo '<span class="sik-mmenu__glyph" aria-hidden="true">' . $glyph . '</span>';
            };

            /**
             * The label and its chip, in the order `badge_position` asks for.
             *
             * One closure rather than a bare badge printer because the four call
             * sites below all wrap the pair in the same <span> — leaving the
             * order to each of them is how three of them would end up ignoring
             * the setting. .sik-nav__badge is the bar's chip class, so a badge
             * styled once looks the same in both navigations; its -7px lift is
             * meant for the header bar, and --drawer puts it back on the label's
             * baseline here.
             */
            $drawerLabel = static function (array $row): void {
                // --lead is what tells the stylesheet which side the gap goes
                // on: the label is a bare text node in here, so a leading chip
                // is the span's first *element* child and :first-child cannot
                // tell the two orders apart. Set from the same call that decides
                // the DOM order, so the class and the markup always agree.
                $lead = menu_badge_first($row);
                $chip = menu_badge_chip(
                    $row,
                    'sik-nav__badge sik-nav__badge--drawer' . ($lead ? ' sik-nav__badge--lead' : '')
                );
                echo '<span>'
                    . ($lead ? $chip : '')
                    . e((string) $row['label'])
                    . ($lead ? '' : $chip)
                    . '</span>';
            };

            $drawerTab = static function (array $row): string {
                return !empty($row['open_new_tab']) ? ' target="_blank" rel="noopener"' : '';
            };
            ?>
            <div class="sik-mmenu">
            <?php foreach ($drawerMenu as $item): ?>
                <div class="sik-mmenu__item" data-mmenu-item>
                    <?php if (!empty($item['children'])): ?>
                        <button type="button" class="sik-mmenu__link" data-mmenu-toggle aria-expanded="false">
                            <?php $drawerGlyph($item); ?>
                            <?php $drawerLabel($item); ?>
                            <?= icon('chevron-down', 'w-4 h-4') ?>
                        </button>
                        <?php // One wrapper, so grid-template-rows can animate the whole
                              // list instead of just its first row. ?>
                        <div class="sik-mmenu__sub">
                        <div class="sik-mmenu__subinner">
                            <a class="sik-mmenu__sublink" href="<?= e($item['href']) ?>"<?= $drawerTab($item) ?>><span>All <?= e($item['label']) ?></span><?= icon('arrow-right', 'w-3.5 h-3.5') ?></a>
                            <?php // The glyph belongs on a nested row too. The icon
                                  // picker's help text promises "shown in the mobile
                                  // drawer" without qualification, the badge beside
                                  // it is already drawn at every depth, and the
                                  // desktop mega panel draws the same child's icon —
                                  // so an icon set on a nested item was stored, shown
                                  // in the picker and rendered nowhere. Reached with
                                  // the shipped data as soon as the mobile menu is
                                  // switched off and this drawer falls back to Main,
                                  // whose items all have children. ?>
                            <?php foreach ($item['children'] as $child): ?>
                                <a class="sik-mmenu__sublink" href="<?= e($child['href']) ?>"<?= $drawerTab($child) ?>><?php $drawerGlyph($child); ?><?php $drawerLabel($child); ?></a>
                                <?php foreach (array_slice($child['children'] ?? [], 0, 8) as $grandchild): ?>
                                    <a class="sik-mmenu__sublink sik-mmenu__sublink--deep" href="<?= e($grandchild['href']) ?>"<?= $drawerTab($grandchild) ?>>
                                        <?php $drawerGlyph($grandchild); ?>
                                        <?php $drawerLabel($grandchild); ?>
                                    </a>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        </div>
                        </div>
                    <?php else: ?>
                        <a class="sik-mmenu__link" href="<?= e($item['href']) ?>"<?= $drawerTab($item) ?>>
                            <?php $drawerGlyph($item); ?>
                            <?php $drawerLabel($item); ?>
                            <?= icon('chevron-right', 'w-4 h-4') ?>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            </div>

            <?php
            // Deliberately none of Home / Shop / Deals / Wishlist / Account: the
            // bottom bar owns those five, and repeating them here is how a drawer
            // ends up feeling like a second, competing navigation.
            $drawerExtras = [
                ['label' => 'Track Order', 'url' => 'track-order.php', 'icon' => 'truck'],
                // The bottom bar gave its fifth slot to the cart, so the account
                // needs a route of its own on a phone.
                ['label' => $currentUser ? 'My Account' : 'Sign In', 'url' => $currentUser ? 'account.php' : 'login.php', 'icon' => 'user'],
                ['label' => 'Contact Us',  'url' => 'contact.php',     'icon' => 'headset'],
            ];

            // The drawer IS the compare affordance on a phone (the header's own
            // action is desktop-only), so it follows the same switch. A link to a
            // page that refuses is worse than no link.
            if (compare_shows_on('header')) {
                array_splice($drawerExtras, 1, 0, [
                    ['label' => 'Compare', 'url' => 'compare.php', 'icon' => 'compare'],
                ]);
            }
            ?>
            <p class="sik-mmenu__label">Support</p>
            <div class="sik-mmenu">
            <?php foreach ($drawerExtras as $drawerExtra): ?>
                <div class="sik-mmenu__item">
                    <?php // Same row shape as Browse above: tile, label, chevron. These
                          // used to trail their icon on the right with no tile, which read
                          // as a second, unrelated kind of list. ?>
                    <a class="sik-mmenu__link" href="<?= e(url((string) $drawerExtra['url'])) ?>">
                        <span class="sik-mmenu__glyph" aria-hidden="true"><?= icon((string) $drawerExtra['icon'], 'w-4 h-4') ?></span>
                        <span><?= e((string) $drawerExtra['label']) ?></span>
                        <?= icon('chevron-right', 'w-4 h-4') ?>
                    </a>
                </div>
            <?php endforeach; ?>

            <?php if ($currentUser): ?>
                <div class="sik-mmenu__item">
                    <?php // Signing out is a state change, so it posts with a token. ?>
                    <form method="post" action="<?= e(url('logout.php')) ?>">
                        <?= csrf_field() ?>
                        <button type="submit" class="sik-mmenu__link sik-mmenu__link--danger">
                            <span>Log out</span>
                            <?= icon('logout', 'w-4 h-4') ?>
                        </button>
                    </form>
                </div>
            <?php endif; ?>
            </div>
        </nav>

        <?php // The phone's theme control. Three states rather than a flip, because
              // "follow my phone" is the setting most people actually want and a
              // two-state toggle has no way to go back to it once touched. ?>
        <?php if (setting_bool('theme_toggle_enabled', true)): ?>
            <div class="sik-drawer__theme">
                <p class="sik-mmenu__label" id="sikThemeLabel">Appearance</p>
                <div class="sik-segmented" role="group" aria-labelledby="sikThemeLabel">
                    <button type="button" class="sik-segmented__opt" data-theme-set="light" aria-pressed="false">
                        <?= icon('sun', 'w-4 h-4') ?><span>Light</span>
                    </button>
                    <button type="button" class="sik-segmented__opt" data-theme-set="dark" aria-pressed="false">
                        <?= icon('moon', 'w-4 h-4') ?><span>Dark</span>
                    </button>
                    <button type="button" class="sik-segmented__opt" data-theme-set="system" aria-pressed="false">
                        <?= icon('monitor', 'w-4 h-4') ?><span>Auto</span>
                    </button>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <?php
    /* The promotional card the reference puts at the bottom of the drawer.
       It advertises whatever offer an operator has actually marked visible -
       with nothing running there is no card, rather than a permanent box
       promising a discount that does not exist. */
    $drawerOffer = header_promo();
    ?>
    <?php if ($drawerOffer !== null): ?>
        <a class="sik-drawer__promo" href="<?= e(url('shop.php')) ?>">
            <span class="sik-drawer__promo-icon" aria-hidden="true"><?= icon('tag', 'w-5 h-5') ?></span>
            <span class="sik-drawer__promo-text">
                <b><?= e((string) $drawerOffer['headline']) ?></b>
                <i>Use code <?= e((string) $drawerOffer['code']) ?></i>
            </span>
            <?= icon('arrow-right', 'sik-drawer__promo-go') ?>
        </a>
    <?php endif; ?>

    <?php if ($supportPhone = (string) setting('store_phone', '')): ?>
        <div class="sik-drawer__foot">
            <div class="sik-drawer__help">
                <?= icon('headset', 'w-5 h-5') ?>
                <span>Need help?
                    <a href="tel:<?= e(preg_replace('/\s+/', '', $supportPhone)) ?>"><?= e($supportPhone) ?></a>
                </span>
            </div>
        </div>
    <?php endif; ?>
</aside>

<?php
// ============================== Search dialog =============================
// Moved here out of <header>.
//
// It lived inside <header class="sik-header sik-header--sticky">, and that
// header is position:sticky with z-index var(--z-header) = 60, which creates a
// stacking context. A descendant cannot escape it, so the dialog's own
// z-index: var(--z-modal) = 120 only ever ranked it against its siblings
// INSIDE the header - never against the rest of the page. .sik-bottomnav is
// position:fixed at the same z-index 60 and comes later in the document, so it
// won on source order: measured on a 390px viewport with the dialog open,
// elementFromPoint at the bottom centre of the screen returned the bottom nav,
// painting over the dialog's backdrop. .sik-overlay (90) and .sik-drawer (95)
// outranked it for the same reason.
//
// Nothing in it depended on being inside the header: it is position:fixed and
// search.js finds it with a document-wide [data-search-pop] query. As a direct
// child of <body> its z-index finally means what it says.
?>
    <?php
    // The floating mini search. Opened by the search action at any width, and
    // by Ctrl/Cmd+K or "/" from anywhere. It is a dialog rather than an
    // in-flow panel so opening it never reflows the page behind it.
    //
    // The field is the same search_bar() component as the header and /search,
    // which is what keeps the microphone, the suggestion behaviour and the
    // keyboard handling identical on all three.
    ?>
    <div id="sikMobileSearch" hidden class="sik-searchpop" data-search-pop>
        <div class="sik-searchpop__backdrop" data-search-pop-close></div>

        <div class="sik-searchpop__panel" role="dialog" aria-modal="true"
             aria-label="Search products">
            <?php
            // Field and close button on one row. There is no title and no
            // keyboard-hint strip: a search dialog that opens with the caret
            // already in the box does not need a heading telling you it is a
            // search box, and the hints were three lines of chrome around a
            // control most people use for four seconds.
            ?>
            <div class="sik-searchpop__bar">
                <?php
                // Same two Appearance > Header settings the inline field
                // above obeys. They have to be passed here as well: when
                // "Show the search field inline from" is set to Never - or
                // the Minimal style is selected - this dialog is the ONLY
                // search surface in the header, so a hard-coded placeholder
                // and an unconditional microphone meant both settings
                // silently did nothing for exactly the operators who had
                // moved search into this dialog on purpose.
                search_bar([
                    'variant'     => 'drawer',
                    'id'          => 'sikSearchInputMobile',
                    'placeholder' => $hd['header_search_placeholder'],
                    'voice'       => $hd['header_search_voice'] === '1',
                ]); ?>

                <button type="button" class="sik-searchpop__close" data-search-pop-close
                        aria-label="Close search">
                    <?= icon('close') ?>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ============================ Cart drawer ============================= -->
<aside class="sik-drawer sik-drawer--right" id="sikCartDrawer" aria-hidden="true" role="dialog" aria-label="Shopping cart">
    <div class="sik-drawer__head">
        <span class="sik-drawer__title">Your Cart</span>
        <button type="button" class="sik-iconbtn" data-close-drawer aria-label="Close cart"><?= icon('close', 'w-4 h-4') ?></button>
    </div>
    <?php // The drawer's lines are fetched when it first opens (cart.js), and
          // that is also when it paints its skeleton. It used to be rendered
          // here, which meant two .sik-skel elements with a running infinite
          // shimmer sat inside a closed, visibility:hidden drawer on every page
          // view - an animation ticking from first paint for something most
          // visits never open. cart.js clones sikSkel-cartline on the first
          // load instead, and replaces it with the lines, the empty state or a
          // Retry - never leaves it. ?>
    <div class="sik-drawer__body" id="sikCartDrawerBody"></div>
    <div class="sik-drawer__foot" id="sikCartDrawerFoot" hidden>
        <div class="sik-drawer__total">
            <span>Total</span><span id="sikCartDrawerTotal">—</span>
        </div>
        <div class="sik-drawer__actions">
            <a href="<?= e(url('checkout.php')) ?>" class="sik-btn sik-btn--primary sik-btn--block">Checkout</a>
            <a href="<?= e(url('cart.php')) ?>" class="sik-btn sik-btn--outline sik-btn--block">View Cart</a>
        </div>
    </div>
</aside>

<?php if (setting_bool('mobile_bottom_nav', true)): ?>
<!-- ========================= Mobile bottom nav ========================= -->
<?php
/**
 * Built from a list rather than five hand-written anchors, because the
 * wishlist slot is now conditional: Settings > Widgets can switch the wishlist
 * off, and a bar that keeps a link to a page which refuses is worse than a bar
 * with four cells. The track count travels with the list through
 * --bottomnav-cols so the grid never has an empty column.
 */
$bottomNavItems = [
    ['url' => '',            'key' => 'index.php',    'icon' => 'home',    'label' => 'Home'],
    ['url' => 'shop.php',    'key' => 'shop.php',     'icon' => 'grid',    'label' => 'Categories'],
];

// Deals only while a deal or a flash sale is actually live. With nothing on, the
// tab opened an empty page, so search takes the slot instead.
$bottomNavItems[] = (get_active_deal() !== null || get_active_flash_sale() !== null)
    ? ['url' => 'deals.php',  'key' => 'deals.php',  'icon' => 'percent', 'label' => 'Deals']
    : ['url' => 'search.php', 'key' => 'search.php', 'icon' => 'search',  'label' => 'Search'];

if (wishlist_shows_on('header')) {
    $bottomNavItems[] = [
        'url'   => 'wishlist.php',
        'key'   => 'wishlist.php',
        'icon'  => 'heart',
        'label' => 'Wishlist',
        // Same badge renderer as the header, so the two can never diverge.
        'badge' => header_action_badge(wishlist_count(), 'data-wishlist-count'),
    ];
}

// The cart, as a button rather than a link: it opens the mini-cart drawer that
// is already on the page, so a shopper can check the basket without leaving
// whatever they were reading. The href is kept as the no-JavaScript fallback.
$bottomNavItems[] = [
    'url'    => 'cart.php',
    'key'    => 'cart.php',
    'icon'   => 'cart',
    'label'  => 'Cart',
    'drawer' => 'sikCartDrawer',
    'badge'  => header_action_badge(cart_count(), 'data-cart-count'),
];
?>
<nav class="sik-bottomnav" aria-label="Quick navigation"
     style="--bottomnav-cols:<?= count($bottomNavItems) ?>">
    <?php foreach ($bottomNavItems as $navItem): ?>
        <a href="<?= e(url((string) $navItem['url'])) ?>" class="sik-bottomnav__item"
           data-bottomnav-item="<?= e_attr((string) $navItem['key']) ?>"
           <?= isset($navItem['drawer']) ? 'data-open-drawer="' . e_attr((string) $navItem['drawer']) . '"' : '' ?>>
            <?= icon((string) $navItem['icon'], 'w-5 h-5') ?><span><?= e((string) $navItem['label']) ?></span>
            <?= $navItem['badge'] ?? '' ?>
        </a>
    <?php endforeach; ?>
</nav>
<?php endif; ?>

<!-- =========================== Floating actions ========================= -->
<?php
// Every floating button - WhatsApp, call, chat, support, back to top, custom -
// is a row in `floating_buttons`, so its icon, label, tooltip, colour, size,
// animation, corner, order and per-device audience are all admin-owned. There
// is nothing left to hard-code here.
require_once INCLUDES_PATH . '/floating-functions.php';
render_floating_stacks();
?>

<?php
// ============================== Popups & pop-ins ==========================
foreach ($popups as $popup):
    $isPopin = $popup['display_mode'] === 'popin';
    $domId = 'sikPopup' . (int) $popup['id'];

    // Admin > Popups > Appearance colours, and the optional portrait crop that
    // the media panel promises "falls back to the main image when empty".
    $popupStyle  = popup_theme_style($popup);
    // Position drives pop-ins through a modifier class; a modal reads it as the
    // two flex axes of .sik-modal, so "Bottom right" no longer renders centred.
    $popupAnchor = $isPopin ? '' : popup_anchor_style((string) ($popup['position'] ?? 'center'));
    $popupInk    = popup_ink_style($popup);
    $popupSubInk = popup_ink_style($popup, true);
    $popupImage  = (string) ($popup['image'] ?? '');
    $popupMobile = (string) ($popup['mobile_image'] ?? '');
    $popupPoster = $popupImage !== '' ? $popupImage : $popupMobile;
?>
    <?php if ($isPopin): ?>
        <div class="sik-popin sik-popin--<?= e($popup['position'] === 'center' ? 'bottom-left' : $popup['position']) ?>"
             id="<?= e($domId) ?>"
             <?= $popupStyle !== '' ? 'style="' . e_attr($popupStyle) . '"' : '' ?>
             data-popup="<?= (int) $popup['id'] ?>"
             data-trigger="<?= e($popup['trigger_type']) ?>"
             data-trigger-value="<?= (int) $popup['trigger_value'] ?>"
             data-frequency="<?= e($popup['frequency']) ?>"
             role="status">
            <?php if ($popupPoster !== ''): ?>
                <img src="<?= e(img_url($popupPoster)) ?>" alt="" width="52" height="52"
                     style="width:52px;height:52px;object-fit:contain;flex:none" loading="lazy">
            <?php endif; ?>
            <div style="flex:1;min-width:0">
                <?php if (!empty($popup['title'])): ?>
                    <strong style="display:block;font-size:13.5px;<?= e_attr($popupInk) ?>"><?= e($popup['title']) ?></strong>
                <?php endif; ?>
                <?php if (!empty($popup['subtitle'])): ?>
                    <span style="display:block;font-size:12.5px;margin-top:2px;<?= e_attr($popupSubInk) ?>"><?= e($popup['subtitle']) ?></span>
                <?php endif; ?>
                <?php if (!empty($popup['coupon_code'])): ?>
                    <button type="button" class="sik-badge sik-badge--soft" style="margin-top:var(--sp-2);cursor:pointer;border:1px dashed var(--sik-primary)"
                            data-copy="<?= e($popup['coupon_code']) ?>">
                        <?= e($popup['coupon_code']) ?> · tap to copy
                    </button>
                <?php elseif (!empty($popup['button_text'])): ?>
                    <a class="sik-btn sik-btn--primary sik-btn--sm" style="margin-top:var(--sp-2)"
                       href="<?= e(url((string) $popup['button_url'])) ?>"><?= e($popup['button_text']) ?></a>
                <?php endif; ?>
            </div>
            <?php if ((int) $popup['show_close'] === 1): ?>
                <button type="button" class="sik-toast__close" style="<?= e_attr($popupSubInk) ?>"
                        data-popup-close aria-label="Dismiss"><?= icon('close', 'w-4 h-4') ?></button>
            <?php endif; ?>
        </div>

    <?php else: ?>
        <?php /* --promo keeps a promotional interruption to a small card rather
                 than the full-width sheet the generic modal allows. */ ?>
        <div class="sik-modal sik-modal--promo" id="<?= e($domId) ?>" aria-hidden="true" role="dialog" aria-modal="true"
             <?= $popupAnchor !== '' ? 'style="' . e_attr($popupAnchor) . '"' : '' ?>
             data-popup="<?= (int) $popup['id'] ?>"
             data-trigger="<?= e($popup['trigger_type']) ?>"
             data-trigger-value="<?= (int) $popup['trigger_value'] ?>"
             data-frequency="<?= e($popup['frequency']) ?>">
            <div class="sik-modal__backdrop"></div>
            <div class="sik-modal__panel sik-modal__panel--<?= e($popup['size']) ?>"
                 <?= $popupStyle !== '' ? 'style="' . e_attr($popupStyle) . '"' : '' ?>>
                <?php if ((int) $popup['show_close'] === 1): ?>
                    <button type="button" class="sik-modal__close" data-popup-close aria-label="Close"><?= icon('close', 'w-4 h-4') ?></button>
                <?php endif; ?>

                <!-- minmax(0, 1fr): a bare fr track cannot shrink below its widest
                     unbreakable child, which would burst the modal panel at 320px. -->
                <div style="display:grid;grid-template-columns:minmax(0,1fr)">
                    <?php if ($popupPoster !== ''): ?>
                        <picture>
                            <?php if ($popupMobile !== '' && $popupMobile !== $popupPoster): ?>
                                <?php // Same breakpoint the hero banners use for their portrait crop. ?>
                                <source media="(max-width: 767px)" srcset="<?= e(img_url($popupMobile)) ?>">
                            <?php endif; ?>
                            <?php // Size lives in .sik-modal--promo .sik-modal__poster. It used to
                                  // be an inline height:190px, which beat the class and was why the
                                  // promo card never actually shrank. ?>
                            <img src="<?= e(img_url($popupPoster)) ?>" alt="" loading="lazy" width="600" height="120"
                                 class="sik-modal__poster">
                        </picture>
                    <?php endif; ?>

                    <?php // Only the ink colours stay inline - they are per-popup admin values.
                          // Every font-size and padding here was a hardcoded literal that
                          // overrode .sik-modal--promo and kept the card at full-sheet size. ?>
                    <div class="sik-modal__body">
                        <?php if (!empty($popup['title'])): ?>
                            <h2 class="sik-modal__title" style="<?= e_attr($popupInk) ?>"><?= e($popup['title']) ?></h2>
                        <?php endif; ?>
                        <?php if (!empty($popup['subtitle'])): ?>
                            <p class="sik-modal__sub" style="<?= e_attr($popupSubInk) ?>"><?= e($popup['subtitle']) ?></p>
                        <?php endif; ?>
                        <?php if (!empty($popup['content'])): ?>
                            <div class="sik-prose sik-modal__note" style="<?= e_attr($popupInk) ?>"><?= sanitize_html($popup['content']) ?></div>
                        <?php endif; ?>

                        <?php if ($popup['popup_type'] === 'video' && ($popupVideo = popup_video_embed((string) ($popup['video_url'] ?? ''))) !== ''): ?>
                            <div style="margin-top:var(--sp-5);border-radius:var(--sik-radius-lg);overflow:hidden">
                                <?= $popupVideo ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($popup['popup_type'] === 'product' && !empty($popupProducts[(int) $popup['id']])): ?>
                            <div style="margin-top:var(--sp-5);text-align:left">
                                <?= product_card($popupProducts[(int) $popup['id']], 'horizontal', false) ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($popup['popup_type'] === 'newsletter'): ?>
                            <form class="sik-news__form" style="margin-top:var(--sp-5);border:1px solid var(--sik-border)"
                                  data-newsletter-form="popup">
                                <?= bot_form_html('newsletter') ?>
                                <label class="sik-sr" for="popupEmail<?= (int) $popup['id'] ?>">Email address</label>
                                <input id="popupEmail<?= (int) $popup['id'] ?>" class="sik-news__input" type="email"
                                       name="email" placeholder="Enter your email address" required>
                                <button type="submit" class="sik-btn sik-btn--primary"><span class="sik-btn__label">Subscribe</span></button>
                            </form>
                        <?php endif; ?>

                        <?php if (!empty($popup['coupon_code'])): ?>
                            <div style="margin-top:var(--sp-5)">
                                <div style="border:2px dashed var(--sik-primary);border-radius:10px;padding:var(--sp-4);background:var(--sik-primary-soft)">
                                    <div style="font-size:11px;letter-spacing:.1em;text-transform:uppercase;color:var(--sik-muted)">Use code</div>
                                    <div style="font-size:22px;font-weight:800;color:var(--sik-primary);letter-spacing:.06em"><?= e($popup['coupon_code']) ?></div>
                                </div>
                                <?php // currentColor keeps the outline legible whichever ink the popup was given. ?>
                                <button type="button" class="sik-btn sik-btn--outline sik-btn--sm"
                                        style="margin-top:var(--sp-3);<?= e_attr($popupInk !== '' ? $popupInk . 'border-color:currentColor;' : '') ?>"
                                        data-copy="<?= e($popup['coupon_code']) ?>"><?= icon('copy', 'w-4 h-4') ?> Copy code</button>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($popup['button_text'])): ?>
                            <a class="sik-btn sik-btn--primary sik-btn--lg" style="margin-top:var(--sp-5)"
                               href="<?= e(url((string) $popup['button_url'])) ?>"><?= e($popup['button_text']) ?></a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
<?php endforeach; ?>

<div class="sik-toasts" id="sikToasts" role="status" aria-live="polite"></div>

<?php // Inert skeleton markup the scripts clone (SIK.skeleton in app.js), and
      // only the ones this page's scripts can reach - the same way the
      // checkout script below is loaded only where there is a checkout. ?>
<?php skeleton_templates(skeleton_page_templates($currentScript)); ?>

<?php
/*
 * First-party analytics: is THIS page view counted?
 *
 * The library is loaded only when the owner has switched counting on, so a
 * store on the shipped default parses nothing and prints nothing - no config,
 * no script tag, no beacon. When it is on, analytics_js_config() answers null
 * for a visitor we must not count (a DNT or GPC signal, the signed-in admin
 * walking their own shop, a bot, an excluded office IP), and the result is the
 * same: this page ships no tracker at all.
 *
 * The token in that config is what the collector trusts instead of a session.
 * It is minted per render, so it must never be printed into a cached page -
 * see the note in api/analytics/collect.php.
 */
$sikAnalytics = null;
try {
    if (setting('analytics_mode', 'off') !== 'off') {
        require_once INCLUDES_PATH . '/analytics/collect.php';
        $sikAnalytics = analytics_js_config();
    }
} catch (Throwable $e) {
    // Measuring the storefront must never be able to take the storefront down.
    // A missing application key, a missing table, a settings read that failed:
    // the page finishes without a tracker and the reason goes to the log.
    $sikAnalytics = null;
    ErrorHandler::log('warning', 'analytics config skipped: ' . $e->getMessage());
}
?>

<script>
    window.SIK_CONFIG = <?= e_json([
        'baseUrl'        => SITE_URL,
        'apiUrl'         => API_URL,
        'csrfToken'      => csrf_token(),
        'currencySymbol' => (string) setting('currency_symbol', CURRENCY_SYMBOL),
        'grouping'       => (string) setting('number_grouping', 'indian'),
        'loggedIn'       => is_logged_in(),
        'maxCompare'     => compare_max(),
        // compare.js used to learn "is anything being compared?" from the
        // header badge alone. Settings > Widgets can hide that badge
        // (compare_show_header) while the card buttons stay on, which left the
        // floating compare bar - and with it the only remaining route to
        // /compare - unable to restore itself after a page load. The count is
        // server state, so it travels in the config rather than being inferred
        // from a control that the admin is allowed to remove.
        'compareCount'   => compare_usable() ? compare_count() : 0,
        'freeShipAt'     => setting_float('free_shipping_threshold', 999),
        'cartSubtotal'   => $popupCartSubtotal,
        // `an` is present only on a page view that is actually counted, so
        // "is this visitor tracked?" is one key's existence rather than a
        // flag the client could disagree with.
    ] + ($sikAnalytics === null ? [] : ['an' => $sikAnalytics])) ?>;
</script>
<script src="<?= e(asset('js/app.js')) ?>" defer></script>
<script src="<?= e(asset('js/notifications.js')) ?>" defer></script>
<script src="<?= e(asset('js/header.js')) ?>" defer></script>
<script src="<?= e(asset('js/search.js')) ?>" defer></script>
<script src="<?= e(asset('js/voice-search.js')) ?>" defer></script>
<script src="<?= e(asset('js/cart.js')) ?>" defer></script>
<script src="<?= e(asset('js/wishlist.js')) ?>" defer></script>
<script src="<?= e(asset('js/compare.js')) ?>" defer></script>
<script src="<?= e(asset('js/products.js')) ?>" defer></script>
<script src="<?= e(asset('js/account.js')) ?>" defer></script>
<?php if (in_array($currentScript, ['checkout.php'], true)): ?>
<script src="<?= e(asset('js/checkout.js')) ?>" defer></script>
<?php endif; ?>
<?php // Shipped only where there is something to measure. It is first-party,
      // so no consent banner gates it in the owner's anonymous mode; what
      // gates it is $sikAnalytics being null above. ?>
<?php if ($sikAnalytics !== null): ?>
<script src="<?= e(asset('js/analytics.js')) ?>" defer></script>
<?php endif; ?>

<?php
// A page can opt out of everything third-party by setting
// $GLOBALS['SIK_NO_THIRD_PARTY'] before the footer. reset-password.php does:
// it carries a live reset token in its URL, and a tag container reports
// document.location - token and all - to somebody else's server.
$sikNoThirdParty = !empty($GLOBALS['SIK_NO_THIRD_PARTY']);
?>

<?php if (!$sikNoThirdParty && ($customJs = setting('custom_js', ''))): ?>
<script><?= strip_tags((string) $customJs) ?></script>
<?php endif; ?>

<?php
/*
 * Analytics and tag containers - now gated on consent.
 *
 * This block used to load Google Tag Manager, Google Analytics and the Meta
 * Pixel for every visitor the moment an id was configured, with nothing
 * anywhere in the storefront to refuse them, while the Cookie Policy claimed
 * they could be refused. Both halves of that are fixed here: consent.php
 * decides, and it emits nothing third-party until the visitor has allowed the
 * marketing category (and never for the signed-in admin, never on a page that
 * set SIK_NO_THIRD_PARTY, and never against a DNT/GPC signal).
 *
 * consent_banner_html() prints the banner, its config and consent.js, and
 * prints nothing at all when the store has no tags configured and its own
 * analytics mode needs no opt-in - so a clean store pays nothing for this.
 */
echo consent_tag_html();
echo consent_banner_html();
?>

<?php
/**
 * Give queued mail a chance to go out on installs with no cron configured.
 *
 * This used to drain 5 rows inline on roughly one page view in six. That was
 * harmless while send_email() was a non-blocking mail() call, but every send
 * is now a real SMTP round trip — five of them would stall the visitor's page
 * for seconds. So: flush the response first, then drain a smaller batch, and
 * only when the operator has not set up a proper worker
 * (bin/send-queued-emails.php) and left email_queue_auto_drain on.
 */
if (setting_bool('email_queue_auto_drain', true) && mt_rand(1, 6) === 1) {
    // The visitor already has the complete page; the drain happens after.
    if (function_exists('fastcgi_finish_request')) {
        @fastcgi_finish_request();
    } elseif (!headers_sent()) {
        @ignore_user_abort(true);
    }

    try { process_notification_queue(2); } catch (Throwable $e) { /* never block a page render */ }
}
?>
</body>
</html>
