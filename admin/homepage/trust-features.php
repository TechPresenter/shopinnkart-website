<?php
/**
 * ShopInnKart Admin - Trust features.
 *
 * The little "Free delivery / 7-day returns / Genuine products" badges. Two
 * placements read this table: the hero slider stacks the `hero` rows beside
 * the slides, and the trust widget renders the `strip` rows as its own band.
 *
 * List and form share one screen because the rows are three fields long —
 * bouncing to a separate page to change a subtitle would be pure ceremony.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('homepage.edit');

$placements = ['strip' => 'Trust strip', 'hero' => 'Beside the hero'];

// ---------------------------------------------------------------------------
//  Delete — posted from admin_delete_form() with ?do=delete on the action.
// ---------------------------------------------------------------------------
if (is_post() && ($_GET['do'] ?? '') === 'delete') {
    csrf_require();

    $id = input_int('id');
    $feature = $id > 0
        ? Database::fetch('SELECT `id`, `title` FROM `trust_features` WHERE `id` = :id', ['id' => $id])
        : null;

    if ($feature === null) {
        flash('error', 'That trust feature no longer exists.');
    } else {
        Database::delete('trust_features', '`id` = :id', ['id' => $id]);
        log_activity('trust_feature.deleted', 'trust_feature', $id, 'Deleted trust feature "' . $feature['title'] . '"');
        admin_after_write();
        flash('success', 'Trust feature "' . $feature['title'] . '" deleted.');
    }

    redirect(admin_url('homepage/trust-features.php'));
}

// ---------------------------------------------------------------------------
//  Create / update
// ---------------------------------------------------------------------------
$feature = [
    'id'         => 0,
    'placement'  => 'strip',
    'title'      => '',
    'subtitle'   => '',
    'icon'       => 'shield',
    'link'       => '',
    'sort_order' => 0,
    'status'     => 'active',
];
$errors = [];

if (is_post()) {
    csrf_require();

    $id = input_int('id');
    $submitted = [
        'id'         => $id,
        'placement'  => (string) input('placement', 'strip'),
        'title'      => (string) input('title', ''),
        'subtitle'   => (string) input('subtitle', ''),
        // No default: an unrecognised stored icon leaves the radio group with
        // nothing checked, and silently rewriting it to "shield" would hide that.
        'icon'       => (string) input('icon', ''),
        'link'       => (string) input('link', ''),
        'sort_order' => input_int('sort_order', 0),
        'status'     => (string) input('status', 'active'),
    ];
    $feature = $submitted;

    $v = new Validator($submitted, ['title' => 'Title', 'sort_order' => 'Sort order']);
    $v->required('title')->max('title', 120)
      ->max('subtitle', 180)
      ->max('link', 255)
      ->in('placement', array_keys($placements))
      ->in('status', ['active', 'inactive'])
      ->between('sort_order', 0, 9999)
      ->required('icon', 'Pick an icon from the list.')
      ->rule('icon', $submitted['icon'] === '' || icon_exists($submitted['icon']), 'Pick an icon from the list.');

    if ($id > 0 && !Database::exists('trust_features', '`id` = :id', ['id' => $id])) {
        $v->rule('title', false, 'That trust feature no longer exists.');
    }

    if ($v->fails()) {
        $errors = $v->errors();
        flash('error', 'Please correct the highlighted fields.');
    } else {
        $columns = [
            'placement'  => $submitted['placement'],
            'title'      => $submitted['title'],
            'subtitle'   => $submitted['subtitle'] !== '' ? $submitted['subtitle'] : null,
            'icon'       => $submitted['icon'],
            'link'       => $submitted['link'] !== '' ? $submitted['link'] : null,
            'sort_order' => $submitted['sort_order'],
            'status'     => $submitted['status'],
        ];

        if ($id > 0) {
            Database::update('trust_features', $columns, '`id` = :id', ['id' => $id]);
            log_activity('trust_feature.updated', 'trust_feature', $id, 'Updated trust feature "' . $columns['title'] . '"');
            $message = 'Trust feature "' . $columns['title'] . '" saved.';
        } else {
            $id = Database::insert('trust_features', $columns);
            log_activity('trust_feature.created', 'trust_feature', $id, 'Added trust feature "' . $columns['title'] . '"');
            $message = 'Trust feature "' . $columns['title'] . '" added.';
        }

        admin_after_write();
        flash('success', $message);
        redirect(admin_url('homepage/trust-features.php'));
    }
}

// ---------------------------------------------------------------------------
//  Read
// ---------------------------------------------------------------------------
$editId = input_int('edit');
if ($editId > 0 && $errors === [] && !is_post()) {
    $existing = Database::fetch('SELECT * FROM `trust_features` WHERE `id` = :id', ['id' => $editId]);
    if ($existing === null) {
        flash('error', 'That trust feature no longer exists.');
        redirect(admin_url('homepage/trust-features.php'));
    }
    $feature = $existing;
}

$features = Database::fetchAll(
    'SELECT * FROM `trust_features` ORDER BY `placement` ASC, `sort_order` ASC, `id` ASC'
);

$isEdit    = (int) $feature['id'] > 0;
$iconValue = (string) $feature['icon'];

$pageTitle    = 'Trust Features';
$pageSubtitle = count($features) . ' badge' . (count($features) === 1 ? '' : 's') . ' across both placements.';
$breadcrumbs  = [
    ['label' => 'Dashboard',        'url' => admin_url('dashboard.php')],
    ['label' => 'Homepage Builder', 'url' => admin_url('homepage/')],
    ['label' => 'Trust Features'],
];

require ADMIN_PATH . '/includes/header.php';
?>
<style>
    /* Scoped to this screen: the icon set is small enough to pick visually,
       which beats typing a key that has to match icon_paths(). */
    .ad-iconpick {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(84px, 1fr));
        gap: 8px;
        max-height: 232px;
        overflow-y: auto;
        padding: 10px;
        border: 1px solid var(--ad-border);
        border-radius: 10px;
        background: var(--ad-bg);
    }
    .ad-iconpick input { position: absolute; opacity: 0; width: 0; height: 0; }
    .ad-iconpick__box {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 5px;
        padding: 9px 4px;
        border: 1px solid var(--ad-border);
        border-radius: 9px;
        background: #fff;
        cursor: pointer;
        font-size: 11.5px;
        color: var(--ad-muted);
        text-align: center;
    }
    /* The key wraps instead of clipping: "shield-check" is wider than an 84px
       tile at 11.5px, and an ellipsis would hide which icon you are picking
       with nothing to reveal the rest. The names break at their own hyphens;
       `anywhere` covers a long one with no hyphen in it. */
    .ad-iconpick__box em { font-style: normal; max-width: 100%; line-height: 1.3; overflow-wrap: anywhere; }
    .ad-iconpick input:checked + .ad-iconpick__box {
        border-color: var(--ad-primary);
        color: var(--ad-primary);
        box-shadow: 0 0 0 2px rgba(244, 81, 30, .16);
    }
    .ad-iconpick input:focus-visible + .ad-iconpick__box { outline: 2px solid var(--ad-primary); outline-offset: 2px; }
