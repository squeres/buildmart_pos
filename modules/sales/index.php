<?php
require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../views/partials/icons.php';
Auth::requireLogin();
Auth::requirePerm('sales');

$pageTitle   = __('nav_sales');
$breadcrumbs = [[$pageTitle, null]];

$search = sanitize($_GET['search'] ?? '');
$from   = sanitize($_GET['from'] ?? date('Y-m-01'));
$to     = sanitize($_GET['to'] ?? date('Y-m-d'));
$status = sanitize($_GET['status'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));

$availableWarehouses = user_warehouses();
$availableWarehouseIds = array_map('intval', array_column($availableWarehouses, 'id'));
$selectedWarehouseId = selected_warehouse_id();
$boardLimit = 20;

$warehouseById = [];
foreach ($availableWarehouses as $warehouse) {
    $warehouseById[(int)$warehouse['id']] = $warehouse;
}

$selectedWarehouse = ($selectedWarehouseId > 0 && isset($warehouseById[$selectedWarehouseId]))
    ? $warehouseById[$selectedWarehouseId]
    : null;

$isMultiWarehouseMode = $selectedWarehouseId === 0 && count($availableWarehouses) > 1;

$activeBusinessEntities = [];
try {
    $activeBusinessEntities = Database::all(
        "SELECT id, name FROM business_entities WHERE is_active=1 ORDER BY name, id"
    );
} catch (Throwable $e) {
    $activeBusinessEntities = [];
}

$statusLabels = [
    'completed' => __('sales_status_completed'),
    'voided' => __('sales_status_voided'),
    'refunded' => __('sales_status_refunded'),
    'partial_refund' => __('sales_status_partial_refund'),
];

$statusClasses = [
    'completed' => 'success',
    'voided' => 'danger',
    'refunded' => 'warning',
    'partial_refund' => 'warning',
];

$baseWhere = [];
$baseParams = [];

if ($availableWarehouseIds) {
    $whPlaceholders = implode(',', array_fill(0, count($availableWarehouseIds), '?'));
    $baseWhere[] = "s.warehouse_id IN ($whPlaceholders)";
    $baseParams = array_merge($baseParams, $availableWarehouseIds);
} else {
    $baseWhere[] = '0=1';
}

if ($search !== '') {
    $like = '%' . $search . '%';
    $baseWhere[] = '(s.receipt_no LIKE ? OR c.name LIKE ? OR c.company LIKE ? OR u.name LIKE ? OR w.name LIKE ? OR si.invoice_number LIKE ?)';
    array_push($baseParams, $like, $like, $like, $like, $like, $like);
}
if ($from) {
    $baseWhere[] = 'DATE(s.created_at) >= ?';
    $baseParams[] = $from;
}
if ($to) {
    $baseWhere[] = 'DATE(s.created_at) <= ?';
    $baseParams[] = $to;
}
if ($status !== '') {
    $baseWhere[] = 's.status = ?';
    $baseParams[] = $status;
}

$baseWhereSQL = implode(' AND ', $baseWhere);
$baseFromSQL = "FROM sales s
    JOIN customers c ON c.id = s.customer_id
    JOIN users u ON u.id = s.user_id
    LEFT JOIN warehouses w ON w.id = s.warehouse_id
    LEFT JOIN sale_invoices si ON si.sale_id = s.id";

$singleWhere = $baseWhere;
$singleParams = $baseParams;
if (!$isMultiWarehouseMode && $selectedWarehouseId > 0) {
    $singleWhere[] = 's.warehouse_id = ?';
    $singleParams[] = $selectedWarehouseId;
}
$singleWhereSQL = implode(' AND ', $singleWhere);

$summaryWhereSQL = $isMultiWarehouseMode ? $baseWhereSQL : $singleWhereSQL;
$summaryParams = $isMultiWarehouseMode ? $baseParams : $singleParams;

$summary = Database::row(
    "SELECT COUNT(*) AS cnt,
            COALESCE(SUM(s.total), 0) AS revenue,
            COALESCE(AVG(s.total), 0) AS avg_total
     $baseFromSQL
     WHERE $summaryWhereSQL AND s.status = 'completed'",
    $summaryParams
);

$sales = [];
$total = 0;
$pg = ['pages' => 1, 'page' => 1, 'perPage' => 25, 'offset' => 0];
$salesByWarehouse = [];

