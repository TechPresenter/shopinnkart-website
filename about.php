<?php
/**
 * ShopInnKart - About Us.
 * Body copy lives in the `pages` row with slug about-us.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_once INCLUDES_PATH . '/content-functions.php';

cms_page_render('about-us', [
    'title'    => 'About Us',
    'subtitle' => 'The people, the promise and the process behind every order we ship.',
]);
