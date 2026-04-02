<?php
/**
 * Goods Receipt — Save Handler
 * modules/receipts/save.php
 *
 * NEW FLOW:
 *   save_draft      → status = 'draft'              (no stock change)
 *   save_and_post   → status = 'pending_acceptance' (no stock change)
 *                     Stock only moves at acceptance step (modules/acceptance/)
 */
require_once __DIR__ . '/../../core/bootstrap.php';
Auth::requireLogin();
Auth::requirePerm('inventory');

if (!is_post()) { redirect('/modules/receipts/'); }
if (!csrf_verify()) { flash_error(_r('err_csrf')); redirect('/modules/receipts/'); }

$id     = (int)($_POST['id'] ?? 0);
$action = sanitize($_POST['action'] ?? 'save_draft');
$isEdit = $id > 0;

// ── Validate header ─────────────────────────────────────────────
$docNo         = sanitize($_POST['doc_no']        ?? '');
$docDate       = sanitize($_POST['doc_date']       ?? '');
$supplierId    = (int)($_POST['supplier_id']        ?? 0) ?: null;
$warehouseId   = (int)($_POST['warehouse_id']       ?? 0);
$acceptedBy    = sanitize($_POST['accepted_by']     ?? '');
$deliveredBy   = sanitize($_POST['delivered_by']    ?? '');
$supplierDocNo = sanitize($_POST['supplier_doc_no'] ?? '');
$notes         = sanitize($_POST['notes']           ?? '');

$existingDoc = null;
if ($isEdit) {
    $existingDoc = Database::row("SELECT id, warehouse_id, status FROM goods_receipts WHERE id=?", [$id]);
    if (!$existingDoc) {
        flash_error(_r('err_not_found'));
        redirect('/modules/receipts/');
    }
    require_warehouse_access((int)$existingDoc['warehouse_id'], '/modules/receipts/');
}

$errors = [];
if ($docNo === '')   $errors[] = _r('gr_err_no_doc_no');
if ($docDate === '')  $errors[] = _r('gr_err_no_date');
if ($warehouseId < 1) $errors[] = _r('gr_err_no_warehouse');
if ($warehouseId > 0 && !user_can_access_warehouse($warehouseId)) $errors[] = _r('auth_no_permission');

if ($docNo !== '') {
    $dup = Database::value("SELECT id FROM goods_receipts WHERE doc_no=? AND id!=?", [$docNo, $id]);
    if ($dup) $errors[] = _r('gr_err_doc_no_exists');
}

// ── Parse items ─────────────────────────────────────────────────
$rawItems   = $_POST['items'] ?? [];
$cleanItems = [];
$subtotal   = 0.0;
$taxTotal   = 0.0;

foreach ($rawItems as $row) {
    $productId = (int)($row['product_id'] ?? 0) ?: null;
    $name      = sanitize($row['name']        ?? '');
    $unit      = sanitize($row['unit']        ?? 'pcs');
    $qty       = sanitize_float($row['qty']       ?? 0);
    $price     = sanitize_float($row['unit_price'] ?? 0);
    $taxRate   = min(100, max(0, sanitize_float($row['tax_rate'] ?? 0)));
    $lineNotes = sanitize($row['notes'] ?? '');
    $rowId     = (int)($row['id'] ?? 0);
    $unitPricesJson = trim((string)($row['unit_prices_json'] ?? ''));
    $salePricesJson = trim((string)($row['sale_prices_json'] ?? ''));

    if ($productId && $name === '') {
        $p = Database::row("SELECT name_en, name_ru FROM products WHERE id=?", [$productId]);
        if ($p) $name = product_name($p);
    }
    if ($name === '' && !$productId) continue;
    if ($qty <= 0) { $errors[] = _r('gr_err_qty_zero', [':name' => $name]); continue; }

    $lineTotal  = round($qty * $price, 2);
    $lineTax    = round($lineTotal * $taxRate / 100, 2);
    $subtotal  += $lineTotal;
    $taxTotal  += $lineTax;

    $cleanItems[] = [
        'id'         => $rowId,
        'product_id' => $productId,
        'name'       => $name,
        'unit'       => $unit,
        'qty'        => $qty,
        'unit_price' => $price,
        'tax_rate'   => $taxRate,
        'line_total' => $lineTotal,
        'notes'      => $lineNotes,
        'unit_prices_json' => $unitPricesJson,
        'sale_prices_json' => $salePricesJson,
    ];
}

