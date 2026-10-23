<?php
/**
 * modules/stock_transfer_in/print_format/d_stock_transfer_in_print_01.php — Stock Transfer In (A4)
 *
 * Included by shared/print_preview.php, which provides:
 *   $con, $company_code, $branch_code, $doc_code, $doc_type, $doc_no, $company (erp_print_company)
 * Echoes the A4 sheets; sets $print_title, or $print_error when the document can't be printed.
 */

$print_title = 'Stock Transfer In';

$stmt_h = $con->prepare(
    "SELECT h.doc_no, h.doc_dt, h.location_from, h.location_to, h.branch_to, h.narration,
            lf.location_name AS from_name, lt.location_name AS to_name, bt.branch_name AS to_branch
     FROM stock_transfer_in_h h
     LEFT JOIN location_master lf
            ON lf.company_code = h.company_code AND lf.branch_code = h.branch_code AND lf.location_code = h.location_from
     LEFT JOIN location_master lt
            ON lt.company_code = h.company_code AND lt.branch_code = h.branch_code AND lt.location_code = h.location_to
     LEFT JOIN branch_master bt
            ON bt.company_code = h.company_code AND bt.branch_code = h.branch_to
     WHERE h.doc_no = ? AND h.company_code = ? AND h.branch_code = ?"
);
$stmt_h->execute([$doc_no, $company_code, $branch_code]);
$sti_h = $stmt_h->fetch(PDO::FETCH_ASSOC);
if (!$sti_h) {
    $print_error = 'Stock Transfer In ' . $doc_no . ' was not found.';
    return;
}

$stmt_d = $con->prepare(
    "SELECT item_code, item_description, trans_unit, item_unit, trans_qty, transfer_outqty, transfer_outno, item_cost
     FROM stock_transfer_in_d
     WHERE doc_no = ? AND company_code = ? AND branch_code = ?
     ORDER BY serial_no ASC"
);
$stmt_d->execute([$doc_no, $company_code, $branch_code]);

$sti_rows = [];
$sti_out_nos = [];
$sti_total_out = 0;
$sti_total_in = 0;
$sti_total_amount = 0;
while ($d = $stmt_d->fetch(PDO::FETCH_ASSOC)) {
    $out_qty = (float) $d['transfer_outqty'];
    $in_qty  = (float) $d['trans_qty'];
    $cost    = (float) $d['item_cost'];
    $amount  = $in_qty * $cost;
    $sti_total_out    += $out_qty;
    $sti_total_in     += $in_qty;
    $sti_total_amount += $amount;
    $out_no = trim((string) $d['transfer_outno']);
    if ($out_no !== '' && !in_array($out_no, $sti_out_nos, true)) {
        $sti_out_nos[] = $out_no;
    }
    $sti_rows[] = [
        'code'    => trim((string) $d['item_code']),
        'desc'    => trim((string) $d['item_description']),
        'unit'    => trim((string) ($d['trans_unit'] ?: $d['item_unit'])),
        'out_qty' => $out_qty,
        'in_qty'  => $in_qty,
        'cost'    => $cost,
        'amount'  => $amount,
    ];
}

// Narration of the transfer out this document receives (when it receives a single one)
$sti_out_narration = '';
if (count($sti_out_nos) === 1) {
    $stmt_on = $con->prepare("SELECT TOP 1 narration FROM stock_transfer_out_h WHERE company_code = ? AND doc_no = ?");
    $stmt_on->execute([$company_code, $sti_out_nos[0]]);
    $sti_out_narration = trim((string) $stmt_on->fetchColumn());
}

$sti_from = trim((string) ($sti_h['from_name'] ?: $sti_h['location_from']));
$sti_to   = trim((string) ($sti_h['to_name'] ?: $sti_h['location_to']));
if (trim((string) $sti_h['branch_to']) !== '') {
    $sti_to .= ($sti_to !== '' ? ' - ' : '') . trim((string) ($sti_h['to_branch'] ?: $sti_h['branch_to']));
}

// Item rows per A4 page; the item area keeps the same height on every page
$sti_rows_per_page = 28;
$sti_row_mm        = 5.5;
$sti_area_mm       = 165;

$sti_pages = erp_print_pages($sti_rows, $sti_rows_per_page);
$sti_page_count = count($sti_pages);

