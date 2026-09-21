<?php
/**
 * POST /api/orders/create.php
 *
 * Alias of /api/checkout/process.php for callers that think in terms of
 * orders rather than checkout. Same handler, same behaviour.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/init.php';
require_once ROOT_PATH . '/api/includes/order-handler.php';

api_place_order();
