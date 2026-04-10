<?php
/**
 * modules/products/import.php
 * Импорт товаров из Excel (.xlsx)
 * Доступно только admin и manager.
 */
require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../views/partials/icons.php';
Auth::requireLogin();
Auth::requirePerm('products.import');

$autoload = ROOT_PATH . '/vendor/autoload.php';
$hasLibrary = file_exists($autoload);
if ($hasLibrary) { require_once $autoload; }
use PhpOffice\PhpSpreadsheet\IOFactory;

$pageTitle   = __('prod_import');
$breadcrumbs = [[__('prod_title'), url('modules/products/')], [$pageTitle, null]];

// ── Допустимые единицы измерения ─────────────────────────────────
$VALID_UNITS = ['pcs','kg','g','t','l','ml','m','m2','m3','pack','roll','bag','box','pair','set'];

// ── Результат импорта ────────────────────────────────────────────
$importResult = null;

// ── Обработка загруженного файла ─────────────────────────────────
if (is_post() && $hasLibrary) {
    if (!csrf_verify()) {
        flash_error(_r('err_csrf'));
        redirect($_SERVER['REQUEST_URI']);
    }


    $file = $_FILES['import_file'] ?? null;

    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        flash_error(__('prod_import_no_file'));
        redirect($_SERVER['REQUEST_URI']);
    }

    // Проверка расширения и MIME
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['xlsx', 'xls'])) {
        flash_error(__('prod_import_bad_format'));
        redirect($_SERVER['REQUEST_URI']);
    }

    // Временный файл уже есть — используем его напрямую
    $tmpPath = $file['tmp_name'];

    try {
        $spreadsheet = IOFactory::load($tmpPath);
        $sheet = $spreadsheet->getActiveSheet();
        $highestRow = $sheet->getHighestDataRow();
        $highestCol = $sheet->getHighestDataColumn();

        // Читаем заголовки из первой строки
        $headers = [];
        foreach ($sheet->getRowIterator(1, 1) as $row) {
            foreach ($row->getCellIterator('A', $highestCol) as $cell) {
                $val = trim((string)$cell->getValue());
                $headers[$cell->getColumn()] = strtolower($val);
            }
        }

        // Инвертируем: имя_поля => колонка
        $colMap = array_flip($headers);

        // Нужные поля
        $requiredCols = ['name', 'name_ru', 'name_en', 'sku', 'barcode'];
        $foundCols = array_intersect($requiredCols, array_values($headers));
        if (empty(array_intersect(['name', 'name_ru', 'name_en'], $foundCols))) {
            flash_error(__('prod_import_bad_headers'));
            redirect($_SERVER['REQUEST_URI']);
        }

        // ── Кэш категорий ────────────────────────────────────────
        $catCache = [];
        $existingCats = Database::all("SELECT id, name_ru, name_en FROM categories");
        foreach ($existingCats as $c) {
            $catCache[mb_strtolower($c['name_ru'])] = $c['id'];
            $catCache[mb_strtolower($c['name_en'])] = $c['id'];
        }

        $getOrCreateCategory = function(string $name) use (&$catCache): int {
            $key = mb_strtolower(trim($name));
            if (isset($catCache[$key])) {
                return $catCache[$key];
            }
            // Создаём новую категорию
            $id = Database::insert(
                "INSERT INTO categories (name_en, name_ru, icon, color, sort_order, is_active)
                 VALUES (?, ?, 'box', '#607D8B', 99, 1)",
                [$name, $name]
            );
            $catCache[$key] = $id;
            return $id;
        };

        // Категория по умолчанию (первая активная)
        $defaultCatId = (int)Database::value("SELECT id FROM categories WHERE is_active=1 ORDER BY sort_order LIMIT 1");

        // ── Обработка строк ──────────────────────────────────────
        $created  = 0;
        $updated  = 0;
        $skipped  = 0;
        $errors   = [];

        $getCell = function(array $colMap, object $row, string $field): string {
            if (!isset($colMap[$field])) return '';
            $col = $colMap[$field];
            try {
                $cell = $row->getWorksheet()->getCell($col . $row->getRowIndex());
                $val  = $cell->getFormattedValue();
                return trim((string)$val);
            } catch (\Throwable) {
                return '';
            }
        };

        for ($rowNum = 2; $rowNum <= $highestRow; $rowNum++) {
            $sheetRow = $sheet->getRowIterator($rowNum, $rowNum)->current();
            if (!$sheetRow) continue;

            try {
                $g = fn(string $f) => $getCell($colMap, $sheetRow, $f);

                $sku      = strtoupper(sanitize($g('sku')));
                $barcode  = sanitize($g('barcode'));
                $name     = sanitize($g('name'));
                $nameRu   = $name !== '' ? $name : sanitize($g('name_ru'));
                $nameEn   = $name !== '' ? $name : sanitize($g('name_en'));
                $catName  = sanitize($g('category'));
                $brand    = sanitize($g('brand'));
                $unitRaw  = strtolower(sanitize($g('unit')));
                $costRaw  = $g('cost_price');
                $saleRaw  = $g('sale_price');
                $taxRaw   = $g('tax_rate');
                $minStRaw = $g('min_stock_qty');
                $allowDisc = $g('allow_discount');
                $isActiveRaw = $g('is_active');

                // ── Валидация ────────────────────────────────────
                if (!$name && !$nameRu && !$nameEn) {
                    $errors[] = "Строка {$rowNum}: пустое название товара (name_ru и name_en) — пропущена.";
                    $skipped++;
                    continue;
                }

                // Нормализуем имена
                if ($name !== '') $nameEn = $nameRu = $name;
                if (!$nameEn) $nameEn = $nameRu;
                if (!$nameRu) $nameRu = $nameEn;

                // Числовые поля
                $costPrice  = $costRaw  !== '' ? sanitize_float($costRaw)  : 0.0;
                $salePrice  = $saleRaw  !== '' ? sanitize_float($saleRaw)  : 0.0;
                $taxRate    = $taxRaw   !== '' ? sanitize_float($taxRaw)   : 0.0;
                $minStockQty = $minStRaw !== '' ? sanitize_float($minStRaw) : 0.0;

                if ($costPrice < 0) {
                    $errors[] = "Строка {$rowNum}: отрицательная закупочная цена ({$costPrice}) — установлено 0.";
                    $costPrice = 0.0;
                }
                if ($salePrice < 0) {
                    $errors[] = "Строка {$rowNum}: отрицательная цена продажи ({$salePrice}) — установлено 0.";
                    $salePrice = 0.0;
                }
                if ($minStockQty < 0) {
                    $errors[] = "Строка {$rowNum}: отрицательный минимальный остаток — установлено 0.";
                    $minStockQty = 0.0;
                }

                // Единица измерения
                if ($unitRaw && !in_array($unitRaw, $VALID_UNITS)) {
                    $errors[] = "Строка {$rowNum}: неизвестная единица «{$unitRaw}» — установлено pcs.";
                    $unitRaw = 'pcs';
                }
                $unit = $unitRaw ?: 'pcs';

                // is_active / allow_discount
                $isActive    = ($isActiveRaw  === '' || $isActiveRaw  === null) ? 1
                    : ($isActiveRaw  === '0' || $isActiveRaw  === 'false' ? 0 : 1);
                $allowDiscount = ($allowDisc === '' || $allowDisc === null) ? 1
                    : ($allowDisc === '0' || $allowDisc === 'false' ? 0 : 1);

                // Категория
                $catId = $defaultCatId;
                if ($catName !== '') {
                    $catId = $getOrCreateCategory($catName);
                }

                // ── Поиск существующего товара ───────────────────
                $existingId = null;

                if ($sku !== '') {
                    $existingId = Database::value(
                        "SELECT id FROM products WHERE sku = ? LIMIT 1", [$sku]
                    );
                }

                if (!$existingId && $barcode !== '') {
                    $existingId = Database::value(
                        "SELECT id FROM products WHERE barcode = ? LIMIT 1", [$barcode]
                    );
                }

                // ── Генерация SKU, если пустой и новый товар ─────
                if (!$existingId && $sku === '') {
                    // Генерируем из имени
                    $base = preg_replace('/[^A-Z0-9]/i', '', strtoupper($nameEn));
                    $base = substr($base, 0, 8) ?: 'PROD';
                    $candidate = $base . '-' . strtoupper(substr(uniqid(), -5));
                    // Убеждаемся в уникальности
                    while (Database::value("SELECT id FROM products WHERE sku=?", [$candidate])) {
                        $candidate = $base . '-' . strtoupper(substr(uniqid(), -5));
                    }
                    $sku = $candidate;
                }

                // ── Проверка уникальности barcode ────────────────
                if ($barcode !== '') {
                    $bcOwner = Database::value(
                        "SELECT id FROM products WHERE barcode = ? AND id != ? LIMIT 1",
                        [$barcode, (int)$existingId]
                    );
                    if ($bcOwner) {
                        $errors[] = "Строка {$rowNum}: штрихкод «{$barcode}» уже используется другим товаром — поле очищено.";
                        $barcode = '';
                    }
                }

                if ($existingId) {
                    // ── Обновление ────────────────────────────────
                    $updateFields = [
                        'name_ru'       => $nameRu,
                        'name_en'       => $nameEn,
                        'category_id'   => $catId,
                        'unit'          => $unit,
                        'cost_price'    => $costPrice,
                        'sale_price'    => $salePrice,
                        'tax_rate'      => $taxRate,
                        'min_stock_qty' => $minStockQty,
                        'allow_discount'=> $allowDiscount,
                        'is_active'     => $isActive,
                    ];

                    // Обновляем barcode только если он задан в файле
                    if ($barcode !== '') {
                        $updateFields['barcode'] = $barcode;
                    }
                    if ($brand !== '') {
                        $updateFields['brand'] = $brand;
                    }
                    // SKU — только если изменился
                    if ($sku !== '') {
                        $updateFields['sku'] = $sku;
                    }

                    $setClauses = implode(', ', array_map(fn($k) => "`{$k}` = ?", array_keys($updateFields)));
                    $values = array_values($updateFields);
                    $values[] = (int)$existingId;

                    Database::exec(
                        "UPDATE products SET {$setClauses} WHERE id = ?",
                        $values
                    );
                    $updated++;

                } else {
                    // ── Создание ──────────────────────────────────

                    // Финальная проверка уникальности SKU
                    $skuExists = Database::value("SELECT id FROM products WHERE sku=?", [$sku]);
                    if ($skuExists) {
                        $errors[] = "Строка {$rowNum}: артикул «{$sku}» уже занят другим товаром — пропущена.";
                        $skipped++;
                        continue;
                    }

                    Database::insert(
                        "INSERT INTO products
                            (category_id, name_en, name_ru, sku, barcode, brand, unit,
                             sale_price, cost_price, tax_rate, min_stock_qty,
                             allow_discount, is_active, stock_qty)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)",
                        [
                            $catId, $nameEn, $nameRu,
                            $sku,
                            $barcode ?: null,
                            $brand   ?: null,
                            $unit,
                            $salePrice,
                            $costPrice,
                            $taxRate,
                            $minStockQty,
                            $allowDiscount,
                            $isActive,
                        ]
                    );
                    $created++;
                }

            } catch (\Throwable $e) {
                $errors[] = "Строка {$rowNum}: непредвиденная ошибка — " . $e->getMessage();
                $skipped++;
            }
        }

        $importResult = compact('created', 'updated', 'skipped', 'errors', 'highestRow');

    } catch (\Throwable $e) {
        flash_error(_r('prod_import_read_error', ['error' => $e->getMessage()]));
        redirect($_SERVER['REQUEST_URI']);
    }
}

