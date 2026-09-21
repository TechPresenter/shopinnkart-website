<?php
/**
 * ShopInnKart Admin - FAQ list, grouped by category.
 *
 * The storefront renders the FAQ page as one accordion per category, so the
 * admin screen mirrors that shape instead of a flat table.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('faq.view');

/**
 * Grouping means the whole result set has to be in hand at once, so the query
 * is capped rather than paginated. A FAQ list that reaches this size wants
 * splitting into more categories, not another page of results.
 */
const FAQ_LIST_CAP = 500;

$search   = trim((string) ($_GET['q'] ?? ''));
$status   = admin_filter('status', ['active', 'inactive']);
$category = trim((string) ($_GET['category'] ?? ''));

// Existing category names double as the filter allowlist.
$categoryNames = Database::fetchColumnAll('SELECT DISTINCT `category` FROM `faqs` ORDER BY `category`');
if ($category !== '' && !in_array($category, $categoryNames, true)) {
    $category = '';
}

$where  = ['1'];
$params = [];

if ($search !== '') {
    // Each occurrence needs its own placeholder: with emulated prepares off,
    // PDO binds a named marker exactly once.
    $where[] = '(`question` LIKE :q_question OR `answer` LIKE :q_answer OR `category` LIKE :q_category)';
    $params['q_question'] = $params['q_answer'] = $params['q_category'] = '%' . $search . '%';
}
if ($status !== '') {
    $where[] = '`status` = :status';
    $params['status'] = $status;
}
if ($category !== '') {
    $where[] = '`category` = :category';
    $params['category'] = $category;
}
$whereSql = implode(' AND ', $where);

$faqs = Database::fetchAll(
    "SELECT `id`, `category`, `question`, `answer`, `sort_order`, `status`, `updated_at`
     FROM `faqs`
     WHERE {$whereSql}
     ORDER BY `category` ASC, `sort_order` ASC, `id` ASC
     LIMIT " . FAQ_LIST_CAP,
    $params
);

$grouped = [];
foreach ($faqs as $faq) {
    $grouped[(string) $faq['category']][] = $faq;
}

$counts = [
    'all'      => Database::count('faqs'),
    'active'   => Database::count('faqs', "`status` = 'active'"),
    'inactive' => Database::count('faqs', "`status` = 'inactive'"),
];

$canEdit   = admin_can('faq.edit');
$canDelete = admin_can('faq.delete');
$hasFilter = $search !== '' || $status !== '' || $category !== '';

$pageTitle    = 'FAQ';
$pageSubtitle = $counts['all'] . ' questions in ' . count($categoryNames) . ' categor'
    . (count($categoryNames) === 1 ? 'y' : 'ies');
$breadcrumbs  = [
    ['label' => 'Dashboard', 'url' => admin_url('dashboard.php')],
    ['label' => 'FAQ'],
];
$pageActions = '<a class="ad-btn" href="' . e(url('faq')) . '" target="_blank" rel="noopener">'
    . icon('external', 'w-4 h-4') . ' View on store</a>';
if (admin_can('faq.create')) {
    $pageActions .= '<a class="ad-btn ad-btn--primary" href="' . e(admin_url('faq/create.php')) . '">'
        . icon('plus', 'w-4 h-4') . ' Add Question</a>';
}

require ADMIN_PATH . '/includes/header.php';
?>

<div class="ad-card">
    <div class="ad-tabs">
        <?php foreach (['' => 'All', 'active' => 'Active', 'inactive' => 'Inactive'] as $key => $label): ?>
            <a class="ad-tab <?= $status === $key ? 'is-active' : '' ?>"
               href="<?= e(url_with(['status' => $key === '' ? null : $key])) ?>">
                <?= e($label) ?>
                <span class="ad-tab__count"><?= (int) ($counts[$key === '' ? 'all' : $key] ?? 0) ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <form class="ad-filters" method="get" action="<?= e(admin_url('faq/')) ?>">
        <?php if ($status !== ''): ?>
            <input type="hidden" name="status" value="<?= e($status) ?>">
        <?php endif; ?>
        <div class="ad-search">
            <?= icon('search', 'w-4 h-4') ?>
            <label class="sik-sr" for="faqSearch">Search questions</label>
            <input class="sik-input" type="search" id="faqSearch" name="q" data-filter-search
                   value="<?= e($search) ?>" placeholder="Search question or answer text&hellip;" autocomplete="off">
        </div>

        <label class="sik-sr" for="faqCategory">Category filter</label>
        <select class="sik-select" id="faqCategory" name="category" data-auto-submit>
            <?= admin_options(array_combine($categoryNames, $categoryNames) ?: [], $category, 'All categories') ?>
        </select>

        <button type="submit" class="ad-btn ad-btn--sm"><?= icon('filter', 'w-4 h-4') ?> Filter</button>
        <?php if ($hasFilter): ?>
            <a class="ad-btn ad-btn--sm" href="<?= e(admin_url('faq/')) ?>">Reset</a>
        <?php endif; ?>
    </form>

    <?php if ($grouped === []): ?>
        <div class="ad-card__body ad-card__body--flush">
            <?= $hasFilter
                ? admin_empty('No questions match', 'Try a different search term or clear the filters.', null, null, 'search')
                : admin_empty(
                    'No questions yet',
                    'The storefront FAQ page groups questions by category. Add the first one to get started.',
                    admin_can('faq.create') ? 'Add Question' : null,
                    admin_can('faq.create') ? admin_url('faq/create.php') : null,
                    'info'
                ) ?>
        </div>
    <?php else: ?>
        <div class="ad-card__body">
            <span class="ad-muted">
                <?= count($faqs) ?> question<?= count($faqs) === 1 ? '' : 's' ?>
                across <?= count($grouped) ?> categor<?= count($grouped) === 1 ? 'y' : 'ies' ?>.
                <?php if ($canEdit): ?>
                    Change a sort number to reorder within its category &mdash; it saves as soon as you leave the field.
                <?php endif; ?>
            </span>
        </div>
    <?php endif; ?>
