<?php
/**
 * ShopInnKart Admin - Theme.
 *
 * Colours, shape and typography for the storefront, plus the two admin
 * chrome colours. The preview strip on the right is rendered from the same
 * values the storefront reads, so an admin can see a change before saving it.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('settings.view');

require_once ADMIN_PATH . '/settings/_layout.php';
require_once ADMIN_PATH . '/includes/rbac.php';
require_once INCLUDES_PATH . '/theme-fonts.php';

/** Shipped defaults, matching the values the installer seeds. */
const THEME_DEFAULTS = [
    'primary_color'      => '#F4511E',
    'secondary_color'    => '#0F2143',
    'accent_color'       => '#FF8A3D',
    'body_bg'            => '#FFFFFF',
    'soft_bg'            => '#F8F7F4',
    'text_color'         => '#111827',
    'muted_color'        => '#6B7280',
    'border_color'       => '#E5E7EB',
    'button_color'       => '#F4511E',
    'button_text_color'  => '#FFFFFF',
    'border_radius'      => '12',
    'card_style'         => 'soft',
    'button_style'       => 'rounded',
    'product_card_style' => 'standard',
    'container_width'    => '1280',
    'header_style'       => 'sticky',
    'footer_style'       => 'dark',
    'font_family'        => 'Plus Jakarta Sans',
    'enable_animations'  => '1',
    'theme_mode_default' => 'system',
    'theme_toggle_enabled' => '1',
    'custom_css'         => '',
    'custom_js'          => '',
];

/**
 * Font stacks offered. No webfonts are fetched, so each one falls back locally.
 *
 * The list itself moved to includes/theme-fonts.php: this screen is admin-only,
 * so while it owned the stacks the storefront could not read them and the
 * chosen family never left this page. Same list, one copy, now readable by
 * includes/header.php as well.
 */
$themeFonts = theme_font_stacks();

$colorLabels = [
    'primary_color'     => 'Primary',
    'secondary_color'   => 'Secondary (navy)',
    'accent_color'      => 'Accent',
    'button_color'      => 'Button background',
    'button_text_color' => 'Button text',
    'body_bg'           => 'Page background',
    'soft_bg'           => 'Soft section background',
    'text_color'        => 'Body text',
    'muted_color'       => 'Muted text',
    'border_color'      => 'Borders',
];

$spec = [];
foreach ($colorLabels as $key => $label) {
    $spec[$key] = ['type' => 'color', 'label' => $label, 'required' => true];
}

