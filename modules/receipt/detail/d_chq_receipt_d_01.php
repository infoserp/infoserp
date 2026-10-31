<?php
// Receipt Voucher Detail — account lines grid
// Fragment included by modules/receipt/receipt.php after the header fragment (d_chq_receipt_h_01).
// The header closes <form id="rvForm"> before this file renders, so the grid lines are joined to
// that form through the hidden detail_lines_json field (form="rvForm"), filled on submit.
// When called directly for AJAX (?action=rvd_search_jobs), load db/auth.
if (!isset($con)) {
    require('../../../db.php');
    include('../../../auth.php');
}

$rvd_company_code = $_SESSION['company_code'] ?? '';
$rvd_branch_code  = $_SESSION['branch_code']  ?? '';

// AJAX Handler: Job No lookup
if (isset($_GET['action']) && $_GET['action'] === 'rvd_search_jobs') {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json');
    if (!isset($_SESSION['username'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
    try {
        $term = '%' . trim($_GET['term'] ?? '') . '%';
        $stmt = $con->prepare(
            "SELECT TOP 200 job_no, ISNULL(job_name, '') AS job_name
             FROM job_master
             WHERE company_code = ? AND branch_code = ? AND (job_no LIKE ? OR job_name LIKE ?)
             ORDER BY job_no DESC"
        );
        $stmt->execute([$rvd_company_code, $rvd_branch_code, $term, $term]);
        $jobs = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $jobs[] = ['job_no' => trim($row['job_no']), 'job_name' => trim($row['job_name'])];
        }
        echo json_encode($jobs);
    } catch (Exception $e) {
        error_log("Receipt detail job search error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

$rvd_view_mode = !empty($is_view_mode);
$rvd_doc_no    = isset($doc_no) ? trim((string) $doc_no) : '';

// Saved lines of the loaded document
$rvd_lines = [];
if ($rvd_view_mode && $rvd_doc_no !== '') {
    try {
        $stmt_l = $con->prepare(
            "SELECT d.serial_no, d.ac_code, ISNULL(am.description, '') AS ac_name,
                    d.ac_subcode, d.costcenter_code, d.dr_amount, d.cr_amount, d.narration,
                    d.job_no, d.subjob_no, d.invoice_no, d.invoice_date, d.salesman_code
             FROM chq_receipt_d d
             LEFT JOIN ac_master am ON am.company_code = d.company_code AND am.ac_code = d.ac_code
             WHERE d.company_code = ? AND d.branch_code = ? AND d.doc_no = ?
             ORDER BY d.serial_no"
        );
        $stmt_l->execute([$rvd_company_code, $rvd_branch_code, $rvd_doc_no]);
        while ($row = $stmt_l->fetch(PDO::FETCH_ASSOC)) {
            $inv_dt = trim((string) ($row['invoice_date'] ?? ''));
            $rvd_lines[] = [
                'ac_code'         => trim((string) $row['ac_code']),
                'ac_name'         => trim((string) $row['ac_name']),
                'dr_amount'       => (float) ($row['dr_amount'] ?? 0),
                'cr_amount'       => (float) ($row['cr_amount'] ?? 0),
                'ac_subcode'      => trim((string) ($row['ac_subcode'] ?? '')),
                'costcenter_code' => trim((string) ($row['costcenter_code'] ?? '')),
                'invoice_no'      => trim((string) ($row['invoice_no'] ?? '')),
                'invoice_date'    => $inv_dt !== '' ? substr($inv_dt, 0, 10) : '',
                'narration'       => trim((string) ($row['narration'] ?? '')),
                'salesman_code'   => trim((string) ($row['salesman_code'] ?? '')),
                'job_no'          => trim((string) ($row['job_no'] ?? '')),
                'subjob_no'       => trim((string) ($row['subjob_no'] ?? '')),
            ];
        }
    } catch (Exception $e) {
        error_log("Receipt detail lines fetch error: " . $e->getMessage());
    }
}

// Salesman dropdown
$rvd_salesmen = [];
try {
    $stmt_sm = $con->prepare("SELECT salesman_code, salesman_name FROM salesman WHERE company_code = ? ORDER BY salesman_name");
    $stmt_sm->execute([$rvd_company_code]);
    while ($row = $stmt_sm->fetch(PDO::FETCH_ASSOC)) {
        $rvd_salesmen[] = ['code' => trim($row['salesman_code']), 'name' => trim($row['salesman_name'])];
    }
} catch (Exception $e) {
    error_log("Receipt detail salesman fetch error: " . $e->getMessage());
}

// Cost center suggestions
$rvd_costcenters = [];
try {
    $stmt_cc = $con->prepare("SELECT costcenter_code, costcenter_name FROM costcenter WHERE company_code = ? ORDER BY costcenter_code");
    $stmt_cc->execute([$rvd_company_code]);
    while ($row = $stmt_cc->fetch(PDO::FETCH_ASSOC)) {
        $rvd_costcenters[] = ['code' => trim($row['costcenter_code']), 'name' => trim((string) $row['costcenter_name'])];
    }
} catch (Exception $e) {
    error_log("Receipt detail cost center fetch error: " . $e->getMessage());
}

// Absolute URL path to this file — used for AJAX calls (same approach as the header fragment)
$rvd_self_url = '/' . ltrim(
    str_replace(
        str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'] ?? '')),
        '',
        str_replace('\\', '/', realpath(__FILE__))
    ),
    '/'
);
?>

<div class="detail-container">
        <input type="hidden" name="detail_lines_json" id="rvdLinesJson" form="rvForm" value="">
        <div class="detail-header">
            <h2>Account Lines</h2>
        </div>
        <div class="detail-table-wrapper">
            <table class="detail-table detail-table-fixed" id="rvdGrid">
                <colgroup>
                    <col style="width:44px;">
                    <col style="width:80px;">
                    <col>
                    <col style="width:170px;">
                    <col style="width:84px;">
                    <col style="width:84px;">
                    <col style="width:130px;">
                    <col style="width:130px;">
                    <col style="width:140px;">
                    <col style="width:36px;">
                </colgroup>
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Code</th>
                        <th>Account Description</th>
                        <th>Narration</th>
                        <th>Debit</th>
                        <th>Credit</th>
                        <th>INV. No</th>
                        <th>INV Date</th>
                        <th>Salesman</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="rvdEmpty" style="display:none;">
                    <tr>
                        <td colspan="10" class="empty-state">
                            <i class="fa fa-inbox"></i>
                            <p>No account lines</p>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="detail-toolbar detail-toolbar-bottom" id="rvdActions" style="<?php echo $rvd_view_mode ? 'display:none;' : ''; ?>">
            <button type="button" class="detail-btn detail-btn-link" onclick="rvdAddLine()">
                <i class="fa fa-plus"></i> Add line
            </button>
        </div>
        <datalist id="rvdCostcenterList">
            <?php foreach ($rvd_costcenters as $cc): ?>
                <option value="<?php echo htmlspecialchars($cc['code']); ?>"><?php echo htmlspecialchars($cc['name']); ?></option>
            <?php endforeach; ?>
        </datalist>
</div><!-- /.detail-container -->

<!-- Line lookup modal (Account / Invoice / Job) -->
<div id="rvdLookupModal" class="popup-overlay erp-search" style="display:none;">
    <div class="erp-search-dialog" role="dialog" aria-modal="true">
        <div class="erp-search-head">
            <h4 class="erp-search-title"><i class="fa fa-search"></i><span id="rvdLookupTitle"></span></h4>
            <button type="button" class="erp-search-close" onclick="rvdCloseLookup()" title="Close" aria-label="Close"><i class="fa fa-times"></i></button>
        </div>
        <div class="erp-search-bar" id="rvdLookupSearchWrap">
            <div class="erp-search-input">
                <i class="fa fa-search"></i>
                <input type="text" id="rvdLookupInput" autocomplete="off" placeholder="Search..." oninput="rvdRunLookup(this.value)">
            </div>
        </div>
        <div class="erp-search-body">
            <table class="erp-search-table">
                <thead id="rvdLookupHead"></thead>
                <tbody id="rvdLookupBody"></tbody>
            </table>
        </div>
    </div>
</div>

<!-- Line narration More.. modal -->
<div id="rvdMoreModal" class="popup-overlay erp-search" style="display:none;">
    <div class="erp-search-dialog sm" role="dialog" aria-modal="true">
        <div class="erp-search-head">
            <h4 class="erp-search-title"><i class="fa fa-comment-o"></i>Narration</h4>
            <button type="button" class="erp-search-close" onclick="rvdCloseMore()" title="Close" aria-label="Close"><i class="fa fa-times"></i></button>
        </div>
        <div class="erp-search-content">
            <textarea id="rvdMoreText" class="erp-search-textarea" rows="6" maxlength="1000"></textarea>
        </div>
        <div class="erp-search-foot">
            <button type="button" class="erp-search-btn" onclick="rvdCloseMore()">Close</button>
        </div>
    </div>
</div>

<!-- Debit unlock — same INFOS Yes/No style as unsaved-leave warning -->
<div id="rvdDebitAskModal" class="popup-overlay erp-search erp-leave-warn" style="display:none; z-index:1300;">
    <div class="erp-search-dialog sm erp-leave-dialog" role="alertdialog" aria-modal="true" aria-labelledby="rvdDebitAskTitle" aria-describedby="rvdDebitAskMsg">
        <div class="erp-leave-icon" aria-hidden="true"><i class="fa fa-exclamation-circle"></i></div>
        <div class="erp-leave-body">
            <h4 id="rvdDebitAskTitle">INFOS</h4>
            <p id="rvdDebitAskMsg">Would you like to enter a debit amount for this line?</p>
        </div>
        <div class="erp-leave-actions">
            <button type="button" class="erp-leave-btn primary" id="rvdDebitAskYes">Yes</button>
            <button type="button" class="erp-leave-btn" id="rvdDebitAskNo">No</button>
        </div>
    </div>
</div>

<script>
    var RVD_SELF          = <?php echo json_encode($rvd_self_url); ?>;
    var RVD_HEADER_SELF   = (typeof _rvHeaderSelf !== 'undefined') ? _rvHeaderSelf : '';
    var RVD_LINES         = <?php echo json_encode($rvd_lines); ?>;
    var RVD_SALESMEN      = <?php echo json_encode($rvd_salesmen); ?>;
    var RVD_DEFAULT_SALESMAN = <?php echo json_encode(isset($salesman_code) ? (string) $salesman_code : ''); ?>;
    var rvdEditable       = <?php echo $rvd_view_mode ? 'false' : 'true'; ?>;

    // Fields of one line, in tab order (ac_name is display-only)
    var RVD_FIELDS = ['ac_code', 'ac_name', 'dr_amount', 'cr_amount', 'ac_subcode', 'costcenter_code',
                      'invoice_no', 'invoice_date', 'narration', 'salesman_code', 'job_no', 'subjob_no'];
    var RVD_LOCKED = ['ac_name', 'ac_code']; // ac_code is filled only through the account lookup

    function rvdEsc(text) {
        return String(text == null ? '' : text).replace(/[&<>"']/g, function (m) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m];
        });
    }

    function rvdParseNum(v) {
        var n = parseFloat(String(v == null ? '' : v).replace(/,/g, ''));
        return isNaN(n) ? 0 : n;
    }

    function rvdFmtNum(n) {
        return rvdParseNum(n).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    function rvdSalesmanOptions(selected) {
        var html = '<option value=""></option>';
        var found = false;
        RVD_SALESMEN.forEach(function (s) {
            var sel = (s.code === selected);
            if (sel) found = true;
            html += '<option value="' + rvdEsc(s.code) + '"' + (sel ? ' selected' : '') + '>' + rvdEsc(s.name) + '</option>';
        });
        if (selected && !found) {
            html += '<option value="' + rvdEsc(selected) + '" selected>' + rvdEsc(selected) + '</option>';
        }
        return html;
    }

    function rvdLineHtml(line) {
        return ''
            + '<tr class="detail-row">'
            +   '<td class="line-no"><span class="rvd-no-txt"></span>'
            +     '<input type="hidden" data-f="ac_subcode"><input type="hidden" data-f="costcenter_code">'
            +     '<input type="hidden" data-f="job_no"><input type="hidden" data-f="subjob_no"></td>'
            +   '<td><input type="text" class="detail-input" data-f="ac_code" maxlength="5" placeholder="Click to select" title="Click to select account"></td>'
            +   '<td><input type="text" class="detail-input" data-f="ac_name" tabindex="-1"></td>'
            +   '<td><div class="detail-cell"><input type="text" class="detail-input" data-f="narration" maxlength="1000">'
            +     '<button type="button" class="detail-more" tabindex="-1" onclick="rvdOpenMore(this)">More..</button></div></td>'
            +   '<td><input type="text" class="detail-input num" data-f="dr_amount" data-dr-unlocked="0" readonly title="Debit is locked until confirmed"></td>'
            +   '<td><input type="text" class="detail-input num" data-f="cr_amount"></td>'
            +   '<td><div class="detail-cell"><input type="text" class="detail-input" data-f="invoice_no" maxlength="50">'
            // +     '<button type="button" class="rvd-pick detail-pick" data-pick="invoice" tabindex="-1" title="Select invoice (F2)"><i class="fa fa-arrow-circle-down"></i></button>'
            +   '</div></td>'
            +   '<td><input type="date" class="detail-input" data-f="invoice_date"></td>'
            +   '<td><select class="detail-input" data-f="salesman_code">' + rvdSalesmanOptions(line.salesman_code || '') + '</select></td>'
            +   '<td class="rvd-del-cell"><button type="button" class="rvd-del btn-danger" title="Delete line" tabindex="-1" onclick="rvdDeleteLine(this)"><i class="fa fa-trash"></i></button></td>'
            + '</tr>';
    }

    function rvdField(lineEl, f) {
        return lineEl.querySelector('[data-f="' + f + '"]');
    }

    function rvdAddLine(line, silent) {
        line = line || { salesman_code: RVD_DEFAULT_SALESMAN };
        var tbody = document.createElement('tbody');
        tbody.className = 'rvd-line';
        tbody.innerHTML = rvdLineHtml(line);
        document.getElementById('rvdGrid').appendChild(tbody);

        RVD_FIELDS.forEach(function (f) {
            var el = rvdField(tbody, f);
            if (!el || f === 'salesman_code' || line[f] === undefined || line[f] === null) return;
            el.value = (f === 'dr_amount' || f === 'cr_amount') ? rvdFmtNum(line[f]) : line[f];
        });

        rvdApplyEditable(tbody);
        rvdRenumber();
        if (!silent) {
            var code = rvdField(tbody, 'ac_code');
            if (code) code.focus();
        }
        return tbody;
    }

    function rvdDeleteLine(btn) {
        if (!rvdEditable) return;
        var lineEl = btn.closest('.rvd-line');
        var lines = document.querySelectorAll('#rvdGrid .rvd-line');
        if (lines.length <= 1) {
            lineEl.remove();
            rvdAddLine(null, true);
        } else {
            lineEl.remove();
        }
        rvdRenumber();
        rvdRecalc();
    }

    function rvdRenumber() {
        var lines = document.querySelectorAll('#rvdGrid .rvd-line');
        lines.forEach(function (lineEl, i) {
            lineEl.querySelector('.rvd-no-txt').textContent = String(i + 1).padStart(4, '0');
        });
        document.getElementById('rvdEmpty').style.display = lines.length ? 'none' : '';
    }

    function rvdIsDebitUnlocked(el) {
        return el && el.getAttribute('data-dr-unlocked') === '1';
    }

    function rvdApplyDebitLock(scope) {
        scope.querySelectorAll('[data-f="dr_amount"]').forEach(function (el) {
            var unlock = rvdEditable && rvdIsDebitUnlocked(el);
            el.readOnly = !unlock;
            el.style.cursor = (rvdEditable && !unlock) ? 'pointer' : '';
            el.title = unlock ? '' : 'Debit is locked until confirmed';
        });
    }

    var _rvdDebitAskEl = null;
    var _rvdDebitAskOpen = false;

    function rvdCloseDebitAsk() {
        var modal = document.getElementById('rvdDebitAskModal');
        if (modal) modal.style.display = 'none';
        _rvdDebitAskOpen = false;
        _rvdDebitAskEl = null;
    }

    function rvdUnlockDebitField(el) {
        if (!el) return;
        el.setAttribute('data-dr-unlocked', '1');
        el.readOnly = false;
        el.style.cursor = '';
        el.title = '';
    }

    /** App-style Yes/No (not browser confirm). Only call from Debit field user actions. */
    function rvdRequestDebitUnlock(el) {
        if (!rvdEditable || !el || rvdIsDebitUnlocked(el) || _rvdDebitAskOpen) return;
        _rvdDebitAskOpen = true;
        _rvdDebitAskEl = el;
        var modal = document.getElementById('rvdDebitAskModal');
        if (!modal) {
            _rvdDebitAskOpen = false;
            return;
        }
        modal.style.display = 'flex';
        var yes = document.getElementById('rvdDebitAskYes');
        if (yes) yes.focus();
    }

    document.addEventListener('click', function (e) {
        if (!e.target || !e.target.closest) return;
        if (e.target.closest('#rvdDebitAskYes')) {
            var el = _rvdDebitAskEl;
            rvdCloseDebitAsk();
            if (el) {
                rvdUnlockDebitField(el);
                setTimeout(function () { el.focus(); }, 0);
            }
            return;
        }
        if (e.target.closest('#rvdDebitAskNo') || e.target.id === 'rvdDebitAskModal') {
            rvdCloseDebitAsk();
        }
    });
    document.addEventListener('keydown', function (e) {
        var modal = document.getElementById('rvdDebitAskModal');
        if (!modal || modal.style.display === 'none') return;
        if (e.key === 'Escape') {
            e.preventDefault();
            rvdCloseDebitAsk();
        }
        if (e.key === 'Enter') {
            e.preventDefault();
            var el = _rvdDebitAskEl;
            rvdCloseDebitAsk();
            if (el) {
                rvdUnlockDebitField(el);
                setTimeout(function () { el.focus(); }, 0);
            }
        }
    });

    function rvdApplyEditable(scope) {
        scope.querySelectorAll('[data-f]').forEach(function (el) {
            var locked = !rvdEditable || RVD_LOCKED.indexOf(el.getAttribute('data-f')) >= 0;
            if (el.tagName === 'SELECT' || el.type === 'date') {
                el.disabled = locked;
            } else {
                el.readOnly = locked;
            }
        });
        // Debit stays view-only until the user confirms unlock (Receipt Voucher only)
        rvdApplyDebitLock(scope);
        scope.querySelectorAll('[data-f="ac_code"]').forEach(function (el) { el.style.cursor = rvdEditable ? 'pointer' : ''; });
        scope.querySelectorAll('.rvd-pick').forEach(function (b) { b.disabled = !rvdEditable; });
        scope.querySelectorAll('.rvd-del').forEach(function (b) { b.style.display = rvdEditable ? '' : 'none'; });
    }

    function rvdSetEditable(on) {
        rvdEditable = !!on;
        rvdApplyEditable(document.getElementById('rvdGrid'));
        document.getElementById('rvdActions').style.display = rvdEditable ? '' : 'none';
        if (rvdEditable && !document.querySelector('#rvdGrid .rvd-line')) {
            rvdAddLine(null, true);
        }
    }

    // Header Amount = total Credit - total Debit of the lines
    function rvdRecalc() {
        var dr = 0, cr = 0;
        document.querySelectorAll('#rvdGrid .rvd-line').forEach(function (lineEl) {
            dr += rvdParseNum(rvdField(lineEl, 'dr_amount').value);
            cr += rvdParseNum(rvdField(lineEl, 'cr_amount').value);
        });
        var amount = document.querySelector('#rvForm input[name="amount"]');
        if (amount) amount.value = (cr - dr).toFixed(2);
    }

    function rvdCollectLines() {
        var out = [];
        document.querySelectorAll('#rvdGrid .rvd-line').forEach(function (lineEl, i) {
            var line = { serial_no: String(i + 1).padStart(4, '0') };
            RVD_FIELDS.forEach(function (f) {
                var el = rvdField(lineEl, f);
                var v = el ? el.value.trim() : '';
                line[f] = (f === 'dr_amount' || f === 'cr_amount') ? rvdParseNum(v) : v;
            });
            if (line.ac_code !== '' || line.dr_amount !== 0 || line.cr_amount !== 0 || line.narration !== '') {
                out.push(line);
            }
        });
        return out;
    }

    // ── Header Ref. account → lines ──────────────────────────────────────────
    // Lines with no code (or still on the previous Ref. account) take the new Ref. account.
    function rvdApplyRefAccount(acCode, acName, prevCode) {
        if (!rvdEditable) return;
        if (!document.querySelector('#rvdGrid .rvd-line')) rvdAddLine(null, true);
        document.querySelectorAll('#rvdGrid .rvd-line').forEach(function (lineEl) {
            var cur = rvdField(lineEl, 'ac_code').value.trim();
            if (cur === '' || (prevCode && cur === prevCode)) {
                rvdField(lineEl, 'ac_code').value = acCode;
                rvdField(lineEl, 'ac_name').value = acName;
            }
        });
    }

    function rvdLineIsBlank(lineEl, refCode) {
        var code = rvdField(lineEl, 'ac_code').value.trim();
        return (code === '' || code === refCode)
            && rvdParseNum(rvdField(lineEl, 'dr_amount').value) === 0
            && rvdParseNum(rvdField(lineEl, 'cr_amount').value) === 0
            && rvdField(lineEl, 'invoice_no').value.trim() === '';
    }

    // Header SELECT → one credit line per selected invoice (an invoice already in the grid is updated)
    function rvdApplyInvoices(refCode, refName, invoices) {
        if (!rvdEditable) return;
        document.querySelectorAll('#rvdGrid .rvd-line').forEach(function (lineEl) {
            if (rvdLineIsBlank(lineEl, refCode)) lineEl.remove();
        });
        invoices.forEach(function (inv) {
            var lineEl = null;
            document.querySelectorAll('#rvdGrid .rvd-line').forEach(function (l) {
                if (rvdField(l, 'invoice_no').value.trim() === inv.invoiceno && rvdField(l, 'ac_code').value.trim() === refCode) lineEl = l;
            });
            if (!lineEl) lineEl = rvdAddLine(null, true);
            rvdField(lineEl, 'ac_code').value = refCode;
            rvdField(lineEl, 'ac_name').value = refName;
            rvdField(lineEl, 'invoice_no').value = inv.invoiceno;
            rvdField(lineEl, 'invoice_date').value = String(inv.invoicedate || '').substring(0, 10);
            rvdField(lineEl, 'cr_amount').value = rvdFmtNum(inv.amount);
        });
        if (!document.querySelector('#rvdGrid .rvd-line')) rvdAddLine(null, true);
        rvdRenumber();
        rvdRecalc();
    }

    // ── Generic lookup modal ─────────────────────────────────────────────────
    var _rvdLookup = null;      // { url(term), cols, pick(row), search }
    var _rvdLookupLine = null;  // line element the lookup fills
    var _rvdLookupTimer;

    function rvdOpenLookup(lineEl, cfg) {
        _rvdLookup = cfg;
        _rvdLookupLine = lineEl;
        document.getElementById('rvdLookupTitle').textContent = cfg.title;
        document.getElementById('rvdLookupSearchWrap').style.display = cfg.search === false ? 'none' : '';
        var head = '<tr>';
        cfg.cols.forEach(function (c) { head += '<th style="' + (c.num ? 'text-align:right;' : '') + '">' + rvdEsc(c.label) + '</th>'; });
        head += '<th style="width:70px;"></th></tr>';
        document.getElementById('rvdLookupHead').innerHTML = head;
        document.getElementById('rvdLookupModal').style.display = 'flex';
        document.body.style.overflow = 'hidden';
        var input = document.getElementById('rvdLookupInput');
        input.value = '';
        if (cfg.search !== false) input.focus();
        rvdRunLookup('');
    }

    function rvdCloseLookup() {
        document.getElementById('rvdLookupModal').style.display = 'none';
        document.body.style.overflow = '';
        _rvdLookup = null;
    }

    function rvdRunLookup(term) {
        clearTimeout(_rvdLookupTimer);
        var cfg = _rvdLookup;
        if (!cfg) return;
        var colspan = cfg.cols.length + 1;
        _rvdLookupTimer = setTimeout(function () {
            var tbody = document.getElementById('rvdLookupBody');
            fetch(cfg.url(term))
                .then(function (r) {
                    if (!r.ok) throw new Error('Server returned ' + r.status);
                    return r.json();
                })
                .then(function (data) {
                    if (cfg !== _rvdLookup) return;
                    tbody.innerHTML = '';
                    if (!Array.isArray(data)) throw new Error((data && data.error) || 'Invalid response');
                    if (!data.length) {
                        tbody.innerHTML = '<tr><td colspan="' + colspan + '" class="text-center p-3 text-muted">No records found</td></tr>';
                        return;
                    }
                    data.forEach(function (row) {
                        var tr = document.createElement('tr');
                        var html = '';
                        cfg.cols.forEach(function (c) {
                            var v = c.fmt ? c.fmt(row[c.k]) : row[c.k];
                            html += '<td style="' + (c.num ? 'text-align:right;' : '') + '">' + rvdEsc(v) + '</td>';
                        });
                        html += '<td class="text-center"><button type="button" class="btn btn-sm btn-primary"><i class="fa fa-check"></i></button></td>';
                        tr.innerHTML = html;
                        tr.style.cursor = 'pointer';
                        tr.addEventListener('click', function () {
                            cfg.pick(_rvdLookupLine, row);
                            rvdCloseLookup();
                        });
                        tbody.appendChild(tr);
                    });
                })
                .catch(function (err) {
                    console.error('rvdRunLookup error:', err);
                    tbody.innerHTML = '<tr><td colspan="' + colspan + '" class="text-center p-3 text-danger">Error: ' + rvdEsc(err.message) + '</td></tr>';
                });
        }, 250);
    }

    function rvdLookupAccount(lineEl) {
        rvdOpenLookup(lineEl, {
            title: 'Select Account',
            url: function (term) { return RVD_HEADER_SELF + '?action=search_references&term=' + encodeURIComponent(term); },
            cols: [{ k: 'ac_code', label: 'Code' }, { k: 'description', label: 'Account Name' }],
            pick: function (l, row) {
                rvdField(l, 'ac_code').value = row.ac_code;
                rvdField(l, 'ac_name').value = row.description;
                rvdField(l, 'ac_code').classList.remove('is-invalid');
                // Focus Credit — do not focus Debit (Debit unlock is user-initiated only)
                rvdField(l, 'cr_amount').focus();
            }
        });
    }

    function rvdLookupInvoice(lineEl) {
        var acCode = rvdField(lineEl, 'ac_code').value.trim();
        if (acCode === '') {
            alert('Please select the account Code of this line first.');
            rvdField(lineEl, 'ac_code').focus();
            return;
        }
        rvdOpenLookup(lineEl, {
            title: 'Outstanding Invoices - ' + (rvdField(lineEl, 'ac_name').value || acCode),
            search: false,
            url: function () { return RVD_HEADER_SELF + '?action=get_outstanding_invoices&ac_code=' + encodeURIComponent(acCode); },
            cols: [
                { k: 'invoiceno', label: 'Invoice No.' },
                { k: 'invoicedate', label: 'Invoice Date', fmt: function (v) { return String(v || '').substring(0, 10); } },
                { k: 'debit', label: 'Debit', num: true, fmt: rvdFmtNum },
                { k: 'credit', label: 'Credit', num: true, fmt: rvdFmtNum },
                { k: 'balance', label: 'Balance', num: true, fmt: rvdFmtNum }
            ],
            pick: function (l, row) {
                var invNo = row.invoiceno || '';
                rvdField(l, 'invoice_no').value = invNo;
                rvdField(l, 'invoice_date').value = String(row.invoicedate || '').substring(0, 10);
                var crEl = rvdField(l, 'cr_amount');
                var balance = rvdParseNum(row.balance);
                if (rvdParseNum(crEl.value) === 0 && balance > 0) {
                    crEl.value = rvdFmtNum(balance);
                }
                rvdRecalc();
            }
        });
    }

    function rvdLookupJob(lineEl) {
        rvdOpenLookup(lineEl, {
            title: 'Select Job',
            url: function (term) { return RVD_SELF + '?action=rvd_search_jobs&term=' + encodeURIComponent(term); },
            cols: [{ k: 'job_no', label: 'Job No' }, { k: 'job_name', label: 'Job Name' }],
            pick: function (l, row) {
                rvdField(l, 'job_no').value = row.job_no;
                rvdField(l, 'subjob_no').focus();
            }
        });
    }

    // ── Narration More.. ─────────────────────────────────────────────────────
    var _rvdMoreLine = null;

    function rvdOpenMore(btn) {
        _rvdMoreLine = btn.closest('.rvd-line');
        var ta = document.getElementById('rvdMoreText');
        ta.value = rvdField(_rvdMoreLine, 'narration').value;
        ta.readOnly = !rvdEditable;
        document.getElementById('rvdMoreModal').style.display = 'flex';
        ta.focus();
    }

    function rvdCloseMore() {
        // Apply to line field; document toolbar Save persists to DB
        if (_rvdMoreLine && rvdEditable) {
            rvdField(_rvdMoreLine, 'narration').value = document.getElementById('rvdMoreText').value;
        }
        document.getElementById('rvdMoreModal').style.display = 'none';
        _rvdMoreLine = null;
    }

    // ── Grid events ──────────────────────────────────────────────────────────
    (function () {
        var grid = document.getElementById('rvdGrid');

        grid.addEventListener('focusin', function (e) {
            var lineEl = e.target.closest('.rvd-line');
            grid.querySelectorAll('.rvd-line.detail-line-active').forEach(function (l) { if (l !== lineEl) l.classList.remove('detail-line-active'); });
            if (lineEl) lineEl.classList.add('detail-line-active');
            var f = e.target.getAttribute('data-f');
            // Do not prompt on focus alone (account select must not trigger Debit unlock)
            if (rvdEditable && (f === 'dr_amount' || f === 'cr_amount') && e.target.value !== '' && !e.target.readOnly) {
                e.target.value = String(rvdParseNum(e.target.value));
            }
        });

        grid.addEventListener('focusout', function (e) {
            var f = e.target.getAttribute('data-f');
            if (f === 'dr_amount' || f === 'cr_amount') {
                e.target.value = e.target.value.trim() !== '' ? rvdFmtNum(e.target.value) : '';
            }
        });

        grid.addEventListener('input', function (e) {
            var f = e.target.getAttribute('data-f');
            if (f === 'dr_amount' || f === 'cr_amount') rvdRecalc();
        });

        // Debit unlock only when the user acts on the Debit input (not after account pick)
        grid.addEventListener('pointerdown', function (e) {
            var el = e.target;
            if (!el || el.getAttribute('data-f') !== 'dr_amount') return;
            if (!rvdEditable || rvdIsDebitUnlocked(el)) return;
            e.preventDefault();
            e.stopPropagation();
            rvdRequestDebitUnlock(el);
        }, true);

        grid.addEventListener('click', function (e) {
            if (rvdEditable && e.target.getAttribute('data-f') === 'ac_code') {
                rvdLookupAccount(e.target.closest('.rvd-line'));
                return;
            }
            var btn = e.target.closest('.rvd-pick');
            if (!btn || btn.disabled || !rvdEditable) return;
            var lineEl = btn.closest('.rvd-line');
            if (btn.getAttribute('data-pick') === 'invoice') rvdLookupInvoice(lineEl);
            if (btn.getAttribute('data-pick') === 'job') rvdLookupJob(lineEl);
        });

        grid.addEventListener('keydown', function (e) {
            var f = e.target.getAttribute('data-f');
            if (f === 'dr_amount' && rvdEditable && !rvdIsDebitUnlocked(e.target)) {
                if (e.key === 'Tab') return;
                e.preventDefault();
                rvdRequestDebitUnlock(e.target);
                return;
            }
            if (e.key === 'Enter') {
                e.preventDefault();
                return;
            }
            if (e.key === 'F2' && rvdEditable) {
                var lineEl = e.target.closest('.rvd-line');
                if (f === 'invoice_no') { e.preventDefault(); rvdLookupInvoice(lineEl); }
                if (f === 'job_no')     { e.preventDefault(); rvdLookupJob(lineEl); }
            }
        });

        // Lines travel with the header form as JSON
        var form = document.getElementById('rvForm');
        if (form) {
            form.addEventListener('submit', function () {
                document.getElementById('rvdLinesJson').value = JSON.stringify(rvdCollectLines());
            });
        }

        // Initial lines: saved lines of the loaded document, or one blank line for a new one
        if (RVD_LINES.length) {
            RVD_LINES.forEach(function (l) { rvdAddLine(l, true); });
        } else if (rvdEditable) {
            rvdAddLine(null, true);
        }
        rvdRenumber();
    })();

    // EDIT — unlock the grid together with the header form
    window.erpToolbar = window.erpToolbar || {};
    (function () {
        var headerEditVerified = window.erpToolbar.onEditVerified;
        window.erpToolbar.onEditVerified = function () {
            if (typeof headerEditVerified === 'function') headerEditVerified();
            rvdSetEditable(true);
        };
    })();
</script>
