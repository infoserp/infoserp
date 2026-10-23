<?php
define('API_JSON_MODE', true);

ob_start();
error_reporting(0);

require('db.php');

ob_clean();

if (!isset($con) || !$con) {
    header('Content-Type: application/json; charset=utf-8');
    $msg = isset($db_error_message) ? $db_error_message : 'Database connection not available. Please log in again.';
    echo json_encode(["error" => $msg]);
    exit;
}

$searchTerm = isset($_GET['term']) ? $_GET['term'] : '';

function force_utf8($str)
{
    if ($str === null)
        return '';
    return mb_convert_encoding($str, 'UTF-8', 'ISO-8859-1');
}

$company_code = isset($_SESSION['company_code']) ? $_SESSION['company_code'] : '';
$branch_code = isset($_SESSION['branch_code']) ? $_SESSION['branch_code'] : '';

try {
    $sql = "SELECT j.doc_no, j.doc_dt, j.ac_code, a.description as ac_name 
            FROM job_card_h j
            LEFT JOIN ac_master a ON j.ac_code = a.ac_code
        WHERE j.company_code = ? 
          AND j.branch_code = ?
          AND (j.doc_no LIKE ? 
               OR a.description LIKE ?)
            ORDER BY j.doc_dt DESC";

    $stmt = $con->prepare($sql);
    $like = "%{$searchTerm}%";
    $stmt->execute(array($company_code, $branch_code, $like, $like));

    $jobs = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $dt = isset($row['doc_dt']) ? force_utf8($row['doc_dt']) : '';
        if ($dt && strpos($dt, ' ') !== false) {
            $dt = explode(' ', $dt)[0];
        }

        $jobs[] = [
            'doc_no' => force_utf8($row['doc_no']),
            'doc_dt' => $dt,
            'ac_code' => isset($row['ac_code']) ? force_utf8($row['ac_code']) : '',
            'ac_name' => isset($row['ac_name']) ? force_utf8($row['ac_name']) : ''
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
    echo json_encode(["error" => $e->getMessage()]);
}
?>