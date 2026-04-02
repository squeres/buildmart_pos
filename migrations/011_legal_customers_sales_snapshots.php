<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/Database.php';

$pdo = Database::connect();

$customerTypeExists = (bool)$pdo->query("SHOW COLUMNS FROM customers LIKE 'customer_type'")->fetchColumn();
if (!$customerTypeExists) {
    $pdo->exec(
        "ALTER TABLE customers
         ADD COLUMN customer_type ENUM('retail','legal') NOT NULL DEFAULT 'retail'
         AFTER inn"
    );
}

$salesColumns = [
    'customer_type_snapshot' => "ALTER TABLE sales ADD COLUMN customer_type_snapshot ENUM('retail','legal') NOT NULL DEFAULT 'retail' AFTER customer_id",
    'customer_name_snapshot' => "ALTER TABLE sales ADD COLUMN customer_name_snapshot VARCHAR(150) NULL AFTER customer_type_snapshot",
    'customer_company_snapshot' => "ALTER TABLE sales ADD COLUMN customer_company_snapshot VARCHAR(150) NULL AFTER customer_name_snapshot",
    'customer_iin_bin_snapshot' => "ALTER TABLE sales ADD COLUMN customer_iin_bin_snapshot VARCHAR(32) NULL AFTER customer_company_snapshot",
    'customer_address_snapshot' => "ALTER TABLE sales ADD COLUMN customer_address_snapshot TEXT NULL AFTER customer_iin_bin_snapshot",
    'customer_phone_snapshot' => "ALTER TABLE sales ADD COLUMN customer_phone_snapshot VARCHAR(25) NULL AFTER customer_address_snapshot",
    'customer_email_snapshot' => "ALTER TABLE sales ADD COLUMN customer_email_snapshot VARCHAR(150) NULL AFTER customer_phone_snapshot",
];

foreach ($salesColumns as $column => $sql) {
    $exists = (bool)$pdo->query("SHOW COLUMNS FROM sales LIKE '{$column}'")->fetchColumn();
    if (!$exists) {
        $pdo->exec($sql);
    }
}

$pdo->exec("UPDATE customers SET customer_type='retail' WHERE customer_type IS NULL OR customer_type=''");

$pdo->exec(
    "UPDATE sales s
     JOIN customers c ON c.id = s.customer_id
     SET s.customer_type_snapshot = CASE WHEN COALESCE(s.customer_type_snapshot, '') = '' THEN COALESCE(c.customer_type, 'retail') ELSE s.customer_type_snapshot END,
         s.customer_name_snapshot = CASE WHEN COALESCE(s.customer_name_snapshot, '') = '' THEN c.name ELSE s.customer_name_snapshot END,
         s.customer_company_snapshot = CASE WHEN COALESCE(s.customer_company_snapshot, '') = '' THEN c.company ELSE s.customer_company_snapshot END,
         s.customer_iin_bin_snapshot = CASE WHEN COALESCE(s.customer_iin_bin_snapshot, '') = '' THEN c.inn ELSE s.customer_iin_bin_snapshot END,
         s.customer_address_snapshot = CASE WHEN COALESCE(s.customer_address_snapshot, '') = '' THEN c.address ELSE s.customer_address_snapshot END,
         s.customer_phone_snapshot = CASE WHEN COALESCE(s.customer_phone_snapshot, '') = '' THEN c.phone ELSE s.customer_phone_snapshot END,
         s.customer_email_snapshot = CASE WHEN COALESCE(s.customer_email_snapshot, '') = '' THEN c.email ELSE s.customer_email_snapshot END"
);

echo "legal customers and sales snapshots ready", PHP_EOL;
