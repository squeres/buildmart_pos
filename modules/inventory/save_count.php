<?php
require_once __DIR__ . '/../../core/bootstrap.php';

Auth::requireLogin();
Auth::requirePerm('inventory.apply');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'message' => _r('err_validation')], 405);
}

if (!csrf_verify()) {
    json_response(['success' => false, 'message' => _r('err_csrf')], 403);
}

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$warehouseId = (int)($payload['warehouse_id'] ?? 0);
if ($warehouseId <= 0 || !user_can_access_warehouse($warehouseId)) {
    json_response(['success' => false, 'message' => _r('auth_no_permission')], 403);
}

$items = $payload['items'] ?? [];
if (!is_array($items) || $items === []) {
    json_response(['success' => false, 'message' => __('inv_count_queue_empty')], 422);
}

$preparedItems = [];
foreach ($items as $rawItem) {
    if (!is_array($rawItem)) {
        continue;
    }

    $productId = (int)($rawItem['product_id'] ?? 0);
    $actualQty = sanitize_float($rawItem['actual_qty'] ?? 0);
    $notes = sanitize($rawItem['notes'] ?? '');

    if ($productId <= 0) {
        json_response(['success' => false, 'message' => _r('prod_not_found')], 422);
    }
    if ($actualQty < 0) {
        json_response(['success' => false, 'message' => __('inv_count_actual_required')], 422);
    }

    $preparedItems[$productId] = [
        'product_id' => $productId,
        'actual_qty' => $actualQty,
        'notes' => $notes,
    ];
}

if ($preparedItems === []) {
    json_response(['success' => false, 'message' => __('inv_count_queue_empty')], 422);
}

$movementType = inventory_movement_type('inventory');

try {
    $applied = Database::transaction(static function () use ($preparedItems, $warehouseId, $movementType): int {
        $updatedCount = 0;

        foreach ($preparedItems as $item) {
            $product = Database::row(
                'SELECT id, name_en, name_ru, unit FROM products WHERE id = ? AND is_active = 1 LIMIT 1',
                [(int)$item['product_id']]
            );
            if (!$product) {
                throw new AppServiceException(_r('prod_not_found'), 'product_not_found');
            }

            [$qtyBefore, $qtyAfter] = InventoryService::setStock(
                (int)$item['product_id'],
                $warehouseId,
                (float)$item['actual_qty']
            );

            $qtyChange = stock_qty_round($qtyAfter - $qtyBefore);
            $notes = $item['notes'] !== ''
                ? $item['notes']
                : __('inv_count_note_default');

            Database::insert(
                "INSERT INTO inventory_movements
                    (product_id, warehouse_id, user_id, type, qty_change, qty_before, qty_after, notes, created_at)
                 VALUES (?,?,?,?,?,?,?,?,NOW())",
                [
                    (int)$item['product_id'],
                    $warehouseId,
                    Auth::id(),
                    $movementType,
                    $qtyChange,
                    $qtyBefore,
                    $qtyAfter,
                    $notes,
                ]
            );

            $updatedCount++;
        }

        return $updatedCount;
    });

    json_response([
        'success' => true,
        'count' => $applied,
        'message' => __('inv_count_saved'),
    ]);
} catch (AppServiceException $e) {
    json_response([
        'success' => false,
        'message' => $e->getMessage(),
        'code' => $e->appCode(),
    ], 422);
} catch (Throwable $e) {
    error_log($e->__toString());
    json_response(['success' => false, 'message' => _r('err_db')], 500);
}
