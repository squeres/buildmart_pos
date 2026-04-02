<?php
require_once __DIR__ . '/../../core/bootstrap.php';

Auth::requireLogin();
Auth::requirePerm('shifts');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'message' => 'Bad request'], 405);
}

if (!csrf_verify()) {
    json_response(['success' => false, 'message' => _r('err_csrf')], 403);
}

$body = $_POST;
if (str_contains(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')) {
    $decoded = json_decode(file_get_contents('php://input'), true);
    if (is_array($decoded)) {
        $body = $decoded;
    }
}

shift_expire_stale_extension_requests();

$shift = Database::row(
    "SELECT *
     FROM shifts
     WHERE user_id = ? AND status = 'open'
     ORDER BY opened_at DESC
     LIMIT 1",
    [Auth::id()]
);

if (!$shift) {
    json_response([
        'success' => false,
        'message' => _r('pos_no_shift'),
        'code' => 'shift_not_open',
    ], 409);
}

$requestState = shift_can_request_extension($shift);
if (empty($requestState['ok'])) {
    json_response([
        'success' => false,
        'message' => $requestState['message'],
        'code' => 'shift_extension_unavailable',
    ], 409);
}

$requestedMinutes = max(0, (int)($body['requested_minutes'] ?? 0));
$reason = trim(strip_tags((string)($body['reason'] ?? '')));
if ($requestedMinutes > (int)$requestState['remaining_minutes']) {
    json_response([
        'success' => false,
        'message' => _r('shift_extension_limit_reached'),
        'code' => 'shift_extension_limit_reached',
    ], 422);
}

if ($requestedMinutes === 0) {
    $requestedMinutes = null;
}

try {
    $requestId = Database::insert(
        "INSERT INTO shift_extension_requests (
            shift_id,
            cashier_id,
            requested_at,
            requested_minutes,
            reason,
            status,
            created_at
         ) VALUES (?, ?, NOW(), ?, ?, 'pending', NOW())",
        [
            (int)$shift['id'],
            Auth::id(),
            $requestedMinutes,
            $reason !== '' ? $reason : null,
        ]
    );
} catch (Throwable $e) {
    json_response([
        'success' => false,
        'message' => _r('err_validation'),
        'code' => 'shift_extension_unavailable',
    ], 500);
}

json_response([
    'success' => true,
    'message' => _r('shift_extension_request_sent'),
    'request_id' => (int)$requestId,
]);
