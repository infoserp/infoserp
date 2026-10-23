<?php
// Stock Transfer Out Header
// Fragment included by modules/stock_transfer_out/stock_transfer_out.php
// Owns: doc type, doc no, save (header + lines), document load, title bar and header fields.
// The <form id="stoForm"> opened here is closed by the detail fragment (d_stock_transfer_out_d_01),
// so header fields and item lines are posted together.
if (!isset($con)) {
    require('../../../db.php');
    include('../../../auth.php');
}
require_once __DIR__ . '/../../../includes/erp_paths.php';
require_once __DIR__ . '/../../../includes/doc_setup.php';

$company_code = $_SESSION["company_code"];
$branch_code = $_SESSION["branch_code"];
$user = $_SESSION['username'];

// Available Stock Transfer Out doc types (defined in doc_type_master AND enabled in document_setup)
$sto_doc_code = isset($doc_code) && $doc_code !== '' ? $doc_code : 'TO';
$sto_doc_types = erp_doc_configured_types($con, $company_code, $branch_code, $sto_doc_code);

$selected_doc_type = (isset($doc_type) && $doc_type !== '')
    ? $doc_type
    : erp_doc_default_type($con, $company_code, $branch_code, $sto_doc_code, $sto_doc_types);
if ($selected_doc_type === '') {
    $selected_doc_type = erp_doc_master_type($con, $company_code, $branch_code, $sto_doc_code);
}
$sto_posted_doc_type = strtoupper(trim($_POST['doc_type_selected'] ?? ''));
if (!isset($_GET['type']) && !isset($_GET['doc_type']) && isset($sto_doc_types[$sto_posted_doc_type])) {
    $selected_doc_type = $sto_posted_doc_type;
}
$selected_doc_name = $sto_doc_types[$selected_doc_type]
    ?? erp_doc_type_name($con, $company_code, $branch_code, $sto_doc_code, $selected_doc_type);

// Doc No — prefix/digits from doc_number (company + branch + doc_code + doc_type)
function stoNextDocNo($con, $company_code, $branch_code, $doc_code, $doc_type)
{
    $prefix = $doc_type . "/"; // used only when doc_number has no row for this type
    $digits = 5;

    $stmt_prefix = $con->prepare(
        "SELECT doc_prefix, doc_line, txn_prefix, year_prefix,
                doc_year, month_prefix, doc_month, number_prefix, doc_digit
         FROM doc_number
         WHERE company_code = ? AND branch_code = ? AND doc_code = ? AND doc_type = ?"
    );
    $stmt_prefix->execute([$company_code, $branch_code, $doc_code, $doc_type]);
    $row_prefix = $stmt_prefix->fetch(PDO::FETCH_ASSOC);
    if ($row_prefix) {
        $prefix = '';
        foreach (['doc_prefix', 'doc_line', 'txn_prefix', 'year_prefix', 'doc_year', 'month_prefix', 'doc_month', 'number_prefix'] as $col) {
            $prefix .= trim((string) ($row_prefix[$col] ?? ''));
        }
        if (!empty($row_prefix['doc_digit']) && intval($row_prefix['doc_digit']) > 0) {
            $digits = intval($row_prefix['doc_digit']);
        }
    }

    $stmt = $con->prepare(
        "SELECT doc_no FROM stock_transfer_out_h WITH (UPDLOCK, HOLDLOCK)
         WHERE company_code = ? AND branch_code = ? AND doc_type = ?"
    );
    $stmt->execute([$company_code, $branch_code, $doc_type]);
    $max_num = 0;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (preg_match('/(\d+)$/', (string) $row['doc_no'], $m)) {
            $max_num = max($max_num, intval($m[1]));
        }
    }
    return $prefix . str_pad($max_num + 1, $digits, "0", STR_PAD_LEFT);
}

// The container has already buffered the page shell and toolbar; the save response
// must contain only the save result (alert + redirect, or the error text).
function stoDiscardPageOutput()
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
}

function stoAbortSave($con, $message)
{
    if ($con->inTransaction()) {
        $con->rollBack();
    }
    stoDiscardPageOutput();
    die($message);
}

