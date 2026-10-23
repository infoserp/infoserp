<?php
// Receipt Voucher Header - Reusing Sales Invoice Layout and Functionality
// Fragment included by modules/receipt/receipt.php
// When called directly for AJAX (e.g., ?action=search_references), load db/auth
// When included by the container, these are already loaded, so we skip
if (!isset($con)) {
    require('../../../db.php');
    include('../../../auth.php');
}
require_once __DIR__ . '/../../../includes/doc_setup.php';

$owns_output_buffer = !isset($con);
if ($owns_output_buffer) {
    ob_start();
}

$company_code = $_SESSION["company_code"] ?? '';
$branch_code  = $_SESSION["branch_code"]  ?? '';
$user         = $_SESSION['username']     ?? '';
$today        = date("d/m/Y");

// AJAX Handler: Fetch reference list for search
if (isset($_GET['action']) && $_GET['action'] === 'search_references') {
    if ($owns_output_buffer && ob_get_level() > 0) {
        ob_end_clean();
    }
    if (!isset($_SESSION['username'])) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
    try {
        $search_term  = isset($_GET['term']) ? trim($_GET['term']) : '';
        $search_group = isset($_GET['group']) && $_GET['group'] === '1';

        $sql = "SELECT ac_master.ac_code, ac_master.description, ac_master.contact_name, ac_master.contact_no, ac_master.contact_email,
                       parent_group.description AS parent_group
                FROM ac_master 
                LEFT JOIN ac_master AS parent_group
                       ON parent_group.company_code = ac_master.company_code
                      AND parent_group.account_code = ac_master.parent_account_code
                WHERE (ac_master.company_code = ?) 
                AND (ac_master.child_exist = 'N')";

        if ($search_term !== '') {
            $sql .= $search_group
                ? " AND (ac_master.ac_code LIKE ? OR ac_master.description LIKE ? OR parent_group.description LIKE ?)"
                : " AND (ac_master.ac_code LIKE ? OR ac_master.description LIKE ?)";
        }

        $sql .= " ORDER BY ac_master.ac_code ASC";

        $stmt = $con->prepare($sql);

        if ($search_term !== '') {
            $search_pattern = '%' . $search_term . '%';
            $params = [$company_code, $search_pattern, $search_pattern];
            if ($search_group) {
                $params[] = $search_pattern;
            }
            $stmt->execute($params);
        } else {
            $stmt->execute([$company_code]);
        }

        $references = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $references[] = [
                'ac_code'       => trim($row['ac_code']),
                'description'   => trim($row['description']),
                'contact_name'  => trim($row['contact_name'] ?? ''),
                'contact_no'    => trim($row['contact_no'] ?? ''),
                'contact_email' => trim($row['contact_email'] ?? ''),
                'parent_group'  => trim($row['parent_group'] ?? '')
            ];
        }

        header('Content-Type: application/json');
        echo json_encode($references);
    } catch (Exception $e) {
        error_log("Reference search error: " . $e->getMessage());
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// AJAX Handler: Fetch outstanding invoices for selected Ref. Code (SELECT button)
if (isset($_GET['action']) && $_GET['action'] === 'get_outstanding_invoices') {
    if ($owns_output_buffer && ob_get_level() > 0) {
        ob_end_clean();
    }
    if (!isset($_SESSION['username'])) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
    try {
        $ref_ac_code = isset($_GET['ac_code']) ? trim($_GET['ac_code']) : '';
        
        if ($ref_ac_code === '') {
            throw new InvalidArgumentException('Account code is required');
        }

        $sql = "SELECT
                    view_invoice_ledger.companycode,
                    MAX(view_invoice_ledger.branchcode) AS branchcode,
                    MIN(view_invoice_ledger.invoicedate) AS invoicedate,
                    view_invoice_ledger.accode,
                    SUM(view_invoice_ledger.debit) AS debit,
                    SUM(view_invoice_ledger.credit) AS credit,
                    ROUND(
                        SUM(ISNULL(view_invoice_ledger.debit,0))
                        - SUM(ISNULL(view_invoice_ledger.credit,0)),
                        3
                    ) AS balance,
                    0.000 AS applied_amount,
                    SPACE(1) AS posted,
                    view_invoice_ledger.invoiceno
                FROM view_invoice_ledger
                WHERE view_invoice_ledger.companycode = ?
                  AND view_invoice_ledger.branchcode LIKE ?
                  AND view_invoice_ledger.accode = ?
                GROUP BY
                    view_invoice_ledger.companycode,
                    view_invoice_ledger.accode,
                    view_invoice_ledger.invoiceno
                HAVING SUM(ISNULL(view_invoice_ledger.debit,0))
                     - SUM(ISNULL(view_invoice_ledger.credit,0)) <> 0
                ORDER BY invoicedate, invoiceno";

        $stmt = $con->prepare($sql);
        $branch_pattern = $branch_code . '%';
        $stmt->execute([$company_code, $branch_pattern, $ref_ac_code]);

        $invoices = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $invoices[] = [
                'invoiceno'      => trim($row['invoiceno'] ?? ''),
                'invoicedate'    => trim($row['invoicedate'] ?? ''),
                'debit'          => (float)($row['debit'] ?? 0),
                'credit'         => (float)($row['credit'] ?? 0),
                'balance'        => (float)($row['balance'] ?? 0),
                'applied_amount' => (float)($row['applied_amount'] ?? 0),
                'posted'         => trim($row['posted'] ?? '')
            ];
        }

        header('Content-Type: application/json');
        echo json_encode($invoices);
    } catch (Exception $e) {
        error_log("Outstanding invoices fetch error: " . $e->getMessage());
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── Save Receipt Voucher Handler ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_rv'])) {
    try {
        $is_update_mode  = isset($_POST['is_update']) && $_POST['is_update'] === '1';
        $p_doc_type      = strtoupper(trim($_POST['doc_type_selected']   ?? ''));
        $p_doc_no        = trim($_POST['doc_no']            ?? '');
        $p_doc_dt        = trim($_POST['doc_dt']            ?? '');
        $p_chq_no        = trim($_POST['chq_no']            ?? '');
        $p_chq_date      = trim($_POST['chq_date']          ?? '');
        $p_ac_code       = trim($_POST['ac_code']           ?? '');
        $p_ref_code      = trim($_POST['ref_code']          ?? '');
        $p_ref_name      = trim($_POST['ref_name']          ?? '');
        $p_ref_no        = trim($_POST['ref_no']            ?? '');
        $p_currency      = trim($_POST['currency']          ?? '');
        $p_exchange_rate = trim($_POST['currency_rate']     ?? '');
        $p_chq_bank      = trim($_POST['chq_bank']          ?? '');
        $p_salesman_code = trim($_POST['salesman_code']     ?? '');
        $p_narration     = trim($_POST['narration']         ?? '');
        $p_amount        = (float)($_POST['amount']         ?? 0);
        $p_doc_dt_val    = $p_doc_dt !== '' ? $p_doc_dt : null;
        $p_chq_date_val  = $p_chq_date !== '' ? $p_chq_date : null;
        $now             = date('Y-m-d H:i:s');

        // Validate required fields before touching the DB
        if ($p_ac_code === '') {
            throw new InvalidArgumentException('Please select a Debit Account before saving.');
        }
        if ($p_doc_type === '') {
            throw new InvalidArgumentException('Document type is missing.');
        }

        // Detail lines (chq_receipt_d) posted by the detail grid as JSON; null = not posted, keep saved lines
        $p_lines = null;
        $p_lines_json = trim($_POST['detail_lines_json'] ?? '');
        if ($p_lines_json !== '') {
            $p_lines = json_decode($p_lines_json, true);
            if (!is_array($p_lines)) {
                throw new InvalidArgumentException('Account lines could not be read. Please try again.');
            }
            foreach ($p_lines as $li => $ln) {
                if (trim((string) ($ln['ac_code'] ?? '')) === '') {
                    throw new InvalidArgumentException('Account line ' . ($li + 1) . ': please select the account Code.');
                }
            }
        }

        $con->beginTransaction();

        if ($is_update_mode) {
            $sql_update = "UPDATE chq_receipt_h SET
                doc_dt = ?,
                doc_type = ?,
                chq_no = ?,
                chq_date = ?,
                ac_code = ?,
                ref_code = ?,
                ref_name = ?,
                ref_no = ?,
                currency_code = ?,
                exchange_rate = ?,
                chq_bank = ?,
                salesman_code = ?,
                narration = ?,
                amount = ?,
                last_userid = ?,
                last_userdate = ?
            WHERE company_code = ? AND branch_code = ? AND doc_no = ?";

            $stmt_upd = $con->prepare($sql_update);
            $stmt_upd->execute([
                $p_doc_dt_val, $p_doc_type,
                $p_chq_no, $p_chq_date_val,
                $p_ac_code,
                $p_ref_code, $p_ref_name, $p_ref_no,
                $p_currency, $p_exchange_rate,
                $p_chq_bank,
                $p_salesman_code,
                $p_narration,
                $p_amount,
                $user, $now,
                $company_code, $branch_code, $p_doc_no
            ]);
        } else {
            $rv_save_doc_code = isset($doc_code) && $doc_code !== '' ? $doc_code : 'RV';
            $rv_save_types = erp_doc_configured_types($con, $company_code, $branch_code, $rv_save_doc_code);
            if (!isset($rv_save_types[$p_doc_type])) {
                throw new InvalidArgumentException("Document type '" . $p_doc_type . "' is not enabled in Document Setup for " . $rv_save_doc_code . ".");
            }

            // doc_no and doc_num are regenerated at INSERT time under UPDLOCK/HOLDLOCK
            // so two users saving simultaneously cannot get the same number.
            $p_doc_no  = getNextDocNo($con, $company_code, $branch_code, $rv_save_doc_code, $p_doc_type);
            $p_doc_num = getNextDocNum($con, $company_code, $branch_code, $p_doc_type);
            $sql_insert = "INSERT INTO chq_receipt_h (
                company_code, branch_code,
                doc_no, doc_num, doc_dt, doc_type,
                chq_no, chq_date,
                ac_code, ref_code, ref_name, ref_no,
                currency_code, exchange_rate,
                chq_bank, salesman_code,
                narration,
                user_id, user_date, last_userid, last_userdate,
                amount
            ) VALUES (
                ?, ?,
                ?, ?, ?, ?,
                ?, ?,
                ?, ?, ?, ?,
                ?, ?,
                ?, ?,
                ?,
                ?, ?, ?, ?,
                ?
            )";

            $stmt_ins = $con->prepare($sql_insert);
            $stmt_ins->execute([
                $company_code, $branch_code,
                $p_doc_no, $p_doc_num, $p_doc_dt_val, $p_doc_type,
                $p_chq_no, $p_chq_date_val,
                $p_ac_code, $p_ref_code, $p_ref_name, $p_ref_no,
                $p_currency, $p_exchange_rate,
                $p_chq_bank, $p_salesman_code,
                $p_narration,
                $user, $now, $user, $now,
                $p_amount
            ]);
        }

        if ($p_lines !== null) {
            $stmt_dd = $con->prepare("DELETE FROM chq_receipt_d WHERE company_code = ? AND branch_code = ? AND doc_no = ?");
            $stmt_dd->execute([$company_code, $branch_code, $p_doc_no]);

            $stmt_di = $con->prepare(
                "INSERT INTO chq_receipt_d (
                    company_code, branch_code, doc_no, serial_no,
                    ac_code, ac_subcode, costcenter_code,
                    dr_amount, cr_amount, narration,
                    job_no, subjob_no, invoice_no, invoice_date,
                    salesman_code, jv_branch_code
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $nz = function ($v) { $v = trim((string) $v); return $v === '' ? null : $v; };
            foreach (array_values($p_lines) as $li => $ln) {
                $stmt_di->execute([
                    $company_code, $branch_code, $p_doc_no,
                    str_pad((string) ($li + 1), 4, '0', STR_PAD_LEFT),
                    trim((string) $ln['ac_code']),
                    $nz($ln['ac_subcode'] ?? ''),
                    $nz($ln['costcenter_code'] ?? ''),
                    (float) ($ln['dr_amount'] ?? 0),
                    (float) ($ln['cr_amount'] ?? 0),
                    $nz($ln['narration'] ?? ''),
                    $nz($ln['job_no'] ?? ''),
                    $nz($ln['subjob_no'] ?? ''),
                    $nz($ln['invoice_no'] ?? ''),
                    $nz($ln['invoice_date'] ?? ''),
                    $nz($ln['salesman_code'] ?? ''),
                    $branch_code
                ]);
            }
        }

        $con->commit();

        // Compute PRIOR / NEXT doc for toolbar navigation after save
        $_save_prev_doc = '';
        $_save_next_doc = '';
        try {
            $stmt_sp = $con->prepare(
                "SELECT doc_no FROM chq_receipt_h
                 WHERE company_code=? AND branch_code=? AND doc_type=?
                   AND doc_num < (SELECT doc_num FROM chq_receipt_h WHERE doc_no=? AND company_code=? AND branch_code=?)
                 ORDER BY doc_num DESC OFFSET 0 ROWS FETCH NEXT 1 ROWS ONLY"
            );
            $stmt_sp->execute([$company_code, $branch_code, $p_doc_type, $p_doc_no, $company_code, $branch_code]);
            $r_sp = $stmt_sp->fetch(PDO::FETCH_ASSOC);
            if ($r_sp) $_save_prev_doc = trim($r_sp['doc_no']);
        } catch (Exception $e) { error_log('Prior nav error: ' . $e->getMessage()); }
        try {
            $stmt_sn = $con->prepare(
                "SELECT doc_no FROM chq_receipt_h
                 WHERE company_code=? AND branch_code=? AND doc_type=?
                   AND doc_num > (SELECT doc_num FROM chq_receipt_h WHERE doc_no=? AND company_code=? AND branch_code=?)
                 ORDER BY doc_num ASC OFFSET 0 ROWS FETCH NEXT 1 ROWS ONLY"
            );
            $stmt_sn->execute([$company_code, $branch_code, $p_doc_type, $p_doc_no, $company_code, $branch_code]);
            $r_sn = $stmt_sn->fetch(PDO::FETCH_ASSOC);
            if ($r_sn) $_save_next_doc = trim($r_sn['doc_no']);
        } catch (Exception $e) { error_log('Next nav error: ' . $e->getMessage()); }
        // Stay on the same page — trigger the "Load existing document" block below
        // so the form re-renders in view mode showing the just-saved record.
        $_GET['doc_no']   = $p_doc_no;
        $_GET['doc_type'] = $p_doc_type;
        $save_success     = true;

    } catch (InvalidArgumentException $e) {
        // Validation errors — shown as a warning, not logged as a system error
        if ($con->inTransaction()) $con->rollBack();
        $save_error = $e->getMessage();
    } catch (Exception $e) {
        if ($con->inTransaction()) $con->rollBack();
        error_log("Save receipt voucher error: " . $e->getMessage());
        $save_error = "Save failed: " . $e->getMessage();
    }
}

// Doc types for this module: defined in doc_type_master AND enabled in document_setup
$target_code = isset($doc_code) && $doc_code !== ''
    ? $doc_code
    : (isset($_GET['code']) && ctype_alnum($_GET['code']) ? strtoupper(trim($_GET['code'])) : 'RV');
$rv_doc_types = erp_doc_configured_types($con, $company_code, $branch_code, $target_code);

// Selected doc type: GET/POST override if enabled, otherwise the doc_master default
$requested_doc_type = '';
if (isset($_GET['doc_type']) && $_GET['doc_type'] !== '' && ctype_alnum($_GET['doc_type'])) {
    $requested_doc_type = strtoupper(trim($_GET['doc_type']));
} elseif (isset($_GET['type']) && $_GET['type'] !== '' && ctype_alnum($_GET['type'])) {
    // Legacy param name
    $requested_doc_type = strtoupper(trim($_GET['type']));
} elseif (isset($_POST['doc_type_selected']) && $_POST['doc_type_selected'] !== '') {
    $requested_doc_type = strtoupper(trim($_POST['doc_type_selected']));
}
$selected_doc_type = isset($rv_doc_types[$requested_doc_type])
    ? $requested_doc_type
    : erp_doc_default_type($con, $company_code, $branch_code, $target_code, $rv_doc_types);
$selected_doc_name = $rv_doc_types[$selected_doc_type] ?? $selected_doc_type;

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
        $p1 = isset($row_prefix['doc_prefix'])   ? trim($row_prefix['doc_prefix'])   : '';
        $p2 = isset($row_prefix['doc_line'])      ? trim($row_prefix['doc_line'])      : '';
        $p3 = isset($row_prefix['txn_prefix'])    ? trim($row_prefix['txn_prefix'])    : '';
        $p4 = isset($row_prefix['year_prefix'])   ? trim($row_prefix['year_prefix'])   : '';
        $p5 = isset($row_prefix['doc_year'])      ? trim($row_prefix['doc_year'])      : '';
        $p6 = isset($row_prefix['month_prefix'])  ? trim($row_prefix['month_prefix'])  : '';
        $p7 = isset($row_prefix['doc_month'])     ? trim($row_prefix['doc_month'])     : '';
        $p8 = isset($row_prefix['number_prefix']) ? trim($row_prefix['number_prefix']) : '';

        $generated_prefix = $p1 . $p2 . $p3 . $p4 . $p5 . $p6 . $p7 . $p8;
        if ($generated_prefix !== '') {
            $prefix = $generated_prefix;
        }

        if (!empty($row_prefix['doc_digit']) && intval($row_prefix['doc_digit']) > 0) {
            $digits = intval($row_prefix['doc_digit']);
        }
    }

    $sql = "SELECT doc_no FROM chq_receipt_h WITH (UPDLOCK, HOLDLOCK)
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

