<?php
ob_start();
error_reporting(0); // Suppress warnings

require('db.php');

ob_clean();

$searchTerm = isset($_GET['term']) ? $_GET['term'] : '';
$type = isset($_GET['type']) ? $_GET['type'] : 'doc_no';
$doc_type_filter = isset($_GET['doc_type']) ? trim($_GET['doc_type']) : '';
$doc_code = isset($_GET['doc_code']) ? strtoupper(trim($_GET['doc_code'])) : 'MR';

$company_code = isset($_SESSION['company_code']) ? $_SESSION['company_code'] : '';
$branch_code = isset($_SESSION['branch_code']) ? $_SESSION['branch_code'] : '';

/**
 * Tables searched per doc_code (whitelist — never take table/column names from the request).
 *
 *   ref_col        : header reference returned as tracking_no (and labelled via $search_ref_label)
 *   job_col        : header job column, or null
 *   detail_has_job : detail table has job_no (used for select + search)
 *   with_customer  : join ac_master; return/search customer name
 *   detail_ref_col : optional detail column aggregated into tracking_no when header ref is blank
 *                    (e.g. job_card_d.quotation_no)
 */
$search_sources = [
    'MR' => [
        'h' => 'material_request_h', 'd' => 'material_request_d',
        'ref_col' => 'tracking_no', 'job_col' => null,
        'detail_has_job' => true, 'with_customer' => false,
    ],
    'SI' => [
        'h' => 'invoice_h', 'd' => 'invoice_d',
        'ref_col' => 'tracking_no', 'job_col' => 'job_no',
        'detail_has_job' => true, 'with_customer' => true,
    ],
    'SQ' => [
        'h' => 'sales_quotation_h', 'd' => 'sales_quotation_d',
        'ref_col' => 'tracking_no', 'job_col' => null,
        'detail_has_job' => true, 'with_customer' => true,
    ],
    'JC' => [
        'h' => 'job_card_h', 'd' => 'job_card_d',
        // Quotation No on header; fall back to detail quotation_no
        'ref_col' => 'qtn_no', 'job_col' => null,
        'detail_has_job' => false, 'with_customer' => true,
        'detail_ref_col' => 'quotation_no',
    ],
    'RV' => [
        'h' => 'chq_receipt_h', 'd' => 'chq_receipt_d',
        'ref_col' => 'chq_no', 'job_col' => null,
        'detail_has_job' => false, 'with_customer' => false,
    ],
    'TO' => [
        'h' => 'stock_transfer_out_h', 'd' => 'stock_transfer_out_d',
        'ref_col' => 'requisition_no', 'job_col' => null,
        'detail_has_job' => false, 'with_customer' => false,
    ],
    'TI' => [
        'h' => 'stock_transfer_in_h', 'd' => 'stock_transfer_in_d',
        'ref_col' => 'location_from', 'job_col' => null,
        'detail_has_job' => false, 'with_customer' => false,
    ],
];
if (!isset($search_sources[$doc_code])) {
    $doc_code = 'MR';
}
$src = $search_sources[$doc_code];
$tbl_h = $src['h'];
$tbl_d = $src['d'];
$ref_col = $src['ref_col'];
$job_col = $src['job_col'];
$with_customer = !empty($src['with_customer']);
$detail_has_job = !empty($src['detail_has_job']);
$detail_ref_col = isset($src['detail_ref_col']) ? $src['detail_ref_col'] : null;

// Helper function to force UTF-8
function force_utf8($str)
{
    if ($str === null) {
        return '';
    }
    return mb_convert_encoding($str, 'UTF-8', 'ISO-8859-1');
}

$detail_jobs = '';
if ($detail_has_job) {
    $detail_jobs = "STUFF((SELECT DISTINCT ', ' + d.job_no
                      FROM $tbl_d d
                      WHERE d.doc_no = h.doc_no
                        AND d.company_code = h.company_code
                        AND d.branch_code = h.branch_code
                        AND d.job_no IS NOT NULL AND d.job_no <> ''
                      FOR XML PATH('')), 1, 2, '')";
}

if ($job_col !== null && $detail_has_job) {
    $job_select = "COALESCE(NULLIF(LTRIM(RTRIM(h.$job_col)), ''), $detail_jobs)";
} elseif ($job_col !== null) {
    $job_select = "NULLIF(LTRIM(RTRIM(h.$job_col)), '')";
} elseif ($detail_has_job) {
    $job_select = $detail_jobs;
} else {
    $job_select = "CAST('' AS NVARCHAR(100))";
}

$ref_select = "NULLIF(LTRIM(RTRIM(h.$ref_col)), '')";
if ($detail_ref_col !== null) {
    $detail_refs = "STUFF((SELECT DISTINCT ', ' + d.$detail_ref_col
                      FROM $tbl_d d
                      WHERE d.doc_no = h.doc_no
                        AND d.company_code = h.company_code
                        AND d.branch_code = h.branch_code
                        AND d.$detail_ref_col IS NOT NULL AND d.$detail_ref_col <> ''
                      FOR XML PATH('')), 1, 2, '')";
    $ref_select = "COALESCE($ref_select, $detail_refs)";
}

