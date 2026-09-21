<?php
/**
 * ShopInnKart - Database credentials template.
 *
 * Copy to db.local.php and fill in. config/config.php picks it up automatically.
 * Environment variables (DB_HOST, DB_NAME, DB_USER, DB_PASS) still win over
 * whatever is set here, which is what you want on a managed host.
 *
 * db.local.php is git-ignored and blocked from the web by .htaccess.
 * The installer writes it for you.
 */

declare(strict_types=1);

return [
    'host' => 'localhost',
    'port' => '3306',
    'name' => 'shopinnkart',
    'user' => 'root',
    'pass' => '',
];