$spec += [
    'border_radius' => [
        'type' => 'number', 'label' => 'Corner radius (px)', 'required' => true,
        'min_value' => 0, 'max_value' => 32,
        'help' => 'Drives cards, inputs and buttons. 0 gives square corners throughout.',
    ],
    'container_width' => [
        'type' => 'number', 'label' => 'Container width (px)', 'required' => true,
        'min_value' => 960, 'max_value' => 1920,
        'help' => 'Maximum content width on large screens.',
    ],
    'card_style' => [
        'type' => 'select', 'label' => 'Card style', 'required' => true,
        'options' => ['flat' => 'Flat', 'soft' => 'Soft shadow', 'bordered' => 'Bordered', 'elevated' => 'Elevated'],
    ],
    'button_style' => [
        'type' => 'select', 'label' => 'Button shape', 'required' => true,
        'options' => ['rounded' => 'Rounded', 'pill' => 'Pill', 'square' => 'Square'],
    ],
    'product_card_style' => [
        'type' => 'select', 'label' => 'Product card style', 'required' => true,
        'options' => [
            'standard' => 'Standard', 'minimal' => 'Minimal', 'premium' => 'Premium',
            'compact' => 'Compact', 'horizontal' => 'Horizontal',
        ],
        'help' => 'Default layout for product grids that do not pick their own.',
    ],
    'header_style' => [
        'type' => 'select', 'label' => 'Header', 'required' => true,
        'options' => ['sticky' => 'Sticky on scroll', 'static' => 'Scrolls away'],
    ],
    'footer_style' => [
        'type' => 'select', 'label' => 'Footer', 'required' => true,
        'options' => ['dark' => 'Dark', 'light' => 'Light'],
    ],
    'font_family' => [
        'type' => 'select', 'label' => 'Font family', 'required' => true,
        'options' => array_combine(array_keys($themeFonts), array_keys($themeFonts)),
        'help' => 'No webfonts are downloaded, so a family the visitor does not have falls back to the next in the stack.',
    ],
    'enable_animations' => [
        'type' => 'bool', 'label' => 'Enable entrance animations',
        'help' => 'Off also respects visitors who ask for reduced motion.',
    ],
    'theme_mode_default' => [
        'type' => 'select', 'label' => 'Default colour mode', 'required' => true,
        'options' => ['system' => 'Follow the shopper\'s device', 'light' => 'Light', 'dark' => 'Dark'],
        'help' => 'What a first-time visitor sees. Anyone who picks a mode with the switch keeps it on later visits.',
    ],
    'theme_toggle_enabled' => [
        'type' => 'bool', 'label' => 'Show the light / dark switch',
        'help' => 'In the header on desktop and in the menu drawer on phones. Off locks every visitor to the default above.',
    ],
    'custom_css' => [
        'type' => 'code', 'label' => 'Custom CSS', 'rows' => 8,
        'help' => 'Injected into the storefront head after the theme tokens. Tags are stripped on output.',
    ],
    'custom_js' => [
        'type' => 'code', 'label' => 'Custom JavaScript', 'rows' => 8,
        'help' => 'Runs on every storefront page, on this site\'s own origin and session. '
            . 'Treat it as admin-level access, not a styling tweak.',
    ],
    'admin_primary' => [
        'type' => 'color', 'label' => 'Admin accent', 'required' => true, 'group' => 'admin_theme',
    ],
    'admin_sidebar_bg' => [
        'type' => 'color', 'label' => 'Admin sidebar background', 'required' => true, 'group' => 'admin_theme',
    ],
    'admin_sidebar_collapsed' => [
        'type' => 'bool', 'label' => 'Collapse the sidebar by default', 'group' => 'admin_theme',
        'help' => 'Only the default for a browser that has not set its own preference.',
    ],
];

// Admin > Appearance reads THIS spec and THESE defaults through
// settings_spec_harvest() so its "reset to shipped defaults" cannot drift from
// the screen that owns them. The hook sits above every branch that writes, so a
// harvest can only ever read. See _layout.php for the guarantees on the caller
// side.
if (defined('SETTINGS_SPEC_ONLY')) {
    return ['spec' => $spec, 'group' => 'theme', 'defaults' => THEME_DEFAULTS];
}

$action = (string) input('action', '');

/**
 * Custom CSS and JavaScript are not styling fields.
 *
 * custom_js is printed inside a <script> tag on every storefront page,
 * including checkout, on the same origin and the same session as /admin. A
 * settings.edit holder who can write it can make the next Super Admin who
 * browses the shop POST a new admin account for them, or skim card details at
 * checkout. So the two boxes need settings.scripts (or Super Admin), they need
 * the actor's own password, and every change is recorded with a hash of the
 * before and after.
 */
$canScripts = admin_can_edit_scripts();

if (!$canScripts) {
    // Still rendered, so the operator can see what is running and ask for it -
    // but not editable, and never writable (see the save branch below).
    foreach (ADMIN_SCRIPT_SETTING_KEYS as $scriptKey) {
        $spec[$scriptKey]['attr'] = 'disabled readonly';
        $spec[$scriptKey]['help'] = 'Read-only: changing code that runs on the storefront needs the '
            . 'settings.scripts permission.';
    }
}

// ---------------------------------------------------------------------------
//  Reset
// ---------------------------------------------------------------------------
if (is_post() && $action === 'reset') {
    admin_require_action('settings.edit');

    foreach (THEME_DEFAULTS as $key => $value) {
        // A reset must not be a way to wipe (or keep) code the operator could
        // not have written in the first place.
        if (in_array($key, ADMIN_SCRIPT_SETTING_KEYS, true) && !$canScripts) {
            continue;
        }
        setting_save($key, $value, 'theme', settings_store_type($spec[$key] ?? []));
    }

    log_activity('settings.updated', 'settings', null, 'Reset the storefront theme to its shipped defaults');
    admin_after_write();

    flash('success', 'Theme reset to the shipped defaults. Admin colours were left alone.');
    redirect(settings_url('theme'));
}

