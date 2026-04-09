<?php
/**
 * Приёмка товара — Карточка приёмки
 * modules/acceptance/view.php
 *
 * Для документов pending_acceptance: форма с редактируемыми полями (факт. кол-во,
 * закупочная и продажная цены). Для accepted/cancelled: только просмотр.
 */
require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../views/partials/icons.php';
Auth::requireLogin();
Auth::requirePerm('acceptance');
$canProcessAcceptance = Auth::can('acceptance.process');
$canAcceptAcceptance = Auth::can('acceptance.accept');

$id = (int)($_GET['id'] ?? 0);
if (!$id) { redirect('/modules/acceptance/'); }

$doc = Database::row(
    "SELECT gr.*,
            s.name  AS supplier_name, s.inn AS supplier_inn, s.address AS supplier_address,
            w.name  AS warehouse_name,
            u.name  AS created_by_name,
            pu.name AS posted_by_name,
            au.name AS accepted_user_name
     FROM   goods_receipts gr
     LEFT JOIN suppliers  s  ON s.id  = gr.supplier_id
     LEFT JOIN warehouses w  ON w.id  = gr.warehouse_id
     LEFT JOIN users      u  ON u.id  = gr.created_by
     LEFT JOIN users      pu ON pu.id = gr.posted_by
     LEFT JOIN users      au ON au.id = gr.accepted_by_user
     WHERE gr.id = ? AND gr.status IN ('pending_acceptance','accepted','cancelled')",
    [$id]
);
if (!$doc) { flash_error(_r('err_not_found')); redirect('/modules/acceptance/'); }
require_warehouse_access((int)$doc['warehouse_id'], '/modules/acceptance/');

$items = Database::all(
    "SELECT gri.*, p.sku, p.unit AS product_unit, p.sale_price AS current_sale_price
     FROM goods_receipt_items gri
     LEFT JOIN products p ON p.id = gri.product_id
     WHERE gri.receipt_id = ?
     ORDER BY gri.sort_order, gri.id",
    [$id]
);

$isEditable  = $doc['status'] === 'pending_acceptance' && ($canProcessAcceptance || $canAcceptAcceptance);
$pageTitle   = __('acc_title') . ': ' . $doc['doc_no'];
$breadcrumbs = [[__('acc_title'), url('modules/acceptance/')], [$doc['doc_no'], null]];

include __DIR__ . '/../../views/layouts/header.php';
?>

<style>
.acc-input { width:100%; text-align:right; }
.qty-diff  { font-size:11px; margin-top:2px; }
.qty-diff.over  { color: var(--success); }
.qty-diff.under { color: var(--danger); }
.qc-overlay {
  display: none;
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,.65);
  z-index: 1200;
  align-items: center;
  justify-content: center;
  padding: 18px;
}
.qc-overlay.open { display: flex; }
.qc-modal {
  background: var(--bg-surface);
  border: 1px solid var(--border-medium);
  border-radius: var(--radius-xl);
  width: min(520px, 100%);
  box-shadow: var(--shadow-xl);
  display: flex;
  flex-direction: column;
  max-height: 90vh;
}
.qc-modal-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 18px 22px 14px;
  border-bottom: 1px solid var(--border-dim);
}
.qc-modal-title {
  font-size: 15px;
  font-weight: 600;
  color: var(--text-primary);
  display: flex;
  align-items: center;
  gap: 8px;
}
.qc-modal-close {
  background: none;
  border: none;
  color: var(--text-muted);
  cursor: pointer;
  padding: 2px 6px;
  border-radius: var(--radius-sm);
  font-size: 20px;
  line-height: 1;
}
.qc-modal-body {
  padding: 18px 22px;
}
.qc-modal-footer {
  padding: 12px 22px 18px;
  border-top: 1px solid var(--border-dim);
  display: flex;
  gap: 8px;
}
.doc-confirm-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px;
}
.doc-confirm-card {
  padding: 10px 12px;
  border: 1px solid var(--border-soft);
  border-radius: 12px;
  background: var(--bg-raised);
}
.doc-confirm-label {
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: .08em;
  color: var(--text-muted);
  margin-bottom: 4px;
}
.doc-confirm-value {
  font-size: 14px;
  font-weight: 600;
  color: var(--text-primary);
  word-break: break-word;
}
.doc-confirm-check {
  display: flex;
  gap: 10px;
  align-items: flex-start;
  margin-top: 12px;
  padding: 12px;
  border: 1px solid var(--border-soft);
  border-radius: 12px;
  background: var(--bg-raised);
}
.doc-confirm-check input { margin-top: 3px; }
.doc-confirm-check label { margin: 0; color: var(--text-primary); }
.acc-selected-unit {
  color: var(--text-primary);
  font-weight: 600;
  letter-spacing: .01em;
}
.acc-unit-matrix {
  display: flex;
  flex-direction: column;
  gap: 6px;
  margin-top: 8px;
}
.acc-unit-matrix .form-label {
  color: var(--text-secondary);
}
.acc-matrix-sale-wrap {
  display: flex;
  align-items: center;
  gap: 8px;
}
.acc-matrix-sale-wrap .acc-matrix-sale {
  flex: 1 1 auto;
}
#acc-table {
  min-width: 1280px;
}
.acc-sale-wrap {
  display: flex;
  width: 100%;
  min-width: 170px;
  gap: 8px;
  align-items: center;
}
.acc-sale-wrap .acc-sale {
  flex: 1 1 auto;
  min-width: 110px;
}
.acc-percent-btn {
  flex: 0 0 42px;
  min-width: 42px;
  justify-content: center;
  padding-inline: 0;
  background: var(--amber);
  border-color: var(--amber);
  color: #fff;
  font-weight: 700;
  font-size: 15px;
  box-shadow: inset 0 0 0 1px rgba(255,255,255,0.08);
}
.acc-percent-btn:hover,
.acc-percent-btn:focus {
  background: var(--amber-light);
  border-color: var(--amber-light);
  color: #fff;
}
@media (max-width: 640px) {
  .doc-confirm-grid { grid-template-columns: 1fr; }
}
</style>

