<?php
require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../views/partials/icons.php';
Auth::requireLogin();

$id     = (int)($_GET['id'] ?? 0);
$isEdit = $id > 0;
$inventoryPopupMode = !$isEdit && !empty($_GET['inventory_popup']);
$inventorySeedQuery = sanitize($_GET['inventory_query'] ?? '');
$inventoryWarehouseId = (int)($_GET['warehouse_id'] ?? 0);

if ($isEdit) {
    Auth::requirePerm('products.edit');
} elseif ($inventoryPopupMode && !Auth::can('products.create')) {
    Auth::requirePerm('inventory.create_product');
} else {
    Auth::requirePerm('products.create');
}

$prod   = $isEdit ? Database::row("SELECT * FROM products WHERE id=?", [$id]) : null;
if ($prod) {
    $prod['stock_qty'] = InventoryService::getTotalStock($id);
}

if ($isEdit && !$prod) {
    flash_error(_r('err_not_found'));
    redirect('/modules/products/');
}

$pageTitle   = $isEdit ? __('prod_edit') : __('prod_add');
$breadcrumbs = [[__('prod_title'), url('modules/products/')], [$pageTitle, null]];
$priceTypes  = UISettings::allActivePriceTypes();
$savedPrices = $isEdit ? UISettings::productPrices($id) : [];
$unitPriceOverrides = $isEdit ? product_unit_price_overrides($id) : [];
$replenishmentClassColumnReady = replenishment_has_product_column('replenishment_class');
$targetStockColumnReady = replenishment_has_product_column('target_stock_qty');
$targetStockUnitColumnReady = replenishment_has_product_column('target_stock_display_unit_code');

$emptyProduct = [
    'name_en'=>'','name_ru'=>'','sku'=>'','barcode'=>'','brand'=>'',
    'category_id'=>'','unit'=>'pcs','sale_price'=>'','cost_price'=>'',
    'tax_rate'=>setting('default_tax_rate',20),'stock_qty'=>0,'min_stock_qty'=>0,'min_stock_display_unit_code'=>'',
    'replenishment_class'=>'C','target_stock_qty'=>0,'target_stock_display_unit_code'=>'',
    'description_en'=>'','description_ru'=>'','allow_discount'=>1,
    'is_weighable'=>0,'is_active'=>1,'image'=>null,
];
$f = $prod ? array_merge($emptyProduct, $prod) : $emptyProduct;
if ($inventoryPopupMode && !$isEdit && $inventorySeedQuery !== '') {
    if (preg_match('/^\d[\d\s-]{4,}$/', $inventorySeedQuery)) {
        if ($f['barcode'] === '') {
            $f['barcode'] = preg_replace('/\s+/', '', $inventorySeedQuery) ?? $inventorySeedQuery;
        }
    } else {
        if ($f['name_ru'] === '') {
            $f['name_ru'] = $inventorySeedQuery;
        }
        if ($f['name_en'] === '') {
            $f['name_en'] = $inventorySeedQuery;
        }
    }
}
$defaultSaleUnitCode = $f['unit'];
$productUnits = $isEdit ? product_units($id, $f['unit']) : [[
    'unit_code' => $f['unit'],
    'unit_label' => unit_label($f['unit']),
    'ratio_to_base' => 1,
    'sort_order' => 0,
    'is_default' => 1,
]];
$baseUnitRow = $productUnits[0];
foreach ($productUnits as $unitRow) {
    if ($unitRow['unit_code'] === $f['unit']) {
        $baseUnitRow = $unitRow;
        break;
    }
}
$baseUnitLabel = product_unit_label_text($baseUnitRow);
$extraUnits = array_values(array_filter($productUnits, static fn($unit) => (float)$unit['ratio_to_base'] !== 1.0 || $unit['unit_code'] !== $f['unit']));
usort($extraUnits, static fn($a, $b) => (float)$a['ratio_to_base'] <=> (float)$b['ratio_to_base']);
foreach ($productUnits as $unitRow) {
    if (!empty($unitRow['is_default'])) {
        $defaultSaleUnitCode = (string)$unitRow['unit_code'];
        break;
    }
}
$defaultSaleUnitRatio = 1.0;
foreach ($productUnits as $unitRow) {
    if ((string)$unitRow['unit_code'] === $defaultSaleUnitCode) {
        $defaultSaleUnitRatio = (float)$unitRow['ratio_to_base'];
        break;
    }
}
$f['prices'] = [];
foreach ($priceTypes as $priceType) {
    $code = $priceType['code'];
    $fallback = match ($code) {
        'retail' => $f['sale_price'],
        'purchase' => $f['cost_price'],
        default => '',
    };
    $f['prices'][$code] = (string)($savedPrices[$code] ?? $fallback);
}
$minStockState = product_min_stock_data($f, $productUnits);
$f['min_stock_qty'] = number_format((float)$minStockState['display_qty'], 3, '.', '');
$f['min_stock_display_unit_code'] = (string)$minStockState['display_unit_code'];
$targetStockState = product_target_stock_data($f, $productUnits);
$f['target_stock_qty'] = number_format((float)$targetStockState['display_qty'], 3, '.', '');
$f['target_stock_display_unit_code'] = (string)$targetStockState['display_unit_code'];
$unitPriceRows = product_unit_price_rows($productUnits, $priceTypes, array_map('floatval', $f['prices']), $unitPriceOverrides);

$errors = [];

