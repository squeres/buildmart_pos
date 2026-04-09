<!DOCTYPE html>
<html lang="<?= Lang::current() ?>" dir="ltr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle ?? __('app_name')) ?> &mdash; <?= __('app_name') ?></title>
<?= app_favicon_links() ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;1,400&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= url('assets/css/app.css') ?>">
<link rel="stylesheet" href="<?= url('assets/css/ui-settings.css') ?>">
<?php if (!empty($extraCss)) echo $extraCss; ?>
</head>
<body class="app-body<?= !empty($bodyClassExtra) ? ' ' . e($bodyClassExtra) : '' ?>">
<?php
$menuCfg    = UISettings::menu();
$menuOrder  = $menuCfg['items']  ?? [];
$menuHidden = $menuCfg['hidden'] ?? [];
$menuPinned = $menuCfg['pinned'] ?? [];
$menuDefs = [
  'dashboard'  => ['perm'=>'dashboard',  'path'=>'/',                     'icon'=>'grid',         'key'=>'nav_dashboard'],
  'pos'        => ['perm'=>'pos',        'path'=>'/modules/pos/',         'icon'=>'shopping-cart','key'=>'nav_pos'],
  'products'   => ['perm'=>'products',   'path'=>'/modules/products/',    'icon'=>'package',      'key'=>'nav_products'],
  'categories' => ['perm'=>'categories', 'path'=>'/modules/categories/', 'icon'=>'tag',          'key'=>'nav_categories'],
  'inventory'  => ['perm'=>'inventory',  'path'=>'/modules/inventory/',   'icon'=>'layers',       'key'=>'nav_inventory'],
  'inventory_count' => ['perm'=>'inventory.count', 'path'=>'/modules/inventory/count.php', 'icon'=>'check-square', 'key'=>'nav_inventory_count'],
  'receipts'   => ['perm'=>'receipts',   'path'=>'/modules/receipts/',    'icon'=>'truck',        'key'=>'nav_receipts'],
  'acceptance' => ['perm'=>'acceptance', 'path'=>'/modules/acceptance/',  'icon'=>'clipboard',    'key'=>'nav_acceptance'],
  'transfers'  => ['perm'=>'transfers',  'path'=>'/modules/transfers/',   'icon'=>'shuffle',      'key'=>'nav_transfers'],
  'customers'  => ['perm'=>'customers',  'path'=>'/modules/customers/',   'icon'=>'users',        'key'=>'nav_customers'],
  'business_entities' => ['perm'=>'settings', 'path'=>'/modules/business_entities/', 'icon'=>'briefcase', 'key'=>'nav_business_entities'],
  'shifts'     => ['perm'=>'shifts',     'path'=>'/modules/shifts/',      'icon'=>'clock',        'key'=>'nav_shifts'],
  'sales'      => ['perm'=>'sales',      'path'=>'/modules/sales/',       'icon'=>'file-text',    'key'=>'nav_sales'],
  'reports'    => ['perm'=>'reports',    'path'=>'/modules/reports/',     'icon'=>'bar-chart-2',  'key'=>'nav_reports'],
  'suppliers'  => ['perm'=>'suppliers',  'path'=>'/modules/suppliers/',   'icon'=>'briefcase',    'key'=>'nav_suppliers'],
  'warehouses' => ['perm'=>'warehouses', 'path'=>'/modules/warehouses/',  'icon'=>'home',         'key'=>'nav_warehouses'],
  'users'      => ['perm'=>'users',      'path'=>'/modules/users/',       'icon'=>'user-check',   'key'=>'nav_users'],
  'settings'   => ['perm'=>'settings',   'path'=>'/modules/settings/',    'icon'=>'settings',     'key'=>'nav_settings'],
];
$orderedKeys = array_unique(array_merge($menuOrder, array_keys($menuDefs)));
if (in_array('inventory', $orderedKeys, true) && in_array('inventory_count', $orderedKeys, true)) {
  $orderedKeys = array_values(array_filter($orderedKeys, static fn(string $key): bool => $key !== 'inventory_count'));
  $inventoryIndex = array_search('inventory', $orderedKeys, true);
  if ($inventoryIndex === false) {
    $orderedKeys[] = 'inventory_count';
  } else {
    array_splice($orderedKeys, $inventoryIndex + 1, 0, ['inventory_count']);
  }
}
$currentPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$activeMenuKey = null;
$activeMenuPathLength = -1;
$basePathPrefix = rtrim(BASE_URL, '/');
$normalizedCurrentPath = rtrim($currentPath ?: '/', '/');
$normalizedCurrentPath = $normalizedCurrentPath === '' ? '/' : $normalizedCurrentPath;

