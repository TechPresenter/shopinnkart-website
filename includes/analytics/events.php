<?php
/**
 * ShopInnKart - Server-side analytics events.
 *
 * WHY THE SERVER, NOT THE BROWSER
 * -------------------------------
 * Every number on this list decides something: which products get restocked,
 * which coupon gets renewed, whether a channel paid for itself. A browser
 * event is missing whenever an ad blocker, a dropped beacon or a closed tab
 * says so - between a fifth and a third of Indian mobile traffic, on the
 * measurements in analytics-data/NOTES.md - and it is forgeable by anyone with
 * a console. So `add_to_cart` is written where the row is written, `purchase`
 * where the order is committed, and the browser is never asked about money.
 *
 * What the browser still reports (outbound clicks, downloads, shares, popup
 * views) is in AN_CLIENT_EVENTS and cannot name a price.
 *
 * THREE RULES THIS FILE KEEPS
 * ---------------------------
 *   1. It never throws. A checkout must not fail because a counter did.
 *   2. It never runs inside a transaction. An analytics INSERT inside the
 *      order transaction would hold the row locks it takes for as long as the
 *      analytics write takes, and a rollback would silently undo the order.
 *      The guard below makes that impossible rather than merely documented.
 *   3. It never creates a session. A shopper whose beacon never arrived has no
 *      session, and the event is stored with session_id NULL - "unattributed",
 *      which still counts for the product and is honest about the visit.
 */

declare(strict_types=1);

require_once __DIR__ . '/collect.php';
require_once __DIR__ . '/session.php';

/** Funnel bits an event sets on its session: 1 view, 2 cart, 4 checkout, 8 purchase. */
const AN_FUNNEL_BITS = [
    'add_to_cart'    => 2,
    'begin_checkout' => 4,
    'purchase'       => 8,
];

/**
 * Record one event. Fire and forget: no return value, nothing to check.
 *
 * @param string $name one of AN_EVENT_IDS
 * @param array  $props product_id, combo_id, order_id, qty, value, label
 */
function analytics_event(string $name, array $props = []): void
{
    try {
        $eventId = an_event_id($name);
        if ($eventId === 0) {
            return;
        }

        if (!analytics_tracking_allowed() || !analytics_tables_ready()) {
            return;
        }

        // Rule 2. Callers are expected to fire after the commit; this catches
        // the one that forgets, at the cost of losing that event.
        if (Database::inTransaction()) {
            ErrorHandler::log('debug', 'analytics_event(' . $name . ') skipped: called inside a transaction');
            return;
        }

        $session = analytics_current_session();

        Database::insert('an_events', [
            'session_id' => $session > 0 ? $session : null,
            'created_at' => date('Y-m-d H:i:s'),
            'name'       => $eventId,
            'product_id' => ($props['product_id'] ?? 0) > 0 ? (int) $props['product_id'] : null,
            'combo_id'   => ($props['combo_id'] ?? 0) > 0 ? (int) $props['combo_id'] : null,
            'order_id'   => ($props['order_id'] ?? 0) > 0 ? (int) $props['order_id'] : null,
            'qty'        => isset($props['qty']) ? max(0, min(65535, (int) $props['qty'])) : null,
            'value'      => isset($props['value']) ? round((float) $props['value'], 2) : null,
            // Never shopper free text: a coupon code, an outbound host, a
            // payment method. Search terms stay in search_logs.
            'label'      => isset($props['label']) ? mb_substr((string) $props['label'], 0, 100) : null,
        ]);

        $bit = AN_FUNNEL_BITS[$name] ?? 0;
        if ($session > 0 && $bit > 0) {
            Database::query(
                'UPDATE `an_sessions` SET `funnel` = `funnel` | :b, `last_seen_at` = NOW() WHERE `id` = :id',
                ['b' => $bit, 'id' => $session]
            );
        }
    } catch (Throwable $e) {
        // Rule 1.
        ErrorHandler::log('warning', 'analytics_event(' . $name . ') failed: ' . $e->getMessage());
    }
}

/**
 * The session id this request belongs to, or 0.
 *
 * Resolved from the same visitor key the collector uses, so an event fired by
 * a page lands on the session that page's beacon created. It never creates
 * one: a session that exists only because somebody added to a cart would have
 * no landing page, no source and no device, and would poison every rollup it
 * appeared in.
 */
