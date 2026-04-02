<?php
require_once __DIR__ . '/../../core/bootstrap.php';
Auth::requireLogin();
if (!Auth::can('pos') && !Auth::can('sales')) {
    http_response_code(403);
    include ROOT_PATH . '/views/partials/403.php';
    exit;
}

$saleId = (int)($_GET['id'] ?? 0);
$sale   = Database::row(
    "SELECT s.*, u.name AS cashier_name, c.name AS customer_name, c.phone AS customer_phone,
            c.customer_type, c.company AS customer_company, c.inn AS customer_inn,
            c.address AS customer_address, c.email AS customer_email
     FROM sales s
     JOIN users u ON u.id=s.user_id
     JOIN customers c ON c.id=s.customer_id
     WHERE s.id=?",
    [$saleId]
);
if (!$sale) { die('Sale not found'); }
require_warehouse_access((int)$sale['warehouse_id'], '/modules/pos/');
header('Content-Type: text/html; charset=UTF-8');

$items    = Database::all("SELECT * FROM sale_items WHERE sale_id=?", [$saleId]);
$payments = Database::all("SELECT * FROM payments WHERE sale_id=?", [$saleId]);
$itemUnitLabels = [];
if ($items) {
    $productIds = array_values(array_unique(array_map(static fn(array $item): int => (int)$item['product_id'], $items)));
    if ($productIds) {
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $productRows = Database::all("SELECT id, unit FROM products WHERE id IN ($placeholders)", $productIds);
        foreach ($productRows as $productRow) {
            $unitMap = product_unit_map((int)$productRow['id'], (string)$productRow['unit']);
            $labels = [];
            foreach ($unitMap as $code => $unitRow) {
                $labels[(string)$code] = product_unit_label_text($unitRow);
            }
            $itemUnitLabels[(int)$productRow['id']] = $labels;
        }
    }
}

