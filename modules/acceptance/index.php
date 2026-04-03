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
    <form method="GET" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end">
      <div class="form-group mb-0" style="flex:1;min-width:180px">
        <label class="form-label"><?= __('lbl_search') ?></label>
        <input type="text" name="q" class="form-control" value="<?= e($fSearch) ?>"
               placeholder="<?= __('gr_search_ph') ?>">
      </div>
      <div class="form-group mb-0" style="min-width:160px">
        <label class="form-label"><?= __('lbl_status') ?></label>
        <select name="status" class="form-control">
          <option value=""><?= __('lbl_all') ?></option>
          <option value="pending_acceptance" <?= $fStatus==='pending_acceptance'?'selected':'' ?>><?= __('gr_status_pending') ?></option>
          <option value="accepted"           <?= $fStatus==='accepted'?'selected':'' ?>><?= __('gr_status_accepted') ?></option>
          <option value="cancelled"          <?= $fStatus==='cancelled'?'selected':'' ?>><?= __('gr_status_cancelled') ?></option>
        </select>
      </div>
      <div class="form-group mb-0" style="min-width:160px">
        <label class="form-label"><?= __('gr_supplier') ?></label>
        <select name="supplier_id" class="form-control">
          <option value=""><?= __('lbl_all') ?></option>
          <?php foreach ($suppliers as $sup): ?>
            <option value="<?= $sup['id'] ?>" <?= $fSupplier==$sup['id']?'selected':'' ?>><?= e($sup['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group mb-0" style="min-width:120px">
        <label class="form-label"><?= __('lbl_date_from') ?></label>
        <input type="date" name="date_from" class="form-control" value="<?= e($fDateFrom) ?>">
      </div>
      <div class="form-group mb-0" style="min-width:120px">
        <label class="form-label"><?= __('lbl_date_to') ?></label>
        <input type="date" name="date_to" class="form-control" value="<?= e($fDateTo) ?>">
      </div>
      <div class="form-group mb-0">
        <button type="submit" class="btn btn-primary"><?= feather_icon('search',14) ?> <?= __('btn_filter') ?></button>
        <a href="<?= url('modules/acceptance/') ?>" class="btn btn-ghost"><?= __('btn_reset') ?></a>
      </div>
    </form>
  </div>
</div>

<!-- Таблица -->
<div class="card">
  <div class="table-wrap">
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
          <tr><td colspan="8" class="text-center text-muted" style="padding:30px">
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

  <!-- Пагинация -->
  <?php if ($pag['pages'] > 1): ?>
  <div class="card-body" style="border-top:1px solid var(--border-dim)">
    <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
      <?php for ($p = 1; $p <= $pag['pages']; $p++): ?>
        <?php $q = http_build_query(array_merge($_GET, ['page'=>$p])); ?>
        <a href="?<?= $q ?>" class="btn btn-sm <?= $p == $pag['page'] ? 'btn-primary' : 'btn-ghost' ?>"><?= $p ?></a>
      <?php endfor; ?>
      <span class="text-muted" style="font-size:12px;margin-left:8px">
        <?= __('lbl_total') ?>: <?= $total ?>
      </span>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../../views/layouts/footer.php'; ?>
<script src="https://unpkg.com/feather-icons/dist/feather.min.js"></script>
<script>feather.replace();</script>
