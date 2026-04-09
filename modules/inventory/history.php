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
$types    = ['receipt','sale','return','adjustment','inventory','writeoff','transfer'];

include __DIR__ . '/../../views/layouts/header.php';
?>

<div class="page-header">
  <h1 class="page-heading"><?= __('inv_history') ?></h1>
</div>

<form method="GET" class="filter-bar mobile-form-stack mb-2">
  <select name="product_id" class="form-control filter-field-xl">
    <option value=""><?= __('lbl_all') ?> <?= __('nav_products') ?></option>
    <?php foreach ($products as $p): ?>
      <option value="<?= $p['id'] ?>" <?= $productId==$p['id']?'selected':'' ?>>
        <?= e(product_name($p)) ?>
      </option>
    <?php endforeach; ?>
  </select>
  <select name="type" class="form-control filter-field-sm">
    <option value=""><?= __('lbl_all') ?> <?= __('lbl_type') ?></option>
    <?php foreach ($types as $t): ?>
      <option value="<?= $t ?>" <?= $type===$t?'selected':'' ?>><?= movement_label($t) ?></option>
    <?php endforeach; ?>
  </select>
  <input type="date" name="from" class="form-control filter-field-sm" value="<?= e($from) ?>">
  <input type="date" name="to"   class="form-control filter-field-sm" value="<?= e($to) ?>">
  <div class="filter-actions">
    <button type="submit" class="btn btn-secondary"><?= feather_icon('filter',14) ?> <?= __('btn_filter') ?></button>
    <a href="<?= url('modules/inventory/history.php') ?>" class="btn btn-ghost"><?= __('btn_reset') ?></a>
  </div>
</form>

<div class="card">
  <div class="table-wrap desktop-only mobile-table-scroll">
    <table class="table">
      <thead>
        <tr>
          <th><?= __('lbl_date') ?></th>
          <th><?= __('nav_products') ?></th>
          <th><?= __('lbl_sku') ?></th>
          <th><?= __('wh_title') ?></th>
          <th><?= __('lbl_type') ?></th>
          <th class="col-num"><?= __('inv_qty_before') ?></th>
          <th class="col-num"><?= __('inv_qty_change') ?></th>
          <th class="col-num"><?= __('inv_qty_after') ?></th>
          <th><?= __('inv_unit_cost') ?></th>
          <th><?= __('nav_users') ?></th>
          <th><?= __('lbl_notes') ?></th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$movements): ?>
          <tr><td colspan="11" class="text-center text-muted table-empty-cell"><?= __('no_results') ?></td></tr>
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
  <div class="mobile-card-list mobile-only">
    <?php if (!$movements): ?>
      <div class="empty-state">
        <div class="empty-state-icon"><?= feather_icon('clock', 36) ?></div>
        <div class="empty-state-title"><?= __('no_results') ?></div>
      </div>
    <?php else: ?>
      <?php foreach ($movements as $m): ?>
        <div class="mobile-record-card">
          <div class="mobile-record-header">
            <div class="mobile-record-main">
              <div class="mobile-record-title"><?= e(product_name($m)) ?></div>
              <div class="mobile-record-subtitle"><?= e($m['sku']) ?></div>
            </div>
            <div class="mobile-badge-row">
              <span class="badge badge-<?= movement_badge_class($m['type']) ?>"><?= movement_label($m['type']) ?></span>
            </div>
          </div>

          <div class="mobile-meta-grid">
            <div class="mobile-meta-row">
              <span class="mobile-meta-row-label"><?= __('lbl_date') ?></span>
              <span class="mobile-meta-row-value"><?= date_fmt($m['created_at']) ?></span>
            </div>
            <div class="mobile-meta-row">
              <span class="mobile-meta-row-label"><?= __('wh_title') ?></span>
              <span class="mobile-meta-row-value"><?= e($m['warehouse_name'] ?? '—') ?></span>
            </div>
            <div class="mobile-meta-row">
              <span class="mobile-meta-row-label"><?= __('inv_qty_before') ?></span>
              <span class="mobile-meta-row-value"><?= number_format((float)$m['qty_before'],3) ?></span>
            </div>
            <div class="mobile-meta-row">
              <span class="mobile-meta-row-label"><?= __('inv_qty_change') ?></span>
              <span class="mobile-meta-row-value"><?= ($m['qty_change']>=0?'+':'').number_format((float)$m['qty_change'],3) ?></span>
            </div>
            <div class="mobile-meta-row">
              <span class="mobile-meta-row-label"><?= __('inv_qty_after') ?></span>
              <span class="mobile-meta-row-value"><?= number_format((float)$m['qty_after'],3) ?></span>
            </div>
            <div class="mobile-meta-row">
              <span class="mobile-meta-row-label"><?= __('inv_unit_cost') ?></span>
              <span class="mobile-meta-row-value"><?= $m['unit_cost'] ? money($m['unit_cost']) : '—' ?></span>
            </div>
            <div class="mobile-meta-row">
              <span class="mobile-meta-row-label"><?= __('nav_users') ?></span>
              <span class="mobile-meta-row-value"><?= e($m['user_name']) ?></span>
            </div>
            <?php if (!empty($m['notes'])): ?>
              <div class="mobile-meta-row">
                <span class="mobile-meta-row-label"><?= __('lbl_notes') ?></span>
                <span class="mobile-meta-row-value text-left"><?= e($m['notes']) ?></span>
              </div>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
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
