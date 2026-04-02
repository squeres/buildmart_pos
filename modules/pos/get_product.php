<?php
require_once __DIR__ . '/../../core/bootstrap.php';
Auth::requireLogin();
Auth::requirePerm('pos');

$id   = (int)($_GET['id'] ?? 0);
$whId = pos_warehouse_id();

$p = Database::row(
    'SELECT p.*, COALESCE(sb.qty, 0) AS stock_qty
     FROM products p
     LEFT JOIN stock_balances sb ON sb.product_id = p.id AND sb.warehouse_id = ?
     WHERE p.id=? AND p.is_active=1 AND COALESCE(sb.qty, 0) > 0',
    [$whId, $id]
);

if (!$p) {
    json_response(['product' => null], 404);
}

$effectivePrice = UISettings::effectivePrice((int)$p['id'], UISettings::defaultPriceType('pos'));
if ($effectivePrice <= 0) {
    json_response(['product' => null], 404);
}

$units = product_units((int)$p['id'], $p['unit']);
$defaultUnit = product_default_unit((int)$p['id'], $p['unit']);
$unitOverrides = product_unit_price_overrides((int)$p['id']);
$isWeighable = !empty($p['is_weighable']);
$stockQty = (float)$p['stock_qty'];
$minQty   = (float)$p['min_stock_qty'];
$baseStockUnit = null;
foreach ($units as $unit) {
    if ((string)$unit['unit_code'] === (string)$p['unit']) {
        $baseStockUnit = $unit;
        break;
    }
}
$baseStockUnit = $baseStockUnit ?: ($units[0] ?? ['unit_code' => $p['unit'], 'unit_label' => unit_label($p['unit'])]);
json_response(['product' => [
    'id'            => (int)$p['id'],
    'name'          => product_name($p),
    'sku'           => $p['sku'],
    'sale_price'    => product_unit_price((int)$p['id'], $defaultUnit['unit_code'], UISettings::defaultPriceType('pos'), $effectivePrice, $units, $unitOverrides),
    'unit'          => $defaultUnit['unit_code'],
    'unit_label'    => product_unit_label_text($defaultUnit),
    'base_unit'     => $p['unit'],
    'base_unit_label' => product_unit_label_text($baseStockUnit),
    'stock_qty'     => $stockQty,
    'stock_display' => product_stock_breakdown($stockQty, $units, $p['unit']),
    'min_stock_qty' => $minQty,
    'stock_low'     => $minQty > 0 && $stockQty <= $minQty,
    'tax_rate'      => (float)$p['tax_rate'],
    'allow_discount'=> (bool)$p['allow_discount'],
    'is_weighable'  => $isWeighable,
    'default_unit'  => $defaultUnit['unit_code'],
    'units'         => array_map(static function (array $unit) use ($effectivePrice, $stockQty, $isWeighable, $units, $p, $unitOverrides) {
        $ratio = (float)$unit['ratio_to_base'];
        $maxRatio = 1.0;
        foreach ($units as $candidate) {
            $maxRatio = max($maxRatio, (float)$candidate['ratio_to_base']);
        }
        $stockQtyForUnit = $stockQty * $ratio;
        if (abs($stockQtyForUnit - round($stockQtyForUnit)) < 0.001) {
            $stockQtyForUnit = (float)round($stockQtyForUnit);
        } else {
            $stockQtyForUnit = round($stockQtyForUnit, 3);
        }
        return [
            'code' => $unit['unit_code'],
            'label' => product_unit_label_text($unit),
            'ratio_to_base' => $ratio,
            'price' => product_unit_price((int)$p['id'], $unit['unit_code'], UISettings::defaultPriceType('pos'), $effectivePrice, $units, $unitOverrides),
            'stock_qty' => $stockQtyForUnit,
            'allow_fractional' => product_unit_allows_fractional($unit, $units, $isWeighable),
            'is_default' => !empty($unit['is_default']),
        ];
    }, $units),
    'image'         => $p['image'],
    'image_url'     => $p['image'] ? UPLOAD_URL . $p['image'] : null,
]]);
