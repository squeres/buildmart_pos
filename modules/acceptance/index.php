<?php
/**
 * Приёмка товара — Список документов
 * modules/acceptance/index.php
 *
 * Показывает поступления со статусом pending_acceptance (и accepted для истории).
 */
require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../views/partials/icons.php';
Auth::requireLogin();
Auth::requirePerm('acceptance');
$canProcessAcceptance = Auth::can('acceptance.process');
$canAcceptAcceptance = Auth::can('acceptance.accept');

$pageTitle   = __('acc_title');
$breadcrumbs = [[$pageTitle, null]];

// ── Фильтры ────────────────────────────────────────────────────
$fSearch   = sanitize($_GET['q']         ?? '');
$fStatus   = sanitize($_GET['status']    ?? 'pending_acceptance');
$fSupplier = (int)($_GET['supplier_id']  ?? 0);
$fDateFrom = sanitize($_GET['date_from'] ?? '');
$fDateTo   = sanitize($_GET['date_to']   ?? '');
$page      = max(1, (int)($_GET['page']  ?? 1));
$accessibleWarehouseIds = array_map('intval', user_warehouse_ids());
if (!$accessibleWarehouseIds) {
    $accessibleWarehouseIds = [0];
}

// ── Запрос ─────────────────────────────────────────────────────
$where  = ["gr.status IN ('pending_acceptance','accepted','cancelled')"];
$params = [];
$where[] = 'gr.warehouse_id IN (' . implode(',', array_fill(0, count($accessibleWarehouseIds), '?')) . ')';
$params = array_merge($params, $accessibleWarehouseIds);

if ($fSearch !== '') {
    $like     = '%' . $fSearch . '%';
    $where[]  = '(gr.doc_no LIKE ? OR s.name LIKE ? OR gr.supplier_doc_no LIKE ?)';
    $params[] = $like; $params[] = $like; $params[] = $like;
}
if ($fStatus !== '') {
    $where[]  = 'gr.status = ?';
    $params[] = $fStatus;
}
if ($fSupplier > 0) {
    $where[]  = 'gr.supplier_id = ?';
    $params[] = $fSupplier;
}
if ($fDateFrom !== '') { $where[] = 'gr.doc_date >= ?'; $params[] = $fDateFrom; }
if ($fDateTo   !== '') { $where[] = 'gr.doc_date <= ?'; $params[] = $fDateTo;   }

$whereSQL = implode(' AND ', $where);

$total = (int) Database::value(
    "SELECT COUNT(*) FROM goods_receipts gr
     LEFT JOIN suppliers s ON s.id = gr.supplier_id
     WHERE $whereSQL",
    $params
);

$pag  = paginate($total, $page);
$docs = Database::all(
    "SELECT gr.*,
            s.name AS supplier_name,
            w.name AS warehouse_name,
            (SELECT COUNT(*) FROM goods_receipt_items i WHERE i.receipt_id = gr.id) AS item_count,
            (SELECT COALESCE(SUM(i.qty),0) FROM goods_receipt_items i WHERE i.receipt_id = gr.id) AS total_qty,
            au.name AS accepted_by_name
     FROM   goods_receipts gr
     LEFT JOIN suppliers  s  ON s.id  = gr.supplier_id
     LEFT JOIN warehouses w  ON w.id  = gr.warehouse_id
     LEFT JOIN users      au ON au.id = gr.accepted_by_user
     WHERE $whereSQL
     ORDER BY gr.doc_date DESC, gr.id DESC
     LIMIT ? OFFSET ?",
    array_merge($params, [$pag['perPage'], $pag['offset']])
);

$suppliers  = Database::all("SELECT id, name FROM suppliers WHERE is_active=1 ORDER BY name");
$pendingCnt = gr_pending_count();

include __DIR__ . '/../../views/layouts/header.php';
?>

<div class="page-header">
  <div>
    <h1 class="page-heading">
      <?= feather_icon('clipboard', 20) ?> <?= __('acc_title') ?>
      <?php if ($pendingCnt > 0): ?>
        <span class="badge badge-warning" style="font-size:13px;margin-left:6px"><?= $pendingCnt ?></span>
      <?php endif; ?>
    </h1>
  </div>
