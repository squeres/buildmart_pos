<?php
require_once __DIR__ . '/../../core/bootstrap.php';
Auth::requireLogin();
Auth::requirePerm('sales');

function si_ru_plural(int $value, array $forms): string
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

function si_ru_triplet(int $value, string $gender = 'm'): string
{
    $hundreds = ['', 'сто', 'двести', 'триста', 'четыреста', 'пятьсот', 'шестьсот', 'семьсот', 'восемьсот', 'девятьсот'];
    $tens = ['', 'десять', 'двадцать', 'тридцать', 'сорок', 'пятьдесят', 'шестьдесят', 'семьдесят', 'восемьдесят', 'девяносто'];
    $teens = ['десять', 'одиннадцать', 'двенадцать', 'тринадцать', 'четырнадцать', 'пятнадцать', 'шестнадцать', 'семнадцать', 'восемнадцать', 'девятнадцать'];
    $ones = [
        'm' => ['', 'один', 'два', 'три', 'четыре', 'пять', 'шесть', 'семь', 'восемь', 'девять'],
        'f' => ['', 'одна', 'две', 'три', 'четыре', 'пять', 'шесть', 'семь', 'восемь', 'девять'],
    ];

    $result = [];
    $h = intdiv($value, 100);
    $t = intdiv($value % 100, 10);
    $o = $value % 10;

    if ($h > 0) {
        $result[] = $hundreds[$h];
    }
    if ($t === 1) {
        $result[] = $teens[$o];
    } else {
        if ($t > 1) {
            $result[] = $tens[$t];
        }
        if ($o > 0) {
            $result[] = $ones[$gender][$o];
        }
    }

    return trim(implode(' ', array_filter($result)));
}

function si_ru_number_words(int $value): string
{
    if ($value === 0) {
        return 'ноль';
    }

    $groups = [
        ['', 'm', ['', '', '']],
        ['тысяча', 'f', ['тысяча', 'тысячи', 'тысяч']],
        ['миллион', 'm', ['миллион', 'миллиона', 'миллионов']],
        ['миллиард', 'm', ['миллиард', 'миллиарда', 'миллиардов']],
    ];

    $parts = [];
    $groupIndex = 0;
    while ($value > 0 && isset($groups[$groupIndex])) {
        $triplet = $value % 1000;
        if ($triplet > 0) {
            [$label, $gender, $forms] = $groups[$groupIndex];
            $chunk = si_ru_triplet($triplet, $gender);
            if ($groupIndex > 0) {
                $chunk .= ' ' . si_ru_plural($triplet, $forms);
            }
            array_unshift($parts, trim($chunk));
        }
        $value = intdiv($value, 1000);
        $groupIndex++;
    }

    return trim(implode(' ', $parts));
}

function si_amount_words(float $amount): string
{
    if (!Lang::isRu()) {
        return money($amount);
    }

    $whole = (int) floor($amount);
    $fraction = (int) round(($amount - $whole) * 100);
    if ($fraction === 100) {
        $whole++;
        $fraction = 0;
    }

    return trim(sprintf('%s тенге %02d тиын', si_ru_number_words($whole), $fraction));
}

function si_total_qty_words(array $items): string
{
    if (!$items) {
        return 'ноль';
    }

    $sum = 0.0;
    foreach ($items as $item) {
        $sum += (float) $item['qty'];
    }

    if (abs($sum - round($sum)) <= 0.00001) {
        return si_ru_number_words((int) round($sum));
    }

    return str_replace('.', ',', rtrim(rtrim(number_format($sum, 3, '.', ''), '0'), '.'));
}

function si_money_plain(float $value): string
{
    return number_format($value, 2, ',', ' ');
}

$id = (int) ($_GET['id'] ?? 0);
$invoice = Database::row(
    "SELECT si.*, s.receipt_no, s.created_at AS sale_created_at, s.subtotal, s.discount_amount,
            s.tax_amount, s.total, s.status AS sale_status,
            w.name AS warehouse_name
     FROM sale_invoices si
     JOIN sales s ON s.id = si.sale_id
     LEFT JOIN warehouses w ON w.id = s.warehouse_id
     WHERE si.id = ?",
    [$id]
);
if (!$invoice) {
    die('Invoice not found');
}

