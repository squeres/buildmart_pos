<?php
/**
 * Goods Receipt — Create / Edit Form
 * modules/receipts/edit.php
 */
require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../views/partials/icons.php';
Auth::requireLogin();

$id     = (int)($_GET['id'] ?? 0);
$isEdit = $id > 0;

Auth::requirePerm($isEdit ? 'receipts.edit' : 'receipts.create');

$canPostReceipt = Auth::can('receipts.post');
$canManageSuppliers = Auth::can('suppliers.manage');
$canCreateProducts = Auth::can('products.create');
$canEditProducts = Auth::can('products.edit');
$canManageReceiptProducts = $canCreateProducts || $canEditProducts;

if ($isEdit) {
    $doc = Database::row("SELECT gr.* FROM goods_receipts gr WHERE gr.id = ?", [$id]);
    if (!$doc) { flash_error(_r('err_not_found')); redirect('/modules/receipts/'); }
    require_warehouse_access((int)$doc['warehouse_id'], '/modules/receipts/');
    if ($doc['status'] !== 'draft') {
        flash_error(_r('gr_err_not_draft'));
        redirect('/modules/receipts/view.php?id=' . $id);
    }
    $items       = Database::all("SELECT * FROM goods_receipt_items WHERE receipt_id=? ORDER BY sort_order, id", [$id]);
    $pageTitle   = __('gr_edit');
    $breadcrumbs = [[__('gr_title'), url('modules/receipts/')], [$doc['doc_no'], null]];
} else {
    $doc = [
        'id' => 0, 'doc_no' => gr_next_doc_no(), 'doc_date' => date('Y-m-d'),
        'supplier_id' => '', 'warehouse_id' => user_default_warehouse_id(), 'status' => 'draft',
        'accepted_by' => Auth::user()['name'] ?? '', 'delivered_by' => '',
        'supplier_doc_no' => '', 'notes' => '',
    ];
    $items       = [];
    $pageTitle   = __('gr_new');
    $breadcrumbs = [[__('gr_title'), url('modules/receipts/')], [$pageTitle, null]];
}

$suppliers  = Database::all("SELECT id, name FROM suppliers  WHERE is_active=1 ORDER BY name");
$warehouses = user_warehouses();
$categories = Database::all("SELECT id, name_en, name_ru FROM categories WHERE is_active=1 ORDER BY sort_order, name_en");
$unitPresets = unit_preset_rows();

$selectedProductIds = array_values(array_unique(array_filter(array_map(
    static fn(array $item): int => (int)($item['product_id'] ?? 0),
    $items
))));
$receiptProductsById = [];
if ($selectedProductIds) {
    $placeholders = implode(',', array_fill(0, count($selectedProductIds), '?'));
    $selectedProducts = Database::all(
        "SELECT id, category_id, name_en, name_ru, sku, barcode, unit, cost_price, sale_price, image
         FROM products
         WHERE is_active = 1
           AND id IN ($placeholders)",
        $selectedProductIds
    );
    foreach ($selectedProducts as $selectedProduct) {
        $receiptProductsById[(int)$selectedProduct['id']] = $selectedProduct;
    }
}

$productsJs = [];
foreach ($receiptProductsById as $p) {
    $units = product_units((int)$p['id'], $p['unit']);
    usort($units, static fn($a, $b) => (float)$a['ratio_to_base'] <=> (float)$b['ratio_to_base']);
    $defaultUnit = product_default_unit((int)$p['id'], $p['unit']);
    $basePrices = UISettings::productPrices((int)$p['id']);
    $unitOverrides = product_unit_price_overrides((int)$p['id']);
    $defaultPurchasePrice = product_unit_price((int)$p['id'], $defaultUnit['unit_code'], 'purchase', (float)($basePrices['purchase'] ?? $p['cost_price']), $units, $unitOverrides);
    $defaultSalePrice = product_unit_price((int)$p['id'], $defaultUnit['unit_code'], 'retail', (float)($basePrices['retail'] ?? $p['sale_price']), $units, $unitOverrides);
    $baseUnitLabel = product_unit_label_text($units[0] ?? ['unit_code' => $p['unit']]);
    $editorUnitRows = [];
    $prevRatio = 1.0;
    foreach ($units as $idx => $unitRow) {
        if ($idx === 0) {
            continue;
        }
        $ratio = (float)$unitRow['ratio_to_base'];
        $editorUnitRows[] = [
            'label' => product_unit_label_text($unitRow),
            'ratio_to_base' => $ratio,
            'step' => round($ratio / max(0.001, $prevRatio), 3),
        ];
        $prevRatio = $ratio;
    }
    $productsJs[$p['id']] = [
        'id'    => (int)$p['id'],
        'name'  => product_name($p),
        'name_en' => (string)$p['name_en'],
        'name_ru' => (string)$p['name_ru'],
        'sku'   => (string)$p['sku'],
        'barcode' => (string)($p['barcode'] ?? ''),
        'category_id' => (int)$p['category_id'],
        'unit'  => (string)$defaultUnit['unit_code'],
        'base_unit' => (string)$p['unit'],
        'base_unit_label' => $baseUnitLabel,
        'default_sale_unit' => (string)$defaultUnit['unit_code'],
        'unit_rows' => $editorUnitRows,
        'image' => (string)($p['image'] ?? ''),
        'base_cost_price' => $defaultPurchasePrice,
        'base_sale_price' => $defaultSalePrice,
        'price' => $defaultPurchasePrice,
        'units' => array_map(static function (array $unit) use ($p, $basePrices, $unitOverrides, $units) {
            return [
                'code' => $unit['unit_code'],
                'label' => product_unit_label_text($unit),
                'ratio' => (float)$unit['ratio_to_base'],
                'purchase_price' => product_unit_price((int)$p['id'], $unit['unit_code'], 'purchase', (float)($basePrices['purchase'] ?? $p['cost_price']), $units, $unitOverrides),
                'sale_price' => product_unit_price((int)$p['id'], $unit['unit_code'], 'retail', (float)($basePrices['retail'] ?? $p['sale_price']), $units, $unitOverrides),
                'price' => product_unit_price((int)$p['id'], $unit['unit_code'], 'purchase', (float)($basePrices['purchase'] ?? $p['cost_price']), $units, $unitOverrides),
            ];
        }, $units),
    ];
}
$unitOptions = unit_options();

include __DIR__ . '/../../views/layouts/header.php';
?>

<style>
/* ── Quick-create modal ───────────────────────────────────────── */
.qc-overlay {
  display: none;
  position: fixed; inset: 0;
  background: rgba(0,0,0,.65);
  z-index: 1000;
  align-items: center;
  justify-content: center;
  padding: 20px;
}
.qc-overlay.open { display: flex; }

