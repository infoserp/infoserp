<?php
/**
 * Reports — Ledger Report-Single
 * Menu: user_menu.menu_code = m_ledgersingle
 *       (Reports → Financial Report → Ledger Report-Single).
 *
 * Filters: Date From / Date To, account search (ac_master), All Branch.
 * Confidential: always show non-confidential; confidential only when
 * branch_user.show_confidential_ac = 'Y'.
 * All Branch: only when branch_user.allow_allbranch = 'Y'.
 */
ob_start();
require __DIR__ . '/../../db.php';
include __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../includes/erp_paths.php';
date_default_timezone_set('Asia/Dubai');

$ls_ajax = strtolower(trim((string) ($_GET['action'] ?? $_POST['action'] ?? '')));
$ls_is_ajax = in_array($ls_ajax, ['search_accounts', 'run_report'], true);
if (!$ls_is_ajax) {
    erp_guard_standalone_get();
}

$pdo = isset($con) ? $con : (isset($conn) ? $conn : null);
if (!$pdo) {
    die('Database connection not available');
}

if (($_SESSION['LS'] ?? 'N') !== 'Y') {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code(403);
    if ($ls_is_ajax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Access denied.']);
        exit;
    }
    echo 'Access denied. Enable menu m_ledgersingle (Reports → Financial Report → Ledger Report-Single) for this user.';
    exit;
}

$user_type = isset($_SESSION['user_type']) ? trim((string) $_SESSION['user_type']) : '';
if ($user_type !== 'A' && $user_type !== 'U') {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if ($ls_is_ajax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
        exit;
    }
    header('Location: ' . erp_url('login'));
    exit;
}

$user_id      = (string) ($_SESSION['username'] ?? '');
$company_code = (string) ($_SESSION['company_code'] ?? '');
$branch_code  = (string) ($_SESSION['branch_code'] ?? '');
$self_url     = erp_iframe_url('modules/Report/ledger_report_signle');

/* ── helpers ─────────────────────────────────────────────────────────── */

function ls_flag_yn($value)
{
    return strtoupper(trim((string) $value)) === 'Y' ? 'Y' : 'N';
}

function ls_can_show_ledger($confidential, $show_confidential_ac)
{
    if (ls_flag_yn($confidential) !== 'Y') {
        return true;
    }
    return ls_flag_yn($show_confidential_ac) === 'Y';
}

function ls_confidential_sql($alias = 'a')
{
    $alias = preg_replace('/[^A-Za-z0-9_]/', '', (string) $alias);
    if ($alias === '') {
        $alias = 'a';
    }
    return '(UPPER(ISNULL(' . $alias . '.confidential, \'N\')) <> \'Y\''
         . ' OR :show_confidential_ac = \'Y\')';
}

