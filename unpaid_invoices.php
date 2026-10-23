<?php
// unpaid_invoices.php
require('db.php');
include("auth.php");
require_once __DIR__ . '/includes/erp_paths.php';
erp_guard_standalone_get();

// Permission check

$company_code = $_SESSION["company_code"];
$branch_code  = $_SESSION["branch_code"];


// Query to fetch unpaid invoice rows
$LIST_QUERY = "
    SELECT
        vsi.doc_no,
        vsi.doc_dt,
        vsi.doc_type,
        vsi.ac_code,
        am.description AS customer,
        vsi.salesman_code,
        ISNULL(s.salesman_name, '') AS salesman_name,
        vsi.inv_amount,
        ISNULL(viss.balance, 0) AS paid_amount,
        (vsi.inv_amount - ISNULL(viss.balance, 0)) AS bal_amount,
        ih.remark
    FROM view_sales_invoice vsi
    LEFT JOIN view_invoice_settled_summary viss
        ON vsi.company_code = viss.companycode
       AND vsi.branch_code  = viss.branchcode
       AND vsi.doc_no       = viss.invoiceno
       AND vsi.ac_code      = viss.accode
    INNER JOIN ac_master am
        ON vsi.company_code = am.company_code
       AND vsi.ac_code     = am.ac_code
    LEFT JOIN salesman s
        ON vsi.company_code = s.company_code
       AND vsi.salesman_code = s.salesman_code
    LEFT JOIN invoice_h ih
        ON vsi.company_code = ih.company_code
       AND vsi.branch_code  = ih.branch_code
       AND vsi.doc_no       = ih.doc_no
    WHERE vsi.company_code = '$company_code'
      AND vsi.branch_code  = '$branch_code'
      AND ROUND(vsi.inv_amount, 2) <> ROUND(ISNULL(viss.balance, 0), 2)
    ORDER BY vsi.doc_dt, vsi.doc_no
";

$stmt = $con->prepare($LIST_QUERY);
$stmt->execute(array($company_code, $branch_code));

// Render page — same shell and list styles as the approval pages
$user                  = $_SESSION['username'] ?? '';
$page_title            = 'Unpaid Invoices - Followup';
$active_page           = 'UNPAID';
$defer_approval_counts = true;
include __DIR__ . '/includes/approval_layout_start.php';
?>
        <div class="al-table-wrap">
        <table class="al-table">
            <thead>
                <tr>
                    <td colspan="10"><h5>Unpaid Invoices for Followup</h5></td>
                </tr>
                <tr>
                    <th class="text-center">S.No</th>
                    <th>Doc Date</th>
                    <th>Doc No</th>
                    <th>Doc Type</th>
                    <th>Customer</th>
                    <th>Salesman</th>
                    <th class="text-right">Amount</th>
                    <th class="text-right">Paid</th>
                    <th class="text-right">Balance</th>
                    <th>Remark</th>
                </tr>
            </thead>

            <tbody>
            <?php
            $i = 0;
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $i++;

                $doc_dt = '';
                if (!empty($row['doc_dt'])) {
                    $dt = strtotime($row['doc_dt']);
                    $doc_dt = $dt ? date('d/m/Y', $dt) : htmlspecialchars($row['doc_dt']);
                }

                $salesman = $row['salesman_name']
                    ? $row['salesman_name'] . " (" . $row['salesman_code'] . ")"
                    : $row['salesman_code'];

                echo "<tr>
                    <td class='text-center'>{$i}</td>
                    <td>{$doc_dt}</td>
                    <th scope='row'>{$row['doc_no']}</th>
                    <td>{$row['doc_type']}</td>
                    <td>".htmlspecialchars($row['customer'])."</td>
                    <td>".htmlspecialchars($salesman)."</td>
                    <td class='text-right'>".number_format($row['inv_amount'],2)."</td>
                    <td class='text-right'>".number_format($row['paid_amount'],2)."</td>
                    <td class='text-right'>".number_format($row['bal_amount'],2)."</td>
                   <td>".htmlspecialchars($row['remark'])."</td>
                </tr>";
            }

            if ($i == 0) {
                echo "<tr><td colspan='10' class='text-center text-slate-400'>No unpaid invoices found.</td></tr>";
            }
            ?>
            </tbody>
        </table>
        </div>
<?php include __DIR__ . '/includes/approval_layout_end.php'; ?>