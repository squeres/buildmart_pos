<?php
/**
 * Goods Receipt — Export to Excel (.xlsx or .xls fallback)
 * modules/receipts/export_excel.php
 *
 * Strategy:
 *   1. Try PhpSpreadsheet (if available via Composer autoload).
 *   2. Fall back to HTML table with application/vnd.ms-excel MIME type.
 *      This opens correctly in Excel but is not a true XLSX file.
 *      Works well in OpenServer/OSPanel environments without Composer.
 *
 * Install PhpSpreadsheet (optional, for true XLSX):
 *   composer require phpoffice/phpspreadsheet
 */
require_once __DIR__ . '/../../core/bootstrap.php';
Auth::requireLogin();
Auth::requirePerm('receipts.export');

$id = (int)($_GET['id'] ?? 0);
if (!$id) { redirect('/modules/receipts/'); }

$doc = Database::row(
    "SELECT gr.*, s.name AS supplier_name, w.name AS warehouse_name
     FROM goods_receipts gr
     LEFT JOIN suppliers s  ON s.id = gr.supplier_id
     LEFT JOIN warehouses w ON w.id = gr.warehouse_id
     WHERE gr.id = ?",
    [$id]
);
if (!$doc) { die('Not found'); }

$items = Database::all(
    "SELECT * FROM goods_receipt_items WHERE receipt_id=? ORDER BY sort_order, id",
    [$id]
);

$orgName    = setting('gr_org_name',  setting('store_name', APP_NAME));
$docTitle   = setting('gr_doc_title', _r('gr_doc_title_default'));
$safeNo     = preg_replace('/[^A-Za-z0-9_\-]/', '_', $doc['doc_no']);
$filename   = 'GR_' . $safeNo . '_' . date('Ymd');

// ── Try PhpSpreadsheet ──────────────────────────────────────────
$spreadsheetPath = ROOT_PATH . '/vendor/autoload.php';
if (file_exists($spreadsheetPath)) {
    require_once $spreadsheetPath;
    exportXlsx($doc, $items, $orgName, $docTitle, $filename);
    exit;
}

// ── Fallback: HTML as XLS ───────────────────────────────────────
exportHtmlXls($doc, $items, $orgName, $docTitle, $filename);
exit;

