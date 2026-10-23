<?php
ob_start();
require('../../db.php');
include('../../auth.php');
require_once __DIR__ . '/../../includes/erp_paths.php';
require_once __DIR__ . '/../../includes/do_print_service.php';

$company_code = $_SESSION['company_code'];
$branch_code  = $_SESSION['branch_code'];
$user         = trim((string) ($_SESSION['username'] ?? ''));

// ── Shared helpers (needed by both AJAX and full-page render) ─
function getDaysOld($doc_dt) {
    if (empty($doc_dt)) return 0;
    try {
        $dt  = new DateTime(date('Y-m-d', strtotime($doc_dt)));
        $now = new DateTime(date('Y-m-d'));
        return (int) $now->diff($dt)->days;
    } catch (Exception $e) { return 0; }
}

function pageUrl($p) {
    $params         = $_GET;
    $params['page'] = $p;
    unset($params['ajax']);
    if (erp_is_embedded()) {
        $params['embed'] = '1';
    }
    return '?' . http_build_query($params);
}

function doResetUrl() {
    return erp_is_embedded() ? '?embed=1' : '?';
}

function doPrintUrl($doc_no) {
    $params = ['doc_no' => (string) $doc_no];
    $page   = max(1, intval($_GET['page'] ?? 1));
    $search = trim($_GET['search'] ?? '');
    if ($page > 1) {
        $params['page'] = $page;
    }
    if ($search !== '') {
        $params['search'] = $search;
    }
    return erp_embed_href('do_print.php?' . http_build_query($params));
}

