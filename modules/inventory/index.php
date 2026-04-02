<?php
require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../views/partials/icons.php';
Auth::requireLogin();
Auth::requirePerm('inventory');

$pageTitle   = __('inv_title');
$breadcrumbs = [[$pageTitle, null]];

$search  = sanitize($_GET['search'] ?? '');
$catId   = (int)($_GET['cat']    ?? 0);
$filter  = sanitize($_GET['filter'] ?? '');
$page    = max(1, (int)($_GET['page'] ?? 1));

// ── Accessible warehouses ────────────────────────────────────────
$myWarehouses = user_warehouses();
$myWhIds      = array_column($myWarehouses, 'id');
$whIn         = implode(',', array_fill(0, count($myWhIds), '?'));

$where  = ['1=1'];
$params = [];

if ($search !== '') {
    $like = '%'.$search.'%';
    $where[] = '(p.name_en LIKE ? OR p.name_ru LIKE ? OR p.sku LIKE ?)';
    array_push($params, $like, $like, $like);
}
if ($catId > 0) { $where[] = 'p.category_id=?'; $params[] = $catId; }

// Per-warehouse low/out filters
if ($filter === 'low') {
    // Product is "low" on at least one accessible warehouse
    $where[] = "EXISTS (
        SELECT 1 FROM stock_balances sb
        WHERE sb.product_id = p.id
          AND sb.warehouse_id IN ($whIn)
          AND sb.qty > 0
          AND p.min_stock_qty > 0
          AND sb.qty <= p.min_stock_qty
    )";
    $params = array_merge($params, $myWhIds);
}
if ($filter === 'out') {
    // Product is "out" on ALL accessible warehouses (total = 0)
    $where[] = "COALESCE((
        SELECT SUM(sb.qty) FROM stock_balances sb
        WHERE sb.product_id = p.id AND sb.warehouse_id IN ($whIn)
    ), 0) <= 0";
    $params = array_merge($params, $myWhIds);
}

$where[] = 'p.is_active=1';
$whereSQL = implode(' AND ', $where);
$total    = (int)Database::value("SELECT COUNT(*) FROM products p WHERE $whereSQL", $params);
$pg       = paginate($total, $page);

$products = Database::all(
    "SELECT p.id, p.name_en, p.name_ru, p.sku, p.unit, p.sale_price, p.cost_price,
            p.stock_qty, p.min_stock_qty, p.min_stock_display_unit_code,
            " . replenishment_product_select_sql('p') . ",
            c.name_en AS cat_en, c.name_ru AS cat_ru
     FROM products p
     JOIN categories c ON c.id = p.category_id
     WHERE $whereSQL
     ORDER BY p.name_en
     LIMIT {$pg['perPage']} OFFSET {$pg['offset']}",
    $params
);
$displayMap = [];
foreach ($products as $productRow) {
    $units = product_units((int)$productRow['id'], $productRow['unit']);
    $displayMap[(int)$productRow['id']] = [
        'units' => $units,
        'default_unit' => product_default_unit((int)$productRow['id'], $productRow['unit']),
        'overrides' => product_unit_price_overrides((int)$productRow['id']),
        'prices' => UISettings::productPrices((int)$productRow['id']),
        'min_stock' => product_min_stock_data($productRow, $units),
        'target_stock' => product_target_stock_data($productRow, $units),
    ];
}

// Pre-fetch per-warehouse balances for this page of products
$productIds = array_column($products, 'id');
$balanceMap = [];
if ($productIds && $myWhIds) {
    $pIn   = implode(',', array_fill(0, count($productIds), '?'));
    $bRows = Database::all(
        "SELECT product_id, warehouse_id, qty
         FROM stock_balances
         WHERE product_id IN ($pIn) AND warehouse_id IN ($whIn)",
        array_merge($productIds, $myWhIds)
    );
    foreach ($bRows as $br) {
        $balanceMap[$br['product_id']][$br['warehouse_id']] = (float)$br['qty'];
    }
}

// Per-warehouse low/out counts for alerts
$lowCount = (int)Database::value(
    "SELECT COUNT(DISTINCT sb.product_id)
     FROM stock_balances sb JOIN products p ON p.id = sb.product_id
     WHERE p.is_active=1 AND p.min_stock_qty>0
       AND sb.qty > 0 AND sb.qty <= p.min_stock_qty
       AND sb.warehouse_id IN ($whIn)",
    $myWhIds
);
$outCount = (int)Database::value(
    "SELECT COUNT(*) FROM products p
     WHERE p.is_active=1
       AND COALESCE((SELECT SUM(sb.qty) FROM stock_balances sb
                     WHERE sb.product_id=p.id AND sb.warehouse_id IN ($whIn)), 0) <= 0",
    $myWhIds
);

