<?php
/**
 * modules/receipt/print_format/d_chq_receipt_print_01.php — Receipt Voucher (A4)
 *
 * Included by shared/print_preview.php, which provides:
 *   $con, $company_code, $branch_code, $doc_code, $doc_type, $doc_no, $company (erp_print_company)
 *
 * Letter header / footer from branch_master (same pattern as other print formats).
 * Stamp from branch_master.stamp_path; signature from branch_user.signature_file
 * for the logged-in user_id — both shown above the "Approved by" label.
 */

$print_title = 'Receipt Voucher';

$stmt_h = $con->prepare(
    "SELECT rh.*,
            ISNULL(am.description, '') AS debit_ac_name
     FROM chq_receipt_h rh
     LEFT JOIN ac_master am
            ON am.ac_code = rh.ac_code AND am.company_code = rh.company_code
     WHERE rh.doc_no = ? AND rh.company_code = ? AND rh.branch_code = ?"
);
$stmt_h->execute([$doc_no, $company_code, $branch_code]);
$rv_h = $stmt_h->fetch(PDO::FETCH_ASSOC);
if (!$rv_h) {
    $print_error = 'Receipt Voucher ' . $doc_no . ' was not found.';
    return;
}

$stmt_d = $con->prepare(
    "SELECT d.serial_no, d.ac_code, ISNULL(am.description, '') AS ac_name,
            d.dr_amount, d.cr_amount, d.invoice_no, d.invoice_date, d.job_no, d.narration
     FROM chq_receipt_d d
     LEFT JOIN ac_master am ON am.company_code = d.company_code AND am.ac_code = d.ac_code
     WHERE d.company_code = ? AND d.branch_code = ? AND d.doc_no = ?
     ORDER BY d.serial_no ASC"
);
$stmt_d->execute([$company_code, $branch_code, $doc_no]);

$rv_rows = [];
while ($d = $stmt_d->fetch(PDO::FETCH_ASSOC)) {
    $desc = trim((string) ($d['ac_name'] ?? ''));
    if ($desc === '') {
        $desc = trim((string) ($d['ac_code'] ?? ''));
    }
    $rv_rows[] = [
        'desc'     => $desc,
        'ac_code'  => trim((string) ($d['ac_code'] ?? '')),
        'debit'    => (float) ($d['dr_amount'] ?? 0),
        'credit'   => (float) ($d['cr_amount'] ?? 0),
        'inv_no'   => trim((string) ($d['invoice_no'] ?? '')),
        'inv_date' => $d['invoice_date'] ?? '',
        'job_no'   => trim((string) ($d['job_no'] ?? '')),
    ];
}

// Header Debit A/c as first table row — amount from chq_receipt_h.amount
$rv_hdr_ac   = trim((string) ($rv_h['ac_code'] ?? ''));
$rv_hdr_name = trim((string) ($rv_h['debit_ac_name'] ?? ''));
if ($rv_hdr_name === '') {
    $rv_hdr_name = $rv_hdr_ac;
}
$rv_hdr_amt = (float) ($rv_h['amount'] ?? 0);
$rv_has_hdr_debit = false;
foreach ($rv_rows as $r) {
    if ($rv_hdr_ac !== '' && $r['ac_code'] === $rv_hdr_ac && $r['debit'] > 0) {
        $rv_has_hdr_debit = true;
        break;
    }
}
if ($rv_hdr_ac !== '' && $rv_hdr_amt != 0.0 && !$rv_has_hdr_debit) {
    array_unshift($rv_rows, [
        'desc'     => $rv_hdr_name,
        'ac_code'  => $rv_hdr_ac,
        'debit'    => $rv_hdr_amt,
        'credit'   => 0.0,
        'inv_no'   => '',
        'inv_date' => '',
        'job_no'   => '',
    ]);
}

// Letterhead + stamp (branch_master) — same setup as other formats
$stmt_b = $con->prepare(
    "SELECT header_filepath, footer_filepath, stamp_path
     FROM branch_master WHERE company_code = ? AND branch_code = ?"
);
$stmt_b->execute([$company_code, $branch_code]);
$rv_branch = $stmt_b->fetch(PDO::FETCH_ASSOC) ?: [];
$rv_header_img = erp_print_image_data_uri($rv_branch['header_filepath'] ?? '');
$rv_footer_img = erp_print_image_data_uri($rv_branch['footer_filepath'] ?? '');
$rv_stamp_img  = erp_print_image_data_uri($rv_branch['stamp_path'] ?? '');

