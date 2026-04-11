<?php
require_once __DIR__ . '/../../core/bootstrap.php';
Auth::requireLogin();
Auth::requirePerm('sales');

function si_ru_plural_x(int $value, array $forms): string
{
    $value = abs($value) % 100;
    $n = $value % 10;
    if ($value > 10 && $value < 20) {
        return $forms[2];
    }
    if ($n > 1 && $n < 5) {
        return $forms[1];
    }
    if ($n === 1) {
        return $forms[0];
    }
    return $forms[2];
}

function si_ru_triplet_x(int $value, string $gender = 'm'): string
{
    $hundreds = ['', 'Р РЋР С“Р РЋРІР‚С™Р В РЎвЂў', 'Р В РўвЂР В Р вЂ Р В Р’ВµР РЋР С“Р РЋРІР‚С™Р В РЎвЂ', 'Р РЋРІР‚С™Р РЋР вЂљР В РЎвЂР РЋР С“Р РЋРІР‚С™Р В Р’В°', 'Р РЋРІР‚РЋР В Р’ВµР РЋРІР‚С™Р РЋРІР‚в„–Р РЋР вЂљР В Р’ВµР РЋР С“Р РЋРІР‚С™Р В Р’В°', 'Р В РЎвЂ”Р РЋР РЏР РЋРІР‚С™Р РЋР Р‰Р РЋР С“Р В РЎвЂўР РЋРІР‚С™', 'Р РЋРІвЂљВ¬Р В Р’ВµР РЋР С“Р РЋРІР‚С™Р РЋР Р‰Р РЋР С“Р В РЎвЂўР РЋРІР‚С™', 'Р РЋР С“Р В Р’ВµР В РЎВР РЋР Р‰Р РЋР С“Р В РЎвЂўР РЋРІР‚С™', 'Р В Р вЂ Р В РЎвЂўР РЋР С“Р В Р’ВµР В РЎВР РЋР Р‰Р РЋР С“Р В РЎвЂўР РЋРІР‚С™', 'Р В РўвЂР В Р’ВµР В Р вЂ Р РЋР РЏР РЋРІР‚С™Р РЋР Р‰Р РЋР С“Р В РЎвЂўР РЋРІР‚С™'];
    $tens = ['', 'Р В РўвЂР В Р’ВµР РЋР С“Р РЋР РЏР РЋРІР‚С™Р РЋР Р‰', 'Р В РўвЂР В Р вЂ Р В Р’В°Р В РўвЂР РЋРІР‚В Р В Р’В°Р РЋРІР‚С™Р РЋР Р‰', 'Р РЋРІР‚С™Р РЋР вЂљР В РЎвЂР В РўвЂР РЋРІР‚В Р В Р’В°Р РЋРІР‚С™Р РЋР Р‰', 'Р РЋР С“Р В РЎвЂўР РЋР вЂљР В РЎвЂўР В РЎвЂќ', 'Р В РЎвЂ”Р РЋР РЏР РЋРІР‚С™Р РЋР Р‰Р В РўвЂР В Р’ВµР РЋР С“Р РЋР РЏР РЋРІР‚С™', 'Р РЋРІвЂљВ¬Р В Р’ВµР РЋР С“Р РЋРІР‚С™Р РЋР Р‰Р В РўвЂР В Р’ВµР РЋР С“Р РЋР РЏР РЋРІР‚С™', 'Р РЋР С“Р В Р’ВµР В РЎВР РЋР Р‰Р В РўвЂР В Р’ВµР РЋР С“Р РЋР РЏР РЋРІР‚С™', 'Р В Р вЂ Р В РЎвЂўР РЋР С“Р В Р’ВµР В РЎВР РЋР Р‰Р В РўвЂР В Р’ВµР РЋР С“Р РЋР РЏР РЋРІР‚С™', 'Р В РўвЂР В Р’ВµР В Р вЂ Р РЋР РЏР В Р вЂ¦Р В РЎвЂўР РЋР С“Р РЋРІР‚С™Р В РЎвЂў'];
    $teens = ['Р В РўвЂР В Р’ВµР РЋР С“Р РЋР РЏР РЋРІР‚С™Р РЋР Р‰', 'Р В РЎвЂўР В РўвЂР В РЎвЂР В Р вЂ¦Р В Р вЂ¦Р В Р’В°Р В РўвЂР РЋРІР‚В Р В Р’В°Р РЋРІР‚С™Р РЋР Р‰', 'Р В РўвЂР В Р вЂ Р В Р’ВµР В Р вЂ¦Р В Р’В°Р В РўвЂР РЋРІР‚В Р В Р’В°Р РЋРІР‚С™Р РЋР Р‰', 'Р РЋРІР‚С™Р РЋР вЂљР В РЎвЂР В Р вЂ¦Р В Р’В°Р В РўвЂР РЋРІР‚В Р В Р’В°Р РЋРІР‚С™Р РЋР Р‰', 'Р РЋРІР‚РЋР В Р’ВµР РЋРІР‚С™Р РЋРІР‚в„–Р РЋР вЂљР В Р вЂ¦Р В Р’В°Р В РўвЂР РЋРІР‚В Р В Р’В°Р РЋРІР‚С™Р РЋР Р‰', 'Р В РЎвЂ”Р РЋР РЏР РЋРІР‚С™Р В Р вЂ¦Р В Р’В°Р В РўвЂР РЋРІР‚В Р В Р’В°Р РЋРІР‚С™Р РЋР Р‰', 'Р РЋРІвЂљВ¬Р В Р’ВµР РЋР С“Р РЋРІР‚С™Р В Р вЂ¦Р В Р’В°Р В РўвЂР РЋРІР‚В Р В Р’В°Р РЋРІР‚С™Р РЋР Р‰', 'Р РЋР С“Р В Р’ВµР В РЎВР В Р вЂ¦Р В Р’В°Р В РўвЂР РЋРІР‚В Р В Р’В°Р РЋРІР‚С™Р РЋР Р‰', 'Р В Р вЂ Р В РЎвЂўР РЋР С“Р В Р’ВµР В РЎВР В Р вЂ¦Р В Р’В°Р В РўвЂР РЋРІР‚В Р В Р’В°Р РЋРІР‚С™Р РЋР Р‰', 'Р В РўвЂР В Р’ВµР В Р вЂ Р РЋР РЏР РЋРІР‚С™Р В Р вЂ¦Р В Р’В°Р В РўвЂР РЋРІР‚В Р В Р’В°Р РЋРІР‚С™Р РЋР Р‰'];
    $ones = [
        'm' => ['', 'Р В РЎвЂўР В РўвЂР В РЎвЂР В Р вЂ¦', 'Р В РўвЂР В Р вЂ Р В Р’В°', 'Р РЋРІР‚С™Р РЋР вЂљР В РЎвЂ', 'Р РЋРІР‚РЋР В Р’ВµР РЋРІР‚С™Р РЋРІР‚в„–Р РЋР вЂљР В Р’Вµ', 'Р В РЎвЂ”Р РЋР РЏР РЋРІР‚С™Р РЋР Р‰', 'Р РЋРІвЂљВ¬Р В Р’ВµР РЋР С“Р РЋРІР‚С™Р РЋР Р‰', 'Р РЋР С“Р В Р’ВµР В РЎВР РЋР Р‰', 'Р В Р вЂ Р В РЎвЂўР РЋР С“Р В Р’ВµР В РЎВР РЋР Р‰', 'Р В РўвЂР В Р’ВµР В Р вЂ Р РЋР РЏР РЋРІР‚С™Р РЋР Р‰'],
        'f' => ['', 'Р В РЎвЂўР В РўвЂР В Р вЂ¦Р В Р’В°', 'Р В РўвЂР В Р вЂ Р В Р’Вµ', 'Р РЋРІР‚С™Р РЋР вЂљР В РЎвЂ', 'Р РЋРІР‚РЋР В Р’ВµР РЋРІР‚С™Р РЋРІР‚в„–Р РЋР вЂљР В Р’Вµ', 'Р В РЎвЂ”Р РЋР РЏР РЋРІР‚С™Р РЋР Р‰', 'Р РЋРІвЂљВ¬Р В Р’ВµР РЋР С“Р РЋРІР‚С™Р РЋР Р‰', 'Р РЋР С“Р В Р’ВµР В РЎВР РЋР Р‰', 'Р В Р вЂ Р В РЎвЂўР РЋР С“Р В Р’ВµР В РЎВР РЋР Р‰', 'Р В РўвЂР В Р’ВµР В Р вЂ Р РЋР РЏР РЋРІР‚С™Р РЋР Р‰'],
    ];

    $result = [];
    $h = intdiv($value, 100);
    $t = intdiv($value % 100, 10);
    $o = $value % 10;
    if ($h > 0) { $result[] = $hundreds[$h]; }
    if ($t === 1) {
        $result[] = $teens[$o];
    } else {
        if ($t > 1) { $result[] = $tens[$t]; }
        if ($o > 0) { $result[] = $ones[$gender][$o]; }
    }
    return trim(implode(' ', array_filter($result)));
}