// ---------------------------------------------------------------------------
//  Save
// ---------------------------------------------------------------------------
$stored = settings_group('theme') + settings_group('admin_theme');

if (is_post()) {
    admin_require_action('settings.edit');   // POST + CSRF + permission, before any decision below

    $saveSpec = $spec;

    foreach (ADMIN_SCRIPT_SETTING_KEYS as $scriptKey) {
        $submittedCode = (string) ($_POST[$scriptKey] ?? ($stored[$scriptKey] ?? ''));
        $storedCode    = (string) ($stored[$scriptKey] ?? '');

        if ($submittedCode === $storedCode) {
            continue;   // nothing to guard: an ordinary theme save carrying the box along
        }

        if (!$canScripts) {
            // The field is disabled in the HTML, so anything arriving here was
            // hand-crafted. Refuse the whole save rather than quietly dropping
            // one field, and leave a record of the attempt.
            admin_deny_back(
                'Changing the storefront\'s custom CSS or JavaScript needs the settings.scripts permission. '
                    . 'Nothing was saved.',
                settings_url('theme'),
                ['setting' => $scriptKey, 'bytes' => mb_strlen($submittedCode)]
            );
        }

        if (!admin_reauth_ok()) {
            flash_errors(['reauth_password' => admin_reauth_error('change code that runs on every storefront page')]);
            flash_old($_POST);
            flash('error', 'Nothing was saved. Confirm with your own password to change the custom code.');
            redirect(settings_url('theme'));
        }

        security_event('settings.scripts_changed', 'critical', [
            'setting'     => $scriptKey,
            'bytes_from'  => mb_strlen($storedCode),
            'bytes_to'    => mb_strlen($submittedCode),
            'sha256_from' => $storedCode === '' ? null : hash('sha256', $storedCode),
            'sha256_to'   => $submittedCode === '' ? null : hash('sha256', $submittedCode),
        ], (int) $admin['id'], 'admin');
    }

    if (!$canScripts) {
        // Belt and braces: even an unchanged value is not written by someone
        // who may not write it.
        foreach (ADMIN_SCRIPT_SETTING_KEYS as $scriptKey) {
            unset($saveSpec[$scriptKey]);
        }
    }

    settings_handle_save('theme', 'theme', $saveSpec, $stored);
}

$errors = errors_pull();
$values = settings_values($spec, $stored);

// Fall back to a shipped default whenever a colour is blank, so the preview
// never renders with an empty custom property.
$preview = [];
foreach (array_keys($colorLabels) as $key) {
    $preview[$key] = preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $values[$key]) === 1
        ? (string) $values[$key]
        : THEME_DEFAULTS[$key];
}
$previewRadius = max(0, min(32, (int) ($values['border_radius'] !== '' ? $values['border_radius'] : 12)));
$previewFont   = theme_font_stack((string) $values['font_family']);

$sampleProduct = Database::fetch(
    "SELECT `name`, `main_image`, `price`, `sale_price`
     FROM `products`
     WHERE `status` = 'active'
     ORDER BY `sold_count` DESC, `id` ASC
     LIMIT 1"
);

$sampleName  = (string) ($sampleProduct['name'] ?? 'Sample Curtain Light');
$sampleMrp   = (float) ($sampleProduct['price'] ?? 4999);
$samplePrice = $sampleProduct !== null && $sampleProduct['sale_price'] !== null
    ? (float) $sampleProduct['sale_price']
    : $sampleMrp * 0.72;
$sampleImage = img_url($sampleProduct['main_image'] ?? null);
$sampleOff   = discount_percent($sampleMrp, $samplePrice);

$pageTitle    = 'Theme Settings';
$pageSubtitle = 'Colour, shape and type for the storefront, plus the admin chrome.';
$breadcrumbs  = settings_breadcrumbs('theme');

