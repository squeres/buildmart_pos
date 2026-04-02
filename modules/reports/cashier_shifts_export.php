<?php
require_once __DIR__ . '/../../core/bootstrap.php';
require_once ROOT_PATH . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

Auth::requireLogin();
Auth::requireManagerOrAdmin();

$dateFrom = sanitize($_GET['date_from'] ?? date('Y-m-01'));
$dateTo = sanitize($_GET['date_to'] ?? date('Y-m-d'));
$cashierId = max(0, (int)($_GET['cashier_id'] ?? 0));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $dateFrom = date('Y-m-01');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $dateTo = date('Y-m-d');
}
if ($dateFrom > $dateTo) {
    [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
}

$params = [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'];
$cashierFilterSql = '';
if ($cashierId > 0) {
    $cashierFilterSql = ' AND s.user_id = ?';
    $params[] = $cashierId;
}

$rows = Database::all(
    "SELECT s.*,
            u.name AS cashier_name,
            COALESCE(sf.sales_count, 0) AS sales_count_fallback,
            COALESCE(sf.sales_total, 0) AS sales_total_fallback
     FROM shifts s
     JOIN users u ON u.id = s.user_id
     LEFT JOIN (
        SELECT shift_id,
               COUNT(*) AS sales_count,
               COALESCE(SUM(total), 0) AS sales_total
        FROM sales
        WHERE status = 'completed' AND shift_id IS NOT NULL
        GROUP BY shift_id
     ) sf ON sf.shift_id = s.id
     WHERE s.opened_at BETWEEN ? AND ?{$cashierFilterSql}
     ORDER BY s.opened_at ASC",
    $params
);

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Cashier Shifts');

$headers = [
    '№',
    __('shift_report_day'),
    __('shift_cashier'),
    __('shift_opened_at'),
    __('shift_closed_at'),
    __('shift_tx_count'),
    __('shift_sales_total'),
    __('shift_effective_hours'),
];

$sheet->fromArray($headers, null, 'A1');
$sheet->getStyle('A1:H1')->applyFromArray([
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['rgb' => '1F4E78'],
    ],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical' => Alignment::VERTICAL_CENTER,
    ],
    'borders' => [
        'allBorders' => [
            'borderStyle' => Border::BORDER_THIN,
            'color' => ['rgb' => 'D9E2F3'],
        ],
    ],
]);

$rowIndex = 2;
$totalSales = 0.0;
$totalReceipts = 0;
$totalWorkedSeconds = 0;
$counter = 1;

foreach ($rows as $row) {
    $openedAt = shift_datetime((string)$row['opened_at']) ?: shift_now();
    $day = $openedAt->format('d.m.Y');
    $receipts = (int)$row['transaction_count'] > 0 ? (int)$row['transaction_count'] : (int)$row['sales_count_fallback'];
    $salesTotal = (float)$row['total_sales'] > 0 ? (float)$row['total_sales'] : (float)$row['sales_total_fallback'];
    $workedSeconds = shift_worked_seconds($row);

    $sheet->setCellValue('A' . $rowIndex, $counter++);
    $sheet->setCellValue('B' . $rowIndex, $day);
    $sheet->setCellValue('C' . $rowIndex, (string)$row['cashier_name']);
    $sheet->setCellValue('D' . $rowIndex, date_fmt((string)$row['opened_at']));
    $sheet->setCellValue('E' . $rowIndex, shift_report_closed_label($row));
    $sheet->setCellValue('F' . $rowIndex, $receipts);
    $sheet->setCellValue('G' . $rowIndex, $salesTotal);
    $sheet->setCellValue('H' . $rowIndex, shift_format_duration($workedSeconds));

    $totalSales += $salesTotal;
    $totalReceipts += $receipts;
    $totalWorkedSeconds += $workedSeconds;
    $rowIndex++;
}

if ($rowIndex === 2) {
    $sheet->mergeCells('A2:H2');
    $sheet->setCellValue('A2', __('no_results'));
    $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $rowIndex = 3;
}

$summaryStart = $rowIndex + 1;
$sheet->setCellValue('F' . $summaryStart, __('shift_report_period_receipts'));
$sheet->setCellValue('G' . $summaryStart, $totalReceipts);

$sheet->setCellValue('F' . ($summaryStart + 1), __('shift_report_period_sales'));
$sheet->setCellValue('G' . ($summaryStart + 1), $totalSales);

$sheet->setCellValue('F' . ($summaryStart + 2), __('shift_report_period_hours'));
$sheet->setCellValue('G' . ($summaryStart + 2), shift_format_duration($totalWorkedSeconds));

$sheet->getStyle('F' . $summaryStart . ':G' . ($summaryStart + 2))->applyFromArray([
    'font' => ['bold' => true],
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['rgb' => 'F3F6FA'],
    ],
    'borders' => [
        'allBorders' => [
            'borderStyle' => Border::BORDER_THIN,
            'color' => ['rgb' => 'D0D7E2'],
        ],
    ],
]);

$sheet->getStyle('G2:G' . max(2, $rowIndex - 1))
    ->getNumberFormat()
    ->setFormatCode('#,##0.00');

foreach (range('A', 'H') as $column) {
    $sheet->getColumnDimension($column)->setAutoSize(true);
}

$sheet->freezePane('A2');
$sheet->getDefaultRowDimension()->setRowHeight(20);

$filename = 'cashier_shift_report_' . date('Ymd_His') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
