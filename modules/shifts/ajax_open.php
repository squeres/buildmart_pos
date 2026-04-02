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

$openingCash = max(0, (float)sanitize_float($body['opening_cash'] ?? 0));
$notes = sanitize($body['notes'] ?? '');
try {
    $result = ShiftService::openShift(Auth::id(), $openingCash, $notes);
    json_response([
        'success' => true,
        'message' => $result['message'],
        'shift_id' => $result['shift_id'],
        'already_open' => $result['already_open'],
        'code' => $result['already_open'] ? 'already_open' : 'shift_opened',
    ]);
} catch (AppServiceException $e) {
    json_response([
        'success' => false,
        'message' => $e->getMessage(),
        'code' => $e->appCode(),
    ], 409);
} catch (Throwable $e) {
    error_log($e->__toString());
    json_response(['success' => false, 'message' => _r('err_db')], 500);
}
