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

$company_code = isset($_SESSION['company_code']) ? $_SESSION['company_code'] : '';
$branch_code = isset($_SESSION['branch_code']) ? $_SESSION['branch_code'] : '';
$searchTerm = isset($_GET['term']) ? $_GET['term'] : '';

function force_utf8($str)
{
    if ($str === null)
        return '';
    return mb_convert_encoding($str, 'UTF-8', 'ISO-8859-1');
}

try {
    $sql = "SELECT DISTINCT ac_subcode, ac_name 
            FROM ac_subledger 
            WHERE company_code = ?";

    $params = array($company_code);

    if ($searchTerm !== '') {
        $sql .= " AND (ac_subcode LIKE ? OR ac_name LIKE ?)";
        $like = "%{$searchTerm}%";
        $params[] = $like;
        $params[] = $like;
    }

    $sql .= " ORDER BY ac_name ASC";
    $stmt = $con->prepare($sql);
    $stmt->execute($params);

    $activities = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $activities[] = [
            'ac_subcode' => force_utf8($row['ac_subcode']),
            'ac_name' => force_utf8($row['ac_name'])
        ];
    }

    header('Content-Type: application/json; charset=utf-8');
    $json = json_encode($activities);

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