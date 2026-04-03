<?php
declare(strict_types=1);

require_once __DIR__ . '/../../core/bootstrap.php';

Auth::requireLogin();
Auth::requirePerm('inventory');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/modules/inventory/');
}

if (!csrf_verify()) {
    flash_error(_r('err_csrf'));
    redirect('/modules/inventory/');
}

$id = (int)($_POST['id'] ?? 0);

if ($id <= 0) {
    flash_error(_r('prod_not_found'));
    redirect('/modules/inventory/');
}

$product = Database::row(
    "SELECT id, name_en, name_ru, unit, min_stock_qty, min_stock_display_unit_code,
            " . replenishment_product_select_sql('products_alias') . "
     FROM products
     AS products_alias
     WHERE id=? LIMIT 1",
    [$id]
);

if (!$product) {
    flash_error(_r('err_not_found'));
    redirect('/modules/inventory/');
}

$minStock  = (float)str_replace(',', '.', (string)($_POST['min_stock_qty'] ?? '0'));
$minStockDisplayUnitCode = sanitize((string)($_POST['min_stock_display_unit_code'] ?? ''));
$replenishmentClass = replenishment_class_normalize($_POST['replenishment_class'] ?? 'C');
$targetStock = (float)str_replace(',', '.', (string)($_POST['target_stock_qty'] ?? '0'));
$targetStockDisplayUnitCode = sanitize((string)($_POST['target_stock_display_unit_code'] ?? ''));

if ($minStock < 0)  $minStock  = 0;
if ($targetStock < 0) $targetStock = 0;
$units = product_units($id, (string)$product['unit']);
$resolvedUnit = product_resolve_unit($units, (string)$product['unit'], $minStockDisplayUnitCode);
$minStockBase = product_qty_to_base_unit($minStock, $units, (string)$product['unit'], (string)$resolvedUnit['unit_code']);
$resolvedTargetUnit = product_resolve_unit($units, (string)$product['unit'], $targetStockDisplayUnitCode);
$targetStockBase = product_qty_to_base_unit($targetStock, $units, (string)$product['unit'], (string)$resolvedTargetUnit['unit_code']);

if ($targetStockBase > 0 && $minStockBase > 0 && $targetStockBase < $minStockBase) {
    flash_error(__('repl_target_less_than_min'));
    redirect('/modules/inventory/');
}

$fields = [
    'min_stock_qty = ?',
    'min_stock_display_unit_code = ?',
];
$params = [
    $minStockBase,
    (string)$resolvedUnit['unit_code'],
];
if (replenishment_has_product_column('replenishment_class')) {
    $fields[] = 'replenishment_class = ?';
    $params[] = $replenishmentClass;
}
if (replenishment_has_product_column('target_stock_qty')) {
    $fields[] = 'target_stock_qty = ?';
    $params[] = $targetStockBase;
}
if (replenishment_has_product_column('target_stock_display_unit_code')) {
    $fields[] = 'target_stock_display_unit_code = ?';
    $params[] = (string)$resolvedTargetUnit['unit_code'];
}
$params[] = $id;

Database::exec(
    "UPDATE products
     SET " . implode(', ', $fields) . "
     WHERE id = ?",
    $params
);

flash_success(__('msg_saved'));
redirect('/modules/inventory/');
