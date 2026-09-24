<?php
/**
 * ShopInnKart Admin - Marketing report.
 *
 * What the promotions actually returned: coupons, deals, flash sales, the
 * newsletter list, popup conversions, and what people typed into search.
 * Campaigns are only credited for orders placed while they were live.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('reports.view');

require_once __DIR__ . '/_shared.php';
require_once __DIR__ . '/_filters.php';

$filters    = report_filter_state();
$dateParams = ['from' => $filters['from_dt'], 'to' => $filters['to_dt']];
$revenueSql = report_revenue_sql();

$couponTotals = Database::fetch(
    'SELECT COUNT(*)                              AS redemptions,
            COALESCE(SUM(o.`discount_amount`), 0) AS discount,
            COALESCE(SUM(o.`total_amount`), 0)    AS revenue
     FROM `orders` o
     WHERE ' . $revenueSql . ' AND o.`coupon_id` IS NOT NULL
       AND o.`created_at` BETWEEN :from AND :to',
    $dateParams
) ?? [];

$allOrderRevenue = (float) Database::fetchColumn(
    'SELECT COALESCE(SUM(o.`total_amount`), 0)
     FROM `orders` o
     WHERE ' . $revenueSql . ' AND o.`created_at` BETWEEN :from AND :to',
    $dateParams
);

$coupons     = report_coupon_performance($filters);
$deals       = report_campaign_performance('deal', $filters);
$flashSales  = report_campaign_performance('flash', $filters);

$newsletterNew = (int) Database::fetchColumn(
    'SELECT COUNT(*) FROM `newsletter_subscribers` WHERE `created_at` BETWEEN :from AND :to',
    $dateParams
);
$newsletterActive = Database::count('newsletter_subscribers', "`status` = 'active'");
$newsletterGone   = Database::count('newsletter_subscribers', "`status` = 'unsubscribed'");

$newsletterSeries = report_fill_series(
    Database::fetchPairs(
        'SELECT ' . report_bucket_sql('`created_at`', $filters['group']) . ' AS bucket, COUNT(*)
         FROM `newsletter_subscribers`
         WHERE `created_at` BETWEEN :from AND :to
         GROUP BY bucket',
        $dateParams
    ),
    $filters
);

$newsletterSources = Database::fetchAll(
    'SELECT `source`, COUNT(*) AS signups,
            COALESCE(SUM(CASE WHEN `status` = \'active\' THEN 1 ELSE 0 END), 0) AS still_active
     FROM `newsletter_subscribers`
     WHERE `created_at` BETWEEN :from AND :to
     GROUP BY `source`
     ORDER BY signups DESC
     LIMIT 10',
    $dateParams
);

// Popup counters are lifetime totals on the popup row, not a per-day journal,
// so this table is never date-filtered and says so.
$popups = Database::fetchAll(
    'SELECT `id`, `name`, `popup_type`, `display_mode`, `status`, `impressions`, `conversions`
     FROM `popups`
     ORDER BY `impressions` DESC, `name` ASC
     LIMIT 20'
);
$popupImpressions = 0;
$popupConversions = 0;
foreach ($popups as $popup) {
    $popupImpressions += (int) $popup['impressions'];
    $popupConversions += (int) $popup['conversions'];
}

$searchTerms = report_search_terms($filters, 25);
$searchStats = Database::fetch(
    'SELECT COUNT(*) AS searches,
            COALESCE(SUM(CASE WHEN `results_count` = 0 THEN 1 ELSE 0 END), 0) AS zero_results,
            COUNT(DISTINCT `query`) AS terms
     FROM `search_logs`
     WHERE `created_at` BETWEEN :from AND :to',
    $dateParams
) ?? [];

$redemptions = (int) ($couponTotals['redemptions'] ?? 0);
$couponSpend = (float) ($couponTotals['discount'] ?? 0);
$couponRev   = (float) ($couponTotals['revenue'] ?? 0);

$revenueOrders = (int) Database::fetchColumn(
    'SELECT COUNT(*) FROM `orders` o
     WHERE ' . $revenueSql . ' AND o.`created_at` BETWEEN :from AND :to',
    $dateParams
);
$couponShare = report_percent((float) $redemptions, (float) $revenueOrders);

$pageTitle    = 'Marketing Report';
$pageSubtitle = report_range_subtitle($filters);
$breadcrumbs  = [
    ['label' => 'Dashboard', 'url' => admin_url('dashboard.php')],
    ['label' => 'Reports'],
    ['label' => 'Marketing'],
];

$pageActions = '';
if (admin_can('coupons.view')) {
    $pageActions = '<a class="ad-btn" href="' . e(admin_url('coupons/')) . '">'
        . icon('percent', 'w-4 h-4') . ' Coupons</a>';
}

require ADMIN_PATH . '/includes/header.php';
?>

<div class="ad-card" style="margin-bottom:18px">
    <?= report_nav('marketing', $filters) ?>
    <?= report_filter_bar('marketing', $filters) ?>
</div>

<!-- ============================ Headline tiles =========================== -->
<div class="ad-grid ad-grid--4" style="margin-bottom:18px">
    <?= admin_stat_card('Coupon Redemptions', number_format($redemptions), 'tag', 'primary',
        report_percent_text($couponShare) . ' of ' . number_format($revenueOrders) . ' revenue-earning orders') ?>
    <?= admin_stat_card('Discount Given', money($couponSpend), 'percent', 'amber',
        'Through coupon codes in this range') ?>
    <?= admin_stat_card('Revenue Influenced', money($couponRev), 'wallet', 'green',
        report_percent_text(report_percent($couponRev, $allOrderRevenue)) . ' of all revenue') ?>
    <?= admin_stat_card('Newsletter Signups', number_format($newsletterNew), 'mail', 'violet',
        number_format($newsletterActive) . ' active · ' . number_format($newsletterGone) . ' unsubscribed',
        admin_can('newsletter.view') ? admin_url('newsletter/') : null) ?>
</div>

<!-- =============================== Coupons =============================== -->
<div class="ad-card">
    <div class="ad-card__head">
        <div>
            <div class="ad-card__title">Coupon performance</div>
            <div class="ad-card__sub">
                Redemptions counted on revenue-earning orders placed in <?= e($filters['label']) ?>
            </div>
        </div>
    </div>
    <div class="ad-card__body ad-card__body--flush">
        <?php if ($coupons === []): ?>
            <?= admin_empty('No coupons yet',
                'Create a coupon and its redemptions will be tracked here.',
                admin_can('coupons.create') ? 'Add coupon' : null,
                admin_can('coupons.create') ? admin_url('coupons/create.php') : null,
                'tag') ?>
        <?php else: ?>
            <div class="ad-tablewrap">
                <table class="ad-table">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Type</th>
                            <th class="ad-table__num">Redemptions</th>
                            <th class="ad-table__num">Discount given</th>
                            <th class="ad-table__num">Revenue influenced</th>
                            <th class="ad-table__num">Avg discount</th>
                            <th class="ad-table__num">Lifetime uses</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($coupons as $row): ?>
                            <?php $uses = max(1, (int) $row['redemptions']); ?>
                            <tr>
                                <td>
                                    <?php if (admin_can('coupons.edit')): ?>
                                        <a class="ad-mono" style="font-weight:700"
                                           href="<?= e(admin_url('coupons/edit.php?id=' . (int) $row['id'])) ?>">
                                            <?= e((string) $row['code']) ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="ad-mono" style="font-weight:700"><?= e((string) $row['code']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="ad-cellflex__name"><?= e(COUPON_TYPES[$row['type']] ?? (string) $row['type']) ?></div>
                                    <div class="ad-cellflex__meta">
                                        <?= $row['type'] === COUPON_TYPE_PERCENTAGE
                                            ? e(number_format((float) $row['value'], 0) . '%')
                                            : e(money((float) $row['value'])) ?>
                                    </div>
                                </td>
                                <td class="ad-table__num"><strong><?= number_format((int) $row['redemptions']) ?></strong></td>
                                <td class="ad-table__num"><?= e(money((float) $row['discount_given'])) ?></td>
                                <td class="ad-table__num"><?= e(money((float) $row['revenue_influenced'])) ?></td>
                                <td class="ad-table__num">
                                    <?= (int) $row['redemptions'] > 0
                                        ? e(money((float) $row['discount_given'] / $uses))
                                        : '<span class="ad-muted">—</span>' ?>
                                </td>
                                <td class="ad-table__num">
                                    <?= number_format((int) $row['used_count']) ?>
                                    <?php if ($row['usage_limit'] !== null): ?>
                                        <span class="ad-muted">/ <?= number_format((int) $row['usage_limit']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?= admin_state_badge((string) $row['status']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ========================= Deals and flash sales ======================= -->
<div class="ad-grid ad-grid--2">
    <div class="ad-card">
        <div class="ad-card__head">
            <div>
                <div class="ad-card__title">Deal performance</div>
                <div class="ad-card__sub">Sales while each deal was live in this range</div>
            </div>
        </div>
        <div class="ad-card__body ad-card__body--flush">
            <?php if ($deals === []): ?>
                <?= admin_empty('No deals ran in this range',
                    'Deals that overlap the selected window appear here.',
                    admin_can('deals.create') ? 'Add deal' : null,
                    admin_can('deals.create') ? admin_url('deals/create.php') : null,
                    'fire') ?>
            <?php else: ?>
                <div class="ad-tablewrap">
                    <table class="ad-table">
                        <thead>
                            <tr>
                                <th>Deal</th>
                                <th class="ad-table__num">Products</th>
                                <th class="ad-table__num">Orders</th>
                                <th class="ad-table__num">Units</th>
                                <th class="ad-table__num">Revenue</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($deals as $row): ?>
                                <tr>
                                    <td>
                                        <div class="ad-cellflex__name">
                                            <?php if (admin_can('deals.edit')): ?>
                                                <a href="<?= e(admin_url('deals/edit.php?id=' . (int) $row['id'])) ?>">
                                                    <?= e(str_limit((string) $row['title'], 40)) ?>
                                                </a>
                                            <?php else: ?>
                                                <?= e(str_limit((string) $row['title'], 40)) ?>
                                            <?php endif; ?>
                                        </div>
                                        <div class="ad-cellflex__meta">
                                            <?= e(format_date($row['start_time'], 'd M')) ?>
                                            &ndash; <?= e(format_date($row['end_time'], 'd M Y')) ?>
                                            <?php if ($row['is_live']): ?>
                                                <span class="sik-status sik-status--green">Live</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="ad-table__num"><?= number_format((int) $row['product_count']) ?></td>
                                    <td class="ad-table__num"><?= number_format($row['sales']['orders']) ?></td>
                                    <td class="ad-table__num"><strong><?= number_format($row['sales']['units']) ?></strong></td>
                                    <td class="ad-table__num"><strong><?= e(money($row['sales']['revenue'])) ?></strong></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="ad-card">
        <div class="ad-card__head">
            <div>
                <div class="ad-card__title">Flash sale performance</div>
                <div class="ad-card__sub">Sales while each sale was live in this range</div>
            </div>
        </div>
        <div class="ad-card__body ad-card__body--flush">
            <?php if ($flashSales === []): ?>
                <?= admin_empty('No flash sales ran in this range',
                    'Flash sales that overlap the selected window appear here.',
                    admin_can('flash_sales.create') ? 'Add flash sale' : null,
                    admin_can('flash_sales.create') ? admin_url('flash-sales/create.php') : null,
                    'zap') ?>
            <?php else: ?>
                <div class="ad-tablewrap">
                    <table class="ad-table">
                        <thead>
                            <tr>
                                <th>Flash sale</th>
                                <th class="ad-table__num">Products</th>
                                <th class="ad-table__num">Units</th>
                                <th class="ad-table__num">Reserved sold</th>
                                <th class="ad-table__num">Revenue</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($flashSales as $row): ?>
                                <tr>
                                    <td>
                                        <div class="ad-cellflex__name">
                                            <?php if (admin_can('flash_sales.edit')): ?>
                                                <a href="<?= e(admin_url('flash-sales/edit.php?id=' . (int) $row['id'])) ?>">
                                                    <?= e(str_limit((string) $row['title'], 40)) ?>
                                                </a>
                                            <?php else: ?>
                                                <?= e(str_limit((string) $row['title'], 40)) ?>
                                            <?php endif; ?>
                                        </div>
                                        <div class="ad-cellflex__meta">
                                            <?= e(format_date($row['start_time'], 'd M')) ?>
                                            &ndash; <?= e(format_date($row['end_time'], 'd M Y')) ?>
                                            <?php if ($row['is_live']): ?>
                                                <span class="sik-status sik-status--green">Live</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="ad-table__num"><?= number_format((int) $row['product_count']) ?></td>
                                    <td class="ad-table__num"><strong><?= number_format($row['sales']['units']) ?></strong></td>
                                    <td class="ad-table__num"><?= number_format((int) $row['stock_sold']) ?></td>
                                    <td class="ad-table__num"><strong><?= e(money($row['sales']['revenue'])) ?></strong></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ====================== Newsletter and popups ========================== -->
<div class="ad-grid ad-grid--2">
    <div class="ad-card">
        <div class="ad-card__head">
            <div>
                <div class="ad-card__title">Newsletter growth by <?= e($filters['group']) ?></div>
                <div class="ad-card__sub"><?= number_format($newsletterNew) ?> signups in <?= e($filters['label']) ?></div>
            </div>
        </div>
        <div class="ad-card__body"><?= admin_bar_chart($newsletterSeries, '', 190) ?></div>
        <?php if ($newsletterSources !== []): ?>
            <div class="ad-card__body ad-card__body--flush">
                <div class="ad-tablewrap">
                    <table class="ad-table">
                        <thead>
                            <tr>
                                <th>Signup source</th>
                                <th class="ad-table__num">Signups</th>
                                <th class="ad-table__num">Still subscribed</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($newsletterSources as $row): ?>
                                <tr>
                                    <td><?= e(ucfirst(str_replace('_', ' ', (string) $row['source']))) ?></td>
                                    <td class="ad-table__num"><strong><?= number_format((int) $row['signups']) ?></strong></td>
                                    <td class="ad-table__num"><?= number_format((int) $row['still_active']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="ad-card">
        <div class="ad-card__head">
            <div>
                <div class="ad-card__title">Popup impressions vs conversions</div>
                <div class="ad-card__sub">
                    Lifetime counters &mdash; not limited to the selected range
                </div>
            </div>
        </div>
        <div class="ad-card__body ad-card__body--flush">
            <?php if ($popups === []): ?>
                <?= admin_empty('No popups configured',
                    'Popups record an impression when shown and a conversion when acted on.',
                    admin_can('banners.create') ? 'Add popup' : null,
                    admin_can('banners.create') ? admin_url('popups/create.php') : null,
                    'bell') ?>
            <?php else: ?>
                <div class="ad-tablewrap">
                    <table class="ad-table">
                        <thead>
                            <tr>
                                <th>Popup</th>
                                <th class="ad-table__num">Impressions</th>
                                <th class="ad-table__num">Conversions</th>
                                <th class="ad-table__num">Rate</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($popups as $row): ?>
                                <tr>
                                    <td>
                                        <div class="ad-cellflex__name">
                                            <?php if (admin_can('banners.edit')): ?>
                                                <a href="<?= e(admin_url('popups/edit.php?id=' . (int) $row['id'])) ?>">
                                                    <?= e(str_limit((string) $row['name'], 36)) ?>
                                                </a>
                                            <?php else: ?>
                                                <?= e(str_limit((string) $row['name'], 36)) ?>
                                            <?php endif; ?>
                                        </div>
                                        <div class="ad-cellflex__meta">
                                            <?= e(ucfirst(str_replace('_', ' ', (string) $row['popup_type']))) ?>
                                            &middot; <?= e((string) $row['display_mode']) ?>
                                        </div>
                                    </td>
                                    <td class="ad-table__num"><?= number_format((int) $row['impressions']) ?></td>
                                    <td class="ad-table__num"><strong><?= number_format((int) $row['conversions']) ?></strong></td>
                                    <td class="ad-table__num">
                                        <?= e(report_percent_text(report_percent(
                                            (float) $row['conversions'],
                                            (float) $row['impressions']
                                        ))) ?>
                                    </td>
                                    <td><?= admin_state_badge((string) $row['status']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr style="background:var(--ad-bg)">
                                <td><strong>All popups</strong></td>
                                <td class="ad-table__num"><strong><?= number_format($popupImpressions) ?></strong></td>
                                <td class="ad-table__num"><strong><?= number_format($popupConversions) ?></strong></td>
                                <td class="ad-table__num">
                                    <strong><?= e(report_percent_text(report_percent(
                                        (float) $popupConversions,
                                        (float) $popupImpressions
                                    ))) ?></strong>
                                </td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ============================ Search terms ============================= -->
<div class="ad-card">
    <div class="ad-card__head">
        <div>
            <div class="ad-card__title">Top search terms</div>
            <div class="ad-card__sub">
                <?= number_format((int) ($searchStats['searches'] ?? 0)) ?> searches
                across <?= number_format((int) ($searchStats['terms'] ?? 0)) ?> distinct terms
                &middot; <?= number_format((int) ($searchStats['zero_results'] ?? 0)) ?> returned nothing
            </div>
        </div>
    </div>
    <div class="ad-card__body ad-card__body--flush">
        <?php if ($searchTerms === []): ?>
            <?= admin_empty('Nobody searched in this range',
                'Storefront searches are logged as customers use them.', null, null, 'search') ?>
        <?php else: ?>
            <div class="ad-tablewrap">
                <table class="ad-table">
                    <thead>
                        <tr>
                            <th>Term</th>
                            <th class="ad-table__num">Searches</th>
                            <th class="ad-table__num">Sessions</th>
                            <th class="ad-table__num">Best result count</th>
                            <th>Outcome</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($searchTerms as $row): ?>
                            <?php $isDeadEnd = (int) $row['best_results'] === 0; ?>
                            <tr>
                                <td>
                                    <?php if ($isDeadEnd): ?>
                                        <strong><?= e(str_limit((string) $row['query'], 60)) ?></strong>
                                    <?php else: ?>
                                        <a href="<?= e(url('search.php?q=' . urlencode((string) $row['query']))) ?>"
                                           target="_blank" rel="noopener">
                                            <?= e(str_limit((string) $row['query'], 60)) ?>
                                        </a>
                                    <?php endif; ?>
                                </td>
                                <td class="ad-table__num"><strong><?= number_format((int) $row['searches']) ?></strong></td>
                                <td class="ad-table__num"><?= number_format((int) $row['sessions']) ?></td>
                                <td class="ad-table__num"><?= number_format((int) $row['best_results']) ?></td>
                                <td>
                                    <?php if ($isDeadEnd): ?>
                                        <span class="sik-status sik-status--red">No results</span>
                                    <?php else: ?>
                                        <span class="sik-status sik-status--green">Found products</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
    <?php if ((int) ($searchStats['zero_results'] ?? 0) > 0): ?>
        <?php // The two causes worth checking, in order: the product is missing from
              // the catalogue, or it is there under a name customers do not use. ?>
        <div class="ad-card__foot ad-muted" style="font-size:var(--ad-text-xs)">
            Terms marked <strong>No results</strong> are demand you are not serving.
        </div>
    <?php endif; ?>
</div>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
