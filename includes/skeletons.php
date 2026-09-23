<?php
/**
 * ShopInnKart - Skeleton loading partials.
 *
 * The PHP half of the skeleton system; the CSS half is app.css section 30b and
 * the JS half is SIK.skeleton in assets/js/app.js.
 *
 * WHERE THESE ARE USED, AND WHY ONLY THERE
 *
 * Almost every storefront page is rendered on the server and arrives complete:
 * there is no moment in which a skeleton could stand in for anything, and
 * deferring HTML that is already in the response just to show one would make
 * the page slower. So skeletons appear only where content genuinely arrives
 * after the page does:
 *
 *   - the listing grid when a filter, sort or page change refetches it
 *     (products.js), and the batch "Show more products" appends;
 *   - Quick View, between the click and the product arriving (products.js);
 *   - the mini-cart drawer before its first sync (footer.php + cart.js);
 *   - search suggestions while a query is in flight (search.js);
 *   - a homepage-builder widget the admin marked "lazy load", which renders
 *     an empty shell and fetches its body on scroll (render_widget());
 *   - and every lazily loaded image, which gets a shimmering well until it
 *     paints (SIK.images in app.js, no markup needed).
 *
 * Every partial reuses the REAL component's classes for its boxes - .sik-card,
 * .sik-card__media, .sik-card__body, .sik-pdp, .sik-minicart__item - and only
 * swaps the content for bones. That is what makes a skeleton exactly as tall
 * as the thing that replaces it: the padding, the aspect ratio, the reserved
 * two-line name and the button height all come from the same rules, so when
 * a theme setting changes the card, the skeleton follows without an edit here.
 *
 * Text is drawn as .sik-skel__line: an inline-block bone inside an element
 * carrying the real text class. The line box takes its height from that
 * class's font-size and line-height, not from the bone, so a skeleton line is
 * precisely one line of real text tall.
 *
 * Every skeleton is aria-hidden. The region being loaded carries aria-busy
 * (set by the script), which is what assistive technology reads.
 */

declare(strict_types=1);

// ===========================================================================
//  PRIMITIVES
// ===========================================================================

/**
 * One text-line bone. $width is any CSS length or percentage.
 * Wrap it in an element carrying the real text class to get the real height.
 */
function skeleton_line(string $width = '100%'): string
{
    // The span is what gives the bone a line box when the parent is a flex
    // row (a price row, a rating row): a bare inline-block there would be
    // blockified into a flex item exactly as tall as itself.
    return '<span class="sik-skel__text"><i class="sik-skel__line" style="--w:' . e_attr($width) . '"></i></span>';
}

/** A block bone with an explicit size (a thumbnail, a disc, a pill, a bar). */
function skeleton_bone(string $class = '', string $style = ''): string
{
    return '<span class="sik-skel__bone' . ($class !== '' ? ' ' . e_attr($class) : '') . '"'
        . ($style !== '' ? ' style="' . e_attr($style) . '"' : '') . '></span>';
}

/**
 * The "could not load" block that replaces a skeleton when a request fails.
 * A skeleton must never be left shimmering over a request that has already
 * failed: this is what takes its place, with a way to try again.
 */
function skeleton_load_error(string $title = 'Unable to load products', string $text = ''): string
{
    $text = $text !== '' ? $text : 'The connection dropped or the server did not answer.';

    return '<div class="sik-empty sik-empty--sm sik-loaderr" data-skel-error>'
        . icon('alert', 'w-10 h-10')
        . '<p class="sik-empty__title" role="alert" data-skel-error-title>' . e($title) . '</p>'
        . '<p class="sik-empty__text" data-skel-error-text>' . e($text) . '</p>'
        . '<button type="button" class="sik-btn sik-btn--primary" data-skel-retry>'
        . icon('refresh', 'w-4 h-4') . '<span class="sik-btn__label">Retry</span></button>'
        . '</div>';
}

// ===========================================================================
//  PRODUCT CARD / GRID / RAIL
// ===========================================================================

/**
 * <ProductCardSkeleton /> - one card, box-for-box the same as product_card().
 *
 * @param array $opts style   standard | compact | minimal | premium | horizontal
 *                    brand   draw the brand line (product_card() prints it when
 *                            the product has a brand)
 *                    rating  draw the rating row (printed when it has reviews)
 *                    tools   draw the wishlist disc on the photo
 *                    badge   draw the badge pill on the photo
 */