// Locations of the logged-in company/branch
$sto_locations = [];
try {
    $stmt_loc = $con->prepare("SELECT location_code, location_name FROM location_master WHERE company_code = ? AND branch_code = ? ORDER BY location_code");
    $stmt_loc->execute([$company_code, $branch_code]);
    while ($r = $stmt_loc->fetch(PDO::FETCH_ASSOC)) {
        $sto_locations[trim($r['location_code'])] = trim((string) $r['location_name']);
    }
} catch (Exception $e) {
    error_log("stock_transfer_out location fetch error: " . $e->getMessage());
}

// Branches of the logged-in company
$sto_branches = [];
try {
    $stmt_br = $con->prepare("SELECT branch_code, branch_name FROM branch_master WHERE company_code = ? ORDER BY branch_name");
    $stmt_br->execute([$company_code]);
    while ($r = $stmt_br->fetch(PDO::FETCH_ASSOC)) {
        $sto_branches[trim($r['branch_code'])] = trim((string) $r['branch_name']);
    }
} catch (Exception $e) {
    error_log("stock_transfer_out branch fetch error: " . $e->getMessage());
}

// Handle Form Submission
if (isset($_POST['save_sto'])) {
    $is_update = isset($_POST['is_update']) && $_POST['is_update'] == '1';

    if (!$is_update && !isset($sto_doc_types[$sto_posted_doc_type])) {
        stoAbortSave($con, "Document type '" . $sto_posted_doc_type . "' is not enabled in Document Setup for " . $sto_doc_code . ".");
    }

    $doc_dt           = trim($_POST['doc_dt'] ?? '') ?: date('Y-m-d');
    $branch_to        = trim($_POST['branch_to'] ?? '');
    $location_from    = trim($_POST['location_from'] ?? '');
    $location_to      = trim($_POST['location_to'] ?? '');
    $narration        = mb_substr(trim($_POST['narration'] ?? ''), 0, 100);
    $update_inventory = (($_POST['update_inventory'] ?? 'N') === 'Y') ? 'Y' : 'N';

    if ($location_from === '' || !isset($sto_locations[$location_from])) {
        stoAbortSave($con, "Please select Location From (OUT).");
    }
    if ($location_to === '' || !isset($sto_locations[$location_to])) {
        stoAbortSave($con, "Please select Location To (IN).");
    }
    if ($branch_to !== '' && !isset($sto_branches[$branch_to])) {
        stoAbortSave($con, "Invalid Branch To.");
    }
    if ($location_from === $location_to && ($branch_to === '' || $branch_to === $branch_code)) {
        stoAbortSave($con, "Location From and Location To cannot be the same.");
    }

    // Item lines (rows without an item code are skipped)
    $lines = [];
    $codes = $_POST['item_code'] ?? [];
    if (is_array($codes)) {
        foreach ($codes as $i => $code) {
            $code = trim((string) $code);
            if ($code === '') continue;
            $qty = (float) ($_POST['qty'][$i] ?? 0);
            if ($qty <= 0) {
                stoAbortSave($con, "Enter a quantity for item " . $code . ".");
            }
            $lines[] = [
                'item_no'   => trim((string) ($_POST['line_no'][$i] ?? '')),
                'item_code' => $code,
                'item_desc' => mb_substr(trim((string) ($_POST['item_desc'][$i] ?? '')), 0, 250),
                'bin'       => mb_substr(trim((string) ($_POST['bin'][$i] ?? '')), 0, 15),
                'stock'     => (float) ($_POST['stock'][$i] ?? 0),
                'qty'       => $qty,
                'unit'      => mb_substr(trim((string) ($_POST['unit'][$i] ?? '')), 0, 5),
            ];
        }
    }
    if (empty($lines)) {
        stoAbortSave($con, "Add at least one item line.");
    }

    $currency_code = 'AED';
    $exchange_rate = 1;
    try {
        $stmt_cur = $con->prepare("SELECT currency_code, exchange_rate FROM branch_master WHERE company_code = ? AND branch_code = ?");
        $stmt_cur->execute([$company_code, $branch_code]);
        $row_cur = $stmt_cur->fetch(PDO::FETCH_ASSOC);
        if ($row_cur) {
            $cur = trim((string) $row_cur['currency_code']);
            if ($cur !== '') $currency_code = $cur;
            if ($row_cur['exchange_rate'] !== null) $exchange_rate = (float) $row_cur['exchange_rate'];
        }
    } catch (Exception $e) {
        error_log("stock_transfer_out currency fetch error: " . $e->getMessage());
    }

    // Qty vs Stock at Location From (branch_user.allow_zero_stock = 'Y' skips it)
    require_once __DIR__ . '/../../../includes/erp_stock_check.php';
    if (erp_user_allow_zero_stock($con) !== 'Y') {
        $sto_stock_lines = [];
        foreach ($lines as $ln) {
            $sto_stock_lines[] = ['location' => $location_from, 'item_code' => $ln['item_code'], 'qty' => $ln['qty']];
        }

        $sto_own_qty = [];
        $sto_own_doc = trim($_POST['doc_no'] ?? '');
        if ($is_update && $sto_own_doc !== '') {
            $stmt_own = $con->prepare(
                "SELECT h.location_from, d.item_code, SUM(d.trans_qty) AS qty
                 FROM   stock_transfer_out_d d
                 INNER JOIN stock_transfer_out_h h
                         ON h.company_code = d.company_code AND h.branch_code = d.branch_code AND h.doc_no = d.doc_no
                 WHERE  h.company_code = ? AND h.branch_code = ? AND h.doc_no = ? AND h.update_inventory = 'Y'
                 GROUP BY h.location_from, d.item_code"
            );
            $stmt_own->execute([$company_code, $branch_code, $sto_own_doc]);
            while ($r = $stmt_own->fetch(PDO::FETCH_ASSOC)) {
                $sto_own_qty[trim((string) $r['location_from']) . '|' . trim((string) $r['item_code'])] = (float) $r['qty'];
            }
        }

        $sto_short = erp_stock_shortages($con, $sto_stock_lines, $sto_own_qty);
        if ($sto_short) {
            stoAbortSave($con, erp_stock_shortage_message($sto_short));
        }
    }

    // On INSERT, doc_no is generated under UPDLOCK/HOLDLOCK inside the transaction
    $con->beginTransaction();
    try {
        $final_doc_no = $is_update
            ? trim($_POST['doc_no'] ?? '')
            : stoNextDocNo($con, $company_code, $branch_code, $sto_doc_code, $selected_doc_type);
    } catch (Exception $e) {
        stoAbortSave($con, "Error generating document number: " . $e->getMessage());
    }

    $doc_num = 0;
    if (preg_match('/(\d+)$/', $final_doc_no, $numVal)) {
        $doc_num = intval($numVal[1]);
    }

    try {
        if ($is_update) {
            $stmt_chk = $con->prepare("SELECT received FROM stock_transfer_out_h WHERE doc_no = ? AND company_code = ? AND branch_code = ?");
            $stmt_chk->execute([$final_doc_no, $company_code, $branch_code]);
            $row_chk = $stmt_chk->fetch(PDO::FETCH_ASSOC);
            if (!$row_chk) {
                stoAbortSave($con, "Document " . $final_doc_no . " was not found.");
            }
            if (strtoupper(trim((string) $row_chk['received'])) === 'Y') {
                stoAbortSave($con, "Document " . $final_doc_no . " has already been received. Editing is not allowed.");
            }
            $stmt_h = $con->prepare(
                "UPDATE stock_transfer_out_h
                 SET doc_dt = ?, location_from = ?, location_to = ?, branch_to = ?, update_inventory = ?,
                     narration = ?, last_userid = ?, last_userdate = GETDATE()
                 WHERE doc_no = ? AND company_code = ? AND branch_code = ?"
            );
            $stmt_h->execute([
                $doc_dt, $location_from, $location_to, ($branch_to !== '' ? $branch_to : null), $update_inventory,
                ($narration !== '' ? $narration : null), $user,
                $final_doc_no, $company_code, $branch_code,
            ]);
            $stmt_del = $con->prepare("DELETE FROM stock_transfer_out_d WHERE doc_no = ? AND company_code = ? AND branch_code = ?");
            $stmt_del->execute([$final_doc_no, $company_code, $branch_code]);
        } else {
            $stmt_h = $con->prepare(
                "INSERT INTO stock_transfer_out_h (
                    company_code, branch_code, doc_no, doc_num, doc_dt, doc_type,
                    location_from, location_to, branch_to, update_inventory,
                    currency_code, exchange_rate, amount, narration,
                    user_id, user_date, last_userid, last_userdate, received, approved
                ) VALUES (
                    ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, 0, ?,
                    ?, GETDATE(), ?, GETDATE(), 'N', 'N'
                )"
            );
            $stmt_h->execute([
                $company_code, $branch_code, $final_doc_no, $doc_num, $doc_dt, $selected_doc_type,
                $location_from, $location_to, ($branch_to !== '' ? $branch_to : null), $update_inventory,
                $currency_code, $exchange_rate, ($narration !== '' ? $narration : null),
                $user, $user,
            ]);
        }
    } catch (Exception $e) {
        stoAbortSave($con, "Error saving header: " . $e->getMessage());
    }

    $stmt_d = $con->prepare(
        "INSERT INTO stock_transfer_out_d (
            company_code, branch_code, doc_no, serial_no, item_no, item_code, item_description,
            item_qty, item_unit, upp, trans_qty, trans_unit, trans_rate, item_cost,
            location_code, location_bincode, stock_qty
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?,
            ?, ?, 1, ?, ?, 0, 0,
            ?, ?, ?
        )"
    );
    foreach ($lines as $idx => $ln) {
        $srl = $idx + 1;
        $item_no = ($ln['item_no'] !== '' && ctype_digit($ln['item_no'])) ? $ln['item_no'] : (string) $srl;
        try {
            $stmt_d->execute([
                $company_code, $branch_code, $final_doc_no, str_pad($srl, 4, "0", STR_PAD_LEFT), $item_no,
                $ln['item_code'], $ln['item_desc'],
                $ln['qty'], ($ln['unit'] !== '' ? $ln['unit'] : null),
                $ln['qty'], ($ln['unit'] !== '' ? $ln['unit'] : null),
                $location_from, ($ln['bin'] !== '' ? $ln['bin'] : null), $ln['stock'],
            ]);
        } catch (Exception $e) {
            stoAbortSave($con, "Error saving detail line $srl: " . $e->getMessage());
        }
    }

    $con->commit();

    $stay_url = erp_page_url('modules/stock_transfer_out/stock_transfer_out?doc_no=' . rawurlencode($final_doc_no));
    stoDiscardPageOutput();
    echo "<script>alert('Document Saved with No: " . addslashes($final_doc_no) . "'); window.location.href=" . json_encode($stay_url) . ";</script>";
    exit;
}

