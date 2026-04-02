<?php
require_once __DIR__ . '/../../core/bootstrap.php';
flash_info(_r('auth_logout'));
Auth::logout();
