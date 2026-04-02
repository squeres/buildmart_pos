<?php
/**
 * modules/receipts/check.php
 * Диагностика модуля поступления товаров.
 * Удали этот файл после проверки!
 */
require_once __DIR__ . '/../../core/bootstrap.php';
Auth::requireLogin();

header('Content-Type: text/plain; charset=utf-8');

echo "=== BuildMart GR Module Diagnostics ===\n\n";

// 1. Текущий пользователь и права
$u = Auth::user();
echo "User: " . ($u['name'] ?? '???') . "\n";
echo "Role: " . ($u['role_slug'] ?? '???') . "\n";
echo "Permissions: " . json_encode($u['permissions'] ?? []) . "\n";
echo "Can inventory: " . (Auth::can('inventory') ? 'YES' : 'NO') . "\n\n";

// 2. Проверка таблиц
$tables = ['warehouses','suppliers','goods_receipts','goods_receipt_items'];
echo "=== Tables ===\n";
foreach ($tables as $t) {
    $exists = Database::value("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?", [$t]);
    echo "$t: " . ($exists ? "OK" : "MISSING - run migrations/001_goods_receipts.sql!") . "\n";
}

// 3. Проверка gr_helpers
echo "\n=== gr_helpers.php ===\n";
echo "gr_next_doc_no() exists: " . (function_exists('gr_next_doc_no') ? 'YES' : 'NO') . "\n";
echo "gr_status_badge() exists: " . (function_exists('gr_status_badge') ? 'YES' : 'NO') . "\n";

// 4. Проверка settings
echo "\n=== GR Settings in DB ===\n";
$grSettings = Database::all("SELECT `key`,`value` FROM settings WHERE `key` LIKE 'gr_%'");
if ($grSettings) {
    foreach ($grSettings as $s) echo $s['key'] . " = " . $s['value'] . "\n";
} else {
    echo "No GR settings found - run migrations/001_goods_receipts.sql!\n";
}

// 5. BASE_URL
echo "\n=== Config ===\n";
echo "BASE_URL: " . BASE_URL . "\n";
echo "ROOT_PATH: " . ROOT_PATH . "\n";
echo "PHP version: " . PHP_VERSION . "\n";

echo "\n=== Done ===\n";
