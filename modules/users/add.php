<?php
require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../views/partials/icons.php';
Auth::requireLogin();
Auth::requirePerm('all');

$id     = (int)($_GET['id'] ?? 0);
$isEdit = $id > 0;
$user   = $isEdit ? Database::row("SELECT * FROM users WHERE id=?",[$id]) : null;

if ($isEdit && !$user) { flash_error(_r('err_not_found')); redirect('/modules/users/'); }

$pageTitle   = $isEdit ? __('usr_edit') : __('usr_add');
$breadcrumbs = [[__('usr_title'), url('modules/users/')], [$pageTitle, null]];
$errors      = [];
$f = $user ?? ['name'=>'','email'=>'','role_id'=>3,'language'=>'en','is_active'=>1,'pin'=>''];
$f['pin'] = '';

if (is_post()) {
    if (!csrf_verify()) { flash_error(_r('err_csrf')); redirect($_SERVER['REQUEST_URI']); }

    $f = [
        'name'      => sanitize($_POST['name']     ?? ''),
        'email'     => sanitize($_POST['email']    ?? ''),
        'role_id'   => (int)($_POST['role_id']     ?? 3),
        'language'  => sanitize($_POST['language'] ?? 'en'),
        'is_active' => isset($_POST['is_active']) ? 1 : 0,
        'pin'                => sanitize($_POST['pin']      ?? ''),
        'default_warehouse_id' => (int)($_POST['default_warehouse_id'] ?? 1),
    ];
    $pass  = $_POST['password']         ?? '';
    $pass2 = $_POST['password_confirm'] ?? '';

    if (!$f['name'])  $errors['name']  = _r('lbl_required');
    if (!$f['email']) $errors['email'] = _r('lbl_required');
    if (!$isEdit && !$pass) $errors['password'] = _r('lbl_required');
    if ($pass && $pass !== $pass2) $errors['password'] = _r('usr_pass_mismatch');

    $emailExists = Database::value("SELECT id FROM users WHERE email=? AND id!=?",[$f['email'],$id]);
    if ($emailExists) $errors['email'] = 'Email already in use';

    if (!$errors) {
        try {
            $pinStorage = AuthService::preparePinStorage($f['pin']);
        } catch (AppServiceException $e) {
            $errors['pin'] = $e->getMessage();
        }
    }

    if (!$errors) {
        if ($isEdit) {
            $fields = ['name=?','email=?','role_id=?','language=?','is_active=?','default_warehouse_id=?'];
            $params = [$f['name'],$f['email'],$f['role_id'],$f['language'],$f['is_active'],$f['default_warehouse_id']];
            if (AuthService::hasPinHashColumn()) {
                $fields[] = 'pin=?';
                $fields[] = 'pin_hash=?';
                $params[] = $pinStorage['pin'];
                $params[] = $pinStorage['pin_hash'];
            } else {
                $fields[] = 'pin=?';
                $params[] = $pinStorage['pin'];
            }
            if ($pass) {
                $fields[] = 'password=?';
                $fields[] = 'must_change_password=0';
                $params[] = password_hash($pass,PASSWORD_BCRYPT);
            }
            $params[] = $id;
            Database::exec("UPDATE users SET " . implode(',', $fields) . ",updated_at=NOW() WHERE id=?", $params);
            // Update warehouse access
            $whIds = array_map('intval', $_POST['warehouse_ids'] ?? []);
            Database::exec("DELETE FROM warehouse_user_access WHERE user_id=?", [$id]);
            foreach ($whIds as $wid) {
                if ($wid > 0) Database::exec(
                    "INSERT IGNORE INTO warehouse_user_access (user_id, warehouse_id) VALUES (?,?)",
                    [$id, $wid]
                );
            }
        } else {
            $columns = ['name','email','password','role_id','language','is_active','default_warehouse_id','must_change_password'];
            $placeholders = ['?','?','?','?','?','?','?','0'];
            $params = [$f['name'],$f['email'],password_hash($pass,PASSWORD_BCRYPT),$f['role_id'],$f['language'],$f['is_active'],$f['default_warehouse_id']];
            if (AuthService::hasPinHashColumn()) {
                $columns[] = 'pin';
                $columns[] = 'pin_hash';
                $placeholders[] = '?';
                $placeholders[] = '?';
                $params[] = $pinStorage['pin'];
                $params[] = $pinStorage['pin_hash'];
            } else {
                $columns[] = 'pin';
                $placeholders[] = '?';
                $params[] = $pinStorage['pin'];
            }
            $newId = Database::insert(
                "INSERT INTO users (" . implode(',', $columns) . ") VALUES (" . implode(',', $placeholders) . ")",
                $params
            );
            $whIds = array_map('intval', $_POST['warehouse_ids'] ?? []);
            foreach ($whIds as $wid) {
                if ($wid > 0) Database::exec(
                    "INSERT IGNORE INTO warehouse_user_access (user_id, warehouse_id) VALUES (?,?)",
                    [$newId, $wid]
                );
            }
        }
        flash_success(_r('usr_saved'));
        redirect('/modules/users/');
    }
}