function skeleton_product_card(array $opts = []): string
{
    $opts += [
        'style'  => 'standard',
        'brand'  => true,
        'rating' => true,
        'tools'  => wishlist_shows_on('card'),
        'badge'  => true,
    ];
    $style = (string) $opts['style'];

    $classes = ['sik-card', 'sik-skel', 'sik-skel-card'];
    if ($style === 'horizontal') $classes[] = 'sik-card--row';
    if ($style === 'compact')    $classes[] = 'sik-card--compact';
    if ($style === 'minimal')    $classes[] = 'sik-card--minimal';
    if ($style === 'premium')    $classes[] = 'sik-card--premium';

    $html = '<div class="' . e_attr(implode(' ', $classes)) . '" aria-hidden="true" data-skel="card">'
        . '<div class="sik-card__media sik-skel__well"></div>';

    if ($opts['badge']) {
        $html .= '<div class="sik-card__badges">' . skeleton_bone('sik-skel__pill', 'width:62px') . '</div>';
    }
    if ($opts['tools']) {
        $html .= '<div class="sik-card__tools">' . skeleton_bone('sik-skel__disc') . '</div>';
    }

    $html .= '<div class="sik-card__body">';
    if ($opts['brand']) {
        $html .= '<span class="sik-card__brand" data-skel-part="brand">' . skeleton_line('36%') . '</span>';
    }
    // Two lines, because .sik-card__name reserves two whether the name needs
    // them or not. A <div>, not the card's <h3>: a skeleton is not a heading.
    $html .= '<div class="sik-card__name">' . skeleton_line('94%') . '<br>' . skeleton_line('58%') . '</div>';
    if ($opts['rating']) {
        // Five 14px stars and a "4.5 (12)" count, as two pieces, so the row
        // wraps onto a second line exactly where the real one does (a two-up
        // card at 320px).
        $html .= '<div class="sik-card__rating" data-skel-part="rating">'
            . skeleton_bone('sik-skel__stars') . skeleton_line('4.6em') . '</div>';
    }
    if ($style === 'horizontal') {
        $html .= '<p class="sik-caption">' . skeleton_line('100%') . '<br>' . skeleton_line('92%')
            . '<br>' . skeleton_line('64%') . '</p>';
    }
    // Price, MRP and saving at their real widths: on a phone the three do not
    // fit one line and the real row wraps, so the skeleton has to as well.
    $html .= '<div class="sik-card__price"><span class="sik-price">' . skeleton_line('3.6em') . '</span>'
        . '<span class="sik-price--mrp" data-skel-part="sale">' . skeleton_line('3.3em') . '</span>'
        . '<span class="sik-price--off" data-skel-part="sale">' . skeleton_line('4em') . '</span></div>';

    if ($style !== 'minimal') {
        // The real control's own classes, so its min-height - 36/40px, 44px on
        // a finger - is whatever section 11 says it is today.
        $size = $style === 'compact' ? 'sm' : 'md';
        $html .= '<div class="sik-card__foot"><span class="sik-atc sik-atc--' . $size
            . ' sik-atc--block sik-skel__bone"></span></div>';
    }

    return $html . '</div></div>';
}

/**
 * <ProductGridSkeleton /> - $count cards in the same .sik-grid the listing and
 * the product_grid widget use. $colsStyle is the --cols-* custom properties.
 */
function skeleton_product_grid(int $count = 8, string $colsStyle = '', array $cardOpts = []): string
{
    $colsStyle = $colsStyle !== '' ? $colsStyle : '--cols-desktop:4;--cols-tablet:3;--cols-mobile:2';

    $html = '<div class="sik-grid" style="' . e_attr($colsStyle) . '" aria-hidden="true">';
    for ($i = 0; $i < max(1, $count); $i++) {
        $html .= skeleton_product_card($cardOpts);
    }
    return $html . '</div>';
}

