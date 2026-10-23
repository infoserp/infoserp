<?php
/**
 * Shared approval menu data — backend logic preserved from approvalmenu.php.
 * Populates $approval_items and $menu_items arrays (no HTML output).
 * Included by approvalmenu.php and dashboard.php.
 *
 * Set $defer_approval_counts = true before include to skip SQL on initial page load
 * (counts loaded via api/dashboard_counts.php).
 */

$defer_approval_counts = !empty($defer_approval_counts);
require_once __DIR__ . '/erp_paths.php';

$company_code = $_SESSION["company_code"];
$branch_code  = $_SESSION["branch_code"];

$approval_items = [];
$menu_items     = [];

if ($_SESSION["MR"] == "Y") {
    $item = [
        'key'   => 'MR',
        'menu_code' => 'm_materialrequestapproval',
        'label' => 'Material Request Approval',
        'href'  => erp_url('approval?doc_type=MR'),
        'icon'  => 'fa-truck',
    ];
    if ($defer_approval_counts) {
        $item['count']    = null;
        $item['badge_id'] = 'badge_count_MR';
    } else {
        $QUERY = "SELECT        COUNT(*) AS cnt
           FROM            ( SELECT       1 AS Expr1
               FROM            material_request_h INNER JOIN
               material_request_d ON material_request_h.company_code = material_request_d.company_code AND material_request_h.branch_code = material_request_d.branch_code AND
               material_request_h.doc_no = material_request_d.doc_no
               WHERE        material_request_h.company_code = '$company_code' AND material_request_h.branch_code = '$branch_code'
            AND material_request_d.approved = 'N'  AND material_request_d.cancelled = 'N' 
               GROUP BY material_request_h.doc_no, material_request_h.company_code,material_request_h.branch_code) AS Q ";
        $stmt = $con->prepare($QUERY);
        $stmt->execute(array($company_code, $branch_code));
        $REPORT = $stmt->fetch(PDO::FETCH_ASSOC);
        $item['count'] = $REPORT['cnt'];
    }
    $approval_items[] = $item;
}
if ($_SESSION["PR"] == "Y") {
    $item = [
        'key'   => 'PR',
        'menu_code' => 'm_purchaserequestapproval',
        'label' => 'Purchase Request Approval',
        'href'  => erp_url('approval?doc_type=PR'),
        'icon'  => 'fa-list',
    ];
    if ($defer_approval_counts) {
        $item['count']    = null;
        $item['badge_id'] = 'badge_count_PR';
    } else {
        $QUERY = " SELECT        COUNT(*) AS cnt
           FROM            (SELECT        COUNT(DISTINCT purchase_request_d.doc_no) AS Expr1
           FROM            purchase_request_d INNER JOIN
           purchase_request_h ON purchase_request_d.company_code = purchase_request_h.company_code AND purchase_request_d.branch_code = purchase_request_h.branch_code AND 
           purchase_request_d.doc_no = purchase_request_h.doc_no
           WHERE        (purchase_request_h.company_code = '$company_code') AND
          (purchase_request_h.branch_code = '$branch_code') AND 
          (purchase_request_d.cancelled = 'N') AND (purchase_request_d.approved = 'N')
           GROUP BY purchase_request_h.doc_no, purchase_request_h.company_code, purchase_request_h.branch_code) AS Q";
        $stmt = $con->prepare($QUERY);
        $stmt->execute(array($company_code, $branch_code));
        $REPORT = $stmt->fetch(PDO::FETCH_ASSOC);
        $item['count'] = $REPORT['cnt'];
    }
    $approval_items[] = $item;
}

if ($_SESSION["POC"] == "Y") {
/* POC block preserved — no active output in original */
}

