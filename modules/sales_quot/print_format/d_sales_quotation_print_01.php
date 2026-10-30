<?php
/**
 * modules/sales_quot/print_format/d_sales_quotation_print_01.php — Sales Quotation (A4)
 *
 * Included by shared/print_preview.php, which provides:
 *   $con, $company_code, $branch_code, $doc_code, $doc_type, $doc_no, $company (erp_print_company)
 * Echoes the A4 sheets; sets $print_title, or $print_error when the document can't be printed.
 *
 * Fixed labels (match print sample): QUOTATION, intro/closing sentences, right-box labels
 * (T.R.No / QTN No. / QTN Date / Your Ref / Currency), Terms & Conditions, table columns.
 * Terms body comes from doc_h_terms (same source as Variable Terms).
 * Letter header / footer images from branch_master.header_filepath / footer_filepath;
 * blank bands are kept when no path is set (same pattern as sales invoice).
 */

$print_title = 'Sales Quotation';

$stmt_h = $con->prepare(
    "SELECT h.*,
            ISNULL(am.description, '')     AS customer_name_db,
            ISNULL(am.tax_no, '')          AS customer_tax_no,
            ISNULL(am.po_box, '')          AS customer_po_box,
            ISNULL(am.address, '')         AS customer_address,
            ISNULL(am.address1, '')        AS customer_address1,
            ISNULL(am.street, '')          AS customer_street,
            ISNULL(am.city, '')            AS customer_city,
            ISNULL(am.mobile1, '')         AS customer_mobile1,
            ISNULL(am.contact_name, '')    AS customer_contact_name,
            ISNULL(am.contact_no, '')      AS customer_contact_no
     FROM sales_quotation_h h
     LEFT JOIN ac_master am
            ON am.ac_code = h.ac_code AND am.company_code = h.company_code
     WHERE h.doc_no = ? AND h.company_code = ? AND h.branch_code = ?"
);
$stmt_h->execute([$doc_no, $company_code, $branch_code]);
$sq_h = $stmt_h->fetch(PDO::FETCH_ASSOC);
if (!$sq_h) {
    $print_error = 'Sales Quotation ' . $doc_no . ' was not found.';
    return;
}

$stmt_d = $con->prepare(
    "SELECT serial_no, item_description, note, narration, remark,
            trans_qty, trans_unit, item_unit, trans_rate,
            discount_amount, tax1_percent, tax1_amount, trans_amount
     FROM sales_quotation_d
     WHERE doc_no = ? AND company_code = ? AND branch_code = ?
     ORDER BY serial_no ASC"
);
$stmt_d->execute([$doc_no, $company_code, $branch_code]);

$sq_rows = [];
$sq_tot = ['gross' => 0.0, 'discount' => 0.0, 'vat' => 0.0];
$sq_vat_pcts = [];
while ($d = $stmt_d->fetch(PDO::FETCH_ASSOC)) {
    $qty      = (float) $d['trans_qty'];
    $rate     = (float) $d['trans_rate'];
    $discount = (float) $d['discount_amount'];
    // Amount column / Total = qty × rate; Discount & VAT applied in the summary
    $amount   = round($qty * $rate, 2);
    $vat      = (float) $d['tax1_amount'];

    $desc = trim((string) $d['item_description']);
    $note = trim((string) ($d['note'] ?? ''));
    if ($note === '') {
        $note = trim((string) ($d['narration'] ?? ''));
    }
    if ($note === '') {
        $note = trim((string) ($d['remark'] ?? ''));
    }

    $sq_tot['gross']    += $amount;
    $sq_tot['discount'] += $discount;
    $sq_tot['vat']      += $vat;
    $sq_vat_pcts[erp_print_qty($d['tax1_percent'])] = true;

    $sq_rows[] = [
        'desc'   => $desc,
        'note'   => $note,
        'qty'    => $qty,
        'unit'   => trim((string) ($d['trans_unit'] ?: $d['item_unit'])),
        'rate'   => $rate,
        'amount' => $amount,
    ];
}