include __DIR__ . '/../../views/layouts/header.php';
?>

<div class="page-header">
  <h1 class="page-heading"><?= __('prod_import') ?></h1>
  <div class="page-actions">
    <a href="<?= url('modules/products/') ?>" class="btn btn-ghost">
      <?= feather_icon('arrow-left', 14) ?> <?= __('btn_back') ?>
    </a>
  </div>
</div>

<?php if (!$hasLibrary): ?>
<!-- PhpSpreadsheet не установлен -->
<div class="card" style="border-left:4px solid var(--warning)">
  <div class="card-body" style="padding:28px">
    <h3 style="margin:0 0 12px;color:var(--warning)"><?= feather_icon('alert-triangle',18) ?> Библиотека PhpSpreadsheet не установлена</h3>
    <p style="margin:0 0 16px">Для работы импорта/экспорта необходимо установить библиотеку через Composer.</p>
    <div class="card" style="background:var(--bg-base);padding:16px 20px;font-family:monospace">
      <div># В корневой папке проекта выполните:</div>
      <div style="color:var(--amber);margin-top:8px">composer install</div>
      <div style="color:var(--text-muted);margin-top:4px"># или, если composer.json уже есть:</div>
      <div style="color:var(--amber)">composer require phpoffice/phpspreadsheet</div>
    </div>
    <p style="margin:16px 0 0;font-size:13px;color:var(--text-muted)">
      Если Composer не установлен: <a href="https://getcomposer.org/download/" target="_blank">getcomposer.org</a>
    </p>
  </div>
