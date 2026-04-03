<?php
require_once __DIR__ . '/../../core/bootstrap.php';
Auth::requireLogin();
Auth::requirePerm('receipts');

header('Content-Type: application/json; charset=utf-8');

if (!is_post() || !is_ajax()) {
    json_response(['success' => false, 'error' => 'Invalid request'], 400);
}

if (!csrf_verify()) {
    json_response(['success' => false, 'error' => _r('err_csrf')], 403);
}

$action = sanitize($_POST['action'] ?? '');

$buildProductPayload = static function (int $productId): array {
    $product = Database::row(
        "SELECT id, category_id, name_en, name_ru, sku, barcode, unit, cost_price, sale_price, image
         FROM products WHERE id=?",
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

    $editorUnits = [];
    $prevRatio = 1.0;
    foreach ($units as $idx => $unitRow) {
        if ($idx === 0) {
            continue;
        }
        $ratio = (float)$unitRow['ratio_to_base'];
        $editorUnits[] = [
            'label' => product_unit_label_text($unitRow),
            'ratio_to_base' => $ratio,
            'step' => round($ratio / max(0.001, $prevRatio), 3),
        ];
        $prevRatio = $ratio;
    }

    return [
        'id' => (int)$product['id'],
        'name' => product_name($product),
        'name_en' => (string)$product['name_en'],
        'name_ru' => (string)$product['name_ru'],
        'sku' => (string)$product['sku'],
        'barcode' => (string)$product['barcode'],
        'category_id' => (int)$product['category_id'],
        'unit' => (string)$defaultUnit['unit_code'],
        'base_unit' => (string)$product['unit'],
        'base_unit_label' => product_unit_label_text($units[0] ?? ['unit_code' => $product['unit']]),
        'default_sale_unit' => (string)$defaultUnit['unit_code'],
        'unit_rows' => $editorUnits,
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

$uploadProductImage = static function (?array $file, ?string $currentImage = null): ?string {
    if (empty($file['tmp_name'])) {
        return $currentImage;
    }

    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $mimeType = mime_content_type($file['tmp_name']);
    if (!isset($allowed[$mimeType])) {
        json_response(['success' => false, 'error' => _r('err_upload') . ': unsupported format (use JPG/PNG/WebP)'], 422);
    }
    if (($file['size'] ?? 0) > 2 * 1024 * 1024) {
        json_response(['success' => false, 'error' => _r('err_upload') . ': file too large (max 2MB)'], 422);
    }

    $uploadDir = ROOT_PATH . '/assets/uploads/products/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $filename = 'prod_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $allowed[$mimeType];
    $dest = $uploadDir . $filename;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        json_response(['success' => false, 'error' => _r('err_upload')], 500);
    }

    if ($currentImage) {
        $oldPath = ROOT_PATH . '/' . ltrim((string)$currentImage, '/');
        if (is_file($oldPath)) {
            @unlink($oldPath);
        }
    }

    return 'assets/uploads/products/' . $filename;
};

$normalizeUnitSignature = static function (array $units): array {
    return array_map(static function (array $unit): array {
        return [
            'code' => (string)($unit['unit_code'] ?? ''),
            'label' => (string)($unit['unit_label'] ?? ''),
            'ratio' => (string)round((float)($unit['ratio_to_base'] ?? 0), 6),
            'default' => (int)($unit['is_default'] ?? 0),
        ];
    }, $units);
};

try {
    if ($action === 'create_supplier') {
        Auth::requirePerm('suppliers.manage');
        $name    = sanitize($_POST['name']    ?? '');
        $phone   = sanitize($_POST['phone']   ?? '');
        $inn     = sanitize($_POST['inn']     ?? '');
        $contact = sanitize($_POST['contact'] ?? '');
        $address = sanitize($_POST['address'] ?? '');

        if ($name === '') {
            json_response(['success' => false, 'error' => _r('lbl_required') . ': ' . _r('lbl_name')], 422);
        }

        $newId = Database::insert(
            "INSERT INTO suppliers (name, phone, inn, contact, address, is_active) VALUES (?,?,?,?,?,1)",
            [$name, $phone, $inn, $contact, $address]
        );

        json_response([
            'success' => true,
            'data'    => ['id' => $newId, 'name' => $name],
        ]);
    }

    if ($action === 'create_product' || $action === 'update_product') {
        Auth::requirePerm($action === 'update_product' ? 'products.edit' : 'products.create');
        $productId   = (int)($_POST['product_id'] ?? 0);
        $isUpdate    = $action === 'update_product';
        $existing    = $isUpdate ? Database::row("SELECT * FROM products WHERE id=?", [$productId]) : null;
        if ($isUpdate && !$existing) {
            json_response(['success' => false, 'error' => 'Product not found'], 404);
        }

        $nameRu      = sanitize($_POST['name_ru'] ?? '');
        $nameEn      = sanitize($_POST['name_en'] ?? '');
        $sku         = sanitize($_POST['sku'] ?? '');
        $barcode     = sanitize($_POST['barcode'] ?? '');
        $unit        = sanitize($_POST['unit'] ?? '');
        $unitLabel   = sanitize($_POST['unit_label'] ?? '');
        $unitRows    = $_POST['unit_rows'] ?? [];
        $unitPriceRows = $_POST['unit_price_rows'] ?? [];
        if ($unit === '') {
            $unit = $unitLabel !== '' ? unit_storage_code_from_label($unitLabel) : ((string)($existing['unit'] ?? 'pcs'));
        }
        $defaultSaleUnitCode = sanitize($_POST['default_sale_unit'] ?? $unit);
        $categoryId  = (int)($_POST['category_id'] ?? 0);

        if ($nameRu === '' && $nameEn === '') {
            json_response(['success' => false, 'error' => _r('lbl_required') . ': ' . _r('lbl_name')], 422);
        }
        if ($nameEn === '') {
            $nameEn = $nameRu;
        }
        if ($nameRu === '') {
            $nameRu = $nameEn;
        }

        if ($sku === '') {
            $seed = $nameEn . microtime() . ($productId ?: 'new');
            $sku = 'AUTO-' . strtoupper(substr(md5($seed), 0, 8));
        }
        if ($barcode === '') {
            $barcode = generate_product_barcode();
        }

        $duplicateSku = Database::value(
            "SELECT id FROM products WHERE sku=? AND id<>?",
            [$sku, $productId]
        );
        if ($duplicateSku) {
            json_response(['success' => false, 'error' => _r('prod_sku_exists') . ': ' . $sku], 422);
        }

        $duplicateBarcode = Database::value(
            "SELECT id FROM products WHERE barcode=? AND id<>?",
            [$barcode, $productId]
        );
        if ($barcode && $duplicateBarcode) {
            json_response(['success' => false, 'error' => _r('prod_barcode_exists') . ': ' . $barcode], 422);
        }

        if ($categoryId < 1) {
            $categoryId = (int)(Database::value("SELECT id FROM categories WHERE is_active=1 ORDER BY sort_order LIMIT 1") ?? ($existing['category_id'] ?? 1));
        }

        $imagePath = $uploadProductImage($_FILES['image'] ?? null, $existing['image'] ?? null);
        $normalizedUnits = normalize_product_units($unitRows, $unit, $defaultSaleUnitCode, $unitLabel !== '' ? $unitLabel : unit_label($unit));

        if ($isUpdate) {
            if ((string)$existing['unit'] !== $unit && (float)$existing['stock_qty'] > 0) {
                json_response(['success' => false, 'error' => 'Base unit can only be changed when stock is zero.'], 422);
            }

            $currentUnits = product_units($productId, (string)$existing['unit']);
            $structureChanged = $normalizeUnitSignature($currentUnits) !== $normalizeUnitSignature($normalizedUnits);

            Database::exec(
                "UPDATE products
                 SET category_id=?, name_en=?, name_ru=?, sku=?, barcode=?, unit=?, image=?, updated_at=NOW()
                 WHERE id=?",
                [$categoryId, $nameEn, $nameRu, $sku, $barcode, $unit, $imagePath, $productId]
            );
            save_product_units($productId, $unit, $unitRows, $defaultSaleUnitCode, $unitLabel !== '' ? $unitLabel : unit_label($unit));
            if ($structureChanged) {
                Database::exec("DELETE FROM product_unit_prices WHERE product_id=?", [$productId]);
            }

            json_response([
                'success' => true,
                'data' => $buildProductPayload($productId),
            ]);
        }

        $defaultUnitIndex = 0;
        foreach ($normalizedUnits as $idx => $unitRow) {
            if ((string)$unitRow['unit_code'] === $defaultSaleUnitCode) {
                $defaultUnitIndex = $idx;
                break;
            }
        }
        $baseCostPrice = sanitize_float($unitPriceRows[$defaultUnitIndex]['purchase'] ?? 0);
        $baseSalePrice = sanitize_float($unitPriceRows[$defaultUnitIndex]['retail'] ?? 0);

        $newId = Database::insert(
            "INSERT INTO products
               (category_id, name_en, name_ru, sku, barcode, unit, cost_price, sale_price, tax_rate, stock_qty, image, is_active)
             VALUES (?,?,?,?,?,?,?,?,0,0,?,1)",
            [$categoryId, $nameEn, $nameRu, $sku, $barcode, $unit, $baseCostPrice, $baseSalePrice, $imagePath]
        );
        save_product_units($newId, $unit, $unitRows, $defaultSaleUnitCode, $unitLabel !== '' ? $unitLabel : unit_label($unit));
        UISettings::saveProductPrices($newId, [
            'purchase' => $baseCostPrice,
            'retail' => $baseSalePrice,
        ]);
        save_product_unit_prices($newId, $normalizedUnits, $unitPriceRows, [
            'purchase' => $baseCostPrice,
            'retail' => $baseSalePrice,
        ]);

        json_response([
            'success' => true,
            'data' => $buildProductPayload($newId),
        ]);
    }

    json_response(['success' => false, 'error' => 'Unknown action'], 400);
} catch (Throwable $e) {
    json_response(['success' => false, 'error' => $e->getMessage()], 500);
}
