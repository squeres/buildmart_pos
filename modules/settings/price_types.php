<?php
require_once dirname(__DIR__, 2) . '/core/bootstrap.php';
Auth::requirePerm('settings');

$pageTitle   = __('pt_title');
$breadcrumbs = [[__('nav_settings'), url('modules/settings/')], [__('pt_title'), null]];

// Handle POST actions
if (is_post()) {
    if (!csrf_verify()) { flash_error(_r('err_csrf')); redirect('/modules/settings/price_types.php'); }

    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id       = (int)($_POST['id'] ?? 0);
        $code     = preg_replace('/[^a-z0-9_]/', '', strtolower(sanitize($_POST['code'] ?? '')));
        $nameEn   = sanitize($_POST['name_en'] ?? '');
        $nameRu   = sanitize($_POST['name_ru'] ?? '');
        $sort     = (int)($_POST['sort_order'] ?? 10);
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $isDef    = isset($_POST['is_default']) ? 1 : 0;
        $visPos   = isset($_POST['visible_in_pos']) ? 1 : 0;
        $visProd  = isset($_POST['visible_in_products']) ? 1 : 0;
        $visRec   = isset($_POST['visible_in_receipts']) ? 1 : 0;
        $color    = sanitize($_POST['color_hex'] ?? '');

        if (!$code || !$nameEn || !$nameRu) {
            flash_error(_r('pt_validation_required'));
            redirect('/modules/settings/price_types.php');
        }

        // If setting as default, unset others
        if ($isDef) {
            Database::exec('UPDATE price_types SET is_default = 0');
        }

        if ($id) {
            Database::exec(
                'UPDATE price_types SET code=?, name_en=?, name_ru=?, sort_order=?, is_active=?,
                 is_default=?, visible_in_pos=?, visible_in_products=?, visible_in_receipts=?, color_hex=?
                 WHERE id=?',
                [$code, $nameEn, $nameRu, $sort, $isActive, $isDef, $visPos, $visProd, $visRec, $color ?: null, $id]
            );
        } else {
            Database::exec(
                'INSERT INTO price_types (code,name_en,name_ru,sort_order,is_active,is_default,visible_in_pos,visible_in_products,visible_in_receipts,color_hex)
                 VALUES (?,?,?,?,?,?,?,?,?,?)',
                [$code, $nameEn, $nameRu, $sort, $isActive, $isDef, $visPos, $visProd, $visRec, $color ?: null]
            );
        }
        flash_success(__('pt_saved'));
        redirect('/modules/settings/price_types.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pt = Database::row('SELECT * FROM price_types WHERE id = ?', [$id]);
        if (!$pt) { flash_error(_r('err_not_found')); redirect('/modules/settings/price_types.php'); }
        if ($pt['is_default']) { flash_error(__('pt_cannot_delete_default')); redirect('/modules/settings/price_types.php'); }
        // Check if used
        $used = (int)Database::value('SELECT COUNT(*) FROM product_prices WHERE price_type_id = ?', [$id]);
        if ($used > 0) { flash_error(_r('pt_cannot_delete_used', ['count' => (string)$used])); redirect('/modules/settings/price_types.php'); }
        Database::exec('DELETE FROM price_types WHERE id = ?', [$id]);
        flash_success(__('pt_deleted'));
        redirect('/modules/settings/price_types.php');
    }

    if ($action === 'save_visibility') {
        $roles = Database::all('SELECT id, slug FROM roles');
        $types = Database::all('SELECT id FROM price_types');
        Database::exec('DELETE FROM role_price_visibility');
        foreach ($roles as $role) {
            foreach ($types as $type) {
                $key = "r{$role['id']}_t{$type['id']}";
                Database::exec(
                    'INSERT INTO role_price_visibility (role_id,price_type_id,can_view,can_edit,in_pos,in_products)
                     VALUES (?,?,?,?,?,?)',
                    [
                        $role['id'], $type['id'],
                        isset($_POST["{$key}_view"])  ? 1 : 0,
                        isset($_POST["{$key}_edit"])  ? 1 : 0,
                        isset($_POST["{$key}_pos"])   ? 1 : 0,
                        isset($_POST["{$key}_prod"])  ? 1 : 0,
                    ]
                );
            }
        }
        flash_success(__('rui_saved'));
        redirect('/modules/settings/price_types.php');
    }
}

$priceTypes = Database::all('SELECT * FROM price_types ORDER BY sort_order, id');
$roles      = Database::all('SELECT id, slug, name FROM roles ORDER BY id');
$editId     = (int)($_GET['edit'] ?? 0);
$editRow    = $editId ? Database::row('SELECT * FROM price_types WHERE id = ?', [$editId]) : null;

