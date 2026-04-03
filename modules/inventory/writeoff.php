<?php
require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../views/partials/icons.php';
Auth::requireLogin();
Auth::requirePerm('inventory');

$pageTitle   = __('inv_writeoff');
$breadcrumbs = [[__('inv_title'), url('modules/inventory/')], [$pageTitle, null]];
$errors      = [];
$warehouseId = pos_warehouse_id();

$products = Database::all(
    "SELECT p.id, p.name_en, p.name_ru, p.sku, p.unit, COALESCE(sb.qty, 0) AS stock_qty
     FROM products p
     LEFT JOIN stock_balances sb ON sb.product_id = p.id AND sb.warehouse_id = ?
     WHERE p.is_active = 1 AND COALESCE(sb.qty, 0) > 0
     ORDER BY p.name_en",
    [$warehouseId]
);

if (is_post()) {
    if (!csrf_verify()) { flash_error(_r('err_csrf')); redirect($_SERVER['REQUEST_URI']); }

    $pid   = (int)($_POST['product_id'] ?? 0);
    $qty   = sanitize_float($_POST['qty']  ?? 0);
    $notes = sanitize($_POST['notes'] ?? '');

    if (!$pid)   $errors['product_id'] = _r('lbl_required');
    if ($qty<=0) $errors['qty']        = _r('lbl_required');
    if (!$notes) $errors['notes']      = _r('lbl_required');

    if (!$errors) {
        $qtyBeforeWh = get_stock_qty($pid, $warehouseId);
        if ($qty > $qtyBeforeWh) {
            $errors['qty'] = 'Cannot write off more than available stock in the selected warehouse (' . $qtyBeforeWh . ')';
        }
    }

    if (!$errors) {
        [$qtyBefore, $qtyAfter] = update_stock_balance($pid, $warehouseId, -$qty);
        Database::insert(
            "INSERT INTO inventory_movements (product_id,warehouse_id,user_id,type,qty_change,qty_before,qty_after,notes,created_at)
             VALUES (?,?,?,'writeoff',?,?,?,?,NOW())",
            [$pid, $warehouseId, Auth::id(), -$qty, $qtyBefore, $qtyAfter, $notes]
        );

        flash_success(_r('inv_saved'));
        redirect('/modules/inventory/');
    }
}

include __DIR__ . '/../../views/layouts/header.php';
?>
<div class="page-header"><h1 class="page-heading"><?= __('inv_writeoff') ?></h1></div>
<div style="max-width:500px">
<div class="card">
  <div class="card-body">
    <?php if ($errors): ?><div class="flash flash-error mb-2"><?= feather_icon('alert-circle',15) ?><span><?= __('err_validation') ?></span></div><?php endif; ?>
    <form method="POST">
      <?= csrf_field() ?>
      <div class="form-group">
        <label class="form-label"><?= __('nav_products') ?> <span class="req">*</span></label>
        <select name="product_id" class="form-control" required>
          <option value=""><?= __('lbl_select') ?></option>
          <?php foreach ($products as $p): ?>
            <option value="<?= $p['id'] ?>"><?= e(product_name($p)) ?> — <?= qty_display((float)$p['stock_qty'], $p['unit']) ?></option>
          <?php endforeach; ?>
        </select>
        <?php if (isset($errors['product_id'])): ?><div class="form-error"><?= e($errors['product_id']) ?></div><?php endif; ?>
      </div>
      <div class="form-group">
        <label class="form-label"><?= __('lbl_qty') ?> to write off <span class="req">*</span></label>
        <input type="number" name="qty" class="form-control mono" min="0.001" step="0.001" required placeholder="0.000">
        <?php if (isset($errors['qty'])): ?><div class="form-error"><?= e($errors['qty']) ?></div><?php endif; ?>
      </div>
      <div class="form-group">
        <label class="form-label">Reason <span class="req">*</span></label>
        <input type="text" name="notes" class="form-control" required placeholder="<?= __('inv_writeoff_reason_ph') ?>">
        <?php if (isset($errors['notes'])): ?><div class="form-error"><?= e($errors['notes']) ?></div><?php endif; ?>
      </div>
      <div style="display:flex;gap:8px">
        <button type="submit" class="btn btn-danger btn-lg"><?= feather_icon('trash-2',17) ?> <?= __('inv_writeoff') ?></button>
        <a href="<?= url('modules/inventory/') ?>" class="btn btn-ghost btn-lg"><?= __('btn_cancel') ?></a>
      </div>
    </form>
  </div>
</div>
</div>
<?php include __DIR__ . '/../../views/layouts/footer.php'; ?>
<script src="https://unpkg.com/feather-icons/dist/feather.min.js"></script>
<script>feather.replace();</script>