foreach ($sti_pages as $page_index => $page_rows) {
    $is_last = ($page_index === $sti_page_count - 1);
    $fill_mm = max(0, $sti_area_mm - count($page_rows) * $sti_row_mm);
    ?>
<div class="erp-print-sheet<?php echo $page_index > 0 ? ' pf-break' : ''; ?>">
    <?php echo erp_print_company_head($company, 'STOCK TRANSFER IN'); ?>

    <table class="pf-boxes">
        <tr>
            <td class="pf-box">
                <table class="pf-kv">
                    <tr><td class="pf-k">Transfer FROM</td><td class="pf-v"><?php echo htmlspecialchars($sti_from); ?></td></tr>
                    <tr><td class="pf-k">Transfer TO</td><td class="pf-v"><?php echo htmlspecialchars($sti_to); ?></td></tr>
                    <tr><td class="pf-k">Narration</td><td class="pf-v"><?php echo htmlspecialchars($sti_out_narration); ?></td></tr>
                </table>
            </td>
            <td class="pf-gap"></td>
            <td class="pf-box" style="width: 75mm;">
                <table class="pf-kv">
                    <tr><td class="pf-k">Transfer IN No</td><td class="pf-sep">:</td><td class="pf-v"><?php echo htmlspecialchars(trim((string) $sti_h['doc_no'])); ?></td></tr>
                    <tr><td class="pf-k">Transfer IN Date</td><td class="pf-sep">:</td><td class="pf-v"><?php echo htmlspecialchars(erp_print_date($sti_h['doc_dt'])); ?></td></tr>
                    <tr><td class="pf-k">Transfer Out No</td><td class="pf-sep">:</td><td class="pf-v"><?php echo htmlspecialchars(implode(', ', $sti_out_nos)); ?></td></tr>
                </table>
            </td>
        </tr>
    </table>

    <div class="pf-narr">Narration: <?php echo htmlspecialchars(trim((string) $sti_h['narration'])); ?></div>

    <table class="pf-items">
        <thead>
            <tr>
                <th style="width: 25mm;">Item Code</th>
                <th>Item Description</th>
                <th style="width: 13mm;">Unit</th>
                <th style="width: 15mm;">OUT Qty</th>
                <th style="width: 15mm;">IN Qty</th>
                <?php /* Cost / Amount hidden for now
                <th style="width: 19mm;">Cost</th>
                <th style="width: 21mm;">Amount</th>
                */ ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($page_rows as $r) { ?>
            <tr>
                <td><?php echo htmlspecialchars($r['code']); ?></td>
                <td><?php echo htmlspecialchars($r['desc']); ?></td>
                <td class="center"><?php echo htmlspecialchars($r['unit']); ?></td>
                <td class="center"><?php echo erp_print_qty($r['out_qty']); ?></td>
                <td class="center"><?php echo erp_print_qty($r['in_qty']); ?></td>
                <?php /* Cost / Amount hidden for now
                <td class="num"><?php echo erp_print_amount($r['cost']); ?></td>
                <td class="num"><?php echo erp_print_amount($r['amount']); ?></td>
                */ ?>
            </tr>
            <?php } ?>
            <?php if ($fill_mm > 0) { ?>
            <tr class="pf-fill">
                <td style="height: <?php echo $fill_mm; ?>mm;"></td><td></td><td></td><td></td><td></td><?php /* Cost / Amount hidden for now <td></td><td></td> */ ?>
            </tr>
            <?php } ?>
            <tr class="pf-total">
                <td colspan="2"></td>
                <td class="center"><?php echo $is_last ? 'Total' : ''; ?></td>
                <td class="center"><?php echo $is_last ? erp_print_qty($sti_total_out) : ''; ?></td>
                <td class="center"><?php echo $is_last ? erp_print_qty($sti_total_in) : ''; ?></td>
                <?php /* Cost / Amount hidden for now
                <td></td>
                <td class="num"><?php echo $is_last ? erp_print_amount($sti_total_amount) : ''; ?></td>
                */ ?>
            </tr>
        </tbody>
    </table>

    <div class="pf-pageno">Page <?php echo $page_index + 1; ?> of <?php echo $sti_page_count; ?></div>

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
