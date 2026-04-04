<?php
/**
 * modules/inventory/balances.php
 * Stock balances per warehouse — shows every product's qty on each warehouse.
 */
require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../views/partials/icons.php';
Auth::requireLogin();
Auth::requirePerm('inventory');

$pageTitle   = __('wh_balances');
$breadcrumbs = [[__('inv_title'), url('modules/inventory/')], [$pageTitle, null]];

$myWhIds = user_warehouse_ids();
$whPlaceholders = implode(',', array_fill(0, count($myWhIds), '?'));

$search  = sanitize($_GET['search'] ?? '');
$whId    = (int)($_GET['wh'] ?? 0);
$page    = max(1, (int)($_GET['page'] ?? 1));

// All accessible warehouses for column headers
$warehouses = user_warehouses();

$where  = ["p.is_active = 1"];
$params = [];

if ($search !== '') {
    $like = '%' . $search . '%';
    $where[] = "(p.name_en LIKE ? OR p.name_ru LIKE ? OR p.sku LIKE ?)";
    array_push($params, $like, $like, $like);
}

// Filter by warehouse: only show products that have any stock on that wh
if ($whId > 0 && in_array($whId, $myWhIds)) {
    $where[] = "EXISTS (SELECT 1 FROM stock_balances sb WHERE sb.product_id=p.id AND sb.warehouse_id=? AND ABS(sb.qty) > 0.000001)";
    $params[] = $whId;
}

$whereSQL = implode(' AND ', $where);
$total    = (int)Database::value("SELECT COUNT(*) FROM products p WHERE $whereSQL", $params);
$pg       = paginate($total, $page, 25);

$products = Database::all(
    "SELECT p.id, p.name_en, p.name_ru, p.sku, p.unit, p.min_stock_qty,
            p.stock_qty AS total_qty,
            c.name_en AS cat_en, c.name_ru AS cat_ru
     FROM products p
     LEFT JOIN categories c ON c.id = p.category_id
     WHERE $whereSQL
     ORDER BY p.name_en
     LIMIT {$pg['perPage']} OFFSET {$pg['offset']}",
    $params
);
$displayMap = [];
foreach ($products as $productRow) {
    $units = product_units((int)$productRow['id'], $productRow['unit']);
    $displayMap[(int)$productRow['id']] = [
        'units' => $units,
        'default_unit' => product_default_unit((int)$productRow['id'], $productRow['unit']),
    ];
}

// Pre-fetch all stock_balances for displayed products × accessible warehouses
$productIds = array_column($products, 'id');
$balanceMap = []; // [product_id][warehouse_id] = qty

if ($productIds && $myWhIds) {
    $pPlaceholders = implode(',', array_fill(0, count($productIds), '?'));
    $balanceRows = Database::all(
        "SELECT product_id, warehouse_id, qty
         FROM stock_balances
         WHERE product_id IN ($pPlaceholders)
           AND warehouse_id IN ($whPlaceholders)",
        array_merge($productIds, $myWhIds)
    );
    foreach ($balanceRows as $row) {
        $balanceMap[$row['product_id']][$row['warehouse_id']] = (float)$row['qty'];
    }
}

include __DIR__ . '/../../views/layouts/header.php';
?>

<div class="page-header">
  <h1 class="page-heading"><?= __('wh_balances') ?></h1>
  <div class="page-actions">
    <?php if (Auth::can('transfers.create')): ?>
    <a href="<?= url('modules/transfers/create.php') ?>" class="btn btn-secondary">
      <?= feather_icon('shuffle', 14) ?> <?= __('tr_create') ?>
    </a>
    <?php endif; ?>
  </div>
</div>

