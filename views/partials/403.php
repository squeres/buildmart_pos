<?php
require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/icons.php';

$pageTitle = __('err_403_title');
include __DIR__ . '/../layouts/header.php';
?>
<div class="empty-state" style="padding:80px 20px">
  <div class="empty-state-icon" aria-hidden="true">
    <?= feather_icon('lock', 60) ?>
  </div>
  <h1 class="empty-state-title"><?= __('err_403_title') ?></h1>
  <p class="empty-state-text"><?= __('auth_no_permission') ?></p>
  <a href="<?= url('index.php') ?>" class="btn btn-primary mt-2">
    <?= feather_icon('arrow-left', 14) ?> <?= __('nav_dashboard') ?>
  </a>
</div>
<?php include __DIR__ . '/../layouts/footer.php'; ?>
