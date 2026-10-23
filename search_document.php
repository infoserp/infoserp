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

// Tables searched per doc_code (whitelist — table/column names are never taken from the request).
//   ref_col : header column returned as tracking_no and searched by the "tracking_no" option
//   job_col : header job column (searched together with the detail lines' job_no), or null
$search_sources = [
    'MR' => ['h' => 'material_request_h', 'd' => 'material_request_d', 'ref_col' => 'tracking_no', 'job_col' => null],
    'SI' => ['h' => 'invoice_h',          'd' => 'invoice_d',          'ref_col' => 'tracking_no', 'job_col' => 'job_no'],
    'RV' => ['h' => 'chq_receipt_h',      'd' => 'chq_receipt_d',      'ref_col' => 'chq_no',      'job_col' => null],
    'TO' => ['h' => 'stock_transfer_out_h', 'd' => 'stock_transfer_out_d', 'ref_col' => 'requisition_no', 'job_col' => null],
    'TI' => ['h' => 'stock_transfer_in_h',  'd' => 'stock_transfer_in_d',  'ref_col' => 'location_from',  'job_col' => null],
];
if (!isset($search_sources[$doc_code])) {
    $doc_code = 'MR';
}
$src = $search_sources[$doc_code];
$tbl_h = $src['h'];
$tbl_d = $src['d'];
$ref_col = $src['ref_col'];
$job_col = $src['job_col'];

// Helper function to force UTF-8
function force_utf8($str)
{
    if ($str === null)
        return '';
    return mb_convert_encoding($str, 'UTF-8', 'ISO-8859-1');
}

$detail_jobs = "STUFF((SELECT DISTINCT ', ' + d.job_no 
                      FROM $tbl_d d 
                      WHERE d.doc_no = h.doc_no 
                        AND d.company_code = h.company_code 
                        AND d.branch_code = h.branch_code
                        AND d.job_no IS NOT NULL AND d.job_no <> ''
                      FOR XML PATH('')), 1, 2, '')";
$job_select = $job_col !== null
    ? "COALESCE(NULLIF(LTRIM(RTRIM(h.$job_col)), ''), $detail_jobs)"
    : $detail_jobs;

$sql = "SELECT TOP 500 h.doc_no, h.doc_dt, h.$ref_col AS tracking_no, h.narration, 
               $job_select AS job_no
        FROM $tbl_h h 
        WHERE h.company_code = ? 
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

if ($type === 'doc_no') {
    $sql .= " AND h.doc_no LIKE ?";
    $params[] = "%{$searchTerm}%";
} else if ($type === 'tracking_no') {
    $sql .= " AND h.$ref_col LIKE ?";
    $params[] = "%{$searchTerm}%";
} else if ($type === 'job_no') {
    $job_detail_match = "EXISTS (SELECT 1 FROM $tbl_d d WHERE d.doc_no = h.doc_no AND d.company_code = h.company_code AND d.branch_code = h.branch_code AND d.job_no LIKE ?)";
    if ($job_col !== null) {
        $sql .= " AND (h.$job_col LIKE ? OR $job_detail_match)";
        $params[] = "%{$searchTerm}%";
    } else {
        $sql .= " AND $job_detail_match";
    }
    $params[] = "%{$searchTerm}%";
}

$sql .= " ORDER BY h.doc_no DESC";

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
        'narration' => isset($row['narration']) ? force_utf8($row['narration']) : ''
    ];
}

header('Content-Type: application/json; charset=utf-8');
$json = json_encode($docs);

if ($json === false) {
    echo json_encode(["error" => "JSON Encoding Failed: " . json_last_error_msg()]);
} else {
    echo $json;
}
?>
