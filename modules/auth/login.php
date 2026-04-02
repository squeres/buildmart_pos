<?php
require_once __DIR__ . '/../../core/bootstrap.php';

if (Auth::check()) {
    redirect('/index.php');
}

$errors = [];
$email  = '';

if (is_post()) {
    if (!csrf_verify()) {
        $errors[] = _r('err_csrf');
    } else {
        $email    = sanitize($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($email) || empty($password)) {
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
<title><?= __('auth_login') ?> — <?= __('app_name') ?></title>
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
    display: flex; align-items: center; gap: 12px;
    margin-bottom: 32px;
  }
  .login-brand-icon {
    width: 48px; height: 48px;
    background: var(--amber);
    border-radius: var(--radius);
    display: flex; align-items: center; justify-content: center;
    font-size: 24px;
  }
  .login-title { font-size: 22px; font-weight: 700; margin-bottom: 4px; }
  .login-sub   { font-size: 13px; color: var(--text-muted); margin-bottom: 28px; }
  .lang-row { display: flex; gap: 6px; justify-content: center; margin-top: 24px; }
  .demo-note { margin-top: 20px; padding: 12px 14px; background: var(--bg-raised); border-radius: var(--radius-sm); font-size: 12px; color: var(--text-muted); border: 1px solid var(--border-dim); }
</style>
</head>
<body class="app-body" style="background:var(--bg-base)">

<div class="login-wrap">
  <div class="login-card">
    <div class="login-brand">
      <div class="login-brand-icon">🏗️</div>
      <div>
        <div class="brand-name"><?= __('app_name') ?></div>
        <div class="brand-sub"><?= __('app_tagline') ?></div>
      </div>
    </div>

    <h1 class="login-title"><?= __('auth_login_heading') ?></h1>
    <p class="login-sub"><?= __('auth_login_sub') ?></p>

    <?php foreach ($errors as $err): ?>
      <div class="flash flash-error mb-2">
        <span><?= e($err) ?></span>
      </div>
    <?php endforeach; ?>

    <?php if (isset($_SESSION['flash'])): ?>
      <?php foreach (get_flashes() as $fl): ?>
        <div class="flash flash-<?= e($fl['type']) ?> mb-2"><span><?= e($fl['message']) ?></span></div>
      <?php endforeach; ?>
    <?php endif; ?>

    <form method="POST">
      <?= csrf_field() ?>

      <div class="form-group">
        <label class="form-label" for="email"><?= __('auth_email') ?></label>
        <input type="email" id="email" name="email" class="form-control"
               value="<?= e($email) ?>"
               placeholder="user@example.com"
               autocomplete="username"
               required autofocus>
      </div>

      <div class="form-group">
        <label class="form-label" for="password"><?= __('auth_password') ?></label>
        <input type="password" id="password" name="password" class="form-control"
               placeholder="••••••••"
               autocomplete="current-password"
               required>
      </div>

      <button type="submit" class="btn btn-primary btn-block btn-lg" style="margin-top:6px">
        <?= __('auth_login') ?>
      </button>
    </form>

    <div class="lang-row">
      <?php foreach (SUPPORTED_LANGS as $code => $label): ?>
        <a href="?lang=<?= $code ?>" class="lang-btn <?= Lang::current() === $code ? 'active' : '' ?>">
          <?= e($label) ?>
        </a>
      <?php endforeach; ?>
    </div>

  </div>
</div>

<script src="https://unpkg.com/feather-icons/dist/feather.min.js"></script>
<script>if(typeof feather!=='undefined')feather.replace();</script>
</body>
</html>