// doc_num runs separately per doc_type (it matches the numeric part of doc_no)
function getNextDocNum($con, $company_code, $branch_code, $doc_type)
{
    $stmt = $con->prepare(
        "SELECT ISNULL(MAX(doc_num), 0) FROM chq_receipt_h WITH (UPDLOCK, HOLDLOCK)
         WHERE company_code = ? AND branch_code = ? AND doc_type = ?"
    );
    $stmt->execute([$company_code, $branch_code, $doc_type]);
    return intval($stmt->fetchColumn()) + 1;
}

// Defaults
$doc_no  = getNextDocNo($con, $company_code, $branch_code, $target_code, $selected_doc_type);
$doc_num = 1;
try {
    $doc_num = getNextDocNum($con, $company_code, $branch_code, $selected_doc_type);
} catch (Exception $e) {
    error_log("doc_num fetch error: " . $e->getMessage());
}
$doc_dt          = date("Y-m-d");
$chq_no          = '';
$chq_date        = date("Y-m-d");
$chq_bank        = '';
$ac_code         = '';
$debit_ac_name   = '';
$ref_code        = '';
$ref_name        = '';
$ref_no          = '';
$salesman_code   = '';
$salesman_name   = '';
$narration       = 'RECEIPT VOUCHER';
$currency        = 'AED';
$currency_rate   = '1.000000000';
$amount          = '0.00';

