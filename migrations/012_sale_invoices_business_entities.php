<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/Database.php';

$pdo = Database::connect();

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS business_entities (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150) NOT NULL,
        legal_name VARCHAR(190) NULL,
        iin_bin VARCHAR(32) NULL,
        address TEXT NULL,
        phone VARCHAR(25) NULL,
        email VARCHAR(150) NULL,
        responsible_name VARCHAR(150) NULL,
        responsible_position VARCHAR(150) NULL,
        released_by_name VARCHAR(150) NULL,
        chief_accountant_name VARCHAR(150) NULL,
        notes TEXT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$contactPersonExists = (bool)$pdo->query("SHOW COLUMNS FROM customers LIKE 'contact_person'")->fetchColumn();
if (!$contactPersonExists) {
    $pdo->exec(
        "ALTER TABLE customers
         ADD COLUMN contact_person VARCHAR(150) NULL
         AFTER company"
    );
}

$customerTypeExists = (bool)$pdo->query("SHOW COLUMNS FROM customers LIKE 'customer_type'")->fetchColumn();
if (!$customerTypeExists) {
    $pdo->exec(
        "ALTER TABLE customers
         ADD COLUMN customer_type ENUM('individual','legal') NOT NULL DEFAULT 'individual'
         AFTER inn"
    );
} else {
    $pdo->exec(
        "ALTER TABLE customers
         MODIFY COLUMN customer_type ENUM('retail','individual','legal') NOT NULL DEFAULT 'retail'"
    );
    $pdo->exec(
        "UPDATE customers
         SET customer_type = 'individual'
         WHERE customer_type IS NULL OR customer_type = '' OR customer_type = 'retail'"
    );
    $pdo->exec(
        "ALTER TABLE customers
         MODIFY COLUMN customer_type ENUM('individual','legal') NOT NULL DEFAULT 'individual'"
    );
}

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS sale_invoices (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        sale_id INT UNSIGNED NOT NULL,
        business_entity_id INT UNSIGNED NOT NULL,
        customer_id INT UNSIGNED NULL,
        invoice_number VARCHAR(60) NOT NULL,
        invoice_date DATE NOT NULL,
        power_of_attorney_no VARCHAR(60) NULL,
        power_of_attorney_date DATE NULL,
        transport_company VARCHAR(190) NULL,
        transport_waybill_no VARCHAR(60) NULL,
        transport_waybill_date DATE NULL,
        notes TEXT NULL,
        sender_name_snapshot VARCHAR(150) NULL,
        sender_legal_name_snapshot VARCHAR(190) NULL,
        sender_iin_bin_snapshot VARCHAR(32) NULL,
        sender_address_snapshot TEXT NULL,
        sender_phone_snapshot VARCHAR(25) NULL,
        sender_email_snapshot VARCHAR(150) NULL,
        sender_responsible_name_snapshot VARCHAR(150) NULL,
        sender_responsible_position_snapshot VARCHAR(150) NULL,
        sender_released_by_snapshot VARCHAR(150) NULL,
        sender_chief_accountant_snapshot VARCHAR(150) NULL,
        customer_type_snapshot ENUM('individual','legal') NOT NULL DEFAULT 'individual',
        customer_name_snapshot VARCHAR(150) NULL,
        customer_company_snapshot VARCHAR(150) NULL,
        customer_iin_bin_snapshot VARCHAR(32) NULL,
        customer_address_snapshot TEXT NULL,
        customer_contact_person_snapshot VARCHAR(150) NULL,
        customer_phone_snapshot VARCHAR(25) NULL,
        customer_email_snapshot VARCHAR(150) NULL,
        created_by INT UNSIGNED NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_sale_invoices_sale_id (sale_id),
        KEY idx_sale_invoices_invoice_date (invoice_date),
        KEY idx_sale_invoices_business_entity (business_entity_id),
        CONSTRAINT fk_sale_invoices_sale FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
        CONSTRAINT fk_sale_invoices_business_entity FOREIGN KEY (business_entity_id) REFERENCES business_entities(id),
        CONSTRAINT fk_sale_invoices_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
        CONSTRAINT fk_sale_invoices_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

echo "business entities and sale invoices ready", PHP_EOL;