/** A product rail: the same track and item widths as product_rail(). */
function skeleton_product_rail(int $count = 5, string $railStyle = '', array $cardOpts = []): string
{
    $html = '<div class="sik-rail" aria-hidden="true"><div class="sik-rail__track sik-skel-track"'
        . ($railStyle !== '' ? ' style="' . e_attr($railStyle) . '"' : '') . '>';
    for ($i = 0; $i < max(1, $count); $i++) {
        $html .= '<div class="sik-rail__item">' . skeleton_product_card($cardOpts) . '</div>';
    }
    return $html . '</div></div>';
}

// ===========================================================================
//  PRODUCT DETAIL (Quick View body)
// ===========================================================================

/**
 * <ProductDetailSkeleton /> - the .sik-pdp pair Quick View renders: a square
 * gallery with its thumbnail strip, then the buy column.
 */
function skeleton_product_detail(): string
{
    $thumbs = '';
    for ($i = 0; $i < 4; $i++) {
        $thumbs .= '<span class="sik-gallery__thumb sik-skel__well"></span>';
    }

    // The inline padding matches api/products/details.php, which sets the
    // real body's 22px the same way.
    return '<div class="sik-pdp sik-skel sik-skel-detail" style="padding:22px" aria-hidden="true" data-skel="detail">'
        . '<div data-gallery>'
        .   '<div class="sik-gallery__main sik-skel__well"></div>'
        .   '<div class="sik-gallery__thumbs">' . $thumbs . '</div>'
        . '</div>'
        . '<div>'
        .   '<span class="sik-card__brand" style="display:block;margin-bottom:6px">' . skeleton_line('22%') . '</span>'
        // Three title lines: the catalogue's names are long, and at Quick View's
        // display size they run to three or four.
        .   '<div class="sik-pdp__title">' . skeleton_line('92%') . '<br>' . skeleton_line('84%')
        .     '<br>' . skeleton_line('56%') . '</div>'
        .   '<div class="sik-pdp__meta" style="margin-top:10px">' . skeleton_bone('sik-skel__stars')
        .     skeleton_line('7em') . skeleton_line('8em') . '</div>'
        .   '<div class="sik-pdp__price"><span class="sik-price">' . skeleton_line('3.2em') . '</span>'
        .     skeleton_bone('sik-skel__pill', 'width:78px;height:24px') . '</div>'
        .   '<div class="sik-skel-detail__chips">' . skeleton_bone('sik-skel__pill', 'width:96px;height:26px')
        .     skeleton_bone('sik-skel__pill', 'width:136px;height:26px')
        .     skeleton_bone('sik-skel__pill', 'width:108px;height:26px') . '</div>'
        .   '<p class="sik-skel-detail__text">' . skeleton_line('100%') . '<br>' . skeleton_line('96%')
        .     '<br>' . skeleton_line('58%') . '</p>'
        .   '<div class="sik-skel-detail__row sik-skel-detail__row--qty">' . skeleton_bone('sik-skel__btn', 'width:126px;border-radius:var(--sik-radius-pill)')
        .     '<span class="sik-skel-detail__note">' . skeleton_line('7em') . '</span></div>'
        .   '<div class="sik-skel-detail__row">' . skeleton_bone('sik-skel__btn', 'width:140px')
        .     skeleton_bone('sik-skel__btn', 'width:116px') . skeleton_bone('sik-skel__disc', 'width:40px;height:40px')
        .     skeleton_bone('sik-skel__disc', 'width:40px;height:40px') . '</div>'
        .   '<div class="sik-skel-detail__features">' . skeleton_line('78%') . '<br>' . skeleton_line('70%')
        .     '<br>' . skeleton_line('74%') . '<br>' . skeleton_line('82%') . '</div>'
        . '</div>'
        . '</div>';
}

// ===========================================================================
//  CATEGORY, HERO, BLOG
// ===========================================================================

/**
 * <CategorySkeleton /> - circles and labels in the same .sik-catrow the
 * category widget renders. $cols carries its --cols-* / --cat-* properties.
 */
function skeleton_category_grid(int $count = 6, string $cols = ''): string
{
    $html = '<div class="sik-catrow sik-catrow--wrap sik-skel" aria-hidden="true"'
        . ($cols !== '' ? ' style="' . e_attr($cols) . '"' : '') . '>';
    for ($i = 0; $i < max(1, $count); $i++) {
        $html .= '<span class="sik-catrow__tile"><span class="sik-catrow__ring sik-skel__well"></span>'
            . '<span class="sik-catrow__label">' . skeleton_line('70%') . '</span></span>';
    }
    return $html . '</div>';
}

