<?php
/**
 * Suppliers — List & Edit
 * modules/suppliers/index.php
 */
require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../views/partials/icons.php';
Auth::requireLogin();
Auth::requirePerm('suppliers');

$pageTitle   = __('sup_title');
$breadcrumbs = [[$pageTitle, null]];
$errors      = [];
$editSup     = null;

// ── Handle DELETE ──────────────────────────────────────────────
if (isset($_GET['delete'])) {
    $delId  = (int)$_GET['delete'];
    $inUse  = Database::value("SELECT COUNT(*) FROM goods_receipts WHERE supplier_id=?", [$delId]);
    if ($inUse) {
        flash_error(_r('err_delete_in_use'));
    } else {
        Database::exec("DELETE FROM suppliers WHERE id=?", [$delId]);
        flash_success(_r('sup_deleted'));
    }
    redirect('/modules/suppliers/');
}

// ── Handle POST (add/edit) ─────────────────────────────────────
if (is_post()) {
    if (!csrf_verify()) { flash_error(_r('err_csrf')); redirect($_SERVER['REQUEST_URI']); }

    $editId  = (int)($_POST['edit_id'] ?? 0);
    $f = [
        'name'         => sanitize($_POST['name']         ?? ''),
        'contact'      => sanitize($_POST['contact']      ?? ''),
        'phone'        => sanitize($_POST['phone']        ?? ''),
        'email'        => sanitize($_POST['email']        ?? ''),
        'address'      => sanitize($_POST['address']      ?? ''),
        'inn'          => sanitize($_POST['inn']          ?? ''),
        'bank_details' => sanitize($_POST['bank_details'] ?? ''),
        'notes'        => sanitize($_POST['notes']        ?? ''),
        'is_active'    => isset($_POST['is_active']) ? 1 : 0,
    ];

    if (!$f['name']) $errors['name'] = _r('lbl_required');

    if (!$errors) {
        if ($editId) {
            Database::exec(
                "UPDATE suppliers SET name=?,contact=?,phone=?,email=?,address=?,inn=?,bank_details=?,notes=?,is_active=?,updated_at=NOW() WHERE id=?",
                [$f['name'],$f['contact'],$f['phone'],$f['email'],$f['address'],$f['inn'],$f['bank_details'],$f['notes'],$f['is_active'],$editId]
            );
        } else {
            Database::insert(
                "INSERT INTO suppliers (name,contact,phone,email,address,inn,bank_details,notes,is_active) VALUES (?,?,?,?,?,?,?,?,?)",
                [$f['name'],$f['contact'],$f['phone'],$f['email'],$f['address'],$f['inn'],$f['bank_details'],$f['notes'],$f['is_active']]
            );
        }
        flash_success(_r('sup_saved'));
        redirect('/modules/suppliers/');
    }
}

// ── Load for edit ──────────────────────────────────────────────
$editId = (int)($_GET['edit'] ?? 0);
if ($editId) $editSup = Database::row("SELECT * FROM suppliers WHERE id=?", [$editId]);

$suppliers = Database::all(
    "SELECT s.*, (SELECT COUNT(*) FROM goods_receipts gr WHERE gr.supplier_id=s.id) AS doc_count
     FROM suppliers s ORDER BY s.name"
);

include __DIR__ . '/../../views/layouts/header.php';
?>

<div class="page-header">
  <h1 class="page-heading"><?= __('sup_title') ?></h1>
</div>

