/**
 * BuildMart POS — UI Settings JavaScript
 * assets/js/ui-settings.js
 *
 * Provides:
 *  - Menu configurator drawer (drag, show/hide, pin)
 *  - ViewConfigurator class for module-level column/filter/preset drawers
 *  - POS view mode switcher
 *  - Generic preset management
 */

/* ── Utilities ───────────────────────────────────────────────── */
const UISettings = {

  API: window._uiSettingsApiUrl || '/modules/ui/settings_api.php',
  CSRF: document.querySelector('meta[name="csrf-token"]')?.content || '',

  post(data) {
    const fd = new FormData();
    for (const [k, v] of Object.entries(data)) {
      fd.append(k, typeof v === 'object' ? JSON.stringify(v) : v);
    }
    return fetch(this.API, {
      method: 'POST',
      headers: { 'X-CSRF-Token': this.CSRF, 'X-Requested-With': 'XMLHttpRequest' },
      body: fd
    }).then(r => r.json());
  },

  async savePrefs(module, settings) {
    return this.post({ action: 'save_prefs', module, settings });
  },

  async resetPrefs(module) {
    return this.post({ action: 'reset_prefs', module });
  },

  async listPresets(module) {
    return this.post({ action: 'list_presets', module });
  },

  async loadPreset(id) {
    return this.post({ action: 'load_preset', id });
  },

  async savePreset(name, module, settings, scopeType = 'user', scopeId = null, isDefault = false) {
    return this.post({ action: 'save_preset', name, module, settings, scope_type: scopeType, scope_id: scopeId || '', is_default: isDefault ? 1 : 0 });
  },

  async deletePreset(id) {
    return this.post({ action: 'delete_preset', id });
  },
};

/* ── Drag-and-Drop for lists ─────────────────────────────────── */
class DragList {
  constructor(container, itemSelector = '[data-key]') {
    this.container = container;
    this.itemSelector = itemSelector;
    this.dragging = null;
    this._init();
  }
  _init() {
    this.container.addEventListener('dragstart', e => {
      const row = e.target.closest(this.itemSelector);
      if (!row) return;
      this.dragging = row;
      row.classList.add('dragging');
      e.dataTransfer.effectAllowed = 'move';
    });
    this.container.addEventListener('dragend', e => {
      const row = e.target.closest(this.itemSelector);
      if (row) row.classList.remove('dragging');
      this.container.querySelectorAll('.drag-over').forEach(r => r.classList.remove('drag-over'));
      this.dragging = null;
    });
    this.container.addEventListener('dragover', e => {
      e.preventDefault();
      const row = e.target.closest(this.itemSelector);
      if (!row || row === this.dragging) return;
      this.container.querySelectorAll('.drag-over').forEach(r => r.classList.remove('drag-over'));
      row.classList.add('drag-over');
    });
    this.container.addEventListener('drop', e => {
      e.preventDefault();
      const row = e.target.closest(this.itemSelector);
      if (!row || row === this.dragging || !this.dragging) return;
      row.classList.remove('drag-over');
      const allRows = [...this.container.querySelectorAll(this.itemSelector)];
      const dragIdx = allRows.indexOf(this.dragging);
      const dropIdx = allRows.indexOf(row);
      if (dragIdx < dropIdx) {
        row.after(this.dragging);
      } else {
        row.before(this.dragging);
      }
      this.container.dispatchEvent(new CustomEvent('reordered'));
    });
    // Make drag handles trigger drag on their rows
    this.container.addEventListener('mousedown', e => {
      const handle = e.target.closest('.menu-drag-handle, .column-drag-handle');
      if (!handle) return;
      const row = handle.closest(this.itemSelector);
      if (row) row.draggable = true;
    });
    this.container.addEventListener('mouseup', e => {
      const row = e.target.closest(this.itemSelector);
      if (row) row.draggable = false;
    });
  }
  getOrder() {
    return [...this.container.querySelectorAll(this.itemSelector)].map(r => r.dataset.key);
  }
}