/**
 * <HeroSkeleton /> - a banner-sized block. The hero is the page's largest
 * paint and should never be lazy-loaded; this exists for an admin who does it
 * anyway, so the fold at least keeps its height.
 */
function skeleton_hero(): string
{
    return '<div class="sik-container"><div class="sik-skel sik-skel-hero" aria-hidden="true">'
        . '<div class="sik-skel-hero__copy">'
        .   skeleton_bone('sik-skel__pill', 'width:120px')
        .   '<div class="sik-skel-hero__title">' . skeleton_line('86%') . '<br>' . skeleton_line('58%') . '</div>'
        .   '<p class="sik-skel-hero__text">' . skeleton_line('92%') . '<br>' . skeleton_line('66%') . '</p>'
        .   '<div class="sik-skel-hero__actions">' . skeleton_bone('sik-skel__btn', 'width:148px')
        .     skeleton_bone('sik-skel__btn', 'width:120px') . '</div>'
        . '</div>'
        . '<div class="sik-skel-hero__art sik-skel__well"></div>'
        . '</div></div>';
}

/** Blog cards: the 16:10 media well and the text rhythm of blog_card(). */
function skeleton_blog_grid(int $count = 3, string $colsStyle = ''): string
{
    $html = '<div class="sik-grid"' . ($colsStyle !== '' ? ' style="' . e_attr($colsStyle) . '"' : '') . ' aria-hidden="true">';
    for ($i = 0; $i < max(1, $count); $i++) {
        $html .= '<div class="sik-card sik-skel sik-skel-card">'
            . '<div class="sik-card__media sik-skel__well" style="aspect-ratio:16/10"></div>'
            . '<div class="sik-card__body">'
            .   '<span class="sik-card__brand">' . skeleton_line('30%') . '</span>'
            .   '<div class="sik-card__name">' . skeleton_line('96%') . '<br>' . skeleton_line('70%') . '</div>'
            .   '<p class="sik-skel-blog__text">' . skeleton_line('100%') . '<br>' . skeleton_line('82%') . '</p>'
            .   '<span class="sik-skel-blog__date">' . skeleton_line('6em') . '</span>'
            . '</div></div>';
    }
    return $html . '</div>';
}

// ===========================================================================
//  CART, SEARCH, TABLE, LIST ROWS
// ===========================================================================

/** <CartSkeleton /> - mini-cart rows, box-for-box .sik-minicart__item. */
function skeleton_cart_lines(int $count = 2): string
{
    $html = '';
    for ($i = 0; $i < max(1, $count); $i++) {
        $html .= '<div class="sik-minicart__item sik-skel sik-skel-cartline" aria-hidden="true" data-skel="cartline">'
            . '<span class="sik-minicart__img sik-skel__well"></span>'
            . '<div class="sik-skel-cartline__body">'
            .   '<div class="sik-minicart__name">' . skeleton_line('92%') . '<br>' . skeleton_line('56%') . '</div>'
            .   '<div class="sik-minicart__row">'
            .     skeleton_bone('sik-skel__qty')
            .     '<span class="sik-price">' . skeleton_line('3.4em') . '</span>'
            .   '</div>'
            . '</div>'
            . '</div>';
    }
    return $html;
}

/** Search-suggestion rows: the 44px thumb, name and meta, price on the right. */
function skeleton_search_rows(int $count = 4): string
{
    $html = '<div class="sik-suggest__section sik-skel sik-skel-suggest" aria-hidden="true" data-skel="suggest">'
        . '<div class="sik-suggest__head"><span class="sik-suggest__label">' . skeleton_line('5.5em') . '</span></div>';
    for ($i = 0; $i < max(1, $count); $i++) {
        $html .= '<div class="sik-suggest__item">'
            . '<span class="sik-suggest__thumb sik-skel__well"></span>'
            . '<span class="sik-suggest__body">'
            .   '<span class="sik-suggest__name">' . skeleton_line($i % 2 ? '64%' : '82%') . '</span>'
            .   '<span class="sik-suggest__meta">' . skeleton_line('40%') . '</span>'
            . '</span>'
            . '<span class="sik-suggest__side"><span class="sik-suggest__price">' . skeleton_line('3.4em') . '</span></span>'
            . '</div>';
    }
    return $html . '</div>';
}

