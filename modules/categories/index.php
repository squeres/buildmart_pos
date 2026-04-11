<?php
require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../views/partials/icons.php';
Auth::requireLogin();
Auth::requirePerm('categories');
$canManageCategories = Auth::can('categories.manage');

$pageTitle   = __('nav_categories');
$breadcrumbs = [[$pageTitle, null]];
$errors      = [];
$editCat     = null;

// Handle POST (add, edit, delete)
if (is_post()) {
    if (!$canManageCategories) {
        http_response_code(403);
        include ROOT_PATH . '/views/partials/403.php';
        exit;
    }
    require_csrf($_SERVER['REQUEST_URI']);

    $formAction = sanitize($_POST['form_action'] ?? 'save');

    if ($formAction === 'delete') {
        $delId   = (int)($_POST['delete_id'] ?? 0);
        $prodCnt = Database::value("SELECT COUNT(*) FROM products WHERE category_id=?", [$delId]);
        if ($prodCnt) {
            flash_error(_r('err_delete_in_use'));
        } elseif ($delId > 0) {
            Database::exec("DELETE FROM categories WHERE id=?", [$delId]);
            flash_success(_r('prod_deleted'));
        }
        redirect('/modules/categories/');
    }

    $editId  = (int)($_POST['edit_id'] ?? 0);
    $name    = sanitize($_POST['name'] ?? '');
    $nameEn  = $name !== '' ? $name : sanitize($_POST['name_en'] ?? '');
    $nameRu  = $name !== '' ? $name : sanitize($_POST['name_ru'] ?? '');
    $icon    = sanitize($_POST['icon']    ?? 'box');
    $color   = sanitize($_POST['color']   ?? '#607D8B');
    $sort    = (int)($_POST['sort_order'] ?? 0);
    $active  = isset($_POST['is_active']) ? 1 : 0;

    $nameEn = unified_name_value($nameRu, $nameEn);
    $nameRu = $nameEn;
    if (!$nameEn) $errors['name'] = _r('lbl_required');

    if (!$errors) {
        if ($editId) {
            Database::exec(
                "UPDATE categories SET name_en=?,name_ru=?,icon=?,color=?,sort_order=?,is_active=? WHERE id=?",
                [$nameEn,$nameRu,$icon,$color,$sort,$active,$editId]
            );
            flash_success(_r('btn_save') . '!');
        } else {
            Database::insert(
                "INSERT INTO categories (name_en,name_ru,icon,color,sort_order,is_active) VALUES (?,?,?,?,?,?)",
                [$nameEn,$nameRu,$icon,$color,$sort,$active]
            );
            flash_success(_r('btn_save') . '!');
        }
        redirect('/modules/categories/');
    }
}

// Load for edit
$editId = (int)($_GET['edit'] ?? 0);
if ($editId && !$canManageCategories) {
    http_response_code(403);
    include ROOT_PATH . '/views/partials/403.php';
    exit;
}
if ($editId) $editCat = Database::row("SELECT * FROM categories WHERE id=?", [$editId]);

$categories = Database::all(
    "SELECT c.*, (SELECT COUNT(*) FROM products p WHERE p.category_id=c.id AND p.is_active=1) AS product_count
     FROM categories c ORDER BY c.sort_order, c.name_en"
);

$featherIcons = ['box','layers','grid','tag','package','tool','droplet','zap','home','settings',
                 'shopping-bag','shield','align-justify','droplets','database','archive'];
$featherIconLabels = [
    'box' => __('icon_box'),
    'layers' => __('icon_layers'),
    'grid' => __('icon_grid'),
    'tag' => __('icon_tag'),
    'package' => __('icon_package'),
    'tool' => __('icon_tool'),
    'droplet' => __('icon_droplet'),
    'zap' => __('icon_zap'),
    'home' => __('icon_home'),
    'settings' => __('icon_settings'),
    'shopping-bag' => __('icon_shopping_bag'),
    'shield' => __('icon_shield'),
    'align-justify' => __('icon_align_justify'),
    'droplets' => __('icon_droplets'),
    'database' => __('icon_database'),
    'archive' => __('icon_archive'),
];

