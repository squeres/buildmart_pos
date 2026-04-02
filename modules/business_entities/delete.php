<?php
require_once __DIR__ . '/../../core/bootstrap.php';
Auth::requireLogin();
Auth::requirePerm('settings');

if (!is_post() || !csrf_verify()) {
    flash_error(_r('err_csrf'));
    redirect('/modules/business_entities/');
}

$id = (int)($_POST['id'] ?? 0);
$entity = Database::row("SELECT * FROM business_entities WHERE id=?", [$id]);
if (!$entity) {
    flash_error(_r('err_not_found'));
    redirect('/modules/business_entities/');
}

$inUse = (int)Database::value("SELECT COUNT(*) FROM sale_invoices WHERE business_entity_id=?", [$id]);
if ($inUse > 0) {
    Database::exec("UPDATE business_entities SET is_active=0, updated_at=NOW() WHERE id=?", [$id]);
    flash_success(_r('be_deactivated'));
} else {
    Database::exec("DELETE FROM business_entities WHERE id=?", [$id]);
    flash_success(_r('be_deleted'));
}

redirect('/modules/business_entities/');