<?php
/**
 * ShopInnKart Admin - Analytics: speed as visitors felt it.
 *
 * Core Web Vitals from real visits, not from a lab run. analytics_vitals()
 * takes an optional device and page type, which is the whole reason this screen
 * is worth its own tab: a store that is fast on a desktop and slow on a phone
 * has one number that hides the problem and two that name it.
 *
 * WHAT THE FIGURES CAN AND CANNOT SAY, and metrics.php is explicit about it:
 *
 *   - the good / needs-work / poor COUNTS are exact for any range, because
 *     counts add up;
 *   - the 75th percentile is NOT. A week's percentile is not the average of
 *     seven daily percentiles, and the samples it would need are deleted with
 *     the raw rows. So the column is labelled "typical" and the worst single
 *     day is printed beside it, because that is the day worth opening.
 *
 * For a one-day range the typical figure IS that day's p75, and the screen says
 * so rather than hedging about something exact.
 *
 * READ-ONLY: no POST handler, nothing written.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('analytics.view');

require_once __DIR__ . '/_shared.php';

$state = analytics_admin_state();
$range = $state['range'];

// No analytics_summary() here: nothing on this screen divides by visits, and
// analytics_vitals() already triggers the lazy rollup that every metric
// function triggers. One fewer query for a figure that would not be printed.
$quality = analytics_quality($range);

// analytics_vitals() takes its own device argument, so the range bar's device
// filter is dropped here: two device controls on one screen disagreeing about
// which one won is worse than one that works.
$deviceId = (string) ($_GET['vdev'] ?? '');
$device   = $deviceId !== '' && ctype_digit($deviceId) ? (int) $deviceId : null;

$typeId   = (string) ($_GET['ptype'] ?? '');
$pageType = $typeId !== '' && ctype_digit($typeId) ? (int) $typeId : null;

$vitals   = analytics_vitals($range, $device, $pageType);
$allTotal = 0;
foreach ($vitals as $vital) {
    $allTotal += $vital['samples'];
}

/** What each metric measures, in one line the owner can act on. */
const ANALYTICS_VITAL_MEANS = [
    'LCP'  => 'How long until the main thing on the page appeared.',
    'INP'  => 'How long the page took to answer a tap.',
    'CLS'  => 'How much the page jumped about while loading.',
    'FCP'  => 'How long until anything at all appeared.',
    'TTFB' => 'How long the server took to answer.',
];

/** A vitals figure in the unit it is stored in. CLS is kept as score x 1000. */
function an_perf_value(array $vital, int $raw): string
{
    return $vital['unit'] === 'score'
        ? number_format($raw / 1000, 3)
        : number_format($raw) . ' ms';
}

/** Good / needs work / poor, from the thresholds metrics.php returns. */
function an_perf_verdict(array $vital): string
{
    $p75 = $vital['p75_typical'];

    if ($vital['samples'] === 0) {
        return '<span class="ad-muted">&mdash;</span>';
    }
    if ($p75 <= $vital['good_under']) {
        return admin_state_badge('approved');
    }
    if ($p75 >= $vital['poor_over']) {
        return admin_state_badge('rejected');
    }

    return admin_state_badge('pending');
}

$pageTitle    = 'Analytics - Speed';
$pageSubtitle = analytics_admin_subtitle($state);
$breadcrumbs  = analytics_admin_breadcrumbs('Speed', $state);

require ADMIN_PATH . '/includes/header.php';
?>

<div class="ad-card" style="margin-bottom:18px">
    <?= analytics_admin_tabs('performance', $state) ?>
    <?= analytics_admin_range_bar('performance', $state, false) ?>

    <form class="ad-filters" method="get" action="<?= e(admin_url('analytics/performance.php')) ?>">
        <input type="hidden" name="r" value="<?= e_attr($range['preset']) ?>">
        <?php if ($range['preset'] === 'custom'): ?>
            <input type="hidden" name="from" value="<?= e_attr($range['from']) ?>">
            <input type="hidden" name="to" value="<?= e_attr($range['to']) ?>">
        <?php endif; ?>

        <label class="sik-label" for="anVdev" style="margin:0">Split by</label>
        <?php
        $devices = ['' => 'All devices'];
        foreach (AN_DEVICES as $id => $name) {
            $devices[(string) $id] = ucfirst($name);
        }
        $types = ['' => 'All page types'];
        foreach (AN_PAGE_TYPES as $id => $name) {
            $types[(string) $id] = ucfirst($name);
        }
        ?>
        <select class="sik-select" id="anVdev" name="vdev">
            <?= admin_options($devices, $device === null ? '' : (string) $device) ?>
        </select>
        <select class="sik-select" name="ptype" aria-label="Page type">
            <?= admin_options($types, $pageType === null ? '' : (string) $pageType) ?>
        </select>
        <button type="submit" class="ad-btn ad-btn--primary ad-btn--sm">Apply</button>
    </form>
</div>