foreach ($orderedKeys as $menuKey) {
  if (!isset($menuDefs[$menuKey])) continue;
  $item = $menuDefs[$menuKey];
  if (!Auth::can($item['perm'])) continue;
  if (in_array($menuKey, $menuHidden, true)) continue;

  $path = (string)$item['path'];
  if ($path === '/') {
    $matches = ($normalizedCurrentPath === $basePathPrefix || $normalizedCurrentPath === '/' || $normalizedCurrentPath === rtrim($basePathPrefix . '/index.php', '/'));
    $pathLen = 1;
  } else {
    $targetPath = rtrim($basePathPrefix . $path, '/');
    $matches = $normalizedCurrentPath === $targetPath || str_starts_with($normalizedCurrentPath . '/', $targetPath . '/');
    $pathLen = strlen($targetPath);
  }

  if ($matches && $pathLen > $activeMenuPathLength) {
    $activeMenuKey = $menuKey;
    $activeMenuPathLength = $pathLen;
  }
}

$acceptanceBadge = (function() { try { return gr_pending_count(); } catch(\Throwable $e) { return 0; } })();
$u = Auth::user();
$userInitial = (string)($u['name'] ?? '?');
$userInitial = function_exists('mb_substr')
  ? mb_substr($userInitial, 0, 1, 'UTF-8')
  : substr($userInitial, 0, 1);
$userInitial = function_exists('mb_strtoupper')
  ? mb_strtoupper($userInitial, 'UTF-8')
  : strtoupper($userInitial);
?>
<aside class="sidebar" id="sidebar">
  <div class="sidebar-brand">
    <a href="<?= url() ?>" class="sidebar-brand-link" aria-label="<?= __('app_name') ?>">
      <span class="sidebar-brand-mark" aria-hidden="true">
        <img src="<?= e(APP_ICON_URL) ?>" alt="" class="brand-mark-image">
      </span>
      <span class="sidebar-brand-copy">
        <span class="sidebar-brand-name"><?= __('app_name') ?></span>
        <span class="sidebar-brand-sub"><?= __('app_tagline') ?></span>
      </span>
    </a>
  </div>
  <nav class="sidebar-nav" id="sidebarNav">
    <?php foreach ($orderedKeys as $menuKey):
      if (!isset($menuDefs[$menuKey])) continue;
      $item = $menuDefs[$menuKey];
      if (!Auth::can($item['perm'])) continue;
      if (in_array($menuKey, $menuHidden)) continue;
      $isPinned = in_array($menuKey, $menuPinned);
      $href = BASE_URL . $item['path'];
      $active = $menuKey === $activeMenuKey;
    ?>
      <a href="<?= $href ?>" class="nav-link <?= $active ? 'active' : '' ?><?= $isPinned ? ' nav-pinned' : '' ?>" data-menu-key="<?= e($menuKey) ?>">
        <span class="nav-icon"><?php echo feather_icon($item['icon']); ?></span>
        <span class="nav-label"><?= __($item['key']) ?></span>
        <?php if ($menuKey === 'acceptance' && $acceptanceBadge > 0): ?>
          <span class="badge badge-warning" style="margin-left:auto;font-size:11px;padding:2px 6px"><?= $acceptanceBadge ?></span>
        <?php endif; ?>
      </a>
    <?php endforeach; ?>
    <div class="nav-divider"></div>
    <button class="nav-link nav-link-action" id="configMenuBtn" type="button">
      <span class="nav-icon"><?= feather_icon('sliders', 16) ?></span>
      <span class="nav-label"><?= __('ui_configure_menu') ?></span>
    </button>
  </nav>
  <div class="sidebar-footer">
    <div class="sidebar-user">
      <div class="user-avatar"><?= e($userInitial) ?></div>
      <div class="user-info">
        <div class="user-name"><?= e($u['name']) ?></div>
        <div class="user-role"><?= e($u['role_name']) ?></div>
        <div class="user-meta"><?= e($u['email'] ?? '') ?></div>
      </div>
    </div>
    <div class="sidebar-user-actions">
      <a href="<?= url('modules/profile/') ?>" class="btn btn-secondary btn-sm user-action-link">
        <?= feather_icon('user', 14) ?> <?= __('nav_profile') ?>
      </a>
      <a href="<?= url('modules/auth/logout.php') ?>" class="btn btn-ghost btn-sm user-action-link user-action-logout">
        <?= feather_icon('log-out', 14) ?> <?= __('nav_logout') ?>
      </a>
    </div>
  </div>
