-- ================================================================
-- BuildMart POS — Goods Receipt Module Migration
-- Run once. Compatible with MySQL 5.7+ / MariaDB 10.3+
-- ================================================================

SET NAMES utf8mb4;

-- ----------------------------------------------------------------
-- warehouses
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `warehouses` (
  `id`         SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(120)      NOT NULL,
  `address`    VARCHAR(255)      DEFAULT NULL,
  `notes`      TEXT              DEFAULT NULL,
  `is_active`  TINYINT(1)        NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `warehouses` (`id`, `name`, `address`) VALUES
(1, 'Основной склад',     'г. Москва, ул. Строителей, 1'),
(2, 'Склад №2 (запасной)', NULL);

-- ----------------------------------------------------------------
-- suppliers
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `suppliers` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(200)  NOT NULL,
  `contact`     VARCHAR(120)  DEFAULT NULL COMMENT 'Contact person name',
  `phone`       VARCHAR(40)   DEFAULT NULL,
  `email`       VARCHAR(150)  DEFAULT NULL,
  `address`     VARCHAR(255)  DEFAULT NULL,
  `inn`         VARCHAR(30)   DEFAULT NULL COMMENT 'Tax ID / BIN / IIN',
  `bank_details`TEXT          DEFAULT NULL,
  `notes`       TEXT          DEFAULT NULL,
  `is_active`   TINYINT(1)    NOT NULL DEFAULT 1,
  `created_at`  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_name`   (`name`(50)),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `suppliers` (`id`, `name`, `phone`, `inn`) VALUES
(1, 'Holcim Россия',       '+7 (800) 555-01-01', '7710000001'),
(2, 'Knauf Инсулейшн',    '+7 (800) 555-02-02', '7710000002'),
(3, 'Ярославские краски',  '+7 (485) 555-03-03', '7610000003');

-- ----------------------------------------------------------------
-- goods_receipts  (header)
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `goods_receipts` (
  `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `doc_no`        VARCHAR(30)   NOT NULL COMMENT 'Human-readable document number (GR-250101-001)',
  `doc_date`      DATE          NOT NULL,
  `supplier_id`   INT UNSIGNED  DEFAULT NULL,
  `warehouse_id`  SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  `status`        ENUM('draft','posted','cancelled') NOT NULL DEFAULT 'draft',
  -- Parties
  `accepted_by`   VARCHAR(120)  DEFAULT NULL COMMENT 'Name of person who accepted delivery',
  `delivered_by`  VARCHAR(120)  DEFAULT NULL COMMENT 'Driver / courier name',
  `supplier_doc_no` VARCHAR(80) DEFAULT NULL COMMENT 'Supplier invoice / waybill number',
  -- Financials (server-calculated)
  `subtotal`      DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `tax_amount`    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `total`         DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  -- Meta
  `notes`         TEXT          DEFAULT NULL,
  `created_by`    INT UNSIGNED  NOT NULL,
  `posted_by`     INT UNSIGNED  DEFAULT NULL,
  `posted_at`     DATETIME      DEFAULT NULL,
  `cancelled_by`  INT UNSIGNED  DEFAULT NULL,
  `cancelled_at`  DATETIME      DEFAULT NULL,
  `created_at`    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_doc_no`      (`doc_no`),
  KEY        `idx_supplier`    (`supplier_id`),
  KEY        `idx_warehouse`   (`warehouse_id`),
  KEY        `idx_status`      (`status`),
  KEY        `idx_doc_date`    (`doc_date`),
  KEY        `idx_created_by`  (`created_by`),
  CONSTRAINT `fk_gr_supplier`  FOREIGN KEY (`supplier_id`)  REFERENCES `suppliers`  (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_gr_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`),
  CONSTRAINT `fk_gr_created`   FOREIGN KEY (`created_by`)   REFERENCES `users`      (`id`),
  CONSTRAINT `fk_gr_posted`    FOREIGN KEY (`posted_by`)    REFERENCES `users`      (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_gr_cancelled` FOREIGN KEY (`cancelled_by`) REFERENCES `users`      (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- goods_receipt_items  (rows)
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `goods_receipt_items` (
  `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `receipt_id`  INT UNSIGNED  NOT NULL,
  `product_id`  INT UNSIGNED  DEFAULT NULL COMMENT 'NULL = custom/non-catalogue item',
  `name`        VARCHAR(250)  NOT NULL COMMENT 'Snapshot of product name at time of save',
  `unit`        VARCHAR(20)   NOT NULL DEFAULT 'pcs',
  `qty`         DECIMAL(14,3) NOT NULL DEFAULT 1.000,
  `unit_price`  DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `tax_rate`    DECIMAL(5,2)  NOT NULL DEFAULT 0.00 COMMENT 'VAT % per line',
  `line_total`  DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT 'qty * unit_price (excl VAT)',
  `notes`       VARCHAR(255)  DEFAULT NULL,
  `sort_order`  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_receipt`  (`receipt_id`),
  KEY `idx_product`  (`product_id`),
  CONSTRAINT `fk_gri_receipt`  FOREIGN KEY (`receipt_id`) REFERENCES `goods_receipts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_gri_product`  FOREIGN KEY (`product_id`) REFERENCES `products`        (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- New settings for goods receipt document template
-- ----------------------------------------------------------------
INSERT IGNORE INTO `settings` (`key`, `value`, `label`, `group`, `type`) VALUES
('gr_org_name',          'ООО «BuildMart»',                  'Organization Name',        'gr_template', 'text'),
('gr_org_inn',           '7700000000',                       'INN / BIN / Tax Number',   'gr_template', 'text'),
('gr_org_address',       'г. Москва, ул. Строителей, 1',    'Organization Address',     'gr_template', 'textarea'),
('gr_doc_title',         'ТОВАРНАЯ НАКЛАДНАЯ',               'Document Title',           'gr_template', 'text'),
('gr_header_note',       '',                                 'Header Note',              'gr_template', 'textarea'),
('gr_footer_note',       'Товар получен в полном объёме, претензий нет.', 'Footer Note', 'gr_template', 'textarea'),
('gr_label_warehouse',   'Склад',                            'Warehouse Label',          'gr_template', 'text'),
('gr_label_supplier',    'Поставщик',                        'Supplier Label',           'gr_template', 'text'),
('gr_label_accepted_by', 'Принял',                           'Accepted By Label',        'gr_template', 'text'),
('gr_label_delivered_by','Сдал',                             'Delivered By Label',       'gr_template', 'text');

-- ----------------------------------------------------------------
-- Add warehouse_id column to inventory_movements if missing
-- (safe: uses IF NOT EXISTS logic via ALTER IGNORE)
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
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