if (empty($cleanItems)) $errors[] = _r('gr_err_no_items');

if ($errors) {
    foreach ($errors as $e) flash_error($e);
    redirect($isEdit ? '/modules/receipts/edit.php?id='.$id : '/modules/receipts/edit.php');
}

$total     = round($subtotal + $taxTotal, 2);
$newStatus = $action === 'save_and_post' ? 'pending_acceptance' : 'draft';

// ── DB transaction ──────────────────────────────────────────────
try {
    Database::beginTransaction();

    if ($isEdit) {
        $curStatus = (string)($existingDoc['status'] ?? '');
        if ($curStatus !== 'draft') {
            Database::rollback();
            flash_error(_r('gr_err_not_draft'));
            redirect('/modules/receipts/view.php?id='.$id);
        }
        Database::exec(
            "UPDATE goods_receipts SET
               doc_no=?, doc_date=?, supplier_id=?, warehouse_id=?,
               accepted_by=?, delivered_by=?, supplier_doc_no=?,
               notes=?, subtotal=?, tax_amount=?, total=?,
               updated_at=NOW()
             WHERE id=?",
            [$docNo, $docDate, $supplierId, $warehouseId,
             $acceptedBy, $deliveredBy, $supplierDocNo,
             $notes, $subtotal, $taxTotal, $total, $id]
        );
        Database::exec("DELETE FROM goods_receipt_items WHERE receipt_id=?", [$id]);
    } else {
        $id = Database::insert(
            "INSERT INTO goods_receipts
               (doc_no, doc_date, supplier_id, warehouse_id, accepted_by, delivered_by,
                supplier_doc_no, notes, subtotal, tax_amount, total, status, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)",
            [$docNo, $docDate, $supplierId, $warehouseId,
             $acceptedBy, $deliveredBy, $supplierDocNo,
             $notes, $subtotal, $taxTotal, $total, 'draft', Auth::id()]
        );
    }

    // Insert items (sale_price defaults to 0 — set at acceptance)
    foreach ($cleanItems as $sort => $item) {
        Database::exec(
            "INSERT INTO goods_receipt_items
               (receipt_id, product_id, name, unit, qty, unit_price, unit_prices_json, sale_price, sale_prices_json,
                tax_rate, line_total, notes, sort_order)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)",
            [$id, $item['product_id'], $item['name'], $item['unit'],
             $item['qty'], $item['unit_price'], $item['unit_prices_json'], 0, $item['sale_prices_json'], $item['tax_rate'],
             $item['line_total'], $item['notes'], $sort]
        );
    }

    // If posting: move to pending_acceptance queue (NO stock change here)
    if ($newStatus === 'pending_acceptance') {
        Database::exec(
            "UPDATE goods_receipts
             SET status='pending_acceptance', posted_by=?, posted_at=NOW(), updated_at=NOW()
             WHERE id=?",
            [Auth::id(), $id]
        );
    }

    Database::commit();

    $msg = $newStatus === 'pending_acceptance' ? _r('gr_sent_to_acceptance') : _r('gr_saved_ok');
    flash_success($msg);
    redirect('/modules/receipts/view.php?id='.$id);

} catch (Throwable $e) {
    Database::rollback();
    flash_error(_r('err_db').': '.$e->getMessage());
    redirect($isEdit ? '/modules/receipts/edit.php?id='.$id : '/modules/receipts/edit.php');
}
