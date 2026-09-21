<?php
/**
 * ShopInnKart Admin - Customers list.
 *
 * The order count and lifetime spend are computed in the same pass as the row
 * itself so the table can be sorted by them.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('customers.view');

require_once __DIR__ . '/_filters.php';

$filters = customer_list_filters();

$total = (int) Database::fetchColumn(
    'SELECT COUNT(*) FROM `users` u WHERE ' . $filters['where'],
    $filters['params']
);

$page       = max(1, (int) ($_GET['page'] ?? 1));
$pagination = paginate($total, ADMIN_PER_PAGE, $page);

// PDO cannot bind LIMIT/OFFSET with emulated prepares off, so they are cast here.
$limit  = (int) $pagination['per_page'];
$offset = (int) $pagination['offset'];

$customers = Database::fetchAll(
    customer_list_select()
    . ' WHERE ' . $filters['where']
    . ' GROUP BY u.`id`'
    . ' ORDER BY ' . $filters['order_by']
    . ' LIMIT ' . $limit . ' OFFSET ' . $offset,
    $filters['params']
);

// ---------------------------------------------------------------------------
// Summary tiles. These describe the whole customer base, not the current
// filter - an admin narrowing the list still wants the store-wide numbers.
// ---------------------------------------------------------------------------
$totalCustomers = Database::count('users');
$newThisMonth   = (int) Database::fetchColumn(
    "SELECT COUNT(*) FROM `users` WHERE `created_at` >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"
);
$repeatBuyers = (int) Database::fetchColumn(
    'SELECT COUNT(*) FROM (
        SELECT o.`user_id` FROM `orders` o
        WHERE o.`user_id` IS NOT NULL AND o.`status` IN (' . CUSTOMER_SPEND_ORDER_STATUSES . ')
        GROUP BY o.`user_id` HAVING COUNT(*) >= 2
     ) repeat_buyers'
);
$valueRow = Database::fetch(
    'SELECT COALESCE(SUM(t.spend), 0) AS total_spend, COUNT(*) AS buyers FROM (
        SELECT o.`user_id`, SUM(o.`total_amount`) AS spend FROM `orders` o
        WHERE o.`user_id` IS NOT NULL AND o.`status` IN (' . CUSTOMER_SPEND_ORDER_STATUSES . ')
        GROUP BY o.`user_id`
     ) t'
) ?? ['total_spend' => 0, 'buyers' => 0];

$buyers            = (int) $valueRow['buyers'];
$averageLifetime   = $buyers > 0 ? (float) $valueRow['total_spend'] / $buyers : 0.0;

// The export must reproduce exactly this view, minus paging.
$exportQuery = $_GET;
unset($exportQuery['page']);
$exportUrl = admin_url('customers/export.php')
    . ($exportQuery !== [] ? '?' . http_build_query($exportQuery) : '');

$pageTitle    = 'Customers';
$pageSubtitle = number_format($total) . ' customer' . ($total === 1 ? '' : 's') . ' match this view.';
$breadcrumbs  = [
    ['label' => 'Dashboard', 'url' => admin_url('dashboard.php')],
    ['label' => 'Customers'],
];
$pageActions = '<a class="ad-btn" href="' . e($exportUrl) . '">' . icon('download', 'w-4 h-4') . ' Export CSV</a>';

require ADMIN_PATH . '/includes/header.php';
?>

<div class="ad-grid ad-grid--4" style="margin-bottom:18px">
    <?= admin_stat_card('Total Customers', number_format($totalCustomers), 'users', 'primary',
        number_format($buyers) . ' have placed an order') ?>
    <?= admin_stat_card('New This Month', number_format($newThisMonth), 'user', 'green',
        'Signed up since ' . format_date(date('Y-m-01'), 'd M')) ?>
    <?= admin_stat_card('Repeat Customers', number_format($repeatBuyers), 'refresh', 'blue',
        'Two or more completed orders') ?>
    <?= admin_stat_card('Avg Lifetime Value', money($averageLifetime), 'wallet', 'violet',
        'Per customer who has ordered') ?>
</div>

<div class="ad-card">
    <form class="ad-filters" method="get" action="<?= e(admin_url('customers/')) ?>">
        <!-- Sorting survives a filter change. -->
        <input type="hidden" name="sort" value="<?= e($filters['sort']) ?>">
        <input type="hidden" name="dir" value="<?= e($filters['dir']) ?>">

        <div class="ad-search">
            <?= icon('search', 'w-4 h-4') ?>
            <label class="sik-sr" for="custSearch">Search customers</label>
            <input class="sik-input" type="search" id="custSearch" name="q" data-filter-search
                   placeholder="Name, email or phone&hellip;" value="<?= e($filters['q']) ?>" autocomplete="off">
        </div>

        <label class="sik-sr" for="custStatus">Status</label>
        <select class="sik-select" id="custStatus" name="status" data-auto-submit>
            <option value="">All statuses</option>
            <?= admin_options(['active' => 'Active', 'inactive' => 'Inactive', 'blocked' => 'Blocked'], $filters['status']) ?>
        </select>

        <label class="sik-sr" for="custHas">Ordering</label>
        <select class="sik-select" id="custHas" name="has" data-auto-submit>
            <option value="">Everyone</option>
            <?= admin_options(['yes' => 'Has ordered', 'no' => 'Never ordered'], $filters['has']) ?>
        </select>

        <label class="sik-sr" for="custFrom">Joined from</label>
        <input class="sik-input" type="date" id="custFrom" name="from" value="<?= e($filters['from']) ?>"
               title="Joined on or after">
        <label class="sik-sr" for="custTo">Joined to</label>
        <input class="sik-input" type="date" id="custTo" name="to" value="<?= e($filters['to']) ?>"
               title="Joined on or before">

        <button type="submit" class="ad-btn ad-btn--primary ad-btn--sm">Apply</button>
        <?php if ($filters['active']): ?>
            <a class="ad-btn ad-btn--sm" href="<?= e(admin_url('customers/')) ?>">Reset</a>
        <?php endif; ?>
    </form>

    <div class="ad-card__body ad-card__body--flush">
        <?php if ($customers === []): ?>
            <?= $filters['active']
                ? admin_empty(
                    'No customers match those filters',
                    'Try a different search term, or widen the date range.',
                    'Clear filters',
                    admin_url('customers/'),
                    'users'
                )
                : admin_empty(
                    'No customers yet',
                    'Accounts created on the storefront will show up here.',
                    null,
                    null,
                    'users'
                ) ?>
        <?php else: ?>
            <div class="ad-tablewrap">
                <table class="ad-table">
                    <thead>
                        <tr>
                            <th><?= admin_sort_header('Customer', 'name', $filters['sort'], $filters['dir']) ?></th>
                            <th><?= admin_sort_header('Email', 'email', $filters['sort'], $filters['dir']) ?></th>
                            <th>Phone</th>
                            <th class="ad-table__num"><?= admin_sort_header('Orders', 'orders', $filters['sort'], $filters['dir']) ?></th>
                            <th class="ad-table__num"><?= admin_sort_header('Lifetime Spend', 'spend', $filters['sort'], $filters['dir']) ?></th>
                            <th>Status</th>
                            <th><?= admin_sort_header('Joined', 'created_at', $filters['sort'], $filters['dir']) ?></th>
                            <th class="ad-table__actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($customers as $customer): ?>
                            <?php
                            $customerId = (int) $customer['id'];
                            $name       = customer_full_name($customer);
                            $orderCount = (int) $customer['orders_count'];
                            $viewUrl    = admin_url('customers/view.php?id=' . $customerId);
                            ?>
                            <tr>
                                <td>
                                    <a class="ad-cellflex" href="<?= e($viewUrl) ?>">
                                        <span class="ad-avatar"><?= e(initials($name)) ?></span>
                                        <span style="min-width:0">
                                            <span class="ad-cellflex__name" style="display:block"><?= e($name) ?></span>
                                            <span class="ad-cellflex__meta">#<?= $customerId ?></span>
                                        </span>
                                    </a>
                                </td>
                                <td><a href="mailto:<?= e($customer['email']) ?>"><?= e($customer['email']) ?></a></td>
                                <td>
                                    <?php if (!empty($customer['phone'])): ?>
                                        <span class="ad-mono"><?= e($customer['phone']) ?></span>
                                    <?php else: ?>
                                        <span class="ad-muted">&mdash;</span>
                                    <?php endif; ?>
                                </td>
                                <td class="ad-table__num"><?= number_format($orderCount) ?></td>
                                <td class="ad-table__num"><strong><?= e(money((float) $customer['lifetime_spend'])) ?></strong></td>
                                <td><?= admin_state_badge((string) $customer['status']) ?></td>
                                <td class="ad-muted" title="<?= e(format_datetime($customer['created_at'])) ?>">
                                    <?= e(format_date($customer['created_at'], 'd M Y')) ?>
                                </td>
                                <td class="ad-table__actions">
                                    <a class="ad-btn ad-btn--icon" href="<?= e($viewUrl) ?>" title="View" aria-label="View customer">
                                        <?= icon('eye', 'w-4 h-4') ?>
                                    </a>
                                    <?php if (admin_can('customers.edit')): ?>
                                        <a class="ad-btn ad-btn--icon"
                                           href="<?= e(admin_url('customers/edit.php?id=' . $customerId)) ?>"
                                           title="Edit" aria-label="Edit customer">
                                            <?= icon('edit', 'w-4 h-4') ?>
                                        </a>
                                    <?php endif; ?>
                                    <?php if (admin_can('customers.delete') && $orderCount === 0): ?>
                                        <?= admin_delete_form(
                                            admin_url('customers/delete.php'),
                                            $customerId,
                                            'Delete ' . $name . '? Their addresses, wishlist and cart go with them. This cannot be undone.'
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
        <div class="ad-card__foot" style="justify-content:space-between;align-items:center">
            <span class="ad-muted" style="font-size:12.5px">
                Showing <?= number_format($pagination['from']) ?>&ndash;<?= number_format($pagination['to']) ?>
                of <?= number_format($pagination['total']) ?>
            </span>
            <?= admin_pagination($pagination) ?>
        </div>
    <?php endif; ?>
</div>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
