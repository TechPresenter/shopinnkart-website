<?php
/**
 * ShopInnKart - Terms and Conditions.
 * Body copy lives in the `pages` row with slug terms-conditions.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_once INCLUDES_PATH . '/content-functions.php';

cms_page_render('terms-conditions', [
    'title'    => 'Terms and Conditions',
    'subtitle' => 'The rules that govern your use of this store and every order placed on it.',
]);
