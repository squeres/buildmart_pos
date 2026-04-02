<?php
/**
 * Goods Receipt — Cancel Handler
 * modules/receipts/cancel.php
 *
 * Cancels a draft or posted document.
 *
 * IMPORTANT STOCK REVERSAL RULE:
 *   - Draft → cancelled: no stock change needed (stock was never touched).
 *   - Posted → cancelled: we REVERSE the stock increase to maintain integrity.
 *     A reversal inventory_movement row (negative qty_change) is created.
 *
 * After cancellation the document cannot be edited or re-posted.
 * If you need to redo a delivery, use Duplicate instead.
 */
require_once __DIR__ . '/../../core/bootstrap.php';
Auth::requireLogin();
Auth::requirePerm('inventory');

if (!is_post() || !csrf_verify()) {
    flash_error(_r('err_csrf'));
    redirect('/modules/receipts/');
}

$id = (int)($_POST['id'] ?? 0);
if (!$id) { redirect('/modules/receipts/'); }

$doc = Database::row("SELECT * FROM goods_receipts WHERE id=?", [$id]);
if (!$doc) { flash_error(_r('err_not_found')); redirect('/modules/receipts/'); }
require_warehouse_access((int)$doc['warehouse_id'], '/modules/receipts/');

if ($doc['status'] === 'cancelled') {
    flash_error(_r('gr_err_already_cancelled'));
    redirect('/modules/receipts/view.php?id=' . $id);
}

try {
    Database::beginTransaction();

    // If document was posted, reverse stock movements
    if ($doc['status'] === 'accepted') {
        $warehouseId = (int)$doc['warehouse_id'];
        $items = Database::all(
            "SELECT * FROM goods_receipt_items WHERE receipt_id=?",
            [$id]
        );
        foreach ($items as $item) {
            if (!$item['product_id']) continue;

            $productBaseUnit = (string)(Database::value("SELECT unit FROM products WHERE id=?", [$item['product_id']]) ?: $item['unit']);
            $unitMap = product_unit_map((int)$item['product_id'], $productBaseUnit);
            $selectedUnit = $unitMap[$item['unit']] ?? ($unitMap[$productBaseUnit] ?? ['ratio_to_base' => 1]);
            $qtyDocument = $item['accepted_qty'] !== null ? (float)$item['accepted_qty'] : (float)$item['qty'];
            $qtyChange = -stock_qty_round($qtyDocument / max(1, (float)$selectedUnit['ratio_to_base']));
            [$qtyBefore, $qtyAfter] = update_stock_balance((int)$item['product_id'], $warehouseId, $qtyChange);

            Database::exec(
                "INSERT INTO inventory_movements
                   (product_id, warehouse_id, user_id, type, qty_change, qty_before, qty_after, unit_cost, reference_id, reference_type, notes)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)",
                [
                    $item['product_id'], $warehouseId, Auth::id(),
                    'adjustment', $qtyChange, $qtyBefore, $qtyAfter,
                    $item['unit_price'], $id, 'goods_receipt_cancel',
                    'Cancellation of GR#' . $id,
                ]
            );
        }
    }

    Database::exec(
        "UPDATE goods_receipts
         SET status='cancelled', cancelled_by=?, cancelled_at=NOW(), updated_at=NOW()
         WHERE id=?",
        [Auth::id(), $id]
    );

    Database::commit();
    flash_success(_r('gr_cancelled_ok'));

} catch (Throwable $e) {
    Database::rollback();
    flash_error(_r('err_db') . ': ' . $e->getMessage());
}

redirect('/modules/receipts/view.php?id=' . $id);