function ls_load_user_flags(PDO $pdo, $branch_code, $user_id)
{
    $stmt = $pdo->prepare(
        'SELECT show_confidential_ac, allow_allbranch
         FROM   branch_user
         WHERE  branch_code = ?
           AND  user_id     = ?'
    );
    $stmt->execute([(string) $branch_code, (string) $user_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    return [
        'show_confidential_ac' => ls_flag_yn($row['show_confidential_ac'] ?? 'N'),
        'allow_allbranch'      => ls_flag_yn($row['allow_allbranch'] ?? 'N'),
    ];
}

function ls_json_exit(array $payload, $code = 200)
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code((int) $code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

function ls_parse_date($value)
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }
    $dt = DateTime::createFromFormat('Y-m-d', $value);
    if ($dt instanceof DateTime) {
        return $dt->format('Y-m-d');
    }
    return null;
}

function ls_fmt_date($ymd)
{
    $ymd = trim((string) $ymd);
    if ($ymd === '') {
        return '';
    }
    $dt = DateTime::createFromFormat('Y-m-d', substr($ymd, 0, 10));
    return $dt ? $dt->format('d/m/Y') : $ymd;
}

function ls_fmt_money($n)
{
    $n = (float) $n;
    if (abs($n) < 0.0000001) {
        return '';
    }
    return number_format($n, 2, '.', ',');
}

function ls_fmt_balance($n)
{
    $n = round((float) $n, 2);
    if (abs($n) < 0.0000001) {
        return number_format(0, 2, '.', ',') . ' Dr';
    }
    $side = $n >= 0 ? 'Dr' : 'Cr';
    return number_format(abs($n), 2, '.', ',') . ' ' . $side;
}

/**
 * Modules in this app that can open a voucher by doc_no.
 * Key = doc_code from doc_type_master.
 */
function ls_voucher_routes()
{
    return [
        'SI' => 'modules/invoice/invoice',
        'RV' => 'modules/receipt/receipt',
        'SQ' => 'modules/sales_quot/sales_quot',
        'JC' => 'modules/job_card/job_card',
        'MR' => 'modules/material_request/material_request',
        'TO' => 'modules/stock_transfer_out/stock_transfer_out',
        'TI' => 'modules/stock_transfer_in/stock_transfer_in',
    ];
}

/**
 * Build doctype → open URL for a voucher (or null when no module exists).
 */
function ls_voucher_url($doctype, $doc_no, array $dtypeToDocCode)
{
    $doc_no = trim((string) $doc_no);
    $dtype  = strtoupper(trim((string) $doctype));
    if ($doc_no === '' || $dtype === '' || $dtype === 'OB') {
        return null;
    }
    $routes = ls_voucher_routes();
    $docCode = $dtypeToDocCode[$dtype] ?? '';
    if ($docCode === '' || !isset($routes[$docCode])) {
        return null;
    }
    $path = $routes[$docCode]
        . '?code=' . rawurlencode($docCode)
        . '&doc_no=' . rawurlencode($doc_no);
    return erp_iframe_url($path);
}

function ls_load_company(PDO $pdo, $company_code)
{
    $stmt = $pdo->prepare(
        'SELECT company_name, po_box, street, city, country, tel, email, www
         FROM   company_master
         WHERE  company_code = ?'
    );
    $stmt->execute([(string) $company_code]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $parts = array_filter([
        trim((string) ($row['po_box'] ?? '')),
        trim((string) ($row['street'] ?? '')),
        trim((string) ($row['city'] ?? '')),
        trim((string) ($row['country'] ?? '')),
    ], function ($v) { return $v !== ''; });
    $contact = [];
    if (trim((string) ($row['tel'] ?? '')) !== '') {
        $contact[] = 'Phone: ' . trim((string) $row['tel']);
    }
    if (trim((string) ($row['email'] ?? '')) !== '') {
        $contact[] = 'Email: ' . trim((string) $row['email']);
    }
    if (trim((string) ($row['www'] ?? '')) !== '') {
        $contact[] = 'Website: ' . trim((string) $row['www']);
    }
    return [
        'company_name' => trim((string) ($row['company_name'] ?? '')),
        'address_line' => implode(' ', $parts),
        'contact_line' => implode(', ', $contact),
    ];
}

function ls_load_account(PDO $pdo, $company_code, $ac_code, $show_confidential_ac)
{
    $sql = 'SELECT a.ac_code, a.description, a.po_box, a.city, a.country,
                   a.tel_off, a.contact_no, a.confidential,
                   pg.description AS parent_group
            FROM   ac_master a
            LEFT JOIN ac_master pg
                   ON pg.company_code = a.company_code
                  AND pg.account_code = a.parent_account_code
            WHERE  a.company_code = :company_code
              AND  a.ac_code      = :ac_code
              AND  ISNULL(a.child_exist, \'N\') = \'N\'
              AND  ' . ls_confidential_sql('a');
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':company_code'         => (string) $company_code,
        ':ac_code'              => (string) $ac_code,
        ':show_confidential_ac' => ls_flag_yn($show_confidential_ac),
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Branch filter for view_account_ledger.
 * All Branch (allowed): branchcode between '0' and 'ZZ'
 * Otherwise: current session branch only.
 */
function ls_branch_sql($all_branch, $allow_allbranch)
{
    if (ls_flag_yn($all_branch) === 'Y' && ls_flag_yn($allow_allbranch) === 'Y') {
        return [
            'sql'    => 'a.branchcode >= :branch_from AND a.branchcode <= :branch_to',
            'params' => [':branch_from' => '0', ':branch_to' => 'ZZ'],
        ];
    }
    return [
        'sql'    => 'a.branchcode = :branch_code',
        'params' => [':branch_code' => null], // filled by caller
    ];
}

$flags = ls_load_user_flags($pdo, $branch_code, $user_id);
$show_confidential_ac = $flags['show_confidential_ac'];
$allow_allbranch      = $flags['allow_allbranch'];
$company              = ls_load_company($pdo, $company_code);

/* ── AJAX: account search ────────────────────────────────────────────── */

if ($ls_ajax === 'search_accounts') {
    $term = trim((string) ($_GET['term'] ?? ''));
    $sql = 'SELECT TOP 100
                   a.ac_code,
                   a.description,
                   pg.description AS parent_group
            FROM   ac_master a
            LEFT JOIN ac_master pg
                   ON pg.company_code = a.company_code
                  AND pg.account_code = a.parent_account_code
            WHERE  a.company_code = :company_code
              AND  ISNULL(a.child_exist, \'N\') = \'N\'
              AND  ISNULL(a.active, \'Y\') = \'Y\'
              AND  ' . ls_confidential_sql('a');
    $params = [
        ':company_code'         => $company_code,
        ':show_confidential_ac' => $show_confidential_ac,
    ];
    if ($term !== '') {
        $sql .= ' AND (a.ac_code LIKE :term1
                    OR a.description LIKE :term2
                    OR pg.description LIKE :term3)';
        $like = '%' . $term . '%';
        $params[':term1'] = $like;
        $params[':term2'] = $like;
        $params[':term3'] = $like;
    }
    $sql .= ' ORDER BY a.ac_code ASC';
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = [];
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $rows[] = [
                'ac_code'      => trim((string) ($r['ac_code'] ?? '')),
                'description'  => trim((string) ($r['description'] ?? '')),
                'parent_group' => trim((string) ($r['parent_group'] ?? '')),
            ];
        }
        ls_json_exit(['success' => true, 'rows' => $rows]);
    } catch (Exception $e) {
        error_log('ledger_report_signle search: ' . $e->getMessage());
        ls_json_exit(['success' => false, 'message' => 'Account search failed.'], 500);
    }
}

/* ── AJAX: run report ────────────────────────────────────────────────── */

if ($ls_ajax === 'run_report') {
    $ac_code    = trim((string) ($_POST['ac_code'] ?? ''));
    $date_from  = ls_parse_date($_POST['date_from'] ?? '');
    $date_to    = ls_parse_date($_POST['date_to'] ?? '');
    $all_branch = ls_flag_yn($_POST['all_branch'] ?? 'N');

    if ($ac_code === '') {
        ls_json_exit(['success' => false, 'message' => 'Select an account.']);
    }
    if ($date_from === null || $date_to === null) {
        ls_json_exit(['success' => false, 'message' => 'Enter a valid Date From and Date To.']);
    }
    if ($date_from > $date_to) {
        ls_json_exit(['success' => false, 'message' => 'Date From cannot be after Date To.']);
    }
    if ($all_branch === 'Y' && $allow_allbranch !== 'Y') {
        $all_branch = 'N';
    }

    $account = ls_load_account($pdo, $company_code, $ac_code, $show_confidential_ac);
    if (!$account) {
        ls_json_exit(['success' => false, 'message' => 'Account not found or not permitted.']);
    }

    $branchFilter = ls_branch_sql($all_branch, $allow_allbranch);
    if (array_key_exists(':branch_code', $branchFilter['params'])) {
        $branchFilter['params'][':branch_code'] = $branch_code;
    }

    $date_to_exclusive = (new DateTime($date_to))->modify('+1 day')->format('Y-m-d');

    $baseSelect = 'SELECT a.companycode, a.accode, a.docno, a.docdate, a.doctype,
                          SUM(a.debit * a.exrate)  AS debit,
                          SUM(a.credit * a.exrate) AS credit,
                          LEFT(a.narration, 250)   AS narration
                   FROM   view_account_ledger a
                   WHERE  a.companycode = :company_code
                     AND  ' . $branchFilter['sql'] . '
                     AND  a.accode = :ac_code';

    $groupBy = ' GROUP BY a.companycode, a.accode, a.docno, a.docdate, a.doctype, a.narration
                 ORDER BY a.docdate, a.doctype, a.docno';

    try {
        // Opening balance: activity before Date From
        $sqlOb = $baseSelect . ' AND a.docdate < :date_from' . $groupBy;
        $paramsOb = array_merge(
            [
                ':company_code' => $company_code,
                ':ac_code'      => $ac_code,
                ':date_from'    => $date_from,
            ],
            $branchFilter['params']
        );
        $stmt = $pdo->prepare($sqlOb);
        $stmt->execute($paramsOb);
        $obRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $obDebit  = 0.0;
        $obCredit = 0.0;
        foreach ($obRows as $r) {
            $obDebit  += (float) ($r['debit'] ?? 0);
            $obCredit += (float) ($r['credit'] ?? 0);
        }
        $opening = round($obDebit - $obCredit, 2);

        // Period lines: Date From inclusive through Date To inclusive
        $sqlPeriod = $baseSelect
            . ' AND a.docdate >= :date_from AND a.docdate < :date_to_exclusive'
            . $groupBy;
        $paramsPeriod = array_merge(
            [
                ':company_code'      => $company_code,
                ':ac_code'           => $ac_code,
                ':date_from'         => $date_from,
                ':date_to_exclusive' => $date_to_exclusive,
            ],
            $branchFilter['params']
        );
        $stmt = $pdo->prepare($sqlPeriod);
        $stmt->execute($paramsPeriod);
        $periodRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // doc_type → name (description fallback) and doc_type → doc_code (voucher open)
        $docTypeNames  = [];
        $dtypeToDocCode = [];
        $voucherRoutes  = ls_voucher_routes();
        try {
            $stmtNames = $pdo->prepare(
                'SELECT doc_code, doc_type, doc_name
                 FROM   doc_type_master
                 WHERE  company_code = ?
                   AND  branch_code  = ?'
            );
            $stmtNames->execute([$company_code, $branch_code]);
            while ($nr = $stmtNames->fetch(PDO::FETCH_ASSOC)) {
                $dtype = strtoupper(trim((string) ($nr['doc_type'] ?? '')));
                $dcode = strtoupper(trim((string) ($nr['doc_code'] ?? '')));
                $name  = trim((string) ($nr['doc_name'] ?? ''));
                if ($dtype !== '' && $name !== '' && !isset($docTypeNames[$dtype])) {
                    $docTypeNames[$dtype] = $name;
                }
                // Prefer a doc_code that has a screen in this app
                if ($dtype !== '' && $dcode !== '' && isset($voucherRoutes[$dcode])) {
                    if (!isset($dtypeToDocCode[$dtype]) || !isset($voucherRoutes[$dtypeToDocCode[$dtype]])) {
                        $dtypeToDocCode[$dtype] = $dcode;
                    }
                } elseif ($dtype !== '' && $dcode !== '' && !isset($dtypeToDocCode[$dtype])) {
                    $dtypeToDocCode[$dtype] = $dcode;
                }
            }
        } catch (Exception $e) {
            error_log('ledger_report_signle doc_type names: ' . $e->getMessage());
        }

        $lines = [];
        $run = $opening;
        $totDebit  = 0.0;
        $totCredit = 0.0;

        $lines[] = [
            'doc_date'    => ls_fmt_date($date_from),
            'doc_type'    => 'OB',
            'doc_no'      => '',
            'doc_url'     => null,
            'description' => 'OPENING BALANCE',
            'debit'       => '',
            'credit'      => '',
            'balance'     => ls_fmt_balance($run),
            'balance_raw' => $run,
        ];

        foreach ($periodRows as $r) {
            $debit  = round((float) ($r['debit'] ?? 0), 2);
            $credit = round((float) ($r['credit'] ?? 0), 2);
            $run    = round($run + $debit - $credit, 2);
            $totDebit  += $debit;
            $totCredit += $credit;

            $docDate = '';
            if (!empty($r['docdate'])) {
                $docDate = ls_fmt_date(substr((string) $r['docdate'], 0, 10));
            }
            $narr  = trim((string) ($r['narration'] ?? ''));
            $dtype = trim((string) ($r['doctype'] ?? ''));
            $dtypeKey = strtoupper($dtype);
            $docNo = trim((string) ($r['docno'] ?? ''));
            // Description: narration if present, otherwise doc_type name (then code)
            $desc = $narr !== ''
                ? $narr
                : ($docTypeNames[$dtypeKey] ?? $dtype);

            $lines[] = [
                'doc_date'    => $docDate,
                'doc_type'    => $dtype,
                'doc_no'      => $docNo,
                'doc_url'     => ls_voucher_url($dtype, $docNo, $dtypeToDocCode),
                'description' => $desc,
                'debit'       => ls_fmt_money($debit),
                'credit'      => ls_fmt_money($credit),
                'balance'     => ls_fmt_balance($run),
                'balance_raw' => $run,
            ];
        }

        $acctMeta = [];
        if (trim((string) ($account['po_box'] ?? '')) !== '' || trim((string) ($account['city'] ?? '')) !== '') {
            $acctMeta[] = 'P.O. Box: ' . trim((string) ($account['po_box'] ?? ''))
                . (trim((string) ($account['city'] ?? '')) !== '' ? ' ' . trim((string) $account['city']) : '');
        }
        $tel = trim((string) ($account['tel_off'] ?? ''));
        if ($tel === '') {
            $tel = trim((string) ($account['contact_no'] ?? ''));
        }
        if ($tel !== '') {
            $acctMeta[] = 'Tel: ' . $tel;
        }

        ls_json_exit([
            'success' => true,
            'header'  => [
                'company_name' => $company['company_name'],
                'address_line' => $company['address_line'],
                'contact_line' => $company['contact_line'],
                'period_label' => ls_fmt_date($date_from) . ' to ' . ls_fmt_date($date_to),
                'account_name' => trim((string) ($account['description'] ?? '')),
                'account_code' => trim((string) ($account['ac_code'] ?? '')),
                'account_meta' => implode(' · ', $acctMeta),
                'parent_group' => trim((string) ($account['parent_group'] ?? '')),
                'all_branch'   => $all_branch,
                'printed_at'   => date('d/m/Y H:i'),
            ],
            'lines'   => $lines,
            // Summary card sums: opening (before Date From), period debit/credit, closing
            'totals'  => [
                'opening'     => number_format(abs($opening), 2, '.', ','),
                'opening_raw' => $opening,
                'debit'       => number_format($totDebit, 2, '.', ','),
                'credit'      => number_format($totCredit, 2, '.', ','),
                'balance'     => ls_fmt_balance($run),
                'balance_raw' => $run,
                'entry_count' => count($lines),
            ],
        ]);
    } catch (Exception $e) {
        error_log('ledger_report_signle run: ' . $e->getMessage());
        ls_json_exit(['success' => false, 'message' => 'Could not generate ledger report.'], 500);
    }
}

/* ── page ────────────────────────────────────────────────────────────── */

$erp_embedded = erp_is_embedded();
$page_title   = 'Ledger Report-Single';
$default_to   = date('Y-m-d');
$default_from = $default_to;
try {
    $stmt = $pdo->prepare(
        'SELECT start_date
         FROM   branch_master
         WHERE  company_code = ?
           AND  branch_code  = ?'
    );
    $stmt->execute([$company_code, $branch_code]);
    $startRaw = $stmt->fetchColumn();
    if ($startRaw) {
        $startYmd = ls_parse_date(substr((string) $startRaw, 0, 10));
        if ($startYmd !== null) {
            $default_from = $startYmd;
        }
    }
} catch (Exception $e) {
    error_log('ledger_report_signle start_date: ' . $e->getMessage());
}
$branchStartDate = $default_from;
$allowAllBranch  = $allow_allbranch;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8'); ?> — INFOS ERP</title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(erp_font_awesome_href(), ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(erp_asset_v('assets/css/erp_document.css'), ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(erp_asset_v('assets/css/erp_search.css'), ENT_QUOTES, 'UTF-8'); ?>">
    <script src="<?php echo htmlspecialchars(erp_asset_v('assets/js/erp-tabs/erp-tabs.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
    <style>
        body.ls-body {
            background: var(--bg);
            color: var(--text);
            font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', sans-serif;
            margin: 0;
            padding: 16px;
        }
        .ls-page { max-width: 1120px; margin: 0 auto; }
        .ls-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            padding: 16px 18px;
            margin-bottom: 14px;
        }
        .ls-label {
            display: block;
            font-size: 11px;
            font-weight: 600;
            color: var(--text-3);
            text-transform: uppercase;
            letter-spacing: .04em;
            margin-bottom: 6px;
        }
        .ls-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1.6fr;
            gap: 12px;
            align-items: end;
        }
        @media (max-width: 860px) {
            .ls-grid { grid-template-columns: 1fr 1fr; }
            .ls-grid .ls-span-acct { grid-column: 1 / -1; }
        }
        .ls-input {
            width: 100%;
            height: 38px;
            border: 1px solid var(--border-strong);
            border-radius: 8px;
            padding: 0 12px;
            font-size: 13px;
            color: var(--text);
            background: var(--surface);
            box-sizing: border-box;
        }
        .ls-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px var(--primary-ring);
        }
        .ls-account-wrap { display: flex; gap: 8px; }
        .ls-account-wrap .ls-input { flex: 1; }
        .ls-toolbar {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-top: 14px;
        }
        .ls-pills { display: flex; flex-wrap: wrap; gap: 8px; }
        .ls-pill {
            border: 1px solid var(--border-strong);
            background: var(--surface);
            color: var(--text-2);
            border-radius: 999px;
            padding: 6px 12px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
        }
        .ls-pill:hover { background: var(--bg); color: var(--text); }
        .ls-pill.is-active {
            border-color: var(--primary);
            color: var(--primary);
            background: var(--primary-soft);
        }
        .ls-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; }
        .ls-check {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-2);
        }
        .ls-check input { accent-color: var(--primary); }
        .ls-hint { font-size: 11px; color: var(--text-3); margin-left: 4px; }
        .ls-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            height: 36px;
            padding: 0 14px;
            border-radius: 8px;
            border: 1px solid var(--border-strong);
            background: var(--surface);
            color: var(--text);
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
        }
        .ls-btn:hover { background: var(--bg); }
        .ls-btn:disabled { opacity: .45; cursor: not-allowed; }
        .ls-btn-primary {
            background: var(--primary);
            border-color: var(--primary);
            color: #fff;
        }
        .ls-btn-primary:hover { background: var(--primary-hover); border-color: var(--primary-hover); }
        .ls-msg { margin-top: 10px; font-size: 13px; color: var(--text-2); }
        .ls-msg-error { color: var(--danger); }

        .ls-head {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 14px;
        }
        .ls-head-left h2 {
            margin: 0 0 4px;
            font-size: 15px;
            font-weight: 700;
            color: var(--text);
        }
        .ls-head-left .ls-title {
            margin: 8px 0 2px;
            font-size: 18px;
            font-weight: 700;
            color: var(--text);
        }
        .ls-head-left .ls-period,
        .ls-head-left .ls-sub,
        .ls-head-right .ls-sub {
            font-size: 12px;
            color: var(--text-2);
        }
        .ls-head-right { text-align: right; }
        .ls-head-right .ls-acct {
            font-size: 16px;
            font-weight: 700;
            color: var(--text);
        }
        .ls-head-right .ls-generated {
            margin-top: 6px;
            font-size: 11px;
            color: var(--text-3);
        }

        .ls-summary {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            overflow: hidden;
            background: var(--bg);
        }
        @media (max-width: 720px) {
            .ls-summary { grid-template-columns: 1fr 1fr; }
        }
        .ls-sum-item {
            padding: 14px 16px;
            border-right: 1px solid var(--border);
        }
        .ls-sum-item:last-child { border-right: none; }
        .ls-sum-item.is-closing {
            background: var(--primary-soft);
        }
        .ls-sum-label {
            display: block;
            font-size: 11px;
            font-weight: 600;
            color: var(--text-3);
            margin-bottom: 6px;
        }
        .ls-sum-value {
            font-size: 20px;
            font-weight: 700;
            color: var(--text);
            letter-spacing: -.02em;
        }
        .ls-sum-item.is-closing .ls-sum-value { color: var(--primary); }

        .ls-table-tools {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 12px;
        }
        .ls-table-search {
            position: relative;
            min-width: 240px;
            flex: 1;
            max-width: 360px;
        }
        .ls-table-search i {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-3);
        }
        .ls-table-search input {
            width: 100%;
            height: 36px;
            border: 1px solid var(--border-strong);
            border-radius: 999px;
            padding: 0 12px 0 34px;
            font-size: 13px;
            background: var(--surface);
            color: var(--text);
            box-sizing: border-box;
        }
        .ls-table-search input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px var(--primary-ring);
        }
        .ls-entry-count { font-size: 12px; color: var(--text-3); font-weight: 600; }

        .ls-table-wrap { overflow-x: auto; }
        .ls-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        .ls-table thead th {
            text-align: left;
            font-size: 11px;
            font-weight: 700;
            color: var(--text-3);
            text-transform: uppercase;
            letter-spacing: .04em;
            padding: 10px 12px;
            border-bottom: 1px solid var(--border);
            background: var(--bg);
        }
        .ls-table thead th.num,
        .ls-table td.num { text-align: right; }
        .ls-table tbody td {
            padding: 12px;
            border-bottom: 1px solid var(--border);
            vertical-align: top;
            color: var(--text);
        }
        .ls-table tbody tr:hover { background: var(--bg); }
        .ls-badge {
            display: inline-block;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: .03em;
            padding: 2px 8px;
            border-radius: 999px;
            background: var(--primary-soft);
            color: var(--primary);
            border: 1px solid var(--border);
        }
        .ls-docno {
            display: block;
            margin-top: 4px;
            font-size: 12px;
            color: var(--primary);
            font-weight: 600;
        }
        .ls-doclink {
            color: var(--primary);
            font-weight: 600;
            text-decoration: none;
        }
        .ls-doclink:hover { text-decoration: underline; color: var(--primary-hover); }
        .ls-docplain { color: var(--text); font-weight: 600; }
        .ls-amt-empty { color: var(--text-3); }
        .ls-amt-credit { color: var(--success); font-weight: 600; }
        .ls-amt-balance { font-weight: 700; }
        .ls-table tfoot td {
            padding: 12px;
            border-top: 2px solid var(--border-strong);
            font-weight: 700;
            background: var(--bg);
        }

        @media print {
            body.ls-body { padding: 0; background: #fff; }
            .ls-no-print { display: none !important; }
            .ls-card { box-shadow: none; }
        }
    </style>
</head>
<body class="ls-body<?php echo $erp_embedded ? ' erp-embedded' : ''; ?>">
<div class="ls-page">

    <section class="ls-card ls-no-print">
        <form id="lsForm" autocomplete="off">
            <div class="ls-grid">
                <div>
                    <label class="ls-label" for="date_from">Date from</label>
                    <input type="date" id="date_from" class="ls-input"
                           value="<?php echo htmlspecialchars($default_from, ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>
                <div>
                    <label class="ls-label" for="date_to">Date to</label>
                    <input type="date" id="date_to" class="ls-input"
                           value="<?php echo htmlspecialchars($default_to, ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>
                <div class="ls-span-acct">
                    <label class="ls-label" for="ac_display">Account</label>
                    <div class="ls-account-wrap">
                        <input type="hidden" id="ac_code" value="">
                        <input type="text" id="ac_display" class="ls-input" readonly
                               placeholder="Search account code / name / parent group">
                        <button type="button" class="ls-btn" id="btnSearchAc" title="Search accounts">
                            <i class="fa fa-search"></i>
                        </button>
                    </div>
                </div>
            </div>

            <div class="ls-toolbar">
                <div class="ls-pills" id="lsQuickRanges">
                    <button type="button" class="ls-pill" data-range="this_month">This month</button>
                    <button type="button" class="ls-pill" data-range="last_month">Last month</button>
                    <button type="button" class="ls-pill" data-range="this_quarter">This quarter</button>
                    <button type="button" class="ls-pill" data-range="this_year">This year</button>
                </div>
                <div class="ls-actions">
                    <label class="ls-check" title="<?php echo $allowAllBranch === 'Y'
                        ? 'Include ledger postings from all branches'
                        : 'Not enabled for your user'; ?>">
                        <input type="checkbox" id="all_branch" value="Y"
                            <?php echo $allowAllBranch === 'Y' ? '' : 'disabled'; ?>>
                        All branches
                    </label>
                    <?php if ($allowAllBranch !== 'Y'): ?>
                        <span class="ls-hint">Not enabled for your user</span>
                    <?php endif; ?>
                    <button type="button" class="ls-btn" id="btnPrint" disabled>
                        <i class="fa fa-print"></i> Print
                    </button>
                    <button type="button" class="ls-btn" id="btnExport" disabled>
                        <i class="fa fa-download"></i> Export
                    </button>
                    <button type="submit" class="ls-btn ls-btn-primary" id="btnGenerate">
                        <i class="fa fa-search"></i> Generate
                    </button>
                </div>
            </div>
            <div id="lsMsg" class="ls-msg" hidden></div>
        </form>
    </section>

    <section id="lsReport" hidden>
        <div class="ls-card">
            <div class="ls-head">
                <div class="ls-head-left">
                    <h2>
                        <span id="lsCompanyName"></span>
                        <span class="ls-sub" id="lsCompanyAddrWrap"> — <span id="lsCompanyAddr"></span></span>
                    </h2>
                    <div class="ls-title">Statement of ledger</div>
                    <div class="ls-period" id="lsPeriod"></div>
                </div>
                <div class="ls-head-right">
                    <div class="ls-acct" id="lsAccountName"></div>
                    <div class="ls-sub" id="lsAccountMeta"></div>
                    <div class="ls-generated ls-no-print">
                        Generated <span id="lsPrintedAt"></span>
                        <button type="button" class="ls-btn" id="btnRefreshReport" style="height:28px;margin-left:8px;padding:0 10px;" title="Refresh report" hidden>
                            <i class="fa fa-refresh"></i> Refresh
                        </button>
                    </div>
                </div>
            </div>

            <div class="ls-summary">
                <div class="ls-sum-item">
                    <span class="ls-sum-label">Opening balance</span>
                    <div class="ls-sum-value" id="lsSumOpening">—</div>
                </div>
                <div class="ls-sum-item">
                    <span class="ls-sum-label">Total debit</span>
                    <div class="ls-sum-value" id="lsSumDebit">—</div>
                </div>
                <div class="ls-sum-item">
                    <span class="ls-sum-label">Total credit</span>
                    <div class="ls-sum-value" id="lsSumCredit">—</div>
                </div>
                <div class="ls-sum-item is-closing">
                    <span class="ls-sum-label">Closing balance</span>
                    <div class="ls-sum-value" id="lsSumClosing">—</div>
                </div>
            </div>
        </div>

        <div class="ls-card">
            <div class="ls-table-tools ls-no-print">
                <div class="ls-table-search">
                    <i class="fa fa-search" aria-hidden="true"></i>
                    <input type="text" id="lsLineFilter" placeholder="Search doc type, doc no or description" autocomplete="off">
                </div>
                <div class="ls-entry-count"><span id="lsEntryCount">0</span> entries</div>
            </div>
            <div class="ls-table-wrap">
                <table class="ls-table">
                    <thead>
                        <tr>
                            <th style="width:100px;">Date</th>
                            <th style="width:140px;">Document</th>
                            <th>Description</th>
                            <th class="num" style="width:120px;">Debit</th>
                            <th class="num" style="width:120px;">Credit</th>
                            <th class="num" style="width:140px;">Balance</th>
                        </tr>
                    </thead>
                    <tbody id="lsBody"></tbody>
                    <tfoot>
                        <tr>
                            <td colspan="3">Total</td>
                            <td class="num" id="lsTotDebit"></td>
                            <td class="num" id="lsTotCredit"></td>
                            <td class="num" id="lsTotBalance"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </section>
</div>

<div id="acSearchModal" class="popup-overlay erp-search" style="display:none;">
    <div class="erp-search-dialog lg" role="dialog" aria-modal="true">
        <div class="erp-search-head">
            <h4 class="erp-search-title"><i class="fa fa-search"></i> Select Account</h4>
            <button type="button" class="erp-search-close" id="acSearchClose" title="Close" aria-label="Close">
                <i class="fa fa-times"></i>
            </button>
        </div>
        <div class="erp-search-bar">
            <div class="erp-search-input">
                <i class="fa fa-search"></i>
                <input type="text" id="acSearchInput" autocomplete="off"
                       placeholder="Search by Code, Name or Parent Group...">
            </div>
            <button type="button" class="erp-search-btn" id="acSearchReset">Reset</button>
        </div>
        <div class="erp-search-body">
            <table class="erp-search-table" id="acSearchResults">
                <thead>
                    <tr>
                        <th style="width:110px;">Code</th>
                        <th>Account Name</th>
                        <th style="width:220px;">Parent Group</th>
                        <th style="width:100px; text-align:center;">Action</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
</div>

<script>
(function () {
    var selfUrl = <?php echo json_encode($self_url, JSON_UNESCAPED_SLASHES); ?>;
    var branchStart = <?php echo json_encode($branchStartDate); ?>;
    var form = document.getElementById('lsForm');
    var msg = document.getElementById('lsMsg');
    var report = document.getElementById('lsReport');
    var btnPrint = document.getElementById('btnPrint');
    var btnExport = document.getElementById('btnExport');
    var searchTimeout = null;
    var lastData = null;
    var allLines = [];
    var FILTER_KEY = 'erp_report_filters:' + window.location.pathname + window.location.search;
    var RESULT_KEY = 'erp_report_result:' + window.location.pathname + window.location.search;

    function showMsg(text, isError) {
        msg.hidden = !text;
        msg.textContent = text || '';
        msg.className = 'ls-msg' + (isError ? ' ls-msg-error' : '');
    }

    function saveFilters() {
        try {
            sessionStorage.setItem(FILTER_KEY, JSON.stringify({
                date_from: document.getElementById('date_from').value,
                date_to: document.getElementById('date_to').value,
                ac_code: document.getElementById('ac_code').value,
                ac_display: document.getElementById('ac_display').value,
                all_branch: document.getElementById('all_branch').checked ? 'Y' : 'N'
            }));
        } catch (e) { /* ignore */ }
    }

    function saveResult(data) {
        try {
            sessionStorage.setItem(RESULT_KEY, JSON.stringify(data));
        } catch (e) { /* quota — keep filters only */ }
    }

    function restoreFilters() {
        try {
            var raw = sessionStorage.getItem(FILTER_KEY);
            if (!raw) return;
            var f = JSON.parse(raw) || {};
            if (f.date_from) document.getElementById('date_from').value = f.date_from;
            if (f.date_to) document.getElementById('date_to').value = f.date_to;
            if (f.ac_code) document.getElementById('ac_code').value = f.ac_code;
            if (f.ac_display) document.getElementById('ac_display').value = f.ac_display;
            var all = document.getElementById('all_branch');
            if (all && !all.disabled) all.checked = (f.all_branch === 'Y');
        } catch (e) { /* ignore */ }
    }

    function restoreResult() {
        try {
            var raw = sessionStorage.getItem(RESULT_KEY);
            if (!raw) return;
            var data = JSON.parse(raw);
            if (!data || !data.success) return;
            lastData = data;
            renderReport(data);
            report.hidden = false;
            btnPrint.disabled = false;
            btnExport.disabled = false;
            var btnRefresh = document.getElementById('btnRefreshReport');
            if (btnRefresh) btnRefresh.hidden = false;
        } catch (e) { /* ignore */ }
    }

    restoreFilters();

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
    function escAttr(s) {
        return esc(s).replace(/'/g, '&#39;');
    }
    function dashAmt(v) {
        return (v == null || String(v).trim() === '') ? '<span class="ls-amt-empty">—</span>' : esc(v);
    }

    function ymd(d) {
        var m = (d.getMonth() + 1);
        var day = d.getDate();
        return d.getFullYear() + '-' + (m < 10 ? '0' : '') + m + '-' + (day < 10 ? '0' : '') + day;
    }

    function setQuickRange(key) {
        var today = new Date();
        var from, to = new Date(today.getFullYear(), today.getMonth(), today.getDate());
        if (key === 'this_month') {
            from = new Date(today.getFullYear(), today.getMonth(), 1);
        } else if (key === 'last_month') {
            from = new Date(today.getFullYear(), today.getMonth() - 1, 1);
            to = new Date(today.getFullYear(), today.getMonth(), 0);
        } else if (key === 'this_quarter') {
            var q = Math.floor(today.getMonth() / 3) * 3;
            from = new Date(today.getFullYear(), q, 1);
        } else if (key === 'this_year') {
            from = new Date(today.getFullYear(), 0, 1);
            if (branchStart) {
                var bs = new Date(branchStart + 'T00:00:00');
                if (!isNaN(bs.getTime()) && bs > from) from = bs;
            }
        } else {
            return;
        }
        document.getElementById('date_from').value = ymd(from);
        document.getElementById('date_to').value = ymd(to);
        document.querySelectorAll('#lsQuickRanges .ls-pill').forEach(function (btn) {
            btn.classList.toggle('is-active', btn.getAttribute('data-range') === key);
        });
    }

    document.getElementById('lsQuickRanges').addEventListener('click', function (e) {
        var btn = e.target.closest('.ls-pill');
        if (!btn) return;
        setQuickRange(btn.getAttribute('data-range'));
    });

    function openSearch() {
        document.getElementById('acSearchModal').style.display = 'flex';
        var input = document.getElementById('acSearchInput');
        input.value = '';
        input.focus();
        searchAccounts('');
    }
    function closeSearch() {
        document.getElementById('acSearchModal').style.display = 'none';
    }

    function searchAccounts(term) {
        var tbody = document.querySelector('#acSearchResults tbody');
        tbody.innerHTML = '<tr><td colspan="4" class="text-center p-3">Searching...</td></tr>';
        var url = selfUrl + (selfUrl.indexOf('?') >= 0 ? '&' : '?') + 'action=search_accounts&term='
            + encodeURIComponent(term || '');
        fetch(url, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success) {
                    tbody.innerHTML = '<tr><td colspan="4" class="text-center p-3">Search failed</td></tr>';
                    return;
                }
                var rows = data.rows || [];
                if (!rows.length) {
                    tbody.innerHTML = '<tr><td colspan="4" class="text-center p-3">No accounts found</td></tr>';
                    return;
                }
                tbody.innerHTML = rows.map(function (row) {
                    return '<tr>'
                        + '<td>' + esc(row.ac_code) + '</td>'
                        + '<td>' + esc(row.description) + '</td>'
                        + '<td>' + esc(row.parent_group || '') + '</td>'
                        + '<td style="text-align:center;"><button type="button" class="erp-search-btn primary ls-pick"'
                        + ' data-code="' + escAttr(row.ac_code) + '"'
                        + ' data-name="' + escAttr(row.description) + '">Select</button></td>'
                        + '</tr>';
                }).join('');
            })
            .catch(function () {
                tbody.innerHTML = '<tr><td colspan="4" class="text-center p-3">Search failed</td></tr>';
            });
    }

    document.getElementById('btnSearchAc').addEventListener('click', openSearch);
    document.getElementById('ac_display').addEventListener('click', openSearch);
    document.getElementById('acSearchClose').addEventListener('click', closeSearch);
    document.getElementById('acSearchReset').addEventListener('click', function () {
        document.getElementById('acSearchInput').value = '';
        searchAccounts('');
    });
    document.getElementById('acSearchInput').addEventListener('keyup', function () {
        var v = this.value;
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(function () { searchAccounts(v); }, 250);
    });
    document.getElementById('acSearchResults').addEventListener('click', function (e) {
        var btn = e.target.closest('.ls-pick');
        if (!btn) return;
        document.getElementById('ac_code').value = btn.getAttribute('data-code') || '';
        document.getElementById('ac_display').value =
            (btn.getAttribute('data-code') || '') + ' — ' + (btn.getAttribute('data-name') || '');
        closeSearch();
    });
    document.getElementById('acSearchModal').addEventListener('click', function (e) {
        if (e.target === this) closeSearch();
    });

    btnPrint.addEventListener('click', function () { window.print(); });

    btnExport.addEventListener('click', function () {
        if (!lastData || !lastData.lines) return;
        var rows = [['Date', 'Doc Type', 'Doc No', 'Description', 'Debit', 'Credit', 'Balance']];
        lastData.lines.forEach(function (line) {
            rows.push([
                line.doc_date || '',
                line.doc_type || '',
                line.doc_no || '',
                line.description || '',
                line.debit || '',
                line.credit || '',
                line.balance || ''
            ]);
        });
        var csv = rows.map(function (r) {
            return r.map(function (c) {
                var s = String(c == null ? '' : c);
                return /[",\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
            }).join(',');
        }).join('\r\n');
        var blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'ledger_report.csv';
        a.click();
        URL.revokeObjectURL(a.href);
    });

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        showMsg('');
        var ac = document.getElementById('ac_code').value.trim();
        if (!ac) {
            showMsg('Select an account.', true);
            return;
        }
        var fd = new FormData();
        fd.append('action', 'run_report');
        fd.append('ac_code', ac);
        fd.append('date_from', document.getElementById('date_from').value);
        fd.append('date_to', document.getElementById('date_to').value);
        fd.append('all_branch', document.getElementById('all_branch').checked ? 'Y' : 'N');

        var btn = document.getElementById('btnGenerate');
        btn.disabled = true;
        fetch(selfUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                btn.disabled = false;
                if (!data.success) {
                    report.hidden = true;
                    btnPrint.disabled = true;
                    btnExport.disabled = true;
                    showMsg(data.message || 'Report failed.', true);
                    return;
                }
                lastData = data;
                saveFilters();
                saveResult(data);
                if (window.ErpTabs && data.header && data.header.account_name) {
                    window.ErpTabs.setTitle(data.header.account_name);
                }
                document.getElementById('lsLineFilter').value = '';
                renderReport(data);
                report.hidden = false;
                btnPrint.disabled = false;
                btnExport.disabled = false;
                var btnRefresh = document.getElementById('btnRefreshReport');
                if (btnRefresh) btnRefresh.hidden = false;
            })
            .catch(function () {
                btn.disabled = false;
                showMsg('Report failed.', true);
            });
    });

    var btnRefreshReport = document.getElementById('btnRefreshReport');
    if (btnRefreshReport) {
        btnRefreshReport.addEventListener('click', function () {
            if (typeof form.requestSubmit === 'function') form.requestSubmit();
            else form.dispatchEvent(new Event('submit', { cancelable: true }));
        });
    }

    document.getElementById('lsBody').addEventListener('click', function (e) {
        var a = e.target.closest('a.ls-doclink');
        if (!a) return;
        var url = a.getAttribute('data-erp-tab-url') || a.getAttribute('href');
        var title = a.getAttribute('data-erp-tab-title') || a.textContent || 'Document';
        if (!url) return;
        // Ctrl/Cmd/middle-click: let browser open in new browser tab
        if (e.ctrlKey || e.metaKey || e.button === 1) return;
        e.preventDefault();
        if (window.ErpTabs && typeof window.ErpTabs.open === 'function') {
            window.ErpTabs.open(title, url);
            return;
        }
        // ErpTabs script missing: still ask parent shell; never replace this report iframe
        if (window.parent && window.parent !== window) {
            try {
                window.parent.postMessage(
                    { type: 'OPEN_TAB', title: title, url: url },
                    window.location.origin
                );
                return;
            } catch (err) { /* fall through */ }
            window.open(url, '_blank');
            return;
        }
        window.location.href = url;
    });
    document.getElementById('lsBody').addEventListener('auxclick', function (e) {
        if (e.button !== 1) return;
        var a = e.target.closest('a.ls-doclink');
        if (!a) return;
        // Middle-click keeps default new-tab behavior
    });

    function renderRows(lines) {
        var body = document.getElementById('lsBody');
        body.innerHTML = lines.map(function (line) {
            var creditHtml = (line.credit && String(line.credit).trim() !== '')
                ? '<span class="ls-amt-credit">' + esc(line.credit) + '</span>'
                : dashAmt('');
            var docCell = '';
            if (line.doc_type) {
                docCell += '<span class="ls-badge">' + esc(line.doc_type) + '</span>';
            }
            if (line.doc_no) {
                if (line.doc_url) {
                    docCell += '<a class="ls-doclink ls-docno" href="' + escAttr(line.doc_url)
                        + '" data-erp-tab-url="' + escAttr(line.doc_url)
                        + '" data-erp-tab-title="' + escAttr(line.doc_no)
                        + '" title="Open voucher">' + esc(line.doc_no) + '</a>';
                } else {
                    docCell += '<span class="ls-docplain ls-docno">' + esc(line.doc_no) + '</span>';
                }
            }
            if (!docCell) {
                docCell = '<span class="ls-amt-empty">—</span>';
            }
            return '<tr>'
                + '<td>' + esc(line.doc_date) + '</td>'
                + '<td>' + docCell + '</td>'
                + '<td>' + esc(line.description) + '</td>'
                + '<td class="num">' + dashAmt(line.debit) + '</td>'
                + '<td class="num">' + creditHtml + '</td>'
                + '<td class="num ls-amt-balance">' + esc(line.balance) + '</td>'
                + '</tr>';
        }).join('');
        document.getElementById('lsEntryCount').textContent = String(lines.length);
    }

    function renderReport(data) {
        var h = data.header || {};
        document.getElementById('lsCompanyName').textContent = h.company_name || '';
        document.getElementById('lsCompanyAddr').textContent = h.address_line || '';
        document.getElementById('lsCompanyAddrWrap').style.display = h.address_line ? '' : 'none';
        document.getElementById('lsPeriod').textContent = h.period_label || '';
        document.getElementById('lsAccountName').textContent = h.account_name || '';
        document.getElementById('lsAccountMeta').textContent = h.account_meta || '';
        document.getElementById('lsPrintedAt').textContent = h.printed_at || '';

        var t = data.totals || {};
        document.getElementById('lsSumOpening').textContent = t.opening || '0.00';
        document.getElementById('lsSumDebit').textContent = t.debit || '0.00';
        document.getElementById('lsSumCredit').textContent = t.credit || '0.00';
        document.getElementById('lsSumClosing').textContent = t.balance || '0.00 Dr';
        document.getElementById('lsTotDebit').textContent = t.debit || '0.00';
        document.getElementById('lsTotCredit').textContent = t.credit || '0.00';
        document.getElementById('lsTotBalance').textContent = t.balance || '0.00 Dr';

        allLines = data.lines || [];
        renderRows(allLines);
    }

    document.getElementById('lsLineFilter').addEventListener('input', function () {
        var q = String(this.value || '').toLowerCase().trim();
        if (!q) {
            renderRows(allLines);
            return;
        }
        renderRows(allLines.filter(function (line) {
            return String(line.doc_no || '').toLowerCase().indexOf(q) >= 0
                || String(line.description || '').toLowerCase().indexOf(q) >= 0
                || String(line.doc_type || '').toLowerCase().indexOf(q) >= 0;
        }));
    });

    // After helpers exist, restore last generated result for this report URL
    restoreResult();
})();
</script>
</body>
</html>
<?php
if (ob_get_level() > 0) {
    ob_end_flush();
}
