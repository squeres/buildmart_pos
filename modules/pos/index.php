<?php
require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../views/partials/icons.php';
Auth::requireLogin();
Auth::requirePerm('pos');

// Active warehouse from global session selector
$activeWhId = pos_warehouse_id();
$activeWh   = Database::row("SELECT id, name FROM warehouses WHERE id=?", [$activeWhId]);

$pageTitle = __('pos_title');
$breadcrumbs = [[$pageTitle, null]];

// Check open shift
$requireShift = setting('shifts_required', '1') === '1';
$openShift = ShiftService::getOpenShiftForUser(Auth::id());
$openShiftSaleState = $openShift ? shift_can_sell_now($openShift) : ['ok' => true];
$openShiftExtensionState = $openShift ? shift_can_request_extension($openShift) : ['ok' => false];
$canOpenShift = Auth::can('shifts.open');
$canCloseShift = Auth::can('shifts.close');
$canRequestShiftExtension = Auth::can('shifts.extend');
$canSell = Auth::can('pos.sell');
$allowNegativeStock = allow_negative_stock();

if (!$canRequestShiftExtension) {
    $openShiftExtensionState = [
        'ok' => false,
        'message' => _r('auth_no_permission'),
        'request_options' => [],
        'remaining_minutes' => 0,
    ];
}

// Load categories for tabs
$categories = Database::all(
    "SELECT id, name_en, name_ru FROM categories WHERE is_active=1 ORDER BY sort_order,name_en"
);

// Products — stock from the active warehouse only
$products = Database::all(
    "SELECT p.id, p.name_en, p.name_ru, p.sku, p.sale_price, p.unit,
            COALESCE(sb.qty, 0) AS stock_qty,
            p.min_stock_qty, p.image, p.is_active
     FROM products p
     LEFT JOIN stock_balances sb ON sb.product_id = p.id AND sb.warehouse_id = ?
     WHERE p.is_active=1" . ($allowNegativeStock ? '' : ' AND COALESCE(sb.qty, 0) > 0') . "
     ORDER BY (COALESCE(sb.qty, 0) <= 0), p.name_en
     LIMIT 120",
    [$activeWhId]
);
$defaultPriceType = UISettings::defaultPriceType('pos');
$products = array_values(array_filter(array_map(function (array $product) use ($defaultPriceType) {
    $defaultUnit = product_default_unit((int)$product['id'], $product['unit']);
    $units = product_units((int)$product['id'], $product['unit']);
    $unitOverrides = product_unit_price_overrides((int)$product['id']);
    $product['sale_price'] = product_unit_price((int)$product['id'], $defaultUnit['unit_code'], $defaultPriceType, UISettings::effectivePrice((int)$product['id'], $defaultPriceType), $units, $unitOverrides);
    $product['display_unit_label'] = product_unit_label_text($defaultUnit);
    return $product['sale_price'] > 0 ? $product : null;
}, $products)));
$products = array_slice($products, 0, 60);

// Customers for selector
$customers = Database::all(
    "SELECT id, name, phone, email, company, inn, address, discount_pct, customer_type
     FROM customers
     ORDER BY name"
);

$currencySymbol = currency_symbol();
$taxRate        = (float)setting('default_tax_rate', 20);
$initialShiftExtensionOptions = json_encode(array_values($openShiftExtensionState['request_options'] ?? []), JSON_UNESCAPED_UNICODE);
$initialShiftExtensionRemaining = (int)($openShiftExtensionState['remaining_minutes'] ?? 0);
$shiftCloseUrl = ($openShift && $canCloseShift) ? url('modules/shifts/close.php?id=' . (int)$openShift['id']) : '';
$shiftExtensionUrl = 'modules/shifts/extension_request.php';

