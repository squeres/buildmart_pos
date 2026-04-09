<?php
require_once __DIR__ . '/../../core/bootstrap.php';

Auth::requireLogin();
if (!Auth::can('receipts') && !Auth::can('receipts.create') && !Auth::can('receipts.edit')) {
    json_response(['products' => [], 'message' => _r('auth_no_permission')], 403);
}

header('Content-Type: application/json; charset=utf-8');

$query = trim((string)($_GET['q'] ?? ''));
$limit = (int)($_GET['limit'] ?? 8);
$limit = max(1, min(20, $limit));

$buildProductPayload = static function (int $productId): array {
    $product = Database::row(
        "SELECT id, category_id, name_en, name_ru, sku, barcode, unit, cost_price, sale_price, image
         FROM products
         WHERE id = ?",
        [$productId]
    );
    if (!$product) {
        throw new RuntimeException('Product not found');
    }

    $units = product_units($productId, (string)$product['unit']);
    usort($units, static fn($a, $b) => (float)$a['ratio_to_base'] <=> (float)$b['ratio_to_base']);
    $defaultUnit = product_default_unit($productId, (string)$product['unit']);
    $basePrices = UISettings::productPrices($productId);
    $unitOverrides = product_unit_price_overrides($productId);
    $defaultPurchasePrice = product_unit_price(
        $productId,
        (string)$defaultUnit['unit_code'],
        'purchase',
        (float)($basePrices['purchase'] ?? $product['cost_price']),
        $units,
        $unitOverrides
    );
    $defaultSalePrice = product_unit_price(
        $productId,
        (string)$defaultUnit['unit_code'],
        'retail',
        (float)($basePrices['retail'] ?? $product['sale_price']),
        $units,
        $unitOverrides
    );

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

    $aliases = Database::value(
        "SELECT GROUP_CONCAT(pa.alias ORDER BY pa.priority DESC, pa.alias SEPARATOR ', ')
         FROM product_aliases pa
         WHERE pa.product_id = ?
           AND pa.is_active = 1",
        [$productId]
    );

    return [
        'id' => (int)$product['id'],
        'name' => product_name($product),
        'name_en' => (string)$product['name_en'],
        'name_ru' => (string)$product['name_ru'],
        'sku' => (string)$product['sku'],
        'barcode' => (string)($product['barcode'] ?? ''),
        'aliases' => (string)($aliases ?? ''),
        'category_id' => (int)$product['category_id'],
        'unit' => (string)$defaultUnit['unit_code'],
        'base_unit' => (string)$product['unit'],
        'base_unit_label' => product_unit_label_text($units[0] ?? ['unit_code' => $product['unit']]),
        'default_sale_unit' => (string)$defaultUnit['unit_code'],
        'unit_rows' => $editorUnitRows,
        'base_cost_price' => $defaultPurchasePrice,
        'base_sale_price' => $defaultSalePrice,
        'price' => $defaultPurchasePrice,
        'image' => (string)($product['image'] ?? ''),
        'units' => array_map(static function (array $unitRow) use ($productId, $units, $unitOverrides, $basePrices, $product) {
            return [
                'code' => $unitRow['unit_code'],
                'label' => product_unit_label_text($unitRow),
                'ratio' => (float)$unitRow['ratio_to_base'],
                'purchase_price' => product_unit_price($productId, $unitRow['unit_code'], 'purchase', (float)($basePrices['purchase'] ?? $product['cost_price']), $units, $unitOverrides),
                'sale_price' => product_unit_price($productId, $unitRow['unit_code'], 'retail', (float)($basePrices['retail'] ?? $product['sale_price']), $units, $unitOverrides),
                'price' => product_unit_price($productId, $unitRow['unit_code'], 'purchase', (float)($basePrices['purchase'] ?? $product['cost_price']), $units, $unitOverrides),
            ];
        }, $units),
    ];
};

$params = [];
$where = ['p.is_active = 1'];
$normalizedQuery = normalized_lookup_value($query);

if ($query !== '') {
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
}

$sql = "
    SELECT p.id
    FROM products p
    WHERE " . implode(' AND ', $where) . '
    ORDER BY ' . ($query !== ''
        ? "CASE
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
           p.created_at DESC,
           p.name_en ASC"
        : 'p.created_at DESC, p.id DESC') . "
    LIMIT {$limit}";

if ($query !== '') {
    $like = '%' . $query . '%';
    $normalizedLike = '%' . $normalizedQuery . '%';
    array_push($params, $query, $query, $like, $like, $like, $normalizedLike);
}

$ids = array_map(static fn(array $row): int => (int)$row['id'], Database::all($sql, $params));
$products = array_values(array_filter(array_map(static function (int $productId) use ($buildProductPayload): ?array {
    try {
        return $buildProductPayload($productId);
    } catch (\Throwable $e) {
        error_log($e->getMessage());
        return null;
    }
}, $ids)));

json_response([
    'products' => $products,
    'mode' => $query === '' ? 'recent' : 'search',
]);
