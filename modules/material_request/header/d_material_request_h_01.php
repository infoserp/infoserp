<?php
// Material Request Header
// Fragment included by modules/material_request/material_request.php
// Owns: doc type, doc no, save (header + lines), document load, title bar and header fields.
// The <form id="mrForm"> opened here is closed by the detail fragment (d_material_request_d_01),
// so header fields and item lines are posted together exactly as before.
if (!isset($con)) {
    require('../../../db.php');
    include('../../../auth.php');
}
require_once __DIR__ . '/../../../includes/erp_paths.php';
require_once __DIR__ . '/../../../includes/doc_setup.php';

$company_code = $_SESSION["company_code"];
$branch_code = $_SESSION["branch_code"];
$user = $_SESSION['username'];
$today = date("d/m/Y");
$tracking_no = date("ymdHis");

// Fetch available Material Request doc types for this company/branch (defined in doc_type_master AND enabled in document_setup)
$mr_doc_code = isset($doc_code) && $doc_code !== '' ? $doc_code : 'MR';
$mr_doc_types = erp_doc_configured_types($con, $company_code, $branch_code, $mr_doc_code);

// Selected doc type: from the container (URL if enabled / saved doc type / doc_master default),
// a posted type overrides it only when it is enabled in document_setup
$selected_doc_type = (isset($doc_type) && $doc_type !== '')
    ? $doc_type
    : erp_doc_default_type($con, $company_code, $branch_code, $mr_doc_code, $mr_doc_types);
if ($selected_doc_type === '') {
    $selected_doc_type = erp_doc_master_type($con, $company_code, $branch_code, $mr_doc_code);
}
$mr_posted_doc_type = strtoupper(trim($_POST['doc_type_selected'] ?? ''));
if (!isset($_GET['type']) && !isset($_GET['doc_type']) && isset($mr_doc_types[$mr_posted_doc_type])) {
    $selected_doc_type = $mr_posted_doc_type;
}
$selected_doc_name = $mr_doc_types[$selected_doc_type]
    ?? erp_doc_type_name($con, $company_code, $branch_code, $mr_doc_code, $selected_doc_type);

// Doc No Generation Function — prefix/digits from doc_number (company + branch + doc_code + doc_type)
function getNextDocNo($con, $company_code, $branch_code, $doc_code, $doc_type)
{
    $prefix = $doc_type . "/"; // Fallback

    $sql_prefix = "SELECT doc_prefix, doc_line, txn_prefix, year_prefix,
                          doc_year, month_prefix, doc_month, number_prefix, doc_digit
                   FROM doc_number
                   WHERE company_code = ?
                     AND branch_code = ?
                     AND doc_code = ?
                     AND doc_type = ?";
    $stmt_prefix = $con->prepare($sql_prefix);
    $stmt_prefix->execute(array($company_code, $branch_code, $doc_code, $doc_type));
    $row_prefix = $stmt_prefix->fetch(PDO::FETCH_ASSOC);

    $digits = 5; // default padding
    if ($row_prefix) {
        $p1 = isset($row_prefix['doc_prefix'])    ? trim($row_prefix['doc_prefix'])    : '';
        $p2 = isset($row_prefix['doc_line'])       ? trim($row_prefix['doc_line'])       : '';
        $p3 = isset($row_prefix['txn_prefix'])     ? trim($row_prefix['txn_prefix'])     : '';
        $p4 = isset($row_prefix['year_prefix'])    ? trim($row_prefix['year_prefix'])    : '';
        $p5 = isset($row_prefix['doc_year'])       ? trim($row_prefix['doc_year'])       : '';
        $p6 = isset($row_prefix['month_prefix'])   ? trim($row_prefix['month_prefix'])   : '';
        $p7 = isset($row_prefix['doc_month'])      ? trim($row_prefix['doc_month'])      : '';
        $p8 = isset($row_prefix['number_prefix'])  ? trim($row_prefix['number_prefix'])  : '';

        $generated_prefix = $p1 . $p2 . $p3 . $p4 . $p5 . $p6 . $p7 . $p8;
        if ($generated_prefix !== '') {
            $prefix = $generated_prefix;
        }

        if (!empty($row_prefix['doc_digit']) && intval($row_prefix['doc_digit']) > 0) {
            $digits = intval($row_prefix['doc_digit']);
        }
    }

    $sql = "SELECT doc_no FROM material_request_h WITH (UPDLOCK, HOLDLOCK)
            WHERE company_code = ? AND branch_code = ? AND doc_type = ?";
    $stmt = $con->prepare($sql);
    $stmt->execute(array($company_code, $branch_code, $doc_type));
    $max_num = 0;

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $doc = isset($row['doc_no']) ? $row['doc_no'] : '';
            if (preg_match('/(\d+)$/', $doc, $matches)) {
                $num = intval($matches[1]);
                if ($num > $max_num) {
                    $max_num = $num;
                }
            }
    }

    $number = $max_num + 1;
    return $prefix . str_pad($number, $digits, "0", STR_PAD_LEFT);
}

