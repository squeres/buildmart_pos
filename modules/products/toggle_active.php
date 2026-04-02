<?php
declare(strict_types=1);

require_once __DIR__ . '/../../core/bootstrap.php';

Auth::requireLogin();
Auth::requirePerm('products');

$returnTo = (string)($_POST['return_to'] ?? '/modules/products/');
if (!str_starts_with($returnTo, '/modules/products/')) {
    $returnTo = '/modules/products/';
}

if (!is_post() || !csrf_verify()) {
    flash_error(__('err_csrf'));
    redirect($returnTo);
}

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    flash_error(__('err_not_found'));
    redirect($returnTo);
}

$product = Database::row(
    'SELECT id, is_active FROM products WHERE id=? LIMIT 1',
    [$id]
);

if (!$product) {
    flash_error(__('err_not_found'));
    redirect($returnTo);
}

$requestedState = $_POST['active'] ?? null;
$nextState = $requestedState === null
    ? ((int)$product['is_active'] === 1 ? 0 : 1)
    : ((int)$requestedState === 1 ? 1 : 0);

if ((int)$product['is_active'] !== $nextState) {
    Database::exec(
        'UPDATE products SET is_active=?, updated_at=NOW() WHERE id=?',
        [$nextState, $id]
    );
}

flash_success($nextState === 1 ? __('msg_product_restored') : __('msg_product_deactivated'));
redirect($returnTo);
