<?php
require_once __DIR__ . '/../../core/bootstrap.php';
$id = (int)($_GET['id'] ?? 0);
if (!$id) redirect('/modules/users/');
include __DIR__ . '/add.php';
