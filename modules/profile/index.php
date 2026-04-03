<?php
require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../views/partials/icons.php';

Auth::requireLogin();

$hasLanguageSetAt = shift_schema_has_column('users', 'language_set_at');
$languageSetSelect = $hasLanguageSetAt ? 'u.language_set_at,' : 'NULL AS language_set_at,';
$profile = Database::row(
    "SELECT u.id, u.name, u.email, u.phone, u.language,
            {$languageSetSelect}
            u.last_login, u.created_at,
            r.name AS role_name
     FROM users u
     JOIN roles r ON r.id = u.role_id
     WHERE u.id = ?
     LIMIT 1",
    [Auth::id()]
);

if (!$profile) {
    Auth::logout();
}

$pageTitle = __('nav_profile');
$breadcrumbs = [[$pageTitle, null]];
$profileErrors = [];
$passwordErrors = [];
$pinErrors = [];

$profileForm = [
    'name' => (string)$profile['name'],
    'email' => (string)$profile['email'],
    'phone' => (string)($profile['phone'] ?? ''),
    'language' => Lang::normalizeCode($profile['language'] ?? null) ?? DEFAULT_LANG,
];

if (is_post()) {
    if (!csrf_verify()) {
        flash_error(_r('err_csrf'));
        redirect($_SERVER['REQUEST_URI']);
    }

    $action = sanitize($_POST['action'] ?? '');

    if ($action === 'save_profile') {
        $profileForm = [
            'name' => sanitize($_POST['name'] ?? ''),
            'email' => sanitize($_POST['email'] ?? ''),
            'phone' => sanitize($_POST['phone'] ?? ''),
            'language' => Lang::normalizeCode($_POST['language'] ?? null) ?? '',
        ];

        if ($profileForm['name'] === '') {
            $profileErrors[] = _r('lbl_required');
        }

        if ($profileForm['email'] === '') {
            $profileErrors[] = _r('profile_email_required');
        } elseif (!filter_var($profileForm['email'], FILTER_VALIDATE_EMAIL)) {
            $profileErrors[] = _r('auth_invalid_email');
        }

        if ($profileForm['language'] === '') {
            $profileErrors[] = _r('profile_language_invalid');
        }

        $emailExists = Database::value(
            'SELECT id FROM users WHERE email=? AND id<>?',
            [$profileForm['email'], Auth::id()]
        );
        if ($emailExists) {
            $profileErrors[] = _r('auth_email_in_use');
        }

        if (!$profileErrors) {
            $params = [
                $profileForm['name'],
                $profileForm['email'],
                $profileForm['phone'] !== '' ? $profileForm['phone'] : null,
                $profileForm['language'],
            ];
            $sql = 'UPDATE users SET name=?, email=?, phone=?, language=?';
            if ($hasLanguageSetAt) {
                $sql .= ', language_set_at=NOW()';
            }
            $sql .= ', updated_at=NOW() WHERE id=?';
            $params[] = Auth::id();

            Database::exec($sql, $params);
            Auth::refreshCurrentUser();
            flash_success(_r('profile_saved'));
            redirect('/modules/profile/');
        }
    }

    if ($action === 'change_password') {
        try {
            AuthService::changePassword(
                Auth::id(),
                $_POST['current_password'] ?? '',
                $_POST['new_password'] ?? '',
                $_POST['new_password_confirm'] ?? ''
            );
            Auth::clearMustChangePassword();
            flash_success(_r('auth_password_updated'));
            redirect('/modules/profile/');
        } catch (AppServiceException $e) {
            $passwordErrors[] = $e->getMessage();
        }
    }

    if ($action === 'change_pin') {
        try {
            AuthService::changePin(
                Auth::id(),
                $_POST['pin_current_password'] ?? '',
                $_POST['new_pin'] ?? '',
                $_POST['new_pin_confirm'] ?? ''
            );
            flash_success(_r('profile_pin_updated'));
            redirect('/modules/profile/');
        } catch (AppServiceException $e) {
            $pinErrors[] = $e->getMessage();
        }
    }
}

