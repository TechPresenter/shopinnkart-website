<?php
/**
 * ShopInnKart - Popup impression / conversion counter.
 *
 * Fired from assets/js/app.js the moment a popup opens (impression) and when
 * its call-to-action is clicked (conversion). The two counters are what
 * Admin > Marketing > Popups reports a conversion rate from.
 *
 * ---------------------------------------------------------------------------
 * Why this endpoint IS CSRF-gated, despite being "just a counter"
 * ---------------------------------------------------------------------------
 * It used to run without a gate, on the reasoning that a beacon should not fail
 * over a stale token. Two facts retired that argument:
 *
 *  1. It is not a beacon. Nothing here uses navigator.sendBeacon() - the caller
 *     is SIK.post() in assets/js/app.js, an ordinary fetch() that already
 *     attaches the token twice, as the X-CSRF-Token header and as csrf_token in
 *     the JSON body. The token was on the wire the whole time and was simply
 *     not being read, so the gate costs the real caller nothing.
 *
 *  2. Without it the counters were writable by anyone. request_input() falls
 *     back to $_POST, so a plain application/x-www-form-urlencoded POST - a
 *     CORS "simple request", no preflight, no cookie, no same-origin check -
 *     incremented any popup from any third-party page. Measured before the
 *     gate: three such POSTs from a forged Origin took popup 4 from 1
 *     impression to 4. That is not a security boundary being crossed, but it is
 *     a merchandising decision being made on numbers a stranger can write.
 *
 * The cost of the gate is that a token which rotated while the page sat open
 * loses one increment. That is acceptable: api_rate_limit() below already caps
 * this at 30 writes a minute, so these counts are sampled rather than exact by
 * design, and a lost increment is strictly better than an invented one.
 *
 * If a future caller genuinely needs sendBeacon() - which cannot set headers -
 * it can still send the token: sendBeacon() accepts a URLSearchParams body,
 * which arrives as $_POST and is the first thing csrf_supplied_token() checks.
 *
 * ---------------------------------------------------------------------------
 * Why it also refuses a popup that could not have rendered
 * ---------------------------------------------------------------------------
 * The CSRF gate stops a stranger writing these numbers, but it does not stop a
 * signed-in visitor's own token being replayed at a popup the shop is no longer
 * showing. Measured: with Settings > Widgets > "Show popups" off - so shop.php
 * carried zero popup nodes - a POST with a valid token still took popup 1 from
 * 239 impressions to 240, and an `inactive` popup took increments too. A switch
 * that removes the UI but leaves the endpoint writing is not a switch.
 *
 * The guards below are the same four active_popups() applies before a popup is
 * printed: the per-mode master switch, `status`, and both ends of the schedule.
 * They cannot cost the honest caller anything, because app.js only fires for a
 * popup active_popups() already returned. The one loss is a popup switched off
 * between render and click, which is the same one-increment race the CSRF note
 * above accepts for the same reason.
 *
 * Targeting (display_pages, device_visibility, auth_visibility) is deliberately
 * NOT re-checked here: those depend on the page and the request that drew the
 * popup, and re-deriving them from this request would reject legitimate
 * conversions fired from a route the visitor has since navigated away from.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/init.php';

api_require_method(['POST']);
api_require_csrf();
api_rate_limit('popup_track', 30, 60);

$popupId = request_int('id');
$event = (string) request_input('event', 'impression');

if ($popupId <= 0) {
    json_validation_error(['id' => 'A popup id is required.']);
}
if (!in_array($event, ['impression', 'conversion'], true)) {
    json_validation_error(['event' => 'Event must be impression or conversion.']);
}

// Only these two column names may ever reach the SQL string.
$column = $event === 'conversion' ? 'conversions' : 'impressions';

// The two master switches, read exactly as active_popups() reads them. Both off
// means nothing on the shop can have produced this call, so nothing is counted.
$modes = [];
if (setting_bool('popups_enabled', true)) {
    $modes['mode0'] = 'popup';
}
if (setting_bool('popins_enabled', true)) {
    $modes['mode1'] = 'popin';
}
if ($modes === []) {
    json_error('Popups are switched off.', [], 403);
}

// The placeholders come from the fixed allowlist directly above, never from the
// request, so the IN list is still a constant as far as the caller is concerned.
$modePlaceholders = implode(', ', array_map(static fn ($k) => ':' . $k, array_keys($modes)));

$updated = Database::query(
    'UPDATE `popups`
        SET `' . $column . '` = `' . $column . '` + 1
      WHERE `id` = :id
        AND `status` = \'active\'
        AND `display_mode` IN (' . $modePlaceholders . ')
        AND (`start_date` IS NULL OR `start_date` <= NOW())
        AND (`end_date`   IS NULL OR `end_date`   >= NOW())',
    ['id' => $popupId] + $modes
)->rowCount();

if ($updated === 0) {
    // One message for "no such id" and for "that popup is not running": the
    // caller can do nothing with either, and telling an anonymous POST which
    // ids exist is a free enumeration it does not need.
    json_error('That popup is not running.', [], 404);
}

json_success('Recorded.', ['id' => $popupId, 'event' => $event]);