/**
 * <TableSkeleton /> - rows of bones in a real table, for any list that is
 * fetched after the page (account tables, admin lists fed by AJAX).
 */
function skeleton_table(int $rows = 5, int $cols = 4, string $class = ''): string
{
    $html = '<table class="sik-skel sik-skel-table' . ($class !== '' ? ' ' . e_attr($class) : '') . '" aria-hidden="true"><tbody>';
    $widths = ['72%', '48%', '60%', '36%', '54%', '40%'];
    for ($r = 0; $r < max(1, $rows); $r++) {
        $html .= '<tr>';
        for ($c = 0; $c < max(1, $cols); $c++) {
            $html .= '<td>' . skeleton_line($widths[($r + $c) % count($widths)]) . '</td>';
        }
        $html .= '</tr>';
    }
    return $html . '</tbody></table>';
}

/**
 * Notification-centre rows, for the "Load older notifications" append
 * (account.js). These are <li>, because the feed is a <ul>, and they borrow
 * .sik-feeditem / .sik-notif / .sik-notif__icon so the gap, the padding and
 * the three text line-heights are the real row's - an appended page then
 * lands exactly where its skeletons stood.
 *
 * The 38px avatar is the real .sik-notif__icon with .sik-skel__well over it,
 * not .sik-skel__disc: the well only repaints the fill, so the circle keeps
 * the row's own size instead of the bone's 34px.
 *
 * The two row controls are drawn as well, because they are not decoration
 * here: under 480px .sik-feeditem wraps and the tools drop below the row,
 * adding their 44px target to its height. A skeleton without them measured
 * 79px against a real row's 132px on a phone, so an appended page shoved the
 * footer down by ~52px per row - exactly the shift this system exists to
 * prevent.
 */
function skeleton_feed_rows(int $count = 3): string
{
    $tools = '<span class="sik-feeditem__tools">'
        . '<span class="sik-feeditem__btn sik-skel-feedrow__tool"></span>'
        . '<span class="sik-feeditem__btn sik-skel-feedrow__tool"></span>'
        . '</span>';

    $html = '';
    for ($i = 0; $i < max(1, $count); $i++) {
        $html .= '<li class="sik-feeditem sik-skel sik-skel-feedrow" aria-hidden="true" data-skel="feedrow">'
            . '<div class="sik-notif">'
            .   '<span class="sik-notif__icon sik-skel__well"></span>'
            .   '<span class="sik-notif__body">'
            .     '<span class="sik-notif__title">' . skeleton_line($i % 2 ? '54%' : '68%') . '</span>'
            .     '<span class="sik-notif__text">' . skeleton_line($i % 2 ? '76%' : '92%') . '</span>'
            .     '<span class="sik-notif__meta">' . skeleton_line('5em') . skeleton_line('4em') . '</span>'
            .   '</span>'
            . '</div>'
            . $tools
            . '</li>';
    }
    return $html;
}

/** Generic list rows (a notification, an order): a disc and two lines. */
function skeleton_rows(int $count = 3): string
{
    $html = '';
    for ($i = 0; $i < max(1, $count); $i++) {
        $html .= '<div class="sik-skel sik-skel-row" aria-hidden="true" data-skel="row">'
            . skeleton_bone('sik-skel__disc')
            . '<div class="sik-skel-row__body"><div class="sik-skel-row__title">' . skeleton_line($i % 2 ? '52%' : '68%')
            . '</div><div class="sik-skel-row__text">' . skeleton_line('88%') . '</div></div>'
            . '</div>';
    }
    return $html;
}

// ===========================================================================
//  LAZY WIDGET SHELLS
// ===========================================================================

