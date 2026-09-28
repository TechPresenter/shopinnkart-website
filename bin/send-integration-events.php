<?php
/**
 * ShopInnKart - integration event worker (CLI).
 *
 * The only thing that actually talks to Meta or to the owner's webhook. Nothing
 * in a web request ever does, which is what stops a slow third party from
 * slowing a checkout and a down one from losing a conversion.
 *
 * Two jobs, in this order:
 *
 *   1. HARVEST. Look at orders that changed since the last run and queue the
 *      webhook events the owner has chosen. The first run queues nothing and
 *      only records the watermark - switching a webhook on must not fire the
 *      whole order history at an endpoint that is still being set up.
 *   2. SEND. Deliver what is due, with backoff, and give up loudly.
 *
 * GIVING UP IS LOUD ON PURPOSE. A row that has exhausted its attempts is
 * written to STDERR and makes this script exit non-zero, so a cron that mails
 * its output mails somebody. The heartbeat is still stamped either way: "did the
 * schedule fire" and "did the work succeed" are different questions, and
 * conflating them is how the old inference-based checks went wrong.
 *
 * Schedule it every five minutes:
 *
 *   Linux crontab (the minute list is spelled out because a slash-star in this
 *   comment would end it):
 *     0,5,10,15,20,25,30,35,40,45,50,55 * * * * /usr/bin/php /path/to/ecomweb/bin/send-integration-events.php --prune >> /path/to/ecomweb/storage/logs/integration-worker.log 2>&1
 *
 *   Windows Task Scheduler (XAMPP):
 *     Program:   C:\xampp\php\php.exe
 *     Arguments: C:\xampp\htdocs\ecomweb\bin\send-integration-events.php --prune
 *     Trigger:   daily, repeat every 5 minutes
 *
 * Options:
 *   --limit=N       events per run (default 25)
 *   --harvest=N     orders scanned per run (default 200)
 *   --no-harvest    send only; do not look for new events
 *   --retry         put failed rows from the last 7 days back in the queue
 *   --prune         delete sent and skipped rows past the retention setting
 *   --quiet         only print on error
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script is CLI only.');
}

require_once dirname(__DIR__) . '/includes/init.php';
require_once INCLUDES_PATH . '/integration-senders.php';

// Record that the schedule fired on THIS machine, the way the other six workers
// do. See includes/cron-heartbeat.php. Registered before any early exit so a
// run that finds nothing to do still counts as a run.
require_once INCLUDES_PATH . '/cron-heartbeat.php';
cron_heartbeat_on_finish('send-integration-events');

// ---------------------------------------------------------------------------
//  Arguments
// ---------------------------------------------------------------------------
$options = getopt('', ['limit::', 'harvest::', 'no-harvest', 'retry', 'prune', 'quiet']);

$limit   = isset($options['limit']) ? max(1, min(500, (int) $options['limit'])) : 25;
$scan    = isset($options['harvest']) ? max(1, min(1000, (int) $options['harvest'])) : 200;
$quiet   = array_key_exists('quiet', $options);
$exit    = 0;

$say = static function (string $message) use ($quiet): void {
    if (!$quiet) {
        echo '[', date('Y-m-d H:i:s'), '] ', $message, PHP_EOL;
    }
};
$shout = static function (string $message): void {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL);
};

// ---------------------------------------------------------------------------
//  Is there anything installed to work with?
// ---------------------------------------------------------------------------
if (!integration_queue_installed()) {
    $shout('The integration queue tables are missing. Run database/migrations/2026_09_28_integration_queue.php.');
    exit(1);
}

// ---------------------------------------------------------------------------
//  Only one worker at a time
// ---------------------------------------------------------------------------
// Two overlapping runs would both claim the same due rows and post every event
// twice; the queue's SELECT is not a claim. Meta would deduplicate on event_id,
// but the owner's endpoint has no such promise from us.
$locked = (int) Database::fetchColumn("SELECT GET_LOCK('sik_integration_worker', 0)") === 1;
if (!$locked) {
    $say('Another worker is running - exiting.');
    exit(0);
}

try {
    $status = integration_sender_status();

    // -----------------------------------------------------------------------
    //  Requeue failures when asked
    // -----------------------------------------------------------------------
    if (array_key_exists('retry', $options)) {
        $reset = integration_queue_retry_failed(7);
        $say(sprintf('Requeued %d given-up event(s).', $reset));
    }

    // -----------------------------------------------------------------------
    //  Harvest
    // -----------------------------------------------------------------------
    if (!array_key_exists('no-harvest', $options)) {
        $harvest = integration_harvest_webhooks($scan);

        if ($harvest['note'] !== '') {
            $say('Harvest: ' . $harvest['note']);
        } else {
            $say(sprintf(
                'Harvest: scanned %d changed order(s), queued %d event(s), %d already queued.',
                $harvest['scanned'],
                $harvest['queued'],
                $harvest['skipped']
            ));
        }
    }

    // -----------------------------------------------------------------------
    //  Send
    // -----------------------------------------------------------------------
    // Said once per run rather than per row: an operator reading a log wants to
    // know why nothing moved, and "switched off" is the commonest answer.
    foreach (['meta' => 'Meta CAPI', 'webhook' => 'Webhook'] as $key => $label) {
        if (!in_array($status[$key]['state'], ['sending', 'no-source'], true)) {
            $say($label . ': ' . $status[$key]['line']);
        }
    }

    $due = integration_queue_due($limit);

    if ($due === []) {
        $say('Nothing due.');
    } else {
        $started = microtime(true);
        $result  = integration_process_queue($limit);

        $say(sprintf(
            'Sent %d, retrying %d, gave up on %d, skipped %d (%.1fs).',
            $result['sent'],
            $result['retry'],
            $result['failed'],
            $result['skipped'],
            microtime(true) - $started
        ));

        foreach ($result['gave_up'] as $row) {
            $shout(sprintf(
                '  GAVE UP  #%d %s %s after %d attempt(s), HTTP %s: %s',
                $row['id'],
                $row['channel'],
                $row['event'],
                $row['attempts'],
                $row['status'] === 0 ? 'none' : (string) $row['status'],
                $row['error'] === '' ? 'no reason given' : $row['error']
            ));
        }

        if ($result['failed'] > 0) {
            // Non-zero exit is the loud part: cron mails it.
            $exit = 1;
        }
    }

    // -----------------------------------------------------------------------
    //  Retention - the queue is also the send log, and the webhook copy of an
    //  order holds the customer's name, email and address.
    // -----------------------------------------------------------------------
    if (array_key_exists('prune', $options)) {
        $days    = max(1, setting_int('integration_queue_retention_days', 30));
        $deleted = integration_queue_prune($days);
        $say(sprintf('Pruned %d finished event(s) older than %d day(s).', $deleted, $days));
    }

    exit($exit);
} catch (Throwable $e) {
    $shout('Worker failed: ' . $e->getMessage());
    exit(1);
} finally {
    Database::query("SELECT RELEASE_LOCK('sik_integration_worker')");
}