$extraJs = '
<script>
window.BASE_URL = "' . BASE_URL . '";
window.CURRENCY_SYMBOL = "' . e($currencySymbol) . '";
window.CSRF_TOKEN       = "' . csrf_token() . '";
window.POS_WAREHOUSE_ID   = ' . $activeWhId . ';
window.POS_WAREHOUSE_NAME = "' . addslashes(e($activeWh['name'] ?? '')) . '";
window.POS_SHIFT_REQUIRED = ' . ($requireShift ? 'true' : 'false') . ';
window.POS_HAS_OPEN_SHIFT = ' . ($openShift ? 'true' : 'false') . ';
window.POS_CAN_OPEN_SHIFT = ' . ($canOpenShift ? 'true' : 'false') . ';
window.POS_ACTIVE_SHIFT_ID = ' . (int)($openShift['id'] ?? 0) . ';
window.POS_SHIFT_EXTENSION_URL = "' . addslashes($shiftExtensionUrl) . '";
window.POS_SHIFT_CLOSE_URL = "' . addslashes($shiftCloseUrl) . '";
window.POS_SHIFT_EXTENSION_OPTIONS = ' . $initialShiftExtensionOptions . ';
window.POS_SHIFT_EXTENSION_REMAINING = ' . $initialShiftExtensionRemaining . ';
window.POS_CAN_REQUEST_SHIFT_EXTENSION = ' . ($canRequestShiftExtension && !empty($openShiftExtensionState['ok']) ? 'true' : 'false') . ';
window.POS_CAN_SELL = ' . ($canSell ? 'true' : 'false') . ';
window.POS_ALLOW_NEGATIVE_STOCK = ' . ($allowNegativeStock ? 'true' : 'false') . ';
window.LANG = {
  cart_empty:           "' . _r('pos_cart_empty') . '",
  out_of_stock:         "' . _r('out_of_stock') . '",
  negative_stock:       "' . _r('negative_stock') . '",
  low_stock:            "' . _r('low_stock') . '",
  err_validation:       "' . addslashes(_r('err_validation')) . '",
  prod_not_found:       "' . addslashes(_r('prod_not_found')) . '",
  insufficient_stock:   "' . addslashes(_r('pos_insufficient_stock')) . '",
  pos_insufficient_stock: "' . addslashes(_r('pos_insufficient_stock')) . '",
  sale_complete:        "' . _r('pos_sale_complete') . '",
  pos_sale_complete:    "' . addslashes(_r('pos_sale_complete')) . '",
  process_payment:      "' . _r('pos_process_payment') . '",
  no_results:           "' . _r('no_results') . '",
  loading:              "' . _r('loading') . '",
  pos_discount_item:    "' . addslashes(_r('pos_discount_item')) . '",
  pos_discount_receipt: "' . addslashes(_r('pos_discount_receipt')) . '",
  pos_discount_percent: "' . addslashes(_r('pos_discount_percent')) . '",
  pos_discount_amount:  "' . addslashes(_r('pos_discount_amount')) . '",
  pos_discount_clear:   "' . addslashes(_r('pos_discount_clear')) . '",
  pos_discount_apply_all: "' . addslashes(_r('pos_discount_apply_all')) . '",
  pos_discount_skip_discounted: "' . addslashes(_r('pos_discount_skip_discounted')) . '",
  pos_discount_conflict: "' . addslashes(_r('pos_discount_conflict')) . '",
  pos_discount_value:   "' . addslashes(_r('pos_discount_value')) . '",
  pos_discount_none:    "' . addslashes(_r('pos_discount_none')) . '",
  pos_add_unit_line:    "' . addslashes(_r('pos_add_unit_line')) . '",
  pos_unit_relations:   "' . addslashes(_r('pos_unit_relations')) . '",
  pos_remove_line:      "' . addslashes(_r('pos_remove_line')) . '",
  pos_remove_product:   "' . addslashes(_r('pos_remove_product')) . '",
  pos_unit_duplicate:   "' . addslashes(_r('pos_unit_duplicate')) . '",
  pos_all_units_added:  "' . addslashes(_r('pos_all_units_added')) . '",
  pos_no_shift:         "' . addslashes(_r('pos_no_shift')) . '",
  auth_no_permission:   "' . addslashes(_r('auth_no_permission')) . '",
  pos_legal_docs_ready: "' . addslashes(_r('pos_legal_docs_ready')) . '",
  pos_customer_legal:   "' . addslashes(_r('pos_customer_legal')) . '",
  shift_opened:         "' . addslashes(_r('shift_opened')) . '",
  shift_already_open:   "' . addslashes(_r('shift_already_open')) . '",
  shift_sales_extension_required: "' . addslashes(_r('shift_sales_extension_required')) . '",
  shift_extension_pending: "' . addslashes(_r('shift_extension_pending')) . '"
};
window.POS_PRODUCT_SEARCH_URL = "' . addslashes(url('modules/pos/search_products.php')) . '";
</script>
<script>
document.addEventListener("DOMContentLoaded", () => {
  const cameraBtn = document.getElementById("posCameraTrigger");
  const searchInput = document.getElementById("posSearch");
  if (!cameraBtn || !searchInput || !window.ProductCameraScanner) {
    return;
  }
  window.ProductCameraScanner.attach(cameraBtn, {
    onDetected: async (code) => {
      searchInput.value = code;
      searchInput.dispatchEvent(new Event("input", { bubbles: true }));
      try {
        const url = new URL(window.POS_PRODUCT_SEARCH_URL, window.location.origin);
        url.searchParams.set("q", code);
        const response = await fetch(url.toString(), { headers: { "X-Requested-With": "XMLHttpRequest" } });
        const payload = await response.json();
        const products = Array.isArray(payload?.products) ? payload.products : [];
        const exact = products.find((product) => (
          String(product.barcode || "").trim() === code
          || String(product.sku || "").trim().toLowerCase() === String(code).trim().toLowerCase()
        )) || (products.length === 1 ? products[0] : null);
        if (exact && window.POS?.addProduct) {
          await window.POS.addProduct(exact.id);
          searchInput.value = "";
          searchInput.dispatchEvent(new Event("input", { bubbles: true }));
        }
      } catch (_) {
        // Keep manual search flow active if exact auto-pick fails.
      }
    }
  });
});
</script>
<script src="https://unpkg.com/feather-icons/dist/feather.min.js"></script>';

