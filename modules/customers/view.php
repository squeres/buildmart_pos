<?php
require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../views/partials/icons.php';
Auth::requireLogin();
Auth::requirePerm('customers');

$id = (int)($_GET['id'] ?? 0);
$cust = Database::row("SELECT * FROM customers WHERE id=?", [$id]);
if (!$cust) {
    flash_error(_r('err_not_found'));
    redirect('/modules/customers/');
}

$pageTitle = e($cust['name']);
$breadcrumbs = [[__('cust_title'), url('modules/customers/')], [$pageTitle, null]];

$sales = Database::all(
    "SELECT s.id, s.receipt_no, s.total, s.status, s.created_at, u.name AS cashier
     FROM sales s
     JOIN users u ON u.id = s.user_id
     WHERE s.customer_id = ?
     ORDER BY s.created_at DESC
     LIMIT 50",
    [$id]
);

$isLegal = customer_is_legal($cust);

include __DIR__ . '/../../views/layouts/header.php';
?>

<div class="page-header">
  <div>
    <h1 class="page-heading"><?= e($cust['name']) ?></h1>
    <div style="margin-top:8px">
      <span class="badge badge-<?= $isLegal ? 'warning' : 'secondary' ?>">
        <?= e(customer_type_label((string)$cust['customer_type'])) ?>
      </span>
    </div>
  </div>
  <a href="<?= url('modules/customers/edit.php?id=' . $id) ?>" class="btn btn-secondary">
    <?= feather_icon('edit-2', 15) ?> <?= __('btn_edit') ?>
  </a>
</div>

<div style="display:grid;grid-template-columns:340px 1fr;gap:16px;align-items:start">
  <div style="display:flex;flex-direction:column;gap:12px">
    <div class="card">
      <div class="card-body" style="display:flex;flex-direction:column;gap:10px">
        <?php
        $info = [
            [__('cust_type'), customer_type_label((string)$cust['customer_type'])],
            [__('cust_company'), $cust['company'] ?: '—'],
            [__('cust_contact_person'), $cust['contact_person'] ?: '—'],
            [__('cust_inn'), $cust['inn'] ?: '—'],
            [__('lbl_phone'), $cust['phone'] ?: '—'],
            [__('lbl_email'), $cust['email'] ?: '—'],
            [__('cust_discount'), $cust['discount_pct'] . '%'],
            [__('cust_total_spent'), money((float)$cust['total_spent'])],
            [__('cust_visits'), (string)$cust['visits']],
            [__('lbl_created'), date_fmt((string)$cust['created_at'], 'd.m.Y')],
        ];
        ?>
        <?php foreach ($info as [$label, $value]): ?>
          <div style="display:flex;justify-content:space-between;gap:8px;font-size:13px">
            <span class="text-muted"><?= e($label) ?></span>
            <span class="fw-600" style="text-align:right"><?= e((string)$value) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><span class="card-title"><?= __('lbl_address') ?></span></div>
      <div class="card-body" style="font-size:13px;line-height:1.6">
        <?= $cust['address'] ? nl2br(e($cust['address'])) : '<span class="text-muted">—</span>' ?>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><span class="card-title"><?= __('lbl_notes') ?></span></div>
      <div class="card-body" style="font-size:13px;line-height:1.6">
        <?= $cust['notes'] ? nl2br(e($cust['notes'])) : '<span class="text-muted">—</span>' ?>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><span class="card-title"><?= __('cust_history') ?></span></div>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th><?= __('pos_receipt_no') ?></th>
            <th><?= __('shift_cashier') ?></th>
            <th><?= __('lbl_status') ?></th>
            <th class="col-num"><?= __('lbl_total') ?></th>
            <th><?= __('lbl_date') ?></th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$sales): ?>
            <tr><td colspan="5" class="text-center text-muted" style="padding:30px"><?= __('cust_no_sales') ?></td></tr>
          <?php else: ?>
            <?php foreach ($sales as $sale): ?>
              <tr>
                <td><a href="<?= url('modules/sales/view.php?id=' . $sale['id']) ?>" class="font-mono"><?= e($sale['receipt_no']) ?></a></td>
                <td><?= e($sale['cashier']) ?></td>
                <td>
                  <span class="badge badge-<?= $sale['status'] === 'completed' ? 'success' : 'secondary' ?>">
                    <?= e($sale['status']) ?>
                  </span>
                </td>
                <td class="col-num fw-600"><?= money((float)$sale['total']) ?></td>
                <td class="text-muted"><?= date_fmt((string)$sale['created_at']) ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../../views/layouts/footer.php'; ?>
<script src="https://unpkg.com/feather-icons/dist/feather.min.js"></script>
<script>feather.replace();</script>