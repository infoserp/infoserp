<?php
ob_start();
require('../../db.php');
include('../../auth.php');
require_once __DIR__ . '/../../includes/erp_paths.php';
require_once __DIR__ . '/../../includes/do_print_service.php';

erp_guard_standalone_get();

$company_code = $_SESSION['company_code'];
$branch_code  = $_SESSION['branch_code'];
$user         = $_SESSION['username'];

function doPrintBackToListUrl() {
    $params = [];
    $page   = max(1, intval($_GET['page'] ?? 1));
    $search = trim($_GET['search'] ?? '');
    if ($page > 1) {
        $params['page'] = $page;
    }
    if ($search !== '') {
        $params['search'] = $search;
    }
    $route = 'modules/do_dispatch/do_dispatch';
    if ($params !== []) {
        $route .= '?' . http_build_query($params);
    }
    return erp_iframe_url($route);
}

// ── Validate doc_no ───────────────────────────────────────────
$doc_no = trim($_GET['doc_no'] ?? '');
if ($doc_no === '') {
    header('Location: ' . doPrintBackToListUrl());
    exit;
}

$do_dispatch_back_url = doPrintBackToListUrl();

// ── Fetch header ──────────────────────────────────────────────
$do_header   = null;
$do_lines    = [];
$branch_info = [];
$job_no      = '';
$fetch_error = null;

try {
    $loaded      = doPrintLoadData($con, $company_code, $branch_code, $doc_no);
    $do_header   = $loaded['do_header'];
    $do_lines    = $loaded['do_lines'];
    $branch_info = $loaded['branch_info'];
    $job_no      = $loaded['job_no'];
} catch (Exception $e) {
    error_log("DO Print fetch error: " . $e->getMessage());
    if (strpos($e->getMessage(), 'not found') !== false) {
        $fetch_error = "Delivery Order <strong>" . htmlspecialchars($doc_no) . "</strong> not found.";
    } else {
        $fetch_error = "Unable to load Delivery Order. Please try again.";
    }
}

// ── Helpers ───────────────────────────────────────────────────
function fmtDate($d) {
    return doPrintFmtDate($d);
}
function safe($v) {
    return doPrintSafe($v);
}