include __DIR__ . '/../../views/layouts/header.php';
?>

<?php if ($requireShift && !$openShift): ?>
<div id="posShiftWarning" class="flash flash-warning" style="margin:0 0 12px">
  <?= feather_icon('alert-triangle', 16) ?>
  <span><?= __('pos_no_shift') ?></span>
  <?php if ($canOpenShift): ?>
  <button type="button" class="btn btn-sm btn-primary" style="margin-left:auto" onclick="POS.promptOpenShift()">
    <?= __('pos_open_shift') ?>
  </button>
  <?php endif; ?>
</div>
<?php elseif ($openShift && empty($openShiftSaleState['ok'])): ?>
<div id="posShiftWarning" class="flash flash-warning" style="margin:0 0 12px;gap:10px;align-items:center">
  <?= feather_icon('alert-triangle', 16) ?>
  <span><?= e($openShiftSaleState['message']) ?></span>
  <?php if (!empty($openShiftExtensionState['ok'])): ?>
  <button type="button" class="btn btn-sm btn-primary" style="margin-left:auto" onclick="POS.openShiftExtensionModal()">
    <?= __('shift_extension_request_button') ?>
  </button>
  <?php endif; ?>
  <?php if ($openShift): ?>
  <a href="<?= e($shiftCloseUrl) ?>" class="btn btn-sm btn-secondary">
    <?= __('shift_close') ?>
  </a>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="pos-layout">
  <!-- ── LEFT: product search + grid ─────────────────────────── -->
  <div class="pos-left">
    <!-- Active warehouse indicator -->
    <div class="pos-wh-indicator">
      <?= feather_icon('home', 13) ?>&nbsp;<?= e($activeWh['name'] ?? '') ?>
    </div>

    <!-- Search bar -->
    <div class="pos-search-bar">
      <input type="text" id="posSearch" class="form-control"
             placeholder="<?= __('pos_search_ph') ?>"
             autocomplete="off"
             autofocus>
      <button type="button"
              class="btn btn-secondary btn-icon product-camera-trigger"
              id="posCameraTrigger"
              title="<?= e(__('camera_scan_title')) ?>"
              hidden>
        <?= feather_icon('camera', 16) ?>
      </button>
      <button class="btn btn-secondary btn-icon" onclick="POS.init()">
        <?= feather_icon('search') ?>
      </button>
    </div>

    <!-- Category tabs -->
    <div class="category-tabs">
      <button class="cat-tab active" data-cat-id="" style="gap:0">
        <?= __('lbl_all') ?>
      </button>
      <?php foreach ($categories as $cat): ?>
        <button class="cat-tab" data-cat-id="<?= $cat['id'] ?>">
          <?= e(category_name($cat)) ?>
        </button>
      <?php endforeach; ?>
    </div>

    <!-- Products grid -->
    <div class="products-grid" id="productsGrid">
      <?php foreach ($products as $p): ?>
        <?php $pname = product_name($p); ?>
        <div class="product-card <?= $p['stock_qty'] <= 0 ? 'out-of-stock' : '' ?>"
             onclick="POS.addProduct(<?= $p['id'] ?>)"
             title="<?= e($pname) ?>">
          <div class="product-thumb">
            <?php if ($p['image']): ?>
              <img src="<?= e(UPLOAD_URL . $p['image']) ?>" alt="<?= e($pname) ?>" loading="lazy">
            <?php else: ?>
              <div class="product-thumb-placeholder"><?= feather_icon('package', 28) ?></div>
            <?php endif; ?>
          </div>
          <?php if ($p['stock_qty'] < 0): ?>
            <span class="badge badge-warning product-card-stock-badge"><?= __('negative_stock') ?></span>
          <?php elseif ($p['stock_qty'] == 0.0): ?>
            <span class="badge badge-danger product-card-stock-badge"><?= __('out_of_stock') ?></span>
          <?php elseif ($p['min_stock_qty'] > 0 && $p['stock_qty'] <= $p['min_stock_qty']): ?>
            <span class="badge badge-warning product-card-stock-badge"><?= __('low_stock') ?></span>
          <?php endif; ?>
          <div class="product-card-name"><?= e($pname) ?></div>
          <div class="product-card-sku"><?= e($p['sku']) ?></div>
          <div class="product-card-price"><?= money($p['sale_price']) ?> <span style="font-size:11px;color:var(--text-muted)"><?= e($p['display_unit_label']) ?></span></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- ── RIGHT: Cart ──────────────────────────────────────────── -->
  <div class="pos-right">
    <div class="cart-header">
      <span class="cart-title"><?= __('pos_cart') ?></span>
      <button class="btn btn-sm btn-ghost" onclick="if(confirm('<?= __('pos_clear_cart') ?>?')){POS._clearCart()}" title="<?= __('pos_clear_cart') ?>">
        <?= feather_icon('trash-2', 15) ?>
      </button>
    </div>

    <!-- Customer -->
    <div style="padding:10px 12px;border-bottom:1px solid var(--border-dim)">
      <select id="cartCustomer" class="form-control" style="font-size:13px">
        <option value="" data-discount="0" data-type="retail"><?= __('pos_walk_in') ?></option>
        <?php foreach ($customers as $cust): ?>
          <?php if ($cust['id'] == 1) continue; ?>
          <option
            value="<?= $cust['id'] ?>"
            data-discount="<?= e((string)$cust['discount_pct']) ?>"
            data-type="<?= e((string)$cust['customer_type']) ?>"
            data-company="<?= e((string)$cust['company']) ?>"
            data-inn="<?= e((string)$cust['inn']) ?>"
            data-address="<?= e((string)$cust['address']) ?>"
            data-email="<?= e((string)$cust['email']) ?>"
          >
            <?= e($cust['name']) ?>
            <?= $cust['phone'] ? '— ' . e($cust['phone']) : '' ?>
            <?= $cust['discount_pct'] > 0 ? "(-{$cust['discount_pct']}%)" : '' ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <!-- Cart items -->
    <div class="cart-items-wrap" id="cartItemsWrap">
      <div class="cart-empty" id="cartEmpty">
        <div class="cart-empty-icon"><?= feather_icon('shopping-cart', 48) ?></div>
        <div class="cart-empty-text"><?= __('pos_cart_empty') ?></div>
      </div>
    </div>

    <!-- Footer -->
    <div class="cart-footer">
      <!-- Discount -->
      <div class="cart-discount">
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;width:100%">
          <span style="font-size:12px;color:var(--text-muted);min-width:68px"><?= __('pos_discount_receipt') ?></span>
          <div class="view-switcher" style="gap:4px">
            <button type="button" class="btn btn-sm btn-ghost active" id="receiptDiscountPctBtn" onclick="POS.setReceiptDiscountType('percent')">%</button>
            <button type="button" class="btn btn-sm btn-ghost" id="receiptDiscountAmtBtn" onclick="POS.setReceiptDiscountType('amount')"><?= e($currencySymbol) ?></button>
          </div>
          <input type="number" id="cartDiscountValue" class="form-control form-control mono"
                 min="0" step="0.01" value="0" style="width:96px">
          <button type="button" class="btn btn-sm btn-ghost" onclick="POS.clearReceiptDiscount()">
            <?= feather_icon('x', 13) ?>
          </button>
        </div>
      </div>

      <!-- Totals -->
      <div class="cart-totals">
        <div class="totals-row">
          <span><?= __('lbl_subtotal') ?></span>
          <span class="mono" id="subtotalAmt">0,00 <?= e($currencySymbol) ?></span>
        </div>
        <div class="totals-row">
          <span><?= __('lbl_discount') ?></span>
          <span class="mono" id="discountAmt">—</span>
        </div>
        <div class="totals-row">
          <span><?= __('lbl_tax') ?></span>
          <span class="mono" id="taxAmt">0,00 <?= e($currencySymbol) ?></span>
        </div>
        <div class="totals-row grand">
          <span><?= __('lbl_total') ?></span>
          <span class="mono" id="totalAmt">0,00 <?= e($currencySymbol) ?></span>
        </div>
      </div>

      <!-- Checkout button -->
      <button id="checkoutBtn" class="btn btn-primary btn-block btn-xl"
              onclick="POS.openCheckout()" <?= $canSell ? 'disabled' : 'disabled title="' . e(__('auth_no_permission')) . '"' ?>>
        <?= feather_icon('credit-card', 19) ?>
        <?= __('pos_checkout') ?>
      </button>
      <?php if (!$canSell): ?>
        <div class="form-hint" style="margin-top:10px;text-align:center"><?= __('auth_no_permission') ?></div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ── Payment Modal ────────────────────────────────────────────── -->
