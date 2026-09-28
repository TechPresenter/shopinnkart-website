<?php
/**
 * ShopInnKart - Migration: the custom snippet store.
 *
 * WHAT IT CREATES
 * --------------
 * One table, `custom_scripts`: a row per operator-authored snippet, with a
 * name, a type, a placement, a consent category, an on/off switch and an
 * order. See includes/custom-scripts.php for why the code column is stored
 * unfiltered and what actually guards it.
 *
 * THE MOVE, AND WHY IT IS THE RISKY HALF
 * -------------------------------------
 * Before this, the storefront's only snippet fields were the `custom_css` and
 * `custom_js` settings, injected by includes/header.php and
 * includes/footer.php. Those two injection points now read the new table
 * instead. That leaves exactly two ways to get it wrong, and this migration is
 * where both are prevented:
 *
 *   - leaving the settings populated AND unread means the owner's live snippet
 *     silently stops running, so their value is carried across as a row here;
 *   - carrying it across without clearing the setting would be fine today and
 *     a double injection the first time anybody re-reads that key, so the
 *     setting is blanked in the SAME transaction as the insert.
 *
 * Both halves are one transaction on purpose: a half-applied move is the only
 * state in which a snippet could be lost or printed twice.
 *
 * WHY THE MIGRATED ROWS ARE `essential`, WHEN A NEW ROW DEFAULTS TO MARKETING
 * -------------------------------------------------------------------------
 * The two settings ran for every visitor, unconditionally, with no consent
 * gate anywhere - and Admin > SEO > Injected code described them as
 * "first-party by assumption". Importing them as `essential` reproduces
 * exactly what the store did yesterday. Importing them as `marketing` would be
 * defensible on paper and would switch off a working snippet on a live store
 * during an upgrade, which is not a migration's job. New snippets added by
 * hand default to `marketing` instead, because a pasted snippet is usually
 * somebody else's tag.
 *
 * `imported_from` carries a UNIQUE key, so the import is idempotent by the
 * database rather than by a flag somebody has to remember to check: a second
 * run finds the row already there and moves nothing.
 *
 * Idempotent. Never DROPs. Re-running reports "nothing to do".
 *
 *     php database/migrations/2026_09_28_custom_scripts.php
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/init.php';

/**
 * The two legacy keys, and the name each becomes.
 *
 * Keyed by the `imported_from` marker so the value, the row it becomes and the
 * idempotency key are declared in one place.
 */
const CUSTOM_SCRIPTS_LEGACY = [
    'setting:custom_css' => [
        'setting'   => 'custom_css',
        'name'      => 'Theme custom CSS',
        'type'      => 'css',
        'placement' => 'head',
        'notes'     => 'Moved here from Settings > Theme, where it was injected into every page\'s '
            . 'style block. Unchanged.',
    ],
    'setting:custom_js' => [
        'setting'   => 'custom_js',
        'name'      => 'Theme custom JavaScript',
        'type'      => 'js',
        'placement' => 'body_end',
        'notes'     => 'Moved here from Settings > Theme, where it was injected just before '
            . '</body>. Unchanged.',
    ],
];

/**
 * @return array{applied:string[], errors:string[]}
 */