// ── New document defaults ──────────────────────────────────────────────────
$doc_no = stoNextDocNo($con, $company_code, $branch_code, $sto_doc_code, $selected_doc_type);
$doc_dt = date("Y-m-d");
$branch_to = '';
$location_from = '';
$location_to = '';
$narration = '';
$doc_received = 'N';
$sto_lines = [];

$update_inventory = false;
try {
    $stmt_ct = $con->prepare("SELECT stk_inv, location_code FROM control_table WHERE company_code = ? AND branch_code = ?");
    $stmt_ct->execute([$company_code, $branch_code]);
    $row_ct = $stmt_ct->fetch(PDO::FETCH_ASSOC);
    if ($row_ct) {
        $update_inventory = (strtoupper(trim((string) ($row_ct['stk_inv'] ?? ''))) === 'Y');
        $location_from = trim((string) ($row_ct['location_code'] ?? ''));
    }
} catch (Exception $e) {
    error_log("stock_transfer_out control_table fetch error: " . $e->getMessage());
}

$sto_currency = '';
$sto_exrate = '';
try {
    $stmt_bc = $con->prepare("SELECT currency_code, exchange_rate FROM branch_master WHERE company_code = ? AND branch_code = ?");
    $stmt_bc->execute([$company_code, $branch_code]);
    $row_bc = $stmt_bc->fetch(PDO::FETCH_ASSOC);
    if ($row_bc) {
        $sto_currency = trim((string) $row_bc['currency_code']);
        $sto_exrate = $row_bc['exchange_rate'] !== null ? (string) (float) $row_bc['exchange_rate'] : '';
    }
} catch (Exception $e) {
    error_log("stock_transfer_out branch currency fetch error: " . $e->getMessage());
}

