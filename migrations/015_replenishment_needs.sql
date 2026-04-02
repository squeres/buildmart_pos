ALTER TABLE `products`
  ADD COLUMN `replenishment_class` ENUM('A','B','C') NOT NULL DEFAULT 'C' AFTER `min_stock_display_unit_code`,
  ADD COLUMN `target_stock_qty` DECIMAL(14,6) NOT NULL DEFAULT 0.000000 AFTER `replenishment_class`,
  ADD COLUMN `target_stock_display_unit_code` VARCHAR(64) DEFAULT NULL AFTER `target_stock_qty`,
  ADD KEY `idx_products_replenishment_class` (`replenishment_class`);

UPDATE `products`
SET `target_stock_display_unit_code` = `min_stock_display_unit_code`
WHERE `target_stock_display_unit_code` IS NULL;
