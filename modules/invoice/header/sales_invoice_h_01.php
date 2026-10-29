<?php
// Fragment included by modules/invoice/invoice.php
// When called directly for AJAX (e.g., ?action=search_customers), load db/auth
// When included by the container, these are already loaded, so we skip
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

$company_code = $_SESSION["company_code"] ?? '';
$branch_code  = $_SESSION["branch_code"]  ?? '';
$user         = $_SESSION['username']     ?? '';
$today        = date("d/m/Y");

// ── AJAX: Select JOB (pending job cards for Sales Invoice) ──────────────────
$si_ajax = $_POST['action'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($si_ajax === 'si_pending_jobs' || $si_ajax === 'si_pending_job_items')) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    if (!isset($_SESSION['username'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
    $si_enc = function ($s) {
        if ($s === null) {
            return '';
        }
        $s = trim((string) $s);
        if (!mb_check_encoding($s, 'UTF-8')) {
            $s = mb_convert_encoding($s, 'UTF-8', 'ISO-8859-1');
        }
        return $s;
    };
    try {
        if ($si_ajax === 'si_pending_jobs') {
            $ac_code = trim($_POST['ac_code'] ?? '');
            if ($ac_code === '') {
                echo json_encode(['error' => 'Please select a Customer first.']);
                exit;
            }
            $branch_like = (($_POST['all_branch'] ?? '0') === '1') ? '%' : $branch_code;
            $term = '%' . trim($_POST['term'] ?? '') . '%';
            $stmt = $con->prepare(
                "SELECT DISTINCT view_jc_si_balance.docno,
                        job_card_h.doc_dt
                 FROM job_card_h, view_jc_si_balance
                 WHERE (job_card_h.company_code = view_jc_si_balance.companycode)
                   AND (job_card_h.doc_no = view_jc_si_balance.docno)
                   AND (job_card_h.branch_code = view_jc_si_balance.branchcode)
                   AND (view_jc_si_balance.companycode = ?)
                   AND (view_jc_si_balance.branchcode LIKE ?)
                   AND (job_card_h.ac_code = ?)
                   AND (job_card_h.cancelled = 'N')
                   AND (view_jc_si_balance.docno LIKE ?)
                 GROUP BY view_jc_si_balance.companycode,
                          view_jc_si_balance.docno,
                          job_card_h.doc_dt
                 HAVING (SUM(view_jc_si_balance.txnqty) > 0)
                 ORDER BY job_card_h.doc_dt DESC, view_jc_si_balance.docno DESC"
            );
            $stmt->execute([$company_code, $branch_like, $ac_code, $term]);
            $docs = [];
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $docs[] = [
                    'doc_no' => $si_enc($r['docno']),
                    'doc_dt' => $r['doc_dt'] ? date('d/m/Y', strtotime($r['doc_dt'])) : '',
                ];
            }
            echo json_encode($docs);
        } else {
            $job_no = trim($_POST['doc_no'] ?? '');
            if ($job_no === '') {
                echo json_encode(['error' => 'Job Card number is required.']);
                exit;
            }
            $branch_like = trim($_POST['branch'] ?? '');
            $branch_like = ($branch_like === '' || (($_POST['all_branch'] ?? '0') === '1')) ? '%' : $branch_like;

            $header = [
                'job_no'       => $si_enc($job_no),
                'subject'      => '',
                'payment_days' => 0,
                'qtn_no'       => '',
            ];
            try {
                $stmt_h = $con->prepare(
                    "SELECT TOP 1 subject, ISNULL(payment_days, 0) AS payment_days, qtn_no
                     FROM job_card_h
                     WHERE company_code = ?
                       AND branch_code LIKE ?
                       AND doc_no = ?
                     ORDER BY CASE WHEN branch_code = ? THEN 0 ELSE 1 END"
                );
                $stmt_h->execute([$company_code, $branch_like, $job_no, $branch_code]);
                $row_h = $stmt_h->fetch(PDO::FETCH_ASSOC);
                if ($row_h) {
                    $header['subject']      = $si_enc($row_h['subject'] ?? '');
                    $header['payment_days'] = (int) ($row_h['payment_days'] ?? 0);
                    $header['qtn_no']       = $si_enc($row_h['qtn_no'] ?? '');
                }
            } catch (Exception $e) {
                error_log('SI select JOB header error: ' . $e->getMessage());
            }

            $stmt = $con->prepare(
                "SELECT DISTINCT SPACE(1) AS selected,
                        SUM(view_jc_si_balance.txnqty) AS txnqty,
                        MAX(view_jc_si_balance.txnunit) AS unit,
                        0.00 AS qty,
                        view_jc_si_balance.companycode,
                        view_jc_si_balance.branchcode,
                        view_jc_si_balance.docno,
                        view_jc_si_balance.itemno,
                        view_jc_si_balance.itemcode,
                        view_jc_si_balance.itemname,
                        MAX(ISNULL(job_card_d.trans_rate, job_card_d.item_rate)) AS rate,
                        MAX(ISNULL(job_card_d.tax1_percent, 0)) AS tax1_percent,
                        MAX(CAST(job_card_d.remark AS NVARCHAR(MAX))) AS remark,
                        MAX(ISNULL(job_card_d.quotation_no, '')) AS quotation_no
                 FROM view_jc_si_balance
                 LEFT JOIN job_card_d
                   ON job_card_d.company_code = view_jc_si_balance.companycode
                  AND job_card_d.branch_code = view_jc_si_balance.branchcode
                  AND job_card_d.doc_no = view_jc_si_balance.docno
                  AND job_card_d.item_no = view_jc_si_balance.itemno
                 WHERE (view_jc_si_balance.companycode = ?)
                   AND (view_jc_si_balance.branchcode LIKE ?)
                   AND (view_jc_si_balance.docno = ?)
                 GROUP BY view_jc_si_balance.companycode,
                          view_jc_si_balance.branchcode,
                          view_jc_si_balance.docno,
                          view_jc_si_balance.itemno,
                          view_jc_si_balance.itemcode,
                          view_jc_si_balance.itemname
                 HAVING (SUM(view_jc_si_balance.txnqty) > 0)
                 ORDER BY view_jc_si_balance.itemno"
            );
            $stmt->execute([$company_code, $branch_like, $job_no]);
            $items = [];
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $items[] = [
                    'doc_no'    => $si_enc($r['docno']),
                    'item_no'   => $si_enc($r['itemno']),
                    'item_code' => $si_enc($r['itemcode']),
                    'item_name' => $si_enc($r['itemname']),
                    'unit'      => $si_enc($r['unit']),
                    'balance'   => (float) $r['txnqty'],
                    'rate'      => (float) ($r['rate'] ?? 0),
                    'vat'       => (float) ($r['tax1_percent'] ?? 0),
                    'remark'    => $si_enc($r['remark'] ?? ''),
                    'qtn_no'    => $si_enc($r['quotation_no'] ?? ''),
                ];
            }
            echo json_encode(['header' => $header, 'items' => $items]);
        }
    } catch (Exception $e) {
        error_log('SI select JOB error: ' . $e->getMessage());
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── AJAX: Delete document (detail + header) when allow_delete = Y ────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $si_ajax === 'si_delete_document') {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    if (!isset($_SESSION['username'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
    try {
        $del_no = trim($_POST['doc_no'] ?? '');
        if ($del_no === '') {
            echo json_encode(['error' => 'Document number is required.']);
            exit;
        }
        $stmt_perm = $con->prepare(
            "SELECT ISNULL(allow_delete, 'N') AS allow_delete
             FROM branch_user WHERE user_id = ? AND branch_code = ?"
        );
        $stmt_perm->execute([$user, $branch_code]);
        $perm = $stmt_perm->fetch(PDO::FETCH_ASSOC) ?: [];
        if (strtoupper(trim((string) ($perm['allow_delete'] ?? 'N'))) !== 'Y') {
            echo json_encode(['error' => 'You do not have delete permission (allow_delete must be Y).']);
            exit;
        }
        $con->beginTransaction();
        $stmt_dd = $con->prepare(
            "DELETE FROM invoice_d WHERE company_code = ? AND branch_code = ? AND doc_no = ?"
        );
        $stmt_dd->execute([$company_code, $branch_code, $del_no]);
        $stmt_dh = $con->prepare(
            "DELETE FROM invoice_h WHERE company_code = ? AND branch_code = ? AND doc_no = ?"
        );
        $stmt_dh->execute([$company_code, $branch_code, $del_no]);
        try {
            $stmt_vt = $con->prepare(
                "DELETE FROM doc_h_terms WHERE company_code = ? AND branch_code = ? AND doc_no = ?"
            );
            $stmt_vt->execute([$company_code, $branch_code, $del_no]);
        } catch (Exception $e) {
            // terms table may be absent on some DBs
        }
        $con->commit();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        if ($con->inTransaction()) {
            $con->rollBack();
        }
        error_log('SI delete error: ' . $e->getMessage());
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// AJAX Handler: Fetch customer list for search
if (isset($_GET['action']) && $_GET['action'] === 'search_customers') {
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
        $search_term = isset($_GET['term']) ? trim($_GET['term']) : '';

        $sql = "SELECT ac_master.ac_code, ac_master.description, ac_master.contact_name, ac_master.contact_no, ac_master.contact_email 
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
            $search_pattern = '%' . $search_term . '%';
            $stmt->execute([$company_code, $company_code, $search_pattern, $search_pattern]);
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
                'contact_email' => trim($row['contact_email'] ?? '')
            ];
        }

        header('Content-Type: application/json');
        echo json_encode($customers);
    } catch (Exception $e) {
        error_log("Customer search error: " . $e->getMessage());
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// AJAX Handler: customer credit limit / credit days (ac_master) and outstanding (view_account_ledger_summary)
if (isset($_GET['action']) && $_GET['action'] === 'customer_credit') {
    if ($owns_output_buffer && ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json');
    if (!isset($_SESSION['username'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
    // Release the session lock: the outstanding query can be slow and must not hold up
    // other requests of this session (print preview, save, find).
    session_write_close();
    $cc_ac_code = trim($_GET['ac_code'] ?? '');
    if ($cc_ac_code === '') {
        echo json_encode(['found' => false]);
        exit;
    }
    try {
        $stmt_cc = $con->prepare("SELECT TOP 1 ISNULL(cr_amount, 0) AS cr_amount, ISNULL(cr_days, 0) AS cr_days
                                  FROM ac_master WHERE company_code = ? AND ac_code = ?");
        $stmt_cc->execute([$company_code, $cc_ac_code]);
        $row_cc = $stmt_cc->fetch(PDO::FETCH_ASSOC);

        $stmt_os = $con->prepare("SELECT ISNULL(SUM(amount), 0) AS outstanding
                                  FROM view_account_ledger_summary WHERE companycode = ? AND accode = ?");
        $stmt_os->execute([$company_code, $cc_ac_code]);
        $outstanding = (float)$stmt_os->fetchColumn();

        echo json_encode([
            'found'       => (bool)$row_cc,
            'cr_amount'   => $row_cc ? (float)$row_cc['cr_amount'] : 0,
            'cr_days'     => $row_cc ? (float)$row_cc['cr_days'] : 0,
            'outstanding' => round($outstanding, 2) + 0.0,
        ]);
    } catch (Exception $e) {
        error_log("Customer credit error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Could not load credit details']);
    }
    exit;
}

// ── Save Invoice Handler ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_si'])) {
    try {
        $is_update_mode  = isset($_POST['is_update']) && $_POST['is_update'] === '1';
        $p_doc_type      = strtoupper(trim($_POST['doc_type_selected']   ?? ''));
        $p_doc_no        = trim($_POST['doc_no']            ?? '');
        $p_doc_dt        = trim($_POST['doc_dt']            ?? '');
        $p_ac_code       = trim($_POST['ac_code']           ?? '');
        $p_currency      = trim($_POST['currency']          ?? '');
        $p_exchange_rate = trim($_POST['currency_rate']     ?? '');
        $p_salesman_code = trim($_POST['salesman_code']     ?? '');
        $p_contact_name  = trim($_POST['contact_name']      ?? '');
        $p_contact_no    = trim($_POST['mobile']            ?? '');
        $p_contact_email = trim($_POST['email']             ?? '');
        $p_subject       = trim($_POST['subject']           ?? '');
        $p_project       = trim($_POST['project']           ?? '');
        $p_payment_terms = trim($_POST['payment_terms']     ?? '');
        $p_delivery_terms= trim($_POST['delivery_terms']    ?? '');
        $p_delivery_loc  = trim($_POST['delivery_loc']      ?? '');
        $p_narration     = trim($_POST['narration']         ?? '');
        $p_qtn_no        = trim($_POST['qtn_no']            ?? '');
        $p_qtn_date      = trim($_POST['qtn_date']          ?? '') ?: null;
        $p_po_no         = trim($_POST['po_no']             ?? '');
        $p_po_date       = trim($_POST['po_date']           ?? '') ?: null;
        $p_do_no         = trim($_POST['do_no']             ?? '');
        $p_do_date       = trim($_POST['do_date']           ?? '') ?: null;
        $p_job_no        = trim($_POST['job_no']            ?? '');
        $p_payment_days  = (int) ($_POST['payment_days']    ?? $_POST['cr_days'] ?? 0);
        $p_update_inv      = isset($_POST['update_inventory']) && $_POST['update_inventory'] === 'Y' ? 'Y' : 'N';
        $p_amount          = (float)($_POST['amount']      ?? 0);
        $p_tax_amount      = (float)($_POST['tax_amount']  ?? 0);
        $p_tax1_amount     = (float)($_POST['vat_amount']  ?? 0);
        $p_payment_mode    = trim($_POST['payment_mode']   ?? '');
        $p_discount_amount = (float)($_POST['discount']    ?? 0);
        $p_doc_dt_val      = $p_doc_dt !== '' ? $p_doc_dt : null;
        $now             = date('Y-m-d H:i:s');

        // Optional text columns are stored as NULL (not '') when left blank
        foreach (['p_salesman_code', 'p_contact_name', 'p_contact_no', 'p_contact_email', 'p_subject',
                  'p_project', 'p_payment_terms', 'p_delivery_terms', 'p_delivery_loc', 'p_narration',
                  'p_qtn_no', 'p_po_no', 'p_do_no', 'p_job_no', 'p_payment_mode'] as $si_opt) {
            if ($$si_opt === '') {
                $$si_opt = null;
            }
        }

        // Validate required fields before touching the DB
        if ($p_ac_code === '') {
            throw new InvalidArgumentException('Please select a Customer before saving.');
        }
        if ($p_doc_type === '') {
            throw new InvalidArgumentException('Document type is missing.');
        }

        $si_save_doc_code = isset($doc_code) && $doc_code !== '' ? $doc_code : 'SI';
        if (!$is_update_mode) {
            $si_save_types = erp_doc_configured_types($con, $company_code, $branch_code, $si_save_doc_code);
            if (!isset($si_save_types[$p_doc_type])) {
                throw new InvalidArgumentException("Document type '" . $p_doc_type . "' is not enabled in Document Setup for " . $si_save_doc_code . ".");
            }
        }

        // Qty vs Stock (branch_user.allow_zero_stock = 'Y' skips it)
        require_once __DIR__ . '/../../../includes/erp_stock_check.php';
        if (erp_user_allow_zero_stock($con) !== 'Y') {
            $si_chk_lines = json_decode(trim($_POST['detail_lines_json'] ?? ''), true);
            if (is_array($si_chk_lines) && $si_chk_lines) {
                $stmt_bl = $con->prepare("SELECT location_code FROM branch_user WHERE user_id = ? AND branch_code = ?");
                $stmt_bl->execute([$user, $branch_code]);
                $si_default_loc = trim((string) ($stmt_bl->fetchColumn() ?: ''));

                $si_stock_lines = [];
                foreach ($si_chk_lines as $ln) {
                    // Job Card / free-text lines are not stock-controlled
                    if (trim((string) ($ln['job_no'] ?? '')) !== '') {
                        continue;
                    }
                    $icode = trim((string) ($ln['item_code'] ?? ''));
                    if ($icode === '') {
                        continue;
                    }
                    $loc = trim((string) ($ln['location'] ?? ''));
                    $si_stock_lines[] = [
                        'location'  => $loc !== '' ? $loc : $si_default_loc,
                        'item_code' => $icode,
                        'qty'       => $ln['qty'] ?? 0,
                    ];
                }

                $si_own_qty = [];
                if ($is_update_mode && $p_doc_no !== '') {
                    $stmt_own = $con->prepare(
                        "SELECT d.location_code, d.item_code, SUM(d.trans_qty) AS qty
                         FROM   invoice_d d
                         INNER JOIN invoice_h h
                                 ON h.company_code = d.company_code AND h.branch_code = d.branch_code AND h.doc_no = d.doc_no
                         WHERE  h.company_code = ? AND h.branch_code = ? AND h.doc_no = ? AND h.update_inventory = 'Y'
                         GROUP BY d.location_code, d.item_code"
                    );
                    $stmt_own->execute([$company_code, $branch_code, $p_doc_no]);
                    while ($r = $stmt_own->fetch(PDO::FETCH_ASSOC)) {
                        $si_own_qty[trim((string) $r['location_code']) . '|' . trim((string) $r['item_code'])] = (float) $r['qty'];
                    }
                }

                $si_short = erp_stock_shortages($con, $si_stock_lines, $si_own_qty);
                if ($si_short) {
                    throw new InvalidArgumentException(erp_stock_shortage_message($si_short));
                }
            }
        }

        // Header and detail lines are saved together; on INSERT, doc_no and doc_num are
        // regenerated under UPDLOCK/HOLDLOCK so simultaneous saves cannot share a number.
        $con->beginTransaction();

        if ($is_update_mode) {
            $sql_update = "UPDATE invoice_h SET
                doc_dt = ?,
                doc_type = ?,
                ac_code = ?,
                currency_code = ?,
                exchange_rate = ?,
                salesman_code = ?,
                contact_name = ?,
                contact_no = ?,
                contact_email = ?,
                subject = ?,
                project = ?,
                payment_terms = ?,
                delivery_terms = ?,
                delivery_location = ?,
                narration = ?,
                qtn_no = ?,
                qtn_date = ?,
                po_no = ?,
                po_date = ?,
                do_no = ?,
                do_date = ?,
                job_no = ?,
                payment_days = ?,
                confirmed = 'Y',
                approved = 'Y',
                update_inventory = ?,
                pay_mode = ?,
                discount_amount = ?,
                amount = ?,
                tax_amount = ?,
                tax1_amount = ?,
                tax_applied = 'Y',
                discount_percent = ISNULL(discount_percent, 0),
                incentive_amount = ISNULL(incentive_amount, 0),
                contract_amount  = ISNULL(contract_amount, 0),
                received_amount  = ISNULL(received_amount, 0),
                discount1 = ISNULL(discount1, 0),
                discount2 = ISNULL(discount2, 0),
                discount3 = ISNULL(discount3, 0),
                bank_code = ISNULL(bank_code, '0'),
                tax1_percent = ISNULL(tax1_percent, 0),
                last_userid = ?,
                last_userdate = ?
            WHERE company_code = ? AND branch_code = ? AND doc_no = ?";

            $stmt_upd = $con->prepare($sql_update);
            $stmt_upd->execute([
                $p_doc_dt_val, $p_doc_type,
                $p_ac_code, $p_currency, $p_exchange_rate,
                $p_salesman_code,
                $p_contact_name, $p_contact_no, $p_contact_email,
                $p_subject, $p_project, $p_payment_terms, $p_delivery_terms, $p_delivery_loc,
                $p_narration,
                $p_qtn_no, $p_qtn_date, $p_po_no, $p_po_date, $p_do_no, $p_do_date,
                $p_job_no, $p_payment_days,
                $p_update_inv,
                $p_payment_mode, $p_discount_amount,
                $p_amount, $p_tax_amount, $p_tax1_amount,
                $user, $now,
                $company_code, $branch_code, $p_doc_no
            ]);
        } else {
            $p_doc_no  = getNextDocNo($con, $company_code, $branch_code, $si_save_doc_code, $p_doc_type);
            $p_doc_num = getNextDocNum($con, $company_code, $branch_code, $p_doc_type);
            $sql_insert = "INSERT INTO invoice_h (
                company_code, branch_code,
                doc_no, doc_num, doc_dt, doc_type,
                ac_code, currency_code, exchange_rate,
                salesman_code,
                contact_name, contact_no, contact_email,
                subject, project, payment_terms, delivery_terms, delivery_location,
                narration,
                user_id, user_date, last_userid, last_userdate,
                confirmed, approved, cancelled, despatch, copy_received,
                qtn_no, qtn_date, po_no, po_date, do_no, do_date,
                job_no, payment_days,
                update_inventory,
                pay_mode, discount_amount,
                amount, tax_amount, tax1_amount, tax_applied,
                discount_percent, incentive_amount, contract_amount, received_amount,
                discount1, discount2, discount3, bank_code, tax1_percent, entry_time
            ) VALUES (
                ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?,
                ?,
                ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?,
                ?, ?, ?, ?,
                'Y', 'Y', 'N', 'N', 'N',
                ?, ?, ?, ?, ?, ?,
                ?, ?,
                ?,
                ?, ?,
                ?, ?, ?, 'Y',
                0, 0, 0, 0,
                0, 0, 0, '0', 0, ?
            )";

            $stmt_ins = $con->prepare($sql_insert);
            $stmt_ins->execute([
                $company_code, $branch_code,
                $p_doc_no, $p_doc_num, $p_doc_dt_val, $p_doc_type,
                $p_ac_code, $p_currency, $p_exchange_rate,
                $p_salesman_code,
                $p_contact_name, $p_contact_no, $p_contact_email,
                $p_subject, $p_project, $p_payment_terms, $p_delivery_terms, $p_delivery_loc,
                $p_narration,
                $user, $now, $user, $now,
                $p_qtn_no, $p_qtn_date, $p_po_no, $p_po_date, $p_do_no, $p_do_date,
                $p_job_no, $p_payment_days,
                $p_update_inv,
                $p_payment_mode, $p_discount_amount,
                $p_amount, $p_tax_amount, $p_tax1_amount,
                date('Hi')
            ]);
        }

        // Save detail lines to invoice_d
        $detail_json = trim($_POST['detail_lines_json'] ?? '');
        if ($detail_json !== '') {
            $detail_lines = json_decode($detail_json, true);
            if (is_array($detail_lines) && !empty($detail_lines)) {
                $stmt_del = $con->prepare("DELETE FROM invoice_d WHERE company_code = ? AND branch_code = ? AND doc_no = ?");
                $stmt_del->execute([$company_code, $branch_code, $p_doc_no]);

                // Unit columns follow item_master: item_unit = other_unit (e.g. PCS),
                // trans_unit = the line's selling unit (e.g. CAS), upp = units per pack,
                // item_rate = retail_price * upp, item_qty = 0.
                $stmt_im = $con->prepare(
                    "SELECT TOP 1 stock_unit, other_unit, upp FROM item_master WHERE item_code = ?
                     ORDER BY CASE WHEN company_code = ? AND branch_code = ? THEN 0 ELSE 1 END"
                );
                $stmt_ip = $con->prepare(
                    "SELECT TOP 1 retail_price FROM item_price WHERE item_code = ?
                     ORDER BY CASE WHEN company_code = ? AND branch_code = ? THEN 0 ELSE 1 END"
                );

                $sql_d = "INSERT INTO invoice_d (
                            company_code, branch_code, doc_no,
                            serial_no, item_no,
                            item_code, item_description, item_unit, trans_unit, upp,
                            item_qty, trans_qty, item_rate, trans_rate,
                            ac_code, location_code, stock_qty,
                            tax1_percent, tax_amount, tax1_amount,
                            discount_percent, discount_amount,
                            job_no, remark, po_no, do_no, so_no, qtn_no
                          ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt_d = $con->prepare($sql_d);

                foreach ($detail_lines as $idx => $line) {
                    $serial_no = str_pad($idx + 1, 4, '0', STR_PAD_LEFT);
                    $item_no   = trim((string) ($line['line_no'] ?? ''));
                    if ($item_no === '') {
                        $item_no = (string) ($idx + 1);
                    }
                    $d_item_code = trim($line['item_code'] ?? '');
                    $d_unit      = trim($line['unit'] ?? '');

                    $stmt_im->execute([$d_item_code, $company_code, $branch_code]);
                    $im = $stmt_im->fetch(PDO::FETCH_ASSOC) ?: [];
                    $stmt_ip->execute([$d_item_code, $company_code, $branch_code]);
                    $d_retail = (float) ($stmt_ip->fetchColumn() ?: 0);

                    $d_upp        = (float) ($im['upp'] ?? 0) > 0 ? (float) $im['upp'] : 1;
                    $d_trans_unit = $d_unit !== '' ? $d_unit : trim((string) ($im['stock_unit'] ?? ''));
                    $d_item_unit  = trim((string) ($im['other_unit'] ?? ''));
                    if ($d_item_unit === '') {
                        $d_item_unit = $d_trans_unit;
                    }
                    $d_job_no = trim((string) ($line['job_no'] ?? ''));
                    $d_remark = trim((string) ($line['remark'] ?? ''));
                    $d_po_no  = trim((string) ($line['po_no'] ?? ''));
                    $d_do_no  = trim((string) ($line['do_no'] ?? ''));
                    $d_so_no  = trim((string) ($line['so_no'] ?? ''));
                    $d_qtn_no = trim((string) ($line['qtn_no'] ?? ''));

                    $stmt_d->execute([
                        $company_code,
                        $branch_code,
                        $p_doc_no,
                        $serial_no,
                        $item_no,
                        $d_item_code,
                        trim($line['item_desc']   ?? ''),
                        $d_item_unit !== '' ? $d_item_unit : null,
                        $d_trans_unit !== '' ? $d_trans_unit : null,
                        $d_upp,
                        0,
                        (float)($line['qty']      ?? 0),
                        $d_retail * $d_upp,
                        (float)($line['rate']     ?? 0),
                        trim($line['account']     ?? ''),
                        trim($line['location']    ?? ''),
                        (float)($line['stock']    ?? 0),
                        (float)($line['vat']      ?? 0),
                        (float)($line['tax_amount']  ?? 0),
                        (float)($line['tax1_amount'] ?? 0),
                        (float)($line['discount_pct'] ?? 0),
                        (float)($line['discount_amt'] ?? 0),
                        $d_job_no !== '' ? $d_job_no : null,
                        $d_remark !== '' ? $d_remark : null,
                        $d_po_no !== '' ? $d_po_no : null,
                        $d_do_no !== '' ? $d_do_no : null,
                        $d_so_no !== '' ? $d_so_no : null,
                        $d_qtn_no !== '' ? $d_qtn_no : null,
                    ]);
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

        // Compute PRIOR / NEXT doc for toolbar navigation after save
        $_save_prev_doc = '';
        $_save_next_doc = '';
        try {
            $stmt_sp = $con->prepare(
                "SELECT doc_no FROM invoice_h
                 WHERE company_code=? AND branch_code=? AND doc_type=?
                   AND doc_num < (SELECT doc_num FROM invoice_h WHERE doc_no=? AND company_code=? AND branch_code=?)
                 ORDER BY doc_num DESC OFFSET 0 ROWS FETCH NEXT 1 ROWS ONLY"
            );
            $stmt_sp->execute([$company_code, $branch_code, $p_doc_type, $p_doc_no, $company_code, $branch_code]);
            $r_sp = $stmt_sp->fetch(PDO::FETCH_ASSOC);
            if ($r_sp) $_save_prev_doc = trim($r_sp['doc_no']);
        } catch (Exception $e) { error_log('Prior nav error: ' . $e->getMessage()); }
        try {
            $stmt_sn = $con->prepare(
                "SELECT doc_no FROM invoice_h
                 WHERE company_code=? AND branch_code=? AND doc_type=?
                   AND doc_num > (SELECT doc_num FROM invoice_h WHERE doc_no=? AND company_code=? AND branch_code=?)
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
        error_log("Save invoice error: " . $e->getMessage());
        $save_error = "Save failed: " . $e->getMessage();
    }
}

// Doc types for this module: defined in doc_type_master AND enabled in document_setup
$target_code = isset($doc_code) && $doc_code !== ''
    ? $doc_code
    : (isset($_GET['code']) && ctype_alnum($_GET['code']) ? strtoupper(trim($_GET['code'])) : 'SI');
$si_doc_types = erp_doc_configured_types($con, $company_code, $branch_code, $target_code);

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
$selected_doc_type = isset($si_doc_types[$requested_doc_type])
    ? $requested_doc_type
    : erp_doc_default_type($con, $company_code, $branch_code, $target_code, $si_doc_types);
$selected_doc_name = $si_doc_types[$selected_doc_type] ?? $selected_doc_type;

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

    $sql = "SELECT doc_no FROM invoice_h WITH (UPDLOCK, HOLDLOCK)
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
        "SELECT ISNULL(MAX(doc_num), 0) FROM invoice_h WITH (UPDLOCK, HOLDLOCK)
         WHERE company_code = ? AND branch_code = ? AND doc_type = ?"
    );
    $stmt->execute([$company_code, $branch_code, $doc_type]);
    return intval($stmt->fetchColumn()) + 1;
}

// Defaults
$doc_no          = getNextDocNo($con, $company_code, $branch_code, $target_code, $selected_doc_type);
$doc_num         = 1;
try {
    $doc_num = getNextDocNum($con, $company_code, $branch_code, $selected_doc_type);
} catch (Exception $e) {
    error_log("doc_num fetch error: " . $e->getMessage());
}
$doc_dt          = date("Y-m-d");
$po_no           = '';
$po_date         = '';
$qtn_no          = '';
$qtn_date        = '';
$do_no           = '';
$do_date         = '';
$job_no_hdr      = '';
$payment_days    = '';
$payment_mode    = '';
$sales_type      = '';
$salesman        = '';
$subject         = '';
$project         = '';
$narration       = 'SALES INVOICE';
$remark          = '';
$driver          = '';
$vehicle_no      = '';
$prepared_by     = '';
$approved_by     = '';
$currency        = 'AED';
$currency_rate   = '1.000000000';
$tax_pct         = '';
$vat_pct         = '0';
$vat_amount      = '0.00';
$tax_amount      = '0.00';
$tax1_amount     = '0.00';
$total_lc        = '0.00';
$del_date        = '';

$ac_code         = '';
$customer_name   = '';
$telephone       = '';
$contact_name    = '';
$desig           = '';
$mobile          = '';
$email           = '';
$payment_terms   = '';
$delivery_terms  = '';
$delivery_loc    = '';
$amount          = '0.00';
$discount        = '0.00';
$advance         = '0.00';
$expense         = '0.00';
$incentive       = '0.00';

$is_view_mode    = false;
$entered_by_user = strtoupper($user);
$entered_by_date = date('d/m/Y H:i');
$modified_by_user = strtoupper($user);
$modified_by_date = $entered_by_date;

// Fetch logged-in user's salesman info via branch_user.user_id -> salesman.login_id
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
    error_log("Salesman fetch error: " . $e->getMessage());
}
$update_inventory = false;
try {
    $stmt_ct = $con->prepare("SELECT stk_inv FROM control_table WHERE company_code = ? AND branch_code = ?");
    $stmt_ct->execute([$company_code, $branch_code]);
    $row_ct = $stmt_ct->fetch(PDO::FETCH_ASSOC);
    if ($row_ct && strtoupper(trim($row_ct['stk_inv'] ?? '')) === 'Y') {
        $update_inventory = true;
    }
} catch (Exception $e) {
    error_log("Control table stk_inv fetch error: " . $e->getMessage());
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

// Fetch payment modes from pay_mode_master
$pay_mode_list = [];
try {
    $stmt_pm = $con->prepare("SELECT pay_mode_code, pay_mode_name FROM pay_mode_master  ORDER BY pay_mode_code ASC");
    $stmt_pm->execute([$company_code]);
    while ($row_pm = $stmt_pm->fetch(PDO::FETCH_ASSOC)) {
        $pay_mode_list[] = [
            'code' => trim($row_pm['pay_mode_code']),
            'name' => trim($row_pm['pay_mode_name'])
        ];
    }
} catch (Exception $e) {
    error_log("Pay mode fetch error: " . $e->getMessage());
}

// Load existing document
if (isset($_GET['doc_no']) && trim($_GET['doc_no']) !== '') {
    $search_doc = trim($_GET['doc_no']);
    $stmt_edit = $con->prepare(
        "SELECT ih.*,
                ISNULL(am.description, '') AS customer_name_db,
                ISNULL(s.salesman_name,  '') AS salesman_name_db
         FROM invoice_h ih
         LEFT JOIN ac_master am ON am.ac_code = ih.ac_code AND am.company_code = ih.company_code
         LEFT JOIN salesman   s  ON s.salesman_code = ih.salesman_code AND s.company_code = ih.company_code
         WHERE ih.doc_no = ? AND ih.company_code = ? AND ih.branch_code = ?"
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
        $salesman        = trim($row_edit['salesman_name_db'] ?? '');
        $contact_name    = trim($row_edit['contact_name'] ?? '');
        $mobile          = trim($row_edit['contact_no'] ?? '');
        $email           = trim($row_edit['contact_email'] ?? '');
        $telephone       = trim($row_edit['contact_email'] ?? ''); // email row label
        $subject         = trim($row_edit['subject'] ?? '');
        $project         = trim($row_edit['project'] ?? '');
        $narration       = trim($row_edit['narration'] ?? '');
        $payment_mode    = trim($row_edit['pay_mode'] ?? '');
        $payment_terms   = trim($row_edit['payment_terms'] ?? '');
        $delivery_terms  = trim($row_edit['delivery_terms'] ?? '');
        $delivery_loc    = trim($row_edit['delivery_location'] ?? '');
        $po_no           = trim($row_edit['po_no'] ?? '');
        $qtn_no          = trim($row_edit['qtn_no'] ?? '');
        $do_no           = trim($row_edit['do_no'] ?? '');
        $job_no_hdr      = trim($row_edit['job_no'] ?? '');
        $payment_days    = (string) intval($row_edit['payment_days'] ?? 0);
        $currency        = trim($row_edit['currency_code'] ?? 'AED');
        $currency_rate   = trim($row_edit['exchange_rate'] ?? '1.000000000');
        $driver          = trim($row_edit['driver'] ?? '');
        $vehicle_no      = trim($row_edit['vehicle_no'] ?? '');
        // Dates — format to Y-m-d for <input type="date">
        $fmt_date = function($d) { if (!$d) return ''; try { return (new DateTime($d))->format('Y-m-d'); } catch (Exception $e) { return $d; } };
        $doc_dt    = $fmt_date($row_edit['doc_dt']   ?? '');
        $po_date   = $fmt_date($row_edit['po_date']  ?? '');
        $qtn_date  = $fmt_date($row_edit['qtn_date'] ?? '');
        $do_date   = $fmt_date($row_edit['do_date']  ?? '');
        // Financial totals
        $amount     = number_format((float)($row_edit['amount']          ?? 0), 2, '.', '');
        $discount   = number_format((float)($row_edit['discount_amount'] ?? 0), 2, '.', '');
        $tax_amount = number_format((float)($row_edit['tax_amount']      ?? 0), 2, '.', '');
        $vat_amount = number_format((float)($row_edit['tax1_amount']     ?? 0), 2, '.', '');
        $total_lc   = number_format(
            (float)($row_edit['amount'] ?? 0)
            + (float)($row_edit['tax_amount'] ?? 0)
            + (float)($row_edit['tax1_amount'] ?? 0), 2, '.', '');
        // Update selected type from the loaded document
        if (!empty($row_edit['doc_type'])) {
            $selected_doc_type = strtoupper(trim($row_edit['doc_type']));
            $selected_doc_name = $si_doc_types[$selected_doc_type]
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
        $update_inventory = (strtoupper(trim($row_edit['update_inventory'] ?? '')) === 'Y');
    }
}

// Absolute URL path to this file — used for AJAX calls.
// Works correctly whether this file is accessed standalone or included by invoice.php.
$si_self_url = '/' . ltrim(
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
        <h1 class="doc-title-text">Sales Invoice</h1>
        <div class="mr-title-wrapper">
            <button type="button" class="doc-chip doc-chip-type" id="mrTitleBtn" onclick="toggleDocTypeMenu()" title="Click to switch document type">
                <span id="mrTitleText"><?php echo htmlspecialchars($selected_doc_name); ?></span>
                <i class="fa fa-caret-down"></i>
            </button>
            <div class="mr-doctype-menu" id="mrDocTypeMenu">
                <?php foreach ($si_doc_types as $dt_code => $dt_name): ?>
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
    <form id="siForm" class="doc-contents" method="POST" onkeydown="return event.key !== 'Enter' || event.target.tagName === 'TEXTAREA'">
        <input type="hidden" name="is_update" value="<?php echo $is_view_mode ? '1' : '0'; ?>">
        <input type="hidden" name="doc_type_selected" id="doc_type_selected_field" value="<?php echo htmlspecialchars($selected_doc_type); ?>">
        <input type="hidden" name="doc_num" value="<?php echo htmlspecialchars($doc_num ?? 0); ?>">
        <input type="hidden" name="update_inventory" id="updateInventoryHidden" value="<?php echo $update_inventory ? 'Y' : 'N'; ?>">
        <input type="hidden" name="detail_lines_json" id="detailLinesJson" value="">
        <button type="submit" name="save_si" value="1" style="display:none;">Save</button>
        <?php if (!empty($save_success) || !empty($save_error)): ?>
        <div class="erp-toasts" id="erpToasts">
            <?php if (!empty($save_success)): ?>
            <div class="erp-toast success" role="status" data-autohide="3000">
                <i class="fa fa-check-circle erp-toast-icon"></i>
                <div class="erp-toast-msg">Invoice <strong><?php echo htmlspecialchars($_GET['doc_no'] ?? ''); ?></strong> saved successfully.</div>
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
                        <label class="doc-lbl">Invoice No.</label>
                        <input type="text" name="doc_no" class="doc-inp" value="<?php echo htmlspecialchars($doc_no); ?>" readonly>
                    </div>
                    <div class="doc-field">
                        <label class="doc-lbl">Invoice Date</label>
                        <input type="date" name="doc_dt" class="doc-inp" value="<?php echo htmlspecialchars($doc_dt); ?>" <?php echo $is_view_mode ? 'disabled' : ''; ?>>
                    </div>
                    <div class="doc-field">
                        <label class="doc-lbl red">Pay Method</label>
                        <select name="payment_mode" class="doc-inp" <?php echo $is_view_mode ? 'disabled' : ''; ?>>
                            <option value="">-- Select --</option>
                            <?php foreach ($pay_mode_list as $_pm): ?>
                            <option value="<?php echo htmlspecialchars($_pm['code']); ?>" <?php echo $payment_mode === $_pm['code'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($_pm['code'] . ' - ' . $_pm['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="doc-field">
                        <label class="doc-lbl red">Sales Type</label>
                        <input type="text" name="sales_type" class="doc-inp" value="<?php echo htmlspecialchars($sales_type); ?>" <?php echo $is_view_mode ? 'readonly' : ''; ?>>
                    </div>
                </div>
            </div>

            <!-- ── Customer ── -->
            <div class="doc-section">
                <h4 class="doc-section-title">Customer</h4>
                <div class="doc-fields">
                    <div class="doc-field span-2 row-2">
                        <label class="doc-lbl">Customer</label>
                        <div class="doc-combo">
                            <input type="text" name="ac_code" id="acCodeInput" class="doc-inp doc-combo-code" value="<?php echo htmlspecialchars($ac_code); ?>" <?php echo $is_view_mode ? 'readonly' : ''; ?> placeholder="Code" onclick="<?php echo !$is_view_mode ? 'openCustomerSearch()' : ''; ?>">
                            <span class="doc-combo-sep">&middot;</span>
                            <input type="text" name="customer_name" class="doc-inp doc-combo-name" value="<?php echo htmlspecialchars($customer_name); ?>" placeholder="Search by code or name" readonly onclick="document.getElementById('acCodeInput').click()">
                            <i class="fa fa-search"></i>
                        </div>
                        <div class="doc-party-card" id="custCreditCard" style="<?php echo $ac_code === '' ? 'display:none;' : ''; ?>">
                            <div class="doc-party-name" id="custCardName"><?php echo htmlspecialchars($customer_name); ?></div>
                            <div class="doc-party-stat" id="custCrAmountBox">Credit limit<b id="custCrAmount">&mdash;</b></div>
                            <div class="doc-party-muted">Credit days: <span id="custCrDays">&mdash;</span></div>
                            <div class="doc-party-stat" id="custOutstandingBox">Outstanding<b id="custOutstanding">&mdash;</b></div>
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
                        <label class="doc-lbl">Email</label>
                        <input type="text" name="email" class="doc-inp" value="<?php echo htmlspecialchars($telephone); ?>" placeholder="name@company.com" <?php echo $is_view_mode ? 'readonly' : ''; ?>>
                    </div>
                    <div class="doc-field">
                        <label class="doc-lbl">Telephone</label>
                        <input type="text" name="telephone" class="doc-inp" value="<?php echo htmlspecialchars($email); ?>" <?php echo $is_view_mode ? 'readonly' : ''; ?>>
                    </div>
                </div>
            </div>

            <!-- ── Sales ── -->
            <div class="doc-section">
                <h4 class="doc-section-title">Sales</h4>
                <div class="doc-fields">
                    <div class="doc-field">
                        <label class="doc-lbl">Salesman</label>
                        <input type="hidden" name="salesman_code" value="<?php echo htmlspecialchars($salesman_code); ?>">
                        <input type="text" name="salesman" class="doc-inp" value="<?php echo htmlspecialchars($is_view_mode ? $salesman : $salesman_name); ?>" readonly>
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
                        </div>
                    </div>
                    <div class="doc-field">
                        <label class="doc-lbl">PO No.</label>
                        <input type="text" name="po_no" class="doc-inp" value="<?php echo htmlspecialchars($po_no); ?>" placeholder="Customer PO" <?php echo $is_view_mode ? 'readonly' : ''; ?>>
                    </div>
                    <div class="doc-field">
                        <label class="doc-lbl">PO Date</label>
                        <input type="date" name="po_date" class="doc-inp" value="<?php echo htmlspecialchars($po_date); ?>" <?php echo $is_view_mode ? 'disabled' : ''; ?>>
                    </div>
                    <div class="doc-field">
                        <label class="doc-lbl">Job No.</label>
                        <input type="text" name="job_no" id="siJobNoInput" class="doc-inp" value="<?php echo htmlspecialchars($job_no_hdr); ?>" <?php echo $is_view_mode ? 'readonly' : ''; ?> placeholder="From Job Card">
                    </div>
                    <div class="doc-field">
                        <label class="doc-lbl">Cr. Days</label>
                        <input type="text" name="payment_days" id="siPaymentDaysInput" class="doc-inp" value="<?php echo htmlspecialchars($payment_days); ?>" <?php echo $is_view_mode ? 'readonly' : ''; ?> placeholder="From Job Card">
                    </div>
                    <div class="doc-field span-2">
                        <label class="doc-lbl">Narration</label>
                        <div class="doc-inp-group">
                            <input type="text" name="narration" class="doc-inp" value="<?php echo htmlspecialchars($narration); ?>" <?php echo $is_view_mode ? 'readonly' : ''; ?>>
                            <button type="button" class="doc-more" onclick="openMoreModal('narration','Narration')">More..</button>
                        </div>
                    </div>
                    <div class="doc-field si-header-actions">
                        <label class="doc-lbl">Actions</label>
                        <div class="doc-inp-group si-action-btns">
                            <button type="button" class="doc-action-btn doc-action-terms" onclick="erpOpenVariableTerms()" title="Variable Terms"><i class="fa fa-file-text-o"></i> Variable Terms</button>
                            <button type="button" class="doc-action-btn doc-action-qtn" id="siSelectJobBtn" onclick="siOpenSelectJob()" title="Select Job Card" <?php echo $is_view_mode ? 'disabled' : ''; ?>><i class="fa fa-briefcase"></i> Select JOB</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── More details ── -->
            <button type="button" class="doc-expander" id="siMoreToggle" onclick="toggleMoreDetails()">
                <i class="fa fa-chevron-right"></i> More details
                <span class="doc-expander-hint">Delivery &middot; Terms &middot; References</span>
                <span class="doc-expander-count" id="siMoreCount"></span>
            </button>
            <div class="doc-more-panel" id="siMorePanel">
                <div class="doc-tabs">
                    <button type="button" class="doc-tab active" data-tab="siTabDelivery" onclick="selectMoreTab(this)">Delivery</button>
                    <button type="button" class="doc-tab" data-tab="siTabTerms" onclick="selectMoreTab(this)">Terms</button>
                    <button type="button" class="doc-tab" data-tab="siTabRefs" onclick="selectMoreTab(this)">References</button>
                </div>
                <div class="doc-tab-pane active" id="siTabDelivery">
                    <div class="doc-fields">
                        <div class="doc-field">
                            <label class="doc-lbl">D.O. No.</label>
                            <input type="text" name="do_no" class="doc-inp" value="<?php echo htmlspecialchars($do_no); ?>" <?php echo $is_view_mode ? 'readonly' : ''; ?>>
                        </div>
                        <div class="doc-field">
                            <label class="doc-lbl">D.O. Date</label>
                            <input type="date" name="do_date" class="doc-inp" value="<?php echo htmlspecialchars($do_date); ?>" <?php echo $is_view_mode ? 'disabled' : ''; ?>>
                        </div>
                        <div class="doc-field">
                            <label class="doc-lbl">Driver</label>
                            <input type="text" name="driver" class="doc-inp" value="<?php echo htmlspecialchars($driver); ?>" <?php echo $is_view_mode ? 'readonly' : ''; ?>>
                        </div>
                        <div class="doc-field">
                            <label class="doc-lbl">Vehicle No.</label>
                            <input type="text" name="vehicle_no" class="doc-inp" value="<?php echo htmlspecialchars($vehicle_no); ?>" <?php echo $is_view_mode ? 'readonly' : ''; ?>>
                        </div>
                        <div class="doc-field span-4">
                            <label class="doc-lbl">Delivery Location</label>
                            <div class="doc-inp-group">
                                <input type="text" name="delivery_loc" class="doc-inp" value="<?php echo htmlspecialchars($delivery_loc); ?>" <?php echo $is_view_mode ? 'readonly' : ''; ?>>
                                <button type="button" class="doc-more" onclick="openMoreModal('delivery_loc','Delivery Location')">More..</button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="doc-tab-pane" id="siTabTerms">
                    <div class="doc-fields">
                        <div class="doc-field span-2">
                            <label class="doc-lbl">Payment Terms</label>
                            <div class="doc-inp-group">
                                <input type="text" name="payment_terms" class="doc-inp" value="<?php echo htmlspecialchars($payment_terms); ?>" <?php echo $is_view_mode ? 'readonly' : ''; ?>>
                                <button type="button" class="doc-more" onclick="openMoreModal('payment_terms','Payment Terms')">More..</button>
                            </div>
                        </div>
                        <div class="doc-field span-2">
                            <label class="doc-lbl">Delivery Terms</label>
                            <div class="doc-inp-group">
                                <input type="text" name="delivery_terms" class="doc-inp" value="<?php echo htmlspecialchars($delivery_terms); ?>" <?php echo $is_view_mode ? 'readonly' : ''; ?>>
                                <button type="button" class="doc-more" onclick="openMoreModal('delivery_terms','Delivery Terms')">More..</button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="doc-tab-pane" id="siTabRefs">
                    <div class="doc-fields">
                        <div class="doc-field">
                            <label class="doc-lbl">QTN No.</label>
                            <input type="text" name="qtn_no" class="doc-inp" value="<?php echo htmlspecialchars($qtn_no); ?>" <?php echo $is_view_mode ? 'readonly' : ''; ?>>
                        </div>
                        <div class="doc-field">
                            <label class="doc-lbl">QTN Date</label>
                            <input type="date" name="qtn_date" class="doc-inp" value="<?php echo htmlspecialchars($qtn_date); ?>" <?php echo $is_view_mode ? 'disabled' : ''; ?>>
                        </div>
                        <div class="doc-field span-2">
                            <label class="doc-lbl">Contact Designation</label>
                            <input type="text" name="desig" class="doc-inp" value="<?php echo htmlspecialchars($desig); ?>" <?php echo $is_view_mode ? 'readonly' : ''; ?>>
                        </div>
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
            </div>

        </div><!-- /.doc-form-wrap -->

        <!-- ── Side panel ── -->
        <aside class="doc-page-aside">
            <section class="doc-panel">
                <h3 class="doc-section-title">Summary</h3>
                <div class="doc-sum-row">
                    <label class="doc-lbl">Apply Disc</label>
                    <div class="doc-sum-pair">
                        <input type="number" name="apply_discount_pct" id="applyDiscountPct" class="doc-inp doc-inp-pct" value="" step="0.01" min="0" max="100"
                               placeholder="%" <?php echo $is_view_mode ? 'readonly' : ''; ?>
                               onchange="siApplyHeaderDiscountPct()" onblur="siApplyHeaderDiscountPct()">
                        <input type="number" name="apply_discount_amt" id="applyDiscountAmt" class="doc-inp doc-inp-num" value="" step="0.01" min="0"
                               placeholder="Amt" <?php echo $is_view_mode ? 'readonly' : ''; ?>
                               onchange="siApplyHeaderDiscountAmt()" onblur="siApplyHeaderDiscountAmt()">
                    </div>
                </div>
                <div class="doc-sum-row">
                    <label class="doc-lbl">Apply VAT %</label>
                    <input type="number" name="apply_vat_pct" id="applyVatPct" class="doc-inp doc-inp-num" value="" step="0.01" min="0"
                           placeholder="All lines" <?php echo $is_view_mode ? 'readonly' : ''; ?>
                           onchange="siApplyHeaderVat()" onblur="siApplyHeaderVat()">
                </div>
                <div class="doc-sum-row">
                    <label class="doc-lbl">Amount</label>
                    <input type="text" name="amount" class="doc-inp doc-inp-num" value="<?php echo htmlspecialchars($amount); ?>" readonly>
                </div>
                <div class="doc-sum-row">
                    <label class="doc-lbl">Discount</label>
                    <input type="text" name="discount" class="doc-inp doc-inp-num" value="<?php echo htmlspecialchars($discount); ?>" readonly>
                </div>
                <div class="doc-sum-row">
                    <label class="doc-lbl">Advance</label>
                    <input type="text" name="advance" class="doc-inp doc-inp-num" value="<?php echo htmlspecialchars($advance); ?>" <?php echo $is_view_mode ? 'readonly' : ''; ?>>
                </div>
                <div class="doc-sum-row">
                    <label class="doc-lbl orange">Expense</label>
                    <input type="text" name="expense" class="doc-inp doc-inp-num" value="<?php echo htmlspecialchars($expense); ?>" <?php echo $is_view_mode ? 'readonly' : ''; ?>>
                </div>
                <div class="doc-sum-row">
                    <label class="doc-lbl">Incentive</label>
                    <input type="text" name="incentive" class="doc-inp doc-inp-num" value="<?php echo htmlspecialchars($incentive); ?>" <?php echo $is_view_mode ? 'readonly' : ''; ?>>
                </div>
                <div class="doc-sum-row">
                    <label class="doc-lbl">Excise Tax</label>
                    <div class="doc-sum-pair">
                        <input type="text" name="tax_pct" class="doc-inp doc-inp-pct" value="<?php echo htmlspecialchars($tax_pct); ?>" placeholder="%" readonly>
                        <input type="text" name="tax_amount" class="doc-inp doc-inp-num" value="<?php echo htmlspecialchars($tax_amount); ?>" placeholder="0.00" readonly>
                    </div>
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
                    <small id="siSumCurrency"><?php echo htmlspecialchars($currency); ?></small>
                    <input type="text" name="total_lc" class="doc-inp doc-inp-num" value="<?php echo htmlspecialchars($total_lc); ?>" readonly>
                </div>
                <div class="doc-sum-words" id="siSumWords"></div>
            </section>
            <?php include __DIR__ . '/../../../shared/doc_attachments_panel.php'; ?>
        </aside>

        <?php
        $vt_doc_no   = !empty($is_view_mode) ? (string) $doc_no : '';
        $vt_doc_type = $selected_doc_type ?? '';
        $vt_readonly = !empty($is_view_mode);
        include __DIR__ . '/../../../shared/variable_terms.php';
        ?>
    </form>

</div><!-- /.mr-container -->
</div><!-- /.container-fluid -->

<!-- Select JOB Modal (same UX as Job Card → Select QTN) -->
<div id="siJobModal" class="popup-overlay erp-search" style="display:none;">
    <div class="erp-search-dialog xl" role="dialog" aria-modal="true">
        <div class="erp-search-head">
            <h4 class="erp-search-title"><i class="fa fa-search"></i>Select Job Card</h4>
            <div class="erp-search-actions">
                <label class="erp-search-check">
                    <input type="checkbox" id="siJobAllBranch" checked onchange="siSearchJobDocs()"> All Branch
                </label>
                <button type="button" class="erp-search-btn primary sm" onclick="siAddFromJob()">Add</button>
                <button type="button" class="erp-search-close" onclick="siCloseSelectJob()" title="Close" aria-label="Close"><i class="fa fa-times"></i></button>
            </div>
        </div>
        <div class="erp-search-panes">
            <div class="erp-search-pane">
                <div class="erp-search-pane-head">
                    <div class="erp-search-input">
                        <i class="fa fa-search"></i>
                        <input type="text" id="siJobSearch" autocomplete="off" placeholder="Search Job Card No..." onkeyup="siSearchJobDocs()">
                    </div>
                </div>
                <div class="erp-search-body">
                    <table class="erp-search-table" id="siJobDocsTable">
                        <thead>
                            <tr>
                                <th>Job No</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody style="cursor:pointer;"></tbody>
                    </table>
                </div>
            </div>
            <div class="erp-search-pane wide">
                <div class="erp-search-pane-head">
                    <h6 class="erp-search-pane-title" id="siJobTitle">Select a Job Card document</h6>
                </div>
                <div class="erp-search-body">
                    <table class="erp-search-table" id="siJobItemsTable">
                        <thead>
                            <tr>
                                <th style="min-width:40px;">No</th>
                                <th style="min-width:90px;">Item Code</th>
                                <th style="min-width:160px;">Item Description</th>
                                <th style="min-width:70px;">Bal Qty</th>
                                <th style="min-width:50px;">Unit</th>
                                <th style="text-align:center;min-width:36px;"><input type="checkbox" id="siJobAll" onchange="siToggleAllJob(this)"></th>
                                <th style="min-width:100px;">Qty</th>
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
    // URL to this file for AJAX calls — set by PHP above, works standalone and via invoice.php container
    var _siHeaderSelf = '<?php echo htmlspecialchars($si_self_url); ?>';
    var siJobDoc = null;
    var siJobItems = [];
    var siJobHeader = null;
    var siJobTimer = null;

    let customerSearchTimeout;
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

    function openCustomerSearch() {
        const modal = document.getElementById('customerSearchModal');
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
        const input = document.getElementById('customerSearchInput');
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
        customerSearchTimeout = setTimeout(() => {
            fetch(_siHeaderSelf + '?action=search_customers&term=' + encodeURIComponent(query))
                .then(response => {
                    if (!response.ok) throw new Error('Server returned ' + response.status);
                    return response.json();
                })
                .then(data => {
                    const list = document.getElementById('customerSearchResults');
                    const count = document.getElementById('customerSearchCount');
                    customerSearchRows = Array.isArray(data) ? data : [];
                    count.textContent = customerSearchRows.length + (customerSearchRows.length === 1 ? ' customer' : ' customers');
                    if (customerSearchRows.length === 0) {
                        list.innerHTML = '<div class="erp-search-state">No customers found</div>';
                        return;
                    }
                    list.innerHTML = customerSearchRows.map((customer, i) => {
                        const contact = [];
                        if (customer.contact_name) contact.push('<i class="fa fa-user"></i>' + htmlEscape(customer.contact_name));
                        if (customer.contact_no) contact.push('<i class="fa fa-phone"></i>' + htmlEscape(customer.contact_no));
                        return `
                            <div class="erp-search-row">
                                <span class="erp-search-ico"><i class="fa fa-building-o"></i></span>
                                <div class="erp-search-main">
                                    <div class="erp-search-name">${htmlEscape(customer.description)}</div>
                                    <div class="erp-search-sub">${htmlEscape(customer.ac_code)}</div>
                                    ${contact.length ? '<div class="erp-search-extra">' + contact.join(' &middot; ') + '</div>' : ''}
                                </div>
                                <button type="button" class="erp-search-open" onclick="selectCustomerRow(${i})">
                                    Select <i class="fa fa-check"></i>
                                </button>
                            </div>`;
                    }).join('');
                })
                .catch(err => {
                    console.error('Error:', err);
                    document.getElementById('customerSearchCount').textContent = '';
                    document.getElementById('customerSearchResults').innerHTML = '<div class="erp-search-state error">Error loading customers</div>';
                });
        }, 300);
    }

    let customerSearchRows = [];
    function selectCustomerRow(i) {
        const c = customerSearchRows[i];
        if (c) selectCustomer(c.ac_code, c.description, c.contact_name, c.contact_no, c.contact_email);
    }

    function selectCustomer(ac_code, description, contact_name, contact_no, contact_email) {
        document.getElementById('acCodeInput').value = ac_code;
        document.querySelector('input[name="customer_name"]').value = description;
        document.querySelector('input[name="contact_name"]').value = contact_name || '';
        document.querySelector('input[name="mobile"]').value = contact_no || '';
        document.querySelector('input[name="email"]').value = contact_email || '';
        var cardName = document.getElementById('custCardName');
        if (cardName) cardName.textContent = description;
        closeCustomerSearch();
        loadCustomerCredit(ac_code);
    }

    function siRound2(n) {
        return Math.round((Number(n) + Number.EPSILON) * 100) / 100;
    }
    function siDetailRows() {
        return Array.prototype.slice.call(
            document.querySelectorAll('#detailTableBody tr.detail-row, .si02-lines .detail-row, #detailTableBody.si02-lines .detail-row')
        );
    }
    function siLineGross(row) {
        var qty = parseFloat((row.querySelector('[name="qty[]"]') || {}).value) || 0;
        var rate = parseFloat((row.querySelector('[name="rate[]"]') || {}).value) || 0;
        return siRound2(qty * rate);
    }
    // Apply same % to every line (clears header discount amount)
    function siApplyHeaderDiscountPct() {
        var el = document.getElementById('applyDiscountPct');
        var amtEl = document.getElementById('applyDiscountAmt');
        if (!el || el.readOnly || el.disabled) return;
        var raw = String(el.value || '').trim();
        if (raw === '') return;
        if (amtEl) amtEl.value = '';
        var pct = Math.min(100, Math.max(0, parseFloat(raw) || 0));
        el.value = pct.toFixed(2);
        siDetailRows().forEach(function (row) {
            var pctInp = row.querySelector('[name="discount_pct[]"]');
            var amtInp = row.querySelector('[name="discount_amt[]"]');
            if (!pctInp || pctInp.readOnly || pctInp.disabled) return;
            pctInp.value = pct.toFixed(2);
            if (amtInp && !amtInp.readOnly) amtInp.value = '0.00';
        });
        if (typeof updateSummary === 'function') updateSummary();
    }
    // Distribute header discount amount proportionally by line Amount
    // Line Discount = (Line Amount / Total Detail Amount) × Header Discount Amount
    function siDistributeHeaderDiscountAmt() {
        var el = document.getElementById('applyDiscountAmt');
        if (!el) return false;
        var raw = String(el.value || '').trim();
        if (raw === '') return false;

        var headerDisc = Math.max(0, parseFloat(raw) || 0);
        el.value = headerDisc.toFixed(2);

        var rows = siDetailRows().filter(function (row) {
            var amtInp = row.querySelector('[name="discount_amt[]"]');
            return amtInp && !amtInp.readOnly && !amtInp.disabled;
        });
        if (!rows.length) return true;

        var amounts = rows.map(siLineGross);
        var totalAmt = amounts.reduce(function (s, n) { return s + n; }, 0);

        if (totalAmt <= 0 || headerDisc <= 0) {
            rows.forEach(function (row) {
                var pctInp = row.querySelector('[name="discount_pct[]"]');
                var amtInp = row.querySelector('[name="discount_amt[]"]');
                if (pctInp) pctInp.value = '0.00';
                if (amtInp) amtInp.value = '0.00';
            });
            return true;
        }

        if (headerDisc > totalAmt) {
            headerDisc = totalAmt;
            el.value = headerDisc.toFixed(2);
        }

        var allocated = 0;
        var lastPos = -1;
        for (var i = 0; i < rows.length; i++) {
            var pctInp = rows[i].querySelector('[name="discount_pct[]"]');
            var amtInp = rows[i].querySelector('[name="discount_amt[]"]');
            if (amounts[i] <= 0) {
                if (pctInp) pctInp.value = '0.00';
                if (amtInp) amtInp.value = '0.00';
                continue;
            }
            lastPos = i;
            var share = siRound2((amounts[i] / totalAmt) * headerDisc);
            if (share > amounts[i]) share = amounts[i];
            allocated = siRound2(allocated + share);
            if (pctInp) pctInp.value = '0.00';
            if (amtInp) amtInp.value = share.toFixed(2);
        }

        if (lastPos >= 0) {
            var diff = siRound2(headerDisc - allocated);
            if (diff !== 0) {
                var lastAmt = rows[lastPos].querySelector('[name="discount_amt[]"]');
                var fixed = siRound2((parseFloat(lastAmt.value) || 0) + diff);
                if (fixed < 0) fixed = 0;
                if (fixed > amounts[lastPos]) fixed = amounts[lastPos];
                lastAmt.value = fixed.toFixed(2);
            }
        }
        return true;
    }
    function siApplyHeaderDiscountAmt() {
        var el = document.getElementById('applyDiscountAmt');
        var pctEl = document.getElementById('applyDiscountPct');
        if (!el || el.readOnly || el.disabled) return;
        var raw = String(el.value || '').trim();
        if (raw === '') return;
        if (pctEl) pctEl.value = '';
        if (!siDistributeHeaderDiscountAmt()) return;
        siDetailRows().forEach(function (row) {
            if (typeof recalcRow === 'function') recalcRow(row, 'discount_amt[]');
        });
        if (typeof updateSummary === 'function') updateSummary();
    }
    (function siWrapUpdateSummaryForHdrDisc() {
        function install() {
            if (typeof window.updateSummary !== 'function' || window.updateSummary.__siHdrDiscWrapped) return;
            var orig = window.updateSummary;
            window.updateSummary = function () {
                if (!window.__siHdrDiscRedistributing) {
                    var amtEl = document.getElementById('applyDiscountAmt');
                    if (amtEl && !amtEl.readOnly && !amtEl.disabled && String(amtEl.value || '').trim() !== '') {
                        window.__siHdrDiscRedistributing = true;
                        try { siDistributeHeaderDiscountAmt(); } finally { window.__siHdrDiscRedistributing = false; }
                    }
                }
                return orig.apply(this, arguments);
            };
            window.updateSummary.__siHdrDiscWrapped = true;
        }
        document.addEventListener('DOMContentLoaded', install);
        setTimeout(install, 0);
    })();
    function siApplyHeaderVat() {
        var el = document.getElementById('applyVatPct');
        if (!el || el.readOnly || el.disabled) return;
        var raw = String(el.value || '').trim();
        if (raw === '') return;
        var pct = Math.max(0, parseFloat(raw) || 0);
        el.value = pct.toFixed(2);
        document.querySelectorAll('#detailTableBody [name="vat[]"], .si02-lines [name="vat[]"]').forEach(function (inp) {
            if (inp.readOnly || inp.disabled) return;
            inp.value = (inp.type === 'hidden') ? String(pct) : pct.toFixed(2);
        });
        if (typeof updateSummary === 'function') updateSummary();
    }

    // ---- More details panel ----
    function setMoreDetailsOpen(open) {
        document.getElementById('siMoreToggle').classList.toggle('open', open);
        document.getElementById('siMorePanel').classList.toggle('open', open);
    }
    function toggleMoreDetails() {
        var open = !document.getElementById('siMorePanel').classList.contains('open');
        try { localStorage.setItem('erpSiMoreOpen', open ? '1' : '0'); } catch (e) {}
        setMoreDetailsOpen(open);
    }
    function selectMoreTab(tab) {
        document.querySelectorAll('#siMorePanel .doc-tab, #siMorePanel .doc-tab-pane').forEach(function (el) {
            el.classList.remove('active');
        });
        tab.classList.add('active');
        document.getElementById(tab.getAttribute('data-tab')).classList.add('active');
    }
    function updateMoreCount() {
        var filled = 0;
        document.querySelectorAll('#siMorePanel input:not([type="hidden"])').forEach(function (el) {
            if (el.value.trim() !== '') filled++;
        });
        var el = document.getElementById('siMoreCount');
        if (el) el.textContent = filled ? filled + ' filled' : '';
    }
    document.addEventListener('DOMContentLoaded', function () {
        var moreOpen = false;
        try { moreOpen = localStorage.getItem('erpSiMoreOpen') === '1'; } catch (e) {}
        setMoreDetailsOpen(moreOpen);
        updateMoreCount();
        var panel = document.getElementById('siMorePanel');
        if (panel) panel.addEventListener('input', updateMoreCount);
    });

    // ---- Customer credit card ----
    // Fetched results are kept per customer in sessionStorage (survives the save post-back),
    // so the page re-rendered after a save shows the same figures without a new request.
    var _custCreditReq = 0;
    var _custCreditPending = {};
    var CUST_CREDIT_CACHE_KEY = 'erpSiCustCredit:' + <?php echo json_encode($company_code); ?>;
    var CUST_CREDIT_AFTER_SAVE = <?php echo !empty($save_success) ? 'true' : 'false'; ?>;
    function fmtCreditAmount(n) {
        return Number(n || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function custCreditCacheAll() {
        try { return JSON.parse(sessionStorage.getItem(CUST_CREDIT_CACHE_KEY) || '{}') || {}; } catch (e) { return {}; }
    }
    function custCreditCacheSave(all) {
        try { sessionStorage.setItem(CUST_CREDIT_CACHE_KEY, JSON.stringify(all)); } catch (e) {}
    }
    function custCreditCacheGet(acCode) {
        var entry = custCreditCacheAll()[acCode];
        return entry && entry.data ? entry.data : null;
    }
    function custCreditCacheSet(acCode, data) {
        var all = custCreditCacheAll();
        all[acCode] = { t: Date.now(), data: data };
        custCreditCacheSave(all);
    }
    // Drop the cached figures of one customer (or all) when its credit / balance has been changed
    function invalidateCustomerCredit(acCode) {
        var all = custCreditCacheAll();
        if (acCode) delete all[String(acCode).trim()]; else all = {};
        custCreditCacheSave(all);
    }
    window.erpInvalidateCustomerCredit = invalidateCustomerCredit;

    // One request per customer at a time
    function fetchCustomerCredit(acCode) {
        if (_custCreditPending[acCode]) return _custCreditPending[acCode];
        var req = fetch(_siHeaderSelf + '?action=customer_credit&ac_code=' + encodeURIComponent(acCode))
            .then(function (r) {
                if (!r.ok) throw new Error('Server returned ' + r.status);
                return r.json();
            })
            .then(function (d) {
                if (d && !d.error) custCreditCacheSet(acCode, d);
                return d;
            });
        _custCreditPending[acCode] = req;
        var done = function () { delete _custCreditPending[acCode]; };
        req.then(done, done);
        return req;
    }

    function showCustomerCredit(d) {
        var card    = document.getElementById('custCreditCard');
        var elLimit = document.getElementById('custCrAmount');
        var elDays  = document.getElementById('custCrDays');
        var elOut   = document.getElementById('custOutstanding');
        var boxOut  = document.getElementById('custOutstandingBox');
        card.classList.remove('loading');
        boxOut.classList.remove('warn', 'over');
        var limit = Number(d.cr_amount) || 0;
        var out   = Number(d.outstanding) || 0;
        elLimit.textContent = fmtCreditAmount(limit);
        elDays.textContent  = Number(d.cr_days || 0).toLocaleString('en-US') + ' days';
        elOut.textContent   = fmtCreditAmount(out);
        if (limit > 0 && out > limit) {
            boxOut.classList.add('over');
        } else if (limit > 0 && out > limit * 0.8) {
            boxOut.classList.add('warn');
        }
    }

    // useCache: show the cached figures of this customer instead of fetching them again
    function loadCustomerCredit(acCode, useCache) {
        var card = document.getElementById('custCreditCard');
        if (!card) return;
        acCode = (acCode || '').trim();
        if (acCode === '') {
            card.style.display = 'none';
            return;
        }
        var elLimit = document.getElementById('custCrAmount');
        var elDays  = document.getElementById('custCrDays');
        var elOut   = document.getElementById('custOutstanding');
        var boxOut  = document.getElementById('custOutstandingBox');
        card.style.display = '';

        var reqId = ++_custCreditReq;
        var cached = useCache ? custCreditCacheGet(acCode) : null;
        if (cached) {
            showCustomerCredit(cached);
            return;
        }

        card.classList.add('loading');
        boxOut.classList.remove('warn', 'over');
        elLimit.textContent = elDays.textContent = elOut.textContent = '…';

        fetchCustomerCredit(acCode)
            .then(function (d) {
                if (reqId !== _custCreditReq) return;
                showCustomerCredit(d);
            })
            .catch(function (err) {
                if (reqId !== _custCreditReq) return;
                console.error('Customer credit error:', err);
                card.classList.remove('loading');
                elLimit.textContent = elDays.textContent = elOut.textContent = '—';
            });
    }
    // Page re-rendered after a save: keep the figures already shown for this customer
    document.addEventListener('DOMContentLoaded', function () {
        var acEl = document.getElementById('acCodeInput');
        if (acEl && acEl.value.trim() !== '') loadCustomerCredit(acEl.value, CUST_CREDIT_AFTER_SAVE);
    });

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

    // ---- Currency Dropdown ----
    function onCurrencyChange(sel) {
        const opt = sel.options[sel.selectedIndex];
        document.getElementById('currencyCode').value = opt.value;
        document.getElementById('currencyRate').value = opt.getAttribute('data-rate') || '';
        var curLbl = document.getElementById('siSumCurrency');
        if (curLbl) curLbl.textContent = opt.value;
    }

    // Sync rate on page load from the selected dropdown option
    document.addEventListener('DOMContentLoaded', function () {
        const sel = document.getElementById('currencySelect');
        if (sel) { onCurrencyChange(sel); }
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

    // ── Select JOB ──────────────────────────────────────────────────────────
    function siJobPost(params) {
        var fd = new FormData();
        Object.keys(params).forEach(function (k) { fd.append(k, params[k]); });
        return fetch(_siHeaderSelf, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) {
                if (!r.ok) throw new Error('Server returned ' + r.status);
                return r.json();
            });
    }
    function siJobEsc(v) {
        return String(v == null ? '' : v)
            .replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;')
            .replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }
    function siOpenSelectJob() {
        var ac = String((document.getElementById('acCodeInput') || {}).value || '').trim();
        if (!ac) {
            alert('Please select a Customer before choosing a Job Card.');
            return;
        }
        siJobDoc = null;
        siJobItems = [];
        siJobHeader = null;
        document.getElementById('siJobSearch').value = '';
        document.getElementById('siJobTitle').textContent = 'Select a Job Card document';
        document.getElementById('siJobAll').checked = false;
        document.querySelector('#siJobItemsTable tbody').innerHTML =
            '<tr><td colspan="7" class="text-center text-muted p-3">Select a document from the left panel</td></tr>';
        document.getElementById('siJobModal').style.display = 'flex';
        document.body.style.overflow = 'hidden';
        siSearchJobDocs();
    }
    function siCloseSelectJob() {
        document.getElementById('siJobModal').style.display = 'none';
        document.body.style.overflow = '';
    }
    function siSearchJobDocs() {
        clearTimeout(siJobTimer);
        siJobTimer = setTimeout(function () {
            var tbody = document.querySelector('#siJobDocsTable tbody');
            var ac = String((document.getElementById('acCodeInput') || {}).value || '').trim();
            tbody.innerHTML = '<tr><td colspan="2" class="text-center p-3">Loading...</td></tr>';
            siJobPost({
                action: 'si_pending_jobs',
                ac_code: ac,
                term: document.getElementById('siJobSearch').value,
                all_branch: document.getElementById('siJobAllBranch').checked ? '1' : '0'
            }).then(function (docs) {
                tbody.innerHTML = '';
                if (docs && docs.error) {
                    tbody.innerHTML = '<tr><td colspan="2" class="text-center p-3 text-danger">' + siJobEsc(docs.error) + '</td></tr>';
                    return;
                }
                if (!Array.isArray(docs) || !docs.length) {
                    tbody.innerHTML = '<tr><td colspan="2" class="text-center p-3">No pending job cards for this customer</td></tr>';
                    return;
                }
                docs.forEach(function (doc) {
                    var tr = document.createElement('tr');
                    tr.innerHTML = '<td>' + siJobEsc(doc.doc_no) + '</td><td>' + siJobEsc(doc.doc_dt) + '</td>';
                    tr.onclick = function () { siSelectJobDoc(doc, tr); };
                    tbody.appendChild(tr);
                });
            }).catch(function (err) {
                console.error('Pending JOB error:', err);
                tbody.innerHTML = '<tr><td colspan="2" class="text-center p-3 text-danger">Network or parsing error</td></tr>';
            });
        }, 300);
    }
    function siSelectJobDoc(doc, row) {
        siJobDoc = doc;
        document.querySelectorAll('#siJobDocsTable tbody tr').forEach(function (r) {
            var on = (r === row);
            r.style.backgroundColor = on ? 'var(--primary)' : '';
            r.style.color = on ? 'white' : '';
        });
        document.getElementById('siJobTitle').textContent = 'Job Card ' + doc.doc_no;
        document.getElementById('siJobAll').checked = false;
        var tbody = document.querySelector('#siJobItemsTable tbody');
        tbody.innerHTML = '<tr><td colspan="7" class="text-center p-3">Loading...</td></tr>';
        siJobPost({
            action: 'si_pending_job_items',
            doc_no: doc.doc_no,
            all_branch: document.getElementById('siJobAllBranch').checked ? '1' : '0',
            branch: document.getElementById('siJobAllBranch').checked ? '%' : <?php echo json_encode($branch_code); ?>
        }).then(function (payload) {
            tbody.innerHTML = '';
            if (payload && payload.error) {
                tbody.innerHTML = '<tr><td colspan="7" class="text-center p-3 text-danger">' + siJobEsc(payload.error) + '</td></tr>';
                return;
            }
            if (Array.isArray(payload)) {
                siJobHeader = null;
                siJobItems = payload;
            } else {
                siJobHeader = (payload && payload.header) ? payload.header : null;
                siJobItems = (payload && Array.isArray(payload.items)) ? payload.items : [];
            }
            if (!siJobItems.length) {
                tbody.innerHTML = '<tr><td colspan="7" class="text-center p-3">No pending items for this job card</td></tr>';
                return;
            }
            siJobItems.forEach(function (item, i) {
                var tr = document.createElement('tr');
                tr.innerHTML = '<td>' + siJobEsc(item.item_no) + '</td>'
                    + '<td>' + siJobEsc(item.item_code) + '</td>'
                    + '<td>' + siJobEsc(item.item_name) + '</td>'
                    + '<td class="text-right">' + Number(item.balance || 0).toFixed(2) + '</td>'
                    + '<td>' + siJobEsc(item.unit) + '</td>'
                    + '<td class="text-center"><input type="checkbox" class="si-job-chk" value="' + i + '" onchange="siJobCheck(this)"></td>'
                    + '<td style="padding:4px;"><input type="number" class="form-input-custom si-job-qty text-center" value="0.00" min="0" step="0.01" style="width:90px;padding:2px;"></td>';
                tbody.appendChild(tr);
            });
        }).catch(function (err) {
            console.error('Pending JOB items error:', err);
            tbody.innerHTML = '<tr><td colspan="7" class="text-center p-3 text-danger">Network or parsing error</td></tr>';
        });
    }
    function siJobCheck(chk) {
        var qty = chk.closest('tr').querySelector('.si-job-qty');
        if (chk.checked && !(parseFloat(qty.value) > 0)) {
            qty.value = Number(siJobItems[chk.value].balance || 0).toFixed(2);
        }
    }
    function siToggleAllJob(src) {
        document.querySelectorAll('.si-job-chk').forEach(function (chk) {
            chk.checked = src.checked;
            siJobCheck(chk);
        });
    }
    function siApplyJobHeader(hdr) {
        if (!hdr || typeof hdr !== 'object') return;
        var jobEl = document.getElementById('siJobNoInput') || document.querySelector('input[name="job_no"]');
        if (jobEl && (hdr.job_no || (siJobDoc && siJobDoc.doc_no))) {
            jobEl.value = hdr.job_no || siJobDoc.doc_no;
        }
        var subj = document.querySelector('input[name="subject"]');
        if (subj && hdr.subject != null && String(hdr.subject).trim() !== '') {
            subj.value = String(hdr.subject);
        }
        var qtn = document.querySelector('input[name="qtn_no"]');
        if (qtn && hdr.qtn_no != null && String(hdr.qtn_no).trim() !== '') {
            qtn.value = String(hdr.qtn_no);
        }
        var days = document.getElementById('siPaymentDaysInput') || document.querySelector('input[name="payment_days"]');
        if (days && hdr.payment_days != null && hdr.payment_days !== '') {
            days.value = hdr.payment_days;
        }
    }
    function siAddFromJob() {
        var picked = [];
        var bad = '';
        var hdrQtn = (siJobHeader && siJobHeader.qtn_no) ? siJobHeader.qtn_no : '';
        document.querySelectorAll('.si-job-chk:checked').forEach(function (chk) {
            var item = siJobItems[chk.value];
            var qty = parseFloat(chk.closest('tr').querySelector('.si-job-qty').value) || 0;
            if ((qty <= 0 || qty > item.balance + 0.0001) && !bad) {
                bad = (item.item_code || item.item_no) + ': enter a quantity between 0 and ' + Number(item.balance || 0).toFixed(2) + '.';
            }
            picked.push({
                item_code: item.item_code || '',
                item_desc: item.item_name || '',
                item_no: item.item_no || '',
                unit: item.unit || '',
                qty: qty,
                rate: item.rate || 0,
                vat: item.vat || 0,
                remark: item.remark || '',
                qtn_no: item.qtn_no || hdrQtn || '',
                job_no: item.doc_no || (siJobDoc ? siJobDoc.doc_no : '')
            });
        });
        if (!picked.length) { alert('Please select at least one item'); return; }
        if (bad) { alert(bad); return; }
        siApplyJobHeader(siJobHeader || { job_no: siJobDoc ? siJobDoc.doc_no : '' });
        if (typeof siApplyJobLines === 'function') {
            siApplyJobLines(picked);
        } else {
            alert('Detail screen is not ready to receive job card lines.');
            return;
        }
        siCloseSelectJob();
    }

    // SAVE — submit the header form
    window.erpToolbar.onSave = function () {
        // Collect detail lines from the detail fragment before submitting
        if (typeof getDetailLinesJson === 'function') {
            var jsonField = document.getElementById('detailLinesJson');
            if (jsonField) jsonField.value = getDetailLinesJson();
        }
        if (typeof getVariableTermsJson === 'function') {
            var vtField = document.getElementById('variableTermsJson');
            if (vtField) vtField.value = getVariableTermsJson();
        }
        var btn = document.querySelector('[name="save_si"]');
        if (btn) {
            btn.click();
            return;
        }
        var form = document.getElementById('siForm');
        if (form) {
            form.submit();
        }
    };

    // NEW — open a blank document of the same type
    window.erpToolbar.onNew = function () {
        window.location.href = erpNavUrl('doc_type=<?php echo urlencode($selected_doc_type); ?>');
    };

    // PREVIEW — print preview of the saved document (print format from Document Setup)
    window.erpToolbar.onPreview = function () {
        erpOpenPrintPreview(<?php echo json_encode($target_code); ?>, <?php echo json_encode($selected_doc_type); ?>, <?php echo json_encode($is_view_mode ? $doc_no : ''); ?>);
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
    // Unlocks all readonly/disabled fields in the header form AND the detail table.
    window.erpToolbar.onEditVerified = function () {
        // Unlock header form
        document.querySelectorAll('#siForm [readonly]').forEach(function (el) {
            el.removeAttribute('readonly');
        });
        document.querySelectorAll('#siForm [disabled]').forEach(function (el) {
            if (el.name !== 'doc_no') el.removeAttribute('disabled');
        });
        // Re-lock fields that must never be editable by the user
        ['doc_no','salesman','customer_name','amount','discount','tax_pct','tax_amount','vat_pct','vat_amount','total_lc'].forEach(function(n) {
            var el = document.querySelector('#siForm [name="' + n + '"]');
            if (el) el.setAttribute('readonly', '');
        });
        // Enable customer search popup on ac_code in edit mode
        var acCodeEl = document.getElementById('acCodeInput');
        if (acCodeEl) {
            acCodeEl.setAttribute('onclick', 'openCustomerSearch()');
            acCodeEl.style.cursor = 'pointer';
        }
        var jobBtn = document.getElementById('siSelectJobBtn');
        if (jobBtn) jobBtn.disabled = false;
        if (typeof erpSetVariableTermsReadonly === 'function') {
            erpSetVariableTermsReadonly(false);
        }
        // Unlock editable detail-line inputs (account[] stays readonly)
        ['qty[]','rate[]','excise_rate[]','discount_pct[]','discount_amt[]','item_code[]'].forEach(function(n) {
            document.querySelectorAll('#detailTableBody input[name="' + n + '"]').forEach(function(el) {
                el.removeAttribute('readonly');
                if (n === 'item_code[]') {
                    el.setAttribute('onfocus', 'openItemSearch(this)');
                    el.setAttribute('onclick', 'openItemSearch(this)');
                }
            });
        });
        // Show delete buttons per row and re-enable the detail toolbar buttons
        document.querySelectorAll('#detailTableBody .btn-danger, #detailTableBody .si02-del').forEach(function(btn) { btn.style.display = ''; });
        document.querySelectorAll('.detail-toolbar .detail-btn').forEach(function(btn) { btn.disabled = false; btn.style.removeProperty('opacity'); });
        if (typeof erpToolbarSetState === 'function') {
            erpToolbarSetState({ save: true, edit: false, cancel: true });
        }
    };

    // DELETE — toolbar gate (allow_delete=Y) then remove detail + header
    window.erpToolbar.onDeleteConfirmed = function () {
        var docNo = String((document.querySelector('input[name="doc_no"]') || {}).value || '').trim();
        if (!docNo) {
            alert('Document number is missing.');
            return;
        }
        var fd = new FormData();
        fd.append('action', 'si_delete_document');
        fd.append('doc_no', docNo);
        fetch(_siHeaderSelf, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data && data.success) {
                    alert('Document deleted.');
                    window.location.href = erpNavUrl('doc_type=' + encodeURIComponent(window.ERP_DOC_TYPE || ''));
                    return;
                }
                alert((data && data.error) ? data.error : 'Delete failed.');
            })
            .catch(function (err) {
                console.error('SI delete error:', err);
                alert('Delete failed. Please try again.');
            });
    };

    // CANCEL — discard unsaved changes by reloading the page
    window.erpToolbar.onCancel = function () {
        window.location.reload();
    };

    // Update the toolbar's nav constants now that the fragment knows the current doc
    // (The toolbar PHP vars are already correct when loaded via invoice.php container,
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
