<?php
// Stock Transfer In Header
// Fragment included by modules/stock_transfer_in/stock_transfer_in.php
// Owns: doc type, doc no, save (header + lines), document load, title bar and header fields.
// The <form id="stiForm"> opened here is closed by the detail fragment (d_stock_transfer_in_d_01),
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

// Select From Transfer Out (AJAX): pending transfer out documents / their pending items.
// Pending = transfer out qty minus qty already received (view_stk_out_in_balance) > 0.
$sti_ajax = $_POST['action'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($sti_ajax === 'sti_pending_docs' || $sti_ajax === 'sti_pending_items')) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    $sti_enc = function ($s) {
        return $s === null ? '' : mb_convert_encoding(trim((string) $s), 'UTF-8', 'ISO-8859-1');
    };
    try {
        if ($sti_ajax === 'sti_pending_docs') {
            $branch_like = (($_POST['all_branch'] ?? '0') === '1') ? '%' : $branch_code;
            $stmt = $con->prepare(
                "SELECT b.branchcode, b.docno, MAX(h.doc_dt) AS doc_dt,
                        MAX(h.location_from) AS location_from, MAX(h.location_to) AS location_to
                 FROM (SELECT companycode, branchcode, docno, itemno, itemcode, itemname
                       FROM view_stk_out_in_balance
                       WHERE companycode = ? AND branchcode LIKE ?
                       GROUP BY companycode, branchcode, docno, itemno, itemcode, itemname
                       HAVING SUM(txnqty) > 0) b
                 INNER JOIN stock_transfer_out_h h
                    ON h.company_code = b.companycode AND h.branch_code = b.branchcode AND h.doc_no = b.docno
                 WHERE b.docno LIKE ?
                 GROUP BY b.branchcode, b.docno
                 ORDER BY b.docno DESC"
            );
            $stmt->execute([$company_code, $branch_like, '%' . trim($_POST['term'] ?? '') . '%']);
            $docs = [];
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $docs[] = [
                    'branch'   => $sti_enc($r['branchcode']),
                    'doc_no'   => $sti_enc($r['docno']),
                    'doc_dt'   => $r['doc_dt'] ? date('d/m/Y', strtotime($r['doc_dt'])) : '',
                    'loc_from' => $sti_enc($r['location_from']),
                    'loc_to'   => $sti_enc($r['location_to']),
                ];
            }
            echo json_encode($docs);
        } else {
            $stmt = $con->prepare(
                "SELECT DISTINCT SPACE(1) AS selected, SUM(view_stk_out_in_balance.txnqty) AS txnqty, view_stk_out_in_balance.companycode,
                        view_stk_out_in_balance.branchcode, view_stk_out_in_balance.docno, view_stk_out_in_balance.itemcode,
                        view_stk_out_in_balance.itemname, MAX(view_stk_out_in_balance.txnunit) AS unit, 0.00 AS qty,
                        MAX(stock_transfer_out_d.serial_no) AS serialno, view_stk_out_in_balance.itemno,
                        MAX(stock_transfer_out_d.trans_qty) AS outqty
                 FROM view_stk_out_in_balance, stock_transfer_out_d
                 WHERE (view_stk_out_in_balance.companycode = stock_transfer_out_d.company_code)
                   AND (view_stk_out_in_balance.docno = stock_transfer_out_d.doc_no)
                   AND (view_stk_out_in_balance.itemno = stock_transfer_out_d.item_no)
                   AND (view_stk_out_in_balance.branchcode = stock_transfer_out_d.branch_code)
                   AND (view_stk_out_in_balance.itemcode = stock_transfer_out_d.item_code)
                   AND ((view_stk_out_in_balance.companycode = ?) AND (view_stk_out_in_balance.branchcode LIKE ?)
                   AND (view_stk_out_in_balance.docno = ?))
                 GROUP BY view_stk_out_in_balance.companycode, view_stk_out_in_balance.branchcode, view_stk_out_in_balance.docno,
                          view_stk_out_in_balance.itemno, view_stk_out_in_balance.itemcode, view_stk_out_in_balance.itemname
                 HAVING (SUM(view_stk_out_in_balance.txnqty) > 0)
                 ORDER BY serialno"
            );
            $doc_branch = trim($_POST['branch'] ?? '');
            $stmt->execute([$company_code, ($doc_branch !== '' ? $doc_branch : $branch_code), trim($_POST['doc_no'] ?? '')]);
            $items = [];
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $items[] = [
                    'doc_no'    => $sti_enc($r['docno']),
                    'item_no'   => $sti_enc($r['itemno']),
                    'item_code' => $sti_enc($r['itemcode']),
                    'item_name' => $sti_enc($r['itemname']),
                    'unit'      => $sti_enc($r['unit']),
                    'balance'   => (float) $r['txnqty'],
                    'out_qty'   => (float) $r['outqty'],
                ];
            }
            echo json_encode($items);
        }
    } catch (Exception $e) {
        error_log('stock_transfer_in select transfer out error: ' . $e->getMessage());
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// Available Stock Transfer In doc types (defined in doc_type_master AND enabled in document_setup)
$sti_doc_code = isset($doc_code) && $doc_code !== '' ? $doc_code : 'TI';
$sti_doc_types = erp_doc_configured_types($con, $company_code, $branch_code, $sti_doc_code);

$selected_doc_type = (isset($doc_type) && $doc_type !== '')
    ? $doc_type
    : erp_doc_default_type($con, $company_code, $branch_code, $sti_doc_code, $sti_doc_types);
if ($selected_doc_type === '') {
    $selected_doc_type = erp_doc_master_type($con, $company_code, $branch_code, $sti_doc_code);
}
$sti_posted_doc_type = strtoupper(trim($_POST['doc_type_selected'] ?? ''));
if (!isset($_GET['type']) && !isset($_GET['doc_type']) && isset($sti_doc_types[$sti_posted_doc_type])) {
    $selected_doc_type = $sti_posted_doc_type;
}
$selected_doc_name = $sti_doc_types[$selected_doc_type]
    ?? erp_doc_type_name($con, $company_code, $branch_code, $sti_doc_code, $selected_doc_type);

// Doc No — prefix/digits from doc_number (company + branch + doc_code + doc_type)
function stiNextDocNo($con, $company_code, $branch_code, $doc_code, $doc_type)
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
        "SELECT doc_no FROM stock_transfer_in_h WITH (UPDLOCK, HOLDLOCK)
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
function stiDiscardPageOutput()
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
}

