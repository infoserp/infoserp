<?php
/**
 * modules/invoice/print_format/sales_invoice_print_02.php — Tax Invoice format 2 (A4)
 *
 * Included by shared/print_preview.php, which provides:
 *   $con, $company_code, $branch_code, $doc_code, $doc_type, $doc_no, $company (erp_print_company)
 * Echoes the A4 sheets; sets $print_title, or $print_error when the document can't be printed.
 *
 * Layout matches the Tax Invoice sample (English labels only — no Arabic title).
 * Bank lines come from invoice_bank for invoice_h.bank_code.
 * Letter header / footer images from branch_master.header_filepath / footer_filepath;
 * blank bands are kept when no path is set.
 */

$print_title = 'Tax Invoice';

$stmt_h = $con->prepare(
    "SELECT ih.*,
            ISNULL(am.description, '')  AS customer_name_db,
            ISNULL(am.tax_no, '')       AS customer_tax_no,
            ISNULL(am.po_box, '')       AS customer_po_box,
            ISNULL(am.address, '')      AS customer_address,
            ISNULL(am.address1, '')     AS customer_address1,
            ISNULL(am.street, '')       AS customer_street,
            ISNULL(am.city, '')         AS customer_city,
            ISNULL(am.tel_off, '')      AS customer_tel,
            ISNULL(am.mobile1, '')      AS customer_mobile,
            ISNULL(am.contact_name, '') AS customer_contact_name,
            ISNULL(am.contact_no, '')   AS customer_contact_no
     FROM invoice_h ih
     LEFT JOIN ac_master am ON am.ac_code = ih.ac_code AND am.company_code = ih.company_code
     WHERE ih.doc_no = ? AND ih.company_code = ? AND ih.branch_code = ?"
);
$stmt_h->execute([$doc_no, $company_code, $branch_code]);
$inv_h = $stmt_h->fetch(PDO::FETCH_ASSOC);
if (!$inv_h) {
    $print_error = 'Sales Invoice ' . $doc_no . ' was not found.';
    return;
}

$stmt_d = $con->prepare(
    "SELECT item_description, narration, remark,
            trans_qty, trans_rate, discount_amount, tax1_percent, tax1_amount
     FROM invoice_d
     WHERE doc_no = ? AND company_code = ? AND branch_code = ?
     ORDER BY serial_no ASC"
);
$stmt_d->execute([$doc_no, $company_code, $branch_code]);

$inv_rows = [];
$inv_tot = ['taxable' => 0.0, 'vat' => 0.0, 'net' => 0.0];
$inv_vat_pcts = [];
while ($d = $stmt_d->fetch(PDO::FETCH_ASSOC)) {
    $qty      = (float) $d['trans_qty'];
    $rate     = (float) $d['trans_rate'];
    $discount = (float) $d['discount_amount'];
    // Taxable Amount = qty × rate − discount (same as detail screen)
    $taxable  = round($qty * $rate - $discount, 2);
    if ($taxable < 0) {
        $taxable = 0.0;
    }
    $vat_pct  = (float) ($d['tax1_percent'] ?? 0);
    $vat_amt  = (float) ($d['tax1_amount'] ?? 0);
    // Prefer stored tax1_amount; if missing, derive from taxable × vat%
    if ($vat_amt == 0.0 && $vat_pct > 0 && $taxable > 0) {
        $vat_amt = round(($taxable * $vat_pct) / 100, 2);
    }
    $net = round($taxable + $vat_amt, 2);

    $desc = trim((string) $d['item_description']);
    $note = trim((string) ($d['narration'] ?? ''));
    if ($note === '') {
        $note = trim((string) ($d['remark'] ?? ''));
    }

    $inv_tot['taxable'] += $taxable;
    $inv_tot['vat']     += $vat_amt;
    $inv_tot['net']     += $net;
    $inv_vat_pcts[erp_print_qty($vat_pct)] = true;

    $inv_rows[] = [
        'desc'    => $desc,
        'note'    => $note,
        'qty'     => $qty,
        'rate'    => $rate,
        'taxable' => $taxable,
        'vat_pct' => $vat_pct,
        'vat_amt' => $vat_amt,
        'net'     => $net,
        // paginator uses amount field name historically for wrap sizing only
        'amount'  => $taxable,
    ];
}