// The container has already buffered the page shell and toolbar; the save response
// must contain only the save result (alert + redirect, or the error text).
function mrDiscardPageOutput()
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
}

// Undo the partial save (header and lines are saved as one unit) and stop with the error text
function mrAbortSave($con, $message)
{
    if ($con->inTransaction()) {
        $con->rollBack();
    }
    mrDiscardPageOutput();
    die($message);
}

// Handle Form Submission
if (isset($_POST['save_mr'])) {
    $is_update = isset($_POST['is_update']) && $_POST['is_update'] == '1';

    if (!$is_update && !isset($mr_doc_types[$mr_posted_doc_type])) {
        mrAbortSave($con, "Document type '" . $mr_posted_doc_type . "' is not enabled in Document Setup for " . $mr_doc_code . ".");
    }

    // Rq.Qty vs Stock at the branch location (branch_user.allow_zero_stock = 'Y' skips it)
    require_once __DIR__ . '/../../../includes/erp_stock_check.php';
    if (erp_user_allow_zero_stock($con) !== 'Y' && isset($_POST['item_code']) && is_array($_POST['item_code'])) {
        $stmt_loc = $con->prepare("SELECT location_code FROM control_table WHERE company_code = ? AND branch_code = ?");
        $stmt_loc->execute([$company_code, $branch_code]);
        $mr_stock_loc = trim((string) ($stmt_loc->fetchColumn() ?: ''));

        $mr_stock_lines = [];
        foreach ($_POST['item_code'] as $i => $code) {
            $mr_stock_lines[] = [
                'location'  => $mr_stock_loc,
                'item_code' => $code,
                'qty'       => $_POST['rq_qty'][$i] ?? 0,
            ];
        }

        $mr_short = erp_stock_shortages($con, $mr_stock_lines);
        if ($mr_short) {
            mrAbortSave($con, erp_stock_shortage_message($mr_short));
        }
    }

    // On INSERT, doc_no is regenerated under UPDLOCK/HOLDLOCK inside the transaction
    // so simultaneous saves cannot share a number; on update the existing doc_no is reused.
    $con->beginTransaction();
    try {
        $final_doc_no = $is_update
            ? trim($_POST['doc_no'])
            : getNextDocNo($con, $company_code, $branch_code, $mr_doc_code, $selected_doc_type);
    } catch (Exception $e) {
        mrAbortSave($con, "Error generating document number: " . $e->getMessage());
    }

    // Preserve original entered-by info when updating
    $original_user_id  = $user;
    $original_user_date_str = null; // null = use GETDATE() for new docs
    if ($is_update) {
        // Read original before deleting
        $stmt_orig = $con->prepare("SELECT user_id, user_date FROM material_request_h WHERE doc_no = ? AND company_code = ? AND branch_code = ?");
        $stmt_orig->execute(array($final_doc_no, $company_code, $branch_code));
        $row_orig = $stmt_orig->fetch(PDO::FETCH_ASSOC);
        if ($row_orig) {
            $original_user_id  = trim($row_orig['user_id']);
            $original_user_date_str = trim($row_orig['user_date']);
        }
        $stmt = $con->prepare("DELETE FROM material_request_d WHERE doc_no = ? AND company_code = ? AND branch_code = ?");
        $stmt->execute(array($final_doc_no, $company_code, $branch_code));
        $stmt = $con->prepare("DELETE FROM material_request_h WHERE doc_no = ? AND company_code = ? AND branch_code = ?");
        $stmt->execute(array($final_doc_no, $company_code, $branch_code));
    }

    // Gather Header Data
    $doc_dt = $_POST['doc_dt'];
    $dept_code = $_POST['dept'];
    $tracking_no = $_POST['tracking_no'];
    $site_no = $_POST['site_no'];
    $site_loc = $_POST['site_loc'];
    $contact_person = $_POST['contact_person'];
    $site_contact = $_POST['site_contact'];
    $req_by = $_POST['req_by'];
    $req_to = $_POST['req_to'];
    $approval_to = $_POST['approval_to'];
    $narration = trim($_POST['narration']);
    $header_remark = trim($_POST['remark']);

    // Calculate doc_num (numeric part of XXXXX)
    $doc_num = 0;
    if (preg_match('/(\d+)$/', $final_doc_no, $numVal)) {
        $doc_num = intval($numVal[1]);
    }

    // Insert Header
    // Columns: company_code, branch_code, doc_no, doc_num, doc_dt, doc_type, ac_code, 
    //          narration, remark, dept_code, tracking_no, site_no, site_location, 
    //          site_contact_name, site_contact_no, user_id, user_date, request_by, 
    //          request_to, confirmed, approved, approved_to, cancelled, closed
    // Check approval requirement from control_table
    $mrq_approval_required = 'N'; // Default
    $stmt_control = $con->prepare("SELECT mrq_approval_required FROM control_table WHERE company_code = ? AND branch_code = ?");
    $stmt_control->execute(array($company_code, $branch_code));
    $row_control = $stmt_control->fetch(PDO::FETCH_ASSOC);
    if ($row_control) {
        if ($row_control && isset($row_control['mrq_approval_required'])) {
            $mrq_approval_required = strtoupper($row_control['mrq_approval_required']);
        }
    }

    $approved_val = ($mrq_approval_required == 'Y') ? 'N' : 'Y';

    // Build INSERT
    // New doc:    user_id = current user, user_date = GETDATE(), last_userid = NULL, last_userdate = NULL
    // Edit/update: user_id = original, user_date = original (or GETDATE() if empty), last_userid = current user, last_userdate = GETDATE()
    if ($is_update) {
        // Fallback: if original user_date was NULL (old record), substitute current datetime
        if (!$original_user_date_str) {
            $original_user_date_str = date('Y-m-d H:i:s');
        }
        $stmt_h = $con->prepare("INSERT INTO material_request_h (
            company_code, branch_code, doc_no, doc_num, doc_dt, doc_type, ac_code,
            narration, remark, dept_code, tracking_no, site_no, site_location,
            site_contact_name, site_contact_no, user_id, user_date, last_userid, last_userdate, request_by,
            request_to, confirmed, approved, approved_to, cancelled, closed
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, GETDATE(), ?,
            ?, ?, ?, ?, ?, ?
        )");
        try {
            $stmt_h->execute(array(
                $company_code, $branch_code, $final_doc_no, $doc_num, $doc_dt, $selected_doc_type, 'A',
                $narration, $header_remark, $dept_code, $tracking_no, $site_no, $site_loc,
                $contact_person, $site_contact, $original_user_id, $original_user_date_str, $user, $req_by,
                $req_to, 'N', $approved_val, $approval_to, 'N', 'N'
            ));
        } catch (Exception $e) {
            mrAbortSave($con, "Error saving header: " . $e->getMessage());
        }
    } else {
        $stmt_h = $con->prepare("INSERT INTO material_request_h (
            company_code, branch_code, doc_no, doc_num, doc_dt, doc_type, ac_code,
            narration, remark, dept_code, tracking_no, site_no, site_location,
            site_contact_name, site_contact_no, user_id, user_date, last_userid, last_userdate, request_by,
            request_to, confirmed, approved, approved_to, cancelled, closed
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?,
            ?, ?, ?, GETDATE(), ?, GETDATE(), ?,
            ?, ?, ?, ?, ?, ?
        )");
        try {
            $stmt_h->execute(array(
                $company_code, $branch_code, $final_doc_no, $doc_num, $doc_dt, $selected_doc_type, 'A',
                $narration, $header_remark, $dept_code, $tracking_no, $site_no, $site_loc,
                $contact_person, $site_contact, $user, $user, $req_by,
                $req_to, 'N', $approved_val, $approval_to, 'N', 'N'
            ));
        } catch (Exception $e) {
            mrAbortSave($con, "Error saving header: " . $e->getMessage());
        }
    }

    // Insert Details
    if (isset($_POST['item_code']) && is_array($_POST['item_code'])) {
        for ($i = 0; $i < count($_POST['item_code']); $i++) {
            $srl = $i + 1;
            $serial_no_str = str_pad($srl, 4, "0", STR_PAD_LEFT);
            $line_no = (isset($_POST['line_no'][$i]) && trim($_POST['line_no'][$i]) !== '') ? intval($_POST['line_no'][$i]) : $srl;

            $item_code = !empty($_POST['item_code'][$i]) ? $_POST['item_code'][$i] : null;
            $item_desc_check = isset($_POST['item_desc'][$i]) ? trim($_POST['item_desc'][$i]) : '';
            if (empty($item_code) && empty($item_desc_check))
                continue; // Skip completely empty rows

            $ac_subcode = (isset($_POST['ac_subcode'][$i]) && $_POST['ac_subcode'][$i] !== '') ? $_POST['ac_subcode'][$i] : null;
            $item_desc = isset($_POST['item_desc'][$i]) ? trim($_POST['item_desc'][$i]) : '';
            $stock = $_POST['stock'][$i]; // Assuming column exists
            $qty = $_POST['rq_qty'][$i];
            $apr_qty = $_POST['apr_qty'][$i];
            $unit = $_POST['unit'][$i];
            $exp_dt = (isset($_POST['expected_dt'][$i]) && $_POST['expected_dt'][$i] !== '') ? $_POST['expected_dt'][$i] : null;
            $item_job = $_POST['job_no'][$i];
            $vehicle = (isset($_POST['vehicle_no'][$i]) && $_POST['vehicle_no'][$i] !== '') ? $_POST['vehicle_no'][$i] : null;
            $item_rem = (isset($_POST['item_remark'][$i]) && $_POST['item_remark'][$i] !== '') ? trim($_POST['item_remark'][$i]) : null;
            $loc_code = isset($_SESSION['location_code']) ? $_SESSION['location_code'] : '';

            $stmt_d = $con->prepare("INSERT INTO material_request_d (
                company_code, branch_code, location_code, doc_no, serial_no, item_no, ac_subcode,
                item_code, item_description, stock_qty, request_qty, trans_qty, 
                item_unit, trans_unit, expected_date, job_no, vehicle_no, remark, upp, approved
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?, 1, ?
            )");
            try {
                $stmt_d->execute(array(
                    $company_code, $branch_code, $loc_code, $final_doc_no, $serial_no_str, $line_no, $ac_subcode,
                    $item_code, $item_desc, $stock, $qty, $apr_qty,
                    $unit, $unit, $exp_dt, $item_job, $vehicle, $item_rem, $approved_val
                ));
            } catch (Exception $e) {
                mrAbortSave($con, "Error saving detail line $srl: " . $e->getMessage());
            }
        }
    }

    $con->commit();

    $stay_url = erp_page_url('modules/material_request/material_request?doc_no=' . rawurlencode($final_doc_no));
    mrDiscardPageOutput();
    echo "<script>alert('Document Saved with No: " . addslashes($final_doc_no) . "'); window.location.href=" . json_encode($stay_url) . ";</script>";
    exit;
}

