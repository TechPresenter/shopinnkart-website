<?php
/**
 * ShopInnKart Admin - Contact message inbox.
 *
 * Gated on customers.view to match the sidebar entry in admin/includes/functions.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('customers.view');

$search = trim((string) ($_GET['q'] ?? ''));
$status = admin_filter('status', ['new', 'read', 'replied', 'closed']);
$page   = max(1, (int) ($_GET['page'] ?? 1));

// ORDER BY is built from this map only, never from the raw query string.
$sortMap = [
    'name'    => '`name`',
    'subject' => '`subject`',
    'created' => '`created_at`',
];
$sort   = admin_safe_sort((string) ($_GET['sort'] ?? ''), array_keys($sortMap), 'created');
$dir    = strtolower((string) ($_GET['dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
$dirSql = admin_safe_dir($dir);

$where  = ['1'];
$params = [];

if ($search !== '') {
    // Each occurrence needs its own placeholder: with emulated prepares off,
    // PDO binds a named marker exactly once.
    $where[] = '(`name` LIKE :q_name OR `email` LIKE :q_email OR `phone` LIKE :q_phone
                 OR `subject` LIKE :q_subject OR `message` LIKE :q_message)';
    $params['q_name'] = $params['q_email'] = $params['q_phone']
        = $params['q_subject'] = $params['q_message'] = '%' . $search . '%';
}
if ($status !== '') {
    $where[] = '`status` = :status';
    $params['status'] = $status;
}
$whereSql = implode(' AND ', $where);

$total      = (int) Database::fetchColumn("SELECT COUNT(*) FROM `contact_messages` WHERE {$whereSql}", $params);
$pagination = paginate($total, ADMIN_PER_PAGE, $page);

// PDO cannot bind LIMIT/OFFSET with emulated prepares off, so they are cast here.
$limit  = (int) $pagination['per_page'];
$offset = (int) $pagination['offset'];

$messages = Database::fetchAll(
    "SELECT `id`, `name`, `email`, `phone`, `subject`, `message`, `status`, `admin_reply`, `created_at`
     FROM `contact_messages`
     WHERE {$whereSql}
     ORDER BY {$sortMap[$sort]} {$dirSql}, `id` DESC
     LIMIT {$limit} OFFSET {$offset}",
    $params
);

$statusCounts = Database::fetchPairs('SELECT `status`, COUNT(*) FROM `contact_messages` GROUP BY `status`');
$counts = [
    'new'     => (int) ($statusCounts['new'] ?? 0),
    'read'    => (int) ($statusCounts['read'] ?? 0),
    'replied' => (int) ($statusCounts['replied'] ?? 0),
    'closed'  => (int) ($statusCounts['closed'] ?? 0),
];
$counts['all'] = array_sum($counts);

$canDelete = admin_can('customers.delete');

$pageTitle    = 'Messages';
$pageSubtitle = $counts['new'] . ' unread · ' . $counts['all'] . ' total';
$breadcrumbs  = [
    ['label' => 'Dashboard', 'url' => admin_url('dashboard.php')],
    ['label' => 'Messages'],
];

require ADMIN_PATH . '/includes/header.php';
?>

<div class="ad-card">
    <div class="ad-tabs">
        <?php
        $tabs = ['' => 'All', 'new' => 'New', 'read' => 'Read', 'replied' => 'Replied', 'closed' => 'Closed'];
        ?>
        <?php foreach ($tabs as $key => $label): ?>
            <a class="ad-tab <?= $status === $key ? 'is-active' : '' ?>"
               href="<?= e(url_with(['status' => $key === '' ? null : $key, 'page' => null])) ?>">
                <?= e($label) ?>
                <span class="ad-tab__count"><?= number_format((int) ($counts[$key === '' ? 'all' : $key] ?? 0)) ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <form class="ad-filters" method="get" action="<?= e(admin_url('messages/')) ?>">
        <?php if ($status !== ''): ?>
            <input type="hidden" name="status" value="<?= e($status) ?>">
        <?php endif; ?>
        <div class="ad-search">
            <?= icon('search', 'w-4 h-4') ?>
            <label class="sik-sr" for="messageSearch">Search messages</label>
            <input class="sik-input" type="search" id="messageSearch" name="q" data-filter-search
                   value="<?= e($search) ?>" placeholder="Name, email, phone, subject or body&hellip;" autocomplete="off">
        </div>

        <button type="submit" class="ad-btn ad-btn--sm"><?= icon('filter', 'w-4 h-4') ?> Filter</button>
        <?php if ($search !== '' || $status !== ''): ?>
            <a class="ad-btn ad-btn--sm" href="<?= e(admin_url('messages/')) ?>">Reset</a>
        <?php endif; ?>
    </form>

    <div class="ad-card__body ad-card__body--flush">
        <?php if ($messages === []): ?>
            <?= $search !== '' || $status !== ''
                ? admin_empty('No messages match', 'Try a different search term or clear the filters.', null, null, 'search')
                : admin_empty(
                    'The inbox is empty',
                    'Messages sent from the storefront contact form land here.',
                    null,
                    null,
                    'mail'
                ) ?>
        <?php else: ?>
            <div class="ad-tablewrap">
                <table class="ad-table">
                    <thead>
                        <tr>
                            <th><?= admin_sort_header('From', 'name', $sort, $dir) ?></th>
                            <th><?= admin_sort_header('Subject', 'subject', $sort, $dir) ?></th>
                            <th>Message</th>
                            <th>Status</th>
                            <th><?= admin_sort_header('Received', 'created', $sort, $dir) ?></th>
                            <th class="ad-table__actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($messages as $message): ?>
                            <?php
                            $messageId = (int) $message['id'];
                            $isUnread  = $message['status'] === 'new';
                            ?>
                            <tr>
                                <td>
                                    <div class="ad-cellflex">
                                        <span class="ad-avatar"><?= e(initials((string) $message['name'])) ?></span>
                                        <span style="min-width:0">
                                            <span class="ad-cellflex__name" style="display:block<?= $isUnread ? ';font-weight:700' : '' ?>">
                                                <a href="<?= e(admin_url('messages/view.php?id=' . $messageId)) ?>">
                                                    <?= e($message['name']) ?>
                                                </a>
                                            </span>
                                            <span class="ad-cellflex__meta"><?= e($message['email']) ?></span>
                                        </span>
                                    </div>
                                </td>
                                <td><?= e(str_limit($message['subject'], 42) ?: '—') ?></td>
                                <td class="ad-muted"><?= e(str_limit($message['message'], 90)) ?></td>
                                <td>
                                    <?= admin_state_badge((string) $message['status']) ?>
                                    <?php if (!empty($message['admin_reply']) && $message['status'] !== 'replied'): ?>
                                        <span class="sik-status sik-status--blue">Reply saved</span>
                                    <?php endif; ?>
                                </td>
                                <td class="ad-muted" title="<?= e_attr(format_datetime($message['created_at'])) ?>">
                                    <?= e(time_ago($message['created_at'])) ?>
                                </td>
                                <td class="ad-table__actions">
                                    <a class="ad-btn ad-btn--icon" title="Open message" aria-label="Open message"
                                       href="<?= e(admin_url('messages/view.php?id=' . $messageId)) ?>">
                                        <?= icon('eye', 'w-4 h-4') ?>
                                    </a>
                                    <?php if ($canDelete): ?>
                                        <?= admin_delete_form(
                                            admin_url('messages/delete.php'),
                                            $messageId,
                                            'Delete the message from "' . $message['name'] . '"? This cannot be undone.'
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
            <?= admin_pagination($pagination, admin_url('messages/')) ?>
        </div>
    <?php endif; ?>
</div>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
