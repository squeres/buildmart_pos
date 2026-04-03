<?php
/**
 * Warehouses — List & Edit
 * modules/warehouses/index.php
 */
require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../views/partials/icons.php';
Auth::requireLogin();
Auth::requirePerm('warehouses');

$pageTitle   = __('wh_title');
$breadcrumbs = [[$pageTitle, null]];
$errors      = [];
$editWh      = null;

if (isset($_GET['delete'])) {
    $delId = (int)$_GET['delete'];
    $inUse = Database::value("SELECT COUNT(*) FROM goods_receipts WHERE warehouse_id=?", [$delId]);
    if ($inUse) {
        flash_error(_r('err_delete_in_use'));
    } else {
        Database::exec("DELETE FROM warehouses WHERE id=?", [$delId]);
        flash_success(_r('wh_deleted'));
    }
    redirect('/modules/warehouses/');
}

if (is_post()) {
    if (!csrf_verify()) { flash_error(_r('err_csrf')); redirect($_SERVER['REQUEST_URI']); }

    $editId = (int)($_POST['edit_id'] ?? 0);
    $f = [
        'name'      => sanitize($_POST['name']    ?? ''),
        'address'   => sanitize($_POST['address'] ?? ''),
        'notes'     => sanitize($_POST['notes']   ?? ''),
        'is_active' => isset($_POST['is_active']) ? 1 : 0,
    ];
    if (!$f['name']) $errors['name'] = _r('lbl_required');

    if (!$errors) {
        if ($editId) {
            Database::exec(
                "UPDATE warehouses SET name=?,address=?,notes=?,is_active=? WHERE id=?",
                [$f['name'],$f['address'],$f['notes'],$f['is_active'],$editId]
            );
        } else {
            Database::insert(
                "INSERT INTO warehouses (name,address,notes,is_active) VALUES (?,?,?,?)",
                [$f['name'],$f['address'],$f['notes'],$f['is_active']]
            );
        }
        flash_success(_r('wh_saved'));
        redirect('/modules/warehouses/');
    }
}

$editId = (int)($_GET['edit'] ?? 0);
if ($editId) $editWh = Database::row("SELECT * FROM warehouses WHERE id=?", [$editId]);

$warehouses = Database::all(
    "SELECT w.*, (SELECT COUNT(*) FROM goods_receipts gr WHERE gr.warehouse_id=w.id) AS doc_count
     FROM warehouses w ORDER BY w.name"
);

include __DIR__ . '/../../views/layouts/header.php';
?>

<div class="page-header">
  <h1 class="page-heading"><?= __('wh_title') ?></h1>
</div>

<div style="display:grid;grid-template-columns:1fr 340px;gap:16px;align-items:start">
  <div class="card">
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th><?= __('lbl_name') ?></th>
            <th><?= __('lbl_address') ?></th>
            <th class="col-num"><?= __('wh_docs') ?></th>
            <th><?= __('lbl_status') ?></th>
            <th class="col-actions"><?= __('lbl_actions') ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($warehouses as $w): ?>
          <tr>
            <td class="fw-600"><?= e($w['name']) ?></td>
            <td><?= e($w['address'] ?? '') ?></td>
            <td class="col-num"><?= (int)$w['doc_count'] ?></td>
            <td><?= $w['is_active']
              ? '<span class="badge badge-success">'.__('lbl_active').'</span>'
              : '<span class="badge badge-secondary">'.__('lbl_inactive').'</span>' ?></td>
            <td class="col-actions">
              <a href="?edit=<?= $w['id'] ?>" class="btn btn-sm btn-ghost btn-icon"><?= feather_icon('edit-2',14) ?></a>
              <?php if ($w['doc_count'] == 0 && $w['id'] != 1): ?>
              <a href="?delete=<?= $w['id'] ?>" class="btn btn-sm btn-ghost btn-icon" style="color:var(--danger)"
                 data-confirm="<?= __('confirm_delete') ?>"><?= feather_icon('trash-2',14) ?></a>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="card-header">
      <span class="card-title"><?= $editWh ? __('wh_edit') : __('wh_add') ?></span>
      <?php if ($editWh): ?><a href="<?= url('modules/warehouses/') ?>" class="btn btn-sm btn-ghost"><?= __('btn_cancel') ?></a><?php endif; ?>
    </div>
    <div class="card-body">
      <form method="POST">
        <?= csrf_field() ?>
        <?php if ($editWh): ?><input type="hidden" name="edit_id" value="<?= $editWh['id'] ?>"><?php endif; ?>
        <div class="form-group">
          <label class="form-label"><?= __('lbl_name') ?> <span class="req">*</span></label>
          <input type="text" name="name" class="form-control" value="<?= e($editWh['name'] ?? '') ?>" required>
          <?php if (isset($errors['name'])): ?><div class="form-error"><?= e($errors['name']) ?></div><?php endif; ?>
        </div>
        <div class="form-group">
          <label class="form-label"><?= __('lbl_address') ?></label>
          <textarea name="address" class="form-control" rows="2"><?= e($editWh['address'] ?? '') ?></textarea>
        </div>
        <div class="form-group">
          <label class="form-label"><?= __('lbl_notes') ?></label>
          <textarea name="notes" class="form-control" rows="2"><?= e($editWh['notes'] ?? '') ?></textarea>
        </div>
        <div class="form-group mb-0">
          <label class="form-check">
            <input type="checkbox" name="is_active" value="1" <?= ($editWh['is_active'] ?? 1) ? 'checked' : '' ?>>
            <span class="form-check-label"><?= __('lbl_active') ?></span>
          </label>
        </div>
        <div style="margin-top:14px">
          <button type="submit" class="btn btn-primary btn-block"><?= feather_icon('save',15) ?> <?= __('btn_save') ?></button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../../views/layouts/footer.php'; ?>
<script src="https://unpkg.com/feather-icons/dist/feather.min.js"></script>
<script>
feather.replace();
document.querySelectorAll('[data-confirm]').forEach(function(el){
  el.addEventListener('click', function(e){ if(!confirm(el.dataset.confirm)) e.preventDefault(); });
});
</script>
