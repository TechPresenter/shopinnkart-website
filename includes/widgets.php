<?php
/**
 * ShopInnKart - Widget engine.
 *
 * One row in `homepage_sections` = one widget instance. This file turns those
 * rows into markup. Adding a widget type means adding one case to
 * render_widget() — the admin builder needs no code change.
 */

declare(strict_types=1);

require_once __DIR__ . '/menu-functions.php';

/** Every active widget in a zone, respecting schedule and visibility rules. */
function zone_widgets(string $zone = 'home'): array
{
    $rows = cache_remember('widgets.' . $zone, 300, static function () use ($zone) {
        return Database::fetchAll(
            "SELECT * FROM `homepage_sections`
             WHERE `zone` = :zone AND `status` = 'active'
             ORDER BY `sort_order`, `id`",
            ['zone' => $zone]
        );
    });

    return array_values(array_filter($rows, 'visibility_allows'));
}

/** Render every widget in a zone. */
function render_zone(string $zone = 'home', array $context = []): void
{
    foreach (zone_widgets($zone) as $widget) {
        echo render_widget($widget, $context);
    }
}

/**
 * Render one widget instance to HTML.
 * Returns a string so it can also be served by the AJAX lazy-load endpoint.
 */
function render_widget(array $widget, array $context = []): string
{
    // Deferred widgets render an empty shell; JS fetches the body on scroll.
    if ((int) $widget['lazy_load'] === 1 && empty($context['force'])) {
        // No padding on the shell: a widget that turns out to have nothing to
        // show (recently viewed, for a first visit) must not leave a band of
        // empty section rhythm behind it.
        return '<div data-lazy-widget="' . e_attr($widget['section_key']) . '" class="sik-lazy-widget"></div>';
    }

    ob_start();

    switch ($widget['widget_type']) {
        case 'hero':             widget_hero($widget); break;
        case 'ticker':           widget_ticker($widget); break;
        case 'trust':            widget_trust($widget); break;
        case 'combo_grid':       widget_combo_grid($widget); break;
        case 'offer_strip':      widget_offer_strip($widget); break;
        case 'category_grid':    widget_categories($widget); break;
        case 'product_grid':     widget_products($widget, 'grid'); break;
        case 'product_carousel': widget_products($widget, 'carousel'); break;
        case 'deal_of_day':      widget_deal($widget); break;
        case 'flash_sale':       widget_flash_sale($widget); break;
        case 'brand_slider':     widget_brands($widget); break;
        case 'promo_banner':     widget_promo($widget); break;
        case 'stats':            widget_stats($widget); break;
        case 'testimonials':     widget_testimonials($widget); break;
        case 'newsletter':       widget_newsletter($widget); break;
        case 'offer_slider':     widget_offer_slider($widget); break;
        case 'recently_viewed':  widget_recently_viewed($widget, $context); break;
        case 'recommendations':  widget_recommendations($widget, $context); break;
        case 'blog_grid':        widget_blog($widget); break;
        case 'reviews':          widget_reviews($widget); break;
        case 'faq':              widget_faq($widget); break;
        case 'html':             widget_html($widget); break;
        default:
            // Unknown type: fail quietly rather than breaking the page.
            ErrorHandler::log('warning', 'Unknown widget type: ' . $widget['widget_type']);
    }

    return (string) ob_get_clean();
}

// ===========================================================================
//  Shared chrome
// ===========================================================================

/** Section wrapper classes derived from the widget's styling fields. */
/**
 * @param string $fallbackStyle A widget's own default colouring. Emitted first
 *        so the admin's bg_color / text_color still override it. A caller that
 *        added a second style="" attribute instead silently lost it: the HTML
 *        parser keeps the first occurrence and drops the rest.
 */
function widget_section_attrs(array $widget, string $extraClass = '', string $fallbackStyle = ''): string
{
    $classes = ['sik-section'];
    if ($widget['padding'] === 'none') $classes[] = 'sik-section--none';
    if ($widget['padding'] === 'sm')   $classes[] = 'sik-section--sm';
    if ($widget['padding'] === 'lg')   $classes[] = 'sik-section--lg';
    if ($extraClass !== '')            $classes[] = $extraClass;

    $style = $fallbackStyle !== '' ? rtrim($fallbackStyle, ';') . ';' : '';
    // The builder's colours arrive as custom properties rather than as
    // background/color, and app.css applies them in light mode only. A colour
    // chosen against a white page — a pale tint, or dark text — would otherwise
    // make the section unreadable the moment a shopper switches to dark mode.
    if (!empty($widget['bg_color']))   $style .= '--w-bg:' . $widget['bg_color'] . ';';
    if (!empty($widget['text_color'])) $style .= '--w-fg:' . $widget['text_color'] . ';';

    return 'class="' . e_attr(implode(' ', $classes)) . '"'
        . ($style !== '' ? ' style="' . e_attr($style) . '"' : '')
        . ' id="' . e_attr('w-' . $widget['section_key']) . '"';
}

/** The centred two-tone heading used across the storefront. */
function widget_heading(array $widget, bool $withLink = true): void
{
    if (empty($widget['title']) && empty($widget['title_accent'])) {
        return;
    }

    $hasLink = $withLink && !empty($widget['link_text']) && !empty($widget['link_url']);
    ?>
    <div class="sik-heading <?= $hasLink ? 'sik-heading--row' : '' ?>" data-anim="fade-up">
        <div>
            <h2 class="sik-heading__title">
                <?= e($widget['title']) ?>
                <?php if (!empty($widget['title_accent'])): ?>
                    <span class="sik-heading__accent"><?= e($widget['title_accent']) ?></span>
                <?php endif; ?>
            </h2>
            <?php if (!empty($widget['subtitle'])): ?>
                <p class="sik-heading__sub"><?= e($widget['subtitle']) ?></p>
            <?php endif; ?>
        </div>
        <?php if ($hasLink): ?>
            <a class="sik-viewall" href="<?= e(url((string) $widget['link_url'])) ?>">
                <?= e($widget['link_text']) ?> <?= icon('arrow-right', 'w-3.5 h-3.5') ?>
            </a>
        <?php endif; ?>
    </div>
    <?php
}

/** Responsive column CSS variables for grids and rails. */
function widget_cols_style(array $widget, string $prefix = 'cols'): string
{
    return sprintf(
        '--%1$s-desktop:%2$d;--%1$s-tablet:%3$d;--%1$s-mobile:%4$d',
        $prefix,
        max(1, (int) $widget['cols_desktop']),
        max(1, (int) $widget['cols_tablet']),
        max(1, (int) $widget['cols_mobile'])
    );
}

/** Widget-specific extras stored as JSON. */
function widget_setting(array $widget, string $key, $default = null)
{
    $settings = json_decode_safe($widget['settings'] ?? null, []);
    return $settings[$key] ?? $default;
}

// ===========================================================================
//  ADD TO CART  (the one control; every surface renders through this)
// ===========================================================================

/**
 * The single Add to Cart control.
 *
 * Product cards, the product page, Quick View, the recommendation rails, the
 * cart page's own rails, search results, the compare table and the wishlist all
 * call this. Variants are arguments, never new markup - there is exactly one
 * place where an "add to cart" is described, so its states can never drift
 * apart between surfaces.
 *
 * Every state that matters is decided HERE, on the server, from live data:
 *
 *   out of stock   stock_state / in_stock says so. Rendered as a real disabled
 *                  button, so it cannot be re-enabled by editing CSS or the DOM
 *                  (and cart_add() would refuse it anyway).
 *   choose variant the product has more than one sellable variant, so adding
 *                  from a card would be a guess. Renders as a link to the
 *                  product page instead. See variant_choice_required().
 *   in cart        the product is already in this cart, so a second click is
 *                  unambiguous ("In Cart - 2") rather than looking like a no-op.
 *
 * The remaining states - hover, focus, active, loading, success - are the
 * button's own and live in .sik-atc / assets/js/cart.js.
 *
 * @param array $product Decorated product row (decorate_product()).
 * @param array $opts    mode:  cart | buy | wishlist
 *                       size:  sm | md | lg
 *                       tone:  primary | navy | outline
 *                       block, icon_only: bool
 *                       label: override the resting label
 *                       qty:   fixed quantity, or qty_from: a CSS selector
 *                       variant_from: CSS selector of the variant input. Its
 *                              presence means the surface has a real picker, so
 *                              no "choose options" redirect is needed.
 *                       variant_id: a variant the caller already knows (a "buy
 *                              it again" row off a past order, say). Also
 *                              settles the choice, so no redirect.
 *                       class: extra classes
 */
function add_to_cart_button(array $product, array $opts = []): string
{
    $opts += [
        'mode'         => 'cart',
        'size'         => 'md',
        'tone'         => 'primary',
        'block'        => true,
        'icon_only'    => false,
        'label'        => null,
        'qty'          => 1,
        'qty_from'     => null,
        'variant_from' => null,
        'variant_id'   => null,
        'class'        => '',
    ];

    $id   = (int) ($product['id'] ?? 0);
    $name = (string) ($product['name'] ?? 'this product');
    $url  = (string) ($product['url'] ?? product_url((string) ($product['slug'] ?? '')));

    // stock_state is the decorated field; in_stock is the fallback for rows that
    // only carry the boolean. Never trust a caller-supplied flag over either.
    $outOfStock = array_key_exists('stock_state', $product)
        ? ($product['stock_state'] === STOCK_OUT)
        : empty($product['in_stock']);

    // A choice already made - by a picker on this screen, or by the caller
    // naming the variant - is not a choice still to be made.
    $choiceSettled = $opts['variant_from'] !== null || (int) $opts['variant_id'] > 0;
    $needsChoice   = !$choiceSettled && variant_choice_required($product);
    $units         = $opts['mode'] === 'cart' ? cart_units_of($id) : 0;

    $classes = ['sik-atc', 'sik-atc--' . $opts['size'], 'sik-atc--' . $opts['tone']];
    if ($opts['block'])     $classes[] = 'sik-atc--block';
    if ($opts['icon_only']) $classes[] = 'sik-atc--icon';
    if ($opts['class'] !== '') $classes[] = (string) $opts['class'];

    // ---- out of stock: a genuinely disabled control -----------------------
    if ($outOfStock) {
        $classes[] = 'is-out';
        return '<button type="button" class="' . e_attr(implode(' ', $classes)) . '"'
            . ' disabled aria-disabled="true"'
            . ' aria-label="' . e_attr($name . ' is out of stock') . '">'
            . '<span class="sik-atc__glyph" aria-hidden="true">'
            . '<span class="sik-atc__ico">' . icon('alert', 'w-4 h-4') . '</span></span>'
            . '<span class="' . ($opts['icon_only'] ? 'sik-sr' : 'sik-atc__label') . '">Out of stock</span>'
            . '</button>';
    }

    // ---- a choice has to be made: go to the product page -------------------
    if ($needsChoice) {
        $classes[] = 'sik-atc--choose';
        // Clicking navigates, so this can never be an ambiguous second click -
        // but saying it is already in the cart still saves a trip.
        if ($units > 0) {
            $classes[] = 'has-incart';
        }
        return '<a class="' . e_attr(implode(' ', $classes)) . '" href="' . e($url) . '"'
            . ' aria-label="' . e_attr($units > 0
                ? $units . ' in your cart - choose options to add another ' . $name
                : 'Choose options for ' . $name) . '">'
            . '<span class="sik-atc__glyph" aria-hidden="true">'
            . '<span class="sik-atc__ico">' . icon('sliders', 'w-4 h-4') . '</span></span>'
            . '<span class="' . ($opts['icon_only'] ? 'sik-sr' : 'sik-atc__label') . '">Choose options</span>'
            . '</a>';
    }

    // ---- the live control --------------------------------------------------
    [$hook, $resting, $loading, $done, $settled] = match ($opts['mode']) {
        'buy'      => ['data-buy-now', 'Buy now', 'Starting…', 'Added', 'Buy now'],
        'wishlist' => ['data-wishlist-to-cart', 'Move to cart', 'Moving…', 'Moved', 'In cart'],
        default    => ['data-add-cart', 'Add to cart', 'Adding…', 'Added', 'In cart'],
    };

    if ($opts['label'] !== null) {
        $resting = (string) $opts['label'];
    }

    $label = $units > 0 ? $settled . ' - ' . $units : $resting;
    if ($units > 0) {
        $classes[] = 'is-incart';
    }

    $attrs = [
        'type'                => 'button',
        'class'               => implode(' ', $classes),
        'data-product-id'     => (string) $id,
        'data-product-name'   => $name,
        'data-product-url'    => $url,
        'data-label'          => $resting,
        'data-label-loading'  => $loading,
        'data-label-added'    => $done,
        'data-label-incart'   => $settled,
        'aria-label'          => ($units > 0 ? 'Add another ' . $name . ' to cart' : $resting . ': ' . $name),
    ];

    if ($opts['qty_from'] !== null) {
        $attrs['data-qty-from'] = (string) $opts['qty_from'];
    } else {
        $attrs['data-qty'] = (string) max(1, (int) $opts['qty']);
    }
    if ($opts['variant_from'] !== null) {
        $attrs['data-variant-from'] = (string) $opts['variant_from'];
    } elseif ((int) $opts['variant_id'] > 0) {
        $attrs['data-variant-id'] = (string) (int) $opts['variant_id'];
    }

    $html = '<button';
    foreach ($attrs as $key => $value) {
        $html .= ' ' . $key . '="' . e_attr((string) $value) . '"';
    }
    $html .= ' ' . $hook . '>';

    // Three stacked glyphs in one box: resting icon, success tick, spinner.
    // Swapping opacity instead of rewriting innerHTML keeps the button's width
    // stable, so a grid never reflows mid-click. The spinner is the shared
    // .sik-spinner, which is also what SIK.showLoader() looks for before
    // inserting one of its own - so the guard runs without a second spinner.
    $html .= '<span class="sik-atc__glyph" aria-hidden="true">'
        . '<span class="sik-atc__ico">' . icon($opts['mode'] === 'buy' ? 'zap' : 'cart', 'w-4 h-4') . '</span>'
        . '<span class="sik-atc__tick">' . icon('check', 'w-4 h-4') . '</span>'
        . '<span class="sik-atc__spin"><span class="sik-spinner"></span></span>'
        . '</span>'
        . '<span class="' . ($opts['icon_only'] ? 'sik-sr' : 'sik-atc__label') . '">' . e($label) . '</span>'
        . '</button>';

    return $html;
}

