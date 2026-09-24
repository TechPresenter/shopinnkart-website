<?php
/**
 * ShopInnKart - Migration: schema manager, sitemap sections and search engines.
 *
 * Three things arrive here, none of which existed before:
 *
 *   1. `seo_schema_types` - the switchboard. Until now every JSON-LD block the
 *      storefront emits was hard-coded in a page (product.php, faq.php, ...)
 *      with no way to switch one off, so a store that does not want its FAQ
 *      answers lifted into a search result had to edit PHP. One row per type,
 *      with the contexts it may appear in.
 *
 *   2. `seo_schema_custom` - admin-written JSON-LD. The value is executed by
 *      nobody (it is data, printed through e_json) but it IS published markup
 *      in the store's name, so writing one needs the same settings.scripts
 *      permission as the custom JS box, and every row records who wrote it.
 *
 *   3. The sitemap section switches and the Search Console fields. The two
 *      that already existed - sitemap_include_pages and sitemap_include_blog -
 *      are left exactly as the operator set them.
 *
 * Idempotent. Never DROPs, never overwrites a value the operator has changed.
 *
 * Run from the project root:
 *     php database/migrations/2026_09_24_seo_schema.php
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/init.php';
require_once __DIR__ . '/2026_08_13_invoice_email_system.php';
// For SITEMAP_MAX_URLS, so the shipped chunk size and the format limit the
// builder enforces can never be two different numbers.
require_once INCLUDES_PATH . '/sitemap-functions.php';

/**
 * @return array{applied:string[], errors:string[]}
 */
function migration_seo_schema_run(): array
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
    //  Tables
    // -----------------------------------------------------------------------

    $run('+ table seo_schema_types', static function (): ?string {
        if (mig_table_exists('seo_schema_types')) {
            return null;
        }

        // `contexts` is a comma-separated list of page kinds rather than JSON:
        // it is read on every storefront page view and compared against one
        // string, and a LIKE/IN over a short list beats decoding JSON per hit.
        // '*' means "every context this type supports".
        Database::query(
            "CREATE TABLE `seo_schema_types` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `type_key` VARCHAR(40) NOT NULL,
                `enabled` TINYINT(1) NOT NULL DEFAULT 1,
                `contexts` VARCHAR(255) NOT NULL DEFAULT '*',
                `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_type_key` (`type_key`)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        return '+ table seo_schema_types';
    });

    $run('+ table seo_schema_custom', static function (): ?string {
        if (mig_table_exists('seo_schema_custom')) {
            return null;
        }

        // entity_slug, not entity_id: the storefront knows the slug from the
        // URL it was already given, so targeting one product costs no extra
        // query on the page view. MEDIUMTEXT is far more than any real block
        // needs - the size limit that matters is enforced on save.
        Database::query(
            "CREATE TABLE `seo_schema_custom` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `name` VARCHAR(120) NOT NULL,
                `json_ld` MEDIUMTEXT NOT NULL,
                `contexts` VARCHAR(255) NOT NULL DEFAULT '',
                `entity_type` VARCHAR(20) DEFAULT NULL,
                `entity_slug` VARCHAR(191) DEFAULT NULL,
                `status` ENUM('active','inactive') NOT NULL DEFAULT 'inactive',
                `notes` VARCHAR(255) DEFAULT NULL,
                `created_by` INT UNSIGNED DEFAULT NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_status` (`status`),
                KEY `idx_entity` (`entity_type`, `entity_slug`)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        return '+ table seo_schema_custom';
    });

    // -----------------------------------------------------------------------
    //  The built-in types
    //
    //  Seeded from the catalogue in includes/schema.php so the two can never
    //  disagree about what a type is called. Everything ships ENABLED except
    //  the two that would change what an existing store already publishes -
    //  there are none today, so this is simply "as it behaves now, plus a
    //  switch". A type the operator has already configured is left alone.
    // -----------------------------------------------------------------------

    $run('+ schema types', static function (): ?string {
        require_once INCLUDES_PATH . '/schema.php';

        $added = 0;
        foreach (schema_catalogue() as $key => $type) {
            if (Database::exists('seo_schema_types', '`type_key` = :k', ['k' => $key])) {
                continue;
            }
            Database::insert('seo_schema_types', [
                'type_key' => $key,
                'enabled'  => !empty($type['default_enabled']) ? 1 : 0,
                'contexts' => (string) ($type['default_contexts'] ?? '*'),
            ]);
            $added++;
        }

        return $added > 0 ? '+ schema types (' . $added . ')' : null;
    });

    // -----------------------------------------------------------------------
    //  Settings
    //
    //  key => [value, group, type]. Written only when the key is absent.
    // -----------------------------------------------------------------------

    $settings = [
        // --- which sections the sitemap index lists ----------------------
        // pages and blog already exist and are NOT touched here.
        'sitemap_include_products'   => ['1',   'seo', 'boolean'],
        'sitemap_include_categories' => ['1',   'seo', 'boolean'],
        'sitemap_include_brands'     => ['1',   'seo', 'boolean'],
        'sitemap_include_images'     => ['1',   'seo', 'boolean'],

        // Out of stock is not out of catalogue: the page is still the right
        // answer to a search for that product, and hiding it loses the ranking
        // the moment stock runs out. Off by default, on for stores that treat
        // a sold-out line as gone.
        'sitemap_exclude_oos'        => ['0',   'seo', 'boolean'],

        // Seconds. A sitemap is rebuilt from the database on request; this is
        // how long the built XML is reused for. 0 disables the cache.
        'sitemap_cache_ttl'          => ['900', 'seo', 'number'],

        // URLs per section file. The format's own ceiling is 50,000 and
        // nothing can raise it; a big catalogue may want smaller files so each
        // one downloads quickly.
        'sitemap_urls_per_file'      => [(string) SITEMAP_MAX_URLS, 'seo', 'number'],

        // --- search engines ----------------------------------------------
        // The HTML verification file Google hands out, e.g. google1a2b3c.html.
        // Stored so the admin screen can say whether it is still on disk.
        'gsc_verification_file'      => ['',    'seo', 'text'],
        // When the owner last told a search engine about the sitemap. A date
        // they set, not one we can read back from an API we have no key for.
        'sitemap_submitted_at'       => ['',    'seo', 'text'],
    ];

    foreach ($settings as $key => [$value, $group, $type]) {
        $run('+ setting ' . $key, static function () use ($key, $value, $group, $type): ?string {
            if (Database::exists('settings', '`setting_key` = :k', ['k' => $key])) {
                return null;
            }
            setting_save($key, $value, $group, $type);
            return '+ setting ' . $key;
        });
    }

    // The storefront caches settings; a fresh key must be visible immediately.
    $run('cache flush', static function (): ?string {
        cache_bust();
        return null;
    });

    return ['applied' => $applied, 'errors' => $errors];
}

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === realpath(__FILE__)) {
    $result = migration_seo_schema_run();
    echo "SEO migration (schema manager, sitemap sections, search engines)\n";
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
