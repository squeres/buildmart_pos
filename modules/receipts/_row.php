<?php
/**
 * _row.php - Goods Receipt Item Row Partial
 * Variables: $idx, $item, $receiptProductsById, $unitOptions
 */
$unitOptions = $unitOptions ?? unit_options();
$canCreateProducts = $canCreateProducts ?? true;
$canManageReceiptProducts = $canManageReceiptProducts ?? true;
$canEditProducts = $canEditProducts ?? true;
$receiptProductsById = $receiptProductsById ?? [];
$rowUnitOptions = $unitOptions;
$productRow = null;

if (!empty($item['product_id']) && isset($receiptProductsById[(int)$item['product_id']])) {
    $productRow = $receiptProductsById[(int)$item['product_id']];
}

if ($productRow) {
    $rowUnitOptions = [];
    foreach (product_units((int)$productRow['id'], $productRow['unit']) as $unitRow) {
        $rowUnitOptions[$unitRow['unit_code']] = product_unit_label_text($unitRow);
    }
}

$selectedProductLabel = $productRow
    ? product_name($productRow) . (!empty($productRow['sku']) ? ' [' . (string)$productRow['sku'] . ']' : '')
    : '';
$selectedProductMeta = $productRow
    ? trim(
        ($productRow['sku'] ? __('lbl_sku') . ': ' . (string)$productRow['sku'] : '')
        . ($productRow['barcode'] ? (($productRow['sku'] ? ' · ' : '') . __('lbl_barcode') . ': ' . (string)$productRow['barcode']) : '')
    )
    : '';
?>
<td class="row-num text-muted fs-sm text-center"><?= is_int($idx) ? $idx + 1 : '' ?></td>
<td class="receipt-row-product-cell">
  <input type="hidden" name="items[<?= $idx ?>][id]" value="<?= (int)($item['id'] ?? 0) ?>">
  <input type="hidden" name="items[<?= $idx ?>][unit_prices_json]" class="row-unit-prices-json" value="<?= e($item['unit_prices_json'] ?? '') ?>">
  <input type="hidden" name="items[<?= $idx ?>][sale_prices_json]" class="row-sale-prices-json" value="<?= e($item['sale_prices_json'] ?? '') ?>">
  <input type="hidden" name="items[<?= $idx ?>][product_id]" class="row-product-select" value="<?= e((string)($item['product_id'] ?? '')) ?>">

  <div class="receipt-row-toolbar">
    <div class="product-search-field receipt-product-search">
      <div class="product-search-main">
        <input
          type="text"
          class="form-control form-control-sm row-product-search-input"
          value="<?= e($selectedProductLabel) ?>"
          placeholder="<?= e(__('gr_product_search_ph')) ?>"
          autocomplete="off"
          spellcheck="false"
        >
        <div class="product-search-results hidden row-product-results"></div>
        <div class="receipt-product-search-label row-product-selected-meta"><?= e($selectedProductMeta) ?></div>
      </div>
      <div class="receipt-row-toolbar-actions product-field-actions">
        <button type="button" class="btn btn-sm btn-primary receipt-row-auto-btn btn-row-calc"
                title="<?= e(__('btn_auto')) ?>">
          <?= e(__('btn_auto')) ?>
        </button>
        <button type="button" class="product-field-icon receipt-row-side-btn btn-product-picker-row"
                title="<?= e(__('gr_product_picker_open')) ?>">
          <?= feather_icon('list', 13) ?>
        </button>
        <button type="button" class="product-field-icon receipt-row-side-btn product-camera-trigger btn-product-camera-row"
                title="<?= e(__('camera_scan_title')) ?>"
                hidden>
          <?= feather_icon('camera', 13) ?>
        </button>
        <?php if ($canCreateProducts): ?>
        <button type="button" class="product-field-icon btn-new-product-row receipt-row-side-btn"
                title="<?= e(__('gr_quick_add_product')) ?>">
          <?= feather_icon('plus', 13) ?>
        </button>
        <?php endif; ?>
        <?php if ($canEditProducts): ?>
        <button type="button" class="product-field-icon btn-edit-product-row receipt-row-side-btn"
                title="<?= e(__('btn_edit')) ?>"
                <?= empty($item['product_id']) ? 'disabled' : '' ?>>
          <?= feather_icon('edit-2', 13) ?>
        </button>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <input type="text" name="items[<?= $idx ?>][name]" class="form-control form-control-sm row-name"
         value="<?= e($item['name'] ?? '') ?>"
         placeholder="<?= __('gr_item_name_ph') ?>" maxlength="250">
  <div class="row-unit-matrix"></div>
</td>
<td>
  <select name="items[<?= $idx ?>][unit]" class="form-control form-control-sm row-unit" style="display:none">
    <?php foreach ($rowUnitOptions as $uKey => $uLabel): ?>
      <option value="<?= $uKey ?>" <?= ($item['unit'] ?? 'pcs') === $uKey ? 'selected' : '' ?>><?= e($uLabel) ?></option>
    <?php endforeach; ?>
  </select>
  <div class="row-selected-unit text-muted receipt-row-selected-unit"><?= e($rowUnitOptions[$item['unit'] ?? 'pcs'] ?? unit_label($item['unit'] ?? 'pcs')) ?></div>
</td>
<td>
  <input type="number" name="items[<?= $idx ?>][qty]" class="form-control form-control-sm row-qty text-right"
         value="<?= e($item['qty'] ?? 1) ?>" min="0.001" step="0.001" required>
</td>
<td>
  <input type="number" name="items[<?= $idx ?>][unit_price]" class="form-control form-control-sm row-price text-right"
         value="<?= e(number_format((float)($item['unit_price'] ?? 0), 2, '.', '')) ?>" min="0" step="0.01" required>
</td>
<td>
  <input type="number" name="items[<?= $idx ?>][tax_rate]" class="form-control form-control-sm row-tax receipt-row-tax text-right"
         value="<?= e($item['tax_rate'] ?? 0) ?>" min="0" max="100" step="0.01">
</td>
<td class="col-num row-total text-right fw-600">
  <?= number_format((float)($item['line_total'] ?? 0), 2, '.', '') ?>
</td>
<td>
  <input type="text" name="items[<?= $idx ?>][notes]" class="form-control form-control-sm row-notes"
         value="<?= e($item['notes'] ?? '') ?>" placeholder="..." maxlength="255">
</td>
<td>
  <button type="button" class="btn btn-sm btn-danger btn-icon btn-del-row">
    <?= feather_icon('trash-2', 14) ?>
  </button>
</td>