$is_view_mode = false;
$entered_by_user = strtoupper($user);
$entered_by_date = date('d/m/Y H:i');
$modified_by_user = strtoupper($user);
$modified_by_date = date('d/m/Y H:i');

if (isset($_GET['doc_no'])) {
    $search_doc = trim($_GET['doc_no']);
    $stmt_edit = $con->prepare("SELECT * FROM stock_transfer_out_h WHERE doc_no = ? AND company_code = ? AND branch_code = ?");
    $stmt_edit->execute([$search_doc, $company_code, $branch_code]);
    $row_edit = $stmt_edit->fetch(PDO::FETCH_ASSOC);
    if ($row_edit) {
        $doc_no = trim($row_edit['doc_no']);
        if (!empty($row_edit['doc_type'])) {
            $selected_doc_type = strtoupper(trim($row_edit['doc_type']));
            $selected_doc_name = $sto_doc_types[$selected_doc_type]
                ?? erp_doc_type_name($con, $company_code, $branch_code, $sto_doc_code, $selected_doc_type);
        }
        $db_dt = trim((string) $row_edit['doc_dt']);
        if ($db_dt !== '') $doc_dt = substr($db_dt, 0, 10);

        $branch_to        = trim((string) ($row_edit['branch_to'] ?? ''));
        $location_from    = trim((string) ($row_edit['location_from'] ?? ''));
        $location_to      = trim((string) ($row_edit['location_to'] ?? ''));
        $narration        = trim((string) ($row_edit['narration'] ?? ''));
        $update_inventory = (strtoupper(trim((string) ($row_edit['update_inventory'] ?? ''))) === 'Y');
        $doc_received     = strtoupper(trim((string) ($row_edit['received'] ?? 'N'))) === 'Y' ? 'Y' : 'N';
        if (trim((string) ($row_edit['currency_code'] ?? '')) !== '') {
            $sto_currency = trim((string) $row_edit['currency_code']);
        }
        if (($row_edit['exchange_rate'] ?? null) !== null) {
            $sto_exrate = (string) (float) $row_edit['exchange_rate'];
        }

        $entered_by_user = strtoupper(trim((string) ($row_edit['user_id'] ?? $user)));
        $entered_by_date = '';
        if (!empty($row_edit['user_date'])) {
            try { $entered_by_date = (new DateTime($row_edit['user_date']))->format('d/m/Y H:i'); }
            catch (Exception $e) { $entered_by_date = trim($row_edit['user_date']); }
        }
        $last_uid = trim((string) ($row_edit['last_userid'] ?? ''));
        if ($last_uid !== '') {
            $modified_by_user = strtoupper($last_uid);
            $modified_by_date = '';
            if (!empty($row_edit['last_userdate'])) {
                try { $modified_by_date = (new DateTime($row_edit['last_userdate']))->format('d/m/Y H:i'); }
                catch (Exception $e) { $modified_by_date = trim($row_edit['last_userdate']); }
            }
        } else {
            $modified_by_user = $entered_by_user;
            $modified_by_date = $entered_by_date;
        }

        $is_view_mode = true;

        $stmt_det = $con->prepare(
            "SELECT item_no, item_code, item_description, location_code, location_bincode,
                    stock_qty, trans_qty, trans_unit, item_unit
             FROM stock_transfer_out_d
             WHERE doc_no = ? AND company_code = ? AND branch_code = ?
             ORDER BY serial_no ASC"
        );
        $stmt_det->execute([$doc_no, $company_code, $branch_code]);
        while ($d = $stmt_det->fetch(PDO::FETCH_ASSOC)) {
            $sto_lines[] = [
                'line'  => trim((string) $d['item_no']),
                'code'  => trim((string) $d['item_code']),
                'desc'  => trim((string) $d['item_description']),
                'loc'   => trim((string) ($d['location_code'] ?: $location_from)),
                'bin'   => trim((string) $d['location_bincode']),
                'stock' => (float) $d['stock_qty'],
                'qty'   => (float) $d['trans_qty'],
                'unit'  => trim((string) ($d['trans_unit'] ?: $d['item_unit'])),
            ];
        }
    }
}

