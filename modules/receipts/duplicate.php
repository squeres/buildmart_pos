<?php
/**
 * Goods Receipt — Duplicate Handler
 * modules/receipts/duplicate.php
 *
 * Creates a new DRAFT document with the same header and items
 * as the source document. Assigns a new document number and today's date.
 */
require_once __DIR__ . '/../../core/bootstrap.php';
Auth::requireLogin();
Auth::requirePerm('receipts');

$srcId = (int)($_GET['id'] ?? 0);
if (!$srcId) { redirect('/modules/receipts/'); }

$src = Database::row("SELECT * FROM goods_receipts WHERE id=?", [$srcId]);
if (!$src) { flash_error(_r('err_not_found')); redirect('/modules/receipts/'); }
require_warehouse_access((int)$src['warehouse_id'], '/modules/receipts/');

$srcItems = Database::all(
    "SELECT * FROM goods_receipt_items WHERE receipt_id=? ORDER BY sort_order, id",
    [$srcId]
);

try {
    Database::beginTransaction();

    $newId = Database::insert(
        "INSERT INTO goods_receipts
           (doc_no, doc_date, supplier_id, warehouse_id, accepted_by, delivered_by,
            supplier_doc_no, notes, subtotal, tax_amount, total, status, created_by)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)",
        [
            gr_next_doc_no(),
            date('Y-m-d'),
            $src['supplier_id'],
            $src['warehouse_id'],
            $src['accepted_by'],
            $src['delivered_by'],
            '', // new blank supplier doc no
            $src['notes'],
            $src['subtotal'],
            $src['tax_amount'],
            $src['total'],
            'draft',
            Auth::id(),
        ]
    );

    foreach ($srcItems as $sort => $item) {
        Database::exec(
            "INSERT INTO goods_receipt_items
               (receipt_id, product_id, name, unit, qty, unit_price, tax_rate, line_total, notes, sort_order)
             VALUES (?,?,?,?,?,?,?,?,?,?)",
            [
                $newId, $item['product_id'], $item['name'], $item['unit'],
                $item['qty'], $item['unit_price'], $item['tax_rate'],
                $item['line_total'], $item['notes'], $sort,
            ]
        );
    }

    Database::commit();
    flash_success(_r('gr_duplicated_ok'));
    redirect('/modules/receipts/edit.php?id=' . $newId);

} catch (Throwable $e) {
    Database::rollback();
    flash_error(_r('err_db') . ': ' . $e->getMessage());
    redirect('/modules/receipts/');
}