function si_ru_number_words_x(int $value): string
{
    if ($value === 0) {
        return 'Р В Р вЂ¦Р В РЎвЂўР В Р’В»Р РЋР Р‰';
    }
    $groups = [
        ['', 'm', ['', '', '']],
        ['Р РЋРІР‚С™Р РЋРІР‚в„–Р РЋР С“Р РЋР РЏР РЋРІР‚РЋР В Р’В°', 'f', ['Р РЋРІР‚С™Р РЋРІР‚в„–Р РЋР С“Р РЋР РЏР РЋРІР‚РЋР В Р’В°', 'Р РЋРІР‚С™Р РЋРІР‚в„–Р РЋР С“Р РЋР РЏР РЋРІР‚РЋР В РЎвЂ', 'Р РЋРІР‚С™Р РЋРІР‚в„–Р РЋР С“Р РЋР РЏР РЋРІР‚РЋ']],
        ['Р В РЎВР В РЎвЂР В Р’В»Р В Р’В»Р В РЎвЂР В РЎвЂўР В Р вЂ¦', 'm', ['Р В РЎВР В РЎвЂР В Р’В»Р В Р’В»Р В РЎвЂР В РЎвЂўР В Р вЂ¦', 'Р В РЎВР В РЎвЂР В Р’В»Р В Р’В»Р В РЎвЂР В РЎвЂўР В Р вЂ¦Р В Р’В°', 'Р В РЎВР В РЎвЂР В Р’В»Р В Р’В»Р В РЎвЂР В РЎвЂўР В Р вЂ¦Р В РЎвЂўР В Р вЂ ']],
        ['Р В РЎВР В РЎвЂР В Р’В»Р В Р’В»Р В РЎвЂР В Р’В°Р РЋР вЂљР В РўвЂ', 'm', ['Р В РЎВР В РЎвЂР В Р’В»Р В Р’В»Р В РЎвЂР В Р’В°Р РЋР вЂљР В РўвЂ', 'Р В РЎВР В РЎвЂР В Р’В»Р В Р’В»Р В РЎвЂР В Р’В°Р РЋР вЂљР В РўвЂР В Р’В°', 'Р В РЎВР В РЎвЂР В Р’В»Р В Р’В»Р В РЎвЂР В Р’В°Р РЋР вЂљР В РўвЂР В РЎвЂўР В Р вЂ ']],
    ];
    $parts = [];
    $groupIndex = 0;
    while ($value > 0 && isset($groups[$groupIndex])) {
        $triplet = $value % 1000;
        if ($triplet > 0) {
            [$label, $gender, $forms] = $groups[$groupIndex];
            $chunk = si_ru_triplet_x($triplet, $gender);
            if ($groupIndex > 0) { $chunk .= ' ' . si_ru_plural_x($triplet, $forms); }
            array_unshift($parts, trim($chunk));
        }
        $value = intdiv($value, 1000);
        $groupIndex++;
    }
    return trim(implode(' ', $parts));
}

