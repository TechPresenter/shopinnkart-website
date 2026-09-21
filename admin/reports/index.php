<?php
/**
 * ShopInnKart Admin - Reports entry point.
 * The reports folder has no list screen of its own; sales is the landing page.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

admin_require('reports.view');

$query = $_GET === [] ? '' : '?' . http_build_query($_GET);
redirect(admin_url('reports/sales.php') . $query);
