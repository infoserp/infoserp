<?php
require('db.php');
include("auth.php");
require_once __DIR__ . '/includes/erp_paths.php';
erp_guard_standalone_get();

set_time_limit(120);

$user = $_SESSION['username'];
//include("validation.php");

$defer_approval_counts = true;
require __DIR__ . '/includes/approval_menu_data.php';

$active_page = 'dashboard';
$erp_embedded = erp_is_embedded();

/* Dashboard summary cards — use existing PHP counts */
$dashboard_cards = [];

$card_defs = [
    ['key' => 'MR', 'label' => 'Material Request Approval', 'icon' => 'fa-truck',         'color' => 'blue'],
    ['key' => 'PR', 'label' => 'Purchase Request',          'icon' => 'fa-list',          'color' => 'sky'],
    ['key' => 'PO', 'label' => 'Purchase Order Approval',   'icon' => 'fa-shopping-bag',  'color' => 'slate'],
    ['key' => 'QT', 'label' => 'Quotation Approval',        'icon' => 'fa-quote-right',   'color' => 'red'],
    ['key' => 'SO', 'label' => 'Sales Order',               'icon' => 'fa-trophy',        'color' => 'green'],
];

foreach ($card_defs as $def) {
    if (!erp_dashboard_card_visible($def['key'])) {
        continue;
    }
    foreach ($approval_items as $item) {
        if (($item['key'] ?? '') === $def['key']) {
            $dashboard_cards[] = array_merge($def, [
                'href'  => erp_embed_href($item['href']),
                'count' => 0,
            ]);
            break;
        }
    }
}

/* DO Dispatch card — from menu + pending count */
if ($_SESSION["DD"] == "Y" && erp_dashboard_card_visible('DD')) {
    $dashboard_cards[] = [
        'key'   => 'DD',
        'label' => 'DO Dispatch',
        'icon'  => 'fa-truck',
        'color' => 'indigo',
        'href'  => erp_iframe_url('modules/do_dispatch/do_dispatch'),
        'count' => 0,
    ];
}

/* Follow-up cards — same card style as approvals, shown when the follow-up menu items are enabled; not part of the approval chart */
$followup_cards = [];
$followup_defs = [
    'UNPAID'   => ['label' => 'Unpaid Invoices - Followup', 'color' => 'amber'],
    'PROFORMA' => ['label' => 'Proforma Invoice Pending',   'color' => 'violet'],
];
foreach ($approval_items as $item) {
    $fkey = $item['key'] ?? '';
    if (isset($followup_defs[$fkey])) {
        $followup_cards[] = array_merge($followup_defs[$fkey], [
            'key'  => $fkey,
            'icon' => $item['icon'],
            'href' => erp_embed_href($item['href']),
        ]);
    }
}

/* Chart data payload (real counts for overview) */
$chart_labels = [];
$chart_values = [];
foreach ($dashboard_cards as $c) {
    $short = $c['key'];
    $chart_labels[] = $short;
    $chart_values[] = 0;
}
$chart_payload = [
    'labels'       => $chart_labels,
    'values'       => $chart_values,
    'totalPending' => 0,
    'deferred'     => true,
];

require_once __DIR__ . '/includes/approval_pending_list.php';
$pending_result      = erp_fetch_pending_approvals($con, $_SESSION, 10);
$pending_rows        = $pending_result['rows'];
$pending_total_count = (int) $pending_result['total'];

require_once __DIR__ . '/includes/approval_activity_list.php';
$activities = erp_fetch_recent_activity($con, $_SESSION, 10);

