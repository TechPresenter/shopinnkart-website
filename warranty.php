<?php
/**
 * ShopInnKart - Warranty Policy.
 * Body copy lives in the `pages` row with slug warranty-policy.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_once INCLUDES_PATH . '/content-functions.php';

cms_page_render('warranty-policy', [
    'title'    => 'Warranty Policy',
    'subtitle' => 'Manufacturer cover, what voids it, and how to raise a claim through us.',
]);
