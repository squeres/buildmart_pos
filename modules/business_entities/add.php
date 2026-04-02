<?php
require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../views/partials/icons.php';
Auth::requireLogin();
Auth::requirePerm('settings');

$id = (int)($_GET['id'] ?? 0);
$isEdit = $id > 0;
$entity = $isEdit ? Database::row("SELECT * FROM business_entities WHERE id=?", [$id]) : null;

if ($isEdit && !$entity) {
    flash_error(_r('err_not_found'));
    redirect('/modules/business_entities/');
}

$pageTitle = $isEdit ? __('be_edit') : __('be_add');
$breadcrumbs = [[__('be_title'), url('modules/business_entities/')], [$pageTitle, null]];
$errors = [];

$f = $entity ?? [
    'name' => '',
    'legal_name' => '',
    'iin_bin' => '',
    'address' => '',
    'phone' => '',
    'email' => '',
    'responsible_name' => '',
    'responsible_position' => '',
    'released_by_name' => '',
    'chief_accountant_name' => '',
    'notes' => '',
    'is_active' => 1,
];

if (is_post()) {
    if (!csrf_verify()) {
        flash_error(_r('err_csrf'));
        redirect($_SERVER['REQUEST_URI']);
    }

    $f = [
        'name' => sanitize($_POST['name'] ?? ''),
        'legal_name' => sanitize($_POST['legal_name'] ?? ''),
        'iin_bin' => sanitize($_POST['iin_bin'] ?? ''),
        'address' => sanitize($_POST['address'] ?? ''),
        'phone' => sanitize($_POST['phone'] ?? ''),
        'email' => sanitize($_POST['email'] ?? ''),
        'responsible_name' => sanitize($_POST['responsible_name'] ?? ''),
        'responsible_position' => sanitize($_POST['responsible_position'] ?? ''),
        'released_by_name' => sanitize($_POST['released_by_name'] ?? ''),
        'chief_accountant_name' => sanitize($_POST['chief_accountant_name'] ?? ''),
        'notes' => sanitize($_POST['notes'] ?? ''),
        'is_active' => isset($_POST['is_active']) ? 1 : 0,
    ];

    if ($f['name'] === '') {
        $errors['name'] = _r('lbl_required');
    }

    if (!$errors) {
        if ($isEdit) {
            Database::exec(
                "UPDATE business_entities
                 SET name=?, legal_name=?, iin_bin=?, address=?, phone=?, email=?,
                     responsible_name=?, responsible_position=?, released_by_name=?,
                     chief_accountant_name=?, notes=?, is_active=?, updated_at=NOW()
                 WHERE id=?",
                [
                    $f['name'],
                    $f['legal_name'],
                    $f['iin_bin'],
                    $f['address'],
                    $f['phone'],
                    $f['email'],
                    $f['responsible_name'],
                    $f['responsible_position'],
                    $f['released_by_name'],
                    $f['chief_accountant_name'],
                    $f['notes'],
                    $f['is_active'],
                    $id,
                ]
            );
        } else {
            $id = Database::insert(
                "INSERT INTO business_entities (
                    name, legal_name, iin_bin, address, phone, email,
                    responsible_name, responsible_position, released_by_name,
                    chief_accountant_name, notes, is_active
                 ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
                [
                    $f['name'],
                    $f['legal_name'],
                    $f['iin_bin'],
                    $f['address'],
                    $f['phone'],
                    $f['email'],
                    $f['responsible_name'],
                    $f['responsible_position'],
                    $f['released_by_name'],
                    $f['chief_accountant_name'],
                    $f['notes'],
                    $f['is_active'],
                ]
            );
        }

        flash_success(_r('be_saved'));
        redirect('/modules/business_entities/view.php?id=' . $id);
    }
}

include __DIR__ . '/../../views/layouts/header.php';
?>

<div class="page-header">
  <h1 class="page-heading"><?= e($pageTitle) ?></h1>
</div>

