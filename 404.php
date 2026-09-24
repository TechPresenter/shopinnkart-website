<?php
/**
 * ShopInnKart - 404 Not Found
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_once INCLUDES_PATH . '/widgets.php';

// The hidden admin login address is deliberately not a file, so it lands here.
// Exits with a redirect to the login screen when the path matches.
admin_gate_try_enter();

// A moved URL lands here, because routing has already failed to match a file, a
// rewrite or a record. Checking admin-managed redirects at this boundary keeps
// the lookup off every successful request while still catching every stale
// link. It exits with a 301/302 when one matches.
seo_apply_redirect();

// Nothing matched, so this really is a dead URL. Record what was asked for and
// where the link was, so Admin > SEO > Broken links can show the operator what
// is actually breaking rather than waiting for a customer to report it. Path
// and referrer only, both with their query strings stripped - see
// seo_log_404() for why there is no IP or user agent here. It swallows every
// failure: a page that is already an error must not become a 500.
seo_log_404();

http_response_code(404);

seo_set([
    'title'  => 'Page Not Found',
    'robots' => 'noindex, follow',
    // No canonical. The URL being asked for does not exist, so declaring it
    // the canonical version of this page is a claim about a page that is not
    // there - and it echoed whatever path a bot invented back into our head.
    'canonical' => '',
]);

require INCLUDES_PATH . '/header.php';

$popular = get_products_for_source('best', 4);
?>
<div class="sik-container">
    <div class="sik-empty" style="padding-block:var(--section-y-lg) var(--section-y)">
        <img src="<?= e(asset('images/placeholders/empty-search.svg')) ?>" alt="" width="200" height="150">
        <p style="font-size:64px;font-weight:800;color:var(--sik-primary);line-height:1;margin-bottom:var(--sp-2)">404</p>
        <h1 class="sik-empty__title">We couldn&rsquo;t find that page</h1>
        <p class="sik-empty__text">
            The link may be broken, or the page may have moved. Let&rsquo;s get you back to shopping.
        </p>
        <div style="display:flex;gap:var(--sp-3);justify-content:center;flex-wrap:wrap">
            <a class="sik-btn sik-btn--primary" href="<?= e(url()) ?>"><?= icon('home', 'w-4 h-4') ?> Back to Home</a>
            <a class="sik-btn sik-btn--outline" href="<?= e(url('shop.php')) ?>">Browse All Products</a>
        </div>

        <div style="max-width:460px;margin:var(--sp-6) auto 0">
            <?php search_bar([
                'variant'     => 'page',
                'id'          => 'notFoundSearch',
                'value'       => '',
                'placeholder' => 'Search for a product…',
            ]); ?>
        </div>
    </div>

    <?php if ($popular !== []): ?>
        <section class="sik-section">
            <div class="sik-heading">
                <h2 class="sik-heading__title">POPULAR <span class="sik-heading__accent">RIGHT NOW</span></h2>
            </div>
            <?= product_grid($popular) ?>
        </section>
    <?php endif; ?>
</div>
<?php require INCLUDES_PATH . '/footer.php'; ?>
