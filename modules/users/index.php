<?php
require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../views/partials/icons.php';
Auth::requireLogin();
Auth::requirePerm('all');  // Admin only

$pageTitle   = __('usr_title');
$breadcrumbs = [[$pageTitle, null]];
$hasLanguageSetAt = shift_schema_has_column('users', 'language_set_at');
$permissionStorageReady = AuthService::permissionOverridesTableReady();

$users = Database::all(
    "SELECT u.*,
            r.name AS role_name,
            r.slug AS role_slug,
            s.id AS open_shift_id,
            s.opened_at AS open_shift_opened_at
     FROM users u
     JOIN roles r ON r.id = u.role_id
     LEFT JOIN shifts s ON s.user_id = u.id AND s.status = 'open'
     ORDER BY u.name"
);

$userPermissionOverrideCounts = [];
if ($permissionStorageReady) {
    foreach (Database::all('SELECT user_id, COUNT(*) AS cnt FROM user_permission_overrides GROUP BY user_id') as $row) {
        $userPermissionOverrideCounts[(int)$row['user_id']] = (int)$row['cnt'];
    }
}

include __DIR__ . '/../../views/layouts/header.php';
?>

<div class="page-header">
  <h1 class="page-heading"><?= __('usr_title') ?></h1>
  <a href="<?= url('modules/users/add.php') ?>" class="btn btn-primary">
    <?= feather_icon('user-plus', 15) ?> <?= __('usr_add') ?>
  </a>
</div>

<div class="card">
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th><?= __('lbl_name') ?></th>
          <th><?= __('lbl_email') ?></th>
          <th><?= __('lbl_role') ?></th>
          <th><?= __('lbl_language') ?></th>
          <th><?= __('usr_online') ?></th>
          <th><?= __('usr_last_login') ?></th>
          <th><?= __('lbl_status') ?></th>
          <th class="col-actions"><?= __('lbl_actions') ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($users as $user): ?>
        <?php $isOnline = user_is_online($user); ?>
        <tr>
          <td>
            <div style="display:flex;align-items:center;gap:9px">
              <div class="user-avatar" style="flex-shrink:0"><?= strtoupper(substr($user['name'], 0, 1)) ?></div>
              <span class="fw-600"><?= e($user['name']) ?></span>
            </div>
          </td>
          <td><?= e($user['email']) ?></td>
          <td>
            <span class="badge badge-<?= $user['role_slug'] === 'admin' ? 'danger' : ($user['role_slug'] === 'manager' ? 'warning' : 'info') ?>">
              <?= e($user['role_name']) ?>
            </span>
            <?php if (($userPermissionOverrideCounts[(int)$user['id']] ?? 0) > 0): ?>
              <div style="margin-top:6px">
                <span class="badge badge-secondary"><?= __('usr_custom_access_badge') ?></span>
              </div>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($hasLanguageSetAt && empty($user['language_set_at'])): ?>
              <?= __('usr_language_default_option', ['language' => language_label(DEFAULT_LANG)]) ?>
            <?php else: ?>
              <?= e(language_label($user['language'] ?? null)) ?>
            <?php endif; ?>
          </td>
          <td>
            <div style="display:flex;flex-direction:column;gap:4px">
              <span class="badge badge-<?= $isOnline ? 'success' : 'secondary' ?>" style="width:max-content">
                <?= $isOnline ? __('usr_online_now') : __('usr_offline') ?>
              </span>
              <?php if ($user['open_shift_opened_at']): ?>
                <span class="text-muted" style="font-size:12px">
                  <?= __('usr_shift_open_since') ?>: <?= date_fmt((string)$user['open_shift_opened_at']) ?>
                </span>
              <?php elseif (!$isOnline && !empty($user['last_seen_at'])): ?>
                <span class="text-muted" style="font-size:12px">
                  <?= __('usr_last_seen_at') ?>: <?= date_fmt((string)$user['last_seen_at']) ?>
                </span>
              <?php endif; ?>
            </div>
          </td>
          <td class="text-muted"><?= $user['last_login'] ? date_fmt((string)$user['last_login']) : '—' ?></td>
          <td><?= $user['is_active'] ? '<span class="badge badge-success">'.__('lbl_active').'</span>' : '<span class="badge badge-secondary">'.__('lbl_inactive').'</span>' ?></td>
          <td class="col-actions">
            <a href="<?= url('modules/users/edit.php?id=' . $user['id']) ?>" class="btn btn-sm btn-ghost btn-icon"><?= feather_icon('edit-2', 14) ?></a>
            <?php if ($user['id'] != Auth::id()): ?>
            <form method="POST" action="<?= url('modules/users/toggle.php') ?>" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= (int)$user['id'] ?>">
              <button type="submit"
                 class="btn btn-sm btn-ghost btn-icon"
                 title="<?= $user['is_active'] ? __('usr_deactivate') : __('usr_activate') ?>"
                 style="<?= $user['is_active'] ? 'color:var(--warning)' : '' ?>"
                 data-confirm="<?= $user['is_active'] ? __('usr_confirm_deactivate') : __('usr_confirm_activate') ?>">
                <?= feather_icon($user['is_active'] ? 'user-x' : 'user-check', 14) ?>
              </button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/../../views/layouts/footer.php'; ?>
<script src="https://unpkg.com/feather-icons/dist/feather.min.js"></script>
<script>feather.replace();</script>
