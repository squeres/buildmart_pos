<?php
require_once __DIR__ . '/../../core/bootstrap.php';

Auth::requireLogin();

if (!Auth::mustChangePassword()) {
    redirect('/index.php');
}

$errors = [];

if (is_post()) {
    if (!csrf_verify()) {
        $errors[] = _r('err_csrf');
    } else {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['password'] ?? '';
        $confirmPassword = $_POST['password_confirm'] ?? '';

        $user = Database::row('SELECT id, password FROM users WHERE id=? LIMIT 1', [Auth::id()]);

        if (!$user || !password_verify($currentPassword, $user['password'])) {
            $errors[] = _r('auth_current_password_invalid');
        } elseif (strlen($newPassword) < 8) {
            $errors[] = _r('auth_new_password_short');
        } elseif ($newPassword !== $confirmPassword) {
            $errors[] = _r('usr_pass_mismatch');
        } else {
            Database::exec(
                'UPDATE users SET password=?, must_change_password=0, updated_at=NOW() WHERE id=?',
                [password_hash($newPassword, PASSWORD_BCRYPT), Auth::id()]
            );
            Auth::clearMustChangePassword();
            flash_success(_r('auth_password_updated'));
            redirect('/index.php');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?= Lang::current() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= __('usr_new_password') ?> - <?= __('app_name') ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@300;400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= url('assets/css/app.css') ?>">
<style>
  .login-wrap {
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--bg-base);
    padding: 20px;
  }
  .login-card {
    width: 100%;
    max-width: 400px;
    background: var(--bg-surface);
    border: 1px solid var(--border-medium);
    border-radius: var(--radius-xl);
    padding: 38px 36px;
    box-shadow: var(--shadow-xl);
  }
  .login-brand {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 32px;
  }
  .login-brand-icon {
    width: 48px;
    height: 48px;
    background: var(--amber);
    border-radius: var(--radius);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    font-weight: 700;
  }
  .login-title { font-size: 22px; font-weight: 700; margin-bottom: 4px; }
  .login-sub   { font-size: 13px; color: var(--text-muted); margin-bottom: 28px; }
</style>
</head>
<body class="app-body" style="background:var(--bg-base)">
<div class="login-wrap">
  <div class="login-card">
    <div class="login-brand">
      <div class="login-brand-icon">A</div>
      <div>
        <div class="brand-name"><?= __('app_name') ?></div>
        <div class="brand-sub"><?= __('auth_password_change_required') ?></div>
      </div>
    </div>

    <h1 class="login-title"><?= __('usr_new_password') ?></h1>
    <p class="login-sub"><?= __('auth_change_temp_password') ?></p>

    <?php foreach ($errors as $err): ?>
      <div class="flash flash-error mb-2">
        <span><?= e($err) ?></span>
      </div>
    <?php endforeach; ?>

    <form method="POST">
      <?= csrf_field() ?>

      <div class="form-group">
        <label class="form-label" for="current_password"><?= __('auth_password') ?></label>
        <input type="password" id="current_password" name="current_password" class="form-control" autocomplete="current-password" required autofocus>
      </div>

      <div class="form-group">
        <label class="form-label" for="password"><?= __('usr_new_password') ?></label>
        <input type="password" id="password" name="password" class="form-control" autocomplete="new-password" required>
      </div>

      <div class="form-group">
        <label class="form-label" for="password_confirm"><?= __('usr_confirm_pass') ?></label>
        <input type="password" id="password_confirm" name="password_confirm" class="form-control" autocomplete="new-password" required>
      </div>

      <button type="submit" class="btn btn-primary btn-block btn-lg"><?= __('btn_save') ?></button>
    </form>
  </div>
</div>
</body>
</html>
