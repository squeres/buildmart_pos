<?php
require_once __DIR__ . '/../../core/bootstrap.php';
Auth::requireLogin();

$saleId = (int)($_GET['id'] ?? 0);
if ($saleId <= 0) {
    redirect('/modules/pos/');
}

redirect('/modules/pos/receipt.php?id=' . $saleId);