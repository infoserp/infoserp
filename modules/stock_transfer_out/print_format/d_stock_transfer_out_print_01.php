<?php
/**
 * modules/stock_transfer_out/print_format/d_stock_transfer_out_print_01.php — Stock Transfer Out (A4)
 *
 * Included by shared/print_preview.php, which provides:
 *   $con, $company_code, $branch_code, $doc_code, $doc_type, $doc_no, $company (erp_print_company)
 * Echoes the A4 sheets; sets $print_title, or $print_error when the document can't be printed.
 */

$print_title = 'Stock Transfer Out';

$stmt_h = $con->prepare(
    "SELECT h.doc_no, h.doc_dt, h.location_from, h.location_to, h.branch_to, h.narration,
            lf.location_name AS from_name, lt.location_name AS to_name, bt.branch_name AS to_branch
     FROM stock_transfer_out_h h
     LEFT JOIN location_master lf
            ON lf.company_code = h.company_code AND lf.branch_code = h.branch_code AND lf.location_code = h.location_from
     LEFT JOIN location_master lt
            ON lt.company_code = h.company_code AND lt.branch_code = h.branch_code AND lt.location_code = h.location_to
     LEFT JOIN branch_master bt
            ON bt.company_code = h.company_code AND bt.branch_code = h.branch_to
     WHERE h.doc_no = ? AND h.company_code = ? AND h.branch_code = ?"
);
$stmt_h->execute([$doc_no, $company_code, $branch_code]);
$sto_h = $stmt_h->fetch(PDO::FETCH_ASSOC);
if (!$sto_h) {
    $print_error = 'Stock Transfer Out ' . $doc_no . ' was not found.';
    return;
}

$stmt_d = $con->prepare(
    "SELECT item_code, item_description, trans_unit, item_unit, trans_qty, item_cost
     FROM stock_transfer_out_d
     WHERE doc_no = ? AND company_code = ? AND branch_code = ?
     ORDER BY serial_no ASC"
);
$stmt_d->execute([$doc_no, $company_code, $branch_code]);

$sto_rows = [];
$sto_total_qty = 0;
$sto_total_amount = 0;
while ($d = $stmt_d->fetch(PDO::FETCH_ASSOC)) {
    $qty    = (float) $d['trans_qty'];
    $cost   = (float) $d['item_cost'];
    $amount = $qty * $cost;
    $sto_total_qty    += $qty;
    $sto_total_amount += $amount;
    $sto_rows[] = [
        'code'   => trim((string) $d['item_code']),
        'desc'   => trim((string) $d['item_description']),
        'unit'   => trim((string) ($d['trans_unit'] ?: $d['item_unit'])),
        'qty'    => $qty,
        'cost'   => $cost,
        'amount' => $amount,
    ];
}

$sto_from = trim((string) ($sto_h['from_name'] ?: $sto_h['location_from']));
$sto_to   = trim((string) ($sto_h['to_name'] ?: $sto_h['location_to']));
if (trim((string) $sto_h['branch_to']) !== '') {
    $sto_to .= ($sto_to !== '' ? ' - ' : '') . trim((string) ($sto_h['to_branch'] ?: $sto_h['branch_to']));
}

// Item rows per A4 page; the item area keeps the same height on every page
$sto_rows_per_page = 28;
$sto_row_mm        = 5.5;
$sto_area_mm       = 165;

$sto_pages = erp_print_pages($sto_rows, $sto_rows_per_page);
$sto_page_count = count($sto_pages);

foreach ($sto_pages as $page_index => $page_rows) {
    $is_last = ($page_index === $sto_page_count - 1);
    $fill_mm = max(0, $sto_area_mm - count($page_rows) * $sto_row_mm);
    ?>
<div class="erp-print-sheet<?php echo $page_index > 0 ? ' pf-break' : ''; ?>">
    <?php echo erp_print_company_head($company, 'STOCK TRANSFER OUT'); ?>

    <table class="pf-boxes">
        <tr>
            <td class="pf-box">
                <table class="pf-kv">
                    <tr><td class="pf-k">Transfer FROM</td><td class="pf-v"><?php echo htmlspecialchars($sto_from); ?></td></tr>
                    <tr><td class="pf-k">Transfer TO</td><td class="pf-v"><?php echo htmlspecialchars($sto_to); ?></td></tr>
                </table>
            </td>
            <td class="pf-gap"></td>
            <td class="pf-box" style="width: 75mm;">
                <table class="pf-kv">
                    <tr><td class="pf-k">S.T. Voucher No.</td><td class="pf-sep">:</td><td class="pf-v"><?php echo htmlspecialchars(trim((string) $sto_h['doc_no'])); ?></td></tr>
                    <tr><td class="pf-k">S.T. Voucher Date</td><td class="pf-sep">:</td><td class="pf-v"><?php echo htmlspecialchars(erp_print_date($sto_h['doc_dt'])); ?></td></tr>
                </table>
            </td>
        </tr>
    </table>

    <div class="pf-narr">Narration: <?php echo htmlspecialchars(trim((string) $sto_h['narration'])); ?></div>

    <table class="pf-items">
        <thead>
            <tr>
                <th style="width: 27mm;">Item Code</th>
                <th>Item Description</th>
                <th style="width: 14mm;">Unit</th>
                <th style="width: 17mm;">Qty</th>
                <th style="width: 21mm;">Cost</th>
                <th style="width: 23mm;">Amount</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($page_rows as $r) { ?>
            <tr>
                <td><?php echo htmlspecialchars($r['code']); ?></td>
                <td><?php echo htmlspecialchars($r['desc']); ?></td>
                <td class="center"><?php echo htmlspecialchars($r['unit']); ?></td>
                <td class="center"><?php echo erp_print_qty($r['qty']); ?></td>
                <td class="num"><?php echo erp_print_amount($r['cost']); ?></td>
                <td class="num"><?php echo erp_print_amount($r['amount']); ?></td>
            </tr>
            <?php } ?>
            <?php if ($fill_mm > 0) { ?>
            <tr class="pf-fill">
                <td style="height: <?php echo $fill_mm; ?>mm;"></td><td></td><td></td><td></td><td></td><td></td>
            </tr>
            <?php } ?>
            <tr class="pf-total">
                <td colspan="2"></td>
                <td class="center"><?php echo $is_last ? 'Total' : ''; ?></td>
                <td class="center"><?php echo $is_last ? erp_print_qty($sto_total_qty) : ''; ?></td>
                <td></td>
                <td class="num"><?php echo $is_last ? erp_print_amount($sto_total_amount) : ''; ?></td>
            </tr>
        </tbody>
    </table>

    <div class="pf-pageno">Page <?php echo $page_index + 1; ?> of <?php echo $sto_page_count; ?></div>

    <table class="pf-sign">
        <tr>
            <td>Transferred by......................</td>
            <td class="center">Checked by............................</td>
            <td class="right">Received by .........................</td>
        </tr>
    </table>
</div>
    <?php
}
