<?php
require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../views/partials/icons.php';
Auth::requireLogin();
Auth::requirePerm('inventory');

$pageTitle   = __('inv_history');
$breadcrumbs = [[__('inv_title'), url('modules/inventory/')], [$pageTitle, null]];

$productId = (int)($_GET['product_id'] ?? 0);
$type      = sanitize($_GET['type']    ?? '');
$from      = sanitize($_GET['from']    ?? date('Y-m-01'));
$to        = sanitize($_GET['to']      ?? date('Y-m-d'));
$page      = max(1, (int)($_GET['page'] ?? 1));

$where  = ['1=1'];
$params = [];

if ($productId > 0) { $where[] = 'm.product_id=?'; $params[] = $productId; }
if ($type !== '')   { $where[] = 'm.type=?'; $params[] = $type; }
if ($from)          { $where[] = 'DATE(m.created_at)>=?'; $params[] = $from; }
if ($to)            { $where[] = 'DATE(m.created_at)<=?'; $params[] = $to; }

$whereSQL = implode(' AND ', $where);
$total    = (int)Database::value("SELECT COUNT(*) FROM inventory_movements m WHERE $whereSQL", $params);
$pg       = paginate($total, $page);

$movements = Database::all(
    "SELECT m.*, p.name_en, p.name_ru, p.sku, p.unit, u.name AS user_name,
            w.name AS warehouse_name
     FROM inventory_movements m
     JOIN products p ON p.id = m.product_id
     JOIN users u ON u.id = m.user_id
     LEFT JOIN warehouses w ON w.id = m.warehouse_id
     WHERE $whereSQL
     ORDER BY m.created_at DESC
     LIMIT {$pg['perPage']} OFFSET {$pg['offset']}",
    $params
);

$products = Database::all("SELECT id,name_en,name_ru,sku FROM products ORDER BY name_en");
$types    = ['receipt','sale','return','adjustment','writeoff','transfer'];

include __DIR__ . '/../../views/layouts/header.php';
?>

<div class="page-header">
  <h1 class="page-heading"><?= __('inv_history') ?></h1>
</div>

<form method="GET" class="filter-bar mb-2">
  <select name="product_id" class="form-control" style="max-width:240px">
    <option value=""><?= __('lbl_all') ?> <?= __('nav_products') ?></option>
    <?php foreach ($products as $p): ?>
      <option value="<?= $p['id'] ?>" <?= $productId==$p['id']?'selected':'' ?>>
        <?= e(product_name($p)) ?>
      </option>
    <?php endforeach; ?>
  </select>
  <select name="type" class="form-control" style="max-width:150px">
    <option value=""><?= __('lbl_all') ?> types</option>
    <?php foreach ($types as $t): ?>
      <option value="<?= $t ?>" <?= $type===$t?'selected':'' ?>><?= movement_label($t) ?></option>
    <?php endforeach; ?>
  </select>
  <input type="date" name="from" class="form-control" value="<?= e($from) ?>" style="max-width:150px">
  <input type="date" name="to"   class="form-control" value="<?= e($to) ?>"   style="max-width:150px">
  <button type="submit" class="btn btn-secondary"><?= feather_icon('filter',14) ?></button>
  <a href="<?= url('modules/inventory/history.php') ?>" class="btn btn-ghost"><?= __('btn_reset') ?></a>
</form>

<div class="card">
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th><?= __('lbl_date') ?></th>
          <th><?= __('nav_products') ?></th>
          <th><?= __('lbl_sku') ?></th>
          <th><?= __('wh_title') ?></th>
          <th>Type</th>
          <th class="col-num"><?= __('inv_qty_before') ?></th>
          <th class="col-num"><?= __('inv_qty_change') ?></th>
          <th class="col-num"><?= __('inv_qty_after') ?></th>
          <th><?= __('inv_unit_cost') ?></th>
          <th>User</th>
          <th><?= __('lbl_notes') ?></th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$movements): ?>
          <tr><td colspan="11" class="text-center text-muted" style="padding:40px"><?= __('no_results') ?></td></tr>
        <?php else: ?>
          <?php foreach ($movements as $m): ?>
          <tr>
            <td class="text-muted" style="font-size:12px;white-space:nowrap"><?= date_fmt($m['created_at']) ?></td>
            <td class="fw-600" style="font-size:13px"><?= e(product_name($m)) ?></td>
            <td class="font-mono" style="font-size:11px"><?= e($m['sku']) ?></td>
            <td class="text-muted" style="font-size:12px"><?= e($m['warehouse_name'] ?? '—') ?></td>
            <td><span class="badge badge-<?= movement_badge_class($m['type']) ?>"><?= movement_label($m['type']) ?></span></td>
            <td class="col-num font-mono"><?= number_format((float)$m['qty_before'],3) ?></td>
            <td class="col-num font-mono fw-600" style="color:<?= $m['qty_change']>=0?'var(--success)':'var(--danger)' ?>">
              <?= ($m['qty_change']>=0?'+':'').number_format((float)$m['qty_change'],3) ?>
            </td>
            <td class="col-num font-mono"><?= number_format((float)$m['qty_after'],3) ?></td>
            <td class="text-muted"><?= $m['unit_cost'] ? money($m['unit_cost']) : '—' ?></td>
            <td class="text-secondary" style="font-size:12px"><?= e($m['user_name']) ?></td>
            <td class="text-muted" style="font-size:12px;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($m['notes'] ?? '') ?></td>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pg['pages']>1): ?>
  <div class="card-footer flex-between">
    <span class="text-secondary fs-sm"><?= $total ?> <?= __('results') ?></span>
    <div class="pagination">
      <?php for ($i=1;$i<=$pg['pages'];$i++): ?>
        <a href="?<?= http_build_query(array_merge($_GET,['page'=>$i])) ?>" class="page-link <?= $i==$pg['page']?'active':'' ?>"><?= $i ?></a>
      <?php endfor; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../../views/layouts/footer.php'; ?>
<script src="https://unpkg.com/feather-icons/dist/feather.min.js"></script>
<script>feather.replace();</script>
