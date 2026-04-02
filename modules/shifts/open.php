<?php
require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../views/partials/icons.php';
Auth::requireLogin();
Auth::requirePerm('shifts');

$pageTitle   = __('shift_open');
$breadcrumbs = [[__('shift_title'), url('modules/shifts/')], [$pageTitle, null]];

// Check if user can open a new shift right now.
$openGuard = shift_can_open_now(Auth::id());
if (!$openGuard['ok']) {
    flash_warning($openGuard['message']);
    redirect('/modules/shifts/');
}

if (is_post()) {
    if (!csrf_verify()) { flash_error(_r('err_csrf')); redirect($_SERVER['REQUEST_URI']); }

    $openingCash = sanitize_float($_POST['opening_cash'] ?? 0);
    $notes       = sanitize($_POST['notes'] ?? '');

    try {
        $result = ShiftService::openShift(Auth::id(), $openingCash, $notes);
        flash_success($result['message']);
    } catch (AppServiceException $e) {
        flash_warning($e->getMessage());
        redirect('/modules/shifts/');
    } catch (Throwable $e) {
        error_log($e->__toString());
        flash_error(_r('err_db'));
        redirect('/modules/shifts/');
    }
    redirect('/modules/pos/');
}

include __DIR__ . '/../../views/layouts/header.php';
?>

<div class="page-header"><h1 class="page-heading"><?= __('shift_open') ?></h1></div>
<div style="max-width:440px">
<div class="card">
  <div class="card-body">
    <form method="POST">
      <?= csrf_field() ?>
      <div class="form-group">
        <label class="form-label"><?= __('shift_opening_cash') ?></label>
        <input type="number" name="opening_cash" class="form-control mono" value="0" min="0" step="0.01" style="font-size:20px;padding:12px;text-align:right" autofocus>
        <div class="form-hint">Enter the amount of cash in the register at shift start</div>
      </div>
      <div class="form-group">
        <label class="form-label"><?= __('lbl_notes') ?></label>
        <input type="text" name="notes" class="form-control" placeholder="Optional notes…">
      </div>
      <div style="display:flex;gap:8px">
        <button type="submit" class="btn btn-primary btn-lg btn-block">
          <?= feather_icon('play',18) ?> <?= __('shift_open') ?>
        </button>
      </div>
    </form>
  </div>
</div>
</div>

<?php include __DIR__ . '/../../views/layouts/footer.php'; ?>
<script src="https://unpkg.com/feather-icons/dist/feather.min.js"></script>
<script>feather.replace();</script>
