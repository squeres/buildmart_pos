<?php
require_once __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/views/partials/icons.php';
Auth::requireLogin();
Auth::requirePerm('dashboard');

$pageTitle = __('nav_dashboard');
$breadcrumbs = [[$pageTitle, null]];

// ── Dashboard stats ───────────────────────────────────────────
$today = date('Y-m-d');
$selectedWhId = selected_warehouse_id();
$accessWhIds  = user_warehouse_ids();
$warehouseSql = '';
$warehouseParams = [];

if ($selectedWhId > 0) {
    $warehouseSql = ' AND s.warehouse_id = ?';
    $warehouseParams[] = $selectedWhId;
} elseif ($accessWhIds) {
    $whIn = implode(',', array_fill(0, count($accessWhIds), '?'));
    $warehouseSql = " AND s.warehouse_id IN ($whIn)";
    $warehouseParams = $accessWhIds;
}

$salesToday = Database::row(
    "SELECT COUNT(*) AS cnt, COALESCE(SUM(total),0) AS revenue
     FROM sales s
     WHERE DATE(s.created_at)=? AND s.status='completed'" . $warehouseSql,
    array_merge([$today], $warehouseParams)
);

// Profit = revenue - cost of goods sold
$profitToday = Database::value(
    "SELECT COALESCE(SUM(si.qty * (si.unit_price*(1-si.discount_pct/100) - p.cost_price)), 0)
     FROM sale_items si
     JOIN sales s ON s.id = si.sale_id
     JOIN products p ON p.id = si.product_id
     WHERE DATE(s.created_at)=? AND s.status='completed'" . $warehouseSql,
    array_merge([$today], $warehouseParams)
);

if ($selectedWhId > 0) {
    $lowStockCount = Database::value(
        "SELECT COUNT(DISTINCT sb.product_id)
         FROM stock_balances sb
         JOIN products p ON p.id = sb.product_id
         WHERE p.is_active=1
           AND p.min_stock_qty>0
           AND sb.qty<=p.min_stock_qty
           AND sb.warehouse_id=?",
        [$selectedWhId]
    );
} elseif ($accessWhIds) {
    $whIn = implode(',', array_fill(0, count($accessWhIds), '?'));
    $lowStockCount = Database::value(
        "SELECT COUNT(DISTINCT sb.product_id)
         FROM stock_balances sb
         JOIN products p ON p.id = sb.product_id
         WHERE p.is_active=1
           AND p.min_stock_qty>0
           AND sb.qty<=p.min_stock_qty
           AND sb.warehouse_id IN ($whIn)",
        $accessWhIds
    );
} else {
    $lowStockCount = 0;
}

// Recent sales (last 8)
$recentSales = Database::all(
    "SELECT s.id, s.receipt_no, s.total, s.status, s.created_at,
            u.name AS cashier, c.name AS customer
     FROM sales s
     JOIN users u ON u.id=s.user_id
     JOIN customers c ON c.id=s.customer_id
     WHERE s.status='completed'" . $warehouseSql . "
     ORDER BY s.created_at DESC LIMIT 8",
    $warehouseParams
);

// Best sellers last 30 days
$bestSellers = Database::all(
    "SELECT p.id, p.name_en, p.name_ru, p.unit,
            SUM(si.qty) AS qty_sold,
            SUM(si.line_total) AS revenue
     FROM sale_items si
     JOIN sales s ON s.id=si.sale_id
     JOIN products p ON p.id=si.product_id
     WHERE s.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND s.status='completed'" . $warehouseSql . "
     GROUP BY p.id
     ORDER BY revenue DESC LIMIT 6",
    $warehouseParams
);

// Current open shift for this user
$openShift = Database::row(
    "SELECT * FROM shifts WHERE user_id=? AND status='open' LIMIT 1",
    [Auth::id()]
);

include __DIR__ . '/views/layouts/header.php';
?>

<div class="page-header">
  <div>
    <h1 class="page-heading"><?= __('nav_dashboard') ?></h1>
    <div class="text-secondary" style="font-size:13px"><?= date_now('l, d F Y') ?></div>
  </div>
  <div class="page-actions">
    <?php if (Auth::can('pos')): ?>
    <a href="<?= url('modules/pos/') ?>" class="btn btn-primary btn-lg">
      <?= feather_icon('shopping-cart', 17) ?>
      <?= __('dash_open_pos') ?>
    </a>
    <?php endif; ?>
  </div>
</div>

<!-- Stat cards -->
<div class="grid grid-4 mb-3">
  <div class="stat-card">
    <div class="stat-icon stat-icon-amber"><?= feather_icon('shopping-bag', 20) ?></div>
    <div>
      <div class="stat-value"><?= (int)$salesToday['cnt'] ?></div>
      <div class="stat-label"><?= __('dash_sales_today') ?></div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon stat-icon-green"><?= feather_icon('trending-up', 20) ?></div>
    <div>
      <div class="stat-value" style="font-size:18px"><?= money($salesToday['revenue']) ?></div>
      <div class="stat-label"><?= __('dash_revenue_today') ?></div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon stat-icon-blue"><?= feather_icon('dollar-sign', 20) ?></div>
    <div>
      <div class="stat-value" style="font-size:18px"><?= money($profitToday) ?></div>
      <div class="stat-label"><?= __('dash_profit_today') ?></div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon <?= $lowStockCount > 0 ? 'stat-icon-red' : 'stat-icon-green' ?>"><?= feather_icon('alert-triangle', 20) ?></div>
    <div>
      <div class="stat-value"><?= (int)$lowStockCount ?></div>
      <div class="stat-label"><?= __('dash_low_stock') ?></div>
    </div>
  </div>
