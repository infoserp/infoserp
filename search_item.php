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

$searchTerm = isset($_GET['term']) ? $_GET['term'] : '';

// Helper function to force UTF-8
function force_utf8($str)
{
    if ($str === null)
        return '';
    return mb_convert_encoding($str, 'UTF-8', 'ISO-8859-1');
}

// 1. Get company/branch from session
$company_code = isset($_SESSION['company_code']) ? $_SESSION['company_code'] : '';
$branch_code = isset($_SESSION['branch_code']) ? $_SESSION['branch_code'] : '';
$location_code = '';

try {
    // 2. Fetch default location_code from control_table
    if ($company_code && $branch_code) {
        $sql_loc = "SELECT location_code FROM control_table 
                                    WHERE company_code = ? 
                                        AND branch_code = ?";
            $stmt = $con->prepare($sql_loc);
            $stmt->execute(array($company_code, $branch_code));
        $row_loc = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row_loc && isset($row_loc['location_code'])) {
            $location_code = $row_loc['location_code'];
            $_SESSION['location_code'] = $location_code;
        }
    }

    // 3. Search Items with Stock JOIN
    $sql = "SELECT i.*, v.stockqty 
            FROM item_master i
            LEFT JOIN view_item_ledger_summary v 
        ON i.item_code = v.itemcode AND v.locationcode = ?
        WHERE i.item_code LIKE ? 
           OR i.item_name LIKE ? 
           OR i.oem_code LIKE ?";

    $stmt = $con->prepare($sql);
    $like = "%{$searchTerm}%";
    $stmt->execute(array($location_code, $like, $like, $like));

    $items = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $stock_qty = isset($row['stockqty']) ? (float) $row['stockqty'] : 0;

        $items[] = [
            'item_code' => force_utf8($row['item_code']),
            'oem_code' => isset($row['oem_code']) ? force_utf8($row['oem_code']) : '',
            'item_name' => isset($row['item_name']) ? force_utf8($row['item_name']) : '',
            'unit' => isset($row['stock_unit']) ? force_utf8($row['stock_unit']) : (isset($row['unit']) ? force_utf8($row['unit']) : ''),
            'stock' => $stock_qty
        ];
    }

    // Ensure clean output
    header('Content-Type: application/json; charset=utf-8');
    $json = json_encode($items);

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