$roles      = Database::all("SELECT id,name FROM roles ORDER BY id");
$warehouses = Database::all("SELECT id,name FROM warehouses WHERE is_active=1 ORDER BY name");
$userWhIds  = $isEdit
    ? array_column(Database::all("SELECT warehouse_id FROM warehouse_user_access WHERE user_id=?", [$id]), 'warehouse_id')
    : array_column($warehouses, 'id'); // new users get all by default

include __DIR__ . '/../../views/layouts/header.php';
?>

<div class="page-header"><h1 class="page-heading"><?= $isEdit?__('usr_edit'):__('usr_add') ?></h1></div>
<div style="max-width:540px">
<form method="POST">
  <?= csrf_field() ?>
  <?php if ($errors): ?><div class="flash flash-error mb-2"><?= feather_icon('alert-circle',15) ?><span><?= __('err_validation') ?></span></div><?php endif; ?>
  <div class="card">
    <div class="card-body">
      <div class="form-group">
        <label class="form-label"><?= __('lbl_name') ?> <span class="req">*</span></label>
        <input type="text" name="name" class="form-control" value="<?= e($f['name']) ?>" required>
        <?php if (isset($errors['name'])): ?><div class="form-error"><?= e($errors['name']) ?></div><?php endif; ?>
      </div>
      <div class="form-group">
        <label class="form-label"><?= __('lbl_email') ?> <span class="req">*</span></label>
        <input type="email" name="email" class="form-control" value="<?= e($f['email']) ?>" required>
        <?php if (isset($errors['email'])): ?><div class="form-error"><?= e($errors['email']) ?></div><?php endif; ?>
      </div>
      <div class="form-row form-row-2">
        <div class="form-group">
          <label class="form-label"><?= __('usr_new_password') ?> <?= !$isEdit?'<span class="req">*</span>':'' ?></label>
          <input type="password" name="password" class="form-control" autocomplete="new-password" <?= !$isEdit?'required':'' ?>>
          <?php if (isset($errors['password'])): ?><div class="form-error"><?= e($errors['password']) ?></div><?php endif; ?>
        </div>
        <div class="form-group">
          <label class="form-label"><?= __('usr_confirm_pass') ?></label>
          <input type="password" name="password_confirm" class="form-control" autocomplete="new-password">
        </div>
      </div>
      <div class="form-row form-row-3">
        <div class="form-group">
          <label class="form-label"><?= __('lbl_role') ?></label>
          <select name="role_id" class="form-control">
            <?php foreach ($roles as $r): ?>
              <option value="<?= $r['id'] ?>" <?= $f['role_id']==$r['id']?'selected':'' ?>><?= e($r['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label"><?= __('lbl_language') ?></label>
          <select name="language" class="form-control">
            <?php foreach (SUPPORTED_LANGS as $code=>$label): ?>
              <option value="<?= $code ?>" <?= $f['language']===$code?'selected':'' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label"><?= __('usr_pin') ?></label>
          <input type="text" name="pin" class="form-control mono" maxlength="6" value="<?= e($f['pin']??'') ?>" placeholder="••••">
          <?php if (isset($errors['pin'])): ?><div class="form-error"><?= e($errors['pin']) ?></div><?php endif; ?>
          <?php if ($isEdit): ?><div class="form-hint"><?= e('Оставьте пустым, чтобы отключить PIN и не показывать старое значение.') ?></div><?php endif; ?>
        </div>
      </div>
      <label class="form-check">
        <input type="checkbox" name="is_active" value="1" <?= $f['is_active']?'checked':'' ?>>
        <span class="form-check-label"><?= __('lbl_active') ?></span>
      </label>
    </div>
  </div>

  <!-- Warehouse access card -->
  <div class="card" style="margin-top:16px">
    <div class="card-header">
      <span class="card-title"><?= __('wh_user_access') ?></span>
    </div>
    <div class="card-body">
      <div class="form-group mb-0">
        <label class="form-label"><?= __('wh_default_wh') ?></label>
        <select name="default_warehouse_id" class="form-control" style="max-width:280px">
          <?php foreach ($warehouses as $w): ?>
            <option value="<?= $w['id'] ?>"
              <?= ($f['default_warehouse_id'] ?? 1) == $w['id'] ? 'selected' : '' ?>>
              <?= e($w['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group mb-0" style="margin-top:14px">
        <label class="form-label"><?= __('wh_user_access') ?></label>
        <div style="display:flex;flex-direction:column;gap:6px;margin-top:4px">
          <?php foreach ($warehouses as $w): ?>
            <label class="form-check">
              <input type="checkbox" name="warehouse_ids[]" value="<?= $w['id'] ?>"
                <?= in_array($w['id'], $userWhIds) ? 'checked' : '' ?>>
              <span class="form-check-label"><?= e($w['name']) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <div class="card-footer" style="display:flex;gap:8px">
      <button type="submit" class="btn btn-primary btn-lg"><?= feather_icon('save',17) ?> <?= __('btn_save') ?></button>
      <a href="<?= url('modules/users/') ?>" class="btn btn-ghost btn-lg"><?= __('btn_cancel') ?></a>
    </div>
  </div>
</form>
</div>

<?php include __DIR__ . '/../../views/layouts/footer.php'; ?>
<script src="https://unpkg.com/feather-icons/dist/feather.min.js"></script>
<script>feather.replace();</script>
