<?php
/**
 * ShopInnKart - Shared, database-backed rate limiter.
 *
 * Replaces limiters that kept their counters in $_SESSION (a caller resets
 * them by dropping the cookie) or in the file cache (switched off along with
 * the cache). Counters live in `rate_limits`, keyed by a bucket name and a
 * hashed key - an IP, an account, "ip|account" - so a limit holds across
 * sessions, cookies and requests.
 *
 * Sliding-window approximation over two fixed windows: the previous window's
 * count is weighted by how much of it still overlaps "the last N seconds".
 * One upsert and one read per check, no daemon, safe under concurrency (the
 * increment is a single atomic UPDATE).
 *
 * Fails OPEN when the table is missing or the database errors: a limiter that
 * locks every customer out because of a hiccup is worse than one that briefly
 * lets an attacker through. Each failure is logged.
 */

declare(strict_types=1);

/**
 * Record one attempt and say whether it is allowed.
 *
 * @return bool true = allowed (under the limit), false = over the limit
 */
function rate_limit_attempt(string $bucket, string $key, int $max, int $windowSeconds): bool
{
    return rate_limit_count($bucket, $key, $windowSeconds, true) <= $max;
}

/** Would another attempt be allowed? Records nothing. */
function rate_limit_allows(string $bucket, string $key, int $max, int $windowSeconds): bool
{
    return rate_limit_count($bucket, $key, $windowSeconds, false) < $max;
}

/** Forget a key, e.g. after a successful login clears its failure count. */
function rate_limit_clear(string $bucket, string $key): void
{
    try {
        Database::query('DELETE FROM `rate_limits` WHERE `bucket` = :b AND `key_hash` = :k',
            ['b' => $bucket, 'k' => rate_limit_hash($key)]);
    } catch (Throwable $e) {
        ErrorHandler::log('warning', 'rate_limit_clear failed: ' . $e->getMessage());
    }
}

/** Seconds until a blocked key can try again (upper bound). */
function rate_limit_retry_after(int $windowSeconds): int
{
    return max(1, $windowSeconds - (time() % max(1, $windowSeconds)));
}

/**
 * The weighted number of attempts in the last $windowSeconds, optionally
 * counting a new one first.
 */
function rate_limit_count(string $bucket, string $key, int $windowSeconds, bool $record): float
{
    $window = max(1, $windowSeconds);
    $now    = time();
    $start  = $now - ($now % $window);
    $hash   = rate_limit_hash($key);

    try {
        $mine = null;   // this attempt's own position in the window, when recording
        if ($record) {
            // LAST_INSERT_ID(expr) hands back the value THIS statement wrote,
            // so twelve concurrent attempts against a limit of five see 1..12
            // and exactly five pass. Reading the row afterwards instead lets
            // each one see the others' increments too, and fewer than five pass.
            $stmt = Database::query(
                'INSERT INTO `rate_limits` (`bucket`, `key_hash`, `window_start`, `hits`, `expires_at`)
                 VALUES (:b, :k, :w, 1, :e)
                 ON DUPLICATE KEY UPDATE `hits` = LAST_INSERT_ID(`hits` + 1)',
                ['b' => $bucket, 'k' => $hash, 'w' => $start, 'e' => date('Y-m-d H:i:s', $start + 2 * $window)]
            );
            // 1 row affected = inserted (this is the first hit), 2 = updated.
            $mine = $stmt->rowCount() === 1 ? 1 : (int) Database::fetchColumn('SELECT LAST_INSERT_ID()');
        }

        $rows = Database::fetchAll(
            'SELECT `window_start`, `hits` FROM `rate_limits`
              WHERE `bucket` = :b AND `key_hash` = :k AND `window_start` IN (:cur, :prev)',
            ['b' => $bucket, 'k' => $hash, 'cur' => $start, 'prev' => $start - $window]
        );

        $current = 0;
        $previous = 0;
        foreach ($rows as $row) {
            if ((int) $row['window_start'] === $start) {
                $current = (int) $row['hits'];
            } else {
                $previous = (int) $row['hits'];
            }
        }
        if ($mine !== null) {
            $current = $mine;
        }

        // Housekeeping on roughly 1 call in 100, bounded so it never stalls a request.
        if (random_int(1, 100) === 1) {
            Database::query('DELETE FROM `rate_limits` WHERE `expires_at` < NOW() LIMIT 500');
        }

        $overlap = 1 - (($now - $start) / $window);
        return $current + $previous * $overlap;
    } catch (Throwable $e) {
        ErrorHandler::log('warning', 'Rate limiter unavailable (failing open) for ' . $bucket . ': ' . $e->getMessage());
        return 0.0;
    }
}

function rate_limit_hash(string $key): string
{
    // Hashed so the table never holds raw emails or IPs, and fixed width for the index.
    return sha1('rl|' . $key);
}
