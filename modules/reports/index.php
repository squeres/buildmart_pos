<?php
require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../views/partials/icons.php';
Auth::requireLogin();
Auth::requirePerm('reports');

$pageTitle   = __('rep_title');
$breadcrumbs = [[$pageTitle, null]];
$liveStockSql = "COALESCE((SELECT SUM(sb.qty) FROM stock_balances sb WHERE sb.product_id = p.id), 0)";

// Period selection
$period = sanitize($_GET['period'] ?? 'month');
switch ($period) {
    case 'today': $from = $to = date('Y-m-d'); break;
    case 'week':  $from = date('Y-m-d', strtotime('monday this week')); $to = date('Y-m-d'); break;
    case 'month': $from = date('Y-m-01'); $to = date('Y-m-d'); break;
    default:
        $from = sanitize($_GET['from'] ?? date('Y-m-01'));
        $to   = sanitize($_GET['to']   ?? date('Y-m-d'));
        $period = 'custom';
}

$p = [$from, $to];

// Sales summary
$summary = Database::row(
    "SELECT COUNT(*) AS cnt,
            COALESCE(SUM(total),0) AS revenue,
            COALESCE(AVG(total),0) AS avg_receipt,
            COALESCE(SUM(discount_amount),0) AS total_discount
     FROM sales WHERE DATE(created_at) BETWEEN ? AND ? AND status='completed'",
    $p
);

// Profit (revenue - cost)
$profit = Database::value(
    "SELECT COALESCE(SUM(si.qty*(si.unit_price*(1-si.discount_pct/100)-pr.cost_price)),0)
     FROM sale_items si
     JOIN sales s ON s.id=si.sale_id
     JOIN products pr ON pr.id=si.product_id
     WHERE DATE(s.created_at) BETWEEN ? AND ? AND s.status='completed'",
    $p
);

// Daily sales for chart
$dailySales = Database::all(
    "SELECT DATE(created_at) AS day, COUNT(*) AS cnt, COALESCE(SUM(total),0) AS revenue
     FROM sales WHERE DATE(created_at) BETWEEN ? AND ? AND status='completed'
     GROUP BY DATE(created_at) ORDER BY day",
    $p
);

// Best sellers
$bestSellers = Database::all(
    "SELECT p.id, p.name_en, p.name_ru, p.unit, p.sku,
            SUM(si.qty) AS qty_sold, SUM(si.line_total) AS revenue,
            SUM(si.qty*(si.unit_price*(1-si.discount_pct/100)-p.cost_price)) AS profit
     FROM sale_items si
     JOIN sales s ON s.id=si.sale_id
     JOIN products p ON p.id=si.product_id
     WHERE DATE(s.created_at) BETWEEN ? AND ? AND s.status='completed'
     GROUP BY p.id ORDER BY revenue DESC LIMIT 10",
    $p
);

// Cashier report
$cashierReport = Database::all(
    "SELECT u.name, COUNT(s.id) AS sales, SUM(s.total) AS revenue
     FROM sales s JOIN users u ON u.id=s.user_id
     WHERE DATE(s.created_at) BETWEEN ? AND ? AND s.status='completed'
     GROUP BY u.id ORDER BY revenue DESC",
    $p
);

// Low stock
$lowStockOrderSql = replenishment_has_product_column('replenishment_class')
    ? "CASE p.replenishment_class WHEN 'A' THEN 1 WHEN 'B' THEN 2 ELSE 3 END, "
    : '';
$lowStock = Database::all(
    "SELECT p.id,p.name_en,p.name_ru,p.sku,p.unit,{$liveStockSql} AS stock_qty,p.min_stock_qty,p.min_stock_display_unit_code,
            " . replenishment_product_select_sql('p') . ",
            c.name_en AS cat_en,c.name_ru AS cat_ru
     FROM products p JOIN categories c ON c.id=p.category_id
     WHERE p.is_active=1 AND p.min_stock_qty>0 AND {$liveStockSql}<=p.min_stock_qty
     ORDER BY {$lowStockOrderSql}({$liveStockSql}/p.min_stock_qty) ASC LIMIT 20"
);

// Category breakdown
$catBreakdown = Database::all(
    "SELECT c.name_en,c.name_ru,c.color, SUM(si.line_total) AS revenue, COUNT(DISTINCT s.id) AS sales
     FROM sale_items si
     JOIN products p ON p.id=si.product_id
     JOIN categories c ON c.id=p.category_id
     JOIN sales s ON s.id=si.sale_id
     WHERE DATE(s.created_at) BETWEEN ? AND ? AND s.status='completed'
     GROUP BY c.id ORDER BY revenue DESC LIMIT 8",
    $p
);

$shiftReportCashiers = Auth::isManagerOrAdmin()
    ? Database::all(
        "SELECT u.id, u.name, r.slug AS role_slug
         FROM users u
         JOIN roles r ON r.id = u.role_id
         WHERE u.is_active = 1 AND r.slug IN ('cashier', 'manager', 'admin')
         ORDER BY u.name"
    )
    : [];

