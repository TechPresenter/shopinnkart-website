<?php
/**
 * ShopInnKart - Migration: the first-party analytics tables (phase B2).
 *
 * B1 shipped the consent layer and the settings; this is the storage the
 * collector writes to. Nothing here holds an IP address, an email, a name or
 * any free text a shopper typed - that is not an accident of the schema, it is
 * the schema:
 *
 *   an_sessions.vkey      a 16-byte hash of (today's random salt, the
 *                         ANONYMISED ip, the UA, our host). Tomorrow's salt is
 *                         different and yesterday's is deleted, so the same
 *                         person cannot be followed from one day to the next -
 *                         by us or by anyone who takes a copy of this table.
 *   an_paths.path         a URL with its query string reduced to an allowlist
 *                         (utm_*, page, and q on search only). A password
 *                         reset token or an order id in a URL is dropped
 *                         before it is ever hashed, let alone stored.
 *   an_events.label       a coupon code or a payment method, never a search
 *                         term or a message. Search terms stay in search_logs,
 *                         which has its own retention.
 *
 * WHY RAW AND ROLLUP TABLES BOTH
 * ------------------------------
 * The raw rows answer "what is happening right now" and are deleted after the
 * retention window (60 days by default). The an_daily_* rollups answer
 * everything a dashboard asks and are kept forever, because a dashboard read
 * that scans raw rows measured 925 ms for a single day at the upper bound -
 * on a shared host that is a page nobody opens twice. B3 writes the rollups;
 * they are created here so that one migration owns the whole schema and B3 is
 * pure code.
 *
 * WHAT THIS MIGRATION DOES NOT CREATE
 * -----------------------------------
 *   - customer_stats, which is a commerce summary and belongs to phase A10.
 *   - the analytics.* permissions and the analytics_* settings that B1's
 *     migration already installed; re-running that one is a no-op.
 *
 * Idempotent. Never DROPs. Creating a table it already finds is skipped
 * whole, so a second run reports "nothing to do".
 *
 * Run from the project root:
 *     php database/migrations/2026_09_24_analytics_tables.php
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/init.php';
require_once __DIR__ . '/2026_08_13_invoice_email_system.php';

/**
 * @return array{applied:string[], errors:string[]}
 */