$header_vat = (float) ($inv_h['tax1_amount'] ?? 0);
if ($header_vat > 0 && $inv_tot['vat'] <= 0) {
    $inv_tot['vat'] = $header_vat;
    $inv_tot['net'] = round($inv_tot['taxable'] + $inv_tot['vat'], 2);
}

$inv_taxable   = round($inv_tot['taxable'], 2);
$inv_vat       = round($inv_tot['vat'], 2);
$inv_net_total = round($inv_tot['net'], 2);
if ($inv_net_total <= 0) {
    $inv_net_total = round($inv_taxable + $inv_vat, 2);
}

if (count($inv_vat_pcts) === 1) {
    $inv_vat_label = 'VAT ' . array_key_first($inv_vat_pcts) . '%';
} elseif (isset($inv_h['tax1_percent']) && $inv_h['tax1_percent'] !== '' && $inv_h['tax1_percent'] !== null) {
    $inv_vat_label = 'VAT ' . erp_print_qty($inv_h['tax1_percent']) . '%';
} else {
    $inv_vat_label = 'VAT';
}

// Branch TRN + letter header / footer / stamp images
$stmt_b = $con->prepare(
    "SELECT tax_no, header_filepath, footer_filepath, stamp_path
     FROM branch_master WHERE company_code = ? AND branch_code = ?"
);
$stmt_b->execute([$company_code, $branch_code]);
$inv_branch = $stmt_b->fetch(PDO::FETCH_ASSOC) ?: [];
$inv_trn        = trim((string) ($inv_branch['tax_no'] ?? ''));
$inv_header_img = erp_print_image_data_uri($inv_branch['header_filepath'] ?? '');
$inv_footer_img = erp_print_image_data_uri($inv_branch['footer_filepath'] ?? '');
$inv_stamp_img  = erp_print_image_data_uri($inv_branch['stamp_path'] ?? '');

// Currency
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
$inv_ccy_hdr = $inv_currency_code !== '' ? $inv_currency_code : 'AED';

// Customer block
$inv_customer = trim((string) $inv_h['customer_name_db']) ?: trim((string) $inv_h['ac_code']);

$inv_addr_parts = [];
$po = trim((string) ($inv_h['customer_po_box'] ?? ''));
if ($po !== '' && $po !== '.') {
    $inv_addr_parts[] = 'P.O.Box : ' . $po;
}
foreach (['customer_address', 'customer_address1', 'customer_street'] as $ak) {
    $v = trim((string) ($inv_h[$ak] ?? ''));
    if ($v !== '' && $v !== '.' && !in_array($v, $inv_addr_parts, true)) {
        $inv_addr_parts[] = $v;
    }
}
// Sample appends country-ish text on the address line when present in address fields
$inv_address = implode(' ', $inv_addr_parts);

$inv_city = trim((string) ($inv_h['city'] ?? ''));
if ($inv_city === '') {
    $inv_city = trim((string) ($inv_h['customer_city'] ?? ''));
}

$inv_phone = trim((string) ($inv_h['tel_off'] ?? ''));
if ($inv_phone === '') {
    $inv_phone = trim((string) ($inv_h['customer_tel'] ?? ''));
}
if ($inv_phone === '') {
    $inv_phone = trim((string) ($inv_h['mob_no'] ?? $inv_h['customer_mobile'] ?? ''));
}
$inv_phone_disp = $inv_phone !== '' ? ('Tel: ' . $inv_phone) : '';

$inv_contact_name = trim((string) ($inv_h['contact_name'] ?? ''))
    ?: trim((string) ($inv_h['customer_contact_name'] ?? ''));
$inv_contact_phone = trim((string) ($inv_h['contact_no'] ?? ''))
    ?: trim((string) ($inv_h['customer_contact_no'] ?? ''))
    ?: trim((string) ($inv_h['customer_mobile'] ?? ''));
$inv_contact = trim($inv_contact_name . ($inv_contact_name !== '' && $inv_contact_phone !== '' ? ' - ' : '') . $inv_contact_phone);

$inv_cust_trn = trim((string) ($inv_h['tax_no'] ?? '')) ?: trim((string) ($inv_h['customer_tax_no'] ?? ''));

