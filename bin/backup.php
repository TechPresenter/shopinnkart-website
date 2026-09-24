<?php
/**
 * ShopInnKart - Scheduled backup, verification and restore (CLI).
 *
 * The backup screen in the admin panel is for the backup somebody remembers
 * to take. This is for the one nobody has to remember, and for the restore,
 * which is the half that actually matters and the half a browser is worst at:
 * no execution-time cap, no aborted request, no page to keep open.
 *
 * Schedule it nightly:
 *
 *   Linux crontab (Hostinger: hPanel > Advanced > Cron Jobs):
 *     15 3 * * * /usr/bin/php /home/USER/domains/example.com/ecomweb/bin/backup.php --quiet
 *
 *   Windows Task Scheduler (XAMPP):
 *     Program:   C:\xampp\php\php.exe
 *     Arguments: C:\xampp\htdocs\ecomweb\bin\backup.php --quiet
 *
 * Options:
 *   --create              take a backup (the default when nothing else is asked for)
 *   --note=TEXT           label the backup in the list
 *   --keep=N              override the retention count for this run
 *   --list                show what is on disk
 *   --verify[=ID|all]     re-checksum and re-read a backup (default: the newest)
 *   --selftest            prove the encrypt/checksum/decrypt chain works here
 *   --prune               apply retention without taking a new backup
 *   --restore=FILE        restore a dump. Needs --confirm.
 *   --tables=a,b          with --restore, restore only these tables
 *   --confirm             the acknowledgement --restore will not run without
 *   --passphrase=TEXT     for a passphrase-protected dump; SIK_BACKUP_PASSPHRASE also works
 *   --quiet               only print on error
 *
 * Exit codes: 0 all good, 1 something failed. Cron mails you the output when
 * it is not quiet, and --quiet still prints (and exits 1) on a failure, which
 * is what makes a silent run mean "it worked".
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script is CLI only.');
}

require_once dirname(__DIR__) . '/includes/init.php';
require_once INCLUDES_PATH . '/backup.php';

$options = getopt('', [
    'create', 'note::', 'keep::', 'list', 'verify::', 'selftest', 'prune',
    'restore::', 'tables::', 'confirm', 'passphrase::', 'quiet',
]);

$quiet = array_key_exists('quiet', $options);

$say = static function (string $message) use ($quiet): void {
    if (!$quiet) {
        echo $message, PHP_EOL;
    }
};

/** Always printed, quiet or not: a failure nobody sees is a failure nobody fixes. */
$shout = static function (string $message): void {
    fwrite(STDERR, $message . PHP_EOL);
};

$passphrase = isset($options['passphrase']) && is_string($options['passphrase']) && $options['passphrase'] !== ''
    ? $options['passphrase']
    : backup_passphrase();

// ---------------------------------------------------------------------------
//  Only one run at a time
//
//  Two overlapping dumps would each write a file, each prune, and between them
//  delete the other's work - and on a small host they would also fight for the
//  same disk and the same connection.
// ---------------------------------------------------------------------------
$locked = (int) Database::fetchColumn("SELECT GET_LOCK('sik_backup', 0)") === 1;
if (!$locked) {
    $say('Another backup run is in progress - exiting.');
    exit(0);
}

$exit = 0;

