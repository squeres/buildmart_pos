<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/Database.php';

$pdo = Database::connect();

$columns = [];
foreach ($pdo->query("SHOW COLUMNS FROM stock_transfer_items") as $row) {
    $columns[(string)$row['Field']] = $row;
}

if (isset($columns['unit'])) {
    $pdo->exec("ALTER TABLE stock_transfer_items MODIFY COLUMN unit VARCHAR(64) NOT NULL DEFAULT 'pcs'");
}

if (!isset($columns['unit_label'])) {
    $pdo->exec("ALTER TABLE stock_transfer_items ADD COLUMN unit_label VARCHAR(120) NULL AFTER unit");
}

if (!isset($columns['qty_base'])) {
    $pdo->exec("ALTER TABLE stock_transfer_items ADD COLUMN qty_base DECIMAL(14,6) NOT NULL DEFAULT 0.000000 AFTER qty");
}

if (isset($columns['qty']) && stripos((string)$columns['qty']['Type'], 'decimal(14,6)') === false) {
    $pdo->exec("ALTER TABLE stock_transfer_items MODIFY COLUMN qty DECIMAL(14,6) NOT NULL DEFAULT 0.000000");
}

$pdo->exec(
    "UPDATE stock_transfer_items i
     JOIN products p ON p.id = i.product_id
     SET i.unit_label = COALESCE(NULLIF(i.unit_label, ''), p.unit),
         i.qty_base = CASE
             WHEN i.qty_base > 0 THEN i.qty_base
             ELSE i.qty
         END
     WHERE i.unit_label IS NULL
        OR i.unit_label = ''
        OR i.qty_base <= 0"
);

echo "stock_transfer_items unit fields ready", PHP_EOL;
