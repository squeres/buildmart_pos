<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/helpers.php';

$pdo = Database::connect();

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS unit_presets (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        unit_code VARCHAR(64) NOT NULL,
        unit_label VARCHAR(120) NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_unit_presets_code (unit_code),
        UNIQUE KEY uniq_unit_presets_label (unit_label),
        KEY idx_unit_presets_active_sort (is_active, sort_order, unit_label)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$defaults = [
    ['unit_code' => 'korobka', 'unit_label' => 'Коробка', 'sort_order' => 10],
    ['unit_code' => 'upakovka', 'unit_label' => 'Упаковка', 'sort_order' => 20],
    ['unit_code' => 'shtuk', 'unit_label' => 'Штук', 'sort_order' => 30],
];

$stmt = $pdo->prepare(
    "INSERT INTO unit_presets (unit_code, unit_label, sort_order, is_active)
     VALUES (:unit_code, :unit_label, :sort_order, 1)
     ON DUPLICATE KEY UPDATE
       unit_label = VALUES(unit_label),
       sort_order = VALUES(sort_order),
       is_active = 1"
);

foreach ($defaults as $row) {
    $stmt->execute($row);
}

echo "unit_presets ready", PHP_EOL;
