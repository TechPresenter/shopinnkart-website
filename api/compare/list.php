<?php
/**
 * GET /api/compare/list.php - the comparison list for the floating bar.
 *
 * Only the fields the bar draws; the full spec matrix lives on compare.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/_helpers.php';

api_require_method(['GET']);

// A disabled comparison has no bar to draw - reading it is refused for the same
// reason writing it is.
api_require_feature('compare');

$items = compare_bar_items();

json_success('OK', [
    'count' => count($items),
    'items' => $items,
    'max'   => compare_max(),
]);