if (is_post()) {
    if (!csrf_verify()) { flash_error(_r('err_csrf')); redirect($_SERVER['REQUEST_URI']); }

    $enteredStockQty = $inventoryPopupMode ? 0.0 : sanitize_float($_POST['stock_qty'] ?? 0);
    $enteredMinStockQty = sanitize_float($_POST['min_stock_qty'] ?? 0);
    $enteredTargetStockQty = sanitize_float($_POST['target_stock_qty'] ?? 0);
    $f = [
        'name_en'       => sanitize($_POST['name_en']        ?? ''),
        'name_ru'       => sanitize($_POST['name_ru']        ?? ''),
        'sku'           => strtoupper(sanitize($_POST['sku'] ?? '')),
        'barcode'       => sanitize($_POST['barcode']        ?? ''),
        'brand'         => sanitize($_POST['brand']          ?? ''),
        'category_id'   => (int)($_POST['category_id']       ?? 0),
        'unit'          => sanitize($_POST['unit']           ?? 'pcs'),
        'sale_price'    => sanitize_float($_POST['sale_price']    ?? 0),
        'cost_price'    => sanitize_float($_POST['cost_price']    ?? 0),
        'tax_rate'      => sanitize_float($_POST['tax_rate']      ?? 0),
        'stock_qty'     => $isEdit ? (float)$prod['stock_qty'] : $enteredStockQty,
        'min_stock_qty' => $enteredMinStockQty,
        'min_stock_display_unit_code' => sanitize($_POST['min_stock_display_unit_code'] ?? ''),
        'replenishment_class' => replenishment_class_normalize($_POST['replenishment_class'] ?? 'C'),
        'target_stock_qty' => $enteredTargetStockQty,
        'target_stock_display_unit_code' => sanitize($_POST['target_stock_display_unit_code'] ?? ''),
        'description_en'=> sanitize($_POST['description_en'] ?? ''),
        'description_ru'=> sanitize($_POST['description_ru'] ?? ''),
        'allow_discount'=> isset($_POST['allow_discount']) ? 1 : 0,
        'is_weighable'  => isset($_POST['is_weighable'])   ? 1 : 0,
        'is_active'     => isset($_POST['is_active'])       ? 1 : 0,
        'image'         => $prod['image'] ?? null,
        'prices'        => [],
        'unit_rows'     => $_POST['unit_rows'] ?? [],
        'unit_price_rows' => $_POST['unit_price_rows'] ?? [],
        'base_unit_label' => sanitize($_POST['base_unit_label'] ?? ''),
    ];
    $baseUnitLabel = $f['base_unit_label'] ?: unit_label($f['unit']);
    $defaultSaleUnitCode = sanitize($_POST['default_sale_unit'] ?? $f['unit']);

    foreach ($priceTypes as $priceType) {
        $code = $priceType['code'];
        $f['prices'][$code] = (string)($_POST['prices'][$code] ?? '');
    }
    $allUnitsForPrices = normalize_product_units($f['unit_rows'], $f['unit'], $defaultSaleUnitCode, $baseUnitLabel);
    $productUnits = $allUnitsForPrices;

    $f['sale_price'] = sanitize_float($f['prices']['retail'] ?? $f['sale_price']);
    $f['cost_price'] = sanitize_float($f['prices']['purchase'] ?? $f['cost_price']);

    if (!$f['name_en'])     $errors['name_en']    = _r('lbl_required');
    if (!$f['sku'])         $errors['sku']         = _r('lbl_required');
    if (!$f['category_id']) $errors['category_id'] = _r('lbl_required');
    if ($f['sale_price'] <= 0) $errors['sale_price'] = _r('lbl_required');
    $extraUnits = $allUnitsForPrices;
    $extraUnits = array_values(array_filter($extraUnits, static fn($unit) => (float)$unit['ratio_to_base'] !== 1.0 || $unit['unit_code'] !== $f['unit']));
    usort($extraUnits, static fn($a, $b) => (float)$a['ratio_to_base'] <=> (float)$b['ratio_to_base']);
    $unitPriceRows = product_unit_price_rows($allUnitsForPrices, $priceTypes, array_map('floatval', $f['prices']), $f['unit_price_rows']);
    $defaultDisplayRatio = 1.0;
    foreach ($allUnitsForPrices as $unitRow) {
        if ((string)$unitRow['unit_code'] === $defaultSaleUnitCode) {
            $defaultDisplayRatio = (float)$unitRow['ratio_to_base'];
            break;
        }
    }
    $stockQtyBase = $isEdit ? (float)$prod['stock_qty'] : ($inventoryPopupMode ? 0.0 : ($enteredStockQty / max(1.0, $defaultDisplayRatio)));
    $resolvedMinStockUnit = product_resolve_unit(
        $allUnitsForPrices,
        $f['unit'],
        $f['min_stock_display_unit_code']
    );
    $f['min_stock_display_unit_code'] = (string)$resolvedMinStockUnit['unit_code'];
    $minStockQtyBase = product_qty_to_base_unit(
        $enteredMinStockQty,
        $allUnitsForPrices,
        $f['unit'],
        $f['min_stock_display_unit_code']
    );
    $resolvedTargetStockUnit = product_resolve_unit(
        $allUnitsForPrices,
        $f['unit'],
        $f['target_stock_display_unit_code']
    );
    $f['target_stock_display_unit_code'] = (string)$resolvedTargetStockUnit['unit_code'];
    $targetStockQtyBase = product_qty_to_base_unit(
        $enteredTargetStockQty,
        $allUnitsForPrices,
        $f['unit'],
        $f['target_stock_display_unit_code']
    );
    $minStockState = product_min_stock_data([
        'unit' => $f['unit'],
        'min_stock_qty' => $minStockQtyBase,
        'min_stock_display_unit_code' => $f['min_stock_display_unit_code'],
    ], $allUnitsForPrices);
    $targetStockState = product_target_stock_data([
        'unit' => $f['unit'],
        'target_stock_qty' => $targetStockQtyBase,
        'target_stock_display_unit_code' => $f['target_stock_display_unit_code'],
        'min_stock_display_unit_code' => $f['min_stock_display_unit_code'],
    ], $allUnitsForPrices);

    if ($isEdit && $f['unit'] !== $prod['unit'] && (float)$prod['stock_qty'] > 0) {
        $errors['unit'] = 'Base unit can only be changed when stock is zero.';
    }
    if ($targetStockQtyBase > 0 && $minStockQtyBase > 0 && $targetStockQtyBase < $minStockQtyBase) {
        $errors['target_stock_qty'] = __('repl_target_less_than_min');
    }

    if ($f['barcode'] === '') {
        $f['barcode'] = generate_product_barcode();
    }

    if (!isset($errors['sku']) && $f['sku']) {
        $skuExists = Database::value("SELECT id FROM products WHERE sku=? AND id!=?", [$f['sku'], $id]);
        if ($skuExists) $errors['sku'] = _r('prod_sku_exists');
    }
    if (!isset($errors['barcode']) && $f['barcode']) {
        $barcodeExists = Database::value("SELECT id FROM products WHERE barcode=? AND id!=?", [$f['barcode'], $id]);
        if ($barcodeExists) $errors['barcode'] = _r('prod_barcode_exists');
    }

    if (!empty($_FILES['image']['name'])) {
        $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg','jpeg','png','webp'])) {
            $errors['image'] = 'Invalid file type (jpg, png, webp)';
        } elseif ($_FILES['image']['error'] !== UPLOAD_ERR_OK) {
            $errors['image'] = _r('err_upload');
        } else {
            $newName = 'p_' . uniqid() . '.' . $ext;
            if (!is_dir(UPLOAD_PATH)) mkdir(UPLOAD_PATH, 0775, true);
            if (!move_uploaded_file($_FILES['image']['tmp_name'], UPLOAD_PATH . $newName)) {
                $errors['image'] = _r('err_upload');
            } else {
                if ($f['image'] && file_exists(UPLOAD_PATH . $f['image'])) @unlink(UPLOAD_PATH . $f['image']);
                $f['image'] = $newName;
            }
        }
    }

    if (!$errors) {
        if ($isEdit) {
            $updateFields = [
                'name_en=?',
                'name_ru=?',
                'sku=?',
                'barcode=?',
                'brand=?',
                'category_id=?',
                'unit=?',
                'sale_price=?',
                'cost_price=?',
                'tax_rate=?',
                'min_stock_qty=?',
                'min_stock_display_unit_code=?',
                'description_en=?',
                'description_ru=?',
                'allow_discount=?',
                'is_weighable=?',
                'is_active=?',
                'image=?',
            ];
            $updateParams = [
                $f['name_en'],$f['name_ru'],$f['sku'],$f['barcode'],$f['brand'],$f['category_id'],$f['unit'],
                $f['sale_price'],$f['cost_price'],$f['tax_rate'],$minStockQtyBase,$f['min_stock_display_unit_code'],
                $f['description_en'],$f['description_ru'],$f['allow_discount'],$f['is_weighable'],$f['is_active'],
                $f['image'],
            ];
            if ($replenishmentClassColumnReady) {
                $updateFields[] = 'replenishment_class=?';
                $updateParams[] = $f['replenishment_class'];
            }
            if ($targetStockColumnReady) {
                $updateFields[] = 'target_stock_qty=?';
                $updateParams[] = $targetStockQtyBase;
            }
            if ($targetStockUnitColumnReady) {
                $updateFields[] = 'target_stock_display_unit_code=?';
                $updateParams[] = $f['target_stock_display_unit_code'];
            }
            $updateFields[] = 'updated_at=NOW()';
            $updateParams[] = $id;

            Database::exec(
                "UPDATE products SET " . implode(',', $updateFields) . " WHERE id=?",
                $updateParams
            );
            UISettings::saveProductPrices($id, array_map('sanitize_float', $f['prices']));
            save_product_units($id, $f['unit'], $f['unit_rows'], $defaultSaleUnitCode, $baseUnitLabel);
            save_product_unit_prices($id, normalize_product_units($f['unit_rows'], $f['unit'], $defaultSaleUnitCode, $baseUnitLabel), $f['unit_price_rows'], array_map('sanitize_float', $f['prices']));
        } else {
            $insertColumns = [
                'name_en','name_ru','sku','barcode','brand','category_id','unit',
                'sale_price','cost_price','tax_rate','stock_qty','min_stock_qty','min_stock_display_unit_code','description_en','description_ru',
                'allow_discount','is_weighable','is_active','image',
            ];
            $insertParams = [
                $f['name_en'],$f['name_ru'],$f['sku'],$f['barcode'],$f['brand'],$f['category_id'],$f['unit'],
                $f['sale_price'],$f['cost_price'],$f['tax_rate'],$stockQtyBase,$minStockQtyBase,$f['min_stock_display_unit_code'],
                $f['description_en'],$f['description_ru'],$f['allow_discount'],$f['is_weighable'],$f['is_active'],
                $f['image'],
            ];
            if ($replenishmentClassColumnReady) {
                $insertColumns[] = 'replenishment_class';
                $insertParams[] = $f['replenishment_class'];
            }
            if ($targetStockColumnReady) {
                $insertColumns[] = 'target_stock_qty';
                $insertParams[] = $targetStockQtyBase;
            }
            if ($targetStockUnitColumnReady) {
                $insertColumns[] = 'target_stock_display_unit_code';
                $insertParams[] = $f['target_stock_display_unit_code'];
            }
            $placeholders = implode(',', array_fill(0, count($insertColumns), '?'));
            $newId = Database::insert(
                "INSERT INTO products (" . implode(',', $insertColumns) . ")
                 VALUES ($placeholders)",
                $insertParams
            );
            UISettings::saveProductPrices($newId, array_map('sanitize_float', $f['prices']));
            save_product_units($newId, $f['unit'], $f['unit_rows'], $defaultSaleUnitCode, $baseUnitLabel);
            save_product_unit_prices($newId, normalize_product_units($f['unit_rows'], $f['unit'], $defaultSaleUnitCode, $baseUnitLabel), $f['unit_price_rows'], array_map('sanitize_float', $f['prices']));
            if ($stockQtyBase > 0) {
                $warehouseId = pos_warehouse_id();
                update_stock_balance($newId, $warehouseId, $stockQtyBase);
                Database::insert(
                    "INSERT INTO inventory_movements (product_id,warehouse_id,user_id,type,qty_change,qty_before,qty_after,notes,created_at)
                     VALUES (?,?,?,'receipt',?,0,?,'Initial stock',NOW())",
                    [$newId, $warehouseId, Auth::id(), $stockQtyBase, $stockQtyBase]
                );
            }
        }
        if ($inventoryPopupMode && !$isEdit) {
            $createdProduct = Database::row(
                'SELECT id, name_en, name_ru, sku, barcode, unit FROM products WHERE id = ? LIMIT 1',
                [$newId]
            );
            $createdUnits = product_units($newId, (string)($createdProduct['unit'] ?? $f['unit']));
            $createdDefaultUnit = product_default_unit($newId, (string)($createdProduct['unit'] ?? $f['unit']));
            $payload = [
                'type' => 'inventory-product-created',
                'product' => [
                    'id' => (int)($createdProduct['id'] ?? $newId),
                    'name' => product_name($createdProduct ?: ['name_en' => $f['name_en'], 'name_ru' => $f['name_ru']]),
                    'sku' => (string)($createdProduct['sku'] ?? $f['sku']),
                    'barcode' => (string)($createdProduct['barcode'] ?? $f['barcode']),
                    'unit' => (string)($createdProduct['unit'] ?? $f['unit']),
                    'unit_label' => unit_label((string)($createdProduct['unit'] ?? $f['unit'])),
                    'default_unit_code' => (string)($createdDefaultUnit['unit_code'] ?? ($createdProduct['unit'] ?? $f['unit'])),
                    'default_unit_label' => product_unit_label_text($createdDefaultUnit),
                    'units' => array_map(
                        static fn (array $unitRow): array => [
                            'unit_code' => (string)($unitRow['unit_code'] ?? ''),
                            'unit_label' => product_unit_label_text($unitRow),
                            'ratio_to_base' => (float)($unitRow['ratio_to_base'] ?? 1),
                            'is_default' => !empty($unitRow['is_default']),
                        ],
                        $createdUnits
                    ),
                    'stock_qty' => 0,
                    'stock_display' => qty_display(0, (string)($createdProduct['unit'] ?? $f['unit'])),
                ],
            ];
            ?>
            <!DOCTYPE html>
            <html lang="<?= Lang::current() ?>">
            <head>
              <meta charset="UTF-8">
              <title><?= __('prod_saved') ?></title>
            </head>
            <body>
            <script>
            (function(){
              const payload = <?= json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
              if (window.opener && !window.opener.closed) {
                window.opener.postMessage(payload, window.location.origin);
              }
              window.close();
            })();
            </script>
            </body>
            </html>
            <?php
            exit;
        }

        flash_success(_r('prod_saved'));
        redirect('/modules/products/');
    }
}