</div>

<?php elseif ($importResult !== null): ?>
<!-- Результат импорта -->
<?php
  $total = $importResult['created'] + $importResult['updated'] + $importResult['skipped'];
  $hasErrors = !empty($importResult['errors']);
?>
<div class="card" style="border-left:4px solid <?= $hasErrors ? 'var(--warning)' : 'var(--success)' ?>">
  <div class="card-body" style="padding:24px">
    <h3 style="margin:0 0 20px"><?= feather_icon('check-circle', 18) ?> Импорт завершён</h3>

    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:20px">
      <div class="stat-card" style="background:var(--bg-base);border-radius:8px;padding:16px;text-align:center">
        <div style="font-size:28px;font-weight:700;color:var(--success)"><?= $importResult['created'] ?></div>
        <div style="font-size:12px;color:var(--text-muted);margin-top:4px">Создано</div>
      </div>
      <div class="stat-card" style="background:var(--bg-base);border-radius:8px;padding:16px;text-align:center">
        <div style="font-size:28px;font-weight:700;color:var(--info)"><?= $importResult['updated'] ?></div>
        <div style="font-size:12px;color:var(--text-muted);margin-top:4px">Обновлено</div>
      </div>
      <div class="stat-card" style="background:var(--bg-base);border-radius:8px;padding:16px;text-align:center">
        <div style="font-size:28px;font-weight:700;color:var(--warning)"><?= $importResult['skipped'] ?></div>
        <div style="font-size:12px;color:var(--text-muted);margin-top:4px">Пропущено</div>
      </div>
      <div class="stat-card" style="background:var(--bg-base);border-radius:8px;padding:16px;text-align:center">
        <div style="font-size:28px;font-weight:700;color:var(--text-primary)"><?= $importResult['highestRow'] - 1 ?></div>
        <div style="font-size:12px;color:var(--text-muted);margin-top:4px">Всего строк</div>
      </div>
    </div>

    <?php if ($hasErrors): ?>
    <div style="margin-bottom:20px">
      <h4 style="margin:0 0 10px;color:var(--warning)"><?= feather_icon('alert-circle',14) ?> Предупреждения и ошибки</h4>
      <div style="background:var(--bg-base);border-radius:6px;padding:14px;max-height:280px;overflow-y:auto">
        <?php foreach ($importResult['errors'] as $err): ?>
          <div style="padding:4px 0;border-bottom:1px solid var(--border-dim);font-size:13px;color:var(--text-secondary)">
            <?= feather_icon('info', 12) ?> <?= e($err) ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <div style="display:flex;gap:10px">
      <a href="<?= url('modules/products/') ?>" class="btn btn-primary">
        <?= feather_icon('package', 14) ?> Перейти к товарам
      </a>
      <a href="<?= url('modules/products/import.php') ?>" class="btn btn-ghost">
        <?= feather_icon('upload', 14) ?> Импортировать ещё
      </a>
    </div>
  </div>
