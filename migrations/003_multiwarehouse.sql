-- ================================================================
-- BuildMart POS — Migration 003: Multi-Warehouse & Transfers
-- Run once after migrations 001 and 002.
-- Compatible with MySQL 5.7+ / MariaDB 10.3+
-- ================================================================

SET NAMES utf8mb4;
SET foreign_key_checks = 0;

-- ----------------------------------------------------------------
-- 1. Add `code` column to warehouses if missing
-- ----------------------------------------------------------------
SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'warehouses'
    AND COLUMN_NAME  = 'code'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `warehouses` ADD COLUMN `code` VARCHAR(20) DEFAULT NULL AFTER `id`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Update default codes
UPDATE `warehouses` SET `code` = CONCAT('WH', LPAD(`id`, 3, '0')) WHERE `code` IS NULL OR `code` = '';

-- ----------------------------------------------------------------
-- 2. Warehouse–user access (many-to-many)
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `warehouse_user_access` (
  `id`           INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  `user_id`      INT UNSIGNED      NOT NULL,
  `warehouse_id` SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_wh` (`user_id`, `warehouse_id`),
  KEY `idx_user`      (`user_id`),
  KEY `idx_warehouse` (`warehouse_id`),
  CONSTRAINT `fk_wua_user` FOREIGN KEY (`user_id`)      REFERENCES `users`      (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_wua_wh`   FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Give all existing users access to all warehouses initially
INSERT IGNORE INTO `warehouse_user_access` (`user_id`, `warehouse_id`)
  SELECT u.id, w.id FROM `users` u CROSS JOIN `warehouses` w WHERE u.is_active = 1 AND w.is_active = 1;

-- ----------------------------------------------------------------
-- 3. Add default_warehouse_id to users if missing
-- ----------------------------------------------------------------
SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'users'
    AND COLUMN_NAME  = 'default_warehouse_id'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `users` ADD COLUMN `default_warehouse_id` SMALLINT UNSIGNED DEFAULT 1 AFTER `language`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------
-- 4. Per-warehouse stock balances
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `stock_balances` (
  `product_id`    INT UNSIGNED      NOT NULL,
  `warehouse_id`  SMALLINT UNSIGNED NOT NULL,
  `qty`           DECIMAL(14,3)     NOT NULL DEFAULT 0.000,
  `updated_at`    TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`product_id`, `warehouse_id`),
  KEY `idx_warehouse` (`warehouse_id`),
  KEY `idx_product`   (`product_id`),
  CONSTRAINT `fk_sb_product`   FOREIGN KEY (`product_id`)   REFERENCES `products`   (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sb_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed stock_balances from current products.stock_qty (put all into warehouse 1)
INSERT IGNORE INTO `stock_balances` (`product_id`, `warehouse_id`, `qty`)
  SELECT `id`, 1, `stock_qty` FROM `products` WHERE `stock_qty` > 0;

-- ----------------------------------------------------------------
-- 5. Stock transfers (header document)
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `stock_transfers` (
  `id`               INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  `doc_no`           VARCHAR(30)       NOT NULL COMMENT 'e.g. TRF-250613-001',
  `doc_date`         DATE              NOT NULL,
  `from_warehouse_id` SMALLINT UNSIGNED NOT NULL,
  `to_warehouse_id`   SMALLINT UNSIGNED NOT NULL,
  `status`           ENUM('draft','posted','cancelled') NOT NULL DEFAULT 'draft',
  `notes`            TEXT              DEFAULT NULL,
  `created_by`       INT UNSIGNED      NOT NULL,
  `posted_by`        INT UNSIGNED      DEFAULT NULL,
  `posted_at`        DATETIME          DEFAULT NULL,
  `cancelled_by`     INT UNSIGNED      DEFAULT NULL,
  `cancelled_at`     DATETIME          DEFAULT NULL,
  `created_at`       TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_doc_no` (`doc_no`),
  KEY `idx_from_wh`   (`from_warehouse_id`),
  KEY `idx_to_wh`     (`to_warehouse_id`),
  KEY `idx_status`    (`status`),
  KEY `idx_doc_date`  (`doc_date`),
  KEY `idx_created_by`(`created_by`),
  CONSTRAINT `fk_st_from_wh`   FOREIGN KEY (`from_warehouse_id`) REFERENCES `warehouses` (`id`),
  CONSTRAINT `fk_st_to_wh`     FOREIGN KEY (`to_warehouse_id`)   REFERENCES `warehouses` (`id`),
  CONSTRAINT `fk_st_created`   FOREIGN KEY (`created_by`)        REFERENCES `users`      (`id`),
  CONSTRAINT `fk_st_posted`    FOREIGN KEY (`posted_by`)         REFERENCES `users`      (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_st_cancelled` FOREIGN KEY (`cancelled_by`)      REFERENCES `users`      (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- 6. Stock transfer items (rows)
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `stock_transfer_items` (
  `id`           INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  `transfer_id`  INT UNSIGNED      NOT NULL,
  `product_id`   INT UNSIGNED      NOT NULL,
  `product_name` VARCHAR(250)      NOT NULL COMMENT 'Snapshot at time of save',
  `unit`         VARCHAR(64)       NOT NULL DEFAULT 'pcs',
  `unit_label`   VARCHAR(120)      DEFAULT NULL,
  `qty`          DECIMAL(14,6)     NOT NULL DEFAULT 0.000000,
  `qty_base`     DECIMAL(14,6)     NOT NULL DEFAULT 0.000000,
  `sort_order`   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_transfer` (`transfer_id`),
  KEY `idx_product`  (`product_id`),
  CONSTRAINT `fk_sti_transfer` FOREIGN KEY (`transfer_id`) REFERENCES `stock_transfers`  (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sti_product`  FOREIGN KEY (`product_id`)  REFERENCES `products`         (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- 7. Add warehouse_id FK to inventory_movements (safe — already added in 001, but ensure FK)
-- ----------------------------------------------------------------
SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'inventory_movements'
    AND COLUMN_NAME  = 'warehouse_id'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `inventory_movements` ADD COLUMN `warehouse_id` SMALLINT UNSIGNED DEFAULT NULL AFTER `product_id`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add FK if not present
SET @fk_exists = (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'inventory_movements'
    AND CONSTRAINT_NAME = 'fk_inv_warehouse'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql = IF(@fk_exists = 0,
  'ALTER TABLE `inventory_movements` ADD CONSTRAINT `fk_inv_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`) ON DELETE SET NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Backfill warehouse_id = 1 for existing movements
UPDATE `inventory_movements` SET `warehouse_id` = 1 WHERE `warehouse_id` IS NULL;

-- ----------------------------------------------------------------
-- 8. Add transfers permission to manager role
-- ----------------------------------------------------------------
UPDATE `roles`
SET `permissions` = JSON_SET(`permissions`, '$.transfers', true)
WHERE `slug` IN ('admin', 'manager');

-- Add to admin's all-perm
UPDATE `roles`
SET `permissions` = '{"all":true}'
WHERE `slug` = 'admin';

SET foreign_key_checks = 1;