function stiAbortSave($con, $message)
{
    if ($con->inTransaction()) {
        $con->rollBack();
    }
    stiDiscardPageOutput();
    die($message);
}

// Locations of the logged-in company/branch
$sti_locations = [];
try {
    $stmt_loc = $con->prepare("SELECT location_code, location_name FROM location_master WHERE company_code = ? AND branch_code = ? ORDER BY location_code");
    $stmt_loc->execute([$company_code, $branch_code]);
    while ($r = $stmt_loc->fetch(PDO::FETCH_ASSOC)) {
        $sti_locations[trim($r['location_code'])] = trim((string) $r['location_name']);
    }
} catch (Exception $e) {
    error_log("stock_transfer_in location fetch error: " . $e->getMessage());
}

// Branches of the logged-in company
$sti_branches = [];
try {
    $stmt_br = $con->prepare("SELECT branch_code, branch_name FROM branch_master WHERE company_code = ? ORDER BY branch_name");
    $stmt_br->execute([$company_code]);
    while ($r = $stmt_br->fetch(PDO::FETCH_ASSOC)) {
        $sti_branches[trim($r['branch_code'])] = trim((string) $r['branch_name']);
    }
} catch (Exception $e) {
    error_log("stock_transfer_in branch fetch error: " . $e->getMessage());
}