<div class="page-header">
  <div>
    <h1 class="page-heading"><?= e($doc['doc_no']) ?></h1>
    <div class="status-inline">
      <?= gr_status_badge($doc['status']) ?>
      <span class="text-muted fs-sm"><?= date_fmt($doc['doc_date'], 'd.m.Y') ?></span>
    </div>
  </div>
  <div class="page-actions">
    <a href="<?= url('modules/acceptance/') ?>" class="btn btn-ghost">
      <?= feather_icon('arrow-left',15) ?> <?= __('btn_back') ?>
    </a>
    <a href="<?= url('modules/receipts/view.php?id='.$id) ?>" class="btn btn-ghost" target="_blank">
      <?= feather_icon('file-text',15) ?> <?= __('acc_view_receipt') ?>
    </a>
  </div>
</div>

<!-- Мета -->
<div class="grid grid-2 mobile-form-stack mb-3">
  <div class="card">
    <div class="card-header"><span class="card-title"><?= __('gr_header') ?></span></div>
    <div class="card-body fs-md">
      <table class="detail-table">
        <tr><td style="padding:4px 0;color:var(--text-muted);width:40%"><?= __('gr_doc_no') ?></td>
            <td class="font-mono fw-600"><?= e($doc['doc_no']) ?></td></tr>
        <tr><td style="padding:4px 0;color:var(--text-muted)"><?= __('lbl_date') ?></td>
            <td><?= date_fmt($doc['doc_date'],'d.m.Y') ?></td></tr>
        <tr><td style="padding:4px 0;color:var(--text-muted)"><?= __('gr_supplier') ?></td>
            <td>
              <div><?= !empty($doc['supplier_name']) ? e($doc['supplier_name']) : '&mdash;' ?></div>
              <?php if ($doc['supplier_inn']): ?>
                <div class="text-muted fs-xs"><?= __('sup_inn') ?>: <?= e($doc['supplier_inn']) ?></div>
              <?php endif; ?>
              <?php if ($doc['supplier_address']): ?>
                <div class="text-muted fs-xs"><?= e($doc['supplier_address']) ?></div>
              <?php endif; ?>
            </td></tr>
        <tr><td style="padding:4px 0;color:var(--text-muted)"><?= __('gr_warehouse') ?></td>
            <td><?= !empty($doc['warehouse_name']) ? e($doc['warehouse_name']) : '&mdash;' ?></td></tr>
        <?php if ($doc['notes']): ?>
        <tr><td style="padding:4px 0;color:var(--text-muted)"><?= __('lbl_notes') ?></td>
            <td><?= nl2br(e($doc['notes'])) ?></td></tr>
        <?php endif; ?>
      </table>
    </div>
  </div>
  <div class="card">
    <div class="card-header"><span class="card-title"><?= __('lbl_status') ?></span></div>
    <div class="card-body fs-md">
      <table class="detail-table">
        <tr><td style="padding:4px 0;color:var(--text-muted);width:40%"><?= __('lbl_status') ?></td>
            <td><?= gr_status_badge($doc['status']) ?></td></tr>
        <tr><td style="padding:4px 0;color:var(--text-muted)"><?= __('lbl_created') ?></td>
            <td><?= date_fmt($doc['created_at']) ?> &mdash; <?= e($doc['created_by_name'] ?? '') ?></td></tr>
        <?php if ($doc['posted_at']): ?>
        <tr><td style="padding:4px 0;color:var(--text-muted)"><?= __('acc_sent_at') ?></td>
            <td><?= date_fmt($doc['posted_at']) ?> &mdash; <?= e($doc['posted_by_name'] ?? '') ?></td></tr>
        <?php endif; ?>
        <?php if ($doc['accepted_at']): ?>
        <tr><td style="padding:4px 0;color:var(--text-muted)"><?= __('acc_accepted_at') ?></td>
            <td>
              <strong><?= date_fmt($doc['accepted_at']) ?></strong><br>
              <span class="text-muted"><?= e($doc['accepted_user_name'] ?? '') ?></span>
            </td></tr>
        <?php endif; ?>
      </table>
    </div>
  </div>
</div>

<!-- Товары -->
<?php if ($isEditable): ?>
<form method="POST" action="<?= url('modules/acceptance/save.php') ?>" id="acc-form">
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= $id ?>">
<?php endif; ?>

