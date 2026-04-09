<?php
/**
 * modules/transfers/view.php
 * View a transfer document; handle Post and Cancel actions.
 */
require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../views/partials/icons.php';
Auth::requireLogin();
Auth::requirePerm('transfers');

$id = (int)($_GET['id'] ?? 0);
if (!$id) { flash_error(_r('err_not_found')); redirect('/modules/transfers/'); }

$transfer = Database::row(
    "SELECT t.*,
            wf.name AS from_wh_name,
            wt.name AS to_wh_name,
            uc.name AS created_by_name,
            up.name AS posted_by_name,
            ux.name AS cancelled_by_name
     FROM stock_transfers t
     JOIN warehouses wf ON wf.id = t.from_warehouse_id
     JOIN warehouses wt ON wt.id = t.to_warehouse_id
     JOIN users uc ON uc.id = t.created_by
     LEFT JOIN users up ON up.id = t.posted_by
     LEFT JOIN users ux ON ux.id = t.cancelled_by
     WHERE t.id = ?",
    [$id]
);

if (!$transfer) { flash_error(_r('err_not_found')); redirect('/modules/transfers/'); }

$accessible = user_warehouse_ids();
if (!in_array($transfer['from_warehouse_id'], $accessible) && !in_array($transfer['to_warehouse_id'], $accessible)) {
    http_response_code(403); include ROOT_PATH . '/views/partials/403.php'; exit;
}

$loadTransferItems = static function (int $transferId): array {
    return Database::all(
        "SELECT i.*, p.sku, p.name_en, p.name_ru, p.unit AS prod_unit, p.is_weighable
         FROM stock_transfer_items i
         JOIN products p ON p.id = i.product_id
         WHERE i.transfer_id = ?
         ORDER BY i.sort_order, i.id",
        [$transferId]
    );
};

$items = $loadTransferItems($id);