<div style="max-width:980px">
  <form method="POST">
    <?= csrf_field() ?>

    <?php if ($errors): ?>
      <div class="flash flash-error mb-2">
        <?= feather_icon('alert-circle', 15) ?>
        <span><?= __('err_validation') ?></span>
      </div>
    <?php endif; ?>

    <div class="card">
      <div class="card-body">
        <div class="form-row form-row-2">
          <div class="form-group">
            <label class="form-label"><?= __('lbl_name') ?> <span class="req">*</span></label>
            <input type="text" name="name" class="form-control" value="<?= e($f['name']) ?>" required>
            <?php if (isset($errors['name'])): ?>
              <div class="form-error"><?= e($errors['name']) ?></div>
            <?php endif; ?>
          </div>
          <div class="form-group">
            <label class="form-label"><?= __('be_legal_name') ?></label>
            <input type="text" name="legal_name" class="form-control" value="<?= e($f['legal_name']) ?>">
          </div>
        </div>

        <div class="form-row form-row-2">
          <div class="form-group">
            <label class="form-label"><?= __('cust_inn') ?></label>
            <input type="text" name="iin_bin" class="form-control mono" value="<?= e($f['iin_bin']) ?>">
          </div>
          <div class="form-group">
            <label class="form-label"><?= __('lbl_phone') ?></label>
            <input type="tel" name="phone" class="form-control" value="<?= e($f['phone']) ?>">
          </div>
        </div>

        <div class="form-row form-row-2">
          <div class="form-group">
            <label class="form-label"><?= __('lbl_email') ?></label>
            <input type="email" name="email" class="form-control" value="<?= e($f['email']) ?>">
          </div>
          <div class="form-group">
            <label class="form-label"><?= __('lbl_address') ?></label>
            <textarea name="address" class="form-control" rows="2"><?= e($f['address']) ?></textarea>
          </div>
        </div>

        <div class="form-row form-row-2">
          <div class="form-group">
            <label class="form-label"><?= __('be_responsible_name') ?></label>
            <input type="text" name="responsible_name" class="form-control" value="<?= e($f['responsible_name']) ?>">
          </div>
          <div class="form-group">
            <label class="form-label"><?= __('be_responsible_position') ?></label>
            <input type="text" name="responsible_position" class="form-control" value="<?= e($f['responsible_position']) ?>">
          </div>
        </div>

        <div class="form-row form-row-2">
          <div class="form-group">
            <label class="form-label"><?= __('be_released_by_name') ?></label>
            <input type="text" name="released_by_name" class="form-control" value="<?= e($f['released_by_name']) ?>">
          </div>
          <div class="form-group">
            <label class="form-label"><?= __('be_chief_accountant_name') ?></label>
            <input type="text" name="chief_accountant_name" class="form-control" value="<?= e($f['chief_accountant_name']) ?>">
          </div>
        </div>

        <div class="form-group">
          <label class="form-label"><?= __('lbl_notes') ?></label>
          <textarea name="notes" class="form-control" rows="3"><?= e($f['notes']) ?></textarea>
        </div>

        <div class="form-group mb-0">
          <label class="form-check">
            <input type="checkbox" name="is_active" value="1" <?= !empty($f['is_active']) ? 'checked' : '' ?>>
            <span class="form-check-label"><?= __('lbl_active') ?></span>
          </label>
        </div>
      </div>

      <div class="card-footer" style="display:flex;gap:8px">
        <button type="submit" class="btn btn-primary btn-lg">
          <?= feather_icon('save', 17) ?> <?= __('btn_save') ?>
        </button>
        <a href="<?= url('modules/business_entities/') ?>" class="btn btn-ghost btn-lg"><?= __('btn_cancel') ?></a>
      </div>
    </div>
  </form>
</div>

<?php include __DIR__ . '/../../views/layouts/footer.php'; ?>
<script src="https://unpkg.com/feather-icons/dist/feather.min.js"></script>
<script>feather.replace();</script>