<div class="card mb-3">
  <div class="card-header">
    <span class="card-title"><?= __('gr_items') ?></span>
    <?php if ($isEditable): ?>
    <span class="text-muted fs-sm" style="font-weight:400;margin-left:8px">
      <?= __('acc_edit_hint') ?>
    </span>
    <?php endif; ?>
  </div>
  <div class="table-wrap mobile-table-scroll">
    <table class="table" id="acc-table">
      <thead>
        <tr>
          <th style="width:32px">#</th>
          <th><?= __('gr_product') ?></th>
          <th style="width:110px"><?= __('lbl_unit') ?></th>
          <th class="col-num" style="width:110px"><?= __('acc_ordered_qty') ?></th>
          <th class="col-num" style="width:120px"><?= __('acc_actual_qty') ?></th>
          <th class="col-num" style="width:140px"><?= __('gr_unit_price') ?></th>
          <th class="col-num" style="width:170px"><?= __('acc_sale_price') ?></th>
          <th class="col-num" style="width:150px"><?= __('acc_purchase_total') ?></th>
          <th class="col-num" style="width:150px"><?= __('acc_sale_total') ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($items as $i => $item): ?>
        <?php
          $orderedQty = (float)$item['qty'];
          $acceptedQty = $item['accepted_qty'] !== null ? (float)$item['accepted_qty'] : $orderedQty;
          $costPrice   = (float)$item['unit_price'];
          $salePrice   = (float)$item['sale_price'] ?: (float)($item['current_sale_price'] ?? 0);
          $rowUnits    = !empty($item['product_id']) ? product_units((int)$item['product_id'], $item['product_unit'] ?: $item['unit']) : [];
          $unitOverrides = !empty($item['product_id']) ? product_unit_price_overrides((int)$item['product_id']) : [];
          $basePrices = !empty($item['product_id']) ? UISettings::productPrices((int)$item['product_id']) : ['purchase' => $costPrice, 'retail' => $salePrice];
          $receiptPurchaseMap = json_decode((string)($item['unit_prices_json'] ?? ''), true);
          $receiptSaleMap = json_decode((string)($item['sale_prices_json'] ?? ''), true);
          $purchasePricesByUnit = [];
          $salePricesByUnit = [];
          foreach ($rowUnits as $rowUnitItem) {
              $purchasePricesByUnit[$rowUnitItem['unit_code']] = product_unit_price((int)$item['product_id'], $rowUnitItem['unit_code'], 'purchase', (float)($basePrices['purchase'] ?? $costPrice), $rowUnits, $unitOverrides);
              $salePricesByUnit[$rowUnitItem['unit_code']] = product_unit_price((int)$item['product_id'], $rowUnitItem['unit_code'], 'retail', (float)($basePrices['retail'] ?? $salePrice), $rowUnits, $unitOverrides);
          }
          if (is_array($receiptPurchaseMap)) {
              foreach ($receiptPurchaseMap as $unitCode => $value) {
                  $purchasePricesByUnit[$unitCode] = (float)$value;
              }
          }
          if (is_array($receiptSaleMap)) {
              foreach ($receiptSaleMap as $unitCode => $value) {
                  $salePricesByUnit[$unitCode] = (float)$value;
              }
          }
          $selectedUnit = null;
          foreach ($rowUnits as $rowUnit) {
              if ($rowUnit['unit_code'] === $item['unit']) {
                  $selectedUnit = $rowUnit;
                  break;
              }
          }
          $selectedUnit = $selectedUnit ?: ($rowUnits[0] ?? ['ratio_to_base' => 1]);
          $selectedUnitCode = (string)($selectedUnit['unit_code'] ?? $item['unit']);
          $costPrice = isset($purchasePricesByUnit[$selectedUnitCode])
              ? (float)$purchasePricesByUnit[$selectedUnitCode]
              : $costPrice;
          $salePrice = isset($salePricesByUnit[$selectedUnitCode])
              ? (float)$salePricesByUnit[$selectedUnitCode]
              : $salePrice;
          $linePurchaseTotal = $acceptedQty * $costPrice;
          $lineSaleTotal = $acceptedQty * $salePrice;
          $costBase = $costPrice * max(1, (float)$selectedUnit['ratio_to_base']);
          $saleBase = $salePrice > 0 ? $salePrice * max(1, (float)$selectedUnit['ratio_to_base']) : 0;
        ?>
        <tr data-cost-base="<?= e(number_format($costBase, 6, '.', '')) ?>"
            data-sale-base="<?= e(number_format($saleBase, 6, '.', '')) ?>"
            data-purchase-prices='<?= e(json_encode($purchasePricesByUnit, JSON_UNESCAPED_UNICODE)) ?>'
            data-sale-prices='<?= e(json_encode($salePricesByUnit, JSON_UNESCAPED_UNICODE)) ?>'>
          <td class="text-muted fs-sm text-center"><?= $i+1 ?></td>
          <td>
            <div class="fw-600"><?= e($item['name']) ?></div>
            <?php if ($item['sku']): ?>
              <div class="text-muted font-mono fs-xs"><?= e($item['sku']) ?></div>
            <?php endif; ?>
            <?php if ($isEditable): ?>
              <input type="hidden" name="items[<?= $i ?>][item_id]" value="<?= $item['id'] ?>">
              <input type="hidden" name="items[<?= $i ?>][unit_prices_json]" class="acc-unit-prices-json" value='<?= e(json_encode($purchasePricesByUnit, JSON_UNESCAPED_UNICODE)) ?>'>
              <input type="hidden" name="items[<?= $i ?>][sale_prices_json]" class="acc-sale-prices-json" value='<?= e(json_encode($salePricesByUnit, JSON_UNESCAPED_UNICODE)) ?>'>
            <?php endif; ?>
            <div class="acc-unit-matrix"></div>
          </td>
          <td>
            <?php if ($isEditable && $rowUnits): ?>
              <select name="items[<?= $i ?>][unit]" class="form-control form-control-sm acc-unit" style="display:none">
                <?php foreach ($rowUnits as $rowUnit): ?>
                  <option value="<?= e($rowUnit['unit_code']) ?>"
                          data-ratio="<?= e($rowUnit['ratio_to_base']) ?>"
                          <?= $item['unit'] === $rowUnit['unit_code'] ? 'selected' : '' ?>>
                    <?= e(product_unit_label_text($rowUnit)) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <div class="acc-selected-unit"><?= e($purchasePricesByUnit ? ($rowUnits ? product_unit_label_text($selectedUnit) : unit_label($item['unit'])) : unit_label($item['unit'])) ?></div>
            <?php else: ?>
              <?= unit_label($item['unit']) ?>
            <?php endif; ?>
          </td>
          <td class="col-num text-muted"><?= fmtQty($orderedQty) ?></td>
          <td class="col-num">
            <?php if ($isEditable): ?>
              <input type="number" name="items[<?= $i ?>][accepted_qty]"
                     class="form-control form-control-sm acc-input acc-qty"
                     value="<?= fmtQty($acceptedQty) ?>"
                     data-ordered="<?= $orderedQty ?>"
                     min="0" step="0.001">
              <div class="qty-diff" id="qdiff-<?= $i ?>"></div>
            <?php else: ?>
              <span class="fw-600"><?= fmtQty($acceptedQty) ?></span>
            <?php endif; ?>
          </td>
          <td class="col-num">
            <?php if ($isEditable): ?>
              <input type="number" name="items[<?= $i ?>][unit_price]"
                     class="form-control form-control-sm acc-input acc-cost"
                     value="<?= number_format($costPrice, 2, '.', '') ?>"
                     min="0" step="0.01">
            <?php else: ?>
              <?= money($costPrice) ?>
            <?php endif; ?>
          </td>
          <td class="col-num">
            <?php if ($isEditable): ?>
              <input type="number" name="items[<?= $i ?>][sale_price]"
                     class="form-control form-control-sm acc-input acc-sale"
                     value="<?= number_format($salePrice, 2, '.', '') ?>"
                     min="0" step="0.01">
            <?php else: ?>
              <?= $salePrice > 0 ? money($salePrice) : '<span class="text-muted">&mdash;</span>' ?>
            <?php endif; ?>
          </td>
          <td class="col-num fw-600 row-purchase-total" id="rtotal-purchase-<?= $i ?>">
            <?= money($linePurchaseTotal) ?>
          </td>
          <td class="col-num fw-600 row-sale-total" id="rtotal-sale-<?= $i ?>">
            <?= money($lineSaleTotal) ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="7" class="text-right fw-600"><?= __('lbl_total') ?>:</td>
          <td class="col-num fw-600 fs-lg" id="grand-total-purchase">
            <?= money($doc['total']) ?>
          </td>
          <td class="col-num fw-600 fs-lg" id="grand-total-sale">
            <?= money(array_reduce($items, static function ($carry, $item) {
                $qty = $item['accepted_qty'] !== null ? (float)$item['accepted_qty'] : (float)$item['qty'];
                $sale = (float)$item['sale_price'] ?: (float)($item['current_sale_price'] ?? 0);
                return $carry + ($qty * $sale);
            }, 0.0)) ?>
          </td>
        </tr>
      </tfoot>
    </table>
  </div>
