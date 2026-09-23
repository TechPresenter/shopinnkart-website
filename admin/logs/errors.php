<?php
/**
 * ShopInnKart Admin - Error log.
 *
 * Mirrors what ErrorHandler::log() wrote to /storage/logs. Resolving is an
 * explicit act: tick the entries you have dealt with and mark them resolved.
 *
 * Marking used to DELETE the rows, which made "tidy up the error log" and
 * "destroy the evidence" the same button — an attacker's stack traces are
 * often the only trace of a failed attempt. The rows now stay, stamped with
 * who resolved them and when; only the retention prune in _shared.php ever
 * removes anything, and never inside the retention window.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('logs.view');

require_once __DIR__ . '/_shared.php';

$selfUrl = admin_url('logs/errors.php');

if (is_post() && input('op', '') === 'clear') {
    logs_handle_clear('error_logs', $selfUrl);
}

// "Mark resolved": stamp exactly the rows the admin ticked. Nothing is deleted.
if (is_post() && input('op', '') === 'clear_selected') {
    admin_require_action('logs.delete');   // POST + CSRF + permission

    $ids = array_values(array_filter(array_map('intval', input_array('ids')), static fn (int $id): bool => $id > 0));

    if ($ids === []) {
        flash('error', 'No entries were selected.');
        redirect($selfUrl);
    }

    [$placeholders, $params] = Database::inPlaceholders($ids, 'id');
    $marked = Database::update(
        'error_logs',
        ['resolved_at' => date('Y-m-d H:i:s'), 'resolved_by' => (string) $admin['name']],
        "`id` IN ({$placeholders}) AND `resolved_at` IS NULL",
        $params
    );

    log_activity('logs.resolved', 'error_logs', null,
        'Marked ' . $marked . ' error log entr' . ($marked === 1 ? 'y' : 'ies') . ' resolved');
    admin_after_write();

    flash('success', number_format($marked) . ' entr' . ($marked === 1 ? 'y' : 'ies')
        . ' marked resolved. They stay in the log until they age past the retention window.');
    redirect($selfUrl);
}

// ---------------------------------------------------------------------------
// Filters
// ---------------------------------------------------------------------------
$search = trim((string) ($_GET['q'] ?? ''));
$range  = logs_date_filter();

$levelList = Database::fetchColumnAll('SELECT DISTINCT `level` FROM `error_logs` ORDER BY `level`');
$levelOptions = [];
foreach ($levelList as $levelValue) {
    $levelOptions[(string) $levelValue] = ucfirst((string) $levelValue);
}
$level = admin_filter('level', array_keys($levelOptions));

$where  = ['1'];
$params = [];

if ($search !== '') {
    $where[] = '(`message` LIKE :q_msg OR `file` LIKE :q_file OR `url` LIKE :q_url)';
    $params['q_msg'] = $params['q_file'] = $params['q_url'] = '%' . $search . '%';
}
if ($level !== '') {
    $where[] = '`level` = :level';
    $params['level'] = $level;
}
if ($range !== null) {
    $where[] = '`created_at` >= :from AND `created_at` <= :to';
    $params['from'] = $range['from'] . ' 00:00:00';
    $params['to']   = $range['to'] . ' 23:59:59';
}
$whereSql = implode(' AND ', $where);

$page       = max(1, (int) ($_GET['page'] ?? 1));
$total      = (int) Database::fetchColumn("SELECT COUNT(*) FROM `error_logs` WHERE {$whereSql}", $params);
$pagination = paginate($total, ADMIN_PER_PAGE, $page);

// PDO cannot bind LIMIT/OFFSET with emulated prepares off.
$limit  = (int) $pagination['per_page'];
$offset = (int) $pagination['offset'];

$entries = Database::fetchAll(
    "SELECT `id`, `level`, `message`, `file`, `line`, `url`, `trace`, `ip_address`, `created_at`,
            `resolved_at`, `resolved_by`
     FROM `error_logs`
     WHERE {$whereSql}
     ORDER BY `id` DESC
     LIMIT {$limit} OFFSET {$offset}",
    $params
);

$logTotal   = Database::count('error_logs');
$last24h    = (int) Database::fetchColumn('SELECT COUNT(*) FROM `error_logs` WHERE `created_at` >= DATE_SUB(NOW(), INTERVAL 1 DAY)');
$levelCounts = Database::fetchPairs('SELECT `level`, COUNT(*) FROM `error_logs` GROUP BY `level`');
$canDelete  = admin_can('logs.delete');
$hasFilters = $search !== '' || $level !== '' || $range !== null;

/** Tone per level so a fatal does not look like a notice. */
$levelTone = static function (string $value): string {
    return [
        'fatal'     => 'red',
        'error'     => 'red',
        'warning'   => 'amber',
        'notice'    => 'blue',
        'info'      => 'gray',
        'deprecated' => 'gray',
    ][strtolower($value)] ?? 'gray';
};

