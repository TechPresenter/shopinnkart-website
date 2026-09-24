<?php
/**
 * ShopInnKart Admin - Storefront widgets.
 *
 * The `widgets` settings group has always been read by the storefront - the
 * popup kill switch in active_popups(), the ticker in header.php, the three
 * floating helpers and the bottom nav in footer.php - but it had no admin
 * screen at all. Setting popups_enabled to 0 genuinely empties every page of
 * popups, and until now the only way to do it was a direct UPDATE on the
 * settings table.
 *
 * The screen also owns the wishlist and comparison features. "Enabled" there is
 * a real switch, not a display one: the buttons, the pages and the API refuse
 * together from feature_refusal() in includes/cart-functions.php.
 *
 * Every key in $spec drives live storefront code. sales_notification_enabled
 * and sales_notification_interval are seeded by schema.sql and read by nothing;
 * they are shown further down as disabled controls with that stated plainly,
 * rather than as switches that would look like they did something. They are
 * deliberately absent from $spec so a save never rewrites a value no code
 * consumes.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('settings.view');

require_once ADMIN_PATH . '/settings/_layout.php';

/**
 * Only the keys this screen may write. sales_notification_* are missing on
 * purpose - see the file header.
 */
$spec = [
    'popups_enabled' => [
        'type'    => 'bool',
        'label'   => 'Show popup modals',
        'default' => '1',
        // Off makes active_popups() return none, so no popup markup reaches
        // the page at all - it is not a CSS hide.
        'help'    => 'Master switch for every centre-screen popup.',
    ],
    'popins_enabled' => [
        'type'    => 'bool',
        'label'   => 'Show pop-ins',
        'default' => '1',
        'help'    => 'The small corner cards, independent of the switch above.',
    ],
    'ticker_enabled' => [
        'type'    => 'bool',
        'label'   => 'Show the announcement ticker',
        'default' => '1',
        // No help line: the card subtitle says what the strip is, and the
        // line under the fields says whether a live announcement exists.
    ],
    'ticker_speed' => [
        'type'      => 'number',
        'label'     => 'Ticker loop duration (seconds)',
        'default'   => '40',
        'min_value' => 10,
        'max_value' => 240,
        'help'      => 'Larger scrolls slower. Under 10 seconds is clamped.',
    ],
    'mobile_bottom_nav' => [
        'type'    => 'bool',
        'label'   => 'Show the mobile bottom bar',
        'default' => '1',
        // Off also drops the body padding that reserves room for the bar, so
        // the page does not keep a strip of empty space at the bottom.
        'help'    => 'Home / Shop / Cart / Account, under 768px.',
    ],
    // back_to_top_enabled and floating_whatsapp used to be two booleans here.
    // Each floating button is now a row in `floating_buttons` whose `status` is
    // its switch - along with its icon, label, tooltip, colour, size, corner,
    // order and per-device audience - so the two keys were retired rather than
    // left as a second place to turn the same button off.
    'floating_buttons_enabled' => [
        'type'    => 'bool',
        'label'   => 'Show floating action buttons',
        'default' => '1',
        'help'    => 'Off renders none of them, whatever each row says.',
    ],
    'recently_viewed_enabled' => [
        'type'    => 'bool',
        'label'   => 'Track recently viewed products',
        'default' => '1',
        'help'    => 'Off stops recording, and hides the "Recently viewed" row.',
    ],

    // ---- wishlist --------------------------------------------------------
    'wishlist_enabled' => [
        'type'    => 'bool',
        'label'   => 'Wishlist enabled',
        'default' => '1',
        // What "off" reaches - icon, card and PDP buttons, /wishlist (404)
        // and every api/wishlist/* endpoint (403) - is in the card subtitle,
        // where it is said once instead of once per switch.
    ],
    'wishlist_show_header' => [
        'type'    => 'bool',
        'label'   => 'Heart icon in the header',
        'default' => '1',
        // Appearance > Header can hide the header action on its own as well,
        // so an icon missing with this switch on is worth checking there.
        'help'    => 'Also the phone bottom bar and the account menu.',
    ],
    'wishlist_show_card' => [
        'type'    => 'bool',
        'label'   => 'Heart button on product cards',
        'default' => '1',
    ],
    'wishlist_show_pdp' => [
        'type'    => 'bool',
        'label'   => 'Wishlist button on the product page',
        'default' => '1',
        'help'    => 'Covers the product page and its Quick View modal.',
    ],
    'wishlist_guest_mode' => [
        'type'    => 'select',
        'label'   => 'Signed-out visitors',
        'default' => 'session',
        'options' => [
            'session' => 'Can save — kept in their session, merged into the account on sign-in',
            'prompt'  => 'Must sign in first — the buttons become links to the login page',
            'hidden'  => 'No wishlist at all — controls hidden, endpoints refuse',
        ],
        // One control rather than a pair that could contradict each other:
        // "Must sign in first" IS the login requirement, so there is no
        // second "require login" switch to disagree with it.
    ],

    // ---- compare ---------------------------------------------------------
    'compare_enabled' => [
        'type'    => 'bool',
        'label'   => 'Compare enabled',
        'default' => '1',
        // Off also hides the floating compare bar and answers 404 on
        // /compare and 403 from every api/compare/* endpoint.
    ],
    'compare_show_header' => [
        'type'    => 'bool',
        'label'   => 'Compare icon in the header',
        'default' => '1',
        'help'    => 'Also the mobile drawer and the account menu.',
    ],
    'compare_show_card' => [
        'type'    => 'bool',
        'label'   => 'Compare button on product cards',
        'default' => '1',
    ],
    'compare_show_pdp' => [
        'type'    => 'bool',
        'label'   => 'Compare button on the product page',
        'default' => '1',
        'help'    => 'Covers the product page and its Quick View modal.',
    ],
    'compare_guest_mode' => [
        'type'    => 'select',
        'label'   => 'Signed-out visitors',
        'default' => 'session',
        'options' => [
            'session' => 'Can compare — kept against their session id',
            'prompt'  => 'Must sign in first — the buttons become links to the login page',
            'hidden'  => 'No comparison at all — controls hidden, endpoints refuse',
        ],
    ],
    'max_compare_items' => [
        'type'      => 'number',
        'label'     => 'Maximum products in a comparison',
        'default'   => '4',
        // compare_max() clamps to 2-6, so the field stops at 6 rather than
        // offering 7 and 8 as values that would silently render as 6.
        'min_value' => 2,
        'max_value' => 6,
        // The key belongs to the store group and is the same one Settings →
        // Store edits. Rendered here too because this is where an admin looks
        // for it; the group override keeps it a single row, not a copy.
        'group'     => 'store',
        'help'      => 'Also on the Store tab — the same setting, not a copy.',
    ],
];