<?php if ($vitals === [] || $allTotal === 0): ?>

    <?php
    $settings = admin_can('settings.view') ? admin_url('settings/analytics.php') : null;

    if (!$quality['counting']) {
        echo admin_card('', admin_empty(
            'Counting is switched off',
            'No speed samples are being collected. Turn counting on in Settings > Analytics.',
            $settings !== null ? 'Open Analytics settings' : null,
            $settings,
            'zap'
        ), ['flush' => true]);
    } elseif ($device !== null || $pageType !== null) {
        echo admin_card('', admin_empty(
            'No samples for this split',
            'The period has speed samples, but none for the device or page type you picked.',
            null,
            null,
            'zap'
        ), ['flush' => true]);
    } else {
        echo admin_card('', admin_empty(
            'No speed samples yet',
            'A visitor\'s browser reports these as they leave a page. Real visits fill this in.',
            null,
            null,
            'zap'
        ), ['flush' => true]);
    }
    ?>

<?php else: ?>

    <div class="ad-grid ad-grid--4" style="margin-bottom:18px">
        <?php foreach ($vitals as $vital): ?>
            <?= admin_stat_card(
                $vital['name'],
                an_perf_value($vital, $vital['p75_typical']),
                'zap',
                $vital['p75_typical'] <= $vital['good_under']
                    ? 'green'
                    : ($vital['p75_typical'] >= $vital['poor_over'] ? 'red' : 'amber'),
                an_admin_rate_text($vital['good_pct'], $vital['samples']) . ' good of '
                    . number_format($vital['samples']) . ' samples'
            ) ?>
        <?php endforeach; ?>
    </div>

    <?= admin_card_open('Core Web Vitals', [
        'sub' => $range['days'] <= 1
            ? 'One day, so the typical figure is that day\'s 75th percentile.'
            : 'Typical is a sample-weighted average of daily percentiles.',
        'actions' => an_admin_why('analytics_vitals() over an_daily_vitals. The good, needs-work and poor '
            . 'columns are counts, so they are exact for any range. The typical column is not a true '
            . 'percentile of the range: the raw samples a range-wide percentile needs are deleted with '
            . 'the visit detail, so the daily 75th percentiles are averaged by sample count instead. '
            . 'The worst-day column names the single day worth opening.', 'web vitals'),
    ]) ?>
        <div class="ad-tablewrap">
            <table class="ad-table ad-table--stack">
                <thead><tr>
                    <th scope="col">Metric</th>
                    <th scope="col">Measures</th>
                    <th scope="col" class="ad-table__num">Typical</th>
                    <th scope="col" class="ad-table__num">Worst day</th>
                    <th scope="col" class="ad-table__num">Good under</th>
                    <th scope="col" class="ad-table__num">Samples</th>
                    <th scope="col" class="ad-table__num">Good</th>
                    <th scope="col" class="ad-table__num">Poor</th>
                    <th scope="col">Verdict</th>
                </tr></thead>
                <tbody>
                <?php foreach ($vitals as $vital): ?>
                    <tr>
                        <td><strong class="ad-strong"><?= e($vital['name']) ?></strong></td>
                        <td><?= e(ANALYTICS_VITAL_MEANS[$vital['name']] ?? '') ?></td>
                        <td class="ad-table__num"><?= e(an_perf_value($vital, $vital['p75_typical'])) ?></td>
                        <td class="ad-table__num">
                            <?php if ($vital['exact']): ?>
                                <span class="ad-muted"<?= admin_tip('One day only, so this is the same reading.') ?>>&mdash;</span>
                            <?php else: ?>
                                <?= e(an_perf_value($vital, $vital['p75_worst'])) ?>
                            <?php endif; ?>
                        </td>
                        <td class="ad-table__num"><?= e(an_perf_value($vital, $vital['good_under'])) ?></td>
                        <td class="ad-table__num"><?= e(number_format($vital['samples'])) ?></td>
                        <td class="ad-table__num"><?= an_admin_rate($vital['good_pct'], $vital['samples'], 'samples') ?></td>
                        <td class="ad-table__num"><?= an_admin_rate($vital['poor_pct'], $vital['samples'], 'samples') ?></td>
                        <td><?= an_perf_verdict($vital) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?= admin_card_close('<span class="ad-text-xs ad-muted">' . e(analytics_admin_freshness($quality))
        . '</span>') ?>

    <div class="ad-grid ad-grid--2" style="margin-top:18px">
        <?php foreach ($vitals as $vital): ?>
            <?php if ($vital['samples'] === 0) { continue; } ?>
            <?= admin_card_open($vital['name'] . ' - how the samples fell', [
                'sub' => number_format($vital['samples']) . ' samples in this period.',
                'level' => 3,
            ]) ?>
                <?= admin_bar_chart([
                    ['label' => 'Good',       'value' => $vital['good']],
                    ['label' => 'Needs work', 'value' => $vital['ni']],
                    ['label' => 'Poor',       'value' => $vital['poor']],
                ], '', 150) ?>
                <?= admin_kv([
                    ['Good under', e(an_perf_value($vital, $vital['good_under']))],
                    ['Poor over',  e(an_perf_value($vital, $vital['poor_over']))],
                ]) ?>
            <?= admin_card_close() ?>
        <?php endforeach; ?>
    </div>

    <div class="ad-card" style="margin-top:18px">
        <div class="ad-card__body">
            <?= analytics_admin_notes($quality, $state) ?>
        </div>
    </div>

<?php endif; ?>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
