<?php
require_once __DIR__ . '/../../core/bootstrap.php';
Auth::requireLogin();
Auth::requirePerm('customers.delete');
if (!is_post() || !csrf_verify()) {
    flash_error(_r('err_csrf'));
    redirect('/modules/customers/');
}
$id = (int)($_POST['id'] ?? 0);
if ($id <= 1) { flash_error(__('cust_default_protected')); redirect('/modules/customers/'); }
$inUse = Database::value("SELECT COUNT(*) FROM sales WHERE customer_id=?", [$id]);
if ($inUse) { flash_error(_r('err_delete_in_use')); }
else { Database::exec("DELETE FROM customers WHERE id=?", [$id]); flash_success(_r('prod_deleted')); }
redirect('/modules/customers/');
