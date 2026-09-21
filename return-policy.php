<?php
/**
 * ShopInnKart - Return Policy.
 * Body copy lives in the `pages` row with slug return-policy.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_once INCLUDES_PATH . '/content-functions.php';

cms_page_render('return-policy', [
    'title'    => 'Return Policy',
    'subtitle' => 'What can be returned, how long you have, and how to book a pickup.',
]);
