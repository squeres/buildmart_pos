<?php
require_once __DIR__ . '/../../core/bootstrap.php';
Auth::requireLogin();
Auth::requirePerm('pos');

if (!is_ajax() || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'message' => 'Bad request'], 400);
}

$body = json_decode(file_get_contents('php://input'), true);
if (!$body) {
    json_response(['success' => false, 'message' => _r('err_validation')]);
}

$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!hash_equals(csrf_token(), $csrfToken)) {
    json_response(['success' => false, 'message' => _r('err_csrf')], 403);
}

$cartItems = is_array($body['cart'] ?? null) ? $body['cart'] : [];
$customerId = (int)($body['customer_id'] ?? 1) ?: 1;
$payMethod = in_array($body['payment_method'] ?? '', ['cash', 'card', 'transfer', 'mixed'], true)
    ? $body['payment_method']
    : 'cash';
$cashGiven = max(0, (float)($body['cash_given'] ?? 0));
$cardAmount = max(0, (float)($body['card_amount'] ?? 0));
$notes = sanitize($body['notes'] ?? '');
$warehouseId = (int)($body['warehouse_id'] ?? 0);
$receiptDiscountType = ($body['receipt_discount_type'] ?? 'percent') === 'amount' ? 'amount' : 'percent';
$receiptDiscountValue = max(0, (float)($body['receipt_discount_value'] ?? 0));
$applyReceiptToDiscounted = !empty($body['apply_receipt_to_discounted_items']);

$sessionWhId = pos_warehouse_id();
if ($warehouseId !== $sessionWhId) {
    $warehouseId = $sessionWhId;
}

if (empty($cartItems)) {
    json_response(['success' => false, 'message' => _r('pos_cart_empty')]);
}

$customer = Database::row(
    "SELECT id
     FROM customers
     WHERE id = ?
     LIMIT 1",
    [$customerId]
);
if (!$customer) {
    $customerId = 1;
    $customer = Database::row(
        "SELECT id
         FROM customers
         WHERE id = 1
         LIMIT 1"
    );
}

$requireShift = setting('shifts_required', '1') === '1';
$openShift = Database::row("SELECT * FROM shifts WHERE user_id=? AND status='open' LIMIT 1", [Auth::id()]);
if ($requireShift && !$openShift) {
    json_response([
        'success' => false,
        'message' => _r('pos_no_shift'),
        'code' => 'shift_not_open',
    ], 409);
}

if ($openShift) {
    $shiftSaleState = shift_can_sell_now($openShift);
    if (empty($shiftSaleState['ok'])) {
        $response = [
            'success' => false,
            'message' => $shiftSaleState['message'],
            'code' => $shiftSaleState['code'] ?? 'shift_sales_blocked',
            'close_url' => url('modules/shifts/close.php?id=' . (int)$openShift['id']),
        ];
        if (!empty($shiftSaleState['allowed_until']) && $shiftSaleState['allowed_until'] instanceof DateTimeImmutable) {
            $response['allowed_until_label'] = date_fmt($shiftSaleState['allowed_until']->format('Y-m-d H:i:s'));
        }
        if (!empty($shiftSaleState['request_options'])) {
            $response['request_options'] = array_values($shiftSaleState['request_options']);
        }
        if (isset($shiftSaleState['remaining_minutes'])) {
            $response['remaining_minutes'] = (int)$shiftSaleState['remaining_minutes'];
        }
        json_response($response, 409);
    }
}

$roundMoney = static fn($value): float => round((float)$value, 2);
$clampPercent = static fn($value): float => min(100.0, max(0.0, (float)$value));
$posPriceType = UISettings::defaultPriceType('pos');

