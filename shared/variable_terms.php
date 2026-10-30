<?php
/**
 * Shared Variable Terms popup — reusable across Sales Quotation, Sales Invoice, Job Card, etc.
 *
 * Include from a document header after setting:
 *   $con, $company_code, $branch_code
 *   $vt_doc_no    (string) current document number ('' for new)
 *   $vt_doc_type  (string) document type (e.g. SQN, CRS)
 *   $vt_readonly  (bool)   view mode — grid is read-only until Edit
 *
 * Optional:
 *   $vt_json_field_id  — hidden input id/name for JSON posted with the parent form
 *                        Default: variableTermsJson / variable_terms_json
 *   $vt_render_button  — if true, also outputs the trigger button (default false;
 *                        parent usually places the button in its own layout)
 *
 * Storage table: doc_h_terms (company/branch/doc_no/doc_type + line flags).
 * Process list:  process_master
 * Description templates: process_option.remark filtered by process_code
 *
 * Parent save: read #variableTermsJson (or getVariableTermsJson()) and persist,
 * or call erpVariableTermsPersist($con, ...) after the header row is saved.
 */
if (!isset($con)) {
    return;
}

require_once __DIR__ . '/../includes/erp_variable_terms.php';

$vt_doc_no         = isset($vt_doc_no) ? trim((string) $vt_doc_no) : '';
$vt_doc_type       = isset($vt_doc_type) ? strtoupper(trim((string) $vt_doc_type)) : '';
$vt_readonly       = !empty($vt_readonly);
$vt_json_field_id  = isset($vt_json_field_id) ? (string) $vt_json_field_id : 'variableTermsJson';
$vt_json_field_name = isset($vt_json_field_name) ? (string) $vt_json_field_name : 'variable_terms_json';
$vt_render_button  = !empty($vt_render_button);
$vt_company_code   = $company_code ?? ($_SESSION['company_code'] ?? '');
$vt_branch_code    = $branch_code ?? ($_SESSION['branch_code'] ?? '');

// Process master
$vt_processes = [];
try {
    $stmt_pm = $con->prepare(
        "SELECT process_code, process_name, ISNULL(set_default, 'N') AS set_default, ISNULL(sort_order, '') AS sort_order
         FROM process_master
         ORDER BY sort_order ASC, process_code ASC"
    );
    $stmt_pm->execute([]);
    while ($r = $stmt_pm->fetch(PDO::FETCH_ASSOC)) {
        $vt_processes[] = [
            'code'    => trim((string) $r['process_code']),
            'name'    => trim((string) $r['process_name']),
            'default' => strtoupper(trim((string) $r['set_default'])) === 'Y',
        ];
    }
} catch (Exception $e) {
    error_log('variable_terms process_master: ' . $e->getMessage());
}

// Process options (templates) keyed by process_code
$vt_options_by_process = [];
try {
    $stmt_po = $con->prepare(
        "SELECT doc_no, process_code, remark FROM process_option ORDER BY process_code ASC, doc_no ASC"
    );
    $stmt_po->execute([]);
    while ($r = $stmt_po->fetch(PDO::FETCH_ASSOC)) {
        $pc = trim((string) $r['process_code']);
        if ($pc === '') {
            continue;
        }
        if (!isset($vt_options_by_process[$pc])) {
            $vt_options_by_process[$pc] = [];
        }
        $remark = (string) ($r['remark'] ?? '');
        $vt_options_by_process[$pc][] = [
            'id'     => trim((string) $r['doc_no']),
            'remark' => $remark,
            'label'  => mb_strlen($remark) > 80 ? (mb_substr($remark, 0, 80) . '…') : $remark,
        ];
    }
} catch (Exception $e) {
    error_log('variable_terms process_option: ' . $e->getMessage());
}