$header_discount = (float) ($sq_h['discount_amount'] ?? 0);
if ($header_discount > 0 && abs($sq_tot['discount'] - $header_discount) < 0.005) {
    // detail discounts already match header — keep detail sum
} elseif ($header_discount > 0 && $sq_tot['discount'] <= 0) {
    $sq_tot['discount'] = $header_discount;
}
$header_vat = (float) ($sq_h['tax1_amount'] ?? 0);
if ($header_vat > 0 && $sq_tot['vat'] <= 0) {
    $sq_tot['vat'] = $header_vat;
}

$sq_total      = round($sq_tot['gross'], 2);
$sq_discount   = round($sq_tot['discount'], 2);
$sq_vat        = round($sq_tot['vat'], 2);
$sq_net        = round($sq_total - $sq_discount + $sq_vat, 2);

if (count($sq_vat_pcts) === 1) {
    $sq_vat_label = 'VAT ' . array_key_first($sq_vat_pcts) . '%';
} elseif (!empty($sq_h['tax1_percent']) || $sq_h['tax1_percent'] === '0' || $sq_h['tax1_percent'] === 0.0) {
    $sq_vat_label = 'VAT ' . erp_print_qty($sq_h['tax1_percent']) . '%';
} else {
    $sq_vat_label = 'VAT';
}

// Branch TRN + letter header / footer / stamp images (space reserved even when blank)
$stmt_b = $con->prepare(
    "SELECT tax_no, header_filepath, footer_filepath, stamp_path
     FROM branch_master WHERE company_code = ? AND branch_code = ?"
);
$stmt_b->execute([$company_code, $branch_code]);
$sq_branch = $stmt_b->fetch(PDO::FETCH_ASSOC) ?: [];
$sq_branch_trn = trim((string) ($sq_branch['tax_no'] ?? ''));
$sq_header_img = erp_print_image_data_uri($sq_branch['header_filepath'] ?? '');
$sq_footer_img = erp_print_image_data_uri($sq_branch['footer_filepath'] ?? '');
$sq_stamp_img  = erp_print_image_data_uri($sq_branch['stamp_path'] ?? '');

// Currency
$sq_currency_code = trim((string) ($sq_h['currency_code'] ?? ''));
$stmt_c = $con->prepare("SELECT * FROM currency_master WHERE company_code = ? AND currency_code = ?");
$stmt_c->execute([$company_code, $sq_currency_code]);
$sq_currency = $stmt_c->fetch(PDO::FETCH_ASSOC) ?: [];
$sq_currency_name = trim((string) ($sq_currency['currency_name'] ?? '')) ?: $sq_currency_code;
$sq_fraction_name = '';
foreach (['fraction_name', 'decimal_name', 'sub_unit', 'sub_currency', 'currency_fraction', 'fraction'] as $col) {
    if (trim((string) ($sq_currency[$col] ?? '')) !== '') {
        $sq_fraction_name = trim((string) $sq_currency[$col]);
        break;
    }
}
$sq_words = erp_print_amount_words($sq_net, $sq_currency_code, $sq_fraction_name);
$sq_ccy_hdr = $sq_currency_code !== '' ? $sq_currency_code : 'AED';

$sq_subject = trim((string) ($sq_h['subject'] ?? ''));

// Customer block
$sq_customer = trim((string) $sq_h['customer_name_db']) ?: trim((string) $sq_h['ac_code']);
$sq_addr_parts = [];
$po = trim((string) $sq_h['customer_po_box']);
if ($po !== '') {
    $sq_addr_parts[] = 'P.O.Box : ' . $po;
}
foreach (['customer_address', 'customer_address1', 'customer_street', 'customer_city'] as $ak) {
    $v = trim((string) ($sq_h[$ak] ?? ''));
    if ($v !== '' && !in_array($v, $sq_addr_parts, true)) {
        $sq_addr_parts[] = $v;
    }
}
$sq_cust_addr = implode(' ', $sq_addr_parts);

$sq_attn_name = trim((string) ($sq_h['contact_name'] ?? ''))
    ?: trim((string) ($sq_h['customer_contact_name'] ?? ''));
