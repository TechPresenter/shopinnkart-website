<?php
/**
 * ShopInnKart Admin - Security settings.
 *
 * Home of the controls that decide who can reach the store's back office and
 * how. The page is a shell: every control is a card file in cards/, loaded in
 * file-name order. A card returns:
 *
 *   [
 *     'key'     => 'login-address',          // unique
 *     'order'   => 10,                       // position within its column
 *     'column'  => 'main' | 'side',
 *     'actions' => ['gate_save' => fn (string $back): void, ...],
 *     'render'  => fn (array $errors, bool $canEdit): void,
 *   ]
 *
 * Each card posts its own small form carrying `action`, and only that card's
 * handler runs - so saving one control can never silently reset another.
 * These are the settings where an accidental reset locks the owner out.
 * Handlers always redirect; the shell has already enforced POST + CSRF +
 * security.edit before any of them runs.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('security.view');

$back  = admin_url('security/settings.php');
$cards = [];
foreach (glob(__DIR__ . '/cards/*.php') ?: [] as $file) {
    $card = require $file;
    if (is_array($card) && isset($card['key'], $card['render'])) {
        $cards[] = $card + ['order' => 50, 'column' => 'main', 'actions' => []];
    }
}
usort($cards, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);

if (is_post()) {
    admin_require_action('security.edit');   // POST + CSRF + permission

    $action = (string) input('action', '');
    foreach ($cards as $card) {
        if (isset($card['actions'][$action]) && is_callable($card['actions'][$action])) {
            $card['actions'][$action]($back);
            redirect($back);   // a handler that forgot to redirect still lands here
        }
    }

    flash('error', 'Unknown action.');
    redirect($back);
}

$errors  = errors_pull();
$canEdit = admin_can('security.edit');

$pageTitle    = 'Security Settings';
$pageSubtitle = 'Who can reach the back office, and how.';
$breadcrumbs  = [
    ['label' => 'Dashboard', 'url' => admin_url('dashboard.php')],
    ['label' => 'Security'],
    ['label' => 'Settings'],
];

require ADMIN_PATH . '/includes/header.php';
?>

<?php if (!$canEdit): ?>
    <div class="sik-alert sik-alert--info">
        <?= icon('info', 'w-5 h-5') ?>
        <div>Read-only. Ask a Super Admin for <code>security.edit</code> to change anything here.</div>
    </div>
<?php endif; ?>

<div class="ad-grid ad-grid--sidebar">
    <?php foreach (['main', 'side'] as $column): ?>
        <div style="display:grid;gap:18px;align-content:start">
            <?php foreach ($cards as $card): ?>
                <?php if ($card['column'] === $column): ?>
                    <?php $card['render']($errors, $canEdit); ?>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
</div>

<?php old_clear(); ?>
<?php require ADMIN_PATH . '/includes/footer.php'; ?>