function migration_custom_scripts_run(): array
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

    $tableExists = static function (string $table): bool {
        return (int) Database::fetchColumn(
            'SELECT COUNT(*) FROM `information_schema`.`TABLES`
             WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = :t',
            ['t' => $table]
        ) > 0;
    };

    // -----------------------------------------------------------------------
    //  The table
    // -----------------------------------------------------------------------
    $run('+ table custom_scripts', static function () use ($tableExists): ?string {
        if ($tableExists('custom_scripts')) {
            return null;
        }

        Database::query("CREATE TABLE `custom_scripts` (
            `id`            INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `name`          VARCHAR(120) NOT NULL COMMENT 'The operator\'s own label. Never printed to the page',
            `type`          ENUM('js','css','html') NOT NULL DEFAULT 'js',
            `placement`     ENUM('head','body_start','body_end') NOT NULL DEFAULT 'body_end',
            `consent`       ENUM('essential','analytics','marketing') NOT NULL DEFAULT 'marketing'
                            COMMENT 'Only essential runs before the visitor has answered the banner',
            `code`          MEDIUMTEXT NOT NULL COMMENT 'Stored exactly as typed. See includes/custom-scripts.php',
            `notes`         VARCHAR(500) NULL COMMENT 'Why it exists. Admin-only, never emitted',
            `is_enabled`    TINYINT(1) NOT NULL DEFAULT 0,
            `sort_order`    SMALLINT(5) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Within its placement only',
            `imported_from` VARCHAR(40) NULL COMMENT 'Set once by the migration that moved a legacy setting in',
            `created_by`    INT(10) UNSIGNED NULL,
            `created_name`  VARCHAR(120) NULL COMMENT 'Kept after the admin row is deleted, like activity_logs',
            `updated_by`    INT(10) UNSIGNED NULL,
            `updated_name`  VARCHAR(120) NULL,
            `created_at`    TIMESTAMP NOT NULL DEFAULT current_timestamp(),
            `updated_at`    TIMESTAMP NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_imported` (`imported_from`),
            KEY `ix_live` (`is_enabled`, `placement`, `sort_order`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        return '+ table custom_scripts';
    });

    // -----------------------------------------------------------------------
    //  The move
    // -----------------------------------------------------------------------
    foreach (CUSTOM_SCRIPTS_LEGACY as $marker => $legacy) {
        $run('~ move ' . $legacy['setting'], static function () use ($marker, $legacy, $tableExists): ?string {
            if (!$tableExists('custom_scripts')) {
                return null;   // the create above failed; its error is already recorded
            }

            // The marker's UNIQUE key is the real guard; asking first keeps the
            // re-run quiet instead of catching a duplicate-key error.
            if (Database::exists('custom_scripts', '`imported_from` = :m', ['m' => $marker])) {
                return null;
            }

            // Read the row, not setting(): a blank value and a missing key are
            // the same to setting() and only one of them is worth reporting.
            $value = (string) Database::fetchColumn(
                'SELECT `setting_value` FROM `settings` WHERE `setting_key` = :k',
                ['k' => $legacy['setting']]
            );

            if (trim($value) === '') {
                return null;   // nothing to carry across
            }

            Database::transaction(static function () use ($marker, $legacy, $value): void {
                Database::insert('custom_scripts', [
                    'name'          => $legacy['name'],
                    'type'          => $legacy['type'],
                    'placement'     => $legacy['placement'],
                    // See the file docblock: reproduces yesterday's behaviour.
                    'consent'       => 'essential',
                    'code'          => $value,
                    'notes'         => $legacy['notes'],
                    // It was running, so it goes on running.
                    'is_enabled'    => 1,
                    'sort_order'    => 1,
                    'imported_from' => $marker,
                    'created_name'  => 'Migration',
                ]);

                // Blanked, not deleted: the row keeps its group and type, so an
                // older screen that still reads the key gets '' rather than a
                // missing setting, and nothing resurrects the value.
                Database::update('settings', ['setting_value' => ''],
                    '`setting_key` = :k', ['k' => $legacy['setting']]);
            });

            return '~ moved ' . $legacy['setting'] . ' (' . number_format(mb_strlen($value))
                . ' chars) into a snippet named "' . $legacy['name'] . '", enabled, consent=essential;'
                . ' the setting is now blank';
        });
    }

    $run('= caches', static function (): ?string {
        settings_cache_generation(true);
        if (function_exists('cache_bust')) {
            cache_bust();
        }
        return null;
    });

    // No new permission: settings.scripts already exists in
    // ADMIN_SUPER_ONLY_PERMISSIONS and in the `admin_permissions` seed, and it
    // is the gate the new screen uses.

    return ['applied' => $applied, 'errors' => $errors];
}

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === realpath(__FILE__)) {
    // Shared with every other migration: this one takes no arguments, and a
    // `--dry` that is ignored rather than refused reads exactly like a preview
    // of a change it has already applied.
    require_once __DIR__ . '/_cli.php';
    migration_refuse_arguments($argv);

    $result = migration_custom_scripts_run();
    echo "Custom scripts migration\n";
    foreach ($result['applied'] as $line) {
        echo '  ' . $line . "\n";
    }
    foreach ($result['errors'] as $line) {
        echo '  ! ' . $line . "\n";
    }
    if ($result['applied'] === [] && $result['errors'] === []) {
        echo "  (nothing to do - already applied)\n";
    }
    exit($result['errors'] === [] ? 0 : 1);
}