<div style="display:grid;grid-template-columns:1fr 380px;gap:16px;align-items:start">

  <!-- List -->
  <div class="card">
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th><?= __('lbl_name') ?></th>
            <th><?= __('lbl_phone') ?></th>
            <th><?= __('sup_inn') ?></th>
            <th class="col-num"><?= __('sup_docs') ?></th>
            <th><?= __('lbl_status') ?></th>
            <th class="col-actions"><?= __('lbl_actions') ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($suppliers as $s): ?>
          <tr>
            <td>
              <div class="fw-600"><?= e($s['name']) ?></div>
              <?php if ($s['contact']): ?><div class="text-muted" style="font-size:11px"><?= e($s['contact']) ?></div><?php endif; ?>
            </td>
            <td><?= e($s['phone'] ?? '') ?></td>
            <td class="font-mono"><?= e($s['inn'] ?? '') ?></td>
            <td class="col-num"><?= (int)$s['doc_count'] ?></td>
            <td>
              <?= $s['is_active']
                ? '<span class="badge badge-success">'.__('lbl_active').'</span>'
                : '<span class="badge badge-secondary">'.__('lbl_inactive').'</span>' ?>
            </td>
            <td class="col-actions">
              <a href="?edit=<?= $s['id'] ?>" class="btn btn-sm btn-ghost btn-icon"><?= feather_icon('edit-2',14) ?></a>
              <?php if ($s['doc_count'] == 0): ?>
              <a href="?delete=<?= $s['id'] ?>" class="btn btn-sm btn-ghost btn-icon" style="color:var(--danger)"
                 data-confirm="<?= __('confirm_delete') ?>"><?= feather_icon('trash-2',14) ?></a>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$suppliers): ?>
          <tr><td colspan="6" class="text-center text-muted" style="padding:24px"><?= __('no_results') ?></td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Add/Edit form -->
  <div class="card">
    <div class="card-header">
      <span class="card-title"><?= $editSup ? __('sup_edit') : __('sup_add') ?></span>
      <?php if ($editSup): ?>
        <a href="<?= url('modules/suppliers/') ?>" class="btn btn-sm btn-ghost"><?= __('btn_cancel') ?></a>
      <?php endif; ?>
    </div>
    <div class="card-body">
      <form method="POST">
        <?= csrf_field() ?>
        <?php if ($editSup): ?><input type="hidden" name="edit_id" value="<?= $editSup['id'] ?>"><?php endif; ?>

        <div class="form-group">
          <label class="form-label"><?= __('lbl_name') ?> <span class="req">*</span></label>
          <input type="text" name="name" class="form-control" value="<?= e($editSup['name'] ?? '') ?>" required>
          <?php if (isset($errors['name'])): ?><div class="form-error"><?= e($errors['name']) ?></div><?php endif; ?>
        </div>
        <div class="form-row form-row-2">
          <div class="form-group">
            <label class="form-label"><?= __('sup_contact') ?></label>
            <input type="text" name="contact" class="form-control" value="<?= e($editSup['contact'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label class="form-label"><?= __('lbl_phone') ?></label>
            <input type="tel" name="phone" class="form-control" value="<?= e($editSup['phone'] ?? '') ?>">
          </div>
        </div>
        <div class="form-row form-row-2">
          <div class="form-group">
            <label class="form-label"><?= __('lbl_email') ?></label>
            <input type="email" name="email" class="form-control" value="<?= e($editSup['email'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label class="form-label"><?= __('sup_inn') ?></label>
            <input type="text" name="inn" class="form-control font-mono" value="<?= e($editSup['inn'] ?? '') ?>">
          </div>
        </div>
        <div class="form-group">
          <label class="form-label"><?= __('lbl_address') ?></label>
          <textarea name="address" class="form-control" rows="2"><?= e($editSup['address'] ?? '') ?></textarea>
        </div>
        <div class="form-group">
          <label class="form-label"><?= __('sup_bank_details') ?></label>
          <textarea name="bank_details" class="form-control" rows="2"><?= e($editSup['bank_details'] ?? '') ?></textarea>
        </div>
        <div class="form-group">
          <label class="form-label"><?= __('lbl_notes') ?></label>
          <textarea name="notes" class="form-control" rows="2"><?= e($editSup['notes'] ?? '') ?></textarea>
        </div>
        <div class="form-group mb-0">
          <label class="form-check">
            <input type="checkbox" name="is_active" value="1" <?= ($editSup['is_active'] ?? 1) ? 'checked' : '' ?>>
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
