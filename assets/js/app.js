(() => {
  'use strict';

  const $ = (selector, root = document) => root.querySelector(selector);
  const $$ = (selector, root = document) => Array.from(root.querySelectorAll(selector));

  function lang(key, fallback = '') {
    const strings = window.LANG || {};
    return Object.prototype.hasOwnProperty.call(strings, key) ? strings[key] : fallback;
  }

  function buildUrl(path) {
    const base = String(window.BASE_URL || '/');
    if (/^https?:\/\//i.test(base)) {
      return `${base.replace(/\/+$/, '')}/${String(path || '').replace(/^\/+/, '')}`;
    }
    return `${base.replace(/\/+$/, '/')}${String(path || '').replace(/^\/+/, '')}`;
  }

  function parseNumber(value) {
    if (typeof value === 'number') {
      return Number.isFinite(value) ? value : 0;
    }
    const normalized = String(value ?? '')
      .trim()
      .replace(/\s+/g, '')
      .replace(',', '.');
    const parsed = parseFloat(normalized);
    return Number.isFinite(parsed) ? parsed : 0;
  }

  function roundTo(value, precision = 2) {
    const factor = 10 ** precision;
    return Math.round((Number(value) || 0) * factor) / factor;
  }

  function roundMoney(value) {
    return roundTo(value, 2);
  }

  function roundQty(value) {
    return roundTo(value, 3);
  }

  function formatNumber(value, minDigits = 0, maxDigits = 2) {
    return new Intl.NumberFormat(document.documentElement.lang === 'ru' ? 'ru-RU' : undefined, {
      minimumFractionDigits: minDigits,
      maximumFractionDigits: maxDigits,
    }).format(Number(value) || 0);
  }

  function fmtMoney(value) {
    return `${formatNumber(value, 2, 2)} ${window.CURRENCY_SYMBOL || ''}`.trim();
  }

  function fmtQty(value, fractional = false) {
    return formatNumber(value, 0, fractional ? 3 : 0);
  }

  function escHtml(value) {
    return String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function makeId(prefix = 'id') {
    return `${prefix}_${Date.now().toString(36)}_${Math.random().toString(36).slice(2, 8)}`;
  }

  function featherSvg(name, size = 16) {
    if (window.feather && feather.icons && feather.icons[name]) {
      return feather.icons[name].toSvg({ width: size, height: size });
    }
    return '';
  }

  function openModal(id) {
    const modal = typeof id === 'string' ? document.getElementById(id) : id;
    if (!modal) {
      return;
    }
    modal.classList.remove('hidden');
    document.body.classList.add('modal-open');
  }

  function closeModal(id) {
    const modal = typeof id === 'string' ? document.getElementById(id) : id;
    if (!modal) {
      return;
    }
    modal.classList.add('hidden');
    if (!$$('.modal-overlay:not(.hidden)').length) {
      document.body.classList.remove('modal-open');
    }
  }

  function ensureToastHost() {
    let host = document.getElementById('toastHost');
    if (!host) {
      host = document.createElement('div');
      host.id = 'toastHost';
      host.className = 'toast-host';
      document.body.appendChild(host);
    }
    return host;
  }

  function showToast(message, type = 'info') {
    if (!message) {
      return;
    }
    const host = ensureToastHost();
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.textContent = message;
    host.appendChild(toast);
    requestAnimationFrame(() => toast.classList.add('is-visible'));
    window.setTimeout(() => {
      toast.classList.remove('is-visible');
      window.setTimeout(() => toast.remove(), 180);
    }, 2800);
  }

  window.openModal = openModal;
  window.closeModal = closeModal;
  window.showToast = showToast;

  function initSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const menuBtn = $('.topbar-menu-btn');
    if (!sidebar || !overlay || !menuBtn) {
      return;
    }
    menuBtn.addEventListener('click', () => {
      sidebar.classList.toggle('open');
      overlay.style.display = sidebar.classList.contains('open') ? 'block' : 'none';
    });
  }

  function initFlashes() {
    $$('.flash-close').forEach((button) => {
      button.addEventListener('click', () => {
        const flash = button.closest('.flash');
        if (flash) {
          flash.remove();
        }
      });
    });
  }

  function initDataConfirm() {
    document.addEventListener('click', (event) => {
      const target = event.target.closest('[data-confirm]');
      if (!target) {
        return;
      }
      const message = target.getAttribute('data-confirm');
      if (message && !window.confirm(message)) {
        event.preventDefault();
        event.stopPropagation();
      }
    });
  }

  async function fetchJson(url, options = {}) {
    const response = await fetch(url, {
      credentials: 'same-origin',
      ...options,
    });
    const text = await response.text();
    let data = null;
    try {
      data = text ? JSON.parse(text) : null;
    } catch (error) {
      throw new Error(text || 'Invalid server response');
    }
    if (!response.ok) {
      const requestError = new Error(data?.message || `HTTP ${response.status}`);
      requestError.response = data;
      requestError.status = response.status;
      throw requestError;
    }
    if (data && typeof data === 'object' && data.success === false) {
      const requestError = new Error(data?.message || lang('err_validation', 'Error'));
      requestError.response = data;
      requestError.status = response.status;
      throw requestError;
    }
    return data;
  }

  const POS = (() => {
    let cart = [];
    let productCache = new Map();
    let activeCategoryId = '';
    let searchTimer = null;
    let receiptDiscountType = 'percent';
    let applyReceiptToDiscountedItems = null;
    let paymentMethod = 'cash';
    let activeDiscountLine = null;
    let hasOpenShift = !!window.POS_HAS_OPEN_SHIFT;
    let resumeCheckoutAfterShiftOpen = false;
    let legalSaleState = null;
    let shiftExtensionState = {
      message: lang('shift_sales_extension_required', 'Work hours are over. Request a shift extension to continue sales.'),
      requestOptions: Array.isArray(window.POS_SHIFT_EXTENSION_OPTIONS) ? window.POS_SHIFT_EXTENSION_OPTIONS.slice() : [],
      remainingMinutes: parseInt(window.POS_SHIFT_EXTENSION_REMAINING || 0, 10) || 0,
      closeUrl: String(window.POS_SHIFT_CLOSE_URL || ''),
    };

    const dom = {};

    function cacheDom() {
      dom.productsGrid = document.getElementById('productsGrid');
      dom.searchInput = document.getElementById('posSearch');
      dom.cartWrap = document.getElementById('cartItemsWrap');
      dom.cartCustomer = document.getElementById('cartCustomer');
      dom.cartCustomerMeta = document.getElementById('cartCustomerMeta');
      dom.cartCustomerTypeBadge = document.getElementById('cartCustomerTypeBadge');
      dom.cartCustomerMetaHint = document.getElementById('cartCustomerMetaHint');
      dom.cartDiscountValue = document.getElementById('cartDiscountValue');
      dom.receiptDiscountPctBtn = document.getElementById('receiptDiscountPctBtn');
      dom.receiptDiscountAmtBtn = document.getElementById('receiptDiscountAmtBtn');
      dom.subtotalAmt = document.getElementById('subtotalAmt');
      dom.discountAmt = document.getElementById('discountAmt');
      dom.taxAmt = document.getElementById('taxAmt');
      dom.totalAmt = document.getElementById('totalAmt');
      dom.checkoutBtn = document.getElementById('checkoutBtn');

      dom.paymentModal = document.getElementById('paymentModal');
      dom.payTotalDisplay = document.getElementById('payTotalDisplay');
      dom.cashGiven = document.getElementById('cashGiven');
      dom.cardAmount = document.getElementById('cardAmount');
      dom.changeDisplay = document.getElementById('changeDisplay');
      dom.cashGivenRow = document.getElementById('cashGivenRow');
      dom.cardAmountRow = document.getElementById('cardAmountRow');
      dom.saleNotes = document.getElementById('saleNotes');
      dom.processPayBtn = document.getElementById('processPayBtn');
      dom.shiftWarning = document.getElementById('posShiftWarning');
      dom.shiftRequiredModal = document.getElementById('shiftRequiredModal');
      dom.shiftOpenModal = document.getElementById('shiftOpenModal');
      dom.shiftOpeningCash = document.getElementById('shiftOpeningCash');
      dom.shiftNotes = document.getElementById('shiftNotes');
      dom.shiftOpenBtn = document.getElementById('shiftOpenBtn');
      dom.shiftExtensionModal = document.getElementById('shiftExtensionModal');
      dom.shiftExtensionMessage = document.getElementById('shiftExtensionMessage');
      dom.shiftExtensionPresetButtons = document.getElementById('shiftExtensionPresetButtons');
      dom.shiftExtensionCustomMinutes = document.getElementById('shiftExtensionCustomMinutes');
      dom.shiftExtensionReason = document.getElementById('shiftExtensionReason');
      dom.shiftExtensionSubmitBtn = document.getElementById('shiftExtensionSubmitBtn');
      dom.legalSaleModal = document.getElementById('legalSaleModal');
      dom.legalSaleReceiptNo = document.getElementById('legalSaleReceiptNo');
      dom.legalSaleCustomerName = document.getElementById('legalSaleCustomerName');
      dom.legalSaleCustomerCompany = document.getElementById('legalSaleCustomerCompany');
      dom.legalInvoiceBtn = document.getElementById('legalInvoiceBtn');
      dom.legalReceiptBtn = document.getElementById('legalReceiptBtn');

      dom.itemDiscountModal = document.getElementById('itemDiscountModal');
      dom.itemDiscountProductName = document.getElementById('itemDiscountProductName');
      dom.itemDiscountValue = document.getElementById('itemDiscountValue');
      dom.itemDiscountPctBtn = document.getElementById('itemDiscountPctBtn');
      dom.itemDiscountAmtBtn = document.getElementById('itemDiscountAmtBtn');
    }

    function getSelectedCustomerOption() {
      if (!dom.cartCustomer) {
        return null;
      }
      return dom.cartCustomer.options[dom.cartCustomer.selectedIndex] || null;
    }

    function getSelectedCustomerData() {
      const option = getSelectedCustomerOption();
      return {
        id: parseInt(option?.value || '1', 10) || 1,
        type: String(option?.dataset?.type || 'retail') === 'legal' ? 'legal' : 'retail',
        name: String(option?.textContent || '').trim(),
        company: String(option?.dataset?.company || '').trim(),
        inn: String(option?.dataset?.inn || '').trim(),
      };
    }

    function updateCustomerMeta() {
      if (!dom.cartCustomerMeta || !dom.cartCustomerTypeBadge || !dom.cartCustomerMetaHint) {
        return;
      }
      const customer = getSelectedCustomerData();
      if (customer.type !== 'legal') {
        dom.cartCustomerMeta.style.display = 'none';
        dom.cartCustomerTypeBadge.textContent = '';
        dom.cartCustomerMetaHint.textContent = '';
        return;
      }

      const parts = [lang('pos_legal_docs_ready', 'A fiscal receipt and delivery note will be available after the sale.')];
      if (customer.company) {
        parts.push(customer.company);
      }
      if (customer.inn) {
        parts.push(customer.inn);
      }

      dom.cartCustomerMeta.style.display = '';
      dom.cartCustomerTypeBadge.textContent = lang('pos_customer_legal', 'Legal client');
      dom.cartCustomerMetaHint.textContent = parts.join(' • ');
    }

    function normalizeUnits(product) {
      const units = [];
      const seen = new Set();
      const rawUnits = Array.isArray(product.units) ? product.units : [];
      rawUnits.forEach((rawUnit, index) => {
        const code = String(rawUnit.code || rawUnit.unit_code || '').trim();
        if (!code || seen.has(code)) {
          return;
        }
        seen.add(code);
        units.push({
          code,
          label: String(rawUnit.label || rawUnit.unit_label || code),
          ratio_to_base: Math.max(0.000001, parseNumber(rawUnit.ratio_to_base) || 1),
          price: roundMoney(parseNumber(rawUnit.price)),
          stock_qty: parseNumber(rawUnit.stock_qty),
          allow_fractional: !!rawUnit.allow_fractional,
          is_default: !!rawUnit.is_default,
          sort_order: index,
        });
      });

      if (!units.length) {
        const fallbackCode = String(product.unit || product.base_unit || 'pcs');
        units.push({
          code: fallbackCode,
          label: String(product.unit_label || product.base_unit_label || fallbackCode),
          ratio_to_base: 1,
          price: roundMoney(parseNumber(product.sale_price)),
          stock_qty: parseNumber(product.stock_qty),
          allow_fractional: !!product.is_weighable,
          is_default: true,
          sort_order: 0,
        });
      }

      return units;
    }

    function getDefaultUnit(units, preferredCode) {
      return (
        units.find((unit) => unit.code === preferredCode) ||
        units.find((unit) => unit.is_default) ||
        units[0]
      );
    }

    function getUnitMinQty(unit) {
      return unit && unit.allow_fractional ? 0.001 : 1;
    }

    function getUnitMaxQtyFromBase(stockBaseQty, unit) {
      const maxQty = Math.max(0, parseNumber(stockBaseQty)) * Math.max(0.000001, parseNumber(unit?.ratio_to_base) || 1);
      if (unit?.allow_fractional) {
        return roundQty(maxQty);
      }
      return Math.max(0, Math.floor(maxQty + 0.000001));
    }

    function getPreferredAvailableUnit(units, preferredCode, stockBaseQty) {
      const preferredUnit = getDefaultUnit(units, preferredCode);
      if (preferredUnit && getUnitMaxQtyFromBase(stockBaseQty, preferredUnit) >= getUnitMinQty(preferredUnit)) {
        return preferredUnit;
      }
      return units.find((unit) => getUnitMaxQtyFromBase(stockBaseQty, unit) >= getUnitMinQty(unit)) || preferredUnit || null;
    }

    function getUnit(group, unitCode) {
      return group.units.find((unit) => unit.code === unitCode) || null;
    }

    function createLineFromUnit(unit, qty = 1) {
      const minQty = getUnitMinQty(unit);
      const safeQty = unit.allow_fractional ? Math.max(minQty, roundQty(qty)) : Math.max(1, Math.round(qty));
      return {
        key: makeId('line'),
        unit: unit.code,
        unit_label: unit.label,
        qty: safeQty,
        unit_price: roundMoney(unit.price),
        allow_fractional: !!unit.allow_fractional,
        unit_ratio: Math.max(0.000001, parseNumber(unit.ratio_to_base) || 1),
        line_discount_type: 'none',
        line_discount_value: 0,
      };
    }

    function buildGroup(product) {
      const units = normalizeUnits(product);
      const defaultUnit = getDefaultUnit(units, product.default_unit || product.unit);
      const initialUnit = getPreferredAvailableUnit(units, defaultUnit?.code || product.default_unit || product.unit, parseNumber(product.stock_qty));
      return {
        id: Number(product.id),
        name: String(product.name || ''),
        sku: String(product.sku || ''),
        base_unit: String(product.base_unit || product.unit || defaultUnit.code),
        base_unit_label: String(product.base_unit_label || defaultUnit.label || defaultUnit.code),
        stock_base_qty: parseNumber(product.stock_qty),
        stock_display: String(product.stock_display || ''),
        tax_rate: parseNumber(product.tax_rate),
        allow_discount: !!product.allow_discount,
        default_unit: defaultUnit.code,
        units,
        lines: initialUnit ? [createLineFromUnit(initialUnit)] : [],
      };
    }

    function findGroup(productId) {
      return cart.find((group) => Number(group.id) === Number(productId)) || null;
    }

    function findGroupIndex(productId) {
      return cart.findIndex((group) => Number(group.id) === Number(productId));
    }

    function findLine(group, lineKey) {
      return group?.lines.find((line) => line.key === lineKey) || null;
    }

    function getLineCompositeKey(productId, lineKey) {
      return `${productId}:${lineKey}`;
    }

    function qtyToBase(qty, ratioToBase) {
      return roundTo(parseNumber(qty) / Math.max(0.000001, parseNumber(ratioToBase) || 1), 6);
    }

    function getUsedBaseQty(group, exceptLineKey = null) {
      return roundTo(group.lines.reduce((sum, line) => {
        if (line.key === exceptLineKey) {
          return sum;
        }
        return sum + qtyToBase(line.qty, line.unit_ratio);
      }, 0), 6);
    }

    function getMaxQtyForLine(group, unit, exceptLineKey = null) {
      const remainingBaseQty = Math.max(0, parseNumber(group.stock_base_qty) - getUsedBaseQty(group, exceptLineKey));
      const maxQty = remainingBaseQty * Math.max(0.000001, parseNumber(unit.ratio_to_base) || 1);
      if (unit.allow_fractional) {
        return roundQty(maxQty);
      }
      return Math.max(0, Math.floor(maxQty + 0.000001));
    }

    function lineMinQty(line) {
      return getUnitMinQty(line);
    }

    function nextQtyDelta() {
      return 1;
    }

    function showInsufficientStock(group) {
      const template =
        lang('pos_insufficient_stock') ||
        lang('insufficient_stock') ||
        'Недостаточно остатка по товару «:product». Доступно: :available';
      showToast(
        template
          .replace(':product', group.name)
          .replace(':available', group.stock_display || fmtQty(group.stock_base_qty, true)),
        'error'
      );
    }

    function syncLineFromUnit(line, unit) {
      line.unit = unit.code;
      line.unit_label = unit.label;
      line.allow_fractional = !!unit.allow_fractional;
      line.unit_ratio = Math.max(0.000001, parseNumber(unit.ratio_to_base) || 1);
      line.unit_price = roundMoney(unit.price);
    }

    function syncGroupFromProduct(group, product) {
      const fresh = buildGroup(product);
      group.name = fresh.name;
      group.sku = fresh.sku;
      group.base_unit = fresh.base_unit;
      group.base_unit_label = fresh.base_unit_label;
      group.stock_base_qty = fresh.stock_base_qty;
      group.stock_display = fresh.stock_display;
      group.tax_rate = fresh.tax_rate;
      group.allow_discount = fresh.allow_discount;
      group.default_unit = fresh.default_unit;
      group.units = fresh.units;

      group.lines = group.lines
        .map((line) => {
          const unit = getUnit(group, line.unit) || getDefaultUnit(group.units, group.default_unit);
          if (!unit) {
            return null;
          }
          syncLineFromUnit(line, unit);
          const maxQty = getMaxQtyForLine(group, unit, line.key);
          const minQty = lineMinQty(line);
          if (maxQty <= 0) {
            return null;
          }
          if (line.allow_fractional) {
            line.qty = Math.min(Math.max(minQty, roundQty(line.qty)), maxQty);
          } else {
            line.qty = Math.min(Math.max(1, Math.round(line.qty)), maxQty);
          }
          return line;
        })
        .filter(Boolean);

      if (!group.lines.length) {
        const fallbackUnit = getPreferredAvailableUnit(group.units, group.default_unit, group.stock_base_qty);
        if (fallbackUnit) {
          group.lines = [createLineFromUnit(fallbackUnit)];
        }
      }
    }

    function getUnusedUnits(group, exceptLineKey = null) {
      const used = new Set(
        group.lines
          .filter((line) => line.key !== exceptLineKey)
          .map((line) => line.unit)
      );
      return group.units.filter((unit) => !used.has(unit.code));
    }

    function getAddableUnits(group, exceptLineKey = null) {
      return getUnusedUnits(group, exceptLineKey)
        .filter((unit) => getMaxQtyForLine(group, unit, exceptLineKey) > 0);
    }

    function getPreferredAddableUnit(group, preferredCode, exceptLineKey = null) {
      const addableUnits = getAddableUnits(group, exceptLineKey);
      if (!addableUnits.length) {
        return null;
      }
      return addableUnits.find((unit) => unit.code === preferredCode) || addableUnits[0];
    }

    function canAddAnotherUnit(group) {
      return group.units.length > 1 && getAddableUnits(group).length > 0;
    }

    function resetReceiptDiscountConflict() {
      applyReceiptToDiscountedItems = null;
    }
    async function fetchProduct(productId) {
      const data = await fetchJson(buildUrl(`modules/pos/get_product.php?id=${encodeURIComponent(productId)}`));
      return data?.product || null;
    }

    async function addProduct(productId) {
      try {
        const product = await fetchProduct(productId);
        if (!product) {
          showToast(lang('prod_not_found', 'Product not found'), 'error');
          return;
        }
        productCache.set(Number(product.id), product);

        let group = findGroup(product.id);
        if (!group) {
          group = buildGroup(product);
          const line = group.lines[0];
          if (!line) {
            showInsufficientStock(group);
            return;
          }
          const unit = getUnit(group, line.unit);
          const maxQty = getMaxQtyForLine(group, unit, line.key);
          if (maxQty <= 0) {
            showInsufficientStock(group);
            return;
          }
          line.qty = line.allow_fractional ? Math.min(1, maxQty) : Math.max(1, Math.min(maxQty, 1));
          cart.push(group);
        } else {
          syncGroupFromProduct(group, product);
          let line = group.lines.find((item) => item.unit === group.default_unit);
          if (!line) {
            const candidateUnit = getPreferredAddableUnit(group, group.default_unit);
            if (candidateUnit && !group.lines.some((item) => item.unit === candidateUnit.code)) {
              const maxQty = getMaxQtyForLine(group, candidateUnit);
              if (maxQty > 0) {
                line = createLineFromUnit(candidateUnit, candidateUnit.allow_fractional ? Math.min(1, maxQty) : 1);
                group.lines.push(line);
              }
            }
          }
          line = line || group.lines[0];
          const unit = getUnit(group, line.unit);
          const delta = nextQtyDelta(line);
          const maxQty = getMaxQtyForLine(group, unit, line.key);
          const nextQty = line.allow_fractional ? roundQty(line.qty + delta) : Math.round(line.qty + delta);
          if (nextQty > maxQty + 0.000001) {
            showInsufficientStock(group);
          } else {
            line.qty = nextQty;
          }
        }

        renderCart();
      } catch (error) {
        showToast(error.message || lang('err_validation', 'Error'), 'error');
      }
    }

    function removeGroup(productId) {
      const index = findGroupIndex(productId);
      if (index === -1) {
        return;
      }
      cart.splice(index, 1);
      if (activeDiscountLine && Number(activeDiscountLine.productId) === Number(productId)) {
        activeDiscountLine = null;
        closeModal('itemDiscountModal');
      }
      renderCart();
    }

    function removeLine(productId, lineKey) {
      const group = findGroup(productId);
      if (!group) {
        return;
      }
      const nextLines = group.lines.filter((line) => line.key !== lineKey);
      if (!nextLines.length) {
        removeGroup(productId);
        return;
      }
      group.lines = nextLines;
      if (activeDiscountLine && activeDiscountLine.productId === Number(productId) && activeDiscountLine.lineKey === lineKey) {
        activeDiscountLine = null;
        closeModal('itemDiscountModal');
      }
      renderCart();
    }

    function addUnitLine(productId) {
      const group = findGroup(productId);
      if (!group) {
        return;
      }
      const candidate = getPreferredAddableUnit(group, group.default_unit);
      if (!candidate) {
        if (!getUnusedUnits(group).length) {
          showToast(lang('pos_all_units_added', 'All available units are already added'), 'info');
          return;
        }
        showInsufficientStock(group);
        return;
      }
      const maxQty = getMaxQtyForLine(group, candidate);
      if (maxQty <= 0) {
        showInsufficientStock(group);
        return;
      }
      const initialQty = candidate.allow_fractional ? Math.min(1, maxQty) : 1;
      group.lines.push(createLineFromUnit(candidate, initialQty));
      renderCart();
    }

    function updateQty(productId, lineKey, rawValue) {
      const group = findGroup(productId);
      const line = findLine(group, lineKey);
      if (!group || !line) {
        return;
      }
      const unit = getUnit(group, line.unit);
      if (!unit) {
        return;
      }
      const maxQty = getMaxQtyForLine(group, unit, line.key);
      if (maxQty <= 0) {
        showInsufficientStock(group);
        return;
      }

      let nextQty = parseNumber(rawValue);
      if (line.allow_fractional) {
        nextQty = roundQty(nextQty);
      } else {
        nextQty = Math.round(nextQty);
      }
      nextQty = Math.max(lineMinQty(line), nextQty);
      if (nextQty > maxQty + 0.000001) {
        showInsufficientStock(group);
        nextQty = maxQty;
      }
      line.qty = line.allow_fractional ? roundQty(nextQty) : Math.max(1, Math.round(nextQty));
      renderCart();
    }

    function changeItemUnit(productId, lineKey, nextUnitCode) {
      const group = findGroup(productId);
      const line = findLine(group, lineKey);
      if (!group || !line) {
        return;
      }
      if (group.lines.some((entry) => entry.key !== line.key && entry.unit === nextUnitCode)) {
        showToast(lang('pos_unit_duplicate', 'This unit is already added to this product'), 'warning');
        renderCart();
        return;
      }
      const nextUnit = getUnit(group, nextUnitCode);
      if (!nextUnit) {
        renderCart();
        return;
      }
      const previous = {
        unit: line.unit,
        unit_label: line.unit_label,
        unit_price: line.unit_price,
        allow_fractional: line.allow_fractional,
        unit_ratio: line.unit_ratio,
        qty: line.qty,
      };
      syncLineFromUnit(line, nextUnit);
      const maxQty = getMaxQtyForLine(group, nextUnit, line.key);
      if (maxQty <= 0) {
        showInsufficientStock(group);
        line.unit = previous.unit;
        line.unit_label = previous.unit_label;
        line.unit_price = previous.unit_price;
        line.allow_fractional = previous.allow_fractional;
        line.unit_ratio = previous.unit_ratio;
        line.qty = previous.qty;
        renderCart();
        return;
      }
      if (line.allow_fractional) {
        line.qty = Math.min(Math.max(lineMinQty(line), roundQty(line.qty)), maxQty);
      } else {
        line.qty = Math.min(Math.max(1, Math.round(line.qty)), maxQty);
      }
      renderCart();
    }

    function openItemDiscount(productId, lineKey) {
      const group = findGroup(productId);
      const line = findLine(group, lineKey);
      if (!group || !line || !dom.itemDiscountModal) {
        return;
      }
      activeDiscountLine = { productId: Number(productId), lineKey };
      if (dom.itemDiscountProductName) {
        dom.itemDiscountProductName.value = `${group.name} · ${line.unit_label}`;
      }
      if (dom.itemDiscountValue) {
        dom.itemDiscountValue.value = line.line_discount_value > 0 ? String(line.line_discount_value) : '0';
      }
      setItemDiscountType(line.line_discount_type === 'amount' ? 'amount' : 'percent');
      openModal('itemDiscountModal');
      if (dom.itemDiscountValue) {
        dom.itemDiscountValue.focus();
        dom.itemDiscountValue.select();
      }
    }

    function setItemDiscountType(type) {
      const normalizedType = type === 'amount' ? 'amount' : 'percent';
      if (dom.itemDiscountPctBtn) {
        dom.itemDiscountPctBtn.classList.toggle('active', normalizedType === 'percent');
      }
      if (dom.itemDiscountAmtBtn) {
        dom.itemDiscountAmtBtn.classList.toggle('active', normalizedType === 'amount');
      }
      if (activeDiscountLine) {
        activeDiscountLine.type = normalizedType;
      }
    }

    function saveItemDiscount() {
      if (!activeDiscountLine) {
        return;
      }
      const group = findGroup(activeDiscountLine.productId);
      const line = findLine(group, activeDiscountLine.lineKey);
      if (!group || !line) {
        return;
      }
      const type = dom.itemDiscountAmtBtn?.classList.contains('active') ? 'amount' : 'percent';
      const value = Math.max(0, parseNumber(dom.itemDiscountValue?.value));
      if (value <= 0) {
        line.line_discount_type = 'none';
        line.line_discount_value = 0;
      } else {
        line.line_discount_type = type;
        line.line_discount_value = roundMoney(value);
      }
      resetReceiptDiscountConflict();
      closeModal('itemDiscountModal');
      renderCart();
    }

    function clearItemDiscount() {
      if (!activeDiscountLine) {
        return;
      }
      const group = findGroup(activeDiscountLine.productId);
      const line = findLine(group, activeDiscountLine.lineKey);
      if (line) {
        line.line_discount_type = 'none';
        line.line_discount_value = 0;
      }
      resetReceiptDiscountConflict();
      closeModal('itemDiscountModal');
      renderCart();
    }

    function setReceiptDiscountType(type) {
      receiptDiscountType = type === 'amount' ? 'amount' : 'percent';
      if (dom.receiptDiscountPctBtn) {
        dom.receiptDiscountPctBtn.classList.toggle('active', receiptDiscountType === 'percent');
      }
      if (dom.receiptDiscountAmtBtn) {
        dom.receiptDiscountAmtBtn.classList.toggle('active', receiptDiscountType === 'amount');
      }
      resetReceiptDiscountConflict();
      updateTotals();
    }

    function clearReceiptDiscount() {
      if (dom.cartDiscountValue) {
        dom.cartDiscountValue.value = '0';
      }
      resetReceiptDiscountConflict();
      updateTotals();
    }

    function flattenCartLines() {
      const lines = [];
      cart.forEach((group) => {
        group.lines.forEach((line) => {
          lines.push({
            product_id: Number(group.id),
            line_key: line.key,
            product_name: group.name,
            sku: group.sku,
            unit: line.unit,
            unit_label: line.unit_label,
            qty: parseNumber(line.qty),
            unit_price: roundMoney(line.unit_price),
            tax_rate: parseNumber(group.tax_rate),
            allow_discount: !!group.allow_discount,
            line_discount_type: line.line_discount_type || 'none',
            line_discount_value: parseNumber(line.line_discount_value),
          });
        });
      });
      return lines;
    }

    function maybeResolveReceiptConflict(rawLines) {
      const receiptValue = Math.max(0, parseNumber(dom.cartDiscountValue?.value));
      if (receiptValue <= 0) {
        applyReceiptToDiscountedItems = null;
        return;
      }
      const hasLineDiscounts = rawLines.some((line) => {
        const subtotal = roundMoney(line.unit_price * line.qty);
        if (!line.allow_discount || subtotal <= 0) {
          return false;
        }
        if (line.line_discount_type === 'percent') {
          return line.line_discount_value > 0;
        }
        if (line.line_discount_type === 'amount') {
          return line.line_discount_value > 0;
        }
        return false;
      });
      if (!hasLineDiscounts || applyReceiptToDiscountedItems !== null) {
        return;
      }
      applyReceiptToDiscountedItems = window.confirm(
        lang('pos_discount_conflict', 'Some items already have line discounts. Apply receipt discount to those items too?')
      );
    }

    function calculateTotals() {
      const rawLines = flattenCartLines();
      const lines = rawLines.map((line) => {
        const subtotal = roundMoney(line.unit_price * line.qty);
        let lineDiscountAmount = 0;
        if (line.allow_discount && subtotal > 0) {
          if (line.line_discount_type === 'percent') {
            const pct = Math.min(100, Math.max(0, line.line_discount_value));
            lineDiscountAmount = roundMoney(subtotal * pct / 100);
          } else if (line.line_discount_type === 'amount') {
            lineDiscountAmount = roundMoney(Math.min(subtotal, line.line_discount_value));
          }
        }
        const discountedSubtotal = roundMoney(Math.max(0, subtotal - lineDiscountAmount));
        return {
          ...line,
          subtotal,
          line_discount_amount: lineDiscountAmount,
          discounted_subtotal: discountedSubtotal,
          receipt_discount_amount: 0,
          discount_amount: 0,
          discount_pct: 0,
          taxable_base: discountedSubtotal,
          tax_amount: 0,
          line_total: discountedSubtotal,
        };
      });

      const receiptValue = Math.max(0, parseNumber(dom.cartDiscountValue?.value));
      if (receiptValue <= 0) {
        applyReceiptToDiscountedItems = null;
      }

      const applyReceiptToDiscounted = applyReceiptToDiscountedItems === true;
      lines.forEach((line) => {
        line.eligible_for_receipt_discount = line.allow_discount && (applyReceiptToDiscounted || line.line_discount_amount <= 0);
      });

      const receiptBase = roundMoney(lines.reduce((sum, line) => {
        return sum + (line.eligible_for_receipt_discount ? line.discounted_subtotal : 0);
      }, 0));

      let receiptDiscountAmount = 0;
      if (receiptValue > 0 && receiptBase > 0) {
        if (receiptDiscountType === 'amount') {
          receiptDiscountAmount = roundMoney(Math.min(receiptBase, receiptValue));
        } else {
          receiptDiscountAmount = roundMoney(receiptBase * Math.min(100, receiptValue) / 100);
        }
      }

      if (receiptDiscountAmount > 0) {
        const eligibleIndexes = lines
          .map((line, index) => (line.eligible_for_receipt_discount && line.discounted_subtotal > 0 ? index : null))
          .filter((index) => index !== null);
        let allocated = 0;
        eligibleIndexes.forEach((index, position) => {
          if (position === eligibleIndexes.length - 1) {
            lines[index].receipt_discount_amount = roundMoney(receiptDiscountAmount - allocated);
          } else {
            const share = roundMoney(receiptDiscountAmount * (lines[index].discounted_subtotal / receiptBase));
            lines[index].receipt_discount_amount = share;
            allocated += share;
          }
        });
      }

      const lineMap = new Map();
      let subtotal = 0;
      let discountAmount = 0;
      let taxAmount = 0;
      let total = 0;

      lines.forEach((line) => {
        const totalDiscount = roundMoney(line.line_discount_amount + line.receipt_discount_amount);
        const taxableBase = roundMoney(Math.max(0, line.subtotal - totalDiscount));
        const lineTaxAmount = roundMoney(taxableBase * line.tax_rate / 100);
        const lineTotal = roundMoney(taxableBase + lineTaxAmount);

        line.discount_amount = totalDiscount;
        line.discount_pct = line.subtotal > 0 ? roundMoney((totalDiscount / line.subtotal) * 100) : 0;
        line.taxable_base = taxableBase;
        line.tax_amount = lineTaxAmount;
        line.line_total = lineTotal;

        lineMap.set(getLineCompositeKey(line.product_id, line.line_key), line);

        subtotal += line.subtotal;
        discountAmount += totalDiscount;
        taxAmount += lineTaxAmount;
        total += lineTotal;
      });

      return {
        lines,
        lineMap,
        subtotal: roundMoney(subtotal),
        discountAmount: roundMoney(discountAmount),
        taxAmount: roundMoney(taxAmount),
        total: roundMoney(total),
        receipt_discount_type: receiptDiscountType,
        receipt_discount_value: receiptValue,
        apply_receipt_to_discounted_items: !!applyReceiptToDiscountedItems,
      };
    }
    function renderUnitOptions(group, line) {
      const allowed = getAddableUnits(group, line.key);
      const current = getUnit(group, line.unit);
      const options = current && !allowed.some((unit) => unit.code === current.code)
        ? [current, ...allowed]
        : allowed;

      return options
        .map((unit) => `<option value="${escHtml(unit.code)}" ${unit.code === line.unit ? 'selected' : ''}>${escHtml(unit.label)}</option>`)
        .join('');
    }

    function buildUnitTooltip(group) {
      const orderedUnits = [...group.units].sort((left, right) => {
        if (left.sort_order !== right.sort_order) {
          return left.sort_order - right.sort_order;
        }
        return left.ratio_to_base - right.ratio_to_base;
      });

      const rows = orderedUnits.map((unit, index) => {
        const nextUnit = orderedUnits[index + 1] || null;
        if (!nextUnit) {
          return `
            <div class="cart-unit-tooltip-row">
              <span>1 ${escHtml(unit.label)}</span>
              <span>=</span>
              <span>1 ${escHtml(unit.label)}</span>
            </div>
          `;
        }
        const ratio = nextUnit.ratio_to_base / Math.max(0.000001, unit.ratio_to_base);
        return `
          <div class="cart-unit-tooltip-row">
            <span>1 ${escHtml(unit.label)}</span>
            <span>=</span>
            <span>${fmtQty(ratio, Math.abs(ratio - Math.round(ratio)) > 0.000001)} ${escHtml(nextUnit.label)}</span>
          </div>
        `;
      }).join('');

      return `
        <span class="cart-unit-info-wrap">
          <button type="button" class="cart-unit-info-trigger" tabindex="-1" aria-label="${escHtml(lang('pos_unit_relations', 'Units'))}">
            i
          </button>
          <span class="cart-unit-tooltip">
            <span class="cart-unit-tooltip-title">${escHtml(lang('pos_unit_relations', 'Units'))}</span>
            ${rows}
          </span>
        </span>
      `;
    }

    function renderCartLine(group, line, calculatedLine) {
      const discountActive = line.line_discount_type !== 'none' && parseNumber(line.line_discount_value) > 0;
      const badges = [];
      if (calculatedLine?.line_discount_amount > 0) {
        badges.push(`<span class="cart-line-badge">${escHtml(lang('pos_discount_item', 'Item Discount'))}: -${fmtMoney(calculatedLine.line_discount_amount)}</span>`);
      }
      if (calculatedLine?.receipt_discount_amount > 0) {
        badges.push(`<span class="cart-line-badge">${escHtml(lang('pos_discount_receipt', 'Receipt Discount'))}: -${fmtMoney(calculatedLine.receipt_discount_amount)}</span>`);
      }

      return `
        <div class="cart-line">
          <div class="cart-line-main">
            <div class="cart-item-controls cart-line-controls">
              <button type="button" class="qty-btn" onclick="POS.updateQty(${group.id}, '${line.key}', ${line.allow_fractional ? roundQty(line.qty - nextQtyDelta(line)) : Math.round(line.qty - nextQtyDelta(line))})">−</button>
              <input
                type="number"
                class="qty-input"
                value="${escHtml(String(line.qty))}"
                min="${lineMinQty(line)}"
                step="${line.allow_fractional ? '0.001' : '1'}"
                onchange="POS.updateQty(${group.id}, '${line.key}', this.value)"
              >
              <button type="button" class="qty-btn" onclick="POS.updateQty(${group.id}, '${line.key}', ${line.allow_fractional ? roundQty(line.qty + nextQtyDelta(line)) : Math.round(line.qty + nextQtyDelta(line))})">+</button>
              <button type="button" class="qty-btn qty-btn-discount ${discountActive ? 'active' : ''}" onclick="POS.openItemDiscount(${group.id}, '${line.key}')" title="${escHtml(lang('pos_discount_item', 'Item Discount'))}">%</button>
              <select class="form-control cart-line-unit-select" onchange="POS.changeItemUnit(${group.id}, '${line.key}', this.value)">
                ${renderUnitOptions(group, line)}
              </select>
              <button type="button" class="cart-item-remove cart-line-remove" onclick="POS.removeLine(${group.id}, '${line.key}')" title="${escHtml(lang('pos_remove_line', 'Remove line'))}">
                ${featherSvg('x', 14)}
              </button>
            </div>
            ${badges.length ? `<div class="cart-line-badges">${badges.join('')}</div>` : ''}
          </div>
          <div class="cart-line-right">
            <div class="cart-item-total">${fmtMoney(calculatedLine?.taxable_base ?? (line.unit_price * line.qty))}</div>
            <div class="cart-line-meta">${fmtMoney(line.unit_price)} × ${fmtQty(line.qty, line.allow_fractional)}</div>
          </div>
        </div>
      `;
    }

    function renderCart() {
      if (!dom.cartWrap) {
        return;
      }

      const totals = calculateTotals();
      if (!cart.length) {
        dom.cartWrap.innerHTML = `
          <div class="cart-empty">
            <div class="cart-empty-icon">${featherSvg('shopping-cart', 48)}</div>
            <div class="cart-empty-text">${escHtml(lang('pos_cart_empty', 'Cart is empty'))}</div>
          </div>
        `;
        updateTotals(totals);
        if (window.feather) {
          feather.replace();
        }
        return;
      }

      dom.cartWrap.innerHTML = cart.map((group) => {
        const cardTotal = group.lines.reduce((sum, line) => {
          const calc = totals.lineMap.get(getLineCompositeKey(group.id, line.key));
          return sum + (calc?.taxable_base ?? 0);
        }, 0);

        return `
          <div class="cart-item cart-item-group">
            <div class="cart-item-header">
              <div class="cart-item-header-main">
                <div class="cart-item-name-row">
                  <span class="cart-item-name">${escHtml(group.name)}</span>
                  ${buildUnitTooltip(group)}
                </div>
                <div class="cart-item-sku">${escHtml(group.sku)}</div>
                <div class="cart-item-stock">${escHtml(group.stock_display || '')}</div>
              </div>
              <div class="cart-item-header-right">
                <div class="cart-item-total">${fmtMoney(cardTotal)}</div>
                <button type="button" class="cart-item-remove" onclick="POS.removeGroup(${group.id})" title="${escHtml(lang('pos_remove_product', 'Remove product'))}">
                  ${featherSvg('trash-2', 14)}
                </button>
              </div>
            </div>
            <div class="cart-item-lines">
              ${group.lines.map((line) => renderCartLine(group, line, totals.lineMap.get(getLineCompositeKey(group.id, line.key)))).join('')}
            </div>
            ${canAddAnotherUnit(group)
              ? `<button type="button" class="btn btn-sm btn-ghost cart-add-unit" onclick="POS.addUnitLine(${group.id})">${featherSvg('plus', 14)} ${escHtml(lang('pos_add_unit_line', 'Add unit'))}</button>`
              : ''
            }
          </div>
        `;
      }).join('');

      updateTotals(totals);
      if (window.feather) {
        feather.replace();
      }
    }

    function updateTotals(totals = calculateTotals()) {
      if (dom.subtotalAmt) {
        dom.subtotalAmt.textContent = fmtMoney(totals.subtotal);
      }
      if (dom.discountAmt) {
        dom.discountAmt.textContent = totals.discountAmount > 0 ? `-${fmtMoney(totals.discountAmount)}` : '—';
      }
      if (dom.taxAmt) {
        dom.taxAmt.textContent = fmtMoney(totals.taxAmount);
      }
      if (dom.totalAmt) {
        dom.totalAmt.textContent = fmtMoney(totals.total);
      }
      if (dom.checkoutBtn) {
        dom.checkoutBtn.disabled = cart.length === 0 || totals.total <= 0;
      }
    }

    function setPaymentMethod(method) {
      paymentMethod = ['cash', 'card', 'mixed'].includes(method) ? method : 'cash';
      $$('.pay-method-btn', dom.paymentModal || document).forEach((button) => {
        button.classList.toggle('active', button.dataset.method === paymentMethod);
      });
      updatePaymentUI();
    }

    function updatePaymentUI() {
      const totals = calculateTotals();
      if (dom.payTotalDisplay) {
        dom.payTotalDisplay.textContent = fmtMoney(totals.total);
      }

      if (dom.cashGivenRow) {
        dom.cashGivenRow.classList.toggle('d-none', paymentMethod === 'card');
      }
      if (dom.cardAmountRow) {
        dom.cardAmountRow.classList.toggle('d-none', paymentMethod !== 'mixed');
      }

      if (paymentMethod === 'cash' && dom.cashGiven) {
        dom.cashGiven.value = String(roundMoney(totals.total).toFixed(2));
      }
      if (paymentMethod === 'card' && dom.cardAmount) {
        dom.cardAmount.value = String(roundMoney(totals.total).toFixed(2));
      }
      if (paymentMethod !== 'mixed' && dom.cardAmount) {
        dom.cardAmount.value = paymentMethod === 'card' ? dom.cardAmount.value : '0';
      }

      updateChange();
    }

    function updateChange() {
      const totals = calculateTotals();
      const cashGiven = Math.max(0, parseNumber(dom.cashGiven?.value));
      const cardAmount = Math.max(0, parseNumber(dom.cardAmount?.value));
      let change = 0;
      if (paymentMethod === 'cash') {
        change = Math.max(0, roundMoney(cashGiven - totals.total));
      } else if (paymentMethod === 'mixed') {
        change = Math.max(0, roundMoney(cashGiven + cardAmount - totals.total));
      }
      if (dom.changeDisplay) {
        dom.changeDisplay.textContent = fmtMoney(change);
      }
    }

    function setShiftOpenState(isOpen) {
      hasOpenShift = !!isOpen;
      window.POS_HAS_OPEN_SHIFT = hasOpenShift;
      if (dom.shiftWarning) {
        if (hasOpenShift) {
          dom.shiftWarning.remove();
          dom.shiftWarning = null;
        } else {
          dom.shiftWarning.style.display = '';
        }
      }
    }

    function normalizeShiftExtensionState(payload = null) {
      const source = payload && typeof payload === 'object' ? payload : {};
      const requestOptions = Array.isArray(source.request_options)
        ? source.request_options
        : (Array.isArray(window.POS_SHIFT_EXTENSION_OPTIONS) ? window.POS_SHIFT_EXTENSION_OPTIONS : []);
      const normalizedOptions = requestOptions
        .map((value) => parseInt(value, 10))
        .filter((value) => Number.isFinite(value) && value > 0);

      return {
        message: String(source.message || lang('shift_sales_extension_required', 'Work hours are over. Request a shift extension to continue sales.')),
        requestOptions: Array.from(new Set(normalizedOptions)),
        remainingMinutes: parseInt(source.remaining_minutes ?? window.POS_SHIFT_EXTENSION_REMAINING ?? 0, 10) || 0,
        closeUrl: String(source.close_url || window.POS_SHIFT_CLOSE_URL || ''),
      };
    }

    function renderShiftExtensionOptions() {
      if (!dom.shiftExtensionPresetButtons) {
        return;
      }
      const options = Array.isArray(shiftExtensionState.requestOptions) ? shiftExtensionState.requestOptions : [];
      dom.shiftExtensionPresetButtons.innerHTML = options.length
        ? options.map((minutes) => `
            <button type="button" class="btn btn-sm btn-secondary" data-shift-extension-minutes="${minutes}">
              +${escHtml(String(minutes))}
            </button>
          `).join('')
        : `<div class="text-muted" style="font-size:12px">${escHtml(lang('shift_extension_pending', 'Request is pending'))}</div>`;

      dom.shiftExtensionPresetButtons.querySelectorAll('[data-shift-extension-minutes]').forEach((button) => {
        button.addEventListener('click', () => {
          if (dom.shiftExtensionCustomMinutes) {
            dom.shiftExtensionCustomMinutes.value = String(button.getAttribute('data-shift-extension-minutes') || '');
            dom.shiftExtensionCustomMinutes.focus();
            dom.shiftExtensionCustomMinutes.select();
          }
        });
      });
    }

    function openShiftExtensionModal(payload = null) {
      shiftExtensionState = normalizeShiftExtensionState(payload);
      if (dom.shiftExtensionMessage) {
        dom.shiftExtensionMessage.textContent = shiftExtensionState.message;
      }
      if (dom.shiftExtensionCustomMinutes) {
        dom.shiftExtensionCustomMinutes.value = shiftExtensionState.requestOptions[0] ? String(shiftExtensionState.requestOptions[0]) : '';
        const maxValue = Math.max(1, shiftExtensionState.remainingMinutes || 0);
        dom.shiftExtensionCustomMinutes.max = String(maxValue);
      }
      if (dom.shiftExtensionReason) {
        dom.shiftExtensionReason.value = '';
      }
      renderShiftExtensionOptions();
      openModal('shiftExtensionModal');
      if (dom.shiftExtensionCustomMinutes) {
        dom.shiftExtensionCustomMinutes.focus();
        dom.shiftExtensionCustomMinutes.select();
      }
    }

    function closeShiftExtensionModal() {
      closeModal('shiftExtensionModal');
    }

    async function submitShiftExtensionRequest() {
      if (!window.POS_ACTIVE_SHIFT_ID) {
        showToast(lang('pos_no_shift', 'No open shift. Please open a shift before making sales.'), 'error');
        return;
      }

      const requestedMinutes = Math.max(0, parseInt(dom.shiftExtensionCustomMinutes?.value || '0', 10) || 0);
      if (!requestedMinutes) {
        showToast(lang('err_validation', 'Error'), 'error');
        return;
      }
      if (shiftExtensionState.remainingMinutes && requestedMinutes > shiftExtensionState.remainingMinutes) {
        showToast(lang('err_validation', 'Error'), 'error');
        return;
      }

      if (dom.shiftExtensionSubmitBtn) {
        dom.shiftExtensionSubmitBtn.disabled = true;
      }

      try {
        const response = await fetchJson(buildUrl(window.POS_SHIFT_EXTENSION_URL || 'modules/shifts/extension_request.php'), {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': window.CSRF_TOKEN || '',
            'X-Requested-With': 'XMLHttpRequest',
          },
          body: JSON.stringify({
            requested_minutes: requestedMinutes,
            reason: String(dom.shiftExtensionReason?.value || ''),
          }),
        });

        closeShiftExtensionModal();
        showToast(response?.message || lang('shift_extension_pending', 'Request is pending'), 'success');
      } catch (error) {
        showToast(error.message || lang('err_validation', 'Error'), 'error');
      } finally {
        if (dom.shiftExtensionSubmitBtn) {
          dom.shiftExtensionSubmitBtn.disabled = false;
        }
      }
    }

    function reopenPaymentModal() {
      openModal('paymentModal');
      setPaymentMethod(paymentMethod);
      updatePaymentUI();
      if (paymentMethod === 'cash' && dom.cashGiven) {
        dom.cashGiven.focus();
        dom.cashGiven.select();
      }
    }

    function promptOpenShift(returnToPayment = false) {
      if (returnToPayment) {
        resumeCheckoutAfterShiftOpen = true;
      }
      openModal('shiftRequiredModal');
    }

    function cancelShiftRequired() {
      closeModal('shiftRequiredModal');
      if (resumeCheckoutAfterShiftOpen) {
        reopenPaymentModal();
      }
      resumeCheckoutAfterShiftOpen = false;
    }

    function openShiftModal() {
      closeModal('shiftRequiredModal');
      openModal('shiftOpenModal');
      if (dom.shiftOpeningCash) {
        dom.shiftOpeningCash.focus();
        dom.shiftOpeningCash.select();
      }
    }

    function closeShiftOpenModal() {
      closeModal('shiftOpenModal');
      if (resumeCheckoutAfterShiftOpen) {
        openModal('shiftRequiredModal');
      }
    }

    async function submitShiftOpen() {
      if (!window.POS_CAN_OPEN_SHIFT) {
        showToast(lang('pos_no_shift', 'No open shift. Please open a shift before making sales.'), 'error');
        return;
      }
      if (dom.shiftOpenBtn) {
        dom.shiftOpenBtn.disabled = true;
      }
      try {
        const response = await fetchJson(buildUrl('modules/shifts/ajax_open.php'), {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': window.CSRF_TOKEN || '',
            'X-Requested-With': 'XMLHttpRequest',
          },
          body: JSON.stringify({
            opening_cash: Math.max(0, parseNumber(dom.shiftOpeningCash?.value)),
            notes: String(dom.shiftNotes?.value || ''),
          }),
        });

        setShiftOpenState(true);
        if (response?.shift_id) {
          window.POS_ACTIVE_SHIFT_ID = parseInt(response.shift_id, 10) || 0;
        }
        closeModal('shiftOpenModal');
        closeModal('shiftRequiredModal');
        if (dom.shiftOpeningCash) {
          dom.shiftOpeningCash.value = '0';
        }
        if (dom.shiftNotes) {
          dom.shiftNotes.value = '';
        }

        showToast(response?.message || lang('shift_opened', 'Shift opened'), 'success');

        if (resumeCheckoutAfterShiftOpen) {
          resumeCheckoutAfterShiftOpen = false;
          reopenPaymentModal();
        }
      } catch (error) {
        showToast(error.message || lang('err_validation', 'Error'), 'error');
      } finally {
        if (dom.shiftOpenBtn) {
          dom.shiftOpenBtn.disabled = false;
        }
      }
    }

    function openCheckout() {
      if (!cart.length) {
        showToast(lang('pos_cart_empty', 'Cart is empty'), 'warning');
        return;
      }
      maybeResolveReceiptConflict(flattenCartLines());
      const totals = calculateTotals();
      if (totals.total <= 0) {
        showToast(lang('pos_cart_empty', 'Cart is empty'), 'warning');
        return;
      }
      openModal('paymentModal');
      setPaymentMethod(paymentMethod);
      updatePaymentUI();
      if (paymentMethod === 'cash' && dom.cashGiven) {
        dom.cashGiven.focus();
        dom.cashGiven.select();
      }
    }

    function openDocumentWindow(name, width, height) {
      const left = Math.max(0, Math.round((window.screen.width - width) / 2));
      const top = Math.max(0, Math.round((window.screen.height - height) / 2));
      const features = [
        `width=${width}`,
        `height=${height}`,
        `left=${left}`,
        `top=${top}`,
        'resizable=yes',
        'scrollbars=yes',
      ].join(',');

      return window.open('about:blank', name, features);
    }

    function openReceiptPopup(type = 'retail') {
      const isLegal = type === 'legal';
      const popup = openDocumentWindow(
        isLegal ? 'buildmart_legal_receipt' : 'buildmart_receipt',
        isLegal ? 980 : 420,
        isLegal ? 860 : 760
      );
      if (popup && !popup.closed) {
        popup.document.write(`
          <!DOCTYPE html>
          <html lang="${escHtml(document.documentElement.lang || 'ru')}">
          <head>
            <meta charset="UTF-8">
            <title>${escHtml(lang('loading', 'Loading...'))}</title>
            <style>
              body {
                margin: 0;
                font-family: Arial, sans-serif;
                background: #ffffff;
                color: #222222;
                display: flex;
                align-items: center;
                justify-content: center;
                min-height: 100vh;
                font-size: 14px;
              }
            </style>
          </head>
          <body>${escHtml(lang('loading', 'Loading...'))}</body>
          </html>
        `);
        popup.document.close();
      }
      return popup;
    }

    function setLegalSaleState(state) {
      legalSaleState = state ? { ...state } : null;
      if (!dom.legalInvoiceBtn || !dom.legalReceiptBtn || !dom.legalSaleReceiptNo || !dom.legalSaleCustomerName || !dom.legalSaleCustomerCompany) {
        return;
      }

      dom.legalSaleReceiptNo.textContent = legalSaleState?.receiptNo || '—';
      dom.legalSaleCustomerName.textContent = legalSaleState?.customerName || '—';
      dom.legalSaleCustomerCompany.textContent = legalSaleState?.customerCompany || '—';
      dom.legalInvoiceBtn.disabled = !legalSaleState?.invoiceUrl;
      dom.legalReceiptBtn.disabled = !legalSaleState?.fiscalReceiptUrl;
    }

    function openLegalReceipt() {
      if (!legalSaleState?.fiscalReceiptUrl) {
        return;
      }
      const popup = openReceiptPopup('legal');
      if (popup && !popup.closed) {
        popup.location.href = legalSaleState.fiscalReceiptUrl;
        popup.focus();
      } else {
        window.open(legalSaleState.fiscalReceiptUrl, '_blank');
      }
    }

    function openLegalInvoice() {
      if (!legalSaleState?.invoiceUrl) {
        return;
      }
      window.open(legalSaleState.invoiceUrl, '_blank', 'noopener');
    }

    function closeLegalSaleModal() {
      closeModal('legalSaleModal');
    }

    async function processPayment() {
      maybeResolveReceiptConflict(flattenCartLines());
      const totals = calculateTotals();
      if (!totals.lines.length || totals.total <= 0) {
        showToast(lang('pos_cart_empty', 'Cart is empty'), 'warning');
        return;
      }

      const receiptPopup = openReceiptPopup();

      if (dom.processPayBtn) {
        dom.processPayBtn.disabled = true;
      }

      try {
        const payload = {
          cart: totals.lines.map((line) => ({
            id: line.product_id,
            sku: line.sku,
            unit: line.unit,
            qty: line.qty,
            unit_price: line.unit_price,
            tax_rate: line.tax_rate,
            line_discount_type: line.line_discount_type,
            line_discount_value: line.line_discount_value,
          })),
          customer_id: parseInt(dom.cartCustomer?.value || '1', 10) || 1,
          payment_method: paymentMethod,
          cash_given: Math.max(0, parseNumber(dom.cashGiven?.value)),
          card_amount: Math.max(0, parseNumber(dom.cardAmount?.value)),
          notes: String(dom.saleNotes?.value || ''),
          warehouse_id: Number(window.POS_WAREHOUSE_ID || 0),
          receipt_discount_type: receiptDiscountType,
          receipt_discount_value: Math.max(0, parseNumber(dom.cartDiscountValue?.value)),
          apply_receipt_to_discounted_items: !!applyReceiptToDiscountedItems,
        };

        const response = await fetchJson(buildUrl('modules/pos/process_sale.php'), {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': window.CSRF_TOKEN || '',
            'X-Requested-With': 'XMLHttpRequest',
          },
          body: JSON.stringify(payload),
        });

        closeModal('paymentModal');
        clearCart();
        if (response?.receipt_url || response?.sale_id) {
          const receiptUrl = response.receipt_url || buildUrl(`modules/pos/receipt.php?id=${encodeURIComponent(response.sale_id)}`);
          if (receiptPopup && !receiptPopup.closed) {
            receiptPopup.location.replace(receiptUrl);
            receiptPopup.focus();
          } else {
            window.open(receiptUrl, '_blank', 'width=420,height=760,resizable=yes,scrollbars=yes');
          }
          return;
        }
        if (receiptPopup && !receiptPopup.closed) {
          receiptPopup.close();
        }
        showToast(lang('pos_sale_complete', 'Sale completed'), 'success');
      } catch (error) {
        if (receiptPopup && !receiptPopup.closed) {
          receiptPopup.close();
        }
        if (error?.response?.code === 'shift_not_open') {
          closeModal('paymentModal');
          promptOpenShift(true);
          return;
        }
        if (error?.response?.code === 'shift_extension_required') {
          closeModal('paymentModal');
          openShiftExtensionModal(error.response);
          return;
        }
        if (error?.response?.code === 'shift_expired_close_required') {
          closeModal('paymentModal');
          showToast(error.message || lang('shift_sales_extension_required', 'Work hours are over. Request a shift extension to continue sales.'), 'error');
          return;
        }
        if (error?.response?.code === 'shift_extension_pending') {
          closeModal('paymentModal');
          showToast(error.message || lang('shift_extension_pending', 'Request is pending'), 'info');
          return;
        }
        showToast(error.message || lang('err_validation', 'Error'), 'error');
      } finally {
        if (dom.processPayBtn) {
          dom.processPayBtn.disabled = false;
        }
      }
    }

    async function refreshProducts() {
      if (!dom.productsGrid) {
        return;
      }
      const query = encodeURIComponent(String(dom.searchInput?.value || '').trim());
      const category = encodeURIComponent(activeCategoryId || '');
      try {
        const data = await fetchJson(buildUrl(`modules/pos/search_products.php?q=${query}&cat=${category}`));
        const products = Array.isArray(data?.products) ? data.products : [];
        dom.productsGrid.innerHTML = products.length
          ? products.map((product) => `
            <div class="product-card ${product.stock_qty <= 0 ? 'out-of-stock' : ''}" onclick="POS.addProduct(${product.id})" title="${escHtml(product.name)}">
              <div class="product-thumb">
                ${product.image_url
                  ? `<img src="${escHtml(product.image_url)}" alt="${escHtml(product.name)}" loading="lazy">`
                  : `<div class="product-thumb-placeholder">${featherSvg('package', 28)}</div>`}
              </div>
              ${product.stock_qty <= 0
                ? `<span class="badge badge-danger product-card-stock-badge">${escHtml(lang('out_of_stock', 'Out of stock'))}</span>`
                : ''}
              <div class="product-card-name">${escHtml(product.name)}</div>
              <div class="product-card-sku">${escHtml(product.sku || '')}</div>
              <div class="product-card-price">${fmtMoney(product.sale_price)} <span style="font-size:11px;color:var(--text-muted)">${escHtml(product.unit_label || '')}</span></div>
            </div>
          `).join('')
          : `<div class="empty-state" style="grid-column:1/-1">${escHtml(lang('no_results', 'No results'))}</div>`;
        if (window.feather) {
          feather.replace();
        }
      } catch (error) {
        showToast(error.message || lang('err_validation', 'Error'), 'error');
      }
    }

    function clearCart() {
      cart = [];
      productCache = new Map();
      receiptDiscountType = 'percent';
      paymentMethod = 'cash';
      applyReceiptToDiscountedItems = null;
      activeDiscountLine = null;
      setLegalSaleState(null);
      if (dom.cartDiscountValue) {
        dom.cartDiscountValue.value = '0';
      }
      if (dom.saleNotes) {
        dom.saleNotes.value = '';
      }
      if (dom.cashGiven) {
        dom.cashGiven.value = '';
      }
      if (dom.cardAmount) {
        dom.cardAmount.value = '';
      }
      setReceiptDiscountType('percent');
      closeModal('paymentModal');
      closeModal('itemDiscountModal');
      closeModal('shiftRequiredModal');
      closeModal('shiftOpenModal');
      closeModal('legalSaleModal');
      renderCart();
    }

    function bindEvents() {
      if (dom.searchInput) {
        dom.searchInput.addEventListener('input', () => {
          window.clearTimeout(searchTimer);
          searchTimer = window.setTimeout(refreshProducts, 180);
        });
      }

      $$('.cat-tab').forEach((tab) => {
        tab.addEventListener('click', () => {
          activeCategoryId = tab.dataset.catId || '';
          $$('.cat-tab').forEach((button) => button.classList.toggle('active', button === tab));
          refreshProducts();
        });
      });

      if (dom.cartDiscountValue) {
        dom.cartDiscountValue.addEventListener('input', () => {
          resetReceiptDiscountConflict();
          updateTotals();
        });
      }

      if (dom.cashGiven) {
        dom.cashGiven.addEventListener('input', updateChange);
      }
      if (dom.cardAmount) {
        dom.cardAmount.addEventListener('input', updateChange);
      }

      if (dom.cartCustomer) {
        dom.cartCustomer.addEventListener('change', updateCustomerMeta);
      }

      $$('.pay-method-btn', dom.paymentModal || document).forEach((button) => {
        button.addEventListener('click', () => setPaymentMethod(button.dataset.method || 'cash'));
      });
    }

    function init() {
      cacheDom();
      if (!dom.productsGrid || !dom.cartWrap) {
        return;
      }
      bindEvents();
      setReceiptDiscountType('percent');
      updateCustomerMeta();
      renderCart();
    }

    return {
      init,
      addProduct,
      addUnitLine,
      removeGroup,
      removeLine,
      updateQty,
      changeItemUnit,
      openItemDiscount,
      setItemDiscountType,
      saveItemDiscount,
      clearItemDiscount,
      setReceiptDiscountType,
      clearReceiptDiscount,
      openCheckout,
      processPayment,
      promptOpenShift,
      cancelShiftRequired,
      openShiftModal,
      closeShiftOpenModal,
      submitShiftOpen,
      openShiftExtensionModal,
      closeShiftExtensionModal,
      submitShiftExtensionRequest,
      clearCart,
      openLegalReceipt,
      openLegalInvoice,
      closeLegalSaleModal,
    };
  })();

  window.POS = POS;

  document.addEventListener('DOMContentLoaded', () => {
    initSidebar();
    initFlashes();
    initDataConfirm();
    if (document.getElementById('productsGrid') && document.getElementById('cartItemsWrap')) {
      POS.init();
    }
  });
})();