if (!$fetch_error) {
    extract(doPrintPrepareViewVars([
        'doc_no'      => $doc_no,
        'do_header'   => $do_header,
        'do_lines'    => $do_lines,
        'branch_info' => $branch_info,
        'job_no'      => $job_no,
    ], ['for_pdf' => false]), EXTR_SKIP);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delivery Order – <?php echo safe($doc_no); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: { extend: { fontFamily: { inter: ['Inter','sans-serif'] } } }
        }
    </script>
    <style>
        /* ── Screen shell ─────────────────────────────────── */
        body {
            font-family: 'Inter', sans-serif;
            background: #e8eaf0;
        }

        /* ── A4 paper ─────────────────────────────────────── */
        .a4-paper {
            width: 210mm;
            background: #ffffff;
            margin: 0 auto;
            position: relative;
            box-shadow: 0 4px 32px rgba(0,0,0,0.18);
        }

        .screen-wrap,
        .a4-paper {
            margin-left: auto;
            margin-right: auto;
        }

        /* Screen preview: show page break guides */
        @media screen {
            .a4-paper {
                min-height: 297mm;
            }
        }

        /* ── Print zones ──────────────────────────────────── */
        .print-header-zone {
            /* letterhead + title + customer info — keep on page 1 */
        }

        .print-table-zone {
            margin-bottom: 2mm;
            width: 100%;
        }

        /* Closing block: signature + disclaimer + footer image stay together */
        .print-closing-block {
            margin-top: 6mm;
        }

        /* ── Inner content area ───────────────────────────── */
        .doc-content {
            padding: 0 14mm 14mm 14mm;
        }

        /* ── Letterhead image area ────────────────────────── */
        .letterhead-area {
            height: 38mm;
            border-bottom: 1.5px solid #1e40af;
            margin: 0 0 6mm 0;
            overflow: hidden;
        }

        .letterhead-img,
        .footer-img {
            width: 100%;
            height: 100%;
            display: block;
            border: 0;
        }

        /* ── Document title ───────────────────────────────── */
        .doc-title {
            font-size: 15pt;
            font-weight: 800;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            text-decoration: underline;
            text-underline-offset: 4px;
            text-align: center;
            color: #111827;
            margin: 0 0 5mm 0;
        }

        /* ── Info boxes (two-column table — PDF + print safe) ─ */
        .info-grid-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 4mm 0;
            margin-bottom: 4mm;
            table-layout: fixed;
        }

        .info-box-cell {
            width: 50%;
            vertical-align: top;
            border: none;
            padding: 0;
        }

        .info-box {
            border: 1.2px solid #374151;
            border-radius: 12px;
            overflow: hidden;
            padding: 3mm 4mm;
            height: 38mm;
            min-height: 38mm;
            box-sizing: border-box;
            font-size: 8pt;
            background: #ffffff;
        }

        .info-box-label {
            font-size: 7pt;
            font-weight: 700;
            text-transform: uppercase;
            color: #1f2937;
            letter-spacing: 0.06em;
            margin-bottom: 1.5mm;
        }

        .info-box-company {
            font-size: 9pt;
            font-weight: 700;
            color: #111827;
            margin-bottom: 1.5mm;
            line-height: 1.35;
        }

        .info-box-address {
            font-size: 8pt;
            color: #374151;
            line-height: 1.5;
        }

        .info-row {
            font-size: 8pt;
            line-height: 1.85;
            color: #374151;
            margin-bottom: 0.4mm;
        }

        .info-row-key {
            display: inline-block;
            font-weight: 600;
            min-width: 14mm;
            width: 14mm;
            color: #1f2937;
        }

        .info-row-sep {
            display: inline-block;
            width: 3mm;
            color: #6b7280;
        }

        .info-row-val {
            display: inline-block;
            font-weight: 700;
            color: #111827;
        }

        /* ── Items table ──────────────────────────────────── */
        .items-table {
            width: 100%;
            table-layout: fixed;
            border-collapse: collapse;
            font-size: 8.5pt;
            margin-bottom: 5mm;
        }

        .items-table thead tr {
            background: #1e40af;
        }

        .items-table thead th {
            color: #ffffff;
            font-weight: 700;
            font-size: 8pt;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            padding: 2.5mm 3mm;
            border: 1px solid #1e40af;
            text-align: left;
        }

        .items-table thead th.col-no   { width: 10%; text-align: center; }
        .items-table thead th.col-desc { width: 66%; }
        .items-table thead th.col-qty  { width: 12%; text-align: center; }
        .items-table thead th.col-unit { width: 12%; text-align: center; }

        .items-table thead th:nth-child(1),
        .items-table tbody td:nth-child(1) {
            width: 10%;
            text-align: center;
        }

        .items-table thead th:nth-child(2),
        .items-table tbody td:nth-child(2) {
            width: 66%;
            text-align: left;
        }

        .items-table thead th:nth-child(3),
        .items-table tbody td:nth-child(3) {
            width: 12%;
            text-align: center;
        }

        .items-table thead th:nth-child(4),
        .items-table tbody td:nth-child(4) {
            width: 12%;
            text-align: center;
        }

        .items-table tbody td[colspan="4"] {
            border-top: 2px solid #1e40af;
            font-size: 7.5pt;
            font-weight: 600;
            color: #000000 !important;
            text-align: right;
            padding: 2mm 3mm;
            background: #ffffff !important;
        }

        .items-table thead th.center { text-align: center; }

        .items-table tbody td {
            padding: 2.5mm 3mm;
            border: 1px solid #d1d5db;
            color: #1f2937;
            vertical-align: top;
            line-height: 1.45;
        }

        .items-table tbody tr:nth-child(even) td {
            background: #f8fafc;
        }

        /* Prevent a single row from splitting across pages */
        .items-table tbody tr {
            page-break-inside: avoid;
            break-inside: avoid;
        }

        .items-table tfoot td {
            padding: 2mm 3mm;
            font-size: 7.5pt;
            color: #6b7280;
        }

        /* ── Remarks ──────────────────────────────────────── */
        .remarks-box {
            border: 1px solid #d1d5db;
            border-radius: 4px;
            padding: 2.5mm 3.5mm;
            font-size: 8pt;
            color: #374151;
            margin-bottom: 5mm;
            background: #f9fafb;
        }

        .remarks-label {
            font-size: 7pt;
            font-weight: 700;
            text-transform: uppercase;
            color: #6b7280;
            letter-spacing: 0.06em;
            margin-bottom: 1mm;
        }

        /* ── Document footer (branch + receiver signature) ── */
        .doc-footer-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 8mm 0;
            margin-top: 8mm;
            margin-bottom: 4mm;
        }

        .doc-footer-cell {
            vertical-align: top;
        }

        .doc-footer-cell-left {
            width: 42%;
        }

        .doc-footer-cell-right {
            width: 58%;
        }

        .branch-block {
            border: 1.2px solid #374151;
            border-radius: 12px;
            padding: 2.5mm 3mm 3mm 3mm;
            box-sizing: border-box;
            background: #ffffff;
        }

        .branch-block-label {
            font-size: 7.5pt;
            font-weight: 700;
            text-transform: uppercase;
            color: #000000;
            letter-spacing: 0.06em;
            margin-bottom: 3mm;
        }

        .branch-block-name {
            font-size: 10pt;
            font-weight: 700;
            color: #111827;
            line-height: 1.35;
            margin-bottom: 0;
        }
        /* ── Received By / digital signature pad ──────────── */
        .received-block {
            border: 1.2px solid #374151;
            border-radius: 12px;
            padding: 2.5mm 3mm 3mm 3mm;
            box-sizing: border-box;
            background: #ffffff;
        }

        .received-block-label {
            font-size: 7.5pt;
            font-weight: 700;
            text-transform: uppercase;
            color: #000000;
            letter-spacing: 0.06em;
            margin-bottom: 2mm;
        }

        .dig-sig-pad {
            position: relative;
            height: 22mm;
            background: #f8fafc;
            border: 1.5px solid #cbd5e1;
            border-radius: 6px;
            overflow: hidden;
            text-align: center;
            margin-bottom: 2.5mm;
        }

        .dig-sig-watermark {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            text-align: center;
            padding-top: 5mm;
            pointer-events: none;
        }

        .dig-sig-watermark span {
            font-size: 9pt;
            font-weight: 800;
            color: rgba(148,163,184,0.4);
            text-transform: uppercase;
            letter-spacing: 0.1em;
            transform: rotate(-18deg);
            text-align: center;
            line-height: 1.35;
            white-space: pre-line;
        }

        .dig-sig-img {
            position: relative;
            z-index: 2;
            max-height: 20mm;
            max-width: 95%;
            margin: 1mm auto 0;
            display: block;
        }

        .receiver-name-row {
            border-top: 1px solid #9ca3af;
            padding-top: 1.5mm;
            margin-bottom: 0.5mm;
            font-size: 8pt;
            line-height: 1.45;
        }

        .receiver-name-key {
            display: inline-block;
            font-size: 7.5pt;
            font-weight: 600;
            color: #000000;
            min-width: 28mm;
            vertical-align: baseline;
        }

        .receiver-name-val {
            display: inline-block;
            font-size: 8pt;
            font-weight: 700;
            color: #111827;
            vertical-align: baseline;
        }

        .dig-sig-meta {
            font-size: 7pt;
            color: #1f2937;
            margin-bottom: 1mm;
        }

        .dig-sig-meta-key {
            font-weight: 600;
            color: #1f2937;
        }

        .dig-sig-sep {
            color: #d1d5db;
            margin: 0 1.5mm;
        }

        .dig-sig-timestamp {
            font-size: 7pt;
            color: #000000;
            margin-top: 0.5mm;
            line-height: 1.45;
        }

        /* ── Generated-by bar ─────────────────────────────── */
        .generated-bar {
            border-top: 1px dashed #d1d5db;
            padding-top: 2.5mm;
            margin-bottom: 4mm;
            text-align: center;
        }

        .generated-terms {
            font-size: 7.5pt;
            font-weight: 600;
            color: #000000;
            letter-spacing: 0.02em;
        }

        .generated-by {
            font-size: 7pt;
            font-weight: 700;
            color: #9ca3af;
            letter-spacing: 0.05em;
            text-transform: uppercase;
        }

        /* ── Footer image area ────────────────────────────── */
        .footer-area {
            height: 22mm;
            border-top: 1.5px solid #1e40af;
            overflow: hidden;
            margin-top: auto;
        }

        /* ── Screen-only toolbar ──────────────────────────── */
        .print-toolbar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 100;
            background: linear-gradient(135deg, #1e40af 0%, #2563eb 100%);
            padding: 10px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 2px 12px rgba(30,64,175,0.3);
        }

        /* ── Print media ──────────────────────────────────── */
        @media print {
            @page {
                size: A4 portrait;
                margin: 8mm 10mm 10mm 10mm;
            }

            body {
                background: white;
                print-color-adjust: exact;
                -webkit-print-color-adjust: exact;
            }

            .print-toolbar  { display: none !important; }
            .screen-spacer  { display: none !important; }
            .screen-wrap    { padding: 0 !important; }

            .a4-paper {
                box-shadow: none;
                margin: 0;
                width: 100%;
                min-height: auto;
            }

            .doc-content {
                padding: 0;
            }

            /* Repeat table header on every printed page */
            .items-table thead {
                display: table-header-group;
            }

            .items-table tfoot {
                display: table-footer-group;
            }

            /* Keep document header block together on first page */
            .print-header-zone {
                page-break-inside: avoid;
                break-inside: avoid;
            }

            .letterhead-area {
                page-break-after: avoid;
                break-after: avoid;
            }

            .info-grid {
                page-break-inside: avoid;
                break-inside: avoid;
            }

            /* Table rows never split mid-row */
            .items-table tbody tr {
                page-break-inside: avoid;
                break-inside: avoid;
            }

            /* Continuation label row */
            .page-continue-row td {
                background: #f1f5f9 !important;
                font-size: 7pt;
                font-weight: 600;
                color: #64748b;
                text-align: center;
                padding: 1.5mm 3mm;
            }

            /* Signature + disclaimer + footer image — never split internally */
            .print-closing-block {
                page-break-inside: avoid;
                break-inside: avoid;
                page-break-before: auto;
            }

            .doc-footer-section,
            .doc-footer-table {
                page-break-inside: avoid;
                break-inside: avoid;
            }

            .generated-bar {
                page-break-inside: avoid;
                break-inside: avoid;
            }

            .footer-area {
                page-break-inside: avoid;
                break-inside: avoid;
            }
        }
    </style>
