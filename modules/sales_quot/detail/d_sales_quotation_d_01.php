<?php
/**
 * Sales Quotation detail fragment — included by modules/sales_quot/sales_quot.php
 * Table: sales_quotation_d
 */
if (!isset($con)) {
    require('../../../db.php');
    include('../../../auth.php');
}
require_once __DIR__ . '/../../../includes/erp_paths.php';

$owns_output_buffer = !isset($con);
if ($owns_output_buffer) {
    ob_start();
}

$company_code = $_SESSION['company_code'] ?? '';
$branch_code  = $_SESSION['branch_code']  ?? '';
$user         = $_SESSION['username']     ?? '';

// Default stock location for item lookup (same pattern as Stock Transfer Out)
$sq_item_location = '';
try {
    $stmt_loc = $con->prepare("SELECT location_code FROM branch_user WHERE user_id = ? AND branch_code = ?");
    $stmt_loc->execute([$user, $branch_code]);
    $sq_item_location = trim((string) ($stmt_loc->fetchColumn() ?: ''));
} catch (Exception $e) {
    error_log('SQ location fetch error: ' . $e->getMessage());
}

// Unit list from item_unit (same source/UI as Material Request / Stock Transfer)
$unit_list = [];
try {
    $stmt_units = $con->prepare("SELECT stock_unit FROM item_unit ORDER BY stock_unit ASC");
    $stmt_units->execute([]);
    while ($u = $stmt_units->fetch(PDO::FETCH_ASSOC)) {
        $unit_list[] = trim($u['stock_unit']);
    }
} catch (Exception $e) {
    error_log('SQ item_unit fetch error: ' . $e->getMessage());
}

if (!function_exists('sq_unit_options_html')) {
    function sq_unit_options_html(array $unit_list, $selected = '')
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

// Blank detail line (Add Line AJAX + default line of a new quotation)
if (!function_exists('sq_blank_line')) {
    function sq_blank_line($con, $company_code, $branch_code, $srl = 1, $line_no = 1, array $unit_list = [])
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
            error_log('SQ Branch master VAT fetch error: ' . $e->getMessage());
        }

        $line_id = 'line_' . time() . '_' . rand(1000, 9999);
        $srl_pad = str_pad((string) $srl, 4, '0', STR_PAD_LEFT);
        $unit_opts = sq_unit_options_html($unit_list, '');

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
                    <button type="button" class="detail-more" tabindex="-1" onclick="sqOpenDescMore(this)">More..</button>
                </div>
            </td>
            <td data-label="Remark">
                <input type="hidden" name="item_remark[]" value="">
                <button type="button" class="detail-more" tabindex="-1" onclick="sqOpenRemarkMore(this)">More..</button>
            </td>
            <td data-label="Qty"><input type="number" class="form-control form-control-sm detail-input" name="qty[]" placeholder="0" value="0" style="text-align:right;"></td>
            <td data-label="Unit" class="sq-unit-col"><select class="form-control form-control-sm detail-input text-center" name="unit[]">' . $unit_opts . '</select></td>
            <td data-label="Rate"><input type="number" class="form-control form-control-sm detail-input" name="rate[]" placeholder="0.00" value="0.00" step="0.01" style="text-align:right;"></td>
            <td data-label="Amount"><input type="text" class="form-control form-control-sm detail-input si-amount" name="amount[]" placeholder="0.00" readonly style="text-align:right;"></td>
            <td data-label="Discount">
                <div class="detail-split-inputs">
                    <input type="number" class="form-control form-control-sm detail-input" name="discount_pct[]" placeholder="%" value="0" step="0.01" style="text-align:center;">
                    <input type="number" class="form-control form-control-sm detail-input" name="discount_amt[]" placeholder="Amt" value="0.00" step="0.01" style="text-align:right;">
                </div>
            </td>
            <td data-label="VAT">
                <div class="detail-split-inputs">
                    <input type="number" class="form-control form-control-sm detail-input" name="vat[]" value="' . htmlspecialchars($vat_pct) . '" step="0.01" style="text-align:center;">
                    <input type="text" class="form-control form-control-sm detail-input" name="vat_amount[]" placeholder="0.00" readonly style="text-align:right;">
                </div>
            </td>
            <td data-label="Net"><input type="text" class="form-control form-control-sm detail-input si-total" name="net_amount[]" placeholder="0.00" readonly style="text-align:right;"></td>
            <td class="si-del" data-label=""><button type="button" class="btn btn-sm btn-danger" onclick="deleteDetailLine(this)" title="Delete line"><i class="fa fa-trash"></i></button></td>
            <input type="hidden" name="line_id[]" value="' . $line_id . '">
        </tr>',
        ];
    }
}

