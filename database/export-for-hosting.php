<?php
/**
 * ShopInnKart - build one importable .sql file for a live host.
 *
 * Run:  php database/export-for-hosting.php                    (fresh launch)
 *       php database/export-for-hosting.php --with-customers   (move a running store)
 * Out:  database/shopinnkart-import.sql
 *
 * Why this exists rather than "just use schema.sql": schema.sql opens with
 * CREATE DATABASE and USE, so it ignores whichever database you select and
 * writes to `shopinnkart` instead - on a shared host your database is called
 * something like u123456_shop, and the import either fails or silently targets
 * the wrong schema. This file contains no CREATE DATABASE and no USE, so it
 * imports into whatever database is selected in phpMyAdmin.
 *
 * It also skips the local runtime tables. Structure for every table is always
 * written; only the ROWS of the throwaway ones are left out, so the live site
 * starts with a clean cart and an empty log instead of this machine's test
 * traffic. A fresh-launch dump then zeroes the counters those skipped rows fed
 * (see DERIVED_COUNTERS), so the catalogue does not arrive claiming sales,
 * ratings and coupon redemptions that no imported row supports.
 *
 * The file contains admin password hashes and any customer rows, so it is in
 * .gitignore and must never be committed or emailed.
 */

declare(strict_types=1);

// Command line only. This script writes every admin password hash (and, with
// --with-customers, every customer row) into a file inside the document root.
// database/.htaccess and the root .htaccess both refuse to serve it, but those
// are the host's promise, not ours: a host with AllowOverride off would have
// let anyone trigger the dump by loading the URL.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/init.php';

/**
 * Tables whose DATA is local noise. Structure is still exported for each.
 *
 * Carts and their items are abandoned baskets from testing; the logs and the
 * queue are this machine's history. None of it means anything on a live site,
 * and carts alone is thousands of rows.
 */
const RUNTIME_TABLES = [
    'carts',
    'cart_items',
    'activity_logs',
    'error_logs',
    'login_history',
    'notification_queue',
    'password_resets',
    'email_verifications',
    'rate_limits',
    'shipping_api_logs',
    'security_events',
    'security_alerts',
    'seo_404_log',

    // These three are not merely noise - carrying them across is a way in.
    // api_tokens holds tokens issued to THIS machine, auth_trusted_devices
    // holds the browsers allowed to skip the second sign-in step, and
    // an_salts is what makes a visitor id unguessable. Exporting them would
    // hand a laptop's credentials to the live site, so they never travel.
    'api_tokens',
    'auth_trusted_devices',
    'an_salts',

    // Analytics collected while clicking around a development machine.
    // Shipping it would open the live dashboard on invented traffic.
    'an_events',
    'an_pageviews',
    'an_sessions',
    'an_visitors',
    'an_paths',
    'an_sources',
    'an_vitals',
    'an_state',
    'an_daily_traffic',
    'an_daily_page',
    'an_daily_product',
    'an_daily_dim',
    'an_daily_vitals',
];

/**
 * Tables whose DATA is this machine's customers and orders. For a first launch
 * those are test accounts, test orders and test payments: shipping them would put
 * fake orders in the live dashboard, fake revenue in the reports and test email
 * addresses on the newsletter list. Left out unless --with-customers is passed,
 * which is for moving a store that is already trading.
 */
const CUSTOMER_TABLES = [
    'users', 'user_addresses', 'user_notifications', 'user_preferences',
    'wishlists', 'wishlist_items', 'compare_items', 'recently_viewed', 'search_logs',
    'orders', 'order_items', 'order_status_history', 'payments', 'payment_transactions',
    'invoices', 'return_requests', 'reviews', 'review_images', 'coupon_usage',
    'stock_movements', 'stock_alerts', 'contact_messages', 'newsletter_subscribers',
    'shipments', 'shipment_events',
];
$withCustomers = in_array('--with-customers', $argv ?? [], true);

$dbName = (string) Database::fetchColumn('SELECT DATABASE()');
$tables = array_map(
    static fn (array $row): string => (string) current($row),
    Database::fetchAll('SHOW TABLES')
);
sort($tables);

$dump   = fopen(__DIR__ . '/shopinnkart-import.sql', 'wb');
$stamp  = date('Y-m-d H:i');
$header = <<<SQL
-- ShopInnKart - full import for a live host
-- Generated {$stamp} from `{$dbName}`
--
-- HOW TO USE (Hostinger / cPanel):
--   1. Create a database and a database user, and attach the user to it.
--   2. Open phpMyAdmin and SELECT THAT DATABASE in the left-hand list.
--   3. Import tab -> choose this file -> Go.
--   4. Put the same credentials into config/db.local.php on the server.
--
-- There is deliberately no CREATE DATABASE and no USE in this file, so it
-- imports into whichever database you selected in step 2.
--
-- Contains admin password hashes (and customer rows with --with-customers).
-- Do not commit or email it. After importing, change the admin password at once:
-- the seeded one is published in the README.

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';
SET NAMES utf8mb4;


SQL;
fwrite($dump, $header);

$rowsWritten = 0;
$skipped     = [];

