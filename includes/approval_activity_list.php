<?php
/**
 * Dashboard recent activity — derived from approval/dispatch document tables.
 */

if (!function_exists('erp_fetch_recent_activity')) {

    function erp_activity_time_label($datetime) {
        if ($datetime === null || $datetime === '') {
            return '—';
        }
        if (is_int($datetime) || (is_numeric($datetime) && strlen((string) $datetime) >= 10)) {
            $ts = (int) $datetime;
        } elseif ($datetime instanceof DateTimeInterface) {
            $ts = $datetime->getTimestamp();
        } else {
            $ts = strtotime((string) $datetime);
        }
        if (!$ts) {
            return '—';
        }
        $today_start     = strtotime('today');
        $yesterday_start = strtotime('yesterday');
        if ($ts >= $today_start) {
            return date('g:i A', $ts);
        }
        if ($ts >= $yesterday_start) {
            return 'Yesterday';
        }
        return '';
    }

    function erp_activity_push(array &$rows, $sort_ts, $text, $icon, $color) {
        if ($sort_ts <= 0 || trim($text) === '') {
            return;
        }
        $rows[] = [
            'time'    => erp_activity_time_label($sort_ts),
            'text'    => $text,
            'icon'    => $icon,
            'color'   => $color,
            'sort_ts' => (int) $sort_ts,
        ];
    }

    /**
     * @return array<int, array{time: string, text: string, icon: string, color: string}>
     */
    function erp_fetch_recent_activity(PDO $con, array $session, $limit = 10) {
        require_once __DIR__ . '/approval_pending_list.php';

        $company_code = $session['company_code'] ?? '';
        $branch_code  = $session['branch_code'] ?? '';
        $limit        = max(1, (int) $limit);
        $rows         = [];

        if ($company_code === '' || $branch_code === '') {
            return [];
        }

        if (($session['PO'] ?? '') === 'Y') {
            $stmt = $con->prepare(
                "SELECT TOP 3 doc_no, approved_date, approved_by
                 FROM purchase_order_h
                 WHERE company_code = ? AND branch_code = ?
                   AND approved = 'Y' AND approved_date IS NOT NULL
                 ORDER BY approved_date DESC"
            );
            $stmt->execute([$company_code, $branch_code]);
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $by = trim((string) ($r['approved_by'] ?? ''));
                $ts = strtotime((string) ($r['approved_date'] ?? ''));
                erp_activity_push(
                    $rows,
                    $ts,
                    'Purchase Order ' . ($r['doc_no'] ?? '') . ' approved' . ($by !== '' ? ' by ' . $by : ''),
                    'fa-check',
                    'slate'
                );
            }
        }

        if (($session['MR'] ?? '') === 'Y') {
            $stmt = $con->prepare(
                "SELECT TOP 3 material_request_h.doc_no, material_request_h.doc_dt, material_request_h.request_by
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
                 ORDER BY material_request_h.doc_dt DESC"
            );
            $stmt->execute([$company_code, $branch_code]);
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $by = trim((string) ($r['request_by'] ?? ''));
                $ts = erp_pending_sort_ts($r['doc_dt'] ?? '');
                erp_activity_push(
                    $rows,
                    $ts,
                    'Material Request ' . ($r['doc_no'] ?? '') . ' pending approval' . ($by !== '' ? ' (' . $by . ')' : ''),
                    'fa-truck',
                    'blue'
                );
            }

            $stmt = $con->prepare(
                "SELECT TOP 3 doc_no, MAX(approved_date) AS activity_dt, MAX(approved_by) AS approved_by
                 FROM material_request_d
                 WHERE company_code = ? AND branch_code = ?
                   AND approved = 'Y' AND approved_date IS NOT NULL
                 GROUP BY doc_no
                 ORDER BY activity_dt DESC"
            );
            $stmt->execute([$company_code, $branch_code]);
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $by = trim((string) ($r['approved_by'] ?? ''));
                $ts = strtotime((string) ($r['activity_dt'] ?? ''));
                erp_activity_push(
                    $rows,
                    $ts,
                    'Material Request ' . ($r['doc_no'] ?? '') . ' approved' . ($by !== '' ? ' by ' . $by : ''),
                    'fa-check',
                    'green'
                );
            }
        }

        if (($session['PR'] ?? '') === 'Y') {
            $stmt = $con->prepare(
                "SELECT TOP 3 purchase_request_h.doc_no, purchase_request_h.doc_dt, purchase_request_h.request_by
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
                 ORDER BY purchase_request_h.doc_dt DESC"
            );
            $stmt->execute([$company_code, $branch_code]);
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $by = trim((string) ($r['request_by'] ?? ''));
                $ts = erp_pending_sort_ts($r['doc_dt'] ?? '');
                erp_activity_push(
                    $rows,
                    $ts,
                    'Purchase Request ' . ($r['doc_no'] ?? '') . ' pending approval' . ($by !== '' ? ' (' . $by . ')' : ''),
                    'fa-list',
                    'sky'
                );
            }

            $stmt = $con->prepare(
                "SELECT TOP 3 doc_no, MAX(approved_date) AS activity_dt, MAX(approved_by) AS approved_by
                 FROM purchase_request_d
                 WHERE company_code = ? AND branch_code = ?
                   AND approved = 'Y' AND approved_date IS NOT NULL
                 GROUP BY doc_no
                 ORDER BY activity_dt DESC"
            );
            $stmt->execute([$company_code, $branch_code]);
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $by = trim((string) ($r['approved_by'] ?? ''));
                $ts = strtotime((string) ($r['activity_dt'] ?? ''));
                erp_activity_push(
                    $rows,
                    $ts,
                    'Purchase Request ' . ($r['doc_no'] ?? '') . ' approved' . ($by !== '' ? ' by ' . $by : ''),
                    'fa-check',
                    'green'
                );
            }
        }

        if (($session['QT'] ?? '') === 'Y') {
            $stmt = $con->prepare(
                "SELECT TOP 3 doc_no, approved_date, approved_by
                 FROM sales_quotation_h
                 WHERE company_code = ? AND branch_code = ?
                   AND approved = 'Y' AND approved_date IS NOT NULL
                 ORDER BY approved_date DESC"
            );
            $stmt->execute([$company_code, $branch_code]);
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $by = trim((string) ($r['approved_by'] ?? ''));
                $ts = strtotime((string) ($r['approved_date'] ?? ''));
                erp_activity_push(
                    $rows,
                    $ts,
                    'Quotation ' . ($r['doc_no'] ?? '') . ' approved' . ($by !== '' ? ' by ' . $by : ''),
                    'fa-check',
                    'red'
                );
            }
        }

        if (($session['SO'] ?? '') === 'Y') {
            $stmt = $con->prepare(
                "SELECT TOP 3 doc_no, approved_date, approved_by
                 FROM sales_order_h
                 WHERE company_code = ? AND branch_code = ?
                   AND approved = 'Y' AND approved_date IS NOT NULL
                 ORDER BY approved_date DESC"
            );
            $stmt->execute([$company_code, $branch_code]);
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $by = trim((string) ($r['approved_by'] ?? ''));
                $ts = strtotime((string) ($r['approved_date'] ?? ''));
                erp_activity_push(
                    $rows,
                    $ts,
                    'Sales Order ' . ($r['doc_no'] ?? '') . ' approved' . ($by !== '' ? ' by ' . $by : ''),
                    'fa-check',
                    'green'
                );
            }
        }

        if (($session['DR'] ?? '') === 'Y') {
            $stmt = $con->prepare(
                "SELECT TOP 3 doc_no, approved_date, approved_by
                 FROM delivery_request_h
                 WHERE company_code = ? AND branch_code = ?
                   AND approved = 'Y' AND approved_date IS NOT NULL
                 ORDER BY approved_date DESC"
            );
            $stmt->execute([$company_code, $branch_code]);
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $by = trim((string) ($r['approved_by'] ?? ''));
                $ts = strtotime((string) ($r['approved_date'] ?? ''));
                erp_activity_push(
                    $rows,
                    $ts,
                    'Delivery Request ' . ($r['doc_no'] ?? '') . ' approved' . ($by !== '' ? ' by ' . $by : ''),
                    'fa-check',
                    'indigo'
                );
            }
        }

        if (($session['DD'] ?? '') === 'Y') {
            $user = $session['username'] ?? '';
            try {
                $stmt = $con->prepare(
                    "SELECT TOP 3 doc_no, despatch_date
                     FROM delivery_note_h
                     WHERE company_code = ? AND branch_code = ?
                       AND despatch = 'Y' AND despatch_to = ?
                       AND despatch_date IS NOT NULL
                     ORDER BY despatch_date DESC"
                );
                $stmt->execute([$company_code, $branch_code, $user]);
                while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $ts = strtotime((string) ($r['despatch_date'] ?? ''));
                    erp_activity_push(
                        $rows,
                        $ts,
                        'Delivery Order ' . ($r['doc_no'] ?? '') . ' dispatched',
                        'fa-truck',
                        'indigo'
                    );
                }
            } catch (Exception $e) {
                // despatch_date column may not exist on older schemas
            }
        }

        usort($rows, function ($a, $b) {
            return $b['sort_ts'] <=> $a['sort_ts'];
        });

        $today_start     = strtotime('today');
        $yesterday_start = strtotime('yesterday');
        $filtered        = [];
        $yesterday_count = 0;

        foreach ($rows as $row) {
            $ts = (int) ($row['sort_ts'] ?? 0);
            if ($ts >= $today_start) {
                $filtered[] = $row;
            } elseif ($ts >= $yesterday_start && $yesterday_count < 3) {
                $filtered[] = $row;
                $yesterday_count++;
            }
        }

        foreach ($filtered as &$row) {
            unset($row['sort_ts']);
        }
        unset($row);

        return $filtered;
    }
}
