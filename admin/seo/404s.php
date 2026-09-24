<?php
/**
 * ShopInnKart Admin - Broken links.
 *
 * What visitors actually asked for and did not get, with one click to point it
 * somewhere real. The rule it writes goes into `redirects`, the same table
 * 404.php already consults - there is no second redirect mechanism here.
 *
 * The table holds a path, a referrer, a counter and two timestamps and nothing
 * else: no IP, no user agent, no visitor id. See seo_log_404() for why.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('settings.view');

require_once __DIR__ . '/_layout.php';

$errors = [];

if (is_post()) {
    admin_require_action('settings.edit');

    $action = (string) input('action', '');
    $id     = input_int('id');

    $entry = $id > 0
        ? Database::fetch('SELECT * FROM `seo_404_log` WHERE `id` = :id', ['id' => $id])
        : null;

    if ($action === 'ignore' && $entry !== null) {
        Database::update('seo_404_log', ['status' => 'ignored'], '`id` = :id', ['id' => $id]);
        admin_after_write();
        flash('success', 'Hidden from the list. It will come back if the retention window expires and it is hit again.');
        redirect(seo_admin_url('404s'));
    }

    if ($action === 'delete' && $entry !== null) {
        Database::delete('seo_404_log', '`id` = :id', ['id' => $id]);
        admin_after_write();
        flash('success', 'Entry removed.');
        redirect(seo_admin_url('404s'));
    }

    if ($action === 'prune') {
        $removed = seo_404_prune();
        admin_after_write();
        flash('success', $removed === 0
            ? 'Nothing was old enough to remove.'
            : number_format($removed) . ' stale entr' . ($removed === 1 ? 'y' : 'ies') . ' removed.');
        redirect(seo_admin_url('404s'));
    }

    if ($action === 'redirect' && $entry !== null) {
        $target = trim((string) input('target', ''));

        // An admin field that writes a Location header is an open-redirect hole
        // unless the target is pinned to this site. A rule pointing at
        // evil.test would take every visitor who followed an old link straight
        // off the store, and it would look like the store sent them.
        if ($target === '') {
            $errors[$id] = 'Where should it go?';
        } elseif (preg_match('~^(https?:)?//~i', $target) === 1) {
            $host = (string) parse_url($target, PHP_URL_HOST);
            $own  = (string) parse_url(SITE_URL, PHP_URL_HOST);
            if ($host === '' || strcasecmp($host, $own) !== 0) {
                $errors[$id] = 'Redirects from this screen have to stay on this site. '
                    . 'Use Settings > Redirects for an external target.';
            }
        }

        if (!isset($errors[$id])) {
            // Normalised the same way the redirect manager normalises a typed
            // rule, so /shop, shop and /shop/ cannot become three rules.
            $path = (string) parse_url($target, PHP_URL_PATH);
            $base = rtrim((string) parse_url(SITE_URL, PHP_URL_PATH), '/');
            if ($base !== '' && str_starts_with($path, $base)) {
                $path = substr($path, strlen($base));
            }
            $path = '/' . trim($path, '/');

            if ($path === (string) $entry['path']) {
                $errors[$id] = 'That points the URL at itself.';
            } elseif (seo_redirect_would_loop((string) $entry['path'], $path,
                    (int) Database::fetchColumn(
                        'SELECT `id` FROM `redirects` WHERE `source_path` = :p AND `is_regex` = 0',
                        ['p' => (string) $entry['path']]) ?: 0)) {
                // One click from this screen is exactly where a loop gets built
                // by accident: the operator is looking at a dead URL, not at the
                // rest of the redirect table, so they cannot see that the target
                // they picked already leads back here.
                $errors[$id] = 'That would create a redirect loop - ' . $path
                    . ' already leads back to ' . $entry['path'] . '.';
            } else {
                try {
                    $existing = Database::fetch(
                        'SELECT `id` FROM `redirects` WHERE `source_path` = :p AND `is_regex` = 0',
                        ['p' => (string) $entry['path']]
                    );

                    if ($existing !== null) {
                        Database::update('redirects', [
                            'target_path' => $path,
                            'status_code' => 301,
                            'status'      => 'active',
                        ], '`id` = :id', ['id' => (int) $existing['id']]);
                        $redirectId = (int) $existing['id'];
                    } else {
                        $redirectId = Database::insert('redirects', [
                            'source_path' => (string) $entry['path'],
                            'target_path' => $path,
                            'status_code' => 301,
                            'is_regex'    => 0,
                            'notes'       => 'From the broken-link monitor',
                            'status'      => 'active',
                        ]);
                    }

                    Database::update('seo_404_log', ['status' => 'resolved'], '`id` = :id', ['id' => $id]);

                    log_activity('seo.redirect_created', 'redirect', $redirectId,
                        'Broken link ' . $entry['path'] . ' now redirects to ' . $path);
                    admin_after_write();

                    flash('success', $entry['path'] . ' now redirects to ' . $path . '.');
                    redirect(seo_admin_url('404s'));
                } catch (Throwable $e) {
                    $errors[$id] = 'That redirect could not be saved.';
                }
            }
        }

        flash_errors($errors);
        flash('error', 'Nothing was saved.');
        redirect(seo_admin_url('404s'));
    }
}

$errors = $errors + errors_pull();

$status = admin_filter('status', ['open', 'ignored', 'resolved']);
$search = trim((string) ($_GET['q'] ?? ''));
$page   = max(1, (int) ($_GET['page'] ?? 1));

$where  = ['1'];
$params = [];

if ($status !== '') {
    $where[] = '`status` = :status';
    $params['status'] = $status;
}
if ($search !== '') {
    $where[] = '(`path` LIKE :q_path OR `referrer` LIKE :q_ref)';
    $params['q_path'] = $params['q_ref'] = '%' . $search . '%';
}
$whereSql = implode(' AND ', $where);

$total      = (int) Database::fetchColumn("SELECT COUNT(*) FROM `seo_404_log` WHERE {$whereSql}", $params);
$pagination = paginate($total, ADMIN_PER_PAGE, $page);

// PDO cannot bind LIMIT/OFFSET with emulated prepares off, so they are cast.
$limit  = (int) $pagination['per_page'];
$offset = (int) $pagination['offset'];

$entries = Database::fetchAll(
    "SELECT * FROM `seo_404_log` WHERE {$whereSql}
      ORDER BY `hits` DESC, `last_seen_at` DESC
      LIMIT {$limit} OFFSET {$offset}",
    $params
);

$counts = [
    'all'      => (int) Database::fetchColumn('SELECT COUNT(*) FROM `seo_404_log`'),
    'open'     => (int) Database::fetchColumn("SELECT COUNT(*) FROM `seo_404_log` WHERE `status` = 'open'"),
    'ignored'  => (int) Database::fetchColumn("SELECT COUNT(*) FROM `seo_404_log` WHERE `status` = 'ignored'"),
    'resolved' => (int) Database::fetchColumn("SELECT COUNT(*) FROM `seo_404_log` WHERE `status` = 'resolved'"),
];

$retention = max(1, (int) setting('seo_404_retention_days', 90));
$ceiling   = max(100, (int) setting('seo_404_max_rows', 5000));
$canEdit   = admin_can('settings.edit');

$pageTitle    = 'Broken links';
$pageSubtitle = number_format($counts['open']) . ' waiting · kept for ' . $retention . ' days';
$breadcrumbs  = seo_admin_breadcrumbs('Broken links');

require ADMIN_PATH . '/includes/header.php';
?>

<div class="ad-card">
    <?= seo_admin_tabs('404s') ?>

    <div class="ad-tabs">
        <?php foreach (['' => 'All', 'open' => 'Waiting', 'resolved' => 'Redirected', 'ignored' => 'Ignored']
            as $key => $label): ?>
            <a class="ad-tab <?= $status === $key ? 'is-active' : '' ?>"
               href="<?= e(url_with(['status' => $key === '' ? null : $key, 'page' => null])) ?>">
                <?= e($label) ?>
                <span class="ad-tab__count"><?= (int) ($counts[$key === '' ? 'all' : $key] ?? 0) ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <form class="ad-filters" method="get" action="<?= e(seo_admin_url('404s')) ?>">
        <?php if ($status !== ''): ?>
            <input type="hidden" name="status" value="<?= e($status) ?>">
        <?php endif; ?>
        <div class="ad-search">
            <?= icon('search', 'w-4 h-4') ?>
            <label class="sik-sr" for="link404Search">Search broken links</label>
            <input class="sik-input" type="search" id="link404Search" name="q"
                   value="<?= e($search) ?>" placeholder="Search by path or referrer&hellip;" autocomplete="off">
        </div>
        <button type="submit" class="ad-btn ad-btn--sm"><?= icon('filter', 'w-4 h-4') ?> Filter</button>
        <?php if ($search !== '' || $status !== ''): ?>
            <a class="ad-btn ad-btn--sm" href="<?= e(seo_admin_url('404s')) ?>">Reset</a>
        <?php endif; ?>
    </form>

    <div class="ad-card__body ad-card__body--flush">
        <?php if ($entries === []): ?>
            <?= admin_empty(
                $search !== '' || $status !== '' ? 'Nothing matches' : 'No broken links recorded',
                $search !== '' || $status !== ''
                    ? 'Try a different search or clear the filters.'
                    : 'Every URL a visitor has asked for since this was switched on has resolved. '
                        . 'Entries appear here the first time one does not.',
                null,
                null,
                'link'
            ) ?>
        <?php else: ?>
            <div class="ad-tablewrap">
                <table class="ad-table">
                    <thead>
                        <tr>
                            <th>Path asked for</th>
                            <th>Linked from</th>
                            <th>Visits</th>
                            <th>First seen</th>
                            <th>Last seen</th>
                            <th>Send it to</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($entries as $entry): ?>
                            <?php $entryId = (int) $entry['id']; ?>
                            <tr>
                                <td>
                                    <code><?= e((string) $entry['path']) ?></code>
                                    <?php if ((string) $entry['status'] !== 'open'): ?>
                                        <span class="ad-pill"><?= e((string) $entry['status']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="ad-muted">
                                    <?php if (!empty($entry['referrer'])): ?>
                                        <?= e((string) $entry['referrer']) ?>
                                    <?php else: ?>
                                        &mdash;
                                    <?php endif; ?>
                                </td>
                                <td><?= number_format((int) $entry['hits']) ?></td>
                                <td class="ad-muted"><?= e(format_date((string) $entry['first_seen_at'])) ?></td>
                                <td class="ad-muted"><?= e(format_date((string) $entry['last_seen_at'])) ?></td>
                                <td>
                                    <?php if ($canEdit): ?>
                                        <form method="post" action="<?= e(seo_admin_url('404s')) ?>"
                                              class="ad-cellflex" style="gap:6px">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="redirect">
                                            <input type="hidden" name="id" value="<?= $entryId ?>">
                                            <label class="sik-sr" for="t<?= $entryId ?>">Redirect target</label>
                                            <input class="sik-input" type="text" id="t<?= $entryId ?>"
                                                   name="target" placeholder="/shop.php" style="min-width:150px">
                                            <button type="submit" class="ad-btn ad-btn--sm ad-btn--primary">
                                                301
                                            </button>
                                        </form>
                                        <?php if (isset($errors[$entryId])): ?>
                                            <span class="sik-error"><?= e((string) $errors[$entryId]) ?></span>
                                        <?php endif; ?>

                                        <form method="post" action="<?= e(seo_admin_url('404s')) ?>"
                                              class="ad-inline-form" style="margin-top:4px">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= $entryId ?>">
                                            <button type="submit" name="action" value="ignore"
                                                    class="ad-btn ad-btn--sm">Ignore</button>
                                            <button type="submit" name="action" value="delete"
                                                    class="ad-btn ad-btn--sm ad-btn--danger-ghost">Remove</button>
                                        </form>
                                    <?php else: ?>
                                        <span class="ad-muted">Needs settings.edit</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="ad-card__foot" style="display:flex;gap:12px;justify-content:space-between;flex-wrap:wrap">
        <span class="ad-muted">
            <?php if ($pagination['last'] > 1): ?>
                Showing <?= (int) $pagination['from'] ?>&ndash;<?= (int) $pagination['to'] ?>
                of <?= (int) $pagination['total'] ?> &middot;
            <?php endif; ?>
            <?php /* The cap exists so a scanner hammering random URLs cannot fill the table. */ ?>
            Dropped <?= (int) $retention ?> days after the last hit &middot;
            capped at <?= number_format($ceiling) ?> rows &middot;
            no IP, browser or visitor id stored.
        </span>

        <?php if ($canEdit): ?>
            <form method="post" action="<?= e(seo_admin_url('404s')) ?>" class="ad-inline-form">
                <?= csrf_field() ?>
                <button type="submit" name="action" value="prune" class="ad-btn ad-btn--sm">
                    <?= icon('trash', 'w-4 h-4') ?> Clear stale entries now
                </button>
            </form>
        <?php endif; ?>
    </div>

    <?php if ($pagination['last'] > 1): ?>
        <div class="ad-card__foot">
            <?= admin_pagination($pagination, seo_admin_url('404s')) ?>
        </div>
    <?php endif; ?>
</div>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
