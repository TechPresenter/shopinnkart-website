<?php
/**
 * GET|POST /api/account/preferences.php - read or save the customer's preferences.
 *
 * GET  returns { preferences, schema } - the stored values with defaults filled
 *      in, plus the grouped schema the preferences screen renders from.
 * POST accepts any subset of the preference keys and returns { preferences, saved },
 *      or 422 keyed by field when a value is not one of that field's options.
 *
 * One file for both verbs because the schema is the contract for the write: a
 * client that can read it already knows every key and value this will accept.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/init.php';

api_require_method(['GET', 'POST']);

$isWrite = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

if ($isWrite) {
    api_require_csrf();
}

$user = api_require_login();
$userId = (int) $user['id'];

if (!$isWrite) {
    json_success('OK', [
        'preferences' => user_preferences($userId),
        'schema'      => preference_schema(),
    ]);
}

api_rate_limit('preferences_save', 30, 300);

// The whole payload goes in: save_user_preferences() is the single place that
// decides which keys exist and which values are legal, so nothing is filtered
// or renamed on the way. Unknown keys - including the CSRF token - are dropped
// there rather than stored.
$result = save_user_preferences(request_all(), $userId);

if ($result['errors'] !== []) {
    // '_' is the helper's marker for a storage failure, not a bad field value;
    // 422 would tell the customer to correct something that was already valid.
    if (isset($result['errors']['_'])) {
        json_error((string) $result['errors']['_'], [], 500);
    }
    json_validation_error($result['errors']);
}

json_success($result['saved'] > 0 ? 'Your preferences have been saved.' : 'No changes to save.', [
    // Read back after the write so the client renders what was actually stored,
    // including any key it did not send.
    'preferences' => user_preferences($userId),
    'saved'       => $result['saved'],
]);
