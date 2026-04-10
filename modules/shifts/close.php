<?php
require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../views/partials/icons.php';
Auth::requireLogin();
Auth::requirePerm('shifts.close');

$id    = (int)($_GET['id'] ?? 0);
$shift = Database::row("SELECT s.*,u.name AS cashier FROM shifts s JOIN users u ON u.id=s.user_id WHERE s.id=? AND s.status='open'", [$id]);
if (!$shift) { flash_error(_r('err_not_found')); redirect('/modules/shifts/'); }
if ($shift['user_id'] != Auth::id() && !Auth::can('all')) { flash_error(_r('auth_no_permission')); redirect('/modules/shifts/'); }

$pageTitle   = __('shift_close');
$breadcrumbs = [[__('shift_title'), url('modules/shifts/')], [$pageTitle, null]];
$allowedUntil = shift_allowed_until($shift);
$effectiveHours = shift_format_duration(shift_worked_seconds($shift));

// Calculate expected cash
$cashSales    = (float)Database::value("SELECT COALESCE(SUM(p.amount),0) FROM payments p JOIN sales s ON s.id=p.sale_id WHERE s.shift_id=? AND s.status='completed' AND p.method='cash'", [$id]);
$cashReturns  = (float)Database::value("SELECT COALESCE(SUM(r.total),0) FROM returns r JOIN sales s ON s.id=r.sale_id WHERE s.shift_id=? AND r.refund_method='cash'", [$id]);
$expectedCash = (float)$shift['opening_cash'] + $cashSales - $cashReturns;

if (is_post()) {
    if (!csrf_verify()) { flash_error(_r('err_csrf')); redirect($_SERVER['REQUEST_URI']); }

    $closingCash = sanitize_float($_POST['closing_cash'] ?? 0);
    $notes       = sanitize($_POST['notes'] ?? '');

    try {
        ShiftService::closeShift($id, Auth::id(), $closingCash, $notes, Auth::can('all'));
        flash_success(_r('shift_closed'));
    } catch (AppServiceException $e) {
        flash_error($e->getMessage());
        redirect('/modules/shifts/');
    } catch (Throwable $e) {
        error_log($e->__toString());
        flash_error(_r('err_db'));
        redirect('/modules/shifts/');
    }
    redirect('/modules/shifts/');
}

include __DIR__ . '/../../views/layouts/header.php';
?>

<div class="page-header"><h1 class="page-heading"><?= __('shift_close') ?></h1></div>
<div style="max-width:520px">

<!-- Shift summary -->
<div class="card mb-3">
  <div class="card-header"><span class="card-title"><?= __('shift_current') ?></span></div>
  <div class="card-body">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
      <?php $rows = [
        [__('shift_cashier'),    $shift['cashier']],
        [__('shift_opened_at'),  date_fmt($shift['opened_at'])],
        [__('shift_allowed_until'), date_fmt($allowedUntil->format('Y-m-d H:i:s'))],
        [__('shift_effective_hours'), $effectiveHours],
        [__('shift_opening_cash'), money($shift['opening_cash'])],
        [__('shift_sales_total'), money($shift['total_sales'])],
        [__('shift_returns_total'), money($shift['total_returns'])],
        [__('shift_tx_count'),   $shift['transaction_count']],
        [__('shift_expected_cash').' (cash)', money($expectedCash)],
      ]; ?>
      <?php foreach ($rows as [$label,$val]): ?>
      <div style="display:flex;flex-direction:column;gap:2px">
        <span class="text-muted" style="font-size:11px;text-transform:uppercase;letter-spacing:.04em"><?= $label ?></span>
        <span class="fw-600 font-mono"><?= $val ?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-body">
    <form method="POST">
      <?= csrf_field() ?>
      <div class="form-group">
        <label class="form-label"><?= __('shift_closing_cash') ?></label>
        <input type="number" name="closing_cash" id="closingCash" class="form-control mono"
               value="<?= number_format($expectedCash,2,'.','') ?>"
               min="0" step="0.01" style="font-size:22px;padding:12px;text-align:right" autofocus>
        <div class="form-hint" id="diffDisplay" style="margin-top:8px;font-size:14px;font-weight:600">
          <?= __('shift_expected_cash') ?>: <?= money($expectedCash) ?>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label"><?= __('lbl_notes') ?></label>
        <textarea name="notes" class="form-control" rows="2" placeholder="<?= __('shift_handover_notes_ph') ?>"></textarea>
      </div>
      <button type="submit" class="btn btn-danger btn-block btn-lg"
              data-confirm="<?= __('confirm_close_shift') ?>">
        <?= feather_icon('square',18) ?> <?= __('shift_close') ?>
      </button>
    </form>
  </div>
</div>
</div>

<?php include __DIR__ . '/../../views/layouts/footer.php'; ?>
<script src="https://unpkg.com/feather-icons/dist/feather.min.js"></script>
<script>
feather.replace();
const expected = <?= $expectedCash ?>;
const _lblExpected    = '<?= addslashes(__('shift_expected_cash')) ?>';
const _lblDifference  = '<?= addslashes(__('shift_difference')) ?>';
document.getElementById('closingCash').addEventListener('input', function() {
  const actual = parseFloat(this.value)||0;
  const diff   = actual - expected;
  const el     = document.getElementById('diffDisplay');
  el.textContent = _lblExpected + ': ' + expected.toLocaleString('ru-RU',{minimumFractionDigits:2})
    + '  |  ' + _lblDifference + ': ' + (diff>=0?'+':'') + diff.toLocaleString('ru-RU',{minimumFractionDigits:2});
  el.style.color = Math.abs(diff)<0.01 ? 'var(--success)' : diff<0 ? 'var(--danger)' : 'var(--warning)';
});
</script>
