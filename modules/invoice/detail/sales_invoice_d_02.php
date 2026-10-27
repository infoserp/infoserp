<?php
/**
 * Sales Invoice detail format 02 — included by modules/invoice/invoice.php
 * Fields: Srl, Line, Item Code, Description (+More), Remark, Loc, Stock, Qty, Unit,
 *         Rate, Amount, Discount, VAT Amount, Net Amount, PO No, DO No, SO No, Job No
 * Same save/search/select-job behaviour as sales_invoice_d_01.php; layout wraps (no H-scroll).
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

if (!function_exists('si02_h')) {
    function si02_h($v)
    {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('si02_line_card_html')) {
    /**
     * Card-layout line HTML (preview UI). Keeps field names used by save/JS.
     * @param array $o keys: line_id, srl, line_no, item_code, item_desc, remark, location,
     *   stock, qty, unit, rate, amount, discount_pct, discount_amt, vat_pct, vat_amount,
     *   net, po_no, do_no, so_no, job_no, qtn_no, account, job_item, readonly, view_mode
     */
    function si02_line_card_html(array $o)
    {
        $lid = $o['line_id'] ?? ('line_' . time() . '_' . rand(1000, 9999));
        $srl = $o['srl'] ?? '0001';
        $lineNo = $o['line_no'] ?? '1';
        $ro = !empty($o['readonly']) ? 'readonly' : '';
        $view = !empty($o['view_mode']);
        $codeClick = $view
            ? 'readonly'
            : 'readonly style="cursor:pointer;" onfocus="openItemSearch(this)" onclick="openItemSearch(this)"';
        $delStyle = $view ? ' style="display:none;"' : '';
        $jobItem = isset($o['job_item']) ? ' data-job-item="' . si02_h($o['job_item']) . '"' : '';
        $remark = (string) ($o['remark'] ?? '');

        return '
<article class="detail-row si02-line" data-line-id="' . si02_h($lid) . '"' . $jobItem . '>
  <input type="hidden" name="line_id[]" value="' . si02_h($lid) . '">
  <input type="hidden" name="account[]" value="' . si02_h($o['account'] ?? '') . '">
  <input type="hidden" name="excise_rate[]" value="0">
  <input type="hidden" name="excise_tax_amt[]" value="0">
  <input type="hidden" name="vat[]" value="' . si02_h($o['vat_pct'] ?? '0') . '">
  <input type="hidden" name="item_remark[]" value="' . si02_h($remark) . '">
  <input type="hidden" name="line_no[]" value="' . si02_h($lineNo) . '">

  <div class="si02-line-head">
    <div class="si02-line-meta">
      <span class="srl-col si02-srl">' . si02_h($srl) . '</span>
      <span class="si02-line-label">Line <span class="si02-line-no">' . si02_h($lineNo) . '</span></span>
    </div>
    <button type="button" class="btn btn-sm btn-danger si02-del" title="Delete line" aria-label="Delete line" onclick="deleteDetailLine(this)"' . $delStyle . '><i class="fa fa-trash"></i></button>
  </div>

  <div class="si02-grid">
    <div class="si02-field si02-code">
      <label class="si02-lbl">Item Code</label>
      <input type="text" class="form-control form-control-sm detail-input si-code" name="item_code[]" value="' . si02_h($o['item_code'] ?? '') . '" placeholder="Select item" ' . $codeClick . '>
    </div>
    <div class="si02-field si02-desc">
      <label class="si02-lbl">Description</label>
      <div class="si02-desc-row">
        <input type="text" class="form-control form-control-sm detail-input si-desc" name="item_desc[]" value="' . si02_h($o['item_desc'] ?? '') . '" placeholder="Description" ' . $ro . '>
        <button type="button" class="si02-btn-ghost" tabindex="-1" onclick="si02OpenDescMore(this)">More..</button>
      </div>
    </div>
    <div class="si02-field">
      <label class="si02-lbl">Qty</label>
      <input type="number" class="form-control form-control-sm detail-input si02-num" name="qty[]" value="' . si02_h($o['qty'] ?? '0') . '" ' . $ro . '>
    </div>
    <div class="si02-field">
      <label class="si02-lbl">Unit</label>
      <input type="text" class="form-control form-control-sm detail-input si02-ro" name="unit[]" value="' . si02_h($o['unit'] ?? '') . '" placeholder="Unit" readonly>
    </div>
    <div class="si02-field">
      <label class="si02-lbl">Rate</label>
      <input type="number" class="form-control form-control-sm detail-input si02-num" name="rate[]" value="' . si02_h($o['rate'] ?? '0.00') . '" step="0.01" ' . $ro . '>
    </div>
    <div class="si02-field">
      <label class="si02-lbl">Amount</label>
      <input type="text" class="form-control form-control-sm detail-input si-amount si02-ro si02-num" name="total[]" value="' . si02_h($o['amount'] ?? '0.00') . '" readonly>
    </div>
    <div class="si02-field si02-disc">
      <label class="si02-lbl">Discount</label>
      <div class="si02-disc-row">
        <input type="number" class="form-control form-control-sm detail-input si02-num" name="discount_pct[]" value="' . si02_h($o['discount_pct'] ?? '0') . '" step="0.01" placeholder="%" ' . $ro . '>
        <input type="number" class="form-control form-control-sm detail-input si02-num" name="discount_amt[]" value="' . si02_h($o['discount_amt'] ?? '0.00') . '" step="0.01" placeholder="Amt" ' . $ro . '>
      </div>
    </div>
    <div class="si02-field">
      <label class="si02-lbl">VAT Amount</label>
      <input type="text" class="form-control form-control-sm detail-input si02-ro si02-num" name="vat_amount[]" value="' . si02_h($o['vat_amount'] ?? '0.00') . '" readonly>
    </div>
    <div class="si02-field si02-net">
      <label class="si02-lbl">Net Amount</label>
      <input type="text" class="form-control form-control-sm detail-input si-total si02-ro si02-num si02-net-inp" name="total_with_vat[]" value="' . si02_h($o['net'] ?? '0.00') . '" readonly>
    </div>
  </div>

  <div class="si02-more">
    <button type="button" class="si02-more-toggle" aria-expanded="false" onclick="si02ToggleLineDetails(this)">
      <i class="fa fa-chevron-right" aria-hidden="true"></i>
      Line Details
      <span>Loc · Stock · Remark · QTN / PO / DO / SO / Job</span>
    </button>
    <div class="si02-more-panel" hidden>
      <div class="si02-more-grid">
        <div class="si02-field si02-remark">
          <label class="si02-lbl">Remark</label>
          <div class="si02-remark-row">
            <input type="text" class="form-control form-control-sm si02-remark-preview" value="' . si02_h($remark) . '" readonly tabindex="-1">
            <button type="button" class="si02-btn-ghost si02-remark-btn" tabindex="-1" aria-label="Edit remark" onclick="si02OpenRemarkMore(this)"><i class="fa fa-comment-o"></i></button>
          </div>
        </div>
        <div class="si02-field">
          <label class="si02-lbl">Loc</label>
          <input type="text" class="form-control form-control-sm detail-input" name="location[]" value="' . si02_h($o['location'] ?? '') . '" placeholder="Loc" readonly style="text-align:center;">
        </div>
        <div class="si02-field">
          <label class="si02-lbl">Stock</label>
          <input type="text" class="form-control form-control-sm detail-input si02-ro si02-num" name="stock[]" value="' . si02_h($o['stock'] ?? '0') . '" readonly>
        </div>
        <div class="si02-field">
          <label class="si02-lbl">QTN No.</label>
          <input type="text" class="form-control form-control-sm detail-input" name="line_qtn_no[]" value="' . si02_h($o['qtn_no'] ?? '') . '" placeholder="QTN" ' . $ro . '>
        </div>
        <div class="si02-field">
          <label class="si02-lbl">PO No.</label>
          <input type="text" class="form-control form-control-sm detail-input" name="line_po_no[]" value="' . si02_h($o['po_no'] ?? '') . '" placeholder="PO" ' . $ro . '>
        </div>
        <div class="si02-field">
          <label class="si02-lbl">DO No.</label>
          <input type="text" class="form-control form-control-sm detail-input" name="line_do_no[]" value="' . si02_h($o['do_no'] ?? '') . '" placeholder="DO" ' . $ro . '>
        </div>
        <div class="si02-field">
          <label class="si02-lbl">SO No.</label>
          <input type="text" class="form-control form-control-sm detail-input" name="line_so_no[]" value="' . si02_h($o['so_no'] ?? '') . '" placeholder="SO" ' . $ro . '>
        </div>
        <div class="si02-field">
          <label class="si02-lbl">Job No.</label>
          <input type="text" class="form-control form-control-sm detail-input" name="job_no[]" value="' . si02_h($o['job_no'] ?? '') . '" placeholder="Job" ' . $ro . '>
        </div>
      </div>
    </div>
  </div>
</article>';
    }
}