// ===========================================================================
//  SAVE & COMPARE  (the two controls; every surface renders through these)
// ===========================================================================

/**
 * The one wishlist / compare control.
 *
 * Product cards, Quick View, the product page and the wishlist page all call
 * these two, so a surface can never grow its own copy that forgets the admin's
 * show/hide switch or the guest rule. Whether the control appears at all is the
 * caller's question - wishlist_shows_on()/compare_shows_on() answer it, because
 * only the caller knows which surface it is.
 *
 * Three states, decided here on the server:
 *   usable        a real toggle button; wishlist.js / compare.js drive it.
 *   needs sign-in a link to the login page. A guest cannot save anything when
 *                 the guest mode is "prompt", so a button that would always
 *                 answer 401 would be a lie; a link is the honest control and
 *                 it works with JavaScript off.
 *   refused       nothing at all, and the API refuses too.
 *
 * @param array $opts style: 'icon' (the round tool) | 'ghost' (labelled button)
 *                    class: extra classes
 */
function saved_list_button(string $feature, int $productId, string $productName = '', array $opts = []): string
{
    $opts += ['style' => 'icon', 'class' => ''];

    $isWishlist = $feature === 'wishlist';
    $noun       = $isWishlist ? 'wishlist' : 'compare';
    $glyph      = $isWishlist ? 'heart' : 'compare';
    $label      = $isWishlist ? 'Wishlist' : 'Compare';
    $needsLogin = $isWishlist ? wishlist_needs_login() : compare_needs_login();
    $isUsable   = $isWishlist ? wishlist_usable() : compare_usable();

    if (!$isUsable && !$needsLogin) {
        return '';
    }

    $classes = $opts['style'] === 'ghost'
        ? ['sik-btn', 'sik-btn--ghost', 'sik-btn--sm']
        : ['sik-iconbtn'];
    if ($opts['class'] !== '') {
        $classes[] = (string) $opts['class'];
    }

    $body = icon($glyph, 'w-4 h-4')
        . ($opts['style'] === 'ghost' ? '<span class="sik-btn__label">' . e($label) . '</span>' : '');

    if ($needsLogin) {
        return '<a class="' . e_attr(implode(' ', $classes)) . '" href="' . e(url('login.php')) . '"'
            . ' aria-label="' . e_attr('Sign in to use your ' . $noun) . '">' . $body . '</a>';
    }

    $active = $isWishlist ? in_wishlist($productId) : in_compare($productId);
    if ($active) {
        $classes[] = 'is-active';
    }

    $onName = $productName !== '' ? ' ' . $productName : '';

    return '<button type="button" class="' . e_attr(implode(' ', $classes)) . '"'
        . ' data-' . $noun . ' data-product-id="' . $productId . '"'
        . ' aria-pressed="' . ($active ? 'true' : 'false') . '"'
        . ' aria-label="' . e_attr(($active ? 'Remove' . $onName . ' from ' : 'Add' . $onName . ' to ') . $noun) . '">'
        . $body . '</button>';
}

/**
 * The body of /wishlist or /compare when this visitor may not have one.
 *
 * Two different answers, because they are two different situations:
 *
 *   feature switched off   The route no longer exists on this store, so the
 *                          page answers 404. A soft 200 would keep it in the
 *                          search index and in uptime checks as a working page.
 *   sign-in required       The route exists and works - just not for a signed-
 *                          out visitor - so it answers 200 with the sign-in
 *                          call to action. A 404 here would be a lie, and it
 *                          would throw away the visit.
 *
 * Both are noindex already (the two pages set that in their seo_set()).
 */
function saved_list_unavailable(string $feature): void
{
    $refusal = feature_refusal($feature);
    if ($refusal === null) {
        return;
    }

    $isWishlist = $feature === 'wishlist';
    $isOff      = !($isWishlist ? wishlist_enabled() : compare_enabled());

    if ($isOff && !headers_sent()) {
        http_response_code(404);
    }
    ?>
    <div class="sik-container sik-section sik-section--sm">
        <div class="sik-empty">
            <?php // Direct child: .sik-empty > svg is what sizes the illustration. ?>
            <?= icon($isWishlist ? 'heart' : 'compare') ?>
            <h1 class="sik-empty__title">
                <?= $isOff
                    ? ($isWishlist ? 'The wishlist is turned off' : 'Product comparison is turned off')
                    : ($isWishlist ? 'Sign in to see your wishlist' : 'Sign in to compare products') ?>
            </h1>
            <p class="sik-empty__text"><?= e($refusal['message']) ?></p>
            <div style="display:flex;gap:var(--sp-3);justify-content:center;flex-wrap:wrap">
                <?php if (!$isOff): ?>
                    <a class="sik-btn sik-btn--primary" href="<?= e(url('login.php')) ?>">Sign In</a>
                <?php endif; ?>
                <a class="sik-btn <?= $isOff ? 'sik-btn--primary' : 'sik-btn--outline' ?>" href="<?= e(url('shop.php')) ?>">
                    <?= icon('grid', 'w-4 h-4') ?> Continue shopping
                </a>
            </div>
        </div>
    </div>
    <?php
}

/** @see saved_list_button() */
function wishlist_button(int $productId, string $productName = '', array $opts = []): string
{
    return saved_list_button('wishlist', $productId, $productName, $opts);
}

/** @see saved_list_button() */
function compare_button(int $productId, string $productName = '', array $opts = []): string
{
    return saved_list_button('compare', $productId, $productName, $opts);
}

// ===========================================================================
//  PRODUCT CARD  (used by every product widget and listing page)
// ===========================================================================

/**
 * Render one product card.
 *
 * @param string $style standard | minimal | premium | compact | horizontal
 */
function product_card(array $product, string $style = 'standard', bool $showQuick = true): string
{
    // Admin > Settings > Widgets decides whether the card carries these two at
    // all. Both are rendered by the shared control, so the card has no copy of
    // the guest rule to fall out of step with.
    $wishlistTool = wishlist_shows_on('card')
        ? wishlist_button((int) $product['id'], (string) $product['name']) : '';
    $compareTool = compare_shows_on('card')
        ? compare_button((int) $product['id'], (string) $product['name']) : '';
    $outOfStock = ($product['stock_state'] ?? '') === STOCK_OUT;

    // One badge, and never the discount or the low-stock warning: the price row
    // already reads "80% off" and the stock line already says "Only 3 left", so
    // a sticker repeating either is the loudest thing on the card for no new
    // information. What remains is what a shopper cannot read anywhere else.
    $badge = null;
    foreach ((array) ($product['badges'] ?? []) as $candidate) {
        if (!in_array($candidate['kind'] ?? '', ['discount', 'low'], true)) {
            $badge = $candidate;
            break;
        }
    }

    $classes = ['sik-card'];
    if ($style === 'horizontal') $classes[] = 'sik-card--row';
    if ($style === 'compact')    $classes[] = 'sik-card--compact';
    if ($style === 'minimal')    $classes[] = 'sik-card--minimal';
    if ($style === 'premium')    $classes[] = 'sik-card--premium';
    if ($outOfStock)             $classes[] = 'is-out';

    ob_start();
    ?>
    <article class="<?= e_attr(implode(' ', $classes)) ?>" data-product-card="<?= (int) $product['id'] ?>">
        <?php // The name link below is the card's one tab stop to the product; the
              // photo repeats it for a pointer, so it stays out of the tab order. ?>
        <a class="sik-card__media" href="<?= e($product['url']) ?>" tabindex="-1" aria-hidden="true">
            <img class="sik-card__img sik-card__img--main"
                 src="<?= e($product['image_url']) ?>" alt="<?= e($product['name']) ?>"
                 width="600" height="600" loading="lazy" decoding="async">
            <?php if (!empty($product['hover_url']) && $product['hover_url'] !== $product['image_url']): ?>
                <img class="sik-card__img sik-card__img--hover" src="<?= e($product['hover_url']) ?>"
                     alt="" width="600" height="600" loading="lazy" decoding="async">
            <?php endif; ?>
        </a>

        <?php if ($badge !== null): ?>
            <div class="sik-card__badges">
                <span class="sik-badge sik-badge--<?= e_attr($badge['tone']) ?>"><?= e($badge['label']) ?></span>
            </div>
        <?php endif; ?>

        <?php if ($wishlistTool !== '' || $compareTool !== '' || $showQuick): ?>
        <div class="sik-card__tools">
            <?= $wishlistTool ?>
            <?= $compareTool ?>
            <?php if ($showQuick && $style === 'horizontal'): ?>
                <button type="button" class="sik-iconbtn" data-quickview data-product-id="<?= (int) $product['id'] ?>"
                        aria-label="Quick view <?= e($product['name']) ?>">
                    <?= icon('eye', 'w-4 h-4') ?>
                </button>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="sik-card__body">
            <?php if (!empty($product['brand_name'])): ?>
                <span class="sik-card__brand"><?= e($product['brand_name']) ?></span>
            <?php endif; ?>

            <h3 class="sik-card__name">
                <a href="<?= e($product['url']) ?>"><?= e($product['name']) ?></a>
            </h3>

            <?php if ((int) $product['rating_count'] > 0): ?>
                <div class="sik-card__rating">
                    <?= rating_stars((float) $product['rating_avg']) ?>
                    <span><?= e(number_format((float) $product['rating_avg'], 1)) ?> (<?= (int) $product['rating_count'] ?>)</span>
                </div>
            <?php endif; ?>

            <?php if ($style === 'horizontal' && !empty($product['short_description'])): ?>
                <p class="sik-caption"><?= e(str_limit($product['short_description'], 130)) ?></p>
            <?php endif; ?>

            <div class="sik-card__price">
                <span class="sik-price"><?= e($product['price_display']) ?></span>
                <?php if (!empty($product['on_sale'])): ?>
                    <span class="sik-price--mrp"><?= e($product['mrp_display']) ?></span>
                    <span class="sik-price--off"><?= (int) $product['discount'] ?>% off</span>
                <?php endif; ?>
            </div>

            <?php if (($product['stock_state'] ?? '') === STOCK_LOW): ?>
                <span class="sik-card__stock"><?= e($product['stock_label']) ?></span>
            <?php endif; ?>

            <div class="sik-card__foot">
                <?= add_to_cart_button($product, [
                    'size'  => $style === 'compact' ? 'sm' : 'md',
                    'tone'  => 'outline',
                    'block' => true,
                ]) ?>
            </div>
        </div>

        <?php if ($showQuick && $style !== 'horizontal'): ?>
            <div class="sik-card__quick">
                <button type="button" class="sik-btn sik-btn--sm sik-btn--block"
                        data-quickview data-product-id="<?= (int) $product['id'] ?>">
                    <?= icon('eye', 'w-4 h-4') ?> Quick view
                </button>
            </div>
        <?php endif; ?>
    </article>
    <?php
    return (string) ob_get_clean();
}