if (is_post()) {
    if (!csrf_verify()) { flash_error(_r('err_csrf')); redirect($_SERVER['REQUEST_URI']); }

    $action = sanitize($_POST['action'] ?? '');

    if ($action === 'post' && $transfer['status'] === 'draft') {
        if (empty($items)) {
            flash_error(__('tr_err_no_items'));
            redirect($_SERVER['REQUEST_URI']);
        }

        try {
            Database::beginTransaction();

            $locked = Database::row("SELECT status FROM stock_transfers WHERE id=? FOR UPDATE", [$id]);
            if (($locked['status'] ?? '') !== 'draft') {
                throw new \Exception(__('tr_err_already_posted'));
            }

            $postItems = $loadTransferItems($id);
            if (!$postItems) {
                throw new \Exception(__('tr_err_no_items'));
            }

            $requiredByProduct = [];
            $productMeta = [];
            foreach ($postItems as &$item) {
                $pid = (int)$item['product_id'];
                $units = product_units($pid, (string)$item['prod_unit']);
                $resolvedUnit = product_resolve_unit($units, (string)$item['prod_unit'], (string)$item['unit']);
                $qtyBase = (float)$item['qty_base'];
                if ($qtyBase <= 0) {
                    $qtyBase = product_qty_to_base_unit(
                        (float)$item['qty'],
                        $units,
                        (string)$item['prod_unit'],
                        (string)$resolvedUnit['unit_code']
                    );
                }
                $storedUnitLabel = trim((string)($item['unit_label'] ?? ''));
                $item['unit_label_resolved'] = ($storedUnitLabel !== '' && $storedUnitLabel !== (string)$item['unit'])
                    ? $storedUnitLabel
                    : product_unit_label_text($resolvedUnit);
                $item['qty_base_resolved'] = $qtyBase;
                $requiredByProduct[$pid] = ($requiredByProduct[$pid] ?? 0.0) + $qtyBase;
                $productMeta[$pid] = [
                    'name' => product_name($item),
                    'base_unit' => (string)$item['prod_unit'],
                    'units' => $units,
                ];
            }
            unset($item);

            foreach ($requiredByProduct as $pid => $requiredQtyBase) {
                $lockedBalance = Database::row(
                    "SELECT qty FROM stock_balances WHERE product_id=? AND warehouse_id=? FOR UPDATE",
                    [$pid, (int)$transfer['from_warehouse_id']]
                );
                $availableBase = $lockedBalance ? (float)$lockedBalance['qty'] : 0.0;
                if ($availableBase + 0.000001 < $requiredQtyBase) {
                    $meta = $productMeta[$pid];
                    throw new \Exception(sprintf(
                        __('tr_err_insufficient'),
                        $meta['name'],
                        product_stock_breakdown($availableBase, $meta['units'], $meta['base_unit']),
                        product_stock_breakdown($requiredQtyBase, $meta['units'], $meta['base_unit'])
                    ));
                }
            }

            foreach ($postItems as $item) {
                $pid = (int)$item['product_id'];
                $qtyBase = (float)$item['qty_base_resolved'];
                $fromWh = (int)$transfer['from_warehouse_id'];
                $toWh   = (int)$transfer['to_warehouse_id'];
                $unitLabel = (string)$item['unit_label_resolved'];
                $qtyLabel = fmtQty((float)$item['qty']) . ' ' . $unitLabel;

                [$beforeFrom, $afterFrom] = update_stock_balance($pid, $fromWh, -$qtyBase);
                [$beforeTo, $afterTo] = update_stock_balance($pid, $toWh, +$qtyBase);

                Database::insert(
                    "INSERT INTO inventory_movements
                        (product_id, warehouse_id, user_id, type, qty_change, qty_before, qty_after,
                         reference_id, reference_type, notes, created_at)
                     VALUES (?,?,?,?,?,?,?,?,?,?,NOW())",
                    [
                        $pid,
                        $fromWh,
                        Auth::id(),
                        'transfer',
                        -$qtyBase,
                        $beforeFrom,
                        $afterFrom,
                        $id,
                        'transfer',
                        sprintf(__('tr_movement_out'), $transfer['to_wh_name']) . ' [' . $qtyLabel . ']',
                    ]
                );

                Database::insert(
                    "INSERT INTO inventory_movements
                        (product_id, warehouse_id, user_id, type, qty_change, qty_before, qty_after,
                         reference_id, reference_type, notes, created_at)
                     VALUES (?,?,?,?,?,?,?,?,?,?,NOW())",
                    [
                        $pid,
                        $toWh,
                        Auth::id(),
                        'transfer',
                        +$qtyBase,
                        $beforeTo,
                        $afterTo,
                        $id,
                        'transfer',
                        sprintf(__('tr_movement_in'), $transfer['from_wh_name']) . ' [' . $qtyLabel . ']',
                    ]
                );
            }

            Database::exec(
                "UPDATE stock_transfers SET status='posted', posted_by=?, posted_at=NOW() WHERE id=?",
                [Auth::id(), $id]
            );

            Database::commit();
            flash_success(_r('tr_posted'));
            redirect($_SERVER['REQUEST_URI']);
        } catch (\Throwable $e) {
            Database::rollback();
            error_log($e->__toString());
            flash_error(_r('err_db'));
        }
    }

    if ($action === 'cancel' && $transfer['status'] === 'draft') {
        Database::exec(
            "UPDATE stock_transfers SET status='cancelled', cancelled_by=?, cancelled_at=NOW() WHERE id=?",
            [Auth::id(), $id]
        );
        flash_success(_r('tr_cancelled'));
        redirect('/modules/transfers/');
    }

    redirect($_SERVER['REQUEST_URI']);
}

$transfer = Database::row(
    "SELECT t.*,
            wf.name AS from_wh_name,
            wt.name AS to_wh_name,
            uc.name AS created_by_name,
            up.name AS posted_by_name,
            ux.name AS cancelled_by_name
     FROM stock_transfers t
     JOIN warehouses wf ON wf.id = t.from_warehouse_id
     JOIN warehouses wt ON wt.id = t.to_warehouse_id
     JOIN users uc ON uc.id = t.created_by
     LEFT JOIN users up ON up.id = t.posted_by
     LEFT JOIN users ux ON ux.id = t.cancelled_by
     WHERE t.id = ?",
    [$id]
);
$items = $loadTransferItems($id);