function si_amount_words_x(float $amount): string
{
    $whole = (int) floor($amount);
    $fraction = (int) round(($amount - $whole) * 100);
    if ($fraction === 100) { $whole++; $fraction = 0; }
    return trim(sprintf('%s Р РЋРІР‚С™Р В Р’ВµР В Р вЂ¦Р В РЎвЂ“Р В Р’Вµ %02d Р РЋРІР‚С™Р В РЎвЂР РЋРІР‚в„–Р В Р вЂ¦', si_ru_number_words_x($whole), $fraction));
}

function si_total_qty_words_x(array $items): string
{
    $sum = 0.0;
    foreach ($items as $item) { $sum += (float) $item['qty']; }
    if (abs($sum - round($sum)) <= 0.00001) {
        return si_ru_number_words_x((int) round($sum));
    }
    return str_replace('.', ',', rtrim(rtrim(number_format($sum, 3, '.', ''), '0'), '.'));
}

function si_money_plain_x(float $value): string
{
    return number_format($value, 2, ',', ' ');
}

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    redirect('/modules/sales/');
}

$invoice = Database::row(
    "SELECT si.*, s.receipt_no, s.subtotal, s.tax_amount, s.total,
            w.name AS warehouse_name
     FROM sale_invoices si
     JOIN sales s ON s.id = si.sale_id
     LEFT JOIN warehouses w ON w.id = s.warehouse_id
     WHERE si.id = ?",
    [$id]
);
if (!$invoice) {
    flash_error(_r('err_not_found'));
    redirect('/modules/sales/');
}