</div>

<?php if ($isEditable): ?>
<div class="card">
  <div class="card-body stacked-actions">
    <?php if ($canProcessAcceptance): ?>
    <button type="submit" name="action" value="save" class="btn btn-secondary btn-lg">
      <?= feather_icon('save',17) ?> <?= __('btn_save') ?>
    </button>
    <?php endif; ?>
    <?php if ($canAcceptAcceptance): ?>
    <button type="submit" name="action" value="accept" class="btn btn-primary btn-lg"
            data-doc-confirm="accept">
      <?= feather_icon('check-circle',17) ?> <?= __('acc_accept_btn') ?>
    </button>
    <?php endif; ?>
    <a href="<?= url('modules/acceptance/') ?>" class="btn btn-ghost btn-lg"><?= __('btn_back') ?></a>
  </div>
</div>

<?php if ($canAcceptAcceptance): ?>
<div class="qc-overlay" id="modal-accept-confirm" role="dialog" aria-modal="true">
  <div class="qc-modal">
    <div class="qc-modal-header">
      <div class="qc-modal-title">
        <?= feather_icon('shield', 17) ?> <span id="accept-confirm-title"><?= __('doc_confirm_accept_title') ?></span>
      </div>
      <button type="button" class="qc-modal-close" id="accept-confirm-close">&times;</button>
    </div>
    <div class="qc-modal-body">
      <p class="text-muted mb-1"><?= __('doc_confirm_summary_hint') ?></p>
      <div class="doc-confirm-grid">
        <div class="doc-confirm-card">
          <div class="doc-confirm-label"><?= __('gr_doc_no') ?></div>
          <div class="doc-confirm-value" id="accept-confirm-doc-no"><?= e($doc['doc_no']) ?></div>
        </div>
        <div class="doc-confirm-card">
          <div class="doc-confirm-label"><?= __('lbl_date') ?></div>
          <div class="doc-confirm-value" id="accept-confirm-date"><?= date_fmt($doc['doc_date'],'d.m.Y') ?></div>
        </div>
        <div class="doc-confirm-card">
          <div class="doc-confirm-label"><?= __('gr_supplier') ?></div>
          <div class="doc-confirm-value" id="accept-confirm-supplier"><?= !empty($doc['supplier_name']) ? e($doc['supplier_name']) : '&mdash;' ?></div>
        </div>
        <div class="doc-confirm-card">
          <div class="doc-confirm-label"><?= __('gr_warehouse') ?></div>
          <div class="doc-confirm-value" id="accept-confirm-warehouse"><?= !empty($doc['warehouse_name']) ? e($doc['warehouse_name']) : '&mdash;' ?></div>
        </div>
        <div class="doc-confirm-card">
          <div class="doc-confirm-label"><?= __('gr_items') ?></div>
          <div class="doc-confirm-value" id="accept-confirm-items"><?= count($items) ?></div>
        </div>
        <div class="doc-confirm-card">
          <div class="doc-confirm-label"><?= __('acc_purchase_total') ?></div>
          <div class="doc-confirm-value" id="accept-confirm-purchase-total"><?= money($doc['total']) ?></div>
        </div>
        <div class="doc-confirm-card">
          <div class="doc-confirm-label"><?= __('acc_sale_total') ?></div>
          <div class="doc-confirm-value" id="accept-confirm-sale-total"><?= money(array_reduce($items, static function ($carry, $item) {
              $qty = $item['accepted_qty'] !== null ? (float)$item['accepted_qty'] : (float)$item['qty'];
              $sale = (float)$item['sale_price'] ?: (float)($item['current_sale_price'] ?? 0);
              return $carry + ($qty * $sale);
          }, 0.0)) ?></div>
        </div>
      </div>
      <div class="doc-confirm-check">
        <input type="checkbox" id="accept-confirm-checkbox">
        <label for="accept-confirm-checkbox"><?= __('doc_confirm_accept_checkbox') ?></label>
      </div>
    </div>
    <div class="qc-modal-footer">
      <button type="button" class="btn btn-primary" id="accept-confirm-submit" disabled><?= __('btn_confirm') ?></button>
      <button type="button" class="btn btn-ghost" id="accept-confirm-cancel"><?= __('btn_cancel') ?></button>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="qc-overlay" id="modal-sale-markup" role="dialog" aria-modal="true">
  <div class="qc-modal" style="max-width:420px">
    <div class="qc-modal-header">
      <div class="qc-modal-title">
        <?= feather_icon('percent', 17) ?> <span><?= __('acc_markup_title') ?></span>
      </div>
      <button type="button" class="qc-modal-close" id="sale-markup-close">&times;</button>
    </div>
    <div class="qc-modal-body">
      <div class="doc-confirm-grid">
        <div class="doc-confirm-card">
          <div class="doc-confirm-label"><?= __('lbl_unit') ?></div>
          <div class="doc-confirm-value" id="sale-markup-unit">&mdash;</div>
        </div>
        <div class="doc-confirm-card">
          <div class="doc-confirm-label"><?= __('gr_unit_price') ?></div>
          <div class="doc-confirm-value" id="sale-markup-cost">0.00</div>
        </div>
      </div>
      <div class="form-group mb-0 mt-2">
        <label class="form-label"><?= __('acc_markup_label') ?></label>
        <input type="number" id="sale-markup-percent" class="form-control" min="0" step="0.01" value="0">
      </div>
    </div>
    <div class="qc-modal-footer">
      <button type="button" class="btn btn-primary" id="sale-markup-apply"><?= __('btn_apply') ?></button>
      <button type="button" class="btn btn-ghost" id="sale-markup-cancel"><?= __('btn_cancel') ?></button>
    </div>
  </div>
