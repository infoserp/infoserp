<?php
// API mode: prevent db.php from die()-ing on connection failure
define('API_JSON_MODE', true);

ob_start();
error_reporting(0);

require('db.php');

ob_clean();

// If DB connection failed, return JSON error
if (!isset($con) || !$con) {
    header('Content-Type: application/json; charset=utf-8');
    $msg = isset($db_error_message) ? $db_error_message : 'Database connection not available. Please log in again.';
    echo json_encode(["error" => $msg]);
    exit;
}

$company_code = isset($_SESSION['company_code']) ? $_SESSION['company_code'] : '';
$branch_code = isset($_SESSION['branch_code']) ? $_SESSION['branch_code'] : '';
$searchTerm = isset($_GET['term']) ? $_GET['term'] : '';

function force_utf8($str)
{
    if ($str === null)
        return '';
    return mb_convert_encoding($str, 'UTF-8', 'ISO-8859-1');
}

$sql = "SELECT DISTINCT view_jc_bom_mr_balance_summary.companycode, view_jc_bom_mr_balance_summary.branchcode, 
view_jc_bom_mr_balance_summary.docno, job_card_h.doc_dt 
FROM job_card_h, view_jc_bom_mr_balance_summary 
WHERE (job_card_h.company_code = view_jc_bom_mr_balance_summary.companycode) 
AND (job_card_h.branch_code = view_jc_bom_mr_balance_summary.branchcode) 
AND (job_card_h.doc_no = view_jc_bom_mr_balance_summary.docno) 
AND ((view_jc_bom_mr_balance_summary.companycode = ?) 
AND (view_jc_bom_mr_balance_summary.branchcode LIKE ?) 
AND (job_card_h.ac_code LIKE '%') 
AND (job_card_h.doc_type LIKE '%') 
AND (isnull(job_card_h.bom_closed,'N') = 'N'))";

$params = array($company_code, $branch_code);

if ($searchTerm !== '') {
    $sql .= " AND view_jc_bom_mr_balance_summary.docno LIKE ?";
    $params[] = "%{$searchTerm}%";
}

$sql .= " ORDER BY job_card_h.doc_dt DESC";

try {
    $stmt = $con->prepare($sql);
    $stmt->execute($params);

    $jobs = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $dt = isset($row['doc_dt']) ? force_utf8($row['doc_dt']) : '';
        if ($dt && strpos($dt, ' ') !== false) {
            $dt = explode(' ', $dt)[0];
        }

        $jobs[] = [
            'docno' => force_utf8($row['docno']),
            'doc_dt' => $dt
        ];
    }

    header('Content-Type: application/json; charset=utf-8');
    $json = json_encode($jobs);

    if ($json === false) {
        echo json_encode(["error" => "JSON Encoding Failed: " . json_last_error_msg()]);
    } else {
        echo $json;
    }
} catch (Exception $e) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(200);
    echo json_encode(["error" => $e->getMessage()]);
}
?>