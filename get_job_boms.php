<?php
ob_start();
error_reporting(0);
require('db.php');
ob_clean();

$company_code = isset($_SESSION['company_code']) ? $_SESSION['company_code'] : '';
$branch_code = isset($_SESSION['branch_code']) ? $_SESSION['branch_code'] : '';
$doc_no = isset($_GET['doc_no']) ? $_GET['doc_no'] : '';

function force_utf8($str)
{
    if ($str === null)
        return '';
    return mb_convert_encoding($str, 'UTF-8', 'ISO-8859-1');
}

// Fetch default location_code from control_table
$location_code = isset($_SESSION['location_code']) ? $_SESSION['location_code'] : '';
if (!$location_code && $company_code && $branch_code) {
    $sql_loc = "SELECT location_code FROM control_table 
                WHERE company_code = ? 
                  AND branch_code = ?";
    $stmt_loc = $con->prepare($sql_loc);
    $stmt_loc->execute(array($company_code, $branch_code));
    $row_loc = $stmt_loc->fetch(PDO::FETCH_ASSOC);
    if ($row_loc && isset($row_loc['location_code'])) {
        $location_code = $row_loc['location_code'];
        $_SESSION['location_code'] = $location_code;
    }
}

$with_activity = isset($_GET['with_activity']) ? (int) $_GET['with_activity'] : 0;

if ($with_activity === 1) {
    $sql = "SELECT DISTINCT space(1) as selected, 
    ac_subledger.ac_name,
    (b.txnqty) as txnqty, 
    (b.txnunit) as unit, 0.000 as qty, 
    b.companycode, b.branchcode, 
    b.docno, b.itemno, 
    b.acsubcode, b.itemcode, 
    b.itemname, v.stockqty 
    FROM view_jc_bom_mr_balance_summary b
    LEFT JOIN view_item_ledger_summary v 
    ON b.itemcode = v.itemcode AND v.locationcode = ?
    LEFT JOIN ac_subledger 
    ON b.acsubcode = ac_subledger.ac_subcode
    WHERE (b.companycode = ?) 
    AND (b.branchcode LIKE ?) 
    AND (b.docno = ?)
    ORDER BY b.itemno ASC";
    $params = array($location_code, $company_code, $branch_code, $doc_no);
} else {
    $sql = "SELECT DISTINCT space(1) as selected, 
    (b.txnqty) as txnqty, 
    (b.txnunit) as unit, 0.000 as qty, 
    b.companycode, b.branchcode, 
    b.docno, b.itemno, 
    b.acsubcode, b.itemcode, 
    b.itemname, v.stockqty 
    FROM view_jc_bom_mr_balance_summary b
    LEFT JOIN view_item_ledger_summary v 
    ON b.itemcode = v.itemcode AND v.locationcode = ?
    WHERE (b.companycode = ?) 
    AND (b.branchcode LIKE ?) 
    AND (b.docno = ?)
    ORDER BY b.itemno ASC";
    $params = array($location_code, $company_code, $branch_code, $doc_no);
}

$stmt = $con->prepare($sql);
$stmt->execute($params);

$boms = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $boms[] = [
        'itemno' => force_utf8($row['itemno']),
        'acsubcode' => isset($row['acsubcode']) ? force_utf8($row['acsubcode']) : '',
        'acname' => isset($row['ac_name']) ? force_utf8($row['ac_name']) : '',
        'itemcode' => force_utf8($row['itemcode']),
        'itemname' => force_utf8($row['itemname']),
        'txnqty' => floatval($row['txnqty']),
        'unit' => force_utf8($row['unit']),
        'qty' => floatval($row['qty']),
        'stockqty' => isset($row['stockqty']) ? floatval($row['stockqty']) : 0.00
    ];
}

header('Content-Type: application/json; charset=utf-8');
$json = json_encode($boms);

if ($json === false) {
    echo json_encode(["error" => "JSON Encoding Failed: " . json_last_error_msg()]);
} else {
    echo $json;
}
?>