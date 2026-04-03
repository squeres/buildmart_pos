<?php
require_once __DIR__ . '/../../core/bootstrap.php';

if (Auth::check()) {
    redirect('/index.php');
}

$errors = [];
$email = '';
$authMode = strtolower(trim((string)($_POST['auth_mode'] ?? $_GET['mode'] ?? 'password')));
$authMode = in_array($authMode, ['password', 'pin'], true) ? $authMode : 'password';
$pinRateLimitKey = 'auth_pin_login_rate_limit';

$getPinLockRemaining = static function () use ($pinRateLimitKey): int {
    $lockedUntil = (int)($_SESSION[$pinRateLimitKey]['locked_until'] ?? 0);
    return max(0, $lockedUntil - time());
};

$registerPinFailure = static function () use ($pinRateLimitKey): int {
    $state = $_SESSION[$pinRateLimitKey] ?? ['count' => 0, 'locked_until' => 0];
    $lockedUntil = (int)($state['locked_until'] ?? 0);
    if ($lockedUntil > time()) {
        return $lockedUntil - time();
    }

    $count = (int)($state['count'] ?? 0) + 1;
    $state['count'] = $count;

    if ($count >= AuthService::PIN_LOGIN_MAX_ATTEMPTS) {
        $state['count'] = 0;
        $state['locked_until'] = time() + AuthService::PIN_LOGIN_LOCK_SECONDS;
    } else {
        $state['locked_until'] = 0;
    }

    $_SESSION[$pinRateLimitKey] = $state;

    return max(0, (int)($state['locked_until'] ?? 0) - time());
};

$resetPinFailures = static function () use ($pinRateLimitKey): void {
    unset($_SESSION[$pinRateLimitKey]);
};

$modeQueryBase = $_GET;
unset($modeQueryBase['mode']);
$buildModeUrl = static function (string $mode) use ($modeQueryBase): string {
    $query = $modeQueryBase;
    $query['mode'] = $mode;
    return '?' . http_build_query($query);
};

if (is_post()) {
    if (!csrf_verify()) {
        $errors[] = _r('err_csrf');
    } else {
        if ($authMode === 'pin') {
            $pinLockRemaining = $getPinLockRemaining();
            $pin = trim((string)($_POST['pin'] ?? ''));

            if ($pinLockRemaining > 0) {
                $errors[] = _r('auth_pin_locked', ['seconds' => (string)$pinLockRemaining]);
            } elseif ($pin === '') {
                $errors[] = _r('auth_pin_invalid');
            } elseif (!Auth::attemptPin($pin)) {
                $pinLockRemaining = $registerPinFailure();
                $errors[] = $pinLockRemaining > 0
                    ? _r('auth_pin_locked', ['seconds' => (string)$pinLockRemaining])
                    : _r('auth_pin_invalid');
            } else {
                $resetPinFailures();
                $redir = $_SESSION['redirect_after_login'] ?? BASE_URL . '/index.php';
                unset($_SESSION['redirect_after_login']);
                redirect($redir);
            }
        } else {
            $email = sanitize($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';

            if ($email === '' || $password === '') {
                $errors[] = _r('auth_invalid');
            } elseif (!Auth::attempt($email, $password)) {
                $errors[] = _r('auth_invalid');
            } else {
                $resetPinFailures();
                $redir = $_SESSION['redirect_after_login'] ?? BASE_URL . '/index.php';
                unset($_SESSION['redirect_after_login']);
                redirect($redir);
            }
        }
    }
}

$pinLockRemaining = $getPinLockRemaining();
if ($authMode === 'pin' && !$errors && $pinLockRemaining > 0) {
    $errors[] = _r('auth_pin_locked', ['seconds' => (string)$pinLockRemaining]);
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

      <div class="auth-mode-switcher" role="tablist" aria-label="<?= __('auth_login') ?>">
        <a href="<?= e($buildModeUrl('password')) ?>" class="auth-mode-btn <?= $authMode === 'password' ? 'active' : '' ?>" aria-current="<?= $authMode === 'password' ? 'page' : 'false' ?>">
          <?= __('auth_mode_password') ?>
        </a>
        <a href="<?= e($buildModeUrl('pin')) ?>" class="auth-mode-btn <?= $authMode === 'pin' ? 'active' : '' ?>" aria-current="<?= $authMode === 'pin' ? 'page' : 'false' ?>">
          <?= __('auth_mode_pin') ?>
        </a>
      </div>

      <form method="POST" class="auth-actions">
        <?= csrf_field() ?>
        <input type="hidden" name="auth_mode" value="<?= e($authMode) ?>">

        <?php if ($authMode === 'pin'): ?>
          <div class="form-group mb-0">
            <label class="form-label" for="pin"><?= __('auth_pin') ?></label>
            <input
              type="password"
              id="pin"
              name="pin"
              class="form-control mono auth-pin-field"
              placeholder="<?= __('auth_pin_placeholder') ?>"
              autocomplete="one-time-code"
              inputmode="numeric"
              pattern="\d{4,6}"
              maxlength="<?= AuthService::PIN_MAX_LENGTH ?>"
              required
              autofocus
            >
            <div class="form-hint"><?= __('auth_pin_hint_login') ?></div>
          </div>
        <?php else: ?>
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
        <?php endif; ?>

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