$salesSelect = "SELECT s.id, s.receipt_no, s.total, s.subtotal, s.discount_amount, s.status, s.created_at,
        s.warehouse_id,
        COALESCE(NULLIF(s.customer_company_snapshot, ''), NULLIF(c.company, ''), NULLIF(s.customer_name_snapshot, ''), c.name) AS customer,
        COALESCE(NULLIF(s.customer_type_snapshot, ''), c.customer_type, 'individual') AS sale_customer_type,
        u.name AS cashier,
        w.name AS warehouse_name,
        si.id AS invoice_id,
        si.invoice_number,
        si.invoice_date";

if ($isMultiWarehouseMode) {
    foreach ($availableWarehouses as $warehouse) {
        $warehouseId = (int)$warehouse['id'];
        $warehouseParams = array_merge($baseParams, [$warehouseId]);
        $salesByWarehouse[$warehouseId] = Database::all(
            "$salesSelect
             $baseFromSQL
             WHERE $baseWhereSQL AND s.warehouse_id = ?
             ORDER BY s.created_at DESC
             LIMIT $boardLimit",
            $warehouseParams
        );
    }
} else {
    $total = (int)Database::value(
        "SELECT COUNT(*)
         $baseFromSQL
         WHERE $singleWhereSQL",
        $singleParams
    );
    $pg = paginate($total, $page);

    $sales = Database::all(
        "$salesSelect
         $baseFromSQL
         WHERE $singleWhereSQL
         ORDER BY s.created_at DESC
         LIMIT {$pg['perPage']} OFFSET {$pg['offset']}",
        $singleParams
    );
}

function sale_invoice_prefill_number(array $sale): string
{
    $receipt = preg_replace('/[^A-Za-z0-9\-\/]/', '', (string)($sale['receipt_no'] ?? ''));
    return $receipt !== '' ? $receipt : ('INV-' . (int)($sale['id'] ?? 0));
}

include __DIR__ . '/../../views/layouts/header.php';
?>

<style>
.sales-context {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
  margin-bottom: 14px;
  color: var(--text-secondary);
  font-size: 13px;
}
.sales-board {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
  gap: 16px;
}
.sales-board-column { min-width: 0; }
.sales-board-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
}
.sales-board-subtitle {
  margin-top: 4px;
  color: var(--text-secondary);
  font-size: 12px;
}
.sales-board-list {
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding: 16px;
}
.sales-board-item {
  border: 1px solid var(--border-soft);
  border-radius: 14px;
  padding: 14px;
  background: rgba(255,255,255,0.02);
}
.sales-board-item-top {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 10px;
}
.sales-board-receipt {
  font-family: var(--font-mono);
  font-size: 12px;
  word-break: break-word;
}
.sales-board-total {
  margin-top: 10px;
  font-size: 18px;
  font-weight: 700;
}
.sales-board-meta {
  display: grid;
  gap: 4px;
  margin-top: 10px;
  color: var(--text-secondary);
  font-size: 12px;
}
.sales-board-actions {
  display: flex;
  justify-content: flex-end;
  gap: 6px;
  margin-top: 12px;
  flex-wrap: wrap;
}
.sales-board-empty {
  padding: 22px 16px;
  text-align: center;
  color: var(--text-secondary);
  border: 1px dashed var(--border-soft);
  border-radius: 14px;
}
@media (max-width: 900px) {
  .sales-board { grid-template-columns: 1fr; }
}
</style>

<?php
$contextWarehouseName = $selectedWarehouse
    ? $selectedWarehouse['name']
    : ((count($availableWarehouses) === 1 && !empty($availableWarehouses[0]['name'])) ? $availableWarehouses[0]['name'] : null);
?>

<div class="page-header">
  <h1 class="page-heading"><?= __('nav_sales') ?></h1>
</div>

<div class="sales-context">
  <?php if ($isMultiWarehouseMode): ?>
    <span class="badge badge-secondary"><?= __('lbl_all') ?> <?= __('wh_title') ?></span>
    <span><?= sprintf(__('sales_multi_hint'), $boardLimit) ?></span>
  <?php elseif ($contextWarehouseName): ?>
    <span class="badge badge-secondary"><?= __('col_warehouse') ?></span>
    <span><?= e($contextWarehouseName) ?></span>
  <?php endif; ?>
</div>

