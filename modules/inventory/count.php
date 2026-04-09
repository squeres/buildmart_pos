<?php
require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../views/partials/icons.php';

Auth::requireLogin();
Auth::requirePerm('inventory.count');

$pageTitle = __('inv_count_title');
$breadcrumbs = [[__('inv_title'), url('modules/inventory/')], [$pageTitle, null]];

$warehouses = user_warehouses();
$selectedWarehouseId = (int)($_GET['warehouse_id'] ?? 0);
if ($selectedWarehouseId <= 0 || !user_can_access_warehouse($selectedWarehouseId)) {
    $selectedWarehouseId = selected_warehouse_id();
    if ($selectedWarehouseId <= 0 || !user_can_access_warehouse($selectedWarehouseId)) {
        $selectedWarehouseId = user_default_warehouse_id();
    }
}

$canApplyCount = Auth::can('inventory.apply');
$canCreateProduct = Auth::can('products.create') || Auth::can('inventory.create_product');

$extraJs = '
<script>
window.INVENTORY_COUNT_CONFIG = ' . json_encode([
    'searchUrl' => url('modules/inventory/search_products.php'),
    'saveUrl' => url('modules/inventory/save_count.php'),
    'createProductUrl' => url('modules/products/add.php'),
    'selectedWarehouseId' => $selectedWarehouseId,
    'canApply' => $canApplyCount,
    'canCreateProduct' => $canCreateProduct,
    'strings' => [
        'searchMin' => __('inv_count_search_min'),
        'notFound' => __('inv_count_not_found'),
        'createProduct' => __('inv_count_create_product'),
        'addLabel' => __('btn_add'),
        'queueEmpty' => __('inv_count_queue_empty'),
        'saveAll' => __('inv_count_save_all'),
        'saved' => __('inv_count_saved'),
        'saving' => __('loading'),
        'actualQtyRequired' => __('inv_count_actual_required'),
        'warehouseRequired' => __('inv_count_warehouse_required'),
        'changeWarehouseConfirm' => __('inv_count_change_warehouse_confirm'),
        'clearConfirm' => __('inv_count_clear_confirm'),
        'applyDenied' => __('auth_no_permission'),
        'barcode' => __('lbl_barcode'),
        'sku' => __('lbl_sku'),
        'aliases' => __('inv_count_aliases'),
        'negativeStock' => __('negative_stock'),
        'outOfStock' => __('out_of_stock'),
        'lowStock' => __('low_stock'),
        'scanHint' => __('inv_count_scan_hint'),
        'warehouseStock' => __('inv_count_current_stock'),
        'notePlaceholder' => __('inv_count_note_placeholder'),
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';
</script>
<script src="' . url('assets/js/inventory-count.js') . '"></script>';

include __DIR__ . '/../../views/layouts/header.php';
?>

<div class="page-header">
  <h1 class="page-heading"><?= __('inv_count_title') ?></h1>
  <div class="page-actions">
    <a href="<?= url('modules/inventory/') ?>" class="btn btn-ghost"><?= feather_icon('layers', 15) ?> <?= __('nav_inventory') ?></a>
    <a href="<?= url('modules/inventory/history.php?type=inventory') ?>" class="btn btn-secondary"><?= feather_icon('clock', 15) ?> <?= __('inv_history') ?></a>
  </div>
</div>

<div class="inventory-count-shell">
  <div class="inventory-count-main">
    <div class="card inventory-count-search-card">
      <div class="card-header">
        <span class="card-title"><?= __('inv_count_title') ?></span>
      </div>
      <div class="card-body">
        <div class="form-row form-row-2 inventory-count-toolbar">
          <div class="form-group mb-0">
            <label class="form-label"><?= __('wh_title') ?></label>
            <select class="form-control" id="inventoryCountWarehouse">
              <?php foreach ($warehouses as $warehouse): ?>
                <option value="<?= (int)$warehouse['id'] ?>" <?= (int)$warehouse['id'] === $selectedWarehouseId ? 'selected' : '' ?>>
                  <?= e($warehouse['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group mb-0">
            <label class="form-label"><?= __('lbl_search') ?></label>
            <div class="product-search-field">
              <div class="product-search-main">
                <input
                  type="text"
                  class="form-control inventory-count-search-input"
                  id="inventoryCountSearch"
                  placeholder="<?= e(__('inv_count_search_ph')) ?>"
                  autocomplete="off"
                  autofocus
                >
              </div>
              <div class="product-field-actions">
                <button type="button"
                        class="product-field-icon product-camera-trigger"
                        id="inventoryCountCameraBtn"
                        title="<?= e(__('camera_scan_title')) ?>"
                        hidden>
                  <?= feather_icon('camera', 15) ?>
                </button>
              </div>
            </div>
            <div class="form-hint"><?= __('inv_count_search_hint') ?></div>
          </div>
        </div>

        <div class="inventory-count-status-row">
          <div class="inventory-count-status-copy">
            <div class="inventory-count-status-title"><?= __('inv_count_scan_hint') ?></div>
            <div class="inventory-count-status-text"><?= __('inv_count_manual_hint') ?></div>
          </div>
          <?php if ($canCreateProduct): ?>
            <button type="button" class="btn btn-secondary" id="inventoryCountCreateProductBtn">
              <?= feather_icon('plus', 15) ?> <?= __('inv_count_create_product') ?>
            </button>
          <?php endif; ?>
        </div>

        <div class="inventory-count-not-found hidden" id="inventoryCountNotFound">
          <div class="inventory-count-not-found-copy">
            <strong><?= __('inv_count_not_found') ?></strong>
            <span><?= __('inv_count_not_found_hint') ?></span>
          </div>
          <?php if ($canCreateProduct): ?>
            <button type="button" class="btn btn-primary" id="inventoryCountCreateInlineBtn">
              <?= feather_icon('plus-circle', 15) ?> <?= __('inv_count_create_product') ?>
            </button>
          <?php endif; ?>
        </div>

        <div class="inventory-count-results hidden" id="inventoryCountResults"></div>
      </div>
    </div>
  </div>

  <div class="inventory-count-side">
    <div class="card inventory-count-queue-card">
      <div class="card-header">
        <span class="card-title"><?= __('inv_count_session_title') ?></span>
      </div>
      <div class="card-body">
        <div class="inventory-count-queue-actions">
          <div class="form-hint" style="margin:0"><?= __('inv_count_session_hint') ?></div>
          <div class="inventory-count-queue-buttons">
            <button type="button" class="btn btn-ghost btn-sm" id="inventoryCountClearBtn"><?= __('btn_reset') ?></button>
            <button type="button" class="btn btn-primary btn-sm" id="inventoryCountSaveBtn" <?= $canApplyCount ? '' : 'disabled' ?>>
              <?= feather_icon('save', 14) ?> <?= __('inv_count_save_all') ?>
            </button>
          </div>
        </div>

        <div class="inventory-count-queue-empty" id="inventoryCountQueueEmpty"><?= __('inv_count_queue_empty') ?></div>

        <div class="table-wrap inventory-count-queue-table-wrap desktop-only mobile-table-scroll">
          <table class="table inventory-count-queue-table hidden" id="inventoryCountQueueTable">
            <thead>
              <tr>
                <th><?= __('lbl_product') ?></th>
                <th class="col-num"><?= __('inv_count_current_stock') ?></th>
                <th class="col-num"><?= __('inv_count_actual_qty') ?></th>
                <th class="col-num"><?= __('inv_qty_change') ?></th>
                <th><?= __('lbl_notes') ?></th>
                <th class="col-actions"><?= __('lbl_actions') ?></th>
              </tr>
            </thead>
            <tbody id="inventoryCountQueueBody"></tbody>
          </table>
        </div>

        <div class="mobile-card-list mobile-only hidden" id="inventoryCountQueueCards"></div>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../../views/layouts/footer.php'; ?>
