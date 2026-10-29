<?php
/**
 * Shared ERP Toolbar
 *
 * Include this file from any module CONTAINER (e.g. modules/invoice/invoice.php).
 *
 * PHP variables to set BEFORE including this file:
 *   $is_view_mode     (bool)   - true = viewing saved doc → SAVE disabled
 *   $close_url        (string) - URL for the CLOSE button. Default: dashboard in iframe shell
 *   $user_allow_edit  (string) - 'Y' enables the EDIT button; anything else disables it
 *   $user_allow_delete(string) - 'Y' enables the DELETE button; anything else disables it
 *   $prev_doc_no      (string) - doc_no of the previous document (enables PRIOR button)
 *   $next_doc_no      (string) - doc_no of the next document (NEXT navigates there, or NEW if empty)
 *   $selected_doc_type(string) - current doc type code (used by NEXT to open new doc of same type)
 *   $doc_no           (string) - current document number
 *   $search_doc_url   (string) - path to search_document.php relative to the page URL
 *                                Default: ../../search_document.php
 *   $search_ref_label (string) - FIND label for the reference field (search type "tracking_no")
 *                                Default: Tracking No
 *
 * JS callbacks to override AFTER this file loads (in your header/detail fragment):
 *   window.erpToolbar.onNew          - NEW button
 *   window.erpToolbar.onSave         - SAVE button
 *   window.erpToolbar.onDelete       - DELETE button
 *   window.erpToolbar.onPreview      - PREVIEW button
 *   window.erpToolbar.onPrint        - PRINT button
 *   window.erpToolbar.onDocSelect(doc_no) - called when user picks a doc in FIND modal
 *   window.erpToolbar.onEditVerified - called after edit password is verified successfully
 *
 * PRIOR / NEXT / FIND / EDIT are fully handled by this toolbar (no override needed).
 * Set the PHP vars above to control their behaviour.
 */
require_once __DIR__ . '/../includes/erp_paths.php';

$is_view_mode      = $is_view_mode      ?? false;
$close_url         = $close_url         ?? erp_iframe_url('dashboard');
$shell_home_url    = erp_shell_url();
$user_allow_edit   = $user_allow_edit   ?? 'Y';
$user_allow_delete = $user_allow_delete ?? 'Y';
$prev_doc_no       = $prev_doc_no       ?? '';
$next_doc_no       = $next_doc_no       ?? '';
$selected_doc_type = $selected_doc_type ?? '';
$doc_no            = $doc_no            ?? '';
$search_doc_url    = $search_doc_url    ?? '../../search_document';
$search_ref_label  = $search_ref_label  ?? 'Tracking No';
$save_label        = $save_label        ?? 'Save';
$tb_close_attr     = htmlspecialchars($close_url, ENT_QUOTES, 'UTF-8');
?>
<!-- ══════════════════ SHARED TOOLBAR (action bar) ══════════════════
     Frequent actions are buttons; the rest sit in the More menu. Every action keeps its
     id (tbSave, tbPrior …) so erpToolbarSetState() and the fragments' enable/disable code still apply. -->
