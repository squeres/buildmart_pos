<?php
require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../views/partials/icons.php';

Auth::requireLogin();
Auth::requirePerm('shifts');

$pageTitle   = __('shift_title');
$breadcrumbs = [[$pageTitle, null]];

if (shift_extension_schema_ready()) {
    shift_expire_stale_extension_requests();
}

$openShift = Database::row(
    "SELECT s.*, u.name AS cashier
     FROM shifts s
     JOIN users u ON u.id = s.user_id
     WHERE s.user_id = ? AND s.status = 'open'
     LIMIT 1",
    [Auth::id()]
);

$canManageExtensions = Auth::isManagerOrAdmin() && shift_extension_schema_ready();
$canOpenOwnShift = Auth::can('shifts.open');
$canCloseOwnShift = Auth::can('shifts.close');
$canRequestShiftExtension = Auth::can('shifts.extend');
$pendingExtensionCount = $canManageExtensions
    ? (int)Database::value("SELECT COUNT(*) FROM shift_extension_requests WHERE status = 'pending'")
    : 0;

$page   = max(1, (int)($_GET['page'] ?? 1));
$total  = (int)Database::value("SELECT COUNT(*) FROM shifts");
$pg     = paginate($total, $page);
$shifts = Database::all(
    "SELECT s.*, u.name AS cashier
     FROM shifts s
     JOIN users u ON u.id = s.user_id
     ORDER BY s.opened_at DESC
     LIMIT {$pg['perPage']} OFFSET {$pg['offset']}"
);

include __DIR__ . '/../../views/layouts/header.php';
?>

<div class="page-header">
  <h1 class="page-heading"><?= __('shift_title') ?></h1>
  <div class="page-actions">
    <?php if ($canManageExtensions): ?>
      <a href="<?= url('modules/shifts/extension_requests.php') ?>" class="btn btn-secondary">
        <?= feather_icon('shield', 15) ?> <?= __('shift_extension_requests_title') ?>
        <?php if ($pendingExtensionCount > 0): ?>
          <span class="badge badge-warning" style="margin-left:6px"><?= $pendingExtensionCount ?></span>
        <?php endif; ?>
      </a>
    <?php endif; ?>
    <?php if (!$openShift && $canOpenOwnShift): ?>
      <a href="<?= url('modules/shifts/open.php') ?>" class="btn btn-primary">
        <?= feather_icon('play', 15) ?> <?= __('shift_open') ?>
      </a>
    <?php elseif ($openShift && $canCloseOwnShift): ?>
      <a href="<?= url('modules/shifts/close.php?id=' . $openShift['id']) ?>" class="btn btn-danger">
        <?= feather_icon('square', 15) ?> <?= __('shift_close') ?>
      </a>
    <?php endif; ?>
  </div>
</div>

<?php if ($openShift): ?>
  <?php
  $openShiftAllowedUntil = shift_allowed_until($openShift);
  $openShiftWorked = shift_worked_seconds($openShift);
  $openShiftFrozen = shift_is_frozen_open($openShift);
  $pendingRequest = shift_pending_extension_request((int)$openShift['id']);
  ?>
  <div class="card mb-3" style="border-color:<?= $openShiftFrozen ? 'var(--warning)' : 'var(--success)' ?>;background:<?= $openShiftFrozen ? 'var(--warning-dim)' : 'var(--success-dim)' ?>">
    <div class="card-body">
      <?php if ($pendingRequest): ?>
        <div class="flash flash-info" style="margin:0 0 14px">
          <?= feather_icon('clock', 16) ?>
          <span><?= __('shift_extension_pending') ?></span>
        </div>
      <?php elseif ($openShiftFrozen): ?>
        <div class="flash flash-warning" style="margin:0 0 14px">
          <?= feather_icon('alert-triangle', 16) ?>
          <span><?= __('shift_close_expired_first', ['date' => date_fmt((string)$openShift['opened_at'])]) ?></span>
        </div>
      <?php endif; ?>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:16px;text-align:center">
        <?php
        $info = [
            [__('shift_status_label'), $openShiftFrozen
                ? '<span class="badge badge-warning">' . __('shift_status_expired') . '</span>'
                : '<span class="badge badge-success">' . __('shift_status_open') . '</span>'],
            [__('shift_opened_at'), date_fmt((string)$openShift['opened_at'])],
            [__('shift_allowed_until'), date_fmt($openShiftAllowedUntil->format('Y-m-d H:i:s'))],
            [__('shift_effective_hours'), shift_format_duration($openShiftWorked)],
            [__('shift_sales_total'), money((float)$openShift['total_sales'])],
            [__('shift_tx_count'), (string)$openShift['transaction_count']],
        ];
        ?>
        <?php foreach ($info as [$label, $value]): ?>
        <div>
          <div class="text-muted" style="font-size:11px;text-transform:uppercase;letter-spacing:.05em"><?= $label ?></div>
          <div style="font-size:16px;font-weight:700;margin-top:4px"><?= $value ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
