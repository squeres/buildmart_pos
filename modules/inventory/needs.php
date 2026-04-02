<?php
require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../views/partials/icons.php';

Auth::requireLogin();
Auth::requirePerm('inventory');

$pageTitle = __('repl_needs_title');
$breadcrumbs = [
    [__('inv_title'), url('modules/inventory/')],
    [$pageTitle, null],
];

$search = sanitize($_GET['search'] ?? '');
$catId = (int)($_GET['cat'] ?? 0);
$rawClassFilter = strtoupper(trim((string)($_GET['class'] ?? '')));
$classFilter = in_array($rawClassFilter, ['A', 'B', 'C'], true) ? $rawClassFilter : '';
$belowOnly = !isset($_GET['below_only_present'])
    ? true
    : (string)($_GET['below_only'] ?? '') === '1';

$warehouses = user_warehouses();
$warehouseIds = array_column($warehouses, 'id');
$defaultWarehouseId = selected_warehouse_id();
$warehouseId = isset($_GET['warehouse_id']) ? (int)$_GET['warehouse_id'] : $defaultWarehouseId;
if ($warehouseId !== 0 && !in_array($warehouseId, $warehouseIds, true)) {
    $warehouseId = $defaultWarehouseId > 0 && in_array($defaultWarehouseId, $warehouseIds, true)
        ? $defaultWarehouseId
        : 0;
}

$where = ['p.is_active = 1', 'p.min_stock_qty > 0'];
$params = [];
if ($search !== '') {
    $like = '%' . $search . '%';
    $where[] = '(p.name_en LIKE ? OR p.name_ru LIKE ? OR p.sku LIKE ?)';
    array_push($params, $like, $like, $like);
}
if ($catId > 0) {
    $where[] = 'p.category_id = ?';
    $params[] = $catId;
}
if ($classFilter !== '' && replenishment_has_product_column('replenishment_class')) {
    $where[] = 'p.replenishment_class = ?';
    $params[] = $classFilter;
}
$whereSql = implode(' AND ', $where);

$warehouseLabel = __('lbl_all') . ' ' . __('wh_title');
$stockSql = '0';
$stockParams = [];
if (!$warehouseIds) {
    $products = [];
} elseif ($warehouseId > 0) {
    $warehouseLabel = (string)Database::value("SELECT name FROM warehouses WHERE id = ?", [$warehouseId]) ?: $warehouseLabel;
    $stockSql = "COALESCE((
        SELECT sb.qty
        FROM stock_balances sb
        WHERE sb.product_id = p.id AND sb.warehouse_id = ?
        LIMIT 1
    ), 0)";
    $stockParams[] = $warehouseId;
} else {
    $whIn = implode(',', array_fill(0, count($warehouseIds), '?'));
    $stockSql = "COALESCE((
        SELECT SUM(sb.qty)
        FROM stock_balances sb
        WHERE sb.product_id = p.id AND sb.warehouse_id IN ($whIn)
    ), 0)";
    $stockParams = array_merge($stockParams, $warehouseIds);
}

if (!isset($products)) {
    $products = Database::all(
        "SELECT p.id, p.name_en, p.name_ru, p.sku, p.unit, p.stock_qty,
                p.min_stock_qty, p.min_stock_display_unit_code,
                " . replenishment_product_select_sql('p') . ",
                c.name_en AS cat_en, c.name_ru AS cat_ru,
                {$stockSql} AS current_stock_qty
         FROM products p
         JOIN categories c ON c.id = p.category_id
         WHERE {$whereSql}
         ORDER BY p.name_en, p.id",
        array_merge($stockParams, $params)
    );
}

$groups = ['A' => [], 'B' => [], 'C' => []];
foreach ($products as $product) {
    $units = product_units((int)$product['id'], (string)$product['unit']);
    $state = product_replenishment_state($product, (float)$product['current_stock_qty'], $units);
    if ($classFilter !== '' && $state['class'] !== $classFilter) {
        continue;
    }
    if ($belowOnly && !$state['is_below_min_stock']) {
        continue;
    }

    $groups[$state['class']][] = [
        'row' => $product,
        'units' => $units,
        'state' => $state,
        'product_name' => product_name($product),
        'category_name' => category_name(['name_en' => $product['cat_en'], 'name_ru' => $product['cat_ru']]),
        'warehouse_name' => $warehouseLabel,
    ];
}