// Handle Form Submission
if (isset($_POST['save_sti'])) {
    $is_update = isset($_POST['is_update']) && $_POST['is_update'] == '1';

    if (!$is_update && !isset($sti_doc_types[$sti_posted_doc_type])) {
        stiAbortSave($con, "Document type '" . $sti_posted_doc_type . "' is not enabled in Document Setup for " . $sti_doc_code . ".");
    }

    $doc_dt           = trim($_POST['doc_dt'] ?? '') ?: date('Y-m-d');
    $branch_to        = trim($_POST['branch_to'] ?? '');
    $location_from    = trim($_POST['location_from'] ?? '');
    $location_to      = trim($_POST['location_to'] ?? '');
    $narration        = mb_substr(trim($_POST['narration'] ?? ''), 0, 250);
    $update_inventory = (($_POST['update_inventory'] ?? 'N') === 'Y') ? 'Y' : 'N';

    if ($location_from === '' || !isset($sti_locations[$location_from])) {
        stiAbortSave($con, "Please select Location From (OUT).");
    }
    if ($location_to === '' || !isset($sti_locations[$location_to])) {
        stiAbortSave($con, "Please select Location To (IN).");
    }
    if ($branch_to !== '' && !isset($sti_branches[$branch_to])) {
        stiAbortSave($con, "Invalid Branch To.");
    }
    if ($location_from === $location_to && ($branch_to === '' || $branch_to === $branch_code)) {
        stiAbortSave($con, "Location From and Location To cannot be the same.");
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
                stiAbortSave($con, "Enter a quantity for item " . $code . ".");
            }
            $lines[] = [
                'item_no'   => trim((string) ($_POST['line_no'][$i] ?? '')),
                'item_code' => $code,
                'item_desc' => mb_substr(trim((string) ($_POST['item_desc'][$i] ?? '')), 0, 250),
                'bin'       => mb_substr(trim((string) ($_POST['bin'][$i] ?? '')), 0, 15),
                'stock'     => (float) ($_POST['stock'][$i] ?? 0),
                'qty'       => $qty,
                'unit'      => mb_substr(trim((string) ($_POST['unit'][$i] ?? '')), 0, 5),
                'to_no'     => mb_substr(trim((string) ($_POST['transfer_outno'][$i] ?? '')), 0, 20),
                'to_qty'    => (float) ($_POST['transfer_outqty'][$i] ?? 0),
            ];
        }
    }
    if (empty($lines)) {
        stiAbortSave($con, "Add at least one item line.");
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
        error_log("stock_transfer_in currency fetch error: " . $e->getMessage());
    }

    // On INSERT, doc_no is generated under UPDLOCK/HOLDLOCK inside the transaction
    $con->beginTransaction();
    try {
        $final_doc_no = $is_update
            ? trim($_POST['doc_no'] ?? '')
            : stiNextDocNo($con, $company_code, $branch_code, $sti_doc_code, $selected_doc_type);
    } catch (Exception $e) {
        stiAbortSave($con, "Error generating document number: " . $e->getMessage());
    }

    $doc_num = 0;
    if (preg_match('/(\d+)$/', $final_doc_no, $numVal)) {
        $doc_num = intval($numVal[1]);
    }

    try {
        if ($is_update) {
            $stmt_chk = $con->prepare("SELECT received FROM stock_transfer_in_h WHERE doc_no = ? AND company_code = ? AND branch_code = ?");
            $stmt_chk->execute([$final_doc_no, $company_code, $branch_code]);
            $row_chk = $stmt_chk->fetch(PDO::FETCH_ASSOC);
            if (!$row_chk) {
                stiAbortSave($con, "Document " . $final_doc_no . " was not found.");
            }
            if (strtoupper(trim((string) $row_chk['received'])) === 'Y') {
                stiAbortSave($con, "Document " . $final_doc_no . " has already been received. Editing is not allowed.");
            }
            $stmt_h = $con->prepare(
                "UPDATE stock_transfer_in_h
                 SET doc_dt = ?, location_from = ?, location_to = ?, branch_to = ?, update_inventory = ?,
                     narration = ?, last_userid = ?, last_userdate = GETDATE()
                 WHERE doc_no = ? AND company_code = ? AND branch_code = ?"
            );
            $stmt_h->execute([
                $doc_dt, $location_from, $location_to, ($branch_to !== '' ? $branch_to : null), $update_inventory,
                ($narration !== '' ? $narration : null), $user,
                $final_doc_no, $company_code, $branch_code,
            ]);
            $stmt_del = $con->prepare("DELETE FROM stock_transfer_in_d WHERE doc_no = ? AND company_code = ? AND branch_code = ?");
            $stmt_del->execute([$final_doc_no, $company_code, $branch_code]);
        } else {
            $stmt_h = $con->prepare(
                "INSERT INTO stock_transfer_in_h (
                    company_code, branch_code, doc_no, doc_num, doc_dt, doc_type,
                    location_from, location_to, branch_to, update_inventory,
                    currency_code, exchange_rate, amount, narration,
                    user_id, user_date, last_userid, last_userdate, received
                ) VALUES (
                    ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, 0, ?,
                    ?, GETDATE(), ?, GETDATE(), 'N'
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
        stiAbortSave($con, "Error saving header: " . $e->getMessage());
    }

    // Lines picked from a transfer out cannot receive more than its pending balance
    // (on update this document's own lines were deleted above, so they are not counted).
    $to_need = [];
    foreach ($lines as $ln) {
        if ($ln['to_no'] === '') continue;
        $key = $ln['to_no'] . '|' . $ln['item_no'] . '|' . $ln['item_code'];
        $to_need[$key] = ($to_need[$key] ?? 0) + $ln['qty'];
    }
    if ($to_need) {
        $stmt_bal = $con->prepare(
            "SELECT ISNULL(SUM(txnqty), 0) FROM view_stk_out_in_balance
             WHERE companycode = ? AND docno = ? AND itemno = ? AND itemcode = ?"
        );
        foreach ($to_need as $key => $need) {
            [$k_doc, $k_no, $k_code] = explode('|', $key, 3);
            try {
                $stmt_bal->execute([$company_code, $k_doc, $k_no, $k_code]);
                $bal = (float) $stmt_bal->fetchColumn();
            } catch (Exception $e) {
                stiAbortSave($con, "Error checking transfer out balance: " . $e->getMessage());
            }
            if ($need > $bal + 0.0001) {
                stiAbortSave($con, "Item " . $k_code . " of transfer out " . $k_doc . ": quantity " . $need . " is more than the pending quantity " . $bal . ".");
            }
        }
    }

    $stmt_d = $con->prepare(
        "INSERT INTO stock_transfer_in_d (
            company_code, branch_code, doc_no, serial_no, item_no, item_code, item_description,
            item_qty, item_unit, upp, trans_qty, trans_unit, trans_rate, item_cost,
            location_code, location_bincode, stock_qty, transfer_outno, transfer_outqty
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?,
            ?, ?, 1, ?, ?, 0, 0,
            ?, ?, ?, ?, ?
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
                $location_to, ($ln['bin'] !== '' ? $ln['bin'] : null), $ln['stock'],
                ($ln['to_no'] !== '' ? $ln['to_no'] : null), ($ln['to_no'] !== '' ? $ln['to_qty'] : null),
            ]);
        } catch (Exception $e) {
            stiAbortSave($con, "Error saving detail line $srl: " . $e->getMessage());
        }
    }

    $con->commit();

    $stay_url = erp_page_url('modules/stock_transfer_in/stock_transfer_in?doc_no=' . rawurlencode($final_doc_no));
    stiDiscardPageOutput();
    echo "<script>alert('Document Saved with No: " . addslashes($final_doc_no) . "'); window.location.href=" . json_encode($stay_url) . ";</script>";
    exit;
}

