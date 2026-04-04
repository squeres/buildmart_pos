<?php
require_once __DIR__ . '/../../core/bootstrap.php';
Auth::requireLogin();
Auth::requirePerm('pos');

$q     = sanitize($_GET['q']   ?? '');
$cat   = (int)($_GET['cat']   ?? 0);
$whId  = pos_warehouse_id();  // active POS warehouse from session
$allowNegativeStock = allow_negative_stock();
$normalizedQ = normalized_lookup_value($q);

$params = [$whId];
$where  = [
    'p.is_active = 1',
];

if (!$allowNegativeStock) {
    $where[] = 'COALESCE(sb.qty, 0) > 0';
}

if ($q !== '') {
    $like = '%' . $q . '%';
    $normalizedLike = '%' . $normalizedQ . '%';
    $where[] = '(
        p.name_en LIKE ? OR p.name_ru LIKE ? OR p.sku LIKE ? OR p.barcode LIKE ?
        OR p.search_name_normalized LIKE ?
        OR EXISTS (
            SELECT 1
            FROM product_aliases pa
            WHERE pa.product_id = p.id
              AND pa.is_active = 1
              AND (pa.alias LIKE ? OR pa.normalized_alias LIKE ?)
        )
    )';
    array_push($params, $q, $q, $like, $like, $like, $like, $normalizedLike, $like, $normalizedLike);
}

if ($cat > 0) {
    $where[] = 'p.category_id = ?';
    $params[] = $cat;
}

$sql = 'SELECT p.id, p.name_en, p.name_ru, p.sku, p.barcode, p.sale_price, p.unit,
               COALESCE(sb.qty, 0) AS stock_qty,
               p.min_stock_qty, p.image, p.tax_rate, p.allow_discount
        FROM products p
        LEFT JOIN stock_balances sb ON sb.product_id = p.id AND sb.warehouse_id = ?
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY
          CASE
            WHEN ? <> "" AND (p.barcode = ? OR p.sku = ?) THEN 0
            WHEN ? <> "" AND (p.name_en LIKE ? OR p.name_ru LIKE ?) THEN 1
            ELSE 2
          END,
          (COALESCE(sb.qty, 0) <= 0),
          p.name_en
        LIMIT 80';
$rows = Database::all($sql, array_merge($params, [$q, $q, $q, $q, '%' . $q . '%', '%' . $q . '%']));

$defaultPriceType = UISettings::defaultPriceType('pos');
$products = array_values(array_filter(array_map(function($p) use ($defaultPriceType) {
    $stockQty = (float)$p['stock_qty'];
    $minQty   = (float)$p['min_stock_qty'];
    $effectivePrice = UISettings::effectivePrice((int)$p['id'], $defaultPriceType);
    if ($effectivePrice <= 0) {
        return null;
    }
    $defaultUnit = product_default_unit((int)$p['id'], $p['unit']);
    $units = product_units((int)$p['id'], $p['unit']);
    $unitOverrides = product_unit_price_overrides((int)$p['id']);
    return [
        'id'            => (int)$p['id'],
        'name'          => product_name($p),
        'sku'           => $p['sku'],
        'barcode'       => $p['barcode'],
        'sale_price'    => product_unit_price((int)$p['id'], $defaultUnit['unit_code'], $defaultPriceType, $effectivePrice, $units, $unitOverrides),
        'unit'          => $defaultUnit['unit_code'],
        'unit_label'    => product_unit_label_text($defaultUnit),
        'stock_qty'     => $stockQty,
        'stock_display' => product_stock_breakdown($stockQty, $units, $p['unit']),
        'min_stock_qty' => $minQty,
        'stock_low'     => $minQty > 0 && $stockQty <= $minQty,
        'tax_rate'      => (float)$p['tax_rate'],
        'allow_discount'=> (bool)$p['allow_discount'],
        'image'         => $p['image'] ? true : false,
        'image_url'     => $p['image'] ? UPLOAD_URL . $p['image'] : null,
    ];
}, $rows)));

json_response(['products' => $products]);