</div>

<!-- Shift banner -->
<?php if (Auth::can('shifts') && !$openShift): ?>
<div class="flash flash-warning mb-3" style="margin:0 0 20px">
  <?= feather_icon('alert-triangle', 16) ?>
  <span><?= __('pos_no_shift') ?></span>
  <a href="<?= url('modules/shifts/open.php') ?>" class="btn btn-sm btn-primary" style="margin-left:auto">
    <?= __('pos_open_shift') ?>
  </a>
</div>
<?php elseif ($openShift): ?>
<div class="flash flash-success mb-3" style="margin:0 0 20px">
  <?= feather_icon('check-circle', 16) ?>
  <span><?= __('shift_current') ?>: <strong><?= __('shift_status_open') ?></strong>
  — <?= __('shift_opened_at') ?> <?= date_fmt($openShift['opened_at']) ?></span>
  <a href="<?= url('modules/shifts/close.php?id='.$openShift['id']) ?>" class="btn btn-sm btn-secondary" style="margin-left:auto">
    <?= __('shift_close') ?>
  </a>
</div>
<?php endif; ?>

<!-- Main grid -->
<div class="grid" style="grid-template-columns:1fr 360px;gap:16px">
  <!-- Recent sales -->
  <div class="card">
    <div class="card-header">
      <span class="card-title"><?= __('dash_recent_sales') ?></span>
      <a href="<?= url('modules/sales/') ?>" class="btn btn-sm btn-ghost">
        <?= feather_icon('arrow-right', 14) ?>
      </a>
    </div>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th><?= __('pos_receipt_no') ?></th>
            <th><?= __('shift_cashier') ?></th>
            <th><?= __('cust_title') ?></th>
            <th class="col-num"><?= __('lbl_total') ?></th>
            <th><?= __('lbl_date') ?></th>
          </tr>
        </thead>
        <tbody>
          <?php if ($recentSales): ?>
            <?php foreach ($recentSales as $s): ?>
            <tr>
              <td><a href="<?= url('modules/sales/view.php?id='.$s['id']) ?>" class="font-mono"><?= e($s['receipt_no']) ?></a></td>
              <td><?= e($s['cashier']) ?></td>
              <td><?= e($s['customer']) ?></td>
              <td class="col-num fw-600"><?= money($s['total']) ?></td>
              <td class="text-secondary"><?= date_fmt($s['created_at'], 'd.m H:i') ?></td>
            </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr><td colspan="5" class="text-center text-muted" style="padding:30px"><?= __('no_results') ?></td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Right column -->
  <div style="display:flex;flex-direction:column;gap:16px">
    <!-- Quick actions -->
    <div class="card">
      <div class="card-header"><span class="card-title"><?= __('dash_quick_actions') ?></span></div>
      <div class="card-body" style="display:flex;flex-direction:column;gap:8px">
        <?php if (Auth::can('pos')): ?>
        <a href="<?= url('modules/pos/') ?>" class="btn btn-primary btn-block">
          <?= feather_icon('shopping-cart') ?><?= __('nav_pos') ?>
        </a>
        <?php endif; ?>
        <?php if (Auth::can('inventory')): ?>
        <a href="<?= url('modules/receipts/edit.php') ?>" class="btn btn-secondary btn-block">
          <?= feather_icon('truck') ?><?= __('nav_receipts') ?>
        </a>
        <?php endif; ?>
        <?php if (Auth::can('products')): ?>
        <a href="<?= url('modules/products/add.php') ?>" class="btn btn-secondary btn-block">
          <?= feather_icon('plus-circle') ?><?= __('prod_add') ?>
        </a>
        <?php endif; ?>
        <a href="<?= url('modules/reports/') ?>" class="btn btn-ghost btn-block">
          <?= feather_icon('bar-chart-2') ?><?= __('nav_reports') ?>
        </a>
      </div>
    </div>

    <!-- Best sellers -->
    <div class="card">
      <div class="card-header"><span class="card-title"><?= __('dash_best_sellers') ?></span></div>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th><?= __('lbl_name') ?></th><th class="col-num"><?= __('rep_total_revenue') ?></th></tr></thead>
          <tbody>
            <?php foreach ($bestSellers as $bs): ?>
            <tr>
              <td>
                <div style="font-size:12.5px;font-weight:500"><?= e(product_name($bs)) ?></div>
                <div class="text-muted font-mono" style="font-size:11px"><?= e(rtrim(rtrim(number_format((float)($bs['qty_sold'] ?? 0), 3, '.', ''), '0'), '.')) ?> <?= unit_label($bs['unit']) ?></div>
              </td>
              <td class="col-num fw-600"><?= money($bs['revenue']) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$bestSellers): ?>
            <tr><td colspan="2" class="text-center text-muted" style="padding:20px"><?= __('no_results') ?></td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/views/layouts/footer.php'; ?>
