<?php
/**
 * modules/transfers/create.php
 * Create a new stock transfer document (draft).
 */
require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../views/partials/icons.php';
Auth::requireLogin();
Auth::requirePerm('transfers.create');

$pageTitle   = __('tr_create');
$breadcrumbs = [[__('tr_title'), url('modules/transfers/')], [$pageTitle, null]];

$myWarehouses = user_warehouses();
$allWh = Database::all("SELECT id,name FROM warehouses WHERE is_active=1 ORDER BY name");
$preferredFromWhId = selected_warehouse_id();
if ($preferredFromWhId <= 0 || !user_can_access_warehouse($preferredFromWhId)) {
    $preferredFromWhId = 0;
}
$errors = [];
$initialRows = [];

$productRows = Database::all(
    "SELECT id, name_en, name_ru, sku, unit, is_weighable
     FROM products
     WHERE is_active=1
     ORDER BY name_en"
);

$productIds = array_map(static fn(array $row): int => (int)$row['id'], $productRows);
$warehouseIds = array_map(static fn(array $row): int => (int)$row['id'], $myWarehouses);
$stockByProductWarehouse = [];
if ($productIds && $warehouseIds) {
    $pIn = implode(',', array_fill(0, count($productIds), '?'));
    $wIn = implode(',', array_fill(0, count($warehouseIds), '?'));
    $stockRows = Database::all(
        "SELECT product_id, warehouse_id, qty
         FROM stock_balances
         WHERE qty > 0
           AND product_id IN ($pIn)
           AND warehouse_id IN ($wIn)",
        array_merge($productIds, $warehouseIds)
    );
    foreach ($stockRows as $stockRow) {
        $stockByProductWarehouse[(int)$stockRow['product_id']][(int)$stockRow['warehouse_id']] = (float)$stockRow['qty'];
    }
}

$productsById = [];
$productPayload = [];
foreach ($productRows as $productRow) {
    $productId = (int)$productRow['id'];
    $units = product_units($productId, (string)$productRow['unit']);
    $defaultUnit = product_default_unit($productId, (string)$productRow['unit']);
    $productPayload[$productId] = [
        'id' => $productId,
        'name' => product_name($productRow),
        'sku' => (string)$productRow['sku'],
        'base_unit' => (string)$productRow['unit'],
        'base_unit_label' => product_unit_label_text(product_resolve_unit($units, (string)$productRow['unit'], (string)$productRow['unit'])),
        'default_unit' => (string)$defaultUnit['unit_code'],
        'stock_by_warehouse' => $stockByProductWarehouse[$productId] ?? [],
        'units' => array_values(array_map(static function (array $unitRow) use ($units, $productRow): array {
            return [
                'unit_code' => (string)$unitRow['unit_code'],
                'unit_label' => product_unit_label_text($unitRow),
                'ratio_to_base' => (float)$unitRow['ratio_to_base'],
                'sort_order' => (int)($unitRow['sort_order'] ?? 0),
                'allow_fractional' => product_unit_allows_fractional($unitRow, $units, !empty($productRow['is_weighable'])),
            ];
        }, $units)),
    ];
    $productsById[$productId] = $productRow;
}

