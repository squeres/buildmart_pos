<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$sessionPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.sessions';
if (is_dir($sessionPath)) {
    session_save_path($sessionPath);
}

$_SERVER['SCRIPT_NAME'] = '/migrations/007_fix_legacy_unit_data.php';
$_SERVER['REQUEST_URI'] = $_SERVER['SCRIPT_NAME'];

require_once __DIR__ . '/../core/bootstrap.php';

$apply = in_array('--apply', $argv, true);
$migrationUserId = (int)(Database::value("SELECT id FROM users WHERE is_active=1 ORDER BY id ASC LIMIT 1") ?: 1);
$priceTypes = Database::all('SELECT code FROM price_types WHERE is_active=1 ORDER BY sort_order, id');
$priceTypeCodes = array_map(static fn(array $row): string => (string)$row['code'], $priceTypes);

$receiptRows = Database::all(
    "SELECT
        gr.id AS receipt_id,
        gr.doc_no,
        gr.status AS receipt_status,
        gr.warehouse_id,
        gr.accepted_at,
        gr.cancelled_at,
        gr.created_at AS receipt_created_at,
        gri.*
     FROM goods_receipts gr
     JOIN goods_receipt_items gri ON gri.receipt_id = gr.id
     WHERE gri.product_id IS NOT NULL
     ORDER BY COALESCE(gr.accepted_at, gr.created_at) DESC, gri.id DESC"
);

$saleRows = Database::all(
    "SELECT
        s.id AS sale_id,
        s.status AS sale_status,
        s.warehouse_id,
        s.created_at AS sale_created_at,
        si.*
     FROM sales s
     JOIN sale_items si ON si.sale_id = s.id
     ORDER BY s.created_at DESC, si.id DESC"
);

$movementRows = Database::all(
    "SELECT
        product_id,
        warehouse_id,
        type,
        reference_id,
        reference_type,
        SUM(qty_change) AS qty_sum
     FROM inventory_movements
     GROUP BY product_id, warehouse_id, type, reference_id, reference_type"
);

$receiptRowsByProduct = [];
foreach ($receiptRows as $row) {
    $receiptRowsByProduct[(int)$row['product_id']][] = $row;
}

$saleRowsByProduct = [];
foreach ($saleRows as $row) {
    $saleRowsByProduct[(int)$row['product_id']][] = $row;
}

$movementMap = [];
foreach ($movementRows as $row) {
    $referenceType = (string)($row['reference_type'] ?? '');
    $referenceId = (int)($row['reference_id'] ?? 0);
    $productId = (int)$row['product_id'];
    $type = (string)$row['type'];
    $movementMap[$referenceType][$referenceId][$productId][$type] = (float)$row['qty_sum'];
}

function unit_ratio_map(array $units): array
{
    $map = [];
    foreach ($units as $unit) {
        $map[(string)$unit['unit_code']] = max(1.0, (float)($unit['ratio_to_base'] ?? 1));
    }
    return $map;
}

function unit_label_map(array $units): array
{
    $map = [];
    foreach ($units as $unit) {
        $map[(string)$unit['unit_code']] = product_unit_label_text($unit);
    }
    return $map;
}

function positive_price_map(array $map, array $ratioMap): array
{
    $clean = [];
    foreach ($map as $unitCode => $value) {
        $unitCode = (string)$unitCode;
        if (!isset($ratioMap[$unitCode])) {
            continue;
        }
        $price = round((float)$value, 2);
        if ($price > 0) {
            $clean[$unitCode] = $price;
        }
    }
    return $clean;
}

function derive_price_map(array $ratioMap, string $anchorUnitCode, float $anchorPrice): array
{
    if ($anchorPrice <= 0 || !isset($ratioMap[$anchorUnitCode])) {
        return [];
    }

    $anchorRatio = $ratioMap[$anchorUnitCode];
    $map = [];
    foreach ($ratioMap as $unitCode => $ratio) {
        $map[$unitCode] = derive_unit_price($anchorPrice, $anchorRatio, $ratio);
    }
    return $map;
}

