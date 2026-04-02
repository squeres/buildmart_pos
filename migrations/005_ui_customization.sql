-- ================================================================
-- BuildMart POS — Migration 005: UI Customization System
-- Flexible price types, configurable views, presets, preferences
-- Run once on existing database.
-- ================================================================

SET foreign_key_checks = 0;
SET NAMES utf8mb4;

-- ----------------------------------------------------------------
-- 1. price_types — dynamic price type registry
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `price_types` (
  `id`                TINYINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `code`              VARCHAR(40)       NOT NULL UNIQUE,
  `name_en`           VARCHAR(80)       NOT NULL,
  `name_ru`           VARCHAR(80)       NOT NULL,
  `sort_order`        TINYINT UNSIGNED  NOT NULL DEFAULT 10,
  `is_active`         TINYINT(1)        NOT NULL DEFAULT 1,
  `is_default`        TINYINT(1)        NOT NULL DEFAULT 0,
  `visible_in_pos`    TINYINT(1)        NOT NULL DEFAULT 1,
  `visible_in_products` TINYINT(1)      NOT NULL DEFAULT 1,
  `visible_in_receipts` TINYINT(1)      NOT NULL DEFAULT 0,
  `color_hex`         VARCHAR(7)        DEFAULT NULL COMMENT 'badge color e.g. #f59e0b',
  `created_at`        TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed default price types (idempotent)
INSERT IGNORE INTO `price_types`
  (`code`, `name_en`, `name_ru`, `sort_order`, `is_active`, `is_default`, `visible_in_pos`, `visible_in_products`, `color_hex`)
VALUES
  ('retail',      'Retail Price',       'Розничная',      1, 1, 1, 1, 1, '#10b981'),
  ('wholesale1',  'Wholesale 1',        'Опт 1',          2, 1, 0, 1, 1, '#3b82f6'),
  ('wholesale2',  'Wholesale 2',        'Опт 2',          3, 1, 0, 1, 1, '#8b5cf6'),
  ('wholesale3',  'Wholesale 3',        'Опт 3',          4, 1, 0, 1, 1, '#f59e0b'),
  ('purchase',    'Purchase Cost',      'Закупочная',     5, 1, 0, 0, 1, '#ef4444');

-- ----------------------------------------------------------------
-- 2. product_prices — one row per product×price_type
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `product_prices` (
  `id`            INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  `product_id`    INT UNSIGNED      NOT NULL,
  `price_type_id` TINYINT UNSIGNED  NOT NULL,
  `price`         DECIMAL(12,2)     NOT NULL DEFAULT 0.00,
  `currency`      CHAR(3)           NOT NULL DEFAULT 'KZT',
  `updated_at`    TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_product_pricetype` (`product_id`, `price_type_id`),
  KEY `idx_pp_product` (`product_id`),
  CONSTRAINT `fk_pp_product`    FOREIGN KEY (`product_id`)    REFERENCES `products`(`id`)     ON DELETE CASCADE,
  CONSTRAINT `fk_pp_pricetype`  FOREIGN KEY (`price_type_id`) REFERENCES `price_types`(`id`)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migrate existing sale_price → retail, cost_price → purchase
-- (safe to run multiple times — INSERT IGNORE)
INSERT IGNORE INTO `product_prices` (`product_id`, `price_type_id`, `price`)
SELECT p.id,
       (SELECT id FROM price_types WHERE code = 'retail' LIMIT 1),
       IFNULL(p.sale_price, 0)
FROM products p
WHERE p.sale_price IS NOT NULL AND p.sale_price > 0;

INSERT IGNORE INTO `product_prices` (`product_id`, `price_type_id`, `price`)
SELECT p.id,
       (SELECT id FROM price_types WHERE code = 'purchase' LIMIT 1),
       IFNULL(p.cost_price, 0)
FROM products p
WHERE p.cost_price IS NOT NULL AND p.cost_price > 0;

-- ----------------------------------------------------------------
-- 3. ui_presets — saved view configurations (system/role/wh/user)
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ui_presets` (
  `id`            INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  `name`          VARCHAR(100)      NOT NULL,
  `module`        VARCHAR(60)       NOT NULL COMMENT 'products, pos, inventory, sales, etc.',
  `scope_type`    ENUM('system','role','warehouse','user') NOT NULL DEFAULT 'user',
  `scope_id`      INT UNSIGNED      DEFAULT NULL COMMENT 'role_id / warehouse_id / user_id',
  `is_default`    TINYINT(1)        NOT NULL DEFAULT 0,
  `settings_json` JSON              NOT NULL,
  `created_by`    INT UNSIGNED      DEFAULT NULL,
  `created_at`    TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_preset_scope` (`module`, `scope_type`, `scope_id`),
  KEY `idx_preset_default` (`module`, `scope_type`, `scope_id`, `is_default`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- 4. user_preferences — per-user per-module settings (last saved state)
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `user_preferences` (
  `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `user_id`       INT UNSIGNED  NOT NULL,
  `module`        VARCHAR(60)   NOT NULL,
  `settings_json` JSON          NOT NULL,
  `updated_at`    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_module` (`user_id`, `module`),
  CONSTRAINT `fk_up_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- 5. role_ui_settings — role-level defaults per module
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `role_ui_settings` (
  `id`            INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  `role_id`       TINYINT UNSIGNED NOT NULL,
  `module`        VARCHAR(60)      NOT NULL,
  `settings_json` JSON             NOT NULL,
  `updated_at`    TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_role_module` (`role_id`, `module`),
  CONSTRAINT `fk_rus_role` FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- 6. warehouse_ui_settings — warehouse-level defaults per module
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `warehouse_ui_settings` (
  `id`            INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  `warehouse_id`  SMALLINT UNSIGNED NOT NULL,
  `module`        VARCHAR(60)       NOT NULL,
  `settings_json` JSON              NOT NULL,
  `updated_at`    TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wh_module` (`warehouse_id`, `module`),
  CONSTRAINT `fk_wus_wh` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- 7. role_price_visibility — which price types each role can see
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `role_price_visibility` (
  `role_id`       TINYINT UNSIGNED NOT NULL,
  `price_type_id` TINYINT UNSIGNED NOT NULL,
  `can_view`      TINYINT(1)       NOT NULL DEFAULT 1,
  `can_edit`      TINYINT(1)       NOT NULL DEFAULT 0,
  `in_pos`        TINYINT(1)       NOT NULL DEFAULT 1,
  `in_products`   TINYINT(1)       NOT NULL DEFAULT 1,
  PRIMARY KEY (`role_id`, `price_type_id`),
  CONSTRAINT `fk_rpv_role`  FOREIGN KEY (`role_id`)       REFERENCES `roles`(`id`)       ON DELETE CASCADE,
  CONSTRAINT `fk_rpv_pt`    FOREIGN KEY (`price_type_id`) REFERENCES `price_types`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default visibility: admin sees all, manager sees all, cashier sees retail only
-- admin
INSERT IGNORE INTO `role_price_visibility` (`role_id`, `price_type_id`, `can_view`, `can_edit`, `in_pos`, `in_products`)
SELECT r.id, pt.id, 1, 1, pt.visible_in_pos, pt.visible_in_products
FROM roles r, price_types pt WHERE r.slug = 'admin';

-- manager: see all, edit all except purchase hidden in POS
INSERT IGNORE INTO `role_price_visibility` (`role_id`, `price_type_id`, `can_view`, `can_edit`, `in_pos`, `in_products`)
SELECT r.id, pt.id, 1, 1,
       IF(pt.code = 'purchase', 0, pt.visible_in_pos),
       pt.visible_in_products
FROM roles r, price_types pt WHERE r.slug = 'manager';

-- cashier: retail only
INSERT IGNORE INTO `role_price_visibility` (`role_id`, `price_type_id`, `can_view`, `can_edit`, `in_pos`, `in_products`)
SELECT r.id, pt.id,
       IF(pt.code IN ('retail'), 1, 0),
       0,
       IF(pt.code = 'retail', 1, 0),
       IF(pt.code = 'retail', 1, 0)
FROM roles r, price_types pt WHERE r.slug = 'cashier';

-- ----------------------------------------------------------------
-- 8. Seed system-level presets for common roles
-- ----------------------------------------------------------------

-- SYSTEM default: sidebar menu order
INSERT IGNORE INTO `ui_presets` (`name`, `module`, `scope_type`, `scope_id`, `is_default`, `settings_json`)
VALUES (
  'System Default Menu', 'sidebar', 'system', NULL, 1,
  JSON_OBJECT(
    'items', JSON_ARRAY(
      'dashboard','pos','products','categories','inventory',
      'receipts','acceptance','transfers','sales','customers',
      'shifts','reports','suppliers','warehouses','users','settings'
    ),
    'hidden', JSON_ARRAY(),
    'pinned', JSON_ARRAY('dashboard','pos')
  )
);

-- Cashier preset: minimal menu
INSERT IGNORE INTO `ui_presets` (`name`, `module`, `scope_type`, `scope_id`, `is_default`, `settings_json`, `created_by`)
SELECT 'Cashier Menu', 'sidebar', 'role', r.id, 1,
  JSON_OBJECT(
    'items', JSON_ARRAY('dashboard','pos','sales','customers','shifts'),
    'hidden', JSON_ARRAY('users','warehouses','settings','reports','suppliers','receipts','acceptance','transfers'),
    'pinned', JSON_ARRAY('pos')
  ), NULL
FROM roles r WHERE r.slug = 'cashier';

-- Manager preset: operational menu
INSERT IGNORE INTO `ui_presets` (`name`, `module`, `scope_type`, `scope_id`, `is_default`, `settings_json`)
SELECT 'Manager Menu', 'sidebar', 'role', r.id, 1,
  JSON_OBJECT(
    'items', JSON_ARRAY('dashboard','products','categories','inventory','receipts','acceptance','transfers','sales','customers','shifts','reports','suppliers','warehouses','settings'),
    'hidden', JSON_ARRAY('users','pos'),
    'pinned', JSON_ARRAY('dashboard','reports')
  )
FROM roles r WHERE r.slug = 'manager';

-- Admin preset: full menu
INSERT IGNORE INTO `ui_presets` (`name`, `module`, `scope_type`, `scope_id`, `is_default`, `settings_json`)
SELECT 'Admin Menu', 'sidebar', 'role', r.id, 1,
  JSON_OBJECT(
    'items', JSON_ARRAY('dashboard','pos','products','categories','inventory','receipts','acceptance','transfers','sales','customers','shifts','reports','suppliers','warehouses','users','settings'),
    'hidden', JSON_ARRAY(),
    'pinned', JSON_ARRAY('dashboard')
  )
FROM roles r WHERE r.slug = 'admin';

-- ----------------------------------------------------------------
-- 9. System-level module presets (products, pos, inventory, sales)
-- ----------------------------------------------------------------

-- Products: default columns
INSERT IGNORE INTO `ui_presets` (`name`, `module`, `scope_type`, `scope_id`, `is_default`, `settings_json`)
VALUES (
  'Default Products View', 'products', 'system', NULL, 1,
  JSON_OBJECT(
    'view_mode', 'table',
    'columns', JSON_ARRAY('name','sku','category','stock','status','retail','actions'),
    'columns_order', JSON_ARRAY('name','sku','category','stock','status','retail','actions'),
    'sort_by', 'name',
    'sort_dir', 'asc',
    'filters', JSON_OBJECT('search','','category_id','','status','','stock_filter',''),
    'per_page', 30
  )
);

-- POS: default card view
INSERT IGNORE INTO `ui_presets` (`name`, `module`, `scope_type`, `scope_id`, `is_default`, `settings_json`)
VALUES (
  'Default POS View', 'pos', 'system', NULL, 1,
  JSON_OBJECT(
    'view_mode', 'cards',
    'show_categories_bar', 1,
    'show_search', 1,
    'show_stock', 1,
    'show_sku', 0,
    'price_type', 'retail',
    'price_types_visible', JSON_ARRAY('retail'),
    'large_touch', 0,
    'columns_list', JSON_ARRAY('name','price','stock','add_btn')
  )
);

-- Inventory: default columns
INSERT IGNORE INTO `ui_presets` (`name`, `module`, `scope_type`, `scope_id`, `is_default`, `settings_json`)
VALUES (
  'Default Inventory View', 'inventory', 'system', NULL, 1,
  JSON_OBJECT(
    'view_mode', 'table',
    'stock_display', 'summarized',
    'columns', JSON_ARRAY('name','sku','category','warehouse','stock','min_stock','status','actions'),
    'sort_by', 'name',
    'sort_dir', 'asc',
    'filters', JSON_OBJECT('search','','category_id','','warehouse_id','','stock_filter','')
  )
);

-- Sales: default columns
INSERT IGNORE INTO `ui_presets` (`name`, `module`, `scope_type`, `scope_id`, `is_default`, `settings_json`)
VALUES (
  'Default Sales View', 'sales', 'system', NULL, 1,
  JSON_OBJECT(
    'columns', JSON_ARRAY('receipt_no','date','cashier','customer','total','status','payment_method','actions'),
    'sort_by', 'created_at',
    'sort_dir', 'desc',
    'filters', JSON_OBJECT('search','','date_from','','date_to','','status','','cashier_id','','payment_method','')
  )
);

-- ----------------------------------------------------------------
-- 10. Role-specific module presets
-- ----------------------------------------------------------------

-- Cashier: POS wholesale with multiple prices
INSERT IGNORE INTO `ui_presets` (`name`, `module`, `scope_type`, `scope_id`, `is_default`, `settings_json`)
SELECT 'Wholesale POS', 'pos', 'role', r.id, 1,
  JSON_OBJECT(
    'view_mode', 'list',
    'show_categories_bar', 1,
    'show_search', 1,
    'show_stock', 1,
    'show_sku', 1,
    'price_type', 'wholesale1',
    'price_types_visible', JSON_ARRAY('retail','wholesale1','wholesale2','wholesale3'),
    'large_touch', 0,
    'columns_list', JSON_ARRAY('name','sku','stock','retail','wholesale1','wholesale2','add_btn')
  )
FROM roles r WHERE r.slug = 'manager';

-- Manager products: all prices visible
INSERT IGNORE INTO `ui_presets` (`name`, `module`, `scope_type`, `scope_id`, `is_default`, `settings_json`)
SELECT 'Manager Products View', 'products', 'role', r.id, 1,
  JSON_OBJECT(
    'view_mode', 'table',
    'columns', JSON_ARRAY('name','sku','category','brand','stock','min_stock','status','retail','wholesale1','wholesale2','purchase','updated_at','actions'),
    'sort_by', 'name',
    'sort_dir', 'asc',
    'filters', JSON_OBJECT('search','','category_id','','status','','stock_filter','','supplier_id',''),
    'per_page', 30,
    'group_by_category', 0
  )
FROM roles r WHERE r.slug = 'manager';

-- Cashier products: minimal
INSERT IGNORE INTO `ui_presets` (`name`, `module`, `scope_type`, `scope_id`, `is_default`, `settings_json`)
SELECT 'Cashier Products View', 'products', 'role', r.id, 1,
  JSON_OBJECT(
    'view_mode', 'table',
    'columns', JSON_ARRAY('name','sku','category','stock','status','retail','actions'),
    'sort_by', 'name',
    'sort_dir', 'asc',
    'filters', JSON_OBJECT('search','','category_id','','status',''),
    'per_page', 30
  )
FROM roles r WHERE r.slug = 'cashier';

-- ----------------------------------------------------------------
-- 11. Add role_ui_settings defaults
-- ----------------------------------------------------------------

-- Cashier role UI: hide prices, show only retail
INSERT IGNORE INTO `role_ui_settings` (`role_id`, `module`, `settings_json`)
SELECT r.id, 'global',
  JSON_OBJECT(
    'visible_prices', JSON_ARRAY('retail'),
    'can_see_purchase_price', 0,
    'can_see_profit', 0,
    'dashboard_widgets', JSON_ARRAY('shift_status','today_sales','quick_actions'),
    'reports_access', JSON_ARRAY()
  )
FROM roles r WHERE r.slug = 'cashier';

-- Manager role UI
INSERT IGNORE INTO `role_ui_settings` (`role_id`, `module`, `settings_json`)
SELECT r.id, 'global',
  JSON_OBJECT(
    'visible_prices', JSON_ARRAY('retail','wholesale1','wholesale2','wholesale3','purchase'),
    'can_see_purchase_price', 1,
    'can_see_profit', 1,
    'dashboard_widgets', JSON_ARRAY('revenue_today','profit_today','low_stock','recent_sales','best_sellers','pending_acceptance'),
    'reports_access', JSON_ARRAY('daily','weekly','monthly','products','cashiers','categories')
  )
FROM roles r WHERE r.slug = 'manager';

-- Admin role UI
INSERT IGNORE INTO `role_ui_settings` (`role_id`, `module`, `settings_json`)
SELECT r.id, 'global',
  JSON_OBJECT(
    'visible_prices', JSON_ARRAY('retail','wholesale1','wholesale2','wholesale3','purchase'),
    'can_see_purchase_price', 1,
    'can_see_profit', 1,
    'dashboard_widgets', JSON_ARRAY('revenue_today','profit_today','low_stock','out_of_stock','recent_sales','best_sellers','pending_acceptance','active_shift','quick_actions'),
    'reports_access', JSON_ARRAY('daily','weekly','monthly','products','cashiers','categories','warehouse','wholesale')
  )
FROM roles r WHERE r.slug = 'admin';

-- ----------------------------------------------------------------
-- 12. Warehouse UI settings examples
-- ----------------------------------------------------------------

-- Wholesale warehouse: show multiple prices, list POS mode
INSERT IGNORE INTO `warehouse_ui_settings` (`warehouse_id`, `module`, `settings_json`)
SELECT id, 'pos',
  JSON_OBJECT(
    'view_mode', 'list',
    'price_type', 'wholesale1',
    'price_types_visible', JSON_ARRAY('wholesale1','wholesale2','wholesale3'),
    'show_sku', 1,
    'show_stock', 1
  )
FROM warehouses WHERE id = 1;

-- ----------------------------------------------------------------
-- 13. Add permissions for new UI modules to roles
-- ----------------------------------------------------------------

-- Allow managers to access UI settings for their scope
UPDATE `roles`
SET `permissions` = JSON_SET(`permissions`,
  '$.ui_settings', true,
  '$.price_types_view', true
)
WHERE `slug` = 'manager';

-- Admin gets price_types management
UPDATE `roles`
SET `permissions` = '{"all":true}'
WHERE `slug` = 'admin';

SET foreign_key_checks = 1;