$customer_select = $with_customer
    ? ", ISNULL(am.description, '') AS customer_name"
    : ", CAST('' AS NVARCHAR(150)) AS customer_name";

$sql = "SELECT TOP 500 h.doc_no, h.doc_dt,
               $ref_select AS tracking_no,
               h.narration,
               $job_select AS job_no
               $customer_select
        FROM $tbl_h h ";
if ($with_customer) {
    $sql .= "LEFT JOIN ac_master am
                ON am.company_code = h.company_code
               AND am.ac_code = h.ac_code ";
}
$sql .= "WHERE h.company_code = ?
          AND h.branch_code = ?";

$params = array($company_code, $branch_code);

// branch_user.user_type = 'U' sees only the documents it saved (h.user_id); 'A' sees all
$session_user = isset($_SESSION['username']) ? trim($_SESSION['username']) : '';
$stmt_ut = $con->prepare("SELECT user_type FROM branch_user WHERE user_id = ? AND branch_code = ?");
$stmt_ut->execute(array($session_user, $branch_code));
$user_type = strtoupper(trim((string) $stmt_ut->fetchColumn()));
if ($user_type === 'U') {
    $sql .= " AND h.user_id = ?";
    $params[] = $session_user;
}

if ($doc_type_filter !== '') {
    $sql .= " AND h.doc_type = ?";
    $params[] = $doc_type_filter;
}

/**
 * Live search: match every field that is shown in the Find list for this doc type.
 * $type is kept for backward compatibility; empty term still returns recent docs.
 */
if ($searchTerm !== '') {
    $like = "%{$searchTerm}%";
    $or = [];
    $or[] = 'h.doc_no LIKE ?';
    $params[] = $like;
    $or[] = 'CONVERT(VARCHAR(10), h.doc_dt, 23) LIKE ?';
    $params[] = $like;
    $or[] = 'CONVERT(VARCHAR(10), h.doc_dt, 103) LIKE ?'; // dd/mm/yyyy
    $params[] = $like;
    $or[] = "ISNULL(h.narration, '') LIKE ?";
    $params[] = $like;
    $or[] = "ISNULL(h.$ref_col, '') LIKE ?";
    $params[] = $like;

    if ($with_customer) {
        $or[] = 'ISNULL(am.description, \'\') LIKE ?';
        $params[] = $like;
        $or[] = 'ISNULL(h.ac_code, \'\') LIKE ?';
        $params[] = $like;
    }
    if ($job_col !== null) {
        $or[] = "ISNULL(h.$job_col, '') LIKE ?";
        $params[] = $like;
    }
    if ($detail_has_job) {
        $or[] = "EXISTS (SELECT 1 FROM $tbl_d d
                  WHERE d.doc_no = h.doc_no AND d.company_code = h.company_code
                    AND d.branch_code = h.branch_code AND d.job_no LIKE ?)";
        $params[] = $like;
    }
    if ($detail_ref_col !== null) {
        $or[] = "EXISTS (SELECT 1 FROM $tbl_d d
                  WHERE d.doc_no = h.doc_no AND d.company_code = h.company_code
                    AND d.branch_code = h.branch_code AND d.$detail_ref_col LIKE ?)";
        $params[] = $like;
    }

    // Legacy type filters still narrow when only one type is requested with a term
    // (toolbar now prefers a single all-fields call; keep type for older callers).
    if ($type === 'tracking_no' && !$with_customer && $detail_ref_col === null) {
        // already covered by ref_col LIKE
    }

    $sql .= ' AND (' . implode(' OR ', $or) . ')';
} else {
    // Empty search: still allow optional legacy type= filters (unused when term is blank)
    if ($type === 'doc_no') {
        $sql .= ' AND h.doc_no LIKE ?';
        $params[] = '%';
    }
}

$sql .= ' ORDER BY h.doc_no DESC';

try {
    $stmt = $con->prepare($sql);
    $stmt->execute($params);

    $docs = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $dt = isset($row['doc_dt']) ? force_utf8($row['doc_dt']) : '';
        if ($dt && strpos($dt, ' ') !== false) {
            $dt = explode(' ', $dt)[0];
        }
        $docs[] = [
            'doc_no' => force_utf8($row['doc_no']),
            'doc_dt' => $dt,
            'tracking_no' => isset($row['tracking_no']) ? force_utf8($row['tracking_no']) : '',
            'job_no' => isset($row['job_no']) ? force_utf8($row['job_no']) : '',
            'narration' => isset($row['narration']) ? force_utf8($row['narration']) : '',
            'customer' => isset($row['customer_name']) ? force_utf8(trim((string) $row['customer_name'])) : '',
        ];
    }

    header('Content-Type: application/json; charset=utf-8');
    $json = json_encode($docs);

    if ($json === false) {
        echo json_encode(['error' => 'JSON Encoding Failed: ' . json_last_error_msg()]);
    } else {
        echo $json;
    }
} catch (Exception $e) {
    error_log('search_document error [' . $doc_code . ']: ' . $e->getMessage());
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(['error' => 'Search failed']);
}
?>