<div class="toolbar" id="erpToolbar">
    <button type="button" class="toolbar-btn tb-back" id="tbClose" title="Close and go back" aria-label="Back"
            onclick="erpToolbarGoHome('<?php echo $tb_close_attr; ?>')">
        <i class="fa fa-arrow-left"></i>
    </button>

    <!-- filled from the page's .doc-titlebar by erpToolbarAdoptTitlebar() -->
    <div class="tb-identity" id="tbIdentity"></div>

    <span class="tb-fill"></span>

    <div class="tb-group tb-nav">
        <button type="button" class="toolbar-btn" id="tbNew" title="New document"
                onclick="erpToolbarAction('new')">
            <i class="fa fa-file-o"></i><span class="tb-lbl">New</span>
        </button>
        <button type="button" class="toolbar-btn" id="tbFind" title="Find a document"
                onclick="erpToolbarAction('find')">
            <i class="fa fa-search"></i><span class="tb-lbl">Find</span>
        </button>
        <button type="button" class="toolbar-btn" id="tbEdit"
                onclick="erpToolbarAction('edit')"
                <?php echo ($user_allow_edit !== 'Y') ? 'disabled title="Edit not allowed for your account"' : 'title="Edit document"'; ?>>
            <i class="fa fa-pencil"></i><span class="tb-lbl">Edit</span>
        </button>
        <button type="button" class="toolbar-btn" id="tbPrior" title="Previous document"
                onclick="erpToolbarAction('prior')"
                <?php echo empty($prev_doc_no) ? 'disabled' : ''; ?>>
            <i class="fa fa-angle-left"></i><span class="tb-lbl">Previous</span>
        </button>
        <button type="button" class="toolbar-btn" id="tbNext" title="Next document"
                onclick="erpToolbarAction('next')">
            <span class="tb-lbl">Next</span><i class="fa fa-angle-right"></i>
        </button>
    </div>

    <span class="tb-sep"></span>

    <div class="tb-group">
        <button type="button" class="toolbar-btn" id="tbPreview" title="Preview (available after saving)"
                onclick="erpToolbarAction('preview')"
                <?php echo (!$is_view_mode || empty($doc_no)) ? 'disabled' : ''; ?>>
            <i class="fa fa-eye"></i><span class="tb-lbl">Preview</span>
        </button>
        <button type="button" class="toolbar-btn" id="tbPrint" title="Print"
                onclick="erpToolbarAction('print')">
            <i class="fa fa-print"></i><span class="tb-lbl">Print</span>
        </button>

        <div class="tb-more">
            <button type="button" class="toolbar-btn" id="tbMoreBtn" title="More actions"
                    aria-haspopup="menu" aria-expanded="false" aria-controls="tbMoreMenu"
                    onclick="erpToolbarToggleMore(event)">
                <i class="fa fa-ellipsis-h"></i><span class="tb-lbl">More</span>
            </button>
            <div class="tb-menu" id="tbMoreMenu" role="menu" aria-labelledby="tbMoreBtn">
                <div class="tb-menu-label">Document</div>
                <button type="button" role="menuitem" id="tbSelect" onclick="erpToolbarAction('select')"><i class="fa fa-check-square-o"></i>Select</button>
                <button type="button" role="menuitem" id="tbAttach" onclick="erpToolbarAction('attach')"><i class="fa fa-paperclip"></i>Attach files</button>
                <hr>
                <div class="tb-menu-label">Navigate</div>
                <button type="button" role="menuitem" id="tbList" onclick="erpToolbarAction('list')"><i class="fa fa-list"></i>Document list</button>
                <button type="button" role="menuitem" id="tbHome" onclick="erpToolbarGoHome('<?php echo $tb_close_attr; ?>')"><i class="fa fa-home"></i>Home</button>
                <hr>
                <div class="tb-menu-label">View</div>
                <button type="button" role="menuitem" id="densityBtn" title="Toggle compact / comfortable density"
                        onclick="erpToolbarSetMore(false); if (typeof toggleDensity === 'function') toggleDensity();"><i class="fa fa-compress"></i><span>Compact</span></button>
                <hr>
                <div class="tb-menu-label">Workflow</div>
                <button type="button" role="menuitem" id="tbForward" onclick="erpToolbarAction('forward')"><i class="fa fa-share"></i>Forward for approval</button>
                <button type="button" role="menuitem" id="tbStatus" onclick="erpToolbarAction('status')"><i class="fa fa-check-circle-o"></i>Approval status</button>
                <button type="button" role="menuitem" id="tbRevision" onclick="erpToolbarAction('revision')"><i class="fa fa-history"></i>Revision history</button>
                <button type="button" role="menuitem" id="tbReport" onclick="erpToolbarAction('report')"><i class="fa fa-file-excel-o"></i>Export report</button>
                <hr>
                <button type="button" role="menuitem" class="tb-danger" id="tbCancel" onclick="erpToolbarAction('cancel')"><i class="fa fa-ban"></i>Cancel</button>
                <button type="button" role="menuitem" class="tb-danger" id="tbDelete" onclick="erpToolbarAction('delete')"
                        <?php echo ($user_allow_delete !== 'Y') ? 'disabled title="Delete not allowed for your account"' : 'title="Delete document"'; ?>><i class="fa fa-trash-o"></i>Delete</button>
            </div>
        </div>

        <button type="button" class="toolbar-btn tb-primary" id="tbSave" title="Save document"
                onclick="erpToolbarAction('save')"
                <?php echo $is_view_mode ? 'disabled' : ''; ?>>
            <i class="fa fa-check"></i><span class="tb-lbl"><?php echo htmlspecialchars($save_label, ENT_QUOTES, 'UTF-8'); ?></span>
        </button>
    </div>
</div>

<!-- ══════════════════ FIND — Document Search Modal ══════════════════ -->
<link rel="stylesheet" href="<?php echo htmlspecialchars(erp_asset_v('assets/css/erp_search.css'), ENT_QUOTES, 'UTF-8'); ?>">

<!-- ══════════════════ PREVIEW — print preview window (erpOpenPrintPreview) ══════════════════ -->
<div id="erpPrintPreview" class="popup-overlay erp-search erp-search-preview" style="display: none;">
    <div class="erp-search-dialog preview" role="dialog" aria-modal="true" aria-label="Print preview">
        <iframe id="erpPrintPreviewFrame" title="Print preview" src="about:blank"></iframe>
    </div>