</div>

<!-- Фильтры -->
<div class="card mb-3">
  <div class="card-body">
    <form method="GET" class="filter-bar filter-bar-card mobile-form-stack">
      <div class="form-group mb-0">
        <label class="form-label"><?= __('lbl_search') ?></label>
        <input type="text" name="q" class="form-control filter-field-xxl" value="<?= e($fSearch) ?>"
               placeholder="<?= __('gr_search_ph') ?>">
      </div>
      <div class="form-group mb-0">
        <label class="form-label"><?= __('lbl_status') ?></label>
        <select name="status" class="form-control filter-field-md">
          <option value=""><?= __('lbl_all') ?></option>
          <option value="pending_acceptance" <?= $fStatus==='pending_acceptance'?'selected':'' ?>><?= __('gr_status_pending') ?></option>
          <option value="accepted"           <?= $fStatus==='accepted'?'selected':'' ?>><?= __('gr_status_accepted') ?></option>
          <option value="cancelled"          <?= $fStatus==='cancelled'?'selected':'' ?>><?= __('gr_status_cancelled') ?></option>
        </select>
      </div>
      <div class="form-group mb-0">
        <label class="form-label"><?= __('gr_supplier') ?></label>
        <select name="supplier_id" class="form-control filter-field-md">
          <option value=""><?= __('lbl_all') ?></option>
          <?php foreach ($suppliers as $sup): ?>
            <option value="<?= $sup['id'] ?>" <?= $fSupplier==$sup['id']?'selected':'' ?>><?= e($sup['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group mb-0">
        <label class="form-label"><?= __('lbl_date_from') ?></label>
        <input type="date" name="date_from" class="form-control filter-field-sm" value="<?= e($fDateFrom) ?>">
      </div>
      <div class="form-group mb-0">
        <label class="form-label"><?= __('lbl_date_to') ?></label>
        <input type="date" name="date_to" class="form-control filter-field-sm" value="<?= e($fDateTo) ?>">
      </div>
      <div class="filter-actions">
        <button type="submit" class="btn btn-primary"><?= feather_icon('search',14) ?> <?= __('btn_filter') ?></button>
        <a href="<?= url('modules/acceptance/') ?>" class="btn btn-ghost"><?= __('btn_reset') ?></a>
      </div>
    </form>
  </div>
</div>

<!-- Таблица -->
<div class="card">
  <div class="table-wrap desktop-only mobile-table-scroll">
    <table class="table">
      <thead>
        <tr>
          <th><?= __('gr_doc_no') ?></th>
          <th><?= __('lbl_date') ?></th>
          <th><?= __('gr_supplier') ?></th>
          <th class="col-num"><?= __('acc_item_count') ?></th>
          <th class="col-num"><?= __('acc_total_qty') ?></th>
          <th class="col-num"><?= __('lbl_total') ?></th>
          <th><?= __('lbl_status') ?></th>
          <th style="width:160px"></th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($docs)): ?>
          <tr><td colspan="8" class="text-center text-muted table-empty-cell">
            <?= $fStatus === 'pending_acceptance' ? __('acc_no_pending') : __('lbl_no_records') ?>
          </td></tr>
        <?php endif; ?>
        <?php foreach ($docs as $doc): ?>
        <tr>
          <td class="font-mono fw-600">
            <a href="<?= url('modules/acceptance/view.php?id='.$doc['id']) ?>" style="color:inherit">
              <?= e($doc['doc_no']) ?>
            </a>
          </td>
          <td><?= date_fmt($doc['doc_date'], 'd.m.Y') ?></td>
          <td><?= e($doc['supplier_name'] ?? '—') ?></td>
          <td class="col-num"><?= $doc['item_count'] ?></td>
          <td class="col-num"><?= fmtQty((float)$doc['total_qty']) ?></td>
          <td class="col-num fw-600"><?= money($doc['total']) ?></td>
          <td><?= gr_status_badge($doc['status']) ?></td>
          <td style="white-space:nowrap">
            <a href="<?= url('modules/acceptance/view.php?id='.$doc['id']) ?>"
               class="btn btn-sm btn-ghost btn-icon" title="<?= __('btn_open') ?>">
              <?= feather_icon('eye', 14) ?>
            </a>
            <?php if ($doc['status'] === 'pending_acceptance' && ($canProcessAcceptance || $canAcceptAcceptance)): ?>
            <a href="<?= url('modules/acceptance/view.php?id='.$doc['id']) ?>"
               class="btn btn-sm btn-primary" style="padding:4px 10px;font-size:12px"
               title="<?= __('acc_accept_btn') ?>">
              <?= feather_icon('check', 14) ?> <?= __('acc_accept_btn') ?>
            </a>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="mobile-card-list mobile-only">
    <?php if (empty($docs)): ?>
      <div class="empty-state">
        <div class="empty-state-icon"><?= feather_icon('clipboard', 36) ?></div>
        <div class="empty-state-title"><?= $fStatus === 'pending_acceptance' ? __('acc_no_pending') : __('lbl_no_records') ?></div>
      </div>
    <?php else: ?>
      <?php foreach ($docs as $doc): ?>
        <div class="mobile-record-card">
          <div class="mobile-record-header">
            <div class="mobile-record-main">
              <div class="mobile-record-title"><?= e($doc['doc_no']) ?></div>
              <div class="mobile-record-subtitle"><?= e($doc['supplier_name'] ?? '—') ?></div>
            </div>
            <div class="mobile-badge-row">
              <?= gr_status_badge($doc['status']) ?>
            </div>
          </div>

          <div class="mobile-meta-grid">
            <div class="mobile-meta-row">
              <span class="mobile-meta-row-label"><?= __('lbl_date') ?></span>
              <span class="mobile-meta-row-value"><?= date_fmt($doc['doc_date'], 'd.m.Y') ?></span>
            </div>
            <div class="mobile-meta-row">
              <span class="mobile-meta-row-label"><?= __('acc_item_count') ?></span>
              <span class="mobile-meta-row-value"><?= (int)$doc['item_count'] ?></span>
            </div>
            <div class="mobile-meta-row">
              <span class="mobile-meta-row-label"><?= __('acc_total_qty') ?></span>
              <span class="mobile-meta-row-value"><?= fmtQty((float)$doc['total_qty']) ?></span>
            </div>
            <div class="mobile-meta-row">
              <span class="mobile-meta-row-label"><?= __('lbl_total') ?></span>
              <span class="mobile-meta-row-value"><?= money($doc['total']) ?></span>
            </div>
          </div>

          <div class="mobile-actions">
            <a href="<?= url('modules/acceptance/view.php?id='.$doc['id']) ?>" class="btn btn-secondary">
              <?= feather_icon('eye', 14) ?> <?= __('btn_open') ?>
            </a>
            <?php if ($doc['status'] === 'pending_acceptance' && ($canProcessAcceptance || $canAcceptAcceptance)): ?>
              <a href="<?= url('modules/acceptance/view.php?id='.$doc['id']) ?>" class="btn btn-primary">
                <?= feather_icon('check', 14) ?> <?= __('acc_accept_btn') ?>
              </a>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <!-- Пагинация -->
  <?php if ($pag['pages'] > 1): ?>
  <div class="card-footer flex-between">
    <div class="pagination">
      <?php for ($p = 1; $p <= $pag['pages']; $p++): ?>
        <?php $q = http_build_query(array_merge($_GET, ['page'=>$p])); ?>
        <a href="?<?= $q ?>" class="page-link <?= $p == $pag['page'] ? 'active' : '' ?>"><?= $p ?></a>
      <?php endfor; ?>
    </div>
    <span class="text-muted fs-sm"><?= __('lbl_total') ?>: <?= $total ?></span>
  </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../../views/layouts/footer.php'; ?>
<script src="https://unpkg.com/feather-icons/dist/feather.min.js"></script>
<script>feather.replace();</script>