/* ── Menu Configurator ───────────────────────────────────────── */
class MenuConfigurator {
  constructor() {
    this.drawer   = document.getElementById('configMenuDrawer');
    this.openBtn  = document.getElementById('configMenuBtn');
    this.closeBtn = document.getElementById('configMenuClose');
    this.overlay  = document.getElementById('configMenuOverlay');
    this.saveBtn  = document.getElementById('menuConfigSave');
    this.resetBtn = document.getElementById('menuConfigReset');
    this.list     = document.getElementById('menuConfigList');
    if (!this.drawer || !this.list) return;
    this._init();
  }
  _init() {
    this.dragList = new DragList(this.list, '.menu-config-row');

    this.openBtn?.addEventListener('click', () => this.open());
    this.closeBtn?.addEventListener('click', () => this.close());
    this.overlay?.addEventListener('click', () => this.close());

    this.saveBtn?.addEventListener('click', () => this.save());
    this.resetBtn?.addEventListener('click', () => this.reset());

    // Pin buttons
    this.list.addEventListener('click', e => {
      const btn = e.target.closest('.menu-pin-btn');
      if (!btn) return;
      btn.classList.toggle('active');
    });

    // Keyboard close
    document.addEventListener('keydown', e => {
      if (e.key === 'Escape' && this.drawer.getAttribute('aria-hidden') === 'false') this.close();
    });
  }
  open() {
    this.drawer.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
  }
  close() {
    this.drawer.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
  }
  save() {
    const order  = this.dragList.getOrder();
    const hidden = [];
    const pinned = [];

    this.list.querySelectorAll('.menu-config-row').forEach(row => {
      const key     = row.dataset.key;
      const visible = row.querySelector('.menu-visibility-check')?.checked;
      const isPinned = row.querySelector('.menu-pin-btn')?.classList.contains('active');
      if (!visible) hidden.push(key);
      if (isPinned) pinned.push(key);
    });

    const settings = { items: order, hidden, pinned };

    UISettings.savePrefs('sidebar', settings).then(d => {
      if (d.ok) {
        this.close();
        window.location.reload();
      } else {
        alert(d.error || 'Error saving menu settings');
      }
    });
  }
  reset() {
    if (!confirm(window._uiStrings?.confirmReset || 'Restore default menu?')) return;
    UISettings.resetPrefs('sidebar').then(d => {
      if (d.ok) {
        this.close();
        window.location.reload();
      }
    });
  }
}

/* ── View Configurator Drawer ────────────────────────────────── */
class ViewConfigurator {
  /**
   * @param {Object} options
   *   module       - module name
   *   current      - current settings object
   *   allColumns   - [{key, label}] all available columns
   *   allFilters   - [{key, label}] all available filters (optional)
   *   sortFields   - [{key, label}] sortable fields (optional)
   *   onApply      - callback(settings) when Apply clicked
   *   showViewMode - show view mode selector (optional)
   *   viewModes    - [{key, label, icon}] available view modes
   */
  constructor(options) {
    this.opts    = options;
    this.module  = options.module;
    this.current = JSON.parse(JSON.stringify(options.current || {}));
    this.drawer  = null;
    this._build();
  }

  _build() {
    // Build drawer HTML
    const el = document.createElement('div');
    el.className = 'ui-drawer';
    el.setAttribute('aria-hidden', 'true');
    el.id = `viewCfgDrawer_${this.module}`;
    el.innerHTML = this._html();
    document.body.appendChild(el);
    this.drawer = el;

    // Overlay close
    el.querySelector('.ui-drawer-overlay').addEventListener('click', () => this.close());

    // Close btn
    el.querySelector('.ui-drawer-close').addEventListener('click', () => this.close());

    // Tabs
    el.querySelectorAll('.ui-drawer-tab').forEach(tab => {
      tab.addEventListener('click', () => {
        el.querySelectorAll('.ui-drawer-tab').forEach(t => t.classList.remove('active'));
        el.querySelectorAll('.ui-drawer-tab-panel').forEach(p => p.classList.remove('active'));
        tab.classList.add('active');
        el.querySelector('#' + tab.dataset.panel)?.classList.add('active');
      });
    });

    // Drag columns
    const colList = el.querySelector('#vcColList');
    if (colList) {
      this._colDrag = new DragList(colList, '.column-config-row');
    }

    // Sort dir toggle
    const sortDirBtn = el.querySelector('#vcSortDir');
    if (sortDirBtn) {
      sortDirBtn.addEventListener('click', () => {
        const cur = sortDirBtn.dataset.dir || 'asc';
        const next = cur === 'asc' ? 'desc' : 'asc';
        sortDirBtn.dataset.dir = next;
        sortDirBtn.textContent = next === 'asc' ? '↑ ASC' : '↓ DESC';
      });
    }

    // Per-page
    const ppInput = el.querySelector('#vcPerPage');

    // Footer buttons
    el.querySelector('#vcApply')?.addEventListener('click', () => this.apply());
    el.querySelector('#vcReset')?.addEventListener('click', () => this.reset());
    el.querySelector('#vcSavePreset')?.addEventListener('click', () => this.savePreset());

    // Preset load
    el.querySelector('#vcPresetList')?.addEventListener('click', async e => {
      const row = e.target.closest('.preset-row');
      if (!row) return;
      if (e.target.closest('.preset-action-btn.delete')) {
        if (!confirm(window._uiStrings?.confirmDeletePreset || 'Delete preset?')) return;
        const id = parseInt(row.dataset.id);
        const d = await UISettings.deletePreset(id);
        if (d.ok) row.remove();
        return;
      }
      const id = parseInt(row.dataset.id);
      const d = await UISettings.loadPreset(id);
      if (d.ok) {
        Object.assign(this.current, d.preset.settings);
        this.close();
        this._build(); // rebuild with new settings
        this.opts.onApply?.(this.current);
      }
    });

    this._loadPresets();

    // Keyboard close
    document.addEventListener('keydown', e => {
      if (e.key === 'Escape' && this.drawer?.getAttribute('aria-hidden') === 'false') this.close();
    });
  }