$items = Database::all("SELECT * FROM sale_items WHERE sale_id=? ORDER BY id", [$invoice['sale_id']]);
$senderName = trim((string) ($invoice['sender_legal_name_snapshot'] ?: $invoice['sender_name_snapshot']));
$recipientName = trim((string) ($invoice['customer_company_snapshot'] ?: $invoice['customer_name_snapshot']));
$recipientContact = trim((string) ($invoice['customer_contact_person_snapshot'] ?: $invoice['customer_name_snapshot']));
$recipientBlock = trim(($recipientName ?: 'Р Р†Р вЂљРІР‚Сњ') . (($invoice['customer_address_snapshot'] ?? '') !== '' ? "\n" . $invoice['customer_address_snapshot'] : ''));
$responsibleBlock = trim(($invoice['sender_responsible_name_snapshot'] ?: 'Р Р†Р вЂљРІР‚Сњ') . (($invoice['sender_responsible_position_snapshot'] ?? '') !== '' ? "\n" . $invoice['sender_responsible_position_snapshot'] : ''));
$transportDoc = trim((string) $invoice['transport_waybill_no']);
if (!empty($invoice['transport_waybill_date'])) {
    $transportDoc .= ($transportDoc !== '' ? ', ' : '') . date('d.m.Y', strtotime((string) $invoice['transport_waybill_date']));
}
$poaIssuedBy = trim((string) ($invoice['customer_company_snapshot'] ?: $invoice['customer_name_snapshot'] ?: ''));
$filenameBase = 'SI_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', (string) $invoice['invoice_number']);
$autoload = ROOT_PATH . '/vendor/autoload.php';