$sq_attn_phone = trim((string) ($sq_h['contact_no'] ?? ''))
    ?: trim((string) ($sq_h['customer_contact_no'] ?? ''))
    ?: trim((string) ($sq_h['customer_mobile1'] ?? ''))
    ?: trim((string) ($sq_h['tel_off'] ?? ''));
$sq_attn = '';
if ($sq_attn_name !== '' || $sq_attn_phone !== '') {
    $sq_attn = 'Attn: ' . trim($sq_attn_name . ($sq_attn_name !== '' && $sq_attn_phone !== '' ? ' / ' : '') . $sq_attn_phone);
}
$sq_cust_trn = trim((string) ($sq_h['tax_no'] ?? '')) ?: trim((string) ($sq_h['customer_tax_no'] ?? ''));
$sq_your_ref = trim((string) ($sq_h['ref_no'] ?? ''));

// Variable Terms → doc_h_terms (same table as shared/variable_terms.php)
$sq_terms = [];
$stmt_t = $con->prepare(
    "SELECT t.serial_no, t.line_no, t.process_code, t.remark, t.line_hdr,
            t.line_bold, t.line_italics, t.line_underline,
            ISNULL(pm.process_name, '') AS process_name
     FROM doc_h_terms t
     LEFT JOIN process_master pm ON pm.process_code = t.process_code
     WHERE t.company_code = ? AND t.branch_code = ? AND t.doc_no = ? AND t.doc_type = ?
     ORDER BY t.serial_no ASC"
);
$stmt_t->execute([$company_code, $branch_code, $doc_no, $doc_type]);
while ($t = $stmt_t->fetch(PDO::FETCH_ASSOC)) {
    $sq_terms[] = [
        'process_code'   => trim((string) ($t['process_code'] ?? '')),
        'process_name'   => trim((string) ($t['process_name'] ?? '')),
        'remark'         => trim((string) ($t['remark'] ?? '')),
        'line_hdr'       => strtoupper(trim((string) ($t['line_hdr'] ?? 'N'))) === 'Y',
        'line_bold'      => strtoupper(trim((string) ($t['line_bold'] ?? 'N'))) === 'Y',
        'line_italics'   => strtoupper(trim((string) ($t['line_italics'] ?? 'N'))) === 'Y',
        'line_underline' => strtoupper(trim((string) ($t['line_underline'] ?? 'N'))) === 'Y',
    ];
}

// Group terms by process for numbered headings (e.g. "1 Payment Terms")
$sq_term_groups = [];
$sq_group_i = 0;
$sq_last_pc = null;
foreach ($sq_terms as $t) {
    $pc = $t['process_code'];
    $is_new = ($sq_last_pc === null || $pc !== $sq_last_pc || $t['line_hdr']);
    if ($is_new) {
        $sq_group_i++;
        $title = $t['process_name'] !== '' ? $t['process_name'] : ($pc !== '' ? $pc : 'Terms');
        if ($t['line_hdr'] && $t['remark'] !== '') {
            $title = $t['remark'];
        }
        $sq_term_groups[] = [
            'no'    => $sq_group_i,
            'title' => $title,
            'lines' => [],
        ];
        $sq_last_pc = $pc;
        if ($t['line_hdr']) {
            continue;
        }
    }
    if ($t['remark'] === '') {
        continue;
    }
    $gi = count($sq_term_groups) - 1;
    if ($gi < 0) {
        $sq_group_i++;
        $sq_term_groups[] = ['no' => $sq_group_i, 'title' => 'Terms', 'lines' => []];
        $gi = 0;
    }
    $sq_term_groups[$gi]['lines'][] = $t;
}

