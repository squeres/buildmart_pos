(() => {
  'use strict';

  const config = window.INVENTORY_COUNT_CONFIG;
  if (!config) {
    return;
  }

  const dom = {
    warehouse: document.getElementById('inventoryCountWarehouse'),
    search: document.getElementById('inventoryCountSearch'),
    cameraBtn: document.getElementById('inventoryCountCameraBtn'),
    results: document.getElementById('inventoryCountResults'),
    notFound: document.getElementById('inventoryCountNotFound'),
    queueBody: document.getElementById('inventoryCountQueueBody'),
    queueTable: document.getElementById('inventoryCountQueueTable'),
    queueCards: document.getElementById('inventoryCountQueueCards'),
    queueEmpty: document.getElementById('inventoryCountQueueEmpty'),
    saveBtn: document.getElementById('inventoryCountSaveBtn'),
    clearBtn: document.getElementById('inventoryCountClearBtn'),
    createBtn: document.getElementById('inventoryCountCreateProductBtn'),
    createInlineBtn: document.getElementById('inventoryCountCreateInlineBtn'),
  };

  if (!dom.warehouse || !dom.search || !dom.results || !dom.queueBody) {
    return;
  }

  const state = {
    items: new Map(),
    results: [],
    debounceId: 0,
  };

  function t(key, fallback = '') {
    return Object.prototype.hasOwnProperty.call(config.strings || {}, key)
      ? config.strings[key]
      : fallback;
  }

  function escHtml(value) {
    return String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function parseNumber(value) {
    const normalized = String(value ?? '')
      .trim()
      .replace(/\s+/g, '')
      .replace(',', '.');
    const parsed = parseFloat(normalized);
    return Number.isFinite(parsed) ? parsed : 0;
  }

  function normalizeInputQty(value) {
    const numeric = parseNumber(value);
    if (Math.abs(numeric - Math.round(numeric)) < 0.000001) {
      return String(Math.round(numeric));
    }
    return numeric.toFixed(3).replace(/0+$/, '').replace(/\.$/, '');
  }

  function formatQty(value) {
    const locale = document.documentElement.lang === 'ru' ? 'ru-RU' : undefined;
    return new Intl.NumberFormat(locale, {
      minimumFractionDigits: 0,
      maximumFractionDigits: 3,
    }).format(Number(value) || 0);
  }

  function normalizeUnits(rawUnits, fallbackCode, fallbackLabel = '') {
    const result = [];
    const seen = new Set();
    const units = Array.isArray(rawUnits) ? rawUnits : [];

    units.forEach((rawUnit) => {
      const unitCode = String(rawUnit?.unit_code || '').trim();
      if (!unitCode || seen.has(unitCode)) {
        return;
      }

      seen.add(unitCode);
      result.push({
        unit_code: unitCode,
        unit_label: String(rawUnit?.unit_label || fallbackLabel || unitCode),
        ratio_to_base: Math.max(0.000001, parseNumber(rawUnit?.ratio_to_base) || 1),
        is_default: !!rawUnit?.is_default,
      });
    });

    const safeFallbackCode = String(fallbackCode || '').trim();
    if (safeFallbackCode && !seen.has(safeFallbackCode)) {
      result.unshift({
        unit_code: safeFallbackCode,
        unit_label: String(fallbackLabel || safeFallbackCode),
        ratio_to_base: 1,
        is_default: result.length === 0,
      });
    }

    if (!result.length) {
      result.push({
        unit_code: safeFallbackCode || 'pcs',
        unit_label: String(fallbackLabel || safeFallbackCode || 'pcs'),
        ratio_to_base: 1,
        is_default: true,
      });
    }

    return result;
  }

  function toast(message, type = 'info') {
    if (typeof window.showToast === 'function') {
      window.showToast(message, type);
      return;
    }
    window.alert(message);
  }

  function selectedWarehouseId() {
    return parseInt(dom.warehouse.value || config.selectedWarehouseId || 0, 10) || 0;
  }

  function currentQuery() {
    return String(dom.search.value || '').trim();
  }

  function setNotFoundVisible(visible) {
    dom.notFound.classList.toggle('hidden', !visible);
  }

  function queueItems() {
    return Array.from(state.items.values());
  }

  function focusSearch(select = false) {
    dom.search.focus();
    if (select) {
      dom.search.select();
    }
  }

  function clearResults() {
    state.results = [];
    dom.results.innerHTML = '';
    dom.results.classList.add('hidden');
    setNotFoundVisible(false);
  }

  function getItemUnit(item, unitCode = null) {
    const code = String(unitCode || item?.unit_code || '').trim();
    const units = Array.isArray(item?.units) ? item.units : [];
    return (
      units.find((unit) => unit.unit_code === code)
      || units.find((unit) => unit.is_default)
      || units[0]
      || {
        unit_code: String(item?.unit || 'pcs'),
        unit_label: String(item?.unit_label || item?.unit || 'pcs'),
        ratio_to_base: 1,
        is_default: true,
      }
    );
  }

  function qtyFromBase(baseQty, item, unitCode = null) {
    const unit = getItemUnit(item, unitCode);
    return parseNumber(baseQty) * Math.max(0.000001, parseNumber(unit?.ratio_to_base) || 1);
  }

  function qtyToBase(qty, item, unitCode = null) {
    const unit = getItemUnit(item, unitCode);
    return parseNumber(qty) / Math.max(0.000001, parseNumber(unit?.ratio_to_base) || 1);
  }

  function formatQtyInUnit(baseQty, item, unitCode = null) {
    const unit = getItemUnit(item, unitCode);
    return `${formatQty(qtyFromBase(baseQty, item, unit.unit_code))} ${String(unit?.unit_label || '').trim()}`.trim();
  }

  function diffBaseQty(item) {
    return qtyToBase(item.actual_qty, item, item.unit_code) - parseNumber(item.stock_qty);
  }

  function formatDiffInUnit(item) {
    const unit = getItemUnit(item, item.unit_code);
    const diffQty = qtyFromBase(diffBaseQty(item), item, unit.unit_code);
    return `${diffQty > 0 ? '+' : ''}${formatQty(diffQty)} ${String(unit?.unit_label || '').trim()}`.trim();
  }

  function buildUnitOptions(item) {
    const selectedCode = getItemUnit(item, item.unit_code).unit_code;
    return (Array.isArray(item.units) ? item.units : []).map((unit) => `
      <option value="${escHtml(unit.unit_code)}" ${unit.unit_code === selectedCode ? 'selected' : ''}>
        ${escHtml(unit.unit_label)}
      </option>
    `).join('');
  }

  function resultBadge(product) {
    if (parseNumber(product.stock_qty) < 0) {
      return `<span class="badge badge-warning">${escHtml(t('negativeStock', 'Negative stock'))}</span>`;
    }
    if (parseNumber(product.stock_qty) === 0) {
      return `<span class="badge badge-danger">${escHtml(t('outOfStock', 'Out of stock'))}</span>`;
    }
    if (product.stock_low) {
      return `<span class="badge badge-warning">${escHtml(t('lowStock', 'Low stock'))}</span>`;
    }
    return '';
  }

  function renderResults() {
    if (!state.results.length) {
      dom.results.innerHTML = '';
      dom.results.classList.add('hidden');
      return;
    }

    dom.results.classList.remove('hidden');
    dom.results.innerHTML = state.results.map((product) => `
      <button type="button" class="inventory-count-result" data-product-id="${product.id}">
        <div class="inventory-count-result-main">
          <div class="inventory-count-result-name-row">
            <strong>${escHtml(product.name)}</strong>
            ${resultBadge(product)}
          </div>
          <div class="inventory-count-result-meta">
            <span>${escHtml(t('sku', 'SKU'))}: ${escHtml(product.sku || '-')}</span>
            <span>${escHtml(t('barcode', 'Barcode'))}: ${escHtml(product.barcode || '-')}</span>
            <span>${escHtml(t('warehouseStock', 'Current stock'))}: ${escHtml(product.stock_display || '')}</span>
          </div>
          ${product.aliases
            ? `<div class="inventory-count-result-aliases">${escHtml(t('aliases', 'Aliases'))}: ${escHtml(product.aliases)}</div>`
            : ''}
        </div>
        <span class="inventory-count-result-action">${escHtml(t('addLabel', 'Add'))}</span>
      </button>
    `).join('');
  }

  function renderQueue() {
    const items = queueItems();
    dom.queueEmpty.classList.toggle('hidden', items.length > 0);
    dom.queueTable.classList.toggle('hidden', items.length === 0);
    dom.queueCards?.classList.toggle('hidden', items.length === 0);

    dom.queueBody.innerHTML = items.map((item) => {
      const diff = diffBaseQty(item);
      const diffClass = diff > 0
        ? 'inventory-count-diff positive'
        : (diff < 0 ? 'inventory-count-diff negative' : 'inventory-count-diff');

      return `
        <tr data-product-id="${item.id}">
          <td>
            <div class="inventory-count-row-product">${escHtml(item.name)}</div>
            <div class="inventory-count-row-meta">${escHtml(item.sku || '-')} &middot; ${escHtml(item.barcode || '-')}</div>
          </td>
          <td class="col-num inventory-count-row-current">${escHtml(formatQtyInUnit(item.stock_qty, item, item.unit_code))}</td>
          <td class="col-num">
            <div class="inventory-count-qty-editor">
              <input
                type="number"
                class="form-control mono inventory-count-qty-input"
                data-action="actual"
                data-product-id="${item.id}"
                min="0"
                step="0.001"
                value="${escHtml(item.actual_qty)}"
              >
              <select
                class="form-control inventory-count-unit-select"
                data-action="unit"
                data-product-id="${item.id}"
              >
                ${buildUnitOptions(item)}
              </select>
            </div>
          </td>
          <td class="col-num ${diffClass}" data-role="diff">${escHtml(formatDiffInUnit(item))}</td>
          <td>
            <input
              type="text"
              class="form-control inventory-count-note-input"
              data-action="notes"
              data-product-id="${item.id}"
              value="${escHtml(item.notes || '')}"
              placeholder="${escHtml(t('notePlaceholder', 'Comment'))}"
            >
          </td>
          <td class="col-actions">
            <button type="button" class="btn btn-ghost btn-sm" data-action="remove" data-product-id="${item.id}">
              ×
            </button>
          </td>
        </tr>
      `;
    }).join('');

    if (dom.queueCards) {
      dom.queueCards.innerHTML = items.map((item) => {
        const diff = diffBaseQty(item);
        const diffClass = diff > 0
          ? 'inventory-count-diff positive'
          : (diff < 0 ? 'inventory-count-diff negative' : 'inventory-count-diff');

        return `
          <div class="mobile-record-card inventory-count-queue-card" data-product-id="${item.id}">
            <div class="mobile-record-header">
              <div class="mobile-record-main">
                <div class="mobile-record-title">${escHtml(item.name)}</div>
                <div class="mobile-record-subtitle">${escHtml(item.sku || '-')} · ${escHtml(item.barcode || '-')}</div>
              </div>
              <div class="mobile-badge-row">
                <span class="${diffClass}">${escHtml(formatDiffInUnit(item))}</span>
              </div>
            </div>

            <div class="mobile-meta-grid">
              <div class="mobile-meta-row">
                <span class="mobile-meta-row-label">${escHtml(t('warehouseStock', 'Current stock'))}</span>
                <span class="mobile-meta-row-value">${escHtml(formatQtyInUnit(item.stock_qty, item, item.unit_code))}</span>
              </div>
            </div>

            <div class="inventory-count-queue-card-fields">
              <div class="inventory-count-qty-editor">
                <input
                  type="number"
                  class="form-control mono inventory-count-qty-input"
                  data-action="actual"
                  data-product-id="${item.id}"
                  min="0"
                  step="0.001"
                  value="${escHtml(item.actual_qty)}"
                >
                <select
                  class="form-control inventory-count-unit-select"
                  data-action="unit"
                  data-product-id="${item.id}"
                >
                  ${buildUnitOptions(item)}
                </select>
              </div>
              <input
                type="text"
                class="form-control inventory-count-note-input"
                data-action="notes"
                data-product-id="${item.id}"
                value="${escHtml(item.notes || '')}"
                placeholder="${escHtml(t('notePlaceholder', 'Comment'))}"
              >
            </div>

            <div class="mobile-actions">
              <button type="button" class="btn btn-danger" data-action="remove" data-product-id="${item.id}">
                ${escHtml(t('btn_delete', 'Remove'))}
              </button>
            </div>
          </div>
        `;
      }).join('');
    }
  }

  function buildQueueItem(product) {
    const units = normalizeUnits(product.units, product.unit, product.unit_label || product.unit || '');
    const defaultUnit = getItemUnit({
      units,
      unit: String(product.unit || ''),
      unit_label: String(product.unit_label || product.unit || ''),
      unit_code: String(product.default_unit_code || product.unit || ''),
    }, String(product.default_unit_code || product.unit || ''));

    return {
      id: Number(product.id),
      name: String(product.name || ''),
      sku: String(product.sku || ''),
      barcode: String(product.barcode || ''),
      unit: String(product.unit || ''),
      unit_label: String(product.unit_label || product.unit || ''),
      units,
      unit_code: defaultUnit.unit_code,
      stock_qty: parseNumber(product.stock_qty),
      stock_display: String(product.stock_display || ''),
      actual_qty: normalizeInputQty(qtyFromBase(product.stock_qty, { units, unit_code: defaultUnit.unit_code }, defaultUnit.unit_code)),
      notes: '',
    };
  }

  function upsertQueueItem(product) {
    const existing = state.items.get(Number(product.id));
    if (existing) {
      existing.stock_qty = parseNumber(product.stock_qty);
      existing.stock_display = String(product.stock_display || '');
      existing.units = normalizeUnits(product.units, product.unit, product.unit_label || product.unit || '');
      existing.unit = String(product.unit || existing.unit || '');
      existing.unit_label = String(product.unit_label || product.unit || existing.unit_label || existing.unit || '');
      existing.unit_code = getItemUnit(existing, existing.unit_code).unit_code;
      renderQueue();
      const input = dom.queueBody.querySelector(`input[data-action="actual"][data-product-id="${product.id}"]`);
      if (input) {
        input.focus();
        input.select();
      }
      return;
    }

    state.items.set(Number(product.id), buildQueueItem(product));

    renderQueue();
    const input = dom.queueBody.querySelector(`input[data-action="actual"][data-product-id="${product.id}"]`);
    if (input) {
      input.focus();
      input.select();
    }
  }

  async function fetchProducts(query) {
    const url = new URL(config.searchUrl, window.location.origin);
    url.searchParams.set('warehouse_id', String(selectedWarehouseId()));
    url.searchParams.set('q', query);

    const response = await fetch(url.toString(), { credentials: 'same-origin' });
    const data = await response.json();
    if (!response.ok) {
      throw new Error(data?.message || 'Search failed');
    }
    return Array.isArray(data?.products) ? data.products : [];
  }

  async function runSearch(force = false) {
    const query = currentQuery();
    if (!force && query.length < 2) {
      clearResults();
      return;
    }

    try {
      const products = await fetchProducts(query);
      state.results = products;
      renderResults();
      setNotFoundVisible(query.length >= 2 && products.length === 0);
    } catch (error) {
      clearResults();
      toast(error.message || 'Search failed', 'error');
    }
  }

  async function handleCameraScan(code) {
    dom.search.value = code;
    try {
      const products = await fetchProducts(code);
      const exact = products.find((product) => (
        String(product.barcode || '').trim() === code
        || String(product.sku || '').trim().toLowerCase() === String(code).trim().toLowerCase()
      )) || (products.length === 1 ? products[0] : null);
      if (exact) {
        upsertQueueItem(exact);
        dom.search.value = '';
        clearResults();
        setNotFoundVisible(false);
        focusSearch();
        return;
      }
      state.results = products;
      renderResults();
      setNotFoundVisible(products.length === 0);
    } catch (error) {
      toast(error.message || 'Search failed', 'error');
    }
  }

  function queuePayload() {
    return queueItems().map((item) => ({
      product_id: item.id,
      actual_qty: parseNumber(item.actual_qty),
      unit_code: String(getItemUnit(item, item.unit_code).unit_code || ''),
      notes: String(item.notes || '').trim(),
    }));
  }

  async function saveAll() {
    if (!config.canApply) {
      toast(t('applyDenied', 'Access denied'), 'error');
      return;
    }

    const items = queuePayload();
    if (!items.length) {
      toast(t('queueEmpty', 'Queue is empty'), 'warning');
      return;
    }

    if (items.some((item) => item.actual_qty < 0)) {
      toast(t('actualQtyRequired', 'Enter factual quantity'), 'error');
      return;
    }

    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const payload = {
      warehouse_id: selectedWarehouseId(),
      items,
    };

    dom.saveBtn.disabled = true;
    try {
      const response = await fetch(config.saveUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': csrfToken,
        },
        body: JSON.stringify(payload),
      });
      const data = await response.json();
      if (!response.ok || !data?.success) {
        throw new Error(data?.message || 'Save failed');
      }

      state.items.clear();
      renderQueue();
      clearResults();
      dom.search.value = '';
      toast(data.message || t('saved', 'Saved'), 'success');
      focusSearch();
    } catch (error) {
      toast(error.message || 'Save failed', 'error');
    } finally {
      dom.saveBtn.disabled = false;
    }
  }

  function clearQueue(withConfirm = true) {
    if (withConfirm && state.items.size && !window.confirm(t('clearConfirm', 'Clear current list?'))) {
      return;
    }
    state.items.clear();
    renderQueue();
    focusSearch();
  }

  function openCreateProductPopup() {
    if (!config.canCreateProduct) {
      return;
    }

    const url = new URL(config.createProductUrl, window.location.origin);
    url.searchParams.set('inventory_popup', '1');
    url.searchParams.set('warehouse_id', String(selectedWarehouseId()));
    if (currentQuery() !== '') {
      url.searchParams.set('inventory_query', currentQuery());
    }

    window.open(
      url.toString(),
      'inventory_product_create',
      'width=1360,height=920,resizable=yes,scrollbars=yes'
    );
  }

  dom.search.addEventListener('input', () => {
    window.clearTimeout(state.debounceId);
    state.debounceId = window.setTimeout(() => {
      runSearch();
    }, 180);
  });

  dom.search.addEventListener('keydown', (event) => {
    if (event.key !== 'Enter') {
      return;
    }

    event.preventDefault();
    if (state.results[0]) {
      upsertQueueItem(state.results[0]);
      dom.search.value = '';
      clearResults();
      return;
    }

    runSearch(true);
  });

  dom.results.addEventListener('click', (event) => {
    const button = event.target.closest('[data-product-id]');
    if (!button) {
      return;
    }
    const productId = Number(button.getAttribute('data-product-id'));
    const product = state.results.find((item) => Number(item.id) === productId);
    if (!product) {
      return;
    }
    upsertQueueItem(product);
    dom.search.value = '';
    clearResults();
  });

  dom.queueBody.addEventListener('input', (event) => {
    const productId = Number(event.target.getAttribute('data-product-id'));
    const action = event.target.getAttribute('data-action');
    const item = state.items.get(productId);
    if (!item) {
      return;
    }

    if (action === 'actual') {
      item.actual_qty = event.target.value;
      const row = event.target.closest('tr');
      const diffCell = row?.querySelector('[data-role="diff"]');
      const currentCell = row?.querySelector('.inventory-count-row-current');
      if (diffCell) {
        const diff = diffBaseQty(item);
        diffCell.className = `col-num inventory-count-diff${diff > 0 ? ' positive' : (diff < 0 ? ' negative' : '')}`;
        diffCell.textContent = formatDiffInUnit(item);
      }
      if (currentCell) {
        currentCell.textContent = formatQtyInUnit(item.stock_qty, item, item.unit_code);
      }
    } else if (action === 'notes') {
      item.notes = event.target.value;
    }
  });

  dom.queueBody.addEventListener('change', (event) => {
    const productId = Number(event.target.getAttribute('data-product-id'));
    const action = event.target.getAttribute('data-action');
    if (action !== 'unit') {
      return;
    }

    const item = state.items.get(productId);
    if (!item) {
      return;
    }

    const previousUnitCode = getItemUnit(item, item.unit_code).unit_code;
    const actualQtyBase = qtyToBase(item.actual_qty, item, previousUnitCode);
    item.unit_code = String(event.target.value || previousUnitCode);
    item.unit_code = getItemUnit(item, item.unit_code).unit_code;
    item.actual_qty = normalizeInputQty(qtyFromBase(actualQtyBase, item, item.unit_code));

    renderQueue();
    const input = dom.queueBody.querySelector(`input[data-action="actual"][data-product-id="${productId}"]`);
    if (input) {
      input.focus();
      input.select();
    }
  });

  dom.queueBody.addEventListener('click', (event) => {
    const button = event.target.closest('[data-action="remove"]');
    if (!button) {
      return;
    }
    const productId = Number(button.getAttribute('data-product-id'));
    state.items.delete(productId);
    renderQueue();
    focusSearch();
  });

  dom.clearBtn?.addEventListener('click', () => clearQueue(true));
  dom.saveBtn?.addEventListener('click', saveAll);
  dom.createBtn?.addEventListener('click', openCreateProductPopup);
  dom.createInlineBtn?.addEventListener('click', openCreateProductPopup);

  dom.warehouse.addEventListener('change', () => {
    if (state.items.size && !window.confirm(t('changeWarehouseConfirm', 'Change warehouse and clear current list?'))) {
      dom.warehouse.value = String(config.selectedWarehouseId || dom.warehouse.value);
      return;
    }

    config.selectedWarehouseId = selectedWarehouseId();
    clearQueue(false);
    clearResults();
    if (currentQuery().length >= 2) {
      runSearch(true);
    }
  });

  if (dom.cameraBtn && window.ProductCameraScanner) {
    window.ProductCameraScanner.attach(dom.cameraBtn, {
      onDetected: (code) => handleCameraScan(code),
    });
  }

  window.addEventListener('message', (event) => {
    if (event.origin !== window.location.origin) {
      return;
    }

    const payload = event.data || {};
    if (payload.type !== 'inventory-product-created' || !payload.product) {
      return;
    }

    upsertQueueItem(payload.product);
    dom.search.value = '';
    clearResults();
    setNotFoundVisible(false);
  });

  renderQueue();
  focusSearch();
})();