$inv_contract = trim((string) ($inv_h['contract_no'] ?? ''));
if ($inv_contract === '') {
    $inv_contract = trim((string) ($inv_h['project'] ?? ''));
}
if ($inv_contract === '') {
    $inv_contract = trim((string) ($inv_h['job_no'] ?? ''));
}

$inv_po_no   = trim((string) ($inv_h['po_no'] ?? ''));
$inv_po_date = trim((string) ($inv_h['po_date'] ?? ''));

// Bank details from invoice_bank for this invoice's bank_code
$inv_bank_lines = [];
$inv_bank_code = trim((string) ($inv_h['bank_code'] ?? '0'));
if ($inv_bank_code === '') {
    $inv_bank_code = '0';
}
$stmt_bank = $con->prepare(
    "SELECT bank_label, bank_detail, bank_label2, bank_detail2
     FROM invoice_bank
     WHERE company_code = ? AND branch_code = ? AND bank_code = ?
     ORDER BY sort_order ASC, serial_no ASC"
);
$stmt_bank->execute([$company_code, $branch_code, $inv_bank_code]);
while ($b = $stmt_bank->fetch(PDO::FETCH_ASSOC)) {
    $label = trim((string) ($b['bank_label'] ?? ''));
    $detail = trim((string) ($b['bank_detail'] ?? ''));
    if ($label !== '' || $detail !== '') {
        $inv_bank_lines[] = ['label' => $label, 'detail' => $detail];
    }
    $label2 = trim((string) ($b['bank_label2'] ?? ''));
    $detail2 = trim((string) ($b['bank_detail2'] ?? ''));
    if ($label2 !== '' || $detail2 !== '') {
        $inv_bank_lines[] = ['label' => $label2, 'detail' => $detail2];
    }
}

$inv_company_name = trim((string) ($company['name'] ?? ''));

// Salesman signature image (invoice_h.salesman_code → salesman.signature_file)
$inv_sign_img = '';
$inv_salesman_code = trim((string) ($inv_h['salesman_code'] ?? ''));
if ($inv_salesman_code !== '') {
    $stmt_sm = $con->prepare(
        "SELECT signature_file FROM salesman WHERE company_code = ? AND salesman_code = ?"
    );
    $stmt_sm->execute([$company_code, $inv_salesman_code]);
    $sm = $stmt_sm->fetch(PDO::FETCH_ASSOC) ?: [];
    $inv_sign_img = erp_print_image_data_uri($sm['signature_file'] ?? '');
}

// Pagination: customer/info boxes only on page 1; narrower desc column with new fields.
// Sign/stamp/company flow under the table (not pinned to letter-footer).
$inv_row_mm   = 5.5;
$inv_wrap_mm  = 4;
$inv_wrap_chr = 32;
$inv_area_mm  = 155;
$inv_first_area_mm = 110;
// bank+totals(~32) + words(~8) + breakup(~12) + sign(~32) + pad
$inv_footer_reserve = 100.0;
$inv_last_area_mm = max(24.0, min($inv_area_mm, 205.0 - $inv_footer_reserve));
$inv_first_last_area_mm = max(18.0, min($inv_first_area_mm, 170.0 - $inv_footer_reserve));

list($inv_pages, $inv_page_mm) = erp_print_paginate_detail_rows($inv_rows, [
    'area_mm'            => $inv_area_mm,
    'first_area_mm'      => $inv_first_area_mm,
    'last_area_mm'       => $inv_last_area_mm,
    'first_last_area_mm' => $inv_first_last_area_mm,
    'row_mm'             => $inv_row_mm,
    'wrap_mm'            => $inv_wrap_mm,
    'wrap_chr'           => $inv_wrap_chr,
]);
$inv_page_count = count($inv_pages);
$inv_line_no = 0;