$cats = Database::all("SELECT id,name_en,name_ru FROM categories WHERE is_active=1 ORDER BY name_en");

include __DIR__ . '/../../views/layouts/header.php';
?>

<div class="page-header">
  <h1 class="page-heading"><?= __('inv_title') ?></h1>
  <div class="page-actions">
    <a href="<?= url('modules/inventory/needs.php') ?>" class="btn btn-ghost"><?= feather_icon('alert-triangle',15) ?> <?= __('repl_needs_title') ?></a>
    <a href="<?= url('modules/inventory/receive.php') ?>"  class="btn btn-primary"><?= feather_icon('plus',15) ?> <?= __('inv_receive') ?></a>
    <a href="<?= url('modules/inventory/adjust.php') ?>"   class="btn btn-secondary"><?= feather_icon('sliders',15) ?> <?= __('inv_adjust') ?></a>
    <a href="<?= url('modules/inventory/writeoff.php') ?>" class="btn btn-secondary"><?= feather_icon('trash-2',15) ?> <?= __('inv_writeoff') ?></a>
    <a href="<?= url('modules/inventory/history.php') ?>"  class="btn btn-ghost"><?= feather_icon('clock',15) ?> <?= __('inv_history') ?></a>
  </div>
</div>

<!-- Alert banners -->
<?php if ($outCount > 0): ?>
<div class="flash flash-error mb-2">
  <?= feather_icon('x-circle',15) ?>
  <span><?= $outCount ?> product(s) are <strong><?= __('out_of_stock') ?></strong></span>
  <a href="?filter=out" class="btn btn-sm btn-outline" style="margin-left:auto"><?= __('btn_view') ?></a>
</div>
<?php endif; ?>
<?php if ($lowCount > 0): ?>
<div class="flash flash-warning mb-2">
  <?= feather_icon('alert-triangle',15) ?>
  <span><?= $lowCount ?> product(s) are <strong><?= __('low_stock') ?></strong></span>
  <a href="?filter=low" class="btn btn-sm btn-outline" style="margin-left:auto"><?= __('btn_view') ?></a>
</div>
<?php endif; ?>

<!-- Filters -->
<form method="GET" class="filter-bar mb-2">
  <input type="text" name="search" class="form-control" placeholder="<?= __('btn_search') ?>…" value="<?= e($search) ?>" style="max-width:200px">
  <select name="cat" class="form-control" style="max-width:180px">
    <option value=""><?= __('lbl_all') ?></option>
    <?php foreach ($cats as $c): ?>
      <option value="<?= $c['id'] ?>" <?= $catId==$c['id']?'selected':'' ?>><?= e(category_name($c)) ?></option>
    <?php endforeach; ?>
  </select>
  <select name="filter" class="form-control" style="max-width:150px">
    <option value=""><?= __('lbl_all') ?></option>
    <option value="low" <?= $filter=='low'?'selected':'' ?>><?= __('low_stock') ?></option>
    <option value="out" <?= $filter=='out'?'selected':'' ?>><?= __('out_of_stock') ?></option>
  </select>
  <button type="submit" class="btn btn-secondary"><?= feather_icon('search',14) ?></button>
  <a href="<?= url('modules/inventory/') ?>" class="btn btn-ghost"><?= __('btn_reset') ?></a>
</form>

