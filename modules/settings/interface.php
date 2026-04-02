<?php
require_once dirname(__DIR__, 2) . '/core/bootstrap.php';
Auth::requirePerm('settings');

$pageTitle   = __('set_ui_title');
$breadcrumbs = [[__('nav_settings'), url('modules/settings/')], [__('set_ui_title'), null]];

if (is_post() && csrf_verify()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_role_ui') {
        $roleId  = (int)($_POST['role_id'] ?? 0);
        $module  = 'global';
        $visible = $_POST['visible_prices'] ?? [];
        $settings = [
            'visible_prices'         => $visible,
            'can_see_purchase_price' => isset($_POST['can_see_purchase']) ? 1 : 0,
            'can_see_profit'         => isset($_POST['can_see_profit']) ? 1 : 0,
            'dashboard_widgets'      => $_POST['dashboard_widgets'] ?? [],
        ];
        UISettings::saveRoleSettings($roleId, $module, $settings);
        flash_success(__('rui_saved'));
        redirect('/modules/settings/interface.php?tab=roles');
    }

    if ($action === 'save_wh_ui') {
        $whId   = (int)($_POST['warehouse_id'] ?? 0);
        $module = $_POST['module'] ?? 'pos';
        $settings = [
            'view_mode'             => sanitize($_POST['view_mode'] ?? 'cards'),
            'price_type'            => sanitize($_POST['price_type'] ?? 'retail'),
            'price_types_visible'   => $_POST['price_types_visible'] ?? ['retail'],
            'show_sku'              => isset($_POST['show_sku']) ? 1 : 0,
            'show_stock'            => isset($_POST['show_stock']) ? 1 : 0,
        ];
        UISettings::saveWarehouseSettings($whId, $module, $settings);
        flash_success(__('wui_saved'));
        redirect('/modules/settings/interface.php?tab=warehouses');
    }
}

$roles      = Database::all('SELECT id, slug, name FROM roles ORDER BY id');
$warehouses = Database::all('SELECT id, name FROM warehouses WHERE is_active=1 ORDER BY name');
$priceTypes = UISettings::allActivePriceTypes();
$activeTab  = $_GET['tab'] ?? 'roles';

// Load current settings per role
$roleSettings = [];
foreach ($roles as $r) {
    $row = Database::row('SELECT settings_json FROM role_ui_settings WHERE role_id=? AND module="global" LIMIT 1', [$r['id']]);
    $roleSettings[$r['id']] = $row ? (json_decode($row['settings_json'], true) ?: []) : [];
}

$allWidgets = [
    'revenue_today','profit_today','sales_today','avg_receipt',
    'low_stock','out_of_stock','recent_sales','best_sellers',
    'pending_acceptance','active_shift','quick_actions','shift_status',
    'revenue_chart','category_chart',
];

require_once ROOT_PATH . '/views/layouts/header.php';
?>

<div class="page-header">
  <div>
    <h2><?= __('set_ui_title') ?></h2>
    <p class="page-subtitle"><?= __('set_ui_title') ?></p>
  </div>
  <a href="<?= url('modules/settings/price_types.php') ?>" class="btn btn-secondary">
    <?= __('set_ui_price_types') ?> →
  </a>
</div>

<!-- Tabs -->
<div style="display:flex;gap:4px;margin-bottom:20px;background:#1a1a1a;border-radius:10px;padding:4px;width:fit-content">
  <?php foreach (['roles'=>__('set_ui_role_presets'), 'warehouses'=>__('set_ui_wh_presets')] as $tab => $label): ?>
  <a href="?tab=<?= $tab ?>"
     style="padding:7px 18px;border-radius:7px;font-size:13px;font-weight:500;text-decoration:none;transition:all .15s;<?= $activeTab===$tab ? 'background:#2a2a2a;color:#f59e0b' : 'color:#888' ?>">
    <?= $label ?>
  </a>
  <?php endforeach; ?>
</div>

