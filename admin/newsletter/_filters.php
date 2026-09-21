<?php
/**
 * ShopInnKart Admin - Newsletter list filters.
 *
 * index.php and export.php build their WHERE and ORDER BY from here, otherwise
 * the downloaded CSV quietly disagrees with the screen the admin was looking at.
 */

declare(strict_types=1);

if (!defined('SIK_BOOTSTRAPPED')) {
    http_response_code(403);
    exit;
}

/** sort key => the SQL expression it maps to. Nothing outside this reaches ORDER BY. */
const NEWSLETTER_SORT_COLUMNS = [
    'email'   => '`email`',
    'name'    => '`name`',
    'source'  => '`source`',
    'created' => '`created_at`',
];

const NEWSLETTER_STATUSES = ['active', 'unsubscribed'];

/** Sources actually present in the table, used as the filter allowlist. */
function newsletter_sources(): array
{
    return Database::fetchColumnAll('SELECT DISTINCT `source` FROM `newsletter_subscribers` ORDER BY `source`');
}

/** Wrap a search term for LIKE, neutralising the wildcards a user can type. */
function newsletter_like(string $term): string
{
    return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term) . '%';
}

/**
 * Read the list filters out of the query string.
 *
 * @return array{where:string, params:array, order_by:string, q:string, status:string,
 *               source:string, from:string, to:string, sort:string, dir:string, active:bool}
 */
function newsletter_list_filters(): array
{
    $q      = trim((string) ($_GET['q'] ?? ''));
    $status = admin_filter('status', NEWSLETTER_STATUSES);
    $source = admin_filter('source', newsletter_sources());

    // Accept only real dates; anything else is treated as "not set".
    $from = trim((string) ($_GET['from'] ?? ''));
    $to   = trim((string) ($_GET['to'] ?? ''));
    $from = ($from !== '' && strtotime($from) !== false) ? date('Y-m-d', (int) strtotime($from)) : '';
    $to   = ($to !== '' && strtotime($to) !== false) ? date('Y-m-d', (int) strtotime($to)) : '';
    // Entered backwards - swap rather than silently return nothing.
    if ($from !== '' && $to !== '' && $from > $to) {
        [$from, $to] = [$to, $from];
    }

    $sort = admin_safe_sort((string) ($_GET['sort'] ?? ''), array_keys(NEWSLETTER_SORT_COLUMNS), 'created');
    $dir  = admin_safe_dir((string) ($_GET['dir'] ?? 'desc'));

    $where  = ['1'];
    $params = [];

    if ($q !== '') {
        // Each occurrence needs its own placeholder: with emulated prepares off,
        // PDO binds a named marker exactly once.
        $where[] = '(`email` LIKE :q_email OR `name` LIKE :q_name)';
        $like = newsletter_like($q);
        $params['q_email'] = $params['q_name'] = $like;
    }
    if ($status !== '') {
        $where[] = '`status` = :status';
        $params['status'] = $status;
    }
    if ($source !== '') {
        $where[] = '`source` = :source';
        $params['source'] = $source;
    }
    if ($from !== '') {
        $where[] = '`created_at` >= :from';
        $params['from'] = $from . ' 00:00:00';
    }
    if ($to !== '') {
        // Half-open upper bound so the last day is included whatever its time.
        $where[] = '`created_at` < :to';
        $params['to'] = date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00';
    }

    return [
        'where'    => implode(' AND ', $where),
        'params'   => $params,
        'order_by' => NEWSLETTER_SORT_COLUMNS[$sort] . ' ' . $dir . ', `id` DESC',
        'q'        => $q,
        'status'   => $status,
        'source'   => $source,
        'from'     => $from,
        'to'       => $to,
        'sort'     => $sort,
        'dir'      => strtolower($dir),
        'active'   => $q !== '' || $status !== '' || $source !== '' || $from !== '' || $to !== '',
    ];
}