</div>
<div id="erpDocSearchModal" class="popup-overlay erp-search" style="display: none;">
    <div class="erp-search-dialog" role="dialog" aria-modal="true" aria-labelledby="erpFindTitle">
        <div class="erp-search-head">
            <h4 class="erp-search-title" id="erpFindTitle"><i class="fa fa-search"></i>Find Document</h4>
            <button type="button" class="erp-search-close" onclick="erpCloseDocSearch()" title="Close" aria-label="Close">
                <i class="fa fa-times"></i>
            </button>
        </div>
        <div class="erp-search-bar">
            <div class="erp-search-input">
                <i class="fa fa-search"></i>
                <input type="text" id="erpDocSearchInput" autocomplete="off"
                       placeholder="Search by Doc No, <?php echo htmlspecialchars($search_ref_label, ENT_QUOTES, 'UTF-8'); ?> or Job No"
                       oninput="erpSearchDocs(this.value)"
                       onkeydown="if (event.key === 'Enter') { event.preventDefault(); erpSearchDocsNow(); }">
            </div>
            <button type="button" class="erp-search-btn" onclick="erpResetDocSearch()">Reset</button>
        </div>
        <div class="erp-search-meta">
            <div>
                <div class="erp-search-meta-title">Search results</div>
                <div class="erp-search-meta-sub" id="erpFindDocName"></div>
            </div>
            <span class="erp-search-count" id="erpFindCount"></span>
        </div>
        <div class="erp-search-list" id="erpDocSearchResults"></div>
    </div>
</div>

<!-- ══════════════════ EDIT — Password Verification Modal ══════════════════ -->
<div id="erpEditPasswordModal" class="popup-overlay" style="z-index: 1050; display: none;">
    <div class="popup-content" style="max-width:400px; padding:25px;">
        <h4 style="margin-top:0; margin-bottom:15px; font-weight:600;">Enter Edit Password</h4>
        <input type="password" id="erpEditPasswordInput" class="form-control"
               placeholder="Password" style="margin-bottom:20px;"
               onkeydown="if(event.key==='Enter') erpVerifyEditPassword()">
        <div style="text-align:right; display:flex; justify-content:flex-end; gap:10px;">
            <button type="button" class="btn btn-outline-secondary" onclick="erpCloseEditPassword()">Cancel</button>
            <button type="button" class="btn btn-primary" onclick="erpVerifyEditPassword()">Verify</button>
        </div>
    </div>
</div>

<!-- ══════════════════ LEAVE — Unsaved changes warning ══════════════════ -->
<div id="erpLeaveWarnModal" class="popup-overlay erp-search erp-leave-warn" style="display: none; z-index: 1200;">
    <div class="erp-search-dialog sm erp-leave-dialog" role="alertdialog" aria-modal="true" aria-labelledby="erpLeaveTitle" aria-describedby="erpLeaveMsg">
        <div class="erp-leave-icon" aria-hidden="true"><i class="fa fa-exclamation-circle"></i></div>
        <div class="erp-leave-body">
            <h4 id="erpLeaveTitle">INFOS</h4>
            <p id="erpLeaveMsg">You have Un-updated Records..<br>Do you want to Continue.?</p>
        </div>
        <div class="erp-leave-actions">
            <button type="button" class="erp-leave-btn primary" id="erpLeaveYesBtn">Yes</button>
            <button type="button" class="erp-leave-btn" id="erpLeaveNoBtn">No</button>
        </div>
    </div>
</div>

<script>
// ─────────────────────────────────────────────────────────────────────────────
// PHP → JS: navigation constants (updated by the fragment after it loads)
// ─────────────────────────────────────────────────────────────────────────────
window.ERP_PREV_DOC_NO  = '<?php echo addslashes($prev_doc_no); ?>';
window.ERP_NEXT_DOC_NO  = '<?php echo addslashes($next_doc_no); ?>';
window.ERP_DOC_TYPE     = '<?php echo addslashes($selected_doc_type); ?>';
window.ERP_SEARCH_URL   = '<?php echo addslashes($search_doc_url); ?>';

// ─────────────────────────────────────────────────────────────────────────────
// Preserve ?embed=1 on in-page navigations (iframe shell guard otherwise → dashboard)
// ─────────────────────────────────────────────────────────────────────────────
function erpNavUrl(queryString) {
    var qs = String(queryString || '').replace(/^\?/, '');
    var url = window.location.pathname;
    if (qs) {
        url += '?' + qs;
    }
    if (window.location.search.indexOf('embed=1') >= 0 && url.indexOf('embed=1') < 0) {
        url += (url.indexOf('?') >= 0 ? '&' : '?') + 'embed=1';
    }
    return url;
}

