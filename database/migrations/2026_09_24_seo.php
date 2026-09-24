<?php
/**
 * ShopInnKart - Migration: the per-entity SEO layer.
 *
 * WHAT IT ADDS AND WHY EACH PIECE EXISTS
 * --------------------------------------
 *   seo_entity_meta      The fields the shared SEO panel grew that no entity
 *                        table has a column for: keywords, the Twitter trio,
 *                        breadcrumb controls and the per-page custom code.
 *
 *                        A side table rather than eleven more columns on each
 *                        of six tables. Two reasons. First, `combos` would
 *                        otherwise need a different shape from the rest and the
 *                        "one panel for every entity" promise would quietly
 *                        become "one panel and an exception". Second, the code
 *                        columns are the dangerous ones: keeping them in a
 *                        single table means the "what is injected sitewide?"
 *                        inventory is one query, not a UNION over six tables
 *                        that somebody will forget to extend when a seventh
 *                        entity type appears.
 *
 *                        The nine ORIGINAL columns (meta_title, robots, ...)
 *                        deliberately stay where they are. seo_from_entity()
 *                        already reads them straight off the row the page
 *                        fetched, at no extra query cost, and moving them would
 *                        be a rewrite of six storefront templates for no gain.
 *
 *   combos.*             The six SEO columns `combos` was missing. Products,
 *                        categories, brands, pages and blog posts all carry the
 *                        same nine; combos carried three, which is why its form
 *                        hand-rolled a cut-down SEO card instead of using the
 *                        shared editor. Adding the six makes the sixth entity
 *                        identical to the other five.
 *
 *   seo_404_log          What visitors actually asked for and did not get.
 *                        Path, referrer, a counter and two timestamps - and
 *                        deliberately NO ip_address, user agent, user id or
 *                        session id. A 404 log is a list of broken links, not a
 *                        second analytics system, and the moment it holds an IP
 *                        it acquires a retention policy, a subject-access
 *                        obligation and a reason to be in the privacy notice.
 *                        One row per path (UNIQUE) so a crawler hammering the
 *                        same dead URL costs one row, not ten thousand.
 *
 * Idempotent. Never DROPs. Safe to run on a database that already has some of
 * this - each step checks first.
 *
 * Run from the project root:
 *     php database/migrations/2026_09_24_seo.php
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/init.php';

// mig_table_exists() / mig_column_exists() / mig_add_column() / mig_add_index()
// live in the first migration that needed them; every migration since has
// reused them rather than restating four information_schema queries.
require_once __DIR__ . '/2026_08_13_invoice_email_system.php';

/**
 * @return array{applied:string[], errors:string[]}
 */