/**
 * The skeleton for a deferred homepage-builder widget, sized from the widget's
 * own settings so the swap does not move the page: same section padding, a
 * heading only when the widget has a title, the same columns and card style.
 *
 * Returns ['html' => markup, 'reserve' => bool].
 *
 *   reserve = true   the widget reliably has content (a product row, the
 *                    category circles, the blog), so the shell renders the
 *                    skeleton straight away and holds the space from the
 *                    first paint.
 *   reserve = false  recently viewed: empty for every first visit, and a
 *                    reserved band that collapses is a layout shift of its
 *                    own. The markup travels in a <template>, and the script
 *                    only uses it while the shell is still below the fold,
 *                    where a collapse moves nothing anyone can see.
 *   html = ''        a widget whose height is not predictable (a flash-sale
 *                    panel, testimonials, raw HTML): no skeleton at all, so
 *                    nothing claims a shape the content will not have.
 */
function skeleton_widget(array $widget): array
{
    $type = (string) $widget['widget_type'];
    $limit = max(1, (int) ($widget['item_limit'] ?? 4));
    $reserve = true;

    switch ($type) {
        case 'product_grid':
        case 'product_carousel':
        case 'recommendations':
            $cardOpts = ['style' => (string) ($widget['card_style'] ?? 'standard')];
            $isRail = $type !== 'product_grid' || ($widget['layout'] ?? '') === 'carousel';
            $body = $isRail
                ? skeleton_product_rail(min($limit, 6), widget_cols_style($widget, 'rail'), $cardOpts)
                : skeleton_product_grid(min($limit, 8), widget_cols_style($widget), $cardOpts);
            break;
        case 'recently_viewed':
            // widget_recently_viewed() always renders compact cards in a rail.
            $body = skeleton_product_rail(min($limit, 6), widget_cols_style($widget, 'rail'), ['style' => 'compact']);
            $reserve = false;
            break;
        case 'category_grid':
            $body = skeleton_category_grid(min($limit + 1, 8), widget_cols_style($widget));
            break;
        case 'blog_grid':
            $body = skeleton_blog_grid(min($limit, 4), widget_cols_style($widget));
            break;
        case 'hero':
            return ['html' => '<div class="sik-section sik-section--none" aria-hidden="true">' . skeleton_hero() . '</div>', 'reserve' => true];
        default:
            return ['html' => '', 'reserve' => false];
    }

    $classes = ['sik-section', 'sik-skel-section'];
    if (($widget['padding'] ?? '') === 'none') $classes[] = 'sik-section--none';
    if (($widget['padding'] ?? '') === 'sm')   $classes[] = 'sik-section--sm';
    if (($widget['padding'] ?? '') === 'lg')   $classes[] = 'sik-section--lg';

    $heading = '';
    if (!empty($widget['title']) || !empty($widget['title_accent'])) {
        $hasLink = !empty($widget['link_text']) && !empty($widget['link_url']);
        $heading = '<div class="sik-heading' . ($hasLink ? ' sik-heading--row' : '') . ' sik-skel">'
            . '<div><div class="sik-heading__title">' . skeleton_line('min(16em, 70%)') . '</div>'
            . (!empty($widget['subtitle']) ? '<p class="sik-heading__sub">' . skeleton_line('min(24em, 90%)') . '</p>' : '')
            . '</div>'
            . ($hasLink ? '<span class="sik-viewall">' . skeleton_line('5em') . '</span>' : '')
            . '</div>';
    }

    return [
        'html'    => '<div class="' . e_attr(implode(' ', $classes)) . '" aria-hidden="true">'
            . '<div class="sik-container">' . $heading . $body . '</div></div>',
        'reserve' => $reserve,
    ];
}

// ===========================================================================
//  <template> ELEMENTS FOR THE SCRIPTS
// ===========================================================================

/**
 * Which of the cloned skeletons the page being rendered can actually use.
 *
 * Three of them are on every storefront page, because the surfaces that clone
 * them are: the search dialog and the mini-cart drawer are both printed by
 * footer.php unconditionally, and either can fail. The other five need
 * something the page has to have rendered first:
 *
 *   card, card-row  a [data-product-grid] the scripts refetch or append to
 *   detail          a Quick View button (products.js fills the modal with it)
 *   feedrow         the notification feed's "Load older notifications"
 *   row             the track-order result panel
 *
 * Printing all eight everywhere put 11.6 KB of inert markup - 8.8% of the
 * HTML, uncompressed on this server - on pages whose scripts can clone none of
 * them: faq.php, contact.php, the policy pages, the sign-in pages, a blog post.
 *
 * The lists below are measured rather than guessed. scratchpad/skelprobe2.php
 * fetches every storefront page - the parameterised ones with real slugs, the
 * account ones signed in with a cart, a wishlist and a comparison - and records
 * which of those markers each page renders. A page NOT named here gets the full
 * set, so a page added later is never worse off than it was before this gate
 * existed, and a missing template is a soft failure in any case: SIK.skeleton
 * .make() falls back to a plain bone.
 *
 * @return string[] template names, in the order they are printed
 */