include __DIR__ . '/../../views/layouts/header.php';
?>

<div class="page-header">
  <h1 class="page-heading"><?= __('nav_categories') ?></h1>
</div>

<div class="content-split content-split-sidebar-wide">

  <!-- Category list -->
  <div class="card">
    <div class="table-wrap mobile-table-wrap hide-on-mobile">
      <table class="table">
        <thead>
          <tr>
            <th><?= __('lbl_name') ?></th>
            <th><?= __('cat_icon') ?></th>
            <th class="col-num"><?= __('nav_products') ?></th>
            <th><?= __('lbl_status') ?></th>
            <th class="col-actions"><?= __('lbl_actions') ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($categories as $c): ?>
          <tr>
            <td>
              <span style="display:inline-flex;align-items:center;gap:8px">
                <span style="width:10px;height:10px;border-radius:50%;background:<?= e($c['color']) ?>;flex-shrink:0;display:inline-block"></span>
                <strong><?= e(category_name($c)) ?></strong>
              </span>
            </td>
            <td><span class="text-muted" style="font-size:12px"><?= e($featherIconLabels[$c['icon']] ?? $c['icon']) ?></span></td>
            <td class="col-num"><?= $c['product_count'] ?></td>
            <td><?= $c['is_active'] ? '<span class="badge badge-success">'.__('lbl_active').'</span>' : '<span class="badge badge-secondary">'.__('lbl_inactive').'</span>' ?></td>
            <td class="col-actions">
              <?php if ($canManageCategories): ?>
                <a href="?edit=<?= $c['id'] ?>" class="btn btn-sm btn-ghost btn-icon" title="<?= __('btn_edit') ?>"><?= feather_icon('edit-2',14) ?></a>
                <?php if ($c['product_count'] == 0): ?>
                <form method="POST" class="inline-action-form">
                  <?= csrf_field() ?>
                  <input type="hidden" name="form_action" value="delete">
                  <input type="hidden" name="delete_id" value="<?= (int)$c['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-ghost btn-icon" style="color:var(--danger)"
                     data-confirm="<?= __('confirm_delete') ?>" title="<?= __('btn_delete') ?>"><?= feather_icon('trash-2',14) ?></button>
                </form>
                <?php endif; ?>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="mobile-list show-on-mobile">
      <?php if (!$categories): ?>
        <div class="empty-state">
          <div class="empty-state-icon"><?= feather_icon('layers', 36) ?></div>
          <div class="empty-state-title"><?= __('no_results') ?></div>
        </div>
      <?php else: ?>
        <?php foreach ($categories as $c): ?>
          <div class="mobile-card">
            <div class="mobile-card__header">
              <div class="mobile-record-main">
                <div class="mobile-record-title"><?= e(category_name($c)) ?></div>
              </div>
              <div class="mobile-badge-row">
                <?= $c['is_active'] ? '<span class="badge badge-success">'.__('lbl_active').'</span>' : '<span class="badge badge-secondary">'.__('lbl_inactive').'</span>' ?>
              </div>
            </div>

            <div class="mobile-card__meta">
              <div class="mobile-card__row">
                <span class="mobile-card__row-label"><?= __('cat_icon') ?></span>
                <span class="mobile-card__row-value text-left">
                  <span class="category-visual">
                    <span class="category-visual-dot" style="background:<?= e($c['color']) ?>"></span>
                    <?= feather_icon($c['icon'], 14) ?>
                    <span><?= e($featherIconLabels[$c['icon']] ?? $c['icon']) ?></span>
                  </span>
                </span>
              </div>
              <div class="mobile-card__row">
                <span class="mobile-card__row-label"><?= __('nav_products') ?></span>
                <span class="mobile-card__row-value"><?= (int)$c['product_count'] ?></span>
              </div>
              <div class="mobile-card__row">
                <span class="mobile-card__row-label"><?= __('cat_sort_order') ?></span>
                <span class="mobile-card__row-value"><?= (int)$c['sort_order'] ?></span>
              </div>
            </div>

            <?php if ($canManageCategories): ?>
              <div class="mobile-actions">
                <a href="?edit=<?= $c['id'] ?>" class="btn btn-secondary">
                  <?= feather_icon('edit-2', 14) ?> <?= __('btn_edit') ?>
                </a>
                <?php if ((int)$c['product_count'] === 0): ?>
                  <form method="POST" class="inline-action-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="form_action" value="delete">
                    <input type="hidden" name="delete_id" value="<?= (int)$c['id'] ?>">
                    <button type="submit" class="btn btn-ghost" style="color:var(--danger)" data-confirm="<?= __('confirm_delete') ?>">
                      <?= feather_icon('trash-2', 14) ?> <?= __('btn_delete') ?>
                    </button>
                  </form>
                <?php endif; ?>
              </div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- Add/Edit form -->
  <?php if ($canManageCategories): ?>
    <div class="card">
      <div class="card-header">
        <span class="card-title"><?= $editCat ? __('btn_edit') : __('btn_add') ?> <?= __('nav_categories') ?></span>
        <?php if ($editCat): ?>
          <a href="<?= url('modules/categories/') ?>" class="btn btn-sm btn-ghost"><?= __('btn_cancel') ?></a>
        <?php endif; ?>
      </div>
      <div class="card-body">
        <form method="POST" class="mobile-form">
          <?= csrf_field() ?>
          <input type="hidden" name="form_action" value="save">
          <?php if ($editCat): ?>
            <input type="hidden" name="edit_id" value="<?= $editCat['id'] ?>">
          <?php endif; ?>

          <div class="form-group">
            <label class="form-label"><?= __('lbl_name') ?> <span class="req">*</span></label>
            <input type="text" name="name" class="form-control" value="<?= e(unified_name_value($editCat['name_ru'] ?? '', $editCat['name_en'] ?? '')) ?>" required>
            <?php if (isset($errors['name'])): ?><div class="form-error"><?= e($errors['name']) ?></div><?php endif; ?>
          </div>
          <div class="form-row form-row-2">
            <div class="form-group">
              <label class="form-label"><?= __('cat_icon') ?></label>
              <select name="icon" class="form-control">
                <?php foreach ($featherIcons as $ico): ?>
                  <option value="<?= $ico ?>" <?= ($editCat['icon']??'box')===$ico?'selected':'' ?>><?= e($featherIconLabels[$ico] ?? $ico) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label"><?= __('cat_color') ?></label>
              <input type="color" name="color" class="form-control category-color-control" value="<?= e($editCat['color'] ?? '#607D8B') ?>">
            </div>
          </div>
          <div class="form-row form-row-2">
            <div class="form-group">
              <label class="form-label"><?= __('cat_sort_order') ?></label>
              <input type="number" name="sort_order" class="form-control" value="<?= e($editCat['sort_order'] ?? 0) ?>" min="0">
            </div>
            <div class="form-group" style="display:flex;align-items:flex-end">
              <label class="form-check">
                <input type="checkbox" name="is_active" value="1" <?= ($editCat['is_active'] ?? 1) ? 'checked' : '' ?>>
                <span class="form-check-label"><?= __('lbl_active') ?></span>
              </label>
            </div>
          </div>
          <div class="mobile-form-actions">
            <button type="submit" class="btn btn-primary btn-block"><?= feather_icon('save',15) ?> <?= __('btn_save') ?></button>
          </div>
        </form>
      </div>
    </div>
  <?php endif; ?>

</div>

<?php include __DIR__ . '/../../views/layouts/footer.php'; ?>
<script src="https://unpkg.com/feather-icons/dist/feather.min.js"></script>
<script>feather.replace();</script>
