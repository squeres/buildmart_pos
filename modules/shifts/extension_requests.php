<?php
require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../views/partials/icons.php';

Auth::requireLogin();
Auth::requireManagerOrAdmin();

$pageTitle = __('shift_extension_requests_title');
$breadcrumbs = [
    [__('shift_title'), url('modules/shifts/')],
    [$pageTitle, null],
];

$featureReady = shift_extension_schema_ready();
if ($featureReady) {
    shift_expire_stale_extension_requests();
}

if (is_post()) {
    if (!csrf_verify()) {
        flash_error(_r('err_csrf'));
        redirect($_SERVER['REQUEST_URI']);
    }

    if (!$featureReady) {
        flash_error(_r('shift_extension_not_available'));
        redirect($_SERVER['REQUEST_URI']);
    }

    $action = (string)($_POST['action'] ?? '');
    $requestId = (int)($_POST['request_id'] ?? 0);
    $shiftId = (int)($_POST['shift_id'] ?? 0);
    $approvedMinutes = 0;
    if (isset($_POST['preset_minutes'])) {
        $action = 'approve';
        $approvedMinutes = max(0, (int)$_POST['preset_minutes']);
    } elseif ($action === 'approve_custom') {
        $action = 'approve';
        $approvedMinutes = max(0, (int)($_POST['approved_minutes'] ?? 0));
    } elseif (isset($_POST['direct_preset_minutes'])) {
        $action = 'grant_direct';
        $approvedMinutes = max(0, (int)$_POST['direct_preset_minutes']);
    } elseif ($action === 'grant_direct_custom') {
        $action = 'grant_direct';
        $approvedMinutes = max(0, (int)($_POST['direct_minutes'] ?? 0));
    }

    if ($action === 'grant_direct') {
        $shift = Database::row(
            "SELECT s.*,
                    u.name AS cashier_name
             FROM shifts s
             JOIN users u ON u.id = s.user_id
             WHERE s.id = ?
             LIMIT 1",
            [$shiftId]
        );

        if (!$shift) {
            flash_error(_r('err_not_found'));
            redirect($_SERVER['REQUEST_URI']);
        }

        $directState = shift_can_extend_directly($shift);
        if (empty($directState['ok'])) {
            flash_error($directState['message']);
            redirect($_SERVER['REQUEST_URI']);
        }
        if ($approvedMinutes <= 0 || $approvedMinutes > (int)$directState['remaining_minutes']) {
            flash_error(_r('err_validation'));
            redirect($_SERVER['REQUEST_URI']);
        }

        $directReason = trim(strip_tags((string)($_POST['direct_reason'] ?? '')));
        try {
            $grant = shift_grant_direct_extension(
                $shift,
                Auth::id(),
                $approvedMinutes,
                $directReason !== '' ? $directReason : null
            );
            flash_success(_r('shift_extension_approved_until', ['time' => date_fmt($grant['expires_at']->format('Y-m-d H:i:s'))]));
        } catch (Throwable $e) {
            flash_error($e->getMessage() ?: _r('err_validation'));
        }
        redirect($_SERVER['REQUEST_URI']);
    }

    $request = Database::row(
        "SELECT ser.*,
                s.opened_at,
                s.closed_at,
                s.extended_until,
                s.status AS shift_status,
                u.name AS cashier_name
         FROM shift_extension_requests ser
         JOIN shifts s ON s.id = ser.shift_id
         JOIN users u ON u.id = ser.cashier_id
         WHERE ser.id = ?
         LIMIT 1",
        [$requestId]
    );

    if (!$request) {
        flash_error(_r('err_not_found'));
        redirect($_SERVER['REQUEST_URI']);
    }
    if (($request['status'] ?? '') !== 'pending') {
        flash_warning(_r('shift_extension_request_already_handled'));
        redirect($_SERVER['REQUEST_URI']);
    }
    if ((int)$request['cashier_id'] === Auth::id()) {
        flash_error(_r('shift_extension_self_approve_forbidden'));
        redirect($_SERVER['REQUEST_URI']);
    }

    if ($action === 'reject') {
        Database::exec(
            "UPDATE shift_extension_requests
             SET status = 'rejected',
                 approved_by = ?,
                 approved_at = NOW()
             WHERE id = ? AND status = 'pending'",
            [Auth::id(), $requestId]
        );
        flash_success(_r('shift_extension_rejected'));
        redirect($_SERVER['REQUEST_URI']);
    }

    if ($action !== 'approve') {
        flash_error(_r('err_validation'));
        redirect($_SERVER['REQUEST_URI']);
    }

    $approvalState = shift_can_request_extension($request);
    $remainingMinutes = shift_remaining_extension_minutes($request);
    if (!$approvalState['ok'] && $remainingMinutes <= 0) {
        flash_error(_r('shift_extension_limit_reached'));
        redirect($_SERVER['REQUEST_URI']);
    }
    if (($request['shift_status'] ?? '') !== 'open' || !empty($request['closed_at'])) {
        flash_error(_r('shift_extension_not_available'));
        redirect($_SERVER['REQUEST_URI']);
    }
    if ($approvedMinutes <= 0 || $approvedMinutes > $remainingMinutes) {
        flash_error(_r('err_validation'));
        redirect($_SERVER['REQUEST_URI']);
    }

    $now = shift_now();
    if ($now > shift_extension_max_deadline($request)) {
        Database::exec(
            "UPDATE shift_extension_requests
             SET status = 'expired'
             WHERE id = ? AND status = 'pending'",
            [$requestId]
        );
        flash_error(_r('shift_close_expired_first', ['date' => date_fmt((string)$request['opened_at'])]));
        redirect($_SERVER['REQUEST_URI']);
    }

    $deadline = shift_extension_deadline_after_approval($request, $approvedMinutes, $now);
    $baseline = shift_allowed_until($request);
    if ($now > $baseline) {
        $baseline = $now;
    }
    $actualApprovedMinutes = max(0, (int)floor(($deadline->getTimestamp() - $baseline->getTimestamp()) / 60));
    if ($actualApprovedMinutes <= 0) {
        flash_error(_r('shift_extension_limit_reached'));
        redirect($_SERVER['REQUEST_URI']);
    }

    Database::beginTransaction();
    try {
        Database::exec(
            "UPDATE shift_extension_requests
             SET status = 'approved',
                 approved_by = ?,
                 approved_minutes = ?,
                 approved_at = NOW(),
                 expires_at = ?
             WHERE id = ? AND status = 'pending'",
            [
                Auth::id(),
                $actualApprovedMinutes,
                $deadline->format('Y-m-d H:i:s'),
                $requestId,
            ]
        );

        Database::exec(
            "UPDATE shifts
             SET extended_until = ?,
                 extension_approved_by = ?
             WHERE id = ? AND status = 'open'",
            [
                $deadline->format('Y-m-d H:i:s'),
                Auth::id(),
                (int)$request['shift_id'],
            ]
        );

        Database::exec(
            "UPDATE shift_extension_requests
             SET status = 'expired'
             WHERE shift_id = ? AND status = 'pending' AND id <> ?",
            [(int)$request['shift_id'], $requestId]
        );

        Database::commit();
    } catch (Throwable $e) {
        Database::rollback();
        flash_error(_r('err_validation'));
        redirect($_SERVER['REQUEST_URI']);
    }

    flash_success(_r('shift_extension_approved_until', ['time' => date_fmt($deadline->format('Y-m-d H:i:s'))]));
    redirect($_SERVER['REQUEST_URI']);
}