</div>
</form>

<script src="https://unpkg.com/feather-icons/dist/feather.min.js"></script>
<script>
feather.replace();

const acceptConfirmModal = document.getElementById('modal-accept-confirm');
const acceptConfirmCheckbox = document.getElementById('accept-confirm-checkbox');
const acceptConfirmSubmit = document.getElementById('accept-confirm-submit');
const saleMarkupModal = document.getElementById('modal-sale-markup');
const saleMarkupUnit = document.getElementById('sale-markup-unit');
const saleMarkupCost = document.getElementById('sale-markup-cost');
const saleMarkupPercent = document.getElementById('sale-markup-percent');
let pendingAcceptSubmitter = null;
let pendingMarkupRow = null;
let pendingMarkupUnitCode = '';

function openAcceptConfirm(submitter) {
  const form = document.getElementById('acc-form');
  if (!form || !submitter) return;
  if (!form.reportValidity()) return;

  pendingAcceptSubmitter = submitter;
  document.getElementById('accept-confirm-items').textContent = String(document.querySelectorAll('#acc-table tbody tr').length);
  document.getElementById('accept-confirm-purchase-total').textContent = document.getElementById('grand-total-purchase')?.textContent?.trim() || '0.00';
  document.getElementById('accept-confirm-sale-total').textContent = document.getElementById('grand-total-sale')?.textContent?.trim() || '0.00';
  if (acceptConfirmCheckbox) acceptConfirmCheckbox.checked = false;
  if (acceptConfirmSubmit) acceptConfirmSubmit.disabled = true;
  acceptConfirmModal?.classList.add('open');
}

function closeAcceptConfirm() {
  pendingAcceptSubmitter = null;
  if (acceptConfirmCheckbox) acceptConfirmCheckbox.checked = false;
  if (acceptConfirmSubmit) acceptConfirmSubmit.disabled = true;
  acceptConfirmModal?.classList.remove('open');
}

function openSaleMarkupModal(tr, unitCode) {
  const unitSelect = tr.querySelector('.acc-unit');
  const matrixPurchase = unitCode ? tr.querySelector(`.acc-matrix-purchase[data-unit="${unitCode}"]`) : null;
  const selectedOption = unitCode
    ? [...(unitSelect?.options || [])].find((option) => option.value === unitCode)
    : unitSelect?.selectedOptions?.[0];
  const selectedUnit = selectedOption?.textContent?.trim() || '-';
  const cost = parseFloat(matrixPurchase?.value || tr.querySelector('.acc-cost')?.value || 0) || 0;
  pendingMarkupRow = tr;
  pendingMarkupUnitCode = unitCode || unitSelect?.value || '';
  if (saleMarkupUnit) saleMarkupUnit.textContent = selectedUnit;
  if (saleMarkupCost) saleMarkupCost.textContent = accFormatPrice(cost);
  if (saleMarkupPercent) saleMarkupPercent.value = '0';
  saleMarkupModal?.classList.add('open');
}

function closeSaleMarkupModal() {
  pendingMarkupRow = null;
  pendingMarkupUnitCode = '';
  if (saleMarkupPercent) saleMarkupPercent.value = '0';
  saleMarkupModal?.classList.remove('open');
}

