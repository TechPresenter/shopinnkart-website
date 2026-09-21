<?php
/**
 * POST /api/account/address-delete.php
 * Remove an address from the signed-in customer's address book.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/init.php';

api_require_method(['POST']);
api_require_csrf();
$user = api_require_login();
api_rate_limit('address_delete', 20, 300);

$validator = new Validator(request_all(), ['address_id' => 'Address']);
$validator->required('address_id')->integer('address_id');

if ($validator->fails()) {
    json_validation_error($validator->errors());
}

$userId = (int) $user['id'];
$addressId = request_int('address_id');

$address = Database::fetch(
    'SELECT `id`, `is_default` FROM `user_addresses` WHERE `id` = :id AND `user_id` = :uid LIMIT 1',
    ['id' => $addressId, 'uid' => $userId]
);

if ($address === null) {
    json_error('That address could not be found in your account.', [], 404);
}

$promotedId = Database::transaction(static function () use ($addressId, $userId, $address): ?int {
    Database::delete('user_addresses', '`id` = :id AND `user_id` = :uid', ['id' => $addressId, 'uid' => $userId]);

    if ((int) $address['is_default'] !== 1) {
        return null;
    }

    // Deleting the default would leave checkout with nothing pre-selected,
    // so the newest remaining address takes over.
    $next = Database::fetchColumn(
        'SELECT `id` FROM `user_addresses` WHERE `user_id` = :uid ORDER BY `id` DESC LIMIT 1',
        ['uid' => $userId]
    );
    if ($next === null) {
        return null;
    }

    Database::update('user_addresses', ['is_default' => 1], '`id` = :id', ['id' => (int) $next]);
    return (int) $next;
});

json_success('Address deleted.', [
    'address_id' => $addressId,
    'default_id' => $promotedId,
    'remaining'  => Database::count('user_addresses', '`user_id` = :uid', ['uid' => $userId]),
]);
