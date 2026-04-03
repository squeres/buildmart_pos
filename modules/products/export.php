<?php
/**
 * modules/products/export.php
 * Экспорт всех товаров в Excel (.xlsx)
 * Доступно только admin и manager.
 */
require_once __DIR__ . '/../../core/bootstrap.php';
Auth::requireLogin();
Auth::requirePerm('products.export');

// Подключаем PhpSpreadsheet через Composer
$autoload = ROOT_PATH . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    flash_error(_r('prod_excel_library_missing'));
    redirect('/modules/products/');
}
require_once $autoload;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

// ── Получаем товары с категорией ────────────────────────────────
$products = Database::all(
    "SELECT
        p.id,
        p.sku,
        p.barcode,
        p.name_ru,
        p.name_en,
        COALESCE(c.name_ru, c.name_en, '') AS category,
        p.brand,
        p.unit,
        p.cost_price,
        p.sale_price,
        p.tax_rate,
        p.min_stock_qty,
        p.allow_discount,
        p.is_active
     FROM products p
     LEFT JOIN categories c ON c.id = p.category_id
     ORDER BY p.name_en"
);

// ── Создаём таблицу ─────────────────────────────────────────────
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Products');

// Заголовки колонок
$headers = [
    'A' => 'id',
    'B' => 'sku',
    'C' => 'barcode',
    'D' => 'name_ru',
    'E' => 'name_en',
    'F' => 'category',
    'G' => 'brand',
    'H' => 'unit',
    'I' => 'cost_price',
    'J' => 'sale_price',
    'K' => 'tax_rate',
    'L' => 'min_stock_qty',
    'M' => 'allow_discount',
    'N' => 'is_active',
];

foreach ($headers as $col => $label) {
    $sheet->setCellValue($col . '1', $label);
}

// Стиль шапки
$headerStyle = [
    'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '2D3748']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    'borders'   => ['bottom' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => 'F6AD55']]],
];
$sheet->getStyle('A1:N1')->applyFromArray($headerStyle);

// Данные
$row = 2;
foreach ($products as $p) {
    $sheet->setCellValue('A' . $row, $p['id']);
    $sheet->setCellValue('B' . $row, $p['sku']);
    $sheet->setCellValue('C' . $row, $p['barcode'] ?? '');
    $sheet->setCellValue('D' . $row, $p['name_ru']);
    $sheet->setCellValue('E' . $row, $p['name_en']);
    $sheet->setCellValue('F' . $row, $p['category']);
    $sheet->setCellValue('G' . $row, $p['brand'] ?? '');
    $sheet->setCellValue('H' . $row, $p['unit']);
    $sheet->setCellValueExplicit('I' . $row, (float)$p['cost_price'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
    $sheet->setCellValueExplicit('J' . $row, (float)$p['sale_price'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
    $sheet->setCellValueExplicit('K' . $row, (float)$p['tax_rate'],   \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
    $sheet->setCellValueExplicit('L' . $row, (float)$p['min_stock_qty'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
    $sheet->setCellValue('M' . $row, (int)$p['allow_discount']);
    $sheet->setCellValue('N' . $row, (int)$p['is_active']);

    // Чередующийся фон строк
    if ($row % 2 === 0) {
        $sheet->getStyle('A' . $row . ':N' . $row)->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F7FAFC']],
        ]);
    }
    $row++;
}

// Формат числовых колонок
$numFmt = '#,##0.00';
foreach (['I', 'J', 'K', 'L'] as $col) {
    $sheet->getStyle($col . '2:' . $col . $row)
          ->getNumberFormat()->setFormatCode($numFmt);
}

// Ширина колонок
$widths = ['A'=>8,'B'=>16,'C'=>16,'D'=>30,'E'=>30,'F'=>22,'G'=>18,'H'=>8,'I'=>12,'J'=>12,'K'=>8,'L'=>12,'M'=>10,'N'=>8];
foreach ($widths as $col => $w) {
    $sheet->getColumnDimension($col)->setWidth($w);
}

// Закрепить первую строку
$sheet->freezePane('A2');

// Автофильтр
$lastRow = max($row - 1, 1);
$sheet->setAutoFilter('A1:N' . $lastRow);

// ── Инструкция на втором листе ───────────────────────────────────
$info = $spreadsheet->createSheet();
$info->setTitle('Instructions');
$info->setCellValue('A1', 'ИНСТРУКЦИЯ ПО ИМПОРТУ / IMPORT GUIDE');
$info->getStyle('A1')->getFont()->setBold(true)->setSize(13);

$notes = [
    ['Поле',          'Описание',                                                    'Пример'],
    ['id',            'ID товара. При импорте игнорируется.',                        '42'],
    ['sku',           'Артикул. Если найден — товар обновляется. Уникальный.',       'CEM-M500-50'],
    ['barcode',       'Штрихкод EAN-13/EAN-8 (необязательно).',                     '4607153390011'],
    ['name_ru',       'Название на русском.',                                         'Цемент М-500 50кг'],
    ['name_en',       'Название на английском.',                                      'Cement M-500 50kg'],
    ['category',      'Название категории. Если не найдена — создаётся автоматически.', 'Цемент и бетон'],
    ['brand',         'Бренд/производитель (необязательно).',                          'Holcim'],
    ['unit',          'Единица: pcs kg g t l ml m m2 m3 pack roll bag box pair set', 'bag'],
    ['cost_price',    'Закупочная цена (число ≥ 0).',                                 '450.00'],
    ['sale_price',    'Цена продажи (число > 0).',                                    '590.00'],
    ['tax_rate',      'Ставка НДС в % (0, 10, 20 и т.д.).',                           '20'],
    ['min_stock_qty', 'Порог низкого остатка (число ≥ 0).',                            '5'],
    ['allow_discount','Разрешить скидку: 1 = да, 0 = нет.',                            '1'],
    ['is_active',     'Активен: 1 = да, 0 = нет.',                                    '1'],
];

foreach ($notes as $i => $noteRow) {
    $r = $i + 3;
    $info->setCellValue('A' . $r, $noteRow[0]);
    $info->setCellValue('B' . $r, $noteRow[1]);
    $info->setCellValue('C' . $r, $noteRow[2]);
}
$info->getStyle('A3:C3')->applyFromArray([
    'font' => ['bold' => true],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EDF2F7']],
]);
$info->getColumnDimension('A')->setWidth(16);
$info->getColumnDimension('B')->setWidth(60);
$info->getColumnDimension('C')->setWidth(20);

// Вернуть на лист Products
$spreadsheet->setActiveSheetIndex(0);

// ── Отправить файл ───────────────────────────────────────────────
$filename = 'products_export_' . date('Y-m-d_His') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0, no-cache, no-store');
header('Pragma: no-cache');
header('Expires: 0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
