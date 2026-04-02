<?php
require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../views/partials/icons.php';
Auth::requireLogin();
Auth::requirePerm('inventory');

$pageTitle   = __('inv_adjust');
$breadcrumbs = [[__('inv_title'), url('modules/inventory/')], [$pageTitle, null]];
$productId   = (int)($_GET['product_id'] ?? 0);
$errors      = [];

$products = Database::all("SELECT id,name_en,name_ru,sku,unit,stock_qty FROM products WHERE is_active=1 ORDER BY name_en");

if (is_post()) {
    if (!csrf_verify()) { flash_error(_r('err_csrf')); redirect($_SERVER['REQUEST_URI']); }

    $pid      = (int)($_POST['product_id'] ?? 0);
    $newQty   = sanitize_float($_POST['new_qty'] ?? -1);
    $notes    = sanitize($_POST['notes'] ?? '');

    if (!$pid)    $errors['product_id'] = _r('lbl_required');
    if ($newQty < 0) $errors['new_qty'] = _r('lbl_required');

    if (!$errors) {
        $warehouseId = pos_warehouse_id();
        [$qtyBefore, $qtyAfter] = set_stock_balance($pid, $warehouseId, $newQty);
        $change = $qtyAfter - $qtyBefore;
        Database::insert(
            "INSERT INTO inventory_movements (product_id,warehouse_id,user_id,type,qty_change,qty_before,qty_after,notes,created_at)
             VALUES (?,?,?,'adjustment',?,?,?,?,NOW())",
            [$pid, $warehouseId, Auth::id(), $change, $qtyBefore, $qtyAfter, $notes]
        );

        flash_success(_r('inv_saved'));
        redirect('/modules/inventory/');
    }
}

include __DIR__ . '/../../views/layouts/header.php';
?>

<div class="page-header">
  <h1 class="page-heading"><?= __('inv_adjust') ?></h1>
</div>

<div style="max-width:500px">
<div class="card">
  <div class="card-body">
    <form method="POST">
      <?= csrf_field() ?>

      <div class="form-group">
        <label class="form-label"><?= __('nav_products') ?> <span class="req">*</span></label>
        <select name="product_id" class="form-control" id="productSel" required>
          <option value=""><?= __('lbl_select') ?></option>
          <?php foreach ($products as $p): ?>
            <option value="<?= $p['id'] ?>"
                    data-stock="<?= $p['stock_qty'] ?>"
                    <?= $productId==$p['id']?'selected':'' ?>>
              <?= e(product_name($p)) ?> — <?= qty_display((float)$p['stock_qty'], $p['unit']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <?php if (isset($errors['product_id'])): ?><div class="form-error"><?= e($errors['product_id']) ?></div><?php endif; ?>
      </div>

      <div class="form-group">
        <label class="form-label"><?= __('inv_qty_after') ?> (new actual qty) <span class="req">*</span></label>
        <input type="number" name="new_qty" id="newQty" class="form-control mono" min="0" step="0.001" required placeholder="0.000">
        <?php if (isset($errors['new_qty'])): ?><div class="form-error"><?= e($errors['new_qty']) ?></div><?php endif; ?>
        <div class="form-hint" id="diffHint"></div>
      </div>

      <div class="form-group">
        <label class="form-label"><?= __('lbl_notes') ?> <span class="req">*</span></label>
        <input type="text" name="notes" class="form-control" required placeholder="Reason for adjustment…">
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
const sel  = document.getElementById('productSel');
const nqty = document.getElementById('newQty');
const hint = document.getElementById('diffHint');

function updateHint() {
  const opt   = sel.selectedOptions[0];
  const stock = parseFloat(opt?.dataset.stock ?? 0);
  const newQ  = parseFloat(nqty.value);
  if (!isNaN(newQ) && opt?.value) {
    const diff = newQ - stock;
    hint.textContent = 'Current: ' + stock.toLocaleString('ru-RU',{maximumFractionDigits:3})
                     + ' → Change: ' + (diff >= 0 ? '+' : '') + diff.toLocaleString('ru-RU',{maximumFractionDigits:3});
    hint.style.color = diff > 0 ? 'var(--success)' : diff < 0 ? 'var(--danger)' : 'var(--text-muted)';
  }
}

sel.addEventListener('change', () => {
  const opt = sel.selectedOptions[0];
  if (opt?.value) nqty.value = opt.dataset.stock;
  updateHint();
});
nqty.addEventListener('input', updateHint);
</script>