$color_map = [
    'blue'   => ['bg' => 'bg-blue-50',   'text' => 'text-blue-600',   'badge' => 'bg-blue-100 text-blue-700'],
    'sky'    => ['bg' => 'bg-sky-50',    'text' => 'text-sky-600',    'badge' => 'bg-sky-100 text-sky-700'],
    'slate'  => ['bg' => 'bg-slate-100', 'text' => 'text-slate-600',  'badge' => 'bg-slate-200 text-slate-700'],
    'red'    => ['bg' => 'bg-red-50',    'text' => 'text-red-600',    'badge' => 'bg-red-100 text-red-700'],
    'green'  => ['bg' => 'bg-green-50',  'text' => 'text-green-600',  'badge' => 'bg-green-100 text-green-700'],
    'indigo' => ['bg' => 'bg-indigo-50', 'text' => 'text-indigo-600', 'badge' => 'bg-indigo-100 text-indigo-700'],
    'amber'  => ['bg' => 'bg-amber-50',  'text' => 'text-amber-600',  'badge' => 'bg-amber-100 text-amber-700'],
    'violet' => ['bg' => 'bg-violet-50', 'text' => 'text-violet-600', 'badge' => 'bg-violet-100 text-violet-700'],
];

$dash_branch_label = $branch_name !== '' ? $branch_name : ('Branch ' . $branch_code);
if ($branch_name !== '') {
    $branch_parts = array_values(array_unique(array_map('trim', preg_split('/,\s*/', $branch_name))));
    $dash_branch_label = implode(', ', $branch_parts);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Dashboard — INFOS ERP</title>
    <?php include __DIR__ . '/includes/erp_styles.php'; ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="<?php echo htmlspecialchars(erp_asset_v('assets/css/approvalmenu.css'), ENT_QUOTES, 'UTF-8'); ?>" rel="stylesheet" type="text/css">
    <link href="<?php echo htmlspecialchars(erp_asset('assets/css/dashboard.css'), ENT_QUOTES, 'UTF-8'); ?>" rel="stylesheet" type="text/css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>body { font-family: 'Inter', system-ui, sans-serif; }</style>
    <script id="dash-count-data" type="application/json"><?php echo json_encode($chart_payload); ?></script>
</head>
<body class="erp-body erp-scroll text-slate-800<?php echo $erp_embedded ? ' erp-embedded' : ''; ?>">

<?php if (!$erp_embedded) { include __DIR__ . '/includes/erp_sidebar.php'; } ?>

<div class="erp-main flex flex-col min-h-screen">

    <!-- Topbar -->
    <header class="dash-topbar sticky top-0 z-20 bg-white/95 backdrop-blur border-b border-slate-200 px-4 sm:px-6 py-3">
        <div class="dash-topbar-inner flex items-center justify-between gap-4">
            <div class="dash-topbar-brand flex items-center gap-3 min-w-0">
                <button id="sidebar-toggle" type="button" class="lg:hidden w-9 h-9 shrink-0 rounded-lg border border-slate-200 text-slate-500 hover:bg-slate-50 flex items-center justify-center" aria-label="Open menu">
                    <i class="fa fa-bars" aria-hidden="true"></i>
                </button>
                <div class="min-w-0">
                    <div class="flex items-center gap-2">
                        <h1 class="text-base sm:text-lg font-extrabold text-slate-900 truncate">INFOS ERP Dashboard</h1>
                        <button type="button" id="refresh-counts-btn" class="am-refresh-counts shrink-0" title="Refresh counts" aria-label="Refresh counts">
                            <i class="fa fa-refresh" aria-hidden="true"></i>
                        </button>
                    </div>
                    <p class="text-xs text-slate-500 truncate">Accounting and approval system overview</p>
                </div>
            </div>

            <div class="dash-topbar-meta flex items-center gap-4 shrink-0">
                <div class="dash-topbar-clock hidden sm:block text-right leading-tight whitespace-nowrap">
                    <div id="dash-date" class="text-[11px] font-semibold text-slate-600">&nbsp;</div>
                    <div id="dash-time" class="text-sm font-bold text-blue-600">&nbsp;</div>
                </div>

                <div class="dash-topbar-user flex items-center gap-2.5 pl-4 border-l border-slate-200 min-w-0">
                    <div class="w-9 h-9 shrink-0 rounded-full bg-blue-50 text-blue-600 flex items-center justify-center font-bold uppercase text-sm">
                        <?php echo erp_e(strtoupper(substr($user, 0, 1))); ?>
                    </div>
                    <div class="min-w-0 leading-tight">
                        <div class="text-sm font-bold text-slate-800 truncate"><?php echo erp_e($user); ?></div>
                        <div class="text-[11px] text-slate-400 truncate max-w-[10rem] sm:max-w-[14rem] md:max-w-xs" title="<?php echo erp_e($dash_branch_label); ?>"><?php echo erp_e($dash_branch_label); ?></div>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <main class="flex-1 p-4 sm:p-6 lg:p-8 space-y-6">

        <!-- Count cards -->
        <section>
            <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6 gap-4">
                <?php $ci = 1; foreach ($dashboard_cards as $card):
                    $cm = $color_map[$card['color']] ?? $color_map['blue'];
                ?>
                <a href="<?php echo $card['href']; ?>"
                   class="dash-card dash-stat dash-animate dash-d<?php echo min($ci, 5); ?> block p-4 group">
                    <div class="flex items-start justify-between mb-3">
                        <div class="w-10 h-10 rounded-xl <?php echo $cm['bg']; ?> <?php echo $cm['text']; ?> flex items-center justify-center">
                            <i class="fa <?php echo erp_e($card['icon']); ?>" aria-hidden="true"></i>
                        </div>
                        <span class="dash-stat-count text-2xl font-extrabold text-slate-800" id="badge_count_<?php echo erp_e($card['key']); ?>" data-count-key="<?php echo erp_e($card['key']); ?>">
                            <i class="fa fa-spinner fa-spin am-count-loading" aria-hidden="true"></i>
                        </span>
                    </div>
                    <div class="text-xs font-bold text-slate-700 leading-snug group-hover:text-blue-600 transition-colors"><?php echo erp_e($card['label']); ?></div>
                    <div class="mt-1">
                        <span class="text-[10px] font-semibold uppercase tracking-wide <?php echo $cm['badge']; ?> rounded-full px-2 py-0.5">Pending</span>
                    </div>
                </a>
                <?php $ci++; endforeach; ?>

                <?php foreach ($followup_cards as $card):
                    $cm = $color_map[$card['color']] ?? $color_map['blue'];
                ?>
                <a href="<?php echo $card['href']; ?>"
                   class="dash-card dash-stat dash-animate dash-d<?php echo min($ci, 5); ?> block p-4 group">
                    <div class="flex items-start justify-between mb-3">
                        <div class="w-10 h-10 rounded-xl <?php echo $cm['bg']; ?> <?php echo $cm['text']; ?> flex items-center justify-center">
                            <i class="fa <?php echo erp_e($card['icon']); ?>" aria-hidden="true"></i>
                        </div>
                        <span class="dash-stat-count text-2xl font-extrabold text-slate-800" id="badge_count_<?php echo erp_e($card['key']); ?>" data-count-key="<?php echo erp_e($card['key']); ?>">
                            <i class="fa fa-spinner fa-spin am-count-loading" aria-hidden="true"></i>
                        </span>
                    </div>
                    <div class="text-xs font-bold text-slate-700 leading-snug group-hover:text-blue-600 transition-colors"><?php echo erp_e($card['label']); ?></div>
                    <div class="mt-1">
                        <span class="text-[10px] font-semibold uppercase tracking-wide <?php echo $cm['badge']; ?> rounded-full px-2 py-0.5">Follow-up</span>
                    </div>
                </a>
                <?php $ci++; endforeach; ?>

                <?php if (empty($dashboard_cards) && empty($followup_cards)): ?>
                <div class="col-span-full dash-card p-8 text-center text-slate-400 text-sm">
                    No approval modules enabled for your account.
                </div>
                <?php endif; ?>
            </div>
        </section>

        <!-- Analytics — real pending counts only -->
        <?php if (!empty($dashboard_cards)): ?>
        <section class="grid grid-cols-1 gap-4">
            <div class="dash-card dash-animate dash-d2 p-5">
                <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
                    <div>
                        <h2 class="text-sm font-bold text-slate-900 mb-0.5">Approval Overview</h2>
                        <p class="text-xs text-slate-500">Pending documents by type</p>
                    </div>
                    <span id="dash-total-pending" class="text-xs font-bold text-blue-600 bg-blue-50 border border-blue-100 px-2.5 py-1 rounded-full">
                        <i class="fa fa-spinner fa-spin am-count-loading" aria-hidden="true"></i>
                    </span>
                </div>
                <div class="dash-chart-wrap"><canvas id="chart-overview"></canvas></div>
            </div>
        </section>
        <?php endif; ?>

        <!-- Pending table + Activity -->
        <section class="grid grid-cols-1 xl:grid-cols-3 gap-4">

            <div class="dash-card dash-animate dash-d3 xl:col-span-2 overflow-hidden">
                <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
                    <div>
                        <h2 class="text-sm font-bold text-slate-900">Pending Approvals</h2>
                        <p class="text-xs text-slate-500">Documents pending for your approval modules</p>
                    </div>
                    <span class="text-xs font-bold text-blue-600 bg-blue-50 px-2.5 py-1 rounded-full"><?php echo (int) $pending_total_count; ?> items</span>
                </div>
                <div class="overflow-x-auto">
                    <table id="dash-pending-table" class="dash-table w-full text-left">
                        <thead>
                            <tr>
                                <th class="px-5 py-3">Document No</th>
                                <th class="px-5 py-3">Type</th>
                                <th class="px-5 py-3 hidden md:table-cell">Created By</th>
                                <th class="px-5 py-3 hidden sm:table-cell">Date</th>
                                <th class="px-5 py-3">Status</th>
                                <th class="px-5 py-3 text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-50">
                            <?php if (empty($pending_rows)): ?>
                            <tr>
                                <td colspan="6" class="px-5 py-10 text-center text-sm text-slate-400">No pending approvals for your enabled modules.</td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($pending_rows as $row): ?>
                            <tr class="hover:bg-slate-50/80 transition-colors">
                                <td class="px-5 py-3 font-mono text-xs font-bold text-blue-600"><?php echo erp_e($row['doc']); ?></td>
                                <td class="px-5 py-3 text-slate-700"><?php echo erp_e($row['type']); ?></td>
                                <td class="px-5 py-3 hidden md:table-cell text-slate-500"><?php echo erp_e($row['by']); ?></td>
                                <td class="px-5 py-3 hidden sm:table-cell text-slate-500"><?php echo erp_e($row['date']); ?></td>
                                <td class="px-5 py-3">
                                    <span class="dash-status dash-status--pending"><?php echo erp_e($row['status']); ?></span>
                                </td>
                                <td class="px-5 py-3 text-right">
                                    <a href="<?php echo erp_e($row['href']); ?>" class="inline-flex items-center gap-1 text-xs font-bold text-blue-600 hover:text-blue-800">
                                        Review <i class="fa fa-arrow-right text-[10px]" aria-hidden="true"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Recent Activity -->
            <div class="dash-card dash-animate dash-d4 p-5">
                <h2 class="text-sm font-bold text-slate-900 mb-0.5">Recent Activity</h2>
                <p class="text-xs text-slate-500 mb-4">Latest approvals and pending items for your modules</p>
                <div class="space-y-0">
                    <?php if (empty($activities)): ?>
                    <p class="text-sm text-slate-400 py-6 text-center">No recent activity for your enabled modules.</p>
                    <?php else: ?>
                    <?php foreach ($activities as $act):
                        $ac = $color_map[$act['color']] ?? $color_map['blue'];
                    ?>
                    <div class="dash-timeline-item">
                        <div class="dash-timeline-dot" style="background:var(--dash-blue);"></div>
                        <div class="flex items-start gap-2">
                            <div class="w-7 h-7 rounded-lg <?php echo $ac['bg']; ?> <?php echo $ac['text']; ?> flex items-center justify-center shrink-0 mt-0.5">
                                <i class="fa <?php echo erp_e($act['icon']); ?> text-[10px]" aria-hidden="true"></i>
                            </div>
                            <div>
                                <p class="text-xs text-slate-700 leading-snug"><?php echo erp_e($act['text']); ?></p>
                                <p class="text-[10px] text-slate-400 mt-0.5"><?php echo erp_e($act['time']); ?></p>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </section>

    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script src="<?php echo htmlspecialchars(erp_asset_v('assets/js/approvalmenu.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
<script src="<?php echo htmlspecialchars(erp_asset('assets/js/dashboard.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
</body>
</html>