$sessionUser = Auth::user();
$profileLanguageLabel = language_label($sessionUser['language'] ?? $profileForm['language']);
$currentLanguageLabel = language_label($_SESSION['lang'] ?? $profileForm['language']);
$profileInitial = function_exists('mb_substr')
    ? mb_substr((string)$profileForm['name'], 0, 1, 'UTF-8')
    : substr((string)$profileForm['name'], 0, 1);
$profileInitial = function_exists('mb_strtoupper')
    ? mb_strtoupper($profileInitial, 'UTF-8')
    : strtoupper($profileInitial);

include __DIR__ . '/../../views/layouts/header.php';
?>

<div class="page-header">
  <div>
    <h1 class="page-heading"><?= __('nav_profile') ?></h1>
    <p class="page-subtitle"><?= __('profile_page_subtitle') ?></p>
  </div>
</div>

<div class="profile-grid">
  <div class="profile-summary">
    <div class="card">
      <div class="card-body">
        <div class="profile-hero">
          <div class="profile-avatar"><?= e($profileInitial) ?></div>
          <div>
            <div class="profile-title"><?= e($profileForm['name']) ?></div>
            <div class="text-secondary"><?= e($profile['role_name']) ?></div>
          </div>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-header">
        <span class="card-title"><?= __('profile_personal_heading') ?></span>
      </div>
      <div class="card-body">
        <?php if ($profileErrors): ?>
          <div class="flash flash-error" style="margin:0 0 16px">
            <?= feather_icon('alert-circle', 15) ?>
            <span><?= e(implode(' ', $profileErrors)) ?></span>
          </div>
        <?php endif; ?>

        <form method="POST">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="save_profile">

          <div class="form-group">
            <label class="form-label"><?= __('lbl_name') ?> <span class="req">*</span></label>
            <input type="text" name="name" class="form-control" value="<?= e($profileForm['name']) ?>" autocomplete="name" required>
          </div>

          <div class="form-row form-row-2">
            <div class="form-group">
              <label class="form-label"><?= __('lbl_email') ?> <span class="req">*</span></label>
              <input type="email" name="email" class="form-control" value="<?= e($profileForm['email']) ?>" autocomplete="email" required>
            </div>
            <div class="form-group">
              <label class="form-label"><?= __('lbl_phone') ?></label>
              <input type="text" name="phone" class="form-control" value="<?= e($profileForm['phone']) ?>" autocomplete="tel">
            </div>
          </div>

          <div class="form-group">
            <label class="form-label"><?= __('lbl_language') ?></label>
            <select name="language" class="form-control" style="max-width:320px">
              <?php foreach (SUPPORTED_LANGS as $code => $label): ?>
                <option value="<?= e($code) ?>" <?= $profileForm['language'] === $code ? 'selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="form-hint"><?= __('profile_language_hint') ?></div>
          </div>

          <button type="submit" class="btn btn-primary">
            <?= feather_icon('save', 16) ?> <?= __('btn_save') ?>
          </button>
        </form>
      </div>
    </div>
  </div>

  <div class="profile-summary">
    <div class="card">
      <div class="card-header">
        <span class="card-title"><?= __('profile_summary_heading') ?></span>
      </div>
      <div class="card-body profile-meta">
        <div class="profile-meta-row">
          <span class="profile-meta-label"><?= __('lbl_role') ?></span>
          <span class="profile-meta-value"><?= e($profile['role_name']) ?></span>
        </div>
        <div class="profile-meta-row">
          <span class="profile-meta-label"><?= __('profile_saved_language_label') ?></span>
          <span class="profile-meta-value"><?= e($profileLanguageLabel) ?></span>
        </div>
        <div class="profile-meta-row">
          <span class="profile-meta-label"><?= __('profile_current_language_label') ?></span>
          <span class="profile-meta-value"><?= e($currentLanguageLabel) ?></span>
        </div>
        <div class="profile-meta-row">
          <span class="profile-meta-label"><?= __('usr_last_login') ?></span>
          <span class="profile-meta-value"><?= e($profile['last_login'] ? date_fmt((string)$profile['last_login']) : _r('lbl_none')) ?></span>
        </div>
        <div class="profile-meta-row">
          <span class="profile-meta-label"><?= __('lbl_created') ?></span>
          <span class="profile-meta-value"><?= e(date_fmt((string)$profile['created_at'])) ?></span>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-header">
        <span class="card-title"><?= __('profile_security_heading') ?></span>
      </div>
      <div class="card-body">
        <div class="profile-security-stack">
          <section class="profile-security-section">
            <p class="page-subtitle" style="margin-top:0;margin-bottom:16px"><?= __('profile_security_hint', ['min' => (string)AuthService::PASSWORD_MIN_LENGTH]) ?></p>

            <?php if ($passwordErrors): ?>
              <div class="flash flash-error" style="margin:0 0 16px">
                <?= feather_icon('alert-circle', 15) ?>
                <span><?= e(implode(' ', $passwordErrors)) ?></span>
              </div>
            <?php endif; ?>

            <form method="POST">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="change_password">

              <div class="form-group">
                <label class="form-label"><?= __('profile_current_password') ?></label>
                <input type="password" name="current_password" class="form-control" autocomplete="current-password" required>
              </div>

              <div class="form-group">
                <label class="form-label"><?= __('usr_new_password') ?></label>
                <input type="password" name="new_password" class="form-control" autocomplete="new-password" required>
              </div>

              <div class="form-group">
                <label class="form-label"><?= __('usr_confirm_pass') ?></label>
                <input type="password" name="new_password_confirm" class="form-control" autocomplete="new-password" required>
              </div>

              <button type="submit" class="btn btn-primary">
                <?= feather_icon('shield', 16) ?> <?= __('profile_change_password') ?>
              </button>
            </form>
          </section>

          <section class="profile-security-section">
            <p class="page-subtitle" style="margin-top:0;margin-bottom:16px"><?= __('profile_pin_hint', ['min' => (string)AuthService::PIN_MIN_LENGTH, 'max' => (string)AuthService::PIN_MAX_LENGTH]) ?></p>

            <?php if ($pinErrors): ?>
              <div class="flash flash-error" style="margin:0 0 16px">
                <?= feather_icon('alert-circle', 15) ?>
                <span><?= e(implode(' ', $pinErrors)) ?></span>
              </div>
            <?php endif; ?>

            <form method="POST" autocomplete="off">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="change_pin">

              <div class="form-group">
                <label class="form-label"><?= __('profile_current_password') ?></label>
                <input type="password" name="pin_current_password" class="form-control" autocomplete="current-password" required>
              </div>

              <div class="form-group">
                <label class="form-label"><?= __('profile_new_pin') ?></label>
                <input type="password" name="new_pin" class="form-control" inputmode="numeric" pattern="[0-9]*" maxlength="<?= (int)AuthService::PIN_MAX_LENGTH ?>" placeholder="<?= e(__('auth_pin_placeholder')) ?>" autocomplete="new-password" required>
              </div>

              <div class="form-group">
                <label class="form-label"><?= __('profile_confirm_pin') ?></label>
                <input type="password" name="new_pin_confirm" class="form-control" inputmode="numeric" pattern="[0-9]*" maxlength="<?= (int)AuthService::PIN_MAX_LENGTH ?>" placeholder="<?= e(__('auth_pin_placeholder')) ?>" autocomplete="new-password" required>
              </div>

              <button type="submit" class="btn btn-primary">
                <?= feather_icon('key', 16) ?> <?= __('profile_change_pin') ?>
              </button>
            </form>
          </section>
        </div>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../../views/layouts/footer.php'; ?>