// ── Build tbody HTML from a result array ──────────────────────
function buildTbodyHtml($rows, $search_term) {
    if (empty($rows)) {
        $is_search = $search_term !== '';
        $icon    = $is_search ? 'search' : 'inbox';
        $title   = $is_search
            ? 'No results for &ldquo;' . htmlspecialchars($search_term) . '&rdquo;'
            : 'No pending delivery orders';
        $msg     = $is_search
            ? 'Try a different DO number or customer name.'
            : 'There are no DOs assigned to you awaiting dispatch.';
        return '<tr><td colspan="5">
                    <div class="dod-empty-state flex flex-col items-center justify-center py-14 text-gray-400">
                        <div class="w-14 h-14 rounded-2xl bg-gray-100 flex items-center justify-center mb-3">
                            <i class="fa fa-' . $icon . ' text-2xl text-gray-300"></i>
                        </div>
                        <div class="dod-empty-state-title text-sm font-semibold text-gray-500 mb-1">' . $title . '</div>
                        <div class="dod-empty-state-text text-xs text-gray-400">' . $msg . '</div>
                    </div></td></tr>';
    }

    $html = '';
    foreach ($rows as $do) {
        $days_old    = getDaysOld($do['doc_dt']);
        $is_delayed  = ($days_old > 0);
        $delay_label = $days_old === 1 ? '1D DELAY' : $days_old . 'D DELAY';
        $doc_dt_fmt  = !empty($do['doc_dt']) ? date('M d, Y', strtotime($do['doc_dt'])) : '—';
        $customer    = !empty($do['customer_name']) ? $do['customer_name'] : $do['ac_code'];
        $doc_no_safe = htmlspecialchars($do['doc_no']);
        $cust_safe   = htmlspecialchars($customer);

        $row_bg    = $is_delayed ? 'bg-amber-50 hover:bg-amber-100' : 'bg-white hover:bg-blue-50';
        $row_class = 'dod-row' . ($is_delayed ? ' dod-row-delayed' : '') . ' transition-colors duration-100';

        $warn_cell = $is_delayed
            ? '<i class="fa fa-exclamation-triangle dod-warning-icon text-amber-500 text-xs mr-1" title="Overdue by ' . $days_old . ' day(s)"></i>'
            : '<span class="dod-spacer-icon inline-block w-4 mr-1"></span>';

        $delay_badge = $is_delayed
            ? '<span class="dod-delay-badge inline-block text-[10px] font-bold uppercase tracking-wide text-red-600 bg-red-50 border border-red-200 rounded px-1.5 py-0.5 mt-0.5">'
              . $delay_label . '</span>'
            : '';

        $is_signed   = !empty($do['receiver_signature_path']);
        $can_dispatch = doPrintIsReceiverProofComplete($do, true);
        $status_badge = $is_signed
            ? '<span class="dod-badge dod-badge-signed inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold bg-green-50 text-green-700 border border-green-200">
                    <span class="dod-badge-dot w-1.5 h-1.5 rounded-full bg-green-500"></span>
                    Signed
               </span>'
            : '<span class="dod-badge dod-badge-pending inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold bg-amber-50 text-amber-700 border border-amber-200">
                    <span class="dod-badge-dot w-1.5 h-1.5 rounded-full bg-amber-500"></span>
                    Pending Dispatch
               </span>';

        $sign_btn = $is_signed
            ? '<span class="dod-action-btn dod-action-btn--sign inline-flex items-center justify-center w-8 h-8 rounded-lg text-gray-300 bg-gray-50 border border-gray-100 cursor-not-allowed opacity-60" title="Signature already captured">
                    <i class="fa fa-pencil-square-o text-sm"></i>
               </span>'
            : '<button class="dod-action-btn dod-action-btn--sign inline-flex items-center justify-center w-8 h-8 rounded-lg text-gray-400 hover:text-indigo-600 hover:bg-indigo-50 border border-transparent hover:border-indigo-200 transition-all" type="button" title="Proof of Delivery / Signature" data-doc="' . $doc_no_safe . '" onclick="openSignModal(this.dataset.doc)">
                    <i class="fa fa-pencil-square-o text-sm"></i>
               </button>';

        $html .= '
        <tr class="' . $row_class . ' ' . $row_bg . ' border-b border-gray-50" data-doc="' . $doc_no_safe . '">
            <td class="px-5 py-3">
                <div class="dod-doc-no-cell flex items-center">
                    ' . $warn_cell . '
                    <span class="dod-doc-no-link text-blue-600 font-bold text-xs tracking-wide font-mono">'
                    . $doc_no_safe . '</span>
                </div>
            </td>
            <td class="px-5 py-3">
                <div class="dod-date-cell flex flex-col gap-0.5">
                    <span class="dod-date-text text-xs text-gray-600">' . $doc_dt_fmt . '</span>
                    ' . $delay_badge . '
                </div>
            </td>
            <td class="px-5 py-3">
                <span class="dod-customer-name text-sm font-medium text-gray-800">' . $cust_safe . '</span>
            </td>
            <td class="td-center px-5 py-3 text-center">
                ' . $status_badge . '
            </td>
            <td class="td-right px-5 py-3 text-right">
                <div class="dod-actions-cell flex items-center justify-end gap-1">
                    <a href="' . htmlspecialchars(doPrintUrl($do['doc_no']), ENT_QUOTES, 'UTF-8') . '"
                       class="dod-action-btn inline-flex items-center justify-center w-8 h-8 rounded-lg text-gray-400 hover:text-blue-600 hover:bg-blue-50 border border-transparent hover:border-blue-200 transition-all" title="View">
                        <i class="fa fa-eye text-sm"></i>
                    </a>
                    ' . $sign_btn . '
                    ' . ($can_dispatch
                        ? '<button class="dod-action-btn dod-action-btn--dispatch inline-flex items-center justify-center w-8 h-8 rounded-lg text-white bg-blue-600 hover:bg-blue-700 shadow-sm hover:shadow transition-all active:scale-95" type="button" title="Dispatch" data-doc="' . $doc_no_safe . '">
                            <i class="fa fa-paper-plane text-sm"></i>
                           </button>'
                        : '<span class="dod-action-btn dod-action-btn--dispatch inline-flex items-center justify-center w-8 h-8 rounded-lg text-gray-300 bg-gray-50 border border-gray-100 cursor-not-allowed opacity-60" title="Capture signature, receiver name and number before dispatch">
                            <i class="fa fa-paper-plane text-sm"></i>
                           </span>') . '
                </div>
            </td>
        </tr>';
    }
    return $html;
}

