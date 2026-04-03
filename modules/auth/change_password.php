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
        try {
            AuthService::changePassword(
                Auth::id(),
                $_POST['current_password'] ?? '',
                $_POST['password'] ?? '',
                $_POST['password_confirm'] ?? '',
                true
            );
            Auth::clearMustChangePassword();
            flash_success(_r('auth_password_updated'));
            redirect('/index.php');
        } catch (AppServiceException $e) {
            $errors[] = $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?= Lang::current() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= __('usr_new_password') ?> &mdash; <?= __('app_name') ?></title>
<?= app_favicon_links() ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@300;400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= url('assets/css/app.css') ?>">
</head>
<body class="app-body">
  <div class="auth-shell">
    <div class="auth-card">
      <div class="auth-brand" aria-label="<?= __('app_name') ?>">
        <span class="auth-brand-mark" aria-hidden="true">
          <img src="<?= e(APP_ICON_URL) ?>" alt="" class="brand-mark-image">
        </span>
        <div class="auth-brand-copy">
          <div class="auth-brand-name"><?= __('app_name') ?></div>
          <div class="auth-brand-sub"><?= __('app_tagline') ?></div>
        </div>
      </div>

      <h1 class="auth-heading"><?= __('usr_new_password') ?></h1>
      <p class="auth-subtitle"><?= __('auth_change_temp_password') ?></p>

      <?php foreach ($errors as $err): ?>
        <div class="flash flash-error mb-2" style="margin:0 0 12px">
          <span><?= e($err) ?></span>
        </div>
      <?php endforeach; ?>

      <form method="POST" class="auth-actions">
        <?= csrf_field() ?>

        <div class="form-group mb-0">
          <label class="form-label" for="current_password"><?= __('profile_current_password') ?></label>
          <input type="password" id="current_password" name="current_password" class="form-control" autocomplete="current-password" required autofocus>
        </div>

        <div class="form-group mb-0">
          <label class="form-label" for="password"><?= __('usr_new_password') ?></label>
          <input type="password" id="password" name="password" class="form-control" autocomplete="new-password" required>
          <div class="form-hint"><?= __('auth_password_hint', ['min' => (string)AuthService::PASSWORD_MIN_LENGTH]) ?></div>
        </div>

        <div class="form-group mb-0">
          <label class="form-label" for="password_confirm"><?= __('usr_confirm_pass') ?></label>
          <input type="password" id="password_confirm" name="password_confirm" class="form-control" autocomplete="new-password" required>
        </div>

        <button type="submit" class="btn btn-primary btn-lg btn-block">
          <?= __('btn_save') ?>
        </button>
      </form>
    </div>
  </div>
</body>
</html>