// Admin > Appearance reads THIS spec through settings_spec_harvest() so its
// "reset to shipped defaults" uses the `default` written beside each field
// rather than a second copy. The hook sits above every branch that writes.
if (defined('SETTINGS_SPEC_ONLY')) {
    return ['spec' => $spec, 'group' => 'widgets'];
}

// max_compare_items is filed under `store` (its spec entry says so), so the
// prefill has to read that group too. The union only ever supplies keys the
// spec asked for, and settings_handle_save() writes each key back to the group
// its own entry names - so the row stays where it has always lived.
$storedWidgets = settings_group('widgets');
$stored        = $storedWidgets + settings_group('store');

if (is_post()) {
    settings_handle_save('widgets', 'widgets', $spec, $stored);
}

$errors = errors_pull();
$values = settings_values($spec, $stored);

// ---------------------------------------------------------------------------
// Live counts, so a switch can be read against what it is actually silencing.
// ---------------------------------------------------------------------------
$popupCounts = Database::fetch(
    "SELECT
        SUM(`display_mode` = 'popup'  AND `status` = 'active') AS popups,
        SUM(`display_mode` = 'popin'  AND `status` = 'active') AS popins
     FROM `popups`"
) ?: [];
$livePopups = (int) ($popupCounts['popups'] ?? 0);
$livePopins = (int) ($popupCounts['popins'] ?? 0);

