<?php
/**
 * Dashboard pending approvals list — same filters as approvalline.php / approval_menu_data.php.
 */

if (!function_exists('erp_fetch_pending_approvals')) {

    function erp_format_pending_date($doc_dt) {
        $doc_dt = trim((string) $doc_dt);
        if ($doc_dt === '') {
            return '—';
        }
        $ts = strtotime(str_replace('-', ' ', $doc_dt));
        return $ts ? date('M j, Y', $ts) : $doc_dt;
    }

    function erp_pending_sort_ts($doc_dt) {
        $doc_dt = trim((string) $doc_dt);
        if ($doc_dt === '') {
            return 0;
        }
        $ts = strtotime(str_replace('-', ' ', $doc_dt));
        return $ts ?: 0;
    }

    function erp_pending_push(array &$rows, $doc_no, $doc_type, $type_label, $created_by, $doc_dt) {
        $doc_no = trim((string) $doc_no);
        if ($doc_no === '') {
            return;
        }
        $rows[] = [
            'doc'      => $doc_no,
            'doc_type' => $doc_type,
            'type'     => $type_label,
            'by'       => trim((string) $created_by) !== '' ? trim((string) $created_by) : '—',
            'date'     => erp_format_pending_date($doc_dt),
            'sort_ts'  => erp_pending_sort_ts($doc_dt),
            'status'   => 'Pending',
            'href'     => erp_iframe_url(
                'viewdetailed?doc_type=' . rawurlencode($doc_type) . '&doc_id=' . rawurlencode($doc_no)
            ),
        ];
    }

    /**
     * @return array{rows: array<int, array>, total: int}
     */
    function erp_fetch_pending_approvals(PDO $con, array $session, $limit = 10) {
        require_once __DIR__ . '/approval_count_queries.php';

        $company_code = $session['company_code'] ?? '';
        $branch_code  = $session['branch_code'] ?? '';
        $limit        = max(1, (int) $limit);
        $rows         = [];

        if ($company_code === '' || $branch_code === '') {
            return ['rows' => [], 'total' => 0];
        }

        if (($session['MR'] ?? '') === 'Y') {
            $stmt = $con->prepare(
                "SELECT TOP 10 material_request_h.doc_no,
                        replace(convert(char(11), material_request_h.doc_dt,113),' ','-') AS doc_dt,
                        material_request_h.request_by
                 FROM material_request_h
                 INNER JOIN material_request_d
                    ON material_request_h.company_code = material_request_d.company_code
                   AND material_request_h.branch_code  = material_request_d.branch_code
                   AND material_request_h.doc_no       = material_request_d.doc_no
                 WHERE material_request_h.company_code = ?
                   AND material_request_h.branch_code  = ?
                   AND material_request_d.approved  = 'N'
                   AND material_request_d.cancelled = 'N'
                 GROUP BY material_request_h.doc_no, material_request_h.doc_dt, material_request_h.request_by
                 ORDER BY material_request_h.doc_dt DESC, material_request_h.doc_no DESC"
            );
            $stmt->execute([$company_code, $branch_code]);
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                erp_pending_push($rows, $r['doc_no'], 'MR', 'Material Request', $r['request_by'] ?? '', $r['doc_dt'] ?? '');
            }
        }

        if (($session['PR'] ?? '') === 'Y') {
            $stmt = $con->prepare(
                "SELECT TOP 10 purchase_request_h.doc_no,
                        replace(convert(char(11), purchase_request_h.doc_dt,113),' ','-') AS doc_dt,
                        purchase_request_h.request_by
                 FROM purchase_request_d
                 INNER JOIN purchase_request_h
                    ON purchase_request_d.company_code = purchase_request_h.company_code
                   AND purchase_request_d.branch_code  = purchase_request_h.branch_code
                   AND purchase_request_d.doc_no       = purchase_request_h.doc_no
                 WHERE purchase_request_h.company_code = ?
                   AND purchase_request_h.branch_code  = ?
                   AND purchase_request_d.cancelled = 'N'
                   AND purchase_request_d.approved  = 'N'
                 GROUP BY purchase_request_h.doc_no, purchase_request_h.doc_dt, purchase_request_h.request_by
                 ORDER BY purchase_request_h.doc_dt DESC, purchase_request_h.doc_no DESC"
            );
            $stmt->execute([$company_code, $branch_code]);
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                erp_pending_push($rows, $r['doc_no'], 'PR', 'Purchase Request', $r['request_by'] ?? '', $r['doc_dt'] ?? '');
            }
        }

        if (($session['PO'] ?? '') === 'Y') {
            $stmt = $con->prepare(
                "SELECT TOP 10 purchase_order_h.doc_no,
                        replace(convert(char(11), purchase_order_h.doc_dt,113),' ','-') AS doc_dt,
                        purchase_order_h.user_id
                 FROM purchase_order_h
                 WHERE purchase_order_h.company_code = ?
                   AND purchase_order_h.branch_code  = ?
                   AND purchase_order_h.cancelled  = 'N'
                   AND purchase_order_h.approved   = 'N'
                   AND purchase_order_h.confirmed  = 'Y'
                   AND (purchase_order_h.rejected IS NULL OR purchase_order_h.rejected = 'N')
                 ORDER BY purchase_order_h.doc_dt DESC, purchase_order_h.doc_no DESC"
            );
            $stmt->execute([$company_code, $branch_code]);
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                erp_pending_push($rows, $r['doc_no'], 'PO', 'Purchase Order', $r['user_id'] ?? '', $r['doc_dt'] ?? '');
            }
        }

        if (($session['QT'] ?? '') === 'Y') {
            $stmt = $con->prepare(
                "SELECT TOP 10 sales_quotation_h.doc_no,
                        replace(convert(char(11), sales_quotation_h.doc_dt,113),' ','-') AS doc_dt
                 FROM sales_quotation_h
                 WHERE sales_quotation_h.company_code = ?
                   AND sales_quotation_h.branch_code  = ?
                   AND sales_quotation_h.approved  = 'N'
                   AND sales_quotation_h.cancelled = 'N'
                 ORDER BY sales_quotation_h.doc_dt DESC, sales_quotation_h.doc_no DESC"
            );
            $stmt->execute([$company_code, $branch_code]);
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                erp_pending_push($rows, $r['doc_no'], 'QT', 'Quotation', '', $r['doc_dt'] ?? '');
            }
        }

        if (($session['SO'] ?? '') === 'Y') {
            $stmt = $con->prepare(
                "SELECT TOP 10 sales_order_h.doc_no,
                        replace(convert(char(11), sales_order_h.doc_dt,113),' ','-') AS doc_dt
                 FROM sales_order_h
                 WHERE sales_order_h.company_code = ?
                   AND sales_order_h.branch_code  = ?
                   AND sales_order_h.approved  = 'N'
                   AND sales_order_h.cancelled = 'N'
                 ORDER BY sales_order_h.doc_dt DESC, sales_order_h.doc_no DESC"
            );
            $stmt->execute([$company_code, $branch_code]);
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                erp_pending_push($rows, $r['doc_no'], 'SO', 'Sales Order', '', $r['doc_dt'] ?? '');
            }
        }

        if (($session['SOC'] ?? '') === 'Y') {
            $stmt = $con->prepare(
                "SELECT TOP 10 sales_order_h.doc_no,
                        replace(convert(char(11), sales_order_h.doc_dt,113),' ','-') AS doc_dt
                 FROM sales_order_h
                 WHERE sales_order_h.company_code = ?
                   AND sales_order_h.branch_code  = ?
                   AND sales_order_h.approved  = 'N'
                   AND sales_order_h.cancelled = 'N'
                 ORDER BY sales_order_h.doc_dt DESC, sales_order_h.doc_no DESC"
            );
            $stmt->execute([$company_code, $branch_code]);
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                erp_pending_push($rows, $r['doc_no'], 'SOC', 'Sales Order Confirmation', '', $r['doc_dt'] ?? '');
            }
        }

        if (($session['PI'] ?? '') === 'Y') {
            $stmt = $con->prepare(
                "SELECT TOP 10 proforma_h.doc_no,
                        replace(convert(char(11), proforma_h.doc_dt,113),' ','-') AS doc_dt
                 FROM proforma_h
                 WHERE proforma_h.company_code = ?
                   AND proforma_h.branch_code  = ?
                   AND proforma_h.approved_by IS NULL
                 ORDER BY proforma_h.doc_dt DESC, proforma_h.doc_no DESC"
            );
            $stmt->execute([$company_code, $branch_code]);
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                erp_pending_push($rows, $r['doc_no'], 'PI', 'Proforma Invoice', '', $r['doc_dt'] ?? '');
            }
        }

        if (($session['DR'] ?? '') === 'Y') {
            $stmt = $con->prepare(
                "SELECT TOP 10 delivery_request_h.doc_no,
                        replace(convert(char(11), delivery_request_h.doc_dt,113),' ','-') AS doc_dt
                 FROM delivery_request_h
                 WHERE delivery_request_h.company_code = ?
                   AND delivery_request_h.branch_code  = ?
                   AND delivery_request_h.approved  = 'N'
                   AND delivery_request_h.cancelled = 'N'
                 ORDER BY delivery_request_h.doc_dt DESC, delivery_request_h.doc_no DESC"
            );
            $stmt->execute([$company_code, $branch_code]);
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                erp_pending_push($rows, $r['doc_no'], 'DR', 'Delivery Request', '', $r['doc_dt'] ?? '');
            }
        }

        usort($rows, function ($a, $b) {
            if ($a['sort_ts'] === $b['sort_ts']) {
                return strcmp($b['doc'], $a['doc']);
            }
            return $b['sort_ts'] <=> $a['sort_ts'];
        });

        $counts = erp_fetch_dashboard_counts($con, $session);
        $by_key = $counts['by_key'] ?? [];
        $total  = 0;
        foreach (['MR', 'PR', 'PO', 'QT', 'SO', 'SOC', 'PI', 'DR'] as $key) {
            if (($session[$key] ?? '') === 'Y') {
                $total += (int) ($by_key[$key] ?? 0);
            }
        }

        $display_rows = array_slice($rows, 0, $limit);
        foreach ($display_rows as &$row) {
            unset($row['sort_ts'], $row['doc_type']);
        }
        unset($row);

        return [
            'rows'  => $display_rows,
            'total' => $total,
        ];
    }
}