/** Render a list of products as a grid. */
function product_grid(array $products, array $widget = [], string $cardStyle = 'standard'): string
{
    if ($products === []) {
        return '';
    }
    $style = $widget !== [] ? widget_cols_style($widget) : '--cols-desktop:4;--cols-tablet:3;--cols-mobile:2';

    $html = '<div class="sik-grid" style="' . e_attr($style) . '" data-product-grid>';
    foreach ($products as $product) {
        $html .= product_card($product, $cardStyle);
    }
    return $html . '</div>';
}

/** Render a list of products as a scroll-snap carousel. */
function product_rail(array $products, array $widget, string $cardStyle = 'standard'): string
{
    if ($products === []) {
        return '';
    }
    $style = widget_cols_style($widget, 'rail');
    $autoplay = (int) $widget['autoplay'] === 1 ? (int) $widget['autoplay_speed'] : 0;

    ob_start();
    ?>
    <div class="sik-rail" data-rail data-autoplay="<?= $autoplay ?>">
        <?php if ((int) $widget['show_arrows'] === 1): ?>
            <button type="button" class="sik-rail__nav sik-rail__nav--prev" data-rail-prev aria-label="Previous">
                <?= icon('chevron-left', 'w-5 h-5') ?>
            </button>
        <?php endif; ?>

        <div class="sik-rail__track" style="<?= e_attr($style) ?>" data-rail-track>
            <?php foreach ($products as $product): ?>
                <div class="sik-rail__item"><?= product_card($product, $cardStyle) ?></div>
            <?php endforeach; ?>
        </div>

        <?php if ((int) $widget['show_arrows'] === 1): ?>
            <button type="button" class="sik-rail__nav sik-rail__nav--next" data-rail-next aria-label="Next">
                <?= icon('chevron-right', 'w-5 h-5') ?>
            </button>
        <?php endif; ?>
        <?php if ((int) $widget['show_dots'] === 1): ?>
            <div class="sik-rail__dots" data-rail-dots></div>
        <?php endif; ?>
    </div>
    <?php
    return (string) ob_get_clean();
}

// ===========================================================================
//  WIDGET RENDERERS
// ===========================================================================

/**
 * Is this banner image the artwork that shipped with the store, rather than
 * one an admin uploaded?
 *
 * The shipped hero and promo art still draws the old electronics catalogue —
 * the files are literally labelled "Laptop, headphones and smartwatch" and
 * "Device cluster" — so it is treated as no image at all, and the widget shows
 * the store's own product photography instead. Anything uploaded through
 * Marketing > Banners lands under uploads/ and is always used as it is.
 */
function banner_image_is_stock(?string $path): bool
{
    $path = ltrim(trim((string) $path), '/');
    return $path === '' || str_starts_with($path, 'assets/images/');
}

/**
 * Product photos for a hero slide that has no uploaded image of its own.
 *
 * Chosen from what the slide's button points at — a slide linking to a
 * category shows that category — and otherwise from the best sellers, rotated
 * by slide so consecutive slides do not open on the same photograph.
 */
function hero_slide_products(array $slide, int $index, int $count = 3): array
{
    $key = 'hero.tiles.' . (int) ($slide['id'] ?? 0) . '.' . $index . '.' . $count;

    return cache_remember($key, 600, static function () use ($slide, $index, $count): array {
        $query = [];
        parse_str((string) (parse_url((string) ($slide['button_url'] ?? ''), PHP_URL_QUERY) ?? ''), $query);

        $pool = [];
        if (!empty($query['category'])) {
            $pool = query_products([
                'category' => (string) $query['category'], 'per_page' => 6, 'sort' => 'popularity',
            ])['items'];
        }

        if (count($pool) < $count) {
            $pool = get_products_for_source('best', 9);
            if (count($pool) < $count) {
                $pool = get_products_for_source('featured', 9);
            }
            if ($pool !== []) {
                $offset = ($index * $count) % count($pool);
                $pool = array_merge(array_slice($pool, $offset), array_slice($pool, 0, $offset));
            }
        }

        $pool = array_values(array_filter($pool, static fn ($p) => !empty($p['image_url'])));
        return array_slice($pool, 0, $count);
    });
}

/**
 * The biggest genuine discount in the catalogue right now.
 *
 * The hero medallion says "up to N% off", so N has to be a number a shopper
 * can actually find by clicking through — it is measured from the catalogue,
 * never typed into a banner. Returns 0 when nothing is on sale, and the
 * medallion then does not render at all.
 */
function catalogue_max_discount(): int
{
    return (int) cache_remember('catalogue.max_discount', 600, static function (): int {
        $top = Database::fetchColumn(
            "SELECT MAX(ROUND((`price` - `sale_price`) / `price` * 100))
               FROM `products`
              WHERE `status` = 'active'
                AND `sale_price` IS NOT NULL
                AND `sale_price` > 0
                AND `sale_price` < `price`"
        );

        return (int) $top;
    });
}

function widget_hero(array $widget): void
{
    $slides = cache_remember('banners.hero', 300, static function () {
        return Database::fetchAll(
            "SELECT * FROM `banners`
             WHERE `position` = 'hero' AND `status` = 'active'
               AND (`start_date` IS NULL OR `start_date` <= NOW())
               AND (`end_date` IS NULL OR `end_date` >= NOW())
             ORDER BY `sort_order`, `id`"
        );
    });

    if ($slides === []) {
        return;
    }

    $autoplay = (int) $widget['autoplay'] === 1 ? (int) $widget['autoplay_speed'] : 0;
    $total    = count($slides);

    // Three promises, from Admin > Content > Trust features (hero placement).
    // Admin-owned rows rather than copy in this template, because every one of
    // them is a claim the store has to keep.
    $assurances = array_slice(trust_features('hero'), 0, 3);

    // Measured, not typed. See catalogue_max_discount().
    $topDiscount = catalogue_max_discount();
    ?>
    <section class="sik-hero sik-onwine" data-hero data-autoplay="<?= $autoplay ?>"
             aria-roledescription="carousel" aria-label="Featured collections">
        <div class="sik-container">
            <div class="sik-hero__frame">
                <?php foreach ($slides as $index => $slide): ?>
                    <?php
                    // Every slide is in the DOM at once, so only the first may be
                    // the page's h1.
                    $headingTag = $index === 0 ? 'h1' : 'h2';
                    $ownImage   = !banner_image_is_stock((string) $slide['desktop_image']);
                    $tiles      = $ownImage ? [] : hero_slide_products($slide, $index);
                    ?>
                    <div class="sik-hero__slide<?= $index === 0 ? ' is-active' : '' ?>" data-hero-slide
                         role="group" aria-roledescription="slide" aria-label="<?= $index + 1 ?> of <?= $total ?>">
                        <div class="sik-hero__grid">
                            <div class="sik-hero__copy">
                                <?php if (!empty($slide['badge'])): ?>
                                    <?php // The banner's badge line, set in a serif italic:
                                          // the festive flourish above the headline. ?>
                                    <span class="sik-flourish sik-hero__flourish"><?= e($slide['badge']) ?></span>
                                <?php endif; ?>

                                <<?= $headingTag ?> class="sik-hero__title">
                                    <?= e($slide['title']) ?>
                                    <?php if (!empty($slide['title_accent'])): ?>
                                        <span><?= e($slide['title_accent']) ?></span>
                                    <?php endif; ?>
                                </<?= $headingTag ?>>

                                <?php if (!empty($slide['description'])): ?>
                                    <p class="sik-hero__text"><?= e($slide['description']) ?></p>
                                <?php endif; ?>

                                <?php if (!empty($slide['button_text']) || !empty($slide['button2_text'])): ?>
                                    <div class="sik-hero__cta">
                                        <?php if (!empty($slide['button_text'])): ?>
                                            <a class="sik-btn sik-btn--lg sik-btn--pill sik-btn--onwine" href="<?= e(url((string) $slide['button_url'])) ?>">
                                                <?= e($slide['button_text']) ?> <?= icon('arrow-right', 'w-4 h-4') ?>
                                            </a>
                                        <?php endif; ?>
                                        <?php if (!empty($slide['button2_text'])): ?>
                                            <a class="sik-btn sik-btn--lg sik-btn--pill sik-btn--outline-white" href="<?= e(url((string) $slide['button2_url'])) ?>">
                                                <?= e($slide['button2_text']) ?>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>

                                <?php if ($assurances !== []): ?>
                                    <ul class="sik-hero__assure">
                                        <?php foreach ($assurances as $assurance): ?>
                                            <li class="sik-hero__assure-item">
                                                <?= icon($assurance['icon'] ?: 'shield', 'w-4 h-4') ?>
                                                <span>
                                                    <b><?= e($assurance['title']) ?></b>
                                                    <?php if (!empty($assurance['subtitle'])): ?>
                                                        <i><?= e($assurance['subtitle']) ?></i>
                                                    <?php endif; ?>
                                                </span>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </div>

                            <div class="sik-hero__media">
                                <?php if ($topDiscount > 0): ?>
                                    <span class="sik-hero__medal" aria-hidden="true">
                                        <small>Up to</small>
                                        <b><?= $topDiscount ?>%</b>
                                        <small>off</small>
                                    </span>
                                <?php endif; ?>

                                <?php if ($ownImage): ?>
                                    <picture>
                                        <?php if (!empty($slide['mobile_image']) && !banner_image_is_stock((string) $slide['mobile_image'])): ?>
                                            <source media="(max-width: 767px)" srcset="<?= e(img_url($slide['mobile_image'])) ?>">
                                        <?php endif; ?>
                                        <img class="sik-hero__img" src="<?= e(img_url($slide['desktop_image'])) ?>"
                                             alt="<?= e($slide['title']) ?>" width="860" height="680"
                                             <?= $index === 0 ? 'fetchpriority="high"' : 'loading="lazy"' ?> decoding="async">
                                    </picture>
                                <?php elseif ($tiles !== []): ?>
                                    <?php // No uploaded artwork: the store's own product photography
                                          // carries the slide instead of shipped placeholder art. ?>
                                    <div class="sik-hero__mosaic sik-hero__mosaic--<?= count($tiles) ?>">
                                        <?php foreach ($tiles as $tile): ?>
                                            <a class="sik-hero__tile" href="<?= e($tile['url']) ?>">
                                                <img src="<?= e($tile['image_url']) ?>" alt="<?= e($tile['name']) ?>"
                                                     width="600" height="600"
                                                     <?= $index === 0 ? 'fetchpriority="high"' : 'loading="lazy"' ?> decoding="async">
                                                <span class="sik-hero__tag">
                                                    <span class="sik-hero__tag-name"><?= e($tile['name']) ?></span>
                                                    <span class="sik-hero__tag-price"><?= e($tile['price_display']) ?></span>
                                                </span>
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>

                <?php if ($total > 1): ?>
                    <div class="sik-hero__controls">
                        <div class="sik-hero__dots">
                            <?php for ($dot = 0; $dot < $total; $dot++): ?>
                                <?php // header.js drives these by position and writes aria-current;
                                      // the markup must keep that contract. ?>
                                <button type="button" class="sik-hero__dot<?= $dot === 0 ? ' is-active' : '' ?>"
                                        data-hero-dot aria-label="Show slide <?= $dot + 1 ?>"
                                        aria-current="<?= $dot === 0 ? 'true' : 'false' ?>"></button>
                            <?php endfor; ?>
                        </div>
                    </div>
                    <button type="button" class="sik-hero__arrow sik-hero__arrow--prev" data-hero-prev aria-label="Previous slide">
                        <?= icon('chevron-left', 'w-4 h-4') ?>
                    </button>
                    <button type="button" class="sik-hero__arrow sik-hero__arrow--next" data-hero-next aria-label="Next slide">
                        <?= icon('chevron-right', 'w-4 h-4') ?>
                    </button>
                <?php endif; ?>
            </div>
        </div>
    </section>
    <?php
}