try {
    // -----------------------------------------------------------------------
    //  --list
    // -----------------------------------------------------------------------
    if (array_key_exists('list', $options)) {
        $status = backup_dir_status();
        $say('Folder: ' . $status['path']
            . ($status['outside_web_root'] ? ' (outside the web root)' : ' (inside the web root, denied by .htaccess)'));
        $say('Keeping: ' . (backup_keep() > 0 ? backup_keep() . ' most recent' : 'every backup'));
        $say('');

        $rows = Database::fetchAll(
            'SELECT `id`, `filename`, `size_bytes`, `protection`, `source`, `rows_count`, `created_at`
               FROM `backups` ORDER BY `id` DESC LIMIT 50'
        );

        if ($rows === []) {
            $say('No backups yet.');
        }
        foreach ($rows as $row) {
            $path = backup_resolve_path((string) $row['filename']);
            $say(sprintf(
                '%-5s %-46s %10s  %-10s %-9s %s%s',
                '#' . $row['id'],
                (string) $row['filename'],
                format_bytes((int) $row['size_bytes']),
                (string) $row['protection'],
                (string) $row['source'],
                (string) $row['created_at'],
                $path === null ? '   [FILE MISSING]' : ''
            ));
        }

        exit(0);
    }

    // -----------------------------------------------------------------------
    //  --selftest
    // -----------------------------------------------------------------------
    if (array_key_exists('selftest', $options)) {
        $test = backup_selftest($passphrase);
        foreach ($test['steps'] as $step) {
            $line = ($step['ok'] ? '  ok   ' : '  FAIL ') . $step['label']
                . ($step['detail'] !== '' ? '  -  ' . $step['detail'] : '');
            $step['ok'] ? $say($line) : $shout($line);
        }
        $test['ok'] ? $say('') : $shout('The backup path does not work on this server.');
        exit($test['ok'] ? 0 : 1);
    }

    // -----------------------------------------------------------------------
    //  --restore
    // -----------------------------------------------------------------------
    if (isset($options['restore']) && is_string($options['restore']) && $options['restore'] !== '') {
        $path = backup_resolve_path($options['restore']);
        if ($path === null) {
            $shout('No such backup in ' . backup_dir() . ': ' . $options['restore']);
            exit(1);
        }

        $only = isset($options['tables']) && is_string($options['tables']) && $options['tables'] !== ''
            ? array_values(array_filter(array_map('trim', explode(',', $options['tables']))))
            : null;

        echo 'Restoring ' . basename($path) . ' into ' . DB_NAME . '.' . PHP_EOL;
        echo PHP_EOL;
        foreach (backup_restore_caveats() as $caveat) {
            echo '  * ' . wordwrap($caveat, 92, PHP_EOL . '    ') . PHP_EOL;
        }
        echo PHP_EOL;

        if (!array_key_exists('confirm', $options)) {
            $shout('Nothing was changed. Add --confirm once you have read the list above.');
            exit(1);
        }

        try {
            $head = backup_headline($path, $passphrase);
            echo 'This dump was taken from "' . $head['database'] . '" on ' . $head['generated']
                . ' and holds ' . $head['tables'] . ' tables.' . PHP_EOL;
        } catch (Throwable $e) {
            $shout('The dump could not be opened: ' . $e->getMessage());
            exit(1);
        }

        $result = backup_restore($path, [
            'passphrase' => $passphrase,
            'tables'     => $only,
            'max_seconds' => 0,
        ]);

        echo sprintf(
            '%s statements, %s rows, %d tables, %ss.%s',
            number_format($result['statements']),
            number_format($result['rows']),
            count($result['tables']),
            $result['seconds'],
            PHP_EOL
        );

        security_event($result['complete'] ? 'backup.restored' : 'backup.restore_failed',
            'critical', [
                'file'       => basename($path),
                'tables'     => count($result['tables']),
                'statements' => $result['statements'],
                'errors'     => array_slice($result['errors'], 0, 3),
            ]);

        foreach ($result['errors'] as $error) {
            $shout('  ! ' . $error);
        }

        if (!$result['complete']) {
            $shout('The restore did NOT finish. The database is part-way between two states.');
            exit(1);
        }

        echo 'Restore complete.' . PHP_EOL;
        exit(0);
    }

    // -----------------------------------------------------------------------
    //  --verify
    // -----------------------------------------------------------------------
    if (array_key_exists('verify', $options)) {
        $which = is_string($options['verify']) ? $options['verify'] : '';

        if ($which === 'all') {
            $records = Database::fetchAll('SELECT * FROM `backups` ORDER BY `id` DESC');
        } elseif ($which !== '' && ctype_digit($which)) {
            $records = array_filter([Database::fetch('SELECT * FROM `backups` WHERE `id` = :id', ['id' => (int) $which])]);
        } else {
            $records = array_filter([Database::fetch('SELECT * FROM `backups` ORDER BY `id` DESC LIMIT 1')]);
        }

        if ($records === []) {
            $shout('Nothing to verify.');
            exit(1);
        }

        foreach ($records as $record) {
            $check = backup_verify($record, $passphrase);
            Database::update('backups', [
                'verified_at'  => date('Y-m-d H:i:s'),
                'verify_error' => $check['ok'] ? null : mb_substr($check['error'], 0, 255),
            ], '`id` = :id', ['id' => (int) $record['id']]);

            $line = sprintf('%-5s %-46s %s%s',
                '#' . $record['id'],
                (string) $record['filename'],
                $check['ok'] ? 'ok' : 'FAILED',
                $check['ok'] ? ' (' . number_format($check['statements']) . ' statements)' : ' - ' . $check['error']);

            if ($check['ok']) {
                $say($line);
            } else {
                $shout($line);
                $exit = 1;
                security_event('backup.verify_failed', 'high',
                    ['backup_id' => (int) $record['id'], 'error' => $check['error']]);
            }
        }

        exit($exit);
    }

    // -----------------------------------------------------------------------
    //  --prune
    // -----------------------------------------------------------------------
    if (array_key_exists('prune', $options) && !array_key_exists('create', $options)) {
        $keep    = isset($options['keep']) && is_string($options['keep']) ? (int) $options['keep'] : null;
        $removed = backup_prune($keep);
        $say($removed > 0
            ? $removed . ' old backup(s) removed.'
            : 'Nothing to remove - ' . (backup_keep() > 0 ? backup_keep() : 'every') . ' backup(s) kept.');
        exit(0);
    }

    // -----------------------------------------------------------------------
    //  --create (the default)
    // -----------------------------------------------------------------------
    $began = microtime(true);

    try {
        if (isset($options['keep']) && is_string($options['keep']) && $options['keep'] !== '') {
            // Only for this run; the setting is not rewritten from a cron flag.
            $keepOverride = max(0, (int) $options['keep']);
        }

        $result = backup_create([
            'note'       => isset($options['note']) && is_string($options['note']) ? $options['note'] : '',
            'source'     => 'scheduled',
            'passphrase' => $passphrase,
        ]);

        if (isset($keepOverride)) {
            $result['pruned'] += backup_prune($keepOverride);
        }

        security_event('backup.created', 'high', [
            'backup_id'  => $result['id'],
            'filename'   => $result['filename'],
            'tables'     => $result['tables'],
            'rows'       => $result['rows'],
            'protection' => $result['protection'],
            'source'     => 'scheduled',
        ]);

        log_activity('backup.created', 'backup', $result['id'],
            'Scheduled backup "' . $result['filename'] . '" - ' . $result['tables'] . ' tables, '
            . number_format($result['rows']) . ' rows, ' . format_bytes($result['bytes']));

        $say(sprintf(
            '%s  %s tables, %s rows, %s, %s, %.1fs%s',
            $result['filename'],
            number_format($result['tables']),
            number_format($result['rows']),
            format_bytes($result['bytes']),
            $result['protection'],
            microtime(true) - $began,
            $result['pruned'] > 0 ? ' (' . $result['pruned'] . ' old removed)' : ''
        ));
    } catch (Throwable $e) {
        $exit = 1;
        $shout('The backup failed: ' . $e->getMessage());

        ErrorHandler::log('error', 'Scheduled backup failed: ' . $e->getMessage(), $e->getFile(), $e->getLine());
        security_event('backup.failed', 'critical', ['error' => $e->getMessage(), 'source' => 'scheduled']);

        // The whole point of a scheduled backup is that nobody is watching it,
        // so a failure has to come and find somebody.
        $last = Database::fetchColumn('SELECT `created_at` FROM `backups` ORDER BY `id` DESC LIMIT 1');
        notify_admins('backup_failed_admin', [
            'error'       => $e->getMessage(),
            'event_time'  => format_datetime(date('Y-m-d H:i:s')),
            'last_backup' => $last !== null ? time_ago((string) $last) : 'never',
        ], 'backup', null);
    }
} finally {
    // Belt and braces. GET_LOCK is scoped to the connection, so the lock is
    // released anyway when the process ends - including on the exit() calls
    // above, which skip this block entirely.
    Database::query("SELECT RELEASE_LOCK('sik_backup')");
}

exit($exit);
