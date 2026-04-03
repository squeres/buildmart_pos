<?php
require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../views/partials/icons.php';

Auth::requireLogin();
Auth::requirePerm('all');

$id = (int)($_GET['id'] ?? 0);
$isEdit = $id > 0;
$user = $isEdit ? Database::row('SELECT * FROM users WHERE id=?', [$id]) : null;
$hasLanguageSetAt = shift_schema_has_column('users', 'language_set_at');

if ($isEdit && !$user) {
    flash_error(_r('err_not_found'));
    redirect('/modules/users/');
}

$pageTitle = $isEdit ? __('usr_edit') : __('usr_add');
$breadcrumbs = [[__('usr_title'), url('modules/users/')], [$pageTitle, null]];
$errors = [];
$defaultLanguage = DEFAULT_LANG;
$defaultWarehouseId = (int)($user['default_warehouse_id'] ?? 1);

$f = $user ?? [
    'name' => '',
    'email' => '',
    'phone' => '',
    'role_id' => 3,
    'language' => $defaultLanguage,
    'is_active' => 1,
    'pin' => '',
    'default_warehouse_id' => 1,
];
$f['pin'] = '';
$f['language_preference'] = $isEdit
    ? (($hasLanguageSetAt && empty($user['language_set_at'])) ? '' : (Lang::normalizeCode($user['language'] ?? null) ?? $defaultLanguage))
    : '';

