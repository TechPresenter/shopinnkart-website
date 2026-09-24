<?php
/**
 * ShopInnKart Admin - Social profiles.
 *
 * The footer and the Organization schema both read this group and skip any
 * blank entry, so leaving a field empty is how you remove a network rather
 * than a separate switch.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('settings.view');

require_once ADMIN_PATH . '/settings/_layout.php';

/** setting key => [icon, label, placeholder] */
const SOCIAL_NETWORKS = [
    'social_facebook'  => ['facebook',  'Facebook',    'https://facebook.com/yourpage'],
    'social_instagram' => ['instagram', 'Instagram',   'https://instagram.com/yourhandle'],
    'social_twitter'   => ['twitter',   'X (Twitter)', 'https://x.com/yourhandle'],
    'social_youtube'   => ['youtube',   'YouTube',     'https://youtube.com/@yourchannel'],
    'social_linkedin'  => ['linkedin',  'LinkedIn',    'https://linkedin.com/company/yourcompany'],
    'social_pinterest' => ['pinterest', 'Pinterest',   'https://pinterest.com/yourprofile'],
];

$spec = [];
foreach (SOCIAL_NETWORKS as $key => [$iconName, $label, $placeholder]) {
    $spec[$key] = [
        'type'        => 'url',
        'label'       => $label,
        'max'         => 255,
        'placeholder' => $placeholder,
    ];
}

// Admin > Appearance reads THIS spec through settings_spec_harvest(). Every
// entry's shipped default is blank - a fresh install ships with no profiles -
// which is exactly why the Appearance hub refuses to reset this section: see
// the `reset` note on the Social Links card.
if (defined('SETTINGS_SPEC_ONLY')) {
    return ['spec' => $spec, 'group' => 'social'];
}

if (is_post()) {
    settings_handle_save('social', 'social', $spec, settings_group('social'));
}

$stored = settings_group('social');
$errors = errors_pull();
$values = settings_values($spec, $stored);

$filled = 0;
foreach (array_keys(SOCIAL_NETWORKS) as $key) {
    if (trim((string) ($stored[$key] ?? '')) !== '') {
        $filled++;
    }
}

$pageTitle    = 'Social Profiles';
$pageSubtitle = 'The links behind the footer icons and the Organization schema.';
$breadcrumbs  = settings_breadcrumbs('social');

require ADMIN_PATH . '/includes/header.php';
?>

<?= settings_tabs('social') ?>

<div class="ad-grid ad-grid--sidebar">
    <form class="ad-form" method="post" data-guard-unsaved>
        <?= csrf_field() ?>

        <div class="ad-card" style="margin:0">
            <div class="ad-card__head">
                <div>
                    <div class="ad-card__title">Profile links</div>
                    <div class="ad-card__sub">Full URLs. Blank hides that network everywhere.</div>
                </div>
            </div>
            <div class="ad-card__body" style="display:grid;gap:4px">
                <?php foreach (SOCIAL_NETWORKS as $key => [$iconName, $label, $placeholder]): ?>
                    <div style="display:flex;gap:12px;align-items:flex-start">
                        <span style="flex:none;width:38px;height:38px;border-radius:9px;margin-top:26px;
                                     display:inline-flex;align-items:center;justify-content:center;
                                     background:var(--ad-bg);color:<?= trim((string) $values[$key]) !== '' ? 'var(--ad-primary)' : 'var(--ad-muted)' ?>">
                            <?= icon($iconName, 'w-5 h-5') ?>
                        </span>
                        <div style="flex:1;min-width:0">
                            <?= settings_field($key, $spec, $values, $errors) ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?= settings_save_bar($filled . ' of ' . count(SOCIAL_NETWORKS) . ' networks currently linked.') ?>
        </div>
    </form>

    <div style="display:grid;gap:18px;align-content:start">
        <div class="ad-card" style="margin:0">
            <div class="ad-card__head">
                <div>
                    <div class="ad-card__title">Footer preview</div>
                    <div class="ad-card__sub">Saved links only. Icons render in the order above.</div>
                </div>
            </div>
            <div class="ad-card__body">
                <?php /* Each rendered icon carries target="_blank" rel="noopener noreferrer",
                         the same as the storefront footer does. */ ?>
                <?php if ($filled === 0): ?>
                    <p class="ad-muted" style="font-size:13px">
                        No links saved: the footer prints no social row.
                    </p>
                <?php else: ?>
                    <div style="display:flex;flex-wrap:wrap;gap:10px;padding:16px;border-radius:var(--ad-radius);
                                background:<?= e((string) setting('secondary_color', '#0F2143')) ?>">
                        <?php foreach (SOCIAL_NETWORKS as $key => [$iconName, $label, $placeholder]): ?>
                            <?php $link = trim((string) ($stored[$key] ?? '')); ?>
                            <?php if ($link === '') { continue; } ?>
                            <a href="<?= e($link) ?>" target="_blank" rel="noopener noreferrer"
                               title="<?= e_attr($label) ?>" aria-label="<?= e_attr($label) ?>"
                               style="width:38px;height:38px;border-radius:50%;display:inline-flex;
                                      align-items:center;justify-content:center;color:#fff;
                                      background:rgba(255,255,255,.12)">
                                <?= icon($iconName, 'w-4 h-4') ?>
                            </a>
                        <?php endforeach; ?>
                    </div>

                <?php endif; ?>
            </div>
        </div>

        <div class="ad-card" style="margin:0">
            <div class="ad-card__body">
                <div class="sik-alert sik-alert--info" style="margin:0">
                    <?= icon('info', 'w-5 h-5') ?>
                    <div>
                        Also emitted as Organization <code>sameAs</code>, which ties the profiles to the store.
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