<div class="grid grid-3 mb-3">
  <div class="stat-card">
    <div class="stat-icon stat-icon-amber"><?= feather_icon('file-text', 20) ?></div>
    <div>
      <div class="stat-value"><?= (int)($summary['cnt'] ?? 0) ?></div>
      <div class="stat-label"><?= __('rep_num_receipts') ?></div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon stat-icon-green"><?= feather_icon('trending-up', 20) ?></div>
    <div>
      <div class="stat-value" style="font-size:18px"><?= money((float)($summary['revenue'] ?? 0)) ?></div>
      <div class="stat-label"><?= __('rep_total_revenue') ?></div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon stat-icon-blue"><?= feather_icon('bar-chart', 20) ?></div>
    <div>
      <div class="stat-value" style="font-size:18px"><?= money((float)($summary['avg_total'] ?? 0)) ?></div>
      <div class="stat-label"><?= __('rep_avg_receipt') ?></div>
    </div>
  </div>
</div>

<form method="GET" class="filter-bar mb-2">
  <input type="text" name="search" class="form-control" placeholder="<?= e(__('sales_search_placeholder')) ?>" value="<?= e($search) ?>" style="max-width:260px">
  <input type="date" name="from" class="form-control" value="<?= e($from) ?>" style="max-width:150px">
  <input type="date" name="to" class="form-control" value="<?= e($to) ?>" style="max-width:150px">
  <select name="status" class="form-control" style="max-width:180px">
    <option value=""><?= __('lbl_all') ?></option>
    <option value="completed" <?= $status === 'completed' ? 'selected' : '' ?>><?= __('sales_status_completed') ?></option>
    <option value="voided" <?= $status === 'voided' ? 'selected' : '' ?>><?= __('sales_status_voided') ?></option>
    <option value="refunded" <?= $status === 'refunded' ? 'selected' : '' ?>><?= __('sales_status_refunded') ?></option>
    <option value="partial_refund" <?= $status === 'partial_refund' ? 'selected' : '' ?>><?= __('sales_status_partial_refund') ?></option>
  </select>
  <button type="submit" class="btn btn-secondary"><?= feather_icon('filter', 14) ?></button>
  <a href="<?= url('modules/sales/') ?>" class="btn btn-ghost"><?= __('btn_reset') ?></a>
</form>

<?php if ($isMultiWarehouseMode): ?>
  <div class="sales-board">
    <?php foreach ($availableWarehouses as $warehouse): ?>
      <?php $warehouseId = (int)$warehouse['id']; $warehouseSales = $salesByWarehouse[$warehouseId] ?? []; ?>
      <section class="card sales-board-column">
        <div class="card-header sales-board-header">
          <div>
            <div class="card-title"><?= e($warehouse['name']) ?></div>
            <div class="sales-board-subtitle"><?= sprintf(__('sales_latest_in_warehouse'), $boardLimit) ?></div>
          </div>
          <span class="badge badge-secondary"><?= count($warehouseSales) ?></span>
        </div>
        <div class="sales-board-list">
          <?php if (!$warehouseSales): ?>
            <div class="sales-board-empty"><?= __('sales_no_sales') ?></div>
          <?php else: ?>
            <?php foreach ($warehouseSales as $sale): ?>
              <?php $statusClass = $statusClasses[$sale['status']] ?? 'secondary'; ?>
              <?php $invoiceUrls = !empty($sale['invoice_id']) ? sale_invoice_urls((int)$sale['invoice_id']) : null; ?>
              <article class="sales-board-item">
                <div class="sales-board-item-top">
                  <div>
                    <a class="sales-board-receipt" href="<?= url('modules/sales/view.php?id=' . $sale['id']) ?>"><?= e($sale['receipt_no']) ?></a>
                  </div>
                  <span class="badge badge-<?= $statusClass ?>"><?= e($statusLabels[$sale['status']] ?? $sale['status']) ?></span>
                </div>
                <div class="sales-board-total"><?= money((float)$sale['total']) ?></div>
                <div class="sales-board-meta">
                  <div><?= __('shift_cashier') ?>: <?= e($sale['cashier']) ?></div>
                  <div><?= __('cust_title') ?>: <?= e($sale['customer']) ?></div>
                  <div><?= __('lbl_date') ?>: <?= date_fmt((string)$sale['created_at']) ?></div>
                  <?php if (!empty($sale['invoice_number'])): ?>
                    <div><?= __('doc_delivery_note') ?>: <span class="font-mono"><?= e($sale['invoice_number']) ?></span></div>
                  <?php endif; ?>
                </div>
                <div class="sales-board-actions">
                  <a href="<?= url('modules/pos/receipt.php?id=' . $sale['id']) ?>" onclick="return openReceiptWindow(this.href)" class="btn btn-sm btn-ghost btn-icon" title="<?= e(__('btn_print')) ?>"><?= feather_icon('printer', 14) ?></a>
                  <a href="<?= url('modules/sales/view.php?id=' . $sale['id']) ?>" class="btn btn-sm btn-ghost btn-icon" title="<?= e(__('btn_view')) ?>"><?= feather_icon('eye', 14) ?></a>
                  <?php if ($invoiceUrls): ?>
                    <a href="<?= e($invoiceUrls['view_url']) ?>" class="btn btn-sm btn-ghost btn-icon" title="<?= e(__('sales_open_invoice')) ?>"><?= feather_icon('file-text', 14) ?></a>
                    <a href="<?= e($invoiceUrls['print_url']) ?>" target="_blank" class="btn btn-sm btn-ghost btn-icon" title="<?= e(__('btn_print')) ?> <?= e(__('doc_delivery_note')) ?>"><?= feather_icon('printer', 14) ?></a>
                    <a href="<?= e($invoiceUrls['excel_url']) ?>" class="btn btn-sm btn-ghost btn-icon" title="Excel <?= e(__('doc_delivery_note')) ?>"><?= feather_icon('download', 14) ?></a>
                  <?php elseif ($sale['status'] === 'completed'): ?>
                    <button type="button"
                            class="btn btn-sm btn-ghost btn-icon"
                            title="<?= e(__('sales_create_invoice')) ?>"
                            data-open-sale-invoice-modal
                            data-sale-id="<?= (int)$sale['id'] ?>"
                            data-sale-receipt="<?= e($sale['receipt_no']) ?>"
                            data-sale-customer="<?= e($sale['customer']) ?>"
                            data-sale-date-label="<?= e(date_fmt((string)$sale['created_at'])) ?>"
                            data-invoice-number="<?= e(sale_invoice_prefill_number($sale)) ?>"
                            data-invoice-date="<?= e(substr((string)$sale['created_at'], 0, 10)) ?>"
                    ><?= feather_icon('file-plus', 14) ?></button>
                  <?php endif; ?>
                  <?php if ($sale['status'] === 'completed'): ?>
                    <form method="POST" action="<?= url('modules/sales/void.php') ?>" style="display:inline">
                      <?= csrf_field() ?>
                      <input type="hidden" name="id" value="<?= (int)$sale['id'] ?>">
                      <button type="submit" class="btn btn-sm btn-ghost btn-icon" style="color:var(--danger)" data-confirm="<?= e(__('confirm_void')) ?>" title="<?= e(__('sales_status_voided')) ?>"><?= feather_icon('x-circle', 14) ?></button>
                    </form>
                  <?php endif; ?>
                </div>
              </article>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </section>
    <?php endforeach; ?>
  </div>
