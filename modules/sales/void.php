<?php
require_once __DIR__ . '/../../core/bootstrap.php';
Auth::requireLogin();
Auth::requirePerm('sales');

if (!is_post() || !csrf_verify()) {
    flash_error(_r('err_csrf'));
    redirect('/modules/sales/');
}

$id   = (int)($_POST['id'] ?? 0);
try {
    Database::beginTransaction();

    $sale = Database::row("SELECT * FROM sales WHERE id=? FOR UPDATE", [$id]);
    if (!$sale || $sale['status'] !== 'completed') {
        throw new RuntimeException(_r('err_not_found'));
    }
    if (!user_can_access_warehouse((int)$sale['warehouse_id'])) {
        throw new RuntimeException(_r('auth_no_permission'));
    }

    $items = Database::all("SELECT * FROM sale_items WHERE sale_id=?", [$id]);
    foreach ($items as $it) {
        $baseUnit = (string)(Database::value("SELECT unit FROM products WHERE id=?", [$it['product_id']]) ?: 'pcs');
        $unitMap = product_unit_map((int)$it['product_id'], $baseUnit);
        $saleUnit = $unitMap[$it['unit']] ?? ($unitMap[$baseUnit] ?? ['ratio_to_base' => 1]);
        $qtyBase = stock_qty_round((float)$it['qty'] / max(1, (float)$saleUnit['ratio_to_base']));
        [$before, $after] = update_stock_balance((int)$it['product_id'], (int)$sale['warehouse_id'], $qtyBase);
        Database::insert(
            "INSERT INTO inventory_movements (product_id,warehouse_id,user_id,type,qty_change,qty_before,qty_after,reference_id,reference_type,notes,created_at) VALUES (?,?,?,'return',?,?,?,?,'sale','Voided sale',NOW())",
            [$it['product_id'], $sale['warehouse_id'], Auth::id(), $qtyBase, $before, $after, $id]
        );
    }
    Database::exec("UPDATE sales SET status='voided' WHERE id=?", [$id]);
    if (!empty($sale['shift_id'])) {
        Database::exec(
            "UPDATE shifts
             SET total_sales = GREATEST(total_sales - ?, 0),
                 transaction_count = GREATEST(transaction_count - 1, 0)
             WHERE id=?",
            [(float)$sale['total'], (int)$sale['shift_id']]
        );
    }
    if ((int)$sale['customer_id'] > 1) {
        Database::exec(
            "UPDATE customers
             SET total_spent = GREATEST(total_spent - ?, 0),
                 visits = GREATEST(visits - 1, 0)
             WHERE id=?",
            [(float)$sale['total'], (int)$sale['customer_id']]
        );
    }
    Database::commit();
    flash_success(_r('sales_status_voided'));
} catch (Throwable $e) {
    Database::rollback();
    flash_error($e->getMessage());
}
redirect('/modules/sales/');
