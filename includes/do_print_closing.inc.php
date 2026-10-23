        <table class="doc-footer-table" cellspacing="0" cellpadding="0">
            <tr>
                <td class="doc-footer-cell doc-footer-cell-left" valign="top">
                    <div class="branch-block">
                        <div class="branch-block-label">Issued By</div>
                        <div class="branch-block-name"><?php echo doPrintSafe($do_print_branch_name); ?></div>
                    </div>
                </td>
                <td class="doc-footer-cell doc-footer-cell-right" valign="top">
                    <div class="received-block">
                        <div class="received-block-label">Received By</div>

                        <div class="dig-sig-pad" id="sig-pad">
                            <?php if ($do_print_sig_src !== ''): ?>
                                <img src="<?php echo doPrintSafe($do_print_sig_src); ?>"
                                     alt="Receiver signature"
                                     class="dig-sig-img">
                            <?php else: ?>
                                <div class="dig-sig-watermark">
                                    <span>Verified&#10;Digital&#10;Signature</span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="receiver-name-row">
                            <span class="receiver-name-key">Receiver Name</span>
                            <span class="receiver-name-val" id="sig-receiver-name"><?php echo $do_print_receiver_name !== '' ? doPrintSafe($do_print_receiver_name) : '&nbsp;'; ?></span>
                        </div>

                        <div class="receiver-name-row">
                            <span class="receiver-name-key">Receiver Number</span>
                            <span class="receiver-name-val" id="sig-receiver-contact"><?php echo ($do_print_receiver_contact ?? '') !== '' ? doPrintSafe($do_print_receiver_contact) : '&nbsp;'; ?></span>
                        </div>

                        <div class="dig-sig-timestamp">
                            Timestamp: <?php echo doPrintSafe($do_print_sig_ts); ?>
                        </div>

                    </div>
                </td>
            </tr>
        </table>

        <div class="generated-bar">
            <span class="generated-terms">
                This is a computer-generated document.
            </span>
        </div>