function migration_analytics_tables_run(): array
{
    $applied = [];
    $errors  = [];

    $run = static function (string $label, callable $fn) use (&$applied, &$errors): void {
        try {
            $line = $fn();
            if ($line !== null && $line !== false) {
                $applied[] = is_string($line) ? $line : $label;
            }
        } catch (Throwable $e) {
            $errors[] = $label . ': ' . $e->getMessage();
        }
    };

    /**
     * table => DDL. Every one is created only when absent, so an owner who has
     * added a column by hand keeps it.
     */
    $tables = [

        // ===================================================================
        //  Raw. Deleted after analytics_retention_days (default 60).
        // ===================================================================

        // One row per visit. Everything a report groups by lives here, which
        // is why the rollup can read one table per day instead of joining
        // four: the session carries its own device, channel, geo and outcome.
        'an_sessions' => "CREATE TABLE `an_sessions` (
            `id`              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `vkey`            BINARY(16) NOT NULL COMMENT 'Daily-salted hash. Never reversible to an IP',
            `consented`       TINYINT(1) NOT NULL DEFAULT 0,
            `user_id`         INT(10) UNSIGNED NULL,
            `visitor_type`    TINYINT(3) UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 unknown 1 new 2 returning',
            `day`             DATE NOT NULL COMMENT 'Store-local date of started_at',
            `started_at`      DATETIME NOT NULL,
            `last_seen_at`    DATETIME NOT NULL,
            `pageviews`       SMALLINT(5) UNSIGNED NOT NULL DEFAULT 0,
            `engaged_ms`      INT(10) UNSIGNED NOT NULL DEFAULT 0,
            `is_engaged`      TINYINT(1) NOT NULL DEFAULT 0,
            `landing_path_id` INT(10) UNSIGNED NOT NULL DEFAULT 0,
            `current_path_id` INT(10) UNSIGNED NOT NULL DEFAULT 0,
            `channel`         TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
            `source_id`       SMALLINT(5) UNSIGNED NOT NULL DEFAULT 0,
            `medium`          VARCHAR(50) NULL,
            `campaign`        VARCHAR(100) NULL,
            `click_id`        TINYINT(3) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'WHICH click id was present, never its value',
            `country`         CHAR(2) NULL,
            `region`          VARCHAR(8) NULL,
            `city`            VARCHAR(80) NULL,
            `device`          TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
            `browser`         TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
            `os`              TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
            `funnel`          TINYINT(3) UNSIGNED NOT NULL DEFAULT 0 COMMENT '1 view 2 cart 4 checkout 8 purchase',
            `order_id`        INT(10) UNSIGNED NULL,
            `revenue`         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `flags`           TINYINT(3) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Bot heuristics, set at rollup time',
            PRIMARY KEY (`id`),
            KEY `ix_vkey_seen` (`vkey`, `last_seen_at`),
            KEY `ix_last_seen` (`last_seen_at`),
            KEY `ix_day` (`day`),
            KEY `ix_order` (`order_id`),
            KEY `ix_user` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // One row per page view. `nonce` comes from the signed page token, so
        // the UNIQUE key is what makes a replayed beacon a no-op rather than a
        // second visit, and what lets a heartbeat find its own page view
        // without the client naming a row id.
        'an_pageviews' => "CREATE TABLE `an_pageviews` (
            `id`         BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `nonce`      BINARY(8) NOT NULL COMMENT 'From the page token: one per rendered page',
            `seq`        TINYINT(3) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'bfcache re-show of the same page',
            `session_id` BIGINT(20) UNSIGNED NOT NULL,
            `created_at` DATETIME NOT NULL,
            `path_id`    INT(10) UNSIGNED NOT NULL,
            `page_type`  TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
            `entity_id`  INT(10) UNSIGNED NULL COMMENT 'Product / category / combo / post, from inside the signature',
            `engaged_ms` INT(10) UNSIGNED NOT NULL DEFAULT 0,
            `scroll_pct` TINYINT(3) UNSIGNED NULL,
            `hits`       TINYINT(3) UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Beacon budget spent by this page view',
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_nonce_seq` (`nonce`, `seq`),
            KEY `ix_created` (`created_at`),
            KEY `ix_session` (`session_id`),
            KEY `ix_entity` (`page_type`, `entity_id`, `created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // session_id NULL is deliberate and common: a shopper whose beacon was
        // blocked still adds to a cart, and that add still counts for the
        // product. An event is never allowed to invent a session, because a
        // session with no landing page or device would poison every rollup.
        'an_events' => "CREATE TABLE `an_events` (
            `id`         BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `session_id` BIGINT(20) UNSIGNED NULL COMMENT 'NULL = unattributed, still counted',
            `created_at` DATETIME NOT NULL,
            `name`       TINYINT(3) UNSIGNED NOT NULL COMMENT 'AN_EVENT_IDS in includes/analytics/classify.php',
            `product_id` INT(10) UNSIGNED NULL,
            `combo_id`   INT(10) UNSIGNED NULL,
            `order_id`   INT(10) UNSIGNED NULL,
            `qty`        SMALLINT(5) UNSIGNED NULL,
            `value`      DECIMAL(12,2) NULL,
            `label`      VARCHAR(100) NULL COMMENT 'Coupon code, payment method, outbound host. Never shopper text',
            PRIMARY KEY (`id`),
            KEY `ix_created` (`created_at`),
            KEY `ix_name_created` (`name`, `created_at`),
            KEY `ix_session` (`session_id`),
            KEY `ix_product` (`product_id`, `created_at`),
            KEY `ix_order` (`order_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // Core Web Vitals, one row per page view that reported any. Page type,
        // device and browser are copied in rather than joined: the report that
        // matters is "LCP on the product page on mobile", and Safari reports
        // none of these at all - which is why the browser is stored, so B5 can
        // print the sample count next to the percentile.
        'an_vitals' => "CREATE TABLE `an_vitals` (
            `id`         BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `created_at` DATETIME NOT NULL,
            `session_id` BIGINT(20) UNSIGNED NOT NULL,
            `page_type`  TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
            `device`     TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
            `browser`    TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
            `lcp_ms`     MEDIUMINT(8) UNSIGNED NULL,
            `inp_ms`     MEDIUMINT(8) UNSIGNED NULL,
            `cls_x1000`  SMALLINT(5) UNSIGNED NULL,
            `fcp_ms`     MEDIUMINT(8) UNSIGNED NULL,
            `ttfb_ms`    MEDIUMINT(8) UNSIGNED NULL,
            PRIMARY KEY (`id`),
            KEY `ix_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // ===================================================================
        //  Dictionaries and state. Small, kept.
        // ===================================================================

        // The URL is stored once and referenced by id, which is what keeps a
        // pageview row small. The LABEL ("Diya String Lights") is never stored:
        // it is resolved from entity_id at read time, so renaming a product
        // renames it in every historical report instead of leaving the old
        // name frozen in the table. hash is BINARY(8) because a 512-byte
        // VARCHAR cannot carry a UNIQUE index in utf8mb4.
        'an_paths' => "CREATE TABLE `an_paths` (
            `id`         INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `hash`       BINARY(8) NOT NULL,
            `path`       VARCHAR(512) NOT NULL COMMENT 'Query string reduced to the allowlist before it gets here',
            `page_type`  TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
            `entity_id`  INT(10) UNSIGNED NULL,
            `first_seen` DATE NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_hash` (`hash`),
            KEY `ix_type_entity` (`page_type`, `entity_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'an_sources' => "CREATE TABLE `an_sources` (
            `id`      SMALLINT(5) UNSIGNED NOT NULL AUTO_INCREMENT,
            `name`    VARCHAR(100) NOT NULL COMMENT 'Referrer host or utm_source, lower-cased',
            `channel` TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_name` (`name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // The ONLY table that holds a key surviving the day, and it exists
        // only for visitors who said yes. In the owner's anonymous mode it
        // stays empty, which is the honest reason new-vs-returning reads
        // "Unknown" on the screens B4 builds.
        'an_visitors' => "CREATE TABLE `an_visitors` (
            `vkey`       BINARY(16) NOT NULL,
            `first_seen` DATE NOT NULL,
            `last_seen`  DATE NOT NULL,
            `sessions`   INT(10) UNSIGNED NOT NULL DEFAULT 0,
            `user_id`    INT(10) UNSIGNED NULL,
            PRIMARY KEY (`vkey`),
            KEY `ix_last_seen` (`last_seen`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // Two rows, ever: today's and yesterday's. Deleting the old one is
        // what makes the anonymous visitor key un-linkable across days, so
        // this table being small is a privacy property, not tidiness.
        'an_salts' => "CREATE TABLE `an_salts` (
            `day`  DATE NOT NULL,
            `salt` BINARY(32) NOT NULL,
            PRIMARY KEY (`day`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // rolled_through, today_rolled_at, purge_through, and one rejects.*
        // counter per refusal reason. The counters are the only trace a
        // refused beacon leaves, because the endpoint answers 204 to
        // everything - so they are also how we would notice a real browser
        // being turned away by mistake.
        'an_state' => "CREATE TABLE `an_state` (
            `k`          VARCHAR(40) NOT NULL,
            `v`          VARCHAR(255) NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`k`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // ===================================================================
        //  Rollups. Written by B3, kept forever, read by every screen.
        // ===================================================================

        // The filterable cube. `visitors` is a DAILY-DISTINCT count and must
        // never be summed across days - two days of one visitor is one
        // visitor, and adding the rows says two. B4's reads use it for a day
        // and fall back to a raw distinct count for a range.
        'an_daily_traffic' => "CREATE TABLE `an_daily_traffic` (
            `day`               DATE NOT NULL,
            `channel`           TINYINT(3) UNSIGNED NOT NULL,
            `source_id`         SMALLINT(5) UNSIGNED NOT NULL,
            `device`            TINYINT(3) UNSIGNED NOT NULL,
            `country`           CHAR(2) NOT NULL DEFAULT '',
            `region`            VARCHAR(8) NOT NULL DEFAULT '',
            `visitor_type`      TINYINT(3) UNSIGNED NOT NULL,
            `sessions`          INT(10) UNSIGNED NOT NULL DEFAULT 0,
            `visitors`          INT(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Daily distinct. NOT additive across days',
            `new_visitors`      INT(10) UNSIGNED NOT NULL DEFAULT 0,
            `pageviews`         INT(10) UNSIGNED NOT NULL DEFAULT 0,
            `engaged_sessions`  INT(10) UNSIGNED NOT NULL DEFAULT 0,
            `engaged_ms`        BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            `view_sessions`     INT(10) UNSIGNED NOT NULL DEFAULT 0,
            `cart_sessions`     INT(10) UNSIGNED NOT NULL DEFAULT 0,
            `checkout_sessions` INT(10) UNSIGNED NOT NULL DEFAULT 0,
            `purchase_sessions` INT(10) UNSIGNED NOT NULL DEFAULT 0,
            `orders`            INT(10) UNSIGNED NOT NULL DEFAULT 0,
            `revenue`           DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            PRIMARY KEY (`day`, `channel`, `source_id`, `device`, `country`, `region`, `visitor_type`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // Everything with too many distinct values to belong in the cube:
        // 1 medium 2 campaign 3 referrer host 4 browser 5 os 6 city
        // 7 landing path 8 exit path 9 search term.
        'an_daily_dim' => "CREATE TABLE `an_daily_dim` (
            `dim`               TINYINT(3) UNSIGNED NOT NULL,
            `day`               DATE NOT NULL,
            `val`               VARCHAR(191) NOT NULL,
            `sessions`          INT(10) UNSIGNED NOT NULL DEFAULT 0,
            `pageviews`         INT(10) UNSIGNED NOT NULL DEFAULT 0,
            `engaged_sessions`  INT(10) UNSIGNED NOT NULL DEFAULT 0,
            `purchase_sessions` INT(10) UNSIGNED NOT NULL DEFAULT 0,
            `revenue`           DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            PRIMARY KEY (`dim`, `day`, `val`),
            KEY `ix_dim_day` (`dim`, `day`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'an_daily_page' => "CREATE TABLE `an_daily_page` (
            `day`           DATE NOT NULL,
            `path_id`       INT(10) UNSIGNED NOT NULL,
            `device`        TINYINT(3) UNSIGNED NOT NULL,
            `views`         INT(10) UNSIGNED NOT NULL DEFAULT 0,
            `view_sessions` INT(10) UNSIGNED NOT NULL DEFAULT 0,
            `entries`       INT(10) UNSIGNED NOT NULL DEFAULT 0,
            `exits`         INT(10) UNSIGNED NOT NULL DEFAULT 0,
            `bounces`       INT(10) UNSIGNED NOT NULL DEFAULT 0,
            `engaged_ms`    BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            `scroll_sum`    INT(10) UNSIGNED NOT NULL DEFAULT 0,
            `scroll_n`      INT(10) UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`day`, `path_id`, `device`),
            KEY `ix_path_day` (`path_id`, `day`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // units_sold and revenue come from `orders`, not from the browser, and
        // are re-derived for the trailing 45 days so a cancellation flows back
        // into last week's product report instead of leaving it overstated.
        'an_daily_product' => "CREATE TABLE `an_daily_product` (
            `day`           DATE NOT NULL,
            `product_id`    INT(10) UNSIGNED NOT NULL,
            `device`        TINYINT(3) UNSIGNED NOT NULL,
            `views`         INT(10) UNSIGNED NOT NULL DEFAULT 0,
            `view_sessions` INT(10) UNSIGNED NOT NULL DEFAULT 0,
            `cart_adds`     INT(10) UNSIGNED NOT NULL DEFAULT 0,
            `cart_qty`      INT(10) UNSIGNED NOT NULL DEFAULT 0,
            `wishlist_adds` INT(10) UNSIGNED NOT NULL DEFAULT 0,
            `units_sold`    INT(10) UNSIGNED NOT NULL DEFAULT 0,
            `revenue`       DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            PRIMARY KEY (`day`, `product_id`, `device`),
            KEY `ix_product_day` (`product_id`, `day`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // p75 is computed in PHP during the rollup: MariaDB 10.4 and MySQL 8
        // disagree about percentile functions, and a report that changes
        // meaning when the host upgrades is worse than a loop.
        'an_daily_vitals' => "CREATE TABLE `an_daily_vitals` (
            `day`       DATE NOT NULL,
            `metric`    TINYINT(3) UNSIGNED NOT NULL COMMENT 'AN_VITALS: 1 lcp 2 inp 3 cls 4 fcp 5 ttfb',
            `device`    TINYINT(3) UNSIGNED NOT NULL,
            `page_type` TINYINT(3) UNSIGNED NOT NULL,
            `samples`   INT(10) UNSIGNED NOT NULL DEFAULT 0,
            `good`      INT(10) UNSIGNED NOT NULL DEFAULT 0,
            `ni`        INT(10) UNSIGNED NOT NULL DEFAULT 0,
            `poor`      INT(10) UNSIGNED NOT NULL DEFAULT 0,
            `p75`       INT(10) UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`day`, `metric`, `device`, `page_type`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];

    foreach ($tables as $table => $ddl) {
        $run('+ table ' . $table, static function () use ($table, $ddl): ?string {
            if (mig_table_exists($table)) {
                return null;
            }
            Database::query($ddl);
            return '+ table ' . $table;
        });
    }

    // -----------------------------------------------------------------------
    //  Settings the collector reads
    // -----------------------------------------------------------------------
    // Both have working defaults in code, so the store runs without them; they
    // are created so that B3's settings screen has rows to bind to and so the
    // owner can find them without an UPDATE.
    $settings = [
        // The owner's own office, warehouse or VPN. CIDR or bare address,
        // comma or newline separated. Empty is the normal case.
        'analytics_exclude_ips'    => ['', 'analytics', 'textarea'],
        // Logs how long each beacon took. Off, and deliberately not switchable
        // from any request parameter: an endpoint whose log verbosity a
        // stranger can turn on is an endpoint that fills a disk.
        'analytics_debug_timing'   => ['0', 'analytics', 'boolean'],
        // Days of RAW rows. Rollups are kept forever. B3 builds the purge;
        // the value is the owner's (spec §17), 60 by default, 30-180 allowed.
        'analytics_retention_days' => ['60', 'analytics', 'number'],
    ];

    foreach ($settings as $key => [$value, $group, $type]) {
        $run('+ setting ' . $key, static function () use ($key, $value, $group, $type): ?string {
            if (Database::exists('settings', '`setting_key` = :k', ['k' => $key])) {
                return null;
            }
            setting_save($key, $value, $group, $type);
            return '+ setting ' . $key . ($value !== '' ? ' = ' . $value : '');
        });
    }

    return ['applied' => $applied, 'errors' => $errors];
}

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === realpath(__FILE__)) {
    $result = migration_analytics_tables_run();
    echo "Analytics migration (B2: tracking tables)\n";
    foreach ($result['applied'] as $line) {
        echo '  ' . $line . "\n";
    }
    foreach ($result['errors'] as $line) {
        echo '  ERROR ' . $line . "\n";
    }
    if ($result['applied'] === [] && $result['errors'] === []) {
        echo "  nothing to do\n";
    }
    exit($result['errors'] === [] ? 0 : 1);
}