</div>

<?php if (count($faqs) >= FAQ_LIST_CAP): ?>
    <div class="sik-alert sik-alert--warning">
        <?= icon('alert', 'w-5 h-5') ?>
        <div>
            Only the first <?= FAQ_LIST_CAP ?> questions are shown. Narrow the list with the
            category filter or search to reach the rest.
        </div>
    </div>
<?php endif; ?>

<?php foreach ($grouped as $groupName => $groupFaqs): ?>
    <div class="ad-card">
        <div class="ad-card__head">
            <div>
                <div class="ad-card__title"><?= e($groupName) ?></div>
                <div class="ad-card__sub"><?= count($groupFaqs) ?> question<?= count($groupFaqs) === 1 ? '' : 's' ?></div>
            </div>
            <?php if ($category !== $groupName): ?>
                <a class="ad-btn ad-btn--sm" href="<?= e(url_with(['category' => $groupName])) ?>">Only this category</a>
            <?php endif; ?>
        </div>
        <div class="ad-card__body ad-card__body--flush">
            <div class="ad-tablewrap">
                <table class="ad-table">
                    <thead>
                        <tr>
                            <th style="width:92px">Order</th>
                            <th>Question</th>
                            <th>Answer</th>
                            <th>Status</th>
                            <th class="ad-table__actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($groupFaqs as $faq): ?>
                            <?php $faqId = (int) $faq['id']; ?>
                            <tr>
                                <td>
                                    <?php if ($canEdit): ?>
                                        <label class="sik-sr" for="faqSort<?= $faqId ?>">
                                            Sort order for <?= e(str_limit($faq['question'], 60)) ?>
                                        </label>
                                        <input class="sik-input" type="number" id="faqSort<?= $faqId ?>"
                                               style="width:72px;padding:6px 8px" min="0" max="9999" step="1"
                                               value="<?= (int) $faq['sort_order'] ?>"
                                               data-sort-order data-id="<?= $faqId ?>">
                                    <?php else: ?>
                                        <?= (int) $faq['sort_order'] ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="ad-cellflex__name">
                                        <?php if ($canEdit): ?>
                                            <a href="<?= e(admin_url('faq/edit.php?id=' . $faqId)) ?>">
                                                <?= e(str_limit($faq['question'], 90)) ?>
                                            </a>
                                        <?php else: ?>
                                            <?= e(str_limit($faq['question'], 90)) ?>
                                        <?php endif; ?>
                                    </span>
                                </td>
                                <td class="ad-muted"><?= e(str_limit($faq['answer'], 110)) ?></td>
                                <td><?= admin_state_badge((string) $faq['status']) ?></td>
                                <td class="ad-table__actions">
                                    <?php if ($canEdit): ?>
                                        <a class="ad-btn ad-btn--icon" title="Edit"
                                           aria-label="Edit this question"
                                           href="<?= e(admin_url('faq/edit.php?id=' . $faqId)) ?>">
                                            <?= icon('edit', 'w-4 h-4') ?>
                                        </a>
                                    <?php endif; ?>
                                    <?php if ($canDelete): ?>
                                        <?= admin_delete_form(
                                            admin_url('faq/delete.php'),
                                            $faqId,
                                            'Delete "' . str_limit($faq['question'], 70) . '"? This cannot be undone.'
                                        ) ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<?php if ($canEdit && $faqs !== []): ?>
<script>
    // One number per row rather than drag-and-drop: it survives the category
    // grouping, works on touch, and never needs a second "save order" button.
    document.addEventListener('DOMContentLoaded', function () {
        var endpoint = (window.SIK_CONFIG && SIK_CONFIG.adminUrl ? SIK_CONFIG.adminUrl : '') + '/faq/reorder.php';

        SIK.on('change', '[data-sort-order]', async function () {
            var input = this;
            if (input.dataset.saving === '1') return;

            var value = parseInt(input.value, 10);
            if (isNaN(value) || value < 0) { value = 0; }
            input.value = String(value);

            input.dataset.saving = '1';
            input.disabled = true;

            var result = await SIK.post(endpoint, { id: parseInt(input.dataset.id, 10), sort_order: value });

            input.disabled = false;
            delete input.dataset.saving;

            if (result.success) {
                SIK.toast(result.message || 'Order saved.', 'success');
            } else {
                SIK.toast(result.message || 'Could not save the sort order.', 'error');
            }
        });
    });
</script>
<?php endif; ?>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
