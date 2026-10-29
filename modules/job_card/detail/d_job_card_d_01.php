<?php
/**
 * Job Card detail fragment — included by modules/job_card/job_card.php
 * Table: job_card_d
 */
if (!isset($con)) {
    require('../../../db.php');
    include('../../../auth.php');
}

$owns_output_buffer = !isset($con);
if ($owns_output_buffer) {
    ob_start();
}

$company_code = $_SESSION['company_code'] ?? '';
$branch_code  = $_SESSION['branch_code']  ?? '';
$user         = $_SESSION['username']     ?? '';

$unit_list = [];
try {
    $stmt_units = $con->prepare("SELECT stock_unit FROM item_unit ORDER BY stock_unit ASC");
    $stmt_units->execute([]);
    while ($u = $stmt_units->fetch(PDO::FETCH_ASSOC)) {
        $unit_list[] = trim($u['stock_unit']);
    }
} catch (Exception $e) {
    error_log('JC item_unit fetch error: ' . $e->getMessage());
}

if (!function_exists('jc_unit_options_html')) {
    function jc_unit_options_html(array $unit_list, $selected = '')
    {
        $selected = trim((string) $selected);
        $list = $unit_list;
        if ($selected !== '' && !in_array($selected, $list, true)) {
            $list[] = $selected;
        }
        $html = '<option value="">--</option>';
        foreach ($list as $ul) {
            $ul_esc = htmlspecialchars($ul, ENT_QUOTES, 'UTF-8');
            $sel = ($ul === $selected) ? ' selected' : '';
            $html .= '<option value="' . $ul_esc . '"' . $sel . '>' . $ul_esc . '</option>';
        }
        return $html;
    }
}

