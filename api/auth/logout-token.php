<?php
/**
 * POST /api/auth/logout-token.php
 *
 * Sign one device out (the default) or every device on the account.
 *
 *   Authorization: Bearer sikt_...
 *   { "all": true }          // optional - revoke every token on the account
 *
 * The app's own "log out" and its "log out everywhere". A revoked token is
 * refused from the very next request, and using it again is recorded as
 * api.token_reuse_after_revoke - which is how a copied token shows up.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/init.php';

api_require_method(['POST']);

$auth = api_token_authenticate();
if ($auth === null) {
    // Same answer whether the token was never valid, has expired or was
    // revoked an hour ago: it tells a holder nothing they can work with.
    json_error('That token is not valid.', [], 401, 'auth');
}

$token    = $auth['token'];
$userType = (string) $auth['user_type'];
$userId   = (int) $token['user_id'];
$tokenId  = (int) $token['id'];

if (request_bool('all')) {
    $count = api_token_revoke_all($userType, $userId, 'signed_out_all');
    json_success('Signed out on every device.', ['revoked' => $count]);
}

api_token_revoke($tokenId, 'signed_out');

json_success('Signed out on this device.', ['revoked' => 1]);
