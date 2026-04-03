<?php

$envPath = realpath(__DIR__ . '/..') . '/.env';
if (is_file($envPath) && is_readable($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);

        if ($key === '' || getenv($key) !== false) {
            continue;
        }

        if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
            $value = substr($value, 1, -1);
        }

        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}
/**
 * BuildMart POS — Main Configuration
 * Edit DB credentials and paths before deploying.
 */

// ── Database ────────────────────────────────────────────────────
define('DB_HOST',    getenv('DB_HOST')    ?: 'localhost');
define('DB_NAME',    getenv('DB_NAME')    ?: 'buildmart_pos');
define('DB_USER',    getenv('DB_USER')    ?: 'root');
define('DB_PASS',    getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');
define('DB_CHARSET', 'utf8mb4');

// ── Paths ───────────────────────────────────────────────────────
define('ROOT_PATH',   realpath(__DIR__ . '/..'));
define('UPLOAD_PATH', ROOT_PATH . '/uploads/products/');

// ── URL (auto-detect, or hard-code if behind proxy) ─────────────
define('BASE_URL', getenv('BASE_URL') ?: 'https://buildmart.local');
define('UPLOAD_URL', BASE_URL . '/uploads/products/');

// ── App settings ────────────────────────────────────────────────
define('APP_NAME',         'BuildMart POS');
define('APP_VERSION',      '1.0.0');
define('SESSION_LIFETIME', 28800); // 8 h
define('ITEMS_PER_PAGE',   30);

// ── Languages ───────────────────────────────────────────────────
define('SUPPORTED_LANGS', ['ru' => 'Русский', 'en' => 'English']);
define('DEFAULT_LANG',    'ru');

// ── Timezone ────────────────────────────────────────────────────
date_default_timezone_set(getenv('TZ') ?: 'Asia/Almaty');