</div>

<?php else: ?>
<!-- Форма загрузки -->
<div style="display:grid;grid-template-columns:1fr 340px;gap:24px;align-items:start">

  <div class="card">
    <div class="card-header"><h3 style="margin:0"><?= feather_icon('upload', 16) ?> Загрузить файл Excel</h3></div>
    <div class="card-body" style="padding:24px">

      <form method="POST" enctype="multipart/form-data" id="import-form">
        <?= csrf_field() ?>

        <div style="border:2px dashed var(--border-medium);border-radius:10px;padding:40px 24px;text-align:center;cursor:pointer;transition:border-color .2s" id="drop-zone">
          <div style="font-size:40px;margin-bottom:12px">📊</div>
          <div style="font-weight:600;margin-bottom:6px">Перетащите .xlsx файл сюда</div>
          <div style="color:var(--text-muted);font-size:13px;margin-bottom:16px">или</div>
          <label class="btn btn-secondary" style="cursor:pointer">
            <?= feather_icon('folder', 14) ?> Выбрать файл
            <input type="file" name="import_file" id="file-input" accept=".xlsx,.xls" style="display:none" required>
          </label>
          <div id="file-name" style="margin-top:12px;font-size:13px;color:var(--text-muted)"></div>
        </div>

        <div style="margin-top:20px;padding:14px;background:var(--bg-base);border-radius:8px;font-size:13px;color:var(--text-muted)">
          <?= feather_icon('info', 12) ?> Файл будет обработан на сервере. Максимальный размер: 10 МБ. Формат: .xlsx, .xls
        </div>

        <div style="margin-top:20px">
          <button type="submit" class="btn btn-primary btn-lg" id="submit-btn" disabled>
            <?= feather_icon('upload', 15) ?> Запустить импорт
          </button>
        </div>
      </form>

    </div>
  </div>

  <!-- Боковая панель -->
  <div style="display:flex;flex-direction:column;gap:16px">

    <div class="card">
      <div class="card-header"><h4 style="margin:0"><?= feather_icon('download', 14) ?> Шаблон</h4></div>
      <div class="card-body" style="padding:16px">
        <p style="font-size:13px;color:var(--text-muted);margin:0 0 12px">
          Скачайте шаблон, заполните его и загрузите обратно.
        </p>
        <a href="<?= url('modules/products/template.php') ?>" class="btn btn-secondary btn-block">
          <?= feather_icon('file-text', 14) ?> Скачать шаблон (.xlsx)
        </a>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><h4 style="margin:0"><?= feather_icon('info', 14) ?> Логика импорта</h4></div>
      <div class="card-body" style="padding:16px">
        <ul style="margin:0;padding-left:18px;font-size:13px;color:var(--text-secondary);line-height:1.8">
          <li>Поиск сначала по <strong>SKU</strong>, затем по <strong>штрихкоду</strong></li>
          <li>Найден — <strong>обновление</strong> карточки</li>
          <li>Не найден — <strong>создание</strong> нового товара</li>
          <li>Категории создаются автоматически</li>
          <li><strong>Остатки не изменяются</strong></li>
          <li>Ошибочные строки пропускаются с отчётом</li>
        </ul>
      </div>
    </div>

  </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../../views/layouts/footer.php'; ?>
