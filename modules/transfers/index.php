<?php
/**
 * modules/transfers/index.php
 * List of stock transfer documents.
 */
require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../views/partials/icons.php';
Auth::requireLogin();
Auth::requirePerm('transfers');

$pageTitle   = __('tr_title');
$breadcrumbs = [[$pageTitle, null]];

$status    = sanitize($_GET['status']   ?? '');
$fromWh    = (int)($_GET['from_wh']     ?? 0);
$toWh      = (int)($_GET['to_wh']       ?? 0);
$page      = max(1, (int)($_GET['page'] ?? 1));

$accessIds = user_warehouse_ids();
$whPlaceholders = implode(',', array_fill(0, count($accessIds), '?'));

// Build WHERE
$where  = ["(t.from_warehouse_id IN ($whPlaceholders) OR t.to_warehouse_id IN ($whPlaceholders))"];
$params = array_merge($accessIds, $accessIds);

if ($status !== '') { $where[] = 't.status=?'; $params[] = $status; }
if ($fromWh > 0)    { $where[] = 't.from_warehouse_id=?'; $params[] = $fromWh; }
if ($toWh   > 0)    { $where[] = 't.to_warehouse_id=?'; $params[] = $toWh; }

$whereSQL = implode(' AND ', $where);

$total = (int)Database::value("SELECT COUNT(*) FROM stock_transfers t WHERE $whereSQL", $params);
$pg    = paginate($total, $page);

$transfers = Database::all(
    "SELECT t.*,
            wf.name AS from_wh_name,
            wt.name AS to_wh_name,
            u.name  AS created_by_name,
            (SELECT COUNT(*) FROM stock_transfer_items i WHERE i.transfer_id=t.id) AS item_count
     FROM stock_transfers t
     JOIN warehouses wf ON wf.id = t.from_warehouse_id
     JOIN warehouses wt ON wt.id = t.to_warehouse_id
     JOIN users u ON u.id = t.created_by
     WHERE $whereSQL
     ORDER BY t.created_at DESC
     LIMIT {$pg['perPage']} OFFSET {$pg['offset']}",
    $params
);

$warehouses = Database::all("SELECT id, name FROM warehouses WHERE is_active=1 ORDER BY name");

include __DIR__ . '/../../views/layouts/header.php';
?>

<div class="page-header">
  <h1 class="page-heading"><?= __('tr_title') ?></h1>
  <div class="page-actions">
    <a href="<?= url('modules/transfers/create.php') ?>" class="btn btn-primary">
      <?= feather_icon('plus', 15) ?> <?= __('tr_create') ?>
    </a>
  </div>
</div>

<form method="GET" class="filter-bar mb-2">
  <select name="from_wh" class="form-control" style="max-width:180px">
    <option value=""><?= __('tr_from_wh') ?>: <?= __('lbl_all') ?></option>
    <?php foreach ($warehouses as $w): ?>
      <option value="<?= $w['id'] ?>" <?= $fromWh==$w['id']?'selected':'' ?>><?= e($w['name']) ?></option>
    <?php endforeach; ?>
  </select>
  <select name="to_wh" class="form-control" style="max-width:180px">
    <option value=""><?= __('tr_to_wh') ?>: <?= __('lbl_all') ?></option>
    <?php foreach ($warehouses as $w): ?>
      <option value="<?= $w['id'] ?>" <?= $toWh==$w['id']?'selected':'' ?>><?= e($w['name']) ?></option>
    <?php endforeach; ?>
  </select>
  <select name="status" class="form-control" style="max-width:150px">
    <option value=""><?= __('lbl_all') ?></option>
    <option value="draft"     <?= $status==='draft'    ?'selected':'' ?>><?= __('tr_status_draft') ?></option>
    <option value="posted"    <?= $status==='posted'   ?'selected':'' ?>><?= __('tr_status_posted') ?></option>
    <option value="cancelled" <?= $status==='cancelled'?'selected':'' ?>><?= __('tr_status_cancelled') ?></option>
  </select>
  <button type="submit" class="btn btn-secondary"><?= feather_icon('search',14) ?> <?= __('btn_filter') ?></button>
  <a href="<?= url('modules/transfers/') ?>" class="btn btn-ghost"><?= __('btn_reset') ?></a>
</form>

<div class="card">
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th><?= __('tr_doc_no') ?></th>
          <th><?= __('lbl_date') ?></th>
          <th><?= __('tr_from_wh') ?></th>
          <th><?= __('tr_to_wh') ?></th>
          <th class="col-num"><?= __('tr_items') ?></th>
          <th><?= __('lbl_status') ?></th>
          <th><?= __('tr_created_by') ?></th>
          <th class="col-actions"><?= __('lbl_actions') ?></th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$transfers): ?>
          <tr><td colspan="8" class="text-center text-muted" style="padding:40px"><?= __('no_results') ?></td></tr>
        <?php else: ?>
          <?php foreach ($transfers as $t): ?>
          <tr>
            <td class="font-mono fw-600"><?= e($t['doc_no']) ?></td>
            <td><?= date_fmt($t['doc_date'], 'd.m.Y') ?></td>
            <td><?= e($t['from_wh_name']) ?></td>
            <td><?= e($t['to_wh_name']) ?></td>
            <td class="col-num"><?= (int)$t['item_count'] ?></td>
            <td><?= transfer_status_badge($t['status']) ?></td>
            <td class="text-muted"><?= e($t['created_by_name']) ?></td>
            <td class="col-actions">
              <a href="<?= url('modules/transfers/view.php?id='.$t['id']) ?>"
                 class="btn btn-sm btn-ghost" title="<?= __('btn_view') ?>">
                <?= feather_icon('eye', 14) ?>
              </a>
              <?php if ($t['status'] === 'draft'): ?>
                <a href="<?= url('modules/transfers/view.php?id='.$t['id']) ?>"
                   class="btn btn-sm btn-secondary" style="font-size:12px">
                  <?= feather_icon('send', 12) ?> <?= __('tr_post') ?>
                </a>
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