<div class="modal-overlay hidden" id="paymentModal">
  <div class="modal">
    <div class="modal-header">
      <span class="modal-title"><?= __('pos_payment') ?></span>
      <button class="modal-close" onclick="closeModal('paymentModal')"><?= feather_icon('x', 18) ?></button>
    </div>
    <div class="modal-body">
      <!-- Grand total -->
      <div style="text-align:center;margin-bottom:22px">
        <div style="font-size:12px;text-transform:uppercase;letter-spacing:.08em;color:var(--text-muted);margin-bottom:6px"><?= __('lbl_total') ?></div>
        <div style="font-size:36px;font-weight:700;font-family:var(--font-mono);color:var(--amber)" id="payTotalDisplay">—</div>
      </div>

      <!-- Payment method buttons -->
      <div class="payment-methods">
        <button class="pay-method-btn active" data-method="cash">
          <?= feather_icon('dollar-sign', 22) ?>
          <?= __('pos_pay_cash') ?>
        </button>
        <button class="pay-method-btn" data-method="card">
          <?= feather_icon('credit-card', 22) ?>
          <?= __('pos_pay_card') ?>
        </button>
        <button class="pay-method-btn" data-method="mixed">
          <?= feather_icon('layers', 22) ?>
          <?= __('pos_pay_mixed') ?>
        </button>
      </div>

      <!-- Cash fields -->
      <div id="cashFields">
        <div id="cashGivenRow" class="form-group">
          <label class="form-label"><?= __('pos_cash_given') ?></label>
          <input type="number" id="cashGiven" class="form-control form-control mono"
                 placeholder="0.00" step="0.01" min="0"
                 style="font-size:20px;padding:12px;text-align:right">
        </div>
        <div id="cardAmountRow" class="form-group d-none">
          <label class="form-label"><?= __('pos_pay_card') ?></label>
          <input type="number" id="cardAmount" class="form-control form-control mono"
                 placeholder="0.00" step="0.01" min="0"
                 style="font-size:16px;padding:10px;text-align:right">
        </div>
        <div class="flex-between" style="padding:8px 0;border-top:1px solid var(--border-dim);margin-top:8px">
          <span style="font-size:14px;font-weight:600"><?= __('pos_change') ?></span>
          <span id="changeDisplay" class="font-mono" style="font-size:20px;font-weight:700">—</span>
        </div>
      </div>

      <!-- Notes -->
      <div class="form-group mt-2">
        <label class="form-label"><?= __('lbl_notes') ?> (<?= __('lbl_optional') ?>)</label>
        <input type="text" id="saleNotes" class="form-control" placeholder="">
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" onclick="closeModal('paymentModal')"><?= __('btn_cancel') ?></button>
      <button id="processPayBtn" class="btn btn-success btn-lg" onclick="POS.processPayment()" <?= $canSell ? '' : 'disabled' ?>>
        <?= feather_icon('check-circle') ?>
        <?= __('pos_process_payment') ?>
      </button>
    </div>
  </div>