if (!function_exists('stoLocationOptions')) {
    function stoLocationOptions(array $locations, $selected)
    {
        $html = '<option value="">Select Location</option>';
        foreach ($locations as $code => $name) {
            $sel = ((string) $code === (string) $selected) ? ' selected' : '';
            $html .= '<option value="' . htmlspecialchars($code) . '"' . $sel . '>'
                . htmlspecialchars($name !== '' ? $code . ' - ' . $name : $code) . '</option>';
        }
        return $html;
    }
}
?>
            <div class="mr-header doc-titlebar">
                <h1 class="doc-title-text">Stock Transfer Out</h1>
                <div class="mr-title-wrapper">
                    <button type="button" class="doc-chip doc-chip-type" id="stoTitleBtn" onclick="toggleDocTypeMenu()" title="Click to switch document type">
                        <span id="stoTitleText"><?php echo htmlspecialchars($selected_doc_name); ?></span>
                        <i class="fa fa-caret-down"></i>
                    </button>
                    <div class="mr-doctype-menu" id="stoDocTypeMenu">
                        <?php foreach ($sto_doc_types as $dt_code => $dt_name): ?>
                        <div class="mr-doctype-item <?php echo ($dt_code === $selected_doc_type) ? 'active' : ''; ?>"
                             onclick="selectDocType('<?php echo htmlspecialchars($dt_code, ENT_QUOTES); ?>')">
                            <?php echo htmlspecialchars($dt_name); ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php /* Update Inventory checkbox hidden; the hidden update_inventory field keeps the control_table / saved value.
                <label class="doc-chip doc-chip-type mb-0" title="Post this transfer to inventory">
                    <input type="checkbox" id="stoUpdateInventory" onchange="document.getElementById('stoUpdateInventoryHidden').value = this.checked ? 'Y' : 'N';"
                        <?php echo $update_inventory ? 'checked' : ''; ?> <?php echo $is_view_mode ? 'disabled' : ''; ?>>
                    Update Inventory
                </label>
                */ ?>
                <span class="doc-title-spacer"></span>
                <div class="mr-info">
                    Entered by: <strong><?php echo htmlspecialchars($entered_by_user); ?></strong> - <?php echo htmlspecialchars($entered_by_date); ?><br>
                    Modified by: <strong><?php echo htmlspecialchars($modified_by_user); ?></strong> - <?php echo htmlspecialchars($modified_by_date); ?>
                </div>
            </div>
            <script>if (window.erpToolbarAdoptTitlebar) erpToolbarAdoptTitlebar();</script>

            <form id="stoForm" class="doc-contents" method="POST" onkeydown="return event.key !== 'Enter' || event.target.tagName === 'TEXTAREA'">
                <input type="hidden" name="is_update" id="is_update_field" value="0">
                <input type="hidden" name="doc_type_selected" id="doc_type_selected_field" value="<?php echo htmlspecialchars($selected_doc_type); ?>">
                <input type="hidden" name="update_inventory" id="stoUpdateInventoryHidden" value="<?php echo $update_inventory ? 'Y' : 'N'; ?>">
                <div class="doc-form-wrap">
                <div class="doc-layout no-aside">
                    <div class="doc-main">

                        <!-- ── Document ── -->
                        <div class="doc-section">
                            <h4 class="doc-section-title">Document</h4>
                            <div class="doc-fields">
                                <div class="doc-field">
                                    <label class="doc-lbl">Doc No</label>
                                    <input type="text" name="doc_no" class="doc-inp" value="<?php echo htmlspecialchars($doc_no); ?>" readonly>
                                </div>
                                <div class="doc-field">
                                    <label class="doc-lbl">Doc Date</label>
                                    <input type="date" name="doc_dt" class="doc-inp" value="<?php echo htmlspecialchars($doc_dt); ?>" <?php echo $is_view_mode ? 'disabled' : ''; ?>>
                                </div>
                                <div class="doc-field">
                                    <label class="doc-lbl">Currency</label>
                                    <input type="text" class="doc-inp" value="<?php echo htmlspecialchars($sto_currency); ?>" readonly>
                                </div>
                                <div class="doc-field">
                                    <label class="doc-lbl">EX.Rate</label>
                                    <input type="text" class="doc-inp text-right" value="<?php echo htmlspecialchars($sto_exrate); ?>" readonly>
                                </div>
                            </div>
                        </div>

                        <!-- ── Location ── -->
                        <div class="doc-section">
                            <h4 class="doc-section-title">Location</h4>
                            <div class="doc-fields cols-2">
                                <div class="doc-field">
                                    <label class="doc-lbl">Location From (OUT)</label>
                                    <select name="location_from" id="stoLocFrom" class="doc-inp" onchange="if (typeof stoRefreshLines === 'function') stoRefreshLines();" <?php echo $is_view_mode ? 'disabled' : ''; ?>>
                                        <?php echo stoLocationOptions($sto_locations, $location_from); ?>
                                    </select>
                                </div>
                                <div class="doc-field">
                                    <label class="doc-lbl">Location To (IN)</label>
                                    <select name="location_to" id="stoLocTo" class="doc-inp" <?php echo $is_view_mode ? 'disabled' : ''; ?>>
                                        <?php echo stoLocationOptions($sto_locations, $location_to); ?>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- ── Notes ── -->
                        <div class="doc-section">
                            <h4 class="doc-section-title">Notes</h4>
                            <div class="doc-fields cols-2">
                                <div class="doc-field span-2">
                                    <label class="doc-lbl">Narration</label>
                                    <textarea name="narration" class="doc-inp auto-resize" rows="1" maxlength="100" <?php echo $is_view_mode ? 'readonly' : ''; ?>><?php echo htmlspecialchars($narration); ?></textarea>
                                </div>
                            </div>
                        </div>

                    </div><!-- /.doc-main -->
                </div><!-- /.doc-layout -->
                </div><!-- /.doc-form-wrap -->

                <!-- ── Side panel ── -->
                <aside class="doc-page-aside">
                    <section class="doc-panel">
                        <h3 class="doc-section-title">Summary</h3>
                        <div class="doc-sum-line"><span>Lines</span><b id="stoSumLines">0</b></div>
                        <div class="doc-sum-line"><span>Total qty</span><b id="stoSumQty">0.00</b></div>
                    </section>
                    <?php include __DIR__ . '/../../../shared/doc_attachments_panel.php'; ?>
                </aside>
