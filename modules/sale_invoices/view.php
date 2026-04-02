<?php
require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../views/partials/icons.php';
Auth::requireLogin();
Auth::requirePerm('sales');

$id = (int)($_GET['id'] ?? 0);
$invoice = Database::row(
    "SELECT si.*, s.receipt_no, s.created_at AS sale_created_at, s.subtotal, s.discount_amount,
            s.tax_amount, s.total, s.status AS sale_status,
            w.name AS warehouse_name,
            u.name AS created_by_name
     FROM sale_invoices si
     JOIN sales s ON s.id = si.sale_id
     LEFT JOIN warehouses w ON w.id = s.warehouse_id
     LEFT JOIN users u ON u.id = si.created_by
     WHERE si.id = ?",
    [$id]
);
if (!$invoice) {
    flash_error(_r('err_not_found'));
    redirect('/modules/sales/');
}

$items = Database::all("SELECT * FROM sale_items WHERE sale_id=? ORDER BY id", [$invoice['sale_id']]);
$urls = sale_invoice_urls((int)$invoice['id']);
$pageTitle = __('doc_delivery_note') . ': ' . $invoice['invoice_number'];
$breadcrumbs = [[__('nav_sales'), url('modules/sales/')], [__('doc_delivery_note'), null]];

$senderName = trim((string)($invoice['sender_legal_name_snapshot'] ?: $invoice['sender_name_snapshot']));
$recipientName = trim((string)($invoice['customer_company_snapshot'] ?: $invoice['customer_name_snapshot']));
$recipientContact = trim((string)($invoice['customer_contact_person_snapshot'] ?: $invoice['customer_name_snapshot']));

include __DIR__ . '/../../views/layouts/header.php';
?>

<div class="page-header">
  <div>
    <h1 class="page-heading"><?= e($invoice['invoice_number']) ?></h1>
    <div style="display:flex;align-items:center;gap:10px;margin-top:4px">
      <span class="badge badge-secondary"><?= __('doc_delivery_note') ?></span>
      <span class="text-muted" style="font-size:13px"><?= date_fmt((string)$invoice['invoice_date'], 'd.m.Y') ?></span>
    </div>
  </div>
  <div class="page-actions">
    <a href="<?= e($urls['print_url']) ?>" target="_blank" class="btn btn-secondary">
      <?= feather_icon('printer', 15) ?> <?= __('btn_print') ?>
    </a>
    <a href="<?= e($urls['excel_url']) ?>" class="btn btn-ghost">
      <?= feather_icon('download', 15) ?> Excel
    </a>
    <a href="<?= url('modules/sales/view.php?id=' . (int)$invoice['sale_id']) ?>" class="btn btn-ghost">
      <?= feather_icon('arrow-left', 15) ?> <?= __('btn_back') ?>
    </a>
  </div>
</div>

<div class="grid grid-2 mb-3">
  <div class="card">
    <div class="card-header"><span class="card-title"><?= __('gr_header') ?></span></div>
    <div class="card-body" style="font-size:13.5px">
      <table style="width:100%;border-collapse:collapse">
        <tr><td style="padding:4px 0;color:var(--text-muted);width:44%"><?= __('invoice_doc_number') ?></td><td class="font-mono fw-600"><?= e($invoice['invoice_number']) ?></td></tr>
        <tr><td style="padding:4px 0;color:var(--text-muted)"><?= __('invoice_doc_date') ?></td><td><?= date_fmt((string)$invoice['invoice_date'], 'd.m.Y') ?></td></tr>
        <tr><td style="padding:4px 0;color:var(--text-muted)"><?= __('pos_receipt_no') ?></td><td class="font-mono"><?= e($invoice['receipt_no']) ?></td></tr>
        <tr><td style="padding:4px 0;color:var(--text-muted)"><?= __('col_warehouse') ?></td><td><?= e($invoice['warehouse_name'] ?: '—') ?></td></tr>
        <tr><td style="padding:4px 0;color:var(--text-muted)"><?= __('invoice_by_power') ?></td><td><?= e($invoice['power_of_attorney_no'] ?: '—') ?></td></tr>
        <tr><td style="padding:4px 0;color:var(--text-muted)"><?= __('invoice_from_date') ?></td><td><?= $invoice['power_of_attorney_date'] ? date_fmt((string)$invoice['power_of_attorney_date'], 'd.m.Y') : '—' ?></td></tr>
        <tr><td style="padding:4px 0;color:var(--text-muted)"><?= __('invoice_transport') ?></td><td><?= e($invoice['transport_company'] ?: '—') ?></td></tr>
        <tr><td style="padding:4px 0;color:var(--text-muted)"><?= __('invoice_transport_doc') ?></td><td><?= e($invoice['transport_waybill_no'] ?: '—') ?></td></tr>
      </table>
    </div>
  </div>
  <div class="card">
    <div class="card-header"><span class="card-title"><?= __('lbl_status') ?></span></div>
    <div class="card-body" style="font-size:13.5px">
      <table style="width:100%;border-collapse:collapse">
        <tr><td style="padding:4px 0;color:var(--text-muted);width:44%"><?= __('lbl_created') ?></td><td><?= date_fmt((string)$invoice['created_at']) ?> · <?= e($invoice['created_by_name'] ?: '—') ?></td></tr>
        <tr><td style="padding:4px 0;color:var(--text-muted)"><?= __('lbl_total') ?></td><td class="fw-600"><?= money((float)$invoice['total']) ?></td></tr>
        <tr><td style="padding:4px 0;color:var(--text-muted)"><?= __('lbl_tax') ?></td><td><?= money((float)$invoice['tax_amount']) ?></td></tr>
        <tr><td style="padding:4px 0;color:var(--text-muted)"><?= __('si_sender') ?></td><td><?= e($senderName ?: '—') ?></td></tr>
        <tr><td style="padding:4px 0;color:var(--text-muted)"><?= __('si_recipient') ?></td><td><?= e($recipientName ?: '—') ?></td></tr>
        <tr><td style="padding:4px 0;color:var(--text-muted)"><?= __('cust_contact_person') ?></td><td><?= e($recipientContact ?: '—') ?></td></tr>
      </table>
    </div>
  </div>
