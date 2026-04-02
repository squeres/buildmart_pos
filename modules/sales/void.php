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
    SaleService::voidSale($id, Auth::id());
    flash_success(_r('sales_status_voided'));
} catch (AppServiceException $e) {
    flash_error($e->getMessage());
} catch (Throwable $e) {
    error_log($e->__toString());
    flash_error(_r('err_db'));
}
redirect('/modules/sales/');