// Signature of the logged-in user (branch_user.user_id → signature_file)
$rv_sign_img = '';
$rv_login_user = trim((string) ($_SESSION['username'] ?? ''));
if ($rv_login_user !== '') {
    try {
        $stmt_bu = $con->prepare(
            "SELECT TOP 1 signature_file
             FROM branch_user
             WHERE user_id = ? AND branch_code = ?"
        );
        $stmt_bu->execute([$rv_login_user, $branch_code]);
        $bu = $stmt_bu->fetch(PDO::FETCH_ASSOC) ?: [];
        $rv_sign_img = erp_print_image_data_uri($bu['signature_file'] ?? '');
    } catch (Exception $e) {
        error_log('RV print signature fetch: ' . $e->getMessage());
    }
}

$rv_currency = trim((string) ($rv_h['currency_code'] ?? '')) ?: 'AED';
$rv_amount   = (float) ($rv_h['amount'] ?? 0);

$rv_fraction = '';
try {
    $stmt_c = $con->prepare("SELECT * FROM currency_master WHERE company_code = ? AND currency_code = ?");
    $stmt_c->execute([$company_code, $rv_currency]);
    $rv_cur = $stmt_c->fetch(PDO::FETCH_ASSOC) ?: [];
    foreach (['fraction_name', 'decimal_name', 'sub_unit', 'sub_currency', 'currency_fraction', 'fraction'] as $col) {
        if (trim((string) ($rv_cur[$col] ?? '')) !== '') {
            $rv_fraction = trim((string) $rv_cur[$col]);
            break;
        }
    }
} catch (Exception $e) {
    error_log('RV print currency: ' . $e->getMessage());
}
$rv_words = erp_print_amount_words($rv_amount, $rv_currency, $rv_fraction);

$rv_from = trim((string) ($rv_h['ref_name'] ?? ''));
if ($rv_from === '') {
    foreach ($rv_rows as $r) {
        if ($r['credit'] > 0 && $r['desc'] !== '') {
            $rv_from = $r['desc'];
            break;
        }
    }
}
$rv_being   = trim((string) ($rv_h['narration'] ?? ''));
$rv_chq_no  = trim((string) ($rv_h['chq_no'] ?? ''));
$rv_chq_bank = trim((string) ($rv_h['chq_bank'] ?? ''));

$rv_fmt_doc_date = function ($value) {
    if (empty($value)) {
        return '';
    }
    try {
        return strtoupper((new DateTime($value))->format('d-M-Y'));
    } catch (Exception $e) {
        return trim((string) $value);
    }
};

// Pad blank rows so the grid matches the sample look; paginate if many lines
$rv_min_rows = 6;
$rv_per_page = 12;
$rv_pages = erp_print_pages($rv_rows, $rv_per_page);
$rv_page_count = count($rv_pages);