<div class="card">
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th><?= __('lbl_name') ?></th>
          <th><?= __('lbl_sku') ?></th>
          <th><?= __('lbl_category') ?></th>
          <?php foreach ($myWarehouses as $wh): ?>
            <th class="col-num" style="white-space:nowrap" title="<?= e($wh['name']) ?>">
              <?= e(mb_strlen($wh['name']) > 12 ? mb_substr($wh['name'],0,10).'…' : $wh['name']) ?>
            </th>
          <?php endforeach; ?>
          <th class="col-num"><?= __('wh_balance_total') ?></th>
          <th class="col-num"><?= __('prod_min_stock') ?></th>
          <th class="col-num"><?= __('prod_sale_price') ?></th>
          <th><?= __('lbl_status') ?></th>
          <th class="col-actions"><?= __('lbl_actions') ?></th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$products): ?>
          <tr><td colspan="<?= 7 + count($myWarehouses) ?>" class="text-center text-muted" style="padding:40px"><?= __('no_results') ?></td></tr>
        <?php else: ?>
          <?php foreach ($products as $p): ?>
          <?php
            // Per-warehouse quantities for this product
            $whQtys    = [];
            foreach ($myWarehouses as $wh) {
                $whQtys[$wh['id']] = $balanceMap[$p['id']][$wh['id']] ?? 0.0;
            }
            $totalWhQty = array_sum($whQtys);
            $minQty     = (float)$p['min_stock_qty'];
            $replenishmentMeta = replenishment_class_meta($p['replenishment_class'] ?? 'C');

            // "Low" = any warehouse has qty > 0 but <= min
            $isLow = $minQty > 0 && (function() use ($whQtys, $minQty) {
                foreach ($whQtys as $q) {
                    if ($q > 0 && $q <= $minQty) return true;
                }
                return false;
            })();
            $isOut = $totalWhQty <= 0;
          ?>
          <tr>
            <td class="fw-600"><?= e(product_name($p)) ?></td>
            <td class="font-mono" style="font-size:12px"><?= e($p['sku']) ?></td>
            <td><?= e(category_name(['name_en'=>$p['cat_en'],'name_ru'=>$p['cat_ru']])) ?></td>
            <?php foreach ($myWarehouses as $wh): ?>
              <?php $q = $whQtys[$wh['id']]; ?>
              <td class="col-num" style="font-family:monospace;<?=
                ($minQty > 0 && $q > 0 && $q <= $minQty) ? 'color:var(--warning);font-weight:600' :
                ($q <= 0 ? 'color:var(--border-medium)' : '') ?>">
                <?= $q > 0 ? e(product_stock_breakdown($q, $displayMap[(int)$p['id']]['units'], $p['unit'])) : '—' ?>
              </td>
            <?php endforeach; ?>
            <td class="col-num fw-600" style="font-family:monospace"><?= e(product_stock_breakdown($totalWhQty, $displayMap[(int)$p['id']]['units'], $p['unit'])) ?></td>
            <td class="col-num text-muted"><?= $minQty > 0 ? e($displayMap[(int)$p['id']]['min_stock']['full_text']) : '—' ?></td>
            <td class="col-num">
              <?php
                $displayUnit = $displayMap[(int)$p['id']]['default_unit'];
                $retailBase = (float)($displayMap[(int)$p['id']]['prices']['retail'] ?? $p['sale_price']);
                $retailDisplay = $retailBase > 0
                  ? product_unit_price((int)$p['id'], $displayUnit['unit_code'], 'retail', $retailBase, $displayMap[(int)$p['id']]['units'], $displayMap[(int)$p['id']]['overrides'])
                  : 0;
              ?>
              <?= $retailDisplay > 0 ? money($retailDisplay) : '—' ?>
            </td>
            <td>
              <?= $isOut
                  ? '<span class="badge badge-danger">'.__('out_of_stock').'</span>'
                  : ($isLow ? '<span class="badge badge-' . e($replenishmentMeta['badge_class']) . '">'.__('low_stock').'</span>'
                             : '<span class="badge badge-success">'.__('lbl_active').'</span>') ?>
              <?php if ($minQty > 0): ?>
                <div style="margin-top:4px"><?= replenishment_class_badge($p['replenishment_class'] ?? 'C') ?></div>
              <?php endif; ?>
            </td>
            <td class="col-actions">
              <?php if (Auth::can('inventory')): ?>
                <button
                  type="button"
                  class="btn btn-sm btn-secondary js-edit-stock"
                  title="<?= __('btn_edit') ?>"
                  data-id="<?= (int)$p['id'] ?>"
                  data-name="<?= e(product_name($p)) ?>"
                  data-min-stock="<?= e((string)$displayMap[(int)$p['id']]['min_stock']['display_qty']) ?>"
                  data-min-stock-unit="<?= e((string)$displayMap[(int)$p['id']]['min_stock']['display_unit_code']) ?>"
                  data-min-stock-base="<?= e((string)$displayMap[(int)$p['id']]['min_stock']['base_text']) ?>"
                  data-repl-class="<?= e((string)replenishment_class_normalize($p['replenishment_class'] ?? 'C')) ?>"
                  data-target-stock="<?= e((string)$displayMap[(int)$p['id']]['target_stock']['display_qty']) ?>"
                  data-target-stock-unit="<?= e((string)$displayMap[(int)$p['id']]['target_stock']['display_unit_code']) ?>"
                  data-target-stock-base="<?= e((string)$displayMap[(int)$p['id']]['target_stock']['base_text']) ?>"
                  data-unit-options="<?= e(json_encode(array_map(static fn($unit) => [
                    'code' => (string)$unit['unit_code'],
                    'label' => product_unit_label_text($unit),
                    'ratio' => (float)$unit['ratio_to_base'],
                  ], $displayMap[(int)$p['id']]['units']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>"
                  data-edit-url="<?= e(url('modules/products/edit.php?id=' . (int)$p['id'])) ?>"
                >
                  <?= feather_icon('edit-2',14) ?>
                </button>
              <?php endif; ?>

              <a href="<?= url('modules/inventory/receive.php?product_id='.$p['id']) ?>" class="btn btn-sm btn-ghost" title="<?= __('inv_receive') ?>"><?= feather_icon('plus-circle',14) ?></a>
              <a href="<?= url('modules/inventory/adjust.php?product_id='.$p['id']) ?>" class="btn btn-sm btn-ghost" title="<?= __('inv_adjust') ?>"><?= feather_icon('sliders',14) ?></a>
              <a href="<?= url('modules/inventory/history.php?product_id='.$p['id']) ?>" class="btn btn-sm btn-ghost" title="<?= __('inv_history') ?>"><?= feather_icon('clock',14) ?></a>
            </td>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pg['pages'] > 1): ?>
  <div class="card-footer flex-between">
    <div class="text-secondary fs-sm"><?= __('showing') ?> <?= $pg['offset']+1 ?>–<?= min($pg['offset']+$pg['perPage'],$total) ?> <?= __('of') ?> <?= $total ?></div>
    <div class="pagination">
      <?php for ($i=1;$i<=$pg['pages'];$i++): ?>
        <a href="?<?= http_build_query(array_merge($_GET,['page'=>$i])) ?>" class="page-link <?= $i==$pg['page']?'active':'' ?>"><?= $i ?></a>
      <?php endfor; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<div class="modal-overlay hidden" id="editStockModal">
  <div class="modal modal-sm">
    <div class="modal-header">
      <div class="modal-title"><?= __('inv_edit_product') ?></div>
      <button type="button" class="modal-close" onclick="closeModal('editStockModal')">
        <?= feather_icon('x', 18) ?>
      </button>
    </div>

    <form method="POST" action="<?= url('modules/inventory/update_product.php') ?>">
      <div class="modal-body">
        <?= csrf_field() ?>
        <input type="hidden" name="id" id="editStockProductId">

        <div class="form-group mb-2">
          <label class="form-label"><?= __('lbl_product') ?></label>
          <input type="text" id="editStockProductName" class="form-control" readonly>
        </div>

        <div class="form-group mb-2">
          <label class="form-label"><?= __('prod_min_stock') ?></label>
          <div style="display:grid;grid-template-columns:minmax(0,1fr) 220px;gap:10px">
            <input
              type="number"
              step="0.001"
              min="0"
              name="min_stock_qty"
              id="editStockMinQty"
              class="form-control"
              required
            >
            <select name="min_stock_display_unit_code" id="editStockMinUnit" class="form-control"></select>
          </div>
          <div class="form-hint" id="editStockMinPreview"></div>
          <div class="form-hint" style="margin-top:8px"><?= __('inv_quick_edit_hint') ?></div>
        </div>

        <div class="form-group mb-2">
          <label class="form-label"><?= __('repl_target_stock') ?></label>
          <div style="display:grid;grid-template-columns:minmax(0,1fr) 220px;gap:10px">
            <input
              type="number"
              step="0.001"
              min="0"
              name="target_stock_qty"
              id="editStockTargetQty"
              class="form-control"
            >
            <select name="target_stock_display_unit_code" id="editStockTargetUnit" class="form-control"></select>
          </div>
          <div class="form-hint" id="editStockTargetPreview"></div>
        </div>

        <div class="form-group mb-0">
          <label class="form-label"><?= __('repl_class') ?></label>
          <select name="replenishment_class" id="editStockReplenishmentClass" class="form-control">
            <?php foreach (replenishment_class_options() as $classCode => $classLabel): ?>
              <option value="<?= e($classCode) ?>"><?= e($classCode . ' — ' . $classLabel) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="modal-footer">
        <a href="#" class="btn btn-ghost" id="editStockFullEditLink"><?= __('inv_open_full_edit') ?></a>
        <button type="button" class="btn btn-ghost" onclick="closeModal('editStockModal')"><?= __('btn_cancel') ?></button>
        <button type="submit" class="btn btn-primary"><?= __('btn_save') ?></button>
      </div>
    </form>
  </div>
