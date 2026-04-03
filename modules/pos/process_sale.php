<?php
require_once __DIR__ . '/../../core/bootstrap.php';
Auth::requireLogin();
Auth::requirePerm('pos.sell');

if (!is_ajax() || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'message' => 'Bad request'], 400);
}

$body = json_decode(file_get_contents('php://input'), true);
if (!$body) {
    json_response(['success' => false, 'message' => _r('err_validation')]);
}

$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!hash_equals(csrf_token(), $csrfToken)) {
    json_response(['success' => false, 'message' => _r('err_csrf')], 403);
}

$warehouseId = (int)($body['warehouse_id'] ?? 0);

$sessionWhId = pos_warehouse_id();
if ($warehouseId !== $sessionWhId) {
    $warehouseId = $sessionWhId;
}

try {
    $response = SaleService::processPosCheckout($body, Auth::id(), $warehouseId);
    json_response($response);
} catch (AppServiceException $e) {
    $status = str_starts_with($e->appCode(), 'shift_') ? 409 : 422;
    json_response(
        array_merge([
            'success' => false,
            'message' => $e->getMessage(),
            'code' => $e->appCode(),
        ], $e->payload()),
        $status
    );
} catch (Throwable $e) {
    error_log($e->__toString());
    json_response(['success' => false, 'message' => _r('err_db')], 500);
}
