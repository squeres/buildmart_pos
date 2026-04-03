<?php
/**
 * Goods Receipt — Post to Acceptance Queue
 * modules/receipts/post.php
 *
 * Moves a draft document to 'pending_acceptance'.
 * Stock is NOT touched here — that happens in modules/acceptance/accept.php
 */
require_once __DIR__ . '/../../core/bootstrap.php';
Auth::requireLogin();
Auth::requirePerm('receipts');

if (!is_post() || !csrf_verify()) {
    flash_error(_r('err_csrf'));
    redirect('/modules/receipts/');
}

$id = (int)($_POST['id'] ?? 0);
if (!$id) { redirect('/modules/receipts/'); }

$doc = Database::row("SELECT * FROM goods_receipts WHERE id=?", [$id]);
if (!$doc) { flash_error(_r('err_not_found')); redirect('/modules/receipts/'); }
require_warehouse_access((int)$doc['warehouse_id'], '/modules/receipts/');

if ($doc['status'] !== 'draft') {
    flash_error(_r('gr_err_not_draft'));
    redirect('/modules/receipts/view.php?id='.$id);
}

$itemCount = (int)Database::value("SELECT COUNT(*) FROM goods_receipt_items WHERE receipt_id=?", [$id]);
if ($itemCount === 0) {
    flash_error(_r('gr_err_no_items'));
    redirect('/modules/receipts/edit.php?id='.$id);
}

Database::exec(
    "UPDATE goods_receipts
     SET status='pending_acceptance', posted_by=?, posted_at=NOW(), updated_at=NOW()
     WHERE id=? AND status='draft'",
    [Auth::id(), $id]
);

flash_success(_r('gr_sent_to_acceptance'));
redirect('/modules/receipts/view.php?id='.$id);
