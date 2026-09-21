<?php
/**
 * ShopInnKart - Shipping Policy.
 * Body copy lives in the `pages` row with slug shipping-policy.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_once INCLUDES_PATH . '/content-functions.php';

cms_page_render('shipping-policy', [
    'title'    => 'Shipping Policy',
    'subtitle' => 'Dispatch times, delivery partners, charges, and what happens if a parcel goes astray.',
]);
