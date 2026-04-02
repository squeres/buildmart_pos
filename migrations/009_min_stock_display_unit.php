<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/Database.php';

$pdo = Database::connect();

$columnExists = (bool)$pdo->query("SHOW COLUMNS FROM products LIKE 'min_stock_display_unit_code'")->fetchColumn();
if (!$columnExists) {
    $pdo->exec("ALTER TABLE products ADD COLUMN min_stock_display_unit_code VARCHAR(64) NULL AFTER min_stock_qty");
}

$pdo->exec(
    "UPDATE products p
     SET p.min_stock_display_unit_code = COALESCE(
         (
             SELECT pu.unit_code
             FROM product_units pu
             WHERE pu.product_id = p.id
               AND pu.is_default = 1
             ORDER BY pu.sort_order ASC, pu.id ASC
             LIMIT 1
         ),
         p.unit
     )
     WHERE p.min_stock_display_unit_code IS NULL
        OR p.min_stock_display_unit_code = ''"
);

echo "min_stock_display_unit_code ready", PHP_EOL;