$is_view_mode    = false;
$entered_by_user = strtoupper($user);
$entered_by_date = date('d/m/Y H:i');
$modified_by_user = strtoupper($user);
$modified_by_date = $entered_by_date;

// Fetch logged-in user's salesman info via branch_user.user_id -> salesman.login_id
try {
    $stmt_sm = $con->prepare(
        "SELECT s.salesman_code, s.salesman_name
         FROM salesman s
         INNER JOIN branch_user bu ON bu.user_id = s.login_id
         WHERE bu.user_id = ? AND s.company_code = ?"
    );
    $stmt_sm->execute([$user, $company_code]);
    $row_sm = $stmt_sm->fetch(PDO::FETCH_ASSOC);
    if ($row_sm) {
        $salesman_code = trim($row_sm['salesman_code']);
        $salesman_name = trim($row_sm['salesman_name']);
    }
} catch (Exception $e) {
    error_log("Salesman fetch error: " . $e->getMessage());
}

// Fetch currency list from currency_master
$currency_list = [];
try {
    $stmt_cur = $con->prepare(
        "SELECT currency_code, currency_name, exchange_rate FROM currency_master WHERE company_code = ? ORDER BY currency_code ASC"
    );
    $stmt_cur->execute([$company_code]);
    while ($row_cur = $stmt_cur->fetch(PDO::FETCH_ASSOC)) {
        $currency_list[] = [
            'code'          => trim($row_cur['currency_code']),
            'name'          => trim($row_cur['currency_name']),
            'exchange_rate' => rtrim(rtrim(number_format((float)$row_cur['exchange_rate'], 9, '.', ''), '0'), '.')
        ];
    }
} catch (Exception $e) {
    error_log("Currency fetch error: " . $e->getMessage());
}

// Default Debit A/c of a new voucher: ac_default.dr_ac mapped to the selected doc_type
try {
    $stmt_dac = $con->prepare(
        "SELECT TOP 1 ad.dr_ac, ISNULL(am.description, '') AS dr_ac_name
         FROM ac_default ad
         LEFT JOIN ac_master am ON am.ac_code = ad.dr_ac AND am.company_code = ad.company_code
         WHERE ad.company_code = ? AND ad.branch_code = ? AND ad.ac_type = ?"
    );
    $stmt_dac->execute([$company_code, $branch_code, $selected_doc_type]);
    $row_dac = $stmt_dac->fetch(PDO::FETCH_ASSOC);
    if ($row_dac && trim($row_dac['dr_ac'] ?? '') !== '') {
        $ac_code       = trim($row_dac['dr_ac']);
        $debit_ac_name = trim($row_dac['dr_ac_name']);
    }
} catch (Exception $e) {
    error_log("Default debit A/c fetch error: " . $e->getMessage());
}

