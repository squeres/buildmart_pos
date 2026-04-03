<?php
require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../views/partials/icons.php';
Auth::requireLogin();
Auth::requirePerm('sales');

$id = (int)($_GET['id'] ?? 0);
$sale = Database::row(
    "SELECT s.*, u.name AS cashier,
            c.name AS customer_name, c.phone AS customer_phone, c.email AS customer_email,
            c.company AS customer_company, c.inn AS customer_inn, c.address AS customer_address,
            c.contact_person AS customer_contact_person, c.customer_type,
            w.name AS warehouse_name
     FROM sales s
     JOIN users u ON u.id = s.user_id
     LEFT JOIN customers c ON c.id = s.customer_id
     LEFT JOIN warehouses w ON w.id = s.warehouse_id
     WHERE s.id = ?",
    [$id]
);

if (!$sale) {
    flash_error(_r('err_not_found'));
    redirect('/modules/sales/');
}
require_warehouse_access((int)$sale['warehouse_id'], '/modules/sales/');

$items = Database::all("SELECT * FROM sale_items WHERE sale_id = ?", [$id]);
$payments = Database::all("SELECT * FROM payments WHERE sale_id = ?", [$id]);
$customerSnapshot = sale_customer_snapshot($sale, [
    'name' => $sale['customer_name'] ?? '',
    'phone' => $sale['customer_phone'] ?? '',
    'email' => $sale['customer_email'] ?? '',
    'company' => $sale['customer_company'] ?? '',
    'inn' => $sale['customer_inn'] ?? '',
    'address' => $sale['customer_address'] ?? '',
    'customer_type' => $sale['customer_type'] ?? 'individual',
]);
$saleInvoice = sale_invoice_for_sale($id);
$invoiceUrls = $saleInvoice ? sale_invoice_urls((int)$saleInvoice['id']) : null;
$canVoidSale = Auth::can('sales.void');
$canCreateInvoice = Auth::can('sales.invoice');
$saleStatusKey = 'sales_status_' . $sale['status'];
$saleStatusLabel = __($saleStatusKey);
if ($saleStatusLabel === $saleStatusKey) {
    $saleStatusLabel = $sale['status'];
}

$activeBusinessEntities = [];
try {
    $activeBusinessEntities = Database::all(
        "SELECT id, name FROM business_entities WHERE is_active=1 ORDER BY name, id"
    );
} catch (Throwable $e) {
    $activeBusinessEntities = [];
}

$pageTitle = __('pos_receipt_no') . $sale['receipt_no'];
$breadcrumbs = [[__('nav_sales'), url('modules/sales/')], [$pageTitle, null]];

include __DIR__ . '/../../views/layouts/header.php';
?>