</div>

<div class="modal-overlay hidden" id="itemDiscountModal">
  <div class="modal modal-sm">
    <div class="modal-header">
      <span class="modal-title"><?= __('pos_discount_item') ?></span>
      <button class="modal-close" onclick="closeModal('itemDiscountModal')"><?= feather_icon('x', 18) ?></button>
    </div>
    <div class="modal-body">
      <div class="form-group mb-2">
        <label class="form-label"><?= __('lbl_name') ?></label>
        <input type="text" id="itemDiscountProductName" class="form-control" readonly>
      </div>
      <div class="form-group mb-2">
        <label class="form-label"><?= __('lbl_discount') ?></label>
        <div class="view-switcher" style="gap:6px;justify-content:flex-start">
          <button type="button" class="btn btn-sm btn-ghost active" id="itemDiscountPctBtn" onclick="POS.setItemDiscountType('percent')">%</button>
          <button type="button" class="btn btn-sm btn-ghost" id="itemDiscountAmtBtn" onclick="POS.setItemDiscountType('amount')"><?= e($currencySymbol) ?></button>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label"><?= __('pos_discount_value') ?></label>
        <input type="number" id="itemDiscountValue" class="form-control mono" min="0" step="0.01" value="0">
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" onclick="POS.clearItemDiscount()"><?= __('pos_discount_clear') ?></button>
      <button type="button" class="btn btn-primary" onclick="POS.saveItemDiscount()"><?= __('btn_apply') ?></button>
    </div>
  </div>