if ($_SESSION["PO"] == "Y") {
    $item = [
        'key'   => 'PO',
        'menu_code' => 'm_txn_purchaseorderapproval',
        'label' => 'Purchase Order Approval',
        'href'  => erp_url('approval?doc_type=PO'),
        'icon'  => 'fa-shopping-bag',
    ];
    if ($defer_approval_counts) {
        $item['count']    = null;
        $item['badge_id'] = 'badge_count_PO';
    } else {
        $QUERY = " SELECT  COUNT(*) AS cnt         
               FROM            purchase_order_h
               WHERE        (company_code = '$company_code')
     AND (branch_code = '$branch_code') AND (cancelled = 'N') AND (approved = 'N') AND (confirmed = 'Y') AND (rejected = 'N' OR rejected IS NULL)";
        $stmt = $con->prepare($QUERY);
        $stmt->execute(array($company_code, $branch_code));
        $REPORT = $stmt->fetch(PDO::FETCH_ASSOC);
        $item['count'] = $REPORT['cnt'];
    }
    $approval_items[] = $item;
}
if ($_SESSION["QT"] == "Y") {
    $item = [
        'key'   => 'QT',
        'menu_code' => 'm_txn_salesquotationapproval',
        'label' => 'Quotation Approval',
        'href'  => erp_url('approval?doc_type=QT'),
        'icon'  => 'fa-quote-right',
    ];
    if ($defer_approval_counts) {
        $item['count']    = null;
        $item['badge_id'] = 'badge_count_QT';
    } else {
        $QUERY = " SELECT        COUNT(*) AS cnt
           FROM            sales_quotation_h  
           WHERE        (sales_quotation_h.company_code = '$company_code')
            AND (sales_quotation_h.branch_code = '$branch_code') 
            AND (sales_quotation_h.approved = 'N') AND 
            (sales_quotation_h.cancelled = 'N')";
        $stmt = $con->prepare($QUERY);
        $stmt->execute(array($company_code, $branch_code));
        $REPORT = $stmt->fetch(PDO::FETCH_ASSOC);
        $item['count'] = $REPORT['cnt'];
    }
    $approval_items[] = $item;
}
if ($_SESSION["SOC"] == "Y") {
    $item = [
        'key'   => 'SOC',
        'menu_code' => 'm_salesorderconfirmation',
        'label' => 'Sales Order Confirmation',
        'href'  => erp_url('approval?doc_type=SOC'),
        'icon'  => 'fa-trophy',
    ];
    if ($defer_approval_counts) {
        $item['count']    = null;
        $item['badge_id'] = 'badge_count_SOC';
    } else {
        $QUERY = " SELECT   COUNT(*) AS cnt
            FROM             sales_order_h
            WHERE        (sales_order_h.company_code = '$company_code') AND
            (sales_order_h.branch_code = '$branch_code') AND
            (sales_order_h.approved = 'N') AND (sales_order_h.cancelled = 'N')";
        $stmt = $con->prepare($QUERY);
        $stmt->execute(array($company_code, $branch_code));
        $REPORT = $stmt->fetch(PDO::FETCH_ASSOC);
        $item['count'] = $REPORT['cnt'];
    }
    $approval_items[] = $item;
}
if ($_SESSION["SO"] == "Y") {
    $item = [
        'key'   => 'SO',
        'menu_code' => 'm_salesorderapproval',
        'label' => 'Sales Order Approval',
        'href'  => erp_url('approval?doc_type=SO'),
        'icon'  => 'fa-trophy',
    ];
    if ($defer_approval_counts) {
        $item['count']    = null;
        $item['badge_id'] = 'badge_count_SO';
    } else {
        $QUERY = " SELECT   COUNT(*) AS cnt     
            FROM             sales_order_h                                  
            WHERE        (sales_order_h.company_code = '$company_code') AND 
            (sales_order_h.branch_code = '$branch_code') AND 
            (sales_order_h.approved = 'N') AND (sales_order_h.cancelled = 'N')";
        $stmt = $con->prepare($QUERY);
        $stmt->execute(array($company_code, $branch_code));
        $REPORT = $stmt->fetch(PDO::FETCH_ASSOC);
        $item['count'] = $REPORT['cnt'];
    }
    $approval_items[] = $item;
}
if ($_SESSION["PI"] == "Y") {
    $item = [
        'key'   => 'PI',
        'menu_code' => 'm_proformainvoiceapproval',
        'label' => 'Proforma Invoice',
        'href'  => erp_url('approval?doc_type=PI'),
        'icon'  => 'fa-file-text-o',
    ];
    if ($defer_approval_counts) {
        $item['count']    = null;
        $item['badge_id'] = 'badge_count_PI';
    } else {
        $QUERY = " SELECT count(*) as cnt
           FROM   ac_master INNER JOIN proforma_h ON ac_master.company_code = proforma_h.company_code
           AND ac_master.ac_code = proforma_h.ac_code
           WHERE        (proforma_h.company_code = '$company_code') AND
           (proforma_h.branch_code = '$branch_code')
           AND (proforma_h.approved_by IS NULL) ";
        $stmt = $con->prepare($QUERY);
        $stmt->execute(array($company_code, $branch_code));
        $REPORT = $stmt->fetch(PDO::FETCH_ASSOC);
        $item['count'] = $REPORT['cnt'];
    }
    $approval_items[] = $item;
}
if ($_SESSION["DR"] == "Y") {
    $item = [
        'key'   => 'DR',
        'menu_code' => 'm_deliveryrequestapproval',
        'label' => 'Delivery Request',
        'href'  => erp_url('approval?doc_type=DR'),
        'icon'  => 'fa-shopping-cart',
    ];
    if ($defer_approval_counts) {
        $item['count']    = null;
        $item['badge_id'] = 'badge_count_DR';
    } else {
        $QUERY = "SELECT        count(*) as cnt
          FROM            delivery_request_h 
          WHERE delivery_request_h.company_code = '$company_code'
          AND  delivery_request_h.branch_code = '$branch_code'
          AND delivery_request_h.approved = 'N'
          AND delivery_request_h.cancelled = 'N' ";
        $stmt = $con->prepare($QUERY);
        $stmt->execute(array($company_code, $branch_code));
        $REPORT = $stmt->fetch(PDO::FETCH_ASSOC);
        $item['count'] = $REPORT['cnt'];
    }
    $approval_items[] = $item;
}