// Get next doc_no for display on page load
$doc_no = getNextDocNo($con, $company_code, $branch_code, $mr_doc_code, $selected_doc_type);
$doc_dt = date("Y-m-d");
$dept = '';
$site_no = '';
$site_loc = '';
$contact_person = '';
$site_contact = '';
$req_by = $user;
$req_to = '';
$approval_to = '';
$narration = '';
$header_remark = '';

$is_view_mode = false;
$doc_approved = 'N';
$doc_cancelled = 'N';
$edit_details = [];

// Entered-by / Modified-by display vars
$entered_by_user = strtoupper($user);
$entered_by_date = date('d/m/Y H:i');
$modified_by_user = strtoupper($user);
$modified_by_date = date('d/m/Y H:i');

// Fetch approval setting for display/edit logic
$mrq_approval_required = 'N';
$stmt_ctrl = $con->prepare("SELECT mrq_approval_required FROM control_table WHERE company_code = ? AND branch_code = ?");
$stmt_ctrl->execute(array($company_code, $branch_code));
$row_ctrl = $stmt_ctrl->fetch(PDO::FETCH_ASSOC);
if ($row_ctrl && isset($row_ctrl['mrq_approval_required'])) {
    $mrq_approval_required = strtoupper($row_ctrl['mrq_approval_required']);
}