function widget_ticker(array $widget): void
{
    // The announcement bar in the header already carries these messages;
    // this widget places a second ticker further down the page.
    $items = active_announcements();
    if ($items === []) {
        return;
    }
    ?>
    <section <?= widget_section_attrs($widget, 'sik-section--none', 'background:var(--sik-navy);color:#fff') ?>>
        <div class="sik-ticker" style="padding-block:var(--sp-3)">
            <div class="sik-ticker__track">
                <?php for ($pass = 0; $pass < 2; $pass++): ?>
                    <?php foreach ($items as $item): ?>
                        <span class="sik-announce__item" style="font-size:13px" <?= $pass === 1 ? 'aria-hidden="true"' : '' ?>>
                            <?= icon($item['icon'] ?: 'zap', 'w-4 h-4') ?>
                            <strong><?= e($item['text']) ?></strong>
                            <?php if (!empty($item['subtext'])): ?><span style="opacity:.75"><?= e($item['subtext']) ?></span><?php endif; ?>
                        </span>
                    <?php endforeach; ?>
                <?php endfor; ?>
            </div>
        </div>
    </section>
    <?php
}

/**
 * The offer strip: three panels of running promotions.
 *
 * Every panel is independent and every one of them can be absent - a store
 * with no scratch coupon, no free-delivery threshold and no running offer
 * renders nothing at all rather than a row of empty boxes. That matters
 * because this band sits high on the page: a placeholder here is the first
 * thing a shopper reads.
 *
 *   scratch   a coupon an operator marked with the `scratch` placement
 *   delivery  the real free-delivery threshold, measured against THIS
 *             visitor's cart rather than a number typed into a banner
 *   offer     the running offer's code, the same one the header strip carries
 */
function widget_offer_strip(array $widget): void
{
    $scratch = null;
    foreach (public_offers('scratch', null, 4) as $candidate) {
        $scratch = $candidate;
        break;
    }

    $offer = header_promo();
    // The same coupon in both panels would be one offer wearing two hats.
    if ($scratch !== null && $offer !== null && (string) $scratch['code'] === (string) $offer['code']) {
        $offer = null;
    }

    $freeFrom = setting_bool('free_shipping_enabled', true)
        ? setting_float('free_shipping_threshold', 0)
        : 0.0;

    if ($scratch === null && $offer === null && $freeFrom <= 0) {
        return;
    }

    // What the visitor has in the cart right now, so the meter is about them.
    // cart_totals() is already computed once per request behind its own cache.
    $cartValue = 0.0;
    if ($freeFrom > 0 && !cart_is_empty()) {
        $totals    = cart_totals();
        $cartValue = (float) ($totals['subtotal'] ?? 0);
    }
    $remaining = max(0.0, $freeFrom - $cartValue);
    $progress  = $freeFrom > 0 ? min(100, (int) round($cartValue / $freeFrom * 100)) : 0;
    ?>
    <section <?= widget_section_attrs($widget, 'sik-section--tight') ?>>
        <div class="sik-container">
            <div class="sik-strip">

                <?php if ($scratch !== null): ?>
                    <?php
                    $scratchId = 'sikScratch' . (int) $scratch['id'];
                    ?>
                    <div class="sik-strip__card sik-strip__card--scratch sik-onwine">
                        <div class="sik-strip__body">
                            <span class="sik-gchip">Scratch &amp; save</span>
                            <p class="sik-strip__title"><?= e((string) $scratch['headline']) ?></p>

                            <?php /* The foil is drawn by app.js over this panel. The button
                                     underneath it is what makes the card usable with a
                                     keyboard, a screen reader or no JavaScript at all -
                                     a scratch you can only perform with a pointer is a
                                     coupon some shoppers simply cannot have. */ ?>
                            <div class="sik-scratch" data-scratch id="<?= e_attr($scratchId) ?>">
                                <div class="sik-scratch__prize">
                                    <span class="sik-scratch__label">Your code</span>
                                    <button type="button" class="sik-scratch__code" data-copy="<?= e_attr((string) $scratch['code']) ?>"
                                            title="Copy <?= e_attr((string) $scratch['code']) ?>">
                                        <?= e((string) $scratch['code']) ?>
                                    </button>
                                </div>
                                <button type="button" class="sik-scratch__reveal" data-scratch-reveal
                                        aria-controls="<?= e_attr($scratchId) ?>">
                                    Reveal my code
                                </button>
                            </div>

                            <?php if (($scratchTerms = (string) $scratch['terms']) !== ''): ?>
                                <p class="sik-strip__fine"><?= e($scratchTerms) ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($freeFrom > 0): ?>
                    <div class="sik-strip__card sik-strip__card--ship">
                        <div class="sik-strip__body">
                            <div class="sik-strip__row">
                                <span class="sik-strip__icon"><?= icon('truck', 'w-5 h-5') ?></span>
                                <div>
                                    <p class="sik-strip__eyebrow">Free delivery over</p>
                                    <p class="sik-strip__amount"><?= e(money($freeFrom)) ?></p>
                                </div>
                            </div>

                            <?php /* A real meter, not decoration: it reads the visitor's own
                                     subtotal, so an empty cart shows an empty bar rather than
                                     the two-thirds-full one every mockup has. */ ?>
                            <div class="sik-strip__meter" role="progressbar" aria-valuemin="0" aria-valuemax="100"
                                 aria-valuenow="<?= $progress ?>"
                                 aria-label="Progress towards free delivery">
                                <span class="sik-strip__meter-fill" style="width:<?= $progress ?>%"></span>
                            </div>

                            <p class="sik-strip__fine">
                                <?php if ($cartValue <= 0): ?>
                                    Add <?= e(money($freeFrom)) ?> of anything and delivery is on us.
                                <?php elseif ($remaining > 0): ?>
                                    <?= e(money($remaining)) ?> to go for free delivery.
                                <?php else: ?>
                                    <span class="sik-strip__won"><?= icon('check', 'w-4 h-4') ?> Free delivery unlocked.</span>
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($offer !== null): ?>
                    <div class="sik-strip__card sik-strip__card--offer sik-onwine">
                        <div class="sik-strip__body">
                            <span class="sik-flourish sik-strip__flourish">Offer running</span>
                            <p class="sik-strip__title"><?= e((string) $offer['headline']) ?></p>
                            <button type="button" class="sik-strip__code" data-copy="<?= e_attr((string) $offer['code']) ?>">
                                <span>Use code</span>
                                <b><?= e((string) $offer['code']) ?></b>
                                <?= icon('copy', 'w-4 h-4') ?>
                            </button>
                            <a class="sik-btn sik-btn--sm sik-btn--pill sik-btn--onwine" href="<?= e(url('shop.php')) ?>">
                                Shop now <?= icon('arrow-right', 'w-4 h-4') ?>
                            </a>
                        </div>
                    </div>
                <?php endif; ?>

            </div>
        </div>
    </section>
    <?php
}

/**
 * Render one combo card.
 *
 * Every number here is read off combo_decorate(); the card does no arithmetic
 * of its own, so the homepage, the combo page and the admin preview can never
 * quote three different prices for the same set.
 *
 * @param string $style standard | minimal | premium | compact | horizontal
 */
function combo_card(array $combo, string $style = 'standard'): string
{
    $items = (array) ($combo['items'] ?? []);
    $badge = $combo['badge'] ?? null;

    // Four thumbnails plus the "+N" chip is what fits one row inside a
    // one-column phone card. Beyond that the row either wraps into the price or
    // pushes the card past 320px, which is the failure .sik-grid's own comment
    // records for a bare 1fr track.
    $shown  = array_slice($items, 0, 4);
    $hidden = max(0, (int) $combo['item_count'] - count($shown));

    // combo_list() only ever hands over combos that save something, but this is
    // also the combo page's card and the admin's preview. A set priced at or
    // above what its components cost today must not grow a struck-through
    // number, which would advertise a discount it does not actually give.
    $saves = (float) $combo['pricing']['saving'] > 0;

    $classes = ['sik-combo'];
    if ($style === 'horizontal') $classes[] = 'sik-combo--row';
    if ($style === 'compact')    $classes[] = 'sik-combo--compact';
    if ($style === 'minimal')    $classes[] = 'sik-combo--minimal';
    if ($style === 'premium')    $classes[] = 'sik-combo--premium';

    ob_start();
    ?>
    <article class="<?= e_attr(implode(' ', $classes)) ?>" data-combo-card="<?= (int) $combo['id'] ?>">
        <?php // The name link below is the card's one tab stop to the combo; the
              // photo repeats it for a pointer, so it stays out of the tab order. ?>
        <a class="sik-combo__media" href="<?= e($combo['url']) ?>" tabindex="-1" aria-hidden="true">
            <img class="sik-combo__img" src="<?= e($combo['image_url']) ?>" alt="<?= e($combo['name']) ?>"
                 width="600" height="600" loading="lazy" decoding="async">
        </a>

        <?php if ($badge !== null): ?>
            <?php // The badge lies on the photo link - .sik-combo__badges keeps
                  // pointer-events off it so it can never eat the click. ?>
            <div class="sik-combo__badges">
                <span class="sik-badge sik-badge--<?= e_attr($badge['tone']) ?>"><?= e($badge['label']) ?></span>
            </div>
        <?php endif; ?>

        <div class="sik-combo__body">
            <h3 class="sik-combo__name">
                <a href="<?= e($combo['url']) ?>"><?= e($combo['name']) ?></a>
            </h3>

            <?php if (!empty($combo['subtitle'])): ?>
                <?php // Free admin text landing in a card whose grid row is as tall as
                      // its tallest member: cut it here rather than let one long
                      // subtitle set the height of every card beside it. ?>
                <p class="sik-combo__sub"><?= e(str_limit((string) $combo['subtitle'], 70)) ?></p>
            <?php endif; ?>

            <?php if ($shown !== []): ?>
                <?php /* Nothing beside these thumbnails names the products, so each alt
                         carries its own product name. alt="" is only right where the name
                         is already written next to the picture; here it would hide what
                         is in the set from anyone not looking at it.
                         They are images, not links: four more tab stops per card would
                         bury the one name link that leads to the combo. */ ?>
                <ul class="sik-combo__items">
                    <?php foreach ($shown as $item): ?>
                        <li class="sik-combo__thumb">
                            <img src="<?= e($item['image_url']) ?>" alt="<?= e($item['name']) ?>"
                                 width="96" height="96" loading="lazy" decoding="async">
                            <?php if ((int) $item['quantity'] > 1): ?>
                                <span class="sik-combo__qty" aria-hidden="true">&times;<?= (int) $item['quantity'] ?></span>
                                <span class="sik-sr">, quantity <?= (int) $item['quantity'] ?></span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                    <?php if ($hidden > 0): ?>
                        <?php // The remainder is counted off item_count, never written as
                              // a number: a hard-coded "+2" goes wrong the first time an
                              // admin adds a fifth product to the set. ?>
                        <li class="sik-combo__more">
                            <span aria-hidden="true">+<?= $hidden ?></span>
                            <span class="sik-sr">and <?= $hidden ?> more item<?= $hidden === 1 ? '' : 's' ?></span>
                        </li>
                    <?php endif; ?>
                </ul>
            <?php endif; ?>

            <div class="sik-combo__price">
                <span class="sik-price sik-num"><?= e($combo['price_display']) ?></span>
                <?php if ($saves): ?>
                    <?php /* The struck number is `regular` - what these components cost
                             TODAY bought separately - and never `mrp`. Most of this
                             catalogue is already discounted, so an MRP comparison would
                             advertise a saving the shopper could get anyway by adding the
                             items one at a time. */ ?>
                    <span class="sik-price--mrp sik-num"><?= e($combo['regular_display']) ?></span>
                    <span class="sik-price--off"><?= (int) $combo['percent'] ?>% off</span>
                <?php endif; ?>
            </div>

            <?php if ($saves): ?>
                <span class="sik-gchip sik-combo__save">
                    <?= icon('tag', 'w-4 h-4') ?> You save <?= e($combo['saving_display']) ?>
                </span>
            <?php endif; ?>

            <?php if (!empty($combo['low_stock'])): ?>
                <?php // combo_decorate() only raises this at five sets or fewer, so the
                      // line is scarcity a shopper can act on rather than inventory noise. ?>
                <span class="sik-combo__stock">
                    Only <?= (int) $combo['available'] ?> set<?= (int) $combo['available'] === 1 ? '' : 's' ?> left
                </span>
            <?php endif; ?>

            <div class="sik-combo__foot">
                <?php // A link to the combo, not an Add to cart: a set enters the basket
                      // as its component lines under one combo_group_id(), and choosing
                      // those lines is the combo page's job. A card-level add would guess. ?>
                <a class="sik-btn sik-btn--primary sik-btn--block sik-combo__cta" href="<?= e($combo['url']) ?>">
                    View combo <?= icon('arrow-right', 'w-4 h-4') ?>
                </a>
            </div>
        </div>
    </article>
    <?php
    return (string) ob_get_clean();
}