foreach ($tables as $table) {
    // --- structure --------------------------------------------------------
    $create = Database::fetch('SHOW CREATE TABLE `' . $table . '`');
    $ddl    = (string) ($create['Create Table'] ?? $create['Create View'] ?? '');
    if ($ddl === '') {
        continue;
    }

    fwrite($dump, "--\n-- Table: {$table}\n--\nDROP TABLE IF EXISTS `{$table}`;\n{$ddl};\n\n");

    if (in_array($table, RUNTIME_TABLES, true)) {
        $skipped[] = $table;
        fwrite($dump, "-- rows skipped: local runtime data, not meaningful on a live site\n\n");
        continue;
    }
    if (!$withCustomers && in_array($table, CUSTOMER_TABLES, true)) {
        $skipped[] = $table;
        fwrite($dump, "-- rows skipped: local test customers/orders (re-run with --with-customers to keep them)\n\n");
        continue;
    }

    // --- rows -------------------------------------------------------------
    $count = (int) Database::fetchColumn('SELECT COUNT(*) FROM `' . $table . '`');
    if ($count === 0) {
        continue;
    }

    // Chunked so a large table cannot exhaust memory, and batched into
    // multi-row INSERTs so the import is not thousands of round trips.
    $pdo    = Database::connect();
    $offset = 0;
    $chunk  = 200;

    while ($offset < $count) {
        $rows = Database::fetchAll('SELECT * FROM `' . $table . '` LIMIT ' . $chunk . ' OFFSET ' . $offset);
        if ($rows === []) {
            break;
        }

        $columns = '`' . implode('`, `', array_keys($rows[0])) . '`';
        $values  = [];

        foreach ($rows as $row) {
            $cells = [];
            foreach ($row as $value) {
                if ($value === null) {
                    $cells[] = 'NULL';
                } elseif (is_int($value) || is_float($value)) {
                    $cells[] = (string) $value;
                } else {
                    // quote() handles the escaping and the surrounding quotes,
                    // for the connection's own charset.
                    $cells[] = $pdo->quote((string) $value);
                }
            }
            $values[] = '(' . implode(', ', $cells) . ')';
        }

        fwrite($dump, "INSERT INTO `{$table}` ({$columns}) VALUES\n" . implode(",\n", $values) . ";\n");
        $rowsWritten += count($rows);
        $offset += $chunk;
    }

    fwrite($dump, "\n");
}

// The mock courier fakes bookings and AWBs for local testing. It must never be
// switched on for a real store, whatever state it was left in on this machine.
if (in_array('shipping_providers', $tables, true)) {
    fwrite($dump, "UPDATE `shipping_providers` SET `status` = 'inactive', `webhook_enabled` = 0 WHERE `code` = 'mock';\n\n");
}

/**
 * Counters the skipped rows produced, zeroed when those rows are skipped.
 *
 * A fresh-launch dump keeps the catalogue and leaves out the orders, reviews,
 * coupon redemptions and stock movements behind it. The counters are cached
 * totals OF those rows, so keeping them opens the live store with this
 * machine's figures and nothing to back them: "best seller" ordering and star
 * ratings with no orders and no reviews, and - the one that costs money -
 * coupon allocation already burnt (a 1500-use code imported at 1487 refuses
 * itself after 13 redemptions, because validate_coupon() reads used_count).
 *
 * Reset here rather than by not exporting the row, because the product, coupon
 * and deal rows themselves are exactly what a new store wants. With
 * --with-customers the rows that justify the counters travel too, so the
 * counters stay as they are.
 */
const DERIVED_COUNTERS = [
    'products'            => ['sold_count', 'rating_avg', 'rating_count'],
    'combos'              => ['sold_count'],
    'coupons'             => ['used_count'],
    'deals'               => ['stock_sold'],
    'flash_sale_products' => ['stock_sold'],
];
$resetCounters = [];
if (!$withCustomers) {
    foreach (DERIVED_COUNTERS as $table => $counters) {
        if (!in_array($table, $tables, true)) {
            continue;
        }
        // Only the columns this database actually has: an older schema that
        // never grew one must not make the import stop on a missing column.
        $present = array_map(
            static fn (array $row): string => (string) $row['Field'],
            Database::fetchAll('SHOW COLUMNS FROM `' . $table . '`')
        );
        $sets = [];
        foreach ($counters as $column) {
            if (in_array($column, $present, true)) {
                $sets[] = '`' . $column . '` = 0';
            }
        }
        if ($sets === []) {
            continue;
        }
        fwrite($dump, "-- derived from the skipped orders/reviews/redemptions, so reset with them\n"
            . 'UPDATE `' . $table . '` SET ' . implode(', ', $sets) . ";\n\n");
        $resetCounters[] = $table . '.' . implode('/', array_map(
            static fn (string $s): string => trim(explode('=', $s)[0], '` '),
            $sets
        ));
    }
}

fwrite($dump, "SET FOREIGN_KEY_CHECKS = 1;\n");
fclose($dump);

$path = __DIR__ . '/shopinnkart-import.sql';
printf("written: %s\n", $path);
printf("  tables:      %d\n", count($tables));
printf("  rows:        %s\n", number_format($rowsWritten));
printf("  size:        %s MB\n", number_format(filesize($path) / 1048576, 2));
printf("  data skipped: %s\n", $skipped === [] ? 'none' : implode(', ', $skipped));
printf("  counters reset: %s\n", $resetCounters === [] ? 'none (--with-customers keeps them)' : implode(', ', $resetCounters));