$liveAnnouncements = (int) Database::fetchColumn(
    "SELECT COUNT(*) FROM `announcements` WHERE `status` = 'active'"
);

$whatsappNumber = trim((string) setting('store_whatsapp', ''));

// What the floating stack actually contains right now, so the master switch can
// be read against the rows it silences.
require_once INCLUDES_PATH . '/floating-functions.php';
$floatingRows   = floating_buttons_all();
$floatingActive = count(array_filter($floatingRows, static fn (array $r): bool => $r['status'] === 'active'));

/** The two keys nothing reads, with their stored values, for the honest panel. */
$dormant = [
    'sales_notification_enabled'  => [
        'label' => 'Sales notification toasts',
        'value' => (string) ($stored['sales_notification_enabled'] ?? '1'),
        'type'  => 'bool',
    ],
    'sales_notification_interval' => [
        'label' => 'Sales notification interval (seconds)',
        'value' => (string) ($stored['sales_notification_interval'] ?? '18'),
        'type'  => 'number',
    ],
];

/**
 * Keys this group still stores that were replaced by something better, and
 * whose value is now read by nothing.
 *
 * They are NOT "owned by another feature screen": the schema seed dropped them
 * when each floating button became a row in `floating_buttons`, but the live
 * settings table kept the rows it already had. Listing them under a heading
 * that told the operator to "edit them where they live" pointed at a screen
 * that does not exist, on the one page whose whole point is not to promise
 * behaviour the shop does not have.
 */
// The replacement, not a sentence about it: the column is headed "Replaced by",
// and each row's own `status` in Content > Floating Buttons is now its switch.
$retired = [
    'back_to_top_enabled' => 'The Back to top row in Content → Floating Buttons.',
    'floating_whatsapp'   => 'The WhatsApp row in Content → Floating Buttons.',
];
$retired = array_intersect_key($retired, $storedWidgets);

// Anything else filed under `widgets` belongs to a feature with its own screen.
// Listing it read-only means this page never hides a stored key, and never
// becomes a second place to edit one - which is exactly how the same setting
// ends up with two owners.
$elsewhere = array_diff_key($storedWidgets, $spec, $dormant, $retired);
ksort($elsewhere);

$pageTitle    = 'Storefront Widgets';
$pageSubtitle = 'Wishlist, comparison, popups, the ticker and the floating helpers.';
$breadcrumbs  = settings_breadcrumbs('widgets');

require ADMIN_PATH . '/includes/header.php';
?>

<?= settings_tabs('widgets') ?>

