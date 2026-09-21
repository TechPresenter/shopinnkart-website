<?php
/**
 * ShopInnKart - build one importable .sql file for a live host.
 *
 * Run:  php database/export-for-hosting.php
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
 * traffic.
 *
 * The file contains admin password hashes and any customer rows, so it is in
 * .gitignore and must never be committed or emailed.
 */

declare(strict_types=1);

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
];

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
-- Contains admin password hashes and customer rows. Do not commit or email it.

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

fwrite($dump, "SET FOREIGN_KEY_CHECKS = 1;\n");
fclose($dump);

$path = __DIR__ . '/shopinnkart-import.sql';
printf("written: %s\n", $path);
printf("  tables:      %d\n", count($tables));
printf("  rows:        %s\n", number_format($rowsWritten));
printf("  size:        %s MB\n", number_format(filesize($path) / 1048576, 2));
printf("  data skipped: %s\n", $skipped === [] ? 'none' : implode(', ', $skipped));
