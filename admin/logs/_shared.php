<?php
/**
 * ShopInnKart Admin - Shared pieces for the three log screens.
 *
 * Activity, errors and login history all need the same date-range filter and
 * the same guarded "clear the log" action, so the guard lives in one place
 * rather than being re-typed (and eventually mis-typed) three times.
 */

declare(strict_types=1);

// Include-only: the parent page already ran authentication and permissions.
if (!defined('SIK_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

/** Date-range presets offered on every log screen. */
const LOG_RANGE_PRESETS = [
    ''           => 'Any date',
    'today'      => 'Today',
    'yesterday'  => 'Yesterday',
    'last_7'     => 'Last 7 days',
    'last_30'    => 'Last 30 days',
    'this_month' => 'This month',
    'last_month' => 'Last month',
    'this_year'  => 'This year',
    'custom'     => 'Custom range…',
];

/** How much of a log an admin may drop in one go. */
const LOG_CLEAR_SCOPES = [
    '30'  => 'Older than 30 days',
    '90'  => 'Older than 90 days',
    '365' => 'Older than 1 year',
    'all' => 'Everything in this log',
];

/** Only these tables can be targeted by the clear action. */
const LOG_TABLES = [
    'activity_logs' => 'activity log',
    'error_logs'    => 'error log',
    'login_history' => 'login history',
];

/**
 * Resolve the date filter into ['from' => 'Y-m-d', 'to' => 'Y-m-d'] or null
 * when the admin asked for any date.
 */
function logs_date_filter(): ?array
{
    $preset = admin_filter('range', array_keys(LOG_RANGE_PRESETS));
    if ($preset === '') {
        return null;
    }

    [$from, $to] = admin_date_range(
        $preset,
        (string) ($_GET['from'] ?? ''),
        (string) ($_GET['to'] ?? '')
    );

    return ['preset' => $preset, 'from' => $from, 'to' => $to];
}

/**
 * Handle the clear-log POST. Never returns when it fires.
 * POST + CSRF + logs.delete are all enforced by admin_require_action().
 */
function logs_handle_clear(string $table, string $redirectUrl): void
{
    if (!isset(LOG_TABLES[$table])) {
        throw new InvalidArgumentException('logs_handle_clear: unsupported table ' . $table);
    }

    admin_require_action('logs.delete');

    $label = LOG_TABLES[$table];
    $scope = (string) input('scope', '');
    if (!isset(LOG_CLEAR_SCOPES[$scope])) {
        flash('error', 'Choose how much of the ' . $label . ' to clear.');
        redirect($redirectUrl);
    }

    if ($scope === 'all') {
        // Database::delete() refuses an empty WHERE, so "everything" is spelled out.
        $removed = Database::delete($table, '1 = 1');
        $what = 'every entry';
    } else {
        // The scope comes from LOG_CLEAR_SCOPES, and the cast makes that
        // explicit — MariaDB will not take a bound parameter inside INTERVAL.
        $days = (int) $scope;
        $removed = Database::delete($table, "`created_at` < DATE_SUB(NOW(), INTERVAL {$days} DAY)");
        $what = 'entries older than ' . $days . ' day(s)';
    }

    log_activity('logs.cleared', $table, null,
        'Cleared ' . $what . ' from the ' . $label . ' (' . $removed . ' row(s))');
    admin_after_write();

    flash('success', $removed > 0
        ? number_format($removed) . ' entr' . ($removed === 1 ? 'y' : 'ies') . ' removed from the ' . $label . '.'
        : 'Nothing matched that range — the ' . $label . ' is unchanged.');
    redirect($redirectUrl);
}

/** The guarded clear-log card shown at the foot of each log screen. */
function logs_clear_card(string $table, string $actionUrl, int $total): string
{
    $label = LOG_TABLES[$table] ?? 'log';

    return '<div class="ad-card">'
        . '<div class="ad-card__head"><div>'
        . '<div class="ad-card__title">Clear the ' . e($label) . '</div>'
        . '<div class="ad-card__sub">'
        . number_format($total) . ' entr' . ($total === 1 ? 'y' : 'ies') . ' stored. '
        . 'Deleting is permanent — nothing here can be recovered afterwards.'
        . '</div></div></div>'
        . '<form method="post" action="' . e($actionUrl) . '" class="ad-card__body ad-filters" style="border:0"'
        . ' onsubmit="return confirm(' . e_attr((string) json_encode(
            'Permanently delete the selected part of the ' . $label . '? This cannot be undone.'
        )) . ')">'
        . csrf_field()
        . '<input type="hidden" name="op" value="clear">'
        . '<label class="sik-sr" for="logClearScope">How much to clear</label>'
        . '<select class="sik-select" id="logClearScope" name="scope" required>'
        . admin_options(LOG_CLEAR_SCOPES, '30')
        . '</select>'
        . '<button type="submit" class="ad-btn ad-btn--danger ad-btn--sm">'
        . icon('trash', 'w-4 h-4') . ' Clear log</button>'
        . '</form>'
        . '</div>';
}

/** The shared date-range controls for a log filter bar. */
function logs_range_controls(?array $range): string
{
    $preset = $range['preset'] ?? '';
    $from   = $range['from'] ?? '';
    $to     = $range['to'] ?? '';

    return '<label class="sik-sr" for="logRange">Date range</label>'
        . '<select class="sik-select" id="logRange" name="range" data-range-preset>'
        . admin_options(LOG_RANGE_PRESETS, $preset)
        . '</select>'
        // No inline display on the wrapper: an inline style would beat the
        // browser's own rule for [hidden] and the custom dates would never hide.
        . '<span data-range-custom' . ($preset === 'custom' ? '' : ' hidden') . '>'
        . '<label class="sik-sr" for="logFrom">From date</label>'
        . '<input class="sik-input" type="date" id="logFrom" name="from" value="' . e_attr($from)
        . '" style="margin-right:8px">'
        . '<label class="sik-sr" for="logTo">To date</label>'
        . '<input class="sik-input" type="date" id="logTo" name="to" value="' . e_attr($to) . '">'
        . '</span>';
}