</div>

<div class="modal-overlay hidden" id="shiftRequiredModal">
  <div class="modal modal-sm">
    <div class="modal-header">
      <span class="modal-title"><?= __('pos_open_shift') ?></span>
      <button class="modal-close" onclick="POS.cancelShiftRequired()"><?= feather_icon('x', 18) ?></button>
    </div>
    <div class="modal-body">
      <div style="font-size:15px;font-weight:700;margin-bottom:8px"><?= __('pos_no_shift') ?></div>
      <div style="font-size:13px;line-height:1.5;color:var(--text-secondary)">
        <?= __('pos_shift_required_hint') ?>
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" onclick="POS.cancelShiftRequired()"><?= __('btn_cancel') ?></button>
      <?php if ($canOpenShift): ?>
      <button type="button" class="btn btn-primary" onclick="POS.openShiftModal()">
        <?= feather_icon('play', 16) ?> <?= __('pos_open_shift') ?>
      </button>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if ($canOpenShift): ?>
<div class="modal-overlay hidden" id="shiftOpenModal">
  <div class="modal modal-sm">
    <div class="modal-header">
      <span class="modal-title"><?= __('shift_open') ?></span>
      <button class="modal-close" onclick="POS.closeShiftOpenModal()"><?= feather_icon('x', 18) ?></button>
    </div>
    <div class="modal-body">
      <div class="form-group">
        <label class="form-label"><?= __('shift_opening_cash') ?></label>
        <input type="number" id="shiftOpeningCash" class="form-control mono" value="0" min="0" step="0.01" style="font-size:20px;padding:12px;text-align:right">
      </div>
      <div class="form-group">
        <label class="form-label"><?= __('lbl_notes') ?> (<?= __('lbl_optional') ?>)</label>
        <input type="text" id="shiftNotes" class="form-control" placeholder="">
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" onclick="POS.closeShiftOpenModal()"><?= __('btn_cancel') ?></button>
      <button type="button" class="btn btn-primary" id="shiftOpenBtn" onclick="POS.submitShiftOpen()">
        <?= feather_icon('play', 16) ?> <?= __('shift_open') ?>
      </button>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="modal-overlay hidden" id="shiftExtensionModal">
  <div class="modal modal-sm">
    <div class="modal-header">
      <span class="modal-title"><?= __('shift_extension_request_button') ?></span>
      <button class="modal-close" onclick="POS.closeShiftExtensionModal()"><?= feather_icon('x', 18) ?></button>
    </div>
    <div class="modal-body">
      <div id="shiftExtensionMessage" style="font-size:13px;line-height:1.55;color:var(--text-secondary);margin-bottom:16px">
        <?= __('shift_sales_extension_required') ?>
      </div>
      <div class="form-group">
        <label class="form-label"><?= __('shift_extension_requested_minutes') ?></label>
        <div id="shiftExtensionPresetButtons" style="display:flex;gap:8px;flex-wrap:wrap"></div>
      </div>
      <div class="form-group">
        <label class="form-label"><?= __('shift_extension_custom_minutes') ?></label>
        <input type="number" id="shiftExtensionCustomMinutes" class="form-control mono" min="1" step="1" placeholder="<?= __('shift_extension_custom_minutes') ?>">
      </div>
      <div class="form-group">
        <label class="form-label"><?= __('shift_extension_reason') ?> (<?= __('lbl_optional') ?>)</label>
        <textarea id="shiftExtensionReason" class="form-control" rows="3" placeholder="<?= __('shift_extension_reason') ?>"></textarea>
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" onclick="POS.closeShiftExtensionModal()"><?= __('btn_cancel') ?></button>
      <button type="button" class="btn btn-primary" id="shiftExtensionSubmitBtn" onclick="POS.submitShiftExtensionRequest()">
        <?= feather_icon('send', 16) ?> <?= __('shift_extension_send_button') ?>
      </button>
    </div>
  </div>