</div>

<?php include __DIR__ . '/../../views/layouts/footer.php'; ?>
<script src="https://unpkg.com/feather-icons/dist/feather.min.js"></script>
<script>
feather.replace();

(function () {
  const qtyInput = document.getElementById('editStockMinQty');
  const unitSelect = document.getElementById('editStockMinUnit');
  const preview = document.getElementById('editStockMinPreview');
  const targetQtyInput = document.getElementById('editStockTargetQty');
  const targetUnitSelect = document.getElementById('editStockTargetUnit');
  const targetPreview = document.getElementById('editStockTargetPreview');
  const replenishmentClassSelect = document.getElementById('editStockReplenishmentClass');
  const fullEditLink = document.getElementById('editStockFullEditLink');

  function formatQty(value) {
    const num = parseFloat(value || 0) || 0;
    return Number.isInteger(num) ? String(num) : num.toFixed(3).replace(/\.?0+$/, '');
  }

  function renderThresholdPreview(input, select, previewNode, labelText) {
    if (!previewNode) return;
    const units = JSON.parse(select?.dataset.units || '[]');
    const selected = units.find((unit) => unit.code === select.value) || units[0] || { label: '', ratio: 1 };
    const baseUnit = units.find((unit) => (parseFloat(unit.ratio || 1) || 1) === 1) || selected;
    const qty = parseFloat(String(input?.value || '0').replace(',', '.')) || 0;
    const baseQty = qty / Math.max(1, parseFloat(selected.ratio || 1) || 1);
    previewNode.textContent = labelText + ': ' + formatQty(baseQty) + ' ' + (baseUnit.label || '');
  }

  function renderMinStockPreview() {
    renderThresholdPreview(qtyInput, unitSelect, preview, '<?= e(__('prod_min_stock_saved_as')) ?>');
  }

  function renderTargetStockPreview() {
    renderThresholdPreview(targetQtyInput, targetUnitSelect, targetPreview, '<?= e(__('repl_target_stock_saved_as')) ?>');
  }

  document.querySelectorAll('.js-edit-stock').forEach(btn => {
    btn.addEventListener('click', () => {
      const units = JSON.parse(btn.dataset.unitOptions || '[]');
      document.getElementById('editStockProductId').value   = btn.dataset.id || '';
      document.getElementById('editStockProductName').value = btn.dataset.name || '';
      qtyInput.value = btn.dataset.minStock || '0';
      unitSelect.innerHTML = units.map((unit) => `<option value="${unit.code}">${unit.label}</option>`).join('');
      unitSelect.dataset.units = JSON.stringify(units);
      unitSelect.value = units.some((unit) => unit.code === btn.dataset.minStockUnit)
        ? btn.dataset.minStockUnit
        : (units[0]?.code || '');
      unitSelect.disabled = units.length <= 1;
      targetQtyInput.value = btn.dataset.targetStock || '0';
      targetUnitSelect.innerHTML = units.map((unit) => `<option value="${unit.code}">${unit.label}</option>`).join('');
      targetUnitSelect.dataset.units = JSON.stringify(units);
      targetUnitSelect.value = units.some((unit) => unit.code === btn.dataset.targetStockUnit)
        ? btn.dataset.targetStockUnit
        : (unitSelect.value || units[0]?.code || '');
      targetUnitSelect.disabled = units.length <= 1;
      if (replenishmentClassSelect) {
        replenishmentClassSelect.value = btn.dataset.replClass || 'C';
      }
      if (fullEditLink) {
        fullEditLink.href = btn.dataset.editUrl || '#';
      }
      renderMinStockPreview();
      renderTargetStockPreview();
      openModal('editStockModal');
    });
  });

  qtyInput?.addEventListener('input', renderMinStockPreview);
  unitSelect?.addEventListener('change', renderMinStockPreview);
  targetQtyInput?.addEventListener('input', renderTargetStockPreview);
  targetUnitSelect?.addEventListener('change', renderTargetStockPreview);
})();
</script>
