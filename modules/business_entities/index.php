<?php
require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../views/partials/icons.php';
Auth::requireLogin();
Auth::requirePerm('settings');

$pageTitle = __('be_title');
$breadcrumbs = [[$pageTitle, null]];

$search = sanitize($_GET['search'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));

$where = ['1=1'];
$params = [];
if ($search !== '') {
    $like = '%' . $search . '%';
    $where[] = '(be.name LIKE ? OR be.legal_name LIKE ? OR be.iin_bin LIKE ? OR be.phone LIKE ? OR be.email LIKE ?)';
    array_push($params, $like, $like, $like, $like, $like);
}

$whereSql = implode(' AND ', $where);
$total = (int)Database::value("SELECT COUNT(*) FROM business_entities be WHERE $whereSql", $params);
$pg = paginate($total, $page);
$rows = Database::all(
    "SELECT be.*, (SELECT COUNT(*) FROM sale_invoices si WHERE si.business_entity_id = be.id) AS invoice_count
     FROM business_entities be
     WHERE $whereSql
     ORDER BY be.is_active DESC, be.name ASC
     LIMIT {$pg['perPage']} OFFSET {$pg['offset']}",
    $params
);

include __DIR__ . '/../../views/layouts/header.php';
?>

<div class="page-header">
  <h1 class="page-heading"><?= __('be_title') ?></h1>
  <div class="page-actions">
    <a href="<?= url('modules/business_entities/add.php') ?>" class="btn btn-primary">
      <?= feather_icon('plus', 15) ?> <?= __('be_add') ?>
    </a>
  </div>
</div>

<form method="GET" class="filter-bar mb-2">
  <input type="text" name="search" class="form-control" value="<?= e($search) ?>" placeholder="<?= e(__('btn_search')) ?>" style="max-width:360px">
  <button type="submit" class="btn btn-secondary"><?= feather_icon('search', 14) ?></button>
  <a href="<?= url('modules/business_entities/') ?>" class="btn btn-ghost"><?= __('btn_reset') ?></a>
</form>

<div class="card">
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th><?= __('lbl_name') ?></th>
          <th><?= __('cust_inn') ?></th>
          <th><?= __('lbl_phone') ?></th>
          <th><?= __('be_responsible_name') ?></th>
          <th class="col-num"><?= __('be_invoice_count') ?></th>
          <th><?= __('lbl_status') ?></th>
          <th class="col-actions"><?= __('lbl_actions') ?></th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="7" class="text-center text-muted" style="padding:40px"><?= __('no_results') ?></td></tr>
        <?php else: ?>
          <?php foreach ($rows as $row): ?>
            <tr>
              <td>
                <div class="fw-600"><?= e($row['name']) ?></div>
                <?php if (!empty($row['legal_name'])): ?>
                  <div class="text-muted" style="font-size:11px"><?= e($row['legal_name']) ?></div>
                <?php endif; ?>
              </td>
              <td class="font-mono"><?= e($row['iin_bin'] ?: '—') ?></td>
              <td><?= e($row['phone'] ?: '—') ?></td>
              <td><?= e($row['responsible_name'] ?: '—') ?></td>
              <td class="col-num"><?= (int)$row['invoice_count'] ?></td>
              <td>
                <?= !empty($row['is_active'])
                  ? '<span class="badge badge-success">' . __('lbl_active') . '</span>'
                  : '<span class="badge badge-secondary">' . __('lbl_inactive') . '</span>' ?>
              </td>
              <td class="col-actions">
                <a href="<?= url('modules/business_entities/view.php?id=' . $row['id']) ?>" class="btn btn-sm btn-ghost btn-icon" title="<?= e(__('btn_view')) ?>">
                  <?= feather_icon('eye', 14) ?>
                </a>
                <a href="<?= url('modules/business_entities/edit.php?id=' . $row['id']) ?>" class="btn btn-sm btn-ghost btn-icon" title="<?= e(__('btn_edit')) ?>">
                  <?= feather_icon('edit-2', 14) ?>
                </a>
                <form method="POST" action="<?= url('modules/business_entities/delete.php') ?>" style="display:inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-ghost btn-icon" style="color:var(--danger)" title="<?= e(__('btn_delete')) ?>" data-confirm="<?= e(__('confirm_delete')) ?>">
                    <?= feather_icon('trash-2', 14) ?>
                  </button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($pg['pages'] > 1): ?>
    <div class="card-footer flex-between">
      <span class="text-secondary fs-sm"><?= $total ?> <?= __('results') ?></span>
      <div class="pagination">
        <?php for ($i = 1; $i <= $pg['pages']; $i++): ?>
          <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>" class="page-link <?= $i === $pg['page'] ? 'active' : '' ?>"><?= $i ?></a>
        <?php endfor; ?>
      </div>
    </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../../views/layouts/footer.php'; ?>
<script src="https://unpkg.com/feather-icons/dist/feather.min.js"></script>
<script>
feather.replace();
document.querySelectorAll('[data-confirm]').forEach(function (el) {
  el.addEventListener('click', function (e) {
    if (!confirm(el.dataset.confirm)) e.preventDefault();
  });
});
</script>