// ── New document defaults ──────────────────────────────────────────────────
$doc_no = stiNextDocNo($con, $company_code, $branch_code, $sti_doc_code, $selected_doc_type);
$doc_dt = date("Y-m-d");
$branch_to = '';
$location_from = '';
$location_to = '';
$narration = '';
$doc_received = 'N';
$sti_lines = [];

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
    error_log("stock_transfer_in control_table fetch error: " . $e->getMessage());
}

$sti_currency = '';
$sti_exrate = '';
try {
    $stmt_bc = $con->prepare("SELECT currency_code, exchange_rate FROM branch_master WHERE company_code = ? AND branch_code = ?");
    $stmt_bc->execute([$company_code, $branch_code]);
    $row_bc = $stmt_bc->fetch(PDO::FETCH_ASSOC);
    if ($row_bc) {
        $sti_currency = trim((string) $row_bc['currency_code']);
        $sti_exrate = $row_bc['exchange_rate'] !== null ? (string) (float) $row_bc['exchange_rate'] : '';
    }
} catch (Exception $e) {
    error_log("stock_transfer_in branch currency fetch error: " . $e->getMessage());
}

$is_view_mode = false;
$entered_by_user = strtoupper($user);
$entered_by_date = date('d/m/Y H:i');
$modified_by_user = strtoupper($user);
$modified_by_date = date('d/m/Y H:i');

if (isset($_GET['doc_no'])) {
    $search_doc = trim($_GET['doc_no']);
    $stmt_edit = $con->prepare("SELECT * FROM stock_transfer_in_h WHERE doc_no = ? AND company_code = ? AND branch_code = ?");
    $stmt_edit->execute([$search_doc, $company_code, $branch_code]);
    $row_edit = $stmt_edit->fetch(PDO::FETCH_ASSOC);
    if ($row_edit) {
        $doc_no = trim($row_edit['doc_no']);
        if (!empty($row_edit['doc_type'])) {
            $selected_doc_type = strtoupper(trim($row_edit['doc_type']));
            $selected_doc_name = $sti_doc_types[$selected_doc_type]
                ?? erp_doc_type_name($con, $company_code, $branch_code, $sti_doc_code, $selected_doc_type);
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
            $sti_currency = trim((string) $row_edit['currency_code']);
        }
        if (($row_edit['exchange_rate'] ?? null) !== null) {
            $sti_exrate = (string) (float) $row_edit['exchange_rate'];
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
                    stock_qty, trans_qty, trans_unit, item_unit, transfer_outno, transfer_outqty
             FROM stock_transfer_in_d
             WHERE doc_no = ? AND company_code = ? AND branch_code = ?
             ORDER BY serial_no ASC"
        );
        $stmt_det->execute([$doc_no, $company_code, $branch_code]);
        while ($d = $stmt_det->fetch(PDO::FETCH_ASSOC)) {
            $sti_lines[] = [
                'line'  => trim((string) $d['item_no']),
                'code'  => trim((string) $d['item_code']),
                'desc'  => trim((string) $d['item_description']),
                'loc'   => trim((string) ($d['location_code'] ?: $location_to)),
                'bin'   => trim((string) $d['location_bincode']),
                'stock' => (float) $d['stock_qty'],
                'qty'   => (float) $d['trans_qty'],
                'unit'  => trim((string) ($d['trans_unit'] ?: $d['item_unit'])),
                'to_no' => trim((string) $d['transfer_outno']),
                'to_qty' => (float) $d['transfer_outqty'],
                'to_bal' => 0,
            ];
        }

        // Pending to receive before this document: the view already nets this
        // document's own lines, so they are added back.
        $stmt_bal = $con->prepare(
            "SELECT ISNULL(SUM(txnqty), 0) FROM view_stk_out_in_balance
             WHERE companycode = ? AND docno = ? AND itemno = ? AND itemcode = ?"
        );
        $own_qty = [];
        foreach ($sti_lines as $ln) {
            if ($ln['to_no'] === '') continue;
            $key = $ln['to_no'] . '|' . $ln['line'] . '|' . $ln['code'];
            $own_qty[$key] = ($own_qty[$key] ?? 0) + $ln['qty'];
        }
        foreach ($sti_lines as &$ln) {
            if ($ln['to_no'] === '') continue;
            $key = $ln['to_no'] . '|' . $ln['line'] . '|' . $ln['code'];
            try {
                $stmt_bal->execute([$company_code, $ln['to_no'], $ln['line'], $ln['code']]);
                $ln['to_bal'] = (float) $stmt_bal->fetchColumn() + $own_qty[$key];
            } catch (Exception $e) {
                error_log("stock_transfer_in balance fetch error: " . $e->getMessage());
            }
        }
        unset($ln);
    }
}

