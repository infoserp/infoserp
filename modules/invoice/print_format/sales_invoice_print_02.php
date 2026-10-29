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
$inv_tot = ['amount' => 0.0, 'vat' => 0.0];
$inv_vat_pcts = [];
while ($d = $stmt_d->fetch(PDO::FETCH_ASSOC)) {
    $qty      = (float) $d['trans_qty'];
    $rate     = (float) $d['trans_rate'];
    $discount = (float) $d['discount_amount'];
    $amount   = round($qty * $rate - $discount, 2);
    $vat      = (float) $d['tax1_amount'];

    $desc = trim((string) $d['item_description']);
    $note = trim((string) ($d['narration'] ?? ''));
    if ($note === '') {
        $note = trim((string) ($d['remark'] ?? ''));
    }

    $inv_tot['amount'] += $amount;
    $inv_tot['vat']    += $vat;
    $inv_vat_pcts[erp_print_qty($d['tax1_percent'])] = true;

    $inv_rows[] = [
        'desc'   => $desc,
        'note'   => $note,
        'qty'    => $qty,
        'rate'   => $rate,
        'amount' => $amount,
    ];
}

$header_vat = (float) ($inv_h['tax1_amount'] ?? 0);
if ($header_vat > 0 && $inv_tot['vat'] <= 0) {
    $inv_tot['vat'] = $header_vat;
}

$inv_sub_total = round($inv_tot['amount'], 2);
$inv_vat       = round($inv_tot['vat'], 2);
$inv_net_total = round($inv_sub_total + $inv_vat, 2);

if (count($inv_vat_pcts) === 1) {
    $inv_vat_label = 'VAT ' . array_key_first($inv_vat_pcts) . '%';
} elseif (isset($inv_h['tax1_percent']) && $inv_h['tax1_percent'] !== '' && $inv_h['tax1_percent'] !== null) {
    $inv_vat_label = 'VAT ' . erp_print_qty($inv_h['tax1_percent']) . '%';
} else {
    $inv_vat_label = 'VAT';
}

// Branch TRN + letter header / footer images
$stmt_b = $con->prepare(
    "SELECT tax_no, header_filepath, footer_filepath
     FROM branch_master WHERE company_code = ? AND branch_code = ?"
);
$stmt_b->execute([$company_code, $branch_code]);
$inv_branch = $stmt_b->fetch(PDO::FETCH_ASSOC) ?: [];
$inv_trn        = trim((string) ($inv_branch['tax_no'] ?? ''));
$inv_header_img = erp_print_image_data_uri($inv_branch['header_filepath'] ?? '');
$inv_footer_img = erp_print_image_data_uri($inv_branch['footer_filepath'] ?? '');

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

// Pagination — tall item area so column lines fill the page (sample style)
$inv_area_mm  = 120;
$inv_row_mm   = 5.5;
$inv_wrap_mm  = 4;
$inv_wrap_chr = 58;

$inv_pages = [[]];
$inv_page_mm = [0];
foreach ($inv_rows as $r) {
    $text = $r['desc'];
    if ($r['note'] !== '') {
        $text .= "\n" . $r['note'];
    }
    $lines = 0;
    foreach (preg_split("/\r\n|\r|\n/", $text) as $para) {
        $lines += substr_count(wordwrap($para !== '' ? $para : ' ', $inv_wrap_chr, "\n", true), "\n") + 1;
    }
    if ($lines < 1) {
        $lines = 1;
    }
    $r['mm'] = $inv_row_mm + ($lines - 1) * $inv_wrap_mm;
    $p = count($inv_pages) - 1;
    if ($inv_pages[$p] && $inv_page_mm[$p] + $r['mm'] > $inv_area_mm) {
        $inv_pages[] = [];
        $inv_page_mm[] = 0;
        $p++;
    }
    $inv_pages[$p][] = $r;
    $inv_page_mm[$p] += $r['mm'];
}
$inv_page_count = count($inv_pages);
$inv_line_no = 0;

foreach ($inv_pages as $page_index => $page_rows) {
    $is_last = ($page_index === $inv_page_count - 1);
    $fill_mm = max(0, $inv_area_mm - $inv_page_mm[$page_index]);
    ?>
<div class="erp-print-sheet pf-inv2-sheet<?php echo $page_index > 0 ? ' pf-break' : ''; ?>">
    <div class="pf-inv2-head"><?php if ($inv_header_img !== '') { ?><img src="<?php echo $inv_header_img; ?>" alt=""><?php } ?></div>

    <div class="pf-inv2-body">
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

        <table class="pf-items pf-inv2-items">
            <colgroup>
                <col class="pf-inv2-c-no">
                <col class="pf-inv2-c-desc">
                <col class="pf-inv2-c-qty">
                <col class="pf-inv2-c-price">
                <col class="pf-inv2-c-amt">
            </colgroup>
            <thead>
                <tr>
                    <th>No</th>
                    <th>Description</th>
                    <th>Qty</th>
                    <th>Price</th>
                    <th>Amount(<?php echo htmlspecialchars($inv_ccy_hdr); ?>)</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($page_rows as $r) {
                    $inv_line_no++;
                    $desc_html = nl2br(htmlspecialchars($r['desc']));
                    if ($r['note'] !== '') {
                        $desc_html .= '<div class="pf-inv2-note">' . nl2br(htmlspecialchars($r['note'])) . '</div>';
                    }
                    ?>
                <tr>
                    <td class="center"><?php echo $inv_line_no; ?></td>
                    <td class="wrap"><?php echo $desc_html; ?></td>
                    <td class="num"><?php echo erp_print_qty($r['qty']); ?></td>
                    <td class="num"><?php echo erp_print_amount($r['rate']); ?></td>
                    <td class="num"><?php echo erp_print_amount($r['amount']); ?></td>
                </tr>
                <?php } ?>
                <?php if ($fill_mm > 0) { ?>
                <tr class="pf-fill">
                    <td style="height: <?php echo $fill_mm; ?>mm;"></td><td></td><td></td><td></td><td></td>
                </tr>
                <?php } ?>
            </tbody>
        </table>

        <?php if ($is_last) { ?>
        <!-- Totals width = Price + Amount columns so the grid lines up under the item table -->
        <table class="pf-inv2-bottom">
            <colgroup>
                <col class="pf-inv2-c-bank">
                <col class="pf-inv2-c-price">
                <col class="pf-inv2-c-amt">
            </colgroup>
            <tr>
                <td class="pf-inv2-bank">
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
                <td class="pf-inv2-sum-k">
                    <div class="pf-inv2-sum-line">Sub Total</div>
                    <div class="pf-inv2-sum-line"><?php echo htmlspecialchars($inv_vat_label); ?></div>
                    <div class="pf-inv2-sum-line pf-inv2-net">Net Total</div>
                </td>
                <td class="pf-inv2-sum-v">
                    <div class="pf-inv2-sum-line"><?php echo erp_print_amount($inv_sub_total); ?></div>
                    <div class="pf-inv2-sum-line"><?php echo erp_print_amount($inv_vat); ?></div>
                    <div class="pf-inv2-sum-line pf-inv2-net"><?php echo erp_print_amount($inv_net_total); ?></div>
                </td>
            </tr>
        </table>

        <div class="pf-inv2-words"><?php echo htmlspecialchars($inv_words); ?></div>

        <div class="pf-inv2-sign">
            <div class="pf-inv2-for">For <?php echo htmlspecialchars($inv_company_name); ?></div>
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