/**
 * The homepage band of combo offers.
 *
 * combo_list() decides sellability in PHP, so everything reaching a card here
 * already has at least two live components, stock for a set and a real saving.
 */
function widget_combo_grid(array $widget): void
{
    // The builder stores at most one of featured/flash/best. Anything else -
    // including the NULL settings blob the migration's own row ships with -
    // means "no filter", so an unconfigured section still lists combos rather
    // than quietly matching nothing.
    $filters = ['limit' => max(1, (int) $widget['item_limit'])];
    $filter  = widget_setting($widget, 'filter', '');
    if (in_array($filter, ['featured', 'flash', 'best'], true)) {
        $filters[$filter] = true;
    }

    $combos = combo_list($filters);
    if ($combos === []) {
        return;
    }

    $cardStyle = (string) $widget['card_style'];
    ?>
    <section <?= widget_section_attrs($widget) ?>>
        <div class="sik-container">
            <?php widget_heading($widget); ?>
            <div data-anim="fade-up">
                <?php /* Deliberately not data-product-grid. products.js binds the FIRST
                         element on the page carrying that attribute and toggles .sik-grid
                         off it for the list/grid switch, so a combo grid wearing it would
                         hand the shop page's own control the wrong element. */ ?>
                <div class="sik-grid" style="<?= e_attr(widget_cols_style($widget)) ?>">
                    <?php foreach ($combos as $combo): ?>
                        <?= combo_card($combo, $cardStyle) ?>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </section>
    <?php
}

function widget_trust(array $widget): void
{
    $features = trust_features('strip');
    if ($features === []) {
        return;
    }
    $limit = max(1, (int) $widget['item_limit']);
    $features = array_slice($features, 0, $limit);
    ?>
    <section class="sik-trust">
        <div class="sik-container">
            <div class="sik-trust__grid <?= count($features) === 6 ? 'sik-trust__grid--6' : '' ?>">
                <?php foreach ($features as $feature): ?>
                    <?php $tag = !empty($feature['link']) ? 'a' : 'div'; ?>
                    <<?= $tag ?> class="sik-trust__item"<?= !empty($feature['link']) ? ' href="' . e(url($feature['link'])) . '"' : '' ?>>
                        <span class="sik-trust__icon"><?= icon($feature['icon'] ?: 'shield', 'w-5 h-5') ?></span>
                        <span>
                            <span class="sik-trust__title"><?= e($feature['title']) ?></span>
                            <?php if (!empty($feature['subtitle'])): ?>
                                <span class="sik-trust__sub"><?= e($feature['subtitle']) ?></span>
                            <?php endif; ?>
                        </span>
                    </<?= $tag ?>>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php
}

/**
 * The featured categories at any depth, for the homepage tiles.
 *
 * featured_categories() only reads the top of the tree, so a store with one
 * umbrella category and three featured children showed a single tile. A
 * featured parent whose children are featured too is replaced by those
 * children — a tile for "everything" beside tiles for its parts says the same
 * thing twice.
 */
function category_tiles(int $limit = 8): array
{
    $tiles = [];
    foreach (category_tree() as $root) {
        $children = array_values(array_filter(
            $root['children'] ?? [],
            static fn ($child) => (int) $child['is_featured'] === 1
        ));
        if ($children !== []) {
            array_push($tiles, ...$children);
        } elseif ((int) $root['is_featured'] === 1) {
            $tiles[] = $root;
        }
    }
    return array_slice($tiles, 0, max(1, $limit));
}

/**
 * The picture for a category tile: the category's own image when it has one,
 * otherwise a photo of its most popular product. Real data either way — never
 * a stock illustration standing in for the range.
 */
function category_tile_image(array $category): array
{
    if (!empty($category['image'])) {
        $file = strtolower((string) $category['image']);

        // An SVG or a PNG is almost always artwork - an illustration or a cut-out
        // on a transparent background - so it sits INSIDE the tile with room
        // around it. A JPG or a WEBP is almost always a photograph, which wants
        // to fill the frame. Guessing from the format beats a second admin field
        // asking an operator to classify their own upload.
        $artwork = (bool) preg_match('/\.(svg|png)$/', $file);

        return [
            'src'     => category_image_url((string) $category['image']),
            'contain' => $artwork,
        ];
    }

    $first = query_products([
        'category_id' => (int) $category['id'], 'per_page' => 1, 'sort' => 'popularity',
    ])['items'][0] ?? null;

    return [
        'src'     => $first !== null ? (string) $first['image_url'] : img_url(null),
        'contain' => $first === null,
    ];
}

/** One category tile: photo, name and item count. */
function category_tile(array $category): string
{
    $image = cache_remember(
        'category.tile.' . (int) $category['id'],
        600,
        static fn (): array => category_tile_image($category)
    );
    $count = (int) ($category['product_count'] ?? 0);

    return '<a class="sik-cat" href="' . e(category_url((string) $category['slug'])) . '">'
        . '<span class="sik-cat__media' . ($image['contain'] ? ' sik-cat__media--contain' : '') . '">'
        . '<img src="' . e($image['src']) . '" alt="" width="480" height="480" loading="lazy" decoding="async">'
        . '</span>'
        . '<span class="sik-cat__body">'
        . '<span class="sik-cat__name">' . e((string) $category['name']) . '</span>'
        . '<span class="sik-cat__count">' . $count . ' ' . ($count === 1 ? 'item' : 'items') . '</span>'
        . '</span>'
        . '</a>';
}

function widget_categories(array $widget): void
{
    $categories = category_tiles((int) $widget['item_limit']);
    if ($categories === []) {
        return;
    }

    $isCarousel = $widget['layout'] === 'carousel';

    // The heading carries the builder's "view all" link when it has one; the
    // row only grows its own All categories tile when the heading does not,
    // so the section never offers the same destination twice.
    $headingLinks = !empty($widget['link_text']) && !empty($widget['link_url']);

    // Never more columns than tiles: three categories in a four-column grid
    // leave a hole at the end of the row.
    $count = count($categories) + ($headingLinks ? 0 : 1);
    // A short row of small circles spread across a 1200px container reads as
    // three lonely dots. The tile grows when there are few of them, so a
    // catalogue with three departments gets a deliberate three-up feature row
    // and one with a dozen gets the compact marketplace row the design asks
    // for. Sizes are the circle's diameter; the CSS reads --cat-w.
    [$tileSize, $tileW] = $count <= 4 ? [104, 132] : ($count <= 6 ? [88, 112] : [72, 96]);

    $cols  = sprintf(
        '--cols-desktop:%d;--cols-tablet:%d;--cols-mobile:%d',
        max(1, min($count, (int) $widget['cols_desktop'] ?: 4)),
        max(1, min($count, (int) $widget['cols_tablet'] ?: 3)),
        max(1, min($count, (int) $widget['cols_mobile'] ?: 2))
    ) . sprintf(';--cat-size:%dpx;--cat-w:%dpx', $tileSize, $tileW);

    // Settings > Theme > "Enable entrance animations" off means "nothing moves
    // on its own". initRails' autoplay is a setInterval that scrolls the rail
    // forever and checks neither that switch nor prefers-reduced-motion, and
    // CSS cannot stop a timer — withholding the interval is the only lever the
    // renderer has. The builder's own autoplay choice still decides the rest.
    $autoplay = (int) $widget['autoplay'] === 1 && setting_bool('enable_animations', true)
        ? (int) $widget['autoplay_speed']
        : 0;

    // The last tile goes wherever the builder already points its "view all"
    // link, so the heading and the row cannot disagree about where everything
    // lives.
    $allUrl = !empty($widget['link_url']) ? url((string) $widget['link_url']) : url('shop.php');

    // The two placeholders category_tile_image() ends at when there is nothing
    // real to show: no image on the category, no product to borrow a photo
    // from, or a stored path whose file has since gone missing. Compared by
    // value rather than by a new flag in the cached array, because
    // category.tile.* entries live for ten minutes and an added key would be
    // missing on every already-cached category until they expire.
    $blanks = [img_url(null), category_image_url(null)];

    $tile = static function (array $category) use ($blanks): string {
        // Same cache entry category_tile() uses, so the two never disagree.
        $image = cache_remember(
            'category.tile.' . (int) $category['id'],
            600,
            static fn (): array => category_tile_image($category)
        );

        $name    = (string) $category['name'];
        $src     = (string) ($image['src'] ?? '');
        $isBlank = $src === '' || in_array($src, $blanks, true);
        // A logo or an SVG icon is artwork, not a photograph: it sits inside
        // the circle rather than being cropped to fill it.
        $contain = !$isBlank && !empty($image['contain']);

        // The letter is decorative twice over: the link text already carries
        // the name, and the glyph is a stand-in for a picture.
        $inner = $isBlank
            ? '<span class="sik-catrow__initial" aria-hidden="true">'
                . e(mb_strtoupper(mb_substr(trim($name), 0, 1))) . '</span>'
            : '<img src="' . e($src) . '" alt="" width="480" height="480" loading="lazy" decoding="async">';

        return '<a class="sik-catrow__tile" href="' . e(category_url((string) $category['slug'])) . '">'
            . '<span class="sik-catrow__ring' . ($contain ? ' sik-catrow__ring--contain' : '') . '">'
            . $inner
            . '</span>'
            . '<span class="sik-catrow__label">' . e($name) . '</span>'
            . '</a>';
    };

    $allTile = $headingLinks ? '' : '<a class="sik-catrow__tile" href="' . e($allUrl) . '">'
        . '<span class="sik-catrow__ring sik-catrow__ring--all">' . icon('grid', 'w-6 h-6') . '</span>'
        . '<span class="sik-catrow__label">All categories</span>'
        . '</a>';
    ?>
    <section <?= widget_section_attrs($widget) ?>>
        <div class="sik-container">
            <?php widget_heading($widget); ?>

            <?php if ($isCarousel): ?>
                <div class="sik-rail sik-rail--cats" data-rail data-autoplay="<?= $autoplay ?>">
                    <?php if ((int) $widget['show_arrows'] === 1): ?>
                        <button type="button" class="sik-rail__nav sik-rail__nav--prev" data-rail-prev aria-label="Previous">
                            <?= icon('chevron-left', 'w-5 h-5') ?>
                        </button>
                    <?php endif; ?>
                    <?php /* No data-anim on the track: it is the scroll container, and the
                             entrance transform would fight its scroll position. */ ?>
                    <div class="sik-rail__track sik-catrow" style="<?= e_attr(widget_cols_style($widget, 'rail')) ?>" data-rail-track>
                        <?php foreach ($categories as $category): ?>
                            <div class="sik-rail__item sik-catrow__slot"><?= $tile($category) ?></div>
                        <?php endforeach; ?>
                        <div class="sik-rail__item sik-catrow__slot"><?= $allTile ?></div>
                    </div>
                    <?php if ((int) $widget['show_arrows'] === 1): ?>
                        <button type="button" class="sik-rail__nav sik-rail__nav--next" data-rail-next aria-label="Next">
                            <?= icon('chevron-right', 'w-5 h-5') ?>
                        </button>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="sik-catrow sik-catrow--wrap" style="<?= e_attr($cols) ?>" data-anim="fade-up">
                    <?php foreach ($categories as $category): ?>
                        <?= $tile($category) ?>
                    <?php endforeach; ?>
                    <?= $allTile ?>
                </div>
            <?php endif; ?>
        </div>
    </section>
    <?php
}

