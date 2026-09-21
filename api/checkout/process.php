<?php
/**
 * POST /api/checkout/process.php
 *
 * Places the order from the checkout form. The whole payload is handed to
 * create_order(), which owns validation, pricing, stock and the transaction.
 *
 * Returns: { order_number, order_id, redirect }
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/init.php';
require_once ROOT_PATH . '/api/includes/order-handler.php';

api_place_order();