$pageTitle    = 'Error Log';
$pageSubtitle = number_format($logTotal) . ' entries · ' . number_format($last24h) . ' in the last 24 hours';
$breadcrumbs  = [
    ['label' => 'Dashboard', 'url' => admin_url('dashboard.php')],
    ['label' => 'Error Log'],
];

require ADMIN_PATH . '/includes/header.php';
?>

<?php if ($last24h > 0): ?>
    <div class="sik-alert sik-alert--warning">
        <?= icon('alert', 'w-5 h-5') ?>
        <div>
            <?= number_format($last24h) ?> error<?= $last24h === 1 ? '' : 's' ?> logged in the last 24 hours.
            The same entries are also in <code>/storage/logs/app-<?= e(date('Y-m-d')) ?>.log</code>
            if you need the raw file.
        </div>
    </div>
<?php endif; ?>

<div class="ad-card">
    <div class="ad-tabs">
        <a class="ad-tab <?= $level === '' ? 'is-active' : '' ?>"
           href="<?= e(url_with(['level' => null, 'page' => null])) ?>">
            All <span class="ad-tab__count"><?= number_format($logTotal) ?></span>
        </a>
        <?php foreach ($levelOptions as $key => $label): ?>
            <a class="ad-tab <?= $level === $key ? 'is-active' : '' ?>"
               href="<?= e(url_with(['level' => $key, 'page' => null])) ?>">
                <?= e($label) ?>
                <span class="ad-tab__count"><?= number_format((int) ($levelCounts[$key] ?? 0)) ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <form class="ad-filters" method="get" action="<?= e($selfUrl) ?>">
        <?php if ($level !== ''): ?>
            <input type="hidden" name="level" value="<?= e($level) ?>">
        <?php endif; ?>

        <div class="ad-search">
            <?= icon('search', 'w-4 h-4') ?>
            <label class="sik-sr" for="errorSearch">Search the error log</label>
            <input class="sik-input" type="search" id="errorSearch" name="q" data-filter-search
                   value="<?= e($search) ?>" placeholder="Message, file or URL&hellip;" autocomplete="off">
        </div>

        <?= logs_range_controls($range) ?>

        <button type="submit" class="ad-btn ad-btn--sm"><?= icon('filter', 'w-4 h-4') ?> Filter</button>
        <?php if ($hasFilters): ?>
            <a class="ad-btn ad-btn--sm" href="<?= e($selfUrl) ?>">Reset</a>
        <?php endif; ?>
    </form>

    <?php if ($entries === []): ?>
        <div class="ad-card__body ad-card__body--flush">
            <?= $hasFilters
                ? admin_empty('Nothing matches those filters', 'Widen the date range or clear the filters to see more.', null, null, 'search')
                : admin_empty('No errors logged', 'Nothing has thrown since the log was last cleared. That is the good outcome.', null, null, 'check-circle') ?>
        </div>
    <?php else: ?>
        <form method="post" action="<?= e($selfUrl) ?>" data-bulk-form>
            <?= csrf_field() ?>
            <input type="hidden" name="op" value="clear_selected">

            <?php if ($canDelete): ?>
                <div class="ad-bulk" data-bulk-bar>
                    <strong><span data-bulk-count>0</span> selected</strong>
                    <span class="ad-muted" style="font-size:12.5px">
                        Marking keeps the entry and stamps it with your name &mdash; do it once the cause is fixed.
                    </span>
                    <button type="submit" class="ad-btn ad-btn--sm" style="margin-left:auto"
                            data-confirm="Mark the selected entries as resolved?">
                        <?= icon('check', 'w-4 h-4') ?> Mark resolved
                    </button>
                </div>
            <?php endif; ?>

            <div class="ad-card__body ad-card__body--flush">
                <div class="ad-tablewrap">
                    <table class="ad-table">
                        <thead>
                            <tr>
                                <?php if ($canDelete): ?>
                                    <th class="ad-table__check">
                                        <label class="sik-sr" for="errorCheckAll">Select every visible entry</label>
                                        <input type="checkbox" id="errorCheckAll" data-check-all>
                                    </th>
                                <?php endif; ?>
                                <th>When</th>
                                <th>Level</th>
                                <th>Message</th>
                                <th>Origin</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($entries as $entry): ?>
                                <?php $entryId = (int) $entry['id']; ?>
                                <tr>
                                    <?php if ($canDelete): ?>
                                        <td class="ad-table__check">
                                            <label class="sik-sr" for="errorRow<?= $entryId ?>">Select entry <?= $entryId ?></label>
                                            <input type="checkbox" id="errorRow<?= $entryId ?>"
                                                   data-check-row value="<?= $entryId ?>">
                                        </td>
                                    <?php endif; ?>
                                    <td style="white-space:nowrap">
                                        <div><?= e(format_datetime($entry['created_at'], 'd M Y, g:i A')) ?></div>
                                        <div class="ad-cellflex__meta"><?= e(time_ago($entry['created_at'])) ?></div>
                                    </td>
                                    <td>
                                        <span class="sik-status sik-status--<?= e($levelTone((string) $entry['level'])) ?>">
                                            <?= e(ucfirst((string) $entry['level'])) ?>
                                        </span>
                                        <?php if (!empty($entry['resolved_at'])): ?>
                                            <div class="ad-cellflex__meta" style="margin-top:4px"
                                                 title="<?= e_attr('Resolved ' . format_datetime($entry['resolved_at'], 'd M Y, g:i A')
                                                     . (!empty($entry['resolved_by']) ? ' by ' . $entry['resolved_by'] : '')) ?>">
                                                <?= icon('check', 'w-3 h-3') ?> Resolved
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <?php // Was min-width:320px. An inline floor outranks every rule in
                                          // admin.css, so this one cell kept the log table 320px wide
                                          // whatever the screen: 91px of sideways scroll at 768px and 3px
                                          // at 360px, inside a table that otherwise now fits or stacks.
                                          // A preferred width asks for the same room and gives it up. ?>
                                    <td style="width:45%">
                                        <div style="font-weight:600;line-height:1.45"><?= e($entry['message']) ?></div>
                                        <?php if (!empty($entry['url'])): ?>
                                            <div class="ad-cellflex__meta ad-mono"><?= e(str_limit((string) $entry['url'], 110)) ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($entry['trace'])): ?>
                                            <details style="margin-top:6px">
                                                <summary style="cursor:pointer;font-size:12px;color:var(--ad-primary)">
                                                    Stack trace
                                                </summary>
                                                <?php // `overflow-x:auto; white-space:pre` made a stack trace scroll 1387px
                                                      // sideways inside this <details> on a phone. admin.css now wraps
                                                      // pre.ad-mono; the inline pair outranked it, so it is gone. ?>
                                                <pre class="ad-mono" style="margin-top:6px;padding:10px;background:#F9FAFB;
                                                     border:1px solid var(--ad-border);border-radius:8px;
                                                     font-size:11.5px;line-height:1.6;max-height:320px;overflow-y:auto"><?= e($entry['trace']) ?></pre>
                                            </details>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($entry['file'])): ?>
                                            <div class="ad-mono" style="word-break:break-all">
                                                <?= e(str_replace(str_replace('\\', '/', ROOT_PATH) . '/', '', str_replace('\\', '/', (string) $entry['file']))) ?>
                                                <?php if (!empty($entry['line'])): ?>:<?= (int) $entry['line'] ?><?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="ad-muted">&mdash;</span>
                                        <?php endif; ?>
                                        <div class="ad-cellflex__meta ad-mono"><?= e($entry['ip_address'] ?: '—') ?></div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </form>
    <?php endif; ?>

    <?php if ($pagination['last'] > 1): ?>
        <div class="ad-card__foot">
            <span class="ad-muted">
                Showing <?= (int) $pagination['from'] ?>&ndash;<?= (int) $pagination['to'] ?>
                of <?= (int) $pagination['total'] ?>
            </span>
            <?= admin_pagination($pagination, $selfUrl) ?>
        </div>
    <?php endif; ?>
</div>

<?php if ($canDelete): ?>
    <?= logs_clear_card('error_logs', $selfUrl, $logTotal) ?>
<?php endif; ?>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