include __DIR__ . '/../../views/layouts/header.php';
?>

<div class="page-header">
  <h1 class="page-heading"><?= __('rep_title') ?></h1>
</div>

<!-- Period selector -->
<form method="GET" class="filter-bar mb-3">
  <div style="display:flex;gap:4px">
    <?php foreach (['today'=>__('rep_today'),'week'=>__('rep_week'),'month'=>__('rep_month'),'custom'=>__('rep_custom')] as $k=>$v): ?>
      <a href="?period=<?= $k ?>" class="btn btn-sm <?= $period===$k?'btn-primary':'btn-secondary' ?>"><?= e($v) ?></a>
    <?php endforeach; ?>
  </div>
  <?php if ($period==='custom'): ?>
  <input type="date" name="from" class="form-control" value="<?= e($from) ?>" style="max-width:150px">
  <span class="text-muted">—</span>
  <input type="date" name="to" class="form-control" value="<?= e($to) ?>" style="max-width:150px">
  <input type="hidden" name="period" value="custom">
  <button type="submit" class="btn btn-secondary"><?= feather_icon('filter',14) ?></button>
  <?php endif; ?>
  <span class="text-muted fs-sm" style="margin-left:auto"><?= date_fmt($from,'d.m.Y') ?> — <?= date_fmt($to,'d.m.Y') ?></span>
</form>

