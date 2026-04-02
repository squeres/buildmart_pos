<?php
declare(strict_types=1);

require_once __DIR__ . '/../../core/bootstrap.php';

Auth::requireLogin();
Auth::requirePerm('products');

if (!is_post() || !csrf_verify()) {
    flash_error(__('err_csrf'));
    redirect('/modules/products/');
}

$id = (int)($_POST['id'] ?? 0);
$returnTo = (string)($_POST['return_to'] ?? '/modules/products/');
if (!str_starts_with($returnTo, '/modules/products/')) {
    $returnTo = '/modules/products/';
}

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

$moveRow = Database::row(
    'SELECT COUNT(*) AS cnt FROM inventory_movements WHERE product_id=?',
    [$id]
);

$hasMoves = (int)($moveRow['cnt'] ?? 0);

if ($hasMoves > 0) {
    Database::exec(
        'UPDATE products SET is_active=0 WHERE id=?',
        [$id]
    );

    flash_success(__('msg_product_archived'));
    redirect($returnTo);
}

Database::exec('DELETE FROM products WHERE id=?', [$id]);
flash_success(__('msg_deleted'));
redirect($returnTo);