function widget_products(array $widget, string $layout = 'grid'): void
{
    $manualIds = array_map('intval', explode(',', (string) widget_setting($widget, 'product_ids', '')));
    $products = get_products_for_source(
        (string) $widget['data_source'],
        (int) $widget['item_limit'],
        $widget['source_id'] !== null ? (int) $widget['source_id'] : null,
        array_filter($manualIds)
    );

    if ($products === []) {
        return;
    }

    $cardStyle = (string) $widget['card_style'];
    ?>
    <section <?= widget_section_attrs($widget) ?>>
        <div class="sik-container">
            <?php widget_heading($widget); ?>
            <div data-anim="fade-up">
                <?= $layout === 'carousel' || $widget['layout'] === 'carousel'
                    ? product_rail($products, $widget, $cardStyle)
                    : product_grid($products, $widget, $cardStyle) ?>
            </div>
        </div>
    </section>
    <?php
}

function widget_deal(array $widget): void
{
    $deal = get_active_deal();
    if ($deal === null || empty($deal['product_id'])) {
        return;
    }

    $product = get_product((int) $deal['product_id']);
    if ($product === null) {
        return;
    }

    $stockLimit = $deal['stock_limit'] !== null ? (int) $deal['stock_limit'] : null;
    $sold = (int) $deal['stock_sold'];
    $remaining = $stockLimit !== null ? max(0, $stockLimit - $sold) : (int) $product['stock'];
    $soldPercent = $stockLimit !== null && $stockLimit > 0
        ? min(100, (int) round(($sold / $stockLimit) * 100))
        : 0;
    $secondsLeft = seconds_until($deal['end_time']);
    ?>
    <section <?= widget_section_attrs($widget) ?>>
        <div class="sik-container">
            <div class="sik-deal" data-anim="fade-up">
                <div class="sik-deal__grid">
                    <div>
                        <span class="sik-deal__eyebrow"><?= e($deal['subtitle'] ?: 'Deal of the Day') ?></span>
                        <h2 class="sik-deal__title"><?= e($deal['title']) ?></h2>

                        <?php /* Type comes from .sik-deal__* in app.css. Inline font sizes here
                                 outranked the stylesheet, so the deal was the one block whose
                                 price and product name could not follow the type scale. */ ?>
                        <a class="sik-deal__product" href="<?= e($product['url']) ?>">
                            <span class="sik-card__brand"><?= e($product['brand_name'] ?? '') ?></span>
                            <span class="sik-deal__product-name"><?= e($product['name']) ?></span>
                        </a>

                        <?php if ((int) $product['rating_count'] > 0): ?>
                            <div class="sik-card__rating sik-deal__rating">
                                <?= rating_stars((float) $product['rating_avg']) ?>
                                <span><?= number_format((float) $product['rating_avg'], 1) ?> · <?= (int) $product['rating_count'] ?> reviews</span>
                            </div>
                        <?php endif; ?>

                        <div class="sik-card__price sik-deal__price">
                            <span class="sik-price sik-num"><?= e($product['price_display']) ?></span>
                            <?php if (!empty($product['on_sale'])): ?>
                                <span class="sik-price--mrp sik-num"><?= e($product['mrp_display']) ?></span>
                                <span class="sik-price--off"><?= (int) $product['discount'] ?>% off</span>
                            <?php endif; ?>
                        </div>

                        <?php if ($stockLimit !== null): ?>
                            <div class="sik-deal__meter">
                                <div class="sik-deal__meter-row">
                                    <span>Sold: <?= $sold ?></span>
                                    <span class="sik-deal__left">Only <?= $remaining ?> left!</span>
                                </div>
                                <div class="sik-progress"><span style="width:<?= $soldPercent ?>%"></span></div>
                            </div>
                        <?php endif; ?>

                        <?php if ($secondsLeft > 0): ?>
                            <div class="sik-deal__timer">
                                <div class="sik-deal__timer-label">
                                    Hurry! Offer ends in
                                </div>
                                <div class="sik-countdown" data-countdown="<?= $secondsLeft ?>" data-expired-text="This deal has ended">
                                    <span class="sik-countdown__unit"><b class="sik-countdown__num" data-cd="days">00</b><span class="sik-countdown__label">Days</span></span>
                                    <span class="sik-countdown__unit"><b class="sik-countdown__num" data-cd="hours">00</b><span class="sik-countdown__label">Hrs</span></span>
                                    <span class="sik-countdown__unit"><b class="sik-countdown__num" data-cd="minutes">00</b><span class="sik-countdown__label">Min</span></span>
                                    <span class="sik-countdown__unit"><b class="sik-countdown__num" data-cd="seconds">00</b><span class="sik-countdown__label">Sec</span></span>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="sik-deal__actions">
                            <?= add_to_cart_button($product, [
                                'size'  => 'lg',
                                'block' => false,
                                // The admin's own wording for this deal, if set.
                                'label' => !empty($deal['button_text']) ? (string) $deal['button_text'] : null,
                            ]) ?>
                            <a class="sik-btn sik-btn--outline sik-btn--lg" href="<?= e($product['url']) ?>">View Details</a>
                        </div>
                    </div>

                    <div class="sik-deal__media">
                        <a href="<?= e($product['url']) ?>">
                            <img src="<?= e($product['image_url']) ?>" alt="<?= e($product['name']) ?>"
                                 width="420" height="420" loading="lazy">
                        </a>
                        <?php if ((int) $product['discount'] > 0): ?>
                            <div class="sik-deal__off">
                                <b><?= (int) $product['discount'] ?>%</b>
                                <span>OFF</span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </section>
    <?php
}

function widget_flash_sale(array $widget): void
{
    $sale = get_active_flash_sale();
    $products = $sale !== null ? get_products_for_source('flash', (int) $widget['item_limit']) : [];

    // No flash sale configured - or one configured with nothing in it - falls
    // back to whatever is genuinely reduced right now, deepest discount first.
    // The band then carries the store's real savings and NO countdown, because
    // there is no end time to count down to. That is the same trade the Best
    // sellers source makes when nothing has been flagged, and it matters more
    // here: a clock counting down to a deadline the store has not set is the
    // one thing this band must never show.
    $isFallback = false;
    if ($products === []) {
        $products   = get_products_for_source('sale', (int) $widget['item_limit']);
        $isFallback = true;
    }
    if ($products === []) {
        return;
    }

    $secondsLeft = $isFallback ? 0 : seconds_until($sale['end_time']);

    // The Days cell is dropped rather than hidden in CSS. app.js's set() looks
    // the node up with querySelector and no-ops when it is missing, so nothing
    // breaks either way — but a sale ending this evening should not carry a
    // permanently "00 Days" cell next to a live two-hour timer.
    $units = ['hours' => 'Hours', 'minutes' => 'Minutes', 'seconds' => 'Seconds'];
    if ($secondsLeft >= 86400) {
        $units = ['days' => 'Days'] + $units;
    }

    // The qualifier pill. The sale's own subtitle wins because it describes
    // THIS sale; the builder's subtitle — present in the DB and never rendered
    // until now — is the fallback. There is deliberately no third, hard-coded
    // option: no pill at all is better than copy the store never wrote.
    // In fallback mode the sale's subtitle does not exist and the builder's
    // one was written for a flash sale, so the pill carries the only thing
    // that is true of this band: how many products are actually reduced.
    $qualifier = $isFallback
        ? count($products) . ' reduced right now'
        : trim((string) ($sale['subtitle'] ?? ''));
    if ($qualifier === '') {
        $qualifier = trim((string) ($widget['subtitle'] ?? ''));
    }

    // Seeded as 'SEE ALL' / 'deals.php?type=flash' and never rendered before.
    $hasLink = !empty($widget['link_text']) && !empty($widget['link_url']);

    // The block paints its own gradient bar and surface panel, so the builder's
    // band colours would only fight it — and app.css applies them in light mode
    // only, which would leave the two themes looking like different components
    // (the seeded pair is a navy background with white text forced onto every
    // heading, including the product cards'). Cleared here rather than in the
    // DB so the same fields keep working for every other widget type.
    $widget['bg_color'] = null;
    $widget['text_color'] = null;
    ?>
    <section <?= widget_section_attrs($widget, 'sik-flash') ?>>
        <div class="sik-container">
            <div class="sik-flash__wrap" data-anim="fade-up">
                <div class="sik-flash__head">
                    <div class="sik-flash__lead">
                        <h2 class="sik-flash__title">
                            <?= icon('zap', '', true) ?>
                            <span>
                                <?= e($widget['title'] ?: (string) ($sale['name'] ?? '')) ?>
                                <?php if (!empty($widget['title_accent'])): ?>
                                    <span class="sik-flash__accent"><?= e($widget['title_accent']) ?></span>
                                <?php endif; ?>
                            </span>
                        </h2>
                        <?php if ($qualifier !== ''): ?>
                            <p class="sik-flash__sub"><?= e($qualifier) ?></p>
                        <?php endif; ?>
                    </div>

                    <?php if ($secondsLeft > 0 || $hasLink): ?>
                        <div class="sik-flash__aside">
                            <?php if ($secondsLeft > 0): ?>
                                <div class="sik-flash__timer">
                                    <span class="sik-flash__timer-label">Ends in</span>
                                    <?php /* No sik-countdown--dark: the cells are light on this bar, so
                                             the modifier would only lie. Section 15 styles them from
                                             .sik-flash__head instead, which also catches the one
                                             deals.php prints. The data-countdown / data-cd contract
                                             app.js writes into is unchanged. */ ?>
                                    <div class="sik-countdown" data-countdown="<?= $secondsLeft ?>" data-expired-text="Sale ended">
                                        <?php foreach ($units as $unit => $label): ?>
                                            <span class="sik-countdown__unit"><b class="sik-countdown__num" data-cd="<?= e($unit) ?>">00</b><span class="sik-countdown__label"><?= e($label) ?></span></span>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if ($hasLink): ?>
                                <a class="sik-viewall" href="<?= e(url((string) $widget['link_url'])) ?>">
                                    <?= e($widget['link_text']) ?> <?= icon('arrow-right', 'w-3.5 h-3.5') ?>
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="sik-flash__panel">
                    <?= product_rail($products, $widget, (string) $widget['card_style']) ?>
                </div>
            </div>
        </div>
    </section>
    <?php
}