// ── Shared query function ─────────────────────────────────────
function fetchOrders($con, $company_code, $branch_code, $user, $search_like, $offset, $page_size) {
    $search_clause = $search_like !== null
        ? " AND (dnh.doc_no LIKE :search1 OR am.description LIKE :search2)"
        : '';

    // Count
    $count_sql = "SELECT COUNT(*) AS cnt
                  FROM   delivery_note_h dnh
                  LEFT   JOIN ac_master am
                         ON  am.ac_code      = dnh.ac_code
                         AND am.company_code = dnh.company_code
                  WHERE  dnh.company_code = :company_code
                    AND  dnh.branch_code  = :branch_code
                    AND  dnh.despatch     = 'N'
                    AND  dnh.despatch_to  = :user_id
                  $search_clause";

    $cs = $con->prepare($count_sql);
    $cs->bindValue(':company_code', $company_code);
    $cs->bindValue(':branch_code',  $branch_code);
    $cs->bindValue(':user_id',      $user);
    if ($search_like !== null) {
        $cs->bindValue(':search1', $search_like);
        $cs->bindValue(':search2', $search_like);
    }
    $cs->execute();
    $total_count = (int) $cs->fetchColumn();

    // Data
    $sql = "SELECT dnh.doc_no, dnh.doc_dt, dnh.ac_code,
                   am.description AS customer_name,
                   dnh.receiver_signature_path,
                   dnh.receiver_name,
                   dnh.receiver_contact
            FROM   delivery_note_h dnh
            LEFT   JOIN ac_master am
                   ON  am.ac_code      = dnh.ac_code
                   AND am.company_code = dnh.company_code
            WHERE  dnh.company_code = :company_code
              AND  dnh.branch_code  = :branch_code
              AND  dnh.despatch     = 'N'
              AND  dnh.despatch_to  = :user_id
            $search_clause
            ORDER  BY dnh.doc_dt DESC, dnh.doc_no DESC
            OFFSET :offset ROWS FETCH NEXT :page_size ROWS ONLY";

    $s = $con->prepare($sql);
    $s->bindValue(':company_code', $company_code);
    $s->bindValue(':branch_code',  $branch_code);
    $s->bindValue(':user_id',      $user);
    $s->bindValue(':offset',       $offset,    PDO::PARAM_INT);
    $s->bindValue(':page_size',    $page_size, PDO::PARAM_INT);
    if ($search_like !== null) {
        $s->bindValue(':search1', $search_like);
        $s->bindValue(':search2', $search_like);
    }
    $s->execute();

    return ['rows' => $s->fetchAll(PDO::FETCH_ASSOC), 'total' => $total_count];
}

// ── AJAX handler — returns JSON when ?ajax=1 ──────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');

    $search_term = trim($_GET['search'] ?? '');
    $search_like = $search_term !== '' ? '%' . $search_term . '%' : null;
    $page_size   = 5;
    $page        = max(1, intval($_GET['page'] ?? 1));
    $offset      = ($page - 1) * $page_size;

    try {
        $result      = fetchOrders($con, $company_code, $branch_code, $user, $search_like, $offset, $page_size);
        $rows        = $result['rows'];
        $total_count = $result['total'];
        $total_pages = max(1, (int) ceil($total_count / $page_size));
        $showing_from = $total_count > 0 ? $offset + 1 : 0;
        $showing_to   = min($offset + $page_size, $total_count);

        // Build pagination HTML (Tailwind classes match full-page render)
        $btn_base     = 'inline-flex items-center justify-center text-xs font-medium border rounded-lg transition-all';
        $btn_nav      = $btn_base . ' gap-1 px-3 py-1.5 text-gray-600 bg-white border-gray-200 hover:bg-blue-50 hover:border-blue-300 hover:text-blue-600';
        $btn_num      = $btn_base . ' w-8 h-8 text-gray-600 bg-white border-gray-200 hover:bg-blue-50 hover:border-blue-300 hover:text-blue-600';
        $btn_active   = $btn_base . ' w-8 h-8 font-bold text-white bg-blue-600 border-blue-600 shadow-sm cursor-default';
        $btn_disabled = $btn_base . ' gap-1 px-3 py-1.5 text-gray-300 bg-white border-gray-100 cursor-not-allowed';

        $pag = '';
        if ($page > 1) {
            $pag .= '<a href="' . pageUrl($page - 1) . '" class="dod-page-btn dod-page-btn--nav dod-page-link ' . $btn_nav . '" data-page="' . ($page - 1) . '"><i class="fa fa-chevron-left text-[10px]"></i> Prev</a>';
        } else {
            $pag .= '<span class="dod-page-btn dod-page-btn--nav dod-page-btn--disabled ' . $btn_disabled . '"><i class="fa fa-chevron-left text-[10px]"></i> Prev</span>';
        }
        $range_start = max(1, $page - 2);
        $range_end   = min($total_pages, $page + 2);
        if ($range_start > 1) {
            $pag .= '<a href="' . pageUrl(1) . '" class="dod-page-btn dod-page-link ' . $btn_num . '" data-page="1">1</a>';
            if ($range_start > 2) $pag .= '<span class="dod-page-ellipsis text-gray-400 px-1 text-xs">&hellip;</span>';
        }
        for ($p = $range_start; $p <= $range_end; $p++) {
            if ($p === $page) {
                $pag .= '<span class="dod-page-btn dod-page-btn--active ' . $btn_active . '">' . $p . '</span>';
            } else {
                $pag .= '<a href="' . pageUrl($p) . '" class="dod-page-btn dod-page-link ' . $btn_num . '" data-page="' . $p . '">' . $p . '</a>';
            }
        }
        if ($range_end < $total_pages) {
            if ($range_end < $total_pages - 1) $pag .= '<span class="dod-page-ellipsis text-gray-400 px-1 text-xs">&hellip;</span>';
            $pag .= '<a href="' . pageUrl($total_pages) . '" class="dod-page-btn dod-page-link ' . $btn_num . '" data-page="' . $total_pages . '">' . $total_pages . '</a>';
        }
        if ($page < $total_pages) {
            $pag .= '<a href="' . pageUrl($page + 1) . '" class="dod-page-btn dod-page-btn--nav dod-page-link ' . $btn_nav . '" data-page="' . ($page + 1) . '">Next <i class="fa fa-chevron-right text-[10px]"></i></a>';
        } else {
            $pag .= '<span class="dod-page-btn dod-page-btn--nav dod-page-btn--disabled ' . $btn_disabled . '">Next <i class="fa fa-chevron-right text-[10px]"></i></span>';
        }

        // Showing text
        $showing = $total_count > 0
            ? 'Showing ' . $showing_from . '–' . $showing_to . ' of ' . $total_count . ' pending orders'
              . ($search_term !== '' ? ' <em class="dod-pagination-filter-note">(filtered)</em>' : '')
            : 'No pending orders found';

        echo json_encode([
            'success'      => true,
            'tbody'        => buildTbodyHtml($rows, $search_term),
            'pagination'   => $pag,
            'showing'      => $showing,
            'total_count'  => $total_count,
        ]);
    } catch (Exception $e) {
        error_log("DO Dispatch AJAX error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Error loading records.']);
    }
    exit;
}