try {
    Database::beginTransaction();

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
             WHERE id=? AND is_active=1",
            [$pid]
        );
        if (!$product) {
            throw new Exception(_r('prod_not_found'));
        }

        $units = product_units($pid, $product['unit']);
        $unitMap = [];
        foreach ($units as $unitRow) {
            $unitMap[(string)$unitRow['unit_code']] = $unitRow;
        }
        $saleUnitCode = (string)($item['unit'] ?? $product['unit']);
        $saleUnit = $unitMap[$saleUnitCode] ?? ($unitMap[$product['unit']] ?? null);
        if (!$saleUnit) {
            throw new Exception(_r('err_validation'));
        }

        $effectivePrice = UISettings::effectivePrice($pid, $posPriceType);
        if ($effectivePrice <= 0) {
            throw new Exception(_r('prod_not_found'));
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
            'unit' => $product['unit'],
        ];
    }

    if (empty($lines)) {
        throw new Exception(_r('pos_cart_empty'));
    }

    $productIds = array_keys($requiredByProduct);
    sort($productIds);
    foreach ($productIds as $productId) {
        $balance = Database::row(
            "SELECT qty FROM stock_balances WHERE product_id=? AND warehouse_id=? FOR UPDATE",
            [$productId, $warehouseId]
        );
        $availableQty = $balance ? (float)$balance['qty'] : 0.0;
        $requiredQty = (float)$requiredByProduct[$productId];
        if ($availableQty + 0.000001 < $requiredQty) {
            $meta = $productMetaById[$productId] ?? ['name' => '', 'unit' => ''];
            throw new Exception(_r('pos_insufficient_stock', [
                'product' => $meta['name'],
                'available' => qty_display($availableQty, (string)$meta['unit']),
            ]));
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
        throw new Exception(_r('pos_cart_empty'));
    }

    if ($payMethod === 'mixed') {
        if ($roundMoney($cashGiven + $cardAmount) + 0.009 < $total) {
            throw new Exception(_r('err_validation'));
        }
    } elseif ($payMethod === 'cash' && $cashGiven + 0.009 < $total) {
        throw new Exception(_r('err_validation'));
    }

    $receiptNo = generate_receipt_no();
    $saleId = Database::insert(
        "INSERT INTO sales (
            shift_id, user_id, customer_id, receipt_no, warehouse_id,
            subtotal, discount_amount, tax_amount, total, notes, status, created_at
         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'completed', NOW())",
        [
            $openShift['id'] ?? null,
            Auth::id(),
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
            "INSERT INTO sale_items (sale_id,product_id,product_name,product_sku,unit,qty,unit_price,discount_pct,discount_amount,tax_rate,tax_amount,line_total)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
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

        [$qtyBefore, $qtyAfter] = update_stock_balance($line['product_id'], $warehouseId, -$line['qty_base']);

        Database::insert(
            "INSERT INTO inventory_movements
                (product_id, warehouse_id, user_id, type, qty_change, qty_before, qty_after, reference_id, reference_type, created_at)
             VALUES (?,?,?,'sale',?,?,?,?,'sale',NOW())",
            [$line['product_id'], $warehouseId, Auth::id(), -$line['qty_base'], $qtyBefore, $qtyAfter, $saleId]
        );
    }

    if ($payMethod === 'mixed') {
        $cardApplied = $roundMoney(min($total, $cardAmount));
        $cashApplied = $roundMoney(max(0, $total - $cardApplied));
        $cashChange = max(0, $roundMoney($cashGiven - $cashApplied));

        if ($cashGiven > 0) {
            Database::insert(
                "INSERT INTO payments (sale_id,method,amount,cash_given,change_given) VALUES (?,'cash',?,?,?)",
                [$saleId, $cashApplied, $cashGiven, $cashChange]
            );
        }
        if ($cardApplied > 0) {
            Database::insert(
                "INSERT INTO payments (sale_id,method,amount) VALUES (?,'card',?)",
                [$saleId, $cardApplied]
            );
        }
    } else {
        $paidAmount = $total;
        $cashValue = $payMethod === 'cash' ? $cashGiven : 0;
        $change = $payMethod === 'cash' ? max(0, $roundMoney($cashGiven - $total)) : 0;
        Database::insert(
            "INSERT INTO payments (sale_id,method,amount,cash_given,change_given) VALUES (?,?,?,?,?)",
            [$saleId, $payMethod, $paidAmount, $cashValue, $change]
        );
    }

    if ($openShift) {
        Database::exec(
            "UPDATE shifts SET total_sales=total_sales+?, transaction_count=transaction_count+1 WHERE id=?",
            [$total, $openShift['id']]
        );
    }

    if ($customerId > 1) {
        Database::exec(
            "UPDATE customers SET total_spent=total_spent+?, visits=visits+1 WHERE id=?",
            [$total, $customerId]
        );
    }

    Database::commit();

    json_response([
        'success' => true,
        'sale_id' => $saleId,
        'receipt_no' => $receiptNo,
        'receipt_url' => url('modules/pos/receipt.php?id=' . $saleId),
    ]);
} catch (Throwable $e) {
    Database::rollback();
    json_response(['success' => false, 'message' => $e->getMessage()]);
}