// Load existing document
if (isset($_GET['doc_no']) && trim($_GET['doc_no']) !== '') {
    $search_doc = trim($_GET['doc_no']);
    $stmt_edit = $con->prepare(
        "SELECT rh.*,
                ISNULL(am.description, '') AS debit_ac_name_db
         FROM chq_receipt_h rh
         LEFT JOIN ac_master am ON am.ac_code = rh.ac_code AND am.company_code = rh.company_code
         WHERE rh.doc_no = ? AND rh.company_code = ? AND rh.branch_code = ?"
    );
    $stmt_edit->execute([$search_doc, $company_code, $branch_code]);
    $row_edit = $stmt_edit->fetch(PDO::FETCH_ASSOC);
    if ($row_edit) {
        $is_view_mode    = true;
        $doc_no          = trim($row_edit['doc_no']);
        $doc_num         = intval($row_edit['doc_num'] ?? 0);
        $chq_no          = trim($row_edit['chq_no'] ?? '');
        $chq_bank        = trim($row_edit['chq_bank'] ?? '');
        $ac_code         = trim($row_edit['ac_code'] ?? '');
        $debit_ac_name   = trim($row_edit['debit_ac_name_db'] ?? '');
        $ref_code        = trim($row_edit['ref_code'] ?? '');
        $ref_name        = trim($row_edit['ref_name'] ?? '');
        $ref_no          = trim($row_edit['ref_no'] ?? '');
        $salesman_code   = trim($row_edit['salesman_code'] ?? '');
        $narration       = trim($row_edit['narration'] ?? '');
        $currency        = trim($row_edit['currency_code'] ?? 'AED');
        $currency_rate   = trim($row_edit['exchange_rate'] ?? '1.000000000');
        // Dates — format to Y-m-d for <input type="date">
        $fmt_date = function($d) { if (!$d) return ''; try { return (new DateTime($d))->format('Y-m-d'); } catch (Exception $e) { return $d; } };
        $doc_dt    = $fmt_date($row_edit['doc_dt']   ?? '');
        $chq_date  = $fmt_date($row_edit['chq_date'] ?? '');
        // Amount
        $amount     = number_format((float)($row_edit['amount'] ?? 0), 2, '.', '');
        // Update selected type from the loaded document
        if (!empty($row_edit['doc_type'])) {
            $selected_doc_type = strtoupper(trim($row_edit['doc_type']));
            $selected_doc_name = $rv_doc_types[$selected_doc_type]
                ?? erp_doc_type_name($con, $company_code, $branch_code, $target_code, $selected_doc_type);
        }
        $entered_by_user     = strtoupper(trim($row_edit['user_id'] ?? $user));
        $entered_by_date_raw = trim($row_edit['user_date'] ?? '');
        if ($entered_by_date_raw) {
            try { $entered_by_date = (new DateTime($entered_by_date_raw))->format('d/m/Y H:i'); } catch (Exception $e) { $entered_by_date = $entered_by_date_raw; }
        }
        $last_uid = trim($row_edit['last_userid']   ?? '');
        $last_udt = trim($row_edit['last_userdate'] ?? '');
        if ($last_uid !== '') {
            $modified_by_user = strtoupper($last_uid);
            if ($last_udt) {
                try { $modified_by_date = (new DateTime($last_udt))->format('d/m/Y H:i'); } catch (Exception $e) { $modified_by_date = $last_udt; }
            }
        } else {
            $modified_by_user = $entered_by_user;
            $modified_by_date = $entered_by_date;
        }
    }
}

// Absolute URL path to this file — used for AJAX calls.
// Works correctly whether this file is accessed standalone or included by receipt.php.
$rv_self_url = '/' . ltrim(
    str_replace(
        str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'] ?? '')),
        '',
        str_replace('\\', '/', realpath(__FILE__))
    ),
    '/'
);
?>

