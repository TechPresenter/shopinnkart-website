<?php
/**
 * POST /api/account/address-save.php
 * Create or update an address in the customer's address book.
 *
 * Fields: address_id (omit to create), label, full_name, phone, address_line1,
 * address_line2, landmark, city, state, pincode, country, address_type, is_default.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/init.php';

api_require_method(['POST']);
api_require_csrf();
$user = api_require_login();
api_rate_limit('address_save', 30, 300);

$userId = (int) $user['id'];
$addressId = request_int('address_id');

$validator = new Validator(request_all(), [
    'full_name'     => 'Full name',
    'phone'         => 'Mobile number',
    'address_line1' => 'Address',
    'address_line2' => 'Address line 2',
    'landmark'      => 'Landmark',
    'city'          => 'City',
    'state'         => 'State',
    'pincode'       => 'PIN code',
    'label'         => 'Label',
]);

$validator->required('full_name')->max('full_name', 150)
    ->required('phone')->phone('phone')
    ->required('address_line1')->max('address_line1', 255)
    ->max('address_line2', 255)
    ->max('landmark', 150)
    ->required('city')->max('city', 100)
    ->required('state')->in('state', INDIAN_STATES)
    ->required('pincode')->pincode('pincode')
    ->max('label', 50)
    ->in('address_type', ['home', 'work', 'other']);

if ($validator->fails()) {
    json_validation_error($validator->errors());
}

// Editing: the row must belong to this customer, not just exist.
if ($addressId > 0) {
    $owned = Database::exists('user_addresses', '`id` = :id AND `user_id` = :uid', ['id' => $addressId, 'uid' => $userId]);
    if (!$owned) {
        json_error('That address could not be found in your account.', [], 404);
    }
}

$clean = $validator->validated([
    'full_name', 'phone', 'address_line1', 'address_line2', 'landmark',
    'city', 'state', 'pincode', 'label', 'address_type',
]);

$blankToNull = static fn ($value): ?string => trim((string) $value) === '' ? null : trim((string) $value);

$addressType = (string) ($clean['address_type'] ?? '');

$data = [
    'full_name'     => (string) $clean['full_name'],
    'phone'         => (string) normalize_phone((string) $clean['phone']),
    'address_line1' => (string) $clean['address_line1'],
    'address_line2' => $blankToNull($clean['address_line2'] ?? null),
    'landmark'      => $blankToNull($clean['landmark'] ?? null),
    'city'          => (string) $clean['city'],
    'state'         => (string) $clean['state'],
    'pincode'       => (string) $clean['pincode'],
    // Only India is serviceable, and the PIN code rule already assumes it.
    'country'       => 'India',
    'label'         => $blankToNull($clean['label'] ?? null) ?? 'Home',
    'address_type'  => $addressType === '' ? 'home' : $addressType,
];

$existingCount = Database::count('user_addresses', '`user_id` = :uid', ['uid' => $userId]);

// The first address is the default whether or not the box was ticked, so a
// customer always has one to fall back on at checkout.
$makeDefault = request_bool('is_default') || ($addressId === 0 && $existingCount === 0);

$savedId = Database::transaction(static function () use ($data, $userId, $addressId, $makeDefault): int {
    // Clearing first keeps "exactly one default" true at every point where
    // another request could read the table.
    if ($makeDefault) {
        Database::update('user_addresses', ['is_default' => 0], '`user_id` = :uid', ['uid' => $userId]);
    }

    if ($addressId > 0) {
        if ($makeDefault) {
            $data['is_default'] = 1;
        }
        Database::update('user_addresses', $data, '`id` = :id AND `user_id` = :uid', ['id' => $addressId, 'uid' => $userId]);
        return $addressId;
    }

    $data['user_id'] = $userId;
    $data['is_default'] = $makeDefault ? 1 : 0;
    return Database::insert('user_addresses', $data);
});

$address = Database::fetch(
    'SELECT * FROM `user_addresses` WHERE `id` = :id AND `user_id` = :uid LIMIT 1',
    ['id' => $savedId, 'uid' => $userId]
);

json_success($addressId > 0 ? 'Address updated.' : 'Address saved.', [
    'address_id' => $savedId,
    'address'    => $address,
    'is_default' => (int) ($address['is_default'] ?? 0) === 1,
]);
