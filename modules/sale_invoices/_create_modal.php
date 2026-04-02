<?php
$saleInvoiceModalEntities = $activeBusinessEntities ?? [];
$hasBusinessEntities = !empty($saleInvoiceModalEntities);
?>
<div class="modal-overlay hidden" id="saleInvoiceCreateModal">
  <div class="modal modal-md" style="max-width:760px">
    <div class="modal-header">
      <span class="modal-title"><?= __('si_create_title') ?></span>
      <button type="button" class="modal-close" data-sale-invoice-close><?= feather_icon('x', 18) ?></button>
    </div>
    <form method="POST" action="<?= url('modules/sale_invoices/create.php') ?>" id="saleInvoiceCreateForm">
      <?= csrf_field() ?>
      <input type="hidden" name="sale_id" id="saleInvoiceSaleId" value="0">
      <div class="modal-body">
        <div class="card" style="margin-bottom:14px;border-style:dashed">
          <div class="card-body" style="display:grid;gap:8px;padding:14px">
            <div class="flex-between" style="font-size:13px">
              <span class="text-muted"><?= __('pos_receipt_no') ?></span>
              <span class="fw-600 font-mono" id="saleInvoiceSaleReceipt">—</span>
            </div>
            <div class="flex-between" style="font-size:13px">
              <span class="text-muted"><?= __('cust_title') ?></span>
              <span class="fw-600" id="saleInvoiceSaleCustomer">—</span>
            </div>
            <div class="flex-between" style="font-size:13px">
              <span class="text-muted"><?= __('lbl_date') ?></span>
              <span class="fw-600" id="saleInvoiceSaleDate">—</span>
            </div>
          </div>
        </div>

        <div class="form-row form-row-2">
          <div class="form-group">
            <label class="form-label"><?= __('si_business_entity') ?> <span class="req">*</span></label>
            <select name="business_entity_id" id="saleInvoiceBusinessEntity" class="form-control" <?= $hasBusinessEntities ? '' : 'disabled' ?> required>
              <option value=""><?= __('btn_select') ?>...</option>
              <?php foreach ($saleInvoiceModalEntities as $entity): ?>
                <option value="<?= (int)$entity['id'] ?>"><?= e($entity['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <?php if (!$hasBusinessEntities): ?>
              <div class="text-secondary" style="font-size:12px;margin-top:6px">
                <?= __('si_no_business_entities') ?>
              </div>
            <?php endif; ?>
          </div>
          <div class="form-group">
            <label class="form-label"><?= __('invoice_doc_number') ?> <span class="req">*</span></label>
            <input type="text" name="invoice_number" id="saleInvoiceNumber" class="form-control mono" required>
          </div>
        </div>

        <div class="form-row form-row-2">
          <div class="form-group">
            <label class="form-label"><?= __('invoice_doc_date') ?> <span class="req">*</span></label>
            <input type="date" name="invoice_date" id="saleInvoiceDate" class="form-control" required>
          </div>
          <div class="form-group">
            <label class="form-label"><?= __('invoice_by_power') ?></label>
            <input type="text" name="power_of_attorney_no" class="form-control">
          </div>
        </div>

        <div class="form-row form-row-2">
          <div class="form-group">
            <label class="form-label"><?= __('invoice_from_date') ?></label>
            <input type="date" name="power_of_attorney_date" class="form-control">
          </div>
          <div class="form-group">
            <label class="form-label"><?= __('invoice_transport') ?></label>
            <input type="text" name="transport_company" class="form-control">
          </div>
        </div>

        <div class="form-row form-row-2">
          <div class="form-group">
            <label class="form-label"><?= __('invoice_transport_doc') ?></label>
            <input type="text" name="transport_waybill_no" class="form-control">
          </div>
          <div class="form-group">
            <label class="form-label"><?= __('invoice_doc_date') ?></label>
            <input type="date" name="transport_waybill_date" class="form-control">
          </div>
        </div>

        <div class="form-group mb-0">
          <label class="form-label"><?= __('lbl_notes') ?></label>
          <textarea name="notes" class="form-control" rows="3"></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-sale-invoice-close><?= __('btn_cancel') ?></button>
        <button type="submit" class="btn btn-primary" <?= $hasBusinessEntities ? '' : 'disabled' ?>>
          <?= feather_icon('file-plus', 16) ?> <?= __('si_create_submit') ?>
        </button>
      </div>
    </form>
  </div>
</div>

<script>
(function () {
  if (window.SaleInvoiceModal) {
    return;
  }

  const modal = document.getElementById('saleInvoiceCreateModal');
  const saleIdInput = document.getElementById('saleInvoiceSaleId');
  const saleReceipt = document.getElementById('saleInvoiceSaleReceipt');
  const saleCustomer = document.getElementById('saleInvoiceSaleCustomer');
  const saleDate = document.getElementById('saleInvoiceSaleDate');
  const invoiceNumber = document.getElementById('saleInvoiceNumber');
  const invoiceDate = document.getElementById('saleInvoiceDate');
  const closeButtons = document.querySelectorAll('[data-sale-invoice-close]');

  function openFromButton(button) {
    if (!modal || !button) {
      return;
    }
    saleIdInput.value = button.dataset.saleId || '0';
    saleReceipt.textContent = button.dataset.saleReceipt || '—';
    saleCustomer.textContent = button.dataset.saleCustomer || '—';
    saleDate.textContent = button.dataset.saleDateLabel || '—';
    invoiceNumber.value = button.dataset.invoiceNumber || button.dataset.saleReceipt || '';
    invoiceDate.value = button.dataset.invoiceDate || '';
    modal.classList.remove('hidden');
  }

  function close() {
    if (modal) {
      modal.classList.add('hidden');
    }
  }

  document.querySelectorAll('[data-open-sale-invoice-modal]').forEach(function (button) {
    button.addEventListener('click', function () {
      if (button.disabled) {
        return;
      }
      openFromButton(button);
    });
  });

  closeButtons.forEach(function (button) {
    button.addEventListener('click', close);
  });

  window.SaleInvoiceModal = { openFromButton, close };
})();
</script>