<form method="GET" class="filter-bar mb-2">
  <input type="text" name="search" class="form-control" placeholder="<?= __('btn_search') ?>…"
         value="<?= e($search) ?>" style="max-width:220px">
  <select name="wh" class="form-control" style="max-width:200px">
    <option value=""><?= __('lbl_all') ?> <?= __('wh_title') ?></option>
    <?php foreach ($warehouses as $w): ?>
      <option value="<?= $w['id'] ?>" <?= $whId==$w['id']?'selected':'' ?>><?= e($w['name']) ?></option>
    <?php endforeach; ?>
  </select>
  <button type="submit" class="btn btn-secondary"><?= feather_icon('search',14) ?> <?= __('btn_filter') ?></button>
  <a href="<?= url('modules/inventory/balances.php') ?>" class="btn btn-ghost"><?= __('btn_reset') ?></a>
</form>

<div class="card">
  <div class="table-wrap">
    <table class="table" style="min-width:600px">
      <thead>
        <tr>
          <th style="min-width:220px"><?= __('lbl_name') ?></th>
          <th><?= __('lbl_sku') ?></th>
          <th><?= __('lbl_unit') ?></th>
          <?php foreach ($warehouses as $w): ?>
            <th class="col-num" style="white-space:nowrap" title="<?= e($w['name']) ?>">
              <?= e(mb_strlen($w['name']) > 14 ? mb_substr($w['name'],0,12).'…' : $w['name']) ?>
            </th>
          <?php endforeach; ?>
          <th class="col-num fw-600"><?= __('wh_balance_total') ?></th>
          <th><?= __('lbl_status') ?></th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$products): ?>
          <tr><td colspan="<?= 6 + count($warehouses) ?>" class="text-center text-muted" style="padding:40px">
            <?= __('no_results') ?>
          </td></tr>
        <?php else: ?>
          <?php foreach ($products as $p): ?>
          <?php
            $whQtys = [];
            foreach ($warehouses as $w) {
                $whQtys[$w['id']] = $balanceMap[$p['id']][$w['id']] ?? 0.0;
            }
            $total = array_sum($whQtys);
          ?>
          <tr>
            <td>
              <div class="fw-600"><?= e(product_name($p)) ?></div>
              <div class="text-muted fs-sm"><?= e(category_name(['name_en'=>$p['cat_en'],'name_ru'=>$p['cat_ru']])) ?></div>
            </td>
            <td class="font-mono" style="font-size:12px"><?= e($p['sku']) ?></td>
            <td><?= e(product_unit_label_text($displayMap[(int)$p['id']]['default_unit'])) ?></td>
            <?php foreach ($warehouses as $w): ?>
              <?php $q = $whQtys[$w['id']]; ?>
              <td class="col-num <?= abs($q) < 0.000001 ? 'text-muted' : '' ?>" style="font-family:monospace;<?= $q < 0 ? 'color:var(--danger);font-weight:600' : '' ?>">
                <?= abs($q) >= 0.000001 ? e(product_stock_breakdown($q, $displayMap[(int)$p['id']]['units'], $p['unit'])) : '<span style="color:var(--border-medium)">&mdash;</span>' ?>
              </td>
            <?php endforeach; ?>
            <td class="col-num fw-600" style="font-family:monospace">
              <?= e(product_stock_breakdown($total, $displayMap[(int)$p['id']]['units'], $p['unit'])) ?>
            </td>
            <td><?= stock_badge($total, (float)$p['min_stock_qty']) ?></td>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($pg['pages'] > 1): ?>
  <div class="card-footer flex-between">
    <div class="text-secondary fs-sm">
      <?= __('showing') ?> <?= $pg['offset']+1 ?>–<?= min($pg['offset']+$pg['perPage'], $total) ?>
      <?= __('of') ?> <?= $total ?> <?= __('results') ?>
    </div>
    <div class="pagination">
      <?php for ($i = 1; $i <= $pg['pages']; $i++): ?>
        <?php $q = array_merge($_GET, ['page'=>$i]); ?>
        <a href="?<?= http_build_query($q) ?>" class="page-link <?= $i==$pg['page']?'active':'' ?>"><?= $i ?></a>
      <?php endfor; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../../views/layouts/footer.php'; ?>
<script src="https://unpkg.com/feather-icons/dist/feather.min.js"></script>
<script>feather.replace();</script>