<?php /* <form id="stoForm"> stays open — closed in detail/d_stock_transfer_out_d_01.php */ ?>

    <script>
        const STO_RECEIVED = '<?php echo $doc_received; ?>';
        let isEditActive = <?php echo $is_view_mode ? 'false' : 'true'; ?>;

        function stoUrl(queryString) {
            var url = <?php echo json_encode(erp_url('modules/stock_transfer_out/stock_transfer_out')); ?> + '?' + String(queryString).replace(/^\?/, '');
            if (window.location.search.indexOf('embed=1') >= 0 && url.indexOf('embed=1') < 0) {
                url += '&embed=1';
            }
            return url;
        }

        // ---- Prior / Next Navigation ----
        const PREV_DOC_NO = '<?php echo addslashes($prev_doc_no ?? ''); ?>';
        const NEXT_DOC_NO = '<?php echo addslashes($next_doc_no ?? ''); ?>';
        const CURRENT_DOC_TYPE = '<?php echo addslashes($selected_doc_type); ?>';

        function navPrior() {
            if (PREV_DOC_NO) window.location.href = stoUrl('doc_no=' + encodeURIComponent(PREV_DOC_NO));
        }
        function navNext() {
            if (NEXT_DOC_NO) {
                window.location.href = stoUrl('doc_no=' + encodeURIComponent(NEXT_DOC_NO));
            } else {
                window.location.href = stoUrl('type=' + encodeURIComponent(CURRENT_DOC_TYPE));
            }
        }

        // ---- Doc Type Title Dropdown ----
        function toggleDocTypeMenu() {
            document.getElementById('stoDocTypeMenu').classList.toggle('open');
        }

        function selectDocType(code) {
            document.getElementById('stoDocTypeMenu').classList.remove('open');
            window.location.href = stoUrl('type=' + encodeURIComponent(code));
        }

        document.addEventListener('click', function (e) {
            var btn = document.getElementById('stoTitleBtn');
            var wrapper = btn ? btn.closest('.mr-title-wrapper') : null;
            if (wrapper && !wrapper.contains(e.target)) {
                var menu = document.getElementById('stoDocTypeMenu');
                if (menu) menu.classList.remove('open');
            }
        });

        // Auto-resize textareas (Narration)
        document.querySelectorAll('textarea.auto-resize').forEach(function (ta) {
            function resize() {
                ta.style.height = 'auto';
                ta.style.height = ta.scrollHeight + 'px';
            }
            ta.addEventListener('input', resize);
            if (ta.value) resize();
        });

        function enableEditMode() {
            if (STO_RECEIVED === 'Y') {
                alert("This transfer has already been received. Editing is not allowed.");
                return false;
            }
            const form = document.getElementById('stoForm');
            form.querySelectorAll('input:not([name="doc_no"]), select, textarea').forEach(function (el) {
                if (el.type !== 'hidden' && !el.closest('#itemsBody')) {
                    el.removeAttribute('readonly');
                    el.removeAttribute('disabled');
                }
            });
            var inv = document.getElementById('stoUpdateInventory');
            if (inv) inv.disabled = false;

            document.getElementById('is_update_field').value = '1';

            if (typeof stoDetailEnableEdit === 'function') {
                stoDetailEnableEdit();
            }
            isEditActive = true;
            return true;
        }

        // Header + line checks before the form is posted
        function stoValidate() {
            var from = document.getElementById('stoLocFrom').value;
            var to = document.getElementById('stoLocTo').value;
            if (!from) { alert('Please select Location From (OUT).'); return false; }
            if (!to) { alert('Please select Location To (IN).'); return false; }
            if (from === to) {
                alert('Location From and Location To cannot be the same.');
                return false;
            }
            var lines = 0, bad = '';
            document.querySelectorAll('#itemsBody tr').forEach(function (tr) {
                var code = tr.querySelector('input[name="item_code[]"]');
                if (!code || !code.value.trim()) return;
                lines++;
                var qty = parseFloat((tr.querySelector('input[name="qty[]"]') || {}).value) || 0;
                if (qty <= 0 && !bad) bad = code.value.trim();
            });
            if (!lines) { alert('Add at least one item line.'); return false; }
            if (bad) { alert('Enter a quantity for item ' + bad + '.'); return false; }
            return true;
        }

        // ── Register shared toolbar callbacks ────────────────────────────────────
        window.erpToolbar = window.erpToolbar || {};

        window.erpToolbar.onSave = function () {
            if (!isEditActive || !stoValidate()) return;
            var btn = document.querySelector('#stoForm button[name="save_sto"]');
            if (btn) btn.click();
        };

        window.erpToolbar.onNew = function () {
            window.location.href = stoUrl('type=' + encodeURIComponent(CURRENT_DOC_TYPE));
        };

        // PREVIEW — print preview of the saved document (print format from Document Setup)
        window.erpToolbar.onPreview = function () {
            erpOpenPrintPreview(<?php echo json_encode($sto_doc_code); ?>, CURRENT_DOC_TYPE, <?php echo json_encode($is_view_mode ? $doc_no : ''); ?>);
        };

        window.erpToolbar.onPrior = navPrior;
        window.erpToolbar.onNext  = navNext;

        window.erpToolbar.onDocSelect = function (doc_no) {
            window.location.href = stoUrl('doc_no=' + encodeURIComponent(doc_no));
        };

        window.erpToolbar.onEditVerified = function () {
            var ok = false;
            try { ok = enableEditMode(); } catch (e) { console.error(e); }
            if (ok) {
                alert("Password verified. Edit mode enabled.");
            } else {
                setTimeout(function () {
                    if (typeof erpToolbarSetState === 'function') erpToolbarSetState({ save: false, edit: true });
                }, 0);
            }
        };

        window.ERP_PREV_DOC_NO = PREV_DOC_NO;
        window.ERP_NEXT_DOC_NO = NEXT_DOC_NO;
        window.ERP_DOC_TYPE    = CURRENT_DOC_TYPE;
        (function () {
            var tbPrior = document.getElementById('tbPrior');
            if (tbPrior) tbPrior.disabled = (PREV_DOC_NO === '');
        })();
    </script>
