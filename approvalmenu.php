<?php
require('db.php');
include("auth.php");
require_once __DIR__ . '/includes/erp_paths.php';

if (!defined('ERP_IS_SHELL') && $_SERVER['REQUEST_METHOD'] === 'GET') {
    erp_redirect('home', 301);
}

erp_guard_shell_not_in_iframe();

set_time_limit(120);

$user = $_SESSION['username'];
//include("validation.php");

$defer_approval_counts = true;
require __DIR__ . '/includes/approval_menu_data.php';

$active_page = 'dashboard';
$page_title  = 'INFOS ERP Dashboard';

$iframe_default = erp_iframe_url('dashboard');
$erp_has_topnav = !empty($menu_tree);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Dashboard — INFOS ERP</title>
    <meta name="erp-base" content="<?php echo htmlspecialchars(erp_base_path(), ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="erp-shell-url" content="<?php echo htmlspecialchars(erp_shell_url(), ENT_QUOTES, 'UTF-8'); ?>">
<?php include __DIR__ . '/includes/erp_styles.php'; ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="<?php echo htmlspecialchars(erp_asset_v('assets/css/approvalmenu.css'), ENT_QUOTES, 'UTF-8'); ?>" rel="stylesheet" type="text/css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>body { font-family: 'Inter', system-ui, sans-serif; }</style>
</head>
<body class="erp-body erp-scroll erp-shell text-slate-800<?php echo $erp_has_topnav ? ' erp-has-topnav' : ''; ?>">

<?php include __DIR__ . '/includes/erp_sidebar.php'; ?>

<div class="erp-main flex flex-col min-h-screen">

    <?php if ($erp_has_topnav): ?>
    <?php include __DIR__ . '/includes/erp_topnav.php'; ?>
    <?php else: ?>
    <button id="sidebar-toggle" type="button"
            class="erp-shell-menu-btn lg:hidden fixed top-3 left-3 z-30 w-9 h-9 rounded-lg border border-slate-200 bg-white text-slate-500 hover:bg-slate-50 flex items-center justify-center shadow-sm"
            aria-label="Open menu">
        <i class="fa fa-bars" aria-hidden="true"></i>
    </button>
    <?php endif; ?>

    <main class="flex-1 p-0">
        <iframe
            id="erp-main-frame"
            name="erp-main-frame"
            src="<?php echo htmlspecialchars($iframe_default, ENT_QUOTES, 'UTF-8'); ?>"
            class="erp-shell-frame w-full bg-white"
            loading="eager"
            referrerpolicy="same-origin"
            title="INFOS ERP Dashboard"
        ></iframe>
    </main>
</div>

<script src="<?php echo htmlspecialchars(erp_asset_v('assets/js/approvalmenu.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
</body>
</html>