require ADMIN_PATH . '/includes/header.php';
?>

<?= settings_tabs('theme') ?>

<style>
    /* Scoped to the preview strip: everything here reads the same custom
       properties the storefront head writes, so it moves with the form. */
    #themePreview {
        --tp-primary: <?= e($preview['primary_color']) ?>;
        --tp-navy: <?= e($preview['secondary_color']) ?>;
        --tp-accent: <?= e($preview['accent_color']) ?>;
        --tp-btn: <?= e($preview['button_color']) ?>;
        --tp-btn-text: <?= e($preview['button_text_color']) ?>;
        --tp-bg: <?= e($preview['body_bg']) ?>;
        --tp-soft: <?= e($preview['soft_bg']) ?>;
        --tp-text: <?= e($preview['text_color']) ?>;
        --tp-muted: <?= e($preview['muted_color']) ?>;
        --tp-border: <?= e($preview['border_color']) ?>;
        --tp-radius: <?= $previewRadius ?>px;
        --tp-font: <?= e($previewFont) ?>;

        background: var(--tp-bg);
        color: var(--tp-text);
        font-family: var(--tp-font);
        padding: 18px;
        border-radius: var(--ad-radius);
        border: 1px solid var(--ad-border);
        display: grid;
        gap: 16px;
    }
    #themePreview .tp-row { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
    #themePreview .tp-btn {
        background: var(--tp-btn);
        color: var(--tp-btn-text);
        border: 1px solid var(--tp-btn);
        border-radius: var(--tp-radius);
        padding: 10px 18px;
        font-size: 13.5px;
        font-weight: 700;
        cursor: default;
    }
    #themePreview .tp-btn--ghost { background: transparent; color: var(--tp-primary); border-color: var(--tp-border); }
    #themePreview[data-button="pill"] .tp-btn { border-radius: 999px; }
    #themePreview[data-button="square"] .tp-btn { border-radius: 0; }

    #themePreview .tp-badge {
        background: var(--tp-primary);
        color: #fff;
        border-radius: 999px;
        padding: 3px 10px;
        font-size: 11px;
        font-weight: 800;
        letter-spacing: .02em;
    }
    #themePreview .tp-badge--navy { background: var(--tp-navy); }
    #themePreview .tp-badge--accent { background: var(--tp-accent); color: var(--tp-navy); }

    #themePreview .tp-card {
        background: var(--tp-bg);
        border-radius: var(--tp-radius);
        border: 1px solid transparent;
        overflow: hidden;
        max-width: 260px;
        transition: none;
    }
    #themePreview[data-card="soft"]     .tp-card { box-shadow: 0 1px 3px rgba(16,35,61,.12), 0 6px 20px rgba(16,35,61,.06); }
    #themePreview[data-card="bordered"] .tp-card { border-color: var(--tp-border); }
    #themePreview[data-card="elevated"] .tp-card { box-shadow: 0 14px 34px rgba(16,35,61,.18); }
    #themePreview[data-anim="1"] .tp-card { transition: transform .18s ease, box-shadow .18s ease; }
    #themePreview[data-anim="1"] .tp-card:hover { transform: translateY(-3px); }

    #themePreview .tp-card__media { background: var(--tp-soft); display: grid; place-items: center; padding: 14px; }
    #themePreview .tp-card__media img { width: 100%; max-height: 150px; object-fit: contain; }
    #themePreview .tp-card__body { padding: 12px; display: grid; gap: 6px; }
    #themePreview .tp-card__brand { font-size: 11px; color: var(--tp-muted); text-transform: uppercase; letter-spacing: .06em; }
    #themePreview .tp-card__name { font-size: 13.5px; font-weight: 600; line-height: 1.35; }
    #themePreview .tp-card__price { font-size: 16px; font-weight: 800; }
    #themePreview .tp-card__mrp { font-size: 12.5px; color: var(--tp-muted); text-decoration: line-through; font-weight: 500; }
    #themePreview .tp-card__off { font-size: 12.5px; color: var(--tp-primary); font-weight: 700; }

    #themePreview[data-product="compact"] .tp-card { max-width: 200px; }
    #themePreview[data-product="minimal"] .tp-card__cart,
    #themePreview[data-product="minimal"] .tp-card__brand { display: none; }
    #themePreview[data-product="premium"] .tp-card { border: 1px solid var(--tp-accent); }
    #themePreview[data-product="horizontal"] .tp-card { max-width: 420px; display: grid; grid-template-columns: 128px 1fr; }
    #themePreview[data-product="horizontal"] .tp-card__media { padding: 8px; }

    #themePreview .tp-swatches { display: flex; flex-wrap: wrap; gap: 8px; }
    #themePreview .tp-swatch {
        width: 44px; height: 30px;
        border-radius: 7px;
        border: 1px solid var(--tp-border);
    }