// ── Full-page render ──────────────────────────────────────────
erp_guard_standalone_get();

$search_term = trim($_GET['search'] ?? '');
$search_like = $search_term !== '' ? '%' . $search_term . '%' : null;
$page_size   = 5;
$page        = max(1, intval($_GET['page'] ?? 1));
$offset      = ($page - 1) * $page_size;

$delivery_orders = [];
$total_count     = 0;
$db_error        = null;

try {
    $result          = fetchOrders($con, $company_code, $branch_code, $user, $search_like, $offset, $page_size);
    $delivery_orders = $result['rows'];
    $total_count     = $result['total'];
} catch (Exception $e) {
    error_log("DO Dispatch fetch error: " . $e->getMessage());
    $db_error = "Unable to load delivery orders. Please try again.";
}

$total_pages  = max(1, (int) ceil($total_count / $page_size));
$showing_from = $total_count > 0 ? $offset + 1 : 0;
$showing_to   = min($offset + $page_size, $total_count);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DO Dispatch</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="../../vendor/font-awesome/css/font-awesome.min.css" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { inter: ['Inter', 'sans-serif'] },
                    colors: {
                        primary: { DEFAULT: '#2563eb', dark: '#1e40af', light: '#eff6ff', border: '#bfdbfe' },
                        surface: '#f5f7fa',
                    },
                }
            }
        }
    </script>
    <link href="css/do_dispatch.css?v=2" rel="stylesheet">
    <link href="<?php echo htmlspecialchars(erp_asset_v('assets/css/erp_search.css'), ENT_QUOTES, 'UTF-8'); ?>" rel="stylesheet">
</head>
<body class="bg-surface font-inter antialiased min-h-screen">