if (isset($_SESSION['allow_dashboard']) && $_SESSION['allow_dashboard'] === 'Y'
&& isset($_SESSION['message_sales']) && $_SESSION['message_sales'] === 'Y') {

    $approval_items[] = [
        'key'      => 'UNPAID',
        'label'    => 'Unpaid Invoices - Followup',
        'href'     => erp_url('unpaid_invoices'),
        'icon'     => 'fa-money',
        'count'    => null,
        'badge_id' => 'badge_unpaid_invoices',
    ];
}

if (isset($_SESSION['allow_dashboard']) && $_SESSION['allow_dashboard'] === 'Y'
&& isset($_SESSION['message_sales']) && $_SESSION['message_sales'] === 'Y') {

    $approval_items[] = [
        'key'      => 'PROFORMA',
        'label'    => 'Proforma Invoice Pending',
        'href'     => erp_url('proforma_pending'),
        'icon'     => 'fa-file-text',
        'count'    => null,
        'badge_id' => 'badge_proforma_pending',
    ];
}

if ($_SESSION["MRQ"] == "Y") {
    $menu_items[] = [
        'key'   => 'MRQ',
        'menu_code' => 'm_materialrequest',
        'label' => 'Material Request',
        'href'  => erp_url('modules/material_request/material_request?code=MR'),
        'icon'  => 'fa-clipboard',
    ];
}
if ($_SESSION["SI"] == "Y") {
    $menu_items[] = [
        'key'   => 'SI',
        'menu_code' => 'm_txn_salesinvoice',
        'label' => 'Sales Invoice',
        'href'  => erp_url('modules/invoice/invoice?code=SI'),
        'icon'  => 'fa-file-text-o',
    ];
}
if ($_SESSION["DOC"] == "Y") {
    $menu_items[] = [
        'key'   => 'DOC',
        'menu_code' => 'm_documentsetup',
        'label' => 'Document Setup',
        'href'  => erp_url('document_setup'),
        'icon'  => 'fa-cog',
    ];
}
if ($_SESSION["RV"] == "Y") {
    $menu_items[] = [
        'key'   => 'RV',
        'menu_code' => 'm_tr-receiptvoucher',
        'label' => 'Receipt Voucher',
        'href'  => erp_url('modules/receipt/receipt?code=RV'),
        'icon'  => 'fa-money',
    ];
}
if (($_SESSION["TO"] ?? 'N') == "Y") {
    $menu_items[] = [
        'key'   => 'TO',
        'menu_code' => 'm_tr-stocktransferout',
        'label' => 'Stock Transfer Out',
        'href'  => erp_url('modules/stock_transfer_out/stock_transfer_out?code=TO'),
        'icon'  => 'fa-sign-out',
    ];
}
if (($_SESSION["TI"] ?? 'N') == "Y") {
    $menu_items[] = [
        'key'   => 'TI',
        'menu_code' => 'm_tr-stocktransferin',
        'label' => 'Stock Transfer In',
        'href'  => erp_url('modules/stock_transfer_in/stock_transfer_in?code=TI'),
        'icon'  => 'fa-sign-in',
    ];
}
if ($_SESSION["DD"] == "Y") {
    $menu_items[] = [
        'key'   => 'DD',
        'menu_code' => 'm_deliveryorderdespatch',
        'label' => 'DO Dispatch',
        'href'  => erp_url('modules/do_dispatch/do_dispatch'),
        'icon'  => 'fa-truck',
    ];

    if (!$defer_approval_counts) {
        $QUERY = "SELECT COUNT(*) AS cnt
                  FROM   delivery_note_h dnh
                  WHERE  dnh.company_code = :company_code
                    AND  dnh.branch_code  = :branch_code
                    AND  dnh.despatch     = 'N'
                    AND  dnh.despatch_to  = :user_id";
        $dstmt = $con->prepare($QUERY);
        $dstmt->execute([
            ':company_code' => $company_code,
            ':branch_code'  => $branch_code,
            ':user_id'      => $user,
        ]);
        $DO_REPORT = $dstmt->fetch(PDO::FETCH_ASSOC);
        $do_dispatch_count = (int) ($DO_REPORT['cnt'] ?? 0);
    }
}