// ── XLSX via PhpSpreadsheet ─────────────────────────────────────
function exportXlsx(array $doc, array $items, string $orgName, string $docTitle, string $filename): void
{
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle(_r('gr_title'));

    $bold   = ['font' => ['bold' => true]];
    $center = ['alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER]];
    $right  = ['alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT]];
    $border = ['borders' => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN]]];
    $bgGray = ['fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                           'startColor' => ['rgb' => 'E8E8E8']]];

    // Row 1: Org name
    $sheet->setCellValue('A1', $orgName);
    $sheet->getStyle('A1')->applyFromArray($bold);
    $sheet->mergeCells('A1:G1');
    excel_set_text_cell($sheet, 'A1', $orgName);

    // Row 2: Doc title + number
    $sheet->setCellValue('A2', $docTitle . ' № ' . $doc['doc_no'] . ' | ' . _r('lbl_date') . ': ' . date_fmt($doc['doc_date'], 'd.m.Y'));
    $sheet->getStyle('A2')->applyFromArray(array_merge($bold, ['font' => ['bold' => true, 'size' => 13]]));
    $sheet->mergeCells('A2:G2');
    excel_set_text_cell($sheet, 'A2', $docTitle . ' № ' . $doc['doc_no'] . ' | ' . _r('lbl_date') . ': ' . date_fmt($doc['doc_date'], 'd.m.Y'));

    // Row 3: Supplier / Warehouse
    $sheet->setCellValue('A3', _r('gr_supplier') . ': ' . ($doc['supplier_name'] ?? '—'));
    $sheet->setCellValue('E3', _r('gr_warehouse') . ': ' . ($doc['warehouse_name'] ?? '—'));
    $sheet->mergeCells('A3:D3');
    $sheet->mergeCells('E3:G3');
    excel_set_text_cell($sheet, 'A3', _r('gr_supplier') . ': ' . ($doc['supplier_name'] ?? '—'));
    excel_set_text_cell($sheet, 'E3', _r('gr_warehouse') . ': ' . ($doc['warehouse_name'] ?? '—'));

    // Row 4: Accepted by / Delivered by
    $sheet->setCellValue('A4', _r('gr_accepted_by') . ': ' . ($doc['accepted_by'] ?? ''));
    $sheet->setCellValue('E4', _r('gr_delivered_by') . ': ' . ($doc['delivered_by'] ?? ''));
    $sheet->mergeCells('A4:D4');
    $sheet->mergeCells('E4:G4');
    excel_set_text_cell($sheet, 'A4', _r('gr_accepted_by') . ': ' . ($doc['accepted_by'] ?? ''));
    excel_set_text_cell($sheet, 'E4', _r('gr_delivered_by') . ': ' . ($doc['delivered_by'] ?? ''));

    // Row 6: Table header
    $headerRow = 6;
    $headers = ['#', _r('lbl_name'), _r('lbl_unit'), _r('lbl_qty'), _r('gr_unit_price'), _r('gr_tax_rate'), _r('gr_line_total')];
    $cols = ['A', 'B', 'C', 'D', 'E', 'F', 'G'];
    foreach ($headers as $i => $h) {
        $sheet->setCellValue($cols[$i] . $headerRow, $h);
    }
    $sheet->getStyle("A{$headerRow}:G{$headerRow}")->applyFromArray(array_merge($bold, $bgGray, $border, $center));

    // Data rows
    $row = $headerRow + 1;
    foreach ($items as $n => $item) {
        $sheet->setCellValue('A' . $row, $n + 1);
        excel_set_text_cell($sheet, 'B' . $row, $item['name']);
        excel_set_text_cell($sheet, 'C' . $row, unit_label($item['unit']));
        $sheet->setCellValue('D' . $row, (float)$item['qty']);
        $sheet->setCellValue('E' . $row, (float)$item['unit_price']);
        $sheet->setCellValue('F' . $row, (float)$item['tax_rate']);
        $sheet->setCellValue('G' . $row, (float)$item['line_total']);

        $sheet->getStyle("A{$row}:G{$row}")->applyFromArray($border);
        $sheet->getStyle("D{$row}:G{$row}")->applyFromArray($right);
        $row++;
    }

    // Totals
    $sheet->setCellValue('F' . $row, _r('gr_subtotal') . ':');
    $sheet->setCellValue('G' . $row, (float)$doc['subtotal']);
    $sheet->getStyle("F{$row}:G{$row}")->applyFromArray($bold);
    $row++;

    if ((float)$doc['tax_amount'] > 0) {
        $sheet->setCellValue('F' . $row, _r('lbl_tax') . ':');
        $sheet->setCellValue('G' . $row, (float)$doc['tax_amount']);
        $row++;
    }

    $sheet->setCellValue('F' . $row, _r('lbl_total') . ':');
    $sheet->setCellValue('G' . $row, (float)$doc['total']);
    $sheet->getStyle("F{$row}:G{$row}")->applyFromArray(array_merge($bold, ['font' => ['bold' => true, 'size' => 12]]));

    // Column widths
    $sheet->getColumnDimension('A')->setWidth(5);
    $sheet->getColumnDimension('B')->setWidth(38);
    $sheet->getColumnDimension('C')->setWidth(9);
    $sheet->getColumnDimension('D')->setWidth(9);
    $sheet->getColumnDimension('E')->setWidth(12);
    $sheet->getColumnDimension('F')->setWidth(9);
    $sheet->getColumnDimension('G')->setWidth(14);

    // Number format for price/amount columns
    $fmtNum = '#,##0.00';
    $sheet->getStyle('E7:E' . ($row))->getNumberFormat()->setFormatCode($fmtNum);
    $sheet->getStyle('G7:G' . ($row))->getNumberFormat()->setFormatCode($fmtNum);

    // Stream
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '.xlsx"');
    header('Cache-Control: max-age=0');

    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save('php://output');
}