<div class="container-fluid doc-contents">
<div class="mr-container doc-contents">

    <!-- ══ TITLE BAR ══ -->
    <div class="mr-header doc-titlebar">
        <h1 class="doc-title-text">Receipt Voucher</h1>
        <div class="mr-title-wrapper">
            <button type="button" class="doc-chip doc-chip-type" id="mrTitleBtn" onclick="toggleDocTypeMenu()" title="Click to switch document type">
                <span id="mrTitleText"><?php echo htmlspecialchars($selected_doc_name); ?></span>
                <i class="fa fa-caret-down"></i>
            </button>
            <div class="mr-doctype-menu" id="mrDocTypeMenu">
                <?php foreach ($rv_doc_types as $dt_code => $dt_name): ?>
                <div class="mr-doctype-item <?php echo ($dt_code === $selected_doc_type) ? 'active' : ''; ?>"
                     onclick="selectDocType('<?php echo htmlspecialchars($dt_code, ENT_QUOTES); ?>', '<?php echo htmlspecialchars($dt_name, ENT_QUOTES); ?>')">
                    <?php echo htmlspecialchars($dt_name); ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <span class="doc-title-spacer"></span>
        <div class="mr-info">
            Entered by: <strong><?php echo htmlspecialchars($entered_by_user); ?></strong><?php echo $entered_by_date ? ' - ' . htmlspecialchars($entered_by_date) : ''; ?><br>
            Modified by: <strong><?php echo htmlspecialchars($modified_by_user); ?></strong><?php echo $modified_by_date ? ' - ' . htmlspecialchars($modified_by_date) : ''; ?>
        </div>
    </div>
    <script>if (window.erpToolbarAdoptTitlebar) erpToolbarAdoptTitlebar();</script>

    <!-- ══ FORM ══ -->
    <form id="rvForm" class="doc-contents" method="POST" onkeydown="return event.key !== 'Enter' || event.target.tagName === 'TEXTAREA'">
        <input type="hidden" name="is_update" value="<?php echo $is_view_mode ? '1' : '0'; ?>">
        <input type="hidden" name="doc_type_selected" id="doc_type_selected_field" value="<?php echo htmlspecialchars($selected_doc_type); ?>">
        <input type="hidden" name="doc_num" value="<?php echo htmlspecialchars($doc_num ?? 0); ?>">
        <button type="submit" name="save_rv" value="1" style="display:none;">Save</button>
        <?php if (!empty($save_success)): ?>
        <div class="alert alert-success"><i class="fa fa-check-circle"></i> Receipt Voucher <strong><?php echo htmlspecialchars($_GET['doc_no'] ?? ''); ?></strong> saved successfully.</div>
        <?php endif; ?>
        <?php if (!empty($save_error)): ?>
        <div class="alert alert-danger"><i class="fa fa-exclamation-triangle"></i> <?php echo htmlspecialchars($save_error); ?></div>
        <?php endif; ?>

        <div class="doc-form-wrap">
        <div class="doc-layout no-aside">
            <div class="doc-main">

                <!-- ── Voucher ── -->
                <div class="doc-section">
                    <h4 class="doc-section-title">Voucher</h4>
                    <div class="doc-fields">
                        <div class="doc-field">
                            <label class="doc-lbl">Voucher No.</label>
                            <input type="text" name="doc_no" class="doc-inp" value="<?php echo htmlspecialchars($doc_no); ?>" readonly>
                        </div>
                        <div class="doc-field">
                            <label class="doc-lbl">Voucher Date</label>
                            <input type="date" name="doc_dt" class="doc-inp" value="<?php echo htmlspecialchars($doc_dt); ?>" <?php echo $is_view_mode ? 'disabled' : ''; ?>>
                        </div>
                        <div class="doc-field">
                            <label class="doc-lbl">Ref. No.</label>
                            <input type="text" name="ref_no" class="doc-inp" value="<?php echo htmlspecialchars($ref_no); ?>" <?php echo $is_view_mode ? 'readonly' : ''; ?>>
                        </div>
                        <div class="doc-field">
                            <label class="doc-lbl">Salesman</label>
                            <input type="hidden" name="salesman_code" value="<?php echo htmlspecialchars($salesman_code); ?>">
                            <input type="text" name="salesman" class="doc-inp" value="<?php echo htmlspecialchars($salesman_name); ?>" readonly>
                        </div>
                        <div class="doc-field span-2">
                            <label class="doc-lbl">Currency / Rate</label>
                            <div class="doc-inp-group">
                                <?php if ($is_view_mode): ?>
                                    <input type="text" name="currency" class="doc-inp" value="<?php echo htmlspecialchars($currency); ?>" readonly>
                                <?php else: ?>
                                    <select id="currencySelect" class="doc-inp" onchange="onCurrencyChange(this)">
                                        <?php foreach ($currency_list as $cur): ?>
                                            <option value="<?php echo htmlspecialchars($cur['code']); ?>"
                                                    data-rate="<?php echo htmlspecialchars($cur['exchange_rate']); ?>"
                                                    data-name="<?php echo htmlspecialchars($cur['name']); ?>"
                                                    <?php echo ($cur['code'] === $currency) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($cur['code'] . ' - ' . $cur['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="hidden" name="currency" id="currencyCode" value="<?php echo htmlspecialchars($currency); ?>">
                                <?php endif; ?>
                                <input type="text" name="currency_rate" id="currencyRate" class="doc-rate" value="<?php echo htmlspecialchars($currency_rate); ?>" <?php echo $is_view_mode ? 'readonly' : ''; ?>>
                            </div>
                        </div>
                        <div class="doc-field span-2">
                            <label class="doc-lbl">Narration</label>
                            <div class="doc-inp-group">
                                <input type="text" name="narration" class="doc-inp" value="<?php echo htmlspecialchars($narration); ?>" <?php echo $is_view_mode ? 'readonly' : ''; ?>>
                                <button type="button" class="doc-more" onclick="openMoreModal('narration','Narration')">More..</button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ── Cheque / TT ── -->
                <div class="doc-section">
                    <h4 class="doc-section-title">Cheque / TT</h4>
                    <div class="doc-fields">
                        <div class="doc-field">
                            <label class="doc-lbl">CHQ/TT No.</label>
                            <input type="text" name="chq_no" class="doc-inp" value="<?php echo htmlspecialchars($chq_no); ?>" <?php echo $is_view_mode ? 'readonly' : ''; ?> oninput="rvUpdateChqNarration()">
                        </div>
                        <div class="doc-field">
                            <label class="doc-lbl">CHQ/TT Date</label>
                            <input type="date" name="chq_date" class="doc-inp" value="<?php echo htmlspecialchars($chq_date); ?>" <?php echo $is_view_mode ? 'disabled' : ''; ?> onchange="rvUpdateChqNarration()">
                        </div>
                        <div class="doc-field">
                            <label class="doc-lbl red">CHQ Bank</label>
                            <input type="text" name="chq_bank" class="doc-inp" value="<?php echo htmlspecialchars($chq_bank); ?>" <?php echo $is_view_mode ? 'readonly' : ''; ?>>
                        </div>
                        <div class="doc-field">
                            <label class="doc-lbl">Narration</label>
                            <input type="text" id="rvChqNarration" class="doc-inp" value="" readonly tabindex="-1">
                        </div>
                    </div>
                </div>

                <!-- ── Accounts ── -->
                <div class="doc-section">
                    <h4 class="doc-section-title">Accounts</h4>
                    <div class="doc-fields rv-accounts">
                        <div class="doc-field span-2">
                            <label class="doc-lbl">Debit A/c</label>
                            <div class="doc-inp-group">
                                <div class="doc-inp-icon">
                                    <input type="text" name="ac_code" id="acCodeInput" class="doc-inp" value="<?php echo htmlspecialchars($ac_code); ?>" <?php echo $is_view_mode ? 'readonly' : ''; ?> placeholder="Code" onclick="<?php echo !$is_view_mode ? "openReferenceSearch('debit')" : ''; ?>">
                                    <i class="fa fa-search"></i>
                                </div>
                                <input type="text" name="debit_ac_name" class="doc-inp" value="<?php echo htmlspecialchars($debit_ac_name); ?>" placeholder="Account Name" readonly>
                            </div>
                        </div>
                        <div class="doc-field span-2">
                            <label class="doc-lbl">Ref. Name</label>
                            <div class="doc-inp-group">
                                <div class="doc-inp-icon">
                                    <input type="text" name="ref_code" id="refCodeInput" class="doc-inp" value="<?php echo htmlspecialchars($ref_code); ?>" <?php echo $is_view_mode ? 'readonly' : ''; ?> placeholder="Code" onclick="<?php echo !$is_view_mode ? "openReferenceSearch('ref')" : ''; ?>">
                                    <i class="fa fa-search"></i>
                                </div>
                                <input type="text" name="ref_name" class="doc-inp" value="<?php echo htmlspecialchars($ref_name); ?>" placeholder="Reference Name" readonly>
                                <button type="button" class="doc-more doc-more-soft" onclick="erpToolbarAction('select')"><i class="fa fa-check-square-o"></i> Pending Invoices</button>
                            </div>
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
                <div class="doc-sum-total">
                    <label class="doc-lbl">Amount</label>
                    <small id="rvSumCurrency"><?php echo htmlspecialchars($currency); ?></small>
                    <input type="text" name="amount" class="doc-inp doc-inp-num" value="<?php echo htmlspecialchars($amount); ?>" readonly>
                </div>
            </section>
            <?php include __DIR__ . '/../../../shared/doc_attachments_panel.php'; ?>
        </aside>
    </form>

</div><!-- /.mr-container -->
</div><!-- /.container-fluid -->

<!-- Reference Search Modal -->
<div id="referenceSearchModal" class="popup-overlay erp-search" style="display:none;">
    <div class="erp-search-dialog" role="dialog" aria-modal="true">
        <div class="erp-search-head">
            <h4 class="erp-search-title"><i class="fa fa-search"></i><span id="referenceSearchTitle">Select Debit Account</span></h4>
            <button type="button" class="erp-search-close" onclick="closeReferenceSearch()" title="Close" aria-label="Close"><i class="fa fa-times"></i></button>
        </div>
        <div class="erp-search-bar">
            <div class="erp-search-input">
                <i class="fa fa-search"></i>
                <input type="text" id="referenceSearchInput" autocomplete="off" placeholder="Search by Code or Name..." onkeyup="searchReferences(this.value)">
            </div>
            <button type="button" class="erp-search-btn" id="referenceSearchReset" style="display: none;" onclick="resetReferenceSearch()">Reset</button>
        </div>
        <div class="erp-search-body">
            <table class="erp-search-table" id="referenceSearchResults">
                <thead>
                    <tr>
                        <th style="width: 110px;">Code</th>
                        <th>Account Name</th>
                        <th id="referenceGroupHead" style="width: 220px; display: none;">Parent Group</th>
                        <th style="width: 100px; text-align: center;">Action</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
</div>

<!-- Select Invoices Modal -->
<div id="selectInvoicesModal" class="popup-overlay erp-search" style="display:none;">
    <div class="erp-search-dialog lg" role="dialog" aria-modal="true">
        <div class="erp-search-head">
            <h4 class="erp-search-title"><i class="fa fa-file-text-o"></i>Outstanding Invoices - <span id="selectInvoicesAccountName"></span></h4>
            <button type="button" class="erp-search-close" onclick="closeSelectInvoices()" title="Close" aria-label="Close"><i class="fa fa-times"></i></button>
        </div>
        <div class="erp-search-body">
            <table class="erp-search-table" id="selectInvoicesTable">
                <thead>
                    <tr>
                        <th style="min-width: 100px;">Invoice No.</th>
                        <th style="min-width: 100px;">Invoice Date</th>
                        <th style="width: 110px; text-align: right;">Debit</th>
                        <th style="width: 110px; text-align: right;">Credit</th>
                        <th style="width: 120px; text-align: right;">Balance</th>
                        <th style="width: 90px; text-align: center;">
                            <label class="erp-search-check" style="font-size: 12px; font-weight: 600;">
                                <input type="checkbox" id="selectAllInvoices" onchange="toggleAllInvoices(this.checked)"> Select
                            </label>
                        </th>
                        <th style="width: 130px; text-align: right;">Applied Amount</th>
                    </tr>
                </thead>
                <tbody id="selectInvoicesTableBody"></tbody>
            </table>
        </div>
        <div id="selectInvoicesNoData" class="erp-search-state" style="display: none;">
            No outstanding invoices found for this account.
        </div>
        <div class="erp-search-foot">
            <button type="button" class="erp-search-btn" onclick="closeSelectInvoices()">Close</button>
            <button type="button" class="erp-search-btn primary" onclick="applySelectedInvoices()">Apply Selected</button>
        </div>
    </div>
</div>

<!-- ── More.. Comment Modal ── -->
<div id="moreCommentModal" class="popup-overlay erp-search" style="display:none;">
    <div class="erp-search-dialog sm" role="dialog" aria-modal="true">
        <div class="erp-search-head">
            <h4 class="erp-search-title"><i class="fa fa-comment-o"></i><span id="moreModalTitle"></span></h4>
            <button type="button" class="erp-search-close" onclick="closeMoreModal()" title="Close" aria-label="Close"><i class="fa fa-times"></i></button>
        </div>
        <div class="erp-search-content">
            <textarea id="moreModalTextarea" class="erp-search-textarea" rows="6"></textarea>
        </div>
        <div class="erp-search-foot">
            <button type="button" class="erp-search-btn" onclick="closeMoreModal()">Close</button>
            <button type="button" class="erp-search-btn primary" onclick="saveMore()">Save</button>
        </div>
    </div>
</div>

<script>
    // URL to this file for AJAX calls — set by PHP above, works standalone and via receipt.php container
    var _rvHeaderSelf = '<?php echo htmlspecialchars($rv_self_url); ?>';

    let referenceSearchTimeout;
    let _moreFieldName = '';

    // ---- More.. Comment Modal ----
    function openMoreModal(fieldName, label) {
        _moreFieldName = fieldName;
        const input = document.querySelector('[name="' + fieldName + '"]');
        document.getElementById('moreModalTitle').textContent = label;
        document.getElementById('moreModalTextarea').value = input ? input.value : '';
        document.getElementById('moreCommentModal').style.display = 'flex';
        document.getElementById('moreModalTextarea').focus();
    }

    function saveMore() {
        const input = document.querySelector('[name="' + _moreFieldName + '"]');
        if (input) {
            input.value = document.getElementById('moreModalTextarea').value;
        }
        closeMoreModal();
    }

    function closeMoreModal() {
        document.getElementById('moreCommentModal').style.display = 'none';
        _moreFieldName = '';
    }

    // 'debit' fills Debit A/c, 'ref' fills Ref. Name (and the detail lines' Code)
    let _referenceSearchMode = 'debit';

    function openReferenceSearch(mode) {
        _referenceSearchMode = mode === 'ref' ? 'ref' : 'debit';
        document.getElementById('referenceSearchTitle').textContent =
            _referenceSearchMode === 'ref' ? 'Select Reference Account' : 'Select Debit Account';
        document.getElementById('referenceGroupHead').style.display = _referenceSearchMode === 'ref' ? '' : 'none';
        document.getElementById('referenceSearchReset').style.display = _referenceSearchMode === 'ref' ? '' : 'none';
        const modal = document.getElementById('referenceSearchModal');
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
        const input = document.getElementById('referenceSearchInput');
        input.value = '';
        input.focus();
        searchReferences('');
    }

    function resetReferenceSearch() {
        const input = document.getElementById('referenceSearchInput');
        input.value = '';
        input.focus();
        searchReferences('');
    }

    function closeReferenceSearch() {
        document.getElementById('referenceSearchModal').style.display = 'none';
        document.body.style.overflow = '';
    }

    function searchReferences(query) {
        clearTimeout(referenceSearchTimeout);
        referenceSearchTimeout = setTimeout(() => {
            fetch(_rvHeaderSelf + '?action=search_references&term=' + encodeURIComponent(query)
                    + (_referenceSearchMode === 'ref' ? '&group=1' : ''))
                .then(response => {
                    if (!response.ok) throw new Error('Server returned ' + response.status);
                    return response.json();
                })
                .then(data => {
                    console.log('Reference search results:', data);
                    const tbody = document.querySelector('#referenceSearchResults tbody');
                    const showGroup = _referenceSearchMode === 'ref';
                    const cols = showGroup ? 4 : 3;
                    tbody.innerHTML = '';
                    if (data.length === 0) {
                        tbody.innerHTML = '<tr><td colspan="' + cols + '" class="text-center p-3 text-muted">No accounts found</td></tr>';
                        return;
                    }
                    data.forEach(reference => {
                        const row = tbody.insertRow();
                        row.style.cursor = 'pointer';
                        row.innerHTML = `
                            <td style="font-weight: 600;">${htmlEscape(reference.ac_code)}</td>
                            <td>${htmlEscape(reference.description)}</td>
                            ${showGroup ? `<td style="color: var(--text-2, #475569);">${htmlEscape(reference.parent_group || '')}</td>` : ''}
                            <td class="text-center">
                                <button type="button" class="btn btn-sm btn-primary"
                                    onclick="selectReference(
                                        '${htmlEscape(reference.ac_code)}',
                                        '${htmlEscape(reference.description)}',
                                        '${htmlEscape(reference.contact_name)}',
                                        '${htmlEscape(reference.contact_no)}',
                                        '${htmlEscape(reference.contact_email)}'
                                    )">
                                    <i class="fa fa-check"></i>
                                </button>
                            </td>
                        `;
                    });
                })
                .catch(err => {
                    console.error('Error:', err);
                    const tbody = document.querySelector('#referenceSearchResults tbody');
                    tbody.innerHTML = '<tr><td colspan="' + (_referenceSearchMode === 'ref' ? 4 : 3) + '" class="text-center p-3 text-danger">Error loading accounts</td></tr>';
                });
        }, 300);
    }

    function selectReference(ac_code, description, contact_name, contact_no, contact_email) {
        if (_referenceSearchMode === 'ref') {
            const refCodeEl = document.getElementById('refCodeInput');
            const prevRef = refCodeEl.value.trim();
            refCodeEl.value = ac_code;
            document.querySelector('input[name="ref_name"]').value = description || '';
            if (typeof rvdApplyRefAccount === 'function') rvdApplyRefAccount(ac_code, description || '', prevRef);
        } else {
            document.getElementById('acCodeInput').value = ac_code;
            document.querySelector('input[name="debit_ac_name"]').value = description;
        }
        closeReferenceSearch();
    }

    function htmlEscape(text) {
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, m => map[m]);
    }

    // ---- Select Invoices Modal (SELECT button) ----
    function openSelectInvoices() {
        const refCodeInput = document.getElementById('refCodeInput');
        const refCode = refCodeInput ? refCodeInput.value.trim() : '';
        
        if (!refCode) {
            alert('Please select a Reference Code first');
            return;
        }

        const modal = document.getElementById('selectInvoicesModal');
        const refNameInput = document.querySelector('input[name="ref_name"]');
        const refName = refNameInput ? refNameInput.value : 'Unknown Account';
        
        document.getElementById('selectInvoicesAccountName').textContent = refName;
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
        
        loadOutstandingInvoices(refCode);
    }

    function closeSelectInvoices() {
        document.getElementById('selectInvoicesModal').style.display = 'none';
        document.body.style.overflow = '';
    }

    function loadOutstandingInvoices(ac_code) {
        const tableBody = document.getElementById('selectInvoicesTableBody');
        const noData = document.getElementById('selectInvoicesNoData');
        const tableDiv = document.querySelector('#selectInvoicesTable').parentElement;
        
        tableBody.innerHTML = '';
        noData.style.display = 'none';
        document.getElementById('selectAllInvoices').checked = false;
        
        fetch(_rvHeaderSelf + '?action=get_outstanding_invoices&ac_code=' + encodeURIComponent(ac_code))
            .then(response => {
                if (!response.ok) throw new Error('Server returned ' + response.status);
                return response.json();
            })
            .then(data => {
                if (!Array.isArray(data)) {
                    throw new Error('Invalid response format');
                }
                
                if (data.length === 0) {
                    noData.style.display = 'block';
                    tableDiv.style.display = 'none';
                    return;
                }
                
                tableDiv.style.display = 'block';
                data.forEach((invoice, index) => {
                    const row = tableBody.insertRow();
                    const balanceAmount = parseFloat(invoice.balance) || 0;
                    
                    row.innerHTML = `
                        <td style="font-weight: 600;">${htmlEscape(invoice.invoiceno)}</td>
                        <td>${htmlEscape(invoice.invoicedate)}</td>
                        <td style="text-align: right;">${formatNumber(invoice.debit)}</td>
                        <td style="text-align: right;">${formatNumber(invoice.credit)}</td>
                        <td style="text-align: right; font-weight: 600;">${formatNumber(balanceAmount)}</td>
                        <td style="text-align: center;">
                            <input type="checkbox" class="invoice-checkbox" 
                                   data-invoiceno="${htmlEscape(invoice.invoiceno)}"
                                   data-invoicedate="${htmlEscape(invoice.invoicedate)}"
                                   data-balance="${balanceAmount}"
                                   value="${index}"
                                   onchange="syncSelectAllInvoices()">
                        </td>
                        <td style="text-align: right;">
                            <input type="number" class="invoice-applied-amount" 
                                   style="width: 100px; padding: 4px; text-align: right;"
                                   step="0.01" min="0" max="${balanceAmount}" 
                                   value="0.00" placeholder="0.00">
                        </td>
                    `;
                });
            })
            .catch(err => {
                console.error('Error:', err);
                tableBody.innerHTML = '<tr><td colspan="7" class="text-center p-3 text-danger">Error loading invoices: ' + htmlEscape(err.message) + '</td></tr>';
            });
    }

    function toggleAllInvoices(checked) {
        document.querySelectorAll('#selectInvoicesTableBody .invoice-checkbox').forEach(cb => { cb.checked = checked; });
    }

    function syncSelectAllInvoices() {
        const boxes = document.querySelectorAll('#selectInvoicesTableBody .invoice-checkbox');
        document.getElementById('selectAllInvoices').checked =
            boxes.length > 0 && Array.from(boxes).every(cb => cb.checked);
    }

    function formatNumber(value) {
        const num = parseFloat(value) || 0;
        return num.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ",");
    }

    function applySelectedInvoices() {
        const checkboxes = document.querySelectorAll('.invoice-checkbox:checked');
        
        if (checkboxes.length === 0) {
            alert('Please select at least one invoice');
            return;
        }

        let totalAmount = 0;
        let selectedInvoices = [];
        
        checkboxes.forEach((checkbox, index) => {
            const invoiceNo = checkbox.getAttribute('data-invoiceno');
            const appliedInput = checkbox.closest('tr').querySelector('.invoice-applied-amount');
            const appliedAmount = parseFloat(appliedInput.value) || 0;
            
            selectedInvoices.push({
                invoiceno: invoiceNo,
                invoicedate: checkbox.getAttribute('data-invoicedate') || '',
                applied_amount: appliedAmount,
                // no Applied Amount entered → receive the full balance
                amount: appliedAmount > 0 ? appliedAmount : (parseFloat(checkbox.getAttribute('data-balance')) || 0)
            });
            
            totalAmount += appliedAmount;
        });

        // Store the selected invoices for later reference if needed
        window._selectedInvoicesData = selectedInvoices;

        if (typeof rvdApplyInvoices === 'function') {
            // Detail lines drive the header Amount (rvdRecalc)
            rvdApplyInvoices(
                document.getElementById('refCodeInput').value.trim(),
                document.querySelector('input[name="ref_name"]').value,
                selectedInvoices
            );
        } else {
            const amountInput = document.querySelector('input[name="amount"]');
            if (amountInput) {
                amountInput.value = totalAmount.toFixed(2);
            }
        }

        closeSelectInvoices();
    }

    // ---- Currency Dropdown ----
    function onCurrencyChange(sel) {
        const opt = sel.options[sel.selectedIndex];
        document.getElementById('currencyCode').value = opt.value;
        document.getElementById('currencyRate').value = opt.getAttribute('data-rate') || '';
        var curLbl = document.getElementById('rvSumCurrency');
        if (curLbl) curLbl.textContent = opt.value;
    }

    // ---- Cheque narration: "Chq:12365 Dt:10/10/2026" from CHQ/TT No. + Date ----
    function rvUpdateChqNarration() {
        const out = document.getElementById('rvChqNarration');
        if (!out) return;
        const chq = (document.querySelector('#rvForm [name="chq_no"]') || {}).value || '';
        const dt  = (document.querySelector('#rvForm [name="chq_date"]') || {}).value || '';
        const m = dt.match(/^(\d{4})-(\d{2})-(\d{2})$/);
        const parts = [];
        if (chq.trim() !== '') parts.push('Chq:' + chq.trim());
        if (m) parts.push('Dt:' + m[3] + '/' + m[2] + '/' + m[1]);
        out.value = parts.join(' ');
    }

    // Sync rate on page load from the selected dropdown option
    document.addEventListener('DOMContentLoaded', function () {
        const sel = document.getElementById('currencySelect');
        if (sel) { onCurrencyChange(sel); }
        rvUpdateChqNarration();
    });

    // ---- Doc Type Title Dropdown ----
    function toggleDocTypeMenu() {
        const menu = document.getElementById('mrDocTypeMenu');
        if (menu) {
            menu.classList.toggle('open');
        }
    }

    function selectDocType(code, name) {
        document.getElementById('mrTitleText').textContent = name;
        var codeEl = document.getElementById('mrTitleCode');
        if (codeEl) codeEl.textContent = code;
        document.getElementById('doc_type_selected_field').value = code;
        const menu = document.getElementById('mrDocTypeMenu');
        if (menu) {
            menu.classList.remove('open');
        }
        window.location.href = erpNavUrl('doc_type=' + encodeURIComponent(code));
    }

    // Close dropdown when clicking outside (but not on modals)
    document.addEventListener('click', function(e) {
        const btn = document.getElementById('mrTitleBtn');
        const wrapper = btn ? btn.closest('.mr-title-wrapper') : null;

        // Don't close dropdown if clicking inside modal
        const modalOverlay = e.target.closest('.popup-overlay');
        if (modalOverlay && modalOverlay.style.display === 'flex') {
            return;
        }

        if (wrapper && !wrapper.contains(e.target)) {
            const menu = document.getElementById('mrDocTypeMenu');
            if (menu) {
                menu.classList.remove('open');
            }
        }
    });

    // ── Register shared toolbar callbacks ────────────────────────────────────
    // window.erpToolbar is defined by shared/toolbar.php (loaded by the container).
    // Guard allows this fragment to also work standalone if needed.
    window.erpToolbar = window.erpToolbar || {};

    // SAVE — submit the header form
    window.erpToolbar.onSave = function () {
        var btn = document.querySelector('[name="save_rv"]');
        if (btn) {
            btn.click();
            return;
        }
        var form = document.getElementById('rvForm');
        if (form) {
            form.submit();
        }
    };

    // SELECT — open invoices popup for the selected reference account
    window.erpToolbar.onSelect = function () {
        openSelectInvoices();
    };

    // NEW — open a blank document of the same type
    window.erpToolbar.onNew = function () {
        window.location.href = erpNavUrl('doc_type=<?php echo urlencode($selected_doc_type); ?>');
    };

    // FIND — navigate to the document selected in the toolbar's search modal
    window.erpToolbar.onDocSelect = function (doc_no) {
        window.location.href = erpNavUrl('doc_no=' + encodeURIComponent(doc_no)
            + '&doc_type=' + encodeURIComponent(window.ERP_DOC_TYPE));
    };

    // PRIOR — navigate to the previous document, preserving doc_type
    window.erpToolbar.onPrior = function () {
        if (window.ERP_PREV_DOC_NO) {
            window.location.href = erpNavUrl('doc_no=' + encodeURIComponent(window.ERP_PREV_DOC_NO)
                + '&doc_type=' + encodeURIComponent(window.ERP_DOC_TYPE));
        }
    };

    // NEXT — navigate to the next document (or open new), preserving doc_type
    window.erpToolbar.onNext = function () {
        if (window.ERP_NEXT_DOC_NO) {
            window.location.href = erpNavUrl('doc_no=' + encodeURIComponent(window.ERP_NEXT_DOC_NO)
                + '&doc_type=' + encodeURIComponent(window.ERP_DOC_TYPE));
        } else {
            window.location.href = erpNavUrl('doc_type=' + encodeURIComponent(window.ERP_DOC_TYPE));
        }
    };

    // EDIT (onEditVerified) — called by toolbar AFTER the edit password is verified.
    // Unlocks all readonly/disabled fields in the header form.
    window.erpToolbar.onEditVerified = function () {
        // Unlock header form
        document.querySelectorAll('#rvForm [readonly]').forEach(function (el) {
            el.removeAttribute('readonly');
        });
        document.querySelectorAll('#rvForm [disabled]').forEach(function (el) {
            if (el.name !== 'doc_no') el.removeAttribute('disabled');
        });
        // Re-lock fields that must never be editable by the user
        ['doc_no','salesman','debit_ac_name','amount'].forEach(function(n) {
            var el = document.querySelector('#rvForm [name="' + n + '"]');
            if (el) el.setAttribute('readonly', '');
        });
        var chqNarrEl = document.getElementById('rvChqNarration');
        if (chqNarrEl) chqNarrEl.setAttribute('readonly', '');
        // Enable account search popup on ac_code in edit mode
        var acCodeEl = document.getElementById('acCodeInput');
        if (acCodeEl) {
            acCodeEl.setAttribute('onclick', "openReferenceSearch('debit')");
            acCodeEl.style.cursor = 'pointer';
        }
        var refCodeEl = document.getElementById('refCodeInput');
        if (refCodeEl) {
            refCodeEl.setAttribute('onclick', "openReferenceSearch('ref')");
            refCodeEl.style.cursor = 'pointer';
        }
        if (typeof erpToolbarSetState === 'function') {
            erpToolbarSetState({ save: true, edit: false, cancel: true });
        }
    };

    // CANCEL — discard unsaved changes by reloading the page
    window.erpToolbar.onCancel = function () {
        window.location.reload();
    };

    // Update the toolbar's nav constants now that the fragment knows the current doc
    // (The toolbar PHP vars are already correct when loaded via receipt.php container,
    //  but this guard ensures it also works when the fragment is loaded standalone.)
    if (typeof window.ERP_DOC_TYPE !== 'undefined') {
        window.ERP_DOC_TYPE = '<?php echo addslashes($selected_doc_type); ?>';
    }
    <?php if (!empty($save_success)): ?>
    // After save: disable SAVE button and update PRIOR/NEXT toolbar navigation
    (function() {
        var tbSave = document.getElementById('tbSave');
        if (tbSave) tbSave.disabled = true;
        window.ERP_PREV_DOC_NO = <?php echo json_encode($_save_prev_doc ?? ''); ?>;
        window.ERP_NEXT_DOC_NO = <?php echo json_encode($_save_next_doc ?? ''); ?>;
        var tbPrior = document.getElementById('tbPrior');
        if (tbPrior) tbPrior.disabled = (window.ERP_PREV_DOC_NO === '');
        // is_update hidden field: mark as existing record so next save is UPDATE
        var isUpd = document.querySelector('input[name="is_update"]');
        if (isUpd) isUpd.value = '1';
    })();
    <?php endif; ?>
</script>
