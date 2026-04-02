<?php
require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../views/partials/icons.php';
Auth::requireLogin();
Auth::requirePerm('settings');

$id = (int)($_GET['id'] ?? 0);
$entity = Database::row(
    "SELECT be.*, (SELECT COUNT(*) FROM sale_invoices si WHERE si.business_entity_id = be.id) AS invoice_count
     FROM business_entities be
     WHERE be.id = ?",
    [$id]
);
if (!$entity) {
    flash_error(_r('err_not_found'));
    redirect('/modules/business_entities/');
}

$pageTitle = e($entity['name']);
$breadcrumbs = [[__('be_title'), url('modules/business_entities/')], [$pageTitle, null]];

include __DIR__ . '/../../views/layouts/header.php';
?>

<div class="page-header">
  <div>
    <h1 class="page-heading"><?= e($entity['name']) ?></h1>
    <div style="margin-top:8px">
      <?= !empty($entity['is_active'])
        ? '<span class="badge badge-success">' . __('lbl_active') . '</span>'
        : '<span class="badge badge-secondary">' . __('lbl_inactive') . '</span>' ?>
    </div>
  </div>
  <div class="page-actions">
    <a href="<?= url('modules/business_entities/edit.php?id=' . $id) ?>" class="btn btn-secondary">
      <?= feather_icon('edit-2', 15) ?> <?= __('btn_edit') ?>
    </a>
    <a href="<?= url('modules/business_entities/') ?>" class="btn btn-ghost">
      <?= feather_icon('arrow-left', 15) ?> <?= __('btn_back') ?>
    </a>
  </div>
</div>

<div style="display:grid;grid-template-columns:360px 1fr;gap:16px;align-items:start">
  <div class="card">
    <div class="card-body" style="display:flex;flex-direction:column;gap:10px">
      <?php
      $info = [
          [__('be_legal_name'), $entity['legal_name'] ?: '—'],
          [__('cust_inn'), $entity['iin_bin'] ?: '—'],
          [__('lbl_phone'), $entity['phone'] ?: '—'],
          [__('lbl_email'), $entity['email'] ?: '—'],
          [__('be_responsible_name'), $entity['responsible_name'] ?: '—'],
          [__('be_responsible_position'), $entity['responsible_position'] ?: '—'],
          [__('be_released_by_name'), $entity['released_by_name'] ?: '—'],
          [__('be_chief_accountant_name'), $entity['chief_accountant_name'] ?: '—'],
          [__('be_invoice_count'), (string)((int)$entity['invoice_count'])],
      ];
      ?>
      <?php foreach ($info as [$label, $value]): ?>
        <div style="display:flex;justify-content:space-between;gap:10px;font-size:13px">
          <span class="text-muted"><?= e($label) ?></span>
          <span class="fw-600" style="text-align:right"><?= e((string)$value) ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div style="display:flex;flex-direction:column;gap:12px">
    <div class="card">
      <div class="card-header"><span class="card-title"><?= __('lbl_address') ?></span></div>
      <div class="card-body" style="font-size:13px;line-height:1.6">
        <?= $entity['address'] ? nl2br(e($entity['address'])) : '<span class="text-muted">—</span>' ?>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><span class="card-title"><?= __('lbl_notes') ?></span></div>
      <div class="card-body" style="font-size:13px;line-height:1.6">
        <?= $entity['notes'] ? nl2br(e($entity['notes'])) : '<span class="text-muted">—</span>' ?>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../../views/layouts/footer.php'; ?>
<script src="https://unpkg.com/feather-icons/dist/feather.min.js"></script>
<script>feather.replace();</script>