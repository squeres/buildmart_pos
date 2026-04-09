<?php
/**
 * Goods Receipt � View (Read-only)
 * modules/receipts/view.php
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
$canOpenAcceptance = Auth::can('acceptance');

$id = (int)($_GET['id'] ?? 0);
if (!$id) { redirect('/modules/receipts/'); }

$doc = Database::row(
    "SELECT gr.*,
            s.name  AS supplier_name, s.inn AS supplier_inn,
            w.name  AS warehouse_name,
            u.name  AS created_by_name,
            pu.name AS posted_by_name,
            cu.name AS cancelled_by_name
     FROM   goods_receipts gr
     LEFT JOIN suppliers  s  ON s.id  = gr.supplier_id
     LEFT JOIN warehouses w  ON w.id  = gr.warehouse_id
     LEFT JOIN users      u  ON u.id  = gr.created_by
     LEFT JOIN users      pu ON pu.id = gr.posted_by
     LEFT JOIN users      cu ON cu.id = gr.cancelled_by
     WHERE gr.id = ?",
    [$id]
);
if (!$doc) { flash_error(_r('err_not_found')); redirect('/modules/receipts/'); }
require_warehouse_access((int)$doc['warehouse_id'], '/modules/receipts/');

$items = Database::all(
    "SELECT * FROM goods_receipt_items WHERE receipt_id=? ORDER BY sort_order, id",
    [$id]
);

$pageTitle   = __('gr_title') . ': ' . $doc['doc_no'];
$breadcrumbs = [[__('gr_title'), url('modules/receipts/')], [$doc['doc_no'], null]];

include __DIR__ . '/../../views/layouts/header.php';
?>

<style>
.qc-overlay {
  display: none;
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,.65);
  z-index: 1200;
  align-items: center;
  justify-content: center;
  padding: 18px;
}
.qc-overlay.open { display: flex; }
.qc-modal {
  background: var(--bg-surface);
  border: 1px solid var(--border-medium);
  border-radius: var(--radius-xl);
  width: min(520px, 100%);
  box-shadow: var(--shadow-xl);
}
.qc-modal-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 18px 22px 14px;
  border-bottom: 1px solid var(--border-dim);
}
.qc-modal-title {
  font-size: 15px;
  font-weight: 600;
  color: var(--text-primary);
  display: flex;
  align-items: center;
  gap: 8px;
}
.qc-modal-close {
  background: none;
  border: none;
  color: var(--text-muted);
  cursor: pointer;
  padding: 2px 6px;
  border-radius: var(--radius-sm);
  font-size: 20px;
  line-height: 1;
}
.qc-modal-body {
  padding: 18px 22px;
}
.qc-modal-footer {
  padding: 12px 22px 18px;
  border-top: 1px solid var(--border-dim);
  display: flex;
  gap: 8px;
}
.doc-confirm-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px;
}
.doc-confirm-card {
  padding: 10px 12px;
  border: 1px solid var(--border-soft);
  border-radius: 12px;
  background: var(--bg-raised);
}
.doc-confirm-label {
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: .08em;
  color: var(--text-muted);
  margin-bottom: 4px;
}
.doc-confirm-value {
  font-size: 14px;
  font-weight: 600;
  color: var(--text-primary);
  word-break: break-word;
}
.doc-confirm-check {
  display: flex;
  gap: 10px;
  align-items: flex-start;
  margin-top: 12px;
  padding: 12px;
  border: 1px solid var(--border-soft);
  border-radius: 12px;
  background: var(--bg-raised);
}
.doc-confirm-check input { margin-top: 3px; }
.doc-confirm-check label { margin: 0; color: var(--text-primary); }
@media (max-width: 640px) {
  .doc-confirm-grid { grid-template-columns: 1fr; }
}
</style>

<div class="page-header">
  <div>
    <h1 class="page-heading"><?= e($doc['doc_no']) ?></h1>
    <div class="status-inline">
      <?= gr_status_badge($doc['status']) ?>
      <span class="text-muted fs-sm"><?= date_fmt($doc['doc_date'], 'd.m.Y') ?></span>
    </div>
  </div>
  <div class="page-actions">
    <?php if ($doc['status'] === 'draft' && $canEditReceipts): ?>
    <a href="<?= url('modules/receipts/edit.php?id='.$id) ?>" class="btn btn-secondary">
      <?= feather_icon('edit-2',15) ?> <?= __('btn_edit') ?>
    </a>
    <?php endif; ?>
    <?php if ($doc['status'] === 'draft' && $canPostReceipts): ?>
    <form method="POST" action="<?= url('modules/receipts/post.php') ?>" class="inline-action-form">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= (int)$id ?>">
      <button type="submit" class="btn btn-primary"
         data-doc-confirm="receipt-post">
        <?= feather_icon('send',15) ?> <?= __('gr_post') ?>
      </button>
    </form>
    <?php endif; ?>
    <?php if ($doc['status'] === 'pending_acceptance' && $canOpenAcceptance): ?>
    <a href="<?= url('modules/acceptance/view.php?id='.$id) ?>" class="btn btn-warning">
      <?= feather_icon('clipboard',15) ?> <?= __('acc_go_to_acceptance') ?>
    </a>
    <?php endif; ?>
    <a href="<?= url('modules/receipts/print.php?id='.$id) ?>" target="_blank" class="btn btn-ghost">
      <?= feather_icon('printer',15) ?> <?= __('btn_print') ?>
    </a>
    <?php if ($canExportReceipts): ?>
    <a href="<?= url('modules/receipts/export_excel.php?id='.$id) ?>" class="btn btn-ghost">
      <?= feather_icon('download',15) ?> Excel
    </a>
    <?php endif; ?>
    <?php if ($canCreateReceipts): ?>
    <a href="<?= url('modules/receipts/duplicate.php?id='.$id) ?>" class="btn btn-ghost">
      <?= feather_icon('copy',15) ?> <?= __('gr_duplicate') ?>
    </a>
    <?php endif; ?>
    <?php if ($canCancelReceipts && in_array($doc['status'], ['draft','pending_acceptance','accepted'], true)): ?>
    <form method="POST" action="<?= url('modules/receipts/cancel.php') ?>" class="inline-action-form">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= (int)$id ?>">
      <button type="submit" class="btn btn-ghost btn-danger-ghost"
         data-confirm="<?= __('gr_confirm_cancel') ?>">
        <?= feather_icon('x-circle',15) ?> <?= __('gr_cancel') ?>
      </button>
    </form>
    <?php endif; ?>
    <a href="<?= url('modules/receipts/') ?>" class="btn btn-ghost">
      <?= feather_icon('arrow-left',15) ?> <?= __('btn_back') ?>
    </a>
  </div>
</div>

<!-- Document metadata -->
<div class="grid grid-2 mobile-form-stack mb-3">
  <div class="card">
    <div class="card-header"><span class="card-title"><?= __('gr_header') ?></span></div>
    <div class="card-body fs-md">
      <table class="detail-table">
        <tr><td style="padding:4px 0;color:var(--text-muted);width:45%"><?= __('gr_doc_no') ?></td>
            <td class="font-mono fw-600"><?= e($doc['doc_no']) ?></td></tr>
        <tr><td style="padding:4px 0;color:var(--text-muted)"><?= __('lbl_date') ?></td>
            <td><?= date_fmt($doc['doc_date'], 'd.m.Y') ?></td></tr>
        <?php if ($doc['supplier_doc_no']): ?>
        <tr><td style="padding:4px 0;color:var(--text-muted)"><?= __('gr_supplier_doc_no') ?></td>
            <td class="font-mono"><?= e($doc['supplier_doc_no']) ?></td></tr>
        <?php endif; ?>
        <tr><td style="padding:4px 0;color:var(--text-muted)"><?= __('gr_supplier') ?></td>
            <td><?= e($doc['supplier_name'] ?? '�') ?>
              <?php if ($doc['supplier_inn']): ?>
                <div class="text-muted" style="font-size:11px"><?= __('sup_inn') ?>: <?= e($doc['supplier_inn']) ?></div>
              <?php endif; ?>
            </td></tr>
        <tr><td style="padding:4px 0;color:var(--text-muted)"><?= __('gr_warehouse') ?></td>
            <td><?= e($doc['warehouse_name'] ?? '�') ?></td></tr>
        <tr><td style="padding:4px 0;color:var(--text-muted)"><?= __('gr_accepted_by') ?></td>
            <td><?= e($doc['accepted_by'] ?? '�') ?></td></tr>
        <tr><td style="padding:4px 0;color:var(--text-muted)"><?= __('gr_delivered_by') ?></td>
            <td><?= e($doc['delivered_by'] ?? '�') ?></td></tr>
        <?php if ($doc['notes']): ?>
        <tr><td style="padding:4px 0;color:var(--text-muted)"><?= __('lbl_notes') ?></td>
            <td><?= nl2br(e($doc['notes'])) ?></td></tr>
        <?php endif; ?>
      </table>
    </div>
  </div>
  <div class="card">
    <div class="card-header"><span class="card-title"><?= __('lbl_status') ?></span></div>
    <div class="card-body fs-md">
      <table class="detail-table">
        <tr><td style="padding:4px 0;color:var(--text-muted);width:45%"><?= __('lbl_status') ?></td>
            <td><?= gr_status_badge($doc['status']) ?></td></tr>
        <tr><td style="padding:4px 0;color:var(--text-muted)"><?= __('lbl_created') ?></td>
            <td><?= date_fmt($doc['created_at']) ?> � <?= e($doc['created_by_name'] ?? '') ?></td></tr>
        <?php if ($doc['posted_at']): ?>
        <tr><td style="padding:4px 0;color:var(--text-muted)"><?= __('acc_sent_at') ?></td>
            <td><?= date_fmt($doc['posted_at']) ?> � <?= e($doc['posted_by_name'] ?? '') ?></td></tr>
        <?php endif; ?>
        <?php if ($doc['cancelled_at']): ?>
        <tr><td style="padding:4px 0;color:var(--text-muted)"><?= __('gr_cancelled_at') ?></td>
            <td><?= date_fmt($doc['cancelled_at']) ?> � <?= e($doc['cancelled_by_name'] ?? '') ?></td></tr>
        <?php endif; ?>
      </table>
    </div>
  </div>
</div>

<!-- Items table -->
<div class="card mb-3">
  <div class="card-header"><span class="card-title"><?= __('gr_items') ?></span></div>
  <div class="table-wrap mobile-table-scroll">
    <table class="table">
      <thead>
        <tr>
          <th style="width:36px">#</th>
          <th><?= __('gr_product') ?></th>
          <th style="width:70px"><?= __('lbl_unit') ?></th>
          <th class="col-num" style="width:90px"><?= __('lbl_qty') ?></th>
          <th class="col-num" style="width:110px"><?= __('gr_unit_price') ?></th>
          <th class="col-num" style="width:70px"><?= __('gr_tax_rate') ?></th>
          <th class="col-num" style="width:120px"><?= __('gr_line_total') ?></th>
          <th><?= __('lbl_notes') ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($items as $i => $item): ?>
        <tr>
          <td class="text-muted fs-sm text-center"><?= $i+1 ?></td>
          <td>
            <div class="fw-600"><?= e($item['name']) ?></div>
            <?php if ($item['product_id']): ?>
              <?php $p = Database::row("SELECT sku FROM products WHERE id=?", [$item['product_id']]); ?>
              <?php if ($p): ?><div class="text-muted font-mono fs-xs"><?= e($p['sku']) ?></div><?php endif; ?>
            <?php endif; ?>
          </td>
          <td><?= unit_label($item['unit']) ?></td>
          <td class="col-num"><?= fmtQty((float)$item['qty']) ?></td>
          <td class="col-num"><?= money($item['unit_price']) ?></td>
          <td class="col-num"><?= $item['tax_rate'] > 0 ? e($item['tax_rate']).'%' : '�' ?></td>
          <td class="col-num fw-600"><?= money($item['line_total']) ?></td>
          <td class="text-muted"><?= e($item['notes']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="6" class="text-right text-muted"><?= __('gr_subtotal') ?>:</td>
          <td class="col-num fw-600"><?= money($doc['subtotal']) ?></td>
          <td></td>
        </tr>
        <tr>
          <td colspan="6" class="text-right text-muted"><?= __('lbl_tax') ?>:</td>
          <td class="col-num"><?= money($doc['tax_amount']) ?></td>
          <td></td>
        </tr>
        <tr class="fs-lg">
          <td colspan="6" class="text-right fw-600"><?= __('lbl_total') ?>:</td>
          <td class="col-num fw-600"><?= money($doc['total']) ?></td>
          <td></td>
        </tr>
      </tfoot>
    </table>
  </div>
</div>

<div class="qc-overlay" id="modal-doc-confirm" role="dialog" aria-modal="true">
  <div class="qc-modal">
    <div class="qc-modal-header">
      <div class="qc-modal-title">
        <?= feather_icon('shield', 17) ?> <span><?= __('doc_confirm_post_title') ?></span>
      </div>
      <button type="button" class="qc-modal-close" id="doc-confirm-close">?</button>
    </div>
    <div class="qc-modal-body">
      <p class="text-muted mb-1"><?= __('doc_confirm_summary_hint') ?></p>
      <div class="doc-confirm-grid">
        <div class="doc-confirm-card">
          <div class="doc-confirm-label"><?= __('gr_doc_no') ?></div>
          <div class="doc-confirm-value"><?= e($doc['doc_no']) ?></div>
        </div>
        <div class="doc-confirm-card">
          <div class="doc-confirm-label"><?= __('lbl_date') ?></div>
          <div class="doc-confirm-value"><?= date_fmt($doc['doc_date'], 'd.m.Y') ?></div>
        </div>
        <div class="doc-confirm-card">
          <div class="doc-confirm-label"><?= __('gr_supplier') ?></div>
          <div class="doc-confirm-value"><?= e($doc['supplier_name'] ?? '�') ?></div>
        </div>
        <div class="doc-confirm-card">
          <div class="doc-confirm-label"><?= __('gr_warehouse') ?></div>
          <div class="doc-confirm-value"><?= e($doc['warehouse_name'] ?? '�') ?></div>
        </div>
        <div class="doc-confirm-card">
          <div class="doc-confirm-label"><?= __('gr_items') ?></div>
          <div class="doc-confirm-value"><?= count($items) ?></div>
        </div>
        <div class="doc-confirm-card">
          <div class="doc-confirm-label"><?= __('lbl_total') ?></div>
          <div class="doc-confirm-value"><?= money($doc['total']) ?></div>
        </div>
      </div>
      <div class="doc-confirm-check">
        <input type="checkbox" id="doc-confirm-checkbox">
        <label for="doc-confirm-checkbox"><?= __('doc_confirm_post_checkbox') ?></label>
      </div>
    </div>
    <div class="qc-modal-footer">
      <button type="button" class="btn btn-primary" id="doc-confirm-submit" disabled><?= __('btn_confirm') ?></button>
      <button type="button" class="btn btn-ghost" id="doc-confirm-cancel"><?= __('btn_cancel') ?></button>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../../views/layouts/footer.php'; ?>
<script src="https://unpkg.com/feather-icons/dist/feather.min.js"></script>
<script>
feather.replace();
const receiptConfirmModal = document.getElementById('modal-doc-confirm');
const receiptConfirmCheckbox = document.getElementById('doc-confirm-checkbox');
const receiptConfirmSubmit = document.getElementById('doc-confirm-submit');
let pendingReceiptSubmitter = null;

function openReceiptConfirm(submitter) {
  pendingReceiptSubmitter = submitter;
  if (receiptConfirmCheckbox) receiptConfirmCheckbox.checked = false;
  if (receiptConfirmSubmit) receiptConfirmSubmit.disabled = true;
  receiptConfirmModal?.classList.add('open');
}

function closeReceiptConfirm() {
  pendingReceiptSubmitter = null;
  if (receiptConfirmCheckbox) receiptConfirmCheckbox.checked = false;
  if (receiptConfirmSubmit) receiptConfirmSubmit.disabled = true;
  receiptConfirmModal?.classList.remove('open');
}

document.querySelectorAll('[data-doc-confirm="receipt-post"]').forEach(function(el){
  el.addEventListener('click', function(e){
    e.preventDefault();
    openReceiptConfirm(el);
  });
});
document.querySelectorAll('[data-confirm]:not([data-doc-confirm])').forEach(function(el){
  el.addEventListener('click', function(e){
    if(!confirm(el.dataset.confirm)) e.preventDefault();
  });
});
document.getElementById('doc-confirm-close')?.addEventListener('click', closeReceiptConfirm);
document.getElementById('doc-confirm-cancel')?.addEventListener('click', closeReceiptConfirm);
receiptConfirmCheckbox?.addEventListener('change', function(){
  if (receiptConfirmSubmit) receiptConfirmSubmit.disabled = !receiptConfirmCheckbox.checked;
});
receiptConfirmSubmit?.addEventListener('click', function(){
  if (!pendingReceiptSubmitter || !receiptConfirmCheckbox?.checked) return;
  const submitter = pendingReceiptSubmitter;
  closeReceiptConfirm();
  submitter.form?.requestSubmit(submitter);
});
</script>
