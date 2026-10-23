<?php
/**
 * ERP sidebar navigation — shared by home shell and standalone pages
 * Expects: $approval_items, $menu_items, $footer_nav, $user, $branch_name, $branch_code, $active_page
 */
require_once __DIR__ . '/erp_paths.php';

if (!isset($active_page)) {
    $active_page = '';
}

$erp_is_shell = defined('ERP_IS_SHELL') && ERP_IS_SHELL;
$erp_logo_url = erp_asset('image/infos_logo.jpg');
$menu_tree      = $menu_tree ?? [];
$menu_ungrouped = $menu_ungrouped ?? [];
/* Unpaid invoices / proforma pending are dashboard cards, not a menu */
$menu_ungrouped = array_values(array_filter($menu_ungrouped, function ($item) {
    return !in_array($item['key'] ?? '', ['UNPAID', 'PROFORMA'], true);
}));

if (!function_exists('erp_render_sidebar_link')) {
    function erp_render_sidebar_link(array $item, $active_page, $erp_is_shell)
    {
        $is_async = !empty($item['badge_id']);
        $cnt      = $is_async ? '' : (int) ($item['count'] ?? 0);
        $nav_href = $erp_is_shell ? erp_embed_href($item['href']) : $item['href'];
        ?>
        <a href="<?php echo $nav_href; ?>"
           <?php if ($erp_is_shell): ?>data-iframe-nav="1"<?php endif; ?>
           data-nav-key="<?php echo erp_e($item['key'] ?? ''); ?>"
           class="erp-nav-link <?php echo ($active_page === ($item['key'] ?? '')) ? 'erp-nav-link--active' : ''; ?>">
            <i class="fa <?php echo erp_e($item['icon']); ?> w-5 text-center" aria-hidden="true"></i>
            <span class="flex-1 min-w-0 text-[11px] sm:text-xs leading-snug"><?php echo erp_e($item['label']); ?></span>
            <?php if ($is_async): ?>
                <span class="erp-nav-badge am-count-badge" id="<?php echo erp_e($item['badge_id']); ?>" data-count-key="<?php echo erp_e($item['key']); ?>">
                    <i class="fa fa-spinner fa-spin am-count-loading" aria-hidden="true"></i>
                </span>
            <?php elseif ($cnt > 0): ?>
                <span class="erp-nav-badge"><?php echo $cnt; ?></span>
            <?php endif; ?>
        </a>
        <?php
    }
}

