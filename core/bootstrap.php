<?php
/**
 * Bootstrap — loaded by every page before anything else.
 * Initialises config, autoloader, session, and language.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Services/AppServiceException.php';
require_once __DIR__ . '/Services/AuthService.php';
require_once __DIR__ . '/Services/InventoryService.php';
require_once __DIR__ . '/Services/ShiftService.php';
require_once __DIR__ . '/Services/SaleService.php';
require_once __DIR__ . '/Lang.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/shift_helpers.php';
require_once __DIR__ . '/user_presence.php';
require_once __DIR__ . '/gr_helpers.php';
require_once __DIR__ . '/wh_helpers.php';
require_once __DIR__ . '/replenishment_helpers.php';
require_once __DIR__ . '/UISettings.php';

// ── Session ────────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('buildmart_sess');
    session_start();
}

if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('Content-Type: text/html; charset=UTF-8');
}

// ── Language ───────────────────────────────────────────────────────
Lang::init();
date_default_timezone_set(current_timezone());

$scriptPath = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
$saleInvoicePrintPath = '/modules/sale_invoices/print.php';
$passwordChangeAllowed = [
    '/modules/auth/login.php',
    '/modules/auth/logout.php',
    '/modules/auth/change_password.php',
];

if (Auth::check() && Auth::mustChangePassword() && !in_array($scriptPath, $passwordChangeAllowed, true)) {
    flash_warning(_r('auth_password_change_required'));
    redirect('/modules/auth/change_password.php');
}

if (Auth::check()) {
    touch_current_user_presence();
}

if (Auth::check() && $scriptPath === $saleInvoicePrintPath) {
    $saleInvoiceId = (int)($_GET['id'] ?? 0);
    if ($saleInvoiceId > 0) {
        $saleInvoiceWarehouseId = (int)Database::value(
            "SELECT s.warehouse_id
             FROM sale_invoices si
             JOIN sales s ON s.id = si.sale_id
             WHERE si.id = ?",
            [$saleInvoiceId]
        );
        if ($saleInvoiceWarehouseId > 0) {
            require_warehouse_access($saleInvoiceWarehouseId, '/modules/sales/');
        }
    }
}

// ── Timezone from settings (after DB available) ────────────────────
// (already set in config, fine for now)