<?php if ($activeTab === 'roles'): ?>
<!-- Role UI Settings -->
<div style="display:grid;gap:16px">
  <?php foreach ($roles as $role):
    $s = $roleSettings[$role['id']] ?? [];
    $visiblePrices   = $s['visible_prices']          ?? ['retail'];
    $canSeePurchase  = $s['can_see_purchase_price']   ?? 0;
    $canSeeProfit    = $s['can_see_profit']            ?? 0;
    $dashWidgets     = $s['dashboard_widgets']         ?? [];
  ?>
  <div class="card">
    <div class="card-header">
      <h3 class="card-title"><?= e($role['name']) ?> <small style="color:#888;font-weight:400">(<?= e($role['slug']) ?>)</small></h3>
    </div>
    <div class="card-body">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_role_ui">
        <input type="hidden" name="role_id" value="<?= $role['id'] ?>">

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">
          <div>
            <div class="form-label" style="margin-bottom:8px"><?= __('rui_visible_prices') ?></div>
            <?php foreach ($priceTypes as $pt): ?>
            <label style="display:flex;align-items:center;gap:8px;margin-bottom:6px;cursor:pointer;font-size:13px">
              <input type="checkbox" name="visible_prices[]" value="<?= e($pt['code']) ?>"
                     <?= in_array($pt['code'], $visiblePrices) ? 'checked' : '' ?>>
              <?php if ($pt['color_hex']): ?>
                <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:<?= e($pt['color_hex']) ?>"></span>
              <?php endif; ?>
              <?= e(UISettings::priceTypeName($pt)) ?>
            </label>
            <?php endforeach; ?>

            <div style="margin-top:12px">
              <label style="display:flex;align-items:center;gap:8px;margin-bottom:6px;cursor:pointer;font-size:13px">
                <input type="checkbox" name="can_see_purchase" <?= $canSeePurchase ? 'checked' : '' ?>>
                <?= __('rui_can_see_purchase') ?>
              </label>
              <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px">
                <input type="checkbox" name="can_see_profit" <?= $canSeeProfit ? 'checked' : '' ?>>
                <?= __('rui_can_see_profit') ?>
              </label>
            </div>
          </div>

          <div>
            <div class="form-label" style="margin-bottom:8px"><?= __('rui_dashboard_widgets') ?></div>
            <?php foreach ($allWidgets as $wk): ?>
            <label style="display:flex;align-items:center;gap:8px;margin-bottom:5px;cursor:pointer;font-size:12px">
              <input type="checkbox" name="dashboard_widgets[]" value="<?= e($wk) ?>"
                     <?= in_array($wk, $dashWidgets) ? 'checked' : '' ?>>
              <?= __('wdg_' . $wk) ?>
            </label>
            <?php endforeach; ?>
          </div>
        </div>

        <div style="margin-top:16px">
          <button class="btn btn-primary btn-sm" type="submit"><?= __('btn_save') ?></button>
        </div>
      </form>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<?php elseif ($activeTab === 'warehouses'): ?>
<!-- Warehouse UI Settings -->
<div style="display:grid;gap:16px">
  <?php foreach ($warehouses as $wh):
    $whPosCfg = UISettings::get('pos', (int)$wh['id']);
    $vp = $whPosCfg['price_types_visible'] ?? ['retail'];
  ?>
  <div class="card">
    <div class="card-header">
      <h3 class="card-title"><?= e($wh['name']) ?></h3>
    </div>
    <div class="card-body">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_wh_ui">
        <input type="hidden" name="warehouse_id" value="<?= $wh['id'] ?>">
        <input type="hidden" name="module" value="pos">

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px">
          <div class="form-group">
            <label class="form-label"><?= __('ui_view_mode') ?></label>
            <select name="view_mode" class="form-control">
              <option value="cards"   <?= $whPosCfg['view_mode']==='cards'   ? 'selected':'' ?>><?= __('pos_view_cards') ?></option>
              <option value="list"    <?= $whPosCfg['view_mode']==='list'    ? 'selected':'' ?>><?= __('pos_view_list') ?></option>
              <option value="compact" <?= $whPosCfg['view_mode']==='compact' ? 'selected':'' ?>><?= __('pos_view_compact') ?></option>
            </select>
          </div>

          <div class="form-group">
            <label class="form-label"><?= __('wui_default_price_type') ?></label>
            <select name="price_type" class="form-control">
              <?php foreach ($priceTypes as $pt): ?>
              <option value="<?= e($pt['code']) ?>" <?= $whPosCfg['price_type']===$pt['code'] ? 'selected':'' ?>>
                <?= e(UISettings::priceTypeName($pt)) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group">
            <label class="form-label"><?= __('wui_visible_prices') ?></label>
            <?php foreach ($priceTypes as $pt): ?>
            <label style="display:flex;align-items:center;gap:8px;margin-bottom:4px;font-size:12px;cursor:pointer">
              <input type="checkbox" name="price_types_visible[]" value="<?= e($pt['code']) ?>"
                     <?= in_array($pt['code'], $vp) ? 'checked' : '' ?>>
              <?= e(UISettings::priceTypeName($pt)) ?>
            </label>
            <?php endforeach; ?>
          </div>

          <div class="form-group">
            <label class="form-label"><?= __('lbl_options') ?></label>
            <label style="display:flex;align-items:center;gap:8px;margin-bottom:6px;font-size:13px;cursor:pointer">
              <input type="checkbox" name="show_sku" <?= !empty($whPosCfg['show_sku']) ? 'checked':'' ?>>
              <?= __('pos_show_sku_pos') ?>
            </label>
            <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer">
              <input type="checkbox" name="show_stock" <?= !empty($whPosCfg['show_stock']) ? 'checked':'' ?>>
              <?= __('pos_show_stock_qty') ?>
            </label>
          </div>
        </div>

        <div style="margin-top:16px">
          <button class="btn btn-primary btn-sm" type="submit"><?= __('btn_save') ?></button>
        </div>
      </form>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php require_once ROOT_PATH . '/views/layouts/footer.php'; ?>