<?php endif; ?>

<div class="card">
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th><?= __('shift_cashier') ?></th>
          <th><?= __('shift_opened_at') ?></th>
          <th><?= __('shift_closed_at') ?></th>
          <th><?= __('shift_allowed_until') ?></th>
          <th><?= __('shift_effective_hours') ?></th>
          <th class="col-num"><?= __('shift_opening_cash') ?></th>
          <th class="col-num"><?= __('shift_sales_total') ?></th>
          <th class="col-num"><?= __('shift_returns_total') ?></th>
          <th class="col-num"><?= __('shift_tx_count') ?></th>
          <th><?= __('lbl_status') ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($shifts as $shift): ?>
          <?php
          $allowedUntil = shift_allowed_until($shift);
          $effectiveHours = shift_format_duration(shift_worked_seconds($shift));
          $isFrozen = shift_is_frozen_open($shift);
          ?>
          <tr>
            <td class="fw-600"><?= e($shift['cashier']) ?></td>
            <td><?= date_fmt((string)$shift['opened_at']) ?></td>
            <td><?= shift_report_closed_label($shift) ?></td>
            <td><?= date_fmt($allowedUntil->format('Y-m-d H:i:s')) ?></td>
            <td><?= e($effectiveHours) ?></td>
            <td class="col-num font-mono"><?= money((float)$shift['opening_cash']) ?></td>
            <td class="col-num fw-600"><?= money((float)$shift['total_sales']) ?></td>
            <td class="col-num text-danger"><?= (float)$shift['total_returns'] > 0 ? money((float)$shift['total_returns']) : '—' ?></td>
            <td class="col-num"><?= (int)$shift['transaction_count'] ?></td>
            <td>
              <?php if (($shift['status'] ?? '') === 'open' && $isFrozen): ?>
                <span class="badge badge-warning"><?= __('shift_status_expired') ?></span>
              <?php elseif (($shift['status'] ?? '') === 'open'): ?>
                <span class="badge badge-success"><?= __('shift_status_open') ?></span>
              <?php else: ?>
                <span class="badge badge-secondary"><?= __('shift_status_closed') ?></span>
                <?php if (!is_null($shift['cash_difference'])): ?>
                  <span class="badge badge-<?= abs((float)$shift['cash_difference']) < 0.01 ? 'success' : ((float)$shift['cash_difference'] < 0 ? 'danger' : 'warning') ?>" style="margin-top:2px">
                    <?= ((float)$shift['cash_difference'] >= 0 ? '+' : '') . money((float)$shift['cash_difference'], false) ?> <?= e(currency_symbol()) ?>
                  </span>
                <?php endif; ?>
              <?php endif; ?>
              <?php if (!empty($shift['extended_until'])): ?>
                <div class="text-muted" style="font-size:11px;margin-top:4px">
                  <?= __('shift_extended_until') ?>: <?= date_fmt((string)$shift['extended_until']) ?>
                </div>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pg['pages'] > 1): ?>
  <div class="card-footer flex-between">
    <span class="text-secondary fs-sm"><?= $total ?> <?= __('results') ?></span>
    <div class="pagination">
      <?php for ($i = 1; $i <= $pg['pages']; $i++): ?>
        <a href="?page=<?= $i ?>" class="page-link <?= $i == $pg['page'] ? 'active' : '' ?>"><?= $i ?></a>
      <?php endfor; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../../views/layouts/footer.php'; ?>
<script src="https://unpkg.com/feather-icons/dist/feather.min.js"></script>
<script>feather.replace();</script>
