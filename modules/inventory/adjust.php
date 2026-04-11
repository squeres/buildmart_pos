<?php
require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../views/partials/icons.php';

Auth::requireLogin();
Auth::requirePerm('inventory.adjust');

$pageTitle = __('inv_adjust');
$breadcrumbs = [[__('inv_title'), url('modules/inventory/')], [$pageTitle, null]];
$productId = (int)($_GET['product_id'] ?? 0);
$errors = [];
$warehouseId = pos_warehouse_id();

$products = Database::all(
    "SELECT p.id, p.name_en, p.name_ru, p.sku, p.unit, COALESCE(sb.qty, 0) AS stock_qty
     FROM products p
     LEFT JOIN stock_balances sb ON sb.product_id = p.id AND sb.warehouse_id = ?
     WHERE p.is_active = 1
     ORDER BY p.name_en",
    [$warehouseId]
);

if (is_post()) {
    if (!csrf_verify()) {
        flash_error(_r('err_csrf'));
        redirect($_SERVER['REQUEST_URI']);
    }

    $pid = (int)($_POST['product_id'] ?? 0);
    $newQty = sanitize_float($_POST['new_qty'] ?? -1);
    $notes = sanitize($_POST['notes'] ?? '');

    if (!$pid) {
        $errors['product_id'] = _r('lbl_required');
    }
    if ($newQty < 0) {
        $errors['new_qty'] = _r('lbl_required');
    }

    if (!$errors) {
        [$qtyBefore, $qtyAfter] = set_stock_balance($pid, $warehouseId, $newQty);
        $change = $qtyAfter - $qtyBefore;
        Database::insert(
            "INSERT INTO inventory_movements (product_id, warehouse_id, user_id, type, qty_change, qty_before, qty_after, notes, created_at)
             VALUES (?, ?, ?, 'adjustment', ?, ?, ?, ?, NOW())",
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
          <div class="product-search-field">
            <div class="product-search-main">
              <select name="product_id" class="form-control" id="productSel" required>
                <option value=""><?= __('lbl_select') ?></option>
                <?php foreach ($products as $product): ?>
                  <option
                    value="<?= (int)$product['id'] ?>"
                    data-stock="<?= e((string)$product['stock_qty']) ?>"
                    <?= $productId === (int)$product['id'] ? 'selected' : '' ?>
                  >
                    <?= e(product_name($product)) ?> - <?= e(qty_display((float)$product['stock_qty'], (string)$product['unit'])) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="product-field-actions">
              <button
                type="button"
                class="product-field-icon product-camera-trigger"
                id="inventoryAdjustCameraBtn"
                title="<?= e(__('camera_scan_title')) ?>"
                hidden
              >
                <?= feather_icon('camera', 15) ?>
              </button>
            </div>
          </div>
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
          <input type="text" name="notes" class="form-control" required placeholder="<?= __('inv_adjust_reason_ph') ?>">
        </div>

        <div style="display:flex;gap:8px">
          <button type="submit" class="btn btn-primary btn-lg"><?= feather_icon('save', 17) ?> <?= __('btn_save') ?></button>
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

const sel = document.getElementById('productSel');
const nqty = document.getElementById('newQty');
const hint = document.getElementById('diffHint');
const adjustCameraBtn = document.getElementById('inventoryAdjustCameraBtn');

function updateHint() {
  const option = sel.selectedOptions[0];
  const stock = parseFloat(option?.dataset.stock ?? 0);
  const newQty = parseFloat(nqty.value);
  if (!Number.isNaN(newQty) && option?.value) {
    const diff = newQty - stock;
    hint.textContent = 'Current: ' + stock.toLocaleString('ru-RU', { maximumFractionDigits: 3 })
      + ' -> Change: ' + (diff >= 0 ? '+' : '') + diff.toLocaleString('ru-RU', { maximumFractionDigits: 3 });
    hint.style.color = diff > 0 ? 'var(--success)' : (diff < 0 ? 'var(--danger)' : 'var(--text-muted)');
  } else {
    hint.textContent = '';
  }
}

sel.addEventListener('change', () => {
  const option = sel.selectedOptions[0];
  if (option?.value) {
    nqty.value = option.dataset.stock;
  }
  updateHint();
});
nqty.addEventListener('input', updateHint);
updateHint();

if (adjustCameraBtn && window.ProductCameraScanner) {
  window.ProductCameraScanner.attach(adjustCameraBtn, {
    onDetected: async (code) => {
      const searchUrl = new URL(<?= json_for_html(url('modules/inventory/search_products.php')) ?>, window.location.origin);
      searchUrl.searchParams.set('warehouse_id', <?= (int)$warehouseId ?>);
      searchUrl.searchParams.set('q', code);
      const response = await fetch(searchUrl.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
      const payload = await response.json();
      const products = Array.isArray(payload?.products) ? payload.products : [];
      const exact = products.find((product) => (
        String(product.barcode || '').trim() === code
        || String(product.sku || '').trim().toLowerCase() === String(code).trim().toLowerCase()
      )) || (products.length === 1 ? products[0] : null);
      if (exact) {
        sel.value = String(exact.id);
        sel.dispatchEvent(new Event('change', { bubbles: true }));
      }
    }
  });
}
</script>
