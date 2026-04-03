<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/Database.php';

$pdo = Database::connect();

$languageSetAtExists = (bool)$pdo->query("SHOW COLUMNS FROM users LIKE 'language_set_at'")->fetchColumn();
if (!$languageSetAtExists) {
    $pdo->exec("ALTER TABLE users ADD COLUMN language_set_at DATETIME DEFAULT NULL AFTER language");
}

$pdo->exec("ALTER TABLE users MODIFY COLUMN language CHAR(2) NOT NULL DEFAULT 'ru'");
$pdo->exec("UPDATE users SET language='ru' WHERE language IS NULL OR language='' OR language NOT IN ('ru', 'en')");

$pdo->exec(
    "INSERT INTO settings (`key`, value, label, `group`, `type`)
     SELECT 'default_language', 'ru', 'Default Language', 'general', 'select'
     WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'default_language')"
);
$pdo->exec("UPDATE settings SET value='ru' WHERE `key`='default_language'");

echo "user profile language migration ready", PHP_EOL;