// Visibility matrix
$visMatrix = [];
foreach (Database::all('SELECT * FROM role_price_visibility') as $row) {
    $visMatrix[$row['role_id']][$row['price_type_id']] = $row;
}

require_once ROOT_PATH . '/views/layouts/header.php';
?>
<div class="page-header">
  <div>
    <h2><?= __('pt_title') ?></h2>
    <p class="page-subtitle"><?= __('pt_price_visibility') ?></p>
  </div>
  <button class="btn btn-primary" onclick="document.getElementById('addPtModal').style.display='flex'" type="button">
    + <?= __('pt_add') ?>
  </button>
</div>

<!-- Price Types Table -->
<div class="card mb-4">
  <div class="card-body p-0">
    <table class="data-table">
      <thead>
        <tr>
          <th><?= __('pt_code') ?></th>
          <th><?= __('pt_name_en') ?></th>
          <th><?= __('pt_name_ru') ?></th>
          <th><?= __('pt_sort_order') ?></th>
          <th><?= __('pt_visible_pos') ?></th>
          <th><?= __('pt_visible_products') ?></th>
          <th><?= __('pt_is_default') ?></th>
          <th><?= __('lbl_status') ?></th>
          <th><?= __('lbl_actions') ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($priceTypes as $pt): ?>
        <tr>
          <td>
            <code style="font-size:12px;background:#1a1a1a;padding:2px 6px;border-radius:4px">
              <?= e($pt['code']) ?>
            </code>
            <?php if ($pt['color_hex']): ?>
              <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:<?= e($pt['color_hex']) ?>;margin-left:6px;vertical-align:middle"></span>
            <?php endif; ?>
          </td>
          <td><?= e($pt['name_en']) ?></td>
          <td><?= e($pt['name_ru']) ?></td>
          <td><?= $pt['sort_order'] ?></td>
          <td><?= $pt['visible_in_pos'] ? '<span class="badge badge-success">✓</span>' : '<span class="badge badge-secondary">—</span>' ?></td>
          <td><?= $pt['visible_in_products'] ? '<span class="badge badge-success">✓</span>' : '<span class="badge badge-secondary">—</span>' ?></td>
          <td><?= $pt['is_default'] ? '<span class="badge badge-warning">★</span>' : '' ?></td>
          <td><?= $pt['is_active'] ? '<span class="badge badge-success">'.__('lbl_active').'</span>' : '<span class="badge badge-secondary">'.__('lbl_inactive').'</span>' ?></td>
          <td>
            <a href="?edit=<?= $pt['id'] ?>" class="btn btn-secondary btn-xs"><?= __('btn_edit') ?></a>
            <?php if (!$pt['is_default']): ?>
            <form method="post" style="display:inline" onsubmit="return confirm('<?= __('pt_confirm_delete') ?>')">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= $pt['id'] ?>">
              <button class="btn btn-danger btn-xs" type="submit"><?= __('btn_delete') ?></button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Role Visibility Matrix -->
<div class="card">
  <div class="card-header">
    <h3 class="card-title"><?= __('pt_price_visibility') ?></h3>
  </div>
  <div class="card-body">
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_visibility">
      <div style="overflow-x:auto">
        <table class="data-table" style="font-size:12px">
          <thead>
            <tr>
              <th><?= __('lbl_role') ?> / <?= __('pt_title') ?></th>
              <?php foreach ($priceTypes as $pt): ?>
                <th colspan="4" style="text-align:center;border-left:2px solid #333">
                  <?= e($pt['code']) ?>
                </th>
              <?php endforeach; ?>
            </tr>
            <tr>
              <th></th>
              <?php foreach ($priceTypes as $pt): ?>
                <th style="border-left:2px solid #333;font-size:10px;color:#888"><?= __('pt_can_view') ?></th>
                <th style="font-size:10px;color:#888"><?= __('pt_can_edit') ?></th>
                <th style="font-size:10px;color:#888"><?= __('pt_in_pos') ?></th>
                <th style="font-size:10px;color:#888"><?= __('pt_in_products') ?></th>
              <?php endforeach; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($roles as $role): ?>
            <tr>
              <td><strong><?= e($role['name']) ?></strong> <small style="color:#888">(<?= e($role['slug']) ?>)</small></td>
              <?php foreach ($priceTypes as $pt):
                $key = "r{$role['id']}_t{$pt['id']}";
                $v   = $visMatrix[$role['id']][$pt['id']] ?? ['can_view'=>1,'can_edit'=>0,'in_pos'=>$pt['visible_in_pos'],'in_products'=>$pt['visible_in_products']];
              ?>
                <td style="border-left:2px solid #333;text-align:center">
                  <input type="checkbox" name="<?= $key ?>_view" <?= $v['can_view'] ? 'checked' : '' ?>>
                </td>
                <td style="text-align:center"><input type="checkbox" name="<?= $key ?>_edit" <?= $v['can_edit'] ? 'checked' : '' ?>></td>
                <td style="text-align:center"><input type="checkbox" name="<?= $key ?>_pos" <?= $v['in_pos'] ? 'checked' : '' ?>></td>
                <td style="text-align:center"><input type="checkbox" name="<?= $key ?>_prod" <?= $v['in_products'] ? 'checked' : '' ?>></td>
              <?php endforeach; ?>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div style="margin-top:16px">
        <button class="btn btn-primary" type="submit"><?= __('btn_save') ?></button>
      </div>
    </form>
  </div>
