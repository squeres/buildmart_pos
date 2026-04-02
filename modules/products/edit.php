<?php
// Edit is the same as add — just forward with the id param
require_once __DIR__ . '/../../core/bootstrap.php';
$id = (int)($_GET['id'] ?? 0);
if (!$id) { redirect('/modules/products/'); }
// Include add.php which handles both modes via $isEdit flag
include __DIR__ . '/add.php';