function widget_brands(array $widget): void
{
    $brands = all_brands(true);
    if ($brands === []) {
        $brands = all_brands();
    }
    if ($brands === []) {
        return;
    }
    $brands = array_slice($brands, 0, max(1, (int) $widget['item_limit']));
    ?>
    <section <?= widget_section_attrs($widget) ?>>
        <div class="sik-container">
            <?php widget_heading($widget); ?>

            <div class="sik-rail" data-rail data-autoplay="<?= (int) $widget['autoplay'] === 1 ? (int) $widget['autoplay_speed'] : 0 ?>">
                <?php if ((int) $widget['show_arrows'] === 1): ?>
                    <button type="button" class="sik-rail__nav sik-rail__nav--prev" data-rail-prev aria-label="Previous">
                        <?= icon('chevron-left', 'w-5 h-5') ?>
                    </button>
                <?php endif; ?>

                <div class="sik-rail__track" style="<?= e_attr(widget_cols_style($widget, 'rail')) ?>" data-rail-track>
                    <?php foreach ($brands as $brand): ?>
                        <div class="sik-rail__item">
                            <a class="sik-brandtile" href="<?= e(brand_url((string) $brand['slug'])) ?>">
                                <img src="<?= e(img_url($brand['logo'])) ?>" alt="<?= e($brand['name']) ?>"
                                     width="120" height="50" loading="lazy">
                                <span><?= (int) $brand['product_count'] ?> products</span>
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if ((int) $widget['show_arrows'] === 1): ?>
                    <button type="button" class="sik-rail__nav sik-rail__nav--next" data-rail-next aria-label="Next">
                        <?= icon('chevron-right', 'w-5 h-5') ?>
                    </button>
                <?php endif; ?>
            </div>
        </div>
    </section>
    <?php
}

function widget_promo(array $widget): void
{
    $banners = cache_remember('banners.promo', 300, static function () {
        return Database::fetchAll(
            "SELECT * FROM `banners`
             WHERE `position` = 'promo' AND `status` = 'active'
               AND (`start_date` IS NULL OR `start_date` <= NOW())
               AND (`end_date` IS NULL OR `end_date` >= NOW())
             ORDER BY `sort_order`, `id`"
        );
    });

    if ($banners === []) {
        return;
    }

    // "From ₹149" is measured from the catalogue the card's own button opens,
    // the same rule as the hero medallion (catalogue_max_discount()): the
    // banners table has no price column, and a price typed into one would go
    // stale the first time a product was re-priced. A button that does not
    // open a single category has no meaningful "from", so that card simply
    // gets no price line.
    $startingPrice = static function (array $banner): string {
        $query = [];
        parse_str((string) (parse_url((string) ($banner['button_url'] ?? ''), PHP_URL_QUERY) ?? ''), $query);
        $slug = trim((string) ($query['category'] ?? ''));
        if ($slug === '') {
            return '';
        }

        return (string) cache_remember('banners.promo.from.' . $slug, 600, static function () use ($slug): string {
            $cheapest = query_products(['category' => $slug, 'per_page' => 1, 'sort' => 'price_asc'])['items'];
            return $cheapest === [] ? '' : (string) $cheapest[0]['price_display'];
        });
    };
    ?>
    <section <?= widget_section_attrs($widget) ?>>
        <div class="sik-container">
            <div class="sik-promos">
                <?php foreach ($banners as $index => $banner): ?>
                    <?php
                    // Shipped placeholder art is left out; an uploaded image is
                    // shown. With neither, the corner photo is one of the
                    // store's own product shots chosen from whatever the button
                    // opens — hero_slide_products() already makes exactly that
                    // choice, and banner ids never collide with the hero's.
                    $ownImage = !banner_image_is_stock((string) ($banner['desktop_image'] ?? ''));
                    $tiles    = $ownImage ? [] : hero_slide_products($banner, $index, 1);
                    $photo    = $tiles[0] ?? null;

                    // banners.subtitle is the one-line version of the pitch and
                    // has never reached the storefront; description is the
                    // two-sentence paragraph written for the old full-width
                    // panel and is far too long for a card. Short line first,
                    // the long one only so a banner with no subtitle still
                    // says something.
                    $sub = trim((string) ($banner['subtitle'] ?? ''));
                    if ($sub === '') {
                        $sub = trim((string) ($banner['description'] ?? ''));
                    }

                    // A headline of "From" + "₹149" already is the price line;
                    // printing the measured one under it would say the same
                    // thing twice.
                    $price   = $startingPrice($banner);
                    $heading = trim((string) $banner['title'] . ' ' . (string) ($banner['title_accent'] ?? ''));
                    if ($price !== '' && str_contains($heading, $price)) {
                        $price = '';
                    }

                    // Wine, plum, indigo, then round again. Each card reveals on
                    // its own so the row arrives as a short stagger rather than
                    // one block.
                    $variant = ($index % 3) + 1;
                    ?>
                    <article class="sik-promo sik-onwine sik-promo--<?= $variant ?>"
                             data-anim="fade-up" data-anim-delay="<?= ($index % 3) * 90 ?>"
                             <?= !empty($banner['bg_color']) ? 'style="--promo-bg:' . e_attr($banner['bg_color']) . '"' : '' ?>>
                        <?php if ($ownImage): ?>
                            <div class="sik-promo__photo" aria-hidden="true">
                                <picture>
                                    <?php if (!empty($banner['mobile_image']) && !banner_image_is_stock((string) $banner['mobile_image'])): ?>
                                        <source media="(max-width: 767px)" srcset="<?= e(img_url($banner['mobile_image'])) ?>">
                                    <?php endif; ?>
                                    <img src="<?= e(img_url($banner['desktop_image'])) ?>" alt=""
                                         width="480" height="320" loading="lazy" decoding="async">
                                </picture>
                            </div>
                        <?php elseif ($photo !== null): ?>
                            <div class="sik-promo__photo" aria-hidden="true">
                                <img src="<?= e($photo['image_url']) ?>" alt=""
                                     width="600" height="600" loading="lazy" decoding="async">
                            </div>
                        <?php endif; ?>

                        <div class="sik-promo__body">
                            <?php if (!empty($banner['badge'])): ?>
                                <?php // The banner's badge line, set in the serif italic the
                                      // storefront uses for a festive flourish. ?>
                                <span class="sik-flourish sik-promo__eyebrow"><?= e($banner['badge']) ?></span>
                            <?php endif; ?>

                            <h2 class="sik-promo__title">
                                <?= e($banner['title']) ?>
                                <?php if (!empty($banner['title_accent'])): ?>
                                    <em><?= e($banner['title_accent']) ?></em>
                                <?php endif; ?>
                            </h2>

                            <?php if ($sub !== ''): ?>
                                <p class="sik-promo__sub"><?= e($sub) ?></p>
                            <?php endif; ?>

                            <?php if ($price !== ''): ?>
                                <p class="sik-promo__price">From <b class="sik-num"><?= e($price) ?></b></p>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($banner['button_text'])): ?>
                            <a class="sik-btn sik-btn--onwine sik-btn--pill sik-promo__cta"
                               href="<?= e(url((string) $banner['button_url'])) ?>">
                                <?= e($banner['button_text']) ?> <?= icon('arrow-right', 'w-4 h-4') ?>
                            </a>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php
}

function widget_stats(array $widget): void
{
    $stats = cache_remember('stats.active', 600, static function () {
        return Database::fetchAll(
            "SELECT * FROM `site_stats` WHERE `status` = 'active' ORDER BY `sort_order`, `id`"
        );
    });

    if ($stats === []) {
        return;
    }
    ?>
    <section <?= widget_section_attrs($widget) ?>>
        <div class="sik-container">
            <?php widget_heading($widget); ?>
            <div class="sik-stats" data-anim="fade-up">
                <?php foreach ($stats as $stat): ?>
                    <div class="sik-stat">
                        <span class="sik-stat__icon"><?= icon($stat['icon'] ?: 'users', '') ?></span>
                        <span class="sik-stat__value"><?= e($stat['value']) ?></span>
                        <span class="sik-stat__label"><?= e($stat['label']) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php
}

function widget_testimonials(array $widget): void
{
    $testimonials = cache_remember('testimonials.active', 600, static function () {
        return Database::fetchAll(
            "SELECT * FROM `testimonials` WHERE `status` = 'active' ORDER BY `sort_order`, `id`"
        );
    });

    if ($testimonials === []) {
        return;
    }
    $testimonials = array_slice($testimonials, 0, max(1, (int) $widget['item_limit']));
    ?>
    <section <?= widget_section_attrs($widget, 'sik-section--soft') ?>>
        <div class="sik-container">
            <?php widget_heading($widget); ?>

            <div class="sik-rail" data-rail data-autoplay="<?= (int) $widget['autoplay'] === 1 ? (int) $widget['autoplay_speed'] : 0 ?>">
                <?php if ((int) $widget['show_arrows'] === 1): ?>
                    <button type="button" class="sik-rail__nav sik-rail__nav--prev" data-rail-prev aria-label="Previous">
                        <?= icon('chevron-left', 'w-5 h-5') ?>
                    </button>
                <?php endif; ?>

                <div class="sik-rail__track" style="<?= e_attr(widget_cols_style($widget, 'rail')) ?>" data-rail-track>
                    <?php foreach ($testimonials as $testimonial): ?>
                        <div class="sik-rail__item">
                            <figure class="sik-quote">
                                <?= rating_stars((float) $testimonial['rating'], 'w-4 h-4') ?>
                                <?php if (!empty($testimonial['title'])): ?>
                                    <strong style="font-size:14px"><?= e($testimonial['title']) ?></strong>
                                <?php endif; ?>
                                <blockquote class="sik-quote__text"><?= e($testimonial['message']) ?></blockquote>
                                <figcaption class="sik-quote__author">
                                    <img class="sik-quote__avatar" src="<?= e(img_url($testimonial['avatar'], 'assets/images/placeholders/avatar-1.svg')) ?>"
                                         alt="" width="42" height="42" loading="lazy">
                                    <span>
                                        <span class="sik-quote__name"><?= e($testimonial['customer_name']) ?></span>
                                        <span class="sik-quote__role"><?= icon('check-circle', 'w-3.5 h-3.5') ?> <?= e($testimonial['designation']) ?></span>
                                    </span>
                                </figcaption>
                            </figure>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if ((int) $widget['show_arrows'] === 1): ?>
                    <button type="button" class="sik-rail__nav sik-rail__nav--next" data-rail-next aria-label="Next">
                        <?= icon('chevron-right', 'w-5 h-5') ?>
                    </button>
                <?php endif; ?>
                <?php if ((int) $widget['show_dots'] === 1): ?>
                    <div class="sik-rail__dots" data-rail-dots></div>
                <?php endif; ?>
            </div>
        </div>
    </section>
    <?php
}

function widget_newsletter(array $widget): void
{
    // The placeholder is admin-settable on the widget row; the shipped string
    // is only the fallback for a row that never had one written.
    $placeholder = trim((string) widget_setting($widget, 'placeholder', ''));
    if ($placeholder === '') {
        $placeholder = 'Your email address';
    }

    // The consent line under the field. No fallback on purpose: a promise
    // about what the store does with a shopper's address is a claim, and this
    // storefront has had invented copy stripped out of it once already. If an
    // admin has not written one, the band simply does not make the promise.
    $note = trim((string) widget_setting($widget, 'consent_text', ''));
    ?>
    <section <?= widget_section_attrs($widget) ?>>
        <div class="sik-container">
            <div class="sik-news" data-anim="fade-up">
                <div class="sik-news__copy">
                    <span class="sik-news__ico" aria-hidden="true"><?= icon('mail', 'w-5 h-5') ?></span>
                    <div class="sik-news__lines">
                        <h2 class="sik-news__title">
                            <?= e($widget['title'] ?: 'Stay in the loop') ?>
                            <?php if (!empty($widget['title_accent'])): ?>
                                <span class="sik-news__accent"><?= e($widget['title_accent']) ?></span>
                            <?php endif; ?>
                        </h2>
                        <?php if (!empty($widget['subtitle'])): ?>
                            <p class="sik-news__text"><?= e($widget['subtitle']) ?></p>
                        <?php endif; ?>
                    </div>
                </div>

                <?php /* The form and its consent line are wrapped so the band stays a
                         two-column grid: a third child would drop the note into a
                         column of its own. A plain div is safe here — account.js
                         only looks for .sik-modal / .sik-popin above the form. */ ?>
                <div class="sik-news__action">
                    <?php /* No action, method or hidden token by design. Submission is
                             fetch-based (assets/js/account.js), and SIK.apiRequest puts
                             csrf_token in the body and X-CSRF-Token on the request for
                             api_require_csrf() to check. A native action here would only
                             give the form a second, unguarded way to submit. */ ?>
                    <form class="sik-news__form" data-newsletter-form="homepage">
                        <label class="sik-sr" for="sikNewsEmail">Email address</label>
                        <input class="sik-news__input" id="sikNewsEmail" type="email" name="email"
                               placeholder="<?= e($placeholder) ?>" required autocomplete="email">
                        <?php /* The arrow is a sibling of the label, not a pseudo-element:
                                 .sik-btn--primary::after is the sheen sweep. SIK.showLoader
                                 inserts the spinner as the button's first child, so both
                                 survive together. */ ?>
                        <button type="submit" class="sik-btn sik-btn--primary">
                            <span class="sik-btn__label">Subscribe</span>
                            <?= icon('arrow-right', 'w-4 h-4') ?>
                        </button>
                    </form>
                    <?php if ($note !== ''): ?>
                        <p class="sik-news__note"><?= e($note) ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>
    <?php
}