if (file_exists($autoload)) {
    require_once $autoload;

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle(__('doc_delivery_note'));
    $spreadsheet->getDefaultStyle()->getFont()->setName('Times New Roman')->setSize(11);
    $sheet->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE);
    $sheet->getPageSetup()->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4);
    $sheet->getPageMargins()->setTop(0.3);
    $sheet->getPageMargins()->setBottom(0.3);
    $sheet->getPageMargins()->setLeft(0.25);
    $sheet->getPageMargins()->setRight(0.25);

    foreach (['A'=>6,'B'=>34,'C'=>16,'D'=>12,'E'=>12,'F'=>12,'G'=>14,'H'=>16,'I'=>14] as $col => $width) {
        $sheet->getColumnDimension($col)->setWidth($width);
    }

    $sheet->mergeCells('G1:I1')->setCellValue('G1', __('invoice_appendix_26'));
    $sheet->mergeCells('G2:I2')->setCellValue('G2', __('invoice_finance_order_line_1'));
    $sheet->mergeCells('G3:I3')->setCellValue('G3', __('invoice_finance_order_line_2'));
    $sheet->mergeCells('G4:I4')->setCellValue('G4', __('invoice_finance_order_line_3'));
    $sheet->mergeCells('H5:I5')->setCellValue('H5', __('invoice_form_z2'));
    $sheet->getStyle('G1:I5')->getFont()->setItalic(true);
    $sheet->getStyle('G1:I5')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);

    $sheet->mergeCells('A7:C7')->setCellValue('A7', __('invoice_org_label'));
    $sheet->mergeCells('D7:G7')->setCellValue('D7', $senderName ?: 'Р Р†Р вЂљРІР‚Сњ');
    $sheet->setCellValue('H7', __('cust_inn'));
    $sheet->setCellValue('I7', (string) ($invoice['sender_iin_bin_snapshot'] ?: 'Р Р†Р вЂљРІР‚Сњ'));

    $sheet->mergeCells('H9:H10')->setCellValue('H9', __('invoice_doc_number'));
    $sheet->mergeCells('I9:I10')->setCellValue('I9', __('invoice_doc_date'));
    $sheet->setCellValue('H11', (string) $invoice['invoice_number']);
    $sheet->setCellValue('I11', date('d.m.Y', strtotime((string) $invoice['invoice_date'])));
    excel_set_text_cell($sheet, 'D7', $senderName ?: 'РІР‚вЂќ');
    excel_set_text_cell($sheet, 'I7', (string) ($invoice['sender_iin_bin_snapshot'] ?: 'РІР‚вЂќ'));
    excel_set_text_cell($sheet, 'H11', (string) $invoice['invoice_number']);

    $sheet->mergeCells('A13:I13')->setCellValue('A13', __('invoice_title'));
    $sheet->getStyle('A13:I13')->getFont()->setBold(true)->setSize(16);
    $sheet->getStyle('A13:I13')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

    $sheet->mergeCells('A15:B15')->setCellValue('A15', __('invoice_sender_header'));
    $sheet->mergeCells('C15:D15')->setCellValue('C15', __('invoice_recipient_header'));
    $sheet->mergeCells('E15:F15')->setCellValue('E15', __('invoice_responsible_supply'));
    $sheet->setCellValue('G15', __('invoice_transport'));
    $sheet->mergeCells('H15:I15')->setCellValue('H15', __('invoice_transport_waybill_header'));
    $sheet->mergeCells('A16:B18')->setCellValue('A16', $senderName ?: 'Р Р†Р вЂљРІР‚Сњ');
    $sheet->mergeCells('C16:D18')->setCellValue('C16', $recipientBlock);
    $sheet->mergeCells('E16:F18')->setCellValue('E16', $responsibleBlock);
    $sheet->mergeCells('G16:G18')->setCellValue('G16', (string) ($invoice['transport_company'] ?: ''));
    $sheet->mergeCells('H16:I18')->setCellValue('H16', $transportDoc);
    excel_set_text_cell($sheet, 'A16', $senderName ?: 'РІР‚вЂќ');
    excel_set_text_cell($sheet, 'C16', $recipientBlock);
    excel_set_text_cell($sheet, 'E16', $responsibleBlock);
    excel_set_text_cell($sheet, 'G16', (string) ($invoice['transport_company'] ?: ''));
    excel_set_text_cell($sheet, 'H16', $transportDoc);
    $sheet->getStyle('A15:I18')->getAlignment()->setWrapText(true)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER)->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

    $sheet->mergeCells('A20:A21')->setCellValue('A20', 'Р Р†РІР‚С›РІР‚вЂњ');
    $sheet->mergeCells('B20:B21')->setCellValue('B20', __('invoice_item_name'));
    $sheet->mergeCells('C20:C21')->setCellValue('C20', __('invoice_item_sku'));
    $sheet->mergeCells('D20:D21')->setCellValue('D20', __('lbl_unit'));
    $sheet->setCellValue('E20', __('invoice_to_release'));
    $sheet->setCellValue('F20', __('invoice_released_qty'));
    $sheet->mergeCells('G20:G21')->setCellValue('G20', __('invoice_unit_price_kzt'));
    $sheet->mergeCells('H20:H21')->setCellValue('H20', __('invoice_total_with_tax_kzt'));
    $sheet->mergeCells('I20:I21')->setCellValue('I20', __('invoice_nds_amount_kzt'));
    $sheet->setCellValue('E21', '5');
    $sheet->setCellValue('F21', '6');
    $sheet->getStyle('A20:I21')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER)->setWrapText(true);
    $sheet->getStyle('A20:I21')->getFont()->setBold(true);

    $row = 22;
    $sumQty = 0.0;
    foreach ($items as $index => $item) {
        $sumQty += (float) $item['qty'];
        $sheet->setCellValue('A' . $row, $index + 1);
        $sheet->setCellValue('B' . $row, $item['product_name']);
        $sheet->setCellValue('C' . $row, $item['product_sku']);
        $sheet->setCellValue('D' . $row, unit_label((string) $item['unit']));
        excel_set_text_cell($sheet, 'B' . $row, $item['product_name']);
        excel_set_text_cell($sheet, 'C' . $row, $item['product_sku']);
        excel_set_text_cell($sheet, 'D' . $row, unit_label((string) $item['unit']));
        $sheet->setCellValue('E' . $row, (float) $item['qty']);
        $sheet->setCellValue('F' . $row, (float) $item['qty']);
        $sheet->setCellValue('G' . $row, (float) $item['unit_price']);
        $sheet->setCellValue('H' . $row, (float) $item['line_total']);
        $sheet->setCellValue('I' . $row, (float) $item['tax_amount']);
        $sheet->getStyle('A' . $row . ':I' . $row)->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        $sheet->getStyle('A' . $row . ':A' . $row)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('D' . $row . ':F' . $row)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $row++;
    }

    $sheet->mergeCells('A' . $row . ':D' . $row)->setCellValue('A' . $row, __('lbl_total'));
    $sheet->setCellValue('E' . $row, $sumQty);
    $sheet->setCellValue('F' . $row, $sumQty);
    $sheet->setCellValue('G' . $row, 'X');
    $sheet->setCellValue('H' . $row, (float) $invoice['total']);
    $sheet->setCellValue('I' . $row, (float) $invoice['tax_amount']);
    $sheet->getStyle('A' . $row . ':I' . $row)->getFont()->setBold(true);
    $sheet->getStyle('A' . $row . ':D' . $row)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

    $row += 2;
    $sheet->mergeCells('A' . $row . ':C' . $row)->setCellValue('A' . $row, __('invoice_total_qty_words_label'));
    $sheet->mergeCells('D' . $row . ':E' . $row)->setCellValue('D' . $row, mb_convert_case(si_total_qty_words_x($items), MB_CASE_TITLE, 'UTF-8'));
    $sheet->mergeCells('F' . $row . ':G' . $row)->setCellValue('F' . $row, __('invoice_total_amount_words_label'));
    $sheet->mergeCells('H' . $row . ':I' . $row)->setCellValue('H' . $row, mb_convert_case(si_amount_words_x((float) $invoice['total']), MB_CASE_TITLE, 'UTF-8'));
    excel_set_text_cell($sheet, 'D' . $row, mb_convert_case(si_total_qty_words_x($items), MB_CASE_TITLE, 'UTF-8'));
    excel_set_text_cell($sheet, 'H' . $row, mb_convert_case(si_amount_words_x((float) $invoice['total']), MB_CASE_TITLE, 'UTF-8'));

    $row += 2;
    $sheet->setCellValue('A' . $row, __('invoice_release_authorized'));
    $sheet->setCellValue('B' . $row, '');
    $sheet->setCellValue('C' . $row, '');
    $sheet->setCellValue('D' . $row, (string) ($invoice['sender_responsible_name_snapshot'] ?: 'Р Р†Р вЂљРІР‚Сњ'));
    $sheet->setCellValue('E' . $row, __('invoice_by_power'));
    $sheet->setCellValue('F' . $row, (string) ($invoice['power_of_attorney_no'] ?: ''));
    $sheet->setCellValue('G' . $row, __('invoice_from_date'));
    $sheet->mergeCells('H' . $row . ':I' . $row)->setCellValue('H' . $row, $invoice['power_of_attorney_date'] ? date('d.m.Y', strtotime((string) $invoice['power_of_attorney_date'])) : '');
    excel_set_text_cell($sheet, 'D' . $row, (string) ($invoice['sender_responsible_name_snapshot'] ?: 'РІР‚вЂќ'));
    excel_set_text_cell($sheet, 'F' . $row, (string) ($invoice['power_of_attorney_no'] ?: ''));

    $row++;
    $sheet->setCellValue('B' . $row, __('invoice_position'));
    $sheet->setCellValue('C' . $row, __('invoice_signature'));
    $sheet->setCellValue('D' . $row, __('invoice_signature_name'));
    $sheet->setCellValue('E' . $row, __('invoice_issued_by'));

    $row++;
    $sheet->setCellValue('A' . $row, __('invoice_chief_accountant'));
    $sheet->setCellValue('B' . $row, '');
    $sheet->mergeCells('C' . $row . ':D' . $row)->setCellValue('C' . $row, (string) ($invoice['sender_chief_accountant_snapshot'] ?: __('invoice_not_provided')));
    excel_set_text_cell($sheet, 'C' . $row, (string) ($invoice['sender_chief_accountant_snapshot'] ?: __('invoice_not_provided')));

    $row++;
    $sheet->setCellValue('B' . $row, __('invoice_signature'));
    $sheet->mergeCells('C' . $row . ':D' . $row)->setCellValue('C' . $row, __('invoice_signature_name'));

    $row++;
    $sheet->setCellValue('A' . $row, 'Р В РЎС™.Р В РЎСџ.');

    $row++;
    $sheet->setCellValue('A' . $row, __('invoice_released_by'));
    $sheet->setCellValue('B' . $row, '');
    $sheet->mergeCells('C' . $row . ':D' . $row)->setCellValue('C' . $row, (string) ($invoice['sender_released_by_snapshot'] ?: 'Р Р†Р вЂљРІР‚Сњ'));
    $sheet->setCellValue('E' . $row, __('invoice_received_by'));
    $sheet->setCellValue('F' . $row, '');
    $sheet->mergeCells('G' . $row . ':I' . $row)->setCellValue('G' . $row, $recipientContact ?: 'Р Р†Р вЂљРІР‚Сњ');
    excel_set_text_cell($sheet, 'C' . $row, (string) ($invoice['sender_released_by_snapshot'] ?: 'РІР‚вЂќ'));
    excel_set_text_cell($sheet, 'G' . $row, $recipientContact ?: 'РІР‚вЂќ');

    $row++;
    $sheet->setCellValue('B' . $row, __('invoice_signature'));
    $sheet->mergeCells('C' . $row . ':D' . $row)->setCellValue('C' . $row, __('invoice_signature_name'));
    $sheet->setCellValue('F' . $row, __('invoice_signature'));
    $sheet->mergeCells('G' . $row . ':I' . $row)->setCellValue('G' . $row, __('invoice_signature_name'));

    $lastRow = $row;
    $borderRange = 'A7:I' . $lastRow;
    $sheet->getStyle($borderRange)->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
    $sheet->getStyle('D7:G7')->getFont()->setBold(true);
    $sheet->getStyle('H11:I11')->getFont()->setBold(true);
    $sheet->getStyle('A22:I' . $lastRow)->getAlignment()->setWrapText(true);
    $sheet->getStyle('G22:I' . $lastRow)->getNumberFormat()->setFormatCode('#,##0.00');
    $sheet->getStyle('E22:F' . $lastRow)->getNumberFormat()->setFormatCode('#,##0.###');

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filenameBase . '.xlsx"');
    header('Cache-Control: max-age=0');
    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filenameBase . '.xls"');
