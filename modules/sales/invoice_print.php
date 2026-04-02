<?php
require_once __DIR__ . '/../../core/bootstrap.php';
Auth::requireLogin();
Auth::requirePerm('sales');

$saleId = (int)($_GET['id'] ?? 0);
if ($saleId <= 0) {
    redirect('/modules/sales/');
}

$sale = Database::row("SELECT warehouse_id FROM sales WHERE id=?", [$saleId]);
if (!$sale) {
    flash_error(__('err_not_found'));
    redirect('/modules/sales/');
}
require_warehouse_access((int)$sale['warehouse_id'], '/modules/sales/');

$invoice = sale_invoice_for_sale($saleId);
if ($invoice) {
    redirect('/modules/sale_invoices/print.php?id=' . (int)$invoice['id']);
}

flash_error(__('err_not_found'));
redirect('/modules/sales/view.php?id=' . $saleId);