if (is_post()) {
    if (!csrf_verify()) {
        flash_error(_r('err_csrf'));
        redirect($_SERVER['REQUEST_URI']);
    }

    $submittedLanguage = sanitize($_POST['language'] ?? '');
    $normalizedLanguage = Lang::normalizeCode($submittedLanguage);
    $languageSetAt = null;

    if ($submittedLanguage !== '' && $normalizedLanguage === null) {
        $errors['language'] = _r('profile_language_invalid');
    }

    if ($normalizedLanguage !== null) {
        $languageSetAt = date('Y-m-d H:i:s');
    }

    $f = [
        'name' => sanitize($_POST['name'] ?? ''),
        'email' => sanitize($_POST['email'] ?? ''),
        'phone' => sanitize($_POST['phone'] ?? ''),
        'role_id' => (int)($_POST['role_id'] ?? 3),
        'language' => $normalizedLanguage ?? $defaultLanguage,
        'language_preference' => $normalizedLanguage ?? '',
        'is_active' => isset($_POST['is_active']) ? 1 : 0,
        'pin' => sanitize($_POST['pin'] ?? ''),
        'default_warehouse_id' => (int)($_POST['default_warehouse_id'] ?? $defaultWarehouseId),
    ];
    $pass = $_POST['password'] ?? '';
    $pass2 = $_POST['password_confirm'] ?? '';

    if ($f['name'] === '') {
        $errors['name'] = _r('lbl_required');
    }

    if ($f['email'] === '') {
        $errors['email'] = _r('lbl_required');
    } elseif (!filter_var($f['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = _r('auth_invalid_email');
    }

    if (!$isEdit && $pass === '') {
        $errors['password'] = _r('lbl_required');
    } elseif ($pass !== '' && mb_strlen($pass, 'UTF-8') < AuthService::PASSWORD_MIN_LENGTH) {
        $errors['password'] = _r('auth_new_password_short', ['min' => (string)AuthService::PASSWORD_MIN_LENGTH]);
    } elseif ($pass !== '' && $pass !== $pass2) {
        $errors['password'] = _r('usr_pass_mismatch');
    }

    $emailExists = Database::value('SELECT id FROM users WHERE email=? AND id!=?', [$f['email'], $id]);
    if ($emailExists) {
        $errors['email'] = _r('auth_email_in_use');
    }

    if (!$errors) {
        try {
            $pinStorage = AuthService::preparePinStorage($f['pin']);
            AuthService::assertPinAvailable($f['pin'], $isEdit ? $id : 0);
        } catch (AppServiceException $e) {
            $errors['pin'] = $e->getMessage();
        }
    }

    if (!$errors) {
        if ($isEdit) {
            $fields = ['name=?', 'email=?', 'phone=?', 'role_id=?', 'language=?', 'is_active=?', 'default_warehouse_id=?'];
            $params = [
                $f['name'],
                $f['email'],
                $f['phone'] !== '' ? $f['phone'] : null,
                $f['role_id'],
                $f['language'],
                $f['is_active'],
                $f['default_warehouse_id'],
            ];

            if ($hasLanguageSetAt) {
                $fields[] = 'language_set_at=?';
                $params[] = $languageSetAt;
            }

            if (AuthService::hasPinHashColumn()) {
                $fields[] = 'pin=?';
                $fields[] = 'pin_hash=?';
                $params[] = $pinStorage['pin'];
                $params[] = $pinStorage['pin_hash'];
            } else {
                $fields[] = 'pin=?';
                $params[] = $pinStorage['pin'];
            }

            if ($pass !== '') {
                $fields[] = 'password=?';
                $fields[] = 'must_change_password=0';
                $params[] = AuthService::hashPassword($pass);
            }

            $params[] = $id;

            Database::exec(
                'UPDATE users SET ' . implode(', ', $fields) . ', updated_at=NOW() WHERE id=?',
                $params
            );

            $userId = $id;
        } else {
            $columns = ['name', 'email', 'phone', 'password', 'role_id', 'language', 'is_active', 'default_warehouse_id', 'must_change_password'];
            $placeholders = ['?', '?', '?', '?', '?', '?', '?', '?', '0'];
            $params = [
                $f['name'],
                $f['email'],
                $f['phone'] !== '' ? $f['phone'] : null,
                AuthService::hashPassword($pass),
                $f['role_id'],
                $f['language'],
                $f['is_active'],
                $f['default_warehouse_id'],
            ];

            if ($hasLanguageSetAt) {
                $columns[] = 'language_set_at';
                $placeholders[] = '?';
                $params[] = $languageSetAt;
            }

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

            $userId = (int)Database::insert(
                'INSERT INTO users (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')',
                $params
            );
        }

        $whIds = array_values(array_filter(array_map('intval', $_POST['warehouse_ids'] ?? []), static fn(int $wid): bool => $wid > 0));
        Database::exec('DELETE FROM warehouse_user_access WHERE user_id=?', [$userId]);
        foreach ($whIds as $wid) {
            Database::exec(
                'INSERT IGNORE INTO warehouse_user_access (user_id, warehouse_id) VALUES (?, ?)',
                [$userId, $wid]
            );
        }

        if ($isEdit && $id === Auth::id()) {
            Auth::refreshCurrentUser();
        }

        flash_success(_r('usr_saved'));
        redirect('/modules/users/');
    }
}

$roles = Database::all('SELECT id, name FROM roles ORDER BY id');
$warehouses = Database::all('SELECT id, name FROM warehouses WHERE is_active=1 ORDER BY name');
$userWhIds = $isEdit
    ? array_column(Database::all('SELECT warehouse_id FROM warehouse_user_access WHERE user_id=?', [$id]), 'warehouse_id')
    : array_column($warehouses, 'id');

include __DIR__ . '/../../views/layouts/header.php';
?>

<div class="page-header">
  <h1 class="page-heading"><?= $isEdit ? __('usr_edit') : __('usr_add') ?></h1>
</div>

<div style="max-width:760px">
  <form method="POST">
    <?= csrf_field() ?>

    <?php if ($errors): ?>
      <div class="flash flash-error mb-2">
        <?= feather_icon('alert-circle', 15) ?>
        <span><?= __('err_validation') ?></span>
      </div>
    <?php endif; ?>

    <div class="card">
      <div class="card-header">
        <span class="card-title"><?= __('lbl_details') ?></span>
      </div>
      <div class="card-body">
        <div class="form-group">
          <label class="form-label"><?= __('lbl_name') ?> <span class="req">*</span></label>
          <input type="text" name="name" class="form-control" value="<?= e($f['name']) ?>" autocomplete="name" required>
          <?php if (isset($errors['name'])): ?><div class="form-error"><?= e($errors['name']) ?></div><?php endif; ?>
        </div>

        <div class="form-row form-row-2">
          <div class="form-group">
            <label class="form-label"><?= __('lbl_email') ?> <span class="req">*</span></label>
            <input type="email" name="email" class="form-control" value="<?= e($f['email']) ?>" autocomplete="email" required>
            <?php if (isset($errors['email'])): ?><div class="form-error"><?= e($errors['email']) ?></div><?php endif; ?>
          </div>
          <div class="form-group">
            <label class="form-label"><?= __('lbl_phone') ?></label>
            <input type="text" name="phone" class="form-control" value="<?= e($f['phone'] ?? '') ?>" autocomplete="tel">
          </div>
        </div>

        <div class="form-row form-row-2">
          <div class="form-group">
            <label class="form-label"><?= __('usr_new_password') ?><?= !$isEdit ? ' <span class="req">*</span>' : '' ?></label>
            <input type="password" name="password" class="form-control" autocomplete="new-password" <?= !$isEdit ? 'required' : '' ?>>
            <div class="form-hint"><?= __('auth_password_hint', ['min' => (string)AuthService::PASSWORD_MIN_LENGTH]) ?></div>
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
              <?php foreach ($roles as $role): ?>
                <option value="<?= (int)$role['id'] ?>" <?= (int)$f['role_id'] === (int)$role['id'] ? 'selected' : '' ?>>
                  <?= e($role['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group">
            <label class="form-label"><?= __('lbl_language') ?></label>
            <select name="language" class="form-control">
              <option value="" <?= $f['language_preference'] === '' ? 'selected' : '' ?>>
                <?= __('usr_language_default_option', ['language' => language_label($defaultLanguage)]) ?>
              </option>
              <?php foreach (SUPPORTED_LANGS as $code => $label): ?>
                <option value="<?= e($code) ?>" <?= $f['language_preference'] === $code ? 'selected' : '' ?>>
                  <?= e($label) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <div class="form-hint"><?= __('usr_language_default_hint') ?></div>
            <?php if (isset($errors['language'])): ?><div class="form-error"><?= e($errors['language']) ?></div><?php endif; ?>
          </div>

          <div class="form-group">
            <label class="form-label"><?= __('usr_pin') ?></label>
            <input type="text" name="pin" class="form-control mono" maxlength="6" value="<?= e($f['pin']) ?>" placeholder="1234" inputmode="numeric">
            <?php if (isset($errors['pin'])): ?><div class="form-error"><?= e($errors['pin']) ?></div><?php endif; ?>
            <div class="form-hint"><?= $isEdit ? __('usr_pin_clear_hint') : __('usr_pin_hint') ?></div>
          </div>
        </div>

        <label class="form-check">
          <input type="checkbox" name="is_active" value="1" <?= $f['is_active'] ? 'checked' : '' ?>>
          <span class="form-check-label"><?= __('lbl_active') ?></span>
        </label>
      </div>
    </div>

    <div class="card mt-2">
      <div class="card-header">
        <span class="card-title"><?= __('wh_user_access') ?></span>
      </div>
      <div class="card-body">
        <div class="form-group">
          <label class="form-label"><?= __('wh_default_wh') ?></label>
          <select name="default_warehouse_id" class="form-control" style="max-width:320px">
            <?php foreach ($warehouses as $warehouse): ?>
              <option value="<?= (int)$warehouse['id'] ?>" <?= (int)($f['default_warehouse_id'] ?? 1) === (int)$warehouse['id'] ? 'selected' : '' ?>>
                <?= e($warehouse['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group mb-0">
          <label class="form-label"><?= __('wh_user_access') ?></label>
          <div style="display:flex;flex-direction:column;gap:6px;margin-top:4px">
            <?php foreach ($warehouses as $warehouse): ?>
              <label class="form-check">
                <input type="checkbox" name="warehouse_ids[]" value="<?= (int)$warehouse['id'] ?>" <?= in_array((int)$warehouse['id'], array_map('intval', $userWhIds), true) ? 'checked' : '' ?>>
                <span class="form-check-label"><?= e($warehouse['name']) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
      <div class="card-footer" style="display:flex;gap:8px">
        <button type="submit" class="btn btn-primary btn-lg"><?= feather_icon('save', 17) ?> <?= __('btn_save') ?></button>
        <a href="<?= url('modules/users/') ?>" class="btn btn-ghost btn-lg"><?= __('btn_cancel') ?></a>
      </div>
    </div>
  </form>
</div>

<?php include __DIR__ . '/../../views/layouts/footer.php'; ?>
