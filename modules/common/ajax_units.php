<?php
require_once __DIR__ . '/../../core/bootstrap.php';

Auth::requireLogin();
if (!Auth::can('products') && !Auth::can('inventory')) {
    http_response_code(403);
    json_response(['success' => false, 'error' => 'Forbidden'], 403);
}

header('Content-Type: application/json; charset=utf-8');

if (!is_post() || !is_ajax()) {
    json_response(['success' => false, 'error' => 'Invalid request'], 400);
}

if (!csrf_verify()) {
    json_response(['success' => false, 'error' => _r('err_csrf')], 403);
}

$action = sanitize($_POST['action'] ?? '');

try {
    if ($action === 'create_unit_preset') {
        $label = sanitize($_POST['label'] ?? '');
        if ($label === '') {
            json_response(['success' => false, 'error' => _r('lbl_required') . ': ' . _r('lbl_unit')], 422);
        }

        $row = create_unit_preset($label);
        json_response([
            'success' => true,
            'data' => [
                'id' => (int)($row['id'] ?? 0),
                'unit_code' => (string)($row['unit_code'] ?? ''),
                'unit_label' => (string)($row['unit_label'] ?? $label),
                'storage_code' => unit_storage_code_from_label((string)($row['unit_label'] ?? $label)),
            ],
        ]);
    }

    json_response(['success' => false, 'error' => 'Unknown action'], 400);
} catch (Throwable $e) {
    json_response(['success' => false, 'error' => $e->getMessage()], 500);
}