$pendingRequests = [];
$historyRequests = [];
$activeShifts = [];
$pendingCount = 0;
if ($featureReady) {
    $activeShifts = Database::all(
        "SELECT s.*,
                u.name AS cashier_name,
                approver.name AS extension_approver_name
         FROM shifts s
         JOIN users u ON u.id = s.user_id
         LEFT JOIN users approver ON approver.id = s.extension_approved_by
         WHERE s.status = 'open'
         ORDER BY s.opened_at ASC"
    );
    $pendingRequests = Database::all(
        "SELECT ser.*,
                s.opened_at,
                s.closed_at,
                s.extended_until,
                s.status AS shift_status,
                u.name AS cashier_name
         FROM shift_extension_requests ser
         JOIN shifts s ON s.id = ser.shift_id
         JOIN users u ON u.id = ser.cashier_id
         WHERE ser.status = 'pending'
         ORDER BY ser.requested_at ASC, ser.id ASC"
    );
    $historyRequests = Database::all(
        "SELECT ser.*,
                s.opened_at,
                u.name AS cashier_name,
                approver.name AS approver_name
         FROM shift_extension_requests ser
         JOIN shifts s ON s.id = ser.shift_id
         JOIN users u ON u.id = ser.cashier_id
         LEFT JOIN users approver ON approver.id = ser.approved_by
         WHERE ser.status <> 'pending'
         ORDER BY COALESCE(ser.approved_at, ser.requested_at) DESC, ser.id DESC
         LIMIT 25"
    );
    $pendingCount = count($pendingRequests);
}