<?php else: ?>
  <div class="card">
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th><?= __('pos_receipt_no') ?></th>
            <th><?= __('shift_cashier') ?></th>
            <th><?= __('cust_title') ?></th>
            <th><?= __('col_warehouse') ?></th>
            <th><?= __('doc_delivery_note') ?></th>
            <th class="col-num"><?= __('lbl_subtotal') ?></th>
            <th class="col-num"><?= __('lbl_discount') ?></th>
            <th class="col-num"><?= __('lbl_total') ?></th>
            <th><?= __('lbl_status') ?></th>
            <th><?= __('lbl_date') ?></th>
            <th class="col-actions"><?= __('lbl_actions') ?></th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$sales): ?>
            <tr><td colspan="11" class="text-center text-muted" style="padding:40px"><?= __('no_results') ?></td></tr>
          <?php else: ?>
            <?php foreach ($sales as $sale): ?>
              <?php $statusClass = $statusClasses[$sale['status']] ?? 'secondary'; ?>
              <?php $invoiceUrls = !empty($sale['invoice_id']) ? sale_invoice_urls((int)$sale['invoice_id']) : null; ?>
              <tr>
                <td class="font-mono" style="font-size:12px"><a href="<?= url('modules/sales/view.php?id=' . $sale['id']) ?>"><?= e($sale['receipt_no']) ?></a></td>
                <td><?= e($sale['cashier']) ?></td>
                <td><?= e($sale['customer']) ?></td>
                <td><?= e($sale['warehouse_name'] ?: '—') ?></td>
                <td>
                  <?php if (!empty($sale['invoice_id'])): ?>
                    <a href="<?= e($invoiceUrls['view_url']) ?>" class="font-mono"><?= e($sale['invoice_number']) ?></a>
                  <?php else: ?>
                    <span class="text-muted">—</span>
                  <?php endif; ?>
                </td>
                <td class="col-num"><?= money((float)$sale['subtotal']) ?></td>
                <td class="col-num text-danger"><?= (float)$sale['discount_amount'] > 0 ? '−' . money((float)$sale['discount_amount']) : '—' ?></td>
                <td class="col-num fw-600"><?= money((float)$sale['total']) ?></td>
                <td><span class="badge badge-<?= $statusClass ?>"><?= e($statusLabels[$sale['status']] ?? $sale['status']) ?></span></td>
                <td class="text-muted" style="font-size:12px"><?= date_fmt((string)$sale['created_at']) ?></td>
                <td class="col-actions">
                  <a href="<?= url('modules/pos/receipt.php?id=' . $sale['id']) ?>" onclick="return openReceiptWindow(this.href)" class="btn btn-sm btn-ghost btn-icon" title="<?= e(__('btn_print')) ?>"><?= feather_icon('printer', 14) ?></a>
                  <a href="<?= url('modules/sales/view.php?id=' . $sale['id']) ?>" class="btn btn-sm btn-ghost btn-icon" title="<?= e(__('btn_view')) ?>"><?= feather_icon('eye', 14) ?></a>
                  <?php if ($invoiceUrls): ?>
                    <a href="<?= e($invoiceUrls['view_url']) ?>" class="btn btn-sm btn-ghost btn-icon" title="<?= e(__('sales_open_invoice')) ?>"><?= feather_icon('file-text', 14) ?></a>
                    <a href="<?= e($invoiceUrls['print_url']) ?>" target="_blank" class="btn btn-sm btn-ghost btn-icon" title="<?= e(__('btn_print')) ?> <?= e(__('doc_delivery_note')) ?>"><?= feather_icon('printer', 14) ?></a>
                    <a href="<?= e($invoiceUrls['excel_url']) ?>" class="btn btn-sm btn-ghost btn-icon" title="Excel <?= e(__('doc_delivery_note')) ?>"><?= feather_icon('download', 14) ?></a>
                  <?php elseif ($sale['status'] === 'completed'): ?>
                    <button type="button"
                            class="btn btn-sm btn-ghost btn-icon"
                            title="<?= e(__('sales_create_invoice')) ?>"
                            data-open-sale-invoice-modal
                            data-sale-id="<?= (int)$sale['id'] ?>"
                            data-sale-receipt="<?= e($sale['receipt_no']) ?>"
                            data-sale-customer="<?= e($sale['customer']) ?>"
                            data-sale-date-label="<?= e(date_fmt((string)$sale['created_at'])) ?>"
                            data-invoice-number="<?= e(sale_invoice_prefill_number($sale)) ?>"
                            data-invoice-date="<?= e(substr((string)$sale['created_at'], 0, 10)) ?>"
                    ><?= feather_icon('file-plus', 14) ?></button>
                  <?php endif; ?>
                  <?php if ($sale['status'] === 'completed'): ?>
                    <form method="POST" action="<?= url('modules/sales/void.php') ?>" style="display:inline">
                      <?= csrf_field() ?>
                      <input type="hidden" name="id" value="<?= (int)$sale['id'] ?>">
                      <button type="submit" class="btn btn-sm btn-ghost btn-icon" style="color:var(--danger)" data-confirm="<?= e(__('confirm_void')) ?>" title="<?= e(__('sales_status_voided')) ?>"><?= feather_icon('x-circle', 14) ?></button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <?php if ($pg['pages'] > 1): ?>
      <div class="card-footer flex-between">
        <span class="text-secondary fs-sm"><?= __('showing') ?> <?= $pg['offset'] + 1 ?>–<?= min($pg['offset'] + $pg['perPage'], $total) ?> <?= __('of') ?> <?= $total ?></span>
        <div class="pagination">
          <?php for ($i = 1; $i <= $pg['pages']; $i++): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>" class="page-link <?= $i == $pg['page'] ? 'active' : '' ?>"><?= $i ?></a>
          <?php endfor; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php include __DIR__ . '/../../modules/sale_invoices/_create_modal.php'; ?>
<script>
window.openReceiptWindow = window.openReceiptWindow || function(url) {
  const width = 420;
  const height = 760;
  const left = Math.max(0, Math.round((window.screen.width - width) / 2));
  const top = Math.max(0, Math.round((window.screen.height - height) / 2));
  const features = 'width=' + width + ',height=' + height + ',left=' + left + ',top=' + top + ',resizable=yes,scrollbars=yes';
  const popup = window.open(url, 'buildmart_receipt_history', features);
  if (popup) { popup.focus(); }
  return false;
};
</script>
<?php include __DIR__ . '/../../views/layouts/footer.php'; ?>
<script src="https://unpkg.com/feather-icons/dist/feather.min.js"></script>
<script>feather.replace();</script>