// ─────────────────────────────────────────────────────────────────────────────
// PRIOR / NEXT navigation
// ─────────────────────────────────────────────────────────────────────────────
function erpNavPrior() {
    if (window.ERP_PREV_DOC_NO) {
        window.location.href = erpNavUrl('doc_no=' + encodeURIComponent(window.ERP_PREV_DOC_NO));
    }
}

function erpNavNext() {
    if (window.ERP_NEXT_DOC_NO) {
        window.location.href = erpNavUrl('doc_no=' + encodeURIComponent(window.ERP_NEXT_DOC_NO));
    } else {
        // No next doc → open a blank new document of the same type
        window.location.href = erpNavUrl('doc_type=' + encodeURIComponent(window.ERP_DOC_TYPE));
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// FIND — Document search modal
// ─────────────────────────────────────────────────────────────────────────────
window.ERP_FIND_REF_LABEL = <?php echo json_encode($search_ref_label); ?>;

function erpOpenDocSearch() {
    var modal = document.getElementById('erpDocSearchModal');
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
    document.getElementById('erpFindDocName').textContent = erpFindDocName();
    var input = document.getElementById('erpDocSearchInput');
    input.value = '';
    input.focus();
    erpSearchDocsNow();
}

function erpCloseDocSearch() {
    document.getElementById('erpDocSearchModal').style.display = 'none';
    document.body.style.overflow = '';
}

function erpResetDocSearch() {
    var input = document.getElementById('erpDocSearchInput');
    input.value = '';
    input.focus();
    erpSearchDocsNow();
}

function erpFindEsc(v) {
    return String(v == null ? '' : v)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

// Document name from the page's doc-type chip, e.g. "STOCK TRANSFER IN" -> "Stock Transfer In"
function erpFindDocName() {
    var chip = document.querySelector('#tbIdentity .doc-chip-type, .doc-titlebar .doc-chip-type');
    var name = (chip ? chip.textContent : document.title.replace(/\(.*\)/, '')).replace(/\s+/g, ' ').trim();
    return name.toLowerCase().replace(/\b[a-z]/g, function (c) { return c.toUpperCase(); });
}

function erpFindDate(v) {
    var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(v || '');
    if (!m) return v || '';
    var mon = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'][parseInt(m[2], 10) - 1];
    return m[3] + ' ' + mon + ' ' + m[1];
}

function erpFindState(msg, isError) {
    document.getElementById('erpDocSearchResults').innerHTML =
        '<div class="erp-search-state' + (isError ? ' error' : '') + '">' + erpFindEsc(msg) + '</div>';
}

var _erpDocSearchTimeout;
var _erpDocSearchSeq = 0;
function erpSearchDocs(query) {
    clearTimeout(_erpDocSearchTimeout);
    _erpDocSearchTimeout = setTimeout(function () { erpRunDocSearch(query); }, 300);
}

function erpSearchDocsNow() {
    clearTimeout(_erpDocSearchTimeout);
    erpRunDocSearch(document.getElementById('erpDocSearchInput').value);
}

// One box for every field: search_document.php is asked once per search type
// (Doc No, reference, Job No) and the matches are merged by doc no.
function erpRunDocSearch(query) {
    var seq = ++_erpDocSearchSeq;
    var term = String(query || '').trim();
    var types = term === '' ? ['doc_no'] : ['doc_no', 'tracking_no', 'job_no'];
    document.getElementById('erpFindCount').textContent = '';
    erpFindState('Searching...');

    Promise.all(types.map(function (type) {
        var url = window.ERP_SEARCH_URL
            + (window.ERP_SEARCH_URL.indexOf('?') >= 0 ? '&' : '?')
            + 'term='      + encodeURIComponent(term)
            + '&type='     + encodeURIComponent(type)
            + '&doc_type=' + encodeURIComponent(window.ERP_DOC_TYPE);
        return fetch(url)
            .then(function (r) { return r.json(); })
            .then(function (data) { return Array.isArray(data) ? data : null; })
            .catch(function (err) { console.error('erpSearchDocs error:', err); return null; });
    })).then(function (results) {
        if (seq !== _erpDocSearchSeq) return;
        if (results.every(function (r) { return r === null; })) {
            erpFindState('Could not load documents. Please try again.', true);
            return;
        }
        var byNo = {};
        var docs = [];
        results.forEach(function (list) {
            (list || []).forEach(function (doc) {
                if (!doc || doc.error || byNo[doc.doc_no]) return;
                byNo[doc.doc_no] = true;
                docs.push(doc);
            });
        });
        docs.sort(function (a, b) {
            return String(b.doc_no).localeCompare(String(a.doc_no), undefined, { numeric: true });
        });
        erpRenderDocSearch(docs);
    });
}

function erpRenderDocSearch(docs) {
    document.getElementById('erpFindCount').textContent = docs.length + (docs.length === 1 ? ' document' : ' documents');
    if (!docs.length) {
        erpFindState('No documents found');
        return;
    }
    var docName = erpFindDocName();
    document.getElementById('erpDocSearchResults').innerHTML = docs.map(function (doc) {
        var dn = String(doc.doc_no || '').replace(/\\/g, '\\\\').replace(/'/g, "\\'");
        var sub = [docName, erpFindDate(doc.doc_dt)].filter(Boolean).join(' \u00b7 ');
        var extra = [];
        if (doc.customer) extra.push('Customer: ' + doc.customer);
        if (doc.tracking_no) extra.push(window.ERP_FIND_REF_LABEL + ': ' + doc.tracking_no);
        if (doc.job_no) extra.push('Job No: ' + doc.job_no);
        if (doc.narration) extra.push(doc.narration);
        return '<div class="erp-search-row">'
            +   '<span class="erp-search-ico"><i class="fa fa-file-text-o"></i></span>'
            +   '<div class="erp-search-main">'
            +     '<div class="erp-search-name">' + erpFindEsc(doc.doc_no) + '</div>'
            +     '<div class="erp-search-sub">' + erpFindEsc(sub) + '</div>'
            +     (extra.length ? '<div class="erp-search-extra" title="' + erpFindEsc(extra.join(' \u00b7 ')) + '">' + erpFindEsc(extra.join(' \u00b7 ')) + '</div>' : '')
            +   '</div>'
            +   '<button type="button" class="erp-search-open" onclick="erpSelectDoc(\'' + erpFindEsc(dn) + '\')">'
            +     'Open <i class="fa fa-external-link"></i></button>'
            + '</div>';
    }).join('');
}

function erpSelectDoc(doc_no) {
    erpConfirmLeaveNav(function () {
        erpCloseDocSearch();
        // Delegate to the active module so it can navigate to its own URL
        if (typeof window.erpToolbar.onDocSelect === 'function') {
            window.erpToolbar.onDocSelect(doc_no);
        } else {
            window.location.href = erpNavUrl('doc_no=' + encodeURIComponent(doc_no));
        }
    });
}

// ─────────────────────────────────────────────────────────────────────────────
// EDIT — Password modal
// ─────────────────────────────────────────────────────────────────────────────
function erpOpenEditPassword() {
    // Only show password prompt when viewing a saved document
    var docDtInput = document.querySelector('input[name="doc_dt"]');
    if (docDtInput && !docDtInput.hasAttribute('disabled') && !docDtInput.hasAttribute('readonly')) {
        alert('You are already in edit mode.');
        return;
    }
    var pwdTitle = document.querySelector('#erpEditPasswordModal h4');
    if (pwdTitle) pwdTitle.textContent = 'Enter Edit Password';
    document.getElementById('erpEditPasswordModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
    var inp = document.getElementById('erpEditPasswordInput');
    inp.value = '';
    inp.focus();
}

/** Delete gate: allow_delete=Y (button enabled) → confirm → module deletes header + detail. */
function erpRequestDelete() {
    var delBtn = document.getElementById('tbDelete');
    if (delBtn && delBtn.disabled) {
        alert('You do not have permission to delete.');
        return;
    }
    var docNo = (typeof window.ERP_DOC_NO !== 'undefined') ? String(window.ERP_DOC_NO || '').trim() : '';
    if (!docNo) {
        var dn = document.querySelector('input[name="doc_no"]');
        docNo = dn ? String(dn.value || '').trim() : '';
    }
    if (!docNo) {
        alert('Open a saved document before deleting.');
        return;
    }
    if (!confirm('Delete this document permanently? Header and detail lines will be removed.')) return;
    if (typeof window.erpToolbar.onDeleteConfirmed === 'function') {
        window.erpToolbar.onDeleteConfirmed();
    } else {
        alert('Delete is not configured for this document.');
    }
}

function erpCloseEditPassword() {
    document.getElementById('erpEditPasswordModal').style.display = 'none';
    document.body.style.overflow = '';
}

function erpVerifyEditPassword() {
    var pwd = document.getElementById('erpEditPasswordInput').value;
    if (!pwd) { alert('Please enter a password.'); return; }

    var fd = new FormData();
    fd.append('password', pwd);

    fetch('../../verify_edit_password.php', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data.success) {
                alert(data.message || 'Invalid password.');
                return;
            }
            erpCloseEditPassword();

            // Edit unlock
            if (typeof window.erpToolbar.onEditVerified === 'function') {
                window.erpToolbar.onEditVerified();
            }
            if (typeof erpToolbarSetState === 'function') {
                erpToolbarSetState({ save: true, edit: false, cancel: true });
            }
        })
        .catch(function (err) {
            console.error('erpVerifyEditPassword error:', err);
            alert('Error verifying password. Please try again.');
        });
}

// ─────────────────────────────────────────────────────────────────────────────
// ERP Toolbar dispatcher
// ─────────────────────────────────────────────────────────────────────────────
// ── Dirty form guard + styled leave warning + toasts ─────────────────────────
window.erpAllowLeaveOnce = false;
window.erpFormDirty = false;
var _erpFormSnapshot = null;
var _erpLeaveProceed = null;

function erpNormFieldValue(v) {
    if (v == null) return '';
    var s = String(v);
    if (/^\s*-?\d+(\.\d+)?\s*$/.test(s)) {
        var n = parseFloat(s);
        if (!isNaN(n)) return String(n);
    }
    return s;
}

function erpSerializeFormState() {
    var parts = [];
    document.querySelectorAll('input[name], select[name], textarea[name]').forEach(function (el) {
        if (!el.name) return;
        // Computed on save — ignore so auto-summary does not mark dirty
        if (el.name === 'detail_lines_json' || el.name === 'variableTermsJson') return;
        if (el.type === 'button' || el.type === 'submit') return;
        if (el.type === 'checkbox' || el.type === 'radio') {
            parts.push(el.name + '=' + (el.checked ? erpNormFieldValue(el.value) : ''));
        } else {
            parts.push(el.name + '=' + erpNormFieldValue(el.value));
        }
    });
    if (typeof getDetailLinesJson === 'function') {
        try { parts.push('__lines=' + getDetailLinesJson()); } catch (e) {}
    }
    parts.sort();
    return parts.join('\n');
}

window.erpCaptureFormSnapshot = function () {
    _erpFormSnapshot = erpSerializeFormState();
    window.erpFormDirty = false;
};

window.erpMarkFormDirty = function () {
    window.erpFormDirty = true;
};

window.erpHasUnsavedEntry = function () {
    if (window.erpAllowLeaveOnce) return false;
    if (window.erpFormDirty) return true;
    if (_erpFormSnapshot == null) return false;
    try {
        return erpSerializeFormState() !== _erpFormSnapshot;
    } catch (e) {
        return false;
    }
};

function erpCloseLeaveWarning() {
    var modal = document.getElementById('erpLeaveWarnModal');
    if (modal) modal.style.display = 'none';
    document.body.style.overflow = '';
    _erpLeaveProceed = null;
}

function erpOpenLeaveWarning(proceed) {
    _erpLeaveProceed = proceed;
    var modal = document.getElementById('erpLeaveWarnModal');
    if (!modal) {
        // Fallback if markup missing
        if (window.confirm('You have Un-updated Records..\nDo you want to Continue.?')) {
            if (typeof proceed === 'function') proceed();
        }
        return;
    }
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
    var yes = document.getElementById('erpLeaveYesBtn');
    if (yes) yes.focus();
}

/**
 * If the form is clean, runs proceed() immediately and returns true.
 * If dirty, shows the styled warning; Yes runs proceed(), No stays. Returns false.
 */
window.erpConfirmLeaveNav = function (proceed) {
    if (typeof window.erpHasUnsavedEntry !== 'function' || !window.erpHasUnsavedEntry()) {
        if (typeof proceed === 'function') proceed();
        return true;
    }
    if (typeof proceed !== 'function') return false;
    erpOpenLeaveWarning(proceed);
    return false;
};

document.addEventListener('click', function (e) {
    if (!e.target || !e.target.closest) return;
    if (e.target.closest('#tbSave')) {
        window.erpAllowLeaveOnce = true;
    }
    if (e.target.closest('#erpLeaveYesBtn')) {
        var go = _erpLeaveProceed;
        erpCloseLeaveWarning();
        if (typeof go === 'function') go();
    }
    if (e.target.closest('#erpLeaveNoBtn') || (e.target.id === 'erpLeaveWarnModal')) {
        erpCloseLeaveWarning();
    }
});
document.addEventListener('keydown', function (e) {
    var modal = document.getElementById('erpLeaveWarnModal');
    if (!modal || modal.style.display === 'none') return;
    if (e.key === 'Escape') { e.preventDefault(); erpCloseLeaveWarning(); }
    if (e.key === 'Enter') {
        e.preventDefault();
        var go = _erpLeaveProceed;
        erpCloseLeaveWarning();
        if (typeof go === 'function') go();
    }
});
document.addEventListener('submit', function () {
    window.erpAllowLeaveOnce = true;
});

// Mark dirty only on real user edits (not toolbar / programmatic noise)
document.addEventListener('input', function (e) {
    var t = e.target;
    if (!t || !t.name) return;
    if (t.name === 'detail_lines_json' || t.name === 'variableTermsJson') return;
    window.erpMarkFormDirty();
}, true);
document.addEventListener('change', function (e) {
    var t = e.target;
    if (!t || !t.name) return;
    if (t.name === 'detail_lines_json' || t.name === 'variableTermsJson') return;
    window.erpMarkFormDirty();
}, true);

// Snapshot after initial form/detail settle so auto-filled totals are not "dirty"
document.addEventListener('DOMContentLoaded', function () {
    setTimeout(function () { window.erpCaptureFormSnapshot(); }, 400);
    setTimeout(function () { window.erpCaptureFormSnapshot(); }, 1000);
    // Auto-hide any server-rendered toasts (success = 3s)
    document.querySelectorAll('#erpToasts .erp-toast[data-autohide], .erp-toasts .erp-toast[data-autohide]').forEach(function (toast) {
        var ms = parseInt(toast.getAttribute('data-autohide'), 10) || 3000;
        setTimeout(function () { erpToastClose(toast); }, ms);
    });
});

window.erpToastClose = function (toast) {
    if (!toast || toast.classList.contains('hide')) return;
    toast.classList.add('hide');
    setTimeout(function () { if (toast.parentNode) toast.parentNode.removeChild(toast); }, 250);
};

/** Styled toast flag. type: 'success' | 'error'. Default success hides after 3s. */
window.erpShowToast = function (type, message, ms) {
    type = type === 'error' ? 'error' : 'success';
    if (ms == null) ms = type === 'success' ? 3000 : 6000;
    var box = document.getElementById('erpToasts');
    if (!box) {
        box = document.createElement('div');
        box.id = 'erpToasts';
        box.className = 'erp-toasts';
        box.setAttribute('aria-live', 'polite');
        document.body.appendChild(box);
    }
    var bar = document.getElementById('erpToolbar');
    if (bar) box.style.top = Math.max(12, Math.round(bar.getBoundingClientRect().bottom) + 8) + 'px';
    var toast = document.createElement('div');
    toast.className = 'erp-toast ' + type;
    toast.setAttribute('role', type === 'error' ? 'alert' : 'status');
    toast.innerHTML = '<i class="fa ' + (type === 'error' ? 'fa-exclamation-triangle' : 'fa-check-circle')
        + ' erp-toast-icon"></i><div class="erp-toast-msg"></div>'
        + '<button type="button" class="erp-toast-close" title="Close" aria-label="Close">'
        + '<i class="fa fa-times"></i></button>';
    toast.querySelector('.erp-toast-msg').innerHTML = message;
    toast.querySelector('.erp-toast-close').addEventListener('click', function () { erpToastClose(toast); });
    box.appendChild(toast);
    if (ms > 0) setTimeout(function () { erpToastClose(toast); }, ms);
};

function erpToolbarGoHome(contentUrl) {
    erpConfirmLeaveNav(function () {
        if (window.self !== window.top) {
            window.location.href = contentUrl;
            return;
        }
        window.location.href = <?php echo json_encode($shell_home_url); ?>;
    });
}

window.erpToolbar = window.erpToolbar || {};

(function () {
    var defaults = {
        // Default implementations — containers/fragments can override any of these
        onNew:         function () {},
        onSave:        function () {},
        onFind:        function () { erpOpenDocSearch(); },
        onEdit:        function () { erpOpenEditPassword(); },
        onDelete:      function () { erpRequestDelete(); },
        onDeleteConfirmed: function () {},
        onPreview:     function () {},
        onPrint:       function () {},
        onSelect:      function () {},
        onPrior:       function () { erpNavPrior(); },
        onNext:        function () { erpNavNext(); },
        onRevision:    function () {},
        onCancel:      function () {},
        onAttach:      function () {},
        onForward:     function () {},
        onStatus:      function () {},
        onList:        function () {},
        onReport:      function () {},
        // Called after edit password verified — fragment overrides to unlock its form
        onEditVerified: function () {},
        // Called when a document is selected in the FIND modal
        onDocSelect:   function (doc_no) {
            window.location.href = erpNavUrl('doc_no=' + encodeURIComponent(doc_no));
        }
    };
    for (var k in defaults) {
        if (typeof window.erpToolbar[k] === 'undefined') {
            window.erpToolbar[k] = defaults[k];
        }
    }
}());

// ─────────────────────────────────────────────────────────────────────────────
// Single header row: the fragment's .doc-titlebar (title, doc-type chip, status,
// Entered/Modified by) moves into the toolbar.
// Fragments call this right after the title bar so the page never shows two rows.
// ─────────────────────────────────────────────────────────────────────────────
function erpToolbarAdoptTitlebar() {
    var bar  = document.querySelector('.doc-titlebar');
    var slot = document.getElementById('tbIdentity');
    if (!bar || !slot) return;
    var info = bar.querySelector('.mr-info');
    if (info) info.title = info.innerText.replace(/\s*\n\s*/g, '\n').trim();
    Array.prototype.slice.call(bar.children).forEach(function (el) {
        if (!el.classList.contains('doc-title-spacer')) slot.appendChild(el);
    });
    bar.parentNode.removeChild(bar);
    document.body.classList.add('tb-merged');
}
document.addEventListener('DOMContentLoaded', erpToolbarAdoptTitlebar);

// ─────────────────────────────────────────────────────────────────────────────
// MORE menu (secondary actions)
// ─────────────────────────────────────────────────────────────────────────────
function erpToolbarSetMore(open) {
    var menu = document.getElementById('tbMoreMenu');
    var btn  = document.getElementById('tbMoreBtn');
    if (!menu || !btn) return;
    menu.classList.toggle('open', open);
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (open) {
        var first = menu.querySelector('button:not(:disabled)');
        if (first) first.focus();
    }
}

function erpToolbarToggleMore(e) {
    if (e) e.stopPropagation();
    var menu = document.getElementById('tbMoreMenu');
    erpToolbarSetMore(!(menu && menu.classList.contains('open')));
}

document.addEventListener('click', function (e) {
    if (!e.target.closest || !e.target.closest('.tb-more')) erpToolbarSetMore(false);
});
document.addEventListener('keydown', function (e) {
    var menu = document.getElementById('tbMoreMenu');
    if (!menu || !menu.classList.contains('open')) return;
    if (e.key === 'Escape') {
        erpToolbarSetMore(false);
        document.getElementById('tbMoreBtn').focus();
    } else if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        var items = Array.prototype.slice.call(menu.querySelectorAll('button:not(:disabled)'));
        var at = items.indexOf(document.activeElement);
        items[(at + (e.key === 'ArrowDown' ? 1 : items.length - 1)) % items.length].focus();
    }
});

// ─────────────────────────────────────────────────────────────────────────────
// PREVIEW window: the document's print format (document_setup.print_file)
// shown inside this page by shared/print_preview.php
// ─────────────────────────────────────────────────────────────────────────────
function erpOpenPrintPreview(docCode, docType, docNo) {
    if (!docNo) return;
    var box   = document.getElementById('erpPrintPreview');
    var frame = document.getElementById('erpPrintPreviewFrame');
    frame.src = <?php echo json_encode(erp_url('shared/print_preview')); ?>
        + '?doc_code=' + encodeURIComponent(docCode)
        + '&doc_type=' + encodeURIComponent(docType)
        + '&doc_no=' + encodeURIComponent(docNo)
        + '&embed=1';
    box.style.display = 'flex';
    frame.focus();
}

function erpClosePrintPreview() {
    var box = document.getElementById('erpPrintPreview');
    if (!box) return;
    box.style.display = 'none';
    document.getElementById('erpPrintPreviewFrame').src = 'about:blank';
    var btn = document.getElementById('tbPreview');
    if (btn) btn.focus();
}

document.addEventListener('keydown', function (e) {
    var box = document.getElementById('erpPrintPreview');
    if (e.key === 'Escape' && box && box.style.display !== 'none') erpClosePrintPreview();
});

function erpToolbarAction(action) {
    erpToolbarSetMore(false);
    var leaveActions = { new: 1, prior: 1, next: 1, cancel: 1, list: 1 };
    var run = function () {
        var fn = 'on' + action.charAt(0).toUpperCase() + action.slice(1);
        if (typeof window.erpToolbar[fn] === 'function') {
            window.erpToolbar[fn]();
        }
    };
    if (leaveActions[action]) {
        erpConfirmLeaveNav(run);
        return;
    }
    run();
}

/**
 * Enable or disable toolbar buttons.
 * @param {Object} states  e.g. { save: true, edit: false, delete: true }
 */
function erpToolbarSetState(states) {
    var map = {
        'new':      'tbNew',    'save':     'tbSave',
        'find':     'tbFind',   'edit':     'tbEdit',
        'delete':   'tbDelete', 'preview':  'tbPreview',
        'print':    'tbPrint',  'select':   'tbSelect',
        'prior':    'tbPrior',  'next':     'tbNext',
        'revision': 'tbRevision','cancel':  'tbCancel',
        'attach':   'tbAttach', 'forward':  'tbForward',
        'status':   'tbStatus', 'list':     'tbList',
        'report':   'tbReport'
    };
    for (var action in states) {
        if (map[action]) {
            var btn = document.getElementById(map[action]);
            if (btn) btn.disabled = !states[action];
        }
    }
}
</script>
