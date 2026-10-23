<?php
/**
 * Dashboard / menu approval counts — same SQL as approval_menu_data.php.
 * Used by api/dashboard_counts.php only (not on initial page load).
 *
 * Suggested indexes (run manually if counts are slow):
 * -- material_request_d: (company_code, branch_code, approved, cancelled) INCLUDE (doc_no)
 * -- purchase_request_d: (company_code, branch_code, approved, cancelled) INCLUDE (doc_no)
 * -- purchase_order_h: (company_code, branch_code, cancelled, approved, confirmed)
 * -- sales_quotation_h: (company_code, branch_code, approved, cancelled)
 * -- sales_order_h: (company_code, branch_code, approved, cancelled)
 * -- proforma_h: (company_code, branch_code, approved_by)
 * -- delivery_request_h: (company_code, branch_code, approved, cancelled)
 * -- delivery_note_h: (company_code, branch_code, despatch, despatch_to)
 */

if (!function_exists('erp_fetch_dashboard_counts')) {

    function erp_fetch_dashboard_counts(PDO $con, array $session) {
        $company_code = $session['company_code'] ?? '';
        $branch_code  = $session['branch_code'] ?? '';
        $user         = $session['username'] ?? '';

        $by_key = [];

        if (($session['MR'] ?? '') === 'Y') {
            $QUERY = "SELECT COUNT(*) AS cnt
               FROM ( SELECT 1 AS Expr1
                   FROM material_request_h INNER JOIN
                   material_request_d ON material_request_h.company_code = material_request_d.company_code AND material_request_h.branch_code = material_request_d.branch_code AND
                   material_request_h.doc_no = material_request_d.doc_no
                   WHERE material_request_h.company_code = '$company_code' AND material_request_h.branch_code = '$branch_code'
                AND material_request_d.approved = 'N' AND material_request_d.cancelled = 'N'
                   GROUP BY material_request_h.doc_no, material_request_h.company_code, material_request_h.branch_code) AS Q";
            $stmt = $con->prepare($QUERY);
            $stmt->execute([$company_code, $branch_code]);
            $by_key['MR'] = (int) ($stmt->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0);
        }

        if (($session['PR'] ?? '') === 'Y') {
            $QUERY = "SELECT COUNT(*) AS cnt
               FROM (SELECT COUNT(DISTINCT purchase_request_d.doc_no) AS Expr1
               FROM purchase_request_d INNER JOIN
               purchase_request_h ON purchase_request_d.company_code = purchase_request_h.company_code AND purchase_request_d.branch_code = purchase_request_h.branch_code AND
               purchase_request_d.doc_no = purchase_request_h.doc_no
               WHERE (purchase_request_h.company_code = '$company_code') AND
              (purchase_request_h.branch_code = '$branch_code') AND
              (purchase_request_d.cancelled = 'N') AND (purchase_request_d.approved = 'N')
               GROUP BY purchase_request_h.doc_no, purchase_request_h.company_code, purchase_request_h.branch_code) AS Q";
            $stmt = $con->prepare($QUERY);
            $stmt->execute([$company_code, $branch_code]);
            $by_key['PR'] = (int) ($stmt->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0);
        }

        if (($session['PO'] ?? '') === 'Y') {
            $QUERY = "SELECT COUNT(*) AS cnt
               FROM purchase_order_h
               WHERE (company_code = '$company_code')
                 AND (branch_code = '$branch_code') AND (cancelled = 'N') AND (approved = 'N') AND (confirmed = 'Y') AND (rejected = 'N' OR rejected IS NULL)";
            $stmt = $con->prepare($QUERY);
            $stmt->execute([$company_code, $branch_code]);
            $by_key['PO'] = (int) ($stmt->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0);
        }

        if (($session['QT'] ?? '') === 'Y') {
            $QUERY = "SELECT COUNT(*) AS cnt
               FROM sales_quotation_h
               WHERE (sales_quotation_h.company_code = '$company_code')
                 AND (sales_quotation_h.branch_code = '$branch_code')
                 AND (sales_quotation_h.approved = 'N') AND (sales_quotation_h.cancelled = 'N')";
            $stmt = $con->prepare($QUERY);
            $stmt->execute([$company_code, $branch_code]);
            $by_key['QT'] = (int) ($stmt->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0);
        }

        if (($session['SOC'] ?? '') === 'Y') {
            $QUERY = "SELECT COUNT(*) AS cnt
                FROM sales_order_h
                WHERE (sales_order_h.company_code = '$company_code') AND
                (sales_order_h.branch_code = '$branch_code') AND
                (sales_order_h.approved = 'N') AND (sales_order_h.cancelled = 'N')";
            $stmt = $con->prepare($QUERY);
            $stmt->execute([$company_code, $branch_code]);
            $by_key['SOC'] = (int) ($stmt->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0);
        }

        if (($session['SO'] ?? '') === 'Y') {
            $QUERY = "SELECT COUNT(*) AS cnt
                FROM sales_order_h
                WHERE (sales_order_h.company_code = '$company_code') AND
                (sales_order_h.branch_code = '$branch_code') AND
                (sales_order_h.approved = 'N') AND (sales_order_h.cancelled = 'N')";
            $stmt = $con->prepare($QUERY);
            $stmt->execute([$company_code, $branch_code]);
            $by_key['SO'] = (int) ($stmt->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0);
        }

        if (($session['PI'] ?? '') === 'Y') {
            $QUERY = "SELECT count(*) as cnt
               FROM ac_master INNER JOIN proforma_h ON ac_master.company_code = proforma_h.company_code
               AND ac_master.ac_code = proforma_h.ac_code
               WHERE (proforma_h.company_code = '$company_code') AND
               (proforma_h.branch_code = '$branch_code')
               AND (proforma_h.approved_by IS NULL)";
            $stmt = $con->prepare($QUERY);
            $stmt->execute([$company_code, $branch_code]);
            $by_key['PI'] = (int) ($stmt->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0);
        }

        if (($session['DR'] ?? '') === 'Y') {
            $QUERY = "SELECT count(*) as cnt
              FROM delivery_request_h
              WHERE delivery_request_h.company_code = '$company_code'
              AND delivery_request_h.branch_code = '$branch_code'
              AND delivery_request_h.approved = 'N'
              AND delivery_request_h.cancelled = 'N'";
            $stmt = $con->prepare($QUERY);
            $stmt->execute([$company_code, $branch_code]);
            $by_key['DR'] = (int) ($stmt->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0);
        }

        if (($session['DD'] ?? '') === 'Y') {
            $QUERY = "SELECT COUNT(*) AS cnt
                      FROM delivery_note_h dnh
                      WHERE dnh.company_code = :company_code
                        AND dnh.branch_code  = :branch_code
                        AND dnh.despatch     = 'N'
                        AND dnh.despatch_to  = :user_id";
            $stmt = $con->prepare($QUERY);
            $stmt->execute([
                ':company_code' => $company_code,
                ':branch_code'  => $branch_code,
                ':user_id'      => $user,
            ]);
            $by_key['DD'] = (int) ($stmt->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0);
        }

        $unpaid_invoices  = 0;
        $proforma_pending = 0;

        if (($session['allow_dashboard'] ?? '') === 'Y' && ($session['message_sales'] ?? '') === 'Y') {
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
                AND ROUND(vsi.inv_amount, 2) <> ROUND(ISNULL(viss.balance, 0), 2)";
            try {
                $stmt = $con->prepare($QUERY_UNPAID);
                $stmt->execute([$company_code, $branch_code]);
                $unpaid_invoices = (int) ($stmt->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0);
            } catch (Exception $e) {
                $unpaid_invoices = 0;
            }

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
                AND ISNULL(vpss.balance,0) > 0";
            try {
                $stmt = $con->prepare($QUERY_PROFORMA);
                $stmt->execute([$company_code, $branch_code]);
                $proforma_pending = (int) ($stmt->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0);
            } catch (Exception $e) {
                $proforma_pending = 0;
            }

            $by_key['UNPAID']   = $unpaid_invoices;
            $by_key['PROFORMA'] = $proforma_pending;
        }

        return [
            'material_request_approval'  => (int) ($by_key['MR'] ?? 0),
            'purchase_request'             => (int) ($by_key['PR'] ?? 0),
            'purchase_order_approval'      => (int) ($by_key['PO'] ?? 0),
            'quotation_approval'           => (int) ($by_key['QT'] ?? 0),
            'sales_order_confirmation'     => (int) ($by_key['SOC'] ?? 0),
            'sales_order'                  => (int) ($by_key['SO'] ?? 0),
            'proforma_invoice'             => (int) ($by_key['PI'] ?? 0),
            'delivery_request'             => (int) ($by_key['DR'] ?? 0),
            'do_dispatch'                  => (int) ($by_key['DD'] ?? 0),
            'unpaid_invoices'              => $unpaid_invoices,
            'proforma_pending'             => $proforma_pending,
            'by_key'                       => $by_key,
        ];
    }
}
