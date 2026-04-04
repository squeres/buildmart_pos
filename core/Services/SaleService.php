<?php
declare(strict_types=1);

/**
 * Sales and stock mutation workflow service.
 */
final class SaleService
{
    /**
     * Process a POS checkout and return created sale metadata.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public static function processPosCheckout(array $body, int $userId, int $sessionWarehouseId): array
    {
        $cartItems = is_array($body['cart'] ?? null) ? $body['cart'] : [];
        if ($cartItems === []) {
            throw new AppServiceException(_r('pos_cart_empty'), 'validation_error');
        }

        $customerId = (int)($body['customer_id'] ?? 1) ?: 1;
        $payMethod = in_array($body['payment_method'] ?? '', ['cash', 'card', 'transfer', 'mixed'], true)
            ? (string)$body['payment_method']
            : 'cash';
        $cashGiven = max(0, (float)($body['cash_given'] ?? 0));
        $cardAmount = max(0, (float)($body['card_amount'] ?? 0));
        $notes = sanitize((string)($body['notes'] ?? ''));
        $warehouseId = (int)($body['warehouse_id'] ?? 0);
        if ($warehouseId !== $sessionWarehouseId) {
            $warehouseId = $sessionWarehouseId;
        }

        $receiptDiscountType = ($body['receipt_discount_type'] ?? 'percent') === 'amount' ? 'amount' : 'percent';
        $receiptDiscountValue = max(0, (float)($body['receipt_discount_value'] ?? 0));
        $applyReceiptToDiscounted = !empty($body['apply_receipt_to_discounted_items']);

        $customer = Database::row(
            'SELECT id FROM customers WHERE id = ? LIMIT 1',
            [$customerId]
        );
        if (!$customer) {
            $customerId = 1;
        }

        $openShift = ShiftService::requireShiftForSale($userId);
        $roundMoney = static fn($value): float => round((float)$value, 2);
        $clampPercent = static fn($value): float => min(100.0, max(0.0, (float)$value));
        $posPriceType = UISettings::defaultPriceType('pos');
        $allowNegativeStock = allow_negative_stock();

        try {
            return Database::transaction(function () use (
                $cartItems,
                $customerId,
                $payMethod,
                $cashGiven,
                $cardAmount,
                $notes,
                $warehouseId,
            $receiptDiscountType,
            $receiptDiscountValue,
            $applyReceiptToDiscounted,
            $openShift,
            $userId,
            $allowNegativeStock,
                $roundMoney,
            $clampPercent,
            $posPriceType
        ): array {
                $openShiftId = is_array($openShift) ? (int)($openShift['id'] ?? 0) : 0;
                $lines = [];
                $requiredByProduct = [];
                $productMetaById = [];

                foreach ($cartItems as $item) {
                    $pid = (int)($item['id'] ?? 0);
                    $qty = (float)($item['qty'] ?? 0);
                    if ($pid <= 0 || $qty <= 0) {
                        continue;
                    }

                    $product = Database::row(
                        "SELECT id, sku, unit, tax_rate, allow_discount, is_active, name_en, name_ru
                         FROM products
                         WHERE id = ? AND is_active = 1",
                        [$pid]
                    );
                    if (!$product) {
                        throw new AppServiceException(_r('prod_not_found'), 'product_not_found');
                    }

                    $units = product_units($pid, (string)$product['unit']);
                    $unitMap = [];
                    foreach ($units as $unitRow) {
                        $unitMap[(string)$unitRow['unit_code']] = $unitRow;
                    }
                    $saleUnitCode = (string)($item['unit'] ?? $product['unit']);
                    $saleUnit = $unitMap[$saleUnitCode] ?? ($unitMap[(string)$product['unit']] ?? null);
                    if (!$saleUnit) {
                        throw new AppServiceException(_r('err_validation'), 'validation_error');
                    }

                    $effectivePrice = UISettings::effectivePrice($pid, $posPriceType);
                    if ($effectivePrice <= 0) {
                        throw new AppServiceException(_r('prod_not_found'), 'product_not_found');
                    }

                    $unitOverrides = product_unit_price_overrides($pid);
                    $unitPrice = $roundMoney(product_unit_price(
                        $pid,
                        $saleUnitCode,
                        $posPriceType,
                        $effectivePrice,
                        $units,
                        $unitOverrides
                    ));
                    $taxRate = max(0, (float)($product['tax_rate'] ?? 0));
                    $allowDiscount = (int)($product['allow_discount'] ?? 0) === 1;
                    $lineDiscountType = ($item['line_discount_type'] ?? 'none') === 'amount'
                        ? 'amount'
                        : (($item['line_discount_type'] ?? 'none') === 'percent' ? 'percent' : 'none');
                    $lineDiscountValue = max(0, (float)($item['line_discount_value'] ?? 0));

                    $ratioToBase = max(1.0, (float)$saleUnit['ratio_to_base']);
                    $qtyBase = stock_qty_round($qty / $ratioToBase);

                    $subtotal = $roundMoney($unitPrice * $qty);
                    $lineDiscountAmount = 0.0;
                    if ($allowDiscount && $subtotal > 0) {
                        if ($lineDiscountType === 'percent') {
                            $lineDiscountAmount = $roundMoney($subtotal * $clampPercent($lineDiscountValue) / 100);
                        } elseif ($lineDiscountType === 'amount') {
                            $lineDiscountAmount = $roundMoney(min($subtotal, $lineDiscountValue));
                        }
                    }

                    $discountedSubtotal = $roundMoney(max(0, $subtotal - $lineDiscountAmount));
                    $eligibleForReceipt = $allowDiscount && ($applyReceiptToDiscounted || $lineDiscountAmount <= 0);

                    $lines[] = [
                        'product_id' => $pid,
                        'product_name' => product_name($product),
                        'product_sku' => (string)$product['sku'],
                        'unit' => $saleUnitCode,
                        'qty' => $qty,
                        'qty_base' => $qtyBase,
                        'unit_price' => $unitPrice,
                        'tax_rate' => $taxRate,
                        'allow_discount' => $allowDiscount,
                        'subtotal' => $subtotal,
                        'line_discount_type' => $lineDiscountType,
                        'line_discount_value' => $lineDiscountValue,
                        'line_discount_amount' => $lineDiscountAmount,
                        'discounted_subtotal' => $discountedSubtotal,
                        'eligible_for_receipt_discount' => $eligibleForReceipt,
                        'receipt_discount_amount' => 0.0,
                    ];

                    $requiredByProduct[$pid] = stock_qty_round(($requiredByProduct[$pid] ?? 0.0) + $qtyBase);
                    $productMetaById[$pid] = [
                        'name' => product_name($product),
                        'unit' => (string)$product['unit'],
                    ];
                }

                if ($lines === []) {
                    throw new AppServiceException(_r('pos_cart_empty'), 'validation_error');
                }

                $productIds = array_keys($requiredByProduct);
                sort($productIds);
                foreach ($productIds as $productId) {
                    $requiredQty = (float)$requiredByProduct[$productId];
                    $availableQty = InventoryService::getAvailableStock($productId, $warehouseId, true);
                    if (!$allowNegativeStock && $availableQty + 0.000001 < $requiredQty) {
                        $meta = $productMetaById[$productId] ?? ['name' => '', 'unit' => ''];
                        throw new AppServiceException(
                            _r('pos_insufficient_stock', [
                                'product' => $meta['name'],
                                'available' => qty_display($availableQty, (string)$meta['unit']),
                            ]),
                            'insufficient_stock'
                        );
                    }
                }

                $receiptBase = $roundMoney(array_reduce($lines, static function ($sum, $line) {
                    return $sum + ($line['eligible_for_receipt_discount'] ? $line['discounted_subtotal'] : 0);
                }, 0.0));

                $receiptDiscountAmount = 0.0;
                if ($receiptDiscountValue > 0 && $receiptBase > 0) {
                    if ($receiptDiscountType === 'percent') {
                        $receiptDiscountAmount = $roundMoney($receiptBase * $clampPercent($receiptDiscountValue) / 100);
                    } else {
                        $receiptDiscountAmount = $roundMoney(min($receiptBase, $receiptDiscountValue));
                    }
                }

                if ($receiptDiscountAmount > 0) {
                    $eligibleIndexes = [];
                    foreach ($lines as $index => $line) {
                        if ($line['eligible_for_receipt_discount'] && $line['discounted_subtotal'] > 0) {
                            $eligibleIndexes[] = $index;
                        }
                    }

                    $allocated = 0.0;
                    $lastIndex = count($eligibleIndexes) - 1;
                    foreach ($eligibleIndexes as $position => $index) {
                        if ($position === $lastIndex) {
                            $share = $roundMoney($receiptDiscountAmount - $allocated);
                        } else {
                            $share = $roundMoney($receiptDiscountAmount * ($lines[$index]['discounted_subtotal'] / $receiptBase));
                            $allocated += $share;
                        }
                        $lines[$index]['receipt_discount_amount'] = $share;
                    }
                }

                $subtotal = 0.0;
                $discountAmt = 0.0;
                $taxAmt = 0.0;
                $total = 0.0;

                foreach ($lines as &$line) {
                    $totalDiscount = $roundMoney($line['line_discount_amount'] + $line['receipt_discount_amount']);
                    $taxableBase = $roundMoney(max(0, $line['subtotal'] - $totalDiscount));
                    $lineTaxAmount = $roundMoney($taxableBase * $line['tax_rate'] / 100);
                    $lineTotal = $roundMoney($taxableBase + $lineTaxAmount);

                    $line['discount_amount'] = $totalDiscount;
                    $line['discount_pct'] = $line['subtotal'] > 0 ? $roundMoney(($totalDiscount / $line['subtotal']) * 100) : 0.0;
                    $line['tax_amount'] = $lineTaxAmount;
                    $line['line_total'] = $lineTotal;

                    $subtotal += $line['subtotal'];
                    $discountAmt += $totalDiscount;
                    $taxAmt += $lineTaxAmount;
                    $total += $lineTotal;
                }
                unset($line);

                $subtotal = $roundMoney($subtotal);
                $discountAmt = $roundMoney($discountAmt);
                $taxAmt = $roundMoney($taxAmt);
                $total = $roundMoney($total);

                if ($total <= 0) {
                    throw new AppServiceException(_r('pos_cart_empty'), 'validation_error');
                }
                if ($payMethod === 'mixed') {
                    if ($roundMoney($cashGiven + $cardAmount) + 0.009 < $total) {
                        throw new AppServiceException(_r('err_validation'), 'validation_error');
                    }
                } elseif ($payMethod === 'cash' && $cashGiven + 0.009 < $total) {
                    throw new AppServiceException(_r('err_validation'), 'validation_error');
                }

                $receiptNo = generate_receipt_no();
                $saleId = Database::insert(
                    "INSERT INTO sales (
                        shift_id, user_id, customer_id, receipt_no, warehouse_id,
                        subtotal, discount_amount, tax_amount, total, notes, status, created_at
                     ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'completed', NOW())",
                    [
                        $openShiftId > 0 ? $openShiftId : null,
                        $userId,
                        $customerId,
                        $receiptNo,
                        $warehouseId,
                        $subtotal,
                        $discountAmt,
                        $taxAmt,
                        $total,
                        $notes,
                    ]
                );

                foreach ($lines as $line) {
                    Database::insert(
                        "INSERT INTO sale_items (
                            sale_id, product_id, product_name, product_sku, unit, qty,
                            unit_price, discount_pct, discount_amount, tax_rate, tax_amount, line_total
                         ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
                        [
                            $saleId,
                            $line['product_id'],
                            $line['product_name'],
                            $line['product_sku'],
                            $line['unit'],
                            $line['qty'],
                            $line['unit_price'],
                            $line['discount_pct'],
                            $line['discount_amount'],
                            $line['tax_rate'],
                            $line['tax_amount'],
                            $line['line_total'],
                        ]
                    );

                    [$qtyBefore, $qtyAfter] = InventoryService::deductStock(
                        (int)$line['product_id'],
                        $warehouseId,
                        (float)$line['qty_base'],
                        $allowNegativeStock
                    );

                    Database::insert(
                        "INSERT INTO inventory_movements
                            (product_id, warehouse_id, user_id, type, qty_change, qty_before, qty_after, reference_id, reference_type, created_at)
                         VALUES (?,?,?,'sale',?,?,?,?,'sale',NOW())",
                        [$line['product_id'], $warehouseId, $userId, -$line['qty_base'], $qtyBefore, $qtyAfter, $saleId]
                    );
                }

                if ($payMethod === 'mixed') {
                    $cardApplied = $roundMoney(min($total, $cardAmount));
                    $cashApplied = $roundMoney(max(0, $total - $cardApplied));
                    $cashChange = max(0, $roundMoney($cashGiven - $cashApplied));

                    if ($cashGiven > 0) {
                        Database::insert(
                            "INSERT INTO payments (sale_id, method, amount, cash_given, change_given)
                             VALUES (?,'cash',?,?,?)",
                            [$saleId, $cashApplied, $cashGiven, $cashChange]
                        );
                    }
                    if ($cardApplied > 0) {
                        Database::insert(
                            "INSERT INTO payments (sale_id, method, amount) VALUES (?,'card',?)",
                            [$saleId, $cardApplied]
                        );
                    }
                } else {
                    $paidAmount = $total;
                    $cashValue = $payMethod === 'cash' ? $cashGiven : 0;
                    $change = $payMethod === 'cash' ? max(0, $roundMoney($cashGiven - $total)) : 0;
                    Database::insert(
                        "INSERT INTO payments (sale_id, method, amount, cash_given, change_given) VALUES (?,?,?,?,?)",
                        [$saleId, $payMethod, $paidAmount, $cashValue, $change]
                    );
                }

                if ($openShiftId > 0) {
                    Database::exec(
                        'UPDATE shifts SET total_sales = total_sales + ?, transaction_count = transaction_count + 1 WHERE id = ?',
                        [$total, $openShiftId]
                    );
                }

                if ($customerId > 1) {
                    Database::exec(
                        'UPDATE customers SET total_spent = total_spent + ?, visits = visits + 1 WHERE id = ?',
                        [$total, $customerId]
                    );
                }

                return [
                    'success' => true,
                    'sale_id' => $saleId,
                    'receipt_no' => $receiptNo,
                    'receipt_url' => url('modules/pos/receipt.php?id=' . $saleId),
                ];
            });
        } catch (AppServiceException $e) {
            throw $e;
        } catch (Throwable $e) {
            error_log($e->__toString());
            throw new AppServiceException(_r('err_db'), 'internal_error', [], $e);
        }
    }

    /**
     * Void a completed sale and restore stock.
     */
    public static function voidSale(int $saleId, int $actorUserId): void
    {
        try {
            Database::transaction(function () use ($saleId, $actorUserId): void {
                $sale = Database::row('SELECT * FROM sales WHERE id = ? FOR UPDATE', [$saleId]);
                if (!$sale || ($sale['status'] ?? '') !== 'completed') {
                    throw new AppServiceException(_r('err_not_found'), 'sale_not_found');
                }
                if (!user_can_access_warehouse((int)$sale['warehouse_id'])) {
                    throw new AppServiceException(_r('auth_no_permission'), 'auth_no_permission');
                }

                $items = Database::all('SELECT * FROM sale_items WHERE sale_id = ?', [$saleId]);
                if ($items === []) {
                    throw new AppServiceException(_r('err_not_found'), 'sale_items_missing');
                }

                foreach ($items as $item) {
                    $baseUnit = (string)(Database::value('SELECT unit FROM products WHERE id = ?', [$item['product_id']]) ?: 'pcs');
                    $unitMap = product_unit_map((int)$item['product_id'], $baseUnit);
                    $saleUnit = $unitMap[(string)$item['unit']] ?? ($unitMap[$baseUnit] ?? ['ratio_to_base' => 1]);
                    $qtyBase = stock_qty_round((float)$item['qty'] / max(1.0, (float)$saleUnit['ratio_to_base']));

                    [$before, $after] = InventoryService::restoreStock(
                        (int)$item['product_id'],
                        (int)$sale['warehouse_id'],
                        $qtyBase
                    );
                    Database::insert(
                        "INSERT INTO inventory_movements
                            (product_id, warehouse_id, user_id, type, qty_change, qty_before, qty_after, reference_id, reference_type, notes, created_at)
                         VALUES (?,?,?,'return',?,?,?,?,'sale','Voided sale',NOW())",
                        [$item['product_id'], $sale['warehouse_id'], $actorUserId, $qtyBase, $before, $after, $saleId]
                    );
                }

                Database::exec("UPDATE sales SET status = 'voided' WHERE id = ?", [$saleId]);
                if (!empty($sale['shift_id'])) {
                    Database::exec(
                        "UPDATE shifts
                         SET total_sales = GREATEST(total_sales - ?, 0),
                             transaction_count = GREATEST(transaction_count - 1, 0)
                         WHERE id = ?",
                        [(float)$sale['total'], (int)$sale['shift_id']]
                    );
                }
                if ((int)$sale['customer_id'] > 1) {
                    Database::exec(
                        "UPDATE customers
                         SET total_spent = GREATEST(total_spent - ?, 0),
                             visits = GREATEST(visits - 1, 0)
                         WHERE id = ?",
                        [(float)$sale['total'], (int)$sale['customer_id']]
                    );
                }
            });
        } catch (AppServiceException $e) {
            throw $e;
        } catch (Throwable $e) {
            error_log($e->__toString());
            throw new AppServiceException(_r('err_db'), 'internal_error', [], $e);
        }
    }
}