if (!function_exists('jc_blank_line')) {
    function jc_blank_line($con, $company_code, $branch_code, $srl = 1, $line_no = 1, array $unit_list = [])
    {
        $vat_pct = '0';
        try {
            $stmt_bm = $con->prepare("SELECT tax1_percent FROM branch_master WHERE company_code = ? AND branch_code = ?");
            $stmt_bm->execute([$company_code, $branch_code]);
            $row_bm = $stmt_bm->fetch(PDO::FETCH_ASSOC);
            if ($row_bm) {
                $vat_pct = trim((string) ($row_bm['tax1_percent'] ?? '0'));
            }
        } catch (Exception $e) {
            error_log('JC Branch master VAT fetch error: ' . $e->getMessage());
        }

        $line_id = 'line_' . time() . '_' . rand(1000, 9999);
        $srl_pad = str_pad((string) $srl, 4, '0', STR_PAD_LEFT);
        $unit_opts = jc_unit_options_html($unit_list, '');

        return [
            'success' => true,
            'line_id' => $line_id,
            'vat_pct' => $vat_pct,
            'html'    => '
        <tr data-line-id="' . $line_id . '" class="detail-row">
            <td class="srl-col" data-label="Srl">' . htmlspecialchars($srl_pad) . '</td>
            <td data-label="Line"><input type="text" class="form-control form-control-sm detail-input text-center" name="line_no[]" value="' . (int) $line_no . '" readonly></td>
            <td data-label="Code"><input type="text" class="form-control form-control-sm detail-input si-code" name="item_code[]" placeholder="Select item" readonly style="cursor:pointer;" onfocus="openItemSearch(this)" onclick="openItemSearch(this)"></td>
            <td class="si-desc-cell" data-label="Description">
                <div class="detail-cell">
                    <textarea class="form-control form-control-sm detail-input si-desc" name="item_desc[]" rows="1" placeholder="Description"></textarea>
                    <button type="button" class="detail-more" tabindex="-1" onclick="jcOpenDescMore(this)">More..</button>
                </div>
            </td>
            <td data-label="Qty"><input type="number" class="form-control form-control-sm detail-input" name="qty[]" placeholder="0" value="0" style="text-align:right;"></td>
            <td data-label="Unit"><select class="form-control form-control-sm detail-input text-center" name="unit[]">' . $unit_opts . '</select></td>
            <td data-label="Rate"><input type="number" class="form-control form-control-sm detail-input" name="rate[]" placeholder="0.00" value="0.00" step="0.01" style="text-align:right;"></td>
            <td data-label="Discount">
                <div class="detail-split-inputs">
                    <input type="number" class="form-control form-control-sm detail-input" name="discount_pct[]" placeholder="%" value="0" step="0.01" style="text-align:center;">
                    <input type="number" class="form-control form-control-sm detail-input" name="discount_amt[]" placeholder="Amt" value="0.00" step="0.01" style="text-align:right;">
                </div>
            </td>
            <td data-label="VAT Amt">
                <input type="hidden" name="vat[]" value="' . htmlspecialchars($vat_pct) . '">
                <input type="text" class="form-control form-control-sm detail-input" name="vat_amount[]" placeholder="0.00" readonly style="text-align:right;">
            </td>
            <td data-label="Amount"><input type="text" class="form-control form-control-sm detail-input si-total" name="net_amount[]" placeholder="0.00" readonly style="text-align:right;"></td>
            <td data-label="More">
                <input type="hidden" name="enquiry_no[]" value="">
                <input type="hidden" name="estimate_no[]" value="">
                <input type="hidden" name="quotation_no[]" value="">
                <input type="hidden" name="salesorder_no[]" value="">
                <input type="hidden" name="line_po_no[]" value="">
                <input type="hidden" name="item_serialno[]" value="">
                <input type="hidden" name="item_remark[]" value="">
                <button type="button" class="detail-more detail-more-data" tabindex="-1" onclick="jcOpenMoreData(this)"><i class="fa fa-list-alt"></i> More Data</button>
            </td>
            <td class="si-del" data-label=""><button type="button" class="btn btn-sm btn-danger" onclick="deleteDetailLine(this)" title="Delete line"><i class="fa fa-trash"></i></button></td>
            <input type="hidden" name="line_id[]" value="' . $line_id . '">
        </tr>',
        ];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_line') {
    if ($owns_output_buffer && ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json');
    $srl = max(1, (int) ($_POST['next_srl'] ?? 1));
    echo json_encode(jc_blank_line($con, $company_code, $branch_code, $srl, $srl, $unit_list));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'search_items') {
    if ($owns_output_buffer && ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json');

    $user_id = $_SESSION['username'] ?? '';
    $term    = '%' . trim($_POST['term'] ?? '') . '%';

    try {
        $sql = "SELECT ils.itemcode,
                       im.item_name,
                       im.stock_unit,
                       im.tax_amount,
                       ils.stockqty,
                       bu.location_code,
                       ISNULL((SELECT TOP 1 retail_price FROM item_price
                                WHERE item_code = ils.itemcode), 0) AS retail_price
                FROM branch_user bu
                INNER JOIN view_item_ledger_summary ils ON ils.locationcode = bu.location_code
                INNER JOIN item_master im ON im.item_code = ils.itemcode
                WHERE bu.user_id = ? AND bu.branch_code = ?
                AND (ils.itemcode LIKE ? OR im.item_name LIKE ?)";
        $stmt = $con->prepare($sql);
        $stmt->execute([$user_id, $branch_code, $term, $term]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Exception $e) {
        error_log('JC Item search error: ' . $e->getMessage());
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

$existing_detail_lines = [];
$_detail_doc_no = (isset($doc_no) && $doc_no !== '') ? $doc_no
                : (isset($_GET['doc_no']) ? trim($_GET['doc_no']) : '');
if ($_detail_doc_no !== '') {
    try {
        $stmt_ld = $con->prepare(
            "SELECT * FROM job_card_d
             WHERE company_code = ? AND branch_code = ? AND doc_no = ?
             ORDER BY serial_no ASC"
        );
        $stmt_ld->execute([$company_code, $branch_code, $_detail_doc_no]);
        $existing_detail_lines = $stmt_ld->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log('Load job_card_d error: ' . $e->getMessage());
    }
}
$_detail_view = isset($is_view_mode) && $is_view_mode;
?>
<?php if ($owns_output_buffer): ?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Job Card Detail</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="//maxcdn.bootstrapcdn.com/bootstrap/4.0.0/css/bootstrap.min.css" rel="stylesheet">
    <link href="../../../vendor/font-awesome/css/font-awesome.min.css" rel="stylesheet" type="text/css">
    <link rel="stylesheet" href="../../../material_request.css?v=5">
    <link rel="stylesheet" href="../../../assets/css/erp_document.css?v=27">
</head>
<body>
<?php endif; ?>

<div class="detail-container">
    <div class="detail-header">
        <h2>Items <span class="si-line-count" id="jcLineCount"></span></h2>
    </div>

    <div class="detail-table-wrapper">
        <form id="detailForm">
            <table class="detail-table si-lines">
                <thead>
                    <tr>
                        <th style="width:48px;">Srl</th>
                        <th style="width:48px;">Line</th>
                        <th style="width:96px;">Item Code</th>
                        <th style="min-width:200px;">Item Description</th>
                        <th style="width:72px;" class="num">Qty</th>
                        <th style="width:64px;">Unit</th>
                        <th style="width:84px;" class="num">Rate</th>
                        <th style="width:130px;" class="split">Discount</th>
                        <th style="width:92px;" class="num">VAT Amount</th>
                        <th style="width:96px;" class="num">Amount</th>
                        <th style="width:110px;">More Data</th>
                        <th style="width:34px;"></th>
                    </tr>
                </thead>
                <tbody id="detailTableBody">
<?php if (!empty($existing_detail_lines)): ?>
<?php foreach ($existing_detail_lines as $_di => $_dl): ?>
<?php
    $_dl_qty      = (float) ($_dl['trans_qty'] ?? $_dl['item_qty'] ?? 0);
    $_dl_rate     = (float) ($_dl['trans_rate'] ?? $_dl['item_rate'] ?? 0);
    $_dl_disc_pct = (float) ($_dl['discount_percent'] ?? 0);
    $_dl_disc_amt = (float) ($_dl['discount_amount'] ?? 0);
    $_dl_vat_pct  = trim((string) ($_dl['tax1_percent'] ?? '0'));
    $_dl_vat_amt  = (float) ($_dl['tax1_amount'] ?? 0);
    $_dl_gross    = round($_dl_qty * $_dl_rate, 2);
    if ($_dl_disc_amt <= 0 && $_dl_disc_pct > 0) {
        $_dl_disc_amt = round(($_dl_gross * $_dl_disc_pct) / 100, 2);
    }
    $_dl_net      = round($_dl_gross - $_dl_disc_amt + $_dl_vat_amt, 2);
    $_dl_lid      = 'line_db_' . ($_di + 1);
    $_dl_srl      = trim((string) ($_dl['serial_no'] ?? ''));
    if ($_dl_srl === '') {
        $_dl_srl = str_pad((string) ($_di + 1), 4, '0', STR_PAD_LEFT);
    }
    $_dl_line     = trim((string) ($_dl['item_no'] ?? $_dl['line_no'] ?? ''));
    if ($_dl_line === '') {
        $_dl_line = (string) ($_di + 1);
    }
    $_dl_unit     = trim((string) ($_dl['trans_unit'] ?? ''));
    if ($_dl_unit === '') {
        $_dl_unit = trim((string) ($_dl['item_unit'] ?? ''));
    }
    $_dl_ro       = $_detail_view ? 'readonly' : '';
    $_dl_desc_ro  = $_detail_view ? 'readonly' : '';
?>
<tr data-line-id="<?php echo $_dl_lid; ?>" class="detail-row">
    <td class="srl-col" data-label="Srl"><?php echo htmlspecialchars($_dl_srl); ?></td>
    <td data-label="Line"><input type="text" class="form-control form-control-sm detail-input text-center" name="line_no[]" value="<?php echo htmlspecialchars($_dl_line); ?>" readonly></td>
    <td data-label="Code"><input type="text" class="form-control form-control-sm detail-input si-code" name="item_code[]" value="<?php echo htmlspecialchars($_dl['item_code'] ?? ''); ?>"
             <?php echo $_detail_view ? 'readonly' : 'onfocus="openItemSearch(this)" onclick="openItemSearch(this)"'; ?>
             style="cursor:<?php echo $_detail_view ? 'default' : 'pointer'; ?>;"></td>
    <td class="si-desc-cell" data-label="Description">
        <div class="detail-cell">
            <textarea class="form-control form-control-sm detail-input si-desc" name="item_desc[]" rows="1" <?php echo $_dl_desc_ro; ?>><?php echo htmlspecialchars($_dl['item_description'] ?? ''); ?></textarea>
            <button type="button" class="detail-more" tabindex="-1" onclick="jcOpenDescMore(this)">More..</button>
        </div>
    </td>
    <td data-label="Qty"><input type="number" class="form-control form-control-sm detail-input" name="qty[]" value="<?php echo htmlspecialchars((string) $_dl_qty); ?>" <?php echo $_dl_ro; ?> style="text-align:right;"></td>
    <td data-label="Unit"><select class="form-control form-control-sm detail-input text-center" name="unit[]" <?php echo $_detail_view ? 'disabled' : ''; ?>><?php echo jc_unit_options_html($unit_list, $_dl_unit); ?></select></td>
    <td data-label="Rate"><input type="number" class="form-control form-control-sm detail-input" name="rate[]" value="<?php echo htmlspecialchars((string) $_dl_rate); ?>" <?php echo $_dl_ro; ?> step="0.01" style="text-align:right;"></td>
    <td data-label="Discount">
        <div class="detail-split-inputs">
            <input type="number" class="form-control form-control-sm detail-input" name="discount_pct[]" value="<?php echo htmlspecialchars(number_format($_dl_disc_pct, 2, '.', '')); ?>" <?php echo $_dl_ro; ?> step="0.01" style="text-align:center;">
            <input type="number" class="form-control form-control-sm detail-input" name="discount_amt[]" value="<?php echo htmlspecialchars(number_format($_dl_disc_amt, 2, '.', '')); ?>" <?php echo $_dl_ro; ?> step="0.01" style="text-align:right;">
        </div>
    </td>
    <td data-label="VAT Amt">
        <input type="hidden" name="vat[]" value="<?php echo htmlspecialchars($_dl_vat_pct); ?>">
        <input type="text" class="form-control form-control-sm detail-input" name="vat_amount[]" value="<?php echo htmlspecialchars(number_format($_dl_vat_amt, 2, '.', '')); ?>" readonly style="text-align:right;">
    </td>
    <td data-label="Amount"><input type="text" class="form-control form-control-sm detail-input si-total" name="net_amount[]" value="<?php echo htmlspecialchars(number_format($_dl_net, 2, '.', '')); ?>" readonly style="text-align:right;"></td>
    <td data-label="More">
        <input type="hidden" name="enquiry_no[]" value="<?php echo htmlspecialchars($_dl['enquiry_no'] ?? ''); ?>">
        <input type="hidden" name="estimate_no[]" value="<?php echo htmlspecialchars($_dl['estimate_no'] ?? ''); ?>">
        <input type="hidden" name="quotation_no[]" value="<?php echo htmlspecialchars($_dl['quotation_no'] ?? ''); ?>">
        <input type="hidden" name="salesorder_no[]" value="<?php echo htmlspecialchars($_dl['salesorder_no'] ?? ''); ?>">
        <input type="hidden" name="line_po_no[]" value="<?php echo htmlspecialchars($_dl['po_no'] ?? ''); ?>">
        <input type="hidden" name="item_serialno[]" value="<?php echo htmlspecialchars($_dl['item_serialno'] ?? ''); ?>">
        <input type="hidden" name="item_remark[]" value="<?php echo htmlspecialchars($_dl['remark'] ?? ''); ?>">
        <button type="button" class="detail-more detail-more-data" tabindex="-1" onclick="jcOpenMoreData(this)"><i class="fa fa-list-alt"></i> More Data</button>
    </td>
    <td class="si-del"><button type="button" class="btn btn-sm btn-danger" title="Delete line" onclick="deleteDetailLine(this)" <?php echo $_detail_view ? 'style="display:none;"' : ''; ?>><i class="fa fa-trash"></i></button></td>
    <input type="hidden" name="line_id[]" value="<?php echo $_dl_lid; ?>">
</tr>
<?php endforeach; ?>
<?php elseif (!$_detail_view): ?>
<?php $_blank_line = jc_blank_line($con, $company_code, $branch_code, 1, 1, $unit_list); echo $_blank_line['html']; ?>
<?php else: ?>
                    <tr id="detailEmptyState">
                        <td colspan="12" class="empty-state">
                            <i class="fa fa-inbox"></i>
                            <p>No detail lines added. Click <strong>Add Line</strong> to start.</p>
                        </td>
                    </tr>
<?php endif; ?>
                </tbody>
            </table>
        </form>
    </div>

    <div class="summary-bar">
        <span class="summary-caption">Total</span>
        <div class="summary-item">
            <span class="summary-label">Qty</span>
            <span class="summary-value" id="totalQty">0</span>
        </div>
        <div class="summary-item">
            <span class="summary-label">Discount</span>
            <span class="summary-value" id="totalDiscount">0.00</span>
        </div>
        <div class="summary-item">
            <span class="summary-label">VAT</span>
            <span class="summary-value" id="totalVat">0.00</span>
        </div>
        <div class="summary-item">
            <span class="summary-label">Amount</span>
            <span class="summary-value highlight" id="grandTotal">0.00</span>
        </div>
    </div>

    <div class="detail-toolbar detail-toolbar-bottom">
        <button id="btnAddLine" class="detail-btn detail-btn-link" onclick="addDetailLine()" <?php echo $_detail_view ? 'disabled style="opacity:0.5;"' : ''; ?>>
            <i class="fa fa-plus"></i> Add line
        </button>
        <button id="btnDeleteLine" class="detail-btn detail-btn-link danger" onclick="deleteSelectedLines()" <?php echo $_detail_view ? 'disabled style="opacity:0.5;"' : ''; ?>>
            <i class="fa fa-trash"></i> Clear all lines
        </button>
    </div>
</div>

<!-- Item Search Modal -->
<div id="itemSearchModal" class="popup-overlay erp-search" style="display:none;">
    <div class="erp-search-dialog lg" role="dialog" aria-modal="true" aria-labelledby="itemSearchTitle">
        <div class="erp-search-head">
            <h4 class="erp-search-title" id="itemSearchTitle"><i class="fa fa-search"></i>Select Item</h4>
            <button type="button" class="erp-search-close" onclick="closeItemSearch()" title="Close" aria-label="Close"><i class="fa fa-times"></i></button>
        </div>
        <div class="erp-search-bar">
            <div class="erp-search-input">
                <i class="fa fa-search"></i>
                <input type="text" id="itemSearchInput" autocomplete="off" placeholder="Search by Code or Description..." oninput="searchItems(this.value)" autofocus>
            </div>
        </div>
        <div class="erp-search-body">
            <table class="erp-search-table">
                <thead>
                    <tr>
                        <th>Item Code</th>
                        <th>Description</th>
                        <th style="text-align:right;">Stock</th>
                        <th style="text-align:center;">Action</th>
                    </tr>
                </thead>
                <tbody id="itemResultsBody">
                    <tr><td colspan="4" style="text-align:center;padding:20px;color:#9ca3af;">Type to search items...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Description More.. Modal -->
<div id="jcDescModal" class="popup-overlay erp-search" style="display:none;">
    <div class="erp-search-dialog sm" role="dialog" aria-modal="true">
        <div class="erp-search-head">
            <h4 class="erp-search-title"><i class="fa fa-align-left"></i>Description</h4>
            <button type="button" class="erp-search-close" onclick="jcCloseDescMore()" title="Close" aria-label="Close"><i class="fa fa-times"></i></button>
        </div>
        <div class="erp-search-content">
            <textarea id="jcDescText" class="erp-search-textarea" rows="6"></textarea>
        </div>
        <div class="erp-search-foot">
            <button type="button" class="erp-search-btn" onclick="jcCloseDescMore()">Close</button>
            <button type="button" class="erp-search-btn primary" id="jcDescSaveBtn" onclick="jcSaveDescMore()">Save</button>
        </div>
    </div>
</div>

<!-- More Data Modal -->
<div id="jcMoreDataModal" class="popup-overlay erp-search" style="display:none;">
    <div class="erp-search-dialog sm" role="dialog" aria-modal="true" aria-labelledby="jcMoreDataTitle">
        <div class="erp-search-head">
            <h4 class="erp-search-title" id="jcMoreDataTitle"><i class="fa fa-list-alt"></i>More Data</h4>
            <button type="button" class="erp-search-close" onclick="jcCloseMoreData()" title="Close" aria-label="Close"><i class="fa fa-times"></i></button>
        </div>
        <div class="erp-search-content" style="display:flex;flex-direction:column;gap:10px;">
            <div class="doc-field">
                <label class="doc-lbl">Enquiry No</label>
                <input type="text" id="jcMdEnquiry" class="doc-inp" maxlength="20">
            </div>
            <div class="doc-field">
                <label class="doc-lbl">Estimate No</label>
                <input type="text" id="jcMdEstimate" class="doc-inp" maxlength="20">
            </div>
            <div class="doc-field">
                <label class="doc-lbl">Quotation No</label>
                <input type="text" id="jcMdQuotation" class="doc-inp" maxlength="20">
            </div>
            <div class="doc-field">
                <label class="doc-lbl">Salesorder No</label>
                <input type="text" id="jcMdSalesorder" class="doc-inp" maxlength="20">
            </div>
            <div class="doc-field">
                <label class="doc-lbl">Po No</label>
                <input type="text" id="jcMdPo" class="doc-inp" maxlength="50">
            </div>
            <div class="doc-field">
                <label class="doc-lbl">Item Serialno</label>
                <input type="text" id="jcMdSerial" class="doc-inp" maxlength="50">
            </div>
            <div class="doc-field">
                <label class="doc-lbl">Remark</label>
                <textarea id="jcMdRemark" class="erp-search-textarea" rows="6"></textarea>
            </div>
        </div>
        <div class="erp-search-foot">
            <button type="button" class="erp-search-btn" onclick="jcCloseMoreData()">Close</button>
            <button type="button" class="erp-search-btn primary" id="jcMdSaveBtn" onclick="jcSaveMoreData()">Save</button>
        </div>
    </div>
</div>

<script>
    var JC_DETAIL_URL = 'detail/d_job_card_d_01.php';
    var JC_UNITS = <?php echo json_encode($unit_list); ?>;
    var VAT_PCT = <?php
        $js_vat = 0;
        try {
            $stmt_bm2 = $con->prepare("SELECT tax1_percent FROM branch_master WHERE company_code = ? AND branch_code = ?");
            $stmt_bm2->execute([$company_code, $branch_code]);
            $row_bm2 = $stmt_bm2->fetch(PDO::FETCH_ASSOC);
            if ($row_bm2) { $js_vat = (float) ($row_bm2['tax1_percent'] ?? 0); }
        } catch (Exception $e) { error_log('JC Branch VAT JS var error: ' . $e->getMessage()); }
        echo json_encode($js_vat);
    ?>;
    var JC_VIEW_MODE = <?php echo $_detail_view ? 'true' : 'false'; ?>;
    var currentLineInput = null;
    var _jcDescInput = null;
    var _jcMoreRow = null;
    var jcTypingBox = null;

    function jcEsc(v) {
        return String(v == null ? '' : v)
            .replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;')
            .replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }
    function jcUnitOptions(selected) {
        var list = JC_UNITS.slice();
        if (selected && list.indexOf(selected) < 0) list.push(selected);
        return '<option value="">--</option>' + list.map(function (u) {
            return '<option value="' + jcEsc(u) + '"' + (u === selected ? ' selected' : '') + '>' + jcEsc(u) + '</option>';
        }).join('');
    }

    function showAlert(type, message) {
        console.error('[' + type + ']', message);
        alert(message);
    }

    function addDetailLine() {
        var tbody = document.getElementById('detailTableBody');
        var emptyRow = tbody.querySelector('tr td[colspan="12"]');
        if (emptyRow) emptyRow.closest('tr').remove();

        var nextSrl = tbody.querySelectorAll('tr.detail-row').length + 1;
        var formData = new FormData();
        formData.append('action', 'add_line');
        formData.append('next_srl', String(nextSrl));

        fetch(JC_DETAIL_URL, { method: 'POST', body: formData })
            .then(function (response) {
                if (!response.ok) throw new Error('Server returned ' + response.status);
                return response.json();
            })
            .then(function (data) {
                if (data.success) {
                    tbody.insertAdjacentHTML('beforeend', data.html);
                    updateLineNumbers();
                    updateSummary();
                } else {
                    showAlert('danger', 'Failed to add line: ' + (data.error || 'Unknown error'));
                }
            })
            .catch(function (err) {
                console.error('Error adding detail line:', err);
                showAlert('danger', 'Error adding detail line: ' + err.message);
            });
    }

    function deleteDetailLine(button) {
        if (!confirm('Are you sure you want to delete this line?')) return;
        button.closest('tr').remove();
        updateLineNumbers();
        updateSummary();
    }

    function deleteSelectedLines() {
        var tbody = document.getElementById('detailTableBody');
        var rows = tbody.querySelectorAll('tr.detail-row');
        if (rows.length === 0) {
            showAlert('danger', 'No lines to delete');
            return;
        }
        if (!confirm('Are you sure you want to delete all lines?')) return;
        tbody.innerHTML = '<tr><td colspan="12" class="empty-state"><i class="fa fa-inbox"></i><p>No detail lines added. Click <strong>Add Line</strong> to start.</p></td></tr>';
        updateSummary();
    }

    function updateLineNumbers() {
        var rows = document.querySelectorAll('#detailTableBody tr.detail-row');
        rows.forEach(function (row, index) {
            var n = index + 1;
            var srl = row.querySelector('.srl-col');
            if (srl) srl.textContent = String(n).padStart(4, '0');
            var lineInp = row.querySelector('input[name="line_no[]"]');
            if (lineInp) lineInp.value = String(n);
        });
    }

    function roundMoney(value) {
        return Math.round((value + Number.EPSILON) * 100) / 100;
    }
    function getNumericInput(row, name) {
        var input = row.querySelector('input[name="' + name + '"]');
        return input ? (parseFloat(input.value) || 0) : 0;
    }
    function setInputValue(row, name, value, decimals) {
        var input = row.querySelector('input[name="' + name + '"]');
        if (input && input !== jcTypingBox) {
            input.value = Number(value || 0).toFixed(decimals);
        }
    }

    function recalcRow(row, changedName) {
        var qty = getNumericInput(row, 'qty[]');
        var rate = getNumericInput(row, 'rate[]');
        var lineTotal = roundMoney(qty * rate);

        var discountPct = getNumericInput(row, 'discount_pct[]');
        var discountAmt = getNumericInput(row, 'discount_amt[]');
        if (changedName === 'discount_amt[]') {
            discountAmt = Math.min(discountAmt, lineTotal);
            discountPct = 0;
        } else if (discountPct > 0) {
            discountPct = Math.min(discountPct, 100);
            discountAmt = roundMoney((lineTotal * discountPct) / 100);
        }
        setInputValue(row, 'discount_pct[]', discountPct, 2);
        setInputValue(row, 'discount_amt[]', discountAmt, 2);

        var afterDiscount = Math.max(0, roundMoney(lineTotal - discountAmt));
        var vatPct = getNumericInput(row, 'vat[]') || VAT_PCT;
        var vatAmount = roundMoney((afterDiscount * vatPct) / 100);
        setInputValue(row, 'vat_amount[]', vatAmount, 2);

        var net = roundMoney(afterDiscount + vatAmount);
        setInputValue(row, 'net_amount[]', net, 2);

        return {
            qty: qty,
            amount: lineTotal,
            discount: discountAmt,
            vat: vatAmount,
            net: net
        };
    }

    function updateSummary() {
        var rows = document.querySelectorAll('#detailTableBody tr.detail-row');
        var totalQty = 0, subtotal = 0, totalDiscount = 0, totalVat = 0, grandTotal = 0;

        rows.forEach(function (row) {
            var t = recalcRow(row);
            totalQty += t.qty;
            subtotal += t.amount;
            totalDiscount += t.discount;
            totalVat += t.vat;
            grandTotal += t.net;
        });

        document.getElementById('totalQty').textContent = totalQty.toFixed(2);
        document.getElementById('totalDiscount').textContent = totalDiscount.toFixed(2);
        document.getElementById('totalVat').textContent = totalVat.toFixed(2);
        document.getElementById('grandTotal').textContent = grandTotal.toFixed(2);

        var lineCountEl = document.getElementById('jcLineCount');
        if (lineCountEl) lineCountEl.textContent = '\u00b7 ' + rows.length + (rows.length === 1 ? ' line' : ' lines');
        var sumWordsEl = document.getElementById('jcSumWords');
        if (sumWordsEl) sumWordsEl.textContent = rows.length + (rows.length === 1 ? ' line' : ' lines') + ' \u00b7 ' + totalQty.toFixed(2) + ' units';

        function setHdr(name, val) {
            var el = document.querySelector('#jcForm input[name="' + name + '"]') || document.querySelector('input[name="' + name + '"]');
            if (el) el.value = val;
        }
        setHdr('amount', subtotal.toFixed(2));
        setHdr('discount', totalDiscount.toFixed(2));
        setHdr('vat_amount', totalVat.toFixed(2));
        setHdr('total_lc', grandTotal.toFixed(2));
    }

    function jcOpenDescMore(btn) {
        var row = btn.closest('tr');
        _jcDescInput = row.querySelector('textarea[name="item_desc[]"], input[name="item_desc[]"]');
        var ta = document.getElementById('jcDescText');
        ta.value = _jcDescInput ? _jcDescInput.value : '';
        ta.readOnly = JC_VIEW_MODE && _jcDescInput && _jcDescInput.readOnly;
        document.getElementById('jcDescSaveBtn').style.display = ta.readOnly ? 'none' : '';
        document.getElementById('jcDescModal').style.display = 'flex';
        ta.focus();
    }
    function jcSaveDescMore() {
        if (_jcDescInput && !_jcDescInput.readOnly) {
            // Keep exact line breaks from the More.. editor (same as Sales Quotation)
            _jcDescInput.value = document.getElementById('jcDescText').value;
        }
        jcCloseDescMore();
    }
    function jcCloseDescMore() {
        document.getElementById('jcDescModal').style.display = 'none';
        _jcDescInput = null;
    }

    function jcIsEditable() {
        var addBtn = document.getElementById('btnAddLine');
        return addBtn ? !addBtn.disabled : !JC_VIEW_MODE;
    }

    function jcOpenMoreData(btn) {
        _jcMoreRow = btn.closest('tr');
        function hv(name) {
            var el = _jcMoreRow.querySelector('input[name="' + name + '"]');
            return el ? el.value : '';
        }
        document.getElementById('jcMdEnquiry').value = hv('enquiry_no[]');
        document.getElementById('jcMdEstimate').value = hv('estimate_no[]');
        document.getElementById('jcMdQuotation').value = hv('quotation_no[]');
        document.getElementById('jcMdSalesorder').value = hv('salesorder_no[]');
        document.getElementById('jcMdPo').value = hv('line_po_no[]');
        document.getElementById('jcMdSerial').value = hv('item_serialno[]');
        document.getElementById('jcMdRemark').value = hv('item_remark[]');

        var editable = jcIsEditable();
        ['jcMdEnquiry','jcMdEstimate','jcMdQuotation','jcMdSalesorder','jcMdPo','jcMdSerial','jcMdRemark'].forEach(function (id) {
            var el = document.getElementById(id);
            if (el.tagName === 'TEXTAREA' || el.tagName === 'INPUT') {
                el.readOnly = !editable;
            }
        });
        document.getElementById('jcMdSaveBtn').style.display = editable ? '' : 'none';
        document.getElementById('jcMoreDataModal').style.display = 'flex';
        document.getElementById('jcMdEnquiry').focus();
    }
    function jcSaveMoreData() {
        if (!_jcMoreRow || !jcIsEditable()) {
            jcCloseMoreData();
            return;
        }
        function setHv(name, val) {
            var el = _jcMoreRow.querySelector('input[name="' + name + '"]');
            if (el) el.value = val;
        }
        setHv('enquiry_no[]', document.getElementById('jcMdEnquiry').value);
        setHv('estimate_no[]', document.getElementById('jcMdEstimate').value);
        setHv('quotation_no[]', document.getElementById('jcMdQuotation').value);
        setHv('salesorder_no[]', document.getElementById('jcMdSalesorder').value);
        setHv('line_po_no[]', document.getElementById('jcMdPo').value);
        setHv('item_serialno[]', document.getElementById('jcMdSerial').value);
        setHv('item_remark[]', document.getElementById('jcMdRemark').value);
        jcCloseMoreData();
    }
    function jcCloseMoreData() {
        document.getElementById('jcMoreDataModal').style.display = 'none';
        _jcMoreRow = null;
    }

    function openItemSearch(inputElement) {
        if (inputElement.readOnly && JC_VIEW_MODE) return;
        currentLineInput = inputElement;
        document.getElementById('itemSearchModal').style.display = 'flex';
        document.body.style.overflow = 'hidden';
        var searchInput = document.getElementById('itemSearchInput');
        searchInput.value = '';
        searchInput.focus();
        doItemFetch('');
    }
    function closeItemSearch() {
        document.getElementById('itemSearchModal').style.display = 'none';
        document.body.style.overflow = '';
        currentLineInput = null;
    }

    var itemSearchTimer;
    function doItemFetch(query) {
        var tbody = document.getElementById('itemResultsBody');
        tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:20px;color:#9ca3af;"><i class="fa fa-spinner fa-spin"></i> Loading...</td></tr>';
        var formData = new FormData();
        formData.append('action', 'search_items');
        formData.append('term', query);
        fetch(JC_DETAIL_URL, { method: 'POST', body: formData })
            .then(function (r) {
                if (!r.ok) throw new Error('Server returned ' + r.status);
                return r.json();
            })
            .then(function (data) { renderItemResults(data); })
            .catch(function (err) {
                console.error('Error fetching items:', err);
                tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:20px;color:red;">Network error — check login session</td></tr>';
            });
    }
    function searchItems(query) {
        clearTimeout(itemSearchTimer);
        itemSearchTimer = setTimeout(function () { doItemFetch(query); }, 300);
    }
    function renderItemResults(data) {
        var tbody = document.getElementById('itemResultsBody');
        if (data && data.error) {
            tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:20px;color:red;">Error: ' + data.error + '</td></tr>';
            return;
        }
        if (!Array.isArray(data) || data.length === 0) {
            tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:20px;color:#9ca3af;">No items found</td></tr>';
            return;
        }
        tbody.innerHTML = data.map(function (item) {
            var code = item.itemcode ? String(item.itemcode).replace(/'/g, "\\'") : '';
            var name = item.item_name ? String(item.item_name).replace(/'/g, "\\'") : '';
            var unit = item.stock_unit ? String(item.stock_unit).replace(/'/g, "\\'") : '';
            var stock = item.stockqty || '0';
            var rate = item.retail_price || '0';
            return '<tr>'
                + '<td>' + (item.itemcode || '') + '</td>'
                + '<td>' + (item.item_name || '') + '</td>'
                + '<td style="text-align:right;">' + stock + '</td>'
                + '<td style="text-align:center;"><button type="button" class="btn btn-primary btn-sm" onclick="selectItem(\'' + code + '\',\'' + name + '\',\'' + unit + '\',\'' + rate + '\')"><i class="fa fa-check"></i> Select</button></td>'
                + '</tr>';
        }).join('');
    }
    function selectItem(code, description, unit, rate) {
        if (!currentLineInput) return;
        var row = currentLineInput.closest('tr');
        var codeInput = row.querySelector('input[name="item_code[]"]');
        var descInput = row.querySelector('textarea[name="item_desc[]"], input[name="item_desc[]"]');
        var unitSel   = row.querySelector('select[name="unit[]"]');
        var rateInput = row.querySelector('input[name="rate[]"]');
        if (codeInput) codeInput.value = code;
        if (descInput) descInput.value = description;
        if (unitSel) unitSel.innerHTML = jcUnitOptions(unit || '');
        if (rateInput) rateInput.value = parseFloat(rate) || 0;
        updateSummary();
        closeItemSearch();
    }

    function jcBuildLineHtml(data, srl, lineNo) {
        var lid = 'line_qtn_' + Date.now() + '_' + Math.floor(Math.random() * 9000 + 1000);
        var srlPad = String(srl).padStart(4, '0');
        var unit = data.unit || '';
        var qty = Number(data.qty || 0);
        var rate = Number(data.rate || 0);
        var vatPct = (data.vat != null && data.vat !== '') ? Number(data.vat) : VAT_PCT;
        var discPct = 0;
        var discAmt = 0;
        var gross = Math.round((qty * rate + Number.EPSILON) * 100) / 100;
        var vatAmt = Math.round(((Math.max(0, gross - discAmt) * vatPct) / 100 + Number.EPSILON) * 100) / 100;
        var net = Math.round((gross - discAmt + vatAmt + Number.EPSILON) * 100) / 100;
        return '<tr data-line-id="' + lid + '" class="detail-row">'
            + '<td class="srl-col" data-label="Srl">' + jcEsc(srlPad) + '</td>'
            + '<td data-label="Line"><input type="text" class="form-control form-control-sm detail-input text-center" name="line_no[]" value="' + jcEsc(String(lineNo)) + '" readonly></td>'
            + '<td data-label="Code"><input type="text" class="form-control form-control-sm detail-input si-code" name="item_code[]" value="' + jcEsc(data.item_code || '') + '" readonly style="cursor:pointer;" onfocus="openItemSearch(this)" onclick="openItemSearch(this)"></td>'
            + '<td class="si-desc-cell" data-label="Description"><div class="detail-cell">'
            + '<textarea class="form-control form-control-sm detail-input si-desc" name="item_desc[]" rows="1">' + jcEsc(data.item_desc || '') + '</textarea>'
            + '<button type="button" class="detail-more" tabindex="-1" onclick="jcOpenDescMore(this)">More..</button></div></td>'
            + '<td data-label="Qty"><input type="number" class="form-control form-control-sm detail-input" name="qty[]" value="' + qty + '" style="text-align:right;"></td>'
            + '<td data-label="Unit"><select class="form-control form-control-sm detail-input text-center" name="unit[]">' + jcUnitOptions(unit) + '</select></td>'
            + '<td data-label="Rate"><input type="number" class="form-control form-control-sm detail-input" name="rate[]" value="' + rate.toFixed(2) + '" step="0.01" style="text-align:right;"></td>'
            + '<td data-label="Discount"><div class="detail-split-inputs">'
            + '<input type="number" class="form-control form-control-sm detail-input" name="discount_pct[]" value="0.00" step="0.01" style="text-align:center;">'
            + '<input type="number" class="form-control form-control-sm detail-input" name="discount_amt[]" value="0.00" step="0.01" style="text-align:right;">'
            + '</div></td>'
            + '<td data-label="VAT Amt"><input type="hidden" name="vat[]" value="' + vatPct + '">'
            + '<input type="text" class="form-control form-control-sm detail-input" name="vat_amount[]" value="' + vatAmt.toFixed(2) + '" readonly style="text-align:right;"></td>'
            + '<td data-label="Amount"><input type="text" class="form-control form-control-sm detail-input si-total" name="net_amount[]" value="' + net.toFixed(2) + '" readonly style="text-align:right;"></td>'
            + '<td data-label="More">'
            + '<input type="hidden" name="enquiry_no[]" value="">'
            + '<input type="hidden" name="estimate_no[]" value="">'
            + '<input type="hidden" name="quotation_no[]" value="' + jcEsc(data.quotation_no || '') + '">'
            + '<input type="hidden" name="salesorder_no[]" value="">'
            + '<input type="hidden" name="line_po_no[]" value="">'
            + '<input type="hidden" name="item_serialno[]" value="">'
            + '<input type="hidden" name="item_remark[]" value="' + jcEsc(data.remark || '') + '">'
            + '<button type="button" class="detail-more detail-more-data" tabindex="-1" onclick="jcOpenMoreData(this)"><i class="fa fa-list-alt"></i> More Data</button></td>'
            + '<td class="si-del" data-label=""><button type="button" class="btn btn-sm btn-danger" onclick="deleteDetailLine(this)" title="Delete line"><i class="fa fa-trash"></i></button></td>'
            + '<input type="hidden" name="line_id[]" value="' + lid + '">'
            + '</tr>';
    }

    /** Apply lines picked from Select QTN modal (called from header). */
    function jcApplyQtnLines(picked) {
        if (!Array.isArray(picked) || !picked.length) return;
        var tbody = document.getElementById('detailTableBody');
        var emptyRow = tbody.querySelector('tr td[colspan="12"]');
        if (emptyRow) emptyRow.closest('tr').remove();

        // Drop blank starter rows with no content
        Array.prototype.slice.call(tbody.querySelectorAll('tr.detail-row')).forEach(function (tr) {
            var code = (tr.querySelector('input[name="item_code[]"]') || {}).value || '';
            var descEl = tr.querySelector('textarea[name="item_desc[]"], input[name="item_desc[]"]');
            var desc = descEl ? descEl.value : '';
            var qty = parseFloat((tr.querySelector('input[name="qty[]"]') || {}).value) || 0;
            if (!String(code).trim() && !String(desc).trim() && qty === 0) tr.remove();
        });

        picked.forEach(function (p) {
            var existing = null;
            tbody.querySelectorAll('tr.detail-row').forEach(function (tr) {
                var qtn = (tr.querySelector('input[name="quotation_no[]"]') || {}).value || '';
                var code = (tr.querySelector('input[name="item_code[]"]') || {}).value || '';
                var line = (tr.querySelector('input[name="line_no[]"]') || {}).value || '';
                if (qtn === (p.quotation_no || '') && code === (p.item_code || '') && String(line) === String(p.item_no || '')) {
                    existing = tr;
                }
            });
            if (existing) {
                var qtyEl = existing.querySelector('input[name="qty[]"]');
                var rateEl = existing.querySelector('input[name="rate[]"]');
                var unitEl = existing.querySelector('select[name="unit[]"]');
                var remEl = existing.querySelector('input[name="item_remark[]"]');
                var descElUp = existing.querySelector('textarea[name="item_desc[]"], input[name="item_desc[]"]');
                if (qtyEl) qtyEl.value = p.qty;
                if (rateEl) rateEl.value = Number(p.rate || 0).toFixed(2);
                if (unitEl) unitEl.innerHTML = jcUnitOptions(p.unit || '');
                if (descElUp && (p.item_desc || '')) descElUp.value = p.item_desc;
                if (remEl && (p.remark || '') !== '') remEl.value = p.remark || '';
                return;
            }
            var next = tbody.querySelectorAll('tr.detail-row').length + 1;
            tbody.insertAdjacentHTML('beforeend', jcBuildLineHtml(p, next, p.item_no || next));
        });
        updateLineNumbers();
        updateSummary();
    }

    function getDetailLinesJson() {
        var rows = document.querySelectorAll('#detailTableBody tr.detail-row');
        var lines = [];
        rows.forEach(function (row) {
            function val(name) {
                var el = row.querySelector('[name="' + name + '"]');
                return el ? el.value : '';
            }
            var code = val('item_code[]').trim();
            // Preserve internal line breaks; only trim outer whitespace (same as Sales Quotation)
            var desc = String(val('item_desc[]') || '').replace(/^\s+|\s+$/g, '');
            var remark = val('item_remark[]').trim();
            var qty = parseFloat(val('qty[]')) || 0;
            if (code === '' && desc === '' && remark === '' && qty === 0) return;
            lines.push({
                item_code: code,
                item_desc: desc,
                remark: val('item_remark[]'),
                unit: val('unit[]'),
                qty: val('qty[]'),
                rate: val('rate[]'),
                vat: val('vat[]'),
                tax1_amount: val('vat_amount[]'),
                discount_pct: val('discount_pct[]'),
                discount_amt: val('discount_amt[]'),
                line_no: val('line_no[]'),
                enquiry_no: val('enquiry_no[]'),
                estimate_no: val('estimate_no[]'),
                quotation_no: val('quotation_no[]'),
                salesorder_no: val('salesorder_no[]'),
                po_no: val('line_po_no[]'),
                item_serialno: val('item_serialno[]')
            });
        });
        return JSON.stringify(lines);
    }

    document.addEventListener('input', function (e) {
        if (!e.target.classList.contains('detail-input')) return;
        var row = e.target.closest('tr.detail-row');
        if (!row) return;
        recalcRow(row, e.target.name);
        updateSummary();
    });

    var JC_DIGIT_DECIMALS = { 'qty[]': null, 'rate[]': null, 'discount_pct[]': 2, 'discount_amt[]': 2 };
    function isJcDigitBox(el) {
        return el && el.tagName === 'INPUT' && el.type === 'number' && !el.readOnly
            && JC_DIGIT_DECIMALS.hasOwnProperty(el.name) && el.closest('#detailTableBody');
    }
    document.addEventListener('focusin', function (e) {
        var el = e.target;
        if (!isJcDigitBox(el)) return;
        jcTypingBox = el;
        if (el.value !== '' && (parseFloat(el.value) || 0) === 0) {
            el.dataset.zeroValue = el.value;
            el.value = '';
        }
    });
    document.addEventListener('focusout', function (e) {
        var el = e.target;
        if (!isJcDigitBox(el)) return;
        jcTypingBox = null;
        var decimals = JC_DIGIT_DECIMALS[el.name];
        if (el.value === '') {
            el.value = el.dataset.zeroValue || (decimals === null ? '0' : (0).toFixed(decimals));
        } else if (decimals !== null) {
            el.value = (parseFloat(el.value) || 0).toFixed(decimals);
        }
        delete el.dataset.zeroValue;
        var row = el.closest('tr.detail-row');
        if (row) recalcRow(row, el.name);
        updateSummary();
    });

    document.addEventListener('DOMContentLoaded', function () {
        updateSummary();
    });
</script>

<?php if ($owns_output_buffer): ?>
</body>
</html>
<?php endif; ?>