header('Cache-Control: max-age=0');
echo "\xEF\xBB\xBF";
?>
<html xmlns:o="urn:schemas-microsoft-com:office:office"
      xmlns:x="urn:schemas-microsoft-com:office:excel"
      xmlns="http://www.w3.org/TR/REC-html40">
<head>
<meta charset="UTF-8">
<style>
body { font-family: "Times New Roman", serif; font-size: 11pt; }
table { border-collapse: collapse; width: 100%; table-layout: fixed; }
th, td { border: 1px solid #000; padding: 4px 6px; vertical-align: middle; }
th { text-align: center; }
.num { text-align: right; }
.center { text-align: center; }
.title { font-size: 16pt; font-weight: bold; text-align: center; margin: 12px 0; }
</style>
</head>
<body>
<div style="text-align:right;font-style:italic;line-height:1.4">
  <div><?= e(__('invoice_appendix_26')) ?></div>
  <div><?= e(__('invoice_finance_order_line_1')) ?></div>
  <div><?= e(__('invoice_finance_order_line_2')) ?></div>
  <div><?= e(__('invoice_finance_order_line_3')) ?></div>
  <div style="margin-top:8px;font-style:normal"><?= e(__('invoice_form_z2')) ?></div>
</div>
<table>
  <tr>
    <td style="width:30%" class="center"><?= e(__('invoice_org_label')) ?></td>
    <td style="width:44%" class="center"><strong><?= e(excel_safe_text($senderName ?: 'вЂ”')) ?></strong></td>
    <td style="width:10%" class="center"><?= e(__('cust_inn')) ?></td>
    <td style="width:16%" class="center"><strong><?= e(excel_safe_text($invoice['sender_iin_bin_snapshot'] ?: 'вЂ”')) ?></strong></td>
  </tr>
</table>
<table style="margin-top:8px">
  <tr>
    <td style="border:none;width:78%"></td>
    <td class="center" style="width:11%"><strong><?= e(__('invoice_doc_number')) ?></strong><br><?= e(excel_safe_text($invoice['invoice_number'])) ?></td>
    <td class="center" style="width:11%"><strong><?= e(__('invoice_doc_date')) ?></strong><br><?= e(date('d.m.Y', strtotime((string) $invoice['invoice_date']))) ?></td>
  </tr>
</table>
<div class="title"><?= e(__('invoice_title')) ?></div>
<table>
  <tr>
    <th style="width:23%"><?= e(__('invoice_sender_header')) ?></th>
    <th style="width:21%"><?= e(__('invoice_recipient_header')) ?></th>
    <th style="width:20%"><?= e(__('invoice_responsible_supply')) ?></th>
    <th style="width:17%"><?= e(__('invoice_transport')) ?></th>
    <th style="width:19%"><?= e(__('invoice_transport_waybill_header')) ?></th>
  </tr>
  <tr>
    <td><?= nl2br(e(excel_safe_text($senderName ?: 'вЂ”'))) ?></td>
    <td><?= nl2br(e(excel_safe_text($recipientBlock))) ?></td>
    <td><?= nl2br(e(excel_safe_text($responsibleBlock))) ?></td>
    <td><?= nl2br(e(excel_safe_text($invoice['transport_company'] ?: ''))) ?></td>
    <td><?= nl2br(e(excel_safe_text($transportDoc ?: ''))) ?></td>
  </tr>
</table>
<table style="margin-top:10px">
  <tr>
    <th rowspan="2" style="width:5%">Р Р†РІР‚С›РІР‚вЂњ</th>
    <th rowspan="2" style="width:25%"><?= e(__('invoice_item_name')) ?></th>
    <th rowspan="2" style="width:9%"><?= e(__('invoice_item_sku')) ?></th>
    <th rowspan="2" style="width:6%"><?= e(__('lbl_unit')) ?></th>
    <th style="width:11%"><?= e(__('invoice_to_release')) ?></th>
    <th style="width:10%"><?= e(__('invoice_released_qty')) ?></th>
    <th rowspan="2" style="width:12%"><?= e(__('invoice_unit_price_kzt')) ?></th>
    <th rowspan="2" style="width:11%"><?= e(__('invoice_total_with_tax_kzt')) ?></th>
    <th rowspan="2" style="width:11%"><?= e(__('invoice_nds_amount_kzt')) ?></th>
  </tr>
  <tr>
    <th class="center">5</th>
    <th class="center">6</th>
  </tr>
  <?php $sumQty = 0.0; foreach ($items as $index => $item): $sumQty += (float) $item['qty']; ?>
    <tr>
      <td class="center"><?= $index + 1 ?></td>
      <td><?= e(excel_safe_text($item['product_name'])) ?></td>
      <td class="center"><?= e(excel_safe_text($item['product_sku'])) ?></td>
      <td class="center"><?= e(excel_safe_text(unit_label((string) $item['unit']))) ?></td>
      <td class="num"><?= fmtQty((float) $item['qty']) ?></td>
      <td class="num"><?= fmtQty((float) $item['qty']) ?></td>
      <td class="num"><?= e(si_money_plain_x((float) $item['unit_price'])) ?></td>
      <td class="num"><?= e(si_money_plain_x((float) $item['line_total'])) ?></td>
      <td class="num"><?= e(si_money_plain_x((float) $item['tax_amount'])) ?></td>
    </tr>
  <?php endforeach; ?>
  <tr>
    <td colspan="4" class="center"><strong><?= e(__('lbl_total')) ?></strong></td>
    <td class="num"><strong><?= fmtQty($sumQty) ?></strong></td>
    <td class="num"><strong><?= fmtQty($sumQty) ?></strong></td>
    <td class="center">X</td>
    <td class="num"><strong><?= e(si_money_plain_x((float) $invoice['total'])) ?></strong></td>
    <td class="num"><strong><?= e(si_money_plain_x((float) $invoice['tax_amount'])) ?></strong></td>
  </tr>
</table>
<table style="margin-top:10px">
  <tr>
    <td style="width:27%"><strong><?= e(__('invoice_total_qty_words_label')) ?></strong></td>
    <td style="width:18%"><em><?= e(mb_convert_case(si_total_qty_words_x($items), MB_CASE_TITLE, 'UTF-8')) ?></em></td>
    <td style="width:18%"><strong><?= e(__('invoice_total_amount_words_label')) ?></strong></td>
    <td style="width:37%"><em><?= e(mb_convert_case(si_amount_words_x((float) $invoice['total']), MB_CASE_TITLE, 'UTF-8')) ?></em></td>
  </tr>
</table>
</body>
</html>