</aside>
<div class="sidebar-overlay" id="sidebarOverlay" hidden></div>

<!-- Configure Menu Drawer -->
<div class="ui-drawer" id="configMenuDrawer" aria-hidden="true">
  <div class="ui-drawer-overlay" id="configMenuOverlay"></div>
  <div class="ui-drawer-panel">
    <div class="ui-drawer-header">
      <h3><?= __('ui_configure_menu') ?></h3>
      <button class="ui-drawer-close" id="configMenuClose" type="button"><?= feather_icon('x', 18) ?></button>
    </div>
    <div class="ui-drawer-body">
      <p class="ui-drawer-hint"><?= __('ui_menu_hint') ?></p>
      <div id="menuConfigList" class="menu-config-list">
        <?php foreach ($orderedKeys as $menuKey):
          if (!isset($menuDefs[$menuKey])) continue;
          $item = $menuDefs[$menuKey];
          if (!Auth::can($item['perm'])) continue;
          $isHidden = in_array($menuKey, $menuHidden);
          $isPinned = in_array($menuKey, $menuPinned);
        ?>
        <div class="menu-config-row" data-key="<?= e($menuKey) ?>">
          <span class="menu-drag-handle"><?= feather_icon('more-vertical', 14) ?></span>
          <span class="menu-config-icon"><?= feather_icon($item['icon'], 15) ?></span>
          <span class="menu-config-label"><?= __($item['key']) ?></span>
          <div class="menu-config-actions">
            <button class="menu-pin-btn <?= $isPinned ? 'active' : '' ?>" title="<?= __('ui_pin') ?>" data-key="<?= e($menuKey) ?>"><?= feather_icon('anchor', 13) ?></button>
            <label class="toggle-switch">
              <input type="checkbox" class="menu-visibility-check" data-key="<?= e($menuKey) ?>" <?= !$isHidden ? 'checked' : '' ?>>
              <span class="toggle-slider"></span>
            </label>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="ui-drawer-footer">
      <button class="btn btn-secondary btn-sm" id="menuConfigReset" type="button"><?= feather_icon('rotate-ccw', 14) ?> <?= __('ui_restore_defaults') ?></button>
      <button class="btn btn-primary btn-sm" id="menuConfigSave" type="button"><?= feather_icon('save', 14) ?> <?= __('btn_save') ?></button>
    </div>
  </div>
</div>