<div class="page-header">
  <div>
    <h1 class="page-heading font-mono"><?= e($sale['receipt_no']) ?></h1>
    <div style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap">
      <span class="badge badge-secondary"><?= e(customer_type_label($customerSnapshot['type'])) ?></span>
      <?php if (!empty($sale['warehouse_name'])): ?>
        <span class="badge badge-secondary"><?= e($sale['warehouse_name']) ?></span>
      <?php endif; ?>
      <?php if ($saleInvoice): ?>
        <span class="badge badge-warning"><?= __('doc_delivery_note') ?>: <?= e($saleInvoice['invoice_number']) ?></span>
      <?php endif; ?>
    </div>
  </div>
  <div class="page-actions">
    <a href="<?= url('modules/pos/receipt.php?id=' . $id) ?>" onclick="return openReceiptWindow(this.href)" class="btn btn-secondary">
      <?= feather_icon('printer', 15) ?> <?= __('btn_print') ?>
    </a>

    <?php if ($invoiceUrls): ?>
      <a href="<?= e($invoiceUrls['view_url']) ?>" class="btn btn-secondary">
        <?= feather_icon('file-text', 15) ?> <?= __('sales_open_invoice') ?>
      </a>
      <a href="<?= e($invoiceUrls['print_url']) ?>" target="_blank" class="btn btn-ghost">
        <?= feather_icon('printer', 15) ?> <?= __('btn_print') ?> <?= __('doc_delivery_note') ?>
      </a>
      <a href="<?= e($invoiceUrls['excel_url']) ?>" class="btn btn-ghost">
        <?= feather_icon('download', 15) ?> Excel
      </a>
    <?php elseif ($sale['status'] === 'completed' && $canCreateInvoice): ?>
      <button type="button"
              class="btn btn-secondary"
              data-open-sale-invoice-modal
              data-sale-id="<?= (int)$sale['id'] ?>"
              data-sale-receipt="<?= e($sale['receipt_no']) ?>"
              data-sale-customer="<?= e($customerSnapshot['display_name']) ?>"
              data-sale-date-label="<?= e(date_fmt((string)$sale['created_at'])) ?>"
              data-invoice-number="<?= e(preg_replace('/[^A-Za-z0-9\-\/]/', '', (string)$sale['receipt_no']) ?: ('INV-' . $sale['id'])) ?>"
              data-invoice-date="<?= e(substr((string)$sale['created_at'], 0, 10)) ?>"
      >
        <?= feather_icon('file-plus', 15) ?> <?= __('sales_create_invoice') ?>
      </button>
    <?php endif; ?>

    <?php if ($sale['status'] === 'completed' && $canVoidSale): ?>
      <form method="POST" action="<?= url('modules/sales/void.php') ?>" style="display:inline">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int)$id ?>">
        <button type="submit" class="btn btn-danger" data-confirm="<?= e(__('confirm_void')) ?>">
          <?= feather_icon('x-circle', 15) ?> <?= __('sales_void_sale') ?>
        </button>
      </form>
    <?php endif; ?>
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 340px;gap:16px">
  <div style="display:flex;flex-direction:column;gap:12px">
    <div class="card">
      <div class="card-header"><span class="card-title"><?= __('lbl_items') ?></span></div>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th><?= __('lbl_name') ?></th>
              <th><?= __('lbl_sku') ?></th>
              <th class="col-num"><?= __('lbl_qty') ?></th>
              <th class="col-num"><?= __('lbl_price') ?></th>
              <th class="col-num"><?= __('lbl_discount') ?></th>
              <th class="col-num"><?= __('lbl_tax') ?></th>
              <th class="col-num"><?= __('lbl_total') ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($items as $item): ?>
              <tr>
                <td class="fw-600"><?= e($item['product_name']) ?></td>
                <td class="font-mono" style="font-size:12px"><?= e($item['product_sku']) ?></td>
                <td class="col-num"><?= fmtQty((float)$item['qty']) ?> <?= e(unit_label((string)$item['unit'])) ?></td>
                <td class="col-num"><?= money((float)$item['unit_price']) ?></td>
                <td class="col-num"><?= (float)$item['discount_amount'] > 0 ? '−' . money((float)$item['discount_amount']) : '—' ?></td>
                <td class="col-num"><?= (float)$item['tax_amount'] > 0 ? money((float)$item['tax_amount']) : '—' ?></td>
                <td class="col-num fw-600"><?= money((float)$item['line_total']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php if ($saleInvoice): ?>
      <div class="card">
        <div class="card-header"><span class="card-title"><?= __('doc_delivery_note') ?></span></div>
        <div class="card-body" style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;font-size:13px">
          <div>
            <div class="text-muted"><?= __('invoice_doc_number') ?></div>
            <div class="fw-600 font-mono"><?= e($saleInvoice['invoice_number']) ?></div>
          </div>
          <div>
            <div class="text-muted"><?= __('invoice_doc_date') ?></div>
            <div class="fw-600"><?= date_fmt((string)$saleInvoice['invoice_date'], 'd.m.Y') ?></div>
          </div>
          <div>
            <div class="text-muted"><?= __('si_business_entity') ?></div>
            <div class="fw-600"><?= e($saleInvoice['business_entity_name'] ?: '—') ?></div>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <div style="display:flex;flex-direction:column;gap:12px">
    <div class="card">
      <div class="card-header"><span class="card-title"><?= __('lbl_summary') ?></span></div>
      <div class="card-body">
        <?php
        $rows = [
            [__('lbl_subtotal'), money((float)$sale['subtotal'])],
            [__('lbl_discount'), (float)$sale['discount_amount'] > 0 ? '−' . money((float)$sale['discount_amount']) : '—'],
            [__('lbl_tax'), money((float)$sale['tax_amount'])],
        ];
        ?>
        <?php foreach ($rows as [$label, $value]): ?>
          <div class="flex-between mb-1" style="font-size:13px">
            <span class="text-secondary"><?= e($label) ?></span>
            <span class="font-mono"><?= $value ?></span>
          </div>
        <?php endforeach; ?>
        <div class="flex-between mt-2" style="font-size:20px;font-weight:700;border-top:1px solid var(--border-dim);padding-top:10px">
          <span><?= __('lbl_total') ?></span>
          <span class="font-mono text-amber"><?= money((float)$sale['total']) ?></span>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><span class="card-title"><?= __('pos_payment') ?></span></div>
      <div class="card-body">
        <?php foreach ($payments as $payment): ?>
          <div class="flex-between mb-1" style="font-size:13px">
            <span class="text-secondary"><?= __('pos_pay_' . $payment['method']) ?></span>
            <span class="font-mono fw-600"><?= money((float)$payment['amount']) ?></span>
          </div>
          <?php if ((float)$payment['change_given'] > 0): ?>
            <div class="flex-between" style="font-size:13px">
              <span class="text-secondary"><?= __('pos_change') ?></span>
              <span class="font-mono"><?= money((float)$payment['change_given']) ?></span>
            </div>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><span class="card-title"><?= __('lbl_details') ?></span></div>
      <div class="card-body">
        <?php
        $details = [
            [__('pos_receipt_no'), $sale['receipt_no']],
            [__('lbl_date'), date_fmt((string)$sale['created_at'])],
            [__('shift_cashier'), $sale['cashier']],
            [__('cust_title'), $customerSnapshot['display_name']],
            [__('cust_type'), customer_type_label($customerSnapshot['type'])],
            [__('cust_contact_person'), $sale['customer_contact_person'] ?: '—'],
            [__('cust_inn'), $customerSnapshot['iin_bin'] ?: '—'],
            [__('col_warehouse'), $sale['warehouse_name'] ?: '—'],
            [__('lbl_status'), $saleStatusLabel],
        ];
        ?>
        <?php foreach ($details as [$label, $value]): ?>
          <div class="flex-between mb-1" style="font-size:13px">
            <span class="text-muted"><?= e($label) ?></span>
            <span class="fw-600 font-mono"><?= e((string)$value) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../../modules/sale_invoices/_create_modal.php'; ?>
<script>
window.openReceiptWindow = window.openReceiptWindow || function(url) {
  const width = 420;
  const height = 760;
  const left = Math.max(0, Math.round((window.screen.width - width) / 2));
  const top = Math.max(0, Math.round((window.screen.height - height) / 2));
  const features = 'width=' + width + ',height=' + height + ',left=' + left + ',top=' + top + ',resizable=yes,scrollbars=yes';
  const popup = window.open(url, 'buildmart_receipt_history', features);
  if (popup) { popup.focus(); }
  return false;
};
</script>
<?php include __DIR__ . '/../../views/layouts/footer.php'; ?>
<script src="https://unpkg.com/feather-icons/dist/feather.min.js"></script>
<script>feather.replace();</script>