<script src="https://unpkg.com/feather-icons/dist/feather.min.js"></script>
<script>
feather.replace();

const input   = document.getElementById('file-input');
const dropZone = document.getElementById('drop-zone');
const fileName = document.getElementById('file-name');
const submitBtn = document.getElementById('submit-btn');

if (input) {
  input.addEventListener('change', () => {
    if (input.files.length) {
      fileName.textContent = '✓ ' + input.files[0].name + ' (' + (input.files[0].size / 1024).toFixed(1) + ' KB)';
      fileName.style.color = 'var(--success)';
      submitBtn.disabled = false;
    }
  });

  dropZone.addEventListener('dragover', e => { e.preventDefault(); dropZone.style.borderColor = 'var(--amber)'; });
  dropZone.addEventListener('dragleave', () => { dropZone.style.borderColor = ''; });
  dropZone.addEventListener('drop', e => {
    e.preventDefault();
    dropZone.style.borderColor = '';
    const files = e.dataTransfer.files;
    if (files.length) {
      const dt = new DataTransfer();
      dt.items.add(files[0]);
      input.files = dt.files;
      input.dispatchEvent(new Event('change'));
    }
  });

  document.getElementById('import-form')?.addEventListener('submit', () => {
    submitBtn.disabled = true;
    submitBtn.textContent = '⏳ Обработка...';
  });
}
</script>
