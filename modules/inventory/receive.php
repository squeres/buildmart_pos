<?php
require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../views/partials/icons.php';
Auth::requireLogin();
Auth::requirePerm('inventory.receive');

$pageTitle   = __('inv_receive');
$breadcrumbs = [[__('inv_title'), url('modules/inventory/')], [$pageTitle, null]];
$productId   = (int)($_GET['product_id'] ?? 0);
$errors      = [];

$products = Database::all("SELECT id,name_en,name_ru,sku,unit,cost_price FROM products WHERE is_active=1 ORDER BY name_en");

if (is_post()) {
    if (!csrf_verify()) { flash_error(_r('err_csrf')); redirect($_SERVER['REQUEST_URI']); }

    $pid      = (int)($_POST['product_id'] ?? 0);
    $qty      = sanitize_float($_POST['qty']      ?? 0);
    $unitCost = sanitize_float($_POST['unit_cost'] ?? 0);
    $notes    = sanitize($_POST['notes']           ?? '');

    if (!$pid)   $errors['product_id'] = _r('lbl_required');
    if ($qty<=0) $errors['qty']        = _r('lbl_required');

    if (!$errors) {
        $warehouseId = pos_warehouse_id();
        [$qtyBefore, $qtyAfter] = update_stock_balance($pid, $warehouseId, $qty);
        Database::exec("UPDATE products SET cost_price=IF(?> 0, ?, cost_price) WHERE id=?",
            [$unitCost, $unitCost, $pid]);

        Database::insert(
            "INSERT INTO inventory_movements (product_id,warehouse_id,user_id,type,qty_change,qty_before,qty_after,unit_cost,notes,created_at)
             VALUES (?,?,?,'receipt',?,?,?,?,?,NOW())",
            [$pid, $warehouseId, Auth::id(), $qty, $qtyBefore, $qtyAfter, $unitCost ?: null, $notes]
        );

        flash_success(_r('inv_saved'));
        redirect('/modules/inventory/');
    }
}

include __DIR__ . '/../../views/layouts/header.php';
?>

<div class="page-header">
  <h1 class="page-heading"><?= __('inv_receive') ?></h1>
</div>

<div style="max-width:560px">
<div class="card">
  <div class="card-body">
    <form method="POST">
      <?= csrf_field() ?>

      <div class="form-group">
        <label class="form-label"><?= __('nav_products') ?> <span class="req">*</span></label>
        <select name="product_id" class="form-control" required>
          <option value=""><?= __('lbl_select') ?></option>
          <?php foreach ($products as $p): ?>
            <option value="<?= $p['id'] ?>"
                    data-cost="<?= $p['cost_price'] ?>"
                    <?= $productId==$p['id']?'selected':'' ?>>
              <?= e(product_name($p)) ?> — <?= e($p['sku']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <?php if (isset($errors['product_id'])): ?><div class="form-error"><?= e($errors['product_id']) ?></div><?php endif; ?>
      </div>

      <div class="form-row form-row-2">
        <div class="form-group">
          <label class="form-label"><?= __('inv_qty_change') ?> <span class="req">*</span></label>
          <input type="number" name="qty" class="form-control mono" min="0.001" step="0.001" required placeholder="0.000">
          <?php if (isset($errors['qty'])): ?><div class="form-error"><?= e($errors['qty']) ?></div><?php endif; ?>
        </div>
        <div class="form-group">
          <label class="form-label"><?= __('inv_unit_cost') ?></label>
          <input type="number" name="unit_cost" id="unitCost" class="form-control mono" min="0" step="0.01" placeholder="0.00">
        </div>
      </div>

      <div class="form-group">
        <label class="form-label"><?= __('lbl_notes') ?></label>
        <input type="text" name="notes" class="form-control" placeholder="<?= __('inv_reference') ?>…">
      </div>

      <div style="display:flex;gap:8px">
        <button type="submit" class="btn btn-primary btn-lg"><?= feather_icon('save',17) ?> <?= __('btn_save') ?></button>
        <a href="<?= url('modules/inventory/') ?>" class="btn btn-ghost btn-lg"><?= __('btn_cancel') ?></a>
      </div>
    </form>
  </div>
</div>
</div>

<?php include __DIR__ . '/../../views/layouts/footer.php'; ?>
<script src="https://unpkg.com/feather-icons/dist/feather.min.js"></script>
<script>
feather.replace();
document.querySelector('[name=product_id]').addEventListener('change', function() {
  const opt = this.selectedOptions[0];
  const cost = opt.dataset.cost;
  if (cost && parseFloat(cost) > 0) document.getElementById('unitCost').value = cost;
});
</script>
