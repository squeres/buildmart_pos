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
$products   = Database::all("SELECT id, category_id, name_en, name_ru, sku, barcode, unit, cost_price, sale_price, image FROM products WHERE is_active=1 ORDER BY name_en");
$categories = Database::all("SELECT id, name_en, name_ru FROM categories WHERE is_active=1 ORDER BY sort_order, name_en");
$unitPresets = unit_preset_rows();

$productsJs = [];
foreach ($products as $p) {
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
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
      <span class="card-title"><?= __('gr_items') ?></span>
      <button type="button" id="btn-add-row" class="btn btn-sm btn-secondary">
        <?= feather_icon('plus', 14) ?> <?= __('gr_add_row') ?>
      </button>
    </div>
    <div class="table-wrap">
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
    <div class="card-body" style="display:flex;gap:10px;flex-wrap:wrap">
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
          <label class="form-label"><?= __('lbl_name') ?> (RU) <span class="req">*</span></label>
          <input type="text" id="prod-name-ru" class="form-control" placeholder="<?= __('prod_name_ru_placeholder') ?>" maxlength="200">
        </div>
        <div class="form-group">
          <label class="form-label"><?= __('lbl_name') ?> (EN)</label>
          <input type="text" id="prod-name-en" class="form-control" placeholder="<?= __('prod_name_en_placeholder') ?>" maxlength="200">
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
          <input type="text" id="prod-barcode" class="form-control font-mono" placeholder="4600000000000" maxlength="60">
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
        <div id="prod-unit-rows" style="display:flex;flex-direction:column;gap:10px"></div>
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

const PRODUCTS = <?= json_encode(array_values($productsJs), JSON_UNESCAPED_UNICODE) ?>;
const PROD_MAP = {};
PRODUCTS.forEach((product) => {
  PROD_MAP[product.id] = product;
});

const AJAX_URL = <?= json_encode(url('modules/receipts/ajax_create.php')) ?>;
const CSRF_TOKEN = document.querySelector('input[name="_token"]')?.value || '';
const UNIT_PRESET_AJAX_URL = <?= json_encode(url('modules/common/ajax_units.php')) ?>;
const UNIT_PRESETS = <?= json_encode(array_values(array_map(static fn($row) => ['label' => (string)$row['unit_label'], 'storageCode' => unit_storage_code_from_label((string)$row['unit_label'])], $unitPresets)), JSON_UNESCAPED_UNICODE) ?>;

let rowIndex = <?= max(count($items) - 1, -1) ?>;
let _targetRow = null;
let _editingProductId = 0;

const prodUnitRowsWrap = document.getElementById('prod-unit-rows');
const prodBaseUnitSelect = document.getElementById('prod-unit');
const prodBaseUnitLabel = document.getElementById('prod-unit-label');
const prodDefaultUnitSelect = document.getElementById('prod-default-unit');
const productSaveBtn = document.getElementById('btn-product-save');
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
    <div style="display:grid;grid-template-columns:auto minmax(130px,1.2fr) minmax(120px,1fr);gap:8px;align-items:end;padding:8px 10px;border:1px solid var(--border-soft);border-radius:10px;background:var(--bg-raised)">
      <label style="display:flex;align-items:center;justify-content:center;height:36px">
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

  if (productSel) productSel.value = String(product.id);
  if (nameInput) nameInput.value = product.name;
  fillUnitOptions(unitSel, product, nextUnit);
  if (!preserveOverrides) {
    tr.dataset.priceOverrides = JSON.stringify({});
    if (priceJsonInput) priceJsonInput.value = '';
  }
  if (!matrix) return;
  applyRowUnitPrice(tr, product, unitSel?.value || product.unit);
  renderReceiptUnitMatrix(tr, product);
  setRowActionButtonsState(tr, product);
  recalcRow(tr);
}

function updateProductOptions(product) {
  const label = `${product.name} [${product.sku}]`;
  document.querySelectorAll('.row-product-select').forEach((select) => {
    const option = [...select.options].find((item) => item.value === String(product.id));
    if (option) {
      option.textContent = label;
    } else {
      select.add(new Option(label, product.id));
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
  row.style.cssText = 'display:grid;grid-template-columns:1.2fr .9fr auto;gap:10px;align-items:end';
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
  document.getElementById('prod-name-ru').value = product?.name_ru || '';
  document.getElementById('prod-name-en').value = product?.name_en || '';
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
  ['prod-name-ru','prod-name-en','prod-sku','prod-barcode'].forEach((id) => {
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
  const unitSel = tr.querySelector('.row-unit');
  const priceInput = tr.querySelector('.row-price');
  const delBtn = tr.querySelector('.btn-del-row');
  const calcBtn = tr.querySelector('.btn-row-calc');
  const newProdBtn = tr.querySelector('.btn-new-product-row');
  const editProdBtn = tr.querySelector('.btn-edit-product-row');

  productSel?.addEventListener('change', function () {
    const product = PROD_MAP[this.value];
    if (product) {
      applyProductToRow(tr, product, { preserveOverrides: false });
    } else {
      const matrix = tr.querySelector('.row-unit-matrix');
      const label = tr.querySelector('.row-selected-unit');
      if (matrix) matrix.innerHTML = '';
      if (label) label.textContent = '';
      setRowActionButtonsState(tr, null);
      recalcRow(tr);
    }
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
    showError('supplier-error', <?= json_encode(_r('lbl_required') . ': ' . _r('lbl_name')) ?>);
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

function openProductModal(tr, productId = 0) {
  _targetRow = tr;
  clearError('product-error');
  resetProductModal();
  if (productId && PROD_MAP[productId]) {
    fillProductModal(PROD_MAP[productId]);
  }
  openModal('modal-product');
  setTimeout(() => document.getElementById('prod-name-ru').focus(), 80);
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
  const nameRu = document.getElementById('prod-name-ru').value.trim();
  const nameEn = document.getElementById('prod-name-en').value.trim();
  if (!nameRu && !nameEn) {
    showError('product-error', <?= json_encode(_r('lbl_required') . ': ' . _r('lbl_name')) ?>);
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
    fd.append('name_ru', nameRu);
    fd.append('name_en', nameEn);
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
