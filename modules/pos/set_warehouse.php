<?php
/**
 * modules/pos/set_warehouse.php
 * AJAX: set the active warehouse for this session (global, not POS-only).
 * Only admin/manager (inventory perm) can switch.
 */
require_once __DIR__ . '/../../core/bootstrap.php';
Auth::requireLogin();

// Only admin + manager can switch warehouses
if (!Auth::can('inventory')) {
    json_response(['success' => false, 'message' => 'Access denied'], 403);
}

$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!hash_equals(csrf_token(), $csrfToken)) {
    json_response(['success' => false, 'message' => _r('err_csrf')], 403);
}

$body = json_decode(file_get_contents('php://input'), true);
$whId = (int)($body['warehouse_id'] ?? 0);

if ($whId < 0 || ($whId > 0 && !user_can_access_warehouse($whId))) {
    json_response(['success' => false, 'message' => _r('tr_err_no_access')], 403);
}

selected_warehouse_id($whId);

if ($whId === 0) {
    json_response(['success' => true, 'warehouse' => ['id' => 0, 'name' => __('lbl_all') . ' ' . __('wh_title')]]);
}

$wh = Database::row("SELECT id, name FROM warehouses WHERE id=?", [$whId]);
json_response(['success' => true, 'warehouse' => ['id' => (int)$wh['id'], 'name' => $wh['name']]]);
