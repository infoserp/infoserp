<?php
/**
 * Approval page shell — sidebar + topbar + home navigation
 * Expects: $user, $branch_name, $branch_code, $page_title, $active_page (optional)
 */
require_once __DIR__ . '/erp_paths.php';
erp_guard_standalone_get();

if (!isset($approval_items)) {
    require __DIR__ . '/approval_menu_data.php';
}
if (!isset($active_page)) {
    $active_page = $_REQUEST['doc_type'] ?? $_GET['doc_type'] ?? '';
}
if (!isset($page_title)) {
    $page_title = 'Approval';
}
$erp_embedded = erp_is_embedded();
$doc_type_param = htmlspecialchars($_REQUEST['doc_type'] ?? $_GET['doc_type'] ?? '', ENT_QUOTES, 'UTF-8');
$approvalline_href = erp_iframe_url('approvalline?doc_type=' . rawurlencode($doc_type_param));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title><?php echo erp_e($page_title); ?> — INFOS ERP</title>
    <?php include __DIR__ . '/erp_styles.php'; ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="<?php echo htmlspecialchars(erp_asset_v('assets/css/approvalmenu.css'), ENT_QUOTES, 'UTF-8'); ?>" rel="stylesheet">
    <link href="<?php echo htmlspecialchars(erp_asset_v('assets/css/approval-pages.css'), ENT_QUOTES, 'UTF-8'); ?>" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>body { font-family: 'Inter', system-ui, sans-serif; }</style>
</head>
<body class="erp-body erp-scroll text-slate-800<?php echo $erp_embedded ? ' erp-embedded' : ''; ?>">

<?php if (!$erp_embedded) { include __DIR__ . '/erp_sidebar.php'; } ?>

<div class="erp-main flex flex-col min-h-screen">

    <header class="sticky top-0 z-20 bg-white/95 backdrop-blur border-b border-slate-200 px-4 sm:px-6 py-3">
        <div class="flex items-center justify-between gap-3 flex-wrap">
            <div class="flex items-center gap-3 min-w-0">
                <button id="sidebar-toggle" type="button"
                        class="lg:hidden w-9 h-9 rounded-lg border border-slate-200 text-slate-500 hover:bg-slate-50 flex items-center justify-center"
                        aria-label="Open menu">
                    <i class="fa fa-bars" aria-hidden="true"></i>
                </button>
                <div class="min-w-0">
                    <h1 class="al-page-title truncate"><?php echo erp_e($page_title); ?></h1>
                    <p class="al-page-sub truncate">INFOS ERP — Accounting &amp; Approval System</p>
                </div>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <a href="<?php echo htmlspecialchars(erp_iframe_url('dashboard'), ENT_QUOTES, 'UTF-8'); ?>" class="al-home-btn al-home-btn--primary" title="Home — Dashboard">
                    <i class="fa fa-home" aria-hidden="true"></i>
                    <span class="hidden sm:inline">Home</span>
                </a>
                <?php if ($doc_type_param !== ''): ?>
                <a href="<?php echo htmlspecialchars($approvalline_href, ENT_QUOTES, 'UTF-8'); ?>"
                   class="al-home-btn al-home-btn--ghost hidden md:inline-flex" title="Back to list">
                    <i class="fa fa-list" aria-hidden="true"></i>
                    <span>List</span>
                </a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <main class="flex-1 p-4 sm:p-6 lg:p-8 max-w-[1400px] w-full mx-auto">