if (isset($_GET['doc_no'])) {
    $search_doc = $_GET['doc_no'];
    $stmt_edit = $con->prepare("SELECT * FROM material_request_h WHERE doc_no = ? AND company_code = ? AND branch_code = ?");
    $stmt_edit->execute(array($search_doc, $company_code, $branch_code));
    $row_edit = $stmt_edit->fetch(PDO::FETCH_ASSOC);
    if ($row_edit) {
        $doc_no = $row_edit['doc_no'];
        // Update selected type from the loaded document
        if (isset($row_edit['doc_type']) && $row_edit['doc_type'] !== '') {
            $selected_doc_type = strtoupper(trim($row_edit['doc_type']));
            $selected_doc_name = $mr_doc_types[$selected_doc_type]
                ?? erp_doc_type_name($con, $company_code, $branch_code, $mr_doc_code, $selected_doc_type);
        }
        $db_dt = trim($row_edit['doc_dt']);
        if ($db_dt && strpos($db_dt, ' ') !== false) {
            $db_dt = explode(' ', $db_dt)[0];
        }
        if ($db_dt)
            $doc_dt = $db_dt;

        $tracking_no = isset($row_edit['tracking_no']) ? trim($row_edit['tracking_no']) : $tracking_no;
        $dept = isset($row_edit['dept_code']) ? trim($row_edit['dept_code']) : '';
        $site_no = isset($row_edit['site_no']) ? trim($row_edit['site_no']) : '';
        $site_loc = isset($row_edit['site_location']) ? trim($row_edit['site_location']) : '';
        $contact_person = isset($row_edit['site_contact_name']) ? trim($row_edit['site_contact_name']) : '';
        $site_contact = isset($row_edit['site_contact_no']) ? trim($row_edit['site_contact_no']) : '';
        $req_by = isset($row_edit['request_by']) ? trim($row_edit['request_by']) : '';
        $req_to = isset($row_edit['request_to']) ? trim($row_edit['request_to']) : '';
        $approval_to = isset($row_edit['approved_to']) ? trim($row_edit['approved_to']) : '';
        $narration = isset($row_edit['narration']) ? trim($row_edit['narration']) : '';
        $header_remark = isset($row_edit['remark']) ? trim($row_edit['remark']) : '';
        $doc_approved = isset($row_edit['approved']) ? trim($row_edit['approved']) : 'N';

        // Populate entered-by from DB record
        $entered_by_user = strtoupper(isset($row_edit['user_id']) ? trim($row_edit['user_id']) : $user);
        $entered_by_date_raw = isset($row_edit['user_date']) ? trim($row_edit['user_date']) : '';
        if ($entered_by_date_raw) {
            try {
                $entered_by_date = (new DateTime($entered_by_date_raw))->format('d/m/Y H:i');
            } catch (Exception $e) {
                $entered_by_date = $entered_by_date_raw;
            }
        }
        // Modified-by: read last_userid / last_userdate from DB; fall back to entered-by if never modified
        $last_uid_raw = isset($row_edit['last_userid']) ? trim($row_edit['last_userid']) : '';
        $last_udate_raw = isset($row_edit['last_userdate']) ? trim($row_edit['last_userdate']) : '';
        if ($last_uid_raw !== '' && $last_uid_raw !== null) {
            $modified_by_user = strtoupper($last_uid_raw);
            if ($last_udate_raw) {
                try {
                    $modified_by_date = (new DateTime($last_udate_raw))->format('d/m/Y H:i');
                } catch (Exception $e) {
                    $modified_by_date = $last_udate_raw;
                }
            } else {
                $modified_by_date = '';
            }
        } else {
            // Never been edited — show same as entered-by
            $modified_by_user = $entered_by_user;
            $modified_by_date = $entered_by_date;
        }

        $is_view_mode = true;

        // Compute previous and next doc_no ordered by doc_num
        $current_doc_num = isset($row_edit['doc_num']) ? intval($row_edit['doc_num']) : 0;
        $prev_doc_no = '';
        $next_doc_no = '';
        $stmt_prev = $con->prepare("SELECT TOP 1 doc_no FROM material_request_h WHERE company_code = ? AND branch_code = ? AND doc_type = ? AND doc_num < ? ORDER BY doc_num DESC");
        $stmt_prev->execute(array($company_code, $branch_code, $selected_doc_type, $current_doc_num));
        $row_prev = $stmt_prev->fetch(PDO::FETCH_ASSOC);
        if ($row_prev) $prev_doc_no = $row_prev['doc_no'];

        $stmt_next = $con->prepare("SELECT TOP 1 doc_no FROM material_request_h WHERE company_code = ? AND branch_code = ? AND doc_type = ? AND doc_num > ? ORDER BY doc_num ASC");
        $stmt_next->execute(array($company_code, $branch_code, $selected_doc_type, $current_doc_num));
        $row_next = $stmt_next->fetch(PDO::FETCH_ASSOC);
        if ($row_next) $next_doc_no = $row_next['doc_no'];

        $stmt_det = $con->prepare("SELECT d.*, a.ac_name FROM material_request_d d LEFT JOIN ac_subledger a ON d.ac_subcode = a.ac_subcode AND a.company_code = d.company_code WHERE d.doc_no = ? AND d.company_code = ? AND d.branch_code = ? ORDER BY d.item_no ASC");
        $stmt_det->execute(array($search_doc, $company_code, $branch_code));
        while ($row_d = $stmt_det->fetch(PDO::FETCH_ASSOC)) {
            $edit_details[] = $row_d;
            // If any detail row has cancelled = Y, treat the document as cancelled
            if (isset($row_d['cancelled']) && strtoupper(trim($row_d['cancelled'])) === 'Y') {
                $doc_cancelled = 'Y';
            }
        }
    }
}
// For new-doc mode: PRIOR goes to the last saved document of the selected type
if (!isset($prev_doc_no)) {
    $prev_doc_no = '';
    $stmt_last = $con->prepare("SELECT TOP 1 doc_no FROM material_request_h WHERE company_code = ? AND branch_code = ? AND doc_type = ? ORDER BY doc_num DESC");
    $stmt_last->execute(array($company_code, $branch_code, $selected_doc_type));
    $row_last = $stmt_last->fetch(PDO::FETCH_ASSOC);
    if ($row_last) $prev_doc_no = $row_last['doc_no'];
}
if (!isset($next_doc_no)) $next_doc_no = '';

