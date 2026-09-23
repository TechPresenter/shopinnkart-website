<?php
/**
 * ShopInnKart - Email queue worker (CLI).
 *
 * The proper way to deliver queued mail. Without it the only dispatcher is the
 * opportunistic drain in the storefront footer, which means a quiet store never
 * sends anything and a busy one delivers at random intervals.
 *
 * Schedule it every minute:
 *
 *   Linux crontab:
 *     * * * * * /usr/bin/php /path/to/ecomweb/bin/send-queued-emails.php >> /path/to/ecomweb/storage/logs/mail-worker.log 2>&1
 *
 *   Windows Task Scheduler (XAMPP):
 *     Program:   C:\xampp\php\php.exe
 *     Arguments: C:\xampp\htdocs\ecomweb\bin\send-queued-emails.php
 *     Trigger:   daily, repeat every 1 minute
 *
 * Options:
 *   --limit=N   messages per run (default 25)
 *   --retry     also retry rows already marked failed
 *   --prune     delete sent rows older than email_log_retention_days
 *   --quiet     only print on error
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script is CLI only.');
}

require_once dirname(__DIR__) . '/includes/init.php';

// ---------------------------------------------------------------------------
//  Arguments
// ---------------------------------------------------------------------------
$options = getopt('', ['limit::', 'retry', 'prune', 'quiet']);

$limit = isset($options['limit']) ? max(1, min(500, (int) $options['limit'])) : 25;
$quiet = array_key_exists('quiet', $options);

$say = static function (string $message) use ($quiet): void {
    if (!$quiet) {
        echo '[', date('Y-m-d H:i:s'), '] ', $message, PHP_EOL;
    }
};

// ---------------------------------------------------------------------------
//  Only one worker at a time
// ---------------------------------------------------------------------------
// Two overlapping runs would both pick up the same pending rows and send every
// message twice; the queue's SELECT is not a claim.
$locked = (int) Database::fetchColumn("SELECT GET_LOCK('sik_mail_worker', 0)") === 1;
if (!$locked) {
    $say('Another worker is running — exiting.');
    exit(0);
}

try {
    // -----------------------------------------------------------------------
    //  Retry failed rows when asked
    // -----------------------------------------------------------------------
    if (array_key_exists('retry', $options)) {
        $reset = Database::query(
            "UPDATE `notification_queue`
             SET `status` = 'pending', `attempts` = 0
             WHERE `status` = 'failed' AND `created_at` > DATE_SUB(NOW(), INTERVAL 7 DAY)"
        )->rowCount();
        $say(sprintf('Requeued %d failed message(s).', $reset));
    }

    // -----------------------------------------------------------------------
    //  Transport sanity check — fail loudly rather than burning attempts
    // -----------------------------------------------------------------------
    if (!mail_is_configured()) {
        $config = mail_config();
        fwrite(STDERR, sprintf(
            "[%s] Mail transport is not usable (transport=%s, host=%s). Queue left untouched.%s",
            date('Y-m-d H:i:s'),
            $config['transport'],
            $config['host'] === '' ? '(none)' : $config['host'],
            PHP_EOL
        ));
        exit(1);
    }

    $pending = (int) Database::fetchColumn(
        "SELECT COUNT(*) FROM `notification_queue` WHERE `status` = 'pending' AND `attempts` < 3"
    );

    if ($pending === 0) {
        $say('Nothing pending.');
    } else {
        $started = microtime(true);
        $result = process_notification_queue($limit);

        $say(sprintf(
            'Processed %d of %d pending — %d sent, %d failed (%.1fs).',
            min($limit, $pending),
            $pending,
            $result['sent'],
            $result['failed'],
            microtime(true) - $started
        ));

        if ($result['failed'] > 0) {
            $recent = Database::fetchAll(
                "SELECT `recipient`, `template_key`, `attempts`, `error`
                 FROM `notification_queue`
                 WHERE `error` IS NOT NULL AND `last_attempt_at` > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
                 ORDER BY `id` DESC LIMIT 5"
            );
            foreach ($recent as $row) {
                fwrite(STDERR, sprintf(
                    "  ! %s (%s, attempt %d): %s%s",
                    mask_email((string) $row['recipient']),
                    (string) $row['template_key'],
                    (int) $row['attempts'],
                    (string) $row['error'],
                    PHP_EOL
                ));
            }
        }
    }

    // -----------------------------------------------------------------------
    //  Retention — the queue doubles as the email log and grows forever
    // -----------------------------------------------------------------------
    if (array_key_exists('prune', $options)) {
        $days = max(7, setting_int('email_log_retention_days', 90));
        $deleted = Database::query(
            "DELETE FROM `notification_queue`
             WHERE `status` = 'sent' AND `created_at` < DATE_SUB(NOW(), INTERVAL :days DAY)",
            ['days' => $days]
        )->rowCount();
        $say(sprintf('Pruned %d sent row(s) older than %d days.', $deleted, $days));
    }

    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, sprintf('[%s] Worker failed: %s%s', date('Y-m-d H:i:s'), $e->getMessage(), PHP_EOL));
    exit(1);
} finally {
    Database::query("SELECT RELEASE_LOCK('sik_mail_worker')");
}
