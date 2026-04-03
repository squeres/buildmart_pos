<?php
/**
 * Приёмка товара — Обработчик сохранения и подтверждения
 * modules/acceptance/save.php
 *
 * action=save   → сохраняет фактические кол-ва и цены в items, статус не меняется
 * action=accept → сохраняет данные + проводит приёмку:
 *                  - обновляет stock_qty в products (+ accepted_qty)
 *                  - обновляет cost_price и sale_price на products
 *                  - создаёт строку в inventory_movements
 *                  - ставит статус 'accepted', записывает кто и когда
 *
 * Защита от двойного проведения: проверяем status = 'pending_acceptance' с FOR UPDATE.
 */
require_once __DIR__ . '/../../core/bootstrap.php';
Auth::requireLogin();

if (!is_post()) { redirect('/modules/acceptance/'); }
if (!csrf_verify()) { flash_error(_r('err_csrf')); redirect('/modules/acceptance/'); }

$id     = (int)($_POST['id'] ?? 0);
$action = sanitize($_POST['action'] ?? 'save');
if (!$id) { redirect('/modules/acceptance/'); }

Auth::requirePerm('acceptance');
Auth::requirePerm($action === 'accept' ? 'acceptance.accept' : 'acceptance.process');

// Загружаем документ
$doc = Database::row(
    "SELECT * FROM goods_receipts WHERE id=? AND status='pending_acceptance'",
    [$id]
);
if (!$doc) {
    flash_error(_r('acc_err_not_pending'));
    redirect('/modules/acceptance/');
}
require_warehouse_access((int)$doc['warehouse_id'], '/modules/acceptance/');

// ── Разбираем и валидируем данные из формы ──────────────────────
$rows = $_POST['items'] ?? [];
$cleanRows = [];
$errors    = [];

foreach ($rows as $row) {
    $itemId      = (int)($row['item_id']      ?? 0);
    $acceptedQty = sanitize_float($row['accepted_qty'] ?? 0);
    $costPrice   = sanitize_float($row['unit_price']   ?? 0);
    $salePrice   = sanitize_float($row['sale_price']   ?? 0);
    $unit        = sanitize($row['unit'] ?? '');
    $unitPricesJson = trim((string)($row['unit_prices_json'] ?? ''));
    $salePricesJson = trim((string)($row['sale_prices_json'] ?? ''));

    if ($itemId < 1) continue;
    if ($action === 'accept' && $acceptedQty < 0) {
        $errors[] = _r('acc_err_qty_negative');
        continue;
    }

    $cleanRows[] = [
        'item_id'      => $itemId,
        'accepted_qty' => $acceptedQty,
        'unit_price'   => $costPrice,
        'sale_price'   => $salePrice,
        'unit'         => $unit,
        'unit_prices_json' => $unitPricesJson,
        'sale_prices_json' => $salePricesJson,
    ];
}

if ($errors) {
    foreach ($errors as $e) flash_error($e);
    redirect('/modules/acceptance/view.php?id='.$id);
}