/*
 * Sidebar tree grouped by user_menu parents (paths saved at branch selection).
 * $menu_tree: top-level sections -> groups -> items. Items without a saved
 * parent path (e.g. follow-up links) go to $menu_ungrouped.
 * Both stay empty for sessions started before paths were saved; the sidebar
 * then falls back to the flat Approvals/Modules lists.
 */
if (!function_exists('erp_menu_group_icon')) {
    function erp_menu_group_icon($label) {
        $icons = [
            'inventory' => 'fa-cubes',
            'purchase'  => 'fa-shopping-cart',
            'sales'     => 'fa-line-chart',
            'receipt'   => 'fa-money',
        ];
        return $icons[strtolower(trim((string) $label))] ?? 'fa-folder-o';
    }
}

if (!function_exists('erp_sort_menu_tree')) {
    function erp_sort_menu_tree(array &$nodes) {
        usort($nodes, function ($a, $b) {
            return strnatcmp((string) $a['sort'], (string) $b['sort']);
        });
        foreach ($nodes as &$node) {
            if ($node['type'] === 'group') {
                erp_sort_menu_tree($node['children']);
            }
        }
        unset($node);
    }
}

$menu_tree      = [];
$menu_ungrouped = [];
if (isset($_SESSION['menu_paths']) && is_array($_SESSION['menu_paths'])) {
    $menu_index = [];
    foreach (array_merge($approval_items, $menu_items) as $item) {
        $entry = $_SESSION['menu_paths'][$item['menu_code'] ?? ''] ?? null;
        if (empty($entry['path'])) {
            $menu_ungrouped[] = $item;
            continue;
        }
        $level = &$menu_index;
        foreach ($entry['path'] as $node) {
            $code = $node['menu_code'];
            if (!isset($level[$code])) {
                $level[$code] = [
                    'type'     => 'group',
                    'code'     => $code,
                    'label'    => $node['menu_name'],
                    'sort'     => $node['sort_order'],
                    'children' => [],
                ];
            }
            $level = &$level[$code]['children'];
        }
        $level[] = ['type' => 'item', 'sort' => $entry['sort_order'], 'item' => $item];
        unset($level);
    }
    $menu_tree = array_values($menu_index);
    erp_sort_menu_tree($menu_tree);
}

/* Footer navigation items (always shown) */
$footer_nav = [
    ['key' => 'branch', 'label' => 'Change Branch', 'href' => erp_url('branchselection'), 'icon' => 'fa-exchange'],
    ['key' => 'logout', 'label' => 'Log out',       'href' => erp_url('logout'),          'icon' => 'fa-sign-out'],
];

if (!function_exists('erp_e')) {
    function erp_e($v) {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    }
}

$branch_name  = $_SESSION['branch_name']  ?? '';
$company_name = $_SESSION['company_name'] ?? '';

if (!isset($do_dispatch_count)) {
    $do_dispatch_count = 0;
}

/* Helper: get approval count by session key */
function erp_get_count($items, $key) {
    foreach ($items as $it) {
        if (($it['key'] ?? '') === $key && empty($it['badge_id'])) {
            return (int) ($it['count'] ?? 0);
        }
    }
    return 0;
}

/* Dashboard card visibility — menu access unchanged; icon='Y' required for dashboard cards only */
function erp_dashboard_card_visible($key) {
    $icon_key = 'icon_' . $key;
    return isset($_SESSION[$icon_key]) && strtoupper((string) $_SESSION[$icon_key]) === 'Y';
}
