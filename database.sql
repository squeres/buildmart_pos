-- ================================================================
-- BuildMart POS — Complete Database Schema
-- Construction Materials & Hardware Store Management System
-- MySQL 5.7+ / MariaDB 10.3+
-- ================================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;

CREATE DATABASE IF NOT EXISTS `buildmart_pos`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `buildmart_pos`;

-- ----------------------------------------------------------------
-- roles
-- ----------------------------------------------------------------
CREATE TABLE `roles` (
  `id`          TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug`        VARCHAR(30)      NOT NULL UNIQUE,
  `name`        VARCHAR(60)      NOT NULL,
  `permissions` JSON             NOT NULL DEFAULT ('{}'),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `roles` (`slug`, `name`, `permissions`) VALUES
('admin',   'Administrator', '{"all":true}'),
('manager', 'Manager',       '{"dashboard":true,"pos":true,"products":true,"categories":true,"inventory":true,"customers":true,"shifts":true,"sales":true,"reports":true,"returns":true}'),
('cashier', 'Cashier',       '{"dashboard":true,"pos":true,"customers":true,"shifts":true,"sales":true,"returns":true}');

-- ----------------------------------------------------------------
-- users
-- ----------------------------------------------------------------
CREATE TABLE `users` (
  `id`         INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  `role_id`    TINYINT UNSIGNED NOT NULL DEFAULT 3,
  `name`       VARCHAR(100)     NOT NULL,
  `email`      VARCHAR(150)     NOT NULL UNIQUE,
  `password`   VARCHAR(255)     NOT NULL,
  `pin`        VARCHAR(10)      DEFAULT NULL COMMENT 'Quick PIN for shift login',
  `phone`      VARCHAR(25)      DEFAULT NULL,
  `language`   CHAR(2)          NOT NULL DEFAULT 'en',
  `is_active`  TINYINT(1)       NOT NULL DEFAULT 1,
  `must_change_password` TINYINT(1) NOT NULL DEFAULT 0,
  `last_login` DATETIME         DEFAULT NULL,
  `created_at` TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_email`    (`email`),
  KEY `idx_role`     (`role_id`),
  CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Security: no default privileged users are seeded.
-- Create application users manually after import and assign strong passwords and PINs.

-- ----------------------------------------------------------------
-- categories
-- ----------------------------------------------------------------
CREATE TABLE `categories` (
  `id`         SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `parent_id`  SMALLINT UNSIGNED DEFAULT NULL,
  `name_en`    VARCHAR(100)      NOT NULL,
  `name_ru`    VARCHAR(100)      NOT NULL,
  `icon`       VARCHAR(40)       NOT NULL DEFAULT 'box',
  `color`      CHAR(7)           NOT NULL DEFAULT '#607D8B',
  `sort_order` TINYINT UNSIGNED  NOT NULL DEFAULT 0,
  `is_active`  TINYINT(1)        NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_parent` (`parent_id`),
  CONSTRAINT `fk_cat_parent` FOREIGN KEY (`parent_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `categories` (`name_en`,`name_ru`,`icon`,`color`,`sort_order`) VALUES
('Cement & Concrete', 'Цемент и бетон',    'layers',        '#78909C', 1),
('Bricks & Blocks',   'Кирпич и блоки',    'grid',          '#8D6E63', 2),
('Timber & Wood',     'Лесоматериалы',     'align-justify', '#6D4C41', 3),
('Paint & Coatings',  'Краски и покрытия', 'droplet',       '#EF5350', 4),
('Tools',             'Инструменты',       'tool',          '#FF7043', 5),
('Fasteners',         'Крепёж',            'settings',      '#546E7A', 6),
('Plumbing',          'Сантехника',        'droplets',      '#29B6F6', 7),
('Electrical',        'Электрика',         'zap',           '#FDD835', 8),
('Dry Mixes',         'Сухие смеси',       'package',       '#A5D6A7', 9),
('Insulation',        'Утеплители',        'shield',        '#80DEEA', 10),
('Roofing',           'Кровля',            'home',          '#90A4AE', 11),
('Household Goods',   'Хозтовары',         'shopping-bag',  '#CE93D8', 12);

-- ----------------------------------------------------------------
-- unit_presets
-- ----------------------------------------------------------------
CREATE TABLE `unit_presets` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `unit_code`  VARCHAR(64)  NOT NULL,
  `unit_label` VARCHAR(120) NOT NULL,
  `sort_order` INT          NOT NULL DEFAULT 0,
  `is_active`  TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_unit_presets_code` (`unit_code`),
  UNIQUE KEY `uniq_unit_presets_label` (`unit_label`),
  KEY `idx_unit_presets_active_sort` (`is_active`,`sort_order`,`unit_label`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `unit_presets` (`unit_code`,`unit_label`,`sort_order`,`is_active`) VALUES
('korobka','Коробка',10,1),
('upakovka','Упаковка',20,1),
('shtuk','Штук',30,1);

-- ----------------------------------------------------------------
-- products
-- ----------------------------------------------------------------
CREATE TABLE `products` (
  `id`             INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  `category_id`    SMALLINT UNSIGNED NOT NULL,
  `name_en`        VARCHAR(200)      NOT NULL,
  `name_ru`        VARCHAR(200)      NOT NULL DEFAULT '',
  `sku`            VARCHAR(60)       NOT NULL UNIQUE,
  `barcode`        VARCHAR(60)       DEFAULT NULL,
  `brand`          VARCHAR(80)       DEFAULT NULL,
  `description_en` TEXT              DEFAULT NULL,
  `description_ru` TEXT              DEFAULT NULL,
  `unit`           ENUM('pcs','kg','g','t','l','ml','m','m2','m3','pack','roll','bag','box','pair','set','pallet') NOT NULL DEFAULT 'pcs',
  `sale_price`     DECIMAL(14,2)     NOT NULL DEFAULT 0.00,
  `cost_price`     DECIMAL(14,2)     NOT NULL DEFAULT 0.00,
  `tax_rate`       DECIMAL(5,2)      NOT NULL DEFAULT 0.00 COMMENT 'VAT percent',
  `stock_qty`      DECIMAL(14,6)     NOT NULL DEFAULT 0.000000,
  `min_stock_qty`  DECIMAL(14,6)     NOT NULL DEFAULT 0.000000 COMMENT 'Alert threshold',
  `min_stock_display_unit_code` VARCHAR(64) DEFAULT NULL,
  `replenishment_class` ENUM('A','B','C') NOT NULL DEFAULT 'C',
  `target_stock_qty` DECIMAL(14,6) NOT NULL DEFAULT 0.000000,
  `target_stock_display_unit_code` VARCHAR(64) DEFAULT NULL,
  `image`          VARCHAR(255)      DEFAULT NULL,
  `allow_discount` TINYINT(1)        NOT NULL DEFAULT 1,
  `is_weighable`   TINYINT(1)        NOT NULL DEFAULT 0,
  `is_active`      TINYINT(1)        NOT NULL DEFAULT 1,
  `created_at`     TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_sku`     (`sku`),
  KEY       `idx_barcode`  (`barcode`),
  KEY       `idx_category` (`category_id`),
  KEY       `idx_active`   (`is_active`),
  KEY       `idx_products_replenishment_class` (`replenishment_class`),
  CONSTRAINT `fk_product_cat` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- product_units
-- ----------------------------------------------------------------
CREATE TABLE `product_units` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id`      INT UNSIGNED NOT NULL,
  `unit_code`       VARCHAR(64)  NOT NULL,
  `unit_label`      VARCHAR(120) NOT NULL,
  `ratio_to_base`   DECIMAL(14,3) NOT NULL DEFAULT 1.000,
  `sort_order`      INT          NOT NULL DEFAULT 0,
  `is_default`      TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_product_unit` (`product_id`,`unit_code`),
  KEY `idx_product_units_product` (`product_id`),
  CONSTRAINT `fk_product_units_product`
    FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- product_unit_prices
-- ----------------------------------------------------------------
CREATE TABLE `product_unit_prices` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id`      INT UNSIGNED NOT NULL,
  `unit_code`       VARCHAR(64)  NOT NULL,
  `price_type_id`   TINYINT UNSIGNED NOT NULL,
  `price`           DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_product_unit_price` (`product_id`,`unit_code`,`price_type_id`),
  KEY `idx_product_unit_prices_product` (`product_id`),
  KEY `idx_product_unit_prices_type` (`price_type_id`),
  CONSTRAINT `fk_product_unit_prices_product`
    FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_product_unit_prices_type`
    FOREIGN KEY (`price_type_id`) REFERENCES `price_types`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `goods_receipt_items`
  ADD COLUMN `unit_prices_json` LONGTEXT NULL AFTER `unit_price`,
  ADD COLUMN `sale_prices_json` LONGTEXT NULL AFTER `sale_price`;

INSERT INTO `products`
  (`category_id`,`name_en`,`name_ru`,`sku`,`barcode`,`brand`,`unit`,`sale_price`,`cost_price`,`tax_rate`,`stock_qty`,`min_stock_qty`) VALUES
-- Cement
(1,'Portland Cement M500 50kg','Цемент Портланд М500 50кг','CEM-M500-50','4600000000011','Holcim','bag',650.00,420.00,20.00,480,50),
(1,'Portland Cement M400 50kg','Цемент Портланд М400 50кг','CEM-M400-50','4600000000028','CEM Russia','bag',580.00,370.00,20.00,300,50),
(1,'Concrete Mix B25 (per m³)','Бетон B25 (м³)','CONC-B25-M3',NULL,NULL,'m3',5800.00,3800.00,20.00,50,5),
-- Bricks
(2,'Red Ceramic Brick M150','Кирпич красный М150','BRK-RED-M150','4600000000035',NULL,'pcs',25.00,16.00,20.00,15000,1000),
(2,'Silicate Brick M200','Кирпич силикатный М200','BRK-SIL-M200','4600000000042',NULL,'pcs',22.00,14.00,20.00,10000,1000),
(2,'Gas Block 600×200×300','Газоблок 600×200×300','GBLK-600-200','4600000000059','Ytong','pcs',220.00,145.00,20.00,500,50),
-- Timber
(3,'Pine Board 150×25×6000 mm','Доска сосна 150×25×6000 мм','PINE-BRD-25','4600000000066',NULL,'m',340.00,210.00,20.00,200,20),
(3,'OSB 3 Board 2440×1220×12','ОСП-3 2440×1220×12','OSB3-12','4600000000073','Kronopol','pcs',1400.00,950.00,20.00,80,10),
(3,'Plywood FK 2440×1220×12','Фанера ФК 2440×1220×12','PLY-FK-12',NULL,NULL,'pcs',1650.00,1100.00,20.00,60,10),
-- Paint
(4,'White Interior Paint 10L','Краска интерьерная белая 10л','PNT-WHT-10','4600000000080','Tikkurila','l',1250.00,820.00,20.00,120,15),
(4,'Facade Paint 10L','Краска фасадная 10л','PNT-FAC-10','4600000000097','Caparol','l',1480.00,980.00,20.00,80,10),
(4,'Floor Enamel PF-266 3kg','Эмаль ПФ-266 3кг','ENM-PF266-3','4600000000103','Yaroslavl Paints','kg',450.00,290.00,20.00,50,5),
-- Tools
(5,'Hammer 500g','Молоток 500г','TLS-HAM-500','4600000000110','STAYER','pcs',580.00,380.00,20.00,40,5),
(5,'Hand Saw 500mm','Ножовка 500мм','TLS-SAW-500','4600000000127','ЗУБР','pcs',850.00,550.00,20.00,25,3),
(5,'Tape Measure 5m','Рулетка 5м','TLS-TAPE-5','4600000000134','Stanley','pcs',480.00,310.00,20.00,60,10),
(5,'Drill Bit Set 10pc','Набор свёрл 10пр','TLS-DRL-10','4600000000141','Bosch','set',1200.00,790.00,20.00,30,5),
-- Fasteners
(6,'Wood Screw 4×50 (200pc)','Саморез 4×50 (200шт)','FST-SCR-4X50','4600000000158',NULL,'pack',120.00,78.00,20.00,200,20),
(6,'Anchor Bolt M8×80 (10pc)','Анкер-болт М8×80 (10шт)','FST-ANK-M8','4600000000165','Fischer','pack',380.00,245.00,20.00,150,20),
(6,'Dowel-Nail 6×40 (100pc)','Дюбель-гвоздь 6×40 (100шт)','FST-DWL-6X40','4600000000172',NULL,'pack',95.00,60.00,20.00,300,30),
(6,'Hex Bolt M10×80 (10pc)','Болт М10×80 (10шт)','FST-HEX-M10','4600000000189',NULL,'pack',145.00,95.00,20.00,200,20),
-- Plumbing
(7,'PPR Pipe 25mm (per m)','Труба ППР 25мм (за м)','PLM-PPR-25','4600000000196','Ekoplastik','m',185.00,120.00,20.00,300,30),
(7,'Ball Valve 3/4"','Кран шаровый 3/4"','PLM-KRN-34','4600000000202','Valtec','pcs',450.00,290.00,20.00,80,10),
(7,'Silicone Sealant 300ml','Герметик силиконовый 300мл','PLM-SLK-300','4600000000219','Henkel','pcs',280.00,180.00,20.00,100,15),
-- Electrical
(8,'NYM Cable 3×1.5 (per m)','Кабель ВВГнг 3×1.5 (за м)','ELC-NYM-315','4600000000226','Southwire','m',62.00,40.00,20.00,500,50),
(8,'Socket Outlet IP44','Розетка уличная IP44','ELC-SKT-IP44','4600000000233','Schneider','pcs',420.00,270.00,20.00,60,10),
(8,'LED Bulb E27 12W','Лампа LED E27 12Вт','ELC-LED-E27','4600000000240','Gauss','pcs',180.00,115.00,20.00,200,20),
-- Dry Mixes
(9,'Tile Adhesive 25kg','Плиточный клей 25кг','MIX-TLE-25','4600000000257','Ceresit','bag',480.00,310.00,20.00,400,40),
(9,'Gypsum Plaster 30kg','Гипсовая штукатурка 30кг','MIX-GYP-30','4600000000264','Knauf','bag',550.00,360.00,20.00,300,30),
(9,'Self-Levelling Floor 25kg','Нивелир пол 25кг','MIX-SLF-25','4600000000271','Bergauf','bag',620.00,405.00,20.00,200,25),
-- Insulation
(10,'Mineral Wool 100mm 0.36m³','Минвата 100мм 0.36м³','INS-MW-100','4600000000288','Rockwool','pack',2200.00,1450.00,20.00,80,10),
(10,'EPS Foam 50mm 1×0.5m²','Пенопласт ЭПС 50мм','INS-EPS-50','4600000000295',NULL,'pack',880.00,575.00,20.00,100,15),
-- Roofing
(11,'Corrugated Sheet C-8 (per m²)','Профлист С-8 (за м²)','RFG-C8-M2','4600000000301',NULL,'m2',420.00,275.00,20.00,500,50),
(11,'Bitumen Felt 1×15m Roll','Рубероид 1×15м рулон','RFG-RUB-15','4600000000318',NULL,'roll',950.00,620.00,20.00,60,8),
-- Household
(12,'Work Gloves, pair','Перчатки рабочие, пара','HSH-GLV-1','4600000000325',NULL,'pair',85.00,55.00,20.00,500,50),
(12,'Safety Helmet','Каска строительная','HSH-HLM-1','4600000000332','3M','pcs',780.00,510.00,20.00,40,5),
(12,'Construction Tape 50m','Малярная лента 50м','HSH-TPE-50','4600000000349',NULL,'roll',120.00,78.00,20.00,300,30);

-- ----------------------------------------------------------------
-- customers
-- ----------------------------------------------------------------
CREATE TABLE `customers` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`            VARCHAR(150) NOT NULL,
  `phone`           VARCHAR(25)  DEFAULT NULL,
  `email`           VARCHAR(150) DEFAULT NULL,
  `company`         VARCHAR(150) DEFAULT NULL,
  `inn`             VARCHAR(20)  DEFAULT NULL COMMENT 'Tax ID',
  `customer_type`   ENUM('retail','legal') NOT NULL DEFAULT 'retail',
  `address`         TEXT         DEFAULT NULL,
  `notes`           TEXT         DEFAULT NULL,
  `discount_pct`    DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `total_spent`     DECIMAL(16,2) NOT NULL DEFAULT 0.00,
  `visits`          INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_phone` (`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default customer (always id=1)
INSERT INTO `customers` (`name`,`phone`,`customer_type`) VALUES ('Покупатель','','retail');

-- ----------------------------------------------------------------
-- shifts
-- ----------------------------------------------------------------
CREATE TABLE `shifts` (
  `id`               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `user_id`          INT UNSIGNED  NOT NULL,
  `opened_at`        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `closed_at`        DATETIME      DEFAULT NULL,
  `opening_cash`     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `closing_cash`     DECIMAL(12,2) DEFAULT NULL,
  `expected_cash`    DECIMAL(12,2) DEFAULT NULL,
  `cash_difference`  DECIMAL(12,2) DEFAULT NULL,
  `total_sales`      DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `total_returns`    DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `transaction_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `notes`            TEXT          DEFAULT NULL,
  `status`           ENUM('open','closed') NOT NULL DEFAULT 'open',
  PRIMARY KEY (`id`),
  KEY `idx_user`   (`user_id`),
  KEY `idx_status` (`status`),
  CONSTRAINT `fk_shift_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- sales
-- ----------------------------------------------------------------
CREATE TABLE `sales` (
  `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `shift_id`        INT UNSIGNED  DEFAULT NULL,
  `user_id`         INT UNSIGNED  NOT NULL,
  `customer_id`     INT UNSIGNED  NOT NULL DEFAULT 1,
  `customer_type_snapshot` ENUM('retail','legal') NOT NULL DEFAULT 'retail',
  `customer_name_snapshot` VARCHAR(150) DEFAULT NULL,
  `customer_company_snapshot` VARCHAR(150) DEFAULT NULL,
  `customer_iin_bin_snapshot` VARCHAR(32) DEFAULT NULL,
  `customer_address_snapshot` TEXT DEFAULT NULL,
  `customer_phone_snapshot` VARCHAR(25) DEFAULT NULL,
  `customer_email_snapshot` VARCHAR(150) DEFAULT NULL,
  `receipt_no`      VARCHAR(30)   NOT NULL UNIQUE,
  `subtotal`        DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `discount_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `tax_amount`      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `total`           DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `notes`           TEXT          DEFAULT NULL,
  `status`          ENUM('completed','voided','refunded','partial_refund') NOT NULL DEFAULT 'completed',
  `created_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_receipt`   (`receipt_no`),
  KEY        `idx_shift`     (`shift_id`),
  KEY        `idx_user`      (`user_id`),
  KEY        `idx_customer`  (`customer_id`),
  KEY        `idx_created`   (`created_at`),
  CONSTRAINT `fk_sale_shift`    FOREIGN KEY (`shift_id`)    REFERENCES `shifts`    (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_sale_user`     FOREIGN KEY (`user_id`)     REFERENCES `users`     (`id`),
  CONSTRAINT `fk_sale_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- sale_items
-- ----------------------------------------------------------------
CREATE TABLE `sale_items` (
  `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `sale_id`         INT UNSIGNED  NOT NULL,
  `product_id`      INT UNSIGNED  NOT NULL,
  `product_name`    VARCHAR(200)  NOT NULL COMMENT 'Snapshot at time of sale',
  `product_sku`     VARCHAR(60)   NOT NULL,
  `unit`            VARCHAR(15)   NOT NULL,
  `qty`             DECIMAL(14,3) NOT NULL,
  `unit_price`      DECIMAL(14,2) NOT NULL,
  `discount_pct`    DECIMAL(5,2)  NOT NULL DEFAULT 0.00,
  `discount_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `tax_rate`        DECIMAL(5,2)  NOT NULL DEFAULT 0.00,
  `tax_amount`      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `line_total`      DECIMAL(14,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_sale`    (`sale_id`),
  KEY `idx_product` (`product_id`),
  CONSTRAINT `fk_item_sale`    FOREIGN KEY (`sale_id`)    REFERENCES `sales`    (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_item_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- payments
-- ----------------------------------------------------------------
CREATE TABLE `payments` (
  `id`           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `sale_id`      INT UNSIGNED  NOT NULL,
  `method`       ENUM('cash','card','transfer','mixed') NOT NULL DEFAULT 'cash',
  `amount`       DECIMAL(12,2) NOT NULL,
  `cash_given`   DECIMAL(12,2) DEFAULT NULL COMMENT 'For cash payments: amount given by customer',
  `change_given` DECIMAL(12,2) DEFAULT NULL COMMENT 'Change returned',
  `reference`    VARCHAR(100)  DEFAULT NULL COMMENT 'Card auth / transfer ref',
  `created_at`   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sale` (`sale_id`),
  CONSTRAINT `fk_payment_sale` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- returns
-- ----------------------------------------------------------------
CREATE TABLE `returns` (
  `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `sale_id`       INT UNSIGNED  NOT NULL,
  `user_id`       INT UNSIGNED  NOT NULL,
  `return_no`     VARCHAR(30)   NOT NULL UNIQUE,
  `total`         DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `reason`        TEXT          DEFAULT NULL,
  `refund_method` ENUM('cash','card','store_credit') NOT NULL DEFAULT 'cash',
  `created_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sale` (`sale_id`),
  CONSTRAINT `fk_return_sale` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`),
  CONSTRAINT `fk_return_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `return_items` (
  `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `return_id`  INT UNSIGNED  NOT NULL,
  `sale_item_id` INT UNSIGNED NOT NULL,
  `product_id` INT UNSIGNED  NOT NULL,
  `qty`        DECIMAL(14,3) NOT NULL,
  `unit_price` DECIMAL(14,2) NOT NULL,
  `line_total` DECIMAL(14,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_return` (`return_id`),
  CONSTRAINT `fk_retitem_return` FOREIGN KEY (`return_id`) REFERENCES `returns` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_retitem_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- inventory_movements
-- ----------------------------------------------------------------
CREATE TABLE `inventory_movements` (
  `id`             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `product_id`     INT UNSIGNED  NOT NULL,
  `user_id`        INT UNSIGNED  NOT NULL,
  `type`           ENUM('receipt','sale','return','adjustment','writeoff','transfer') NOT NULL,
  `qty_change`     DECIMAL(14,6) NOT NULL COMMENT 'Positive = stock in, Negative = stock out',
  `qty_before`     DECIMAL(14,6) NOT NULL,
  `qty_after`      DECIMAL(14,6) NOT NULL,
  `unit_cost`      DECIMAL(14,2) DEFAULT NULL,
  `reference_id`   INT UNSIGNED  DEFAULT NULL COMMENT 'sale_id / return_id / etc.',
  `reference_type` VARCHAR(20)   DEFAULT NULL,
  `notes`          TEXT          DEFAULT NULL,
  `created_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_product` (`product_id`),
  KEY `idx_type`    (`type`),
  KEY `idx_created` (`created_at`),
  CONSTRAINT `fk_inv_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  CONSTRAINT `fk_inv_user`    FOREIGN KEY (`user_id`)    REFERENCES `users`    (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- settings
-- ----------------------------------------------------------------
CREATE TABLE `settings` (
  `id`    SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `key`   VARCHAR(80)       NOT NULL UNIQUE,
  `value` TEXT              DEFAULT NULL,
  `label` VARCHAR(120)      DEFAULT NULL,
  `group` VARCHAR(40)       NOT NULL DEFAULT 'general',
  `type`  ENUM('text','number','boolean','select','textarea','color') NOT NULL DEFAULT 'text',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `settings` (`key`,`value`,`label`,`group`,`type`) VALUES
('store_name',          'BuildMart',                        'Store Name',              'store',   'text'),
('store_address',       'г. Москва, ул. Строителей, 1',    'Store Address',           'store',   'textarea'),
('store_phone',         '+7 (495) 000-00-00',               'Phone',                   'store',   'text'),
('store_email',         'info@buildmart.local',             'Email',                   'store',   'text'),
('store_inn',           '7700000000',                       'IIN/BIN',                 'store',   'text'),
('currency_symbol',     '₸',                                'Currency Symbol',         'general', 'text'),
('currency_code',       'KZT',                              'Currency Code',           'general', 'select'),
('timezone',            'Asia/Almaty',                      'Timezone',                'general', 'select'),
('default_tax_rate',    '20',                               'Default VAT Rate (%)',    'general', 'number'),
('default_language',    'en',                               'Default Language',        'general', 'select'),
('receipt_header',      'Спасибо за покупку!',              'Receipt Header Text',     'receipt', 'text'),
('receipt_footer',      'Товар надлежащего качества обмену и возврату не подлежит в течение 14 дней', 'Receipt Footer', 'receipt', 'textarea'),
('receipt_show_logo',   '0',                                'Show Logo on Receipt',    'receipt', 'boolean'),
('low_stock_email',     '',                                 'Low Stock Alert Email',   'alerts',  'text'),
('shifts_required',     '1',                                'Require Shift to Make Sales', 'pos', 'boolean');