</div>

<?php if (false): ?>
<div class="modal-overlay hidden" id="legalSaleModal">
  <div class="modal modal-sm">
    <div class="modal-header">
      <span class="modal-title"><?= __('legal_sale_completed') ?></span>
      <button class="modal-close" onclick="POS.closeLegalSaleModal()"><?= feather_icon('x', 18) ?></button>
    </div>
    <div class="modal-body">
      <div class="text-secondary" style="font-size:13px;line-height:1.5;margin-bottom:12px" id="legalSaleModalHint">
        <?= __('legal_sale_completed_hint') ?>
      </div>
      <div class="card" style="border-style:dashed">
        <div class="card-body" style="display:grid;gap:8px;padding:14px">
          <div class="flex-between" style="font-size:13px">
            <span class="text-muted"><?= __('pos_receipt_no') ?></span>
            <span class="fw-600 font-mono" id="legalSaleReceiptNo">—</span>
          </div>
          <div class="flex-between" style="font-size:13px">
            <span class="text-muted"><?= __('cust_title') ?></span>
            <span class="fw-600" id="legalSaleCustomerName">—</span>
          </div>
          <div class="flex-between" style="font-size:13px">
            <span class="text-muted"><?= __('cust_company') ?></span>
            <span class="fw-600" id="legalSaleCustomerCompany">—</span>
          </div>
        </div>
      </div>
    </div>
    <div class="modal-footer" style="justify-content:space-between">
      <button type="button" class="btn btn-secondary" onclick="POS.closeLegalSaleModal()"><?= __('btn_close') ?></button>
      <div style="display:flex;gap:8px">
        <button type="button" class="btn btn-ghost" onclick="POS.openLegalInvoice()" id="legalInvoiceBtn">
          <?= feather_icon('file-text', 16) ?> <?= __('btn_invoice') ?>
        </button>
        <button type="button" class="btn btn-primary" onclick="POS.openLegalReceipt()" id="legalReceiptBtn">
          <?= feather_icon('printer', 16) ?> <?= __('btn_fiscal_receipt') ?>
        </button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Sidebar overlay for mobile -->
<div id="sidebarOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:99"
     onclick="document.getElementById('sidebar').classList.remove('open');this.style.display='none'"></div>

<?php include __DIR__ . '/../../views/layouts/footer.php'; ?>
<script>
// Expose internal helper for inline onclick
POS._clearCart = function() { POS.clearCart(); };
</script>