foreach ($rv_pages as $page_index => $page_rows) {
    $is_last = ($page_index === $rv_page_count - 1);
    $pad = $is_last ? max(0, $rv_min_rows - count($page_rows)) : 0;
    ?>
<div class="erp-print-sheet pf-rv-sheet<?php echo $page_index > 0 ? ' pf-break' : ''; ?>">
    <div class="pf-rv-head"><?php if ($rv_header_img !== '') { ?><img src="<?php echo $rv_header_img; ?>" alt=""><?php } ?></div>

    <div class="pf-rv-body">
        <?php if ($page_index === 0) { ?>
        <div class="pf-rv-frame">
            <table class="pf-rv-top">
                <tr>
                    <td class="pf-rv-top-l">
                        <div class="pf-rv-amt-box">
                            <div class="pf-rv-amt-lbl">Amount <?php echo htmlspecialchars($rv_currency); ?></div>
                            <div class="pf-rv-amt-val"><?php echo htmlspecialchars(erp_print_amount($rv_amount)); ?></div>
                        </div>
                    </td>
                    <td class="pf-rv-top-c">
                        <div class="pf-rv-title">RECEIPT VOUCHER</div>
                    </td>
                    <td class="pf-rv-top-r">
                        <table class="pf-rv-meta">
                            <tr>
                                <td class="pf-k">No.</td><td class="pf-sep">:</td>
                                <td class="pf-v"><?php echo htmlspecialchars(trim((string) $rv_h['doc_no'])); ?></td>
                            </tr>
                            <tr>
                                <td class="pf-k">Date</td><td class="pf-sep">:</td>
                                <td class="pf-v"><?php echo htmlspecialchars($rv_fmt_doc_date($rv_h['doc_dt'] ?? '')); ?></td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>

            <table class="pf-rv-lines">
                <tr>
                    <td class="pf-rv-lbl">Received with thanks from</td>
                    <td class="pf-rv-colon">:</td>
                    <td class="pf-rv-uline"><?php echo htmlspecialchars($rv_from); ?></td>
                </tr>
                <tr>
                    <td class="pf-rv-lbl">The sum of</td>
                    <td class="pf-rv-colon">:</td>
                    <td class="pf-rv-uline"><?php echo htmlspecialchars($rv_words); ?></td>
                </tr>
            </table>

            <table class="pf-rv-chq">
                <tr>
                    <td class="pf-rv-chq-cell">
                        <span class="pf-rv-lbl">Cheque No</span>
                        <span class="pf-rv-colon">:</span>
                        <span class="pf-rv-chq-val"><?php echo htmlspecialchars($rv_chq_no); ?></span>
                    </td>
                    <td class="pf-rv-chq-cell">
                        <span class="pf-rv-lbl">Cheque Dt.</span>
                        <span class="pf-rv-colon">:</span>
                        <span class="pf-rv-chq-val"><?php echo htmlspecialchars($rv_fmt_doc_date($rv_h['chq_date'] ?? '')); ?></span>
                    </td>
                    <td class="pf-rv-chq-cell">
                        <span class="pf-rv-lbl">Bank</span>
                        <span class="pf-rv-colon">:</span>
                        <span class="pf-rv-chq-val"><?php echo htmlspecialchars($rv_chq_bank); ?></span>
                    </td>
                </tr>
            </table>

            <table class="pf-rv-lines">
                <tr>
                    <td class="pf-rv-lbl">Being</td>
                    <td class="pf-rv-colon">:</td>
                    <td class="pf-rv-uline"><?php echo htmlspecialchars($rv_being); ?></td>
                </tr>
            </table>
        </div>
        <?php } else { ?>
        <div class="pf-rv-cont-head">RECEIPT VOUCHER — continued</div>
        <?php } ?>

        <table class="pf-rv-items">
            <colgroup>
                <col style="width:34%"><col style="width:12%"><col style="width:12%">
                <col style="width:16%"><col style="width:14%"><col style="width:12%">
            </colgroup>
            <thead>
                <tr>
                    <th>Account Description</th>
                    <th class="num">Debit</th>
                    <th class="num">Credit</th>
                    <th>Inv.No</th>
                    <th>Inv.Date</th>
                    <th>Job No</th>
                </tr>
            </thead>
            <tbody>
            <?php
            foreach ($page_rows as $r) {
                $show_dr = $r['debit'] != 0.0;
                $show_cr = $r['credit'] != 0.0;
                ?>
                <tr>
                    <td class="wrap"><?php echo htmlspecialchars($r['desc']); ?></td>
                    <td class="num"><?php echo $show_dr ? htmlspecialchars(erp_print_amount($r['debit'])) : ''; ?></td>
                    <td class="num"><?php echo $show_cr ? htmlspecialchars(erp_print_amount($r['credit'])) : ''; ?></td>
                    <td><?php echo htmlspecialchars($r['inv_no']); ?></td>
                    <td><?php echo htmlspecialchars(erp_print_date($r['inv_date'])); ?></td>
                    <td><?php echo htmlspecialchars($r['job_no']); ?></td>
                </tr>
                <?php
            }
            for ($i = 0; $i < $pad; $i++) {
                echo '<tr><td>&nbsp;</td><td></td><td></td><td></td><td></td><td></td></tr>';
            }
            ?>
            </tbody>
        </table>

        <?php if ($is_last) { ?>
        <table class="pf-rv-sign">
            <tr>
                <td class="pf-rv-sign-col">
                    <div class="pf-rv-sign-space"></div>
                    <div class="pf-rv-sign-lbl">Accountant</div>
                </td>
                <td class="pf-rv-sign-col pf-rv-sign-approved">
                    <table class="pf-rv-sign-media">
                        <tr>
                            <?php if ($rv_stamp_img !== '') { ?>
                            <td class="pf-rv-sign-media-cell"><img class="pf-rv-stamp" src="<?php echo $rv_stamp_img; ?>" alt=""></td>
                            <?php } ?>
                            <?php if ($rv_sign_img !== '') { ?>
                            <td class="pf-rv-sign-media-cell"><img class="pf-rv-sign-img" src="<?php echo $rv_sign_img; ?>" alt=""></td>
                            <?php } ?>
                            <?php if ($rv_stamp_img === '' && $rv_sign_img === '') { ?>
                            <td class="pf-rv-sign-media-cell"><div class="pf-rv-sign-space-tall"></div></td>
                            <?php } ?>
                        </tr>
                    </table>
                    <div class="pf-rv-sign-lbl">Approved by</div>
                </td>
                <td class="pf-rv-sign-col">
                    <div class="pf-rv-sign-space"></div>
                    <div class="pf-rv-sign-lbl">Received from</div>
                </td>
            </tr>
        </table>
        <div class="pf-rv-note">** Cheques Subject to Realisation **</div>
        <?php } else { ?>
        <div class="pf-rv-cont">Continued on next page…</div>
        <?php } ?>

        <div class="pf-pageno">Page <?php echo $page_index + 1; ?> of <?php echo $rv_page_count; ?></div>
    </div>

    <div class="pf-rv-foot"><?php if ($rv_footer_img !== '') { ?><img src="<?php echo $rv_footer_img; ?>" alt=""><?php } ?></div>
</div>
    <?php
}