$items = Database::all("SELECT * FROM sale_items WHERE sale_id=? ORDER BY id", [$invoice['sale_id']]);
$senderName = trim((string) ($invoice['sender_legal_name_snapshot'] ?: $invoice['sender_name_snapshot']));
$recipientName = trim((string) ($invoice['customer_company_snapshot'] ?: $invoice['customer_name_snapshot']));
$recipientContact = trim((string) ($invoice['customer_contact_person_snapshot'] ?: $invoice['customer_name_snapshot']));
$recipientBlock = trim(($recipientName ?: '—') . (($invoice['customer_address_snapshot'] ?? '') !== '' ? "\n" . $invoice['customer_address_snapshot'] : ''));
$responsibleBlock = trim(($invoice['sender_responsible_name_snapshot'] ?: '—') . (($invoice['sender_responsible_position_snapshot'] ?? '') !== '' ? "\n" . $invoice['sender_responsible_position_snapshot'] : ''));
$transportDoc = trim((string) $invoice['transport_waybill_no']);
if (!empty($invoice['transport_waybill_date'])) {
    $transportDoc .= ($transportDoc !== '' ? ', ' : '') . date('d.m.Y', strtotime((string) $invoice['transport_waybill_date']));
}
$poaIssuedBy = trim((string) ($invoice['customer_company_snapshot'] ?: $invoice['customer_name_snapshot'] ?: ''));
$totalQtyWords = si_total_qty_words($items);
$totalAmountWords = si_amount_words((float) $invoice['total']);
?>
<!DOCTYPE html>
<html lang="<?= Lang::current() ?>">
<head>
<meta charset="UTF-8">
<title><?= __('doc_delivery_note') ?> <?= e($invoice['invoice_number']) ?></title>
<style>
  * { box-sizing: border-box; }
  body {
    margin: 0;
    padding: 6mm 7mm 10mm;
    background: #fff;
    color: #000;
    font-family: "Times New Roman", Times, serif;
    font-size: 12px;
  }
  .print-actions {
    position: fixed;
    top: 12px;
    right: 12px;
    display: flex;
    gap: 8px;
    z-index: 20;
  }
  .print-actions button {
    border: 0;
    border-radius: 6px;
    padding: 9px 14px;
    cursor: pointer;
    font-family: sans-serif;
    font-size: 13px;
  }
  .print-btn { background: #f5a623; color: #111; }
  .close-btn { background: #6b7280; color: #fff; }
  .sheet { width: 100%; max-width: 1280px; margin: 0 auto; }
  .top-note {
    text-align: right;
    font-style: italic;
    font-size: 12px;
    line-height: 1.35;
    margin-bottom: 12px;
  }
  table { width: 100%; border-collapse: collapse; table-layout: fixed; }
  .header-table td,
  .party-table td,
  .items-table th,
  .items-table td,
  .footer-table td,
  .signature-table td {
    border: 1px solid #000;
    padding: 4px 6px;
    vertical-align: middle;
  }
  .header-table td { height: 34px; }
  .label-cell { font-size: 11px; text-align: center; }
  .value-cell { font-weight: 700; text-align: center; }
  .doc-title {
    text-align: center;
    font-size: 18px;
    font-weight: 700;
    text-transform: uppercase;
    margin: 16px 0 12px;
  }
  .party-table .head {
    text-align: center;
    font-size: 11px;
    height: 40px;
  }
  .party-table .body {
    text-align: center;
    vertical-align: top;
    line-height: 1.4;
    min-height: 84px;
  }
  .items-table th {
    text-align: center;
    font-weight: 700;
    font-size: 11px;
  }
  .items-table td { min-height: 28px; font-size: 11px; }
  .num { text-align: right; white-space: nowrap; }
  .center { text-align: center; }
  .fw-600 { font-weight: 700; }
  .footer-table td,
  .signature-table td { font-size: 12px; }
  .signature-caption {
    display: block;
    font-size: 10px;
    text-align: center;
    padding-top: 3px;
  }
  .line-empty {
    min-height: 18px;
    display: inline-block;
    width: 100%;
    border-bottom: 1px solid #000;
  }
  .mp-cell {
    width: 44px;
    font-weight: 700;
    text-align: center;
  }
  .signature-table .label { white-space: nowrap; }
  .signature-table .name-cell { text-align: center; }
  .signature-table .wide { height: 30px; }
  @media print {
    .print-actions { display: none !important; }
    body { padding: 0; }
    @page { size: A4 landscape; margin: 8mm; }
  }
</style>
</head>
<body>
<div class="print-actions">
  <button class="print-btn" onclick="window.print()"><?= __('btn_print') ?></button>
  <button class="close-btn" onclick="window.close()"><?= __('btn_close') ?></button>
</div>

<div class="sheet">
  <div class="top-note">
    <div><?= __('invoice_appendix_26') ?></div>
    <div><?= __('invoice_finance_order_line_1') ?></div>
    <div><?= __('invoice_finance_order_line_2') ?></div>
    <div><?= __('invoice_finance_order_line_3') ?></div>
    <div style="margin-top:10px;font-style:normal"><?= __('invoice_form_z2') ?></div>
  </div>

  <table class="header-table">
    <colgroup>
      <col style="width:30%"><col style="width:44%"><col style="width:10%"><col style="width:16%">
    </colgroup>
    <tr>
      <td class="label-cell"><?= __('invoice_org_label') ?></td>
      <td class="value-cell"><?= e($senderName ?: '—') ?></td>
      <td class="label-cell"><?= __('cust_inn') ?></td>
      <td class="value-cell"><?= e($invoice['sender_iin_bin_snapshot'] ?: '—') ?></td>
    </tr>
  </table>

  <table class="header-table" style="margin-top:8px">
    <colgroup>
      <col style="width:78%"><col style="width:11%"><col style="width:11%">
    </colgroup>
    <tr>
      <td rowspan="2" style="border:none"></td>
      <td class="label-cell"><?= __('invoice_doc_number') ?></td>
      <td class="label-cell"><?= __('invoice_doc_date') ?></td>
    </tr>
    <tr>
      <td class="value-cell"><?= e($invoice['invoice_number']) ?></td>
      <td class="value-cell"><?= date('d.m.Y', strtotime((string) $invoice['invoice_date'])) ?></td>
    </tr>
  </table>

  <div class="doc-title"><?= __('invoice_title') ?></div>

  <table class="party-table">
    <colgroup>
      <col style="width:23%"><col style="width:21%"><col style="width:20%"><col style="width:17%"><col style="width:19%">
    </colgroup>
    <tr>
      <td class="head"><?= __('invoice_sender_header') ?></td>
      <td class="head"><?= __('invoice_recipient_header') ?></td>
      <td class="head"><?= __('invoice_responsible_supply') ?></td>
      <td class="head"><?= __('invoice_transport') ?></td>
      <td class="head"><?= __('invoice_transport_waybill_header') ?></td>
    </tr>
    <tr>
      <td class="body"><?= nl2br(e($senderName ?: '—')) ?></td>
      <td class="body"><?= nl2br(e($recipientBlock)) ?></td>
      <td class="body"><?= nl2br(e($responsibleBlock)) ?></td>
      <td class="body"><?= nl2br(e($invoice['transport_company'] ?: '')) ?></td>
      <td class="body"><?= nl2br(e($transportDoc ?: '')) ?></td>
    </tr>
  </table>

  <table class="items-table" style="margin-top:10px">
    <colgroup>
      <col style="width:5%"><col style="width:25%"><col style="width:9%"><col style="width:6%"><col style="width:11%"><col style="width:10%"><col style="width:12%"><col style="width:11%"><col style="width:11%">
    </colgroup>
    <thead>
      <tr>
        <th rowspan="2">№</th>
        <th rowspan="2"><?= __('invoice_item_name') ?></th>
        <th rowspan="2"><?= __('invoice_item_sku') ?></th>
        <th rowspan="2"><?= __('lbl_unit') ?></th>
        <th><?= __('invoice_to_release') ?></th>
        <th><?= __('invoice_released_qty') ?></th>
        <th rowspan="2"><?= __('invoice_unit_price_kzt') ?></th>
        <th rowspan="2"><?= __('invoice_total_with_tax_kzt') ?></th>
        <th rowspan="2"><?= __('invoice_nds_amount_kzt') ?></th>
      </tr>
      <tr>
        <th class="center">5</th>
        <th class="center">6</th>
      </tr>
    </thead>
    <tbody>
      <?php $sumQty = 0.0; foreach ($items as $idx => $item): $sumQty += (float) $item['qty']; ?>
        <tr>
          <td class="center"><?= $idx + 1 ?></td>
          <td><?= e($item['product_name']) ?></td>
          <td class="center"><?= e($item['product_sku']) ?></td>
          <td class="center"><?= e(unit_label((string) $item['unit'])) ?></td>
          <td class="num"><?= fmtQty((float) $item['qty']) ?></td>
          <td class="num"><?= fmtQty((float) $item['qty']) ?></td>
          <td class="num"><?= si_money_plain((float) $item['unit_price']) ?></td>
          <td class="num"><?= si_money_plain((float) $item['line_total']) ?></td>
          <td class="num"><?= si_money_plain((float) $item['tax_amount']) ?></td>
        </tr>
      <?php endforeach; ?>
      <tr>
        <td colspan="4" class="center fw-600"><?= __('lbl_total') ?></td>
        <td class="num fw-600"><?= fmtQty($sumQty) ?></td>
        <td class="num fw-600"><?= fmtQty($sumQty) ?></td>
        <td class="center">X</td>
        <td class="num fw-600"><?= si_money_plain((float) $invoice['total']) ?></td>
        <td class="num fw-600"><?= si_money_plain((float) $invoice['tax_amount']) ?></td>
      </tr>
    </tbody>
  </table>

  <table class="footer-table" style="margin-top:10px">
    <colgroup>
      <col style="width:27%"><col style="width:18%"><col style="width:18%"><col style="width:37%">
    </colgroup>
    <tr>
      <td><?= __('invoice_total_qty_words_label') ?></td>
      <td><em><?= e(function_exists('mb_convert_case') ? mb_convert_case($totalQtyWords, MB_CASE_TITLE, 'UTF-8') : ucfirst($totalQtyWords)) ?></em></td>
      <td><?= __('invoice_total_amount_words_label') ?></td>
      <td><em><?= e(function_exists('mb_convert_case') ? mb_convert_case($totalAmountWords, MB_CASE_TITLE, 'UTF-8') : ucfirst($totalAmountWords)) ?></em></td>
    </tr>
  </table>

  <table class="signature-table" style="margin-top:10px">
    <colgroup>
      <col style="width:14%"><col style="width:11%"><col style="width:11%"><col style="width:14%"><col style="width:15%"><col style="width:10%"><col style="width:6%"><col style="width:19%">
    </colgroup>
    <tr>
      <td class="label"><?= __('invoice_release_authorized') ?></td>
      <td class="wide"><span class="line-empty"></span></td>
      <td class="wide"><span class="line-empty"></span></td>
      <td class="name-cell"><?= e($invoice['sender_responsible_name_snapshot'] ?: '—') ?></td>
      <td class="label"><?= __('invoice_by_power') ?></td>
      <td><?= e($invoice['power_of_attorney_no'] ?: '') ?></td>
      <td class="center"><?= __('invoice_from_date') ?></td>
      <td><?= $invoice['power_of_attorney_date'] ? date('d.m.Y', strtotime((string) $invoice['power_of_attorney_date'])) : '' ?></td>
    </tr>
    <tr>
      <td></td>
      <td class="center"><span class="signature-caption"><?= __('invoice_position') ?></span></td>
      <td class="center"><span class="signature-caption"><?= __('invoice_signature') ?></span></td>
      <td class="center"><span class="signature-caption"><?= __('invoice_signature_name') ?></span></td>
      <td class="label"><?= __('invoice_issued_by') ?></td>
      <td colspan="3"></td>
    </tr>
    <tr>
      <td class="label"><?= __('invoice_chief_accountant') ?></td>
      <td class="wide"><span class="line-empty"></span></td>
      <td colspan="2" class="name-cell"><?= e($invoice['sender_chief_accountant_snapshot'] ?: __('invoice_not_provided')) ?></td>
      <td colspan="4"></td>
    </tr>
    <tr>
      <td></td>
      <td class="center"><span class="signature-caption"><?= __('invoice_signature') ?></span></td>
      <td colspan="2" class="center"><span class="signature-caption"><?= __('invoice_signature_name') ?></span></td>
      <td colspan="4"></td>
    </tr>
    <tr>
      <td class="mp-cell">М.П.</td>
      <td colspan="7"></td>
    </tr>
    <tr>
      <td class="label"><?= __('invoice_released_by') ?></td>
      <td class="wide"><span class="line-empty"></span></td>
      <td colspan="2" class="name-cell"><?= e($invoice['sender_released_by_snapshot'] ?: '—') ?></td>
      <td class="label"><?= __('invoice_received_by') ?></td>
      <td class="wide"><span class="line-empty"></span></td>
      <td colspan="2" class="name-cell"><?= e($recipientContact ?: '—') ?></td>
    </tr>
    <tr>
      <td></td>
      <td class="center"><span class="signature-caption"><?= __('invoice_signature') ?></span></td>
      <td colspan="2" class="center"><span class="signature-caption"><?= __('invoice_signature_name') ?></span></td>
      <td></td>
      <td class="center"><span class="signature-caption"><?= __('invoice_signature') ?></span></td>
      <td colspan="2" class="center"><span class="signature-caption"><?= __('invoice_signature_name') ?></span></td>
    </tr>
  </table>
</div>
</body>
</html>
