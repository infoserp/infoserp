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

// Branch TRN + letter header / footer images (space reserved even when blank)
$stmt_b = $con->prepare(
    "SELECT tax_no, header_filepath, footer_filepath
     FROM branch_master WHERE company_code = ? AND branch_code = ?"
);
$stmt_b->execute([$company_code, $branch_code]);
$sq_branch = $stmt_b->fetch(PDO::FETCH_ASSOC) ?: [];
$sq_branch_trn = trim((string) ($sq_branch['tax_no'] ?? ''));
$sq_header_img = erp_print_image_data_uri($sq_branch['header_filepath'] ?? '');
$sq_footer_img = erp_print_image_data_uri($sq_branch['footer_filepath'] ?? '');

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

// Signatory from salesman table (header salesman_code)
$sq_sign_name  = '';
$sq_sign_desig = '';
$sq_sign_phone = '';
$sq_salesman_code = trim((string) ($sq_h['salesman_code'] ?? ''));
if ($sq_salesman_code !== '') {
    $stmt_sm = $con->prepare(
        "SELECT salesman_name, designation, mobile_no
         FROM salesman
         WHERE company_code = ? AND salesman_code = ?"
    );
    $stmt_sm->execute([$company_code, $sq_salesman_code]);
    $sm = $stmt_sm->fetch(PDO::FETCH_ASSOC) ?: [];
    $sq_sign_name  = trim((string) ($sm['salesman_name'] ?? ''));
    $sq_sign_desig = trim((string) ($sm['designation'] ?? ''));
    $sq_sign_phone = trim((string) ($sm['mobile_no'] ?? ''));
}

$sq_company_name = trim((string) ($company['name'] ?? ''));

// Pagination: item area height (body sits between fixed header/footer bands)
$sq_area_mm   = 95;
$sq_row_mm    = 5.5;
$sq_wrap_mm   = 4;
$sq_wrap_chr  = 55;

$sq_pages = [[]];
$sq_page_mm = [0];
foreach ($sq_rows as $r) {
    $text = $r['desc'];
    if ($r['note'] !== '') {
        $text .= "\n" . $r['note'];
    }
    $lines = 0;
    foreach (preg_split("/\r\n|\r|\n/", $text) as $para) {
        $lines += substr_count(wordwrap($para !== '' ? $para : ' ', $sq_wrap_chr, "\n", true), "\n") + 1;
    }
    if ($lines < 1) {
        $lines = 1;
    }
    $r['mm'] = $sq_row_mm + ($lines - 1) * $sq_wrap_mm;
    $p = count($sq_pages) - 1;
    if ($sq_pages[$p] && $sq_page_mm[$p] + $r['mm'] > $sq_area_mm) {
        $sq_pages[] = [];
        $sq_page_mm[] = 0;
        $p++;
    }
    $sq_pages[$p][] = $r;
    $sq_page_mm[$p] += $r['mm'];
}
$sq_page_count = count($sq_pages);
$sq_line_no = 0;

foreach ($sq_pages as $page_index => $page_rows) {
    $is_last = ($page_index === $sq_page_count - 1);
    $fill_mm = max(0, $sq_area_mm - $sq_page_mm[$page_index]);
    ?>
<div class="erp-print-sheet pf-sq-sheet<?php echo $page_index > 0 ? ' pf-break' : ''; ?>">
    <div class="pf-sq-head"><?php if ($sq_header_img !== '') { ?><img src="<?php echo $sq_header_img; ?>" alt=""><?php } ?></div>

    <div class="pf-sq-body">
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

        <div class="pf-sq-detail">
            <div class="pf-sq-intro">Thank you very much for your valid enquiry. We are offering our best quote as below.</div>

            <table class="pf-items pf-sq-items">
                <thead>
                    <tr>
                        <th style="width: 8mm;">No</th>
                        <th>Description</th>
                        <th style="width: 14mm;">Qty</th>
                        <th style="width: 14mm;">Unit</th>
                        <th style="width: 24mm;">Price (<?php echo htmlspecialchars($sq_ccy_hdr); ?>)</th>
                        <th style="width: 26mm;">Amount (<?php echo htmlspecialchars($sq_ccy_hdr); ?>)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($page_rows as $r) {
                        $sq_line_no++;
                        $desc_html = nl2br(htmlspecialchars($r['desc']));
                        if ($r['note'] !== '') {
                            $desc_html .= '<div class="pf-sq-note">' . nl2br(htmlspecialchars($r['note'])) . '</div>';
                        }
                        ?>
                    <tr>
                        <td class="center"><?php echo $sq_line_no; ?></td>
                        <td class="wrap"><?php echo $desc_html; ?></td>
                        <td class="center"><?php echo erp_print_qty($r['qty']); ?></td>
                        <td class="center"><?php echo htmlspecialchars($r['unit']); ?></td>
                        <td class="num"><?php echo erp_print_amount($r['rate']); ?></td>
                        <td class="num"><?php echo erp_print_amount($r['amount']); ?></td>
                    </tr>
                    <?php } ?>
                    <?php if ($fill_mm > 0 && !$is_last) { ?>
                    <tr class="pf-fill">
                        <td style="height: <?php echo $fill_mm; ?>mm;"></td><td></td><td></td><td></td><td></td><td></td>
                    </tr>
                    <?php } ?>
                </tbody>
            </table>

            <?php if ($is_last) { ?>
            <table class="pf-sq-sum">
                <tr>
                    <td class="pf-sq-words"><?php echo htmlspecialchars($sq_words); ?></td>
                    <td class="pf-sq-sum-panel">
                        <table class="pf-sq-sum-grid">
                            <tr>
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
                    </td>
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
                <div class="pf-sq-sign-space"></div>
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
