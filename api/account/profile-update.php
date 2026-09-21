<?php
/**
 * POST /api/account/profile-update.php
 * Update the signed-in customer's personal details.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/init.php';

api_require_method(['POST']);
api_require_csrf();
$user = api_require_login();
api_rate_limit('profile_update', 20, 300);

$validator = new Validator(request_all(), [
    'first_name'    => 'First name',
    'last_name'     => 'Last name',
    'phone'         => 'Mobile number',
    'gender'        => 'Gender',
    'date_of_birth' => 'Date of birth',
]);

$dob = trim((string) request_input('date_of_birth', ''));
$dobTime = $dob === '' ? null : strtotime($dob);

$validator->required('first_name')->max('first_name', 100)
    ->max('last_name', 100)
    ->required('phone')->phone('phone')
    ->in('gender', ['male', 'female', 'other'])
    ->date('date_of_birth')
    ->rule('date_of_birth', $dobTime === null || ($dobTime !== false && $dobTime <= time()), 'Date of birth cannot be in the future.');

if ($validator->fails()) {
    json_validation_error($validator->errors());
}

$clean = $validator->validated(['first_name', 'last_name', 'phone', 'gender']);
$lastName = trim((string) ($clean['last_name'] ?? ''));
$gender = (string) ($clean['gender'] ?? '');

// Email is intentionally not editable here. Changing it needs a verification
// round-trip to the new address first - order mail and password resets are
// delivered there, so an unverified change would hand the account away.
Database::update('users', [
    'first_name'    => (string) $clean['first_name'],
    'last_name'     => $lastName === '' ? null : $lastName,
    'phone'         => normalize_phone((string) $clean['phone']),
    'gender'        => $gender === '' ? null : $gender,
    'date_of_birth' => $dobTime === null || $dobTime === false ? null : date('Y-m-d', $dobTime),
], '`id` = :id', ['id' => (int) $user['id']]);

// The header greeting reads the cached session copy, so refresh it too.
$displayName = trim($clean['first_name'] . ' ' . $lastName);
$_SESSION[USER_SESSION_KEY]['name'] = $displayName;

json_success('Your profile has been updated.', ['name' => $displayName]);
