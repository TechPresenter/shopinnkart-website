<?php
/**
 * ShopInnKart - Courier tracking poller (CLI).
 *
 * Webhooks are the fast path, but not a sufficient one: a courier whose
 * webhook was never registered, a push lost to a deploy or an outage, or a
 * courier with no webhooks at all leaves a parcel frozen at its last status -
 * and "delivered" is what marks a COD order paid, "RTO delivered" what puts
 * the stock back. This asks the courier directly, through the same
 * shipping_refresh_tracking() the admin's Refresh button uses, so a poll and a
 * webhook for the same scan are one event, never two.
 *
 * What it polls: shipments that are not finished (delivered, RTO delivered,
 * returned, cancelled, booking failed) and have an AWB - or, for a courier
 * whose driver can track by shipment reference (Shiprocket), a booking that
 * has a reference but no AWB yet, so an AWB assigned in the courier's panel
 * or by a request whose answer was lost is picked up without a webhook. Only
 * couriers that still have a driver, whatever the integration's Active switch
 * or mode says, because "inactive" stops new bookings, not the parcels
 * already out. Oldest first, a batch per run, carrying on from where the last
 * run stopped, so a backlog larger than one batch is still covered in turn
 * rather than the same oldest rows forever.
 *
 * What it does NOT poll: a simulator. The mock driver invents its own scans
 * from the clock - forty hours after booking it reports "delivered" - so a
 * cron that asked it would walk every leftover test order to delivered, mark
 * its COD payment paid and email the customer with nobody pressing anything.
 * The admin's Refresh button still polls it, one shipment at a time, and
 * --simulated asks for it explicitly on a test bench. Never put that flag in
 * the cron line.
 *
 * It also re-settles, without calling any courier, a shipment that has already
 * finished while its order has not caught up: when the courier call committed
 * the shipment but moving the order failed (a lock, a deadlock), the money and
 * the stock stay out of step until someone presses Refresh. Re-reading the
 * stored timeline is all that repairs it, so the poller keeps those in its
 * candidate set until the order agrees.
 *
 * A courier that is down is abandoned for the rest of the run after a few
 * consecutive failures, rather than being asked once per shipment: a failed
 * Shiprocket login caches nothing, so 50 shipments meant 50 logins into a rate
 * limit, and 20s timeouts made the run outlast its own schedule.
 *
 * Schedule it every 30 minutes:
 *
 *   Linux crontab:
 *     0,30 * * * * /usr/bin/php /path/to/ecomweb/bin/refresh-shipments.php --quiet >> /path/to/ecomweb/storage/logs/shipment-poll.log 2>&1
 *
 *   Windows Task Scheduler (XAMPP):
 *     Program:   C:\xampp\php\php.exe
 *     Arguments: C:\xampp\htdocs\ecomweb\bin\refresh-shipments.php --quiet
 *     Trigger:   daily, repeat every 30 minutes
 *
 * Options:
 *   --limit=N    shipments per run (default 50, at most 500)
 *   --ids=1,2,3  poll only these shipments (still skipping finished ones)
 *   --simulated  also poll simulator couriers (the mock). Test bench only.
 *   --dry-run    list what would be polled; call no courier
 *   --json       print the summary as one line of JSON
 *   --quiet      only print on error
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script is CLI only.');
}

require_once dirname(__DIR__) . '/includes/init.php';
require_once INCLUDES_PATH . '/shipping-service.php';

// ---------------------------------------------------------------------------
//  Arguments
// ---------------------------------------------------------------------------
$options = getopt('', ['limit::', 'ids::', 'simulated', 'dry-run', 'json', 'quiet']);

$limit  = isset($options['limit']) ? max(1, min(500, (int) $options['limit'])) : 50;
$dryRun = array_key_exists('dry-run', $options);
$asJson = array_key_exists('json', $options);
$quiet  = array_key_exists('quiet', $options) || $asJson;
$withSim = array_key_exists('simulated', $options);
$ids    = isset($options['ids'])
    ? array_values(array_filter(array_map('intval', explode(',', (string) $options['ids']))))
    : [];

// How many couriers in a row may fail before this run gives that courier up.
// Small, because the failure it exists for - a login that is down - answers
// the same for every shipment, slowly.
const POLL_MAX_CONSECUTIVE_FAILURES = 3;

$say = static function (string $message) use ($quiet): void {
    if (!$quiet) {
        echo '[', date('Y-m-d H:i:s'), '] ', $message, PHP_EOL;
    }
};

$summary = [
    'database' => DB_NAME, 'dry_run' => $dryRun, 'locked' => false,
    'selected' => [], 'polled' => 0, 'updated' => 0, 'events' => 0, 'unchanged' => 0, 'failed' => 0,
    'resettle' => [], 'resettled' => 0,
    'skipped_no_driver' => 0, 'skipped_simulated' => 0, 'skipped_after_failures' => 0,
    'abandoned' => [], 'pruned_logs' => 0, 'errors' => [],
];
$finish = static function (array $summary, int $exitCode) use ($asJson): void {
    if ($asJson) {
        echo json_encode($summary, JSON_UNESCAPED_SLASHES), PHP_EOL;
    }
    exit($exitCode);
};

// ---------------------------------------------------------------------------
//  Only one poll at a time
// ---------------------------------------------------------------------------
// Two overlapping runs would ask the courier twice for every shipment and race
// each other through the same status moves; with a slow courier API a run can
// outlast the schedule. flock() is released by the OS if the process dies, so
// a crashed run never leaves a stale lock behind. The file also carries the
// cursor between runs.
$lockPath = STORAGE_PATH . '/refresh-shipments.lock';
$lock     = @fopen($lockPath, 'c+');
if ($lock === false) {
    fwrite(STDERR, sprintf('[%s] Cannot open %s.%s', date('Y-m-d H:i:s'), $lockPath, PHP_EOL));
    $finish($summary + ['error' => 'lock file'], 1);
}
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    $say('Another poll is running — exiting.');
    $summary['locked'] = true;
    $finish($summary, 0);
}

$exitCode = 0;
try {
    $state  = json_decode((string) stream_get_contents($lock), true);
    $cursor = $ids === [] ? (int) ($state['cursor'] ?? 0) : 0;

    // -----------------------------------------------------------------------
    //  What is due
    // -----------------------------------------------------------------------
    $finished = array_values(array_filter(array_keys(shipping_statuses()), 'shipping_is_terminal'));
    [$doneIn, $doneParams] = Database::inPlaceholders($finished, 'done');

    // Couriers this run may talk to. A simulator is left out unless asked for:
    // its "scans" are invented from the clock, so an unattended run would walk
    // leftover test orders to delivered by itself.
    $pollCodes = $withSim ? ShippingProviderFactory::implementedCodes() : shipping_real_codes();
    [$codeIn, $codeParams] = Database::inPlaceholders($pollCodes, 'code');
    $codeIn = $pollCodes === [] ? "''" : $codeIn;

    $trackable = "(s.`awb` IS NOT NULL AND s.`awb` <> '')";
    $params    = $doneParams;

    // An AWB-less booking is asked about only where the driver can track it
    // by reference. Any other courier would answer "no AWB" on every run, a
    // failure in the log each time for a booking that is simply not ready.
    $refCodes = array_values(array_filter($pollCodes,
        static fn (string $code): bool => shipping_tracks_by_reference($code)));
    if ($refCodes !== []) {
        [$refIn, $refParams] = Database::inPlaceholders($refCodes, 'ref');
        $trackable = "($trackable OR (s.`shipment_ref` IS NOT NULL AND s.`shipment_ref` <> '' AND s.`provider_code` IN ($refIn)))";
        $params   += $refParams;
    }

    $where = "$trackable AND s.`status` NOT IN ($doneIn)";
    if ($ids !== []) {
        [$idIn, $idParams] = Database::inPlaceholders($ids, 'sid');
        $where .= " AND s.`id` IN ($idIn)";
        $params += $idParams;
    }

    // A courier whose driver was removed cannot be asked; say how many, once.
    [$allIn, $allParams] = Database::inPlaceholders(ShippingProviderFactory::implementedCodes(), 'inst');
    $summary['skipped_no_driver'] = (int) Database::fetchColumn(
        "SELECT COUNT(*) FROM `shipments` s WHERE $where AND s.`provider_code` NOT IN ($allIn)",
        $params + $allParams
    );
    // And how many were left to the test bench, so a run is never silently empty.
    $summary['skipped_simulated'] = (int) Database::fetchColumn(
        "SELECT COUNT(*) FROM `shipments` s WHERE $where AND s.`provider_code` IN ($allIn) AND s.`provider_code` NOT IN ($codeIn)",
        $params + $allParams + $codeParams
    );

    $due = static function (int $afterId, int $take) use ($where, $params, $codeIn, $codeParams): array {
        return Database::fetchColumnAll(
            "SELECT s.`id` FROM `shipments` s
              WHERE $where AND s.`provider_code` IN ($codeIn) AND s.`id` > :after
              ORDER BY s.`id` ASC LIMIT " . $take,
            $params + $codeParams + ['after' => $afterId]
        );
    };

    // Oldest first from the cursor; when that runs out, wrap to the start.
    $selected = array_map('intval', $due($cursor, $limit));
    if (count($selected) < $limit && $cursor > 0) {
        $wrapped  = array_map('intval', $due(0, $limit - count($selected)));
        $selected = array_values(array_unique(array_merge($selected, array_filter($wrapped, static fn (int $id): bool => $id <= $cursor))));
    }
    $summary['selected'] = $selected;

    // -----------------------------------------------------------------------
    //  What has finished but never settled its order
    // -----------------------------------------------------------------------
    // A shipment can reach a terminal status and still leave its order behind:
    // the courier call commits the shipment and its events, then moving the
    // order fails on a lock, and nothing retries. The parcel is back in the
    // warehouse while the COD is still booked as collected and the stock still
    // sold. No courier call repairs this - re-reading the stored timeline
    // does - so these stay in the candidate set until the order agrees.
    $settleParts   = [];
    $settleParams  = [];
    $settleStatuses = [];
    foreach (['delivered', 'rto_delivered'] as $i => $shipStatus) {
        $target = shipping_order_status_for($shipStatus);
        $behind = array_values(array_filter(
            array_keys(ORDER_STATUSES),
            static fn (string $status): bool =>
                // Cancelled and refunded are not "behind": a courier may not
                // un-cancel an order, and shipping_sync_order() says so loudly
                // instead of moving it.
                !in_array($status, [ORDER_STATUS_CANCELLED, ORDER_STATUS_REFUNDED], true)
                && shipping_order_rank($status) >= 0
                && shipping_order_rank($status) < shipping_order_rank((string) $target)
        ));
        if ($behind === []) {
            continue;
        }
        [$behindIn, $behindParams] = Database::inPlaceholders($behind, 'beh' . $i);
        $settleParts[]     = "(s.`status` = :sst$i AND o.`status` IN ($behindIn))";
        $settleParams     += $behindParams + ['sst' . $i => $shipStatus];
        $settleStatuses[]  = $shipStatus;
    }

    $resettle = [];
    if ($settleParts !== []) {
        // Only the consignment that currently speaks for the order, by the
        // same rule shipment_live_for_order() applies - re-settling any other
        // one is a no-op that would keep it in the candidate set forever.
        // The status first, so the index on it narrows the table before the
        // per-row subquery below is ever run.
        [$sstIn, $sstParams] = Database::inPlaceholders($settleStatuses, 'sin');
        $settleWhere = "s.`status` IN ($sstIn) AND s.`provider_code` IN ($codeIn)
              AND s.`id` = (SELECT MAX(x.`id`) FROM `shipments` x
                             WHERE x.`order_id` = s.`order_id` AND x.`status` NOT IN ('cancelled', 'failed_booking'))
              AND EXISTS (SELECT 1 FROM `orders` o
                           WHERE o.`id` = s.`order_id`
                             AND (" . implode(' OR ', $settleParts) . '))';
        $settleArgs  = $codeParams + $settleParams + $sstParams;
        if ($ids !== []) {
            [$idIn2, $idParams2] = Database::inPlaceholders($ids, 'ssid');
            $settleWhere .= " AND s.`id` IN ($idIn2)";
            $settleArgs  += $idParams2;
        }
        $resettle = array_map('intval', Database::fetchColumnAll(
            "SELECT s.`id` FROM `shipments` s WHERE $settleWhere ORDER BY s.`id` ASC LIMIT " . $limit,
            $settleArgs
        ));
    }
    $summary['resettle'] = $resettle;

    // -----------------------------------------------------------------------
    //  Poll
    // -----------------------------------------------------------------------
    // A courier that has just failed this many times in a row is down, not
    // unlucky: asking it once per remaining shipment only repeats a slow
    // failure - and for Shiprocket, a login attempt per shipment into the very
    // rate limit its token cache exists to respect.
    $streak = [];
    foreach ($selected as $shipmentId) {
        if ($dryRun) {
            $say('Would poll shipment #' . $shipmentId . '.');
            continue;
        }

        $code = (string) (shipment_get($shipmentId)['provider_code'] ?? '');
        if (($streak[$code] ?? 0) >= POLL_MAX_CONSECUTIVE_FAILURES) {
            $summary['skipped_after_failures']++;
            $summary['abandoned'][$code] = ($summary['abandoned'][$code] ?? 0) + 1;
            continue;
        }

        $summary['polled']++;
        try {
            $res = shipping_refresh_tracking($shipmentId);
        } catch (Throwable $e) {
            $res = ['ok' => false, 'message' => $e->getMessage()];
        }

        if (!empty($res['ok'])) {
            $streak[$code] = 0;
            $added = (int) ($res['added'] ?? 0);
            // An AWB picked up from the courier is an update even when no new
            // scan came with it.
            $summary[$added > 0 || !empty($res['awb_adopted']) ? 'updated' : 'unchanged']++;
            $summary['events'] += $added;
        } else {
            $streak[$code] = ($streak[$code] ?? 0) + 1;
            $summary['failed']++;
            $summary['errors'][$shipmentId] = mb_substr((string) ($res['message'] ?? 'unknown error'), 0, 200);
            if ($streak[$code] === POLL_MAX_CONSECUTIVE_FAILURES) {
                $say($code . ' failed ' . POLL_MAX_CONSECUTIVE_FAILURES . ' times in a row — leaving the rest of its shipments to the next run.');
            }
        }
    }

    // -----------------------------------------------------------------------
    //  Re-settle what finished without its order
    // -----------------------------------------------------------------------
    // No courier is called: the events are already stored, and reading them
    // again is what moves the order, restores the stock and un-collects a COD
    // that never was. Idempotent, so a shipment that is in fact in step is a
    // no-op and drops out of the candidate set on the next run.
    foreach ($resettle as $shipmentId) {
        if ($dryRun) {
            $say('Would re-settle shipment #' . $shipmentId . ' (its order is out of step).');
            continue;
        }
        try {
            shipping_record_events($shipmentId, [], 'poll');
            $summary['resettled']++;
        } catch (Throwable $e) {
            $summary['failed']++;
            $summary['errors'][$shipmentId] = 'Re-settle failed: ' . mb_substr($e->getMessage(), 0, 180);
        }
    }

    // The cursor moves only on a real, unfiltered run.
    if (!$dryRun && $ids === []) {
        $last = $selected === [] ? 0 : (int) end($selected);
        ftruncate($lock, 0);
        rewind($lock);
        fwrite($lock, (string) json_encode(['cursor' => $last, 'finished_at' => date('Y-m-d H:i:s')]));
        fflush($lock);
    }

    // Housekeeping, while a cron is here anyway: the API log is written by an
    // unauthenticated endpoint too, so it needs a ceiling in time.
    if (!$dryRun) {
        $summary['pruned_logs'] = shipping_prune_logs();
    }

    $line = sprintf(
        '%s %d shipment(s): %d updated (%d new event(s)), %d unchanged, %d failed, %d re-settled; %d skipped (no driver installed), %d left to the bench (simulator), %d left to the next run (courier down).',
        $dryRun ? 'Would poll' : 'Polled',
        $dryRun ? count($selected) : $summary['polled'],
        $summary['updated'],
        $summary['events'],
        $summary['unchanged'],
        $summary['failed'],
        $dryRun ? count($resettle) : $summary['resettled'],
        $summary['skipped_no_driver'],
        $summary['skipped_simulated'],
        $summary['skipped_after_failures']
    );
    $say($line);
    if (!$dryRun && ($summary['polled'] > 0 || $summary['resettled'] > 0 || $summary['skipped_no_driver'] > 0)) {
        ErrorHandler::log($summary['failed'] > 0 ? 'warning' : 'info', 'Shipment poll: ' . $line
            . ($summary['errors'] !== [] ? ' ' . json_encode($summary['errors'], JSON_UNESCAPED_SLASHES) : ''));
    }
    foreach ($summary['errors'] as $shipmentId => $message) {
        fwrite(STDERR, sprintf('  ! shipment #%d: %s%s', $shipmentId, $message, PHP_EOL));
    }
} catch (Throwable $e) {
    fwrite(STDERR, sprintf('[%s] Poll failed: %s%s', date('Y-m-d H:i:s'), $e->getMessage(), PHP_EOL));
    $summary['errors'][] = $e->getMessage();
    $exitCode = 1;
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}

$finish($summary, $exitCode);
