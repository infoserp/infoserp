<?php
/**
 * modules/invoice/print_format/sales_invoice_print_01.php — Sales Invoice / Tax Invoice (A4)
 *
 * Included by shared/print_preview.php, which provides:
 *   $con, $company_code, $branch_code, $doc_code, $doc_type, $doc_no, $company (erp_print_company)
 * Echoes the A4 sheets; sets $print_title, or $print_error when the document can't be printed.
 *
 * Letter header / footer images come from branch_master.header_filepath / footer_filepath;
 * the areas stay blank (for pre-printed stationery) when no image is set.
 */

$print_title = 'Sales Invoice';

$stmt_h = $con->prepare(
    "SELECT ih.*,
            ISNULL(am.description, '')  AS customer_name_db,
            ISNULL(s.salesman_name, '') AS salesman_name_db
     FROM invoice_h ih
     LEFT JOIN ac_master am ON am.ac_code = ih.ac_code AND am.company_code = ih.company_code
     LEFT JOIN salesman s   ON s.salesman_code = ih.salesman_code AND s.company_code = ih.company_code
     WHERE ih.doc_no = ? AND ih.company_code = ? AND ih.branch_code = ?"
);
$stmt_h->execute([$doc_no, $company_code, $branch_code]);
$inv_h = $stmt_h->fetch(PDO::FETCH_ASSOC);
if (!$inv_h) {
    $print_error = 'Sales Invoice ' . $doc_no . ' was not found.';
    return;
}

$stmt_d = $con->prepare(
    "SELECT item_code, item_description, trans_qty, trans_rate, discount_amount, tax_amount, tax1_percent, tax1_amount
     FROM invoice_d
     WHERE doc_no = ? AND company_code = ? AND branch_code = ?
     ORDER BY serial_no ASC"
);
$stmt_d->execute([$doc_no, $company_code, $branch_code]);

$inv_rows = [];
$inv_tot = ['qty' => 0, 'discount' => 0, 'amount' => 0, 'excise' => 0, 'net' => 0, 'vat' => 0];
$inv_vat_pcts = [];
while ($d = $stmt_d->fetch(PDO::FETCH_ASSOC)) {
    $qty      = (float) $d['trans_qty'];
    $rate     = (float) $d['trans_rate'];
    $discount = (float) $d['discount_amount'];
    $amount   = round($qty * $rate - $discount, 2);
    $excise   = (float) $d['tax_amount'];
    $net      = round($amount + $excise, 2);
    $inv_tot['qty']      += $qty;
    $inv_tot['discount'] += $discount;
    $inv_tot['amount']   += $amount;
    $inv_tot['excise']   += $excise;
    $inv_tot['net']      += $net;
    $inv_tot['vat']      += (float) $d['tax1_amount'];
    $inv_vat_pcts[erp_print_qty($d['tax1_percent'])] = true;
    $inv_rows[] = [
        'code'     => trim((string) $d['item_code']),
        'desc'     => trim((string) $d['item_description']),
        'qty'      => $qty,
        'rate'     => $rate,
        'discount' => $discount,
        'amount'   => $amount,
        'ex_rate'  => $qty != 0 ? $excise / $qty : 0,
        'excise'   => $excise,
        'net'      => $net,
    ];
}
$inv_net_total = round($inv_tot['net'] + $inv_tot['vat'], 2);
$inv_vat_label = count($inv_vat_pcts) === 1 ? 'VAT@' . array_key_first($inv_vat_pcts) . '%' : 'VAT';

// Branch TRN and letter header / footer images
$stmt_b = $con->prepare(
    "SELECT tax_no, header_filepath, footer_filepath FROM branch_master WHERE company_code = ? AND branch_code = ?"
);
$stmt_b->execute([$company_code, $branch_code]);
$inv_branch = $stmt_b->fetch(PDO::FETCH_ASSOC) ?: [];
$inv_trn        = trim((string) ($inv_branch['tax_no'] ?? ''));
$inv_header_img = erp_print_image_data_uri($inv_branch['header_filepath'] ?? '');
$inv_footer_img = erp_print_image_data_uri($inv_branch['footer_filepath'] ?? '');

// Currency name and (when the currency master has one) the fraction name used in the amount in words
$inv_currency_code = trim((string) ($inv_h['currency_code'] ?? ''));
$stmt_c = $con->prepare("SELECT * FROM currency_master WHERE company_code = ? AND currency_code = ?");
$stmt_c->execute([$company_code, $inv_currency_code]);
$inv_currency = $stmt_c->fetch(PDO::FETCH_ASSOC) ?: [];
$inv_currency_name = trim((string) ($inv_currency['currency_name'] ?? '')) ?: $inv_currency_code;
$inv_fraction_name = '';
foreach (['fraction_name', 'decimal_name', 'sub_unit', 'sub_currency', 'currency_fraction', 'fraction'] as $col) {
    if (trim((string) ($inv_currency[$col] ?? '')) !== '') {
        $inv_fraction_name = trim((string) $inv_currency[$col]);
        break;
    }
}
$inv_words = erp_print_amount_words($inv_net_total, $inv_currency_code, $inv_fraction_name);