// Existing terms for this document
$vt_existing_lines = [];
if ($vt_doc_no !== '' && $vt_doc_type !== '') {
    try {
        $stmt_vt = $con->prepare(
            "SELECT serial_no, line_no, process_code, remark,
                    line_hdr, line_bold, line_italics, line_underline, line_left, line_big
             FROM doc_h_terms
             WHERE company_code = ? AND branch_code = ? AND doc_no = ? AND doc_type = ?
             ORDER BY serial_no ASC"
        );
        $stmt_vt->execute([$vt_company_code, $vt_branch_code, $vt_doc_no, $vt_doc_type]);
        while ($r = $stmt_vt->fetch(PDO::FETCH_ASSOC)) {
            $vt_existing_lines[] = [
                'serial_no'      => trim((string) ($r['serial_no'] ?? '')),
                'line_no'        => trim((string) ($r['line_no'] ?? '')),
                'process_code'   => trim((string) ($r['process_code'] ?? '')),
                'remark'         => (string) ($r['remark'] ?? ''),
                'line_hdr'       => strtoupper(trim((string) ($r['line_hdr'] ?? 'N'))) === 'Y' ? 'Y' : 'N',
                'line_bold'      => strtoupper(trim((string) ($r['line_bold'] ?? 'N'))) === 'Y' ? 'Y' : 'N',
                'line_italics'   => strtoupper(trim((string) ($r['line_italics'] ?? 'N'))) === 'Y' ? 'Y' : 'N',
                'line_underline' => strtoupper(trim((string) ($r['line_underline'] ?? 'N'))) === 'Y' ? 'Y' : 'N',
                'line_left'      => strtoupper(trim((string) ($r['line_left'] ?? 'N'))) === 'Y' ? 'Y' : 'N',
                'line_big'       => strtoupper(trim((string) ($r['line_big'] ?? 'N'))) === 'Y' ? 'Y' : 'N',
            ];
        }
    } catch (Exception $e) {
        error_log('variable_terms load: ' . $e->getMessage());
    }
}

$vt_initial_json = json_encode($vt_existing_lines, JSON_UNESCAPED_UNICODE);
if ($vt_initial_json === false) {
    $vt_initial_json = '[]';
}
?>

<?php if ($vt_render_button): ?>
<button type="button" class="doc-more" id="vtOpenBtn" onclick="erpOpenVariableTerms()" title="Variable Terms">
    Variable Terms
</button>
<?php endif; ?>

<input type="hidden" name="<?php echo htmlspecialchars($vt_json_field_name, ENT_QUOTES, 'UTF-8'); ?>"
       id="<?php echo htmlspecialchars($vt_json_field_id, ENT_QUOTES, 'UTF-8'); ?>"
       value="<?php echo htmlspecialchars($vt_initial_json, ENT_QUOTES, 'UTF-8'); ?>">

<!-- Variable Terms Modal -->
<div id="vtModal" class="popup-overlay erp-search vt-overlay" style="display:none;">
    <div class="vt-dialog" role="dialog" aria-modal="true" aria-labelledby="vtModalTitle">
        <div class="vt-head">
            <div class="vt-head-main">
                <span class="vt-head-ico"><i class="fa fa-sliders"></i></span>
                <div>
                    <h4 class="vt-title" id="vtModalTitle">Variable Terms</h4>
                    <p class="vt-sub">Add process terms and formatting for this document</p>
                </div>
            </div>
            <button type="button" class="vt-close" onclick="erpCloseVariableTerms(false)" title="Close" aria-label="Close"><i class="fa fa-times"></i></button>
        </div>
        <div class="vt-body">
            <div class="vt-grid-wrap">
                <table class="vt-table" id="vtTable">
                    <thead>
                        <tr>
                            <th class="vt-col-srl">Srl</th>
                            <th class="vt-col-line">Line</th>
                            <th class="vt-col-process">Process</th>
                            <th class="vt-col-desc">Description</th>
                            <th class="vt-col-flag" title="Header style">H</th>
                            <th class="vt-col-flag" title="Bold">B</th>
                            <th class="vt-col-flag" title="Italic">I</th>
                            <th class="vt-col-flag" title="Underline">U</th>
                        </tr>
                    </thead>
                    <tbody id="vtTableBody"></tbody>
                </table>
                <div class="vt-empty" id="vtEmptyHint" style="display:none;">
                    <i class="fa fa-file-text-o"></i>
                    <p>No terms yet. Click <strong>Add</strong> or <strong>Set</strong> to start.</p>
                </div>
            </div>
            <aside class="vt-sidebar">
                <div class="vt-side-group">
                    <button type="button" class="vt-side-btn" id="vtBtnAdd" onclick="vtAddRow()"><i class="fa fa-plus"></i><span>Add</span></button>
                    <button type="button" class="vt-side-btn" id="vtBtnInsert" onclick="vtInsertRow()"><i class="fa fa-indent"></i><span>Insert</span></button>
                    <button type="button" class="vt-side-btn primary" id="vtBtnSave" onclick="erpCloseVariableTerms(true)"><i class="fa fa-check"></i><span>Save</span></button>
                    <button type="button" class="vt-side-btn danger" id="vtBtnDelete" onclick="vtDeleteRow()"><i class="fa fa-trash-o"></i><span>Delete</span></button>
                </div>
                <div class="vt-side-group">
                    <button type="button" class="vt-side-btn" id="vtBtnSet" onclick="vtSetDefaults()" title="Load all process defaults"><i class="fa fa-magic"></i><span>Set</span></button>
                    <button type="button" class="vt-side-btn ghost" onclick="erpCloseVariableTerms(false)"><i class="fa fa-times"></i><span>Close</span></button>
                </div>
            </aside>
        </div>
    </div>
