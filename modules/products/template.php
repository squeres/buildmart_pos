<?php
/**
 * modules/products/template.php
 * Скачать пустой шаблон Excel для заполнения и импорта.
 */
require_once __DIR__ . '/../../core/bootstrap.php';
Auth::requireLogin();
Auth::requirePerm('products');

if (!in_array(Auth::role(), ['admin', 'manager'])) {
    http_response_code(403);
    include ROOT_PATH . '/views/partials/403.php';
    exit;
}

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
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Products Import');

// ── Заголовки ────────────────────────────────────────────────────
$headers = [
    'A' => 'sku',
    'B' => 'barcode',
    'C' => 'name_ru',
    'D' => 'name_en',
    'E' => 'category',
    'F' => 'brand',
    'G' => 'unit',
    'H' => 'cost_price',
    'I' => 'sale_price',
    'J' => 'tax_rate',
    'K' => 'min_stock_qty',
    'L' => 'allow_discount',
    'M' => 'is_active',
];

foreach ($headers as $col => $label) {
    $sheet->setCellValue($col . '1', $label);
}

// Стиль шапки
$sheet->getStyle('A1:M1')->applyFromArray([
    'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F6AD55']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    'borders'   => ['bottom' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => '2D3748']]],
]);

// ── Пример строки ────────────────────────────────────────────────
$example = [
    'A' => 'CEM-M500-50',
    'B' => '4607153390011',
    'C' => 'Цемент М-500 50кг',
    'D' => 'Cement M-500 50kg',
    'E' => 'Цемент и бетон',
    'F' => 'Holcim',
    'G' => 'bag',
    'H' => 450.00,
    'I' => 590.00,
    'J' => 20,
    'K' => 5,
    'L' => 1,
    'M' => 1,
];
foreach ($example as $col => $val) {
    $sheet->setCellValue($col . '2', $val);
}
$sheet->getStyle('A2:M2')->applyFromArray([
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFFFF0']],
    'font' => ['italic' => true, 'color' => ['rgb' => '718096']],
]);

// Подсказка над примером
$sheet->getComment('A2')->getText()->createTextRun('Это пример строки. Удалите или замените перед импортом.');

// ── Валидация unit ───────────────────────────────────────────────
$unitValid = $sheet->getCell('G2')->getDataValidation();
$unitValid->setType(DataValidation::TYPE_LIST)
          ->setErrorStyle(DataValidation::STYLE_INFORMATION)
          ->setAllowBlank(false)
          ->setShowDropDown(false)
          ->setFormula1('"pcs,kg,g,t,l,ml,m,m2,m3,pack,roll,bag,box,pair,set"');
for ($r = 2; $r <= 1000; $r++) {
    $dv = clone $unitValid;
    $sheet->getCell('G' . $r)->setDataValidation($dv);
}

// ── Ширина колонок ───────────────────────────────────────────────
$widths = ['A'=>16,'B'=>16,'C'=>30,'D'=>30,'E'=>22,'F'=>18,'G'=>8,'H'=>12,'I'=>12,'J'=>8,'K'=>12,'L'=>10,'M'=>8];
foreach ($widths as $col => $w) {
    $sheet->getColumnDimension($col)->setWidth($w);
}

$sheet->freezePane('A2');

// ── Лист-инструкция ──────────────────────────────────────────────
$info = $spreadsheet->createSheet();
$info->setTitle('Guide');
$info->setCellValue('A1', 'ИНСТРУКЦИЯ ПО ЗАПОЛНЕНИЮ ШАБЛОНА');
$info->getStyle('A1')->getFont()->setBold(true)->setSize(13);

$rules = [
    ['Колонка',         'Обязательно?', 'Описание'],
    ['sku',             'Рекомендуется','Артикул товара. По нему идёт поиск при обновлении.'],
    ['barcode',         'Нет',          'Штрихкод. Используется, если SKU не заполнен.'],
    ['name_ru',         'Да*',          '* Нужно хотя бы name_ru ИЛИ name_en.'],
    ['name_en',         'Да*',          '* Нужно хотя бы name_ru ИЛИ name_en.'],
    ['category',        'Нет',          'Название категории. Если нет — будет создана.'],
    ['brand',           'Нет',          'Бренд / производитель.'],
    ['unit',            'Да',           'pcs | kg | g | t | l | ml | m | m2 | m3 | pack | roll | bag | box | pair | set'],
    ['cost_price',      'Нет',          'Закупочная цена (число ≥ 0). Пустое = 0.'],
    ['sale_price',      'Рекомендуется','Цена продажи (число ≥ 0). Пустое = 0.'],
    ['tax_rate',        'Нет',          'Ставка НДС в процентах (0, 10, 20). Пустое = 0.'],
    ['min_stock_qty',   'Нет',          'Порог минимального остатка для предупреждения.'],
    ['allow_discount',  'Нет',          '1 = разрешить скидку, 0 = запретить. Пустое = 1.'],
    ['is_active',       'Нет',          '1 = активен, 0 = неактивен. Пустое = 1.'],
    ['',                '',             ''],
    ['ВАЖНО:',          '',             'Остатки (stock_qty) через этот импорт не меняются.'],
    ['',                '',             'Для прихода товара используйте раздел «Поступления».'],
];

foreach ($rules as $i => $r) {
    $row = $i + 3;
    $info->setCellValue('A' . $row, $r[0]);
    $info->setCellValue('B' . $row, $r[1]);
    $info->setCellValue('C' . $row, $r[2]);
}
$info->getStyle('A3:C3')->applyFromArray([
    'font' => ['bold' => true],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EDF2F7']],
]);
$info->getColumnDimension('A')->setWidth(18);
$info->getColumnDimension('B')->setWidth(16);
$info->getColumnDimension('C')->setWidth(65);

$spreadsheet->setActiveSheetIndex(0);

// ── Отправить ────────────────────────────────────────────────────
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="products_import_template.xlsx"');
header('Cache-Control: max-age=0, no-cache, no-store');
header('Pragma: no-cache');
header('Expires: 0');

(new Xlsx($spreadsheet))->save('php://output');
exit;
