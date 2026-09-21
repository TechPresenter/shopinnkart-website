<?php
/**
 * ShopInnKart Admin - Appearance reset / undo.
 *
 * Three destructive actions, all POST, all behind CSRF and `settings.edit`:
 *
 *   reset_section   restore one section's keys to their shipped defaults
 *   reset_all       restore every resettable section
 *   undo            put the last snapshot back
 *
 * Every default written here is read out of the screen that owns the field, via
 * appearance_specs(). Nothing in this file knows what a colour or a height
 * should be, which is the point: a reset that carried its own copy of the
 * defaults would drift the first time someone changed one.
 *
 * The `confirm` flag is a second, deliberate step in the UI - the modal will not
 * submit without it. It is checked again here so that a form replayed without
 * the modal (a stale tab, a bookmarked POST) still cannot fire. It is not a
 * security control; CSRF is. Nothing here deletes a row: reset only ever writes
 * settings values, and content - menus, footer links, popups, floating buttons,
 * categories - is out of its reach by construction.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require_action('settings.edit');

require_once ADMIN_PATH . '/appearance/_registry.php';

$back   = admin_url('appearance/');
$action = (string) input('action', '');

if (!input_bool('confirm')) {
    flash('error', 'Nothing was changed - the confirmation box was not ticked.');
    redirect($back);
}

$sections = appearance_sections();
$specs    = appearance_specs();

/** Section keys that are actually allowed to be reset. */
$resettable = [];
foreach ($sections as $key => $section) {
    if (($section['reset'] ?? '') === $key) {
        $resettable[] = $key;
    }
}

// ---------------------------------------------------------------------------
//  Undo - restore the last snapshot
// ---------------------------------------------------------------------------
if ($action === 'undo') {
    $snapshot = appearance_snapshot_read();

    if ($snapshot === null) {
        flash('error', 'There is no snapshot to restore.');
        redirect($back);
    }

    // Swap: capture where we are now first, so Undo is itself undoable and an
    // operator who restores by mistake is not stranded.
    appearance_snapshot_capture('Before undoing the snapshot of ' . (string) ($snapshot['taken_at'] ?? 'earlier'));

    $written = appearance_write((array) $snapshot['values']);

    log_activity('settings.updated', 'settings', null,
        'Restored the appearance snapshot from ' . (string) ($snapshot['taken_at'] ?? '?')
        . ' (' . $written . ' setting(s))');
    admin_after_write();

    flash('success', $written . ' setting(s) restored from the snapshot taken '
        . (string) ($snapshot['taken_at'] ?? 'earlier') . '. The current values were snapshotted first.');
    redirect($back);
}

// ---------------------------------------------------------------------------
//  Reset - work out exactly which keys are in scope
// ---------------------------------------------------------------------------
$targets = [];
$label   = '';

if ($action === 'reset_all') {
    $targets = $resettable;
    $label   = 'every appearance section';
} elseif ($action === 'reset_section') {
    $section = (string) input('section', '');
    if (!in_array($section, $resettable, true)) {
        flash('error', 'That section cannot be reset.');
        redirect($back);
    }
    $targets = [$section];
    $label   = $sections[$section]['label'] . ' settings';
} else {
    flash('error', 'Unknown appearance action.');
    redirect($back);
}

$values = [];
foreach ($targets as $section) {
    foreach (APPEARANCE_KEYS[$section] ?? [] as $key) {
        // A key the specs do not know about is skipped rather than guessed at.
        // appearance_unknown_keys() surfaces those on the hub, so a silent skip
        // here is never a silent failure overall.
        if (array_key_exists($key, $specs['defaults'])) {
            $values[$key] = $specs['defaults'][$key];
        }
    }
}

if ($values === []) {
    flash('error', 'Nothing to reset - no known settings in that section.');
    redirect($back);
}

// The snapshot is taken BEFORE the write and inside its own call, so the row it
// stores is the state the operator is leaving, not the one they are moving to.
appearance_snapshot_capture('Before resetting ' . $label);

$written = appearance_write($values);

log_activity('settings.updated', 'settings', null,
    'Reset ' . $label . ' to shipped defaults (' . $written . ' setting(s))');
admin_after_write();

flash('success', $written . ' setting(s) in ' . $label
    . ' restored to their shipped defaults. Use Undo below if that was not what you wanted.');
redirect($back);