foreach ($inv_pages as $page_index => $page_rows) {
    $is_last = ($page_index === $inv_page_count - 1);
    ?>
<div class="erp-print-sheet pf-inv2-sheet<?php echo $page_index > 0 ? ' pf-break' : ''; ?>">
    <div class="pf-inv2-head"><?php if ($inv_header_img !== '') { ?><img src="<?php echo $inv_header_img; ?>" alt=""><?php } ?></div>

    <div class="pf-inv2-body">
        <?php if ($page_index === 0) { ?>
        <div class="pf-inv2-title-box">
            <div class="pf-inv2-title">TAX INVOICE</div>
            <div class="pf-inv2-trn">T.R.No. : <?php echo htmlspecialchars($inv_trn); ?></div>
        </div>

        <table class="pf-inv2-info">
            <tr>
                <td class="pf-inv2-info-l">
                    <table class="pf-inv2-kv">
                        <tr>
                            <td class="pf-k">Name</td><td class="pf-sep">:</td>
                            <td class="pf-v"><?php echo htmlspecialchars($inv_customer); ?></td>
                        </tr>
                        <tr>
                            <td class="pf-k">Address</td><td class="pf-sep">:</td>
                            <td class="pf-v"><?php echo htmlspecialchars($inv_address); ?></td>
                        </tr>
                        <tr>
                            <td class="pf-k">City</td><td class="pf-sep">:</td>
                            <td class="pf-v"><?php echo htmlspecialchars($inv_city); ?></td>
                        </tr>
                        <tr>
                            <td class="pf-k">Phone</td><td class="pf-sep">:</td>
                            <td class="pf-v"><?php echo htmlspecialchars($inv_phone_disp); ?></td>
                        </tr>
                        <tr>
                            <td class="pf-k">Contact</td><td class="pf-sep">:</td>
                            <td class="pf-v"><?php echo htmlspecialchars($inv_contact); ?></td>
                        </tr>
                        <tr>
                            <td class="pf-k">TR.No.</td><td class="pf-sep">:</td>
                            <td class="pf-v"><?php echo htmlspecialchars($inv_cust_trn); ?></td>
                        </tr>
                    </table>
                </td>
                <td class="pf-inv2-info-r">
                    <table class="pf-inv2-kv">
                        <tr>
                            <td class="pf-k">Invoice No</td><td class="pf-sep">:</td>
                            <td class="pf-v"><?php echo htmlspecialchars(trim((string) $inv_h['doc_no'])); ?></td>
                        </tr>
                        <tr>
                            <td class="pf-k">Date</td><td class="pf-sep">:</td>
                            <td class="pf-v"><?php echo htmlspecialchars(erp_print_date($inv_h['doc_dt'] ?? '')); ?></td>
                        </tr>
                        <tr>
                            <td class="pf-k">PO No</td><td class="pf-sep">:</td>
                            <td class="pf-v"><?php echo htmlspecialchars($inv_po_no); ?></td>
                        </tr>
                        <tr>
                            <td class="pf-k">PO Date</td><td class="pf-sep">:</td>
                            <td class="pf-v"><?php echo htmlspecialchars(erp_print_date($inv_po_date)); ?></td>
                        </tr>
                        <tr>
                            <td class="pf-k">Contract No</td><td class="pf-sep">:</td>
                            <td class="pf-v"><?php echo htmlspecialchars($inv_contract); ?></td>
                        </tr>
                        <tr>
                            <td class="pf-k">Currency</td><td class="pf-sep">:</td>
                            <td class="pf-v"><?php echo htmlspecialchars($inv_currency_code !== '' ? $inv_currency_code : $inv_currency_name); ?></td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
        <?php } ?>

        <?php if (!empty($page_rows)) { ?>
        <table class="pf-items pf-inv2-items<?php echo $page_index > 0 ? ' pf-inv2-items-cont' : ''; ?>">
            <colgroup>
                <col class="pf-inv2-c-no">
                <col class="pf-inv2-c-desc">
                <col class="pf-inv2-c-qty">
                <col class="pf-inv2-c-rate">
                <col class="pf-inv2-c-taxable">
                <col class="pf-inv2-c-vpct">
                <col class="pf-inv2-c-vat">
                <col class="pf-inv2-c-net">
            </colgroup>
            <thead>
                <tr>
                    <th>No</th>
                    <th>Description</th>
                    <th>Qty</th>
                    <th>Rate</th>
                    <th>Taxable Amount</th>
                    <th>Vat %</th>
                    <th>Vat Amount</th>
                    <th>Net Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($page_rows as $r) {
                    $show = !empty($r['show_values']);
                    if ($show) {
                        $inv_line_no++;
                    }
                    $desc_html = $r['desc'] !== '' ? nl2br(htmlspecialchars($r['desc'])) : '';
                    if ($r['note'] !== '') {
                        $desc_html .= '<div class="pf-inv2-note">' . nl2br(htmlspecialchars($r['note'])) . '</div>';
                    }
                    if (!empty($r['continued']) && $desc_html === '') {
                        $desc_html = '<span class="pf-inv2-cont-label">(continued)</span>';
                    }
                    ?>
                <tr>
                    <td class="center"><?php echo $show ? $inv_line_no : ''; ?></td>
                    <td class="wrap"><?php echo $desc_html; ?></td>
                    <td class="num"><?php echo $show ? erp_print_qty($r['qty']) : ''; ?></td>
                    <td class="num"><?php echo $show ? erp_print_amount($r['rate']) : ''; ?></td>
                    <td class="num"><?php echo $show ? erp_print_amount($r['taxable']) : ''; ?></td>
                    <td class="center"><?php echo $show ? erp_print_qty($r['vat_pct']) : ''; ?></td>
                    <td class="num"><?php echo $show ? erp_print_amount($r['vat_amt']) : ''; ?></td>
                    <td class="num"><?php echo $show ? erp_print_amount($r['net']) : ''; ?></td>
                </tr>
                <?php } ?>
            </tbody>
        </table>
        <?php } ?>

        <?php if ($is_last) { ?>
        <!-- Same column widths as items table so totals lines match Vat Amount / Net Amount -->
        <table class="pf-inv2-bottom">
            <colgroup>
                <col class="pf-inv2-c-no">
                <col class="pf-inv2-c-desc">
                <col class="pf-inv2-c-qty">
                <col class="pf-inv2-c-rate">
                <col class="pf-inv2-c-taxable">
                <col class="pf-inv2-c-vpct">
                <col class="pf-inv2-c-vat">
                <col class="pf-inv2-c-net">
            </colgroup>
            <tr>
                <td class="pf-inv2-bank" colspan="5">
                    <div class="pf-inv2-bank-title">BANK ACCOUNT :</div>
                    <table class="pf-inv2-bank-kv">
                        <?php foreach ($inv_bank_lines as $bl) { ?>
                        <tr>
                            <td class="pf-k"><?php echo htmlspecialchars($bl['label']); ?></td>
                            <td class="pf-sep"><?php echo $bl['label'] !== '' ? ':' : ''; ?></td>
                            <td class="pf-v"><?php echo htmlspecialchars($bl['detail']); ?></td>
                        </tr>
                        <?php } ?>
                    </table>
                </td>
                <td class="pf-inv2-sum-k" colspan="2">
                    <div class="pf-inv2-sum-line">Taxable Amount</div>
                    <div class="pf-inv2-sum-line"><?php echo htmlspecialchars($inv_vat_label); ?></div>
                    <div class="pf-inv2-sum-line pf-inv2-net">Net Amount</div>
                </td>
                <td class="pf-inv2-sum-v">
                    <div class="pf-inv2-sum-line"><?php echo erp_print_amount($inv_taxable); ?></div>
                    <div class="pf-inv2-sum-line"><?php echo erp_print_amount($inv_vat); ?></div>
                    <div class="pf-inv2-sum-line pf-inv2-net"><?php echo erp_print_amount($inv_net_total); ?></div>
                </td>
            </tr>
        </table>

        <div class="pf-inv2-words"><?php echo htmlspecialchars($inv_words); ?></div>

        <div class="pf-inv2-sign-block">
            <div class="pf-inv2-sign-media">
                <?php if ($inv_sign_img !== '') { ?>
                <img class="pf-inv2-sign-img" src="<?php echo $inv_sign_img; ?>" alt="">
                <?php } ?>
                <?php if ($inv_stamp_img !== '') { ?>
                <img class="pf-inv2-stamp" src="<?php echo $inv_stamp_img; ?>" alt="">
                <?php } ?>
            </div>
            <div class="pf-inv2-for">For <?php echo htmlspecialchars($inv_company_name !== '' ? $inv_company_name : ($company['name'] ?? '')); ?></div>
        </div>
        <?php } else { ?>
        <div class="pf-inv2-cont">Continued on next page…</div>
        <?php } ?>

        <div class="pf-pageno">Page <?php echo $page_index + 1; ?> of <?php echo $inv_page_count; ?></div>
    </div>

    <div class="pf-inv2-foot"><?php if ($inv_footer_img !== '') { ?><img src="<?php echo $inv_footer_img; ?>" alt=""><?php } ?></div>
</div>
    <?php
}
