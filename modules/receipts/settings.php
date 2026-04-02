<?php
/**
 * Goods Receipt Template Settings
 * modules/receipts/settings.php
 *
 * Allows admin users to edit document template fields from the UI
 * without touching code. Values stored in the `settings` table.
 */
require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../views/partials/icons.php';
Auth::requireLogin();
Auth::requirePerm('settings'); // admin/manager only

$pageTitle   = __('gr_settings_title');
$breadcrumbs = [[__('gr_title'), url('modules/receipts/')], [$pageTitle, null]];

// Settings keys managed on this page
$settingKeys = [
    'gr_org_name',
    'gr_org_inn',
    'gr_org_address',
    'gr_doc_title',
    'gr_header_note',
    'gr_footer_note',
    'gr_label_warehouse',
    'gr_label_supplier',
    'gr_label_accepted_by',
    'gr_label_delivered_by',
];

if (is_post()) {
    if (!csrf_verify()) { flash_error(_r('err_csrf')); redirect($_SERVER['REQUEST_URI']); }

    foreach ($settingKeys as $key) {
        $value = sanitize($_POST[$key] ?? '');
        // Use INSERT ... ON DUPLICATE KEY UPDATE for safety
        Database::exec(
            "INSERT INTO settings (`key`, `value`, `group`) VALUES (?, ?, 'gr_template')
             ON DUPLICATE KEY UPDATE `value` = ?",
            [$key, $value, $value]
        );
    }
    flash_success(_r('set_saved'));
    redirect('/modules/receipts/settings.php');
}

// Load current values
$current = [];
foreach ($settingKeys as $key) {
    $current[$key] = setting($key, '');
}

// Field meta
$fields = [
    'gr_org_name'          => ['label' => __('gr_set_org_name'),         'type' => 'text'],
    'gr_org_inn'           => ['label' => __('gr_set_org_inn'),           'type' => 'text'],
    'gr_org_address'       => ['label' => __('gr_set_org_address'),       'type' => 'textarea'],
    'gr_doc_title'         => ['label' => __('gr_set_doc_title'),         'type' => 'text'],
    'gr_header_note'       => ['label' => __('gr_set_header_note'),       'type' => 'textarea'],
    'gr_footer_note'       => ['label' => __('gr_set_footer_note'),       'type' => 'textarea'],
    'gr_label_warehouse'   => ['label' => __('gr_set_lbl_warehouse'),     'type' => 'text'],
    'gr_label_supplier'    => ['label' => __('gr_set_lbl_supplier'),      'type' => 'text'],
    'gr_label_accepted_by' => ['label' => __('gr_set_lbl_accepted_by'),   'type' => 'text'],
    'gr_label_delivered_by'=> ['label' => __('gr_set_lbl_delivered_by'),  'type' => 'text'],
];

include __DIR__ . '/../../views/layouts/header.php';
?>

<div class="page-header">
  <div>
    <h1 class="page-heading"><?= __('gr_settings_title') ?></h1>
    <div class="text-secondary" style="font-size:13px"><?= __('gr_settings_subtitle') ?></div>
  </div>
  <div class="page-actions">
    <a href="<?= url('modules/receipts/') ?>" class="btn btn-ghost">
      <?= feather_icon('arrow-left',15) ?> <?= __('btn_back') ?>
    </a>
    <a href="<?= url('modules/receipts/print.php?id=1') ?>" target="_blank" class="btn btn-ghost">
      <?= feather_icon('eye',15) ?> <?= __('gr_preview_print') ?>
    </a>
  </div>
</div>

<form method="POST">
  <?= csrf_field() ?>
  <div class="card" style="max-width:700px">
    <div class="card-header"><span class="card-title"><?= __('gr_settings_org') ?></span></div>
    <div class="card-body">
      <?php foreach ($fields as $key => $meta): ?>
      <div class="form-group">
        <label class="form-label"><?= $meta['label'] ?></label>
        <?php if ($meta['type'] === 'textarea'): ?>
          <textarea name="<?= $key ?>" class="form-control" rows="3"><?= e($current[$key]) ?></textarea>
        <?php else: ?>
          <input type="text" name="<?= $key ?>" class="form-control" value="<?= e($current[$key]) ?>">
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="card-footer">
      <button type="submit" class="btn btn-primary">
        <?= feather_icon('save',16) ?> <?= __('btn_save') ?>
      </button>
    </div>
  </div>
</form>

<?php include __DIR__ . '/../../views/layouts/footer.php'; ?>
<script src="https://unpkg.com/feather-icons/dist/feather.min.js"></script>
<script>feather.replace();</script>
