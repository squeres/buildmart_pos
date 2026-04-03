<?php
require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../views/partials/icons.php';
Auth::requireLogin();

$id = (int)($_GET['id'] ?? 0);
$isEdit = $id > 0;
Auth::requirePerm($isEdit ? 'customers.edit' : 'customers.create');
$cust = $isEdit ? Database::row("SELECT * FROM customers WHERE id=? AND id!=1", [$id]) : null;

if ($isEdit && !$cust) {
    flash_error(_r('err_not_found'));
    redirect('/modules/customers/');
}

$pageTitle = $isEdit ? __('cust_edit') : __('cust_add');
$breadcrumbs = [[__('cust_title'), url('modules/customers/')], [$pageTitle, null]];
$errors = [];

$f = $cust ?? [
    'name' => '',
    'customer_type' => 'individual',
    'company' => '',
    'contact_person' => '',
    'inn' => '',
    'address' => '',
    'phone' => '',
    'email' => '',
    'notes' => '',
    'discount_pct' => 0,
];

if (is_post()) {
    if (!csrf_verify()) {
        flash_error(_r('err_csrf'));
        redirect($_SERVER['REQUEST_URI']);
    }

    $f = [
        'name' => sanitize($_POST['name'] ?? ''),
        'customer_type' => customer_type_normalize($_POST['customer_type'] ?? 'individual'),
        'company' => sanitize($_POST['company'] ?? ''),
        'contact_person' => sanitize($_POST['contact_person'] ?? ''),
        'inn' => sanitize($_POST['inn'] ?? ''),
        'address' => sanitize($_POST['address'] ?? ''),
        'phone' => sanitize($_POST['phone'] ?? ''),
        'email' => sanitize($_POST['email'] ?? ''),
        'notes' => sanitize($_POST['notes'] ?? ''),
        'discount_pct' => min(100, max(0, sanitize_float($_POST['discount_pct'] ?? 0))),
    ];

    if ($f['name'] === '') {
        $errors['name'] = _r('lbl_required');
    }
    if ($f['customer_type'] === 'legal' && $f['inn'] === '') {
        $errors['inn'] = _r('cust_iin_required');
    }

    if (!$errors) {
        if ($isEdit) {
            Database::exec(
                "UPDATE customers
                 SET name=?, customer_type=?, company=?, contact_person=?, inn=?, address=?, phone=?, email=?, notes=?, discount_pct=?, updated_at=NOW()
                 WHERE id=?",
                [
                    $f['name'],
                    $f['customer_type'],
                    $f['company'],
                    $f['contact_person'],
                    $f['inn'],
                    $f['address'],
                    $f['phone'],
                    $f['email'],
                    $f['notes'],
                    $f['discount_pct'],
                    $id,
                ]
            );
        } else {
            Database::insert(
                "INSERT INTO customers (name, customer_type, company, contact_person, inn, address, phone, email, notes, discount_pct)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $f['name'],
                    $f['customer_type'],
                    $f['company'],
                    $f['contact_person'],
                    $f['inn'],
                    $f['address'],
                    $f['phone'],
                    $f['email'],
                    $f['notes'],
                    $f['discount_pct'],
                ]
            );
        }

        flash_success(_r('cust_saved'));
        redirect('/modules/customers/');
    }
}

include __DIR__ . '/../../views/layouts/header.php';
?>

<div class="page-header">
  <h1 class="page-heading"><?= e($pageTitle) ?></h1>
</div>

