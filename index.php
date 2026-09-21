<?php
/**
 * ShopInnKart - Homepage
 *
 * Renders nothing of its own: every section comes from the widget rows in
 * `homepage_sections` (zone = 'home'), managed in Admin > Content > Homepage.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_once INCLUDES_PATH . '/widgets.php';

seo_from_page('home');
seo_set([
    'og_type'   => 'website',
    'canonical' => url(),
]);

// The WebSite node carries the sitelinks search box and is emitted here only:
// repeating it on every page adds nothing to a crawl. LocalBusiness returns
// null until an operator has configured a real address and phone, so a fresh
// install declares no physical location rather than an empty one.
seo_add_schema(seo_website_schema());
if ($localBusiness = seo_local_business_schema()) {
    seo_add_schema($localBusiness);
}

require INCLUDES_PATH . '/header.php';

$widgets = zone_widgets('home');

if ($widgets === []):
    // Nothing configured yet - point the store owner at the builder rather
    // than showing an empty page.
    ?>
    <div class="sik-container">
        <div class="sik-empty" style="padding-block:var(--section-y-lg)">
            <?= icon('grid', 'w-16 h-16') ?>
            <h1 class="sik-empty__title">Your homepage has no sections yet</h1>
            <p class="sik-empty__text">Add hero banners, product rows and promotions from the admin homepage builder.</p>
            <a class="sik-btn sik-btn--primary" href="<?= e(admin_url('homepage/')) ?>">Open Homepage Builder</a>
        </div>
    </div>
    <?php
else:
    foreach ($widgets as $widget) {
        echo render_widget($widget);
    }
endif;

require INCLUDES_PATH . '/footer.php';