$categories = Database::all("SELECT id,name_en,name_ru FROM categories WHERE is_active=1 ORDER BY name_en");
$units      = unit_options();
$unitPresets = unit_preset_rows();
$bodyClassExtra = $inventoryPopupMode ? 'inventory-popup-mode' : '';

include __DIR__ . '/../../views/layouts/header.php';
?>

<style>
body.inventory-popup-mode .sidebar,
body.inventory-popup-mode .topbar {
  display: none !important;
}
body.inventory-popup-mode .main-wrap {
  margin-left: 0;
}
body.inventory-popup-mode .page-content {
  padding: 18px;
}
body.inventory-popup-mode .flash {
  margin-top: 0;
}
body.inventory-popup-mode .page-content > form {
  max-width: 1380px;
  margin: 0 auto;
}
.qc-overlay {
  display: none;
  position: fixed;
  inset: 0;
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
.qc-modal-body { padding: 18px 22px; }
.qc-modal-footer {
  padding: 12px 22px 18px;
  border-top: 1px solid var(--border-dim);
  display: flex;
  gap: 8px;
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
.qc-select-wrap {
  display: flex;
  gap: 6px;
  align-items: center;
}
.qc-select-wrap .form-control { flex: 1; }
.btn-qc {
  flex-shrink: 0;
  width: 34px;
  height: 34px;
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--amber-dim);
  border: 1px solid var(--amber-border);
  border-radius: var(--radius-sm);
  color: var(--amber);
  cursor: pointer;
  padding: 0;
}
@media (max-width: 900px) {
  .qc-select-wrap {
    flex-wrap: wrap;
  }
  .btn-qc {
    width: 100%;
  }
}
</style>

<form method="POST" enctype="multipart/form-data">
<?= csrf_field() ?>

<?php if ($errors): ?>
<div class="flash flash-error mb-2"><?= feather_icon('alert-circle',15) ?> <span><?= __('err_validation') ?></span></div>
<?php endif; ?>

<?php if ($inventoryPopupMode): ?>
<div class="flash flash-info mb-2">
  <?= feather_icon('layers', 15) ?>
  <span><?= __('inv_count_create_hint') ?></span>
</div>
<?php endif; ?>

<div class="content-split content-split-sidebar">

  <!-- Left: main fields -->
  <div class="mobile-stack">    <!-- Names -->
    <div class="card">
      <div class="card-header"><span class="card-title"><?= __('lbl_name') ?></span></div>
      <div class="card-body">
        <div class="form-row form-row-2">
          <div class="form-group mb-0">
            <label class="form-label">Name (EN) <span class="req">*</span></label>
            <input type="text" name="name_en" class="form-control" value="<?= e($f['name_en']) ?>" required>
            <?php if (isset($errors['name_en'])): ?><div class="form-error"><?= e($errors['name_en']) ?></div><?php endif; ?>
          </div>
          <div class="form-group mb-0">
            <label class="form-label">Название (RU)</label>
            <input type="text" name="name_ru" class="form-control" value="<?= e($f['name_ru']) ?>">
          </div>
        </div>
      </div>
    </div>

    <!-- Identifiers -->
    <div class="card">
      <div class="card-header"><span class="card-title"><?= __('lbl_sku') ?> / <?= __('lbl_barcode') ?></span></div>
      <div class="card-body">
        <div class="form-row form-row-3">
          <div class="form-group mb-0">
            <label class="form-label"><?= __('lbl_sku') ?> <span class="req">*</span></label>
            <input type="text" name="sku" class="form-control mono text-uppercase" value="<?= e($f['sku']) ?>" required>
            <?php if (isset($errors['sku'])): ?><div class="form-error"><?= e($errors['sku']) ?></div><?php endif; ?>
          </div>
          <div class="form-group mb-0">
            <label class="form-label"><?= __('lbl_barcode') ?></label>
            <input type="text" name="barcode" class="form-control mono" value="<?= e($f['barcode']) ?>">
            <?php if (isset($errors['barcode'])): ?><div class="form-error"><?= e($errors['barcode']) ?></div><?php endif; ?>
            <div class="form-hint">Leave blank to generate automatically.</div>
          </div>
          <div class="form-group mb-0">
            <label class="form-label"><?= __('lbl_brand') ?></label>
            <input type="text" name="brand" class="form-control" value="<?= e($f['brand']) ?>">
          </div>
        </div>
      </div>
    </div>

    <!-- Category & Unit -->
    <div class="card">
      <div class="card-header"><span class="card-title"><?= __('lbl_category') ?> & <?= __('lbl_unit') ?></span></div>
      <div class="card-body">
        <div class="form-row form-row-2">
          <div class="form-group mb-0">
            <label class="form-label"><?= __('lbl_category') ?> <span class="req">*</span></label>
            <select name="category_id" class="form-control" required>
              <option value=""><?= __('lbl_select') ?></option>
              <?php foreach ($categories as $c): ?>
                <option value="<?= $c['id'] ?>" <?= $f['category_id']==$c['id']?'selected':'' ?>>
                  <?= e(category_name($c)) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <?php if (isset($errors['category_id'])): ?><div class="form-error"><?= e($errors['category_id']) ?></div><?php endif; ?>
          </div>
          <div class="form-group mb-0">
            <label class="form-label"><?= __('lbl_unit') ?></label>
            <div class="qc-select-wrap">
              <select name="base_unit_label" class="form-control unit-preset-select base-unit-preset" data-storage-code="<?= e($f['unit']) ?>">
                <?php foreach ($unitPresets as $preset): ?>
                  <option value="<?= e($preset['unit_label']) ?>" data-storage-code="<?= e(unit_storage_code_from_label((string)$preset['unit_label'])) ?>" <?= $baseUnitLabel === $preset['unit_label'] ? 'selected' : '' ?>>
                    <?= e($preset['unit_label']) ?>
                  </option>
                <?php endforeach; ?>
                <?php if (!isset(unit_preset_options()[$baseUnitLabel])): ?>
                  <option value="<?= e($baseUnitLabel) ?>" data-storage-code="<?= e($f['unit']) ?>" selected><?= e($baseUnitLabel) ?></option>
                <?php endif; ?>
              </select>
              <button type="button" class="btn-qc" id="btn-new-unit-preset" title="<?= __('pos_add_unit_line') ?>"><?= feather_icon('plus', 15) ?></button>
            </div>
            <?php if (isset($errors['unit'])): ?><div class="form-error"><?= e($errors['unit']) ?></div><?php endif; ?>
            <div class="form-hint">Единица выбирается из общего справочника, а старый технический код хранится только внутри системы.</div>
          </div>
        </div>
        <div class="form-group mb-0 hidden">
          <label class="form-label"><?= __('lbl_unit') ?></label>
          <select name="unit" class="form-control">
            <?php foreach ($units as $uKey => $uLabel): ?>
              <option value="<?= $uKey ?>" <?= $f['unit']===$uKey?'selected':'' ?>><?= e($uLabel) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><span class="card-title">Единицы и отображение</span></div>
      <div class="card-body">
        <div class="form-group">
          <label class="form-label">Единица по умолчанию для отображения</label>
          <select name="default_sale_unit" id="defaultSaleUnit" class="form-control">
            <option value="<?= e($f['unit']) ?>" <?= $defaultSaleUnitCode === $f['unit'] ? 'selected' : '' ?>><?= e($baseUnitLabel) ?> (корневая)</option>
            <?php foreach ($extraUnits as $unitRow): ?>
              <option value="<?= e($unitRow['unit_code']) ?>" <?= $defaultSaleUnitCode === $unitRow['unit_code'] ? 'selected' : '' ?>><?= e(product_unit_label_text($unitRow)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="section-toolbar mb-1">
          <div class="form-hint">Эта единица будет показываться по умолчанию в товарах, складе и кассе. Цепочку добавляйте сверху вниз: самая большая единица, затем меньшая внутри нее, затем еще меньшая.</div>
          <button type="button" class="btn btn-sm btn-ghost" id="addUnitRowBtn"><?= feather_icon('plus', 14) ?> Добавить единицу</button>
        </div>
        <div class="unit-builder-row mb-1">
          <div class="form-group mb-0">
            <label class="form-label">Уровень 1: корневая единица</label>
            <input type="text" class="form-control" value="<?= e($baseUnitLabel) ?>" readonly>
          </div>
          <div class="form-group mb-0">
            <label class="form-label">Содержит</label>
            <input type="text" class="form-control mono" value="1" readonly>
          </div>
          <div class="form-hint text-nowrap">Корень</div>
        </div>
        <div id="unitRows" class="mobile-stack">
          <?php foreach ($extraUnits as $idx => $unitRow): ?>
          <?php $prevRatio = $idx === 0 ? 1 : (float)$extraUnits[$idx - 1]['ratio_to_base']; ?>
          <div class="unit-row unit-builder-row">
            <div class="form-group mb-0">
              <label class="form-label">Следующая меньшая единица</label>
              <select name="unit_rows[<?= $idx ?>][unit_label]" class="form-control unit-preset-select" data-allow-empty="1">
                <?php foreach ($unitPresets as $preset): ?>
                  <option value="<?= e($preset['unit_label']) ?>" <?= product_unit_label_text($unitRow) === $preset['unit_label'] ? 'selected' : '' ?>>
                    <?= e($preset['unit_label']) ?>
                  </option>
                <?php endforeach; ?>
                <?php if (!isset(unit_preset_options()[product_unit_label_text($unitRow)])): ?>
                  <option value="<?= e(product_unit_label_text($unitRow)) ?>" selected><?= e(product_unit_label_text($unitRow)) ?></option>
                <?php endif; ?>
              </select>
            </div>
            <div class="form-group mb-0">
              <label class="form-label">Сколько внутри родителя</label>
              <input type="hidden" name="unit_rows[<?= $idx ?>][ratio_to_base]" value="<?= e($unitRow['ratio_to_base']) ?>">
              <input type="number" class="form-control mono unit-step-input" value="<?= e((float)$unitRow['ratio_to_base'] / max(0.001, $prevRatio)) ?>" min="0.001" step="0.001">
            </div>
            <button type="button" class="btn btn-sm btn-danger unit-row-remove"><?= feather_icon('trash-2', 14) ?></button>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- Pricing -->
    <div class="card">
      <div class="card-header"><span class="card-title"><?= __('lbl_price') ?></span></div>
      <div class="card-body">
        <div class="form-hint mb-1">Эти поля относятся к выбранной единице по умолчанию. Главной ценой станет цена именно этой единицы.</div>
        <div class="auto-fit-grid-sm">
          <?php foreach ($priceTypes as $priceType): ?>
          <?php
            $code = $priceType['code'];
            $label = match ($code) {
                'retail' => __('prod_sale_price'),
                'purchase' => __('prod_cost_price'),
                default => e(UISettings::priceTypeName($priceType)),
            };
            $required = $code === 'retail';
          ?>
          <div class="form-group mb-0">
            <label class="form-label"><?= $label ?><?= $required ? ' <span class="req">*</span>' : '' ?></label>
            <input type="number"
                   name="prices[<?= e($code) ?>]"
                   class="form-control mono"
                   value="<?= e($f['prices'][$code] ?? '') ?>"
                   min="0"
                   step="0.01"
                   <?= $required ? 'required' : '' ?>>
            <?php if ($required && isset($errors['sale_price'])): ?><div class="form-error"><?= e($errors['sale_price']) ?></div><?php endif; ?>
          </div>
          <?php endforeach; ?>
          <div class="form-group mb-0">
            <label class="form-label"><?= __('prod_tax_rate') ?></label>
            <input type="number" name="tax_rate" class="form-control mono" value="<?= e($f['tax_rate']) ?>" min="0" max="100" step="0.01">
          </div>
        </div>
        <div class="section-divider">
          <div class="section-toolbar mb-1">
            <div>
              <div class="form-label mb-0">Цены по единицам</div>
              <div class="form-hint">Можно задать отдельную цену для каждой единицы. Пустое поле возьмет автопересчет от базовой цены.</div>
            </div>
          </div>
          <div id="unitPriceRows" class="mobile-stack">
            <?php foreach ($unitPriceRows as $unitIdx => $priceRow): ?>
            <div class="unit-price-row" data-unit-index="<?= $unitIdx ?>" style="--unit-price-columns: <?= count($priceTypes) ?>">
              <div class="form-group mb-0">
                <label class="form-label"><?= $unitIdx === 0 ? 'Базовая единица' : 'Единица' ?></label>
                <input type="text" class="form-control" value="<?= e($priceRow['unit_label']) ?>" readonly>
              </div>
              <?php foreach ($priceTypes as $priceType): ?>
              <?php $code = $priceType['code']; ?>
              <div class="form-group mb-0">
                <label class="form-label"><?= e(UISettings::priceTypeName($priceType)) ?></label>
                <input type="number"
                       name="unit_price_rows[<?= $unitIdx ?>][<?= e($code) ?>]"
                       class="form-control mono unit-price-input"
                       data-unit-index="<?= $unitIdx ?>"
                       data-price-type="<?= e($code) ?>"
                       value="<?= e(number_format((float)($priceRow['prices'][$code] ?? 0), 2, '.', '')) ?>"
                       min="0"
                       step="0.01">
              </div>
              <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>

    <!-- Stock -->
    <?php if (!$inventoryPopupMode): ?>
    <div class="card">
      <div class="card-header"><span class="card-title"><?= __('prod_stock_qty') ?></span></div>
      <div class="card-body">
        <div class="form-row form-row-2">
          <?php if (!$isEdit): ?>
          <div class="form-group mb-0">
            <label class="form-label"><?= __('prod_stock_qty') ?> (<?= __('lbl_optional') ?>)</label>
            <input type="number" name="stock_qty" class="form-control mono" value="<?= e($f['stock_qty']) ?>" min="0" step="0.001">
          </div>
          <?php else: ?>
          <div class="form-group mb-0">
            <label class="form-label"><?= __('prod_stock_qty') ?></label>
            <div class="form-control form-control-static">
              <?= qty_display((float)$f['stock_qty'], $f['unit']) ?>
            </div>
            <?php if ($isEdit): ?>
            <div class="form-hint"><?= e(product_stock_breakdown((float)$f['stock_qty'], product_units($id, $f['unit']), $f['unit'])) ?></div>
            <?php endif; ?>
            <div class="form-hint"><?= __('inv_adjust') ?> via <a href="<?= url('modules/inventory/adjust.php?product_id='.$id) ?>"><?= __('nav_inventory') ?></a></div>
          </div>
          <?php endif; ?>
          <div class="form-group mb-0">
            <label class="form-label"><?= __('prod_min_stock') ?></label>
            <div class="split-input-row">
              <input type="number" name="min_stock_qty" class="form-control mono" value="<?= e($f['min_stock_qty']) ?>" min="0" step="0.001">
              <select name="min_stock_display_unit_code" id="minStockDisplayUnit" class="form-control">
                <?php foreach ($productUnits as $unitRow): ?>
                  <option value="<?= e($unitRow['unit_code']) ?>" <?= (string)$f['min_stock_display_unit_code'] === (string)$unitRow['unit_code'] ? 'selected' : '' ?>>
                    <?= e(product_unit_label_text($unitRow)) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-hint" id="minStockPreview"><?= e(__('prod_min_stock_saved_as')) ?>: <?= e($minStockState['base_text']) ?></div>
          </div>
        </div>
        <div class="form-row form-row-2 mt-2">
          <div class="form-group mb-0">
            <label class="form-label"><?= __('repl_class') ?></label>
            <select name="replenishment_class" class="form-control">
              <?php foreach (replenishment_class_options() as $classCode => $classLabel): ?>
                <option value="<?= e($classCode) ?>" <?= replenishment_class_normalize($f['replenishment_class']) === $classCode ? 'selected' : '' ?>>
                  <?= e($classCode . ' — ' . $classLabel) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <div class="form-hint"><?= __('repl_class_hint') ?></div>
          </div>
          <div class="form-group mb-0">
            <label class="form-label"><?= __('repl_target_stock') ?> (<?= __('lbl_optional') ?>)</label>
            <div class="split-input-row">
              <input type="number" name="target_stock_qty" class="form-control mono" value="<?= e($f['target_stock_qty']) ?>" min="0" step="0.001">
              <select name="target_stock_display_unit_code" id="targetStockDisplayUnit" class="form-control">
                <?php foreach ($productUnits as $unitRow): ?>
                  <option value="<?= e($unitRow['unit_code']) ?>" <?= (string)$f['target_stock_display_unit_code'] === (string)$unitRow['unit_code'] ? 'selected' : '' ?>>
                    <?= e(product_unit_label_text($unitRow)) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <?php if (isset($errors['target_stock_qty'])): ?><div class="form-error"><?= e($errors['target_stock_qty']) ?></div><?php endif; ?>
            <div class="form-hint" id="targetStockPreview"><?= e(__('repl_target_stock_saved_as')) ?>: <?= e($targetStockState['base_text']) ?></div>
          </div>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Descriptions -->
    <div class="card">
      <div class="card-header"><span class="card-title"><?= __('lbl_description') ?></span></div>
      <div class="card-body">
        <div class="form-group">
          <label class="form-label">Description (EN)</label>
          <textarea name="description_en" class="form-control" rows="3"><?= e($f['description_en']) ?></textarea>
        </div>
        <div class="form-group mb-0">
          <label class="form-label">Описание (RU)</label>
          <textarea name="description_ru" class="form-control" rows="3"><?= e($f['description_ru']) ?></textarea>
        </div>
      </div>
    </div>

  </div>

  <!-- Right: image + options -->
  <div class="mobile-stack">

    <!-- Image -->
    <div class="card">
      <div class="card-header"><span class="card-title"><?= __('lbl_image') ?></span></div>
      <div class="card-body">
        <?php if ($f['image']): ?>
          <img src="<?= e(UPLOAD_URL.$f['image']) ?>" alt="" class="image-preview-square">
        <?php else: ?>
          <div class="image-placeholder-square">
            <?= feather_icon('image', 48) ?>
          </div>
        <?php endif; ?>
        <input type="file" name="image" class="form-control file-input-sm" accept="image/*">
        <?php if (isset($errors['image'])): ?><div class="form-error"><?= e($errors['image']) ?></div><?php endif; ?>
      </div>
    </div>

    <!-- Options -->
    <div class="card">
      <div class="card-header"><span class="card-title"><?= __('lbl_status') ?></span></div>
      <div class="card-body option-stack">
        <label class="form-check">
          <input type="checkbox" name="is_active" value="1" <?= $f['is_active']?'checked':'' ?>>
          <span class="form-check-label"><?= __('lbl_active') ?></span>
        </label>
        <label class="form-check">
          <input type="checkbox" name="allow_discount" value="1" <?= $f['allow_discount']?'checked':'' ?>>
          <span class="form-check-label"><?= __('prod_allow_discount') ?></span>
        </label>
        <label class="form-check">
          <input type="checkbox" name="is_weighable" value="1" <?= $f['is_weighable']?'checked':'' ?>>
          <span class="form-check-label"><?= __('prod_weighable') ?></span>
        </label>
      </div>
    </div>

    <!-- Actions -->
    <div class="mobile-stack">
      <button type="submit" class="btn btn-primary btn-block btn-lg">
        <?= feather_icon('save',17) ?> <?= __('btn_save') ?>
      </button>
      <a href="<?= $inventoryPopupMode ? '#' : url('modules/products/') ?>" class="btn btn-ghost btn-block"<?= $inventoryPopupMode ? ' onclick="window.close(); return false;"' : '' ?>>
        <?= feather_icon('arrow-left',15) ?> <?= __('btn_back') ?>
      </a>
    </div>

  </div>
</div><!-- /grid -->
</form>

<div class="qc-overlay" id="unit-preset-modal" role="dialog" aria-modal="true">
  <div class="qc-modal" style="max-width:420px">
    <div class="qc-modal-header">
      <div class="qc-modal-title"><?= feather_icon('layers', 17) ?> Новая единица</div>
      <button type="button" class="qc-modal-close" data-close-modal="unit-preset-modal">×</button>
    </div>
    <div class="qc-modal-body">
      <div class="qc-error" id="unit-preset-error"></div>
      <div class="form-group mb-0">
        <label class="form-label">Название единицы</label>
        <input type="text" id="unit-preset-name" class="form-control" placeholder="<?= __('unit_preset_placeholder') ?>">
      </div>
    </div>
    <div class="qc-modal-footer">
      <button type="button" class="btn btn-primary" id="btn-unit-preset-save"><?= feather_icon('save', 15) ?> Сохранить</button>
      <button type="button" class="btn btn-ghost" data-close-modal="unit-preset-modal">Отмена</button>
    </div>
  </div>
</div>

<?php if ($isEdit): ?>
<div class="qc-overlay" id="product-confirm-modal" role="dialog" aria-modal="true">
  <div class="qc-modal" style="max-width:680px">
    <div class="qc-modal-header">
      <div class="qc-modal-title"><?= feather_icon('clipboard', 17) ?> <?= e(__('prod_confirm_changes')) ?></div>
      <button type="button" class="qc-modal-close" data-close-modal="product-confirm-modal">×</button>
    </div>
    <div class="qc-modal-body">
      <div class="form-hint mb-1"><?= e(__('prod_confirm_changes_hint')) ?></div>
      <div id="product-confirm-empty" class="form-hint hidden mb-1"><?= e(__('prod_confirm_no_changes')) ?></div>
      <div id="product-confirm-list" class="mobile-stack" style="max-height:340px;overflow:auto"></div>
      <label class="form-check mt-2">
        <input type="checkbox" id="product-confirm-check">
        <span class="form-check-label"><?= e(__('prod_confirm_checkbox')) ?></span>
      </label>
    </div>
    <div class="qc-modal-footer">
      <button type="button" class="btn btn-primary" id="product-confirm-submit" disabled><?= feather_icon('save', 15) ?> <?= e(__('prod_confirm_submit')) ?></button>
      <button type="button" class="btn btn-ghost" data-close-modal="product-confirm-modal"><?= e(__('btn_cancel')) ?></button>
    </div>
  </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../../views/layouts/footer.php'; ?>
<script src="https://unpkg.com/feather-icons/dist/feather.min.js"></script>
<script>
feather.replace();

(function() {
  const rowsWrap = document.getElementById('unitRows');
  const addBtn = document.getElementById('addUnitRowBtn');
  const defaultSelect = document.getElementById('defaultSaleUnit');
  const minStockUnitSelect = document.getElementById('minStockDisplayUnit');
  const minStockQtyInput = document.querySelector('[name="min_stock_qty"]');
  const minStockPreview = document.getElementById('minStockPreview');
  const targetStockUnitSelect = document.getElementById('targetStockDisplayUnit');
  const targetStockQtyInput = document.querySelector('[name="target_stock_qty"]');
  const targetStockPreview = document.getElementById('targetStockPreview');
  const baseUnitSelect = document.querySelector('select[name="unit"]');
  const baseUnitLabelInput = document.querySelector('[name="base_unit_label"]');
  const unitPriceRowsWrap = document.getElementById('unitPriceRows');
  const form = document.querySelector('form[method="POST"]');
  const unitPresetModal = document.getElementById('unit-preset-modal');
  const unitPresetNameInput = document.getElementById('unit-preset-name');
  const unitPresetError = document.getElementById('unit-preset-error');
  const unitPresetSaveBtn = document.getElementById('btn-unit-preset-save');
  const unitPresetOpenBtn = document.getElementById('btn-new-unit-preset');
  const confirmModal = document.getElementById('product-confirm-modal');
  const confirmList = document.getElementById('product-confirm-list');
  const confirmEmpty = document.getElementById('product-confirm-empty');
  const confirmCheck = document.getElementById('product-confirm-check');
  const confirmSubmitBtn = document.getElementById('product-confirm-submit');
  const unitPresetAjaxUrl = <?= json_encode(url('modules/common/ajax_units.php')) ?>;
  const csrfToken = document.querySelector('input[name="_token"]')?.value || '';
  const isEditMode = <?= $isEdit ? 'true' : 'false' ?>;
  const unitPresets = <?= json_encode(array_values(array_map(static fn($row) => ['label' => (string)$row['unit_label'], 'storageCode' => unit_storage_code_from_label((string)$row['unit_label'])], $unitPresets)), JSON_UNESCAPED_UNICODE) ?>;
  const priceTypes = <?= json_encode(array_map(static fn($pt) => ['code' => $pt['code'], 'label' => UISettings::priceTypeName($pt)], $priceTypes), JSON_UNESCAPED_UNICODE) ?>;
  const minStockSavedAsText = <?= json_encode(__('prod_min_stock_saved_as'), JSON_UNESCAPED_UNICODE) ?>;
  const targetStockSavedAsText = <?= json_encode(__('repl_target_stock_saved_as'), JSON_UNESCAPED_UNICODE) ?>;
  const priceInputs = {};
  const priceState = <?= json_encode(array_map(static function ($row) {
    return array_map(static fn($value) => number_format((float)$value, 2, '.', ''), $row['prices']);
  }, $unitPriceRows), JSON_UNESCAPED_UNICODE) ?>;
  let lastRenderedUnits = [];
  let allowConfirmedSubmit = false;
  let initialProductState = null;
  let unitIndex = <?= count($extraUnits) ?>;
  priceTypes.forEach((priceType) => {
    priceInputs[priceType.code] = document.querySelector(`input[name="prices[${priceType.code}]"]`);
  });

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

  function syncBaseUnitCode() {
    if (!baseUnitSelect) return;
    baseUnitSelect.value = selectedStorageCode(baseUnitLabelInput, baseUnitSelect.value || 'pcs');
  }

  function presetOptionHtml(selectedLabel = '', allowEmpty = false, selectedStorageCode = 'pcs') {
    const parts = [];
    if (allowEmpty) {
      parts.push('<option value="">Выберите единицу</option>');
    }
    unitPresets.forEach((preset) => {
      const selected = preset.label === selectedLabel ? 'selected' : '';
      parts.push(`<option value="${escapeHtml(preset.label)}" data-storage-code="${escapeHtml(preset.storageCode || 'pcs')}" ${selected}>${escapeHtml(preset.label)}</option>`);
    });
    if (selectedLabel && !unitPresets.some((preset) => preset.label === selectedLabel)) {
      parts.push(`<option value="${escapeHtml(selectedLabel)}" data-storage-code="${escapeHtml(selectedStorageCode || 'pcs')}" selected>${escapeHtml(selectedLabel)}</option>`);
    }
    return parts.join('');
  }

  function refreshPresetSelects(selectedLabel = '', selectedStorageCode = '') {
    document.querySelectorAll('.unit-preset-select').forEach((select) => {
      const current = select.value || selectedLabel;
      const allowEmpty = select.dataset.allowEmpty === '1';
      const currentStorageCode = select.dataset.storageCode || selectedStorageCode || 'pcs';
      select.innerHTML = presetOptionHtml(current, allowEmpty, currentStorageCode);
      if (current) {
        select.value = current;
      }
    });
  }

  function openUnitPresetModal() {
    unitPresetError.textContent = '';
    unitPresetError.classList.remove('show');
    unitPresetNameInput.value = '';
    unitPresetModal.classList.add('open');
    setTimeout(() => unitPresetNameInput.focus(), 60);
  }

  function closeUnitPresetModal() {
    unitPresetModal.classList.remove('open');
  }

  function setUnitPresetLoading(on) {
    unitPresetSaveBtn.disabled = on;
    unitPresetSaveBtn.style.opacity = on ? '.6' : '1';
  }

  function currentUnits() {
    const units = [{
      code: baseUnitSelect?.value || 'base',
      label: (baseUnitLabelInput?.value || baseUnitSelect?.selectedOptions?.[0]?.text || 'База').trim() || 'База',
      ratio: 1,
    }];
    let cumulative = 1;
    rowsWrap.querySelectorAll('.unit-row').forEach((row, idx) => {
      const label = (row.querySelector('[name$="[unit_label]"]')?.value || '').trim() || `Единица ${idx + 1}`;
      const stepValue = parseFloat(row.querySelector('.unit-step-input')?.value || 1) || 1;
      cumulative *= stepValue > 0 ? stepValue : 1;
      const code = label.toLowerCase().replace(/\s+/g, '_') || `unit_${idx + 1}`;
      row.dataset.unitCode = code;
      units.push({
        code,
        label,
        ratio: cumulative,
      });
    });
    return units;
  }

  function findUnit(units, code) {
    return units.find(unit => unit.code === code) || units[0] || { code: '', ratio: 1 };
  }

  function formatPrice(value) {
    const num = parseFloat(value || 0) || 0;
    return num.toFixed(2);
  }

  function formatQtyValue(value, precision = 3) {
    const num = parseFloat(value || 0) || 0;
    return Number.isInteger(num) ? String(num) : num.toFixed(precision).replace(/\.?0+$/, '');
  }

  function defaultUnitIndex(units = currentUnits()) {
    const currentCode = defaultSelect?.value || baseUnitSelect?.value || '';
    const idx = units.findIndex((unit) => unit.code === currentCode);
    return idx >= 0 ? idx : 0;
  }

  function ensurePriceState(units = currentUnits()) {
    units.forEach((_, idx) => {
      priceState[idx] = priceState[idx] || {};
      priceTypes.forEach((priceType) => {
        if (priceState[idx][priceType.code] === undefined) {
          priceState[idx][priceType.code] = '0.00';
        }
      });
    });
    Object.keys(priceState).forEach((key) => {
      if (Number(key) >= units.length) {
        delete priceState[key];
      }
    });
  }

  function syncPriceStateFromDom() {
    if (!unitPriceRowsWrap) return;
    unitPriceRowsWrap.querySelectorAll('.unit-price-input').forEach((input) => {
      const idx = parseInt(input.dataset.unitIndex || '0', 10);
      const code = input.dataset.priceType || '';
      priceState[idx] = priceState[idx] || {};
      priceState[idx][code] = input.value || '';
    });
  }

  function remapPriceState(units) {
    if (!lastRenderedUnits.length) {
      ensurePriceState(units);
      lastRenderedUnits = units.map((unit) => ({ ...unit }));
      return;
    }
    const previousByCode = {};
    const previousByIndex = {};
    lastRenderedUnits.forEach((unit, idx) => {
      previousByCode[unit.code] = { ...(priceState[idx] || {}) };
      previousByIndex[idx] = { ...(priceState[idx] || {}) };
    });
    Object.keys(priceState).forEach((key) => delete priceState[key]);
    units.forEach((unit, idx) => {
      priceState[idx] = previousByCode[unit.code]
        ? { ...previousByCode[unit.code] }
        : previousByIndex[idx]
          ? { ...previousByIndex[idx] }
          : {};
    });
    ensurePriceState(units);
    lastRenderedUnits = units.map((unit) => ({ ...unit }));
  }

  function updateUnitRatioFields() {
    let cumulative = 1;
    rowsWrap.querySelectorAll('.unit-row').forEach((row) => {
      const stepInput = row.querySelector('.unit-step-input');
      const ratioInput = row.querySelector('input[name$="[ratio_to_base]"]');
      if (!stepInput) return;
      const stepValue = parseFloat(stepInput.value || 0);
      cumulative *= stepValue > 0 ? stepValue : 1;
      if (ratioInput) ratioInput.value = cumulative.toFixed(3);
    });
  }

  function refreshUnitRowPrompts() {
    let parentLabel = (baseUnitLabelInput?.value || baseUnitSelect?.selectedOptions?.[0]?.text || 'База').trim() || 'База';
    rowsWrap.querySelectorAll('.unit-row').forEach((row, idx) => {
      const labels = row.querySelectorAll('.form-label');
      const currentLabel = (row.querySelector('[name$="[unit_label]"]')?.value || '').trim() || `Уровень ${idx + 2}`;
      if (labels[0]) {
        labels[0].textContent = `Уровень ${idx + 2}: внутри "${parentLabel}"`;
      }
      if (labels[1]) {
        labels[1].textContent = `Сколько "${currentLabel}" находится в "${parentLabel}"`;
      }
      parentLabel = currentLabel;
    });
  }

  function refreshDefaultOptions() {
    if (!defaultSelect || !baseUnitSelect) return;
    const current = defaultSelect.value;
    const rootLabel = (baseUnitLabelInput?.value || baseUnitSelect.selectedOptions?.[0]?.text || 'База').trim() || 'База';
    const options = [`<option value="${baseUnitSelect.value}">${rootLabel} (корневая)</option>`];
    rowsWrap.querySelectorAll('.unit-row').forEach((row, idx) => {
      const input = row.querySelector('[name$="[unit_label]"]');
      const label = (input?.value || '').trim() || `Единица ${idx + 1}`;
      const code = row.dataset.unitCode || label.toLowerCase().replace(/\s+/g, '_') || `unit_${idx + 1}`;
      options.push(`<option value="${code}">${label}</option>`);
    });
    defaultSelect.innerHTML = options.join('');
    if ([...defaultSelect.options].some(option => option.value === current)) {
      defaultSelect.value = current;
    }
  }

  function updateThresholdPreview(qtyInput, unitSelect, previewNode, labelText) {
    if (!previewNode || !qtyInput) return;
    const units = currentUnits();
    const selectedCode = unitSelect?.value || defaultSelect?.value || units[0]?.code || '';
    const selectedUnit = findUnit(units, selectedCode);
    const baseUnit = findUnit(units, baseUnitSelect?.value || units[0]?.code || '');
    const displayQty = parseFloat(String(qtyInput.value || '0').replace(',', '.')) || 0;
    const baseQty = displayQty / Math.max(1, parseFloat(selectedUnit?.ratio || 1) || 1);
    previewNode.textContent = `${labelText}: ${formatQtyValue(baseQty)} ${baseUnit.label || ''}`.trim();
  }

  function updateMinStockPreview() {
    updateThresholdPreview(minStockQtyInput, minStockUnitSelect, minStockPreview, minStockSavedAsText);
  }

  function updateTargetStockPreview() {
    updateThresholdPreview(targetStockQtyInput, targetStockUnitSelect, targetStockPreview, targetStockSavedAsText);
  }

  function refreshThresholdOptions(unitSelect, onAfterChange) {
    if (!unitSelect) return;
    const units = currentUnits();
    const current = unitSelect.value;
    unitSelect.innerHTML = units.map((unit) => `
      <option value="${escapeHtml(unit.code)}">${escapeHtml(unit.label)}</option>
    `).join('');
    const fallback = defaultSelect?.value || units[0]?.code || '';
    unitSelect.value = units.some((unit) => unit.code === current) ? current : fallback;
    unitSelect.disabled = units.length <= 1;
    onAfterChange?.();
  }

  function refreshMinStockOptions() {
    refreshThresholdOptions(minStockUnitSelect, updateMinStockPreview);
  }

  function refreshTargetStockOptions() {
    refreshThresholdOptions(targetStockUnitSelect, updateTargetStockPreview);
  }

  function syncUnitRatios(renderPrices = true) {
    updateUnitRatioFields();
    refreshMinStockOptions();
    refreshTargetStockOptions();
    if (renderPrices) {
      renderUnitPriceRows();
    }
  }

  function renderUnitPriceRows() {
    if (!unitPriceRowsWrap) return;
    syncPriceStateFromDom();
    const units = currentUnits();
    remapPriceState(units);
    unitPriceRowsWrap.innerHTML = units.map((unit, idx) => {
      const cells = priceTypes.map((priceType) => {
        const value = priceState[idx]?.[priceType.code] ?? '0.00';
        return `
          <div class="form-group mb-0">
            <label class="form-label">${priceType.label}</label>
            <input type="number"
                   name="unit_price_rows[${idx}][${priceType.code}]"
                   class="form-control mono unit-price-input"
                   data-unit-index="${idx}"
                   data-unit-code="${unit.code}"
                   data-price-type="${priceType.code}"
                   value="${value}"
                   min="0"
                   step="0.01">
          </div>
        `;
      }).join('');
      return `
        <div class="unit-price-row" data-unit-index="${idx}" style="--unit-price-columns:${priceTypes.length}">
          <div class="form-group mb-0">
            <label class="form-label">${idx === 0 ? 'Базовая единица' : 'Единица'}</label>
            <input type="text" class="form-control" value="${unit.label}" readonly>
          </div>
          ${cells}
        </div>
      `;
    }).join('');

    unitPriceRowsWrap.querySelectorAll('.unit-price-input').forEach((input) => {
      input.addEventListener('input', () => {
        const idx = parseInt(input.dataset.unitIndex || '0', 10);
        const code = input.dataset.priceType || '';
        priceState[idx] = priceState[idx] || {};
        priceState[idx][code] = input.value || '';
      });
      input.addEventListener('blur', () => {
        const idx = parseInt(input.dataset.unitIndex || '0', 10);
        const code = input.dataset.priceType || '';
        const normalized = formatPrice(input.value || 0);
        priceState[idx] = priceState[idx] || {};
        priceState[idx][code] = normalized;
        input.value = normalized;
      });
    });
  }

  function bindRow(row) {
    row.querySelector('.unit-row-remove')?.addEventListener('click', () => {
      row.remove();
      refreshDefaultOptions();
      refreshUnitRowPrompts();
      syncUnitRatios();
    });
    row.querySelector('[name$="[unit_label]"]')?.addEventListener('change', () => {
      refreshDefaultOptions();
      refreshUnitRowPrompts();
      refreshMinStockOptions();
      refreshTargetStockOptions();
      renderUnitPriceRows();
    });
    row.querySelector('.unit-step-input')?.addEventListener('input', syncUnitRatios);
  }

  addBtn?.addEventListener('click', () => {
    const row = document.createElement('div');
    row.className = 'unit-row unit-builder-row';
    row.innerHTML = `
      <div class="form-group mb-0">
        <label class="form-label">Следующая меньшая единица</label>
        <select name="unit_rows[${unitIndex}][unit_label]" class="form-control unit-preset-select" data-allow-empty="1">
          ${presetOptionHtml('', true)}
        </select>
      </div>
      <div class="form-group mb-0">
        <label class="form-label">Сколько внутри родителя</label>
        <input type="hidden" name="unit_rows[${unitIndex}][ratio_to_base]" value="1">
        <input type="number" class="form-control mono unit-step-input" min="0.001" step="0.001" value="1">
      </div>
      <button type="button" class="btn btn-sm btn-danger unit-row-remove"><i data-feather="trash-2"></i></button>
    `;
    unitIndex += 1;
    rowsWrap.appendChild(row);
    bindRow(row);
    refreshDefaultOptions();
    refreshUnitRowPrompts();
    syncUnitRatios();
    feather.replace();
  });

  function cleanLabel(value) {
    return String(value || '').replace(/\s+/g, ' ').replace(/\*/g, '').trim();
  }

  function normalizeText(value) {
    return String(value || '').trim();
  }

  function normalizeNumber(value, precision = 2) {
    const raw = String(value ?? '').trim().replace(',', '.');
    if (raw === '') return '';
    const num = parseFloat(raw);
    if (!Number.isFinite(num)) return '';
    return num.toFixed(precision);
  }

  function getFieldLabel(selector, fallback = '') {
    const field = form?.querySelector(selector);
    const label = field?.closest('.form-group')?.querySelector('.form-label');
    return cleanLabel(label?.textContent || fallback);
  }

  function getCheckLabel(name, fallback = '') {
    const input = form?.querySelector(`[name="${name}"]`);
    return cleanLabel(input?.closest('.form-check')?.querySelector('.form-check-label')?.textContent || fallback);
  }

  function captureProductState() {
    syncBaseUnitCode();
    syncPriceStateFromDom();
    updateUnitRatioFields();
    const units = currentUnits();
    const fields = {
      name_ru: { label: getFieldLabel('[name="name_ru"]', 'Наименование (RU)'), value: normalizeText(form?.querySelector('[name="name_ru"]')?.value) },
      name_en: { label: getFieldLabel('[name="name_en"]', 'Наименование (EN)'), value: normalizeText(form?.querySelector('[name="name_en"]')?.value) },
      sku: { label: getFieldLabel('[name="sku"]', 'Артикул'), value: normalizeText(form?.querySelector('[name="sku"]')?.value) },
      barcode: { label: getFieldLabel('[name="barcode"]', 'Штрихкод'), value: normalizeText(form?.querySelector('[name="barcode"]')?.value) },
      brand: { label: getFieldLabel('[name="brand"]', 'Бренд'), value: normalizeText(form?.querySelector('[name="brand"]')?.value) },
      category: {
        label: getFieldLabel('[name="category_id"]', 'Категория'),
        value: cleanLabel(form?.querySelector('[name="category_id"]')?.selectedOptions?.[0]?.textContent || ''),
      },
      base_unit_label: {
        label: getFieldLabel('[name="base_unit_label"]', 'Ед. изм.'),
        value: cleanLabel(baseUnitLabelInput?.selectedOptions?.[0]?.textContent || baseUnitLabelInput?.value || ''),
      },
      default_sale_unit: {
        label: cleanLabel(document.querySelector('label[for="defaultSaleUnit"]')?.textContent || 'Единица по умолчанию'),
        value: cleanLabel(defaultSelect?.selectedOptions?.[0]?.textContent || ''),
      },
      min_stock_qty: { label: getFieldLabel('[name="min_stock_qty"]', 'Минимальный остаток'), value: normalizeNumber(form?.querySelector('[name="min_stock_qty"]')?.value, 3) },
      min_stock_display_unit_code: {
        label: 'Единица минимального остатка',
        value: cleanLabel(minStockUnitSelect?.selectedOptions?.[0]?.textContent || ''),
      },
      min_stock_preview: {
        label: cleanLabel(minStockSavedAsText),
        value: normalizeText(minStockPreview?.textContent?.replace(`${minStockSavedAsText}:`, '').trim()),
      },
      replenishment_class: {
        label: getFieldLabel('[name="replenishment_class"]', 'Класс потребности'),
        value: cleanLabel(form?.querySelector('[name="replenishment_class"]')?.selectedOptions?.[0]?.textContent || ''),
      },
      target_stock_qty: {
        label: getFieldLabel('[name="target_stock_qty"]', 'Желаемый остаток'),
        value: normalizeNumber(form?.querySelector('[name="target_stock_qty"]')?.value, 3),
      },
      target_stock_display_unit_code: {
        label: 'Единица желаемого остатка',
        value: cleanLabel(targetStockUnitSelect?.selectedOptions?.[0]?.textContent || ''),
      },
      target_stock_preview: {
        label: cleanLabel(targetStockSavedAsText),
        value: normalizeText(targetStockPreview?.textContent?.replace(`${targetStockSavedAsText}:`, '').trim()),
      },
      tax_rate: { label: getFieldLabel('[name="tax_rate"]', 'Ставка НДС %'), value: normalizeNumber(form?.querySelector('[name="tax_rate"]')?.value) },
      allow_discount: { label: getCheckLabel('allow_discount', 'Разрешить скидку'), value: form?.querySelector('[name="allow_discount"]')?.checked ? 'Да' : 'Нет' },
      is_weighable: { label: getCheckLabel('is_weighable', 'Весовой товар'), value: form?.querySelector('[name="is_weighable"]')?.checked ? 'Да' : 'Нет' },
      is_active: { label: getCheckLabel('is_active', 'Активный'), value: form?.querySelector('[name="is_active"]')?.checked ? 'Да' : 'Нет' },
      description_en: { label: getFieldLabel('[name="description_en"]', 'Description (EN)'), value: normalizeText(form?.querySelector('[name="description_en"]')?.value) },
      description_ru: { label: getFieldLabel('[name="description_ru"]', 'Описание (RU)'), value: normalizeText(form?.querySelector('[name="description_ru"]')?.value) },
      unit_chain: {
        label: 'Цепочка единиц',
        value: [
          cleanLabel(baseUnitLabelInput?.selectedOptions?.[0]?.textContent || baseUnitLabelInput?.value || ''),
          ...Array.from(rowsWrap.querySelectorAll('.unit-row')).map((row) => {
            const label = cleanLabel(row.querySelector('[name$="[unit_label]"]')?.selectedOptions?.[0]?.textContent || row.querySelector('[name$="[unit_label]"]')?.value || '');
            const step = normalizeNumber(row.querySelector('.unit-step-input')?.value, 3) || '1.000';
            return `${label} × ${step}`;
          }),
        ].filter(Boolean).join(' -> '),
      },
    };

    priceTypes.forEach((priceType) => {
      fields[`price_header_${priceType.code}`] = {
        label: priceType.label,
        value: normalizeNumber(priceInputs[priceType.code]?.value),
      };
    });

    units.forEach((unit, idx) => {
      priceTypes.forEach((priceType) => {
        fields[`unit_price_${idx}_${priceType.code}`] = {
          label: `${priceType.label} / ${unit.label}`,
          value: normalizeNumber(priceState[idx]?.[priceType.code] ?? ''),
        };
      });
    });

    return fields;
  }

  function buildProductChangeList(before, after) {
    const changes = [];
    const keys = new Set([...Object.keys(before || {}), ...Object.keys(after || {})]);
    keys.forEach((key) => {
      const prev = before?.[key];
      const next = after?.[key];
      const prevValue = prev?.value ?? '';
      const nextValue = next?.value ?? '';
      if (prevValue === nextValue) return;
      changes.push({
        label: next?.label || prev?.label || key,
        before: prevValue || '—',
        after: nextValue || '—',
      });
    });
    return changes;
  }

  function renderProductConfirmChanges(changes) {
    if (!confirmList || !confirmEmpty) return;
    confirmEmpty.classList.toggle('hidden', changes.length > 0);
    confirmList.classList.toggle('hidden', changes.length === 0);
    confirmList.innerHTML = changes.map((change) => `
      <div class="confirm-change-card">
        <div class="confirm-change-title">${escapeHtml(change.label)}</div>
        <div class="confirm-change-row">
          <div>${escapeHtml(change.before)}</div>
          <div class="confirm-change-arrow">→</div>
          <div class="text-primary">${escapeHtml(change.after)}</div>
        </div>
      </div>
    `).join('');
  }

  function openProductConfirmModal() {
    if (!confirmModal) return;
    confirmCheck.checked = false;
    if (confirmSubmitBtn) confirmSubmitBtn.disabled = true;
    confirmModal.classList.add('open');
  }

  function closeProductConfirmModal() {
    confirmModal?.classList.remove('open');
    if (confirmCheck) confirmCheck.checked = false;
    if (confirmSubmitBtn) confirmSubmitBtn.disabled = true;
  }

  function prepareProductFormForSubmit() {
    syncBaseUnitCode();
    syncPriceStateFromDom();
    updateUnitRatioFields();
  }

  rowsWrap.querySelectorAll('.unit-row').forEach(bindRow);
  baseUnitSelect?.addEventListener('change', () => {
    refreshDefaultOptions();
    refreshUnitRowPrompts();
    syncUnitRatios();
  });
  baseUnitLabelInput?.addEventListener('change', () => {
    syncBaseUnitCode();
    refreshDefaultOptions();
    refreshUnitRowPrompts();
    renderUnitPriceRows();
    refreshMinStockOptions();
    refreshTargetStockOptions();
  });
  defaultSelect?.addEventListener('change', () => {
    refreshMinStockOptions();
    refreshTargetStockOptions();
  });
  minStockQtyInput?.addEventListener('input', updateMinStockPreview);
  minStockUnitSelect?.addEventListener('change', updateMinStockPreview);
  targetStockQtyInput?.addEventListener('input', updateTargetStockPreview);
  targetStockUnitSelect?.addEventListener('change', updateTargetStockPreview);
  unitPresetOpenBtn?.addEventListener('click', openUnitPresetModal);
  document.querySelectorAll('[data-close-modal="unit-preset-modal"]').forEach((btn) => {
    btn.addEventListener('click', closeUnitPresetModal);
  });
  document.querySelectorAll('[data-close-modal="product-confirm-modal"]').forEach((btn) => {
    btn.addEventListener('click', closeProductConfirmModal);
  });
  confirmCheck?.addEventListener('change', () => {
    if (confirmSubmitBtn) confirmSubmitBtn.disabled = !confirmCheck.checked;
  });
  confirmSubmitBtn?.addEventListener('click', () => {
    if (!form || !confirmCheck?.checked) return;
    allowConfirmedSubmit = true;
    closeProductConfirmModal();
    if (typeof form.requestSubmit === 'function') {
      form.requestSubmit();
    } else {
      form.submit();
    }
  });
  unitPresetSaveBtn?.addEventListener('click', async () => {
    const label = (unitPresetNameInput.value || '').trim();
    if (!label) {
      unitPresetError.textContent = 'Введите название единицы.';
      unitPresetError.classList.add('show');
      return;
    }
    setUnitPresetLoading(true);
    try {
      const formData = new FormData();
      formData.append('action', 'create_unit_preset');
      formData.append('_token', csrfToken);
      formData.append('label', label);
      const res = await fetch(unitPresetAjaxUrl, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: formData,
      });
      const json = await res.json().catch(() => null);
      if (!res.ok || !json?.success) {
        throw new Error(json?.error || ('HTTP ' + res.status));
      }
      if (!unitPresets.some((preset) => preset.label === json.data.unit_label)) {
        unitPresets.push({ label: json.data.unit_label, storageCode: json.data.storage_code || 'pcs' });
      }
      refreshPresetSelects(json.data.unit_label, json.data.storage_code || 'pcs');
      syncBaseUnitCode();
      refreshDefaultOptions();
      refreshUnitRowPrompts();
      closeUnitPresetModal();
    } catch (error) {
      unitPresetError.textContent = error.message || 'Не удалось сохранить единицу.';
      unitPresetError.classList.add('show');
    } finally {
      setUnitPresetLoading(false);
    }
  });
  form?.addEventListener('submit', (event) => {
    prepareProductFormForSubmit();
    if (!isEditMode || allowConfirmedSubmit) return;
    event.preventDefault();
    const currentState = captureProductState();
    renderProductConfirmChanges(buildProductChangeList(initialProductState, currentState));
    openProductConfirmModal();
  });
  ensurePriceState();
  refreshPresetSelects(baseUnitLabelInput?.value || '', baseUnitSelect?.value || 'pcs');
  syncBaseUnitCode();
  refreshDefaultOptions();
  refreshUnitRowPrompts();
  syncUnitRatios(false);
  refreshMinStockOptions();
  refreshTargetStockOptions();
  renderUnitPriceRows();
  initialProductState = captureProductState();
})();
</script>
