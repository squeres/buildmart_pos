<?php
/**
 * Goods Receipt - Print-Friendly Page
 * modules/receipts/print.php
 *
 * Standalone page with print CSS. No app layout header/footer.
 * Opens in a new tab. Uses document template settings from `settings` table.
 */
require_once __DIR__ . '/../../core/bootstrap.php';
Auth::requireLogin();
Auth::requirePerm('receipts');

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    redirect('/modules/receipts/');
}

$doc = Database::row(
    "SELECT gr.*,
            s.name AS supplier_name, s.inn AS supplier_inn,
            s.address AS supplier_address,
            w.name AS warehouse_name, w.address AS warehouse_address
     FROM   goods_receipts gr
     LEFT JOIN suppliers  s ON s.id = gr.supplier_id
     LEFT JOIN warehouses w ON w.id = gr.warehouse_id
     WHERE gr.id = ?",
    [$id]
);
if (!$doc) {
    die('Not found');
}
require_warehouse_access((int)$doc['warehouse_id'], '/modules/receipts/');
header('Content-Type: text/html; charset=UTF-8');

$items = Database::all(
    "SELECT * FROM goods_receipt_items WHERE receipt_id=? ORDER BY sort_order, id",
    [$id]
);

$orgName      = setting('gr_org_name', setting('store_name', APP_NAME));
$orgInn       = setting('gr_org_inn', setting('store_inn', ''));
$orgAddress   = setting('gr_org_address', setting('store_address', ''));
$docTitle     = setting('gr_doc_title', _r('gr_doc_title_default'));
$headerNote   = setting('gr_header_note', '');
$footerNote   = setting('gr_footer_note', '');
$lblWarehouse = setting('gr_label_warehouse', __('gr_warehouse'));
$lblSupplier  = setting('gr_label_supplier', __('gr_supplier'));
$lblAccepted  = setting('gr_label_accepted_by', __('gr_accepted_by'));
$lblDelivered = setting('gr_label_delivered_by', __('gr_delivered_by'));