</style>

<div class="ad-grid ad-grid--sidebar">
    <div>
        <div class="ad-card" style="margin:0">
            <div class="ad-card__head">
                <div>
                    <div class="ad-card__title">All trust features</div>
                    <div class="ad-card__sub">Lower sort numbers come first within a placement.</div>
                </div>
                <?php if ($isEdit): ?>
                    <a class="ad-btn ad-btn--sm" href="<?= e(admin_url('homepage/trust-features.php')) ?>">
                        <?= icon('plus', 'w-4 h-4') ?> New feature
                    </a>
                <?php endif; ?>
            </div>

            <div class="ad-card__body ad-card__body--flush">
                <?php if ($features === []): ?>
                    <?= admin_empty(
                        'No trust features yet',
                        'Add badges like "Free delivery over ₹499" or "7-day easy returns" — they show under the hero and in the trust strip.',
                        null,
                        null,
                        'shield'
                    ) ?>
                <?php else: ?>
                    <div class="ad-tablewrap">
                        <table class="ad-table">
                            <thead>
                                <tr>
                                    <th>Badge</th>
                                    <th>Placement</th>
                                    <th>Link</th>
                                    <th class="ad-table__num">Sort</th>
                                    <th>Status</th>
                                    <th class="ad-table__actions">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($features as $row): ?>
                                    <?php $rowId = (int) $row['id']; ?>
                                    <tr>
                                        <td>
                                            <div class="ad-cellflex">
                                                <span class="ad-stat__icon ad-stat__icon--green" style="width:34px;height:34px;flex:none">
                                                    <?= icon((string) $row['icon'], 'w-4 h-4') ?>
                                                </span>
                                                <span style="min-width:0">
                                                    <span class="ad-cellflex__name" style="display:block">
                                                        <a href="<?= e(admin_url('homepage/trust-features.php?edit=' . $rowId)) ?>">
                                                            <?= e($row['title']) ?>
                                                        </a>
                                                    </span>
                                                    <?php if (!empty($row['subtitle'])): ?>
                                                        <span class="ad-cellflex__meta"><?= e($row['subtitle']) ?></span>
                                                    <?php endif; ?>
                                                </span>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="sik-status sik-status--<?= $row['placement'] === 'hero' ? 'violet' : 'blue' ?>">
                                                <?= e($placements[$row['placement']] ?? $row['placement']) ?>
                                            </span>
                                        </td>
                                        <td class="ad-muted ad-mono">
                                            <?= !empty($row['link']) ? e(str_limit((string) $row['link'], 30)) : '&mdash;' ?>
                                        </td>
                                        <td class="ad-table__num"><?= (int) $row['sort_order'] ?></td>
                                        <td><?= admin_state_badge((string) $row['status']) ?></td>
                                        <td class="ad-table__actions">
                                            <a class="ad-btn ad-btn--icon" title="Edit"
                                               aria-label="Edit <?= e_attr($row['title']) ?>"
                                               href="<?= e(admin_url('homepage/trust-features.php?edit=' . $rowId)) ?>">
                                                <?= icon('edit', 'w-4 h-4') ?>
                                            </a>
                                            <?= admin_delete_form(
                                                admin_url('homepage/trust-features.php?do=delete'),
                                                $rowId,
                                                'Delete "' . $row['title'] . '"? This cannot be undone.'
                                            ) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div>
        <form class="ad-form" method="post" data-guard-unsaved>
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int) $feature['id'] ?>">

            <div class="ad-card" style="margin:0">
                <div class="ad-card__head">
                    <div class="ad-card__title"><?= $isEdit ? 'Edit trust feature' : 'Add trust feature' ?></div>
                </div>
                <div class="ad-card__body" style="display:grid;gap:14px">

                    <div class="ad-field">
                        <label class="sik-label" for="tfTitle">Title <span class="req">*</span></label>
                        <input class="sik-input<?= isset($errors['title']) ? ' is-invalid' : '' ?>" type="text"
                               id="tfTitle" name="title" maxlength="120" required
                               value="<?= e($feature['title']) ?>" placeholder="Free delivery">
                        <?php if (isset($errors['title'])): ?>
                            <span class="sik-error"><?= e($errors['title']) ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="ad-field">
                        <label class="sik-label" for="tfSubtitle">Subtitle</label>
                        <input class="sik-input<?= isset($errors['subtitle']) ? ' is-invalid' : '' ?>" type="text"
                               id="tfSubtitle" name="subtitle" maxlength="180"
                               value="<?= e($feature['subtitle'] ?? '') ?>" placeholder="On orders above ₹499">
                        <?php if (isset($errors['subtitle'])): ?>
                            <span class="sik-error"><?= e($errors['subtitle']) ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="ad-field">
                        <label class="sik-label" for="tfPlacement">Placement</label>
                        <select class="sik-select" id="tfPlacement" name="placement">
                            <?= admin_options($placements, $feature['placement']) ?>
                        </select>
                        <span class="sik-help">
                            "Trust strip" feeds the trust widget; "beside the hero" stacks under the hero slider.
                        </span>
                    </div>

                    <div class="ad-field">
                        <label class="sik-label" for="tfLink">Link</label>
                        <input class="sik-input<?= isset($errors['link']) ? ' is-invalid' : '' ?>" type="text"
                               id="tfLink" name="link" maxlength="255"
                               value="<?= e($feature['link'] ?? '') ?>" placeholder="page/shipping-policy">
                        <?php if (isset($errors['link'])): ?>
                            <span class="sik-error"><?= e($errors['link']) ?></span>
                        <?php else: ?>
                            <span class="sik-help">Relative to the store URL. Leave blank to render the badge as plain text.</span>
                        <?php endif; ?>
                    </div>

                    <div class="ad-row ad-row--2">
                        <div class="ad-field">
                            <label class="sik-label" for="tfSort">Sort order</label>
                            <input class="sik-input<?= isset($errors['sort_order']) ? ' is-invalid' : '' ?>" type="number"
                                   id="tfSort" name="sort_order" min="0" max="9999" step="1"
                                   value="<?= (int) $feature['sort_order'] ?>">
                            <?php if (isset($errors['sort_order'])): ?>
                                <span class="sik-error"><?= e($errors['sort_order']) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="ad-field">
                            <label class="sik-label" for="tfStatus">Status</label>
                            <select class="sik-select" id="tfStatus" name="status">
                                <?= admin_options(['active' => 'Active', 'inactive' => 'Inactive'], $feature['status']) ?>
                            </select>
                        </div>
                    </div>

                    <div class="ad-field">
                        <label class="sik-label" id="tfIconLabel">Icon</label>
                        <div class="ad-iconpick" role="radiogroup" aria-labelledby="tfIconLabel">
                            <?php foreach (icon_names() as $name): ?>
                                <label>
                                    <input type="radio" name="icon" value="<?= e_attr($name) ?>"
                                           <?= $iconValue === $name ? 'checked' : '' ?>>
                                    <span class="ad-iconpick__box">
                                        <?= icon($name, 'w-5 h-5') ?><em><?= e($name) ?></em>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <?php if (isset($errors['icon'])): ?>
                            <span class="sik-error"><?= e($errors['icon']) ?></span>
                        <?php elseif ($iconValue !== '' && !icon_exists($iconValue)): ?>
                            <span class="sik-error">
                                The saved icon &ldquo;<?= e($iconValue) ?>&rdquo; is not in the icon set, so this badge
                                currently draws a fallback glyph. Pick a replacement above.
                            </span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="ad-card__foot">
                    <?php if ($isEdit): ?>
                        <a class="ad-btn" href="<?= e(admin_url('homepage/trust-features.php')) ?>">Cancel</a>
                    <?php endif; ?>
                    <button type="submit" class="ad-btn ad-btn--primary">
                        <?= icon('check', 'w-4 h-4') ?>
                        <?= $isEdit ? 'Save Feature' : 'Add Feature' ?>
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
