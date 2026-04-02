-- ================================================================
-- BuildMart POS — Migration 004: warehouse_id in sales table
-- Run after migration 003.
-- ================================================================

SET NAMES utf8mb4;

-- Add warehouse_id to sales if missing
SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'sales'
    AND COLUMN_NAME  = 'warehouse_id'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `sales` ADD COLUMN `warehouse_id` SMALLINT UNSIGNED DEFAULT 1 AFTER `user_id`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Backfill existing sales to warehouse 1
UPDATE `sales` SET `warehouse_id` = 1 WHERE `warehouse_id` IS NULL;

-- Add FK
SET @fk_exists = (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'sales'
    AND CONSTRAINT_NAME = 'fk_sales_warehouse'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql = IF(@fk_exists = 0,
  'ALTER TABLE `sales` ADD CONSTRAINT `fk_sales_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