$pageTitle   = __('tr_title') . ' ' . $transfer['doc_no'];
$breadcrumbs = [[__('tr_title'), url('modules/transfers/')], [$transfer['doc_no'], null]];

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
  <h1 class="page-heading"><?= e($transfer['doc_no']) ?></h1>
  <div class="page-actions">
    <?php if ($transfer['status'] === 'draft'): ?>
      <form method="POST" class="inline-action-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="post">
        <button type="submit" class="btn btn-primary" data-doc-confirm="transfer-post">
          <?= feather_icon('send', 14) ?> <?= __('tr_post') ?>
        </button>
      </form>
      <form method="POST" class="inline-action-form" onsubmit="return confirm('<?= __('tr_confirm_cancel') ?>')">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="cancel">
        <button type="submit" class="btn btn-ghost btn-danger-ghost">
          <?= feather_icon('x-circle', 14) ?> <?= __('tr_cancel') ?>
        </button>
      </form>
    <?php endif; ?>
    <a href="<?= url('modules/transfers/') ?>" class="btn btn-ghost">
      <?= feather_icon('arrow-left', 14) ?> <?= __('btn_back') ?>
    </a>
  </div>
</div>

<div class="mb-2">
  <?= transfer_status_badge($transfer['status'], 'lg') ?>
</div>