</style>

<div class="ad-grid ad-grid--sidebar">
    <form class="ad-form" method="post" action="<?= e(settings_url('theme')) ?>" data-guard-unsaved id="themeForm">
        <?= csrf_field() ?>

        <div class="ad-card" style="margin:0">
            <div class="ad-card__head">
                <div>
                    <div class="ad-card__title">Storefront colours</div>
                    <div class="ad-card__sub">Written into the storefront head as CSS custom properties.</div>
                </div>
            </div>
            <div class="ad-card__body">
                <div class="ad-row ad-row--2">
                    <?= settings_field('primary_color', $spec, $values, $errors) ?>
                    <?= settings_field('secondary_color', $spec, $values, $errors) ?>
                </div>
                <div class="ad-row ad-row--2">
                    <?= settings_field('accent_color', $spec, $values, $errors) ?>
                    <?= settings_field('border_color', $spec, $values, $errors) ?>
                </div>
                <div class="ad-row ad-row--2">
                    <?= settings_field('button_color', $spec, $values, $errors) ?>
                    <?= settings_field('button_text_color', $spec, $values, $errors) ?>
                </div>
                <div class="ad-row ad-row--2">
                    <?= settings_field('body_bg', $spec, $values, $errors) ?>
                    <?= settings_field('soft_bg', $spec, $values, $errors) ?>
                </div>
                <div class="ad-row ad-row--2">
                    <?= settings_field('text_color', $spec, $values, $errors) ?>
                    <?= settings_field('muted_color', $spec, $values, $errors) ?>
                </div>
            </div>
        </div>

        <div class="ad-card">
            <div class="ad-card__head">
                <div>
                    <div class="ad-card__title">Shape &amp; layout</div>
                    <div class="ad-card__sub">Applies to cards, buttons and the page container.</div>
                </div>
            </div>
            <div class="ad-card__body">
                <div class="ad-row ad-row--2">
                    <?= settings_field('border_radius', $spec, $values, $errors) ?>
                    <?= settings_field('container_width', $spec, $values, $errors) ?>
                </div>
                <div class="ad-row ad-row--3">
                    <?= settings_field('card_style', $spec, $values, $errors) ?>
                    <?= settings_field('button_style', $spec, $values, $errors) ?>
                    <?= settings_field('product_card_style', $spec, $values, $errors) ?>
                </div>
                <div class="ad-row ad-row--3">
                    <?= settings_field('header_style', $spec, $values, $errors) ?>
                    <?= settings_field('footer_style', $spec, $values, $errors) ?>
                    <?= settings_field('font_family', $spec, $values, $errors) ?>
                </div>
                <?= settings_field('enable_animations', $spec, $values, $errors) ?>
            </div>
        </div>

        <div class="ad-card">
            <div class="ad-card__head">
                <div>
                    <div class="ad-card__title">Light &amp; dark mode</div>
                    <div class="ad-card__sub">Both palettes are designed; the colours above drive light mode.</div>
                </div>
            </div>
            <div class="ad-card__body">
                <div class="ad-row ad-row--2">
                    <?= settings_field('theme_mode_default', $spec, $values, $errors) ?>
                    <?= settings_field('theme_toggle_enabled', $spec, $values, $errors) ?>
                </div>
            </div>
        </div>

        <div class="ad-card">
            <div class="ad-card__head">
                <div>
                    <div class="ad-card__title">Custom code</div>
                    <div class="ad-card__sub">Escape hatch for a tweak that does not deserve a settings field.</div>
                </div>
            </div>
            <div class="ad-card__body">
                <?= settings_field('custom_css', $spec, $values, $errors) ?>
                <?= settings_field('custom_js', $spec, $values, $errors) ?>
                <?php if ($canScripts): ?>
                    <?= admin_reauth_field('change the custom CSS or JavaScript',
                        (string) ($errors['reauth_password'] ?? '')) ?>
                <?php endif; ?>
                <div class="sik-alert sik-alert--warning" style="margin:0">
                    <?= icon('alert', 'w-5 h-5') ?>
                    <div>
                        Both blocks are printed on every storefront page, on this site's own origin and
                        session &mdash; JavaScript here can do anything a signed-in admin browsing the shop
                        could do. A syntax error breaks the shop for real visitors, so test on a copy first.
                        <?php if (!$canScripts): ?>
                            <br><strong>You have read-only access to these two boxes</strong>
                            (<code>settings.scripts</code> is needed to change them).
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="ad-card">
            <div class="ad-card__head">
                <div>
                    <div class="ad-card__title">Admin panel</div>
                    <div class="ad-card__sub">Only affects this backend, never the storefront.</div>
                </div>
            </div>
            <div class="ad-card__body">
                <div class="ad-row ad-row--2">
                    <?= settings_field('admin_primary', $spec, $values, $errors) ?>
                    <?= settings_field('admin_sidebar_bg', $spec, $values, $errors) ?>
                </div>
                <?= settings_field('admin_sidebar_collapsed', $spec, $values, $errors) ?>
            </div>
            <?= settings_save_bar('The preview updates as you type; nothing is stored until you save.') ?>
        </div>
    </form>

    <div style="display:grid;gap:18px;align-content:start">
        <div class="ad-card" style="margin:0">
            <div class="ad-card__head">
                <div>
                    <div class="ad-card__title">Live preview</div>
                    <div class="ad-card__sub">Unsaved values, drawn the way the storefront draws them.</div>
                </div>
            </div>
            <div class="ad-card__body">
                <div id="themePreview"
                     data-button="<?= e_attr((string) $values['button_style']) ?>"
                     data-card="<?= e_attr((string) $values['card_style']) ?>"
                     data-product="<?= e_attr((string) $values['product_card_style']) ?>"
                     data-anim="<?= e_attr((string) $values['enable_animations']) ?>">

                    <div class="tp-row">
                        <button type="button" class="tp-btn" tabindex="-1">Add to Cart</button>
                        <button type="button" class="tp-btn tp-btn--ghost" tabindex="-1">Buy Now</button>
                    </div>

                    <div class="tp-row">
                        <span class="tp-badge">Bestseller</span>
                        <span class="tp-badge tp-badge--navy">In Stock</span>
                        <span class="tp-badge tp-badge--accent">Limited Deal</span>
                    </div>

                    <div class="tp-card">
                        <div class="tp-card__media">
                            <img src="<?= e($sampleImage) ?>" alt="" loading="lazy">
                        </div>
                        <div class="tp-card__body">
                            <span class="tp-card__brand">ShopInnKart</span>
                            <span class="tp-card__name"><?= e(str_limit($sampleName, 48)) ?></span>
                            <span>
                                <span class="tp-card__price"><?= e(money($samplePrice)) ?></span>
                                <?php if ($sampleOff > 0): ?>
                                    <span class="tp-card__mrp"><?= e(money($sampleMrp)) ?></span>
                                    <span class="tp-card__off"><?= (int) $sampleOff ?>% off</span>
                                <?php endif; ?>
                            </span>
                            <button type="button" class="tp-btn tp-card__cart" tabindex="-1"
                                    style="width:100%;padding:8px 12px">Add to Cart</button>
                        </div>
                    </div>

                    <div>
                        <div style="font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:var(--tp-muted);margin-bottom:7px">
                            Palette
                        </div>
                        <div class="tp-swatches">
                            <?php foreach (array_keys($colorLabels) as $key): ?>
                                <span class="tp-swatch" data-swatch="<?= e_attr($key) ?>"
                                      style="background:<?= e($preview[$key]) ?>"
                                      title="<?= e_attr($colorLabels[$key]) ?>"></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <p class="ad-muted" style="font-size:12px;margin-top:12px">
                    Container width and header behaviour cannot be shown at this size; everything else here
                    is live.
                </p>
            </div>
        </div>

        <?php if (admin_can('settings.edit')): ?>
            <div class="ad-card" style="margin:0">
                <div class="ad-card__head">
                    <div class="ad-card__title">Reset</div>
                </div>
                <form class="ad-card__body" method="post" action="<?= e(settings_url('theme')) ?>"
                      onsubmit="return confirm('Reset every storefront theme value to the shipped defaults? Custom CSS and JavaScript are cleared too.')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="reset">

                    <p class="ad-muted" style="font-size:13px;margin-bottom:12px">
                        Restores the <?= count(THEME_DEFAULTS) ?> storefront theme values to what the store
                        shipped with, including clearing custom CSS and JavaScript. Admin colours are not touched.
                    </p>
                    <button type="submit" class="ad-btn ad-btn--danger ad-btn--block">
                        <?= icon('refresh', 'w-4 h-4') ?> Reset to defaults
                    </button>
                </form>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        var preview = document.getElementById('themePreview');
        var form = document.getElementById('themeForm');
        if (!preview || !form) { return; }

        var fonts = <?= e_json($themeFonts) ?>;
        var colourVars = <?= e_json([
            'primary_color'     => '--tp-primary',
            'secondary_color'   => '--tp-navy',
            'accent_color'      => '--tp-accent',
            'button_color'      => '--tp-btn',
            'button_text_color' => '--tp-btn-text',
            'body_bg'           => '--tp-bg',
            'soft_bg'           => '--tp-soft',
            'text_color'        => '--tp-text',
            'muted_color'       => '--tp-muted',
            'border_color'      => '--tp-border',
        ]) ?>;

        // The colour picker writes into the hex field, which is the input that
        // posts, so a blank value stays blank instead of becoming #000000.
        form.querySelectorAll('input[type="color"][data-color-picker]').forEach(function (picker) {
            var text = document.getElementById(picker.dataset.colorPicker);
            if (!text) { return; }

            picker.addEventListener('input', function () {
                text.value = picker.value.toUpperCase();
                text.dispatchEvent(new Event('input', { bubbles: true }));
            });
            text.addEventListener('input', function () {
                if (/^#[0-9a-fA-F]{6}$/.test(text.value)) { picker.value = text.value; }
            });
        });

        function apply() {
            Object.keys(colourVars).forEach(function (name) {
                var field = form.elements[name];
                if (!field || !/^#[0-9a-fA-F]{6}$/.test(field.value)) { return; }
                preview.style.setProperty(colourVars[name], field.value);
                var swatch = preview.querySelector('[data-swatch="' + name + '"]');
                if (swatch) { swatch.style.background = field.value; }
            });

            var radius = form.elements['border_radius'];
            if (radius && radius.value !== '') {
                preview.style.setProperty('--tp-radius', Math.max(0, Math.min(32, parseInt(radius.value, 10) || 0)) + 'px');
            }

            var font = form.elements['font_family'];
            if (font && fonts[font.value]) { preview.style.setProperty('--tp-font', fonts[font.value]); }

            var button = form.elements['button_style'];
            if (button) { preview.dataset.button = button.value; }

            var card = form.elements['card_style'];
            if (card) { preview.dataset.card = card.value; }

            var product = form.elements['product_card_style'];
            if (product) { preview.dataset.product = product.value; }

            var anim = form.elements['enable_animations'];
            if (anim) { preview.dataset.anim = anim.checked ? '1' : '0'; }
        }

        form.addEventListener('input', apply);
        form.addEventListener('change', apply);
        apply();
    });
</script>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
