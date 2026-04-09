<?php
/**
 * Goods Receipt � Document List
 * modules/receipts/index.php
 *
 * Shows all incoming goods receipt documents with search/filter.
 * Users with 'receipts' permission may access this page.
 */
require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../views/partials/icons.php';
Auth::requireLogin();
Auth::requirePerm('receipts');
$canCreateReceipts = Auth::can('receipts.create');
$canEditReceipts = Auth::can('receipts.edit');
$canPostReceipts = Auth::can('receipts.post');
$canCancelReceipts = Auth::can('receipts.cancel');
$canExportReceipts = Auth::can('receipts.export');

$pageTitle   = __('gr_title');
$breadcrumbs = [[$pageTitle, null]];

// -- Filters ----------------------------------------------------
$fSearch    = sanitize($_GET['q']          ?? '');
$fStatus    = sanitize($_GET['status']     ?? '');
$fSupplier  = (int)($_GET['supplier_id']   ?? 0);
$fWarehouse = (int)($_GET['warehouse_id']  ?? 0);
$fDateFrom  = sanitize($_GET['date_from']  ?? '');
$fDateTo    = sanitize($_GET['date_to']    ?? '');
$page       = max(1, (int)($_GET['page']   ?? 1));
$accessibleWarehouses = user_warehouses();
$accessibleWarehouseIds = array_map('intval', array_column($accessibleWarehouses, 'id'));
if (!$accessibleWarehouseIds) {
    $accessibleWarehouseIds = [0];
}
if ($fWarehouse > 0 && !in_array($fWarehouse, $accessibleWarehouseIds, true)) {
    $fWarehouse = 0;
}

// -- Build query ------------------------------------------------
$where  = ['1=1'];
$params = [];
$where[] = 'gr.warehouse_id IN (' . implode(',', array_fill(0, count($accessibleWarehouseIds), '?')) . ')';
$params = array_merge($params, $accessibleWarehouseIds);

if ($fSearch !== '') {
    $where[]  = '(gr.doc_no LIKE ? OR gr.supplier_doc_no LIKE ? OR s.name LIKE ?)';
    $like     = '%' . $fSearch . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}
if ($fStatus !== '') {
    $where[]  = 'gr.status = ?';
    $params[] = $fStatus;
}
if ($fSupplier > 0) {
    $where[]  = 'gr.supplier_id = ?';
    $params[] = $fSupplier;
}
if ($fWarehouse > 0) {
    $where[]  = 'gr.warehouse_id = ?';
    $params[] = $fWarehouse;
}
if ($fDateFrom !== '') {
    $where[]  = 'gr.doc_date >= ?';
    $params[] = $fDateFrom;
}
if ($fDateTo !== '') {
    $where[]  = 'gr.doc_date <= ?';
    $params[] = $fDateTo;
}

$whereSQL = implode(' AND ', $where);

$total = (int) Database::value(
    "SELECT COUNT(*) FROM goods_receipts gr
     LEFT JOIN suppliers  s ON s.id = gr.supplier_id
     WHERE $whereSQL",
    $params
);

$pag  = paginate($total, $page);
$rows = Database::all(
    "SELECT gr.*,
            s.name  AS supplier_name,
            w.name  AS warehouse_name,
            u.name  AS created_by_name
     FROM   goods_receipts gr
     LEFT JOIN suppliers  s ON s.id = gr.supplier_id
     LEFT JOIN warehouses w ON w.id = gr.warehouse_id
     LEFT JOIN users      u ON u.id = gr.created_by
     WHERE $whereSQL
     ORDER BY gr.doc_date DESC, gr.id DESC
     LIMIT ? OFFSET ?",
    array_merge($params, [$pag['perPage'], $pag['offset']])
);

// -- Select data for filters -------------------------------------
$suppliers  = Database::all("SELECT id, name FROM suppliers WHERE is_active=1 ORDER BY name");
$warehouses = $accessibleWarehouses;

$statusOptions = [
    ''           => __('lbl_all'),
    'draft'      => __('gr_status_draft'),
    'pending_acceptance' => __('gr_status_pending'),
    'accepted'   => __('gr_status_accepted'),
    'cancelled'  => __('gr_status_cancelled'),
];

