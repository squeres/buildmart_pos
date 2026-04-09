<?php
require_once __DIR__ . '/../../core/bootstrap.php';

Auth::requireLogin();
if (
    !Auth::can('inventory.count')
    && !Auth::can('inventory.receive')
    && !Auth::can('inventory.adjust')
    && !Auth::can('inventory.writeoff')
) {
    json_response(['products' => [], 'message' => _r('auth_no_permission')], 403);
}

$warehouseId = (int)($_GET['warehouse_id'] ?? 0);
if ($warehouseId <= 0 || !user_can_access_warehouse($warehouseId)) {
    json_response(['products' => [], 'message' => _r('auth_no_permission')], 403);
}

$productId = (int)($_GET['id'] ?? 0);
$query = sanitize($_GET['q'] ?? '');
$normalizedQuery = normalized_lookup_value($query);

$baseSql = "
    SELECT
        p.id,
        p.name_en,
        p.name_ru,
        p.sku,
        p.barcode,
        p.brand,
        p.unit,
        p.min_stock_qty,
        COALESCE(sb.qty, 0) AS stock_qty,
        (
            SELECT GROUP_CONCAT(pa.alias ORDER BY pa.priority DESC, pa.alias SEPARATOR ', ')
            FROM product_aliases pa
            WHERE pa.product_id = p.id
              AND pa.is_active = 1
        ) AS aliases
    FROM products p
    LEFT JOIN stock_balances sb ON sb.product_id = p.id AND sb.warehouse_id = ?
";

$params = [$warehouseId];
$where = ['p.is_active = 1'];

if ($productId > 0) {
    $where[] = 'p.id = ?';
    $params[] = $productId;
} elseif ($query !== '') {
    $like = '%' . $query . '%';
    $normalizedLike = '%' . $normalizedQuery . '%';
    $where[] = "(
        p.barcode = ? OR p.sku = ?
        OR p.name_en LIKE ? OR p.name_ru LIKE ?
        OR p.sku LIKE ? OR p.barcode LIKE ?
        OR p.search_name_normalized LIKE ?
        OR EXISTS (
            SELECT 1
            FROM product_aliases pa
            WHERE pa.product_id = p.id
              AND pa.is_active = 1
              AND (pa.alias LIKE ? OR pa.normalized_alias LIKE ?)
        )
    )";
    array_push($params, $query, $query, $like, $like, $like, $like, $normalizedLike, $like, $normalizedLike);
} else {
    json_response(['products' => []]);
}

$orderSql = $productId > 0
    ? 'p.name_en'
    : "CASE
          WHEN p.barcode = ? OR p.sku = ? THEN 0
          WHEN p.name_en LIKE ? OR p.name_ru LIKE ? THEN 1
          WHEN EXISTS (
              SELECT 1
              FROM product_aliases pa
              WHERE pa.product_id = p.id
                AND pa.is_active = 1
                AND (pa.alias LIKE ? OR pa.normalized_alias LIKE ?)
          ) THEN 2
          ELSE 3
       END,
       (COALESCE(sb.qty, 0) < 0) DESC,
       (COALESCE(sb.qty, 0) = 0),
       p.name_en";

$sql = $baseSql . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY ' . $orderSql . ' LIMIT 20';

if ($productId <= 0) {
    $like = '%' . $query . '%';
    $normalizedLike = '%' . $normalizedQuery . '%';
    array_push($params, $query, $query, $like, $like, $like, $normalizedLike);
}

$rows = Database::all($sql, $params);
$products = array_map(static function (array $row): array {
    $units = product_units((int)$row['id'], (string)$row['unit']);
    $defaultUnit = product_default_unit((int)$row['id'], (string)$row['unit']);
    $stockQty = (float)$row['stock_qty'];

    return [
        'id' => (int)$row['id'],
        'name' => product_name($row),
        'sku' => (string)$row['sku'],
        'barcode' => (string)($row['barcode'] ?? ''),
        'brand' => (string)($row['brand'] ?? ''),
        'unit' => (string)$row['unit'],
        'unit_label' => unit_label((string)$row['unit']),
        'stock_qty' => $stockQty,
        'stock_display' => product_stock_breakdown($stockQty, $units, (string)$row['unit']),
        'stock_low' => (float)$row['min_stock_qty'] > 0 && $stockQty <= (float)$row['min_stock_qty'],
        'aliases' => (string)($row['aliases'] ?? ''),
        'default_unit_code' => (string)($defaultUnit['unit_code'] ?? $row['unit']),
        'default_unit_label' => product_unit_label_text($defaultUnit),
        'units' => array_map(
            static fn (array $unit): array => [
                'unit_code' => (string)($unit['unit_code'] ?? ''),
                'unit_label' => product_unit_label_text($unit),
                'ratio_to_base' => (float)($unit['ratio_to_base'] ?? 1),
                'is_default' => !empty($unit['is_default']),
            ],
            $units
        ),
    ];
}, $rows);

json_response(['products' => $products]);