<div class="content-split content-split-sidebar">
  <div class="card">
    <div class="card-header">
      <span class="card-title"><?= __('tr_items') ?></span>
      <span class="text-muted fs-sm"><?= count($items) ?> <?= __('tr_items_count') ?></span>
    </div>
    <div class="table-wrap mobile-table-scroll desktop-only">
      <table class="table">
        <thead>
          <tr>
            <th><?= __('lbl_name') ?></th>
            <th><?= __('lbl_sku') ?></th>
            <th class="col-num"><?= __('lbl_qty') ?></th>
            <th><?= __('lbl_unit') ?></th>
            <th><?= __('tr_equivalent') ?></th>
            <?php if ($transfer['status'] === 'draft'): ?>
            <th class="col-num"><?= __('tr_available') ?></th>
            <?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php if (!$items): ?>
            <tr><td colspan="<?= $transfer['status'] === 'draft' ? 6 : 5 ?>" class="text-center text-muted table-empty-cell"><?= __('no_results') ?></td></tr>
          <?php else: ?>
            <?php foreach ($items as $item): ?>
            <?php
              $itemUnits = product_units((int)$item['product_id'], (string)$item['prod_unit']);
              $resolvedUnit = product_resolve_unit($itemUnits, (string)$item['prod_unit'], (string)$item['unit']);
              $storedUnitLabel = trim((string)($item['unit_label'] ?? ''));
              $unitLabel = ($storedUnitLabel !== '' && $storedUnitLabel !== (string)$item['unit'])
                ? $storedUnitLabel
                : product_unit_label_text($resolvedUnit);
              $qtyBase = (float)$item['qty_base'] > 0
                ? (float)$item['qty_base']
                : product_qty_to_base_unit((float)$item['qty'], $itemUnits, (string)$item['prod_unit'], (string)$resolvedUnit['unit_code']);
              $baseUnitRow = product_resolve_unit($itemUnits, (string)$item['prod_unit'], (string)$item['prod_unit']);
              $equivalentText = product_unit_qty_text($qtyBase, $baseUnitRow);
              $availableBase = get_stock_qty((int)$item['product_id'], (int)$transfer['from_warehouse_id']);
              $availableBreakdown = product_stock_breakdown($availableBase, $itemUnits, (string)$item['prod_unit']);
              $availableSelected = product_formatted_qty_in_unit($availableBase, $itemUnits, (string)$item['prod_unit'], (string)$resolvedUnit['unit_code']);
            ?>
            <tr>
              <td class="fw-600"><?= e(product_name($item)) ?></td>
              <td class="font-mono" style="font-size:12px"><?= e($item['sku']) ?></td>
              <td class="col-num fw-600"><?= fmtQty((float)$item['qty']) ?></td>
              <td><?= e($unitLabel) ?></td>
              <td class="text-muted" style="font-size:12px"><?= e($equivalentText) ?></td>
              <?php if ($transfer['status'] === 'draft'): ?>
              <td class="col-num text-muted" style="font-size:12px">
                <div style="font-family:monospace"><?= e($availableBreakdown) ?></div>
                <div style="margin-top:4px"><?= e($availableSelected) ?></div>
              </td>
              <?php endif; ?>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <div class="mobile-card-list mobile-only">
      <?php if (!$items): ?>
        <div class="mobile-record-card text-center text-muted"><?= __('no_results') ?></div>
      <?php else: ?>
        <?php foreach ($items as $item): ?>
        <?php
          $itemUnits = product_units((int)$item['product_id'], (string)$item['prod_unit']);
          $resolvedUnit = product_resolve_unit($itemUnits, (string)$item['prod_unit'], (string)$item['unit']);
          $storedUnitLabel = trim((string)($item['unit_label'] ?? ''));
          $unitLabel = ($storedUnitLabel !== '' && $storedUnitLabel !== (string)$item['unit'])
            ? $storedUnitLabel
            : product_unit_label_text($resolvedUnit);
          $qtyBase = (float)$item['qty_base'] > 0
            ? (float)$item['qty_base']
            : product_qty_to_base_unit((float)$item['qty'], $itemUnits, (string)$item['prod_unit'], (string)$resolvedUnit['unit_code']);
          $baseUnitRow = product_resolve_unit($itemUnits, (string)$item['prod_unit'], (string)$item['prod_unit']);
          $equivalentText = product_unit_qty_text($qtyBase, $baseUnitRow);
          $availableBase = get_stock_qty((int)$item['product_id'], (int)$transfer['from_warehouse_id']);
          $availableBreakdown = product_stock_breakdown($availableBase, $itemUnits, (string)$item['prod_unit']);
          $availableSelected = product_formatted_qty_in_unit($availableBase, $itemUnits, (string)$item['prod_unit'], (string)$resolvedUnit['unit_code']);
        ?>
        <div class="mobile-record-card">
          <div class="mobile-record-header">
            <div class="mobile-record-main">
              <div class="mobile-record-title"><?= e(product_name($item)) ?></div>
              <div class="mobile-record-subtitle font-mono"><?= e($item['sku']) ?></div>
            </div>
            <div class="fw-600"><?= fmtQty((float)$item['qty']) ?> <?= e($unitLabel) ?></div>
          </div>
          <div class="mobile-meta-grid">
            <div class="mobile-meta-row">
              <span class="mobile-meta-row-label"><?= __('tr_equivalent') ?></span>
              <span class="mobile-meta-row-value"><?= e($equivalentText) ?></span>
            </div>
            <?php if ($transfer['status'] === 'draft'): ?>
            <div class="mobile-meta-row">
              <span class="mobile-meta-row-label"><?= __('tr_available') ?></span>
              <span class="mobile-meta-row-value font-mono"><?= e($availableBreakdown) ?></span>
            </div>
            <div class="mobile-meta-row">
              <span class="mobile-meta-row-label"><?= __('tr_available_selected') ?></span>
              <span class="mobile-meta-row-value"><?= e($availableSelected) ?></span>
            </div>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><span class="card-title"><?= __('tr_doc_info') ?></span></div>
    <div class="card-body">
      <dl class="detail-definition">
        <dt class="text-muted"><?= __('tr_doc_no') ?></dt>
        <dd class="font-mono fw-600"><?= e($transfer['doc_no']) ?></dd>

        <dt class="text-muted"><?= __('lbl_date') ?></dt>
        <dd><?= date_fmt($transfer['doc_date'], 'd.m.Y') ?></dd>

        <dt class="text-muted"><?= __('tr_from_wh') ?></dt>
        <dd class="fw-600 text-primary"><?= e($transfer['from_wh_name']) ?></dd>

        <dt class="text-muted"><?= __('tr_to_wh') ?></dt>
        <dd class="fw-600 text-primary"><?= e($transfer['to_wh_name']) ?></dd>

        <dt class="text-muted"><?= __('tr_created_by') ?></dt>
        <dd><?= e($transfer['created_by_name']) ?></dd>

        <dt class="text-muted"><?= __('lbl_created') ?></dt>
        <dd><?= date_fmt($transfer['created_at']) ?></dd>

        <?php if ($transfer['posted_at']): ?>
        <dt class="text-muted"><?= __('tr_posted_by') ?></dt>
        <dd><?= e($transfer['posted_by_name']) ?></dd>
        <dt class="text-muted"><?= __('tr_posted_at') ?></dt>
        <dd><?= date_fmt($transfer['posted_at']) ?></dd>
        <?php endif; ?>

        <?php if ($transfer['cancelled_at']): ?>
        <dt class="text-muted"><?= __('tr_cancelled_by') ?></dt>
        <dd><?= e($transfer['cancelled_by_name']) ?></dd>
        <?php endif; ?>

        <?php if ($transfer['notes']): ?>
        <dt class="text-muted"><?= __('lbl_notes') ?></dt>
        <dd><?= e($transfer['notes']) ?></dd>
        <?php endif; ?>
      </dl>
    </div>
  </div>
</div>