  _html() {
    const c = this.current;
    const s = window._uiStrings || {};

    // Columns tab content
    const allCols = this.opts.allColumns || [];
    const activeCols = c.columns || allCols.map(x => x.key);
    const colRows = allCols.map(col => {
      const isOn = activeCols.includes(col.key);
      return `<div class="column-config-row" data-key="${col.key}">
        <span class="column-drag-handle">${_featherSvg('more-vertical',13)}</span>
        <span class="column-config-label">${col.label}</span>
        <label class="toggle-switch">
          <input type="checkbox" class="col-vis-check" data-key="${col.key}" ${isOn ? 'checked' : ''}>
          <span class="toggle-slider"></span>
        </label>
      </div>`;
    }).join('');

    // Filters tab
    const allFilters = this.opts.allFilters || [];
    const activeFilters = c.filters ? Object.keys(c.filters) : allFilters.map(x => x.key);
    const filterRows = allFilters.map(f => {
      const isOn = activeFilters.includes(f.key);
      return `<div class="filter-config-row" data-key="${f.key}">
        <span class="filter-config-label">${f.label}</span>
        <label class="toggle-switch">
          <input type="checkbox" class="filter-vis-check" data-key="${f.key}" ${isOn ? 'checked' : ''}>
          <span class="toggle-slider"></span>
        </label>
      </div>`;
    }).join('');

    // Sort tab
    const sortFields = this.opts.sortFields || [];
    const sortRows = sortFields.map(f =>
      `<option value="${f.key}" ${c.sort_by === f.key ? 'selected' : ''}>${f.label}</option>`
    ).join('');

    // View mode tab
    const viewModes = this.opts.viewModes || [];
    const vmHtml = viewModes.map(vm =>
      `<button class="view-switcher-btn ${c.view_mode === vm.key ? 'active' : ''}" data-vm="${vm.key}" type="button">
        ${_featherSvg(vm.icon, 14)} ${vm.label}
      </button>`
    ).join('');

    const tabs = [];
    const panels = [];
    if (allCols.length) {
      tabs.push(`<button class="ui-drawer-tab active" data-panel="vcPanelCols">${s.tabColumns || 'Columns'}</button>`);
      panels.push(`<div class="ui-drawer-tab-panel active" id="vcPanelCols">
        <div class="ui-section-title">${s.dragToReorder || 'Drag to reorder'}</div>
        <div id="vcColList" class="column-config-list">${colRows}</div>
      </div>`);
    }
    if (allFilters.length) {
      tabs.push(`<button class="ui-drawer-tab" data-panel="vcPanelFilters">${s.tabFilters || 'Filters'}</button>`);
      panels.push(`<div class="ui-drawer-tab-panel" id="vcPanelFilters">
        <div class="filter-config-list">${filterRows}</div>
      </div>`);
    }
    if (sortFields.length) {
      tabs.push(`<button class="ui-drawer-tab" data-panel="vcPanelSort">${s.tabSort || 'Sorting'}</button>`);
      panels.push(`<div class="ui-drawer-tab-panel" id="vcPanelSort">
        <div class="ui-section-title">${s.sortBy || 'Sort by'}</div>
        <div class="sort-controls">
          <select id="vcSortBy" class="sort-select">${sortRows}</select>
          <button id="vcSortDir" class="sort-dir-btn" data-dir="${c.sort_dir || 'asc'}" type="button">
            ${c.sort_dir === 'desc' ? '↓ DESC' : '↑ ASC'}
          </button>
        </div>
        ${this.opts.showPerPage !== false ? `
        <div class="ui-section-title" style="margin-top:14px">${s.perPage || 'Items per page'}</div>
        <select id="vcPerPage" class="sort-select" style="width:100%">
          ${[15,20,30,50,100].map(n => `<option value="${n}" ${(c.per_page||30)==n?'selected':''}>${n}</option>`).join('')}
        </select>` : ''}
      </div>`);
    }
    if (viewModes.length) {
      tabs.push(`<button class="ui-drawer-tab" data-panel="vcPanelView">${s.tabView || 'View'}</button>`);
      panels.push(`<div class="ui-drawer-tab-panel" id="vcPanelView">
        <div class="ui-section-title">${s.viewMode || 'View mode'}</div>
        <div class="view-switcher" style="width:100%;justify-content:stretch" id="vcViewModes">${vmHtml}</div>
      </div>`);
    }
    // Presets tab always
    tabs.push(`<button class="ui-drawer-tab" data-panel="vcPanelPresets">${s.tabPresets || 'Presets'}</button>`);
    panels.push(`<div class="ui-drawer-tab-panel" id="vcPanelPresets">
      <div id="vcPresetList" class="presets-list"></div>
      <div class="ui-section-title">${s.saveAsPreset || 'Save as preset'}</div>
      <div class="preset-save-row">
        <input id="vcPresetName" type="text" placeholder="${s.presetNamePh || 'Preset name…'}">
        <button id="vcSavePreset" class="btn btn-secondary btn-sm" type="button">${s.save || 'Save'}</button>
      </div>
    </div>`);

    return `
    <div class="ui-drawer-overlay"></div>
    <div class="ui-drawer-panel">
      <div class="ui-drawer-header">
        <h3>${s.configureView || 'Configure view'}</h3>
        <button class="ui-drawer-close" type="button">${_featherSvg('x', 18)}</button>
      </div>
      <div class="ui-drawer-body">
        <div class="ui-drawer-tabs">${tabs.join('')}</div>
        ${panels.join('')}
      </div>
      <div class="ui-drawer-footer">
        <button id="vcReset" class="btn btn-secondary btn-sm" type="button">${_featherSvg('rotate-ccw',13)} ${s.restoreDefaults || 'Reset'}</button>
        <button id="vcApply" class="btn btn-primary btn-sm" type="button">${_featherSvg('check',13)} ${s.apply || 'Apply'}</button>
      </div>
    </div>`;
  }

