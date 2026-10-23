<?php
require('db.php');
include("auth.php");

header('Content-Type: application/json');

if (!isset($_SESSION['allow_dashboard']) || $_SESSION['allow_dashboard'] !== 'Y' ||
    !isset($_SESSION['message_sales']) || $_SESSION['message_sales'] !== 'Y') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$company_code = $_SESSION["company_code"];
$branch_code = $_SESSION["branch_code"];

$response = [
    'unpaid_count' => 0,
    'proforma_count' => 0
];

// 1. Unpaid Invoices
$QUERY_UNPAID = "
    SELECT COUNT(DISTINCT vsi.doc_no) AS cnt
    FROM view_sales_invoice vsi
    LEFT JOIN view_invoice_settled_summary viss
        ON vsi.company_code = viss.companycode
        AND vsi.branch_code  = viss.branchcode
        AND vsi.doc_no       = viss.invoiceno
        AND vsi.ac_code      = viss.accode
    INNER JOIN ac_master am
        ON vsi.company_code = am.company_code
        AND vsi.ac_code     = am.ac_code
    WHERE vsi.company_code = ?
    AND vsi.branch_code  = ?
    AND ROUND(vsi.inv_amount, 2) <> ROUND(ISNULL(viss.balance, 0), 2)
";

try {
    $stmt_unpaid = $con->prepare($QUERY_UNPAID);
    $stmt_unpaid->execute(array($company_code, $branch_code));
    $row_unpaid = $stmt_unpaid->fetch(PDO::FETCH_ASSOC);
    $response['unpaid_count'] = isset($row_unpaid['cnt']) ? (int)$row_unpaid['cnt'] : 0;
} catch (Exception $e) {
    
}

// 2. Proforma Invoices
$QUERY_PROFORMA = "
    SELECT COUNT(DISTINCT ph.doc_no) AS cnt
    FROM proforma_h ph
    INNER JOIN ac_master am
        ON ph.company_code = am.company_code
        AND ph.ac_code = am.ac_code
    INNER JOIN view_pfi_si_settled_summary vpss
        ON ph.company_code = vpss.companycode
        AND ph.branch_code  = vpss.branchcode
        AND ph.doc_no       = vpss.invoiceno
    WHERE ph.company_code = ?
    AND ph.branch_code = ?
    AND ISNULL(vpss.balance,0) > 0
";

try {
    $stmt_proforma = $con->prepare($QUERY_PROFORMA);
    $stmt_proforma->execute(array($company_code, $branch_code));
    $row_proforma = $stmt_proforma->fetch(PDO::FETCH_ASSOC);
    $response['proforma_count'] = isset($row_proforma['cnt']) ? (int)$row_proforma['cnt'] : 0;
} catch (Exception $e) {
    
}

echo json_encode($response);
?>