// Handle Add Detail Line (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_line') {
    if ($owns_output_buffer && ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json');
    $srl = max(1, (int) ($_POST['next_srl'] ?? 1));
    echo json_encode(sq_blank_line($con, $company_code, $branch_code, $srl, $srl, $unit_list));
    exit;
}

// Handle Item Search (AJAX) — same approach as Stock Transfer Out (item_master + optional stock)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'search_items') {
    if ($owns_output_buffer && ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');

    $term = '%' . trim($_POST['term'] ?? '') . '%';
    $loc  = trim($_POST['location'] ?? $sq_item_location);

    try {
        $sql = "SELECT TOP 200 i.item_code AS itemcode,
                       i.item_name,
                       i.stock_unit,
                       i.oem_code,
                       ISNULL(v.stockqty, 0) AS stockqty,
                       ISNULL((SELECT TOP 1 retail_price FROM item_price
                                WHERE item_code = i.item_code), 0) AS retail_price
                FROM item_master i
                LEFT JOIN view_item_ledger_summary v
                  ON i.item_code = v.itemcode AND v.locationcode = ?
                WHERE i.item_code LIKE ? OR i.item_name LIKE ? OR ISNULL(i.oem_code, '') LIKE ?
                ORDER BY i.item_code";
        $stmt = $con->prepare($sql);
        $stmt->execute([$loc, $term, $term, $term]);
        $items = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $items[] = [
                'itemcode'     => trim((string) $row['itemcode']),
                'item_name'    => trim((string) ($row['item_name'] ?? '')),
                'stock_unit'   => trim((string) ($row['stock_unit'] ?? '')),
                'oem_code'     => trim((string) ($row['oem_code'] ?? '')),
                'stockqty'     => (float) ($row['stockqty'] ?? 0),
                'retail_price' => (float) ($row['retail_price'] ?? 0),
            ];
        }
        echo json_encode($items);
    } catch (Exception $e) {
        error_log('SQ Item search error: ' . $e->getMessage());
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// Load existing sales_quotation_d lines
$existing_detail_lines = [];
$_detail_doc_no = (isset($doc_no) && $doc_no !== '') ? $doc_no
                : (isset($_GET['doc_no']) ? trim($_GET['doc_no']) : '');
if ($_detail_doc_no !== '') {
    try {
        $stmt_ld = $con->prepare(
            "SELECT * FROM sales_quotation_d
             WHERE company_code = ? AND branch_code = ? AND doc_no = ?
             ORDER BY serial_no ASC"
        );
        $stmt_ld->execute([$company_code, $branch_code, $_detail_doc_no]);
        $existing_detail_lines = $stmt_ld->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log('Load sales_quotation_d error: ' . $e->getMessage());
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
    <title>Quotation Detail</title>
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
        <h2>Items <span class="si-line-count" id="sqLineCount"></span></h2>
    </div>

    <div class="detail-table-wrapper">
        <form id="detailForm">
            <table class="detail-table si-lines">
                <thead>
                    <tr>
                        <th style="width:48px;">Srl</th>
                        <th style="width:48px;">Line</th>
                        <th style="width:96px;">Code</th>
                        <th style="min-width:200px;">Description</th>
                        <th style="width:72px;">Remark</th>
                        <th style="width:72px;" class="num">Qty</th>
                        <th style="min-width:88px;width:96px;">Unit</th>
                        <th style="width:84px;" class="num">Rate</th>
                        <th style="width:92px;" class="num">Amount</th>
                        <th style="width:130px;" class="split">Disc % / Amt</th>
                        <th style="width:120px;" class="split">VAT % / Amt</th>
                        <th style="width:96px;" class="num">Net Amount</th>
                        <th style="width:34px;"></th>
                    </tr>
                </thead>
                <tbody id="detailTableBody">
<?php if (!empty($existing_detail_lines)): ?>
<?php foreach ($existing_detail_lines as $_di => $_dl): ?>
<?php
    $_dl_qty      = (float) ($_dl['trans_qty'] ?? 0);
    $_dl_rate     = (float) ($_dl['trans_rate'] ?? 0);
    $_dl_disc_pct = (float) ($_dl['discount_percent'] ?? 0);
    $_dl_disc_amt = (float) ($_dl['discount_amount'] ?? 0);
    $_dl_vat_pct  = trim((string) ($_dl['tax1_percent'] ?? '0'));
    $_dl_vat_amt  = (float) ($_dl['tax1_amount'] ?? 0);
    $_dl_amount   = round($_dl_qty * $_dl_rate, 2);
    if ($_dl_disc_amt <= 0 && $_dl_disc_pct > 0) {
        $_dl_disc_amt = round(($_dl_amount * $_dl_disc_pct) / 100, 2);
    }
    $_dl_net      = round($_dl_amount - $_dl_disc_amt + $_dl_vat_amt, 2);
    $_dl_lid      = 'line_db_' . ($_di + 1);
    $_dl_srl      = trim((string) ($_dl['serial_no'] ?? ''));
    if ($_dl_srl === '') {
        $_dl_srl = str_pad((string) ($_di + 1), 4, '0', STR_PAD_LEFT);
    }
    $_dl_line     = trim((string) ($_dl['item_no'] ?? ''));
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
             <?php echo $_detail_view ? 'readonly' : 'readonly onfocus="openItemSearch(this)" onclick="openItemSearch(this)"'; ?>
             style="cursor:<?php echo $_detail_view ? 'default' : 'pointer'; ?>;"></td>
    <td class="si-desc-cell" data-label="Description">
        <div class="detail-cell">
            <textarea class="form-control form-control-sm detail-input si-desc" name="item_desc[]" rows="1" <?php echo $_dl_desc_ro; ?>><?php echo htmlspecialchars($_dl['item_description'] ?? ''); ?></textarea>
            <button type="button" class="detail-more" tabindex="-1" onclick="sqOpenDescMore(this)">More..</button>
        </div>
    </td>
    <td data-label="Remark">
        <input type="hidden" name="item_remark[]" value="<?php echo htmlspecialchars($_dl['remark'] ?? ''); ?>">
        <button type="button" class="detail-more" tabindex="-1" onclick="sqOpenRemarkMore(this)">More..</button>
    </td>
    <td data-label="Qty"><input type="number" class="form-control form-control-sm detail-input" name="qty[]" value="<?php echo htmlspecialchars((string) $_dl_qty); ?>" <?php echo $_dl_ro; ?> style="text-align:right;"></td>
    <td data-label="Unit" class="sq-unit-col"><select class="form-control form-control-sm detail-input text-center" name="unit[]" <?php echo $_detail_view ? 'disabled' : ''; ?>><?php echo sq_unit_options_html($unit_list, $_dl_unit); ?></select></td>
    <td data-label="Rate"><input type="number" class="form-control form-control-sm detail-input" name="rate[]" value="<?php echo htmlspecialchars((string) $_dl_rate); ?>" <?php echo $_dl_ro; ?> step="0.01" style="text-align:right;"></td>
    <td data-label="Amount"><input type="text" class="form-control form-control-sm detail-input si-amount" name="amount[]" value="<?php echo htmlspecialchars(number_format($_dl_amount, 2, '.', '')); ?>" readonly style="text-align:right;"></td>
    <td data-label="Discount">
        <div class="detail-split-inputs">
            <input type="number" class="form-control form-control-sm detail-input" name="discount_pct[]" value="<?php echo htmlspecialchars(number_format($_dl_disc_pct, 2, '.', '')); ?>" <?php echo $_dl_ro; ?> step="0.01" style="text-align:center;">
            <input type="number" class="form-control form-control-sm detail-input" name="discount_amt[]" value="<?php echo htmlspecialchars(number_format($_dl_disc_amt, 2, '.', '')); ?>" <?php echo $_dl_ro; ?> step="0.01" style="text-align:right;">
        </div>
    </td>
    <td data-label="VAT">
        <div class="detail-split-inputs">
            <input type="number" class="form-control form-control-sm detail-input" name="vat[]" value="<?php echo htmlspecialchars($_dl_vat_pct); ?>" <?php echo $_dl_ro; ?> step="0.01" style="text-align:center;">
            <input type="text" class="form-control form-control-sm detail-input" name="vat_amount[]" value="<?php echo htmlspecialchars(number_format($_dl_vat_amt, 2, '.', '')); ?>" readonly style="text-align:right;">
        </div>
    </td>
    <td data-label="Net"><input type="text" class="form-control form-control-sm detail-input si-total" name="net_amount[]" value="<?php echo htmlspecialchars(number_format($_dl_net, 2, '.', '')); ?>" readonly style="text-align:right;"></td>
    <td class="si-del"><button type="button" class="btn btn-sm btn-danger" title="Delete line" onclick="deleteDetailLine(this)" <?php echo $_detail_view ? 'style="display:none;"' : ''; ?>><i class="fa fa-trash"></i></button></td>
    <input type="hidden" name="line_id[]" value="<?php echo $_dl_lid; ?>">
</tr>
<?php endforeach; ?>
<?php elseif (!$_detail_view): ?>
<?php $_blank_line = sq_blank_line($con, $company_code, $branch_code, 1, 1, $unit_list); echo $_blank_line['html']; ?>
<?php else: ?>
                    <tr id="detailEmptyState">
                        <td colspan="13" class="empty-state">
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
            <span class="summary-label">Amount</span>
            <span class="summary-value" id="subtotal">0.00</span>
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
            <span class="summary-label">Net Amount</span>
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
<div id="sqDescModal" class="popup-overlay erp-search" style="display:none;">
    <div class="erp-search-dialog sm" role="dialog" aria-modal="true">
        <div class="erp-search-head">
            <h4 class="erp-search-title"><i class="fa fa-align-left"></i>Description</h4>
            <button type="button" class="erp-search-close" onclick="sqCloseDescMore()" title="Close" aria-label="Close"><i class="fa fa-times"></i></button>
        </div>
        <div class="erp-search-content">
            <textarea id="sqDescText" class="erp-search-textarea" rows="6"></textarea>
        </div>
        <div class="erp-search-foot">
            <button type="button" class="erp-search-btn" onclick="sqCloseDescMore()">Close</button>
            <button type="button" class="erp-search-btn primary" id="sqDescSaveBtn" onclick="sqSaveDescMore()">Save</button>
        </div>
    </div>
</div>

<!-- Remark More.. Modal -->
<div id="sqRemarkModal" class="popup-overlay erp-search" style="display:none;">
    <div class="erp-search-dialog sm" role="dialog" aria-modal="true">
        <div class="erp-search-head">
            <h4 class="erp-search-title"><i class="fa fa-comment-o"></i>Remark</h4>
            <button type="button" class="erp-search-close" onclick="sqCloseRemarkMore()" title="Close" aria-label="Close"><i class="fa fa-times"></i></button>
        </div>
        <div class="erp-search-content">
            <textarea id="sqRemarkText" class="erp-search-textarea" rows="8" placeholder="Type remark..."></textarea>
        </div>
        <div class="erp-search-foot">
            <button type="button" class="erp-search-btn" onclick="sqCloseRemarkMore()">Close</button>
            <button type="button" class="erp-search-btn primary" id="sqRemarkSaveBtn" onclick="sqSaveRemarkMore()">Save</button>
        </div>
    </div>
</div>

<style>
.detail-table.si-lines th:nth-child(7),
.detail-table.si-lines td.sq-unit-col,
.detail-table.si-lines td[data-label="Unit"] {
    min-width: 88px;
    width: 96px;
}
.detail-table.si-lines td.sq-unit-col select.detail-input {
    min-width: 80px;
    width: 100%;
}
.detail-table.si-lines textarea.detail-input.si-desc {
    white-space: pre-wrap;
    overflow-wrap: anywhere;
}
</style>
<script>
    var SQ_DETAIL_URL = <?php echo json_encode(erp_url('modules/sales_quot/detail/d_sales_quotation_d_01')); ?>;
    var SQ_ITEM_LOC = <?php echo json_encode($sq_item_location); ?>;
    var SQ_UNITS = <?php echo json_encode($unit_list); ?>;
    var VAT_PCT = <?php
        $js_vat = 0;
        try {
            $stmt_bm2 = $con->prepare("SELECT tax1_percent FROM branch_master WHERE company_code = ? AND branch_code = ?");
            $stmt_bm2->execute([$company_code, $branch_code]);
            $row_bm2 = $stmt_bm2->fetch(PDO::FETCH_ASSOC);
            if ($row_bm2) { $js_vat = (float) ($row_bm2['tax1_percent'] ?? 0); }
        } catch (Exception $e) { error_log('SQ Branch VAT JS var error: ' . $e->getMessage()); }
        echo json_encode($js_vat);
    ?>;
    var SQ_VIEW_MODE = <?php echo $_detail_view ? 'true' : 'false'; ?>;
    var lineCounter = 0;
    var currentLineInput = null;
    var _sqDescInput = null;
    var _sqRemarkInput = null;
    var sqTypingBox = null;

    function sqEsc(v) {
        return String(v == null ? '' : v)
            .replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;')
            .replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }
    function sqUnitOptions(selected) {
        var list = SQ_UNITS.slice();
        if (selected && list.indexOf(selected) < 0) list.push(selected);
        return '<option value="">--</option>' + list.map(function (u) {
            return '<option value="' + sqEsc(u) + '"' + (u === selected ? ' selected' : '') + '>' + sqEsc(u) + '</option>';
        }).join('');
    }

    function showAlert(type, message) {
        console.error('[' + type + ']', message);
        alert(message);
    }

    function addDetailLine() {
        var tbody = document.getElementById('detailTableBody');
        var emptyRow = tbody.querySelector('tr td[colspan="13"]');
        if (emptyRow) emptyRow.closest('tr').remove();

        var nextSrl = tbody.querySelectorAll('tr.detail-row').length + 1;
        var formData = new FormData();
        formData.append('action', 'add_line');
        formData.append('next_srl', String(nextSrl));

        fetch(SQ_DETAIL_URL, { method: 'POST', body: formData })
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
        tbody.innerHTML = '<tr><td colspan="13" class="empty-state"><i class="fa fa-inbox"></i><p>No detail lines added. Click <strong>Add Line</strong> to start.</p></td></tr>';
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
        if (input && input !== sqTypingBox) {
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

        setInputValue(row, 'amount[]', lineTotal, 2);

        var afterDiscount = Math.max(0, roundMoney(lineTotal - discountAmt));
        // Honour 0% VAT (do not fall back to branch default when user clears/sets 0)
        var vatEl = row.querySelector('input[name="vat[]"]');
        var vatPct = vatEl ? (parseFloat(vatEl.value) || 0) : VAT_PCT;
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
        document.getElementById('subtotal').textContent = subtotal.toFixed(2);
        document.getElementById('totalDiscount').textContent = totalDiscount.toFixed(2);
        document.getElementById('totalVat').textContent = totalVat.toFixed(2);
        document.getElementById('grandTotal').textContent = grandTotal.toFixed(2);

        var lineCountEl = document.getElementById('sqLineCount');
        if (lineCountEl) lineCountEl.textContent = '\u00b7 ' + rows.length + (rows.length === 1 ? ' line' : ' lines');
        var sumWordsEl = document.getElementById('sqSumWords');
        if (sumWordsEl) sumWordsEl.textContent = rows.length + (rows.length === 1 ? ' line' : ' lines') + ' \u00b7 ' + totalQty.toFixed(2) + ' units';

        function setHdr(name, val) {
            var el = document.querySelector('#sqForm input[name="' + name + '"]') || document.querySelector('input[name="' + name + '"]');
            if (el) el.value = val;
        }
        setHdr('amount', subtotal.toFixed(2));
        setHdr('discount', totalDiscount.toFixed(2));
        setHdr('vat_amount', totalVat.toFixed(2));
        setHdr('total_lc', grandTotal.toFixed(2));
    }

    // ── Description More.. ──────────────────────────────────────────────────
    function sqOpenDescMore(btn) {
        var row = btn.closest('tr');
        _sqDescInput = row.querySelector('textarea[name="item_desc[]"], input[name="item_desc[]"]');
        var ta = document.getElementById('sqDescText');
        ta.value = _sqDescInput ? _sqDescInput.value : '';
        ta.readOnly = SQ_VIEW_MODE && _sqDescInput && _sqDescInput.readOnly;
        document.getElementById('sqDescSaveBtn').style.display = ta.readOnly ? 'none' : '';
        document.getElementById('sqDescModal').style.display = 'flex';
        ta.focus();
    }
    function sqSaveDescMore() {
        if (_sqDescInput && !_sqDescInput.readOnly) {
            // Keep exact line breaks from the More.. editor
            _sqDescInput.value = document.getElementById('sqDescText').value;
        }
        sqCloseDescMore();
    }
    function sqCloseDescMore() {
        document.getElementById('sqDescModal').style.display = 'none';
        _sqDescInput = null;
    }

    // ── Remark More.. ───────────────────────────────────────────────────────
    function sqOpenRemarkMore(btn) {
        var td = btn.closest('td');
        _sqRemarkInput = td.querySelector('input[name="item_remark[]"]');
        var ta = document.getElementById('sqRemarkText');
        ta.value = _sqRemarkInput ? _sqRemarkInput.value : '';
        // Editable when Add Line is enabled (new doc, or after Edit unlock)
        var addBtn = document.getElementById('btnAddLine');
        var editable = addBtn ? !addBtn.disabled : !SQ_VIEW_MODE;
        ta.readOnly = !editable;
        document.getElementById('sqRemarkSaveBtn').style.display = editable ? '' : 'none';
        document.getElementById('sqRemarkModal').style.display = 'flex';
        ta.focus();
    }
    function sqSaveRemarkMore() {
        if (_sqRemarkInput && !document.getElementById('sqRemarkText').readOnly) {
            _sqRemarkInput.value = document.getElementById('sqRemarkText').value;
        }
        sqCloseRemarkMore();
    }
    function sqCloseRemarkMore() {
        document.getElementById('sqRemarkModal').style.display = 'none';
        _sqRemarkInput = null;
    }

    // ── Item search (Stock Transfer Out style) ──────────────────────────────
    var sqItemResults = [];
    function openItemSearch(inputElement) {
        if (SQ_VIEW_MODE && inputElement.readOnly) return;
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
        formData.append('location', SQ_ITEM_LOC || '');
        fetch(SQ_DETAIL_URL, { method: 'POST', body: formData, credentials: 'same-origin' })
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
            tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:20px;color:red;">Error: ' + sqEsc(data.error) + '</td></tr>';
            return;
        }
        if (!Array.isArray(data) || data.length === 0) {
            tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:20px;color:#9ca3af;">No items found</td></tr>';
            return;
        }
        sqItemResults = data;
        tbody.innerHTML = data.map(function (item, i) {
            return '<tr>'
                + '<td>' + sqEsc(item.itemcode || '') + '</td>'
                + '<td>' + sqEsc(item.item_name || '') + '</td>'
                + '<td style="text-align:right;">' + (parseFloat(item.stockqty) || 0).toFixed(2) + '</td>'
                + '<td style="text-align:center;"><button type="button" class="btn btn-primary btn-sm" onclick="selectItemIdx(' + i + ')"><i class="fa fa-check"></i> Select</button></td>'
                + '</tr>';
        }).join('');
    }
    function selectItemIdx(i) {
        var item = sqItemResults[i];
        if (!item || !currentLineInput) { closeItemSearch(); return; }
        selectItem(item.itemcode || '', item.item_name || '', item.stock_unit || '', item.retail_price || 0);
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
        if (unitSel) unitSel.innerHTML = sqUnitOptions(unit || '');
        if (rateInput) rateInput.value = parseFloat(rate) || 0;
        updateSummary();
        closeItemSearch();
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
            // Preserve internal line breaks; only trim outer whitespace
            var desc = String(val('item_desc[]') || '').replace(/^\s+|\s+$/g, '');
            var remark = val('item_remark[]').trim();
            var qty = parseFloat(val('qty[]')) || 0;
            // Keep free-text quotation lines (no item code) when they have content
            if (code === '' && desc === '' && remark === '' && qty === 0) return;
            lines.push({
                item_code: code,
                item_desc: val('item_desc[]'),
                remark: val('item_remark[]'),
                unit: val('unit[]'),
                qty: val('qty[]'),
                rate: val('rate[]'),
                vat: val('vat[]'),
                tax1_amount: val('vat_amount[]'),
                discount_pct: val('discount_pct[]'),
                discount_amt: val('discount_amt[]'),
                line_no: val('line_no[]')
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

    var SQ_DIGIT_DECIMALS = { 'qty[]': null, 'rate[]': null, 'discount_pct[]': 2, 'discount_amt[]': 2, 'vat[]': 2 };
    function isSqDigitBox(el) {
        return el && el.tagName === 'INPUT' && el.type === 'number' && !el.readOnly
            && SQ_DIGIT_DECIMALS.hasOwnProperty(el.name) && el.closest('#detailTableBody');
    }
    document.addEventListener('focusin', function (e) {
        var el = e.target;
        if (!isSqDigitBox(el)) return;
        sqTypingBox = el;
        if (el.value !== '' && (parseFloat(el.value) || 0) === 0) {
            el.dataset.zeroValue = el.value;
            el.value = '';
        }
    });
    document.addEventListener('focusout', function (e) {
        var el = e.target;
        if (!isSqDigitBox(el)) return;
        sqTypingBox = null;
        var decimals = SQ_DIGIT_DECIMALS[el.name];
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