try {
    Database::beginTransaction();

    // Перепроверяем статус с блокировкой
    $statusNow = Database::value(
        "SELECT status FROM goods_receipts WHERE id=? FOR UPDATE",
        [$id]
    );
    if ($statusNow !== 'pending_acceptance') {
        Database::rollback();
        flash_error(_r('acc_err_already_processed'));
        redirect('/modules/acceptance/view.php?id='.$id);
    }

    // Сохраняем принятые данные в строки документа
    foreach ($cleanRows as $r) {
        Database::exec(
            "UPDATE goods_receipt_items
             SET accepted_qty=?, unit_price=?, sale_price=?, unit=?, unit_prices_json=?, sale_prices_json=?
             WHERE id=? AND receipt_id=?",
            [$r['accepted_qty'], $r['unit_price'], $r['sale_price'], $r['unit'], $r['unit_prices_json'], $r['sale_prices_json'], $r['item_id'], $id]
        );
    }

    // Пересчитываем totals документа по новым данным
    Database::exec(
        "UPDATE goods_receipts gr
         SET subtotal  = (SELECT COALESCE(SUM(accepted_qty * unit_price), 0)
                          FROM goods_receipt_items WHERE receipt_id = gr.id),
             tax_amount = (SELECT COALESCE(SUM(accepted_qty * unit_price * tax_rate / 100), 0)
                           FROM goods_receipt_items WHERE receipt_id = gr.id),
             total      = (SELECT COALESCE(SUM(accepted_qty * unit_price * (1 + tax_rate/100)), 0)
                           FROM goods_receipt_items WHERE receipt_id = gr.id),
             updated_at = NOW()
         WHERE gr.id = ?",
        [$id]
    );

    if ($action === 'accept') {
        // ── Проводим приёмку — двигаем товары в остатки ────────
        $warehouseId = (int)$doc['warehouse_id'];
        $items = Database::all(
            "SELECT * FROM goods_receipt_items WHERE receipt_id=?",
            [$id]
        );

        foreach ($items as $item) {
            if (!$item['product_id']) continue; // товар без привязки — пропускаем

            $acceptedQty = $item['accepted_qty'] !== null
                ? (float)$item['accepted_qty']
                : (float)$item['qty'];

            if ($acceptedQty <= 0) continue; // нулевые строки не двигают остатки

            $costPrice = (float)$item['unit_price'];
            $salePrice = (float)$item['sale_price'];

            // Проверяем что товар существует
            $prod = Database::row(
                "SELECT id, unit, cost_price, sale_price FROM products WHERE id=?",
                [$item['product_id']]
            );
            if (!$prod) continue;
            $rowUnits = product_units((int)$item['product_id'], $prod['unit']);
            $unitMap = [];
            foreach ($rowUnits as $rowUnit) {
                $unitMap[(string)$rowUnit['unit_code']] = $rowUnit;
            }
            $selectedUnit = $unitMap[$item['unit']] ?? ($unitMap[$prod['unit']] ?? ['ratio_to_base' => 1]);
            $ratioToBase = max(1.0, (float)$selectedUnit['ratio_to_base']);
            $acceptedQtyBase = stock_qty_round($acceptedQty / $ratioToBase);
            $costPriceBase = $costPrice * $ratioToBase;
            $salePriceBase = $salePrice > 0 ? $salePrice * $ratioToBase : 0;
            $purchasePricesByUnit = json_decode((string)($item['unit_prices_json'] ?? ''), true);
            $salePricesByUnit = json_decode((string)($item['sale_prices_json'] ?? ''), true);
            if (!is_array($purchasePricesByUnit)) {
                $purchasePricesByUnit = [];
            }
            if (!is_array($salePricesByUnit)) {
                $salePricesByUnit = [];
            }

            $defaultUnit = product_default_unit((int)$item['product_id'], $prod['unit']);
            $defaultUnitCode = (string)($defaultUnit['unit_code'] ?? $prod['unit']);
            if (!isset($purchasePricesByUnit[$item['unit']])) {
                $purchasePricesByUnit[$item['unit']] = $costPrice;
            }
            if ($salePrice > 0 && !isset($salePricesByUnit[$item['unit']])) {
                $salePricesByUnit[$item['unit']] = $salePrice;
            }

            $unitPriceRows = [];
            foreach ($rowUnits as $rowUnit) {
                $unitCode = (string)$rowUnit['unit_code'];
                $unitPriceRows[] = [
                    'purchase' => $purchasePricesByUnit[$unitCode] ?? '',
                    'retail' => $salePricesByUnit[$unitCode] ?? '',
                ];
            }

            $defaultPurchasePrice = isset($purchasePricesByUnit[$defaultUnitCode])
                ? (float)$purchasePricesByUnit[$defaultUnitCode]
                : $costPrice;
            $defaultSalePrice = isset($salePricesByUnit[$defaultUnitCode])
                ? (float)$salePricesByUnit[$defaultUnitCode]
                : ($salePrice > 0 ? $salePrice : (float)$prod['sale_price']);

            // ── Обновляем stock_balances (по-складской учёт) ──
            $qtyBefore = InventoryService::getAvailableStock((int)$item['product_id'], $warehouseId, true);
            $qtyAfter = InventoryService::restoreStock((int)$item['product_id'], $warehouseId, $acceptedQtyBase);

            // ── Пересчитываем products.stock_qty как сумму по всем складам ──
            $totalQty = InventoryService::syncLegacyProductStockQty((int)$item['product_id']);

            // Обновляем цены и суммарный остаток
            save_product_unit_prices(
                (int)$item['product_id'],
                $rowUnits,
                $unitPriceRows,
                [
                    'purchase' => $defaultPurchasePrice,
                    'retail' => $defaultSalePrice,
                ]
            );

            $updateSale   = $salePrice > 0 ? 'sale_price=?,' : '';
            $updateParams = $salePrice > 0
                ? [$totalQty, $defaultPurchasePrice, $defaultSalePrice, $item['product_id']]
                : [$totalQty, $defaultPurchasePrice, $item['product_id']];

            Database::exec(
                "UPDATE products
                 SET stock_qty=?, cost_price=?, {$updateSale} is_active=1, updated_at=NOW()
                 WHERE id=?",
                $updateParams
            );
            UISettings::saveProductPrices(
                (int)$item['product_id'],
                [
                    'purchase' => $defaultPurchasePrice,
                    'retail' => $salePrice > 0 ? $defaultSalePrice : (float)$prod['sale_price'],
                ]
            );

            // Аудит: inventory_movements
            Database::exec(
                "INSERT INTO inventory_movements
                   (product_id, warehouse_id, user_id, type,
                    qty_change, qty_before, qty_after, unit_cost,
                    reference_id, reference_type, notes)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)",
                [
                    $item['product_id'], $warehouseId, Auth::id(),
                    'receipt',
                    $acceptedQtyBase, $qtyBefore, $qtyAfter,
                    $costPriceBase,
                    $id, 'receipt_accept',
                    'Acceptance of GR#' . $doc['doc_no'],
                ]
            );
        }

        // Помечаем документ как принятый
        Database::exec(
            "UPDATE goods_receipts
             SET status='accepted',
                 accepted_at=NOW(),
                 accepted_by_user=?,
                 updated_at=NOW()
             WHERE id=?",
            [Auth::id(), $id]
        );

        Database::commit();
        flash_success(_r('acc_accepted_ok'));
        redirect('/modules/acceptance/view.php?id='.$id);

    } else {
        // Просто сохранили без проведения
        Database::commit();
        flash_success(_r('acc_saved_ok'));
        redirect('/modules/acceptance/view.php?id='.$id);
    }

} catch (Throwable $e) {
    Database::rollback();
    error_log($e->__toString());
    flash_error(_r('err_db'));
    redirect('/modules/acceptance/view.php?id='.$id);
}
