<?php
/**
 * ShopInnKart Admin - Newsletter subscribers.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('newsletter.view');

require_once __DIR__ . '/_filters.php';

$filters = newsletter_list_filters();
$page    = max(1, (int) ($_GET['page'] ?? 1));

$total      = (int) Database::fetchColumn(
    'SELECT COUNT(*) FROM `newsletter_subscribers` WHERE ' . $filters['where'],
    $filters['params']
);
$pagination = paginate($total, ADMIN_PER_PAGE, $page);

// PDO cannot bind LIMIT/OFFSET with emulated prepares off, so they are cast here.
$limit  = (int) $pagination['per_page'];
$offset = (int) $pagination['offset'];

$subscribers = Database::fetchAll(
    'SELECT `id`, `email`, `name`, `source`, `ip_address`, `status`, `created_at`, `updated_at`
     FROM `newsletter_subscribers`
     WHERE ' . $filters['where'] . '
     ORDER BY ' . $filters['order_by'] . "
     LIMIT {$limit} OFFSET {$offset}",
    $filters['params']
);

$counts = [
    'all'          => Database::count('newsletter_subscribers'),
    'active'       => Database::count('newsletter_subscribers', "`status` = 'active'"),
    'unsubscribed' => Database::count('newsletter_subscribers', "`status` = 'unsubscribed'"),
];
$sources = newsletter_sources();

$canEdit   = admin_can('newsletter.edit');
$canDelete = admin_can('newsletter.delete');

$pageTitle    = 'Newsletter';
$pageSubtitle = number_format($counts['active']) . ' active subscriber'
    . ($counts['active'] === 1 ? '' : 's') . ' · ' . number_format($counts['unsubscribed']) . ' opted out';
$breadcrumbs  = [
    ['label' => 'Dashboard', 'url' => admin_url('dashboard.php')],
    ['label' => 'Newsletter'],
];

// The export mirrors the current filters, so it carries the same query string.
$exportQuery = array_filter([
    'q'      => $filters['q'],
    'status' => $filters['status'],
    'source' => $filters['source'],
    'from'   => $filters['from'],
    'to'     => $filters['to'],
    'sort'   => $filters['sort'],
    'dir'    => $filters['dir'],
], static fn ($value): bool => $value !== '');

$pageActions = '<a class="ad-btn" href="' . e(admin_url('newsletter/export.php')
        . ($exportQuery === [] ? '' : '?' . http_build_query($exportQuery))) . '">'
    . icon('download', 'w-4 h-4') . ' Export CSV</a>';

require ADMIN_PATH . '/includes/header.php';
?>

<div class="ad-card">
    <div class="ad-tabs">
        <?php foreach (['' => 'All', 'active' => 'Active', 'unsubscribed' => 'Unsubscribed'] as $key => $label): ?>
            <a class="ad-tab <?= $filters['status'] === $key ? 'is-active' : '' ?>"
               href="<?= e(url_with(['status' => $key === '' ? null : $key, 'page' => null])) ?>">
                <?= e($label) ?>
                <span class="ad-tab__count"><?= number_format((int) ($counts[$key === '' ? 'all' : $key] ?? 0)) ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <form class="ad-filters" method="get" action="<?= e(admin_url('newsletter/')) ?>">
        <?php if ($filters['status'] !== ''): ?>
            <input type="hidden" name="status" value="<?= e($filters['status']) ?>">
        <?php endif; ?>
        <div class="ad-search">
            <?= icon('search', 'w-4 h-4') ?>
            <label class="sik-sr" for="subscriberSearch">Search subscribers</label>
            <input class="sik-input" type="search" id="subscriberSearch" name="q" data-filter-search
                   value="<?= e($filters['q']) ?>" placeholder="Email or name&hellip;" autocomplete="off">
        </div>

        <label class="sik-sr" for="subscriberSource">Source filter</label>
        <select class="sik-select" id="subscriberSource" name="source" data-auto-submit>
            <?= admin_options(array_combine($sources, $sources) ?: [], $filters['source'], 'Any source') ?>
        </select>

        <label class="sik-sr" for="subscriberFrom">Subscribed from</label>
        <input class="sik-input" type="date" id="subscriberFrom" name="from" value="<?= e($filters['from']) ?>">

        <label class="sik-sr" for="subscriberTo">Subscribed to</label>
        <input class="sik-input" type="date" id="subscriberTo" name="to" value="<?= e($filters['to']) ?>">

        <button type="submit" class="ad-btn ad-btn--sm"><?= icon('filter', 'w-4 h-4') ?> Filter</button>
        <?php if ($filters['active']): ?>
            <a class="ad-btn ad-btn--sm" href="<?= e(admin_url('newsletter/')) ?>">Reset</a>
        <?php endif; ?>
    </form>

    <div class="ad-card__body ad-card__body--flush">
        <?php if ($subscribers === []): ?>
            <?= $filters['active']
                ? admin_empty('No subscribers match', 'Try a different search term or clear the filters.', null, null, 'search')
                : admin_empty(
                    'No subscribers yet',
                    'Sign-ups from the footer form, the newsletter popup and checkout land here.',
                    null,
                    null,
                    'mail'
                ) ?>
        <?php else: ?>
            <div class="ad-tablewrap">
                <table class="ad-table">
                    <thead>
                        <tr>
                            <th><?= admin_sort_header('Email', 'email', $filters['sort'], $filters['dir']) ?></th>
                            <th><?= admin_sort_header('Name', 'name', $filters['sort'], $filters['dir']) ?></th>
                            <th><?= admin_sort_header('Source', 'source', $filters['sort'], $filters['dir']) ?></th>
                            <th>Status</th>
                            <th><?= admin_sort_header('Subscribed', 'created', $filters['sort'], $filters['dir']) ?></th>
                            <th class="ad-table__actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($subscribers as $subscriber): ?>
                            <?php
                            $subscriberId = (int) $subscriber['id'];
                            $isActive     = $subscriber['status'] === 'active';
                            ?>
                            <tr>
                                <td>
                                    <a href="mailto:<?= e_attr($subscriber['email']) ?>"><?= e($subscriber['email']) ?></a>
                                </td>
                                <td><?= e($subscriber['name'] ?: '—') ?></td>
                                <td><span class="sik-badge sik-badge--soft"><?= e($subscriber['source']) ?></span></td>
                                <td><?= admin_state_badge((string) $subscriber['status']) ?></td>
                                <td class="ad-muted" title="<?= e_attr(format_datetime($subscriber['created_at'])) ?>">
                                    <?= e(format_date($subscriber['created_at'], 'd M Y')) ?>
                                </td>
                                <td class="ad-table__actions">
                                    <?php if ($canEdit): ?>
                                        <form method="post" action="<?= e(admin_url('newsletter/toggle.php')) ?>"
                                              class="ad-inline-form">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= $subscriberId ?>">
                                            <input type="hidden" name="action"
                                                   value="<?= $isActive ? 'unsubscribe' : 'activate' ?>">
                                            <button type="submit"
                                                    class="ad-btn ad-btn--icon<?= $isActive ? '' : ' ad-btn--success' ?>"
                                                    title="<?= $isActive ? 'Unsubscribe' : 'Re-activate' ?>"
                                                    aria-label="<?= $isActive ? 'Unsubscribe' : 'Re-activate' ?> <?= e_attr($subscriber['email']) ?>">
                                                <?= icon($isActive ? 'close' : 'check', 'w-4 h-4') ?>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($canDelete): ?>
                                        <?= admin_delete_form(
                                            admin_url('newsletter/delete.php'),
                                            $subscriberId,
                                            'Delete ' . $subscriber['email'] . ' from the list? '
                                                . 'Unsubscribing keeps the record and honours the opt-out; deleting does not.'
                                        ) ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($pagination['last'] > 1): ?>
        <div class="ad-card__foot">
            <span class="ad-muted">
                Showing <?= (int) $pagination['from'] ?>&ndash;<?= (int) $pagination['to'] ?>
                of <?= (int) $pagination['total'] ?>
            </span>
            <?= admin_pagination($pagination, admin_url('newsletter/')) ?>
        </div>
    <?php endif; ?>
</div>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
