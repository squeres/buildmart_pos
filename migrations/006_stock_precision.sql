-- Increase stock precision so weighted sales can be stored correctly

ALTER TABLE `products`
  MODIFY `stock_qty` DECIMAL(14,6) NOT NULL DEFAULT 0.000000,
  MODIFY `min_stock_qty` DECIMAL(14,6) NOT NULL DEFAULT 0.000000;

ALTER TABLE `stock_balances`
  MODIFY `qty` DECIMAL(14,6) NOT NULL DEFAULT 0.000000;

ALTER TABLE `inventory_movements`
  MODIFY `qty_change` DECIMAL(14,6) NOT NULL,
  MODIFY `qty_before` DECIMAL(14,6) NOT NULL,
  MODIFY `qty_after` DECIMAL(14,6) NOT NULL;