<div class="dod-page max-w-6xl mx-auto px-4 py-6 space-y-5">

    <!-- ── Page Header ─────────────────────────────────────── -->
    <div class="dod-page-header flex flex-col sm:flex-row sm:items-end justify-between gap-3">
        <div>
            <div class="flex items-center gap-2 mb-1">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-primary text-white shadow">
                    <i class="fa fa-truck text-sm"></i>
                </span>
                <h1 class="dod-title text-2xl font-bold text-gray-800 tracking-tight">Delivery Order Dispatch</h1>
            </div>
            <p class="dod-subtitle text-sm text-gray-500 ml-11">Search and dispatch pending delivery orders.</p>
        </div>
        <div class="flex items-center gap-3 ml-11 sm:ml-0">
            <a href="<?php echo htmlspecialchars(erp_iframe_url('dashboard'), ENT_QUOTES, 'UTF-8'); ?>"
               class="inline-flex items-center gap-1.5 text-xs text-gray-500 hover:text-blue-600 bg-white border border-gray-200 rounded-full px-3 py-1.5 shadow-sm hover:border-blue-300 transition-all">
                <i class="fa fa-arrow-left text-[10px]"></i> Back
            </a>
            <div class="dod-stats flex items-center gap-2 bg-white border border-blue-100 rounded-full px-4 py-1.5 shadow-sm">
                <span class="dod-stat-dot dod-stat-dot--active w-2 h-2 rounded-full bg-blue-500 animate-pulse"></span>
                <span class="text-sm font-semibold text-gray-700">
                    <span id="dod-stat-count" class="text-blue-600"><?php echo $total_count; ?></span>
                    <span class="text-gray-500 font-normal ml-0.5">Pending Dispatch</span>
                </span>
            </div>
            <button onclick="location.href='<?php echo htmlspecialchars(doResetUrl(), ENT_QUOTES, 'UTF-8'); ?>'"
                    class="inline-flex items-center gap-1.5 text-xs text-gray-500 hover:text-blue-600 bg-white border border-gray-200 rounded-full px-3 py-1.5 shadow-sm hover:border-blue-300 transition-all">
                <i class="fa fa-refresh"></i> Refresh
            </button>
        </div>
    </div>

    <!-- ── Search Card ─────────────────────────────────────── -->
    <div class="dod-search-card bg-white rounded-2xl border border-gray-100 shadow-sm p-4">
        <form method="get" action="" id="dod-search-form" autocomplete="off">
            <?php if (erp_is_embedded()) { ?>
            <input type="hidden" name="embed" value="1">
            <?php } ?>
            <div class="dod-search-row flex gap-3">
                <div class="dod-search-wrapper relative flex-1">
                    <i class="fa fa-search dod-search-icon absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 text-sm pointer-events-none"
                       id="dod-search-spinner-icon"></i>
                    <input
                        type="text"
                        name="search"
                        id="dod-search"
                        class="dod-search-input w-full pl-11 pr-10 py-2.5 rounded-xl border border-gray-200 bg-gray-50 text-sm text-gray-700 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent focus:bg-white transition-all"
                        placeholder="Search by customer name or DO number..."
                        value="<?php echo htmlspecialchars($search_term); ?>"
                        autocomplete="off"
                    >
                    <button type="button" id="dod-clear-btn" title="Clear"
                            class="dod-search-clear absolute right-3 top-1/2 -translate-y-1/2 w-5 h-5 rounded-full bg-gray-200 hover:bg-red-100 hover:text-red-500 text-gray-400 text-xs flex items-center justify-center transition-all"
                            style="<?php echo $search_term === '' ? 'display:none' : ''; ?>">
                        <i class="fa fa-times"></i>
                    </button>
                </div>
                <button type="submit"
                        class="dod-filter-btn inline-flex items-center gap-2 px-5 py-2.5 bg-blue-600 hover:bg-blue-700 active:scale-95 text-white text-sm font-semibold rounded-xl shadow hover:shadow-md transition-all whitespace-nowrap">
                    <i class="fa fa-search"></i> Search
                </button>
            </div>

            <?php if ($search_term !== ''): ?>
            <div id="dod-search-active-label" class="dod-search-active-label flex items-center gap-2 mt-2.5 text-xs text-gray-500">
                <span class="inline-flex items-center gap-1 bg-blue-50 border border-blue-200 text-blue-700 rounded-full px-2.5 py-0.5">
                    <i class="fa fa-filter text-blue-400"></i>
                    Filtering: <strong id="dod-filter-term" class="font-semibold"><?php echo htmlspecialchars($search_term); ?></strong>
                </span>
                <a href="<?php echo htmlspecialchars(doResetUrl(), ENT_QUOTES, 'UTF-8'); ?>" id="dod-clear-link" class="dod-search-clear-link text-red-500 hover:text-red-700 font-medium hover:underline transition-colors">
                    <i class="fa fa-times-circle"></i> Clear
                </a>
            </div>
            <?php else: ?>
            <div id="dod-search-active-label" class="dod-search-active-label" style="display:none">
                <span class="inline-flex items-center gap-1 bg-blue-50 border border-blue-200 text-blue-700 rounded-full px-2.5 py-0.5 text-xs">
                    <i class="fa fa-filter text-blue-400"></i>
                    Filtering: <strong id="dod-filter-term" class="font-semibold"></strong>
                </span>
                <a href="<?php echo htmlspecialchars(doResetUrl(), ENT_QUOTES, 'UTF-8'); ?>" id="dod-clear-link" class="dod-search-clear-link ml-2 text-red-500 hover:text-red-700 text-xs font-medium hover:underline transition-colors">
                    <i class="fa fa-times-circle"></i> Clear
                </a>
            </div>
            <?php endif; ?>
        </form>
    </div>

    <!-- ── Table Card ──────────────────────────────────────── -->
    <div class="dod-table-card bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">

        <!-- Card header -->
        <div class="dod-table-card-header flex items-center justify-between px-5 py-3.5 border-b border-gray-100 bg-gradient-to-r from-blue-600 to-blue-700">
            <div class="flex items-center gap-2">
                <i class="fa fa-list-alt text-blue-200 text-sm"></i>
                <span class="dod-table-title text-sm font-semibold text-white tracking-wide uppercase">
                    Pending Delivery Orders
                </span>
            </div>
            <!-- <div class="dod-table-card-actions">
                <button class="dod-icon-btn inline-flex items-center gap-1.5 text-xs text-blue-200 hover:text-white bg-white/10 hover:bg-white/20 border border-white/20 rounded-lg px-2.5 py-1 transition-all"
                        type="button" title="Refresh" onclick="location.href='<?php echo htmlspecialchars(doResetUrl(), ENT_QUOTES, 'UTF-8'); ?>'">
                    <i class="fa fa-refresh"></i> Refresh
                </button>
            </div> -->
        </div>

        <?php if ($db_error): ?>
        <div class="dod-db-error flex items-center gap-2 mx-5 mt-4 p-3 bg-red-50 border border-red-200 text-red-700 text-sm rounded-xl">
            <i class="fa fa-exclamation-circle text-red-400"></i>
            <?php echo htmlspecialchars($db_error); ?>
        </div>
        <?php endif; ?>

        <!-- Table -->
        <div class="dod-table-scroll overflow-x-auto">
            <table class="dod-table w-full text-sm">
                <colgroup>
                    <col class="col-no">
                    <col class="col-date">
                    <col class="col-customer">
                    <col class="col-status">
                    <col class="col-actions">
                </colgroup>
                <thead>
                    <tr class="bg-gray-50 border-b border-gray-100">
                        <th class="px-5 py-3 text-left text-xs font-700 text-gray-400 uppercase tracking-wider">DO Number</th>
                        <th class="px-5 py-3 text-left text-xs font-700 text-gray-400 uppercase tracking-wider">Date</th>
                        <th class="px-5 py-3 text-left text-xs font-700 text-gray-400 uppercase tracking-wider">Customer</th>
                        <th class="th-center px-5 py-3 text-center text-xs font-700 text-gray-400 uppercase tracking-wider">Status</th>
                        <th class="th-right px-5 py-3 text-right text-xs font-700 text-gray-400 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody id="dod-tbody" class="divide-y divide-gray-50">
                    <?php echo buildTbodyHtml($delivery_orders, $search_term); ?>
                </tbody>
            </table>
        </div>

        <!-- Table footer / Pagination -->
        <div class="dod-table-footer flex flex-wrap items-center justify-between gap-3 px-5 py-3.5 bg-gray-50 border-t border-gray-100">
            <span class="dod-pagination-info text-xs text-gray-500 font-medium" id="dod-showing">
                <?php if ($total_count > 0): ?>
                    Showing
                    <span class="font-semibold text-gray-700"><?php echo $showing_from; ?>–<?php echo $showing_to; ?></span>
                    of
                    <span class="font-semibold text-gray-700"><?php echo $total_count; ?></span>
                    pending orders
                    <?php if ($search_term !== ''): ?>
                        <em class="dod-pagination-filter-note not-italic text-blue-500 font-semibold">(filtered)</em>
                    <?php endif; ?>
                <?php else: ?>
                    No pending orders found
                <?php endif; ?>
            </span>

            <div class="dod-pagination flex items-center gap-1" id="dod-pagination">
                <?php if ($page > 1): ?>
                    <a href="<?php echo pageUrl($page - 1); ?>"
                       class="dod-page-btn dod-page-btn--nav dod-page-link inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium text-gray-600 bg-white border border-gray-200 rounded-lg hover:bg-blue-50 hover:border-blue-300 hover:text-blue-600 transition-all"
                       data-page="<?php echo $page - 1; ?>">
                        <i class="fa fa-chevron-left text-[10px]"></i> Prev
                    </a>
                <?php else: ?>
                    <span class="dod-page-btn dod-page-btn--nav dod-page-btn--disabled inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium text-gray-300 bg-white border border-gray-100 rounded-lg cursor-not-allowed">
                        <i class="fa fa-chevron-left text-[10px]"></i> Prev
                    </span>
                <?php endif;

                $range_start = max(1, $page - 2);
                $range_end   = min($total_pages, $page + 2);
                if ($range_start > 1): ?>
                    <a href="<?php echo pageUrl(1); ?>"
                       class="dod-page-btn dod-page-link inline-flex items-center justify-center w-8 h-8 text-xs font-medium text-gray-600 bg-white border border-gray-200 rounded-lg hover:bg-blue-50 hover:border-blue-300 hover:text-blue-600 transition-all"
                       data-page="1">1</a>
                    <?php if ($range_start > 2): ?>
                        <span class="dod-page-ellipsis text-gray-400 px-1 text-xs">&hellip;</span>
                    <?php endif; ?>
                <?php endif;

                for ($p = $range_start; $p <= $range_end; $p++): ?>
                    <?php if ($p === $page): ?>
                        <span class="dod-page-btn dod-page-btn--active inline-flex items-center justify-center w-8 h-8 text-xs font-bold text-white bg-blue-600 border border-blue-600 rounded-lg shadow-sm">
                            <?php echo $p; ?>
                        </span>
                    <?php else: ?>
                        <a href="<?php echo pageUrl($p); ?>"
                           class="dod-page-btn dod-page-link inline-flex items-center justify-center w-8 h-8 text-xs font-medium text-gray-600 bg-white border border-gray-200 rounded-lg hover:bg-blue-50 hover:border-blue-300 hover:text-blue-600 transition-all"
                           data-page="<?php echo $p; ?>"><?php echo $p; ?></a>
                    <?php endif; ?>
                <?php endfor;

                if ($range_end < $total_pages): ?>
                    <?php if ($range_end < $total_pages - 1): ?>
                        <span class="dod-page-ellipsis text-gray-400 px-1 text-xs">&hellip;</span>
                    <?php endif; ?>
                    <a href="<?php echo pageUrl($total_pages); ?>"
                       class="dod-page-btn dod-page-link inline-flex items-center justify-center w-8 h-8 text-xs font-medium text-gray-600 bg-white border border-gray-200 rounded-lg hover:bg-blue-50 hover:border-blue-300 hover:text-blue-600 transition-all"
                       data-page="<?php echo $total_pages; ?>"><?php echo $total_pages; ?></a>
                <?php endif;

                if ($page < $total_pages): ?>
                    <a href="<?php echo pageUrl($page + 1); ?>"
                       class="dod-page-btn dod-page-btn--nav dod-page-link inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium text-gray-600 bg-white border border-gray-200 rounded-lg hover:bg-blue-50 hover:border-blue-300 hover:text-blue-600 transition-all"
                       data-page="<?php echo $page + 1; ?>">
                        Next <i class="fa fa-chevron-right text-[10px]"></i>
                    </a>
                <?php else: ?>
                    <span class="dod-page-btn dod-page-btn--nav dod-page-btn--disabled inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium text-gray-300 bg-white border border-gray-100 rounded-lg cursor-not-allowed">
                        Next <i class="fa fa-chevron-right text-[10px]"></i>
                    </span>
                <?php endif; ?>
            </div>
        </div>

    </div><!-- /.dod-table-card -->

