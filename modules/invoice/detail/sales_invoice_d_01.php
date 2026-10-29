<?php
// Fragment included by modules/invoice/invoice.php
// When called directly for AJAX (e.g., ?action=add_line), load db/auth
// When included by the container, these are already loaded, so we skip
if (!isset($con)) {
    require('../../../db.php');
    include('../../../auth.php');
}

$owns_output_buffer = !isset($con);
if ($owns_output_buffer) {
    ob_start();
}

$company_code = $_SESSION["company_code"] ?? '';
$branch_code  = $_SESSION["branch_code"]  ?? '';
$user         = $_SESSION['username']     ?? '';

// Blank detail line (Add Line AJAX + default line of a new invoice)
if (!function_exists('si_blank_line')) {
function si_blank_line($con, $company_code, $branch_code) {
    // Fetch VAT rate from branch_master
    $vat_pct = '0';
    try {
        $stmt_bm = $con->prepare("SELECT tax1_percent FROM branch_master WHERE company_code = ? AND branch_code = ?");
        $stmt_bm->execute([$company_code, $branch_code]);
        $row_bm = $stmt_bm->fetch(PDO::FETCH_ASSOC);
        if ($row_bm) {
            $vat_pct = trim($row_bm['tax1_percent'] ?? '0');
        }
    } catch (Exception $e) {
        error_log("Branch master VAT fetch error: " . $e->getMessage());
    }

    $line_id = 'line_' . time() . '_' . rand(1000, 9999);
    
    return [
        'success' => true,
        'line_id' => $line_id,
        'vat_pct' => $vat_pct,
        'html' => '
        <tr data-line-id="' . $line_id . '" class="detail-row">
            <td class="line-no">1</td>
            <td><input type="text" class="form-control form-control-sm detail-input si-code" name="item_code[]" placeholder="Select item" readonly style="cursor:pointer;" onfocus="openItemSearch(this)" onclick="openItemSearch(this)"></td>
            <td class="si-desc-cell">
                <textarea class="form-control form-control-sm detail-input si-desc" name="item_desc[]" rows="1" placeholder="Description" readonly></textarea>
                <span class="si-stock">Stock <input type="text" class="detail-input si-stock-inp" name="stock[]" placeholder="0" readonly></span>
            </td>
            <td><input type="text" class="form-control form-control-sm detail-input" name="location[]" placeholder="Loc" style="text-align:center;" readonly></td>
            <td><input type="text" class="form-control form-control-sm detail-input" name="unit[]" placeholder="Unit" readonly></td>
            <td><input type="number" class="form-control form-control-sm detail-input" name="qty[]" placeholder="0" value="0" style="text-align:right;"></td>
            <td><input type="number" class="form-control form-control-sm detail-input" name="rate[]" placeholder="0.00" value="0.00" step="0.01" style="text-align:right;"></td>
            <td>
                <div class="detail-split-inputs">
                    <input type="number" class="form-control form-control-sm detail-input" name="discount_pct[]" placeholder="%" value="0" step="0.01" style="text-align:center;">
                    <input type="number" class="form-control form-control-sm detail-input" name="discount_amt[]" placeholder="Amt" value="0.00" step="0.01" style="text-align:right;">
                </div>
            </td>
            <td>
                <div class="detail-split-inputs si-excise">
                    <input type="number" class="form-control form-control-sm detail-input" name="excise_rate[]" placeholder="Rate" value="0" step="0.01" style="text-align:right;">
                    <input type="number" class="form-control form-control-sm detail-input" name="excise_tax_amt[]" placeholder="Amt" value="0.00" step="0.01" style="text-align:right;" readonly>
                </div>
            </td>
            <td><input type="text" class="form-control form-control-sm detail-input si-amount" name="total[]" placeholder="0.00" readonly style="text-align:right;"></td>
            <td>
                <div class="detail-split-inputs">
                    <input type="text" class="form-control form-control-sm detail-input" name="vat[]" value="' . htmlspecialchars($vat_pct) . '" readonly style="text-align:center;">
                    <input type="text" class="form-control form-control-sm detail-input" name="vat_amount[]" placeholder="0.00" readonly style="text-align:right;">
                </div>
            </td>
            <td><input type="text" class="form-control form-control-sm detail-input si-total" name="total_with_vat[]" placeholder="0.00" readonly style="text-align:right;"></td>
            <td><input type="text" class="form-control form-control-sm detail-input" name="account[]" placeholder="A/c" readonly></td>
            <td class="si-del"><button type="button" class="btn btn-sm btn-danger" onclick="deleteDetailLine(this)" title="Delete line"><i class="fa fa-trash"></i></button></td>
            <input type="hidden" name="line_id[]" value="' . $line_id . '">
            <input type="hidden" name="job_no[]" value="">
        </tr>'
    ];
}
}