if (is_post()) {
    if (!csrf_verify()) {
        flash_error(_r('err_csrf'));
        redirect($_SERVER['REQUEST_URI']);
    }

    $fromWhId = (int)($_POST['from_warehouse_id'] ?? $preferredFromWhId);
    $toWhId   = (int)($_POST['to_warehouse_id'] ?? 0);
    $docDate  = sanitize($_POST['doc_date'] ?? date('Y-m-d'));
    $notes    = sanitize($_POST['notes'] ?? '');
    $items    = $_POST['items'] ?? [];

    if (!$fromWhId) {
        $errors['from_warehouse_id'] = _r('lbl_required');
    }
    if (!$toWhId) {
        $errors['to_warehouse_id'] = _r('lbl_required');
    }
    if ($fromWhId && $toWhId && $fromWhId === $toWhId) {
        $errors['to_warehouse_id'] = __('tr_err_same_wh');
    }
    if ($fromWhId && !user_can_access_warehouse($fromWhId)) {
        $errors['from_warehouse_id'] = __('tr_err_no_access');
    }

    $validItems = [];
    foreach ($items as $row) {
        $pid = (int)($row['product_id'] ?? 0);
        $qty = sanitize_float($row['qty'] ?? 0);
        $unitCode = sanitize($row['unit_code'] ?? $row['unit'] ?? '');

        $initialRows[] = [
            'product_id' => $pid,
            'qty' => $qty > 0 ? number_format($qty, 3, '.', '') : '',
            'unit_code' => $unitCode,
        ];

        if (!$pid || $qty <= 0) {
            continue;
        }

        $productRow = $productsById[$pid] ?? null;
        if (!$productRow) {
            $errors['items'] = __('tr_err_invalid_product');
            continue;
        }

        $units = product_units($pid, (string)$productRow['unit']);
        $resolvedUnit = product_resolve_unit($units, (string)$productRow['unit'], $unitCode);
        $allowFractional = product_unit_allows_fractional($resolvedUnit, $units, !empty($productRow['is_weighable']));
        if (!$allowFractional && abs($qty - round($qty)) > 0.000001) {
            $errors['items'] = sprintf(
                __('tr_err_fractional_not_allowed'),
                product_name($productRow),
                product_unit_label_text($resolvedUnit)
            );
            continue;
        }

        $validItems[] = [
            'product_id' => $pid,
            'product_name' => product_name($productRow),
            'unit_code' => (string)$resolvedUnit['unit_code'],
            'unit_label' => product_unit_label_text($resolvedUnit),
            'qty' => stock_qty_round($qty),
            'qty_base' => product_qty_to_base_unit($qty, $units, (string)$productRow['unit'], (string)$resolvedUnit['unit_code']),
        ];
    }

    if (empty($validItems)) {
        $errors['items'] = $errors['items'] ?? __('tr_err_no_items');
    }

    if (!$errors && $fromWhId > 0) {
        $requiredByProduct = [];
        foreach ($validItems as $item) {
            $requiredByProduct[$item['product_id']] = ($requiredByProduct[$item['product_id']] ?? 0.0) + (float)$item['qty_base'];
        }
        foreach ($requiredByProduct as $productId => $requiredQtyBase) {
            $productRow = $productsById[$productId] ?? null;
            if (!$productRow) {
                continue;
            }
            $units = product_units($productId, (string)$productRow['unit']);
            $availableQtyBase = get_stock_qty($productId, $fromWhId);
            if ($availableQtyBase + 0.000001 < $requiredQtyBase) {
                $errors['items'] = sprintf(
                    __('tr_err_insufficient'),
                    product_name($productRow),
                    product_stock_breakdown($availableQtyBase, $units, (string)$productRow['unit']),
                    product_stock_breakdown($requiredQtyBase, $units, (string)$productRow['unit'])
                );
                break;
            }
        }
    }

    if (!$errors) {
        try {
            Database::beginTransaction();

            $transferId = Database::insert(
                "INSERT INTO stock_transfers
                    (doc_no, doc_date, from_warehouse_id, to_warehouse_id, notes, status, created_by)
                 VALUES (?,?,?,?,?,'draft',?)",
                [generate_transfer_no(), $docDate, $fromWhId, $toWhId, $notes, Auth::id()]
            );

            foreach (array_values($validItems) as $i => $item) {
                Database::insert(
                    "INSERT INTO stock_transfer_items
                        (transfer_id, product_id, product_name, unit, unit_label, qty, qty_base, sort_order)
                     VALUES (?,?,?,?,?,?,?,?)",
                    [
                        $transferId,
                        $item['product_id'],
                        $item['product_name'],
                        $item['unit_code'],
                        $item['unit_label'],
                        $item['qty'],
                        $item['qty_base'],
                        $i,
                    ]
                );
            }

            Database::commit();
            flash_success(_r('tr_saved'));
            redirect('/modules/transfers/view.php?id=' . $transferId);
        } catch (\Throwable $e) {
            Database::rollback();
            error_log($e->__toString());
            flash_error(_r('err_db'));
        }
    }
}

$initialRows = $initialRows ?: [['product_id' => 0, 'qty' => '', 'unit_code' => '']];

include __DIR__ . '/../../views/layouts/header.php';
?>

