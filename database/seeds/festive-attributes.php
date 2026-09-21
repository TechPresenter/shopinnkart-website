<?php
/**
 * ShopInnKart - Drop the electronics attributes from the filter rail.
 *
 * The store seeded eight attributes for a phone-and-laptop catalogue: Storage,
 * RAM, Size, Model, Screen Size, Processor, Warranty and Colour. The catalogue
 * is now festive and decorative lighting, and every one of those attributes is
 * attached to zero variants — they rendered as filter groups that could never
 * narrow anything, because no product carries a value for them.
 *
 * Removes the six the shop has no use for. Colour stays (it is the one
 * attribute lighting actually varies by) and Model stays because it was
 * already flagged is_filter = 0 and costs nothing.
 *
 * Refuses to delete an attribute that any variant actually uses, so running it
 * against a restored electronics catalogue is a no-op rather than data loss.
 * Idempotent: a second run finds nothing to remove.
 *
 *     php database/seeds/festive-attributes.php          apply
 *     php database/seeds/festive-attributes.php --dry    report only
 */

declare(strict_types=1);

if (!defined('SIK_BOOTSTRAPPED')) {
    require_once dirname(__DIR__, 2) . '/includes/init.php';
}

/** The attributes that describe electronics and nothing this store sells. */
function festive_dead_attribute_slugs(): array
{
    return ['storage', 'ram', 'size', 'screen-size', 'processor', 'warranty'];
}

function festive_attributes_run(bool $dryRun = false): array
{
    $report = ['removed' => [], 'kept_in_use' => [], 'values_removed' => 0, 'absent' => []];

    foreach (festive_dead_attribute_slugs() as $slug) {
        $row = Database::fetch(
            'SELECT `id`, `name` FROM `attributes` WHERE `slug` = :s LIMIT 1',
            ['s' => $slug]
        );

        if (!$row) {
            $report['absent'][] = $slug;      // already gone - idempotent re-run
            continue;
        }

        $id = (int) $row['id'];

        // The guard that makes this safe to run anywhere: an attribute that a
        // variant actually uses is real data, not seed leftovers.
        $inUse = (int) Database::fetchColumn(
            'SELECT COUNT(*) FROM `product_variant_attributes` pva
               JOIN `attribute_values` av ON av.`id` = pva.`attribute_value_id`
              WHERE av.`attribute_id` = :i',
            ['i' => $id]
        );

        if ($inUse > 0) {
            $report['kept_in_use'][] = ['name' => $row['name'], 'variants' => $inUse];
            continue;
        }

        $valueCount = (int) Database::fetchColumn(
            'SELECT COUNT(*) FROM `attribute_values` WHERE `attribute_id` = :i',
            ['i' => $id]
        );

        if ($dryRun) {
            $report['removed'][] = $row['name'];
            $report['values_removed'] += $valueCount;
            continue;
        }

        Database::transaction(static function () use ($id) {
            // attribute_values has no guaranteed ON DELETE CASCADE to
            // attributes across installs, so clear the children explicitly
            // rather than relying on the schema.
            Database::delete('attribute_values', '`attribute_id` = :i', ['i' => $id]);
            Database::delete('attributes', '`id` = :i', ['i' => $id]);
        });

        $report['removed'][] = $row['name'];
        $report['values_removed'] += $valueCount;
    }

    // Filter rails are cached per listing; drop it so the shop stops offering
    // groups that no longer exist.
    if (function_exists('admin_after_write')) {
        admin_after_write();
    } elseif (function_exists('cache_bust')) {
        cache_bust();
    }

    return $report;
}

// ---------------------------------------------------------------------------
//  CLI
// ---------------------------------------------------------------------------
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    $result = festive_attributes_run(in_array('--dry', $argv, true));

    foreach ($result['removed'] as $name) {
        echo '  removed   ', $name, PHP_EOL;
    }
    foreach ($result['kept_in_use'] as $kept) {
        printf('  KEPT      %s (%d variants use it)%s', $kept['name'], $kept['variants'], PHP_EOL);
    }
    foreach ($result['absent'] as $slug) {
        echo '  already gone: ', $slug, PHP_EOL;
    }
    printf('%sAttributes removed : %d%sValues removed     : %d%s',
        PHP_EOL, count($result['removed']), PHP_EOL, $result['values_removed'], PHP_EOL);
    exit(0);
}
