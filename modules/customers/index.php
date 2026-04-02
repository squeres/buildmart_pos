<?php
require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../views/partials/icons.php';
Auth::requireLogin();
Auth::requirePerm('customers');

$pageTitle = __('cust_title');
$breadcrumbs = [[$pageTitle, null]];

$search = sanitize($_GET['search'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));

$where = ['id != 1'];
$params = [];
if ($search !== '') {
    $like = '%' . $search . '%';
    $where[] = '(name LIKE ? OR phone LIKE ? OR email LIKE ? OR company LIKE ? OR contact_person LIKE ? OR inn LIKE ?)';
    array_push($params, $like, $like, $like, $like, $like, $like);
}

$whereSQL = implode(' AND ', $where);
$total = (int)Database::value("SELECT COUNT(*) FROM customers WHERE $whereSQL", $params);
$pg = paginate($total, $page);
$customers = Database::all(
    "SELECT * FROM customers
     WHERE $whereSQL
     ORDER BY CASE WHEN customer_type='legal' THEN 0 ELSE 1 END, name
     LIMIT {$pg['perPage']} OFFSET {$pg['offset']}",
    $params
);

include __DIR__ . '/../../views/layouts/header.php';
?>

<div class="page-header">
  <h1 class="page-heading"><?= __('cust_title') ?></h1>
  <div class="page-actions">
    <a href="<?= url('modules/customers/add.php') ?>" class="btn btn-primary">
      <?= feather_icon('user-plus', 15) ?> <?= __('cust_add') ?>
    </a>
  </div>
</div>

<form method="GET" class="filter-bar mb-2">
  <input
    type="text"
    name="search"
    class="form-control"
    placeholder="<?= e(__('btn_search')) ?> — <?= e(__('lbl_name')) ?>, <?= e(__('cust_company')) ?>, <?= e(__('cust_contact_person')) ?>"
    value="<?= e($search) ?>"
    style="max-width:420px"
  >
  <button type="submit" class="btn btn-secondary"><?= feather_icon('search', 14) ?></button>
  <a href="<?= url('modules/customers/') ?>" class="btn btn-ghost"><?= __('btn_reset') ?></a>
</form>

<div class="card">
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th><?= __('lbl_name') ?></th>
          <th><?= __('cust_type') ?></th>
          <th><?= __('cust_company') ?></th>
          <th><?= __('cust_contact_person') ?></th>
          <th><?= __('cust_inn') ?></th>
          <th><?= __('cust_discount') ?></th>
          <th class="col-num"><?= __('cust_total_spent') ?></th>
          <th class="col-num"><?= __('cust_visits') ?></th>
          <th class="col-actions"><?= __('lbl_actions') ?></th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$customers): ?>
          <tr>
            <td colspan="9" class="text-center text-muted" style="padding:40px"><?= __('no_results') ?></td>
          </tr>
        <?php else: ?>
          <?php foreach ($customers as $customer): ?>
            <?php $isLegal = customer_is_legal($customer); ?>
            <tr>
              <td>
                <div class="fw-600"><?= e($customer['name']) ?></div>
                <?php if (!empty($customer['phone']) || !empty($customer['email'])): ?>
                  <div class="text-muted" style="font-size:11px">
                    <?= e($customer['phone'] ?: '') ?><?= !empty($customer['phone']) && !empty($customer['email']) ? ' · ' : '' ?><?= e($customer['email'] ?: '') ?>
                  </div>
                <?php endif; ?>
              </td>
              <td>
                <span class="badge badge-<?= $isLegal ? 'warning' : 'secondary' ?>">
                  <?= e(customer_type_label((string)$customer['customer_type'])) ?>
                </span>
              </td>
              <td><?= e($customer['company'] ?: '—') ?></td>
              <td><?= e($customer['contact_person'] ?: '—') ?></td>
              <td class="font-mono"><?= e($customer['inn'] ?: '—') ?></td>
              <td><?= $customer['discount_pct'] > 0 ? '<span class="badge badge-success">' . e((string)$customer['discount_pct']) . '%</span>' : '—' ?></td>
              <td class="col-num fw-600"><?= money((float)$customer['total_spent']) ?></td>
              <td class="col-num"><?= (int)$customer['visits'] ?></td>
              <td class="col-actions">
                <a href="<?= url('modules/customers/view.php?id=' . $customer['id']) ?>" class="btn btn-sm btn-ghost btn-icon" title="<?= e(__('btn_view')) ?>">
                  <?= feather_icon('eye', 14) ?>
                </a>
                <a href="<?= url('modules/customers/edit.php?id=' . $customer['id']) ?>" class="btn btn-sm btn-ghost btn-icon" title="<?= e(__('btn_edit')) ?>">
                  <?= feather_icon('edit-2', 14) ?>
                </a>
                <form method="POST" action="<?= url('modules/customers/delete.php') ?>" style="display:inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="id" value="<?= (int)$customer['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-ghost btn-icon" style="color:var(--danger)" data-confirm="<?= e(__('confirm_delete')) ?>" title="<?= e(__('btn_delete')) ?>">
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
<script>feather.replace();</script>