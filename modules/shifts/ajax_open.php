<?php
require_once __DIR__ . '/../../core/bootstrap.php';

Auth::requireLogin();
Auth::requirePerm('shifts');

if (!is_ajax() || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'message' => 'Bad request'], 400);
}

$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!hash_equals(csrf_token(), $csrfToken)) {
    json_response(['success' => false, 'message' => _r('err_csrf')], 403);
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    json_response(['success' => false, 'message' => _r('err_validation')], 422);
}

$openGuard = shift_can_open_now(Auth::id());
if (!$openGuard['ok']) {
    $status = ($openGuard['code'] ?? '') === 'already_open' ? 200 : 409;
    json_response([
        'success' => ($openGuard['code'] ?? '') === 'already_open',
        'message' => $openGuard['message'],
        'shift_id' => (int)($openGuard['shift']['id'] ?? 0),
        'already_open' => ($openGuard['code'] ?? '') === 'already_open',
        'code' => $openGuard['code'] ?? 'shift_open_denied',
    ], $status);
}

$openingCash = max(0, (float)sanitize_float($body['opening_cash'] ?? 0));
$notes = sanitize($body['notes'] ?? '');

$shiftId = Database::insert(
    "INSERT INTO shifts (user_id, opening_cash, notes, status, opened_at) VALUES (?, ?, ?, 'open', NOW())",
    [Auth::id(), $openingCash, $notes]
);

json_response([
    'success' => true,
    'message' => _r('shift_opened'),
    'shift_id' => (int)$shiftId,
]);