function skeleton_page_templates(string $script): array
{
    // The search dialog, the cart drawer, and the block either falls back to.
    $base = ['suggest', 'cartline', 'error'];

    // A grid the scripts refetch (filters, sort) or append to (Show more).
    // Quick View travels with it: the cards the API sends back carry the
    // button even where the server-rendered first page does not.
    $catalogue = ['index.php', 'shop.php', 'category.php', 'brand.php', 'search.php',
        'deals.php', 'new-arrivals.php', 'best-sellers.php', '404.php'];

    // Product cards, but no grid anything refetches: Quick View only.
    $cards = ['product.php'];

    // Pages that fetch one particular kind of row after the page has loaded.
    $fetches = [
        'notifications.php' => ['feedrow'],
        'track-order.php'   => ['row'],
        'order-success.php' => ['row'],
    ];

    // Measured to render no product card, no feed and no track panel.
    $lean = ['about.php', 'addresses.php', 'account.php', 'blog.php', 'blog-post.php',
        'brands.php', 'cart.php', 'change-password.php', 'checkout.php', 'combo.php',
        'combos.php', 'compare.php', 'contact.php', 'faq.php', 'forgot-password.php',
        'login.php', 'my-reviews.php', 'newsletter-unsubscribe.php', 'order-details.php',
        'orders.php', 'page.php', 'preferences.php', 'privacy-policy.php', 'profile.php',
        'purchase-history.php', 'refund-policy.php', 'register.php', 'reset-password.php',
        'return-policy.php', 'shipping-policy.php', 'terms.php', 'verify-email.php',
        'warranty.php', 'wishlist.php', '403.php', '500.php'];

    if (in_array($script, $catalogue, true)) {
        return array_merge(['card', 'card-row', 'detail'], $base);
    }
    if (in_array($script, $cards, true)) {
        return array_merge(['detail'], $base);
    }
    if (isset($fetches[$script])) {
        return array_merge($fetches[$script], $base);
    }
    if (in_array($script, $lean, true)) {
        return $base;
    }
    return skeleton_template_names();
}

/** Every template this file can print, in their printing order. */
function skeleton_template_names(): array
{
    return ['card', 'card-row', 'detail', 'suggest', 'cartline', 'row', 'feedrow', 'error'];
}

/**
 * Print the skeletons the storefront scripts clone. Printed once, near the end
 * of the page, as inert <template>s: they cost nothing at render time, and they
 * keep the markup in one place (here) rather than as a second copy in
 * JavaScript strings.
 *
 * @param string[]|null $only the names to print (see skeleton_page_templates());
 *                            null prints every one of them.
 */
function skeleton_templates(?array $only = null): void
{
    static $printed = false;
    if ($printed) {
        return;
    }
    $printed = true;

    $templates = [
        'card'     => static fn (): string => skeleton_product_card(),
        'card-row' => static fn (): string => skeleton_product_card(['style' => 'horizontal']),
        'detail'   => static fn (): string => skeleton_product_detail(),
        'suggest'  => static fn (): string => skeleton_search_rows(4),
        'cartline' => static fn (): string => skeleton_cart_lines(1),
        'row'      => static fn (): string => skeleton_rows(1),
        'feedrow'  => static fn (): string => skeleton_feed_rows(1),
        'error'    => static fn (): string => skeleton_load_error(),
    ];

    // Closures rather than strings, so a page that needs two of them does not
    // pay to build the other six.
    foreach ($templates as $name => $build) {
        if ($only !== null && !in_array($name, $only, true)) {
            continue;
        }
        echo '<template id="sikSkel-' . e_attr($name) . '">' . $build() . '</template>', "\n";
    }
}