.qc-modal {
  background: var(--bg-surface);
  border: 1px solid var(--border-medium);
  border-radius: var(--radius-xl);
  width: 100%;
  max-width: 520px;
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
  flex-shrink: 0;
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
  transition: color .15s;
}
.qc-modal-close:hover { color: var(--text-primary); }
.qc-modal-body {
  padding: 18px 22px;
  overflow-y: auto;
  flex: 1;
}
.qc-modal-footer {
  padding: 12px 22px 18px;
  border-top: 1px solid var(--border-dim);
  display: flex;
  gap: 8px;
  flex-shrink: 0;
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
.doc-confirm-check input {
  margin-top: 3px;
}
.doc-confirm-check label {
  margin: 0;
  color: var(--text-primary);
}
@media (max-width: 640px) {
  .doc-confirm-grid {
    grid-template-columns: 1fr;
  }
}
.qc-error {
  display: none;
  background: var(--danger-dim);
  border: 1px solid rgba(248,81,73,.4);
  color: var(--danger);
  border-radius: var(--radius-sm);
  padding: 8px 12px;
  font-size: 12.5px;
  margin-bottom: 12px;
}
.qc-error.show { display: block; }

/* + button next to select */
.qc-select-wrap { display: flex; gap: 6px; align-items: center; }
.qc-select-wrap select { flex: 1; }
.btn-qc {
  flex-shrink: 0;
  width: 34px; height: 34px;
  display: flex; align-items: center; justify-content: center;
  background: var(--amber-dim);
  border: 1px solid var(--amber-border);
  border-radius: var(--radius-sm);
  color: var(--amber);
  cursor: pointer;
  transition: background .15s;
  padding: 0;
}
.btn-qc:hover { background: rgba(245,166,35,.22); }
</style>

<div class="page-header">
  <div>
    <h1 class="page-heading"><?= $isEdit ? __('gr_edit').': '.e($doc['doc_no']) : __('gr_new') ?></h1>
    <?php if ($isEdit): ?>
      <div class="text-secondary" style="font-size:13px"><?= gr_status_badge($doc['status']) ?></div>
    <?php endif; ?>
  </div>
  <div class="page-actions">
    <a href="<?= url('modules/receipts/') ?>" class="btn btn-ghost">
      <?= feather_icon('arrow-left', 15) ?> <?= __('btn_back') ?>
    </a>
  </div>
</div>

<form method="POST" action="<?= url('modules/receipts/save.php') ?>" id="gr-form">
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= $doc['id'] ?>">

  <div class="card mb-3">
    <div class="card-header"><span class="card-title"><?= __('gr_header') ?></span></div>
    <div class="card-body">
      <div class="form-row form-row-3">
        <div class="form-group">
          <label class="form-label"><?= __('gr_doc_no') ?> <span class="req">*</span></label>
          <input type="text" name="doc_no" class="form-control font-mono" value="<?= e($doc['doc_no']) ?>" required maxlength="30">
        </div>
        <div class="form-group">
          <label class="form-label"><?= __('lbl_date') ?> <span class="req">*</span></label>
          <input type="date" name="doc_date" class="form-control" value="<?= e($doc['doc_date']) ?>" required>
        </div>
        <div class="form-group">
          <label class="form-label"><?= __('gr_supplier_doc_no') ?></label>
          <input type="text" name="supplier_doc_no" class="form-control font-mono" value="<?= e($doc['supplier_doc_no']) ?>" maxlength="80" placeholder="<?= __('gr_supplier_doc_ph') ?>">
        </div>
      </div>
      <div class="form-row form-row-2">
        <div class="form-group">
          <label class="form-label">
            <?= __('gr_supplier') ?>
            <?php if ($canManageSuppliers): ?>
            <span class="text-muted" style="font-size:11px;font-weight:400;margin-left:4px"><?= __('gr_or_create_new') ?></span>
            <?php endif; ?>
          </label>
          <div class="qc-select-wrap">
            <select name="supplier_id" id="supplier-select" class="form-control">
              <option value=""><?= __('lbl_select') ?></option>
              <?php foreach ($suppliers as $sup): ?>
                <option value="<?= $sup['id'] ?>" <?= (string)$doc['supplier_id'] === (string)$sup['id'] ? 'selected' : '' ?>>
                  <?= e($sup['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <?php if ($canManageSuppliers): ?>
            <button type="button" class="btn-qc" id="btn-new-supplier" title="<?= __('gr_quick_add_supplier') ?>">
              <?= feather_icon('plus', 15) ?>
            </button>
            <?php endif; ?>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label"><?= __('gr_warehouse') ?> <span class="req">*</span></label>
          <select name="warehouse_id" class="form-control" required>
            <?php foreach ($warehouses as $wh): ?>
              <option value="<?= $wh['id'] ?>" <?= (string)$doc['warehouse_id'] === (string)$wh['id'] ? 'selected' : '' ?>>
                <?= e($wh['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="form-row form-row-2">
        <div class="form-group">
          <label class="form-label"><?= __('gr_accepted_by') ?></label>
          <input type="text" name="accepted_by" class="form-control" value="<?= e($doc['accepted_by']) ?>" maxlength="120">
        </div>
        <div class="form-group">
          <label class="form-label"><?= __('gr_delivered_by') ?></label>
          <input type="text" name="delivered_by" class="form-control" value="<?= e($doc['delivered_by']) ?>" maxlength="120">
        </div>
      </div>
      <div class="form-group mb-0">
        <label class="form-label"><?= __('lbl_notes') ?></label>
        <textarea name="notes" class="form-control" rows="2"><?= e($doc['notes']) ?></textarea>
      </div>
    </div>
  </div>

  <div class="card mb-3">
    <div class="card-header flex-between">
      <span class="card-title"><?= __('gr_items') ?></span>
      <button type="button" id="btn-add-row" class="btn btn-sm btn-secondary">
        <?= feather_icon('plus', 14) ?> <?= __('gr_add_row') ?>
      </button>
    </div>
    <div class="table-wrap mobile-table-scroll">
      <table class="table" id="items-table">
        <thead>
          <tr>
            <th style="width:32px">#</th>
            <th><?= __('gr_product') ?></th>
            <th style="width:80px"><?= __('lbl_unit') ?></th>
            <th style="width:100px" class="col-num"><?= __('lbl_qty') ?></th>
            <th style="width:120px" class="col-num"><?= __('gr_unit_price') ?></th>
            <th style="width:110px" class="col-num"><?= __('gr_tax_rate') ?></th>
            <th style="width:130px" class="col-num"><?= __('gr_line_total') ?></th>
            <th style="width:160px"><?= __('lbl_notes') ?></th>
            <th style="width:36px"></th>
          </tr>
        </thead>
        <tbody id="items-body">
          <?php foreach ($items as $idx => $item): ?>
            <?php include __DIR__ . '/_row.php'; ?>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <td colspan="6" class="text-right fw-600"><?= __('gr_subtotal') ?>:</td>
            <td class="col-num fw-600" id="grand-subtotal">—</td>
            <td colspan="2"></td>
          </tr>
          <tr>
            <td colspan="6" class="text-right"><?= __('lbl_tax') ?>:</td>
            <td class="col-num" id="grand-tax">—</td>
            <td colspan="2"></td>
          </tr>
          <tr>
            <td colspan="6" class="text-right fw-600" style="font-size:15px"><?= __('lbl_total') ?>:</td>
            <td class="col-num fw-600" id="grand-total" style="font-size:15px">—</td>
            <td colspan="2"></td>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="card-body stacked-actions">
      <button type="submit" name="action" value="save_draft" class="btn btn-secondary btn-lg">
        <?= feather_icon('save', 17) ?> <?= __('gr_save_draft') ?>
      </button>
      <?php if ($canPostReceipt): ?>
      <button type="submit" name="action" value="save_and_post" class="btn btn-primary btn-lg" data-doc-confirm="receipt-post">
        <?= feather_icon('check-circle', 17) ?> <?= __('gr_save_and_post') ?>
      </button>
      <?php endif; ?>
      <a href="<?= url('modules/receipts/') ?>" class="btn btn-ghost btn-lg"><?= __('btn_cancel') ?></a>
    </div>
  </div>
</form>

<template id="row-template">
  <?php
  $idx  = '__IDX__';
  $item = ['id'=>0,'product_id'=>'','name'=>'','unit'=>'pcs','qty'=>1,'unit_price'=>0,'tax_rate'=>0,'line_total'=>0,'notes'=>''];
  include __DIR__ . '/_row.php';
  ?>
</template>

<?php if ($canManageSuppliers): ?>
<div class="qc-overlay" id="modal-supplier" role="dialog" aria-modal="true">
  <div class="qc-modal">
    <div class="qc-modal-header">
      <div class="qc-modal-title">
        <?= feather_icon('truck', 17) ?> <?= __('gr_quick_add_supplier') ?>
      </div>
      <button type="button" class="qc-modal-close" data-close-modal="modal-supplier">×</button>
    </div>
    <div class="qc-modal-body">
      <div class="qc-error" id="supplier-error"></div>
      <div class="form-group">
        <label class="form-label"><?= __('lbl_name') ?> <span class="req">*</span></label>
        <input type="text" id="sup-name" class="form-control" placeholder="<?= __('gr_sup_name_ph') ?>" maxlength="200">
      </div>
      <div class="form-row form-row-2">
        <div class="form-group">
          <label class="form-label"><?= __('lbl_phone') ?></label>
          <input type="tel" id="sup-phone" class="form-control" placeholder="+7 (___) ___-__-__">
        </div>
        <div class="form-group">
          <label class="form-label"><?= __('sup_inn') ?></label>
          <input type="text" id="sup-inn" class="form-control font-mono" placeholder="7700000000" maxlength="30">
        </div>
      </div>
      <div class="form-group mb-0">
        <label class="form-label"><?= __('sup_contact') ?></label>
        <input type="text" id="sup-contact" class="form-control" placeholder="<?= __('gr_sup_contact_ph') ?>" maxlength="120">
      </div>
      <div class="form-group mb-0" style="margin-top:12px">
        <label class="form-label"><?= __('lbl_address') ?></label>
        <textarea id="sup-address" class="form-control" rows="2" placeholder="<?= __('gr_sup_address_ph') ?>" maxlength="255"></textarea>
      </div>
    </div>
    <div class="qc-modal-footer">
      <button type="button" class="btn btn-primary" id="btn-supplier-save">
        <?= feather_icon('save', 15) ?> <?= __('btn_save') ?>
      </button>
      <button type="button" class="btn btn-ghost" data-close-modal="modal-supplier"><?= __('btn_cancel') ?></button>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="qc-overlay" id="modal-product-picker" role="dialog" aria-modal="true">
  <div class="qc-modal product-picker-modal" style="max-width:620px">
    <div class="qc-modal-header">
      <div class="qc-modal-title">
        <?= feather_icon('list', 17) ?> <?= __('gr_product_picker') ?>
      </div>
      <button type="button" class="qc-modal-close" data-close-modal="modal-product-picker">×</button>
    </div>
    <div class="qc-modal-body">
      <div class="product-search-field">
        <div class="product-search-main">
          <input
            type="text"
            id="receiptProductPickerSearch"
            class="form-control"
            placeholder="<?= e(__('gr_product_search_ph')) ?>"
            autocomplete="off"
            spellcheck="false"
          >
        </div>
        <div class="product-field-actions">
          <button type="button"
                  class="product-field-icon product-camera-trigger"
                  id="receiptProductPickerCamera"
                  title="<?= e(__('camera_scan_title')) ?>"
                  hidden>
            <?= feather_icon('camera', 15) ?>
          </button>
        </div>
      </div>
      <div class="product-search-inline-hint"><?= __('gr_product_picker_hint') ?></div>
      <div class="product-picker-empty hidden" id="receiptProductPickerEmpty"><?= __('gr_product_picker_empty') ?></div>
      <div class="product-picker-list" id="receiptProductPickerList"></div>
    </div>
    <div class="qc-modal-footer">
      <button type="button" class="btn btn-ghost" data-close-modal="modal-product-picker"><?= __('btn_close') ?></button>
    </div>
  </div>
</div>

<?php if ($canManageReceiptProducts): ?>
<div class="qc-overlay" id="modal-product" role="dialog" aria-modal="true">
  <div class="qc-modal" style="max-width:580px">
    <div class="qc-modal-header">
      <div class="qc-modal-title">
        <?= feather_icon('package', 17) ?> <?= __('gr_quick_add_product') ?>
      </div>
      <button type="button" class="qc-modal-close" data-close-modal="modal-product">×</button>
    </div>
    <div class="qc-modal-body">
      <div class="qc-error" id="product-error"></div>
      <div class="form-row form-row-2">
        <div class="form-group">
          <label class="form-label"><?= __('lbl_name') ?> <span class="req">*</span></label>
          <input type="text" id="prod-name" class="form-control" placeholder="<?= __('prod_name_placeholder') ?>" maxlength="200">
        </div>
      </div>
      <div class="form-row form-row-2">
        <div class="form-group">
          <label class="form-label"><?= __('lbl_sku') ?></label>
          <input type="text" id="prod-sku" class="form-control font-mono" placeholder="<?= __('gr_sku_ph') ?>" maxlength="60">
          <div style="font-size:11px;color:var(--text-muted);margin-top:3px"><?= __('gr_sku_autogen') ?></div>
        </div>
        <div class="form-group">
          <label class="form-label"><?= __('lbl_barcode') ?></label>
          <div class="barcode-camera-field">
            <input type="text" id="prod-barcode" class="form-control font-mono" placeholder="4600000000000" maxlength="60">
            <div class="product-field-actions">
              <button type="button"
                      class="product-field-icon product-camera-trigger"
                      data-barcode-camera
                      data-camera-target="#prod-barcode"
                      title="<?= e(__('camera_scan_title')) ?>"
                      hidden>
                <?= feather_icon('camera', 15) ?>
              </button>
            </div>
          </div>
          <div style="font-size:11px;color:var(--text-muted);margin-top:3px">Оставьте пустым — сгенерируется автоматически</div>
        </div>
      </div>
      <div class="form-row form-row-2">
        <div class="form-group">
          <label class="form-label"><?= __('lbl_category') ?></label>
          <select id="prod-category" class="form-control">
            <?php foreach ($categories as $cat): ?>
              <option value="<?= $cat['id'] ?>"><?= e(category_name($cat)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label"><?= __('lbl_unit') ?></label>
          <div class="qc-select-wrap">
            <select id="prod-unit-label" class="form-control unit-preset-select" data-storage-code="<?= e(unit_storage_code_from_label((string)($unitPresets[0]['unit_label'] ?? 'Штук'))) ?>">
              <?php foreach ($unitPresets as $preset): ?>
                <option value="<?= e($preset['unit_label']) ?>" data-storage-code="<?= e(unit_storage_code_from_label((string)$preset['unit_label'])) ?>"><?= e($preset['unit_label']) ?></option>
              <?php endforeach; ?>
            </select>
            <button type="button" class="btn-qc" id="btn-new-unit-preset-modal" title="<?= __('pos_add_unit_line') ?>">
              <?= feather_icon('plus', 15) ?>
            </button>
          </div>
          <div style="font-size:11px;color:var(--text-muted);margin-top:3px">Выбирается из общего справочника единиц.</div>
        </div>
      </div>
      <div class="form-group" style="display:none">
        <label class="form-label"><?= __('lbl_unit') ?></label>
        <select id="prod-unit" class="form-control">
          <?php foreach ($unitOptions as $uKey => $uLabel): ?>
            <option value="<?= $uKey ?>"><?= e($uLabel) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group" style="margin-top:14px">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
          <label class="form-label" style="margin:0">Цепочка единиц</label>
          <button type="button" class="btn btn-sm btn-ghost" id="prod-add-unit-row"><?= feather_icon('plus', 14) ?> Добавить единицу</button>
        </div>
        <div style="font-size:11px;color:var(--text-muted);margin-bottom:10px">Стройте сверху вниз: самая большая единица, затем меньшая внутри нее, потом еще меньшая.</div>
        <div id="prod-unit-rows" class="mobile-stack"></div>
      </div>
      <div class="form-group" style="margin-top:10px">
        <label class="form-label">Единица по умолчанию</label>
        <select id="prod-default-unit" class="form-control"></select>
        <div style="font-size:11px;color:var(--text-muted);margin-top:3px">Эта единица будет показываться в товарах, кассе и документах по умолчанию.</div>
      </div>
      <div class="form-group mb-0">
        <label class="form-label"><?= __('lbl_image') ?></label>
        <input type="file" id="prod-image" class="form-control" accept="image/jpeg,image/png,image/webp">
        <div style="font-size:11px;color:var(--text-muted);margin-top:3px"><?= __('gr_img_hint') ?></div>
        <img id="prod-image-preview" src="" alt="" style="display:none;margin-top:8px;max-height:80px;max-width:160px;border-radius:6px;border:1px solid var(--border-soft)">
      </div>
    </div>
    <div class="qc-modal-footer">
      <button type="button" class="btn btn-primary" id="btn-product-save">
        <?= feather_icon('save', 15) ?> <?= __('btn_save') ?>
      </button>
      <button type="button" class="btn btn-ghost" data-close-modal="modal-product"><?= __('btn_cancel') ?></button>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="qc-overlay" id="modal-unit-preset" role="dialog" aria-modal="true">
  <div class="qc-modal" style="max-width:420px">
    <div class="qc-modal-header">
      <div class="qc-modal-title">
        <?= feather_icon('layers', 17) ?> Новая единица
      </div>
      <button type="button" class="qc-modal-close" data-close-modal="modal-unit-preset">×</button>
    </div>
    <div class="qc-modal-body">
      <div class="qc-error" id="unit-preset-error"></div>
      <div class="form-group mb-0">
        <label class="form-label">Название единицы</label>
        <input type="text" id="unit-preset-name" class="form-control" placeholder="<?= __('unit_preset_placeholder') ?>">
      </div>
    </div>
    <div class="qc-modal-footer">
      <button type="button" class="btn btn-primary" id="btn-unit-preset-save">
        <?= feather_icon('save', 15) ?> <?= __('btn_save') ?>
      </button>
      <button type="button" class="btn btn-ghost" data-close-modal="modal-unit-preset"><?= __('btn_cancel') ?></button>
    </div>
  </div>
</div>

<div class="qc-overlay" id="modal-doc-confirm" role="dialog" aria-modal="true">
  <div class="qc-modal" style="max-width:520px">
    <div class="qc-modal-header">
      <div class="qc-modal-title">
        <?= feather_icon('shield', 17) ?> <span id="doc-confirm-title"><?= __('doc_confirm_post_title') ?></span>
      </div>
      <button type="button" class="qc-modal-close" id="doc-confirm-close">×</button>
    </div>
    <div class="qc-modal-body">
      <p class="text-muted" style="margin:0 0 14px"><?= __('doc_confirm_summary_hint') ?></p>
      <div class="doc-confirm-grid">
        <div class="doc-confirm-card">
          <div class="doc-confirm-label"><?= __('gr_doc_no') ?></div>
          <div class="doc-confirm-value" id="doc-confirm-doc-no">—</div>
        </div>
        <div class="doc-confirm-card">
          <div class="doc-confirm-label"><?= __('lbl_date') ?></div>
          <div class="doc-confirm-value" id="doc-confirm-date">—</div>
        </div>
        <div class="doc-confirm-card">
          <div class="doc-confirm-label"><?= __('gr_supplier') ?></div>
          <div class="doc-confirm-value" id="doc-confirm-supplier">—</div>
        </div>
        <div class="doc-confirm-card">
          <div class="doc-confirm-label"><?= __('gr_warehouse') ?></div>
          <div class="doc-confirm-value" id="doc-confirm-warehouse">—</div>
        </div>
        <div class="doc-confirm-card">
          <div class="doc-confirm-label"><?= __('gr_items') ?></div>
          <div class="doc-confirm-value" id="doc-confirm-items">0</div>
        </div>
        <div class="doc-confirm-card">
          <div class="doc-confirm-label"><?= __('lbl_total') ?></div>
          <div class="doc-confirm-value" id="doc-confirm-total">0.00</div>
        </div>
      </div>
      <div class="doc-confirm-check">
        <input type="checkbox" id="doc-confirm-checkbox">
        <label for="doc-confirm-checkbox" id="doc-confirm-checkbox-label"><?= __('doc_confirm_post_checkbox') ?></label>
      </div>
    </div>
    <div class="qc-modal-footer">
      <button type="button" class="btn btn-primary" id="doc-confirm-submit" disabled><?= __('btn_confirm') ?></button>
      <button type="button" class="btn btn-ghost" id="doc-confirm-cancel"><?= __('btn_cancel') ?></button>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../../views/layouts/footer.php'; ?>

<script src="https://unpkg.com/feather-icons/dist/feather.min.js"></script>
<script>
feather.replace();

const PRODUCTS = <?= json_for_html(array_values($productsJs)) ?>;
const PROD_MAP = {};
PRODUCTS.forEach((product) => {
  PROD_MAP[product.id] = product;
});

const AJAX_URL = <?= json_for_html(url('modules/receipts/ajax_create.php')) ?>;
const PRODUCT_SEARCH_URL = <?= json_for_html(url('modules/receipts/search_products.php')) ?>;
const CSRF_TOKEN = document.querySelector('input[name="_token"]')?.value || '';
const UNIT_PRESET_AJAX_URL = <?= json_for_html(url('modules/common/ajax_units.php')) ?>;
const UNIT_PRESETS = <?= json_for_html(array_values(array_map(static fn($row) => ['label' => (string)$row['unit_label'], 'storageCode' => unit_storage_code_from_label((string)$row['unit_label'])], $unitPresets))) ?>;
const RECEIPT_SEARCH_STRINGS = {
  recentTitle: <?= json_for_html(__('gr_product_recent')) ?>,
  recentHint: <?= json_for_html(__('gr_product_recent_hint')) ?>,
  searchMin: <?= json_for_html(__('gr_product_search_min')) ?>,
  noResults: <?= json_for_html(__('gr_product_search_no_results')) ?>,
  pickerEmpty: <?= json_for_html(__('gr_product_picker_empty')) ?>,
  sku: <?= json_for_html(__('lbl_sku')) ?>,
  barcode: <?= json_for_html(__('lbl_barcode')) ?>,
  aliases: <?= json_for_html(__('inv_count_aliases')) ?>,
  selected: <?= json_for_html(__('gr_product_selected')) ?>,
};

let rowIndex = <?= max(count($items) - 1, -1) ?>;
let _targetRow = null;
let _editingProductId = 0;
let _pickerResults = [];
let _pickerSearchTimer = 0;
const receiptProductCache = new Map();

const prodUnitRowsWrap = document.getElementById('prod-unit-rows');
const prodBaseUnitSelect = document.getElementById('prod-unit');
const prodBaseUnitLabel = document.getElementById('prod-unit-label');
const prodDefaultUnitSelect = document.getElementById('prod-default-unit');
const productSaveBtn = document.getElementById('btn-product-save');
const pickerSearchInput = document.getElementById('receiptProductPickerSearch');
const pickerList = document.getElementById('receiptProductPickerList');
const pickerEmpty = document.getElementById('receiptProductPickerEmpty');
const pickerCameraBtn = document.getElementById('receiptProductPickerCamera');
const unitPresetModal = document.getElementById('modal-unit-preset');
const unitPresetNameInput = document.getElementById('unit-preset-name');
const unitPresetError = document.getElementById('unit-preset-error');
const unitPresetSaveBtn = document.getElementById('btn-unit-preset-save');
const unitPresetOpenBtn = document.getElementById('btn-new-unit-preset-modal');
const docConfirmModal = document.getElementById('modal-doc-confirm');
const docConfirmTitle = document.getElementById('doc-confirm-title');
const docConfirmDocNo = document.getElementById('doc-confirm-doc-no');
const docConfirmDate = document.getElementById('doc-confirm-date');
const docConfirmSupplier = document.getElementById('doc-confirm-supplier');
const docConfirmWarehouse = document.getElementById('doc-confirm-warehouse');
const docConfirmItems = document.getElementById('doc-confirm-items');
const docConfirmTotal = document.getElementById('doc-confirm-total');
const docConfirmCheckbox = document.getElementById('doc-confirm-checkbox');
const docConfirmCheckboxLabel = document.getElementById('doc-confirm-checkbox-label');
const docConfirmSubmit = document.getElementById('doc-confirm-submit');
let pendingDocSubmitter = null;

function priceNumber(value) {
  return parseFloat(value || 0) || 0;
}

function formatPrice(value) {
  return priceNumber(value).toFixed(2);
}

function escapeHtml(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function selectedStorageCode(select, fallback = 'pcs') {
  return select?.selectedOptions?.[0]?.dataset?.storageCode || select?.dataset?.storageCode || fallback;
}

function syncModalBaseUnitCode() {
  if (!prodBaseUnitSelect) return;
  prodBaseUnitSelect.value = selectedStorageCode(prodBaseUnitLabel, prodBaseUnitSelect.value || 'pcs');
}

function unitPresetOptionsHtml(selectedLabel = '', allowEmpty = false, selectedStorageCode = 'pcs') {
  const parts = [];
  if (allowEmpty) {
    parts.push('<option value="">Выберите единицу</option>');
  }
  UNIT_PRESETS.forEach((preset) => {
    const selected = preset.label === selectedLabel ? 'selected' : '';
    parts.push(`<option value="${escapeHtml(preset.label)}" data-storage-code="${escapeHtml(preset.storageCode || 'pcs')}" ${selected}>${escapeHtml(preset.label)}</option>`);
  });
  if (selectedLabel && !UNIT_PRESETS.some((preset) => preset.label === selectedLabel)) {
    parts.push(`<option value="${escapeHtml(selectedLabel)}" data-storage-code="${escapeHtml(selectedStorageCode || 'pcs')}" selected>${escapeHtml(selectedLabel)}</option>`);
  }
  return parts.join('');
}

function refreshUnitPresetSelects(selectedLabel = '', selectedStorageCode = '') {
  document.querySelectorAll('.unit-preset-select').forEach((select) => {
    const current = select.value || selectedLabel;
    const allowEmpty = select.dataset.allowEmpty === '1';
    const currentStorageCode = select.dataset.storageCode || selectedStorageCode || 'pcs';
    select.innerHTML = unitPresetOptionsHtml(current, allowEmpty, currentStorageCode);
    if (current) {
      select.value = current;
    }
  });
}

function unitPriceMap(units, anchorCode, anchorValue) {
  const list = units || [];
  const anchor = list.find((unit) => unit.code === anchorCode) || list[0] || { ratio: 1 };
  const anchorRatio = priceNumber(anchor?.ratio || 1) || 1;
  const value = priceNumber(anchorValue);
  const map = {};
  list.forEach((unit) => {
    const ratio = priceNumber(unit?.ratio || 1) || 1;
    map[unit.code] = value > 0 ? priceNumber((value * (anchorRatio / Math.max(1, ratio))).toFixed(2)) : 0;
  });
  return map;
}

function normalizePriceMap(units, existingMap, fallbackCode = '', fallbackValue = 0, getter = 'purchase_price') {
  const map = {};
  (units || []).forEach((unit) => {
    const raw = existingMap?.[unit.code];
    if (raw !== undefined && raw !== null && raw !== '') {
      map[unit.code] = priceNumber(raw);
    } else {
      map[unit.code] = priceNumber(unit?.[getter] ?? unit?.price ?? 0);
    }
  });
  if (Object.values(map).some((value) => value > 0)) {
    return map;
  }
  return unitPriceMap(units, fallbackCode || units?.[0]?.code || '', fallbackValue);
}

function getProductUnit(product, unitCode) {
  return (product?.units || []).find((unit) => unit.code === unitCode) || product?.units?.[0] || null;
}

function fillUnitOptions(select, product, selectedCode = '') {
  if (!select) return;
  const units = product?.units || [];
  select.innerHTML = units.map((unit) => `<option value="${unit.code}" ${unit.code === selectedCode ? 'selected' : ''}>${unit.label}</option>`).join('');
  if (!selectedCode && units[0]) {
    select.value = units[0].code;
  }
}

function rememberRowPrice(tr, product, unitCode, price) {
  const overrides = JSON.parse(tr.dataset.priceOverrides || '{}');
  overrides[unitCode] = priceNumber(price);
  tr.dataset.priceOverrides = JSON.stringify(overrides);
  const priceJsonInput = tr.querySelector('.row-unit-prices-json');
  if (priceJsonInput) {
    priceJsonInput.value = JSON.stringify(overrides);
  }
}

function applyRowUnitPrice(tr, product, unitCode) {
  const unit = getProductUnit(product, unitCode);
  if (!unit) return;
  const priceInput = tr.querySelector('.row-price');
  const overrides = JSON.parse(tr.dataset.priceOverrides || '{}');
  const nextPrice = overrides[unitCode] ?? unit.purchase_price ?? unit.price ?? 0;
  if (priceInput) {
    priceInput.value = formatPrice(nextPrice);
  }
}

function renderReceiptUnitMatrix(tr, product) {
  const container = tr.querySelector('.row-unit-matrix');
  const unitSelect = tr.querySelector('.row-unit');
  const selectedLabel = tr.querySelector('.row-selected-unit');
  const priceJsonInput = tr.querySelector('.row-unit-prices-json');
  const saleJsonInput = tr.querySelector('.row-sale-prices-json');
  if (!container || !unitSelect || !product) return;

  const units = product.units || [];
  const state = normalizePriceMap(
    units,
    {
      ...JSON.parse(priceJsonInput?.value || '{}'),
      ...JSON.parse(tr.dataset.priceOverrides || '{}'),
    },
    unitSelect.value || product.unit,
    tr.querySelector('.row-price')?.value || product.base_cost_price || product.price || 0,
    'purchase_price'
  );
  const saleState = normalizePriceMap(
    units,
    JSON.parse(saleJsonInput?.value || '{}'),
    product.unit,
    product.base_sale_price || 0,
    'sale_price'
  );
  tr.dataset.priceOverrides = JSON.stringify(state);
  if (priceJsonInput) priceJsonInput.value = JSON.stringify(state);
  if (saleJsonInput) saleJsonInput.value = JSON.stringify(saleState);

  container.innerHTML = units.map((unit) => `
    <div class="unit-matrix-card">
      <label class="unit-matrix-radio">
        <input type="radio" class="row-doc-unit" name="row_doc_unit_${tr.rowIndex}" value="${unit.code}" ${unit.code === unitSelect.value ? 'checked' : ''}>
      </label>
      <div class="form-group mb-0">
        <label class="form-label"><?= e(__('lbl_unit')) ?></label>
        <input type="text" class="form-control" value="${unit.label}" readonly>
      </div>
      <div class="form-group mb-0">
        <label class="form-label"><?= e(__('gr_unit_price')) ?></label>
        <input type="number" class="form-control form-control-sm row-unit-card-price" data-unit="${unit.code}" value="${formatPrice(state[unit.code] || 0)}" min="0" step="0.01">
      </div>
    </div>
  `).join('');

  container.querySelectorAll('.row-doc-unit').forEach((radio) => {
    radio.addEventListener('change', () => {
      unitSelect.value = radio.value;
      if (selectedLabel) selectedLabel.textContent = unitSelect.selectedOptions[0]?.textContent || '';
      applyRowUnitPrice(tr, product, radio.value);
      recalcRow(tr);
    });
  });

  container.querySelectorAll('.row-unit-card-price').forEach((input) => {
    input.addEventListener('input', () => {
      const overrides = JSON.parse(tr.dataset.priceOverrides || '{}');
      const nextValue = priceNumber(input.value);
      overrides[input.dataset.unit] = nextValue;
      tr.dataset.priceOverrides = JSON.stringify(overrides);
      tr.dataset.autoAnchorUnit = input.dataset.unit;
      if (priceJsonInput) priceJsonInput.value = JSON.stringify(overrides);
      const rowPrice = tr.querySelector('.row-price');
      if (rowPrice && (unitSelect.value || '') === input.dataset.unit) {
        rowPrice.value = formatPrice(nextValue);
      }
      recalcRow(tr);
    });
  });

  if (selectedLabel) {
    selectedLabel.textContent = unitSelect.selectedOptions[0]?.textContent || '';
  }
}
function recalculateReceiptRowUnits(tr, product) {
  if (!tr || !product) return;
  const container = tr.querySelector('.row-unit-matrix');
  const unitSelect = tr.querySelector('.row-unit');
  const priceJsonInput = tr.querySelector('.row-unit-prices-json');
  const units = product.units || [];
  if (!container || !unitSelect || units.length < 2) return;
  const anchorCode = tr.dataset.autoAnchorUnit || unitSelect.value || product.unit;
  const anchorInput = container.querySelector(`.row-unit-card-price[data-unit="${anchorCode}"]`);
  const anchorValue = anchorInput?.value ?? ((unitSelect.value || '') === anchorCode ? tr.querySelector('.row-price')?.value : 0);
  const nextMap = unitPriceMap(units, anchorCode, anchorValue);
  tr.dataset.priceOverrides = JSON.stringify(nextMap);
  if (priceJsonInput) priceJsonInput.value = JSON.stringify(nextMap);
  container.querySelectorAll('.row-unit-card-price').forEach((card) => {
    card.value = formatPrice(nextMap[card.dataset.unit] ?? 0);
  });
  applyRowUnitPrice(tr, product, unitSelect.value || anchorCode);
  recalcRow(tr);
}
function formatReceiptProductLabel(product) {
  if (!product) return '';
  return `${product.name}${product.sku ? ` [${product.sku}]` : ''}`;
}

function formatReceiptProductMeta(product) {
  if (!product) return '';
  const parts = [];
  if (product.sku) {
    parts.push(`${RECEIPT_SEARCH_STRINGS.sku}: ${product.sku}`);
  }
  if (product.barcode) {
    parts.push(`${RECEIPT_SEARCH_STRINGS.barcode}: ${product.barcode}`);
  }
  return parts.join(' · ');
}

function closeRowSearchResults(tr) {
  const resultsWrap = tr?.querySelector('.row-product-results');
  if (resultsWrap) {
    resultsWrap.classList.add('hidden');
    resultsWrap.innerHTML = '';
  }
}

function clearRowProductSelection(tr, options = {}) {
  if (!tr) return;
  const keepInput = options.keepInput === true;
  const productSel = tr.querySelector('.row-product-select');
  const searchInput = tr.querySelector('.row-product-search-input');
  const meta = tr.querySelector('.row-product-selected-meta');
  const matrix = tr.querySelector('.row-unit-matrix');
  const label = tr.querySelector('.row-selected-unit');
  if (productSel) productSel.value = '';
  if (searchInput) {
    searchInput.dataset.selectedProductId = '';
    searchInput.dataset.selectedProductLabel = '';
    if (!keepInput) searchInput.value = '';
  }
  if (meta) meta.textContent = '';
  if (matrix) matrix.innerHTML = '';
  if (label) label.textContent = '';
  setRowActionButtonsState(tr, null);
  recalcRow(tr);
}

function updateRowProductDisplay(tr, product = null) {
  const searchInput = tr?.querySelector('.row-product-search-input');
  const meta = tr?.querySelector('.row-product-selected-meta');
  if (!searchInput) return;
  if (!product) {
    searchInput.dataset.selectedProductId = '';
    searchInput.dataset.selectedProductLabel = '';
    if (meta) meta.textContent = '';
    return;
  }
  const label = formatReceiptProductLabel(product);
  searchInput.value = label;
  searchInput.dataset.selectedProductId = String(product.id);
  searchInput.dataset.selectedProductLabel = label;
  if (meta) {
    meta.textContent = formatReceiptProductMeta(product);
  }
}

async function fetchReceiptProducts(query = '') {
  const trimmed = String(query || '').trim();
  const cacheKey = trimmed || '__recent__';
  if (receiptProductCache.has(cacheKey)) {
    return receiptProductCache.get(cacheKey);
  }

  const url = new URL(PRODUCT_SEARCH_URL, window.location.origin);
  if (trimmed) {
    url.searchParams.set('q', trimmed);
  }

  const response = await fetch(url.toString(), {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
  });
  const payload = await response.json();
  if (!response.ok) {
    throw new Error(payload?.message || payload?.error || 'Search failed');
  }
  const products = Array.isArray(payload?.products) ? payload.products : [];
  products.forEach((product) => {
    PROD_MAP[product.id] = product;
  });
  const data = { products, mode: payload?.mode || (trimmed ? 'search' : 'recent') };
  receiptProductCache.set(cacheKey, data);
  return data;
}

function renderRowSearchResults(tr, products, meta = {}) {
  const resultsWrap = tr?.querySelector('.row-product-results');
  if (!resultsWrap) return;

  if (!products.length) {
    const emptyText = meta.query
      ? RECEIPT_SEARCH_STRINGS.noResults
      : RECEIPT_SEARCH_STRINGS.recentHint;
    resultsWrap.innerHTML = `<div class="product-picker-empty">${escapeHtml(emptyText)}</div>`;
    resultsWrap.classList.remove('hidden');
    return;
  }

  resultsWrap.innerHTML = products.map((product, index) => `
    <button
      type="button"
      class="product-search-result ${index === 0 ? 'is-active' : ''}"
      data-product-id="${product.id}"
      data-result-index="${index}"
    >
      <div class="product-search-result-top">
        <span class="product-search-result-name">${escapeHtml(product.name)}</span>
        ${meta.mode === 'recent' ? `<span class="badge badge-secondary">${escapeHtml(RECEIPT_SEARCH_STRINGS.recentTitle)}</span>` : ''}
      </div>
      <div class="product-search-result-meta">
        <span>${escapeHtml(RECEIPT_SEARCH_STRINGS.sku)}: ${escapeHtml(product.sku || '-')}</span>
        <span>${escapeHtml(RECEIPT_SEARCH_STRINGS.barcode)}: ${escapeHtml(product.barcode || '-')}</span>
      </div>
      ${product.aliases ? `<div class="product-search-result-sub">${escapeHtml(RECEIPT_SEARCH_STRINGS.aliases)}: ${escapeHtml(product.aliases)}</div>` : ''}
    </button>
  `).join('');
  resultsWrap.classList.remove('hidden');
}

function activateRowSearchResult(tr, nextIndex) {
  const buttons = [...(tr?.querySelectorAll('.row-product-results .product-search-result') || [])];
  if (!buttons.length) return;
  const normalized = Math.max(0, Math.min(buttons.length - 1, nextIndex));
  buttons.forEach((button, index) => button.classList.toggle('is-active', index === normalized));
  const activeButton = buttons[normalized];
  activeButton?.scrollIntoView({ block: 'nearest' });
  const searchInput = tr.querySelector('.row-product-search-input');
  if (searchInput) {
    searchInput.dataset.activeResultIndex = String(normalized);
  }
}

function setRowActionButtonsState(tr, product = null) {
  const productSel = tr.querySelector('.row-product-select');
  const editBtn = tr.querySelector('.btn-edit-product-row');
  const calcBtn = tr.querySelector('.btn-row-calc');
  const currentProduct = product || PROD_MAP[productSel?.value];
  const enabled = Boolean(productSel?.value && currentProduct);
  if (editBtn) {
    editBtn.disabled = !enabled;
    editBtn.style.opacity = enabled ? '1' : '.45';
  }
  if (calcBtn) {
    const canCalculate = enabled && (currentProduct?.units || []).length > 1;
    calcBtn.style.display = canCalculate ? 'inline-flex' : 'none';
    calcBtn.disabled = !canCalculate;
    calcBtn.style.opacity = canCalculate ? '1' : '.45';
  }
}

function applyProductToRow(tr, product, options = {}) {
  if (!tr || !product) return;
  const preserveOverrides = options.preserveOverrides === true;
  const productSel = tr.querySelector('.row-product-select');
  const nameInput = tr.querySelector('.row-name');
  const unitSel = tr.querySelector('.row-unit');
  const matrix = tr.querySelector('.row-unit-matrix');
  const priceJsonInput = tr.querySelector('.row-unit-prices-json');
  const desiredUnit = unitSel?.value || product.unit;
  const nextUnit = (product.units || []).some((unit) => unit.code === desiredUnit) ? desiredUnit : product.unit;

  PROD_MAP[product.id] = product;
  if (productSel) productSel.value = String(product.id);
  if (nameInput) nameInput.value = product.name;
  updateRowProductDisplay(tr, product);
  fillUnitOptions(unitSel, product, nextUnit);
  if (!preserveOverrides) {
    tr.dataset.priceOverrides = JSON.stringify({});
    if (priceJsonInput) priceJsonInput.value = '';
  }
  closeRowSearchResults(tr);
  if (!matrix) return;
  applyRowUnitPrice(tr, product, unitSel?.value || product.unit);
  renderReceiptUnitMatrix(tr, product);
  setRowActionButtonsState(tr, product);
  recalcRow(tr);
}

function updateProductOptions(product) {
  PROD_MAP[product.id] = product;
  receiptProductCache.clear();
  document.querySelectorAll('#items-body tr').forEach((row) => {
    const productSel = row.querySelector('.row-product-select');
    if (String(productSel?.value || '') === String(product.id)) {
      updateRowProductDisplay(row, product);
    }
  });
}

function modalCurrentUnits() {
  const units = [{
    label: (prodBaseUnitLabel?.value || prodBaseUnitSelect?.selectedOptions?.[0]?.text || 'База').trim() || 'База',
    ratio: 1,
    code: prodBaseUnitSelect?.value || 'base',
  }];
  let cumulative = 1;
  document.querySelectorAll('#prod-unit-rows .prod-unit-row').forEach((row, idx) => {
    const label = (row.querySelector('.prod-unit-label')?.value || '').trim() || `Единица ${idx + 1}`;
    const stepValue = parseFloat(row.querySelector('.prod-unit-step')?.value || 1) || 1;
    cumulative *= stepValue > 0 ? stepValue : 1;
    const code = label.toLowerCase().replace(/\s+/g, '_') || `unit_${idx + 1}`;
    row.dataset.unitCode = code;
    units.push({ label, ratio: cumulative, code });
  });
  return units;
}

function syncModalDefaultUnitOptions() {
  if (!prodDefaultUnitSelect) return;
  const current = prodDefaultUnitSelect.value || prodBaseUnitSelect?.value || '';
  const units = modalCurrentUnits();
  prodDefaultUnitSelect.innerHTML = units.map((unit) => `
    <option value="${unit.code}" ${unit.code === current ? 'selected' : ''}>${unit.label}</option>
  `).join('');
  if (![...prodDefaultUnitSelect.options].some((option) => option.value === current) && units[0]) {
    prodDefaultUnitSelect.value = units[0].code;
  }
}

function refreshModalUnitPrompts() {
  let parentLabel = (prodBaseUnitLabel?.value || prodBaseUnitSelect?.selectedOptions?.[0]?.text || 'База').trim() || 'База';
  document.querySelectorAll('#prod-unit-rows .prod-unit-row').forEach((row, idx) => {
    const labels = row.querySelectorAll('.form-label');
    const currentLabel = (row.querySelector('.prod-unit-label')?.value || '').trim() || `Уровень ${idx + 2}`;
    if (labels[0]) {
      labels[0].textContent = `Уровень ${idx + 2}: внутри "${parentLabel}"`;
    }
    if (labels[1]) {
      labels[1].textContent = `Сколько "${currentLabel}" находится в "${parentLabel}"`;
    }
    parentLabel = currentLabel;
  });
}

function syncModalUnitRatios() {
  let cumulative = 1;
  document.querySelectorAll('#prod-unit-rows .prod-unit-row').forEach((row) => {
    const stepInput = row.querySelector('.prod-unit-step');
    const ratioInput = row.querySelector('.prod-unit-ratio');
    const stepValue = parseFloat(stepInput?.value || 1) || 1;
    cumulative *= stepValue > 0 ? stepValue : 1;
    if (ratioInput) ratioInput.value = cumulative.toFixed(3);
  });
  syncModalDefaultUnitOptions();
  refreshModalUnitPrompts();
}

function bindModalUnitRow(row) {
  row.querySelector('.prod-unit-remove')?.addEventListener('click', () => {
    row.remove();
    syncModalUnitRatios();
  });
  row.querySelector('.prod-unit-label')?.addEventListener('change', () => {
    syncModalDefaultUnitOptions();
    refreshModalUnitPrompts();
  });
  row.querySelector('.prod-unit-step')?.addEventListener('input', syncModalUnitRatios);
}

function addModalUnitRow(label = '', step = '1') {
  if (!prodUnitRowsWrap) return;
  const row = document.createElement('div');
  row.className = 'prod-unit-row';
  row.classList.add('unit-builder-row');
  row.innerHTML = `
    <div class="form-group mb-0">
      <label class="form-label">Следующая меньшая единица</label>
      <select class="form-control prod-unit-label unit-preset-select" data-allow-empty="1">
        ${unitPresetOptionsHtml(label, true)}
      </select>
    </div>
    <div class="form-group mb-0">
      <label class="form-label">Сколько внутри родителя</label>
      <input type="hidden" class="prod-unit-ratio" value="1">
      <input type="number" class="form-control mono prod-unit-step" value="${step}" min="0.001" step="0.001">
    </div>
    <button type="button" class="btn btn-sm btn-danger prod-unit-remove"><?= feather_icon('trash-2', 14) ?></button>
  `;
  prodUnitRowsWrap.appendChild(row);
  bindModalUnitRow(row);
  syncModalUnitRatios();
  feather.replace();
}

function fillProductModal(product) {
  _editingProductId = parseInt(product?.id || 0, 10) || 0;
  document.getElementById('prod-name').value = product?.name || product?.name_ru || product?.name_en || '';
  document.getElementById('prod-sku').value = product?.sku || '';
  document.getElementById('prod-barcode').value = product?.barcode || '';
  document.getElementById('prod-category').value = String(product?.category_id || document.getElementById('prod-category').value || '');
  refreshUnitPresetSelects(product?.base_unit_label || '', product?.base_unit || 'pcs');
  if (prodBaseUnitLabel) {
    prodBaseUnitLabel.value = product?.base_unit_label || prodBaseUnitLabel.value;
  }
  if (prodBaseUnitSelect) {
    prodBaseUnitSelect.value = product?.base_unit || prodBaseUnitSelect.value || 'pcs';
  }
  syncModalBaseUnitCode();
  if (prodUnitRowsWrap) prodUnitRowsWrap.innerHTML = '';
  (product?.unit_rows || []).forEach((row) => addModalUnitRow(row.label || '', String(row.step || 1)));
  syncModalUnitRatios();
  if (prodDefaultUnitSelect) {
    prodDefaultUnitSelect.value = product?.default_sale_unit || prodBaseUnitSelect?.value || '';
  }
  document.getElementById('prod-image').value = '';
  document.getElementById('prod-image-preview').style.display = 'none';
}

function resetProductModal() {
  _editingProductId = 0;
  ['prod-name','prod-sku','prod-barcode'].forEach((id) => {
    document.getElementById(id).value = '';
  });
  document.getElementById('prod-category').selectedIndex = 0;
  refreshUnitPresetSelects(prodBaseUnitLabel?.value || '', prodBaseUnitSelect?.value || 'pcs');
  if (prodBaseUnitLabel) prodBaseUnitLabel.selectedIndex = 0;
  if (prodBaseUnitSelect) prodBaseUnitSelect.selectedIndex = 0;
  syncModalBaseUnitCode();
  if (prodUnitRowsWrap) prodUnitRowsWrap.innerHTML = '';
  if (prodDefaultUnitSelect) prodDefaultUnitSelect.innerHTML = '';
  if (prodDefaultUnitSelect) prodDefaultUnitSelect.value = prodBaseUnitSelect?.value || '';
  document.getElementById('prod-image').value = '';
  document.getElementById('prod-image-preview').style.display = 'none';
  syncModalUnitRatios();
}

function openModal(id) {
  document.getElementById(id)?.classList.add('open');
}

function closeModal(id) {
  document.getElementById(id)?.classList.remove('open');
}

function resetDocConfirm() {
  pendingDocSubmitter = null;
  if (docConfirmCheckbox) docConfirmCheckbox.checked = false;
  if (docConfirmSubmit) docConfirmSubmit.disabled = true;
}

function closeDocConfirm() {
  resetDocConfirm();
  closeModal('modal-doc-confirm');
}

function openReceiptPostConfirm(submitter) {
  const form = document.getElementById('gr-form');
  if (!form || !submitter) return;
  if (!form.reportValidity()) return;

  const supplierSelect = document.getElementById('supplier-select');
  const warehouseSelect = form.querySelector('select[name="warehouse_id"]');
  const docNoInput = form.querySelector('input[name="doc_no"]');
  const docDateInput = form.querySelector('input[name="doc_date"]');

  pendingDocSubmitter = submitter;
  if (docConfirmTitle) docConfirmTitle.textContent = '<?= e(__('doc_confirm_post_title')) ?>';
  if (docConfirmCheckboxLabel) docConfirmCheckboxLabel.textContent = '<?= e(__('doc_confirm_post_checkbox')) ?>';
  if (docConfirmDocNo) docConfirmDocNo.textContent = docNoInput?.value?.trim() || '—';
  if (docConfirmDate) docConfirmDate.textContent = docDateInput?.value || '—';
  if (docConfirmSupplier) docConfirmSupplier.textContent = supplierSelect?.selectedOptions?.[0]?.textContent?.trim() || '—';
  if (docConfirmWarehouse) docConfirmWarehouse.textContent = warehouseSelect?.selectedOptions?.[0]?.textContent?.trim() || '—';
  if (docConfirmItems) docConfirmItems.textContent = String(document.querySelectorAll('#items-body tr').length);
  if (docConfirmTotal) docConfirmTotal.textContent = document.getElementById('grand-total')?.textContent?.trim() || '0.00';

  resetDocConfirm();
  pendingDocSubmitter = submitter;
  openModal('modal-doc-confirm');
}

document.querySelectorAll('[data-close-modal]').forEach((button) => {
  button.addEventListener('click', () => closeModal(button.dataset.closeModal));
});

function showError(id, message) {
  const el = document.getElementById(id);
  if (!el) return;
  el.textContent = message;
  el.classList.add('show');
}

function clearError(id) {
  const el = document.getElementById(id);
  if (!el) return;
  el.textContent = '';
  el.classList.remove('show');
}

function setLoading(button, on) {
  if (!button) return;
  button.disabled = on;
  button.style.opacity = on ? '.55' : '1';
}

function showUnitPresetError(message) {
  unitPresetError.textContent = message;
  unitPresetError.classList.add('show');
}

function clearUnitPresetError() {
  unitPresetError.textContent = '';
  unitPresetError.classList.remove('show');
}

function exactReceiptProductMatch(code, products) {
  const needle = String(code || '').trim().toLowerCase();
  if (!needle) return null;
  return products.find((product) => (
    String(product.barcode || '').trim().toLowerCase() === needle
    || String(product.sku || '').trim().toLowerCase() === needle
  )) || (products.length === 1 ? products[0] : null);
}

async function runRowProductSearch(tr, rawQuery = '', options = {}) {
  const searchInput = tr?.querySelector('.row-product-search-input');
  if (!searchInput) return [];

  const query = String(rawQuery || '').trim();
  const exactLike = /^[0-9A-Za-z._-]{4,}$/.test(query);
  if (query && !exactLike && query.length < 2) {
    const resultsWrap = tr.querySelector('.row-product-results');
    if (resultsWrap) {
      resultsWrap.innerHTML = `<div class="product-picker-empty">${escapeHtml(RECEIPT_SEARCH_STRINGS.searchMin)}</div>`;
      resultsWrap.classList.remove('hidden');
    }
    return [];
  }

  const token = `${Date.now()}_${Math.random()}`;
  searchInput.dataset.searchToken = token;
  try {
    const payload = await fetchReceiptProducts(query);
    if (searchInput.dataset.searchToken !== token) {
      return [];
    }
    renderRowSearchResults(tr, payload.products, { query, mode: payload.mode });
    searchInput.dataset.activeResultIndex = '0';
    return payload.products;
  } catch (_) {
    renderRowSearchResults(tr, [], { query, mode: 'search' });
    return [];
  }
}

function bindRowSearchResults(tr) {
  const resultsWrap = tr?.querySelector('.row-product-results');
  if (!resultsWrap || resultsWrap.dataset.bound === '1') {
    return;
  }
  resultsWrap.dataset.bound = '1';
  resultsWrap.addEventListener('mousedown', (event) => {
    const button = event.target.closest('.product-search-result');
    if (!button) return;
    event.preventDefault();
    const product = PROD_MAP[button.dataset.productId];
    if (product) {
      applyProductToRow(tr, product, { preserveOverrides: false });
      tr.querySelector('.row-qty')?.focus();
      tr.querySelector('.row-qty')?.select();
    }
  });
}

async function handleRowProductScan(tr, code) {
  const searchInput = tr?.querySelector('.row-product-search-input');
  if (!searchInput) return;
  searchInput.value = code;
  clearRowProductSelection(tr, { keepInput: true });
  const products = await runRowProductSearch(tr, code, { force: true });
  const exact = exactReceiptProductMatch(code, products);
  if (exact) {
    applyProductToRow(tr, exact, { preserveOverrides: false });
    tr.querySelector('.row-qty')?.focus();
    tr.querySelector('.row-qty')?.select();
  }
}

document.getElementById('btn-add-row')?.addEventListener('click', () => {
  rowIndex += 1;
  const html = document.getElementById('row-template').innerHTML.replaceAll('__IDX__', rowIndex);
  const tr = document.createElement('tr');
  tr.innerHTML = html;
  document.getElementById('items-body').appendChild(tr);
  feather.replace();
  initRow(tr);
  renumberRows();
  recalcTotals();
});

function initRow(tr) {
  const productSel = tr.querySelector('.row-product-select');
  const searchInput = tr.querySelector('.row-product-search-input');
  const pickerBtn = tr.querySelector('.btn-product-picker-row');
  const cameraBtn = tr.querySelector('.btn-product-camera-row');
  const unitSel = tr.querySelector('.row-unit');
  const priceInput = tr.querySelector('.row-price');
  const delBtn = tr.querySelector('.btn-del-row');
  const calcBtn = tr.querySelector('.btn-row-calc');
  const newProdBtn = tr.querySelector('.btn-new-product-row');
  const editProdBtn = tr.querySelector('.btn-edit-product-row');

  bindRowSearchResults(tr);

  searchInput?.addEventListener('focus', () => {
    if (!String(searchInput.value || '').trim()) {
      runRowProductSearch(tr, '', { force: true });
    }
  });

  searchInput?.addEventListener('input', () => {
    const typed = String(searchInput.value || '').trim();
    if (typed !== String(searchInput.dataset.selectedProductLabel || '').trim()) {
      clearRowProductSelection(tr, { keepInput: true });
    }
    window.clearTimeout(searchInput._searchTimer || 0);
    searchInput._searchTimer = window.setTimeout(() => {
      runRowProductSearch(tr, typed, { force: true });
    }, typed ? 140 : 0);
  });

  searchInput?.addEventListener('keydown', (event) => {
    const resultsButtons = [...(tr.querySelectorAll('.row-product-results .product-search-result') || [])];
    if (!resultsButtons.length) {
      return;
    }
    const currentIndex = parseInt(searchInput.dataset.activeResultIndex || '0', 10) || 0;
    if (event.key === 'ArrowDown') {
      event.preventDefault();
      activateRowSearchResult(tr, currentIndex + 1);
      return;
    }
    if (event.key === 'ArrowUp') {
      event.preventDefault();
      activateRowSearchResult(tr, currentIndex - 1);
      return;
    }
    if (event.key === 'Enter') {
      event.preventDefault();
      const active = resultsButtons[parseInt(searchInput.dataset.activeResultIndex || '0', 10) || 0];
      const product = active ? PROD_MAP[active.dataset.productId] : null;
      if (product) {
        applyProductToRow(tr, product, { preserveOverrides: false });
        tr.querySelector('.row-qty')?.focus();
        tr.querySelector('.row-qty')?.select();
      }
      return;
    }
    if (event.key === 'Escape') {
      closeRowSearchResults(tr);
    }
  });

  searchInput?.addEventListener('blur', () => {
    window.setTimeout(() => closeRowSearchResults(tr), 120);
  });

  unitSel?.addEventListener('change', function () {
    const product = PROD_MAP[productSel?.value];
    if (!product) return;
    applyRowUnitPrice(tr, product, this.value);
    renderReceiptUnitMatrix(tr, product);
    recalcRow(tr);
  });

  priceInput?.addEventListener('input', function () {
    const product = PROD_MAP[productSel?.value];
    if (!product) return;
    const currentUnit = unitSel?.value || product.unit;
    tr.dataset.autoAnchorUnit = currentUnit;
    rememberRowPrice(tr, product, currentUnit, this.value);
    const matrixInput = tr.querySelector(`.row-unit-card-price[data-unit="${currentUnit}"]`);
    if (matrixInput && matrixInput !== this) {
      matrixInput.value = formatPrice(this.value);
    }
    recalcRow(tr);
  });

  newProdBtn?.addEventListener('click', () => openProductModal(tr));
  pickerBtn?.addEventListener('click', () => openProductPicker(tr));
  calcBtn?.addEventListener('click', () => {
    const product = PROD_MAP[productSel?.value];
    if (!product) return;
    recalculateReceiptRowUnits(tr, product);
  });
  editProdBtn?.addEventListener('click', () => {
    if (!productSel?.value || !PROD_MAP[productSel.value]) return;
    openProductModal(tr, productSel.value);
  });

  tr.querySelectorAll('.row-qty, .row-price, .row-tax').forEach((input) => {
    input.addEventListener('input', () => recalcRow(tr));
  });

  delBtn?.addEventListener('click', () => {
    tr.remove();
    renumberRows();
    recalcTotals();
  });

  if (cameraBtn && window.ProductCameraScanner) {
    window.ProductCameraScanner.attach(cameraBtn, {
      onDetected: (code) => handleRowProductScan(tr, code),
    });
  }

  recalcRow(tr);
  if (productSel?.value && PROD_MAP[productSel.value]) {
    applyProductToRow(tr, PROD_MAP[productSel.value], { preserveOverrides: true });
  } else {
    setRowActionButtonsState(tr, null);
  }
}

function recalcRow(tr) {
  const qty = parseFloat(tr.querySelector('.row-qty')?.value) || 0;
  const price = parseFloat(tr.querySelector('.row-price')?.value) || 0;
  const totalCell = tr.querySelector('.row-total');
  if (totalCell) {
    totalCell.textContent = (qty * price).toFixed(2);
  }
  recalcTotals();
}

function recalcTotals() {
  let subtotal = 0;
  let tax = 0;
  document.querySelectorAll('#items-body tr').forEach((tr) => {
    const qty = parseFloat(tr.querySelector('.row-qty')?.value) || 0;
    const price = parseFloat(tr.querySelector('.row-price')?.value) || 0;
    const rate = parseFloat(tr.querySelector('.row-tax')?.value) || 0;
    const line = qty * price;
    subtotal += line;
    tax += line * rate / 100;
  });
  document.getElementById('grand-subtotal').textContent = subtotal.toFixed(2);
  document.getElementById('grand-tax').textContent = tax.toFixed(2);
  document.getElementById('grand-total').textContent = (subtotal + tax).toFixed(2);
}

function renumberRows() {
  document.querySelectorAll('#items-body tr').forEach((tr, idx) => {
    const cell = tr.querySelector('.row-num');
    if (cell) cell.textContent = idx + 1;
  });
}

document.querySelectorAll('#items-body tr').forEach(initRow);
recalcTotals();

document.addEventListener('click', (event) => {
  if (!event.target.closest('.receipt-product-search')) {
    document.querySelectorAll('#items-body tr').forEach((row) => closeRowSearchResults(row));
  }
});

function renderPickerList(products, meta = {}) {
  if (!pickerList || !pickerEmpty) return;
  if (!products.length) {
    pickerList.innerHTML = '';
    pickerEmpty.classList.remove('hidden');
    pickerEmpty.textContent = meta.query ? RECEIPT_SEARCH_STRINGS.noResults : RECEIPT_SEARCH_STRINGS.pickerEmpty;
    return;
  }

  pickerEmpty.classList.add('hidden');
  pickerList.innerHTML = products.map((product) => `
    <button type="button" class="product-search-result" data-picker-product-id="${product.id}">
      <div class="product-search-result-top">
        <span class="product-search-result-name">${escapeHtml(product.name)}</span>
        ${meta.mode === 'recent' ? `<span class="badge badge-secondary">${escapeHtml(RECEIPT_SEARCH_STRINGS.recentTitle)}</span>` : ''}
      </div>
      <div class="product-search-result-meta">
        <span>${escapeHtml(RECEIPT_SEARCH_STRINGS.sku)}: ${escapeHtml(product.sku || '-')}</span>
        <span>${escapeHtml(RECEIPT_SEARCH_STRINGS.barcode)}: ${escapeHtml(product.barcode || '-')}</span>
      </div>
      ${product.aliases ? `<div class="product-search-result-sub">${escapeHtml(RECEIPT_SEARCH_STRINGS.aliases)}: ${escapeHtml(product.aliases)}</div>` : ''}
    </button>
  `).join('');
}

async function loadProductPicker(query = '') {
  const normalizedQuery = String(query || '').trim();
  const exactLike = /^[0-9A-Za-z._-]{4,}$/.test(normalizedQuery);
  if (normalizedQuery && !exactLike && normalizedQuery.length < 2) {
    _pickerResults = [];
    pickerList.innerHTML = '';
    pickerEmpty.classList.remove('hidden');
    pickerEmpty.textContent = RECEIPT_SEARCH_STRINGS.searchMin;
    return [];
  }
  try {
    const payload = await fetchReceiptProducts(normalizedQuery);
    _pickerResults = payload.products;
    renderPickerList(payload.products, { mode: payload.mode, query: normalizedQuery });
    return payload.products;
  } catch (_) {
    _pickerResults = [];
    renderPickerList([], { mode: 'search', query: normalizedQuery });
    return [];
  }
}

function openProductPicker(tr) {
  _targetRow = tr;
  if (pickerSearchInput) {
    pickerSearchInput.value = '';
  }
  renderPickerList([], { mode: 'recent', query: '' });
  openModal('modal-product-picker');
  loadProductPicker('');
  window.setTimeout(() => pickerSearchInput?.focus(), 70);
}

document.getElementById('btn-new-supplier')?.addEventListener('click', () => {
  clearError('supplier-error');
  ['sup-name','sup-phone','sup-inn','sup-contact','sup-address'].forEach((id) => {
    document.getElementById(id).value = '';
  });
  openModal('modal-supplier');
  setTimeout(() => document.getElementById('sup-name').focus(), 80);
});

document.getElementById('btn-supplier-save')?.addEventListener('click', async function () {
  clearError('supplier-error');
  const name = document.getElementById('sup-name').value.trim();
  if (!name) {
    showError('supplier-error', <?= json_for_html(_r('lbl_required') . ': ' . _r('lbl_name')) ?>);
    return;
  }
  setLoading(this, true);
  try {
    const fd = new FormData();
    fd.append('action', 'create_supplier');
    fd.append('_token', CSRF_TOKEN);
    fd.append('name', name);
    fd.append('phone', document.getElementById('sup-phone').value.trim());
    fd.append('inn', document.getElementById('sup-inn').value.trim());
    fd.append('contact', document.getElementById('sup-contact').value.trim());
    fd.append('address', document.getElementById('sup-address').value.trim());
    const json = await postAjax(fd);
    if (!json.success) {
      showError('supplier-error', json.error);
      return;
    }
    const select = document.getElementById('supplier-select');
    select.add(new Option(json.data.name, json.data.id, true, true));
    select.value = json.data.id;
    closeModal('modal-supplier');
  } catch (error) {
    showError('supplier-error', error.message || 'Network error');
  } finally {
    setLoading(this, false);
  }
});

pickerSearchInput?.addEventListener('input', () => {
  window.clearTimeout(_pickerSearchTimer);
  _pickerSearchTimer = window.setTimeout(() => {
    loadProductPicker(pickerSearchInput.value || '');
  }, (pickerSearchInput.value || '').trim() ? 140 : 0);
});

pickerList?.addEventListener('mousedown', (event) => {
  const button = event.target.closest('[data-picker-product-id]');
  if (!button) return;
  event.preventDefault();
  const product = PROD_MAP[button.dataset.pickerProductId];
  if (_targetRow && product) {
    applyProductToRow(_targetRow, product, { preserveOverrides: false });
    closeModal('modal-product-picker');
    _targetRow.querySelector('.row-qty')?.focus();
    _targetRow.querySelector('.row-qty')?.select();
  }
});

if (pickerCameraBtn && window.ProductCameraScanner) {
  window.ProductCameraScanner.attach(pickerCameraBtn, {
    onDetected: async (code) => {
      if (pickerSearchInput) {
        pickerSearchInput.value = code;
      }
      const products = await loadProductPicker(code);
      const exact = exactReceiptProductMatch(code, products);
      if (_targetRow && exact) {
        applyProductToRow(_targetRow, exact, { preserveOverrides: false });
        closeModal('modal-product-picker');
        _targetRow.querySelector('.row-qty')?.focus();
        _targetRow.querySelector('.row-qty')?.select();
      }
    },
  });
}

function openProductModal(tr, productId = 0) {
  _targetRow = tr;
  clearError('product-error');
  resetProductModal();
  if (productId && PROD_MAP[productId]) {
    fillProductModal(PROD_MAP[productId]);
  }
  openModal('modal-product');
  setTimeout(() => document.getElementById('prod-name').focus(), 80);
}

document.getElementById('prod-add-unit-row')?.addEventListener('click', () => addModalUnitRow());
prodBaseUnitLabel?.addEventListener('change', () => {
  syncModalBaseUnitCode();
  syncModalDefaultUnitOptions();
  refreshModalUnitPrompts();
});
prodBaseUnitSelect?.addEventListener('change', syncModalUnitRatios);
prodDefaultUnitSelect?.addEventListener('change', syncModalDefaultUnitOptions);
unitPresetOpenBtn?.addEventListener('click', () => {
  clearUnitPresetError();
  unitPresetNameInput.value = '';
  openModal('modal-unit-preset');
  setTimeout(() => unitPresetNameInput.focus(), 60);
});

unitPresetSaveBtn?.addEventListener('click', async function () {
  const label = (unitPresetNameInput.value || '').trim();
  if (!label) {
    showUnitPresetError('Введите название единицы.');
    return;
  }
  clearUnitPresetError();
  setLoading(this, true);
  try {
    const fd = new FormData();
    fd.append('action', 'create_unit_preset');
    fd.append('_token', CSRF_TOKEN);
    fd.append('label', label);
    const json = await postAjaxTo(UNIT_PRESET_AJAX_URL, fd);
    if (!json.success) {
      showUnitPresetError(json.error || 'Не удалось сохранить единицу.');
      return;
    }
    if (!UNIT_PRESETS.some((preset) => preset.label === json.data.unit_label)) {
      UNIT_PRESETS.push({ label: json.data.unit_label, storageCode: json.data.storage_code || 'pcs' });
    }
    refreshUnitPresetSelects(json.data.unit_label, json.data.storage_code || 'pcs');
    syncModalBaseUnitCode();
    syncModalDefaultUnitOptions();
    refreshModalUnitPrompts();
    closeModal('modal-unit-preset');
  } catch (error) {
    showUnitPresetError(error.message || 'Network error');
  } finally {
    setLoading(this, false);
  }
});

productSaveBtn?.addEventListener('click', async function () {
  clearError('product-error');
    const productName = document.getElementById('prod-name').value.trim();
  if (!productName) {
    showError('product-error', <?= json_for_html(_r('lbl_required') . ': ' . _r('lbl_name')) ?>);
    return;
  }

  const isEdit = _editingProductId > 0;
  setLoading(this, true);
  try {
    const fd = new FormData();
    fd.append('action', isEdit ? 'update_product' : 'create_product');
    fd.append('_token', CSRF_TOKEN);
    if (isEdit) {
      fd.append('product_id', String(_editingProductId));
    }
    fd.append('name', productName);
    fd.append('sku', document.getElementById('prod-sku').value.trim());
    fd.append('barcode', document.getElementById('prod-barcode').value.trim());
    fd.append('category_id', document.getElementById('prod-category').value);
    fd.append('unit', document.getElementById('prod-unit').value);
    fd.append('unit_label', document.getElementById('prod-unit-label').value.trim());
    fd.append('default_sale_unit', document.getElementById('prod-default-unit')?.value || document.getElementById('prod-unit').value);
    document.querySelectorAll('#prod-unit-rows .prod-unit-row').forEach((row, idx) => {
      fd.append(`unit_rows[${idx}][unit_label]`, row.querySelector('.prod-unit-label')?.value.trim() || '');
      fd.append(`unit_rows[${idx}][ratio_to_base]`, row.querySelector('.prod-unit-ratio')?.value || '1');
    });
    const imgFile = document.getElementById('prod-image').files[0];
    if (imgFile) fd.append('image', imgFile);

    const json = await postAjax(fd);
    if (!json.success) {
      showError('product-error', json.error);
      return;
    }

    const product = json.data;
    PROD_MAP[product.id] = product;
    updateProductOptions(product);

    document.querySelectorAll('.row-product-select').forEach((select) => {
      if (String(select.value) === String(product.id)) {
        const row = select.closest('tr');
        if (row) {
          applyProductToRow(row, product, { preserveOverrides: isEdit });
        }
      }
    });

    if (_targetRow) {
      applyProductToRow(_targetRow, product, { preserveOverrides: isEdit });
    }

    _editingProductId = 0;
    closeModal('modal-product');
  } catch (error) {
    showError('product-error', error.message || 'Network error');
  } finally {
    setLoading(this, false);
  }
});

document.getElementById('prod-image')?.addEventListener('change', function () {
  const preview = document.getElementById('prod-image-preview');
  if (this.files && this.files[0]) {
    const reader = new FileReader();
    reader.onload = (event) => {
      preview.src = event.target.result;
      preview.style.display = 'block';
    };
    reader.readAsDataURL(this.files[0]);
  } else {
    preview.style.display = 'none';
  }
});

refreshUnitPresetSelects(prodBaseUnitLabel?.value || '', prodBaseUnitSelect?.value || 'pcs');
syncModalBaseUnitCode();
syncModalUnitRatios();

async function postAjaxTo(url, formData) {
  const res = await fetch(url, {
    method: 'POST',
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    body: formData,
  });
  const raw = await res.text();
  const json = (() => {
    try {
      return raw ? JSON.parse(raw) : null;
    } catch (_) {
      return null;
    }
  })();
  if (!res.ok) {
    throw new Error(json?.error || raw || ('HTTP ' + res.status));
  }
  if (!json) {
    return {
      success: false,
      error: raw || 'Invalid server response',
    };
  }
  return json;
}

async function postAjax(formData) {
  return postAjaxTo(AJAX_URL, formData);
}

document.querySelectorAll('[data-doc-confirm="receipt-post"]').forEach((button) => {
  button.addEventListener('click', (event) => {
    event.preventDefault();
    openReceiptPostConfirm(button);
  });
});

document.getElementById('doc-confirm-close')?.addEventListener('click', closeDocConfirm);
document.getElementById('doc-confirm-cancel')?.addEventListener('click', closeDocConfirm);
docConfirmCheckbox?.addEventListener('change', () => {
  if (docConfirmSubmit) {
    docConfirmSubmit.disabled = !docConfirmCheckbox.checked;
  }
});
docConfirmSubmit?.addEventListener('click', () => {
  if (!pendingDocSubmitter || !docConfirmCheckbox?.checked) return;
  const submitter = pendingDocSubmitter;
  closeDocConfirm();
  submitter.form?.requestSubmit(submitter);
});
</script>
