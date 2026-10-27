<?php
/**
 * Sales Quotation header fragment — included by modules/sales_quot/sales_quot.php
 * Table: sales_quotation_h  |  doc_code: SQ
 */
if (!isset($con)) {
    require('../../../db.php');
    include('../../../auth.php');
}
require_once __DIR__ . '/../../../includes/doc_setup.php';
require_once __DIR__ . '/../../../includes/erp_variable_terms.php';

$owns_output_buffer = !isset($con);
if ($owns_output_buffer) {
    ob_start();
}

$company_code = $_SESSION['company_code'] ?? '';
$branch_code  = $_SESSION['branch_code']  ?? '';
$user         = $_SESSION['username']     ?? '';

// ── AJAX: customer search ───────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'search_customers') {
    if ($owns_output_buffer && ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json');
    if (!isset($_SESSION['username'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
    try {
        $search_term = isset($_GET['term']) ? trim($_GET['term']) : '';
        $sql = "SELECT ac_master.ac_code, ac_master.description, ac_master.contact_name,
                       ac_master.contact_no, ac_master.contact_email, ISNULL(ac_master.cr_days, 0) AS cr_days
                FROM ac_master
                WHERE (ac_master.company_code = ?)
                AND (ac_master.child_exist = 'N')
                AND (ac_master.parent_account_code IN (
                    SELECT ac_master.account_code
                    FROM ac_master
                    INNER JOIN parent_control ON ac_master.company_code = parent_control.company_code
                        AND ac_master.ac_code = parent_control.ac_code
                    WHERE parent_control.company_code = ? AND parent_control.parent_type = 'C'
                ))";
        if ($search_term !== '') {
            $sql .= " AND (ac_master.ac_code LIKE ? OR ac_master.description LIKE ?)";
        }
        $sql .= " ORDER BY ac_master.ac_code ASC";
        $stmt = $con->prepare($sql);
        if ($search_term !== '') {
            $pat = '%' . $search_term . '%';
            $stmt->execute([$company_code, $company_code, $pat, $pat]);
        } else {
            $stmt->execute([$company_code, $company_code]);
        }
        $customers = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $customers[] = [
                'ac_code'       => trim($row['ac_code']),
                'description'   => trim($row['description']),
                'contact_name'  => trim($row['contact_name'] ?? ''),
                'contact_no'    => trim($row['contact_no'] ?? ''),
                'contact_email' => trim($row['contact_email'] ?? ''),
                'cr_days'       => (float) ($row['cr_days'] ?? 0),
            ];
        }
        echo json_encode($customers);
    } catch (Exception $e) {
        error_log('SQ customer search error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── AJAX: cr_days for selected customer ─────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'customer_cr_days') {
    if ($owns_output_buffer && ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json');
    if (!isset($_SESSION['username'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
    session_write_close();
    $cc_ac_code = trim($_GET['ac_code'] ?? '');
    if ($cc_ac_code === '') {
        echo json_encode(['found' => false, 'cr_days' => 0]);
        exit;
    }
    try {
        $stmt_cc = $con->prepare(
            "SELECT TOP 1 ISNULL(cr_days, 0) AS cr_days FROM ac_master WHERE company_code = ? AND ac_code = ?"
        );
        $stmt_cc->execute([$company_code, $cc_ac_code]);
        $row_cc = $stmt_cc->fetch(PDO::FETCH_ASSOC);
        echo json_encode([
            'found'   => (bool) $row_cc,
            'cr_days' => $row_cc ? (float) $row_cc['cr_days'] : 0,
        ]);
    } catch (Exception $e) {
        error_log('SQ customer cr_days error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Could not load credit days']);
    }
    exit;
}

// ── Doc No helpers (same pattern as Sales Invoice) ──────────────────────────
if (!function_exists('sq_getNextDocNo')) {
    function sq_getNextDocNo($con, $company_code, $branch_code, $doc_code, $doc_type)
    {
        $prefix = $doc_type . '/';
        $sql_prefix = "SELECT doc_prefix, doc_line, txn_prefix, year_prefix,
                              doc_year, month_prefix, doc_month, number_prefix, doc_digit
                       FROM doc_number
                       WHERE company_code = ? AND branch_code = ? AND doc_code = ? AND doc_type = ?";
        $stmt_prefix = $con->prepare($sql_prefix);
        $stmt_prefix->execute([$company_code, $branch_code, $doc_code, $doc_type]);
        $row_prefix = $stmt_prefix->fetch(PDO::FETCH_ASSOC);
        $digits = 5;
        if ($row_prefix) {
            $generated = trim(($row_prefix['doc_prefix'] ?? '')
                . ($row_prefix['doc_line'] ?? '')
                . ($row_prefix['txn_prefix'] ?? '')
                . ($row_prefix['year_prefix'] ?? '')
                . ($row_prefix['doc_year'] ?? '')
                . ($row_prefix['month_prefix'] ?? '')
                . ($row_prefix['doc_month'] ?? '')
                . ($row_prefix['number_prefix'] ?? ''));
            if ($generated !== '') {
                $prefix = $generated;
            }
            if (!empty($row_prefix['doc_digit']) && intval($row_prefix['doc_digit']) > 0) {
                $digits = intval($row_prefix['doc_digit']);
            }
        }
        $stmt = $con->prepare(
            "SELECT doc_no FROM sales_quotation_h WITH (UPDLOCK, HOLDLOCK)
             WHERE company_code = ? AND branch_code = ? AND doc_type = ?"
        );
        $stmt->execute([$company_code, $branch_code, $doc_type]);
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
        return $prefix . str_pad((string) ($max_num + 1), $digits, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('sq_getNextDocNum')) {
    function sq_getNextDocNum($con, $company_code, $branch_code, $doc_type)
    {
        $stmt = $con->prepare(
            "SELECT ISNULL(MAX(doc_num), 0) FROM sales_quotation_h WITH (UPDLOCK, HOLDLOCK)
             WHERE company_code = ? AND branch_code = ? AND doc_type = ?"
        );
        $stmt->execute([$company_code, $branch_code, $doc_type]);
        return intval($stmt->fetchColumn()) + 1;
    }
}

// ── Save Quotation Header ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_sq'])) {
    try {
        $is_update_mode  = isset($_POST['is_update']) && $_POST['is_update'] === '1';
        $p_doc_type      = strtoupper(trim($_POST['doc_type_selected'] ?? ''));
        $p_doc_no        = trim($_POST['doc_no'] ?? '');
        $p_doc_dt        = trim($_POST['doc_dt'] ?? '');
        $p_ac_code       = trim($_POST['ac_code'] ?? '');
        $p_currency      = trim($_POST['currency'] ?? '');
        $p_exchange_rate = trim($_POST['currency_rate'] ?? '');
        $p_salesman_code = trim($_POST['salesman_code'] ?? '');
        $p_txn_type      = trim($_POST['txn_type'] ?? '');
        $p_contact_name  = trim($_POST['contact_name'] ?? '');
        $p_contact_no    = trim($_POST['mobile'] ?? '');
        $p_subject       = trim($_POST['subject'] ?? '');
        $p_project       = trim($_POST['project'] ?? '');
        $p_payment_days  = (int) ($_POST['cr_days'] ?? 0);
        $p_amount        = (float) ($_POST['amount'] ?? 0);
        $p_discount      = (float) ($_POST['discount'] ?? 0);
        $p_vat_amount    = (float) ($_POST['vat_amount'] ?? 0);
        // doc_dt is date-only from the form; store with current time (datetime column)
        $p_doc_dt_val    = $p_doc_dt !== '' ? ($p_doc_dt . ' ' . date('H:i:s')) : null;
        $now             = date('Y-m-d H:i:s');

        foreach (['p_salesman_code', 'p_txn_type', 'p_contact_name', 'p_contact_no', 'p_subject', 'p_project'] as $opt) {
            if ($$opt === '') {
                $$opt = null;
            }
        }

        if ($p_ac_code === '') {
            throw new InvalidArgumentException('Please select a Customer before saving.');
        }
        if ($p_doc_type === '') {
            throw new InvalidArgumentException('Document type is missing.');
        }

        $sq_save_doc_code = isset($doc_code) && $doc_code !== '' ? $doc_code : 'SQ';
        if (!$is_update_mode) {
            $sq_save_types = erp_doc_configured_types($con, $company_code, $branch_code, $sq_save_doc_code);
            if (!isset($sq_save_types[$p_doc_type])) {
                throw new InvalidArgumentException(
                    "Document type '" . $p_doc_type . "' is not enabled in Document Setup for " . $sq_save_doc_code . '.'
                );
            }
        }

        $con->beginTransaction();

        if ($is_update_mode) {
            $sql_update = "UPDATE sales_quotation_h SET
                doc_dt = ?,
                doc_type = ?,
                ac_code = ?,
                currency_code = ?,
                exchange_rate = ?,
                salesman_code = ?,
                txn_type = ?,
                contact_name = ?,
                contact_no = ?,
                subject = ?,
                project = ?,
                payment_days = ?,
                amount = ?,
                discount_amount = ?,
                tax1_amount = ?,
                last_userid = ?,
                last_userdate = ?
            WHERE company_code = ? AND branch_code = ? AND doc_no = ?";
            $stmt_upd = $con->prepare($sql_update);
            $stmt_upd->execute([
                $p_doc_dt_val, $p_doc_type,
                $p_ac_code, $p_currency, $p_exchange_rate,
                $p_salesman_code, $p_txn_type,
                $p_contact_name, $p_contact_no,
                $p_subject, $p_project, $p_payment_days,
                $p_amount, $p_discount, $p_vat_amount,
                $user, $now,
                $company_code, $branch_code, $p_doc_no,
            ]);
        } else {
            $p_doc_no  = sq_getNextDocNo($con, $company_code, $branch_code, $sq_save_doc_code, $p_doc_type);
            $p_doc_num = sq_getNextDocNum($con, $company_code, $branch_code, $p_doc_type);
            $sql_insert = "INSERT INTO sales_quotation_h (
                company_code, branch_code,
                doc_no, doc_num, doc_dt, doc_type,
                ac_code, currency_code, exchange_rate,
                salesman_code, txn_type,
                contact_name, contact_no,
                subject, project, payment_days,
                amount, discount_amount, discount_percent, tax1_amount, tax1_percent,
                user_id, user_date, last_userid, last_userdate,
                approved, cancelled, active, submitted, despatch, revised, hide_subtotal, hide_nettotal
            ) VALUES (
                ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?,
                ?, ?,
                ?, ?,
                ?, ?, ?,
                ?, ?, 0, ?, 0,
                ?, ?, ?, ?,
                'Y', 'N', 'Y', 'N', 'N', 'N', 'N', 'N'
            )";
            $stmt_ins = $con->prepare($sql_insert);
            $stmt_ins->execute([
                $company_code, $branch_code,
                $p_doc_no, $p_doc_num, $p_doc_dt_val, $p_doc_type,
                $p_ac_code, $p_currency, $p_exchange_rate,
                $p_salesman_code, $p_txn_type,
                $p_contact_name, $p_contact_no,
                $p_subject, $p_project, $p_payment_days,
                $p_amount, $p_discount, $p_vat_amount,
                $user, $now, $user, $now,
            ]);
        }

        // Save detail lines to sales_quotation_d
        $detail_json = trim($_POST['detail_lines_json'] ?? '');
        if ($detail_json !== '') {
            $detail_lines = json_decode($detail_json, true);
            if (is_array($detail_lines)) {
                $stmt_del = $con->prepare(
                    "DELETE FROM sales_quotation_d WHERE company_code = ? AND branch_code = ? AND doc_no = ?"
                );
                $stmt_del->execute([$company_code, $branch_code, $p_doc_no]);

                if (!empty($detail_lines)) {
                    $sql_d = "INSERT INTO sales_quotation_d (
                                company_code, branch_code, doc_no,
                                serial_no, item_no,
                                item_code, item_description, remark,
                                item_qty, item_unit, item_rate, upp,
                                trans_qty, trans_unit, trans_rate,
                                discount_percent, discount_amount,
                                tax1_percent, tax1_amount,
                                exchange_rate, cancelled,
                                group_no, group_header, line_bold, line_italics, line_underline, line_size, line_left
                              ) VALUES (
                                ?, ?, ?,
                                ?, ?,
                                ?, ?, ?,
                                0, ?, 0, 1,
                                ?, ?, ?,
                                ?, ?,
                                ?, ?,
                                1, 'N',
                                '00', 'N', 'N', 'N', 'N', 'N', 'N'
                              )";
                    $stmt_d = $con->prepare($sql_d);
                    foreach ($detail_lines as $idx => $line) {
                        $serial_no = str_pad((string) ($idx + 1), 4, '0', STR_PAD_LEFT);
                        $item_no   = trim((string) ($line['line_no'] ?? '')) !== ''
                            ? trim((string) $line['line_no'])
                            : (string) ($idx + 1);
                        $d_unit = trim((string) ($line['unit'] ?? ''));
                        $d_code = trim((string) ($line['item_code'] ?? ''));
                        $d_desc = trim((string) ($line['item_desc'] ?? ''));
                        $d_remark = trim((string) ($line['remark'] ?? ''));
                        $stmt_d->execute([
                            $company_code,
                            $branch_code,
                            $p_doc_no,
                            $serial_no,
                            $item_no,
                            $d_code !== '' ? $d_code : '',
                            $d_desc !== '' ? $d_desc : null,
                            $d_remark !== '' ? $d_remark : null,
                            $d_unit !== '' ? $d_unit : null,
                            (float) ($line['qty'] ?? 0),
                            $d_unit !== '' ? $d_unit : null,
                            (float) ($line['rate'] ?? 0),
                            (float) ($line['discount_pct'] ?? 0),
                            (float) ($line['discount_amt'] ?? 0),
                            (float) ($line['vat'] ?? 0),
                            (float) ($line['tax1_amount'] ?? 0),
                        ]);
                    }
                }
            }
        }

        // Variable Terms → doc_h_terms (shared)
        erpVariableTermsPersist(
            $con,
            $company_code,
            $branch_code,
            $p_doc_no,
            $p_doc_type,
            $_POST['variable_terms_json'] ?? '[]',
            $user
        );

        $con->commit();

        $_save_prev_doc = '';
        $_save_next_doc = '';
        try {
            $stmt_sp = $con->prepare(
                "SELECT doc_no FROM sales_quotation_h
                 WHERE company_code=? AND branch_code=? AND doc_type=?
                   AND doc_num < (SELECT doc_num FROM sales_quotation_h WHERE doc_no=? AND company_code=? AND branch_code=?)
                 ORDER BY doc_num DESC OFFSET 0 ROWS FETCH NEXT 1 ROWS ONLY"
            );
            $stmt_sp->execute([$company_code, $branch_code, $p_doc_type, $p_doc_no, $company_code, $branch_code]);
            $r_sp = $stmt_sp->fetch(PDO::FETCH_ASSOC);
            if ($r_sp) {
                $_save_prev_doc = trim($r_sp['doc_no']);
            }
        } catch (Exception $e) {
            error_log('SQ Prior nav error: ' . $e->getMessage());
        }
        try {
            $stmt_sn = $con->prepare(
                "SELECT doc_no FROM sales_quotation_h
                 WHERE company_code=? AND branch_code=? AND doc_type=?
                   AND doc_num > (SELECT doc_num FROM sales_quotation_h WHERE doc_no=? AND company_code=? AND branch_code=?)
                 ORDER BY doc_num ASC OFFSET 0 ROWS FETCH NEXT 1 ROWS ONLY"
            );
            $stmt_sn->execute([$company_code, $branch_code, $p_doc_type, $p_doc_no, $company_code, $branch_code]);
            $r_sn = $stmt_sn->fetch(PDO::FETCH_ASSOC);
            if ($r_sn) {
                $_save_next_doc = trim($r_sn['doc_no']);
            }
        } catch (Exception $e) {
            error_log('SQ Next nav error: ' . $e->getMessage());
        }

        $_GET['doc_no']   = $p_doc_no;
        $_GET['doc_type'] = $p_doc_type;
        $save_success     = true;
    } catch (InvalidArgumentException $e) {
        if ($con->inTransaction()) {
            $con->rollBack();
        }
        $save_error = $e->getMessage();
    } catch (Exception $e) {
        if ($con->inTransaction()) {
            $con->rollBack();
        }
        error_log('Save sales quotation error: ' . $e->getMessage());
        $save_error = 'Save failed: ' . $e->getMessage();
    }
}

// ── Doc types / selected type ───────────────────────────────────────────────
$target_code = isset($doc_code) && $doc_code !== ''
    ? $doc_code
    : (isset($_GET['code']) && ctype_alnum($_GET['code']) ? strtoupper(trim($_GET['code'])) : 'SQ');
$sq_doc_types = erp_doc_configured_types($con, $company_code, $branch_code, $target_code);

$requested_doc_type = '';
if (isset($_GET['doc_type']) && $_GET['doc_type'] !== '' && ctype_alnum($_GET['doc_type'])) {
    $requested_doc_type = strtoupper(trim($_GET['doc_type']));
} elseif (isset($_GET['type']) && $_GET['type'] !== '' && ctype_alnum($_GET['type'])) {
    $requested_doc_type = strtoupper(trim($_GET['type']));
} elseif (isset($_POST['doc_type_selected']) && $_POST['doc_type_selected'] !== '') {
    $requested_doc_type = strtoupper(trim($_POST['doc_type_selected']));
}
$selected_doc_type = isset($sq_doc_types[$requested_doc_type])
    ? $requested_doc_type
    : erp_doc_default_type($con, $company_code, $branch_code, $target_code, $sq_doc_types);
$selected_doc_name = $sq_doc_types[$selected_doc_type] ?? $selected_doc_type;

// Defaults
$doc_no        = sq_getNextDocNo($con, $company_code, $branch_code, $target_code, $selected_doc_type);
$doc_num       = 1;
try {
    $doc_num = sq_getNextDocNum($con, $company_code, $branch_code, $selected_doc_type);
} catch (Exception $e) {
    error_log('SQ doc_num fetch error: ' . $e->getMessage());
}
$doc_dt        = date('Y-m-d');
$ac_code       = '';
$customer_name = '';
$contact_name  = '';
$mobile        = '';
$subject       = '';
$project       = '';
$txn_type      = '';
$cr_days       = '';
$currency      = 'AED';
$currency_rate = '1.000000000';
$amount        = '0.00';
$discount      = '0.00';
$vat_pct       = '0';
$vat_amount    = '0.00';
$total_lc      = '0.00';
$is_view_mode  = false;
$entered_by_user  = strtoupper($user);
$entered_by_date  = date('d/m/Y H:i');
$modified_by_user = strtoupper($user);
$modified_by_date = $entered_by_date;

// Logged-in salesman
$salesman_code = '';
$salesman_name = '';
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
    error_log('SQ Salesman fetch error: ' . $e->getMessage());
}

// Currency list
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
            'exchange_rate' => rtrim(rtrim(number_format((float) $row_cur['exchange_rate'], 9, '.', ''), '0'), '.'),
        ];
    }
} catch (Exception $e) {
    error_log('SQ Currency fetch error: ' . $e->getMessage());
}

// Type list (txn_type_master)
$txn_type_list = [];
try {
    $stmt_tt = $con->prepare(
        "SELECT type_code, type_name FROM txn_type_master
         WHERE company_code = ? AND branch_code = ?
         ORDER BY type_code ASC"
    );
    $stmt_tt->execute([$company_code, $branch_code]);
    while ($row_tt = $stmt_tt->fetch(PDO::FETCH_ASSOC)) {
        $txn_type_list[] = [
            'code' => trim($row_tt['type_code']),
            'name' => trim($row_tt['type_name']),
        ];
    }
} catch (Exception $e) {
    error_log('SQ txn_type_master fetch error: ' . $e->getMessage());
}

// Default VAT % from branch
try {
    $stmt_bm = $con->prepare("SELECT tax1_percent FROM branch_master WHERE company_code = ? AND branch_code = ?");
    $stmt_bm->execute([$company_code, $branch_code]);
    $row_bm = $stmt_bm->fetch(PDO::FETCH_ASSOC);
    if ($row_bm) {
        $vat_pct = trim((string) ($row_bm['tax1_percent'] ?? '0'));
    }
} catch (Exception $e) {
    error_log('SQ branch VAT fetch error: ' . $e->getMessage());
}

// Load existing document
if (isset($_GET['doc_no']) && trim($_GET['doc_no']) !== '') {
    $search_doc = trim($_GET['doc_no']);
    $stmt_edit = $con->prepare(
        "SELECT qh.*,
                ISNULL(am.description, '') AS customer_name_db,
                ISNULL(s.salesman_name,  '') AS salesman_name_db
         FROM sales_quotation_h qh
         LEFT JOIN ac_master am ON am.ac_code = qh.ac_code AND am.company_code = qh.company_code
         LEFT JOIN salesman   s  ON s.salesman_code = qh.salesman_code AND s.company_code = qh.company_code
         WHERE qh.doc_no = ? AND qh.company_code = ? AND qh.branch_code = ?"
    );
    $stmt_edit->execute([$search_doc, $company_code, $branch_code]);
    $row_edit = $stmt_edit->fetch(PDO::FETCH_ASSOC);
    if ($row_edit) {
        $is_view_mode    = true;
        $doc_no          = trim($row_edit['doc_no']);
        $doc_num         = intval($row_edit['doc_num'] ?? 0);
        $ac_code         = trim($row_edit['ac_code'] ?? '');
        $customer_name   = trim($row_edit['customer_name_db'] ?? '');
        $salesman_code   = trim($row_edit['salesman_code'] ?? '');
        $salesman_name   = trim($row_edit['salesman_name_db'] ?? '');
        $txn_type        = trim($row_edit['txn_type'] ?? '');
        $contact_name    = trim($row_edit['contact_name'] ?? '');
        $mobile          = trim($row_edit['contact_no'] ?? '');
        $subject         = trim($row_edit['subject'] ?? '');
        $project         = trim($row_edit['project'] ?? '');
        $cr_days         = (string) intval($row_edit['payment_days'] ?? 0);
        $currency        = trim($row_edit['currency_code'] ?? 'AED');
        $currency_rate   = trim((string) ($row_edit['exchange_rate'] ?? '1'));
        $fmt_date = function ($d) {
            if (!$d) {
                return '';
            }
            try {
                return (new DateTime($d))->format('Y-m-d');
            } catch (Exception $e) {
                return $d;
            }
        };
        $doc_dt     = $fmt_date($row_edit['doc_dt'] ?? '');
        $amount     = number_format((float) ($row_edit['amount'] ?? 0), 2, '.', '');
        $discount   = number_format((float) ($row_edit['discount_amount'] ?? 0), 2, '.', '');
        $vat_amount = number_format((float) ($row_edit['tax1_amount'] ?? 0), 2, '.', '');
        $total_lc   = number_format(
            (float) ($row_edit['amount'] ?? 0)
            - (float) ($row_edit['discount_amount'] ?? 0)
            + (float) ($row_edit['tax1_amount'] ?? 0),
            2,
            '.',
            ''
        );
        if (!empty($row_edit['doc_type'])) {
            $selected_doc_type = strtoupper(trim($row_edit['doc_type']));
            $selected_doc_name = $sq_doc_types[$selected_doc_type]
                ?? erp_doc_type_name($con, $company_code, $branch_code, $target_code, $selected_doc_type);
        }
        $entered_by_user = strtoupper(trim($row_edit['user_id'] ?? $user));
        $entered_by_date_raw = trim($row_edit['user_date'] ?? '');
        if ($entered_by_date_raw) {
            try {
                $entered_by_date = (new DateTime($entered_by_date_raw))->format('d/m/Y H:i');
            } catch (Exception $e) {
                $entered_by_date = $entered_by_date_raw;
            }
        }
        $last_uid = trim($row_edit['last_userid'] ?? '');
        $last_udt = trim($row_edit['last_userdate'] ?? '');
        if ($last_uid !== '') {
            $modified_by_user = strtoupper($last_uid);
            if ($last_udt) {
                try {
                    $modified_by_date = (new DateTime($last_udt))->format('d/m/Y H:i');
                } catch (Exception $e) {
                    $modified_by_date = $last_udt;
                }
            }
        } else {
            $modified_by_user = $entered_by_user;
            $modified_by_date = $entered_by_date;
        }
    }
}

$sq_self_url = '/' . ltrim(
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
        <h1 class="doc-title-text">Sales Quotation</h1>
        <div class="mr-title-wrapper">
            <button type="button" class="doc-chip doc-chip-type" id="mrTitleBtn" onclick="toggleDocTypeMenu()" title="Click to switch document type">
                <span id="mrTitleText"><?php echo htmlspecialchars($selected_doc_name); ?></span>
                <i class="fa fa-caret-down"></i>
            </button>
            <div class="mr-doctype-menu" id="mrDocTypeMenu">
                <?php foreach ($sq_doc_types as $dt_code => $dt_name): ?>
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
    <form id="sqForm" class="doc-contents" method="POST" onkeydown="return event.key !== 'Enter' || event.target.tagName === 'TEXTAREA'">
        <input type="hidden" name="is_update" value="<?php echo $is_view_mode ? '1' : '0'; ?>">
        <input type="hidden" name="doc_type_selected" id="doc_type_selected_field" value="<?php echo htmlspecialchars($selected_doc_type); ?>">
        <input type="hidden" name="doc_num" value="<?php echo htmlspecialchars((string) ($doc_num ?? 0)); ?>">
        <input type="hidden" name="detail_lines_json" id="detailLinesJson" value="">
        <button type="submit" name="save_sq" value="1" style="display:none;">Save</button>

        <?php if (!empty($save_success) || !empty($save_error)): ?>
        <div class="erp-toasts" id="erpToasts">
            <?php if (!empty($save_success)): ?>
            <div class="erp-toast success" role="status" data-autohide="3000">
                <i class="fa fa-check-circle erp-toast-icon"></i>
                <div class="erp-toast-msg">Quotation <strong><?php echo htmlspecialchars($_GET['doc_no'] ?? ''); ?></strong> saved successfully.</div>
                <button type="button" class="erp-toast-close" title="Close" aria-label="Close" onclick="erpToastClose(this.parentNode)"><i class="fa fa-times"></i></button>
            </div>
            <?php endif; ?>
            <?php if (!empty($save_error)): ?>
            <div class="erp-toast error" role="alert" data-autohide="6000">
                <i class="fa fa-exclamation-triangle erp-toast-icon"></i>
                <div class="erp-toast-msg"><?php echo htmlspecialchars($save_error); ?></div>
                <button type="button" class="erp-toast-close" title="Close" aria-label="Close" onclick="erpToastClose(this.parentNode)"><i class="fa fa-times"></i></button>
            </div>
            <?php endif; ?>
        </div>
        <script>
            function erpToastClose(toast) {
                if (!toast || toast.classList.contains('hide')) return;
                toast.classList.add('hide');
                setTimeout(function () { toast.remove(); }, 250);
            }
            (function () {
                var box = document.getElementById('erpToasts');
                var bar = document.getElementById('erpToolbar');
                if (bar) box.style.top = Math.max(12, Math.round(bar.getBoundingClientRect().bottom) + 8) + 'px';
                box.querySelectorAll('.erp-toast[data-autohide]').forEach(function (toast) {
                    setTimeout(function () { erpToastClose(toast); }, parseInt(toast.getAttribute('data-autohide'), 10) || 3000);
                });
            })();
        </script>
        <?php endif; ?>

        <div class="doc-form-wrap">

            <!-- ── Document ── -->
            <div class="doc-section">
                <h4 class="doc-section-title">Document</h4>
                <div class="doc-fields">
                    <div class="doc-field">
                        <label class="doc-lbl">QTN. No.</label>
                        <input type="text" name="doc_no" class="doc-inp" value="<?php echo htmlspecialchars($doc_no); ?>" readonly>
                    </div>
                    <div class="doc-field">
                        <label class="doc-lbl">QTN. Date</label>
                        <input type="date" name="doc_dt" class="doc-inp" value="<?php echo htmlspecialchars($doc_dt); ?>" <?php echo $is_view_mode ? 'disabled' : ''; ?>>
                    </div>
                    <div class="doc-field">
                        <label class="doc-lbl">Sales Man</label>
                        <input type="hidden" name="salesman_code" value="<?php echo htmlspecialchars($salesman_code); ?>">
                        <input type="text" name="salesman" class="doc-inp" value="<?php echo htmlspecialchars($salesman_name); ?>" readonly>
                    </div>
                    <div class="doc-field">
                        <label class="doc-lbl">Type</label>
                        <select name="txn_type" class="doc-inp" <?php echo $is_view_mode ? 'disabled' : ''; ?>>
                            <option value="">-- Select --</option>
                            <?php foreach ($txn_type_list as $_tt): ?>
                            <option value="<?php echo htmlspecialchars($_tt['code']); ?>" <?php echo $txn_type === $_tt['code'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($_tt['code'] . ' - ' . $_tt['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <!-- ── Customer ── -->
            <div class="doc-section">
                <h4 class="doc-section-title">Customer</h4>
                <div class="doc-fields">
                    <div class="doc-field span-2">
                        <label class="doc-lbl">Customer</label>
                        <div class="doc-combo">
                            <input type="text" name="ac_code" id="acCodeInput" class="doc-inp doc-combo-code" value="<?php echo htmlspecialchars($ac_code); ?>" <?php echo $is_view_mode ? 'readonly' : ''; ?> placeholder="Code" onclick="<?php echo !$is_view_mode ? 'openCustomerSearch()' : ''; ?>">
                            <span class="doc-combo-sep">&middot;</span>
                            <input type="text" name="customer_name" class="doc-inp doc-combo-name" value="<?php echo htmlspecialchars($customer_name); ?>" placeholder="Search by code or name" readonly onclick="document.getElementById('acCodeInput').click()">
                            <i class="fa fa-search"></i>
                        </div>
                    </div>
                    <div class="doc-field">
                        <label class="doc-lbl">Contact Name</label>
                        <input type="text" name="contact_name" class="doc-inp" value="<?php echo htmlspecialchars($contact_name); ?>" <?php echo $is_view_mode ? 'readonly' : ''; ?>>
                    </div>
                    <div class="doc-field">
                        <label class="doc-lbl">Mobile</label>
                        <div class="doc-inp-icon static">
                            <input type="text" name="mobile" class="doc-inp" value="<?php echo htmlspecialchars($mobile); ?>" <?php echo $is_view_mode ? 'readonly' : ''; ?>>
                            <i class="fa fa-phone"></i>
                        </div>
                    </div>
                    <div class="doc-field">
                        <label class="doc-lbl">Cr. Days</label>
                        <input type="text" name="cr_days" id="crDaysInput" class="doc-inp" value="<?php echo htmlspecialchars($cr_days); ?>" <?php echo $is_view_mode ? 'readonly' : ''; ?> placeholder="From customer">
                    </div>
                    <div class="doc-field">
                        <label class="doc-lbl">Currency</label>
                        <div class="doc-inp-group">
                            <?php if ($is_view_mode): ?>
                                <input type="text" name="currency" class="doc-inp doc-currency" value="<?php echo htmlspecialchars($currency); ?>" readonly maxlength="4">
                            <?php else: ?>
                                <select id="currencySelect" class="doc-inp doc-currency" onchange="onCurrencyChange(this)">
                                    <?php foreach ($currency_list as $cur): ?>
                                        <option value="<?php echo htmlspecialchars($cur['code']); ?>"
                                                data-rate="<?php echo htmlspecialchars($cur['exchange_rate']); ?>"
                                                data-name="<?php echo htmlspecialchars($cur['name']); ?>"
                                                <?php echo ($cur['code'] === $currency) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($cur['code']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="hidden" name="currency" id="currencyCode" value="<?php echo htmlspecialchars($currency); ?>">
                            <?php endif; ?>
                            <input type="text" name="currency_rate" id="currencyRate" class="doc-rate" value="<?php echo htmlspecialchars($currency_rate); ?>" <?php echo $is_view_mode ? 'readonly' : ''; ?>>
                            <button type="button" class="doc-more" onclick="erpOpenVariableTerms()" title="Variable Terms">Variable Terms</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Quotation ── -->
            <div class="doc-section">
                <h4 class="doc-section-title">Quotation</h4>
                <div class="doc-fields">
                    <div class="doc-field span-2">
                        <label class="doc-lbl">Subject</label>
                        <div class="doc-inp-group">
                            <input type="text" name="subject" class="doc-inp" value="<?php echo htmlspecialchars($subject); ?>" <?php echo $is_view_mode ? 'readonly' : ''; ?>>
                            <button type="button" class="doc-more" onclick="openMoreModal('subject','Subject')">More..</button>
                        </div>
                    </div>
                    <div class="doc-field span-2">
                        <label class="doc-lbl">Project</label>
                        <div class="doc-inp-group">
                            <input type="text" name="project" class="doc-inp" value="<?php echo htmlspecialchars($project); ?>" <?php echo $is_view_mode ? 'readonly' : ''; ?>>
                            <button type="button" class="doc-more" onclick="openMoreModal('project','Project')">More..</button>
                        </div>
                    </div>
                </div>
            </div>

        </div><!-- /.doc-form-wrap -->

        <!-- ── Side panel Summary ── -->
        <aside class="doc-page-aside">
            <section class="doc-panel">
                <h3 class="doc-section-title">Summary</h3>
                <div class="doc-sum-row">
                    <label class="doc-lbl">Amount</label>
                    <input type="text" name="amount" class="doc-inp doc-inp-num" value="<?php echo htmlspecialchars($amount); ?>" readonly>
                </div>
                <div class="doc-sum-row">
                    <label class="doc-lbl">Discount</label>
                    <input type="text" name="discount" class="doc-inp doc-inp-num" value="<?php echo htmlspecialchars($discount); ?>" readonly>
                </div>
                <div class="doc-sum-row">
                    <label class="doc-lbl">VAT</label>
                    <div class="doc-sum-pair">
                        <input type="text" name="vat_pct" class="doc-inp doc-inp-pct" value="<?php echo htmlspecialchars($vat_pct); ?>" placeholder="%" readonly>
                        <input type="text" name="vat_amount" class="doc-inp doc-inp-num" value="<?php echo htmlspecialchars($vat_amount); ?>" readonly>
                    </div>
                </div>
                <div class="doc-sum-total">
                    <label class="doc-lbl">Total</label>
                    <small id="sqSumCurrency"><?php echo htmlspecialchars($currency); ?></small>
                    <input type="text" name="total_lc" class="doc-inp doc-inp-num" value="<?php echo htmlspecialchars($total_lc); ?>" readonly>
                </div>
                <div class="doc-sum-words" id="sqSumWords"></div>
            </section>
            <?php include __DIR__ . '/../../../shared/doc_attachments_panel.php'; ?>
        </aside>

        <?php
        // Shared Variable Terms (hidden JSON must stay inside this form)
        // Load existing rows only for a saved document (view mode), not a preview next-doc_no.
        $vt_doc_no   = !empty($is_view_mode) ? (string) $doc_no : '';
        $vt_doc_type = $selected_doc_type ?? '';
        $vt_readonly = !empty($is_view_mode);
        include __DIR__ . '/../../../shared/variable_terms.php';
        ?>
    </form>

</div><!-- /.mr-container -->
</div><!-- /.container-fluid -->

<!-- Customer Search Modal -->
<div id="customerSearchModal" class="popup-overlay erp-search" style="display:none;">
    <div class="erp-search-dialog" role="dialog" aria-modal="true" aria-labelledby="customerSearchTitle">
        <div class="erp-search-head">
            <h4 class="erp-search-title" id="customerSearchTitle"><i class="fa fa-search"></i>Select Customer</h4>
            <button type="button" class="erp-search-close" onclick="closeCustomerSearch()" title="Close" aria-label="Close"><i class="fa fa-times"></i></button>
        </div>
        <div class="erp-search-bar">
            <div class="erp-search-input">
                <i class="fa fa-search"></i>
                <input type="text" id="customerSearchInput" autocomplete="off" placeholder="Search by Customer Code or Name..." onkeyup="searchCustomers(this.value)">
            </div>
        </div>
        <div class="erp-search-meta">
            <div>
                <div class="erp-search-meta-title">Search results</div>
                <div class="erp-search-meta-sub">Customers</div>
            </div>
            <span class="erp-search-count" id="customerSearchCount"></span>
        </div>
        <div class="erp-search-list" id="customerSearchResults"></div>
    </div>
</div>

<!-- More.. Comment Modal -->
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
    var _sqHeaderSelf = '<?php echo htmlspecialchars($sq_self_url); ?>';
    var customerSearchTimeout;
    var customerSearchRows = [];
    var _moreFieldName = '';

    function openMoreModal(fieldName, label) {
        _moreFieldName = fieldName;
        var input = document.querySelector('[name="' + fieldName + '"]');
        document.getElementById('moreModalTitle').textContent = label;
        document.getElementById('moreModalTextarea').value = input ? input.value : '';
        document.getElementById('moreCommentModal').style.display = 'flex';
        document.getElementById('moreModalTextarea').focus();
    }
    function saveMore() {
        var input = document.querySelector('[name="' + _moreFieldName + '"]');
        if (input) input.value = document.getElementById('moreModalTextarea').value;
        closeMoreModal();
    }
    function closeMoreModal() {
        document.getElementById('moreCommentModal').style.display = 'none';
        _moreFieldName = '';
    }

    function openCustomerSearch() {
        var modal = document.getElementById('customerSearchModal');
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
        var input = document.getElementById('customerSearchInput');
        input.value = '';
        input.focus();
        searchCustomers('');
    }
    function closeCustomerSearch() {
        document.getElementById('customerSearchModal').style.display = 'none';
        document.body.style.overflow = '';
    }
    function searchCustomers(query) {
        clearTimeout(customerSearchTimeout);
        customerSearchTimeout = setTimeout(function () {
            fetch(_sqHeaderSelf + '?action=search_customers&term=' + encodeURIComponent(query))
                .then(function (response) {
                    if (!response.ok) throw new Error('Server returned ' + response.status);
                    return response.json();
                })
                .then(function (data) {
                    var list = document.getElementById('customerSearchResults');
                    var count = document.getElementById('customerSearchCount');
                    customerSearchRows = Array.isArray(data) ? data : [];
                    count.textContent = customerSearchRows.length + (customerSearchRows.length === 1 ? ' customer' : ' customers');
                    if (customerSearchRows.length === 0) {
                        list.innerHTML = '<div class="erp-search-state">No customers found</div>';
                        return;
                    }
                    list.innerHTML = customerSearchRows.map(function (customer, i) {
                        var contact = [];
                        if (customer.contact_name) contact.push('<i class="fa fa-user"></i>' + htmlEscape(customer.contact_name));
                        if (customer.contact_no) contact.push('<i class="fa fa-phone"></i>' + htmlEscape(customer.contact_no));
                        return '<div class="erp-search-row">'
                            + '<span class="erp-search-ico"><i class="fa fa-building-o"></i></span>'
                            + '<div class="erp-search-main">'
                            + '<div class="erp-search-name">' + htmlEscape(customer.description) + '</div>'
                            + '<div class="erp-search-sub">' + htmlEscape(customer.ac_code) + '</div>'
                            + (contact.length ? '<div class="erp-search-extra">' + contact.join(' &middot; ') + '</div>' : '')
                            + '</div>'
                            + '<button type="button" class="erp-search-open" onclick="selectCustomerRow(' + i + ')">Select <i class="fa fa-check"></i></button>'
                            + '</div>';
                    }).join('');
                })
                .catch(function (err) {
                    console.error('Error:', err);
                    document.getElementById('customerSearchCount').textContent = '';
                    document.getElementById('customerSearchResults').innerHTML = '<div class="erp-search-state error">Error loading customers</div>';
                });
        }, 300);
    }
    function selectCustomerRow(i) {
        var c = customerSearchRows[i];
        if (c) selectCustomer(c.ac_code, c.description, c.contact_name, c.contact_no, c.cr_days);
    }
    function selectCustomer(ac_code, description, contact_name, contact_no, cr_days) {
        document.getElementById('acCodeInput').value = ac_code;
        document.querySelector('input[name="customer_name"]').value = description;
        document.querySelector('input[name="contact_name"]').value = contact_name || '';
        document.querySelector('input[name="mobile"]').value = contact_no || '';
        if (typeof cr_days !== 'undefined' && cr_days !== null && cr_days !== '') {
            document.getElementById('crDaysInput').value = Number(cr_days) || 0;
        } else {
            loadCustomerCrDays(ac_code);
        }
        closeCustomerSearch();
    }
    function loadCustomerCrDays(acCode) {
        acCode = (acCode || '').trim();
        if (!acCode) return;
        fetch(_sqHeaderSelf + '?action=customer_cr_days&ac_code=' + encodeURIComponent(acCode))
            .then(function (r) { if (!r.ok) throw new Error('Server ' + r.status); return r.json(); })
            .then(function (d) {
                if (d && !d.error) {
                    document.getElementById('crDaysInput').value = Number(d.cr_days || 0);
                }
            })
            .catch(function (err) { console.error('Cr days error:', err); });
    }

    function htmlEscape(text) {
        return String(text || '').replace(/[&<>"']/g, function (m) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[m];
        });
    }

    function onCurrencyChange(sel) {
        var opt = sel.options[sel.selectedIndex];
        document.getElementById('currencyCode').value = opt.value;
        document.getElementById('currencyRate').value = opt.getAttribute('data-rate') || '';
        var curLbl = document.getElementById('sqSumCurrency');
        if (curLbl) curLbl.textContent = opt.value;
    }
    document.addEventListener('DOMContentLoaded', function () {
        var sel = document.getElementById('currencySelect');
        if (sel) onCurrencyChange(sel);
    });

    function toggleDocTypeMenu() {
        var menu = document.getElementById('mrDocTypeMenu');
        if (menu) menu.classList.toggle('open');
    }
    function selectDocType(code, name) {
        document.getElementById('mrTitleText').textContent = name;
        document.getElementById('doc_type_selected_field').value = code;
        var menu = document.getElementById('mrDocTypeMenu');
        if (menu) menu.classList.remove('open');
        window.location.href = erpNavUrl('doc_type=' + encodeURIComponent(code));
    }
    document.addEventListener('click', function (e) {
        var btn = document.getElementById('mrTitleBtn');
        var wrapper = btn ? btn.closest('.mr-title-wrapper') : null;
        var modalOverlay = e.target.closest('.popup-overlay');
        if (modalOverlay && modalOverlay.style.display === 'flex') return;
        if (wrapper && !wrapper.contains(e.target)) {
            var menu = document.getElementById('mrDocTypeMenu');
            if (menu) menu.classList.remove('open');
        }
    });

    window.erpToolbar = window.erpToolbar || {};

    window.erpToolbar.onSave = function () {
        if (typeof getDetailLinesJson === 'function') {
            var jsonField = document.getElementById('detailLinesJson');
            if (jsonField) jsonField.value = getDetailLinesJson();
        }
        if (typeof getVariableTermsJson === 'function') {
            var vtField = document.getElementById('variableTermsJson');
            if (vtField) vtField.value = getVariableTermsJson();
        }
        var btn = document.querySelector('[name="save_sq"]');
        if (btn) { btn.click(); return; }
        var form = document.getElementById('sqForm');
        if (form) form.submit();
    };
    window.erpToolbar.onNew = function () {
        window.location.href = erpNavUrl('doc_type=<?php echo urlencode($selected_doc_type); ?>');
    };
    window.erpToolbar.onPreview = function () {
        erpOpenPrintPreview(<?php echo json_encode($target_code); ?>, <?php echo json_encode($selected_doc_type); ?>, <?php echo json_encode($is_view_mode ? $doc_no : ''); ?>);
    };
    window.erpToolbar.onDocSelect = function (doc_no) {
        window.location.href = erpNavUrl('doc_no=' + encodeURIComponent(doc_no)
            + '&doc_type=' + encodeURIComponent(window.ERP_DOC_TYPE));
    };
    window.erpToolbar.onPrior = function () {
        if (window.ERP_PREV_DOC_NO) {
            window.location.href = erpNavUrl('doc_no=' + encodeURIComponent(window.ERP_PREV_DOC_NO)
                + '&doc_type=' + encodeURIComponent(window.ERP_DOC_TYPE));
        }
    };
    window.erpToolbar.onNext = function () {
        if (window.ERP_NEXT_DOC_NO) {
            window.location.href = erpNavUrl('doc_no=' + encodeURIComponent(window.ERP_NEXT_DOC_NO)
                + '&doc_type=' + encodeURIComponent(window.ERP_DOC_TYPE));
        } else {
            window.location.href = erpNavUrl('doc_type=' + encodeURIComponent(window.ERP_DOC_TYPE));
        }
    };
    window.erpToolbar.onEditVerified = function () {
        document.querySelectorAll('#sqForm [readonly]').forEach(function (el) {
            el.removeAttribute('readonly');
        });
        document.querySelectorAll('#sqForm [disabled]').forEach(function (el) {
            if (el.name !== 'doc_no') el.removeAttribute('disabled');
        });
        ['doc_no', 'salesman', 'customer_name', 'amount', 'discount', 'vat_pct', 'vat_amount', 'total_lc'].forEach(function (n) {
            var el = document.querySelector('#sqForm [name="' + n + '"]');
            if (el) el.setAttribute('readonly', '');
        });
        var acCodeEl = document.getElementById('acCodeInput');
        if (acCodeEl) {
            acCodeEl.setAttribute('onclick', 'openCustomerSearch()');
            acCodeEl.style.cursor = 'pointer';
        }
        // Unlock detail lines
        ['qty[]', 'rate[]', 'discount_pct[]', 'discount_amt[]', 'item_desc[]', 'item_code[]'].forEach(function (n) {
            document.querySelectorAll('#detailTableBody input[name="' + n + '"]').forEach(function (el) {
                el.removeAttribute('readonly');
                if (n === 'item_code[]') {
                    el.setAttribute('onfocus', 'openItemSearch(this)');
                    el.setAttribute('onclick', 'openItemSearch(this)');
                    el.style.cursor = 'pointer';
                }
            });
        });
        document.querySelectorAll('#detailTableBody select[name="unit[]"]').forEach(function (el) {
            el.disabled = false;
        });
        document.querySelectorAll('#detailTableBody .btn-danger').forEach(function (btn) { btn.style.display = ''; });
        document.querySelectorAll('.detail-toolbar .detail-btn').forEach(function (btn) {
            btn.disabled = false;
            btn.style.removeProperty('opacity');
        });
        if (typeof window.SQ_VIEW_MODE !== 'undefined') window.SQ_VIEW_MODE = false;
        if (typeof erpSetVariableTermsReadonly === 'function') {
            erpSetVariableTermsReadonly(false);
        }
        if (typeof erpToolbarSetState === 'function') {
            erpToolbarSetState({ save: true, edit: false, cancel: true });
        }
    };
    window.erpToolbar.onCancel = function () {
        window.location.reload();
    };

    if (typeof window.ERP_DOC_TYPE !== 'undefined') {
        window.ERP_DOC_TYPE = '<?php echo addslashes($selected_doc_type); ?>';
    }
    <?php if (!empty($save_success)): ?>
    (function () {
        var tbSave = document.getElementById('tbSave');
        if (tbSave) tbSave.disabled = true;
        window.ERP_PREV_DOC_NO = <?php echo json_encode($_save_prev_doc ?? ''); ?>;
        window.ERP_NEXT_DOC_NO = <?php echo json_encode($_save_next_doc ?? ''); ?>;
        var tbPrior = document.getElementById('tbPrior');
        if (tbPrior) tbPrior.disabled = (window.ERP_PREV_DOC_NO === '');
        var isUpd = document.querySelector('input[name="is_update"]');
        if (isUpd) isUpd.value = '1';
    })();
    <?php endif; ?>
</script>