// Compute status badge from material_request_d approved column
$header_status = '';
if ($is_view_mode) {
    if (!empty($edit_details)) {
        $all_approved = true;
        foreach ($edit_details as $det_row) {
            if (strtoupper(trim($det_row['approved'] ?? 'N')) !== 'Y') {
                $all_approved = false;
                break;
            }
        }
        $header_status = $all_approved ? 'APPROVED' : 'NOT APPROVED';
    } else {
        $header_status = (strtoupper($doc_approved) === 'Y') ? 'APPROVED' : 'NOT APPROVED';
    }
}
?>
            <div class="mr-header doc-titlebar">
                <h1 class="doc-title-text">Material Request</h1>
                <div class="mr-title-wrapper">
                    <button type="button" class="doc-chip doc-chip-type" id="mrTitleBtn" onclick="toggleDocTypeMenu()" title="Click to switch document type">
                        <span id="mrTitleText"><?php echo htmlspecialchars($selected_doc_name); ?></span>
                        <i class="fa fa-caret-down"></i>
                    </button>
                    <div class="mr-doctype-menu" id="mrDocTypeMenu">
                        <?php foreach ($mr_doc_types as $dt_code => $dt_name): ?>
                        <div class="mr-doctype-item <?php echo ($dt_code === $selected_doc_type) ? 'active' : ''; ?>"
                             onclick="selectDocType('<?php echo htmlspecialchars($dt_code, ENT_QUOTES); ?>', '<?php echo htmlspecialchars($dt_name, ENT_QUOTES); ?>')"> 
                            <?php echo htmlspecialchars($dt_name); ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <span class="doc-title-spacer"></span>
                <div class="mr-info">
                    Entered by: <strong><?php echo htmlspecialchars($entered_by_user); ?></strong> - <?php echo htmlspecialchars($entered_by_date); ?><br>
                    Modified by: <strong><?php echo htmlspecialchars($modified_by_user); ?></strong> - <?php echo htmlspecialchars($modified_by_date); ?>
                </div>
            </div>
            <script>if (window.erpToolbarAdoptTitlebar) erpToolbarAdoptTitlebar();</script>

            <form id="mrForm" class="doc-contents" method="POST" onkeydown="return event.key !== 'Enter' || event.target.tagName === 'TEXTAREA'">
                <input type="hidden" name="is_update" id="is_update_field" value="0">
                <input type="hidden" name="doc_type_selected" id="doc_type_selected_field" value="<?php echo htmlspecialchars($selected_doc_type); ?>">
                <div class="doc-form-wrap">
                <div class="doc-layout no-aside">
                    <div class="doc-main">

                        <!-- ── Document ── -->
                        <div class="doc-section">
                            <h4 class="doc-section-title">Document</h4>
                            <div class="doc-fields">
                                <div class="doc-field">
                                    <label class="doc-lbl">Doc No</label>
                                    <input type="text" name="doc_no" class="doc-inp" value="<?php echo $doc_no; ?>" readonly>
                                </div>
                                <div class="doc-field">
                                    <label class="doc-lbl">Doc Date</label>
                                    <input type="date" name="doc_dt" class="doc-inp" value="<?php echo htmlspecialchars($doc_dt); ?>" <?php echo $is_view_mode ? 'disabled' : ''; ?>>
                                </div>
                                <div class="doc-field">
                                    <label class="doc-lbl">Department</label>
                                    <select name="dept" class="doc-inp" <?php echo $is_view_mode ? 'disabled' : ''; ?>>
                                        <option value="">Select Department</option>
                                        <?php
                                        $stmt_dept = $con->prepare("SELECT dept_code, dept_name FROM dept_master ORDER BY dept_name");
                                        $stmt_dept->execute();
                                        while ($row = $stmt_dept->fetch(PDO::FETCH_ASSOC)) {
                                            $dept_code = $row['dept_code'];
                                            $dept_name = $row['dept_name'];
                                            $selected_dept = ($dept === $dept_code) ? 'selected' : '';
                                            echo "<option value='" . htmlspecialchars($dept_code) . "' $selected_dept>" . htmlspecialchars($dept_code . ' - ' . $dept_name) . "</option>";
                                        }
                                        ?>
                                    </select>
                                </div>
                                <div class="doc-field">
                                    <label class="doc-lbl">Tracking No</label>
                                    <input type="text" name="tracking_no" class="doc-inp" value="<?php echo htmlspecialchars($tracking_no); ?>" <?php echo $is_view_mode ? 'readonly' : ''; ?>>
                                </div>
                            </div>
                        </div>

                        <!-- ── Site ── -->
                        <div class="doc-section">
                            <h4 class="doc-section-title">Site</h4>
                            <div class="doc-fields">
                                <div class="doc-field">
                                    <label class="doc-lbl">Site No</label>
                                    <input type="text" name="site_no" class="doc-inp" value="<?php echo htmlspecialchars($site_no); ?>" <?php echo $is_view_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="doc-field">
                                    <label class="doc-lbl">Site Location</label>
                                    <input type="text" name="site_loc" class="doc-inp" value="<?php echo htmlspecialchars($site_loc); ?>" <?php echo $is_view_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="doc-field">
                                    <label class="doc-lbl">Cont.Person</label>
                                    <input type="text" name="contact_person" class="doc-inp" value="<?php echo htmlspecialchars($contact_person); ?>" <?php echo $is_view_mode ? 'readonly' : ''; ?>>
                                </div>
                                <div class="doc-field">
                                    <label class="doc-lbl">Site Contact</label>
                                    <input type="text" name="site_contact" class="doc-inp" value="<?php echo htmlspecialchars($site_contact); ?>" <?php echo $is_view_mode ? 'readonly' : ''; ?>>
                                </div>
                            </div>
                        </div>

                        <!-- ── Request ── -->
                        <?php
                        $user_options = "<option value=''>Select User</option>";
                        $stmt_bu = $con->prepare("SELECT DISTINCT user_id FROM branch_user WHERE branch_code = ? ORDER BY user_id");
                        $stmt_bu->execute(array($branch_code));
                        while ($row = $stmt_bu->fetch(PDO::FETCH_ASSOC)) {
                            $u_id = htmlspecialchars($row['user_id']);
                            $user_options .= "<option value='$u_id'>$u_id</option>";
                        }
                        ?>
                        <div class="doc-section">
                            <h4 class="doc-section-title">Request</h4>
                            <div class="doc-fields cols-3">
                                <div class="doc-field">
                                    <label class="doc-lbl">Request By</label>
                                    <select name="req_by" class="doc-inp" <?php echo $is_view_mode ? 'disabled' : ''; ?>>
                                        <?php echo str_replace("value='$req_by'", "value='$req_by' selected", $user_options); ?>
                                    </select>
                                </div>
                                <div class="doc-field">
                                    <label class="doc-lbl">Request To</label>
                                    <select name="req_to" class="doc-inp" <?php echo $is_view_mode ? 'disabled' : ''; ?>>
                                        <?php echo str_replace("value='$req_to'", "value='$req_to' selected", $user_options); ?>
                                    </select>
                                </div>
                                <div class="doc-field">
                                    <label class="doc-lbl">Approval To</label>
                                    <select name="approval_to" class="doc-inp" <?php echo $is_view_mode ? 'disabled' : ''; ?>>
                                        <?php echo str_replace("value='$approval_to'", "value='$approval_to' selected", $user_options); ?>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- ── Notes ── -->
                        <div class="doc-section">
                            <h4 class="doc-section-title">Notes</h4>
                            <div class="doc-fields cols-2">
                                <div class="doc-field">
                                    <label class="doc-lbl">Narration</label>
                                    <textarea name="narration" class="doc-inp auto-resize" rows="1" <?php echo $is_view_mode ? 'readonly' : ''; ?>><?php echo htmlspecialchars($narration); ?></textarea>
                                </div>
                                <div class="doc-field">
                                    <label class="doc-lbl">Remark</label>
                                    <textarea name="remark" class="doc-inp auto-resize" rows="1" <?php echo $is_view_mode ? 'readonly' : ''; ?>><?php echo htmlspecialchars($header_remark); ?></textarea>
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
                        <div class="doc-sum-line"><span>Lines</span><b id="mrSumLines">0</b></div>
                        <div class="doc-sum-line"><span>Requested qty</span><b id="mrSumRq">0.00</b></div>
                        <div class="doc-sum-line"><span>Approved qty</span><b id="mrSumApr">0.00</b></div>
                    </section>
                    <?php include __DIR__ . '/../../../shared/doc_attachments_panel.php'; ?>
                </aside>