function recalcRow(tr, idx) {
  const qty  = parseFloat(tr.querySelector('.acc-qty')?.value)  || 0;
  const cost = parseFloat(tr.querySelector('.acc-cost')?.value) || 0;
  const sale = parseFloat(tr.querySelector('.acc-sale')?.value) || 0;
  const purchaseTotal = qty * cost;
  const saleTotal = qty * sale;
  const purchaseCell = document.getElementById('rtotal-purchase-' + idx);
  const saleCell = document.getElementById('rtotal-sale-' + idx);
  if (purchaseCell) purchaseCell.textContent = purchaseTotal.toFixed(2);
  if (saleCell) saleCell.textContent = saleTotal.toFixed(2);

  // Show qty diff
  const ordered = parseFloat(tr.querySelector('.acc-qty')?.dataset.ordered) || 0;
  const diff    = qty - ordered;
  const diffEl  = document.getElementById('qdiff-' + idx);
  if (diffEl) {
    if (Math.abs(diff) < 0.0005) {
      diffEl.textContent = '';
    } else {
      diffEl.textContent = (diff > 0 ? '+' : '') + diff.toFixed(3);
      diffEl.className   = 'qty-diff ' + (diff > 0 ? 'over' : 'under');
    }
  }
  recalcTotal();
}

function recalcTotal() {
  let purchaseSum = 0;
  let saleSum = 0;
  document.querySelectorAll('#acc-table tbody tr').forEach((tr, idx) => {
    const qty  = parseFloat(tr.querySelector('.acc-qty')?.value)  || 0;
    const cost = parseFloat(tr.querySelector('.acc-cost')?.value) || 0;
    const sale = parseFloat(tr.querySelector('.acc-sale')?.value) || 0;
    purchaseSum += qty * cost;
    saleSum += qty * sale;
  });
  const purchaseEl = document.getElementById('grand-total-purchase');
  const saleEl = document.getElementById('grand-total-sale');
  if (purchaseEl) purchaseEl.textContent = purchaseSum.toFixed(2);
  if (saleEl) saleEl.textContent = saleSum.toFixed(2);
}

function currentAccRatio(tr) {
  return parseFloat(tr.querySelector('.acc-unit')?.selectedOptions?.[0]?.dataset?.ratio || 1) || 1;
}

function accPriceNumber(value) {
  return parseFloat(value || 0) || 0;
}

function accFormatPrice(value) {
  return accPriceNumber(value).toFixed(2);
}

function accUnitList(tr) {
  return [...(tr.querySelector('.acc-unit')?.options || [])].map((option) => ({
    code: option.value,
    ratio: accPriceNumber(option.dataset.ratio || 1) || 1,
    label: option.textContent || option.value,
  }));
}

function accDerivedPriceMap(tr, anchorCode, anchorValue) {
  const units = accUnitList(tr);
  const anchor = units.find((unit) => unit.code === anchorCode) || units[0] || { ratio: 1 };
  const anchorRatio = accPriceNumber(anchor?.ratio || 1) || 1;
  const value = accPriceNumber(anchorValue);
  const map = {};
  units.forEach((unit) => {
    map[unit.code] = value > 0
      ? accPriceNumber((value * (anchorRatio / Math.max(1, unit.ratio))).toFixed(2))
      : 0;
  });
  return map;
}

function syncAccPricesFromBase(tr) {
  const ratio = currentAccRatio(tr);
  const unitCode = tr.querySelector('.acc-unit')?.value || '';
  const costInput = tr.querySelector('.acc-cost');
  const saleInput = tr.querySelector('.acc-sale');
  const purchaseMap = JSON.parse(tr.dataset.purchasePrices || '{}');
  const saleMap = JSON.parse(tr.dataset.salePrices || '{}');
  if (costInput) {
    const purchasePrice = purchaseMap[unitCode];
    costInput.value = purchasePrice !== undefined
      ? accFormatPrice(purchasePrice)
      : ((parseFloat(tr.dataset.costBase || 0) || 0) / Math.max(1, ratio)).toFixed(2);
  }
  if (saleInput) {
    const salePrice = saleMap[unitCode];
    const saleBase = parseFloat(tr.dataset.saleBase || 0) || 0;
    saleInput.value = salePrice !== undefined
      ? accFormatPrice(salePrice)
      : (saleBase > 0 ? (saleBase / Math.max(1, ratio)).toFixed(2) : '0.00');
  }
}

function accApplyAutoPriceMaps(tr) {
  const unitSelect = tr.querySelector('.acc-unit');
  if (!unitSelect) return;
  const units = accUnitList(tr);
  if (units.length < 2) return;

  const purchaseAnchorCode = tr.dataset.purchaseAnchorUnit || unitSelect.value || units[0]?.code || '';
  const saleAnchorCode = tr.dataset.saleAnchorUnit || unitSelect.value || units[0]?.code || '';
  const purchaseAnchorInput = tr.querySelector(`.acc-matrix-purchase[data-unit="${purchaseAnchorCode}"]`);
  const saleAnchorInput = tr.querySelector(`.acc-matrix-sale[data-unit="${saleAnchorCode}"]`);
  const purchaseAnchorValue = purchaseAnchorInput?.value ?? ((unitSelect.value || '') === purchaseAnchorCode ? tr.querySelector('.acc-cost')?.value : 0);
  const saleAnchorValue = saleAnchorInput?.value ?? ((unitSelect.value || '') === saleAnchorCode ? tr.querySelector('.acc-sale')?.value : 0);
  const nextPurchaseMap = accDerivedPriceMap(tr, purchaseAnchorCode, purchaseAnchorValue);
  const nextSaleMap = accDerivedPriceMap(tr, saleAnchorCode, saleAnchorValue);

  tr.dataset.purchasePrices = JSON.stringify(nextPurchaseMap);
  tr.dataset.salePrices = JSON.stringify(nextSaleMap);
  tr.querySelector('.acc-unit-prices-json').value = JSON.stringify(nextPurchaseMap);
  tr.querySelector('.acc-sale-prices-json').value = JSON.stringify(nextSaleMap);
  tr.querySelectorAll('.acc-matrix-purchase').forEach((input) => {
    input.value = accFormatPrice(nextPurchaseMap[input.dataset.unit] ?? 0);
  });
  tr.querySelectorAll('.acc-matrix-sale').forEach((input) => {
    input.value = accFormatPrice(nextSaleMap[input.dataset.unit] ?? 0);
  });
  syncAccPricesFromBase(tr);
  recalcRow(tr, tr.rowIndex - 1);
}