// Handle Add Detail Line (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_line') {
    if ($owns_output_buffer && ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json');
    echo json_encode(si_blank_line($con, $company_code, $branch_code));
    exit;
}

// Handle Item Search (AJAX)
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
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($items);
    } catch (Exception $e) {
        error_log('Item search error: ' . $e->getMessage());
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── Load existing invoice_d lines when a saved doc_no is available ────────────
$existing_detail_lines = [];
$_detail_doc_no = (isset($doc_no) && $doc_no !== '') ? $doc_no
                : (isset($_GET['doc_no']) ? trim($_GET['doc_no']) : '');
if ($_detail_doc_no !== '') {
    try {
        $stmt_ld = $con->prepare(
            "SELECT * FROM invoice_d
             WHERE company_code = ? AND branch_code = ? AND doc_no = ?
             ORDER BY item_no ASC"
        );
        $stmt_ld->execute([$company_code, $branch_code, $_detail_doc_no]);
        $existing_detail_lines = $stmt_ld->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log('Load invoice_d error: ' . $e->getMessage());
    }
}
$_detail_view = isset($is_view_mode) && $is_view_mode;
?>
<?php if ($owns_output_buffer): /* standalone only: inside invoice.php these would re-load and override erp_document.css */ ?>
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
    <link rel="stylesheet" href="../../../assets/css/erp_document.css?v=25">
</head>
<body>
<?php endif; ?>

<div class="detail-container">
    <!-- Header -->
    <div class="detail-header">
        <h2>Items <span class="si-line-count" id="siLineCount"></span></h2>
    </div>
    
    <!-- Alerts -->
    <div id="successAlert" class="alert alert-success">
        <i class="fa fa-check-circle"></i> <span id="successMessage"></span>
    </div>
    <div id="errorAlert" class="alert alert-danger">
        <i class="fa fa-exclamation-circle"></i> <span id="errorMessage"></span>
    </div>
    
    <!-- Table -->
    <div class="detail-table-wrapper">
        <form id="detailForm">
            <table class="detail-table si-lines">
                <thead>
                    <tr>
                        <th style="width:34px;">#</th>
                        <th style="width:96px;">Item Code</th>
                        <th style="min-width:228px;">Description</th>
                        <th style="width:56px;">Loc</th>
                        <th style="width:56px;">Unit</th>
                        <th style="width:72px;" class="num">Qty</th>
                        <th style="width:84px;" class="num">Rate</th>
                        <th style="width:130px;" class="split">Disc % / Amt</th>
                        <th style="width:112px;" class="split">Excise Tax</th>
                        <th style="width:92px;" class="num">Amount</th>
                        <th style="width:120px;" class="split">VAT % / Amt</th>
                        <th style="width:96px;" class="num">Total + VAT</th>
                        <th style="width:70px;">A/c</th>
                        <th style="width:34px;"></th>
                    </tr>
                </thead>
                <tbody id="detailTableBody">
<?php if (!empty($existing_detail_lines)): ?>
<?php foreach ($existing_detail_lines as $_di => $_dl): ?>
<?php
    $_dl_qty        = (float)($_dl['trans_qty']    ?? 0);
    $_dl_rate       = (float)($_dl['trans_rate']   ?? 0);
    $_dl_disc_pct   = (float)($_dl['discount_percent'] ?? 0);
    $_dl_disc_amt   = (float)($_dl['discount_amount']  ?? 0);
    $_dl_excise_amt = (float)($_dl['tax_amount']   ?? 0);
    $_dl_excise_rate= $_dl_qty > 0 ? round($_dl_excise_amt / $_dl_qty, 4) : 0;
    $_dl_vat_pct    = trim($_dl['tax1_percent']    ?? '0');
    $_dl_vat_amt    = (float)($_dl['tax1_amount']  ?? 0);
    $_dl_total      = round($_dl_qty * $_dl_rate - $_dl_disc_amt + $_dl_excise_amt, 2);
    $_dl_total_vat  = round($_dl_total + $_dl_vat_amt, 2);
    $_dl_lid        = 'line_db_' . ($_di + 1);
    $_dl_ro         = $_detail_view ? 'readonly' : ''; // editable num inputs
?>
<tr data-line-id="<?php echo $_dl_lid; ?>" class="detail-row">
    <td class="line-no"><?php echo $_di + 1; ?></td>
    <td><input type="text" class="form-control form-control-sm detail-input si-code" name="item_code[]" value="<?php echo htmlspecialchars($_dl['item_code'] ?? ''); ?>"
             <?php echo $_detail_view ? 'readonly' : 'onfocus="openItemSearch(this)" onclick="openItemSearch(this)"'; ?>
             style="cursor:<?php echo $_detail_view ? 'default' : 'pointer'; ?>;"></td>
    <td class="si-desc-cell">
        <textarea class="form-control form-control-sm detail-input si-desc" name="item_desc[]" rows="1" readonly><?php echo htmlspecialchars($_dl['item_description'] ?? ''); ?></textarea>
        <span class="si-stock">Stock <input type="text" class="detail-input si-stock-inp" name="stock[]" value="<?php echo htmlspecialchars($_dl['stock_qty'] ?? '0'); ?>" readonly></span>
    </td>
    <td><input type="text" class="form-control form-control-sm detail-input" name="location[]" value="<?php echo htmlspecialchars($_dl['location_code'] ?? ''); ?>" readonly style="text-align:center;"></td>
    <td><input type="text" class="form-control form-control-sm detail-input" name="unit[]" value="<?php echo htmlspecialchars(trim((string) ($_dl['trans_unit'] ?? '')) !== '' ? trim($_dl['trans_unit']) : ($_dl['item_unit'] ?? '')); ?>" readonly></td>
    <td><input type="number" class="form-control form-control-sm detail-input" name="qty[]" value="<?php echo htmlspecialchars($_dl_qty); ?>" <?php echo $_dl_ro; ?> style="text-align:right;"></td>
    <td><input type="number" class="form-control form-control-sm detail-input" name="rate[]" value="<?php echo htmlspecialchars($_dl_rate); ?>" <?php echo $_dl_ro; ?> step="0.01" style="text-align:right;"></td>
    <td>
        <div class="detail-split-inputs">
            <input type="number" class="form-control form-control-sm detail-input" name="discount_pct[]" value="<?php echo htmlspecialchars(number_format($_dl_disc_pct, 2, '.', '')); ?>" <?php echo $_dl_ro; ?> step="0.01" style="text-align:center;">
            <input type="number" class="form-control form-control-sm detail-input" name="discount_amt[]" value="<?php echo htmlspecialchars(number_format($_dl_disc_amt, 2, '.', '')); ?>" <?php echo $_dl_ro; ?> step="0.01" style="text-align:right;">
        </div>
    </td>
    <td>
        <div class="detail-split-inputs si-excise">
            <input type="number" class="form-control form-control-sm detail-input" name="excise_rate[]" value="<?php echo htmlspecialchars($_dl_excise_rate); ?>" <?php echo $_dl_ro; ?> step="0.01" style="text-align:right;">
            <input type="number" class="form-control form-control-sm detail-input" name="excise_tax_amt[]" value="<?php echo htmlspecialchars(number_format($_dl_excise_amt, 2, '.', '')); ?>" readonly step="0.01" style="text-align:right;">
        </div>
    </td>
    <td><input type="text" class="form-control form-control-sm detail-input si-amount" name="total[]" value="<?php echo htmlspecialchars(number_format($_dl_total, 2, '.', '')); ?>" readonly style="text-align:right;"></td>
    <td>
        <div class="detail-split-inputs">
            <input type="text" class="form-control form-control-sm detail-input" name="vat[]" value="<?php echo htmlspecialchars($_dl_vat_pct); ?>" readonly style="text-align:center;">
            <input type="text" class="form-control form-control-sm detail-input" name="vat_amount[]" value="<?php echo htmlspecialchars(number_format($_dl_vat_amt, 2, '.', '')); ?>" readonly style="text-align:right;">
        </div>
    </td>
    <td><input type="text" class="form-control form-control-sm detail-input si-total" name="total_with_vat[]" value="<?php echo htmlspecialchars(number_format($_dl_total_vat, 2, '.', '')); ?>" readonly style="text-align:right;"></td>
    <td><input type="text" class="form-control form-control-sm detail-input" name="account[]" value="<?php echo htmlspecialchars($_dl['ac_code'] ?? ''); ?>" readonly></td>
    <td class="si-del"><button type="button" class="btn btn-sm btn-danger" title="Delete line" onclick="deleteDetailLine(this)" <?php echo $_detail_view ? 'style="display:none;"' : ''; ?>><i class="fa fa-trash"></i></button></td>
    <input type="hidden" name="line_id[]" value="<?php echo $_dl_lid; ?>">
    <input type="hidden" name="job_no[]" value="<?php echo htmlspecialchars($_dl['job_no'] ?? ''); ?>">
</tr>
<?php endforeach; ?>
<?php elseif (!$_detail_view): ?>
<?php $_blank_line = si_blank_line($con, $company_code, $branch_code); echo $_blank_line['html']; ?>
<?php else: ?>
                    <tr id="detailEmptyState">
                        <td colspan="15" class="empty-state">
                            <i class="fa fa-inbox"></i>
                            <p>No detail lines added. Click <strong>Add Line</strong> to start.</p>
                        </td>
                    </tr>
<?php endif; ?>
                </tbody>
            </table>
        </form>
    </div>
    
    <!-- Totals -->
    <div class="summary-bar">
        <span class="summary-caption">Total</span>
        <div class="summary-item">
            <span class="summary-label">Qty</span>
            <span class="summary-value" id="totalQty">0</span>
        </div>
        <div class="summary-item">
            <span class="summary-label">Gross</span>
            <span class="summary-value" id="subtotal">0.00</span>
        </div>
        <div class="summary-item">
            <span class="summary-label">Discount</span>
            <span class="summary-value" id="totalDiscount">0.00</span>
        </div>
        <div class="summary-item">
            <span class="summary-label">Tax</span>
            <span class="summary-value" id="totalTax">0.00</span>
        </div>
        <div class="summary-item">
            <span class="summary-label">Grand Total</span>
            <span class="summary-value highlight" id="grandTotal">0.00</span>
        </div>
    </div>

    <!-- Toolbar -->
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
                <input type="text" id="itemSearchInput" autocomplete="off" placeholder="Search by Code, Description or OEM Code..." oninput="searchItems(this.value)" autofocus>
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

<script src="../../../vendor/jquery/jquery.min.js"></script>
<script src="//maxcdn.bootstrapcdn.com/bootstrap/4.0.0/js/bootstrap.min.js"></script>
<script>
    var LOCATION_CODE = <?php echo json_encode($_SESSION['branch_code'] ?? ''); ?>; // Set from session branch code
    var ALLOW_ZERO_STOCK = <?php
        require_once __DIR__ . '/../../../includes/erp_user_access.php';
        echo json_encode(erp_user_allow_zero_stock($con));
    ?>;
    // Default VAT rate fetched from branch_master.tax1_percent at page load
    var VAT_PCT = <?php
        $js_vat = 0;
        try {
            $stmt_bm2 = $con->prepare("SELECT tax1_percent FROM branch_master WHERE company_code = ? AND branch_code = ?");
            $stmt_bm2->execute([$company_code, $branch_code]);
            $row_bm2 = $stmt_bm2->fetch(PDO::FETCH_ASSOC);
            if ($row_bm2) { $js_vat = (float)($row_bm2['tax1_percent'] ?? 0); }
        } catch (Exception $e) { error_log('Branch master VAT JS var error: ' . $e->getMessage()); }
        echo json_encode($js_vat);
    ?>;
    // Default A/C code fetched from ac_default for the current doc_type at page load
    var DEFAULT_AC = <?php
        $default_ac = '';
        try {
            $ac_doc_type = isset($selected_doc_type) ? trim((string) $selected_doc_type) : '';
            if ($ac_doc_type === '') {
                $stmt_ds = $con->prepare("SELECT TOP 1 doc_type FROM document_setup WHERE company_code = ? AND branch_code = ? AND detail_file LIKE ?");
                $stmt_ds->execute([$company_code, $branch_code, '%sales_invoice_d_01%']);
                $ac_doc_type = trim((string) $stmt_ds->fetchColumn());
            }
            if ($ac_doc_type !== '') {
                $stmt_ac = $con->prepare("SELECT TOP 1 cr_ac FROM ac_default WHERE company_code = ? AND branch_code = ? AND ac_type = ?");
                $stmt_ac->execute([$company_code, $branch_code, $ac_doc_type]);
                $row_ac = $stmt_ac->fetch(PDO::FETCH_ASSOC);
                if ($row_ac) { $default_ac = $row_ac['cr_ac'] ?? ''; }
            }
        } catch (Exception $e) { error_log('Default AC fetch error: ' . $e->getMessage()); }
        echo json_encode($default_ac);
    ?>;
    
    var lineCounter = 0;
    var currentLineInput = null;
    
    function showAlert(type, message) {
        console.error('[' + type + ']', message);
        alert(message);
    }
    
    function addDetailLine() {
        const tbody = document.getElementById('detailTableBody');
        const rowCount = tbody.querySelectorAll('tr').length;
        
        // Remove empty state row if exists
        const emptyRow = tbody.querySelector('tr td[colspan="15"]');
        if (emptyRow) {
            emptyRow.closest('tr').remove();
        }
        
        lineCounter++;
        
        const formData = new FormData();
        formData.append('action', 'add_line');
        
        fetch('detail/sales_invoice_d_01.php', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            if (!response.ok) {
                throw new Error('Server returned ' + response.status);
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                tbody.insertAdjacentHTML('beforeend', data.html);
                updateSummary();
            } else {
                showAlert('danger', 'Failed to add line: ' + (data.error || 'Unknown error'));
            }
        })
        .catch(err => {
            console.error('Error adding detail line:', err);
            showAlert('danger', 'Error adding detail line: ' + err.message);
        });
    }
    
    function deleteDetailLine(button) {
        if (confirm('Are you sure you want to delete this line?')) {
            button.closest('tr').remove();
            updateSummary();
            updateLineNumbers();
        }
    }
    
    function deleteSelectedLines() {
        const tbody = document.getElementById('detailTableBody');
        const rows = tbody.querySelectorAll('tr');
        
        if (rows.length === 0) {
            showAlert('danger', 'No lines to delete');
            return;
        }
        
        if (confirm('Are you sure you want to delete all lines?')) {
            rows.forEach(row => row.remove());
            
            // Add empty state
            tbody.innerHTML = '<tr><td colspan="15" class="empty-state"><i class="fa fa-inbox"></i><p>No detail lines added. Click <strong>Add Line</strong> to start.</p></td></tr>';
            updateSummary();
        }
    }
    
    function updateLineNumbers() {
        const tbody = document.getElementById('detailTableBody');
        const rows = tbody.querySelectorAll('tr.detail-row');
        
        rows.forEach((row, index) => {
            row.querySelector('.line-no').textContent = index + 1;
        });
    }

    function roundMoney(value) {
        return Math.round((value + Number.EPSILON) * 100) / 100;
    }

    function getNumericInput(row, name) {
        const input = row.querySelector('input[name="' + name + '"]');
        return input ? (parseFloat(input.value) || 0) : 0;
    }

    function setInputValue(row, name, value, decimals) {
        const input = row.querySelector('input[name="' + name + '"]');
        // the box being typed in is formatted on blur, otherwise the caret jumps past the decimals
        if (input && input !== siTypingBox) {
            input.value = Number(value || 0).toFixed(decimals);
        }
    }

    function recalcRow(row, changedName) {
        const qty      = getNumericInput(row, 'qty[]');
        const rate     = getNumericInput(row, 'rate[]');
        const lineTotal = roundMoney(qty * rate);

        // Discount — two independent modes, no back-conversion
        let discountPct = getNumericInput(row, 'discount_pct[]');
        let discountAmt = getNumericInput(row, 'discount_amt[]');
        if (changedName === 'discount_amt[]') {
            // Amount entered directly — clamp to line total, clear pct box
            discountAmt = Math.min(discountAmt, lineTotal);
            discountPct = 0;
        } else if (discountPct > 0) {
            // Percentage entered — recalculate amt from pct
            discountPct = Math.min(discountPct, 100);
            discountAmt = roundMoney((lineTotal * discountPct) / 100);
        }
        // else: pct=0 and amt was set directly — keep existing discountAmt as-is
        setInputValue(row, 'discount_pct[]', discountPct, 2);
        setInputValue(row, 'discount_amt[]', discountAmt, 2);

        const afterDiscount = Math.max(0, roundMoney(lineTotal - discountAmt));

        // Excise Tax = qty × per-unit excise rate (from item_master.tax_amount)
        const exciseRate = getNumericInput(row, 'excise_rate[]');
        const exciseAmt  = roundMoney(qty * exciseRate);
        setInputValue(row, 'excise_tax_amt[]', exciseAmt, 2);

        // TOTAL = (qty×rate) − discount_amount + tax_amount
        const total = roundMoney(afterDiscount + exciseAmt);
        setInputValue(row, 'total[]', total, 2);

        // VAT amount = TOTAL × tax1_percent / 100
        const vatEl     = row.querySelector('input[name="vat[]"]');
        const vatPct    = vatEl ? (parseFloat(vatEl.value) || 0) : VAT_PCT;
        const vatAmount = roundMoney((total * vatPct) / 100);
        setInputValue(row, 'vat_amount[]', vatAmount, 2);

        // Total+VAT
        const totalWithVat = roundMoney(total + vatAmount);
        setInputValue(row, 'total_with_vat[]', totalWithVat, 2);

        return {
            qty:       qty,
            subtotal:  lineTotal,
            discount:  discountAmt,
            excise:    exciseAmt,
            vat:       vatAmount,
            grandTotal: totalWithVat
        };
    }
    
    function updateSummary() {
        const tbody = document.getElementById('detailTableBody');
        const rows = tbody.querySelectorAll('tr.detail-row');
        
        let totalQty      = 0;
        let subtotal      = 0;
        let totalDiscount = 0;
        let totalExcise   = 0;
        let totalVat      = 0;
        let grandTotal    = 0;
        
        rows.forEach(row => {
            const rowTotals = recalcRow(row);

            totalQty      += rowTotals.qty;
            subtotal      += rowTotals.subtotal;
            totalDiscount += rowTotals.discount;
            totalExcise   += rowTotals.excise;
            totalVat      += rowTotals.vat;
            grandTotal    += rowTotals.grandTotal;
        });

        // Update detail footer totals
        document.getElementById('totalQty').textContent      = totalQty.toFixed(2);
        document.getElementById('subtotal').textContent      = subtotal.toFixed(2);
        document.getElementById('totalDiscount').textContent = totalDiscount.toFixed(2);
        document.getElementById('totalTax').textContent      = (totalExcise + totalVat).toFixed(2);
        document.getElementById('grandTotal').textContent    = grandTotal.toFixed(2);

        var lineCountEl = document.getElementById('siLineCount');
        if (lineCountEl) lineCountEl.textContent = '\u00b7 ' + rows.length + (rows.length === 1 ? ' line' : ' lines');
        var sumWordsEl = document.getElementById('siSumWords');
        if (sumWordsEl) sumWordsEl.textContent = rows.length + (rows.length === 1 ? ' line' : ' lines') + ' \u00b7 ' + totalQty.toFixed(2) + ' units';

        // Push totals to header form fields (same DOM — invoice.php container)
        function setHdr(name, val) {
            var el = document.querySelector('input[name="' + name + '"]');
            if (el) el.value = val;
        }
        setHdr('amount',     subtotal.toFixed(2));
        setHdr('discount',   totalDiscount.toFixed(2));
        setHdr('tax_amount', totalExcise.toFixed(2));
        setHdr('vat_amount', totalVat.toFixed(2));
        setHdr('total_lc',   grandTotal.toFixed(2));
    }
    
    function openItemSearch(inputElement) {
        currentLineInput = inputElement;
        const modal = document.getElementById('itemSearchModal');
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
        const searchInput = document.getElementById('itemSearchInput');
        searchInput.value = '';
        searchInput.focus();
        // Load all items (empty search) when modal opens
        doItemFetch('');
    }

    function closeItemSearch() {
        const modal = document.getElementById('itemSearchModal');
        modal.style.display = 'none';
        document.body.style.overflow = '';
        currentLineInput = null;
    }

    let itemSearchTimer;
    // Immediate fetch (no debounce) — used on modal open
    function doItemFetch(query) {
        const tbody = document.getElementById('itemResultsBody');
        tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:20px;color:#9ca3af;"><i class="fa fa-spinner fa-spin"></i> Loading...</td></tr>';
        const formData = new FormData();
        formData.append('action', 'search_items');
        formData.append('term', query);
        fetch('detail/sales_invoice_d_01.php', {
            method: 'POST',
            body: formData
        })
            .then(r => {
                if (!r.ok) throw new Error('Server returned ' + r.status);
                return r.json();
            })
            .then(data => renderItemResults(data))
            .catch(err => {
                console.error('Error fetching items:', err);
                tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:20px;color:red;">Network error — check login session</td></tr>';
            });
    }
    
    // Debounced fetch — used while typing in the search box
    function searchItems(query) {
        clearTimeout(itemSearchTimer);
        itemSearchTimer = setTimeout(() => doItemFetch(query), 300);
    }
    function renderItemResults(data) {
        const tbody = document.getElementById('itemResultsBody');
        
        if (data && data.error) {
            tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:20px;color:red;">Error: ' + data.error + '</td></tr>';
            return;
        }
        
        if (!Array.isArray(data) || data.length === 0) {
            tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:20px;color:#9ca3af;">No items found</td></tr>';
            return;
        }
        
        tbody.innerHTML = data.map(item => {
            const code    = item.itemcode     ? item.itemcode.replace(/'/g, "\\'")     : '';
            const name    = item.item_name    ? item.item_name.replace(/'/g, "\\'")    : '';
            const unit    = item.stock_unit   ? item.stock_unit.replace(/'/g, "\\'")   : '';
            const loc     = item.location_code ? item.location_code.replace(/'/g, "\\'") : '';
            const stock   = item.stockqty     || '0';
            const rate    = item.retail_price  || '0';
            const taxAmt  = item.tax_amount    || '0';
            
            return `<tr>
                <td>${item.itemcode}</td>
                <td>${item.item_name}</td>
                <td style="text-align:right;">${stock}</td>
                <td style="text-align:center;">
                    <button type="button" class="btn btn-primary btn-sm" 
                        onclick="selectItem('${code}', '${name}', '${unit}', '${stock}', '${rate}', '${loc}', '${taxAmt}')">
                        <i class="fa fa-check"></i> Select
                    </button>
                </td>
            </tr>`;
        }).join('');
    }

    function selectItem(code, description, unit, stock, rate, locationCode, taxAmtPerUnit) {
        if (currentLineInput) {
            const row = currentLineInput.closest('tr');
            
            const codeInput        = row.querySelector('input[name="item_code[]"]');
            const descInput        = row.querySelector('[name="item_desc[]"]');
            const unitInput        = row.querySelector('input[name="unit[]"]');
            const stockInput       = row.querySelector('input[name="stock[]"]');
            const rateInput        = row.querySelector('input[name="rate[]"]');
            const exciseRateInput  = row.querySelector('input[name="excise_rate[]"]');
            const acInput          = row.querySelector('input[name="account[]"]');
            const locInput         = row.querySelector('input[name="location[]"]');
            
            if (codeInput)       codeInput.value       = code;
            if (descInput)       descInput.value       = description;
            if (unitInput)       unitInput.value       = unit;
            if (stockInput)      stockInput.value      = stock;
            if (rateInput)       rateInput.value       = parseFloat(rate) || 0;
            if (exciseRateInput) exciseRateInput.value = parseFloat(taxAmtPerUnit) || 0;
            if (acInput && !acInput.value) acInput.value = DEFAULT_AC;
            if (locInput)        locInput.value        = locationCode || LOCATION_CODE;
            
            updateSummary();
            updateLineNumbers();
            closeItemSearch();
        }
    }
    
    function saveDetailLines() {
        showAlert('success', 'Detail lines saved successfully');
        updateSummary();
    }

    function siEsc(v) {
        return String(v == null ? '' : v)
            .replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;')
            .replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function siBuildJobLineHtml(data) {
        var lid = 'line_job_' + Date.now() + '_' + Math.floor(Math.random() * 9000 + 1000);
        var qty = Number(data.qty || 0);
        var rate = Number(data.rate || 0);
        var vatPct = (data.vat != null && data.vat !== '') ? Number(data.vat) : VAT_PCT;
        var discAmt = 0;
        var excise = 0;
        var gross = Math.round((qty * rate - discAmt + Number.EPSILON) * 100) / 100;
        var amount = Math.round((gross + excise + Number.EPSILON) * 100) / 100;
        var vatAmt = Math.round((amount * vatPct / 100 + Number.EPSILON) * 100) / 100;
        var totalVat = Math.round((amount + vatAmt + Number.EPSILON) * 100) / 100;
        var descRo = '';
        return '<tr data-line-id="' + lid + '" class="detail-row" data-job-no="' + siEsc(data.job_no || '') + '" data-job-item="' + siEsc(data.item_no || '') + '">'
            + '<td class="line-no">1</td>'
            + '<td><input type="text" class="form-control form-control-sm detail-input si-code" name="item_code[]" value="' + siEsc(data.item_code || '') + '" readonly style="cursor:pointer;" onfocus="openItemSearch(this)" onclick="openItemSearch(this)"></td>'
            + '<td class="si-desc-cell">'
            + '<textarea class="form-control form-control-sm detail-input si-desc" name="item_desc[]" rows="1" ' + descRo + '>' + siEsc(data.item_desc || '') + '</textarea>'
            + '<span class="si-stock">Stock <input type="text" class="detail-input si-stock-inp" name="stock[]" value="0" readonly></span>'
            + '</td>'
            + '<td><input type="text" class="form-control form-control-sm detail-input" name="location[]" value="' + siEsc(LOCATION_CODE) + '" style="text-align:center;" readonly></td>'
            + '<td><input type="text" class="form-control form-control-sm detail-input" name="unit[]" value="' + siEsc(data.unit || '') + '" readonly></td>'
            + '<td><input type="number" class="form-control form-control-sm detail-input" name="qty[]" value="' + qty + '" style="text-align:right;"></td>'
            + '<td><input type="number" class="form-control form-control-sm detail-input" name="rate[]" value="' + rate.toFixed(2) + '" step="0.01" style="text-align:right;"></td>'
            + '<td><div class="detail-split-inputs">'
            + '<input type="number" class="form-control form-control-sm detail-input" name="discount_pct[]" value="0.00" step="0.01" style="text-align:center;">'
            + '<input type="number" class="form-control form-control-sm detail-input" name="discount_amt[]" value="0.00" step="0.01" style="text-align:right;">'
            + '</div></td>'
            + '<td><div class="detail-split-inputs si-excise">'
            + '<input type="number" class="form-control form-control-sm detail-input" name="excise_rate[]" value="0" step="0.01" style="text-align:right;">'
            + '<input type="number" class="form-control form-control-sm detail-input" name="excise_tax_amt[]" value="0.00" step="0.01" style="text-align:right;" readonly>'
            + '</div></td>'
            + '<td><input type="text" class="form-control form-control-sm detail-input si-amount" name="total[]" value="' + amount.toFixed(2) + '" readonly style="text-align:right;"></td>'
            + '<td><div class="detail-split-inputs">'
            + '<input type="text" class="form-control form-control-sm detail-input" name="vat[]" value="' + vatPct + '" readonly style="text-align:center;">'
            + '<input type="text" class="form-control form-control-sm detail-input" name="vat_amount[]" value="' + vatAmt.toFixed(2) + '" readonly style="text-align:right;">'
            + '</div></td>'
            + '<td><input type="text" class="form-control form-control-sm detail-input si-total" name="total_with_vat[]" value="' + totalVat.toFixed(2) + '" readonly style="text-align:right;"></td>'
            + '<td><input type="text" class="form-control form-control-sm detail-input" name="account[]" value="' + siEsc(DEFAULT_AC) + '" readonly></td>'
            + '<td class="si-del"><button type="button" class="btn btn-sm btn-danger" onclick="deleteDetailLine(this)" title="Delete line"><i class="fa fa-trash"></i></button></td>'
            + '<input type="hidden" name="line_id[]" value="' + lid + '">'
            + '<input type="hidden" name="job_no[]" value="' + siEsc(data.job_no || '') + '">'
            + '</tr>';
    }

    /** Apply lines picked from Select JOB modal (called from header). */
    function siApplyJobLines(picked) {
        if (!Array.isArray(picked) || !picked.length) return;
        var tbody = document.getElementById('detailTableBody');
        var emptyRow = tbody.querySelector('tr td[colspan="15"]');
        if (emptyRow) emptyRow.closest('tr').remove();

        Array.prototype.slice.call(tbody.querySelectorAll('tr.detail-row')).forEach(function (tr) {
            var code = (tr.querySelector('input[name="item_code[]"]') || {}).value || '';
            var desc = (tr.querySelector('[name="item_desc[]"]') || {}).value || '';
            var qty = parseFloat((tr.querySelector('input[name="qty[]"]') || {}).value) || 0;
            if (!String(code).trim() && !String(desc).trim() && qty === 0) tr.remove();
        });

        picked.forEach(function (p) {
            var existing = null;
            tbody.querySelectorAll('tr.detail-row').forEach(function (tr) {
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
                var descEl = existing.querySelector('[name="item_desc[]"]');
                if (qtyEl) qtyEl.value = p.qty;
                if (rateEl) rateEl.value = Number(p.rate || 0).toFixed(2);
                if (unitEl) unitEl.value = p.unit || '';
                if (descEl && (p.item_desc || '')) descEl.value = p.item_desc;
                recalcRow(existing);
                return;
            }
            tbody.insertAdjacentHTML('beforeend', siBuildJobLineHtml(p));
        });
        updateLineNumbers();
        updateSummary();
    }

    function getDetailLinesJson() {
        var rows = document.querySelectorAll('#detailTableBody tr.detail-row');
        var lines = [];
        rows.forEach(function(row) {
            function val(name) {
                var el = row.querySelector('[name="' + name + '"]');
                return el ? el.value : '';
            }
            var code = val('item_code[]').trim();
            var desc = val('item_desc[]').trim();
            var qty = parseFloat(val('qty[]')) || 0;
            // Keep job/free-text lines that have description or qty even without item code
            if (code === '' && desc === '' && qty === 0) return;
            lines.push({
                item_code:    val('item_code[]'),
                item_desc:    val('item_desc[]'),
                unit:         val('unit[]'),
                qty:          val('qty[]'),
                rate:         val('rate[]'),
                account:      val('account[]'),
                location:     val('location[]'),
                stock:        val('stock[]'),
                vat:          val('vat[]'),
                tax_amount:   val('excise_tax_amt[]'),
                tax1_amount:  val('vat_amount[]'),
                discount_pct: val('discount_pct[]'),
                discount_amt: val('discount_amt[]'),
                job_no:       val('job_no[]')
            });
        });
        return JSON.stringify(lines);
    }
    
    // Update row totals and summary while editing
    document.addEventListener('input', function(e) {
        if (e.target.classList.contains('detail-input')) {
            const row = e.target.closest('tr.detail-row');
            if (row) {
                // Qty > stock validation (branch_user.allow_zero_stock = 'Y' skips it).
                // Job Card lines (job_no set) and free-text lines without item code skip stock check.
                if (e.target.name === 'qty[]' && ALLOW_ZERO_STOCK !== 'Y') {
                    const jobNo = ((row.querySelector('input[name="job_no[]"]') || {}).value || '').trim();
                    const itemCode = ((row.querySelector('input[name="item_code[]"]') || {}).value || '').trim();
                    if (!jobNo && itemCode) {
                        const enteredQty = parseFloat(e.target.value) || 0;
                        const stockInput = row.querySelector('input[name="stock[]"]');
                        const stockQty   = parseFloat(stockInput ? stockInput.value : 0) || 0;
                        if (enteredQty > stockQty) {
                            alert('Entered Qty is more than the Stock..');
                            e.target.value = Math.max(stockQty, 0);
                        }
                    }
                }
                recalcRow(row, e.target.name);
            }
            updateSummary();
        }
    });

    // Editable digit boxes: clear a zero value on entry so typing starts fresh, restore / format on exit
    var SI_DIGIT_DECIMALS = { 'qty[]': null, 'rate[]': null, 'discount_pct[]': 2, 'discount_amt[]': 2, 'excise_tax_amt[]': 2 };
    var siTypingBox = null;
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
        var row = el.closest('tr.detail-row');
        if (row) recalcRow(row, el.name);
        updateSummary();
    });

    // On page load: compute summary from pre-filled DB rows and push totals to header
    document.addEventListener('DOMContentLoaded', function() {
        updateSummary();
    });
</script>

<?php if ($owns_output_buffer): ?>
</body>
</html>
<?php endif; ?>