if (!function_exists('erp_menu_node_has_active')) {
    function erp_menu_node_has_active(array $node, $active_page)
    {
        if ($node['type'] === 'item') {
            return $active_page !== '' && $active_page === ($node['item']['key'] ?? '');
        }
        foreach ($node['children'] as $child) {
            if (erp_menu_node_has_active($child, $active_page)) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('erp_render_sidebar_node')) {
    function erp_render_sidebar_node(array $node, $active_page, $erp_is_shell)
    {
        if ($node['type'] === 'item') {
            erp_render_sidebar_link($node['item'], $active_page, $erp_is_shell);
            return;
        }
        $icon = erp_menu_group_icon($node['label']);
        ?>
        <details class="erp-nav-group" data-group-key="<?php echo erp_e($node['code']); ?>"<?php echo erp_menu_node_has_active($node, $active_page) ? ' open' : ''; ?>>
            <summary class="erp-nav-link erp-nav-group__toggle">
                <i class="fa <?php echo erp_e($icon); ?> w-5 text-center" aria-hidden="true"></i>
                <span class="flex-1 min-w-0 truncate"><?php echo erp_e($node['label']); ?></span>
                <i class="fa fa-chevron-right erp-nav-group__chevron" aria-hidden="true"></i>
            </summary>
            <div class="erp-nav-group__items">
                <?php foreach ($node['children'] as $child) {
                    erp_render_sidebar_node($child, $active_page, $erp_is_shell);
                } ?>
            </div>
        </details>
        <?php
    }
}
?>
<aside id="erp-sidebar" class="erp-sidebar fixed inset-y-0 left-0 z-40 flex flex-col bg-white border-r border-slate-200 shadow-sm transition-transform duration-300 lg:translate-x-0 -translate-x-full w-64">

    <!-- Brand -->
    <div class="flex items-center gap-3 px-4 py-4 border-b border-slate-100">
        <div class="erp-brand-logo-wrap shrink-0">
            <img src="<?php echo htmlspecialchars($erp_logo_url, ENT_QUOTES, 'UTF-8'); ?>" alt="INFOS ERP" class="erp-brand-logo">
        </div>
        <div class="min-w-0">
            <div class="text-sm font-extrabold text-slate-900 truncate">INFOS ERP</div>
            <div class="text-[10px] text-slate-400 truncate">Accounting &amp; Approval System</div>
        </div>
        <button id="sidebar-close" type="button" class="lg:hidden ml-auto w-8 h-8 rounded-lg text-slate-400 hover:bg-slate-100 flex items-center justify-center" aria-label="Close menu">
            <i class="fa fa-times" aria-hidden="true"></i>
        </button>
    </div>

    <!-- Nav scroll area -->
    <nav class="flex-1 overflow-y-auto erp-scroll py-3 px-2 space-y-5">

        <!-- Main -->
        <div>
            <p class="px-3 mb-1.5 text-[10px] font-bold uppercase tracking-wider text-slate-400">Main</p>
            <?php if ($erp_is_shell): ?>
            <a href="<?php echo htmlspecialchars(erp_iframe_url('dashboard'), ENT_QUOTES, 'UTF-8'); ?>"
               data-iframe-nav="1"
               data-nav-key="dashboard"
               class="erp-nav-link <?php echo $active_page === 'dashboard' ? 'erp-nav-link--active' : ''; ?>">
                <i class="fa fa-th-large w-5 text-center" aria-hidden="true"></i>
                <span>Dashboard</span>
            </a>
            <?php else: ?>
            <a href="<?php echo htmlspecialchars(erp_shell_url(), ENT_QUOTES, 'UTF-8'); ?>"
               class="erp-nav-link <?php echo $active_page === 'home' ? 'erp-nav-link--active' : ''; ?>">
                <i class="fa fa-home w-5 text-center" aria-hidden="true"></i>
                <span>Home</span>
            </a>
            <?php endif; ?>
        </div>

        <?php if (!empty($menu_tree)): ?>

        <!-- Menu tree: user_menu parents -> menus -->
        <?php foreach ($menu_tree as $section): ?>
        <div class="erp-sidebar-tree">
            <p class="px-3 mb-1.5 text-[10px] font-bold uppercase tracking-wider text-slate-400"><?php echo erp_e($section['label']); ?></p>
            <?php foreach ($section['children'] as $node) {
                erp_render_sidebar_node($node, $active_page, $erp_is_shell);
            } ?>
        </div>
        <?php endforeach; ?>

        <?php if (!empty($menu_ungrouped)): ?>
        <div class="erp-sidebar-approvals">
            <p class="px-3 mb-1.5 text-[10px] font-bold uppercase tracking-wider text-slate-400">Follow-up</p>
            <?php foreach ($menu_ungrouped as $item) {
                erp_render_sidebar_link($item, $active_page, $erp_is_shell);
            } ?>
        </div>
        <?php endif; ?>

        <?php else: ?>

        <!-- Approvals -->
        <?php if (!empty($approval_items)): ?>
        <div class="erp-sidebar-approvals">
            <p class="px-3 mb-1.5 text-[10px] font-bold uppercase tracking-wider text-slate-400">Approvals</p>
            <?php foreach ($approval_items as $item) {
                if (in_array($item['key'] ?? '', ['UNPAID', 'PROFORMA'], true)) {
                    continue;
                }
                erp_render_sidebar_link($item, $active_page, $erp_is_shell);
            } ?>
        </div>
        <?php endif; ?>

        <!-- Modules -->
        <?php if (!empty($menu_items)): ?>
        <div>
            <p class="px-3 mb-1.5 text-[10px] font-bold uppercase tracking-wider text-slate-400">Modules</p>
            <?php foreach ($menu_items as $item) {
                erp_render_sidebar_link($item, $active_page, $erp_is_shell);
            } ?>
        </div>
        <?php endif; ?>

        <?php endif; ?>
    </nav>

    <!-- Footer nav -->
    <div class="border-t border-slate-100 p-2 space-y-0.5">
        <?php foreach ($footer_nav as $item):
            $is_logout = ($item['key'] === 'logout');
        ?>
        <a href="<?php echo $item['href']; ?>"
           <?php if ($erp_is_shell): ?>target="_top"<?php endif; ?>
           class="erp-nav-link <?php echo $is_logout ? 'erp-nav-link--danger' : ''; ?>">
            <i class="fa <?php echo erp_e($item['icon']); ?> w-5 text-center" aria-hidden="true"></i>
            <span><?php echo erp_e($item['label']); ?></span>
        </a>
        <?php endforeach; ?>
    </div>
</aside>

<!-- Mobile overlay -->
<div id="sidebar-overlay" class="fixed inset-0 bg-slate-900/40 z-30 hidden lg:hidden" aria-hidden="true"></div>