include __DIR__ . '/../../views/layouts/header.php';
?>

<div class="page-header">
  <div>
    <h1 class="page-heading"><?= __('gr_title') ?></h1>
  </div>
  <div class="page-actions">
    <?php if ($canCreateReceipts): ?>
    <a href="<?= url('modules/receipts/edit.php') ?>" class="btn btn-primary">
      <?= feather_icon('plus', 15) ?> <?= __('gr_new') ?>
    </a>
    <?php endif; ?>
  </div>
</div>

<!-- Filter bar -->
<form method="GET" class="card mb-3">
  <div class="card-body filter-bar filter-bar-card mobile-form-stack">
    <div class="form-group mb-0">
      <label class="form-label"><?= __('btn_search') ?></label>
      <input type="text" name="q" value="<?= e($fSearch) ?>" class="form-control filter-field-xxl" placeholder="<?= __('gr_search_ph') ?>">
    </div>
    <div class="form-group mb-0">
      <label class="form-label"><?= __('lbl_status') ?></label>
      <select name="status" class="form-control filter-field-sm">
        <?php foreach ($statusOptions as $val => $lbl): ?>
          <option value="<?= e($val) ?>" <?= $fStatus === $val ? 'selected' : '' ?>><?= $lbl ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group mb-0">
      <label class="form-label"><?= __('gr_supplier') ?></label>
      <select name="supplier_id" class="form-control filter-field-md">
        <option value="0"><?= __('lbl_all') ?></option>
        <?php foreach ($suppliers as $sup): ?>
          <option value="<?= $sup['id'] ?>" <?= $fSupplier === (int)$sup['id'] ? 'selected' : '' ?>><?= e($sup['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group mb-0">
      <label class="form-label"><?= __('gr_warehouse') ?></label>
      <select name="warehouse_id" class="form-control filter-field-md">
        <option value="0"><?= __('lbl_all') ?></option>
        <?php foreach ($warehouses as $wh): ?>
          <option value="<?= $wh['id'] ?>" <?= $fWarehouse === (int)$wh['id'] ? 'selected' : '' ?>><?= e($wh['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group mb-0">
      <label class="form-label"><?= __('rep_from') ?></label>
      <input type="date" name="date_from" value="<?= e($fDateFrom) ?>" class="form-control">
    </div>
    <div class="form-group mb-0">
      <label class="form-label"><?= __('rep_to') ?></label>
      <input type="date" name="date_to" value="<?= e($fDateTo) ?>" class="form-control">
    </div>
    <div class="filter-actions">
      <button type="submit" class="btn btn-primary"><?= feather_icon('search', 14) ?> <?= __('btn_filter') ?></button>
      <a href="<?= url('modules/receipts/') ?>" class="btn btn-ghost"><?= __('btn_reset') ?></a>
    </div>
  </div>
</form>

<!-- Results table -->
<div class="card">
  <div class="table-wrap desktop-only mobile-table-scroll">
    <table class="table">
      <thead>
        <tr>
          <th><?= __('gr_doc_no') ?></th>
          <th><?= __('lbl_date') ?></th>
          <th><?= __('gr_supplier') ?></th>
          <th><?= __('gr_warehouse') ?></th>
          <th class="col-num"><?= __('lbl_total') ?></th>
          <th><?= __('lbl_status') ?></th>
          <th><?= __('lbl_actions') ?></th>
        </tr>
      </thead>
      <tbody>
        <?php if ($rows): ?>
          <?php foreach ($rows as $r): ?>
          <tr>
            <td>
              <a href="<?= url('modules/receipts/view.php?id='.$r['id']) ?>" class="font-mono fw-600">
                <?= e($r['doc_no']) ?>
              </a>
              <?php if ($r['supplier_doc_no']): ?>
                <div class="text-muted" style="font-size:11px"><?= e($r['supplier_doc_no']) ?></div>
              <?php endif; ?>
            </td>
            <td><?= date_fmt($r['doc_date'], 'd.m.Y') ?></td>
            <td><?= e($r['supplier_name'] ?? '�') ?></td>
            <td><?= e($r['warehouse_name'] ?? '�') ?></td>
            <td class="col-num fw-600"><?= money($r['total']) ?></td>
            <td><?= gr_status_badge($r['status']) ?></td>
            <td class="col-actions">
              <!-- View -->
              <a href="<?= url('modules/receipts/view.php?id='.$r['id']) ?>"
                 class="btn btn-sm btn-ghost btn-icon" title="<?= __('btn_view') ?>"><?= feather_icon('eye',14) ?></a>
              <!-- Edit (only draft) -->
              <?php if ($r['status'] === 'draft' && $canEditReceipts): ?>
              <a href="<?= url('modules/receipts/edit.php?id='.$r['id']) ?>"
                 class="btn btn-sm btn-ghost btn-icon" title="<?= __('btn_edit') ?>"><?= feather_icon('edit-2',14) ?></a>
              <?php endif; ?>
              <!-- Print -->
              <a href="<?= url('modules/receipts/print.php?id='.$r['id']) ?>" target="_blank"
                 class="btn btn-sm btn-ghost btn-icon" title="<?= __('btn_print') ?>"><?= feather_icon('printer',14) ?></a>
              <!-- Export Excel -->
              <?php if ($canExportReceipts): ?>
              <a href="<?= url('modules/receipts/export_excel.php?id='.$r['id']) ?>"
                 class="btn btn-sm btn-ghost btn-icon" title="<?= __('gr_export_excel') ?>"><?= feather_icon('file-text',14) ?></a>
              <?php endif; ?>
              <!-- Duplicate -->
              <?php if ($canCreateReceipts): ?>
              <a href="<?= url('modules/receipts/duplicate.php?id='.$r['id']) ?>"
                 class="btn btn-sm btn-ghost btn-icon" title="<?= __('gr_duplicate') ?>"><?= feather_icon('copy',14) ?></a>
              <?php endif; ?>
              <!-- Post (only draft) -->
              <?php if ($r['status'] === 'draft' && $canPostReceipts): ?>
              <form method="POST" action="<?= url('modules/receipts/post.php') ?>" class="inline-action-form">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button type="submit"
                   class="btn btn-sm btn-ghost btn-icon" style="color:var(--success)"
                   title="<?= __('gr_post') ?>"
                   data-confirm="<?= __('gr_confirm_post') ?>"><?= feather_icon('check-circle',14) ?></button>
              </form>
              <?php endif; ?>
              <!-- Cancel (draft or posted) -->
              <?php if ($canCancelReceipts && in_array($r['status'], ['draft','pending_acceptance','accepted'], true)): ?>
              <form method="POST" action="<?= url('modules/receipts/cancel.php') ?>" class="inline-action-form">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button type="submit"
                   class="btn btn-sm btn-ghost btn-icon" style="color:var(--danger)"
                   title="<?= __('gr_cancel') ?>"
                   data-confirm="<?= __('gr_confirm_cancel') ?>"><?= feather_icon('x-circle',14) ?></button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        <?php else: ?>
          <tr>
            <td colspan="7" class="text-center text-muted table-empty-cell"><?= __('no_results') ?></td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <div class="mobile-card-list mobile-only">
    <?php if (!$rows): ?>
      <div class="empty-state">
        <div class="empty-state-icon"><?= feather_icon('truck', 36) ?></div>
        <div class="empty-state-title"><?= __('no_results') ?></div>
      </div>
    <?php else: ?>
      <?php foreach ($rows as $r): ?>
        <div class="mobile-record-card">
          <div class="mobile-record-header">
            <div class="mobile-record-main">
              <div class="mobile-record-title"><?= e($r['doc_no']) ?></div>
              <div class="mobile-record-subtitle"><?= e($r['supplier_name'] ?? '—') ?></div>
              <?php if ($r['supplier_doc_no']): ?>
                <div class="mobile-record-subtitle"><?= e($r['supplier_doc_no']) ?></div>
              <?php endif; ?>
            </div>
            <div class="mobile-badge-row">
              <?= gr_status_badge($r['status']) ?>
            </div>
          </div>

          <div class="mobile-meta-grid">
            <div class="mobile-meta-row">
              <span class="mobile-meta-row-label"><?= __('lbl_date') ?></span>
              <span class="mobile-meta-row-value"><?= date_fmt($r['doc_date'], 'd.m.Y') ?></span>
            </div>
            <div class="mobile-meta-row">
              <span class="mobile-meta-row-label"><?= __('gr_warehouse') ?></span>
              <span class="mobile-meta-row-value"><?= e($r['warehouse_name'] ?? '—') ?></span>
            </div>
            <div class="mobile-meta-row">
              <span class="mobile-meta-row-label"><?= __('lbl_total') ?></span>
              <span class="mobile-meta-row-value"><?= money($r['total']) ?></span>
            </div>
          </div>

          <div class="mobile-actions">
            <a href="<?= url('modules/receipts/view.php?id='.$r['id']) ?>" class="btn btn-secondary">
              <?= feather_icon('eye',14) ?> <?= __('btn_view') ?>
            </a>
            <?php if ($r['status'] === 'draft' && $canEditReceipts): ?>
              <a href="<?= url('modules/receipts/edit.php?id='.$r['id']) ?>" class="btn btn-ghost">
                <?= feather_icon('edit-2',14) ?> <?= __('btn_edit') ?>
              </a>
            <?php endif; ?>
            <a href="<?= url('modules/receipts/print.php?id='.$r['id']) ?>" target="_blank" class="btn btn-ghost">
              <?= feather_icon('printer',14) ?> <?= __('btn_print') ?>
            </a>
            <?php if ($canExportReceipts): ?>
              <a href="<?= url('modules/receipts/export_excel.php?id='.$r['id']) ?>" class="btn btn-ghost">
                <?= feather_icon('file-text',14) ?> Excel
              </a>
            <?php endif; ?>
            <?php if ($canCreateReceipts): ?>
              <a href="<?= url('modules/receipts/duplicate.php?id='.$r['id']) ?>" class="btn btn-ghost">
                <?= feather_icon('copy',14) ?> <?= __('gr_duplicate') ?>
              </a>
            <?php endif; ?>
            <?php if ($r['status'] === 'draft' && $canPostReceipts): ?>
              <form method="POST" action="<?= url('modules/receipts/post.php') ?>" class="inline-action-form">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button type="submit" class="btn btn-primary" data-confirm="<?= __('gr_confirm_post') ?>">
                  <?= feather_icon('check-circle',14) ?> <?= __('gr_post') ?>
                </button>
              </form>
            <?php endif; ?>
            <?php if ($canCancelReceipts && in_array($r['status'], ['draft','pending_acceptance','accepted'], true)): ?>
              <form method="POST" action="<?= url('modules/receipts/cancel.php') ?>" class="inline-action-form">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button type="submit" class="btn btn-danger" data-confirm="<?= __('gr_confirm_cancel') ?>">
                  <?= feather_icon('x-circle',14) ?> <?= __('gr_cancel') ?>
                </button>
              </form>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <?php if ($pag['pages'] > 1): ?>
  <div class="card-footer flex-between">
    <span class="text-muted fs-sm">
      <?= __('showing') ?> <?= $pag['offset']+1 ?>�<?= min($pag['offset']+$pag['perPage'], $total) ?>
      <?= __('of') ?> <?= $total ?> <?= __('results') ?>
    </span>
    <div class="pagination">
      <?php for ($p = 1; $p <= $pag['pages']; $p++): ?>
        <?php
          $q = array_merge($_GET, ['page' => $p]);
          $href = url('modules/receipts/') . '?' . http_build_query($q);
        ?>
        <a href="<?= $href ?>" class="page-link <?= $p === $pag['page'] ? 'active' : '' ?>"><?= $p ?></a>
      <?php endfor; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../../views/layouts/footer.php'; ?>
<script src="https://unpkg.com/feather-icons/dist/feather.min.js"></script>
<script>
feather.replace();
// Confirm dialogs for destructive actions
document.querySelectorAll('[data-confirm]').forEach(function(el){
  el.addEventListener('click', function(e){
    if (!confirm(el.dataset.confirm)) e.preventDefault();
  });
});
</script>