// ── HTML-as-XLS fallback ────────────────────────────────────────
function exportHtmlXls(array $doc, array $items, string $orgName, string $docTitle, string $filename): void
{
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
    header('Cache-Control: max-age=0');

    // BOM for UTF-8 Excel
    echo "\xEF\xBB\xBF";
?>
<html xmlns:o="urn:schemas-microsoft-com:office:office"
      xmlns:x="urn:schemas-microsoft-com:office:excel"
      xmlns="http://www.w3.org/TR/REC-html40">
<head><meta charset="UTF-8">
<?= app_favicon_links() ?>
<style>
  body { font-family: Arial, sans-serif; font-size: 10pt; }
  table { border-collapse: collapse; width: 100%; }
  th { background: #ddd; border: 1px solid #999; font-weight: bold; padding: 4px 6px; }
  td { border: 1px solid #ccc; padding: 3px 6px; }
  .num { text-align: right; mso-number-format: "#,##0.00"; }
  .bold { font-weight: bold; }
  .total-row td { border-top: 2px solid #555; }
  h2 { font-size: 13pt; }
</style></head>
<body>
<p><?= e(excel_safe_text($orgName)) ?></p>
<h2><?= e(excel_safe_text($docTitle)) ?> № <?= e(excel_safe_text($doc['doc_no'])) ?> | <?= __('lbl_date') ?>: <?= date_fmt($doc['doc_date'], 'd.m.Y') ?></h2>
<p><?= __('gr_supplier') ?>: <?= e(excel_safe_text($doc['supplier_name'] ?? '—')) ?> &nbsp;&nbsp; <?= __('gr_warehouse') ?>: <?= e(excel_safe_text($doc['warehouse_name'] ?? '—')) ?></p>
<p><?= __('gr_accepted_by') ?>: <?= e(excel_safe_text($doc['accepted_by'] ?? '')) ?> &nbsp;&nbsp; <?= __('gr_delivered_by') ?>: <?= e(excel_safe_text($doc['delivered_by'] ?? '')) ?></p>
<br>
<table>
  <thead>
    <tr>
      <th>#</th>
      <th><?= __('lbl_name') ?></th>
      <th><?= __('lbl_unit') ?></th>
      <th><?= __('lbl_qty') ?></th>
      <th><?= __('gr_unit_price') ?></th>
      <th><?= __('gr_tax_rate') ?></th>
      <th><?= __('gr_line_total') ?></th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($items as $n => $item): ?>
    <tr>
      <td><?= $n+1 ?></td>
      <td><?= e(excel_safe_text($item['name'])) ?></td>
      <td><?= e(excel_safe_text(unit_label($item['unit']))) ?></td>
      <td class="num"><?= fmtQty((float)$item['qty']) ?></td>
      <td class="num"><?= number_format((float)$item['unit_price'], 2, '.', '') ?></td>
      <td class="num"><?= $item['tax_rate'] > 0 ? e($item['tax_rate']).'%' : '' ?></td>
      <td class="num"><?= number_format((float)$item['line_total'], 2, '.', '') ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
  <tfoot>
    <tr class="total-row">
      <td colspan="5" class="bold" style="text-align:right"><?= __('gr_subtotal') ?>:</td>
      <td></td>
      <td class="num bold"><?= number_format((float)$doc['subtotal'], 2, '.', '') ?></td>
    </tr>
    <?php if ((float)$doc['tax_amount'] > 0): ?>
    <tr>
      <td colspan="5" style="text-align:right"><?= __('lbl_tax') ?>:</td>
      <td></td>
      <td class="num"><?= number_format((float)$doc['tax_amount'], 2, '.', '') ?></td>
    </tr>
    <?php endif; ?>
    <tr>
      <td colspan="5" class="bold" style="text-align:right;font-size:12pt"><?= __('lbl_total') ?>:</td>
      <td></td>
      <td class="num bold" style="font-size:12pt"><?= number_format((float)$doc['total'], 2, '.', '') ?></td>
    </tr>
  </tfoot>
</table>
</body></html>
<?php
}