// Signatory from salesman table (header salesman_code) + signature image
$sq_sign_name  = '';
$sq_sign_desig = '';
$sq_sign_phone = '';
$sq_sign_img   = '';
$sq_salesman_code = trim((string) ($sq_h['salesman_code'] ?? ''));
if ($sq_salesman_code !== '') {
    $stmt_sm = $con->prepare(
        "SELECT salesman_name, designation, mobile_no, signature_file
         FROM salesman
         WHERE company_code = ? AND salesman_code = ?"
    );
    $stmt_sm->execute([$company_code, $sq_salesman_code]);
    $sm = $stmt_sm->fetch(PDO::FETCH_ASSOC) ?: [];
    $sq_sign_name  = trim((string) ($sm['salesman_name'] ?? ''));
    $sq_sign_desig = trim((string) ($sm['designation'] ?? ''));
    $sq_sign_phone = trim((string) ($sm['mobile_no'] ?? ''));
    $sq_sign_img   = erp_print_image_data_uri($sm['signature_file'] ?? '');
}

$sq_company_name = trim((string) ($company['name'] ?? ''));

// Body below letterhead bands has fixed room; header boxes only on page 1.
// Reserve last-page space for totals + variable terms + closing/sign.
$sq_row_mm   = 5.5;
$sq_wrap_mm  = 4;
$sq_wrap_chr = 55;
$sq_area_mm  = 145; // continuation pages (title only, no customer/meta boxes)
$sq_first_area_mm = 105; // page 1 also has boxes (~28mm) + intro

$sq_terms_mm = 0.0;
if (!empty($sq_term_groups)) {
    $sq_terms_mm = 8.0;
    foreach ($sq_term_groups as $g) {
        $sq_terms_mm += 5.0;
        foreach ($g['lines'] as $line) {
            $n = count(erp_print_wrap_lines($line['remark'], 70));
            $sq_terms_mm += max(4.0, $n * 3.5);
        }
    }
}
// sum(~28) + terms + close(~12) + sign/stamp(~34) + pad
$sq_footer_reserve = min(130.0, max(78.0, 28.0 + $sq_terms_mm + 12.0 + 34.0 + 6.0));
// Continuation last page: no boxes. Single-page doc: boxes + footer on page 1.
$sq_last_area_mm = max(28.0, min($sq_area_mm, 220.0 - $sq_footer_reserve));
$sq_first_last_area_mm = max(20.0, min($sq_first_area_mm, 185.0 - $sq_footer_reserve));

list($sq_pages, $sq_page_mm) = erp_print_paginate_detail_rows($sq_rows, [
    'area_mm'            => $sq_area_mm,
    'first_area_mm'      => $sq_first_area_mm,
    'last_area_mm'       => $sq_last_area_mm,
    'first_last_area_mm' => $sq_first_last_area_mm,
    'row_mm'             => $sq_row_mm,
    'wrap_mm'            => $sq_wrap_mm,
    'wrap_chr'           => $sq_wrap_chr,
]);
$sq_page_count = count($sq_pages);
$sq_line_no = 0;

