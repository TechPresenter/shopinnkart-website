<?php
/**
 * ShopInnKart - Privacy Policy.
 * Body copy lives in the `pages` row with slug privacy-policy.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_once INCLUDES_PATH . '/content-functions.php';

cms_page_render('privacy-policy', [
    'title'    => 'Privacy Policy',
    'subtitle' => 'What we collect, why we collect it, and the control you have over your data.',
]);
