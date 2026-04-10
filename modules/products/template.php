<?php
/**
 * modules/products/template.php
 * Download blank Excel template for product import.
 */
require_once __DIR__ . '/../../core/bootstrap.php';
Auth::requireLogin();
Auth::requirePerm('products.import');

$autoload = ROOT_PATH . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    flash_error(_r('prod_excel_library_missing'));
    redirect('/modules/products/');
}
require_once $autoload;

use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Products Import');

$headers = [
    'A' => 'sku',
    'B' => 'barcode',
    'C' => 'name',
    'D' => 'category',
    'E' => 'brand',
    'F' => 'unit',
    'G' => 'cost_price',
    'H' => 'sale_price',
    'I' => 'tax_rate',
    'J' => 'min_stock_qty',
    'K' => 'allow_discount',
    'L' => 'is_active',
];

foreach ($headers as $col => $label) {
    $sheet->setCellValue($col . '1', $label);
}

$sheet->getStyle('A1:L1')->applyFromArray([
    'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F6AD55']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    'borders'   => ['bottom' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => '2D3748']]],
]);

$example = [
    'A' => 'CEM-M500-50',
    'B' => '4607153390011',
    'C' => 'Cement M-500 50kg',
    'D' => 'Cement & Concrete',
    'E' => 'Holcim',
    'F' => 'bag',
    'G' => 450.00,
    'H' => 590.00,
    'I' => 20,
    'J' => 5,
    'K' => 1,
    'L' => 1,
];
foreach ($example as $col => $val) {
    $sheet->setCellValue($col . '2', $val);
}
$sheet->getStyle('A2:L2')->applyFromArray([
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFFFF0']],
    'font' => ['italic' => true, 'color' => ['rgb' => '718096']],
]);
$sheet->getComment('A2')->getText()->createTextRun('Example row. Delete or replace it before import.');

$unitValid = $sheet->getCell('F2')->getDataValidation();
$unitValid->setType(DataValidation::TYPE_LIST)
          ->setErrorStyle(DataValidation::STYLE_INFORMATION)
          ->setAllowBlank(false)
          ->setShowDropDown(false)
          ->setFormula1('"pcs,kg,g,t,l,ml,m,m2,m3,pack,roll,bag,box,pair,set"');
for ($r = 2; $r <= 1000; $r++) {
    $dv = clone $unitValid;
    $sheet->getCell('F' . $r)->setDataValidation($dv);
}

$widths = ['A'=>16,'B'=>16,'C'=>34,'D'=>22,'E'=>18,'F'=>8,'G'=>12,'H'=>12,'I'=>8,'J'=>12,'K'=>10,'L'=>8];
foreach ($widths as $col => $w) {
    $sheet->getColumnDimension($col)->setWidth($w);
}

$sheet->freezePane('A2');

$info = $spreadsheet->createSheet();
$info->setTitle('Guide');
$info->setCellValue('A1', 'PRODUCT IMPORT GUIDE');
$info->getStyle('A1')->getFont()->setBold(true)->setSize(13);

$rules = [
    ['Column',         'Required?',      'Description'],
    ['sku',            'Recommended',    'Product SKU. Used first for matching existing records.'],
    ['barcode',        'No',             'Barcode. Used if SKU is empty or not found.'],
    ['name',           'Yes',            'Single product name used across the system.'],
    ['category',       'No',             'Category name. Created automatically if missing.'],
    ['brand',          'No',             'Brand / manufacturer.'],
    ['unit',           'Yes',            'pcs | kg | g | t | l | ml | m | m2 | m3 | pack | roll | bag | box | pair | set'],
    ['cost_price',     'No',             'Purchase price, number >= 0.'],
    ['sale_price',     'Recommended',    'Sale price, number >= 0.'],
    ['tax_rate',       'No',             'VAT rate in percent, for example 0, 10, 20.'],
    ['min_stock_qty',  'No',             'Low-stock threshold.'],
    ['allow_discount', 'No',             '1 = allow discount, 0 = disallow. Empty = 1.'],
    ['is_active',      'No',             '1 = active, 0 = inactive. Empty = 1.'],
    ['',               '',               ''],
    ['IMPORTANT',      '',               'This template does not change stock_qty. Use receipts or inventory count for stock movements.'],
];

foreach ($rules as $i => $rowData) {
    $row = $i + 3;
    $info->setCellValue('A' . $row, $rowData[0]);
    $info->setCellValue('B' . $row, $rowData[1]);
    $info->setCellValue('C' . $row, $rowData[2]);
}
$info->getStyle('A3:C3')->applyFromArray([
    'font' => ['bold' => true],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EDF2F7']],
]);
$info->getColumnDimension('A')->setWidth(18);
$info->getColumnDimension('B')->setWidth(16);
$info->getColumnDimension('C')->setWidth(65);

$spreadsheet->setActiveSheetIndex(0);

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="products_import_template.xlsx"');
header('Cache-Control: max-age=0, no-cache, no-store');
header('Pragma: no-cache');
header('Expires: 0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