<style>
.transfer-product-cell {
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.transfer-product-tools {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}
.transfer-product-tools .cart-unit-tooltip {
  top: auto;
  bottom: calc(100% + 8px);
  left: 0;
}
.transfer-add-unit {
  gap: 6px;
  white-space: nowrap;
}
.transfer-stock-breakdown {
  font-family: var(--font-mono);
  font-size: 12px;
}
.transfer-stock-selected {
  font-size: 11px;
  margin-top: 4px;
}
.transfer-row-remove {
  text-align: right;
}
@media (max-width: 900px) {
  #items-table.transfer-editor-table thead {
    display: none;
  }
  #items-table.transfer-editor-table,
  #items-table.transfer-editor-table tbody,
  #items-table.transfer-editor-table tr,
  #items-table.transfer-editor-table td {
    display: block;
    width: 100%;
  }
  #items-table.transfer-editor-table tbody {
    display: grid;
    gap: 12px;
  }
  #items-table.transfer-editor-table tbody tr {
    padding: 12px;
    border: 1px solid var(--border-soft);
    border-radius: 12px;
    background: var(--bg-raised);
  }
  #items-table.transfer-editor-table td {
    padding: 0;
    border: 0;
  }
  #items-table.transfer-editor-table td + td {
    margin-top: 10px;
  }
  #items-table.transfer-editor-table td::before {
    content: attr(data-label);
    display: block;
    margin-bottom: 6px;
    font-size: 11px;
    letter-spacing: .04em;
    text-transform: uppercase;
    color: var(--text-muted);
  }
  #items-table.transfer-editor-table td[data-label=""]::before {
    display: none;
  }
  .transfer-row-remove .btn {
    width: 100%;
    justify-content: center;
  }
}
</style>

<div class="page-header">
  <h1 class="page-heading"><?= __('tr_create') ?></h1>
  <div class="page-actions">
    <a href="<?= url('modules/transfers/') ?>" class="btn btn-ghost">
      <?= feather_icon('arrow-left', 14) ?> <?= __('btn_back') ?>
    </a>
  </div>
</div>

<?php if ($errors): ?>
<div class="flash flash-error mb-2"><?= feather_icon('alert-circle', 15) ?> <span><?= __('err_validation') ?></span></div>
<?php endif; ?>

