<?php
/**
 * ShopInnKart Admin - Banners list.
 *
 * Grouped by position, because "which slide is third in the hero" is the
 * question this screen exists to answer and a flat sortable list hides it.
 * Banners are a small curated set — the whole matching set is rendered rather
 * than paged, so the sort order inside each group stays readable.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('banners.view');

require_once ADMIN_PATH . '/includes/marketing.php';
require_once __DIR__ . '/_save.php';

$positions = banner_positions();
$notes     = banner_position_notes();

$search   = trim((string) ($_GET['q'] ?? ''));
$position = admin_filter('position', array_keys($positions));
$state    = admin_filter('state', array_keys(marketing_state_options()));

$where  = ['1'];
$params = [];

if ($search !== '') {
    // Each occurrence needs its own placeholder: with emulated prepares off,
    // PDO binds a named marker exactly once.
    $where[] = '(b.`title` LIKE :q_title OR b.`title_accent` LIKE :q_accent OR b.`subtitle` LIKE :q_sub OR b.`badge` LIKE :q_badge)';
    $params['q_title'] = $params['q_accent'] = $params['q_sub'] = $params['q_badge'] = '%' . $search . '%';
}
if ($position !== '') {
    $where[] = 'b.`position` = :position';
    $params['position'] = $position;
}
if ($state !== '') {
    $where[] = '(' . marketing_state_where($state, 'b', 'start_date', 'end_date') . ')';
}
$whereSql = implode(' AND ', $where);

$banners = Database::fetchAll(
    "SELECT b.`id`, b.`position`, b.`title`, b.`title_accent`, b.`subtitle`, b.`badge`,
            b.`desktop_image`, b.`mobile_image`, b.`button_text`, b.`button_url`,
            b.`button2_text`, b.`bg_color`, b.`text_color`, b.`sort_order`,
            b.`start_date`, b.`end_date`, b.`status`
     FROM `banners` b
     WHERE {$whereSql}
     ORDER BY b.`sort_order` ASC, b.`id` ASC",
    $params
);

$grouped = array_fill_keys(array_keys($positions), []);
foreach ($banners as $banner) {
    $grouped[(string) $banner['position']][] = $banner;
}

$positionCounts = Database::fetchPairs('SELECT `position`, COUNT(*) FROM `banners` GROUP BY `position`');
$liveCount = Database::count('banners', marketing_state_where('live', 'banners', 'start_date', 'end_date'));

$canEdit    = admin_can('banners.edit');
$canCreate  = admin_can('banners.create');
$canDelete  = admin_can('banners.delete');
$hasFilters = $search !== '' || $position !== '' || $state !== '';

$pageTitle    = 'Banners';
$pageSubtitle = count($banners) . ' shown · ' . $liveCount . ' live on the storefront right now';
$breadcrumbs  = [
    ['label' => 'Dashboard', 'url' => admin_url('dashboard.php')],
    ['label' => 'Banners'],
];
$pageActions = '';
if ($canCreate) {
    $pageActions = '<a class="ad-btn ad-btn--primary" href="' . e(admin_url('banners/create.php')) . '">'
        . icon('plus', 'w-4 h-4') . ' Add Banner</a>';
}

require ADMIN_PATH . '/includes/header.php';
?>

<div class="ad-card">
    <div class="ad-tabs">
        <a class="ad-tab <?= $position === '' ? 'is-active' : '' ?>"
           href="<?= e(url_with(['position' => null])) ?>">
            All positions
            <span class="ad-tab__count"><?= (int) array_sum($positionCounts) ?></span>
        </a>
        <?php foreach ($positions as $key => $label): ?>
            <a class="ad-tab <?= $position === $key ? 'is-active' : '' ?>"
               href="<?= e(url_with(['position' => $key])) ?>">
                <?= e($label) ?>
                <span class="ad-tab__count"><?= (int) ($positionCounts[$key] ?? 0) ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <form class="ad-filters" method="get" action="<?= e(admin_url('banners/')) ?>">
        <?php if ($position !== ''): ?>
            <input type="hidden" name="position" value="<?= e($position) ?>">
        <?php endif; ?>
        <div class="ad-search">
            <?= icon('search', 'w-4 h-4') ?>
            <label class="sik-sr" for="bannerSearch">Search banners</label>
            <input class="sik-input" type="search" id="bannerSearch" name="q" data-filter-search
                   value="<?= e($search) ?>" placeholder="Search by title, accent or badge&hellip;" autocomplete="off">
        </div>

        <label class="sik-sr" for="bannerStateFilter">Schedule state</label>
        <select class="sik-select" id="bannerStateFilter" name="state" data-auto-submit>
            <?= admin_options(marketing_state_options(), $state, 'Any state') ?>
        </select>

        <button type="submit" class="ad-btn ad-btn--sm"><?= icon('filter', 'w-4 h-4') ?> Filter</button>
        <?php if ($hasFilters): ?>
            <a class="ad-btn ad-btn--sm" href="<?= e(admin_url('banners/')) ?>">Reset</a>
        <?php endif; ?>
    </form>
</div>

<?php if ($banners === []): ?>
    <div class="ad-card">
        <div class="ad-card__body ad-card__body--flush">
            <?= $hasFilters
                ? admin_empty('No banners match', 'Try a different search term or clear the filters.', null, null, 'search')
                : admin_empty(
                    'No banners yet',
                    'Banners drive the hero slider, the promo block and the category artwork.',
                    $canCreate ? 'Add Banner' : null,
                    $canCreate ? admin_url('banners/create.php') : null,
                    'monitor'
                ) ?>
        </div>
    </div>
<?php else: ?>
    <?php foreach ($positions as $key => $label): ?>
        <?php if ($position !== '' && $position !== $key) { continue; } ?>
        <?php $rows = $grouped[$key]; ?>

        <div class="ad-card">
            <div class="ad-card__head">
                <div>
                    <div class="ad-card__title"><?= e($label) ?></div>
                    <div class="ad-card__sub"><?= e($notes[$key] ?? '') ?></div>
                </div>
                <?php if ($canCreate): ?>
                    <a class="ad-btn ad-btn--sm" href="<?= e(admin_url('banners/create.php?position=' . urlencode($key))) ?>">
                        <?= icon('plus', 'w-4 h-4') ?> Add
                    </a>
                <?php endif; ?>
            </div>

            <div class="ad-card__body ad-card__body--flush">
                <?php if ($rows === []): ?>
                    <p class="ad-muted" style="padding:18px;font-size:13px">
                        Nothing in this position<?= $hasFilters ? ' matches the current filters' : ' yet' ?>.
                    </p>
                <?php else: ?>
                    <div class="ad-tablewrap">
                        <table class="ad-table">
                            <thead>
                                <tr>
                                    <th style="width:120px">Preview</th>
                                    <th>Banner</th>
                                    <th>Buttons</th>
                                    <th class="ad-table__num">Sort</th>
                                    <th>Window</th>
                                    <th>State</th>
                                    <th class="ad-table__actions">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $banner): ?>
                                    <?php
                                    $bannerId = (int) $banner['id'];
                                    $rowState = marketing_state($banner['start_date'], $banner['end_date'], (string) $banner['status']);
                                    $title    = trim((string) ($banner['title'] ?? ''));
                                    $rowLabel = $title !== '' ? $title : 'Banner #' . $bannerId;
                                    $artwork  = $banner['desktop_image'] ?: $banner['mobile_image'];
                                    $chipBg   = (string) ($banner['bg_color'] ?? '') !== '' ? (string) $banner['bg_color'] : '#0F2143';
                                    $chipText = (string) ($banner['text_color'] ?? '') !== '' ? (string) $banner['text_color'] : '#FFFFFF';
                                    ?>
                                    <tr>
                                        <td>
                                            <?php if (!empty($artwork)): ?>
                                                <img src="<?= e(img_url($artwork)) ?>" alt=""
                                                     width="104" height="58" loading="lazy"
                                                     style="width:104px;height:58px;object-fit:cover;border-radius:8px;
                                                            border:1px solid var(--ad-border);background:<?= e_attr($chipBg) ?>">
                                            <?php else: ?>
                                                <!-- No artwork: show the colour pair the storefront will paint instead. -->
                                                <span style="display:flex;align-items:center;justify-content:center;
                                                             width:104px;height:58px;border-radius:8px;font-size:11px;
                                                             font-weight:700;text-align:center;padding:4px;overflow:hidden;
                                                             border:1px solid var(--ad-border);
                                                             background:<?= e_attr($chipBg) ?>;color:<?= e_attr($chipText) ?>">
                                                    <?php /* Left as a plain cut, deliberately: this is the
                                                             stand-in for missing artwork, and the untruncated
                                                             label is the very next cell. A title here would be
                                                             a tooltip on a decoration and a second reading of
                                                             the same words for a screen reader. */ ?>
                                                    <?= e(str_limit($rowLabel, 22)) ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="ad-cellflex__name" style="display:block">
                                                <?php if ($canEdit): ?>
                                                    <a href="<?= e(admin_url('banners/edit.php?id=' . $bannerId)) ?>">
                                                        <?= e($rowLabel) ?>
                                                    </a>
                                                <?php else: ?>
                                                    <?= e($rowLabel) ?>
                                                <?php endif; ?>
                                                <?php if (!empty($banner['title_accent'])): ?>
                                                    <em style="color:var(--ad-primary);font-style:normal">
                                                        <?= e($banner['title_accent']) ?>
                                                    </em>
                                                <?php endif; ?>
                                            </span>
                                            <span class="ad-cellflex__meta">
                                                <?php if (!empty($banner['badge'])): ?>
                                                    <span class="sik-status sik-status--violet"><?= e($banner['badge']) ?></span>
                                                <?php endif; ?>
                                                <?= !empty($banner['subtitle'])
                                                    ? admin_trunc((string) $banner['subtitle'], 46)
                                                    : '<em>No subtitle</em>' ?>
                                            </span>
                                            <?php if (empty($banner['desktop_image']) && $key === 'hero'): ?>
                                                <span class="sik-status sik-status--amber" style="margin-top:4px">
                                                    Hero slides need a desktop image
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="font-size:var(--ad-text-sm)">
                                            <?php if (!empty($banner['button_text'])): ?>
                                                <div><strong><?= e($banner['button_text']) ?></strong></div>
                                                <?php /* admin_trunc(), not e(str_limit(...)): a button's
                                                         destination cut at 28 characters was unreadable and
                                                         unrecoverable - "…/collections/fes…" told you nothing
                                                         about where the banner actually sends a shopper. The
                                                         whole URL is now the title. `break` because a URL has
                                                         no spaces to wrap at. */ ?>
                                                <div class="ad-cellflex__meta ad-mono">
                                                    <?= admin_trunc((string) ($banner['button_url'] ?? ''), 28, true) ?>
                                                </div>
                                            <?php else: ?>
                                                <span class="ad-muted">&mdash;</span>
                                            <?php endif; ?>
                                            <?php if (!empty($banner['button2_text'])): ?>
                                                <div class="ad-cellflex__meta">+ <?= e($banner['button2_text']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="ad-table__num"><?= (int) $banner['sort_order'] ?></td>
                                        <td class="ad-muted" style="font-size:12px;white-space:nowrap">
                                            <?= e(marketing_window_text($banner['start_date'], $banner['end_date'])) ?>
                                        </td>
                                        <td><?= marketing_state_badge($rowState) ?></td>
                                        <td class="ad-table__actions">
                                            <?php if ($canEdit): ?>
                                                <a class="ad-btn ad-btn--icon" title="Edit"
                                                   aria-label="Edit <?= e_attr($rowLabel) ?>"
                                                   href="<?= e(admin_url('banners/edit.php?id=' . $bannerId)) ?>">
                                                    <?= icon('edit', 'w-4 h-4') ?>
                                                </a>
                                            <?php endif; ?>
                                            <?php if ($canDelete): ?>
                                                <?= admin_delete_form(
                                                    admin_url('banners/delete.php'),
                                                    $bannerId,
                                                    'Delete "' . $rowLabel . '"? Its uploaded images are removed from the server too.'
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
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