function renderAccUnitMatrix(tr) {
  const container = tr.querySelector('.acc-unit-matrix');
  const unitSelect = tr.querySelector('.acc-unit');
  if (!container || !unitSelect) return;
  const purchaseRaw = JSON.parse(tr.dataset.purchasePrices || '{}');
  const saleRaw = JSON.parse(tr.dataset.salePrices || '{}');
  const purchaseDerived = accDerivedPriceMap(tr, unitSelect.value || '', tr.querySelector('.acc-cost')?.value || 0);
  const saleDerived = accDerivedPriceMap(tr, unitSelect.value || '', tr.querySelector('.acc-sale')?.value || 0);
  const purchaseMap = {};
  const saleMap = {};
  const units = accUnitList(tr);
  units.forEach((unit) => {
    purchaseMap[unit.code] = purchaseRaw[unit.code] !== undefined && purchaseRaw[unit.code] !== null && purchaseRaw[unit.code] !== ''
      ? accPriceNumber(purchaseRaw[unit.code])
      : purchaseDerived[unit.code];
    saleMap[unit.code] = saleRaw[unit.code] !== undefined && saleRaw[unit.code] !== null && saleRaw[unit.code] !== ''
      ? accPriceNumber(saleRaw[unit.code])
      : saleDerived[unit.code];
  });
  tr.dataset.purchasePrices = JSON.stringify(purchaseMap);
  tr.dataset.salePrices = JSON.stringify(saleMap);
  tr.querySelector('.acc-unit-prices-json').value = JSON.stringify(purchaseMap);
  tr.querySelector('.acc-sale-prices-json').value = JSON.stringify(saleMap);
  const selected = unitSelect.value || '';
  const autoButton = units.length > 1 ? `
    <div class="unit-matrix-toolbar">
      <button type="button" class="btn btn-sm btn-primary acc-matrix-auto"><?= e(__('btn_auto')) ?></button>
    </div>
  ` : '';

  container.innerHTML = autoButton + [...unitSelect.options].map((option) => {
    const code = option.value;
    const label = option.textContent;
    const purchase = accFormatPrice(purchaseMap[code] ?? 0);
    const sale = accFormatPrice(saleMap[code] ?? 0);
    return `
      <div class="unit-matrix-card unit-matrix-card-extended">
        <label class="unit-matrix-radio">
          <input type="radio" class="acc-doc-unit" name="acc_doc_unit_${tr.rowIndex}" value="${code}" ${code === selected ? 'checked' : ''}>
        </label>
        <div class="form-group mb-0">
          <label class="form-label"><?= e(__('lbl_unit')) ?></label>
          <input type="text" class="form-control" value="${label}" readonly>
        </div>
        <div class="form-group mb-0">
          <label class="form-label"><?= e(__('gr_unit_price')) ?></label>
          <input type="number" class="form-control form-control-sm acc-matrix-purchase" data-unit="${code}" value="${purchase}" min="0" step="0.01">
        </div>
        <div class="form-group mb-0">
          <label class="form-label"><?= e(__('acc_sale_price')) ?></label>
          <div class="acc-matrix-sale-wrap">
            <input type="number" class="form-control form-control-sm acc-matrix-sale" data-unit="${code}" value="${sale}" min="0" step="0.01">
            <button type="button" class="btn btn-sm acc-percent-btn acc-matrix-percent-btn" data-unit="${code}" title="<?= e(__('acc_markup_title')) ?>">%</button>
          </div>
        </div>
      </div>
    `;
  }).join('');

  container.querySelectorAll('.acc-doc-unit').forEach((radio) => {
    radio.addEventListener('change', () => {
      unitSelect.value = radio.value;
      tr.querySelector('.acc-selected-unit').textContent = unitSelect.selectedOptions[0]?.textContent || '';
      syncAccPricesFromBase(tr);
      recalcRow(tr, tr.rowIndex - 1);
    });
  });
  container.querySelectorAll('.acc-matrix-purchase').forEach((input) => {
    input.addEventListener('input', () => {
      const purchaseMapNow = JSON.parse(tr.dataset.purchasePrices || '{}');
      purchaseMapNow[input.dataset.unit] = accPriceNumber(input.value);
      tr.dataset.purchasePrices = JSON.stringify(purchaseMapNow);
      tr.dataset.purchaseAnchorUnit = input.dataset.unit;
      tr.querySelector('.acc-unit-prices-json').value = JSON.stringify(purchaseMapNow);
      if ((unitSelect.value || '') === input.dataset.unit) {
        tr.querySelector('.acc-cost').value = accFormatPrice(purchaseMapNow[input.dataset.unit] ?? 0);
      }
      recalcRow(tr, tr.rowIndex - 1);
    });
  });
  container.querySelectorAll('.acc-matrix-sale').forEach((input) => {
    input.addEventListener('input', () => {
      const saleMapNow = JSON.parse(tr.dataset.salePrices || '{}');
      saleMapNow[input.dataset.unit] = accPriceNumber(input.value);
      tr.dataset.salePrices = JSON.stringify(saleMapNow);
      tr.dataset.saleAnchorUnit = input.dataset.unit;
      tr.querySelector('.acc-sale-prices-json').value = JSON.stringify(saleMapNow);
      if ((unitSelect.value || '') === input.dataset.unit) {
        tr.querySelector('.acc-sale').value = accFormatPrice(saleMapNow[input.dataset.unit] ?? 0);
      }
      recalcRow(tr, tr.rowIndex - 1);
    });
  });
  container.querySelectorAll('.acc-matrix-percent-btn').forEach((button) => {
    button.addEventListener('click', () => {
      openSaleMarkupModal(tr, button.dataset.unit || '');
    });
  });
  container.querySelector('.acc-matrix-auto')?.addEventListener('click', () => {
    accApplyAutoPriceMaps(tr);
  });
}