<div style="max-width:920px">
  <form method="POST" id="customerForm">
    <?= csrf_field() ?>

    <?php if ($errors): ?>
      <div class="flash flash-error mb-2">
        <?= feather_icon('alert-circle', 15) ?>
        <span><?= __('err_validation') ?></span>
      </div>
    <?php endif; ?>

    <div class="card">
      <div class="card-body">
        <div class="form-row form-row-2">
          <div class="form-group">
            <label class="form-label"><?= __('cust_type') ?></label>
            <select name="customer_type" id="customerTypeSelect" class="form-control">
              <option value="individual" <?= $f['customer_type'] === 'individual' ? 'selected' : '' ?>><?= __('cust_type_individual') ?></option>
              <option value="legal" <?= $f['customer_type'] === 'legal' ? 'selected' : '' ?>><?= __('cust_type_legal') ?></option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label"><?= __('cust_discount') ?></label>
            <input type="number" name="discount_pct" class="form-control mono" value="<?= e((string)$f['discount_pct']) ?>" min="0" max="100" step="0.5">
          </div>
        </div>

        <div class="form-row form-row-2">
          <div class="form-group">
            <label class="form-label"><?= __('cust_name_or_title') ?> <span class="req">*</span></label>
            <input type="text" name="name" class="form-control" value="<?= e($f['name']) ?>" required>
            <?php if (isset($errors['name'])): ?>
              <div class="form-error"><?= e($errors['name']) ?></div>
            <?php endif; ?>
          </div>
          <div class="form-group">
            <label class="form-label"><?= __('cust_company') ?></label>
            <input type="text" name="company" class="form-control" value="<?= e($f['company']) ?>">
          </div>
        </div>

        <div id="legalCustomerBlock" class="card" style="margin:8px 0 14px;border-style:dashed;<?= $f['customer_type'] === 'legal' ? '' : 'display:none' ?>">
          <div class="card-body" style="padding:14px">
            <div class="text-secondary" style="font-size:13px;line-height:1.5">
              <?= __('cust_legal_hint') ?>
            </div>
          </div>
        </div>

        <div class="form-row form-row-2">
          <div class="form-group">
            <label class="form-label"><?= __('cust_contact_person') ?></label>
            <input type="text" name="contact_person" class="form-control" value="<?= e($f['contact_person']) ?>">
          </div>
          <div class="form-group">
            <label class="form-label"><?= __('cust_inn') ?> <span class="req" id="customerInnRequired" style="<?= $f['customer_type'] === 'legal' ? '' : 'display:none' ?>">*</span></label>
            <input type="text" name="inn" class="form-control mono" value="<?= e($f['inn']) ?>">
            <?php if (isset($errors['inn'])): ?>
              <div class="form-error"><?= e($errors['inn']) ?></div>
            <?php endif; ?>
          </div>
        </div>

        <div class="form-row form-row-2">
          <div class="form-group">
            <label class="form-label"><?= __('lbl_phone') ?></label>
            <input type="tel" name="phone" class="form-control" value="<?= e($f['phone']) ?>">
          </div>
          <div class="form-group">
            <label class="form-label"><?= __('lbl_email') ?></label>
            <input type="email" name="email" class="form-control" value="<?= e($f['email']) ?>">
          </div>
        </div>

        <div class="form-group">
          <label class="form-label"><?= __('lbl_address') ?></label>
          <textarea name="address" class="form-control" rows="2"><?= e($f['address']) ?></textarea>
        </div>

        <div class="form-group mb-0">
          <label class="form-label"><?= __('lbl_notes') ?></label>
          <textarea name="notes" class="form-control" rows="3"><?= e($f['notes']) ?></textarea>
        </div>
      </div>

      <div class="card-footer" style="display:flex;gap:8px">
        <button type="submit" class="btn btn-primary btn-lg">
          <?= feather_icon('save', 17) ?> <?= __('btn_save') ?>
        </button>
        <a href="<?= url('modules/customers/') ?>" class="btn btn-ghost btn-lg"><?= __('btn_cancel') ?></a>
      </div>
    </div>
  </form>
</div>

<?php include __DIR__ . '/../../views/layouts/footer.php'; ?>
<script src="https://unpkg.com/feather-icons/dist/feather.min.js"></script>
<script>
  feather.replace();
  (function () {
    const select = document.getElementById('customerTypeSelect');
    const legalBlock = document.getElementById('legalCustomerBlock');
    const innInput = document.querySelector('input[name="inn"]');
    const innRequired = document.getElementById('customerInnRequired');

    function syncType() {
      if (!select || !legalBlock || !innInput) {
        return;
      }
      const isLegal = select.value === 'legal';
      legalBlock.style.display = isLegal ? '' : 'none';
      innInput.required = isLegal;
      if (innRequired) {
        innRequired.style.display = isLegal ? '' : 'none';
      }
    }

    if (select) {
      select.addEventListener('change', syncType);
      syncType();
    }
  })();
</script>
