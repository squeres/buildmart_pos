<?php
declare(strict_types=1);

/**
 * Central stock source of truth based on stock_balances.
 */
final class InventoryService
{
    /**
     * Get available stock in a specific warehouse.
     */
    public static function getAvailableStock(int $productId, int $warehouseId, bool $forUpdate = false): float
    {
        if ($productId <= 0 || $warehouseId <= 0) {
            return 0.0;
        }

        $sql = 'SELECT qty FROM stock_balances WHERE product_id = ? AND warehouse_id = ?'
            . ($forUpdate ? ' FOR UPDATE' : '');
        $row = Database::row($sql, [$productId, $warehouseId]);

        return $row ? stock_qty_round((float)$row['qty']) : 0.0;
    }

    /**
     * Get total stock across all warehouses or a provided subset.
     *
     * @param int[]|null $warehouseIds
     */
    public static function getTotalStock(int $productId, ?array $warehouseIds = null): float
    {
        if ($productId <= 0) {
            return 0.0;
        }

        if ($warehouseIds === null || $warehouseIds === []) {
            $qty = Database::value(
                'SELECT COALESCE(SUM(qty), 0) FROM stock_balances WHERE product_id = ?',
                [$productId]
            );
            return stock_qty_round((float)$qty);
        }

        $warehouseIds = array_values(array_filter(array_map('intval', $warehouseIds), static fn (int $id): bool => $id > 0));
        if ($warehouseIds === []) {
            return 0.0;
        }

        $placeholders = implode(',', array_fill(0, count($warehouseIds), '?'));
        $qty = Database::value(
            "SELECT COALESCE(SUM(qty), 0)
             FROM stock_balances
             WHERE product_id = ?
               AND warehouse_id IN ($placeholders)",
            array_merge([$productId], $warehouseIds)
        );

        return stock_qty_round((float)$qty);
    }

    /**
     * Lock and validate stock without changing it.
     */
    public static function reserveStock(int $productId, int $warehouseId, float $qty): float
    {
        $qty = stock_qty_round(max(0.0, $qty));
        $available = self::getAvailableStock($productId, $warehouseId, true);
        if ($qty > $available + 0.000001) {
            throw new AppServiceException(__('err_validation'), 'insufficient_stock');
        }

        return $available;
    }

    /**
     * Deduct stock from the source warehouse.
     *
     * @return array{0:float,1:float}
     */
    public static function deductStock(int $productId, int $warehouseId, float $qty): array
    {
        $qty = stock_qty_round(max(0.0, $qty));
        $qtyBefore = self::getAvailableStock($productId, $warehouseId, true);
        if ($qty <= 0) {
            return [$qtyBefore, $qtyBefore];
        }

        $qtyAfter = stock_qty_round($qtyBefore - $qty);
        if ($qtyAfter < -0.000001) {
            throw new AppServiceException(__('err_validation'), 'insufficient_stock');
        }

        self::persistWarehouseBalance($productId, $warehouseId, max(0.0, $qtyAfter));
        self::syncLegacyProductStockQty($productId);

        return [$qtyBefore, max(0.0, $qtyAfter)];
    }

    /**
     * Increase stock in the target warehouse.
     *
     * @return array{0:float,1:float}
     */
    public static function restoreStock(int $productId, int $warehouseId, float $qty): array
    {
        $qty = stock_qty_round(max(0.0, $qty));
        $qtyBefore = self::getAvailableStock($productId, $warehouseId, true);
        $qtyAfter = stock_qty_round($qtyBefore + $qty);

        self::persistWarehouseBalance($productId, $warehouseId, $qtyAfter);
        self::syncLegacyProductStockQty($productId);

        return [$qtyBefore, $qtyAfter];
    }

    /**
     * Set an exact balance in a warehouse.
     *
     * @return array{0:float,1:float}
     */
    public static function setStock(int $productId, int $warehouseId, float $qty): array
    {
        $qtyBefore = self::getAvailableStock($productId, $warehouseId, true);
        $qtyAfter = stock_qty_round(max(0.0, $qty));

        self::persistWarehouseBalance($productId, $warehouseId, $qtyAfter);
        self::syncLegacyProductStockQty($productId);

        return [$qtyBefore, $qtyAfter];
    }

    /**
     * Keep deprecated products.stock_qty in sync as a legacy cache only.
     */
    public static function syncLegacyProductStockQty(int $productId): float
    {
        $total = self::getTotalStock($productId);
        Database::exec(
            'UPDATE products SET stock_qty = ? WHERE id = ?',
            [$total, $productId]
        );

        return $total;
    }

    private static function persistWarehouseBalance(int $productId, int $warehouseId, float $qty): void
    {
        $existing = Database::row(
            'SELECT qty FROM stock_balances WHERE product_id = ? AND warehouse_id = ? FOR UPDATE',
            [$productId, $warehouseId]
        );

        if ($existing) {
            Database::exec(
                'UPDATE stock_balances SET qty = ? WHERE product_id = ? AND warehouse_id = ?',
                [$qty, $productId, $warehouseId]
            );
            return;
        }

        Database::exec(
            'INSERT INTO stock_balances (product_id, warehouse_id, qty) VALUES (?, ?, ?)',
            [$productId, $warehouseId, $qty]
        );
    }
}
