<?php
$do_print_for_pdf = !empty($do_print_for_pdf);
?>
    <?php if (!$do_print_for_pdf): ?>
    <div class="letterhead-area">
        <img src="<?php echo doPrintSafe($do_print_header_img); ?>"
             alt="Company Header"
             class="letterhead-img">
    </div>
    <?php endif; ?>

    <div class="doc-content">

        <div class="doc-title">Delivery Order</div>

        <table class="info-grid-table" cellspacing="0" cellpadding="0">
            <tr>
                <td class="info-box-cell info-box-cell-left" valign="top">
                    <div class="info-box">
                        <div class="info-box-label">Bill To</div>
                        <div class="info-box-company">
                            M/s &nbsp;<?php echo doPrintSafe($do_header['customer_name'] ?? $do_header['ac_code']); ?>
                        </div>
                        <div class="info-box-address">
                            <?php if (!empty($do_header['po_box'])): ?>
                                PO Box : <?php echo doPrintSafe($do_header['po_box']); ?><br>
                            <?php endif; ?>
                            <?php
                                $city_country = array_filter([
                                    trim($do_header['city']    ?? ''),
                                    trim($do_header['country'] ?? ''),
                                ]);
                                if ($city_country):
                            ?>
                                <?php echo doPrintSafe(implode(' ', $city_country)); ?><br>
                            <?php endif; ?>
                            <?php if (!empty($do_header['customer_tel'])): ?>
                                Tel: <?php echo doPrintSafe($do_header['customer_tel']); ?><br>
                            <?php endif; ?>
                            <?php if (!empty($do_header['customer_trn'])): ?>
                                TRN : &nbsp;<?php echo doPrintSafe($do_header['customer_trn']); ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </td>
                <td class="info-box-cell info-box-cell-right" valign="top">
                    <div class="info-box">
                        <?php if (!empty($branch_info['company_trn'])): ?>
                        <div class="info-row">
                            <span class="info-row-key">TRN</span><span class="info-row-sep">:</span><span class="info-row-val"><?php echo doPrintSafe($branch_info['company_trn']); ?></span>
                        </div>
                        <?php endif; ?>
                        <div class="info-row">
                            <span class="info-row-key">No</span><span class="info-row-sep">:</span><span class="info-row-val"><?php echo doPrintSafe($do_header['doc_no']); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-row-key">Date</span><span class="info-row-sep">:</span><span class="info-row-val"><?php echo doPrintFmtDate($do_header['doc_dt']); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-row-key">PO No</span><span class="info-row-sep">:</span><span class="info-row-val"><?php echo doPrintSafe($do_header['po_no'] ?? ''); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-row-key">Job No</span><span class="info-row-sep">:</span><span class="info-row-val"><?php echo doPrintSafe($job_no ?? ''); ?></span>
                        </div>
                    </div>
                </td>
            </tr>
        </table>

        <div class="print-table-zone">
        <table class="items-table">
            <thead>
                <tr>
                    <th style="width:10mm;" class="center">No.</th>
                    <th>Description</th>
                    <th style="width:18mm;" class="center">Qty</th>
                    <th style="width:18mm;" class="center">Unit</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($do_lines)): ?>
                <tr>
                    <td colspan="4" style="text-align:center;color:#9ca3af;padding:8mm 3mm;font-size:8pt;">
                        No line items found for this delivery order.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($do_lines as $i => $line): ?>
                <tr>
                    <td style="text-align:center;font-weight:600;color:#374151;">
                        <?php echo $i + 1; ?>
                    </td>
                    <td>
                        <?php if (!empty($line['item_code'])): ?>
                            <span style="font-weight:600;color:#111827;"><?php echo doPrintSafe($line['item_code']); ?></span><br>
                        <?php endif; ?>
                        <?php echo nl2br(doPrintSafe($line['item_description'] ?? '')); ?>
                    </td>
                    <td style="text-align:center;font-weight:600;">
                        <?php echo doPrintSafe($line['item_qty'] ?? ''); ?>
                    </td>
                    <td style="text-align:center;color:#374151;">
                        <?php echo doPrintSafe($line['item_unit'] ?? ''); ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <tr>
                    <td colspan="4" style="border-top:2px solid #1e40af;font-size:7.5pt;color:#6b7280;text-align:right;padding:2mm 3mm;">
                        Total items: <?php echo count($do_lines); ?>
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
            <tfoot>
                <tr class="page-continue-row">
                    <td colspan="4">— Continued on next page —</td>
                </tr>
            </tfoot>
        </table>
        </div>

        <?php if (!$do_print_for_pdf): ?>
        <div class="print-closing-block">
        <?php include __DIR__ . '/do_print_closing.inc.php'; ?>
        </div><!-- /.print-closing-block -->
        <?php endif; ?>

    </div><!-- /.doc-content -->

    <?php if (!$do_print_for_pdf): ?>
    <div class="footer-area">
        <img src="<?php echo doPrintSafe($do_print_footer_img); ?>"
             alt="Company Footer"
             class="footer-img">
    </div>
    <?php endif; ?>