if (!function_exists('stiLocationOptions')) {
    function stiLocationOptions(array $locations, $selected)
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
                <h1 class="doc-title-text">Stock Transfer In</h1>
                <div class="mr-title-wrapper">
                    <button type="button" class="doc-chip doc-chip-type" id="stiTitleBtn" onclick="toggleDocTypeMenu()" title="Click to switch document type">
                        <span id="stiTitleText"><?php echo htmlspecialchars($selected_doc_name); ?></span>
                        <i class="fa fa-caret-down"></i>
                    </button>
                    <div class="mr-doctype-menu" id="stiDocTypeMenu">
                        <?php foreach ($sti_doc_types as $dt_code => $dt_name): ?>
                        <div class="mr-doctype-item <?php echo ($dt_code === $selected_doc_type) ? 'active' : ''; ?>"
                             onclick="selectDocType('<?php echo htmlspecialchars($dt_code, ENT_QUOTES); ?>')">
                            <?php echo htmlspecialchars($dt_name); ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php /* Update Inventory checkbox hidden; the hidden update_inventory field keeps the control_table / saved value.
                <label class="doc-chip doc-chip-type mb-0" title="Post this transfer to inventory">
                    <input type="checkbox" id="stiUpdateInventory" onchange="document.getElementById('stiUpdateInventoryHidden').value = this.checked ? 'Y' : 'N';"
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

            <form id="stiForm" class="doc-contents" method="POST" onkeydown="return event.key !== 'Enter' || event.target.tagName === 'TEXTAREA'">
                <input type="hidden" name="is_update" id="is_update_field" value="0">
                <input type="hidden" name="doc_type_selected" id="doc_type_selected_field" value="<?php echo htmlspecialchars($selected_doc_type); ?>">
                <input type="hidden" name="update_inventory" id="stiUpdateInventoryHidden" value="<?php echo $update_inventory ? 'Y' : 'N'; ?>">
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
                                    <input type="text" class="doc-inp" value="<?php echo htmlspecialchars($sti_currency); ?>" readonly>
                                </div>
                                <div class="doc-field">
                                    <label class="doc-lbl">EX.Rate</label>
                                    <input type="text" class="doc-inp text-right" value="<?php echo htmlspecialchars($sti_exrate); ?>" readonly>
                                </div>
                            </div>
                        </div>

                        <!-- ── Location ── -->
                        <div class="doc-section">
                            <h4 class="doc-section-title">Location</h4>
                            <div class="doc-fields cols-2">
                                <div class="doc-field">
                                    <label class="doc-lbl">Location From (OUT)</label>
                                    <select name="location_from" id="stiLocFrom" class="doc-inp" <?php echo $is_view_mode ? 'disabled' : ''; ?>>
                                        <?php echo stiLocationOptions($sti_locations, $location_from); ?>
                                    </select>
                                </div>
                                <div class="doc-field">
                                    <label class="doc-lbl">Location To (IN)</label>
                                    <div class="doc-inp-group">
                                        <select name="location_to" id="stiLocTo" class="doc-inp" onchange="if (typeof stiRefreshLines === 'function') stiRefreshLines();" <?php echo $is_view_mode ? 'disabled' : ''; ?>>
                                            <?php echo stiLocationOptions($sti_locations, $location_to); ?>
                                        </select>
                                        <button type="button" class="doc-more doc-more-soft" id="stiSelectToBtn" onclick="stiOpenTransferOut()" <?php echo $is_view_mode ? 'disabled' : ''; ?>><i class="fa fa-check-square-o"></i> Select From Transfer Out</button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- ── Notes ── -->
                        <div class="doc-section">
                            <h4 class="doc-section-title">Notes</h4>
                            <div class="doc-fields cols-2">
                                <div class="doc-field span-2">
                                    <label class="doc-lbl">Narration</label>
                                    <textarea name="narration" class="doc-inp auto-resize" rows="1" maxlength="250" <?php echo $is_view_mode ? 'readonly' : ''; ?>><?php echo htmlspecialchars($narration); ?></textarea>
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
                        <div class="doc-sum-line"><span>Lines</span><b id="stiSumLines">0</b></div>
                        <div class="doc-sum-line"><span>Total qty</span><b id="stiSumQty">0.00</b></div>
                    </section>
                    <?php include __DIR__ . '/../../../shared/doc_attachments_panel.php'; ?>
                </aside>
<?php /* <form id="stiForm"> stays open — closed in detail/d_stock_transfer_in_d_01.php */ ?>

    <!-- Select From Transfer Out Modal -->
    <div id="stiToModal" class="popup-overlay erp-search" style="display:none;">
        <div class="erp-search-dialog xl" role="dialog" aria-modal="true">
            <div class="erp-search-head">
                <h4 class="erp-search-title"><i class="fa fa-search"></i>Select From Transfer Out</h4>
                <div class="erp-search-actions">
                    <label class="erp-search-check">
                        <input type="checkbox" id="stiToAllBranch" checked onchange="stiSearchToDocs()"> All Branch
                    </label>
                    <button type="button" class="erp-search-btn primary sm" onclick="stiAddFromTransferOut()">Add</button>
                    <button type="button" class="erp-search-close" onclick="stiCloseTransferOut()" title="Close" aria-label="Close"><i class="fa fa-times"></i></button>
                </div>
            </div>

            <div class="erp-search-panes">
                <!-- Left: pending transfer out documents -->
                <div class="erp-search-pane">
                    <div class="erp-search-pane-head">
                        <div class="erp-search-input">
                            <i class="fa fa-search"></i>
                            <input type="text" id="stiToSearch" autocomplete="off" placeholder="Search Doc No..." onkeyup="stiSearchToDocs()">
                        </div>
                    </div>
                    <div class="erp-search-body">
                        <table class="erp-search-table" id="stiToDocsTable">
                            <thead>
                                <tr>
                                    <th>Doc No</th>
                                    <th>Date</th>
                                    <th>Branch</th>
                                </tr>
                            </thead>
                            <tbody style="cursor: pointer;"></tbody>
                        </table>
                    </div>
                </div>

                <!-- Right: pending items of the selected document -->
                <div class="erp-search-pane wide">
                    <div class="erp-search-pane-head">
                        <h6 class="erp-search-pane-title" id="stiToTitle">Select a Transfer Out document</h6>
                    </div>
                    <div class="erp-search-body">
                        <table class="erp-search-table" id="stiToItemsTable">
                            <thead>
                                <tr>
                                    <th style="min-width: 40px;">No</th>
                                    <th style="min-width: 90px;">Item Code</th>
                                    <th style="min-width: 160px;">Item Description</th>
                                    <th style="min-width: 70px;">Qty</th>
                                    <th style="min-width: 50px;">Unit</th>
                                    <th style="text-align:center; min-width: 36px;"><input type="checkbox" id="stiToAll" onchange="stiToggleAllTo(this)"></th>
                                    <th style="min-width: 100px;">Qty</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr><td colspan="7" class="text-center text-muted p-3">Select a document from the left panel</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        const STI_RECEIVED = '<?php echo $doc_received; ?>';
        let isEditActive = <?php echo $is_view_mode ? 'false' : 'true'; ?>;

        function stiUrl(queryString) {
            var url = <?php echo json_encode(erp_url('modules/stock_transfer_in/stock_transfer_in')); ?> + '?' + String(queryString).replace(/^\?/, '');
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
            if (PREV_DOC_NO) window.location.href = stiUrl('doc_no=' + encodeURIComponent(PREV_DOC_NO));
        }
        function navNext() {
            if (NEXT_DOC_NO) {
                window.location.href = stiUrl('doc_no=' + encodeURIComponent(NEXT_DOC_NO));
            } else {
                window.location.href = stiUrl('type=' + encodeURIComponent(CURRENT_DOC_TYPE));
            }
        }

        // ---- Doc Type Title Dropdown ----
        function toggleDocTypeMenu() {
            document.getElementById('stiDocTypeMenu').classList.toggle('open');
        }

        function selectDocType(code) {
            document.getElementById('stiDocTypeMenu').classList.remove('open');
            window.location.href = stiUrl('type=' + encodeURIComponent(code));
        }

        document.addEventListener('click', function (e) {
            var btn = document.getElementById('stiTitleBtn');
            var wrapper = btn ? btn.closest('.mr-title-wrapper') : null;
            if (wrapper && !wrapper.contains(e.target)) {
                var menu = document.getElementById('stiDocTypeMenu');
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
            if (STI_RECEIVED === 'Y') {
                alert("This transfer has already been received. Editing is not allowed.");
                return false;
            }
            const form = document.getElementById('stiForm');
            form.querySelectorAll('input:not([name="doc_no"]), select, textarea').forEach(function (el) {
                if (el.type !== 'hidden' && !el.closest('#itemsBody')) {
                    el.removeAttribute('readonly');
                    el.removeAttribute('disabled');
                }
            });
            var inv = document.getElementById('stiUpdateInventory');
            if (inv) inv.disabled = false;
            var toBtn = document.getElementById('stiSelectToBtn');
            if (toBtn) toBtn.disabled = false;

            document.getElementById('is_update_field').value = '1';

            if (typeof stiDetailEnableEdit === 'function') {
                stiDetailEnableEdit();
            }
            isEditActive = true;
            return true;
        }

        // Header + line checks before the form is posted
        function stiValidate() {
            var from = document.getElementById('stiLocFrom').value;
            var to = document.getElementById('stiLocTo').value;
            if (!from) { alert('Please select Location From (OUT).'); return false; }
            if (!to) { alert('Please select Location To (IN).'); return false; }
            if (from === to) {
                alert('Location From and Location To cannot be the same.');
                return false;
            }
            var lines = 0, bad = '', over = '';
            document.querySelectorAll('#itemsBody tr').forEach(function (tr) {
                var code = tr.querySelector('input[name="item_code[]"]');
                if (!code || !code.value.trim()) return;
                lines++;
                var qty = parseFloat((tr.querySelector('input[name="qty[]"]') || {}).value) || 0;
                if (qty <= 0 && !bad) bad = code.value.trim();
                var toNo = tr.querySelector('input[name="transfer_outno[]"]');
                var avail = parseFloat(tr.getAttribute('data-to-bal')) || 0;
                if (toNo && toNo.value && qty > avail + 0.0001 && !over) {
                    over = code.value.trim() + ': In Qty cannot be more than ' + avail.toFixed(2) + '.';
                }
            });
            if (!lines) { alert('Add at least one item line.'); return false; }
            if (bad) { alert('Enter a quantity for item ' + bad + '.'); return false; }
            if (over) { alert(over); return false; }
            return true;
        }

        // ---- Select From Transfer Out ----
        const STI_HEADER_URL = 'header/d_stock_transfer_in_h_01.php';
        let stiToDoc = null;
        let stiToItems = [];

        function stiToPost(params) {
            var fd = new FormData();
            Object.keys(params).forEach(function (k) { fd.append(k, params[k]); });
            return fetch(STI_HEADER_URL, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) {
                    if (!r.ok) throw new Error('Server returned ' + r.status);
                    return r.json();
                });
        }

        function stiToEsc(v) {
            return String(v == null ? '' : v)
                .replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;')
                .replace(/</g, '&lt;').replace(/>/g, '&gt;');
        }

        function stiOpenTransferOut() {
            if (!isEditActive) return;
            stiToDoc = null;
            stiToItems = [];
            document.getElementById('stiToSearch').value = '';
            document.getElementById('stiToTitle').textContent = 'Select a Transfer Out document';
            document.getElementById('stiToAll').checked = false;
            document.querySelector('#stiToItemsTable tbody').innerHTML =
                '<tr><td colspan="7" class="text-center text-muted p-3">Select a document from the left panel</td></tr>';
            document.getElementById('stiToModal').style.display = 'flex';
            document.body.style.overflow = 'hidden';
            stiSearchToDocs();
        }

        function stiCloseTransferOut() {
            document.getElementById('stiToModal').style.display = 'none';
            document.body.style.overflow = '';
        }

        var stiToTimer;
        function stiSearchToDocs() {
            clearTimeout(stiToTimer);
            stiToTimer = setTimeout(function () {
                var tbody = document.querySelector('#stiToDocsTable tbody');
                stiToPost({
                    action: 'sti_pending_docs',
                    term: document.getElementById('stiToSearch').value,
                    all_branch: document.getElementById('stiToAllBranch').checked ? '1' : '0'
                }).then(function (docs) {
                    tbody.innerHTML = '';
                    if (docs && docs.error) {
                        tbody.innerHTML = '<tr><td colspan="3" class="text-center p-3 text-danger">Error: ' + stiToEsc(docs.error) + '</td></tr>';
                        return;
                    }
                    if (!Array.isArray(docs) || !docs.length) {
                        tbody.innerHTML = '<tr><td colspan="3" class="text-center p-3">No pending transfer out documents</td></tr>';
                        return;
                    }
                    docs.forEach(function (doc) {
                        var tr = document.createElement('tr');
                        tr.innerHTML = '<td>' + stiToEsc(doc.doc_no) + '</td><td>' + stiToEsc(doc.doc_dt) + '</td><td>' + stiToEsc(doc.branch) + '</td>';
                        tr.onclick = function () { stiSelectToDoc(doc, tr); };
                        tbody.appendChild(tr);
                    });
                }).catch(function (err) {
                    console.error('Pending transfer out error:', err);
                    tbody.innerHTML = '<tr><td colspan="3" class="text-center p-3 text-danger">Network or Parsing Error</td></tr>';
                });
            }, 300);
        }

        function stiSelectToDoc(doc, row) {
            stiToDoc = doc;
            document.querySelectorAll('#stiToDocsTable tbody tr').forEach(function (r) {
                var on = (r === row);
                r.style.backgroundColor = on ? 'var(--primary)' : '';
                r.style.color = on ? 'white' : '';
            });
            document.getElementById('stiToTitle').textContent = 'Transfer Out ' + doc.doc_no
                + (doc.loc_from || doc.loc_to ? '  (' + doc.loc_from + ' to ' + doc.loc_to + ')' : '');
            document.getElementById('stiToAll').checked = false;
            var tbody = document.querySelector('#stiToItemsTable tbody');
            tbody.innerHTML = '<tr><td colspan="7" class="text-center p-3">Loading...</td></tr>';
            stiToPost({ action: 'sti_pending_items', doc_no: doc.doc_no, branch: doc.branch })
                .then(function (items) {
                    tbody.innerHTML = '';
                    if (items && items.error) {
                        tbody.innerHTML = '<tr><td colspan="7" class="text-center p-3 text-danger">Error: ' + stiToEsc(items.error) + '</td></tr>';
                        return;
                    }
                    stiToItems = Array.isArray(items) ? items : [];
                    if (!stiToItems.length) {
                        tbody.innerHTML = '<tr><td colspan="7" class="text-center p-3">No pending items for this document</td></tr>';
                        return;
                    }
                    stiToItems.forEach(function (item, i) {
                        var tr = document.createElement('tr');
                        tr.innerHTML = '<td>' + stiToEsc(item.item_no) + '</td>'
                            + '<td>' + stiToEsc(item.item_code) + '</td>'
                            + '<td>' + stiToEsc(item.item_name) + '</td>'
                            + '<td class="text-right">' + item.balance.toFixed(2) + '</td>'
                            + '<td>' + stiToEsc(item.unit) + '</td>'
                            + '<td class="text-center"><input type="checkbox" class="sti-to-chk" value="' + i + '" onchange="stiToCheck(this)"></td>'
                            + '<td style="padding: 4px;"><input type="number" class="form-input-custom sti-to-qty text-center" value="0.00" min="0" step="0.01" style="width: 90px; padding: 2px;"></td>';
                        tbody.appendChild(tr);
                    });
                })
                .catch(function (err) {
                    console.error('Pending transfer out items error:', err);
                    tbody.innerHTML = '<tr><td colspan="7" class="text-center p-3 text-danger">Network or Parsing Error</td></tr>';
                });
        }

        // Ticking an item fills its Qty with the pending quantity
        function stiToCheck(chk) {
            var qty = chk.closest('tr').querySelector('.sti-to-qty');
            if (chk.checked && !(parseFloat(qty.value) > 0)) {
                qty.value = stiToItems[chk.value].balance.toFixed(2);
            }
        }

        function stiToggleAllTo(src) {
            document.querySelectorAll('.sti-to-chk').forEach(function (chk) {
                chk.checked = src.checked;
                stiToCheck(chk);
            });
        }

        // A location of another branch's transfer out may be missing from this branch's list
        function stiSetLocation(id, code) {
            var sel = document.getElementById(id);
            if (!sel || !code) return;
            if (!Array.prototype.some.call(sel.options, function (o) { return o.value === code; })) {
                sel.add(new Option(code, code));
            }
            sel.value = code;
        }

        function stiAddFromTransferOut() {
            var picked = [];
            var bad = '';
            document.querySelectorAll('.sti-to-chk:checked').forEach(function (chk) {
                var item = stiToItems[chk.value];
                var qty = parseFloat(chk.closest('tr').querySelector('.sti-to-qty').value) || 0;
                if ((qty <= 0 || qty > item.balance + 0.0001) && !bad) {
                    bad = item.item_code + ': enter a quantity between 0 and ' + item.balance.toFixed(2) + '.';
                }
                picked.push({ item: item, qty: qty });
            });
            if (!picked.length) { alert('Please select at least one item'); return; }
            if (bad) { alert(bad); return; }

            stiSetLocation('stiLocFrom', stiToDoc.loc_from);
            stiSetLocation('stiLocTo', stiToDoc.loc_to);

            var body = document.getElementById('itemsBody');
            picked.forEach(function (p) {
                var item = p.item;
                var existing = null;
                body.querySelectorAll('tr').forEach(function (tr) {
                    var no = tr.querySelector('input[name="transfer_outno[]"]');
                    var line = tr.querySelector('input[name="line_no[]"]');
                    var code = tr.querySelector('input[name="item_code[]"]');
                    if (no && no.value === item.doc_no && line.value === item.item_no && code.value === item.item_code) existing = tr;
                });
                if (existing) {
                    existing.querySelector('input[name="qty[]"]').value = p.qty;
                    existing.setAttribute('data-to-bal', item.balance);
                    stiUpdateBal(existing);
                    return;
                }
                stiAppendLine({
                    line: item.item_no, code: item.item_code, desc: item.item_name, loc: stiToDoc.loc_to, bin: '',
                    stock: 0, qty: p.qty, unit: item.unit, to_no: item.doc_no, to_qty: item.out_qty, to_bal: item.balance
                }, false);
            });

            // Drop the empty starter line(s)
            Array.prototype.slice.call(body.rows).forEach(function (tr) {
                var code = tr.querySelector('input[name="item_code[]"]');
                if (code && !code.value.trim() && body.rows.length > 1) tr.remove();
            });
            stiReindex();
            stiRefreshLines();
            stiCloseTransferOut();
        }

        // ── Register shared toolbar callbacks ────────────────────────────────────
        window.erpToolbar = window.erpToolbar || {};

        window.erpToolbar.onSelect = function () { stiOpenTransferOut(); };

        window.erpToolbar.onSave = function () {
            if (!isEditActive || !stiValidate()) return;
            var btn = document.querySelector('#stiForm button[name="save_sti"]');
            if (btn) btn.click();
        };

        window.erpToolbar.onNew = function () {
            window.location.href = stiUrl('type=' + encodeURIComponent(CURRENT_DOC_TYPE));
        };

        // PREVIEW — print preview of the saved document (print format from Document Setup)
        window.erpToolbar.onPreview = function () {
            erpOpenPrintPreview(<?php echo json_encode($sti_doc_code); ?>, CURRENT_DOC_TYPE, <?php echo json_encode($is_view_mode ? $doc_no : ''); ?>);
        };

        window.erpToolbar.onPrior = navPrior;
        window.erpToolbar.onNext  = navNext;

        window.erpToolbar.onDocSelect = function (doc_no) {
            window.location.href = stiUrl('doc_no=' + encodeURIComponent(doc_no));
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
