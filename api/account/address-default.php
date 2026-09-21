<?php
/**
 * POST /api/account/address-default.php
 * Make one saved address the customer's default.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/init.php';

api_require_method(['POST']);
api_require_csrf();
$user = api_require_login();
api_rate_limit('address_default', 20, 300);

$validator = new Validator(request_all(), ['address_id' => 'Address']);
$validator->required('address_id')->integer('address_id');

if ($validator->fails()) {
    json_validation_error($validator->errors());
}

$userId = (int) $user['id'];
$addressId = request_int('address_id');

if (!Database::exists('user_addresses', '`id` = :id AND `user_id` = :uid', ['id' => $addressId, 'uid' => $userId])) {
    json_error('That address could not be found in your account.', [], 404);
}

// Both writes together: a reader must never see two defaults or none.
Database::transaction(static function () use ($addressId, $userId): void {
    Database::update('user_addresses', ['is_default' => 0], '`user_id` = :uid', ['uid' => $userId]);
    Database::update('user_addresses', ['is_default' => 1], '`id` = :id AND `user_id` = :uid', ['id' => $addressId, 'uid' => $userId]);
});

json_success('Default address updated.', ['address_id' => $addressId]);
