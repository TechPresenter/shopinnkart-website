<?php
/**
 * ShopInnKart Admin - Reorder the sections in a zone.
 *
 * Two entry points, one endpoint:
 *   move=up|down   plain form post from the arrow buttons, redirects back.
 *                  Works with JavaScript switched off.
 *   sort_order=N   AJAX save from the number input, answers JSON.
 *
 * POST + CSRF, enforced by admin_require_action().
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require_action('homepage.edit');

$id   = request_int('id');
$move = strtolower(trim((string) request_input('move', '')));

$section = $id > 0
    ? Database::fetch('SELECT `id`, `zone`, `section_key`, `sort_order` FROM `homepage_sections` WHERE `id` = :id', ['id' => $id])
    : null;

// ---------------------------------------------------------------------------
//  Drag reorder - the whole zone in one request
// ---------------------------------------------------------------------------
$ordered = trim((string) request_input('order', ''));
if ($ordered !== '') {
    $wanted = array_values(array_filter(array_map('intval', explode(',', $ordered))));
    if ($wanted === []) {
        json_validation_error(['order' => 'No sections were supplied.']);
    }

    $zone = trim((string) request_input('zone', ''));
    if ($zone === '') {
        json_validation_error(['zone' => 'A zone is required to reorder.']);
    }

    // The zone's real membership decides what may be written. Trusting the
    // posted list would let a crafted request renumber rows in another zone,
    // and would silently drop any section the browser had not loaded.
    $actual = array_map('intval', Database::fetchColumnAll(
        'SELECT `id` FROM `homepage_sections` WHERE `zone` = :zone ORDER BY `sort_order` ASC, `id` ASC',
        ['zone' => $zone]
    ));

    $known = array_values(array_intersect($wanted, $actual));
    if (count($known) !== count($actual)) {
        // Someone else added or deleted a section while this page was open.
        // Renumbering now would drop whatever is missing to the bottom in an
        // arbitrary order, so refuse and let the browser reload.
        json_error('This zone changed in another window. Reload to see the current order.', [], 409);
    }

    Database::transaction(static function () use ($known): void {
        foreach ($known as $index => $sectionId) {
            Database::update('homepage_sections', ['sort_order' => $index + 1], '`id` = :id', ['id' => $sectionId]);
        }
    });

    log_activity('homepage_section.reordered', 'homepage_section', 0,
        'Reordered ' . count($known) . ' sections in the ' . $zone . ' zone by drag');
    admin_after_write();

    json_success('Order saved.', ['zone' => $zone, 'order' => $known]);
}

// ---------------------------------------------------------------------------
//  Arrow buttons
// ---------------------------------------------------------------------------
if ($move === 'up' || $move === 'down') {
    if ($section === null) {
        flash('error', 'That section no longer exists.');
        redirect(admin_url('homepage/'));
    }

    $zone = (string) $section['zone'];
    $ids = Database::fetchColumnAll(
        'SELECT `id` FROM `homepage_sections` WHERE `zone` = :zone ORDER BY `sort_order` ASC, `id` ASC',
        ['zone' => $zone]
    );
    $ids = array_map('intval', $ids);
    $position = array_search($id, $ids, true);
    $target = $position === false ? false : $position + ($move === 'up' ? -1 : 1);

    if ($target === false || $target < 0 || $target >= count($ids)) {
        flash('warning', 'That section is already at the ' . ($move === 'up' ? 'top' : 'bottom') . ' of its zone.');
        redirect(admin_url('homepage/?zone=' . urlencode($zone)));
    }

    // Swap in the list, then renumber the whole zone. Renumbering matters:
    // seeded rows can share a sort_order, and swapping two equal numbers
    // would move nothing.
    [$ids[$position], $ids[$target]] = [$ids[$target], $ids[$position]];

    Database::transaction(static function () use ($ids): void {
        foreach ($ids as $index => $sectionId) {
            Database::update('homepage_sections', ['sort_order' => $index + 1], '`id` = :id', ['id' => $sectionId]);
        }
    });

    log_activity('homepage_section.reordered', 'homepage_section', $id,
        'Moved "' . $section['section_key'] . '" ' . $move . ' in the ' . $zone . ' zone');
    admin_after_write();

    flash('success', 'Section order updated.');
    redirect(admin_url('homepage/?zone=' . urlencode($zone)));
}

// ---------------------------------------------------------------------------
//  Number input
// ---------------------------------------------------------------------------
if ($section === null) {
    json_error('That section no longer exists.', [], 404);
}

$sortOrder = request_int('sort_order', -1);
if ($sortOrder < 0 || $sortOrder > 9999) {
    json_validation_error(['sort_order' => 'Sort order must be between 0 and 9999.']);
}

Database::update('homepage_sections', ['sort_order' => $sortOrder], '`id` = :id', ['id' => $id]);

log_activity('homepage_section.reordered', 'homepage_section', $id,
    'Set sort order of "' . $section['section_key'] . '" to ' . $sortOrder);
admin_after_write();

json_success('Order saved.', ['id' => $id, 'sort_order' => $sortOrder]);
