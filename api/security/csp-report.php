<?php
/**
 * ShopInnKart - Content-Security-Policy violation collector.
 *
 * The site has shipped a policy in report-only mode for a while with nowhere
 * to report to, which meant violations were visible in each visitor's console
 * and nowhere else. This is the "somewhere else".
 *
 * ---------------------------------------------------------------------------
 * Why this endpoint has no CSRF token, no login and no session
 * ---------------------------------------------------------------------------
 * The caller is the BROWSER's policy engine, not the page. It posts on its own
 * with a Content-Type of its own choosing and will not attach anything this
 * app asks for. So the endpoint is open by construction, and every guard here
 * is about what an open endpoint can be made to do:
 *
 *   - It can be FLOODED. Capped per address per hour, and capped again by the
 *     number of distinct violation groups the table will ever learn
 *     (csp_report_record()). One visitor with a misbehaving extension fires
 *     the same violation on every page load; that has to be one row with a
 *     counter, not a row per page view.
 *   - It can be fed a HUGE body. Read through a hard byte cap, never
 *     file_get_contents() of whatever arrived.
 *   - It can be fed LIES. Nothing here is trusted as fact: a report is a
 *     browser's claim, so it is stored as a claim, shown as a claim, and the
 *     only decision taken from it - the automatic revert - needs several
 *     DIFFERENT first-party violations before it acts.
 *
 * It answers 204 to everything, always. A browser does not read the body and
 * an error status would only teach a prober that this path is interesting.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/init.php';

/** 204 and stop. Used for every exit, wanted or not. */
$done = static function (): void {
    if (!headers_sent()) {
        http_response_code(204);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
    }
    exit;
};

// The session is of no use to a report and holding its lock would serialise a
// burst of them behind the visitor's own page loads.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    $done();
}

if (!setting_bool('sec_csp_collect', true)) {
    $done();
}

// ---------------------------------------------------------------------------
// Flood control, before anything is parsed
// ---------------------------------------------------------------------------
$perHour = max(1, setting_int('sec_csp_report_max', 60));
if (!rate_limit_attempt('csp.report', client_ip(), $perHour, 3600)) {
    $done();
}

// ---------------------------------------------------------------------------
// The body, read through a cap
// ---------------------------------------------------------------------------
const CSP_REPORT_MAX_BYTES = 16384;

if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > CSP_REPORT_MAX_BYTES) {
    $done();
}

$handle = @fopen('php://input', 'rb');
if ($handle === false) {
    $done();
}
// One byte over the cap is enough to know it was over the cap.
$raw = (string) stream_get_contents($handle, CSP_REPORT_MAX_BYTES + 1);
fclose($handle);

if ($raw === '' || strlen($raw) > CSP_REPORT_MAX_BYTES) {
    $done();
}

$payload = json_decode($raw, true);
if (!is_array($payload)) {
    $done();
}

// ---------------------------------------------------------------------------
// Two shapes arrive here.
//   report-uri  (Safari, older Chrome):  {"csp-report": {...}}
//   report-to   (current Chrome):        [{"type":"csp-violation","body":{...}}, ...]
// ---------------------------------------------------------------------------
$reports = [];

if (isset($payload['csp-report']) && is_array($payload['csp-report'])) {
    $reports[] = $payload['csp-report'];
} elseif (array_is_list($payload)) {
    foreach ($payload as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        if ((string) ($entry['type'] ?? 'csp-violation') !== 'csp-violation') {
            continue;   // the Reporting API also carries deprecation and crash reports
        }
        $reports[] = is_array($entry['body'] ?? null) ? $entry['body'] : $entry;
    }
} else {
    // A bare body, which some browsers and most test tools send.
    $reports[] = $payload;
}

// A single POST may not describe a hundred violations.
$reports = array_slice($reports, 0, 10);

$firstParty = false;
foreach ($reports as $report) {
    $stored = csp_report_record($report);
    $firstParty = $firstParty || ($stored['stored'] && $stored['first_party']);
}

// ---------------------------------------------------------------------------
// The safety net
//
// Only worth asking when this batch contained a first-party violation, so the
// ordinary case - a blocked third-party tracker - costs nothing extra.
// ---------------------------------------------------------------------------
if ($firstParty) {
    try {
        csp_enforce_autorevert_check();
    } catch (Throwable $e) {
        ErrorHandler::log('warning', 'CSP auto-revert check failed: ' . $e->getMessage());
    }
}

$done();
