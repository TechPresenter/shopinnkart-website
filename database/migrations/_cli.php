<?php
/**
 * Migrations take no arguments - and until now they did not say so.
 *
 * Every seed in this project honours --dry: it prints what it would change and
 * writes nothing. No migration does. All of them apply immediately and rely on
 * being idempotent instead, which is a reasonable design; what made it
 * dangerous is that the flag was not REJECTED either, just ignored. So
 *
 *     php database/migrations/2026_09_28_integration_queue.php --dry
 *
 * ran the whole migration on whatever database was configured, while printing
 * output that reads exactly like a preview:
 *
 *     + table integration_queue
 *     + setting meta_capi_enabled = 0
 *
 * The operator believes they looked. They deployed. On a local copy that is a
 * shrug; typed against the live site while checking what a migration would do,
 * it is the difference between a rehearsal and a performance.
 *
 * The fix is only to refuse. A migration that applies when you asked it to
 * apply is fine, and adding a real --dry to twenty-seven files would mean
 * twenty-seven new code paths that are never exercised - which is its own way
 * of lying. Idempotence is the guarantee these carry, so the honest thing is
 * to state it and stop.
 */

declare(strict_types=1);

/**
 * Stop unless this migration was invoked with no arguments.
 *
 * @param array $argv the caller's own $argv; element 0 is the script itself.
 */
function migration_refuse_arguments(array $argv): void
{
    $extra = array_slice($argv, 1);

    if ($extra === []) {
        return;
    }

    $name = basename((string) ($argv[0] ?? 'this migration'));

    fwrite(STDERR, "\n" . $name . ": refusing to run.\n\n");
    fwrite(STDERR, "  Given:    " . implode(' ', $extra) . "\n");
    fwrite(STDERR, "  Expected: no arguments.\n\n");

    if (in_array('--dry', $extra, true) || in_array('--dry-run', $extra, true)) {
        fwrite(STDERR, "  Migrations in this project have no --dry. The SEEDS do, which is\n");
        fwrite(STDERR, "  where the habit comes from, and why this refuses instead of\n");
        fwrite(STDERR, "  ignoring you: until now the flag was accepted and discarded, so\n");
        fwrite(STDERR, "  the migration applied in full while its output read like a\n");
        fwrite(STDERR, "  preview.\n\n");
        fwrite(STDERR, "  Migrations are idempotent. Run it for real, twice if you like -\n");
        fwrite(STDERR, "  the second run reports nothing to do. Take a backup first if it\n");
        fwrite(STDERR, "  is the live database: php bin/backup.php\n\n");
    }

    exit(2);
}