function migration_seo_entity_run(): array
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

    // -----------------------------------------------------------------------
    //  1. The extended per-entity fields
    // -----------------------------------------------------------------------

    $run('+ table seo_entity_meta', static function (): ?string {
        if (mig_table_exists('seo_entity_meta')) {
            return null;
        }

        Database::query(
            "CREATE TABLE `seo_entity_meta` (
                `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
                `entity_type` VARCHAR(20) NOT NULL COMMENT 'product, category, brand, page, post, combo',
                `entity_id` INT(10) UNSIGNED NOT NULL,

                `meta_keywords` VARCHAR(500) NULL,

                `twitter_title` VARCHAR(255) NULL,
                `twitter_description` VARCHAR(500) NULL,
                `twitter_image` VARCHAR(255) NULL,

                `breadcrumb_label` VARCHAR(150) NULL
                    COMMENT 'Shorter label for the trail; the full name is still the page title',
                `breadcrumb_hide` TINYINT(1) NOT NULL DEFAULT 0
                    COMMENT 'Leave this record out of its own breadcrumb trail',

                `custom_head` MEDIUMTEXT NULL COMMENT 'Raw markup injected into <head>. settings.scripts only.',
                `custom_body` MEDIUMTEXT NULL COMMENT 'Raw markup injected after <body>. settings.scripts only.',
                `custom_css` MEDIUMTEXT NULL COMMENT 'Page-specific CSS. settings.scripts only.',
                `custom_js` MEDIUMTEXT NULL COMMENT 'Page-specific JS, emitted deferred. settings.scripts only.',
                `code_consent` VARCHAR(20) NOT NULL DEFAULT 'marketing'
                    COMMENT 'none | analytics | marketing - which consent category the code above waits for',
                `code_updated_by` INT(10) UNSIGNED NULL COMMENT 'admins.id of whoever last changed the code',
                `code_updated_at` DATETIME NULL,

                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_seo_entity` (`entity_type`, `entity_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        return '+ table seo_entity_meta';
    });

    // The inventory screen asks "every row that injects anything", which is a
    // scan without this. It stays cheap because almost every row has no code.
    $run('+ index seo_entity_meta.idx_seo_entity_code', static function (): ?string {
        return mig_add_index(
            'seo_entity_meta',
            'idx_seo_entity_code',
            'KEY `idx_seo_entity_code` (`code_updated_at`)'
        );
    });

    // -----------------------------------------------------------------------
    //  2. combos catches up with the other five entities
    // -----------------------------------------------------------------------
    // combos already had meta_title, meta_description and og_image. These six
    // are the rest of the set the shared editor posts; without them six of its
    // nine inputs had nowhere to land, which is exactly why the combo form was
    // carrying a hand-written three-field card instead.

    $comboColumns = [
        'focus_keyword'  => "VARCHAR(120) NULL AFTER `meta_description`",
        'canonical_url'  => "VARCHAR(255) NULL AFTER `focus_keyword`",
        'robots'         => "VARCHAR(60) NULL AFTER `canonical_url`",
        'og_title'       => "VARCHAR(255) NULL AFTER `robots`",
        'og_description' => "TEXT NULL AFTER `og_title`",
        'schema_json'    => "TEXT NULL COMMENT 'Custom JSON-LD, validated on save' AFTER `og_image`",
    ];

    foreach ($comboColumns as $column => $definition) {
        $run('+ combos.' . $column, static function () use ($column, $definition): ?string {
            return mig_add_column('combos', $column, $definition);
        });
    }

    // -----------------------------------------------------------------------
    //  3. The 404 monitor
    // -----------------------------------------------------------------------

    $run('+ table seo_404_log', static function (): ?string {
        if (mig_table_exists('seo_404_log')) {
            return null;
        }

        Database::query(
            "CREATE TABLE `seo_404_log` (
                `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,

                `path` VARCHAR(255) NOT NULL
                    COMMENT 'App-relative path, query string stripped, one row per path',
                `referrer` VARCHAR(255) NULL
                    COMMENT 'Where the broken link was, query string stripped - never a full URL with parameters',
                `hits` INT(10) UNSIGNED NOT NULL DEFAULT 1,
                `status` ENUM('open','ignored','resolved') NOT NULL DEFAULT 'open',
                `first_seen_at` DATETIME NOT NULL,
                `last_seen_at` DATETIME NOT NULL,

                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_seo_404_path` (`path`),
                KEY `idx_seo_404_triage` (`status`, `hits`),
                KEY `idx_seo_404_seen` (`last_seen_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        return '+ table seo_404_log';
    });

    // -----------------------------------------------------------------------
    //  4. Retention and inventory settings
    // -----------------------------------------------------------------------
    // Defaults only - setting_save() leaves an operator's own value alone, so
    // re-running this never resets a tuned retention window.

    $run('+ SEO defaults', static function (): ?string {
        $added = 0;

        $defaults = [
            // 90 days is long enough to see a seasonal link go stale and short
            // enough that the table stays a working list rather than an archive.
            'seo_404_retention_days' => '90',
            // A hard ceiling for the pathological case: a scanner walking
            // /a, /b, /c... would otherwise add a row per probe.
            'seo_404_max_rows'       => '5000',
        ];

        foreach ($defaults as $key => $value) {
            if (!Database::exists('settings', '`setting_key` = :k', ['k' => $key])) {
                setting_save($key, $value, 'seo', 'text');
                $added++;
            }
        }

        return $added > 0 ? '+ ' . $added . ' SEO setting default(s)' : null;
    });

    return ['applied' => $applied, 'errors' => $errors];
}

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === realpath(__FILE__)) {
    $result = migration_seo_entity_run();
    echo "Per-entity SEO migration\n";
    foreach ($result['applied'] as $line) {
        echo '  ' . $line . "\n";
    }
    foreach ($result['errors'] as $line) {
        echo '  ERROR ' . $line . "\n";
    }
    printf("\n%d change(s), %d error(s)\n", count($result['applied']), count($result['errors']));
    exit($result['errors'] === [] ? 0 : 1);
}