</div>

<div class="grid grid-2 mb-3">
  <div class="card">
    <div class="card-header"><span class="card-title"><?= __('invoice_sender') ?></span></div>
    <div class="card-body" style="font-size:13px;line-height:1.6">
      <div class="fw-600"><?= e($senderName ?: '—') ?></div>
      <div><?= __('cust_inn') ?>: <?= e($invoice['sender_iin_bin_snapshot'] ?: '—') ?></div>
      <div><?= __('lbl_address') ?>: <?= e($invoice['sender_address_snapshot'] ?: '—') ?></div>
      <div><?= __('be_responsible_name') ?>: <?= e($invoice['sender_responsible_name_snapshot'] ?: '—') ?></div>
      <div><?= __('be_released_by_name') ?>: <?= e($invoice['sender_released_by_snapshot'] ?: '—') ?></div>
      <div><?= __('be_chief_accountant_name') ?>: <?= e($invoice['sender_chief_accountant_snapshot'] ?: '—') ?></div>
    </div>
  </div>
  <div class="card">
    <div class="card-header"><span class="card-title"><?= __('invoice_recipient') ?></span></div>
    <div class="card-body" style="font-size:13px;line-height:1.6">
      <div class="fw-600"><?= e($recipientName ?: '—') ?></div>
      <div><?= __('cust_contact_person') ?>: <?= e($recipientContact ?: '—') ?></div>
      <div><?= __('cust_inn') ?>: <?= e($invoice['customer_iin_bin_snapshot'] ?: '—') ?></div>
      <div><?= __('lbl_address') ?>: <?= e($invoice['customer_address_snapshot'] ?: '—') ?></div>
      <div><?= __('lbl_phone') ?>: <?= e($invoice['customer_phone_snapshot'] ?: '—') ?></div>
      <div><?= __('lbl_email') ?>: <?= e($invoice['customer_email_snapshot'] ?: '—') ?></div>
    </div>
  </div>
</div>

<div class="card mb-3">
  <div class="card-header"><span class="card-title"><?= __('lbl_items') ?></span></div>
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th style="width:36px">#</th>
          <th><?= __('lbl_name') ?></th>
          <th><?= __('lbl_sku') ?></th>
          <th><?= __('lbl_unit') ?></th>
          <th class="col-num"><?= __('lbl_qty') ?></th>
          <th class="col-num"><?= __('gr_unit_price') ?></th>
          <th class="col-num"><?= __('invoice_total_with_tax') ?></th>
          <th class="col-num"><?= __('invoice_nds_amount') ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($items as $index => $item): ?>
          <tr>
            <td class="text-center"><?= $index + 1 ?></td>
            <td class="fw-600"><?= e($item['product_name']) ?></td>
            <td class="font-mono"><?= e($item['product_sku']) ?></td>
            <td><?= e(unit_label((string)$item['unit'])) ?></td>
            <td class="col-num"><?= fmtQty((float)$item['qty']) ?></td>
            <td class="col-num"><?= money((float)$item['unit_price']) ?></td>
            <td class="col-num fw-600"><?= money((float)$item['line_total']) ?></td>
            <td class="col-num"><?= money((float)$item['tax_amount']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="6" class="text-right text-muted"><?= __('gr_subtotal') ?>:</td>
          <td class="col-num fw-600"><?= money((float)$invoice['subtotal']) ?></td>
          <td></td>
        </tr>
        <tr>
          <td colspan="6" class="text-right text-muted"><?= __('lbl_tax') ?>:</td>
          <td class="col-num fw-600"><?= money((float)$invoice['tax_amount']) ?></td>
          <td></td>
        </tr>
        <tr>
          <td colspan="6" class="text-right fw-600"><?= __('lbl_total') ?>:</td>
          <td class="col-num fw-600"><?= money((float)$invoice['total']) ?></td>
          <td></td>
        </tr>
      </tfoot>
    </table>
  </div>
</div>

<?php if (!empty($invoice['notes'])): ?>
  <div class="card">
    <div class="card-header"><span class="card-title"><?= __('lbl_notes') ?></span></div>
    <div class="card-body" style="font-size:13px;line-height:1.6"><?= nl2br(e($invoice['notes'])) ?></div>
  </div>
<?php endif; ?>

<?php include __DIR__ . '/../../views/layouts/footer.php'; ?>
<script src="https://unpkg.com/feather-icons/dist/feather.min.js"></script>
<script>feather.replace();</script>