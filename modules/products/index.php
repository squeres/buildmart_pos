<?php
require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../views/partials/icons.php';
Auth::requireLogin();
Auth::requirePerm('products');

$pageTitle   = __('prod_title');
$breadcrumbs = [[$pageTitle, null]];
$viewCfg     = UISettings::get('products');
$priceTypes  = UISettings::visiblePriceTypes('products');

$sortMap = [
    'name' => 'p.name_en',
    'sku' => 'p.sku',
    'category' => 'c.name_en',
    'replenishment' => replenishment_has_product_column('replenishment_class')
        ? "FIELD(p.replenishment_class,'A','B','C')"
        : "'C'",
    'stock' => 'p.stock_qty',
    'min_stock' => 'p.min_stock_qty',
    'status' => 'p.is_active',
    'price_retail' => 'p.sale_price',
    'price_purchase' => 'p.cost_price',
];
$sortBy = $viewCfg['sort_by'] ?? 'name';
$sortDir = strtolower($viewCfg['sort_dir'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';
$perPage = max(15, min(100, (int)($viewCfg['per_page'] ?? 30)));

$allColumns = [
    ['key' => 'name', 'label' => __('lbl_name')],
    ['key' => 'sku', 'label' => __('lbl_sku')],
    ['key' => 'category', 'label' => __('lbl_category')],
    ['key' => 'replenishment', 'label' => __('repl_class')],
    ['key' => 'unit', 'label' => __('lbl_unit')],
    ['key' => 'stock', 'label' => __('prod_stock_qty')],
    ['key' => 'min_stock', 'label' => __('prod_min_stock')],
    ['key' => 'status', 'label' => __('lbl_status')],
];
foreach ($priceTypes as $priceType) {
    $code = $priceType['code'];
    $allColumns[] = [
        'key' => 'price_' . $code,
        'label' => $code === 'retail'
            ? __('prod_sale_price')
            : ($code === 'purchase' ? __('prod_cost_price') : e(UISettings::priceTypeName($priceType))),
    ];
}
$allColumns[] = ['key' => 'actions', 'label' => __('lbl_actions')];
$allColumnKeys = array_column($allColumns, 'key');
$legacyColumnMap = ['retail' => 'price_retail', 'purchase' => 'price_purchase'];
$visibleColumns = array_map(
    fn ($column) => $legacyColumnMap[$column] ?? $column,
    $viewCfg['columns'] ?? ['name','sku','category','replenishment','price_purchase','price_retail','stock','min_stock','status','actions']
);
$visibleColumns = array_values(array_filter($visibleColumns, fn ($column) => in_array($column, $allColumnKeys, true)));
if (!$visibleColumns) {
    $visibleColumns = ['name','sku','category','replenishment','price_purchase','price_retail','stock','min_stock','status','actions'];
}

// Filters
$search       = sanitize($_GET['search'] ?? '');
$catId        = (int)($_GET['cat'] ?? 0);
$legacyStatus = sanitize($_GET['status'] ?? '');
$mode         = sanitize($_GET['mode'] ?? '');
$stockFilter  = sanitize($_GET['stock'] ?? '');
$page         = max(1, (int)($_GET['page'] ?? 1));

if ($mode === '' && in_array($legacyStatus, ['active', 'inactive', 'all'], true)) {
    $mode = $legacyStatus;
}
if ($stockFilter === '' && in_array($legacyStatus, ['low', 'out'], true)) {
    $stockFilter = $legacyStatus;
}
if (!in_array($mode, ['active', 'inactive', 'all'], true)) {
    $mode = 'active';
}
if (!in_array($stockFilter, ['', 'low', 'out'], true)) {
    $stockFilter = '';
}

$baseWhere  = ['1=1'];
$baseParams = [];

if ($search !== '') {
    $like = '%' . $search . '%';
    $baseWhere[] = '(p.name_en LIKE ? OR p.name_ru LIKE ? OR p.sku LIKE ? OR p.barcode LIKE ? OR p.brand LIKE ?)';
    array_push($baseParams, $like, $like, $like, $like, $like);
}
if ($catId > 0) {
    $baseWhere[] = 'p.category_id=?';
    $baseParams[] = $catId;
}
if ($stockFilter === 'low') {
    $baseWhere[] = 'p.min_stock_qty>0 AND p.stock_qty<=p.min_stock_qty';
}
if ($stockFilter === 'out') {
    $baseWhere[] = 'p.stock_qty<=0';
}

$baseWhereSQL = implode(' AND ', $baseWhere);
$countAll = (int)Database::value("SELECT COUNT(*) FROM products p WHERE $baseWhereSQL", $baseParams);
$countActive = (int)Database::value("SELECT COUNT(*) FROM products p WHERE $baseWhereSQL AND p.is_active=1", $baseParams);
$countInactive = (int)Database::value("SELECT COUNT(*) FROM products p WHERE $baseWhereSQL AND p.is_active=0", $baseParams);

$where = $baseWhere;
$params = $baseParams;
if ($mode === 'active') {
    $where[] = 'p.is_active=1';
} elseif ($mode === 'inactive') {
    $where[] = 'p.is_active=0';
}

$whereSQL = implode(' AND ', $where);
$total    = (int)Database::value("SELECT COUNT(*) FROM products p WHERE $whereSQL", $params);
$pg       = paginate($total, $page, $perPage);
$orderBy  = $sortMap[$sortBy] ?? 'p.name_en';

$products = Database::all(
    "SELECT p.*, c.name_en AS cat_en, c.name_ru AS cat_ru,
            EXISTS(SELECT 1 FROM inventory_movements im WHERE im.product_id = p.id LIMIT 1) AS has_moves
     FROM products p JOIN categories c ON c.id=p.category_id
     WHERE $whereSQL
     ORDER BY {$orderBy} {$sortDir}, p.id DESC
     LIMIT {$perPage} OFFSET {$pg['offset']}",
    $params
);
$priceMap = UISettings::productPricesMap(array_column($products, 'id'));
$displayMap = [];
foreach ($products as $productRow) {
    $units = product_units((int)$productRow['id'], $productRow['unit']);
    $displayMap[(int)$productRow['id']] = [
        'units' => $units,
        'default_unit' => product_default_unit((int)$productRow['id'], $productRow['unit']),
        'overrides' => product_unit_price_overrides((int)$productRow['id']),
        'stock_display' => product_stock_breakdown((float)$productRow['stock_qty'], $units, $productRow['unit']),
        'min_stock' => product_min_stock_data($productRow, $units),
        'replenishment' => product_replenishment_state($productRow, (float)$productRow['stock_qty'], $units),
    ];
}

$categories = Database::all("SELECT id,name_en,name_ru FROM categories WHERE is_active=1 ORDER BY name_en");
$currentListUrl = (string)($_SERVER['REQUEST_URI'] ?? '/modules/products/');
if (!str_starts_with($currentListUrl, '/modules/products/')) {
    $currentListUrl = '/modules/products/';
}
$modeMeta = [
    'active' => [
        'label' => __('prod_active_items'),
        'hint' => __('prod_active_items_hint'),
        'count' => $countActive,
    ],
    'inactive' => [
        'label' => __('prod_inactive_items'),
        'hint' => __('prod_inactive_items_hint'),
        'count' => $countInactive,
    ],
    'all' => [
        'label' => __('prod_all_items'),
        'hint' => __('prod_all_items_hint'),
        'count' => $countAll,
    ],
];
$buildModeUrl = static function (string $targetMode) use ($search, $catId, $stockFilter): string {
    $query = ['mode' => $targetMode];
    if ($search !== '') {
        $query['search'] = $search;
    }
    if ($catId > 0) {
        $query['cat'] = $catId;
    }
    if ($stockFilter !== '') {
        $query['stock'] = $stockFilter;
    }
    return '?' . http_build_query($query);
};

include __DIR__ . '/../../views/layouts/header.php';
?>

<div class="page-header">
  <h1 class="page-heading"><?= __('prod_title') ?></h1>
  <div class="page-actions">
    <?php if (in_array(Auth::role(), ['admin', 'manager'])): ?>
      <a href="<?= url('modules/products/import.php') ?>" class="btn btn-ghost">
        <?= feather_icon('upload', 14) ?> <?= __('prod_import') ?>
      </a>
      <a href="<?= url('modules/products/export.php') ?>" class="btn btn-ghost">
        <?= feather_icon('download', 14) ?> <?= __('btn_export') ?>
      </a>
      <a href="<?= url('modules/products/template.php') ?>" class="btn btn-ghost">
        <?= feather_icon('file-text', 14) ?> <?= __('prod_template') ?>
      </a>
    <?php endif; ?>
    <a href="<?= url('modules/products/add.php') ?>" class="btn btn-primary">
      <?= feather_icon('plus', 15) ?> <?= __('prod_add') ?>
    </a>
    <button type="button" class="btn btn-ghost" id="productsViewConfigBtn">
      <?= feather_icon('sliders', 15) ?> <?= __('ui_configure_view') ?>
    </button>
  </div>
</div>

<div class="products-folder-bar mb-2">
  <div class="view-switcher products-folder-switcher">
    <?php foreach (['active', 'inactive', 'all'] as $folderMode): ?>
      <a href="<?= e($buildModeUrl($folderMode)) ?>" class="btn btn-sm <?= $mode === $folderMode ? 'active' : 'btn-ghost' ?>">
        <span><?= e($modeMeta[$folderMode]['label']) ?></span>
        <span class="products-folder-count"><?= (int)$modeMeta[$folderMode]['count'] ?></span>
      </a>
    <?php endforeach; ?>
  </div>
  <div class="products-folder-summary">
    <div class="products-folder-title"><?= e($modeMeta[$mode]['label']) ?></div>
    <div class="products-folder-hint"><?= e($modeMeta[$mode]['hint']) ?></div>
  </div>
</div>

<!-- Filters -->
<form method="GET" class="filter-bar mb-2">
  <input type="hidden" name="mode" value="<?= e($mode) ?>">
  <input type="text" name="search" class="form-control" placeholder="<?= __('btn_search') ?>..." value="<?= e($search) ?>" style="max-width:220px">
  <select name="cat" class="form-control" style="max-width:180px">
    <option value=""><?= __('lbl_all') ?> <?= __('nav_categories') ?></option>
    <?php foreach ($categories as $c): ?>
      <option value="<?= $c['id'] ?>" <?= $catId==$c['id']?'selected':'' ?>><?= e(category_name($c)) ?></option>
    <?php endforeach; ?>
  </select>
  <select name="stock" class="form-control" style="max-width:180px">
    <option value=""><?= __('lbl_all') ?></option>
    <option value="low" <?= $stockFilter === 'low' ? 'selected' : '' ?>><?= __('low_stock') ?></option>
    <option value="out" <?= $stockFilter === 'out' ? 'selected' : '' ?>><?= __('out_of_stock') ?></option>
  </select>
  <button type="submit" class="btn btn-secondary"><?= feather_icon('search',14) ?> <?= __('btn_filter') ?></button>
  <a href="<?= url('modules/products/') ?>" class="btn btn-ghost"><?= __('btn_reset') ?></a>
</form>

<div class="card">
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th></th>
          <?php foreach ($visibleColumns as $column): ?>
            <?php
              $isPrice = str_starts_with($column, 'price_');
              $isNum = in_array($column, ['stock'], true) || $isPrice;
              $isActions = $column === 'actions';
              $label = '';
              foreach ($allColumns as $meta) {
                  if ($meta['key'] === $column) {
                      $label = $meta['label'];
                      break;
                  }
              }
            ?>
            <th class="<?= $isActions ? 'col-actions' : ($isNum ? 'col-num' : '') ?>"><?= $label ?></th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php if (!$products): ?>
          <tr><td colspan="<?= 1 + count($visibleColumns) ?>" class="text-center text-muted" style="padding:40px"><?= __('no_results') ?></td></tr>
        <?php else: ?>
          <?php foreach ($products as $p): ?>
          <tr class="<?= !$p['is_active'] ? 'product-row-inactive' : '' ?>">
            <td style="width:44px">
              <?php if ($p['image']): ?>
                <img src="<?= e(UPLOAD_URL . $p['image']) ?>" alt=""
                     style="width:40px;height:40px;object-fit:cover;border-radius:6px;border:1px solid var(--border-dim)">
              <?php else: ?>
                <div style="width:40px;height:40px;background:var(--bg-raised);border-radius:6px;display:flex;align-items:center;justify-content:center;color:var(--text-muted)">
                  <?= feather_icon('package', 18) ?>
                </div>
              <?php endif; ?>
            </td>
            <?php foreach ($visibleColumns as $column): ?>
              <?php if ($column === 'name'): ?>
                <td>
                  <div class="fw-600"><?= e(product_name($p)) ?></div>
                  <?php if ($p['brand']): ?>
                    <div class="text-muted fs-sm"><?= e($p['brand']) ?></div>
                  <?php endif; ?>
                </td>
              <?php elseif ($column === 'sku'): ?>
                <td class="font-mono" style="font-size:12px"><?= e($p['sku']) ?></td>
              <?php elseif ($column === 'category'): ?>
                <td><?= e(category_name(['name_en'=>$p['cat_en'],'name_ru'=>$p['cat_ru']])) ?></td>
              <?php elseif ($column === 'replenishment'): ?>
                <td><?= replenishment_class_badge($p['replenishment_class'] ?? 'C') ?></td>
              <?php elseif ($column === 'unit'): ?>
                <td><?= e(product_unit_label_text($displayMap[(int)$p['id']]['default_unit'])) ?></td>
              <?php elseif ($column === 'stock'): ?>
                <td class="col-num"><?= e($displayMap[(int)$p['id']]['stock_display']) ?></td>
              <?php elseif ($column === 'min_stock'): ?>
                <td class="col-num text-muted"><?= (float)$p['min_stock_qty'] > 0 ? e($displayMap[(int)$p['id']]['min_stock']['full_text']) : '—' ?></td>
              <?php elseif ($column === 'status'): ?>
                <td>
                  <?php $replenishmentState = $displayMap[(int)$p['id']]['replenishment']; ?>
                  <?= ($replenishmentState['is_below_min_stock'] || $replenishmentState['is_out_of_stock'])
                    ? replenishment_status_badge($replenishmentState)
                    : stock_badge((float)$p['stock_qty'], (float)$p['min_stock_qty']) ?>
                  <?php if ($replenishmentState['is_below_min_stock'] && (float)$p['min_stock_qty'] > 0): ?>
                    <div style="margin-top:4px"><?= replenishment_class_badge($p['replenishment_class'] ?? 'C') ?></div>
                  <?php endif; ?>
                  <?php if (!$p['is_active']): ?>
                    <span class="badge badge-secondary" style="margin-top:2px"><?= __('lbl_inactive') ?></span>
                  <?php endif; ?>
                </td>
              <?php elseif (str_starts_with($column, 'price_')): ?>
                <?php
                  $priceCode = substr($column, 6);
                  $basePrice = (float)($priceMap[$p['id']][$priceCode] ?? 0);
                  $priceValue = $basePrice > 0
                    ? product_unit_price((int)$p['id'], $displayMap[(int)$p['id']]['default_unit']['unit_code'], $priceCode, $basePrice, $displayMap[(int)$p['id']]['units'], $displayMap[(int)$p['id']]['overrides'])
                    : 0;
                ?>
                <td class="col-num fw-600"><?= $priceValue > 0 ? money($priceValue) : '-' ?></td>
              <?php elseif ($column === 'actions'): ?>
                <td class="col-actions">
                  <a href="<?= url('modules/products/edit.php?id='.$p['id']) ?>" class="btn btn-sm btn-ghost btn-icon" title="<?= __('btn_edit') ?>">
                    <?= feather_icon('edit-2', 14) ?>
                  </a>
                  <a href="<?= url('modules/inventory/history.php?product_id='.$p['id']) ?>"
                     class="btn btn-sm btn-ghost"
                     title="<?= __('inv_history') ?>">
                    <?= feather_icon('clock', 14) ?>
                  </a>
                  <form method="POST" action="<?= url('modules/products/toggle_active.php') ?>" style="display:inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                    <input type="hidden" name="active" value="<?= $p['is_active'] ? 0 : 1 ?>">
                    <input type="hidden" name="return_to" value="<?= e($currentListUrl) ?>">
                    <button type="submit"
                            class="btn btn-sm btn-ghost btn-icon"
                            title="<?= $p['is_active'] ? __('prod_deactivate') : __('prod_restore') ?>"
                            data-confirm="<?= $p['is_active'] ? __('prod_confirm_deactivate') : __('prod_confirm_restore') ?>">
                      <?= $p['is_active'] ? feather_icon('archive', 14) : feather_icon('rotate-ccw', 14) ?>
                    </button>
                  </form>
                  <?php if (!(int)$p['has_moves']): ?>
                  <form method="POST" action="<?= url('modules/products/delete.php') ?>" style="display:inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                    <input type="hidden" name="return_to" value="<?= e($currentListUrl) ?>">
                    <button type="submit"
                            class="btn btn-sm btn-ghost btn-icon" style="color:var(--danger)"
                            title="<?= __('btn_delete') ?>"
                            data-confirm="<?= __('confirm_delete') ?>">
                      <?= feather_icon('trash-2', 14) ?>
                    </button>
                  </form>
                  <?php endif; ?>
                </td>
              <?php endif; ?>
            <?php endforeach; ?>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($pg['pages'] > 1): ?>
  <div class="card-footer flex-between">
    <div class="text-secondary fs-sm">
      <?= __('showing') ?> <?= $pg['offset']+1 ?> - <?= min($pg['offset']+$pg['perPage'], $total) ?>
      <?= __('of') ?> <?= $total ?> <?= __('results') ?>
    </div>
    <div class="pagination">
      <?php for ($i = 1; $i <= $pg['pages']; $i++): ?>
        <?php $q = array_merge($_GET, ['page' => $i]); ?>
        <a href="?<?= http_build_query($q) ?>" class="page-link <?= $i==$pg['page']?'active':'' ?>"><?= $i ?></a>
      <?php endfor; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../../views/layouts/footer.php'; ?>
<script src="https://unpkg.com/feather-icons/dist/feather.min.js"></script>
<script>feather.replace();</script>
<script>
(() => {
  const btn = document.getElementById('productsViewConfigBtn');
  if (!btn || typeof ViewConfigurator === 'undefined') return;

  const current = <?= json_encode($viewCfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const allColumns = <?= json_encode($allColumns, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const allFilters = [
    { key: 'search', label: <?= json_encode(__('btn_search'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?> },
    { key: 'category_id', label: <?= json_encode(__('lbl_category'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?> },
    { key: 'mode', label: <?= json_encode(__('lbl_status'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?> },
    { key: 'stock', label: <?= json_encode(__('prod_stock_filter'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?> },
  ];
  const sortFields = [
    { key: 'name', label: <?= json_encode(__('lbl_name'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?> },
    { key: 'sku', label: <?= json_encode(__('lbl_sku'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?> },
    { key: 'category', label: <?= json_encode(__('lbl_category'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?> },
    { key: 'replenishment', label: <?= json_encode(__('repl_class'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?> },
    { key: 'stock', label: <?= json_encode(__('prod_stock_qty'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?> },
    { key: 'min_stock', label: <?= json_encode(__('prod_min_stock'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?> },
    { key: 'price_retail', label: <?= json_encode(__('prod_sale_price'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?> },
    { key: 'price_purchase', label: <?= json_encode(__('prod_cost_price'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?> },
  ];

  const drawer = new ViewConfigurator({
    module: 'products',
    current,
    allColumns,
    allFilters,
    sortFields,
    viewModes: [{ key: 'table', label: <?= json_encode(__('ui_view_table'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>, icon: 'table' }],
    onApply: () => window.location.reload(),
  });

  btn.addEventListener('click', () => drawer.open());
})();
</script>