function amount_in_words(float $amount): string
{
    return money($amount) . ' (' . currency_name() . ')';
}
?>
<!DOCTYPE html>
<html lang="<?= Lang::current() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($docTitle) ?> <?= e($doc['doc_no']) ?></title>
<?= app_favicon_links() ?>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }

  body {
    font-family: 'Times New Roman', Times, serif;
    font-size: 11pt;
    color: #000;
    background: #fff;
    padding: 18mm 15mm;
  }

  .org-block {
    font-size: 10pt;
    margin-bottom: 12px;
    border-bottom: 1px solid #ccc;
    padding-bottom: 8px;
  }

  .org-name {
    font-size: 13pt;
    font-weight: bold;
  }

  .org-meta {
    color: #333;
    font-size: 9.5pt;
  }

  .doc-title {
    text-align: center;
    font-size: 15pt;
    font-weight: bold;
    text-transform: uppercase;
    margin: 14px 0 4px;
    letter-spacing: .04em;
  }

  .doc-subtitle {
    text-align: center;
    font-size: 10pt;
    color: #444;
    margin-bottom: 14px;
  }

  .info-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 6px 20px;
    margin-bottom: 12px;
    font-size: 10.5pt;
  }

  .info-row {
    display: flex;
    gap: 6px;
    align-items: baseline;
  }

  .info-label {
    color: #555;
    white-space: nowrap;
    min-width: 80px;
  }

  .info-value {
    font-weight: 600;
  }

  .items-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 10pt;
    margin-bottom: 10px;
  }

  .items-table th {
    border: 1px solid #555;
    padding: 5px 6px;
    text-align: center;
    background: #f0f0f0;
    font-size: 9.5pt;
    font-weight: bold;
  }

  .items-table td {
    border: 1px solid #999;
    padding: 4px 6px;
    vertical-align: top;
  }

  .items-table .num { text-align: right; }
  .items-table .ctr { text-align: center; }
  .items-table tfoot td { font-weight: bold; border-top: 2px solid #555; }

  .amount-words {
    margin-bottom: 14px;
    font-size: 10pt;
  }

  .amount-words span {
    font-weight: bold;
  }

  .footer-note {
    font-size: 9.5pt;
    color: #444;
    border-top: 1px solid #ccc;
    padding-top: 8px;
    margin-bottom: 14px;
  }

  .sig-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 20px;
    font-size: 10.5pt;
  }

  .sig-table td {
    padding: 6px 0;
    vertical-align: bottom;
  }

  .sig-line {
    border-bottom: 1px solid #333;
    min-width: 180px;
    display: inline-block;
    margin: 0 8px;
  }

  .print-controls {
    position: fixed;
    top: 14px;
    right: 14px;
    display: flex;
    gap: 8px;
    z-index: 999;
  }

  .btn-print,
  .btn-close {
    padding: 7px 16px;
    color: #fff;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 13px;
    font-family: sans-serif;
  }

  .btn-print { background: #1a56db; }
  .btn-close { background: #6b7280; }

  @media print {
    .print-controls { display: none !important; }
    body { padding: 10mm 12mm; }
    @page { margin: 12mm 14mm; }
  }
</style>
</head>
<body>

<div class="print-controls">
  <button class="btn-print" onclick="window.print()"><?= __('btn_print') ?></button>
  <button class="btn-close" onclick="window.close()"><?= __('btn_close') ?></button>
</div>

<div class="org-block">
  <div class="org-name"><?= e($orgName) ?></div>
  <div class="org-meta">
    <?php if ($orgInn): ?><?= __('cust_inn') ?>: <?= e($orgInn) ?><?php if ($orgAddress): ?> &nbsp;|&nbsp; <?php endif; ?><?php endif; ?>
    <?= e($orgAddress) ?>
  </div>
  <?php if ($headerNote): ?>
    <div class="org-meta" style="margin-top:3px"><?= nl2br(e($headerNote)) ?></div>
  <?php endif; ?>
</div>

<div class="doc-title"><?= e($docTitle) ?></div>
<div class="doc-subtitle">
  <?= __('gr_doc_no') ?>: <strong><?= e($doc['doc_no']) ?></strong>
  &nbsp;<?= __('lbl_date') ?>: <strong><?= date_fmt($doc['doc_date'], 'd.m.Y') ?></strong>
  <?php if ($doc['supplier_doc_no']): ?>
    &nbsp;| <?= __('gr_supplier_doc_no') ?>: <strong><?= e($doc['supplier_doc_no']) ?></strong>
  <?php endif; ?>
</div>

<div class="info-grid">
  <div class="info-row">
    <span class="info-label"><?= e($lblSupplier) ?>:</span>
    <span class="info-value"><?= e($doc['supplier_name'] ?? '-') ?>
      <?php if ($doc['supplier_inn']): ?> (<?= __('sup_inn') ?>: <?= e($doc['supplier_inn']) ?>)<?php endif; ?></span>
  </div>
  <div class="info-row">
    <span class="info-label"><?= e($lblWarehouse) ?>:</span>
    <span class="info-value"><?= e($doc['warehouse_name'] ?? '-') ?></span>
  </div>
  <?php if ($doc['supplier_address']): ?>
  <div class="info-row">
    <span class="info-label"><?= __('lbl_address') ?>:</span>
    <span><?= e($doc['supplier_address']) ?></span>
  </div>
  <?php endif; ?>
  <?php if ($doc['warehouse_address']): ?>
  <div class="info-row">
    <span class="info-label"><?= __('lbl_address') ?>:</span>
    <span><?= e($doc['warehouse_address']) ?></span>
  </div>
  <?php endif; ?>
</div>

<table class="items-table">
  <thead>
    <tr>
      <th style="width:28px">#</th>
      <th style="min-width:200px;text-align:left"><?= __('lbl_name') ?></th>
      <th style="width:55px"><?= __('lbl_unit') ?></th>
      <th style="width:70px"><?= __('lbl_qty') ?></th>
      <th style="width:100px"><?= __('gr_unit_price') ?></th>
      <th style="width:70px"><?= __('gr_tax_rate') ?></th>
      <th style="width:110px"><?= __('gr_line_total') ?></th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($items as $i => $item): ?>
    <tr>
      <td class="ctr"><?= $i + 1 ?></td>
      <td><?= e($item['name']) ?><?php if ($item['notes']): ?><br><small style="color:#666"><?= e($item['notes']) ?></small><?php endif; ?></td>
      <td class="ctr"><?= unit_label($item['unit']) ?></td>
      <td class="num"><?= fmtQty((float)$item['qty']) ?></td>
      <td class="num"><?= number_format((float)$item['unit_price'], 2, '.', ' ') ?></td>
      <td class="ctr"><?= $item['tax_rate'] > 0 ? e($item['tax_rate']) . '%' : '-' ?></td>
      <td class="num"><?= number_format((float)$item['line_total'], 2, '.', ' ') ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
  <tfoot>
    <tr>
      <td colspan="5" style="text-align:right;padding-right:8px"><?= __('gr_subtotal') ?>:</td>
      <td></td>
      <td class="num"><?= number_format((float)$doc['subtotal'], 2, '.', ' ') ?></td>
    </tr>
    <?php if ($doc['tax_amount'] > 0): ?>
    <tr>
      <td colspan="5" style="text-align:right;padding-right:8px"><?= __('lbl_tax') ?>:</td>
      <td></td>
      <td class="num"><?= number_format((float)$doc['tax_amount'], 2, '.', ' ') ?></td>
    </tr>
    <?php endif; ?>
    <tr>
      <td colspan="5" style="text-align:right;padding-right:8px;font-size:12pt"><?= __('lbl_total') ?>:</td>
      <td></td>
      <td class="num" style="font-size:12pt"><?= number_format((float)$doc['total'], 2, '.', ' ') ?></td>
    </tr>
  </tfoot>
</table>

<div class="amount-words">
  <?= __('gr_total_amount_label') ?>: <span><?= amount_in_words((float)$doc['total']) ?></span>
</div>

<?php if ($footerNote): ?>
<div class="footer-note"><?= nl2br(e($footerNote)) ?></div>
<?php endif; ?>

<?php if ($doc['notes']): ?>
<div class="footer-note"><?= __('lbl_notes') ?>: <?= nl2br(e($doc['notes'])) ?></div>
<?php endif; ?>

<table class="sig-table">
  <tr>
    <td style="width:50%">
      <?= e($lblDelivered) ?>: <span class="sig-line">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</span>
      &nbsp;/&nbsp;<em><?= e($doc['delivered_by'] ?? '') ?></em>
    </td>
    <td style="width:50%">
      <?= e($lblAccepted) ?>: <span class="sig-line">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</span>
      &nbsp;/&nbsp;<em><?= e($doc['accepted_by'] ?? '') ?></em>
    </td>
  </tr>
  <tr>
    <td style="padding-top:4px;font-size:9pt;color:#666">(<?= __('invoice_signature') ?>)&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;(<?= __('invoice_signature_name') ?>)</td>
    <td style="padding-top:4px;font-size:9pt;color:#666">(<?= __('invoice_signature') ?>)&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;(<?= __('invoice_signature_name') ?>)</td>
  </tr>
</table>

<script>
if (window.location.search.includes('autoprint=1')) {
  window.onload = function() { window.print(); };
}
</script>
</body>
</html>
