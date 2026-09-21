<?php
/**
 * ShopInnKart - Lazy widget renderer.
 *
 * Backs the [data-lazy-widget] path in assets/js/app.js: a deferred widget
 * renders an empty shell server-side and fetches its body from here.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/init.php';
require_once INCLUDES_PATH . '/widgets.php';

api_require_method(['GET']);

$key = trim((string) input('key', ''));
if ($key === '') {
    json_validation_error(['key' => 'A widget key is required.']);
}

$widget = Database::fetch(
    "SELECT * FROM `homepage_sections` WHERE `section_key` = :key AND `status` = 'active' LIMIT 1",
    ['key' => $key]
);

if ($widget === null) {
    json_error('That section is no longer available.', [], 404);
}

// Schedule and device/auth rules are re-checked here: the shell may have been
// rendered before the window closed, and the client never decides visibility.
if (!visibility_allows($widget)) {
    json_success('OK', ['html' => '']);
}

// Recommendation and recently-viewed widgets need to know where they sit.
$context = ['force' => true];
foreach (['product_id', 'category_id', 'exclude_id'] as $contextKey) {
    if (input_int($contextKey) > 0) {
        $context[$contextKey] = input_int($contextKey);
    }
}

json_success('OK', [
    'key'  => (string) $widget['section_key'],
    'html' => render_widget($widget, $context),
]);