<?php if (Auth::isManagerOrAdmin()): ?>
<div class="card mb-3">
  <div class="card-header"><span class="card-title"><?= __('rep_cashier_shifts_export') ?></span></div>
  <div class="card-body">
    <form method="GET" action="<?= url('modules/reports/cashier_shifts_export.php') ?>" class="filter-bar" style="align-items:flex-end">
      <div class="form-group" style="margin:0">
        <label class="form-label" for="shiftDateFrom"><?= __('rep_from') ?></label>
        <input type="date" id="shiftDateFrom" name="date_from" class="form-control" value="<?= e($from) ?>" style="min-width:160px">
      </div>
      <div class="form-group" style="margin:0">
        <label class="form-label" for="shiftDateTo"><?= __('rep_to') ?></label>
        <input type="date" id="shiftDateTo" name="date_to" class="form-control" value="<?= e($to) ?>" style="min-width:160px">
      </div>
      <div class="form-group" style="margin:0;min-width:220px">
        <label class="form-label" for="shiftCashierId"><?= __('rep_cashier_filter') ?></label>
        <select id="shiftCashierId" name="cashier_id" class="form-control">
          <option value=""><?= __('lbl_all') ?></option>
          <?php foreach ($shiftReportCashiers as $cashier): ?>
            <option value="<?= (int)$cashier['id'] ?>"><?= e($cashier['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit" class="btn btn-primary">
        <?= feather_icon('download', 15) ?> <?= __('rep_cashier_shifts_export_btn') ?>
      </button>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- KPI cards -->
<div class="grid grid-4 mb-3">
  <div class="stat-card">
    <div class="stat-icon stat-icon-amber"><?= feather_icon('file-text',20) ?></div>
    <div><div class="stat-value"><?= $summary['cnt'] ?></div><div class="stat-label"><?= __('rep_num_receipts') ?></div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon stat-icon-green"><?= feather_icon('trending-up',20) ?></div>
    <div><div class="stat-value" style="font-size:17px"><?= money($summary['revenue']) ?></div><div class="stat-label"><?= __('rep_total_revenue') ?></div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon stat-icon-blue"><?= feather_icon('dollar-sign',20) ?></div>
    <div><div class="stat-value" style="font-size:17px"><?= money($profit) ?></div><div class="stat-label"><?= __('rep_total_profit') ?></div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon" style="background:var(--info-dim);color:var(--info);border:1px solid rgba(88,166,255,.3)"><?= feather_icon('bar-chart',20) ?></div>
    <div><div class="stat-value" style="font-size:17px"><?= money($summary['avg_receipt']) ?></div><div class="stat-label"><?= __('rep_avg_receipt') ?></div></div>
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">

  <!-- Best sellers -->
  <div class="card">
    <div class="card-header"><span class="card-title"><?= __('rep_best_sellers') ?></span></div>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>#</th><th><?= __('lbl_name') ?></th><th class="col-num">Sold</th><th class="col-num">Revenue</th></tr></thead>
        <tbody>
          <?php if (!$bestSellers): ?>
            <tr><td colspan="4" class="text-center text-muted" style="padding:30px"><?= __('no_results') ?></td></tr>
          <?php else: ?>
            <?php foreach ($bestSellers as $i=>$bs): ?>
            <tr>
              <td class="text-muted"><?= $i+1 ?></td>
              <td>
                <div class="fw-600" style="font-size:13px"><?= e(product_name($bs)) ?></div>
                <div class="text-muted font-mono" style="font-size:11px"><?= e($bs['sku']) ?></div>
              </td>
              <td class="col-num font-mono"><?= number_format((float)$bs['qty_sold'],2) ?> <?= unit_label($bs['unit']) ?></td>
              <td class="col-num fw-600"><?= money($bs['revenue']) ?></td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Category breakdown -->
  <div class="card">
    <div class="card-header"><span class="card-title">Revenue by Category</span></div>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th><?= __('lbl_category') ?></th><th class="col-num">Sales</th><th class="col-num">Revenue</th></tr></thead>
        <tbody>
          <?php if (!$catBreakdown): ?>
            <tr><td colspan="3" class="text-center text-muted" style="padding:30px"><?= __('no_results') ?></td></tr>
          <?php else: ?>
            <?php $maxRev = max(array_column($catBreakdown,'revenue') ?: [1]); ?>
            <?php foreach ($catBreakdown as $cb): ?>
            <tr>
              <td>
                <div style="display:flex;align-items:center;gap:8px">
                  <span style="width:8px;height:8px;border-radius:50%;background:<?= e($cb['color']) ?>;flex-shrink:0;display:inline-block"></span>
                  <span style="font-size:13px"><?= e(category_name($cb)) ?></span>
                </div>
                <div style="margin-top:3px;height:3px;background:var(--bg-raised);border-radius:2px">
                  <div style="height:100%;border-radius:2px;background:<?= e($cb['color']) ?>;width:<?= min(100, round($cb['revenue']/$maxRev*100)) ?>%"></div>
                </div>
              </td>
              <td class="col-num"><?= $cb['sales'] ?></td>
              <td class="col-num fw-600"><?= money($cb['revenue']) ?></td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Cashier report -->
  <div class="card">
    <div class="card-header"><span class="card-title"><?= __('rep_cashier') ?></span></div>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th><?= __('shift_cashier') ?></th><th class="col-num">Sales</th><th class="col-num">Revenue</th></tr></thead>
        <tbody>
          <?php if (!$cashierReport): ?>
            <tr><td colspan="3" class="text-center text-muted" style="padding:30px"><?= __('no_results') ?></td></tr>
          <?php else: ?>
            <?php foreach ($cashierReport as $cr): ?>
            <tr>
              <td class="fw-600"><?= e($cr['name']) ?></td>
              <td class="col-num"><?= $cr['sales'] ?></td>
              <td class="col-num fw-600"><?= money($cr['revenue']) ?></td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Low stock -->
  <div class="card">
    <div class="card-header">
      <span class="card-title"><?= __('rep_low_stock') ?></span>
      <a href="<?= url('modules/inventory/needs.php') ?>" class="btn btn-sm btn-ghost"><?= feather_icon('arrow-right',14) ?></a>
    </div>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th><?= __('lbl_name') ?></th><th><?= __('repl_class') ?></th><th class="col-num">Stock</th><th class="col-num">Min</th><th><?= __('lbl_status') ?></th></tr></thead>
        <tbody>
          <?php if (!$lowStock): ?>
            <tr><td colspan="5" class="text-center text-success" style="padding:20px"><?= feather_icon('check-circle',16) ?> All stocked OK</td></tr>
          <?php else: ?>
            <?php foreach ($lowStock as $ls): ?>
            <?php
              $lsUnits = product_units((int)$ls['id'], $ls['unit']);
              $lsDefaultUnit = product_default_unit((int)$ls['id'], $ls['unit']);
              $lsMinStock = product_min_stock_data($ls, $lsUnits);
              $lsReplenishment = product_replenishment_state($ls, (float)$ls['stock_qty'], $lsUnits);
            ?>
            <tr>
              <td>
                <div class="fw-600" style="font-size:12.5px"><?= e(product_name($ls)) ?></div>
                <div class="text-muted font-mono" style="font-size:11px"><?= e($ls['sku']) ?></div>
                <div class="text-muted" style="font-size:11px"><?= e(product_unit_label_text($lsDefaultUnit)) ?></div>
              </td>
              <td><?= replenishment_class_badge($ls['replenishment_class'] ?? 'C') ?></td>
              <td class="col-num font-mono"><?= e(product_stock_breakdown((float)$ls['stock_qty'], $lsUnits, $ls['unit'])) ?></td>
              <td class="col-num font-mono text-muted"><?= e($lsMinStock['full_text']) ?></td>
              <td><?= replenishment_status_badge($lsReplenishment) ?></td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>

<!-- Daily sales table -->
<?php if ($dailySales): ?>
<div class="card mt-3">
  <div class="card-header"><span class="card-title">Daily Breakdown</span></div>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th><?= __('lbl_date') ?></th><th class="col-num">Receipts</th><th class="col-num">Revenue</th></tr></thead>
      <tbody>
        <?php foreach ($dailySales as $d): ?>
        <tr>
          <td><?= date('l, d.m.Y', strtotime($d['day'])) ?></td>
          <td class="col-num"><?= $d['cnt'] ?></td>
          <td class="col-num fw-600"><?= money($d['revenue']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../../views/layouts/footer.php'; ?>
<script src="https://unpkg.com/feather-icons/dist/feather.min.js"></script>
<script>feather.replace();</script>