foreach ($sq_pages as $page_index => $page_rows) {
    $is_last = ($page_index === $sq_page_count - 1);
    ?>
<div class="erp-print-sheet pf-sq-sheet<?php echo $page_index > 0 ? ' pf-break' : ''; ?>">
    <div class="pf-sq-head"><?php if ($sq_header_img !== '') { ?><img src="<?php echo $sq_header_img; ?>" alt=""><?php } ?></div>

    <div class="pf-sq-body">
        <?php if ($page_index === 0) { ?>
        <div class="pf-sq-title">QUOTATION</div>

        <table class="pf-sq-top">
            <tr>
                <td class="pf-sq-box pf-sq-cust">
                    <div class="pf-sq-cust-name"><?php echo htmlspecialchars($sq_customer); ?></div>
                    <?php if ($sq_cust_addr !== '') { ?>
                    <div class="pf-sq-cust-line"><?php echo htmlspecialchars($sq_cust_addr); ?></div>
                    <?php } ?>
                    <?php if ($sq_attn !== '') { ?>
                    <div class="pf-sq-cust-line"><?php echo htmlspecialchars($sq_attn); ?></div>
                    <?php } ?>
                    <div class="pf-sq-cust-line">T.R.No : <?php echo htmlspecialchars($sq_cust_trn); ?></div>
                </td>
                <td class="pf-sq-gap"></td>
                <td class="pf-sq-box pf-sq-meta">
                    <table class="pf-sq-kv">
                        <tr><td class="pf-k">T.R.No</td><td class="pf-sep">:</td><td class="pf-v"><?php echo htmlspecialchars($sq_branch_trn); ?></td></tr>
                        <tr><td class="pf-k">QTN No.</td><td class="pf-sep">:</td><td class="pf-v"><?php echo htmlspecialchars(trim((string) $sq_h['doc_no'])); ?></td></tr>
                        <tr><td class="pf-k">QTN Date</td><td class="pf-sep">:</td><td class="pf-v"><?php echo htmlspecialchars(erp_print_date($sq_h['doc_dt'] ?? '')); ?></td></tr>
                        <tr><td class="pf-k">Your Ref</td><td class="pf-sep">:</td><td class="pf-v"><?php echo htmlspecialchars($sq_your_ref); ?></td></tr>
                        <tr><td class="pf-k">Currency</td><td class="pf-sep">:</td><td class="pf-v"><?php echo htmlspecialchars($sq_currency_name); ?></td></tr>
                    </table>
                </td>
            </tr>
        </table>
        <?php } ?>

        <div class="pf-sq-detail">
            <?php if ($page_index === 0) { ?>
            <?php if ($sq_subject !== '') { ?>
            <div class="pf-sq-sub">Sub : <?php echo htmlspecialchars($sq_subject); ?></div>
            <?php } ?>
            <div class="pf-sq-intro">Thank you very much for your valid enquiry. We are offering our best quote as below.</div>
            <?php } ?>

            <?php if (!empty($page_rows)) { ?>
            <table class="pf-items pf-sq-items">
                <colgroup>
                    <col class="pf-sq-c-no">
                    <col class="pf-sq-c-desc">
                    <col class="pf-sq-c-qty">
                    <col class="pf-sq-c-unit">
                    <col class="pf-sq-c-price">
                    <col class="pf-sq-c-amt">
                </colgroup>
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Description</th>
                        <th>Qty</th>
                        <th>Unit</th>
                        <th>Price (<?php echo htmlspecialchars($sq_ccy_hdr); ?>)</th>
                        <th>Amount (<?php echo htmlspecialchars($sq_ccy_hdr); ?>)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($page_rows as $r) {
                        $show = !empty($r['show_values']);
                        if ($show) {
                            $sq_line_no++;
                        }
                        $desc_html = $r['desc'] !== '' ? nl2br(htmlspecialchars($r['desc'])) : '';
                        if ($r['note'] !== '') {
                            $desc_html .= '<div class="pf-sq-note">' . nl2br(htmlspecialchars($r['note'])) . '</div>';
                        }
                        if (!empty($r['continued']) && $desc_html === '') {
                            $desc_html = '<span class="pf-sq-cont-label">(continued)</span>';
                        }
                        ?>
                    <tr>
                        <td class="center"><?php echo $show ? $sq_line_no : ''; ?></td>
                        <td class="wrap"><?php echo $desc_html; ?></td>
                        <td class="center"><?php echo $show ? erp_print_qty($r['qty']) : ''; ?></td>
                        <td class="center"><?php echo $show ? htmlspecialchars($r['unit']) : ''; ?></td>
                        <td class="num"><?php echo $show ? erp_print_amount($r['rate']) : ''; ?></td>
                        <td class="num"><?php echo $show ? erp_print_amount($r['amount']) : ''; ?></td>
                    </tr>
                    <?php } ?>
                </tbody>
            </table>
            <?php } ?>

            <?php if ($is_last) { ?>
            <!-- Words + totals: Price/Amount columns line up with the item table above -->
            <table class="pf-sq-sum">
                <colgroup>
                    <col class="pf-sq-c-words">
                    <col class="pf-sq-c-price">
                    <col class="pf-sq-c-amt">
                </colgroup>
                <tr>
                    <td class="pf-sq-words" rowspan="4"><?php echo htmlspecialchars($sq_words); ?></td>
                    <td class="pf-sq-sum-k">Total :</td>
                    <td class="pf-sq-sum-v"><?php echo erp_print_amount($sq_total); ?></td>
                </tr>
                <tr>
                    <td class="pf-sq-sum-k">Discount :</td>
                    <td class="pf-sq-sum-v"><?php echo erp_print_amount($sq_discount); ?></td>
                </tr>
                <tr>
                    <td class="pf-sq-sum-k"><?php echo htmlspecialchars($sq_vat_label); ?> :</td>
                    <td class="pf-sq-sum-v"><?php echo erp_print_amount($sq_vat); ?></td>
                </tr>
                <tr class="pf-sq-net">
                    <td class="pf-sq-sum-k">Net Amount :</td>
                    <td class="pf-sq-sum-v"><?php echo erp_print_amount($sq_net); ?></td>
                </tr>
            </table>

            <?php if (!empty($sq_term_groups)) { ?>
            <div class="pf-sq-terms">
                <div class="pf-sq-terms-title">Terms &amp; Conditions</div>
                <?php foreach ($sq_term_groups as $g) { ?>
                <div class="pf-sq-term-group">
                    <div class="pf-sq-term-head"><?php echo (int) $g['no']; ?> <?php echo htmlspecialchars($g['title']); ?></div>
                    <?php if (!empty($g['lines'])) { ?>
                    <ul class="pf-sq-term-list">
                        <?php foreach ($g['lines'] as $line) {
                            $cls = [];
                            if ($line['line_bold']) {
                                $cls[] = 'pf-sq-b';
                            }
                            if ($line['line_italics']) {
                                $cls[] = 'pf-sq-i';
                            }
                            if ($line['line_underline']) {
                                $cls[] = 'pf-sq-u';
                            }
                            $class_attr = $cls ? ' class="' . implode(' ', $cls) . '"' : '';
                            ?>
                        <li<?php echo $class_attr; ?>><?php echo nl2br(htmlspecialchars($line['remark'])); ?></li>
                        <?php } ?>
                    </ul>
                    <?php } ?>
                </div>
                <?php } ?>
            </div>
            <?php } ?>

            <div class="pf-sq-close">We sincerely trust that this quotation is in accordance with your requirement and look forward to your kind order. Thanking You,</div>

            <div class="pf-sq-sign">
                <div class="pf-sq-for">For <?php echo htmlspecialchars($sq_company_name); ?></div>
                <table class="pf-sq-sign-row">
                    <tr>
                        <td class="pf-sq-sign-img-cell">
                            <?php if ($sq_sign_img !== '') { ?>
                            <img class="pf-sq-sign-img" src="<?php echo $sq_sign_img; ?>" alt="">
                            <?php } else { ?>
                            <div class="pf-sq-sign-space"></div>
                            <?php } ?>
                        </td>
                        <td class="pf-sq-stamp-cell">
                            <?php if ($sq_stamp_img !== '') { ?>
                            <img class="pf-sq-stamp" src="<?php echo $sq_stamp_img; ?>" alt="">
                            <?php } ?>
                        </td>
                    </tr>
                </table>
                <?php if ($sq_sign_name !== '') { ?><div class="pf-sq-sign-name"><?php echo htmlspecialchars($sq_sign_name); ?></div><?php } ?>
                <?php if ($sq_sign_desig !== '') { ?><div class="pf-sq-sign-meta"><?php echo htmlspecialchars($sq_sign_desig); ?></div><?php } ?>
                <?php if ($sq_sign_phone !== '') { ?><div class="pf-sq-sign-meta"><?php echo htmlspecialchars($sq_sign_phone); ?></div><?php } ?>
            </div>
            <?php } else { ?>
            <div class="pf-sq-cont">Continued on next page…</div>
            <?php } ?>
        </div>

        <div class="pf-pageno">Page <?php echo $page_index + 1; ?> of <?php echo $sq_page_count; ?></div>
    </div>

    <div class="pf-sq-foot"><?php if ($sq_footer_img !== '') { ?><img src="<?php echo $sq_footer_img; ?>" alt=""><?php } ?></div>
</div>
    <?php
}