$storeName    = setting('store_name',    'BuildMart');
$storeAddress = setting('store_address', '');
$storePhone   = setting('store_phone',   '');
$storeInn     = setting('store_inn',     '');
$header       = setting('receipt_header', '');
$footer       = setting('receipt_footer', '');
$currency     = currency_symbol();
$customerSnapshot = sale_customer_snapshot($sale, [
    'name' => $sale['customer_name'] ?? '',
    'phone' => $sale['customer_phone'] ?? '',
    'email' => $sale['customer_email'] ?? '',
    'company' => $sale['customer_company'] ?? '',
    'inn' => $sale['customer_inn'] ?? '',
    'address' => $sale['customer_address'] ?? '',
    'customer_type' => $sale['customer_type'] ?? 'retail',
]);
?>
<!DOCTYPE html>
<html lang="<?= Lang::current() ?>">
<head>
<meta charset="UTF-8">
<title><?= __('pos_receipt_no') ?><?= e($sale['receipt_no']) ?></title>
<style>
  * { margin:0;padding:0;box-sizing:border-box; }
  body { font-family:'Courier New',Courier,monospace; font-size:12px; color:#000; background:#fff; width:300px; margin:0 auto; padding:8px; }
  .center { text-align:center; }
  .bold { font-weight:bold; }
  .right { text-align:right; }
  .dashes { border-top:1px dashed #000; margin:6px 0; }
  .row { display:flex; justify-content:space-between; margin:2px 0; }
  .item-name { font-size:11px; margin-bottom:1px; }
  .total-row { font-size:15px; font-weight:bold; }
  .btn-print { display:block;width:100%;padding:10px;margin-top:12px;background:#f5a623;border:none;border-radius:4px;font-size:13px;font-weight:bold;cursor:pointer; }
  @media print { .btn-print,.no-print { display:none; } @page { margin:0;size:80mm auto; } }
</style>
</head>
<body>
<div class="center bold" style="font-size:16px"><?= e($storeName) ?></div>
<?php if ($storeAddress): ?><div class="center" style="font-size:11px"><?= e($storeAddress) ?></div><?php endif; ?>
<?php if ($storePhone):   ?><div class="center" style="font-size:11px"><?= e($storePhone) ?></div><?php endif; ?>
<?php if ($storeInn):     ?><div class="center" style="font-size:11px"><?= __('cust_inn') ?> <?= e($storeInn) ?></div><?php endif; ?>
<?php if ($header):       ?><div class="center" style="margin-top:4px;font-size:11px"><?= e($header) ?></div><?php endif; ?>

<div class="dashes"></div>

<div class="row">
  <span><?= __('pos_receipt_no') ?></span>
  <span class="bold"><?= e($sale['receipt_no']) ?></span>
</div>
<div class="row">
  <span><?= __('lbl_date') ?></span>
  <span><?= date('d.m.Y H:i', strtotime($sale['created_at'])) ?></span>
</div>
<div class="row">
  <span><?= __('pos_cashier') ?></span>
  <span><?= e($sale['cashier_name']) ?></span>
</div>
<?php if ($customerSnapshot['display_name'] && $sale['customer_id'] != 1): ?>
<div class="row">
  <span><?= __('cust_title') ?></span>
  <span><?= e($customerSnapshot['display_name']) ?></span>
</div>
<?php endif; ?>
<?php if ($customerSnapshot['iin_bin'] !== ''): ?>
<div class="row">
  <span><?= __('cust_inn') ?></span>
  <span><?= e($customerSnapshot['iin_bin']) ?></span>
</div>
<?php endif; ?>

<div class="dashes"></div>

<?php foreach ($items as $it): ?>
<?php
  $unitCode = (string)($it['unit'] ?? '');
  $unitLabel = $itemUnitLabels[(int)$it['product_id']][$unitCode] ?? unit_label($unitCode);
?>
<div class="item-name"><?= e($it['product_name']) ?> [<?= e($unitLabel) ?>]</div>
<div style="font-size:10px;color:#444;margin-bottom:1px"><?= __('lbl_unit') ?>: <?= e($unitLabel) ?></div>
<div class="row">
  <span><?= e(fmtQty((float)$it['qty'])) ?> × <?= money($it['unit_price']) ?></span>
  <span class="bold"><?= money($it['line_total']) ?></span>
</div>
<?php if ($it['discount_amount'] > 0): ?>
  <div class="row" style="font-size:11px;color:#555">
    <span>  <?= __('lbl_discount') ?> <?= $it['discount_pct'] ?>%</span>
    <span>-<?= money($it['discount_amount']) ?></span>
  </div>
<?php endif; ?>
<?php endforeach; ?>

<div class="dashes"></div>

<div class="row"><span><?= __('lbl_subtotal') ?></span><span><?= money($sale['subtotal']) ?></span></div>
<?php if ($sale['discount_amount'] > 0): ?>
<div class="row"><span><?= __('lbl_discount') ?></span><span>-<?= money($sale['discount_amount']) ?></span></div>
<?php endif; ?>
<?php if ($sale['tax_amount'] > 0): ?>
<div class="row"><span><?= __('lbl_tax') ?></span><span><?= money($sale['tax_amount']) ?></span></div>
<?php endif; ?>
<div class="dashes"></div>
<div class="row total-row">
  <span><?= __('lbl_total') ?></span>
  <span><?= money($sale['total']) ?></span>
</div>
<div class="dashes"></div>

<?php foreach ($payments as $pay): ?>
<div class="row">
  <span><?= __('pos_pay_' . $pay['method']) ?></span>
  <span><?= money($pay['amount']) ?></span>
</div>
<?php if ($pay['change_given'] > 0): ?>
<div class="row">
  <span><?= __('pos_change') ?></span>
  <span><?= money($pay['change_given']) ?></span>
</div>
<?php endif; ?>
<?php endforeach; ?>

<?php if ($footer): ?>
<div class="dashes"></div>
<div class="center" style="font-size:11px;margin-top:4px"><?= e($footer) ?></div>
<?php endif; ?>

<button class="btn-print no-print" onclick="window.print()"><?= __('btn_print') ?></button>

<script>
  window.onload = function() {
    // Auto-print after a short delay
    setTimeout(function() { window.print(); }, 500);
  };
</script>
</body>
</html>
<?php