function analytics_current_session(): int
{
    static $id = null;

    if ($id !== null) {
        return $id;
    }

    $id = 0;

    try {
        $vkey    = analytics_request_vkey();
        $session = $vkey === '' ? null : an_session_find($vkey);
        $id      = $session === null ? 0 : (int) $session['id'];
    } catch (Throwable $e) {
        ErrorHandler::log('warning', 'analytics session lookup failed: ' . $e->getMessage());
    }

    return $id;
}

/** This request's visitor key, from the cookie when consented, else the daily hash. */
function analytics_request_vkey(): string
{
    $stored = consent_stored();
    $vid    = ($stored !== null && $stored['analytics']) ? (string) ($_COOKIE[AN_VID_COOKIE] ?? '') : '';

    return an_visitor_key(
        client_ip(),
        (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
        analytics_own_host(),
        $vid !== '' ? $vid : null
    );
}

/** Our own host, lower-cased, used by the source classifier and the visitor key. */
function analytics_own_host(): string
{
    $host = (string) (parse_url(SITE_URL, PHP_URL_HOST) ?: '');

    return strtolower($host !== '' ? $host : (string) ($_SERVER['HTTP_HOST'] ?? ''));
}

// ===========================================================================
//  Purchase: the one event with money in it
// ===========================================================================

/**
 * Called after create_order() has COMMITTED, never before.
 *
 * Writes the event and stamps the session with the order, so a session row
 * answers "did this visit buy, and for how much" without a join. Revenue is
 * taken from the order row the database just wrote - not from the cart, not
 * from the client, not from a total computed a second time.
 */
function analytics_record_purchase(array $order): void
{
    try {
        if (!analytics_tracking_allowed() || !analytics_tables_ready()) {
            return;
        }

        $orderId = (int) ($order['id'] ?? 0);
        if ($orderId <= 0) {
            return;
        }

        // `total_amount` is the orders table's own column - the number the
        // customer was actually charged, read back from the row the database
        // just committed. Not the cart, not a total computed a second time,
        // and not anything the browser said: a revenue figure that can differ
        // from the invoice is a revenue figure nobody can defend.
        $revenue = round((float) ($order['total_amount'] ?? 0), 2);
        $units   = (int) Database::fetchColumn(
            'SELECT COALESCE(SUM(`quantity`), 0) FROM `order_items` WHERE `order_id` = :id',
            ['id' => $orderId]
        );

        analytics_event('purchase', [
            'order_id' => $orderId,
            'value'    => $revenue,
            'qty'      => $units,
            'label'    => (string) ($order['payment_method'] ?? ''),
        ]);

        $session = analytics_current_session();
        if ($session > 0) {
            // funnel bit 8 is already set by analytics_event(); this adds the
            // money. Revenue accumulates, because a visit that places two
            // orders really did bring both; order_id keeps the FIRST one, so
            // the "which visit produced this order" link stays single-valued
            // and the second order is still reachable through an_events.
            Database::query(
                'UPDATE `an_sessions`
                    SET `order_id` = COALESCE(`order_id`, :oid),
                        `revenue`  = `revenue` + :rev,
                        `last_seen_at` = NOW()
                  WHERE `id` = :id',
                ['oid' => $orderId, 'rev' => $revenue, 'id' => $session]
            );
        }
    } catch (Throwable $e) {
        ErrorHandler::log('warning', 'analytics purchase record failed: ' . $e->getMessage());
    }
}

/**
 * begin_checkout, at most once per session.
 *
 * checkout.php is re-rendered on every validation error and every back
 * button, and a funnel where "began checkout" outnumbers "added to cart" is a
 * funnel nobody trusts. The session's own funnel bit is the memo, so this
 * needs no session variable and survives a new browser tab.
 */
function analytics_record_begin_checkout(): void
{
    try {
        if (!analytics_tracking_allowed() || !analytics_tables_ready()) {
            return;
        }

        $session = analytics_current_session();
        if ($session > 0) {
            $funnel = (int) Database::fetchColumn('SELECT `funnel` FROM `an_sessions` WHERE `id` = :id', ['id' => $session]);
            if (($funnel & 4) === 4) {
                return;
            }
        }

        analytics_event('begin_checkout', []);
    } catch (Throwable $e) {
        ErrorHandler::log('warning', 'analytics begin_checkout failed: ' . $e->getMessage());
    }
}
