<?php
require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/icons.php';
$pageTitle = '403 — Access Denied';
include __DIR__ . '/../layouts/header.php';
?>
<div class="empty-state" style="padding:80px 20px">
  <div class="empty-state-icon" style="font-size:60px">🔒</div>
  <h1 class="empty-state-title">Access Denied</h1>
  <p class="empty-state-text"><?= __('auth_no_permission') ?></p>
  <a href="<?= url('index.php') ?>" class="btn btn-primary mt-2">← Dashboard</a>
</div>
<?php include __DIR__ . '/../layouts/footer.php'; ?>