document.querySelectorAll('#acc-table tbody tr').forEach((tr, idx) => {
  tr.querySelectorAll('.acc-qty, .acc-cost, .acc-sale').forEach(inp => {
    inp.addEventListener('input', () => {
      const ratio = currentAccRatio(tr);
      const selectedUnit = tr.querySelector('.acc-unit')?.value || '';
      if (inp.classList.contains('acc-cost')) {
        tr.dataset.costBase = ((parseFloat(inp.value || 0) || 0) * Math.max(1, ratio)).toFixed(6);
        tr.dataset.purchaseAnchorUnit = selectedUnit;
        const purchaseMapNow = JSON.parse(tr.dataset.purchasePrices || '{}');
        purchaseMapNow[selectedUnit] = accPriceNumber(inp.value);
        tr.dataset.purchasePrices = JSON.stringify(purchaseMapNow);
        tr.querySelector('.acc-unit-prices-json').value = JSON.stringify(purchaseMapNow);
        const matrixInput = tr.querySelector(`.acc-matrix-purchase[data-unit="${selectedUnit}"]`);
        if (matrixInput && matrixInput !== inp) {
          matrixInput.value = accFormatPrice(inp.value);
        }
      }
      if (inp.classList.contains('acc-sale')) {
        tr.dataset.saleBase = ((parseFloat(inp.value || 0) || 0) * Math.max(1, ratio)).toFixed(6);
        tr.dataset.saleAnchorUnit = selectedUnit;
        const saleMapNow = JSON.parse(tr.dataset.salePrices || '{}');
        saleMapNow[selectedUnit] = accPriceNumber(inp.value);
        tr.dataset.salePrices = JSON.stringify(saleMapNow);
        tr.querySelector('.acc-sale-prices-json').value = JSON.stringify(saleMapNow);
        const matrixInput = tr.querySelector(`.acc-matrix-sale[data-unit="${selectedUnit}"]`);
        if (matrixInput && matrixInput !== inp) {
          matrixInput.value = accFormatPrice(inp.value);
        }
      }
      recalcRow(tr, idx);
    });
  });
  tr.querySelector('.acc-unit')?.addEventListener('change', () => {
    syncAccPricesFromBase(tr);
    recalcRow(tr, idx);
  });
  renderAccUnitMatrix(tr);
  syncAccPricesFromBase(tr);
  recalcRow(tr, idx); // init diffs
});

document.querySelectorAll('[data-doc-confirm="accept"]').forEach((button) => {
  button.addEventListener('click', (event) => {
    event.preventDefault();
    openAcceptConfirm(button);
  });
});
document.getElementById('accept-confirm-close')?.addEventListener('click', closeAcceptConfirm);
document.getElementById('accept-confirm-cancel')?.addEventListener('click', closeAcceptConfirm);
acceptConfirmCheckbox?.addEventListener('change', () => {
  if (acceptConfirmSubmit) {
    acceptConfirmSubmit.disabled = !acceptConfirmCheckbox.checked;
  }
});
acceptConfirmSubmit?.addEventListener('click', () => {
  if (!pendingAcceptSubmitter || !acceptConfirmCheckbox?.checked) return;
  const submitter = pendingAcceptSubmitter;
  closeAcceptConfirm();
  submitter.form?.requestSubmit(submitter);
});
document.getElementById('sale-markup-close')?.addEventListener('click', closeSaleMarkupModal);
document.getElementById('sale-markup-cancel')?.addEventListener('click', closeSaleMarkupModal);
document.getElementById('sale-markup-apply')?.addEventListener('click', () => {
  if (!pendingMarkupRow || !pendingMarkupUnitCode) return;
  const tr = pendingMarkupRow;
  const selectedUnit = tr.querySelector('.acc-unit')?.value || '';
  const markupUnit = pendingMarkupUnitCode;
  const purchaseInput = tr.querySelector(`.acc-matrix-purchase[data-unit="${markupUnit}"]`);
  const saleMatrixInput = tr.querySelector(`.acc-matrix-sale[data-unit="${markupUnit}"]`);
  const cost = parseFloat(purchaseInput?.value || 0) || 0;
  const percent = parseFloat(saleMarkupPercent?.value || 0) || 0;
  const saleValue = cost * (1 + (percent / 100));
  const saleMapNow = JSON.parse(tr.dataset.salePrices || '{}');
  saleMapNow[markupUnit] = accPriceNumber(saleValue);
  tr.dataset.salePrices = JSON.stringify(saleMapNow);
  tr.querySelector('.acc-sale-prices-json').value = JSON.stringify(saleMapNow);
  if (saleMatrixInput) {
    saleMatrixInput.value = accFormatPrice(saleValue);
  }
  if (selectedUnit === markupUnit) {
    const saleInput = tr.querySelector('.acc-sale');
    if (saleInput) {
      saleInput.value = accFormatPrice(saleValue);
    }
    tr.dataset.saleBase = ((saleValue || 0) * Math.max(1, currentAccRatio(tr))).toFixed(6);
    tr.dataset.saleAnchorUnit = selectedUnit;
  } else if (!tr.dataset.saleAnchorUnit) {
    tr.dataset.saleAnchorUnit = markupUnit;
  }
  closeSaleMarkupModal();
  recalcRow(tr, tr.rowIndex - 1);
});
</script>

<?php else: ?>
<?php include __DIR__ . '/../../views/layouts/footer.php'; ?>
<script src="https://unpkg.com/feather-icons/dist/feather.min.js"></script>
<script>feather.replace();</script>
<?php endif; ?>
