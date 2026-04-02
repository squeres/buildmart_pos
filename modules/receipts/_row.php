<?php
/**
 * _row.php — Goods Receipt Item Row Partial
 * Variables: $idx, $item, $products, $unitOptions
 */
$unitOptions = $unitOptions ?? unit_options();
$rowUnitOptions = $unitOptions;
if (!empty($item['product_id'])) {
    $productRow = null;
    foreach ($products as $candidate) {
        if ((string)$candidate['id'] === (string)$item['product_id']) {
            $productRow = $candidate;
            break;
        }
    }
    if ($productRow) {
        $rowUnitOptions = [];
        foreach (product_units((int)$productRow['id'], $productRow['unit']) as $unitRow) {
            $rowUnitOptions[$unitRow['unit_code']] = product_unit_label_text($unitRow);
        }
    }
}
?>
<td class="row-num" style="color:var(--text-muted);font-size:12px;text-align:center"><?= is_int($idx) ? $idx + 1 : '' ?></td>
<td style="min-width:240px">
  <input type="hidden" name="items[<?= $idx ?>][id]" value="<?= (int)($item['id'] ?? 0) ?>">
  <input type="hidden" name="items[<?= $idx ?>][unit_prices_json]" class="row-unit-prices-json" value="<?= e($item['unit_prices_json'] ?? '') ?>">
  <input type="hidden" name="items[<?= $idx ?>][sale_prices_json]" class="row-sale-prices-json" value="<?= e($item['sale_prices_json'] ?? '') ?>">

  <!-- Product select + quick-create button -->
  <div style="display:flex;gap:5px;align-items:center;margin-bottom:4px">
    <select name="items[<?= $idx ?>][product_id]" class="form-control form-control-sm row-product-select" style="flex:1">
      <option value=""><?= __('gr_select_product') ?></option>
      <?php foreach ($products as $p): ?>
        <option value="<?= $p['id'] ?>"
                <?= (string)($item['product_id'] ?? '') === (string)$p['id'] ? 'selected' : '' ?>>
          <?= e(product_name($p)) ?> [<?= e($p['sku']) ?>]
        </option>
      <?php endforeach; ?>
    </select>
    <button type="button" class="btn btn-sm btn-primary btn-row-calc"
            title="<?= __('btn_auto') ?>"
            style="flex-shrink:0;height:28px;padding:0 10px;display:none;align-items:center;justify-content:center">
      <?= e(__('btn_auto')) ?>
    </button>
    <button type="button" class="btn-qc btn-new-product-row"
            title="<?= __('gr_quick_add_product') ?>"
            style="flex-shrink:0;width:28px;height:28px">
      <?= feather_icon('plus', 13) ?>
    </button>
    <button type="button" class="btn-qc btn-edit-product-row"
            title="<?= __('btn_edit') ?>"
            style="flex-shrink:0;width:28px;height:28px"
            <?= empty($item['product_id']) ? 'disabled' : '' ?>>
      <?= feather_icon('edit-2', 13) ?>
    </button>
  </div>

  <!-- Manual name override -->
  <input type="text" name="items[<?= $idx ?>][name]" class="form-control form-control-sm row-name"
         value="<?= e($item['name'] ?? '') ?>"
         placeholder="<?= __('gr_item_name_ph') ?>" maxlength="250">
  <div class="row-unit-matrix" style="display:flex;flex-direction:column;gap:6px;margin-top:8px"></div>
</td>
<td>
  <select name="items[<?= $idx ?>][unit]" class="form-control form-control-sm row-unit" style="display:none">
    <?php foreach ($rowUnitOptions as $uKey => $uLabel): ?>
      <option value="<?= $uKey ?>" <?= ($item['unit'] ?? 'pcs') === $uKey ? 'selected' : '' ?>><?= e($uLabel) ?></option>
    <?php endforeach; ?>
  </select>
  <div class="row-selected-unit text-muted" style="font-size:12px"><?= e($rowUnitOptions[$item['unit'] ?? 'pcs'] ?? unit_label($item['unit'] ?? 'pcs')) ?></div>
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
  <input type="number" name="items[<?= $idx ?>][tax_rate]" class="form-control form-control-sm row-tax text-right"
         value="<?= e($item['tax_rate'] ?? 0) ?>" min="0" max="100" step="0.01" style="min-width:96px">
</td>
<td class="col-num row-total text-right fw-600">
  <?= number_format((float)($item['line_total'] ?? 0), 2, '.', '') ?>
</td>
<td>
  <input type="text" name="items[<?= $idx ?>][notes]" class="form-control form-control-sm row-notes"
         value="<?= e($item['notes'] ?? '') ?>" placeholder="…" maxlength="255">
</td>
<td>
  <button type="button" class="btn btn-sm btn-ghost btn-icon btn-del-row" style="color:var(--danger)">
    <?= feather_icon('trash-2', 14) ?>
  </button>
</td>