if (!function_exists('si02_blank_line')) {
    function si02_blank_line($con, $company_code, $branch_code, $srl = 1, $line_no = 1)
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
            error_log('SI02 Branch VAT fetch error: ' . $e->getMessage());
        }

        $line_id = 'line_' . time() . '_' . rand(1000, 9999);
        $srl_pad = str_pad((string) $srl, 4, '0', STR_PAD_LEFT);

        return [
            'success' => true,
            'line_id' => $line_id,
            'vat_pct' => $vat_pct,
            'html'    => si02_line_card_html([
                'line_id' => $line_id,
                'srl'     => $srl_pad,
                'line_no' => (string) (int) $line_no,
                'vat_pct' => $vat_pct,
                'qty'     => '0',
                'rate'    => '0.00',
                'amount'  => '0.00',
                'discount_pct' => '0',
                'discount_amt' => '0.00',
                'vat_amount' => '0.00',
                'net'     => '0.00',
                'stock'   => '0',
            ]),
        ];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_line') {
    if ($owns_output_buffer && ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json');
    $srl = max(1, (int) ($_POST['next_srl'] ?? 1));
    echo json_encode(si02_blank_line($con, $company_code, $branch_code, $srl, $srl));
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
        error_log('SI02 Item search error: ' . $e->getMessage());
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
            "SELECT * FROM invoice_d
             WHERE company_code = ? AND branch_code = ? AND doc_no = ?
             ORDER BY serial_no ASC"
        );
        $stmt_ld->execute([$company_code, $branch_code, $_detail_doc_no]);
        $existing_detail_lines = $stmt_ld->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log('Load invoice_d (d_02) error: ' . $e->getMessage());
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
    <title>Invoice Detail</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="//maxcdn.bootstrapcdn.com/bootstrap/4.0.0/css/bootstrap.min.css" rel="stylesheet">
    <link href="../../../vendor/font-awesome/css/font-awesome.min.css" rel="stylesheet" type="text/css">
    <link rel="stylesheet" href="../../../material_request.css?v=5">
    <link rel="stylesheet" href="../../../assets/css/erp_document.css?v=35">