<div class="qc-overlay" id="modal-transfer-confirm" role="dialog" aria-modal="true">
  <div class="qc-modal">
    <div class="qc-modal-header">
      <div class="qc-modal-title">
        <?= feather_icon('shield', 17) ?> <span><?= __('doc_confirm_post_title') ?></span>
      </div>
      <button type="button" class="qc-modal-close" id="transfer-confirm-close">×</button>
    </div>
    <div class="qc-modal-body">
      <p class="text-muted" style="margin:0 0 14px"><?= __('doc_confirm_summary_hint') ?></p>
      <div class="doc-confirm-grid">
        <div class="doc-confirm-card">
          <div class="doc-confirm-label"><?= __('tr_doc_no') ?></div>
          <div class="doc-confirm-value"><?= e($transfer['doc_no']) ?></div>
        </div>
        <div class="doc-confirm-card">
          <div class="doc-confirm-label"><?= __('lbl_date') ?></div>
          <div class="doc-confirm-value"><?= date_fmt($transfer['doc_date'], 'd.m.Y') ?></div>
        </div>
        <div class="doc-confirm-card">
          <div class="doc-confirm-label"><?= __('tr_from_wh') ?></div>
          <div class="doc-confirm-value"><?= e($transfer['from_wh_name']) ?></div>
        </div>
        <div class="doc-confirm-card">
          <div class="doc-confirm-label"><?= __('tr_to_wh') ?></div>
          <div class="doc-confirm-value"><?= e($transfer['to_wh_name']) ?></div>
        </div>
        <div class="doc-confirm-card">
          <div class="doc-confirm-label"><?= __('tr_items') ?></div>
          <div class="doc-confirm-value"><?= count($items) ?></div>
        </div>
        <div class="doc-confirm-card">
          <div class="doc-confirm-label"><?= __('tr_created_by') ?></div>
          <div class="doc-confirm-value"><?= e($transfer['created_by_name']) ?></div>
        </div>
      </div>
      <div class="doc-confirm-check">
        <input type="checkbox" id="transfer-confirm-checkbox">
        <label for="transfer-confirm-checkbox"><?= __('doc_confirm_post_checkbox') ?></label>
      </div>
    </div>
    <div class="qc-modal-footer">
      <button type="button" class="btn btn-primary" id="transfer-confirm-submit" disabled><?= __('btn_confirm') ?></button>
      <button type="button" class="btn btn-ghost" id="transfer-confirm-cancel"><?= __('btn_cancel') ?></button>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../../views/layouts/footer.php'; ?>
<script src="https://unpkg.com/feather-icons/dist/feather.min.js"></script>
<script>
feather.replace();

const transferConfirmModal = document.getElementById('modal-transfer-confirm');
const transferConfirmCheckbox = document.getElementById('transfer-confirm-checkbox');
const transferConfirmSubmit = document.getElementById('transfer-confirm-submit');
let pendingTransferSubmitter = null;

function openTransferConfirm(submitter) {
  pendingTransferSubmitter = submitter;
  if (transferConfirmCheckbox) transferConfirmCheckbox.checked = false;
  if (transferConfirmSubmit) transferConfirmSubmit.disabled = true;
  transferConfirmModal?.classList.add('open');
}

function closeTransferConfirm() {
  pendingTransferSubmitter = null;
  if (transferConfirmCheckbox) transferConfirmCheckbox.checked = false;
  if (transferConfirmSubmit) transferConfirmSubmit.disabled = true;
  transferConfirmModal?.classList.remove('open');
}

document.querySelectorAll('[data-doc-confirm="transfer-post"]').forEach((button) => {
  button.addEventListener('click', (event) => {
    event.preventDefault();
    openTransferConfirm(button);
  });
});

document.getElementById('transfer-confirm-close')?.addEventListener('click', closeTransferConfirm);
document.getElementById('transfer-confirm-cancel')?.addEventListener('click', closeTransferConfirm);
transferConfirmCheckbox?.addEventListener('change', () => {
  if (transferConfirmSubmit) {
    transferConfirmSubmit.disabled = !transferConfirmCheckbox.checked;
  }
});
transferConfirmSubmit?.addEventListener('click', () => {
  if (!pendingTransferSubmitter || !transferConfirmCheckbox?.checked) return;
  const submitter = pendingTransferSubmitter;
  closeTransferConfirm();
  submitter.form?.requestSubmit(submitter);
});
</script>