foreach ($groups as $classCode => $rows) {
    usort($rows, static function (array $left, array $right): int {
        $leftState = $left['state'];
        $leftState['product_name'] = $left['product_name'];
        $rightState = $right['state'];
        $rightState['product_name'] = $right['product_name'];
        return replenishment_compare_states($leftState, $rightState);
    });
    $groups[$classCode] = $rows;
}

$summary = [
    'A' => count($groups['A']),
    'B' => count($groups['B']),
    'C' => count($groups['C']),
];
$summary['total'] = $summary['A'] + $summary['B'] + $summary['C'];
$categories = Database::all("SELECT id, name_en, name_ru FROM categories WHERE is_active = 1 ORDER BY name_en");
$showWarehouseColumn = count($warehouses) > 1 || $warehouseId === 0;

$sectionMeta = [
    'A' => array_merge(replenishment_class_meta('A'), ['title' => __('repl_critical_items')]),
    'B' => array_merge(replenishment_class_meta('B'), ['title' => __('repl_medium_items')]),
    'C' => array_merge(replenishment_class_meta('C'), ['title' => __('repl_low_items')]),
];

include __DIR__ . '/../../views/layouts/header.php';
?>

<div class="page-header">
  <h1 class="page-heading"><?= __('repl_needs_title') ?></h1>
  <div class="page-actions">
    <a href="<?= url('modules/inventory/') ?>" class="btn btn-ghost"><?= feather_icon('arrow-left', 15) ?> <?= __('inv_title') ?></a>
  </div>
</div>