include __DIR__ . '/../../views/layouts/header.php';
?>

<div class="page-header">
  <h1 class="page-heading"><?= __('shift_extension_requests_title') ?></h1>
  <div class="page-actions">
    <a href="<?= url('modules/shifts/') ?>" class="btn btn-secondary">
      <?= feather_icon('arrow-left', 15) ?> <?= __('btn_back') ?>
    </a>
  </div>
</div>

<?php if (!$featureReady): ?>
<div class="card">
  <div class="card-body">
    <div class="text-muted"><?= __('shift_extension_not_available') ?></div>
  </div>
</div>
<?php else: ?>

<div class="grid grid-2 mb-3">
  <div class="stat-card">
    <div class="stat-icon stat-icon-amber"><?= feather_icon('clock', 20) ?></div>
    <div>
      <div class="stat-value"><?= $pendingCount ?></div>
      <div class="stat-label"><?= __('shift_extension_pending_count') ?></div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon stat-icon-blue"><?= feather_icon('shield', 20) ?></div>
    <div>
      <div class="stat-value"><?= e(implode(', ', shift_extension_default_options())) ?></div>
      <div class="stat-label"><?= __('shift_extension_default_options') ?></div>
    </div>
  </div>
</div>

<div class="card mb-3">
  <div class="card-header"><span class="card-title"><?= __('shift_extension_direct_title') ?></span></div>
  <div class="card-body" style="display:grid;gap:14px">
    <?php if (!$activeShifts): ?>
      <div class="text-muted"><?= __('shift_extension_direct_none') ?></div>
    <?php else: ?>
      <?php foreach ($activeShifts as $shift): ?>
        <?php
        $directState = shift_can_extend_directly($shift);
        $currentAllowedUntil = shift_allowed_until($shift);
        $remainingMinutes = max(0, (int)($directState['remaining_minutes'] ?? 0));
        $directOptions = $directState['request_options'] ?? [];
        ?>
        <div class="card" style="border-style:dashed">
          <div class="card-body" style="display:grid;gap:12px">
            <div style="display:flex;justify-content:space-between;gap:16px;align-items:flex-start;flex-wrap:wrap">
              <div>
                <div class="fw-600" style="font-size:15px"><?= e($shift['cashier_name']) ?></div>
                <div class="text-muted" style="font-size:12px"><?= __('shift_opened_at') ?>: <?= date_fmt((string)$shift['opened_at']) ?></div>
                <div class="text-muted" style="font-size:12px"><?= __('shift_allowed_until') ?>: <?= date_fmt($currentAllowedUntil->format('Y-m-d H:i:s')) ?></div>
              </div>
              <div style="text-align:right">
                <div class="badge badge-<?= shift_is_frozen_open($shift) ? 'warning' : 'success' ?>">
                  <?= shift_is_frozen_open($shift) ? __('shift_status_expired') : __('shift_status_open') ?>
                </div>
                <div class="text-muted" style="font-size:12px;margin-top:6px"><?= __('shift_extension_remaining') ?>: <?= $remainingMinutes ?> <?= __('shift_minutes_label') ?></div>
                <div class="text-muted" style="font-size:12px"><?= __('shift_extension_max_deadline') ?>: <?= date_fmt(shift_extension_max_deadline($shift)->format('Y-m-d H:i:s')) ?></div>
              </div>
            </div>

            <?php if (!empty($shift['extended_until'])): ?>
            <div class="text-muted" style="font-size:12px">
              <?= __('shift_extended_until') ?>: <?= date_fmt((string)$shift['extended_until']) ?>
              <?php if (!empty($shift['extension_approved_by']) && !empty($shift['extension_approver_name'])): ?>
                • <?= __('shift_extension_approved_by') ?>: <?= e($shift['extension_approver_name']) ?>
              <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($directState['ok'])): ?>
            <form method="POST" style="display:grid;gap:10px">
              <?= csrf_field() ?>
              <input type="hidden" name="shift_id" value="<?= (int)$shift['id'] ?>">
              <div style="display:flex;gap:8px;flex-wrap:wrap">
                <?php foreach ($directOptions as $minutes): ?>
                  <button type="submit" name="direct_preset_minutes" value="<?= (int)$minutes ?>" class="btn btn-sm btn-primary">
                    <?= feather_icon('plus-circle', 14) ?> +<?= (int)$minutes ?> <?= __('shift_minutes_label') ?>
                  </button>
                <?php endforeach; ?>
              </div>
              <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                <input type="number" name="direct_minutes" class="form-control mono" min="1" max="<?= max(1, $remainingMinutes) ?>" placeholder="<?= __('shift_extension_custom_minutes') ?>" style="max-width:170px">
                <input type="text" name="direct_reason" class="form-control" placeholder="<?= __('shift_extension_reason') ?>" style="max-width:260px">
                <button type="submit" name="action" value="grant_direct_custom" class="btn btn-success">
                  <?= feather_icon('check-circle', 15) ?> <?= __('shift_extension_direct_add_button') ?>
                </button>
              </div>
            </form>
            <?php else: ?>
            <div class="text-muted"><?= e($directState['message'] ?? __('shift_extension_not_available')) ?></div>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<div class="card mb-3">
  <div class="card-header"><span class="card-title"><?= __('shift_extension_pending_title') ?></span></div>
  <div class="card-body" style="display:grid;gap:14px">
    <?php if (!$pendingRequests): ?>
      <div class="text-muted"><?= __('shift_extension_pending_none') ?></div>
    <?php else: ?>
      <?php foreach ($pendingRequests as $request): ?>
        <?php
        $remainingMinutes = shift_remaining_extension_minutes($request);
        $currentAllowedUntil = shift_allowed_until($request);
        $maxDeadline = shift_extension_max_deadline($request);
        $requestOptions = shift_request_options_for_remaining($remainingMinutes);
        ?>
        <div class="card" style="border-style:dashed">
          <div class="card-body" style="display:grid;gap:12px">
            <div style="display:flex;justify-content:space-between;gap:16px;align-items:flex-start;flex-wrap:wrap">
              <div>
                <div class="fw-600" style="font-size:15px"><?= e($request['cashier_name']) ?></div>
                <div class="text-muted" style="font-size:12px"><?= __('shift_opened_at') ?>: <?= date_fmt((string)$request['opened_at']) ?></div>
                <div class="text-muted" style="font-size:12px"><?= __('shift_extension_requested_at') ?>: <?= date_fmt((string)$request['requested_at']) ?></div>
              </div>
              <div style="text-align:right">
                <div class="badge badge-warning"><?= __('shift_status_expired') ?></div>
                <div class="text-muted" style="font-size:12px;margin-top:6px"><?= __('shift_allowed_until') ?>: <?= date_fmt($currentAllowedUntil->format('Y-m-d H:i:s')) ?></div>
                <div class="text-muted" style="font-size:12px"><?= __('shift_extension_max_deadline') ?>: <?= date_fmt($maxDeadline->format('Y-m-d H:i:s')) ?></div>
              </div>
            </div>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px">
              <div>
                <div class="text-muted" style="font-size:11px;text-transform:uppercase"><?= __('shift_extension_requested_minutes') ?></div>
                <div class="fw-600"><?= $request['requested_minutes'] ? (int)$request['requested_minutes'] . ' ' . __('shift_minutes_label') : '—' ?></div>
              </div>
              <div>
                <div class="text-muted" style="font-size:11px;text-transform:uppercase"><?= __('shift_extension_remaining') ?></div>
                <div class="fw-600"><?= $remainingMinutes ?> <?= __('shift_minutes_label') ?></div>
              </div>
            </div>

            <div>
              <div class="text-muted" style="font-size:11px;text-transform:uppercase"><?= __('shift_extension_reason') ?></div>
              <div><?= e($request['reason'] ?: '—') ?></div>
            </div>

            <form method="POST" style="display:grid;gap:10px">
              <?= csrf_field() ?>
              <input type="hidden" name="request_id" value="<?= (int)$request['id'] ?>">
              <div style="display:flex;gap:8px;flex-wrap:wrap">
                <?php foreach ($requestOptions as $minutes): ?>
                  <button type="submit" name="preset_minutes" value="<?= (int)$minutes ?>" class="btn btn-sm btn-primary">
                    <?= feather_icon('check', 14) ?> +<?= (int)$minutes ?> <?= __('shift_minutes_label') ?>
                  </button>
                <?php endforeach; ?>
              </div>
              <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                <input type="number" name="approved_minutes" class="form-control mono" min="1" max="<?= max(1, $remainingMinutes) ?>" placeholder="<?= __('shift_extension_custom_minutes') ?>" style="max-width:170px">
                <button type="submit" name="action" value="approve_custom" class="btn btn-success">
                  <?= feather_icon('check-circle', 15) ?> <?= __('shift_extension_approve_custom') ?>
                </button>
                <button type="submit" name="action" value="reject" class="btn btn-danger">
                  <?= feather_icon('x-circle', 15) ?> <?= __('shift_extension_reject') ?>
                </button>
              </div>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="card-header"><span class="card-title"><?= __('shift_extension_history_title') ?></span></div>
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th><?= __('shift_cashier') ?></th>
          <th><?= __('shift_extension_requested_at') ?></th>
          <th><?= __('shift_extension_requested_minutes') ?></th>
          <th><?= __('lbl_status') ?></th>
          <th><?= __('shift_extension_expires_at') ?></th>
          <th><?= __('shift_extension_approved_by') ?></th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$historyRequests): ?>
          <tr><td colspan="6" class="text-center text-muted" style="padding:24px"><?= __('no_results') ?></td></tr>
        <?php else: ?>
          <?php foreach ($historyRequests as $request): ?>
          <tr>
            <td class="fw-600"><?= e($request['cashier_name']) ?></td>
            <td><?= date_fmt((string)$request['requested_at']) ?></td>
            <td><?= $request['requested_minutes'] ? (int)$request['requested_minutes'] . ' ' . __('shift_minutes_label') : '—' ?></td>
            <td>
              <?php
              $status = (string)$request['status'];
              $badgeClass = match ($status) {
                  'approved' => 'success',
                  'rejected' => 'danger',
                  'expired' => 'warning',
                  default => 'secondary',
              };
              ?>
              <span class="badge badge-<?= $badgeClass ?>"><?= __('shift_extension_status_' . $status) ?></span>
            </td>
            <td><?= $request['expires_at'] ? date_fmt((string)$request['expires_at']) : '—' ?></td>
            <td><?= e($request['approver_name'] ?: '—') ?></td>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php endif; ?>

<?php include __DIR__ . '/../../views/layouts/footer.php'; ?>
<script src="https://unpkg.com/feather-icons/dist/feather.min.js"></script>
<script>feather.replace();</script>
