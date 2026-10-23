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

try {
    $sql = "SELECT vehicle_no, plate_no, plate_code 
            FROM vehicle_master 
        WHERE vehicle_no LIKE ? 
           OR plate_no LIKE ? 
           OR plate_code LIKE ?
            ORDER BY vehicle_no ASC";

    $stmt = $con->prepare($sql);
    $like = "%{$searchTerm}%";
    $stmt->execute(array($like, $like, $like));

    $vehicles = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $vehicles[] = [
            'vehicle_no' => force_utf8($row['vehicle_no']),
            'plate_no' => isset($row['plate_no']) ? force_utf8($row['plate_no']) : '',
            'plate_code' => isset($row['plate_code']) ? force_utf8($row['plate_code']) : ''
        ];
    }

    header('Content-Type: application/json; charset=utf-8');
    $json = json_encode($vehicles);

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