</div>

<!-- Description More editor -->
<div id="vtDescModal" class="popup-overlay erp-search vt-overlay" style="display:none;">
    <div class="vt-dialog vt-dialog-sm" role="dialog" aria-modal="true">
        <div class="vt-head">
            <div class="vt-head-main">
                <span class="vt-head-ico"><i class="fa fa-align-left"></i></span>
                <div>
                    <h4 class="vt-title">Description</h4>
                    <p class="vt-sub">Edit the full term text</p>
                </div>
            </div>
            <button type="button" class="vt-close" onclick="vtCloseDescMore()" title="Close" aria-label="Close"><i class="fa fa-times"></i></button>
        </div>
        <div class="vt-desc-body">
            <textarea id="vtDescText" class="vt-textarea" rows="9" placeholder="Enter description..."></textarea>
        </div>
        <div class="vt-foot">
            <button type="button" class="vt-foot-btn" onclick="vtCloseDescMore()">Close</button>
        </div>
    </div>
</div>

<style>
.vt-overlay {
    z-index: 12000;
    background: rgba(15, 23, 42, .45);
    backdrop-filter: blur(2px);
}
.vt-dialog {
    width: min(1080px, 96vw);
    max-width: 1080px;
    height: min(560px, 88vh);
    display: flex;
    flex-direction: column;
    background: var(--surface, #fff);
    border: 1px solid var(--border, #e2e8f0);
    border-radius: 14px;
    box-shadow: 0 20px 50px rgba(15, 23, 42, .22);
    overflow: hidden;
}
.vt-dialog-sm {
    width: min(520px, 94vw);
    height: auto;
    max-height: 85vh;
}
.vt-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 14px 16px;
    border-bottom: 1px solid var(--border, #e2e8f0);
    background: linear-gradient(180deg, #fff 0%, #f8fafc 100%);
}
.vt-head-main {
    display: flex;
    align-items: center;
    gap: 12px;
    min-width: 0;
}
.vt-head-ico {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: var(--primary-ring, rgba(74,108,212,.18));
    color: var(--primary, #4a6cd4);
    font-size: 15px;
    flex-shrink: 0;
}
.vt-title {
    margin: 0;
    font-size: 1.05rem;
    font-weight: 700;
    color: var(--text, #0f172a);
    line-height: 1.2;
}
.vt-sub {
    margin: 2px 0 0;
    font-size: 0.78rem;
    color: var(--text-3, #94a3b8);
}
.vt-close {
    width: 32px;
    height: 32px;
    border: none;
    border-radius: 8px;
    background: transparent;
    color: var(--text-2, #475569);
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}
.vt-close:hover { background: var(--primary-soft, #f1f3f5); color: var(--text, #0f172a); }

.vt-body {
    display: flex;
    flex: 1;
    min-height: 0;
    background: var(--bg, #f8fafc);
}
.vt-grid-wrap {
    position: relative;
    flex: 1;
    min-width: 0;
    overflow: auto;
    padding: 12px 14px;
}
.vt-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    background: var(--surface, #fff);
    font-size: 0.84rem;
    border: 1px solid var(--border, #e2e8f0);
    border-radius: 10px;
    overflow: hidden;
}
.vt-table th {
    position: sticky;
    top: 0;
    z-index: 1;
    background: #f1f5f9;
    color: var(--text-2, #475569);
    font-weight: 650;
    font-size: 0.72rem;
    letter-spacing: .04em;
    text-transform: uppercase;
    padding: 10px 8px;
    border-bottom: 1px solid var(--border, #e2e8f0);
    text-align: center;
    white-space: nowrap;
}
.vt-table th.vt-col-desc { text-align: left; padding-left: 12px; }
.vt-table td {
    padding: 6px;
    vertical-align: middle;
    border-bottom: 1px solid #eef2f7;
    background: #fff;
}
.vt-table tbody tr:last-child td { border-bottom: none; }
.vt-table tbody tr:hover td { background: #f8fafc; }
.vt-table tr.vt-active td { background: #eef2ff; }
.vt-table tr.vt-active td:first-child {
    box-shadow: inset 3px 0 0 var(--primary, #4a6cd4);
}
.vt-col-srl { width: 48px; }
.vt-col-line { width: 56px; }
.vt-col-process { width: 168px; }
.vt-col-flag { width: 36px; }
.vt-srl {
    text-align: center;
    font-weight: 650;
    color: var(--text-3, #94a3b8);
    font-variant-numeric: tabular-nums;
}
.vt-table .vt-inp,
.vt-table .vt-sel {
    width: 100%;
    border: 1px solid var(--border, #e2e8f0);
    border-radius: 7px;
    padding: 6px 8px;
    font-size: 0.84rem;
    color: var(--text, #0f172a);
    background: #fff;
    min-height: 34px;
    outline: none;
    transition: border-color .15s, box-shadow .15s, font-weight .1s, font-style .1s, text-decoration .1s;
}
.vt-table .vt-inp:focus,
.vt-table .vt-sel:focus {
    border-color: var(--primary, #4a6cd4);
    box-shadow: 0 0 0 3px var(--primary-ring, rgba(74,108,212,.18));
}
.vt-table .vt-line { text-align: center; }
.vt-desc-cell { display: flex; align-items: center; gap: 6px; }
.vt-desc-cell .vt-sel { flex: 1; min-width: 0; }
.vt-desc-cell .vt-sel.vt-fs-hdr { font-size: 0.95rem; font-weight: 700; }
.vt-desc-cell .vt-sel.vt-fs-bold { font-weight: 700; }
.vt-desc-cell .vt-sel.vt-fs-italic { font-style: italic; }
.vt-desc-cell .vt-sel.vt-fs-underline { text-decoration: underline; }
.vt-desc-cell .vt-more {
    flex-shrink: 0;
    border: 1px solid var(--border, #e2e8f0);
    background: #fff;
    color: var(--primary, #4a6cd4);
    font-weight: 650;
    font-size: 0.75rem;
    cursor: pointer;
    padding: 6px 10px;
    border-radius: 7px;
    white-space: nowrap;
}
.vt-desc-cell .vt-more:hover {
    background: var(--primary-soft, #f1f3f5);
    border-color: #c7d2fe;
}
.vt-style-btn {
    width: 28px;
    height: 28px;
    margin: 0 auto;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 1px solid var(--border, #e2e8f0);
    border-radius: 6px;
    background: #fff;
    color: var(--text-2, #475569);
    font-size: 0.78rem;
    font-weight: 700;
    cursor: pointer;
    padding: 0;
    line-height: 1;
}
.vt-style-btn[data-f="line_italics"] { font-style: italic; font-family: Georgia, serif; }
.vt-style-btn[data-f="line_underline"] { text-decoration: underline; }
.vt-style-btn:hover { border-color: #c7d2fe; background: var(--primary-soft, #f1f3f5); }
.vt-style-btn.on {
    background: var(--primary, #4a6cd4);
    border-color: var(--primary, #4a6cd4);
    color: #fff;
    box-shadow: 0 1px 4px rgba(74, 108, 212, .28);
}
.vt-style-btn:disabled {
    opacity: .45;
    cursor: default;
}

.vt-empty {
    position: absolute;
    inset: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 8px;
    color: var(--text-3, #94a3b8);
    pointer-events: none;
}
.vt-empty i { font-size: 1.6rem; opacity: .7; }
.vt-empty p { margin: 0; font-size: 0.9rem; }

.vt-sidebar {
    width: 128px;
    flex-shrink: 0;
    background: #fff;
    border-left: 1px solid var(--border, #e2e8f0);
    padding: 12px 10px;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    gap: 16px;
}
.vt-side-group {
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.vt-side-btn {
    display: inline-flex;
    align-items: center;
    justify-content: flex-start;
    gap: 8px;
    width: 100%;
    background: #fff;
    border: 1px solid var(--border, #e2e8f0);
    border-radius: 9px;
    padding: 9px 10px;
    font-size: 0.82rem;
    font-weight: 650;
    color: var(--text, #0f172a);
    cursor: pointer;
    transition: background .15s, border-color .15s, color .15s, box-shadow .15s;
}
.vt-side-btn i {
    width: 14px;
    text-align: center;
    color: var(--text-2, #475569);
}
.vt-side-btn:hover {
    background: var(--primary-soft, #f1f3f5);
    border-color: #c7d2fe;
}
.vt-side-btn.primary {
    background: var(--primary, #4a6cd4);
    border-color: var(--primary, #4a6cd4);
    color: #fff;
    box-shadow: 0 4px 10px rgba(74, 108, 212, .25);
}
.vt-side-btn.primary i { color: #fff; }
.vt-side-btn.primary:hover {
    background: var(--primary-hover, #3a59b8);
    border-color: var(--primary-hover, #3a59b8);
}
.vt-side-btn.danger { color: var(--danger, #cc2c2a); }
.vt-side-btn.danger i { color: var(--danger, #cc2c2a); }
.vt-side-btn.danger:hover {
    background: var(--danger-soft, #fdeceb);
    border-color: #fecaca;
}
.vt-side-btn.ghost { color: var(--text-2, #475569); }
.vt-side-btn:disabled {
    opacity: .4;
    cursor: default;
    box-shadow: none;
}
.vt-side-btn:disabled:hover {
    background: #fff;
    border-color: var(--border, #e2e8f0);
}

.vt-desc-body { padding: 14px 16px; background: var(--bg, #f8fafc); }
.vt-textarea {
    width: 100%;
    min-height: 180px;
    resize: vertical;
    border: 1px solid var(--border, #e2e8f0);
    border-radius: 10px;
    padding: 12px 14px;
    font-size: 0.9rem;
    line-height: 1.45;
    color: var(--text, #0f172a);
    background: #fff;
    outline: none;
}
.vt-textarea:focus {
    border-color: var(--primary, #4a6cd4);
    box-shadow: 0 0 0 3px var(--primary-ring, rgba(74,108,212,.18));
}
.vt-foot {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    padding: 12px 16px;
    border-top: 1px solid var(--border, #e2e8f0);
    background: #fff;
}
.vt-foot-btn {
    border: 1px solid var(--border, #e2e8f0);
    background: #fff;
    color: var(--text, #0f172a);
    border-radius: 8px;
    padding: 8px 14px;
    font-size: 0.85rem;
    font-weight: 650;
    cursor: pointer;
}
.vt-foot-btn:hover { background: var(--primary-soft, #f1f3f5); }
.vt-foot-btn.primary {
    background: var(--primary, #4a6cd4);
    border-color: var(--primary, #4a6cd4);
    color: #fff;
}
.vt-foot-btn.primary:hover { background: var(--primary-hover, #3a59b8); }

@media (max-width: 760px) {
    .vt-dialog { height: min(92vh, 720px); }
    .vt-body { flex-direction: column; }
    .vt-sidebar {
        width: 100%;
        border-left: none;
        border-top: 1px solid var(--border, #e2e8f0);
        flex-direction: row;
        justify-content: stretch;
        padding: 10px;
    }
    .vt-side-group {
        flex: 1;
        flex-direction: row;
        flex-wrap: wrap;
    }
    .vt-side-btn { flex: 1 1 calc(50% - 4px); justify-content: center; }
}
</style>

<script>
(function () {
    var VT_PROCESSES = <?php echo json_encode($vt_processes, JSON_UNESCAPED_UNICODE); ?>;
    var VT_OPTIONS   = <?php echo json_encode($vt_options_by_process, JSON_UNESCAPED_UNICODE); ?>;
    var VT_JSON_ID   = <?php echo json_encode($vt_json_field_id); ?>;
    var VT_READONLY  = <?php echo $vt_readonly ? 'true' : 'false'; ?>;
    var vtActiveRow  = null;
    var vtDescRow    = null;
    var vtDraftLines = null;

    function vtEsc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;')
            .replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function vtProcessOptionsHtml(selected) {
        var html = '<option value="">-- Select --</option>';
        (VT_PROCESSES || []).forEach(function (p) {
            html += '<option value="' + vtEsc(p.code) + '"' + (p.code === selected ? ' selected' : '') + '>'
                + vtEsc(p.name) + '</option>';
        });
        return html;
    }

    function vtDescOptionsHtml(processCode, selectedRemark) {
        var opts = (VT_OPTIONS && VT_OPTIONS[processCode]) ? VT_OPTIONS[processCode] : [];
        var html = '<option value="">-- Select --</option>';
        var matched = false;
        opts.forEach(function (o) {
            var sel = (o.remark === selectedRemark);
            if (sel) matched = true;
            html += '<option value="' + vtEsc(o.remark) + '"' + (sel ? ' selected' : '') + '>'
                + vtEsc(o.label || o.remark) + '</option>';
        });
        // Keep a custom / saved remark that is not in the template list
        if (selectedRemark && !matched) {
            var lab = selectedRemark.length > 80 ? selectedRemark.slice(0, 80) + '…' : selectedRemark;
            html += '<option value="' + vtEsc(selectedRemark) + '" selected>' + vtEsc(lab) + '</option>';
        }
        return html;
    }

    function vtStyleBtn(name, label, val, disabled) {
        return '<button type="button" class="vt-style-btn' + (val === 'Y' ? ' on' : '') + '" data-f="' + name + '"'
            + ' data-on="' + (val === 'Y' ? 'Y' : 'N') + '"'
            + (disabled ? ' disabled' : '')
            + ' onclick="vtToggleStyle(this)" title="' + vtEsc(label) + '">' + vtEsc(label) + '</button>';
    }

    function vtApplyDescStyles(tr) {
        if (!tr) return;
        var sel = tr.querySelector('select[data-f="remark"]');
        if (!sel) return;
        function on(f) {
            var btn = tr.querySelector('.vt-style-btn[data-f="' + f + '"]');
            return btn && btn.getAttribute('data-on') === 'Y';
        }
        sel.classList.toggle('vt-fs-hdr', on('line_hdr'));
        sel.classList.toggle('vt-fs-bold', on('line_bold'));
        sel.classList.toggle('vt-fs-italic', on('line_italics'));
        sel.classList.toggle('vt-fs-underline', on('line_underline'));
    }

    window.vtToggleStyle = function (btn) {
        if (!btn || btn.disabled) return;
        var next = btn.getAttribute('data-on') === 'Y' ? 'N' : 'Y';
        btn.setAttribute('data-on', next);
        btn.classList.toggle('on', next === 'Y');
        vtApplyDescStyles(btn.closest('tr'));
    };

    function vtRowHtml(line, readonly) {
        line = line || {};
        var process = line.process_code || '';
        var remark  = line.remark || '';
        return ''
            + '<td class="vt-srl"></td>'
            + '<td><input type="text" class="vt-inp vt-line" data-f="line_no" value="' + vtEsc(line.line_no || '') + '"' + (readonly ? ' readonly' : '') + '></td>'
            + '<td><select class="vt-sel" data-f="process_code"' + (readonly ? ' disabled' : '') + '>'
            +     vtProcessOptionsHtml(process) + '</select></td>'
            + '<td><div class="vt-desc-cell">'
            +     '<select class="vt-sel" data-f="remark"' + (readonly ? ' disabled' : '') + '>'
            +         vtDescOptionsHtml(process, remark) + '</select>'
            +     '<button type="button" class="vt-more" tabindex="-1" onclick="vtOpenDescMore(this)">More...</button>'
            + '</div></td>'
            + '<td>' + vtStyleBtn('line_hdr', 'H', line.line_hdr || 'N', readonly) + '</td>'
            + '<td>' + vtStyleBtn('line_bold', 'B', line.line_bold || 'N', readonly) + '</td>'
            + '<td>' + vtStyleBtn('line_italics', 'I', line.line_italics || 'N', readonly) + '</td>'
            + '<td>' + vtStyleBtn('line_underline', 'U', line.line_underline || 'N', readonly) + '</td>';
    }

    function vtReadJsonField() {
        var el = document.getElementById(VT_JSON_ID);
        if (!el || !el.value) return [];
        try {
            var arr = JSON.parse(el.value);
            return Array.isArray(arr) ? arr : [];
        } catch (e) { return []; }
    }

    function vtCollectLines() {
        var rows = document.querySelectorAll('#vtTableBody tr');
        var lines = [];
        rows.forEach(function (tr) {
            function val(f) {
                var el = tr.querySelector('[data-f="' + f + '"]');
                if (!el) return '';
                if (el.classList && el.classList.contains('vt-style-btn')) {
                    return el.getAttribute('data-on') === 'Y' ? 'Y' : 'N';
                }
                return el.value;
            }
            lines.push({
                line_no:        val('line_no'),
                process_code:   val('process_code'),
                remark:         val('remark'),
                line_hdr:       val('line_hdr'),
                line_bold:      val('line_bold'),
                line_italics:   val('line_italics'),
                line_underline: val('line_underline'),
                line_left:      'N',
                line_big:       'N'
            });
        });
        return lines;
    }

    function vtWriteJsonField(lines) {
        var el = document.getElementById(VT_JSON_ID);
        if (el) el.value = JSON.stringify(lines || []);
    }

    function vtRenumber() {
        var rows = document.querySelectorAll('#vtTableBody tr');
        rows.forEach(function (tr, i) {
            var srl = tr.querySelector('.vt-srl');
            if (srl) srl.textContent = String(i + 1).padStart(3, '0');
        });
        var hint = document.getElementById('vtEmptyHint');
        if (hint) hint.style.display = rows.length ? 'none' : 'flex';
    }

    function vtSetActive(tr) {
        document.querySelectorAll('#vtTableBody tr.vt-active').forEach(function (r) {
            r.classList.remove('vt-active');
        });
        vtActiveRow = tr || null;
        if (tr) tr.classList.add('vt-active');
    }

    function vtAppendRow(line, silent) {
        var tbody = document.getElementById('vtTableBody');
        var tr = document.createElement('tr');
        tr.innerHTML = vtRowHtml(line, VT_READONLY);
        tbody.appendChild(tr);
        vtApplyDescStyles(tr);
        vtRenumber();
        if (!silent) vtSetActive(tr);
        return tr;
    }

    function vtRender(lines) {
        var tbody = document.getElementById('vtTableBody');
        tbody.innerHTML = '';
        vtActiveRow = null;
        (lines || []).forEach(function (line) { vtAppendRow(line, true); });
        if (!lines || !lines.length) {
            // empty grid — ready for Add
        } else {
            var first = tbody.querySelector('tr');
            if (first) vtSetActive(first);
        }
        vtRenumber();
        vtApplyReadonly();
    }

    function vtApplyReadonly() {
        var disabled = !!VT_READONLY;
        ['vtBtnAdd', 'vtBtnInsert', 'vtBtnDelete', 'vtBtnSet'].forEach(function (id) {
            var btn = document.getElementById(id);
            if (btn) btn.disabled = disabled;
        });
        // Save always available: persists edits when editable, otherwise just closes
        var saveBtn = document.getElementById('vtBtnSave');
        if (saveBtn) saveBtn.disabled = false;
        document.querySelectorAll('#vtTableBody select, #vtTableBody input').forEach(function (el) {
            if (el.tagName === 'SELECT') {
                el.disabled = disabled;
            } else if (disabled) {
                el.setAttribute('readonly', '');
            } else {
                el.removeAttribute('readonly');
            }
        });
        document.querySelectorAll('#vtTableBody .vt-style-btn').forEach(function (btn) {
            btn.disabled = disabled;
        });
    }

    window.erpSetVariableTermsReadonly = function (ro) {
        VT_READONLY = !!ro;
        vtApplyReadonly();
    };

    window.getVariableTermsJson = function () {
        // Prefer live grid if open; else hidden field
        var modal = document.getElementById('vtModal');
        if (modal && modal.style.display === 'flex') {
            return JSON.stringify(vtCollectLines());
        }
        var el = document.getElementById(VT_JSON_ID);
        return el ? (el.value || '[]') : '[]';
    };

    window.erpOpenVariableTerms = function () {
        vtDraftLines = vtReadJsonField();
        vtRender(JSON.parse(JSON.stringify(vtDraftLines)));
        document.getElementById('vtModal').style.display = 'flex';
        document.body.style.overflow = 'hidden';
    };

    window.erpCloseVariableTerms = function (save) {
        if (save && !VT_READONLY) {
            vtWriteJsonField(vtCollectLines());
        }
        document.getElementById('vtModal').style.display = 'none';
        document.body.style.overflow = '';
        vtActiveRow = null;
        vtDraftLines = null;
    };

    window.vtAddRow = function () {
        if (VT_READONLY) return;
        var tr = vtAppendRow({
            line_no: '', process_code: '', remark: '',
            line_hdr: 'N', line_bold: 'N', line_italics: 'N',
            line_underline: 'N', line_left: 'N', line_big: 'N'
        });
        vtSetActive(tr);
    };

    window.vtInsertRow = function () {
        if (VT_READONLY) return;
        var tbody = document.getElementById('vtTableBody');
        var tr = document.createElement('tr');
        tr.innerHTML = vtRowHtml({}, false);
        if (vtActiveRow && vtActiveRow.parentNode === tbody) {
            tbody.insertBefore(tr, vtActiveRow);
        } else {
            tbody.appendChild(tr);
        }
        vtApplyDescStyles(tr);
        vtRenumber();
        vtSetActive(tr);
    };

    window.vtDeleteRow = function () {
        if (VT_READONLY) return;
        if (!vtActiveRow) {
            alert('Select a line to delete.');
            return;
        }
        if (!confirm('Delete the selected Variable Terms line?')) return;
        var next = vtActiveRow.nextElementSibling || vtActiveRow.previousElementSibling;
        vtActiveRow.remove();
        vtActiveRow = null;
        vtRenumber();
        if (next) vtSetActive(next);
    };

    window.vtSetDefaults = function () {
        if (VT_READONLY) return;
        var existing = vtCollectLines().map(function (l) { return l.process_code; });
        (VT_PROCESSES || []).forEach(function (p) {
            if (existing.indexOf(p.code) >= 0) return;
            var opts = (VT_OPTIONS && VT_OPTIONS[p.code]) ? VT_OPTIONS[p.code] : [];
            var remark = opts.length ? opts[0].remark : '';
            vtAppendRow({
                line_no: '', process_code: p.code, remark: remark,
                line_hdr: 'N', line_bold: 'N', line_italics: 'N',
                line_underline: 'N', line_left: 'N', line_big: 'N'
            }, true);
            existing.push(p.code);
        });
        vtRenumber();
        var last = document.querySelector('#vtTableBody tr:last-child');
        if (last) vtSetActive(last);
    };

    window.vtOpenDescMore = function (btn) {
        var tr = btn.closest('tr');
        vtDescRow = tr;
        var sel = tr.querySelector('[data-f="remark"]');
        var ta = document.getElementById('vtDescText');
        ta.value = sel ? sel.value : '';
        ta.readOnly = VT_READONLY;
        document.getElementById('vtDescModal').style.display = 'flex';
        ta.focus();
    };

    window.vtCloseDescMore = function () {
        // Apply description to the row; Variable Terms Save persists to DB
        if (vtDescRow && !VT_READONLY) {
            var sel = vtDescRow.querySelector('[data-f="remark"]');
            var text = document.getElementById('vtDescText').value;
            if (sel) {
                var found = false;
                Array.prototype.forEach.call(sel.options, function (opt) {
                    if (opt.value === text) found = true;
                });
                if (!found) {
                    var opt = document.createElement('option');
                    opt.value = text;
                    opt.textContent = text.length > 80 ? text.slice(0, 80) + '…' : text;
                    sel.appendChild(opt);
                }
                sel.value = text;
            }
        }
        document.getElementById('vtDescModal').style.display = 'none';
        vtDescRow = null;
    };

    // Row click + process change → refresh description options
    document.addEventListener('click', function (e) {
        var tr = e.target.closest('#vtTableBody tr');
        if (tr) vtSetActive(tr);
    });
    document.addEventListener('change', function (e) {
        if (!e.target.matches('#vtTableBody select[data-f="process_code"]')) return;
        var tr = e.target.closest('tr');
        var code = e.target.value;
        var descSel = tr.querySelector('select[data-f="remark"]');
        if (!descSel) return;
        var opts = (VT_OPTIONS && VT_OPTIONS[code]) ? VT_OPTIONS[code] : [];
        var firstRemark = opts.length ? opts[0].remark : '';
        descSel.innerHTML = vtDescOptionsHtml(code, firstRemark);
        descSel.value = firstRemark;
    });
})();
</script>