<?php /* <form id="mrForm"> stays open — closed in detail/d_material_request_d_01.php */ ?>

    <script>
        const DOC_APPROVED = '<?php echo $doc_approved; ?>';
        const DOC_CANCELLED = '<?php echo $doc_cancelled; ?>';
        const MRQ_APPROVAL_REQUIRED = '<?php echo $mrq_approval_required; ?>';
        let isEditActive = <?php echo $is_view_mode ? 'false' : 'true'; ?>;

        // Side panel summary: line count and quantity totals from the item grid
        function updateMrSummary() {
            var lines = 0, rq = 0, apr = 0;
            document.querySelectorAll('#itemsBody tr').forEach(function (tr) {
                var rqEl = tr.querySelector('input[name="rq_qty[]"]');
                if (!rqEl) return;
                var aprEl = tr.querySelector('input[name="apr_qty[]"]');
                lines++;
                rq  += parseFloat(rqEl.value) || 0;
                apr += aprEl ? (parseFloat(aprEl.value) || 0) : 0;
            });
            document.getElementById('mrSumLines').textContent = lines;
            document.getElementById('mrSumRq').textContent    = rq.toFixed(2);
            document.getElementById('mrSumApr').textContent   = apr.toFixed(2);
        }
        document.addEventListener('DOMContentLoaded', function () {
            var body = document.getElementById('itemsBody');
            if (!body) return;
            updateMrSummary();
            new MutationObserver(updateMrSummary).observe(body, { childList: true });
            body.addEventListener('input', updateMrSummary);
        });

        function mrUrl(queryString) {
            var url = <?php echo json_encode(erp_url('modules/material_request/material_request')); ?> + '?' + String(queryString).replace(/^\?/, '');
            if (window.location.search.indexOf('embed=1') >= 0 && url.indexOf('embed=1') < 0) {
                url += '&embed=1';
            }
            return url;
        }

        // ---- Prior / Next Navigation ----
        const PREV_DOC_NO = '<?php echo addslashes($prev_doc_no); ?>';
        const NEXT_DOC_NO = '<?php echo addslashes($next_doc_no); ?>';
        const CURRENT_DOC_TYPE = '<?php echo addslashes($selected_doc_type); ?>';

        function navPrior() {
            if (PREV_DOC_NO) window.location.href = mrUrl('doc_no=' + encodeURIComponent(PREV_DOC_NO));
        }
        function navNext() {
            if (NEXT_DOC_NO) {
                window.location.href = mrUrl('doc_no=' + encodeURIComponent(NEXT_DOC_NO));
            } else {
                window.location.href = mrUrl('type=' + encodeURIComponent(CURRENT_DOC_TYPE));
            }
        }

        // ---- Doc Type Title Dropdown ----
        function toggleDocTypeMenu() {
            const menu = document.getElementById('mrDocTypeMenu');
            menu.classList.toggle('open');
        }

        function selectDocType(code, name) {
            document.getElementById('mrTitleText').textContent = name;
            var codeEl = document.getElementById('mrTitleCode');
            if (codeEl) codeEl.textContent = code;
            document.getElementById('doc_type_selected_field').value = code;
            document.getElementById('mrDocTypeMenu').classList.remove('open');
            // Navigate to fresh new doc with selected type
            window.location.href = mrUrl('type=' + encodeURIComponent(code));
        }

        // Close dropdown when clicking outside
        document.addEventListener('click', function(e) {
            const wrapper = document.getElementById('mrTitleBtn') ? document.getElementById('mrTitleBtn').closest('.mr-title-wrapper') : null;
            if (wrapper && !wrapper.contains(e.target)) {
                const menu = document.getElementById('mrDocTypeMenu');
                if (menu) menu.classList.remove('open');
            }
        });

        // Auto-resize textareas (Narration, Remark)
        document.querySelectorAll('textarea.auto-resize').forEach(function (ta) {
            function resize() {
                ta.style.height = 'auto';
                ta.style.height = ta.scrollHeight + 'px';
            }
            ta.addEventListener('input', resize);
            if (ta.value) resize(); // size on page load for pre-filled values
        });

        function enableEditMode() {
            // If approval is required, block only when already approved (and not cancelled)
            if (MRQ_APPROVAL_REQUIRED === 'Y' && DOC_APPROVED === 'Y' && DOC_CANCELLED !== 'Y') {
                alert("This document is approved. Editing is not allowed.");
                return;
            }
            const form = document.getElementById('mrForm');
            const inputs = form.querySelectorAll(
                'input:not([name="doc_no"]):not([name="line_no[]"]):not([name="apr_qty[]"]):not([name="stock[]"]), select, textarea'
            );
            inputs.forEach(el => {
                if (el.type !== 'hidden' && !el.closest('#itemsBody')) {
                    el.removeAttribute('readonly');
                    el.removeAttribute('disabled');
                }
            });

            document.getElementById('is_update_field').value = '1';

            const saveButtons = document.querySelectorAll('button[name="save_mr"]');
            saveButtons.forEach(btn => btn.removeAttribute('disabled'));

            // Unlock the item lines (detail fragment)
            if (typeof mrDetailEnableEdit === 'function') {
                mrDetailEnableEdit();
            }
            isEditActive = true;
        }

        // ── Register shared toolbar callbacks ────────────────────────────────────
        window.erpToolbar = window.erpToolbar || {};

        // SAVE — submit the form through its Save button (stays inactive until edit is enabled)
        window.erpToolbar.onSave = function () {
            if (!isEditActive) return;
            var btn = document.querySelector('#mrForm button[name="save_mr"]');
            if (btn) btn.click();
        };

        // NEW — open a blank document of the same type
        window.erpToolbar.onNew = function () {
            window.location.href = mrUrl('type=' + encodeURIComponent(CURRENT_DOC_TYPE));
        };

        // PREVIEW — print preview of the saved document
        window.erpToolbar.onPreview = function () {
            window.open('../../mr_print.php?doc_no=<?php echo urlencode($doc_no); ?>', '_blank');
        };

        window.erpToolbar.onPrior = navPrior;
        window.erpToolbar.onNext  = navNext;

        // FIND — open the document picked in the toolbar's search modal
        window.erpToolbar.onDocSelect = function (doc_no) {
            window.location.href = mrUrl('doc_no=' + encodeURIComponent(doc_no));
        };

        // EDIT — called by the toolbar after the edit password is verified
        window.erpToolbar.onEditVerified = function () {
            alert("Password verified. Edit mode enabled.");
            try { enableEditMode(); } catch(e) { console.error(e); }
        };

        // Keep the toolbar's navigation state in line with the loaded document
        window.ERP_PREV_DOC_NO = PREV_DOC_NO;
        window.ERP_NEXT_DOC_NO = NEXT_DOC_NO;
        window.ERP_DOC_TYPE    = CURRENT_DOC_TYPE;
        (function () {
            var tbPrior = document.getElementById('tbPrior');
            if (tbPrior) tbPrior.disabled = (PREV_DOC_NO === '');
            var tbPreview = document.getElementById('tbPreview');
            if (tbPreview) tbPreview.disabled = <?php echo (!$is_view_mode || empty($doc_no)) ? 'true' : 'false'; ?>;
        })();
    </script>
