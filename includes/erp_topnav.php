<?php
/**
 * App shell top menu bar — desktop (lg+) replacement for the sidebar.
 * Each top-level user_menu parent opens a dropdown panel with one column per
 * sub-group. The sidebar stays as the mobile menu (toggle button lives here).
 * Expects: $menu_tree, $menu_ungrouped, $footer_nav, $active_page
 */
require_once __DIR__ . '/erp_paths.php';

if (!function_exists('erp_render_topnav_link')) {
    function erp_render_topnav_link(array $item, $active_page)
    {
        ?>
        <a href="<?php echo erp_embed_href($item['href']); ?>"
           data-iframe-nav="1"
           data-nav-key="<?php echo erp_e($item['key'] ?? ''); ?>"
           class="erp-topnav-link <?php echo ($active_page === ($item['key'] ?? '')) ? 'erp-topnav-link--active' : ''; ?>">
            <i class="fa <?php echo erp_e($item['icon']); ?>" aria-hidden="true"></i>
            <span><?php echo erp_e($item['label']); ?></span>
        </a>
        <?php
    }
}

if (!function_exists('erp_render_topnav_nodes')) {
    /** Links for a column; deeper groups become an indented sub-list. */
    function erp_render_topnav_nodes(array $nodes, $active_page)
    {
        foreach ($nodes as $node) {
            if ($node['type'] === 'item') {
                erp_render_topnav_link($node['item'], $active_page);
                continue;
            }
            ?>
            <div class="erp-topnav-subhead"><?php echo erp_e($node['label']); ?></div>
            <div class="erp-topnav-sub">
                <?php erp_render_topnav_nodes($node['children'], $active_page); ?>
            </div>
            <?php
        }
    }
}

if (!function_exists('erp_render_topnav_dropdown')) {
    /**
     * @param list<array> $columns each ['label' => ?string, 'icon' => ?string, 'nodes' => list]
     */
    function erp_render_topnav_dropdown($label, array $columns, $is_current, $active_page)
    {
        ?>
        <div class="erp-topnav-item<?php echo $is_current ? ' is-current' : ''; ?>">
            <button type="button" class="erp-topnav-top erp-topnav-trigger" aria-haspopup="true" aria-expanded="false">
                <span><?php echo erp_e($label); ?></span>
                <i class="fa fa-angle-down erp-topnav-caret" aria-hidden="true"></i>
            </button>
            <div class="erp-topnav-panel" hidden>
                <div class="erp-topnav-cols">
                    <?php foreach ($columns as $col): ?>
                    <div class="erp-topnav-col">
                        <?php if (!empty($col['label'])): ?>
                        <div class="erp-topnav-colhead">
                            <i class="fa <?php echo erp_e($col['icon']); ?>" aria-hidden="true"></i>
                            <span><?php echo erp_e($col['label']); ?></span>
                        </div>
                        <?php endif; ?>
                        <?php erp_render_topnav_nodes($col['nodes'], $active_page); ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php
    }
}

/* Build dropdown columns: direct menu items first, then one column per sub-group. */
$erp_topnav_menus = [];
foreach ($menu_tree as $section) {
    $direct  = [];
    $columns = [];
    foreach ($section['children'] as $node) {
        if ($node['type'] === 'item') {
            $direct[] = $node;
        } else {
            $columns[] = [
                'label' => $node['label'],
                'icon'  => erp_menu_group_icon($node['label']),
                'nodes' => $node['children'],
            ];
        }
    }
    if ($direct) {
        array_unshift($columns, ['label' => null, 'icon' => null, 'nodes' => $direct]);
    }
    $erp_topnav_menus[] = [
        'label'      => $section['label'],
        'columns'    => $columns,
        'is_current' => erp_menu_node_has_active($section, $active_page),
    ];
}
/* Unpaid invoices / proforma pending are dashboard cards, not a menu */
$menu_ungrouped = array_values(array_filter($menu_ungrouped, function ($item) {
    return !in_array($item['key'] ?? '', ['UNPAID', 'PROFORMA'], true);
}));
if (!empty($menu_ungrouped)) {
    $ungrouped_nodes = [];
    $ungrouped_current = false;
    foreach ($menu_ungrouped as $item) {
        $ungrouped_nodes[] = ['type' => 'item', 'item' => $item];
        $ungrouped_current = $ungrouped_current || ($active_page === ($item['key'] ?? ''));
    }
    $erp_topnav_menus[] = [
        'label'      => 'Follow-up',
        'columns'    => [['label' => null, 'icon' => null, 'nodes' => $ungrouped_nodes]],
        'is_current' => $ungrouped_current,
    ];
}
?>
<header id="erp-topnav" class="erp-topnav">
    <div class="erp-topnav-inner">
        <button id="sidebar-toggle" type="button"
                class="lg:hidden w-9 h-9 shrink-0 rounded-lg border border-slate-200 text-slate-500 hover:bg-slate-50 flex items-center justify-center"
                aria-label="Open menu">
            <i class="fa fa-bars" aria-hidden="true"></i>
        </button>

        <div class="erp-topnav-brand">
            <div class="erp-brand-logo-wrap shrink-0">
                <img src="<?php echo htmlspecialchars(erp_asset('image/infos_logo.jpg'), ENT_QUOTES, 'UTF-8'); ?>" alt="INFOS ERP" class="erp-brand-logo">
            </div>
            <span class="text-sm font-extrabold text-slate-900 whitespace-nowrap">INFOS ERP</span>
        </div>

        <nav class="erp-topnav-menu" aria-label="Main menu">
            <a href="<?php echo htmlspecialchars(erp_iframe_url('dashboard'), ENT_QUOTES, 'UTF-8'); ?>"
               data-iframe-nav="1"
               data-nav-key="dashboard"
               class="erp-topnav-top<?php echo $active_page === 'dashboard' ? ' is-current' : ''; ?>">
                <i class="fa fa-th-large" aria-hidden="true"></i>
                <span>Dashboard</span>
            </a>
            <?php foreach ($erp_topnav_menus as $menu) {
                erp_render_topnav_dropdown($menu['label'], $menu['columns'], $menu['is_current'], $active_page);
            } ?>
        </nav>

        <div class="erp-topnav-actions">
            <?php foreach ($footer_nav as $item):
                $is_logout = ($item['key'] === 'logout');
            ?>
            <a href="<?php echo $item['href']; ?>"
               target="_top"
               title="<?php echo erp_e($item['label']); ?>"
               class="erp-topnav-top<?php echo $is_logout ? ' erp-topnav-top--danger' : ''; ?>">
                <i class="fa <?php echo erp_e($item['icon']); ?>" aria-hidden="true"></i>
                <span class="hidden xl:inline"><?php echo erp_e($item['label']); ?></span>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</header>
