<?php
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once('db.php');

$company_code = '01';
$branch_code = '%';
$doc_no_esc = 'test';
$location_code = '001';

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
ON b.itemcode = v.itemcode AND v.locationcode = '$location_code'
LEFT JOIN ac_subledger 
ON b.acsubcode = ac_subledger.ac_subcode
WHERE (b.companycode = '$company_code') 
AND (b.branchcode LIKE '$branch_code') 
AND (b.docno = '$doc_no_esc')
ORDER BY b.itemno ASC";

$res = odbc_exec($con, $sql);
if (!$res) {
    echo "ERROR: " . odbc_errormsg($con);
} else {
    echo "SUCCESS";
}
?>