<form method="POST" id="transfer-form">
  <?= csrf_field() ?>

  <div class="content-split content-split-sidebar">
    <div class="card">
      <div class="card-header"><span class="card-title"><?= __('tr_header') ?></span></div>
      <div class="card-body">
        <div class="form-row form-row-3">
          <div class="form-group">
            <label class="form-label"><?= __('tr_from_wh') ?> <span class="req">*</span></label>
            <select name="from_warehouse_id" id="fromWh" class="form-control" required>
              <option value=""><?= __('lbl_select') ?>...</option>
              <?php foreach ($myWarehouses as $w): ?>
                <option value="<?= $w['id'] ?>" <?= ((int)($_POST['from_warehouse_id'] ?? $preferredFromWhId) === (int)$w['id']) ? 'selected' : '' ?>>
                  <?= e($w['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <?php if (isset($errors['from_warehouse_id'])): ?>
              <div class="form-error"><?= e($errors['from_warehouse_id']) ?></div>
            <?php endif; ?>
          </div>

          <div class="form-group">
            <label class="form-label"><?= __('tr_to_wh') ?> <span class="req">*</span></label>
            <select name="to_warehouse_id" class="form-control" required>
              <option value=""><?= __('lbl_select') ?>...</option>
              <?php foreach ($allWh as $w): ?>
                <option value="<?= $w['id'] ?>" <?= (($_POST['to_warehouse_id'] ?? 0) == $w['id']) ? 'selected' : '' ?>>
                  <?= e($w['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <?php if (isset($errors['to_warehouse_id'])): ?>
              <div class="form-error"><?= e($errors['to_warehouse_id']) ?></div>
            <?php endif; ?>
          </div>

          <div class="form-group">
            <label class="form-label"><?= __('lbl_date') ?></label>
            <input type="date" name="doc_date" class="form-control" value="<?= e($_POST['doc_date'] ?? date('Y-m-d')) ?>">
          </div>
        </div>

        <div class="form-group">
          <label class="form-label"><?= __('lbl_notes') ?></label>
          <textarea name="notes" class="form-control" rows="2"><?= e($_POST['notes'] ?? '') ?></textarea>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-body">
        <p class="text-muted fs-sm mb-0">
          <?= feather_icon('info', 13) ?>
          <?= __('tr_create_hint') ?>
        </p>
      </div>
    </div>
  </div>

  <div class="card mt-2">
    <div class="card-header flex-between">
      <span class="card-title"><?= __('tr_items') ?></span>
      <button type="button" class="btn btn-secondary btn-sm" id="addRow">
        <?= feather_icon('plus', 13) ?> <?= __('tr_add_item') ?>
      </button>
    </div>
    <?php if (isset($errors['items'])): ?>
      <div style="padding:8px 16px"><div class="form-error"><?= e($errors['items']) ?></div></div>
    <?php endif; ?>
    <div id="transfer-items-client-error" style="display:none;padding:8px 16px">
      <div class="form-error" id="transfer-items-client-error-text"></div>
    </div>
    <div class="table-wrap mobile-table-scroll">
      <table class="table transfer-editor-table" id="items-table">
        <thead>
          <tr>
            <th style="width:36%"><?= __('lbl_name') ?></th>
            <th class="col-num" style="width:24%"><?= __('tr_available') ?></th>
            <th class="col-num" style="width:18%"><?= __('lbl_qty') ?></th>
            <th style="width:16%"><?= __('tr_transfer_unit') ?></th>
            <th style="width:6%"></th>
          </tr>
        </thead>
        <tbody id="items-body"></tbody>
      </table>
    </div>
    <div class="card-footer stacked-actions">
      <a href="<?= url('modules/transfers/') ?>" class="btn btn-ghost btn-lg"><?= __('btn_cancel') ?></a>
      <button type="submit" class="btn btn-primary btn-lg">
        <?= feather_icon('save', 16) ?> <?= __('tr_save_draft') ?>
      </button>
    </div>
  </div>
</form>

<script>
const PRODUCTS = <?= json_encode($productPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const INITIAL_ROWS = <?= json_encode($initialRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const STOCK_URL = <?= json_encode(url('modules/transfers/get_stock.php'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const PREFERRED_FROM_WAREHOUSE_ID = <?= (int)$preferredFromWhId ?>;
const I18N = {
  select: <?= json_encode(__('lbl_select'), JSON_UNESCAPED_UNICODE) ?>,
  availableSelected: <?= json_encode(__('tr_available_selected'), JSON_UNESCAPED_UNICODE) ?>,
  equivalent: <?= json_encode(__('tr_equivalent'), JSON_UNESCAPED_UNICODE) ?>,
  baseUnit: <?= json_encode(__('tr_in_base_unit'), JSON_UNESCAPED_UNICODE) ?>,
  addUnitLine: <?= json_encode(__('pos_add_unit_line'), JSON_UNESCAPED_UNICODE) ?>,
  allUnitsAdded: <?= json_encode(__('pos_all_units_added'), JSON_UNESCAPED_UNICODE) ?>,
  unitRelations: <?= json_encode(__('pos_unit_relations'), JSON_UNESCAPED_UNICODE) ?>,
  selectSourceWarehouseFirst: <?= json_encode(__('tr_select_source_warehouse_first'), JSON_UNESCAPED_UNICODE) ?>,
  insufficientShort: <?= json_encode(__('tr_err_insufficient_short'), JSON_UNESCAPED_UNICODE) ?>,
  fixStockErrors: <?= json_encode(__('tr_fix_stock_errors'), JSON_UNESCAPED_UNICODE) ?>,
};

let rowIndex = 0;

function escapeHtml(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function formatQty(value, precision = 3) {
  const num = parseFloat(value || 0) || 0;
  return Number.isInteger(num) ? String(num) : num.toFixed(precision).replace(/\.?0+$/, '');
}

function productById(productId) {
  return PRODUCTS[String(productId)] || null;
}

function selectedSourceWarehouseId() {
  const fromWh = parseInt(document.getElementById('fromWh')?.value || '0', 10) || 0;
  if (fromWh > 0) {
    return fromWh;
  }
  return PREFERRED_FROM_WAREHOUSE_ID > 0 ? PREFERRED_FROM_WAREHOUSE_ID : 0;
}

function ensureSourceWarehouseSelected() {
  const fromWhSelect = document.getElementById('fromWh');
  const explicitId = parseInt(fromWhSelect?.value || '0', 10) || 0;
  if (explicitId > 0) {
    clearItemsClientError();
    return explicitId;
  }
  if (PREFERRED_FROM_WAREHOUSE_ID > 0 && fromWhSelect) {
    fromWhSelect.value = String(PREFERRED_FROM_WAREHOUSE_ID);
    clearItemsClientError();
    return PREFERRED_FROM_WAREHOUSE_ID;
  }
  showItemsClientError(I18N.selectSourceWarehouseFirst);
  return 0;
}

function productAvailableInWarehouse(product, warehouseId) {
  if (!product || warehouseId <= 0) return false;
  const qty = parseFloat(product.stock_by_warehouse?.[warehouseId] ?? product.stock_by_warehouse?.[String(warehouseId)] ?? 0) || 0;
  return qty > 0;
}

function showItemsClientError(message) {
  const wrap = document.getElementById('transfer-items-client-error');
  const text = document.getElementById('transfer-items-client-error-text');
  if (!wrap || !text) return;
  text.textContent = message || '';
  wrap.style.display = message ? '' : 'none';
}

function clearItemsClientError() {
  showItemsClientError('');
}

function rowsForProduct(productId, exceptTr = null) {
  if (!productId) return [];
  return [...document.querySelectorAll('#items-body tr')].filter((tr) => (
    tr !== exceptTr
    && String(tr.querySelector('.prod-select')?.value || '') === String(productId)
  ));
}

function usedUnitCodes(productId, exceptTr = null) {
  return rowsForProduct(productId, exceptTr)
    .map((tr) => tr.querySelector('.unit-select')?.value || '')
    .filter(Boolean);
}

function getUnusedUnits(productId) {
  const product = productById(productId);
  if (!product) return [];
  const used = new Set(usedUnitCodes(productId));
  return product.units.filter((unit) => !used.has(unit.unit_code));
}

function buildUnitTooltip(product) {
  if (!product || !Array.isArray(product.units) || !product.units.length) {
    return '';
  }

  const orderedUnits = [...product.units].sort((left, right) => {
    if (left.sort_order !== right.sort_order) {
      return left.sort_order - right.sort_order;
    }
    return left.ratio_to_base - right.ratio_to_base;
  });

  const rows = orderedUnits.map((unit, index) => {
    const nextUnit = orderedUnits[index + 1] || null;
    if (!nextUnit) {
      return `
        <div class="cart-unit-tooltip-row">
          <span>1 ${escapeHtml(unit.unit_label)}</span>
          <span>=</span>
          <span>1 ${escapeHtml(unit.unit_label)}</span>
        </div>
      `;
    }
    const ratio = nextUnit.ratio_to_base / Math.max(0.000001, unit.ratio_to_base);
    return `
      <div class="cart-unit-tooltip-row">
        <span>1 ${escapeHtml(unit.unit_label)}</span>
        <span>=</span>
        <span>${formatQty(ratio, Math.abs(ratio - Math.round(ratio)) > 0.000001 ? 3 : 0)} ${escapeHtml(nextUnit.unit_label)}</span>
      </div>
    `;
  }).join('');

  return `
    <span class="cart-unit-info-wrap">
      <button type="button" class="cart-unit-info-trigger" tabindex="-1" aria-label="${escapeHtml(I18N.unitRelations)}">i</button>
      <span class="cart-unit-tooltip">
        <span class="cart-unit-tooltip-title">${escapeHtml(I18N.unitRelations)}</span>
        ${rows}
      </span>
    </span>
  `;
}

function renderProductOptions(selectedId = '') {
  const warehouseId = selectedSourceWarehouseId();
  const visibleProducts = Object.values(PRODUCTS).filter((product) => productAvailableInWarehouse(product, warehouseId));
  return [
    `<option value="">${escapeHtml(I18N.select)}...</option>`,
    ...visibleProducts.map((product) => `
      <option value="${product.id}" ${String(product.id) === String(selectedId) ? 'selected' : ''}>
        ${escapeHtml(product.name)} - ${escapeHtml(product.sku)}
      </option>
    `),
  ].join('');
}

function selectedUnitData(tr) {
  const unitSelect = tr.querySelector('.unit-select');
  const product = productById(tr.querySelector('.prod-select')?.value || '');
  if (!product || !unitSelect) {
    return null;
  }
  return product.units.find((unit) => unit.unit_code === unitSelect.value) || product.units[0] || null;
}

function updateQtyField(tr) {
  const qtyInput = tr.querySelector('.qty-input');
  const unit = selectedUnitData(tr);
  if (!qtyInput || !unit) return;

  if (unit.allow_fractional) {
    qtyInput.step = '0.001';
    qtyInput.min = '0.001';
    qtyInput.placeholder = '0.000';
  } else {
    qtyInput.step = '1';
    qtyInput.min = '1';
    qtyInput.placeholder = '0';
  }
}

function updateEquivalent(tr) {
  const hint = tr.querySelector('.qty-base-hint');
  const product = productById(tr.querySelector('.prod-select')?.value || '');
  const unit = selectedUnitData(tr);
  const qtyInput = tr.querySelector('.qty-input');
  if (!hint || !product || !unit || !qtyInput) {
    if (hint) hint.textContent = '';
    return;
  }

  const qty = parseFloat(String(qtyInput.value || '0').replace(',', '.')) || 0;
  const qtyBase = qty / Math.max(1, parseFloat(unit.ratio_to_base || 1) || 1);
  hint.textContent = `${I18N.equivalent}: ${formatQty(qtyBase)} ${product.base_unit_label}`;
}

function rowRequestedBaseQty(tr) {
  const unit = selectedUnitData(tr);
  const qtyInput = tr.querySelector('.qty-input');
  if (!unit || !qtyInput) {
    return 0;
  }
  const qty = parseFloat(String(qtyInput.value || '0').replace(',', '.')) || 0;
  return qty / Math.max(1, parseFloat(unit.ratio_to_base || 1) || 1);
}

function setRowStockError(tr, message) {
  const error = tr.querySelector('.qty-stock-error');
  if (!error) return;
  error.textContent = message || '';
  error.classList.toggle('hidden', !message);
}

function renderUnits(tr, preferredCode = '') {
  const product = productById(tr.querySelector('.prod-select')?.value || '');
  const unitSelect = tr.querySelector('.unit-select');
  if (!unitSelect) return;

  if (!product) {
    unitSelect.innerHTML = `<option value="">${escapeHtml(I18N.select)}...</option>`;
    unitSelect.value = '';
    updateQtyField(tr);
    updateEquivalent(tr);
    syncRowTools(tr);
    return;
  }

  const currentCode = unitSelect.value || '';
  const used = new Set(usedUnitCodes(product.id, tr));
  const units = product.units.filter((unit) => (
    !used.has(unit.unit_code)
    || unit.unit_code === currentCode
    || unit.unit_code === preferredCode
  ));
  const renderableUnits = units.length ? units : product.units;

  unitSelect.innerHTML = renderableUnits.map((unit) => `
    <option value="${escapeHtml(unit.unit_code)}">${escapeHtml(unit.unit_label)}</option>
  `).join('');

  const target = renderableUnits.some((unit) => unit.unit_code === preferredCode)
    ? preferredCode
    : (renderableUnits.some((unit) => unit.unit_code === currentCode)
      ? currentCode
      : (renderableUnits.some((unit) => unit.unit_code === product.default_unit)
        ? product.default_unit
        : (renderableUnits[0]?.unit_code || '')));
  unitSelect.value = target;
  updateQtyField(tr);
  updateEquivalent(tr);
  syncRowTools(tr);
}

function renderStock(tr, data) {
  const breakdown = tr.querySelector('.avail-breakdown');
  const selected = tr.querySelector('.avail-selected');
  if (!breakdown || !selected) return;
  tr.dataset.availableBase = String(parseFloat(data?.qty_base || 0) || 0);
  breakdown.textContent = data?.formatted_breakdown || '-';
  selected.textContent = data?.formatted_selected_unit
    ? `${I18N.availableSelected}: ${data.formatted_selected_unit}`
    : '';
  validateProductRows(tr.querySelector('.prod-select')?.value || '');
}

function loadStock(tr) {
  const productId = tr.querySelector('.prod-select')?.value || '';
  const unitCode = tr.querySelector('.unit-select')?.value || '';
  const fromWh = selectedSourceWarehouseId();
  if (!productId || !fromWh) {
    renderStock(tr, null);
    return;
  }

  const params = new URLSearchParams({
    product_id: productId,
    warehouse_id: fromWh,
    unit_code: unitCode,
  });

  fetch(`${STOCK_URL}?${params.toString()}`, {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
  })
    .then((response) => response.json())
    .then((data) => renderStock(tr, data))
    .catch(() => renderStock(tr, null));
}

function applyProduct(tr, preferredUnitCode = '') {
  renderUnits(tr, preferredUnitCode);
  loadStock(tr);
}

function validateProductRows(productId) {
  if (!productId) {
    return true;
  }
  const rows = rowsForProduct(productId);
  if (!rows.length) {
    return true;
  }

  const totalRequestedBase = rows.reduce((sum, row) => sum + rowRequestedBaseQty(row), 0);
  const availableBase = Math.min(...rows.map((row) => parseFloat(row.dataset.availableBase || '0') || 0));
  const hasStockError = totalRequestedBase > availableBase + 0.000001;
  const availableText = rows[0]?.querySelector('.avail-breakdown')?.textContent?.trim() || '0';

  rows.forEach((row) => {
    setRowStockError(row, hasStockError ? `${I18N.insufficientShort}: ${availableText}` : '');
  });

  return !hasStockError;
}

function refreshProductRows(productId) {
  if (!productId) return;
  rowsForProduct(productId).forEach((row) => {
    renderUnits(row, row.querySelector('.unit-select')?.value || '');
    loadStock(row);
  });
  validateProductRows(productId);
}

function syncRowTools(tr) {
  const productId = tr.querySelector('.prod-select')?.value || '';
  const product = productById(productId);
  const tooltipHost = tr.querySelector('.transfer-unit-tooltip-host');
  const addButton = tr.querySelector('.transfer-add-unit');
  if (tooltipHost) {
    tooltipHost.innerHTML = product && product.units.length > 1 ? buildUnitTooltip(product) : '';
  }
  if (addButton) {
    const canAdd = !!product && product.units.length > 1 && getUnusedUnits(productId).length > 0;
    addButton.hidden = !product || product.units.length <= 1;
    addButton.disabled = !canAdd;
    addButton.title = canAdd ? I18N.addUnitLine : I18N.allUnitsAdded;
  }
}

function addSameProductUnit(tr) {
  if (!ensureSourceWarehouseSelected()) return;
  const productId = tr.querySelector('.prod-select')?.value || '';
  if (!productId) return;
  const unusedUnits = getUnusedUnits(productId);
  if (!unusedUnits.length) {
    syncRowTools(tr);
    return;
  }
  const newRow = addRow(productId, '', unusedUnits[0].unit_code, tr);
  refreshProductRows(productId);
  syncRowTools(newRow);
}

function addRow(productId = '', qty = '', unitCode = '', insertAfter = null) {
  const body = document.getElementById('items-body');
  const idx = rowIndex++;
  const tr = document.createElement('tr');
  tr.dataset.idx = idx;
  tr.dataset.productId = String(productId || '');
  tr.innerHTML = `
    <td data-label="${escapeHtml(<?= json_encode(__('lbl_name'), JSON_UNESCAPED_UNICODE) ?>)}">
      <div class="transfer-product-cell">
        <select name="items[${idx}][product_id]" class="form-control prod-select" required>
          ${renderProductOptions(productId)}
        </select>
        <div class="transfer-product-tools">
          <span class="transfer-unit-tooltip-host"></span>
          <button type="button" class="btn btn-sm btn-ghost transfer-add-unit">
            <?= feather_icon('plus', 13) ?> ${escapeHtml(I18N.addUnitLine)}
          </button>
        </div>
      </div>
    </td>
    <td class="col-num" data-label="${escapeHtml(<?= json_encode(__('tr_available'), JSON_UNESCAPED_UNICODE) ?>)}">
      <div class="avail-breakdown text-muted transfer-stock-breakdown">-</div>
      <div class="avail-selected text-muted transfer-stock-selected"></div>
    </td>
    <td class="col-num" data-label="${escapeHtml(<?= json_encode(__('lbl_qty'), JSON_UNESCAPED_UNICODE) ?>)}">
      <input type="number"
             name="items[${idx}][qty]"
             class="form-control mono qty-input"
             value="${escapeHtml(qty)}"
             min="0.001"
             step="0.001"
             required>
      <div class="form-hint qty-base-hint"></div>
      <div class="form-error qty-stock-error hidden"></div>
    </td>
    <td data-label="${escapeHtml(<?= json_encode(__('tr_transfer_unit'), JSON_UNESCAPED_UNICODE) ?>)}">
      <select name="items[${idx}][unit_code]" class="form-control unit-select"></select>
    </td>
    <td data-label="" class="transfer-row-remove">
      <button type="button"
              class="btn btn-sm btn-ghost btn-icon remove-row btn-danger-ghost"
              title="<?= e(__('btn_delete')) ?>"
              aria-label="<?= e(__('btn_delete')) ?>">
        <?= feather_icon('trash-2', 13) ?>
      </button>
    </td>`;
  if (insertAfter && insertAfter.parentNode === body) {
    insertAfter.insertAdjacentElement('afterend', tr);
  } else {
    body.appendChild(tr);
  }
  if (window.feather && typeof window.feather.replace === 'function') {
    window.feather.replace();
  }

  tr.querySelector('.prod-select')?.addEventListener('change', () => {
    if (!ensureSourceWarehouseSelected()) {
      tr.querySelector('.prod-select').value = '';
      tr.dataset.productId = '';
      applyProduct(tr);
      return;
    }
    const previousProductId = tr.dataset.productId || '';
    const nextProductId = tr.querySelector('.prod-select')?.value || '';
    tr.dataset.productId = String(nextProductId || '');
    clearItemsClientError();
    applyProduct(tr);
    if (previousProductId && previousProductId !== nextProductId) {
      refreshProductRows(previousProductId);
    }
    refreshProductRows(nextProductId);
  });
  tr.querySelector('.unit-select')?.addEventListener('change', () => {
    updateQtyField(tr);
    updateEquivalent(tr);
    loadStock(tr);
    refreshProductRows(tr.querySelector('.prod-select')?.value || '');
  });
  tr.querySelector('.qty-input')?.addEventListener('input', () => {
    updateEquivalent(tr);
    validateProductRows(tr.querySelector('.prod-select')?.value || '');
  });
  tr.querySelector('.transfer-add-unit')?.addEventListener('click', () => addSameProductUnit(tr));
  tr.querySelector('.remove-row')?.addEventListener('click', () => {
    const productIdToRefresh = tr.querySelector('.prod-select')?.value || '';
    tr.remove();
    refreshProductRows(productIdToRefresh);
    clearItemsClientError();
  });

  if (productId) {
    applyProduct(tr, unitCode);
  } else {
    renderUnits(tr);
  }
  syncRowTools(tr);
  return tr;
}

document.getElementById('addRow')?.addEventListener('click', () => {
  if (!ensureSourceWarehouseSelected()) {
    return;
  }
  addRow();
});
document.getElementById('fromWh')?.addEventListener('change', () => {
  clearItemsClientError();
  document.querySelectorAll('#items-body tr').forEach((tr) => {
    const productSelect = tr.querySelector('.prod-select');
    const currentProductId = productSelect?.value || '';
    const currentUnitCode = tr.querySelector('.unit-select')?.value || '';
    if (productSelect) {
      productSelect.innerHTML = renderProductOptions(currentProductId);
      if (currentProductId && productAvailableInWarehouse(productById(currentProductId), selectedSourceWarehouseId())) {
        productSelect.value = currentProductId;
      } else {
        productSelect.value = '';
        tr.dataset.productId = '';
      }
    }
    applyProduct(tr, productSelect?.value ? currentUnitCode : '');
  });
});

document.getElementById('transfer-form')?.addEventListener('submit', (event) => {
  clearItemsClientError();
  if (!ensureSourceWarehouseSelected()) {
    event.preventDefault();
    return;
  }
  let hasInvalidStock = false;
  const productIds = [...new Set(
    [...document.querySelectorAll('#items-body tr')]
      .map((tr) => tr.querySelector('.prod-select')?.value || '')
      .filter(Boolean)
  )];
  productIds.forEach((productId) => {
    if (!validateProductRows(productId)) {
      hasInvalidStock = true;
    }
  });
  if (hasInvalidStock) {
    event.preventDefault();
    showItemsClientError(I18N.fixStockErrors);
  }
});

if (PREFERRED_FROM_WAREHOUSE_ID > 0) {
  const fromWhSelect = document.getElementById('fromWh');
  if (fromWhSelect && !fromWhSelect.value) {
    fromWhSelect.value = String(PREFERRED_FROM_WAREHOUSE_ID);
  }
}

if (Array.isArray(INITIAL_ROWS) && INITIAL_ROWS.length) {
  INITIAL_ROWS.forEach((row) => addRow(row.product_id || '', row.qty || '', row.unit_code || ''));
} else {
  addRow();
}
</script>

<?php include __DIR__ . '/../../views/layouts/footer.php'; ?>
<script src="https://unpkg.com/feather-icons/dist/feather.min.js"></script>
<script>feather.replace();</script>