function infer_unit_for_price(
    array $ratioMap,
    array $priceMap,
    string $storedUnit,
    float $price
): string {
    if ($price <= 0) {
        return isset($ratioMap[$storedUnit]) ? $storedUnit : (array_key_first($ratioMap) ?: $storedUnit);
    }

    $bestUnit = isset($ratioMap[$storedUnit]) ? $storedUnit : array_key_first($ratioMap);
    $bestScore = INF;

    foreach ($ratioMap as $unitCode => $_ratio) {
        if (!isset($priceMap[$unitCode]) || (float)$priceMap[$unitCode] <= 0) {
            continue;
        }

        $score = price_relative_diff($price, (float)$priceMap[$unitCode]);
        if ($score < $bestScore) {
            $bestScore = $score;
            $bestUnit = $unitCode;
        }
    }

    if (!isset($ratioMap[$storedUnit])) {
        return $bestUnit;
    }

    $storedScore = isset($priceMap[$storedUnit]) && (float)$priceMap[$storedUnit] > 0
        ? price_relative_diff($price, (float)$priceMap[$storedUnit])
        : INF;

    if ($bestUnit !== $storedUnit && $bestScore <= 0.25 && ($storedScore === INF || $storedScore > $bestScore + 0.2 || $storedScore > 0.5)) {
        return $bestUnit;
    }

    return $storedUnit;
}

