<?php
/**
 * ShopInnKart Admin - Appearance & Customization.
 *
 * ONE organised entry point for everything that decides how the storefront
 * looks. It is a hub, not a seventeenth settings form: every control it
 * describes is owned by a screen that already exists, and this page links to
 * that screen rather than growing a second copy of its form.
 *
 * What it adds that no individual screen can:
 *
 *   - the whole surface in one place, grouped the way an operator thinks about
 *     it rather than the way the tables are laid out;
 *   - the live state of each area, read from the database on every load, so
 *     "is the wishlist on?" is answered on the card instead of two clicks away;
 *   - a real preview - an iframe of the actual storefront at a real device
 *     width, not a mock-up;
 *   - reset-to-shipped-defaults, per section and for everything, with the exact
 *     list of what will change shown before it happens;
 *   - a snapshot taken before every destructive action, and an undo.
 *
 * Read admin/appearance/_registry.php for the map, and the comment above
 * APPEARANCE_SNAPSHOT_KEY for why this ships a snapshot instead of a draft.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('settings.view');

require_once ADMIN_PATH . '/appearance/_registry.php';

$canEdit  = admin_can('settings.edit');
$sections = appearance_sections();
$specs    = appearance_specs();
$snapshot = appearance_snapshot_read();

$unknown   = appearance_unknown_keys();
$unclaimed = appearance_unclaimed_keys();

// ---------------------------------------------------------------------------
//  What a reset would actually do, per section.
//
//  Built here rather than in the modal so the confirmation lists real keys with
//  their real current and default values. A confirmation that says "are you
//  sure?" without saying what changes is not a confirmation.
// ---------------------------------------------------------------------------
$resetPlans = [];
$totalChanged = 0;

foreach ($sections as $key => $section) {
    if (($section['reset'] ?? '') !== $key) {
        continue;
    }

    $rows = [];
    foreach (APPEARANCE_KEYS[$key] ?? [] as $settingKey) {
        if (!array_key_exists($settingKey, $specs['defaults'])) {
            continue;
        }
        $current = appearance_value($settingKey);
        $default = (string) $specs['defaults'][$settingKey];
        if ($current === $default) {
            continue;
        }
        $rows[] = [
            'label'   => (string) ($specs['spec'][$settingKey]['label'] ?? $settingKey),
            'key'     => $settingKey,
            'current' => $current === '' ? '(blank)' : mb_substr($current, 0, 60),
            'default' => $default === '' ? '(blank)' : mb_substr($default, 0, 60),
        ];
    }

    $totalChanged += count($rows);
    $resetPlans[$key] = [
        'label' => (string) $section['label'],
        'note'  => (string) ($section['reset_note'] ?? ''),
        'total' => count(APPEARANCE_KEYS[$key] ?? []),
        'rows'  => $rows,
    ];
}

// The "everything" plan is the union of the section plans, so the two can never
// disagree about what is about to change.
$allRows = [];
foreach ($resetPlans as $plan) {
    foreach ($plan['rows'] as $row) {
        $allRows[] = $row;
    }
}
$resetPlans['__all'] = [
    'label' => 'every appearance section',
    'note'  => 'This restores all ' . count($resetPlans) . ' resettable sections at once. Menus, footer '
        . 'links, popups, floating buttons and categories are content, not settings, and are not touched.',
    'total' => array_sum(array_map(static fn (array $p): int => $p['total'], $resetPlans)),
    'rows'  => $allRows,
];

// ---------------------------------------------------------------------------
//  Preview targets. Real storefront URLs, nothing simulated.
// ---------------------------------------------------------------------------
$previewPages = [
    'Home'      => url(),
    'Shop'      => url('shop.php'),
    'Cart'      => url('cart.php'),
    'Wishlist'  => url('wishlist.php'),
    'Compare'   => url('compare.php'),
    'Track'     => url('track-order.php'),
];

$pageTitle    = 'Appearance';
$pageSubtitle = 'Everything that decides how the storefront looks, in one place.';
$breadcrumbs  = [
    ['label' => 'Dashboard', 'url' => admin_url('dashboard.php')],
    ['label' => 'Appearance'],
];

$pageActions = '<a class="ad-btn" href="' . e(url()) . '" target="_blank" rel="noopener">'
    . icon('external', 'w-4 h-4') . ' View storefront</a>';

if ($canEdit) {
    $pageActions .= '<button type="button" class="ad-btn ad-btn--danger" data-reset-open="__all">'
        . icon('refresh', 'w-4 h-4') . ' Reset all</button>';
}

require ADMIN_PATH . '/includes/header.php';
?>

<style>
    /* Scoped to this screen. Nothing here is a component other pages reuse, so
       it stays out of admin.css rather than adding names to a shared file. */
    .apx-layout { display: grid; gap: 18px; align-items: start; }
    @media (min-width: 1200px) { .apx-layout { grid-template-columns: minmax(0, 1fr) 420px; } }

    .apx-grid { display: grid; gap: 14px; grid-template-columns: 1fr; }
    @media (min-width: 720px)  { .apx-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    @media (min-width: 1200px) { .apx-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    @media (min-width: 1600px) { .apx-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); } }

    .apx-card { display: flex; flex-direction: column; gap: 10px; padding: 16px; }
    .apx-card__head { display: flex; align-items: flex-start; gap: 10px; }
    .apx-card__icon {
        flex: none; width: 34px; height: 34px; border-radius: 9px;
        display: grid; place-items: center;
        background: color-mix(in srgb, var(--ad-primary) 12%, transparent);
        color: var(--ad-primary);
    }
    .apx-card__title { font-size: 15px; font-weight: 700; line-height: 1.25; margin: 0; }
    .apx-card__blurb { font-size: 12.5px; line-height: 1.55; color: var(--ad-muted); margin: 0; }

    .apx-chips { display: flex; flex-wrap: wrap; gap: 6px; }
    .apx-chip {
        display: inline-flex; align-items: center; gap: 5px;
        font-size: 11.5px; line-height: 1; padding: 5px 8px; border-radius: 999px;
        border: 1px solid var(--ad-border); background: var(--ad-surface); color: var(--ad-muted);
        white-space: nowrap;
    }
    .apx-chip b { font-weight: 700; color: var(--ad-text); }
    .apx-chip--green b { color: #15803D; }
    .apx-chip--amber b { color: #B45309; }
    .apx-chip--navy  b { color: #0F2143; }

    .apx-sw { display: flex; flex-wrap: wrap; gap: 6px; }
    .apx-sw__item { display: flex; align-items: center; gap: 6px; font-size: 11.5px; color: var(--ad-muted); }
    .apx-sw__dot {
        width: 18px; height: 18px; border-radius: 6px; flex: none;
        border: 1px solid rgba(0, 0, 0, .16); box-shadow: inset 0 0 0 1px rgba(255, 255, 255, .35);
    }

    .apx-links { display: flex; flex-wrap: wrap; gap: 8px; margin-top: auto; padding-top: 4px; }
    .apx-note {
        font-size: 11.5px; line-height: 1.5; color: var(--ad-muted);
        border-left: 2px solid var(--ad-border); padding-left: 8px; margin: 0;
    }

    .apx-state { font-size: 11px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; }
    .apx-state--none { color: #B45309; }

    /* Preview ------------------------------------------------------------- */
    .apx-preview { position: sticky; top: 84px; }
    .apx-preview__bar { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
    .apx-preview__stage {
        position: relative; overflow: hidden; border-radius: 10px;
        border: 1px solid var(--ad-border); background: var(--ad-bg);
        height: 560px;
    }
    .apx-preview__frame {
        position: absolute; top: 0; left: 0; border: 0; background: #fff;
        transform-origin: 0 0;
    }
    .apx-seg { display: inline-flex; border: 1px solid var(--ad-border); border-radius: 8px; overflow: hidden; }
    .apx-seg button {
        border: 0; background: var(--ad-surface); color: var(--ad-muted);
        font: inherit; font-size: 12px; padding: 6px 10px; cursor: pointer; min-height: var(--tap, 44px);
    }
    .apx-seg button.is-on { background: var(--ad-primary); color: #fff; font-weight: 600; }

    /* Reset modal --------------------------------------------------------- */
    .apx-diff { max-height: 260px; overflow: auto; border: 1px solid var(--ad-border); border-radius: 8px; }
    .apx-diff table { width: 100%; border-collapse: collapse; font-size: 12px; }
    .apx-diff th, .apx-diff td { text-align: left; padding: 7px 10px; border-bottom: 1px solid var(--ad-border); }
    .apx-diff th { font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: var(--ad-muted); }
    .apx-diff td:last-child { font-family: ui-monospace, Menlo, Consolas, monospace; }
    .apx-confirm { display: flex; gap: 8px; align-items: flex-start; font-size: 13px; margin-top: 12px; }
</style>

<div class="apx-layout">

    <div>
        <?php if ($unknown !== [] || $unclaimed !== []): ?>
            <div class="ad-card" style="margin-bottom:14px">
                <div class="ad-card__head">
                    <div>
                        <h2 class="ad-card__title">Coverage audit</h2>
                        <p class="ad-card__sub">Checked on every load, so this page can never quietly
                            stop covering a setting.</p>
                    </div>
                </div>
                <div class="ad-card__body" style="font-size:12.5px;line-height:1.6">
                    <?php if ($unknown !== []): ?>
                        <p style="color:#B45309;margin:0 0 8px">
                            <strong><?= count($unknown) ?> key(s) claimed by a section but unknown to any
                            field spec</strong> — these would reset nothing:
                            <code><?= e(implode(', ', $unknown)) ?></code>
                        </p>
                    <?php endif; ?>
                    <?php if ($unclaimed !== []): ?>
                        <p class="ad-muted" style="margin:0">
                            <strong><?= count($unclaimed) ?> setting(s) no Appearance section claims.</strong>
                            The admin chrome colours and the social URLs are excluded deliberately;
                            anything else here is a gap:
                            <code><?= e(implode(', ', $unclaimed)) ?></code>
                        </p>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="apx-grid">
            <?php foreach ($sections as $key => $section): ?>
                <?php
                $resetKey = (string) ($section['reset'] ?? '');
                $changed  = $resetKey === $key ? count($resetPlans[$key]['rows']) : 0;
                ?>
                <section class="ad-card apx-card" id="sec-<?= e_attr($key) ?>">
                    <div class="apx-card__head">
                        <span class="apx-card__icon"><?= icon((string) $section['icon'], 'w-4 h-4') ?></span>
                        <div style="flex:1;min-width:0">
                            <h2 class="apx-card__title"><?= e($section['label']) ?></h2>
                            <?php if ($section['status'] === 'none'): ?>
                                <span class="apx-state apx-state--none">Not configurable yet</span>
                            <?php elseif ($resetKey === $key): ?>
                                <span class="apx-state ad-muted">
                                    <?= $changed ?> of <?= count(APPEARANCE_KEYS[$key]) ?> changed from default
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <p class="apx-card__blurb"><?= e($section['blurb']) ?></p>

                    <?php if (!empty($section['swatches'])): ?>
                        <div class="apx-sw">
                            <?php foreach ($section['swatches'] as $swatchKey): ?>
                                <?php $hex = appearance_value($swatchKey); ?>
                                <span class="apx-sw__item" title="<?= e_attr($swatchKey . ': ' . ($hex ?: 'not set')) ?>">
                                    <span class="apx-sw__dot" style="background:<?= e_attr(preg_match('/^#[0-9A-Fa-f]{6}$/', $hex) === 1 ? $hex : 'transparent') ?>"></span>
                                    <?= e((string) ($specs['spec'][$swatchKey]['label'] ?? $swatchKey)) ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($section['chips'])): ?>
                        <div class="apx-chips">
                            <?php foreach ($section['chips'] as $chip): ?>
                                <span class="apx-chip apx-chip--<?= e_attr($chip['tone']) ?>">
                                    <?= e($chip['label']) ?> <b><?= e($chip['value']) ?></b>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($section['reset_note'])): ?>
                        <p class="apx-note"><?= e($section['reset_note']) ?></p>
                    <?php endif; ?>

                    <div class="apx-links">
                        <?php foreach ($section['links'] as $link): ?>
                            <?php
                            // A link to a screen this admin cannot open is a dead
                            // link with extra steps: the destination would only
                            // bounce off its own admin_require(). Hidden here for
                            // the same reason the sidebar hides it.
                            if (!empty($link['permission']) && !admin_can($link['permission'])) {
                                continue;
                            }
                            ?>
                            <a class="ad-btn ad-btn--sm<?= !empty($link['primary']) ? ' ad-btn--primary' : '' ?>"
                               href="<?= e($link['url']) ?>"
                               <?= !empty($link['external']) ? 'target="_blank" rel="noopener"' : '' ?>>
                                <?= e($link['label']) ?>
                                <?php if (!empty($link['external'])): ?><?= icon('external', 'w-3.5 h-3.5') ?><?php endif; ?>
                            </a>
                        <?php endforeach; ?>

                        <?php if ($canEdit && $resetKey === $key): ?>
                            <button type="button" class="ad-btn ad-btn--sm ad-btn--danger-ghost"
                                    data-reset-open="<?= e_attr($key) ?>"
                                    <?= $changed === 0 ? 'disabled title="Already on the shipped defaults"' : '' ?>>
                                <?= icon('refresh', 'w-3.5 h-3.5') ?> Reset section
                            </button>
                        <?php endif; ?>
                    </div>
                </section>
            <?php endforeach; ?>
        </div>

        <!-- Snapshot / draft honesty ------------------------------------- -->
        <div class="ad-card" style="margin-top:14px">
            <div class="ad-card__head">
                <div>
                    <h2 class="ad-card__title">Snapshot &amp; undo</h2>
                    <p class="ad-card__sub">Taken automatically before every reset.</p>
                </div>
            </div>
            <div class="ad-card__body" style="font-size:12.5px;line-height:1.65">
                <?php if ($snapshot !== null): ?>
                    <p style="margin:0 0 10px">
                        Last snapshot: <strong><?= e((string) ($snapshot['taken_at'] ?? '?')) ?></strong>
                        by <?= e((string) ($snapshot['by'] ?? 'Admin')) ?> —
                        <?= count((array) $snapshot['values']) ?> setting(s).
                        <br><span class="ad-muted"><?= e((string) ($snapshot['reason'] ?? '')) ?></span>
                    </p>
                    <?php if ($canEdit): ?>
                        <?php
                        /* Warning, not danger: the current values are
                           snapshotted on the way past, so this is the one
                           reset on the screen that can be walked back. */
                        ?>
                        <form method="post" action="<?= e(admin_url('appearance/reset.php')) ?>"
                              <?= admin_confirm_form_attrs(
                                  'Put every setting back to the snapshot taken '
                                      . (string) ($snapshot['taken_at'] ?? '')
                                      . '. The values you have now are snapshotted first, so this is reversible.',
                                  ['title' => 'Roll back to the snapshot?', 'label' => 'Roll back', 'tone' => 'warning']
                              ) ?>>
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="undo">
                            <input type="hidden" name="confirm" value="1">
                            <button type="submit" class="ad-btn ad-btn--navy">
                                <?= icon('rotate', 'w-4 h-4') ?> Restore this snapshot
                            </button>
                        </form>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="ad-muted" style="margin:0 0 10px">
                        No snapshot yet. One is written automatically the first time you reset anything.
                    </p>
                <?php endif; ?>

                <hr style="border:0;border-top:1px solid var(--ad-border);margin:14px 0">

                <p style="margin:0 0 6px"><strong>Why there is no “Save draft / Publish” here.</strong></p>
                <p class="ad-muted" style="margin:0">
                    A draft has to be stored somewhere that is not live, and publishing has to move it.
                    That would be honest only if it covered everything this page links to — and most of
                    what it links to is not settings but rows: menu items, footer links, popups, floating
                    buttons, categories, all of which their own builders write live. Drafting three
                    settings groups while the Menu Builder still published immediately would put a
                    <em>Save draft</em> button on a page where it is a lie for most of the controls.
                    So the hub does the useful half of the same job instead: it snapshots the current
                    appearance before it changes anything, and lets you put it back in one click.
                </p>
            </div>
        </div>
    </div>

    <!-- Live preview ----------------------------------------------------- -->
    <div class="apx-preview">
        <div class="ad-card">
            <div class="ad-card__head">
                <div>
                    <h2 class="ad-card__title">Live preview</h2>
                    <p class="ad-card__sub">The real storefront in an iframe — not a mock-up.</p>
                </div>
            </div>
            <div class="ad-card__body">
                <div class="apx-preview__bar" style="margin-bottom:10px">
                    <span class="apx-seg" role="group" aria-label="Preview width">
                        <button type="button" data-preview-width="390">390</button>
                        <button type="button" data-preview-width="768">768</button>
                        <button type="button" data-preview-width="1440" class="is-on">1440</button>
                    </span>
                    <select class="sik-select" data-preview-page style="flex:1;min-width:120px">
                        <?php foreach ($previewPages as $name => $target): ?>
                            <option value="<?= e_attr($target) ?>"><?= e($name) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="ad-btn ad-btn--sm ad-btn--icon" data-preview-reload
                            title="Reload preview" aria-label="Reload preview">
                        <?= icon('refresh', 'w-4 h-4') ?>
                    </button>
                </div>

                <div class="apx-preview__stage" data-preview-stage>
                    <iframe class="apx-preview__frame" data-preview-frame
                            src="<?= e(url()) ?>" title="Storefront preview"
                            loading="lazy" referrerpolicy="same-origin"></iframe>
                </div>

                <p class="ad-muted" style="font-size:11.5px;line-height:1.5;margin:10px 0 0">
                    Scaled to fit, so 390 is a real 390px viewport rather than a narrow desktop. It renders
                    as a visitor sees it, popups included. Reload after saving on any linked screen.
                </p>
            </div>
        </div>
    </div>
</div>

<?php if ($canEdit): ?>
<!-- Reset confirmation ------------------------------------------------- -->
<div class="ad-modal" id="apxResetModal" role="dialog" aria-modal="true" aria-labelledby="apxResetTitle">
    <div class="ad-modal__backdrop" data-modal-close></div>
    <div class="ad-modal__panel ad-modal__panel--lg">
        <form method="post" action="<?= e(admin_url('appearance/reset.php')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="reset_section" data-reset-action>
            <input type="hidden" name="section" value="" data-reset-section>

            <div class="ad-modal__head">
                <h2 id="apxResetTitle">Reset <span data-reset-label>section</span></h2>
                <button type="button" class="ad-iconbtn" data-modal-close aria-label="Close">
                    <?= icon('close', 'w-4 h-4') ?>
                </button>
            </div>

            <div class="ad-modal__body">
                <p style="font-size:13px;line-height:1.6;margin:0 0 10px">
                    This restores the shipped defaults — the values the store came with. It writes
                    settings only: no menu, footer, popup, floating-button or category row is touched.
                    A snapshot of the current values is taken first.
                </p>
                <p class="apx-note" data-reset-note style="margin:0 0 12px"></p>

                <p style="font-size:12.5px;margin:0 0 6px">
                    <strong data-reset-count>0</strong> setting(s) will change:
                </p>
                <div class="apx-diff">
                    <table>
                        <thead>
                            <tr><th>Setting</th><th>Now</th><th>Will become</th></tr>
                        </thead>
                        <tbody data-reset-rows></tbody>
                    </table>
                </div>

                <label class="apx-confirm">
                    <input type="checkbox" name="confirm" value="1" data-reset-confirm required>
                    <span>I understand this overwrites the values above and cannot be undone except
                          through the snapshot.</span>
                </label>
            </div>

            <div class="ad-modal__foot">
                <button type="button" class="ad-btn" data-modal-close>Cancel</button>
                <button type="submit" class="ad-btn ad-btn--danger" data-reset-submit disabled>
                    <?= icon('refresh', 'w-4 h-4') ?> Reset to defaults
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
(function () {
    'use strict';

    /* ------------------------------------------------------------------
       Live preview.

       The iframe loads the real storefront. Width buttons set a real viewport
       width on the frame and scale it down to fit the column, so 390 shows the
       phone layout rather than a squeezed desktop one - a transform does not
       change the layout viewport the page inside is laid out against.
       ------------------------------------------------------------------ */
    var stage = document.querySelector('[data-preview-stage]');
    var frame = document.querySelector('[data-preview-frame]');
    var width = 1440;

    function fit() {
        if (!stage || !frame) return;
        var avail = stage.clientWidth;
        var scale = Math.min(1, avail / width);
        frame.style.width = width + 'px';
        frame.style.height = Math.round(stage.clientHeight / scale) + 'px';
        frame.style.transform = 'scale(' + scale + ')';
    }

    document.querySelectorAll('[data-preview-width]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('[data-preview-width]').forEach(function (b) {
                b.classList.toggle('is-on', b === btn);
            });
            width = parseInt(btn.dataset.previewWidth, 10) || 1440;
            fit();
        });
    });

    var pageSelect = document.querySelector('[data-preview-page]');
    if (pageSelect) {
        pageSelect.addEventListener('change', function () { frame.src = pageSelect.value; });
    }

    var reload = document.querySelector('[data-preview-reload]');
    if (reload) {
        reload.addEventListener('click', function () { frame.src = frame.src; });
    }

    window.addEventListener('resize', fit);
    fit();

    /* ------------------------------------------------------------------
       Reset confirmation.

       The plans are rendered server-side into JSON so the dialog lists the real
       keys with their real current and default values. Nothing is computed in
       the browser, so what the dialog promises is what the endpoint writes.
       ------------------------------------------------------------------ */
    var plans = <?= e_json($resetPlans) ?>;
    var modal = document.getElementById('apxResetModal');
    if (!modal) return;

    var elLabel   = modal.querySelector('[data-reset-label]');
    var elNote    = modal.querySelector('[data-reset-note]');
    var elCount   = modal.querySelector('[data-reset-count]');
    var elRows    = modal.querySelector('[data-reset-rows]');
    var elSection = modal.querySelector('[data-reset-section]');
    var elAction  = modal.querySelector('[data-reset-action]');
    var elConfirm = modal.querySelector('[data-reset-confirm]');
    var elSubmit  = modal.querySelector('[data-reset-submit]');

    elConfirm.addEventListener('change', function () { elSubmit.disabled = !elConfirm.checked; });

    document.querySelectorAll('[data-reset-open]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var key = btn.dataset.resetOpen;
            var plan = plans[key];
            if (!plan) return;

            elLabel.textContent = plan.label;
            elNote.textContent = plan.note || '';
            elNote.style.display = plan.note ? '' : 'none';
            elCount.textContent = String(plan.rows.length);
            elSection.value = key === '__all' ? '' : key;
            elAction.value = key === '__all' ? 'reset_all' : 'reset_section';
            elConfirm.checked = false;
            elSubmit.disabled = true;

            elRows.innerHTML = '';
            if (!plan.rows.length) {
                var empty = document.createElement('tr');
                var cell = document.createElement('td');
                cell.colSpan = 3;
                cell.textContent = 'Everything in this section already matches the shipped defaults.';
                empty.appendChild(cell);
                elRows.appendChild(empty);
            }
            plan.rows.forEach(function (row) {
                var tr = document.createElement('tr');
                [row.label, row.current, row.default].forEach(function (text) {
                    var td = document.createElement('td');
                    td.textContent = text;
                    tr.appendChild(td);
                });
                elRows.appendChild(tr);
            });

            if (window.Admin && window.Admin.openModal) window.Admin.openModal('apxResetModal');
            else modal.classList.add('is-open');
        });
    });
})();
</script>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
