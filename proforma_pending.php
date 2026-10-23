<?php
require('db.php');
include("auth.php");
require_once __DIR__ . '/includes/erp_paths.php';
erp_guard_standalone_get();

$company_code = $_SESSION["company_code"];
$branch_code  = $_SESSION["branch_code"];

// Query for Proforma Invoices Pending (only those that exist in settled summary)
$QUERY = "
    SELECT 
        ph.doc_no,
        ph.doc_dt,
        ph.doc_type,
        am.description AS customer,
        ph.amount,
        ph.discount_amount,
        ISNULL(vpss.balance,0) AS bal_amount,
        e.salesman_name,       -- Correct column name
        am.salesman_code,
        ph.remark
    FROM proforma_h ph
    INNER JOIN ac_master am
        ON ph.company_code = am.company_code
        AND ph.ac_code = am.ac_code
    INNER JOIN view_pfi_si_settled_summary vpss
        ON ph.company_code = vpss.companycode
        AND ph.branch_code  = vpss.branchcode
        AND ph.doc_no       = vpss.invoiceno
    LEFT JOIN salesman e
        ON am.salesman_code = e.salesman_code
    WHERE ph.company_code = ?
    AND ph.branch_code  = ?
    AND ISNULL(vpss.balance,0) > 0
    ORDER BY ph.doc_dt ASC
";

$stmt = $con->prepare($QUERY);
$stmt->execute(array($company_code, $branch_code));

// Render page — same shell and list styles as the approval pages
$user                  = $_SESSION['username'] ?? '';
$page_title            = 'Proforma Invoice Pending';
$active_page           = 'PROFORMA';
$defer_approval_counts = true;
include __DIR__ . '/includes/approval_layout_start.php';
?>
    <div class="al-table-wrap">
    <table class="al-table">
        <thead>
            <tr>
                <td colspan="10"><h5>Proforma Invoice Pending</h5></td>
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

            // Format doc date
            $doc_dt = '';
            if (!empty($row['doc_dt'])) {
                $dt = strtotime($row['doc_dt']);
                $doc_dt = $dt ? date('d/m/Y', $dt) : htmlspecialchars($row['doc_dt']);
            }

            // Salesman display
            $salesman = $row['salesman_name']
                ? $row['salesman_name'] . " (" . $row['salesman_code'] . ")"
                : $row['salesman_code'];

            echo "<tr>
                    <td class='text-center'>{$i}</td>
                    <td>{$doc_dt}</td>
                    <th scope='row'>".htmlspecialchars($row['doc_no'])."</th>
                    <td>".htmlspecialchars($row['doc_type'])."</td>
                    <td>".htmlspecialchars($row['customer'])."</td>
                    <td>".htmlspecialchars($salesman)."</td>
                    <td class='text-right'>".number_format($row['amount'],2)."</td>
                    <td class='text-right'>0.00</td>
                    <td class='text-right'>".number_format($row['bal_amount'],2)."</td>
                    <td>".htmlspecialchars($row['remark'])."</td>
                  </tr>";
        }

        if ($i == 0) {
            echo "<tr><td colspan='10' class='text-center text-slate-400'>No pending proforma invoices found.</td></tr>";
        }
        ?>
        </tbody>
    </table>
    </div>
<?php include __DIR__ . '/includes/approval_layout_end.php'; ?>
