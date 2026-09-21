<?php
/**
 * ShopInnKart - Refund Policy.
 * Body copy lives in the `pages` row with slug refund-policy.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_once INCLUDES_PATH . '/content-functions.php';

cms_page_render('refund-policy', [
    'title'    => 'Refund Policy',
    'subtitle' => 'When a refund is issued, how it is paid back, and how long each method takes.',
]);