  open() {
    this.drawer.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
  }
  close() {
    this.drawer?.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
  }

  _collectSettings() {
    const s = JSON.parse(JSON.stringify(this.current));

    // Columns
    const colList = this.drawer.querySelector('#vcColList');
    if (colList) {
      const order = this._colDrag?.getOrder() || [];
      const visible = [...colList.querySelectorAll('.col-vis-check')].filter(cb => cb.checked).map(cb => cb.dataset.key);
      // Keep order, filter to visible
      s.columns = order.length ? order.filter(k => visible.includes(k)) : visible;
      s.columns_order = order;
    }

    // Filters
    const filterList = this.drawer.querySelector('.filter-config-list');
    if (filterList) {
      const activeFilters = {};
      filterList.querySelectorAll('.filter-vis-check').forEach(cb => {
        if (cb.checked) activeFilters[cb.dataset.key] = s.filters?.[cb.dataset.key] || '';
      });
      s.filters = activeFilters;
    }

    // Sort
    const sortBy = this.drawer.querySelector('#vcSortBy');
    if (sortBy) s.sort_by = sortBy.value;
    const sortDir = this.drawer.querySelector('#vcSortDir');
    if (sortDir) s.sort_dir = sortDir.dataset.dir || 'asc';

    // Per page
    const perPage = this.drawer.querySelector('#vcPerPage');
    if (perPage) s.per_page = parseInt(perPage.value);

    // View mode
    const activeVm = this.drawer.querySelector('#vcViewModes .view-switcher-btn.active');
    if (activeVm) s.view_mode = activeVm.dataset.vm;

    return s;
  }

  async apply() {
    const settings = this._collectSettings();
    const d = await UISettings.savePrefs(this.module, settings);
    if (d.ok) {
      this.current = settings;
      this.close();
      this.opts.onApply?.(settings);
    } else {
      alert(d.error || 'Error saving settings');
    }
  }