function widget_offer_slider(array $widget): void
{
    // Only coupons an admin marked "Show as an offer" for the homepage. This
    // used to list every active coupon — including ones meant for a single
    // customer or a first order — whether anyone had chosen to advertise them.
    $offers = public_offers('home', null, max(1, (int) $widget['item_limit']));
    if ($offers === []) {
        return;
    }
    ?>
    <section <?= widget_section_attrs($widget) ?>>
        <div class="sik-container">
            <?php widget_heading($widget); ?>
            <div class="sik-offers sik-offers--rail" data-anim="fade-up">
                <?php foreach ($offers as $offer): ?>
                    <?= offer_card($offer) ?>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php
}

/**
 * One advertised offer: what it gives, its conditions, and its code.
 *
 * $action "copy" puts the code on the clipboard (product page, homepage);
 * "apply" hands it to the cart's coupon form (cart page).
 */
function offer_card(array $offer, string $action = 'copy'): string
{
    $code  = (string) $offer['code'];
    $terms = (string) ($offer['terms'] ?? '');

    $button = $action === 'apply'
        ? '<button type="button" class="sik-offer__code" data-apply-coupon="' . e_attr($code) . '"'
          . ' aria-label="' . e_attr('Apply code ' . $code) . '">' . e($code) . ' <span aria-hidden="true">· Apply</span></button>'
        : '<button type="button" class="sik-offer__code" data-copy="' . e_attr($code) . '"'
          . ' aria-label="' . e_attr('Copy code ' . $code) . '">' . e($code) . icon('copy', '') . '</button>';

    return '<div class="sik-offer">'
        . '<span class="sik-offer__icon" aria-hidden="true">'
        . icon((string) $offer['type'] === COUPON_TYPE_FREE_SHIPPING ? 'truck' : 'tag', 'w-4 h-4')
        . '</span>'
        . '<span class="sik-offer__body">'
        . '<span class="sik-offer__title">' . e((string) $offer['headline']) . '</span>'
        . ($terms !== '' ? '<span class="sik-offer__terms">' . e($terms) . '</span>' : '')
        . '</span>'
        . $button
        . '</div>';
}

/**
 * Customer reviews: the store's own approved product reviews, newest first,
 * under the average of every approved review. Four stars and up on the
 * homepage; the full, unfiltered distribution is on each product page.
 * Renders nothing until the first review is approved.
 */
function widget_reviews(array $widget): void
{
    $limit = max(1, min(12, (int) $widget['item_limit']));

    $data = cache_remember('reviews.home.' . $limit, 600, static function () use ($limit): array {
        $summary = Database::fetch(
            "SELECT COUNT(*) AS total, AVG(`rating`) AS average FROM `reviews` WHERE `status` = 'approved'"
        );
        $items = Database::fetchAll(
            "SELECT r.`id`, r.`rating`, r.`title`, r.`comment`, r.`customer_name`, r.`verified_purchase`,
                    r.`created_at`, p.`name` AS product_name, p.`slug` AS product_slug,
                    p.`main_image` AS product_image
             FROM `reviews` r
             INNER JOIN `products` p ON p.`id` = r.`product_id` AND p.`status` = 'active'
             WHERE r.`status` = 'approved' AND r.`rating` >= 4
               AND r.`comment` IS NOT NULL AND r.`comment` <> ''
             ORDER BY r.`created_at` DESC
             LIMIT " . $limit
        );
        return [
            'total'   => (int) ($summary['total'] ?? 0),
            'average' => (float) ($summary['average'] ?? 0),
            'items'   => $items,
        ];
    });

    if ($data['items'] === []) {
        return;
    }
    ?>
    <section <?= widget_section_attrs($widget) ?>>
        <div class="sik-container">
            <div class="sik-reviews">
                <div class="sik-reviews__summary">
                    <?php widget_heading($widget); ?>
                    <div class="sik-reviews__score"><?= e(number_format($data['average'], 1)) ?></div>
                    <?= rating_stars($data['average'], 'w-5 h-5') ?>
                    <p class="sik-reviews__count">
                        Based on <?= $data['total'] ?> review<?= $data['total'] === 1 ? '' : 's' ?>
                    </p>
                </div>

                <div class="sik-reviews__list">
                    <?php foreach ($data['items'] as $review): ?>
                        <article class="sik-review" data-anim="fade-up">
                            <div class="sik-review__head">
                                <?= rating_stars((float) $review['rating'], 'w-4 h-4') ?>
                                <?php if ((int) $review['verified_purchase'] === 1): ?>
                                    <span class="sik-review__verified"><?= icon('check-circle', 'w-3.5 h-3.5') ?> Verified purchase</span>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($review['title'])): ?>
                                <h3 class="sik-review__title"><?= e($review['title']) ?></h3>
                            <?php endif; ?>
                            <p class="sik-review__text"><?= e($review['comment']) ?></p>
                            <div class="sik-review__meta">
                                <span class="sik-review__author"><?= e($review['customer_name']) ?></span>
                                <span aria-hidden="true">·</span>
                                <time datetime="<?= e_attr(date('c', strtotime((string) $review['created_at']) ?: time())) ?>">
                                    <?= e(time_ago($review['created_at'])) ?>
                                </time>
                            </div>
                            <a class="sik-review__product" href="<?= e(product_url((string) $review['product_slug'])) ?>">
                                <img src="<?= e(img_url($review['product_image'])) ?>" alt="" width="40" height="40" loading="lazy">
                                <span><?= e($review['product_name']) ?></span>
                            </a>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </section>
    <?php
}

/**
 * Frequently asked questions from Content > FAQ, as native disclosures.
 *
 * Order and on/off state are the FAQ manager's. `settings.category` narrows the
 * widget to one FAQ category; the item limit caps it, and the section's link is
 * only shown when the FAQ page actually has more to read.
 */
function widget_faq(array $widget): void
{
    require_once INCLUDES_PATH . '/content-functions.php';

    $category = trim((string) widget_setting($widget, 'category', ''));
    $all      = faq_list($category);
    if ($all === []) {
        return;
    }

    $faqs = array_slice($all, 0, max(1, (int) $widget['item_limit']));
    if (count($all) <= count($faqs)) {
        $widget['link_text'] = null;
    }
    ?>
    <section <?= widget_section_attrs($widget) ?>>
        <div class="sik-container">
            <div class="sik-faq">
                <?php widget_heading($widget); ?>
                <div class="sik-faq__list">
                    <?php foreach ($faqs as $faq): ?>
                        <details class="sik-faq__item">
                            <summary class="sik-faq__q">
                                <span><?= e($faq['question']) ?></span>
                                <span class="sik-faq__icon" aria-hidden="true"></span>
                            </summary>
                            <div class="sik-faq__a"><?= nl2br(e($faq['answer']), false) ?></div>
                        </details>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </section>
    <?php
}

function widget_recently_viewed(array $widget, array $context = []): void
{
    if (!setting_bool('recently_viewed_enabled', true)) {
        return;
    }

    $products = recently_viewed_products(
        (int) $widget['item_limit'],
        isset($context['exclude_id']) ? (int) $context['exclude_id'] : null
    );

    if (count($products) < 2) {
        return;
    }
    ?>
    <section <?= widget_section_attrs($widget) ?>>
        <div class="sik-container">
            <?php widget_heading($widget); ?>
            <?= product_rail($products, $widget, 'compact') ?>
        </div>
    </section>
    <?php
}

function widget_recommendations(array $widget, array $context = []): void
{
    $productId = (int) ($context['product_id'] ?? 0);
    $categoryId = (int) ($context['category_id'] ?? 0);
    $relationType = (string) widget_setting($widget, 'relation_type', 'related');

    $products = $productId > 0
        ? related_products($productId, $categoryId, (int) $widget['item_limit'], $relationType)
        : get_products_for_source('best', (int) $widget['item_limit']);

    if ($products === []) {
        return;
    }
    ?>
    <section <?= widget_section_attrs($widget) ?>>
        <div class="sik-container">
            <?php widget_heading($widget); ?>
            <?= product_rail($products, $widget, (string) $widget['card_style']) ?>
        </div>
    </section>
    <?php
}

function widget_blog(array $widget): void
{
    $posts = cache_remember('blog.latest.' . (int) $widget['item_limit'], 600, static function () use ($widget) {
        return Database::fetchAll(
            "SELECT p.*, c.`name` AS category_name, c.`slug` AS category_slug
             FROM `blog_posts` p
             LEFT JOIN `blog_categories` c ON c.`id` = p.`category_id`
             WHERE p.`status` = 'published' AND (p.`published_at` IS NULL OR p.`published_at` <= NOW())
             ORDER BY p.`published_at` DESC, p.`id` DESC
             LIMIT " . max(1, (int) $widget['item_limit'])
        );
    });

    if ($posts === []) {
        return;
    }
    ?>
    <section <?= widget_section_attrs($widget) ?>>
        <div class="sik-container">
            <?php widget_heading($widget); ?>
            <div class="sik-grid" style="<?= e_attr(widget_cols_style($widget)) ?>">
                <?php foreach ($posts as $post): ?>
                    <article class="sik-card" data-anim="fade-up">
                        <a class="sik-card__media" href="<?= e(blog_url((string) $post['slug'])) ?>" style="aspect-ratio:16/10">
                            <img src="<?= e(img_url($post['featured_image'])) ?>" alt="<?= e($post['title']) ?>"
                                 style="object-fit:cover;padding:0" width="400" height="250" loading="lazy">
                        </a>
                        <div class="sik-card__body">
                            <?php if (!empty($post['category_name'])): ?>
                                <span class="sik-card__brand"><?= e($post['category_name']) ?></span>
                            <?php endif; ?>
                            <h3 class="sik-card__name"><a href="<?= e(blog_url((string) $post['slug'])) ?>"><?= e($post['title']) ?></a></h3>
                            <p style="font-size:13px;color:var(--sik-muted)"><?= e(str_limit($post['excerpt'], 100)) ?></p>
                            <span style="font-size:11.5px;color:var(--sik-muted);margin-top:auto;padding-top:var(--sp-2)">
                                <?= e(format_date($post['published_at'] ?: $post['created_at'])) ?>
                            </span>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php
}

function widget_html(array $widget): void
{
    if (empty($widget['custom_html'])) {
        return;
    }
    ?>
    <section <?= widget_section_attrs($widget) ?>>
        <div class="<?= $widget['container'] === 'full' ? '' : 'sik-container' ?>">
            <?php widget_heading($widget); ?>
            <div class="sik-prose"><?= sanitize_html($widget['custom_html']) ?></div>
        </div>
    </section>
    <?php
}