</head>
<body>
<?php endif; ?>

<div class="detail-container si02-detail">
    <div class="detail-header si02-header">
        <h2 class="si02-title">Items</h2>
        <span class="si-line-count" id="siLineCount"></span>
    </div>

    <div class="detail-table-wrapper si02-wrap">
        <form id="detailForm">
            <div id="detailTableBody" class="si02-lines">
<?php if (!empty($existing_detail_lines)): ?>
<?php foreach ($existing_detail_lines as $_di => $_dl): ?>
<?php
    $_dl_qty      = (float) ($_dl['trans_qty'] ?? 0);
    $_dl_rate     = (float) ($_dl['trans_rate'] ?? 0);
    $_dl_disc_pct = (float) ($_dl['discount_percent'] ?? 0);
    $_dl_disc_amt = (float) ($_dl['discount_amount'] ?? 0);
    $_dl_vat_pct  = trim((string) ($_dl['tax1_percent'] ?? '0'));
    $_dl_vat_amt  = (float) ($_dl['tax1_amount'] ?? 0);
    $_dl_gross    = round($_dl_qty * $_dl_rate, 2);
    if ($_dl_disc_amt <= 0 && $_dl_disc_pct > 0) {
        $_dl_disc_amt = round(($_dl_gross * $_dl_disc_pct) / 100, 2);
    }
    $_dl_amount   = round($_dl_gross - $_dl_disc_amt, 2);
    $_dl_net      = round($_dl_amount + $_dl_vat_amt, 2);
    $_dl_lid      = 'line_db_' . ($_di + 1);
    $_dl_srl      = trim((string) ($_dl['serial_no'] ?? ''));
    if ($_dl_srl === '') {
        $_dl_srl = str_pad((string) ($_di + 1), 4, '0', STR_PAD_LEFT);
    }
    $_dl_line = trim((string) ($_dl['item_no'] ?? ''));
    if ($_dl_line === '') {
        $_dl_line = (string) ($_di + 1);
    }
    $_dl_unit = trim((string) ($_dl['trans_unit'] ?? ''));
    if ($_dl_unit === '') {
        $_dl_unit = trim((string) ($_dl['item_unit'] ?? ''));
    }
    echo si02_line_card_html([
        'line_id'      => $_dl_lid,
        'srl'          => $_dl_srl,
        'line_no'      => $_dl_line,
        'item_code'    => $_dl['item_code'] ?? '',
        'item_desc'    => $_dl['item_description'] ?? '',
        'remark'       => $_dl['remark'] ?? '',
        'location'     => $_dl['location_code'] ?? '',
        'stock'        => (string) ($_dl['stock_qty'] ?? '0'),
        'qty'          => (string) $_dl_qty,
        'unit'         => $_dl_unit,
        'rate'         => (string) $_dl_rate,
        'amount'       => number_format($_dl_amount, 2, '.', ''),
        'discount_pct' => number_format($_dl_disc_pct, 2, '.', ''),
        'discount_amt' => number_format($_dl_disc_amt, 2, '.', ''),
        'vat_pct'      => $_dl_vat_pct,
        'vat_amount'   => number_format($_dl_vat_amt, 2, '.', ''),
        'net'          => number_format($_dl_net, 2, '.', ''),
        'po_no'        => $_dl['po_no'] ?? '',
        'do_no'        => $_dl['do_no'] ?? '',
        'so_no'        => $_dl['so_no'] ?? '',
        'job_no'       => $_dl['job_no'] ?? '',
        'qtn_no'       => $_dl['qtn_no'] ?? '',
        'account'      => $_dl['ac_code'] ?? '',
        'job_item'     => $_dl_line,
        'readonly'     => $_detail_view,
        'view_mode'    => $_detail_view,
    ]);
?>
<?php endforeach; ?>
<?php elseif (!$_detail_view): ?>
<?php $_blank = si02_blank_line($con, $company_code, $branch_code, 1, 1); echo $_blank['html']; ?>
<?php else: ?>
                <div id="detailEmptyState" class="si02-empty">
                    <i class="fa fa-inbox"></i>
                    <p>No detail lines added. Click <strong>Add Line</strong> to start.</p>
                </div>
<?php endif; ?>
            </div>
        </form>
    </div>

    <div class="summary-bar si02-summary">
        <div class="summary-item"><span class="summary-label">Qty</span> <span class="summary-value" id="totalQty">0</span></div>
        <div class="summary-item"><span class="summary-label">Amount</span> <span class="summary-value" id="subtotal">0.00</span></div>
        <div class="summary-item"><span class="summary-label">Discount</span> <span class="summary-value" id="totalDiscount">0.00</span></div>
        <div class="summary-item"><span class="summary-label">VAT</span> <span class="summary-value" id="totalTax">0.00</span></div>
        <div class="summary-item si02-sum-net"><span class="summary-label">Net Amount</span> <span class="summary-value highlight" id="grandTotal">0.00</span></div>
    </div>

    <div class="detail-toolbar detail-toolbar-bottom si02-toolbar">
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
<div id="si02DescModal" class="popup-overlay erp-search" style="display:none;">
    <div class="erp-search-dialog sm" role="dialog" aria-modal="true">
        <div class="erp-search-head">
            <h4 class="erp-search-title"><i class="fa fa-align-left"></i>Description</h4>
            <button type="button" class="erp-search-close" onclick="si02CloseDescMore()" title="Close" aria-label="Close"><i class="fa fa-times"></i></button>
        </div>
        <div class="erp-search-content">
            <textarea id="si02DescText" class="erp-search-textarea" rows="6"></textarea>
        </div>
        <div class="erp-search-foot">
            <button type="button" class="erp-search-btn" onclick="si02CloseDescMore()">Close</button>
            <button type="button" class="erp-search-btn primary" id="si02DescSaveBtn" onclick="si02SaveDescMore()">Save</button>
        </div>
    </div>
