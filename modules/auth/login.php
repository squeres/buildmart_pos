<?php
require_once __DIR__ . '/../../core/bootstrap.php';

if (Auth::check()) {
    redirect('/index.php');
}

$errors = [];
$email = '';

if (is_post()) {
    if (!csrf_verify()) {
        $errors[] = _r('err_csrf');
    } else {
        $email = sanitize($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($email === '' || $password === '') {
            $errors[] = _r('auth_invalid');
        } elseif (!Auth::attempt($email, $password)) {
            $errors[] = _r('auth_invalid');
        } else {
            $redir = $_SESSION['redirect_after_login'] ?? BASE_URL . '/index.php';
            unset($_SESSION['redirect_after_login']);
            redirect($redir);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?= Lang::current() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= __('auth_login') ?> &mdash; <?= __('app_name') ?></title>
<?= app_favicon_links() ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@300;400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= url('assets/css/app.css') ?>">
</head>
<body class="app-body">
  <div class="auth-shell">
    <div class="auth-card auth-card-wide">
      <div class="auth-brand" aria-label="<?= __('app_name') ?>">
        <span class="auth-brand-mark" aria-hidden="true">
          <img src="<?= e(APP_ICON_URL) ?>" alt="" class="brand-mark-image">
        </span>
        <div class="auth-brand-copy">
          <div class="auth-brand-name"><?= __('app_name') ?></div>
          <div class="auth-brand-sub"><?= __('app_tagline') ?></div>
        </div>
      </div>

      <h1 class="auth-heading"><?= __('auth_login_heading') ?></h1>
      <p class="auth-subtitle"><?= __('auth_login_sub_brand', ['app' => _r('app_name')]) ?></p>

      <?php foreach ($errors as $err): ?>
        <div class="flash flash-error mb-2" style="margin:0 0 12px">
          <span><?= e($err) ?></span>
        </div>
      <?php endforeach; ?>

      <?php foreach (get_flashes() as $fl): ?>
        <div class="flash flash-<?= e($fl['type']) ?> mb-2" style="margin:0 0 12px">
          <span><?= e($fl['message']) ?></span>
        </div>
      <?php endforeach; ?>

      <form method="POST" class="auth-actions">
        <?= csrf_field() ?>

        <div class="form-group mb-0">
          <label class="form-label" for="email"><?= __('auth_email') ?></label>
          <input
            type="email"
            id="email"
            name="email"
            class="form-control"
            value="<?= e($email) ?>"
            placeholder="<?= __('auth_email_placeholder') ?>"
            autocomplete="username"
            required
            autofocus
          >
        </div>

        <div class="form-group mb-0">
          <label class="form-label" for="password"><?= __('auth_password') ?></label>
          <input
            type="password"
            id="password"
            name="password"
            class="form-control"
            placeholder="<?= __('auth_password_placeholder') ?>"
            autocomplete="current-password"
            required
          >
        </div>

        <div class="auth-meta-row">
          <a href="#" class="auth-link-muted" aria-disabled="true" onclick="return false;">
            <?= __('auth_forgot_password') ?>
          </a>
          <button type="submit" class="btn btn-primary btn-lg btn-block">
            <?= __('auth_login') ?>
          </button>
        </div>
      </form>

      <div class="auth-lang-switcher">
        <?php foreach (SUPPORTED_LANGS as $code => $label): ?>
          <a href="?lang=<?= e($code) ?>" class="lang-btn <?= Lang::current() === $code ? 'active' : '' ?>">
            <?= e($label) ?>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</body>
</html>
