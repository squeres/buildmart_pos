<?php
require_once __DIR__ . '/../../core/bootstrap.php';
Auth::requireLogin();
Auth::requirePerm('sales');

if (!is_post() || !csrf_verify()) {
    flash_error(_r('err_csrf'));
    redirect('/modules/sales/');
}

$saleId = (int)($_POST['sale_id'] ?? 0);
$businessEntityId = (int)($_POST['business_entity_id'] ?? 0);
$invoiceNumber = sanitize($_POST['invoice_number'] ?? '');
$invoiceDate = sanitize($_POST['invoice_date'] ?? date('Y-m-d'));
$powerNo = sanitize($_POST['power_of_attorney_no'] ?? '');
$powerDate = sanitize($_POST['power_of_attorney_date'] ?? '');
$transportCompany = sanitize($_POST['transport_company'] ?? '');
$transportWaybillNo = sanitize($_POST['transport_waybill_no'] ?? '');
$transportWaybillDate = sanitize($_POST['transport_waybill_date'] ?? '');
$notes = sanitize($_POST['notes'] ?? '');

if ($saleId <= 0 || $businessEntityId <= 0 || $invoiceNumber === '' || $invoiceDate === '') {
    flash_error(_r('err_validation'));
    redirect('/modules/sales/');
}

$existing = sale_invoice_for_sale($saleId);
if ($existing) {
    flash_info(_r('si_exists'));
    redirect('/modules/sale_invoices/view.php?id=' . $existing['id']);
}

$sale = Database::row(
    "SELECT s.*, c.name AS customer_name, c.company AS customer_company, c.inn AS customer_inn,
            c.address AS customer_address, c.contact_person AS customer_contact_person,
            c.phone AS customer_phone, c.email AS customer_email, c.customer_type,
            w.name AS warehouse_name
     FROM sales s
     LEFT JOIN customers c ON c.id = s.customer_id
     LEFT JOIN warehouses w ON w.id = s.warehouse_id
     WHERE s.id = ?",
    [$saleId]
);
if (!$sale) {
    flash_error(_r('err_not_found'));
    redirect('/modules/sales/');
}
if ($sale['status'] !== 'completed') {
    flash_error(_r('si_sale_not_completed'));
    redirect('/modules/sales/view.php?id=' . $saleId);
}

$entity = Database::row(
    "SELECT * FROM business_entities WHERE id=? AND is_active=1 LIMIT 1",
    [$businessEntityId]
);
if (!$entity) {
    flash_error(_r('si_business_entity_missing'));
    redirect('/modules/sales/view.php?id=' . $saleId);
}

$entitySnapshot = business_entity_snapshot($entity);
$customerType = customer_type_normalize((string)($sale['customer_type'] ?? 'individual'));

$invoiceId = Database::insert(
    "INSERT INTO sale_invoices (
        sale_id, business_entity_id, customer_id, invoice_number, invoice_date,
        power_of_attorney_no, power_of_attorney_date, transport_company,
        transport_waybill_no, transport_waybill_date, notes,
        sender_name_snapshot, sender_legal_name_snapshot, sender_iin_bin_snapshot,
        sender_address_snapshot, sender_phone_snapshot, sender_email_snapshot,
        sender_responsible_name_snapshot, sender_responsible_position_snapshot,
        sender_released_by_snapshot, sender_chief_accountant_snapshot,
        customer_type_snapshot, customer_name_snapshot, customer_company_snapshot,
        customer_iin_bin_snapshot, customer_address_snapshot, customer_contact_person_snapshot,
        customer_phone_snapshot, customer_email_snapshot, created_by
     ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
    [
        $saleId,
        $businessEntityId,
        $sale['customer_id'] ?: null,
        $invoiceNumber,
        $invoiceDate,
        $powerNo !== '' ? $powerNo : null,
        $powerDate !== '' ? $powerDate : null,
        $transportCompany !== '' ? $transportCompany : null,
        $transportWaybillNo !== '' ? $transportWaybillNo : null,
        $transportWaybillDate !== '' ? $transportWaybillDate : null,
        $notes !== '' ? $notes : null,
        $entitySnapshot['name'] !== '' ? $entitySnapshot['name'] : null,
        $entitySnapshot['legal_name'] !== '' ? $entitySnapshot['legal_name'] : null,
        $entitySnapshot['iin_bin'] !== '' ? $entitySnapshot['iin_bin'] : null,
        $entitySnapshot['address'] !== '' ? $entitySnapshot['address'] : null,
        $entitySnapshot['phone'] !== '' ? $entitySnapshot['phone'] : null,
        $entitySnapshot['email'] !== '' ? $entitySnapshot['email'] : null,
        $entitySnapshot['responsible_name'] !== '' ? $entitySnapshot['responsible_name'] : null,
        $entitySnapshot['responsible_position'] !== '' ? $entitySnapshot['responsible_position'] : null,
        $entitySnapshot['released_by_name'] !== '' ? $entitySnapshot['released_by_name'] : null,
        $entitySnapshot['chief_accountant_name'] !== '' ? $entitySnapshot['chief_accountant_name'] : null,
        $customerType,
        $sale['customer_name'] !== '' ? $sale['customer_name'] : null,
        $sale['customer_company'] !== '' ? $sale['customer_company'] : null,
        $sale['customer_inn'] !== '' ? $sale['customer_inn'] : null,
        $sale['customer_address'] !== '' ? $sale['customer_address'] : null,
        $sale['customer_contact_person'] !== '' ? $sale['customer_contact_person'] : null,
        $sale['customer_phone'] !== '' ? $sale['customer_phone'] : null,
        $sale['customer_email'] !== '' ? $sale['customer_email'] : null,
        Auth::id(),
    ]
);

flash_success(_r('si_created'));
redirect('/modules/sale_invoices/view.php?id=' . $invoiceId);