</div><!-- /.dod-container / max-w wrapper -->

<script src="../../vendor/jquery/jquery.min.js"></script>
<script>
(function () {
    var input      = document.getElementById('dod-search');
    var clearBtn   = document.getElementById('dod-clear-btn');
    var clearLink  = document.getElementById('dod-clear-link');
    var activeLabel= document.getElementById('dod-search-active-label');
    var filterTerm = document.getElementById('dod-filter-term');
    var tbody      = document.getElementById('dod-tbody');
    var pagination = document.getElementById('dod-pagination');
    var showing    = document.getElementById('dod-showing');
    var statCount  = document.getElementById('dod-stat-count');
    var iconEl     = document.getElementById('dod-search-spinner-icon');

    var currentPage   = <?php echo $page; ?>;
    var debounceTimer = null;

    /* ── Core AJAX fetch ────────────────────────────────────── */
    function loadResults(term, page) {
        var params = new URLSearchParams(window.location.search);
        params.set('ajax', '1');
        params.set('search', term);
        params.set('page', page);

        // Spinner feedback
        iconEl.className = 'fa fa-spinner fa-spin dod-search-icon';
        tbody.style.opacity = '0.4';

        fetch('?' + params.toString())
            .then(function (r) { return r.json(); })
            .then(function (data) {
                iconEl.className = 'fa fa-search dod-search-icon';
                tbody.style.opacity = '1';

                if (!data.success) return;

                tbody.innerHTML      = data.tbody;
                pagination.innerHTML = data.pagination;
                showing.innerHTML    = data.showing;
                if (statCount) statCount.textContent = data.total_count;

                if (typeof window.erpPatchEmbeddedLinks === 'function') {
                    window.erpPatchEmbeddedLinks(tbody);
                    window.erpPatchEmbeddedLinks(pagination);
                }

                // Update active-filter label
                if (term.trim() !== '') {
                    filterTerm.textContent = term;
                    activeLabel.style.display = '';
                    clearBtn.style.display    = '';
                } else {
                    activeLabel.style.display = 'none';
                    clearBtn.style.display    = 'none';
                }

                // Update browser URL without reload
                var urlParams = new URLSearchParams(window.location.search);
                if (term.trim() !== '') { urlParams.set('search', term); } else { urlParams.delete('search'); }
                urlParams.set('page', page);
                urlParams.delete('ajax');
                history.replaceState(null, '', '?' + urlParams.toString());

                // Re-attach pagination click handlers on new HTML
                attachPaginationHandlers();
            })
            .catch(function () {
                iconEl.className    = 'fa fa-search dod-search-icon';
                tbody.style.opacity = '1';
            });
    }

    /* ── Debounced input handler (live search) ──────────────── */
    input.addEventListener('input', function () {
        var term = this.value;
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(function () {
            currentPage = 1;
            loadResults(term, 1);
        }, 350);            // 350 ms debounce — feels instant, avoids every keystroke
    });

    /* ── Clear button ───────────────────────────────────────── */
    clearBtn.addEventListener('click', function () {
        input.value = '';
        currentPage = 1;
        loadResults('', 1);
        input.focus();
    });

    /* ── Prevent full-page form submit (AJAX handles it) ────── */
    document.getElementById('dod-search-form').addEventListener('submit', function (e) {
        e.preventDefault();
        clearTimeout(debounceTimer);
        currentPage = 1;
        loadResults(input.value, 1);
    });

    /* ── Pagination click handler (delegated, re-attached on update) */
    function attachPaginationHandlers() {
        pagination.querySelectorAll('.dod-page-link').forEach(function (el) {
            el.addEventListener('click', function (e) {
                e.preventDefault();
                var p = parseInt(this.getAttribute('data-page'), 10);
                if (!isNaN(p)) {
                    currentPage = p;
                    loadResults(input.value, p);
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                }
            });
        });
    }
    attachPaginationHandlers();

    window.dodRefreshList = function () {
        loadResults(input.value, currentPage);
    };

    document.getElementById('dod-tbody').addEventListener('click', function (e) {
        var btn = e.target.closest('.dod-action-btn--dispatch');
        if (btn && btn.tagName === 'BUTTON' && btn.dataset.doc) {
            e.preventDefault();
            if (typeof window.openSendModal === 'function') {
                window.openSendModal(btn.dataset.doc);
            }
        }
    });

}());
</script>

<?php include 'do_sign.php'; ?>
<?php include 'do_send_modal.php'; ?>
<script src="<?php echo htmlspecialchars(erp_asset_v('assets/js/approvalmenu.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
</body>
</html>
