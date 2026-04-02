<?php
/**
 * modules/transfers/get_stock.php
 * AJAX: returns stock data for a product on a warehouse.
 */
require_once __DIR__ . '/../../core/bootstrap.php';
Auth::requireLogin();
Auth::requirePerm('transfers');

$productId   = (int)($_GET['product_id'] ?? 0);
$warehouseId = (int)($_GET['warehouse_id'] ?? 0);
$unitCode    = sanitize($_GET['unit_code'] ?? '');

if (!$productId || !$warehouseId || !user_can_access_warehouse($warehouseId)) {
    json_response([
        'qty_base' => 0,
        'formatted_base' => '0',
        'formatted_breakdown' => '0',
        'qty_in_selected_unit' => 0,
        'formatted_selected_unit' => '0',
    ]);
}

$product = Database::row(
    "SELECT id, unit FROM products WHERE id=? LIMIT 1",
    [$productId]
);

if (!$product) {
    json_response([
        'qty_base' => 0,
        'formatted_base' => '0',
        'formatted_breakdown' => '0',
        'qty_in_selected_unit' => 0,
        'formatted_selected_unit' => '0',
    ]);
}

$baseUnit = (string)$product['unit'];
$units = product_units($productId, $baseUnit);
$selectedUnit = product_resolve_unit($units, $baseUnit, $unitCode);
$qtyBase = get_stock_qty($productId, $warehouseId);
$baseUnitRow = product_resolve_unit($units, $baseUnit, $baseUnit);

json_response([
    'qty_base' => stock_qty_round($qtyBase),
    'formatted_base' => product_unit_qty_text($qtyBase, $baseUnitRow),
    'formatted_breakdown' => product_stock_breakdown($qtyBase, $units, $baseUnit),
    'qty_in_selected_unit' => product_qty_from_base_unit($qtyBase, $units, $baseUnit, (string)$selectedUnit['unit_code']),
    'formatted_selected_unit' => product_formatted_qty_in_unit($qtyBase, $units, $baseUnit, (string)$selectedUnit['unit_code']),
    'selected_unit_code' => (string)$selectedUnit['unit_code'],
    'selected_unit_label' => product_unit_label_text($selectedUnit),
]);