</div>

<!-- Add/Edit Modal -->
<?php $e = $editRow; ?>
<div id="addPtModal" style="display:<?= $e ? 'flex' : 'none' ?>;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:2000;align-items:center;justify-content:center">
  <div class="card" style="width:480px;max-width:95vw;max-height:90vh;overflow-y:auto">
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between">
      <h3 class="card-title"><?= $e ? __('pt_edit') : __('pt_add') ?></h3>
      <button type="button" onclick="document.getElementById('addPtModal').style.display='none'" style="background:none;border:none;color:#888;cursor:pointer;font-size:20px" aria-label="<?= __('btn_close') ?>">×</button>
    </div>
    <div class="card-body">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= $e['id'] ?? 0 ?>">

        <div class="form-row" style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
          <div class="form-group">
            <label class="form-label"><?= __('pt_code') ?> *</label>
            <input type="text" name="code" class="form-control" required
                   value="<?= e($e['code'] ?? '') ?>"
                   placeholder="<?= __('pt_code_placeholder') ?>"
                   pattern="[a-z0-9_]+" title="<?= __('pt_code_pattern_hint') ?>">
            <div class="form-hint"><?= __('pt_code_hint') ?></div>
          </div>
          <div class="form-group">
            <label class="form-label"><?= __('pt_sort_order') ?></label>
            <input type="number" name="sort_order" class="form-control" value="<?= $e['sort_order'] ?? 10 ?>" min="1">
          </div>
        </div>

        <div class="form-group">
          <label class="form-label"><?= __('pt_name_en') ?> *</label>
          <input type="text" name="name_en" class="form-control" required value="<?= e($e['name_en'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label class="form-label"><?= __('pt_name_ru') ?> *</label>
          <input type="text" name="name_ru" class="form-control" required value="<?= e($e['name_ru'] ?? '') ?>">
        </div>

        <div class="form-group">
          <label class="form-label"><?= __('pt_color') ?></label>
          <input type="color" name="color_hex" class="form-control" style="height:36px;padding:2px 6px"
                 value="<?= e($e['color_hex'] ?? '#10b981') ?>">
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:8px">
          <?php $checks = [
            ['name'=>'is_active',           'label'=>__('lbl_active'),            'val'=>$e['is_active'] ?? 1],
            ['name'=>'is_default',          'label'=>__('pt_is_default'),          'val'=>$e['is_default'] ?? 0],
            ['name'=>'visible_in_pos',      'label'=>__('pt_visible_pos'),         'val'=>$e['visible_in_pos'] ?? 1],
            ['name'=>'visible_in_products', 'label'=>__('pt_visible_products'),    'val'=>$e['visible_in_products'] ?? 1],
            ['name'=>'visible_in_receipts', 'label'=>__('pt_visible_receipts'),    'val'=>$e['visible_in_receipts'] ?? 0],
          ]; ?>
          <?php foreach ($checks as $ck): ?>
          <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px">
            <input type="checkbox" name="<?= $ck['name'] ?>" <?= $ck['val'] ? 'checked' : '' ?>>
            <?= $ck['label'] ?>
          </label>
          <?php endforeach; ?>
        </div>

        <div style="display:flex;gap:10px;margin-top:20px">
          <button type="submit" class="btn btn-primary"><?= __('btn_save') ?></button>
          <button type="button" class="btn btn-secondary"
                  onclick="document.getElementById('addPtModal').style.display='none'"><?= __('btn_cancel') ?></button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php require_once ROOT_PATH . '/views/layouts/footer.php'; ?>