</div>

<!-- Remark Modal -->
<div id="si02RemarkModal" class="popup-overlay erp-search" style="display:none;">
    <div class="erp-search-dialog sm" role="dialog" aria-modal="true">
        <div class="erp-search-head">
            <h4 class="erp-search-title"><i class="fa fa-comment-o"></i>Remark</h4>
            <button type="button" class="erp-search-close" onclick="si02CloseRemarkMore()" title="Close" aria-label="Close"><i class="fa fa-times"></i></button>
        </div>
        <div class="erp-search-content">
            <textarea id="si02RemarkText" class="erp-search-textarea" rows="8" placeholder="Type remark..."></textarea>
        </div>
        <div class="erp-search-foot">
            <button type="button" class="erp-search-btn" onclick="si02CloseRemarkMore()">Close</button>
            <button type="button" class="erp-search-btn primary" id="si02RemarkSaveBtn" onclick="si02SaveRemarkMore()">Save</button>
        </div>
    </div>
</div>

<script>
    var SI02_DETAIL_URL = 'detail/sales_invoice_d_02.php';
    var LOCATION_CODE = <?php echo json_encode($_SESSION['branch_code'] ?? ''); ?>;
    var ALLOW_ZERO_STOCK = <?php
        require_once __DIR__ . '/../../../includes/erp_user_access.php';
        echo json_encode(erp_user_allow_zero_stock($con));
    ?>;
    var VAT_PCT = <?php
        $js_vat = 0;
        try {
            $stmt_bm2 = $con->prepare("SELECT tax1_percent FROM branch_master WHERE company_code = ? AND branch_code = ?");
            $stmt_bm2->execute([$company_code, $branch_code]);
            $row_bm2 = $stmt_bm2->fetch(PDO::FETCH_ASSOC);
            if ($row_bm2) { $js_vat = (float) ($row_bm2['tax1_percent'] ?? 0); }
        } catch (Exception $e) { error_log('SI02 Branch VAT JS var error: ' . $e->getMessage()); }
        echo json_encode($js_vat);
    ?>;
    var DEFAULT_AC = <?php
        $default_ac = '';
        try {
            $ac_doc_type = isset($selected_doc_type) ? trim((string) $selected_doc_type) : '';
            if ($ac_doc_type === '') {
                $stmt_ds = $con->prepare("SELECT TOP 1 doc_type FROM document_setup WHERE company_code = ? AND branch_code = ? AND detail_file LIKE ?");
                $stmt_ds->execute([$company_code, $branch_code, '%sales_invoice_d_02%']);
                $ac_doc_type = trim((string) $stmt_ds->fetchColumn());
            }
            if ($ac_doc_type !== '') {
                $stmt_ac = $con->prepare("SELECT TOP 1 cr_ac FROM ac_default WHERE company_code = ? AND branch_code = ? AND ac_type = ?");
                $stmt_ac->execute([$company_code, $branch_code, $ac_doc_type]);
                $row_ac = $stmt_ac->fetch(PDO::FETCH_ASSOC);
                if ($row_ac) { $default_ac = $row_ac['cr_ac'] ?? ''; }
            }
        } catch (Exception $e) { error_log('SI02 Default AC fetch error: ' . $e->getMessage()); }
        echo json_encode($default_ac);
    ?>;
    var SI02_VIEW_MODE = <?php echo $_detail_view ? 'true' : 'false'; ?>;
    var currentLineInput = null;
    var _si02DescInput = null;
    var _si02RemarkInput = null;
    var siTypingBox = null;

    function siEsc(v) {
        return String(v == null ? '' : v)
            .replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;')
            .replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function showAlert(type, message) {
        console.error('[' + type + ']', message);
        alert(message);
    }

    function si02Rows() {
        return document.querySelectorAll('#detailTableBody .detail-row');
    }
    function si02ClearEmpty() {
        var empty = document.getElementById('detailEmptyState');
        if (empty) empty.remove();
    }
    function si02ToggleLineDetails(btn) {
        var panel = btn.nextElementSibling;
        if (!panel) return;
        var open = panel.hasAttribute('hidden');
        if (open) panel.removeAttribute('hidden');
        else panel.setAttribute('hidden', '');
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        var icon = btn.querySelector('i.fa-chevron-right');
        if (icon) icon.classList.toggle('is-open', open);
    }

    function addDetailLine() {
        var tbody = document.getElementById('detailTableBody');
        si02ClearEmpty();
        var nextSrl = si02Rows().length + 1;
        var formData = new FormData();
        formData.append('action', 'add_line');
        formData.append('next_srl', String(nextSrl));
        fetch(SI02_DETAIL_URL, { method: 'POST', body: formData })
            .then(function (r) {
                if (!r.ok) throw new Error('Server returned ' + r.status);
                return r.json();
            })
            .then(function (data) {
                if (data.success) {
                    tbody.insertAdjacentHTML('beforeend', data.html);
                    var last = tbody.querySelector('.detail-row:last-child');
                    var acEl = last ? last.querySelector('input[name="account[]"]') : null;
                    if (acEl && !acEl.value) acEl.value = DEFAULT_AC || '';
                    var locEl = last ? last.querySelector('input[name="location[]"]') : null;
                    if (locEl && !locEl.value) locEl.value = LOCATION_CODE || '';
                    updateLineNumbers();
                    updateSummary();
                } else {
                    showAlert('danger', 'Failed to add line: ' + (data.error || 'Unknown error'));
                }
            })
            .catch(function (err) {
                showAlert('danger', 'Error adding detail line: ' + err.message);
            });
    }

    function deleteDetailLine(button) {
        if (!confirm('Are you sure you want to delete this line?')) return;
        var row = button.closest('.detail-row');
        if (row) row.remove();
        updateLineNumbers();
        updateSummary();
    }

    function deleteSelectedLines() {
        var tbody = document.getElementById('detailTableBody');
        if (!si02Rows().length) {
            showAlert('danger', 'No lines to delete');
            return;
        }
        if (!confirm('Are you sure you want to delete all lines?')) return;
        tbody.innerHTML = '<div id="detailEmptyState" class="si02-empty"><i class="fa fa-inbox"></i><p>No detail lines added. Click <strong>Add Line</strong> to start.</p></div>';
        updateSummary();
    }

    function updateLineNumbers() {
        si02Rows().forEach(function (row, index) {
            var n = index + 1;
            var srl = row.querySelector('.srl-col');
            if (srl) srl.textContent = String(n).padStart(4, '0');
            var lineLbl = row.querySelector('.si02-line-no');
            if (lineLbl) lineLbl.textContent = String(n);
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
        if (input && input !== siTypingBox) {
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

        var amount = Math.max(0, roundMoney(lineTotal - discountAmt));
        setInputValue(row, 'total[]', amount, 2);

        var vatPct = getNumericInput(row, 'vat[]') || VAT_PCT;
        var vatAmount = roundMoney((amount * vatPct) / 100);
        setInputValue(row, 'vat_amount[]', vatAmount, 2);

        var net = roundMoney(amount + vatAmount);
        setInputValue(row, 'total_with_vat[]', net, 2);

        return { qty: qty, subtotal: lineTotal, discount: discountAmt, vat: vatAmount, grandTotal: net };
    }

    function updateSummary() {
        var rows = si02Rows();
        var totalQty = 0, subtotal = 0, totalDiscount = 0, totalVat = 0, grandTotal = 0;
        rows.forEach(function (row) {
            var t = recalcRow(row);
            totalQty += t.qty;
            subtotal += t.subtotal;
            totalDiscount += t.discount;
            totalVat += t.vat;
            grandTotal += t.grandTotal;
        });
        document.getElementById('totalQty').textContent = totalQty.toFixed(2);
        document.getElementById('subtotal').textContent = subtotal.toFixed(2);
        document.getElementById('totalDiscount').textContent = totalDiscount.toFixed(2);
        document.getElementById('totalTax').textContent = totalVat.toFixed(2);
        document.getElementById('grandTotal').textContent = grandTotal.toFixed(2);

        var lineCountEl = document.getElementById('siLineCount');
        if (lineCountEl) lineCountEl.textContent = '\u00b7 ' + rows.length + (rows.length === 1 ? ' line' : ' lines');
        var sumWordsEl = document.getElementById('siSumWords');
        if (sumWordsEl) sumWordsEl.textContent = rows.length + (rows.length === 1 ? ' line' : ' lines') + ' \u00b7 ' + totalQty.toFixed(2) + ' units';

        function setHdr(name, val) {
            var el = document.querySelector('#siForm input[name="' + name + '"]') || document.querySelector('input[name="' + name + '"]');
            if (el) el.value = val;
        }
        setHdr('amount', subtotal.toFixed(2));
        setHdr('discount', totalDiscount.toFixed(2));
        setHdr('tax_amount', '0.00');
        setHdr('vat_amount', totalVat.toFixed(2));
        setHdr('total_lc', grandTotal.toFixed(2));
    }

    function si02IsEditable() {
        var addBtn = document.getElementById('btnAddLine');
        return addBtn ? !addBtn.disabled : !SI02_VIEW_MODE;
    }
    function si02OpenDescMore(btn) {
        var row = btn.closest('.detail-row');
        _si02DescInput = row ? row.querySelector('input[name="item_desc[]"]') : null;
        var ta = document.getElementById('si02DescText');
        ta.value = _si02DescInput ? _si02DescInput.value : '';
        ta.readOnly = !si02IsEditable() || (_si02DescInput && _si02DescInput.readOnly);
        document.getElementById('si02DescSaveBtn').style.display = ta.readOnly ? 'none' : '';
        document.getElementById('si02DescModal').style.display = 'flex';
        ta.focus();
    }
    function si02SaveDescMore() {
        if (_si02DescInput && !_si02DescInput.readOnly) {
            _si02DescInput.value = document.getElementById('si02DescText').value;
        }
        si02CloseDescMore();
    }
    function si02CloseDescMore() {
        document.getElementById('si02DescModal').style.display = 'none';
        _si02DescInput = null;
    }
    function si02OpenRemarkMore(btn) {
        var row = btn.closest('.detail-row');
        _si02RemarkInput = row ? row.querySelector('input[name="item_remark[]"]') : null;
        var ta = document.getElementById('si02RemarkText');
        ta.value = _si02RemarkInput ? _si02RemarkInput.value : '';
        var editable = si02IsEditable();
        ta.readOnly = !editable;
        document.getElementById('si02RemarkSaveBtn').style.display = editable ? '' : 'none';
        document.getElementById('si02RemarkModal').style.display = 'flex';
        ta.focus();
    }
    function si02SaveRemarkMore() {
        if (_si02RemarkInput && !document.getElementById('si02RemarkText').readOnly) {
            _si02RemarkInput.value = document.getElementById('si02RemarkText').value;
            var row = _si02RemarkInput.closest('.detail-row');
            var preview = row ? row.querySelector('.si02-remark-preview') : null;
            if (preview) preview.value = _si02RemarkInput.value;
        }
        si02CloseRemarkMore();
    }
    function si02CloseRemarkMore() {
        document.getElementById('si02RemarkModal').style.display = 'none';
        _si02RemarkInput = null;
    }

    function openItemSearch(inputElement) {
        if (inputElement.readOnly && SI02_VIEW_MODE) return;
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
        fetch(SI02_DETAIL_URL, { method: 'POST', body: formData })
            .then(function (r) {
                if (!r.ok) throw new Error('Server returned ' + r.status);
                return r.json();
            })
            .then(function (data) { renderItemResults(data); })
            .catch(function () {
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
            var loc = item.location_code ? String(item.location_code).replace(/'/g, "\\'") : '';
            var stock = item.stockqty || '0';
            var rate = item.retail_price || '0';
            return '<tr>'
                + '<td>' + (item.itemcode || '') + '</td>'
                + '<td>' + (item.item_name || '') + '</td>'
                + '<td style="text-align:right;">' + stock + '</td>'
                + '<td style="text-align:center;"><button type="button" class="btn btn-primary btn-sm" onclick="selectItem(\'' + code + '\',\'' + name + '\',\'' + unit + '\',\'' + stock + '\',\'' + rate + '\',\'' + loc + '\')"><i class="fa fa-check"></i> Select</button></td>'
                + '</tr>';
        }).join('');
    }
    function selectItem(code, description, unit, stock, rate, locationCode) {
        if (!currentLineInput) return;
        var row = currentLineInput.closest('.detail-row');
        if (!row) return;
        var codeInput = row.querySelector('input[name="item_code[]"]');
        var descInput = row.querySelector('input[name="item_desc[]"]');
        var unitInput = row.querySelector('input[name="unit[]"]');
        var stockInput = row.querySelector('input[name="stock[]"]');
        var rateInput = row.querySelector('input[name="rate[]"]');
        var locInput = row.querySelector('input[name="location[]"]');
        var acInput = row.querySelector('input[name="account[]"]');
        if (codeInput) codeInput.value = code;
        if (descInput) descInput.value = description;
        if (unitInput) unitInput.value = unit;
        if (stockInput) stockInput.value = stock;
        if (rateInput) rateInput.value = parseFloat(rate) || 0;
        if (locInput) locInput.value = locationCode || LOCATION_CODE;
        if (acInput && !acInput.value) acInput.value = DEFAULT_AC;
        updateSummary();
        closeItemSearch();
    }

    function siBuildJobLineHtml(data) {
        var lid = 'line_job_' + Date.now() + '_' + Math.floor(Math.random() * 9000 + 1000);
        var qty = Number(data.qty || 0);
        var rate = Number(data.rate || 0);
        var vatPct = (data.vat != null && data.vat !== '') ? Number(data.vat) : VAT_PCT;
        var amount = Math.round((qty * rate + Number.EPSILON) * 100) / 100;
        var vatAmt = Math.round((amount * vatPct / 100 + Number.EPSILON) * 100) / 100;
        var net = Math.round((amount + vatAmt + Number.EPSILON) * 100) / 100;
        var lineNo = siEsc(data.item_no || '1');
        return '<article class="detail-row si02-line" data-line-id="' + lid + '" data-job-item="' + siEsc(data.item_no || '') + '">'
            + '<input type="hidden" name="line_id[]" value="' + lid + '">'
            + '<input type="hidden" name="account[]" value="' + siEsc(DEFAULT_AC) + '">'
            + '<input type="hidden" name="excise_rate[]" value="0">'
            + '<input type="hidden" name="excise_tax_amt[]" value="0">'
            + '<input type="hidden" name="vat[]" value="' + vatPct + '">'
            + '<input type="hidden" name="item_remark[]" value="' + siEsc(data.remark || '') + '">'
            + '<input type="hidden" name="line_no[]" value="' + lineNo + '">'
            + '<div class="si02-line-head"><div class="si02-line-meta">'
            + '<span class="srl-col si02-srl">0001</span>'
            + '<span class="si02-line-label">Line <span class="si02-line-no">' + lineNo + '</span></span></div>'
            + '<button type="button" class="btn btn-sm btn-danger si02-del" title="Delete line" aria-label="Delete line" onclick="deleteDetailLine(this)"><i class="fa fa-trash"></i></button></div>'
            + '<div class="si02-grid">'
            + '<div class="si02-field si02-code"><label class="si02-lbl">Item Code</label>'
            + '<input type="text" class="form-control form-control-sm detail-input si-code" name="item_code[]" value="' + siEsc(data.item_code || '') + '" readonly style="cursor:pointer;" onfocus="openItemSearch(this)" onclick="openItemSearch(this)"></div>'
            + '<div class="si02-field si02-desc"><label class="si02-lbl">Description</label><div class="si02-desc-row">'
            + '<input type="text" class="form-control form-control-sm detail-input si-desc" name="item_desc[]" value="' + siEsc(data.item_desc || '') + '">'
            + '<button type="button" class="si02-btn-ghost" tabindex="-1" onclick="si02OpenDescMore(this)">More..</button></div></div>'
            + '<div class="si02-field"><label class="si02-lbl">Qty</label><input type="number" class="form-control form-control-sm detail-input si02-num" name="qty[]" value="' + qty + '"></div>'
            + '<div class="si02-field"><label class="si02-lbl">Unit</label><input type="text" class="form-control form-control-sm detail-input si02-ro" name="unit[]" value="' + siEsc(data.unit || '') + '" readonly></div>'
            + '<div class="si02-field"><label class="si02-lbl">Rate</label><input type="number" class="form-control form-control-sm detail-input si02-num" name="rate[]" value="' + rate.toFixed(2) + '" step="0.01"></div>'
            + '<div class="si02-field"><label class="si02-lbl">Amount</label><input type="text" class="form-control form-control-sm detail-input si-amount si02-ro si02-num" name="total[]" value="' + amount.toFixed(2) + '" readonly></div>'
            + '<div class="si02-field si02-disc"><label class="si02-lbl">Discount</label><div class="si02-disc-row">'
            + '<input type="number" class="form-control form-control-sm detail-input si02-num" name="discount_pct[]" value="0.00" step="0.01" placeholder="%">'
            + '<input type="number" class="form-control form-control-sm detail-input si02-num" name="discount_amt[]" value="0.00" step="0.01" placeholder="Amt"></div></div>'
            + '<div class="si02-field"><label class="si02-lbl">VAT Amount</label><input type="text" class="form-control form-control-sm detail-input si02-ro si02-num" name="vat_amount[]" value="' + vatAmt.toFixed(2) + '" readonly></div>'
            + '<div class="si02-field si02-net"><label class="si02-lbl">Net Amount</label>'
            + '<input type="text" class="form-control form-control-sm detail-input si-total si02-ro si02-num si02-net-inp" name="total_with_vat[]" value="' + net.toFixed(2) + '" readonly></div>'
            + '</div>'
            + '<div class="si02-more"><button type="button" class="si02-more-toggle" aria-expanded="false" onclick="si02ToggleLineDetails(this)">'
            + '<i class="fa fa-chevron-right" aria-hidden="true"></i> Line Details <span>Loc · Stock · Remark · QTN / PO / DO / SO / Job</span></button>'
            + '<div class="si02-more-panel" hidden><div class="si02-more-grid">'
            + '<div class="si02-field si02-remark"><label class="si02-lbl">Remark</label><div class="si02-remark-row">'
            + '<input type="text" class="form-control form-control-sm si02-remark-preview" value="' + siEsc(data.remark || '') + '" readonly tabindex="-1">'
            + '<button type="button" class="si02-btn-ghost si02-remark-btn" tabindex="-1" aria-label="Edit remark" onclick="si02OpenRemarkMore(this)"><i class="fa fa-comment-o"></i></button></div></div>'
            + '<div class="si02-field"><label class="si02-lbl">Loc</label><input type="text" class="form-control form-control-sm detail-input" name="location[]" value="' + siEsc(LOCATION_CODE) + '" readonly style="text-align:center;"></div>'
            + '<div class="si02-field"><label class="si02-lbl">Stock</label><input type="text" class="form-control form-control-sm detail-input si02-ro si02-num" name="stock[]" value="0" readonly></div>'
            + '<div class="si02-field"><label class="si02-lbl">QTN No.</label><input type="text" class="form-control form-control-sm detail-input" name="line_qtn_no[]" value="' + siEsc(data.qtn_no || '') + '" placeholder="QTN"></div>'
            + '<div class="si02-field"><label class="si02-lbl">PO No.</label><input type="text" class="form-control form-control-sm detail-input" name="line_po_no[]" value="" placeholder="PO"></div>'
            + '<div class="si02-field"><label class="si02-lbl">DO No.</label><input type="text" class="form-control form-control-sm detail-input" name="line_do_no[]" value="" placeholder="DO"></div>'
            + '<div class="si02-field"><label class="si02-lbl">SO No.</label><input type="text" class="form-control form-control-sm detail-input" name="line_so_no[]" value="" placeholder="SO"></div>'
            + '<div class="si02-field"><label class="si02-lbl">Job No.</label><input type="text" class="form-control form-control-sm detail-input" name="job_no[]" value="' + siEsc(data.job_no || '') + '" placeholder="Job"></div>'
            + '</div></div></div></article>';
    }

    function siApplyJobLines(picked) {
        if (!Array.isArray(picked) || !picked.length) return;
        var tbody = document.getElementById('detailTableBody');
        si02ClearEmpty();
        Array.prototype.slice.call(si02Rows()).forEach(function (tr) {
            var code = (tr.querySelector('input[name="item_code[]"]') || {}).value || '';
            var desc = (tr.querySelector('input[name="item_desc[]"]') || {}).value || '';
            var qty = parseFloat((tr.querySelector('input[name="qty[]"]') || {}).value) || 0;
            if (!String(code).trim() && !String(desc).trim() && qty === 0) tr.remove();
        });
        picked.forEach(function (p) {
            var existing = null;
            si02Rows().forEach(function (tr) {
                var job = (tr.querySelector('input[name="job_no[]"]') || {}).value || '';
                var code = (tr.querySelector('input[name="item_code[]"]') || {}).value || '';
                var jobItem = tr.getAttribute('data-job-item') || '';
                if (job === (p.job_no || '') && (code === (p.item_code || '') || jobItem === String(p.item_no || ''))) {
                    existing = tr;
                }
            });
            if (existing) {
                var qtyEl = existing.querySelector('input[name="qty[]"]');
                var rateEl = existing.querySelector('input[name="rate[]"]');
                var unitEl = existing.querySelector('input[name="unit[]"]');
                var descEl = existing.querySelector('input[name="item_desc[]"]');
                if (qtyEl) qtyEl.value = p.qty;
                if (rateEl) rateEl.value = Number(p.rate || 0).toFixed(2);
                if (unitEl) unitEl.value = p.unit || '';
                if (descEl && (p.item_desc || '')) descEl.value = p.item_desc;
                var remEl = existing.querySelector('input[name="item_remark[]"]');
                var remPrev = existing.querySelector('.si02-remark-preview');
                var qtnEl = existing.querySelector('input[name="line_qtn_no[]"]');
                if (remEl && (p.remark || '') !== '') {
                    remEl.value = p.remark || '';
                    if (remPrev) remPrev.value = p.remark || '';
                }
                if (qtnEl && (p.qtn_no || '') !== '') qtnEl.value = p.qtn_no || '';
                recalcRow(existing);
                return;
            }
            tbody.insertAdjacentHTML('beforeend', siBuildJobLineHtml(p));
        });
        updateLineNumbers();
        updateSummary();
    }

    function getDetailLinesJson() {
        var rows = si02Rows();
        var lines = [];
        rows.forEach(function (row) {
            function val(name) {
                var el = row.querySelector('[name="' + name + '"]');
                return el ? el.value : '';
            }
            var code = val('item_code[]').trim();
            var desc = val('item_desc[]').trim();
            var qty = parseFloat(val('qty[]')) || 0;
            if (code === '' && desc === '' && qty === 0) return;
            lines.push({
                item_code: code,
                item_desc: val('item_desc[]'),
                remark: val('item_remark[]'),
                unit: val('unit[]'),
                qty: val('qty[]'),
                rate: val('rate[]'),
                account: val('account[]'),
                location: val('location[]'),
                stock: val('stock[]'),
                vat: val('vat[]'),
                tax_amount: val('excise_tax_amt[]'),
                tax1_amount: val('vat_amount[]'),
                discount_pct: val('discount_pct[]'),
                discount_amt: val('discount_amt[]'),
                line_no: val('line_no[]'),
                job_no: val('job_no[]'),
                qtn_no: val('line_qtn_no[]'),
                po_no: val('line_po_no[]'),
                do_no: val('line_do_no[]'),
                so_no: val('line_so_no[]')
            });
        });
        return JSON.stringify(lines);
    }

    document.addEventListener('input', function (e) {
        if (!e.target.classList.contains('detail-input')) return;
        var row = e.target.closest('.detail-row');
        if (!row) return;
        if (e.target.name === 'qty[]' && ALLOW_ZERO_STOCK !== 'Y') {
            var jobNo = ((row.querySelector('input[name="job_no[]"]') || {}).value || '').trim();
            var itemCode = ((row.querySelector('input[name="item_code[]"]') || {}).value || '').trim();
            if (!jobNo && itemCode) {
                var enteredQty = parseFloat(e.target.value) || 0;
                var stockQty = parseFloat((row.querySelector('input[name="stock[]"]') || {}).value || 0) || 0;
                if (enteredQty > stockQty) {
                    alert('Entered Qty is more than the Stock..');
                    e.target.value = Math.max(stockQty, 0);
                }
            }
        }
        recalcRow(row, e.target.name);
        updateSummary();
    });

    var SI_DIGIT_DECIMALS = { 'qty[]': null, 'rate[]': null, 'discount_pct[]': 2, 'discount_amt[]': 2 };
    function isSiDigitBox(el) {
        return el && el.tagName === 'INPUT' && el.type === 'number' && !el.readOnly
            && SI_DIGIT_DECIMALS.hasOwnProperty(el.name) && el.closest('#detailTableBody');
    }
    document.addEventListener('focusin', function (e) {
        var el = e.target;
        if (!isSiDigitBox(el)) return;
        siTypingBox = el;
        if (el.value !== '' && (parseFloat(el.value) || 0) === 0) {
            el.dataset.zeroValue = el.value;
            el.value = '';
        }
    });
    document.addEventListener('focusout', function (e) {
        var el = e.target;
        if (!isSiDigitBox(el)) return;
        siTypingBox = null;
        var decimals = SI_DIGIT_DECIMALS[el.name];
        if (el.value === '') {
            el.value = el.dataset.zeroValue || (decimals === null ? '0' : (0).toFixed(decimals));
        } else if (decimals !== null) {
            el.value = (parseFloat(el.value) || 0).toFixed(decimals);
        }
        delete el.dataset.zeroValue;
        var row = el.closest('.detail-row');
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
