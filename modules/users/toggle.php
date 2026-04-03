<?php
require_once __DIR__ . '/../../core/bootstrap.php';
Auth::requireLogin(); Auth::requirePerm('all');
if (!is_post() || !csrf_verify()) {
    flash_error(_r('err_csrf'));
    redirect('/modules/users/');
}
$id = (int)($_POST['id'] ?? 0);
if ($id == Auth::id()) { flash_error(_r('usr_cannot_deactivate_self')); redirect('/modules/users/'); }
$u = Database::row("SELECT is_active FROM users WHERE id=?", [$id]);
if ($u) { Database::exec("UPDATE users SET is_active=? WHERE id=?", [$u['is_active']?0:1, $id]); }
redirect('/modules/users/');