$inv_customer = trim((string) $inv_h['customer_name_db']) ?: trim((string) $inv_h['ac_code']);
$inv_remark   = trim((string) ($inv_h['remark'] ?? $inv_h['remarks'] ?? ''));

// Bill-to / doc boxes only on page 1. Format 1 still shows totals/notes/sign
// on every page, so continuation pages gain the box height as extra item room.
$inv_area_mm  = 105;
$inv_first_area_mm = 70;
$inv_row_mm   = 5.5;
$inv_wrap_mm  = 4;
$inv_wrap_chr = 23;
$inv_last_area_mm = 70;

list($inv_pages, $inv_page_mm) = erp_print_paginate_detail_rows($inv_rows, [
    'area_mm'            => $inv_area_mm,
    'first_area_mm'      => $inv_first_area_mm,
    'last_area_mm'       => $inv_last_area_mm,
    'first_last_area_mm' => $inv_first_area_mm,
    'row_mm'             => $inv_row_mm,
    'wrap_mm'            => $inv_wrap_mm,
    'wrap_chr'           => $inv_wrap_chr,
]);
$inv_page_count = count($inv_pages);
$inv_line_no = 0;

foreach ($inv_pages as $page_index => $page_rows) {
    $is_last = ($page_index === $inv_page_count - 1);
    ?>
<div class="erp-print-sheet pf-inv-sheet<?php echo $page_index > 0 ? ' pf-break' : ''; ?>">
    <div class="pf-inv-head"><?php if ($inv_header_img !== '') { ?><img src="<?php echo $inv_header_img; ?>" alt=""><?php } ?></div>

    <div class="pf-inv-body">
        <?php if ($page_index === 0) { ?>
        <div class="pf-inv-title">
            <div class="pf-inv-title-main">TAX INVOICE</div>
            <?php if ($inv_trn !== '') { ?><div class="pf-inv-trn">TRN : <?php echo htmlspecialchars($inv_trn); ?></div><?php } ?>
        </div>

        <table class="pf-inv-boxes">
            <tr>
                <td class="pf-inv-box">
                    <div class="pf-inv-cap">Bill To</div>
                    <div class="pf-inv-cust"><?php echo htmlspecialchars($inv_customer); ?></div>
                    <table class="pf-inv-kv">
                        <tr><td class="pf-k">Contact Person</td><td class="pf-sep">:</td><td class="pf-v"><?php echo htmlspecialchars(trim((string) ($inv_h['contact_name'] ?? ''))); ?></td></tr>
                        <tr><td class="pf-k">Narration</td><td class="pf-sep">:</td><td class="pf-v"><?php echo htmlspecialchars(trim((string) ($inv_h['narration'] ?? ''))); ?></td></tr>
                        <tr><td class="pf-k">Remark</td><td class="pf-sep">:</td><td class="pf-v"><?php echo htmlspecialchars($inv_remark); ?></td></tr>
                    </table>
                </td>
                <td class="pf-gap"></td>
                <td class="pf-inv-box pf-inv-docbox">
                    <table class="pf-inv-kv">
                        <tr class="pf-inv-no"><td class="pf-k">No</td><td class="pf-sep">:</td><td class="pf-v"><?php echo htmlspecialchars(trim((string) $inv_h['doc_no'])); ?></td></tr>
                        <tr><td class="pf-k">Date</td><td class="pf-sep">:</td><td class="pf-v"><?php echo htmlspecialchars(erp_print_date($inv_h['doc_dt'] ?? '')); ?></td></tr>
                        <tr><td class="pf-k">PO No</td><td class="pf-sep">:</td><td class="pf-v"><?php echo htmlspecialchars(trim((string) ($inv_h['po_no'] ?? ''))); ?></td></tr>
                        <tr><td class="pf-k">Proforma No</td><td class="pf-sep">:</td><td class="pf-v"><?php echo htmlspecialchars(trim((string) ($inv_h['qtn_no'] ?? ''))); ?></td></tr>
                        <tr><td class="pf-k">Sales Person</td><td class="pf-sep">:</td><td class="pf-v"><?php echo htmlspecialchars(trim((string) $inv_h['salesman_name_db'])); ?></td></tr>
                        <tr><td class="pf-k">Currency</td><td class="pf-sep">:</td><td class="pf-v"><?php echo htmlspecialchars($inv_currency_name); ?></td></tr>
                    </table>
                </td>
            </tr>
        </table>
        <?php } ?>

        <table class="pf-items pf-inv-items">
            <thead>
                <tr>
                    <th style="width: 6mm;">No</th>
                    <th style="width: 13mm;">Code</th>
                    <th style="width: 42mm;">Item Description</th>
                    <th style="width: 10mm;">Qty</th>
                    <th style="width: 14mm;">Rate</th>
                    <th style="width: 13mm;">Discount</th>
                    <th style="width: 16mm;">Amount</th>
                    <th style="width: 12mm;">Excise Rate</th>
                    <th style="width: 15mm;">Excise Tax</th>
                    <th style="width: 18mm;">Net Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($page_rows as $r) {
                    $show = !empty($r['show_values']);
                    if ($show) {
                        $inv_line_no++;
                    }
                    $desc_html = $r['desc'] !== '' ? nl2br(htmlspecialchars($r['desc'])) : '';
                    if (!empty($r['continued']) && $desc_html === '') {
                        $desc_html = '<span class="pf-inv-cont-label">(continued)</span>';
                    }
                    ?>
                <tr>
                    <td class="center"><?php echo $show ? $inv_line_no : ''; ?></td>
                    <td><?php echo $show ? htmlspecialchars($r['code']) : ''; ?></td>
                    <td class="wrap"><?php echo $desc_html; ?></td>
                    <td class="center"><?php echo $show ? erp_print_qty($r['qty']) : ''; ?></td>
                    <td class="num"><?php echo $show ? erp_print_amount($r['rate']) : ''; ?></td>
                    <td class="num"><?php echo $show ? erp_print_amount($r['discount']) : ''; ?></td>
                    <td class="num"><?php echo $show ? erp_print_amount($r['amount']) : ''; ?></td>
                    <td class="num"><?php echo $show ? erp_print_amount($r['ex_rate']) : ''; ?></td>
                    <td class="num"><?php echo $show ? erp_print_amount($r['excise']) : ''; ?></td>
                    <td class="num"><?php echo $show ? erp_print_amount($r['net']) : ''; ?></td>
                </tr>
                <?php } ?>
                <tr class="pf-total">
                    <td colspan="3" class="num"><?php echo $is_last ? 'TOTAL :' : 'Continued on next page'; ?></td>
                    <td class="center"><?php echo $is_last ? erp_print_qty($inv_tot['qty']) : ''; ?></td>
                    <td></td>
                    <td class="num"><?php echo $is_last ? erp_print_amount($inv_tot['discount']) : ''; ?></td>
                    <td class="num"><?php echo $is_last ? erp_print_amount($inv_tot['amount']) : ''; ?></td>
                    <td></td>
                    <td class="num"><?php echo $is_last ? erp_print_amount($inv_tot['excise']) : ''; ?></td>
                    <td class="num"><?php echo $is_last ? erp_print_amount($inv_tot['net']) : ''; ?></td>
                </tr>
            </tbody>
        </table>

        <table class="pf-inv-sum">
            <tr>
                <td class="pf-inv-words" rowspan="2">
                    <?php if ($is_last) { ?>
                    <div class="pf-inv-cap">Amount in words</div>
                    <div class="pf-inv-words-text"><?php echo htmlspecialchars($inv_words); ?></div>
                    <?php } ?>
                    <div class="pf-inv-dono">D.O. No : <?php echo htmlspecialchars(trim((string) ($inv_h['do_no'] ?? ''))); ?></div>
                </td>
                <td class="pf-inv-sum-k"><?php echo htmlspecialchars($inv_vat_label); ?></td>
                <td class="pf-inv-sum-v"><?php echo $is_last ? erp_print_amount($inv_tot['vat']) : ''; ?></td>
            </tr>
            <tr class="pf-inv-net">
                <td class="pf-inv-sum-k">Net Total</td>
                <td class="pf-inv-sum-v"><?php echo $is_last ? erp_print_amount($inv_net_total) : ''; ?></td>
            </tr>
        </table>

        <div class="pf-inv-notes">
            <div>All Cheques to be issued in favour of <?php echo htmlspecialchars($company['name'] ?? ''); ?></div>
            <div>And must be A/c Payee only</div>
            <div>Goods once sold will not be taken back</div>
            <div>Received above goods in good condition</div>
        </div>

        <table class="pf-inv-sign">
            <tr>
                <td><span>Salesman Signature</span></td>
                <td class="pf-gap"></td>
                <td><span>Customer Signature</span></td>
            </tr>
        </table>

        <div class="pf-pageno">Page <?php echo $page_index + 1; ?> of <?php echo $inv_page_count; ?></div>
    </div>

    <div class="pf-inv-foot"><?php if ($inv_footer_img !== '') { ?><img src="<?php echo $inv_footer_img; ?>" alt=""><?php } ?></div>
</div>
    <?php
}