</head>
<body>

<!-- ── Screen toolbar (hidden on print) ──────────────────────── -->
<div class="print-toolbar">
    <div class="flex items-center gap-3">
        <div class="w-8 h-8 bg-white/20 rounded-lg flex items-center justify-center">
            <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
            </svg>
        </div>
        <div>
            <div class="text-white font-bold text-sm leading-tight">Delivery Order</div>
            <div class="text-blue-200 text-xs"><?php echo safe($doc_no); ?></div>
        </div>
    </div>
    <div class="flex items-center gap-2">
        <!-- <button onclick="window.print()"
                class="inline-flex items-center gap-2 px-4 py-2 bg-white text-blue-700 font-semibold text-sm rounded-lg shadow hover:bg-blue-50 active:scale-95 transition-all">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
            </svg>
            Print / Save PDF
        </button> -->
        <a href="<?php echo htmlspecialchars($do_dispatch_back_url, ENT_QUOTES, 'UTF-8'); ?>"
           class="inline-flex items-center gap-2 px-4 py-2 bg-white/10 border border-white/30 text-white font-medium text-sm rounded-lg hover:bg-white/20 transition-all">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
            </svg>
            Back
        </a>
    </div>
</div>

<!-- Spacer so content clears the fixed toolbar -->
<div class="screen-spacer" style="height:58px;"></div>

<!-- ── A4 Paper ───────────────────────────────────────────────── -->
<div class="screen-wrap" style="padding: 12px 0 32px 0;">
<div class="a4-paper">

    <?php if ($fetch_error): ?>
    <!-- Error state -->
    <div class="doc-content flex items-center justify-center" style="min-height:200px;">
        <div class="text-center p-8">
            <div class="text-5xl text-gray-200 mb-4">⚠</div>
            <div class="text-gray-600 font-semibold text-sm"><?php echo $fetch_error; ?></div>
            <a href="<?php echo htmlspecialchars($do_dispatch_back_url, ENT_QUOTES, 'UTF-8'); ?>" class="mt-4 inline-block text-blue-600 text-xs underline">← Back to list</a>
        </div>
    </div>

    <?php else: ?>

    <?php echo doPrintRenderBodyHtml([
        'doc_no'      => $doc_no,
        'do_header'   => $do_header,
        'do_lines'    => $do_lines,
        'branch_info' => $branch_info,
        'job_no'      => $job_no,
    ], ['for_pdf' => false]); ?>

    <?php endif; ?>

</div><!-- /.a4-paper -->
</div>

</body>
</html>