function json_or_null(array $map): ?string
{
    if (!$map) {
        return null;
    }
    ksort($map);
    return json_encode($map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function map_changed(?string $currentJson, ?string $nextJson): bool
{
    $current = trim((string)$currentJson);
    $next = trim((string)$nextJson);
    return $current !== $next;
}

function ensure_stock_precision(bool $apply): array
{
    $targets = [
        ['table' => 'products', 'column' => 'stock_qty', 'sql' => "ALTER TABLE `products` MODIFY `stock_qty` DECIMAL(14,6) NOT NULL DEFAULT 0.000000"],
        ['table' => 'products', 'column' => 'min_stock_qty', 'sql' => "ALTER TABLE `products` MODIFY `min_stock_qty` DECIMAL(14,6) NOT NULL DEFAULT 0.000000"],
        ['table' => 'stock_balances', 'column' => 'qty', 'sql' => "ALTER TABLE `stock_balances` MODIFY `qty` DECIMAL(14,6) NOT NULL DEFAULT 0.000000"],
        ['table' => 'inventory_movements', 'column' => 'qty_change', 'sql' => "ALTER TABLE `inventory_movements` MODIFY `qty_change` DECIMAL(14,6) NOT NULL"],
        ['table' => 'inventory_movements', 'column' => 'qty_before', 'sql' => "ALTER TABLE `inventory_movements` MODIFY `qty_before` DECIMAL(14,6) NOT NULL"],
        ['table' => 'inventory_movements', 'column' => 'qty_after', 'sql' => "ALTER TABLE `inventory_movements` MODIFY `qty_after` DECIMAL(14,6) NOT NULL"],
    ];

    $changed = [];
    foreach ($targets as $target) {
        $scale = Database::value(
            "SELECT NUMERIC_SCALE
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?",
            [$target['table'], $target['column']]
        );

        if ((int)$scale >= 6) {
            continue;
        }

        $changed[] = $target['table'] . '.' . $target['column'];
        if ($apply) {
            Database::exec($target['sql']);
        }
    }

    return $changed;
}

function report_line(string $text): void
{
    fwrite(STDOUT, $text . PHP_EOL);
}

$precisionUpdates = ensure_stock_precision($apply);

$products = Database::all("SELECT * FROM products ORDER BY id ASC");
$summary = [
    'precision_updates' => $precisionUpdates,
    'products_updated' => 0,
    'receipt_rows_updated' => 0,
    'stock_corrections' => 0,
];

$productReports = [];
$stockCorrections = [];

Database::beginTransaction();

try {
    foreach ($products as $product) {
        $productId = (int)$product['id'];
        $units = product_units($productId, (string)$product['unit']);
        if (!$units) {
            continue;
        }

        usort($units, static fn(array $left, array $right): int => (float)$left['ratio_to_base'] <=> (float)$right['ratio_to_base']);
        $ratioMap = unit_ratio_map($units);
        $labelMap = unit_label_map($units);
        $defaultUnit = product_default_unit($productId, (string)$product['unit']);
        $defaultUnitCode = (string)($defaultUnit['unit_code'] ?? (string)$product['unit']);
        $priceOverrides = product_unit_price_overrides($productId);
        $basePrices = UISettings::productPrices($productId);

        $currentUnitPrices = [];
        foreach ($priceTypeCodes as $priceTypeCode) {
            $fallback = (float)($basePrices[$priceTypeCode] ?? match ($priceTypeCode) {
                'retail' => (float)$product['sale_price'],
                'purchase' => (float)$product['cost_price'],
                default => 0.0,
            });
            foreach ($units as $unit) {
                $unitCode = (string)$unit['unit_code'];
                $currentUnitPrices[$priceTypeCode][$unitCode] = product_unit_price(
                    $productId,
                    $unitCode,
                    $priceTypeCode,
                    $fallback,
                    $units,
                    $priceOverrides
                );
            }
        }

        $purchaseMap = $currentUnitPrices['purchase'] ?? [];
        $saleMap = $currentUnitPrices['retail'] ?? [];
        $productReceiptRows = $receiptRowsByProduct[$productId] ?? [];
        $latestAcceptedRow = null;
        foreach ($productReceiptRows as $receiptRow) {
            if (($receiptRow['receipt_status'] ?? '') === 'accepted') {
                $latestAcceptedRow = $receiptRow;
                break;
            }
        }

        if ($latestAcceptedRow) {
            $rowPurchaseExplicit = positive_price_map((array)json_decode((string)($latestAcceptedRow['unit_prices_json'] ?? ''), true), $ratioMap);
            $rowSaleExplicit = positive_price_map((array)json_decode((string)($latestAcceptedRow['sale_prices_json'] ?? ''), true), $ratioMap);
            $effectivePurchaseUnit = infer_unit_for_price($ratioMap, $purchaseMap, (string)$latestAcceptedRow['unit'], (float)$latestAcceptedRow['unit_price']);
            $effectiveSaleUnit = infer_unit_for_price($ratioMap, $saleMap, (string)$latestAcceptedRow['unit'], (float)$latestAcceptedRow['sale_price']);

            if ((float)$latestAcceptedRow['unit_price'] > 0) {
                $purchaseMap = derive_price_map($ratioMap, $effectivePurchaseUnit, (float)$latestAcceptedRow['unit_price']);
            }
            if ($rowPurchaseExplicit) {
                $purchaseMap = array_replace($purchaseMap, $rowPurchaseExplicit);
            }

            if ((float)$latestAcceptedRow['sale_price'] > 0) {
                $saleMap = derive_price_map($ratioMap, $effectiveSaleUnit, (float)$latestAcceptedRow['sale_price']);
            }
            if ($rowSaleExplicit) {
                $saleMap = array_replace($saleMap, $rowSaleExplicit);
            }

            foreach ($ratioMap as $unitCode => $_ratio) {
                if (($purchaseMap[$unitCode] ?? 0) <= 0 && isset($currentUnitPrices['purchase'][$unitCode])) {
                    $purchaseMap[$unitCode] = (float)$currentUnitPrices['purchase'][$unitCode];
                }
                if (($saleMap[$unitCode] ?? 0) <= 0 && isset($currentUnitPrices['retail'][$unitCode])) {
                    $saleMap[$unitCode] = (float)$currentUnitPrices['retail'][$unitCode];
                }
            }
        }

        $resolvedDefaultPurchase = round((float)($purchaseMap[$defaultUnitCode] ?? ($currentUnitPrices['purchase'][$defaultUnitCode] ?? $product['cost_price'])), 2);
        $resolvedDefaultSale = round((float)($saleMap[$defaultUnitCode] ?? ($currentUnitPrices['retail'][$defaultUnitCode] ?? $product['sale_price'])), 2);

        $priceRows = [];
        foreach (array_values($units) as $index => $unit) {
            $unitCode = (string)$unit['unit_code'];
            $row = [];
            foreach ($priceTypeCodes as $priceTypeCode) {
                $row[$priceTypeCode] = round((float)($currentUnitPrices[$priceTypeCode][$unitCode] ?? 0), 2);
            }
            $row['purchase'] = round((float)($purchaseMap[$unitCode] ?? $row['purchase'] ?? 0), 2);
            $row['retail'] = round((float)($saleMap[$unitCode] ?? $row['retail'] ?? 0), 2);
            $priceRows[$index] = $row;
        }

        $productChanged = abs((float)$product['cost_price'] - $resolvedDefaultPurchase) >= 0.01
            || abs((float)$product['sale_price'] - $resolvedDefaultSale) >= 0.01;

        $basePricesToSave = $basePrices;
        $basePricesToSave['purchase'] = $resolvedDefaultPurchase;
        $basePricesToSave['retail'] = $resolvedDefaultSale;

        if ($apply) {
            save_product_unit_prices($productId, $units, $priceRows, $basePricesToSave);
            UISettings::saveProductPrices($productId, $basePricesToSave);
            Database::exec(
                "UPDATE products SET cost_price=?, sale_price=?, updated_at=NOW() WHERE id=?",
                [$resolvedDefaultPurchase, $resolvedDefaultSale, $productId]
            );
        }

        foreach ($productReceiptRows as $receiptRow) {
            $storedUnit = (string)$receiptRow['unit'];
            $effectivePurchaseUnit = infer_unit_for_price($ratioMap, $purchaseMap, $storedUnit, (float)$receiptRow['unit_price']);
            $effectiveSaleUnit = infer_unit_for_price($ratioMap, $saleMap, $storedUnit, (float)$receiptRow['sale_price']);
            $effectiveUnit = (float)$receiptRow['unit_price'] > 0 ? $effectivePurchaseUnit : $effectiveSaleUnit;

            $rowPurchaseExplicit = positive_price_map((array)json_decode((string)($receiptRow['unit_prices_json'] ?? ''), true), $ratioMap);
            $rowSaleExplicit = positive_price_map((array)json_decode((string)($receiptRow['sale_prices_json'] ?? ''), true), $ratioMap);

            $rowPurchaseMap = $rowPurchaseExplicit;
            if ((float)$receiptRow['unit_price'] > 0) {
                $rowPurchaseMap = array_replace(
                    derive_price_map($ratioMap, $effectivePurchaseUnit, (float)$receiptRow['unit_price']),
                    $rowPurchaseExplicit
                );
            } elseif (!$rowPurchaseMap) {
                $rowPurchaseMap = $purchaseMap;
            }

            $rowSaleMap = $rowSaleExplicit;
            if ((float)$receiptRow['sale_price'] > 0) {
                $rowSaleMap = array_replace(
                    derive_price_map($ratioMap, $effectiveSaleUnit, (float)$receiptRow['sale_price']),
                    $rowSaleExplicit
                );
            } elseif (!$rowSaleMap) {
                $rowSaleMap = $saleMap;
            }

            $nextPurchaseJson = json_or_null(array_map(static fn($price): float => round((float)$price, 2), $rowPurchaseMap));
            $nextSaleJson = json_or_null(array_map(static fn($price): float => round((float)$price, 2), $rowSaleMap));
            $rowChanged = $effectiveUnit !== $storedUnit
                || map_changed((string)($receiptRow['unit_prices_json'] ?? ''), $nextPurchaseJson)
                || map_changed((string)($receiptRow['sale_prices_json'] ?? ''), $nextSaleJson);

            if ($rowChanged) {
                $summary['receipt_rows_updated']++;
                if ($apply) {
                    Database::exec(
                        "UPDATE goods_receipt_items
                         SET unit = ?, unit_prices_json = ?, sale_prices_json = ?
                         WHERE id = ?",
                        [$effectiveUnit, $nextPurchaseJson, $nextSaleJson, (int)$receiptRow['id']]
                    );
                }
            }

            $qtyDocument = $receiptRow['accepted_qty'] !== null ? (float)$receiptRow['accepted_qty'] : (float)$receiptRow['qty'];
            if ($qtyDocument <= 0) {
                continue;
            }

            $expectedBaseQty = stock_qty_round($qtyDocument / max(1.0, (float)($ratioMap[$effectiveUnit] ?? 1.0)));
            $warehouseId = (int)$receiptRow['warehouse_id'];
            $receiptId = (int)$receiptRow['receipt_id'];
            $actualAccept = (float)($movementMap['receipt_accept'][$receiptId][$productId]['receipt'] ?? 0.0);
            $actualCancel = (float)($movementMap['goods_receipt_cancel'][$receiptId][$productId]['adjustment'] ?? 0.0);

            $expectAccept = 0.0;
            $expectCancel = 0.0;
            $status = (string)$receiptRow['receipt_status'];
            $wasAccepted = !empty($receiptRow['accepted_at']) || abs($actualAccept) > 0.000001 || abs($actualCancel) > 0.000001;
            if ($status === 'accepted') {
                $expectAccept = $expectedBaseQty;
            } elseif ($status === 'cancelled' && $wasAccepted) {
                $expectAccept = $expectedBaseQty;
                $expectCancel = -$expectedBaseQty;
            }

            $delta = stock_qty_round(($expectAccept - $actualAccept) + ($expectCancel - $actualCancel));
            if (abs($delta) > 0.000001) {
                $key = $productId . ':' . $warehouseId;
                $stockCorrections[$key] = ($stockCorrections[$key] ?? 0.0) + $delta;
            }
        }

        foreach (($saleRowsByProduct[$productId] ?? []) as $saleRow) {
            $saleUnit = (string)$saleRow['unit'];
            $ratio = max(1.0, (float)($ratioMap[$saleUnit] ?? 1.0));
            $expectedBaseQty = stock_qty_round((float)$saleRow['qty'] / $ratio);
            if ($expectedBaseQty <= 0) {
                continue;
            }

            $saleId = (int)$saleRow['sale_id'];
            $warehouseId = (int)$saleRow['warehouse_id'];
            $actualSale = (float)($movementMap['sale'][$saleId][$productId]['sale'] ?? 0.0);
            $actualReturn = (float)($movementMap['sale'][$saleId][$productId]['return'] ?? 0.0);
            $expectedSale = -$expectedBaseQty;
            $expectedReturn = (string)$saleRow['sale_status'] === 'voided' ? $expectedBaseQty : 0.0;
            $delta = stock_qty_round(($expectedSale - $actualSale) + ($expectedReturn - $actualReturn));
            if (abs($delta) > 0.000001) {
                $key = $productId . ':' . $warehouseId;
                $stockCorrections[$key] = ($stockCorrections[$key] ?? 0.0) + $delta;
            }
        }

        if ($productChanged) {
            $summary['products_updated']++;
        }

        $productReports[] = [
            'id' => $productId,
            'name' => product_name($product),
            'default_unit' => $labelMap[$defaultUnitCode] ?? $defaultUnitCode,
            'purchase' => $resolvedDefaultPurchase,
            'sale' => $resolvedDefaultSale,
        ];
    }

    foreach ($stockCorrections as $key => $delta) {
        $delta = stock_qty_round($delta);
        if (abs($delta) <= 0.000001) {
            continue;
        }

        [$productId, $warehouseId] = array_map('intval', explode(':', $key, 2));
        $summary['stock_corrections']++;

        if ($apply) {
            [$qtyBefore, $qtyAfter] = update_stock_balance($productId, $warehouseId, $delta);
            Database::insert(
                "INSERT INTO inventory_movements
                    (product_id, warehouse_id, user_id, type, qty_change, qty_before, qty_after, reference_type, notes, created_at)
                 VALUES (?,?,?,?,?,?,?,?,?,NOW())",
                [
                    $productId,
                    $warehouseId,
                    $migrationUserId,
                    'adjustment',
                    $delta,
                    $qtyBefore,
                    $qtyAfter,
                    'migration_007',
                    'Legacy unit correction migration',
                ]
            );
        }
    }

    if ($apply) {
        Database::commit();
    } else {
        Database::rollback();
    }
} catch (Throwable $e) {
    Database::rollback();
    fwrite(STDERR, 'Migration failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

report_line(($apply ? '[APPLY]' : '[DRY-RUN]') . ' legacy unit data migration');
if ($precisionUpdates) {
    report_line('Precision updates: ' . implode(', ', $precisionUpdates));
}
report_line('Products to refresh: ' . $summary['products_updated']);
report_line('Receipt rows to normalize: ' . $summary['receipt_rows_updated']);
report_line('Stock corrections: ' . $summary['stock_corrections']);

foreach ($productReports as $report) {
    report_line(sprintf(
        '#%d %s | default=%s | purchase=%.2f | retail=%.2f',
        $report['id'],
        $report['name'],
        $report['default_unit'],
        $report['purchase'],
        $report['sale']
    ));
}