<div class="ad-grid ad-grid--sidebar">
    <form class="ad-form" method="post" data-guard-unsaved style="display:grid;gap:18px;align-content:start">
        <?= csrf_field() ?>

        <!-- ====================== Popups and pop-ins ====================== -->
        <div class="ad-card" style="margin:0">
            <div class="ad-card__head">
                <div>
                    <div class="ad-card__title"><?= icon('bell', 'w-4 h-4') ?> Popups &amp; pop-ins</div>
                    <div class="ad-card__sub">
                        Kill switches. Individual popups keep their own status in Marketing → Popups.
                    </div>
                </div>
            </div>
            <div class="ad-card__body" style="display:grid;gap:4px">
                <?= settings_fields(['popups_enabled', 'popins_enabled'], $spec, $values, $errors) ?>

                <p class="ad-muted" style="font-size:var(--ad-text-xs);margin:6px 0 0">
                    <?= $livePopups ?> active popup<?= $livePopups === 1 ? '' : 's' ?>
                    and <?= $livePopins ?> active pop-in<?= $livePopins === 1 ? '' : 's' ?> exist right now.
                    <a href="<?= e(admin_url('popups/')) ?>">Manage them</a>.
                </p>
            </div>
        </div>

        <!-- ========================= Announcements ========================= -->
        <div class="ad-card" style="margin:0">
            <div class="ad-card__head">
                <div>
                    <div class="ad-card__title"><?= icon('zap', 'w-4 h-4') ?> Announcement ticker</div>
                    <div class="ad-card__sub">The strip above the header, drawn from the announcements table.</div>
                </div>
            </div>
            <div class="ad-card__body" style="display:grid;gap:4px">
                <?= settings_fields(['ticker_enabled', 'ticker_speed'], $spec, $values, $errors) ?>

                <p class="ad-muted" style="font-size:var(--ad-text-xs);margin:6px 0 0">
                    <?php if ($liveAnnouncements === 0): ?>
                        No announcement row is live, so the strip stays hidden whatever this switch says.
                    <?php else: ?>
                        <?= $liveAnnouncements ?> announcement<?= $liveAnnouncements === 1 ? ' is' : 's are' ?> live.
                    <?php endif; ?>
                    The messages have no admin screen yet; edit the <code>announcements</code> table.
                </p>
            </div>
        </div>

        <!-- ====================== Floating / mobile ======================= -->
        <div class="ad-card" style="margin:0">
            <div class="ad-card__head">
                <div>
                    <div class="ad-card__title"><?= icon('smartphone', 'w-4 h-4') ?> Floating &amp; mobile helpers</div>
                    <div class="ad-card__sub">The controls that sit on top of the page.</div>
                </div>
            </div>
            <div class="ad-card__body" style="display:grid;gap:4px">
                <?= settings_fields(['mobile_bottom_nav', 'floating_buttons_enabled'], $spec, $values, $errors) ?>

                <p class="ad-muted" style="font-size:var(--ad-text-xs);margin:6px 0 0">
                    <?= $floatingActive ?> of <?= count($floatingRows) ?> floating buttons are enabled.
                    <a href="<?= e(admin_url('floating/')) ?>">Open the builder</a>.
                    <?php if ($whatsappNumber === ''): ?>
                        No store WhatsApp number is saved, so a WhatsApp button without its own number
                        stays hidden (<a href="<?= e(settings_url('general')) ?>">add one</a>).
                    <?php endif; ?>
                </p>
            </div>
        </div>

        <!-- ======================== Wishlist =============================== -->
        <div class="ad-card" style="margin:0">
            <div class="ad-card__head">
                <div>
                    <div class="ad-card__title"><?= icon('heart', 'w-4 h-4') ?> Wishlist</div>
                    <div class="ad-card__sub">
                        A real off: /wishlist answers 404, api/wishlist/* answers 403.
                    </div>
                </div>
            </div>
            <div class="ad-card__body" style="display:grid;gap:4px">
                <?= settings_fields(['wishlist_enabled'], $spec, $values, $errors) ?>
                <fieldset class="ad-fieldset" style="display:grid;gap:12px">
                    <legend>Where the button appears</legend>
                    <?= settings_fields(
                        ['wishlist_show_header', 'wishlist_show_card', 'wishlist_show_pdp'],
                        $spec, $values, $errors
                    ) ?>
                </fieldset>
                <?= settings_fields(['wishlist_guest_mode'], $spec, $values, $errors) ?>
            </div>
        </div>

        <!-- ========================= Compare =============================== -->
        <div class="ad-card" style="margin:0">
            <div class="ad-card__head">
                <div>
                    <div class="ad-card__title"><?= icon('compare', 'w-4 h-4') ?> Product comparison</div>
                    <div class="ad-card__sub">Same rules as the wishlist, plus how many fit side by side.</div>
                </div>
            </div>
            <div class="ad-card__body" style="display:grid;gap:4px">
                <?= settings_fields(['compare_enabled'], $spec, $values, $errors) ?>
                <fieldset class="ad-fieldset" style="display:grid;gap:12px">
                    <legend>Where the button appears</legend>
                    <?= settings_fields(
                        ['compare_show_header', 'compare_show_card', 'compare_show_pdp'],
                        $spec, $values, $errors
                    ) ?>
                </fieldset>
                <?= settings_fields(['compare_guest_mode', 'max_compare_items'], $spec, $values, $errors) ?>
            </div>
        </div>

        <!-- =========================== Browsing =========================== -->
        <div class="ad-card" style="margin:0">
            <div class="ad-card__head">
                <div>
                    <div class="ad-card__title"><?= icon('eye', 'w-4 h-4') ?> Browsing history</div>
                </div>
            </div>
            <div class="ad-card__body" style="display:grid;gap:4px">
                <?= settings_fields(['recently_viewed_enabled'], $spec, $values, $errors) ?>
            </div>
            <?= settings_save_bar(count($spec) . ' live switches on this screen.') ?>
        </div>
    </form>

    <div style="display:grid;gap:18px;align-content:start">
        <!-- ===================== Not implemented yet ====================== -->
        <div class="ad-card" style="margin:0">
            <div class="ad-card__head">
                <div>
                    <div class="ad-card__title">Stored but not implemented</div>
                    <?php
                    // Disabled rather than editable: a live-looking switch would promise
                    // behaviour the storefront does not have.
                    ?>
                    <div class="ad-card__sub">Seeded by the schema, read by no storefront code.</div>
                </div>
            </div>
            <div class="ad-card__body" style="display:grid;gap:4px">
                <?php foreach ($dormant as $key => $row): ?>
                    <div class="ad-field">
                        <?php if ($row['type'] === 'bool'): ?>
                            <label class="ad-switch" style="opacity:.55;cursor:not-allowed">
                                <input type="checkbox" disabled <?= $row['value'] === '1' ? 'checked' : '' ?>>
                                <span class="ad-switch__track"></span>
                                <span><?= e($row['label']) ?></span>
                            </label>
                        <?php else: ?>
                            <span class="sik-label"><?= e($row['label']) ?></span>
                            <input class="sik-input" type="number" value="<?= e_attr($row['value']) ?>"
                                   disabled style="opacity:.55;cursor:not-allowed;max-width:160px">
                        <?php endif; ?>
                        <?php // Left in place rather than deleted so the seed data and this screen agree. ?>
                        <span class="sik-help">
                            <code><?= e($key) ?></code> — no consumer, so its value has no effect.
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if ($retired !== []): ?>
            <div class="ad-card" style="margin:0">
                <div class="ad-card__head">
                    <div>
                        <div class="ad-card__title">Retired keys</div>
                        <?php // Shown so the settings group has no invisible rows. ?>
                        <div class="ad-card__sub">
                            Read by nothing, replaced by a newer control. Safe to leave.
                        </div>
                    </div>
                </div>
                <div class="ad-card__body">
                    <div class="ad-tablewrap">
                        <table class="ad-table">
                            <thead><tr><th>Key</th><th style="width:70px">Value</th><th>Replaced by</th></tr></thead>
                            <tbody>
                                <?php foreach ($retired as $key => $why): ?>
                                    <tr>
                                        <td><code><?= e((string) $key) ?></code></td>
                                        <td class="ad-muted"><?= e((string) ($storedWidgets[$key] ?? '')) ?></td>
                                        <td class="ad-muted"><?= e($why) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($elsewhere !== []): ?>
            <div class="ad-card" style="margin:0">
                <div class="ad-card__head">
                    <div>
                        <div class="ad-card__title">Also filed under “widgets”</div>
                        <div class="ad-card__sub">
                            Same settings group, owned by another screen. Edit them where they live.
                        </div>
                    </div>
                </div>
                <div class="ad-card__body">
                    <div class="ad-tablewrap">
                        <table class="ad-table">
                            <thead><tr><th>Key</th><th style="width:90px">Value</th></tr></thead>
                            <tbody>
                                <?php foreach ($elsewhere as $key => $value): ?>
                                    <tr>
                                        <td><code><?= e((string) $key) ?></code></td>
                                        <td class="ad-muted"><?= e((string) $value) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="ad-card" style="margin:0">
            <div class="ad-card__body">
                <div class="sik-alert sik-alert--info" style="margin:0">
                    <?= icon('info', 'w-5 h-5') ?>
                    <div>
                        Off removes the markup, not just the look. Saving clears the page cache, so
                        the storefront changes on the next request.
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