<form method="GET" class="filter-bar mb-3" style="align-items:flex-end;flex-wrap:wrap">
  <div class="form-group" style="margin:0;min-width:220px">
    <label class="form-label"><?= __('lbl_search') ?></label>
    <input type="text" name="search" class="form-control" value="<?= e($search) ?>" placeholder="<?= __('btn_search') ?>...">
  </div>
  <div class="form-group" style="margin:0;min-width:220px">
    <label class="form-label"><?= __('wh_title') ?></label>
    <select name="warehouse_id" class="form-control">
      <option value="0" <?= $warehouseId === 0 ? 'selected' : '' ?>><?= __('lbl_all') ?> <?= __('wh_title') ?></option>
      <?php foreach ($warehouses as $warehouse): ?>
        <option value="<?= (int)$warehouse['id'] ?>" <?= $warehouseId === (int)$warehouse['id'] ? 'selected' : '' ?>><?= e($warehouse['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="form-group" style="margin:0;min-width:220px">
    <label class="form-label"><?= __('lbl_category') ?></label>
    <select name="cat" class="form-control">
      <option value=""><?= __('lbl_all') ?></option>
      <?php foreach ($categories as $category): ?>
        <option value="<?= (int)$category['id'] ?>" <?= $catId === (int)$category['id'] ? 'selected' : '' ?>><?= e(category_name($category)) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="form-group" style="margin:0;min-width:220px">
    <label class="form-label"><?= __('repl_class') ?></label>
    <select name="class" class="form-control">
      <option value=""><?= __('lbl_all') ?></option>
      <?php foreach (replenishment_class_options() as $optionCode => $optionLabel): ?>
        <option value="<?= e($optionCode) ?>" <?= $classFilter === $optionCode ? 'selected' : '' ?>><?= e($optionCode . ' — ' . $optionLabel) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <input type="hidden" name="below_only_present" value="1">
  <label class="form-check" style="margin:0 8px 10px 0">
    <input type="checkbox" name="below_only" value="1" <?= $belowOnly ? 'checked' : '' ?>>
    <span class="form-check-label"><?= __('repl_only_below_min') ?></span>
  </label>
  <button type="submit" class="btn btn-secondary"><?= feather_icon('search', 14) ?> <?= __('btn_filter') ?></button>
  <a href="<?= url('modules/inventory/needs.php') ?>" class="btn btn-ghost"><?= __('btn_reset') ?></a>
</form>

<div class="grid grid-4 mb-3">
  <div class="stat-card">
    <div class="stat-icon stat-icon-red"><?= feather_icon('alert-octagon', 20) ?></div>
    <div><div class="stat-value"><?= $summary['A'] ?></div><div class="stat-label"><?= __('repl_critical_items') ?></div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon stat-icon-amber"><?= feather_icon('alert-triangle', 20) ?></div>
    <div><div class="stat-value"><?= $summary['B'] ?></div><div class="stat-label"><?= __('repl_medium_items') ?></div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon stat-icon-blue"><?= feather_icon('info', 20) ?></div>
    <div><div class="stat-value"><?= $summary['C'] ?></div><div class="stat-label"><?= __('repl_low_items') ?></div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon stat-icon-green"><?= feather_icon('layers', 20) ?></div>
    <div><div class="stat-value"><?= $summary['total'] ?></div><div class="stat-label"><?= __('repl_total_to_restock') ?></div></div>
  </div>
</div>

<?php foreach (['A', 'B', 'C'] as $classCode): ?>
  <?php $meta = $sectionMeta[$classCode]; ?>
  <div class="card mb-3" style="border-color:<?= e($meta['card_border']) ?>;background:linear-gradient(180deg, <?= e($meta['card_bg']) ?> 0%, rgba(0,0,0,0) 160px)">
    <div class="card-header" style="border-bottom-color:<?= e($meta['card_border']) ?>">
      <div>
        <span class="card-title"><?= e($meta['title']) ?></span>
        <div class="form-hint" style="margin-top:4px"><?= e($meta['description']) ?></div>
      </div>
      <span class="badge badge-<?= e($meta['badge_class']) ?>"><?= count($groups[$classCode]) ?></span>
    </div>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th style="width:52px">№</th>
            <th><?= __('lbl_name') ?></th>
            <th><?= __('lbl_sku') ?></th>
            <th><?= __('lbl_category') ?></th>
            <?php if ($showWarehouseColumn): ?>
              <th><?= __('wh_title') ?></th>
            <?php endif; ?>
            <th class="col-num"><?= __('prod_stock_qty') ?></th>
            <th class="col-num"><?= __('prod_min_stock') ?></th>
            <th class="col-num"><?= __('repl_target_stock') ?></th>
            <th class="col-num"><?= __('repl_qty_to_order') ?></th>
            <th><?= __('lbl_status') ?></th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$groups[$classCode]): ?>
            <tr>
              <td colspan="<?= $showWarehouseColumn ? 10 : 9 ?>" class="text-center text-muted" style="padding:28px"><?= __('no_results') ?></td>
            </tr>
          <?php else: ?>
            <?php foreach ($groups[$classCode] as $index => $item): ?>
              <?php $state = $item['state']; ?>
              <tr>
                <td class="text-muted"><?= $index + 1 ?></td>
                <td>
                  <div class="fw-600"><?= e($item['product_name']) ?></div>
                  <div style="margin-top:4px"><?= replenishment_class_badge($state['class']) ?></div>
                </td>
                <td class="font-mono" style="font-size:12px"><?= e($item['row']['sku']) ?></td>
                <td><?= e($item['category_name']) ?></td>
                <?php if ($showWarehouseColumn): ?>
                  <td><?= e($item['warehouse_name']) ?></td>
                <?php endif; ?>
                <td class="col-num font-mono"><?= e($state['current_stock_text']) ?></td>
                <td class="col-num text-muted"><?= e($state['min_stock']['full_text']) ?></td>
                <td class="col-num text-muted"><?= (float)$state['target_stock']['base_qty'] > 0 ? e($state['target_stock']['full_text']) : '—' ?></td>
                <td class="col-num fw-600"><?= (float)$state['qty_to_order'] > 0 ? e($state['qty_to_order_text']) : '—' ?></td>
                <td>
                  <?= replenishment_status_badge($state) ?>
                  <?php if ($state['is_out_of_stock']): ?>
                    <div style="margin-top:4px"><?= replenishment_class_badge($state['class']) ?></div>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endforeach; ?>

<?php include __DIR__ . '/../../views/layouts/footer.php'; ?>
<script src="https://unpkg.com/feather-icons/dist/feather.min.js"></script>
<script>feather.replace();</script>