<div class="main-wrap">
  <header class="topbar">
    <button class="topbar-menu-btn" id="sidebarToggle" aria-label="<?= __('ui_toggle_menu') ?>"><?= feather_icon('menu', 20) ?></button>
    <?php if (!empty($breadcrumbs)): ?>
    <nav class="breadcrumb" aria-label="<?= __('ui_breadcrumbs') ?>">
      <?php foreach ($breadcrumbs as $i => [$label, $href]): ?>
        <?php if ($i > 0): ?><span class="bc-sep">/</span><?php endif; ?>
        <?php if ($href && $i < count($breadcrumbs)-1): ?>
          <a href="<?= e($href) ?>" class="bc-link"><?= e($label) ?></a>
        <?php else: ?>
          <span class="bc-current"><?= e($label) ?></span>
        <?php endif; ?>
      <?php endforeach; ?>
    </nav>
    <?php elseif (!empty($pageTitle)): ?>
    <h1 class="topbar-title"><?= e($pageTitle) ?></h1>
    <?php endif; ?>
    <div class="topbar-right">
      <?php
      $accessIds = user_warehouse_ids();
      $whIn = implode(',', array_fill(0, count($accessIds), '?'));
      $lowCount = (int)Database::value("SELECT COUNT(DISTINCT sb.product_id) FROM stock_balances sb JOIN products p ON p.id=sb.product_id WHERE p.is_active=1 AND p.min_stock_qty>0 AND sb.qty<=p.min_stock_qty AND sb.qty>0 AND sb.warehouse_id IN ($whIn)", $accessIds);
      if ($lowCount > 0): ?>
        <a href="<?= url('modules/inventory/?filter=low') ?>" class="topbar-badge" title="<?= __('inv_low_stock') ?>"><?= feather_icon('alert-triangle', 15) ?><span><?= $lowCount ?></span></a>
      <?php endif; ?>
      <?php
      $canSwitchWh  = Auth::can('inventory');
      $myWarehouses = user_warehouses();
      $activeWhId   = selected_warehouse_id();
      $activeWhName = __('lbl_all').' '.__('wh_title');
      foreach ($myWarehouses as $_w) { if ($_w['id'] == $activeWhId) { $activeWhName = $_w['name']; break; } }
      if ($canSwitchWh && count($myWarehouses) > 1): ?>
      <div class="wh-selector" id="whSelector">
        <button class="wh-selector-btn" id="whSelectorBtn" type="button">
          <?= feather_icon('home', 13) ?><span id="whSelectorLabel"><?= e($activeWhName) ?></span><?= feather_icon('chevron-down', 12) ?>
        </button>
        <div class="wh-selector-drop" id="whSelectorDrop">
          <div class="wh-drop-title"><?= __('wh_title') ?></div>
          <button class="wh-drop-item <?= $activeWhId===0?'active':'' ?>" data-wh-id="0" data-wh-name="<?= e(__('lbl_all').' '.__('wh_title')) ?>" type="button">
            <?= feather_icon('layers',12) ?><?= __('lbl_all') ?> <?= __('wh_title') ?><?php if($activeWhId===0): ?><?= feather_icon('check',11) ?><?php endif; ?>
          </button>
          <?php foreach ($myWarehouses as $w): ?>
            <button class="wh-drop-item <?= $w['id']==$activeWhId?'active':'' ?>" data-wh-id="<?= $w['id'] ?>" data-wh-name="<?= e($w['name']) ?>" type="button">
              <?= feather_icon('home',12) ?><?= e($w['name']) ?><?php if($w['id']==$activeWhId): ?><?= feather_icon('check',11) ?><?php endif; ?>
            </button>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </header>
  <?php if ($canSwitchWh && count($myWarehouses) > 1): ?>
  <script>
  (function(){
    const btn=document.getElementById('whSelectorBtn'),drop=document.getElementById('whSelectorDrop'),lbl=document.getElementById('whSelectorLabel');
    if(!btn)return;
    btn.addEventListener('click',e=>{e.stopPropagation();drop.classList.toggle('open');});
    document.addEventListener('click',()=>drop.classList.remove('open'));
    drop.querySelectorAll('.wh-drop-item').forEach(item=>{
      item.addEventListener('click',()=>{
        fetch('<?= url("modules/pos/set_warehouse.php") ?>',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':'<?= csrf_token() ?>'},body:JSON.stringify({warehouse_id:parseInt(item.dataset.whId)})})
        .then(r=>r.json()).then(d=>{if(!d.success){alert(d.message);return;}lbl.textContent=item.dataset.whName;drop.classList.remove('open');window.location.reload();});
      });
    });
  })();
  </script>
  <?php endif; ?>
  <?php foreach (get_flashes() as $fl): ?>
  <div class="flash flash-<?= e($fl['type']) ?>" role="alert">
    <span class="flash-icon"><?php $icons=['success'=>'check-circle','error'=>'x-circle','warning'=>'alert-triangle','info'=>'info'];echo feather_icon($icons[$fl['type']]??'info',16); ?></span>
    <span><?= e($fl['message']) ?></span>
    <button class="flash-close" onclick="this.closest('.flash').remove()" aria-label="<?= __('btn_close') ?>">×</button>
  </div>
  <?php endforeach; ?>
  <main class="page-content" id="pageContent" data-active-menu-key="<?= e((string)($activeMenuKey ?? '')) ?>">