  async reset() {
    const s = window._uiStrings;
    if (!confirm(s?.confirmReset || 'Restore defaults?')) return;
    const d = await UISettings.resetPrefs(this.module);
    if (d.ok) {
      this.current = d.defaults || {};
      this.close();
      this.drawer.remove();
      this.drawer = null;
      this._build();
      this.opts.onApply?.(this.current);
    }
  }

  async savePreset() {
    const nameInput = this.drawer.querySelector('#vcPresetName');
    const name = nameInput?.value.trim();
    if (!name) { nameInput?.focus(); return; }
    const settings = this._collectSettings();
    const d = await UISettings.savePreset(name, this.module, settings);
    if (d.ok) {
      if (nameInput) nameInput.value = '';
      await this._loadPresets();
    }
  }

  async _loadPresets() {
    const list = this.drawer.querySelector('#vcPresetList');
    if (!list) return;
    const d = await UISettings.listPresets(this.module);
    if (!d.ok) return;
    list.innerHTML = d.presets.map(p => `
      <div class="preset-row" data-id="${p.id}">
        <span class="preset-scope-badge preset-scope-${p.scope_type}">${p.scope_type}</span>
        <span class="preset-name">${p.name}</span>
        ${p.creator_name ? `<span class="preset-creator">${p.creator_name}</span>` : ''}
        ${p.scope_type === 'user' ? `<div class="preset-actions"><button class="preset-action-btn delete" title="Delete">${_featherSvg('trash-2',12)}</button></div>` : ''}
      </div>
    `).join('') || `<p style="font-size:12px;color:#888;text-align:center;padding:12px 0">${window._uiStrings?.noPresets || 'No presets saved'}</p>`;
  }
}

/* ── POS View Switcher (inline, no drawer needed) ────────────── */
class POSViewSwitcher {
  constructor(containerId, currentMode, onSwitch) {
    this.container = document.getElementById(containerId);
    this.currentMode = currentMode;
    this.onSwitch = onSwitch;
    if (!this.container) return;
    this.container.addEventListener('click', e => {
      const btn = e.target.closest('.view-switcher-btn');
      if (!btn) return;
      const mode = btn.dataset.vm;
      if (mode === this.currentMode) return;
      this.currentMode = mode;
      this.container.querySelectorAll('.view-switcher-btn').forEach(b => b.classList.toggle('active', b.dataset.vm === mode));
      UISettings.savePrefs('pos', { view_mode: mode });
      this.onSwitch?.(mode);
    });
  }
}

/* ── Helper: inline feather SVG for JS-built UIs ─────────────── */
function _featherSvg(name, size = 16) {
  const icons = {
    'x': `<svg width="${size}" height="${size}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>`,
    'more-vertical': `<svg width="${size}" height="${size}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="5" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="12" cy="19" r="1"/></svg>`,
    'rotate-ccw': `<svg width="${size}" height="${size}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-3"/></svg>`,
    'check': `<svg width="${size}" height="${size}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>`,
    'save': `<svg width="${size}" height="${size}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>`,
    'trash-2': `<svg width="${size}" height="${size}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>`,
    'anchor': `<svg width="${size}" height="${size}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="5" r="3"/><line x1="12" y1="22" x2="12" y2="8"/><path d="M5 12H2a10 10 0 0 0 20 0h-3"/></svg>`,
    'grid': `<svg width="${size}" height="${size}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>`,
    'list': `<svg width="${size}" height="${size}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>`,
    'align-justify': `<svg width="${size}" height="${size}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="21" y1="10" x2="3" y2="10"/><line x1="21" y1="6" x2="3" y2="6"/><line x1="21" y1="14" x2="3" y2="14"/><line x1="21" y1="18" x2="3" y2="18"/></svg>`,
    'sliders': `<svg width="${size}" height="${size}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/></svg>`,
  };
  return icons[name] || icons['grid'];
}

/* ── Init on DOM ready ───────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', function() {
  // Init menu configurator
  new MenuConfigurator();

  // Set API URL from data attr if present
  const apiEl = document.querySelector('[data-ui-api]');
  if (apiEl) UISettings.API = apiEl.dataset.uiApi;

  // CSRF from meta tag
  const csrfMeta = document.querySelector('meta[name="csrf-token"]');
  if (csrfMeta) UISettings.CSRF = csrfMeta.content;
});

// Export for module pages
window.UISettings = UISettings;
window.ViewConfigurator = ViewConfigurator;
window.POSViewSwitcher = POSViewSwitcher;
